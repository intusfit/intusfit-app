<?php
// IP real atrás do Cloudflare (app/api/_ip.php): o cabeçalho CF-Connecting-IP só vale quando a conexão vem de uma faixa do Cloudflare.
//   php -c <php.ini> testes/ip.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }
require __DIR__ . '/../app/api/_ip.php';
function cenario($remote, $cf, $geo = []) { $_SERVER = ['REMOTE_ADDR' => $remote]; if ($cf) $_SERVER['HTTP_CF_CONNECTING_IP'] = $cf; foreach ($geo as $k => $v) $_SERVER[$k] = $v; }

eq('faixa IPv4 do Cloudflare (104.16.x) é reconhecida', (cenario('104.16.5.9', '') || true) && intusVemDoCloudflare());
eq('172.71.x (172.64.0.0/13) é reconhecida', (cenario('172.71.1.1', '') || true) && intusVemDoCloudflare());
eq('IP comum NÃO é do Cloudflare', (cenario('191.6.216.100', '') || true) && !intusVemDoCloudflare());
eq('vizinho logo fora da faixa 104.24.0.0/14 (104.28.0.1) NÃO é', (cenario('104.28.0.1', '') || true) && !intusVemDoCloudflare());
eq('IPv6 do Cloudflare (2606:4700::1) é reconhecido', (cenario('2606:4700::1', '') || true) && intusVemDoCloudflare());
eq('IPv6 de fora (2001:db8::1) não é', (cenario('2001:db8::1', '') || true) && !intusVemDoCloudflare());

// o ponto de segurança: cabeçalho falso vindo direto NÃO vale
cenario('200.1.2.3', '9.9.9.9');
eq('CF-Connecting-IP de quem NÃO é Cloudflare é ignorado (usa REMOTE_ADDR)', (function () { return intusClientIp(); })() === '200.1.2.3');
cenario('104.16.5.9', '201.10.20.30');
eq('atrás do Cloudflare, vale o IP do visitante (CF-Connecting-IP)', intusClientIp() === '201.10.20.30');
cenario('104.16.5.9', 'lixo');
eq('cabeçalho que não é IP é ignorado mesmo vindo do Cloudflare', intusClientIp() === '104.16.5.9');
cenario('104.16.5.9', '201.10.20.30', ['HTTP_CF_IPCOUNTRY' => 'br', 'HTTP_CF_IPCITY' => 'Cascavel', 'HTTP_CF_TIMEZONE' => 'America/Sao_Paulo']);
$g = intusClientGeo();
eq('localização do Cloudflare é lida (país em maiúsculas, cidade, fuso)', $g['pais'] === 'BR' && $g['cidade'] === 'Cascavel' && $g['fuso'] === 'America/Sao_Paulo', json_encode($g));
cenario('200.1.2.3', '', ['HTTP_CF_IPCOUNTRY' => 'US', 'HTTP_CF_IPCITY' => 'Falsa']);
eq('localização enviada por quem NÃO é Cloudflare é ignorada', intusClientGeo()['pais'] === '');
echo "
" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "
";
exit($ruim ? 1 : 0);
