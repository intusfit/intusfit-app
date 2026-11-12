<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/helpers.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// POST /api/auth.php?action=login_professor
// POST /api/auth.php?action=login_atleta
// POST /api/auth.php?action=logout

if ($method === 'POST' && $action === 'login_professor') {
    $data = getInput();
    $email = trim($data['email'] ?? '');
    $senha = trim($data['senha'] ?? '');

    if (!$email || !$senha) {
        jsonError('Email e senha são obrigatórios');
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM professor WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $prof = $stmt->fetch();

    // Nao havia limite nenhum de tentativas neste arquivo — nem para professor
    // nem para atleta. Forca bruta era so questao de tempo.
    @require_once __DIR__ . '/_rate_limit.php';
    if (function_exists('checkRateLimit')) { checkRateLimit($db, 'auth_login', 5, 900); }
    if (function_exists('checkRateLimitConta')) { checkRateLimitConta($db, 'auth_login_conta', (string)$email, 8, 900); }

    if (!$prof) {
        if (function_exists('recordAttempt')) recordAttempt($db, 'auth_login');
        if (function_exists('recordAttemptConta')) recordAttemptConta($db, 'auth_login_conta', (string)$email);
        jsonError('Credenciais inválidas', 401);
    }

    // Suporta senha em hash (novo) e texto puro (legado)
    // ── PASS-THE-HASH ───────────────────────────────────────────────────────
    // O segundo termo comparava a COLUNA com o que veio na requisicao. Depois
    // que a senha vira bcrypt, essa comparacao passa a ser verdadeira quando o
    // atacante envia O PROPRIO HASH como senha. Quem obtivesse o hash — por um
    // backup, por um dump, pela trilha de auditoria — entrava sem quebrar nada.
    // A migracao de senha antiga para bcrypt continua funcionando logo abaixo,
    // mas so quando a coluna NAO for um hash.
    $_ehHash = (strlen((string)$prof['senha']) >= 50 && preg_match('/^\$2[aby]\$/', (string)$prof['senha']));
    $valid = $_ehHash
        ? password_verify($senha, $prof['senha'])
        : hash_equals((string)$prof['senha'], (string)$senha);
    if (!$valid) {
        if (function_exists('recordAttemptConta')) recordAttemptConta($db, 'auth_login_conta', (string)$email);
        jsonError('Credenciais inválidas', 401);
    }

    // Migra para hash se ainda estiver em texto puro
    if (!$_ehHash && hash_equals((string)$prof['senha'], (string)$senha)) {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $db->prepare("UPDATE professor SET senha = ? WHERE idprofessor = ?")->execute([$hash, $prof['idprofessor']]);
    }

    $token = generateToken();
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $db->prepare("INSERT INTO sessao (token, tipo, ref_id, expires_at) VALUES (?, 'professor', ?, ?)")
       ->execute([$token, $prof['idprofessor'], $expires]);

    jsonResponse([
        'token' => $token,
        'tipo'  => 'professor',
        'nome'  => $prof['nmprofessor'],
        'expires_at' => $expires,
    ]);
}

elseif ($method === 'POST' && $action === 'login_atleta') {
    $data = getInput();
    $codacesso = trim($data['codacesso'] ?? '');
    $senha     = trim($data['senha'] ?? '');

    if (!$codacesso) {
        jsonError('Código de acesso é obrigatório');
    }

    $db = getDB();

    // ── MESMA BRECHA DO ACHADO S2 (11/11/2026), AQUI PIOR ──────────────────
    // O código de acesso identificava a conta, não havia limite de tentativas (só o login de professor tinha) e conta SEM senha entregava
    // sessão só com o código e ainda DEFINIA a senha de quem mandasse uma ("primeiro acesso"): quem adivinhasse um código de 4 dígitos
    // sequestrava a conta (o `me` devolve o e-mail da pessoa, e com ele e a senha nova entrava no login moderno).
    // Nenhum cliente deste repositório usa esta rota. Agora: limite por IP e por código, erro único (não confirma se o código existe),
    // senha SEMPRE exigida, e esta rota nunca grava senha nova.
    @require_once __DIR__ . '/_rate_limit.php';
    if (function_exists('checkRateLimit')) { checkRateLimit($db, 'auth_login_atleta', 5, 900); }
    if (function_exists('checkRateLimitConta')) { checkRateLimitConta($db, 'auth_login_atleta_conta', $codacesso, 8, 900); }

    $stmt = $db->prepare("SELECT * FROM atleta WHERE codacesso = ? AND stbloqueio != 'S' LIMIT 1");
    $stmt->execute([$codacesso]);
    $atleta = $stmt->fetch();

    $valid = false;
    if ($atleta && !empty($atleta['senha']) && $senha !== '') {
        // Mesmo defeito do login de professor — ver comentario acima.
        $_ehHashA = (strlen((string)$atleta['senha']) >= 50 && preg_match('/^\$2[aby]\$/', (string)$atleta['senha']));
        $valid = $_ehHashA
            ? password_verify($senha, $atleta['senha'])
            : hash_equals((string)$atleta['senha'], (string)$senha);
        if ($valid && !$_ehHashA) {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $db->prepare("UPDATE atleta SET senha = ? WHERE idatleta = ?")->execute([$hash, $atleta['idatleta']]);
        }
    }
    if (!$valid) {
        if (function_exists('recordAttempt')) recordAttempt($db, 'auth_login_atleta');
        if (function_exists('recordAttemptConta')) recordAttemptConta($db, 'auth_login_atleta_conta', $codacesso);
        jsonError('Credenciais inválidas', 401);
    }

    $token = generateToken();
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $db->prepare("INSERT INTO sessao (token, tipo, ref_id, expires_at) VALUES (?, 'atleta', ?, ?)")
       ->execute([$token, $atleta['idatleta'], $expires]);

    jsonResponse([
        'token'    => $token,
        'tipo'     => 'atleta',
        'nome'     => $atleta['nome'],
        'idatleta' => $atleta['idatleta'],
        'primeiro_acesso' => false,   // a rota não define mais senha: conta sem senha não entra por aqui
        'expires_at' => $expires,
    ]);
}

elseif ($method === 'POST' && $action === 'logout') {
    $session = requireAuth();
    $db = getDB();
    $db->prepare("DELETE FROM sessao WHERE token = ?")->execute([$session['token']]);
    jsonResponse(['message' => 'Logout realizado com sucesso']);
}

elseif ($method === 'GET' && $action === 'me') {
    $session = requireAuth();
    $db = getDB();

    if ($session['tipo'] === 'professor') {
        $stmt = $db->prepare("SELECT idprofessor, nmprofessor, email, tpacesso FROM professor WHERE idprofessor = ?");
        $stmt->execute([$session['ref_id']]);
        $user = $stmt->fetch();
    } else {
        $stmt = $db->prepare("SELECT idatleta, nome, email, genero, codacesso FROM atleta WHERE idatleta = ?");
        $stmt->execute([$session['ref_id']]);
        $user = $stmt->fetch();
    }

    jsonResponse(['tipo' => $session['tipo'], 'usuario' => $user]);
}

else {
    jsonError('Endpoint não encontrado', 404);
}
