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
      await page.mouse.move(t.x + 200, ty, { steps: 10 });
      await page.mouse.up(); await page.waitForTimeout(300);
    };
    const nomes = () => page.evaluate(() => editorState.exercicios.filter(e => e.divisao === 'A').map(e => e.nmexercicio));
    const nomesAl = () => page.evaluate(() => editorState.alongamentos.filter(e => e.divisao === 'A').map(e => e.nome));
    console.log('--- tema', tema);
    ok((await page.evaluate(() => document.querySelector('.ed-wrap').className)).includes('ed-celular'), 'modo padrao celular em tela larga');
    ok(await page.evaluate(() => !!document.getElementById('mini-tela')), 'miniatura aparece');
    ok((await page.locator('.dnd-alca').count()) === 4 + 2, 'alças: 4 exercicios + 2 alongamentos da divisão A');

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
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.locator('.ex-card .dnd-alca').nth(0).dragTo(page.locator('.tab-btn').nth(1));
    await page.waitForTimeout(300);
    ok(await page.evaluate(() => editorState.exercicios.filter(e => e.divisao === 'B').map(e => e.nmexercicio).join('|') === 'Agachamento livre|Supino reto'), 'exercicio solto na aba B vai para o fim de B');
    ok(await page.evaluate(() => editorState.exercicios.filter(e => e.divisao === 'A').length === 3), 'A ficou com 3');
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
  await b.close(); srv.close();
  console.log(falhas.length ? 'FALHAS: ' + falhas.length : 'TUDO OK');
  process.exit(falhas.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
