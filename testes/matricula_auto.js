// Matrícula automática do checkout da Intus (app/api/_matricula_auto.php): paridade com o api.js e o que o Financeiro e a cobrança fazem com as linhas criadas.
// `node matricula_auto.js`           confere
// `node matricula_auto.js gravar`    refaz testes/matricula_auto_paridade.json (o PHP compara a série dele com a de API.gerarParcelas)
const fs = require('fs'), path = require('path');
const { carregar } = require('./_api.js');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const API = carregar({ hoje: '2026-11-13' });

// ── 1. Paridade da série de parcelas (o PHP gera a mesma) ──
const CASOS = [
  { inicio: '2026-11-13', meses: 12, parcelas: 12, valor: 4597 }, { inicio: '2026-11-13', meses: 12, parcelas: 7, valor: 2987 },
  { inicio: '2026-11-13', meses: 6, parcelas: 6, valor: 1678 }, { inicio: '2026-11-13', meses: 6, parcelas: 2, valor: 1689 },
  { inicio: '2026-11-13', meses: 3, parcelas: 3, valor: 897 }, { inicio: '2026-11-13', meses: 3, parcelas: 2, valor: 997 },
  { inicio: '2026-01-31', meses: 12, parcelas: 12, valor: 2997 }, { inicio: '2027-08-31', meses: 6, parcelas: 6, valor: 2597.01 },
];
const fixtures = CASOS.map(c => {
  const I = Math.max(1, Math.floor(c.meses / c.parcelas));
  const g = API.gerarParcelas(Object.assign({ intervalo: I }, c));
  if (!g.ok) throw new Error('caso inválido ' + JSON.stringify(c) + ': ' + g.erro);
  return Object.assign({}, c, { linhas: g.linhas.map(l => ({ parcela_num: l.parcela_num, parcela_total: l.parcela_total, dtinicio: l.dtinicio, dtvencimento: l.dtvencimento, vlpagar: l.vlpagar })) });
});
const arq = path.join(__dirname, 'matricula_auto_paridade.json');
if (process.argv[2] === 'gravar') { fs.writeFileSync(arq, JSON.stringify(fixtures, null, 1) + '\n'); console.log('gravado ' + arq); }
else eq('a série de parcelas do api.js não mudou desde o arquivo que o PHP compara', fixtures, JSON.parse(fs.readFileSync(arq, 'utf8')));
eq('12x de R$ 4.597: a soma fecha e o resto dos centavos vai para a última', Math.round(fixtures[0].linhas.reduce((s, l) => s + l.vlpagar, 0) * 100), 459700);

// ── 2. Pagamento à vista (Pix ou 1x): ciclo pago + ciclo seguinte em aberto ──
function linha(extra) {
  return Object.assign({ idmensalidade: 1, idatleta: 9, nmathleta: 'Carlos', dshistorico: 'Anual · Combo (Treino + Dieta)', tipo_plano: 'Combo (Treino + Dieta)', dtinicio: '2026-11-13', dtvencimento: '2027-11-13',
    vlpagar: 4597, vldesconto: 0, vljuro: 0, stpgto: 'S', dtpagamento: '2026-11-13', vlpagamento: 4597, stmatricula: 'ativa', recorrencia: 'anual', forma_pgto: 'asaas_pix', parcela_num: null, parcela_total: null, idmatricula_origem: null,
    dspagamento: 'Asaas checkout: Pix' }, extra || {});
}
const pago = linha(), proximo = linha({ idmensalidade: 2, dtinicio: '2027-11-13', dtvencimento: '2028-11-13', stpgto: 'N', dtpagamento: null, vlpagamento: null, forma_pgto: null, dspagamento: null, idmatricula_origem: 1 });
const lista = [pago, proximo];
eq('o plano anual é lido como 12 meses', API.mesesDoPlano(pago), 12);
eq('hoje: o ciclo pago está pago', API.situacaoCobranca(pago, lista).estado, 'paga');
eq('hoje: o ciclo seguinte ainda não é dívida', API.situacaoCobranca(proximo, lista).ehDivida, false);
{
  const A1 = carregar({ hoje: '2027-09-01' });   // meses antes do fim
  eq('meses antes do fim, o ciclo seguinte está só programado', [A1.situacaoCobranca(proximo, lista).estado, A1.situacaoCobranca(proximo, lista).ehDivida], ['programada', false]);
  const A2 = carregar({ hoje: '2027-10-20' });   // 24 dias antes do vencimento
  eq('perto do fim a cobrança do ciclo seguinte aparece (pendente), como nos outros planos recorrentes', A2.situacaoCobranca(proximo, lista).estado, 'pendente');
  const A3 = carregar({ hoje: '2027-12-30' });   // passou do vencimento sem pagar o ciclo seguinte
  eq('muito depois do vencimento sem pagamento vira inativo (cobrança de sempre)', [A3.situacaoCobranca(proximo, lista).estado, A3.situacaoCobranca(proximo, lista).ehDivida], ['inativa', true]);
}
const vPix = API.gateway.vendas([pago], {}, [])[0];
eq('Pix: aparece como venda do Asaas, libera na hora (taxa R$ 1,08)', [vPix.gateway, vPix.liq.prazoDias, vPix.liq.taxaFixo, vPix.liq.taxaPct], ['asaas', 0, 1.08, 0]);

// ── 3. Cartão em 12x: baixa total na data da compra, dinheiro caindo mês a mês ──
const serie = fixtures[0].linhas.map((l, i) => linha({ idmensalidade: 10 + i, dshistorico: 'Anual · Combo (Treino + Dieta) (' + l.parcela_num + '/12)', dtinicio: l.dtinicio, dtvencimento: l.dtvencimento, vlpagar: l.vlpagar, vlpagamento: l.vlpagar,
  recorrencia: null, forma_pgto: 'asaas_cartao', parcela_num: l.parcela_num, parcela_total: l.parcela_total, idmatricula_origem: i === 0 ? null : 10, dspagamento: 'Asaas checkout: cartão em 12x (liberação mensal)' }));
const vs = API.gateway.vendas(serie, {}, []);
eq('12 vendas do Asaas, todas com a data da compra (baixa do valor total no dia)', [vs.length, [...new Set(vs.map(v => v.data))]], [12, ['2026-11-13']]);
eq('o dinheiro libera 32 dias depois e depois de 30 em 30 (como o Asaas repassa)', vs.slice(0, 4).map(v => v.liq.prazoDias), [32, 62, 92, 122]);
eq('a taxa é a da faixa de 7 a 12x (2,99%) e a tarifa fixa é só uma', [vs[0].liq.taxaPct, vs[0].liq.taxaFixo, vs[1].liq.taxaFixo, vs[11].liq.taxaFixo], [2.99, 0.49, 0, 0]);
const totalBruto = Math.round(vs.reduce((s, v) => s + v.bruto, 0) * 100);
eq('a soma bruta é o valor total da compra', totalBruto, 459700);
const cedo = vs.filter(v => v.liberacao <= '2026-12-20').length;
eq('em 20/12 só 1 parcela já liberou (o caixa não mostra o valor inteiro)', cedo, 1);
// a série parcelada não gera ciclo novo sozinho e o plano continua coberto até o fim
const sp = API.situacaoCobranca(serie[11], serie);
eq('a última parcela, paga, aparece como paga', sp.estado, 'paga');
{
  const AF = carregar({ hoje: '2027-12-01' });   // passou o fim do plano parcelado
  const ef = AF.situacaoCobranca(serie[11], serie);
  eq('depois do fim do plano parcelado, a renovação é ação (estado renovar), não dívida', [ef.estado, ef.ehDivida], ['renovar', false]);
}
// cartão em 3x (faixa 2 a 6x) e à vista
const t3 = API.gateway.vendas([linha({ forma_pgto: 'asaas_cartao', parcela_num: 1, parcela_total: 3, dspagamento: 'Asaas checkout: cartão em 3x (liberação mensal)' })], {}, [])[0];
eq('3x usa a faixa de 2 a 6x (2,49%)', t3.liq.taxaPct, 2.49);
const t1 = API.gateway.vendas([linha({ forma_pgto: 'asaas_cartao', dspagamento: 'Asaas checkout: cartão à vista' })], {}, [])[0];
eq('cartão à vista: 1,99% + R$ 0,49 e libera em 32 dias', [t1.liq.taxaPct, t1.liq.taxaFixo, t1.liq.prazoDias], [1.99, 0.49, 32]);
// parcelado lançado à mão (sem a marca do checkout) NÃO ganha o espaçamento: cada parcela já é baixada quando chega
const manual = API.gateway.vendas([linha({ forma_pgto: 'asaas_cartao', parcela_num: 3, parcela_total: 6, dspagamento: 'Pagamento registrado' })], {}, [])[0];
eq('parcela lançada à mão não ganha prazo extra', manual.liq.prazoDias, 32);

console.log('\n' + ok + ' ok' + (ruim ? ', ' + ruim + ' FALHA(S)' : ''));
process.exit(ruim ? 1 : 0);
