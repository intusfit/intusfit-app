// Quadros (kanban) de Treinos, Matrículas, Clientes e Nutrição, o perfil do cliente e o bloco de atendimento, no navegador com a API de mentira.
// Os quadros são SÓ visualização: nenhum pedido que grave pode sair ao montar ou usar o quadro; clicar em "Abrir" leva ao editor de sempre.
//   cd testes && INTUS_DIR=../app/painel node quadros.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const SITE = path.resolve(process.env.INTUS_DIR || '../app/painel', '../..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
const HOJE = '2026-10-23';
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };

const ATL = (id, nome, o) => Object.assign({ idatleta: id, nome, email: nome.split(' ')[0].toLowerCase() + '@x.com', telefone: '(45) 99999-000' + id, origem: 'professor', professores_responsaveis: [1, 5], stbloqueio: 'N', dtcadastro: '2026-08-01', genero: 'F', objetivos: [], dificuldades: [] }, o || {});
const ATLETAS = [
  ATL(1, 'Ana Souza'), ATL(2, 'Bia Lead', { origem: 'app', professores_responsaveis: [], dtcadastro: '2026-10-10' }), ATL(3, 'Carla Antiga'),
  ATL(4, 'Dani Bloqueada', { stbloqueio: 'S' }), ATL(5, 'Eva Sem Treino'), ATL(6, 'Fran Atrasada'), ATL(7, 'Gabi Vencendo'),
];
let mid = 1;
const MENS = (at, nome, ini, venc, pago, extra) => Object.assign({ idmensalidade: mid++, idatleta: at, nmathleta: nome, dshistorico: 'Personal 2x', recorrencia: 'mensal', dtinicio: ini, dtvencimento: venc, dtpagamento: pago || null, vlpagar: 500, stpgto: pago ? 'S' : 'N', stmatricula: 'ativa', forma_pgto: 'pix' }, extra || {});
const MENSALIDADES = [
  MENS(1, 'Ana Souza', '2026-10-01', '2026-11-01', '2026-10-01'),
  MENS(3, 'Carla Antiga', '2026-05-01', '2026-06-01', '2026-05-01'),
  MENS(5, 'Eva Sem Treino', '2026-10-20', '2026-11-20', null),
  MENS(6, 'Fran Atrasada', '2026-10-10', '2026-11-10', null),
  MENS(7, 'Gabi Vencendo', '2026-10-05', '2026-11-05', '2026-10-05'),
];
const FICHAS = [
  { idficha: 11, idatleta: 1, nmficha: 'Hipertrofia A', dtinicio: '2026-10-01', dtfim: '2026-12-31', ativo: 1 },
  { idficha: 12, idatleta: 1, nmficha: 'Ficha antiga', dtinicio: '2026-05-01', dtfim: '2026-06-01', ativo: 0 },
  { idficha: 13, idatleta: 6, nmficha: 'Ficha vencida', dtinicio: '2026-08-01', dtfim: '2026-10-01', ativo: 1 },
  { idficha: 14, idatleta: 7, nmficha: 'Termina logo', dtinicio: '2026-09-01', dtfim: '2026-10-28', ativo: 1 },
];
const PLANOS = [
  { idplano: 21, idatleta: 1, titulo: 'Plano Ana', ativo: 1, dtinicio: '2026-10-01', dtfim: '2026-12-01', refeicoes: [] },
  { idplano: 22, idatleta: 6, titulo: 'Plano Fran', ativo: 1, dtinicio: '2026-08-01', dtfim: '2026-10-01', refeicoes: [] },
  { idplano: 23, idatleta: 7, titulo: 'Plano Gabi', ativo: 1, dtinicio: '2026-09-01', dtfim: '2026-10-27', refeicoes: [] },
  { idplano: 24, idatleta: 5, titulo: 'Plano Eva', ativo: 0, dtinicio: '2026-09-01', dtfim: null, refeicoes: [] },
];
const PESSOA = (o) => Object.assign({ chave: 'a2', tipo: 'conta', id: 2, nome: 'Bia Lead', telefone: '(45) 99999-0002', whatsapp: '5545999990002', email: 'bia@x.com', etapa: 'so_conta', etapa_rotulo: 'Criou o acesso, nunca abriu o app', canal: 'instagram', utm: {}, indicado_por: null, criado_em: '2026-10-10 10:00:00', treinos: 0, origem_tipo: 'organico', acao: 'Dar boas-vindas.',
  wa: { contato: false, telefone_valido: true, saiu: false, enviadas: 0, recebidas: 0, respondeu: false }, controle: { ultimo: null, total: 0, historico: [] }, grupo_contato: 'nunca', temperatura: { nivel: 'frio', manual: false, auto: 'frio', motivos: [] },
  opcoes: { 't1': 'Oi Bia, seu teste está liberado.', 'chamou-whatsapp:c4': 'Oi Bia, posso liberar um teste.' }, passo_sugerido: 't1', recuperacao: null, passos_tratados: [] }, o || {});
const BIBLIOTECA = [{ passo: 't1', funil: 'teste-gratis', funil_nome: 'Teste grátis de 3 dias', funil_status: 'aprovado', mensagem: 't1', nome: 'Boas-vindas', aprovada: true }, { passo: 'chamou-whatsapp:c4', funil: 'chamou-whatsapp', funil_nome: 'Quem chama no WhatsApp', funil_status: 'rascunho', mensagem: 'c4', nome: 'Convite para o teste grátis', aprovada: false }];

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p.endsWith('/')) p += 'index.html';
    const f = path.join(SITE, p);
    if (!f.startsWith(SITE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8099, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8099';
  const browser = await chromium.launch();
  const json = (r, o, status) => r.fulfill({ status: status || 200, contentType: 'application/json', body: JSON.stringify(o) });

  async function abrir(url, { admin = true, vistas = {}, perfil = null } = {}) {
    const c = await browser.newContext({ viewport: { width: 1400, height: 900 }, serviceWorkers: 'block' });
    await c.addInitScript(({ HOJE, admin, vistas }) => {
      const _R = Date; window.Date = function (...a) { return a.length ? new _R(...a) : new _R(HOJE + 'T12:00:00'); };
      window.Date.prototype = _R.prototype; window.Date.now = () => new _R(HOJE + 'T12:00:00').getTime(); window.Date.UTC = _R.UTC; window.Date.parse = _R.parse;
      localStorage.setItem('mx-user', JSON.stringify({ idusuario: admin ? 1 : 5, nome: admin ? 'Luiz' : 'Professor', admin: admin, permissoes: {} }));
      localStorage.setItem('mx-token', 'x'.repeat(64));
      Object.keys(vistas).forEach(k => localStorage.setItem('intus-vista-' + k, vistas[k]));
    }, { HOJE, admin, vistas });
    const pg = await c.newPage(); const er = [], gravou = [], posts = [];
    pg.on('pageerror', e => { if (!/Failed to update a ServiceWorker/i.test(String(e.message))) er.push(String(e.message)); });
    // a tela de Treinos sempre sincroniza o catálogo-base de exercícios com o servidor ao abrir (isso já era assim e não tem a ver com o quadro): fica fora da conta
    pg.on('request', r => { if (r.method() !== 'GET' && /\/app\/api\//.test(r.url()) && !/treinos\.php\?action=exercicios/.test(r.url())) gravou.push(r.method() + ' ' + r.url().split('/app/api/')[1]); });
    await pg.route(u => u.href.includes('.php'), r => {
      const u = r.request().url(), m = r.request().method();
      if (m !== 'GET') { let b = {}; try { b = JSON.parse(r.request().postData() || '{}'); } catch (e) {} posts.push({ u, b }); return json(r, { ok: true, erro: null, ja: false, controle: { ultimo: null, total: 0, historico: [] } }); }
      if (u.includes('atendimento.php') && u.includes('action=perfil')) return perfil ? json(r, { ok: true, pessoa: perfil, biblioteca: BIBLIOTECA, agora: HOJE + ' 12:00:00' }) : json(r, { ok: false, erro: 'Pessoa não encontrada.' }, 404);
      if (u.includes('mensalidades.php')) return json(r, MENSALIDADES);
      if (u.includes('atletas.php')) { const id = /[?&]id=(\d+)/.exec(u); return json(r, id ? (ATLETAS.find(a => a.idatleta === +id[1]) || {}) : ATLETAS); }
      if (u.includes('treinos.php') && u.includes('action=fichas')) return json(r, FICHAS);
      if (u.includes('catalogo.php') && u.includes('action=nutricao')) return json(r, PLANOS);
      if (u.includes('catalogo.php') && u.includes('action=avaliacoes')) return json(r, []);
      if (u.includes('treinos.php') && u.includes('action=exercicios')) return json(r, [{ idexercicio: 1, nome: 'Supino reto', grupo: 'Peito' }, { idexercicio: 2, nome: 'Agachamento', grupo: 'Pernas' }]);
      if (u.includes('catalogo.php') && u.includes('action=alongamentos')) return json(r, [{ idalongamento: 1, nome: 'Peitoral' }]);
      return json(r, []);
    });
    await pg.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await pg.goto(B + url, { waitUntil: 'load' }); await pg.waitForTimeout(2200);
    return { pg, er, gravou, posts, c };
  }
  // SHOT_DIR=pasta: salva uma foto de cada tela (só para olhar; o teste não depende disso)
  const foto = async (pg, nome) => { if (process.env.SHOT_DIR) await pg.screenshot({ path: process.env.SHOT_DIR + '/' + nome + '.png' }); };
  const cols = (pg) => pg.evaluate(() => [...document.querySelectorAll('.kb-col')].map(c => c.getAttribute('aria-label') + ':' + c.querySelector('.kb-n').textContent));
  const nomesEm = (pg, col) => pg.evaluate((c) => [...document.querySelectorAll('.kb-col[data-col="' + c + '"] .kb-cartao-nome')].map(x => x.textContent).sort(), col);

  console.log('\n══ Treinos: quadro de fichas ══');
  const tr = await abrir('/app/painel/treinos.html', { vistas: { 'treinos-fichas': 'quadro' } });
  eq('colunas: sem treino, vencidas, vencem em 7 dias, em andamento e inativas', (await cols(tr.pg)).map(x => x.split(':')[0]), ['Aluno ativo sem treino', 'Fichas vencidas', 'Vencem em até 7 dias', 'Em andamento', 'Inativas']);
  eq('aluno ativo sem ficha aparece em "sem treino" (Eva); lead e inativa ficam fora', await nomesEm(tr.pg, 'sem'), ['Eva Sem Treino']);
  eq('ficha vencida sem substituta (Fran) vai para "vencidas"', await nomesEm(tr.pg, 'vencidas'), ['Fran Atrasada']);
  eq('ficha que termina em 5 dias (Gabi) vai para "vencem em até 7 dias"', await nomesEm(tr.pg, 'vencem'), ['Gabi Vencendo']);
  eq('ficha ativa dentro do prazo (Ana) em andamento, a desativada em inativas', [await nomesEm(tr.pg, 'andamento'), await nomesEm(tr.pg, 'inativas')], [['Ana Souza'], ['Ana Souza']]);
  eq('o quadro não grava nada', tr.gravou, []);
  await foto(tr.pg, 'quadro-treinos');
  eq('Treinos: o quadro rola por dentro, a página não cresce para o lado', await tr.pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
  await tr.pg.click('.kb-card:has-text("Hipertrofia A") [data-kbf]'); await tr.pg.waitForTimeout(500);
  eq('"Abrir ficha" leva ao editor de sempre, com a ficha certa', await tr.pg.evaluate(() => [view, Number(editFichaId)]), ['editor', 11]);
  eq('mesmo depois de abrir o editor nada foi gravado', tr.gravou, []);
  eq('sem erro de script em Treinos', tr.er, []);

  console.log('\n══ Matrículas: quadro ══');
  const mt = await abrir('/app/painel/mensalidades.html', { vistas: { 'matriculas': 'quadro' } });
  const colunasMat = await cols(mt.pg);
  eq('colunas de cobrança na ordem de importância', colunasMat.map(x => x.split(':')[0]), ['Pendentes', 'Vencidas', 'Aguardando renovação', 'A cobrar mais tarde', 'Ativas e em dia', 'Trancadas', 'Encerradas', 'Inativas e canceladas']);
  eq('Eva (cobrança em aberto no prazo) em Pendentes, Fran (passou da tolerância) em Vencidas, Ana e Gabi em dia', [await nomesEm(mt.pg, 'pendente'), await nomesEm(mt.pg, 'vencida'), await nomesEm(mt.pg, 'ok')], [['Eva Sem Treino'], ['Fran Atrasada'], ['Ana Souza', 'Gabi Vencendo']]);
  const bate = await mt.pg.evaluate(() => {
    const COL = { pendente: 'pendente', vencida: 'vencida', aguardando: 'aguardando', programada: 'programada', ativa_paga: 'ok', trancada: 'trancada', encerrada: 'fim', renovada: 'fim', inativa: 'inativa', cancelada: 'inativa' };
    return agruparMatriculas(mensalidades).every(mat => { const card = document.querySelector('.kb-card[data-id="' + mat.key + '"]'); return card && card.getAttribute('data-col') === (COL[statusEfetivo(mat.atual)] || 'pendente'); });
  });
  ver('cada cartão está na coluna que o selo da tabela (statusEfetivo, que lê API.situacaoCobranca) diria', bate);
  eq('a tabela fica escondida no modo quadro e o resumo continua no topo', await mt.pg.evaluate(() => [getComputedStyle(document.querySelector('table.data-table')).display, !!document.querySelector('.fin-summary')]), ['none', true]);
  eq('o quadro não grava nada', mt.gravou, []);
  await foto(mt.pg, 'quadro-matriculas');
  eq('Matrículas: o quadro rola por dentro, a página não cresce para o lado', await mt.pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
  if (false && process.env.DEBUG_LARGURA) console.log(JSON.stringify(await mt.pg.evaluate(() => { const out = []; let e = document.querySelector('#mat-quadro'); while (e && e !== document.documentElement) { out.push(e.tagName + '#' + e.id + '.' + e.className + ' w=' + Math.round(e.getBoundingClientRect().width) + ' disp=' + getComputedStyle(e).display); e = e.parentElement; } return { iw: innerWidth, sw: document.documentElement.scrollWidth, cadeia: out }; }), null, 1));
  await mt.pg.click('.kb-card:has-text("Eva Sem Treino") [data-kbm]'); await mt.pg.waitForTimeout(500);
  ver('"Abrir" abre o editor da matrícula (modal), sem gravar', await mt.pg.evaluate(() => !!document.querySelector('.modal-overlay, .modal')) && mt.gravou.length === 0, mt.gravou);
  await mt.pg.keyboard.press('Escape'); await mt.pg.evaluate(() => { if (typeof closeModal === 'function') closeModal(); });
  await mt.pg.click('.vm-bar button[title="Lista"]'); await mt.pg.waitForTimeout(800);
  ver('voltando para "Lista" a tabela de sempre aparece', await mt.pg.evaluate(() => getComputedStyle(document.querySelector('table.data-table')).display !== 'none' && !document.querySelector('.kb')));
  eq('sem erro de script em Matrículas', mt.er, []);

  console.log('\n══ Clientes: quadro e atendimento no cadastro ══');
  const cl = await abrir('/app/painel/alunos.html', { vistas: { 'clientes': 'quadro' }, perfil: PESSOA() });
  await cl.pg.evaluate(() => setFiltro('todos')); await cl.pg.waitForTimeout(600);
  eq('colunas: leads, novos, ativos, inativos, bloqueados', (await cols(cl.pg)).map(x => x.split(':')[0]), ['Leads', 'Novos (7 dias)', 'Ativos', 'Inativos', 'Bloqueados']);
  eq('lead, bloqueada e quem passou da carência (Carla sem plano, Fran com cobrança atrasada) nas colunas certas', [await nomesEm(cl.pg, 'lead'), await nomesEm(cl.pg, 'bloq'), await nomesEm(cl.pg, 'inativo')], [['Bia Lead'], ['Dani Bloqueada'], ['Carla Antiga', 'Fran Atrasada']]);
  const batiaCli = await cl.pg.evaluate(() => atletas.every(a => { const card = document.querySelector('.kb-card[data-id="' + a.idatleta + '"]'); const s = situacaoDoAluno(a); const col = s.bloqueado ? 'bloq' : isLead(a) ? 'lead' : isNovoCadastro(a) ? 'novo' : s.ativo ? 'ativo' : 'inativo'; return !card || card.getAttribute('data-col') === col; }));
  ver('cada cartão está onde o selo da tabela de Clientes colocaria', batiaCli);
  eq('o administrador vê o link "Perfil" nos cartões', await cl.pg.evaluate(() => document.querySelectorAll('.kb-card a[href^="perfil.html?id="]').length > 0), true);
  eq('o quadro não grava nada', cl.gravou, []);
  await foto(cl.pg, 'quadro-clientes');
  eq('Clientes: o quadro rola por dentro, a página não cresce para o lado', await cl.pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
  await cl.pg.click('.kb-card[data-id="2"] [data-kbc]'); await cl.pg.waitForTimeout(1500);
  eq('abrir o cadastro de um lead cai direto na aba Atendimento, com a faixa de temperatura no cabeçalho', await cl.pg.evaluate(() => [miniTabAtivo, !!document.querySelector('#mf-atend-faixa #pa-ft'), !!document.querySelector('#mini-tab-content #pa-temp')]), ['atendimento', true, true]);
  await foto(cl.pg, 'cadastro-atendimento');
  const textoAt = await cl.pg.innerText('#mini-tab-content');
  ver('mostra etapa, mensagens prontas já com o nome e os contatos', /Criou o acesso/.test(textoAt) && /Mandar mensagem/i.test(textoAt) && /Oi Bia, seu teste está liberado/.test(textoAt) && /Ninguém falou com esta pessoa/.test(textoAt), textoAt.slice(0, 300));
  const hrefZap = await cl.pg.getAttribute('#pa-zap', 'href');
  ver('o botão do WhatsApp leva a conversa com o número e o texto da mensagem escolhida', hrefZap.startsWith('https://wa.me/5545999990002?text=') && decodeURIComponent(hrefZap).includes('Oi Bia, seu teste está liberado'), hrefZap);
  await cl.pg.selectOption('#pa-msg', 'chamou-whatsapp:c4');
  eq('trocar a mensagem muda o texto e o link', [await cl.pg.innerText('#pa-prev'), decodeURIComponent(await cl.pg.getAttribute('#pa-zap', 'href')).includes('posso liberar um teste')], ['Oi Bia, posso liberar um teste.', true]);
  await cl.pg.selectOption('#pa-temp', 'quente'); await cl.pg.waitForTimeout(500);
  const pt = cl.posts.find(p => /manual_acao/.test(p.u));
  eq('mudar a temperatura grava só a chave, a ação e o valor', [pt && pt.b.chave, pt && pt.b.acao, pt && pt.b.valor], ['a2', 'temperatura', 'quente']);
  await cl.pg.click('#pa-enviada'); await cl.pg.waitForTimeout(500);
  const pe = cl.posts.filter(p => /manual_acao/.test(p.u)).pop();
  eq('"Já enviei" manda a mensagem escolhida e a que era sugerida', [pe.b.acao, pe.b.passo, pe.b.passo_sugerido, pe.b.telefone], ['enviada', 'chamou-whatsapp:c4', 't1', '5545999990002']);
  await cl.pg.selectOption('#pa-st', 'retornar'); await cl.pg.fill('#pa-nota', 'quer o preço'); await cl.pg.click('#pa-salvar'); await cl.pg.waitForTimeout(500);
  const pc = cl.posts.filter(p => /trafego\.php\?action=contato/.test(p.u)).pop();
  eq('anotar o resultado grava no mesmo registro de Pessoas Captadas', [pc.b.chave, pc.b.status, pc.b.nota, /^\d{4}-\d{2}-\d{2}$/.test(pc.b.retorno_em || '')], ['a2', 'retornar', 'quer o preço', true]);
  eq('o rodapé do cadastro tem o link para o perfil completo', await cl.pg.evaluate(() => !!document.querySelector('a[href="perfil.html?id=2"]')), true);
  eq('sem erro de script em Clientes', cl.er, []);

  console.log('\n══ Clientes: quem não é administrador ══');
  const cp = await abrir('/app/painel/alunos.html', { admin: false, vistas: { 'clientes': 'quadro' }, perfil: PESSOA() });
  await cp.pg.evaluate(() => setFiltro('todos')).catch(() => {}); await cp.pg.waitForTimeout(500);
  eq('professor não vê o link de perfil nos cartões e nenhuma chamada de atendimento sai', [await cp.pg.evaluate(() => document.querySelectorAll('a[href^="perfil.html"]').length), cp.posts.length], [0, 0]);

  console.log('\n══ Nutrição: quadro de planos ══');
  const nu = await abrir('/app/painel/nutricao.html', { vistas: { 'nutricao-planos': 'quadro' } });
  await nu.pg.evaluate(() => { aba = 'planos'; render(); }).catch(() => {}); await nu.pg.waitForTimeout(500);
  eq('colunas: vencidos, vencem em 7 dias, ativos e inativos', (await cols(nu.pg)).map(x => x.split(':')[0]), ['Planos vencidos', 'Vencem em até 7 dias', 'Ativos', 'Inativos']);
  eq('Fran vencido, Gabi vencendo, Ana ativa e Eva inativa', [await nomesEm(nu.pg, 'vencidos'), await nomesEm(nu.pg, 'vencem'), await nomesEm(nu.pg, 'ativos'), await nomesEm(nu.pg, 'inativos')], [['Fran Atrasada'], ['Gabi Vencendo'], ['Ana Souza'], ['Eva Sem Treino']]);
  eq('o quadro não grava nada', nu.gravou, []);
  await foto(nu.pg, 'quadro-nutricao');
  eq('Nutrição: o quadro rola por dentro, a página não cresce para o lado', await nu.pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), true);
  eq('sem erro de script em Nutrição', nu.er, []);

  console.log('\n══ Perfil completo ══');
  const pf = await abrir('/app/painel/perfil.html?id=1', { perfil: PESSOA({ chave: 'a1', id: 1, nome: 'Ana Souza', opcoes: { t1: 'Oi Ana.' } }) });
  await foto(pf.pg, 'perfil');
  const tpf = await pf.pg.innerText('#main-content');
  ver('cabeçalho com nome, situação, contato e professor', /Ana Souza/.test(tpf) && /Ativo/.test(tpf) && /99999-0001/.test(tpf), tpf.slice(0, 250));
  ver('seções de matrículas, treinos, nutrição e avaliações com links para as telas certas', /Matrículas/.test(tpf) && /Personal 2x/.test(tpf) && /Hipertrofia A/.test(tpf) && /Plano Ana/.test(tpf) && /Avaliações/.test(tpf) && (await pf.pg.evaluate(() => [...document.querySelectorAll('a')].map(a => a.getAttribute('href')))).some(h => /mensalidades\.html\?m=\d+/.test(h || '')) && (await pf.pg.evaluate(() => [...document.querySelectorAll('a')].map(a => a.getAttribute('href')))).some(h => h === 'treinos.html?ficha=11'), tpf.slice(0, 600));
  ver('o bloco de atendimento está no perfil', await pf.pg.evaluate(() => !!document.querySelector('#pf-atend #pa-temp')));
  eq('abrir o perfil não grava nada', pf.gravou, []);
  const pl = await abrir('/app/painel/perfil.html?chave=l7', { perfil: PESSOA({ chave: 'l7', tipo: 'contato', id: 7, nome: 'Lia Contato', etapa: 'contato', etapa_rotulo: 'Deixou só o contato' }) });
  const tpl = await pl.pg.innerText('#main-content');
  ver('contato sem conta: mostra o nome, avisa que não tem conta e traz o atendimento', /Lia Contato/.test(tpl) && /Contato sem conta/.test(tpl) && /Mandar mensagem/i.test(tpl) && !/Matrículas/.test(tpl), tpl.slice(0, 300));
  const pcli = await abrir('/app/painel/perfil.html?id=1', { perfil: PESSOA({ chave: 'a1', id: 1, nome: 'Ana Souza', etapa: 'cliente', etapa_rotulo: 'Virou cliente', temperatura: { nivel: 'cliente', manual: false, auto: 'cliente', motivos: ['já é cliente'] } }) });
  eq('cliente não tem temperatura para fixar (só a etiqueta Cliente)', await pcli.pg.evaluate(() => [!!document.querySelector('#pa-temp'), /Cliente/.test(document.querySelector('#pf-atend').innerText)]), [false, true]);
  const pn = await abrir('/app/painel/perfil.html?id=1', { admin: false });
  eq('quem não é administrador vê "Acesso restrito" e nenhuma chamada', [/Acesso restrito/.test(await pn.pg.innerText('#main-content')), pn.posts.length], [true, 0]);
  eq('sem erro de script no perfil', [pf.er, pl.er], [[], []]);

  await browser.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S) de ' + (ok + ruim) : 'OK: ' + ok + ' conferencias passaram.'));
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
