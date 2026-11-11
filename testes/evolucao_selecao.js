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
      const itens = [{ idexercicio: 5, nmexercicio: 'Supino reto', series: pes.map((p, k) => ({ peso: p, reps: reps[k] })) }];
      if (i >= 11) itens.push({ idexercicio: 6, nmexercicio: 'Rosca direta', series: [{ peso: 20, reps: 10 }, { peso: 22, reps: 8 }] });   // só 3 sessões: cabe na tela
      ses.push({ idsessao: 100 + i, idatleta: 1, dtsessao: iso, divisao: i % 2 ? 'D' : 'A', itens });
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
  eq('três gráficos desenhados para o exercício', await page.evaluate(() => document.querySelectorAll('#ov-hist-ex .evs-scroll svg').length), 3);
  eq('ao abrir, a última sessão já está escolhida', (await titulo()).slice(0, 10), '09/11/2026');
  eq('o painel mostra carga e repetições de cada série', await page.evaluate(() => /65 kg\s*× 12/.test(document.querySelector('#ov-hist-ex [id$="-leit"]').innerText)), true);
  eq('nenhuma dica solta que acompanha o dedo (os números são fixos na sessão escolhida)', await page.evaluate(() => document.querySelectorAll('#ov-hist-ex [id$="-tip"]').length), 0);

  // Números SOBRE o gráfico: valor de cada série ao lado da linha escolhida, dentro de cada gráfico, sem se sobrepor nem sair da área do gráfico.
  const numeros = () => page.evaluate(() => [...document.querySelectorAll('#ov-hist-ex .evs-plot')].map(h => {
    const svg = h.querySelector('svg'), sb = svg.getBoundingClientRect(), m = h._m;
    const bg = [...svg.querySelectorAll('.evs-num-bg')].map(e => { const r = e.getBoundingClientRect(); return { x0: r.left - sb.left, x1: r.right - sb.left, y0: r.top - sb.top, y1: r.bottom - sb.top }; });
    return { textos: [...svg.querySelectorAll('.evs-num')].map(t => t.textContent), bg, W: m.W, padT: m.padT, base: m.padT + m.plotH };
  }));
  const sobrepoe = (a, b) => a.x0 < b.x1 - 0.5 && b.x0 < a.x1 - 0.5 && a.y0 < b.y1 - 0.5 && b.y0 < a.y1 - 0.5;
  const dentro = (n) => n.bg.every(r => r.x0 >= 0 && r.x1 <= n.W + 0.5 && r.y0 >= n.padT - 1 && r.y1 <= n.base + 1);
  const livres = (n) => n.bg.every((a, i) => n.bg.every((b, j) => i === j || !sobrepoe(a, b)));
  let nm = await numeros();
  eq('cada gráfico mostra o valor de cada série da sessão escolhida (4 séries, 2 e 1)', nm.map(n => n.textos.length), [4, 2, 1]);
  eq('o gráfico das séries mostra carga e repetições juntas, igual ao painel (de cima para baixo, como as linhas)', nm[0].textos, ['72,5 kg × 8', '72,5 kg × 8', '70 kg × 10', '65 kg × 12']);
  eq('máxima e média aparecem com a unidade', nm[1].textos.every(t => /kg$/.test(t)), true);
  eq('as etiquetas ficam dentro da área do gráfico', nm.map(dentro), [true, true, true]);
  eq('duas séries com a mesma carga (S3 e S4) não ficam uma sobre a outra', nm.map(livres), [true, true, true]);

  // Rolagem para o lado: o gráfico tem um espaço mínimo por sessão; passando do que cabe, rola (os três juntos, eixo de kg parado).
  const rolagem = () => page.evaluate(() => [...document.querySelectorAll('#ov-hist-ex .evs-scroll')].map(s => ({ sl: Math.round(s.scrollLeft), w: s.clientWidth, sw: s.scrollWidth })));
  const linhaX = () => page.evaluate(() => +document.querySelector('#ov-hist-ex [id^="hx-sel-s"] line').getAttribute('x1'));
  const aVista = async () => { const [r] = await rolagem(), x = await linhaX(); return x >= r.sl && x <= r.sl + r.w; };
  let rg = await rolagem();
  eq('com 14 sessões o gráfico é mais largo que a tela e rola para o lado, nos três', rg.map(r => r.sw > r.w + 50), [true, true, true]);
  eq('ao abrir, o gráfico está rolado até o fim e a sessão escolhida (a última) está à vista', [rg.every(r => r.sl + r.w >= r.sw - 2), await aVista()], [true, true]);
  eq('aparece a dica de deslizar o gráfico quando ele rola', await page.evaluate(() => document.querySelector('#ov-hist-ex [id$="-dica"]').offsetParent !== null), true);
  eq('o eixo de kg fica parado sobre a borda esquerda (fora da área que rola)', await page.evaluate(() => { const h = document.querySelector('#ov-hist-ex .evs-plot'), e = h.querySelector('.evs-eixo'), s = h.querySelector('.evs-scroll'); return Math.abs(e.getBoundingClientRect().left - h.getBoundingClientRect().left) < 1 && e.querySelectorAll('text').length === 3 && !s.contains(e); }), true);
  const cdp = await ctx.newCDPSession(page);
  const deslizar = async (sel, dx) => {
    // a régua fica fixa embaixo da folha: leva o gráfico para a parte de cima antes de deslizar o dedo nele
    await page.evaluate(sel => { const c = document.getElementById('ov-hist-ex-corpo'), g = document.querySelector(sel); c.scrollTop = g.getBoundingClientRect().top - c.getBoundingClientRect().top + c.scrollTop - 160; }, sel);
    await page.waitForTimeout(200);
    const p = await page.evaluate(sel => { const b = document.querySelector(sel).getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; }, sel);
    eq('o dedo cai em cima do gráfico (não da régua)', await page.evaluate(([x, y]) => { const e = document.elementFromPoint(x, y); return !!(e && e.closest('.evs-plot')); }, [p.x, p.y]), true);
    // dedo de verdade: toque, arrasto em passos e soltar (o navegador decide se rola, como no celular)
    const t = (type, x) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y: p.y }] });
    await t('touchStart', p.x); for (let k = 1; k <= 10; k++) { await t('touchMove', p.x + k * dx / 10); await page.waitForTimeout(16); } await t('touchEnd', 0); await page.waitForTimeout(500);
  };
  const tituloAntes = await titulo();
  await deslizar('#ov-hist-ex .evs-plot', 160);   // dedo para a direita: aparecem os treinos mais antigos
  const rg2 = await rolagem();
  eq('deslizar o dedo no gráfico rola para o lado e mostra os treinos antigos', rg2[0].sl < rg[0].sl - 100, true);
  eq('os três gráficos rolam juntos', rg2.every(r => Math.abs(r.sl - rg2[0].sl) <= 1), true);
  eq('deslizar para o lado NÃO escolhe nem muda a sessão', await titulo(), tituloAntes);
  await ev('#ov-hist-ex .evs-trilho', 'pointerdown', 0.5, 20); const meio = await titulo();
  await ev('#ov-hist-ex .evs-trilho', 'pointermove', 0.1, 20); const dez = await titulo();
  await ev('#ov-hist-ex .evs-trilho', 'pointerup', 0.1, 20); await page.waitForTimeout(150);
  eq('arrastar a régua muda a sessão', [meio !== '09/11/2026 · divisão D', dez !== meio], [true, true]);
  eq('soltar o dedo NÃO apaga nem muda a escolha', await titulo(), dez);
  const nm2 = await numeros();
  eq('os números sobre o gráfico acompanham a sessão escolhida e continuam depois de soltar', [nm2[0].textos.length, nm2[0].textos.join() !== nm[0].textos.join(), nm2.every(n => dentro(n) && livres(n))], [4, true, true]);
  // sessão na ponta esquerda: a etiqueta passa para a direita da linha e continua inteira
  await ev('#ov-hist-ex .evs-trilho', 'pointerdown', 0, 20); await ev('#ov-hist-ex .evs-trilho', 'pointerup', 0, 20); await page.waitForTimeout(100);
  const nm3 = await numeros();
  eq('na primeira sessão (ponta esquerda) as etiquetas continuam inteiras e sem sobreposição', nm3.every(n => n.textos.length > 0 && dentro(n) && livres(n)), true);
  eq('a régua leva o gráfico até a sessão escolhida: na primeira, ele volta ao começo e a linha está à vista', [(await rolagem()).every(r => r.sl <= 2), await aVista()], [true, true]);
  await ev('#ov-hist-ex .evs-trilho', 'pointerdown', 0.5, 20); await ev('#ov-hist-ex .evs-trilho', 'pointerup', 0.5, 20); await page.waitForTimeout(100);
  eq('na sessão do meio a linha escolhida está à vista (o gráfico acompanha a régua)', await aVista(), true);
  const tickPilula = await page.evaluate(() => [...document.querySelectorAll('#ov-hist-ex .evs-plot')].map(h => {
    const svg = h.querySelector('svg'), p = [...svg.querySelectorAll('text[font-weight="800"]')][0].previousElementSibling.getBoundingClientRect();
    return [...svg.querySelectorAll('.evs-tk')].filter(t => getComputedStyle(t).visibility !== 'hidden').every(t => { const r = t.getBoundingClientRect(); return r.right <= p.left + 0.5 || r.left >= p.right - 0.5; });
  }));
  eq('a etiqueta da data não corta rótulos vizinhos do eixo (eles somem enquanto ela está ali)', tickPilula, [true, true, true]);
  await ev('#ov-hist-ex .evs-trilho', 'pointerdown', 1, 20); await ev('#ov-hist-ex .evs-trilho', 'pointerup', 1, 20); await page.waitForTimeout(100);
  eq('na última sessão (ponta direita) as etiquetas passam para a esquerda e ficam inteiras', (await numeros()).every(n => n.textos.length > 0 && dentro(n) && livres(n)), true);
  await ev('#ov-hist-ex .evs-trilho', 'pointerdown', 0.1, 20); await ev('#ov-hist-ex .evs-trilho', 'pointerup', 0.1, 20); await page.waitForTimeout(100);
  const rot = await rotulosNosGraficos();
  eq('a escolha aparece nos três gráficos ao mesmo tempo, com a mesma data', [rot.length, new Set(rot).size], [3, 1]);
  await ev('#ov-hist-ex .evs-plot', 'pointerdown', 0.5, 60); await ev('#ov-hist-ex .evs-plot', 'pointermove', 0.5, 220); await ev('#ov-hist-ex .evs-plot', 'pointerup', 0.5, 220);
  eq('arrastar sobre o gráfico (rolar a página) NÃO escolhe sessão', await titulo(), dez);
  await ev('#ov-hist-ex .evs-plot', 'pointerdown', 0.95, 60); await ev('#ov-hist-ex .evs-plot', 'pointerup', 0.95, 60);
  eq('toque curto no gráfico escolhe a sessão mais próxima', (await titulo()).slice(0, 10) !== dez.slice(0, 10), true);
  const antes = await titulo(); await page.evaluate(() => document.querySelector('#ov-hist-ex [id$="-ant"]').click());
  eq('a seta ◀ volta uma sessão', (await titulo()) !== antes, true);
  await page.evaluate(() => fecharHistoricoExercicio());
  await page.evaluate(() => abrirHistoricoExercicio(6)); await page.waitForTimeout(800);
  eq('com poucas sessões (3) tudo cabe na tela: nada rola e não aparece a dica', await page.evaluate(() => { const s = document.querySelector('#ov-hist-ex .evs-scroll'), d = document.querySelector('#ov-hist-ex [id$="-dica"]'); return [s.scrollWidth <= s.clientWidth + 1, d.offsetParent === null]; }), [true, true]);
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
  eq('o gráfico de Evoluções também rola para o lado e acompanha o cartão (a linha da sessão está à vista)', await page.evaluate(() => { const s = document.querySelector('#v-historico .evs-scroll'), l = document.querySelector('#v-historico [id^="evo-sel-s"] line'), x = +l.getAttribute('x1'); return [s.scrollWidth > s.clientWidth + 50, x >= s.scrollLeft && x <= s.scrollLeft + s.clientWidth]; }), [true, true]);
  await page.evaluate(() => { const h = document.querySelector('#v-historico .evs-plot'), r = h.getBoundingClientRect(), mk = (t) => h.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: r.left + 60, clientY: r.top + 60, pointerId: 1, pointerType: 'touch' })); mk('pointerdown'); mk('pointerup'); });
  await page.waitForTimeout(1600);
  const i2 = await info();
  const tituloCartao = await page.evaluate(() => { const c = document.querySelector('#v-historico .evs-car'), cw = c.children[0].offsetWidth + 8; return c.children[Math.round(c.scrollLeft / cw)].innerText.slice(0, 5); });
  eq('tocar num ponto do gráfico leva o cartão daquela sessão ao centro (mesma data do ponto)', [tituloCartao === i2.rot, i2.rot !== i1.rot], [true, true]);
  eq('nenhum erro de script', erros, []);

  await b.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falhas'); process.exit(ruim ? 1 : 0);
})();
