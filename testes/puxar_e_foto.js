// A foto do próprio aluno e o "puxar para atualizar" (27/11/2026).
// Garante: numa instalação nova a foto do topo vem do servidor; foto ainda não enviada não é apagada nem perde a marca sem confirmação;
// arrastar para baixo no topo busca de novo feed, ranking, mensagens, notificações, treinos e foto; gesto curto, para cima, de lado, com a
// tela rolada ou com janela por cima não faz nada.
//   cd testes && INTUS_DIR=../app/painel node puxar_e_foto.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

const FOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhWMjWwAAAABJRU5ErkJggg==';
const NOVA = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
const hoje = new Date().toISOString().slice(0, 10);

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();

  const servidor = { avatar: FOTO, putOk: true, pedidos: [], legenda: 'Primeira legenda' };
  async function abrir(perfilLocal, tema) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, serviceWorkers: 'block' });
    await ctx.route(/\/app\/api\//, route => {
      const r = route.request(), u = new URL(r.url()), acao = u.searchParams.get('action') || '', arq = u.pathname.split('/').pop();
      servidor.pedidos.push({ m: r.method(), arq, acao, url: r.url() });
      const j = (o, st) => route.fulfill({ status: st || 200, contentType: 'application/json', body: JSON.stringify(o) });
      if (acao === 'profile' && r.method() === 'GET') return j({ avatar: servidor.avatar, prefs: {} });
      if (acao === 'profile') { if (!servidor.putOk) return route.abort('failed'); servidor.avatar = JSON.parse(r.postData()).avatar; return j({ ok: true }); }
      if (acao === 'ranking') return j({ atletas: { '7': 'Aluna Teste' }, avatars: {}, comentarios: [], sessoes: [] });
      if (acao === 'feed_posts') return j({ posts: [{ idpost: 5, idatleta: 7, imagem: '', legenda: servidor.legenda, destino: 'feed', created_at: hoje + ' 10:00:00', midias: [], colaboradores: [] }], atletas: { '7': 'Aluna Teste' }, avatars: {}, temMais: false });
      if (arq === 'mensagens.php') return j([]);
      if (acao === 'notificacoes') return j({ notificacoes: [], nao_lidas: 0 });
      return j({ error: 'fora do teste' }, 503);
    });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(e.message));
    await page.addInitScript(([perfil, tema]) => {
      if (localStorage.getItem('mx-token')) return;
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' }));
      localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1');
      localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-ajuda-v2', '{"visto":1}'); localStorage.setItem('intus-theme', tema);
      if (perfil) localStorage.setItem('intus-profile-7', perfil);
    }, [perfilLocal ? JSON.stringify(perfilLocal) : '', tema || 'escuro']);
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(3000);
    // janelas de primeira abertura fora do caminho
    await page.evaluate(() => { document.querySelectorAll('body > div').forEach(d => { const s = getComputedStyle(d); if (s.position === 'fixed' && d.id !== 'puxar-bola' && d.id !== 'menu-overlay' && s.display !== 'none' && d.offsetHeight > 300) d.remove(); }); if (!document.querySelector('#v-home.ativo')) { trocarView('home'); renderHome(); } window.scrollTo(0, 0); });
    await page.waitForTimeout(600);
    const cdp = await ctx.newCDPSession(page);
    return { ctx, page, cdp, erros };
  }
  const foto = (page) => page.evaluate(() => { const i = document.querySelector('.top .logo-img'); return { avatar: i.classList.contains('avatar'), src: i.src.startsWith('data:') ? i.src : i.src.split('/').pop() }; });
  const local = (page) => page.evaluate(() => JSON.parse(localStorage.getItem('intus-profile-7') || '{}'));

  console.log('\n══ A foto numa instalação nova ══');
  let t = await abrir(null);
  eq('o topo mostra a foto que está no servidor', await foto(t.page), { avatar: true, src: FOTO });
  eq('e ela fica guardada no aparelho', (await local(t.page)).avatar, FOTO);
  await t.page.evaluate(() => { renderPerfil(); trocarView('perfil'); });
  eq('a tela Perfil também mostra', await t.page.evaluate(() => (document.querySelector('#v-perfil .profile-avatar img') || {}).src || ''), FOTO);
  await t.page.evaluate(() => { trocarView('home'); renderHome(); window.scrollTo(0, 0); });
  await t.page.waitForTimeout(800);

  console.log('\n══ Puxar para atualizar ══');
  async function arrastar(x, y, dx, dy, aoMeio) {
    const toque = (type, px, py) => t.cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x: px, y: py }] });
    await toque('touchStart', x, y);
    for (let k = 1; k <= 12; k++) { await toque('touchMove', x + dx * k / 12, y + dy * k / 12); await t.page.waitForTimeout(16); }
    const meio = aoMeio ? await aoMeio() : null;
    await toque('touchEnd');
    return meio;
  }
  const bola = () => t.page.evaluate(() => { const e = document.getElementById('puxar-bola'); return { visivel: getComputedStyle(e).opacity !== '0', pronto: e.classList.contains('pronto'), girando: e.classList.contains('girando') }; });
  const buscas = () => servidor.pedidos.filter(p => p.acao === 'ranking').length;
  async function nada(rot, fn) {
    await t.page.waitForTimeout(500); servidor.pedidos.length = 0;
    await fn(); await t.page.waitForTimeout(900);
    eq(rot, [buscas(), servidor.pedidos.filter(p => p.acao === 'profile').length], [0, 0]);
  }
  await nada('arrasto curto não atualiza', () => arrastar(195, 300, 0, 70));
  await nada('arrasto para o lado não atualiza', () => arrastar(80, 300, 250, 60));
  await nada('com a tela rolada não atualiza', async () => { await t.page.evaluate(() => { document.getElementById('v-home').style.minHeight = '2500px'; window.scrollTo(0, 600); }); await arrastar(195, 300, 0, 300); });
  await t.page.evaluate(() => window.scrollTo(0, 0)); await t.page.waitForTimeout(300);
  await nada('com o menu aberto não atualiza', async () => { await t.page.evaluate(() => toggleMenu()); await arrastar(195, 300, 0, 300); await t.page.evaluate(() => { document.getElementById('menu-overlay').classList.remove('open'); _destravarFundoMenu(true); }); });
  await nada('com uma janela por cima não atualiza', async () => { await t.page.evaluate(() => { const d = document.createElement('div'); d.id = 'janela-teste'; d.style.cssText = 'position:fixed;inset:0;z-index:100000;background:#000'; document.body.appendChild(d); }); await arrastar(195, 300, 0, 300); await t.page.evaluate(() => document.getElementById('janela-teste').remove()); });
  await nada('fora das telas da lista (Perfil) não atualiza', async () => { await t.page.evaluate(() => { renderPerfil(); trocarView('perfil'); window.scrollTo(0, 0); }); await t.page.waitForTimeout(500); servidor.pedidos.length = 0; await arrastar(195, 300, 0, 300); await t.page.evaluate(() => { trocarView('home'); renderHome(); window.scrollTo(0, 0); }); await t.page.waitForTimeout(500); servidor.pedidos.length = 0; });

  await t.page.waitForTimeout(1500);
  servidor.pedidos.length = 0; servidor.legenda = 'Legenda nova do servidor'; servidor.avatar = NOVA;
  const meio = await arrastar(195, 300, 0, 280, bola);
  eq('durante o arrasto o círculo aparece e avisa que já pode soltar', meio, { visivel: true, pronto: true, girando: false });
  await t.page.waitForTimeout(3500);
  const feito = (f) => servidor.pedidos.some(f);
  eq('busca o ranking e as medalhas', feito(p => p.acao === 'ranking'), true);
  eq('busca o feed', feito(p => p.acao === 'feed_posts'), true);
  eq('busca as mensagens', feito(p => p.arq === 'mensagens.php'), true);
  eq('busca as notificações', feito(p => p.acao === 'notificacoes'), true);
  eq('busca os treinos', feito(p => p.acao === 'fichas'), true);
  eq('a foto trocada em outro aparelho chega ao topo', (await foto(t.page)).src, NOVA);
  eq('o texto novo do post aparece no Início', await t.page.evaluate(() => /Legenda nova do servidor/.test(document.getElementById('h-feed-home').innerText)), true);
  eq('o círculo some no final', (await bola()).visivel, false);

  for (const [tela, abre, marca] of [['feed', "navegar('feed')", 'feed_posts'], ['ranking', "abrirRanking()", 'ranking'], ['ranking', "abrirQuadroMedalhas()", 'ranking'], ['mensagens', "navegar('mensagens')", 'notificacoes'], ['amigos', "renderAmigos();trocarView('amigos')", 'amizades']]) {
    await t.page.evaluate(c => eval(c), abre); await t.page.waitForTimeout(2200); await t.page.evaluate(() => window.scrollTo(0, 0)); await t.page.waitForTimeout(300);
    await t.page.waitForFunction(() => !_puxarRodando, null, { timeout: 40000 });   // a atualização da tela anterior já terminou
    servidor.pedidos.length = 0;
    await arrastar(195, 300, 0, 280); await t.page.waitForTimeout(3000);
    eq('na tela ' + abre + ' puxar busca de novo e continua nela', [servidor.pedidos.some(p => p.acao === marca), await t.page.evaluate(() => window._viewAtual)], [true, tela]);
  }
  eq('nenhum erro de JavaScript', t.erros, []);
  await t.ctx.close();

  console.log('\n══ Foto que ainda não chegou ao servidor ══');
  servidor.avatar = FOTO; servidor.putOk = false; servidor.pedidos.length = 0;
  t = await abrir({ avatar: NOVA, _avatarPendingSync: true }, 'claro');
  let l = await local(t.page);
  eq('sem conexão no envio, a foto nova continua e segue marcada para enviar', [l.avatar === NOVA, l._avatarPendingSync === true, (await foto(t.page)).src === NOVA], [true, true, true]);
  servidor.putOk = true;
  await t.page.evaluate(() => _meuPerfilSincronizar()); await t.page.waitForTimeout(500);
  l = await local(t.page);
  eq('quando o envio funciona, o servidor recebe e a marca sai', [servidor.avatar === NOVA, !!l._avatarPendingSync], [true, false]);
  eq('nenhum erro de JavaScript', t.erros, []);
  await t.ctx.close();

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok' : ok + ' ok'));
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
