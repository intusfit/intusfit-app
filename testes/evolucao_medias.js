// Evolução do aluno: média de duração por musculação e por cardio (25/11/2026), mesma conta do painel.
//   cd testes && INTUS_DIR=../app/painel node evolucao_medias.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json' };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' }); const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderEvolucaoAluno === 'function' && typeof IntusEvolucao !== 'undefined').catch(() => false)) break; await page.waitForTimeout(250); }
  const r = await page.evaluate(() => {
    const d = (n) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10);
    const s = [
      { dtsessao: d(1), duracao_seg: 3600 }, { dtsessao: d(2), duracao_seg: 3900 }, { dtsessao: d(3), duracao_seg: 3600 + 360 },   // musculação: 60, 65, 66 min
      { dtsessao: d(4), duracao_seg: 1800, tipo: 'cardio' }, { dtsessao: d(5), duracao_seg: 1200, cardio_tipo: 'Corrida' },          // cardio: 30 e 20 min
      { dtsessao: d(6), duracao_seg: 9000, nao_contar: 1 },                                                                          // fora das médias
    ];
    const box = document.createElement('div'); box.innerHTML = renderEvolucaoAluno(s, false);
    const t = (id) => { const e = box.querySelector('#' + id); return e ? e.textContent.replace(/\s+/g, ' ').trim() : null; };
    const vazio = document.createElement('div'); vazio.innerHTML = renderEvolucaoAluno([{ dtsessao: d(1), duracao_seg: 2700 }], false);
    const so = vazio.querySelector('#evo-media-car');
    return { mus: t('evo-media-mus'), car: t('evo-media-car'), semCardio: so && so.textContent.replace(/\s+/g, ' ').trim() };
  });
  eq('musculação: média de 3 sessões (60, 65 e 66 min), sem a sessão marcada como "não conta"', /^64 MÉDIA POR MUSCULAÇÃO \(MIN\) em 3 sessões$/i.test(r.mus), true);
  eq('cardio: média de 2 sessões (30 e 20 min)', /^25 MÉDIA POR CARDIO \(MIN\) em 2 sessões$/i.test(r.car), true);
  eq('sem cardio registrado: mostra "–" e "em 0 sessões"', /^– MÉDIA POR CARDIO \(MIN\) em 0 sessões$/i.test(r.semCardio), true);
  eq('sem erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
