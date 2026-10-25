// Compartilhar treino/cardio: a figurinha e as informações do treino são duas peças que o aluno move e redimensiona (foto, câmera ao vivo e
// vídeo), nenhuma sai da moldura, e os cinco modelos não cortam texto em 9:16, 1:1 e 4:5. A imagem final sai com cada peça no lugar visto na prévia.
//   cd testes && INTUS_DIR=../app/painel node compartilhar_pecas.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8113, '127.0.0.1', r));
  const b = await chromium.launch({ args: ['--autoplay-policy=no-user-gesture-required'] }); const page = await b.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); });
  await page.goto('http://127.0.0.1:8113/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof INFO_LAYOUTS !== 'undefined' && typeof html2canvas === 'function' && typeof _ovGestos === 'function' && typeof abrirCompartilharTreino === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
  await page.evaluate(async () => { await Promise.all(['logo-intus-dark.png', 'logo-icon-dark.png'].map(u => new Promise(r => { const i = new Image(); i.onload = i.onerror = r; i.src = u; }))); });

  // figurinha de teste: um quadrado VERMELHO liso, fácil de achar nos pixels
  const FIG = await page.evaluate(() => { const c = document.createElement('canvas'); c.width = c.height = 40; const x = c.getContext('2d'); x.fillStyle = '#ff0000'; x.fillRect(0, 0, 40, 40); return { tipo: 'img', src: c.toDataURL('image/png') }; });
  const INFO = { tipo: 'treino', min: 59, sec: 3, volume: 14280, seriesFeitas: 28, seriesTotal: 28, pctCompleto: 100, nomeAluno: 'Luiz Nunes', nomeTreino: 'Ativação Metabólica 1.2', mensagem: 'x' };
  const abrir = (lay, foto) => page.evaluate(([lay, foto, FIG, INFO]) => {
    abrirCompartilharTreino(Object.assign({ fig: FIG }, INFO));
    _composeInfoLayoutIdx = lay; _composeFig = FIG;
    if (foto) { const c = document.createElement('canvas'); c.width = 800; c.height = 1000; const x = c.getContext('2d'); x.fillStyle = '#2a3a4a'; x.fillRect(0, 0, 800, 1000); _feedComposeImg = c.toDataURL('image/jpeg', .9); _composeImgRatio = 1.25; }
    _renderComposeFeed();
  }, [lay, foto, FIG, INFO]);
  const retDe = (nome) => page.evaluate((n) => { const el = document.querySelector('#feed-compose-info-layer [data-ov="' + n + '"]'); const r = el.getBoundingClientRect(); const m = document.getElementById('feed-compose-preview').getBoundingClientRect(); return { l: r.left - m.left, t: r.top - m.top, r: r.right - m.left, b: r.bottom - m.top, w: r.width, h: r.height, mw: m.width, mh: m.height, cx: (r.left + r.right) / 2, cy: (r.top + r.bottom) / 2 }; }, nome);
  const arrastar = async (de, para, passos = 8) => { await page.mouse.move(de[0], de[1]); await page.mouse.down(); for (let i = 1; i <= passos; i++) await page.mouse.move(de[0] + (para[0] - de[0]) * i / passos, de[1] + (para[1] - de[1]) * i / passos); await page.mouse.up(); };
  const centro = (r, m) => [r.cx, r.cy];

  console.log('\n══ A prévia cabe na coluna e o botão de ajuste existe (foto) ══');
  await abrir(3, true);
  const g0 = await page.evaluate(() => { const p = document.getElementById('feed-compose-preview').getBoundingClientRect(); const c = document.querySelector('#feed-compose-overlay > div:last-child'); const cr = c.getBoundingClientRect(); return { esq: Math.round(p.left - (cr.left + 16)), dir: Math.round((cr.right - 16) - p.right), btn: !!document.getElementById('feed-ajustar-btn'), semBotaoNaFoto: !document.querySelector('#feed-compose-preview #feed-ajustar-btn') }; });
  ver('a prévia não passa da coluna (margens iguais dos dois lados)', Math.abs(g0.esq - g0.dir) <= 1 && g0.esq >= 0 && g0.dir >= 0, g0);
  ver('o botão "Mover e redimensionar" fica abaixo da foto, não por cima dela', g0.btn && g0.semBotaoNaFoto, g0);

  console.log('\n══ Duas peças independentes ══');
  const fig0 = await retDe('fig'), info0 = await retDe('info');
  ver('a figurinha e o bloco de informações são peças separadas', fig0.w > 10 && info0.w > 100 && fig0.w < info0.w, [fig0, info0]);
  await page.click('#feed-ajustar-btn');
  const visCtl = await page.evaluate(() => getComputedStyle(document.getElementById('feed-ajuste-ctl')).display);
  eq('ligar o ajuste mostra os controles (peça, + e −)', visCtl, 'block');
  await page.evaluate(() => document.getElementById('feed-compose-overlay').scrollTo(0, 0));
  const pr = await page.evaluate(() => { const m = document.getElementById('feed-compose-preview').getBoundingClientRect(); return [m.left, m.top]; });
  // arrasta a FIGURINHA para a esquerda e para cima
  let f = await retDe('fig'); let inf = await retDe('info');
  await arrastar([pr[0] + f.cx - pr[0] - 0, f.cy], [pr[0] + 40, pr[1] + 90]);
  const fig1 = await retDe('fig'), info1 = await retDe('info');
  ver('arrastar a figurinha move a figurinha', Math.abs(fig1.cx - fig0.cx) > 40 || Math.abs(fig1.cy - fig0.cy) > 40, [fig0, fig1]);
  eq('...e não mexe nas informações', [Math.round(info1.cx), Math.round(info1.cy)], [Math.round(info0.cx), Math.round(info0.cy)]);
  // arrasta as INFORMAÇÕES para cima
  const iAntes = await retDe('info');
  await arrastar([iAntes.cx, iAntes.cy], [iAntes.cx, iAntes.cy - 120]);
  const info2 = await retDe('info'), fig2 = await retDe('fig');
  ver('arrastar as informações move só elas', info2.cy < iAntes.cy - 60, [iAntes, info2]);
  eq('...e a figurinha fica onde estava', [Math.round(fig2.cx), Math.round(fig2.cy)], [Math.round(fig1.cx), Math.round(fig1.cy)]);
  const alvo = await page.evaluate(() => _composeAlvo);
  eq('a peça tocada vira a escolhida', alvo, 'info');

  console.log('\n══ Tamanho: botões, pinça, roda do mouse ══');
  const t0 = await retDe('info');
  await page.click('button[title="Diminuir"]');
  const t1 = await retDe('info');
  ver('o botão − encolhe a peça escolhida', t1.w < t0.w - 5, [t0.w, t1.w]);
  await page.click('button[title="Aumentar"]'); await page.click('button[title="Aumentar"]');
  const t2 = await retDe('info');
  ver('o botão + aumenta, e a peça nunca passa da largura da moldura', t2.w > t1.w && t2.w <= t2.mw + 1, [t1.w, t2.w, t2.mw]);
  await page.click('#feed-alvo-fig');
  const fa = await retDe('fig');
  await page.click('button[title="Aumentar"]'); await page.click('button[title="Aumentar"]'); await page.click('button[title="Aumentar"]');
  const fb = await retDe('fig');
  ver('com a figurinha escolhida, + e − mudam só a figurinha', fb.w > fa.w * 1.3, [fa.w, fb.w]);
  // pinça com dois ponteiros (eventos de ponteiro direto na camada)
  const pinca = await page.evaluate(() => {
    const cam = document.getElementById('feed-compose-info-layer'); const el = cam.querySelector('[data-ov="fig"]'); const r = el.getBoundingClientRect();
    const mk = (tipo, id, x, y) => cam.dispatchEvent(new PointerEvent(tipo, { pointerId: id, clientX: x, clientY: y, bubbles: true, pointerType: 'touch', isPrimary: id === 1 }));
    const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    const antes = el.getBoundingClientRect().width;
    mk('pointerdown', 1, cx - 8, cy); mk('pointerdown', 2, cx + 8, cy);
    for (let i = 1; i <= 8; i++) { mk('pointermove', 1, cx - 8 - i * 4, cy); mk('pointermove', 2, cx + 8 + i * 4, cy); }
    mk('pointerup', 1, 0, 0); mk('pointerup', 2, 0, 0);
    return { antes, depois: el.getBoundingClientRect().width };
  });
  ver('a pinça com dois dedos aumenta a peça', pinca.depois > pinca.antes * 1.4, pinca);
  const w1 = (await retDe('fig')).w;
  await page.evaluate(() => { const cam = document.getElementById('feed-compose-info-layer'); cam.dispatchEvent(new WheelEvent('wheel', { deltaY: 100, bubbles: true, cancelable: true })); });
  ver('a roda do mouse muda o tamanho', (await retDe('fig')).w < w1, w1);

  console.log('\n══ Nenhuma peça sai da moldura ══');
  const fg = await retDe('fig');
  await arrastar([fg.cx, fg.cy], [-300, -300].map((v, i) => i ? 5 : 5), 6);
  const fl = await retDe('fig');
  ver('arrastar a figurinha para fora do canto: ela fica inteira dentro', fl.l >= -1 && fl.t >= -1 && fl.r <= fl.mw + 1 && fl.b <= fl.mh + 1, fl);
  const ig = await retDe('info');
  await page.click('#feed-alvo-info');
  await arrastar([ig.cx, ig.cy], [ig.cx + 400, ig.cy + 900], 6);
  const il = await retDe('info');
  ver('arrastar as informações para além da borda: ficam inteiras dentro', il.l >= -1 && il.t >= -1 && il.r <= il.mw + 1 && il.b <= il.mh + 1, il);
  for (let i = 0; i < 8; i++) await page.click('button[title="Aumentar"]');
  const ib = await retDe('info');
  ver('aumentar muitas vezes não faz a peça ficar maior que a moldura', ib.w <= ib.mw + 1 && ib.h <= ib.mh + 1 && ib.l >= -1 && ib.r <= ib.mw + 1, ib);
  await page.click('button[title="Voltar ao lugar de partida"], #feed-ajuste-ctl button:last-child');
  const ir = await retDe('info');
  eq('"↺" devolve a peça ao lugar de partida', [Math.round(ir.cx), Math.round(ir.cy)], [Math.round(info0.cx), Math.round(info0.cy)]);
  await page.click('#feed-ajustar-btn');
  const poe = await page.evaluate(() => getComputedStyle(document.getElementById('feed-compose-info-layer')).pointerEvents);
  eq('fora do modo de ajuste a foto não rouba a rolagem da tela', poe, 'none');

  console.log('\n══ A câmera ao vivo também ajusta ══');
  await page.evaluate(([FIG, INFO]) => {
    abrirCompartilharTreino(Object.assign({ fig: FIG }, INFO));
    _camStream = { getTracks() { return []; } };
    _camVideoEl = document.createElement('video'); _camVideoEl.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;background:#345;';
    _feedComposeImg = null; _composeInfoLayoutIdx = 3; _composeFig = FIG; _renderComposeFeed();
  }, [FIG, INFO]);
  const cam = await page.evaluate(() => ({ btn: !!document.getElementById('feed-ajustar-btn'), camada: !!document.getElementById('feed-compose-info-layer'), pecas: document.querySelectorAll('#feed-compose-info-layer [data-ov]').length }));
  eq('na câmera aparece a camada, as duas peças e o botão de ajuste', cam, { btn: true, camada: true, pecas: 2 });
  await page.evaluate(() => document.getElementById('feed-compose-overlay').scrollTo(0, 0));
  await page.click('#feed-ajustar-btn');
  const cf0 = await retDe('fig'); const cp = await page.evaluate(() => { const m = document.getElementById('feed-compose-preview').getBoundingClientRect(); return [m.left, m.top]; });
  await arrastar([cf0.cx, cf0.cy], [cp[0] + 60, cp[1] + 80]);
  const cf1 = await retDe('fig');
  ver('na câmera, arrastar move a figurinha', Math.abs(cf1.cx - cf0.cx) > 40, [cf0, cf1]);
  await page.evaluate(() => { _camStream = null; _camVideoEl = null; });

  console.log('\n══ A imagem final sai com cada peça no lugar visto ══');
  await abrir(0, true);
  await page.evaluate(() => { document.getElementById('feed-compose-overlay').scrollTo(0, 0); });
  await page.click('#feed-ajustar-btn');
  const base = await retDe('fig');
  const mpos = await page.evaluate(() => { const m = document.getElementById('feed-compose-preview').getBoundingClientRect(); return [m.left, m.top]; });
  await arrastar([base.cx, base.cy], [mpos[0] + 70, mpos[1] + 120]);
  await page.click('button[title="Aumentar"]'); await page.click('button[title="Aumentar"]');
  const vista = await retDe('fig');
  await page.click('#feed-alvo-info'); const ib0 = await retDe('info');
  await arrastar([ib0.cx, ib0.cy], [ib0.cx, ib0.cy - 60]);   // sobe um pouco, sem cobrir a figurinha vermelha
  await page.click('#feed-ajustar-btn');
  const caixa = await page.evaluate(async () => {
    const url = await _composeImagemFinal();
    const img = await new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = url; });
    const c = document.createElement('canvas'); c.width = img.width; c.height = img.height; const x = c.getContext('2d'); x.drawImage(img, 0, 0);
    const d = x.getImageData(0, 0, c.width, c.height).data; let x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1;
    for (let y = 0; y < c.height; y++) for (let xx = 0; xx < c.width; xx++) { const i = (y * c.width + xx) * 4; if (d[i] > 200 && d[i + 1] < 60 && d[i + 2] < 60) { if (xx < x0) x0 = xx; if (xx > x1) x1 = xx; if (y < y0) y0 = y; if (y > y1) y1 = y; } }
    // faixa escura das informações: procura as linhas com o verde da "100%" (7FFF00), que só existe no bloco
    let vy0 = 1e9, vy1 = -1; for (let y = 0; y < c.height; y++) for (let xx = 0; xx < c.width; xx += 2) { const i = (y * c.width + xx) * 4; if (d[i] > 90 && d[i] < 170 && d[i + 1] > 230 && d[i + 2] < 60) { if (y < vy0) vy0 = y; if (y > vy1) vy1 = y; } }
    return { W: c.width, H: c.height, x0, y0, x1, y1, vy0, vy1 };
  });
  const ex = caixa.W / vista.mw;
  const esperado = { l: vista.l * ex, t: vista.t * ex, r: vista.r * ex, b: vista.b * ex };
  const tol = Math.max(10, caixa.W * 0.02);
  ver('a figurinha vermelha saiu na posição e no tamanho da prévia', caixa.x1 > 0 && Math.abs(caixa.x0 - esperado.l) < tol && Math.abs(caixa.y0 - esperado.t) < tol && Math.abs(caixa.x1 - esperado.r) < tol && Math.abs(caixa.y1 - esperado.b) < tol, { caixa, esperado, ex });
  ver('o bloco de informações subiu: o texto verde "100%" está acima do lugar de partida', caixa.vy1 > 0 && caixa.vy1 < (info0.t + info0.h) * ex - 40, { caixa, info0 });
  const ibFim = await retDe('info');
  ver('...e na imagem final ele está onde a prévia mostrou (altura do texto verde dentro do bloco)', caixa.vy0 >= ibFim.t * ex - 4 && caixa.vy1 <= ibFim.b * ex + 4, { caixa, ibFim, ex });

  console.log('\n══ Vídeo: as duas peças e a camada na largura da moldura ══');
  const vid = await page.evaluate(async ([FIG, INFO]) => {
    // sem vídeo de verdade: monta só a moldura do editor e chama a camada final
    document.getElementById('feed-compose-overlay')?.remove();
    const fr = document.createElement('div'); fr.id = 'vt-frame'; fr.style.cssText = 'position:fixed;left:0;top:0;width:300px;height:533px;'; document.body.appendChild(fr);
    const cam = document.createElement('div'); cam.id = 'vt-camada'; cam.style.cssText = 'position:absolute;inset:0;'; fr.appendChild(cam);
    _vt = { info: { on: true, dados: INFO, layoutIdx: 0, incluirFig: true, recorde: true, fig: FIG }, TF: { x: -0.25, y: -0.3, s: 2 }, TI: { x: 0, y: -0.1, s: 0.8 }, alvo: 'fig', ajustando: false, filtroOk: false, filtro: '' };
    const cv = await _vtCamadaCanvas(_vt, 720, 1280);
    const x = cv.getContext('2d'); const d = x.getImageData(0, 0, cv.width, cv.height).data; let x0 = 1e9, y0 = 1e9, x1 = -1, y1 = -1;
    for (let y = 0; y < cv.height; y++) for (let xx = 0; xx < cv.width; xx++) { const i = (y * cv.width + xx) * 4; if (d[i] > 200 && d[i + 1] < 60 && d[i + 2] < 60 && d[i + 3] > 200) { if (xx < x0) x0 = xx; if (xx > x1) x1 = xx; if (y < y0) y0 = y; if (y > y1) y1 = y; } }
    _vt = null; fr.remove();
    return { w: cv.width, h: cv.height, x0, y0, x1, y1 };
  }, [FIG, INFO]);
  ver('a camada do vídeo sai do tamanho do vídeo (o html2canvas arredonda a altura para cima)', vid.w === 720 && Math.abs(vid.h - 1280) <= 2, vid);
  ver('a figurinha do vídeo está deslocada e maior (x e y negativos, escala 2)', vid.x1 > 0 && vid.x1 - vid.x0 > 100 && vid.x0 < 720 * 0.5 - 30 && vid.y0 < 1280 * 0.5, vid);

  console.log('\n══ Tema escuro: os botões de ajuste não ficam brancos ══');
  await abrir(0, true);
  const esc = await page.evaluate(async () => {
    document.body.classList.remove('tema-claro'); _renderComposeFeed();
    document.getElementById('feed-compose-overlay').scrollTo(0, 0);
    const claro = (el) => { const c = getComputedStyle(el).backgroundColor.match(/\d+/g).map(Number); return c[0] > 200 && c[1] > 200 && c[2] > 200; };
    document.getElementById('feed-ajustar-btn').click();
    const fig = document.getElementById('feed-alvo-fig'), info = document.getElementById('feed-alvo-info');
    _composeEscolherAlvo('info');
    const r = { chipFora: claro(fig), chipEscolhido: claro(info) };
    _composeEscolherAlvo('fig');
    r.chipDepois = claro(info);
    document.getElementById('feed-ajustar-btn').click();
    r.botaoDepois = claro(document.getElementById('feed-ajustar-btn'));
    document.body.classList.add('tema-claro');
    return r;
  });
  eq('as peças não escolhidas e o botão "Mover e redimensionar" mantêm o fundo escuro (antes ficavam brancos)', esc, { chipFora: false, chipEscolhido: false, chipDepois: false, botaoDepois: false });

  console.log('\n══ Editor de vídeo de verdade: ajustar as duas peças e gravar ══');
  await page.reload({ waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _vtAbrir === 'function' && typeof API !== 'undefined' && API.trecho).catch(() => false)) break; await page.waitForTimeout(250); }
  const vid2 = await page.evaluate(async ([FIG, INFO]) => {
    const esperar = async (fn, ms) => { const t0 = Date.now(); while (Date.now() - t0 < ms) { if (fn()) return true; await new Promise(r => setTimeout(r, 100)); } return false; };
    // vídeo de 2 s feito no próprio navegador: fundo liso azul escuro
    const cv = document.createElement('canvas'); cv.width = 360; cv.height = 640; const cx = cv.getContext('2d');
    const rec = new MediaRecorder(cv.captureStream(15), { mimeType: 'video/webm;codecs=vp8' }); const partes = []; rec.ondataavailable = e => e.data.size && partes.push(e.data);
    const fim = new Promise(r => rec.onstop = r); rec.start(200);
    const t0 = Date.now(); while (Date.now() - t0 < 2200) { cx.fillStyle = '#1b2a3a'; cx.fillRect(0, 0, 360, 640); cx.fillStyle = '#3a5a7a'; cx.fillRect((Date.now() - t0) / 10 % 300, 300, 40, 40); await new Promise(r => setTimeout(r, 60)); }
    rec.stop(); await fim;
    const arquivo = new File([new Blob(partes, { type: 'video/webm' })], 'v.webm', { type: 'video/webm' });
    abrirCompartilharTreino(Object.assign({ fig: FIG }, INFO)); _composeFig = FIG; _composeInfoLayoutIdx = 0;
    const prom = new Promise(res => _vtAbrir(arquivo, res));
    const abriu = await esperar(() => document.getElementById('vt-controles') && document.getElementById('vt-controles').style.display === 'block', 15000);
    if (!abriu) return { erro: 'o editor não abriu' };
    _vtAba('figurinhas'); _vtAjustar();   // o botão "Mover e redimensionar"
    const pecas = document.querySelectorAll('#vt-camada [data-ov]').length;
    const fr = document.getElementById('vt-frame').getBoundingClientRect();
    const painel = document.getElementById('vt-painel').textContent;
    // arrasta a figurinha para o canto de cima, à esquerda
    const cam = document.getElementById('vt-camada'); const fg = cam.querySelector('[data-ov="fig"]'); const r0 = fg.getBoundingClientRect();
    const mk = (tipo, x, y) => cam.dispatchEvent(new PointerEvent(tipo, { pointerId: 1, clientX: x, clientY: y, bubbles: true, pointerType: 'mouse', isPrimary: true }));
    const cx0 = r0.left + r0.width / 2, cy0 = r0.top + r0.height / 2, alvoX = fr.left + 40, alvoY = fr.top + 50;
    mk('pointerdown', cx0, cy0); for (let i = 1; i <= 10; i++) mk('pointermove', cx0 + (alvoX - cx0) * i / 10, cy0 + (alvoY - cy0) * i / 10); mk('pointerup', alvoX, alvoY);
    const moveu = { x: _vt.TF.x, y: _vt.TF.y, infoX: _vt.TI.x, infoY: _vt.TI.y };
    _vtAjustar(false);
    // grava o trecho de verdade e olha um quadro do resultado
    _vtUsar();
    const item = await Promise.race([prom, new Promise(r => setTimeout(() => r(null), 40000))]);
    if (!item) return { erro: 'o vídeo não ficou pronto', pecas, moveu };
    const v = document.createElement('video'); v.muted = true; v.src = URL.createObjectURL(item.file); v.preload = 'auto';
    await new Promise(r => { v.onloadeddata = r; setTimeout(r, 8000); });
    try { v.currentTime = 0.6; await new Promise(r => { v.onseeked = r; setTimeout(r, 4000); }); } catch (e) {}
    const c = document.createElement('canvas'); c.width = v.videoWidth || 360; c.height = v.videoHeight || 640; const x = c.getContext('2d'); x.drawImage(v, 0, 0, c.width, c.height);
    const d = x.getImageData(0, 0, c.width, c.height).data; let sx = 0, sy = 0, n = 0;
    for (let y = 0; y < c.height; y++) for (let xx = 0; xx < c.width; xx++) { const i = (y * c.width + xx) * 4; if (d[i] > 190 && d[i + 1] < 70 && d[i + 2] < 70) { sx += xx; sy += y; n++; } }
    return { pecas, moveu, painel: /Figurinha/.test(painel) && /Informações/.test(painel), w: c.width, h: c.height, n, cx: n ? sx / n / c.width : -1, cy: n ? sy / n / c.height : -1, dur: item.dur };
  }, [FIG, INFO]);
  ver('o editor de vídeo abre e monta as duas peças', !vid2.erro && vid2.pecas === 2, vid2);
  ver('o painel mostra as peças Figurinha e Informações', vid2.painel, vid2);
  ver('arrastar a figurinha no vídeo mexe só nela', vid2.moveu && vid2.moveu.y < -0.2 && vid2.moveu.infoX === 0 && vid2.moveu.infoY === 0, vid2.moveu);
  ver('o vídeo gravado leva a figurinha vermelha no canto de cima à esquerda (onde o aluno a deixou)', vid2.n > 50 && vid2.cx < 0.45 && vid2.cy < 0.45, vid2);

  console.log('\n══ Os cinco modelos em 9:16, 1:1 e 4:5 não cortam nada ══');
  const aud = await page.evaluate(async () => {
    const infos = {
      curto: { tipo: 'treino', min: 59, sec: 3, volume: 14280, seriesFeitas: 28, seriesTotal: 28, pctCompleto: 100, nomeAluno: 'Luiz Nunes', nomeTreino: 'Ativação Metabólica 1.2' },
      longo: { tipo: 'treino', min: 124, sec: 59, volume: 128960, seriesFeitas: 128, seriesTotal: 128, pctCompleto: 100, nomeAluno: 'Maria Fernanda Albuquerque da Silva', nomeTreino: 'Treino C Posterior de coxa e glúteo com ênfase em panturrilha e abdômen completo' },
      cardio: { tipo: 'cardio', min: 45, sec: 0, distKm: 10.52, kcal: 612, nomeAluno: 'Ana Luisa Mansour', nomeTreino: '🏃 Corrida', mensagem: 'Cardio de hoje concluído! 🏃' },
      semDist: { tipo: 'cardio', min: 30, sec: 0, distKm: 0, kcal: 250, nomeAluno: 'Ana', nomeTreino: 'Programa: HIIT 1:1', mensagem: 'x' },
      recordes: { tipo: 'treino', min: 50, sec: 0, volume: 9000, seriesFeitas: 20, seriesTotal: 24, pctCompleto: 83, nomeAluno: 'Isabela Pazzinatto', nomeTreino: 'Treino A', recordes: [{ exercicio: 'Supino reto com barra', peso: 80, reps: 6 }, { exercicio: 'Agachamento livre', peso: 120, reps: 5 }, { exercicio: 'Remada curvada', peso: 70, reps: 8 }], mensagem: 'x' },
    };
    const figs = [{ tipo: 'img', src: 'logo-icon-dark.png' }, { tipo: 'emoji', emoji: '💪🔥', legenda: 'MODO MONSTRO', cor: '#f59e0b' }];
    const problemas = []; let n = 0;
    for (const [rn, hw] of Object.entries({ '9:16': 16 / 9, '1:1': 1, '4:5': 1.25 })) for (const W of [260, 300, 308]) {
      const H = W * hw, esc = _shareEscalaComAltura(W, hw);
      for (const L of INFO_LAYOUTS) for (const [inm, info] of Object.entries(infos)) for (const fig of figs) {
        const host = document.createElement('div'); host.style.cssText = 'position:fixed;left:0;top:0;width:' + W + 'px;height:' + H + 'px;background:#555;';
        host.innerHTML = L.render(info, fig, 'LIVE', false, 0, esc, true); document.body.appendChild(host);
        await Promise.all([...host.querySelectorAll('img')].map(i => i.complete ? 1 : new Promise(r => { i.onload = i.onerror = r; })));
        n++; const hr = host.getBoundingClientRect();
        host.querySelectorAll('*').forEach(el => { const r = el.getBoundingClientRect(); if (!r.width || !r.height) return;
          if (r.left < hr.left - 1 || r.right > hr.right + 1 || r.top < hr.top - 1 || r.bottom > hr.bottom + 1) problemas.push(L.id + ' ' + rn + ' ' + W + ' ' + inm + ': <' + el.tagName + '> fora do cartão');
          if (el.scrollWidth > el.clientWidth + 1 && el.clientWidth > 0 && getComputedStyle(el).display !== 'inline') problemas.push(L.id + ' ' + rn + ' ' + W + ' ' + inm + ': texto passa da caixa "' + (el.textContent || '').trim().slice(0, 24) + '"'); });
        // a figurinha não pode cobrir o bloco de informações em partida
        const fg = host.querySelector('[data-ov="fig"]'), bl = host.querySelector('[data-ov="info"]');
        if (fg && bl) { const a = fg.getBoundingClientRect(), c = bl.getBoundingClientRect(); if (a.bottom > c.top + 1) problemas.push(L.id + ' ' + rn + ' ' + W + ' ' + inm + ': a figurinha começa em cima das informações'); }
        host.remove();
      }
    }
    return { n, problemas: problemas.slice(0, 8), total: problemas.length };
  });
  ver('nenhum elemento sai do cartão, nenhum texto passa da caixa e a figurinha não cobre as informações (' + aud.n + ' combinações)', aud.total === 0, aud);

  console.log('\n══ Estrutura ══');
  const html = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  ver('as duas peças têm estado próprio no compositor e no vídeo', /_composeFigT/.test(html) && /_composeInfoT/.test(html) && /TF: doCompositor/.test(html) && /TI: doCompositor/.test(html));
  ver('a camada do vídeo só clona a figurinha e não espera imagem para sempre', /async function _vtCamadaCanvas[\s\S]{0,2600}ignoreElements[\s\S]{0,200}imageTimeout|imageTimeout: 8000,\s*\n\s*ignoreElements/.test(html));
  ver('o texto do nome do treino não usa line-clamp (a imagem final não o respeita)', !/-webkit-line-clamp/.test(html.slice(html.indexOf('function _shareBarraInferior'), html.indexOf('function _shareLogoRodape'))));
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
