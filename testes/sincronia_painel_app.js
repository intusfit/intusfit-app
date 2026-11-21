// Painel e app do aluno mostram a MESMA situação de matrícula, atraso e validade de ficha (21/11/2026): o app (aluno.html) só traduz o que o api.js decide.
// Roda o app de verdade em vários "hoje" (inclusive fim de semana e feriado) e compara com a função do painel.
//   cd testes && INTUS_DIR=../app/painel node sincronia_painel_app.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const M = (id, extra) => Object.assign({ idmensalidade: id, idatleta: 7, dshistorico: 'Personal 4x', tipo_plano: 'mensal', stpgto: 'S', stmatricula: 'ativa', vlpagar: 1000, recorrencia: 'mensal' }, extra);
const CENARIOS = {
  'pago, terminou na sexta feriado (20/11)': [M(1, { dtinicio: '2026-10-20', dtvencimento: '2026-11-20' })],
  'pago, terminou no sábado (14/11)': [M(1, { dtinicio: '2026-10-14', dtvencimento: '2026-11-14' })],
  'pago, terminou na quarta (18/11)': [M(1, { dtinicio: '2026-10-18', dtvencimento: '2026-11-18' })],
  'pago, vigente até 20/12': [M(1, { dtinicio: '2026-11-20', dtvencimento: '2026-12-20' })],
  'cobrança de 20/11 em aberto': [M(1, { dtinicio: '2026-11-20', dtvencimento: '2026-12-20', stpgto: 'N' })],
};
const FICHAS = [{ idficha: 1, idatleta: 7, ativo: 1, dtinicio: '2026-09-01', dtfim: '2026-11-20' }, { idficha: 2, idatleta: 7, ativo: 1, dtinicio: '2026-09-01', dtfim: '2026-11-14' }, { idficha: 3, idatleta: 7, ativo: 1, dtinicio: '2026-09-01', dtfim: '2026-12-31' }, { idficha: 4, idatleta: 7, ativo: 1, dtinicio: '2026-09-01', dtfim: '2026-11-23' }];
const DATAS = ['2026-11-19', '2026-11-21', '2026-11-23', '2026-11-24', '2026-11-26', '2026-11-28', '2026-11-30'];   // quinta, sábado, segunda, terça, quinta, sábado, segunda

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8165, '127.0.0.1', r));
  const b = await chromium.launch();
  for (const hoje of DATAS) {
    console.log('\n══ Hoje = ' + hoje + ' ══');
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.clock.setFixedTime(hoje + 'T12:00:00');
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
    await page.goto('http://127.0.0.1:8165/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _matSituacao === 'function' && typeof API !== 'undefined' && typeof carregarFicha === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(800);
    for (const [nome, lista] of Object.entries(CENARIOS)) {
      const r = await page.evaluate((l) => {
        localStorage.setItem('intus-mensalidades', JSON.stringify(l));
        const painel = API.situacaoPlano({ idatleta: 7 }, l);
        const app = _matSituacao();
        const fim = l.map(m => m.dtvencimento).sort().pop();
        return { painel: { estado: painel.estado, d: painel.diasVencido }, app: { vigente: app.vigente, atraso: app.carencia ? app.carencia.diasAtraso : null }, tela: calcularDiasAtraso(), esperadoTela: Math.max(0, API.diasDesde(fim)) };
      }, lista);
      const vigEsperado = r.painel.estado === 'ativo' && !(r.painel.d > 0);
      const atrasoEsperado = r.painel.estado === 'ativo' && r.painel.d > 0 ? r.painel.d : null;
      eq(nome + ': o app (vigente, dias de carência) é o do painel', [r.app.vigente, r.app.atraso], [vigEsperado, atrasoEsperado]);
      if (!/aberto/.test(nome)) eq(nome + ': dias de atraso do bloqueio = painel', r.tela, r.esperadoTela);
    }
    const f = await page.evaluate((fs) => { localStorage.setItem('intus-fichas', JSON.stringify(fs)); localStorage.setItem('intus-treinos', '[]'); return { app: carregarFicha().map(x => x.idficha).sort(), painel: fs.filter(x => API.fichaVigente(x)).map(x => x.idficha).sort() }; }, FICHAS);
    eq('fichas que o aluno vê = fichas vigentes do painel', f.app, f.painel);
    eq('sem erro de script', erros, []);
    await ctx.close();
  }
  // Valores conhecidos, para o teste não concordar consigo mesmo por engano
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage();
  await page.clock.setFixedTime('2026-11-24T12:00:00');
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
  await page.goto('http://127.0.0.1:8165/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _matSituacao === 'function' && typeof API !== 'undefined').catch(() => false)) break; await page.waitForTimeout(250); }
  const conhecido = await page.evaluate((l) => { localStorage.setItem('intus-mensalidades', JSON.stringify(l)); const s = _matSituacao(); return { vigente: s.vigente, carencia: s.carencia }; }, CENARIOS['pago, terminou na sexta feriado (20/11)']);
  eq('terça 24/11, plano terminado na sexta feriado 20/11: 1 dia de carência (e não 4), restam 5', conhecido, { vigente: false, carencia: { diasAtraso: 1, restantes: 5 } });
  await ctx.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
