<?php
/**
 * EXTRAS: conteúdos bônus do app do aluno (aulas gravadas, lives, treinos para fazer em casa, módulos como o Cintura de Atleta...), no
 * modelo do CDD: MÓDULOS (capa, título, descrição) com CONTEÚDOS dentro (vídeo, live, material ou link). Biblioteca interna: o "_" no nome
 * faz o .htaccess bloquear o acesso direto. Quem expõe isto é extras.php.
 *
 * Decisões que não são óbvias:
 *  - O CATÁLOGO DE FÁBRICA (enquanto o administrador não salvou nada) são os 4 módulos importados do CDD (_extras_cdd.php): Cintura de Atleta,
 *    Treinos em casa, Assista antes de treinar e Lives e aulas exclusivas, publicados para alunos ativos. Aula que o CDD guardava como rascunho entra como
 *    rascunho. O botão "Importar do CDD" do painel traz de volta o que ainda não está no catálogo (nunca sobrescreve o que o administrador editou).
 *  - NENHUMA tabela nova: o catálogo inteiro é um JSON em intus_config (chave extras_conteudo), como os funis da Central de atendimento.
 *    São dezenas de itens editados só pelo administrador, então um JSON basta e dispensa migração de banco.
 *  - QUEM VÊ é decidido AQUI, no servidor: o aluno só recebe os conteúdos que pode ver (a lista dos outros nem chega ao aparelho dele).
 *  - Visibilidade por módulo; cada conteúdo pode ter a sua (senão herda a do módulo). Um módulo só aparece para o aluno se tiver pelo menos
 *    um conteúdo publicado e visível para ele.
 *      todos          qualquer aluno logado (inclusive teste grátis)
 *      ativos         plano pago em dia (não conta o teste grátis; carência de 5 dias, igual ao resto do app)
 *      nutricao       plano de nutrição ou combo, OU plano alimentar ativo
 *      treino         plano de treino ou combo
 *      selecionados   só os alunos escolhidos pelo administrador
 *  - Plano de nutrição / treino / combo vem do TEXTO da matrícula (tipo_plano e descrição), porque é assim que os planos são nomeados.
 *    "Combo", "Treino + Dieta" e semelhantes contam como os dois. Plano sem a palavra nutrição/dieta conta como treino (Personal, Consultoria...).
 *  - Só endereços https:// entram nos links (nada de javascript: ou data:).
 *  - SQL portável (roda em SQLite nos testes e no MySQL da produção).
 */

require_once __DIR__ . '/_plano_aluno.php';
require_once __DIR__ . '/_extras_cdd.php';

const EXTRAS_CHAVE = 'extras_conteudo';
const EXTRAS_MODOS = ['todos', 'ativos', 'nutricao', 'treino', 'selecionados'];
const EXTRAS_TIPOS = ['video', 'live', 'material', 'link'];
// Versão das imagens de capa que moram no próprio site (app/painel/extras/capas). Sobe quando as capas forem refeitas (junto com VERSAO em
// tools/extras/gerar-catalogo.py). O servidor acrescenta ?v=<versão> a toda capa do site na hora de ENTREGAR, mesmo em catálogo já salvo pelo
// administrador: sem isso o Cloudflare e o navegador seguem servindo a imagem antiga no mesmo endereço por horas.
const EXTRAS_CAPAS_VERSAO = '5';
const EXTRAS_MAX_MODULOS = 60;
const EXTRAS_MAX_ITENS = 200;
const EXTRAS_MAX_SELECIONADOS = 500;
const EXTRAS_ROTULO_MODO = ['todos' => 'Todos os alunos', 'ativos' => 'Só alunos ativos', 'nutricao' => 'Alunos de nutrição ou combo', 'treino' => 'Alunos de treino ou combo', 'selecionados' => 'Alunos escolhidos'];

// ─────────────────────────────────────────────────────────────────────────────
// Armazenamento (intus_config)
// ─────────────────────────────────────────────────────────────────────────────

function extras_garantir_config(PDO $pdo): void {
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

function extras_agora(): string { return gmdate('Y-m-d H:i:s'); }
function extras_novo_id(): string { return bin2hex(random_bytes(5)); }

/** Catálogo salvo, ou o de fábrica (módulos vazios e NÃO publicados) enquanto o administrador não salvou nada. */
function extras_ler(PDO $pdo): array {
    extras_garantir_config($pdo);
    $st = $pdo->prepare('SELECT valor FROM intus_config WHERE chave = ? LIMIT 1');
    $st->execute([EXTRAS_CHAVE]);
    $v = $st->fetchColumn();
    $a = ($v === false || $v === null || $v === '') ? null : json_decode((string)$v, true);
    if (!is_array($a) || !isset($a['categorias']) || !is_array($a['categorias'])) return extras_padrao();
    $a['salvo'] = true;
    return $a;
}

function extras_gravar(PDO $pdo, array $dados): void {
    extras_garantir_config($pdo);
    unset($dados['salvo']);
    $json = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('conteudo nao serializavel');
    $st = $pdo->prepare('SELECT 1 FROM intus_config WHERE chave = ? LIMIT 1');
    $st->execute([EXTRAS_CHAVE]);
    if ($st->fetchColumn()) $pdo->prepare('UPDATE intus_config SET valor = ? WHERE chave = ?')->execute([$json, EXTRAS_CHAVE]);
    else $pdo->prepare('INSERT INTO intus_config (chave, valor) VALUES (?, ?)')->execute([EXTRAS_CHAVE, $json]);
}

/** Catálogo de fábrica: os módulos importados do CDD, publicados para alunos ativos (o administrador edita, reordena e inclui os próximos). */
function extras_padrao(): array {
    return ['versao' => 1, 'salvo' => false, 'categorias' => extras_catalogo_cdd()];
}

/**
 * Traz do CDD o que ainda não está no catálogo salvo: módulo que não existe é criado inteiro; módulo que existe VAZIO (por exemplo os de exemplo da
 * primeira versão) é preenchido com as aulas, capa e regra do CDD. Módulo que já tem conteúdo nunca é tocado. Idempotente.
 * Devolve ['criados' => n, 'preenchidos' => n].
 */
function extras_importar_cdd(PDO $pdo): array {
    $dados = extras_ler($pdo);
    $criados = 0; $preenchidos = 0;
    foreach (extras_catalogo_cdd() as $m) {
        $i = extras_achar_categoria($dados, $m['id']);
        if ($i < 0) { $dados['categorias'][] = $m; $criados++; continue; }
        if (empty($dados['categorias'][$i]['itens'])) { $dados['categorias'][$i] = array_merge($dados['categorias'][$i], ['itens' => $m['itens'], 'capa' => $m['capa'], 'descricao' => $m['descricao'], 'publicado' => $m['publicado'], 'visibilidade' => $m['visibilidade']]); $preenchidos++; }
    }
    if ($criados || $preenchidos) extras_gravar($pdo, $dados);
    return ['criados' => $criados, 'preenchidos' => $preenchidos];
}

// ─────────────────────────────────────────────────────────────────────────────
// Limpeza do que vem da tela
// ─────────────────────────────────────────────────────────────────────────────

function extras_texto($v, int $max): string {
    $s = is_string($v) ? $v : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return mb_substr(trim($s), 0, $max);
}

/** Só https:// e sem espaço. Qualquer outra coisa vira vazio (nada de javascript:, data:, http:). */
function extras_url($v): string {
    $u = extras_texto($v, 600);
    if ($u === '' || !preg_match('#^https://[^\s<>"\']+$#i', $u)) return '';
    return $u;
}

function extras_cor($v, string $padrao = '#0ea5e9'): string {
    $c = is_string($v) ? trim($v) : '';
    return preg_match('/^#[0-9a-fA-F]{6}$/', $c) ? strtolower($c) : $padrao;
}

function extras_limpar_visibilidade($v, bool $permiteHerdar = false): ?array {
    if ($permiteHerdar && ($v === null || $v === '' || (is_array($v) && (($v['modo'] ?? '') === 'herdar' || !isset($v['modo']))))) return null;
    $modo = (is_array($v) && in_array($v['modo'] ?? '', EXTRAS_MODOS, true)) ? $v['modo'] : 'ativos';
    $ids = [];
    if ($modo === 'selecionados' && is_array($v['ids'] ?? null)) {
        foreach ($v['ids'] as $i) { $n = (int)$i; if ($n > 0) $ids[$n] = $n; if (count($ids) >= EXTRAS_MAX_SELECIONADOS) break; }
    }
    return ['modo' => $modo, 'ids' => array_values($ids)];
}

function extras_limpar_item(array $d, ?array $atual = null): array {
    $tipo = in_array($d['tipo'] ?? '', EXTRAS_TIPOS, true) ? $d['tipo'] : 'video';
    $titulo = extras_texto($d['titulo'] ?? '', 140);
    if ($titulo === '') throw new InvalidArgumentException('Dê um título ao conteúdo.');
    $url = extras_url($d['url'] ?? '');
    if (trim((string)($d['url'] ?? '')) !== '' && $url === '') throw new InvalidArgumentException('O link precisa começar com https://.');
    $data = extras_texto($d['data'] ?? '', 16);
    if ($data !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}(T| )\d{2}:\d{2}$/', $data)) $data = '';
    $capa = extras_url($d['capa'] ?? '');
    if (trim((string)($d['capa'] ?? '')) !== '' && $capa === '') throw new InvalidArgumentException('O link da imagem precisa começar com https://.');
    return [
        'id' => $atual['id'] ?? extras_novo_id(),
        'titulo' => $titulo,
        'descricao' => extras_texto($d['descricao'] ?? '', 1200),
        'tipo' => $tipo,
        'url' => $url,
        'capa' => $capa,
        'duracao' => extras_texto($d['duracao'] ?? '', 20),
        'data' => str_replace(' ', 'T', $data),
        'publicado' => !empty($d['publicado']),
        'visibilidade' => extras_limpar_visibilidade($d['visibilidade'] ?? null, true),
        'criado_em' => $atual['criado_em'] ?? extras_agora(),
    ];
}

function extras_limpar_categoria(array $d, ?array $atual = null): array {
    $titulo = extras_texto($d['titulo'] ?? '', 100);
    if ($titulo === '') throw new InvalidArgumentException('Dê um nome ao módulo.');
    $capa = extras_url($d['capa'] ?? '');
    if (trim((string)($d['capa'] ?? '')) !== '' && $capa === '') throw new InvalidArgumentException('O link da capa precisa começar com https://.');
    $emoji = extras_texto($d['emoji'] ?? '', 8);
    return [
        'id' => $atual['id'] ?? extras_novo_id(),
        'titulo' => $titulo,
        'descricao' => extras_texto($d['descricao'] ?? '', 400),
        'emoji' => $emoji !== '' ? $emoji : '🎁',
        'cor' => extras_cor($d['cor'] ?? '', $atual['cor'] ?? '#0ea5e9'),
        'capa' => $capa,
        'publicado' => !empty($d['publicado']),
        'visibilidade' => extras_limpar_visibilidade($d['visibilidade'] ?? null) ?? ['modo' => 'ativos', 'ids' => []],
        'itens' => $atual['itens'] ?? [],
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Operações do administrador
// ─────────────────────────────────────────────────────────────────────────────

function extras_achar_categoria(array $dados, string $id): int {
    foreach ($dados['categorias'] as $i => $c) { if (($c['id'] ?? '') === $id) return (int)$i; }
    return -1;
}

function extras_salvar_categoria(PDO $pdo, array $d): array {
    $dados = extras_ler($pdo);
    $id = (string)($d['id'] ?? '');
    $i = $id !== '' ? extras_achar_categoria($dados, $id) : -1;
    if ($i < 0 && count($dados['categorias']) >= EXTRAS_MAX_MODULOS) throw new InvalidArgumentException('Limite de ' . EXTRAS_MAX_MODULOS . ' módulos atingido.');
    $nova = extras_limpar_categoria($d, $i >= 0 ? $dados['categorias'][$i] : null);
    if ($i >= 0) $dados['categorias'][$i] = $nova; else $dados['categorias'][] = $nova;
    extras_gravar($pdo, $dados);
    return $nova;
}

function extras_excluir_categoria(PDO $pdo, string $id): bool {
    $dados = extras_ler($pdo);
    $i = extras_achar_categoria($dados, $id);
    if ($i < 0) return false;
    array_splice($dados['categorias'], $i, 1);
    extras_gravar($pdo, $dados);
    return true;
}

function extras_salvar_item(PDO $pdo, string $categoria, array $d): array {
    $dados = extras_ler($pdo);
    $ci = extras_achar_categoria($dados, $categoria);
    if ($ci < 0) throw new InvalidArgumentException('Módulo não encontrado.');
    $itens = $dados['categorias'][$ci]['itens'] ?? [];
    $id = (string)($d['id'] ?? '');
    $ii = -1;
    if ($id !== '') foreach ($itens as $k => $it) { if (($it['id'] ?? '') === $id) { $ii = (int)$k; break; } }
    if ($ii < 0 && count($itens) >= EXTRAS_MAX_ITENS) throw new InvalidArgumentException('Limite de ' . EXTRAS_MAX_ITENS . ' conteúdos por módulo atingido.');
    $novo = extras_limpar_item($d, $ii >= 0 ? $itens[$ii] : null);
    if ($ii >= 0) $itens[$ii] = $novo; else $itens[] = $novo;
    $dados['categorias'][$ci]['itens'] = array_values($itens);
    extras_gravar($pdo, $dados);
    return $novo;
}

function extras_excluir_item(PDO $pdo, string $categoria, string $id): bool {
    $dados = extras_ler($pdo);
    $ci = extras_achar_categoria($dados, $categoria);
    if ($ci < 0) return false;
    $antes = count($dados['categorias'][$ci]['itens'] ?? []);
    $dados['categorias'][$ci]['itens'] = array_values(array_filter($dados['categorias'][$ci]['itens'] ?? [], fn($it) => ($it['id'] ?? '') !== $id));
    if (count($dados['categorias'][$ci]['itens']) === $antes) return false;
    extras_gravar($pdo, $dados);
    return true;
}

/** Reordena módulos (tipo "categoria") ou os conteúdos de um módulo (tipo "item"). Ids que faltam na lista ficam no fim, na ordem de antes. */
function extras_reordenar(PDO $pdo, string $tipo, string $categoria, array $ids): void {
    $dados = extras_ler($pdo);
    $ordem = array_values(array_filter(array_map('strval', $ids), fn($x) => $x !== ''));
    $porOrdem = function (array $lista) use ($ordem) {
        $mapa = []; foreach ($lista as $x) $mapa[(string)$x['id']] = $x;
        $out = [];
        foreach ($ordem as $id) { if (isset($mapa[$id])) { $out[] = $mapa[$id]; unset($mapa[$id]); } }
        foreach ($mapa as $x) $out[] = $x;
        return $out;
    };
    if ($tipo === 'categoria') {
        $dados['categorias'] = $porOrdem($dados['categorias']);
    } elseif ($tipo === 'item') {
        $ci = extras_achar_categoria($dados, $categoria);
        if ($ci < 0) throw new InvalidArgumentException('Módulo não encontrado.');
        $dados['categorias'][$ci]['itens'] = $porOrdem($dados['categorias'][$ci]['itens'] ?? []);
    } else {
        throw new InvalidArgumentException('Tipo de ordenação inválido.');
    }
    extras_gravar($pdo, $dados);
}

// ─────────────────────────────────────────────────────────────────────────────
// Quem pode ver
// ─────────────────────────────────────────────────────────────────────────────

const EXTRAS_RX_NUTRICAO = '/nutri|dieta|combo/iu';

/**
 * Perfil de planos de UM aluno, a partir das linhas de intus_mensalidade (dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto, stmatricula).
 * ativo = tem plano pago, que não é o teste grátis, cuja cobertura ainda vale (ou venceu há até 5 dias).
 * nutricao / treino só contam sobre os planos ATIVOS. teste = tem teste grátis vigente.
 */
function extras_perfil_de_linhas(array $linhas, string $hoje): array {
    $perfil = ['ativo' => false, 'treino' => false, 'nutricao' => false, 'teste' => false];
    $limite = date('Y-m-d', strtotime($hoje . ' -' . INTUS_PLANO_CARENCIA_DIAS . ' days'));
    foreach ($linhas as $m) {
        $st = strtolower((string)($m['stmatricula'] ?? ''));
        if (in_array($st, ['cancelada', 'encerrada', 'trancada', 'inativa'], true)) continue;
        $venc = substr((string)($m['dtvencimento'] ?? ''), 0, 10);
        if ($venc === '') continue;
        if (intusMatriculaEhTeste($m)) {
            $ini = substr((string)($m['dtinicio'] ?? ''), 0, 10);
            if ($venc >= $hoje && ($ini === '' || $ini <= $hoje)) $perfil['teste'] = true;
            continue;
        }
        if (strtoupper((string)($m['stpgto'] ?? '')) !== 'S' || $venc < $limite) continue;
        $perfil['ativo'] = true;
        $texto = (string)($m['tipo_plano'] ?? '') . ' ' . (string)($m['dshistorico'] ?? '');
        $nutri = preg_match(EXTRAS_RX_NUTRICAO, $texto) === 1;
        $soNutri = $nutri && preg_match('/treino|personal|consultoria|combo|\+/iu', $texto) !== 1;
        if ($nutri) $perfil['nutricao'] = true;
        if (!$soNutri) $perfil['treino'] = true;
    }
    return $perfil;
}

function extras_perfil_aluno(PDO $pdo, int $idatleta, ?string $hoje = null): array {
    $hoje = $hoje ?? date('Y-m-d');
    $vazio = ['ativo' => false, 'treino' => false, 'nutricao' => false, 'teste' => false];
    if ($idatleta <= 0) return $vazio;
    try {
        $st = $pdo->prepare('SELECT * FROM intus_mensalidade WHERE idatleta = ?');
        $st->execute([$idatleta]);
        $p = extras_perfil_de_linhas($st->fetchAll(PDO::FETCH_ASSOC), $hoje);
    } catch (Throwable $e) { $p = $vazio; }
    // Plano alimentar ativo também conta como nutrição (é o mesmo critério que libera a área de Nutrição no app).
    try {
        $q = $pdo->prepare('SELECT 1 FROM intus_plano_nutricional WHERE idatleta = ? AND ativo = 1 LIMIT 1');
        $q->execute([$idatleta]);
        if ($q->fetchColumn()) $p['nutricao'] = true;
    } catch (Throwable $e) {}
    return $p;
}

function extras_pode_ver(?array $vis, array $perfil, int $idatleta): bool {
    $modo = $vis['modo'] ?? 'ativos';
    switch ($modo) {
        case 'todos': return true;
        case 'ativos': return !empty($perfil['ativo']);
        case 'nutricao': return !empty($perfil['nutricao']);
        case 'treino': return !empty($perfil['treino']);
        case 'selecionados': return in_array($idatleta, array_map('intval', (array)($vis['ids'] ?? [])), true);
    }
    return false;
}

/** Capa do próprio site com a versão atual na URL; qualquer outro endereço passa sem mudança. */
function extras_capa_versionada($url): string {
    $u = (string)$url;
    if (preg_match('#^(https://intusfit\.com\.br/app/painel/extras/capas/[\w\-]+\.jpg)(\?.*)?$#', $u, $m)) return $m[1] . '?v=' . EXTRAS_CAPAS_VERSAO;
    return $u;
}

function extras_versionar_capas(array $categorias): array {
    foreach ($categorias as $i => $c) {
        $categorias[$i]['capa'] = extras_capa_versionada($c['capa'] ?? '');
        foreach ($c['itens'] ?? [] as $k => $it) $categorias[$i]['itens'][$k]['capa'] = extras_capa_versionada($it['capa'] ?? '');
    }
    return $categorias;
}

/** O que o aluno recebe: só módulos publicados com pelo menos um conteúdo publicado e visível para ele, sem nenhuma configuração de acesso. */
function extras_para_aluno(array $dados, array $perfil, int $idatleta): array {
    $out = [];
    foreach ($dados['categorias'] ?? [] as $c) {
        if (empty($c['publicado'])) continue;
        $itens = [];
        foreach ($c['itens'] ?? [] as $it) {
            if (empty($it['publicado'])) continue;
            $vis = $it['visibilidade'] ?? ($c['visibilidade'] ?? null);
            if (!extras_pode_ver($vis, $perfil, $idatleta)) continue;
            $itens[] = ['id' => $it['id'], 'titulo' => $it['titulo'], 'descricao' => $it['descricao'] ?? '', 'tipo' => $it['tipo'] ?? 'video',
                        'url' => $it['url'] ?? '', 'capa' => extras_capa_versionada($it['capa'] ?? ''), 'duracao' => $it['duracao'] ?? '', 'data' => $it['data'] ?? '', 'criado_em' => $it['criado_em'] ?? ''];
        }
        if (!$itens) continue;
        $out[] = ['id' => $c['id'], 'titulo' => $c['titulo'], 'descricao' => $c['descricao'] ?? '', 'emoji' => $c['emoji'] ?? '🎁', 'cor' => $c['cor'] ?? '#0ea5e9',
                  'capa' => extras_capa_versionada($c['capa'] ?? ''), 'itens' => $itens];
    }
    return $out;
}

/** Para a tela do administrador: quantos alunos cada regra alcança hoje. Só números, nenhum nome. */
function extras_alcance(PDO $pdo, ?string $hoje = null): array {
    $hoje = $hoje ?? date('Y-m-d');
    $alcance = ['todos' => 0, 'ativos' => 0, 'nutricao' => 0, 'treino' => 0];
    try {
        $ids = array_map('intval', $pdo->query("SELECT idatleta FROM atleta WHERE (stbloqueio IS NULL OR stbloqueio != 'S') AND (email IS NULL OR email NOT LIKE '%@intusfit.local')")->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return $alcance; }
    $porAluno = [];
    try {
        foreach ($pdo->query('SELECT * FROM intus_mensalidade')->fetchAll(PDO::FETCH_ASSOC) as $m) $porAluno[(int)$m['idatleta']][] = $m;
    } catch (Throwable $e) {}
    $nutriAtiva = [];
    try { foreach ($pdo->query('SELECT DISTINCT idatleta FROM intus_plano_nutricional WHERE ativo = 1')->fetchAll(PDO::FETCH_COLUMN) as $i) $nutriAtiva[(int)$i] = true; } catch (Throwable $e) {}
    foreach ($ids as $i) {
        $p = extras_perfil_de_linhas($porAluno[$i] ?? [], $hoje);
        if (isset($nutriAtiva[$i])) $p['nutricao'] = true;
        $alcance['todos']++;
        foreach (['ativo' => 'ativos', 'nutricao' => 'nutricao', 'treino' => 'treino'] as $k => $modo) { if (!empty($p[$k])) $alcance[$modo]++; }
    }
    return $alcance;
}
