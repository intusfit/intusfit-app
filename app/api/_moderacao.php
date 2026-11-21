<?php
/**
 * CONTROLE DE SPAM, PORNOGRAFIA, VIOLÊNCIA E OFENSAS no que o aluno escreve (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto).
 *
 * Pedido do Luiz (21/11/2026): o texto de post e de comentário é analisado. Se parecer spam, pornografia, ameaça ou ofensa, NADA sai do ar sozinho:
 * o item continua visível, entra na fila de "conteúdo sinalizado" no Início do painel e vai um e-mail ao administrador. Lá ele escolhe Manter, Ocultar ou Excluir.
 * A análise NÃO acontece na hora de publicar nem de carregar o Feed (decisão do Luiz: pode demorar minutos). Uma varredura (intusModVarrer, disparada por _carona_moderacao.php
 * depois que a resposta de outra requisição já saiu, no máximo a cada 5 minutos) olha o que chegou desde a última vez e manda UM e-mail-resumo por varredura.
 *
 * O detector é uma lista de regras, sem IA e sem custo por uso. A regra de ouro é NÃO sinalizar a linguagem normal de academia: "que treino foda", "foi de matar",
 * "esse treino vai te matar", "puta treino", "caralho, que carga" passam. Por isso:
 *   - palavrão usado como reforço (foda, caralho, porra, puta que pariu, do caralho, de matar) não é regra por si só;
 *   - insulto só conta quando é DIRIGIDO a alguém ("você é um idiota", "seu otário", "sua vagabunda") ou é xingamento que nunca é elogio;
 *   - ameaça só conta com ALVO ("vou te matar", "te arrebento", "merece morrer"), e a frase que fala de treino (série, perna, cardio...) é perdoada;
 *   - elogio em gíria ("você é louco", "é uma fera", "monstro", "doente") nunca é insulto.
 * Só texto. Foto e vídeo não são analisados (uma imagem imprópria só aparece se o texto ao lado denunciar, ou se um aluno avisar).
 *
 * Estado: tabela intus_moderacao (status pendente, mantido, oculto, excluido). Ocultar = intus_comentario_pub.oculto = 1 ou intus_feed_post.ativo = 3
 * (ninguém vê, o dono do conteúdo também não; "Mostrar de novo" desfaz). Excluir usa as rotas de exclusão que já existem; a fila se acerta sozinha.
 */

const INTUS_MOD_VARREDURA_SEG = 300;   // a análise roda a cada 5 minutos, nunca na hora de publicar

const INTUS_MOD_ROTULOS = ['spam' => 'Spam', 'pornografia' => 'Pornografia', 'violencia' => 'Violência', 'ofensiva' => 'Ofensa'];

/** Minúsculo e sem acento. Não mexe em mais nada (URL e telefone precisam do texto como o aluno escreveu). */
function intusModBase(string $t): string
{
    $t = mb_strtolower($t, 'UTF-8');
    return strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n']);
}

/** Para casar palavras: letras repetidas 3+ vezes viram uma ("puuuuta"), e número/símbolo no meio de palavra vira letra ("p0rn", "m4tar", "put@"). */
function intusModPalavras(string $base): string
{
    $s = preg_replace('/(\p{L})\1{2,}/u', '$1', $base) ?? $base;
    return preg_replace_callback('/[\p{L}0-9@$]*\p{L}[\p{L}0-9@$]*/u', function ($m) {
        return strtr($m[0], ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']);
    }, $s) ?? $s;
}

/** A frase fala de treino? Então ameaça "de brincadeira" da academia ("esse treino vai te matar") é perdoada. */
function intusModFraseDeTreino(string $f): bool
{
    return preg_match('/\b(treino|treinos|treinar|treinei|treinando|treinao|serie|series|perna|pernas|cardio|agachamento|supino|remada|corrida|correr|corri|hiit|circuito|crossfit|wod|spinning|esteira|musculacao|leg ?day|pump|reps?|repeticao|repeticoes|carga|halter|burpees?|prancha|abdominal|bike|pedal|natacao|nadar|aula)\b/u', $f) === 1;
}

/** Regras: [categoria, expressão (já em ASCII minúsculo), descrição, perdoar frase de treino?]. */
function intusModRegras(): array
{
    // "matar de rir", "morrer de inveja", "matar de fome" não são ameaça
    $res = '(?!\s+d[eo]\s+(?:rir|riso|inveja|fome|sede|saudade|vergonha|cansaco|calor|frio|amor|tanto|tanta|treinar|correr|malhar|trabalhar|estudar))';
    $pessoa = '(?:voce|voces|vc|vcs|ele|ela|eles|elas|todo mundo|todos|todas|geral|alguem|essa gente)';
    return [
        // ── Violência: sempre com alvo. "Matar o treino", "matar a fome", "socar o saco" não entram.
        ['violencia', "~\b(?:vou|vamos|vai|va|irei|iria|quero|queria|preciso|bora|ja ja)\s+(?:te|lhe)\s+(?:matar|esfaquear|espancar|estuprar|estrangular|enforcar|torturar|sequestrar|explodir)\b{$res}~u", 'ameaça', true],
        ['violencia', "~\b(?:vou|vamos|vai|quero|queria)\s+(?:matar|esfaquear|espancar|estuprar|atirar\s+em|explodir)\s+{$pessoa}\b{$res}~u", 'ameaça', true],
        ['violencia', "~\bte\s+(?:mato|arrebento|esfaqueio|espanco|estupro|enforco|estrangulo|torturo|sequestro|quebro|encho\s+de\s+porrada|dou\s+um\s+tiro|dou\s+uma\s+facada)\b{$res}~u", 'ameaça', true],
        ['violencia', "~\b(?:vou|vamos|vai)\s+te\s+(?:dar|meter|passar)\s+(?:um\s+)?(?:tiro|facada|bala|soco\s+na\s+cara)\b~u", 'ameaça', true],
        ['violencia', "~\b(?:tiro|bala)\s+na\s+(?:cara|cabeca|testa|nuca)\b~u", 'ameaça', true],
        ['violencia', "~\bmerece(?:m)?\s+(?:morrer|apanhar|ser\s+(?:morto|morta|estuprad[ao]|espancad[ao]))\b~u", 'desejo de mal a alguém', true],
        ['violencia', "~\b(?:morra|morram)\b{$res}|\bmorre\s+(?:logo|desgrac|vagabund|infeliz|maldit)\w*~u", 'desejo de mal a alguém', true],
        ['violencia', "~\b(?:quero|queria|espero|tomara|desejo)\s+(?:que\s+)?(?:te\s+ver|ver\s+(?:voce|vc|ele|ela|eles|elas)|(?:voce|vc|ele|ela|eles|elas))\s+(?:morto|morta|morrer|morra|sofrer|apanhar)\b{$res}~u", 'desejo de mal a alguém', true],
        ['violencia', "~\bmando\s+(?:te\s+|lhe\s+)?(?:matar|espancar|bater)\b~u", 'ameaça', true],
        // autolesão: não é ofensa, é alguém que talvez precise de cuidado. Vale um contato humano.
        ['violencia', "~\b(?:vou|quero|queria|penso\s+em|pensando\s+em|vontade\s+de)\s+(?:me\s+matar|me\s+suicidar|tirar\s+(?:a\s+)?(?:minha\s+)?(?:propria\s+)?vida|acabar\s+com\s+(?:a\s+)?minha\s+vida)\b{$res}~u", 'possível autolesão (vale um contato com cuidado)', true],

        // ── Pornografia e assédio sexual explícito
        ['pornografia', '~\\b(?:porn|porno|pornos|pornografia|pornhub|pornstar|xvideos?|redtube|xnxx|xhamster|only\\s?fans?)\\b~u', 'conteúdo pornográfico', false],
        ['pornografia', '~\\bnudes?\\b~u', 'pede ou oferece nude', false],
        ['pornografia', '~\\b(?:buceta|xoxota|xereca|perereca|ppk|pau\\s+duro|punheta|siririca|boquete|putaria|orgia|gozada)\\b~u', 'linguagem sexual explícita', false],
        ['pornografia', '~\\bsexo\\s+(?:oral|anal|grupal|selvagem)\\b|\\bsexo\\s+(?:com|contigo)\\s+(?:voce|vc|ti)\\b~u', 'linguagem sexual explícita', false],
        ['pornografia', '~\\b(?:manda|mande|envia|envie|passa|quero\\s+ver)\\s+(?:uns\\s+|umas\\s+|um\\s+|uma\\s+|sua\\s+|suas\\s+|tua\\s+|tuas\\s+)?(?:fotos?|fotinhas?|videos?)\\s+(?:pelad[ao]s?|sem\\s+roupa|intim[ao]s?|nua|nu|hot)\\b~u', 'pede foto íntima', false],
        ['pornografia', '~\\bpack\\s+(?:de\\s+)?(?:fotos|nudes|videos)\\b|\\bconteudo\\s+adulto\\b|\\b18\\s?\\+~u', 'conteúdo adulto', false],
        ['pornografia', '~\\b(?:vou|quero|queria|vamos)\\s+te\\s+(?:comer|chupar|foder|pegar\\s+a\\s+forca)\\b|\\bte\\s+(?:como|chupo|fodo)\\b~u', 'assédio sexual', true],

        // ── Ofensa: xingamento que nunca é elogio, ou insulto dirigido a alguém
        ['ofensiva', '~\\b(?:arrombad[oa]s?|fdp|vsf|vtnc|tnc|cuzao|cuzona|vadi[ao]s?|vagabund[ao]s?|escoria|retardad[ao]s?|mongol(?:oide)?s?|viad[oa]s?|veado|sapatao|traveco|bicha)\\b~u', 'xingamento', false],
        ['ofensiva', '~\\bfilh[oa]s?\\s+d[aeo]s?\\s+put[ao]s?\\b~u', 'xingamento', false],
        ['ofensiva', '~\\bvai\\s+(?:se|te)\\s+(?:foder|fuder)\\b|\\bvai\\s+(?:tomar|toma|pro|pra)\\s+(?:no|o)\\s+cu\\b~u', 'xingamento', false],
        ['ofensiva', '~\\b(?:seu|sua|esse|essa|um|uma)\\s+macac[oa]s?\\b|\\b(?:neg[ao]|pret[ao])s?\\s+(?:imund|sujo|suja|fedid|safad)\\w*|\\blixo\\s+humano\\b~u', 'ofensa racista ou de ódio', false],
        ['ofensiva', '~\\b(?:sua|essa|esta|aquela|uma)\\s+(?:put[ao]|vadia)\\b|\\bput[ao]\\s+(?:nojent|imund|velh|barat|safad|sem\\s+vergonha)\\w*~u', 'xingamento', false],
        ['ofensiva', '~\\b(?:voce|vc|vcs|voces|tu|ce)\\s+(?:e|eh|sao|ta|esta|tava|estava)\\s+(?:um|uma|uns|umas)?\\s*(?:muito\\s+|tao\\s+|bem\\s+)?(?:idiot[ao]s?|imbecil|imbecis|burr[ao]s?|otari[ao]s?|babac[ao]s?|inutil|inuteis|ridicul[ao]s?|lixo|nojent[ao]s?|palha[cc]o|trouxa|fracassad[ao]s?|vergonha|gord[ao]s?|feio|feia|feios|feias|baleia|horrivel|nojo|mentiros[ao]|ladr[ao]|golpista|picaret[ao]|charlata[o]|safad[ao]|covarde|bosta|merda|cretin[ao]s?|fake)\\b~u', 'ofensa dirigida', false],
        ['ofensiva', '~\\b(?:seu|sua|seus|suas)\\s+(?:idiot|imbecil|burr|otari|babac|retardad|inutil|ridicul|lixo|nojent|palha|trouxa|fracassad|gord|feio|feia|baleia|safad|covarde|bosta|merda|ladr|golpist|picaret|charlat|cretin|sem\\s+vergonha)\\w*~u', 'ofensa dirigida', false],
        ['ofensiva', '~\\b(?:odeio|detesto)\\s+(?:voce|vc|vcs|voces)\\b~u', 'ofensa dirigida', false],
    ];
}

/** Regras de spam sobre o texto inteiro (URL e telefone não sobrevivem à divisão em frases). */
function intusModSpam(string $base, string $pal): ?array
{
    // endereços da própria Intus e de sites que aluno cita à toa não contam
    $limpo = preg_replace('~(?:https?://)?(?:[a-z0-9-]+\.)*(?:intusfit\.com(?:\.br)?|luiznunest\.com(?:\.br)?|codigodadefinicao\.com(?:\.br)?|youtube\.com|youtu\.be|instagram\.com|spotify\.com|strava\.com)(?:/\S*)?~u', ' ', $base) ?? $base;
    if (preg_match('~(?:https?://|www\.)\S+|\b(?:wa\.me|t\.me|bit\.ly|tinyurl\.com|linktr\.ee|api\.whatsapp\.com|chat\.whatsapp\.com)\S*|\b[a-z0-9][a-z0-9-]{1,}\.(?:com|com\.br|net|org|me|co|io|link|site|shop|store|xyz|top|club|online|app|ly|gg|tv|bio|vip)\b~u', $limpo, $m)) {
        return ['spam', 'link', $m[0]];
    }
    if (preg_match('~(?<!\d)\+55[\s.-]?\(?\d{2}\)?[\s.-]?9?\d{4}[-\s]?\d{4}(?!\d)|(?<!\d)\(?\d{2}\)?\s?9\d{4}[-\s]?\d{4}(?!\d)|(?<!\d)\d{10,11}(?!\d)~u', $base, $m)) {
        return ['spam', 'telefone ou WhatsApp no texto', $m[0]];
    }
    $frases = [
        ['renda extra', 'propaganda'], ['ganhe dinheiro', 'propaganda'], ['ganhar dinheiro (?:online|em casa|facil|rapido)', 'propaganda'], ['trabalhe (?:em|de) casa', 'propaganda'],
        ['link (?:na|no) (?:bio|perfil)', 'propaganda'], ['clique (?:aqui|no link)', 'propaganda'], ['acesse o link', 'propaganda'],
        ['(?:me )?chama(?:r)? (?:no|na|pelo|la no) (?:privado|direct|dm|whats|whatsapp|zap|inbox)', 'chamada para conversa fora do app'],
        ['chame (?:no|na) (?:privado|direct|dm|whats|whatsapp|zap)', 'chamada para conversa fora do app'], ['manda(?:r)? (?:um )?(?:oi|msg|mensagem) (?:no|pro|pra) (?:whats|whatsapp|zap|direct|privado)', 'chamada para conversa fora do app'],
        ['(?:siga|sigam|segue|seguem) (?:o |meu |nosso )?perfil', 'pede seguidor'], ['(?:compre|comprar|vendo|vendemos) seguidores', 'venda de seguidores'], ['seguidores (?:reais|baratos|garantidos)', 'venda de seguidores'],
        ['cassino|tigrinho|fortune tiger|jogo do bicho|aviator|estrela ?bet|esporte da sorte|aposta(?:s)? (?:esportiva|online)|\\bbets?\\b', 'jogo de azar'],
        ['\\bforex\\b|bitcoin|criptomoeda|renda passiva|lucro garantido|multiplique (?:seu )?dinheiro|dobr(?:e|ar) (?:seu )?dinheiro|day ?trade|investimento garantido', 'golpe ou investimento'],
        ['(?:aproveite|so hoje|ultimas unidades|oferta imperdivel|cupom de desconto|frete gratis|compre ja|garanta ja|link do produto)', 'propaganda'],
        ['(?:vendo|vendemos|vendendo) (?:hormonio|anabolizante|ozempic|mounjaro|tirzepatida|canetas?|sarms|pack|conta|curso)', 'venda de produto'],
    ];
    foreach ($frases as $f) {
        if (preg_match('~' . $f[0] . '~u', $pal, $m)) return ['spam', $f[1], $m[0]];
    }
    // anabolizante só incomoda quando está sendo oferecido
    if (preg_match('~\b(?:oxandrolona|durateston|stanozolol|trembolona|hemogenin|clembuterol|boldenona|winstrol|nandrolona|sustanon|primobolan|masteron)\b~u', $pal, $m)
        && preg_match('~\b(?:vend|compr|entreg|encomend|pedido|preco|valor|r\$|chama|whats|zap|pronta entrega)~u', $pal)) {
        return ['spam', 'venda de anabolizante', $m[0]];
    }
    return null;
}

/**
 * Analisa um texto. Devolve null se estiver limpo, ou ['categorias' => [...], 'motivos' => [...], 'trecho' => '...'] (categorias do mais ao menos grave).
 */
function intusModAnalisar(string $texto): ?array
{
    $texto = trim($texto);
    if ($texto === '') return null;
    $base = intusModBase($texto);
    $pal = intusModPalavras($base);
    $achados = [];   // categoria => [motivo, trecho]
    $spam = intusModSpam($base, $pal);
    if ($spam) $achados['spam'] = [$spam[1], $spam[2]];
    $frases = preg_split('~[.!?;\n]+~u', $pal) ?: [$pal];
    $frasesBase = preg_split('~[.!?;\n]+~u', $base) ?: [$base];
    $regras = intusModRegras();
    foreach ($frases as $i => $f) {
        $f = trim($f);
        if ($f === '') continue;
        $treino = intusModFraseDeTreino($f);
        foreach ($regras as $r) {
            if (isset($achados[$r[0]])) continue;
            if ($r[3] && $treino) continue;
            foreach ([$f, trim($frasesBase[$i] ?? '')] as $cand) {
                if ($cand !== '' && preg_match($r[1], $cand, $m)) { $achados[$r[0]] = [$r[2], trim($m[0])]; break; }
            }
        }
    }
    if (!$achados) return null;
    $ordem = ['violencia', 'pornografia', 'ofensiva', 'spam'];
    $cats = []; $motivos = []; $trecho = '';
    foreach ($ordem as $c) {
        if (!isset($achados[$c])) continue;
        $cats[] = $c; $motivos[] = INTUS_MOD_ROTULOS[$c] . ': ' . $achados[$c][0];
        if ($trecho === '') $trecho = mb_substr($achados[$c][1], 0, 80);
    }
    return ['categorias' => $cats, 'motivos' => $motivos, 'trecho' => $trecho];
}

// ───────────────────────── Fila (banco) ─────────────────────────

function intusModGarantirTabela(PDO $pdo): void
{
    static $ok = [];
    $k = spl_object_id($pdo);
    if (!empty($ok[$k])) return;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec('CREATE TABLE IF NOT EXISTS intus_moderacao (
        idmod ' . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY') . ',
        alvo_tipo VARCHAR(12) NOT NULL,
        alvo_id INT NOT NULL,
        idatleta INT NOT NULL DEFAULT 0,
        autor_nome VARCHAR(200) NOT NULL DEFAULT \'\',
        categorias VARCHAR(80) NOT NULL DEFAULT \'\',
        motivo VARCHAR(400) NOT NULL DEFAULT \'\',
        trecho VARCHAR(120) NOT NULL DEFAULT \'\',
        texto TEXT NOT NULL,
        contexto VARCHAR(20) NOT NULL DEFAULT \'\',
        ctx_id INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT \'pendente\',
        email_em VARCHAR(19) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        decidido_em VARCHAR(19) NULL' . ($sqlite ? '' : ',
        INDEX idx_status (status), INDEX idx_alvo (alvo_tipo, alvo_id)') . '
    )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    $ok[$k] = true;
}

/** O aluno mandou o mesmo texto longo 4 vezes entre as últimas 6 mensagens dele? (spam de repetição) */
function intusModRepetido(PDO $pdo, int $idatleta, string $texto): bool
{
    $texto = trim($texto);
    if ($idatleta <= 0 || mb_strlen($texto) < 15) return false;
    try {
        $st = $pdo->prepare("SELECT texto FROM intus_comentario_pub WHERE autor_tipo = 'aluno' AND autor_id = ? ORDER BY idcomentario DESC LIMIT 6");
        $st->execute([$idatleta]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $t) if (trim((string)$t) === $texto) $n++;
        return $n >= 4;
    } catch (Throwable $e) { return false; }
}

/**
 * Analisa e, se for o caso, põe na fila. Devolve o id na fila ou null. Quem manda o e-mail é a varredura (um resumo por rodada). Nunca derruba quem chamou.
 * $alvoTipo: 'comentario' (alvo_id = idcomentario; $contexto = alvo_tipo do comentário, $ctxId = id do post) ou 'post' (alvo_id = idpost).
 */
function intusModRegistrar(PDO $pdo, string $alvoTipo, int $alvoId, int $idatleta, string $autorNome, string $texto, string $contexto = '', int $ctxId = 0): ?int
{
    try {
        $a = intusModAnalisar($texto);
        if (!$a && $alvoTipo === 'comentario' && intusModRepetido($pdo, $idatleta, $texto)) {
            $a = ['categorias' => ['spam'], 'motivos' => ['Spam: a mesma mensagem repetida várias vezes'], 'trecho' => mb_substr(trim($texto), 0, 80)];
        }
        if (!$a) return null;
        intusModGarantirTabela($pdo);
        $pdo->prepare('INSERT INTO intus_moderacao (alvo_tipo, alvo_id, idatleta, autor_nome, categorias, motivo, trecho, texto, contexto, ctx_id) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$alvoTipo, $alvoId, $idatleta, mb_substr($autorNome, 0, 200), implode(',', $a['categorias']), mb_substr(implode(' | ', $a['motivos']), 0, 400),
                       mb_substr($a['trecho'], 0, 120), mb_substr($texto, 0, 1000), mb_substr($contexto, 0, 20), $ctxId]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { return null; }
}

function intusModOnde(array $r): string
{
    if ($r['alvo_tipo'] === 'post') return 'Post no Feed';
    $m = ['feed_post' => 'Comentário em post do Feed', 'mural' => 'Comentário no mural', 'intus_post' => 'Comentário em post da Intus',
          'nutri_dica' => 'Comentário no mural da Nutrição', 'nutri_video' => 'Comentário em vídeo da Nutrição', 'nutri_receita' => 'Comentário em receita'];
    return $m[$r['contexto']] ?? 'Comentário';
}

/** Um e-mail-resumo por varredura, com os itens novos (até 10 no texto). Sem limite por hora: a varredura roda no máximo a cada 5 minutos. */
function intusModEnviarResumo(PDO $pdo, array $ids): bool
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return false;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM intus_moderacao WHERE idmod IN ($ph) ORDER BY idmod");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return false;
    $pend = (int)$pdo->query("SELECT COUNT(*) FROM intus_moderacao WHERE status = 'pendente'")->fetchColumn();
    $todas = [];
    foreach ($rows as $r) foreach (array_filter(explode(',', (string)$r['categorias'])) as $c) $todas[$c] = INTUS_MOD_ROTULOS[$c] ?? $c;
    $assunto = count($rows) === 1
        ? 'Intus Fit: conteúdo sinalizado (' . implode(', ', $todas) . ') de ' . ($rows[0]['autor_nome'] !== '' ? $rows[0]['autor_nome'] : 'um aluno')
        : 'Intus Fit: ' . count($rows) . ' conteúdos sinalizados (' . implode(', ', $todas) . ')';
    $corpo = (count($rows) === 1 ? "Um texto publicado por um aluno foi sinalizado pelo controle automático.\n" : count($rows) . " textos publicados por alunos foram sinalizados pelo controle automático.\n") . "\n";
    foreach (array_slice($rows, 0, 10) as $i => $r) {
        $corpo .= ($i + 1) . ') ' . ($r['autor_nome'] !== '' ? $r['autor_nome'] : '(sem nome)') . ' · ' . intusModOnde($r) . "\n"
               . '   ' . $r['motivo'] . "\n"
               . '   "' . mb_substr(str_replace(["\r", "\n"], ' ', (string)$r['texto']), 0, 300) . "\"\n\n";
    }
    if (count($rows) > 10) $corpo .= '... e mais ' . (count($rows) - 10) . " no painel.\n\n";
    $corpo .= "Os textos continuam visíveis para os alunos até você decidir. No Início do painel, em \"Conteúdo sinalizado\", escolha Manter, Ocultar ou Excluir:\n"
        . "https://intusfit.com.br/app/painel/index.html\n\n"
        . "Itens aguardando decisão: {$pend}.\n"
        . "Este aviso é automático. Texto de academia como \"que treino foda\" ou \"foi de matar\" não é sinalizado; se algo normal vier para cá, use Manter.";
    if (!empty($GLOBALS['INTUS_MOD_EMAIL_STUB'])) $ok = (bool)call_user_func($GLOBALS['INTUS_MOD_EMAIL_STUB'], $assunto, $corpo);
    else { require_once __DIR__ . '/_rotinas.php'; $ok = intusRotinasEmail($assunto, $corpo); }
    if ($ok) $pdo->prepare('UPDATE intus_moderacao SET email_em = ? WHERE idmod IN (' . $ph . ')')->execute(array_merge([date('Y-m-d H:i:s')], $ids));
    return $ok;
}

// ───────────────────────── Varredura ─────────────────────────

function intusModEstadoArq(): string
{
    return $GLOBALS['INTUS_MOD_ESTADO_ARQ'] ?? (__DIR__ . '/../config/moderacao.json');
}

/**
 * Analisa o que chegou depois da última varredura (comentários de alunos e posts) e manda um e-mail-resumo se algo foi sinalizado.
 * Na primeira vez só marca o ponto de partida (não varre o histórico). Se der erro no meio, o ponto de partida não avança além do que foi analisado.
 * @return array{novos:int,comentarios:int,posts:int,email:bool,inicio?:bool}
 */
function intusModVarrer(PDO $pdo, int $limite = 500): array
{
    intusModGarantirTabela($pdo);
    $arq = intusModEstadoArq();
    $e = is_file($arq) ? json_decode((string)@file_get_contents($arq), true) : null;
    if (!is_array($e) || !isset($e['comentario'], $e['post'])) {
        $c = (int)$pdo->query('SELECT COALESCE(MAX(idcomentario),0) FROM intus_comentario_pub')->fetchColumn();
        $p = (int)$pdo->query('SELECT COALESCE(MAX(idpost),0) FROM intus_feed_post')->fetchColumn();
        @file_put_contents($arq, json_encode(['comentario' => $c, 'post' => $p, 'em' => date('c')]), LOCK_EX);
        return ['novos' => 0, 'comentarios' => 0, 'posts' => 0, 'email' => false, 'inicio' => true];
    }
    $novos = []; $nc = 0; $np = 0;
    $ultC = (int)$e['comentario']; $ultP = (int)$e['post'];
    $q = $pdo->prepare("SELECT idcomentario, alvo_tipo, alvo_id, autor_id, autor_nome, texto FROM intus_comentario_pub WHERE idcomentario > ? AND autor_tipo = 'aluno' ORDER BY idcomentario LIMIT " . (int)$limite);
    $q->execute([$ultC]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $nc++; $ultC = (int)$r['idcomentario'];
        $id = intusModRegistrar($pdo, 'comentario', (int)$r['idcomentario'], (int)$r['autor_id'], (string)$r['autor_nome'], (string)$r['texto'], (string)$r['alvo_tipo'], (int)$r['alvo_id']);
        if ($id) $novos[] = $id;
    }
    $q = $pdo->prepare('SELECT p.idpost, p.idatleta, p.legenda, p.localizacao FROM intus_feed_post p WHERE p.idpost > ? AND p.ativo > 0 ORDER BY p.idpost LIMIT ' . (int)$limite);
    $q->execute([$ultP]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $np++; $ultP = (int)$r['idpost'];
        $txt = trim((string)$r['legenda'] . "\n" . (string)($r['localizacao'] ?? ''));
        if ($txt === '') continue;
        if (!intusModAnalisar($txt)) continue;
        $id = intusModRegistrar($pdo, 'post', (int)$r['idpost'], (int)$r['idatleta'], intusModNomeAluno($pdo, (int)$r['idatleta']), $txt, 'feed_post', (int)$r['idpost']);
        if ($id) $novos[] = $id;
    }
    @file_put_contents($arq, json_encode(['comentario' => $ultC, 'post' => $ultP, 'em' => date('c')]), LOCK_EX);
    $mail = false;
    if ($novos) { try { $mail = intusModEnviarResumo($pdo, $novos); } catch (Throwable $x) { $mail = false; } }
    return ['novos' => count($novos), 'comentarios' => $nc, 'posts' => $np, 'email' => $mail];
}

/** Nome do aluno para o aviso de um post (a tabela do atleta varia por instalação; sem nome, o e-mail diz "(sem nome)"). */
function intusModNomeAluno(PDO $pdo, int $id): string
{
    foreach (['atletas', 'atleta', 'intus_atleta'] as $t) {
        try { $q = $pdo->prepare("SELECT nome FROM `$t` WHERE idatleta = ?"); $q->execute([$id]); $n = $q->fetchColumn(); if ($n) return (string)$n; } catch (Throwable $e) {}
    }
    return '';
}

/** Itens pendentes cujo conteúdo já sumiu (o aluno apagou, ou foi excluído pelo Feed) saem da fila sozinhos como "excluido". */
function intusModAcertar(PDO $pdo): void
{
    $rows = $pdo->query("SELECT idmod, alvo_tipo, alvo_id FROM intus_moderacao WHERE status IN ('pendente','oculto')")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        try {
            if ($r['alvo_tipo'] === 'comentario') {
                $q = $pdo->prepare('SELECT 1 FROM intus_comentario_pub WHERE idcomentario = ?');
                $q->execute([(int)$r['alvo_id']]);
                $existe = (bool)$q->fetchColumn();
            } else {
                $q = $pdo->prepare('SELECT ativo FROM intus_feed_post WHERE idpost = ?');
                $q->execute([(int)$r['alvo_id']]);
                $a = $q->fetchColumn();
                $existe = $a !== false && (int)$a !== 0;
            }
        } catch (Throwable $e) { continue; }
        if (!$existe) $pdo->prepare("UPDATE intus_moderacao SET status = 'excluido', decidido_em = ? WHERE idmod = ?")->execute([date('Y-m-d H:i:s'), (int)$r['idmod']]);
    }
}

function intusModLinha(array $r): array
{
    return ['idmod' => (int)$r['idmod'], 'alvo_tipo' => $r['alvo_tipo'], 'alvo_id' => (int)$r['alvo_id'], 'idatleta' => (int)$r['idatleta'], 'autor' => $r['autor_nome'],
            'categorias' => array_values(array_filter(explode(',', (string)$r['categorias']))), 'motivo' => $r['motivo'], 'trecho' => $r['trecho'], 'texto' => $r['texto'],
            'onde' => intusModOnde($r), 'status' => $r['status'], 'quando' => $r['created_at']];
}

/** Para o Início do painel: pendentes (mais novos primeiro) e os últimos ocultos (para "Mostrar de novo"). */
function intusModListar(PDO $pdo): array
{
    intusModGarantirTabela($pdo);
    intusModAcertar($pdo);
    $pend = $pdo->query("SELECT * FROM intus_moderacao WHERE status = 'pendente' ORDER BY idmod DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $ocu = $pdo->query("SELECT * FROM intus_moderacao WHERE status = 'oculto' ORDER BY idmod DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    return ['pendentes' => array_map('intusModLinha', $pend), 'ocultos' => array_map('intusModLinha', $ocu)];
}

/** Um comentário e todas as respostas dele, em qualquer nível. */
function intusModComentarioComRespostas(PDO $pdo, int $id): array
{
    $ids = [$id]; $fronteira = [$id];
    for ($n = 0; $n < 30 && $fronteira; $n++) {
        $ph = implode(',', array_fill(0, count($fronteira), '?'));
        $st = $pdo->prepare("SELECT idcomentario FROM intus_comentario_pub WHERE resposta_a IN ($ph)");
        $st->execute($fronteira);
        $filhos = array_values(array_diff(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)), $ids));
        $ids = array_merge($ids, $filhos);
        $fronteira = $filhos;
    }
    return $ids;
}

/**
 * Decisão do administrador. $acao: 'manter' (fica visível, sai da fila), 'ocultar' (some para todos, reversível), 'restaurar' (mostra de novo).
 * 'excluir' não passa por aqui: o painel usa as rotas de exclusão de sempre e a fila se acerta sozinha (intusModAcertar).
 * @return array{ok:bool,erro?:string,status?:string}
 */
function intusModDecidir(PDO $pdo, int $idmod, string $acao): array
{
    intusModGarantirTabela($pdo);
    $st = $pdo->prepare('SELECT * FROM intus_moderacao WHERE idmod = ?');
    $st->execute([$idmod]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return ['ok' => false, 'erro' => 'item não encontrado'];
    if (!in_array($acao, ['manter', 'ocultar', 'restaurar'], true)) return ['ok' => false, 'erro' => 'ação inválida'];
    $agora = date('Y-m-d H:i:s');
    if ($acao === 'manter') {
        $novo = 'mantido';
    } elseif ($acao === 'ocultar') {
        $novo = 'oculto';
        if ($r['alvo_tipo'] === 'comentario') {
            $ids = intusModComentarioComRespostas($pdo, (int)$r['alvo_id']);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE intus_comentario_pub SET oculto = 1 WHERE idcomentario IN ($ph)")->execute($ids);
        } else {
            $pdo->prepare('UPDATE intus_feed_post SET ativo = 3 WHERE idpost = ? AND ativo = 1')->execute([(int)$r['alvo_id']]);
        }
    } else {
        $novo = 'mantido';
        if ($r['alvo_tipo'] === 'comentario') {
            $ids = intusModComentarioComRespostas($pdo, (int)$r['alvo_id']);
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE intus_comentario_pub SET oculto = 0 WHERE idcomentario IN ($ph)")->execute($ids);
        } else {
            $pdo->prepare('UPDATE intus_feed_post SET ativo = 1 WHERE idpost = ? AND ativo = 3')->execute([(int)$r['alvo_id']]);
        }
    }
    $pdo->prepare('UPDATE intus_moderacao SET status = ?, decidido_em = ? WHERE idmod = ?')->execute([$novo, $agora, $idmod]);
    return ['ok' => true, 'status' => $novo];
}
