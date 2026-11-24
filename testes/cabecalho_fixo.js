// Barra superior e cabeçalho das telas ficam presos no topo durante TODA a rolagem (aluno.html, 24/11/2026).
// Bug: com body em height:100%, a barra (.top) subia depois da primeira tela de rolagem e o cabeçalho (.tela-header-fixa) ficava flutuando a 66px do topo.
//   cd testes && INTUS_DIR=../app/painel node cabecalho_fixo.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': f.endsWith('.html') ? 'text/html; charset=utf-8' : f.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(8181, '127.0.0.1', r));
  const b = await chromium.launch();
  for (const tema of ['escuro', 'claro']) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.route(/cdn\.jsdelivr\.net|tiles\.openfreemap|openstreetmap|fonts\./, r => r.abort('failed'));
    await page.addInitScript(t => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
      localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
      if (t === 'claro') localStorage.setItem('intus-tema', 'claro');
    }, tema);
    await page.goto('http://127.0.0.1:8181/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderCardio === 'function' && typeof trocarView === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(1200);
    console.log('\n══ Rolagem funda, tema ' + tema + ' ══');
    await page.evaluate(() => {
      renderCardio(); trocarView('cardio');
      const v = document.getElementById('v-cardio');
      const alto = document.createElement('div'); alto.id = 'enchimento'; alto.style.cssText = 'height:6000px;background:linear-gradient(#222,#444);'; v.appendChild(alto);
    });
    await page.waitForTimeout(400);
    const medir = () => page.evaluate(() => {
      const top = document.querySelector('.top').getBoundingClientRect(), cab = document.querySelector('#v-cardio .tela-header-fixa');
      const c = cab && cab.getBoundingClientRect();
      return { topo: Math.round(top.top), cabTop: c ? Math.round(c.top) : null, topoBaixo: Math.round(top.bottom), scroll: Math.round(window.scrollY) };
    });
    for (const y of [0, 500, 1500, 3500, 5500]) {
      await page.evaluate(yy => window.scrollTo(0, yy), y); await page.waitForTimeout(250);
      const m = await medir();
      eq('rolado ' + y + 'px: a barra superior continua no topo', m.topo, 0);
      if (m.cabTop !== null) eq('rolado ' + y + 'px: o cabeçalho da tela fica colado embaixo da barra (sem vão)', Math.abs(m.cabTop - m.topoBaixo) <= 8, true);
    }
    eq('sem erro de script (' + tema + ')', erros, []);
    await ctx.close();
  }
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
