// Início do app do aluno: aceitar os Termos de Uso no meio da sessão NÃO pode deixar o app meio ligado.
//   Caso real (Vanessa Carissimi, 12/11/2026): aparelho/navegador com o armazenamento limpo, login, tela de Termos, "Aceitar e Continuar".
//   Antes do conserto o início do app (INIT) parava na tela de Termos e `aceitarTermos()` só redesenhava a Início: ficavam sem o ❓ e o 🔔 do cabeçalho
//   e sem o histórico de treinos (as sessões só eram baixadas pelo INIT). A ficha aparecia porque o re-sync ao voltar ao app também baixa as fichas.
//   Também: se a ficha falhar na sincronização, o histórico de treinos precisa ser baixado mesmo assim.
//   cd testes && INTUS_DIR=../app/painel node termos_inicio.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const ID = 134;
const SESSOES = [
  { idsessao: 3209, idatleta: ID, dtsessao: '2026-11-12', divisao: 'C', idficha: 1, duracao_min: 55, itens: [] },
  { idsessao: 2508, idatleta: ID, dtsessao: '2026-10-09', divisao: 'C', idficha: 1, duracao_min: 50, itens: [] },
];
const FICHAS = [{ idficha: 1, idatleta: ID, nome: 'Ficha teste', ativo: 1, dtinicio: '2026-11-01', dtfim: '2026-12-31', divisoes: ['A', 'B', 'C'] }];

async function rodar(b, srvPorta, { termosAceitos, fichasFalham }) {
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => {
    const u = r.request().url();
    const json = (corpo, status) => r.fulfill({ status: status || 200, contentType: 'application/json', body: JSON.stringify(corpo) });
    if (u.includes('action=sessoes') && u.includes('atleta=' + ID)) return json(SESSOES);
    if (u.includes('action=fichas')) return fichasFalham ? json({ error: 'falhou' }, 500) : json(FICHAS);
    if (u.includes('action=treinos') || u.includes('action=exercicios')) return json([]);
    if (u.includes('mensalidades.php')) return json([]);
    return json({});
  });
  await page.addInitScript(([id, aceitos]) => {
    if (sessionStorage.getItem('__semeado')) return; sessionStorage.setItem('__semeado', '1');
    localStorage.setItem('mx-token', 't');
    localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: id, idusuario: id, nome: 'Vanessa Teste', email: 'v@t.com', genero: 'F' }));
    if (aceitos) localStorage.setItem('intus-termos-aceito-' + id, '1');
  }, [ID, termosAceitos]);
  await page.goto('http://127.0.0.1:' + srvPorta + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && typeof SessoesTreino !== 'undefined' && !!document.getElementById('v-termos')).catch(() => false)) break; await page.waitForTimeout(200); }
  await page.waitForTimeout(1200);
  return { ctx, page, erros };
}
const estado = (page) => page.evaluate((id) => ({
  tutorial: document.getElementById('tutorial-btn').style.display,
  sino: document.getElementById('notif-btn').style.display,
  sessoes: SessoesTreino.listarDoAtleta(id).length,
  sincronizado: typeof _syncDone !== 'undefined' ? _syncDone : null,
  termosVisiveis: !!document.getElementById('v-termos') && document.getElementById('v-termos').classList.contains('ativo'),
}), ID);

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8121, '127.0.0.1', r));
  const b = await chromium.launch();

  console.log('\n══ Controle: termos já aceitos neste aparelho ══');
  let t = await rodar(b, 8121, { termosAceitos: true });
  let e = await estado(t.page);
  eq('❓ e 🔔 aparecem no cabeçalho', [e.tutorial, e.sino], ['inline-flex', 'inline-flex']);
  eq('o histórico de treinos foi baixado (2 sessões)', e.sessoes, 2);
  eq('sem erro de script', t.erros, []);
  await t.ctx.close();

  console.log('\n══ Caso da Vanessa: aparelho novo, aceita os Termos depois do login ══');
  t = await rodar(b, 8121, { termosAceitos: false });
  e = await estado(t.page);
  ver('a tela de Termos abre e o app espera', e.termosVisiveis, e);
  eq('antes de aceitar: sem histórico baixado ainda (esperado)', e.sessoes, 0);
  await t.page.evaluate(() => { document.getElementById('aceite-termos').checked = true; aceitarTermos(); });
  for (let i = 0; i < 40; i++) { if ((await estado(t.page)).sessoes > 0) break; await t.page.waitForTimeout(250); }
  await t.page.waitForTimeout(800);
  e = await estado(t.page);
  eq('depois de aceitar: ❓ e 🔔 aparecem no cabeçalho', [e.tutorial, e.sino], ['inline-flex', 'inline-flex']);
  eq('depois de aceitar: o histórico de treinos é baixado (2 sessões)', e.sessoes, 2);
  eq('depois de aceitar: a sincronização terminou', e.sincronizado, true);
  eq('depois de aceitar: a Início está aberta (não a tela de Termos)', await t.page.evaluate(() => window._viewAtual), 'home');
  eq('sem erro de script', t.erros, []);
  await t.ctx.close();

  console.log('\n══ Ficha falha na sincronização: o histórico precisa chegar mesmo assim ══');
  t = await rodar(b, 8121, { termosAceitos: true, fichasFalham: true });
  for (let i = 0; i < 40; i++) { if ((await estado(t.page)).sessoes > 0) break; await t.page.waitForTimeout(250); }
  e = await estado(t.page);
  eq('o histórico de treinos chega (2 sessões) mesmo com a ficha falhando', e.sessoes, 2);
  eq('❓ e 🔔 aparecem mesmo assim', [e.tutorial, e.sino], ['inline-flex', 'inline-flex']);
  await t.ctx.close();

  await b.close(); srv.close();
  console.log('\n' + ok + ' OK, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
