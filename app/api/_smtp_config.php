<?php
/**
 * CONFIGURAÇÃO DO SERVIDOR DE E-MAIL (SMTP) (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Antes, host, usuário e SENHA moravam em app/config/smtp.php, um arquivo rastreado no git (a senha ficou no histórico) e que não pode sair do
 * repositório sem o deploy apagar a cópia do servidor. Agora o valor de verdade mora no BANCO (tabela intus_secrets, chave `smtp_config`,
 * como a chave do Drive e as do WhatsApp) e é editado em admin/smtp-config.php, que nunca mostra a senha de volta.
 *
 * intusSmtpConfig()        array com host, port, user, pass, from_name e extras (notificar, verify_tls). O banco manda; o arquivo antigo
 *                          só completa o que faltar no banco (assim a troca é sem queda: enquanto o banco estiver vazio tudo continua como era).
 * intusSmtpConfigOrigem()  'banco' | 'arquivo' | 'nenhum', para o painel mostrar de onde vem.
 */

function _smtpConfigLer(bool $recarregar = false): array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) return $cache;
    $arq = @include __DIR__ . '/../config/smtp.php';
    $arq = is_array($arq) ? $arq : [];
    $banco = [];
    try {
        require_once __DIR__ . '/_gdrive.php';
        $pdo = _gdriveConn();
        if ($pdo) {
            _gdriveEnsureTable($pdo);
            $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'smtp_config' LIMIT 1");
            $st->execute();
            $j = json_decode((string)$st->fetchColumn(), true);
            if (is_array($j) && !empty($j['host']) && !empty($j['user']) && !empty($j['pass'])) $banco = $j;
        }
    } catch (Throwable $e) {}
    $origem = $banco ? 'banco' : (($arq && !empty($arq['pass'])) ? 'arquivo' : 'nenhum');
    $cfg = array_merge($arq, $banco);
    return $cache = ['cfg' => $cfg, 'origem' => $origem, 'arquivo' => $arq];
}

function intusSmtpConfig(bool $recarregar = false): array
{
    return _smtpConfigLer($recarregar)['cfg'];
}

function intusSmtpConfigOrigem(): string
{
    return _smtpConfigLer()['origem'];
}

/** O que o arquivo antigo tem (usado só pela tela do admin para importar sem digitar a senha). */
function intusSmtpConfigArquivo(): array
{
    return _smtpConfigLer()['arquivo'];
}
