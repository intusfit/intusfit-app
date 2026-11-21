// Dias úteis, feriados nacionais e "hoje" local (21/11/2026): atraso, carência e validade de ficha contam a partir do PRÓXIMO DIA ÚTIL, e o painel e o app
// do aluno usam as mesmas funções do api.js.
//   cd testes && INTUS_DIR=../app/painel node dias_uteis.js
const { carregar } = require('./_api.js');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const A = carregar({ hoje: '2026-11-24' });

console.log('\n══ Feriados nacionais ══');
const f26 = A.feriadosNacionais(2026);
eq('20/11 é feriado (Consciência Negra, nacional desde 2024)', [f26.has('2026-11-20'), A.feriadosNacionais(2023).has('2023-11-20')], [true, false]);
eq('fixos de 2026: 1/1, 21/4, 1/5, 7/9, 12/10, 2/11, 15/11, 25/12', ['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '12-25'].every(d => f26.has('2026-' + d)), true);
eq('Páscoa 2026 = 5/4: Sexta-feira Santa 3/4, Carnaval 16 e 17/2, Corpus Christi 4/6', [f26.has('2026-04-03'), f26.has('2026-02-16'), f26.has('2026-02-17'), f26.has('2026-06-04')], [true, true, true, true]);
eq('Páscoa 2027 = 28/3: Sexta-feira Santa 26/3', A.feriadosNacionais(2027).has('2027-03-26'), true);
A.DIAS_UTEIS_CFG.carnaval = false; A.DIAS_UTEIS_CFG.corpusChristi = false;
eq('Carnaval e Corpus Christi são configuráveis (ponto facultativo)', [A.feriadosNacionais(2026).has('2026-02-16'), A.feriadosNacionais(2026).has('2026-06-04'), A.feriadosNacionais(2026).has('2026-04-03')], [false, false, true]);
A.DIAS_UTEIS_CFG.carnaval = true; A.DIAS_UTEIS_CFG.corpusChristi = true;

console.log('\n══ Próximo dia útil ══');
eq('dia útil fica como está', A.proximoDiaUtil('2026-11-24'), '2026-11-24');
eq('sábado vai para a segunda', A.proximoDiaUtil('2026-11-21'), '2026-11-23');
eq('feriado de sexta (20/11) + fim de semana: segunda 23/11', A.proximoDiaUtil('2026-11-20'), '2026-11-23');
eq('Natal em sexta: segunda 28/12', A.proximoDiaUtil('2026-12-25'), '2026-12-28');
eq('virada de ano: 31/12 (quinta) é útil; 1/1 (sexta) pula para segunda 4/1', [A.proximoDiaUtil('2026-12-31'), A.proximoDiaUtil('2027-01-01')], ['2026-12-31', '2027-01-04']);

console.log('\n══ Cobrança: antes da data em dias corridos; atraso conta do dia útil ══');
eq('antes da data o número não muda (-1 na véspera)', A.diasCobranca('2026-11-20', '2026-11-19'), -1);
eq('no feriado, sábado e domingo o atraso ainda é 0', ['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23'].map(h => A.diasCobranca('2026-11-20', h)), [0, 0, 0, 0]);
eq('terça 24/11 já é 1 dia de atraso (antes seriam 4)', A.diasCobranca('2026-11-20', '2026-11-24'), 1);
eq('cobrança em dia útil conta direto (quarta 18/11 -> sexta 20/11 = 2)', A.diasCobranca('2026-11-18', '2026-11-20'), 2);

console.log('\n══ Plano que terminou numa data fora do dia útil (carência de 5 dias) ══');
const atleta = { idatleta: 1, origem: 'professor' };
const mens = [{ idmensalidade: 1, idatleta: 1, stpgto: 'S', stmatricula: 'ativa', dtinicio: '2026-10-20', dtvencimento: '2026-11-20', vlpagar: 100 }];
const sit = h => { const a = carregar({ hoje: h }); const r = a.situacaoPlano(atleta, mens); return [r.estado, r.diasVencido]; };
eq('sábado 21/11 (plano terminou na sexta feriado): ainda ativo, sem atraso', sit('2026-11-21'), ['ativo', 0]);
eq('segunda 23/11: ativo, sem atraso', sit('2026-11-23'), ['ativo', 0]);
eq('terça 24/11: 1 dia de carência', sit('2026-11-24'), ['ativo', 1]);
eq('sábado 28/11: 5 dias, ainda na carência (pela regra antiga já seriam 8 e inativo)', sit('2026-11-28'), ['ativo', 5]);
eq('domingo 29/11: 6 dias, inativo', sit('2026-11-29'), ['inativo', 6]);

console.log('\n══ Ficha: o fim vale no próximo dia útil + 2 dias de tolerância ══');
const fx = { idficha: 1, idatleta: 1, dtinicio: '2026-09-01', dtfim: '2026-11-20' };
const fv = h => { const a = carregar({ hoje: h }); const s = a.fichaSituacao(fx, h); return [s.efetivo, s.vencida, a.fichaVigente(fx, h)]; };
eq('no feriado do fim (20/11): vigente, não vencida', fv('2026-11-20'), ['2026-11-23', false, true]);
eq('segunda 23/11 (dia útil efetivo): ainda não vencida', fv('2026-11-23'), ['2026-11-23', false, true]);
eq('terça 24/11: vencida, mas dentro da tolerância (vigente)', fv('2026-11-24'), ['2026-11-23', true, true]);
eq('quarta 25/11: 2 dias de tolerância, ainda vigente', fv('2026-11-25'), ['2026-11-23', true, true]);
eq('quinta 26/11: passou da tolerância, perde o acesso', fv('2026-11-26'), ['2026-11-23', true, false]);
eq('ficha sem prazo nunca vence; ficha que ainda não começou não vale', [A.fichaVigente({ idficha: 2 }), A.fichaVigente({ idficha: 3, dtinicio: '2026-12-01' }, '2026-11-24')], [true, false]);

console.log('\n══ "Hoje" local (nada de UTC) ══');
if (new Date().getTimezoneOffset() === 180) {
  const tarde = carregar({ hoje: '2026-11-21T00:30:00Z' });   // 21h30 de 20/11 em Brasília, mas já é 21/11 em UTC
  eq('21h30 em Brasília ainda é 20/11 (toISOString diria 21/11)', tarde.hojeISO(), '2026-11-20');
  eq('a ficha de fim 18/11 ainda não passou da tolerância às 21h30 de 20/11', tarde.fichaVigente({ idficha: 1, dtfim: '2026-11-18' }), true);
} else console.log('  (fuso desta máquina não é o de Brasília: conferência de 21h30 pulada)');
console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
process.exit(ruim ? 1 : 0);
