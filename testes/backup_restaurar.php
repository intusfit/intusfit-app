<?php
// Leitor de cópias do banco (tools/backup/restaurar.php --so-ler): lê um dump no formato do cron-backup.php, conta tabelas e dados, não se confunde
// com ";" nem aspas dentro de texto, e reprova cópia cortada.
//   php -c <php.ini com zlib> testes/backup_restaurar.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }
$php = PHP_BINARY; $script = realpath(__DIR__ . '/../tools/backup/restaurar.php');
$tmp = sys_get_temp_dir() . '/dump-teste-' . getmypid();

$sql  = "-- Backup Intus — teste — 14/10/2026 08:00:00\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
$sql .= "\n-- ─── aluno ───\nDROP TABLE IF EXISTS `aluno`;\nCREATE TABLE `aluno` (\n  `id` int(11) NOT NULL,\n  `nome` varchar(80) DEFAULT NULL,\n  `obs` text,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
$sql .= "INSERT INTO `aluno` VALUES\n(1,'Ana','dor no joelho; repetir ciclo;'),\n(2,'O\\'Brien','linha 1\\nlinha 2 );\n'),\n(3,'Caio','texto com ('' aspas duplas escapadas'' e ponto e vírgula;');\n";
$sql .= "\n-- ─── treino ───\nDROP TABLE IF EXISTS `treino`;\nCREATE TABLE `treino` (\n  `id` int(11) NOT NULL\n) ENGINE=InnoDB;\n";
$sql .= "INSERT INTO `treino` VALUES\n(10),\n(11);\n\nSET FOREIGN_KEY_CHECKS=1;\n";
file_put_contents($tmp . '.sql.gz', gzencode($sql));

$r = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --arquivo=' . escapeshellarg($tmp . '.sql.gz') . ' --so-ler 2>&1');
eq('lê o arquivo até o fim', strpos($r, 'Arquivo íntegro') !== false, $r);
eq('conta as 2 tabelas', preg_match('/Tabelas: 2/', $r) === 1, $r);
eq('conta os 2 comandos de dados (texto com ";" e aspas não quebra o comando)', preg_match('/Comandos de dados: 2 /', $r) === 1, $r);

// cópia cortada no meio de um comando
$corte = substr($sql, 0, strpos($sql, '(2,') + 6);
file_put_contents($tmp . '-cortada.sql.gz', gzencode($corte));
$r2 = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --arquivo=' . escapeshellarg($tmp . '-cortada.sql.gz') . ' --so-ler 2>&1');
eq('reprova cópia cortada no meio de um comando', strpos((string)$r2, 'Arquivo íntegro') === false && stripos((string)$r2, 'cortada') !== false, $r2);

// arquivo que nem é gzip
file_put_contents($tmp . '-lixo.sql.gz', 'isto nao e gzip');
$r3 = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --arquivo=' . escapeshellarg($tmp . '-lixo.sql.gz') . ' --so-ler 2>&1');
eq('reprova arquivo que não é gzip', strpos((string)$r3, 'Arquivo íntegro') === false, $r3);

// importar sem confirmar o nome do banco é recusado (não toca em nada)
putenv('DB_HOST=x'); putenv('DB_NAME=intus'); putenv('DB_USER=u'); putenv('DB_PASS=p');
$r4 = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($script) . ' --arquivo=' . escapeshellarg($tmp . '.sql.gz') . ' 2>&1');
eq('recusa importar sem --confirmo-banco', stripos((string)$r4, 'confirmo-banco') !== false, $r4);

@unlink($tmp . '.sql.gz'); @unlink($tmp . '-cortada.sql.gz'); @unlink($tmp . '-lixo.sql.gz');
echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
