// Vídeos do YouTube no app das lojas: o YouTube recusa a origem capacitor://localhost (erro 153). No app o player passa por yt.html (https://intusfit.com.br);
// na web segue direto. yt.html só aceita id de 11 caracteres e a exceção de iframe vale só para ele.
// 24/11/2026: no app o vídeo TOCA DENTRO (a capa abre o player no mesmo quadro, não o aplicativo do YouTube) e yt.html avisa quando o YouTube recusa.
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
  eq('no app o exercício mostra a capa com play (sem iframe preto e sem link que abra o YouTube) e mantém o atalho', [n.html.indexOf('<iframe') < 0 && n.html.indexOf('i.ytimg.com/vi/' + ID) >= 0, /Abrir o vídeo no YouTube/.test(n.html), n.html.indexOf('src="https://www.youtube.com/embed') < 0], [true, true, true]);
  eq('link que não é do YouTube não é mexido', n.naoYoutube, 'https://exemplo.com/video.mp4');
  eq('sem erro de JavaScript', t.erros, []); await t.ctx.close();

  console.log('\n══ Web: continua direto no YouTube ══');
  t = await abrir(false);
  const w = await t.page.evaluate((id) => ({ modal: youtubeToEmbed('https://youtu.be/' + id), nocookie: _ytSrc(id, false, true) }), ID);
  eq('web: embed direto do YouTube', [w.modal, w.nocookie], ['https://www.youtube.com/embed/' + ID + '?rel=0&playsinline=1&autoplay=1', 'https://www.youtube-nocookie.com/embed/' + ID + '?rel=0&playsinline=1']);
  // O HTML do vídeo (exercício/alongamento) tem que gerar um iframe com o endereço certo, para vídeo comum e para Shorts, em qualquer formato de link
  const formas = ['https://www.youtube.com/watch?v=' + ID, 'https://youtu.be/' + ID + '?si=abc', 'https://www.youtube.com/shorts/' + ID, 'https://youtube.com/shorts/' + ID + '?feature=share', 'https://m.youtube.com/watch?v=' + ID + '&t=5s', 'https://www.youtube.com/embed/' + ID];
  const srcs = await t.page.evaluate((fs) => fs.map((u) => { const d = document.createElement('div'); d.innerHTML = _infoVideoHtml(u); const f = d.querySelector('iframe'); return f ? f.getAttribute('src') : null; }), formas);
  eq('web: vídeo comum e Shorts, em todos os formatos de link, viram iframe com o embed certo', srcs, formas.map(() => 'https://www.youtube.com/embed/' + ID + '?rel=0&playsinline=1'));
  await t.ctx.close();
  t = await abrir(true);
  const cartoes = await t.page.evaluate((fs) => fs.map((u) => { const d = document.createElement('div'); d.innerHTML = _infoVideoHtml(u); const a = d.querySelector('a[href*="watch?v="]'); return [!!d.querySelector('iframe'), a ? a.getAttribute('href') : null]; }), formas);
  eq('app: vídeo comum e Shorts mostram a capa com play que abre o vídeo certo no YouTube (sem iframe)', cartoes, formas.map(() => [false, 'https://www.youtube.com/watch?v=' + ID]));
  await t.ctx.close();

  console.log('\n══ App: tocar o vídeo dentro do app ══');
  t = await abrir(true);
  await t.page.evaluate((id) => {
    window.__abertos = []; window.open = (...a) => { window.__abertos.push(a[0]); return null; };
    const d = document.createElement('div'); d.id = 'caixa-teste'; d.innerHTML = _infoVideoHtml('https://www.youtube.com/watch?v=' + id); document.body.appendChild(d);
  }, ID);
  const antes = await t.page.evaluate(() => ({ iframes: document.querySelectorAll('#caixa-teste iframe').length, linkNaCapa: !!document.querySelector('#caixa-teste .yt-cartao a, #caixa-teste a.yt-cartao') }));
  eq('antes de tocar: só a capa, sem iframe e sem link dentro dela', antes, { iframes: 0, linkNaCapa: false });
  await t.page.evaluate(() => document.querySelector('#caixa-teste .yt-cartao').click());
  const dep = await t.page.evaluate(() => { const f = document.querySelector('#caixa-teste iframe.yt-inline'); return { src: f && f.getAttribute('src'), pai: f && f.parentNode.className, capa: getComputedStyle(document.querySelector('#caixa-teste .yt-cartao')).display, abertos: window.__abertos.length }; });
  eq('tocar a capa abre o player no mesmo quadro (yt.html, com início) e não abre o YouTube', dep, { src: SITE + '/app/painel/yt.html?v=' + ID + '&auto=1', pai: 'info-video', capa: 'none', abertos: 0 });
  // aviso de erro vindo de yt.html: só vale se vier do próprio site e do iframe do vídeo
  const falso = await t.page.evaluate((id) => { const f = document.querySelector('#caixa-teste iframe.yt-inline'); window.dispatchEvent(new MessageEvent('message', { data: { intusYt: 'erro', id }, origin: 'https://evil.test', source: f.contentWindow })); return document.querySelectorAll('#caixa-teste .yt-erro').length; }, ID);
  eq('aviso de erro de outro site é ignorado', falso, 0);
  const real = await t.page.evaluate((id) => { const f = document.querySelector('#caixa-teste iframe.yt-inline'); window.dispatchEvent(new MessageEvent('message', { data: { intusYt: 'erro', id }, origin: 'https://intusfit.com.br', source: f.contentWindow })); const m = document.querySelector('#caixa-teste .yt-erro'); const a = m && m.querySelector('a'); return { aviso: !!m, link: a && a.getAttribute('href') }; }, ID);
  eq('quando o YouTube recusa, o quadro mostra o aviso e o atalho "Abrir no YouTube"', real, { aviso: true, link: 'https://www.youtube.com/watch?v=' + ID });
  const modal = await t.page.evaluate((id) => { window.__abertos = []; abrirVideoModal('https://youtu.be/' + id); return { src: document.getElementById('video-iframe').getAttribute('src'), aberto: document.getElementById('video-modal').classList.contains('open'), abertos: window.__abertos.length }; }, ID);
  eq('o modal de vídeo também toca dentro do app (não abre o YouTube)', modal, { src: SITE + '/app/painel/yt.html?v=' + ID + '&auto=1', aberto: true, abertos: 0 });
  eq('sem erro de JavaScript (tocar dentro)', t.erros, []); await t.ctx.close();

  console.log('\n══ yt.html ══');
  async function yt(query, modo) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 300 }, serviceWorkers: 'block' }); const page = await ctx.newPage();
    await page.addInitScript(() => { window.__msgs = []; });
    // API de iframe simulada: registra os parâmetros do player e, no modo 'erro', dispara onError; no 'ok', onReady
    await page.route(/youtube\.com\/iframe_api/, r => r.fulfill({ status: 200, contentType: 'text/javascript', body: "window.YT = { Player: function (el, o) { window.__player = { el: el, o: o }; setTimeout(function () { if (" + (modo === 'erro' ? "true" : "false") + ") o.events.onError({ data: 153 }); else o.events.onReady({ target: { playVideo: function () { window.__tocou = true; } } }); }, 20); } }; setTimeout(function () { window.onYouTubeIframeAPIReady(); }, 5);" }));
    await page.goto(base + '/yt.html' + query, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(500);
    const r = await page.evaluate(() => window.__player ? { id: window.__player.o.videoId, pv: window.__player.o.playerVars, tocou: !!window.__tocou } : null);
    await ctx.close(); return r;
  }
  let r = await yt('?v=' + ID + '&auto=1');
  eq('yt.html: player do YouTube com a origem do site, playsinline e início automático', [r && r.id, r && r.pv.playsinline, r && r.pv.autoplay, r && /^http:\/\/127\.0\.0\.1:\d+$/.test(r.pv.origin), r && r.tocou], [ID, 1, 1, true, true]);
  r = await yt('?v=' + ID);
  eq('sem auto=1 não inicia sozinho', [r && r.pv.autoplay, r && r.tocou], [0, false]);
  eq('id inválido não cria player', [await yt('?v=abc'), await yt('?v=' + ID + 'x'), await yt('?v=https://evil.test/x'), await yt('')], [null, null, null, null]);
  // quando o YouTube dá erro, yt.html avisa o app (postMessage) uma vez só
  {
    const ctx = await b.newContext({ viewport: { width: 390, height: 300 }, serviceWorkers: 'block' }); const page = await ctx.newPage();
    await page.route(/youtube\.com\/iframe_api/, r => r.fulfill({ status: 200, contentType: 'text/javascript', body: "window.YT = { Player: function (el, o) { setTimeout(function () { o.events.onError({ data: 153 }); o.events.onError({ data: 101 }); }, 20); } }; setTimeout(function () { window.onYouTubeIframeAPIReady(); }, 5);" }) );
    await page.route(/\/yt-pai\.html/, r => r.fulfill({ status: 200, contentType: 'text/html', body: '<html><body><script>window.__m=[];addEventListener("message",function(e){__m.push(e.data)});</script><iframe src="/yt.html?v=' + ID + '"></iframe></body></html>' }));
    await page.goto(base + '/yt-pai.html'); await page.waitForTimeout(700);
    const msgs = await page.evaluate(() => window.__m);
    eq('erro do YouTube: yt.html avisa o app uma vez só, com o id do vídeo', msgs.filter(m => m && m.intusYt === 'erro').map(m => m.id), [ID]);
    await ctx.close();
  }
  const ht = fs.readFileSync(path.join(RAIZ, '.htaccess'), 'utf8');
  eq('a exceção de iframe vale só para yt.html (.htaccess)', [/<Files "yt\.html">[\s\S]*unset X-Frame-Options[\s\S]*frame-ancestors \*[\s\S]*<\/Files>/.test(ht), (ht.match(/frame-ancestors \*/g) || []).length], [true, 1]);

  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
