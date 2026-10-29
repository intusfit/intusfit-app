// Frequência (painel): "⏱ Corrigir duração", só para admin, em treino de musculação. Caso da Rosângela (29/10/2026): o app gravou 0:36 e "não conta"
// para um treino de 1h14. A correção regrava a MESMA sessão (PUT treinos.php?action=sessoes&id=N) com a duração informada, mantendo séries,
// comentário e hora, e devolve o treino ao ranking. A API é simulada: nada vai ao servidor de verdade.
//   cd testes && INTUS_DIR=../app/painel node frequencia_corrigir.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const hoje = (() => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
const ITENS = [{ idexercicio: 1, nmexercicio: 'Agachamento', series: [{ peso: '40', reps: '12' }, { peso: '40', reps: '10' }] }];
const sessaoErrada = () => ({ idsessao: 99, idatleta: 7, idficha: 5, divisao: 'A', dtsessao: hoje, duracao_seg: 36, nao_contar: 1, tipo: 'musculacao', pontos: 0, itens: JSON.parse(JSON.stringify(ITENS)), comentario: 'Foi bom', marcado_como_feito: 0, hora_conclusao: '18:28' });

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8133, '127.0.0.1', r));
  const b = await chromium.launch();

  async function abrir(admin, putResp) {
    const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    const puts = []; const dialogos = { alert: [], confirm: [], prompt: [] };
    await page.route(/\/app\/api\//, async r => {
      const u = r.request().url(), m = r.request().method();
      if (/atletas\.php/.test(u) && m === 'GET') return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idatleta: 7, nome: 'Rosangela de Souza', email: 'r@x.com', professores_responsaveis: [1, 2] }]) });
      if (/treinos\.php\?action=sessoes/.test(u) && m === 'GET') return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([sessaoErrada()]) });
      if (/treinos\.php\?action=sessoes&id=99/.test(u) && m === 'PUT') { puts.push(JSON.parse(r.request().postData())); return r.fulfill({ status: putResp.status, contentType: 'application/json', body: JSON.stringify(putResp.body) }); }
      return r.fulfill({ status: 404, contentType: 'application/json', body: '{}' });   // o api.js só usa o servidor se a verificação de conexão receber alguma resposta HTTP
    });
    await page.addInitScript(adm => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: adm ? 1 : 2, nome: adm ? 'Luiz' : 'Professor', admin: adm, permissoes: {} })); }, admin);
    await page.goto('http://127.0.0.1:8133/frequencia.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 60; i++) { if (await page.evaluate(() => typeof loading !== 'undefined' && loading === false && atletas.length > 0 && sessoes.length > 0).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.evaluate(() => { window.__resp = { prompt: '74', confirm: true }; window.__d = []; window.prompt = (m, v) => { window.__d.push(['prompt', m]); return window.__resp.prompt; }; window.confirm = m => { window.__d.push(['confirm', m]); return window.__resp.confirm; }; window.alert = m => { window.__d.push(['alert', m]); }; });
    await page.evaluate(() => abrirDetalheAluno(7));
    return { page, ctx, erros, puts };
  }
  const botao = page => page.evaluate(() => !!document.querySelector('#freq-detail-modal button[onclick^="corrigirDuracaoSessao"]'));
  const dlg = page => page.evaluate(() => window.__d);

  console.log('\n══ Admin: corrige 0:36 para 74 min ══');
  let { page, ctx, erros, puts } = await abrir(true, { status: 200, body: { ok: true, idsessao: 99 } });
  ver('a lista do aluno mostra o treino com 0 min e o botão "Corrigir duração"', (await page.evaluate(() => document.getElementById('freq-detail-modal').innerText)).includes('0min') && await botao(page));
  await page.evaluate(() => corrigirDuracaoSessao(99, 7));
  await page.waitForTimeout(600);
  eq('uma PUT, e só uma', puts.length, 1);
  const p = puts[0] || {};
  eq('duração 74 min (4440 s) e volta a contar', [p.duracao_seg, p.nao_contar], [4440, 0]);
  eq('séries, comentário e hora de conclusão iguais aos de antes', [p.itens, p.comentario, p.hora_conclusao, p.marcado_como_feito], [ITENS, 'Foi bom', '18:28', 0]);
  const d = await dlg(page);
  ver('perguntou a duração e confirmou com o nome da aluna', d.some(x => x[0] === 'prompt') && d.some(x => x[0] === 'confirm' && /Rosangela de Souza/.test(x[1]) && /74 min/.test(x[1])), d);
  const ap = await page.evaluate(() => ({ dur: sessoes[0].duracao_seg, nc: sessoes[0].nao_contar, modal: document.getElementById('freq-detail-modal').innerText, conta: getSessoesFiltradas().some(s => s.idsessao === 99) }));
  eq('na tela o treino passa a 74 min e entra na contagem do ranking e da frequência', [ap.dur, ap.nc, ap.conta, /74min/.test(ap.modal)], [4440, 0, true, true]);
  ver('o treino mostra pontos (antes não mostrava, por ser "não conta")', /\+\d+ pts/.test(ap.modal), ap.modal);
  eq('sem erro de script', erros, []);
  await ctx.close();

  console.log('\n══ Cancelar, valor inválido e servidor recusando não mudam nada ══');
  ({ page, ctx, erros, puts } = await abrir(true, { status: 200, body: { ok: true } }));
  await page.evaluate(() => { window.__resp.prompt = null; }); await page.evaluate(() => corrigirDuracaoSessao(99, 7));
  eq('cancelou a pergunta: nenhuma PUT', puts.length, 0);
  await page.evaluate(() => { window.__resp.prompt = '3'; }); await page.evaluate(() => corrigirDuracaoSessao(99, 7));
  eq('3 min (abaixo de 5): nenhuma PUT e aviso', [puts.length, (await dlg(page)).some(x => x[0] === 'alert')], [0, true]);
  await page.evaluate(() => { window.__resp.prompt = '500'; }); await page.evaluate(() => corrigirDuracaoSessao(99, 7));
  eq('500 min (acima de 240): nenhuma PUT', puts.length, 0);
  await page.evaluate(() => { window.__resp.prompt = '74'; window.__resp.confirm = false; }); await page.evaluate(() => corrigirDuracaoSessao(99, 7));
  eq('não confirmou: nenhuma PUT', puts.length, 0);
  await page.evaluate(() => { window.__resp.confirm = true; sessoes[0].itens = null; }); await page.evaluate(() => corrigirDuracaoSessao(99, 7));
  eq('sem a lista de séries na tela, recusa (a PUT apagaria o treino)', puts.length, 0);
  await ctx.close();

  ({ page, ctx, erros, puts } = await abrir(true, { status: 500, body: { error: 'falha do servidor' } }));
  await page.evaluate(() => corrigirDuracaoSessao(99, 7)); await page.waitForTimeout(500);
  const f = await page.evaluate(() => ({ dur: sessoes[0].duracao_seg, nc: sessoes[0].nao_contar, alertas: window.__d.filter(x => x[0] === 'alert').map(x => x[1]) }));
  eq('servidor recusou: a tela não muda e avisa o motivo', [f.dur, f.nc, f.alertas.length === 1 && /falha do servidor/.test(f.alertas[0])], [36, 1, true]);
  await ctx.close();

  console.log('\n══ Quem não é admin não vê o botão nem consegue usar a função ══');
  ({ page, ctx, erros, puts } = await abrir(false, { status: 200, body: { ok: true } }));
  ver('o modal da aluna abriu para o professor (senão o teste de ausência seria vazio)', await page.evaluate(() => /Rosangela/.test((document.getElementById('freq-detail-modal') || {}).innerText || '')));
  eq('sem botão para professor', await botao(page), false);
  await page.evaluate(() => corrigirDuracaoSessao(99, 7)); await page.waitForTimeout(300);
  eq('chamar a função direto também não faz nada', puts.length, 0);
  await ctx.close();

  await b.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})();
