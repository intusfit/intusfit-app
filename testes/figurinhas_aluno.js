// Figurinha do fim do treino (aluno.html): se a lista do servidor falhar na abertura, não pode voltar o padrão antigo (Alcateia do Ferro).
// Regressão de 27/10 (Samantha Lara): busca única e silenciosa; quando falhava, o aluno recebia as 6 figurinhas de fábrica.
//   cd testes && INTUS_DIR=../app/painel node figurinhas_aluno.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
const LISTA = { itens: [{ tipo: 'img', src: '/app/img/uploads/fig_a.jpg', legenda: 'A' }, { tipo: 'img', src: '/app/img/uploads/fig_b.jpg', legenda: 'B' }] };

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8105, '127.0.0.1', r));
  const b = await chromium.launch();
  const erros = [];
  async function abrir(rede, cache) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => rede(r));
    await page.addInitScript((c) => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); if (c) localStorage.setItem('intus-fig-cfg', JSON.stringify(c)); }, cache);
    await page.goto('http://127.0.0.1:8105/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _figurinhasList === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    return { ctx, page };
  }
  const legendas = (page) => page.evaluate(() => _figurinhasList().map(i => i.legenda));

  console.log('\n══ Servidor fora do ar na abertura, nunca leu a lista ══');
  let s = await abrir(r => r.abort('failed'), null);
  let l = await legendas(s.page);
  ver('nunca aparece a Alcateia do Ferro', !l.includes('ALCATEIA DO FERRO'), l);
  ver('o padrão é uma única figurinha neutra', l.length === 1, l);
  await s.ctx.close();

  console.log('\n══ Servidor fora do ar, mas o aparelho já tinha a lista ══');
  s = await abrir(r => r.abort('failed'), LISTA);
  l = await legendas(s.page);
  eq('usa a última lista boa guardada no aparelho', l, ['A', 'B']);
  await s.ctx.close();

  console.log('\n══ Servidor responde: lista vale e fica guardada ══');
  s = await abrir(r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(LISTA) }), null);
  await s.page.waitForTimeout(800);
  l = await legendas(s.page);
  eq('lista do servidor aplicada', l, ['A', 'B']);
  eq('lista guardada no aparelho', await s.page.evaluate(() => (JSON.parse(localStorage.getItem('intus-fig-cfg') || '{}').itens || []).length), 2);
  await s.ctx.close();

  console.log('\n══ Primeira busca falha e a segunda funciona ══');
  let n = 0;
  s = await abrir(r => { n++; if (n === 1) return r.abort('failed'); r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(LISTA) }); }, null);
  await s.page.waitForTimeout(4500);
  l = await legendas(s.page);
  eq('nova tentativa traz a lista sozinha', l, ['A', 'B']);
  await s.ctx.close();

  console.log('\n══ Estrutura ══');
  const html = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  eq('o texto "ALCATEIA DO FERRO" não existe mais no app', /ALCATEIA DO FERRO/.test(html), false);
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
