// Tela de esforço do cardio ao vivo: campo de distância quando o GPS não mediu ou só estimou (20/11/2026).
//   cd testes && INTUS_DIR=../app/painel node cardio_distancia.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': f.endsWith('.js') ? 'text/javascript; charset=utf-8' : f.endsWith('.html') ? 'text/html; charset=utf-8' : 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8162, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  let puts = [];
  await page.route(/\/app\/api\/treinos\.php\?action=cardio&id=/, r => { if (r.request().method() === 'PUT') { puts.push(JSON.parse(r.request().postData() || '{}')); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"pontos":5}' }); } return r.abort('failed'); });
  await page.route(/\/app\/api\/(?!treinos\.php\?action=cardio&id=)/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto('http://127.0.0.1:8162/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(600);
  const abrir = async (d) => { await page.evaluate(x => { document.getElementById('cardio-esforco-overlay')?.remove(); const o = document.getElementById('cardio-result-overlay'); if (o) o.remove(); _renderTelaEsforcoCardioLive(Object.assign({ idsessao: 99, tipo: 'Corrida', min: 61, distKm: 0, pontos: [], motivo: null }, x)); }, d); await page.waitForTimeout(300); };
  const campo = () => page.evaluate(() => { const i = document.getElementById('cle-dist'); return i ? { v: i.value, un: i.nextElementSibling.textContent, ph: i.placeholder } : null; });

  console.log('\n══ Quando o campo de distância aparece ══');
  await abrir({ tipo: 'Corrida', distKm: 0, distDescartada: true });
  eq('GPS não mediu o suficiente (tela apagou): campo vazio em km', await campo(), { v: '', un: 'km', ph: 'ex: 5' });
  eq('o aviso manda informar no campo abaixo', await page.evaluate(() => /informe no campo abaixo/.test(document.getElementById('cardio-esforco-overlay').innerText)), true);
  await abrir({ tipo: 'Caminhada', distKm: 3.2 });
  eq('distância medida pelo GPS: sem campo (não troca o que o GPS mediu)', await campo(), null);
  await abrir({ tipo: 'Corrida', distKm: 4.1, distEstimada: true });
  eq('distância só estimada: campo já preenchido para corrigir', await campo(), { v: '4.10', un: 'km', ph: 'ex: 5' });
  await abrir({ tipo: 'Natação', distKm: 0 });
  eq('natação: campo em metros', await campo(), { v: '', un: 'm', ph: 'ex: 1000' });
  await abrir({ tipo: 'Bike', distKm: 0, parado: true });
  eq('em um aparelho (esteira, bike ergométrica): campo com a dica do aparelho', [!!(await campo()), await page.evaluate(() => /aparelho mostrou/.test(document.getElementById('cardio-esforco-overlay').innerText))], [true, true]);
  await abrir({ tipo: 'Escada', distKm: 0 });
  eq('escada (degraus): sem campo de distância', await campo(), null);
  await abrir({ tipo: 'Futebol', distKm: 0 });
  eq('atividade sem distância (futebol): sem campo', await campo(), null);

  console.log('\n══ Salvar a distância informada ══');
  puts = [];
  await abrir({ tipo: 'Corrida', distKm: 0, distDescartada: true });
  await page.fill('#cle-dist', '5,2');
  await page.evaluate(() => _confirmarEsforcoCardioLive()); await page.waitForTimeout(900);
  eq('Concluir grava o registro com a distância no texto (5,2 vira 5.2 km)', [puts.length, /Corrida · esforço 5\/10 · 61min · 5\.2 km/.test((puts[0] || {}).comentario || '')], [1, true]);
  eq('a tela de esforço fechou', await page.evaluate(() => !document.getElementById('cardio-esforco-overlay')), true);
  puts = [];
  await abrir({ tipo: 'Natação', distKm: 0 });
  await page.fill('#cle-dist', '1000');
  await page.evaluate(() => _confirmarEsforcoCardioLive()); await page.waitForTimeout(900);
  eq('natação grava em metros', /61min · 1000 m/.test((puts[0] || {}).comentario || ''), true);
  puts = [];
  await abrir({ tipo: 'Corrida', distKm: 4.1, distEstimada: true });
  await page.fill('#cle-dist', '5');
  await page.evaluate(() => _confirmarEsforcoCardioLive()); await page.waitForTimeout(900);
  eq('corrigir a estimativa grava o valor novo, não o estimado', /61min · 5 km/.test((puts[0] || {}).comentario || ''), true);
  puts = [];
  await abrir({ tipo: 'Corrida', distKm: 0, distDescartada: true });
  await page.evaluate(() => _confirmarEsforcoCardioLive()); await page.waitForTimeout(700);
  eq('campo vazio: nada a gravar (esforço padrão, sem foto, sem nota), como sempre', puts.length, 0);

  console.log('\n══ Valores com erro não fecham a tela ══');
  puts = [];
  await abrir({ tipo: 'Caminhada', distKm: 0, min: 61 });
  await page.fill('#cle-dist', '80');
  await page.evaluate(() => _confirmarEsforcoCardioLive()); await page.waitForTimeout(500);
  eq('ritmo impossível (80 km andando em 61 min): a tela continua e nada é gravado', [await page.evaluate(() => !!document.getElementById('cardio-esforco-overlay')), puts.length], [true, 0]);
  await page.fill('#cle-dist', 'abc');
  await page.evaluate(() => _confirmarEsforcoCardioLive()); await page.waitForTimeout(400);
  eq('texto que não é número: a tela continua', await page.evaluate(() => !!document.getElementById('cardio-esforco-overlay')), true);
  await page.fill('#cle-dist', '3,5');
  await page.evaluate(() => _confirmarEsforcoCardioLive()); await page.waitForTimeout(900);
  eq('corrigindo o valor, grava', /3\.5 km/.test((puts[0] || {}).comentario || ''), true);
  eq('nenhum erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
