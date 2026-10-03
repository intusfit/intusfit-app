<?php
/**
 * Intus Fit — notificações sociais (sino) e menções (@).
 *
 * Biblioteca interna (prefixo _ = não é endpoint). Usada por catalogo.php
 * (Feed, comentários, reações) e treinos.php (comentário do mural do ranking).
 * Nenhuma função daqui lança: falha de notificação nunca derruba a ação que a
 * originou.
 */

// ── NOTIFICAÇÕES SOCIAIS (sino) ─────────────────────────────────────────────
// Uma linha por destinatário, com "lido" próprio. NÃO reaproveita intus_avisos:
// aquela é só do professor e o "lido" dela vale pra todo mundo de uma vez.
// destino_tipo/ator_tipo seguem o mesmo par de _resolverAutorPub: 'aluno'
// (id = idatleta) ou 'prof' (id = idusuario).
function _notifGarantirTabela(PDO $pdo) {
    static $ok = false;
    if ($ok) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_notificacao (
            idnotificacao INT AUTO_INCREMENT PRIMARY KEY,
            destino_tipo  VARCHAR(10)  NOT NULL,
            destino_id    INT          NOT NULL,
            tipo          VARCHAR(20)  NOT NULL,
            ator_tipo     VARCHAR(10)  NOT NULL,
            ator_id       INT          NOT NULL,
            ator_nome     VARCHAR(200) NOT NULL DEFAULT '',
            alvo_tipo     VARCHAR(20)  NOT NULL,
            alvo_id       INT          NOT NULL,
            texto         VARCHAR(160) NULL,
            lido          TINYINT(1)   NOT NULL DEFAULT 0,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_destino (destino_tipo, destino_id, lido),
            INDEX idx_alvo (alvo_tipo, alvo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ok = true;
}

// Falha aqui NUNCA pode derrubar comentar/curtir: tudo dentro de try/catch e
// sem retorno que o chamador precise checar. Quem faz a ação não notifica a si
// mesmo. $dedupe = não repetir o mesmo (destino, tipo, autor, alvo) — usado em
// curtida, que dá pra ligar/desligar em sequência e encheria o sino.
function _criarNotificacao(PDO $pdo, string $destTipo, int $destId, string $tipo, string $atorTipo, int $atorId, string $atorNome, string $alvoTipo, int $alvoId, string $texto = '', bool $dedupe = false) {
    if ($destId <= 0 || $atorId <= 0) return;
    if ($destTipo === $atorTipo && $destId === $atorId) return;
    try {
        _notifGarantirTabela($pdo);
        if ($dedupe) {
            $q = $pdo->prepare("SELECT 1 FROM intus_notificacao WHERE destino_tipo = ? AND destino_id = ? AND tipo = ? AND ator_tipo = ? AND ator_id = ? AND alvo_tipo = ? AND alvo_id = ? LIMIT 1");
            $q->execute([$destTipo, $destId, $tipo, $atorTipo, $atorId, $alvoTipo, $alvoId]);
            if ($q->fetchColumn()) return;
        }
        $texto = _mencoesParaTexto($texto);
        if (mb_strlen($texto) > 160) $texto = mb_substr($texto, 0, 160);
        $pdo->prepare("INSERT INTO intus_notificacao (destino_tipo, destino_id, tipo, ator_tipo, ator_id, ator_nome, alvo_tipo, alvo_id, texto) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$destTipo, $destId, $tipo, $atorTipo, $atorId, $atorNome, $alvoTipo, $alvoId, $texto !== '' ? $texto : null]);
    } catch (Throwable $e) {}
}

// ── MENÇÕES (@) ─────────────────────────────────────────────────────────────
// Não existe @usuário único no app (só nome, que repete). A menção só nasce do
// autocomplete do app e vai gravada no texto como token:
//   @[Fabiola Cavalheiro]{aluno:42}   ou   @[Ana]{prof:3}
// O app mostra "@Nome" destacado; digitar "@alguem" na mão não cria menção.
function _mencoesRegex() { return '/@\[([^\]\r\n]{1,60})\]\{(aluno|prof):(\d+)\}/u'; }

// Troca os tokens por "@Nome" (texto de notificação, trecho, etc).
function _mencoesParaTexto(string $texto): string {
    $r = preg_replace(_mencoesRegex(), '@$1', $texto);
    return $r === null ? $texto : $r;
}

// Corta no limite sem deixar pedaço de token no fim (um "@[Nome]{alu" cru
// apareceria feio no texto).
function _mencoesLimitar(string $texto, int $max): string {
    if (mb_strlen($texto) <= $max) return $texto;
    $t = mb_substr($texto, 0, $max);
    $r = preg_replace('/@\[[^\]\r\n]*\]?$|@\[[^\]\r\n]*\]\{[^}\r\n]*$/u', '', $t);
    return rtrim($r === null ? $t : $r);
}

// [{tipo,id,nome}] sem repetir pessoa, no máximo 10 por texto.
function _extrairMencoes(string $texto): array {
    $out = [];
    if (!preg_match_all(_mencoesRegex(), $texto, $m, PREG_SET_ORDER)) return [];
    foreach ($m as $x) {
        $k = $x[2] . ':' . (int)$x[3];
        if (!isset($out[$k])) $out[$k] = ['tipo' => $x[2], 'id' => (int)$x[3], 'nome' => $x[1]];
        if (count($out) >= 10) break;
    }
    return array_values($out);
}

function _mencaoTabela(PDO $pdo, array $nomes) {
    foreach ($nomes as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return $t; } catch (Throwable $e) {}
    }
    return null;
}

// O token vem do cliente, então confere no servidor se a pessoa existe (e, no
// caso de aluno, se está no Feed e não está bloqueada). Evita avisar qualquer id.
function _mencaoAlvoValido(PDO $pdo, string $tipo, int $id): bool {
    if ($id <= 0) return false;
    try {
        if ($tipo === 'aluno') {
            $t = _mencaoTabela($pdo, ['atleta', 'atletas', 'aluno', 'alunos']);
            if (!$t) return false;
            try {
                $q = $pdo->prepare("SELECT 1 FROM `$t` WHERE idatleta = ? AND feed_optin = 'S' AND (stbloqueio IS NULL OR stbloqueio != 'S') LIMIT 1");
                $q->execute([$id]);
            } catch (Throwable $e) {
                $q = $pdo->prepare("SELECT 1 FROM `$t` WHERE idatleta = ? LIMIT 1");
                $q->execute([$id]);
            }
            return (bool)$q->fetchColumn();
        }
        $t = _mencaoTabela($pdo, ['professor', 'usuario', 'usuarios', 'professores']);
        if (!$t) return false;
        $cols = array_column($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $cId = null; foreach (['idusuario', 'idprofessor', 'id'] as $c) { if (in_array($c, $cols, true)) { $cId = $c; break; } }
        if (!$cId) return false;
        $q = $pdo->prepare("SELECT 1 FROM `$t` WHERE `$cId` = ? LIMIT 1");
        $q->execute([$id]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// Entre dois alunos com bloqueio de feed (em qualquer sentido), não avisa.
function _mencaoBloqueada(PDO $pdo, string $atorTipo, int $atorId, string $destTipo, int $destId): bool {
    if ($atorTipo !== 'aluno' || $destTipo !== 'aluno') return false;
    try {
        $q = $pdo->prepare("SELECT 1 FROM intus_feed_pref WHERE (idatleta = ? AND idalvo = ?) OR (idatleta = ? AND idalvo = ?) LIMIT 1");
        $q->execute([$atorId, $destId, $destId, $atorId]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) { return false; }
}

// Avisa cada pessoa marcada no texto. $jaAvisados ('tipo:id' => 1) são quem já
// recebeu outro aviso por ESTE mesmo comentário (dono do post, autor do
// comentário-pai) e não recebe de novo só por estar marcado.
function _notificarMencoes(PDO $pdo, string $texto, string $atorTipo, int $atorId, string $atorNome, string $alvoTipo, int $alvoId, array $jaAvisados = []) {
    try {
        foreach (_extrairMencoes($texto) as $m) {
            if (isset($jaAvisados[$m['tipo'] . ':' . $m['id']])) continue;
            if ($m['tipo'] === $atorTipo && $m['id'] === $atorId) continue;
            if (!_mencaoAlvoValido($pdo, $m['tipo'], $m['id'])) continue;
            if (_mencaoBloqueada($pdo, $atorTipo, $atorId, $m['tipo'], $m['id'])) continue;
            _criarNotificacao($pdo, $m['tipo'], $m['id'], 'mencao', $atorTipo, $atorId, $atorNome, $alvoTipo, $alvoId, $texto);
        }
    } catch (Throwable $e) {}
}
