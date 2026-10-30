<?php
/**
 * CDD: ponto de entrada da API (uma rota por parâmetro ?a=). Toda a regra está em _nucleo.php.
 * Ex.: api.php?a=login (POST), api.php?a=meus_modulos (GET, com o cabeçalho X-CDD-Token).
 */
@ini_set('display_errors', '0');   // a KingHost deixa ligado; um aviso do PHP vazaria caminhos e nomes de tabela
require_once __DIR__ . '/_nucleo.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, private, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

function cddIpCliente(): string {
    // Cópia da regra da Intus: só confia no cabeçalho do Cloudflare quando a conexão vem mesmo de um IP do Cloudflare.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && cddVemDoCloudflare($ip)) $ip = $cf;
    return $ip;
}
function cddVemDoCloudflare(string $ip): bool {
    static $faixas = ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20',
        '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];
    foreach ($faixas as $f) {
        [$base, $bits] = explode('/', $f);
        $a = @inet_pton($ip); $b = @inet_pton($base);
        if ($a === false || $b === false || strlen($a) !== strlen($b)) continue;
        $bits = (int)$bits; $bytes = intdiv($bits, 8); $resto = $bits % 8;
        if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) continue;
        if ($resto === 0) return true;
        $mask = (0xFF << (8 - $resto)) & 0xFF;
        if ((ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask)) return true;
    }
    return false;
}

try {
    $metodo = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($metodo === 'OPTIONS') { http_response_code(204); exit; }
    $acao = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['a'] ?? '')));
    $corpo = [];
    if ($metodo !== 'GET') {
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > 400000) { http_response_code(413); echo json_encode(['error' => 'corpo_grande', 'mensagem' => 'Pedido grande demais.']); exit; }
        $j = json_decode($raw, true);
        $corpo = is_array($j) ? $j : [];
    }
    $token = trim((string)($_SERVER['HTTP_X_CDD_TOKEN'] ?? ''));
    if ($token === '' && preg_match('/^Bearer\s+(\S+)/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''), $m)) $token = $m[1];

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTP_CF_VISITOR'] ?? '') !== '' && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false);
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    $pasta = rtrim(str_replace(chr(92), '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/cdd/api/api.php')))), '/');
    $base = ($https ? 'https://' : 'http://') . $host . $pasta;
    // Domínio próprio via Cloudflare Worker: o Worker avisa o endereço público; só vale este valor exato (não dá para apontar links para outro lugar).
    if (trim((string)($_SERVER['HTTP_X_CDD_BASE'] ?? '')) === 'https://codigodadefinicao.com.br/app') $base = 'https://codigodadefinicao.com.br/app';
    $ctx = ['ip' => cddIpCliente(), 'base' => $base, 'seed' => __DIR__ . '/dados/seed-conteudo.json'];

    [$status, $dados] = cddTratar(cddDB(), $metodo, $acao, $_GET, $corpo, $token, $ctx);
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[cdd] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'erro_interno', 'mensagem' => 'Algo deu errado no servidor. Tente de novo em instantes.']);
}
