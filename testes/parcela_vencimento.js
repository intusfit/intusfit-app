// Editar a matrícula de uma PARCELA (mensalidades.html): o dia de cobrança aparece como "Vencimento da parcela" e a parcela anterior acompanha (09/11/2026).
//   cd testes && INTUS_DIR=../app/painel node parcela_vencimento.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  const P = (id, n, ini, venc, pg) => ({ idmensalidade: id, idatleta: 1, nmathleta: 'Ana Teste', dshistorico: 'Treino + Dieta — Trimestral (' + n + '/2)', recorrencia: null, dtinicio: ini, dtvencimento: venc, dtpagamento: pg || null, vlpagar: 297, vldesconto: 0, vljuro: 0, stpgto: pg ? 'S' : 'N', stmatricula: 'ativa', forma_pgto: 'pix', professor: 'Luiz', parcela_num: n, parcela_total: 2, idmatricula_origem: n === 1 ? null : 1, tipo_plano: 'Treino' });
  const MENS = [P(1, 1, '2026-09-10', '2026-11-10', '2026-09-10'), P(2, 2, '2026-11-10', '2027-01-05')];
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/mensalidades.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8137, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 1400, height: 1000 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(e.message));
  const S = { put: [] };
  await page.route(u => u.href.includes('.php'), async (r) => {
    const req = r.request(), u = req.url(), met = req.method();
    if (u.includes('mensalidades.php')) {
      if (met === 'PUT') { S.put.push({ id: Number((u.match(/id=(\d+)/) || [])[1]), corpo: JSON.parse(req.postData()) }); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(MENS) });
    }
    if (u.includes('atletas.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idatleta: 1, nome: 'Ana Teste', email: 'a@x.com', origem: 'professor', professores_responsaveis: [1], stbloqueio: 'N' }]) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.addInitScript(({ MENS }) => { localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); localStorage.setItem('intus-mensalidades', JSON.stringify(MENS)); }, { MENS });
  await page.goto('http://127.0.0.1:8137/mensalidades.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1800);
  const abrir = async (id) => { await page.evaluate((id) => { closeModal && closeModal(); abrirModalEditar(id); }, id); await page.waitForSelector('#mat-dtinicio', { timeout: 4000 }); };
  const rotulos = () => page.evaluate(() => [...document.querySelectorAll('.modal-body .form-2col')[0].querySelectorAll('label')].map(l => l.textContent.trim()));

  console.log('\n══ Rótulos ══');
  await abrir(2);
  eq('última parcela: "Vencimento da parcela (cobrança)" e "Fim do plano"', await rotulos(), ['Vencimento da parcela (cobrança)', 'Fim do plano']);
  await abrir(1);
  eq('parcela do meio ou primeira: "Cobertura até"', await rotulos(), ['Vencimento da parcela (cobrança)', 'Cobertura até']);

  console.log('\n══ Mudar o vencimento da parcela 2 ══');
  await abrir(2);
  await page.fill('#mat-dtinicio', '2026-11-20');
  await page.click('.modal-footer .btn-primary');
  await page.waitForTimeout(1000);
  const p2 = S.put.find(x => x.id === 2), p1 = S.put.find(x => x.id === 1);
  eq('a parcela 2 grava a nova cobrança e mantém o fim do plano', [p2 && p2.corpo.dtinicio, p2 && p2.corpo.dtvencimento], ['2026-11-20', '2027-01-05']);
  eq('o fim da parcela 1 acompanha (sem buraco entre as duas)', [p1 && p1.corpo], [{ dtvencimento: '2026-11-20' }]);

  console.log('\n══ Data inválida ══');
  S.put = [];
  await abrir(2);
  await page.fill('#mat-dtinicio', '2027-02-01');
  await page.click('.modal-footer .btn-primary');
  await page.waitForTimeout(600);
  eq('cobrança depois do fim do plano: nada é gravado e a janela fica aberta', [S.put.length, await page.evaluate(() => !!document.getElementById('mat-dtinicio'))], [0, true]);
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? 'FALHARAM ' + ruim : 'Tudo certo') + ' (' + ok + ' ok)');
  process.exit(ruim ? 1 : 0);
})();
