// Faixa verde "Você tem N notificações novas" (aluno.html): depois de tocar nela, ou de passar o tempo, tem que sumir de verdade.
// Regressão do iPhone (21/10): com a área segura do topo (~59 px na ilha dinâmica) a faixa escondida ainda deixava a borda e a sombra à mostra, "cortada" no alto.
// O Chromium não simula env(safe-area-inset-top); o teste troca o env() por 59 px na própria faixa para reproduzir o aparelho.
//   cd testes && INTUS_DIR=../app/painel node aviso_notificacao.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8104, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); });
  await page.goto('http://127.0.0.1:8104/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _notifAvisoNovo === 'function' && typeof _notifAvisoEstado === 'function').catch(() => false)) break; await page.waitForTimeout(250); }

  // reproduz a ilha dinâmica: troca env(safe-area-inset-top) por 59px na faixa
  const iphone = () => page.evaluate(() => {
    const el = document.getElementById('notif-aviso');
    el.style.top = 'calc(8px + 59px)';
    const t = el.style.transform; if (/env\(/.test(t)) el.style.transform = t.replace(/env\(safe-area-inset-top,\s*0px\)/, '59px');
  });
  const medir = () => page.evaluate(() => { const el = document.getElementById('notif-aviso'), r = el.getBoundingClientRect(), cs = getComputedStyle(el); return { top: Math.round(r.top), bottom: Math.round(r.bottom), opacidade: cs.opacity, toque: cs.pointerEvents }; });

  console.log('\n══ Aparece ══');
  await page.evaluate(() => _notifAvisoNovo(2));
  await iphone(); await page.waitForTimeout(600);
  let m = await medir();
  ver('visível abaixo da ilha dinâmica (topo ≥ 59 px) e tocável', m.top >= 59 && m.opacidade === '1' && m.toque !== 'none', m);

  console.log('\n══ Some depois de tocar ══');
  await page.evaluate(() => { document.getElementById('notif-aviso').click(); });
  await iphone(); await page.waitForTimeout(700);
  m = await medir();
  ver('depois do toque a faixa sai inteira da tela (nada cortado no alto)', m.bottom <= 0 && m.opacidade === '0' && m.toque === 'none', m);
  await page.evaluate(() => { closeModal && closeModal(); });

  console.log('\n══ Some sozinha depois do tempo ══');
  await page.evaluate(() => { _notifAvisoEm = 0; _notifAvisoNovo(1); });
  await iphone(); await page.waitForTimeout(400);
  await page.evaluate(() => { clearTimeout(_notifAvisoNovo._t); _notifAvisoEstado(document.getElementById('notif-aviso'), false); });
  await iphone(); await page.waitForTimeout(700);
  m = await medir();
  ver('ao esgotar o tempo também sai inteira', m.bottom <= 0 && m.opacidade === '0', m);

  console.log('\n══ Estrutura ══');
  const html = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  eq('nenhum "-180%" de altura própria sobrou nos estados da faixa', /translate\(-50%,-180%\)/.test(html), false);
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
