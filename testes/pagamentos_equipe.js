// Pagamentos da equipe (API.pagamentosEquipe): lançamento mensal no caixa, id fixo, resumo e importação sem duplicar.
const { carregar } = require('./_api.js');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const API = carregar({ hoje: '2026-11-10' });
const P = API.pagamentosEquipe;

console.log('\n── Montar o lançamento ──');
{
  const m = P.montar({ pessoa: 'Ricardo', ref: '2026-05', mentorias: { qtd: 8, unit: 80 }, infoprodutos: 0, outros: 86, data: '2026-06-08' });
  eq('ok', m.ok, true);
  eq('id fixo por pessoa e mês', m.lancamento.id, 'pgto-ricardo-2026-05');
  eq('valor = atendimentos + infoprodutos + outros', m.lancamento.valor, 726);
  eq('é saída, com a data do pagamento', [m.lancamento.tipo, m.lancamento.data], ['saida', '2026-06-08']);
  eq('descrição leva o mês de referência', m.lancamento.descricao, 'Pagamento Ricardo, ref. mai/2026');
  eq('categoria e marca', [m.lancamento.categoria, m.lancamento.origemVenda], ['Pagamentos da equipe', 'pgto-equipe']);
  eq('observação detalha a conta', m.lancamento.obs, 'Atendimentos: 8 x R$ 80,00 = R$ 640,00. Outros: R$ 86,00.');
  const j = P.montar({ pessoa: 'Ricardo', ref: '2026-06', mentorias: { qtd: 7, unit: 80 }, infoprodutos: 600, outros: 86, data: '2026-07-07', dataEstimada: true });
  eq('soma com infoproduto', j.lancamento.valor, 1246);
  eq('data estimada fica registrada', /estimada/.test(j.lancamento.obs), true);
  const ro = P.montar({ pessoa: 'Rosângela', ref: '2026-10', mentorias: { qtd: 3, unit: 100 }, data: '2026-11-06' });
  eq('acento sai do id', ro.lancamento.id, 'pgto-rosangela-2026-10');
  eq('centavos não acumulam erro', P.montar({ pessoa: 'X', ref: '2026-01', mentorias: { qtd: 3, unit: 0.1 }, outros: 0.2, data: '2026-02-01' }).lancamento.valor, 0.5);
}

console.log('\n── Recusas ──');
{
  eq('sem pessoa', P.montar({ ref: '2026-05', mentorias: { qtd: 1, unit: 80 }, data: '2026-06-08' }).ok, false);
  eq('mês inválido', P.montar({ pessoa: 'R', ref: '2026-13', mentorias: { qtd: 1, unit: 80 }, data: '2026-06-08' }).ok, false);
  eq('data inválida', P.montar({ pessoa: 'R', ref: '2026-05', mentorias: { qtd: 1, unit: 80 }, data: '08/06/2026' }).ok, false);
  eq('total zerado', P.montar({ pessoa: 'R', ref: '2026-05', mentorias: { qtd: 0, unit: 80 }, data: '2026-06-08' }).ok, false);
  eq('valor negativo', P.montar({ pessoa: 'R', ref: '2026-05', mentorias: { qtd: 1, unit: 80 }, outros: -5, data: '2026-06-08' }).ok, false);
}

console.log('\n── Listar e resumir ──');
{
  const mk = (p, ref, q, u, inf, out, d) => P.montar({ pessoa: p, ref, mentorias: { qtd: q, unit: u }, infoprodutos: inf, outros: out, data: d }).lancamento;
  const caixa = [
    mk('Ricardo', '2026-05', 8, 80, 0, 86, '2026-06-08'), mk('Ricardo', '2026-06', 7, 80, 600, 86, '2026-07-07'),
    mk('Rosangela', '2026-05', 2, 100, 0, 0, '2026-06-08'),
    { id: 'cx-1', tipo: 'saida', data: '2026-06-01', descricao: 'Aluguel', categoria: 'Aluguel', valor: 1000 },
  ];
  const lista = P.listar(caixa);
  eq('só pega os pagamentos da equipe', lista.length, 3);
  eq('mais recente primeiro, depois por nome', lista.map(x => x.pessoa + ' ' + x.ref), ['Ricardo 2026-06', 'Ricardo 2026-05', 'Rosangela 2026-05']);
  const r = P.resumo(lista);
  eq('total geral', r.total, 726 + 1246 + 200);
  eq('por pessoa, maior primeiro', r.pessoas, [{ pessoa: 'Ricardo', total: 1972, meses: 2 }, { pessoa: 'Rosangela', total: 200, meses: 1 }]);
  eq('filtro por mês de referência', P.resumo(lista, '2026-06', '2026-06').total, 1246);
}

console.log('\n── Importar do arquivo sem duplicar ──');
{
  const it = (p, ref, q, u, inf, out, d) => ({ pessoa: p, ref, mentorias: { qtd: q, unit: u }, infoprodutos: inf, outros: out, data: d });
  const arq = { pagamentos: [it('Ricardo', '2026-05', 8, 80, 0, 86, '2026-06-08'), it('Ricardo', '2026-06', 7, 80, 600, 86, '2026-07-07'), it('Rosangela', '2026-05', 2, 100, 0, 0, '2026-06-08')] };
  const a = P.preparar(arq, []);
  eq('caixa vazio: tudo é novo', [a.novos.length, a.iguais.length, a.mudam.length, a.erros.length], [3, 0, 0, 0]);
  const caixa = a.novos.slice();
  const b = P.preparar(arq, caixa);
  eq('segunda importação não lança de novo', [b.novos.length, b.iguais.length], [0, 3]);
  const arq2 = JSON.parse(JSON.stringify(arq)); arq2.pagamentos[0].outros = 43;
  const c = P.preparar(arq2, caixa);
  eq('valor que mudou entra em "mudam", não em "novos"', [c.novos.length, c.mudam.length, c.iguais.length], [0, 1, 2]);
  const arq3 = { pagamentos: [it('Ricardo', '2026-05', 8, 80, 0, 86, '2026-06-08'), it('Ricardo', '2026-05', 8, 80, 0, 86, '2026-06-08'), it('', '2026-05', 1, 80, 0, 0, '2026-06-08')] };
  const d = P.preparar(arq3, []);
  eq('duplicado e item inválido viram erro', [d.novos.length, d.erros.length], [1, 2]);
  eq('arquivo errado é recusado', P.preparar({ x: 1 }, []).ok, false);
}

console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
process.exit(ruim ? 1 : 0);
