// Login do aluno e faixa de notificação (aluno.html, login.html, api.js), num Chromium com servidor de mentira:
//  - um 401 isolado não derruba o login (o servidor responde 401 também quando o banco engasga);
//  - o cookie de retomada traz o aluno de volta quando o navegador apagou o localStorage, e "Sair" não é desfeito por ele;
//  - a faixa "Você tem uma notificação nova" só aparece quando o SERVIDOR informa notificação nova.
//   cd testes && INTUS_DIR=../app/painel node login_aluno.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };
const TOKEN = 'a'.repeat(64);
const ATLETA = { idatleta: 7, nome: 'Aluna Teste', email: 'a@t.com', telefone: '45999', genero: 'F', server_token: TOKEN };

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html';
    const f = path.join(RAIZ, p);
    if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8094, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8094';
  const browser = await chromium.launch();
  async function nova(opts = {}) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    if (opts.logado) await ctx.addInitScript((a) => { if (!sessionStorage.getItem('__semeado')) { sessionStorage.setItem('__semeado', '1'); localStorage.setItem('mx-token', a.server_token); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: a.idatleta, nome: a.nome })); } }, ATLETA);
    const page = await ctx.newPage(); const chamadas = [], erros = [];
    page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, route => {
      const req = route.request(), u = req.url();
      chamadas.push({ u, metodo: req.method(), auth: req.headers()['authorization'] || '' });
      if (opts.api) return opts.api(route, u, req);
      route.abort('failed');
    });
    return { ctx, page, chamadas, erros };
  }

  const SONDA = /action=notificacoes&so_contagem=1/;
  const r401 = route => route.fulfill({ status: 401, contentType: 'application/json', body: '{"error":"token invalido"}' });
  const r200 = route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"nao_lidas":0}' });
  async function carregarApp(t) {
    await t.page.goto(B + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80 && !(await t.page.evaluate(() => typeof apiFetch === 'function' && typeof window._intusSessaoInvalida === 'function').catch(() => false)); i++) await t.page.waitForTimeout(250);
    await t.page.waitForTimeout(800);
  }
  console.log('\n══ Um 401 isolado não derruba o login ══');
  let t = await nova({ logado: true, api: (route, u) => { if (/zz_teste/.test(u)) { t.cont = (t.cont || 0) + 1; return t.cont === 1 ? r401(route) : route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"v":42}' }); } if (SONDA.test(u)) return r200(route); route.abort('failed'); } });
  await carregarApp(t);
  const r1 = await t.page.evaluate(() => apiFetch('/zz_teste.php').then(d => ({ ok: true, d }), e => ({ ok: false, m: e.message })));
  await t.page.waitForTimeout(1500);
  eq('o 401 do primeiro pedido é refeito e o segundo funciona', [r1.ok, r1.d && r1.d.v, t.cont], [true, 42, 2]);
  eq('o login continua guardado', await t.page.evaluate(() => !!localStorage.getItem('mx-token')), true);
  await t.ctx.close();

  console.log('\n══ 401 que persiste, mas a sonda diz que a sessão vale (servidor engasgado) ══');
  t = await nova({ logado: true, api: (route, u) => /zz_teste/.test(u) ? r401(route) : SONDA.test(u) ? r200(route) : route.abort('failed') });
  await carregarApp(t);
  const r2 = await t.page.evaluate(() => apiFetch('/zz_teste.php').then(d => ({ ok: true }), e => ({ ok: false, status: e.status, m: e.message })));
  await t.page.waitForTimeout(500);
  eq('o pedido falha com aviso de servidor lento (503), sem derrubar o login', [r2.ok, r2.status, /\/login\.html/.test(t.page.url()), await t.page.evaluate(() => !!localStorage.getItem('mx-token'))], [false, 503, false, true]);
  await t.ctx.close();

  console.log('\n══ Fetch solto com 401 "token": só desloga se a sonda confirmar ══');
  t = await nova({ logado: true, api: (route, u) => /zz_solto/.test(u) ? r401(route) : SONDA.test(u) ? r200(route) : route.abort('failed') });
  await carregarApp(t);
  await t.page.evaluate(() => fetch('/app/api/zz_solto.php', { headers: { 'Authorization': 'Bearer ' + localStorage.getItem('mx-token') } }));
  await t.page.waitForTimeout(1500);
  eq('sonda diz que vale: o login fica', [await t.page.evaluate(() => !!localStorage.getItem('mx-token')), /\/aluno\.html/.test(t.page.url())], [true, true]);
  await t.ctx.close();
  t = await nova({ logado: true, api: (route, u) => /zz_solto/.test(u) || SONDA.test(u) ? r401(route) : route.abort('failed') });
  await carregarApp(t);
  const sondas0 = () => t.chamadas.filter(c => SONDA.test(c.u)).length;
  await t.page.evaluate(() => fetch('/app/api/zz_solto.php', { headers: { 'Authorization': 'Bearer ' + localStorage.getItem('mx-token') } }));
  await t.page.waitForTimeout(3000);
  eq('antes de 7 s o login ainda está lá (a sonda ainda está conferindo)', await t.page.evaluate(() => !!localStorage.getItem('mx-token')), true);
  await t.page.waitForFunction(() => !localStorage.getItem('mx-token'), null, { timeout: 15000 }).catch(() => {});
  eq('sonda dizendo "token inválido" 3 vezes seguidas: aí sim sai', [await t.page.evaluate(() => !localStorage.getItem('mx-token')), sondas0() >= 3], [true, true]);
  await t.ctx.close();

  console.log('\n══ Servidor insistindo em 401 de verdade: sai ══');
  t = await nova({ logado: true, api: (route, u) => /zz_teste/.test(u) || SONDA.test(u) ? r401(route) : route.abort('failed') });
  await carregarApp(t);
  await Promise.all([t.page.waitForURL(/login\.html/, { timeout: 25000 }).catch(() => {}), t.page.evaluate(() => apiFetch('/zz_teste.php').catch(() => {})).catch(() => {})]);
  eq('401 no pedido, na repetição e nas 3 sondas levam ao login', [/login\.html/.test(t.page.url()), t.chamadas.filter(c => /zz_teste/.test(c.u)).length], [true, 2]);
  await t.ctx.close();

  console.log('\n══ Cookie de retomada (navegador apagou o localStorage) ══');
  t = await nova({ api: (route, u) => /action=retomar/.test(u) ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, atleta: ATLETA }) }) : route.abort('failed') });
  await t.page.goto(B + '/login.html', { waitUntil: 'domcontentloaded' });
  await t.page.waitForURL(/aluno\.html/, { timeout: 15000 }).catch(() => {});
  eq('sem localStorage, a tela de login retoma sozinha e vai para o app', /aluno\.html/.test(t.page.url()), true);
  eq('guarda a mesma sessão que o servidor devolveu', await t.page.evaluate(() => [localStorage.getItem('mx-token'), JSON.parse(localStorage.getItem('mx-user')).idatleta, JSON.parse(localStorage.getItem('mx-user')).tipo]), [TOKEN, 7, 'aluno']);
  eq('o pedido de retomar é POST', t.chamadas.find(c => /retomar/.test(c.u)).metodo, 'POST');
  await t.ctx.close();

  t = await nova({ api: (route, u) => /action=retomar/.test(u) ? route.fulfill({ status: 401, contentType: 'application/json', body: '{"ok":false}' }) : route.abort('failed') });
  await t.page.goto(B + '/login.html', { waitUntil: 'domcontentloaded' }); await t.page.waitForTimeout(1200);
  eq('sem cookie válido, fica no formulário (sem laço)', [/login\.html/.test(t.page.url()), await t.page.evaluate(() => !localStorage.getItem('mx-token')), await t.page.locator('#senha').count()], [true, true, 1]);
  await t.ctx.close();

  t = await nova({ api: (route, u) => /action=retomar/.test(u) ? route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, atleta: ATLETA }) }) : route.abort('failed') });
  await t.page.goto(B + '/login.html?saiu=1', { waitUntil: 'domcontentloaded' }); await t.page.waitForTimeout(1200);
  eq('depois de "Sair" (?saiu=1) NÃO retoma, mesmo com cookie', [/login\.html/.test(t.page.url()), t.chamadas.some(c => /retomar/.test(c.u))], [true, false]);
  await t.ctx.close();

  console.log('\n══ "Sair" avisa o servidor ══');
  t = await nova({ logado: true, api: (route, u) => /action=esquecer/.test(u) ? route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }) : route.abort('failed') });
  await t.page.goto(B + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80 && !(await t.page.evaluate(() => typeof sair === 'function').catch(() => false)); i++) await t.page.waitForTimeout(250);
  await Promise.all([t.page.waitForURL(/login\.html/, { timeout: 15000 }).catch(() => {}), t.page.evaluate(() => sair())]);
  await t.page.waitForTimeout(600);
  const esq = t.chamadas.find(c => /action=esquecer/.test(c.u));
  ver('"Sair" manda o pedido de esquecer com a sessão atual', esq && esq.metodo === 'POST' && esq.auth === 'Bearer ' + TOKEN, esq);
  eq('vai para o login com ?saiu=1 e apaga o login do aparelho', [/login\.html\?saiu=1/.test(t.page.url()), await t.page.evaluate(() => localStorage.getItem('mx-token'))], [true, null]);
  eq('e a tela de login não retoma sozinha', t.chamadas.some(c => /retomar/.test(c.u)), false);
  await t.ctx.close();

  console.log('\n══ Faixa "Você tem uma notificação nova" ══');
  t = await nova({ logado: true });
  await t.page.goto(B + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80 && !(await t.page.evaluate(() => typeof _notifPintarBadge === 'function').catch(() => false)); i++) await t.page.waitForTimeout(250);
  await t.page.waitForTimeout(1500);
  const faixa = () => t.page.evaluate(() => { const e = document.getElementById('notif-aviso'); if (!e) return { existe: false }; const b = e.getBoundingClientRect(); return { existe: true, visivel: b.bottom > 0 && getComputedStyle(e).transform !== 'none' && b.top >= 0, topo: Math.round(b.top), alt: Math.round(b.height), txt: e.innerText }; });
  await t.page.evaluate(() => { _notifSrvUltima = null; _notifSrvAtual = 0; _notifAvisoEm = 0; document.getElementById('notif-aviso')?.remove(); _notifPintarBadge(2); });
  eq('1ª leitura do servidor não avisa', (await faixa()).existe, false);
  await t.page.evaluate(() => { localStorage.setItem('intus-mensagens', JSON.stringify([1, 2, 3, 4, 5].map(i => ({ idmensagem: i, idatleta: 7, remetente: 'professor', stlido: 'N' })))); _notifPintarBadge(2); });
  eq('mensagens antigas do professor carregando ao trocar de tela NÃO acendem a faixa (era o defeito)', (await faixa()).existe, false);
  eq('mas o número do sino soma as mensagens (2 do servidor + 5 do professor)', await t.page.evaluate(() => document.getElementById('notif-count').textContent), '7');
  await t.page.evaluate(() => _notifPintarBadge(undefined));
  eq('resposta sem número (erro do servidor) não vira "zero" nem avisa', [(await faixa()).existe, await t.page.evaluate(() => document.getElementById('notif-count').textContent)], [false, '7']);
  await t.page.evaluate(() => _notifPintarBadge(3));
  await t.page.waitForTimeout(600);
  const f = await faixa();
  ver('notificação nova do servidor (2 para 3) acende a faixa', f.existe && /uma notificação nova/i.test(f.txt), f);
  ver('a faixa é compacta e fica inteira dentro da tela', f.alt <= 64 && f.topo >= 0, f);
  await t.page.evaluate(() => { document.getElementById('notif-aviso').remove(); _notifPintarBadge(3); });
  eq('a mesma contagem de novo não repete o aviso', (await faixa()).existe, false);
  await t.page.evaluate(() => { _notifPintarBadge(4); });
  eq('e dentro de 20 s de outro aviso não repete', (await faixa()).existe, false);
  eq('sem erro de script', t.erros, []);
  await t.ctx.close();

  await browser.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
