<?php
/**
 * REGISTRO DE ACESSOS (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Tabela nova `intus_acesso` (criada aqui na primeira chamada; só recebe linhas, nunca altera nem apaga dado de aluno; autorizada pelo Luiz em 14/10/2026).
 * Cada linha é um acontecimento de uma pessoa: login, abertura do app/painel (no máximo 1 a cada 20 min), treino ou cardio registrado.
 *   criado_em   em UTC (gmdate). O painel converte para o horário de Brasília e para o fuso do aluno.
 *   ip          IP real (intusClientIp: com Cloudflare, o do visitante).
 *   pais/regiao/cidade/fuso_cf   vindos do Cloudflare (_ip.php, intusClientGeo); vazios sem Cloudflare.
 *   fuso_local  fuso do aparelho do aluno (ex.: America/Sao_Paulo), informado pelo app; usado para mostrar "horário local" quando difere de Brasília.
 *
 * intusRegistrarAcesso($pdo, 'aluno'|'staff', $id, $nome, $evento, $ref = null, $fusoLocal = '')   nunca lança erro (falha em silêncio).
 * Login de ADMINISTRADOR vindo de país diferente dos anteriores manda um aviso por e-mail (intusAcessoAlertar), sem exigir nada do usuário.
 */

function intusAcessoGarantirTabela(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        try { $pdo->query('SELECT 1 FROM intus_acesso LIMIT 1'); return $ok = true; } catch (Throwable $e) { /* ainda nao existe: cria abaixo */ }
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_acesso (
            idacesso   BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_tipo  VARCHAR(10)  NOT NULL,
            user_id    INT          NOT NULL,
            user_nome  VARCHAR(120) NULL,
            evento     VARCHAR(20)  NOT NULL,
            ref_id     INT          NULL,
            ip         VARCHAR(45)  NULL,
            pais       VARCHAR(2)   NULL,
            regiao     VARCHAR(80)  NULL,
            cidade     VARCHAR(80)  NULL,
            fuso_cf    VARCHAR(60)  NULL,
            fuso_local VARCHAR(60)  NULL,
            aparelho   VARCHAR(160) NULL,
            criado_em  DATETIME     NOT NULL,
            INDEX idx_pessoa (user_tipo, user_id, criado_em),
            INDEX idx_data (criado_em),
            INDEX idx_evento (evento, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}

/** Fuso (nome IANA) válido? Evita guardar lixo digitado por quem chama a API. */
function intusFusoValido(string $f): bool
{
    return $f !== '' && strlen($f) <= 60 && preg_match('#^[A-Za-z_]+(?:/[A-Za-z0-9_+\-]+){0,2}$#', $f) && in_array($f, timezone_identifiers_list(), true);
}

/** "Chrome em Android" etc., curto, sem o texto inteiro do navegador. */
function intusAparelhoResumo(string $ua): string
{
    if ($ua === '') return '';
    $so = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad|iPod/i', $ua) ? 'iOS' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'macOS' : (preg_match('/Linux/i', $ua) ? 'Linux' : ''))));
    $nav = preg_match('/OPR\/|Opera/i', $ua) ? 'Opera' : (preg_match('/Edg\//i', $ua) ? 'Edge' : (preg_match('/Firefox\//i', $ua) ? 'Firefox' : (preg_match('/Chrome\//i', $ua) ? 'Chrome' : (preg_match('/Safari\//i', $ua) ? 'Safari' : ''))));
    $app = preg_match('/; wv\)|Capacitor/i', $ua) ? 'app' : '';
    return trim(($app !== '' ? 'App ' : '') . ($nav !== '' && $app === '' ? $nav . ' ' : '') . ($so !== '' ? 'em ' . $so : ''));
}

function intusRegistrarAcesso(PDO $pdo, string $tipo, int $id, string $nome, string $evento, ?int $ref = null, string $fusoLocal = ''): void
{
    try {
        if ($id <= 0 || !intusAcessoGarantirTabela($pdo)) return;
        $tipo = $tipo === 'aluno' ? 'aluno' : 'staff';
        $agora = gmdate('Y-m-d H:i:s');
        if ($evento === 'abertura') {   // no máximo uma abertura a cada 20 min por pessoa
            $st = $pdo->prepare("SELECT 1 FROM intus_acesso WHERE user_tipo = ? AND user_id = ? AND evento = 'abertura' AND criado_em > ? LIMIT 1");
            $st->execute([$tipo, $id, gmdate('Y-m-d H:i:s', time() - 1200)]);
            if ($st->fetchColumn()) return;
        }
        $fusoLocal = intusFusoValido($fusoLocal) ? $fusoLocal : '';
        if ($fusoLocal === '' && $tipo === 'aluno') {   // treino e cardio herdam o último fuso informado pelo aparelho do aluno
            $st = $pdo->prepare("SELECT fuso_local FROM intus_acesso WHERE user_tipo = 'aluno' AND user_id = ? AND fuso_local IS NOT NULL AND fuso_local <> '' ORDER BY idacesso DESC LIMIT 1");
            $st->execute([$id]);
            $fusoLocal = (string)$st->fetchColumn();
        }
        $g = intusClientGeo();
        $ip = substr(intusClientIp(), 0, 45);
        $pdo->prepare("INSERT INTO intus_acesso (user_tipo, user_id, user_nome, evento, ref_id, ip, pais, regiao, cidade, fuso_cf, fuso_local, aparelho, criado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tipo, $id, mb_substr($nome, 0, 120), substr($evento, 0, 20), $ref, $ip, $g['pais'] ?: null, $g['regiao'] ?: null, $g['cidade'] ?: null, $g['fuso'] ?: null,
                       $fusoLocal ?: null, substr(intusAparelhoResumo((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 160) ?: null, $agora]);
    } catch (Throwable $e) { /* o registro de acesso nunca derruba o login nem o treino */ }
}

/**
 * Login de administrador vindo de um PAÍS que ele não usava nos últimos 90 dias: avisa por e-mail (para o e-mail do aviso de backup, ou o do SMTP).
 * Só funciona com o Cloudflare ligado (é ele que informa o país). Chamado depois do login e não bloqueia nada.
 */
function intusAcessoAlertar(PDO $pdo, int $id, string $nome): void
{
    try {
        $g = intusClientGeo();
        if ($g['pais'] === '' || !intusAcessoGarantirTabela($pdo)) return;
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_acesso WHERE user_tipo = 'staff' AND user_id = ? AND evento = 'login' AND criado_em > ?");
        $st->execute([$id, gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
        if ((int)$st->fetchColumn() < 2) return;   // ainda não há histórico para comparar (o login de agora já foi gravado)
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_acesso WHERE user_tipo = 'staff' AND user_id = ? AND evento = 'login' AND pais = ? AND criado_em > ?");
        $st->execute([$id, $g['pais'], gmdate('Y-m-d H:i:s', time() - 90 * 86400)]);
        if ((int)$st->fetchColumn() > 1) return;   // este país já aparecia antes (a linha de agora conta 1)
        require_once __DIR__ . '/_smtp_seguro.php';
        require_once __DIR__ . '/_backup_admin.php';
        $smtp = @include __DIR__ . '/../config/smtp.php';
        $para = _backupEmailConfigurado() ?: (is_array($smtp) ? (string)($smtp['user'] ?? '') : '');
        if ($para === '') return;
        intusEnviarEmailSimples($para, 'Novo país no acesso de ' . $nome . ' - Intus Fit',
            "O administrador {$nome} entrou no painel de um país que não aparecia nos últimos 90 dias.\n\nPaís: {$g['pais']}\nCidade: {$g['cidade']} {$g['regiao']}\nIP: " . intusClientIp() . "\nHorário (UTC): " . gmdate('d/m/Y H:i:s') . "\n\nSe foi você, ignore. Se não foi, troque a senha e encerre as sessões em Configurações > Sessões.");
    } catch (Throwable $e) {}
}
