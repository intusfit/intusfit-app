<?php
/**
 * CORS restrito + headers de segurança.
 * Inclua no topo de cada endpoint ANTES dos outros headers.
 */
// Origens web oficiais e WebViews do app Capacitor (iOS e Android).
// Não usar coringa: a API recebe Bearer tokens em praticamente todas as rotas.
$_cors_allowed = [
    'https://intusfit.com.br',
    'http://localhost:3000',
    'http://localhost:5500',
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
