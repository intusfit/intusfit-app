// Cartões flutuantes de "treino em andamento", cardio ao vivo e programa de cardio: ficam mais acima do fim da tela (acima do indicador do iPhone), os três na mesma altura.
//   cd testes && INTUS_DIR=../app/painel node cartao_ativo.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const [w, h] of [[402, 874], [375, 667]]) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, serviceWorkers: 'block', hasTouch: true, isMobile: true });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _criarBannerTreino === 'function' && !!document.getElementById('prog-pill')).catch(() => false)) break; await page.waitForTimeout(250); }
    const r = await page.evaluate((alt) => {
      _criarBannerTreino();
      const banner = document.getElementById('treino-banner'), live = document.getElementById('cardio-live-pill'), prog = document.getElementById('prog-pill');
      banner.style.display = 'flex'; live.classList.add('on'); prog.classList.add('on');
      const folga = (el) => Math.round(alt - el.getBoundingClientRect().bottom);   // px entre a base do cartão e o fim da tela
      return { banner: folga(banner), live: folga(live), prog: folga(prog), variavel: getComputedStyle(document.documentElement).getPropertyValue('--ativo-bottom').trim() };
    }, h);
    console.log('\n══ Tela de ' + w + 'x' + h + ' ══');
    eq('os três cartões ficam na mesma altura', [r.banner === r.live, r.live === r.prog], [true, true]);
    ver('o cartão fica a pelo menos 80 px do fim da tela (antes 14 a 18 px), acima do indicador do iPhone', r.banner >= 80 && r.live >= 80 && r.prog >= 80, r);
    ver('não sobe demais: no máximo 100 px do fim (sem área segura), para não cobrir o conteúdo', r.banner <= 100, r);
    const fonte = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
    eq('a medida vem de uma variável só (--ativo-bottom) e inclui a área segura do iPhone', [/--ativo-bottom: calc\(86px \+ env\(safe-area-inset-bottom/.test(fonte), (fonte.match(/var\(--ativo-bottom/g) || []).length], [true, 3]);
    eq('sem erro de JavaScript', erros, []);
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
