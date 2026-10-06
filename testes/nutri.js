// Regras do dashboard de Nutrição (API.nutri): aderência, consumo, alertas, lista de compras.
// "Hoje" é congelado: segunda-feira 2026-10-05, 14:00 (meio-dia do sandbox, ver _api.js).
const { carregar } = require('./_api.js');
const A = carregar({ hoje: '2026-10-05' }).nutri;
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const it = (nome, q, kcal, prot, carb, gord, medida) => ({ nome, quantidade: q, medida_id: medida ? 'un' : 'g', medida_nome: medida || '', nutrientes: { energia_kcal: kcal, proteina_g: prot, carboidrato_g: carb, lipidio_g: gord } });
const plano = {
  idplano: 1, dtinicio: '2026-09-28', dtfim: '2026-10-12', calorias: 1900, proteina: 140, carboidrato: 210, gordura: 55,
  refeicoes: [
    { nome: 'Jantar', horario: '20:00', itens_estruturados: [it('Frango', 150, 250, 45, 0, 5)] },
    { nome: 'Café', horario: '07:00', itens_estruturados: [it('Ovo', 3, 210, 18, 1, 15, 'unidade'), it('Aveia', 40, 150, 5, 27, 3)] },
    { nome: 'Almoço', horario: '12:30', itens_estruturados: [it('Frango', 150, 250, 45, 0, 5), it('Arroz', 120, 156, 3, 34, 0)] },
  ],
};
const C = A.chave({ nome: 'Café', horario: '07:00' }), AL = A.chave({ nome: 'Almoço', horario: '12:30' }), J = A.chave({ nome: 'Jantar', horario: '20:00' });
const r = (data, refeicao, status) => ({ data, refeicao, status });

console.log('\n── 1. Aderência ──');
{
  eq('refeições ordenadas por horário', A.refeicoes(plano).map(x => x.nome), ['Café', 'Almoço', 'Jantar']);
  // dia 02/10 (sexta): tudo feito = 100%
  const reg = [r('2026-10-02', C, 'feito'), r('2026-10-02', AL, 'feito'), r('2026-10-02', J, 'feito')];
  const a = A.aderencia(plano, reg, '2026-10-02', '2026-10-02');
  eq('dia completo = 1', a.dias[0].score, 1);
}
{
  // feito + parcial + fora = (1 + .5 + 0) / 3
  const reg = [r('2026-10-02', C, 'feito'), r('2026-10-02', AL, 'parcial'), r('2026-10-02', J, 'fora')];
  eq('feito, parcial e fora', A.aderencia(plano, reg, '2026-10-02', '2026-10-02').dias[0].score, 0.5);
}
{
  // refeição sem registro em dia encerrado vale 0
  const reg = [r('2026-10-02', C, 'feito')];
  const d = A.aderencia(plano, reg, '2026-10-02', '2026-10-02').dias[0];
  eq('sem registro conta zero', [d.score, d.semRegistro], [1 / 3, 2]);
}
{
  // HOJE (segunda 05/10, 12:00 no sandbox): só o café (07:00) já passou; almoço 12:30 e jantar ainda não
  const a = A.aderencia(plano, [r('2026-10-05', C, 'feito')], '2026-10-05', '2026-10-05');
  eq('hoje só conta refeição que já passou', [a.dias[0].total, a.dias[0].score], [1, 1]);
}
{
  // plano que começa no meio do período: dias antes do início não contam
  const a = A.aderencia(plano, [], '2026-09-25', '2026-09-29');
  eq('dias antes do plano não entram', a.dias.map(x => x.data), ['2026-09-28', '2026-09-29']);
  eq('sem nenhum dia, média é nula', A.aderencia(plano, [], '2026-09-01', '2026-09-20').media, null);
}

console.log('\n── 2. Consumo estimado, próxima refeição, sequência ──');
{
  const c = A.consumo(plano, [r('2026-10-05', C, 'feito'), r('2026-10-05', AL, 'parcial'), r('2026-10-05', J, 'fora')]);
  eq('kcal: café 360 + metade do almoço 203', Math.round(c.kcal), 563);
  eq('proteína: 23 + metade de 48 (frango e arroz)', Math.round(c.prot), 47);
}
{
  eq('próxima refeição é a primeira sem registro de agora em diante', A.proximaRefeicao(plano, [r('2026-10-05', C, 'feito')]).nome, 'Almoço');
  eq('tudo registrado: não há próxima', A.proximaRefeicao(plano, [r('x', C, 'feito'), r('x', AL, 'feito'), r('x', J, 'feito')]), null);
}
{
  const reg = [r('2026-10-05', C, 'feito'), r('2026-10-04', AL, 'feito'), r('2026-10-03', J, 'feito'), r('2026-10-01', C, 'feito')];
  eq('sequência de 3 dias (o buraco em 02/10 corta)', A.sequencia(reg), 3);
  eq('hoje sem registro: conta a partir de ontem', A.sequencia([r('2026-10-04', C, 'feito')]), 1);
  eq('parcial e fora não mantêm a sequência', A.sequencia([r('2026-10-05', C, 'parcial')]), 0);
}

console.log('\n── 3. Alertas ──');
{
  const tipos = (p, reg, av) => A.alertas(p, reg, av).map(x => x.tipo);
  eq('sem plano', tipos(null, [], []), ['sem_plano']);
  eq('plano vence em 7 dias', tipos(plano, [r('2026-10-05', C, 'feito')], []).includes('vencendo'), true);
  eq('plano vencido', tipos(Object.assign({}, plano, { dtfim: '2026-10-01' }), [r('2026-10-05', C, 'feito')], []).includes('vencido'), true);
  eq('sem registro há 5 dias', tipos(plano, [r('2026-09-30', C, 'feito')], []).includes('sem_registro'), true);
  eq('nunca registrou', A.alertas(plano, [], []).find(x => x.tipo === 'sem_registro').texto.startsWith('Nunca registrou'), true);
  eq('registro de hoje: sem alerta de sumiço', tipos(plano, [r('2026-10-05', C, 'feito')], []).includes('sem_registro'), false);
}
{
  // 5 dias com tudo fora do plano -> aderência baixa
  const reg = []; ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05'].forEach(d => [C, AL, J].forEach(k => reg.push(r(d, k, 'fora'))));
  eq('aderência baixa', A.alertas(plano, reg, []).map(x => x.tipo).includes('aderencia_baixa'), true);
}
{
  // proteína: 3 dias só com o café (23 g) contra meta 140 -> alerta
  const reg = [r('2026-10-03', C, 'feito'), r('2026-10-04', C, 'feito'), r('2026-10-05', C, 'feito')];
  eq('proteína baixa', A.alertas(plano, reg, []).map(x => x.tipo).includes('proteina_baixa'), true);
}
{
  const av = [{ peso: 78.0, dtavaliacao: '2026-09-15' }, { peso: 78.1, dtavaliacao: '2026-10-02' }];
  eq('peso parado em 3 semanas', A.alertas(plano, [r('2026-10-05', C, 'feito')], av).map(x => x.tipo).includes('peso_parado'), true);
  const av2 = [{ peso: 78.0, dtavaliacao: '2026-09-15' }, { peso: 76.5, dtavaliacao: '2026-10-02' }];
  eq('peso caindo: sem alerta', A.alertas(plano, [r('2026-10-05', C, 'feito')], av2).map(x => x.tipo).includes('peso_parado'), false);
}

console.log('\n── 4. Água e lista de compras ──');
{
  eq('meta de água: 35 ml/kg arredondado a 50', A.metaAguaMl(74.9), 2600);
  eq('sem peso: 2,5 L', A.metaAguaMl(0), 2500);
  eq('teto de 5 L', A.metaAguaMl(200), 5000);
  const l = A.listaCompras(plano, 7);
  eq('frango aparece uma vez, somado (2 refeições × 150 g × 7)', l.find(x => x.nome === 'Frango').quantidade, 2100);
  eq('ovos em unidades (3 × 7)', l.find(x => x.nome === 'Ovo'), { nome: 'Ovo', unidade: 'unidade', quantidade: 21 });
  eq('ordem alfabética', l.map(x => x.nome), ['Aveia', 'Arroz', 'Frango', 'Ovo'].sort((a, b) => a.localeCompare(b, 'pt')));
}

console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
process.exit(ruim ? 1 : 0);
