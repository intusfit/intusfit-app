<?php
/**
 * ══════════════════════════════════════════════════════════════════════════
 * BACKUP DIÁRIO DO INTUS — banco de dados + arquivos enviados
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Por que este arquivo existe
 * ---------------------------
 * Em 21/08/2026 a conferência do painel da KingHost mostrou que o backup
 * recorrente NÃO está contratado: não existe nenhuma cópia automática do site
 * nem do banco. Todo o histórico de treinos, anamneses, avaliações e o
 * financeiro dos clientes vive num único banco, sem cópia em lugar nenhum.
 * Um erro de comando, uma tabela corrompida ou um problema no servidor e não
 * há de onde voltar.
 *
 * Este script fecha esse buraco sem mensalidade: exporta o banco e a pasta de
 * uploads todo dia e manda para a mesma pasta do Google Drive que o app já usa
 * (a chave de serviço e o folder_id que estão em config/gdrive.json).
 *
 * Como este script é disparado
 * -----------------------------
 * A KingHost NÃO executa cron por linha de comando neste plano: o recurso de
 * CronJob do painel é pago (R$ 7,90 por pacote de 20 tarefas) e funciona por
 * HTTP — o servidor deles faz uma requisição na URL que você cadastrar,
 * enviando o cabeçalho `X-Cron-Auth` com um token fixo da sua conta.
 * Por isso este arquivo aceita três formas de disparo, e recusa todo o resto:
 *
 *   1) CRONJOB DA KINGHOST (pago)
 *      Cadastre a URL:  https://intusfit.com.br/app/api/cron-backup.php
 *      O painel manda o header X-Cron-Auth automaticamente. Para o script
 *      aceitar, crie o arquivo /app/config/cron.php com:
 *
 *          <?php return ['token' => 'COLE_AQUI_O_TOKEN_DO_PAINEL'];
 *
 *      (O token aparece no painel, em CronJob → "Header de validação".
 *       Ele fica só nesse arquivo, que o .htaccess já bloqueia.)
 *
 *   2) CARONA NO PRÓPRIO APP (sem custo)
 *      Uma linha no final do dashboard.php dispara o backup no máximo uma vez
 *      por dia, depois que a resposta já foi entregue ao professor — ele não
 *      espera nada. Veja BACKUP_CARONA no fim deste arquivo.
 *
 *   3) LINHA DE COMANDO
 *      Se um dia houver SSH:  php /home/intusfit/www/app/api/cron-backup.php
 *
 * Qualquer acesso pelo navegador SEM o token responde 404 — nem confirma que o
 * arquivo existe.
 *
 * Onde o resultado aparece
 * ------------------------
 * Na pasta do Google Drive configurada em config/gdrive.json, e um resumo de
 * cada execução em /app/config/backup.log.
 *
 * O que ele NÃO faz
 * -----------------
 * Não toca na chave do Google Drive (só lê, como o resto do app já faz), não
 * apaga nada do banco e não altera nenhuma tabela. A única exclusão que faz é
 * de cópias de backup antigas dentro da própria pasta do Drive, e só quando
 * MANTER_DIAS está ligado.
 */

declare(strict_types=1);

// ── Retenção ───────────────────────────────────────────────────────────────
// Depois de quantos dias uma cópia antiga pode ser descartada do Drive.
// 0 = nunca apagar nada (mais seguro, ocupa mais espaço).
// Mesmo com a retenção ligada, o script NUNCA deixa menos de MINIMO_COPIAS
// arquivos no Drive — assim uma falha de data ou um relógio errado no servidor
// não consegue varrer o backup inteiro.
const MANTER_DIAS   = 45;
const MINIMO_COPIAS = 10;

// ── Cópia por e-mail ───────────────────────────────────────────────────────
// O destino principal do backup continua sendo a pasta do Google Drive. Este
// e-mail existe por dois motivos: você fica sabendo todo dia que a cópia foi
// feita (backup que ninguém confere é backup que ninguém tem), e o arquivo do
// banco vai anexado quando couber, o que dá uma segunda cópia fora do Drive.
//
// Deixe vazio para desligar o e-mail. O padrão usa o endereço do próprio SMTP.
const BACKUP_EMAIL_PARA = '';        // vazio = usa o user de config/smtp.php
// Acima deste tamanho o anexo não vai: servidor de e-mail recusa, e o que
// chega é um erro em vez de um aviso. O resumo é enviado do mesmo jeito.
const BACKUP_ANEXO_MAX_MB = 8;

// Pastas do site que valem cópia junto com o banco. São os arquivos que os
// clientes enviaram (fotos de avaliação, avatares) — não dá para regerar.
$PASTAS_ARQUIVOS = [
    __DIR__ . '/../uploads',
    __DIR__ . '/../painel/uploads',
    __DIR__ . '/../img/nutri',       // fotos das refeições que os alunos mandam (não existem em outro lugar)
    __DIR__ . '/../img/receitas',    // fotos de receitas enviadas pelo painel
];

// ── Quem pode disparar ────────────────────────────────────────────────────
// Um endpoint que exporta o banco inteiro é a coisa mais sensível do servidor.
// Ele só roda em três situações, e em nenhuma outra:
//   • linha de comando;
//   • chamada interna do próprio app (BACKUP_CARONA definido antes do include);
//   • requisição HTTP trazendo o X-Cron-Auth que bate com config/cron.php.
// O erro é sempre 404, nunca 401 ou 403: quem não tem o token nem descobre
// que existe um backup aqui. E a comparação usa hash_equals para não vazar o
// token pelo tempo de resposta.
$_viaCli    = (PHP_SAPI === 'cli');
$_viaCarona = defined('BACKUP_CARONA');
$_viaCron   = false;

if (!$_viaCli && !$_viaCarona) {
    $cronCfg = @include __DIR__ . '/../config/cron.php';
    $esperado = (is_array($cronCfg) && !empty($cronCfg['token'])) ? (string)$cronCfg['token'] : '';
    $recebido = $_SERVER['HTTP_X_CRON_AUTH'] ?? '';
    if ($esperado !== '' && is_string($recebido) && hash_equals($esperado, $recebido)) {
        $_viaCron = true;
    } else {
        http_response_code(404);
        exit;
    }
}
// Numa chamada HTTP o corpo não interessa a ninguém: responde curto e segue
// trabalhando com a conexão já encerrada, para não segurar um dos 2 processos
// do pool mais do que o necessário.
if ($_viaCron) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ok\n";
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
}

@set_time_limit(0);
ini_set('memory_limit', '256M');

$inicio  = microtime(true);
$carimbo = date('Y-m-d_His');
$logPath = __DIR__ . '/../config/backup.log';
// Quando este arquivo é incluído por dentro de uma função (a carona do dashboard e o botão "Fazer backup agora"), as variáveis daqui são LOCAIS e blog() e
// _backupEnviarEmail() leem `global`. Sem estas linhas o registro nunca era gravado e o resumo por e-mail saía sem o log. Publicamos no escopo global.
$GLOBALS['logPath'] = $logPath;
// Progresso para a barra do painel (Configurações > Servidor > Backups). Ver _bkProg().
$GLOBALS['progPath']   = __DIR__ . '/../config/backup-progresso.json';
$GLOBALS['progInicio'] = time();
$marcaDia = __DIR__ . '/../config/backup-ultimo.txt';

// ── Trava de um por dia ───────────────────────────────────────────────────
// Protege contra o cron disparar duas vezes e contra um F5.
//
// A CARONA NAO PASSA POR AQUI, E ISSO E ESSENCIAL. O _carona_backup.php grava
// a data de hoje ANTES de incluir este arquivo, de proposito: e assim que duas
// requisicoes simultaneas ao painel nao disparam dois backups ao mesmo tempo.
// Se este bloco tambem olhasse a marca, ele leria a data que a carona acabou
// de escrever, concluiria "ja rodou hoje" e sairia sem fazer nada. Todo dia.
// Para sempre. E o log ainda diria que estava tudo certo, que e a pior parte:
// so daria para descobrir no dia em que voce precisasse do backup.
// Quem chega pela carona ja segurou a tranca; quem chega pelo cron ou pela
// linha de comando e que precisa dela aqui.
function _backupJaTemDeHoje(string $marcaDia, bool $viaCli, bool $viaCarona): bool
{
    if ($viaCli || $viaCarona) return false;
    $ultimo = is_file($marcaDia) ? trim((string)@file_get_contents($marcaDia)) : '';
    return $ultimo === date('Y-m-d');
}

if (_backupJaTemDeHoje($marcaDia, $_viaCli, $_viaCarona)) {
    blog('Backup de hoje ja foi feito, nada a fazer.');
    exit(0);
}
if (!$_viaCli && !$_viaCarona) {
    @file_put_contents($marcaDia, date('Y-m-d'));
}

$LOG_LINHAS = [];
$GLOBALS['LOG_LINHAS'] = &$LOG_LINHAS;
function blog(string $msg): void {
    global $logPath, $LOG_LINHAS;
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $LOG_LINHAS[] = $linha;      // o mesmo texto que vai no resumo por e-mail
    if (PHP_SAPI === 'cli') echo $linha, PHP_EOL;
    @file_put_contents($logPath, $linha . PHP_EOL, FILE_APPEND);
}

/**
 * Grava o andamento do backup (o painel consulta a cada poucos segundos e desenha a barra). estado: rodando | ok | falha.
 * `atualizado` é o sinal de vida: se parar de mudar por minutos com estado "rodando", o servidor matou o processo.
 */
function _bkProg(int $pct, string $etapa, string $estado = 'rodando', string $msg = ''): void
{
    $p = $GLOBALS['progPath'] ?? null;
    if (!$p) return;
    $GLOBALS['progPct'] = $pct;
    @file_put_contents($p, json_encode(['inicio' => $GLOBALS['progInicio'] ?? time(), 'atualizado' => time(), 'pct' => $pct, 'etapa' => $etapa, 'estado' => $estado, 'msg' => $msg], JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// Qualquer saída antes do fim (exit(1) de erro, erro fatal, limite de tempo ou de memória) vira "falha" com o motivo, em vez de a barra ficar parada.
register_shutdown_function(function () {
    $p = $GLOBALS['progPath'] ?? null;
    if (!$p || !is_file($p)) return;
    $j = json_decode((string)@file_get_contents($p), true);
    if (!is_array($j) || ($j['estado'] ?? '') !== 'rodando') return;
    $e = error_get_last();
    $fatal = $e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    $lin = (array)($GLOBALS['LOG_LINHAS'] ?? []);
    $ult = $lin ? preg_replace('/^\[[^\]]+\] (ERRO: )?/', '', (string)end($lin)) : '';
    $msg = $fatal ? 'Erro fatal no servidor: ' . $e['message'] . ' (' . basename((string)$e['file']) . ':' . $e['line'] . ')'
                  : ($ult !== '' ? 'O backup parou: ' . $ult : 'O backup parou antes do fim.');
    blog('ERRO: ' . $msg);
    _bkProg((int)($j['pct'] ?? 0), 'Interrompido', 'falha', $msg);
});
_bkProg(1, 'Iniciando');

// ── Conexão: exatamente do mesmo jeito que o resto da API ──────────────────
// Assim o script nunca precisa saber o nome nem a senha do banco — ele lê a
// mesma config/db.php que todos os outros arquivos leem. Se um dia a base
// mudar de nome ou de servidor, o backup acompanha sozinho.
// ATENÇÃO (causa de o backup nunca ter rodado pela carona e pelo botão): config/db.php DECLARA a função getDB() e já foi incluído por quem chamou
// (catalogo.php, dashboard.php). Um `include` simples de novo é erro FATAL ("Cannot redeclare getDB()"), que nenhum try/catch pega e que acontecia
// antes da primeira linha do registro: nada gravado, nada na tela. include_once só devolve true na segunda vez e as constantes DB_* já estão definidas.
$cfg = @include_once __DIR__ . '/../config/db.php';
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $dbHost = $cfg['host'];
        $dbNome = $cfg['database'];
        $dsn = "mysql:host={$dbHost};dbname={$dbNome};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $dbHost = DB_HOST;
        $dbNome = DB_NAME;
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new RuntimeException('config/db.php não trouxe credenciais');
    }
} catch (Throwable $e) {
    blog('ERRO: não consegui conectar ao banco — ' . $e->getMessage());
    exit(1);
}
$GLOBALS['dbNome'] = $dbNome;
blog('Banco conectado: ' . $dbNome . ' em ' . $dbHost);
_bkProg(4, 'Banco conectado');

// ── Export do banco, tabela por tabela, direto para arquivo .gz ────────────
// Nada é montado inteiro na memória: o pool desta hospedagem dá 85 MB por
// processo, e um banco de 30 MB viraria bem mais que isso como texto SQL.
// Escrevendo em fluxo, o consumo fica praticamente constante.
// O dump sai do /tmp do sistema e passa a nascer em config/, que o .htaccess já
// bloqueia. Em hospedagem compartilhada o /tmp pode ser comum a vários clientes
// do mesmo servidor, e o nome do arquivo é previsível: enquanto o backup roda,
// o banco inteiro (anamneses, financeiro, hashes de senha) ficava legível por
// terceiros. Aqui ele nasce com permissão 0600 e é apagado no fim.
$tmpSql = __DIR__ . "/../config/intus-db-{$carimbo}.sql.gz";
$gz = gzopen($tmpSql, 'wb6');
@chmod($tmpSql, 0600);
if (!$gz) { blog('ERRO: não consegui criar o arquivo temporário do dump'); exit(1); }

function esc(PDO $pdo, $v): string {
    if ($v === null) return 'NULL';
    return $pdo->quote((string)$v);
}

try {
    gzwrite($gz, "-- Backup Intus — {$dbNome} — " . date('d/m/Y H:i:s') . "\n");
    gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tabelas = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $totalLinhas = 0;
    $idxTab = 0;

    foreach ($tabelas as $t) {
        $criar = $pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_NUM);
        gzwrite($gz, "\n-- ─── {$t} ───\nDROP TABLE IF EXISTS `{$t}`;\n" . ($criar[1] ?? '') . ";\n");

        // Cursor sem buffer: as linhas chegam uma a uma em vez de virem todas
        // para a memória antes da primeira ser escrita.
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $st = $pdo->query("SELECT * FROM `{$t}`");
        $lote = [];
        $n = 0;
        while ($linha = $st->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => esc($pdo, $v), array_values($linha));
            $lote[] = '(' . implode(',', $vals) . ')';
            $n++; $totalLinhas++;
            if (count($lote) >= 200) {
                gzwrite($gz, "INSERT INTO `{$t}` VALUES\n" . implode(",\n", $lote) . ";\n");
                $lote = [];
                if ($n % 5000 < 200) _bkProg((int)($GLOBALS['progPct'] ?? 5), "Copiando o banco: {$t} ({$n} linhas)");
            }
        }
        if ($lote) gzwrite($gz, "INSERT INTO `{$t}` VALUES\n" . implode(",\n", $lote) . ";\n");
        $st->closeCursor();
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        blog("  tabela {$t}: {$n} linhas");
        _bkProg(5 + (int)floor(50 * (++$idxTab) / max(1, count($tabelas))), "Copiando o banco: {$t}");
    }

    gzwrite($gz, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    gzclose($gz);
    blog('Dump pronto: ' . count($tabelas) . ' tabelas, ' . $totalLinhas . ' linhas, ' . round(filesize($tmpSql) / 1024) . ' KB');
} catch (Throwable $e) {
    @gzclose($gz);
    @unlink($tmpSql);
    blog('ERRO durante o dump: ' . $e->getMessage());
    exit(1);
}

// ── Envio para o Google Drive ─────────────────────────────────────────────
require_once __DIR__ . '/_gdrive.php';
if (!gdriveDisponivel()) {
    blog('ERRO: o Google Drive não está configurado (chave em intus_secrets) ou faltam as extensões curl/openssl neste servidor - o dump ficou em ' . $tmpSql);
    exit(1);
}

$enviados = 0;
$falhas   = 0;

_bkProg(56, 'Enviando o banco ao Google Drive');
// Envio em pedaços lidos do disco (nunca o arquivo inteiro na memória) e com o motivo exato quando falha (o gdriveBackup antigo devolvia null calado).
[$okDb, $msgDb] = _backupEnviarArquivoDrive($tmpSql, "intus-backup-db-{$carimbo}.sql.gz", 'application/gzip', 56, 68);
if ($okDb) { $enviados++; blog('Banco enviado ao Drive.'); }
else       { $falhas++;   blog('ERRO: o envio do banco ao Drive falhou - ' . $msgDb); }
// NAO apaga aqui: o arquivo ainda vai ser anexado no e-mail do fim. A remocao
// acontece depois do envio, junto com a do zip de arquivos.
$tamDump = (int)@filesize($tmpSql);

// ── Arquivos enviados pelos clientes ──────────────────────────────────────
// Vão num .zip separado do banco, e só quando mudaram de tamanho desde a
// última vez: foto de avaliação não muda todo dia, e reenviar 200 MB de fotos
// diariamente encheria o Drive à toa.
if (class_exists('ZipArchive')) {
    $iPasta = 0;
    foreach ($PASTAS_ARQUIVOS as $pasta) {
        if (!is_dir($pasta)) continue;
        $nomePasta = basename(dirname($pasta)) . '-' . basename($pasta);

        $arquivos = [];
        $soma = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $arquivos[] = $f->getPathname();
            $soma += $f->getSize();
        }
        if (!$arquivos) { blog("Pasta {$nomePasta}: vazia, nada a enviar."); continue; }

        // Assinatura simples (quantidade + bytes). Se não mudou, pula.
        $assin = count($arquivos) . ':' . $soma;
        $marca = __DIR__ . '/../config/backup-' . preg_replace('/[^a-z0-9]+/i', '-', $nomePasta) . '.txt';
        if (is_file($marca) && trim((string)@file_get_contents($marca)) === $assin) {
            blog("Pasta {$nomePasta}: sem alteração desde o último backup, pulando.");
            continue;
        }

        $pctPasta = 70 + 5 * ($iPasta++);
        _bkProg($pctPasta, "Compactando {$nomePasta}");
        $tmpZip = sys_get_temp_dir() . "/intus-{$nomePasta}-{$carimbo}.zip";
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            blog("ERRO: não consegui criar o zip de {$nomePasta}"); $falhas++; continue;
        }
        foreach ($arquivos as $f) {
            $zip->addFile($f, ltrim(str_replace($pasta, '', $f), '/\\'));
        }
        $zip->close();

        [$okZ, $msgZ] = _backupEnviarArquivoDrive($tmpZip, "intus-backup-{$nomePasta}-{$carimbo}.zip", 'application/zip', $pctPasta, $pctPasta + 4);
        if ($okZ) {
            $enviados++;
            @file_put_contents($marca, $assin);
            blog("Pasta {$nomePasta}: " . count($arquivos) . ' arquivos, ' . round($soma / 1048576, 1) . ' MB, enviada.');
        } else { $falhas++; blog("ERRO: envio de {$nomePasta} ao Drive falhou - " . $msgZ); }
        @unlink($tmpZip);
    }
} else {
    blog('AVISO: ZipArchive indisponível — só o banco foi copiado.');
}

// ── Limpeza de cópias antigas ─────────────────────────────────────────────
// Só mexe em arquivos criados por este script (prefixo intus-backup-) e que a
// própria conta de serviço enviou. E nunca abaixo do piso de MINIMO_COPIAS.
_bkProg(92, 'Limpando cópias antigas');
if (MANTER_DIAS > 0 && $falhas === 0) {
    try {
        $apagados = _backupLimparAntigos(MANTER_DIAS, MINIMO_COPIAS);
        if ($apagados > 0) blog("Retenção: {$apagados} cópia(s) com mais de " . MANTER_DIAS . ' dias removidas do Drive.');
    } catch (Throwable $e) {
        blog('AVISO: limpeza de cópias antigas falhou (o backup de hoje está salvo) — ' . $e->getMessage());
    }
}

$seg = round(microtime(true) - $inicio, 1);
blog("FIM: {$enviados} arquivo(s) no Drive, {$falhas} falha(s), {$seg}s.");

// ── Cópia e aviso por e-mail ──────────────────────────────────────────────
_bkProg(95, 'Enviando o aviso por e-mail');
// Roda por último, com o Drive já resolvido, para que uma falha de SMTP nunca
// derrube o backup em si. O anexo é o mesmo .sql.gz que subiu para o Drive.
try {
    _backupEnviarEmail($tmpSql, $tamDump ?? 0, $enviados, $falhas, $seg);
} catch (Throwable $e) {
    blog('AVISO: o e-mail do backup falhou (a cópia no Drive está feita) - ' . $e->getMessage());
}
@unlink($tmpSql);

_bkProg(100, $falhas > 0 ? 'Terminou com falhas' : 'Concluído', $falhas > 0 ? 'falha' : 'ok',
    $falhas > 0 ? "{$falhas} envio(s) ao Drive falharam. Veja o registro abaixo." : "{$enviados} arquivo(s) no Drive em {$seg}s.");
exit($falhas > 0 ? 1 : 0);


/**
 * Envia um arquivo ao Drive por upload retomável, em pedaços de 8 MB lidos do disco (o arquivo inteiro nunca fica na memória, ao contrário do
 * gdriveBackup antigo, que montava tudo em RAM e tinha limite de 30 s: o zip das fotos passava dos dois). Cada pedaço tem 3 tentativas.
 * Devolve [ok, motivo do erro].
 */
function _backupEnviarArquivoDrive(string $caminho, string $nome, string $mime, int $pctIni, int $pctFim): array
{
    try {
        $total = (int)@filesize($caminho);
        if ($total <= 0) return [false, 'o arquivo está vazio'];
        $sessao = gdriveIniciarUploadGrande($nome, $mime, $total);
        $f = @fopen($caminho, 'rb');
        if (!$f) return [false, 'não consegui reabrir o arquivo para enviar'];
        $parte = 8 * 1048576; $pos = 0; $parados = 0;
        while ($pos < $total) {
            fseek($f, $pos);
            $bin = fread($f, $parte);
            if ($bin === false || $bin === '') break;
            $r = null;
            for ($t = 1; $t <= 3; $t++) {
                try { $r = gdriveEnviarParte($sessao, $bin, $pos, $total); break; }
                catch (Throwable $e) { if ($t === 3) { fclose($f); return [false, $e->getMessage()]; } sleep(2); }
            }
            if (!empty($r['file_id'])) { fclose($f); _bkProg($pctFim, "Enviado: {$nome}"); return [true, '']; }
            $novo = (int)($r['recebido'] ?? ($pos + strlen($bin)));
            if ($novo <= $pos) { if (++$parados >= 3) { fclose($f); return [false, 'o Drive não aceitou o envio (parou em ' . round($pos / 1048576, 1) . ' MB)']; } }
            else { $parados = 0; $pos = min($novo, $pos + strlen($bin)); }
            _bkProg($pctIni + (int)floor(($pctFim - $pctIni) * $pos / $total), "Enviando {$nome} (" . round($pos / 1048576, 1) . ' de ' . round($total / 1048576, 1) . ' MB)');
        }
        fclose($f);
        return [false, 'o Drive não confirmou o fim do envio'];
    } catch (Throwable $e) {
        return [false, $e->getMessage()];
    }
}

/**
 * Manda o resumo do backup por e-mail, com o dump do banco anexado quando ele
 * couber. Escreve o SMTP na mão, do mesmo jeito que o resto do app, mas com
 * duas diferenças deliberadas: o certificado do servidor é VERIFICADO (o resto
 * do app manda verify_peer=false e a senha do e-mail viaja logo depois, sem
 * garantia nenhuma de com quem está falando), e o corpo é multipart, para
 * poder levar o anexo.
 */
function _backupEnviarEmail(string $arquivo, int $tamanho, int $enviados, int $falhas, float $seg): void
{
    global $LOG_LINHAS, $dbNome;
    require_once __DIR__ . '/_smtp_seguro.php';

    $smtpCfg = @include __DIR__ . '/../config/smtp.php';
    if (!is_array($smtpCfg)) $smtpCfg = [];
    $host = (string)($smtpCfg['host'] ?? 'smtp.intusfit.com.br');
    $user = (string)($smtpCfg['user'] ?? 'contato@intusfit.com.br');
    $pass = (string)($smtpCfg['pass'] ?? '');
    $nome = (string)($smtpCfg['from_name'] ?? 'Intus Fit');

    // Destino: o e-mail escolhido no painel (Configurações > Servidor > Backups), senão a constante acima, senão o próprio usuário do SMTP.
    $configurado = '';
    try { require_once __DIR__ . '/_backup_admin.php'; $configurado = _backupEmailConfigurado(); } catch (Throwable $e) {}
    $para = $configurado !== '' ? $configurado : (BACKUP_EMAIL_PARA !== '' ? BACKUP_EMAIL_PARA : $user);
    if ($para === '' || $pass === '') { blog('E-mail do backup desligado (sem destino ou sem senha de SMTP).'); return; }

    $ok      = ($falhas === 0);
    $assunto = ($ok ? 'Backup Intus Fit OK - ' : 'Backup Intus Fit COM FALHA - ') . date('d/m/Y');

    $mb = $tamanho > 0 ? round($tamanho / 1048576, 2) : 0;
    $anexar = ($tamanho > 0 && $tamanho <= BACKUP_ANEXO_MAX_MB * 1048576 && is_file($arquivo));

    $corpo  = ($ok ? "Backup concluido." : "Backup terminou com {$falhas} falha(s). Confira o log.") . "\n\n";
    $corpo .= "Banco: {$dbNome}\n";
    $corpo .= "Arquivos no Drive: {$enviados}\n";
    $corpo .= "Tamanho do dump: " . ($mb > 0 ? $mb . ' MB' : 'desconhecido') . "\n";
    $corpo .= "Duracao: {$seg}s\n\n";
    $corpo .= $anexar
        ? "O arquivo do banco vai anexado nesta mensagem, alem da copia no Drive.\n\n"
        : ($tamanho > BACKUP_ANEXO_MAX_MB * 1048576
            ? "O dump tem {$mb} MB e passou do limite de anexo (" . BACKUP_ANEXO_MAX_MB . " MB). Ele esta so no Drive.\n\n"
            : "Sem anexo nesta mensagem. A copia esta no Drive.\n\n");
    $corpo .= "--- Registro da execucao ---\n" . implode("\n", (array)$LOG_LINHAS) . "\n";

    [$cab, $msg, $anexou] = _backupMontarMensagem($para, $user, $nome, $assunto, $corpo, $anexar ? $arquivo : null);
    $anexar = $anexou;

    // Certificado verificado (cadeia e nome real do servidor, ver _smtp_seguro.php): a senha do SMTP viaja nesta conexao. Aqui é sempre estrito.
    $sock = intusSmtpAbrir($host, $infoSmtp, 12, true);
    if (!$sock) { blog('AVISO: SMTP inacessivel para o aviso de backup - ' . $infoSmtp['erro']); return; }

    $ler = function () use ($sock) {
        $r = '';
        while (($l = fgets($sock, 1024)) !== false) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; }
        return $r;
    };
    $cmd = function ($c) use ($sock, $ler) { fwrite($sock, $c . "\r\n"); return $ler(); };
    $codigo = function ($r) { return (int)substr(trim((string)$r), 0, 3); };

    $etapas = [];
    $etapas['EHLO'] = [$cmd('EHLO ' . $host), 250];
    $etapas['AUTH'] = [$cmd('AUTH LOGIN'), 334];
    $etapas['USER'] = [$cmd(base64_encode($user)), 334];
    $etapas['PASS'] = [$cmd(base64_encode($pass)), 235];
    $etapas['FROM'] = [$cmd("MAIL FROM:<{$user}>"), 250];
    $etapas['RCPT'] = [$cmd("RCPT TO:<{$para}>"), 250];
    $etapas['DATA'] = [$cmd('DATA'), 354];
    foreach ($etapas as $nomeEtapa => $par) {
        if ($codigo($par[0]) !== $par[1]) {
            blog("AVISO: SMTP recusou em {$nomeEtapa} - " . trim(substr((string)$par[0], 0, 120)));
            @fclose($sock);
            return;
        }
    }
    fwrite($sock, $cab . "\r\n" . $msg . "\r\n.\r\n");
    $fim = $ler();
    $cmd('QUIT');
    @fclose($sock);

    if ($codigo($fim) === 250) {
        blog('Aviso de backup enviado para ' . $para . ($anexar ? " (com o banco anexado, {$mb} MB)." : ' (sem anexo).'));
    } else {
        blog('AVISO: o servidor recusou a mensagem - ' . trim(substr((string)$fim, 0, 120)));
    }
}


/**
 * Remove do Drive as cópias antigas geradas por este script.
 * Trabalha só dentro da pasta configurada em gdrive.json e só em arquivos cujo
 * nome começa com "intus-backup-". Se, depois de filtrar, restarem menos que
 * $minimo arquivos, não apaga nada — a rede de segurança contra data errada.
 */
function _backupLimparAntigos(int $dias, int $minimo): int
{
    // 07/10/2026: aqui chamava _gdriveConfigPath(), função que não existe mais (a chave passou para o banco em 06/09).
    // O erro era engolido pelo try/catch de quem chama, então a retenção nunca rodou e as cópias só se acumulavam.
    $key = _gdriveKey();
    if (!is_array($key) || empty($key['folder_id'])) return 0;
    $token = _gdriveToken($key);
    if (!$token) return 0;

    $q = rawurlencode("'{$key['folder_id']}' in parents and trashed = false and name contains 'intus-backup-'");
    $url = "https://www.googleapis.com/drive/v3/files?q={$q}&fields=files(id,name,createdTime)&pageSize=1000&supportsAllDrives=true&includeItemsFromAllDrives=true";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    $files = $j['files'] ?? [];
    if (count($files) <= $minimo) return 0;

    $limite = time() - ($dias * 86400);
    $velhos = array_filter($files, fn($f) => strtotime($f['createdTime'] ?? '') < $limite);
    // Nunca deixar o total abaixo do piso.
    $podeApagar = max(0, count($files) - $minimo);
    $velhos = array_slice(array_values($velhos), 0, $podeApagar);

    $n = 0;
    foreach ($velhos as $f) {
        $c = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($f['id']) . '?supportsAllDrives=true');
        curl_setopt_array($c, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 20,
        ]);
        curl_exec($c);
        $ok = curl_getinfo($c, CURLINFO_HTTP_CODE) === 204;
        curl_close($c);
        if ($ok) $n++;
    }
    return $n;
}

/*
 * ── BACKUP_CARONA: como rodar sem pagar o CronJob ──────────────────────────
 * Se você não quiser contratar o pacote de CronJob, dá para disparar o backup
 * de carona numa requisição que já acontece todo dia. Acrescente ESTA linha no
 * FIM do arquivo /app/api/dashboard.php (a última linha, depois de tudo):
 *
 *     @include __DIR__ . '/_carona_backup.php';
 *
 * E suba junto o _carona_backup.php que veio no mesmo pacote. Ele confere se
 * já houve backup hoje; se houve, sai na hora e não custa nada. Se não houve,
 * espera a resposta do dashboard ser entregue ao professor e só então roda —
 * ninguém fica olhando para uma tela travada.
 *
 * A diferença honesta entre os dois caminhos: o CronJob roda todo dia no
 * horário marcado, aconteça o que acontecer. A carona só roda se alguém da
 * equipe abrir o painel naquele dia. Se ninguém abrir por uma semana, fica uma
 * semana sem backup novo — o último continua lá, mas envelhece.
 */


/**
 * Monta o cabeçalho e o corpo MIME da mensagem de backup. Fica separada do
 * envio de propósito: montagem de MIME é fácil de errar de um jeito silencioso
 * (um limite escrito errado e o anexo vira texto no meio da mensagem), e assim
 * dá para testar sem abrir conexão nenhuma.
 *
 * Devolve [cabecalho, corpo, anexou].
 */
function _backupMontarMensagem(string $para, string $user, string $nome, string $assunto, string $texto, ?string $arquivo): array
{
    $limite = '=_intus_' . bin2hex(random_bytes(8));

    $cab  = "From: {$nome} <{$user}>\r\n";
    $cab .= "To: {$para}\r\n";
    $cab .= 'Subject: =?UTF-8?B?' . base64_encode($assunto) . "?=\r\n";
    $cab .= 'Date: ' . date('r') . "\r\n";
    $cab .= "MIME-Version: 1.0\r\n";
    $cab .= "Content-Type: multipart/mixed; boundary=\"{$limite}\"\r\n";

    $msg  = "--{$limite}\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $msg .= chunk_split(base64_encode($texto), 76, "\r\n");

    $anexou = false;
    if ($arquivo !== null && is_file($arquivo)) {
        $bin = @file_get_contents($arquivo);
        if ($bin !== false) {
            $msg .= "--{$limite}\r\n";
            $msg .= 'Content-Type: application/gzip; name="' . basename($arquivo) . "\"\r\n";
            $msg .= 'Content-Disposition: attachment; filename="' . basename($arquivo) . "\"\r\n";
            $msg .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $msg .= chunk_split(base64_encode($bin), 76, "\r\n");
            unset($bin);
            $anexou = true;
        }
    }
    $msg .= "--{$limite}--\r\n";

    return [$cab, $msg, $anexou];
}
