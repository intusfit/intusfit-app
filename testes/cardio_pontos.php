<?php
// O servidor (treinos.php, _cardioPontosBase) precisa dar os mesmos pontos-base de cardio que o api.js (API.cardioPontosBase).
// Extrai a função do arquivo, sem mexer nele, e confere contra a tabela cardio_pontos.json gerada pelo api.js (node cardio_pontos.js gravar).
//   php testes/cardio_pontos.php
$cod = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/treinos.php'));
$i = strpos($cod, 'function _cardioPontosBase(');
$j = $i === false ? false : strpos($cod, "\n}\n", $i);
if ($j === false) { fwrite(STDERR, "funcao nao encontrada\n"); exit(1); }
eval(substr($cod, $i, $j - $i + 3));
$ref = json_decode(file_get_contents(__DIR__ . '/cardio_pontos.json'), true);
$ok = 0; $ruim = 0;
function eq($rot, $cond, $det = '') { global $ok, $ruim; if ($cond) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot $det\n"; } }
$dif = [];
foreach ($ref as $x) { $v = _cardioPontosBase($x['tipo'], $x['rpe'], $x['min']); if (abs($v - $x['pontos']) > 0.05) $dif[] = $x['tipo'] . ' rpe ' . $x['rpe'] . ' ' . $x['min'] . 'min: php ' . $v . ' js ' . $x['pontos']; }
echo "\n── Servidor igual ao api.js (" . count($ref) . " casos) ──\n";
eq('todos os casos batem', !$dif, implode(' | ', array_slice($dif, 0, 5)));
eq('atividade desconhecida vale como "Outro"', _cardioPontosBase('Xadrez', 5, 30) == _cardioPontosBase('Outro', 5, 30));
eq('esforço ausente ou 0 vale como 5', _cardioPontosBase('Bike', 0, 30) == _cardioPontosBase('Bike', 5, 30) && _cardioPontosBase('Bike', null, 30) == _cardioPontosBase('Bike', 5, 30));
eq('esforço acima de 10 é limitado', _cardioPontosBase('Bike', 99, 30) == _cardioPontosBase('Bike', 10, 30));
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
