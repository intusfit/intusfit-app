// Linha de série (treino): a caixa da meta de repetições é compacta (58px; 54px no celular) e a meta cabe em UMA linha, qualquer que seja o texto
// ("12", "8-10", "10-12", "10 a 12"). Antes "8-10" (4 caracteres) ficava em 19px, estourava a caixa e o "10" caía para a linha de baixo no iPhone.
// Usa o CSS e a função _repClasse reais do aluno.html.
//   cd testes && INTUS_DIR=../app/painel node serie_linha.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const [largura, colEsperada] of [[402, 54], [375, 54], [360, 54], [800, 58]]) {
    const ctx = await b.newContext({ viewport: { width: largura, height: 800 }, serviceWorkers: 'block', hasTouch: largura < 500, isMobile: largura < 500 });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _repClasse === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    const r = await page.evaluate(() => {
      const caixa = document.createElement('div'); caixa.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#111;padding:10px;overflow:auto';
      const metas = ['12', '8-10', '10-12', '10 a 12', 'Até falha', '15'];
      caixa.innerHTML = metas.map(t => '<div class="serie-row current"><div class="serie-cel"><div class="lbl">S1</div><div class="rep-hint' + _repClasse(t) + '">' + t + '</div><div class="rep-un">reps</div></div><input type="number" placeholder="kg"><input type="number" placeholder="' + t + '"><button class="check-btn">x</button></div>').join('');
      document.body.appendChild(caixa);
      const medir = (el) => { const h = el.querySelector('.rep-hint'); const rg = document.createRange(); rg.selectNodeContents(h); const linhas = new Set(Array.from(rg.getClientRects()).map(x => Math.round(x.top))).size; const cel = el.querySelector('.serie-cel'); return { texto: h.textContent, classe: h.className, fonte: parseFloat(getComputedStyle(h).fontSize), linhas, cabe: h.scrollWidth <= cel.clientWidth + 1, altura: Math.round(el.getBoundingClientRect().height) }; };
      const linhas = Array.from(caixa.querySelectorAll('.serie-row'));
      return { metas: linhas.map(medir), col: getComputedStyle(linhas[0]).gridTemplateColumns.split(' ')[0], classes: ['', '1', '12', '8-10', '10-12', '10 a 12'].map(t => _repClasse(t)) };
    });
    console.log('\n══ Linha de série em ' + largura + ' px ══');
    eq('a caixa da meta mantém a largura do desenho (' + colEsperada + ' px)', r.col, colEsperada + 'px');
    eq('o degrau de fonte vem do tamanho do texto (1 a 3 normal, 4 médio, 5 ou mais longo)', r.classes, ['', '', '', ' medio', ' longo', ' longo']);
    const numericas = r.metas.filter(m => m.texto !== 'Até falha'), texto = r.metas.find(m => m.texto === 'Até falha');
    for (const m of numericas) eq('"' + m.texto + '" fica em uma linha, dentro da caixa (fonte ' + m.fonte + 'px)', [m.linhas, m.cabe], [1, true]);
    eq('meta em palavras ("Até falha") pode ocupar até 2 linhas, como sempre, sem estourar a caixa', [texto.linhas <= 2, texto.cabe], [true, true]);
    eq('nenhuma linha de série com meta numérica passa de 80 px de altura (quebrada passava de 90)', numericas.every(m => m.altura <= 80), true);
    eq('o "8-10" usa a fonte média, menor que a de "12" e maior que a de "10 a 12"', [r.metas[1].fonte < r.metas[0].fonte, r.metas[1].fonte > r.metas[3].fonte], [true, true]);
    eq('sem erro de JavaScript', erros, []);
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
