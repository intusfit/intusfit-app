<?php
// Teste grátis: a conta nova recebe uma cópia FIEL das fichas ativas do aluno-modelo (masculino ou feminino), rodando o
// _teste_gratis.php de verdade em PHP, com SQLite. Confere ordem das divisões, técnicas por série, unilateral, ordem dos
// exercícios, datas do teste, escolha pelo gênero, ficha inativa que não vai e modelo ausente que não derruba o cadastro.
//   php -c <php.ini com pdo_sqlite, sqlite3> testes/teste_gratis_ficha.php
require __DIR__ . '/../app/api/_teste_gratis.php';
$falhas = 0;
function ok($c, $m) { global $falhas; if (!$c) { $falhas++; echo "FALHA $m\n"; } else echo "ok    $m\n"; }
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT)");
    $pdo->exec("CREATE TABLE intus_ficha (idficha INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INTEGER NOT NULL, nmficha TEXT NOT NULL DEFAULT 'Ficha', dtinicio TEXT, dtfim TEXT, observacao TEXT, ativo INTEGER NOT NULL DEFAULT 1, alongamentos TEXT, div_nomes TEXT, div_ordem TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE intus_treino (idtreino INTEGER PRIMARY KEY AUTOINCREMENT, idficha INTEGER NOT NULL, idexercicio INTEGER NOT NULL, nmexercicio TEXT NOT NULL DEFAULT '', divisao TEXT NOT NULL DEFAULT 'A', qtdserie INTEGER NOT NULL DEFAULT 3, qtdrepeticao INTEGER NOT NULL DEFAULT 12, repeticoes TEXT, tempopausa INTEGER NOT NULL DEFAULT 60, intervalo TEXT, tecnicas TEXT, metodo TEXT, observacao TEXT, substitutos TEXT, unilateral INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("INSERT INTO atleta (nome,email) VALUES ('Modelo Masculino','modelo.masculino@intusfit.local'),('Modelo Feminino','modelo.feminino@intusfit.local'),('Aluno Novo','novo@x.com')");
    // masculino (id 1): ficha ativa com divisões fora da ordem alfabética, técnicas por série e unilateral; mais uma ficha inativa
    $pdo->exec("INSERT INTO intus_ficha (idatleta,nmficha,observacao,ativo,alongamentos,div_nomes,div_ordem) VALUES (1,'Treino ABC','Foco em hipertrofia',1,'[{\"nome\":\"Peitoral\"}]','{\"A\":\"Peito\",\"B\":\"Costas\"}','[\"B\",\"A\"]'),(1,'Ficha antiga',NULL,0,NULL,NULL,NULL)");
    $pdo->exec("INSERT INTO intus_treino (idficha,idexercicio,nmexercicio,divisao,qtdserie,repeticoes,tempopausa,intervalo,tecnicas,metodo,observacao,substitutos,unilateral) VALUES
      (1,10,'Supino reto','A',4,'12/10/8/8',90,'01:30/01:30/01:00/01:00','/ /drop','','Cotovelos a 45','[11,12]',0),
      (1,11,'Crucifixo','A',3,'12',60,'01:00','','super','','',0),
      (1,20,'Remada curvada','B',4,'10',90,'01:30','','','',NULL,1),
      (2,99,'Exercício da ficha inativa','A',3,'10',60,'01:00','','','',NULL,0)");
    // feminino (id 2): outra ficha
    $pdo->exec("INSERT INTO intus_ficha (idatleta,nmficha,ativo,div_ordem) VALUES (2,'Treino Glúteos',1,'[\"A\",\"B\"]')");
    $pdo->exec("INSERT INTO intus_treino (idficha,idexercicio,nmexercicio,divisao,qtdserie,repeticoes,tecnicas,unilateral) VALUES (3,30,'Hip thrust','A',4,'12','drop',0),(3,31,'Afundo','B',3,'10','',1)");
    return $pdo;
}
$pdo = banco();
$r = intusCopiarFichasModelo($pdo, 'atleta', 'idatleta', 'email', 3, 'M', '2026-10-29', '2026-11-01');
ok($r['modelo'] && $r['fichas'] === 1 && $r['treinos'] === 3, 'masculino: 1 ficha ativa e 3 exercícios copiados (' . json_encode($r) . ')');
$f = $pdo->query("SELECT * FROM intus_ficha WHERE idatleta = 3")->fetch(PDO::FETCH_ASSOC);
ok($f && $f['nmficha'] === 'Treino ABC' && (int)$f['ativo'] === 1, 'a ficha chega ativa com o nome do modelo');
ok($f['dtinicio'] === '2026-10-29' && $f['dtfim'] === '2026-11-01', 'datas = período do teste');
ok($f['div_ordem'] === '["B","A"]', 'ordem das divisões copiada (antes ficava de fora): ' . $f['div_ordem']);
ok($f['div_nomes'] === '{"A":"Peito","B":"Costas"}' && $f['alongamentos'] === '[{"nome":"Peitoral"}]' && $f['observacao'] === 'Foco em hipertrofia', 'nomes das divisões, alongamentos e observação copiados');
$t = $pdo->query("SELECT * FROM intus_treino WHERE idficha = " . (int)$f['idficha'] . " ORDER BY idtreino")->fetchAll(PDO::FETCH_ASSOC);
ok(array_column($t, 'nmexercicio') === ['Supino reto', 'Crucifixo', 'Remada curvada'], 'exercícios na mesma ordem do modelo');
ok($t[0]['tecnicas'] === '/ /drop', 'técnicas por série copiadas (antes ficavam de fora)');
ok((int)$t[2]['unilateral'] === 1 && (int)$t[0]['unilateral'] === 0, 'unilateral copiado (antes ficava de fora)');
ok($t[0]['repeticoes'] === '12/10/8/8' && $t[0]['intervalo'] === '01:30/01:30/01:00/01:00' && (int)$t[0]['qtdserie'] === 4 && $t[0]['substitutos'] === '[11,12]' && $t[0]['observacao'] === 'Cotovelos a 45' && $t[1]['metodo'] === 'super', 'séries, repetições, intervalos, substitutos, observação e método iguais');
ok((int)$pdo->query("SELECT COUNT(*) FROM intus_treino WHERE nmexercicio LIKE '%inativa%' AND idficha <> 2")->fetchColumn() === 0, 'ficha inativa do modelo não vai');
ok((int)$pdo->query("SELECT COUNT(*) FROM intus_ficha WHERE idatleta = 1")->fetchColumn() === 2, 'o modelo continua com as fichas dele');
// feminino
$r2 = intusCopiarFichasModelo($pdo, 'atleta', 'idatleta', 'email', 3, 'F', '2026-10-29', '2026-11-01');
ok($r2['fichas'] === 1 && $r2['treinos'] === 2, 'feminino: usa a ficha do modelo feminino');
ok($pdo->query("SELECT COUNT(*) FROM intus_ficha WHERE idatleta = 3 AND nmficha = 'Treino Glúteos'")->fetchColumn() == 1, 'a ficha feminina é a do modelo feminino');
// sem gênero vale o masculino
$pdo2 = banco();
intusCopiarFichasModelo($pdo2, 'atleta', 'idatleta', 'email', 3, '', '2026-10-29', '2026-11-01');
ok($pdo2->query("SELECT nmficha FROM intus_ficha WHERE idatleta = 3")->fetchColumn() === 'Treino ABC', 'sem gênero informado vale o modelo masculino');
// modelo ausente ou sem ficha ativa: não derruba o cadastro e avisa no log
$pdo3 = banco(); $pdo3->exec("DELETE FROM atleta WHERE email = 'modelo.feminino@intusfit.local'");
$errLog = tempnam(sys_get_temp_dir(), 'tg'); ini_set('error_log', $errLog);
$r3 = intusCopiarFichasModelo($pdo3, 'atleta', 'idatleta', 'email', 3, 'F', '2026-10-29', '2026-11-01');
ok($r3 === ['modelo' => false, 'fichas' => 0, 'treinos' => 0], 'sem aluno-modelo: nada copiado e sem exceção');
ok(strpos((string)file_get_contents($errLog), 'modelo.feminino@intusfit.local nao encontrado') !== false, 'o motivo vai para o log do servidor');
$pdo4 = banco(); $pdo4->exec("UPDATE intus_ficha SET ativo = 0 WHERE idatleta = 1");
$r4 = intusCopiarFichasModelo($pdo4, 'atleta', 'idatleta', 'email', 3, 'M', '2026-10-29', '2026-11-01');
ok($r4['modelo'] === true && $r4['fichas'] === 0, 'modelo sem ficha ativa: nada copiado e sem exceção');
ok(strpos((string)file_get_contents($errLog), 'sem ficha ativa') !== false, 'e avisa no log');
echo $falhas ? "FALHAS: $falhas\n" : "TUDO OK\n";
exit($falhas ? 1 : 0);
