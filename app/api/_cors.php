<?php
require_once __DIR__ . '/_ip.php';   // intusClientIp(): IP real do visitante (com ou sem Cloudflare)
/**
 * CORS restrito + headers de segurança.
 * Inclua no topo de cada endpoint ANTES dos outros headers.
 */
// Origens web oficiais e WebViews do app Capacitor (iOS e Android).
// Não usar coringa: a API recebe Bearer tokens em praticamente todas as rotas.
// Origens de DESENVOLVIMENTO (http://localhost:3000 e :5500) saíram em 11/11/2026: produção não deve confiar em servidor local de
// quem navega. Os testes do projeto (testes/) não passam por aqui; para experimentar a API de produção a partir de uma página local,
// sirva a página pelo domínio oficial ou use uma cópia local deste arquivo, nunca a lista de produção.
$_cors_allowed = [
    'https://intusfit.com.br',
    'http://localhost',       // Android Capacitor (configuração padrão)
    'https://localhost',      // Android Capacitor (configuração atual)
    'capacitor://localhost',  // iOS Capacitor
];
$_cors_origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($_cors_origin, $_cors_allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $_cors_origin);
} else {
    header('Access-Control-Allow-Origin: https://intusfit.com.br');
}
header('Vary: Origin');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Cache-Control: no-store, no-cache, private, max-age=0');
