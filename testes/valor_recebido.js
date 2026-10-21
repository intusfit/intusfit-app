// Valor recebido editável na baixa e no próximo ciclo (api.js, mensalidades.html, alunos.html).
//   cd testes && INTUS_DIR=../app/painel node valor_recebido.js
const { carregar } = require('./_api.js');
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  console.log('\n══ A conta (API.calcularRecebimento) ══');
  const API = (carregar({ hoje: '2026-10-21' }).API || carregar({ hoje: '2026-10-21' }));
  const M = { vlpagar: 1000, vldesconto: 0 };
  let c = API.calcularRecebimento(M, 0, '');
  eq('vazio = o combinado, nada muda', [c.ok, c.total, c.diferente, c.vlpagarCiclo], [true, 1000, false, 1000]);
  c = API.calcularRecebimento(M, 0, '1000');
  eq('digitar o mesmo valor também não muda nada', [c.diferente, c.vlpagarCiclo], [false, 1000]);
  c = API.calcularRecebimento(M, 0, '850');
  eq('outro valor: recebido 850, o ciclo passa a valer 850, desconto zera', [c.ok, c.total, c.diferente, c.diferenca, c.vlpagarCiclo, c.vldesconto], [true, 850, true, -150, 850, 0]);
  c = API.calcularRecebimento(M, 0, '1.200,50'.replace('.', '').replace(',', '.'));
  eq('valor maior também vale (acordo de mais)', [c.total, c.diferenca, c.vlpagarCiclo], [1200.5, 200.5, 1200.5]);
  c = API.calcularRecebimento(M, 0, '850,5');
  eq('aceita vírgula', c.total, 850.5);
  c = API.calcularRecebimento({ vlpagar: 1000, vldesconto: 100 }, 0, '');
  eq('o padrão já desconta o desconto cadastrado', [c.padrao, c.total, c.diferente], [900, 900, false]);
  c = API.calcularRecebimento(M, 30, '');
  eq('o padrão soma a multa/juros', [c.padrao, c.total, c.diferente], [1030, 1030, false]);
  c = API.calcularRecebimento(M, 30, '900');
  eq('com multa: o ciclo vale o recebido MENOS a multa (a multa não vira valor do plano)', [c.total, c.vlpagarCiclo, c.juros], [900, 870, 30]);
  eq('zero é recusado', API.calcularRecebimento(M, 0, '0').ok, false);
  eq('negativo é recusado', API.calcularRecebimento(M, 0, '-5').ok, false);
  eq('texto é recusado', API.calcularRecebimento(M, 0, 'abc').ok, false);
  c = API.calcularRecebimento(M, 50, '40');
  eq('recebido menor que a multa é recusado', [c.ok, /multa/.test(c.erro)], [false, true]);
  eq('centavos não acumulam erro de ponto flutuante', API.calcularRecebimento({ vlpagar: 0.1, vldesconto: 0 }, 0.2, '').total, 0.3);

  console.log('\n══ A janela de pagamento (mensalidades.html, Chromium) ══');
  let id = 1;
  const C = (ini, venc, pg, valor, extra) => Object.assign({ idmensalidade: id++, idatleta: 1, nmathleta: 'Aluno Combinou', dshistorico: 'Personal 4x', recorrencia: 'mensal', dtinicio: ini, dtvencimento: venc, dtpagamento: pg || null, vlpagar: valor, vldesconto: 0, vljuro: 0, stpgto: pg ? 'S' : 'N', stmatricula: 'ativa', forma_pgto: 'pix', professor: 'Luiz' }, extra || {});
  const MENS = [C('2026-08-20', '2026-09-20', '2026-09-18', 1000), C('2026-09-20', '2026-10-20', null, 1000)];
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/mensalidades.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8108, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 1400, height: 1000 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(e.message));
  page.on('dialog', d => d.accept());
  const S = { put: [], post: [] };
  await page.route(u => u.href.includes('.php'), async (r) => {
    const req = r.request(), u = req.url(), met = req.method();
    if (u.includes('mensalidades.php')) {
      if (met === 'PUT') { S.put.push(JSON.parse(req.postData())); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
      if (met === 'POST') { S.post.push(JSON.parse(req.postData())); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"idmensalidade":99}' }); }
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(MENS) });
    }
    if (u.includes('atletas.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idatleta: 1, nome: 'Aluno Combinou', email: 'a@x.com', origem: 'professor', professores_responsaveis: [1], stbloqueio: 'N' }]) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.addInitScript(({ MENS, HOJE }) => { const _R = Date; window.Date = function (...a) { return a.length ? new _R(...a) : new _R(HOJE + 'T12:00:00'); }; window.Date.prototype = _R.prototype; window.Date.now = () => new _R(HOJE + 'T12:00:00').getTime(); window.Date.UTC = _R.UTC; window.Date.parse = _R.parse;
    localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); localStorage.setItem('intus-mensalidades', JSON.stringify(MENS)); }, { MENS, HOJE: '2026-10-21' });
  await page.goto('http://127.0.0.1:8108/mensalidades.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1800);
  const abrir = async () => { await page.evaluate(() => { closeModal && closeModal(); marcarPago(2); }); await page.waitForSelector('#pg-valor', { timeout: 4000 }); };

  await abrir();
  eq('o campo "Valor recebido" nasce com o combinado', await page.$eval('#pg-valor', e => e.value), '1000.00');
  ver('sem mudar nada, não aparece aviso nem a opção de manter o valor', await page.evaluate(() => document.getElementById('pg-valor-nota').style.display === 'none' && document.getElementById('pg-manter-wrap').style.display === 'none'));
  await page.fill('#pg-valor', '850');
  ver('digitando outro valor, a janela explica a diferença (R$ 1000,00 -> R$ 850,00, −R$ 150,00)', await page.evaluate(() => /1000,00/.test(document.getElementById('pg-valor-nota').textContent) && /850,00/.test(document.getElementById('pg-valor-nota').textContent) && /150,00/.test(document.getElementById('pg-valor-nota').textContent)), await page.$eval('#pg-valor-nota', e => e.textContent));
  ver('e oferece "Cobrar este valor também nos próximos ciclos"', await page.evaluate(() => document.getElementById('pg-manter-wrap').style.display === 'block'));
  await page.fill('#pg-valor', '0');
  ver('zero mostra o erro na janela', await page.evaluate(() => /maior que zero/.test(document.getElementById('pg-valor-nota').textContent)));
  await page.click('.modal-footer .btn-success');
  await page.waitForTimeout(400);
  eq('valor inválido NÃO envia nada ao servidor e mantém a janela aberta', [S.put.length, await page.evaluate(() => !!document.getElementById('pg-valor'))], [0, true]);

  console.log('\n══ Valor combinado só neste mês ══');
  await page.fill('#pg-valor', '850'); await page.fill('#pg-data', '2026-10-21');
  await page.click('.modal-footer .btn-success');
  await page.waitForTimeout(900);
  const p1 = S.put[0] || {};
  eq('a baixa grava recebido 850, ciclo 850, desconto 0, pago', [p1.vlpagamento, p1.vlpagar, p1.vldesconto, p1.stpgto, p1.dtpagamento], [850, 850, 0, 'S', '2026-10-21']);
  eq('o próximo ciclo continua com o valor do plano (1000), não com o acordo', [S.post.length, S.post[0] && S.post[0].vlpagar, S.post[0] && S.post[0].dtinicio], [1, 1000, '2026-10-20']);

  console.log('\n══ Manter o valor nos próximos ciclos ══');
  S.put = []; S.post = [];
  await abrir(); await page.fill('#pg-valor', '900'); await page.check('#pg-manter'); await page.fill('#pg-data', '2026-10-21');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(900);
  eq('marcando a opção, o próximo ciclo nasce com o valor combinado (900)', [S.put[0] && S.put[0].vlpagamento, S.post[0] && S.post[0].vlpagar], [900, 900]);

  console.log('\n══ Sem mexer: igual ao de sempre ══');
  S.put = []; S.post = [];
  await abrir(); await page.fill('#pg-data', '2026-10-21');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(900);
  eq('valor padrão: recebido 1000, ciclo 1000, próximo 1000', [S.put[0] && S.put[0].vlpagamento, S.put[0] && S.put[0].vlpagar, S.post[0] && S.post[0].vlpagar], [1000, 1000, 1000]);

  console.log('\n══ Renovar (↻) com valor editável ══');
  S.put = []; S.post = [];
  await page.evaluate(() => { MENS_FIX = null; });
  // matrícula sem ciclo em aberto: o último ciclo está pago
  await page.evaluate(() => { mensalidades = mensalidades.map(m => Number(m.idmensalidade) === 2 ? Object.assign({}, m, { stpgto: 'S', dtpagamento: '2026-10-21' }) : m); });
  await page.evaluate(() => { closeModal && closeModal(); renovarCiclo(2); });
  await page.waitForSelector('#rn-valor', { timeout: 4000 });
  eq('a janela de renovação mostra o valor do plano para editar', await page.$eval('#rn-valor', e => e.value), '1000.00');
  await page.fill('#rn-valor', '780');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(900);
  eq('o ciclo novo nasce com o valor digitado', [S.post.length, S.post[0] && S.post[0].vlpagar, S.post[0] && S.post[0].stpgto], [1, 780, 'N']);
  S.post = [];
  await page.evaluate(() => { closeModal && closeModal(); renovarCiclo(2); });
  await page.waitForSelector('#rn-valor'); await page.fill('#rn-valor', '');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(500);
  eq('valor vazio na renovação não cria nada', S.post.length, 0);

  console.log('\n══ Estrutura ══');
  const al = fs.readFileSync(path.join(RAIZ, 'alunos.html'), 'utf8');
  ver('a ficha do aluno (alunos.html) tem o mesmo campo e a mesma conta', /id="pgm-valor"/.test(al) && /API\.calcularRecebimento\(m, juros/.test(al));
  ver('a renovação da ficha só leva o valor combinado adiante se pedido', /vlpagar: manterValor \? calc\.vlpagarCiclo : vlOriginal/.test(al));
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
