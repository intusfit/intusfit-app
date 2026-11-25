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

  console.log('\n══ Gestos na prévia ══');
  await page.evaluate(() => ComparativoFotos.modo('lado')); await page.evaluate(() => ComparativoFotos.modo('divisor'));
  const box = await page.evaluate(() => { const r = document.getElementById('cf-prev').getBoundingClientRect(); return { x: r.left, y: r.top + scrollY, w: r.width, h: r.height }; });
  await page.evaluate(() => window.scrollTo(0, document.getElementById('cf-prev').getBoundingClientRect().top + scrollY - 20));
  const topo = await page.evaluate(() => document.getElementById('cf-prev').getBoundingClientRect().top);
  await page.mouse.move(box.x + box.w * 0.5, topo + box.h * 0.5); await page.mouse.down(); await page.mouse.move(box.x + box.w * 0.3, topo + box.h * 0.5, { steps: 5 }); await page.mouse.up();
  const pos = await page.evaluate(() => ComparativoFotos._teste.estado().pos);
  
  eq('arrastar na prévia move a linha divisória', pos > 0.25 && pos < 0.4, true);
  await page.evaluate(() => ComparativoFotos.ferr('b')); await page.evaluate(() => ComparativoFotos.zoom(200));
  const zoomB = await page.evaluate(() => ComparativoFotos._teste.estado().b.zoom);
  eq('o zoom vale só para a foto selecionada', [zoomB, await page.evaluate(() => ComparativoFotos._teste.estado().a.zoom)], [2, 1]);

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
