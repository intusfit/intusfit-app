<?php
/**
 * Configuração editável da página /black (editor em admin/black.php, leitura pública em black/config.php).
 * Só guarda o que foi alterado; o restante vale o que está escrito em black/index.html.
 * O arquivo salvo fica fora do git: o deploy não apaga o que foi editado.
 */
const BLACK_ARQ = __DIR__ . '/black.json';
const BLACK_ANTERIOR = __DIR__ . '/black.anterior.json';
const BLACK_PAINEL_SEG = 7200;   // quanto vale a entrada vinda do painel (app/api/black_sso.php)

/** Entrada vinda do painel. Libera SÓ o editor da /black e o envio de fotos dele, nunca o resto do /admin (que exige 'auth'). */
function blackPeloPainel(array $s, int $agora): bool {
    return !empty($s['black_ate']) && $agora < (int)$s['black_ate'];
}

/** Descarta tudo o que não estiver no formato esperado. Devolve só chaves conhecidas. */
function blackLimpar($c): array {
    $c = is_array($c) ? $c : [];
    $r = [];
    $chave = fn($k) => is_string($k) && preg_match('/^[a-z0-9._]{1,60}$/', $k);
    $texto = fn($v, $max = 2000) => mb_substr(str_replace("\r", '', trim($v)), 0, $max);

    foreach ((array)($c['textos'] ?? []) as $k => $v) {
        if ($chave($k) && is_string($v)) $r['textos'][$k] = $texto($v);
    }
    foreach ((array)($c['listas'] ?? []) as $k => $v) {
        if ($chave($k) && is_array($v)) $r['listas'][$k] = array_map(fn($i) => $texto($i, 200), array_slice(array_values(array_filter($v, 'is_string')), 0, 20));
    }
    foreach ((array)($c['imagens'] ?? []) as $k => $v) {
        // Só imagem do próprio site (começa com uma barra só), sem aspas, espaços ou "..".
        if ($chave($k) && is_string($v) && preg_match('#^/(?!/)[A-Za-z0-9_\-./?=]{1,200}$#', $v) && strpos($v, '..') === false) $r['imagens'][$k] = $v;
    }
    foreach (['principal', 'botao', 'graca', 'zap', 'bg', 'card', 'borda', 'txt', 'sobre'] as $k) {
        $v = $c['cores'][$k] ?? null;
        if (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $r['cores'][$k] = strtolower($v);
    }
    $formatos = ['fim' => '/^\d{4}-\d\d-\d\dT\d\d:\d\d$/', 'whats' => '/^\d{10,15}$/', 'aba' => '/^(tri|sem|anual)$/', 'destaque' => '/^(treino|combo|dieta|nenhum)$/'];
    foreach ($formatos as $k => $re) {
        $v = $c['ajustes'][$k] ?? null;
        if (is_string($v) && preg_match($re, $v)) $r['ajustes'][$k] = $v;
    }
    foreach (['treino', 'combo', 'dieta'] as $p) {
        foreach (['tri', 'sem', 'anual'] as $per) {
            $v = $c['links'][$p][$per] ?? null;
            // Vazio vale (o botão passa a levar ao WhatsApp). Link de pagamento só com https.
            if (is_string($v) && ($v === '' || (strlen($v) <= 300 && preg_match('#^https://[^\s"\'<>]+$#', $v)))) $r['links'][$p][$per] = $v;
        }
    }
    if (isset($c['duvidas']) && is_array($c['duvidas'])) {
        $r['duvidas'] = [];
        foreach (array_slice($c['duvidas'], 0, 20) as $d) {
            if (is_array($d) && is_string($d['p'] ?? null) && trim($d['p']) !== '') $r['duvidas'][] = ['p' => $texto($d['p'], 200), 'r' => $texto((string)($d['r'] ?? ''))];
        }
    }
    if (isset($c['ocultos']) && is_array($c['ocultos'])) {
        $r['ocultos'] = array_values(array_intersect(['relogio', 'como', 'garantia', 'banda', 'recebe', 'equipe', 'resultados', 'duvidas', 'contato', 'slogan'], $c['ocultos']));
        if (!$r['ocultos']) unset($r['ocultos']);
    }
    return $r;
}

function blackLer(string $arq = BLACK_ARQ): array {
    if (!is_file($arq)) return [];
    return blackLimpar(json_decode((string)file_get_contents($arq), true));
}

/** Grava a nova versão e guarda a anterior, para o botão "Desfazer última publicação". */
function blackGravar(array $cfg, string $arq = BLACK_ARQ, string $anterior = BLACK_ANTERIOR): bool {
    $novo = json_encode((object)blackLimpar($cfg), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($novo === false) return false;
    $velho = is_file($arq) ? (string)file_get_contents($arq) : '{}';
    if (file_put_contents($anterior, $velho, LOCK_EX) === false) return false;
    return file_put_contents($arq, $novo, LOCK_EX) !== false;
}
