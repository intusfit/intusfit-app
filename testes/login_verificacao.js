// Tela de login do aluno (login.html) com o servidor de mentira: rótulos novos (o código de acesso vai no campo da senha), dica para quem
// digita o código no primeiro campo, mensagem do limite de tentativas e a verificação extra (Cloudflare Turnstile) que só aparece quando o
// servidor pede, com o script do Cloudflare de mentira.
//   cd testes && INTUS_DIR=../app/painel node login_verificacao.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ATLETA = { idatleta: 7, nome: 'Aluna Teste', email: 'a@t.com', server_token: 'a'.repeat(64) };
const json = (route, status, corpo) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(corpo) });
const TS_FALSO = "window.turnstile = { render: function (el, o) { window.__ts = o; window.__tsEl = el; el.setAttribute('data-feito', '1'); return 'w1'; }, reset: function () { window.__tsReset = (window.__tsReset || 0) + 1; }, remove: function () {} };";

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    const f = path.join(RAIZ, p);
    if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8095, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8095';
  const browser = await chromium.launch();
  async function nova(api, scriptTs) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const corpos = [], erros = [];
    page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, route => {
      const u = route.request().url();
      if (/action=retomar/.test(u)) return json(route, 401, { ok: false });
      if (/aluno_login\.php$/.test(u.split('?')[0])) { let c = {}; try { c = JSON.parse(route.request().postData() || '{}'); } catch (e) {} corpos.push(c); return api(route, c, corpos.length); }
      route.abort('failed');
    });
    await page.route(/challenges\.cloudflare\.com/, route => scriptTs === null ? route.abort('failed') : route.fulfill({ status: 200, contentType: 'text/javascript', body: scriptTs }));
    await page.goto(B + '/login.html', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#senha');
    return { ctx, page, corpos, erros };
  }
  const entrar = async (t, email, senha) => { await t.page.fill('#email', email); await t.page.fill('#senha', senha); await t.page.click('#btn'); };
  const erroTxt = t => t.page.evaluate(() => { const e = document.getElementById('erro'); return e.style.display === 'none' ? '' : e.textContent; });

  console.log('\n══ Rótulos: o código de acesso vai no campo da senha ══');
  let t = await nova((route) => json(route, 401, { ok: false, erro: 'credenciais invalidas' }), TS_FALSO);
  eq('primeiro campo é só e-mail', await t.page.evaluate(() => [document.querySelector('label[for], .form-label').textContent, document.getElementById('email').placeholder]), ['E-mail', 'seu@email.com']);
  eq('segundo campo aceita "senha ou código de acesso"', await t.page.textContent('#label-senha'), 'Senha ou código de acesso');
  await t.page.click('#btn-toggle-modo');
  eq('no modo professor o rótulo volta a "Senha" (o código é só de aluno)', await t.page.textContent('#label-senha'), 'Senha');
  await t.page.click('#btn-toggle-modo');
  eq('e volta no modo aluno', await t.page.textContent('#label-senha'), 'Senha ou código de acesso');

  console.log('\n══ Erros ══');
  await entrar(t, 'INT1A2B3C', 'INT1A2B3C'); await t.page.waitForTimeout(400);
  eq('código no primeiro campo: a tela diz onde ele vai agora', /e-mail no primeiro campo/i.test(await erroTxt(t)) && /campo da senha/.test(await erroTxt(t)), true);
  await entrar(t, 'a@t.com', 'errada'); await t.page.waitForTimeout(400);
  eq('e-mail com senha errada: a mensagem de sempre', await erroTxt(t), 'E-mail, senha ou código de acesso incorretos.');
  await t.ctx.close();
  t = await nova((route) => json(route, 429, { error: 'muitas_tentativas', mensagem: 'Muitas tentativas. Tente novamente em 15 minutos.', retry_after: 900 }), TS_FALSO);
  await entrar(t, 'a@t.com', 'x'); await t.page.waitForTimeout(400);
  eq('429 do limite de tentativas mostra a mensagem do servidor (antes: "erro 429")', await erroTxt(t), 'Muitas tentativas. Tente novamente em 15 minutos.');
  await t.ctx.close();

  console.log('\n══ Verificação extra (Turnstile) ══');
  const desafio = { ok: false, codigo: 'verificacao_necessaria', erro: 'Por segurança, confirme que você é uma pessoa e toque em Entrar de novo.', verificacao: { tipo: 'turnstile', site_key: 'chave-publica-teste' } };
  t = await nova((route, c, n) => c.turnstile_token === 'tok-1' ? json(route, 200, { ok: true, atleta: ATLETA }) : json(route, 429, desafio), TS_FALSO);
  eq('em tempo normal o widget não existe e o script do Cloudflare não foi pedido', [await t.page.evaluate(() => document.getElementById('ts-box').style.display), await t.page.evaluate(() => typeof window.turnstile)], ['none', 'undefined']);
  await entrar(t, 'a@t.com', 'SenhaForte1'); await t.page.waitForFunction(() => window.__ts, null, { timeout: 5000 });
  eq('o servidor pediu: o widget aparece com a chave pública que veio na resposta', [await t.page.evaluate(() => [document.getElementById('ts-box').style.display, window.__ts.sitekey])], [['block', 'chave-publica-teste']]);
  eq('mostra a mensagem do servidor e o botão volta a funcionar', [/pessoa/.test(await erroTxt(t)), await t.page.isEnabled('#btn')], [true, true]);
  eq('o primeiro envio saiu sem token', t.corpos[0].turnstile_token, undefined);
  await t.page.evaluate(() => window.__ts.callback('tok-1'));
  await t.page.waitForURL(/aluno\.html/, { timeout: 8000 }).catch(() => {});
  eq('resolvido o desafio, reenvia sozinho com o token e a MESMA e-mail/senha, e entra', [t.corpos[1], /aluno\.html/.test(t.page.url()), await t.page.evaluate(() => localStorage.getItem('mx-token'))], [{ email: 'a@t.com', senha: 'SenhaForte1', turnstile_token: 'tok-1' }, true, ATLETA.server_token]);
  eq('sem erro de script', t.erros, []);
  await t.ctx.close();

  t = await nova((route, c) => c.turnstile_token ? json(route, 401, { ok: false, erro: 'credenciais invalidas' }) : json(route, 429, desafio), TS_FALSO);
  await entrar(t, 'a@t.com', 'errada'); await t.page.waitForFunction(() => window.__ts, null, { timeout: 5000 });
  await t.page.evaluate(() => window.__ts.callback('tok-x')); await t.page.waitForTimeout(600);
  eq('senha errada com token: mostra o erro de credenciais e refaz o widget (o token vale uma vez)', [await erroTxt(t), await t.page.evaluate(() => window.__tsReset || 0)], ['E-mail, senha ou código de acesso incorretos.', 1]);
  await entrar(t, 'a@t.com', 'errada');
  await t.page.waitForTimeout(600);
  eq('na tentativa seguinte o token anterior NÃO é reenviado', t.corpos[t.corpos.length - 1].turnstile_token, undefined);
  await t.ctx.close();

  t = await nova((route) => json(route, 429, desafio), null);
  await entrar(t, 'a@t.com', 'x'); await t.page.waitForTimeout(800);
  eq('script do Cloudflare bloqueado (bloqueador de anúncios): mensagem clara, sem travar o botão', [/verificação de segurança/.test(await erroTxt(t)), await t.page.isEnabled('#btn')], [true, true]);
  await t.ctx.close();

  await browser.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
