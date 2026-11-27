// App das lojas sem venda (27/11/2026, recusa da Apple pela regra 3.1.1).
// Com window.INTUS_NATIVE_APP ligado, nenhuma tela do aluno nem o cadastro pode mostrar preço, plano, cobrança, teste grátis, prêmio por indicação ou
// convite para contratar/renovar. Na web tudo continua como antes (o próprio teste confere, para provar que a varredura enxerga o que procura).
//   cd testes && INTUS_DIR=../app/painel node sem_venda.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.jpg': 'image/jpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

const PROIBIDO = /R\$|liberad[oa]s? (por dia|hoje)|renov|regulariz|contrat|assinatur|assine|mensalidade|matr[ií]cula|pagamento|cobran[cç]a|pre[cç]o|teste gr[aá]tis|dias de acesso gr[aá]tis|gr[aá]tis hoje|meses gr[aá]tis|presente especial|indique um amigo|outros planos|escolha (o )?seu plano|plano ativo|ativar (o )?seu plano|liberar todas|quer um plano|🔒 (favoritar|escrever)|do plano alimentar/i;
const dia = n => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
const mens = (ini, fim, pago) => ({ idmensalidade: 1, idatleta: 7, dshistorico: 'Treino anual', dtinicio: ini, dtvencimento: fim, vlpagar: 2987, vldesconto: 0, stpgto: pago ? 'S' : 'N', dtpagamento: pago ? ini : null });
const CENARIOS = {
  'conta nova, sem matrícula': [],
  'cobrança vencida há 60 dias': [mens(dia(-90), dia(-60), false)],
  'em dia, sem plano alimentar': [mens(dia(-30), dia(60), true)],
  'plano vence em 3 dias': [mens(dia(-87), dia(3), true)],
};

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();

  async function abrir(arquivo, nativo, lista, logado, plataforma) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
    await ctx.route(/\/app\/api\/|intusfit\.com\.br/, route => {
      const u = new URL(route.request().url()), acao = u.searchParams.get('action') || '', arq = u.pathname.split('/').pop();
      const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
      // conteúdo real da Nutrição, com um vídeo aberto e um só do plano (a lista do arquivo vem vazia)
      if (arq === 'nutri-conteudo.json') { const c = JSON.parse(fs.readFileSync(path.join(RAIZ, 'nutri-conteudo.json'), 'utf8')); c.videos = [{ id: 'v1', titulo: 'Vídeo aberto', url: 'https://youtu.be/abcdefghijk' }, { id: 'v2', titulo: 'Vídeo fechado', url: '', visibilidade: 'plano' }]; return j(c); }
      if (arq === 'mensalidades.php') return j(lista || []);
      if (arq === 'mensagens.php') return j([]);
      if (acao === 'notificacoes') return j({ notificacoes: [{ idnotificacao: 9, tipo: 'mensalidade', texto: 'Seu plano vence hoje. Combine a renovação.', criado_em: dia(0) + ' 08:00:00', lida: 0 }], nao_lidas: 1 });
      if (acao === 'desafios_abertos' || (arq === 'desafios.php' && acao === 'abertos')) return j([{ iddesafio: 3, nome: 'Desafio pago', pago: 1, valor: 50, dtinicio: dia(0), dtfim: dia(30) }]);
      return j({ error: 'fora do teste' }, 503);
    });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(e.message));
    await page.addInitScript(([nativo, logado, lista, plataforma]) => {
      if (nativo) window.INTUS_NATIVE_APP = true;
      if (plataforma) window.Capacitor = { getPlatform: () => plataforma, isNativePlatform: () => false, Plugins: {} };
      localStorage.setItem('intus-theme', 'claro');
      if (!logado) { localStorage.setItem('intus-intro-vista', '1'); return; }
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' }));
      localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1');
      localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-ajuda-v2', '{"visto":1}');
      localStorage.setItem('intus-mensalidades', JSON.stringify(lista));
    }, [nativo, logado !== false, lista || [], plataforma || '']);
    await page.goto(base + '/' + arquivo, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(2500);
    return { ctx, page, erros };
  }

  // Percorre as telas e devolve [tela, trecho] de tudo que for proibido e estiver visível.
  async function varrer(page, matConfirmada) {
    const achados = [];
    const ver = async (nome) => {
      await page.waitForTimeout(500);
      const texto = await page.evaluate(() => {
        const v = document.querySelector('.view.ativo'), partes = [v ? v.innerText : ''];
        document.querySelectorAll('#menu-overlay, #np-convite, .modal-overlay.open, .toast.show, #toast').forEach(e => { if (e.id !== 'menu-overlay' && e.checkVisibility({ opacityProperty: true, visibilityProperty: true })) partes.push(e.innerText || ''); });   // o menu é conferido aberto, mais abaixo
        return partes.join('\n');
      });
      texto.split('\n').forEach(l => { if (PROIBIDO.test(l)) achados.push(nome + ': ' + l.trim().slice(0, 110)); });
    };
    await page.evaluate((ok) => { document.querySelectorAll('body > div').forEach(d => { const s = getComputedStyle(d); if (s.position === 'fixed' && !['puxar-bola', 'menu-overlay', 'toast'].includes(d.id) && s.display !== 'none' && d.offsetHeight > 300) d.remove(); }); if (ok) { _syncDone = true; _matSyncOk = true; } trocarView('home'); renderHome(); }, matConfirmada !== false);
    await ver('Início');
    await page.evaluate(() => { toggleMenu(); }); await page.waitForTimeout(300);
    const menu = await page.evaluate(() => Array.from(document.querySelectorAll('#menu-overlay .menu-item')).filter(e => e.offsetParent !== null).map(e => e.innerText.trim()).join('\n'));
    menu.split('\n').forEach(l => { if (PROIBIDO.test(l)) achados.push('Menu: ' + l); });
    await page.evaluate(() => { document.getElementById('menu-overlay').classList.remove('open'); try { _destravarFundoMenu(true); } catch (e) {} });
    for (const d of ['perfil', 'matricula', 'avaliacoes', 'desafios', 'ranking', 'feed', 'mensagens', 'nutricao', 'historico', 'resultados', 'amigos', 'quemsomos', 'extras']) {
      await page.evaluate(x => { try { navegar(x); } catch (e) {} }, d);
      await page.waitForTimeout(500);
      await ver(d);
    }
    for (const t of ['receitas', 'videos', 'dicas', 'agua']) {
      await page.evaluate(x => { try { renderNutricaoAluno(); trocarView('nutricao'); } catch (e) {} }, t);
      await page.waitForTimeout(700);
      await page.evaluate(x => { try { nutriTela(x); } catch (e) {} }, t);
      await ver('Nutrição > ' + t);
    }
    await page.evaluate(() => { try { Ajuda.abrir(); } catch (e) {} }); await ver('Ajuda');
    await page.evaluate(() => { try { renderPerfil(); trocarView('perfil'); trocarPerfilTab('dados'); } catch (e) {} }); await ver('Perfil > dados');
    await page.evaluate(() => { try { abrirTreinoForaProtocolo(); } catch (e) {} }); await ver('Treino fora do protocolo');
    await page.evaluate(() => { try { renderNutricaoAluno(); trocarView('nutricao'); } catch (e) {} }); await page.waitForTimeout(600);
    await page.evaluate(() => { try { const r = _nutriCont.receitas.find(x => !_nutriReceitaLiberada(x)); nutriTela('receita', r.id); } catch (e) {} }); await ver('receita fechada');
    await page.evaluate(() => { try { nutriTela('receita', _nutriCont.receitas.find(x => _nutriReceitaLiberada(x)).id); } catch (e) {} }); await ver('receita aberta');
    // a varredura só vale se o conteúdo carregou de verdade
    const cont = await page.evaluate(() => [_nutriCont.receitas.length > 40, _nutriCont.dicas.length > 0, _nutriCont.videos.length]);
    if (JSON.stringify(cont) !== '[true,true,2]') achados.push('conteúdo da Nutrição não carregou: ' + JSON.stringify(cont));
    await page.evaluate(() => { try { nutriTela('video', 'v1'); } catch (e) {} }); await ver('vídeo aberto');
    await page.evaluate(() => { try { nutriConvitePlano('Receita do plano alimentar'); } catch (e) {} }); await ver('convite do plano alimentar');
    await page.evaluate(() => { try { document.getElementById('np-convite')?.remove(); abrirIndicarAmigo(); } catch (e) {} }); await ver('indicar amigo');
    await page.evaluate(() => { try { _centralTab = 'painel'; renderCentral(); trocarView('mensagens'); } catch (e) {} }); await ver('Central');
    // cartões intercalados do Feed e rodapé
    const cartoes = await page.evaluate(() => { const d = document.createElement('div'); d.innerHTML = FEED_EXPLICATIVO_VARIANTES.map(v => _feedCardExplicativo(v, true)).join('') + _indicarAmigoHtml(true, false) + _matriculaBotaoPlanosHtml(); document.body.appendChild(d); const t = d.innerText; d.remove(); return t; });
    cartoes.split('\n').forEach(l => { if (PROIBIDO.test(l)) achados.push('Cartões: ' + l.trim().slice(0, 110)); });
    return Array.from(new Set(achados));
  }

  console.log('\n══ App das lojas: nenhuma venda ══');
  for (const [nome, lista] of Object.entries(CENARIOS)) {
    const t = await abrir('aluno.html', true, lista);
    eq(nome + ': nada de preço, plano ou convite para contratar', await varrer(t.page), []);
    eq(nome + ': sem erro de JavaScript', t.erros, []);
    await t.ctx.close();
  }

  console.log('\n══ A varredura enxerga na web (controle) ══');
  let t = await abrir('aluno.html', false, CENARIOS['conta nova, sem matrícula']);
  const web = await varrer(t.page);
  eq('na web continuam o convite para ativar, a Matrícula e a indicação', [web.length > 4, web.some(l => /matr[ií]cula/i.test(l)), web.some(l => /^Cartões: .*(indique|presente especial)/i.test(l))], [true, true, true]);
  await t.ctx.close();

  console.log('\n══ Conta bloqueada no app das lojas ══');
  t = await abrir('aluno.html', true, CENARIOS['cobrança vencida há 60 dias']);
  await varrer(t.page);
  await t.page.evaluate(() => { trocarView('home'); renderHome(); });
  eq('o aviso é neutro e o WhatsApp é de suporte', await t.page.evaluate(() => { let url = ''; window.open = u => { url = decodeURIComponent(u); }; document.querySelector('#v-home .h-zap').click(); return [document.querySelector('#v-home .h-aviso .tt').textContent, /Preciso de ajuda com o meu acesso no app/.test(url), /pagar|renov|regulariz|plano|matr/i.test(url)]; }), ['Treinos indisponíveis nesta conta', true, false]);
  await t.ctx.close();

  console.log('\n══ Conta grátis: o que abre e o que continua fechado ══');
  const GRATIS = { 'sem matrícula': [], 'vencida há 60 dias': CENARIOS['cobrança vencida há 60 dias'], 'só teste grátis vencido': [Object.assign(mens(dia(-23), dia(-20), true), { dshistorico: 'Teste Grátis (3 dias)' })] };
  for (const nativo of [true, false]) {
    for (const [nome, lista] of Object.entries(GRATIS)) {
      const rot = (nativo ? 'app' : 'web') + ', ' + nome;
      t = await abrir('aluno.html', nativo, lista);
      const r = await t.page.evaluate(async () => {
        const espera = ms => new Promise(r => setTimeout(r, ms));
        document.querySelectorAll('body > div').forEach(d => { const s = getComputedStyle(d); if (s.position === 'fixed' && !['puxar-bola', 'menu-overlay', 'toast'].includes(d.id) && s.display !== 'none' && d.offsetHeight > 300) d.remove(); });
        _syncDone = true; _matSyncOk = true;
        const ir = async f => { trocarView('home'); renderHome(); await espera(100); try { await f(); } catch (e) { return 'erro ' + e.message; } await espera(400); return window._viewAtual; };
        const abre = [];
        for (const d of ['nutricao', 'extras', 'ranking', 'feed']) abre.push(await ir(() => navegar(d)));
        abre.push(await ir(() => abrirCardio())); abre.push(await ir(() => abrirProgramasCardio()));
        const fecha = [];
        for (const d of ['treinos-menu', 'historico', 'amigos']) fecha.push(await ir(() => navegar(d)));
        trocarView('home'); renderHome(); await espera(300);
        const atalhos = Array.from(document.querySelectorAll('#v-home .h-livres .tt')).map(e => e.textContent);
        const publica = _soTesteBarra(), mural = _muralPodeEscrever(), aviso = document.getElementById('toast').textContent;
        renderNutricaoAluno(); trocarView('nutricao'); await espera(900); nutriTela('agua'); await espera(500);
        const chips = (document.getElementById('v-nutricao').innerText.match(/\d+ dias( 🔒)?/g) || []).slice(0, nativo_n());
        function nativo_n() { return API.semVenda() ? 2 : 3; }
        nutriAguaPer(30); await espera(200); const p30 = /\/30/.test(document.getElementById('v-nutricao').innerText);
        nutriAguaPer(90); await espera(200); const p90 = /\/90/.test(document.getElementById('v-nutricao').innerText);
        return { abre, fecha, atalhos, publica, mural, aviso, chips, p30, p90 };
      });
      eq(rot + ': abrem Nutrição, Extras, ranking, Feed, cardio e programas', r.abre, ['nutricao', 'extras', 'ranking', 'feed', 'cardio', 'cardio-prog']);
      eq(rot + ': treinos, histórico e Comunidade continuam fechados', r.fecha, ['home', 'home', 'home']);
      eq(rot + ': atalhos na tela inicial', r.atalhos, ['Cardio', 'Ranking', 'Nutrição', 'Extras', 'Feed', 'Avaliações', 'Meus Dados'].concat(nativo ? [] : ['Matrícula']));
      eq(rot + ': não publica, não comenta nem escreve no mural', [r.publica, r.mural], [false, false]);
      if (nativo) eq(rot + ': o aviso do bloqueio é neutro', PROIBIDO.test(r.aviso) || /\bplano/i.test(r.aviso), false);
      eq(rot + ': água com 7 e 30 dias; 90 fica de fora', [r.chips, r.p30, r.p90], [nativo ? ['7 dias', '30 dias'] : ['7 dias', '30 dias', '90 dias 🔒'], true, false]);
      eq(rot + ': sem erro de JavaScript', t.erros, []);
      await t.ctx.close();
    }
  }
  t = await abrir('aluno.html', true, CENARIOS['em dia, sem plano alimentar']);
  eq('quem está em dia publica e comenta', await t.page.evaluate(() => { _syncDone = true; _matSyncOk = true; return [_soTesteBarra(), _muralPodeEscrever()]; }), [true, true]);
  await t.ctx.close();

  console.log('\n══ Android: aviso do site, só em texto ══');
  const FRASE = 'Os planos são contratados no nosso site, intusfit.com.br.';
  for (const [nome, lista] of Object.entries(CENARIOS)) {
    t = await abrir('aluno.html', true, lista, true, 'android');
    const fora = (await varrer(t.page)).filter(l => !l.endsWith(FRASE));
    eq('Android, ' + nome + ': fora a frase aprovada, nada de venda', fora, []);
    eq('Android, ' + nome + ': sem erro de JavaScript', t.erros, []);
    await t.ctx.close();
  }
  for (const [plat, quer] of [['android', 1], ['ios', 0]]) {
    t = await abrir('aluno.html', true, CENARIOS['cobrança vencida há 60 dias'], true, plat);
    await varrer(t.page);
    const r = await t.page.evaluate(async (frase) => {
      trocarView('home'); renderHome(); const home = document.getElementById('v-home');
      const naHome = home.innerText.split(frase).length - 1;
      const clicavel = Array.from(home.querySelectorAll('.h-site')).some(e => e.closest('a,button,[onclick]') || e.querySelector('a,button,[onclick]'));
      await renderMatricula(); const conta = document.getElementById('v-matricula').textContent.split(frase).length - 1;
      return [naHome, conta, clicavel];
    }, FRASE);
    eq(plat + ': frase no aviso do início e em Minha conta, sem link', r, [quer, quer, false]);
    await t.ctx.close();
  }

  console.log('\n══ Cadastro ══');
  for (const nativo of [true, false]) {
    t = await abrir('login.html', nativo, [], false);
    const r = await t.page.evaluate(() => {
      document.getElementById('btn-criar-conta').click();
      document.getElementById('nc-nome').value = 'Pessoa Teste'; document.getElementById('nc-email').value = 'pessoa@teste.invalid';
      const passo = () => [1, 2, 3, 4].find(n => document.getElementById('nc-step-' + n).style.display !== 'none');
      ncNextStep(2); const textos = [document.getElementById('mod-criar').innerText];
      ncNextStep(3); const depois = passo(); textos.push(document.getElementById('mod-criar').innerText);
      ncNextStep(3); const voltando = passo();
      return { depois, voltando, planos: document.querySelectorAll('input[name="nc-plano"]').length, texto: textos.join('\n') };
    });
    if (nativo) {
      eq('no app: de objetivos vai direto para a senha, e voltar retorna a objetivos', [r.depois, r.voltando, r.planos], [4, 2, 0]);
      eq('no app: o cadastro não fala de plano, preço ou teste', r.texto.split('\n').filter(l => PROIBIDO.test(l) || /\bplano/i.test(l)), []);
    } else eq('na web a etapa de planos continua', [r.depois, r.planos > 1], [3, true]);
    eq('cadastro sem erro de JavaScript (' + (nativo ? 'app' : 'web') + ')', t.erros, []);
    await t.ctx.close();
  }

  if (process.env.FOTOS) {   // capturas para conferir com os olhos
    for (const [i, nome] of [['conta', 'conta nova, sem matrícula'], ['dia', 'em dia, sem plano alimentar']]) {
      const f = await abrir('aluno.html', true, CENARIOS[nome]);
      await f.page.evaluate(() => { document.querySelectorAll('body > div').forEach(d => { const s = getComputedStyle(d); if (s.position === 'fixed' && !['puxar-bola', 'menu-overlay', 'toast'].includes(d.id) && s.display !== 'none' && d.offsetHeight > 300) d.remove(); }); _syncDone = true; _matSyncOk = true; trocarView('home'); renderHome(); });
      await f.page.waitForTimeout(900); await f.page.screenshot({ path: process.env.FOTOS + '/sv-' + i + '-inicio.png' });
      await f.page.evaluate(() => { renderNutricaoAluno(); trocarView('nutricao'); }); await f.page.waitForTimeout(1500); await f.page.screenshot({ path: process.env.FOTOS + '/sv-' + i + '-nutri.png' });
      await f.ctx.close();
    }
  }

  await b.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
