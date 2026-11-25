// "Preparar o app" (25/11/2026): pede notificações, localização e câmera de uma vez, só no app das lojas e só na primeira vez.
//   cd testes && INTUS_DIR=../app/painel node permissoes_app.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json' };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  async function abrir(nativo, perm) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(([n, perm]) => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
      if (!n) return;
      window.INTUS_NATIVE_APP = true; window.INTUS_API_BASE = 'https://intusfit.com.br/app/api';
      window.__pedidos = [];
      const geo = { addWatcher: async (o, cb) => { window.__pedidos.push('local:' + o.requestPermissions); setTimeout(() => cb(perm.local === 'ok' ? { latitude: 1, longitude: 1, accuracy: 5, time: Date.now() } : null, perm.local === 'ok' ? null : { code: 'NOT_AUTHORIZED', message: 'denied' }), 50); return 'w1'; }, removeWatcher: async () => { window.__pedidos.push('local:parou'); } };
      const ln = { checkPermissions: async () => ({ display: 'prompt' }), requestPermissions: async () => { window.__pedidos.push('notif'); return { display: perm.notif }; }, schedule: async () => {}, cancel: async () => {}, getPending: async () => ({ notifications: [] }), createChannel: async () => {}, addListener: () => Promise.resolve({ remove() {} }) };
      window.Capacitor = { isNativePlatform: () => true, Plugins: { LocalNotifications: ln }, registerPlugin: (n) => n === 'BackgroundGeolocation' ? geo : {} };
      navigator.mediaDevices.getUserMedia = async () => { window.__pedidos.push('camera'); if (perm.camera !== 'ok') { const e = new Error('x'); e.name = 'NotAllowedError'; throw e; } return { getTracks: () => [{ stop() { window.__pedidos.push('camera:parou'); } }] }; };
    }, [nativo, perm || { notif: 'granted', local: 'ok', camera: 'ok' }]);
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => !!window.PermissoesApp && typeof _comSheet === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    return { ctx, page, erros };
  }

  console.log('\n══ Pedidos em sequência ══');
  let t = await abrir(true);
  await page_eval(t, () => PermissoesApp.abrir());
  eq('a folha lista notificações, localização precisa e câmera, com o aviso sobre "sempre permitir"', await t.page.evaluate(() => { const x = document.getElementById('perm-sheet').textContent; return [/Notificações/.test(x), /Localização precisa/.test(x), /Câmera/.test(x), /Sempre permitir/.test(x)]; }), [true, true, true, true]);
  await t.page.evaluate(() => { PermissoesApp.permitir(document.getElementById('perm-ok')); });
  await t.page.waitForTimeout(1500);
  eq('pede as três permissões na ordem e solta a câmera e o GPS logo depois', await t.page.evaluate(() => window.__pedidos), ['notif', 'local:true', 'local:parou', 'camera', 'camera:parou']);
  eq('depois de pedir, não oferece de novo e guarda o aval de localização para o cardio ao vivo', await t.page.evaluate(() => [PermissoesApp.feito(), localStorage.getItem('intus-bg-loc'), !!document.getElementById('perm-sheet')]), [true, 'ok', false]);
  eq('sem erro de JavaScript', t.erros, []); await t.ctx.close();

  console.log('\n══ Permissão negada ══');
  t = await abrir(true, { notif: 'denied', local: 'negado', camera: 'negado' });
  await t.page.evaluate(() => PermissoesApp._teste.pedirTudo());
  eq('o que foi negado vira aviso (e o app não trava nem pede de novo na primeira vez)', await t.page.evaluate(() => [PermissoesApp.feito(), localStorage.getItem('intus-bg-loc'), document.body.textContent.indexOf('Faltou permitir') >= 0]), [true, null, true]);
  await t.ctx.close();

  console.log('\n══ Web e primeira vez ══');
  t = await abrir(false);
  await t.page.evaluate(() => PermissoesApp.abrir());
  eq('na web não abre a folha (o navegador pergunta sozinho na hora de usar)', await t.page.evaluate(() => !!document.getElementById('perm-sheet')), false);
  await t.ctx.close();
  t = await abrir(true);
  await t.page.waitForTimeout(6800);
  eq('no app, na primeira vez a folha se oferece sozinha na tela inicial', await t.page.evaluate(() => !!document.getElementById('perm-sheet')), true);
  await t.page.evaluate(() => PermissoesApp.agoraNao());
  eq('"Agora não" fecha e não insiste; o menu continua com "Permissões do app"', await t.page.evaluate(() => [!!document.getElementById('perm-sheet'), PermissoesApp.feito(), /Permissões do app/.test(document.body.innerHTML)]), [false, true, true]);
  await t.ctx.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
  async function page_eval(t, fn) { await t.page.evaluate(fn); await t.page.waitForTimeout(200); }
})().catch(e => { console.error(e); process.exit(2); });
