// Leitura de GPS do Cardio ao vivo (aluno.html, _cardioLiveOnPos): tremido do GPS parado não vira distância, salto impossível é recusado,
// parar no sinal fechado não vira "GPS cego", lacuna real é marcada e a bike aceita velocidade maior (24/11/2026).
//   cd testes && INTUS_DIR=../app/painel node cardio_gps.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': f.endsWith('.html') ? 'text/html; charset=utf-8' : f.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(8171, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.route(/cdn\.jsdelivr\.net|tiles\.openfreemap|openstreetmap|fonts\./, r => r.abort('failed'));
  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
    localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
  });
  await page.goto('http://127.0.0.1:8171/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _cardioLiveOnPos === 'function' && typeof renderHome === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
  const M = 1 / 111195;   // graus de latitude por metro
  // roda uma sequência de leituras: [{n: metros ao norte, dt: segundos desde o início, acc, ...}] e devolve o estado
  async function rodar(tipo, leituras, antes) {
    return page.evaluate(([tipo, leituras, antes, M]) => {
      _cardioLivePararTudo(); _cardioLivePontos = []; _cardioLiveDistM = 0; _cardioLiveLacunaS = 0; _cardioLiveNativoOk = false; _cardioLiveProxPausa = false;
      _cardioLiveTipo = tipo;
      const base = Date.now() - 110000;
      const lat0 = -25.5, lng0 = -54.58;
      leituras.forEach(l => {
        if (l.pausa) { _cardioLiveProxPausa = true; return; }
        _cardioLiveOnPos({ coords: { latitude: lat0 + l.n * M, longitude: lng0 + (l.l || 0) * M, accuracy: l.acc == null ? 10 : l.acc }, timestamp: base + l.dt * 1000 });
      });
      return { dist: Math.round(_cardioLiveDistM * 10) / 10, pontos: _cardioLivePontos.length, lacuna: Math.round(_cardioLiveLacunaS), trechos: _cardioLiveSegmentos().length };
    }, [tipo, leituras, antes || null, M]);
  }

  console.log('\n══ Tremido do GPS parado ══');
  const tremido = []; for (let i = 0; i < 40; i++) tremido.push({ n: Math.sin(i * 1.7) * 3, l: Math.cos(i * 2.3) * 3, dt: i, acc: 12 });
  let r = await rodar('Corrida', tremido);
  ver('40 leituras de uma pessoa parada (tremido de 3 m) somam menos de 6 m', r.dist < 6, r);
  eq('e não enchem a rota de pontos', r.pontos <= 3, true);

  console.log('\n══ Caminhada de verdade ══');
  const anda = []; for (let i = 0; i <= 60; i++) anda.push({ n: i * 1.4, dt: i, acc: 10 });   // 1,4 m/s por 60 s = 84 m
  r = await rodar('Caminhada', anda);
  ver('84 m andados são medidos entre 74 e 84 m (a âncora fica atrás do ruído, nunca passa do real)', r.dist >= 74 && r.dist <= 84.5, r);
  eq('sem lacuna e em um trecho só', [r.lacuna, r.trechos], [0, 1]);

  console.log('\n══ Salto impossível ══');
  const salto = anda.slice(0, 20).concat([{ n: 600, dt: 21, acc: 10 }], anda.slice(21, 40).map(l => ({ n: l.n, dt: l.dt + 1, acc: 10 })));
  r = await rodar('Corrida', salto);
  ver('um ponto 600 m longe em 1 s é recusado e a distância final bate com o caminho real (cerca de 55 m)', r.dist > 45 && r.dist < 60, r);

  console.log('\n══ Precisão ruim ══');
  r = await rodar('Corrida', [{ n: 0, dt: 0, acc: 80 }, { n: 20, dt: 5, acc: 90 }]);
  eq('leituras com precisão pior que 50 m são ignoradas', [r.dist, r.pontos], [0, 0]);

  console.log('\n══ Parado no sinal fechado ══');
  const sinal = []; for (let i = 0; i <= 10; i++) sinal.push({ n: i * 2.5, dt: i, acc: 8 });
  for (let i = 11; i <= 45; i++) sinal.push({ n: 25 + Math.sin(i) * 1.2, dt: i, acc: 8 });   // 35 s parado, com leitura chegando
  for (let i = 46; i <= 60; i++) sinal.push({ n: 25 + (i - 45) * 2.5, dt: i, acc: 8 });
  r = await rodar('Corrida', sinal);
  eq('parar 35 s com o GPS entregando leituras não vira lacuna de sinal', [r.lacuna, r.trechos], [0, 1]);
  ver('a distância continua somando antes e depois (cerca de 62 m)', r.dist > 52 && r.dist < 64, r);

  console.log('\n══ Lacuna real de sinal ══');
  const lacuna = []; for (let i = 0; i <= 10; i++) lacuna.push({ n: i * 2.5, dt: i, acc: 8 });
  for (let i = 0; i <= 10; i++) lacuna.push({ n: 120 + i * 2.5, dt: 40 + i, acc: 8 });   // 30 s sem nenhuma leitura
  r = await rodar('Corrida', lacuna);
  eq('30 s sem leitura abre um novo trecho e conta a lacuna', [r.lacuna >= 29 && r.lacuna <= 31, r.trechos], [true, 2]);
  ver('o salto da lacuna não entra na distância (só os dois trechos, cerca de 50 m)', r.dist > 40 && r.dist < 52, r);

  console.log('\n══ Pausar e retomar ══');
  const pausa = []; for (let i = 0; i <= 10; i++) pausa.push({ n: i * 2.5, dt: i, acc: 8 });
  pausa.push({ pausa: true }); for (let i = 0; i <= 10; i++) pausa.push({ n: 400 + i * 2.5, dt: 70 + i, acc: 8 });
  r = await rodar('Corrida', pausa);
  eq('depois de pausar, o primeiro ponto abre outro trecho sem somar o deslocamento', [r.trechos, r.lacuna], [2, 0]);
  ver('distância é só a dos dois trechos (cerca de 50 m)', r.dist > 40 && r.dist < 52, r);

  console.log('\n══ Bike ══');
  const bike = []; for (let i = 0; i <= 20; i++) bike.push({ n: i * 15, dt: i, acc: 8 });   // 15 m/s = 54 km/h em descida
  r = await rodar('Bike', bike);
  ver('na bike 15 m/s é aceito (300 m)', r.dist > 285 && r.dist < 301, r);
  r = await rodar('Corrida', bike);
  ver('correndo, 15 m/s é erro de GPS e não soma', r.dist < 40, r);

  eq('sem erro de script', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
