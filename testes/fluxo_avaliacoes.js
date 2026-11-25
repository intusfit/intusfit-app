// Fluxo da área de Avaliações do aluno, 26/11/2026: abrir, nova avaliação, ver avaliações, comparativos (dados e fotos), formulários, ir e voltar.
// Cada caminho é percorrido de duas formas: pelo botão "← Voltar" da tela e pelo voltar do celular (gesto), e termina sempre na tela inicial.
//   cd testes && INTUS_DIR=../app/painel node fluxo_avaliacoes.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json', '.css': 'text/css' };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const AVALIACOES = [
    { idavaliacao: 4, idatleta: 7, dtavaliacao: '2026-11-20', peso: 71.2, percgordura: 17.5, metodo: 'Pollock 7 dobras', medidas: { dc_coxa: 18, dc_subescapular: 14, dc_peitoral: 8, dc_abdominal: 20, dc_axilar: 9, dc_tricipital: 12, dc_suprailiaca: 16, cintura: 74, quadril: 98 }, fotos: [] },
    { idavaliacao: 3, idatleta: 7, dtavaliacao: '2026-09-02', peso: 73.4, percgordura: 20.1, metodo: 'Pollock 7 dobras', medidas: { dc_suprailiaca: 19, dc_coxa: 21, dc_tricipital: 14, dc_axilar: 11, dc_peitoral: 10, dc_subescapular: 16, dc_abdominal: 24, cintura: 77, quadril: 100 }, fotos: [] },
    { idavaliacao: 2, idatleta: 7, dtavaliacao: '2026-06-10', peso: 75.9, percgordura: 22.8, metodo: 'Bioimpedância', medidas: { cintura: 80 }, fotos: [] },
    { idavaliacao: 1, idatleta: 7, dtavaliacao: '2026-03-01', peso: 78.0, percgordura: 25.0, metodo: 'Composição', fotos: [] },
  ];
  const novoContexto = async () => {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    const pedidos = [];
    await page.route(/\/app\/api\//, r => {
      const u = r.request().url(), m = r.request().method();
      if (/action=avaliacoes/.test(u) && m !== 'GET') { pedidos.push({ m, u, corpo: JSON.parse(r.request().postData() || '{}') }); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, idavaliacao: 9, fotos: [] }) }); }
      if (/action=avaliacoes/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(AVALIACOES) });
      if (/feedbacks\.php\?action=meus/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, feedbacks: [] }) });
      if (/action=atleta_existe/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
      if (/action=(anamnese|saveAnamnese)|anamnese/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      return r.abort('failed');
    });
    await page.addInitScript(() => {
      localStorage.setItem('mx-token', 'tok-fluxo'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-ajuda-v2', JSON.stringify({ visto: 1 }));
      const fim = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
      localStorage.setItem('intus-mensalidades', JSON.stringify([{ idmensalidade: 1, idatleta: 7, plano: 'Consultoria Premium', dtinicio: '2026-11-01', dtvencimento: fim, stmatricula: 'ativa', status: 'paga' }]));
    });
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => !!window.ComparativoFotos && typeof renderAvaliacoesAluno === 'function' && typeof viewStack !== 'undefined' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(600);
    return { ctx, page, erros, pedidos };
  };

  // Estado visível: tela ativa, pilha, título e se sobrou alguma folha ou camada por cima.
  const estado = (page) => page.evaluate(() => {
    const v = document.querySelector('.view.ativo'); const id = v ? v.id.replace(/^v-/, '') : null;
    const t = v ? ((v.querySelector('.th-titulo, .sect-title, h2') || {}).textContent || '').trim() : '';
    const camadas = Array.from(document.body.children).filter(e => e.classList && (e.classList.contains('com-sheet') || /overlay/.test(e.id || '')) && e.offsetParent !== null && getComputedStyle(e).position === 'fixed').map(e => e.id);
    return { tela: id, pilha: viewStack.slice(), titulo: t.replace(/\s+/g, ' ').slice(0, 40), camadas: camadas.filter(c => !/^(menu-overlay|tutorial-overlay)$/.test(c)) };
  });
  const esperar = (page, ms) => page.waitForTimeout(ms || 450);
  const voltarBotao = async (page) => { await page.evaluate(() => { const v = document.querySelector('.view.ativo'); const bt = v && v.querySelector('.btn-back'); if (!bt) throw new Error('sem botão Voltar na tela ' + (v && v.id)); bt.click(); }); await esperar(page); if (process.env.DBG) console.log('   voltarBotao', JSON.stringify(await estado(page))); };
  const voltarGesto = async (page) => { await page.evaluate(() => history.back()); await esperar(page, 600); if (process.env.DBG) console.log('   voltarGesto', JSON.stringify(await estado(page))); };
  const toque = async (page, js) => { await page.evaluate(js); await esperar(page); if (process.env.DBG) console.log('   toque', String(js).slice(0, 60), JSON.stringify(await estado(page))); };

  const caminhos = [
    { nome: 'Avaliações → Nova avaliação → Composição corporal', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes', pilha: ['home', 'avaliacoes'] },
      { js: () => abrirNovaAvaliacao(), tela: 'avaliacoes', t: /Nova Avalia/ },
      { js: () => abrirFormAutoAvaliacao(), tela: 'avaliacoes', t: /Composi/ } ] },
    { nome: 'Avaliações → Nova avaliação → Circunferências', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirNovaAvaliacao(), tela: 'avaliacoes', t: /Nova Avalia/ },
      { js: () => abrirFormCircunferencias(), tela: 'avaliacoes', t: /Circunfer/ } ] },
    { nome: 'Avaliações → Ver Avaliações', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirVerAvaliacoes(), tela: 'avaliacoes', t: /Hist/ } ] },
    { nome: 'Avaliações → Ver Comparativos', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirComparativos(), tela: 'avaliacoes', t: /Comparativos/ } ] },
    { nome: 'Avaliações → Comparar fotos', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirComparativoFotos(), tela: 'avaliacoes', t: /Comparar fotos/ } ] },
    { nome: 'Avaliações → Anamnese', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirAnamnese(), tela: 'anamnese', t: /Anamnese/ } ] },
    { nome: 'Avaliações → Comportamental', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirComportamental(), tela: 'avaliacoes', t: /Comportamental/ } ] },
    { nome: 'Avaliações → Nutricional', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirFormNutricional(), tela: 'avaliacoes', t: /Nutricional/ } ] },
    { nome: 'Avaliações → Fotos e Postural', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirFormFotosPostural(), tela: 'avaliacoes', t: /Fotos e Avalia/ } ] },
    { nome: 'Avaliações → Comparativos → evolução de 3 avaliações', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirComparativos(), tela: 'avaliacoes' },
      { js: () => { ComparativoAvaliacoes.todas(); ComparativoAvaliacoes.comparar(); }, tela: 'avaliacoes', t: /Sua evolu/ } ] },
    { nome: 'Avaliações → Anamnese → Avaliações → Nova avaliação → Composição', passos: [
      { js: () => navegar('avaliacoes'), tela: 'avaliacoes' },
      { js: () => abrirAnamnese(), tela: 'anamnese' },
      { js: () => voltarTela(), tela: 'avaliacoes' },
      { js: () => abrirNovaAvaliacao(), tela: 'avaliacoes' },
      { js: () => abrirFormAutoAvaliacao(), tela: 'avaliacoes' } ] },
  ];

  for (const modo of ['botão Voltar da tela', 'voltar do celular']) {
    console.log('\n══ ' + modo + ' ══');
    for (const c of caminhos) {
      const { ctx, page, erros } = await novoContexto();
      for (const p of c.passos) { await toque(page, p.js); }
      // sobe pelos mesmos passos, de trás para frente, até a tela inicial
      let n = 0, e = await estado(page), historico = [e.tela + ':' + e.titulo];
      while (e.tela !== 'home' && n < 8) { if (modo === 'botão Voltar da tela') await voltarBotao(page); else await voltarGesto(page); e = await estado(page); historico.push(e.tela + ':' + e.titulo); n++; }
      eq(c.nome + ': chega na tela inicial em ' + c.passos.length + ' passos de volta (caminho feito: ' + historico.join(' › ') + ')', [e.tela, e.pilha, n <= c.passos.length, e.camadas], ['home', ['home'], true, []]);
      eq(c.nome + ': sem erro de script', erros, []);
      await ctx.close();
    }
  }

  // ═══════════════ cenários específicos ═══════════════
  console.log('\n══ Entradas na área de Avaliações ══');
  {
    const { ctx, page, erros } = await novoContexto();
    await page.evaluate(() => toggleMenu()); await esperar(page);
    await page.evaluate(() => { Array.from(document.querySelectorAll('.menu-item')).find(m => /Avalia/.test(m.textContent)).click(); }); await esperar(page, 800);
    let e = await estado(page);
    eq('pelo menu: abre Avaliações com a pilha [início, avaliações]', [e.tela, e.pilha], ['avaliacoes', ['home', 'avaliacoes']]);
    await voltarBotao(page); e = await estado(page);
    eq('pelo menu: o Voltar leva direto para a tela inicial', [e.tela, e.pilha], ['home', ['home']]);
    // vindo da Central: o Voltar volta para a Central, não para o início
    await toque(page, () => navegar('mensagens')); await toque(page, () => navegar('avaliacoes')); await esperar(page, 600);
    e = await estado(page);
    eq('vindo de outra tela (Central): a pilha guarda de onde veio', e.pilha, ['home', 'mensagens', 'avaliacoes']);
    await voltarGesto(page); e = await estado(page);
    eq('o voltar do celular leva para a Central e depois para o início', [e.tela], ['mensagens']);
    await voltarGesto(page); e = await estado(page);
    eq('…e depois para o início', [e.tela, e.pilha], ['home', ['home']]);
    eq('sem erro de script', erros, []);
    await ctx.close();
  }

  console.log('\n══ Ir e vir pelos passos (botão e gesto misturados) ══');
  {
    const { ctx, page, erros } = await novoContexto();
    await toque(page, () => navegar('avaliacoes'));
    await toque(page, () => abrirVerAvaliacoes()); await voltarBotao(page);
    await toque(page, () => abrirComparativos());
    await toque(page, () => { ComparativoAvaliacoes.todas(); ComparativoAvaliacoes.comparar(); }); await voltarGesto(page); await voltarBotao(page);
    await toque(page, () => abrirNovaAvaliacao()); await toque(page, () => abrirFormCircunferencias()); await voltarGesto(page); await voltarBotao(page);   // Circunferências volta para Nova avaliação, que volta para a lista
    await toque(page, () => abrirAnamnese()); await voltarBotao(page);
    await toque(page, () => abrirComparativoFotos()); await voltarBotao(page);
    await toque(page, () => abrirFormNutricional()); await voltarGesto(page);
    let e = await estado(page);
    eq('depois de abrir e fechar 7 passos, continua na lista de Avaliações e a pilha não cresceu', [e.tela, e.titulo, e.pilha], ['avaliacoes', '📋 Minhas Avaliações', ['home', 'avaliacoes']]);
    await voltarBotao(page); e = await estado(page);
    eq('um único Voltar leva à tela inicial', [e.tela, e.pilha], ['home', ['home']]);
    eq('o histórico do navegador ficou do tamanho da pilha (nada sobrando para o voltar girar entre telas)', await page.evaluate(() => viewStack.length), 1);
    eq('sem erro de script', erros, []);
    await ctx.close();
  }

  console.log('\n══ Anamnese: respostas não enviadas e salvar ══');
  {
    const { ctx, page, erros, pedidos } = await novoContexto();
    const perguntas = []; page.on('dialog', d => { perguntas.push(d.message().slice(0, 40)); d.dismiss(); });   // "Cancelar": ficar na tela
    await toque(page, () => navegar('avaliacoes')); await toque(page, () => abrirAnamnese());
    await page.fill('#anamnese-form textarea', 'Quero emagrecer'); await esperar(page, 900);
    await voltarGesto(page); let e = await estado(page);
    eq('com resposta não enviada, o voltar do celular pergunta e (cancelando) fica na Anamnese, com o texto', [e.tela, perguntas.length, await page.inputValue('#anamnese-form textarea'), e.pilha], ['anamnese', 1, 'Quero emagrecer', ['home', 'avaliacoes', 'anamnese']]);
    await voltarBotao(page); e = await estado(page);
    eq('o botão Voltar da tela passa pela mesma pergunta (uma só vez) e fica', [e.tela, perguntas.length], ['anamnese', 2]);
    page.removeAllListeners('dialog'); page.on('dialog', d => { perguntas.push('ok'); d.accept(); });   // "Sair mesmo assim"
    await voltarGesto(page); e = await estado(page);
    eq('aceitando sair, volta para Avaliações (e não reabre a Anamnese)', [e.tela, e.titulo, e.pilha], ['avaliacoes', '📋 Minhas Avaliações', ['home', 'avaliacoes']]);
    // salvar de verdade
    await toque(page, () => abrirAnamnese()); await page.fill('#anamnese-form textarea', 'Quero ganhar massa'); await esperar(page, 900);
    await page.evaluate(() => salvarAnamnese()); await esperar(page, 1000);
    e = await estado(page);
    eq('salvar a Anamnese devolve para Avaliações, com a pilha certa', [e.tela, e.titulo, e.pilha], ['avaliacoes', '📋 Minhas Avaliações', ['home', 'avaliacoes']]);
    await voltarBotao(page); e = await estado(page);
    eq('e o Voltar seguinte chega na tela inicial (antes reabria a Anamnese)', [e.tela, e.pilha], ['home', ['home']]);
    eq('sem erro de script', erros, []);
    await ctx.close();
  }

  console.log('\n══ A sincronização não pode apagar o que a pessoa está fazendo ══');
  {
    const { ctx, page, erros } = await novoContexto();
    await toque(page, () => navegar('avaliacoes')); await toque(page, () => abrirNovaAvaliacao()); await toque(page, () => abrirFormCircunferencias());
    await page.fill('#circ-cintura', '78'); await page.fill('#circ-quadril', '99');
    eq('_avaliacoesNaLista: só a lista principal pode ser redesenhada por baixo dos panos', await page.evaluate(() => { const r = {}; r.circunferencias = _avaliacoesNaLista(); renderAvaliacoesAluno(); return new Promise(res => setTimeout(() => { r.lista = _avaliacoesNaLista(); abrirNovaAvaliacao(); r.nova = _avaliacoesNaLista(); abrirVerAvaliacoes(); r.historico = _avaliacoesNaLista(); abrirComparativos(); r.comparativos = _avaliacoesNaLista(); abrirComparativoFotos(); r.fotos = _avaliacoesNaLista(); abrirFormNutricional(); r.nutri = _avaliacoesNaLista(); res(r); }, 600)); }), { circunferencias: false, lista: true, nova: false, historico: false, comparativos: false, fotos: false, nutri: false });
    await toque(page, () => { abrirNovaAvaliacao(); abrirFormCircunferencias(); }); await page.fill('#circ-cintura', '78'); await page.fill('#circ-quadril', '99');
    // o fim da sincronização (mesmo trecho que roda em sincronizarDoBackend) com o aluno no meio do formulário
    await page.evaluate(() => { _syncDone = true; if (window._viewAtual === 'avaliacoes' && typeof renderAvaliacoesAluno === 'function' && _avaliacoesNaLista()) renderAvaliacoesAluno(); });
    await esperar(page, 700);
    eq('terminar a sincronização com o formulário aberto não apaga o que foi digitado', [await page.inputValue('#circ-cintura'), await page.inputValue('#circ-quadril')], ['78', '99']);
    eq('sem erro de script', erros, []);
    await ctx.close();
  }

  console.log('\n══ Salvar e voltar ══');
  {
    const { ctx, page, erros, pedidos } = await novoContexto();
    await toque(page, () => navegar('avaliacoes')); await toque(page, () => abrirNovaAvaliacao()); await toque(page, () => abrirFormCircunferencias());
    await page.fill('#circ-cintura', '78'); await page.evaluate(() => salvarCircunferencias()); await esperar(page, 1000);
    let e = await estado(page);
    eq('salvar Circunferências grava e mostra a lista de Avaliações', [pedidos.length, pedidos[0] && pedidos[0].corpo.metodo, pedidos[0] && pedidos[0].corpo.medidas.cintura, e.tela, e.titulo], [1, 'Circunferências', 78, 'avaliacoes', '📋 Minhas Avaliações']);
    await voltarBotao(page); e = await estado(page);
    eq('e o Voltar vai direto para a tela inicial', [e.tela, e.pilha], ['home', ['home']]);
    await toque(page, () => navegar('avaliacoes')); await toque(page, () => abrirNovaAvaliacao()); await toque(page, () => abrirFormAutoAvaliacao());
    await page.fill('#av-peso', '70.5'); await page.evaluate(() => salvarAutoAvaliacao()); await esperar(page, 1000);
    e = await estado(page);
    eq('salvar Composição grava e mostra a lista', [pedidos.length, pedidos[1] && pedidos[1].corpo.peso, e.tela, e.titulo], [2, 70.5, 'avaliacoes', '📋 Minhas Avaliações']);
    await voltarGesto(page); e = await estado(page);
    eq('o voltar do celular leva para a tela inicial', [e.tela, e.pilha], ['home', ['home']]);
    eq('sem erro de script', erros, []);
    await ctx.close();
  }

  console.log('\n══ Folhas por cima: o voltar do celular fecha só a folha ══');
  {
    const { ctx, page, erros } = await novoContexto();
    await toque(page, () => navegar('avaliacoes')); await toque(page, () => abrirComparativoFotos());
    await toque(page, () => ComparativoFotos.escolher('a'));
    eq('a lista de fotos abre como folha', await page.evaluate(() => !!document.getElementById('cf-pick')), true);
    await voltarGesto(page); let e = await estado(page);
    eq('o voltar do celular fecha a folha e a montagem continua', [await page.evaluate(() => !!document.getElementById('cf-pick')), e.tela, e.titulo, e.camadas], [false, 'avaliacoes', '📸 Comparar fotos', []]);
    await voltarBotao(page); await voltarBotao(page); e = await estado(page);
    eq('dois Voltar depois, na tela inicial', [e.tela, e.pilha], ['home', ['home']]);
    eq('sem erro de script', erros, []);
    await ctx.close();
  }

  console.log('\n══ Feedback em vídeo ══');
  {
    const { ctx, page, erros } = await novoContexto();
    await page.route(/feedbacks\.php\?action=meus/, r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, feedbacks: [{ titulo: 'Sua evolução em novembro', professor_nome: 'Luiz', criado_em: '2026-11-20 10:00:00', duracao_seg: 95, video_url: 'https://x/feedback/?v=' + 'a'.repeat(32), visto_em: null }] }) }));
    await toque(page, () => navegar('avaliacoes')); await esperar(page, 900);
    eq('o feedback do professor aparece na lista com o selo Novo', await page.evaluate(() => /Sua evolução em novembro/.test(document.getElementById('fb-aluno-bloco').textContent) && /Novo/.test(document.getElementById('fb-aluno-bloco').textContent)), true);
    await toque(page, () => abrirFeedbackVideo(0));
    let e = await estado(page);
    eq('abre a tela do vídeo com o cabeçalho padrão', [e.tela, e.titulo], ['avaliacoes', '🎬 Feedback em vídeo']);
    await voltarGesto(page); e = await estado(page);
    eq('o voltar do celular volta para a lista de Avaliações', [e.tela, e.titulo, e.pilha], ['avaliacoes', '📋 Minhas Avaliações', ['home', 'avaliacoes']]);
    await toque(page, () => abrirFeedbackVideo(0)); await voltarBotao(page); await voltarBotao(page); e = await estado(page);
    eq('e o botão Voltar também, e depois a tela inicial', [e.tela, e.pilha], ['home', ['home']]);
    eq('sem erro de script', erros.filter(m => !/player|IntusFeedback/.test(m)), []);
    await ctx.close();
  }

  console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
  await b.close(); srv.close();
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
