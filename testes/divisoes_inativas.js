// Divisões (A, B, C...) ativas e inativas dentro do mesmo treino (09/11/2026): o professor libera só uma parte de um treino que já está em produção.
//   cd testes && INTUS_DIR=../app/painel node divisoes_inativas.js
// Parte 1: editor (treinos.html), botão Ativar/Inativar, regras e o que vai para o servidor. Parte 2: app do aluno, o que ele enxerga.
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const ex = (id, div, nome) => ({ idtreino: id, idficha: 5, divisao: div, idexercicio: id, nmexercicio: nome, qtdserie: 3, repeticoes: '10', intervalo: '01:00' });
const TREINOS = [ex(1, 'A', 'Supino'), ex(2, 'A', 'Crucifixo'), ex(3, 'B', 'Agachamento'), ex(4, 'C', 'Remada'), ex(5, 'D', 'Elevação lateral')];

(async () => {
  const srv = http.createServer((req, res) => { const p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8136, '127.0.0.1', r));
  const b = await chromium.launch();

  console.log('\n══ Editor do professor ══');
  {
    const ctx = await b.newContext({ viewport: { width: 1500, height: 1600 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = [], dialogos = [];
    page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', tipo: 'admin', admin: true, perfil: 'admin' })); });
    await page.goto('http://127.0.0.1:8136/treinos.html', { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof renderEditor === 'function', null, { timeout: 15000 });
    await page.waitForTimeout(800);
    await page.evaluate((TREINOS) => {
      atletas = [{ idatleta: 7, nome: 'Aluna Teste' }];
      exercicios = TREINOS.map(t => ({ idexercicio: t.idexercicio, nmexercicio: t.nmexercicio, grupo: 'x' }));
      editFichaId = 5;
      editorState = { init: true, fichaId: 5, nome: 'Ficha em uso', atletaId: 7, observacao: '', dtinicio: '2026-10-01', validade: '', ativo: true, divNomes: { A: 'Peito' }, divOrdem: ['A', 'B', 'C', 'D'], tabAtiva: 'A', secaoAlong: false, secaoEx: true,
        exercicios: TREINOS.map(t => ({ ...t, tempopausa: 60, tut: '3010', metodo: '' })), alongamentos: [] };
      view = 'editor'; render();
    }, TREINOS);
    await page.waitForTimeout(500);
    const botaoTxt = () => page.evaluate(() => (document.querySelector('button[onclick="alternarDivisaoAtiva()"]') || {}).textContent);
    const inativas = () => page.evaluate(() => API.divisoesInativas(editorState));
    eq('começa com tudo ativo e o botão oferece "Inativar divisão"', [await inativas(), (await botaoTxt() || '').trim()], [[], '⏸ Inativar divisão']);

    await page.evaluate(() => trocarTab('C')); await page.click('button[onclick="alternarDivisaoAtiva()"]'); await page.waitForTimeout(200);
    await page.evaluate(() => trocarTab('D')); await page.click('button[onclick="alternarDivisaoAtiva()"]'); await page.waitForTimeout(200);
    eq('C e D ficam inativas (A e B seguem ativas)', await inativas(), ['C', 'D']);
    const abas = await page.evaluate(() => [...document.querySelectorAll('.tab-btn')].map(x => x.innerText.replace(/\s+/g, ' ').trim()));
    ver('as abas inativas mostram o ⏸ e as ativas não', /⏸/.test(abas[2]) && /⏸/.test(abas[3]) && !/⏸/.test(abas[0]) && !/⏸/.test(abas[1]), abas);
    ver('na aba inativa o botão vira "Ativar divisão" e há um aviso dizendo que o aluno não vê', await page.evaluate(() => /Ativar divisão/.test(document.querySelector('button[onclick="alternarDivisaoAtiva()"]').textContent) && /inativa.*aluno não a vê/s.test(document.getElementById('main-content').innerText)));

    await page.evaluate(() => trocarTab('B')); await page.click('button[onclick="alternarDivisaoAtiva()"]'); await page.waitForTimeout(200);
    eq('B ficou inativa também (sobra só a A)', await inativas(), ['C', 'D', 'B']);
    await page.evaluate(() => trocarTab('A')); await page.click('button[onclick="alternarDivisaoAtiva()"]'); await page.waitForTimeout(200);
    eq('não deixa inativar a última divisão ativa (A continua ativa) e avisa', [await inativas(), await page.evaluate(() => document.body.innerText.includes('pelo menos uma divisão ativa'))], [['C', 'D', 'B'], true]);

    await page.evaluate(() => trocarTab('B')); await page.click('button[onclick="alternarDivisaoAtiva()"]'); await page.waitForTimeout(200);
    eq('ativar de novo devolve a B (libera uma sequência A e B, C e D continuam guardadas)', await inativas(), ['C', 'D']);

    await page.evaluate(() => { adicionarDivisao(); });
    eq('uma divisão nova nasce ativa', await page.evaluate(() => [editorState.tabAtiva, API.divisaoInativa(editorState, editorState.tabAtiva)]), ['E', false]);
    await page.evaluate(() => { trocarTab('C'); duplicarDivisao(); });
    eq('a cópia de uma divisão inativa nasce ativa', await page.evaluate(() => [editorState.tabAtiva, API.divisaoInativa(editorState, editorState.tabAtiva)]), ['F', false]);
    page.on('dialog', d => d.accept());
    await page.evaluate(() => { trocarTab('D'); excluirDivisao(); });
    eq('excluir uma divisão inativa tira a letra da lista de inativas', await inativas(), ['C']);

    // o que vai para o servidor: a lista vai junto de divNomes, e o nome continua sendo só o nome
    const enviado = await page.evaluate(() => {
      let corpo = null;
      const orig = API.editarFicha;
      API.editarFicha = async (id, dados) => { corpo = dados; return { ok: true }; };
      const o2 = window.voltarLista; window.voltarLista = () => {};
      salvarFichaCompleta();
      return new Promise(r => setTimeout(() => { API.editarFicha = orig; window.voltarLista = o2; r(corpo); }, 700));
    });
    ver('ao salvar, divNomes leva a lista de inativas junto dos nomes', enviado && enviado.divNomes && JSON.stringify(enviado.divNomes._inativas) === '["C"]' && enviado.divNomes.A === 'Peito', enviado && enviado.divNomes);
    await page.evaluate(() => { editorState.divNomes = { A: 'Peito' }; view = 'lista'; render(); });
    eq('sem erro de script no editor', erros, []);
    await ctx.close();
  }

  console.log('\n══ App do aluno ══');
  {
    const FICHA = { idficha: 5, idatleta: 7, nmficha: 'Ficha em uso', ativo: 1, dtinicio: '2026-10-01', dtfim: null, divNomes: { A: 'Peito', _inativas: ['C', 'D'] }, divOrdem: ['A', 'B', 'C', 'D'] };
    const ctx = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(([FICHA, TREINOS]) => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1');
      localStorage.setItem('intus-fichas', JSON.stringify([FICHA])); localStorage.setItem('intus-treinos', JSON.stringify(TREINOS));
    }, [FICHA, TREINOS]);
    await page.goto('http://127.0.0.1:8136/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(500);
    eq('o aluno só recebe as divisões ativas (A e B)', await page.evaluate(() => { const f = carregarFicha(); return [f.length, Object.keys(f[0].divisoes), f[0].itens.length]; }), [1, ['A', 'B'], 3]);
    await page.evaluate(() => { _syncDone = true; abrirMeusTreinos(); }); await page.waitForTimeout(500);
    const tela = await page.evaluate(() => document.getElementById('v-treinos').innerText);
    ver('"Escolha seu treino" lista Peito e a Divisão B, sem a C e a D', /Peito/.test(tela) && /Divisão B/.test(tela) && !/Divisão C/.test(tela) && !/Divisão D/.test(tela), tela);
    ver('o sistema não deixa o aluno entrar numa divisão inativa (sem exercícios para abrir)', await page.evaluate(() => { const f = carregarFicha()[0]; return !f.divisoes.C && !f.divisoes.D; }));
    // liberar a C: some da lista de inativas
    await page.evaluate(() => { const fs = JSON.parse(localStorage.getItem('intus-fichas')); fs[0].divNomes._inativas = ['D']; localStorage.setItem('intus-fichas', JSON.stringify(fs)); });
    eq('com a C liberada o aluno passa a ver A, B e C', await page.evaluate(() => Object.keys(carregarFicha()[0].divisoes)), ['A', 'B', 'C']);
    await page.evaluate(() => { const fs = JSON.parse(localStorage.getItem('intus-fichas')); fs[0].divNomes._inativas = ['A', 'B', 'C', 'D']; localStorage.setItem('intus-fichas', JSON.stringify(fs)); });
    eq('ficha com todas as divisões inativas não aparece para o aluno', await page.evaluate(() => carregarFicha().length), 0);
    await page.evaluate(() => { const fs = JSON.parse(localStorage.getItem('intus-fichas')); fs[0].divNomes = { A: 'Peito' }; localStorage.setItem('intus-fichas', JSON.stringify(fs)); });
    eq('ficha antiga, sem a lista de inativas, continua mostrando tudo', await page.evaluate(() => Object.keys(carregarFicha()[0].divisoes)), ['A', 'B', 'C', 'D']);
    eq('sem erro de script no app', erros, []);
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log('\n' + (ruim ? 'FALHARAM ' + ruim : 'Tudo certo') + ' (' + ok + ' ok)');
  process.exit(ruim ? 1 : 0);
})();
