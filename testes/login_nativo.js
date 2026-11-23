// login.html no app das lojas: o login precisa chamar o servidor pelo endereço absoluto (window.INTUS_API_BASE, definido por
// mobile/scripts/build-web.mjs). Com caminho relativo, no Capacitor a chamada cairia em capacitor://localhost e todo login
// falharia com "Não conseguimos falar com o servidor".
//   cd testes && INTUS_DIR=../app/painel node login_nativo.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
const VISTO = () => { try { localStorage.setItem('intus-intro-vista', '1'); } catch (e) {} };   // a introdução só aparece na primeira visita
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

async function tentarLogin(nativo) {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/login.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const porta = srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.addInitScript(VISTO);
  const chamadas = [];
  // a tela chama aluno_login.php?action=retomar ao abrir (retomar sessão); só a chamada de login nos interessa
  await page.route(/aluno_login\.php/, r => { if (!/action=/.test(r.request().url())) chamadas.push(r.request().url()); r.fulfill({ status: 401, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' }, body: '{"erro":"Credenciais inválidas"}' }); });
  if (nativo) await page.addInitScript(() => { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://api.exemplo.test/app/api'; });
  await page.goto('http://127.0.0.1:' + porta + '/login.html', { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#email'); await page.fill('#email', 'aluno@teste.com'); await page.fill('#senha', 'x'); await page.click('#btn');
  await page.waitForTimeout(800);
  const msg = await page.evaluate(() => (document.body.innerText || '').replace(/\s+/g, ' '));
  await b.close(); srv.close();
  return { chamadas, msg, erros, porta };
}

(async () => {
  console.log('\n══ App das lojas: login chama o servidor absoluto ══');
  const n = await tentarLogin(true);
  eq('a chamada vai para o endereço absoluto do servidor', n.chamadas, ['https://api.exemplo.test/app/api/aluno_login.php']);
  eq('recusa de credenciais aparece como tal, não como "falar com o servidor"', /incorretos/.test(n.msg) && !/falar com o servidor/.test(n.msg), true);
  eq('sem erro de JavaScript', n.erros, []);

  console.log('\n══ Web: continua relativo, mesma origem da página ══');
  const w = await tentarLogin(false);
  eq('a chamada usa a mesma origem da página', w.chamadas, ['http://127.0.0.1:' + w.porta + '/app/api/aluno_login.php']);

  console.log('\n══ Acesso de professor só pelo link específico ══');
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/login.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const base = 'http://127.0.0.1:' + srv.address().port + '/login.html';
  const b2 = await chromium.launch();
  async function estado(url, nativo) {
    const ctx = await b2.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); await page.addInitScript(VISTO);
    if (nativo) await page.addInitScript(() => { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://api.exemplo.test/app/api'; });
    await page.goto(url, { waitUntil: 'domcontentloaded' }); await page.waitForSelector('#email'); await page.waitForTimeout(300);
    const r = await page.evaluate(() => ({ botaoVisivel: !!document.getElementById('btn-toggle-modo').offsetParent, modoProf: document.body.classList.contains('modo-professor'), titulo: document.getElementById('titulo-card').textContent, frase: /em breve teremos|disponível nas lojas|salve na tela inicial/i.test(document.body.innerText) }));
    await ctx.close(); return r;
  }
  let e = await estado(base, false);
  eq('tela de cliente na web: sem o botão "Sou professor" e sem modo professor', [e.botaoVisivel, e.modoProf, e.titulo], [false, false, 'Entrar']);
  eq('a frase "em breve nas lojas" não aparece mais na web', e.frase, false);
  e = await estado(base + '?professor=1', false);
  eq('link ?professor=1 abre o acesso do professor', [e.modoProf, e.titulo], [true, 'Acesso do professor']);
  e = await estado(base + '#professor', false);
  eq('link #professor também abre o acesso do professor', e.modoProf, true);
  e = await estado(base + '?professor=1', true);
  eq('no app das lojas o modo professor não existe nem pelo link', [e.botaoVisivel, e.modoProf], [false, false]);
  eq('a frase "em breve nas lojas" não aparece no app', e.frase, false);
  console.log('\n══ Botões de acesso rápido do tamanho da caixa de login ══');
  const ctxP = await b2.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
  const pg = await ctxP.newPage(); await pg.addInitScript(VISTO);
  await pg.addInitScript(() => { window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://api.exemplo.test/app/api'; window.Capacitor = { getPlatform: () => 'ios', Plugins: { SignInWithApple: {} } }; });
  await pg.goto(base, { waitUntil: 'domcontentloaded' }); await pg.waitForSelector('#btn-apple'); await pg.waitForTimeout(300);
  const med = await pg.evaluate(() => { const r = s => { const b = document.querySelector(s).getBoundingClientRect(); return { w: Math.round(b.width), h: Math.round(b.height) }; }; const cs = s => getComputedStyle(document.querySelector(s)).borderRadius; return { entrar: r('#btn'), apple: r('#btn-apple'), campo: r('#email'), raioEntrar: cs('#btn'), raioApple: cs('#btn-apple') }; });
  eq('o botão da Apple tem a largura do ENTRAR e do campo de e-mail', [med.apple.w === med.entrar.w, med.apple.w === med.campo.w], [true, true]);
  eq('o botão da Apple tem a altura do ENTRAR (52 px) e o mesmo raio', [med.apple.h, med.entrar.h, med.raioApple === med.raioEntrar], [52, 52, true]);
  const sup = await pg.evaluate(() => { const a = document.getElementById('btn-suporte'); const r = a.getBoundingClientRect(); return { href: a.href, texto: a.innerText.trim(), visivel: !!a.offsetParent, h: Math.round(r.height), alvo: a.target }; });
  eq('o botão "Falar com o suporte" aparece e abre o WhatsApp da Intus em outra janela', [sup.visivel, sup.texto, /^https:\/\/wa\.me\/5545991156006\?text=/.test(sup.href), sup.alvo], [true, 'Falar com o suporte', true, '_blank']);
  await pg.screenshot({ path: process.env.INTUS_PRINT || '', fullPage: true }).catch(() => {});
  console.log('\n══ Google e Facebook no app do iPhone (plugin nativo simulado) ══');
  async function nativo(facebookResp) {
    const ctx = await b2.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); await page.addInitScript(VISTO); const chamadas = [], erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/aluno_login\.php\?action=oauth_/, r => { chamadas.push({ url: r.request().url(), corpo: JSON.parse(r.request().postData() || '{}') }); r.fulfill({ status: 401, contentType: 'application/json', headers: { 'Access-Control-Allow-Origin': '*' }, body: '{"ok":false,"erro":"recusado no teste"}' }); });
    await page.addInitScript((fb) => {
      window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://api.exemplo.test/app/api';
      window.__sl = { init: null, logins: [] };
      window.Capacitor = { getPlatform: () => 'ios', Plugins: { SocialLogin: {
        initialize: async (o) => { window.__sl.init = o; },
        login: async (o) => { window.__sl.logins.push(o); return o.provider === 'google' ? { provider: 'google', result: { idToken: 'ID-TOKEN-GOOGLE-TESTE' } } : fb; },
      } } };
    }, facebookResp);
    await page.goto(base, { waitUntil: 'domcontentloaded' }); await page.waitForSelector('#btn-google-nativo'); await page.waitForTimeout(300);
    const vis = await page.evaluate(() => ({ google: !!document.getElementById('btn-google-nativo').offsetParent, fb: !!document.getElementById('btn-facebook').offsetParent, web: !!document.getElementById('google-btn-mount').offsetParent, larg: [document.getElementById('btn-google-nativo').getBoundingClientRect().width === document.getElementById('btn').getBoundingClientRect().width, Math.round(document.getElementById('btn-google-nativo').getBoundingClientRect().height)] }));
    await page.click('#btn-google-nativo'); await page.waitForTimeout(500);
    const msgG = await page.evaluate(() => document.getElementById('erro').innerText);
    await page.click('#btn-facebook'); await page.waitForTimeout(500);
    const msgF = await page.evaluate(() => document.getElementById('erro').innerText);
    const sl = await page.evaluate(() => window.__sl);
    await ctx.close(); return { vis, chamadas, sl, msgG, msgF, erros };
  }
  let nv = await nativo({ provider: 'facebook', result: { accessToken: { token: 'TOKEN-FB-TESTE' }, isLimitedLogin: false } });
  eq('no app aparecem Google e Facebook nativos (e o botão da web do Google não)', [nv.vis.google, nv.vis.fb, nv.vis.web], [true, true, false]);
  eq('o botão do Google tem a largura do ENTRAR e 52 px', nv.vis.larg, [true, 52]);
  eq('o plugin é iniciado com o cliente iOS do Google e o app e token do Facebook', [nv.sl.init && nv.sl.init.google.iOSClientId, nv.sl.init && nv.sl.init.facebook.appId, !!(nv.sl.init && nv.sl.init.facebook.clientToken)], ['534896999126-jmkj1iokmb9b9gk7h02bcnuvgk7mo5gt.apps.googleusercontent.com', '1820818379134680', true]);
  eq('Google pede e-mail e perfil e envia o idToken ao servidor', [nv.sl.logins[0].provider, nv.sl.logins[0].options.scopes, nv.chamadas[0] && nv.chamadas[0].url, nv.chamadas[0] && nv.chamadas[0].corpo], ['google', ['email', 'profile'], 'https://api.exemplo.test/app/api/aluno_login.php?action=oauth_google', { credential: 'ID-TOKEN-GOOGLE-TESTE' }]);
  eq('Facebook pede e-mail e perfil, sem login limitado, e envia o token de acesso', [nv.sl.logins[1].provider, nv.sl.logins[1].options.permissions, nv.sl.logins[1].options.limitedLogin, nv.chamadas[1] && nv.chamadas[1].url, nv.chamadas[1] && nv.chamadas[1].corpo], ['facebook', ['email', 'public_profile'], false, 'https://api.exemplo.test/app/api/aluno_login.php?action=oauth_facebook', { access_token: 'TOKEN-FB-TESTE' }]);
  eq('a recusa do servidor aparece na tela', [nv.msgG, nv.msgF], ['recusado no teste', 'recusado no teste']);
  eq('sem erro de JavaScript', nv.erros, []);
  nv = await nativo({ provider: 'facebook', result: { accessToken: null, idToken: 'JWT-LIMITADO', isLimitedLogin: true } });
  eq('login limitado do Facebook (sem token de acesso) NÃO chega ao servidor e explica o que fazer', [nv.chamadas.filter(c => /facebook/.test(c.url)).length, /Facebook neste aparelho/.test(nv.msgF)], [0, true]);


  console.log('\n══ Introdução (2 telas) e tema claro ══');
  async function nova(url, opts) {
    opts = opts || {};
    const ctx = await b2.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', colorScheme: opts.sistema || 'dark' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    if (opts.tema) await page.addInitScript((t) => { try { localStorage.setItem('intus-theme', t); } catch (e) {} }, opts.tema);
    await page.goto(url, { waitUntil: 'domcontentloaded' }); await page.waitForTimeout(400);
    return { ctx, page, erros };
  }
  const vis = (page, sel) => page.evaluate((s) => { const e = document.querySelector(s); return !!e && !!e.offsetParent && getComputedStyle(e).visibility !== 'hidden'; }, sel);
  const norm = (page, sel) => page.evaluate((s) => (document.querySelector(s).innerText || '').replace(/\s+/g, ' ').trim(), sel);
  let it = await nova(base);
  eq('primeira visita: abre a introdução, não o login', [await vis(it.page, '#vw-intro'), await vis(it.page, '#vw-login'), await vis(it.page, '#email')], [true, false, false]);
  eq('tela 1 da introdução e seus botões', [await norm(it.page, '.slide.on h1'), await norm(it.page, '#intro-prim'), await norm(it.page, '#intro-sec')], ['Seu treino, sempre à mão.', 'Continuar', 'Já tenho conta']);
  await it.page.click('#intro-prim'); await it.page.waitForTimeout(200);
  eq('tela 2: Do iniciante ao atleta, com Criar conta e Fazer login', [await norm(it.page, '.slide.on h1'), await norm(it.page, '#intro-prim'), await norm(it.page, '#intro-sec')], ['Do iniciante ao atleta.', 'Criar conta', 'Fazer login']);
  await it.page.click('#intro-sec'); await it.page.waitForTimeout(200);
  eq('Fazer login mostra o login e guarda que a introdução foi vista', [await vis(it.page, '#vw-login'), await vis(it.page, '#email'), await it.page.evaluate(() => localStorage.getItem('intus-intro-vista'))], [true, true, '1']);
  await it.page.click('#lg-back'); await it.page.waitForTimeout(200);
  eq('a seta volta para a introdução, na primeira tela', [await vis(it.page, '#vw-intro'), await norm(it.page, '.slide.on h1')], [true, 'Seu treino, sempre à mão.']);
  await it.page.reload({ waitUntil: 'domcontentloaded' }); await it.page.waitForTimeout(300);
  eq('depois de vista, a introdução não volta: abre direto no login', [await vis(it.page, '#vw-intro'), await vis(it.page, '#vw-login')], [false, true]);
  eq('sem erro de JavaScript', it.erros, []); await it.ctx.close();

  it = await nova(base);
  await it.page.click('#intro-prim'); await it.page.click('#intro-prim'); await it.page.waitForTimeout(300);
  eq('Criar conta, na introdução, abre o cadastro por cima do login', [await it.page.evaluate(() => document.getElementById('mod-criar').classList.contains('aberto')), await vis(it.page, '#vw-login')], [true, true]);
  await it.ctx.close();

  it = await nova(base + '?professor=1');
  eq('?professor=1 vai direto ao login do professor, sem introdução e sem seta', [await vis(it.page, '#vw-intro'), await vis(it.page, '#vw-login'), await norm(it.page, '#titulo-card'), await vis(it.page, '#lg-back')], [false, true, 'Acesso do professor', false]);
  await it.ctx.close();

  it = await nova(base + '?saiu=1');
  eq('?saiu=1 (saiu da conta) vai direto ao login', [await vis(it.page, '#vw-intro'), await vis(it.page, '#vw-login')], [false, true]); await it.ctx.close();

  // tema: cinza claro do ícone, ou escuro (segue o sistema quando não há escolha, como o aluno.html)
  const cores = (page) => page.evaluate(() => ({ tema: document.documentElement.getAttribute('data-tema'), fundo: getComputedStyle(document.body).backgroundColor, dim: getComputedStyle(document.getElementById('bg-dim')).backgroundColor, logoClaro: !!document.querySelector('.logo-claro').offsetParent, logoEscuro: !!document.querySelector('.logo-escuro').offsetParent, texto: getComputedStyle(document.getElementById('titulo-card')).color, campo: getComputedStyle(document.getElementById('email')).backgroundColor }));
  it = await nova(base + '?login=1', { tema: 'claro' }); let cr = await cores(it.page);
  eq('tema claro: cinza do ícone (D9D9DB), texto escuro, logo escura', [cr.tema, cr.fundo, cr.dim, cr.texto, cr.logoClaro, cr.logoEscuro], ['claro', 'rgb(217, 217, 219)', 'rgb(217, 217, 219)', 'rgb(17, 17, 17)', true, false]);
  eq('tema claro: campos em cinza muito claro (legíveis)', cr.campo, 'rgb(246, 246, 247)'); await it.ctx.close();
  it = await nova(base + '?login=1', { tema: 'escuro' }); cr = await cores(it.page);
  eq('tema escuro: fundo escuro, texto claro, logo branca', [cr.tema, cr.fundo, cr.texto, cr.logoClaro, cr.logoEscuro], ['escuro', 'rgb(13, 13, 13)', 'rgb(240, 240, 240)', false, true]); await it.ctx.close();
  it = await nova(base + '?login=1', { sistema: 'light' }); cr = await cores(it.page);
  eq('sem escolha salva, segue o sistema: celular claro abre o tema claro', cr.tema, 'claro'); await it.ctx.close();
  it = await nova(base + '?login=1&tema=claro'); cr = await cores(it.page);
  eq('?tema=claro força o tema claro (para conferir a tela)', cr.tema, 'claro'); await it.ctx.close();

  // contraste do texto e dos campos nos dois temas (WCAG AA: 4,5:1)
  async function contraste(tema) {
    const x = await nova(base + '?login=1', { tema });
    const r = await x.page.evaluate(() => {
      const lum = (c) => { const m = c.match(/\d+(\.\d+)?/g).map(Number).slice(0, 3).map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * m[0] + 0.7152 * m[1] + 0.0722 * m[2]; };
      const cr = (a, b) => { const l1 = lum(a), l2 = lum(b); return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05); };
      const bg = getComputedStyle(document.body).backgroundColor, g = (s, p) => getComputedStyle(document.querySelector(s))[p];
      return { titulo: cr(g('#titulo-card', 'color'), bg), subtitulo: cr(g('#hint-msg', 'color'), bg), rotulo: cr(g('.form-label', 'color'), bg), botao: cr(g('#btn', 'color'), g('#btn', 'backgroundColor')), digitado: cr(g('#email', 'color'), g('#email', 'backgroundColor')), social: cr(g('#btn-facebook', 'color'), bg) };
    });
    await x.ctx.close(); return r;
  }
  for (const t of ['escuro', 'claro']) {
    const c = await contraste(t);
    const bom = Object.values(c).every(v => v >= 4.5);
    eq('contraste mínimo de 4,5:1 no tema ' + t + ' (título, subtítulo, rótulo, botão Entrar, campo, acessos rápidos)', bom, true);
    if (!bom) console.log('          ' + JSON.stringify(c));
  }

  await b2.close(); srv.close();

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
