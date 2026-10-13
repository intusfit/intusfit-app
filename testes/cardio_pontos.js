// Pontos-base do cardio (API.cardioPontosBase, api.js): o tempo manda, o esforço pesa pouco e as atividades são coerentes entre si.
// A tabela cardio_pontos.json é a referência que o servidor (treinos.php, _cardioPontosBase) também precisa reproduzir (cardio_pontos.php).
//   node cardio_pontos.js            confere   |   node cardio_pontos.js gravar   regrava a tabela a partir do api.js
const fs = require('fs'), path = require('path');
const { carregar } = require('./_api.js');
const A = carregar();
let ok = 0, ruim = 0;
function eq(rot, cond, detalhe) { if (cond) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + (detalhe ? '\n          ' + detalhe : '')); } }
const TIPOS = Object.keys(A.CARDIO_METS), RPES = [1, 3, 5, 8, 10], MINS = [20, 30, 60];
const tabela = [];
TIPOS.forEach(t => RPES.forEach(r => MINS.forEach(m => tabela.push({ tipo: t, rpe: r, min: m, pontos: A.cardioPontosBase(t, r, m) }))));
const ARQ = path.join(__dirname, 'cardio_pontos.json');
if (process.argv[2] === 'gravar') { fs.writeFileSync(ARQ, JSON.stringify(tabela, null, 1) + '\n'); console.log('tabela gravada: ' + tabela.length + ' linhas'); process.exit(0); }

console.log('\n── Tabela de referência (a mesma que o servidor reproduz) ──');
const ref = JSON.parse(fs.readFileSync(ARQ, 'utf8'));
eq('api.js reproduz a tabela de ' + ref.length + ' linhas', ref.length === tabela.length && ref.every((x, i) => x.tipo === tabela[i].tipo && x.rpe === tabela[i].rpe && x.min === tabela[i].min && Math.abs(x.pontos - tabela[i].pontos) < 1e-9),
  'rode "node cardio_pontos.js gravar" só se a mudança de regra foi de propósito (e confira o PHP)');

console.log('\n── O tempo manda ──');
TIPOS.forEach(t => eq(t + ': o dobro do tempo dá o dobro dos pontos', Math.abs(A.cardioPontosBase(t, 5, 60) - 2 * A.cardioPontosBase(t, 5, 30)) < 0.11));
eq('minutos zerados = 0 pontos', A.cardioPontosBase('Corrida', 8, 0) === 0);

console.log('\n── O esforço pesa pouco ──');
TIPOS.forEach(t => { const lo = A.cardioPontosBase(t, 1, 30), me = A.cardioPontosBase(t, 5, 30), hi = A.cardioPontosBase(t, 10, 30);
  eq(t + ': esforço 10 vale no máximo 16% a mais que o 5 (e o 1, no máximo 13% a menos)', hi / me <= 1.16 && lo / me >= 0.87, 'lo ' + lo + ' me ' + me + ' hi ' + hi); });
eq('esforço fora de 1 a 10 é limitado', A.cardioPontosBase('Bike', 99, 30) === A.cardioPontosBase('Bike', 10, 30) && A.cardioPontosBase('Bike', -4, 30) === A.cardioPontosBase('Bike', 1, 30));
eq('esforço ausente vale como 5', A.cardioPontosBase('Bike', undefined, 30) === A.cardioPontosBase('Bike', 5, 30));

console.log('\n── Atividades coerentes entre si (30 min, esforço 5) ──');
const p = t => A.cardioPontosBase(t, 5, 30);
eq('a mais pesada não passa de 2x a mais leve (antes passava de 3x)', Math.max(...TIPOS.map(p)) / Math.min(...TIPOS.map(p)) <= 2, TIPOS.map(t => t + ' ' + p(t)).join(' | '));
eq('corrida pesa no máximo 1,6x a caminhada (antes 2,3x)', p('Corrida') / p('Caminhada') <= 1.6);
eq('bike pesa no máximo 1,5x a caminhada (antes 1,9x)', p('Bike') / p('Caminhada') <= 1.5);
eq('mesma ordem de antes: caminhada < bike < corrida', p('Caminhada') < p('Bike') && p('Bike') < p('Corrida'));
eq('atividade desconhecida vale como "Outro"', A.cardioPontosBase('Xadrez', 5, 30) === A.cardioPontosBase('Outro', 5, 30));

console.log('\n── No ranking (fator e teto entram depois) ──');
eq('corrida 30 min, esforço 10: abaixo do teto', A.pontosCardioRank(A.cardioPontosBase('Corrida', 10, 30)) < A.regrasRanking().cardioTeto);
eq('o teto por sessão continua valendo', A.pontosCardioRank(A.cardioPontosBase('Pular Corda', 10, 240)) === A.regrasRanking().cardioTeto);

console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
process.exit(ruim ? 1 : 0);
