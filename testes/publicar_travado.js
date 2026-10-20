// Publicar/compartilhar nunca fica girando para sempre (aluna do iPhone: "Preparando seu post..." desde o dia anterior).
// Faz a figurinha (html2canvas) e a logo travarem de propósito e confere que o app segue com a foto, e que um servidor mudo vira erro.
//   cd testes && INTUS_DIR=../app/painel node publicar_travado.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8102, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const S = { posts: 0, modo: 'ok' };
  await page.route(/\/app\/api\/catalogo\.php.*action=feed_posts/, async (route) => { if (route.request().method() !== 'POST') return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' }); S.posts++; if (S.modo === 'mudo') return; return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"idpost":1}' }); });
  await page.route(/\/app\/api\/(?!catalogo\.php.*feed_posts)/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); });
  await page.goto('http://127.0.0.1:8102/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _composeImagemFinal === 'function' && typeof html2canvas === 'function' && typeof _comPrazo === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
  const preparar = () => page.evaluate(() => {
    const c = document.createElement('canvas'); c.width = 600; c.height = 750; const x = c.getContext('2d'); x.fillStyle = '#446'; x.fillRect(0, 0, 600, 750);
    abrirCompartilharTreino({ tipo: 'cardio', min: 30, sec: 0, distKm: 5, kcal: 300, nomeAluno: 'Aluna', nomeTreino: 'Corrida', fig: _figPick(_figurinhasList()), mensagem: 'ok' });
    _feedComposeImg = c.toDataURL('image/jpeg', .9); window.__foto = _feedComposeImg;
    _COMPOSE_PRAZO_FIGURINHA_MS = 1500; _COMPOSE_PRAZO_LOGO_MS = 1000;
    document.getElementById('toast').textContent = '';
  });

  console.log('\n══ Figurinha que trava ══');
  await preparar();
  const r1 = await page.evaluate(async () => { const orig = window.html2canvas; window.html2canvas = () => new Promise(() => {}); const t = performance.now(); const out = await _composeImagemFinal(); window.html2canvas = orig; return { ms: Math.round(performance.now() - t), foto: out.startsWith('data:image/jpeg'), toast: document.getElementById('toast').textContent }; });
  ver('com a figurinha travada, termina no prazo (não fica girando)', r1.ms < 4000, r1);
  ver('devolve uma imagem para publicar', r1.foto);
  ver('avisa que vai usar só a foto', /só a foto/.test(r1.toast), r1.toast);

  console.log('\n══ Logo que trava ══');
  const r2 = await page.evaluate(async () => { _composeInfoOn = false; const oImg = window.Image; let n = 0; window.Image = function () { const im = new oImg(); n++; if (n >= 2) { Object.defineProperty(im, 'src', { set() {}, get() { return ''; } }); } return im; }; const t = performance.now(); const out = await _composeImagemFinal(); window.Image = oImg; return { ms: Math.round(performance.now() - t), igual: out === window.__foto || out.startsWith('data:') }; });
  ver('com a logo travada, termina no prazo', r2.ms < 4000, r2);

  console.log('\n══ Servidor que não responde ══');
  await preparar(); await page.evaluate(() => { _composeInfoOn = false; });
  S.modo = 'mudo';
  // prazo curto só para o teste: troca o 90 s por 1,5 s no próprio _fetchT
  await page.evaluate(() => { const f = window._fetchT; window._fetchT = (u, o, ms) => f(u, o, 1500); });
  const t0 = Date.now();
  await page.evaluate(() => _composeAcao('feed'));
  const dt = Date.now() - t0;
  ver('servidor mudo: o envio desiste no prazo e mostra a causa', dt < 6000 && true, dt);
  const est = await page.evaluate(() => ({ toast: document.getElementById('toast').textContent, ocupado: !!document.getElementById('busy-ov'), trava: _postPublicando }));
  ver('avisa que a conexão está lenta', /lenta|conex/i.test(est.toast), est.toast);
  eq('a tela de "Preparando" fecha e dá para tentar de novo', [est.ocupado, est.trava], [false, false]);

  console.log('\n══ Estrutura ══');
  const html = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  ver('html2canvas só clona a figurinha (ignoreElements)', /ignoreElements: \(el\) =>/.test(html));
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
