// Voltar do celular com perfil/post/folha aberta: fecha só a camada de cima, fica na tela de trás (20/11/2026).
//   cd testes && INTUS_DIR=../app/painel node voltar_folha.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': f.endsWith('.js') ? 'text/javascript; charset=utf-8' : f.endsWith('.html') ? 'text/html; charset=utf-8' : 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8119, '127.0.0.1', r));
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto('http://127.0.0.1:' + 8119 + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(600);
  const est = () => page.evaluate(() => ({ view: window._viewAtual, perfil: !!document.getElementById('overlay-perfil-rk'), n: _pkHistN }));
  console.log('\n══ Voltar do celular com o perfil de um aluno aberto ══');
  await page.evaluate(() => abrirRanking()); await page.waitForTimeout(1500);
  eq('ranking aberto', (await est()).view, 'ranking');
  await page.evaluate(() => abrirPerfilRanking(9)); await page.waitForTimeout(800);
  eq('perfil abre e conta 1 entrada de histórico', [(await est()).perfil, (await est()).n], [true, 1]);
  await page.evaluate(() => history.back()); await page.waitForTimeout(700);
  eq('voltar fecha só o perfil e continua no ranking', await est(), { view: 'ranking', perfil: false, n: 0 });
  await page.evaluate(() => history.back()); await page.waitForTimeout(700);
  eq('o voltar seguinte segue o fluxo normal (início)', (await est()).view, 'home');
  await page.evaluate(() => abrirRanking()); await page.waitForTimeout(1200);
  await page.evaluate(() => abrirPerfilRanking(9)); await page.waitForTimeout(800);
  await page.evaluate(() => document.getElementById('overlay-perfil-rk').remove()); await page.waitForTimeout(700);
  eq('fechar pelo X desfaz a entrada sobrando', await est(), { view: 'ranking', perfil: false, n: 0 });
  await page.evaluate(() => history.back()); await page.waitForTimeout(700);
  eq('voltar depois do X vai ao início, sem passo morto', (await est()).view, 'home');
  eq('nenhum erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
