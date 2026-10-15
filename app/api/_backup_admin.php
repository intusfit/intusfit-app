<?php
require_once __DIR__ . '/_smtp_config.php';   // intusSmtpConfig(): SMTP guardado no banco (admin/smtp-config.php)
/**
 * PAINEL DE BACKUPS (só administrador) — incluído por catalogo.php, nunca chamado direto (o "_" no nome faz o .htaccess bloquear).
 *
 * O que faz:
 *   backupStatus()        quando foi o último backup, se deu certo, o que o e-mail disse, quais cópias existem no Drive e onde o banco está
 *   backupConferir()      baixa a cópia mais recente do banco no Drive e confere que ela está inteira (gzip válido, estrutura e dados completos)
 *   backupEmailTeste()    manda um e-mail de teste e devolve o erro EXATO do servidor de e-mail, se houver
 *   backupRodarAgora()    confere o que o backup precisa e, se estiver tudo certo, dispara um backup completo agora (o mesmo script diário)
 *   backupProgresso()     andamento do backup (barra do painel), gravado pelo cron-backup.php
 * Nada aqui apaga ou altera dado de aluno. O e-mail de destino fica em intus_secrets ("backup_config").
 */

function _backupPastaConfig(): string { return __DIR__ . '/../config'; }

function _backupEmailConfigurado(): string {
    try {
        require_once __DIR__ . '/_gdrive.php';
        $pdo = _gdriveConn(); if (!$pdo) return '';
        _gdriveEnsureTable($pdo);
        $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'backup_config' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        $j = $v ? json_decode((string)$v, true) : null;
        return is_array($j) ? trim((string)($j['email'] ?? '')) : '';
    } catch (Throwable $e) { return ''; }
}

function backupSalvarEmail(string $email): bool {
    $email = trim($email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    try {
        require_once __DIR__ . '/_gdrive.php';
        $pdo = _gdriveConn(); if (!$pdo) return false;
        _gdriveEnsureTable($pdo);
        $json = json_encode(['email' => $email]);
        $st = $pdo->prepare("SELECT 1 FROM intus_secrets WHERE chave = 'backup_config' LIMIT 1");
        $st->execute();
        if ($st->fetchColumn()) $pdo->prepare("UPDATE intus_secrets SET valor = ? WHERE chave = 'backup_config'")->execute([$json]);
        else $pdo->prepare("INSERT INTO intus_secrets (chave, valor) VALUES ('backup_config', ?)")->execute([$json]);
        return true;
    } catch (Throwable $e) { return false; }
}

function _backupTail(string $arq, int $n = 80): array {
    if (!is_file($arq)) return [];
    $tam = filesize($arq); if ($tam === 0) return [];
    $f = @fopen($arq, 'rb'); if (!$f) return [];
    fseek($f, max(0, $tam - 24000));
    $txt = (string)stream_get_contents($f); fclose($f);
    $linhas = array_values(array_filter(explode("\n", str_replace("\r", '', $txt)), fn($l) => trim($l) !== ''));
    return array_slice($linhas, -$n);
}

function _backupDriveListar(): array {
    require_once __DIR__ . '/_gdrive.php';
    $key = _gdriveKey();
    if (!is_array($key) || empty($key['folder_id'])) return ['erro' => 'O Drive não está configurado.', 'arquivos' => []];
    $token = _gdriveToken($key);
    if (!$token) return ['erro' => 'Não consegui entrar no Drive (chave do Drive).', 'arquivos' => []];
    $q = rawurlencode("'{$key['folder_id']}' in parents and trashed = false and name contains 'intus-backup-'");
    $url = "https://www.googleapis.com/drive/v3/files?q={$q}&fields=files(id,name,size,createdTime)&orderBy=createdTime%20desc&pageSize=60&supportsAllDrives=true&includeItemsFromAllDrives=true";
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token], CURLOPT_TIMEOUT => 25]);
    $resp = curl_exec($ch); $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$resp, true);
    if ($cod !== 200 || !is_array($j)) return ['erro' => 'O Drive não respondeu a lista de cópias (HTTP ' . $cod . ').', 'arquivos' => []];
    $out = [];
    foreach (($j['files'] ?? []) as $f) {
        $nome = (string)($f['name'] ?? '');
        $tipo = strpos($nome, 'intus-backup-db-') === 0 ? 'banco' : (strpos($nome, 'intus-backup-') === 0 ? 'arquivos' : 'outro');
        $out[] = ['id' => $f['id'] ?? '', 'nome' => $nome, 'tipo' => $tipo, 'bytes' => (int)($f['size'] ?? 0), 'criado' => $f['createdTime'] ?? null];
    }
    return ['erro' => null, 'arquivos' => $out];
}

function backupStatus(PDO $pdo, bool $completo = true): array {
    $cfg = _backupPastaConfig();
    $linhas = _backupTail($cfg . '/backup.log', 120);
    $st = [
        'hoje'            => date('Y-m-d'),
        'marca_dia'       => is_file($cfg . '/backup-ultimo.txt') ? trim((string)@file_get_contents($cfg . '/backup-ultimo.txt')) : null,
        'tem_cron'        => is_file($cfg . '/cron.php'),
        'ultimo_fim'      => null, 'ultimo_fim_ok' => null, 'ultimo_banco_ok' => null,
        'db_host'         => null, 'db_nome' => null, 'usa_maximizeapp' => false,
        'email'           => ['destino_configurado' => _backupEmailConfigurado(), 'ultimo_resultado' => null, 'ultimo_em' => null],
        'tabelas_ultimo'  => [],
        'log'             => array_slice($linhas, -30),
    ];
    $dumpTabelas = [];
    foreach ($linhas as $l) {
        if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] (.*)$/', $l, $m)) continue;
        [$quando, $msg] = [$m[1], $m[2]];
        if (preg_match('/^Banco conectado: (.+?) em (.+)$/', $msg, $b)) { $st['db_nome'] = $b[1]; $st['db_host'] = $b[2]; $dumpTabelas = []; }
        if (preg_match('/^\s*tabela (\S+): (\d+) linhas/', $msg, $t)) $dumpTabelas[$t[1]] = (int)$t[2];
        if (strpos($msg, 'Banco enviado ao Drive') === 0) $st['ultimo_banco_ok'] = $quando;
        if (preg_match('/^FIM: (\d+) arquivo\(s\) no Drive, (\d+) falha/', $msg, $f)) { $st['ultimo_fim'] = $quando; $st['ultimo_fim_ok'] = ((int)$f[2] === 0); }
        if (strpos($msg, 'Aviso de backup enviado para') === 0 || strpos($msg, 'AVISO: SMTP') === 0 || strpos($msg, 'AVISO: o servidor recusou') === 0 || strpos($msg, 'AVISO: o e-mail do backup') === 0 || strpos($msg, 'E-mail do backup desligado') === 0) {
            $st['email']['ultimo_resultado'] = $msg; $st['email']['ultimo_em'] = $quando;
        }
    }
    $st['tabelas_ultimo'] = $dumpTabelas;
    $st['fastcgi']   = function_exists('fastcgi_finish_request');
    $st['progresso'] = backupProgresso();
    $st['smtp_estrito'] = _backupSmtpEstritoLigado();
    $st['rotinas'] = (function () { $a = __DIR__ . '/../config/rotinas.json'; $j = is_file($a) ? json_decode((string)@file_get_contents($a), true) : null; return is_array($j) ? array_intersect_key($j, array_flip(['verificacao_em', 'verificacao_ok', 'verificacao_etapas', 'cert_dias', 'resumo_em', 'ultima_rodada'])) : null; })();
    $st['usa_maximizeapp'] = $st['db_host'] ? (stripos($st['db_host'], 'maximizeapp') !== false) : false;
    $st['smtp_host'] = null;
    $smtp = intusSmtpConfig();
    if (is_array($smtp)) { $st['smtp_host'] = (string)($smtp['host'] ?? ''); $st['smtp_user'] = (string)($smtp['user'] ?? ''); }
    $st['smtp_usa_maximizeapp'] = $st['smtp_host'] ? (stripos($st['smtp_host'] . ($st['smtp_user'] ?? ''), 'maximizeapp') !== false) : false;

    // Dias desde o último backup que terminou bem (o aviso do painel usa isto)
    $ref = $st['ultimo_banco_ok'] ?: ($st['ultimo_fim_ok'] ? $st['ultimo_fim'] : null);
    $st['dias_sem_backup'] = $ref ? (int)floor((time() - strtotime($ref)) / 86400) : null;
    $st['nivel'] = $ref === null ? 'critico' : ($st['dias_sem_backup'] >= 3 ? 'critico' : ($st['dias_sem_backup'] >= 2 ? 'alerta' : 'ok'));
    if ($ref !== null && $st['ultimo_fim_ok'] === false && $st['nivel'] === 'ok') $st['nivel'] = 'alerta';

    if ($completo) {
        $d = _backupDriveListar();
        $st['drive'] = $d;
        // contagem ao vivo para comparar com o que o último backup guardou
        $vivas = [];
        try {
            foreach ($st['tabelas_ultimo'] as $tab => $n) {
                if (!preg_match('/^[A-Za-z0-9_]+$/', $tab)) continue;
                $vivas[$tab] = (int)$pdo->query("SELECT COUNT(*) FROM `$tab`")->fetchColumn();
            }
        } catch (Throwable $e) {}
        $st['tabelas_agora'] = $vivas;
    }
    return $st;
}

/** Baixa a cópia mais recente do banco e confere que ela está inteira. */
function backupConferir(PDO $pdo): array {
    require_once __DIR__ . '/_gdrive.php';
    $lista = _backupDriveListar();
    if ($lista['erro']) return ['ok' => false, 'etapas' => [['Lista de cópias no Drive', false, $lista['erro']]]];
    $dumps = array_values(array_filter($lista['arquivos'], fn($a) => $a['tipo'] === 'banco'));
    if (!$dumps) return ['ok' => false, 'etapas' => [['Cópia do banco no Drive', false, 'Nenhuma cópia do banco encontrada no Drive.']]];
    $alvo = $dumps[0];
    $etapas = [['Cópia mais recente', true, $alvo['nome'] . ' (' . round($alvo['bytes'] / 1048576, 2) . ' MB, ' . $alvo['criado'] . ')']];
    try { $bin = gdriveBaixarArquivo($alvo['id'], 80 * 1048576); }
    catch (Throwable $e) { $etapas[] = ['Baixar do Drive', false, 'Não consegui baixar a cópia: ' . $e->getMessage()]; return ['ok' => false, 'etapas' => $etapas]; }
    if (!is_string($bin) || $bin === '') { $etapas[] = ['Baixar do Drive', false, 'Não consegui baixar a cópia.']; return ['ok' => false, 'etapas' => $etapas]; }
    $etapas[] = ['Baixar do Drive', true, round(strlen($bin) / 1048576, 2) . ' MB baixados'];
    $tmp = _backupPastaConfig() . '/conferir-' . bin2hex(random_bytes(4)) . '.sql.gz';
    file_put_contents($tmp, $bin); @chmod($tmp, 0600); unset($bin);
    $ok = true;
    try {
        $gz = @gzopen($tmp, 'rb');
        if (!$gz) { $etapas[] = ['Abrir o arquivo compactado', false, 'O arquivo não abre como gzip (corrompido).']; return ['ok' => false, 'etapas' => $etapas]; }
        $criar = 0; $inserts = 0; $linhas = 0; $cab = false; $rodape = false; $drops = 0; $ultima = ''; $tabs = [];
        while (!gzeof($gz)) {
            $l = gzgets($gz, 1048576);
            if ($l === false) break;
            $linhas++;
            if ($linhas === 1 && strpos($l, '-- Backup Intus') === 0) $cab = true;
            if (strncmp($l, 'CREATE TABLE', 12) === 0) { $criar++; if (preg_match('/CREATE TABLE `([^`]+)`/', $l, $m)) $tabs[$m[1]] = true; }
            elseif (strncmp($l, 'DROP TABLE IF EXISTS', 20) === 0) $drops++;
            elseif (strncmp($l, 'INSERT INTO', 11) === 0) $inserts++;
            if (trim($l) !== '') $ultima = trim($l);
        }
        $fimLido = gzeof($gz); gzclose($gz);
        $rodape = ($ultima === 'SET FOREIGN_KEY_CHECKS=1;');
        $etapas[] = ['Arquivo compactado íntegro', $fimLido, $fimLido ? $linhas . ' linhas lidas até o fim' : 'Leitura interrompida (arquivo cortado ou corrompido)'];
        $etapas[] = ['Cabeçalho e rodapé do dump', $cab && $rodape, ($cab ? 'cabeçalho ok' : 'sem cabeçalho') . ', ' . ($rodape ? 'terminou corretamente' : 'sem a linha final (dump cortado)')];
        $vivas = (int)$pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'')->fetchColumn();
        $etapas[] = ['Todas as tabelas presentes', $criar > 0 && $criar >= $vivas - 2, $criar . ' tabelas na cópia, ' . $vivas . ' no banco agora (tabelas novas depois do backup são normais)'];
        $etapas[] = ['Dados presentes', $inserts > 0, $inserts . ' blocos de dados'];
        $ok = $fimLido && $cab && $rodape && $criar > 0 && $inserts > 0;
    } catch (Throwable $e) { $etapas[] = ['Conferência', false, 'Erro: ' . $e->getMessage()]; $ok = false; }
    @unlink($tmp);
    return ['ok' => $ok, 'etapas' => $etapas, 'arquivo' => $alvo['nome']];
}

/** Andamento do backup em curso (ou do último): lido de config/backup-progresso.json, que o cron-backup.php atualiza a cada etapa. */
function backupProgresso(): array
{
    $arq = _backupPastaConfig() . '/backup-progresso.json';
    $j = is_file($arq) ? json_decode((string)@file_get_contents($arq), true) : null;
    if (!is_array($j)) return ['estado' => 'nenhum', 'agora' => time()];
    $j['agora'] = time();
    $j['parado_ha'] = max(0, $j['agora'] - (int)($j['atualizado'] ?? $j['agora']));
    // Sinal de vida parado por 4 minutos: o servidor encerrou o processo no meio (limite de tempo ou de processos da hospedagem).
    if (($j['estado'] ?? '') === 'rodando' && $j['parado_ha'] > 240) {
        $j['estado'] = 'falha';
        $j['msg'] = 'O backup parou de responder em "' . ($j['etapa'] ?? '?') . '" (sem sinal há ' . round($j['parado_ha'] / 60) . ' min). O servidor provavelmente encerrou o processo por limite de tempo ou de processos da hospedagem.';
    }
    $j['log'] = array_slice(_backupTail(_backupPastaConfig() . '/backup.log', 12), -8);
    return $j;
}

/**
 * Confere, ANTES de começar, tudo de que o backup precisa e devolve o motivo exato se algo não estiver pronto.
 * [ok, motivo, etapas[nome, ok, detalhe]]. Antes de 14/10/2026 o botão só dizia "aguarde" e, se algo falhasse depois, não aparecia nada.
 */
function backupPreparar(PDO $pdo): array
{
    $et = []; $falha = '';
    $add = function (string $nome, bool $ok, string $det, bool $fatal = true) use (&$et, &$falha) {
        $et[] = [$nome, $ok, $det];
        if (!$ok && $fatal && $falha === '') $falha = $nome . ': ' . $det;
    };
    $prog = backupProgresso();
    if (($prog['estado'] ?? '') === 'rodando') {
        $add('Outro backup em andamento', false, 'já existe um backup rodando (' . ($prog['etapa'] ?? '') . '). Aguarde ele terminar.');
    }
    $cfg = _backupPastaConfig(); $t = $cfg . '/.teste-' . bin2hex(random_bytes(3));
    $okPasta = @file_put_contents($t, 'x') !== false; @unlink($t);
    $add('Pasta de trabalho', $okPasta, $okPasta ? 'o servidor deixa gravar em app/config' : 'o servidor não deixa gravar em app/config (permissão da pasta)');
    $add('Compactação gzip', function_exists('gzopen'), function_exists('gzopen') ? 'disponível' : 'a extensão zlib não está ativa neste servidor');
    $okNet = function_exists('curl_init') && function_exists('openssl_sign');
    $add('Conexão com o Google (curl e openssl)', $okNet, $okNet ? 'disponíveis' : 'faltam as extensões curl/openssl neste servidor');
    try {
        $n = count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
        $add('Banco de dados', $n > 0, $n . ' tabelas');
    } catch (Throwable $e) { $add('Banco de dados', false, 'não consegui ler as tabelas: ' . $e->getMessage()); }
    try {
        require_once __DIR__ . '/_gdrive.php';
        if (!gdriveDisponivel()) {
            $add('Google Drive', false, 'a chave do Drive não está configurada (admin/gdrive-key.php) ou faltam curl/openssl');
        } else {
            $tok = _gdriveToken(_gdriveKey());
            $add('Google Drive', (bool)$tok, $tok ? 'o Google aceitou a chave' : 'o Google recusou a chave da conta de serviço (confira a chave e o acesso à pasta)');
        }
    } catch (Throwable $e) { $add('Google Drive', false, $e->getMessage()); }
    $add('Pastas de fotos (zip)', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'disponível' : 'sem ZipArchive: só o banco será copiado', false);
    return ['ok' => $falha === '', 'motivo' => $falha, 'etapas' => $et];
}

function _backupSmtpEstritoLigado(): bool
{
    try {
        $pdo = _gdriveConn(); if (!$pdo) return false;
        _gdriveEnsureTable($pdo);
        $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'backup_config' LIMIT 1");
        $st->execute();
        $j = json_decode((string)$st->fetchColumn(), true);
        return is_array($j) && !empty($j['smtp_estrito']);
    } catch (Throwable $e) { return false; }
}

/** Liga ou desliga o modo estrito (e-mail só sai por conexão com certificado verificado). Guarda junto do e-mail do aviso. */
function backupSalvarEstrito(bool $ligado): bool
{
    try {
        require_once __DIR__ . '/_gdrive.php';
        $pdo = _gdriveConn(); if (!$pdo) return false;
        _gdriveEnsureTable($pdo);
        $st = $pdo->prepare("SELECT valor FROM intus_secrets WHERE chave = 'backup_config' LIMIT 1");
        $st->execute();
        $v = $st->fetchColumn();
        $j = $v ? json_decode((string)$v, true) : [];
        if (!is_array($j)) $j = [];
        $j['smtp_estrito'] = $ligado;
        $json = json_encode($j);
        if ($v !== false && $v !== null) $pdo->prepare("UPDATE intus_secrets SET valor = ? WHERE chave = 'backup_config'")->execute([$json]);
        else $pdo->prepare("INSERT INTO intus_secrets (chave, valor) VALUES ('backup_config', ?)")->execute([$json]);
        return true;
    } catch (Throwable $e) { return false; }
}

/** Manda um e-mail pelo SMTP do site e devolve [ok, mensagem exata, conexão verificada?]. */
function backupSmtpEnviar(string $para, string $assunto, string $texto): array
{
    require_once __DIR__ . '/_smtp_seguro.php';
    $smtp = intusSmtpConfig();
    if (!is_array($smtp)) return [false, 'O e-mail do site não está configurado (admin/smtp-config.php).', false];
    $host = (string)($smtp['host'] ?? ''); $user = (string)($smtp['user'] ?? ''); $pass = (string)($smtp['pass'] ?? ''); $nome = (string)($smtp['from_name'] ?? 'Intus Fit');
    if ($host === '' || $user === '' || $pass === '') return [false, 'O SMTP do site está sem host, usuário ou senha (configure em admin/smtp-config.php).', false];
    // Teste sempre ESTRITO: o objetivo dele é provar que a conexão é verificada.
    $sock = intusSmtpAbrir($host, $info, 12, true);
    if (!$sock) return [false, $info['erro'], false];
    $ler = function () use ($sock) { $r = ''; while (($l = fgets($sock, 1024)) !== false) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $cmd = function ($c) use ($sock, $ler) { fwrite($sock, $c . "\r\n"); return $ler(); };
    $cod = fn($r) => (int)substr(trim((string)$r), 0, 3);
    $passos = ['EHLO' => [$cmd('EHLO intusfit.com.br'), 250], 'AUTH' => [$cmd('AUTH LOGIN'), 334], 'USER' => [$cmd(base64_encode($user)), 334], 'PASS' => [$cmd(base64_encode($pass)), 235],
               'FROM' => [$cmd("MAIL FROM:<{$user}>"), 250], 'RCPT' => [$cmd("RCPT TO:<{$para}>"), 250], 'DATA' => [$cmd('DATA'), 354]];
    foreach ($passos as $n => $par) {
        if ($cod($par[0]) !== $par[1]) { @fclose($sock); return [false, "O servidor de e-mail recusou em {$n}: " . trim(substr((string)$par[0], 0, 160)), true]; }
    }
    $cab = "From: {$nome} <{$user}>\r\nTo: {$para}\r\nSubject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    fwrite($sock, $cab . "\r\n" . chunk_split(base64_encode($texto), 76, "\r\n") . "\r\n.\r\n");
    $fim = $ler(); $cmd('QUIT'); @fclose($sock);
    $conn = 'conexão segura verificada (certificado de ' . $info['nome'] . ', porta ' . $info['porta'] . ')';
    return $cod($fim) === 250
        ? [true, 'Enviado para ' . $para . ' pelo servidor de e-mail, com ' . $conn . '. Confira também a caixa de spam.', true]
        : [false, 'O servidor recusou a mensagem para ' . $para . ': ' . trim(substr((string)$fim, 0, 160)), true];
}

function backupEmailTeste(): array
{
    $smtp = intusSmtpConfig();
    $padrao = is_array($smtp) ? (string)($smtp['user'] ?? '') : '';
    $para = _backupEmailConfigurado() ?: $padrao;
    if ($para === '') return [false, 'Sem e-mail de destino definido.', ''];
    [$ok, $msg] = backupSmtpEnviar($para, 'Teste do aviso de backup - Intus Fit', "Este é um teste do aviso diário de backup.\nSe esta mensagem chegou, o aviso de backup chega neste endereço.\n" . date('d/m/Y H:i:s'));
    return [$ok, $msg, $para];
}

/**
 * Testa os DOIS endereços (o do site, de onde o app envia, e o de destino do aviso) e confere o que o DNS diz sobre o e-mail do domínio.
 * O que o código NÃO consegue ver: se o encaminhamento do e-mail do site para o Gmail está ligado no painel da KingHost e se o "Enviar como"
 * está configurado no Gmail. Por isso o teste manda uma mensagem para cada endereço: se a do endereço do site chegar na caixa do Gmail, o
 * encaminhamento funciona.
 */
function backupEmailTesteAmbos(): array
{
    $smtp = intusSmtpConfig();
    $site = is_array($smtp) ? strtolower(trim((string)($smtp['user'] ?? ''))) : '';
    $dest = strtolower(trim(_backupEmailConfigurado()));
    $alvos = array_values(array_unique(array_filter([$site, $dest])));
    if (!$alvos) return ['ok' => false, 'linhas' => ['Sem endereços para testar (e-mail do site sem usuário e sem destino escolhido).']];
    $linhas = []; $tudo = true; $conexaoOk = false;
    foreach ($alvos as $a) {
        [$ok, $msg, $verif] = backupSmtpEnviar($a, 'Teste de e-mail da Intus Fit para ' . $a, "Teste de envio e encaminhamento.\nEnviado pelo servidor do site para: {$a}\n" . date('d/m/Y H:i:s') . "\n\nSe você recebeu esta mensagem na caixa certa, o endereço funciona.");
        $linhas[] = ($ok ? '✅ ' : '❌ ') . $a . ': ' . $msg;
        if (!$ok) $tudo = false; if ($ok && $verif) $conexaoOk = true;
    }
    if ($site !== '' && $dest !== '' && $site !== $dest) {
        $linhas[] = 'ℹ️ Agora confira as caixas: a mensagem enviada para ' . $site . ' deve aparecer também em ' . $dest . ' (é o encaminhamento ligado no painel da KingHost). Se só a de ' . $dest . ' chegou, o encaminhamento de ' . $site . ' não está ligado.';
    }
    // O que o DNS diz sobre o e-mail do domínio
    $dom = $site !== '' && strpos($site, '@') !== false ? substr($site, strpos($site, '@') + 1) : '';
    if ($dom !== '' && function_exists('dns_get_record')) {
        $mx = @dns_get_record($dom, DNS_MX); $txt = @dns_get_record($dom, DNS_TXT); $dm = @dns_get_record('_dmarc.' . $dom, DNS_TXT);
        $mxs = is_array($mx) ? array_map(fn($r) => rtrim((string)($r['target'] ?? ''), '.'), $mx) : [];
        $spf = ''; foreach ((is_array($txt) ? $txt : []) as $r) { if (stripos((string)($r['txt'] ?? ''), 'v=spf1') === 0) $spf = (string)$r['txt']; }
        $linhas[] = ($mxs ? '✅' : '❌') . ' Onde chega o e-mail de ' . $dom . ' (MX): ' . ($mxs ? implode(', ', $mxs) : 'nenhum registro');
        $linhas[] = ($spf !== '' ? '✅' : '⚠️') . ' SPF (quem pode enviar em nome do domínio): ' . ($spf !== '' ? $spf : 'não configurado');
        $linhas[] = (is_array($dm) && $dm ? '✅' : '⚠️') . ' DMARC (política contra falsificação): ' . (is_array($dm) && $dm ? (string)$dm[0]['txt'] : 'não configurado. Recomendado: crie um registro TXT em _dmarc.' . $dom . ' com v=DMARC1; p=none; rua=mailto:' . ($dest ?: $site));
    }
    return ['ok' => $tudo, 'linhas' => $linhas, 'pode_exigir' => $conexaoOk, 'estrito' => _backupSmtpEstritoLigado()];
}

/** Entrega a resposta ao painel e segue trabalhando com a conexão já encerrada (o botão não fica esperando o backup inteiro). */
function _backupResponderEContinuar(string $json): void
{
    @ignore_user_abort(true); @set_time_limit(0);
    @ini_set('zlib.output_compression', '0');
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); header('Content-Length: ' . strlen($json)); header('Connection: close'); }
    echo $json;
    while (ob_get_level() > 0) { @ob_end_flush(); }
    @flush();
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
}

/** Confere, responde ao painel e dispara o backup completo agora (mesmo script diário); o painel acompanha pela barra de progresso. */
function backupRodarAgora(PDO $pdo): void
{
    $chk = backupPreparar($pdo);
    if (!$chk['ok']) {
        echo json_encode(['ok' => false, 'msg' => 'O backup não começou. ' . $chk['motivo'], 'etapas' => $chk['etapas']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cfg = _backupPastaConfig();
    @file_put_contents($cfg . '/backup-ultimo.txt', date('Y-m-d'));
    @file_put_contents($cfg . '/backup-progresso.json', json_encode(['inicio' => time(), 'atualizado' => time(), 'pct' => 1, 'etapa' => 'Iniciando', 'estado' => 'rodando', 'msg' => ''], JSON_UNESCAPED_UNICODE), LOCK_EX);
    @file_put_contents($cfg . '/backup.log', '[' . date('Y-m-d H:i:s') . '] Backup manual pedido pelo painel.' . PHP_EOL, FILE_APPEND);
    _backupResponderEContinuar(json_encode(['ok' => true, 'msg' => 'Backup iniciado.', 'etapas' => $chk['etapas']], JSON_UNESCAPED_UNICODE));
    if (!defined('BACKUP_CARONA')) define('BACKUP_CARONA', true);
    try { include __DIR__ . '/cron-backup.php'; }
    catch (Throwable $e) {
        @file_put_contents($cfg . '/backup.log', '[' . date('Y-m-d H:i:s') . '] ERRO no backup manual: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        @file_put_contents($cfg . '/backup-progresso.json', json_encode(['inicio' => time(), 'atualizado' => time(), 'pct' => 0, 'etapa' => 'Erro', 'estado' => 'falha', 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    exit;
}
