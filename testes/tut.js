// Leitura do T.U.T da ficha (aluno.html, _tutLer): a explicação de "exercício isométrico" só vale para campo em branco, "-" ou "x";
// 4 dígitos (com ou sem separador) são válidos; qualquer outra coisa é "outro formato" e NÃO vira isométrico.
const fs = require('fs'), path = require('path');
const dir = process.env.INTUS_DIR || path.join(__dirname, '..', 'app', 'painel');
const src = fs.readFileSync(path.join(dir, 'aluno.html'), 'utf8');
const m = src.match(/function _tutLer\(bruto\) \{[\s\S]*?\n\}\n/);
if (!m) { console.log('FALHA: _tutLer não encontrada em aluno.html'); process.exit(1); }
const _tutLer = new Function(m[0] + '; return _tutLer;')();
let ok = 0, falhas = 0;
function esp(entrada, tipo, dig) {
  const r = _tutLer(entrada);
  if (r.tipo === tipo && (dig === undefined || r.dig === dig)) { ok++; console.log('  OK    ' + JSON.stringify(entrada) + ' -> ' + tipo + (dig ? ' ' + dig : '')); }
  else { falhas++; console.log('  FALHA ' + JSON.stringify(entrada) + ': esperava ' + tipo + (dig ? ' ' + dig : '') + ', veio ' + JSON.stringify(r)); }
}
['', ' ', null, undefined, '-', '--', '–', 'x', 'X', ' x '].forEach(function (v) { esp(v, 'iso', ''); });
esp('3020', 'ok', '3020'); esp('3-0-1-0', 'ok', '3010'); esp('3 0 1 0', 'ok', '3010'); esp('0000', 'ok', '0000'); esp(3020, 'ok', '3020');
['30X0', '302', '30201', 'abc', '3x', 'x3020'].forEach(function (v) { esp(v, 'outro', ''); });
console.log('\n' + (falhas ? 'FALHOU: ' + falhas : 'Tudo certo: ' + ok + ' conferências.'));
process.exit(falhas ? 1 : 0);
