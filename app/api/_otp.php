<?php
/**
 * Intus Fit — OTP (One-Time Password) for password reset.
 *
 * Gera código de 6 dígitos, armazena hash no banco, expira em 10 minutos.
 * Máximo 3 tentativas de verificação por código. Rate-limited a 3 envios/hora por email.
 *
 * Tabela: intus_otp (auto-criada)
 */

function _ensureOtpTable(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query("SELECT 1 FROM `intus_otp` LIMIT 1");
        $ok = true;
    } catch (Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE `intus_otp` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `email` VARCHAR(200) NOT NULL,
                `code_hash` VARCHAR(64) NOT NULL,
                `attempts` TINYINT NOT NULL DEFAULT 0,
                `verified` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                `ip` VARCHAR(45) NULL,
                INDEX `idx_email` (`email`),
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
 * Gera e armazena um OTP para o email. Retorna o código plaintext (6 dígitos) ou null.
 * Rate-limit: máximo 3 envios por hora por email.
 */
function createOtp(PDO $pdo, string $email, int $ttlMinutes = 10): ?string {
    if (!_ensureOtpTable($pdo)) return null;
    $email = strtolower(trim($email));

    // Rate-limit: max 3 OTPs per hour per email
    $st = $pdo->prepare("SELECT COUNT(*) FROM `intus_otp` WHERE `email` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $st->execute([$email]);
    if ((int)$st->fetchColumn() >= 3) return null;

    // Invalidate previous OTPs for this email
    $pdo->prepare("DELETE FROM `intus_otp` WHERE `email` = ?")->execute([$email]);

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $hash = hash('sha256', $code . $email);
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    try {
        $st = $pdo->prepare("INSERT INTO `intus_otp` (`email`, `code_hash`, `expires_at`, `ip`) VALUES (?, ?, ?, ?)");
        $st->execute([$email, $hash, $expiresAt, $ip]);
    } catch (Throwable $e) {
        return null;
    }

    return $code;
}

/**
 * Verifica um OTP. Retorna true se válido, false caso contrário.
 * Máximo 3 tentativas por código. Após verificação bem-sucedida, marca como verified.
 */
function verifyOtp(PDO $pdo, string $email, string $code): bool {
    if (!_ensureOtpTable($pdo)) return false;
    $email = strtolower(trim($email));
    $hash = hash('sha256', $code . $email);

    $st = $pdo->prepare("SELECT * FROM `intus_otp` WHERE `email` = ? AND `expires_at` > NOW() AND `verified` = 0 ORDER BY `id` DESC LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) return false;

    // Max 3 attempts
    if ((int)$row['attempts'] >= 3) {
        $pdo->prepare("DELETE FROM `intus_otp` WHERE `id` = ?")->execute([$row['id']]);
        return false;
    }

    // Increment attempts
    $pdo->prepare("UPDATE `intus_otp` SET `attempts` = `attempts` + 1 WHERE `id` = ?")->execute([$row['id']]);

    if (!hash_equals($row['code_hash'], $hash)) {
        return false;
    }

    // Mark as verified (single-use)
    $pdo->prepare("UPDATE `intus_otp` SET `verified` = 1 WHERE `id` = ?")->execute([$row['id']]);
    return true;
}

/**
 * Verifica se há um OTP verificado (para permitir reset de senha).
 * Consome o OTP (delete) após uso.
 */
function consumeVerifiedOtp(PDO $pdo, string $email): bool {
    if (!_ensureOtpTable($pdo)) return false;
    $email = strtolower(trim($email));

    $st = $pdo->prepare("SELECT `id` FROM `intus_otp` WHERE `email` = ? AND `verified` = 1 AND `expires_at` > NOW() LIMIT 1");
    $st->execute([$email]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) return false;

    // Consume (delete) the OTP
    $pdo->prepare("DELETE FROM `intus_otp` WHERE `id` = ?")->execute([$row['id']]);
    return true;
}

/**
 * Limpeza de OTPs expirados.
 */
function cleanExpiredOtps(PDO $pdo): void {
    if (!_ensureOtpTable($pdo)) return;
    try {
        $pdo->exec("DELETE FROM `intus_otp` WHERE `expires_at` < NOW()");
    } catch (Throwable $e) {}
}
