// Vídeos do YouTube no app das lojas: o YouTube recusa a origem capacitor://localhost (erro 153). No app o player passa por yt.html (https://intusfit.com.br);
// na web segue direto. yt.html só aceita id de 11 caracteres e a exceção de iframe vale só para ele.
//   cd testes && INTUS_DIR=../app/painel node video_app.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const SITE = 'https://intusfit.com.br'; const ID = 'dQw4w9WgXcQ';

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();

  async function abrir(nativo) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript((n) => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1');
      if (n) { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://intusfit.com.br/app/api'; }
    }, nativo);
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _ytSrc === 'function' && typeof _infoVideoHtml === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    return { ctx, page, erros };
  }

  console.log('\n══ App das lojas: o player passa por yt.html ══');
  let t = await abrir(true);
  const n = await t.page.evaluate((id) => ({
    modal: youtubeToEmbed('https://youtu.be/' + id), info: youtubeToEmbed('https://www.youtube.com/watch?v=' + id, false), nocookie: _ytSrc(id, false, true),
    html: _infoVideoHtml('https://www.youtube.com/watch?v=' + id), naoYoutube: youtubeToEmbed('https://exemplo.com/video.mp4'),
  }), ID);
  eq('modal de vídeo (com início automático)', n.modal, SITE + '/app/painel/yt.html?v=' + ID + '&auto=1');
  eq('vídeo do exercício (sem início automático)', n.info, SITE + '/app/painel/yt.html?v=' + ID);
  eq('o caminho "nocookie" também passa por yt.html', n.nocookie, SITE + '/app/painel/yt.html?v=' + ID);
  eq('o cartão do exercício usa o player nosso e mantém o atalho "Abrir no YouTube"', [n.html.indexOf('src="' + SITE + '/app/painel/yt.html?v=' + ID + '"') >= 0, /Abrir o vídeo no YouTube/.test(n.html), n.html.indexOf('src="https://www.youtube.com/embed') < 0], [true, true, true]);
  eq('link que não é do YouTube não é mexido', n.naoYoutube, 'https://exemplo.com/video.mp4');
  eq('sem erro de JavaScript', t.erros, []); await t.ctx.close();

  console.log('\n══ Web: continua direto no YouTube ══');
  t = await abrir(false);
  const w = await t.page.evaluate((id) => ({ modal: youtubeToEmbed('https://youtu.be/' + id), nocookie: _ytSrc(id, false, true) }), ID);
  eq('web: embed direto do YouTube', [w.modal, w.nocookie], ['https://www.youtube.com/embed/' + ID + '?rel=0&playsinline=1&autoplay=1', 'https://www.youtube-nocookie.com/embed/' + ID + '?rel=0&playsinline=1']);
  await t.ctx.close();

  console.log('\n══ yt.html ══');
  async function yt(query) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 300 }, serviceWorkers: 'block' }); const page = await ctx.newPage();
    await page.route(/youtube\.com\/embed/, r => r.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>ok</body></html>' }));
    await page.goto(base + '/yt.html' + query, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(300);
    const r = await page.evaluate(() => { const f = document.querySelector('iframe'); return f ? { src: f.src, ref: f.referrerPolicy, allow: f.allow } : null; });
    await ctx.close(); return r;
  }
  let r = await yt('?v=' + ID + '&auto=1');
  eq('embute o YouTube com a origem do site (parâmetro origin) e início automático', [!!r, r && /^https:\/\/www\.youtube\.com\/embed\/dQw4w9WgXcQ\?rel=0&playsinline=1&origin=http%3A%2F%2F127\.0\.0\.1%3A\d+&autoplay=1$/.test(r.src), r && r.ref], [true, true, 'strict-origin-when-cross-origin']);
  r = await yt('?v=' + ID);
  eq('sem auto=1 não inicia sozinho', r && /autoplay/.test(r.src), false);
  eq('id inválido não embute nada', [await yt('?v=abc'), await yt('?v=' + ID + 'x'), await yt('?v=https://evil.test/x'), await yt('')], [null, null, null, null]);
  const ht = fs.readFileSync(path.join(RAIZ, '.htaccess'), 'utf8');
  eq('a exceção de iframe vale só para yt.html (.htaccess)', [/<Files "yt\.html">[\s\S]*unset X-Frame-Options[\s\S]*frame-ancestors \*[\s\S]*<\/Files>/.test(ht), (ht.match(/frame-ancestors \*/g) || []).length], [true, 1]);

  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
