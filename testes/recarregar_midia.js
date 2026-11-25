// Foto ou vídeo que falha vira "toque para recarregar" e volta a tentar (25/11/2026).
//   cd testes && INTUS_DIR=../app/painel node recarregar_midia.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
(async () => {
  let pedidos = 0, ligado = false;
  const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
  const srv = http.createServer((req, res) => {
    const u = req.url.split('?')[0];
    if (u === '/foto.png') { pedidos++; if (!ligado) { res.writeHead(503); return res.end(); } res.writeHead(200, { 'content-type': 'image/png' }); return res.end(PNG); }
    if (u === '/pagina.html') {
      res.writeHead(200, { 'content-type': 'text/html' });
      return res.end('<head><script src="/recarregar-midia.js"></' + 'script></head><body><div id="caixa" style="width:300px;height:200px"><img id="f" src="/foto.png" class="grande" style="width:300px;height:200px;border-radius:12px"></div>' +
        '<img id="com" src="/foto.png" onerror="this.dataset.tratou=1" style="width:300px;height:200px">' +
        '<img id="mini" src="/foto.png" style="width:16px;height:16px">' +
        '<img id="opt" data-sem-recarregar src="/foto.png" style="width:300px;height:200px">' +
        '</body>');
    }
    const f = path.join(RAIZ, u); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'content-type': 'text/javascript; charset=utf-8' }); res.end(fs.readFileSync(f));
  });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 } }); const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.goto(base + '/pagina.html'); await page.waitForTimeout(700);
  const est = () => page.evaluate(() => ({ ph: document.querySelectorAll('.midia-ph').length, f: !!document.getElementById('f'), com: !!document.getElementById('com'), mini: !!document.getElementById('mini'), opt: !!document.getElementById('opt'), tratou: (document.getElementById('com') && document.getElementById('com').dataset.tratou) || null, pend: window.__recarregarMidia.pendentes() }));
  let e = await est();
  eq('foto que falhou vira um quadro de recarregar (só a foto normal)', [e.ph, e.f, e.pend], [1, false, 1]);
  eq('quem tem tratamento próprio (onerror), é pequena ou pede para ficar de fora não é trocada', [e.com, e.mini, e.opt, e.tratou], [true, true, true, '1']);
  const ph = await page.evaluate(() => { const p = document.querySelector('.midia-ph'); const r = p.getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), classe: p.className, texto: p.textContent.trim(), raio: getComputedStyle(p).borderRadius }; });
  eq('o quadro mantém o tamanho e a classe da foto e diz o que fazer', [ph.w, ph.h, /grande/.test(ph.classe), ph.texto, ph.raio], [300, 200, true, '↻Toque para recarregar', '12px']);
  const antes = pedidos;
  await page.click('.midia-ph'); await page.waitForTimeout(600);
  e = await est();
  eq('tocar tenta de novo; com o servidor ainda fora, volta a mostrar o quadro', [pedidos > antes, e.ph], [true, 1]);
  ligado = true;
  await page.click('.midia-ph'); await page.waitForTimeout(600);
  e = await est();
  eq('servidor de volta: a foto carrega e o quadro some', [e.ph, e.f, await page.evaluate(() => document.getElementById('f').naturalWidth)], [0, true, 1]);
  eq('a nova tentativa usa um endereço com parâmetro novo (não pega o erro guardado)', await page.evaluate(() => /[?&]_r=\d+/.test(document.getElementById('f').getAttribute('src'))), true);
  ligado = false;
  await page.evaluate(() => { const i = document.createElement('img'); i.id = 'g'; i.src = '/foto.png?outra=1'; i.style.cssText = 'width:200px;height:120px'; document.body.appendChild(i); });
  await page.waitForTimeout(600);
  eq('outra foto que falha também vira quadro', (await est()).ph, 1);
  ligado = true;
  await page.evaluate(() => window.dispatchEvent(new Event('online'))); await page.waitForTimeout(1200);
  eq('quando a internet volta, o quadro pendente recarrega sozinho', [(await est()).ph, await page.evaluate(() => !!document.getElementById('g') && document.getElementById('g').naturalWidth)], [0, 1]);
  const cb = await page.evaluate(() => ['/a/b.png', '/a/b.png?x=1', '/a/b.png?x=1&_r=5', '/a/b.png?_r=5&y=2', '/a/b.png?_r=5', 'data:image/png;base64,AAA'].map(u => window.__recarregarMidia._comBusca(u)));
  eq('montagem do endereço novo', cb.map(u => u.replace(/_r=\d{10,}/, '_r=T')), ['/a/b.png?_r=T', '/a/b.png?x=1&_r=T', '/a/b.png?x=1&_r=T', '/a/b.png?y=2&_r=T', '/a/b.png?_r=T', 'data:image/png;base64,AAA']);
  eq('sem erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
