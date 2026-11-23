// Pagamento dividido em varias formas (inclusive Eduzz e Asaas) e os numeros do Financeiro e da Gestao: tudo em centavos exatos, nada aproximado.
//   cd testes && INTUS_DIR=../app/painel node pgto_financeiro.js
const { carregar } = require('./_api.js');
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

const HOJE = '2026-11-23';
let id = 100;
const P = (nome, at, valor, forma, partes, extra) => Object.assign({ idmensalidade: id++, idatleta: at, nmathleta: nome, dshistorico: 'Personal 4x', recorrencia: 'mensal', dtinicio: '2026-11-01', dtvencimento: '2026-12-01', dtpagamento: '2026-11-10', vlpagar: valor, vldesconto: 0, vljuro: 0, vlpagamento: valor, stpgto: 'S', stmatricula: 'ativa', forma_pgto: forma, professor: 'Luiz' }, partes ? { partes } : {}, extra || {});
const A = P('Ana Casa', 1, 1000, 'pix', [{ forma: 'dinheiro', valor: 300 }, { forma: 'pix', valor: 700 }]);                                  // so formas da casa
const B = P('Bia Eduzz', 2, 1000, 'pix', [{ forma: 'eduzz', valor: 300 }, { forma: 'pix', valor: 700 }]);                                      // parte Eduzz
const Cc = P('Caio Dois Gateways', 3, 1000, 'dinheiro', [{ forma: 'eduzz', valor: 200 }, { forma: 'asaas_pix', valor: 100.5 }, { forma: 'dinheiro', valor: 699.5 }]);
const Dd = P('Duda Centavos', 4, 1000, 'dinheiro', [{ forma: 'pix', valor: 333.33 }, { forma: 'cartao', valor: 333.33 }, { forma: 'dinheiro', valor: 333.34 }]);
const E = P('Eva Eduzz Inteiro', 5, 1000, 'eduzz');                                                                                           // sem divisao, tudo Eduzz
const F = P('Fabio Pix Inteiro', 6, 1000, 'pix');                                                                                             // sem divisao, tudo Pix
const G = P('Gil Com Juros', 7, 1000, 'pix', null, { vlpagamento: 1030 });                                                                    // pagou multa
const MENS = [A, B, Cc, Dd, E, F, G];

(async () => {
  console.log('\n══ As partes (API.pagamentoPartes e valores) ══');
  const API = (carregar({ hoje: HOJE }).API || carregar({ hoje: HOJE }));
  const soma = (m) => Math.round(API.pagamentoPartes(m).reduce((s, p) => s + p.valor * 100, 0)) / 100;
  eq('a soma das partes é SEMPRE o valor recebido (7 casos, centavo por centavo)', MENS.map(soma), [1000, 1000, 1000, 1000, 1000, 1000, 1030]);
  eq('Ana: tudo entra no caixa (dinheiro + Pix), nada de gateway', [API.valorForaDeGateway(A), API.valorDeGateway(A)], [1000, 0]);
  eq('Bia: R$ 700 no caixa e R$ 300 pela Eduzz', [API.valorForaDeGateway(B), API.valorDeGateway(B)], [700, 300]);
  eq('Caio: R$ 699,50 no caixa e R$ 300,50 em gateways', [API.valorForaDeGateway(Cc), API.valorDeGateway(Cc)], [699.5, 300.5]);
  eq('Duda: 333,33 + 333,33 + 333,34 = 1000,00 sem sobra', [API.valorForaDeGateway(Dd), API.valorPorForma(Dd)], [1000, { pix: 333.33, cartao: 333.33, dinheiro: 333.34 }]);
  eq('Eva (sem divisão, Eduzz): tudo gateway como sempre', [API.valorForaDeGateway(E), API.valorDeGateway(E)], [0, 1000]);
  eq('Fabio (sem divisão, Pix): tudo caixa como sempre', [API.valorForaDeGateway(F), API.valorDeGateway(F)], [1000, 0]);
  eq('Gil: o valor recebido inclui a multa (R$ 1.030,00), não só o valor do ciclo', API.valorRecebido(G), 1030);

  console.log('\n══ Vendas de gateway por parte (API.gateway.vendas) ══');
  const vs = API.gateway.vendas(MENS, {}, []);
  eq('quem tem parte de gateway gera uma venda por parte: Bia 1, Caio 2, Eva 1 (Ana, Duda, Fabio, Gil nenhuma)', vs.map((v) => v.quem + ':' + v.gateway + ':' + v.bruto), ['Bia Eduzz:eduzz:300', 'Caio Dois Gateways:eduzz:200', 'Caio Dois Gateways:asaas:100.5', 'Eva Eduzz Inteiro:eduzz:1000']);
  eq('ids únicos por parte; a venda inteira mantém o id de sempre', vs.map((v) => v.id), ['mn-' + B.idmensalidade + '-1', 'mn-' + Cc.idmensalidade + '-1', 'mn-' + Cc.idmensalidade + '-2', 'mn-' + E.idmensalidade]);
  const vb = vs[0];
  eq('a taxa da Eduzz é calculada sobre a PARTE (R$ 300), não sobre o total', [API.eduzzTaxaPlataforma(vb.liq), vb.liquido], [Math.round((300 * 4.9 / 100 + 2.49) * 100) / 100, Math.round((300 - (300 * 4.9 / 100 + 2.49)) * 100) / 100]);
  const vAsaas = vs[2];
  eq('o Asaas Pix da Caio tem a taxa do Asaas Pix sobre R$ 100,50', [vAsaas.gateway, vAsaas.liq.valorBruto, vAsaas.liq.presetTaxa], ['asaas', 100.5, 'pix']);
  const liqs = {}; liqs[B.idmensalidade + '-1'] = { sacado: true, dataSaque: '2026-11-20' };
  const vb2 = API.gateway.vendas(MENS, liqs, [])[0];
  eq('o saque guardado de uma parte vale só para aquela parte (chave idmensalidade-k)', [vb2.estado, API.gateway.vendas(MENS, liqs, [])[1].estado === 'sacado'], ['sacado', false]);

  console.log('\n══ Vendido no período (competência) ══');
  const per = API.gateway.vendidoDoPeriodo(MENS, vs, '2026-11-01', '2026-11-30');
  const bruto = Math.round(per.reduce((s, v) => s + v.bruto * 100, 0)) / 100;
  eq('o bruto do período é o dinheiro que entrou de verdade: 6 x 1000 + 1030 da multa, exato', bruto, 7030);
  eq('a parte de gateway não conta duas vezes (parte direta + venda de gateway = total da baixa)', per.filter((v) => /Bia/.test(v.quem)).map((v) => [v.estado, v.bruto]), [['conta', 700], ['retido', 300]]);

  console.log('\n══ Caixa, Planos e Recebimentos (financeiro.html, Chromium) ══');
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/financeiro.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise((r) => srv.listen(8111, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 1500, height: 1100 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', (e) => erros.push(e.message));
  await page.route((u) => u.href.includes('.php'), (r) => {
    const u = r.request().url();
    if (u.includes('mensalidades.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(MENS) });
    if (u.includes('atletas.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(MENS.map((m) => ({ idatleta: m.idatleta, nome: m.nmathleta, origem: 'professor', professores_responsaveis: [1], stbloqueio: 'N' }))) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.addInitScript(({ MENS, HOJE }) => { const _R = Date; window.Date = function (...a) { return a.length ? new _R(...a) : new _R(HOJE + 'T12:00:00'); }; window.Date.prototype = _R.prototype; window.Date.now = () => new _R(HOJE + 'T12:00:00').getTime(); window.Date.UTC = _R.UTC; window.Date.parse = _R.parse;
    localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); localStorage.setItem('intus-mensalidades', JSON.stringify(MENS)); }, { MENS, HOJE });
  await page.goto('http://127.0.0.1:8111/financeiro.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(2000);

  await page.evaluate(() => { caixaDe = '2026-11-01'; caixaAte = '2026-11-30'; setAba('caixa'); });
  await page.waitForTimeout(500);
  const caixa = await page.evaluate(() => document.getElementById('fin-tab-content').innerText.replace(/\s+/g, ' '));
  eq('Caixa: a Ana entra em duas linhas, uma por forma, com R$ 300,00 e R$ 700,00', [/Ana Casa[^R]*\(Dinheiro\)[^R]*R\$ 300,00/.test(caixa), /Ana Casa[^R]*\(Pix\)[^R]*R\$ 700,00/.test(caixa)], [true, true]);
  eq('Caixa: da Bia só entra o Pix (R$ 700,00); os R$ 300,00 da Eduzz esperam o saque', [/Bia Eduzz[^R]*\(Pix\)[^R]*R\$ 700,00/.test(caixa), /Bia Eduzz[^|]*\(Eduzz\)/.test(caixa)], [true, false]);
  eq('Caixa: Eva (tudo Eduzz) não entra; Fabio (tudo Pix) entra inteiro', [/Eva Eduzz/.test(caixa), /Fabio Pix Inteiro[^R]*R\$ 1000,00/.test(caixa)], [false, true]);
  const entradas = await page.evaluate(() => { const t = document.getElementById('fin-tab-content').innerText.replace(/\s+/g, ' '); const m = /ENTRADAS\s*R\$\s*([\d.]+,\d\d)/i.exec(t); return m ? m[1] : t.slice(0, 400); });
  // Ana 1000 + Bia 700 + Caio 699,50 + Duda 1000 + Fabio 1000 + Gil 1030 = 5429,50 (Eva e as partes de gateway ficam para o saque)
  eq('Caixa: total de entradas exato = R$ 5429,50 (sem as partes de gateway)', entradas, '5429,50');

  await page.evaluate(() => { planosF.agrupar = 'forma'; setAba('planos'); });
  await page.waitForTimeout(500);
  const porForma = await page.evaluate(() => [...document.querySelectorAll('#fin-tab-content table.data-table tbody tr')].map((tr) => [...tr.querySelectorAll('td')].map((td) => td.innerText.replace(/\s+/g, ' ').trim()).filter(Boolean)).map((c) => c[0] + '|' + c.find((x) => /^R\$/.test(x))));
  // pix: Ana 700 + Bia 700 + Duda 333,33 + Fabio 1000 + Gil 1030 = 3763,33 ; dinheiro: Ana 300 + Caio 699,50 + Duda 333,34 = 1332,84 ; cartao: 333,33 ; eduzz: Bia 300 + Caio 200 + Eva 1000 = 1500 ; asaas pix: 100,50
  const mapa = {}; porForma.forEach((x) => { const [n, v] = x.split('|'); mapa[n] = v; });
  eq('Planos por forma: Pix R$ 3763,33 e Dinheiro R$ 1332,84 (cada parte no seu lugar)', [mapa['Pix'], mapa['Dinheiro']], ['R$ 3763,33', 'R$ 1332,84']);
  eq('Planos por forma: Cartão R$ 333,33, Eduzz R$ 1500,00 e Asaas Pix R$ 100,50', [mapa['Cartão'], mapa['Eduzz'], mapa['Asaas Pix']], ['R$ 333,33', 'R$ 1500,00', 'R$ 100,50']);
  const totalFormas = Math.round(['Pix', 'Dinheiro', 'Cartão', 'Eduzz', 'Asaas Pix'].reduce((s, k) => s + parseFloat(String(mapa[k]).replace(/[R$\s.]/g, '').replace(',', '.')) * 100, 0)) / 100;
  eq('a soma das formas fecha com o total recebido (R$ 7.030,00) sem diferença nenhuma', totalFormas, 7030);

  await page.evaluate(() => { setAba('vendas'); });
  await page.waitForTimeout(500);
  const rec = await page.evaluate(() => document.getElementById('fin-tab-content').innerText.replace(/\s+/g, ' '));
  eq('Recebimentos: Bia (R$ 300,00), Caio (R$ 200,00 Eduzz e R$ 100,50 Asaas) e Eva (R$ 1.000,00) aparecem como vendas de gateway; a Ana não',
    [/Bia Eduzz/.test(rec), /Caio Dois Gateways/.test(rec), /Eva Eduzz/.test(rec), /Ana Casa/.test(rec)], [true, true, true, false]);
  eq('sem erro de JavaScript', erros, []);
  await b.close(); srv.close();
  console.log('\n' + ok + ' ok' + (ruim ? ', ' + ruim + ' FALHA(S)' : ''));
  process.exit(ruim ? 1 : 0);
})();
