// "Ver perfil no app" (26/11/2026): no painel, o detalhe do aluno abre a própria tela de perfil do app, em modo somente leitura.
// O que este teste garante: abre como um colega vê; NADA do app encosta no armazenamento do painel; nenhum envio sai; só as leituras do perfil
// passam, e as que dependem de privacidade pedem como=colega; aluno comum com o mesmo endereço continua no app normal.
//   cd testes && INTUS_DIR=../app/painel node ver_perfil.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

const hoje = new Date().toISOString().slice(0, 10);
const RANKING = { atletas: { '42': 'Aluna Perfil Exemplo' }, avatars: {}, comentarios: [], sessoes: [{ idsessao: 1, idatleta: 42, dtsessao: hoje, tipo: 'musculacao', divisao: 'B', duracao_seg: 3600, pontos: 10 }] };
const POST = { idpost: 9, idatleta: 42, imagem: '', legenda: 'Texto público de exemplo', destino: 'feed', created_at: hoje + ' 10:00:00', midias: [], colaboradores: [] };

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1366, height: 900 }, serviceWorkers: 'block' });
  const pedidos = [];
  await ctx.route(/\/app\/api\//, route => {
    const r = route.request(), u = new URL(r.url()), acao = u.searchParams.get('action') || '';
    const noPerfil = /verperfil=/.test(r.frame() ? r.frame().url() : '');
    if (noPerfil) pedidos.push({ m: r.method(), arq: u.pathname.split('/').pop(), acao, como: u.searchParams.get('como') || '' });
    const j = (o) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(o) });
    if (acao === 'ranking') return j(RANKING);
    if (acao === 'perfil_config') return j({ meu: false, ver_fotos: true, mostrar_conquistas: true, mostrar_resultados: false, mostrar_semana: true, conexoes: true });
    if (acao === 'feed_posts') return j({ posts: [POST], atletas: { '42': 'Aluna Perfil Exemplo' }, avatars: {}, temMais: false });
    if (acao === 'conquistas_config') return j({});
    return route.abort('failed');
  });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(e.message));
  await page.addInitScript(() => { if (window !== window.top) return; try { if (!localStorage.getItem('mx-user')) { localStorage.setItem('mx-token', 'tok-equipe'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'professor', idusuario: 1, nome: 'Admin', perfil: 'admin' })); localStorage.setItem('intus-sessoes', '[{"marca":"do painel"}]'); localStorage.setItem('intus-fichas', '[{"marca":"do painel"}]'); } } catch (e) {} });
  await page.goto(base + '/frequencia.html', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(2000);
  const foto = () => page.evaluate(() => JSON.stringify(Object.keys(localStorage).sort().map(k => [k, localStorage.getItem(k)])));

  console.log('\n══ Painel: botão no detalhe do aluno ══');
  await page.evaluate(() => { atletas = [{ idatleta: 42, nome: 'Aluna Perfil Exemplo' }]; sessoes = []; abrirDetalheAluno(42); });
  eq('o detalhe tem o botão "Ver perfil no app"', await page.locator('.btn-ver-perfil').count(), 1);
  await page.waitForTimeout(1200);
  const antes = await foto();
  await page.locator('.btn-ver-perfil').click();
  await page.waitForTimeout(5000);
  const quadro = page.frames().find(f => /verperfil=42/.test(f.url()));
  eq('abre a tela do app numa moldura', !!quadro, true);
  const texto = await quadro.evaluate(() => (document.getElementById('vp-raiz') || {}).innerText || '');
  eq('mostra o nome do aluno', /Aluna Perfil Exemplo/.test(texto), true);
  eq('avisa que é somente visualização', /Somente visualização/.test(texto), true);
  eq('mostra a publicação visível', await quadro.evaluate(() => document.querySelectorAll('#perfil-rk-posts *').length > 0), true);
  eq('sem botão de amizade ou parceria', /Adicionar|Pedir amizade|parceir/i.test(texto), false);
  eq('o mural escondido pelo dono continua escondido', await quadro.evaluate(() => !!document.querySelector('[data-pk="res"] #perfil-rk-resultados')), false);

  console.log('\n══ Isolamento do painel ══');
  await quadro.evaluate(() => { localStorage.setItem('intus-sessoes', '[]'); localStorage.removeItem('mx-user'); localStorage.setItem('qualquer', '1'); });
  eq('o armazenamento do painel não mudou em nada', await foto(), antes);
  const envio = await quadro.evaluate(async () => { const r = await fetch(API_BASE + '/catalogo.php?action=reacoes', { method: 'POST', body: '{}' }); return r.status; });
  eq('qualquer envio é recusado antes de sair', envio, 403);
  const fora = await quadro.evaluate(async () => (await fetch(API_BASE + '/mensalidades.php')).status);
  eq('leitura fora da lista (ex.: mensalidades) não sai', fora, 503);
  eq('nenhum envio chegou à rede', pedidos.filter(x => x.m !== 'GET').length, 0);
  eq('só saíram leituras do perfil', pedidos.filter(x => !(x.arq === 'midia.php' || (x.arq === 'treinos.php' && x.acao === 'ranking') || (x.arq === 'catalogo.php' && ['perfil_config', 'feed_posts', 'resultados', 'conquistas_config', 'config', 'comentarios_pub', 'reacoes', 'figurinhas_config'].includes(x.acao)))).map(x => x.arq + ':' + x.acao), []);
  const priv = pedidos.filter(x => ['perfil_config', 'feed_posts', 'resultados'].includes(x.acao));
  eq('as leituras com privacidade pedem como=colega', [priv.length > 0, priv.every(x => x.como === 'colega')], [true, true]);

  await page.keyboard.press('Escape');
  eq('Esc fecha a moldura', await page.locator('#perfil-app-modal').count(), 0);

  console.log('\n══ Aluno comum com o mesmo endereço ══');
  const ctx2 = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  await ctx2.route(/\/app\/api\//, r => r.abort('failed'));
  const p2 = await ctx2.newPage();
  await p2.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
  await p2.goto(base + '/aluno.html?verperfil=42', { waitUntil: 'domcontentloaded' }); await p2.waitForTimeout(2500);
  eq('continua no app normal dela', await p2.evaluate(() => [!!window.__VER_PERFIL, !!document.getElementById('vp-raiz'), JSON.parse(localStorage.getItem('mx-user')).idatleta]), [false, false, 7]);

  eq('nenhum erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok' : ok + ' ok'));
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
