<?php
/**
 * CORS restrito + headers de segurança.
 * Inclua no topo de cada endpoint ANTES dos outros headers.
 */
$_cors_allowed = ['https://intusfit.com.br', 'http://localhost:3000', 'http://localhost:5500'];
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
