// App reaberto com um cardio ao vivo em andamento (página descartada em segundo plano ou tela bloqueada): volta para a TELA do cardio, não para a Início (20/11/2026).
//   cd testes && INTUS_DIR=../app/painel node cardio_retomar.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': f.endsWith('.js') ? 'text/javascript; charset=utf-8' : f.endsWith('.html') ? 'text/html; charset=utf-8' : 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8161, '127.0.0.1', r));
  const b = await chromium.launch();
  async function abrir(rodando, extra) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', permissions: ['geolocation'], geolocation: { latitude: -25.5, longitude: -54.58 } });
    const page = await ctx.newPage(); page.erros = []; page.on('pageerror', e => page.erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(([rod, ex]) => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
      localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
      const agora = Date.now();
      localStorage.setItem('intus-cardio-live-ativo', JSON.stringify({ tipo: 'Corrida', distM: 2100, lacunaS: 0, inicioMs: agora - 25 * 60000, msPausado: 0, pausadoDesde: rod ? 0 : agora - 60000, rodando: rod, salvoEm: agora - 20 * 60000 }));
      if (ex) ex();
    }, [rodando, null]);
    await page.goto('http://127.0.0.1:8161/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('v-home') && document.getElementById('v-home').innerHTML.length > 500).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(1500);
    return { page, ctx };
  }
  const est = page => page.evaluate(() => ({ v: window._viewAtual || 'home', rastreio: !!_cardioLiveInicioMs, pill: getComputedStyle(document.getElementById('cardio-live-pill')).display, tela: !!document.getElementById('v-cardio-live') && document.getElementById('v-cardio-live').innerText.length > 20 }));
  console.log('\n══ App reaberto com cardio ao vivo em andamento ══');
  let { page, ctx } = await abrir(false); let e = await est(page);
  eq('cardio pausado: abre na tela do cardio, com o rastreio de volta', [e.v, e.rastreio, e.tela], ['cardio-live', true, true]);
  eq('na tela do cardio a pílula some', e.pill, 'none');
  await page.goBack(); await page.waitForTimeout(600); e = await est(page);
  eq('voltar do celular leva ao início e a pílula aparece (o rastreio segue)', [e.v, e.rastreio, e.pill], ['home', true, 'flex']);
  await ctx.close();
  ({ page, ctx } = await abrir(true)); e = await est(page);
  eq('cardio rodando: abre na tela do cardio', [e.v, e.rastreio], ['cardio-live', true]);
  eq('sem erro de script', page.erros, []);
  await ctx.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
