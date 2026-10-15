<?php
// Permissões de escrita da equipe conferidas NO SERVIDOR (app/api/_auth_context.php, intusPermissaoEscrita). Antes só o painel escondia o botão e um professor
// sem permissão gravava por chamada direta à API. A leitura de chaves é trocada por uma simulação; a regra é a real (extraída do arquivo).
//   php -c <php.ini> testes/permissoes.php
$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }

$cod = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/_auth_context.php'));
$i = strpos($cod, 'function intusPermissaoEscrita'); $j = strpos($cod, "\n}\n", $i);
eval(substr($cod, $i, $j - $i + 3));
$GLOBALS['perms'] = [];
function usuarioTemAlgumaPermissao($pdo, $uid, array $chaves) { foreach ($chaves as $c) if (!empty($GLOBALS['perms'][$uid][$c])) return true; return false; }
$pdo = new PDO("sqlite::memory:");
function pode($ctx, $pref, $m) { global $pdo; return intusPermissaoEscrita($pdo, $ctx, $pref, $m); }
$prof = ['idusuario' => 7, 'admin' => false, 'is_aluno' => false];

$GLOBALS['perms'][7] = [];
eq('professor SEM nenhuma permissão não cria (POST)', !pode($prof, 'al', 'POST'));
eq('professor SEM nenhuma permissão não edita (PUT)', !pode($prof, 'al', 'PUT'));
eq('professor SEM nenhuma permissão não exclui (DELETE)', !pode($prof, 'al', 'DELETE'));
eq('leitura (GET) nunca é bloqueada aqui', pode($prof, 'al', 'GET'));

$GLOBALS['perms'][7] = ['al_ver' => true];
eq('só "ver" não grava', !pode($prof, 'al', 'POST') && !pode($prof, 'al', 'PUT') && !pode($prof, 'al', 'DELETE'));

$GLOBALS['perms'][7] = ['al_incluir' => true];
eq('"incluir" libera POST e PUT (várias telas gravam com a chave vizinha)', pode($prof, 'al', 'POST') && pode($prof, 'al', 'PUT'));
eq('"incluir" NÃO libera excluir', !pode($prof, 'al', 'DELETE'));

$GLOBALS['perms'][7] = ['al_editar' => true];
eq('"editar" libera PUT e POST', pode($prof, 'al', 'PUT') && pode($prof, 'al', 'POST'));
eq('"editar" NÃO libera excluir', !pode($prof, 'al', 'DELETE'));

$GLOBALS['perms'][7] = ['al_excluir' => true];
eq('"excluir" libera DELETE e só ele', pode($prof, 'al', 'DELETE') && !pode($prof, 'al', 'POST') && !pode($prof, 'al', 'PUT'));

$GLOBALS['perms'][7] = ['nt_editar' => true];
eq('permissão de OUTRA área não vale (nt_editar não grava alongamento)', !pode($prof, 'al', 'PUT'));
eq('...e vale na própria área', pode($prof, 'nt', 'PUT'));

$GLOBALS['perms'][7] = ['pg_editar' => true];
eq('matrículas/pagamentos: qualquer das duas áreas libera (pg_editar grava mensalidade)', pode($prof, ['mt', 'pg'], 'PUT'));
$GLOBALS['perms'][7] = ['mt_incluir' => true];
eq('matrículas/pagamentos: mt_incluir grava mensalidade', pode($prof, ['mt', 'pg'], 'POST'));
$GLOBALS['perms'][7] = ['at_editar' => true];
eq('matrículas/pagamentos: at_editar (clientes) NÃO conta', !pode($prof, ['mt', 'pg'], 'POST'));

$GLOBALS['perms'][7] = [];
eq('administrador sempre pode', pode(['idusuario' => 1, 'admin' => true, 'is_aluno' => false], 'al', 'DELETE'));
eq('aluno não passa por esta regra (tem travas próprias)', pode(['idusuario' => 9, 'admin' => false, 'is_aluno' => true], 'al', 'POST'));
eq('sem identidade (id 0) é negado', !pode(['idusuario' => 0, 'admin' => false, 'is_aluno' => false], 'al', 'POST'));

// Estrutural: cada endpoint continua chamando a regra (apagar a chamada é acusado)
$ar = fn($p) => str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/' . $p));
eq('treinos.php confere tr_/ex_ antes de gravar', strpos($ar('treinos.php'), "intusExigirPermissaoEscrita(\$pdo, \$_authCtx, \$_permTr[\$action], \$method)") !== false);
eq('mensalidades.php confere mt_/pg_ em POST e PUT', strpos($ar('mensalidades.php'), "intusExigirPermissaoEscrita(\$pdo, \$_authCtx, ['mt', 'pg'], \$method)") !== false);
eq('catalogo.php confere al/av/nt em alongamentos, avaliações, nutrição e alimentos', strpos($ar('catalogo.php'), '$_PERM_ESCRITA_CAT[$action], $_mEf)') !== false);
eq('atletas.php confere at_editar no PUT e at_incluir no POST', strpos($ar('atletas.php'), "'at_editar')") !== false && strpos($ar('atletas.php'), "'at_incluir')") !== false);
eq('o PUT de clientes só abre exceção para notas, origem e bloqueio', strpos($ar('atletas.php'), "['notas_professor', 'origem']") !== false && strpos($ar('atletas.php'), "['stbloqueio']") !== false);

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
