// Regras do corte de vídeo (API.trecho): pontas, duração mínima e máxima, deslizar o trecho.
const { carregar } = require('./_api.js');
const T = carregar({ hoje: '2026-10-07' }).trecho;
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const DUR = 92, MAX = 60;   // vídeo de 1:32, trecho de até 60 s

console.log('\n── Pontas ──');
eq('trecho inicial de vídeo longo vai até o máximo', T.inicial(DUR, MAX), { ini: 0, fim: 60 });
eq('trecho inicial de vídeo curto é o vídeo todo', T.inicial(30, MAX), { ini: 0, fim: 30 });
eq('mover o início para dentro do trecho', T.janela(0, 60, DUR, MAX, 'ini', 16), { ini: 16, fim: 60 });
eq('mover o início para trás, o fim acompanha para não passar de 60 s', T.janela(16, 76, DUR, MAX, 'ini', 5), { ini: 5, fim: 65 });
eq('mover o fim para frente, o início acompanha', T.janela(0, 60, DUR, MAX, 'fim', 80), { ini: 20, fim: 80 });
eq('fim não passa da duração do vídeo', T.janela(30, 60, DUR, MAX, 'fim', 500), { ini: 32, fim: 92 });
eq('início não fica negativo', T.janela(0, 40, DUR, MAX, 'ini', -10), { ini: 0, fim: 40 });
eq('trecho nunca fica menor que 1 s (início encosta no fim)', T.janela(10, 20, DUR, MAX, 'ini', 25), { ini: 25, fim: 26 });
eq('trecho nunca fica menor que 1 s (fim arrastado para antes do início leva o início junto)', T.janela(10, 20, DUR, MAX, 'fim', 3), { ini: 2, fim: 3 });

console.log('\n── Deslizar o trecho ──');
eq('desliza para frente', T.deslocar(10, 30, DUR, 5), { ini: 15, fim: 35 });
eq('para no começo do vídeo', T.deslocar(10, 30, DUR, -50), { ini: 0, fim: 20 });
eq('para no fim do vídeo', T.deslocar(10, 30, DUR, 500), { ini: 72, fim: 92 });

console.log('\n── Posição na linha do tempo ──');
eq('meio do vídeo', T.posicao(46, DUR), 0.5);
eq('limita entre 0 e 1', [T.posicao(-5, DUR), T.posicao(500, DUR)], [0, 1]);
eq('duração zero não quebra', T.posicao(3, 0), 0);

console.log('\n' + ok + ' ok, ' + ruim + ' com falha');
process.exit(ruim ? 1 : 0);
