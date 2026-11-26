// Correções de 27/11/2026 no voltar e no treino:
//  1) "← Voltar" cujo destino não está na pilha (Programas de cardio aberto direto da Início, que apontava para o Meu Cardio) volta para a tela de
//     onde veio, sem empilhar o destino por cima e sem laço com o voltar do celular;
//  2) voltar (botão ou gesto) durante a contagem 3-2-1 cancela o treino, sem iniciar nada por baixo;
//  3) a barra do cronômetro não muda de altura quando o descanso começa (a tela "corria" para baixo ao marcar uma série);
//  4) a janela de "Encerrar programa?" fecha com o voltar do celular em vez de ficar parada e travar a tela.
//   cd testes && INTUS_DIR=../app/painel node voltar_correcoes_27_11.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', hasTouch: true, isMobile: true });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(e.message));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { try {
    localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
    localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-ajuda-v2', JSON.stringify({ visto: 1 }));
    const fim = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
    localStorage.setItem('intus-mensalidades', JSON.stringify([{ idmensalidade: 1, idatleta: 7, plano: 'Consultoria Premium', dtinicio: '2026-11-01', dtvencimento: fim, stmatricula: 'ativa', status: 'paga' }]));
    localStorage.setItem('intus-fichas', JSON.stringify([{ idficha: 5, idatleta: 7, nmficha: 'Treino ABC', ativo: 1 }]));
    const t = []; for (let i = 0; i < 6; i++) t.push({ idtreino: 100 + i, idficha: 5, divisao: 'B', idexercicio: 10 + i, nmexercicio: 'Exercicio ' + i, qtdserie: 3, repeticoes: '12', intervalo: '01:00' });
    localStorage.setItem('intus-treinos', JSON.stringify(t));
  } catch (e) {} });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(700);
  const est = () => page.evaluate(() => ({ v: window._viewAtual, pilha: viewStack.join('>'), cd: document.getElementById('countdown-overlay').classList.contains('open'), treino: !!treinoTimer, dlg: !!document.getElementById('prog-fim-ov') }));
  const espera = (ms) => page.waitForTimeout(ms || 600);

  console.log('\n══ "← Voltar" com destino fora da pilha ══');
  await page.evaluate("abrirProgramasCardio()"); await espera();
  eq('Programas aberto da Início: pilha home>cardio-prog', (await est()).pilha, 'home>cardio-prog');
  await page.evaluate(() => document.querySelector('.view.ativo .btn-back').click()); await espera();
  let e = await est(); eq('"← Voltar" leva à Início sem empilhar o Meu Cardio', [e.v, e.pilha], ['home', 'home']);
  await page.evaluate("abrirProgramasCardio()"); await espera();
  await page.evaluate(() => history.back()); await espera();
  e = await est(); eq('voltar do celular também leva à Início', [e.v, e.pilha], ['home', 'home']);
  await page.evaluate("abrirCardio()"); await espera(800); await page.evaluate("abrirProgramasCardio()"); await espera();
  await page.evaluate(() => document.querySelector('.view.ativo .btn-back').click()); await espera();
  e = await est(); eq('com o Meu Cardio na pilha, "← Voltar" dos Programas volta a ele', [e.v, e.pilha], ['cardio', 'home>cardio']);
  await page.evaluate(() => { viewStack.length = 1; trocarView('home'); renderHome(); }); await espera(300);

  console.log('\n══ Contagem 3-2-1 do treino ══');
  await page.evaluate("abrirMeusTreinos()"); await espera();
  await page.evaluate("selecionarTreino(5,'B')"); await espera(900);
  e = await est(); eq('a contagem está na tela', e.cd, true);
  await page.evaluate(() => history.back()); await espera(500);
  e = await est(); eq('voltar do celular cancela: sem contagem e sem treino rodando', [e.cd, e.treino], [false, false]);
  await espera(4500);
  e = await est(); eq('e nada começa depois por baixo', [e.v === 'muscu', e.treino], [false, false]);

  console.log('\n══ Barra do cronômetro estável ══');
  await page.evaluate(() => { viewStack.length = 1; trocarView('home'); renderHome(); }); await espera(300);
  await page.evaluate("abrirMeusTreinos()"); await espera();
  await page.evaluate("selecionarTreino(5,'B')"); await espera(5200);
  await page.evaluate("expandirExercicio(0)"); await espera(400);
  const alt = () => page.evaluate(() => Math.round(document.querySelector('.timer-bar').getBoundingClientRect().height));
  const h0 = await alt();
  await page.evaluate(() => { const row = document.querySelector('#v-muscu .serie-row'); const ins = row.querySelectorAll('input'); ins[0].value = '40'; ins[0].dispatchEvent(new Event('change')); ins[1].value = '10'; ins[1].dispatchEvent(new Event('change')); row.querySelector('.check-btn').click(); });
  await espera(900);
  eq('altura da barra igual antes e depois de iniciar o descanso', await alt(), h0);
  eq('o descanso aparece', await page.evaluate(() => getComputedStyle(document.getElementById('rest-wrap')).visibility), 'visible');

  console.log('\n══ Janela "Encerrar programa?" ══');
  await page.evaluate(() => { descartarTreinoEmAndamento(); viewStack.length = 1; trocarView('home'); renderHome(); }); await espera(300);
  await page.evaluate("abrirProgramaCardio('hiit-1-1')").catch(() => {});
  const ids = await page.evaluate(() => API.PROGRAMAS_CARDIO.map(p => p.id));
  await page.evaluate((id) => { abrirProgramasCardio(); abrirProgramaCardio(id); }, ids[0]); await espera();
  await page.evaluate(() => { _progIniciar(); }); await espera(500);
  await page.evaluate(() => { _progEncerrar(); }); await espera(300);
  e = await est(); eq('a janela de encerrar abre', e.dlg, true);
  await page.evaluate(() => history.back()); await espera(500);
  e = await est(); eq('voltar do celular fecha só a janela', e.dlg, false);

  eq('nenhum erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok' : ok + ' ok'));
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
