<?php
/**
 * Intus Fit — Server-side Session Tokens
 *
 * Gera e valida tokens seguros (32 bytes hex). Tokens antigos (prof-*, aluno-*)
 * continuam aceitos por compatibilidade — a migração é transparente.
 *
 * Tabela: intus_sessions (auto-criada)
 */

function _ensureSessionsTable(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query("SELECT 1 FROM `intus_sessions` LIMIT 1");
        $ok = true;
    } catch (Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE `intus_sessions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `token_hash` VARCHAR(64) NOT NULL,
                `user_id` INT NOT NULL,
                `user_type` ENUM('prof','aluno') NOT NULL DEFAULT 'prof',
                `user_name` VARCHAR(200) NULL,
                `admin` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                `last_used` DATETIME NULL,
                `ip` VARCHAR(45) NULL,
                UNIQUE INDEX `idx_token` (`token_hash`),
                INDEX `idx_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Cria um novo token de sessão server-side.
 * Retorna o token plaintext (para enviar ao client) ou null se falhar.
 */
function createSession(PDO $pdo, int $userId, string $type = 'prof', string $userName = '', bool $admin = false, int $ttlDays = 7): ?string {
    if (!_ensureSessionsTable($pdo)) return null;

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlDays * 86400));
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        $st = $pdo->prepare("INSERT INTO `intus_sessions` (`token_hash`, `user_id`, `user_type`, `user_name`, `admin`, `expires_at`, `ip`) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $st->execute([$hash, $userId, $type, $userName, $admin ? 1 : 0, $expiresAt, $ip]);
    } catch (Throwable $e) {
        return null;
    }

    return $token;
}

/**
 * Valida um token server-side. Retorna dados da sessão ou null.
 * Tokens legacy (prof-*, aluno-*) passam direto com validação pelo formato.
 */
function validateSession(PDO $pdo, string $token): ?array {
    if (!$token) return null;

    // ── OS TOKENS "LEGACY" FORAM REMOVIDOS EM 20/08/2026 ────────────────────
    // Aqui estavam dois preg_match que devolviam identidade a partir do FORMATO
    // do texto: "aluno-42-x" virava o aluno 42, e "prof-1-x" virava o professor
    // 1 com admin = true. Sem senha, sem banco, sem validade — o formato era a
    // credencial. Toda a verificacao de sessao real abaixo era irrelevante
    // enquanto estas oito linhas existissem, porque elas vinham antes.
    // O interruptor equivalente no _auth_context.php ja tinha sido desligado; o
    // sistema tinha DUAS portas para a mesma coisa, e esta continuou aberta.
    // Se alguem ainda tiver login antigo guardado, entra uma vez de novo.

    // Token server-side (64 hex chars)
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    if (!_ensureSessionsTable($pdo)) return null;

    $hash = hash('sha256', $token);
    try {
        $st = $pdo->prepare("SELECT * FROM `intus_sessions` WHERE `token_hash` = ? AND `expires_at` > NOW() LIMIT 1");
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!$row) return null;

    // Atualizar last_used
    try {
        $pdo->prepare("UPDATE `intus_sessions` SET `last_used` = NOW() WHERE `id` = ?")->execute([$row['id']]);
    } catch (Throwable $e) {}

    return [
        'user_id' => (int)$row['user_id'],
        'user_type' => $row['user_type'],
        'user_name' => $row['user_name'] ?? '',
        'admin' => (bool)$row['admin'],
        'legacy' => false,
        'expires_at' => $row['expires_at'],
    ];
}

/**
 * Revoga uma sessão (logout).
 */
function revokeSession(PDO $pdo, string $token): void {
    if (!_ensureSessionsTable($pdo)) return;
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return;
    $hash = hash('sha256', $token);
    try {
        $pdo->prepare("DELETE FROM `intus_sessions` WHERE `token_hash` = ?")->execute([$hash]);
    } catch (Throwable $e) {}
}

/**
 * Limpeza de sessões expiradas (chamar periodicamente).
 */
function cleanExpiredSessions(PDO $pdo): void {
    if (!_ensureSessionsTable($pdo)) return;
    try {
        $pdo->exec("DELETE FROM `intus_sessions` WHERE `expires_at` < NOW()");
    } catch (Throwable $e) {}
}
