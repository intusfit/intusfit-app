// Teste grátis no app do aluno (aluno.html): treina, faz cardio e pontua, mas NÃO publica no Feed nem comenta até ter outro plano ativo.
// Abre a tela num Chromium de verdade com a matrícula semeada e confere o aviso, que nada é enviado ao servidor e que, com plano pago, tudo abre.
//   cd testes && INTUS_DIR=../app/painel node teste_gratis_front.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };

let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const dia = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
const TESTE = { idmensalidade: 1, idatleta: 7, dshistorico: 'Teste Grátis (3 dias)', dtinicio: dia(-1), dtvencimento: dia(2), stpgto: 'S', stmatricula: 'ativa' };
const PLANO = { idmensalidade: 2, idatleta: 7, dshistorico: 'Consultoria Premium', tipo_plano: 'mensal', dtinicio: dia(0), dtvencimento: dia(30), stpgto: 'S', stmatricula: 'ativa' };

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html';
    const f = path.join(RAIZ, p);
    if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8098, '127.0.0.1', r));
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const erros = [];
  page.on('pageerror', e => erros.push(String(e.message)));

  const S = { comentarios: 0, cardios: 0 };
  await page.route('**/catalogo.php**', async (route) => {
    const req = route.request();
    if (req.method() === 'POST' && /action=comentarios_pub/.test(req.url())) { S.comentarios++; return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.route('**/treinos.php**', async (route) => {
    const req = route.request();
    if (req.method() === 'POST' && /action=cardio/.test(req.url())) { S.cardios++; return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"idsessao":9001,"pontos":4.2,"nao_contar":0,"limite_dia":3}' }); }
    return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.route(/\/app\/api\/(?!treinos\.php|catalogo\.php)/, r => r.abort('failed'));

  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 'teste');
    localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluno Teste' }));
  });
  await page.goto('http://127.0.0.1:8098/aluno.html', { waitUntil: 'domcontentloaded' });
  let pronto = false;
  for (let i = 0; i < 80 && !pronto; i++) {
    pronto = await page.evaluate(() => typeof _ehSoTeste === 'function' && typeof abrirNovoPostFeed === 'function' && typeof salvarCardio === 'function' && typeof API !== 'undefined').catch(() => false);
    if (!pronto) await page.waitForTimeout(250);
  }
  if (!pronto) throw new Error('aluno.html nao terminou de carregar');
  await page.waitForTimeout(1500);

  // matrícula semeada + "o servidor já confirmou" (sem isso a trava não age, de propósito)
  const semear = (linhas, sincronizado = true) => page.evaluate(([l, s]) => {
    localStorage.setItem('intus-mensalidades', JSON.stringify(l));
    _syncDone = s; _matSyncOk = s;
    document.getElementById('feed-compose-overlay')?.remove(); document.getElementById('toast').classList.remove('show'); document.getElementById('toast').textContent = '';
  }, [linhas, sincronizado]);
  const estado = () => page.evaluate(() => ({ so: _ehSoTeste(), compose: !!document.getElementById('feed-compose-overlay'), toast: document.getElementById('toast').textContent, toastOn: document.getElementById('toast').classList.contains('show') }));

  console.log('\n══ Só no teste grátis ══');
  await semear([TESTE]);
  let e = await estado();
  eq('é reconhecido como só teste', e.so, true);
  await page.evaluate(() => abrirNovoPostFeed());
  e = await estado();
  eq('Novo post não abre', e.compose, false);
  ver('aparece o aviso do teste grátis', /teste grátis não inclui publicar no Feed nem comentar/.test(e.toast) && e.toastOn, e.toast);
  ver('o aviso não tem travessão', !/[—–]/.test(e.toast));
  await semear([TESTE]);
  await page.evaluate(() => abrirPostTexto());
  e = await estado();
  eq('Escrever (post só de texto) não abre', await page.evaluate(() => !!document.querySelector('#feed-texto-overlay, #pk-texto-ov, [data-post-texto]')), false);
  ver('Escrever avisa o mesmo', /teste grátis/.test(e.toast), e.toast);
  await semear([TESTE]);
  await page.evaluate(() => abrirCompartilharTreino({ tipo: 'treino', min: 40, sec: 0, kg: 1000, series: 12, nomeAluno: 'A', nomeTreino: 'Treino A', fig: null, mensagem: 'ok' }));
  e = await estado();
  eq('Compartilhar treino no Feed não abre', e.compose, false);
  ver('Compartilhar treino avisa', /teste grátis/.test(e.toast), e.toast);
  await semear([TESTE]);
  S.comentarios = 0;
  await page.evaluate(() => { window.__ci = document.createElement('input'); window.__ci.setAttribute('data-feed-comment-input', ''); window.__ci.value = 'Muito bom!'; const c = document.createElement('div'); c.setAttribute('data-feed-card', '55'); c.appendChild(window.__ci); document.body.appendChild(c); });
  await page.evaluate(() => enviarComentarioFeed(55));
  await page.waitForTimeout(300);
  e = await estado();
  eq('comentário no Feed não é enviado ao servidor', S.comentarios, 0);
  ver('comentário no Feed avisa', /teste grátis/.test(e.toast), e.toast);
  eq('o texto digitado continua no campo (nada se perde)', await page.evaluate(() => window.__ci.value), 'Muito bom!');
  await semear([TESTE]);
  await page.evaluate(() => { const i = document.createElement('input'); i.id = 'ranking-comment-input'; i.value = 'Fala, galera'; document.body.appendChild(i); });
  await page.evaluate(() => enviarComentarioRanking());
  e = await estado();
  ver('mural dos membros avisa e não envia', /teste grátis/.test(e.toast), e.toast);

  console.log('\n══ O que continua livre no teste ══');
  await semear([TESTE]);
  S.cardios = 0;
  await page.evaluate(() => { ['intus-cardio-fila-7', 'intus-cardios-7'].forEach(k => localStorage.removeItem(k)); renderCardio(); trocarView('cardio'); const t = document.getElementById('cardio-tipo'); t.value = 'Corrida'; onCardioTipoChange && onCardioTipoChange(); document.getElementById('cardio-min').value = '30'; });
  await page.evaluate(() => salvarCardio());
  await page.waitForTimeout(500);
  eq('o cardio é registrado normalmente (vai ao servidor)', S.cardios, 1);
  eq('o cardio no teste não dispara o aviso de Feed', (await estado()).toast.includes('teste grátis não inclui'), false);
  eq('curtir/reagir não depende desta trava (não há chamada a _soTesteBarra em reações)', await page.evaluate(() => !/_soTesteBarra/.test(String(window.toggleReacaoPost || '') + String(window._rxReagir || ''))), true);

  console.log('\n══ Com plano ativo (ou cortesia) ══');
  await semear([TESTE, PLANO]);
  eq('teste + plano pago: não é "só teste"', (await estado()).so, false);
  await page.evaluate(() => abrirNovoPostFeed());
  e = await estado();
  eq('Novo post abre', e.compose, true);
  eq('sem aviso', /teste grátis não inclui/.test(e.toast), false);
  await semear([TESTE, { ...PLANO, dshistorico: 'Cortesia Premium 30 dias', tipo_plano: null }]);
  eq('cortesia de verdade libera', (await estado()).so, false);
  await semear([TESTE, { ...PLANO, stpgto: 'N' }]);
  eq('plano ainda não pago não libera', (await estado()).so, true);
  await semear([TESTE, { ...PLANO, stmatricula: 'cancelada' }]);
  eq('plano cancelado não libera', (await estado()).so, true);
  await semear([TESTE, { ...PLANO, dtvencimento: dia(-9) }]);
  eq('plano vencido há mais de 5 dias não libera', (await estado()).so, true);
  await semear([TESTE, { ...PLANO, dtvencimento: dia(-3) }]);
  eq('plano vencido há 3 dias (carência) libera', (await estado()).so, false);

  console.log('\n══ Quem não é do teste ══');
  await semear([PLANO]);
  eq('só plano pago: libera', (await estado()).so, false);
  await semear([]);
  eq('sem matrícula nenhuma: não trava (outro caminho cuida)', (await estado()).so, false);
  await semear([{ ...TESTE, dtvencimento: dia(-4), dtinicio: dia(-7) }]);
  eq('teste vencido: não trava por esta regra', (await estado()).so, false);
  await semear([TESTE], false);
  eq('sem confirmação do servidor ainda: não trava', (await estado()).so, false);

  eq('sem erro de script na página', erros.filter(m => !/ServiceWorker/i.test(m)), []);
  await browser.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok'));
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
