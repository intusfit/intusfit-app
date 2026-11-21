// Voltar e botões nas telas de treino, alongamentos, histórico e conquistas (20/11/2026): caixa de confirmação do treino em andamento,
// bloqueio de outro treino ou cardio, explicações sem laço, camadas que o voltar fecha, rolagem devolvida e botões do fim do feed.
//   cd testes && INTUS_DIR=../app/painel node voltar_treino.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const SEED = () => {
  const d = (n) => { const x = new Date(); x.setDate(x.getDate() - n); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); };
  localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
  localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
  localStorage.setItem('intus-fichas', JSON.stringify([{ idficha: 5, idatleta: 7, nmficha: 'Treino ABC', ativo: 1, alongamentos: [{ idalongamento: 1, nome: 'Peitoral', tempo: '30s', divisao: 'A' }] }]));
  localStorage.setItem('intus-treinos', JSON.stringify([
    { idtreino: 11, idficha: 5, divisao: 'A', idexercicio: 1, nmexercicio: 'Agachamento', qtdserie: 3, repeticoes: '12', intervalo: '01:00' },
    { idtreino: 21, idficha: 5, divisao: 'B', idexercicio: 3, nmexercicio: 'Remada', qtdserie: 3, repeticoes: '12', intervalo: '01:00' }]));
  const ss = []; for (let i = 1; i <= 12; i++) ss.push({ idsessao: i, idatleta: 7, idficha: 5, divisao: 'A', dtsessao: d(i), duracao_seg: 3000, pontos: 10, tipo: 'musculacao' });
  localStorage.setItem('intus-sessoes-treino', JSON.stringify(ss));
};
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8160, '127.0.0.1', r));
  const b = await chromium.launch();
  let falharLote = false;
  const mk = id => ({ idpost: id, idatleta: 9, created_at: '2026-11-20 10:00:00', legenda: 'Treino ' + id, midias: [], localizacao: '' });
  async function nova() {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); page.erros = []; page.on('pageerror', e => page.erros.push(String(e.message)));
    page.on('dialog', d => { page.dialogos = (page.dialogos || 0) + 1; d.dismiss(); });
    await page.route(/\/app\/api\/catalogo\.php\?action=feed_posts/, r => {
      const u = new URL(r.request().url()); const lim = +(u.searchParams.get('limite') || 30), antes = +(u.searchParams.get('antes_de') || 0);
      if (antes && falharLote) return r.fulfill({ status: 500, body: 'x' });
      const todos = []; for (let i = 60; i >= 1; i--) todos.push(mk(i));
      const lista = todos.filter(p => !antes || p.idpost < antes);
      r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ posts: lista.slice(0, lim), temMais: lista.length > lim, atletas: { 9: 'Aluna Nove' }, avatars: {} }) });
    });
    await page.route(/\/app\/api\/(?!catalogo\.php\?action=feed_posts)/, r => r.abort('failed'));
    await page.addInitScript(SEED);
    await page.goto('http://127.0.0.1:8160/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(700);
    return page;
  }
  const est = p => p.evaluate(() => ({ v: window._viewAtual || 'home', caixa: !!document.getElementById('dlg-app-ov'), timer: !!treinoTimer, pilha: viewStack.join('>'), banner: (document.getElementById('treino-banner') || {}).style ? document.getElementById('treino-banner').style.display : 'x' }));
  const ir = async (p, js, ms) => { await p.evaluate(js).catch(e => p.erros.push('ERRO ' + js + ': ' + e.message)); await p.waitForTimeout(ms || 500); };
  const comTreino = async (p) => { await ir(p, "abrirMeusTreinos()"); await ir(p, "selecionarTreino(5,'B')", 4800); };   // a divisão B não tem alongamento: vai direto à musculação
  const comAlong = async (p) => { await ir(p, "abrirMeusTreinos()"); await ir(p, "selecionarTreino(5,'A')", 900); };
  let p;

  console.log('\n══ Treino em andamento: o voltar abre a caixa do app ══');
  p = await nova(); await comTreino(p);
  eq('musculação rodando', (await est(p)).v, 'muscu');
  await p.goBack(); await p.waitForTimeout(500);
  let e = await est(p);
  eq('voltar do celular abre a caixa e fica na musculação (sem caixa nativa)', [e.v, e.caixa, e.timer, p.dialogos || 0], ['muscu', true, true, 0]);
  await p.goBack(); await p.waitForTimeout(500);
  e = await est(p);
  eq('voltar de novo fecha a caixa (continuar) e o treino segue', [e.v, e.caixa, e.timer], ['muscu', false, true]);
  await p.goBack(); await p.waitForTimeout(400); await p.click('#dlg-continuar');
  e = await est(p);
  eq('"Continuar treino" fecha a caixa', [e.v, e.caixa], ['muscu', false]);
  await p.goBack(); await p.waitForTimeout(400); await p.click('#dlg-inicio'); await p.waitForTimeout(700);
  e = await est(p);
  eq('"Ir para o início": vai ao início, o treino continua rodando e o aviso verde aparece', [e.v, e.caixa, e.timer, e.banner], ['home', false, true, 'flex']);
  eq('a pilha do voltar ficou limpa', e.pilha, 'home');
  await ir(p, "document.getElementById('treino-banner').click()", 700);
  e = await est(p);
  eq('o aviso verde volta para a musculação', [e.v, e.timer, e.pilha], ['muscu', true, 'home>muscu']);

  console.log('\n══ Menu lateral e Treinos com o treino rodando ══');
  await ir(p, "navegar('ranking')", 700);
  eq('com o treino rodando dá para ir ao Ranking pelo menu', (await est(p)).v, 'ranking');
  await ir(p, "navegar('treinos-menu')", 600);
  e = await est(p);
  eq('"Treinos" com treino rodando abre a caixa (não a lista nem a musculação direto)', [e.v, e.caixa], ['ranking', true]);
  await p.click('#dlg-continuar'); await p.waitForTimeout(800);
  e = await est(p);
  eq('"Continuar treino" volta à musculação pela pilha, sem empilhar de novo', [e.v, e.pilha], ['muscu', 'home>muscu']);
  await ir(p, "selecionarTreino(5,'A')", 600);
  e = await est(p);
  eq('escolher OUTRO treino com um em andamento: bloqueado (caixa) e o treino atual não muda', [e.caixa, await p.evaluate(() => sessaoAtual.divisao)], [true, 'B']);
  await p.click('#dlg-continuar');
  await ir(p, "abrirCardioLive()", 600);
  e = await est(p);
  eq('cardio ao vivo com treino rodando: bloqueado', [e.v, e.caixa], ['muscu', true]);
  await p.click('#dlg-continuar');
  eq('o texto da caixa do cardio fala em terminar ou descartar o treino', await p.evaluate(() => { _treinoCaixa('cardio', 'um programa de cardio'); const t = document.getElementById('dlg-app-ov').innerText; document.getElementById('dlg-app-ov').remove(); return /Termine ou descarte/.test(t) && /programa de cardio/.test(t); }), true);
  await p.goBack(); await p.waitForTimeout(400); await p.click('#dlg-descartar');
  e = await est(p);
  eq('"Descartar treino" pede o segundo toque (não apaga no primeiro)', [e.caixa, e.timer], [true, true]);
  eq('o botão muda de texto para avisar', await p.evaluate(() => document.getElementById('dlg-descartar').textContent), 'Toque de novo para descartar');
  await p.click('#dlg-descartar'); await p.waitForTimeout(800);
  e = await est(p);
  eq('segundo toque descarta: início, sem treino rodando, sem aviso verde', [e.v, e.caixa, e.timer, e.banner, await p.evaluate(() => carregarTreinoAtivo())], ['home', false, false, 'none', null]);
  await ir(p, "abrirMeusTreinos()", 600);
  eq('depois de descartar, "Treinos" abre a lista de novo', (await est(p)).v, 'treinos');

  console.log('\n══ Cardio rodando bloqueia treino ══');
  p = await nova();
  await ir(p, "_cardioLiveInicioMs = Date.now() - 60000; abrirMeusTreinos()", 500);
  await ir(p, "selecionarTreino(5,'B')", 700);
  e = await est(p);
  eq('com cardio ao vivo rodando não começa treino', [e.v === 'muscu', e.caixa], [false, false]);
  await ir(p, "_cardioLiveInicioMs = 0; _prog.rodando = true; selecionarTreino(5,'B')", 700);
  eq('com programa de cardio rodando não começa treino', (await est(p)).v === 'muscu', false);
  await ir(p, "_prog.rodando = false", 100);

  console.log('\n══ Alongamentos e explicações: sem laço ══');
  p = await nova();
  await comAlong(p);
  eq('escolher a divisão com alongamento abre os alongamentos', (await est(p)).v, 'along');
  const base = (await est(p)).pilha;
  await ir(p, "abrirInfoAlong(0)", 600);
  eq('explicação do alongamento aberta', (await est(p)).v, 'exinfo');
  await ir(p, "document.querySelector('#v-exinfo .btn-back').click()", 700);
  e = await est(p);
  eq('"← Voltar" da explicação volta ao alongamento sem empilhar de novo', [e.v, e.pilha], ['along', base]);
  await p.goBack(); await p.waitForTimeout(500);
  eq('o voltar do celular depois leva à lista de treinos (não de volta à explicação)', (await est(p)).v, 'treinos');
  p = await nova(); await comAlong(p);
  await ir(p, "document.querySelector('#v-along .btn-back').click()", 700);
  eq('"← Voltar" dos alongamentos leva à tela de onde veio', (await est(p)).v, 'treinos');
  p = await nova(); await comAlong(p);
  await p.goBack(); await p.waitForTimeout(500);
  eq('voltar do celular nos alongamentos (treino ainda não começou) leva à lista, sem caixa', [(await est(p)).v, (await est(p)).caixa], ['treinos', false]);
  p = await nova(); await comAlong(p);
  await ir(p, "iniciarMusculacao()", 4800);
  eq('Iniciar musculação abre a musculação com o relógio rodando', [(await est(p)).v, (await est(p)).timer], ['muscu', true]);
  await p.goBack(); await p.waitForTimeout(500);
  e = await est(p);
  eq('voltar na musculação (vinda dos alongamentos) abre a caixa e não passa pelos alongamentos', [e.v, e.caixa], ['muscu', true]);
  await p.click('#dlg-inicio'); await p.waitForTimeout(700);
  e = await est(p);
  eq('"Ir para o início" vai direto ao início, sem girar entre alongamentos e musculação', [e.v, e.pilha, e.timer], ['home', 'home', true]);
  p = await nova(); await comTreino(p);
  const baseM = (await est(p)).pilha;
  await ir(p, "abrirInfoExercicio(1,'Agachamento')", 600);
  await ir(p, "document.querySelector('#v-exinfo .btn-back').click()", 700);
  e = await est(p);
  eq('"← Voltar" da explicação do exercício volta à musculação sem empilhar de novo', [e.v, e.pilha, e.caixa], ['muscu', baseM, false]);
  await ir(p, "abrirInfoExercicio(1,'Agachamento')", 600);
  await p.goBack(); await p.waitForTimeout(500);
  e = await est(p);
  eq('voltar do celular na explicação do exercício volta à musculação sem pergunta', [e.v, e.caixa], ['muscu', false]);

  console.log('\n══ Camadas do Histórico e das Conquistas ══');
  p = await nova();
  await ir(p, "renderHistorico();trocarView('historico')", 700);
  await ir(p, "_histAbrirDia(new Date(Date.now() - 86400000).toISOString().slice(0, 10))", 500);
  eq('folha do dia aberta', await p.evaluate(() => !!document.getElementById('hist-dia-overlay')), true);
  await p.goBack(); await p.waitForTimeout(600);
  eq('voltar fecha a folha do dia e fica no Histórico', [await p.evaluate(() => !!document.getElementById('hist-dia-overlay')), (await est(p)).v], [false, 'historico']);
  await p.goBack(); await p.waitForTimeout(500);
  eq('o voltar seguinte sai do Histórico', (await est(p)).v, 'home');
  await ir(p, "renderPerfil();trocarView('perfil')", 700);
  await ir(p, "abrirDetalheConquista(_conquistasDefs()[0].id, idAtleta)", 500);
  eq('detalhe de conquista aberto', await p.evaluate(() => !!document.getElementById('conq-detail-overlay')), true);
  await p.goBack(); await p.waitForTimeout(600);
  eq('voltar fecha o detalhe e fica no Perfil', [await p.evaluate(() => !!document.getElementById('conq-detail-overlay')), (await est(p)).v], [false, 'perfil']);

  console.log('\n══ Rolagem devolvida ao voltar e botões do fim do feed ══');
  p = await nova();
  await ir(p, "navegar('feed')", 1800);
  await ir(p, "window.scrollTo(0, 1500)", 400);
  const y0 = await p.evaluate(() => scrollY);
  await ir(p, "navegar('ranking')", 700);
  await p.goBack(); await p.waitForTimeout(1800);
  const y1 = await p.evaluate(() => scrollY);
  eq('voltar para o Feed devolve a rolagem de antes', Math.abs(y1 - y0) <= 8 && y0 > 1000, true);
  p = await nova();
  falharLote = true;
  await ir(p, "renderHome()", 3500);
  const nPosts = await p.evaluate(() => document.querySelectorAll('#v-home [data-feed-card]').length);
  const rotulos = await p.evaluate(() => [...document.querySelectorAll('#h-feed-home-pag button')].map(b => b.textContent.trim()));
  eq('mesmo com o lote seguinte falhando, o conjunto de botões traz "Carregar mais posts"', [nPosts >= 4, rotulos.some(t => /Carregar mais posts/.test(t)), rotulos.some(t => /Abrir o feed/.test(t)), rotulos.some(t => /Topo/.test(t))], [true, true, true, true]);
  falharLote = false;
  await p.evaluate(() => { [...document.querySelectorAll('#h-feed-home-pag button')].find(b => /Carregar mais/.test(b.textContent)).click(); }); await p.waitForTimeout(2500);
  eq('o botão tenta de novo e traz mais posts', (await p.evaluate(() => document.querySelectorAll('#v-home [data-feed-card]').length)) > nPosts, true);

  console.log('\n══ Botões de voltar com toque confortável ══');
  p = await nova();
  await ir(p, "renderHistorico();trocarView('historico')", 700);
  const hit = await p.evaluate(() => { const bb = document.querySelector('#v-historico .btn-back'); const r = bb.getBoundingClientRect(); const a = getComputedStyle(bb, '::after'); const ins = parseFloat(a.top) || 0; return Math.round(r.height + 2 * Math.abs(ins)); });
  eq('"← Voltar" do cabeçalho tem área de toque de pelo menos 44 px', hit >= 44, true);
  await comAlong(p);
  eq('"← Voltar" dos alongamentos mede pelo menos 44 px', await p.evaluate(() => document.querySelector('#v-along .btn-back').getBoundingClientRect().height >= 43.5), true);
  eq('nenhum erro de JavaScript', p.erros, []);

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
