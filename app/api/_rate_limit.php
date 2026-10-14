<?php
/**
 * Rate-limiting por IP usando tabela MySQL.
 * Uso: require_once '_rate_limit.php'; checkRateLimit($pdo, 'login', 5, 900);
 */

function _ensureRateLimitTable(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query("SELECT 1 FROM `rate_limits` LIMIT 1");
        $ok = true;
    } catch (Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE `rate_limits` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ip` VARCHAR(45) NOT NULL,
                `action` VARCHAR(50) NOT NULL,
                `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_ip_action` (`ip`, `action`, `attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $ok = true;
        } catch (Throwable $e2) {
            $ok = false;
        }
    }
    return $ok;
}

function checkRateLimit(PDO $pdo, string $action, int $maxAttempts = 5, int $windowSeconds = 900): bool {
    if (!_ensureRateLimitTable($pdo)) return true; // graceful: skip if table unavailable

    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    try {
        $pdo->prepare("DELETE FROM `rate_limits` WHERE `action` = ? AND `attempted_at` < DATE_SUB(NOW(), INTERVAL ? SECOND)")
            ->execute([$action, $windowSeconds]);
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `rate_limits` WHERE `ip` = ? AND `action` = ? AND `attempted_at` > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $st->execute([$ip, $action, $windowSeconds]);
        $count = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return true; // graceful
    }

    if ($count >= $maxAttempts) {
        http_response_code(429);
        $retryAfter = $windowSeconds;
        header("Retry-After: $retryAfter");
        echo json_encode([
            'error' => 'muitas_tentativas',
            'mensagem' => "Muitas tentativas. Tente novamente em " . ceil($retryAfter / 60) . " minutos.",
            'retry_after' => $retryAfter,
        ]);
        exit;
    }

    return true;
}

// Trava por CONTA (e-mail ou código de acesso digitado), além da trava por IP: quem testa senhas de uma conta de vários IPs diferentes
// passa pela trava por IP, mas não por esta. A chave é guardada só como hash (nada de e-mail na tabela).
function _chaveConta(string $acao, string $chave): string { return 'k:' . substr(hash('sha256', $acao . '|' . strtolower(trim($chave))), 0, 42); }
function checkRateLimitConta(PDO $pdo, string $acao, string $chave, int $max = 8, int $janela = 900): void {
    if ($chave === '' || !_ensureRateLimitTable($pdo)) return;
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM `rate_limits` WHERE `ip` = ? AND `action` = ? AND `attempted_at` > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $st->execute([_chaveConta($acao, $chave), $acao, $janela]);
        if ((int)$st->fetchColumn() >= $max) {
            http_response_code(429);
            header('Retry-After: ' . $janela);
            echo json_encode(['error' => 'muitas_tentativas', 'mensagem' => 'Muitas tentativas nesta conta. Tente novamente em ' . ceil($janela / 60) . ' minutos.', 'retry_after' => $janela]);
            exit;
        }
    } catch (Throwable $e) {}
}
function recordAttemptConta(PDO $pdo, string $acao, string $chave): void {
    if ($chave === '' || !_ensureRateLimitTable($pdo)) return;
    try { $pdo->prepare("INSERT INTO `rate_limits` (`ip`, `action`) VALUES (?, ?)")->execute([_chaveConta($acao, $chave), $acao]); } catch (Throwable $e) {}
}
function clearAttemptsConta(PDO $pdo, string $acao, string $chave): void {
    if ($chave === '' || !_ensureRateLimitTable($pdo)) return;
    try { $pdo->prepare("DELETE FROM `rate_limits` WHERE `ip` = ? AND `action` = ?")->execute([_chaveConta($acao, $chave), $acao]); } catch (Throwable $e) {}
}

function recordAttempt(PDO $pdo, string $action): void {
    if (!_ensureRateLimitTable($pdo)) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $pdo->prepare("INSERT INTO `rate_limits` (`ip`, `action`) VALUES (?, ?)")
            ->execute([$ip, $action]);
    } catch (Throwable $e) {}
}

function clearAttempts(PDO $pdo, string $action): void {
    if (!_ensureRateLimitTable($pdo)) return;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $pdo->prepare("DELETE FROM `rate_limits` WHERE `ip` = ? AND `action` = ?")
            ->execute([$ip, $action]);
    } catch (Throwable $e) {}
}
