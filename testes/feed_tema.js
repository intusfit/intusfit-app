// Feed: trocar escuro/claro enquanto um lote de posts ainda está chegando não pode deixar cartões com a cor antiga no meio da lista (20/11/2026).
//   cd testes && INTUS_DIR=../app/painel node feed_tema.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const mk = (id) => ({ idpost: id, idatleta: 9, created_at: '2026-11-20 10:00:00', legenda: 'Treino ' + id, midias: [], localizacao: '' });

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8113, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  let atrasar = false;
  await page.route(/\/app\/api\/catalogo\.php\?action=feed_posts/, async r => {
    const url = r.request().url();
    const lote = /antes_de=/.test(url);
    if (lote && atrasar) await new Promise(x => setTimeout(x, 1500));
    const posts = lote ? [mk(5), mk(4), mk(3)] : [mk(9), mk(8), mk(7), mk(6)];
    return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ posts: posts, temMais: !lote, atletas: { 9: 'Aluna Nove' }, avatars: {} }) });
  });
  await page.route(/\/app\/api\/(?!catalogo\.php\?action=feed_posts)/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-theme', 'escuro'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto('http://127.0.0.1:8113/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(800);
  const fundos = () => page.evaluate(() => [...document.querySelectorAll('#v-feed [data-feed-card]')].map(c => c.style.background || c.style.backgroundColor));

  console.log('\n══ Feed: trocar o tema com um lote de posts a caminho ══');
  atrasar = true;
  // abre o Feed completo (escuro), espera o 1º lote e dispara o seguinte
  await page.evaluate(() => { navegar('feed'); });
  await page.waitForTimeout(700);
  // troca para o claro com o lote seguinte ainda em andamento
  await page.evaluate(() => { document.body.classList.contains('tema-claro') || toggleTheme(); });
  await page.waitForTimeout(3500);
  const f = await fundos();
  eq('há cartões na tela', f.length >= 4, true);
  eq('todos os cartões estão no tema claro, nenhum escuro no meio', [...new Set(f)].length, 1);
  eq('e é o branco do tema claro', /255, 255, 255|#fff/i.test(f[0] || ''), true);
  await page.evaluate(() => { toggleTheme(); });
  await page.waitForTimeout(1500);
  const g = await fundos();
  eq('de volta ao escuro: todos os cartões escuros', [...new Set(g)].length === 1 && !/255, 255, 255|#fff/i.test(g[0] || ''), true);
  eq('nenhum erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
