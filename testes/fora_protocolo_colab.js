// Treino fora do protocolo com @menção de outro aluno e o encaminhamento para o post colaborativo (aluno.html + treinos.php).
//   cd testes && INTUS_DIR=../app/painel node fora_protocolo_colab.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8103, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const S = { amigos: [42], enviados: [], posts: [], buscas: 0, listaOk: true, listaVazia: false };
  await page.route(/\/app\/api\/treinos\.php.*action=sessoes/, async (route) => {
    const req = route.request();
    if (req.method() === 'POST') { S.posts.push(JSON.parse(req.postData())); return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"idsessao":777}' }); }
    return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.route(/\/app\/api\/catalogo\.php.*action=(amizades|parcerias|turmas|desafios_aluno|pessoas_mencionaveis)/, route => {
    const a = (route.request().url().match(/action=(\w+)/) || [])[1];
    if (a === 'pessoas_mencionaveis') { S.buscas++; return route.fulfill({ status: S.listaOk ? 200 : 500, contentType: 'application/json', body: JSON.stringify({ pessoas: S.listaVazia ? [] : [{ tipo: 'aluno', id: 42, nome: 'Isabela Pazzinatto', desde: '' }] }) }); }
    const corpo = a === 'amizades' ? { amigos: S.amigos, recebidos: [], enviados: S.enviados, pessoas: {} } : (a === 'parcerias' ? { parceiros: [], recebidos: [], enviados: [], limite: 30, pessoas: {} } : { pessoas: {} });
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(corpo) });
  });
  await page.route(/\/app\/api\/(?!treinos\.php|catalogo\.php.*action=(amizades|parcerias|turmas|desafios_aluno|pessoas_mencionaveis))/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); });
  await page.goto('http://127.0.0.1:8103/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof abrirTreinoForaProtocolo === 'function' && typeof _fpColabCartao === 'function' && typeof API !== 'undefined').catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(1200);

  const preparar = async (texto, escolher) => {
    await page.evaluate(() => {
      document.getElementById('fp-sheet')?.remove(); document.getElementById('fp-ok')?.remove(); document.getElementById('feed-compose-overlay')?.remove();
      _mencoesPessoas = [{ id: 42, nome: 'Isabela Pazzinatto', tipo: 'aluno', desde: '' }, { id: 7, nome: 'Aluna Teste', tipo: 'aluno', desde: '' }]; _mencoesPessoasTs = Date.now(); _mencoesRecalcularRepetidos();
      _conexoesOn = true; _amzDados = { amigos: [], recebidos: [], enviados: [], pessoas: {} };
      localStorage.removeItem('intus-sessoes-treino');
      _mencoesFechar(); abrirTreinoForaProtocolo();
    });
    await page.waitForTimeout(300); await page.focus('#fp-desc');
    await page.keyboard.type(texto);
    // as teclas disparam buscas assíncronas: espera a lista já filtrada (só quem casa com o que foi digitado) e escolhe na mesma hora
    if (texto[0] === '@') { await page.waitForFunction((t) => { const d = document.getElementById('mencao-dd'); return d && d.textContent.toLowerCase().includes(t); }, texto.slice(1).toLowerCase(), { timeout: 3000 }); }
    if (escolher) await page.evaluate(() => _mencoesEscolher(0));
  };
  const preencher = () => page.evaluate(() => {
    document.getElementById('fp-dur').value = '45'; document.getElementById('fp-n-0').value = 'Panturrilha em Pé';
    document.getElementById('fp-c-0-0').value = '50'; document.getElementById('fp-r-0-0').value = '12';
  });

  console.log('\n══ O campo oferece os alunos ao digitar @ ══');
  await preparar('@isa');
  ver('aparece a lista com a Isabela', await page.evaluate(() => !!document.getElementById('mencao-dd') && /Isabela Pazzinatto/.test(document.getElementById('mencao-dd').textContent)));
  // regressão real (iPhone, 20/10): a lista flutuante ficava atrás da folha / saía da tela com o teclado e não dava para tocar nela.
  // Agora ela é parte da própria folha, logo abaixo do campo.
  const vis = await page.evaluate(() => { const d = document.getElementById('mencao-dd'), r = d.getBoundingClientRect(), t = document.elementFromPoint(r.left + r.width / 2, r.top + 14), campo = document.getElementById('fp-desc').getBoundingClientRect(); return { dentro: !!t && d.contains(t), naCaixa: !!d.closest('#fp-mencao-box'), abaixoDoCampo: r.top >= campo.bottom - 1, noEcra: r.bottom <= innerHeight && r.top >= 0 }; });
  ver('a lista está VISÍVEL e tocável, dentro da folha, logo abaixo do campo', vis.dentro && vis.naCaixa && vis.abaixoDoCampo && vis.noEcra, vis);
  // o iPhone rola a página sozinho quando o teclado abre: isso fechava a lista na hora
  await page.evaluate(() => { window.dispatchEvent(new Event('scroll')); document.getElementById('fp-sheet-corpo')?.dispatchEvent(new Event('scroll')); });
  await page.waitForTimeout(100);
  ver('a rolagem do teclado NÃO fecha a lista', await page.evaluate(() => !!document.getElementById('mencao-dd')));
  eq('o próprio aluno não é sugerido? (a lista do app já o exclui no servidor; aqui só confere que a escolha funciona)', await page.evaluate(() => !!_mencoesCtx), true);
  await page.evaluate(() => _mencoesEscolher(0));
  eq('o campo fica com "@Isabela Pazzinatto "', await page.$eval('#fp-desc', e => e.value), '@Isabela Pazzinatto ');
  await preencher();

  console.log('\n══ O que vai no registro ══');
  const m = await page.evaluate(() => _fpMontar());
  ver('sem erro de validação', !m.erro, m.erro);
  eq('o comentário leva só o nome, sem código nem @', m.sessao.comentario, 'Fora do protocolo (treino com outra pessoa): Isabela Pazzinatto');
  eq('a marcação vai à parte, para o servidor avisar', m.sessao.mencoes, '@[Isabela Pazzinatto]{aluno:42}');
  eq('o resumo sabe quem foi marcado', m.resumo.marcados, [{ id: 42, nome: 'Isabela Pazzinatto' }]);
  eq('divisão FORA e 5 pontos continuam', m.sessao.divisao, 'FORA');

  console.log('\n══ Nome digitado à mão (quem não é aluno) ══');
  await preparar('João'); await preencher();
  const j = await page.evaluate(() => _fpMontar());
  eq('texto livre continua valendo, sem marcação', [j.sessao.comentario, j.sessao.mencoes, j.resumo.marcados], ['Fora do protocolo (treino com outra pessoa): João', undefined, []]);

  console.log('\n══ Só se marca a si mesma? Não vale ══');
  await preparar('@alun', true); await preencher();
  const eu = await page.evaluate(() => _fpMontar());
  eq('marcar a si mesma não cria marcação', [eu.sessao.mencoes, eu.resumo.marcados], [undefined, []]);

  console.log('\n══ Registrar e encaminhar ao post colaborativo (amigo) ══');
  S.amigos = [42]; S.posts = [];
  await preparar('@isa', true); await preencher();
  await page.evaluate(() => _fpSalvar());
  await page.waitForSelector('#fp-ok', { timeout: 5000 });
  eq('enviou a marcação ao servidor', S.posts[0] && S.posts[0].mencoes, '@[Isabela Pazzinatto]{aluno:42}');
  await page.waitForFunction(() => /Postar em colaboração/.test((document.getElementById('fp-colab') || {}).textContent || ''), null, { timeout: 5000 });
  const cart = await page.$eval('#fp-colab', e => e.textContent);
  ver('o cartão diz que a Isabela é amiga e oferece o post', /Isabela/.test(cart) && /amigo ✓/.test(cart) && /Postar em colaboração/.test(cart), cart);
  await page.evaluate(() => _fpPostarColab());
  await page.waitForTimeout(600);
  const c = await page.evaluate(() => ({ colab: _composeColab.slice(), compose: !!document.getElementById('feed-compose-overlay'), titulo: (document.getElementById('feed-compose-overlay') || {}).textContent || '', ok: !!document.getElementById('fp-ok') }));
  eq('o compositor abre já com a Isabela convidada', c.colab, [42]);
  ver('na tela de compartilhar treino, com o convite visível', c.compose && /Compartilhar treino/.test(c.titulo) && /Isabela/.test(c.titulo), c.titulo.replace(/\s+/g, ' ').slice(0, 600));
  eq('a tela de sucesso fecha', c.ok, false);

  console.log('\n══ Marcada, mas ainda não é amiga ══');
  S.amigos = []; S.enviados = []; S.posts = [];
  await preparar('@isa', true); await preencher();
  await page.evaluate(() => { document.getElementById('feed-compose-overlay')?.remove(); _fpSalvar(); });
  await page.waitForSelector('#fp-ok');
  await page.waitForFunction(() => /amigos no app|Pedir amizade/.test((document.getElementById('fp-colab') || {}).textContent || ''), null, { timeout: 5000 });
  const cart2 = await page.$eval('#fp-colab', e => e.textContent);
  ver('oferece pedir amizade e NÃO oferece o post colaborativo', /Pedir amizade/.test(cart2) && !/Postar em colaboração/.test(cart2), cart2);
  S.amigos = [42]; await page.evaluate(() => { _amzDados.amigos = [42]; });

  console.log('\n══ Sem marcação nada muda ══');
  S.posts = [];
  await preparar('Fulano'); await preencher();
  await page.evaluate(() => { document.getElementById('fp-ok')?.remove(); _fpSalvar(); });
  await page.waitForSelector('#fp-ok');
  eq('sem @ não manda marcação e não mostra o cartão', [S.posts[0].mencoes, await page.$eval('#fp-colab', e => e.innerHTML)], [undefined, '']);

  console.log('\n══ Sem resultado e sem conexão: o app explica ══');
  await preparar('@zzzz');
  await page.waitForFunction(() => /Nenhum aluno encontrado/.test((document.getElementById('mencao-dd') || {}).textContent || ''), null, { timeout: 3000 });
  ver('nome que ninguém tem: diz "Nenhum aluno encontrado"', true);
  ver('a mensagem diz quantos alunos tinha na lista (para diagnosticar)', await page.evaluate(() => /\(2 na lista do servidor, 0 do ranking\)/.test(document.getElementById('mencao-dd').textContent)));
  // lista vazia guardada de uma chamada anterior: busca de novo antes de dizer que não achou
  S.buscas = 0;
  await page.evaluate(() => { document.getElementById('fp-sheet')?.remove(); _mencoesFechar(); _mencoesPessoas = []; _mencoesPessoasTs = Date.now(); _mencoesFalhou = false; abrirTreinoForaProtocolo(); });
  await page.waitForTimeout(300); await page.focus('#fp-desc'); await page.keyboard.type('@isa');
  await page.waitForFunction(() => /Isabela/.test((document.getElementById('mencao-dd') || {}).textContent || ''), null, { timeout: 4000 });
  ver('lista vazia em cache: refaz a busca uma vez e acha a Isabela', S.buscas >= 1, S.buscas);
  S.listaOk = false;
  await page.evaluate(() => { document.getElementById('fp-sheet')?.remove(); _mencoesFechar(); _mencoesPessoas = null; _mencoesPessoasTs = 0; _mencoesFalhou = false; _rankingData = { atletas: {}, sessoes: [], comentarios: [] }; _feedAtletasMap = {}; _amzDados = { amigos: [], recebidos: [], enviados: [], pessoas: {} }; abrirTreinoForaProtocolo(); });
  await page.waitForTimeout(300); await page.focus('#fp-desc'); await page.keyboard.type('@isa');
  await page.waitForFunction(() => /Não consegui carregar a lista/.test((document.getElementById('mencao-dd') || {}).textContent || ''), null, { timeout: 4000 });
  ver('lista de alunos que não carrega: diz que não conseguiu (e não fica mudo)', true);

  console.log('\n══ Lista do servidor vazia: usa os alunos que o app já conhece ══');
  S.listaVazia = true; S.listaOk = true;
  await page.evaluate(() => { document.getElementById('fp-sheet')?.remove(); _mencoesFechar(); _mencoesPessoas = null; _mencoesPessoasTs = 0; _mencoesFalhou = false; _rankingData = { atletas: { '42': 'Isabela Pazzinatto', '7': 'Aluna Teste' }, sessoes: [], comentarios: [] }; abrirTreinoForaProtocolo(); });
  await page.waitForTimeout(300); await page.focus('#fp-desc'); await page.keyboard.type('@isa');
  await page.waitForFunction(() => /Isabela/.test((document.getElementById('mencao-dd') || {}).textContent || ''), null, { timeout: 4000 });
  ver('servidor devolveu lista vazia: a Isabela vem do ranking que o app já tem', true);
  await page.evaluate(() => _mencoesEscolher(0));
  await page.evaluate(() => { document.getElementById('fp-dur').value = '45'; document.getElementById('fp-n-0').value = 'Supino'; document.getElementById('fp-c-0-0').value = '40'; document.getElementById('fp-r-0-0').value = '10'; });
  const mm = await page.evaluate(() => _fpMontar());
  eq('e a marcação sai igual (id e nome)', mm.resumo && mm.resumo.marcados, [{ id: 42, nome: 'Isabela Pazzinatto' }]);
  await page.evaluate(() => { document.getElementById('fp-sheet')?.remove(); _mencoesFechar(); abrirTreinoForaProtocolo(); });
  await page.waitForTimeout(300); await page.focus('#fp-desc'); await page.keyboard.type('@alun');
  await page.waitForFunction(() => /Nenhum aluno/.test((document.getElementById('mencao-dd') || {}).textContent || ''), null, { timeout: 4000 });
  ver('o próprio aluno nunca é sugerido (nem pelo ranking)', true);
  S.listaVazia = false;

  console.log('\n══ Aviso na lista de notificações ══');
  const l = await page.evaluate(() => _notifLinhaHtml({ idnotificacao: 5, tipo: 'mencao', alvo_tipo: 'sessao', alvo_id: 777, ator_id: 9, ator_nome: 'Maria Souza', texto: '@Isabela Pazzinatto', quando: '2026-10-20 21:00:00', lido: 0 }, false));
  ver('diz "marcou você num treino fora do protocolo"', /Maria Souza<\/b> marcou você num treino fora do protocolo/.test(l), l.slice(0, 300));
  ver('leva o id de quem marcou (abre o perfil dela)', /data-ator="9"/.test(l));

  console.log('\n══ Estrutura do servidor ══');
  const tre = fs.readFileSync(path.join(RAIZ, '../api/treinos.php'), 'utf8').replace(/\r\n/g, '\n');
  const i = tre.indexOf("!empty($b['mencoes'])");
  ver('só aluno logado e só treino FORA avisa', i > 0 && /\$_ehAlunoTr && strtoupper\(\(string\)\$divisao\) === 'FORA'/.test(tre.slice(i - 120, i + 80)));
  ver('usa a mesma checagem de menções do resto do app (pessoa existe, sem bloqueio)', tre.indexOf('_notificarMencoes($pdo, $texto', i) > i);
  ver('no máximo 4 pessoas por treino', /array_slice\(array_map\(fn\(\$m\) => '@\['[\s\S]{0,200}0, 4\)/.test(tre.slice(i, i + 900)));
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
