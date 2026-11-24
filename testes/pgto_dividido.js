// Baixa com pagamento dividido em varias formas (api.js, _mock.js PgPartes, mensalidades.html, alunos.html).
//   cd testes && INTUS_DIR=../app/painel node pgto_dividido.js
const { carregar } = require('./_api.js');
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  console.log('\n══ A regra (API.validarPartesPagamento) ══');
  const API = (carregar({ hoje: '2026-10-21' }).API || carregar({ hoje: '2026-10-21' }));
  let r = API.validarPartesPagamento(1000, [{ forma: 'dinheiro', valor: 300 }, { forma: 'pix', valor: '700,00' }]);
  eq('300 em dinheiro + 700 no Pix fecha 1000; a forma principal é o Pix', [r.ok, r.principal, r.partes.length, r.soma], [true, 'pix', 2, 1000]);
  r = API.validarPartesPagamento(1000, [{ forma: 'cartao', valor: 200 }, { forma: 'dinheiro', valor: 300 }, { forma: 'pix', valor: 500 }]);
  eq('três formas, incluindo cartão', [r.ok, r.principal], [true, 'pix']);
  r = API.validarPartesPagamento(1000, [{ forma: 'dinheiro', valor: 300 }, { forma: 'pix', valor: 600 }]);
  eq('faltando: diz quanto falta', [r.ok, /Faltam R\$ 100,00/.test(r.erro)], [false, true]);
  r = API.validarPartesPagamento(1000, [{ forma: 'dinheiro', valor: 500 }, { forma: 'pix', valor: 600 }]);
  eq('passando: diz quanto passa', [r.ok, /passam R\$ 100,00/.test(r.erro)], [false, true]);
  eq('Eduzz e Asaas também podem ser parte', [API.validarPartesPagamento(1000, [{ forma: 'eduzz', valor: 300 }, { forma: 'pix', valor: 700 }]).ok, API.validarPartesPagamento(1000, [{ forma: 'asaas_cartao', valor: 250.5 }, { forma: 'dinheiro', valor: 749.5 }]).ok], [true, true]);
  eq('forma desconhecida não vale', API.validarPartesPagamento(1000, [{ forma: 'paypal', valor: 500 }, { forma: 'pix', valor: 500 }]).ok, false);
  eq('linha sem valor é recusada', API.validarPartesPagamento(1000, [{ forma: 'dinheiro', valor: '' }, { forma: 'pix', valor: 1000 }]).ok, false);
  eq('centavos não acumulam erro (3 x 333,33 + 0,01)', API.validarPartesPagamento(1000, [{ forma: 'pix', valor: 333.33 }, { forma: 'cartao', valor: 333.33 }, { forma: 'dinheiro', valor: 333.34 }]).ok, true);
  eq('rótulo da baixa dividida', API.rotuloPagamento({ forma_pgto: 'pix', partes: [{ forma: 'dinheiro', valor: 300 }, { forma: 'pix', valor: 700 }] }), 'Dinheiro R$ 300,00 + Pix R$ 700,00');
  eq('rótulo da baixa comum segue o de sempre', API.rotuloPagamento({ forma_pgto: 'cartao' }), 'Cartão');

  console.log('\n══ A janela de pagamento (mensalidades.html, Chromium) ══');
  let id = 1;
  const C = (ini, venc, pg, valor, extra) => Object.assign({ idmensalidade: id++, idatleta: 1, nmathleta: 'Erick Teste', dshistorico: 'Personal 4x', recorrencia: 'mensal', dtinicio: ini, dtvencimento: venc, dtpagamento: pg || null, vlpagar: valor, vldesconto: 0, vljuro: 0, stpgto: pg ? 'S' : 'N', stmatricula: 'ativa', forma_pgto: 'pix', professor: 'Luiz' }, extra || {});
  const MENS = [C('2026-08-20', '2026-09-20', '2026-09-18', 1000, { partes: [{ forma: 'dinheiro', valor: 400 }, { forma: 'cartao', valor: 600 }], forma_pgto: 'cartao' }), C('2026-09-20', '2026-10-20', null, 1000)];
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/mensalidades.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8109, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 1400, height: 1100 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(e.message));
  page.on('dialog', d => d.accept());
  const S = { put: [], post: [] };
  await page.route(u => u.href.includes('.php'), async (r) => {
    const req = r.request(), u = req.url(), met = req.method();
    if (u.includes('mensalidades.php')) {
      if (met === 'PUT') { S.put.push(JSON.parse(req.postData())); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"partes_gravadas":true}' }); }
      if (met === 'POST') { S.post.push(JSON.parse(req.postData())); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"idmensalidade":99}' }); }
      return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(MENS) });
    }
    if (u.includes('atletas.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idatleta: 1, nome: 'Erick Teste', email: 'a@x.com', origem: 'professor', professores_responsaveis: [1], stbloqueio: 'N' }]) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.addInitScript(({ MENS, HOJE }) => { const _R = Date; window.Date = function (...a) { return a.length ? new _R(...a) : new _R(HOJE + 'T12:00:00'); }; window.Date.prototype = _R.prototype; window.Date.now = () => new _R(HOJE + 'T12:00:00').getTime(); window.Date.UTC = _R.UTC; window.Date.parse = _R.parse;
    localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); localStorage.setItem('intus-mensalidades', JSON.stringify(MENS)); }, { MENS, HOJE: '2026-10-21' });
  await page.goto('http://127.0.0.1:8109/mensalidades.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1800);
  const abrir = async () => { await page.evaluate(() => { closeModal && closeModal(); marcarPago(2); }); await page.waitForSelector('#pg-valor', { timeout: 4000 }); };
  const linhas = () => page.evaluate(() => [...document.querySelectorAll('#pg-dv-linhas > div')].map(d => [d.querySelector('select').value, d.querySelector('input').value]));
  const status = () => page.evaluate(() => document.getElementById('pg-dv-status').textContent);

  await abrir();
  eq('o convite para dividir aparece e o painel começa fechado', await page.evaluate(() => [document.getElementById('pg-dv-wrap').style.display !== 'none', document.getElementById('pg-dv').style.display]), [true, 'none']);
  await page.click('#pg-dv-link');
  eq('ao dividir: duas linhas (dinheiro e pix) e o seletor único some', [await linhas(), await page.evaluate(() => document.getElementById('pg-forma').style.display)], [[['dinheiro', ''], ['pix', '1000.00']], 'none']);
  await page.fill('#pg-dv-v0', '300');
  eq('digitando 300 em dinheiro, o Pix se completa sozinho com 700', await linhas(), [['dinheiro', '300'], ['pix', '700.00']]);
  eq('e a janela diz que fecha certinho', /fecha certinho/.test(await status()), true);
  await page.fill('#pg-data', '2026-10-21');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(900);
  const p1 = S.put[0] || {};
  eq('a baixa vai ao servidor numa só gravação com as duas partes e o total', [p1.stpgto, p1.vlpagamento, p1.partes], ['S', 1000, [{ forma: 'dinheiro', valor: 300 }, { forma: 'pix', valor: 700 }]]);
  eq('sem um segundo envio só para a forma (o servidor já define a principal)', S.put.filter(x => x.forma_pgto !== undefined && x.partes === undefined).length, 0);

  console.log('\n══ Erros e variações ══');
  S.put.length = 0; await abrir(); await page.click('#pg-dv-link');
  await page.fill('#pg-dv-v0', '300'); await page.fill('#pg-dv-v1', '500');
  eq('soma 800 de 1000: avisa que faltam 200', /faltam R\$ 200,00/.test(await status()), true);
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(500);
  eq('soma que não fecha NÃO envia nada e mantém a janela aberta', [S.put.length, await page.evaluate(() => !!document.getElementById('pg-valor'))], [0, true]);
  await page.click('#pg-dv-add');
  eq('adicionar outra forma cria a 3ª linha (cartão) que completa o que falta', await linhas(), [['dinheiro', '300'], ['pix', '500'], ['cartao', '200.00']]);
  await page.fill('#pg-valor', '850');
  eq('mudando o valor recebido para 850, a última linha acompanha (850 - 300 - 500 = 50)', (await linhas())[2][1], '50.00');
  await page.fill('#pg-dv-v1', '600');
  await page.fill('#pg-valor', '1000'); await page.waitForTimeout(100);
  eq('voltando para 1000 a última linha refaz a conta (1000 - 300 - 600 = 100)', (await linhas())[2][1], '100.00');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(900);
  eq('três formas, com cartão, fecham e vão ao servidor', (S.put[0] || {}).partes, [{ forma: 'dinheiro', valor: 300 }, { forma: 'pix', valor: 600 }, { forma: 'cartao', valor: 100 }]);

  S.put.length = 0; await abrir();
  await page.click('#pg-dv-link'); await page.fill('#pg-dv-v0', '300');
  await page.click('#pg-dv-link');
  eq('"Voltar a uma forma só" desfaz a divisão e mostra o seletor', [await page.evaluate(() => document.getElementById('pg-dv').style.display), await page.evaluate(() => document.getElementById('pg-forma').style.display)], ['none', '']);
  await page.selectOption('#pg-forma', 'dinheiro'); await page.fill('#pg-data', '2026-10-21');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(900);
  eq('baixa de forma única continua como sempre: sem "partes", e a forma vai numa gravação própria', [S.put[0].partes === undefined, S.put.some(x => x.forma_pgto === 'dinheiro')], [true, true]);

  await abrir();
  await page.selectOption('#pg-forma', 'eduzz');
  eq('com Eduzz escolhida o convite para dividir continua (gateway também pode ser parte)', await page.evaluate(() => document.getElementById('pg-dv-wrap').style.display), 'block');

  console.log('\n══ Eduzz e Asaas como parte ══');
  S.put.length = 0;
  await page.click('#pg-dv-link');
  eq('dividindo a partir da Eduzz, a 1ª linha já vem como Eduzz e a nota da Eduzz aparece', [(await linhas())[0][0], /Eduzz/.test(await page.evaluate(() => document.getElementById('pg-dv-status').textContent))], ['eduzz', true]);
  await page.fill('#pg-dv-v0', '300');
  await page.selectOption('#pg-dv-linhas > div:nth-child(2) select', 'asaas_pix');
  eq('300 pela Eduzz + 700 pelo Asaas Pix: fecha, e as notas dos dois gateways aparecem', [await linhas(), /fecha certinho/.test(await status()), /Eduzz/.test(await status()) && /Asaas/.test(await status())], [[['eduzz', '300'], ['asaas_pix', '700.00']], true, true]);
  await page.fill('#pg-data', '2026-10-21');
  await page.click('.modal-footer .btn-success'); await page.waitForTimeout(900);
  eq('a baixa leva as duas partes de gateway ao servidor', (S.put[0] || {}).partes, [{ forma: 'eduzz', valor: 300 }, { forma: 'asaas_pix', valor: 700 }]);
  await abrir();
  await page.selectOption('#pg-forma', 'pix');
  eq('voltando a uma forma comum o convite segue lá', await page.evaluate(() => document.getElementById('pg-dv-wrap').style.display), 'block');

  console.log('\n══ Como a baixa aparece na lista ══');
  await page.evaluate(() => closeModal && closeModal());
  await page.evaluate(() => { const el = document.querySelector('[onclick*="toggleMatExp"]'); if (el) el.click(); });
  const lista = await page.evaluate(() => /Dinheiro R\$ 400,00 \+ Cartão R\$ 600,00/.test(document.body.innerText));
  eq('a lista mostra "Dinheiro R$ 400,00 + Cartão R$ 600,00" sob a data do pagamento (ao expandir os ciclos)', lista || 'oculto', true);

  console.log('\n══ Ficha do aluno (alunos.html) usa o mesmo componente ══');
  const fonte = fs.readFileSync(path.join(RAIZ, 'alunos.html'), 'utf8');
  eq('alunos.html tem o painel de divisão e envia as partes', [/PgPartes\.html\('pgm'/.test(fonte), /dv && dv\.ok \? dv\.partes : null/.test(fonte)], [true, true]);
  eq('sem erro de JavaScript', erros, []);
  await b.close(); srv.close();
  console.log('\n' + ok + ' ok' + (ruim ? ', ' + ruim + ' FALHA(S)' : ''));
  process.exit(ruim ? 1 : 0);
})();
