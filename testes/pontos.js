const { carregar } = require('./_api.js');
const A = carregar();
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
let _id = 1000;
const mus = (data, min, extra) => Object.assign({ idsessao: _id++, idatleta: 1, tipo: 'musculacao', dtsessao: data, duracao_seg: (min == null ? 60 : min) * 60 }, extra || {});
const car = (data, pts) => ({ idsessao: _id++, idatleta: 1, tipo: 'cardio', dtsessao: data, pontos: pts, duracao_seg: 1800 });

console.log('\n── 1. O pedido do Luiz: 2º treino do dia vale 5 ──');
{
  const l = [mus('2026-09-01'), mus('2026-09-01')];
  eq('1º treino do dia', A.pontosSessaoRank(l[0], l), 10);
  eq('2º treino do dia', A.pontosSessaoRank(l[1], l), 5);
}
{
  const l = [mus('2026-09-01'), mus('2026-09-01'), mus('2026-09-01'), mus('2026-09-01')];
  eq('3º treino do dia = 0', A.pontosSessaoRank(l[2], l), 0);
  eq('4º treino do dia = 0', A.pontosSessaoRank(l[3], l), 0);
  eq('o dia inteiro soma 15, não 40', l.reduce((t, s) => t + A.pontosSessaoRank(s, l), 0), 15);
}

// Documenta o ponto frágil da função: sem a lista, ela não tem como saber se
// aquele treino foi o primeiro ou o segundo do dia, e assume o primeiro. Por
// isso TODA chamada nas telas precisa passar a lista — há um teste separado
// que varre os arquivos e cobra isso.
{
  const l = [mus('2026-09-01'), mus('2026-09-01')];
  eq('sem a lista, assume 1º (por isso as telas têm que passá-la)', A.pontosSessaoRank(l[1]), 10);
}
{
  const l = [mus('2026-09-01'), mus('2026-09-02')];
  eq('dias diferentes: os dois valem 10', [A.pontosSessaoRank(l[0], l), A.pontosSessaoRank(l[1], l)], [10, 10]);
}

console.log('\n── 2. Interação com a regra do treino curto ──');
{
  const l = [mus('2026-09-01', 15), mus('2026-09-01', 60)];
  eq('1º curto vale meia (5)', A.pontosSessaoRank(l[0], l), 5);
  eq('2º vale 5 mesmo sendo longo', A.pontosSessaoRank(l[1], l), 5);
}

console.log('\n── 3. Sessão de teste não pontua nem ocupa lugar na fila ──');
{
  const teste = mus('2026-09-01', 60, { nao_contar: 1 });
  const real1 = mus('2026-09-01');
  const real2 = mus('2026-09-01');
  const l = [teste, real1, real2];
  eq('sessão nao_contar = 0 pts', A.pontosSessaoRank(teste, l), 0);
  eq('1º treino REAL ainda vale 10', A.pontosSessaoRank(real1, l), 10);
  eq('2º treino REAL vale 5', A.pontosSessaoRank(real2, l), 5);
}

console.log('\n── 4. Cardio não interfere na ordem da musculação ──');
{
  const c = car('2026-09-01', 12);
  const m1 = mus('2026-09-01'), m2 = mus('2026-09-01');
  const l = [m1, c, m2];
  eq('musculação depois do cardio ainda é a 2ª', A.pontosSessaoRank(m2, l), 5);
  eq('cardio com fator e teto (12 → 6.6)', A.pontosSessaoRank(c, l), 6.6);
  eq('cardio estoura o teto (20 → 7)', A.pontosSessaoRank(car('2026-09-01', 20), l), 7);
}

console.log('\n── 5. A ordem é a ordem em que os treinos foram feitos ──');
{
  const a = mus('2026-09-01'); const b = mus('2026-09-01');   // b tem idsessao maior
  const listaInvertida = [b, a];                               // tela ordenou do mais novo pro mais velho
  eq('o de id menor é o 1º, mesmo listado depois', A.pontosSessaoRank(a, listaInvertida), 10);
  eq('o de id maior é o 2º, mesmo listado antes', A.pontosSessaoRank(b, listaInvertida), 5);
}
{
  const salvo = mus('2026-09-01');
  const local = { idatleta: 1, tipo: 'musculacao', dtsessao: '2026-09-01', duracao_seg: 3600 }; // ainda não sincronizou
  const l = [local, salvo];
  eq('sessão sem id (mais nova) fica em 2º', A.pontosSessaoRank(local, l), 5);
  eq('sessão já salva fica em 1º', A.pontosSessaoRank(salvo, l), 10);
}

console.log('\n── 6. Placar encerrado não muda: antes do corte, tudo como era ──');
{
  const l = [mus('2026-08-24'), mus('2026-08-24')];  // semana anterior ao corte 30/08
  eq('2º treino do dia ANTES do corte ainda vale 10', A.pontosSessaoRank(l[1], l), 10);
}
{
  const l = [mus('2026-07-20'), mus('2026-07-20')];  // antes do corte antigo (26/07)
  eq('fórmula legada: musculação vale 5', [A.pontosSessaoRank(l[0], l), A.pontosSessaoRank(l[1], l)], [5, 5]);
}

console.log('\n── 7. Bônus semanal agora conta DIAS, não sessões ──');
{
  // 2 dias com treino duplo = 4 sessões, mas só 2 dias: NÃO ganha bônus
  const l = [mus('2026-08-31'), mus('2026-08-31'), mus('2026-09-01'), mus('2026-09-01')];
  const r = A.agregarPontosRank(l)[1];
  eq('4 sessões em 2 dias: base 10+5+10+5', r.base, 30);
  eq('4 sessões em 2 dias: SEM bônus', r.bonus, 0);
}
{
  const l = ['2026-08-30','2026-08-31','2026-09-01','2026-09-02'].map(d => mus(d));
  const r = A.agregarPontosRank(l)[1];
  eq('4 dias diferentes: base 40', r.base, 40);
  eq('4 dias diferentes: bônus +5', r.bonus, 5);
  eq('4 dias diferentes: total 45', r.total, 45);
}
{
  // semana anterior ao corte continua contando SESSÕES (histórico intocado)
  const l = [mus('2026-08-24'), mus('2026-08-24'), mus('2026-08-25'), mus('2026-08-25')];
  const r = A.agregarPontosRank(l)[1];
  eq('semana antiga: 4 sessões em 2 dias ainda dão bônus', r.bonus, 5);
  eq('semana antiga: base 40 (regra antiga)', r.base, 40);
}

console.log('\n── 8. Empate e colocação densa ──');
{
  const arr = [{ id:1, pts:50.4 }, { id:2, pts:49.6 }, { id:3, pts:30 }];
  A.posicionarDenso(arr);
  eq('50,4 e 49,6 mostram 50: dividem o 1º', arr.map(r => r.pos), [1, 1, 2]);
}

console.log('\n── 9. Configuração do painel manda na fórmula ──');
{
  const B = carregar({ musSegundo: 3, musTerceiro: 1, bonusDias: 3, vigorSegundoDia: '2026-08-30' });
  const l = [mus('2026-09-01'), mus('2026-09-01'), mus('2026-09-01')];
  eq('2º passa a valer 3', B.pontosSessaoRank(l[1], l), 3);
  eq('3º passa a valer 1', B.pontosSessaoRank(l[2], l), 1);
  const l2 = ['2026-08-30','2026-08-31','2026-09-01'].map(d => mus(d));
  eq('bônus com meta de 3 dias', B.agregarPontosRank(l2)[1].bonus, 5);
}

console.log('\n' + (ruim === 0 ? 'TODOS OS ' + ok + ' TESTES PASSARAM' : ruim + ' FALHA(S) em ' + (ok + ruim) + ' testes'));
process.exit(ruim === 0 ? 0 : 1);
