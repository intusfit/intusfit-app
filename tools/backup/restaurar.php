<?php
/**
 * RESTAURAR UMA CÓPIA DO BANCO (arquivo intus-backup-db-AAAA-MM-DD_HHMMSS.sql.gz) num banco MySQL.
 *
 * Só roda pela linha de comando. Serve para dois casos:
 *   1) TESTE de restauração (recomendado todo mês): importar a cópia num banco VAZIO de teste e conferir se tudo volta;
 *   2) EMERGÊNCIA: voltar o banco depois de um problema (com o OK do Luiz, ver RESTAURAR.md).
 *
 * Uso:
 *   php tools/backup/restaurar.php --arquivo=intus-backup-db-2026-10-14_080000.sql.gz --so-ler
 *       só lê o arquivo e conta o que há dentro (nenhum banco é tocado). Use para conferir a cópia.
 *
 *   DB_HOST=... DB_NAME=... DB_USER=... DB_PASS=... php tools/backup/restaurar.php --arquivo=ARQ.sql.gz --confirmo-banco=NOME_DO_BANCO
 *       importa no banco indicado pelas variáveis de ambiente. A senha só vai pelo ambiente (nunca em arquivo nem no comando).
 *       --confirmo-banco precisa repetir o nome do banco: trava contra importar no banco errado.
 *       O dump faz DROP TABLE IF EXISTS antes de cada CREATE: o banco de destino PERDE as tabelas que existirem com o mesmo nome.
 *
 * Nunca aponte para o banco de produção a não ser numa emergência real, decidida com o Luiz.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$op = getopt('', ['arquivo:', 'so-ler', 'confirmo-banco:']);
$arq = $op['arquivo'] ?? '';
if ($arq === '' || !is_file($arq)) { fwrite(STDERR, "Informe --arquivo=CAMINHO (o .sql.gz do backup).\n"); exit(2); }

/** Lê o dump e devolve cada comando SQL completo, um por vez (as linhas de dados não têm quebra de linha dentro dos textos: o backup escapa). */
function restaurarComandos(string $arq): Generator {
    $gz = gzopen($arq, 'rb');
    if (!$gz) throw new RuntimeException('Não consegui abrir o arquivo (não é um .gz válido).');
    $buf = '';
    while (!gzeof($gz)) {
        $l = gzgets($gz, 4194304);
        if ($l === false) break;
        if ($buf === '' && (trim($l) === '' || strncmp($l, '--', 2) === 0)) continue;      // comentário ou linha vazia entre comandos
        $buf .= $l;
        $t = rtrim($buf);
        if ($t !== '' && substr($t, -1) === ';' && restaurarAspasFechadas($t)) { yield $t; $buf = ''; }
    }
    $fim = gzeof($gz); gzclose($gz);
    if (trim($buf) !== '') throw new RuntimeException('O arquivo termina no meio de um comando (cópia cortada ou corrompida).');
    if (!$fim) throw new RuntimeException('A leitura parou antes do fim do arquivo (cópia corrompida).');
}
/** O comando só acaba quando as aspas simples estão fechadas (considera \\ e '' como escape). */
function restaurarAspasFechadas(string $sql): bool {
    $aberta = false; $n = strlen($sql);
    for ($i = 0; $i < $n; $i++) {
        $c = $sql[$i];
        if ($aberta) {
            if ($c === '\\') { $i++; continue; }
            if ($c === "'") { if ($i + 1 < $n && $sql[$i + 1] === "'") { $i++; continue; } $aberta = false; }
        } elseif ($c === "'") { $aberta = true; }
    }
    return !$aberta;
}

if (isset($op['so-ler'])) {
    $tab = 0; $ins = 0; $linhasDados = 0; $outros = 0;
    foreach (restaurarComandos($arq) as $c) {
        if (strncmp($c, 'CREATE TABLE', 12) === 0) $tab++;
        elseif (strncmp($c, 'INSERT INTO', 11) === 0) { $ins++; $linhasDados += substr_count($c, "),\n(") + 1; }
        else $outros++;
    }
    echo "Arquivo íntegro.\nTabelas: {$tab}\nComandos de dados: {$ins} (cerca de {$linhasDados} linhas)\nOutros comandos: {$outros}\n";
    exit(0);
}

$host = getenv('DB_HOST') ?: ''; $nome = getenv('DB_NAME') ?: ''; $user = getenv('DB_USER') ?: ''; $pass = getenv('DB_PASS') ?: '';
if ($host === '' || $nome === '' || $user === '') { fwrite(STDERR, "Defina DB_HOST, DB_NAME, DB_USER e DB_PASS no ambiente.\n"); exit(2); }
if (($op['confirmo-banco'] ?? '') !== $nome) { fwrite(STDERR, "Para importar, repita o nome do banco: --confirmo-banco={$nome}\n"); exit(2); }

$pdo = new PDO("mysql:host={$host};dbname={$nome};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$n = 0; $t0 = microtime(true);
foreach (restaurarComandos($arq) as $c) { $pdo->exec($c); $n++; if ($n % 200 === 0) echo "... {$n} comandos\n"; }
$tabs = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();
echo "Restauração concluída: {$n} comandos em " . round(microtime(true) - $t0, 1) . "s. Tabelas no banco agora: {$tabs}.\n";
