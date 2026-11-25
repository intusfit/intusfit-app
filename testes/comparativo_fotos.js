// Comparativo de fotos (antes e depois) do aluno, 25/11/2026: montagem (linha divisória e lado a lado), salvar como avaliação
// e publicar no Feed só depois de confirmar.
//   cd testes && INTUS_DIR=../app/painel node comparativo_fotos.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json', '.css': 'text/css' };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const pedidos = { avaliacao: null, feed: null, feedChamadas: 0 };
  await page.route(/\/app\/api\//, r => {
    const u = r.request().url(), m = r.request().method();
    if (/action=avaliacoes/.test(u) && m === 'POST') { pedidos.avaliacao = JSON.parse(r.request().postData() || '{}'); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, idavaliacao: 9, fotos: [] }) }); }
    if (/action=feed_posts/.test(u) && m === 'POST') { pedidos.feedChamadas++; pedidos.feed = JSON.parse(r.request().postData() || '{}'); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, idpost: 5 }) }); }
    if (/action=avaliacoes/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
    if (/action=atleta_existe/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    return r.abort('failed');
  });
  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
  });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => !!window.ComparativoFotos && typeof _comSheet === 'function' && typeof renderAvaliacoesAluno === 'function').catch(() => false)) break; await page.waitForTimeout(250); }

  console.log('\n══ Montagem ══');
  const imgs = await page.evaluate(() => {
    const mk = (cor) => { const c = document.createElement('canvas'); c.width = 600; c.height = 800; const x = c.getContext('2d'); x.fillStyle = cor; x.fillRect(0, 0, 600, 800); return c.toDataURL('image/png'); };
    return { a: mk('#c0392b'), b: mk('#2980b9') };
  });
  await page.evaluate(() => navegar('avaliacoes'));
  await page.waitForTimeout(1500);       // a tela de Avaliações termina de carregar antes de a pessoa tocar no botão
  await page.evaluate((im) => {
    _avaliacoesCache = [
      { idavaliacao: 2, dtavaliacao: '2026-05-21', peso: 75.6, metodo: 'Fotos Postural', fotos: [im.b] },
      { idavaliacao: 1, dtavaliacao: '2025-02-01', peso: 80, metodo: 'Fotos Postural', fotos: [im.a] },
      { idavaliacao: 3, dtavaliacao: '2026-06-01', metodo: 'Comparativo do aluno', fotos: [im.a] },
    ];
    ComparativoFotos.abrir();
  }, imgs);
  await page.waitForTimeout(300);
  eq('a tela abre com os dois quadros vazios e os botões de salvar e compartilhar travados', await page.evaluate(() => ({ titulo: !!document.querySelector('#v-avaliacoes .sect-title'), salvar: document.getElementById('cf-btn-salvar').style.pointerEvents, comp: document.getElementById('cf-btn-comp').style.pointerEvents })), { titulo: true, salvar: 'none', comp: 'none' });
  await page.evaluate(() => ComparativoFotos.escolher('a'));
  const opcoes = await page.evaluate(() => document.querySelectorAll('#cf-pick img').length);
  eq('a lista de fotos traz só as das avaliações (o comparativo já salvo fica de fora)', opcoes, 2);
  await page.evaluate(() => document.querySelectorAll('#cf-pick img')[1].parentNode.click());   // mais antiga = Antes
  await page.waitForTimeout(500);
  await page.evaluate(() => ComparativoFotos.escolher('b'));
  await page.evaluate(() => document.querySelectorAll('#cf-pick img')[0].parentNode.click());   // mais recente = Depois
  await page.waitForTimeout(500);
  const est = await page.evaluate(() => { const e = ComparativoFotos._teste.estado(); return { a: [!!e.a.img, e.a.dt, e.a.peso], b: [!!e.b.img, e.b.dt, e.b.peso] }; });
  eq('as fotos escolhidas trazem data e peso da avaliação', est, { a: [true, '2025-02-01', '80'], b: [true, '2026-05-21', '75,6'] });
  eq('com as duas fotos, salvar e compartilhar liberam', await page.evaluate(() => [document.getElementById('cf-btn-salvar').style.pointerEvents, document.getElementById('cf-btn-comp').style.pointerEvents]), ['auto', 'auto']);

  const pix = (modo, pos) => page.evaluate(([modo, pos]) => {
    const e = ComparativoFotos._teste.estado(); e.modo = modo; e.pos = pos;
    const cv = document.createElement('canvas'); ComparativoFotos._teste.compor(cv, 1);
    const g = cv.getContext('2d');
    const p = (x, y) => Array.from(g.getImageData(x, y, 1, 1).data).slice(0, 3);
    return { esq: p(150, 600), dir: p(950, 600), linha: p(48 + Math.round(984 * pos), 300), larg: cv.width, alt: cv.height };
  }, [modo, pos]);
  let r = await pix('divisor', 0.5);
  eq('linha divisória: a foto de antes fica à esquerda, a de depois à direita', [r.esq, r.dir], [[192, 57, 43], [41, 128, 185]]);
  eq('linha divisória: a linha é branca e a imagem final mede 1080x1350', [r.linha, r.larg, r.alt], [[255, 255, 255], 1080, 1350]);
  r = await pix('divisor', 0.9);
  eq('mover a linha para a direita amplia a foto de antes', await page.evaluate(() => { const cv = document.createElement('canvas'); ComparativoFotos._teste.compor(cv, 1); const g = cv.getContext('2d'); return Array.from(g.getImageData(800, 600, 1, 1).data).slice(0, 3); }), [192, 57, 43]);
  r = await pix('lado', 0.5);
  eq('lado a lado: cada foto no seu quadro', [r.esq, r.dir], [[192, 57, 43], [41, 128, 185]]);
  const meio = await page.evaluate(() => { const cv = document.createElement('canvas'); ComparativoFotos._teste.compor(cv, 1); return Array.from(cv.getContext('2d').getImageData(540, 400, 1, 1).data).slice(0, 3); });
  eq('lado a lado: entre as fotos fica o fundo escuro (sem linha branca)', meio, [15, 15, 15]);
  await page.evaluate(() => { ComparativoFotos._teste.estado().modo = 'divisor'; });

  console.log('\n══ Layout dos quadros de cima ══');
  eq('nada passa da largura da tela (os quadros das fotos cabem lado a lado)', await page.evaluate(() => { const L = innerWidth; const fora = Array.from(document.querySelectorAll('#v-avaliacoes *')).filter(e => e.getBoundingClientRect().right > L + 0.5 && getComputedStyle(e).position !== 'fixed' && e.offsetParent !== null).length; return [fora, document.documentElement.scrollWidth <= L + 1]; }), [0, true]);
  eq('os dois cartões têm a mesma largura e a data e o peso ficam empilhados', await page.evaluate(() => { const campos = Array.from(document.querySelectorAll('#v-avaliacoes input[type=date], #v-avaliacoes input[type=text]')); const larg = campos.map(i => Math.round(i.getBoundingClientRect().width)); return [campos.length, larg[0] === larg[2] && larg[1] === larg[3], larg[0] === larg[1]]; }), [4, true, true]);

  console.log('\n══ Gestos na prévia ══');
  await page.evaluate(() => ComparativoFotos.modo('lado')); await page.evaluate(() => ComparativoFotos.modo('divisor'));
  await page.evaluate(() => window.scrollTo(0, document.getElementById('cf-prev').getBoundingClientRect().top + scrollY - 20));
  const box = await page.evaluate(() => { const r = document.getElementById('cf-prev').getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; });
  const lerEst = () => page.evaluate(() => { const e = ComparativoFotos._teste.estado(); return { pos: e.pos, a: { z: e.a.zoom, ox: e.a.ox, oy: e.a.oy }, b: { z: e.b.zoom, ox: e.b.ox, oy: e.b.oy } }; });
  const arrastar = async (x0, y0, x1, y1) => { await page.mouse.move(box.x + box.w * x0, box.y + box.h * y0); await page.mouse.down(); await page.mouse.move(box.x + box.w * x1, box.y + box.h * y1, { steps: 6 }); await page.mouse.up(); };
  let e0 = await lerEst();
  await arrastar(0.5, 0.5, 0.3, 0.5);
  eq('arrastar pela bolinha da linha move a linha divisória', (await lerEst()).pos > 0.25 && (await lerEst()).pos < 0.4, true);
  await page.evaluate(() => { ComparativoFotos._teste.estado().pos = 0.5; });
  e0 = await lerEst();
  await arrastar(0.2, 0.3, 0.2, 0.5);
  let e1 = await lerEst();
  eq('arrastar em cima da foto (longe da linha) enquadra a foto daquele lado e não mexe na linha', [e1.pos === e0.pos, e1.a.oy !== e0.a.oy, e1.b.oy === e0.b.oy], [true, true, true]);
  await page.evaluate(() => { ComparativoFotos._teste.estado().pos = 0.5; });
  e0 = await lerEst();
  await arrastar(0.8, 0.3, 0.8, 0.5);
  e1 = await lerEst();
  eq('do lado direito, quem se mexe é a foto de depois', [e1.pos === e0.pos, e1.b.oy !== e0.b.oy, e1.a.oy === e0.a.oy], [true, true, true]);
  // pinça com dois dedos (eventos de ponteiro simulados) sobre a foto de depois
  const pinca = (cx, fx0, fx1) => page.evaluate(([cx, fx0, fx1]) => {
    const cv = document.getElementById('cf-prev'), r = cv.getBoundingClientRect(), y = r.top + r.height * 0.4;
    const ev = (t, id, x) => cv.dispatchEvent(new PointerEvent(t, { pointerId: id, clientX: r.left + r.width * x, clientY: y, bubbles: true, cancelable: true, pointerType: 'touch' }));
    ev('pointerdown', 1, cx - fx0); ev('pointerdown', 2, cx + fx0);
    for (let i = 1; i <= 6; i++) { const f = fx0 + (fx1 - fx0) * i / 6; ev('pointermove', 1, cx - f); ev('pointermove', 2, cx + f); }
    ev('pointerup', 1, cx - fx1); ev('pointerup', 2, cx + fx1);
  }, [cx, fx0, fx1]);
  e0 = await lerEst();
  await pinca(0.75, 0.05, 0.14);
  e1 = await lerEst();
  eq('a pinça com dois dedos sobre a foto de depois dá zoom nela, e só nela', [e1.b.z > 1.5, e1.a.z === e0.a.z, e1.pos === e0.pos], [true, true, true]);
  e0 = await lerEst();
  await pinca(0.75, 0.14, 0.05);
  eq('afastar e juntar os dedos aproxima e afasta (volta perto de 1)', (await lerEst()).b.z < e0.b.z, true);
  await page.evaluate(() => ComparativoFotos.zoom('b', 200));
  eq('o controle de zoom vale só para a foto indicada', [(await lerEst()).b.z, (await lerEst()).a.z <= 1.0001], [2, true]);
  await page.evaluate(() => { const e = ComparativoFotos._teste.estado(); e.a.zoom = 1; e.b.zoom = 1; e.a.oy = e.b.oy = 0; });
  eq('no lado a lado cada painel recebe o próprio gesto', await page.evaluate(async () => {
    ComparativoFotos.modo('lado');
    const cv = document.getElementById('cf-prev'), r = cv.getBoundingClientRect();
    const mover = (x0, x1, y0, y1) => { const ev = (t, x, y) => cv.dispatchEvent(new PointerEvent(t, { pointerId: 5, clientX: r.left + r.width * x, clientY: r.top + r.height * y, bubbles: true, cancelable: true })); ev('pointerdown', x0, y0); ev('pointermove', x1, y1); ev('pointerup', x1, y1); };
    const e = ComparativoFotos._teste.estado();
    e.a.zoom = 2; e.b.zoom = 2;
    mover(0.25, 0.25, 0.4, 0.55);
    const apos1 = [e.a.oy !== 0, e.b.oy === 0];
    mover(0.75, 0.75, 0.4, 0.55);
    return [apos1, e.b.oy !== 0];
  }), [[true, true], true]);
  await page.evaluate(() => ComparativoFotos.modo('divisor'));

  console.log('\n══ Formato (molde) ══');
  const tam = (f) => page.evaluate((f) => { ComparativoFotos.formato(f); const cv = document.createElement('canvas'); ComparativoFotos._teste.compor(cv, 1); const pv = document.getElementById('cf-prev'); const r = pv.getBoundingClientRect(); return { w: cv.width, h: cv.height, razao: Math.round(r.height / r.width * 1000) / 1000, g: ComparativoFotos._teste.geometria().ah }; }, f);
  let t1 = await tam('quadrado');
  eq('quadrado: imagem 1080x1080 e prévia quadrada', [t1.w, t1.h, t1.razao], [1080, 1080, 1]);
  t1 = await tam('stories');
  eq('stories: imagem 1080x1920 e prévia 9:16', [t1.w, t1.h, t1.razao], [1080, 1920, 1.778]);
  t1 = await tam('retrato');
  eq('retrato: volta a 1080x1350', [t1.w, t1.h, t1.razao], [1080, 1350, 1.25]);
  const pixF = (f, modo) => page.evaluate(([f, modo]) => { ComparativoFotos.formato(f); ComparativoFotos._teste.estado().modo = modo; const cv = document.createElement('canvas'); ComparativoFotos._teste.compor(cv, 1); const g = ComparativoFotos._teste.geometria(); const x = cv.getContext('2d'); const p = (px, py) => Array.from(x.getImageData(Math.round(px), Math.round(py), 1, 1).data).slice(0, 3); return { esq: p(g.a.x + g.a.w * 0.5, g.a.y + g.a.h * 0.5), dir: p(g.b.x + g.b.w * 0.5, g.b.y + g.b.h * 0.5), dentro: g.a.y + g.a.h < cv.height - 60 && g.b.y > 100 }; }, [f, modo]);
  let pf = await pixF('stories', 'lado');
  eq('stories lado a lado: cada foto no seu painel e tudo dentro da imagem', [pf.esq, pf.dir, pf.dentro], [[192, 57, 43], [41, 128, 185], true]);
  pf = await pixF('quadrado', 'lado');
  eq('quadrado lado a lado: cada foto no seu painel e tudo dentro da imagem', [pf.esq, pf.dir, pf.dentro], [[192, 57, 43], [41, 128, 185], true]);
  await page.evaluate(() => { ComparativoFotos.formato('retrato'); ComparativoFotos.modo('divisor'); });
  eq('as fotos escolhidas continuam ao trocar de formato', await page.evaluate(() => { const e = ComparativoFotos._teste.estado(); return [!!e.a.img, !!e.b.img]; }), [true, true]);

  console.log('\n══ Salvar como avaliação ══');
  await page.evaluate(() => ComparativoFotos.salvar());
  await page.waitForTimeout(600);
  const av = pedidos.avaliacao || {};
  eq('cria uma avaliação do aluno com método de comparativo e uma imagem JPEG', [av.idatleta, av.metodo, av.origem, Array.isArray(av.fotos) && av.fotos.length, String((av.fotos || [])[0]).slice(0, 23)], [7, 'Comparativo do aluno', 'aluno', 1, 'data:image/jpeg;base64,']);
  eq('não grava peso na avaliação (o peso da imagem é só de exibição)', 'peso' in av, false);
  eq('o botão passa a mostrar que já está salvo', await page.evaluate(() => document.getElementById('cf-btn-salvar').textContent.trim()), '✓ Salvo na sua avaliação');

  console.log('\n══ Compartilhar: o Feed só publica depois de confirmar ══');
  await page.evaluate(() => ComparativoFotos.compartilhar());
  eq('a folha abre com quem pode ver = Todos por padrão', await page.evaluate(() => { const bs = Array.from(document.querySelectorAll('#cf-share button')).filter(x => /Todos|Amigos|Só eu/.test(x.textContent)); return bs.map(x => [x.textContent.trim(), x.style.background.indexOf('127, 255, 0') >= 0]); }), [['🌎 Todos', true], ['👥 Amigos', false], ['🙋 Só eu', false]]);
  await page.evaluate(() => ComparativoFotos.confirmar('feed'));
  const conf = await page.evaluate(() => { const e = document.getElementById('cf-confirma'); return e ? e.textContent : null; });
  eq('pede confirmação dizendo onde vai aparecer e quem vai ver', [/Publicar no Feed da Intus\?/.test(conf || ''), /Todos os alunos da Intus/.test(conf || ''), /fotos do seu corpo/.test(conf || '')], [true, true, true]);
  eq('até confirmar, nada foi publicado', pedidos.feedChamadas, 0);
  await page.evaluate(() => Array.from(document.querySelectorAll('#cf-confirma button')).find(x => /Sim, publicar/.test(x.textContent)).click());
  await page.waitForTimeout(700);
  eq('depois do "Sim", publica no Feed para todos', [pedidos.feedChamadas, pedidos.feed && pedidos.feed.destino, pedidos.feed && pedidos.feed.visib, String((pedidos.feed || {}).imagem).slice(0, 15)], [1, 'feed', 'todos', 'data:image/jpeg']);
  await page.evaluate(() => { ComparativoFotos.compartilhar(); ComparativoFotos.setVisib('amigos'); ComparativoFotos.confirmar('perfil'); });
  eq('com Amigos o aviso muda para "Só os seus amigos"', await page.evaluate(() => /Só os seus amigos/.test(document.getElementById('cf-confirma').textContent)), true);
  await page.evaluate(() => Array.from(document.querySelectorAll('#cf-confirma button')).find(x => x.textContent.trim() === 'Voltar').click());
  await page.waitForTimeout(200);
  eq('"Voltar" não publica', pedidos.feedChamadas, 1);

  eq('sem erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
