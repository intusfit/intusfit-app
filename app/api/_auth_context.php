<?php
/**
 * Intus Fit — Auth Context Helper
 *
 * Extrai contexto do usuário a partir do Bearer token e determina permissões.
 *
 * Modelo de acesso:
 *   - Admin (tpacesso = 'A'): acesso total a todos atletas e funções
 *   - Professor/Nutri/Externo: acesso APENAS aos atletas vinculados via professores_responsaveis
 *   - Aluno: acesso apenas aos próprios dados
 *   - Token desconhecido: acesso NEGADO (segurança: negar por padrão)
 *
 * Token formats:
 *   prof-{idusuario}-{timestamp}
 *   local-{idusuario}-{timestamp}
 *   aluno-{idatleta}-{timestamp}
 */

// ─────────────────────────────────────────────────────────────────────────────
// INTERRUPTOR DOS TOKENS ANTIGOS
// true  = ainda aceita "prof-<id>-...", "local-<id>-...", "aluno-<id>-..."
// false = so aceita sessao real validada em intus_sessions (RECOMENDADO)
//
// Deixe em true apenas ate que o login de professor devolva server_token.
// Enquanto estiver true, qualquer pessoa que saiba montar "prof-1-1" tem
// acesso de administrador. Trocar para false fecha isso; o efeito colateral
// e que quem tiver login antigo guardado precisa entrar de novo, uma vez.
// ─────────────────────────────────────────────────────────────────────────────
// DESLIGADO em 20/08/2026.
//
// Condicoes que faltavam e agora estao cumpridas:
//   - o login de professor e de aluno devolve sessao real de 64 caracteres,
//     gravada em intus_sessions (verificado no ar);
//   - o painel e o aplicativo reconhecem o 401 e levam para a tela de login
//     com aviso claro, em vez de desenhar tela vazia;
//   - a entrada aceita e-mail OU codigo de acesso, entao ninguem fica de fora
//     por nao lembrar o e-mail cadastrado.
//
// O que isto habilita, e que sem isto nao funciona: DESCONECTAR um aparelho
// pelo painel. Token antigo nao fica gravado em lugar nenhum — nao ha o que
// invalidar. Foi por isso que o celular entrado na conta errada nao podia ser
// derrubado de dentro do sistema.
//
// Efeito ao subir: quem tiver login antigo guardado entra uma vez de novo.
// Para voltar atras, troque false por true aqui e suba o arquivo.
if (!defined('PERMITIR_TOKEN_LEGADO')) define('PERMITIR_TOKEN_LEGADO', false);

function getAuthContext(PDO $pdo, string $tok): array {
    $ctx = ['idusuario' => 0, 'admin' => false, 'is_aluno' => false, 'nmusuario' => '', 'idatleta' => 0, 'token_legado' => false];

    // Parse token format
    if (PERMITIR_TOKEN_LEGADO && preg_match('/^aluno-(\d+)-/', $tok, $m)) {
        $ctx['is_aluno'] = true;
        $ctx['idusuario'] = (int)$m[1];
        $ctx['idatleta']  = (int)$m[1];   // para o token de aluno, o id E o do atleta
        return $ctx;
    }

    // Tokens no formato "prof-<id>-<timestamp>" / "local-<id>-<timestamp>" sao
    // AUTO-DECLARADOS: nao passam por nenhuma conferencia, nao tem assinatura e
    // nao existem na tabela de sessoes. Qualquer pessoa consegue montar
    // "prof-1-1" e receber acesso total. So continuam aceitos enquanto este
    // interruptor estiver ligado, para nao derrubar quem ainda tem login antigo
    // guardado no navegador. DESLIGUE assim que o login de professor passar a
    // devolver server_token — a partir dai o formato antigo some sozinho.
    if (PERMITIR_TOKEN_LEGADO && preg_match('/^(?:prof|local)-(\d+)-/', $tok, $m)) {
        $ctx['idusuario'] = (int)$m[1];
        $ctx['token_legado'] = true;
    }

    // ATENCAO — bloco de JWT REMOVIDO de proposito.
    // Ele decodificava o miolo do token e confiava no campo "id" SEM NUNCA
    // conferir a assinatura. Qualquer pessoa podia montar um JWT com {"id":1}
    // e entrar como administrador. Nao havia chave secreta em lugar nenhum do
    // sistema, entao nao existe como validar esse formato: ele foi eliminado.
    // Autenticacao valida agora e apenas a sessao gravada em intus_sessions.

    // Server-side session token lookup (64 hex chars)
    if ($ctx['idusuario'] <= 0 && preg_match('/^[0-9a-f]{64}$/i', $tok)) {
        try {
            $hash = hash('sha256', $tok);
            $st = $pdo->prepare("SELECT user_id, user_type, admin FROM intus_sessions WHERE token_hash = ? AND expires_at > NOW() LIMIT 1");
            $st->execute([$hash]);
            $sess = $st->fetch(PDO::FETCH_ASSOC);
            if ($sess) {
                $ctx['idusuario'] = (int)$sess['user_id'];
                $ctx['is_aluno'] = ($sess['user_type'] === 'aluno');
                // SEGURANCA: para sessao de ALUNO, encerrar aqui.
                // Antes, o fluxo seguia adiante e o aluno podia ganhar privilegio de
                // duas formas: (a) se o id dele fosse 1 ou 2, virava admin direto —
                // e o id 1 era justamente o das contas fantasma que varios alunos
                // compartilhavam; (b) o id dele era procurado na tabela de PROFESSORES
                // logo abaixo, herdando o admin de quem tivesse o mesmo numero.
                // Aluno nunca e admin e nunca deve ser buscado na tabela de usuarios.
                if ($ctx['is_aluno']) {
                    $ctx['idatleta'] = $ctx['idusuario'];
                    $ctx['admin'] = false;
                    return $ctx;
                }
                if (in_array($ctx['idusuario'], [1, 2])) {
                    $ctx['admin'] = true;
                    return $ctx;
                }
            }
        } catch (Throwable $e) {}
    }

    // Token não parseável = sem acesso (segurança: deny by default)
    if ($ctx['idusuario'] <= 0) {
        return $ctx;
    }

    // Cinto de seguranca: nenhum caminho abaixo (tabela de usuarios, atalho de id
    // 1/2) pode ser alcancado por um aluno. Se chegou aqui marcado como aluno,
    // devolve sem privilegio.
    if (!empty($ctx['is_aluno'])) {
        $ctx['idatleta'] = $ctx['idusuario'];
        $ctx['admin'] = false;
        return $ctx;
    }

    // Buscar usuário na tabela
    $userTable = null;
    foreach (['professor', 'usuario', 'usuarios', 'professores'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $userTable = $t; break; }
        catch (Throwable $e) {}
    }

    if (!$userTable) {
        if (in_array($ctx['idusuario'], [1, 2])) $ctx['admin'] = true;
        return $ctx;
    }

    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$userTable`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $colId = null;
    foreach (['idusuario', 'idprofessor', 'id'] as $c) { if (in_array($c, $cols)) { $colId = $c; break; } }
    $colAdmin = null;
    foreach (['admin', 'tpacesso', 'tipo', 'is_admin'] as $c) { if (in_array($c, $cols)) { $colAdmin = $c; break; } }
    $colNome = null;
    foreach (['nome', 'nmusuario', 'nmprofessor', 'name'] as $c) { if (in_array($c, $cols)) { $colNome = $c; break; } }

    if (!$colId) return $ctx;

    $st = $pdo->prepare("SELECT * FROM `$userTable` WHERE `$colId` = ? LIMIT 1");
    $st->execute([$ctx['idusuario']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        if (in_array($ctx['idusuario'], [1, 2])) $ctx['admin'] = true;
        return $ctx;
    }

    if ($colNome) $ctx['nmusuario'] = $row[$colNome] ?? '';

    // Determinar admin pelo campo no banco
    if ($colAdmin && isset($row[$colAdmin])) {
        $val = $row[$colAdmin];
        $ctx['admin'] = ($val === true || $val === 1 || $val === '1' || strtoupper($val) === 'S' || strtoupper($val) === 'A');
    } elseif (in_array($ctx['idusuario'], [1, 2])) {
        $ctx['admin'] = true;
    }

    return $ctx;
}

/**
 * Retorna IDs de atletas vinculados ao usuário.
 * Usa a coluna professores_responsaveis (JSON array) na tabela de atletas.
 * Filtragem PHP-side para compatibilidade com MySQL sem JSON_CONTAINS.
 */
function getAtletasDoUsuario(PDO $pdo, int $idusuario): array {
    $atletaTable = null;
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $atletaTable = $t; break; }
        catch (Throwable $e) {}
    }
    if (!$atletaTable) return [];

    $cols = array_column($pdo->query("SHOW COLUMNS FROM `$atletaTable`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $colId = null;
    foreach (['idatleta', 'idaluno', 'id'] as $c) { if (in_array($c, $cols)) { $colId = $c; break; } }
    $colProfs = null;
    foreach (['professores_responsaveis', 'idprofessor'] as $c) { if (in_array($c, $cols)) { $colProfs = $c; break; } }
    if (!$colId || !$colProfs) return [];

    // Modo simples: coluna FK direta
    if ($colProfs === 'idprofessor') {
        $st = $pdo->prepare("SELECT `$colId` FROM `$atletaTable` WHERE `$colProfs` = ?");
        $st->execute([$idusuario]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    // Modo JSON: professores_responsaveis = "[2,3,6]"
    $rows = $pdo->query("SELECT `$colId`, `$colProfs` FROM `$atletaTable`")->fetchAll(PDO::FETCH_ASSOC);
    $ids = [];
    foreach ($rows as $r) {
        $raw = $r[$colProfs] ?? '';
        if (!$raw || $raw === '[]' || $raw === 'null') continue;
        $arr = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        if (in_array($idusuario, array_map('intval', $arr))) {
            $ids[] = (int)$r[$colId];
        }
    }
    return $ids;
}

/**
 * Aplica o filtro de acesso e retorna a lista de atletas permitidos.
 * Retorna null se admin (sem restrição), array de IDs se não-admin.
 * Array vazio = nenhum atleta vinculado (usuario sem carteira).
 */
function getAccessFilter(PDO $pdo, string $tok): ?array {
    $ctx = getAuthContext($pdo, $tok);
    if ($ctx['admin']) return null; // admin = sem filtro
    if ($ctx['idusuario'] <= 0) return []; // token invalido = nenhum acesso
    return getAtletasDoUsuario($pdo, $ctx['idusuario']);
}
