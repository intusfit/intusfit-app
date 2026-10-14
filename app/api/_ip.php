<?php
/**
 * IP REAL DE QUEM ACESSA E LOCALIZAÇÃO (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Com o Cloudflare na frente do site, o REMOTE_ADDR passa a ser o IP de um servidor do Cloudflare, e o IP do visitante vem no cabeçalho
 * CF-Connecting-IP. Esse cabeçalho só pode ser CONFIADO quando a conexão vem mesmo de um IP do Cloudflare: qualquer pessoa pode mandar
 * "CF-Connecting-IP: 1.2.3.4" direto ao servidor para fugir da trava de tentativas. Por isso só aceitamos o cabeçalho quando REMOTE_ADDR
 * está nas faixas publicadas do Cloudflare (https://www.cloudflare.com/ips/). Sem Cloudflare, tudo continua como era (REMOTE_ADDR).
 *
 * intusClientIp()   o IP real do visitante.
 * intusClientGeo()  ['pais','regiao','cidade','fuso'] vindos do Cloudflare (só existem com o Cloudflare ligado e a regra "Adicionar cabeçalhos
 *                   de localização do visitante" ativa); vazio sem ele.
 */

function _ipDentroDe(string $ip, string $cidr): bool
{
    [$base, $bits] = explode('/', $cidr) + [1 => null];
    $a = @inet_pton($ip); $b = @inet_pton($base);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;
    $bits = (int)$bits;
    $bytes = intdiv($bits, 8); $resto = $bits % 8;
    if ($bytes > 0 && substr($a, 0, $bytes) !== substr($b, 0, $bytes)) return false;
    if ($resto === 0) return true;
    $mask = (0xFF << (8 - $resto)) & 0xFF;
    return (ord($a[$bytes]) & $mask) === (ord($b[$bytes]) & $mask);
}

/** A conexão chegou de um servidor do Cloudflare? (faixas publicadas em https://www.cloudflare.com/ips/) */
function intusVemDoCloudflare(): bool
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') return false;
    static $faixas = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20',
        '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];
    foreach ($faixas as $f) { if (_ipDentroDe($ip, $f)) return true; }
    return false;
}

function intusClientIp(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && intusVemDoCloudflare()) $ip = $cf;
    return $ip;
}

function intusClientGeo(): array
{
    if (!intusVemDoCloudflare()) return ['pais' => '', 'regiao' => '', 'cidade' => '', 'fuso' => ''];
    $g = function ($k) { return substr(trim((string)($_SERVER[$k] ?? '')), 0, 80); };
    $pais = strtoupper($g('HTTP_CF_IPCOUNTRY'));
    if ($pais === 'XX' || $pais === 'T1') $pais = '';   // desconhecido / rede Tor
    return ['pais' => $pais, 'regiao' => $g('HTTP_CF_REGION'), 'cidade' => $g('HTTP_CF_IPCITY'), 'fuso' => $g('HTTP_CF_TIMEZONE')];
}
