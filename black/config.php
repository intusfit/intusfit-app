<?php
// Entrega à página /black as alterações feitas no editor (admin/black.php). Público, só leitura.
require __DIR__ . '/../admin/_black_cfg.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$cfg = blackLer();
if ($cfg) echo 'window.BLACK_CFG=' . json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';';
