// Dobras cutâneas na ordem do protocolo tradicional (Jackson & Pollock), 26/11/2026: peitoral, axilar média, tricipital, subescapular, abdominal,
// suprailíaca e coxa. Antes apareciam na ordem em que o formulário as mandou (parecia sorteada) e a mulher que media tríceps e suprailíaca
// (3 dobras) via "peitoral" e "abdominal" no histórico.
//   cd testes && INTUS_DIR=../app/painel node dobras_ordem.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json', '.css': 'text/css' };
const TRADICIONAL = ['Peitoral', 'Axilar média', 'Tricipital', 'Subescapular', 'Abdominal', 'Suprailíaca', 'Coxa'];
const EMBARALHADO = { dc_coxa: 18, dc_subescapular: 14, dc_peitoral: 8, dc_abdominal: 20, dc_axilar: 9, dc_tricipital: 12, dc_suprailiaca: 16 };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();

  console.log('\n══ App do aluno ══');
  {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    const pedidos = [];
    await page.route(/\/app\/api\//, r => {
      const u = r.request().url(), m = r.request().method();
      if (/action=avaliacoes/.test(u) && m !== 'GET') { pedidos.push(JSON.parse(r.request().postData() || '{}')); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, idavaliacao: 9 }) }); }
      if (/action=avaliacoes/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idavaliacao: 1, idatleta: 7, dtavaliacao: '2026-11-20', peso: 70, percgordura: 20, metodo: 'Pollock 7 dobras', medidas: EMBARALHADO }]) });
      if (/action=atleta_existe/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: '{}' });   // o app só usa a API depois de o servidor responder
      return r.abort('failed');
    });
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-ajuda-v2', JSON.stringify({ visto: 1 })); });
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderAvaliacoesAluno === 'function' && typeof API !== 'undefined' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(500);
    eq('API.ordenarDobras: chaves embaralhadas saem na ordem tradicional e o que não é dobra conhecida vai para o fim', await page.evaluate(() => API.ordenarDobras(['dc_coxa', 'dc_x', 'dc_peitoral', 'dc_suprailiaca', 'dc_tricipital', 'dc_abdominal', 'dc_subescapular', 'dc_axilar'])), ['dc_peitoral', 'dc_axilar', 'dc_tricipital', 'dc_subescapular', 'dc_abdominal', 'dc_suprailiaca', 'dc_coxa', 'dc_x']);
    eq('API.ordenarDobras aceita pares [chave, valor]', await page.evaluate(() => API.ordenarDobras([['dc_coxa', 1], ['dc_peitoral', 2]]).map(e => e[0])), ['dc_peitoral', 'dc_coxa']);
    eq('_dobrasDe (usada no histórico, no resumo e no comparativo) devolve as dobras na ordem tradicional', await page.evaluate((m) => _dobrasDe(m).map(e => DOBRAS_LBL[e[0]]), EMBARALHADO), TRADICIONAL);
    await page.evaluate(() => { _avaliacoesCache = []; });
    await page.evaluate(() => navegar('avaliacoes')); await page.waitForTimeout(900);
    await page.evaluate(() => abrirVerAvaliacoes()); await page.waitForTimeout(300);
    eq('o Histórico de Avaliações mostra as dobras na ordem tradicional e a soma', await page.evaluate(() => { const g = Array.from(document.querySelectorAll('#v-avaliacoes div')).find(d => /DOBRAS CUT/.test(d.textContent) && d.children.length === 2); const cx = Array.from(document.querySelectorAll('#v-avaliacoes [style*="auto-fit"] > div')).map(d => d.children[1].textContent.trim()); return [cx, /soma 97,0 mm/.test(document.getElementById('v-avaliacoes').textContent)]; }), [TRADICIONAL.map(x => x === 'Axilar média' ? 'Axilar média' : x), true]);

    // formulário: sexo vem do cadastro e as 3 dobras mudam com ele
    await page.evaluate(() => abrirFormAutoAvaliacao()); await page.waitForTimeout(300);
    eq('o sexo já vem marcado pelo cadastro do aluno (F)', await page.evaluate(() => document.getElementById('av-sexo').value), 'F');
    await page.evaluate(() => { document.getElementById('av-metodo').value = 'dobras3'; mudarMetodoComposicao(); });
    eq('3 dobras, mulher: tríceps, suprailíaca e coxa', await page.evaluate(() => ['lbl-dc1', 'lbl-dc2', 'lbl-dc3'].map(i => document.getElementById(i).textContent)), ['Tríceps', 'Suprailíaca', 'Coxa']);
    await page.evaluate(() => { document.getElementById('av-sexo').value = 'M'; document.getElementById('av-sexo').dispatchEvent(new Event('change')); });
    eq('trocar o sexo no formulário muda os rótulos na hora (antes só mudava ao trocar de método)', await page.evaluate(() => ['lbl-dc1', 'lbl-dc2', 'lbl-dc3'].map(i => document.getElementById(i).textContent)), ['Peitoral', 'Abdominal', 'Coxa']);
    await page.evaluate(() => { document.getElementById('av-sexo').value = 'F'; document.getElementById('av-sexo').dispatchEvent(new Event('change')); document.getElementById('av-peso').value = '60'; document.getElementById('av-dc1').value = '12'; document.getElementById('av-dc2').value = '15'; document.getElementById('av-dc3').value = '20'; });
    await page.evaluate(() => salvarAutoAvaliacao()); await page.waitForTimeout(500);
    eq('3 dobras, mulher: grava tricipital, suprailíaca e coxa (antes gravava peitoral e abdominal)', Object.keys(pedidos[0].medidas), ['dc_tricipital', 'dc_suprailiaca', 'dc_coxa']);
    await page.evaluate(() => abrirFormAutoAvaliacao()); await page.waitForTimeout(300);
    await page.evaluate(() => { document.getElementById('av-sexo').value = 'M'; document.getElementById('av-metodo').value = 'dobras3'; mudarMetodoComposicao(); document.getElementById('av-peso').value = '80'; document.getElementById('av-dc1').value = '10'; document.getElementById('av-dc2').value = '18'; document.getElementById('av-dc3').value = '16'; });
    await page.evaluate(() => salvarAutoAvaliacao()); await page.waitForTimeout(500);
    eq('3 dobras, homem: grava peitoral, abdominal e coxa', Object.keys(pedidos[1].medidas), ['dc_peitoral', 'dc_abdominal', 'dc_coxa']);
    await page.evaluate(() => abrirFormAutoAvaliacao()); await page.waitForTimeout(300);
    eq('7 dobras: os campos seguem a ordem tradicional', await page.evaluate(() => { document.getElementById('av-metodo').value = 'dobras7'; mudarMetodoComposicao(); return Array.from(document.querySelectorAll('#av-metodo-campos label')).map(l => l.textContent); }), ['Peitoral', 'Axilar Média', 'Tríceps', 'Subescapular', 'Abdominal', 'Suprailíaca', 'Coxa']);
    eq('sem erro de JavaScript', erros, []);
    await ctx.close();
  }

  console.log('\n══ Painel do professor (Nova avaliação) ══');
  {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.fulfill({ status: 200, contentType: 'application/json', body: '[]' }));
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); });
    await page.goto(base + '/avaliacoes.html', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof renderDobrasFields === 'function', null, { timeout: 15000 });
    const rotulos = (metodo) => page.evaluate((m) => { const d = document.createElement('div'); d.innerHTML = renderDobrasFields(m, {}); return { campos: Array.from(d.querySelectorAll('label.label')).map(l => l.textContent.replace(' (mm)', '')), info: d.querySelector('.dobras-info').textContent }; }, metodo);
    const r7 = await rotulos('pollock7');
    eq('Pollock 7 dobras: os campos na ordem tradicional', r7.campos, ['Peitoral', 'Axilar Média', 'Tricipital', 'Subescapular', 'Abdominal', 'Suprailíaca', 'Coxa']);
    eq('Pollock 7 dobras: a explicação lista na mesma ordem', r7.info, 'Pollock 7 dobras: Peitoral, Axilar média, Tricipital, Subescapular, Abdominal, Suprailíaca, Coxa');
    eq('Pollock 5 dobras: campos na ordem tradicional (união de homem e mulher)', (await rotulos('pollock5')).campos, ['Peitoral', 'Tricipital', 'Subescapular', 'Abdominal', 'Suprailíaca', 'Coxa']);
    eq('Pollock 3 dobras: campos na ordem tradicional', (await rotulos('pollock3')).campos, ['Peitoral', 'Tricipital', 'Abdominal', 'Suprailíaca', 'Coxa']);
    eq('dobrasDe do painel (resumo, histórico e detalhe) também sai na ordem tradicional', await page.evaluate((m) => dobrasDe(m).map(e => DOBRAS_LABELS[e[0]]), EMBARALHADO), ['Peitoral', 'Axilar Média', 'Tricipital', 'Subescapular', 'Abdominal', 'Suprailíaca', 'Coxa']);
    eq('sem erro de JavaScript', erros, []);
    await ctx.close();
  }

  console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
  await b.close(); srv.close();
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
