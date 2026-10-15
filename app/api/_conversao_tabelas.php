<?php
/**
 * CONVERSÃO DAS TABELAS ANTIGAS DO BANCO PARA InnoDB + utf8mb4 (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Por que: o banco veio do sistema antigo do Intus com 23 tabelas MyISAM e/ou latin1 (atleta, professor, mensalidade, treino, serie...). MyISAM trava a TABELA INTEIRA a cada
 * gravação (com poucos processos PHP isso vira fila nos picos), não se recupera sozinho de uma queda no meio de uma gravação e latin1 não guarda emoji nem alguns nomes.
 * As 68 tabelas novas já são InnoDB utf8mb4_general_ci; o destino é o mesmo, para não misturar collations (isso daria "Illegal mix of collations" em comparação entre colunas).
 *
 * Como converte uma tabela (uma por vez, só quando o administrador manda, em Configurações > Servidor > Tabelas antigas):
 *   1. cópia de segurança  conv_bak_<tabela>  (CREATE TABLE ... LIKE + INSERT ... SELECT). NUNCA é apagada por código: apagar é decisão do administrador, pelo phpMyAdmin.
 *   2. impressão digital ANTES: contagem de linhas e somas de CRC32 do conteúdo de todas as colunas normalizado em utf8mb4 (o mesmo cálculo vale antes e depois da conversão)
 *   3. ALTER TABLE ... ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci   (atômico; falha = a tabela continua como estava)
 *   4. impressão digital DEPOIS: se não bater, devolve a tabela antiga (RENAME, sem apagar nada) e informa a falha.
 * O AUTO_INCREMENT é preservado pelo próprio ALTER (o contador não volta atrás). Foreign keys, triggers e views: não existem neste banco (conferido na cópia de 15/10/2026).
 */

function conversaoNomeValido(string $t): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_]{1,64}$/', $t);
}

/** Tabelas que ainda são MyISAM ou latin1 (e não são as cópias de segurança desta própria rotina). */
function conversaoListar(PDO $pdo): array
{
    $st = $pdo->query("SELECT table_name AS t, engine AS e, table_collation AS c, table_rows AS linhas, (data_length + index_length) AS bytes, auto_increment AS ai
                       FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
                         AND table_name NOT LIKE 'conv\_bak\_%' AND table_name NOT LIKE 'conv\_falhou\_%'
                         AND (engine <> 'InnoDB' OR table_collation NOT LIKE 'utf8mb4%')
                       ORDER BY (data_length + index_length) DESC, table_name");
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['tabela' => $r['t'], 'engine' => $r['e'], 'collation' => $r['c'], 'linhas' => (int)$r['linhas'], 'bytes' => (int)$r['bytes'], 'auto_increment' => $r['ai'] !== null ? (int)$r['ai'] : null];
    }
    return $out;
}

/** Cópias de segurança já criadas por esta rotina (para o administrador conferir e, só depois, apagar pelo phpMyAdmin). */
function conversaoCopias(PDO $pdo): array
{
    $st = $pdo->query("SELECT table_name AS t, table_rows AS linhas, (data_length + index_length) AS bytes FROM information_schema.tables
                       WHERE table_schema = DATABASE() AND (table_name LIKE 'conv\_bak\_%' OR table_name LIKE 'conv\_falhou\_%') ORDER BY table_name");
    return array_map(fn($r) => ['tabela' => $r['t'], 'linhas' => (int)$r['linhas'], 'bytes' => (int)$r['bytes']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Impressão digital do conteúdo da tabela, igual antes e depois de mudar motor e charset: [linhas, soma de CRC32, XOR de CRC32]. */
function conversaoImpressao(PDO $pdo, string $t): array
{
    $st = $pdo->prepare("SELECT column_name AS c, data_type AS d FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position");
    $st->execute([$t]);
    $partes = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $col) {
        $c = '`' . str_replace('`', '``', $col['c']) . '`';
        $d = strtolower($col['d']);
        if (in_array($d, ['blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary'], true)) $partes[] = "IFNULL(HEX($c), '~N')";
        elseif (in_array($d, ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set'], true)) $partes[] = "IFNULL(CONVERT($c USING utf8mb4), '~N')";
        else $partes[] = "IFNULL(CAST($c AS CHAR), '~N')";
    }
    if (!$partes) return [0, 0, 0];
    $linha = 'CONCAT_WS(CHAR(31), ' . implode(', ', $partes) . ')';
    $r = $pdo->query("SELECT COUNT(*) AS n, IFNULL(SUM(CRC32($linha)), 0) AS s, IFNULL(BIT_XOR(CRC32($linha)), 0) AS x FROM `$t`")->fetch(PDO::FETCH_ASSOC);
    return [(int)$r['n'], (string)$r['s'], (string)$r['x']];
}

/**
 * Converte UMA tabela. Devolve ['ok' => bool, 'msg' => texto, 'antes' => [...], 'depois' => [...], 'copia' => nome da cópia de segurança].
 * Nunca apaga nada: em caso de divergência troca de volta pela cópia (RENAME) e deixa a tabela com problema guardada como conv_falhou_<tabela>.
 */
function conversaoConverter(PDO $pdo, string $t): array
{
    if (!conversaoNomeValido($t)) return ['ok' => false, 'msg' => 'Nome de tabela inválido.'];
    $alvo = null;
    foreach (conversaoListar($pdo) as $x) { if ($x['tabela'] === $t) $alvo = $x; }
    if (!$alvo) return ['ok' => false, 'msg' => 'A tabela não está na lista de tabelas antigas (já convertida ou não existe).'];

    $bak = 'conv_bak_' . substr($t, 0, 50);
    $ex = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $ex->execute([$bak]);
    if ((int)$ex->fetchColumn() > 0) $bak .= '_' . date('Ymd_His');
    $bak = substr($bak, 0, 64);

    try {
        $pdo->exec("CREATE TABLE `$bak` LIKE `$t`");
        $pdo->exec("INSERT INTO `$bak` SELECT * FROM `$t`");
        $antes = conversaoImpressao($pdo, $t);
        $copia = conversaoImpressao($pdo, $bak);
        if ($antes !== $copia) return ['ok' => false, 'msg' => 'A cópia de segurança não bateu com a tabela original; nada foi convertido.', 'antes' => $antes, 'copia' => $bak];
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'Não consegui criar a cópia de segurança (nada foi convertido): ' . $e->getMessage()];
    }

    try {
        $pdo->exec("ALTER TABLE `$t` ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'O MySQL recusou a conversão e a tabela continua como estava: ' . $e->getMessage(), 'antes' => $antes, 'copia' => $bak];
    }

    $depois = conversaoImpressao($pdo, $t);
    if ($depois !== $antes) {
        $falhou = substr('conv_falhou_' . $t, 0, 64);
        try { $pdo->exec("RENAME TABLE `$t` TO `$falhou`, `$bak` TO `$t`"); } catch (Throwable $e) { return ['ok' => false, 'msg' => 'ATENÇÃO: o conteúdo mudou depois da conversão e não consegui devolver a tabela antiga: ' . $e->getMessage() . ' A cópia está em ' . $bak, 'antes' => $antes, 'depois' => $depois, 'copia' => $bak]; }
        return ['ok' => false, 'msg' => 'O conteúdo não bateu depois da conversão: a tabela antiga foi devolvida e a convertida ficou guardada como ' . $falhou . '.', 'antes' => $antes, 'depois' => $depois];
    }
    return ['ok' => true, 'msg' => 'Convertida para InnoDB e utf8mb4 (' . $depois[0] . ' linhas, conteúdo idêntico). Cópia de segurança: ' . $bak . '.', 'antes' => $antes, 'depois' => $depois, 'copia' => $bak];
}
