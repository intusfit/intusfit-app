<?php
// ── DETALHE DE ERRO NAO VAI PARA O CLIENTE ─────────────────────────────────
// As respostas devolviam $e->getMessage() direto. Em falha de conexao isso
// traz host, nome do banco e usuario; em erro de SQL, o nome das tabelas e
// colunas. E um mapa do sistema entregue de graca a quem estiver sondando.
// Agora a mensagem completa vai para o log do servidor e o cliente recebe
// so um numero para voce cruzar com o log.
function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

/**
 * Endpoint CRUD de atletas (clientes).
 *
 * Detecta dinamicamente schema (tabela atleta/atletas/aluno/alunos) e mapeia
 * colunas (id, nome, email, telefone, genero, dtnascimento, codacesso,
 * stbloqueio, observacao, dtcadastro). Auto-cria as colunas que faltarem
 * (incluindo dtnascimento, que é a principal causa de "data nao salva").
 *
 * Rotas:
 *   GET    /api/atletas.php                    -> lista todos
 *   GET    /api/atletas.php?busca=X            -> lista filtrada por nome
 *   GET    /api/atletas.php?id=X               -> 1 atleta
 *   POST   /api/atletas.php                    {nome, email, ...}        -> criar
 *   PUT    /api/atletas.php?id=X               {campo:valor}             -> editar
 *   DELETE /api/atletas.php?id=X               -> bloquear (stbloqueio=S)
 *   DELETE /api/atletas.php?id=X&action=excluir-> excluir definitivo
 *
 * Auth: Bearer token (qualquer não vazio — mesma política de treinos.php / usuarios.php).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Auth ----------
function bearerToken() {
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    elseif (function_exists('apache_request_headers')) {
        $hdrs = apache_request_headers();
        foreach ($hdrs as $k => $v) if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
    }
    if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
    return '';
}
$tok = bearerToken();
if ($tok === '') {
    http_response_code(401);
    echo json_encode(['error' => 'token ausente']);
    exit;
}

// ---------- Conexão ----------
$cfg = @include __DIR__ . '/../config/db.php';
$pdo = null;
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new Exception('sem credenciais');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Detectar tabela ----------
$tabela = null;
foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
    try {
        $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        $tabela = $t;
        break;
    } catch (Throwable $e) { /* nao existe */ }
}
if (!$tabela) {
    http_response_code(500);
    echo json_encode(['error' => 'tabela atleta nao encontrada']);
    exit;
}

function listCols(PDO $pdo, $tabela) {
    $out = [];
    $st = $pdo->query("SHOW COLUMNS FROM `$tabela`");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $out[] = $r['Field'];
    return $out;
}
function pickCol(array $cols, array $candidates) {
    foreach ($candidates as $c) if (in_array($c, $cols, true)) return $c;
    return null;
}

$colunas = listCols($pdo, $tabela);

$col_id    = pickCol($colunas, ['idatleta','idaluno','id']);
$col_nome  = pickCol($colunas, ['nome','nmatleta','nmaluno','name']);
$col_email = pickCol($colunas, ['email','dsemail']);
$col_tel   = pickCol($colunas, ['telefone','nrtelefone','dstelefone','tel','celular']);
$col_gen   = pickCol($colunas, ['genero','sexo','tpsexo']);
$col_nasc  = pickCol($colunas, ['dtnascimento','dtnasc','data_nascimento','nascimento']);
$col_cod   = pickCol($colunas, ['codacesso','codigo','codigo_acesso']);
$col_cpf   = pickCol($colunas, ['cpf','nrcpf','ds_cpf']);
$col_block = pickCol($colunas, ['stbloqueio','bloqueado','stativo']);
$col_obs   = pickCol($colunas, ['observacao','dsobservacao','obs','nota']);
$col_dtcad = pickCol($colunas, ['dtcadastro','dt_cadastro','created_at','dtinclusao']);
$col_senha = pickCol($colunas, ['senha','dssenha','password','hash']);

if (!$col_id || !$col_nome) {
    http_response_code(500);
    echo json_encode(['error'=>'schema atleta sem id/nome', 'colunas'=>$colunas]);
    exit;
}

// ─── Auto-cria colunas opcionais que faltarem ────────────────────
// dtnascimento é o foco — backend antigo nao tinha esse campo, por isso
// o frontend mandava o valor mas ele se perdia silenciosamente.
$alters = [
    'col_email' => ['email', "VARCHAR(180) NULL"],
    'col_tel'   => ['telefone', "VARCHAR(40) NULL"],
    'col_gen'   => ['genero', "CHAR(1) NULL"],
    'col_nasc'  => ['dtnascimento', "DATE NULL"],
    'col_cod'   => ['codacesso', "VARCHAR(40) NULL"],
    'col_cpf'   => ['cpf', "VARCHAR(14) NULL"],
    'col_obs'   => ['observacao', "TEXT NULL"],
    'col_senha' => ['senha', "VARCHAR(255) NULL"],
];
foreach ($alters as $var => [$nome, $tipo]) {
    if (!$$var) {
        try {
            $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `$nome` $tipo");
            $$var = $nome;
        } catch (Throwable $e) { /* permissao negada — segue sem essa coluna */ }
    }
}

// Auto-cria coluna de vínculo professor se não existir
$col_profs = pickCol($colunas, ['professores_responsaveis','idprofessor']);
if (!$col_profs) {
    try {
        $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `professores_responsaveis` TEXT NULL");
        $col_profs = 'professores_responsaveis';
    } catch (Throwable $e) {}
}

// Qualificação e notas do professor
$col_objetivos = pickCol($colunas, ['objetivos']);
$col_dificuldades = pickCol($colunas, ['dificuldades']);
$col_notas_prof = pickCol($colunas, ['notas_professor']);
if (!$col_objetivos) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `objetivos` TEXT NULL"); $col_objetivos = 'objetivos'; } catch (Throwable $e) {} }
if (!$col_dificuldades) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `dificuldades` TEXT NULL"); $col_dificuldades = 'dificuldades'; } catch (Throwable $e) {} }
if (!$col_notas_prof) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `notas_professor` TEXT NULL"); $col_notas_prof = 'notas_professor'; } catch (Throwable $e) {} }
$col_ranking_optin = pickCol($colunas, ['ranking_optin']);
if (!$col_ranking_optin) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `ranking_optin` CHAR(1) DEFAULT 'S'"); $col_ranking_optin = 'ranking_optin'; } catch (Throwable $e) {} }
// Feed dos Alunos e ranking sao coisas diferentes de propósito: o feed tem
// foto e legenda (mais pessoal), então o opt-in é separado — desligar um não
// desliga o outro.
$col_feed_optin = pickCol($colunas, ['feed_optin']);
if (!$col_feed_optin) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `feed_optin` CHAR(1) DEFAULT 'S'"); $col_feed_optin = 'feed_optin'; } catch (Throwable $e) {} }

$col_aluno_intus = pickCol($colunas, ['aluno_intus']);
if (!$col_aluno_intus) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `aluno_intus` CHAR(1) DEFAULT 'N'"); $col_aluno_intus = 'aluno_intus'; } catch (Throwable $e) {} }

// Geolocalização do aluno (país / estado / cidade) — preenchimento opcional
$col_pais   = pickCol($colunas, ['pais']);
$col_estado = pickCol($colunas, ['estado']);
$col_cidade = pickCol($colunas, ['cidade']);
if (!$col_pais)   { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `pais` VARCHAR(80) NULL");    $col_pais   = 'pais'; } catch (Throwable $e) {} }
if (!$col_estado) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `estado` VARCHAR(100) NULL"); $col_estado = 'estado'; } catch (Throwable $e) {} }
if (!$col_cidade) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `cidade` VARCHAR(120) NULL"); $col_cidade = 'cidade'; } catch (Throwable $e) {} }

// Origem do cadastro (classificação Lead x Cliente): 'app' / 'captura' = lead; 'professor' = veio do link de um professor
$col_origem = pickCol($colunas, ['origem','origem_cadastro','fonte']);
if (!$col_origem) { try { $pdo->exec("ALTER TABLE `$tabela` ADD COLUMN `origem` VARCHAR(40) NULL"); $col_origem = 'origem'; } catch (Throwable $e) {} }

$colmap = compact('col_id','col_nome','col_email','col_tel','col_gen','col_nasc','col_cod','col_cpf','col_block','col_obs','col_dtcad','col_profs','col_objetivos','col_dificuldades','col_notas_prof','col_ranking_optin','col_feed_optin','col_aluno_intus','col_pais','col_estado','col_cidade','col_origem');

function rowToAtleta($row, $map) {
    $out = [
        'idatleta'   => (int)$row[$map['col_id']],
        'nome'       => $row[$map['col_nome']] ?? '',
    ];
    if ($map['col_email']) $out['email']        = $row[$map['col_email']] ?? '';
    if ($map['col_tel'])   $out['telefone']     = $row[$map['col_tel']]   ?? '';
    if ($map['col_gen'])   $out['genero']       = $row[$map['col_gen']]   ?? '';
    if ($map['col_nasc'])  $out['dtnascimento'] = $row[$map['col_nasc']]  ?? null;
    if ($map['col_cod'])   $out['codacesso']    = $row[$map['col_cod']]   ?? '';
    // Se a conta ja tem senha propria definida. NUNCA devolve o hash nem
    // qualquer parte dele — so o fato de existir. Serve para o professor saber,
    // olhando o cadastro, se o aluno consegue entrar com senha ou se depende do
    // codigo de acesso; sem isso a resposta era sempre um chute.
    $out['tem_senha'] = false;
    foreach (['senha', 'dssenha', 'password', 'hash'] as $_cs) {
        if (array_key_exists($_cs, $row)) { $out['tem_senha'] = (trim((string)$row[$_cs]) !== ''); break; }
    }
    if ($map['col_cpf'] ?? null) $out['cpf']   = $row[$map['col_cpf']]   ?? '';
    if ($map['col_block']) $out['stbloqueio']   = $row[$map['col_block']] ?? 'N';
    if ($map['col_obs'])   $out['observacao']   = $row[$map['col_obs']]   ?? '';
    if ($map['col_dtcad']) $out['dtcadastro']   = $row[$map['col_dtcad']] ?? null;
    if ($map['col_profs']) {
        $raw = $row[$map['col_profs']] ?? null;
        $out['professores_responsaveis'] = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    if ($map['col_objetivos'] ?? null) {
        $raw = $row[$map['col_objetivos']] ?? null;
        $out['objetivos'] = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    if ($map['col_dificuldades'] ?? null) {
        $raw = $row[$map['col_dificuldades']] ?? null;
        $out['dificuldades'] = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }
    if ($map['col_notas_prof'] ?? null) {
        $out['notas_professor'] = $row[$map['col_notas_prof']] ?? '';
    }
    if ($map['col_ranking_optin'] ?? null) {
        $out['ranking_optin'] = ($row[$map['col_ranking_optin']] ?? 'S') ?: 'S';
    }
    if ($map['col_feed_optin'] ?? null) {
        $out['feed_optin'] = ($row[$map['col_feed_optin']] ?? 'S') ?: 'S';
    }
    if ($map['col_aluno_intus'] ?? null) {
        $out['aluno_intus'] = ($row[$map['col_aluno_intus']] ?? 'N') ?: 'N';
    }
    if ($map['col_pais'] ?? null)   $out['pais']   = $row[$map['col_pais']]   ?? '';
    if ($map['col_estado'] ?? null) $out['estado'] = $row[$map['col_estado']] ?? '';
    if ($map['col_cidade'] ?? null) $out['cidade'] = $row[$map['col_cidade']] ?? '';
    if ($map['col_origem'] ?? null) $out['origem'] = $row[$map['col_origem']] ?? '';
    return $out;
}

function readJsonBody() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

// Mapeia chaves do payload do frontend → colunas do banco
function setFromBody(array $body, array $colmap) {
    $sets = []; $vals = [];
    $map = [
        'nome'         => 'col_nome',
        'email'        => 'col_email',
        'telefone'     => 'col_tel',
        'genero'       => 'col_gen',
        'dtnascimento' => 'col_nasc',
        'codacesso'    => 'col_cod',
        'cpf'          => 'col_cpf',
        'stbloqueio'   => 'col_block',
        'observacao'   => 'col_obs',
        'pais'         => 'col_pais',
        'estado'       => 'col_estado',
        'cidade'       => 'col_cidade',
        'origem'       => 'col_origem',
    ];
    foreach ($map as $bodyKey => $colKey) {
        if (!array_key_exists($bodyKey, $body)) continue;
        $col = $colmap[$colKey] ?? null;
        if (!$col) continue;
        $val = $body[$bodyKey];
        if (is_string($val)) $val = trim($val);
        if ($val === '') $val = null;
        if ($bodyKey === 'dtnascimento' && $val) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $val, $m)) {
                    $val = "{$m[3]}-{$m[2]}-{$m[1]}";
                } else {
                    $val = null;
                }
            }
        }
        $sets[] = "`$col` = ?";
        $vals[] = $val;
    }
    // professores_responsaveis: armazena como JSON
    if (array_key_exists('professores_responsaveis', $body) && ($colmap['col_profs'] ?? null)) {
        $profs = $body['professores_responsaveis'];
        $sets[] = "`{$colmap['col_profs']}` = ?";
        $vals[] = is_array($profs) ? json_encode($profs) : json_encode([]);
    }
    // notas_professor
    if (array_key_exists('notas_professor', $body) && ($colmap['col_notas_prof'] ?? null)) {
        $sets[] = "`{$colmap['col_notas_prof']}` = ?";
        $vals[] = is_string($body['notas_professor']) ? trim($body['notas_professor']) : '';
    }
    // objetivos / dificuldades (JSON arrays)
    if (array_key_exists('objetivos', $body) && ($colmap['col_objetivos'] ?? null)) {
        $sets[] = "`{$colmap['col_objetivos']}` = ?";
        $vals[] = is_array($body['objetivos']) ? json_encode($body['objetivos'], JSON_UNESCAPED_UNICODE) : json_encode([]);
    }
    if (array_key_exists('dificuldades', $body) && ($colmap['col_dificuldades'] ?? null)) {
        $sets[] = "`{$colmap['col_dificuldades']}` = ?";
        $vals[] = is_array($body['dificuldades']) ? json_encode($body['dificuldades'], JSON_UNESCAPED_UNICODE) : json_encode([]);
    }
    if (array_key_exists('ranking_optin', $body) && ($colmap['col_ranking_optin'] ?? null)) {
        $sets[] = "`{$colmap['col_ranking_optin']}` = ?";
        $vals[] = ($body['ranking_optin'] === 'N') ? 'N' : 'S';
    }
    if (array_key_exists('feed_optin', $body) && ($colmap['col_feed_optin'] ?? null)) {
        $sets[] = "`{$colmap['col_feed_optin']}` = ?";
        $vals[] = ($body['feed_optin'] === 'N') ? 'N' : 'S';
    }
    if (array_key_exists('aluno_intus', $body) && ($colmap['col_aluno_intus'] ?? null)) {
        $sets[] = "`{$colmap['col_aluno_intus']}` = ?";
        $vals[] = ($body['aluno_intus'] === 'S') ? 'S' : 'N';
    }
    return [$sets, $vals];
}

require_once __DIR__ . '/_audit_log.php';
require_once __DIR__ . '/_auth_context.php';

// ---------- Auth Context (filtro por professor) ----------
$_authCtx = getAuthContext($pdo, $tok);
$_isAdmin = $_authCtx['admin'];
$_userId  = $_authCtx['idusuario'];
$_ehAlunoAt = !empty($_authCtx['is_aluno']);
// ─── SEGURANCA: token presente porem nao reconhecido ────────────────────────
// Ate aqui bastava existir QUALQUER texto no cabecalho Authorization. Um token
// invalido produz idusuario = 0, e todos os filtros de acesso abaixo comecam
// com "idusuario > 0" — entao eles eram simplesmente pulados e a resposta saia
// com a base inteira. Na pratica, a palavra "xxxx" no lugar do token devolvia
// todos os clientes, com email e telefone. A verificacao abaixo encerra isso:
// sem sessao valida (ou token legado enquanto PERMITIR_TOKEN_LEGADO estiver
// ligado), a requisicao para no 401 e nao chega em nenhuma consulta.
if ((int)($_authCtx['idusuario'] ?? 0) <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'token invalido']);
    exit;
}

// ── ALUNO NAO ESCREVE NO CADASTRO ──────────────────────────────────────────
// As travas de "so professor vinculado" eram escritas assim:
//     if (!$_isAdmin && $_userId > 0 && !$_authCtx['is_aluno']) { ...403... }
// Repare no ultimo termo: com sessao de ALUNO a condicao inteira ficava falsa,
// o 403 era pulado e a execucao seguia para o UPDATE. Nas rotas de leitura o
// aluno tinha sido tratado; no PUT, no POST e no DELETE, nao. Na pratica
// qualquer aluno logado podia trocar a SENHA de outro (o PUT grava senha),
// bloquear colegas, desvincular professores ou apagar cadastros.
// Cadastro de atleta e coisa de professor. Aluno le o proprio e mais nada.
//
// ATENCAO A ORDEM: $method precisa nascer ANTES desta trava. Ela estava sendo
// lida oito linhas antes da atribuicao, e em PHP variavel indefinida vale null.
// Como null !== 'GET' e verdadeiro, TODA requisicao de aluno caia no 403 —
// inclusive o GET da propria ficha. O app do aluno nao conseguia ler o proprio
// cadastro, e o codigo de filtragem por aluno mais abaixo nunca era alcancado.
// A trava parecia testada e nunca tinha sido exercitada no caminho de escrita.
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = $_GET['action'] ?? '';

// O aluno edita o PROPRIO perfil, e so os campos do perfil. Bloquear tudo era
// forte demais: a tela "Meus dados" existe para ele preencher telefone, data de
// nascimento e cidade, e escolher se participa do ranking. Com o bloqueio total,
// salvar o perfil respondia 403 e a tela dizia "Erro ao salvar no servidor".
// O que continua proibido para o aluno: mexer em OUTRO cadastro, e tocar em
// senha, codigo de acesso, professores vinculados ou bloqueio. A lista branca
// que faz valer isso esta logo abaixo, no proprio PUT.
// Mesmo fallback que a rota de leitura usa mais abaixo: nem toda sessao de
// aluno traz idatleta separado do idusuario.
$_meuIdAt = (int)($_authCtx['idatleta'] ?? $_authCtx['idusuario'] ?? 0);
$_alunoEditaProprio = ($method === 'PUT' && $id > 0 && $_meuIdAt > 0 && $id === $_meuIdAt);
if ($_ehAlunoAt && $method !== 'GET' && !$_alunoEditaProprio) {
    http_response_code(403);
    echo json_encode(['error' => 'apenas o professor altera cadastros']);
    exit;
}

// ---------- ROTAS ----------

try {
    // ===== GET listar =====
    if ($method === 'GET' && !$id) {
        $busca = trim((string)($_GET['busca'] ?? ''));
        $where = [];
        $params = [];
        if ($busca !== '') {
            $where[] = "LOWER($col_nome) LIKE ?";
            $params[] = '%' . strtolower($busca) . '%';
        }
        $sql = "SELECT * FROM `$tabela`";
        if ($where) $sql .= " WHERE " . implode(' AND ', $where);
        $sql .= " ORDER BY $col_nome ASC";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // Backfill de código de acesso: preenche quem ficou sem (autocadastros antigos etc.).
        // Só grava nos vazios — depois de preenchido vira no-op.
        if ($col_cod) {
            $usados = [];
            foreach ($rows as $rr) { $c = trim((string)($rr[$col_cod] ?? '')); if ($c !== '') $usados[$c] = true; }
            $updCod = $pdo->prepare("UPDATE `$tabela` SET `$col_cod` = ? WHERE $col_id = ?");
            foreach ($rows as $k => $rr) {
                if (trim((string)($rr[$col_cod] ?? '')) !== '') continue;
                $novo = '';
                for ($t = 0; $t < 8; $t++) {
                    try { $suf = random_int(0, 9); } catch (Throwable $e) { $suf = 0; }
                    $cand = 'INT' . substr((string)(int)(microtime(true) * 1000), -4) . $suf;
                    if (!isset($usados[$cand])) { $novo = $cand; break; }
                    usleep(1200);
                }
                if ($novo === '') $novo = 'INT' . strtoupper(substr(md5(uniqid('', true)), 0, 5));
                $usados[$novo] = true;
                try { $updCod->execute([$novo, $rr[$col_id]]); $rows[$k][$col_cod] = $novo; } catch (Throwable $e) {}
            }
        }

        $out = array_map(fn($r) => rowToAtleta($r, $colmap), $rows);

        // ALUNO: so pode ver a PROPRIA ficha. Antes o aluno era explicitamente
        // isento do filtro abaixo (!is_aluno) e recebia a lista COMPLETA de
        // clientes — nome, e-mail, telefone e nascimento de todos.
        if (!empty($_authCtx['is_aluno'])) {
            $meu = (int)($_authCtx['idatleta'] ?? $_userId);
            $out = array_values(array_filter($out, function($a) use ($meu) {
                return (int)($a['idatleta'] ?? 0) === $meu;
            }));
            echo json_encode($out);
            exit;
        }

        // Filtro por professor: não-admin vê apenas seus atletas vinculados
        if (!$_isAdmin && $_userId > 0 && !$_authCtx['is_aluno']) {
            $out = array_values(array_filter($out, function($a) use ($_userId) {
                $profs = $a['professores_responsaveis'] ?? [];
                if (empty($profs)) return false; // sem vínculo = só admin vê
                return in_array($_userId, array_map('intval', $profs));
            }));
        }

        echo json_encode($out);
        exit;
    }

    // ===== GET por id =====
    if ($method === 'GET' && $id) {
        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { http_response_code(404); echo json_encode(['error'=>'nao encontrado']); exit; }
        $atleta = rowToAtleta($row, $colmap);

        // ALUNO: so a propria ficha (mesma brecha da listagem, na busca por id).
        if (!empty($_authCtx['is_aluno'])) {
            $meu = (int)($_authCtx['idatleta'] ?? $_userId);
            if ($meu <= 0 || (int)($atleta['idatleta'] ?? 0) !== $meu) {
                http_response_code(403);
                echo json_encode(['error' => 'sem permissao para este atleta']);
                exit;
            }
        }

        // Filtro por professor: não-admin só pode ver atleta vinculado
        if (!$_isAdmin && $_userId > 0 && !$_authCtx['is_aluno']) {
            $profs = $atleta['professores_responsaveis'] ?? [];
            if (empty($profs) || !in_array($_userId, array_map('intval', $profs))) {
                http_response_code(403);
                echo json_encode(['error' => 'sem permissao para este atleta']);
                exit;
            }
        }

        echo json_encode($atleta);
        exit;
    }

    // ===== POST criar =====
    if ($method === 'POST') {
        $body = readJsonBody();
        if (empty(trim((string)($body['nome'] ?? '')))) {
            http_response_code(400);
            echo json_encode(['error'=>'nome obrigatorio']);
            exit;
        }

        // Rejeita duplicata: mesmo nome (case-insensitive) já existe
        $nomeCheck = trim((string)$body['nome']);
        $stDup = $pdo->prepare("SELECT $col_id FROM `$tabela` WHERE LOWER(TRIM($col_nome)) = LOWER(?) LIMIT 1");
        $stDup->execute([$nomeCheck]);
        if ($stDup->fetch()) {
            http_response_code(409);
            echo json_encode(['error'=>'atleta_duplicado', 'nome'=>$nomeCheck]);
            exit;
        }

        // Defaults
        if (!isset($body['stbloqueio'])) $body['stbloqueio'] = 'N';
        if (!isset($body['codacesso']) || !$body['codacesso']) {
            $body['codacesso'] = 'INT' . substr((string)(int)(microtime(true)*1000), -4);
        }
        // Origem: quem já é criado com professor responsável veio do link de um professor (não é lead);
        // sem professor = autocadastro pelo /app ou página de captura = lead.
        if ($col_origem && empty($body['origem'])) {
            $temProf = !empty($body['professores_responsaveis']) && is_array($body['professores_responsaveis']) && count($body['professores_responsaveis']) > 0;
            $body['origem'] = $temProf ? 'professor' : 'app';
        }

        [$sets, $vals] = setFromBody($body, $colmap);
        if (!$sets) { http_response_code(400); echo json_encode(['error'=>'sem campos']); exit; }

        // dtcadastro automatico
        if ($col_dtcad) {
            $sets[] = "`$col_dtcad` = ?";
            $vals[] = date('Y-m-d');
        }

        // senha — hash bcrypt separado do setFromBody genérico
        if ($col_senha && !empty(trim((string)($body['senha'] ?? '')))) {
            $sets[] = "`$col_senha` = ?";
            $vals[] = password_hash(trim($body['senha']), PASSWORD_BCRYPT);
        }

        // Monta INSERT — convertendo SET para colunas/valores
        $cols = []; $phs = []; $newVals = [];
        foreach ($sets as $i => $s) {
            if (preg_match('/`([^`]+)`/', $s, $m)) { $cols[] = $m[1]; $phs[] = '?'; $newVals[] = $vals[$i]; }
        }
        $sql = "INSERT INTO `$tabela` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $phs) . ")";
        $pdo->prepare($sql)->execute($newVals);
        $newId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $st->execute([$newId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        auditLog($pdo, $tok, 'criar', 'atleta', $newId, 'Criou atleta: ' . ($row[$col_nome] ?? ''), null, $row);
        echo json_encode(rowToAtleta($row, $colmap));
        exit;
    }

    // ===== PUT editar =====
    if ($method === 'PUT' && $id) {
        // Capturar estado antes da edição
        $stBefore = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $stBefore->execute([$id]);
        $rowBefore = $stBefore->fetch(PDO::FETCH_ASSOC);

        // Filtro por professor: não-admin só pode editar atleta vinculado
        if (!$_isAdmin && $_userId > 0 && !$_authCtx['is_aluno'] && $rowBefore) {
            $rawProfs = $rowBefore[$col_profs] ?? '';
            $profs = is_string($rawProfs) ? (json_decode($rawProfs, true) ?: []) : [];
            if (empty($profs) || !in_array($_userId, array_map('intval', $profs))) {
                http_response_code(403);
                echo json_encode(['error' => 'sem permissao para editar este atleta']);
                exit;
            }
        }

        $body = readJsonBody();

        // ── LISTA BRANCA DO ALUNO ────────────────────────────────────────
        // Chegando aqui com sessao de aluno, so pode ser o proprio cadastro (a
        // trava la em cima ja garantiu isso). Ainda assim, filtramos os campos:
        // o PUT grava senha e codigo de acesso, e nenhum dos dois pode sair da
        // tela de perfil. Confiar so na trava de cima deixaria uma requisicao
        // montada a mao trocar a propria senha por fora do fluxo de login.
        if ($_ehAlunoAt) {
            $permitidos = ['nome', 'email', 'telefone', 'dtnascimento',
                           'ranking_optin', 'feed_optin', 'pais', 'estado', 'cidade'];
            $body = array_intersect_key((array)$body, array_flip($permitidos));
            if (isset($body['ranking_optin'])) {
                $body['ranking_optin'] = ($body['ranking_optin'] === 'S') ? 'S' : 'N';
            }
            if (isset($body['feed_optin'])) {
                $body['feed_optin'] = ($body['feed_optin'] === 'S') ? 'S' : 'N';
            }
        }

        [$sets, $vals] = setFromBody($body, $colmap);

        // senha — hash bcrypt separado do setFromBody genérico
        if ($col_senha && !empty(trim((string)($body['senha'] ?? '')))) {
            $sets[] = "`$col_senha` = ?";
            $vals[] = password_hash(trim($body['senha']), PASSWORD_BCRYPT);
        }

        if (!$sets) { echo json_encode(['ok'=>true, 'noop'=>true]); exit; }
        $vals[] = $id;
        $sql = "UPDATE `$tabela` SET " . implode(', ', $sets) . " WHERE $col_id = ?";
        $pdo->prepare($sql)->execute($vals);

        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        auditLog($pdo, $tok, 'editar', 'atleta', $id, 'Editou atleta: ' . ($row[$col_nome] ?? ''), $rowBefore, $row);
        echo json_encode(rowToAtleta($row, $colmap));
        exit;
    }

    // ===== DELETE =====
    if ($method === 'DELETE' && $id) {
        // Capturar estado antes
        $stDel = $pdo->prepare("SELECT * FROM `$tabela` WHERE $col_id = ? LIMIT 1");
        $stDel->execute([$id]);
        $rowDel = $stDel->fetch(PDO::FETCH_ASSOC);

        // Filtro por professor: não-admin só pode deletar/bloquear atleta vinculado
        if (!$_isAdmin && $_userId > 0 && !$_authCtx['is_aluno'] && $rowDel) {
            $rawProfs = $rowDel[$col_profs] ?? '';
            $profs = is_string($rawProfs) ? (json_decode($rawProfs, true) ?: []) : [];
            if (empty($profs) || !in_array($_userId, array_map('intval', $profs))) {
                http_response_code(403);
                echo json_encode(['error' => 'sem permissao para este atleta']);
                exit;
            }
        }

        if ($action === 'excluir') {
            $pdo->prepare("DELETE FROM `$tabela` WHERE $col_id = ?")->execute([$id]);
            auditLog($pdo, $tok, 'excluir', 'atleta', $id, 'Excluiu atleta: ' . ($rowDel[$col_nome] ?? ''), $rowDel, null);
            echo json_encode(['ok'=>true, 'removed'=>1]);
            exit;
        }
        // bloqueio (delete soft)
        if ($col_block) {
            $val = ($col_block === 'stativo') ? 'N' : 'S';
            $pdo->prepare("UPDATE `$tabela` SET `$col_block` = ? WHERE $col_id = ?")->execute([$val, $id]);
            auditLog($pdo, $tok, 'bloquear', 'atleta', $id, 'Bloqueou atleta: ' . ($rowDel[$col_nome] ?? ''), $rowDel, null);
            echo json_encode(['ok'=>true, 'bloqueado'=>true]);
            exit;
        }
        // sem coluna de bloqueio — recusa exclusão muda
        http_response_code(400);
        echo json_encode(['error'=>'sem coluna stbloqueio — use action=excluir']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error'=>'rota nao reconhecida', 'method'=>$method, 'id'=>$id, 'action'=>$action]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'falha', 'detalhe' => _intusLogErro($e)]);
}
