// Saúde do celular no app (Apple Saúde e Health Connect): conectar, sincronizar, treinos detectados e desconectar, com um plugin de saúde de mentira.
//   cd testes && INTUS_DIR=../app/painel node saude_app.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const plataforma of ['ios', 'android']) {
    console.log('\n══ ' + (plataforma === 'ios' ? 'iPhone (Apple Saúde)' : 'Android (Health Connect)') + ' ══');
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', hasTouch: true, isMobile: true });
    const page = await ctx.newPage(); const erros = [];
    const srvEstado = { dias: [], treinos: [], posts: [], puts: [], deletes: 0, prox: 1 };
    page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.route(/\/app\/api\/catalogo\.php\?action=anamnese/, r => r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }));
    await page.route(/\/app\/api\/catalogo\.php\?action=saude/, r => {
      const m = r.request().method(); const corpo = r.request().postData() ? JSON.parse(r.request().postData()) : {};
      const json = (o) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
      if (m === 'GET') return json({ dias: srvEstado.dias, treinos: srvEstado.treinos });
      if (m === 'POST') {
        srvEstado.posts.push(corpo);
        (corpo.dias || []).forEach(d => { srvEstado.dias = srvEstado.dias.filter(x => x.data !== d.data); srvEstado.dias.push(Object.assign({ fonte: corpo.fonte }, d)); });
        (corpo.treinos || []).forEach(t => { if (!srvEstado.treinos.some(x => x.ext_id === t.ext_id)) srvEstado.treinos.push(Object.assign({ idsaude: srvEstado.prox++, fonte: corpo.fonte, importado: 0 }, t)); });
        return json({ ok: true });
      }
      if (m === 'PUT') { srvEstado.puts.push(corpo); const t = srvEstado.treinos.find(x => x.idsaude === corpo.idsaude); if (t) t.importado = corpo.importado; return json({ ok: true }); }
      if (m === 'DELETE') { srvEstado.deletes++; srvEstado.dias = []; srvEstado.treinos = []; return json({ ok: true }); }
      return json({});
    });
    await page.route(/\/app\/api\/treinos\.php\?action=cardio/, r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, idsessao: 501, pontos: 5, nao_contar: 0 }) }));
    await page.addInitScript((plat) => {
      localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-theme', 'escuro');
      const hoje = (h) => { const d = new Date(); d.setHours(h, 0, 0, 0); return d.toISOString(); };
      window.__chamadas = []; window.__disponivel = true;
      const falso = {
        isAvailable: async () => ({ available: window.__disponivel, reason: 'sem Health Connect' }),
        requestAuthorization: async (o) => { window.__chamadas.push(['auth', o]); return {}; },
        queryAggregated: async (o) => { const v = { steps: 8000, distance: 6100, calories: 410, restingHeartRate: 58 }[o.dataType]; return { samples: v ? [{ startDate: hoje(0), endDate: hoje(23), value: v, values: {}, unit: 'count' }] : [] }; },
        readSamples: async (o) => {
          if (o.dataType === 'sleep') return { samples: [
            { dataType: 'sleep', value: 0, unit: 'minute', startDate: hoje(0), endDate: hoje(6), sleepState: 'asleep', sourceName: 'Relógio' },
            { dataType: 'sleep', value: 0, unit: 'minute', startDate: hoje(0), endDate: hoje(5), sleepState: 'asleep', sourceName: 'iPhone' },
            { dataType: 'sleep', value: 0, unit: 'minute', startDate: hoje(0), endDate: hoje(6), sleepState: 'inBed', sourceName: 'Relógio' } ] };
          if (o.dataType === 'weight') return { samples: [{ dataType: 'weight', value: 72.46, unit: 'kilogram', startDate: hoje(8), endDate: hoje(8) }] };
          return { samples: [] };
        },
        queryWorkouts: async () => ({ workouts: [
          { workoutType: 'running', duration: 35 * 60, totalDistance: 5200, totalEnergyBurned: 380, startDate: hoje(7), endDate: hoje(8), platformId: 'W1' },
          { workoutType: 'traditionalStrengthTraining', duration: 50 * 60, startDate: hoje(9), endDate: hoje(10), platformId: 'W2' },
          { workoutType: 'walking', duration: 3 * 60, startDate: hoje(11), endDate: hoje(11), platformId: 'W3' } ] }),
      };
      window.INTUS_NATIVE_APP = true;
      window.Capacitor = { isNativePlatform: () => true, getPlatform: () => plat, registerPlugin: (n) => (n === 'Health' ? falso : {}), Plugins: {} };
    }, plataforma);
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _saudeConectar === 'function' && typeof renderHome === 'function' && !!document.getElementById('v-home')).catch(() => false)) break; await page.waitForTimeout(250); }
    const nome = plataforma === 'ios' ? 'Apple Saúde' : 'Health Connect';

    await page.evaluate(() => abrirIntegracoes());
    await page.waitForTimeout(300);
    eq('antes de conectar: botão de conectar com o nome certo', await page.evaluate(() => (document.querySelector('#jn-saude-box .jn-btn') || {}).textContent), 'Conectar o ' + nome);
    eq('a lista de "Avisar" não repete a saúde (7 itens)', await page.evaluate(() => document.querySelectorAll('.jn-int').length), 7);

    await page.evaluate(() => _saudeConectar()); await page.waitForTimeout(800);
    const auth = await page.evaluate(() => window.__chamadas.find(c => c[0] === 'auth')[1]);
    eq('pede só LEITURA, dos 7 tipos, sem escrita', [auth.read, auth.write], [['steps', 'distance', 'calories', 'sleep', 'restingHeartRate', 'weight', 'workouts'], []]);
    eq('ficou conectada neste aparelho', await page.evaluate(() => _saudeAtivo()), true);
    const post = srvEstado.posts[srvEstado.posts.length - 1] || {};
    eq('a fonte enviada é a da plataforma', post.fonte, plataforma === 'ios' ? 'apple' : 'android');
    const hoje = await page.evaluate(() => dataHojeLocal());
    const dh = (post.dias || []).find(d => d.data === hoje) || {};
    eq('dia de hoje: passos, distância, calorias, FC de repouso e peso', [dh.passos, dh.distancia_m, dh.kcal_ativas, dh.fc_repouso, dh.peso_kg], [8000, 6100, 410, 58, 72.5]);
    eq('sono: vale a maior fonte (360 min), não a soma, e não conta "na cama"', dh.sono_min, 360);
    eq('só o treino de cardio com 5 min ou mais é enviado (força e 3 min ficam de fora)', (post.treinos || []).map(t => [t.ext_id, t.tipo, t.duracao_min]), [['W1', 'Corrida', 35]]);
    const txt = await page.evaluate(() => document.getElementById('jn-saude-box').textContent);
    eq('o cartão mostra os passos do dia e o treino detectado', [/8\.000/.test(txt), /Corrida · 35 min · 5,2 km/.test(txt), /Desconectar e apagar/.test(txt)], [true, true, true]);
    eq('a trilha marca "Conectar saúde e apps" como feito', await page.evaluate(() => _jnPassos().find(p => p.id === 'saude').ok), true);

    if (process.env.SHOT && plataforma === 'ios') await page.screenshot({ path: process.env.SHOT });
    await page.evaluate(() => _saudeSincronizar(false)); await page.waitForTimeout(300);
    eq('sincronização automática respeita a pausa de 1 hora', srvEstado.posts.length, 1);
    await page.evaluate(() => _saudeSincronizar(true)); await page.waitForTimeout(500);
    eq('"Sincronizar agora" força e o treino não duplica no servidor', [srvEstado.posts.length, srvEstado.treinos.length], [2, 1]);

    // Registrar como cardio
    const id = srvEstado.treinos[0].idsaude;
    await page.evaluate((i) => _saudeImportar(i), id); await page.waitForTimeout(500);
    const form = await page.evaluate(() => ({ f: _cardioForm, tela: _cardioTelaAtual() }));
    eq('"Registrar" abre o formulário de cardio já preenchido (tipo, tempo e km)', [form.tela, form.f.tipo, form.f.min, form.f.dist], ['form', 'Corrida', '35', '5.20']);
    eq('ainda NÃO conta como importado (o aluno nem salvou)', srvEstado.puts.length, 0);
    await page.evaluate(() => salvarCardio()); await page.waitForTimeout(1500);
    eq('depois de salvar o cardio, o treino é marcado como importado', srvEstado.puts, [{ idsaude: id, importado: 1 }]);
    eq('e some da lista de treinos de hoje', await page.evaluate(() => _saudeTreinosHoje().length), 0);

    // Salvar OUTRO cardio não marca nada (o pedido da importação já foi usado)
    await page.evaluate(() => { _cardioFormLimpar(); _cardioForm = { tipo: 'Bike', outro: '', min: '20', dist: '' }; });
    await page.evaluate(() => salvarCardio()); await page.waitForTimeout(1200);
    eq('um cardio comum depois não marca treino nenhum', srvEstado.puts.length, 1);

    // Desconectar
    await page.evaluate(() => _saudeDesconectar()); await page.waitForTimeout(500);
    eq('desconectar apaga no servidor e desliga o aparelho', [srvEstado.deletes, await page.evaluate(() => _saudeAtivo()), srvEstado.dias.length, srvEstado.treinos.length], [1, false, 0, 0]);
    eq('e a trilha volta a pedir a conexão', await page.evaluate(() => { const j = _jnDados(); return [j.saude_conectada]; }), [false]);

    // Aparelho sem o serviço de saúde
    await page.evaluate(() => { window.__disponivel = false; abrirIntegracoes(); }); await page.waitForTimeout(300);
    await page.evaluate(() => _saudeConectar()); await page.waitForTimeout(500);
    eq('sem Health Connect/Saúde no aparelho: avisa e NÃO liga', [await page.evaluate(() => _saudeAtivo()), /não está disponível/.test(await page.evaluate(() => document.getElementById('jn-saude-box').textContent))], [false, true]);
    eq('sem erro de JavaScript', erros, []);
    await ctx.close();
  }

  // No navegador (fora do app): só informa
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage();
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _saudeConectar === 'function' && !!document.getElementById('v-home')).catch(() => false)) break; await page.waitForTimeout(250); }
  console.log('\n══ Navegador comum ══');
  await page.evaluate(() => abrirIntegracoes()); await page.waitForTimeout(300);
  eq('fora do app: explica que é no app e não oferece conectar', await page.evaluate(() => [/Disponível no app/.test(document.getElementById('jn-saude-box').textContent), !!document.querySelector('#jn-saude-box .jn-btn')]), [true, false]);
  await ctx.close();
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
