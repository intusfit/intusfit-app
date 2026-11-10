// Fluxo de caixa por mês (API.fluxoCaixa): entradas, saídas, resultado e o que NÃO pode ser contado duas vezes.
const { carregar } = require('./_api.js');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const API = carregar({ hoje: '2026-11-10' });
const F = API.fluxoCaixa;
const caixa = [
  { id: 'a', tipo: 'entrada', data: '2026-11-02', categoria: 'Outro', valor: 100 },
  { id: 'b', tipo: 'entrada', data: '2026-11-03', categoria: 'Vendas Eduzz', valor: 500, origemVenda: 'vd-1' },
  { id: 'c', tipo: 'saida', data: '2026-11-06', categoria: 'Pagamentos da equipe', valor: 300.10 },
  { id: 'd', tipo: 'saida', data: '2026-11-10', categoria: 'Aluguel', valor: 1000 },
  { id: 'e', tipo: 'saida', data: '2026-11-20', categoria: 'Imposto', valor: 400 },   // futuro: não aconteceu
  { id: 'f', tipo: 'saida', data: '2026-10-31', categoria: 'Aluguel', valor: 50 },
  { id: 'g', tipo: 'saida', data: '2026-10-05', categoria: 'Aluguel', valor: 0.2 },
  { id: 'h', tipo: 'saida', data: '2026-10-06', categoria: 'Aluguel', valor: 0.1 },
];

console.log('\n── Movimento entre duas datas ──');
{
  const m = F.movimento(caixa, '2026-11-01', '2026-11-30', { semSaqueGateway: true });
  eq('entrada de saque da Eduzz fica de fora no regime do vendido', m.entradas, 100);
  const c = F.movimento(caixa, '2026-11-01', '2026-11-30', {});
  eq('no regime de caixa o saque entra', c.entradas, 600);
  eq('saídas somam em centavos', c.saidas, 1700.1);
  eq('categorias, maior primeiro', c.categorias.map(x => x.categoria + ':' + x.valor), ['Aluguel:1000', 'Imposto:400', 'Pagamentos da equipe:300.1']);
  eq('limites inclusivos', F.movimento(caixa, '2026-10-31', '2026-10-31', {}).saidas, 50);
  eq('centavos não acumulam erro', F.movimento(caixa, '2026-10-05', '2026-10-06', {}).saidas, 0.3);
}

console.log('\n── Série mensal ──');
{
  const s = F.serie({ caixa, hoje: '2026-11-10', meses: 3, semSaqueGateway: true, entradasExtra: (de, ate) => de === '2026-11-01' ? 2000 : 0 });
  eq('três meses terminando no atual', s.map(x => x.ref), ['2026-09', '2026-10', '2026-11']);
  eq('rótulos', [s[2].rotulo, s[2].mesAno], ['nov', 'novembro de 2026']);
  eq('entradas = extra da tela + entradas do caixa (sem saque)', s[2].entradas, 2100);
  eq('lançamento de data futura não entra no mês atual', s[2].saidas, 1300.1);
  eq('resultado = entradas menos saídas', s[2].resultado, 799.9);
  eq('mês só com saída tem resultado negativo', [s[1].entradas, s[1].saidas, s[1].resultado], [0, 50.3, -50.3]);
  eq('mês vazio', [s[0].entradas, s[0].saidas, s[0].resultado], [0, 0, 0]);
  eq('a tela recebe o intervalo certo do mês corrente (só até hoje)', (() => { const r = []; F.serie({ caixa: [], hoje: '2026-11-10', meses: 2, entradasExtra: (de, ate) => { r.push(de + '>' + ate); return 0; } }); return r; })(), ['2026-10-01>2026-10-31', '2026-11-01>2026-11-10']);
  const s12 = F.serie({ caixa: [], hoje: '2026-01-15', meses: 12 });
  eq('vira o ano', [s12[0].ref, s12[11].ref], ['2025-02', '2026-01']);
}

console.log('\n── Totais ──');
{
  const s = F.serie({ caixa, hoje: '2026-11-10', meses: 3, semSaqueGateway: true, entradasExtra: (de) => de === '2026-11-01' ? 2000 : 0 });
  eq('totais em centavos', F.totais(s), { entradas: 2100, saidas: 1350.4, resultado: 749.6 });
}

console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
process.exit(ruim ? 1 : 0);
