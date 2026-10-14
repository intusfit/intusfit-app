<?php
// Envio do backup ao Drive em pedaços (cron-backup.php, _backupEnviarArquivoDrive) com um Drive de mentira:
// um arquivo maior que um pedaço sobe inteiro, o Drive que aceita só parte do pedaço é reenviado do ponto certo, falha passageira é repetida,
// falha de verdade devolve o motivo, e o arquivo nunca é lido inteiro de uma vez.
//   php -c <php.ini> testes/backup_envio.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }

$cod = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/cron-backup.php'));
$i = strpos($cod, 'function _backupEnviarArquivoDrive'); $j = strpos($cod, "\n}\n", $i);
eval(substr($cod, $i, $j - $i + 3));
function _bkProg(int $pct, string $etapa, string $estado = 'rodando', string $msg = ''): void { $GLOBALS['progs'][] = [$pct, $etapa]; }
$GLOBALS['progs'] = [];

// Drive de mentira
$GLOBALS['drive'] = ['recebido' => '', 'chamadas' => 0, 'modo' => 'normal', 'falhasRestantes' => 0, 'maxPorChamada' => PHP_INT_MAX];
function gdriveIniciarUploadGrande($nome, $mime, $tamanho) {
    if ($GLOBALS['drive']['modo'] === 'recusa') throw new Exception('Drive não abriu o upload (HTTP 403)');
    $GLOBALS['drive']['recebido'] = ''; $GLOBALS['drive']['chamadas'] = 0; return 'sessao://x';
}
function gdriveEnviarParte($sessao, $bin, $inicio, $total) {
    $d = &$GLOBALS['drive']; $d['chamadas']++;
    if ($d['falhasRestantes'] > 0) { $d['falhasRestantes']--; throw new Exception('Drive recusou o pedaço (HTTP 503)'); }
    if ($inicio !== strlen($d['recebido'])) throw new Exception("inicio {$inicio} fora de ordem (Drive tem " . strlen($d['recebido']) . ')');
    $aceito = substr($bin, 0, $d['maxPorChamada']);
    $d['recebido'] .= $aceito;
    if (strlen($d['recebido']) >= $total) return ['recebido' => $total, 'file_id' => 'ID1'];
    return ['recebido' => strlen($d['recebido']), 'file_id' => null];
}

function arquivo($bytes) { $p = sys_get_temp_dir() . '/bk-envio-' . getmypid() . '-' . mt_rand(); file_put_contents($p, random_bytes($bytes)); return $p; }

// 1) arquivo de 20 MB (3 pedaços de 8 MB) sobe inteiro e igual
$a = arquivo(20 * 1048576); $orig = file_get_contents($a);
[$r, $m] = _backupEnviarArquivoDrive($a, 'x.zip', 'application/zip', 70, 74);
eq('arquivo de 20 MB sobe inteiro', $r === true, $m);
eq('o que o Drive recebeu é idêntico ao arquivo', $GLOBALS['drive']['recebido'] === $orig);
eq('foram 3 pedaços', $GLOBALS['drive']['chamadas'] === 3, 'chamadas=' . $GLOBALS['drive']['chamadas']);
eq('a barra anda e termina no fim da faixa', end($GLOBALS['progs'])[0] === 74 && $GLOBALS['progs'][0][0] >= 70, json_encode($GLOBALS['progs']));
unlink($a);

// 2) o Drive aceita só 3 MB de cada pedaço de 8 MB: o código reenvia do ponto certo e o resultado continua idêntico
$GLOBALS['drive']['maxPorChamada'] = 3 * 1048576;
$a = arquivo(10 * 1048576); $orig = file_get_contents($a);
[$r, $m] = _backupEnviarArquivoDrive($a, 'y.zip', 'application/zip', 0, 10);
eq('Drive que aceita só parte do pedaço: reenvia do ponto certo', $r === true && $GLOBALS['drive']['recebido'] === $orig, $m);
unlink($a); $GLOBALS['drive']['maxPorChamada'] = PHP_INT_MAX;

// 3) falha passageira (2 erros, 3ª tentativa passa)
$GLOBALS['drive']['falhasRestantes'] = 2;
$a = arquivo(1048576);
[$r, $m] = _backupEnviarArquivoDrive($a, 'z.sql.gz', 'application/gzip', 56, 68);
eq('falha passageira é repetida e o envio dá certo', $r === true, $m);
unlink($a);

// 4) falha de verdade devolve o motivo
$GLOBALS['drive']['falhasRestantes'] = 99;
$a = arquivo(1048576);
[$r, $m] = _backupEnviarArquivoDrive($a, 'w.zip', 'application/zip', 0, 5);
eq('falha de verdade devolve o motivo do Drive', $r === false && strpos($m, '503') !== false, $m);
unlink($a); $GLOBALS['drive']['falhasRestantes'] = 0;

// 5) o Drive nem abre o envio
$GLOBALS['drive']['modo'] = 'recusa';
$a = arquivo(1024);
[$r, $m] = _backupEnviarArquivoDrive($a, 'v.zip', 'application/zip', 0, 5);
eq('Drive que recusa abrir o envio: motivo na resposta', $r === false && strpos($m, '403') !== false, $m);
unlink($a); $GLOBALS['drive']['modo'] = 'normal';

// 6) arquivo vazio e arquivo que não existe
$a = sys_get_temp_dir() . '/bk-vazio-' . getmypid(); file_put_contents($a, '');
[$r, $m] = _backupEnviarArquivoDrive($a, 'u.zip', 'application/zip', 0, 5);
eq('arquivo vazio é recusado com motivo', $r === false && $m !== '', $m); unlink($a);
[$r, $m] = _backupEnviarArquivoDrive('/nao/existe.zip', 'u.zip', 'application/zip', 0, 5);
eq('arquivo que não existe é recusado com motivo', $r === false && $m !== '', $m);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
