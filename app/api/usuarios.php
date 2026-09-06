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
 * Endpoint CRUD de usuarios/professores — fonte de verdade dos professores.
 *
 * Detecta dinamicamente o schema (tabela professor/usuario) e mapeia colunas
 * (id, nome, email, senha, admin, ativo, permissoes). Senhas sao gravadas
 * com bcrypt. Validacao de login no auth.php tambem aceita bcrypt.
 *
 * Rotas:
 *   GET    /api/usuarios.php                 -> lista todos (sem senha)
 *   GET    /api/usuarios.php?id=X            -> retorna 1 usuario
 *   POST   /api/usuarios.php?action=criar    {nome,email,senha,admin,ativo,permissoes}
 *   POST   /api/usuarios.php?action=editar&id=X  {nome?,email?,senha?,admin?,ativo?,permissoes?}
 *   DELETE /api/usuarios.php?id=X            -> remove (admin mestre nao pode)
 *   POST   /api/usuarios.php?action=validar  {email,senha} -> valida login (uso interno)
 *
 * Auth: Bearer token VALIDO (sessao real ou token legado reconhecido).
 * Ate 14/08/2026 este arquivo aceitava "qualquer Bearer nao vazio". Isso queria
 * dizer que a palavra "xxxx" no cabecalho Authorization dava direito de LISTAR
 * todos os professores com e-mail, CRIAR um usuario administrador novo, TROCAR
 * a senha de qualquer um e EXCLUIR contas. Nao era preciso saber nenhuma senha.
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Auth minima ----------
function bearerToken() {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}

// Ações públicas (sem token) usadas pelo login/reset
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$is_validar = ($method === 'POST' && $action === 'validar');
$is_reset   = ($method === 'POST' && $action === 'reset_senha');
$is_check_email = false; // era publico e servia so para enumerar e-mails; agora exige token
$is_send_reset  = ($method === 'POST' && $action === 'send_reset_notification');
$is_request_reset = ($method === 'POST' && $action === 'request_reset');
$is_verify_otp = ($method === 'POST' && $action === 'verify_otp');

$_ehPublica = ($is_validar || $is_reset || $is_send_reset || $is_request_reset || $is_verify_otp);
if (!$_ehPublica) {
    $tok = bearerToken();
    if ($tok === '') {
        http_response_code(401);
        echo json_encode(['error' => 'token ausente']);
        exit;
    }
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
    echo json_encode(['error' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Autorizacao ----------
// O token so era conferido quanto a EXISTIR. Nada aqui perguntava QUEM ele era.
// Agora o token precisa corresponder a um usuario real, e cada operacao tem
// dono: ler a equipe qualquer usuario logado pode; criar, editar outra pessoa,
// mudar permissao e excluir sao exclusivos de administrador; cada professor
// mexe no proprio perfil. Aluno continua vendo os nomes dos professores na
// Central, mas sem os e-mails deles.
$_ctxU = ['idusuario' => 0, 'admin' => false, 'is_aluno' => false];
if (!$_ehPublica) {
    if (@file_exists(__DIR__ . '/_auth_context.php')) {
        @require_once __DIR__ . '/_auth_context.php';
        if (function_exists('getAuthContext')) {
            try { $c = getAuthContext($pdo, $tok); if (is_array($c)) $_ctxU = array_merge($_ctxU, $c); }
            catch (Throwable $e) { /* mantem contexto vazio = nega */ }
        }
    }
    if ((int)($_ctxU['idusuario'] ?? 0) <= 0) {
        http_response_code(401);
        echo json_encode(['error' => 'token invalido']);
        exit;
    }
}
$_uAdmin  = !empty($_ctxU['admin']);
$_uAluno  = !empty($_ctxU['is_aluno']);
$_uMeuId  = (int)($_ctxU['idusuario'] ?? 0);

// Nega e encerra. Usada nas rotas de escrita.
$_negarU = function ($codigo, $msg) {
    http_response_code($codigo);
    echo json_encode(['error' => $msg]);
    exit;
};

// ---------- Detectar tabela e colunas ----------
$tabela = null;
foreach (['professor', 'usuario', 'usuarios', 'professores'] as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        $tabela = $t;
        break;
    } catch (Throwable $e) { /* nao existe */ }
}
if (!$tabela) {
    http_response_code(500);
    echo json_encode(['error' => 'tabela nao encontrada']);
    exit;
}

$colunas = [];
$stmt = $pdo->query("SHOW COLUMNS FROM `$tabela`");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $colunas[] = $row['Field'];

function pickCol(array $cols, array $candidates) {
    foreach ($candidates as $c) if (in_array($c, $cols)) return $c;
    return null;
}
$col_id    = pickCol($colunas, ['idusuario','idprofessor','id']);
$col_nome  = pickCol($colunas, ['nome','nmprofessor','nmusuario','name']);
$col_email = pickCol($colunas, ['email']);
$col_senha = pickCol($colunas, ['senha','password','hash']);
$col_admin = pickCol($colunas, ['admin','tpacesso','tipo','is_admin']);
$col_ativo = pickCol($colunas, ['stativo','ativo']);
$col_perms = pickCol($colunas, ['permissoes','permissions']);

// Auto-cria coluna de permissões se não existir
if (!$col_perms) {
    try {
        $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `permissoes` TEXT NULL DEFAULT NULL");
        $col_perms = 'permissoes';
        $colunas[] = 'permissoes';
    } catch (Throwable $e) { /* ignore */ }
}

if (!$col_id || !$col_email || !$col_senha || !$col_nome) {
    http_response_code(500);
    echo json_encode([
        'error' => 'schema incompativel',
        'tabela' => $tabela,
        'colunas' => $colunas,
        'mapa' => compact('col_id','col_nome','col_email','col_senha','col_admin','col_ativo','col_perms'),
    ]);
    exit;
}

// ---------- Helpers ----------
function inAdmin($col_admin, $admin_bool) {
    if ($col_admin === 'tpacesso') return $admin_bool ? 'A' : 'P';
    if ($col_admin === 'is_admin') return $admin_bool ? 1 : 0;
    return $admin_bool ? 'S' : 'N';
}
function outAdmin($col_admin, $val) {
    if ($col_admin === 'tpacesso') return ($val === 'A');
    if ($col_admin === 'is_admin') return (int)$val === 1;
    return ($val === 'S' || $val === '1' || $val === 1 || $val === true);
}
function inAtivo($val) { return $val ? 'S' : 'N'; }
function outAtivo($val) { return ($val === 'S' || $val === null); /* null = sem coluna -> ativo */ }
function permsAllTrue() {
    return [
        'ex_incluir'=>true,'ex_editar'=>true,'ex_excluir'=>true,
        'al_incluir'=>true,'al_editar'=>true,'al_excluir'=>true,
        'tr_incluir'=>true,'tr_editar'=>true,'tr_excluir'=>true,
        'at_incluir'=>true,'at_editar'=>true,
        'mt_gerir'=>true,'av_gerir'=>true,
        'fin_ver'=>true,'fin_caixa'=>true,
    ];
}
function permsAllFalse() {
    $p = permsAllTrue();
    foreach ($p as $k=>$v) $p[$k] = false;
    return $p;
}
function rowToUsuario($row, $colmap) {
    $id    = $row[$colmap['col_id']];
    $nome  = $row[$colmap['col_nome']] ?? '';
    $email = $row[$colmap['col_email']] ?? '';
    $admin = $colmap['col_admin'] ? outAdmin($colmap['col_admin'], $row[$colmap['col_admin']] ?? null) : false;
    $ativo = $colmap['col_ativo'] ? outAtivo($row[$colmap['col_ativo']] ?? null) : true;

    $perms = null;
    if ($colmap['col_perms']) {
        $raw = $row[$colmap['col_perms']] ?? null;
        if (is_string($raw) && $raw !== '') {
            $tmp = json_decode($raw, true);
            if (is_array($tmp)) $perms = $tmp;
        }
    }
    if ($admin) $perms = permsAllTrue();
    elseif (!$perms) $perms = permsAllFalse();

    // Meta from intus_user_meta
    $cargo = '';
    $instagram = '';
    $bio = '';
    global $pdo;
    try {
        $stMeta = $pdo->prepare("SELECT cargo, instagram, bio FROM intus_user_meta WHERE idusuario = ?");
        $stMeta->execute([$id]);
        $rMeta = $stMeta->fetch(PDO::FETCH_ASSOC);
        if ($rMeta) {
            $cargo = $rMeta['cargo'] ?? '';
            $instagram = $rMeta['instagram'] ?? '';
            $bio = $rMeta['bio'] ?? '';
        }
    } catch (Throwable $e) {}

    return [
        'idusuario'  => (int)$id,
        'nome'       => $nome,
        'email'      => $email,
        'admin'      => $admin,
        'ativo'      => $ativo,
        'permissoes' => $perms,
        'cargo'      => $cargo,
        'instagram'  => $instagram,
        'bio'        => $bio,
    ];
}

$colmap = compact('col_id','col_nome','col_email','col_senha','col_admin','col_ativo','col_perms');

// ---------- Body parser (cached — php://input can only be read once) ----------
$_cachedBody = null;
function readJsonBody() {
    global $_cachedBody;
    if ($_cachedBody !== null) return $_cachedBody;
    $raw = file_get_contents('php://input');
    if (!$raw) { $_cachedBody = []; return []; }
    $j = json_decode($raw, true);
    $_cachedBody = is_array($j) ? $j : [];
    return $_cachedBody;
}

// Auto-create user meta table
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_user_meta (
        idusuario INT PRIMARY KEY,
        cargo VARCHAR(100) DEFAULT '',
        instagram VARCHAR(200) DEFAULT '',
        bio TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Add columns if table already exists without them
    try { $pdo->exec("ALTER TABLE intus_user_meta ADD COLUMN instagram VARCHAR(200) DEFAULT '' AFTER cargo"); } catch (Throwable $e2) {}
    try { $pdo->exec("ALTER TABLE intus_user_meta ADD COLUMN bio TEXT AFTER instagram"); } catch (Throwable $e2) {}
} catch (Throwable $e) {}

// ---------- ROTAS ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

try {
    // ===== POST check_email (sem token; verifica se email existe) =====
    if ($method === 'POST' && $action === 'check_email') {
        $body = readJsonBody();
        $email = strtolower(trim($body['email'] ?? ''));
        if ($email === '') {
            echo json_encode(['exists' => false]);
            exit;
        }
        $st = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $st->execute([$email]);
        echo json_encode(['exists' => (bool)$st->fetchColumn()]);
        exit;
    }

    // ===== POST send_reset_notification (legado — mantido por compat) =====
    if ($is_send_reset) {
        echo json_encode(['ok' => true]);
        exit;
    }

    // ===== POST request_reset (envia OTP por email) =====
    if ($is_request_reset) {
        require_once __DIR__ . '/_rate_limit.php';
        checkRateLimit($pdo, 'reset_otp_prof', 3, 3600);

        $body = readJsonBody();
        $email = strtolower(trim($body['email'] ?? ''));
        if ($email === '') {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'email ausente']);
            exit;
        }

        // Verificar se email existe (resposta neutra)
        $st = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $st->execute([$email]);
        if (!$st->fetchColumn()) {
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

        // Enviar email com OTP via SMTP
        $smtpCfg = @include __DIR__ . '/../config/smtp.php';
        if (!is_array($smtpCfg)) $smtpCfg = [];
        $smtpHost = $smtpCfg['host'] ?? 'smtp.intusfit.com.br';
        $smtpUser = $smtpCfg['user'] ?? 'contato@intusfit.com.br';
        $smtpPass = $smtpCfg['pass'] ?? '';
        $fromName = $smtpCfg['from_name'] ?? 'Intus Fit';

        $assunto = "=?UTF-8?B?" . base64_encode("Código de verificação - Intus Fit") . "?=";
        $corpoEmail  = "Seu código de verificação para redefinir a senha é:\n\n";
        $corpoEmail .= "    $code\n\n";
        $corpoEmail .= "Este código expira em 10 minutos.\n";
        $corpoEmail .= "Se você não solicitou isso, ignore este email.\n";

        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $sock = @stream_socket_client("ssl://$smtpHost:465", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if ($sock) {
            $read = function() use ($sock) { $r = ''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
            $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
            $read();
            $send("EHLO intusfit.com.br");
            $send("AUTH LOGIN");
            $send(base64_encode($smtpUser));
            $send(base64_encode($smtpPass));
            $send("MAIL FROM:<$smtpUser>");
            $send("RCPT TO:<$email>");
            $send("DATA");
            $msg = "From: $fromName <$smtpUser>\r\nTo: $email\r\nSubject: $assunto\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n$corpoEmail";
            $send($msg . "\r\n.");
            $send("QUIT");
            fclose($sock);
        } else {
            @mail($email, "Código de verificação - Intus Fit", $corpoEmail, "From: $fromName <$smtpUser>");
        }

        recordAttempt($pdo, 'reset_otp_prof');
        echo json_encode(['ok'=>true, 'msg'=>'Se o email existir, um codigo sera enviado.']);
        exit;
    }

    // ===== POST verify_otp =====
    if ($is_verify_otp) {
        $body = readJsonBody();
        $email = strtolower(trim($body['email'] ?? ''));
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

    // ===== POST reset_senha (requer OTP verificado) =====
    if ($is_reset) {
        $body = readJsonBody();
        $email = strtolower(trim($body['email'] ?? ''));
        $senha = (string)($body['senha'] ?? '');
        if ($email === '' || strlen($senha) < 6) {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'email ou senha invalidos (min 6 chars)']);
            exit;
        }

        // Exigir OTP verificado antes de permitir troca de senha
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

    // ===== POST validar (login) =====
    if ($is_validar) {
        require_once __DIR__ . '/_rate_limit.php';
        checkRateLimit($pdo, 'prof_login', 5, 900);

        $body = readJsonBody();
        $email = strtolower(trim($body['email'] ?? ''));
        $senha = (string)($body['senha'] ?? '');
        if ($email === '' || $senha === '') {
            http_response_code(400);
            echo json_encode(['ok'=>false, 'erro'=>'email/senha ausente']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            recordAttempt($pdo, 'prof_login');
            http_response_code(401);
            echo json_encode(['ok'=>false, 'erro'=>'credenciais invalidas']);
            exit;
        }
        $hashOuPlain = (string)$row[$col_senha];
        $valido = false;
        if (strlen($hashOuPlain) >= 50 && (strpos($hashOuPlain, '$2y$') === 0 || strpos($hashOuPlain, '$2a$') === 0 || strpos($hashOuPlain, '$2b$') === 0)) {
            $valido = password_verify($senha, $hashOuPlain);
        } else {
            // legacy plain ou outro hash — comparacao direta + reidrata para bcrypt
            $valido = hash_equals($hashOuPlain, $senha);
            if ($valido) {
                try {
                    $newHash = password_hash($senha, PASSWORD_BCRYPT);
                    $upd = $pdo->prepare("UPDATE `$tabela` SET $col_senha = ? WHERE $col_id = ?");
                    $upd->execute([$newHash, $row[$col_id]]);
                } catch (Throwable $e) { /* upgrade silencioso */ }
            }
        }
        if (!$valido) {
            recordAttempt($pdo, 'prof_login');
            http_response_code(401);
            echo json_encode(['ok'=>false, 'erro'=>'credenciais invalidas']);
            exit;
        }
        clearAttempts($pdo, 'prof_login');
        if ($col_ativo && ($row[$col_ativo] ?? 'S') === 'N') {
            http_response_code(403);
            echo json_encode(['ok'=>false, 'erro'=>'usuario desativado']);
            exit;
        }
        $u = rowToUsuario($row, $colmap);

        // Gerar token server-side seguro
        require_once __DIR__ . '/_sessions.php';
        $isAdmin = !empty($u['admin']);
        $serverToken = createSession($pdo, (int)$u['idusuario'], 'prof', $u['nome'] ?? '', $isAdmin, 7);
        if ($serverToken) $u['server_token'] = $serverToken;

        echo json_encode(['ok'=>true, 'usuario'=>$u]);
        exit;
    }

    // ===== GET listar / GET por id =====
    if ($method === 'GET') {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error'=>'nao encontrado']); exit; }
            $u1 = rowToUsuario($row, $colmap);
            // A Central do aluno usa esta rota para mostrar nome, cargo, instagram
            // e bio do professor. Nao precisa do e-mail — e ele estava indo junto.
            if ($_uAluno) { $u1['email'] = ''; unset($u1['permissoes'], $u1['ativo']); }
            echo json_encode(['ok'=>true, 'usuario'=>$u1]);
            exit;
        }
        $rows = $pdo->query("SELECT * FROM `$tabela` ORDER BY $col_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(fn($r) => rowToUsuario($r, $colmap), $rows);
        if ($_uAluno) {
            $out = array_map(function ($u) {
                $u['email'] = '';
                unset($u['permissoes'], $u['ativo']);
                return $u;
            }, $out);
        }
        echo json_encode(['ok'=>true, 'usuarios'=>$out]);
        exit;
    }

    // ===== POST criar =====
    if ($method === 'POST' && $action === 'criar') {
        // Criar usuario e criar ACESSO ao painel. Com a regra antiga, qualquer
        // token bastava — daria para criar uma conta admin nova e entrar por ela.
        if (!$_uAdmin) $_negarU(403, 'apenas administrador pode criar usuarios');
        $body = readJsonBody();
        $nome  = trim((string)($body['nome'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $senha = (string)($body['senha'] ?? '');
        $admin = (bool)($body['admin'] ?? false);
        $ativo = array_key_exists('ativo', $body) ? (bool)$body['ativo'] : true;
        $perms = $body['permissoes'] ?? null;
        if (!is_array($perms)) $perms = $admin ? permsAllTrue() : permsAllFalse();

        if (!$nome || !$email || !$senha) {
            http_response_code(400);
            echo json_encode(['error'=>'nome/email/senha obrigatorios']);
            exit;
        }
        // Verificar duplicata
        $st = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? LIMIT 1");
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['error'=>'email ja cadastrado']);
            exit;
        }
        $hash = password_hash($senha, PASSWORD_BCRYPT);
        $cols = [$col_nome, $col_email, $col_senha];
        $vals = [$nome, $email, $hash];
        $phs  = ['?','?','?'];
        if ($col_admin) { $cols[]=$col_admin; $vals[]=inAdmin($col_admin, $admin); $phs[]='?'; }
        if ($col_ativo) { $cols[]=$col_ativo; $vals[]=inAtivo($ativo); $phs[]='?'; }
        if ($col_perms) { $cols[]=$col_perms; $vals[]=json_encode($admin ? permsAllTrue() : $perms); $phs[]='?'; }
        $sql = "INSERT INTO `$tabela` (".implode(',', $cols).") VALUES (".implode(',', $phs).")";
        $pdo->prepare($sql)->execute($vals);
        $newId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $st->execute([$newId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'usuario'=>rowToUsuario($row, $colmap)]);
        exit;
    }

    // ===== POST/PUT editar =====
    if (($method === 'POST' || $method === 'PUT') && $action === 'editar') {
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'id obrigatorio']); exit; }
        // Sem esta trava, um token qualquer trocava a senha do administrador —
        // e tambem podia marcar a si proprio como admin.
        if (!$_uAdmin && !($_uMeuId > 0 && !$_uAluno && $id === $_uMeuId)) {
            $_negarU(403, 'sem permissao para editar este usuario');
        }
        $body = readJsonBody();
        if (!$_uAdmin) {
            // Editando o proprio cadastro: nome, e-mail e senha sim; nivel de
            // acesso nao. Quem promove alguem a administrador e o administrador.
            unset($body['admin'], $body['ativo'], $body['permissoes']);
        }
        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $st->execute([$id]);
        $atual = $st->fetch(PDO::FETCH_ASSOC);
        if (!$atual) { http_response_code(404); echo json_encode(['error'=>'nao encontrado']); exit; }

        $sets = []; $vals = [];
        if (array_key_exists('nome', $body)) {
            $sets[] = "$col_nome = ?"; $vals[] = trim((string)$body['nome']);
        }
        if (array_key_exists('email', $body)) {
            $novoEmail = strtolower(trim((string)$body['email']));
            if ($novoEmail !== strtolower($atual[$col_email])) {
                $st2 = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER($col_email) = ? AND $col_id <> ? LIMIT 1");
                $st2->execute([$novoEmail, $id]);
                if ($st2->fetchColumn()) { http_response_code(409); echo json_encode(['error'=>'email ja cadastrado']); exit; }
            }
            $sets[] = "$col_email = ?"; $vals[] = $novoEmail;
        }
        if (!empty($body['senha'])) {
            $sets[] = "$col_senha = ?"; $vals[] = password_hash((string)$body['senha'], PASSWORD_BCRYPT);
        }
        $isAdminAgora = array_key_exists('admin', $body) ? (bool)$body['admin']
                        : ($col_admin ? outAdmin($col_admin, $atual[$col_admin]) : false);
        if ($col_admin && array_key_exists('admin', $body)) {
            $sets[] = "$col_admin = ?"; $vals[] = inAdmin($col_admin, (bool)$body['admin']);
        }
        if ($col_ativo && array_key_exists('ativo', $body)) {
            $sets[] = "$col_ativo = ?"; $vals[] = inAtivo((bool)$body['ativo']);
        }
        if ($col_perms && (array_key_exists('permissoes', $body) || array_key_exists('admin', $body))) {
            $perms = $body['permissoes'] ?? null;
            if (!is_array($perms)) {
                // mantem perms atuais
                $cur = $atual[$col_perms] ?? null;
                $perms = is_string($cur) ? (json_decode($cur, true) ?: permsAllFalse()) : permsAllFalse();
            }
            if ($isAdminAgora) $perms = permsAllTrue();
            $sets[] = "$col_perms = ?"; $vals[] = json_encode($perms);
        }
        if (!$sets) { echo json_encode(['ok'=>true, 'noop'=>true]); exit; }

        $vals[] = $id;
        $sql = "UPDATE `$tabela` SET " . implode(', ', $sets) . " WHERE $col_id = ?";
        $pdo->prepare($sql)->execute($vals);

        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['ok'=>true, 'usuario'=>rowToUsuario($row, $colmap)]);
        exit;
    }

    // ===== META DO USUARIO (cargo, instagram, bio) =====
    if ($action === 'cargo' || $action === 'user_meta') {
        $uid = (int)($id ?: ($_GET['uid'] ?? 0));
        if (!$uid) { $b = readJsonBody(); $uid = (int)($b['idusuario'] ?? 0); }
        if (!$uid) { http_response_code(400); echo json_encode(['error'=>'idusuario obrigatorio']); exit; }

        if ($method === 'GET') {
            $st = $pdo->prepare("SELECT cargo, instagram, bio FROM intus_user_meta WHERE idusuario = ?");
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            echo json_encode($row ?: ['cargo'=>'','instagram'=>'','bio'=>'']);
            exit;
        }

        if ($method === 'POST' || $method === 'PUT') {
            if (!$_uAdmin && !($_uMeuId > 0 && !$_uAluno && $uid === $_uMeuId)) {
                $_negarU(403, 'sem permissao para alterar este perfil');
            }
            $b = readJsonBody();
            $cargo = trim((string)($b['cargo'] ?? ''));
            $instagram = trim((string)($b['instagram'] ?? ''));
            $bio = trim((string)($b['bio'] ?? ''));
            $st = $pdo->prepare("SELECT 1 FROM intus_user_meta WHERE idusuario = ?");
            $st->execute([$uid]);
            if ($st->fetch()) {
                $pdo->prepare("UPDATE intus_user_meta SET cargo = ?, instagram = ?, bio = ? WHERE idusuario = ?")->execute([$cargo, $instagram, $bio, $uid]);
            } else {
                $pdo->prepare("INSERT INTO intus_user_meta (idusuario, cargo, instagram, bio) VALUES (?, ?, ?, ?)")->execute([$uid, $cargo, $instagram, $bio]);
            }
            echo json_encode(['ok'=>true]);
            exit;
        }
    }

    // ===== AVATAR DO USUARIO =====
    if ($action === 'avatar' || $action === 'avatars_usuarios') {
        // Auto-create table
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_user_avatar (
            idusuario INT PRIMARY KEY,
            avatar LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if ($action === 'avatars_usuarios') {
            $rows = $pdo->query("SELECT idusuario, avatar FROM intus_user_avatar WHERE avatar IS NOT NULL AND avatar != ''")->fetchAll(PDO::FETCH_ASSOC);
            $map = [];
            foreach ($rows as $r) { $map[(int)$r['idusuario']] = $r['avatar']; }
            echo json_encode($map);
            exit;
        }

        $uid = (int)($id ?: ($_GET['uid'] ?? 0));
        if (!$uid) { $b = readJsonBody(); $uid = (int)($b['idusuario'] ?? 0); }
        if (!$uid) { http_response_code(400); echo json_encode(['error'=>'idusuario obrigatorio']); exit; }

        if ($method === 'GET') {
            $st = $pdo->prepare("SELECT avatar FROM intus_user_avatar WHERE idusuario = ?");
            $st->execute([$uid]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['avatar' => $row ? $row['avatar'] : null]);
            exit;
        }
        if ($method === 'POST' || $method === 'PUT') {
            if (!$_uAdmin && !($_uMeuId > 0 && !$_uAluno && $uid === $_uMeuId)) {
                $_negarU(403, 'sem permissao para alterar esta foto');
            }
            $b = readJsonBody();
            $avatar = $b['avatar'] ?? null;
    $avatar = _intusImagemOk($avatar);
    if ($avatar === false) { http_response_code(413); echo json_encode(['error' => 'imagem invalida ou grande demais (max 3 MB)']); exit; }
            $st = $pdo->prepare("SELECT 1 FROM intus_user_avatar WHERE idusuario = ?");
            $st->execute([$uid]);
            if ($st->fetch()) {
                $pdo->prepare("UPDATE intus_user_avatar SET avatar = ? WHERE idusuario = ?")->execute([$avatar, $uid]);
            } else {
                $pdo->prepare("INSERT INTO intus_user_avatar (idusuario, avatar) VALUES (?, ?)")->execute([$uid, $avatar]);
            }
            echo json_encode(['ok'=>true]);
            exit;
        }
    }

    // ===== DELETE =====
    if ($method === 'DELETE' || ($method === 'POST' && $action === 'excluir')) {
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'id obrigatorio']); exit; }
        if (!$_uAdmin) $_negarU(403, 'apenas administrador pode excluir usuarios');
        // Conta mestra (id=1) nao pode ser excluida
        if ($id === 1) {
            http_response_code(403);
            echo json_encode(['error'=>'conta mestra nao pode ser excluida']);
            exit;
        }
        $st = $pdo->prepare("DELETE FROM `$tabela` WHERE $col_id = ?");
        $st->execute([$id]);
        echo json_encode(['ok'=>true, 'removed'=>$st->rowCount()]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error'=>'rota nao reconhecida', 'method'=>$method, 'action'=>$action]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'falha', 'detalhe' => _intusLogErro($e)]);
}
