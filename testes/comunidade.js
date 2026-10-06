// Regras de turmas e desafios entre alunos (API.comunidade): progresso, classificação, estado e prazo.
const { carregar } = require('./_api.js');
const C = carregar({ hoje: '2026-10-05' }).comunidade;
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const s = (d, tipo, extra) => Object.assign({ dtsessao: d + ' 07:00:00', tipo: tipo }, extra || {});

console.log('\n── Progresso ──');
const sess = [s('2026-10-01', 'musculacao'), s('2026-10-01', 'musculacao'), s('2026-10-02', 'cardio'), s('2026-10-03', 'musculacao'), s('2026-09-30', 'musculacao'), s('2026-10-10', 'musculacao')];
eq('treinos: dois no mesmo dia contam um, e só musculação dentro do período', C.progresso(sess, 'treinos', '2026-10-01', '2026-10-05'), 2);
eq('cardios: só cardio', C.progresso(sess, 'cardios', '2026-10-01', '2026-10-05'), 1);
eq('atividades: treino e cardio por dia distinto', C.progresso(sess, 'atividades', '2026-10-01', '2026-10-05'), 3);
eq('fora do período não conta (início e fim valem)', C.progresso(sess, 'atividades', '2026-10-02', '2026-10-03'), 2);
eq('sessão marcada para não contar é ignorada', C.progresso([s('2026-10-01', 'musculacao', { nao_contar: 1 })], 'treinos', '2026-10-01', '2026-10-05'), 0);
eq('sem tipo vale musculação', C.progresso([{ dtsessao: '2026-10-01' }], 'treinos', '2026-10-01', '2026-10-05'), 1);
eq('lista vazia', C.progresso([], 'treinos', '2026-10-01', '2026-10-05'), 0);

console.log('\n── Classificação ──');
const r = C.ranquear([{ id: 1, valor: 5 }, { id: 2, valor: 9 }, { id: 3, valor: 5 }, { id: 4, valor: 1 }], 'valor');
eq('ordem do maior para o menor', r.map(x => x.id), [2, 1, 3, 4]);
eq('empate divide a colocação e a seguinte não é pulada', r.map(x => x.pos), [1, 2, 2, 3]);
eq('não altera a lista original', (function () { const o = [{ valor: 1 }, { valor: 2 }]; C.ranquear(o, 'valor'); return o[0].valor; })(), 1);
eq('lista vazia', C.ranquear([], 'valor'), []);

console.log('\n── Estado e prazo ──');
eq('antes do início', C.estado('2026-10-06', '2026-10-30', '2026-10-05'), 'agendado');
eq('no primeiro dia', C.estado('2026-10-05', '2026-10-30', '2026-10-05'), 'andamento');
eq('no último dia ainda está em andamento', C.estado('2026-10-01', '2026-10-05', '2026-10-05'), 'andamento');
eq('depois do fim', C.estado('2026-10-01', '2026-10-04', '2026-10-05'), 'encerrado');
eq('faltam 5 dias', C.diasRestantes('2026-10-10', '2026-10-05'), 5);
eq('último dia = 0', C.diasRestantes('2026-10-05', '2026-10-05'), 0);
eq('já encerrou = negativo', C.diasRestantes('2026-10-04', '2026-10-05'), -1);

console.log('\n' + ok + ' ok, ' + ruim + ' com falha');
process.exit(ruim ? 1 : 0);
