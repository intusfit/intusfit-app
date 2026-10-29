// Editor de ficha (treinos.html): arrastar exercicios e alongamentos, modos Normal/Ampla/Ampla + celular e a miniatura do celular do aluno.
// Navegador de verdade, API bloqueada, ficha montada na hora. Uso: node editor_ficha.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = process.env.INTUS_DIR || path.join(__dirname, '..', 'app', 'painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
const falhas = [];
const ok = (c, m) => { if (!c) { falhas.push(m); console.log('FALHA', m); } else console.log('ok   ', m); };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/treinos.html'; const f = path.join(RAIZ, p); if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8127, '127.0.0.1', r));
  const b = await chromium.launch();
  for (const tema of ['dark', 'light']) {
    const ctx = await b.newContext({ viewport: { width: 1500, height: 2600 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript((t) => {
      localStorage.setItem('mx-token', 'local-t'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', tipo: 'admin', admin: true, perfil: 'admin' }));
      if (t === 'light') localStorage.setItem('mx-theme', 'light');
      localStorage.removeItem('intus-ed-modo');
    }, tema);
    await page.goto('http://127.0.0.1:8127/treinos.html', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.evaluate(() => {
      exercicios = [
        { idexercicio: 1, nmexercicio: 'Supino reto', grupo: 'Peito' }, { idexercicio: 2, nmexercicio: 'Crucifixo', grupo: 'Peito' },
        { idexercicio: 3, nmexercicio: 'Tríceps corda', grupo: 'Tríceps' }, { idexercicio: 4, nmexercicio: 'Rosca direta', grupo: 'Bíceps' },
        { idexercicio: 5, nmexercicio: 'Agachamento livre', grupo: 'Pernas' },
      ];
      alongamentos = [{ idalongamento: 1, nome: 'Alongamento de peitoral' }, { idalongamento: 2, nome: 'Alongamento de ombros' }];
      atletas = [{ idatleta: 7, nome: 'Aluna Teste' }];
      editFichaId = null;
      editorState = { init: true, fichaId: null, nome: 'Ficha de teste', atletaId: 7, observacao: '', dtinicio: '2026-10-27', validade: '', ativo: true, divNomes: { A: 'Peito e tríceps' }, divOrdem: ['A', 'B'], tabAtiva: 'A', secaoAlong: true, secaoEx: true,
        exercicios: [
          { divisao: 'A', idexercicio: 1, nmexercicio: 'Supino reto', qtdserie: 4, repeticoes: '12/10/8/8', intervalo: '01:30', tempopausa: 90, tut: '3020', metodo: '', observacao: 'Cotovelos a 45 graus', tecnicas_por_serie: ['', '', '', 'drop'] },
          { divisao: 'A', idexercicio: 2, nmexercicio: 'Crucifixo', qtdserie: 3, repeticoes: '12', intervalo: '01:00', tempopausa: 60, tut: '3011', metodo: 'super' },
          { divisao: 'A', idexercicio: 3, nmexercicio: 'Tríceps corda', qtdserie: 3, repeticoes: '15', intervalo: '01:00', tempopausa: 60, tut: '2020', metodo: 'super', unilateral: true },
          { divisao: 'A', idexercicio: 4, nmexercicio: 'Rosca direta', qtdserie: 3, repeticoes: '10', intervalo: '01:00', tempopausa: 60, tut: '3010', metodo: '' },
          { divisao: 'B', idexercicio: 5, nmexercicio: 'Agachamento livre', qtdserie: 4, repeticoes: '8', intervalo: '02:00', tempopausa: 120, tut: '3010', metodo: '' },
        ],
        alongamentos: [
          { divisao: 'A', idalongamento: 1, nome: 'Alongamento de peitoral', series: 2, tempo: '30s', obs: '' },
          { divisao: 'A', idalongamento: 2, nome: 'Alongamento de ombros', series: 1, tempo: '20s', obs: 'sem dor' },
          { divisao: 'B', idalongamento: 2, nome: 'Alongamento de ombros', series: 1, tempo: '20s', obs: '' },
        ] };
      view = 'editor'; render();
    });
    await page.waitForTimeout(600);
    const arrastar = async (alca, alvo, pos) => {
      await page.evaluate(() => window.scrollTo(0, 0)); await page.waitForTimeout(100);
      const a = await alca.boundingBox(); const t = await alvo.boundingBox();
      await page.mouse.move(a.x + 13, a.y + 14); await page.mouse.down();
      await page.mouse.move(a.x + 40, a.y - 10, { steps: 4 });
      const ty = pos === 'fim' ? t.y + t.height - 5 : t.y + 5;
      await page.mouse.move(t.x + Math.min(200, t.width / 2), ty, { steps: 10 });
      await page.mouse.up(); await page.waitForTimeout(300);
    };
    const arrastarAba = async (pg, de, para, depois) => {
      await pg.evaluate(() => window.scrollTo(0, 0)); await pg.waitForTimeout(100);
      const a = await pg.locator('.tab-btn .tab-alca').nth(de).boundingBox();
      const t = await pg.locator('.tab-btn').nth(para).boundingBox();
      await pg.mouse.move(a.x + a.width / 2, a.y + a.height / 2); await pg.mouse.down();
      await pg.mouse.move(a.x + a.width / 2 + 10, a.y + a.height / 2 + 8, { steps: 3 });
      await pg.mouse.move(depois ? t.x + t.width - 6 : t.x + 6, t.y + t.height / 2, { steps: 10 });
      await pg.mouse.up(); await pg.waitForTimeout(300);
    };
    const nomes = () => page.evaluate(() => editorState.exercicios.filter(e => e.divisao === 'A').map(e => e.nmexercicio));
    const nomesAl = () => page.evaluate(() => editorState.alongamentos.filter(e => e.divisao === 'A').map(e => e.nome));
    console.log('--- tema', tema);
    ok((await page.evaluate(() => document.querySelector('.ed-wrap').className)).includes('ed-celular'), 'modo padrao celular em tela larga');
    ok(await page.evaluate(() => !!document.getElementById('mini-tela')), 'miniatura aparece');
    ok((await page.locator('.ex-card .dnd-alca, .along-card .dnd-alca').count()) === 4 + 2, 'alças: 4 exercicios + 2 alongamentos da divisão A');
    ok((await page.locator('.tab-btn .tab-alca').count()) === 2, 'alça em cada aba de divisão');

    // arrastar de verdade: exercicio 4 (Rosca) para antes do 1
    await arrastar(page.locator('.ex-card .dnd-alca').nth(3), page.locator('.ex-card').nth(0), 'topo');
    await page.waitForTimeout(300);
    let n = await nomes();
    ok(n.join('|') === 'Rosca direta|Supino reto|Crucifixo|Tríceps corda', 'arrastar 4º para o topo: ' + n.join('|'));
    ok(await page.evaluate(() => editorState.exercicios.find(e => e.divisao === 'B').nmexercicio === 'Agachamento livre' && editorState.exercicios[editorState.exercicios.length - 1].divisao === 'B'), 'divisão B intacta');
    // arrastar o 1 para depois do 3
    await arrastar(page.locator('.ex-card .dnd-alca').nth(0), page.locator('.ex-card').nth(2), 'fim');
    await page.waitForTimeout(300);
    n = await nomes();
    ok(n.join('|') === 'Supino reto|Crucifixo|Rosca direta|Tríceps corda', 'arrastar 1º para depois do 3º: ' + n.join('|'));
    // alongamento
    await arrastar(page.locator('.along-card .dnd-alca').nth(1), page.locator('.along-card').nth(0), 'topo');
    await page.waitForTimeout(300);
    let na = await nomesAl();
    ok(na.join('|') === 'Alongamento de ombros|Alongamento de peitoral', 'arrastar alongamento: ' + na.join('|'));
    // exercicio solto na aba da divisão B
    await arrastar(page.locator('.ex-card .dnd-alca').nth(0), page.locator('.tab-btn').nth(1), 'topo');
    await page.waitForTimeout(300);
    ok(await page.evaluate(() => editorState.exercicios.filter(e => e.divisao === 'B').map(e => e.nmexercicio).join('|') === 'Agachamento livre|Supino reto'), 'exercicio solto na aba B vai para o fim de B');
    ok(await page.evaluate(() => editorState.exercicios.filter(e => e.divisao === 'A').length === 3), 'A ficou com 3');
    // abas de divisão: arrastar a alça da aba muda a ordem e NÃO troca de aba
    await page.evaluate(() => { editorState.exercicios.push({ divisao: 'C', idexercicio: 5, nmexercicio: 'Agachamento livre', qtdserie: 3, repeticoes: '8', intervalo: '01:00', tempopausa: 60 }); renderEditor(); });
    await page.waitForTimeout(300);
    const ordemDiv = () => page.evaluate(() => [...document.querySelectorAll('.tab-btn[data-d]')].map(t => t.dataset.d).join(''));
    ok(await ordemDiv() === 'ABC', 'abas começam em A B C');
    await arrastarAba(page, 1, 2, true); // B para depois de C
    ok(await ordemDiv() === 'ACB', 'arrastar a aba B para depois de C: ' + await ordemDiv());
    ok(await page.evaluate(() => editorState.tabAtiva === 'A'), 'arrastar uma aba não troca a aba aberta');
    ok(await page.evaluate(() => editorState.divOrdem.join('') === 'ACB'), 'a ordem fica em divOrdem (é o que vai para a ficha)');
    await arrastarAba(page, 2, 0, false); // B para antes de A
    ok(await ordemDiv() === 'BAC', 'arrastar a aba B para antes de A: ' + await ordemDiv());
    // arrastar uma aba e soltar nela mesma não reordena nem abre a aba
    await arrastarAba(page, 1, 1, true);
    ok(await page.evaluate(() => editorState.tabAtiva === 'A'), 'arrastar uma aba e soltar nela mesma não troca a aba aberta');
    // miniatura reflete a ordem
    const mini = await page.evaluate(() => [...document.querySelectorAll('#mini-tela .mi-row .mi-nome')].map(e => e.firstChild.textContent));
    ok(mini.join('|') === 'Crucifixo|Rosca direta|Tríceps corda', 'miniatura na mesma ordem: ' + mini.join('|'));
    // edição de campo atualiza a miniatura
    await page.locator('.ex-card').nth(0).locator('.rep-pill').first().fill('20');
    await page.locator('.ex-card').nth(0).locator('.rep-pill').first().blur();
    await page.waitForTimeout(400);
    ok(await page.evaluate(() => /20/.test(document.querySelector('#mini-tela .mi-det').textContent)), 'miniatura acompanha a edição');
    // foco abre o exercicio na miniatura
    await page.locator('.ex-card').nth(2).locator('.ex-footer input').focus();
    await page.waitForTimeout(300);
    ok(await page.evaluate(() => document.querySelector('#mini-tela .mi-row.ativo .mi-nome').textContent.startsWith('Tríceps')), 'foco no cartão 3 abre o 3º na miniatura');
    // aba alongamentos
    await page.evaluate(() => miniAba('along')); await page.waitForTimeout(200);
    ok(await page.evaluate(() => document.querySelectorAll('#mini-tela .mi-along').length === 2), 'miniatura mostra 2 alongamentos');
    // modos
    await page.evaluate(() => edModo('ampla')); await page.waitForTimeout(300);
    ok(await page.evaluate(() => !document.getElementById('mini-tela') && document.querySelector('.ed-wrap').offsetWidth > 1200), 'modo ampla sem celular, largo');
    await page.evaluate(() => edModo('normal')); await page.waitForTimeout(300);
    ok(await page.evaluate(() => document.querySelector('.ed-wrap').offsetWidth <= 900), 'modo normal estreito');
    await page.evaluate(() => edModo('celular')); await page.waitForTimeout(300);
    // sticky
    await page.setViewportSize({ width: 1500, height: 900 }); await page.waitForTimeout(200);
    await page.evaluate(() => window.scrollTo(0, 600)); await page.waitForTimeout(200);
    const topo = await page.evaluate(() => Math.round(document.querySelector('.ed-side').getBoundingClientRect().top));
    ok(topo >= 60 && topo <= 90, 'miniatura fica fixa ao rolar (top=' + topo + ')');
    ok(erros.length === 0, 'sem erro de página ' + erros.join(';'));
    await ctx.close();
  }
  // ── toque (tablet e celular): dedo na alça, CDP dispara eventos de toque de verdade ──
  for (const [rot, vw, vh] of [['tablet', 820, 1180], ['celular', 390, 844]]) {
    const ctx = await b.newContext({ viewport: { width: vw, height: vh }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(() => { localStorage.setItem('mx-token', 'local-t'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', tipo: 'admin', admin: true })); localStorage.removeItem('intus-ed-modo'); });
    await page.goto('http://127.0.0.1:8127/treinos.html', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1500);
    await page.evaluate(() => {
      exercicios = [1, 2, 3, 4, 5, 6].map(i => ({ idexercicio: i, nmexercicio: 'Ex ' + i }));
      alongamentos = []; atletas = [{ idatleta: 7, nome: 'Aluna Teste' }]; editFichaId = null;
      editorState = { init: true, fichaId: null, nome: 'f', atletaId: 7, observacao: '', dtinicio: '2026-10-27', validade: '', ativo: true, divNomes: {}, divOrdem: ['A', 'B'], tabAtiva: 'A', secaoAlong: true, secaoEx: true,
        exercicios: [1, 2, 3, 4, 5, 6].map(i => ({ divisao: 'A', idexercicio: i, nmexercicio: 'Ex ' + i, qtdserie: 3, repeticoes: '12', intervalo: '01:00', tempopausa: 60 })).concat([{ divisao: 'B', idexercicio: 1, nmexercicio: 'Ex 1', qtdserie: 3, repeticoes: '12', intervalo: '01:00', tempopausa: 60 }]),
        alongamentos: [1, 2, 3].map(i => ({ divisao: 'A', idalongamento: 1, nome: 'Along ' + i, series: 1, tempo: '30s', obs: '' })) };
      view = 'editor'; render();
    });
    await page.waitForTimeout(500);
    const cdp = await ctx.newCDPSession(page);
    const toque = async (de, passos) => {
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: de.x, y: de.y, id: 1 }] });
      for (const q of passos) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: q.x, y: q.y, id: 1 }] }); await page.waitForTimeout(40); }
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
      await page.waitForTimeout(350);
    };
    const centro = async loc => { const r = await loc.boundingBox(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, w: r.width, h: r.height, top: r.y }; };
    const lista = () => page.evaluate(() => editorState.exercicios.filter(e => e.divisao === 'A').map(e => e.nmexercicio.replace('Ex ', '')).join(''));
    console.log('--- toque', rot);
    ok(await page.evaluate(() => getComputedStyle(document.querySelector('.ex-card .dnd-alca')).touchAction === 'none'), rot + ': alça com touch-action none');
    ok(await page.evaluate(() => getComputedStyle(document.querySelector('.tab-alca')).touchAction === 'none'), rot + ': alça da aba com touch-action none');
    ok(await page.evaluate(() => document.querySelector('.ex-card .dnd-alca').getBoundingClientRect().width >= 40 && document.querySelector('.tab-alca').getBoundingClientRect().width >= 32), rot + ': alças grandes o bastante para o dedo');
    // 1) rolagem automática: dedo na alça levado até a borda de baixo da tela e mantido ali
    await page.evaluate(() => document.querySelectorAll('.ex-card')[0].scrollIntoView({ block: 'start' }));
    await page.evaluate(() => window.scrollBy(0, -90)); await page.waitForTimeout(150);
    const rolInicio = await page.evaluate(() => window.scrollY);
    const alca = await centro(page.locator('.ex-card .dnd-alca').nth(0));
    const passos = [];
    for (let k = 1; k <= 10; k++) passos.push({ x: alca.x + 20, y: alca.y + (vh - 20 - alca.y) * k / 10 });
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: alca.x, y: alca.y, id: 1 }] });
    for (const q of passos) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: q.x, y: q.y, id: 1 }] }); await page.waitForTimeout(40); }
    await page.waitForTimeout(700);
    const rolDepois = await page.evaluate(() => window.scrollY);
    ok(rolDepois > rolInicio + 40, rot + ': a página rola sozinha com o dedo na borda de baixo (' + rolInicio + ' -> ' + rolDepois + ')');
    ok(await page.evaluate(() => !!document.querySelector('.dnd-fantasma')), rot + ': bloco flutuante segue o dedo');
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
    await page.waitForTimeout(350);
    const l1 = await lista();
    ok(l1 !== '123456' && [...l1].sort().join('') === '123456', rot + ': o exercício mudou de lugar sem perder nenhum (' + l1 + ')');
    ok(await page.evaluate(() => !document.querySelector('.dnd-fantasma') && !document.querySelector('.dnd-arrastando')), rot + ': sem bloco flutuante nem marca presa');
    // 2) destino exato, tela alta para não haver rolagem: 1º exercício para depois do 2º
    await page.setViewportSize({ width: vw, height: 3400 }); await page.waitForTimeout(250);
    await page.evaluate(() => window.scrollTo(0, 0)); await page.waitForTimeout(150);
    const antes = await lista();
    const a2 = await centro(page.locator('.ex-card .dnd-alca').nth(0));
    const t2 = await centro(page.locator('.ex-card').nth(1));
    await toque({ x: a2.x, y: a2.y }, [{ x: a2.x + 10, y: a2.y + 20 }, { x: t2.x, y: t2.top + t2.h - 6 }, { x: t2.x, y: t2.top + t2.h - 5 }]);
    const depois = await lista();
    ok(depois === antes[1] + antes[0] + antes.slice(2), rot + ': 1º vai para depois do 2º (' + antes + ' -> ' + depois + ')');
    // soltar fora de qualquer alvo não muda nada
    const a3 = await centro(page.locator('.ex-card .dnd-alca').nth(0));
    await toque({ x: a3.x, y: a3.y }, [{ x: a3.x + 8, y: a3.y + 8 }, { x: 4, y: a3.y }]);
    ok(await lista() === depois, rot + ': soltar fora não muda a ordem');
    // alongamento
    await page.evaluate(() => document.querySelectorAll('.along-card')[0].scrollIntoView({ block: 'center' })); await page.waitForTimeout(150);
    const b1 = await centro(page.locator('.along-card .dnd-alca').nth(0));
    const b3 = await centro(page.locator('.along-card').nth(2));
    await toque({ x: b1.x, y: b1.y }, [{ x: b1.x + 10, y: b1.y + 20 }, { x: b3.x, y: b3.top + b3.h - 5 }, { x: b3.x, y: b3.top + b3.h - 4 }]);
    ok(await page.evaluate(() => editorState.alongamentos.filter(a => a.divisao === 'A').map(a => a.nome).join('|')) === 'Along 2|Along 3|Along 1', rot + ': alongamento arrastado com o dedo');
    // abas de divisão com o dedo (3 divisões; tela alta para não rolar)
    await page.evaluate(() => { editorState.exercicios.push({ divisao: 'C', idexercicio: 3, nmexercicio: 'Ex 3', qtdserie: 3, repeticoes: '12', intervalo: '01:00', tempopausa: 60 }); editorState.divOrdem = ['A', 'B', 'C']; renderEditor(); });
    await page.waitForTimeout(300); await page.evaluate(() => window.scrollTo(0, 0));
    const ordemT = () => page.evaluate(() => [...document.querySelectorAll('.tab-btn[data-d]')].map(t => t.dataset.d).join(''));
    const c0 = await centro(page.locator('.tab-btn .tab-alca').nth(0));
    const tC = await centro(page.locator('.tab-btn').nth(2));
    ok(tC.x + tC.w / 2 < vw + 2, rot + ': a aba C está dentro da tela');
    await toque({ x: c0.x, y: c0.y }, [{ x: c0.x + 10, y: c0.y + 6 }, { x: tC.x + tC.w / 2 - 6, y: tC.y }, { x: tC.x + tC.w / 2 - 5, y: tC.y }]);
    ok(await ordemT() === 'BCA', rot + ': aba A arrastada com o dedo para depois de C (' + await ordemT() + ')');
    ok(await page.evaluate(() => editorState.tabAtiva === 'A' && editorState.divOrdem.join('') === 'BCA'), rot + ': aba aberta não mudou e divOrdem acompanha');
    const c1 = await centro(page.locator('.tab-btn .tab-alca').nth(1));
    await toque({ x: c1.x, y: c1.y }, [{ x: c1.x + 8, y: c1.y + 8 }, { x: 4, y: c1.y }]);
    ok(await ordemT() === 'BCA', rot + ': soltar a aba fora não muda a ordem');
    ok(await page.evaluate(() => editorState.tabAtiva === 'A'), rot + ': soltar a aba fora também não abre a aba');
    ok(erros.length === 0, rot + ': sem erro de página ' + erros.join(';'));
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log(falhas.length ? 'FALHAS: ' + falhas.length : 'TUDO OK');
  process.exit(falhas.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
