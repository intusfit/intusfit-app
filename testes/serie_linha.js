// Linha de série (treino): a meta de repetições "8-10" não pode quebrar em duas linhas (no iPhone o "10" caía para baixo e a linha ficava bem mais alta).
// Usa o CSS real do aluno.html e simula uma fonte 15% maior que a prevista, com margem de segurança.
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
  for (const largura of [402, 375, 360]) {
    const ctx = await b.newContext({ viewport: { width: largura, height: 800 }, serviceWorkers: 'block', hasTouch: true, isMobile: true });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(1500);
    const r = await page.evaluate(() => {
      const caixa = document.createElement('div'); caixa.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#111;padding:10px;overflow:auto';
      const linha = (txt, cls) => '<div class="serie-row current"><div class="serie-cel"><div class="lbl">S1</div><div class="rep-hint' + (cls || '') + '">' + txt + '</div><div class="rep-un">reps</div></div><input type="number" placeholder="kg"><input type="number" placeholder="' + txt + '"><button class="check-btn">x</button></div>';
      caixa.innerHTML = linha('8-10') + linha('12') + linha('10-12', ' longo') + linha('8-10');
      document.body.appendChild(caixa);
      const medir = (el, forcar) => { const h = el.querySelector('.rep-hint'); if (forcar) h.style.fontSize = forcar; const rg = document.createRange(); rg.selectNodeContents(h); const linhas = new Set(Array.from(rg.getClientRects()).map(x => Math.round(x.top))).size; const cel = el.querySelector('.serie-cel'); return { linhas, altura: Math.round(el.getBoundingClientRect().height), cabe: h.scrollWidth <= cel.clientWidth + 1 }; };
      const linhas = Array.from(caixa.querySelectorAll('.serie-row'));
      const normal = medir(linhas[0]);
      const cols = getComputedStyle(linhas[0]).gridTemplateColumns;
      const grande = medir(linhas[3], '21.5px');   // 13% maior que os 19px previstos
      return { normal, grande, cols, curta: medir(linhas[1]), longa: medir(linhas[2]), nowrap: getComputedStyle(linhas[0].querySelector('.rep-hint')).whiteSpace };
    });
    console.log('\n══ Linha de série em ' + largura + ' px ══');
    eq('"8-10" cabe em uma linha', [r.normal.linhas, r.normal.cabe], [1, true]);
    eq('mesmo com a fonte 13% maior continua em uma linha e dentro da caixa', [r.grande.linhas, r.grande.cabe], [1, true]);
    eq('a linha da série não passa de 85 px de altura (quebrada passava de 90)', r.normal.altura <= 85 && r.grande.altura <= 85, true);
    eq('"12" e a meta longa "10-12" também ficam bem', [r.curta.linhas, r.longa.linhas <= 2], [1, true]);
    eq('a primeira coluna tem pelo menos 64 px e a meta não quebra (white-space: nowrap)', [parseFloat(r.cols.split(' ')[0]) >= 64, r.nowrap], [true, 'nowrap']);
    eq('sem erro de JavaScript', erros, []);
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
