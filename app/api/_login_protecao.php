<?php
/**
 * PROTEÇÃO DO LOGIN DO ALUNO CONTRA FORÇA BRUTA ESPALHADA (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * As travas de antes (5 erros por IP e 8 por conta, em 15 min) não enxergam quem testa senhas de MUITOS IPs, com poucas tentativas em cada.
 * Aqui há um TETO GLOBAL: se as falhas de login de TODAS as IPs somadas passam do teto em 1 minuto (padrão 20), o login entra em
 * "modo de verificação" por 10 minutos:
 *   - com o Cloudflare Turnstile configurado: toda tentativa precisa trazer um token válido (o servidor confere no Cloudflare ANTES de olhar
 *     a conta). Quem é pessoa resolve o desafio (quase sempre invisível) e entra normalmente; robô não passa. Quem acerta a senha não é derrubado.
 *   - sem Turnstile (ou com o Cloudflare fora do ar): "atraso progressivo" que NÃO segura processo do servidor (a hospedagem tem 4 processos PHP,
 *     então sleep() daria a um atacante o poder de travar o site): os limites por IP e por conta ficam mais apertados (2 e 3 em vez de 5 e 8).
 *     Quem acerta de primeira continua entrando. É mais fraco que o Turnstile: só o Turnstile barra robô de verdade.
 *
 * ONDE FICAM AS CHAVES (nunca no código): tabela intus_secrets, chave `turnstile_config` (JSON site_key, secret_key, teto_falhas_min),
 * gravada em admin/turnstile-keys.php. As chaves vêm do painel do Cloudflare (Turnstile > Add widget); o Luiz as cola ali.
 *
 * Usa só a tabela rate_limits que já existe (linhas com `ip` = chave fixa), sem tabela nova. SQL portável de propósito (horário gravado e
 * comparado pelo PHP), para rodar igual em SQLite nos testes e no MySQL de produção. Qualquer falha aqui NÃO derruba o login (falha aberta).
 */
require_once __DIR__ . '/_ip.php';
require_once __DIR__ . '/_rate_limit.php';

const INTUS_LOGIN_ACAO_FALHA   = 'aluno_login_falha_global';
const INTUS_LOGIN_ACAO_PRESSAO = 'aluno_login_pressao';
const INTUS_LOGIN_CHAVE_GLOBAL = 'g:aluno_login';          // vai na coluna `ip` (VARCHAR 45) de rate_limits
const INTUS_LOGIN_TETO_PADRAO  = 20;                       // falhas por minuto, todas as IPs somadas
const INTUS_LOGIN_PRESSAO_SEG  = 600;                      // o modo de verificação dura 10 min depois do último estouro do teto
const INTUS_LOGIN_MAX_IP       = 5;                        // limites de sempre (aluno_login.php), em 15 min
const INTUS_LOGIN_MAX_CONTA    = 8;
const INTUS_LOGIN_MAX_IP_APERTADO    = 2;                  // limites no modo de verificação sem Turnstile
const INTUS_LOGIN_MAX_CONTA_APERTADO = 3;

function _loginAgora(?int $agora): int { return $agora ?? time(); }
function _loginHora(int $t): string { return date('Y-m-d H:i:s', $t); }

/** Conta até $limite linhas (a contagem para no limite, então nunca custa mais que isso mesmo sob ataque). */
function _loginContar(PDO $pdo, string $acao, string $desde, int $limite): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM (SELECT 1 FROM rate_limits WHERE ip = ? AND action = ? AND attempted_at > ? LIMIT ' . max(1, $limite) . ') t');
    $st->execute([INTUS_LOGIN_CHAVE_GLOBAL, $acao, $desde]);
    return (int)$st->fetchColumn();
}

/* ───────────────────────── Configuração (segredos no banco) ───────────────────────── */

function intusLoginSecretsDdl(PDO $pdo): void
{
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $pdo->exec('CREATE TABLE IF NOT EXISTS intus_secrets (chave TEXT PRIMARY KEY, valor TEXT NOT NULL)');
    else $pdo->exec('CREATE TABLE IF NOT EXISTS intus_secrets (chave VARCHAR(80) PRIMARY KEY, valor LONGTEXT NOT NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** ['site_key', 'secret_key', 'teto']. Sem linha ou com erro: chaves vazias e teto padrão. */
function intusLoginCfg(PDO $pdo): array
{
    $a = [];
    try {
        $st = $pdo->prepare('SELECT valor FROM intus_secrets WHERE chave = ? LIMIT 1'); $st->execute(['turnstile_config']);
        $v = $st->fetchColumn();
        $j = ($v === false || $v === null) ? null : json_decode((string)$v, true);
        if (is_array($j)) $a = $j;
    } catch (Throwable $e) {}
    $teto = (int)($a['teto_falhas_min'] ?? INTUS_LOGIN_TETO_PADRAO);
    if ($teto < 5 || $teto > 1000) $teto = INTUS_LOGIN_TETO_PADRAO;
    return ['site_key' => (string)($a['site_key'] ?? ''), 'secret_key' => (string)($a['secret_key'] ?? ''), 'teto' => $teto];
}
function intusLoginCfgGravar(PDO $pdo, array $cfg): void
{
    $teto = (int)($cfg['teto'] ?? INTUS_LOGIN_TETO_PADRAO);
    if ($teto < 5 || $teto > 1000) $teto = INTUS_LOGIN_TETO_PADRAO;
    $json = json_encode(['site_key' => (string)($cfg['site_key'] ?? ''), 'secret_key' => (string)($cfg['secret_key'] ?? ''), 'teto_falhas_min' => $teto], JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare('SELECT 1 FROM intus_secrets WHERE chave = ?'); $st->execute(['turnstile_config']);
    if ($st->fetchColumn()) $pdo->prepare('UPDATE intus_secrets SET valor = ? WHERE chave = ?')->execute([$json, 'turnstile_config']);
    else $pdo->prepare('INSERT INTO intus_secrets (chave, valor) VALUES (?, ?)')->execute(['turnstile_config', $json]);
}
function intusTurnstileAtivo(array $cfg): bool { return $cfg['site_key'] !== '' && $cfg['secret_key'] !== ''; }

/* ───────────────────────── Teto global ───────────────────────── */

/** O login está em modo de verificação (o teto estourou nos últimos 10 min)? Uma consulta curta; erro = não. */
function intusLoginSobPressao(PDO $pdo, ?int $agora = null): bool
{
    if (!_ensureRateLimitTable($pdo)) return false;
    try { return _loginContar($pdo, INTUS_LOGIN_ACAO_PRESSAO, _loginHora(_loginAgora($agora) - INTUS_LOGIN_PRESSAO_SEG), 1) > 0; }
    catch (Throwable $e) { return false; }
}

/**
 * Registra UMA falha de login (credenciais erradas) na conta global e, se passou do teto no último minuto, liga o modo de verificação.
 * Devolve true quando ESTA falha ligou (ou renovou) o modo. Nunca lança.
 */
function intusLoginFalhaRegistrar(PDO $pdo, ?int $agora = null): bool
{
    if (!_ensureRateLimitTable($pdo)) return false;
    $t = _loginAgora($agora);
    try {
        $ins = $pdo->prepare('INSERT INTO rate_limits (ip, action, attempted_at) VALUES (?, ?, ?)');
        $ins->execute([INTUS_LOGIN_CHAVE_GLOBAL, INTUS_LOGIN_ACAO_FALHA, _loginHora($t)]);
        // só guarda 5 min de falhas e 1 h de marcas (nada cresce sem fim)
        $pdo->prepare('DELETE FROM rate_limits WHERE ip = ? AND action = ? AND attempted_at < ?')->execute([INTUS_LOGIN_CHAVE_GLOBAL, INTUS_LOGIN_ACAO_FALHA, _loginHora($t - 300)]);
        $pdo->prepare('DELETE FROM rate_limits WHERE ip = ? AND action = ? AND attempted_at < ?')->execute([INTUS_LOGIN_CHAVE_GLOBAL, INTUS_LOGIN_ACAO_PRESSAO, _loginHora($t - 3600)]);

        $teto = intusLoginCfg($pdo)['teto'];
        if (_loginContar($pdo, INTUS_LOGIN_ACAO_FALHA, _loginHora($t - 60), $teto) < $teto) return false;
        // estourou: liga o modo (uma marca por minuto no máximo, para não encher a tabela durante um ataque)
        if (_loginContar($pdo, INTUS_LOGIN_ACAO_PRESSAO, _loginHora($t - 60), 1) > 0) return false;
        $ins->execute([INTUS_LOGIN_CHAVE_GLOBAL, INTUS_LOGIN_ACAO_PRESSAO, _loginHora($t)]);
        @error_log('[intus login] teto global de falhas atingido (' . $teto . ' por minuto): modo de verificação ligado por ' . (INTUS_LOGIN_PRESSAO_SEG / 60) . ' min');
        return true;
    } catch (Throwable $e) { return false; }
}

/** Para a tela do admin: falhas no último minuto, teto, e se o modo está ligado (e até quando). */
function intusLoginStatus(PDO $pdo, ?int $agora = null): array
{
    $t = _loginAgora($agora); $cfg = intusLoginCfg($pdo);
    $s = ['falhas_ultimo_minuto' => 0, 'teto' => $cfg['teto'], 'pressao' => false, 'pressao_ate' => null, 'turnstile' => intusTurnstileAtivo($cfg)];
    if (!_ensureRateLimitTable($pdo)) return $s;
    try {
        $s['falhas_ultimo_minuto'] = _loginContar($pdo, INTUS_LOGIN_ACAO_FALHA, _loginHora($t - 60), 1000);
        $st = $pdo->prepare('SELECT MAX(attempted_at) FROM rate_limits WHERE ip = ? AND action = ? AND attempted_at > ?');
        $st->execute([INTUS_LOGIN_CHAVE_GLOBAL, INTUS_LOGIN_ACAO_PRESSAO, _loginHora($t - INTUS_LOGIN_PRESSAO_SEG)]);
        $m = $st->fetchColumn();
        if ($m) { $s['pressao'] = true; $s['pressao_ate'] = _loginHora(strtotime((string)$m) + INTUS_LOGIN_PRESSAO_SEG); }
    } catch (Throwable $e) {}
    return $s;
}

/* ───────────────────────── Cloudflare Turnstile ───────────────────────── */

/**
 * Confere o token no Cloudflare. ['ok' => bool, 'indisponivel' => bool, 'codigos' => []].
 * indisponivel = não deu para perguntar ao Cloudflare (rede, timeout): quem chama NÃO deve derrubar todo mundo por isso.
 * Em teste, $GLOBALS['intusTurnstileHttpFake'] (callable: secret, token, ip) responde no lugar da internet e devolve [status, json].
 */
function intusTurnstileVerificar(string $secret, string $token, string $ip): array
{
    // Token de verdade é texto imprimível e curto (até ~2 KB): lixo nem chega ao Cloudflare (cada conferência segura um processo do servidor).
    if (!preg_match('/^[\x21-\x7e]{1,2048}$/', $token)) return ['ok' => false, 'indisponivel' => false, 'codigos' => ['formato']];
    try {
        if (!empty($GLOBALS['intusTurnstileHttpFake']) && is_callable($GLOBALS['intusTurnstileHttpFake'])) {
            [$st, $j] = call_user_func($GLOBALS['intusTurnstileHttpFake'], $secret, $token, $ip);
        } else {
            if (!function_exists('curl_init')) return ['ok' => false, 'indisponivel' => true, 'codigos' => ['sem_curl']];
            $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5,
                CURLOPT_POSTFIELDS => http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $ip])]);
            $r = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if ($r === false) return ['ok' => false, 'indisponivel' => true, 'codigos' => ['rede']];
            $j = json_decode((string)$r, true);
        }
    } catch (Throwable $e) { return ['ok' => false, 'indisponivel' => true, 'codigos' => ['erro']]; }
    if ($st >= 500 || !is_array($j)) return ['ok' => false, 'indisponivel' => true, 'codigos' => ['resposta']];
    $codigos = array_map('strval', (array)($j['error-codes'] ?? []));
    // chave secreta errada ou token fora do formato: problema de configuração, não de quem está entrando
    if (in_array('invalid-input-secret', $codigos, true) || in_array('missing-input-secret', $codigos, true)) return ['ok' => false, 'indisponivel' => true, 'codigos' => $codigos];
    return ['ok' => ($j['success'] ?? false) === true, 'indisponivel' => false, 'codigos' => $codigos];
}

/**
 * Estado do login para esta requisição. Em tempo normal custa uma consulta curta.
 * ['pressao', 'verificar' (exige token Turnstile), 'max_ip', 'max_conta', 'site_key', 'secret_key'].
 */
function intusLoginEstado(PDO $pdo, ?int $agora = null): array
{
    $e = ['pressao' => false, 'verificar' => false, 'max_ip' => INTUS_LOGIN_MAX_IP, 'max_conta' => INTUS_LOGIN_MAX_CONTA, 'site_key' => '', 'secret_key' => ''];
    if (!intusLoginSobPressao($pdo, $agora)) return $e;
    $cfg = intusLoginCfg($pdo);
    $e['pressao'] = true; $e['site_key'] = $cfg['site_key']; $e['secret_key'] = $cfg['secret_key'];
    if (intusTurnstileAtivo($cfg)) { $e['verificar'] = true; return $e; }
    $e['max_ip'] = INTUS_LOGIN_MAX_IP_APERTADO; $e['max_conta'] = INTUS_LOGIN_MAX_CONTA_APERTADO;   // sem Turnstile: atraso progressivo sem segurar processo
    return $e;
}

/**
 * Em modo de verificação com Turnstile: confere o token. Devolve o estado (possivelmente apertado) e 'acao':
 *   'seguir'   token válido, ou não há o que verificar (modo normal / sem Turnstile)
 *   'desafiar' sem token ou token recusado: quem chama responde intusLoginRespostaDesafio() e sai
 *   (o Cloudflare fora do ar vira 'seguir' com os limites apertados: nunca derruba todo mundo por falha do serviço)
 * 'token_recusado' diz se veio um token e ele foi recusado (quem chama conta isso na trava por IP).
 */
function intusLoginVerificar(array $estado, string $token): array
{
    $estado['acao'] = 'seguir'; $estado['token_recusado'] = false;
    if (!$estado['verificar']) return $estado;
    if ($token === '') { $estado['acao'] = 'desafiar'; return $estado; }
    $v = intusTurnstileVerificar($estado['secret_key'], $token, intusClientIp());
    if ($v['ok']) return $estado;
    if ($v['indisponivel']) {
        @error_log('[intus login] Turnstile indisponivel (' . implode(',', $v['codigos']) . '): limites apertados no lugar do desafio');
        $estado['verificar'] = false; $estado['max_ip'] = INTUS_LOGIN_MAX_IP_APERTADO; $estado['max_conta'] = INTUS_LOGIN_MAX_CONTA_APERTADO;
        return $estado;
    }
    $estado['acao'] = 'desafiar'; $estado['token_recusado'] = true;
    return $estado;
}

/** Resposta (HTTP 429) que pede o desafio: a tela de login mostra o widget com esta chave pública e reenvia com turnstile_token. */
function intusLoginRespostaDesafio(array $estado): array
{
    return [429, [
        'ok' => false,
        'codigo' => 'verificacao_necessaria',
        'erro' => 'Por segurança, confirme que você é uma pessoa e toque em Entrar de novo.',
        'verificacao' => ['tipo' => 'turnstile', 'site_key' => $estado['site_key']],
    ]];
}
