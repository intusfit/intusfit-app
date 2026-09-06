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
