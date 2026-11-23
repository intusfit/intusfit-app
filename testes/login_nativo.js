// login.html no app das lojas: o login precisa chamar o servidor pelo endereço absoluto (window.INTUS_API_BASE, definido por
// mobile/scripts/build-web.mjs). Com caminho relativo, no Capacitor a chamada cairia em capacitor://localhost e todo login
// falharia com "Não conseguimos falar com o servidor".
//   cd testes && INTUS_DIR=../app/painel node login_nativo.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

async function tentarLogin(nativo) {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/login.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const porta = srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const chamadas = [];
  // a tela chama aluno_login.php?action=retomar ao abrir (retomar sessão); só a chamada de login nos interessa
  await page.route(/aluno_login\.php/, r => { if (!/action=/.test(r.request().url())) chamadas.push(r.request().url()); r.fulfill({ status: 401, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' }, body: '{"erro":"Credenciais inválidas"}' }); });
  if (nativo) await page.addInitScript(() => { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://api.exemplo.test/app/api'; });
  await page.goto('http://127.0.0.1:' + porta + '/login.html', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#email'); await page.fill('#email', 'aluno@teste.com'); await page.fill('#senha', 'x'); await page.click('#btn');
  await page.waitForTimeout(800);
  const msg = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  await b.close(); srv.close();
  return { chamadas, msg, erros, porta };
}

(async () => {
  console.log('\n══ App das lojas: login chama o servidor absoluto ══');
  const n = await tentarLogin(true);
  eq('a chamada vai para o endereço absoluto do servidor', n.chamadas, ['https://api.exemplo.test/app/api/aluno_login.php']);
  eq('recusa de credenciais aparece como tal, não como "falar com o servidor"', /incorretos/.test(n.msg) && !/falar com o servidor/.test(n.msg), true);
  eq('sem erro de JavaScript', n.erros, []);

  console.log('\n══ Web: continua relativo, mesma origem da página ══');
  const w = await tentarLogin(false);
  eq('a chamada usa a mesma origem da página', w.chamadas, ['http://127.0.0.1:' + w.porta + '/app/api/aluno_login.php']);

  console.log('\n══ Acesso de professor só pelo link específico ══');
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/login.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + srv.address().port + '/login.html';
  const b2 = await chromium.launch();
  async function estado(url, nativo) {
    const ctx = await b2.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
    const page = await ctx.newPage();
    if (nativo) await page.addInitScript(() => { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://api.exemplo.test/app/api'; });
    await page.goto(url, { waitUntil: 'domcontentloaded' }); await page.waitForSelector('#email'); await page.waitForTimeout(300);
    const r = await page.evaluate(() => ({ botaoVisivel: !!document.getElementById('btn-toggle-modo').offsetParent, modoProf: document.body.classList.contains('modo-professor'), titulo: document.getElementById('titulo-card').textContent, frase: /em breve teremos|disponível nas lojas|salve na tela inicial/i.test(document.body.innerText) }));
    await ctx.close(); return r;
  }
  let e = await estado(base, false);
  eq('tela de cliente na web: sem o botão "Sou professor" e sem modo professor', [e.botaoVisivel, e.modoProf, e.titulo], [false, false, 'Acesso do Cliente']);
  eq('a frase "em breve nas lojas" não aparece mais na web', e.frase, false);
  e = await estado(base + '?professor=1', false);
  eq('link ?professor=1 abre o acesso do professor', [e.modoProf, e.titulo], [true, 'Acesso do Professor']);
  e = await estado(base + '#professor', false);
  eq('link #professor também abre o acesso do professor', e.modoProf, true);
  e = await estado(base + '?professor=1', true);
  eq('no app das lojas o modo professor não existe nem pelo link', [e.botaoVisivel, e.modoProf], [false, false]);
  eq('a frase "em breve nas lojas" não aparece no app', e.frase, false);
  console.log('\n══ Botões de acesso rápido do tamanho da caixa de login ══');
  const ctxP = await b2.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
  const pg = await ctxP.newPage();
  await pg.addInitScript(() => { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://api.exemplo.test/app/api'; window.Capacitor = { getPlatform: () => 'ios', Plugins: { SignInWithApple: {} } }; });
  await pg.goto(base, { waitUntil: 'domcontentloaded' }); await pg.waitForSelector('#btn-apple'); await pg.waitForTimeout(300);
  const med = await pg.evaluate(() => { const r = s => { const b = document.querySelector(s).getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height) }; }; const cs = s => getComputedStyle(document.querySelector(s)).borderRadius; return { entrar: r('#btn'), apple: r('#btn-apple'), campo: r('#email'), raioEntrar: cs('#btn'), raioApple: cs('#btn-apple') }; });
  eq('o botão da Apple tem a largura do ENTRAR e do campo de e-mail', [med.apple.w === med.entrar.w, med.apple.w === med.campo.w], [true, true]);
  eq('o botão da Apple tem a altura do ENTRAR (48 px) e o mesmo raio', [med.apple.h, med.entrar.h, med.raioApple === med.raioEntrar], [48, 48, true]);
  const sup = await pg.evaluate(() => { const a = document.getElementById('btn-suporte'); const r = a.getBoundingClientRect(); const e = document.querySelector('.login-card').getBoundingClientRect(); return { href: a.href, texto: a.innerText.trim(), visivel: !!a.offsetParent, w: Math.round(r.width) === Math.round(e.width), h: Math.round(r.height), alvo: a.target }; });
  eq('o botão "Falar com o suporte" aparece e abre o WhatsApp da Intus em outra janela', [sup.visivel, sup.texto, /^https:\/\/wa\.me\/5545991156006\?text=/.test(sup.href), sup.alvo], [true, 'Falar com o suporte', true, '_blank']);
  eq('e tem a largura da caixa de login e a altura de 48 px', [sup.w, sup.h], [true, 48]);
  await pg.screenshot({ path: process.env.INTUS_PRINT || '', fullPage: true }).catch(() => {});
  await b2.close(); srv.close();

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
