// Previsao financeira (API.previsao): taxas medidas no passado, renovacoes, cobrancas em aberto, parcelas, novas vendas, cenarios e conversao de leads.
const { carregar } = require('./_api.js');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
function perto(rot, obtido, esperado, tol) { const bate = Math.abs(obtido - esperado) <= (tol == null ? 0.011 : tol); eq(rot, bate ? esperado : obtido, esperado); }
const API = carregar({ hoje: '2026-11-10' });
const P = API.previsao, HOJE = '2026-11-10';
let seq = 0;
const mk = (o) => Object.assign({ idmensalidade: ++seq, stmatricula: 'ativa', vldesconto: 0, tipo_plano: 'Treino', forma_pgto: 'pix', recorrencia: null }, o);
const ciclo = (atl, desc, ini, fim, valor, pago) => mk({ idatleta: atl, nmathleta: 'Aluno ' + atl, dshistorico: desc, dtinicio: ini, dtvencimento: fim, vlpagar: valor, vlpagamento: valor, stpgto: pago ? 'S' : 'N', dtpagamento: pago ? ini : null });

// 10 alunos de plano trimestral; 8 renovam duas vezes, 2 saem depois do primeiro ciclo.
const ms = [];
for (let i = 0; i < 10; i++) {
  const a = 101 + i;
  ms.push(ciclo(a, 'Trimestral Treino', '2026-01-05', '2026-04-05', 897, true));
  if (i < 8) { ms.push(ciclo(a, 'Trimestral Treino', '2026-04-05', '2026-07-05', 897, true)); ms.push(ciclo(a, 'Trimestral Treino', '2026-07-05', '2026-10-05', 897, true)); }
}
// uma cobranca mensal em aberto, que vence daqui a 2 dias, e um plano parcelado em 3x com a 1a parcela paga
ms.push(ciclo(201, 'Mensal Dieta', '2026-11-12', '2026-12-12', 119, false));
const parc = (n, ini, fim, pago) => Object.assign(ciclo(301, 'Semestral Treino (' + n + '/3)', ini, fim, 300, pago), { parcela_num: n, parcela_total: 3 });
ms.push(parc(1, '2026-11-01', '2026-12-01', true), parc(2, '2026-12-01', '2027-01-01', false), parc(3, '2027-01-01', '2027-07-01', false));

console.log('\n── Taxas medidas no passado ──');
const t = P.taxas(ms, HOJE);
eq('renovação: 18 ciclos terminados na janela, 16 renovaram', [t.renovacao.geral.n, t.renovacao.geral.sim], [18, 16]);
perto('taxa de renovação 16/18', t.renovacao.geral.taxa, 16 / 18, 0.0001);
eq('por duração: tudo é trimestral', Object.keys(t.renovacao.porMeses), ['3']);
eq('pagamento: tudo o que venceu há mais de 30 dias foi pago', [t.pagamento.n >= 26, t.pagamento.taxa], [true, 1]);
eq('plano de teste e valor zero não entram', P.taxas([ciclo(1, 'Teste grátis 7 dias', '2026-01-01', '2026-01-08', 0, true)], HOJE).renovacao.geral.n, 0);

console.log('\n── Projeção base ──');
const base = P.projetar(ms, { hoje: HOJE, meses: 3, taxas: t, novos: { media: 0, ticket: 0 }, cenario: 'base' });
const ren = base.itens.filter(i => i.tipo === 'renovacao');
eq('8 renovações atrasadas há menos de 45 dias, esperadas para hoje', [ren.length, ren[0].data], [8, HOJE]);
perto('renovação esperada = valor x taxa (897 x 16/18)', ren[0].esperado, 797.33);
eq('quem acabou há mais de 45 dias é dado como perdido (os 2 que saíram não entram)', ren.map(r => r.idatleta).sort().join(','), '101,102,103,104,105,106,107,108');
const ab = base.itens.filter(i => i.tipo === 'aberto');
eq('cobrança em aberto: vence 12/11, entra no mês atual', [ab.length, ab[0].data, ab[0].estado], [1, '2026-11-12', 'pendente']);
perto('cobrança em aberto esperada = 119 x 0,98 (teto da probabilidade)', ab[0].esperado, 116.62);
const pc = base.itens.filter(i => i.tipo === 'parcela');
eq('parcelas 2 e 3 do plano parcelado entram nos meses em que vencem', pc.map(i => i.data), ['2026-12-01', '2027-01-01']);
eq('o plano parcelado não gera renovação enquanto faltam parcelas', base.itens.some(i => i.tipo === 'renovacao' && i.idatleta === 301), false);
eq('meses da projeção', base.meses.map(m => m.ref), ['2026-11', '2026-12', '2027-01']);
perto('novembro: bruto = renovações + em aberto + parcelas + novas', base.meses[0].bruto, base.meses[0].renovacoes + base.meses[0].abertos + base.meses[0].parcelas + base.meses[0].novas);
eq('dezembro tem a parcela 2 (300 x 0,98)', base.meses[1].parcelas, 294);
eq('fator de líquido multiplica o bruto', P.projetar(ms, { hoje: HOJE, meses: 1, taxas: t, novos: { media: 0, ticket: 0 }, fatorLiquido: 0.9 }).meses[0].liquido, Math.round(base.meses[0].bruto * 0.9 * 100) / 100);

console.log('\n── Renovações em sequência ──');
{
  const mensal = [ciclo(7, 'Mensal Treino', '2026-10-20', '2026-11-20', 100, true)];
  const x = P.projetar(mensal, { hoje: HOJE, meses: 4, taxas: P.taxas([], HOJE), novos: null });
  const r = x.itens.filter(i => i.tipo === 'renovacao');
  eq('plano mensal renova todo mês até o fim do horizonte', r.map(i => i.data), ['2026-11-20', '2026-12-20', '2027-01-20', '2027-02-20']);
  eq('a chance se multiplica a cada ciclo (65% x 65% ...)', r.map(i => Math.round(i.prob * 10000) / 10000), [0.65, 0.4225, 0.2746, 0.1785]);
  eq('valor esperado de cada mês = valor x chance acumulada', r.map(i => i.esperado), [65, 42.25, 27.46, 17.85]);
  const parcelado = P.projetar([Object.assign(ciclo(8, 'Trimestral Treino (3/3)', '2026-10-20', '2026-11-20', 100, true), { parcela_num: 3, parcela_total: 3 })], { hoje: HOJE, meses: 6, taxas: P.taxas([], HOJE), novos: null });
  eq('plano parcelado conta só a primeira renovação (a duração do plano novo não é conhecida)', parcelado.itens.filter(i => i.tipo === 'renovacao').length, 1);
}

console.log('\n── Cenários ──');
const cons = P.projetar(ms, { hoje: HOJE, meses: 1, taxas: t, novos: { media: 2, ticket: 500 }, cenario: 'conservador' });
const bas = P.projetar(ms, { hoje: HOJE, meses: 1, taxas: t, novos: { media: 2, ticket: 500 }, cenario: 'base' });
const oti = P.projetar(ms, { hoje: HOJE, meses: 1, taxas: t, novos: { media: 2, ticket: 500 }, cenario: 'otimista' });
eq('conservador < base < otimista no mês atual', cons.meses[0].bruto < bas.meses[0].bruto && bas.meses[0].bruto < oti.meses[0].bruto, true);
perto('novas vendas do mês atual contam só o que resta do mês (21 de 30 dias)', bas.meses[0].novas, 2 * 500 * (21 / 30), 0.011);
eq('sem histórico usa o padrão conservador (65% de renovação)', (() => { const x = P.projetar([ciclo(5, 'Mensal Treino', '2026-10-20', '2026-11-20', 100, true)], { hoje: HOJE, meses: 1, taxas: P.taxas([], HOJE), novos: null }); return x.itens[0].prob; })(), 0.65);

console.log('\n── Novas vendas e conversão ──');
const nv = [];
['2026-05', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-10'].forEach((r, i) => nv.push(ciclo(500 + i, 'Mensal Treino', r + '-10', r + '-28', 400 + i * 10, true)));
nv.push(ciclo(500, 'Mensal Treino', '2026-06-10', '2026-06-28', 400, true));   // renovação do 500: não é aluno novo
const n6 = P.novos(nv, HOJE, 6);
eq('alunos novos por mês nos 6 meses fechados', n6.meses.map(m => m.n), [2, 1, 1, 1, 1, 2]);
perto('média de 8 alunos em 6 meses', n6.media, 8 / 6, 0.0001);
perto('ticket da primeira cobrança', n6.ticket, (400 + 410 + 420 + 430 + 440 + 450 + 460 + 470) / 8, 0.0001);
const cl = P.conversaoLeads([
  { criado_em: '2026-09-10 10:00', etapa: 'cliente' }, { criado_em: '2026-09-12', etapa: 'treinando' }, { criado_em: '2026-10-01', etapa: 'cliente' }, { criado_em: '2026-10-03', etapa: 'so_conta' },
  { criado_em: '2026-11-08', etapa: 'cliente' }, { criado_em: '2026-11-02', etapa: 'contato' }], HOJE);
eq('leads dos últimos 14 dias ficam fora da taxa (ainda não deu tempo de comprar)', [cl.leads, cl.clientes], [4, 2]);
eq('conversão por mês', cl.porMes.map(m => m.ref + ':' + m.clientes + '/' + m.leads), ['2026-09:1/2', '2026-10:1/2']);
eq('quanto falta para a meta, em renovações ou alunos novos', P.faltaPara(1000, 400, 300), { valor: 1000, renovacoes: 3, novos: 4 });
eq('fator de líquido sem taxa de gateway é 1', P.fatorLiquido(nv, [], HOJE), 1);

console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
process.exit(ruim ? 1 : 0);
