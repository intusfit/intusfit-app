<?php
/**
 * Intus Cut (editor de vídeo da equipe, /intus-cut/): regra e armazenamento. Rotas em intuscut.php.
 *
 * Sem tabela nova:
 *   intus_config  cut_index          {id: {nome, formato, atualizado_em, por, por_nome}}   lista de projetos
 *                 cut_p_<id>         o projeto inteiro (JSON, até CUT_PROJETO_MAX bytes)
 *                 cut_bib            biblioteca da equipe [{id, tipo, nome, url, ...}]
 *                 cut_ia_uso         pedidos à IA por dia
 *   intus_midia   dono_tipo 'cut', dono_id = idusuario de quem enviou; o vídeo mora no Google Drive
 *                 (mesma conta e pasta do Feed e dos feedbacks) e toca por midia.php?action=video&t=TOKEN.
 * Imagens da biblioteca (logos, fotos) ficam em app/img/cut/.
 * Quem usa: administrador, ou quem tem a chave cut_ver em Usuários. Aluno nunca.
 */

const CUT_PROJETO_MAX = 2 * 1024 * 1024;           // 2 MB de JSON por projeto
const CUT_BIB_MAX = 600;                           // itens na biblioteca
const CUT_VIDEO_MAX = 2 * 1024 * 1024 * 1024;      // 2 GB por vídeo (entrevista inteira em 1080p cabe)
const CUT_VIDEO_DUR_MAX = 3 * 3600;                // 3 h
const CUT_IMAGEM_MAX = 8 * 1024 * 1024;
const CUT_IA_DIA = 40;
const CUT_MIMES_VIDEO = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v'];
const CUT_MIMES_AUDIO = ['audio/mp4', 'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/aac', 'audio/x-m4a'];
const CUT_TIPOS_BIB = ['logo', 'foto', 'video', 'audio'];

function cut_garantir(PDO $pdo): void {
    static $feito = null;
    if ($feito === null) $feito = new WeakMap();
    if (isset($feito[$pdo])) return;
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS intus_config (chave TEXT PRIMARY KEY, valor TEXT, updated_at TEXT)');
    } else {
        $pdo->exec('CREATE TABLE IF NOT EXISTS intus_config (
            chave VARCHAR(60) PRIMARY KEY,
            valor LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    $feito[$pdo] = true;
}

function cut_ler(PDO $pdo, string $chave, $padrao = null) {
    cut_garantir($pdo);
    $st = $pdo->prepare('SELECT valor FROM intus_config WHERE chave = ? LIMIT 1');
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null || $v === '') return $padrao;
    $a = json_decode((string)$v, true);
    return is_array($a) ? $a : $padrao;
}

function cut_gravar(PDO $pdo, string $chave, $dados): void {
    cut_garantir($pdo);
    $json = is_string($dados) ? $dados : json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $pdo->prepare('SELECT 1 FROM intus_config WHERE chave = ?');
    $st->execute([$chave]);
    if ($st->fetchColumn()) $pdo->prepare('UPDATE intus_config SET valor = ?, updated_at = ? WHERE chave = ?')->execute([$json, gmdate('Y-m-d H:i:s'), $chave]);
    else $pdo->prepare('INSERT INTO intus_config (chave, valor, updated_at) VALUES (?, ?, ?)')->execute([$chave, $json, gmdate('Y-m-d H:i:s')]);
}

function cut_apagar_chave(PDO $pdo, string $chave): void {
    cut_garantir($pdo);
    $pdo->prepare('DELETE FROM intus_config WHERE chave = ?')->execute([$chave]);
}

/** Pode usar o Intus Cut: administrador ou chave cut_ver. */
function cut_pode(PDO $pdo, array $ctx): bool {
    if (!empty($ctx['is_aluno'])) return false;
    $id = (int)($ctx['idusuario'] ?? 0);
    if ($id <= 0) return false;
    if (!empty($ctx['admin'])) return true;
    return function_exists('usuarioTemPermissao') && usuarioTemPermissao($pdo, $id, 'cut_ver');
}

function cut_id_valido($id): bool { return is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{2,44}$/', $id) === 1; }

function cut_texto($v, int $max): string {
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$v) ?? '');
    return mb_substr($s, 0, $max);
}

/** URL que o projeto pode guardar: só deste site (caminho começando com /) ou blob/data do próprio aparelho nunca. */
function cut_url_ok($u): bool {
    if (!is_string($u) || $u === '') return false;
    if (strlen($u) > 600) return false;
    return preg_match('#^/(app/api/midia\.php\?action=video&t=[0-9a-f]{32}|app/img/cut/[A-Za-z0-9_.-]+|app/painel/[A-Za-z0-9_.-]+\.(png|jpe?g|webp))$#', $u) === 1;
}

function cut_lista(PDO $pdo): array {
    $idx = cut_ler($pdo, 'cut_index', []);
    $out = [];
    foreach ($idx as $id => $p) if (cut_id_valido((string)$id) && is_array($p)) $out[] = ['id' => (string)$id] + $p;
    usort($out, fn($a, $b) => strcmp((string)($b['atualizado_em'] ?? ''), (string)($a['atualizado_em'] ?? '')));
    return $out;
}

function cut_projeto(PDO $pdo, string $id): ?array {
    if (!cut_id_valido($id)) return null;
    $p = cut_ler($pdo, 'cut_p_' . $id, null);
    if (!is_array($p)) return null;
    $p['id'] = $id;
    return $p;
}

/**
 * Grava um projeto. Confere id, tamanho e que toda mídia aponte para este site (nada de link externo ou de outro aparelho).
 * Devolve ['ok' => true, 'atualizado_em' => ...] ou ['ok' => false, 'erro' => ...].
 */
function cut_salvar(PDO $pdo, $projeto, array $ctx): array {
    if (!is_array($projeto)) return ['ok' => false, 'erro' => 'projeto inválido'];
    $id = (string)($projeto['id'] ?? '');
    if (!cut_id_valido($id)) return ['ok' => false, 'erro' => 'id do projeto inválido'];
    if (!isset($projeto['trechos']) || !is_array($projeto['trechos']) || !count($projeto['trechos'])) return ['ok' => false, 'erro' => 'o projeto precisa de pelo menos um trecho'];
    $ruins = cut_urls_ruins($projeto);
    if ($ruins) return ['ok' => false, 'erro' => 'mídia fora do sistema da Intus: ' . implode(', ', array_slice($ruins, 0, 3))];
    $projeto['nome'] = cut_texto($projeto['nome'] ?? $id, 160);
    $agora = gmdate('Y-m-d\TH:i:s\Z');
    $projeto['atualizado_em'] = $agora;
    unset($projeto['render']);
    $json = json_encode($projeto, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return ['ok' => false, 'erro' => 'projeto com texto inválido'];
    if (strlen($json) > CUT_PROJETO_MAX) return ['ok' => false, 'erro' => 'projeto grande demais (' . round(strlen($json) / 1048576, 1) . ' MB; o limite é 2 MB)'];
    cut_gravar($pdo, 'cut_p_' . $id, $json);
    $idx = cut_ler($pdo, 'cut_index', []);
    $antes = $idx[$id] ?? [];
    $idx[$id] = cut_status_campos($antes) + ['nome' => $projeto['nome'], 'formato' => cut_texto($projeto['formato'] ?? 'reel', 20), 'atualizado_em' => $agora,
                 'criado_por' => $antes['criado_por'] ?? (int)($ctx['idusuario'] ?? 0), 'por' => (int)($ctx['idusuario'] ?? 0), 'por_nome' => cut_texto($ctx['nmusuario'] ?? '', 80),
                 'ia' => !empty($projeto['ia']) ? cut_texto($projeto['ia']['status'] ?? '', 20) : ''];
    cut_gravar($pdo, 'cut_index', $idx);
    return ['ok' => true, 'atualizado_em' => $agora];
}

// ---------- painel de vídeos: situação de cada projeto (fica no índice, não no projeto) ----------
const CUT_STATUS = ['editando', 'pronto', 'agendado', 'postado', 'arquivado'];
const CUT_REDES = ['instagram', 'tiktok', 'youtube', 'facebook', 'whatsapp', 'outro'];
/** Só os campos de situação de uma entrada do índice (para não se perderem quando o projeto é salvo de novo). */
function cut_status_campos(array $e): array {
    $out = [];
    foreach (['status', 'agendado_para', 'postado_em', 'redes', 'link', 'obs', 'status_em', 'status_por'] as $k) if (array_key_exists($k, $e)) $out[$k] = $e[$k];
    return $out;
}
function cut_data_ok($v): string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $v) ? $v : ''; }
/**
 * Muda a situação de um projeto: editando, pronto, agendado (com data), postado (data, redes, link) ou arquivado.
 * Devolve ['ok' => true, 'item' => entrada do índice] ou ['ok' => false, 'erro' => ...].
 */
function cut_status(PDO $pdo, $d, array $ctx): array {
    if (!is_array($d)) return ['ok' => false, 'erro' => 'pedido inválido'];
    $id = (string)($d['id'] ?? '');
    $idx = cut_ler($pdo, 'cut_index', []);
    if (!cut_id_valido($id) || !isset($idx[$id]) || !is_array($idx[$id])) return ['ok' => false, 'erro' => 'projeto não encontrado'];
    $st = (string)($d['status'] ?? '');
    if (!in_array($st, CUT_STATUS, true)) return ['ok' => false, 'erro' => 'situação inválida'];
    $e = $idx[$id];
    $e['status'] = $st;
    if (array_key_exists('agendado_para', $d)) $e['agendado_para'] = cut_data_ok($d['agendado_para']);
    if (array_key_exists('postado_em', $d)) $e['postado_em'] = cut_data_ok($d['postado_em']);
    if (array_key_exists('redes', $d)) $e['redes'] = array_values(array_unique(array_filter((array)$d['redes'], fn($r) => in_array($r, CUT_REDES, true))));
    if (array_key_exists('link', $d)) { $l = trim((string)$d['link']); $e['link'] = preg_match('#^https://[^\s<>"]{3,500}$#', $l) ? $l : ''; }
    if (array_key_exists('obs', $d)) $e['obs'] = cut_texto($d['obs'], 300);
    if ($st === 'agendado' && empty($e['agendado_para'])) return ['ok' => false, 'erro' => 'informe a data do agendamento'];
    if ($st === 'postado' && empty($e['postado_em'])) $e['postado_em'] = gmdate('Y-m-d');
    $e['status_em'] = gmdate('Y-m-d\TH:i:s\Z');
    $e['status_por'] = cut_texto($ctx['nmusuario'] ?? '', 80);
    $idx[$id] = $e;
    cut_gravar($pdo, 'cut_index', $idx);
    return ['ok' => true, 'item' => ['id' => $id] + $e];
}

/** Toda url/video/voz/musica/partes dentro do projeto (e das fontes) precisa ser deste site. */
function cut_urls_ruins(array $p): array {
    $ruins = [];
    $olhar = function ($m) use (&$ruins) {
        if (!is_array($m)) return;
        foreach (['video', 'voz', 'musica'] as $k) if (!empty($m[$k]) && !cut_url_ok($m[$k])) $ruins[] = (string)$m[$k];
    };
    $olhar($p['midia'] ?? null);
    foreach ((array)($p['fontes'] ?? []) as $f) if (is_array($f)) $olhar($f['midia'] ?? null);
    foreach ((array)($p['imagens'] ?? []) as $im) if (is_array($im) && !empty($im['url']) && !cut_url_ok($im['url'])) $ruins[] = (string)$im['url'];
    if (!empty($p['layout']['logo']['url']) && !cut_url_ok($p['layout']['logo']['url'])) $ruins[] = (string)$p['layout']['logo']['url'];
    return $ruins;
}

function cut_excluir(PDO $pdo, string $id): bool {
    if (!cut_id_valido($id)) return false;
    $idx = cut_ler($pdo, 'cut_index', []);
    if (!isset($idx[$id])) return false;
    unset($idx[$id]);
    cut_gravar($pdo, 'cut_index', $idx);
    cut_apagar_chave($pdo, 'cut_p_' . $id);
    return true;
}

function cut_bib(PDO $pdo): array { $b = cut_ler($pdo, 'cut_bib', []); return array_values(array_filter($b, 'is_array')); }

function cut_bib_salvar(PDO $pdo, $item, array $ctx): array {
    if (!is_array($item)) return ['ok' => false, 'erro' => 'item inválido'];
    $tipo = (string)($item['tipo'] ?? '');
    if (!in_array($tipo, CUT_TIPOS_BIB, true)) return ['ok' => false, 'erro' => 'tipo inválido'];
    $url = (string)($item['url'] ?? '');
    if (!cut_url_ok($url)) return ['ok' => false, 'erro' => 'endereço do arquivo inválido'];
    $id = (string)($item['id'] ?? '');
    if (!preg_match('/^[a-z0-9-]{3,40}$/', $id)) $id = 'b' . bin2hex(random_bytes(5));
    $limpo = ['id' => $id, 'tipo' => $tipo, 'nome' => cut_texto($item['nome'] ?? 'Sem nome', 120), 'url' => $url,
              'w' => max(0, (int)($item['w'] ?? 0)), 'h' => max(0, (int)($item['h'] ?? 0)), 'dur' => max(0, round((float)($item['dur'] ?? 0), 2)),
              'rascunho' => !empty($item['rascunho']), 'react' => !empty($item['react']), 'exportado' => !empty($item['exportado']),
              'criado_em' => cut_texto($item['criado_em'] ?? gmdate('Y-m-d\TH:i:s\Z'), 30), 'por' => (int)($ctx['idusuario'] ?? 0), 'por_nome' => cut_texto($ctx['nmusuario'] ?? '', 80)];
    $b = cut_bib($pdo);
    $achou = false;
    foreach ($b as $i => $x) if (($x['id'] ?? '') === $id) { $limpo['por'] = $x['por'] ?? $limpo['por']; $limpo['por_nome'] = $x['por_nome'] ?? $limpo['por_nome']; $b[$i] = $limpo; $achou = true; }
    if (!$achou) { if (count($b) >= CUT_BIB_MAX) return ['ok' => false, 'erro' => 'biblioteca cheia (' . CUT_BIB_MAX . ' itens)']; array_unshift($b, $limpo); }
    cut_gravar($pdo, 'cut_bib', $b);
    return ['ok' => true, 'item' => $limpo];
}

function cut_bib_excluir(PDO $pdo, string $id): ?array {
    $b = cut_bib($pdo); $tirado = null;
    $b = array_values(array_filter($b, function ($x) use ($id, &$tirado) { if (($x['id'] ?? '') === $id) { $tirado = $x; return false; } return true; }));
    if ($tirado) cut_gravar($pdo, 'cut_bib', $b);
    return $tirado;
}

/** Token do vídeo a partir da url /app/api/midia.php?action=video&t=TOKEN. */
function cut_token_de_url(string $u): string { return preg_match('/[?&]t=([0-9a-f]{32})/', $u, $m) ? $m[1] : ''; }

/** Validação do pedido de envio de vídeo/áudio. Devolve [mime, tamanho, duracao] ou string de erro. */
function cut_validar_envio(array $b) {
    $mime = strtolower(preg_replace('/;.*$/', '', trim((string)($b['mime'] ?? ''))));
    if ($mime === 'video/x-m4v') $mime = 'video/mp4';
    if (!in_array($mime, CUT_MIMES_VIDEO, true) && !in_array($mime, CUT_MIMES_AUDIO, true)) return 'Formato não suportado (use MP4, MOV, WEBM ou áudio M4A, MP3, WAV).';
    $tam = (int)($b['tamanho'] ?? 0);
    if ($tam <= 0) return 'Arquivo vazio.';
    if ($tam > CUT_VIDEO_MAX) return 'Arquivo grande demais (máximo ' . round(CUT_VIDEO_MAX / 1073741824) . ' GB).';
    $dur = (float)($b['duracao'] ?? 0);
    if ($dur > CUT_VIDEO_DUR_MAX) return 'Arquivo com mais de 3 horas.';
    return [$mime, $tam, $dur];
}

function cut_ia_uso_hoje(PDO $pdo): int { $u = cut_ler($pdo, 'cut_ia_uso', []); return (int)($u[gmdate('Y-m-d')] ?? 0); }
function cut_ia_somar(PDO $pdo): void { $u = cut_ler($pdo, 'cut_ia_uso', []); $d = gmdate('Y-m-d'); $u[$d] = (int)($u[$d] ?? 0) + 1; krsort($u); cut_gravar($pdo, 'cut_ia_uso', array_slice($u, 0, 30, true)); }

/**
 * Pede a montagem ao Claude (mesma chave e modelo da Central de atendimento). O texto do pedido vem montado da tela,
 * com as falas dos vídeos; aqui só se põe o limite diário e se extrai o JSON da resposta.
 * $http: callable(string corpo, string chave, bool comFallback): array{status,json,erro} (para testes).
 */
/**
 * Quadros do vídeo que a tela manda junto (para a IA ver o que acontece). Só JPEG, PNG ou WEBP em base64,
 * no máximo CUT_IA_QUADROS imagens, cada uma até 600 KB. O que não servir é descartado em silêncio.
 */
const CUT_IA_QUADROS = 24;
function cut_ia_imagens($lista): array {
    $out = [];
    if (!is_array($lista)) return $out;
    foreach ($lista as $d) {
        if (count($out) >= CUT_IA_QUADROS) break;
        if (!is_string($d) || strlen($d) > 820000) continue;
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $d, $m)) continue;
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) < 100 || strlen($bin) > 600000) continue;
        $assina = ['jpeg' => "\xFF\xD8\xFF", 'png' => "\x89PNG", 'webp' => 'RIFF'];
        if (strncmp($bin, $assina[$m[1]], strlen($assina[$m[1]])) !== 0) continue;
        $out[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/' . $m[1], 'data' => $m[2]]];
    }
    return $out;
}

function cut_ia(PDO $pdo, string $pedido, ?callable $http = null, $imagens = []): array {
    $pedido = trim($pedido);
    if ($pedido === '') return ['ok' => false, 'erro' => 'pedido vazio'];
    if (strlen($pedido) > 240000) return ['ok' => false, 'erro' => 'Os vídeos têm fala demais para um pedido só. Deixe menos vídeos na linha do tempo.'];
    if (cut_ia_uso_hoje($pdo) >= CUT_IA_DIA) return ['ok' => false, 'erro' => 'Limite de ' . CUT_IA_DIA . ' montagens por dia atingido. Volta amanhã.'];
    $cfg = function_exists('wa_segredo') ? wa_segredo($pdo, 'anthropic_config') : null;
    $chave = is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
    if ($chave === '') return ['ok' => false, 'erro' => 'A chave do Claude ainda não foi cadastrada (admin/whatsapp-keys.php).'];
    $modelo = function_exists('wa_ajustes') ? (wa_ajustes($pdo)['modelo'] ?? 'claude-opus-5-5') : 'claude-opus-5-5';
    $corpo = json_encode(['model' => $modelo, 'max_tokens' => 8000, 'output_config' => ['effort' => 'medium'], 'fallbacks' => 'default',
        'system' => 'Você edita vídeos curtos para as redes da Intus Fit. Responda sempre só com o JSON pedido, sem texto antes ou depois.',
        'messages' => [['role' => 'user', 'content' => array_merge(cut_ia_imagens($imagens), [['type' => 'text', 'text' => $pedido]])]]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $chamar = $http ?: 'atd_http_claude';
    $r = $chamar($corpo, $chave, true);
    if (($r['status'] ?? 0) === 400 && preg_match('/fallback|beta/i', json_encode($r['json'] ?? '') ?: '')) {
        $sem = json_decode($corpo, true); unset($sem['fallbacks']);
        $r = $chamar(json_encode($sem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $chave, false);
    }
    $st = (int)($r['status'] ?? 0); $j = $r['json'] ?? null;
    if ($st !== 200 || !is_array($j)) {
        return ['ok' => false, 'erro' => $st === 401 ? 'A chave do Claude foi recusada.' : ($st === 429 ? 'O Claude está com muitos pedidos agora. Tente de novo em instantes.' : 'O Claude não respondeu como esperado' . ($st ? " (HTTP $st)" : '') . '.')];
    }
    if (($j['stop_reason'] ?? '') === 'refusal') return ['ok' => false, 'erro' => 'O Claude recusou este pedido. Reformule as orientações.'];
    $texto = '';
    foreach (($j['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') $texto .= $b['text'];
    cut_ia_somar($pdo);
    $dados = function_exists('atd_extrair_json') ? atd_extrair_json($texto) : json_decode($texto, true);
    if (!is_array($dados)) return ['ok' => false, 'erro' => 'A IA respondeu num formato que não deu para ler. Tente de novo.'];
    return ['ok' => true, 'resposta' => $dados, 'modelo' => $modelo];
}
