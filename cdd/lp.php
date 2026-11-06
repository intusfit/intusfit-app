<?php
/**
 * CDD: entrega das páginas de venda. /vendas (e a raiz do domínio) sorteia entre as LPs ativas, com cookie para a visitante ver sempre a mesma;
 * /lp/<slug> abre uma LP específica. Regras e edição dos textos em api/_gestao.php.
 */
require_once __DIR__ . '/api/_nucleo.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['s'] ?? '')));
try {
    $pdo = cddDB();
    cddGarantirTabelas($pdo);
    cddLpSementes($pdo);
    $lp = null;
    if ($slug !== '') {
        $st = $pdo->prepare("SELECT * FROM cdd_lp WHERE slug = ? AND status <> 'arquivada'");
        $st->execute([$slug]);
        $lp = $st->fetch() ?: null;
    } else {
        $lp = cddLpEscolher($pdo, (string)($_COOKIE['cdd_lp'] ?? ''));
    }
    $html = $lp ? cddLpRenderizar($pdo, $lp) : null;
} catch (Throwable $e) { $html = null; $lp = null; }

if ($html === null) {
    // sem banco ou sem LP: cai na página principal, que é um arquivo estático (nunca deixa o domínio sem landing)
    $f = __DIR__ . '/lp-escura.html';
    $html = is_file($f) ? (string)file_get_contents($f) : 'Página indisponível.';
    $lp = ['slug' => 'escura'];
}
if ($slug === '' && !empty($lp['slug'])) setcookie('cdd_lp', $lp['slug'], ['expires' => time() + 86400 * 30, 'path' => '/', 'samesite' => 'Lax', 'secure' => true]);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
echo $html;
