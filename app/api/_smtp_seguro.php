<?php
/**
 * CONEXÃO SEGURA COM O SERVIDOR DE E-MAIL (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Por que existe
 * --------------
 * Os e-mails do app (código de acesso, redefinição de senha, aviso de backup) abriam a conexão SMTP SEM conferir o certificado do servidor,
 * e a senha do e-mail viaja logo depois. Quem estivesse no caminho podia se passar pelo servidor e capturar a senha.
 *
 * Achado em 14/10/2026 ao diagnosticar o "Testar o e-mail" do painel de backups: smtp.intusfit.com.br é só um apelido (CNAME) de
 * smtp-vip.kinghost.net, e o certificado desse servidor é *.kinghost.net (GlobalSign, válido até 04/2027). O nome "smtp.intusfit.com.br"
 * nunca vai bater com ele, então conferir o nome contra o apelido SEMPRE falha (erro 0). O certo é conferir contra o nome REAL do servidor
 * (o destino do CNAME), mantendo a conferência da cadeia do certificado. É isso que intusSmtpAbrir() faz.
 *
 * Modo estrito: com 'smtp_estrito' ligado (Configurações > Servidor > Backups, depois de um teste de e-mail bem-sucedido), uma conexão
 * que não passa na verificação NÃO acontece (o e-mail não sai pelo SMTP em vez de expor a senha). Desligado, o código tenta verificado
 * primeiro e só cai para sem verificação se a verificação falhar (comportamento antigo), anotando o motivo em config/email_erros.log.
 */

/** Texto dos erros do OpenSSL acumulados na última tentativa. */
function _smtpOpensslErro(): string
{
    $m = '';
    while (($e = openssl_error_string()) !== false) { $m .= ' [' . $e . ']'; }
    return $m;
}

/** O modo estrito está ligado? (guardado em intus_secrets.backup_config, junto do e-mail do aviso de backup) */
function intusSmtpEstrito(): bool
{
    static $v = null;
    if ($v !== null) return $v;
    $v = false;
    try {
        require_once __DIR__ . '/_gdrive.php';
        $pdo = _gdriveConn();
        if ($pdo) {
            _gdriveEnsureTable($pdo);
            $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'backup_config' LIMIT 1");
            $st->execute();
            $j = json_decode((string)$st->fetchColumn(), true);
            $v = is_array($j) && !empty($j['smtp_estrito']);
        }
    } catch (Throwable $e) {}
    return $v;
}

/** Nomes que o certificado pode ter: o destino do CNAME (nome real do servidor) e o próprio nome configurado. */
function _smtpNomesPossiveis(string $host): array
{
    $nomes = [];
    if (function_exists('dns_get_record')) {
        $reg = @dns_get_record($host, DNS_CNAME);
        if (is_array($reg)) foreach ($reg as $r) { if (!empty($r['target'])) $nomes[] = rtrim((string)$r['target'], '.'); }
    }
    $nomes[] = $host;
    return array_values(array_unique($nomes));
}

/** O que o servidor apresenta quando NÃO conferimos nada (só para explicar o erro ao administrador). */
function intusSmtpDiagnosticoCert(string $host): string
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true, 'SNI_enabled' => true]]);
    $s = @stream_socket_client("ssl://{$host}:465", $en, $es, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) return "Nem sem conferir o certificado consegui abrir a porta 465 ({$en} {$es}): a porta pode estar bloqueada neste servidor ou o servidor de e-mail está fora do ar.";
    $p = stream_context_get_params($s);
    @fclose($s);
    $c = $p['options']['ssl']['peer_certificate'] ?? null;
    $i = $c ? @openssl_x509_parse($c) : null;
    if (!is_array($i)) return 'O servidor atende, mas não consegui ler o certificado dele.';
    $nomes = (string)($i['extensions']['subjectAltName'] ?? ($i['subject']['CN'] ?? '?'));
    return 'O servidor atende, mas o certificado dele é para: ' . str_replace('DNS:', '', $nomes)
        . ' (emitido por ' . ($i['issuer']['O'] ?? '?') . ', válido até ' . date('d/m/Y', (int)($i['validTo_time_t'] ?? 0)) . ').';
}

/**
 * Abre a conexão com o servidor de e-mail e devolve o socket JÁ DEPOIS da saudação do servidor (a conversa começa no EHLO), ou null.
 * Tenta SSL direto na 465 e, se a porta não abrir, STARTTLS na 587. $info: 'nome' (nome conferido), 'porta', 'verificado', 'erro'.
 */
function intusSmtpAbrir(string $host, ?array &$info = null, int $timeout = 12, ?bool $estrito = null)
{
    if ($estrito === null) $estrito = intusSmtpEstrito();
    $info = ['host' => $host, 'nome' => null, 'porta' => null, 'verificado' => false, 'erro' => '', 'detalhes' => []];
    $ler = function ($s) { $r = ''; while (($l = fgets($s, 1024)) !== false) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };

    foreach (_smtpNomesPossiveis($host) as $nome) {
        $opt = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $nome]];
        $s = @stream_socket_client("ssl://{$host}:465", $en, $es, $timeout, STREAM_CLIENT_CONNECT, stream_context_create($opt));
        if ($s) { stream_set_timeout($s, 30); $ler($s); $info['nome'] = $nome; $info['porta'] = 465; $info['verificado'] = true; return $s; }
        $info['detalhes'][] = "465 conferindo o nome {$nome}: {$en} {$es}" . _smtpOpensslErro();

        $s = @stream_socket_client("tcp://{$host}:587", $en2, $es2, $timeout, STREAM_CLIENT_CONNECT, stream_context_create($opt));
        if ($s) {
            stream_set_timeout($s, 30);
            $ler($s);
            fwrite($s, "EHLO intusfit.com.br\r\n"); $ler($s);
            fwrite($s, "STARTTLS\r\n"); $r = $ler($s);
            if (substr(trim($r), 0, 3) === '220') {
                stream_context_set_option($s, $opt);
                if (@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    $info['nome'] = $nome; $info['porta'] = 587; $info['verificado'] = true; return $s;
                }
                $info['detalhes'][] = "587 STARTTLS conferindo o nome {$nome}:" . _smtpOpensslErro();
            } else {
                $info['detalhes'][] = '587: o servidor não aceitou STARTTLS (' . trim(substr($r, 0, 80)) . ')';
            }
            @fclose($s);
        } else {
            $info['detalhes'][] = "587: {$en2} {$es2}";
        }
    }

    if (!$estrito) {
        // Comportamento antigo (sem conferir o certificado): só quando o modo estrito está desligado.
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true]]);
        $s = @stream_socket_client("ssl://{$host}:465", $en, $es, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($s) {
            stream_set_timeout($s, 30); $ler($s);
            $info['nome'] = $host; $info['porta'] = 465; $info['verificado'] = false;
            @file_put_contents(__DIR__ . '/../config/email_erros.log', date('c') . " | SMTP aberto SEM conferir o certificado (modo estrito desligado): " . implode(' | ', $info['detalhes']) . "\n", FILE_APPEND);
            return $s;
        }
        $info['detalhes'][] = "465 sem conferir: {$en} {$es}";
    }

    $info['erro'] = 'Não consegui uma conexão segura com ' . $host . '. ' . intusSmtpDiagnosticoCert($host) . ' Detalhes: ' . implode(' | ', $info['detalhes']);
    return null;
}
