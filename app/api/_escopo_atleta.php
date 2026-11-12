<?php
/**
 * Intus Fit — escopo por professor (a "carteira" de alunos) para o catalogo.php.
 *
 * POR QUE EXISTE (revisão de segurança de 11/11/2026, achado S1): atletas.php, treinos.php, mensalidades.php e dashboard.php já
 * limitavam o professor aos alunos em que ele é responsável (professores_responsaveis, via getAtletasDoUsuario). O catalogo.php
 * não: avaliacoes, nutricao, anamnese, anamneses e profile aceitavam qualquer conta de equipe com sessão válida, e as listas sem
 * ?atleta= devolviam a base inteira. Dado de saúde de todos os alunos, para qualquer professor ou nutricionista.
 *
 * Modelo (o mesmo do resto do sistema):
 *   administrador        sem restrição (escopo null)
 *   aluno                só o próprio id (as travas próprias do catalogo.php; a guarda de pedido NÃO se aplica a ele)
 *   professor / nutri    só os alunos de getAtletasDoUsuario(); aluno sem responsável marcado é só do administrador
 *
 * Funções puras, testáveis sem o catalogo.php (testes/escopo_professor.php).
 */
require_once __DIR__ . '/_auth_context.php';   // getAtletasDoUsuario()

// Chaves do pedido (query e corpo JSON) que carregam o id de um aluno, com o nome exato que as rotas do catalogo.php leem.
// Rota nova que leia o id de aluno com outro nome PRECISA entrar aqui, senão a guarda não a enxerga.
const INTUS_ESCOPO_CHAVES = ['atleta', 'idatleta', '_idatleta'];

// Ações em que o id de aluno do pedido NÃO identifica um dado privado dele e que ficam fora da guarda. A regra é negar por padrão:
// ação nova com ?atleta= / idatleta passa pela guarda sem ninguém precisar lembrar de cadastrá-la. Cada isenção tem o motivo.
const INTUS_ESCOPO_ISENTAS = [
    'atleta_existe',   // pública: só diz se o id existe (usada antes do login); nenhum dado
    'feed_posts',      // Feed: conteúdo publicado para a comunidade; idatleta = de quem mostrar o perfil. A equipe modera tudo, por desenho
    'perfil_config',   // privacidade do perfil social (flags), não é dado de saúde; a equipe vê tudo, por desenho
    'resultados',      // Quadro de Resultados: publicado pelo próprio aluno com aceite; idatleta = filtro do mural; a equipe modera
];

/**
 * Quem a sessão pode tocar. null = sem restrição (administrador). Aluno = [o próprio id]. Equipe = a carteira. Falha = [] (fecha).
 */
function intusEscopoCarteira(PDO $pdo, array $ctx): ?array {
    static $cache = [];
    if (!empty($ctx['admin'])) return null;
    if (!empty($ctx['is_aluno'])) {
        $me = (int)($ctx['idatleta'] ?? $ctx['idusuario'] ?? 0);
        return $me > 0 ? [$me] : [];
    }
    $uid = (int)($ctx['idusuario'] ?? 0);
    if ($uid <= 0) return [];
    $k = spl_object_id($pdo) . ':' . $uid;
    if (!isset($cache[$k])) {
        try {
            $cache[$k] = array_values(array_unique(array_map('intval', getAtletasDoUsuario($pdo, $uid))));
        } catch (Throwable $e) {
            @error_log('[intus] escopo: falha ao ler a carteira do usuario ' . $uid . ': ' . $e->getMessage());
            return [];
        }
    }
    return $cache[$k];
}

/**
 * Que ids de aluno o pedido cita (query + corpo JSON). Usa o MESMO cast (int) das rotas, para a guarda e a rota enxergarem o mesmo
 * número. Valor que não é escalar (lista/objeto no lugar de um id) é marcado como inválido: (int) de lista vale 1 e apontaria o aluno 1.
 * @return array{ids:int[], invalido:bool}
 */
function intusEscopoIdsDoPedido(array $get, array $body): array {
    $ids = []; $invalido = false;
    foreach ([$get, $body] as $fonte) {
        foreach (INTUS_ESCOPO_CHAVES as $k) {
            if (!array_key_exists($k, $fonte)) continue;
            $v = $fonte[$k];
            if (is_array($v)) { $invalido = true; continue; }
            $n = (int)$v;
            if ($n > 0) $ids[$n] = true;
        }
    }
    return ['ids' => array_keys($ids), 'invalido' => $invalido];
}

/** Pedido x carteira. Todos os ids citados precisam estar na carteira. @return array{ok:bool,codigo:int,erro:string,fora:int[]} */
function intusEscopoAvaliar(array $pedido, array $carteira): array {
    if (!empty($pedido['invalido'])) return ['ok' => false, 'codigo' => 400, 'erro' => 'parametro de aluno invalido', 'fora' => []];
    $fora = array_values(array_diff($pedido['ids'], $carteira));
    if ($fora) return ['ok' => false, 'codigo' => 403, 'erro' => 'aluno fora da sua carteira', 'fora' => $fora];
    return ['ok' => true, 'codigo' => 200, 'erro' => '', 'fora' => []];
}

/** Fragmento "idatleta IN (?,?)" e parâmetros, para filtrar lista. null = sem filtro; carteira vazia = "1 = 0" (não devolve nada). */
function intusEscopoWhere(?array $escopo, string $coluna = 'idatleta'): array {
    if ($escopo === null) return ['', []];
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $coluna)) throw new InvalidArgumentException('coluna invalida');
    if (!$escopo) return ['1 = 0', []];
    return [$coluna . ' IN (' . implode(',', array_fill(0, count($escopo), '?')) . ')', array_values($escopo)];
}

/**
 * O registro (achado pelo id dele) é de um aluno da carteira? Para PUT/DELETE por id de avaliação, plano, aviso...
 * Registro inexistente = true: não há dado de aluno a proteger (a operação não altera nada). Dono nulo/desconhecido = só administrador.
 * Nomes de tabela e coluna vêm do código, nunca do cliente.
 */
function intusEscopoDono(PDO $pdo, ?array $escopo, string $tabela, string $colPk, int $id, string $colDono = 'idatleta'): bool {
    if ($escopo === null) return true;
    foreach ([$tabela, $colPk, $colDono] as $n) { if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $n)) return false; }
    $st = $pdo->prepare("SELECT `$colDono` FROM `$tabela` WHERE `$colPk` = ? LIMIT 1");
    $st->execute([$id]);
    $dono = $st->fetchColumn();
    if ($dono === false) return true;
    return in_array((int)$dono, $escopo, true);
}
