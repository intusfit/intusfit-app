// Figurinha de imagem no fim do treino no app das lojas (24/11/2026): o servidor guarda o caminho RELATIVO (/app/img/uploads/...). Dentro do app a página é
// capacitor://localhost, então esse caminho não existia e a figurinha sumia. No app o endereço tem de ser o do site; na web nada muda.
//   cd testes && INTUS_DIR=../app/painel node figurinha_app.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const SITE = 'https://intusfit.com.br';
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': f.endsWith('.html') ? 'text/html; charset=utf-8' : f.endsWith('.js') ? 'text/javascript; charset=utf-8' : 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  async function abrir(nativo) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript((n) => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
      if (n) { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://intusfit.com.br/app/api'; }
    }, nativo);
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof figurinhaAleatoriaHtml === 'function' && typeof _urlAbs === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    return { ctx, page, erros };
  }
  const medir = (page) => page.evaluate(() => {
    _figurinhasConfig = { itens: [{ tipo: 'img', src: '/app/img/uploads/fig-abc.png', legenda: 'Bora!' }] };
    const d = document.createElement('div'); d.innerHTML = figurinhaAleatoriaHtml(false);
    const img = d.querySelector('img');
    return { fim: img && img.getAttribute('src') };
  });
  console.log('\n══ Figurinha de imagem ══');
  let t = await abrir(true);
  eq('app das lojas: a figurinha do fim do treino usa o endereço do site', (await medir(t.page)).fim, SITE + '/app/img/uploads/fig-abc.png');
  eq('app das lojas: já com endereço completo não é mexido', await t.page.evaluate(() => { _figurinhasConfig = { itens: [{ tipo: 'img', src: 'https://intusfit.com.br/app/img/uploads/z.png' }] }; const d = document.createElement('div'); d.innerHTML = figurinhaAleatoriaHtml(false); return d.querySelector('img').getAttribute('src'); }), SITE + '/app/img/uploads/z.png');
  eq('sem erro de JavaScript (app)', t.erros, []); await t.ctx.close();
  t = await abrir(false);
  eq('web: o caminho continua relativo', (await medir(t.page)).fim, '/app/img/uploads/fig-abc.png');
  eq('sem erro de JavaScript (web)', t.erros, []); await t.ctx.close();
  console.log('\n══ Lista de figurinhas ainda não lida quando o treino acaba ══');
  t = await abrir(true);
  const tarde = await t.page.evaluate(async () => {
    _figurinhasConfig = null; try { localStorage.removeItem('intus-fig-cfg'); } catch (e) {}
    window._fetchConfigDireto = function () { return new Promise(function (r) { setTimeout(function () { r({ itens: [{ tipo: 'img', src: '/app/img/uploads/tarde.png' }] }); }, 150); }); };
    sessaoAtual = { idficha: 1, divisao: 'A', divisaoLabel: 'Treino A', nomeFicha: 'Ficha', exercicios: [], alongamentos: [], dados: {}, inicio: Date.now() - 600000, comentario: '', activeIdx: 0 };
    renderFim(600, { idsessao: 991, divisao: 'A', itens: [] }); trocarView('fim');
    const antes = document.getElementById('fim-fig').innerHTML.trim();
    await new Promise(r => setTimeout(r, 800));
    const img = document.querySelector('#fim-fig img');
    return { vazioAntes: antes === '', depois: img && img.getAttribute('src') };
  });
  eq('sem lista lida: a tela do fim começa sem figurinha e a preenche quando a lista chega', tarde, { vazioAntes: true, depois: SITE + '/app/img/uploads/tarde.png' });
  eq('sem erro de JavaScript (fim)', t.erros, []); await t.ctx.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
