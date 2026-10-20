<?php
/**
 * CDD: recuperação da senha do administrador. Biblioteca interna (o "_" no nome bloqueia o acesso direto), usada só por admin/cdd-recuperar.php,
 * que fica atrás do login do admin da Intus (outro login, outra senha). Não há "esqueci a senha" por e-mail para a equipe do CDD: este é o caminho.
 * A senha nova é digitada por quem usa a página; nada aqui a guarda, mostra ou envia. Só grava o hash (bcrypt), como o resto do CDD.
 */

/** Contas de administrador do CDD (sem hash nem dados de aluna). */
function cddRecuperarAdmins(PDO $pdo): array {
    return $pdo->query("SELECT idaluna, nome, email, status, ultimo_acesso FROM cdd_aluna WHERE papel = 'admin' ORDER BY idaluna")->fetchAll();
}

/**
 * Troca a senha de uma conta de administrador.
 * @return array [bool ok, string mensagem]
 */
function cddRecuperarTrocarSenha(PDO $pdo, int $idaluna, string $nova, string $confirma, bool $encerrarSessoes): array {
    if ($nova !== $confirma) return [false, 'A confirmação não é igual à senha nova.'];
    if (strlen($nova) < 8) return [false, 'A senha precisa ter pelo menos 8 caracteres.'];
    if (strlen($nova) > 200 || preg_match('/[\r\n\0]/', $nova)) return [false, 'Senha inválida (muito longa ou com quebra de linha).'];
    $st = $pdo->prepare("SELECT idaluna, papel FROM cdd_aluna WHERE idaluna = ?"); $st->execute([$idaluna]); $u = $st->fetch();
    if (!$u || $u['papel'] !== 'admin') return [false, 'Essa conta não é de administrador do CDD.'];
    $pdo->prepare('UPDATE cdd_aluna SET senha_hash = ? WHERE idaluna = ?')->execute([password_hash($nova, PASSWORD_BCRYPT), $idaluna]);
    $msg = 'Senha trocada.';
    if ($encerrarSessoes) { $pdo->prepare('DELETE FROM cdd_sessao WHERE idaluna = ?')->execute([$idaluna]); $msg .= ' As sessões abertas dessa conta foram encerradas.'; }
    return [true, $msg];
}
