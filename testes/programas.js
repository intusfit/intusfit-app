// Programas de cardio (7, incluindo o curso Do sofá aos 5 km) e treino fora do protocolo: regras do api.js que valem no ranking e nos distintivos.
const { carregar } = require('./_api.js');
const A = carregar();
let ok = 0, ruim = 0;
function eq(rot, cond, detalhe) { if (cond) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + (detalhe ? '\n          ' + detalhe : '')); } }
const car = (d, id, com, dur, extra) => Object.assign({ idsessao: id, idatleta: 1, tipo: 'cardio', dtsessao: d, duracao_seg: dur, pontos: 4, comentario: com }, extra || {});
const mus = (d, id, extra) => Object.assign({ idsessao: id, idatleta: 1, tipo: 'musculacao', dtsessao: d, duracao_seg: 3600 }, extra || {});
const P = A.PROGRAMAS_CARDIO, ID = (x) => A.programaPorId(x);

console.log('\n── Catálogo ──');
eq('são 7 programas (6 de uma sessão e o curso Do sofá aos 5 km)', P.length === 7 && P.filter(p => p.sessoes).length === 1, P.map(p => p.id).join(','));
eq('ids e títulos únicos', new Set(P.map(p => p.id)).size === 7 && new Set(P.map(p => p.titulo)).size === 7);
eq('o 12-5-30 usa 12% e 5 km/h (km/h, não mph)', /12%/.test(JSON.stringify(ID('12-5-30').etapas)) && /5 km\/h/.test(JSON.stringify(ID('12-5-30').etapas)) && ID('12-5-30').etapas.find(e => e.f === 'cont').s === 1800);
eq('toda etapa tem nome, segundos positivos e fase válida', P.every(p => (p.sessoes ? p.sessoes.map(x => x.etapas) : [p.etapas]).every(et => et.every(e => e.n && e.s > 0 && ['aq', 'forte', 'leve', 'cont', 'calma'].includes(e.f)))));
eq('todo programa começa em aquecimento e termina em volta à calma (menos o Zona 2, que é contínuo)', P.every(p => A.programaEtapas(p, 0)[0].f === 'aq') && P.filter(p => p.id !== 'zona-2').every(p => { const e = A.programaEtapas(p, 0); return e[e.length - 1].f === 'calma'; }));
eq('durações: 12-5-30 35 min, HIIT 20, Tabata 18, 4x4 40, Zona 2 45, Pirâmide 28', [['12-5-30', 35], ['hiit-1-1', 20], ['tabata', 18], ['4x4', 40], ['zona-2', 45], ['piramide', 28]].every(([id, m]) => A.programaDuracao(ID(id)) === m * 60));
eq('HIIT 1:1 tem 10 tiros fortes de 30 s', ID('hiit-1-1').etapas.filter(e => e.f === 'forte').length === 10 && ID('hiit-1-1').etapas.filter(e => e.f === 'forte').every(e => e.s === 30));
eq('Tabata tem 16 tiros de 20 s', ID('tabata').etapas.filter(e => e.f === 'forte').length === 16 && ID('tabata').etapas.filter(e => e.f === 'forte').every(e => e.s === 20));
eq('o esforço padrão de todos está entre 1 e 10', P.every(p => p.rpe >= 1 && p.rpe <= 10));

console.log('\n── Programa concluído ──');
const hiit = ID('hiit-1-1'), dur = A.programaDuracao(hiit);
eq('sessão com o comentário e a duração inteira conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: HIIT 1:1', dur)) === hiit);
eq('90% da duração ainda conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: HIIT 1:1', dur * 0.9)) === hiit);
eq('menos de 90% não conta (programa não concluído)', A.programaDaSessao(car('2026-10-13', 1, 'Programa: HIIT 1:1', dur * 0.8)) === null);
eq('cardio comum não conta', A.programaDaSessao(car('2026-10-13', 1, 'Corrida · esforço 5/10 · 30min', 1800)) === null);
eq('programa que não existe não conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: Inventado', 3600)) === null);
eq('musculação nunca conta', A.programaDaSessao(mus('2026-10-13', 1, { comentario: 'Programa: HIIT 1:1' })) === null);

console.log('\n── +1 ponto extra por dia com programa (fora do teto) ──');
{
  const a = car('2026-10-13', 1, 'Programa: HIIT 1:1', dur), b = car('2026-10-13', 2, 'Programa: Tabata', A.programaDuracao(ID('tabata')));
  const sem = A.agregarPontosRank([car('2026-10-13', 1, 'Corrida · 20min', dur)])[1], com1 = A.agregarPontosRank([a])[1], com2 = A.agregarPontosRank([a, b])[1];
  eq('um programa concluído soma +1', Math.abs((com1.total - com1.base) - 1) < 1e-9 && com1.bonusProgramas === 1, JSON.stringify([com1.base, com1.bonus]));
  eq('cardio sem programa não soma o extra', sem.bonusProgramas === 0 && sem.bonus === 0);
  eq('dois programas no MESMO dia somam +1 só', com2.bonusProgramas === 1);
  const dias = A.agregarPontosRank([a, car('2026-10-14', 3, 'Programa: HIIT 1:1', dur)])[1];
  eq('programas em dias diferentes somam +1 cada', dias.bonusProgramas === 2);
  eq('programa que o servidor marcou "não contar" (limite do dia) não soma o extra', A.agregarPontosRank([car('2026-10-13', 1, 'Programa: HIIT 1:1', dur, { nao_contar: 1 })])[1].bonusProgramas === 0);
  eq('programa não concluído não soma o extra', A.agregarPontosRank([car('2026-10-13', 1, 'Programa: HIIT 1:1', dur * 0.5)])[1].bonusProgramas === 0);
}

console.log('\n── Do sofá aos 5 km (curso de 8 semanas, 24 treinos) ──');
{
  const c = ID('sofa-5km');
  eq('são 24 treinos, ids S1T1 a S8T3', c.sessoes.length === 24 && c.sessoes[0].id === 'S1T1' && c.sessoes[23].id === 'S8T3');
  eq('todo treino tem aquecimento e volta à calma de 5 min', c.sessoes.every(x => x.etapas[0].f === 'aq' && x.etapas[0].s === 300 && x.etapas[x.etapas.length - 1].f === 'calma' && x.etapas[x.etapas.length - 1].s === 300));
  eq('semana 1: 8 trotes de 1 min e caminhadas de 1 min 30', c.sessoes[0].etapas.filter(e => e.f === 'forte').length === 8 && c.sessoes[0].etapas.filter(e => e.f === 'forte').every(e => e.s === 60) && c.sessoes[0].etapas.filter(e => e.f === 'leve').every(e => e.s === 90));
  eq('semana 8: 30 minutos correndo sem parar', c.sessoes[21].etapas.filter(e => e.f === 'forte').length === 1 && c.sessoes[21].etapas.find(e => e.f === 'forte').s === 1800);
  eq('o tempo correndo no 1º treino de cada semana nunca cai mais de 2 min', [0, 3, 6, 9, 12, 15, 18, 21].map(i => c.sessoes[i].etapas.filter(e => e.f === 'forte').reduce((a, e) => a + e.s, 0)).every((v, k, arr) => k === 0 || v >= arr[k - 1] - 120));
  const cm = (id, dur) => car('2026-10-13', 1, 'Programa: Do sofá aos 5 km · ' + id, dur);
  eq('treino do curso concluído conta (com a sessão no comentário)', !!A.programaDaSessao(cm('S3T2', A.programaDuracao(c, 7))));
  eq('sem a sessão no comentário não conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: Do sofá aos 5 km', 99999)) === null);
  eq('sessão que não existe não conta', A.programaDaSessao(cm('S9T1', 99999)) === null);
  eq('só vale com 90% da duração DAQUELE treino (S8T3 = 40 min)', !A.programaDaSessao(cm('S8T3', 30 * 60)) && !!A.programaDaSessao(cm('S8T3', 40 * 60)));
  eq('programa de uma sessão com sufixo de sessão não conta', A.programaDaSessao(car('2026-10-13', 1, 'Programa: HIIT 1:1 · S1T1', 99999)) === null);
  const todas = c.sessoes.map((x, i) => car('2026-10-13', 100 + i, 'Programa: Do sofá aos 5 km · ' + x.id, A.programaDuracao(c, i)));
  const m23 = A.metricasProgramas(todas.slice(0, 23)), m24 = A.metricasProgramas(todas);
  eq('23 treinos: 23 guiados, o curso ainda não conta como programa diferente', m23.programas === 23 && m23.programasTipos === 0 && m23.cursoCompleto === 0);
  eq('24 treinos: o curso conta como programa diferente e vira "5 Km Conquistados"', m24.programas === 24 && m24.programasTipos === 1 && m24.cursoCompleto === 1);
  eq('o mesmo treino do curso repetido não conta duas vezes para o curso', A.metricasProgramas(todas.slice(0, 23).concat([todas[0]])).cursoCompleto === 0);
  const todos6 = P.filter(p => !p.sessoes).map((p, i) => car('2026-10-14', 300 + i, 'Programa: ' + p.titulo, A.programaDuracao(p)));
  eq('os 6 programas de uma sessão + o curso completo = 7 diferentes', A.metricasProgramas(todos6.concat(todas)).programasTipos === 7);
  eq('+1 ponto por dia vale para o treino do curso', A.agregarPontosRank([cm('S1T1', A.programaDuracao(c, 0))])[1].bonusProgramas === 1);
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
  // o real (id menor) e o F no mesmo dia, na ordem em que o servidor devolve (mais novo primeiro): a ficha não pode cair para 5
  const doServidor = [mus('2026-10-12', 20, { divisao: 'F' }), mus('2026-10-12', 10, { divisao: 'C', idficha: 3 })];
  eq('na ordem que o servidor devolve (F primeiro), a ficha continua 10 e o F 5', A.pontosSessaoRank(doServidor[1], doServidor) === 10 && A.pontosSessaoRank(doServidor[0], doServidor) === 5);
  const semId = [mus('2026-10-12', null, { divisao: 'F', idsessao: undefined }), mus('2026-10-12', null, { divisao: 'C', idficha: 3, idsessao: undefined })];
  eq('mesmo sem idsessao a ficha continua 10', A.pontosSessaoRank(semId[1], semId) === 10);
}
console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
process.exit(ruim ? 1 : 0);
