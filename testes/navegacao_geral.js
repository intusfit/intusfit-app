// Voltar e avançar pelo app inteiro (aluno.html): botão "← Voltar" do cabeçalho e voltar do celular, em todas as telas do menu e em sequências
// como as de quem está aprendendo a mexer (abrir, voltar, abrir de novo, tocar duas vezes, voltar várias vezes seguidas).
//   cd testes && INTUS_DIR=../app/painel node navegacao_geral.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8114, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); let erros = [];
  page.on('pageerror', e => erros.push(String(e.message)));
  page.on('dialog', d => d.accept());   // o app pergunta "sair mesmo?" em treino/cardio em andamento
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); });
  await page.goto('http://127.0.0.1:8114/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(600);
  const view = () => page.evaluate(() => window._viewAtual || 'home');
  const ir = async (js) => { await page.evaluate(js); await page.waitForTimeout(450); };
  const casa = async () => {   // volta ao início "do zero"
    for (let i = 0; i < 12 && (await view()) !== 'home'; i++) { await page.goBack().catch(() => {}); await page.waitForTimeout(250); }
    if ((await view()) !== 'home') await ir("trocarView('home'); renderHome()");
  };
  const voltarCab = async () => {
    const k = await page.evaluate(() => { const v = document.getElementById('v-' + window._viewAtual); const b = v && [...v.querySelectorAll('.btn-back')].find(e => e.offsetParent !== null); if (b) { b.click(); return true; } return false; });
    await page.waitForTimeout(450); return k;
  };

  const ENTRADAS = [
    ['Treinos', "navegar('treinos-menu')"], ['Meu Cardio', 'abrirCardio()'], ['Histórico', "navegar('historico')"], ['Ranking', 'abrirRanking()'], ['Medalhas', 'abrirQuadroMedalhas()'],
    ['Feed', "navegar('feed')"], ['Resultados', "navegar('resultados')"], ['Desafios', "navegar('desafios')"], ['Nutrição', "navegar('nutricao')"], ['Avaliações', "navegar('avaliacoes')"],
    ['Central', "navegar('mensagens')"], ['Perfil', "navegar('perfil')"], ['Quem somos', "navegar('quemsomos')"], ['Programas de cardio', 'abrirProgramasCardio()'], ['Cardio ao vivo', 'abrirCardioLive()'],
  ];
  console.log('\n══ Cada tela: abre, "← Voltar" e voltar do celular levam ao início ══');
  for (const [nome, js] of ENTRADAS) {
    await casa(); erros = [];
    await ir(js); const v = await view();
    if (v === 'home') { eq(nome + ': abriu outra tela', v, '(outra)'); continue; }
    const k = await voltarCab(); const v1 = await view();
    const filhaDoCardio = v === 'cardio-prog' || v === 'cardio-live';   // abertas pelo Meu Cardio: o "← Voltar" delas leva para lá
    eq(nome + ' [' + v + ']: "← Voltar" do cabeçalho' + (k ? '' : ' (sem botão visível)') + ' leva ' + (filhaDoCardio ? 'ao Meu Cardio' : 'ao início'), k ? v1 : 'home', filhaDoCardio ? 'cardio' : 'home');
    await casa(); await ir(js);
    await page.goBack(); await page.waitForTimeout(450);
    eq(nome + ': voltar do celular leva ao início', await view(), 'home');
    await casa(); await ir(js); await ir(js);   // dois toques seguidos no mesmo atalho
    await page.goBack(); await page.waitForTimeout(450);
    eq(nome + ': dois toques no atalho não criam passo extra (um voltar basta)', await view(), 'home');
  }

  console.log('\n══ Sequências (pilha do voltar) ══');
  await casa();
  await ir('abrirRanking()'); await ir("navegar('feed')"); await ir("navegar('nutricao')");
  const seq = [];
  for (let i = 0; i < 3; i++) { await page.goBack(); await page.waitForTimeout(350); seq.push(await view()); }
  eq('início → ranking → feed → nutrição: três voltas passam por feed, ranking e início', seq, ['feed', 'ranking', 'home']);
  await casa();
  for (const js of ["navegar('perfil')", "navegar('quemsomos')", 'abrirRanking()', "navegar('feed')", 'abrirCardio()', "navegar('historico')"]) await ir(js);
  for (let i = 0; i < 8; i++) { await page.goBack().catch(() => {}); await page.waitForTimeout(250); }   // 6 telas abertas, mais dois voltar "de sobra"
  eq('dois voltar de sobra depois do início não tiram o aluno do app', [await view(), page.url().startsWith('http://127.0.0.1:8114/aluno.html')], ['home', true]);

  console.log('\n══ Misturando o Cardio com as outras áreas ══');
  await casa();
  await ir('abrirCardio()'); await ir('cardioAbrirForm()'); await ir("_cardioForm.tipo='Bike'; _cardioForm.min='30'; _cardioFormAtualizar()");
  await ir('abrirRanking()');
  eq('do formulário do cardio para o ranking', await view(), 'ranking');
  await page.goBack(); await page.waitForTimeout(450);
  eq('voltar do ranking retorna ao formulário do cardio, com o que foi digitado', await page.evaluate(() => [window._viewAtual, _cardioUi.pilha.join('>'), document.getElementById('cf-min') ? document.getElementById('cf-min').value : null]), ['cardio', 'home>form', '30']);
  await page.goBack(); await page.waitForTimeout(450);
  eq('mais um voltar: lista do cardio', await page.evaluate(() => [window._viewAtual, _cardioUi.pilha.join('>')]), ['cardio', 'home']);
  await page.goBack(); await page.waitForTimeout(450);
  eq('e depois o início', await view(), 'home');
  await ir('abrirCardio()'); await ir('cardioAbrirForm()'); await ir('cardioEscolherAtiv("Corrida")'); await ir('cardioMinSet(30)'); await ir('cardioRevisar()');
  await ir("navegar('feed')");
  await page.goBack(); await page.waitForTimeout(450);
  eq('da confirmação para o feed e de volta: continua na confirmação', await page.evaluate(() => [window._viewAtual, _cardioUi.pilha.join('>')]), ['cardio', 'home>form>confirma']);
  await voltarCab();
  eq('"← Voltar" da confirmação: formulário', await page.evaluate(() => _cardioUi.pilha.join('>')), 'home>form');
  await voltarCab();
  eq('"← Voltar" do formulário: lista', await page.evaluate(() => _cardioUi.pilha.join('>')), 'home');
  await voltarCab();
  eq('"← Voltar" da lista: início', await view(), 'home');
  await ir('abrirCardio()'); await ir('cardioAbrirForm()'); await ir('abrirCardio()');
  eq('reabrir o Meu Cardio com o formulário aberto começa na lista', await page.evaluate(() => _cardioUi.pilha.join('>')), 'home');

  console.log('\n══ Subtelas e folhas ══');
  await casa();
  await ir("navegar('nutricao')"); await ir("nutriTela('receitas')");
  const rid = await page.evaluate(() => { const r = (typeof _nutriCont !== 'undefined' && _nutriCont.receitas || [])[0]; return r && r.id; });
  if (rid) {
    await ir("nutriTela('receita','" + rid + "')");
    const nut = [];
    for (let i = 0; i < 4; i++) { await page.goBack(); await page.waitForTimeout(350); nut.push(await page.evaluate(() => window._viewAtual === 'nutricao' ? 'nutricao:' + _nutri.tela : window._viewAtual)); }
    eq('Nutrição: receita → lista de receitas → tela inicial da Nutrição → início do app', nut, ['nutricao:receitas', 'nutricao:home', 'home', 'home']);
  } else eq('Nutrição: há receita para testar', false, true);
  await casa();
  await ir('abrirProgramasCardio()');
  const pid = await page.evaluate(() => API.PROGRAMAS_CARDIO[0].id);
  await ir("abrirProgramaCardio('" + pid + "')");
  eq('Programas guiados: abre o detalhe', await page.evaluate(() => _prog.tela), 'detalhe');
  await page.goBack(); await page.waitForTimeout(400);
  eq('voltar do celular: do detalhe para a lista de programas', await page.evaluate(() => [window._viewAtual, _prog.tela]), ['cardio-prog', 'lista']);
  await page.goBack(); await page.waitForTimeout(400);
  eq('e depois para fora dos programas', await view(), 'home');
  await casa();
  await ir("navegar('feed')"); await ir('abrirNovoPostFeed()');
  eq('Novo post abre por cima do feed', await page.evaluate(() => !!document.getElementById('feed-compose-overlay')), true);
  await page.goBack(); await page.waitForTimeout(400);
  eq('voltar do celular fecha o Novo post e continua no feed', await page.evaluate(() => [!!document.getElementById('feed-compose-overlay'), window._viewAtual]), [false, 'feed']);
  await page.goBack(); await page.waitForTimeout(400);
  eq('e o voltar seguinte leva ao início', await view(), 'home');
  await casa();
  await ir('abrirCardio()'); await ir("mostrarResultadoCardio(3, 'Corrida', 30, null, null, { tipo: 'Corrida', min: 30, distKm: 0 })");
  await page.goBack(); await page.waitForTimeout(400);
  eq('Cardio registrado: voltar fecha só a tela de parabéns', await page.evaluate(() => [!!document.getElementById('cardio-result-overlay'), window._viewAtual]), [false, 'cardio']);
  await page.goBack(); await page.waitForTimeout(400);
  eq('e depois sai do cardio', await view(), 'home');

  console.log('\n══ Menu lateral ══');
  await casa();
  await ir('toggleMenu()');
  eq('o menu abre', await page.evaluate(() => document.getElementById('menu-overlay').classList.contains('open')), true);
  await page.evaluate(() => document.querySelector('#menu-overlay .menu-item[onclick*="abrirCardio"]').click()); await page.waitForTimeout(450);
  eq('item "Meu Cardio" do menu abre o cardio e fecha o menu', await page.evaluate(() => [window._viewAtual, document.getElementById('menu-overlay').classList.contains('open')]), ['cardio', false]);
  await page.goBack(); await page.waitForTimeout(400);
  eq('voltar leva ao início', await view(), 'home');

  console.log('\n══ Erros de JavaScript ══');
  const unicos = [...new Set(erros)];
  eq('nenhum erro de página nas últimas sequências', unicos, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
