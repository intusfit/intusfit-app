// Gráficos de evolução de carga com a sessão ESCOLHIDA (IntusEvolucao.selecionavel): régua no Histórico do exercício (durante o treino) e cartões na tela de
// Evoluções. Cobra o que o aluno reclamou: escolher só por toque parado, a escolha FICAR depois de soltar, arrastar não escolher, os números fora do gráfico.
//   cd testes && INTUS_DIR=../app/painel node evolucao_selecao.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html';
    const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f)) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f));
  });
  await new Promise(r => srv.listen(8111, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block', hasTouch: true });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 't');
    localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 1, idusuario: 1, nome: 'Aluno Teste', email: 'a@a' }));
    const ses = [], fim = new Date(2026, 10, 9);
    for (let i = 0; i < 14; i++) {
      const d = new Date(fim.getFullYear(), fim.getMonth(), fim.getDate() - (13 - i) * 5), iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
      const base = Math.round((50 + i * 1.1) / 2.5) * 2.5, pes = [base, base + 5, base + 7.5, base + 7.5], reps = [12, 10, 8, 8];
      ses.push({ idsessao: 100 + i, idatleta: 1, dtsessao: iso, divisao: i % 2 ? 'D' : 'A', itens: [{ idexercicio: 5, nmexercicio: 'Supino reto', series: pes.map((p, k) => ({ peso: p, reps: reps[k] })) }] });
    }
    localStorage.setItem('intus-sessoes-treino', JSON.stringify(ses));
  });
  await page.goto('http://127.0.0.1:8111/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof abrirHistoricoExercicio === 'function' && typeof IntusEvolucao !== 'undefined').catch(() => false)) break; await page.waitForTimeout(150); }
  await page.waitForTimeout(800);

  const ev = (sel, tipo, fx, fy) => page.evaluate(([sel, tipo, fx, fy]) => { const el = document.querySelector(sel), r = el.getBoundingClientRect(); el.dispatchEvent(new PointerEvent(tipo, { bubbles: true, clientX: r.left + r.width * fx, clientY: r.top + fy, pointerId: 1, pointerType: 'touch' })); }, [sel, tipo, fx, fy]);
  const titulo = () => page.evaluate(() => document.querySelector('#ov-hist-ex [id$="-leit"] div').textContent);
  const rotulosNosGraficos = () => page.evaluate(() => [...document.querySelectorAll('#ov-hist-ex .evs-plot svg text[font-weight="800"]')].map(t => t.textContent));

  console.log('\n── Histórico do exercício (régua, durante o treino) ──');
  await page.evaluate(() => abrirHistoricoExercicio(5)); await page.waitForTimeout(800);
  eq('três gráficos desenhados para o exercício', await page.evaluate(() => document.querySelectorAll('#ov-hist-ex .evs-plot svg').length), 3);
  eq('ao abrir, a última sessão já está escolhida', (await titulo()).slice(0, 10), '09/11/2026');
  eq('o painel mostra carga e repetições de cada série', await page.evaluate(() => /65 kg\s*× 12/.test(document.querySelector('#ov-hist-ex [id$="-leit"]').innerText)), true);
  eq('os números ficam fora do gráfico (painel acima, nenhum texto de dica sobre o gráfico)', await page.evaluate(() => document.querySelectorAll('#ov-hist-ex [id$="-tip"]').length), 0);
  await ev('#ov-hist-ex .evs-trilho', 'pointerdown', 0.5, 20); const meio = await titulo();
  await ev('#ov-hist-ex .evs-trilho', 'pointermove', 0.1, 20); const dez = await titulo();
  await ev('#ov-hist-ex .evs-trilho', 'pointerup', 0.1, 20); await page.waitForTimeout(150);
  eq('arrastar a régua muda a sessão', [meio !== '09/11/2026 · divisão D', dez !== meio], [true, true]);
  eq('soltar o dedo NÃO apaga nem muda a escolha', await titulo(), dez);
  const rot = await rotulosNosGraficos();
  eq('a escolha aparece nos três gráficos ao mesmo tempo, com a mesma data', [rot.length, new Set(rot).size], [3, 1]);
  await ev('#ov-hist-ex .evs-plot', 'pointerdown', 0.5, 60); await ev('#ov-hist-ex .evs-plot', 'pointermove', 0.5, 220); await ev('#ov-hist-ex .evs-plot', 'pointerup', 0.5, 220);
  eq('arrastar sobre o gráfico (rolar a página) NÃO escolhe sessão', await titulo(), dez);
  await ev('#ov-hist-ex .evs-plot', 'pointerdown', 0.95, 60); await ev('#ov-hist-ex .evs-plot', 'pointerup', 0.95, 60);
  eq('toque curto no gráfico escolhe a sessão mais próxima', (await titulo()).slice(0, 10) !== dez.slice(0, 10), true);
  const antes = await titulo(); await page.evaluate(() => document.querySelector('#ov-hist-ex [id$="-ant"]').click());
  eq('a seta ◀ volta uma sessão', (await titulo()) !== antes, true);
  await page.evaluate(() => fecharHistoricoExercicio());

  console.log('\n── Tela de Evoluções (cartões) ──');
  await page.evaluate(() => { _evoAl.aberto = true; navegar('historico'); }); await page.waitForTimeout(1500);
  const info = () => page.evaluate(() => { const s = document.querySelector('#v-historico [data-evo-sel]'), c = s.querySelector('.evs-car'), cw = c.children[0].offsetWidth + 8; return { cartoes: c.children.length, rot: s.querySelector('.evs-plot svg text[font-weight="800"]').textContent, idxCartao: Math.round(c.scrollLeft / cw) }; });
  let i0 = await info();
  eq('um cartão por sessão, com a última sessão escolhida ao abrir', [i0.cartoes, i0.rot, i0.idxCartao], [14, '09/11', 13]);
  await page.evaluate(() => { const c = document.querySelector('#v-historico .evs-car'); c.scrollLeft = c.scrollLeft - 5 * (c.children[0].offsetWidth + 8); });
  await page.waitForTimeout(600);
  const i1 = await info();
  eq('deslizar os cartões acende outro ponto no gráfico', [i1.rot !== '09/11', i1.idxCartao], [true, 8]);
  await page.evaluate(() => { const h = document.querySelector('#v-historico .evs-plot'), r = h.getBoundingClientRect(), mk = (t) => h.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: r.left + 60, clientY: r.top + 60, pointerId: 1, pointerType: 'touch' })); mk('pointerdown'); mk('pointerup'); });
  await page.waitForTimeout(1600);
  const i2 = await info();
  const tituloCartao = await page.evaluate(() => { const c = document.querySelector('#v-historico .evs-car'), cw = c.children[0].offsetWidth + 8; return c.children[Math.round(c.scrollLeft / cw)].innerText.slice(0, 5); });
  eq('tocar num ponto do gráfico leva o cartão daquela sessão ao centro (mesma data do ponto)', [tituloCartao === i2.rot, i2.rot !== i1.rot], [true, true]);
  eq('nenhum erro de script', erros, []);

  await b.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falhas'); process.exit(ruim ? 1 : 0);
})();
