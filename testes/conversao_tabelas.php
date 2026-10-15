<?php
// Conversão das tabelas antigas (app/api/_conversao_tabelas.php). A parte pura roda sempre; a parte de banco roda só com um MySQL/MariaDB de teste:
//   INTUS_TESTE_MYSQL="127.0.0.1:3307|root|SENHA" php -c <php.ini com pdo_mysql> testes/conversao_tabelas.php
// (cria e apaga um banco chamado conv_auto_teste; nunca toca em outro).
$ok = 0; $ruim = 0; $pulo = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }
require __DIR__ . '/../app/api/_conversao_tabelas.php';

eq('nome de tabela comum é aceito', conversaoNomeValido('atleta') && conversaoNomeValido('intus_sessao') && conversaoNomeValido('treino_letra_config'));
eq('nome com ponto e vírgula, espaço, crase ou aspas é recusado', !conversaoNomeValido('atleta; DROP TABLE atleta') && !conversaoNomeValido('a b') && !conversaoNomeValido('a`b') && !conversaoNomeValido("a'b") && !conversaoNomeValido(''));
$cat = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/catalogo.php'));
eq('a rota é só do administrador e exige backup de menos de 24 h', strpos($cat, "'seguranca_config', 'conversao_tabelas']") !== false && strpos($cat, '$horas > 24') !== false && strpos($cat, "action === 'conversao_tabelas'") !== false);
$lib = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/_conversao_tabelas.php'));
eq('a rotina nunca usa DROP nem DELETE nem TRUNCATE', !preg_match('/\b(DROP\s+TABLE|DELETE\s+FROM|TRUNCATE)\b/i', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $lib)));

$cfg = getenv('INTUS_TESTE_MYSQL');
if (!$cfg || !extension_loaded('pdo_mysql')) {
    echo "  (sem INTUS_TESTE_MYSQL ou sem pdo_mysql: testes com banco pulados)\n";
} else {
    [$hp, $u, $pw] = explode('|', $cfg) + [null, null, null];
    [$h, $po] = array_pad(explode(':', $hp), 2, '3306');
    $adm = new PDO("mysql:host=$h;port=$po;charset=utf8mb4", $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $adm->exec('DROP DATABASE IF EXISTS conv_auto_teste'); $adm->exec('CREATE DATABASE conv_auto_teste CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $p = new PDO("mysql:host=$h;port=$po;dbname=conv_auto_teste;charset=utf8mb4", $u, $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $p->exec("CREATE TABLE velha (id INT AUTO_INCREMENT PRIMARY KEY, nome VARCHAR(80), obs TEXT, nasc DATE NULL, valor DECIMAL(8,2) NULL, bin BLOB NULL, INDEX(nome)) ENGINE=MyISAM DEFAULT CHARSET=latin1");
    $p->exec("CREATE TABLE moderna (id INT PRIMARY KEY, nome VARCHAR(80)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $ins = $p->prepare("INSERT INTO velha (nome, obs, nasc, valor, bin) VALUES (?,?,?,?,?)");
    foreach ([["José da Conceição", "açúcar, pão e coração; 'aspas' \"duplas\" \\ barra", '1990-05-17', 61.5, "\x00\x01\xff"], ["Ana", null, null, null, null], ["Barão ç", str_repeat('x', 5000), '2001-01-01', 80.25, null]] as $r) $ins->execute($r);
    $p->exec("DELETE FROM velha WHERE id = 2"); $p->exec("INSERT INTO velha (nome) VALUES ('Quarta')"); $p->exec("DELETE FROM velha WHERE nome = 'Quarta'");   // contador de AUTO_INCREMENT fica à frente do maior id
    $ai0 = (int)$p->query("SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'velha'")->fetchColumn();
    $p->exec("INSERT INTO moderna VALUES (1, 'x')");

    $lista = array_column(conversaoListar($p), 'tabela');
    eq('lista só a tabela antiga (a moderna e as cópias ficam de fora)', $lista === ['velha'], json_encode($lista));
    $imp0 = conversaoImpressao($p, 'velha');
    $r = conversaoConverter($p, 'velha');
    eq('converte a tabela antiga', $r['ok'] === true, json_encode($r));
    $info = $p->query("SELECT engine, table_collation, auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'velha'")->fetch(PDO::FETCH_ASSOC);
    eq('agora é InnoDB e utf8mb4_general_ci (a mesma collation das tabelas modernas)', $info['engine'] === 'InnoDB' && $info['table_collation'] === 'utf8mb4_general_ci', json_encode($info));
    eq('o conteúdo tem a mesma impressão digital (linhas, acentos, NULL, binário)', conversaoImpressao($p, 'velha') === $imp0);
    eq('o contador de AUTO_INCREMENT não voltou atrás', (int)$info['auto_increment'] >= $ai0, "antes=$ai0 depois={$info['auto_increment']}");
    $nome = $p->query("SELECT nome FROM velha WHERE id = 1")->fetchColumn();
    eq('acentos ficaram certos (sem "Ã£")', $nome === 'José da Conceição', $nome);
    $p->exec("INSERT INTO velha (nome) VALUES ('Teste 💪🔥 Łukasz')");
    eq('agora aceita emoji e letra fora do latin1', $p->query("SELECT COUNT(*) FROM velha WHERE nome LIKE '%💪%'")->fetchColumn() == 1);
    eq('deixou a cópia de segurança com o conteúdo antigo', conversaoImpressao($p, 'conv_bak_velha') === $imp0 && count(conversaoCopias($p)) === 1);
    eq('comparar coluna convertida com coluna de tabela moderna não dá erro de collation', (bool)$p->query("SELECT COUNT(*) FROM velha v JOIN moderna m ON m.nome = v.nome")->execute());
    $r2 = conversaoConverter($p, 'velha');
    eq('converter de novo é recusado (não há mais o que converter)', $r2['ok'] === false);
    eq('depois de convertida, a lista de tabelas antigas fica vazia', conversaoListar($p) === []);
    $adm->exec('DROP DATABASE conv_auto_teste');
}
echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
