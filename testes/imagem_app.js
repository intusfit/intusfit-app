// Fotos do feed no app das lojas: o servidor devolve caminho relativo (/app/img/feed/x.jpg). No WebView (capacitor://localhost) isso apontava para dentro do
// app e a imagem quebrava. No app vira o endereço do site; na web continua relativo.
//   cd testes && INTUS_DIR=../app/painel node imagem_app.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const SITE = 'https://intusfit.com.br';

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();

  async function abrir(nativo) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    const pedidos = [];
    // a foto só existe no site; dentro do "app" (a página local) o mesmo caminho dá 404
    await page.route(SITE + '/app/img/feed/foto.jpg', r => { pedidos.push('site'); r.fulfill({ status: 200, contentType: 'image/png', body: PNG }); });
    await page.route(SITE + '/app/img/feed/capa.jpg', r => { pedidos.push('site-capa'); r.fulfill({ status: 200, contentType: 'image/png', body: PNG }); });
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript((n) => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1');
      if (n) { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://intusfit.com.br/app/api'; }
    }, nativo);
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _midiaItemHtml === 'function' && typeof _urlAbs === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    return { ctx, page, erros, pedidos };
  }

  console.log('\n══ App das lojas: foto do feed com caminho relativo ══');
  let t = await abrir(true);
  eq('o endereço relativo vira o do site', await t.page.evaluate(() => [_urlAbs('/app/img/feed/foto.jpg'), _urlAbs('https://outro.com/a.jpg'), _urlAbs('data:image/png;base64,AA'), _urlAbs('//cdn.x/a.jpg'), _urlAbs('')]), [SITE + '/app/img/feed/foto.jpg', 'https://outro.com/a.jpg', 'data:image/png;base64,AA', '//cdn.x/a.jpg', '']);
  const html = await t.page.evaluate(() => _midiaItemHtml({ tipo: 'imagem', url: '/app/img/feed/foto.jpg' }));
  eq('o cartão do post pede a foto no site', /src="https:\/\/intusfit\.com\.br\/app\/img\/feed\/foto\.jpg"/.test(html), true);
  const vid = await t.page.evaluate(() => _midiaItemHtml({ tipo: 'video', url: '/app/x.mp4', poster: '/app/img/feed/capa.jpg' }));
  eq('a capa do vídeo também', /poster="https:\/\/intusfit\.com\.br\/app\/img\/feed\/capa\.jpg"/.test(vid), true);
  // rede de segurança: uma <img> qualquer com caminho relativo que falhou é tentada no site
  const r = await t.page.evaluate(async () => {
    const img = new Image(); document.body.appendChild(img);
    await new Promise((res) => { img.onload = res; img.onerror = () => setTimeout(res, 1500); img.setAttribute('src', '/app/img/feed/foto.jpg'); });
    await new Promise(r2 => setTimeout(r2, 600));
    return { src: img.getAttribute('src'), carregou: img.complete && img.naturalWidth > 0 };
  });
  eq('imagem que apontava para dentro do app é refeita no site e carrega', [r.src, r.carregou], [SITE + '/app/img/feed/foto.jpg', true]);
  eq('sem erro de JavaScript', t.erros, []); await t.ctx.close();

  console.log('\n══ Web: nada muda ══');
  t = await abrir(false);
  eq('na web o caminho relativo continua relativo', await t.page.evaluate(() => [_urlAbs('/app/img/feed/foto.jpg'), _midiaItemHtml({ tipo: 'imagem', url: '/app/img/feed/foto.jpg' }).indexOf('src="/app/img/feed/foto.jpg"') >= 0]), ['/app/img/feed/foto.jpg', true]);
  eq('e a rede de segurança não está ligada', await t.page.evaluate(() => { const i = new Image(); document.body.appendChild(i); return typeof i.__tentouSite; }), 'undefined');
  await t.ctx.close();

  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
