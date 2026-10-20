// Analytics de captação, lado do navegador: o rastreador (trafego.js) nas páginas públicas e a tela do painel (trafego.html).
//   cd testes && INTUS_DIR=../app/painel node trafego_front.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const SITE = path.resolve(process.env.INTUS_DIR || '../app/painel', '../..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p.endsWith('/')) p += 'index.html';
    const f = path.join(SITE, p);
    if (!f.startsWith(SITE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8096, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8096';
  const browser = await chromium.launch();

  // ── rastreador ──
  async function novaPagina(ctx, opts = {}) {
    const page = await ctx.newPage();
    const envios = [], erros = [];
    page.on('pageerror', e => { if (!/Failed to update a ServiceWorker/i.test(String(e.message))) erros.push(String(e.message)); });
    await page.route('**/trafego.php**', route => {
      const req = route.request(), u = new URL(req.url());
      let corpo = {}; try { corpo = JSON.parse(req.postData() || '{}'); } catch (e) {}
      envios.push({ acao: u.searchParams.get('action'), corpo });
      if (opts.cair) return route.abort('failed');
      route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
    });
    await page.route('**/app/api/aluno_login.php**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, atleta: { idatleta: 321, nome: 'Teste', email: 't@t.com', server_token: 'tok' } }) }));
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    return { page, envios, erros };
  }
  console.log('\n══ Rastreador nas páginas públicas ══');
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  let { page, envios, erros } = await novaPagina(ctx);
  await page.goto(B + '/teste-gratis.html?utm_source=instagram&utm_medium=stories&utm_campaign=Outubro&utm_content=story-a5&ref=42', { waitUntil: 'load' });
  await page.waitForTimeout(700);
  const v1 = envios.find(e => e.acao === 'visita');
  ver('envia a visita ao abrir a página', v1, envios);
  eq('com UTM e indicação da URL', [v1.corpo.utm_source, v1.corpo.utm_medium, v1.corpo.utm_campaign, v1.corpo.utm_content, v1.corpo.ref], ['instagram', 'stories', 'Outubro', 'story-a5', '42']);
  eq('na página certa, com os identificadores anônimos', [v1.corpo.pagina, /^[a-z0-9]{8,40}$/.test(v1.corpo.vid), /^[a-z0-9]{8,40}$/.test(v1.corpo.sid), v1.corpo.w], ['/teste-gratis.html', true, true, 390]);
  eq('NÃO manda nome, e-mail, telefone nem nada pessoal', Object.keys(v1.corpo).filter(k => !['vid', 'sid', 'pagina', 'referrer', 'ref', 'w', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].includes(k)), []);
  // funil: preencher o formulário
  await page.click('#lead-nome'); await page.click('#lead-whats');
  await page.fill('#lead-nome', 'Maria Teste'); await page.fill('#lead-whats', '45999999999'); await page.fill('#lead-email', 'maria@teste.com');
  await page.check('input[name="genero"] >> nth=0', { force: true }).catch(() => {});
  await page.click('#btn-continuar');
  await page.waitForTimeout(200);
  await page.fill('#lead-senha', 'segredo123'); await page.fill('#lead-senha-confirma', 'segredo123');
  await page.click('#btn-enviar');
  await page.waitForTimeout(800);
  const evs = envios.filter(e => e.acao === 'evento').map(e => e.corpo.evento);
  eq('começar o formulário vale uma vez só', evs.filter(x => x === 'form_inicio').length, 1);
  ver('passar da 1ª etapa é registrado', evs.includes('etapa1_ok'), evs);
  const conta = envios.find(e => e.corpo.evento === 'conta_criada');
  ver('criar o acesso grátis é registrado, com o id e o rótulo "indicado"', conta && conta.corpo.valor === 321 && conta.corpo.rotulo === 'indicado', conta);
  eq('nenhum dado digitado vai para a medição', JSON.stringify(envios).includes('maria@teste.com') || JSON.stringify(envios).includes('segredo123') || JSON.stringify(envios).includes('45999999999'), false);
  await page.evaluate(() => { document.getElementById('link-whats').addEventListener('click', e => e.preventDefault(), true); });
  await page.click('#link-whats');
  await page.waitForTimeout(300);
  const zap = envios.find(e => e.corpo.evento === 'whatsapp' && /Maria Teste|teste gr/i.test(e.corpo.rotulo || ''));
  ver('clique no WhatsApp registra o texto da mensagem', zap, envios.map(e => e.corpo));
  eq('sem erro de script na página', erros, []);

  console.log('\n══ Mesma pessoa em outra página ══');
  envios.length = 0;
  await page.goto(B + '/index.html', { waitUntil: 'load' });
  await page.waitForTimeout(600);
  const v2 = envios.find(e => e.acao === 'visita');
  eq('mesmo visitante e mesma sessão', [v2.corpo.vid === v1.corpo.vid, v2.corpo.sid === v1.corpo.sid], [true, true]);
  eq('a indicação continua valendo por 30 dias e o UTM durante a sessão', [v2.corpo.ref, v2.corpo.utm_campaign], ['42', 'Outubro']);
  eq('manda o caminho cru (o servidor trata /index.html como "/")', v2.corpo.pagina, '/index.html');
  await page.evaluate(() => document.querySelector('a[href*="wa.me"]').addEventListener('click', e => e.preventDefault(), true));
  await page.click('a[href*="wa.me"] >> nth=0', { force: true });
  await page.waitForTimeout(300);
  const z2 = envios.find(e => e.corpo.evento === 'whatsapp');
  ver('clique em plano no site registra o texto do botão', z2 && /contratar|saber mais/i.test(z2.corpo.rotulo), z2);
  const outraAba = await novaPagina(ctx);
  await outraAba.page.goto(B + '/index.html', { waitUntil: 'load' }); await outraAba.page.waitForTimeout(500);
  const v3 = outraAba.envios.find(e => e.acao === 'visita');
  eq('aba nova = sessão nova do mesmo visitante, sem o UTM antigo e com a indicação', [v3.corpo.vid === v1.corpo.vid, v3.corpo.sid !== v1.corpo.sid, v3.corpo.utm_campaign || null, v3.corpo.ref], [true, true, null, '42']);

  console.log('\n══ Equipe logada e falha do servidor ══');
  const ctxEq = await browser.newContext();
  await ctxEq.addInitScript(() => localStorage.setItem('mx-user', JSON.stringify({ tipo: 'professor', idusuario: 3, admin: 0 })));
  const pe = await novaPagina(ctxEq);
  await pe.page.goto(B + '/teste-gratis.html', { waitUntil: 'load' }); await pe.page.waitForTimeout(500);
  eq('quem está logado no painel não é contado', pe.envios.length, 0);
  const ctxCai = await browser.newContext();
  const pc = await novaPagina(ctxCai, { cair: true });
  await pc.page.goto(B + '/teste-gratis.html', { waitUntil: 'load' }); await pc.page.waitForTimeout(500);
  await pc.page.click('#lead-nome');
  eq('servidor de medição fora do ar não derruba a página', [pc.erros, await pc.page.evaluate(() => !!document.getElementById('form-lead'))], [[], true]);

  // ── tela do painel ──
  console.log('\n══ Tela do painel (trafego.html) ══');
  const relatorio = {
    periodo: { de: '2026-09-20', ate: '2026-10-19', dias: 30, anterior_de: '2026-08-21', anterior_ate: '2026-09-19', pagina: null },
    kpis: { visitas: 1200, visitantes: 800, sessoes: 900, novos: 600, conversoes: 40, contas_criadas: 37, contatos_sem_conta: 3, cliques: 150, taxa: 5.0 },
    kpis_anterior: { visitas: 1000, visitantes: 700, sessoes: 800, novos: 500, conversoes: 20, cliques: 100, taxa: 2.9 },
    serie: Array.from({ length: 30 }, (_, i) => ({ dia: '2026-09-' + String(20 + i > 30 ? 30 : 20 + i).padStart(2, '0'), visitas: 30 + i, visitantes: 20 + i, conversoes: i % 5 })),
    dia_semana: [10, 20, 30, 40, 50, 60, 70], horas: Array.from({ length: 24 }, (_, i) => i * 3),
    paginas: [{ pagina: '/teste-gratis.html', visitas: 700, visitantes: 500, cliques: 90, conversoes: 40, taxa: 8.0 }, { pagina: '/', visitas: 500, visitantes: 400, cliques: 60, conversoes: 0, taxa: 0 }],
    canais: [{ canal: 'instagram', visitas: 500, visitantes: 400, conversoes: 25, taxa: 6.3 }, { canal: 'indicacao', visitas: 100, visitantes: 80, conversoes: 10, taxa: 12.5 }],
    campanhas: [{ utm_source: 'instagram', utm_medium: 'stories', utm_campaign: '<img src=x onerror=window.__xss=1>', utm_content: 'story-a5', visitas: 300, visitantes: 250, conversoes: 15, taxa: 6.0 }],
    sites_origem: [{ host: 'l.instagram.com', visitas: 300, visitantes: 250 }],
    indicacoes: [{ ref_idatleta: 42, nome: 'Aluna Exemplo', visitas: 50, visitantes: 40, conversoes: 6 }],
    funil: [{ etapa: 'visita', rotulo: 'Visitaram a página', n: 500 }, { etapa: 'form_inicio', rotulo: 'Começaram a preencher', n: 120 }, { etapa: 'etapa1_ok', rotulo: 'Passaram da 1ª etapa', n: 80 }, { etapa: 'conta_criada', rotulo: 'Criaram o acesso grátis', n: 40 }],
    eventos: [{ evento: 'whatsapp', rotulo: 'Quero contratar o plano de Treino', n: 30, visitantes: 25 }],
    aparelhos: [{ nome: 'celular', visitantes: 700 }], navegadores: [{ nome: 'Instagram', visitantes: 300 }], sistemas: [{ nome: 'iOS', visitantes: 400 }],
    paises: [{ nome: 'BR', visitantes: 790 }], cidades: [{ nome: 'Cascavel - PR', visitantes: 200 }],
    agora: { ativos: 3, ultimas: [{ criado_em: '2026-10-19 15:00:00', pagina: '/', canal: 'instagram', utm_campaign: null, ref_idatleta: null, aparelho: 'celular', cidade: 'Cascavel', regiao: 'PR', novo: 1 }] },
    banco: { leads: 12, leads_por_origem: [{ origem: 'landing-teste-gratis', n: 3 }, { origem: 'app', n: 9 }], leads_indicados: 3 }, armazenado: { visitas: 5000, eventos: 900, desde: '2024-01-10' }, gerado_em: '2026-10-19 15:00:00',
  };
  const pessoa = (o) => Object.assign({ tipo: 'conta', id: 1, medido: true, criado_em: '2026-10-18 10:00:00', canal: 'instagram', utm: { campaign: 'outubro', content: 'story-a5' }, ref: null, indicado_por: null, telefone: '(45) 99999-1111', whatsapp: '5545999991111', email: 'a@t.com', objetivo: '', treinos: 0, ultimo_treino: null, abriu_app: false, aberturas: 0, teste: { dias_restantes: 2, expirou: false }, matricula: null, bloqueado: false, acao: 'Dar boas-vindas e ajudar a entrar no app.' }, o);
  const leadsCanned = {
    periodo: { de: '2026-09-20', ate: '2026-10-19' }, captadas_medidas: 6, sem_medicao: 1, gerado_em: '',
    etapas: [{ etapa: 'contato', rotulo: 'Deixou só o contato (sem conta)' }, { etapa: 'so_conta', rotulo: 'Criou o acesso, nunca abriu o app' }, { etapa: 'abriu_app', rotulo: 'Abriu o app, ainda não treinou' }, { etapa: 'treinando', rotulo: 'Em teste: já treinou' }, { etapa: 'teste_expirado', rotulo: 'Teste acabou, sem comprar' }, { etapa: 'negociando', rotulo: 'Matrícula criada, falta pagar' }, { etapa: 'cliente', rotulo: 'Virou cliente' }],
    hoje: '2026-10-19', resumo: { contato: 1, so_conta: 2, abriu_app: 0, treinando: 1, teste_expirado: 1, negociando: 0, cliente: 1 },
    pessoas: [
      pessoa({ chave: 'a1', id: 1, nome: 'Ana Souza', wa: { contato: true, saiu: false, enviadas: 2, recebidas: 1, respondeu: true, precisa_humano: true, modo: 'robo', pode_responder: true, janela_aberta: true }, controle: { ultimo: { status: 'retornar', status_rotulo: 'Retornar depois', canal: 'whatsapp_link', nota: 'Quer o preço do trimestral', retorno_em: '2026-10-10', usuario: 'Luiz Nunes', criado_em: '2026-10-18 15:00:00' }, total: 2, historico: [{ status: 'retornar', usuario: 'Luiz Nunes', criado_em: '2026-10-18 15:00:00', nota: 'Quer o preço do trimestral' }, { status: 'chamado', usuario: 'Luiz Nunes', criado_em: '2026-10-18 14:00:00', nota: '' }] }, etapa: 'so_conta', etapa_rotulo: 'Criou o acesso, nunca abriu o app', mensagem: 'Oi Ana, tudo bem? Aqui é da Intus Fit. Vi que você criou o seu acesso ao teste grátis.' }),
      pessoa({ chave: 'a2', id: 2, nome: 'Daniel <b>Rocha</b>', telefone: '(45) 96666-4444', whatsapp: '5545966664444', etapa: 'teste_expirado', etapa_rotulo: 'Teste acabou, sem comprar', criado_em: '2026-10-10 10:00:00', treinos: 3, ultimo_treino: '2026-10-12', abriu_app: true, aberturas: 4, teste: { dias_restantes: -6, expirou: true }, acao: 'Melhor hora para oferecer o plano.', mensagem: 'Oi Daniel, tudo bem? Aqui é da Intus Fit. O seu teste grátis terminou.' }),
      pessoa({ chave: 'a3', id: 3, nome: 'Carla Dias', wa: { contato: true, saiu: true, enviadas: 3, recebidas: 0, respondeu: false, precisa_humano: false, modo: 'robo', pode_responder: false, janela_aberta: false }, controle: { ultimo: { status: 'sem_interesse', status_rotulo: 'Sem interesse', canal: 'ligacao', nota: '', retorno_em: null, usuario: 'Luiz Nunes', criado_em: '2026-10-17 10:00:00' }, total: 1, historico: [] }, canal: 'indicacao', indicado_por: 'Indicadora Oficial', etapa: 'treinando', etapa_rotulo: 'Em teste: já treinou', treinos: 2, ultimo_treino: '2026-10-19', abriu_app: true, aberturas: 2, mensagem: 'Oi Carla, tudo bem?' }),
      pessoa({ chave: 'a5', id: 5, nome: 'Elisa Prado', etapa: 'cliente', etapa_rotulo: 'Virou cliente', matricula: { plano: 'Trimestral', pago: true }, mensagem: '' }),
      pessoa({ chave: 'l1', tipo: 'contato', id: 1, nome: 'Gabriela Alves', telefone: '45933337777', whatsapp: '5545933337777', objetivo: 'Emagrecer', etapa: 'contato', etapa_rotulo: 'Deixou só o contato (sem conta)', teste: null, mensagem: 'Oi Gabriela, tudo bem?' }),
      pessoa({ chave: 'a8', id: 8, nome: 'Heitor Cruz', medido: false, canal: null, utm: {}, etapa: 'so_conta', etapa_rotulo: 'Criou o acesso, nunca abriu o app', telefone: '', whatsapp: '', mensagem: 'Oi Heitor, tudo bem?' }),
    ],
  };
  async function painel(usuario) {
    const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    await c.addInitScript((u) => { localStorage.setItem('mx-token', 'teste'); localStorage.setItem('mx-user', JSON.stringify(u)); }, usuario);
    const pg = await c.newPage(); const er = []; pg.on('pageerror', e => { if (!/Failed to update a ServiceWorker/i.test(String(e.message))) er.push(String(e.message)); });
    const chamadas = [], apagou = [], posts = [];
    await pg.route('**/trafego.php**', r => {
      const u = r.request().url(); chamadas.push(u);
      if (r.request().method() === 'POST') {
        const b = JSON.parse(r.request().postData() || '{}'); posts.push({ u, b });
        if (/action=responder_wa/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
        const reg = { status: b.status, status_rotulo: b.status, canal: b.canal, nota: b.nota || '', retorno_em: b.retorno_em || null, usuario: 'Luiz Nunes', criado_em: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 19).replace('T', ' ') };
        return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, registro: reg, controle: { ultimo: reg, total: 1, historico: [reg] } }) });
      }
      if (r.request().method() === 'DELETE') { apagou.push(u); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, apagadas: 120 }) }); }
      const corpo = u.includes('action=agora') ? { agora: relatorio.agora, hoje: relatorio.kpis }
        : u.includes('action=leads') ? leadsCanned
        : u.includes('action=limpar_previa') ? { dias: 365, corte: '2025-10-19', visitas: 100, eventos: 20, total: 120, mais_antiga: '2024-01-10', armazenado: { visitas: 5000, eventos: 900, desde: '2024-01-10' } }
        : relatorio;
      r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(corpo) });
    });
    await pg.route(/\/app\/api\/(?!trafego\.php)/, r => r.abort('failed'));
    await pg.goto(B + '/app/painel/trafego.html', { waitUntil: 'load' });
    await pg.waitForTimeout(1500);
    return { pg, er, chamadas, apagou, posts, c };
  }
  const adm = await painel({ tipo: 'admin', idusuario: 1, admin: 1, nome: 'Luiz Nunes', permissoes: {} });
  const texto = await adm.pg.evaluate(() => document.getElementById('main-content').innerText);
  ver('mostra os números principais', /800/.test(texto) && /1\.200/.test(texto) && /pessoas captadas/i.test(texto), texto.slice(0, 300));
  ver('compara com o período anterior', /vs período anterior/.test(texto));
  ver('mostra o funil, as páginas, as indicações e as campanhas', /funil do teste grátis/i.test(texto) && /teste grátis/i.test(texto) && /Aluna Exemplo/.test(texto) && /story-a5/.test(texto));
  eq('texto vindo da internet nunca vira HTML (campanha maliciosa)', await adm.pg.evaluate(() => window.__xss || null), null);
  const link = await adm.pg.evaluate(() => { document.getElementById('g-camp').value = 'Teste Grátis Outubro'; document.getElementById('g-cont').value = 'Story A5'; gerarLink(); return document.getElementById('g-url').textContent; });
  eq('gerador de link: UTM correto e sem acento ou espaço', link, 'https://intusfit.com.br/teste-gratis.html?utm_source=instagram&utm_medium=stories&utm_campaign=teste-gratis-outubro&utm_content=story-a5');
  await adm.pg.click('.tf-chip >> text=7 dias'); await adm.pg.waitForTimeout(400);
  ver('trocar o período pede o relatório de novo com as datas', adm.chamadas.some(u => /action=relatorio&de=\d{4}-\d{2}-\d{2}&ate=\d{4}-\d{2}-\d{2}/.test(u)) && adm.chamadas.length >= 2, adm.chamadas);
  eq('sem erro de script', adm.er, []);
  console.log('\n══ Gráfico menor e atualização automática ══');
  const alt = await adm.pg.evaluate(() => { const b = document.querySelector('#tf-serie svg').getBoundingClientRect(); return { h: Math.round(b.height), w: Math.round(b.width), caixa: Math.round(document.getElementById('tf-serie').getBoundingClientRect().height), barras: document.querySelectorAll('#tf-serie rect.b').length, path: !!document.querySelector('#tf-serie path') }; });
  ver('o gráfico tem no máximo 140 px de altura (antes passava de 300) e ocupa a largura', alt.h <= 140 && alt.w > 400, alt);
  ver('desenha as barras e a linha suave das captadas', alt.barras >= 25 && alt.path, alt);
  eq('o intervalo de atualização é de 1 minuto', await adm.pg.evaluate(() => INTERVALO_ATUALIZACAO_MS), 60000);
  ver('mostra a hora da última atualização', /atualiza sozinho a cada minuto/.test(await adm.pg.innerText('#tf-att')));
  await adm.pg.fill('#g-camp', 'minha campanha'); await adm.pg.selectOption('#g-src', 'whatsapp');
  await adm.pg.evaluate(() => document.getElementById('tf-avancado').open = true);
  await adm.pg.evaluate(() => window.scrollTo(0, 500));
  const antesCh = adm.chamadas.length;
  await adm.pg.evaluate(() => { document.getElementById('g-camp').blur(); return atualizarSilencioso(false); });
  await adm.pg.waitForTimeout(400);
  const dep = await adm.pg.evaluate(() => ({ camp: document.getElementById('g-camp').value, src: document.getElementById('g-src').value, aberto: document.getElementById('tf-avancado').open, y: Math.round(window.scrollY), url: document.getElementById('g-url').textContent }));
  ver('a atualização pede os números de novo', adm.chamadas.length === antesCh + 1, [antesCh, adm.chamadas.length]);
  eq('e não apaga o que você estava preenchendo, nem fecha a seção, nem muda a rolagem', [dep.camp, dep.src, dep.aberto, dep.y > 300], ['minha campanha', 'whatsapp', true, true]);
  ver('o link do gerador acompanha os campos preservados', /utm_source=whatsapp/.test(dep.url) && /utm_campaign=minha-campanha/.test(dep.url), dep.url);
  await adm.pg.focus('#g-camp');
  const antes2 = adm.chamadas.length;
  await adm.pg.evaluate(() => atualizarSilencioso(false)); await adm.pg.waitForTimeout(200);
  eq('enquanto você digita no gerador, a rodada é pulada', adm.chamadas.length, antes2);
  await adm.pg.evaluate(() => document.getElementById('g-camp').blur());
  await adm.pg.evaluate(() => { document.getElementById('tf-avancado').open = false; });

  console.log('\n══ Conferência com o banco e apagar dados ══');
  ver('a conferência explica e compara (contas e contatos medidos x banco)', /contas de teste grátis criadas/i.test(texto) && /batem|a mais|a menos/i.test(texto) === true, texto.slice(texto.indexOf('CONFER'), texto.indexOf('CONFER') + 400));
  ver('o aviso do banco diz quando o banco tem contatos a mais (aqui 3 = 3: batem)', /batem/i.test(texto));
  ver('a opção de apagar fica escondida (só aparece em "Opções avançadas")', !(await adm.pg.locator('.tf-btn.perigo').first().isVisible()) && /opções avançadas/i.test(texto), texto.slice(-200));
  await adm.pg.click('#tf-avancado > summary');
  ver('abrindo "Opções avançadas" o botão aparece', await adm.pg.locator('.tf-btn.perigo').first().isVisible());
  const corBtn = await adm.pg.evaluate(() => { document.body.classList.add('theme-light'); const b = document.querySelector('.tf-btn.perigo'); const cs = getComputedStyle(b); return { cor: cs.color, fundo: getComputedStyle(document.body).backgroundColor, txt: b.textContent.trim() }; });
  ver('o botão de apagar é visível no tema claro (texto não é branco sobre fundo claro)', corBtn.cor !== 'rgb(255, 255, 255)' && corBtn.txt.length > 3, corBtn);
  await adm.pg.evaluate(() => document.body.classList.remove('theme-light'));
  await adm.pg.click('.tf-btn.perigo >> text=Apagar medições antigas');
  ver('passo 1: explica o que sai e o que NÃO sai', /não dá para desfazer/i.test(await adm.pg.innerText('#tf-modal')) && /clientes, leads, matrículas/i.test(await adm.pg.innerText('#tf-modal')));
  await adm.pg.click('#tf-modal >> text=Cancelar');
  eq('cancelar fecha e não apaga nada', [await adm.pg.locator('#tf-modal').count(), adm.apagou.length], [0, 0]);
  await adm.pg.click('.tf-btn.perigo >> text=Apagar medições antigas');
  await adm.pg.click('#tf-modal >> text=Ver o que seria apagado');
  await adm.pg.waitForTimeout(400);
  const p2 = await adm.pg.innerText('#tf-modal');
  ver('passo 2: mostra quantas visitas e cliques sairiam e quantos ficam', /100/.test(p2) && /20/.test(p2) && /4\.900/.test(p2) && /backup/i.test(p2), p2);
  eq('a prévia não apagou nada', adm.apagou.length, 0);
  await adm.pg.click('#tf-modal >> text=Continuar');
  ver('passo 3: "Continuar" começa travado', await adm.pg.locator('#lp-prox').isDisabled());
  await adm.pg.check('#lp-c1'); await adm.pg.check('#lp-c2'); await adm.pg.fill('#lp-txt', 'apagar');
  ver('só as caixas e "apagar" em minúsculas não destravam', await adm.pg.locator('#lp-prox').isDisabled());
  await adm.pg.fill('#lp-txt', 'APAGAR');
  ver('caixas marcadas e APAGAR digitado destravam', !(await adm.pg.locator('#lp-prox').isDisabled()));
  await adm.pg.click('#lp-prox');
  ver('passo 4: o botão final começa travado, com contagem', await adm.pg.locator('#lp-final').isDisabled() && /\(\d\)/.test(await adm.pg.locator('#lp-final').innerText()));
  eq('até aqui, nenhum pedido de apagar saiu', adm.apagou.length, 0);
  await adm.pg.waitForTimeout(9000);
  ver('depois da contagem o botão libera', !(await adm.pg.locator('#lp-final').isDisabled()));
  await adm.pg.click('#lp-final');
  await adm.pg.waitForTimeout(500);
  eq('um único pedido de apagar, com a palavra APAGAR, o total visto e no mínimo 365 dias', [adm.apagou.length, /confirmo=APAGAR/.test(adm.apagou[0] || ''), /esperadas=120/.test(adm.apagou[0] || ''), Number((/dias=(\d+)/.exec(adm.apagou[0] || '') || [])[1]) >= 365], [1, true, true, true]);
  ver('mostra o resultado', /120 registro/.test(await adm.pg.innerText('#tf-modal')));
  eq('sem erro de script nos fluxos de apagar', adm.er, []);
  console.log('\n══ Pessoas captadas: de quem são e em que etapa estão ══');
  const linkKpi = await adm.pg.evaluate(() => { const a = document.querySelector('a.tf-kpi-link'); return a ? [a.getAttribute('href'), a.innerText] : null; });
  ver('"Pessoas captadas" em Tráfego é um link para a lista, com o mesmo período', linkKpi && /^captados\.html\?de=\d{4}-\d{2}-\d{2}&ate=\d{4}-\d{2}-\d{2}$/.test(linkKpi[0]) && /ver quem são/i.test(linkKpi[1]), linkKpi);
  const cap = await painel({ tipo: 'admin', idatleta: 0, idusuario: 1, admin: 1, nome: 'Luiz Nunes', permissoes: {} });
  await cap.pg.goto(B + '/app/painel/captados.html?de=2026-09-20&ate=2026-10-19', { waitUntil: 'load' }); await cap.pg.waitForTimeout(1500);
  const tx = await cap.pg.innerText('#main-content');
  ver('mostra as pessoas com nome e telefone', /Ana Souza/.test(tx) && /Gabriela Alves/.test(tx) && /45933337777/.test(tx), tx.slice(0, 300));
  eq('os contadores por etapa vêm do servidor', await cap.pg.evaluate(() => [...document.querySelectorAll('.cp-et')].map(b => b.querySelector('.n').textContent)), ['1', '2', '0', '1', '1', '0', '1']);
  const zaps = await cap.pg.evaluate(() => [...document.querySelectorAll('tr[data-chave="a1"] a.zap, tr[data-chave="a2"] a.zap')].map(a => a.getAttribute('href')));
  ver('o botão do WhatsApp abre a conversa com o número e a mensagem da etapa', zaps.length === 2 && zaps[0].startsWith('https://wa.me/5545999991111?text=') && decodeURIComponent(zaps[0]).includes('Oi Ana') && zaps[1].includes('5545966664444'), zaps);
  eq('cliente não tem botão de abordagem', await cap.pg.evaluate(() => ({ botao: !!document.querySelector('tr[data-chave="a5"] a.zap'), txt: document.querySelector('tr[data-chave="a5"]').innerText.includes('já é cliente') })), { botao: false, txt: true });
  eq('quem não tem telefone aparece com o botão desativado', await cap.pg.evaluate(() => document.querySelector('tr[data-chave="a8"] a.zap').getAttribute('aria-disabled')), 'true');
  ver('avisa que uma pessoa não tem a origem medida', /não têm a origem medida|não tem a origem medida/i.test(tx) && /origem não medida/.test(tx), tx.slice(0, 600));
  eq('indicação mostra quem indicou', await cap.pg.evaluate(() => document.querySelector('tr[data-chave="a3"]').innerText.includes('indicado por Indicadora Oficial')), true);
  eq('texto vindo da internet (nome) nunca vira HTML', await cap.pg.evaluate(() => document.querySelectorAll('tr[data-chave="a2"] b').length), 0);
  await cap.pg.click('.cp-et >> nth=4');
  eq('clicar numa etapa filtra a lista', await cap.pg.evaluate(() => [...document.querySelectorAll('#cp-lista tbody tr')].map(r => r.dataset.chave)), ['a2']);
  await cap.pg.click('.cp-et >> nth=4');
  await cap.pg.fill('#cp-busca', '9333'); await cap.pg.waitForTimeout(200);
  eq('a busca acha por pedaço do telefone', await cap.pg.evaluate(() => [...document.querySelectorAll('#cp-lista tbody tr')].map(r => r.dataset.chave)), ['l1']);
  await cap.pg.fill('#cp-busca', '');
  await cap.pg.selectOption('.cp-sel', 'prioridade'); await cap.pg.waitForTimeout(200);
  eq('"quem contatar primeiro": retorno vencido, depois quem nunca foi chamado (teste que acabou antes de só conta), depois já contatados, encerrados e cliente por último', await cap.pg.evaluate(() => [...document.querySelectorAll('#cp-lista tbody tr')].map(r => r.dataset.chave)), ['a1', 'a2', 'a8', 'l1', 'a3', 'a5']);
  console.log('\n══ Controle de contato e WhatsApp ══');
  const rc = await cap.pg.evaluate(() => [...document.querySelectorAll('.cp-rc')].map(b => b.innerText.replace(/\s+/g, ' ')));
  eq('resumo do contato: não contatados, contatados, retornos vencidos, encerrados', rc, ['Ainda não contatados 3', 'Já contatados 1', 'Retornar hoje ou atrasado 1', 'Encerrados (sem interesse, fechou, número errado) 1']);
  const linhaA1 = await cap.pg.innerText('tr[data-chave="a1"]');
  ver('mostra quem chamou, quando, a anotação, o retorno vencido e o histórico', /Retornar depois/.test(linhaA1) && /Luiz Nunes/.test(linhaA1) && /Quer o preço do trimestral/.test(linhaA1) && /hoje ou atrasado/.test(linhaA1) && /Ver histórico \(2\)/.test(linhaA1), linhaA1);
  ver('mostra o que já houve no WhatsApp da Intus (respondeu, pediu atendimento humano)', /recebeu 2 mensagem/.test(linhaA1) && /respondeu/.test(linhaA1) && /atendimento humano/.test(linhaA1), linhaA1);
  eq('quem ainda não foi contatado aparece assim', (await cap.pg.innerText('tr[data-chave="a2"]')).includes('Ainda não contatado'), true);
  const linhaA3 = await cap.pg.innerText('tr[data-chave="a3"]');
  ver('quem pediu para parar: aviso e SEM botão de chamar nem de responder', /Pediu para não receber/.test(linhaA3) && (await cap.pg.locator('tr[data-chave="a3"] a.zap').count()) === 0 && (await cap.pg.locator('tr[data-chave="a3"] >> text=Responder pelo painel').count()) === 0, linhaA3);
  eq('"Responder pelo painel" só aparece onde a janela de 24 h está aberta', [await cap.pg.locator('tr[data-chave="a1"] >> text=Responder pelo painel').count(), await cap.pg.locator('tr[data-chave="a2"] >> text=Responder pelo painel').count()], [1, 0]);
  await cap.pg.click('tr[data-chave="a1"] >> text=Ver histórico');
  ver('o histórico abre com os contatos anteriores', /Chamado/.test(await cap.pg.innerText('tr[data-chave="a1"] .cp-hist')));
  await cap.pg.click('.cp-rc >> nth=0');
  eq('filtrar "Ainda não contatados" mostra só quem nunca foi chamado', await cap.pg.evaluate(() => [...document.querySelectorAll('#cp-lista tbody tr')].map(r => r.dataset.chave)), ['a2', 'a8', 'l1']);
  await cap.pg.click('.cp-rc >> nth=0');
  // clicar em "Chamar no WhatsApp" registra o contato sozinho
  await cap.pg.evaluate(() => document.querySelector('tr[data-chave="a2"] a.zap').addEventListener('click', e => e.preventDefault(), true));
  const antesP = cap.posts.length;
  await cap.pg.click('tr[data-chave="a2"] a.zap'); await cap.pg.waitForTimeout(500);
  eq('clicar em Chamar no WhatsApp registra "chamado" por WhatsApp, com o telefone', [cap.posts.length - antesP, (cap.posts[antesP] || {}).b], [1, { chave: 'a2', status: 'chamado', canal: 'whatsapp_link', telefone: '5545966664444' }]);
  ver('e a linha já mostra "Chamado" sem recarregar', /Chamado/.test(await cap.pg.innerText('tr[data-chave="a2"]')));
  await cap.pg.click('tr[data-chave="a2"] a.zap'); await cap.pg.waitForTimeout(300);
  eq('chamar de novo em menos de 10 min não repete o registro', cap.posts.length - antesP, 1);
  // anotar resultado
  await cap.pg.click('tr[data-chave="a8"] >> text=Anotar resultado');
  ver('o modal de resultado abre', await cap.pg.locator('#cp-modal #rs-status').count() === 1);
  await cap.pg.selectOption('#rs-status', 'retornar');
  ver('"Retornar depois" pede a data', await cap.pg.locator('#rs-data').isVisible());
  await cap.pg.fill('#rs-nota', 'Ligar de noite'); await cap.pg.click('#rs-ok'); await cap.pg.waitForTimeout(500);
  const pr = cap.posts[cap.posts.length - 1];
  eq('salvar manda situação, canal, anotação e data', [pr.b.chave, pr.b.status, pr.b.canal, pr.b.nota, /^\d{4}-\d{2}-\d{2}$/.test(pr.b.retorno_em)], ['a8', 'retornar', 'whatsapp_link', 'Ligar de noite', true]);
  eq('o modal fecha e a linha mostra o resultado', [await cap.pg.locator('#cp-modal').count(), (await cap.pg.innerText('tr[data-chave="a8"]')).includes('Retornar depois')], [0, true]);
  // responder pelo painel
  await cap.pg.click('tr[data-chave="a1"] >> text=Responder pelo painel');
  const aviso24 = await cap.pg.innerText('#cp-modal');
  ver('o modal de resposta explica as 24 h, que sai do número oficial e que o robô para', /24 horas/.test(aviso24) && /número oficial/.test(aviso24) && /robô para/.test(aviso24), aviso24);
  eq('já vem com a mensagem sugerida da etapa', (await cap.pg.inputValue('#rp-texto')).startsWith('Oi Ana'), true);
  const antesR = cap.posts.length;
  await cap.pg.fill('#rp-texto', 'Oi Ana, posso te ajudar com o plano?'); await cap.pg.click('#rp-ok'); await cap.pg.waitForTimeout(700);
  const pw = cap.posts[antesR];
  eq('enviar chama responder_wa com a pessoa, o telefone e o texto', [/action=responder_wa/.test(pw.u), pw.b.chave, pw.b.telefone, pw.b.texto], [true, 'a1', '5545999991111', 'Oi Ana, posso te ajudar com o plano?']);
  eq('depois de enviar, os dados são recarregados', cap.chamadas.filter(u => /action=leads/.test(u)).length >= 2, true);
  const csvDl = await Promise.all([cap.pg.waitForEvent('download', { timeout: 5000 }).catch(() => null), cap.pg.click('text=Baixar planilha')]);
  ver('a planilha também sai com a situação do contato', csvDl[0] && /\.csv$/.test(csvDl[0].suggestedFilename()));
  const [dl] = await Promise.all([cap.pg.waitForEvent('download', { timeout: 5000 }).catch(() => null), cap.pg.click('text=Baixar planilha')]);
  ver('baixa a planilha com a lista filtrada', dl && /^pessoas-captadas-2026-09-20-a-2026-10-19\.csv$/.test(dl.suggestedFilename()), dl && dl.suggestedFilename());
  eq('sem erro de script', cap.er, []);
  const calls0 = cap.chamadas.length;
  await cap.pg.fill('#cp-busca', 'ana'); await cap.pg.click('.cp-et >> nth=1'); await cap.pg.evaluate(() => document.getElementById('cp-busca').blur());
  await cap.pg.evaluate(() => atualizarSilencioso()); await cap.pg.waitForTimeout(400);
  eq('a atualização automática mantém busca e filtro', await cap.pg.evaluate(() => [document.getElementById('cp-busca').value, [...document.querySelectorAll('#cp-lista tbody tr')].map(r => r.dataset.chave)]), ['ana', ['a1']]);
  ver('e pediu os dados de novo', cap.chamadas.length > calls0);
  eq('o intervalo é de 1 minuto', await cap.pg.evaluate(() => INTERVALO_ATUALIZACAO_MS), 60000);
  const profL = await painel({ tipo: 'professor', idusuario: 9, admin: 0, nome: 'Prof', permissoes: {} });
  await profL.pg.goto(B + '/app/painel/captados.html', { waitUntil: 'load' }); await profL.pg.waitForTimeout(800);
  ver('professor não vê telefones: "Acesso restrito" e nenhuma chamada', /Acesso restrito/.test(await profL.pg.innerText('#main-content')) && profL.chamadas.filter(u => /action=leads/.test(u)).length === 0);
  eq('o item do menu "Pessoas Captadas" só aparece para o administrador', [await cap.pg.evaluate(() => [...document.querySelectorAll('a')].some(a => /captados\.html/.test(a.getAttribute('href') || ''))), await profL.pg.evaluate(() => [...document.querySelectorAll('a')].some(a => /captados\.html/.test(a.getAttribute('href') || '')))], [true, false]);
  const prof = await painel({ tipo: 'professor', idusuario: 9, admin: 0, nome: 'Prof', permissoes: {} });
  const negado = await prof.pg.evaluate(() => document.getElementById('main-content').innerText);
  ver('professor vê "Acesso restrito" e nenhuma chamada de dados', /Acesso restrito/.test(negado) && prof.chamadas.length === 0, [negado.slice(0, 80), prof.chamadas]);
  const menuAdm = await adm.pg.evaluate(() => [...document.querySelectorAll('a')].some(a => /trafego\.html/.test(a.getAttribute('href') || '')));
  const menuProf = await prof.pg.evaluate(() => [...document.querySelectorAll('a')].some(a => /trafego\.html/.test(a.getAttribute('href') || '')));
  eq('o item do menu só aparece para o administrador', [menuAdm, menuProf], [true, false]);

  await browser.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
