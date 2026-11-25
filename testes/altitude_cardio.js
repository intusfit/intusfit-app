// Altitude e relevo do cardio (25/11/2026): ganho e descida sem tremido, leitura do relevo (Terrarium) e exibição no cardio ao vivo.
//   cd testes && INTUS_DIR=../app/painel node altitude_cardio.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json' };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  let tiles = 0;
  // Tile de relevo sintético: altitude = 800 m + 2 m por linha de pixel (de cima para baixo), no codificador Terrarium.
  let png = null;
  await page.route(/elevation-tiles-prod/, r => { tiles++; if (!png) return r.abort('failed'); r.fulfill({ status: 200, contentType: 'image/png', headers: { 'access-control-allow-origin': '*' }, body: png }); });
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.route(/openfreemap|unpkg|cdnjs|jsdelivr/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => !!window.AltitudeCardio && typeof _clElevHtml === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
  const b64 = await page.evaluate(() => { const c = document.createElement('canvas'); c.width = 256; c.height = 256; const g = c.getContext('2d'); const d = g.createImageData(256, 256); for (let y = 0; y < 256; y++) for (let x = 0; x < 256; x++) { const v = 800 + 2 * y + 32768, i = (y * 256 + x) * 4; d.data[i] = Math.floor(v / 256); d.data[i + 1] = Math.floor(v) % 256; d.data[i + 2] = Math.floor((v - Math.floor(v)) * 256); d.data[i + 3] = 255; } g.putImageData(d, 0, 0); return c.toDataURL('image/png').split(',')[1]; });
  png = Buffer.from(b64, 'base64');

  console.log('\n══ Contas ══');
  const R = (alts, extra) => page.evaluate(([a, e]) => { let lat = -26.9; return AltitudeCardio.resumo(a.map((v, i) => Object.assign({ lat: lat + i * 0.0002, lng: -49.07, alt: v }, e && e[i] || {}))); }, [alts, extra]);
  let r = await R([100, 100, 100, 100, 100]);
  eq('terreno plano: nada de subida nem descida', [r.ganho, r.perda, r.atual], [0, 0, 100]);
  r = await R([100, 104, 108, 112, 116, 120, 124, 128, 132, 136, 140]);
  eq('subida constante de 40 m: ganho perto de 40 m e nenhuma descida', [r.ganho >= 34 && r.ganho <= 42, r.perda, r.min < r.max], [true, 0, true]);
  r = await R([140, 136, 132, 128, 124, 120, 116, 112, 108, 104, 100]);
  eq('descida de 40 m: descida perto de 40 m e nenhum ganho', [r.perda >= 34 && r.perda <= 42, r.ganho], [true, 0]);
  r = await R([100, 101, 100, 101, 100, 101, 100, 101, 100, 101, 100, 101]);
  eq('tremido de 1 m não vira subida (folga)', [r.ganho, r.perda], [0, 0]);
  const rampa = (a, b, n) => Array.from({ length: n }, (_, i) => a + (b - a) * i / (n - 1));
  r = await R([].concat(rampa(100, 150, 26), rampa(150, 100, 26).slice(1), rampa(100, 160, 31).slice(1)));
  eq('sobe 50, desce 50 e sobe 60 (uma volta de verdade): ganho e descida até 10% abaixo do real (o suavizado corta um pouco nos picos, de propósito)', [r.ganho >= 99 && r.ganho <= 110, r.perda >= 45 - 2 && r.perda <= 50, r.min, r.max], [true, true, 100, 160]);
  eq('sem altitude nenhuma não há resumo', await page.evaluate(() => AltitudeCardio.resumo([{ lat: 1, lng: 1 }, { lat: 1.001, lng: 1 }])), null);
  r = await R([100, 105, 110, 115]);
  eq('o perfil traz distância crescente e termina na altitude suavizada', [r.perfil.length, r.perfil[0].d, r.perfil[r.perfil.length - 1].d > 0], [4, 0, true]);

  console.log('\n══ Relevo (tile Terrarium) ══');
  const t = await page.evaluate(() => AltitudeCardio._teste.tileDe(-26.9, -49.07));
  eq('o ponto cai num tile do nível 13', [t.x > 0 && t.y > 0, t.fx >= 0 && t.fx < 1], [true, true]);
  const dem = await page.evaluate(async () => { const a = await AltitudeCardio.demAltitude(-26.9, -49.07); return a; });
  eq('lê a altitude do relevo e interpola entre os pixels (800 m + 2 m por linha)', dem !== null && dem >= 800 && dem <= 1312, true);
  const antes = tiles;
  await page.evaluate(async () => { await AltitudeCardio.demAltitude(-26.9001, -49.0701); });
  eq('o mesmo tile é reaproveitado (não baixa de novo a cada ponto)', tiles, antes);
  const pt = await page.evaluate(async () => { const p = { lat: -26.9, lng: -49.07 }; let feito = false; AltitudeCardio.amostrar(p, () => { feito = true; }); await new Promise(r => setTimeout(r, 400)); return { alt: p.alt, fonte: p.altFonte, feito }; });
  eq('amostrar grava a altitude e a fonte no ponto e avisa quando termina', [typeof pt.alt, pt.fonte, pt.feito], ['number', 'relevo', true]);
  png = null; // relevo fora do ar
  const fora = await page.evaluate(async () => AltitudeCardio.demAltitude(10, 10));
  eq('relevo indisponível: devolve nulo sem quebrar', fora, null);
  eq('altitude do GPS só vale se for um número plausível', await page.evaluate(() => [AltitudeCardio.altGps({ altitude: 812.4 }), AltitudeCardio.altGps({ altitude: null }), AltitudeCardio.altGps({ altitude: 99999 }), AltitudeCardio.altGps(null)]), [812.4, null, null, null]);

  console.log('\n══ Na tela do cardio ══');
  const html = await page.evaluate(() => { const pts = [100, 104, 108, 112, 116, 120, 124, 128].map((a, i) => ({ lat: -26.9 + i * 0.0003, lng: -49.07, alt: a })); return _clElevHtml(pts, true); });
  eq('mostra altitude atual, subida, descida e o gráfico do perfil', [/Altitude/.test(html), /▲ \d+ m/.test(html), /▼ \d+ m/.test(html), /<svg/.test(html)], [true, true, true, true]);
  eq('sem altitude nada aparece', await page.evaluate(() => _clElevHtml([{ lat: 1, lng: 1 }, { lat: 1.1, lng: 1 }], true)), '');
  const reg = await page.evaluate(async () => {
    // um ponto de GPS aceito grava a altitude do GPS na hora e a do relevo depois
    _cardioLiveTipo = 'Corrida'; _cardioLivePontos = []; _cardioLiveRodando = true;
    _cardioLiveOnPos({ coords: { latitude: -26.9, longitude: -49.07, accuracy: 5, altitude: 777 }, timestamp: Date.now() });
    const p = _cardioLivePontos[0]; const antes = [p.alt, p.altFonte];
    return { antes, tem: !!p };
  });
  eq('o ponto da rota já nasce com a altitude do GPS', reg.antes, [777, 'gps']);
  eq('sem erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
