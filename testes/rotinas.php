<?php
// Rotinas automaticas (app/api/_rotinas.php): o plano do que fazer em cada rodada (puro, sem rede nem banco), a leitura real da validade do certificado do servidor
// e a ligacao com o gatilho do painel. Quebrar a regra de intervalo e acusado.
//   php -c <php.ini com openssl> testes/rotinas.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }
require __DIR__ . '/../app/api/_rotinas.php';
$d = 86400; $agora = 2000000000;

$p = intusRotinasPlano($agora, [], null, null);
eq('primeira rodada (sem estado): confere a cópia e manda o resumo', in_array('conferir_copia', $p, true) && in_array('resumo_mensal', $p, true), json_encode($p));
eq('...e sem backup parado nem certificado conhecido, não alerta', !in_array('alerta_backup', $p, true) && !in_array('alerta_certificado', $p, true));

$est = ['verificacao_em' => $agora - 2 * $d, 'resumo_em' => $agora - 5 * $d];
eq('conferência de 2 dias atrás e resumo de 5 dias atrás: nada a fazer', intusRotinasPlano($agora, $est, 0, 90) === []);
eq('conferência com 7 dias volta a rodar', in_array('conferir_copia', intusRotinasPlano($agora, ['verificacao_em' => $agora - 7 * $d, 'resumo_em' => $agora - $d], 0, 90), true));
eq('conferência com 6 dias ainda não roda', !in_array('conferir_copia', intusRotinasPlano($agora, ['verificacao_em' => $agora - 6 * $d, 'resumo_em' => $agora - $d], 0, 90), true));
eq('resumo mensal só depois de 30 dias', !in_array('resumo_mensal', intusRotinasPlano($agora, ['verificacao_em' => $agora, 'resumo_em' => $agora - 29 * $d], 0, 90), true) && in_array('resumo_mensal', intusRotinasPlano($agora, ['verificacao_em' => $agora, 'resumo_em' => $agora - 30 * $d], 0, 90), true));

$base = ['verificacao_em' => $agora, 'resumo_em' => $agora];
eq('backup com 1 dia de atraso não alerta', !in_array('alerta_backup', intusRotinasPlano($agora, $base, 1, 90), true));
eq('backup com 2 dias de atraso alerta', in_array('alerta_backup', intusRotinasPlano($agora, $base, 2, 90), true));
eq('alerta de backup não repete no mesmo dia', !in_array('alerta_backup', intusRotinasPlano($agora, $base + ['backup_alerta_em' => $agora - 3600], 5, 90), true));
eq('alerta de backup repete no dia seguinte', in_array('alerta_backup', intusRotinasPlano($agora, $base + ['backup_alerta_em' => $agora - 24 * 3600], 5, 90), true));
eq('certificado com 15 dias ou mais não alerta', !in_array('alerta_certificado', intusRotinasPlano($agora, $base, 0, 15), true));
eq('certificado com 14 dias alerta', in_array('alerta_certificado', intusRotinasPlano($agora, $base, 0, 14), true));
eq('alerta de certificado só a cada 3 dias', !in_array('alerta_certificado', intusRotinasPlano($agora, $base + ['cert_alerta_em' => $agora - 2 * $d], 3, 90), true) && in_array('alerta_certificado', intusRotinasPlano($agora, $base + ['cert_alerta_em' => $agora - 3 * $d], 3, 10), true));
eq('certificado vencido (dias negativos) alerta', in_array('alerta_certificado', intusRotinasPlano($agora, $base, 0, -2), true));

// leitura real da validade (rede): o servidor da KingHost responde na porta 443 com o certificado do dominio
$dias = intusRotinasDiasCertificado('intusfit.com.br', '191.6.209.210');
if ($dias === null) echo "  (sem rede para ler o certificado real: conferência pulada)\n";
else eq('lê a validade real do certificado da origem (entre 1 e 100 dias)', $dias > 0 && $dias < 100, (string)$dias);

$cat = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/catalogo.php'));
eq('o sino do painel dispara as rotinas depois do backup', strpos($cat, "@include __DIR__ . '/_carona_rotinas.php'") !== false);
$g = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/_carona_rotinas.php'));
eq('o gatilho respeita o intervalo de 6 horas e marca antes de rodar', strpos($g, '6 * 3600') !== false && strpos($g, "file_put_contents(\$marca") !== false && strpos($g, 'fastcgi_finish_request') !== false);
echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
