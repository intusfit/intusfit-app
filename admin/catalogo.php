<?php
// ── LIMITE PARA IMAGEM EM BASE64 ───────────────────────────────────────────
// Estes campos gravam a imagem inteira dentro do banco, e nao tinham teto nem
// conferencia de formato. Alguns megabytes por chamada, em laco, enchem a
// tabela e derrubam o banco por espaco. O projeto ja valida MIME e tamanho no
// upload de midia; aqui a validacao nunca foi aplicada.
function _intusImagemOk($v, int $maxBytes = 3145728) {
    if ($v === null || $v === '') return null;
    if (!is_string($v)) return false;
    if (strlen($v) > $maxBytes) return false;
    // aceita data URI de imagem ou caminho/URL simples
    if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v)) return $v;
    if (preg_match('#^(https?://|/)[^\s"\'<>]{1,500}$#i', $v)) return $v;
    return false;
}

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
 * Endpoint unificado para dados que antes eram local-only:
 *   - alongamentos (catálogo)
 *   - avaliacoes (medidas corporais)
 *   - planos-config (config premium)
 *   - profile (avatar + prefs do aluno)
 *
 * Rotas:
 *   GET/POST/PUT/DELETE  ?action=alongamentos
 *   GET/POST/PUT/DELETE  ?action=avaliacoes
 *   GET/PUT              ?action=planos_config
 *   GET/PUT              ?action=profile&atleta=ID
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

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
    echo json_encode(['error' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Bootstrap ----------
function ensureCatalogoTables(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_alongamento (
            idalongamento INT AUTO_INCREMENT PRIMARY KEY,
            grupo         VARCHAR(120) NOT NULL DEFAULT 'Geral',
            nome          VARCHAR(200) NOT NULL,
            tempo         VARCHAR(40) NOT NULL DEFAULT '30s',
            obs           TEXT NULL,
            descricao     TEXT NULL,
            videoyoutube  VARCHAR(500) NULL,
            gifalongamento VARCHAR(500) NULL,
            instrucao_ia  TEXT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Auto-add new columns
    $cols = array_column($pdo->query("SHOW COLUMNS FROM intus_alongamento")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('gifalongamento', $cols)) $pdo->exec("ALTER TABLE intus_alongamento ADD COLUMN gifalongamento VARCHAR(500) NULL AFTER videoyoutube");
    if (!in_array('instrucao_ia', $cols)) $pdo->exec("ALTER TABLE intus_alongamento ADD COLUMN instrucao_ia TEXT NULL AFTER gifalongamento");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_avaliacao (
            idavaliacao  INT AUTO_INCREMENT PRIMARY KEY,
            idatleta     INT NOT NULL,
            dtavaliacao  DATE NOT NULL,
            peso         DECIMAL(5,2) NULL,
            altura       DECIMAL(4,2) NULL,
            percgordura  DECIMAL(4,1) NULL,
            observacao   TEXT NULL,
            medidas      TEXT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $acols = array_column($pdo->query("SHOW COLUMNS FROM intus_avaliacao")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('medidas', $acols)) $pdo->exec("ALTER TABLE intus_avaliacao ADD COLUMN medidas TEXT NULL AFTER observacao");
    if (!in_array('metodo', $acols)) $pdo->exec("ALTER TABLE intus_avaliacao ADD COLUMN metodo VARCHAR(120) NULL AFTER medidas");
    if (!in_array('fotos', $acols)) $pdo->exec("ALTER TABLE intus_avaliacao ADD COLUMN fotos LONGTEXT NULL AFTER metodo");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_plano_nutricional (
            idplano      INT AUTO_INCREMENT PRIMARY KEY,
            idatleta     INT NOT NULL,
            titulo       VARCHAR(200) NOT NULL DEFAULT 'Plano Alimentar',
            objetivo     VARCHAR(200) NULL,
            calorias     INT NULL,
            proteina     DECIMAL(5,1) NULL,
            carboidrato  DECIMAL(5,1) NULL,
            gordura      DECIMAL(5,1) NULL,
            refeicoes    LONGTEXT NULL,
            observacao   TEXT NULL,
            ativo        TINYINT(1) NOT NULL DEFAULT 1,
            dtinicio     DATE NULL,
            dtfim        DATE NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ncols = array_column($pdo->query("SHOW COLUMNS FROM intus_plano_nutricional")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('comentario_inicio', $ncols)) $pdo->exec("ALTER TABLE intus_plano_nutricional ADD COLUMN comentario_inicio TEXT NULL AFTER observacao");
    if (!in_array('comentario_fim', $ncols)) $pdo->exec("ALTER TABLE intus_plano_nutricional ADD COLUMN comentario_fim TEXT NULL AFTER comentario_inicio");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_config (
            chave   VARCHAR(60) PRIMARY KEY,
            valor   LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_profile (
            idatleta   INT PRIMARY KEY,
            avatar     LONGTEXT NULL,
            prefs      TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_anamnese (
            idatleta   INT PRIMARY KEY,
            dados      LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
try { ensureCatalogoTables($pdo); } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'bootstrap falhou', 'detalhe' => _intusLogErro($e)]);
    exit;
}

function jsonBody() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// Backup no Google Drive (plugável): fica inativo até existir /app/config/gdrive.json
@include_once __DIR__ . '/_gdrive.php';

function _appBaseUrl() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/app/api/catalogo.php';
    $b = rtrim(dirname(dirname($script)), '/');
    if ($b === '' || $b === '.' || $b === '/') $b = '/app';
    return $b;
}

// Persiste fotos como ARQUIVOS no servidor (seguro) e faz backup no Drive se configurado.
// Recebe um array (data URLs e/ou URLs já salvas) e devolve um array só de URLs. Nunca perde:
// se algo falhar, mantém o valor original.
function _persistirFotos($fotos) {
    if (!is_array($fotos)) return [];
    $dir = __DIR__ . '/../img/avaliacoes';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $base = _appBaseUrl();
    $out = [];
    foreach ($fotos as $f) {
        $f = is_string($f) ? $f : (is_array($f) && isset($f['url']) ? $f['url'] : '');
        if ($f === '') continue;
        if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', $f, $m)) {
            $out[] = $f; // já é URL/arquivo — mantém
            continue;
        }
        if (!is_dir($dir) || !is_writable($dir)) { $out[] = $f; continue; } // não perde
        $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 6 * 1024 * 1024) { $out[] = $f; continue; }
        try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
        $fname = 'av_' . time() . '_' . $rand . '.' . $ext;
        if (@file_put_contents($dir . '/' . $fname, $bin) === false) { $out[] = $f; continue; }
        if (function_exists('gdriveBackup')) { try { gdriveBackup($bin, $fname, 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)); } catch (Throwable $e) {} }
        $out[] = $base . '/img/avaliacoes/' . $fname;
    }
    return $out;
}

function _notificarFotosAvaliacao(PDO $pdo, int $idatleta, int $qtdFotos, string $data) {
    try {
        $nome = 'Atleta #' . $idatleta;
        foreach (['atleta', 'atletas'] as $t) {
            try {
                $st = $pdo->prepare("SELECT nome FROM `$t` WHERE idatleta = ? LIMIT 1");
                $st->execute([$idatleta]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['nome'])) { $nome = $r['nome']; break; }
            } catch (Throwable $e) {}
        }

        $to = 'contato@intusfit.com.br';
        $subject = "Novas fotos de avaliação: $nome";
        $body  = "O aluno $nome enviou $qtdFotos foto(s) de avaliação.\n\n";
        $body .= "Data da avaliação: " . date('d/m/Y', strtotime($data)) . "\n";
        $body .= "Acesse o painel para visualizar.\n\n";
        $body .= "— Intus Fit (notificação automática)";

        _catalogoSendEmail($to, $subject, $body);
    } catch (Throwable $e) {}
}

function _catalogoSendEmail(string $to, string $subject, string $body) {
    $smtpCfg = @include __DIR__ . '/../config/smtp.php';
    if (!is_array($smtpCfg)) $smtpCfg = [];
    $smtpHost = $smtpCfg['host'] ?? 'smtp.intusfit.com.br';
    $smtpUser = $smtpCfg['user'] ?? 'contato@intusfit.com.br';
    $smtpPass = $smtpCfg['pass'] ?? '';
    $fromName = $smtpCfg['from_name'] ?? 'Intus Fit';

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("ssl://$smtpHost:465", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        @file_put_contents(__DIR__ . '/../config/email_erros.log',
            date('c') . " | SMTP inacessivel ($errno $errstr) | para=$to | assunto=$subject\n", FILE_APPEND);
        return @mail($to, $subject, $body, "From: $fromName <$smtpUser>\r\n");
    }

    $read = function() use ($sock) { $r = ''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
    // Antes, nenhuma resposta do SMTP era lida: se a autenticacao falhasse (senha
    // vazia por falta de /app/config/smtp.php, por exemplo) a mensagem sumia em
    // silencio e ninguem ficava sabendo. Agora conferimos o codigo de cada etapa.
    $falhou = function($resp, $esperado) { return (int)substr(trim((string)$resp), 0, 3) !== $esperado; };
    $logErro = function($etapa, $resp) use ($to, $subject) {
        $linha = date('c') . " | SMTP FALHOU em $etapa | para=$to | assunto=$subject | resposta=" . trim(substr((string)$resp, 0, 200)) . "\n";
        @file_put_contents(__DIR__ . '/../config/email_erros.log', $linha, FILE_APPEND);
        @error_log('[intus-email] ' . $linha);
    };

    $read();
    $send("EHLO intusfit.com.br");
    $send("AUTH LOGIN");
    $send(base64_encode($smtpUser));
    $rAuth = $send(base64_encode($smtpPass));
    if ($falhou($rAuth, 235)) {                        // 235 = autenticado
        $logErro('AUTH (senha SMTP ausente ou invalida)', $rAuth);
        @fclose($sock);
        return @mail($to, $subject, $body, "From: $fromName <$smtpUser>\r\n");
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
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/plain; charset=utf-8\r\n";
    $msg .= "\r\n";
    $msg .= $body;

    $rFim = $send($msg . "\r\n.");
    if ($falhou($rFim, 250)) { $logErro('envio final', $rFim); @fclose($sock); return false; }
    $send("QUIT");
    fclose($sock);
    return true;   // so chega aqui se o servidor confirmou o recebimento
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ═══════════════ CONTROLE DE ACESSO ═══════════════
// Ate aqui, TUDO neste arquivo era publico: qualquer pessoa na internet podia ler
// action=anamneses e baixar a anamnese completa de todos os alunos (cirurgias,
// doencas, medicamentos), sobrescrever a anamnese de qualquer aluno sabendo o id,
// reescrever as regras de ranking/figurinhas e subir arquivos via midia_upload.
// Passamos a exigir token nas rotas sensiveis. Leitura de configuracao do app
// (frases, figurinhas, regras) segue publica de proposito: nao e dado pessoal e o
// app do aluno depende dela em telas antes do login.
if (!function_exists('catalogoBearerToken')) {
    function catalogoBearerToken() {
        $h = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
        elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
            }
        }
        if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
        return '';
    }
}
$_tok = catalogoBearerToken();

// Contexto do token (admin / professor / aluno). Se o helper nao existir no
// servidor, seguimos com contexto vazio — o token continua sendo exigido.
$_ctx = ['admin' => false, 'idusuario' => 0, 'is_aluno' => false];
if (@file_exists(__DIR__ . '/_auth_context.php')) {
    @require_once __DIR__ . '/_auth_context.php';
    if (function_exists('getAuthContext')) {
        try { $c = getAuthContext($pdo, $_tok); if (is_array($c)) $_ctx = array_merge($_ctx, $c); }
        catch (Throwable $e) { /* mantem contexto vazio */ }
    }
}
$_ehAluno = !empty($_ctx['is_aluno']);

// ─── SEGURANCA: token presente porem nao reconhecido ────────────────────────
// Ate aqui as rotas protegidas so conferiam se o cabecalho Authorization
// existia. Nao conferiam se o token QUERIA DIZER alguma coisa. Um texto
// qualquer no lugar do token passava por todas elas: a lista de anamneses —
// dados de saude dos alunos — saia inteira para quem mandasse "Bearer xxxx".
// Token valido e o que devolve um usuario real: sessao gravada em
// intus_sessions, ou formato antigo enquanto PERMITIR_TOKEN_LEGADO estiver
// ligado. Sem isso, $_tokValido e falso e a rota protegida devolve 401.
$_tokValido = ((int)($_ctx['idusuario'] ?? 0) > 0);

$_negar = function ($codigo, $msg) {
    http_response_code($codigo);
    echo json_encode(['error' => $msg]);
    exit;
};

// ═══════════════ CONTROLE DE ACESSO ═══════════════
// ATE 20/08/2026 ISTO ERA UMA LISTA DE ROTAS PROTEGIDAS, E O RESTO PASSAVA.
// Quem nao aparecesse em nenhuma lista caia direto no corpo do arquivo, sem
// token nenhum. Foi assim que "avaliacoes" — peso, gordura, dobras, observacoes
// e o endereco das FOTOS corporais dos alunos — ficou aberta na internet, junto
// com "nutricao", "profile" e "alongamentos". Nao era uma rota esquecida: era o
// desenho do controle, que exigia lembrar de cadastrar cada rota nova.
//
// Agora e o contrario: NADA passa sem token, exceto o que estiver escrito
// explicitamente em $_PUBLICAS. Rota nova nasce protegida. Esquecer de
// cadastrar passou a fechar a porta em vez de abrir.
$_PUBLICAS = [
    'atleta_existe',   // so responde sim/nao sobre existir um id; usada antes do login
];

// Rotas que so professor/admin acessa, em qualquer metodo.
$_SO_PROFESSOR = ['anamneses', 'avisos', 'sessoes', 'email_diag', 'email_teste'];

// Leitura liberada para aluno logado; escrita so professor. Catalogos e
// configuracoes que o app do aluno precisa ler para desenhar as telas.
$_LEITURA_ALUNO = ['alongamentos', 'frases_config', 'figurinhas_config', 'conquistas_config',
                   'cardio_regras', 'ranking_regras', 'notif_config', 'planos_config',
                   'planos_admin', 'avatars'];

// Porta de entrada unica.
if (!in_array($action, $_PUBLICAS, true)) {
    if (!$_tokValido) $_negar(401, 'token ausente ou invalido');
}
if (in_array($action, $_SO_PROFESSOR, true) && $_ehAluno) {
    $_negar(403, 'acesso restrito ao professor');
}
if (in_array($action, $_LEITURA_ALUNO, true) && $method !== 'GET' && $_ehAluno) {
    $_negar(403, 'apenas o professor pode alterar');
}

// ── ESCOPO: aluno so mexe no que e dele ────────────────────────────────────
// Rotas que recebem um id de atleta e nao tinham dono nenhum. "profile"
// aceitava PUT com ?atleta=N sem conferir nada: dava para trocar a foto de
// qualquer aluno, sem sequer estar logado.
$_POR_ATLETA = ['avaliacoes', 'nutricao', 'profile', 'anamnese'];
// A listagem geral, sem id de atleta, e so do professor — a checagem vem ANTES
// de o id do token ser aplicado, senao "sem atleta" nunca acontece.
if (in_array($action, ['avaliacoes', 'nutricao'], true) && $_ehAluno && (int)($_GET['atleta'] ?? 0) <= 0) {
    $_negar(400, 'informe o atleta');
}
if (in_array($action, $_POR_ATLETA, true) && $_ehAluno) {
    $_meuId = (int)($_ctx['idatleta'] ?? $_ctx['idusuario'] ?? 0);
    if ($_meuId <= 0) $_negar(401, 'sessao de aluno sem identidade');
    $_pedido = (int)($_GET['atleta'] ?? 0);
    if ($_pedido > 0 && $_pedido !== $_meuId) $_negar(403, 'dados de outro aluno');
    $_GET['atleta'] = $_meuId;   // o id passa a vir do token, nunca da URL
}


try {

// ═══════════════ ALONGAMENTOS ═══════════════
if ($action === 'alongamentos') {
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT * FROM intus_alongamento ORDER BY grupo, nome")->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r) {
            return [
                'idalongamento' => (int)$r['idalongamento'],
                'grupo'         => $r['grupo'],
                'nome'          => $r['nome'],
                'tempo'         => $r['tempo'],
                'obs'           => $r['obs'] ?? '',
                'descricao'     => $r['descricao'] ?? '',
                'videoyoutube'  => $r['videoyoutube'] ?? '',
                'gifalongamento'=> $r['gifalongamento'] ?? '',
                'instrucao_ia'  => $r['instrucao_ia'] ?? '',
            ];
        }, $rows);
        echo json_encode($out);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $nome = trim($b['nome'] ?? '');
        if (!$nome) { http_response_code(400); echo json_encode(['error' => 'nome obrigatorio']); exit; }
        $st = $pdo->prepare("INSERT INTO intus_alongamento (grupo, nome, tempo, obs, descricao, videoyoutube, gifalongamento, instrucao_ia) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([
            $b['grupo'] ?? 'Geral', $nome, $b['tempo'] ?? '30s',
            $b['obs'] ?? '', $b['descricao'] ?? '', $b['videoyoutube'] ?? '',
            $b['gifalongamento'] ?? '', $b['instrucao_ia'] ?? '',
        ]);
        echo json_encode(['ok' => true, 'idalongamento' => (int)$pdo->lastInsertId()]);
        exit;
    }
    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['idalongamento'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $sets = []; $vals = [];
        foreach (['grupo','nome','tempo','obs','descricao','videoyoutube','gifalongamento','instrucao_ia'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = $b[$k]; }
        }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $vals[] = $id;
        $pdo->prepare("UPDATE intus_alongamento SET " . implode(', ', $sets) . " WHERE idalongamento = ?")->execute($vals);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { $b = jsonBody(); $id = (int)($b['idalongamento'] ?? 0); }
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_alongamento WHERE idalongamento = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ AVALIACOES ═══════════════
if ($action === 'avaliacoes') {
    if ($method === 'GET') {
        $idatleta = (int)($_GET['atleta'] ?? 0);
        if ($idatleta > 0) {
            $st = $pdo->prepare("SELECT * FROM intus_avaliacao WHERE idatleta = ? ORDER BY dtavaliacao DESC");
            $st->execute([$idatleta]);
        } else {
            $st = $pdo->query("SELECT * FROM intus_avaliacao ORDER BY dtavaliacao DESC");
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r) {
            $medidas = null;
            if (!empty($r['medidas'])) { $tmp = json_decode($r['medidas'], true); if (is_array($tmp)) $medidas = $tmp; }
            $fotos = null;
            if (!empty($r['fotos'])) { $tmp = json_decode($r['fotos'], true); if (is_array($tmp)) $fotos = $tmp; }
            return [
                'idavaliacao' => (int)$r['idavaliacao'],
                'idatleta'    => (int)$r['idatleta'],
                'dtavaliacao' => $r['dtavaliacao'],
                'peso'        => $r['peso'] !== null ? (float)$r['peso'] : null,
                'altura'      => $r['altura'] !== null ? (float)$r['altura'] : null,
                'percgordura' => $r['percgordura'] !== null ? (float)$r['percgordura'] : null,
                'observacao'  => $r['observacao'] ?? '',
                'medidas'     => $medidas,
                'metodo'      => $r['metodo'] ?? null,
                'fotos'       => $fotos,
                'created_at'  => $r['created_at'] ?? null,
                'updated_at'  => $r['updated_at'] ?? null,
            ];
        }, $rows);
        echo json_encode($out);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $idatleta = (int)($b['idatleta'] ?? 0);
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
        $medidas = isset($b['medidas']) && is_array($b['medidas']) ? json_encode($b['medidas']) : null;
        $fotosArr = isset($b['fotos']) && is_array($b['fotos']) ? _persistirFotos($b['fotos']) : [];
        $fotos = count($fotosArr) ? json_encode($fotosArr) : null;
        $st = $pdo->prepare("INSERT INTO intus_avaliacao (idatleta, dtavaliacao, peso, altura, percgordura, observacao, medidas, metodo, fotos) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $idatleta,
            $b['dtavaliacao'] ?? date('Y-m-d'),
            isset($b['peso']) ? (float)$b['peso'] : null,
            isset($b['altura']) ? (float)$b['altura'] : null,
            isset($b['percgordura']) ? (float)$b['percgordura'] : null,
            $b['observacao'] ?? '',
            $medidas,
            $b['metodo'] ?? null,
            $fotos,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Notificar admin por email quando fotos são enviadas
        if ($fotos) {
            _notificarFotosAvaliacao($pdo, $idatleta, count($fotosArr), $b['dtavaliacao'] ?? date('Y-m-d'));
        }

        echo json_encode(['ok' => true, 'idavaliacao' => $newId]);
        exit;
    }
    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['idavaliacao'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $sets = []; $vals = [];
        foreach (['idatleta','dtavaliacao','peso','altura','percgordura','observacao','metodo'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = $b[$k]; }
        }
        if (array_key_exists('medidas', $b)) { $sets[] = "medidas = ?"; $vals[] = is_array($b['medidas']) ? json_encode($b['medidas']) : null; }
        $fotosArr = null;
        if (array_key_exists('fotos', $b)) { $fotosArr = is_array($b['fotos']) ? _persistirFotos($b['fotos']) : []; $sets[] = "fotos = ?"; $vals[] = count($fotosArr) ? json_encode($fotosArr) : null; }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $vals[] = $id;
        $pdo->prepare("UPDATE intus_avaliacao SET " . implode(', ', $sets) . " WHERE idavaliacao = ?")->execute($vals);

        // Notificar admin se novas fotos foram adicionadas
        if (is_array($fotosArr) && count($fotosArr) > 0) {
            $idatleta = (int)($b['idatleta'] ?? 0);
            if ($idatleta <= 0) {
                $stA = $pdo->prepare("SELECT idatleta FROM intus_avaliacao WHERE idavaliacao = ? LIMIT 1");
                $stA->execute([$id]);
                $rowA = $stA->fetch(PDO::FETCH_ASSOC);
                $idatleta = $rowA ? (int)$rowA['idatleta'] : 0;
            }
            if ($idatleta > 0) {
                _notificarFotosAvaliacao($pdo, $idatleta, count($fotosArr), $b['dtavaliacao'] ?? date('Y-m-d'));
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { $b = jsonBody(); $id = (int)($b['idavaliacao'] ?? 0); }
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_avaliacao WHERE idavaliacao = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ NUTRIÇÃO (Planos Nutricionais) ═══════════════
if ($action === 'nutricao') {
    if ($method === 'GET') {
        $idatleta = (int)($_GET['atleta'] ?? 0);
        if ($idatleta > 0) {
            $st = $pdo->prepare("SELECT * FROM intus_plano_nutricional WHERE idatleta = ? ORDER BY ativo DESC, dtinicio DESC");
            $st->execute([$idatleta]);
        } else {
            $st = $pdo->query("SELECT * FROM intus_plano_nutricional ORDER BY ativo DESC, dtinicio DESC");
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r) {
            $refeicoes = null;
            if (!empty($r['refeicoes'])) { $tmp = json_decode($r['refeicoes'], true); if (is_array($tmp)) $refeicoes = $tmp; }
            return [
                'idplano'     => (int)$r['idplano'],
                'idatleta'    => (int)$r['idatleta'],
                'titulo'      => $r['titulo'],
                'objetivo'    => $r['objetivo'] ?? '',
                'calorias'    => $r['calorias'] !== null ? (int)$r['calorias'] : null,
                'proteina'    => $r['proteina'] !== null ? (float)$r['proteina'] : null,
                'carboidrato' => $r['carboidrato'] !== null ? (float)$r['carboidrato'] : null,
                'gordura'     => $r['gordura'] !== null ? (float)$r['gordura'] : null,
                'refeicoes'   => $refeicoes,
                'observacao'  => $r['observacao'] ?? '',
                'comentario_inicio' => $r['comentario_inicio'] ?? '',
                'comentario_fim'    => $r['comentario_fim'] ?? '',
                'ativo'       => (int)$r['ativo'],
                'dtinicio'    => $r['dtinicio'],
                'dtfim'       => $r['dtfim'],
            ];
        }, $rows);
        echo json_encode($out);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $idatleta = (int)($b['idatleta'] ?? 0);
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
        $refeicoes = isset($b['refeicoes']) && is_array($b['refeicoes']) ? json_encode($b['refeicoes'], JSON_UNESCAPED_UNICODE) : null;
        $st = $pdo->prepare("INSERT INTO intus_plano_nutricional (idatleta, titulo, objetivo, calorias, proteina, carboidrato, gordura, refeicoes, observacao, comentario_inicio, comentario_fim, ativo, dtinicio, dtfim) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $idatleta,
            $b['titulo'] ?? 'Plano Alimentar',
            $b['objetivo'] ?? null,
            isset($b['calorias']) ? (int)$b['calorias'] : null,
            isset($b['proteina']) ? (float)$b['proteina'] : null,
            isset($b['carboidrato']) ? (float)$b['carboidrato'] : null,
            isset($b['gordura']) ? (float)$b['gordura'] : null,
            $refeicoes,
            $b['observacao'] ?? '',
            $b['comentario_inicio'] ?? '',
            $b['comentario_fim'] ?? '',
            isset($b['ativo']) ? (int)$b['ativo'] : 1,
            $b['dtinicio'] ?? null,
            $b['dtfim'] ?? null,
        ]);
        echo json_encode(['ok' => true, 'idplano' => (int)$pdo->lastInsertId()]);
        exit;
    }
    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['idplano'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $sets = []; $vals = [];
        foreach (['idatleta','titulo','objetivo','calorias','proteina','carboidrato','gordura','observacao','comentario_inicio','comentario_fim','ativo','dtinicio','dtfim'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = $b[$k]; }
        }
        if (array_key_exists('refeicoes', $b)) { $sets[] = "refeicoes = ?"; $vals[] = is_array($b['refeicoes']) ? json_encode($b['refeicoes'], JSON_UNESCAPED_UNICODE) : null; }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $vals[] = $id;
        $pdo->prepare("UPDATE intus_plano_nutricional SET " . implode(', ', $sets) . " WHERE idplano = ?")->execute($vals);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { $b = jsonBody(); $id = (int)($b['idplano'] ?? 0); }
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_plano_nutricional WHERE idplano = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PLANOS CONFIG ═══════════════
if ($action === 'planos_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'planos_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('planos_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ FRASES DO APP (tela inicial + fim de treino) ═══════════════
if ($action === 'frases_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'frases_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('frases_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ UPLOAD DE MÍDIA (figurinhas etc. salvas como ARQUIVO, não base64) ═══════════════
// Guardar imagens grandes como base64 dentro de um registro de config estoura os limites do
// MySQL/PHP e faz o save falhar silenciosamente. Aqui a imagem vira um arquivo em /app/img/uploads
// e a config guarda só a URL (pequena e confiável).
if ($action === 'midia_upload') {
    if ($method !== 'POST' && $method !== 'PUT') { http_response_code(405); echo json_encode(['error' => 'metodo']); exit; }
    $b = jsonBody();
    $data = (string)($b['data'] ?? '');
    if ($data === '') { http_response_code(400); echo json_encode(['error' => 'sem dados']); exit; }
    // Aceita data URL (data:image/xxx;base64,....)
    if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', $data, $m)) {
        http_response_code(400); echo json_encode(['error' => 'formato invalido (use PNG, JPG, GIF ou WEBP)']); exit;
    }
    $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
    $bin = base64_decode($m[2], true);
    if ($bin === false) { http_response_code(400); echo json_encode(['error' => 'base64 invalido']); exit; }
    if (strlen($bin) > 3 * 1024 * 1024) { http_response_code(413); echo json_encode(['error' => 'imagem grande demais (max 3MB)']); exit; }
    // Pasta física: /app/img/uploads  (catalogo.php está em /app/api)
    $dir = __DIR__ . '/../img/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_dir($dir) || !is_writable($dir)) { http_response_code(500); echo json_encode(['error' => 'pasta de upload indisponivel']); exit; }
    // Nome único
    try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
    $fname = 'fig_' . time() . '_' . $rand . '.' . $ext;
    if (@file_put_contents($dir . '/' . $fname, $bin) === false) {
        http_response_code(500); echo json_encode(['error' => 'falha ao gravar arquivo']); exit;
    }
    // URL pública relativa à raiz do app (funciona no /app e no /app/painel)
    $script = $_SERVER['SCRIPT_NAME'] ?? '/app/api/catalogo.php';
    $appBase = rtrim(dirname(dirname($script)), '/'); // -> /app
    if ($appBase === '' || $appBase === '.' || $appBase === '/') $appBase = '/app';
    echo json_encode(['ok' => true, 'url' => $appBase . '/img/uploads/' . $fname]);
    exit;
}

// ═══════════════ FIGURINHAS (tela de conclusão de treino) ═══════════════
if ($action === 'figurinhas_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'figurinhas_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(['itens' => []]);
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('figurinhas_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ CONQUISTAS (edições do admin: nome, descrição, emoji, meta, ativa) ═══════════════
if ($action === 'conquistas_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'conquistas_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(['overrides' => new stdClass()]);
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('conquistas_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ REGRAS DO CARDIO (limite diário, duração máx, velocidades máximas) ═══════════════
if ($action === 'cardio_regras') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'cardio_regras'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('cardio_regras', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ REGRAS DE PONTUAÇÃO DO RANKING ═══════════════
// Pesos configuráveis: base/faixa da musculação, fator+teto do cardio, bônus de frequência
// e a data a partir da qual a nova fórmula passa a valer (semanas anteriores ficam congeladas).
if ($action === 'ranking_regras') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'ranking_regras'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('ranking_regras', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ CONFIG DE NOTIFICAÇÕES (Automáticas + Config) — persistência no servidor ═══════════════
if ($action === 'notif_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'notif_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo ($row && $row['valor']) ? $row['valor'] : json_encode(new stdClass());
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('notif_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PLANOS ADMIN (personal, promoções, condições especiais) ═══════════════
if ($action === 'planos_admin') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_plano_admin (
        idplano     INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(200) NOT NULL,
        descricao   VARCHAR(500) DEFAULT '',
        valor       DECIMAL(10,2) NOT NULL DEFAULT 0,
        meses       INT NOT NULL DEFAULT 1,
        tipo        VARCHAR(50) DEFAULT 'personal',
        ativo       CHAR(1) DEFAULT 'S',
        dtcriacao   DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($method === 'GET') {
        $rows = $pdo->query("SELECT * FROM intus_plano_admin ORDER BY tipo, nome")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        if (empty($b['nome'])) { http_response_code(400); echo json_encode(['error' => 'nome obrigatorio']); exit; }
        $st = $pdo->prepare("INSERT INTO intus_plano_admin (nome, descricao, valor, meses, tipo) VALUES (?, ?, ?, ?, ?)");
        $st->execute([
            $b['nome'],
            $b['descricao'] ?? '',
            floatval($b['valor'] ?? 0),
            intval($b['meses'] ?? 1),
            $b['tipo'] ?? 'personal',
        ]);
        echo json_encode(['ok' => true, 'idplano' => (int)$pdo->lastInsertId()]);
        exit;
    }
    if ($method === 'PUT') {
        $idp = (int)($_GET['id'] ?? 0);
        if (!$idp) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $b = jsonBody();
        $sets = []; $params = [];
        foreach (['nome','descricao','valor','meses','tipo','ativo'] as $col) {
            if (array_key_exists($col, $b)) {
                $sets[] = "`$col` = ?";
                $params[] = $b[$col];
            }
        }
        if (empty($sets)) { echo json_encode(['ok' => true, 'noop' => true]); exit; }
        $params[] = $idp;
        $pdo->prepare("UPDATE intus_plano_admin SET " . implode(', ', $sets) . " WHERE idplano = ?")->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $idp = (int)($_GET['id'] ?? 0);
        if (!$idp) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_plano_admin WHERE idplano = ?")->execute([$idp]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PROFILE (avatar + prefs) ═══════════════
if ($action === 'profile') {
    $idatleta = (int)($_GET['atleta'] ?? 0);
    if ($idatleta <= 0) { $b = jsonBody(); $idatleta = (int)($b['idatleta'] ?? 0); }
    if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT avatar, prefs FROM intus_profile WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $prefs = $row['prefs'] ? json_decode($row['prefs'], true) : [];
            echo json_encode(['avatar' => $row['avatar'], 'prefs' => $prefs]);
        } else {
            echo json_encode(['avatar' => null, 'prefs' => new stdClass()]);
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $avatar = $b['avatar'] ?? null;
    $avatar = _intusImagemOk($avatar);
    if ($avatar === false) { http_response_code(413); echo json_encode(['error' => 'imagem invalida ou grande demais (max 3 MB)']); exit; }
        $prefs = isset($b['prefs']) ? json_encode($b['prefs']) : null;

        $st = $pdo->prepare("SELECT 1 FROM intus_profile WHERE idatleta = ?");
        $st->execute([$idatleta]);
        if ($st->fetch()) {
            $sets = []; $vals = [];
            if (array_key_exists('avatar', $b)) { $sets[] = "avatar = ?"; $vals[] = $avatar; }
            if (array_key_exists('prefs', $b)) { $sets[] = "prefs = ?"; $vals[] = $prefs; }
            if (!empty($sets)) {
                $vals[] = $idatleta;
                $pdo->prepare("UPDATE intus_profile SET " . implode(', ', $sets) . " WHERE idatleta = ?")->execute($vals);
            }
        } else {
            $pdo->prepare("INSERT INTO intus_profile (idatleta, avatar, prefs) VALUES (?, ?, ?)")->execute([$idatleta, $avatar, $prefs]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ DIAGNOSTICO DE E-MAIL (so professor) ═══════════════
// Nao envia mensagem nenhuma: apenas abre a conexao SMTP, tenta autenticar e
// relata o codigo de cada etapa. Serve para saber POR QUE os e-mails de
// recuperacao de senha e de novo cadastro nao chegam — antes isso falhava em
// silencio absoluto. Nunca devolve a senha do SMTP, so se ela existe.
if ($action === 'email_diag') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }

    $out = [];
    $cfgPath = __DIR__ . '/../config/smtp.php';
    $out['arquivo_smtp_existe'] = @file_exists($cfgPath);
    $cfg = @include $cfgPath;
    if (!is_array($cfg)) $cfg = [];
    $host = $cfg['host'] ?? 'smtp.intusfit.com.br';
    $user = $cfg['user'] ?? 'contato@intusfit.com.br';
    $pass = $cfg['pass'] ?? '';
    $out['host']            = $host;
    $out['usuario']         = $user;
    $out['tem_senha']       = ($pass !== '');
    $out['tamanho_senha']   = strlen($pass);   // so o tamanho, nunca o valor
    $out['destino_notificacao'] = $cfg['notificar'] ?? 'contato@intusfit.com.br';

    $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("ssl://$host:465", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        $out['conexao'] = 'FALHOU';
        $out['detalhe'] = trim($errno . ' ' . $errstr);
        $out['mail_nativo_disponivel'] = function_exists('mail');
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }
    $out['conexao'] = 'ok';
    $read = function() use ($sock) { $r=''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
    $cod  = function($r) { return (int)substr(trim((string)$r), 0, 3); };

    $out['saudacao'] = $cod($read());
    $out['ehlo']     = $cod($send('EHLO intusfit.com.br'));
    $send('AUTH LOGIN');
    $send(base64_encode($user));
    $rAuth = $send(base64_encode($pass));
    $out['auth_codigo']   = $cod($rAuth);
    $out['auth_ok']       = ($out['auth_codigo'] === 235);
    $out['auth_resposta'] = trim(substr((string)$rAuth, 0, 120));
    @fwrite($sock, "QUIT\r\n");
    @fclose($sock);

    $log = __DIR__ . '/../config/email_erros.log';
    if (@file_exists($log)) {
        $linhas = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($linhas)) $out['ultimas_falhas'] = array_slice($linhas, -5);
    } else {
        $out['ultimas_falhas'] = 'sem log (nenhuma falha registrada ou versao antiga no ar)';
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════ ENVIO DE TESTE (so professor) ═══════════════
// Envia UMA mensagem de teste para o endereco informado pelo professor e relata
// o codigo de CADA etapa do SMTP. E a unica forma de descobrir se a mensagem e
// aceita, recusada no destinatario, ou aceita e descartada depois (spam/SPF).
// Uso: POST /catalogo.php?action=email_teste  { "para": "voce@dominio.com" }
if ($action === 'email_teste') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }
    $b = jsonBody();
    $para = trim((string)($b['para'] ?? ''));
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400); echo json_encode(['error' => 'informe um e-mail valido em "para"']); exit;
    }
    $cfg = @include __DIR__ . '/../config/smtp.php';
    if (!is_array($cfg)) $cfg = [];
    $host = $cfg['host'] ?? 'smtp.intusfit.com.br';
    $user = $cfg['user'] ?? 'contato@intusfit.com.br';
    $pass = $cfg['pass'] ?? '';
    $nome = $cfg['from_name'] ?? 'Intus Fit';

    $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("ssl://$host:465", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) { echo json_encode(['etapa' => 'conexao', 'ok' => false, 'detalhe' => trim($errno.' '.$errstr)]); exit; }
    $read = function() use ($sock) { $r=''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
    $cod  = function($r) { return (int)substr(trim((string)$r), 0, 3); };
    $etapas = [];
    $etapas['saudacao'] = $cod($read());
    $etapas['ehlo']     = $cod($send('EHLO intusfit.com.br'));
    $send('AUTH LOGIN'); $send(base64_encode($user));
    $etapas['entrada']  = $cod($send(base64_encode($pass)));
    $etapas['remetente']    = $cod($send("MAIL FROM:<$user>"));
    $etapas['destinatario'] = $cod($send("RCPT TO:<$para>"));
    $etapas['abre_dados']   = $cod($send('DATA'));
    $msg  = "From: $nome <$user>\r\nTo: $para\r\nSubject: Teste de envio - Intus Fit\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n";
    $msg .= "Mensagem de teste enviada pelo painel em " . date('d/m/Y H:i') . ".\n";
    $msg .= "Se voce recebeu isto, o envio do sistema esta funcionando.\n";
    $rFim = $send($msg . "\r\n.");
    $etapas['aceita_pelo_servidor'] = $cod($rFim);
    $etapas['resposta_final']       = trim(substr((string)$rFim, 0, 140));
    $send('QUIT'); @fclose($sock);
    $etapas['entregue_ao_servidor'] = ($etapas['aceita_pelo_servidor'] === 250);
    $etapas['destino'] = $para;
    $etapas['observacao'] = 'Aceita pelo servidor nao garante caixa de entrada: verifique tambem o spam.';
    echo json_encode($etapas, JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════ AGENDA / EVENTOS ═══════════════
// Compromissos da equipe: reuniao, ligar para lead, palestra, avaliacao presencial.
// Podem ser so seus ou com outros professores marcados como participantes.
// Restrito a professor — aluno nao ve nem cria evento.
if ($action === 'eventos') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_evento (
            idevento INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(160) NOT NULL,
            descricao TEXT NULL,
            dt DATE NOT NULL,
            hora VARCHAR(5) NULL,
            tipo VARCHAR(24) NOT NULL DEFAULT 'geral',
            participantes TEXT NULL,
            criado_por INT NULL,
            criado_por_nome VARCHAR(120) NULL,
            concluido TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dt (dt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    $_saidaEv = function ($r) {
        $part = [];
        if (!empty($r['participantes'])) {
            $tmp = json_decode($r['participantes'], true);
            if (is_array($tmp)) $part = array_map('intval', $tmp);
        }
        return [
            'idevento'  => (int)$r['idevento'],
            'titulo'    => (string)$r['titulo'],
            'descricao' => (string)($r['descricao'] ?? ''),
            'dt'        => $r['dt'],
            'hora'      => (string)($r['hora'] ?? ''),
            'tipo'      => (string)($r['tipo'] ?? 'geral'),
            'participantes'   => $part,
            'criado_por'      => (int)($r['criado_por'] ?? 0),
            'criado_por_nome' => (string)($r['criado_por_nome'] ?? ''),
            'concluido' => (int)($r['concluido'] ?? 0) === 1,
        ];
    };

    if ($method === 'GET') {
        $de  = trim((string)($_GET['de']  ?? ''));
        $ate = trim((string)($_GET['ate'] ?? ''));
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $de) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ate)) {
            $st = $pdo->prepare("SELECT * FROM intus_evento WHERE dt BETWEEN ? AND ? ORDER BY dt, hora, idevento");
            $st->execute([$de, $ate]);
        } else {
            $st = $pdo->query("SELECT * FROM intus_evento WHERE dt >= DATE_SUB(CURDATE(), INTERVAL 120 DAY) ORDER BY dt, hora, idevento");
        }
        echo json_encode(array_map($_saidaEv, $st->fetchAll(PDO::FETCH_ASSOC)), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();

        if (!empty($b['excluir'])) {
            $idv = (int)($b['idevento'] ?? 0);
            if ($idv <= 0) { http_response_code(400); echo json_encode(['error' => 'idevento obrigatorio']); exit; }
            $pdo->prepare("DELETE FROM intus_evento WHERE idevento = ?")->execute([$idv]);
            echo json_encode(['ok' => true]); exit;
        }

        if (array_key_exists('concluido', $b) && !empty($b['idevento'])) {
            $pdo->prepare("UPDATE intus_evento SET concluido = ? WHERE idevento = ?")
                ->execute([!empty($b['concluido']) ? 1 : 0, (int)$b['idevento']]);
            echo json_encode(['ok' => true]); exit;
        }

        $titulo = trim((string)($b['titulo'] ?? ''));
        $dt     = trim((string)($b['dt'] ?? ''));
        if ($titulo === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dt)) {
            http_response_code(400);
            echo json_encode(['error' => 'titulo e data (AAAA-MM-DD) obrigatorios']);
            exit;
        }
        $hora = trim((string)($b['hora'] ?? ''));
        if ($hora !== '' && !preg_match('/^\\d{2}:\\d{2}$/', $hora)) $hora = '';
        $desc = trim((string)($b['descricao'] ?? ''));
        $tipo = trim((string)($b['tipo'] ?? 'geral'));
        $part = $b['participantes'] ?? [];
        $part = is_array($part) ? json_encode(array_values(array_unique(array_map('intval', $part)))) : null;

        $idv = (int)($b['idevento'] ?? 0);
        if ($idv > 0) {
            $st = $pdo->prepare("UPDATE intus_evento SET titulo=?, descricao=?, dt=?, hora=?, tipo=?, participantes=? WHERE idevento=?");
            $st->execute([mb_substr($titulo,0,160), $desc, $dt, $hora, mb_substr($tipo,0,24), $part, $idv]);
            echo json_encode(['ok' => true, 'idevento' => $idv]); exit;
        }
        $st = $pdo->prepare("INSERT INTO intus_evento (titulo, descricao, dt, hora, tipo, participantes, criado_por, criado_por_nome)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([mb_substr($titulo,0,160), $desc, $dt, $hora, mb_substr($tipo,0,24), $part,
                      (int)($_ctx['idusuario'] ?? 0), mb_substr((string)($_ctx['nmusuario'] ?? ''), 0, 120)]);
        echo json_encode(['ok' => true, 'idevento' => (int)$pdo->lastInsertId()]);
        exit;
    }
}

// ═══════════════ APARELHOS CONECTADOS ═══════════════
// Em 18/08/2026 uma aluna preencheu a anamnese inteira num aparelho que estava
// entrado com a conta de OUTRA pessoa. Do ponto de vista do servidor nada
// estava errado — o token era daquela conta, e foi nela que gravou. Nenhuma
// checagem de permissao pega isso: quem estava trocado era a pessoa na frente
// do celular, nao o token.
// O que resolve e conseguir VER quais aparelhos estao entrados em cada conta, e
// poder derrubar os que nao deviam estar. Sem isso, a unica saida era pedir
// para a aluna mexer nas configuracoes dela — constrangedor e nada confiavel.
// So professor. Nao devolve o token nem o hash dele em hipotese alguma.
if ($action === 'sessoes') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }

    // Faxina barata e sem efeito colateral: sessao vencida ja nao valia nada,
    // so ocupava espaco e poluia a leitura. Ninguem e desconectado por isto.
    try { $pdo->exec("DELETE FROM intus_sessions WHERE expires_at <= NOW()"); } catch (Throwable $e) {}

    if ($method === 'GET') {
        try {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM intus_sessions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $sel = ['user_id', 'user_type'];
            foreach (['user_name', 'nome', 'admin', 'created_at', 'expires_at', 'ip', 'user_agent', 'last_seen'] as $c) {
                if (in_array($c, $cols, true)) $sel[] = $c;
            }
            $ordem = in_array('created_at', $cols, true) ? 'created_at DESC' : 'user_id ASC';
            $rows = $pdo->query("SELECT " . implode(',', array_map(function ($c) { return "`$c`"; }, $sel)) .
                                " FROM intus_sessions WHERE expires_at > NOW() ORDER BY $ordem LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'colunas' => $cols, 'sessoes' => $rows], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao ler sessoes', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }

    // Derruba as sessoes de um usuario: todo aparelho entrado naquela conta cai
    // na tela de login na proxima acao. E o caminho para tirar um aparelho de
    // uma conta que nao e dele sem depender da pessoa do outro lado.
    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();

        // ── PODA: manter so as sessoes mais recentes de cada conta ──────────
        // Cada login cria uma sessao nova e nenhuma antiga era removida. Uma
        // aluna que passou semanas com dificuldade para entrar acumulou 39
        // sessoes validas, de 13 aparelhos, todas com 30 dias de validade. Nao
        // foi invasao — foi tentativa repetida — mas sao 39 chaves vivas para
        // uma conta so, e nao deveria haver nem a decima.
        // Esta operacao mantem as N mais recentes de cada conta e descarta o
        // resto. Quem for descartado cai na tela de login e entra de novo.
        if (($b['acao'] ?? '') === 'podar') {
            $manter = max(1, min(20, (int)($b['manter'] ?? 3)));
            try {
                $rows = $pdo->query("SELECT id, user_id, user_type FROM intus_sessions
                                     WHERE expires_at > NOW() ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
                $vistos = [];
                $apagar = [];
                foreach ($rows as $r) {
                    $k = $r['user_type'] . ':' . $r['user_id'];
                    $vistos[$k] = ($vistos[$k] ?? 0) + 1;
                    if ($vistos[$k] > $manter) $apagar[] = (int)$r['id'];
                }
                $n = 0;
                foreach (array_chunk($apagar, 200) as $lote) {
                    $ph = implode(',', array_fill(0, count($lote), '?'));
                    $st = $pdo->prepare("DELETE FROM intus_sessions WHERE id IN ($ph)");
                    $st->execute($lote);
                    $n += $st->rowCount();
                }
                echo json_encode(['ok' => true, 'removidas' => $n, 'mantidas_por_conta' => $manter]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['error' => 'falha ao podar', 'detalhe' => _intusLogErro($e)]);
            }
            exit;
        }

        $uid  = (int)($b['user_id'] ?? 0);
        $tipo = trim((string)($b['user_type'] ?? ''));
        if ($uid <= 0 || ($tipo !== 'aluno' && $tipo !== 'prof')) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id e user_type (aluno|prof) obrigatorios']);
            exit;
        }
        try {
            $st = $pdo->prepare("DELETE FROM intus_sessions WHERE user_id = ? AND user_type = ?");
            $st->execute([$uid, $tipo]);
            echo json_encode(['ok' => true, 'encerradas' => $st->rowCount()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao encerrar', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }
}

// ═══════════════ REACOES ═══════════════
// Reagir a uma publicacao — hoje o mural do ranking, amanha o feed dos alunos.
// A tabela e generica de proposito: guarda o TIPO do alvo e o id dele, entao
// quando o feed existir e so passar outro alvo_tipo, sem tabela nova.
//
// Duas decisoes que mudam o comportamento em relacao ao que existia:
//   1. Cada pessoa pode deixar VARIAS reacoes diferentes no mesmo item. Antes,
//      no painel, reagir de novo TROCAVA a reacao anterior — dar risada apagava
//      o joinha. Clicar na mesma reacao de novo remove; clicar em outra soma.
//   2. Quem reagiu fica gravado com nome, e a lista volta para todo mundo. Uma
//      reacao anonima nao serve para nada num mural entre colegas.
//
// O autor NUNCA vem do corpo da requisicao — vem do token. Sem isso, qualquer
// um poderia reagir no lugar de outra pessoa.
if ($action === 'reacoes') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    try {
        // Nome proprio de proposito. Ja existia uma tabela "intus_reacao" no
        // banco, com outro desenho — provavelmente das reacoes de comentario do
        // mensagens.php. Como CREATE TABLE IF NOT EXISTS nao faz nada quando a
        // tabela existe, a rota nova caiu em cima do esquema antigo e quebrou
        // com "coluna alvo_id desconhecida". Em vez de alterar uma tabela que
        // nao e minha e que outra tela usa, esta funcionalidade tem a sua.
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_reacao_pub (
            idreacao    INT AUTO_INCREMENT PRIMARY KEY,
            alvo_tipo   VARCHAR(20)  NOT NULL,
            alvo_id     INT          NOT NULL,
            autor_tipo  VARCHAR(10)  NOT NULL,
            autor_id    INT          NOT NULL,
            autor_nome  VARCHAR(200) NOT NULL DEFAULT '',
            emoji       VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reacao (alvo_tipo, alvo_id, autor_tipo, autor_id, emoji),
            INDEX idx_alvo (alvo_tipo, alvo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    // ── POR QUE A COLUNA emoji E BINARIA ───────────────────────────────────
    // Na collation padrao do MySQL (utf8mb4_general_ci) emojis de quatro bytes
    // sao considerados IGUAIS entre si: 😂 e 🔥 comparam como o mesmo texto. O
    // efeito no teste foi imediato — reagir com 🔥 depois de 😂 apagava o 😂 em
    // vez de somar, porque o SELECT achava a linha da outra reacao. Com
    // utf8mb4_bin cada emoji e ele mesmo. O ALTER abaixo conserta a tabela que
    // ja foi criada com a collation errada; em base nova ele nao faz nada.
    try {
        $pdo->exec("ALTER TABLE intus_reacao_pub
                    MODIFY COLUMN emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
    } catch (Throwable $e) {}

    $_autorTipo = $_ehAluno ? 'aluno' : 'prof';
    $_autorId   = $_ehAluno
        ? (int)($_ctx['idatleta'] ?? $_ctx['idusuario'] ?? 0)
        : (int)($_ctx['idusuario'] ?? 0);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        $tipo = trim((string)($_GET['alvo_tipo'] ?? 'mural'));
        $idsRaw = trim((string)($_GET['ids'] ?? ''));
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), function ($n) { return $n > 0; }));
        if (!count($ids)) { echo json_encode(new stdClass()); exit; }
        if (count($ids) > 500) $ids = array_slice($ids, 0, 500);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT alvo_id, emoji, autor_tipo, autor_id, autor_nome
                             FROM intus_reacao_pub WHERE alvo_tipo = ? AND alvo_id IN ($ph)
                             ORDER BY idreacao ASC");
        $st->execute(array_merge([$tipo], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)(int)$r['alvo_id'];
            if (!isset($out[$k])) $out[$k] = [];
            $out[$k][] = [
                'emoji'      => $r['emoji'],
                'autor_tipo' => $r['autor_tipo'],
                'autor_id'   => (int)$r['autor_id'],
                'autor_nome' => $r['autor_nome'],
                'meu'        => ($r['autor_tipo'] === $_autorTipo && (int)$r['autor_id'] === $_autorId),
            ];
        }
        echo json_encode($out ?: new stdClass(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();
        $tipo  = trim((string)($b['alvo_tipo'] ?? 'mural'));
        $alvo  = (int)($b['alvo_id'] ?? 0);
        $emoji = trim((string)($b['emoji'] ?? ''));
        if ($alvo <= 0 || $emoji === '') { http_response_code(400); echo json_encode(['error' => 'alvo_id e emoji obrigatorios']); exit; }
        if (mb_strlen($emoji) > 8)       { http_response_code(400); echo json_encode(['error' => 'emoji invalido']); exit; }

        // Nome de quem reagiu, buscado no servidor — nao aceita o que o cliente diz.
        $nome = '';
        try {
            if ($_ehAluno) {
                foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $_t) {
                    try {
                        $_cols = array_column($pdo->query("SHOW COLUMNS FROM `$_t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                    } catch (Throwable $e) { continue; }
                    $_ci = null; foreach (['idatleta','idaluno','id'] as $_c) { if (in_array($_c, $_cols, true)) { $_ci = $_c; break; } }
                    $_cn = null; foreach (['nome','nmathleta','nmatleta','name'] as $_c) { if (in_array($_c, $_cols, true)) { $_cn = $_c; break; } }
                    if (!$_ci || !$_cn) continue;
                    try {
                        $q = $pdo->prepare("SELECT `$_cn` FROM `$_t` WHERE `$_ci` = ? LIMIT 1");
                        $q->execute([$_autorId]);
                        $nome = (string)$q->fetchColumn();
                    } catch (Throwable $e) {}
                    break;
                }
            } else {
                // A tabela de usuarios nao tem nome de coluna garantido — pode
                // ser idusuario/idprofessor/id e nome/nmusuario/nmprofessor. Com
                // o SELECT fixo em "idusuario" e "nome", a busca falhava calada e
                // toda reacao do professor ficava assinada como "Professor".
                foreach (['professor', 'usuario', 'usuarios', 'professores'] as $_t) {
                    try {
                        $_cols = array_column($pdo->query("SHOW COLUMNS FROM `$_t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                    } catch (Throwable $e) { continue; }
                    $_ci = null; foreach (['idusuario','idprofessor','id'] as $_c) { if (in_array($_c, $_cols, true)) { $_ci = $_c; break; } }
                    $_cn = null; foreach (['nome','nmusuario','nmprofessor','name'] as $_c) { if (in_array($_c, $_cols, true)) { $_cn = $_c; break; } }
                    if (!$_ci || !$_cn) continue;
                    try {
                        $q = $pdo->prepare("SELECT `$_cn` FROM `$_t` WHERE `$_ci` = ? LIMIT 1");
                        $q->execute([$_autorId]);
                        $nome = (string)$q->fetchColumn();
                    } catch (Throwable $e) {}
                    break;
                }
            }
        } catch (Throwable $e) {}
        if ($nome === '') $nome = $_ehAluno ? 'Aluno' : 'Professor';

        $st = $pdo->prepare("SELECT idreacao FROM intus_reacao_pub WHERE alvo_tipo = ? AND alvo_id = ? AND autor_tipo = ? AND autor_id = ? AND emoji = ? LIMIT 1");
        $st->execute([$tipo, $alvo, $_autorTipo, $_autorId, $emoji]);
        $existe = $st->fetchColumn();
        if ($existe) {
            $pdo->prepare("DELETE FROM intus_reacao_pub WHERE idreacao = ?")->execute([$existe]);
            echo json_encode(['ok' => true, 'estado' => 'removida']);
        } else {
            $pdo->prepare("INSERT INTO intus_reacao_pub (alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, emoji) VALUES (?,?,?,?,?,?)")
                ->execute([$tipo, $alvo, $_autorTipo, $_autorId, $nome, $emoji]);
            echo json_encode(['ok' => true, 'estado' => 'adicionada']);
        }
        exit;
    }
}

// ═══════════════ AVISOS PARA O PAINEL ═══════════════
// Lista os avisos gerados quando um aluno preenche anamnese/avaliacao, e permite
// marcar como lido. So professor — o aluno nao ve avisos de ninguem.
if ($action === 'avisos') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_avisos (
            idaviso INT AUTO_INCREMENT PRIMARY KEY,
            idatleta INT NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            detalhe VARCHAR(255) NULL,
            lido TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lido (lido), INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    if ($method === 'GET') {
        $rows = $pdo->query("SELECT idaviso, idatleta, tipo, titulo, detalhe, lido, created_at
                             FROM intus_avisos ORDER BY created_at DESC, idaviso DESC LIMIT 100")
                    ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_map(function ($r) {
            return [
                'idaviso'  => (int)$r['idaviso'],
                'idatleta' => (int)$r['idatleta'],
                'tipo'     => $r['tipo'],
                'titulo'   => $r['titulo'],
                'detalhe'  => $r['detalhe'] ?? '',
                'lido'     => (int)$r['lido'] === 1,
                'quando'   => $r['created_at'],
            ];
        }, $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();
        if (!empty($b['marcar_todos'])) {
            $pdo->exec("UPDATE intus_avisos SET lido = 1 WHERE lido = 0");
            echo json_encode(['ok' => true]); exit;
        }
        $idv = (int)($b['idaviso'] ?? 0);
        if ($idv <= 0) { http_response_code(400); echo json_encode(['error' => 'idaviso obrigatorio']); exit; }
        $pdo->prepare("UPDATE intus_avisos SET lido = 1 WHERE idaviso = ?")->execute([$idv]);
        echo json_encode(['ok' => true]); exit;
    }
}

// ═══════════════ CONTA AINDA EXISTE? ═══════════════
// O app do aluno guarda o login no navegador. Se a conta for excluida/recriada, o
// celular continua achando que e o id antigo e grava tudo numa conta fantasma
// (foi o que aconteceu com a anamnese do atleta #1). Este endpoint so responde
// se o id existe — nao devolve nenhum dado pessoal.
if ($action === 'atleta_existe') {
    $id = (int)($_GET['atleta'] ?? 0);
    if ($id <= 0) { echo json_encode(['existe' => false]); exit; }
    $tab = null;
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tab = $t; break; } catch (Throwable $e) {}
    }
    if (!$tab) { echo json_encode(['existe' => true, 'indeterminado' => true]); exit; }
    $col = null;
    foreach (['idatleta', 'idaluno', 'id'] as $c) {
        try { $pdo->query("SELECT `$c` FROM `$tab` LIMIT 1"); $col = $c; break; } catch (Throwable $e) {}
    }
    if (!$col) { echo json_encode(['existe' => true, 'indeterminado' => true]); exit; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM `$tab` WHERE `$col` = ? LIMIT 1");
        $st->execute([$id]);
        echo json_encode(['existe' => (bool)$st->fetch()]);
    } catch (Throwable $e) {
        // Na duvida, nunca deslogar o aluno.
        echo json_encode(['existe' => true, 'indeterminado' => true]);
    }
    exit;
}

// ═══════════════ AVISO AO PROFESSOR RESPONSAVEL ═══════════════
// Quando o aluno preenche anamnese ou qualquer formulario de avaliacao, quem
// precisa saber e o professor dele — nao uma caixa geral que ninguem abre.
// Descobre os responsaveis pela coluna professores_responsaveis do atleta e
// devolve os e-mails. Se nao houver vinculo, cai no endereco de notificacao.
function _catalogoDestinatariosDoAtleta(PDO $pdo, int $idatleta): array {
    $tabAt = null;
    foreach (['atleta','atletas','aluno','alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tabAt = $t; break; } catch (Throwable $e) {}
    }
    $nome = ''; $profs = [];
    if ($tabAt) {
        try {
            $st = $pdo->prepare("SELECT * FROM `$tabAt` WHERE idatleta = ? LIMIT 1");
            $st->execute([$idatleta]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $nome = $r['nome'] ?? ($r['nmatleta'] ?? '');
                $raw = $r['professores_responsaveis'] ?? '';
                if (is_string($raw) && $raw !== '' && $raw !== '[]') {
                    $tmp = json_decode($raw, true);
                    if (is_array($tmp)) $profs = array_map('intval', $tmp);
                }
            }
        } catch (Throwable $e) {}
    }
    $emails = [];
    if ($profs) {
        $tabUs = null;
        foreach (['professor','usuario','usuarios','professores'] as $t) {
            try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tabUs = $t; break; } catch (Throwable $e) {}
        }
        if ($tabUs) {
            $colId = null;
            foreach (['idusuario','idprofessor','id'] as $c) {
                try { $pdo->query("SELECT `$c` FROM `$tabUs` LIMIT 1"); $colId = $c; break; } catch (Throwable $e) {}
            }
            if ($colId) {
                $ph = implode(',', array_fill(0, count($profs), '?'));
                try {
                    $st = $pdo->prepare("SELECT email FROM `$tabUs` WHERE `$colId` IN ($ph)");
                    $st->execute($profs);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $e = trim((string)($u['email'] ?? ''));
                        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[] = $e;
                    }
                } catch (Throwable $e) {}
            }
        }
    }
    if (!$emails) {
        $cfg = @include __DIR__ . '/../config/smtp.php';
        $fb = (is_array($cfg) && !empty($cfg['notificar'])) ? $cfg['notificar'] : 'contato@intusfit.com.br';
        $emails[] = $fb;
    }
    return ['emails' => array_values(array_unique($emails)), 'nome' => $nome];
}

// Registra o aviso NO SISTEMA (aparece no painel) e dispara o e-mail.
function _catalogoAvisarProfessor(PDO $pdo, int $idatleta, string $oQue, int $qtdRespostas = 0): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_avisos (
            idaviso INT AUTO_INCREMENT PRIMARY KEY,
            idatleta INT NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            detalhe VARCHAR(255) NULL,
            lido TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lido (lido), INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    $info = _catalogoDestinatariosDoAtleta($pdo, $idatleta);
    $nome = $info['nome'] !== '' ? $info['nome'] : ('Aluno #' . $idatleta);
    $titulo  = $nome . ' preencheu: ' . $oQue;
    $detalhe = $qtdRespostas > 0 ? ($qtdRespostas . ' respostas') : '';

    // Segunda rede: nao repetir o MESMO aviso do MESMO aluno dentro de seis
    // horas. A primeira rede (so avisar quando o conteudo muda) resolve o caso
    // do reenvio automatico; esta pega o resto — aluno que salva tres vezes
    // seguidas ajustando uma frase nao precisa virar tres avisos iguais.
    try {
        $jaTem = $pdo->prepare("SELECT 1 FROM intus_avisos
                                WHERE idatleta = ? AND titulo = ? AND detalhe = ?
                                  AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) LIMIT 1");
        $jaTem->execute([$idatleta, mb_substr($titulo, 0, 160), mb_substr($detalhe, 0, 255)]);
        if ($jaTem->fetchColumn()) return;
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare("INSERT INTO intus_avisos (idatleta, tipo, titulo, detalhe) VALUES (?, ?, ?, ?)");
        $st->execute([$idatleta, 'avaliacao', mb_substr($titulo, 0, 160), mb_substr($detalhe, 0, 255)]);
    } catch (Throwable $e) {}

    $corpo  = $nome . " preencheu " . $oQue . " no app.\n\n";
    if ($qtdRespostas > 0) $corpo .= "Respostas preenchidas: " . $qtdRespostas . "\n";
    $corpo .= "Data: " . date('d/m/Y H:i') . "\n\n";
    $corpo .= "Abra o painel em Avaliacoes para ver o conteudo.\n";
    foreach ($info['emails'] as $para) {
        @_catalogoSendEmail($para, 'Nova avaliacao preenchida: ' . $nome, $corpo);
    }
}

// ═══════════════ ANAMNESE ═══════════════
if ($action === 'anamnese') {
    $idatleta = (int)($_GET['atleta'] ?? 0);
    $b = null;
    if ($idatleta <= 0) { $b = jsonBody(); $idatleta = (int)($b['_idatleta'] ?? $b['idatleta'] ?? 0); }

    // Aluno logado so pode ler/gravar a propria anamnese. Se o contexto de auth nao
    // souber dizer qual e o id dele, nao bloqueia (para nao quebrar o app) — o token
    // ja foi exigido acima, entao anonimo nao chega aqui.
    if ($_ehAluno) {
        // ── O DONO E O TOKEN, NAO O QUE O APLICATIVO PEDIU ───────────────────
        // Antes o id vinha da URL e so era comparado com o do token QUANDO desse
        // para resolver o do token. Se nao desse, passava. Agora nao se compara:
        // para sessao de aluno o id do token SUBSTITUI o que veio na requisicao.
        // Nao existe mais caminho, nem por erro nem por ma-fe, que grave a
        // anamnese de um aluno na conta de outro.
        $meu = (int)($_ctx['idatleta'] ?? 0);
        if ($meu <= 0) $meu = (int)($_ctx['idusuario'] ?? 0);
        if ($meu <= 0) {
            http_response_code(401);
            echo json_encode(['error' => 'sessao de aluno sem identidade']);
            exit;
        }
        if ($idatleta > 0 && $meu !== $idatleta && $method !== 'GET') {
            // Nao e so bloquear: registrar, porque isto indica aparelho com a
            // sessao de outra pessoa — o tipo de coisa que passa despercebida.
            @error_log('[intus] anamnese: token do atleta ' . $meu . ' tentou gravar no atleta ' . $idatleta);
        }
        $idatleta = $meu;
    }

    if ($method === 'GET') {
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }
        $st = $pdo->prepare("SELECT dados FROM intus_anamnese WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['dados']) {
            $dados = json_decode($row['dados'], true);
            echo is_array($dados) ? json_encode($dados, JSON_UNESCAPED_UNICODE) : json_encode(new stdClass());
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'POST' || $method === 'PUT') {
        if ($b === null) $b = jsonBody();
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }

        // ── O ALUNO PRECISA EXISTIR ─────────────────────────────────────────
        // Ate aqui o servidor gravava a anamnese no numero que viesse do
        // aplicativo, sem perguntar se aquele aluno existe. Foi assim que
        // nasceram as anamneses orfas nos ids 1 e 999999: respostas de gente de
        // verdade guardadas numa conta que nunca existiu, invisiveis no cadastro
        // de quem respondeu e aparecendo no painel como "atleta 1". Pior: como a
        // gravacao e por idatleta, a segunda pessoa a cair no mesmo numero
        // sobrescrevia as respostas da primeira.
        $_existe = false;
        foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $_t) {
            try {
                $_q = $pdo->prepare("SELECT 1 FROM `$_t` WHERE idatleta = ? LIMIT 1");
                $_q->execute([$idatleta]);
                $_existe = (bool)$_q->fetchColumn();
                break;
            } catch (Throwable $e) { /* tabela com outro nome: tenta a proxima */ }
        }
        if (!$_existe) {
            http_response_code(404);
            echo json_encode(['error' => 'aluno inexistente', 'idatleta' => $idatleta,
                              'detalhe' => 'A anamnese nao foi gravada porque este cadastro nao existe. Entre novamente no aplicativo.']);
            exit;
        }

        // ── QUEM GRAVOU ─────────────────────────────────────────────────────
        // Guardado com prefixo "_", entao nao conta como resposta nem aparece
        // como pergunta no painel. Serve para responder "quem respondeu isto?"
        // sem ter que adivinhar depois.
        if (is_array($b)) {
            $b['_auditoria'] = [
                'em'         => date('Y-m-d H:i:s'),
                'ctx_id'     => (int)($_ctx['idusuario'] ?? 0),
                'ctx_atleta' => (int)($_ctx['idatleta'] ?? 0),
                'aluno'      => $_ehAluno ? 'S' : 'N',
                'ip'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            ];
        }

        // ── GRAVACAO MESCLA, NAO SUBSTITUI ──────────────────────────────────
        // Este era o defeito mais caro da tela de avaliacoes. O aplicativo do
        // aluno tem quatro formularios que gravam na mesma anamnese: o
        // principal, o comportamental, o nutricional e o de rotina. Tres deles
        // enviavam so a propria secao — e o servidor fazia UPDATE do campo
        // inteiro. Resultado: o aluno respondia as 16 perguntas da anamnese,
        // depois respondia o comportamental, e as 16 respostas sumiam. Ficava
        // so a secao recem enviada. Era isso que os avisos mostravam: "16
        // respostas", depois "13", depois "2", depois "1" — o registro
        // encolhendo a cada formulario salvo.
        // Agora o que chega e mesclado por chave sobre o que ja existe. Enviar
        // uma secao nunca mais apaga as outras. Para substituir tudo de
        // proposito, mande "_substituir": true no corpo.
        $st = $pdo->prepare("SELECT dados FROM intus_anamnese WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $linha = $st->fetch(PDO::FETCH_ASSOC);

        $substituir = !empty($b['_substituir']);
        unset($b['_substituir']);

        // Reenvio de recuperacao: preenche SO o que falta no servidor, nunca
        // sobrescreve o que ja esta la. O aplicativo reenvia a copia guardada no
        // aparelho para recuperar respostas perdidas — e a copia do aparelho
        // pode ser mais VELHA que a do servidor. Sem esta trava, a recuperacao
        // vira regressao: respostas novas apagadas por respostas antigas.
        $somenteFaltantes = !empty($b['_somente_faltantes']);
        unset($b['_somente_faltantes']);

        $final = $b;
        if ($linha && !$substituir) {
            $antigo = json_decode((string)$linha['dados'], true);
            if (is_array($antigo)) {
                $final = $antigo;
                foreach ($b as $k => $v) {
                    if ($somenteFaltantes && array_key_exists($k, $final) && strpos((string)$k, '_') !== 0) continue;
                    // Secao vazia nao apaga secao preenchida: se o novo valor
                    // for vazio e ja existir conteudo, mantem o que estava.
                    $vazio = ($v === null || $v === '' || $v === [] ||
                              (is_string($v) && trim($v) === '') ||
                              (is_array($v) && count(array_filter($v, function ($z) {
                                  return !($z === null || $z === '' || (is_string($z) && trim($z) === ''));
                              })) === 0));
                    if ($vazio && array_key_exists($k, $final) && strpos((string)$k, '_') !== 0) continue;
                    $final[$k] = $v;
                }
            }
        }
        $b = $final;
        $dados = json_encode($final, JSON_UNESCAPED_UNICODE);

        // ── SO AVISA SE MUDOU ALGUMA COISA ──────────────────────────────────
        // O aplicativo reenvia a copia guardada no aparelho toda vez que abre —
        // e faz bem, foi assim que as respostas da Diely voltaram. So que cada
        // reenvio disparava um aviso novo no painel dizendo que o aluno "tinha
        // preenchido a anamnese", mesmo quando nada tinha mudado. O resultado
        // era o painel enchendo de aviso de resposta que ninguem escreveu.
        // A comparacao ignora o bloco de auditoria, que muda sempre por
        // natureza (data, IP, aparelho) e nao e resposta de ninguem.
        $_semRuido = function ($arr) {
            if (!is_array($arr)) return '';
            unset($arr['_auditoria']);
            ksort($arr);
            return json_encode($arr, JSON_UNESCAPED_UNICODE);
        };
        $_antes = $linha ? json_decode((string)$linha['dados'], true) : null;
        $_mudou = ($_semRuido($_antes) !== $_semRuido($final));

        if ($linha) {
            $pdo->prepare("UPDATE intus_anamnese SET dados = ? WHERE idatleta = ?")->execute([$dados, $idatleta]);
        } else {
            $pdo->prepare("INSERT INTO intus_anamnese (idatleta, dados) VALUES (?, ?)")->execute([$idatleta, $dados]);
        }
        // Conta respostas de verdade, inclusive as que estao dentro de secoes
        // (comportamental, nutricional, rotina). Antes uma secao inteira com 11
        // respostas contava como 1, e o aviso dizia "preencheu a anamnese (1
        // resposta)" para quem tinha escrito onze paragrafos.
        $_contar = function ($arr) use (&$_contar) {
            $n = 0;
            foreach ((array)$arr as $k => $v) {
                if (strpos((string)$k, '_') === 0) continue;
                if ($v === '' || $v === null || $v === []) continue;
                if (is_string($v) && trim($v) === '') continue;
                if (is_array($v)) { $n += $_contar($v); continue; }
                $n++;
            }
            return $n;
        };
        $_qtd = is_array($b) ? $_contar($b) : 0;
        if ($_mudou) {
            @_catalogoAvisarProfessor($pdo, $idatleta, 'a anamnese', $_qtd);
        }

        // "alterado" deixa o aplicativo saber que o reenvio nao acrescentou
        // nada, e parar de insistir.
        echo json_encode(['ok' => true, 'alterado' => $_mudou, 'respostas' => $_qtd]);
        exit;
    }
}

// Lista as anamneses (para o dashboard do professor detectar respostas novas)
if ($action === 'anamneses') {
    $rows = $pdo->query("SELECT idatleta, dados, updated_at FROM intus_anamnese ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $out = array_map(function ($r) {
        $d = null; if (!empty($r['dados'])) { $tmp = json_decode($r['dados'], true); if (is_array($tmp)) $d = $tmp; }
        // Conta so respostas de verdade: ignora campos internos (_timestamp, _idatleta) e vazios.
        $qtd = 0;
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                if (strpos((string)$k, '_') === 0) continue;
                if ($v === '' || $v === null || $v === []) continue;
                if (is_string($v) && trim($v) === '') continue;
                $qtd++;
            }
        }
        return [ 'idatleta' => (int)$r['idatleta'], 'updated_at' => $r['updated_at'] ?? null, 'campos_preenchidos' => $qtd, 'dados' => $d ];
    }, $rows);
    echo json_encode($out);
    exit;
}

if ($action === 'avatars') {
    $rows = $pdo->query("SELECT idatleta, avatar FROM intus_profile WHERE avatar IS NOT NULL AND avatar != ''")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) { $map[(int)$r['idatleta']] = $r['avatar']; }
    echo json_encode($map);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'action invalida', 'action' => $action]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'falha interna', 'detalhe' => _intusLogErro($e)]);
}
