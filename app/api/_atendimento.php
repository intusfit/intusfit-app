<?php
/**
 * Central de atendimento (copy, funis, referências, testes A/B e métricas): biblioteca.
 *
 * Toda regra mora aqui; atendimento.php só autentica e chama estas funções.
 * Depende de _wa.php (conexão, tabelas do WhatsApp, segredos, fuso).
 *
 * Decisões que não são óbvias:
 *  - Nenhuma tabela nova. O que Luiz edita fica em intus_config (chaves atd_*), um JSON por chave,
 *    e arquivos de referência ficam no próprio banco (backup diário já leva). Sem pasta pública.
 *  - O funil fica codificado em intus_wa_agenda.passo: o funil "teste-gratis" mantém os passos
 *    t1..t7 (compatível com wa_agenda_do_teste); os demais usam "<funil>:<mensagem>".
 *  - Teste A/B sorteia a variante pelo telefone (crc32), então a mesma pessoa cai sempre no mesmo
 *    lado e o resultado é reproduzível.
 *  - O robô de conversa e a fila de envio ainda não leem estes funis. Esta biblioteca já entrega o
 *    que eles vão precisar: atd_agenda_do_funil, atd_variante, atd_renderizar, atd_exportar.
 *  - SQL portável (roda em SQLite nos testes e no MySQL de produção).
 */

require_once __DIR__ . '/_wa.php';
require_once __DIR__ . '/_atendimento_conteudo.php';
require_once __DIR__ . '/_provas.php';
require_once __DIR__ . '/_trafego.php';   // só trRelatorio e trGarantirTabelas (versão já publicada)

const ATD_MAX_MENSAGENS_FUNIL = 30;
const ATD_MAX_TEXTO_MENSAGEM = 1024;
const ATD_MAX_ARQUIVO_BYTES = 3145728;      // 3 MB por arquivo
const ATD_MAX_ARQUIVOS = 20;
const ATD_MAX_LINKS = 60;
const ATD_MAX_TEXTO_REF = 20000;            // caracteres guardados por referência
const ATD_MAX_CONTEXTO_REFS = 40000;        // caracteres de referência enviados ao Claude por pedido
const ATD_MAX_CHAMADAS_DIA = 60;
const ATD_USD_ENTRADA_MTOK = 4.0;           // claude-opus-5-5
const ATD_USD_SAIDA_MTOK = 20.0;

// ─────────────────────────────────────────────────────────────────────────────
// Armazenamento (intus_config)
// ─────────────────────────────────────────────────────────────────────────────

function atd_garantir_config(PDO $pdo): void {
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

function atd_cfg_ler(PDO $pdo, string $chave, $padrao = null) {
    atd_garantir_config($pdo);
    $st = $pdo->prepare('SELECT valor FROM intus_config WHERE chave = ? LIMIT 1');
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    if ($v === false || $v === null || $v === '') return $padrao;
    $a = json_decode((string)$v, true);
    return $a === null ? $padrao : $a;
}

function atd_cfg_existe(PDO $pdo, string $chave): bool {
    atd_garantir_config($pdo);
    $st = $pdo->prepare('SELECT 1 FROM intus_config WHERE chave = ? LIMIT 1');
    $st->execute([$chave]);
    return (bool)$st->fetchColumn();
}

function atd_cfg_gravar(PDO $pdo, string $chave, $valor): void {
    atd_garantir_config($pdo);
    $json = json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('conteudo nao serializavel');
    if (atd_cfg_existe($pdo, $chave)) {
        $pdo->prepare('UPDATE intus_config SET valor = ? WHERE chave = ?')->execute([$json, $chave]);
    } else {
        $pdo->prepare('INSERT INTO intus_config (chave, valor) VALUES (?, ?)')->execute([$chave, $json]);
    }
}

function atd_cfg_apagar(PDO $pdo, string $chave): void {
    atd_garantir_config($pdo);
    $pdo->prepare('DELETE FROM intus_config WHERE chave = ?')->execute([$chave]);
}

function atd_novo_id(): string { return bin2hex(random_bytes(5)); }

function atd_slug(string $s, int $max = 24): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return rtrim(substr($s, 0, $max), '-');
}

// ─────────────────────────────────────────────────────────────────────────────
// Funis
// ─────────────────────────────────────────────────────────────────────────────

const ATD_CONDICOES = ['todos', 'sem_treino', 'com_treino', 'sem_resposta'];
const ATD_TIPOS_MENSAGEM = ['agendada', 'resposta', 'silencio'];
const ATD_STATUS_FUNIL = ['rascunho', 'aprovado', 'pausado'];

function atd_texto_limpo($v, int $max): string {
    $s = is_string($v) ? $v : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return mb_substr(trim($s), 0, $max);
}

function atd_limpar_mensagem(array $m, int $pos): array {
    $id = atd_slug((string)($m['id'] ?? ''), 12);
    if ($id === '') $id = 'm' . ($pos + 1);
    $tipo = in_array($m['tipo'] ?? '', ATD_TIPOS_MENSAGEM, true) ? $m['tipo'] : 'agendada';
    $cond = in_array($m['condicao'] ?? '', ATD_CONDICOES, true) ? $m['condicao'] : 'todos';
    $hora = $m['hora'] ?? null;
    $hora = ($hora === null || $hora === '' ) ? null : max(0, min(23, (int)$hora));
    $cat = atd_texto_limpo($m['categoria'] ?? '', 20);
    return [
        'id' => $id,
        'nome' => atd_texto_limpo($m['nome'] ?? '', 80) ?: ('Mensagem ' . ($pos + 1)),
        'tipo' => $tipo,
        'dia' => max(0, min(365, (int)($m['dia'] ?? 0))),
        'hora' => $hora,
        'minutos' => max(0, min(43200, (int)($m['minutos'] ?? 0))),
        'condicao' => $cond,
        'categoria' => $cat,
        'template' => preg_replace('/[^a-z0-9_]/', '', strtolower((string)($m['template'] ?? ''))),
        'aprovada' => !empty($m['aprovada']),
        'por_plano' => !empty($m['por_plano']),   // uma opção para cada plano de Gestão de Planos ({{5}}, {{6}} e {{7}})
        'texto' => atd_texto_limpo($m['texto'] ?? '', ATD_MAX_TEXTO_MENSAGEM),
        'conceito' => atd_texto_limpo($m['conceito'] ?? '', 120),
        'por_que' => atd_texto_limpo($m['por_que'] ?? '', 600),
    ];
}

/** Valida e normaliza um funil vindo da tela. Lança InvalidArgumentException com texto para o usuário. */
/** Funis de fábrica criados depois do primeiro lançamento da Central: aparecem para quem já salvou os funis. */
const ATD_FUNIS_NOVOS = ['recuperacao-teste', 'fechamento'];

function atd_limpar_funil(array $f): array {
    $id = atd_slug((string)($f['id'] ?? ''), 24);
    if ($id === '') $id = atd_slug((string)($f['nome'] ?? ''), 24);
    if ($id === '') throw new InvalidArgumentException('Dê um nome ao funil.');
    $msgs = [];
    $vistos = [];
    foreach (array_slice((array)($f['mensagens'] ?? []), 0, ATD_MAX_MENSAGENS_FUNIL) as $i => $m) {
        if (!is_array($m)) continue;
        $lim = atd_limpar_mensagem($m, (int)$i);
        $base = $lim['id']; $n = 2;
        while (isset($vistos[$lim['id']])) { $lim['id'] = substr($base, 0, 10) . $n++; }
        $vistos[$lim['id']] = true;
        $msgs[] = $lim;
    }
    $vars = [];
    foreach ((array)($f['variaveis'] ?? []) as $k => $v) {
        if (preg_match('/^[1-9]$/', (string)$k)) $vars[(string)$k] = atd_texto_limpo((string)$v, 60);
    }
    return [
        'id' => $id,
        'nome' => atd_texto_limpo($f['nome'] ?? '', 100) ?: $id,
        'status' => in_array($f['status'] ?? '', ATD_STATUS_FUNIL, true) ? $f['status'] : 'rascunho',
        'gatilho' => atd_texto_limpo($f['gatilho'] ?? '', 300),
        'objetivo' => atd_texto_limpo($f['objetivo'] ?? '', 400),
        'variaveis' => $vars ?: ['1' => 'nome'],
        'padrao' => !empty($f['padrao']),
        'mensagens' => $msgs,
    ];
}

/** Lista de funis: o que Luiz salvou, ou o padrão de fábrica enquanto não salvou nada. */
function atd_funis(PDO $pdo): array {
    $salvo = atd_cfg_ler($pdo, 'atd_funis', null);
    if (!is_array($salvo)) return atd_funis_padrao();
    $out = [];
    foreach ($salvo as $f) { if (is_array($f)) $out[] = $f; }
    // funil de fábrica criado depois do último salvamento: entra sozinho, a não ser que a pessoa o tenha excluído
    $tem = array_column($out, 'id');
    $excluidos = atd_cfg_ler($pdo, 'atd_funis_excluidos', []);
    foreach (atd_funis_padrao() as $f) {
        if (in_array($f['id'], ATD_FUNIS_NOVOS, true) && !in_array($f['id'], $tem, true) && !in_array($f['id'], (array)$excluidos, true)) $out[] = $f;
    }
    return $out;
}

function atd_funil_por_id(PDO $pdo, string $id): ?array {
    foreach (atd_funis($pdo) as $f) { if (($f['id'] ?? '') === $id) return $f; }
    return null;
}

function atd_funil_salvar(PDO $pdo, array $funil): array {
    $novo = atd_limpar_funil($funil);
    $lista = atd_funis($pdo);
    $achou = false;
    foreach ($lista as $i => $f) {
        if (($f['id'] ?? '') === $novo['id']) { $lista[$i] = $novo; $achou = true; break; }
    }
    if (!$achou) $lista[] = $novo;
    atd_cfg_gravar($pdo, 'atd_funis', array_values($lista));
    return $novo;
}

function atd_funil_excluir(PDO $pdo, string $id): bool {
    $lista = atd_funis($pdo);
    $fica = array_values(array_filter($lista, fn($f) => ($f['id'] ?? '') !== $id));
    if (count($fica) === count($lista)) return false;
    if (in_array($id, ATD_FUNIS_NOVOS, true)) {
        $ex = (array)atd_cfg_ler($pdo, 'atd_funis_excluidos', []);
        if (!in_array($id, $ex, true)) { $ex[] = $id; atd_cfg_gravar($pdo, 'atd_funis_excluidos', array_values($ex)); }
    }
    atd_cfg_gravar($pdo, 'atd_funis', $fica);
    return true;
}

/** Volta um funil de fábrica ao texto original. Devolve null se o id não é de fábrica. */
function atd_funil_restaurar(PDO $pdo, string $id): ?array {
    foreach (atd_funis_padrao() as $p) {
        if ($p['id'] === $id) return atd_funil_salvar($pdo, $p);
    }
    return null;
}

function atd_funil_duplicar(PDO $pdo, string $id, string $nome): array {
    $orig = atd_funil_por_id($pdo, $id);
    if (!$orig) throw new InvalidArgumentException('Funil não encontrado.');
    $nome = atd_texto_limpo($nome, 100) ?: ($orig['nome'] . ' (variante)');
    $novoId = atd_slug($nome, 20) ?: 'variante';
    $existentes = array_column(atd_funis($pdo), 'id');
    $base = $novoId; $n = 2;
    while (in_array($novoId, $existentes, true)) { $novoId = substr($base, 0, 18) . '-' . $n++; }
    $copia = $orig;
    $copia['id'] = $novoId;
    $copia['nome'] = $nome;
    $copia['status'] = 'rascunho';
    $copia['padrao'] = false;
    foreach ($copia['mensagens'] as $i => $m) {
        $copia['mensagens'][$i]['aprovada'] = false;
        if (!empty($m['template'])) $copia['mensagens'][$i]['template'] = str_replace('-', '_', substr($novoId, 0, 14)) . '_' . $m['id'];
    }
    return atd_funil_salvar($pdo, $copia);
}

/** Valor gravado em intus_wa_agenda.passo para uma mensagem de um funil. */
function atd_passo(string $funilId, string $msgId): string {
    if ($funilId === 'teste-gratis') return $msgId;
    return substr($funilId . ':' . $msgId, 0, 30);
}

/** Inverso de atd_passo: devolve [funilId, msgId]. Passos antigos (t1..t7) são do teste grátis. */
function atd_passo_decodificar(string $passo): array {
    if (strpos($passo, ':') !== false) {
        [$f, $m] = explode(':', $passo, 2);
        return [$f, $m];
    }
    return ['teste-gratis', $passo];
}

/**
 * Agenda de um funil a partir de uma data-base (cadastro, ou o fim do teste no funil de reativação).
 * Só mensagens do tipo "agendada". Mesmo formato de wa_agenda_do_teste: [passo, DateTimeImmutable, condicao].
 * Mensagem sem hora sai logo na primeira hora permitida.
 */
function atd_agenda_do_funil(array $funil, DateTimeImmutable $base, int $ini = 8, int $fim = 21): array {
    $base = $base->setTimezone(wa_tz());
    $dia0 = $base->setTime(0, 0, 0);
    $out = [];
    foreach ($funil['mensagens'] ?? [] as $m) {
        if (($m['tipo'] ?? 'agendada') !== 'agendada') continue;
        $quando = $dia0->modify('+' . (int)($m['dia'] ?? 0) . ' day');
        if (($m['hora'] ?? null) === null || $m['hora'] === '') {
            $quando = (int)($m['dia'] ?? 0) === 0 ? wa_proximo_horario_valido($base, $ini, $fim) : wa_proximo_horario_valido($quando->setTime($ini, 0, 0), $ini, $fim);
        } else {
            $quando = wa_proximo_horario_valido($quando->setTime((int)$m['hora'], 0, 0), $ini, $fim);
        }
        $out[] = [atd_passo((string)$funil['id'], (string)$m['id']), $quando, (string)($m['condicao'] ?? 'todos')];
    }
    return $out;
}

/** Troca {{1}}, {{2}}... pelos valores. Variável sem valor fica como está (a pessoa vê o que falta). */
function atd_renderizar(string $texto, array $vars): string {
    return preg_replace_callback('/\{\{(\d)\}\}/', function ($m) use ($vars) {
        $k = $m[1];
        return (isset($vars[$k]) && $vars[$k] !== '') ? (string)$vars[$k] : $m[0];
    }, $texto);
}

// ─────────────────────────────────────────────────────────────────────────────
// Conceitos (base de conhecimento editável)
// ─────────────────────────────────────────────────────────────────────────────

function atd_conceitos(PDO $pdo): array {
    $salvo = atd_cfg_ler($pdo, 'atd_conceitos', null);
    return is_array($salvo) ? $salvo : atd_conceitos_padrao();
}

function atd_conceito_salvar(PDO $pdo, array $c): array {
    $id = atd_slug((string)($c['id'] ?? ''), 24) ?: atd_slug((string)($c['titulo'] ?? ''), 24);
    if ($id === '') throw new InvalidArgumentException('Dê um título ao conceito.');
    $novo = [
        'id' => $id,
        'titulo' => atd_texto_limpo($c['titulo'] ?? '', 100) ?: $id,
        'resumo' => atd_texto_limpo($c['resumo'] ?? '', 2500),
        'como_aplicar' => atd_texto_limpo($c['como_aplicar'] ?? '', 2500),
        'exemplo' => atd_texto_limpo($c['exemplo'] ?? '', 1200),
        'armadilha' => atd_texto_limpo($c['armadilha'] ?? '', 1200),
    ];
    $lista = atd_conceitos($pdo);
    $achou = false;
    foreach ($lista as $i => $x) { if (($x['id'] ?? '') === $id) { $lista[$i] = $novo; $achou = true; break; } }
    if (!$achou) $lista[] = $novo;
    atd_cfg_gravar($pdo, 'atd_conceitos', array_values($lista));
    return $novo;
}

function atd_conceito_excluir(PDO $pdo, string $id): bool {
    $lista = atd_conceitos($pdo);
    $fica = array_values(array_filter($lista, fn($c) => ($c['id'] ?? '') !== $id));
    if (count($fica) === count($lista)) return false;
    atd_cfg_gravar($pdo, 'atd_conceitos', $fica);
    return true;
}

function atd_conceito_restaurar(PDO $pdo, string $id): ?array {
    foreach (atd_conceitos_padrao() as $p) { if ($p['id'] === $id) return atd_conceito_salvar($pdo, $p); }
    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// Analisador de copy (sem IA, sem custo)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Confere um texto contra as regras da Intus e da Meta.
 * $o: template bool (aplica regras de template), primeiro bool (primeira mensagem do contato),
 *     ultima bool (último contato do funil, deve oferecer SAIR), teste bool (mensagem fala do teste grátis).
 * Devolve ['pontos' => 0..100, 'itens' => [['nivel' => erro|aviso|info, 'regra' => ..., 'msg' => ...]], 'caracteres' => n].
 */
function atd_analisar_copy(string $texto, array $o = []): array {
    $o += ['template' => false, 'primeiro' => false, 'ultima' => false];
    $t = trim($texto);
    $itens = [];
    $add = function (string $nivel, string $regra, string $msg) use (&$itens) { $itens[] = ['nivel' => $nivel, 'regra' => $regra, 'msg' => $msg]; };
    $n = mb_strlen($t);

    if ($t === '') {
        return ['pontos' => 0, 'caracteres' => 0, 'itens' => [['nivel' => 'erro', 'regra' => 'vazio', 'msg' => 'Texto vazio.']]];
    }

    if (preg_match('/[\x{2014}\x{2013}]/u', $t)) {
        $add('erro', 'travessao', 'Tem travessão. Troque por vírgula, ponto ou dois pontos.');
    }
    if (preg_match('/\s-\s/u', $t)) {
        $add('aviso', 'hifen', 'Hífen usado como travessão. Prefira vírgula ou ponto.');
    }
    if (preg_match('/\bn[ãa]o\s+(é|era|foi|são|se trata d[eo]s?)\b[^.!?\n]{0,90}?[,;:]?\s*(mas\s+|e sim\s+|sim,?\s+)?\b(é|são|foi|era)\b/iu', $t)
        || preg_match('/\bn[ãa]o\s+é\s+sobre\b/iu', $t)
        || preg_match('/\bn[ãa]o\s+(é|são)\s+(só|apenas|somente)\b/iu', $t)) {
        $add('erro', 'nao_e_x_e_y', 'Padrão "não é X, é Y" (soa como texto de IA). Diga direto o que é.');
    }
    if (preg_match('/\b(garant(e|imos|ido|ida|ia)|100\s?%|sem esfor[çc]o|resultados?\s+(garantidos?|certos?|r[aá]pidos?)|em\s+\d+\s+(dias|semanas|meses)|perca\s+\d+|emagre[çc](a|er|imento)|queime\s+\d*|\d+\s?kg)\b/iu', $t)) {
        $add('erro', 'promessa', 'Parece promessa de resultado, prazo ou número. A Intus não promete resultado.');
    }
    if (preg_match('/\b([úu]ltimas?\s+(vagas?|horas?|unidades?)|s[óo]\s+hoje|corra|oferta\s+rel[âa]mpago|n[ãa]o\s+perca|[úu]ltima\s+chance)\b/iu', $t)) {
        $add('aviso', 'urgencia', 'Urgência que pode ser falsa. Só use prazo que de fato existe (ex.: o teste acaba no terceiro dia).');
    }
    if (!empty($o['teste']) || preg_match('/\bteste\b/iu', $t)) {
        if (preg_match('/\b(dieta|card[áa]pio|plano alimentar|nutricion)/iu', $t)) {
            $add('aviso', 'dieta_no_teste', 'Fala de dieta perto do teste. O teste grátis é só de treino.');
        }
    }
    if (preg_match('/R\$\s*\d|\b\d+\s*x\s+de\s+R|\bpor\s+\d+\s*reais\b/iu', $t)) {
        $add('aviso', 'preco', 'Tem preço. O robô não fala valor da Premium, só uma pessoa da equipe.');
    }
    if (preg_match('/\b(cura|curar|tratamento|diagn[óo]stic|rem[ée]dio|medicament|horm[ôo]nio|esteroid)/iu', $t)) {
        $add('aviso', 'saude', 'Assunto de saúde. O robô não orienta sobre dor, lesão, doença ou remédio: passe para uma pessoa.');
    }
    if (preg_match('/espero que (voc[êe] )?esteja bem|nos dias de hoje|no mundo atual|jornada|transforma(r|ção) (a )?sua vida|potencializ|alavanc|mergulh(ar|e) (de cabe[çc]a|fundo)|desbloque(ie|ar) seu potencial|sem mais delongas/iu', $t)) {
        $add('aviso', 'cliche', 'Clichê que lembra texto de IA. Reescreva com as palavras que a pessoa usaria.');
    }

    $perguntas = preg_match_all('/\?/u', $t);
    if ($perguntas > 1) $add('aviso', 'perguntas', "Tem $perguntas perguntas. Uma só aumenta a chance de resposta.");
    if (preg_match_all('/!/u', $t) > 2) $add('aviso', 'exclamacoes', 'Muitas exclamações. O tom fica de anúncio.');
    $emojis = preg_match_all('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $t);
    if ($emojis > 1) $add('aviso', 'emojis', "Tem $emojis emojis. No máximo um.");
    $caixa = 0;
    if (preg_match_all('/\b[A-ZÁÉÍÓÚÂÊÔÃÕÇ]{4,}\b/u', $t, $mm)) {
        foreach ($mm[0] as $w) { if ($w !== 'SAIR') $caixa++; }
    }
    if ($caixa > 0) $add('aviso', 'caixa_alta', 'Palavra em caixa alta. Parece grito e aumenta denúncia de spam.');

    if ($n > 900) $add('aviso', 'tamanho', "Texto longo ($n caracteres). No WhatsApp, quanto menor, mais se lê.");
    elseif ($n > 450) $add('info', 'tamanho', "Texto de $n caracteres. Veja se dá para cortar.");

    if (!empty($o['primeiro']) && !preg_match('/assistente virtual/iu', $t)) {
        $add('aviso', 'identificacao', 'Primeira mensagem sem se apresentar como assistente virtual.');
    }
    if (!empty($o['ultima']) && !preg_match('/\bsair\b/iu', $t)) {
        $add('aviso', 'saida', 'Última mensagem do funil sem oferecer o SAIR.');
    }
    if (!$perguntas && !preg_match('/é só|me chama|me conta|responde|manda/iu', $t)) {
        $add('info', 'sem_cta', 'Sem pergunta nem chamada clara para agir.');
    }

    if (!empty($o['template'])) {
        if ($n > ATD_MAX_TEXTO_MENSAGEM) $add('erro', 'template_tamanho', 'Passa de ' . ATD_MAX_TEXTO_MENSAGEM . ' caracteres, limite do corpo de template da Meta.');
        preg_match_all('/\{\{(\d+)\}\}/', $t, $vm);
        $nums = array_values(array_unique(array_map('intval', $vm[1])));
        sort($nums);
        foreach ($nums as $i => $num) {
            if ($num !== $i + 1) { $add('erro', 'template_variaveis', 'As variáveis precisam ser numeradas em sequência: {{1}}, {{2}}, {{3}}.'); break; }
        }
        if (preg_match('/^\s*\{\{\d+\}\}/u', $t)) $add('aviso', 'template_inicio', 'Começa com variável. A Meta costuma recusar template assim: abra com uma palavra ("Oi, {{1}}").');
        if (preg_match('/\{\{\d+\}\}[\s.!?,:;]*$/u', $t)) $add('aviso', 'template_fim', 'Termina com variável. A Meta costuma recusar template assim: feche com texto.');
        if (preg_match('/\n{3,}|[ \t]{5,}/u', $t)) $add('aviso', 'template_espacos', 'Linhas em branco demais ou muitos espaços seguidos. A Meta recusa.');
    }

    $erros = 0; $avisos = 0;
    foreach ($itens as $i) { if ($i['nivel'] === 'erro') $erros++; elseif ($i['nivel'] === 'aviso') $avisos++; }
    $pontos = max(0, 100 - 25 * $erros - 8 * $avisos);
    if (!$itens) $itens[] = ['nivel' => 'ok', 'regra' => 'tudo_certo', 'msg' => 'Nenhum problema encontrado pelas regras automáticas.'];
    return ['pontos' => $pontos, 'caracteres' => $n, 'itens' => $itens];
}

/** Analisa todas as mensagens de um funil, com as opções certas para cada posição. */
function atd_analisar_funil(array $funil): array {
    $out = [];
    $msgs = $funil['mensagens'] ?? [];
    $ult = count($msgs) - 1;
    foreach ($msgs as $i => $m) {
        $r = atd_analisar_copy((string)($m['texto'] ?? ''), [
            'template' => !empty($m['template']),
            'primeiro' => $i === 0 && ($m['tipo'] ?? '') !== 'silencio',
            'ultima' => $i === $ult && ($funil['id'] ?? '') !== 'chamou-whatsapp',
        ]);
        $out[$m['id']] = $r;
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Referências (links e arquivos que deixam a ferramenta mais inteligente)
// ─────────────────────────────────────────────────────────────────────────────

const ATD_TAGS_REF = ['copy', 'vendas', 'psicologia', 'objecoes', 'produto', 'concorrencia', 'conversa', 'geral'];

function atd_refs(PDO $pdo): array {
    $r = atd_cfg_ler($pdo, 'atd_refs', null);
    return is_array($r) ? $r : [];
}

function atd_refs_resumo(array $refs): array {
    return array_map(function ($r) {
        $r['tem_texto'] = !empty($r['texto']);
        $r['chars'] = isset($r['texto']) ? mb_strlen((string)$r['texto']) : 0;
        unset($r['texto']);
        return $r;
    }, $refs);
}

function atd_html_para_texto(string $html): string {
    $html = preg_replace('#<(script|style|noscript|svg|head)\b[^>]*>.*?</\1>#is', ' ', $html);
    $html = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/\n\s*\n+/', "\n\n", $t);
    return trim($t);
}

function atd_ip_privado(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Confere se uma URL pode ser buscada pelo servidor (evita usar a ferramenta para alcançar a rede interna).
 * $resolver devolve a lista de IPs do host (padrão: DNS do sistema). Devolve a URL limpa ou null.
 */
function atd_url_segura(string $url, ?callable $resolver = null): ?string {
    $url = trim($url);
    if (strlen($url) > 500) return null;
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !isset($p['scheme'])) return null;
    if (!in_array(strtolower($p['scheme']), ['http', 'https'], true)) return null;
    if (isset($p['user']) || isset($p['pass'])) return null;
    $porta = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
    if (!in_array((int)$porta, [80, 443], true)) return null;
    $host = trim(strtolower($p['host']), '[]');
    if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') return null;
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = $resolver ? $resolver($host) : (function ($h) {
            $r = @gethostbynamel($h);
            return $r ?: [];
        })($host);
    }
    if (!$ips) return null;
    foreach ($ips as $ip) { if (atd_ip_privado((string)$ip)) return null; }
    return $url;
}

/**
 * Baixa uma página e extrai o texto. Segue até 3 redirecionamentos, revalidando cada destino.
 * $http: ?callable(string $url): array{status:int, tipo:string, corpo:string, local:?string} para testes.
 * Devolve ['ok' => bool, 'texto' => string, 'erro' => string].
 */
function atd_buscar_url(string $url, ?callable $http = null, ?callable $resolver = null): array {
    $atual = $url;
    for ($salto = 0; $salto < 4; $salto++) {
        $ok = atd_url_segura($atual, $resolver);
        if (!$ok) return ['ok' => false, 'texto' => '', 'erro' => 'Endereço não permitido ou inválido.'];
        $r = $http ? $http($ok) : atd_http_pagina($ok);
        if (!empty($r['erro'])) return ['ok' => false, 'texto' => '', 'erro' => (string)$r['erro']];
        $st = (int)($r['status'] ?? 0);
        if (in_array($st, [301, 302, 303, 307, 308], true) && !empty($r['local'])) {
            $atual = $r['local'];
            continue;
        }
        if ($st < 200 || $st >= 300) return ['ok' => false, 'texto' => '', 'erro' => "A página respondeu com erro $st."];
        $tipo = strtolower((string)($r['tipo'] ?? ''));
        if ($tipo !== '' && !preg_match('#text/|json|xml#', $tipo)) return ['ok' => false, 'texto' => '', 'erro' => 'Este link não é uma página de texto. Anexe o arquivo.'];
        $corpo = (string)($r['corpo'] ?? '');
        $texto = (stripos($tipo, 'html') !== false || preg_match('/<html|<body|<p[ >]/i', $corpo)) ? atd_html_para_texto($corpo) : trim($corpo);
        if (mb_strlen($texto) < 80) return ['ok' => false, 'texto' => '', 'erro' => 'Quase nenhum texto lido (pode ser vídeo ou página que exige JavaScript). Resuma na nota.'];
        return ['ok' => true, 'texto' => mb_substr($texto, 0, ATD_MAX_TEXTO_REF), 'erro' => ''];
    }
    return ['ok' => false, 'texto' => '', 'erro' => 'Redirecionamentos demais.'];
}

function atd_http_pagina(string $url): array {
    $ch = curl_init($url);
    $corpo = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; IntusFitBot/1.0)', CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$corpo) {
            $corpo .= $d;
            return strlen($corpo) > 1500000 ? 0 : strlen($d);
        },
        CURLOPT_HTTPHEADER => ['Accept: text/html,text/plain,application/json;q=0.9'],
    ]);
    $local = null;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($c, $h) use (&$local) {
        if (stripos($h, 'location:') === 0) $local = trim(substr($h, 9));
        return strlen($h);
    });
    curl_exec($ch);
    $errno = curl_errno($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $tipo = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($errno && $st === 0) return ['erro' => 'Não consegui abrir o endereço.'];
    if ($local && !preg_match('#^https?://#i', $local)) {
        $p = parse_url($url);
        $local = $p['scheme'] . '://' . $p['host'] . '/' . ltrim($local, '/');
    }
    return ['status' => $st, 'tipo' => $tipo, 'corpo' => $corpo, 'local' => $local];
}

function atd_ref_link_adicionar(PDO $pdo, array $d, ?callable $http = null, ?callable $resolver = null): array {
    $url = atd_url_segura((string)($d['url'] ?? ''), $resolver);
    if (!$url) throw new InvalidArgumentException('Endereço inválido. Use um link http ou https de um site público.');
    $refs = atd_refs($pdo);
    $links = array_filter($refs, fn($r) => ($r['tipo'] ?? '') === 'link');
    if (count($links) >= ATD_MAX_LINKS) throw new InvalidArgumentException('Limite de ' . ATD_MAX_LINKS . ' links atingido.');
    $tag = in_array($d['tag'] ?? '', ATD_TAGS_REF, true) ? $d['tag'] : 'geral';
    $ref = [
        'id' => atd_novo_id(), 'tipo' => 'link', 'titulo' => atd_texto_limpo($d['titulo'] ?? '', 120) ?: $url,
        'url' => $url, 'nota' => atd_texto_limpo($d['nota'] ?? '', 800), 'tag' => $tag, 'usar' => true,
        'texto' => '', 'lido_em' => null, 'erro' => '', 'criado_em' => wa_fmt(wa_agora()),
    ];
    if (!empty($d['ler'])) {
        $r = atd_buscar_url($url, $http, $resolver);
        if ($r['ok']) { $ref['texto'] = $r['texto']; $ref['lido_em'] = wa_fmt(wa_agora()); }
        else $ref['erro'] = $r['erro'];
    }
    $refs[] = $ref;
    atd_cfg_gravar($pdo, 'atd_refs', $refs);
    return $ref;
}

const ATD_EXT_ARQUIVO = ['txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv', 'pdf' => 'application/pdf'];

function atd_ref_arquivo_adicionar(PDO $pdo, array $d): array {
    $nome = atd_texto_limpo($d['nome'] ?? '', 120);
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    if (!isset(ATD_EXT_ARQUIVO[$ext])) throw new InvalidArgumentException('Tipo não aceito. Envie .txt, .md, .csv ou .pdf.');
    $bin = base64_decode((string)($d['base64'] ?? ''), true);
    if ($bin === false || $bin === '') throw new InvalidArgumentException('Arquivo vazio ou corrompido.');
    if (strlen($bin) > ATD_MAX_ARQUIVO_BYTES) throw new InvalidArgumentException('Arquivo maior que 3 MB.');
    if ($ext === 'pdf' && strncmp($bin, '%PDF-', 5) !== 0) throw new InvalidArgumentException('O arquivo não é um PDF de verdade.');
    if ($ext !== 'pdf' && preg_match('/<\?php|<script/i', $bin)) throw new InvalidArgumentException('Arquivo recusado: tem código escondido.');
    $refs = atd_refs($pdo);
    if (count(array_filter($refs, fn($r) => ($r['tipo'] ?? '') === 'arquivo')) >= ATD_MAX_ARQUIVOS) {
        throw new InvalidArgumentException('Limite de ' . ATD_MAX_ARQUIVOS . ' arquivos atingido.');
    }
    $id = atd_novo_id();
    $texto = '';
    if ($ext !== 'pdf') {
        $texto = mb_convert_encoding($bin, 'UTF-8', 'UTF-8,ISO-8859-1');
        $texto = mb_substr(trim($texto), 0, ATD_MAX_TEXTO_REF);
    } else {
        atd_cfg_gravar($pdo, 'atd_arq_' . $id, ['nome' => $nome, 'mime' => 'application/pdf', 'base64' => base64_encode($bin)]);
    }
    $ref = [
        'id' => $id, 'tipo' => 'arquivo', 'titulo' => atd_texto_limpo($d['titulo'] ?? '', 120) ?: $nome,
        'nome' => $nome, 'ext' => $ext, 'bytes' => strlen($bin), 'nota' => atd_texto_limpo($d['nota'] ?? '', 800),
        'tag' => in_array($d['tag'] ?? '', ATD_TAGS_REF, true) ? $d['tag'] : 'geral', 'usar' => true,
        'texto' => $texto, 'lido_em' => $texto !== '' ? wa_fmt(wa_agora()) : null, 'erro' => '', 'criado_em' => wa_fmt(wa_agora()),
    ];
    $refs[] = $ref;
    atd_cfg_gravar($pdo, 'atd_refs', $refs);
    return $ref;
}

function atd_ref_atualizar(PDO $pdo, string $id, array $d): ?array {
    $refs = atd_refs($pdo);
    foreach ($refs as $i => $r) {
        if (($r['id'] ?? '') !== $id) continue;
        if (isset($d['titulo'])) $r['titulo'] = atd_texto_limpo($d['titulo'], 120) ?: $r['titulo'];
        if (isset($d['nota'])) $r['nota'] = atd_texto_limpo($d['nota'], 800);
        if (isset($d['tag']) && in_array($d['tag'], ATD_TAGS_REF, true)) $r['tag'] = $d['tag'];
        if (isset($d['usar'])) $r['usar'] = (bool)$d['usar'];
        $refs[$i] = $r;
        atd_cfg_gravar($pdo, 'atd_refs', $refs);
        return $r;
    }
    return null;
}

function atd_ref_ler_de_novo(PDO $pdo, string $id, ?callable $http = null, ?callable $resolver = null): ?array {
    $refs = atd_refs($pdo);
    foreach ($refs as $i => $r) {
        if (($r['id'] ?? '') !== $id || ($r['tipo'] ?? '') !== 'link') continue;
        $res = atd_buscar_url((string)$r['url'], $http, $resolver);
        if ($res['ok']) { $r['texto'] = $res['texto']; $r['lido_em'] = wa_fmt(wa_agora()); $r['erro'] = ''; }
        else $r['erro'] = $res['erro'];
        $refs[$i] = $r;
        atd_cfg_gravar($pdo, 'atd_refs', $refs);
        return $r;
    }
    return null;
}

function atd_ref_excluir(PDO $pdo, string $id): bool {
    $refs = atd_refs($pdo);
    $fica = array_values(array_filter($refs, fn($r) => ($r['id'] ?? '') !== $id));
    if (count($fica) === count($refs)) return false;
    atd_cfg_gravar($pdo, 'atd_refs', $fica);
    atd_cfg_apagar($pdo, 'atd_arq_' . preg_replace('/[^a-f0-9]/', '', $id));
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Claude (gerar, reescrever, criticar, responder objeção)
// ─────────────────────────────────────────────────────────────────────────────

const ATD_MODOS_CLAUDE = ['gerar', 'reescrever', 'criticar', 'objecao', 'funil'];

/** Texto do contexto fixo: regras, produtos e (se pedidos) conceitos e referências. */
function atd_prompt_sistema(PDO $pdo, array $p): string {
    $aj = wa_ajustes($pdo);
    $nome = $aj['nome_atendente'] ?? 'Cora';
    $s = "Você é redator sênior de WhatsApp e especialista em psicologia de vendas, escrevendo para a Intus Fit, uma consultoria online de treino e nutrição (não é academia, não tem unidade física). Escreve em português do Brasil.\n"
       . "O atendente que assina as mensagens se chama $nome e é sempre apresentado como assistente virtual da Intus.\n\n"
       . "REGRAS OBRIGATÓRIAS DE ESCRITA:\n"
       . "- Nunca use travessão (— ou –) nem hífen como travessão.\n"
       . "- Nunca use o padrão \"não é X, é Y\" nem \"não é sobre X, é sobre Y\".\n"
       . "- Não prometa resultado, prazo ou número (nada de quilos, dias para ver mudança, \"garantido\").\n"
       . "- Não crie urgência falsa. Só cite prazo que existe (o teste grátis dura 3 dias).\n"
       . "- Não invente depoimento, número de alunos, desconto, vaga limitada ou benefício.\n"
       . "- O teste grátis é só de treino. Não inclui dieta.\n"
       . "- Só fale preço com os PLANOS E VALORES OFICIAIS listados abaixo, que vêm de Gestão de Planos. Nunca invente valor, desconto, parcelamento ou bônus. Desconto, condição especial e parcelamento diferente passam para uma pessoa da equipe.\n"
       . "- Não opine sobre dor, lesão, doença, remédio ou suplemento. Passe para um professor.\n"
       . "- Frases curtas, uma ideia e uma pergunta por mensagem, no máximo um emoji, sem caixa alta, sem clichês de IA.\n"
       . "- Variáveis: {{1}} nome, {{2}} link do app, {{3}} nome de quem indicou, {{4}} planos e valores, {{5}} link de pagamento do plano, {{6}} nome do plano, {{7}} valor do plano, {{8}} prova social real. Em template da Meta, não comece nem termine com variável.\n"
       . "- Técnica de venda com ética: pedir licença, perguntar antes de afirmar, recomendar um plano com o motivo, tratar a objeção como informação e deixar sempre a saída digna (SAIR).\n\n";
    $ids = array_map('strval', (array)($p['conceitos'] ?? []));
    if ($ids) {
        $s .= "CONCEITOS DA INTUS PARA APLICAR NESTE PEDIDO:\n";
        foreach (atd_conceitos($pdo) as $c) {
            if (!in_array((string)$c['id'], $ids, true)) continue;
            $s .= "## " . $c['titulo'] . "\n" . $c['resumo'] . "\nComo aplicar: " . $c['como_aplicar'] . "\nArmadilha: " . $c['armadilha'] . "\n\n";
        }
    }
    try {
        $pc = atd_planos_contexto($pdo);
        $s .= "PLANOS E VALORES OFICIAIS (Gestão de Planos):\n" . $pc['bloco'] . "\n\n";
    } catch (Throwable $e) {}
    if (function_exists('provas_para_ia')) {
        try {
            $ps = array_values(array_filter(provas_para_ia($pdo, 12), fn($x) => !empty($x['pode_divulgar'])));
            if ($ps) {
                $s .= "PROVAS SOCIAIS REAIS E AUTORIZADAS (só estas podem ser citadas; nunca invente outra e nunca cite sobrenome):\n";
                foreach (array_slice($ps, 0, 8) as $x) $s .= "- " . $x['texto_pronto'] . "\n";
                $s .= "\n";
            }
        } catch (Throwable $e) {}
    }
    $s .= "FATOS DOS PRODUTOS (não invente nada além disto):\n";
    foreach (atd_conceitos($pdo) as $c) {
        if (($c['id'] ?? '') === 'produtos') $s .= $c['resumo'] . "\n";
    }
    $s .= "\nResponda SOMENTE com um objeto JSON válido, sem texto antes nem depois, neste formato:\n"
        . "{\"variantes\":[{\"texto\":\"...\",\"angulo\":\"o ângulo psicológico usado, em poucas palavras\",\"por_que\":\"por que isso deve funcionar, em uma frase\"}],\"observacoes\":\"alertas ou perguntas para o Luiz, ou vazio\"}\n";
    return $s;
}

/** Referências marcadas para uso, respeitando os limites. Devolve [texto, documentosPdf]. */
function atd_contexto_refs(PDO $pdo, array $idsEscolhidos = []): array {
    $txt = ''; $docs = [];
    $usado = 0;
    foreach (atd_refs($pdo) as $r) {
        $quer = $idsEscolhidos ? in_array($r['id'], $idsEscolhidos, true) : !empty($r['usar']);
        if (!$quer) continue;
        $cab = "### Referência: " . $r['titulo'] . " [" . ($r['tag'] ?? 'geral') . "]" . (!empty($r['nota']) ? "\nO que aprender daqui: " . $r['nota'] : '') . "\n";
        if (!empty($r['texto'])) {
            $resta = ATD_MAX_CONTEXTO_REFS - $usado;
            if ($resta <= 500) continue;
            $corte = mb_substr((string)$r['texto'], 0, $resta);
            $txt .= $cab . $corte . "\n\n";
            $usado += mb_strlen($corte);
        } elseif (($r['ext'] ?? '') === 'pdf' && count($docs) < 2) {
            $a = atd_cfg_ler($pdo, 'atd_arq_' . $r['id'], null);
            if (is_array($a) && !empty($a['base64'])) {
                $docs[] = ['titulo' => $r['titulo'], 'base64' => $a['base64'], 'nota' => $r['nota'] ?? ''];
            }
        } elseif (!empty($r['nota'])) {
            $txt .= $cab . "(sem texto extraído)\n\n";
        }
    }
    return [$txt, $docs];
}

function atd_prompt_usuario(array $p): string {
    $modo = $p['modo'] ?? 'gerar';
    $n = max(1, min(5, (int)($p['quantidade'] ?? 3)));
    $partes = [];
    if (!empty($p['etapa'])) $partes[] = 'Momento do funil: ' . atd_texto_limpo($p['etapa'], 200);
    if (!empty($p['publico'])) $partes[] = 'Quem é a pessoa: ' . atd_texto_limpo($p['publico'], 400);
    if (!empty($p['contexto'])) $partes[] = 'Contexto: ' . atd_texto_limpo($p['contexto'], 800);
    if (!empty($p['tom'])) $partes[] = 'Tom: ' . atd_texto_limpo($p['tom'], 100);
    $max = (int)($p['max_chars'] ?? 0);
    if ($max > 0) $partes[] = "Limite: no máximo $max caracteres por texto.";
    if (!empty($p['template'])) $partes[] = 'O texto será um template da Meta: use {{1}} para o nome e respeite as regras de template.';
    $cab = $partes ? implode("\n", $partes) . "\n\n" : '';
    $base = atd_texto_limpo($p['texto'] ?? '', 3000);
    switch ($modo) {
        case 'reescrever':
            return $cab . "Reescreva a mensagem abaixo em $n variantes que mantenham a intenção e melhorem clareza, naturalidade e chance de resposta. Instrução extra: " . atd_texto_limpo($p['instrucao'] ?? 'nenhuma', 500) . "\n\nMENSAGEM:\n" . $base;
        case 'criticar':
            return $cab . "Critique a mensagem abaixo em 'observacoes' (pontos fortes, pontos fracos, riscos de regra, o que muda a resposta do lead) e devolva em 'variantes' uma versão melhorada (apenas 1).\n\nMENSAGEM:\n" . $base;
        case 'objecao':
            return $cab . "O lead disse: \"" . atd_texto_limpo($p['objecao'] ?? '', 500) . "\". Escreva $n respostas curtas para WhatsApp que acolham, descubram a objeção real com uma pergunta e devolvam a escolha, sem pressionar e sem falar preço. Em 'observacoes' diga quando passar para uma pessoa.";
        case 'funil':
            return $cab . "Avalie esta sequência de mensagens como uma história única (arco narrativo, dor, desejo, objeções que ficam sem resposta, ritmo entre as mensagens, repetição, tom). Em 'observacoes' dê o diagnóstico em tópicos curtos. Em 'variantes' sugira até $n mensagens novas ou reescritas que mais melhorariam a sequência, indicando em 'angulo' qual mensagem substituem.\n\nSEQUÊNCIA:\n" . $base;
        default:
            return $cab . "Objetivo da mensagem: " . atd_texto_limpo($p['objetivo'] ?? 'não informado', 500) . "\nConceito a explorar: " . atd_texto_limpo($p['foco'] ?? 'o que fizer mais sentido', 200) . "\n\nEscreva $n variantes com ângulos psicológicos DIFERENTES entre si.";
    }
}

function atd_http_claude(string $corpo, string $chave, bool $comFallback): array {
    $h = ['content-type: application/json', 'x-api-key: ' . $chave, 'anthropic-version: 2023-06-01'];
    if ($comFallback) $h[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $corpo, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 100, CURLOPT_CONNECTTIMEOUT => 8]);
    $r = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($r === false) return ['status' => 0, 'json' => null, 'erro' => $err ?: 'sem resposta'];
    return ['status' => $st, 'json' => json_decode((string)$r, true), 'erro' => ''];
}

function atd_uso_hoje(PDO $pdo): array {
    $u = atd_cfg_ler($pdo, 'atd_uso', []);
    $hoje = wa_agora()->format('Y-m-d');
    $d = $u[$hoje] ?? ['chamadas' => 0, 'tin' => 0, 'tout' => 0];
    return ['dia' => $hoje] + $d;
}

function atd_uso_somar(PDO $pdo, int $tin, int $tout): void {
    $u = atd_cfg_ler($pdo, 'atd_uso', []);
    $hoje = wa_agora()->format('Y-m-d');
    $d = $u[$hoje] ?? ['chamadas' => 0, 'tin' => 0, 'tout' => 0];
    $d['chamadas']++; $d['tin'] += $tin; $d['tout'] += $tout;
    $u[$hoje] = $d;
    krsort($u);
    atd_cfg_gravar($pdo, 'atd_uso', array_slice($u, 0, 60, true));
}

function atd_custo_usd(int $tin, int $tout): float {
    return round($tin / 1e6 * ATD_USD_ENTRADA_MTOK + $tout / 1e6 * ATD_USD_SAIDA_MTOK, 4);
}

/** Extrai o JSON da resposta mesmo se vier cercado de texto ou de ```. */
function atd_extrair_json(string $t): ?array {
    $t = trim($t);
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $t);
    $a = json_decode($t, true);
    if (is_array($a)) return $a;
    $i = strpos($t, '{'); $f = strrpos($t, '}');
    if ($i !== false && $f !== false && $f > $i) {
        $a = json_decode(substr($t, $i, $f - $i + 1), true);
        if (is_array($a)) return $a;
    }
    return null;
}

/**
 * Pede ao Claude. $p: modo, quantidade, objetivo, foco, texto, objecao, instrucao, etapa, publico, contexto, tom,
 * max_chars, template, conceitos[ids], refs[ids] (vazio = as marcadas "usar").
 * $http: ?callable(string $corpoJson, string $chave, bool $comFallback): array{status,json,erro} para testes.
 * Nunca lança por falha da API: devolve ['ok' => false, 'erro' => ...].
 */
function atd_gerar(PDO $pdo, array $p, ?callable $http = null): array {
    $modo = in_array($p['modo'] ?? '', ATD_MODOS_CLAUDE, true) ? $p['modo'] : 'gerar';
    $p['modo'] = $modo;
    $cfg = wa_segredo($pdo, 'anthropic_config');
    $chave = is_array($cfg) ? trim((string)($cfg['api_key'] ?? '')) : '';
    if ($chave === '') return ['ok' => false, 'erro' => 'A chave do Claude ainda não foi cadastrada em admin/whatsapp-keys.php.'];
    if (in_array($modo, ['reescrever', 'criticar', 'funil'], true) && trim((string)($p['texto'] ?? '')) === '') {
        return ['ok' => false, 'erro' => 'Cole o texto que deve ser trabalhado.'];
    }
    if ($modo === 'objecao' && trim((string)($p['objecao'] ?? '')) === '') return ['ok' => false, 'erro' => 'Escreva a objeção do lead.'];
    if ($modo === 'gerar' && trim((string)($p['objetivo'] ?? '')) === '') return ['ok' => false, 'erro' => 'Diga o objetivo da mensagem.'];
    $uso = atd_uso_hoje($pdo);
    if ($uso['chamadas'] >= ATD_MAX_CHAMADAS_DIA) return ['ok' => false, 'erro' => 'Limite diário de ' . ATD_MAX_CHAMADAS_DIA . ' pedidos ao Claude atingido. Volta amanhã.'];

    [$refTxt, $docs] = atd_contexto_refs($pdo, array_map('strval', (array)($p['refs'] ?? [])));
    $sistema = atd_prompt_sistema($pdo, $p);
    $conteudo = [];
    foreach ($docs as $d) {
        $conteudo[] = ['type' => 'document', 'title' => mb_substr($d['titulo'], 0, 120), 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $d['base64']]];
    }
    $usuario = ($refTxt !== '' ? "MATERIAL DE REFERÊNCIA (use para aprender o estilo e os argumentos; não copie trechos):\n" . $refTxt . "\n" : '') . atd_prompt_usuario($p);
    $conteudo[] = ['type' => 'text', 'text' => $usuario];

    $modelo = wa_ajustes($pdo)['modelo'] ?? 'claude-opus-5-5';
    $corpo = json_encode([
        'model' => $modelo,
        'max_tokens' => 6000,
        'system' => $sistema,
        'output_config' => ['effort' => 'medium'],
        'fallbacks' => 'default',
        'messages' => [['role' => 'user', 'content' => $conteudo]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $chamar = $http ?: 'atd_http_claude';
    try {
        $r = $chamar($corpo, $chave, true);
        if (($r['status'] ?? 0) === 400 && preg_match('/fallback|beta/i', json_encode($r['json'] ?? '') ?: '')) {
            $sem = json_decode($corpo, true); unset($sem['fallbacks']);
            $r = $chamar(json_encode($sem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $chave, false);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'erro' => 'Falha ao falar com o Claude (' . wa_log_erro($e) . ').'];
    }
    $st = (int)($r['status'] ?? 0);
    $j = $r['json'] ?? null;
    if ($st !== 200 || !is_array($j)) {
        $detalhe = is_array($j) ? (string)($j['error']['message'] ?? '') : (string)($r['erro'] ?? '');
        $msg = $st === 401 ? 'A chave do Claude foi recusada. Confira em admin/whatsapp-keys.php.'
             : ($st === 429 ? 'Limite de uso do Claude atingido agora. Tente de novo em instantes.'
             : 'O Claude não respondeu como esperado' . ($st ? " (HTTP $st)" : '') . ($detalhe ? ': ' . mb_substr($detalhe, 0, 160) : '.'));
        return ['ok' => false, 'erro' => $msg];
    }
    if (($j['stop_reason'] ?? '') === 'refusal') return ['ok' => false, 'erro' => 'O Claude recusou este pedido. Reformule.'];
    $texto = '';
    foreach (($j['content'] ?? []) as $b) { if (($b['type'] ?? '') === 'text') $texto .= $b['text']; }
    $tin = (int)($j['usage']['input_tokens'] ?? 0) + (int)($j['usage']['cache_read_input_tokens'] ?? 0) + (int)($j['usage']['cache_creation_input_tokens'] ?? 0);
    $tout = (int)($j['usage']['output_tokens'] ?? 0);
    atd_uso_somar($pdo, $tin, $tout);
    $dados = atd_extrair_json($texto);
    $variantes = [];
    if ($dados && !empty($dados['variantes']) && is_array($dados['variantes'])) {
        foreach (array_slice($dados['variantes'], 0, 6) as $v) {
            if (!is_array($v) || empty($v['texto'])) continue;
            $t = (string)$v['texto'];
            $variantes[] = [
                'texto' => $t, 'angulo' => atd_texto_limpo($v['angulo'] ?? '', 160), 'por_que' => atd_texto_limpo($v['por_que'] ?? '', 300),
                'analise' => atd_analisar_copy($t, ['template' => !empty($p['template'])]),
            ];
        }
    }
    return [
        'ok' => true, 'variantes' => $variantes,
        'observacoes' => $dados ? atd_texto_limpo(is_string($dados['observacoes'] ?? null) ? $dados['observacoes'] : json_encode($dados['observacoes'] ?? '', JSON_UNESCAPED_UNICODE), 3000) : '',
        'bruto' => $variantes ? '' : mb_substr($texto, 0, 4000),
        'tokens_in' => $tin, 'tokens_out' => $tout, 'custo_usd' => atd_custo_usd($tin, $tout),
        'modelo' => $modelo, 'usou_pdfs' => count($docs), 'usou_refs_texto' => $refTxt !== '',
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Testes A/B
// ─────────────────────────────────────────────────────────────────────────────

function atd_testes(PDO $pdo): array {
    $t = atd_cfg_ler($pdo, 'atd_testes', []);
    return is_array($t) ? $t : [];
}

function atd_teste_salvar(PDO $pdo, array $d): array {
    $funis = array_column(atd_funis($pdo), 'id');
    $a = (string)($d['funil_a'] ?? ''); $b = (string)($d['funil_b'] ?? '');
    if (!in_array($a, $funis, true) || !in_array($b, $funis, true)) throw new InvalidArgumentException('Escolha dois funis existentes.');
    if ($a === $b) throw new InvalidArgumentException('Os dois lados do teste precisam ser funis diferentes.');
    $pct = max(5, min(95, (int)($d['pct_b'] ?? 50)));
    $status = in_array($d['status'] ?? '', ['rascunho', 'rodando', 'encerrado'], true) ? $d['status'] : 'rascunho';
    $lista = atd_testes($pdo);
    $id = atd_slug((string)($d['id'] ?? ''), 12) ?: atd_novo_id();
    $existente = null;
    foreach ($lista as $x) { if ($x['id'] === $id) $existente = $x; }
    $inicio = $existente['inicio'] ?? null;
    $fim = $existente['fim'] ?? null;
    if ($status === 'rodando' && !$inicio) $inicio = wa_fmt(wa_agora());
    if ($status === 'encerrado' && !$fim) $fim = wa_fmt(wa_agora());
    if ($status !== 'encerrado') $fim = null;
    if ($status === 'rodando') {
        foreach ($lista as $x) {
            if ($x['id'] !== $id && ($x['status'] ?? '') === 'rodando' && ($x['gatilho'] ?? '') === (string)($d['gatilho'] ?? 'teste-gratis')) {
                throw new InvalidArgumentException('Já existe um teste rodando para este gatilho. Encerre o outro antes.');
            }
        }
    }
    $novo = [
        'id' => $id, 'nome' => atd_texto_limpo($d['nome'] ?? '', 100) ?: ('Teste ' . $a . ' x ' . $b),
        'gatilho' => atd_texto_limpo($d['gatilho'] ?? 'teste-gratis', 40), 'funil_a' => $a, 'funil_b' => $b, 'pct_b' => $pct,
        'status' => $status, 'hipotese' => atd_texto_limpo($d['hipotese'] ?? '', 500), 'inicio' => $inicio, 'fim' => $fim,
    ];
    $achou = false;
    foreach ($lista as $i => $x) { if ($x['id'] === $id) { $lista[$i] = $novo; $achou = true; } }
    if (!$achou) $lista[] = $novo;
    atd_cfg_gravar($pdo, 'atd_testes', array_values($lista));
    return $novo;
}

function atd_teste_excluir(PDO $pdo, string $id): bool {
    $lista = atd_testes($pdo);
    $fica = array_values(array_filter($lista, fn($t) => ($t['id'] ?? '') !== $id));
    if (count($fica) === count($lista)) return false;
    atd_cfg_gravar($pdo, 'atd_testes', $fica);
    return true;
}

/** Lado do teste ('a' ou 'b') para um telefone. Determinístico: a mesma pessoa cai sempre do mesmo lado. */
function atd_variante(array $teste, string $telefone): string {
    $pct = max(0, min(100, (int)($teste['pct_b'] ?? 50)));
    $h = crc32(($teste['id'] ?? '') . '|' . $telefone) % 100;
    return $h < $pct ? 'b' : 'a';
}

/** Funil que um novo contato deve receber para um gatilho, considerando o teste A/B que estiver rodando. */
function atd_funil_para_contato(PDO $pdo, string $gatilho, string $telefone, string $padrao): array {
    foreach (atd_testes($pdo) as $t) {
        if (($t['status'] ?? '') === 'rodando' && ($t['gatilho'] ?? '') === $gatilho) {
            $lado = atd_variante($t, $telefone);
            return ['funil' => $lado === 'b' ? $t['funil_b'] : $t['funil_a'], 'teste' => $t['id'], 'lado' => $lado];
        }
    }
    return ['funil' => $padrao, 'teste' => null, 'lado' => null];
}

function atd_normal_cdf(float $z): float {
    // aproximação de Abramowitz e Stegun para a função de erro
    $t = 1.0 / (1.0 + 0.3275911 * abs($z) / M_SQRT2);
    $y = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-($z * $z) / 2);
    return $z >= 0 ? 0.5 * (1 + $y) : 0.5 * (1 - $y);
}

/** Compara duas taxas (sucessos/total) com teste z de duas proporções. */
function atd_comparar_taxas(int $sa, int $na, int $sb, int $nb, int $minimo = 30): array {
    $pa = $na ? $sa / $na : 0; $pb = $nb ? $sb / $nb : 0;
    $r = ['taxa_a' => round($pa * 100, 1), 'taxa_b' => round($pb * 100, 1), 'n_a' => $na, 'n_b' => $nb, 'p_valor' => null, 'veredito' => 'sem_dados', 'texto' => 'Ainda não há envios nos dois lados.'];
    if ($na === 0 || $nb === 0) return $r;
    if ($na < $minimo || $nb < $minimo) {
        $r['veredito'] = 'amostra_pequena';
        $r['texto'] = "Amostra pequena (mínimo $minimo por lado antes de tirar conclusão). Hoje: A $na, B $nb.";
        return $r;
    }
    $pp = ($sa + $sb) / ($na + $nb);
    $ep = sqrt($pp * (1 - $pp) * (1 / $na + 1 / $nb));
    if ($ep == 0) { $r['veredito'] = 'sem_diferenca'; $r['texto'] = 'As duas taxas são iguais.'; return $r; }
    $z = ($pb - $pa) / $ep;
    $p = 2 * (1 - atd_normal_cdf(abs($z)));
    $r['p_valor'] = round($p, 4);
    if ($p < 0.05) {
        $r['veredito'] = $pb > $pa ? 'b_vence' : 'a_vence';
        $r['texto'] = ($pb > $pa ? 'B' : 'A') . ' vence com cerca de ' . round((1 - $p) * 100) . '% de confiança.';
    } else {
        $r['veredito'] = 'sem_diferenca';
        $r['texto'] = 'Diferença ainda pode ser acaso (confiança de ' . round((1 - $p) * 100) . '%). Deixe rodar mais.';
    }
    return $r;
}

// ─────────────────────────────────────────────────────────────────────────────
// Métricas (dashboard)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Tudo o que o dashboard mostra, lido de intus_wa_*. $dias: janela do período. Resposta a uma mensagem
 * enviada = o contato escreveu em até 24 horas depois dela.
 */
function atd_metricas(PDO $pdo, int $dias, ?DateTimeImmutable $agora = null): array {
    wa_garantir_tabelas($pdo);
    $agora = $agora ?: wa_agora();
    $dias = max(1, min(365, $dias));
    $desde = $agora->modify('-' . $dias . ' day')->setTime(0, 0, 0);
    $desdeTxt = wa_fmt($desde);
    $limiteResp = 86400;

    // contatos
    $q = $pdo->prepare('SELECT idcontato, etapa, modo, optin_em, saiu_em, precisa_humano, criado_em FROM intus_wa_contato');
    $q->execute();
    $contatos = $q->fetchAll(PDO::FETCH_ASSOC);
    $porEtapa = []; $novos = 0; $comOptin = 0; $sairam = 0; $humanos = 0; $ativosHumano = 0;
    $contatoCriado = [];
    foreach ($contatos as $c) {
        $contatoCriado[(int)$c['idcontato']] = $c['criado_em'];
        $porEtapa[$c['etapa']] = ($porEtapa[$c['etapa']] ?? 0) + 1;
        $nonoPeriodo = $c['criado_em'] >= $desdeTxt;
        if ($nonoPeriodo) {
            $novos++;
            if (!empty($c['optin_em'])) $comOptin++;
            if (!empty($c['saiu_em'])) $sairam++;
        }
        if ((int)$c['precisa_humano'] === 1) $humanos++;
        if (($c['modo'] ?? '') === 'humano') $ativosHumano++;
    }

    // mensagens do período
    $q = $pdo->prepare('SELECT idmensagem, idcontato, direcao, origem, template, status, tokens_in, tokens_out, criado_em
                        FROM intus_wa_mensagem WHERE criado_em >= ? ORDER BY idmensagem');
    $q->execute([$desdeTxt]);
    $msgs = $q->fetchAll(PDO::FETCH_ASSOC);

    $porContato = [];
    foreach ($msgs as $m) $porContato[(int)$m['idcontato']][] = $m;

    $enviadas = 0; $recebidas = 0; $statusCont = ['enviada' => 0, 'entregue' => 0, 'lida' => 0, 'falhou' => 0];
    $serie = []; $horas = array_fill(0, 24, 0);
    $tin = 0; $tout = 0; $porTemplate = []; $respTempos = [];
    $templatesEnviados = 0; $templatesRespondidos = 0;

    foreach ($porContato as $idc => $lista) {
        $entradas = [];
        foreach ($lista as $m) if ($m['direcao'] === 'E') $entradas[] = strtotime($m['criado_em']);
        foreach ($lista as $m) {
            $dia = substr($m['criado_em'], 0, 10);
            if (!isset($serie[$dia])) $serie[$dia] = ['dia' => $dia, 'novos' => 0, 'enviadas' => 0, 'recebidas' => 0];
            if ($m['direcao'] === 'E') {
                $recebidas++; $serie[$dia]['recebidas']++;
                $horas[(int)substr($m['criado_em'], 11, 2)]++;
                continue;
            }
            $enviadas++; $serie[$dia]['enviadas']++;
            $s = $m['status'];
            if (isset($statusCont[$s])) {
                $statusCont[$s]++;
            }
            if ($m['origem'] === 'robo') { $tin += (int)$m['tokens_in']; $tout += (int)$m['tokens_out']; }
            if ($m['origem'] === 'sequencia' && !empty($m['template'])) {
                $tp = $m['template'];
                if (!isset($porTemplate[$tp])) $porTemplate[$tp] = ['template' => $tp, 'enviadas' => 0, 'entregues' => 0, 'lidas' => 0, 'falhas' => 0, 'respondidas' => 0];
                $porTemplate[$tp]['enviadas']++;
                if (in_array($s, ['entregue', 'lida'], true)) $porTemplate[$tp]['entregues']++;
                if ($s === 'lida') { $porTemplate[$tp]['lidas']++; $porTemplate[$tp]['entregues'] = $porTemplate[$tp]['entregues']; }
                if ($s === 'falhou') $porTemplate[$tp]['falhas']++;
                $t0 = strtotime($m['criado_em']);
                $primeira = null;
                foreach ($entradas as $te) { if ($te >= $t0 && $te - $t0 <= $limiteResp) { $primeira = $te; break; } }
                $templatesEnviados++;
                if ($primeira !== null) {
                    $porTemplate[$tp]['respondidas']++; $templatesRespondidos++;
                    $respTempos[] = ($primeira - $t0) / 60;
                }
            }
        }
    }
    foreach ($contatoCriado as $idc => $criado) {
        if ($criado >= $desdeTxt) {
            $dia = substr($criado, 0, 10);
            if (!isset($serie[$dia])) $serie[$dia] = ['dia' => $dia, 'novos' => 0, 'enviadas' => 0, 'recebidas' => 0];
            $serie[$dia]['novos']++;
        }
    }
    // lidas contam também como entregues e enviadas no funil de entrega
    $entregues = $statusCont['entregue'] + $statusCont['lida'];
    $lidas = $statusCont['lida'];
    // dias sem movimento aparecem zerados
    $linha = [];
    for ($i = $dias - 1; $i >= 0; $i--) {
        $d = $agora->modify("-$i day")->format('Y-m-d');
        $linha[] = $serie[$d] ?? ['dia' => $d, 'novos' => 0, 'enviadas' => 0, 'recebidas' => 0];
    }

    // agenda
    $q = $pdo->prepare('SELECT idcontato, passo, status, motivo FROM intus_wa_agenda');
    $q->execute();
    $agenda = $q->fetchAll(PDO::FETCH_ASSOC);
    $agStatus = []; $agMotivos = []; $funilDoContato = []; $porFunil = [];
    foreach ($agenda as $a) {
        [$fid, $mid] = atd_passo_decodificar((string)$a['passo']);
        $agStatus[$a['status']] = ($agStatus[$a['status']] ?? 0) + 1;
        if (!empty($a['motivo']) && $a['status'] !== 'enviada') $agMotivos[$a['motivo']] = ($agMotivos[$a['motivo']] ?? 0) + 1;
        if (!isset($funilDoContato[(int)$a['idcontato']])) $funilDoContato[(int)$a['idcontato']] = $fid;
        if (!isset($porFunil[$fid][$mid])) $porFunil[$fid][$mid] = ['pendente' => 0, 'enviada' => 0, 'pulada' => 0, 'cancelada' => 0, 'falhou' => 0];
        if (isset($porFunil[$fid][$mid][$a['status']])) $porFunil[$fid][$mid][$a['status']]++;
    }

    // métricas por funil (contatos criados no período que estão naquele funil)
    $mapContato = [];
    foreach ($contatos as $c) $mapContato[(int)$c['idcontato']] = $c;
    $funisMet = [];
    foreach (array_unique(array_values($funilDoContato)) as $fid) {
        $funisMet[$fid] = atd_metricas_funil($pdo, $fid, $funilDoContato, $mapContato, $desdeTxt);
    }

    $respostasPct = $templatesEnviados ? round($templatesRespondidos / $templatesEnviados * 100, 1) : null;
    sort($respTempos);
    $mediana = $respTempos ? round($respTempos[(int)floor((count($respTempos) - 1) / 2)], 1) : null;

    return [
        'periodo' => ['dias' => $dias, 'desde' => $desdeTxt, 'ate' => wa_fmt($agora)],
        'kpis' => [
            'contatos_total' => count($contatos), 'novos' => $novos, 'com_consentimento' => $comOptin, 'sairam' => $sairam,
            'taxa_saida' => $novos ? round($sairam / $novos * 100, 1) : null,
            'precisam_humano' => $humanos, 'em_modo_humano' => $ativosHumano,
            'enviadas' => $enviadas, 'recebidas' => $recebidas,
            'entregues' => $entregues, 'lidas' => $lidas, 'falhas' => $statusCont['falhou'],
            'taxa_entrega' => $enviadas ? round($entregues / $enviadas * 100, 1) : null,
            'taxa_leitura' => $entregues ? round($lidas / $entregues * 100, 1) : null,
            'templates_enviados' => $templatesEnviados, 'templates_respondidos' => $templatesRespondidos,
            'taxa_resposta' => $respostasPct, 'mediana_resposta_min' => $mediana,
            'convertidos' => $porEtapa['convertido'] ?? 0,
            'taxa_conversao' => $novos ? round(($porEtapa['convertido'] ?? 0) / max(1, count($contatos)) * 100, 1) : null,
        ],
        'etapas' => $porEtapa,
        'serie' => $linha,
        'horas_resposta' => $horas,
        'templates' => array_values($porTemplate),
        'agenda' => ['status' => $agStatus, 'motivos' => $agMotivos],
        'funis' => $funisMet,
        'passos' => $porFunil,
        'claude' => ['tokens_in' => $tin, 'tokens_out' => $tout, 'custo_usd' => atd_custo_usd($tin, $tout)],
    ];
}

function atd_metricas_funil(PDO $pdo, string $fid, array $funilDoContato, array $mapContato, string $desdeTxt): array {
    $ids = [];
    foreach ($funilDoContato as $idc => $f) {
        if ($f === $fid && isset($mapContato[$idc]) && $mapContato[$idc]['criado_em'] >= $desdeTxt) $ids[] = $idc;
    }
    $r = ['contatos' => count($ids), 'receberam' => 0, 'responderam' => 0, 'sairam' => 0, 'humano' => 0, 'convertidos' => 0, 'taxa_resposta' => null];
    if (!$ids) return $r;
    foreach ($ids as $idc) {
        $c = $mapContato[$idc];
        if (!empty($c['saiu_em'])) $r['sairam']++;
        if ((int)$c['precisa_humano'] === 1) $r['humano']++;
        if (($c['etapa'] ?? '') === 'convertido') $r['convertidos']++;
    }
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $q = $pdo->prepare("SELECT idcontato, direcao, origem, criado_em FROM intus_wa_mensagem WHERE idcontato IN ($marcas) ORDER BY idmensagem");
    $q->execute($ids);
    $primeiroEnvio = []; $respondeu = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $idc = (int)$m['idcontato'];
        if ($m['direcao'] === 'S' && $m['origem'] === 'sequencia' && !isset($primeiroEnvio[$idc])) $primeiroEnvio[$idc] = $m['criado_em'];
        if ($m['direcao'] === 'E' && isset($primeiroEnvio[$idc]) && $m['criado_em'] >= $primeiroEnvio[$idc]) $respondeu[$idc] = true;
    }
    $r['receberam'] = count($primeiroEnvio);
    $r['responderam'] = count($respondeu);
    $r['taxa_resposta'] = $r['receberam'] ? round($r['responderam'] / $r['receberam'] * 100, 1) : null;
    return $r;
}

/** Resultado de cada teste A/B com base nas métricas por funil, só contando contatos criados depois do início. */
function atd_resultados_testes(PDO $pdo, ?DateTimeImmutable $agora = null): array {
    wa_garantir_tabelas($pdo);
    $out = [];
    $q = $pdo->prepare('SELECT idcontato, etapa, saiu_em, precisa_humano, criado_em FROM intus_wa_contato');
    $q->execute();
    $mapContato = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $c) $mapContato[(int)$c['idcontato']] = $c;
    $q = $pdo->prepare('SELECT idcontato, passo FROM intus_wa_agenda ORDER BY idagenda');
    $q->execute();
    $funilDoContato = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $idc = (int)$a['idcontato'];
        if (!isset($funilDoContato[$idc])) $funilDoContato[$idc] = atd_passo_decodificar((string)$a['passo'])[0];
    }
    foreach (atd_testes($pdo) as $t) {
        if (empty($t['inicio'])) { $out[$t['id']] = ['sem_inicio' => true]; continue; }
        $a = atd_metricas_funil($pdo, $t['funil_a'], $funilDoContato, $mapContato, (string)$t['inicio']);
        $b = atd_metricas_funil($pdo, $t['funil_b'], $funilDoContato, $mapContato, (string)$t['inicio']);
        $out[$t['id']] = ['a' => $a, 'b' => $b, 'comparacao' => atd_comparar_taxas($a['responderam'], $a['receberam'], $b['responderam'], $b['receberam'])];
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
// Leads (Clientes + Tráfego e Captação + WhatsApp)
// ─────────────────────────────────────────────────────────────────────────────

const ATD_LINK_APP = 'https://intusfit.com.br/app/painel/aluno.html';
const ATD_MAX_LEADS = 1500;
/** Qual mensagem da Central serve para cada etapa: [funil, mensagem]. null = conversa de uma pessoa. */
const ATD_MENSAGEM_DA_ETAPA = [
    'contato'        => ['chamou-whatsapp', 'c4'],
    'so_conta'       => ['teste-gratis', 't1'],
    'abriu_app'      => ['teste-gratis', 't2'],
    'treinando'      => ['teste-gratis', 't3'],
    'teste_expirado' => ['teste-gratis', 't6'],
    'negociando'     => null,
    'cliente'        => null,
];

function atd_colunas(PDO $pdo, string $tbl): array {
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            return array_column($pdo->query("PRAGMA table_info(`$tbl`)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        }
        return array_column($pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    } catch (Throwable $e) { return []; }
}

function atd_escolher(array $cols, array $candidatos): ?string {
    foreach ($candidatos as $c) { if (in_array($c, $cols, true)) return $c; }
    return null;
}

function atd_tabela_atleta(PDO $pdo): ?string {
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return $t; } catch (Throwable $e) {}
    }
    return null;
}

/** Mesma regra de Clientes (alunos.html, isLead) aplicada a uma linha da tabela de atletas. */
function atd_conta_eh_lead(array $r, ?string $colOrigem, ?string $colEmail): bool {
    $o = mb_strtolower(trim((string)($colOrigem ? ($r[$colOrigem] ?? '') : '')), 'UTF-8');
    $email = mb_strtolower(trim((string)($colEmail ? ($r[$colEmail] ?? '') : '')), 'UTF-8');
    if (in_array($email, ['modelo.masculino@intusfit.local', 'modelo.feminino@intusfit.local'], true)) return false;
    if (in_array($o, ['app', 'captura', 'lead'], true) || strpos($o, 'landing') === 0) return true;
    if (in_array($o, ['professor', 'cliente'], true)) return false;
    $profs = json_decode((string)($r['professores_responsaveis'] ?? ''), true);
    return !(is_array($profs) && count($profs) > 0);
}

function atd_primeiro_nome(string $nome): string {
    $p = trim(explode(' ', trim($nome))[0] ?? '');
    return $p === '(sem' ? '' : $p;
}

/**
 * Planos e valores OFICIAIS, os mesmos de Gestão de Planos (intus_config.planos_config: v-<familia>-<periodo> e lk-<familia>-<periodo>).
 * Valor sem nada salvo usa o inicial que a própria Gestão de Planos e o cadastro mostram (ATD_PLANOS_PADRAO); `valor_padrao` avisa.
 * Link de pagamento só vale se for https://; vazio quando não foi cadastrado.
 */
const ATD_PLANOS_PADRAO = ['treino' => [897, 1678, 2987], 'dieta' => [997, 1689, 2997], 'combo' => [1497, 2597, 4597]];
const ATD_PLANOS_FAMILIAS = ['treino' => 'Treino', 'dieta' => 'Dieta', 'combo' => 'Treino + Dieta'];
const ATD_PLANOS_PERIODOS = ['tri' => ['Trimestral', 3], 'sem' => ['Semestral', 6], 'anual' => ['Anual', 12]];

function atd_dinheiro(float $v): string {
    $inteiro = abs($v - round($v)) < 0.005;
    return 'R$ ' . number_format($v, $inteiro ? 0 : 2, ',', '.');
}

function atd_planos(PDO $pdo): array {
    $cfg = atd_cfg_ler($pdo, 'planos_config', []);
    if (!is_array($cfg)) $cfg = [];
    $out = [];
    foreach (ATD_PLANOS_FAMILIAS as $fk => $fnome) {
        $i = 0;
        foreach (ATD_PLANOS_PERIODOS as $pk => [$pnome, $meses]) {
            $v = isset($cfg['v-' . $fk . '-' . $pk]) ? (float)str_replace(',', '.', (string)$cfg['v-' . $fk . '-' . $pk]) : 0.0;
            $padrao = $v <= 0;
            if ($padrao) $v = (float)ATD_PLANOS_PADRAO[$fk][$i];
            $link = trim((string)($cfg['lk-' . $fk . '-' . $pk] ?? ''));
            if (!preg_match('#^https://[^\s<>"\']+$#i', $link)) $link = '';
            $out[] = ['id' => $fk . '-' . $pk, 'familia' => $fk, 'familia_nome' => $fnome, 'periodo' => $pk, 'periodo_nome' => $pnome, 'meses' => $meses,
                'nome' => $fnome . ' ' . mb_strtolower($pnome), 'valor' => $v, 'valor_txt' => atd_dinheiro($v), 'mensal_txt' => atd_dinheiro($v / $meses), 'link' => $link, 'valor_padrao' => $padrao];
            $i++;
        }
    }
    return $out;
}

/** Texto de {{4}}: uma linha por família com os três períodos. */
function atd_planos_bloco(array $planos): string {
    $linhas = [];
    foreach (ATD_PLANOS_FAMILIAS as $fk => $fnome) {
        $p = array_values(array_filter($planos, fn($x) => $x['familia'] === $fk));
        if (!$p) continue;
        $linhas[] = '• ' . $fnome . ': ' . implode(', ', array_map(fn($x) => mb_strtolower($x['periodo_nome']) . ' ' . $x['valor_txt'], $p));
    }
    return implode("\n", $linhas);
}

function atd_planos_contexto(PDO $pdo): array {
    $l = atd_planos($pdo);
    return ['lista' => $l, 'bloco' => atd_planos_bloco($l), 'valores_padrao' => count(array_filter($l, fn($p) => $p['valor_padrao'])), 'sem_link' => count(array_filter($l, fn($p) => $p['link'] === ''))];
}

/**
 * Texto da mensagem com os dados da pessoa. $ctx traz o que as mensagens de fechamento usam:
 *   planos_bloco => {{4}} (planos e valores) · plano => {{5}} link, {{6}} nome e {{7}} valor do plano escolhido · prova => {{8}} (prova social)
 * Link de pagamento ainda não cadastrado e prova ainda não escolhida aparecem entre colchetes, para ninguém mandar sem ver.
 */
function atd_render_mensagem(array $msg, string $nome, string $indicador = '', array $ctx = []): string {
    $primeiro = atd_primeiro_nome($nome);
    $modelo = (string)($msg['texto'] ?? '');
    if ($primeiro === '') {
        // sem nome: "Oi {{1}}, tudo bem?" vira "Oi, tudo bem?" e "{{1}}, parabéns" vira "Parabéns"
        $modelo = preg_replace('/^\{\{1\}\},\s*(\p{L})/u', '$1', $modelo, 1, $abriuComNome);
        if ($abriuComNome) $modelo = mb_strtoupper(mb_substr($modelo, 0, 1)) . mb_substr($modelo, 1);
        $modelo = str_replace(' {{1}}', '', $modelo);
    }
    $vars = ['1' => $primeiro, '2' => ATD_LINK_APP, '3' => $indicador];
    if (!empty($ctx['planos_bloco'])) $vars['4'] = (string)$ctx['planos_bloco'];
    if (!empty($ctx['plano']) && is_array($ctx['plano'])) {
        $pl = $ctx['plano'];
        $vars['5'] = ($pl['link'] ?? '') !== '' ? (string)$pl['link'] : '[cadastre o link de pagamento deste plano em Gestão de Planos]';
        $vars['6'] = (string)$pl['nome'];
        $vars['7'] = (string)$pl['valor_txt'];
    }
    if (array_key_exists('prova', $ctx)) $vars['8'] = ($ctx['prova'] !== null && $ctx['prova'] !== '') ? (string)$ctx['prova'] : '[cadastre uma prova social em Quadro de Resultados, Provas sociais]';
    return atd_renderizar($modelo, $vars);
}

/** Texto pronto da Central para uma etapa, com o nome da pessoa. null se não houver mensagem para a etapa. */
function atd_sugestao(array $funis, string $etapa, string $nome, string $indicador = ''): ?array {
    $alvo = ATD_MENSAGEM_DA_ETAPA[$etapa] ?? null;
    if (!$alvo) return null;
    foreach ($funis as $f) {
        if (($f['id'] ?? '') !== $alvo[0]) continue;
        foreach ($f['mensagens'] as $m) {
            if ($m['id'] !== $alvo[1] || trim((string)$m['texto']) === '') continue;
            return ['funil' => $f['id'], 'funil_nome' => $f['nome'], 'mensagem' => $m['id'], 'nome' => $m['nome'], 'aprovada' => !empty($m['aprovada']), 'texto' => atd_render_mensagem($m, $nome, $indicador)];
        }
    }
    return null;
}

/**
 * Ids das contas que Clientes (alunos.html, isLead) chama de lead dentro do período.
 * A Central entrega esses ids a trLeads, para que lead de autocadastro (origem app ou captura) apareça junto com o que
 * Tráfego e Captação mede, sem repetir aqui a regra das etapas.
 */
function atd_ids_leads_clientes(PDO $pdo, ?string $tbl, string $de, string $ate): array {
    if (!$tbl) return [];
    $cols = atd_colunas($pdo, $tbl);
    $cId = atd_escolher($cols, ['idatleta', 'idaluno', 'id']);
    $cEmail = atd_escolher($cols, ['email', 'dsemail']);
    $cDt = atd_escolher($cols, ['dt_cadastro', 'dtcadastro', 'created_at', 'dtinclusao']);
    $cOri = atd_escolher($cols, ['origem']);
    if (!$cId || !$cDt) return [];
    $ids = [];
    try {
        $st = $pdo->prepare("SELECT * FROM `$tbl` WHERE substr(`$cDt`, 1, 10) BETWEEN ? AND ? ORDER BY `$cId` DESC LIMIT " . ATD_MAX_LEADS);
        $st->execute([$de, $ate]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (atd_conta_eh_lead($r, $cOri, $cEmail)) $ids[] = (int)$r[$cId];
        }
    } catch (Throwable $e) { wa_log_erro($e); }
    // Quem ganhou matrícula de cliente (paga ou bonificada) segue aqui de propósito: a Central e Pessoas Captadas mostram o funil inteiro,
    // então a pessoa aparece na etapa "Virou cliente" (trLeads) e conta na conversão do canal, mas sai da fila de atendimento.
    // Em Clientes e no painel inicial ela deixa de ser lead (API.ehLead, api.js).
    return $ids;
}

/**
 * Leads do período, juntando o que já existe no sistema:
 *  - Tráfego e Captação (trLeads, a mesma conta da tela "Pessoas Captadas"): quem são, de onde vieram (canal, campanha,
 *    indicação), uso do app, teste, matrícula e a ETAPA do funil. A regra das etapas mora lá e não é repetida aqui;
 *  - Clientes: contas que a tela de Clientes chama de lead (autocadastro e captura), passadas a trLeads como extras;
 *  - WhatsApp: se a pessoa já é contato, se consentiu, se saiu, quantas mensagens da Central recebeu e se respondeu.
 * Cada pessoa sai com o estado no WhatsApp e a mensagem sugerida da Central para a etapa dela.
 * $de e $ate: 'Y-m-d' (data de cadastro).
 */
function atd_leads(PDO $pdo, string $de, string $ate, ?DateTimeImmutable $agora = null, ?array $soIds = null): array {
    wa_garantir_tabelas($pdo);
    trGarantirTabelas($pdo);
    $agora = $agora ?: wa_agora();
    $funis = atd_funis($pdo);
    $tbl = atd_tabela_atleta($pdo);
    // $soIds: o perfil de UMA pessoa pede só aquela conta (qualquer data), sem varrer o período
    $T = trLeads($pdo, $de, $ate, $tbl, $agora->getTimestamp(), $soIds !== null ? $soIds : atd_ids_leads_clientes($pdo, $tbl, $de, $ate));
    $pessoas = $T['pessoas'];

    // Contato sem conta cujo telefone ou e-mail já é de uma conta: é a mesma pessoa, fica só a conta
    $telsConta = []; $emailsConta = [];
    foreach ($pessoas as $p) {
        if ($p['tipo'] !== 'conta') continue;
        $t = wa_normalizar_telefone($p['telefone'] ?? '');
        if ($t !== '') $telsConta[$t] = true;
        $e = mb_strtolower(trim((string)($p['email'] ?? '')), 'UTF-8');
        if ($e !== '') $emailsConta[$e] = true;
    }
    $pessoas = array_values(array_filter($pessoas, function ($p) use ($telsConta, $emailsConta) {
        if ($p['tipo'] !== 'contato') return true;
        $t = wa_normalizar_telefone($p['telefone'] ?? '');
        $e = mb_strtolower(trim((string)($p['email'] ?? '')), 'UTF-8');
        return !(($t !== '' && isset($telsConta[$t])) || ($e !== '' && isset($emailsConta[$e])));
    }));

    // WhatsApp e controle de contato: o estado de cada pessoa (`wa`) e o histórico de contatos (`controle`) já vêm de trLeads, a mesma conta
    // de "Pessoas Captadas". Aqui entra só o que é da Central: em qual funil a pessoa está (pela agenda) e a mensagem sugerida.
    $in = fn(array $v) => implode(',', array_fill(0, count($v), '?'));
    $funilPorTel = [];
    $tels = [];
    foreach ($pessoas as $i => $p) {
        $t = wa_normalizar_telefone($p['telefone'] ?? '');
        $pessoas[$i]['whatsapp'] = $t;
        if ($t !== '') $tels[$t] = true;
    }
    if ($tels) {
        try {
            foreach (array_chunk(array_keys($tels), 400) as $lote) {
                $st = $pdo->prepare("SELECT c.telefone, a.passo FROM intus_wa_agenda a JOIN intus_wa_contato c ON c.idcontato = a.idcontato WHERE c.telefone IN (" . $in($lote) . ") ORDER BY a.idagenda");
                $st->execute($lote);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    if (!isset($funilPorTel[$a['telefone']])) $funilPorTel[$a['telefone']] = atd_passo_decodificar((string)$a['passo'])[0];
                }
            }
        } catch (Throwable $e) { wa_log_erro($e); }
    }
    $vazioWa = ['contato' => false, 'consentiu' => false, 'saiu' => false, 'modo' => null, 'precisa_humano' => false, 'enviadas' => 0, 'recebidas' => 0, 'respondeu' => false, 'ultima_em' => null];
    $hoje = (string)($T['hoje'] ?? wa_agora()->format('Y-m-d'));
    foreach ($pessoas as $i => $p) {
        $w = is_array($p['wa'] ?? null) ? $p['wa'] : $vazioWa;
        $w['telefone_valido'] = $p['whatsapp'] !== '';
        $w['funil'] = $funilPorTel[$p['whatsapp']] ?? null;
        $pessoas[$i]['wa'] = $w;
        // em que fila de contato a pessoa está (mesma regra de Pessoas Captadas: o último registro manda)
        $u = $p['controle']['ultimo'] ?? null;
        $grupo = 'nunca';
        if ($u) {
            if (in_array($u['status'], TR_CONTATO_ENCERRADOS, true)) $grupo = 'encerrado';
            elseif ($u['status'] === 'retornar' && !empty($u['retorno_em']) && $u['retorno_em'] <= $hoje) $grupo = 'retorno';
            else $grupo = 'contatado';
        }
        $pessoas[$i]['grupo_contato'] = $p['etapa'] === 'cliente' ? 'cliente' : $grupo;
        // a mensagem pronta de Tráfego (trMensagem) fica em "mensagem"; a da Central (funis editáveis) vem em "sugestao"
        $pessoas[$i]['sugestao'] = $w['saiu'] ? null : atd_sugestao($funis, $p['etapa'], (string)$p['nome'], (string)($p['indicado_por'] ?? ''));
    }

    // Resumos
    $resumo = []; foreach (TR_ETAPAS as $k => $v) $resumo[$k] = 0;
    $cob = ['total' => count($pessoas), 'telefone_valido' => 0, 'sem_telefone' => 0, 'contato' => 0, 'consentiu' => 0, 'saiu' => 0, 'receberam' => 0, 'responderam' => 0, 'treinaram' => 0, 'clientes' => 0];
    $canais = [];
    // fila de contato contada sobre a lista JÁ sem duplicados (a de Tráfego conta antes de juntar contato e conta da mesma pessoa)
    $rc = ['nao_contatados' => 0, 'contatados' => 0, 'retornos_pendentes' => 0, 'encerrados' => 0];
    foreach ($pessoas as $p) {
        $resumo[$p['etapa']]++;
        $g = $p['grupo_contato'];
        if ($g === 'nunca') $rc['nao_contatados']++; elseif ($g === 'encerrado') $rc['encerrados']++;
        elseif ($g === 'contatado' || $g === 'retorno') { $rc['contatados']++; if ($g === 'retorno') $rc['retornos_pendentes']++; }
        if ($p['wa']['telefone_valido']) $cob['telefone_valido']++; else $cob['sem_telefone']++;
        if ($p['wa']['contato']) $cob['contato']++;
        if ($p['wa']['consentiu']) $cob['consentiu']++;
        if ($p['wa']['saiu']) $cob['saiu']++;
        if ($p['wa']['enviadas'] > 0) $cob['receberam']++;
        if ($p['wa']['respondeu']) $cob['responderam']++;
        if (($p['treinos'] ?? 0) > 0) $cob['treinaram']++;
        if ($p['etapa'] === 'cliente') $cob['clientes']++;
        $k = $p['canal'] ?: 'sem_medicao';
        if (!isset($canais[$k])) $canais[$k] = ['canal' => $k, 'leads' => 0, 'treinaram' => 0, 'clientes' => 0, 'responderam' => 0];
        $canais[$k]['leads']++;
        if (($p['treinos'] ?? 0) > 0) $canais[$k]['treinaram']++;
        if ($p['etapa'] === 'cliente') $canais[$k]['clientes']++;
        if ($p['wa']['respondeu']) $canais[$k]['responderam']++;
    }
    $canais = array_values($canais);
    usort($canais, fn($a, $b) => [$b['clientes'], $b['leads']] <=> [$a['clientes'], $a['leads']]);
    return [
        'periodo' => ['de' => $de, 'ate' => $ate], 'pessoas' => $pessoas, 'resumo' => $resumo, 'etapas' => $T['etapas'],
        'whatsapp' => $cob, 'por_canal' => $canais, 'tabela_atleta' => $tbl !== null, 'sem_medicao' => $T['sem_medicao'] ?? 0,
        'resumo_contato' => $rc, 'wa_configurado' => $T['wa_configurado'] ?? false, 'hoje' => $hoje,
        'limite_atingido' => count($pessoas) >= ATD_MAX_LEADS,
    ];
}

/** [de, ate] em 'Y-m-d' (Brasília) para os últimos $dias dias, contando hoje. */
function atd_periodo(int $dias, ?DateTimeImmutable $agora = null): array {
    $agora = $agora ?: wa_agora();
    $dias = max(1, min(365, $dias));
    return [$agora->modify('-' . ($dias - 1) . ' day')->format('Y-m-d'), $agora->format('Y-m-d')];
}

/** Números de Tráfego e Captação para o mesmo período, direto do relatório de lá (fonte única das visitas e cliques). */
function atd_captacao(PDO $pdo, string $de, string $ate): array {
    $rel = trRelatorio($pdo, $de, $ate, null, atd_tabela_atleta($pdo));
    $k = $rel['kpis']; $a = $rel['kpis_anterior'];
    $topo = fn(array $l, int $n) => array_slice($l, 0, $n);
    return [
        'periodo' => $rel['periodo'],
        'kpis' => ['visitantes' => $k['visitantes'], 'visitas' => $k['visitas'], 'conversoes' => $k['conversoes'], 'contas_criadas' => $k['contas_criadas'], 'contatos_sem_conta' => $k['contatos_sem_conta'],
                   'cliques' => $k['cliques'], 'taxa' => $k['taxa'], 'conversoes_anterior' => $a['conversoes'], 'visitantes_anterior' => $a['visitantes']],
        'funil' => $rel['funil'], 'canais' => $topo($rel['canais'], 8), 'campanhas' => $topo($rel['campanhas'], 8), 'indicacoes' => $topo($rel['indicacoes'], 5),
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Exportação para automação
// ─────────────────────────────────────────────────────────────────────────────

/** Pacote único que o robô e a fila de envio vão ler: funis aprovados, templates a cadastrar, regras e testes ativos. */
function atd_exportar(PDO $pdo): array {
    $aj = wa_ajustes($pdo);
    $funis = [];
    $templates = [];
    foreach (atd_funis($pdo) as $f) {
        $msgs = [];
        foreach ($f['mensagens'] as $m) {
            $linha = $m + ['passo' => atd_passo($f['id'], $m['id'])];
            $msgs[] = $linha;
            if (!empty($m['template'])) {
                $templates[] = [
                    'nome' => $m['template'], 'idioma' => 'pt_BR', 'categoria' => strtoupper($m['categoria'] === 'utilidade' ? 'UTILITY' : 'MARKETING'),
                    'corpo' => $m['texto'], 'variaveis' => (int)preg_match_all('/\{\{\d+\}\}/', $m['texto']),
                    'funil' => $f['id'], 'mensagem' => $m['id'], 'aprovada_pelo_luiz' => !empty($m['aprovada']),
                ];
            }
        }
        $f['mensagens'] = $msgs;
        $funis[] = $f;
    }
    return [
        'versao' => 1,
        'gerado_em' => wa_fmt(wa_agora()),
        'atendente' => $aj['nome_atendente'] ?? 'Cora',
        'regras' => [
            'horario' => [(int)$aj['horario_ini'], (int)$aj['horario_fim']], 'max_proativas_dia' => (int)$aj['max_proativas_dia'],
            'fuso' => 'America/Sao_Paulo', 'janela_horas' => 24, 'condicoes' => ATD_CONDICOES,
            'variaveis' => ['1' => 'nome', '2' => 'link do app', '3' => 'indicador', '4' => 'planos e valores', '5' => 'link de pagamento do plano', '6' => 'nome do plano', '7' => 'valor do plano', '8' => 'prova social'],
        ],
        'planos' => atd_planos($pdo),
        'provas_robo' => array_map(fn($p) => ['id' => $p['idprova'], 'objetivo' => $p['objetivo'], 'sexo' => $p['sexo'], 'destaque' => $p['destaque'], 'texto' => provas_texto($p),
            'midias' => array_map(fn($m) => ['tipo' => $m['tipo'], 'momento' => $m['momento'], 'url' => $m['url']], $p['midias'])], provas_carregar_robo($pdo)),
        'funis' => $funis,
        'templates_meta' => $templates,
        'testes_ab' => array_values(array_filter(atd_testes($pdo), fn($t) => ($t['status'] ?? '') === 'rodando')),
        'conceitos' => array_map(fn($c) => ['id' => $c['id'], 'titulo' => $c['titulo'], 'resumo' => $c['resumo']], atd_conceitos($pdo)),
    ];
}

/** Situação da instalação, sem expor nenhum valor secreto. */
function atd_prontidao(PDO $pdo): array {
    $an = wa_segredo($pdo, 'anthropic_config');
    $wa = wa_config_whatsapp($pdo);
    $cr = wa_segredo($pdo, 'cron_config');
    $uso = atd_uso_hoje($pdo);
    return [
        'claude_chave' => is_array($an) && !empty($an['api_key']),
        'whatsapp_configurado' => $wa !== null,
        'cron_token' => is_array($cr) && !empty($cr['token']),
        'modelo' => wa_ajustes($pdo)['modelo'],
        'atendente' => wa_ajustes($pdo)['nome_atendente'],
        'chamadas_hoje' => $uso['chamadas'], 'limite_dia' => ATD_MAX_CHAMADAS_DIA,
        'custo_hoje_usd' => atd_custo_usd((int)$uso['tin'], (int)$uso['tout']),
        'automacao_ligada' => false,
        'automacao_nota' => 'O envio automático por funil (fila e robô de conversa) ainda não está ligado. As mensagens do teste grátis saem quando a fase da sequência for concluída.',
    ];
}
