<?php
/**
 * Matrícula automática do checkout (app/api/_matricula_auto.php): paridade com API.gerarParcelas, aluno novo e aluno que já existe, Pix, cartão parcelado e falhas.
 * Rodar:  php testes/matricula_auto.php   (o arquivo matricula_auto_paridade.json vem de `node testes/matricula_auto.js gravar`)
 */
require_once __DIR__ . '/../app/api/_matricula_auto.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, senha TEXT, telefone TEXT, cpf TEXT, origem TEXT, dt_cadastro TEXT, ranking_optin TEXT, stativo TEXT, codacesso TEXT)");
    $pdo->exec("CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, dshistorico TEXT, dtinicio TEXT, dtvencimento TEXT NOT NULL, vlpagar REAL DEFAULT 0, vldesconto REAL DEFAULT 0, vljuro REAL DEFAULT 0, stpgto TEXT DEFAULT 'N', dtpagamento TEXT, vlpagamento REAL, dspagamento TEXT, professor TEXT, tipo_plano TEXT, stmatricula TEXT DEFAULT 'ativa', idmatricula_origem INTEGER, parcela_num INTEGER, parcela_total INTEGER, forma_pgto TEXT, recorrencia TEXT)");
    return $pdo;
}

// ── Paridade com o api.js ──
$fx = json_decode(file_get_contents(__DIR__ . '/matricula_auto_paridade.json'), true);
foreach ($fx as $c) {
    $php = ima_gerar_parcelas($c['inicio'], $c['meses'], $c['parcelas'], (float)$c['valor']);
    confere('mesma série do api.js: ' . $c['inicio'] . ' ' . $c['meses'] . 'm em ' . $c['parcelas'] . 'x R$ ' . $c['valor'], json_encode($c['linhas']), json_encode($php));
}
confere('31/01 + 1 mês = 28/02 (mês curto)', '2027-02-28', ima_somar_meses('2027-01-31', 1));
confere('31/08 + 6 meses = 28/02', '2028-02-29', ima_somar_meses('2027-08-31', 6));
confere('soma de 12 meses', '2027-11-13', ima_somar_meses('2026-11-13', 12));

$ped = function ($extra = []) { return array_merge(['produto' => 'treino', 'periodo' => 'tri', 'meses' => 3, 'nome' => 'Ana Lima', 'email' => 'Ana.Lima@Exemplo.com', 'telefone' => '5545999990002', 'cpf' => '52998224725', 'forma' => 'pix', 'parcelas' => 1, 'valor' => 897.0], $extra); };

// ── Aluno novo, Pix ──
$pdo = banco();
$r = ima_matricular($pdo, $ped());
$a = $pdo->query("SELECT * FROM atleta")->fetch(); $m = $pdo->query("SELECT * FROM intus_mensalidade ORDER BY idmensalidade")->fetchAll();
confere('aluno novo: criado com e-mail em minúsculas e origem professor', [true, true, 'ana.lima@exemplo.com', 'professor', 'S'], [$r['ok'], $r['novo_aluno'], $a['email'], $a['origem'], $a['ranking_optin']]);
confere('Pix trimestral: ciclo pago e o seguinte em aberto, ambos trimestrais', [2, 'S', 'N', 'trimestral', 'trimestral', 'Treino'], [count($m), $m[0]['stpgto'], $m[1]['stpgto'], $m[0]['recorrencia'], $m[1]['recorrencia'], $m[0]['tipo_plano']]);
confere('datas: começa hoje, vence em 3 meses, o seguinte vence 3 meses depois', [ima_hoje(), ima_somar_meses(ima_hoje(), 3), ima_somar_meses(ima_hoje(), 6)], [$m[0]['dtinicio'], $m[0]['dtvencimento'], $m[1]['dtvencimento']]);
confere('o ciclo seguinte aponta para o primeiro e não tem pagamento', [(int)$m[0]['idmensalidade'], null, null], [(int)$m[1]['idmatricula_origem'], $m[1]['dtpagamento'], $m[1]['vlpagamento']]);
confere('o aviso fala do ciclo seguinte', true, (bool)array_filter($r['avisos'], function ($t) { return strpos($t, 'Ciclo seguinte') !== false; }));

// ── Mesmo aluno compra de novo: começa no fim do que já está pago e avisa ──
$r2 = ima_matricular($pdo, $ped(['produto' => 'dieta', 'periodo' => 'sem', 'meses' => 6, 'valor' => 1689.0]));
$m2 = $pdo->query("SELECT * FROM intus_mensalidade WHERE idmensalidade > " . (int)$m[1]['idmensalidade'] . " ORDER BY idmensalidade")->fetchAll();
confere('aluno existente: não duplica o cadastro', [false, 1], [$r2['novo_aluno'], (int)$pdo->query("SELECT COUNT(*) FROM atleta")->fetchColumn()]);
confere('o novo ciclo começa no fim do que já estava pago', $m[0]['dtvencimento'], $m2[0]['dtinicio']);
confere('o aviso diz que o aluno já tinha matrícula', true, (bool)array_filter($r2['avisos'], function ($t) { return strpos($t, 'JÁ TINHA') !== false; }));
$dep = $pdo->query("SELECT stpgto, dtvencimento FROM intus_mensalidade WHERE idmensalidade IN (" . (int)$m[0]['idmensalidade'] . ", " . (int)$m[1]['idmensalidade'] . ") ORDER BY idmensalidade")->fetchAll();
confere('nada do que existia foi alterado', [['S', $m[0]['dtvencimento']], ['N', $m[1]['dtvencimento']]], [[$dep[0]['stpgto'], $dep[0]['dtvencimento']], [$dep[1]['stpgto'], $dep[1]['dtvencimento']]]);

// ── Cartão parcelado ──
$pdo = banco();
$r = ima_matricular($pdo, $ped(['produto' => 'treino-dieta', 'periodo' => 'anual', 'meses' => 12, 'forma' => 'cartao', 'parcelas' => 12, 'valor' => 4597.0, 'email' => 'bia@exemplo.com']));
$m = $pdo->query("SELECT * FROM intus_mensalidade ORDER BY idmensalidade")->fetchAll();
confere('12x: 12 linhas, todas pagas na data da compra, sem recorrência', [true, 12, ['S'], [ima_hoje()], [null]], [$r['ok'], count($m), array_values(array_unique(array_column($m, 'stpgto'))), array_values(array_unique(array_column($m, 'dtpagamento'))), array_values(array_unique(array_column($m, 'recorrencia')))]);
confere('12x: soma dos valores pagos = valor total e cada linha é uma parcela', [4597.0, 'Combo (Treino + Dieta)', 'asaas_cartao'], [round(array_sum(array_column($m, 'vlpagamento')), 2), $m[0]['tipo_plano'], $m[0]['forma_pgto']]);
confere('12x: a última janela termina no fim do plano', ima_somar_meses(ima_hoje(), 12), $m[11]['dtvencimento']);
confere('12x: o aviso lembra que parcelado renova com ↻', true, (bool)array_filter($r['avisos'], function ($t) { return strpos($t, '↻') !== false; }));
// ── Cartão à vista (1x) = igual ao Pix, com forma de cartão ──
$pdo = banco();
$r = ima_matricular($pdo, $ped(['forma' => 'cartao', 'parcelas' => 1, 'email' => 'c@exemplo.com']));
confere('cartão à vista: um ciclo pago + o seguinte, forma asaas_cartao', ['asaas_cartao', 2], [$pdo->query("SELECT forma_pgto FROM intus_mensalidade ORDER BY idmensalidade LIMIT 1")->fetchColumn(), (int)$pdo->query("SELECT COUNT(*) FROM intus_mensalidade")->fetchColumn()]);
// ── Falhas: nunca lança e não deixa matrícula pela metade sem avisar ──
$vazio = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$r = ima_matricular($vazio, $ped());
confere('sem tabela de alunos: devolve erro em vez de lançar', false, $r['ok']);
$pdo = banco(); $pdo->exec("DROP TABLE intus_mensalidade");
$r = ima_matricular($pdo, $ped(['email' => 'f@exemplo.com']));
confere('falha ao gravar a matrícula: erro claro (a equipe é avisada pelo webhook)', false, $r['ok']);

echo $ok . " ok\n";
if ($falhas) { echo count($falhas) . " FALHA(S):\n  " . implode("\n  ", $falhas) . "\n"; exit(1); }
