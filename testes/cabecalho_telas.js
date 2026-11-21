// Telas cheias que cobriam a barra de cima do app: o resultado do cardio traz o cabeçalho do app (logo, nome, ajuda, sino e menu), o post aberto e o
// compartilhar resultado trazem a logo (20/11/2026).
//   cd testes && INTUS_DIR=../app/painel node cabecalho_telas.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': f.endsWith('.js') ? 'text/javascript; charset=utf-8' : f.endsWith('.html') ? 'text/html; charset=utf-8' : 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8164, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto('http://127.0.0.1:8164/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(800);
  const v = () => page.evaluate(() => window._viewAtual || 'home');

  console.log('\n══ Resultado do cardio com o cabeçalho do app ══');
  await page.evaluate(() => { abrirCardio(); }); await page.waitForTimeout(600);
  await page.evaluate(() => mostrarResultadoCardio(5.4, 'Caminhada', 61, null, null, { tipo: 'Caminhada', min: 61, distKm: 0 })); await page.waitForTimeout(500);
  const topo = await page.evaluate(() => { const ov = document.getElementById('cardio-result-overlay'); const t = ov.querySelector('.top'); if (!t) return null; const r = t.getBoundingClientRect(); const bt = [...t.querySelectorAll('button')].map(x => x.getAttribute('title') || x.textContent.trim()); return { alto: Math.round(r.top), logo: !!t.querySelector('img.logo-img'), nome: t.querySelector('.nome').textContent, botoes: bt, ids: t.querySelectorAll('[id]').length, menuVisivel: [...t.querySelectorAll('button')].some(x => x.textContent.trim() === '☰' && x.getBoundingClientRect().width > 0) }; });
  eq('a tela de parabéns traz a barra do app no topo (logo, nome e menu), sem ids repetidos', [!!topo, topo && topo.alto, topo && topo.logo, topo && topo.nome, topo && topo.menuVisivel, topo && topo.ids], [true, 0, true, 'Aluna Teste', true, 0]);
  eq('o conteúdo continua inteiro (parabéns, pontos e botões)', await page.evaluate(() => { const t = document.getElementById('cardio-result-overlay').innerText; return /Parabéns!/.test(t) && /PONTOS SOMADOS/i.test(t) && /Voltar à tela inicial/.test(t) && /Voltar ao Meu Cardio/.test(t); }), true);
  eq('o conteúdo não passa por baixo do cabeçalho (começa abaixo dele)', await page.evaluate(() => { const ov = document.getElementById('cardio-result-overlay'); const t = ov.querySelector('.top').getBoundingClientRect().bottom; const e = [...ov.querySelectorAll('div')].find(d => /Parabéns!/.test(d.textContent) && d.children.length === 0); return e.getBoundingClientRect().top > t; }), true);
  await page.evaluate(() => { [...document.querySelectorAll('#cardio-result-overlay .top button')].find(x => x.textContent.trim() === '☰').click(); }); await page.waitForTimeout(400);
  eq('o menu lateral abre POR CIMA da tela de resultado', await page.evaluate(() => { const m = document.getElementById('menu-overlay'); const r = m.getBoundingClientRect(); const el = document.elementFromPoint(r.width - 20, 200); return m.classList.contains('open') && !!el && !!el.closest('#menu-overlay'); }), true);
  await page.evaluate(() => { [...document.querySelectorAll('#menu-overlay .menu-item')].find(x => /Início/.test(x.textContent)).click(); }); await page.waitForTimeout(700);
  eq('escolher um item do menu leva à tela e fecha o resultado', [await v(), await page.evaluate(() => !document.getElementById('cardio-result-overlay')), await page.evaluate(() => !document.getElementById('menu-overlay').classList.contains('open'))], ['home', true, true]);
  await page.evaluate(() => { abrirCardio(); }); await page.waitForTimeout(500);
  await page.evaluate(() => mostrarResultadoCardio(5.4, 'Caminhada', 61, null, null, { tipo: 'Caminhada', min: 61, distKm: 0 })); await page.waitForTimeout(400);
  await page.evaluate(() => { [...document.querySelectorAll('#cardio-result-overlay .top button')].find(x => x.textContent.trim() === '☰').click(); }); await page.waitForTimeout(300);
  await page.evaluate(() => fecharMenuDireto()); await page.waitForTimeout(300);
  eq('fechar o menu sem escolher nada mantém o resultado aberto', await page.evaluate(() => !!document.getElementById('cardio-result-overlay')), true);
  await page.evaluate(() => _fecharResultadoCardio());
  await page.evaluate(() => document.body.classList.contains('tema-claro') || toggleTheme()); await page.waitForTimeout(400);
  await page.evaluate(() => mostrarResultadoCardio(5.4, 'Caminhada', 61, null, null, { tipo: 'Caminhada', min: 61, distKm: 0 })); await page.waitForTimeout(500);
  await page.screenshot({ path: process.env.TEMP + '/resultado_cardio_claro.png' });
  await page.evaluate(() => _fecharResultadoCardio());

  console.log('\n══ Post aberto e compartilhar resultado com a logo ══');
  const mkPost = { idpost: 5, idatleta: 7, created_at: '2026-11-20 10:00:00', legenda: 'Treino', midias: [], localizacao: '' };
  await page.evaluate(p => { _feedAtletasMap = { 7: 'Aluna Teste' }; abrirPostTela(p); }, mkPost); await page.waitForTimeout(900);
  eq('post aberto: cabeçalho com Voltar, título e logo', await page.evaluate(() => { const o = document.getElementById('pk-post-ov'); return !!o && !!o.querySelector('img[alt="Intus"]') && /Publicação/.test(o.innerText) && /Voltar/.test(o.innerText); }), true);
  await page.evaluate(() => document.getElementById('pk-post-ov').remove()); await page.waitForTimeout(500);
  await page.evaluate(() => abrirCompartilharResultado()); await page.waitForTimeout(600);
  eq('compartilhar resultado: logo no cabeçalho', await page.evaluate(() => { const o = document.getElementById('res-compose-overlay'); return !!o && !!o.querySelector('img[alt="Intus"]'); }), true);
  eq('nenhum erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
