// Cardio ao vivo (aluno.html): contagem regressiva ao começar, contador de minutos para o próximo ponto extra, emoji animado no Meu Cardio,
// tela de confirmação que abre NA HORA ao finalizar (mesmo com o servidor lento), parabéns na hora ao concluir, foto enviada ao registro certo,
// e o aviso fixo do treino mostrado uma vez só.
//   cd testes && INTUS_DIR=../app/painel node cardio_aovivo_fluxo.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
const hoje = (() => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
// 95 min de cardio ao vivo já feitos nesta semana: 1 ponto extra conquistado e faltam 25 min para o próximo
const HIST = [{ idsessao: 11, dtsessao: hoje, duracao_seg: 95 * 60, pontos: 6, cardio_tipo: 'Corrida', cardio_rpe: 6, nao_contar: 0, ao_vivo: 1, comentario: 'Corrida · esforço 6/10 · 95min' }];
const FOTO = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8112, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const posts = [], puts = []; let atrasoMs = 0;
  await page.route(/\/app\/api\/treinos\.php\?action=cardio/, async r => {
    const req = r.request();
    await new Promise(x => setTimeout(x, atrasoMs));
    if (req.method() === 'POST') { const body = JSON.parse(req.postData() || '{}'); posts.push(body); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ idsessao: 700 + posts.length, pontos: body.pontos }) }); }
    if (req.method() === 'PUT') { puts.push({ url: req.url(), body: JSON.parse(req.postData() || '{}') }); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    if (req.method() === 'GET') return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(HIST) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
  });
  await page.route(/\/app\/api\/(?!treinos\.php\?action=cardio)/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
  await page.goto('http://127.0.0.1:8112/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(500);
  const txt = (sel) => page.evaluate((s) => ((document.querySelector(s) || {}).innerText || '').replace(/\s+/g, ' '), sel);

  console.log('\n══ Contador do ponto extra (já com 1 ponto) ══');
  await page.evaluate((h) => { _cardioHistorico = h; renderCardio(); trocarView('cardio'); }, HIST); await page.waitForTimeout(300);
  let t = await txt('#v-cardio');
  ver('Meu Cardio mostra quantos minutos faltam para o próximo ponto extra mesmo com 1 já conquistado', /Faltam 25 min de cardio ao vivo para o próximo ponto extra \(você já tem \+1 na semana\)/.test(t), t.slice(0, 400));
  eq('mostra também o acumulado da semana', /95 min ao vivo/.test(t), true);
  await page.evaluate(() => { abrirCardioLive(); }); await page.waitForTimeout(300);
  ver('a tela do cardio ao vivo também mostra o contador antes de começar', /Faltam 25 min/.test(await txt('#v-cardio-live')));

  console.log('\n══ Contagem regressiva ══');
  await page.evaluate(() => { _cardioLiveTipo = 'Escada'; _cardioLiveParado = true; _renderCardioLive(); });
  await page.evaluate(() => { _cardioLiveIniciarRastreio(); });
  await page.waitForTimeout(300);
  eq('ao tocar em Começar aparece o aviso de que está começando, com 3', [(await txt('#cl-contagem .cc-t')).toLowerCase(), await txt('#cc-n')], ['seu cardio está começando', '3']);
  eq('o cronômetro ainda NÃO começou durante a contagem', await page.evaluate(() => [_cardioLiveInicioMs, _cardioLiveRodando]), [0, false]);
  await page.waitForTimeout(1100); eq('depois 2', await txt('#cc-n'), '2');
  await page.waitForTimeout(1000); eq('depois 1', await txt('#cc-n'), '1');
  await page.waitForTimeout(1000); eq('depois "Vai!"', await txt('#cc-n'), 'Vai!');
  await page.waitForTimeout(900);
  eq('a contagem some e o rastreio começa', await page.evaluate(() => [!!document.getElementById('cl-contagem'), _cardioLiveInicioMs > 0, _cardioLiveRodando]), [false, true, true]);
  await page.evaluate(() => { _cardioLiveIniciarRastreio(); }); await page.waitForTimeout(200);
  eq('tocar de novo com o rastreio rodando não abre outra contagem', await page.evaluate(() => !!document.getElementById('cl-contagem')), false);

  console.log('\n══ Durante o cardio ══');
  t = await txt('#v-cardio-live');
  ver('a tela mostra o contador de minutos para o ponto extra enquanto o cronômetro corre', /Faltam \d+ min de cardio ao vivo/.test(t), t.slice(0, 300));
  await page.evaluate(() => { renderCardio(); trocarView('cardio'); }); await page.waitForTimeout(400);
  eq('na tela principal do Cardio o emoji da atividade é animado e mostra o tempo correndo', await page.evaluate(() => [!!document.querySelector('#v-cardio .cl-emoji-vivo'), /Em andamento/.test(document.getElementById('v-cardio').innerText), !!document.getElementById('cm-live-tempo')]), [true, true, true]);
  const t1 = await txt('#cm-live-tempo'); await page.waitForTimeout(2200);
  ver('o tempo da tela principal anda sozinho', (await txt('#cm-live-tempo')) !== t1, [t1, await txt('#cm-live-tempo')]);

  console.log('\n══ Finalizar com o servidor lento: a confirmação abre na hora ══');
  atrasoMs = 3000;
  await page.evaluate(() => { _cardioLiveTempoMs = function () { return 6 * 60000; }; });   // 6 min, para passar do mínimo de 5
  const t0 = Date.now();
  await page.evaluate(() => { _cardioLiveFinalizar(); });
  await page.waitForSelector('#cardio-esforco-overlay', { timeout: 1500 });
  const dem = Date.now() - t0;
  ver('a tela "Cardio registrado!" abre em menos de 1,5 s (antes esperava o servidor, 3 s aqui)', dem < 1500, dem);
  eq('enquanto grava, ela diz que está salvando', await txt('#cle-status'), 'Salvando o seu cardio...');
  console.log('\n══ Concluir com foto: parabéns na hora, foto no registro certo ══');
  await page.evaluate((f) => { _cardioFoto = f; _renderCardioLiveFotoArea(); const s = document.getElementById('cle-rpe'); s.value = 7; s.dispatchEvent(new Event('input')); }, FOTO);
  await page.waitForTimeout(3600);   // o servidor termina de gravar DEPOIS de a foto ser anexada (não pode apagar a foto)
  eq('a foto anexada durante a gravação continua anexada quando o servidor responde', await page.evaluate(() => !!_cardioFoto), true);
  const t2 = Date.now();
  await page.evaluate(() => { _confirmarEsforcoCardioLive(); });
  await page.waitForSelector('#cardio-result-overlay', { timeout: 1500 });
  ver('a tela de parabéns abre em menos de 1,5 s, sem esperar o servidor', Date.now() - t2 < 1500, Date.now() - t2);
  await page.waitForTimeout(7000);
  eq('o cardio foi gravado uma vez só', posts.length, 1);
  eq('o ajuste de esforço e a foto foram para o registro que acabou de ser criado', [puts.length, /id=701/.test((puts[0] || {}).url || ''), (puts[0] || {}).body && puts[0].body.cardio_rpe, !!((puts[0] || {}).body && puts[0].body.cardio_foto)], [1, true, 7, true]);

  console.log('\n══ Aviso fixo do treino mostrado uma vez só ══');
  await page.evaluate(() => {
    window.__avisos = [];
    Object.defineProperty(window, 'Notification', { value: Object.assign(function () {}, { permission: 'granted' }), configurable: true });
    const reg = { showNotification: (titulo, o) => { window.__avisos.push(titulo + '|' + o.body); }, getNotifications: async () => window.__avisos.map(a => ({ title: a.split('|')[0], body: a.split('|')[1], close() { window.__avisos = window.__avisos.filter(x => x !== a); } })) };
    Object.defineProperty(navigator, 'serviceWorker', { value: { ready: Promise.resolve(reg) }, configurable: true });
    _statusAtivo = { chave: '' };
  });
  await page.evaluate(async () => { statusAtivoMostrar('treino', '💪 Treino A em andamento', 'Começou às 10:00.'); statusAtivoMostrar('treino', '💪 Treino A em andamento', 'Começou às 10:00.'); await new Promise(r => setTimeout(r, 400)); statusAtivoParar('treino'); statusAtivoMostrar('treino', '💪 Treino A em andamento', 'Começou às 10:00.'); await new Promise(r => setTimeout(r, 400)); });
  eq('dois pedidos iguais seguidos viram um aviso só, e depois de parar um novo aparece (um aviso na barra no fim)', await page.evaluate(() => window.__avisos.length), 1);

  console.log('\n══ Erros de página ══');
  eq('nenhum erro de JavaScript', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
