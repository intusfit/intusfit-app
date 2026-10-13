// Programas de cardio (MVP) e treino fora do protocolo: regras do api.js que valem no ranking e nos distintivos.
const { carregar } = require('./_api.js');
const A = carregar();
let ok = 0, ruim = 0;
function eq(rot, cond, detalhe) { if (cond) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + (detalhe ? '\n          ' + detalhe : '')); } }
const car = (d, id, com, dur, extra) => Object.assign({ idsessao: id, idatleta: 1, tipo: 'cardio', dtsessao: d, duracao_seg: dur, pontos: 4, comentario: com }, extra || {});
const mus = (d, id, extra) => Object.assign({ idsessao: id, idatleta: 1, tipo: 'musculacao', dtsessao: d, duracao_seg: 3600 }, extra || {});
const P = A.PROGRAMAS_CARDIO;

console.log('\n── Catálogo ──');
eq('são 6 programas fixos', P.length === 6, P.map(p => p.id).join(','));
eq('ids e títulos únicos', new Set(P.map(p => p.id)).size === 6 && new Set(P.map(p => p.titulo)).size === 6);
eq('o 12-5-30 usa 12% e 5 km/h (km/h, não mph)', /12%/.test(JSON.stringify(P[0].etapas)) && /5 km\/h/.test(JSON.stringify(P[0].etapas)) && P[0].etapas.find(e => e.f === 'cont').s === 1800);
eq('toda etapa tem nome, segundos positivos e fase válida', P.every(p => p.etapas.every(e => e.n && e.s > 0 && ['aq', 'forte', 'leve', 'cont', 'calma'].includes(e.f))));
eq('todo programa começa em aquecimento e termina em volta à calma (menos o Zona 2, que é contínuo)', P.every(p => p.etapas[0].f === 'aq') && P.filter(p => p.id !== 'zona-2').every(p => p.etapas[p.etapas.length - 1].f === 'calma'));
eq('durações: 12-5-30 35 min, HIIT 20, Tabata 18, 4x4 40, Zona 2 45, Pirâmide 28', [35, 20, 18, 40, 45, 28].every((m, i) => A.programaDuracao(P[i]) === m * 60), P.map(p => A.programaDuracao(p) / 60).join(','));
eq('HIIT 1:1 tem 10 tiros fortes de 30 s', P[1].etapas.filter(e => e.f === 'forte').length === 10 && P[1].etapas.filter(e => e.f === 'forte').every(e => e.s === 30));
eq('Tabata tem 16 tiros de 20 s', P[2].etapas.filter(e => e.f === 'forte').length === 16 && P[2].etapas.filter(e => e.f === 'forte').every(e => e.s === 20));
eq('o esforço padrão de todos está entre 1 e 10', P.every(p => p.rpe >= 1 && p.rpe <= 10));

console.log('\n── Programa concluído ──');
const hiit = P[1], dur = A.programaDuracao(hiit);
eq('sessão com o comentário e a duração inteira conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: HIIT 1:1', dur)) === hiit);
eq('90% da duração ainda conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: HIIT 1:1', dur * 0.9)) === hiit);
eq('menos de 90% não conta (programa não concluído)', A.programaDaSessao(car('2026-10-13', 1, 'Programa: HIIT 1:1', dur * 0.8)) === null);
eq('cardio comum não conta', A.programaDaSessao(car('2026-10-13', 1, 'Corrida · esforço 5/10 · 30min', 1800)) === null);
eq('programa que não existe não conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: Inventado', 3600)) === null);
eq('musculação nunca conta', A.programaDaSessao(mus('2026-10-13', 1, { comentario: 'Programa: HIIT 1:1' })) === null);

console.log('\n── +1 ponto extra por dia com programa (fora do teto) ──');
{
  const a = car('2026-10-13', 1, 'Programa: HIIT 1:1', dur), b = car('2026-10-13', 2, 'Programa: Tabata', A.programaDuracao(P[2]));
  const sem = A.agregarPontosRank([car('2026-10-13', 1, 'Corrida · 20min', dur)])[1], com1 = A.agregarPontosRank([a])[1], com2 = A.agregarPontosRank([a, b])[1];
  eq('um programa concluído soma +1', Math.abs((com1.total - com1.base) - 1) < 1e-9 && com1.bonusProgramas === 1, JSON.stringify([com1.base, com1.bonus]));
  eq('cardio sem programa não soma o extra', sem.bonusProgramas === 0 && sem.bonus === 0);
  eq('dois programas no MESMO dia somam +1 só', com2.bonusProgramas === 1);
  const dias = A.agregarPontosRank([a, car('2026-10-14', 3, 'Programa: HIIT 1:1', dur)])[1];
  eq('programas em dias diferentes somam +1 cada', dias.bonusProgramas === 2);
  eq('programa que o servidor marcou "não contar" (limite do dia) não soma o extra', A.agregarPontosRank([car('2026-10-13', 1, 'Programa: HIIT 1:1', dur, { nao_contar: 1 })])[1].bonusProgramas === 0);
  eq('programa não concluído não soma o extra', A.agregarPontosRank([car('2026-10-13', 1, 'Programa: HIIT 1:1', dur * 0.5)])[1].bonusProgramas === 0);
}

console.log('\n── Treino fora do protocolo (divisão F): 5 pontos fixos ──');
{
  const f = mus('2026-10-12', 5, { divisao: 'F' }), ficha = mus('2026-10-12', 6, { divisao: 'A', idficha: 3 });
  const l = [f, ficha];
  eq('vale 5 pontos', A.pontosSessaoRank(f, l) === 5);
  eq('o treino da ficha continua valendo como o 1º do dia (10)', A.pontosSessaoRank(ficha, l) === 10);
  eq('F feito DEPOIS da ficha também vale 5 (e a ficha segue 10)', A.pontosSessaoRank(mus('2026-10-12', 7, { divisao: 'F' }), [ficha, mus('2026-10-12', 7, { divisao: 'F' })]) === 5 && A.pontosSessaoRank(ficha, [ficha, mus('2026-10-12', 7, { divisao: 'F' })]) === 10);
  eq('F sozinho não ocupa a posição de "1º treino"', A.ordemMusculacaoNoDia(f, l) === 0 && A.ordemMusculacaoNoDia(ficha, l) === 1);
  eq('F conta como dia com musculação', A.diasComMusculacaoRank([f]).length === 1);
  eq('F marcado "não contar" vale 0', A.pontosSessaoRank(mus('2026-10-12', 8, { divisao: 'F', nao_contar: 1 }), []) === 0);
  eq('divisão "f" minúscula também é fora do protocolo', A.foraProtocolo(mus('2026-10-12', 9, { divisao: 'f' })));
  eq('divisão normal não é', !A.foraProtocolo(mus('2026-10-12', 9, { divisao: 'A' })));
  eq('cardio com divisão F não é treino fora do protocolo', !A.foraProtocolo(car('2026-10-12', 9, 'x', 1800, { divisao: 'F' })));
}
console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
process.exit(ruim ? 1 : 0);
