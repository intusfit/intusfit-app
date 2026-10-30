<?php
/** Funções do bilhete de entrada no CDD (usadas por cdd_sso.php e pelos testes). Biblioteca interna: o "_" no nome bloqueia o acesso direto. */

const CDD_SSO_DESTINO = 'https://codigodadefinicao.com.br/app/painel';
const CDD_SSO_VALIDADE = 60;

/** Bilhete assinado. Devolve a string "<corpo>.<assinatura>" (ambos base64 sem padding, seguros para URL). */
function cddSsoMontar(string $segredo, string $email, string $nome, int $idusuario, ?int $agora = null): string {
    $b64 = function (string $s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };
    $corpo = $b64(json_encode(['e' => $email, 'n' => $nome, 'i' => $idusuario, 'x' => ($agora ?? time()) + CDD_SSO_VALIDADE, 'r' => bin2hex(random_bytes(12))], JSON_UNESCAPED_UNICODE));
    return $corpo . '.' . $b64(hash_hmac('sha256', $corpo, $segredo, true));
}

/** Lê (ou cria na primeira vez) o segredo. */
function cddSsoSegredo(PDO $pdo): string {
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_secrets (chave VARCHAR(80) PRIMARY KEY, valor LONGTEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'cdd_sso' LIMIT 1"); $st->execute();
    $seg = (string)$st->fetchColumn();
    if (strlen($seg) < 32) {
        $seg = bin2hex(random_bytes(32));
        $pdo->prepare("DELETE FROM intus_secrets WHERE chave = 'cdd_sso'")->execute();
        $pdo->prepare("INSERT INTO intus_secrets (chave, valor) VALUES ('cdd_sso', ?)")->execute([$seg]);
    }
    // Cópia do lado do CDD (mesmo banco por enquanto). Só grava se as tabelas do CDD já existem e a cópia estiver diferente.
    try {
        $c = $pdo->prepare("SELECT valor FROM cdd_config WHERE chave = 'sso_segredo'"); $c->execute();
        $atual = $c->fetchColumn();
        if ($atual === false) $pdo->prepare("INSERT INTO cdd_config (chave, valor) VALUES ('sso_segredo', ?)")->execute([$seg]);
        elseif ($atual !== $seg) $pdo->prepare("UPDATE cdd_config SET valor = ? WHERE chave = 'sso_segredo'")->execute([$seg]);
    } catch (Throwable $e) { /* CDD em outro banco ou ainda sem tabelas: o segredo é copiado à mão nesse caso */ }
    return $seg;
}
