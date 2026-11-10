// Gateways de pagamento (API.gateway): Eduzz e Asaas, taxa, liquido e liberacao real do dinheiro por forma de pagamento.
const { carregar } = require('./_api.js');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const API = carregar({ hoje: '2026-11-10' });
const G = API.gateway;
const mat = (o) => Object.assign({ idmensalidade: 7, nmathleta: 'Ana', dshistorico: 'Trimestral Treino', vlpagar: 897, vlpagamento: 897, dtpagamento: '2026-11-05', stpgto: 'S', forma_pgto: 'asaas_cartao' }, o || {});

console.log('\n── Qual gateway recebeu ──');
eq('eduzz', G.da('eduzz'), 'eduzz');
eq('asaas por forma', ['asaas_pix', 'asaas_boleto', 'asaas_cartao'].map(G.da), ['asaas', 'asaas', 'asaas']);
eq('pix, dinheiro e cartão diretos não são gateway (entram no Caixa na hora)', ['pix', 'dinheiro', 'cartao', 'boleto', null, ''].map(G.da), [null, null, null, null, null, null]);
eq('rótulos', ['asaas_pix', 'asaas_boleto', 'asaas_cartao', 'eduzz', 'pix'].map(G.rotuloForma), ['Asaas Pix', 'Asaas Boleto', 'Asaas Cartão', 'Eduzz', 'Pix']);

console.log('\n── Liberação do dinheiro no Asaas ──');
{
  const pix = G.itemDeMatricula(mat({ forma_pgto: 'asaas_pix', vlpagamento: 119, dtpagamento: '2026-11-05' }));
  eq('Pix cai na hora (prazo 0)', [pix.prazoDias, API.eduzzLiberacao(pix), API.eduzzStatus(pix)], [0, '2026-11-05', 'disponivel']);
  eq('Pix: taxa de R$ 1,08 por cobrança', [API.eduzzTaxaPlataforma(pix), API.eduzzLiquido(pix)], [1.08, 117.92]);
  const cart = G.itemDeMatricula(mat());
  eq('cartão à vista: 32 dias', [cart.prazoDias, API.eduzzLiberacao(cart)], [32, '2026-12-07']);
  eq('cartão à vista: ainda retido hoje (10/11)', API.eduzzStatus(cart), 'retido');
  eq('cartão à vista: 1,99% + R$ 0,49 sobre R$ 897', [Math.round(API.eduzzTaxaPlataforma(cart) * 100) / 100, Math.round(API.eduzzLiquido(cart) * 100) / 100], [18.34, 878.66]);
  eq('cartão pago há mais de 32 dias já está disponível', API.eduzzStatus(G.itemDeMatricula(mat({ dtpagamento: '2026-10-01' }))), 'disponivel');
  const bol = G.itemDeMatricula(mat({ forma_pgto: 'asaas_boleto', dtpagamento: '2026-11-06' }));   // sexta-feira
  eq('boleto pago na sexta cai na segunda (1 dia útil)', [bol.prazoDias, API.eduzzLiberacao(bol)], [3, '2026-11-09']);
  const bol2 = G.itemDeMatricula(mat({ forma_pgto: 'asaas_boleto', dtpagamento: '2026-11-04' }));  // quarta-feira
  eq('boleto pago na quarta cai na quinta', [bol2.prazoDias, API.eduzzLiberacao(bol2)], [1, '2026-11-05']);
}

console.log('\n── Eduzz continua igual ──');
{
  const e = G.itemDeMatricula(mat({ forma_pgto: 'eduzz', dtpagamento: '2026-10-20' }));
  eq('Eduzz: 30 dias, taxa direta 4,9% + R$ 2,49', [e.prazoDias, e.taxaPct, e.taxaFixo, e.gateway], [30, 4.9, 2.49, 'eduzz']);
  eq('Eduzz pago em 20/10 libera em 19/11', API.eduzzLiberacao(e), '2026-11-19');
  eq('forma que não é gateway não vira venda de gateway', G.itemDeMatricula(mat({ forma_pgto: 'pix' })), null);
}

console.log('\n── Ajuste manual guardado vale mais que o padrão ──');
{
  const it = G.itemDeMatricula(mat(), { taxaPct: 2.49, taxaFixo: 0.49, prazoDias: 30, sacado: true, dataSaque: '2026-11-08', caixaId: 'cx-1' });
  eq('taxa e prazo ajustados', [it.taxaPct, it.prazoDias, it.sacado, it.caixaId], [2.49, 30, true, 'cx-1']);
  eq('sacado manda no estado', API.eduzzStatus(it), 'sacado');
}

console.log('\n── Antecipar ──');
{
  const cart = G.itemDeMatricula(mat({ dtpagamento: '2026-11-10' }));   // libera em 12/12: 32 dias
  const a = G.antecipacao(cart, '2026-11-10');
  eq('Asaas: 1,25% ao mês, proporcional aos 32 dias', [a.diasRestantes, a.taxa], [32, Math.round(((897 - 18.3403) * 0.0125 * 32 / 30) * 100) / 100]);
  const e = G.itemDeMatricula(mat({ forma_pgto: 'eduzz', dtpagamento: '2026-11-10' }));
  const ae = G.antecipacao(e, '2026-11-10');
  eq('Eduzz mantém 2,99% + 0,10% ao dia', ae.taxa, Math.round((((897 - (897 * 0.049 + 2.49)) * 0.0299) + ((897 - (897 * 0.049 + 2.49)) * 0.001 * 30)) * 100) / 100);
}

console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
process.exit(ruim ? 1 : 0);
