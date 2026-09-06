<?php
// ── DETALHE DE ERRO NAO VAI PARA O CLIENTE ─────────────────────────────────
// As respostas devolviam $e->getMessage() direto. Em falha de conexao isso
// traz host, nome do banco e usuario; em erro de SQL, o nome das tabelas e
// colunas. E um mapa do sistema entregue de graca a quem estiver sondando.
// Agora a mensagem completa vai para o log do servidor e o cliente recebe
// so um numero para voce cruzar com o log.
function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

/**
 * Endpoint de autenticacao do aluno (cliente).
 *
 * Usa a tabela `atleta`/`atletas` (detectada dinamicamente) e adiciona as
 * colunas email/senha caso ainda nao existam. Senhas sao gravadas com bcrypt.
 *
 * Rotas:
 *   POST /api/aluno_login.php                 {email, senha}              -> valida login
 *   POST /api/aluno_login.php?action=criar    {nome, email, senha, telefone?} -> auto-cadastro
 *   POST /api/aluno_login.php?action=reset    {email, senha}              -> redefine senha
 *
 * Resposta de sucesso (login/criar):
 *   { ok:true, atleta: { idatleta, nome, email, telefone? } }
 *
 * Sem auth — endpoints publicos consumidos pela tela de login.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control');

function intus_enviar_email($to, $subject, $body, $replyTo = '') {
    // ── INJECAO DE CABECALHO SMTP ───────────────────────────────────────────
    // $to, $subject e $replyTo entravam crus no cabecalho da mensagem. Na rota
    // publica de autocadastro, o NOME e o E-MAIL digitados por quem se cadastra
    // chegavam ate aqui. Um valor contendo quebra de linha seguida de "."
    // encerrava a mensagem e abria uma transacao nova DENTRO da sessao SMTP ja
    // autenticada do dominio — relay de phishing saindo de intusfit.com.br,
    // com SPF e DKIM validos. Sem quebra de linha nao existe cabecalho novo.
    $_lim = function ($v) { return trim(str_replace(["\r", "\n", "\0"], ' ', (string)$v)); };
    $to      = $_lim($to);
    $subject = $_lim($subject);
    $replyTo = $replyTo !== '' ? $_lim($replyTo) : '';
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = '';
    if (mb_strlen($subject) > 180) $subject = mb_substr($subject, 0, 180);

    $smtpCfg = @include __DIR__ . '/../config/smtp.php';
    if (!is_array($smtpCfg)) $smtpCfg = [];
    $smtpHost = $smtpCfg['host'] ?? 'smtp.intusfit.com.br';
    $smtpUser = $smtpCfg['user'] ?? 'contato@intusfit.com.br';
    $smtpPass = $smtpCfg['pass'] ?? '';
    $fromName = $_lim($smtpCfg['from_name'] ?? 'Intus Fit');

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("ssl://$smtpHost:465", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        @file_put_contents(__DIR__ . '/../config/email_erros.log',
            date('c') . " | SMTP inacessivel ($errno $errstr) | para=$to | assunto=$subject\n", FILE_APPEND);
        $headers = "From: $fromName <$smtpUser>\r\n";
        if ($replyTo) $headers .= "Reply-To: $replyTo\r\n";
        return @mail($to, $subject, $body, $headers);
    }

    $read = function() use ($sock) { $r = ''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
    // Antes nenhuma resposta do SMTP era conferida: o e-mail de "novo aluno
    // cadastrado" podia sumir em silencio (senha vazia por falta de
    // /app/config/smtp.php, por exemplo) e ninguem ficava sabendo.
    $falhou = function($resp, $esperado) { return (int)substr(trim((string)$resp), 0, 3) !== $esperado; };
    $logErro = function($etapa, $resp) use ($to, $subject) {
        @file_put_contents(__DIR__ . '/../config/email_erros.log',
            date('c') . " | SMTP FALHOU em $etapa | para=$to | assunto=$subject | resposta=" . trim(substr((string)$resp, 0, 200)) . "\n", FILE_APPEND);
        @error_log('[intus-email] falha em ' . $etapa . ' para ' . $to);
    };

    $read();
    $send("EHLO intusfit.com.br");
    $send("AUTH LOGIN");
    $send(base64_encode($smtpUser));
    $rAuth = $send(base64_encode($smtpPass));
    if ($falhou($rAuth, 235)) {
        $logErro('AUTH (senha SMTP ausente ou invalida)', $rAuth);
        @fclose($sock);
        $headers = "From: $fromName <$smtpUser>\r\n";
        if ($replyTo) $headers .= "Reply-To: $replyTo\r\n";
        return @mail($to, $subject, $body, $headers);
    }
    $rFrom = $send("MAIL FROM:<$smtpUser>");
    if ($falhou($rFrom, 250)) { $logErro('MAIL FROM', $rFrom); @fclose($sock); return false; }
    $rRcpt = $send("RCPT TO:<$to>");
    if ($falhou($rRcpt, 250)) { $logErro('RCPT TO', $rRcpt); @fclose($sock); return false; }
    $rData = $send("DATA");
    if ($falhou($rData, 354)) { $logErro('DATA', $rData); @fclose($sock); return false; }

    $msg = "From: $fromName <$smtpUser>\r\n";
    $msg .= "To: $to\r\n";
    $msg .= "Subject: $subject\r\n";
    if ($replyTo) $msg .= "Reply-To: $replyTo\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/plain; charset=utf-8\r\n";
    $msg .= "\r\n";
    $msg .= $body;

    $rFim = $send($msg . "\r\n.");
    if ($falhou($rFim, 250)) { $logErro('envio final', $rFim); @fclose($sock); return false; }
    $send("QUIT");
    fclose($sock);
    return true;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'erro'=>'metodo invalido']);
    exit;
}

// ---------- Conexao ----------
$cfg = @include __DIR__ . '/../config/db.php';
$pdo = null;
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new Exception('sem credenciais');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'erro'=>'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Detectar tabela e colunas ----------
$tabela = null;
foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        $tabela = $t;
        break;
    } catch (Throwable $e) { /* nao existe */ }
}
if (!$tabela) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'erro'=>'tabela atleta nao encontrada']);
    exit;
}

function listCols(PDO $pdo, $tabela) {
    $out = [];
    $st = $pdo->query("SHOW COLUMNS FROM `$tabela`");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[] = $r['Field'];
    return $out;
}
function pickCol(array $cols, array $candidates) {
    foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
    return null;
}

$colunas = listCols($pdo, $tabela);

$col_id    = pickCol($colunas, ['idatleta','idaluno','id']);
$col_nome  = pickCol($colunas, ['nome','nmatleta','nmaluno','name']);
$col_email = pickCol($colunas, ['email','dsemail','nmemail']);
$col_senha = pickCol($colunas, ['senha','dssenha','password','hash']);
$col_cod   = pickCol($colunas, ['codacesso','codigo','codigo_acesso']);
$col_tel   = pickCol($colunas, ['telefone','nrtelefone','dstelefone','tel','celular']);
$col_block = pickCol($colunas, ['stbloqueio','bloqueado','stativo']);

if (!$col_id || !$col_nome) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'erro'=>'schema atleta sem id/nome', 'colunas'=>$colunas]);
    exit;
}

// ─── Autocriar colunas se faltarem (on-demand migration) ─────────
$autoCreate = [
    'email'        => 'VARCHAR(180) NULL',
    'senha'        => 'VARCHAR(255) NULL',
    'cpf'          => 'VARCHAR(14) NULL',
    'dtnascimento' => 'DATE NULL',
    'genero'       => "CHAR(1) NULL COMMENT 'M ou F'",
    'objetivos'    => 'TEXT NULL',
    'dificuldades' => 'TEXT NULL',
    'plano'        => "VARCHAR(20) NULL COMMENT 'teste|treino|dieta|treino_dieta'",
    'periodo'      => "VARCHAR(12) NULL COMMENT 'trimestral|semestral|anual'",
    'dt_cadastro'  => 'DATETIME NULL',
];
try {
    if (!$col_email) {
        $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `email` VARCHAR(180) NULL");
        $col_email = 'email';
    }
    if (!$col_senha) {
        $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `senha` VARCHAR(255) NULL");
        $col_senha = 'senha';
    }
    foreach ($autoCreate as $acCol => $acDef) {
        if ($acCol === 'email' || $acCol === 'senha') continue;
        if (!in_array($acCol, $colunas, true)) {
            try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `$acCol` $acDef"); } catch (Throwable $e) {}
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'erro'=>'nao foi possivel criar colunas email/senha', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Helpers ----------
function readJsonBody() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function rowToAtleta($row, $map) {
    $out = [
        'idatleta' => (int)$row[$map['col_id']],
        'nome'     => $row[$map['col_nome']] ?? '',
        'email'    => $row[$map['col_email']] ?? '',
    ];
    if ($map['col_tel'])   $out['telefone']   = $row[$map['col_tel']]   ?? '';
    if ($map['col_block']) $out['stbloqueio'] = $row[$map['col_block']] ?? '';
    if (isset($row['genero']))       $out['genero']       = $row['genero'] ?? '';
    if (isset($row['dtnascimento'])) $out['dtnascimento'] = $row['dtnascimento'] ?? '';
    if (isset($row['codacesso']))    $out['codacesso']    = $row['codacesso'] ?? '';
    if (isset($row['plano']))        $out['plano']        = $row['plano'] ?? '';
    if (isset($row['objetivos']))    $out['objetivos']    = $row['objetivos'] ?? '';
    if (isset($row['dificuldades'])) $out['dificuldades'] = $row['dificuldades'] ?? '';
    return $out;
}

$colmap = compact('col_id','col_nome','col_email','col_senha','col_tel','col_block');
$action = $_GET['action'] ?? '';
$body   = readJsonBody();

try {
    // ===== Auto-cadastro =====
    if ($action === 'criar') {
        // Cada chamada insere aluno, cria matricula, COPIA todas as fichas do
        // atleta modelo e dispara um e-mail. Sem limite, um laco enche a base e
        // queima a cota de envio do provedor.
        require_once __DIR__ . '/_rate_limit.php';
        checkRateLimit($pdo, 'aluno_criar', 5, 3600);
        $nome  = trim((string)($body['nome'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $senha = (string)($body['senha'] ?? '');
        $tel   = trim((string)($body['telefone'] ?? ''));
        $cpf   = trim((string)($body['cpf'] ?? ''));
        $dtnasc = trim((string)($body['dtnascimento'] ?? ''));
        $genero = strtoupper(trim((string)($body['genero'] ?? '')));
        $objetivos = $body['objetivos'] ?? [];
        $dificuldades = $body['dificuldades'] ?? [];
        $plano  = trim((string)($body['plano'] ?? 'teste'));
        $periodo = trim((string)($body['periodo'] ?? ''));

        // E-mail de verdade, e nome sem quebra de linha — os dois viram
        // cabecalho de e-mail logo adiante.
        $nome = trim(str_replace(["\r", "\n", "\0"], ' ', $nome));
        if (mb_strlen($nome) > 120) $nome = mb_substr($nome, 0, 120);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'e-mail invalido']);
            exit;
        }
        if ($nome === '' || $email === '' || strlen($senha) < 4) {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'nome, email e senha (>=4) obrigatorios']);
            exit;
        }

        // Duplicidade
        $st = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['ok'=>false, 'erro'=>'email ja cadastrado']);
            exit;
        }

        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $cols = [$col_nome, $col_email, $col_senha];
        $vals = [$nome, $email, $hash];
        $phs  = ['?','?','?'];
        if ($col_tel && $tel !== '') { $cols[]=$col_tel; $vals[]=$tel; $phs[]='?'; }
        if ($cpf !== '')   { $cols[]='cpf';          $vals[]=$cpf;    $phs[]='?'; }
        if ($dtnasc !== '') { $cols[]='dtnascimento'; $vals[]=$dtnasc; $phs[]='?'; }
        if ($genero === 'M' || $genero === 'F') { $cols[]='genero'; $vals[]=$genero; $phs[]='?'; }
        if (is_array($objetivos) && count($objetivos) > 0) {
            $cols[]='objetivos'; $vals[]=json_encode($objetivos, JSON_UNESCAPED_UNICODE); $phs[]='?';
        }
        if (is_array($dificuldades) && count($dificuldades) > 0) {
            $cols[]='dificuldades'; $vals[]=json_encode($dificuldades, JSON_UNESCAPED_UNICODE); $phs[]='?';
        }
        if ($plano !== '') { $cols[]='plano';   $vals[]=$plano;   $phs[]='?'; }
        if ($periodo !== '') { $cols[]='periodo'; $vals[]=$periodo; $phs[]='?'; }
        $profsResp = $body['professores_responsaveis'] ?? [];
        if (is_array($profsResp) && count($profsResp) > 0) {
            if (in_array('professores_responsaveis', $colunas)) {
                $cols[]='professores_responsaveis'; $vals[]=json_encode(array_map('intval', $profsResp)); $phs[]='?';
            }
        }
        // Origem do cadastro (classificação Lead x Cliente):
        // veio do link de um professor (?ref → professores_responsaveis preenchido) = 'professor';
        // direto do /app (autocadastro) ou página de captura = 'app' (lead). Respeita origem enviada no corpo.
        if (!in_array('origem', $colunas)) {
            try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `origem` VARCHAR(40) NULL"); $colunas[] = 'origem'; } catch (Throwable $e) {}
        }
        if (in_array('origem', $colunas)) {
            $origemVal = trim((string)($body['origem'] ?? ''));
            if ($origemVal === '') $origemVal = (is_array($profsResp) && count($profsResp) > 0) ? 'professor' : 'app';
            $cols[]='origem'; $vals[]=mb_substr($origemVal, 0, 40); $phs[]='?';
        }
        // Localização (país/estado/cidade) — opcional; cria colunas on-demand
        foreach (['pais' => 'VARCHAR(80)', 'estado' => 'VARCHAR(100)', 'cidade' => 'VARCHAR(120)'] as $locCol => $locTipo) {
            $locVal = trim((string)($body[$locCol] ?? ''));
            if ($locVal === '') continue;
            if (!in_array($locCol, $colunas)) {
                try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `$locCol` $locTipo NULL"); $colunas[] = $locCol; } catch (Throwable $e) { continue; }
            }
            $cols[] = $locCol; $vals[] = mb_substr($locVal, 0, 120); $phs[] = '?';
        }
        $cols[]='dt_cadastro'; $vals[]=date('Y-m-d H:i:s'); $phs[]='?';

        // Marca como NAO bloqueado por padrao
        if ($col_block && $col_block !== 'stativo') {
            $cols[]=$col_block; $vals[]='N'; $phs[]='?';
        } elseif ($col_block === 'stativo') {
            $cols[]=$col_block; $vals[]='S'; $phs[]='?';
        }

        // Código de acesso automático (login alternativo) — garante que TODO aluno de
        // autocadastro também tenha um código, igual aos criados pelo painel.
        if (!in_array('codacesso', $colunas)) {
            try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `codacesso` VARCHAR(40) NULL"); $colunas[] = 'codacesso'; } catch (Throwable $e) {}
        }
        if (in_array('codacesso', $colunas)) {
            $cod = '';
            for ($try = 0; $try < 6; $try++) {
                try { $suf = random_int(0, 9); } catch (Throwable $e) { $suf = 0; }
                $cand = 'INT' . substr((string)(int)(microtime(true) * 1000), -4) . $suf;
                $chk = $pdo->prepare("SELECT 1 FROM `$tabela` WHERE codacesso = ? LIMIT 1");
                $chk->execute([$cand]);
                if (!$chk->fetchColumn()) { $cod = $cand; break; }
                usleep(1500);
            }
            if ($cod === '') $cod = 'INT' . strtoupper(substr(md5(uniqid('', true)), 0, 5));
            $cols[] = 'codacesso'; $vals[] = $cod; $phs[] = '?';
        }

        $sql = "INSERT INTO `$tabela` (".implode(',', $cols).") VALUES (".implode(',', $phs).")";
        $pdo->prepare($sql)->execute($vals);
        $newId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $st->execute([$newId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        // ── Auto-matrícula teste grátis (dias configurável pelo admin em Planos) ──
        if ($plano === 'teste') {
            $testeDias = 3;
            try {
                $cfgSt = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'planos_config'");
                $cfgSt->execute();
                $cfgRow = $cfgSt->fetch(PDO::FETCH_ASSOC);
                if ($cfgRow && $cfgRow['valor']) {
                    $planosCfg = json_decode($cfgRow['valor'], true);
                    if (!empty($planosCfg['teste_dias']) && (int)$planosCfg['teste_dias'] > 0) {
                        $testeDias = (int)$planosCfg['teste_dias'];
                    }
                }
            } catch (Throwable $e) { /* usa default */ }

            $dtInicio = date('Y-m-d');
            $dtVenc   = date('Y-m-d', strtotime("+{$testeDias} days"));
            try {
                $pdo->prepare("INSERT INTO intus_mensalidade (idatleta, dshistorico, dtinicio, dtvencimento, vlpagar, stpgto, dtpagamento, vlpagamento, dspagamento, dtcriacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
                    ->execute([$newId, "Teste Grátis ({$testeDias} dias)", $dtInicio, $dtVenc, 0, 'S', $dtInicio, 0, 'Cortesia']);
            } catch (Throwable $e) { /* segue sem bloquear cadastro */ }

            // ── Copiar fichas do Modelo (H ou M) ──
            $modeloGenero = ($genero === 'F') ? 'F' : 'M';
            try {
                // Buscar atleta modelo pelo email especial
                $modeloEmail = ($modeloGenero === 'F') ? 'modelo.feminino@intusfit.local' : 'modelo.masculino@intusfit.local';
                $stModelo = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE $col_email = ? LIMIT 1");
                $stModelo->execute([$modeloEmail]);
                $modeloId = $stModelo->fetchColumn();

                if ($modeloId) {
                    // Buscar fichas ativas do modelo
                    $stFichas = $pdo->prepare("SELECT * FROM intus_ficha WHERE idatleta = ? AND ativo = 1");
                    $stFichas->execute([$modeloId]);
                    $fichasModelo = $stFichas->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($fichasModelo as $fm) {
                        // Criar cópia da ficha para o novo aluno
                        $pdo->prepare("INSERT INTO intus_ficha (idatleta, nmficha, dtinicio, dtfim, observacao, ativo, alongamentos, div_nomes) VALUES (?, ?, ?, ?, ?, 1, ?, ?)")
                            ->execute([$newId, $fm['nmficha'], $dtInicio, $dtVenc, $fm['observacao'] ?? '', $fm['alongamentos'] ?? null, $fm['div_nomes'] ?? null]);
                        $novaFichaId = (int)$pdo->lastInsertId();

                        // Copiar treinos/exercícios da ficha modelo
                        $stTreinos = $pdo->prepare("SELECT * FROM intus_treino WHERE idficha = ?");
                        $stTreinos->execute([$fm['idficha']]);
                        $treinosModelo = $stTreinos->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($treinosModelo as $tr) {
                            $pdo->prepare("INSERT INTO intus_treino (idficha, idexercicio, nmexercicio, divisao, qtdserie, qtdrepeticao, repeticoes, tempopausa, intervalo, metodo, observacao, substitutos) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                                ->execute([$novaFichaId, $tr['idexercicio'] ?? 0, $tr['nmexercicio'] ?? '', $tr['divisao'] ?? 'A', $tr['qtdserie'] ?? 4, $tr['qtdrepeticao'] ?? 12, $tr['repeticoes'] ?? '', $tr['tempopausa'] ?? 60, $tr['intervalo'] ?? '', $tr['metodo'] ?? '', $tr['observacao'] ?? '', $tr['substitutos'] ?? null]);
                        }
                    }
                }
            } catch (Throwable $e) { /* treinos modelo opcionais — não bloqueia */ }
        }

        // Notificar por email sobre novo cadastro
        // Destino do aviso de novo cadastro. Estava fixo no codigo: se essa caixa
        // nao for lida, o aviso 'chega' e ninguem ve. Agora pode ser trocado pela
        // chave 'notificar' em /app/config/smtp.php, sem mexer em codigo.
        $_cfgN = @include __DIR__ . '/../config/smtp.php';
        $emailNotif = (is_array($_cfgN) && !empty($_cfgN['notificar'])) ? $_cfgN['notificar'] : 'contato@intusfit.com.br';
        $assunto = "Novo aluno cadastrado: $nome";
        $corpo  = "Um novo aluno se cadastrou no Intus Fit.\n\n";
        $corpo .= "Nome: $nome\n";
        $corpo .= "Email: $email\n";
        $corpo .= "Telefone: $tel\n";
        $corpo .= "Plano: $plano\n";
        $corpo .= "Objetivos: " . (is_array($objetivos) ? implode(', ', $objetivos) : '-') . "\n";
        $corpo .= "Data: " . date('d/m/Y H:i') . "\n";
        @intus_enviar_email($emailNotif, $assunto, $corpo, $email);

        echo json_encode(['ok'=>true, 'atleta'=>rowToAtleta($row, $colmap)]);
        exit;
    }

    // ===== Verificar se email existe =====
    if ($action === 'check_email') {
        // Sem limite, esta rota confirma um a um quais e-mails de uma lista
        // vazada sao alunos da academia. O equivalente no usuarios.php ja tinha
        // sido fechado; a versao do aluno passou batido.
        require_once __DIR__ . '/_rate_limit.php';
        checkRateLimit($pdo, 'check_email', 20, 3600);
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($email === '') {
            echo json_encode(['exists' => false]);
            exit;
        }
        $st = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $st->execute([$email]);
        echo json_encode(['exists' => (bool)$st->fetchColumn()]);
        exit;
    }

    // ===== Solicitar OTP para reset =====
    if ($action === 'request_reset') {
        require_once __DIR__ . '/_rate_limit.php';
        checkRateLimit($pdo, 'reset_otp', 3, 3600);

        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($email === '') {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'email ausente']);
            exit;
        }
        // Verificar se email existe (sem revelar ao atacante)
        $st = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $st->execute([$email]);
        if (!$st->fetchColumn()) {
            // Resposta neutra — não revela se email existe
            echo json_encode(['ok'=>true, 'msg'=>'Se o email existir, um codigo sera enviado.']);
            exit;
        }

        require_once __DIR__ . '/_otp.php';
        $code = createOtp($pdo, $email, 10);
        if (!$code) {
            http_response_code(429);
            echo json_encode(['ok'=>false, 'erro'=>'Muitas tentativas. Aguarde 1 hora.']);
            exit;
        }

        // Enviar email com código
        $assunto = "Código de verificação - Intus Fit";
        $corpo  = "Seu código de verificação para redefinir a senha é:\n\n";
        $corpo .= "    $code\n\n";
        $corpo .= "Este código expira em 10 minutos.\n";
        $corpo .= "Se você não solicitou isso, ignore este email.\n";
        intus_enviar_email($email, $assunto, $corpo);

        recordAttempt($pdo, 'reset_otp');
        echo json_encode(['ok'=>true, 'msg'=>'Se o email existir, um codigo sera enviado.']);
        exit;
    }

    // ===== Verificar OTP =====
    if ($action === 'verify_otp') {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $code = trim((string)($body['code'] ?? ''));
        if ($email === '' || strlen($code) !== 6) {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'email/codigo invalido']);
            exit;
        }
        require_once __DIR__ . '/_otp.php';
        $valid = verifyOtp($pdo, $email, $code);
        if (!$valid) {
            http_response_code(401);
            echo json_encode(['ok'=>false, 'erro'=>'Codigo invalido ou expirado.']);
            exit;
        }
        echo json_encode(['ok'=>true, 'verified'=>true]);
        exit;
    }

    // ===== Reset de senha (requer OTP verificado) =====
    if ($action === 'reset') {
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $senha = (string)($body['senha'] ?? '');
        if ($email === '' || strlen($senha) < 6) {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'email/senha invalidos (min 6 chars)']);
            exit;
        }

        // Exigir OTP verificado
        require_once __DIR__ . '/_otp.php';
        if (!consumeVerifiedOtp($pdo, $email)) {
            http_response_code(403);
            echo json_encode(['ok'=>false, 'erro'=>'Verificacao de identidade necessaria. Solicite um novo codigo.']);
            exit;
        }

        $st = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $st->execute([$email]);
        $rid = $st->fetchColumn();
        if (!$rid) {
            echo json_encode(['ok'=>false, 'erro'=>'nao encontrado']);
            exit;
        }
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $upd = $pdo->prepare("UPDATE `$tabela` SET $col_senha = ? WHERE $col_id = ?");
        $upd->execute([$hash, $rid]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // ===== Validar login (default) =====
    require_once __DIR__ . '/_rate_limit.php';
    checkRateLimit($pdo, 'aluno_login', 5, 900);

    $email = strtolower(trim((string)($body['email'] ?? '')));
    $senha = (string)($body['senha'] ?? '');
    if ($email === '' || $senha === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'erro'=>'email/senha ausente']);
        exit;
    }

    // ── O CODIGO DE ACESSO TAMBEM IDENTIFICA A CONTA ────────────────────────
    // A tela de entrada diz "E-mail, senha ou codigo de acesso incorretos" e o
    // codigo era aceito como SENHA — mas a conta so era procurada por e-mail.
    // Quem digitasse o codigo no primeiro campo, como todo mundo entende que
    // pode, recebia "credenciais invalidas" sem ter errado nada. Agora, nao
    // achando por e-mail, procura-se pelo codigo de acesso. A senha continua
    // sendo exigida do mesmo jeito — isto muda como a conta e encontrada, nao o
    // que e preciso saber para entrar.
    $stmt = $pdo->prepare("SELECT * FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row && $col_cod) {
        $ident = trim((string)($body['email'] ?? ''));
        if ($ident !== '') {
            $q = $pdo->prepare("SELECT * FROM `$tabela` WHERE UPPER(`$col_cod`) = UPPER(?) LIMIT 1");
            $q->execute([$ident]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$row) {
        recordAttempt($pdo, 'aluno_login');
        http_response_code(401);
        echo json_encode(['ok'=>false, 'erro'=>'credenciais invalidas']);
        exit;
    }

    $hashOuPlain = (string)($row[$col_senha] ?? '');
    $codacesso = $col_cod ? (string)($row[$col_cod] ?? '') : '';

    $valido = false;

    if ($hashOuPlain !== '') {
        if (strlen($hashOuPlain) >= 50 && (strpos($hashOuPlain, '$2y$') === 0 || strpos($hashOuPlain, '$2a$') === 0 || strpos($hashOuPlain, '$2b$') === 0)) {
            $valido = password_verify($senha, $hashOuPlain);
        } else {
            $valido = hash_equals($hashOuPlain, $senha);
            if ($valido) {
                try {
                    $newHash = password_hash($senha, PASSWORD_BCRYPT);
                    $upd = $pdo->prepare("UPDATE `$tabela` SET $col_senha = ? WHERE $col_id = ?");
                    $upd->execute([$newHash, $row[$col_id]]);
                } catch (Throwable $e) { /* upgrade silencioso */ }
            }
        }
    }

    // O codigo de acesso vale como credencial alternativa de entrada.
    // ATENCAO: ele NAO e um reset de senha. Antes, entrar com o codigo regravava
    // a senha do aluno com o hash do proprio codigo, em silencio — a senha que ele
    // usava parava de funcionar sem nenhum aviso, e ninguem entendia o porque
    // (foi exatamente o que aconteceu com a Chaiane). Agora so gravamos quando a
    // conta ainda NAO tem senha definida; havendo senha, ela e preservada.
    if (!$valido && $codacesso !== '') {
        if (strcasecmp($codacesso, $senha) === 0) {
            $valido = true;
            if ($hashOuPlain === '') {
                try {
                    $newHash = password_hash($senha, PASSWORD_BCRYPT);
                    $upd = $pdo->prepare("UPDATE `$tabela` SET $col_senha = ? WHERE $col_id = ?");
                    $upd->execute([$newHash, $row[$col_id]]);
                } catch (Throwable $e) { /* primeira senha — melhor esforco */ }
            }
        }
    }

    if (!$valido) {
        recordAttempt($pdo, 'aluno_login');
        http_response_code(401);
        echo json_encode(['ok'=>false, 'erro'=>'credenciais invalidas']);
        exit;
    }
    clearAttempts($pdo, 'aluno_login');

    // Bloqueio (mensalidade vencida etc.)
    if ($col_block) {
        $bv = (string)($row[$col_block] ?? '');
        $bloqueado = ($col_block === 'stativo')
            ? ($bv === 'N')
            : ($bv === 'S' || $bv === '1');
        if ($bloqueado) {
            http_response_code(403);
            echo json_encode(['ok'=>false, 'erro'=>'conta bloqueada — fale com seu professor']);
            exit;
        }
    }

    $atleta = rowToAtleta($row, $colmap);

    // Gerar token server-side seguro
    require_once __DIR__ . '/_sessions.php';
    $serverToken = createSession($pdo, (int)$atleta['idatleta'], 'aluno', $atleta['nome'] ?? '', false, 30);
    if ($serverToken) $atleta['server_token'] = $serverToken;

    echo json_encode(['ok'=>true, 'atleta'=>$atleta]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'erro'=>'falha', 'detalhe' => _intusLogErro($e)]);
}
