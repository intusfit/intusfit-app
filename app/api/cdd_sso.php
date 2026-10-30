<?php
/**
 * Entrada do painel da Intus no painel do CDD, sem digitar senha de novo.
 *
 *   POST ?action=bilhete   (Authorization: Bearer <sessão do painel da Intus>)
 *        -> { ok, url }    url = https://codigodadefinicao.com.br/app/painel#sso=<bilhete>
 *
 * Quem pode: administrador ou quem tem a permissão "cdd_ver" (Usuários). Aluno nunca.
 * O bilhete vale 60 segundos e só pode ser usado uma vez; leva o e-mail e o nome de quem pediu, assinado com HMAC-SHA256 por um segredo que
 * só o servidor conhece. O CDD confere a assinatura, cria (se for a primeira vez) ou reconhece a conta de equipe com aquele e-mail e abre uma
 * sessão de 7 dias. Por isso o login só é pedido de novo depois de 7 dias, e mesmo assim basta clicar de novo no atalho do painel da Intus.
 *
 * O segredo mora no banco (intus_secrets, chave cdd_sso) e é criado aqui, na primeira vez. Enquanto o CDD usa o mesmo banco, a cópia dele
 * (cdd_config.sso_segredo) é gravada junto. Quando o CDD passar para o servidor próprio, o segredo precisa ser colocado nos dois lados
 * (ver CLAUDE.md, seção do CDD comercial). Este arquivo nunca devolve o segredo.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function _ssoResp(int $codigo, array $corpo): void {
    http_response_code($codigo);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/_cdd_sso.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') _ssoResp(405, ['ok' => false, 'erro' => 'Use POST.']);
$cfg = @include __DIR__ . '/../config/db.php';
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4", $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else { throw new Exception('sem credenciais'); }
} catch (Throwable $e) { _ssoResp(500, ['ok' => false, 'erro' => 'Falha na conexão com o banco.']); }

require_once __DIR__ . '/_auth_context.php';
$h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$h && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) $h = $v; } }
$tok = preg_match('/Bearer\s+(\S+)/i', (string)$h, $m) ? $m[1] : '';

try {
    $ctx = getAuthContext($pdo, $tok);
    $id = (int)($ctx['idusuario'] ?? 0);
    if ($id <= 0 || !empty($ctx['is_aluno'])) _ssoResp(401, ['ok' => false, 'erro' => 'Entre no painel da Intus primeiro.']);
    $pode = !empty($ctx['admin']) || (function_exists('usuarioTemPermissao') && usuarioTemPermissao($pdo, $id, 'cdd_ver'));
    if (!$pode) _ssoResp(403, ['ok' => false, 'erro' => 'Seu usuário não tem acesso ao painel do CDD. Peça ao administrador a permissão "Acessar o painel do CDD".']);
    if ((string)($_GET['action'] ?? '') !== 'bilhete') _ssoResp(404, ['ok' => false, 'erro' => 'Ação desconhecida.']);

    // nome e e-mail de quem pediu (a tabela de usuários tem nomes de coluna diferentes entre instalações)
    $tab = null;
    foreach (['professor', 'usuario', 'usuarios', 'professores'] as $t) { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tab = $t; break; } catch (Throwable $e) {} }
    if (!$tab) _ssoResp(500, ['ok' => false, 'erro' => 'Tabela de usuários não encontrada.']);
    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$tab`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $pick = function (array $c) use ($cols) { foreach ($c as $x) if (in_array($x, $cols, true)) return $x; return null; };
    $cId = $pick(['idusuario', 'idprofessor', 'id']); $cNome = $pick(['nome', 'nmprofessor', 'nmusuario', 'name']); $cEmail = $pick(['email']);
    if (!$cId || !$cEmail) _ssoResp(500, ['ok' => false, 'erro' => 'Não encontrei o e-mail do usuário.']);
    $st = $pdo->prepare("SELECT " . ($cNome ? "`$cNome`" : "''") . " AS nome, `$cEmail` AS email FROM `$tab` WHERE `$cId` = ? LIMIT 1"); $st->execute([$id]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    $email = strtolower(trim((string)($u['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) _ssoResp(422, ['ok' => false, 'erro' => 'Seu usuário da Intus não tem um e-mail válido cadastrado. Corrija em Usuários.']);

    $seg = cddSsoSegredo($pdo);
    $bilhete = cddSsoMontar($seg, $email, trim((string)($u['nome'] ?? '')) ?: $email, $id);
    _ssoResp(200, ['ok' => true, 'url' => CDD_SSO_DESTINO . '#sso=' . $bilhete]);
} catch (Throwable $e) {
    @error_log('[cdd sso] ' . $e->getMessage());
    _ssoResp(500, ['ok' => false, 'erro' => 'Não consegui abrir o CDD agora.']);
}
