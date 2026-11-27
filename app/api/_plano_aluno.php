<?php
/**
 * PLANO DO ALUNO: "só teste grátis" ou tem plano ativo? (biblioteca interna: o "_" no nome faz o .htaccess bloquear o acesso direto)
 *
 * Regra do Luiz (20/10/2026): o aluno do teste grátis treina, marca cardio e entra no ranking e nos pontos como qualquer outro, mas NÃO
 * publica no Feed nem comenta (posts, mural e comentários) enquanto o único plano dele for o teste. Libera quando houver outro plano ativo.
 *
 * Teste grátis = matrícula cujo histórico ou tipo de plano fala de teste, grátis, trial, degusta ou experimental (a criada por aluno_login.php é
 * "Teste Grátis (N dias)"). "Cortesia" NÃO é teste: é plano dado de presente a gente de verdade e libera tudo.
 * Plano ativo = matrícula que não é de teste, não cancelada, encerrada, trancada nem inativa, paga, e cuja cobertura (dtvencimento) ainda vale,
 * com a mesma carência de 5 dias do resto do sistema.
 * CONTA GRÁTIS (27/11/2026): publicar e comentar exige plano ativo. Conta sem matrícula, vencida ou só com teste é barrada. Também conta como
 * ativo o ciclo já iniciado há poucos dias e ainda sem baixa, e o de valor zero. Se não der para consultar o banco, ninguém é barrado.
 */

const INTUS_PLANO_CARENCIA_DIAS = 5;
const INTUS_COBRANCA_FOLGA_DIAS = 7;   // matrícula iniciada e ainda sem baixa: tolerância de 5 dias mais fim de semana, como o app
const INTUS_MSG_SEM_PLANO = 'Publicar no Feed e comentar não estão disponíveis nesta conta. Cardio, ranking e pontos continuam liberados.';
const INTUS_MSG_SO_TESTE = 'O teste grátis não inclui publicar no Feed nem comentar. Isso libera assim que você tiver um plano ativo.';

function intusMatriculaEhTeste(array $m): bool
{
    return preg_match('/\bteste\b|gr[aá]tis|trial|degusta|experimental/iu', (string)($m['tipo_plano'] ?? '') . ' ' . (string)($m['dshistorico'] ?? '')) === 1;
}

/** @param array[] $linhas matrículas do aluno (dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto, stmatricula). @return array{teste_ativo:bool,plano_ativo:bool,so_teste:bool} */
function intusPlanoAlunoDeLinhas(array $linhas, string $hoje): array
{
    $teste = false; $plano = false;
    $limite = date('Y-m-d', strtotime($hoje . ' -' . INTUS_PLANO_CARENCIA_DIAS . ' days'));
    foreach ($linhas as $m) {
        $st = strtolower((string)($m['stmatricula'] ?? ''));
        if (in_array($st, ['cancelada', 'encerrada', 'trancada', 'inativa'], true)) continue;
        $venc = substr((string)($m['dtvencimento'] ?? ''), 0, 10);
        if ($venc === '') continue;
        if (intusMatriculaEhTeste($m)) {
            $ini = substr((string)($m['dtinicio'] ?? ''), 0, 10);
            if ($venc >= $hoje && ($ini === '' || $ini <= $hoje)) $teste = true;
        } elseif (strtoupper((string)($m['stpgto'] ?? '')) === 'S' && $venc >= $limite) {
            $plano = true;
        } elseif ($venc >= $hoje) {
            $ini = substr((string)($m['dtinicio'] ?? ''), 0, 10);
            $zero = isset($m['vlpagar']) && (float)$m['vlpagar'] - (float)($m['vldesconto'] ?? 0) <= 0;
            $recente = $ini !== '' && $ini <= $hoje && $ini >= date('Y-m-d', strtotime($hoje . ' -' . INTUS_COBRANCA_FOLGA_DIAS . ' days'));
            if ($recente || $zero) $plano = true;
        }
    }
    return ['teste_ativo' => $teste, 'plano_ativo' => $plano, 'so_teste' => $teste && !$plano];
}

function intusPlanoAluno(PDO $pdo, int $idatleta, ?string $hoje = null): array
{
    $hoje = $hoje ?? date('Y-m-d');
    $vazio = ['teste_ativo' => false, 'plano_ativo' => false, 'so_teste' => false];
    if ($idatleta <= 0) return $vazio;
    try {
        // SELECT * de propósito: tipo_plano e stmatricula nasceram depois da tabela e podem faltar num banco antigo; a ausência não pode virar "sem bloqueio".
        $st = $pdo->prepare('SELECT * FROM intus_mensalidade WHERE idatleta = ?');
        $st->execute([$idatleta]);
        return intusPlanoAlunoDeLinhas($st->fetchAll(PDO::FETCH_ASSOC), $hoje);
    } catch (Throwable $e) {
        return $vazio;   // na dúvida (banco fora, tabela ausente) não bloqueia ninguém
    }
}

const INTUS_MSG_RESTRITO = 'Seu acesso para publicar e comentar no Feed está limitado no momento. Treinos e cardios continuam sendo registrados normalmente. Em caso de dúvida, fale com a equipe.';

/** RESTRIÇÃO MANUAL (20/11/2026): a equipe pode limitar um aluno ativo (aba Cadastro, "Limitar Feed"). Ele continua treinando e pontuando, mas não publica nem comenta. */
function intusAlunoRestritoFeed(PDO $pdo, int $idatleta): bool
{
    if ($idatleta <= 0) return false;
    try {
        foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
            try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); } catch (Throwable $e) { continue; }
            $st = $pdo->prepare("SELECT feed_restrito FROM `$t` WHERE idatleta = ?");
            $st->execute([$idatleta]);
            return strtoupper((string)$st->fetchColumn()) === 'S';
        }
        return false;
    } catch (Throwable $e) {
        return false;   // coluna ausente ou banco fora: na dúvida não limita ninguém
    }
}

/** Responde 403 e encerra se o aluno não tem plano ativo ou foi limitado pela equipe. $ctx = contexto de autenticação (is_aluno, idatleta). */
function intusExigirPlanoParaPublicar(PDO $pdo, array $ctx): void
{
    if (empty($ctx['is_aluno'])) return;
    $id = (int)($ctx['idatleta'] ?? $ctx['idusuario'] ?? 0);
    if (intusAlunoRestritoFeed($pdo, $id)) {
        http_response_code(403);
        echo json_encode(['error' => INTUS_MSG_RESTRITO, 'codigo' => 'restrito'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($id <= 0) return;
    try {
        $st = $pdo->prepare('SELECT * FROM intus_mensalidade WHERE idatleta = ?');
        $st->execute([$id]);
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return;   // não deu para consultar: não barra ninguém
    }
    $p = intusPlanoAlunoDeLinhas($linhas, date('Y-m-d'));
    if (!$p['plano_ativo']) {
        http_response_code(403);
        echo json_encode($p['so_teste'] ? ['error' => INTUS_MSG_SO_TESTE, 'codigo' => 'so_teste'] : ['error' => INTUS_MSG_SEM_PLANO, 'codigo' => 'sem_plano'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
