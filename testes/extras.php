<?php
/**
 * Extras (app/api/_extras.php e extras.php): módulos e conteúdos bônus com visibilidade por regra, em SQLite na memória.
 *   php -c <ini com pdo_sqlite e mbstring> testes/extras.php
 */
require_once __DIR__ . '/../app/api/_extras.php';

$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function banco(): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('CREATE TABLE atleta (idatleta INTEGER PRIMARY KEY, nome TEXT, email TEXT, stbloqueio TEXT)');
    $pdo->exec('CREATE TABLE intus_mensalidade (idmensalidade INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, dshistorico TEXT, tipo_plano TEXT, dtinicio TEXT, dtvencimento TEXT, stpgto TEXT, stmatricula TEXT DEFAULT \'ativa\')');
    $pdo->exec('CREATE TABLE intus_plano_nutricional (idplano INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, ativo INT)');
    return $pdo;
}
$hoje = '2026-10-21';
$L = fn($hist, $tipo = null, $venc = '2026-11-20', $pg = 'S', $st = 'ativa', $ini = '2026-10-20') => ['dshistorico' => $hist, 'tipo_plano' => $tipo, 'dtinicio' => $ini, 'dtvencimento' => $venc, 'stpgto' => $pg, 'stmatricula' => $st];

echo "\n══ Quem é treino, nutrição, combo e ativo ══\n";
$p = fn(array $linhas) => extras_perfil_de_linhas($linhas, $hoje);
eq('Personal 4x pago: ativo e treino', $p([$L('Personal 4x')]), ['ativo' => true, 'treino' => true, 'nutricao' => false, 'teste' => false]);
eq('plano só de nutrição: ativo e nutrição, NÃO treino', $p([$L('Plano Nutrição Mensal')]), ['ativo' => true, 'treino' => false, 'nutricao' => true, 'teste' => false]);
eq('combo (tipo de plano): os dois', $p([$L('Consultoria Recorrente Mensal Especial - Intus', 'Combo (Treino + Dieta)')]), ['ativo' => true, 'treino' => true, 'nutricao' => true, 'teste' => false]);
eq('"Treino + Dieta" no nome: os dois', $p([$L('Treino + Dieta — Trimestral')]), ['ativo' => true, 'treino' => true, 'nutricao' => true, 'teste' => false]);
eq('"Consultoria Semestral Combo": os dois', $p([$L('Consultoria Semestral Combo - Intus')]), ['ativo' => true, 'treino' => true, 'nutricao' => true, 'teste' => false]);
eq('dois planos separados (treino e nutrição) também viram os dois', $p([$L('Personal 3x'), $L('Nutrição Mensal')]), ['ativo' => true, 'treino' => true, 'nutricao' => true, 'teste' => false]);
eq('Bonificado (sem palavra de nutrição) conta como treino', $p([$L('Bonificado')])['treino'], true);
eq('plano não pago não é ativo', $p([$L('Personal 4x', null, '2026-11-20', 'N')])['ativo'], false);
eq('plano vencido há 3 dias (carência) ainda é ativo', $p([$L('Personal 4x', null, '2026-10-18')])['ativo'], true);
eq('plano vencido há 6 dias não é mais ativo', $p([$L('Personal 4x', null, '2026-10-15')])['ativo'], false);
eq('plano cancelado não é ativo', $p([$L('Personal 4x', null, '2026-11-20', 'S', 'cancelada')])['ativo'], false);
eq('plano trancado não é ativo', $p([$L('Personal 4x', null, '2026-11-20', 'S', 'trancada')])['ativo'], false);
eq('teste grátis vigente: não é "ativo", só "teste"', $p([$L('Teste Grátis (3 dias)', null, '2026-10-23', 'S', 'ativa', '2026-10-20')]), ['ativo' => false, 'treino' => false, 'nutricao' => false, 'teste' => true]);
eq('cortesia de verdade (plano de presente) é ativo', $p([$L('Cortesia Premium 30 dias')])['ativo'], true);
eq('sem matrícula nenhuma: tudo falso', $p([]), ['ativo' => false, 'treino' => false, 'nutricao' => false, 'teste' => false]);
$pdo = banco();
$pdo->exec("INSERT INTO intus_mensalidade (idatleta, dshistorico, dtinicio, dtvencimento, stpgto) VALUES (1, 'Personal 2x', '2026-10-20', '2026-11-20', 'S')");
eq('perfil direto do banco', extras_perfil_aluno($pdo, 1, $hoje)['treino'], true);
$pdo->exec('INSERT INTO intus_plano_nutricional (idatleta, ativo) VALUES (1, 1)');
eq('plano alimentar ativo também libera nutrição', extras_perfil_aluno($pdo, 1, $hoje)['nutricao'], true);
eq('aluno sem nada: tudo falso', extras_perfil_aluno($pdo, 99, $hoje)['ativo'], false);
eq('banco sem a tabela não derruba', extras_perfil_aluno(new PDO('sqlite::memory:'), 5, $hoje)['ativo'], false);

echo "\n══ A regra de visibilidade ══\n";
$ativo = ['ativo' => true, 'treino' => true, 'nutricao' => false, 'teste' => false];
$nutri = ['ativo' => true, 'treino' => false, 'nutricao' => true, 'teste' => false];
$teste = ['ativo' => false, 'treino' => false, 'nutricao' => false, 'teste' => true];
$nada  = ['ativo' => false, 'treino' => false, 'nutricao' => false, 'teste' => false];
eq('todos: qualquer um, inclusive teste e sem plano', [extras_pode_ver(['modo' => 'todos'], $teste, 5), extras_pode_ver(['modo' => 'todos'], $nada, 5)], [true, true]);
eq('ativos: só plano pago em dia (nem o teste grátis)', [extras_pode_ver(['modo' => 'ativos'], $ativo, 5), extras_pode_ver(['modo' => 'ativos'], $teste, 5), extras_pode_ver(['modo' => 'ativos'], $nada, 5)], [true, false, false]);
eq('nutrição: quem tem nutrição ou combo, não o aluno só de treino', [extras_pode_ver(['modo' => 'nutricao'], $nutri, 5), extras_pode_ver(['modo' => 'nutricao'], $ativo, 5)], [true, false]);
eq('treino: quem tem treino ou combo, não o aluno só de nutrição', [extras_pode_ver(['modo' => 'treino'], $ativo, 5), extras_pode_ver(['modo' => 'treino'], $nutri, 5)], [true, false]);
eq('selecionados: só quem está na lista, mesmo sem plano', [extras_pode_ver(['modo' => 'selecionados', 'ids' => [7, 9]], $nada, 9), extras_pode_ver(['modo' => 'selecionados', 'ids' => [7, 9]], $ativo, 8)], [true, false]);
eq('selecionados com lista vazia não libera ninguém', extras_pode_ver(['modo' => 'selecionados', 'ids' => []], $ativo, 5), false);
eq('modo desconhecido nega', extras_pode_ver(['modo' => 'qualquer'], $ativo, 5), false);

echo "\n══ Limpeza do que vem da tela ══\n";
eq('só https é aceito', [extras_url('https://youtu.be/abc'), extras_url('http://x.com'), extras_url('javascript:alert(1)'), extras_url('data:text/html,x'), extras_url('https://a b.com')], ['https://youtu.be/abc', '', '', '', '']);
try { extras_limpar_item(['titulo' => 'Aula', 'url' => 'http://x.com']); $r = 'aceitou'; } catch (InvalidArgumentException $e) { $r = 'recusou'; }
eq('link http é recusado com aviso (não some em silêncio)', $r, 'recusou');
try { extras_limpar_item(['titulo' => '  ']); $r = 'aceitou'; } catch (InvalidArgumentException $e) { $r = 'recusou'; }
eq('conteúdo sem título é recusado', $r, 'recusou');
$it = extras_limpar_item(['titulo' => 'Live de terça', 'tipo' => 'live', 'url' => 'https://youtu.be/xyz', 'data' => '2026-10-27 19:30', 'publicado' => 1]);
eq('live guarda a data no formato com T', [$it['tipo'], $it['data'], $it['publicado']], ['live', '2026-10-27T19:30', true]);
eq('tipo inválido vira vídeo; data inválida some', [extras_limpar_item(['titulo' => 'x', 'tipo' => 'hack', 'data' => 'amanha'])['tipo'], extras_limpar_item(['titulo' => 'x', 'data' => 'amanha'])['data']], ['video', '']);
eq('conteúdo novo nasce com id e data de criação', [strlen($it['id']), preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $it['criado_em'])], [10, 1]);
eq('conteúdo sem regra própria herda a do módulo (null)', $it['visibilidade'], null);
eq('"herdar" também vira null', extras_limpar_item(['titulo' => 'x', 'visibilidade' => ['modo' => 'herdar']])['visibilidade'], null);
eq('regra própria é guardada', extras_limpar_item(['titulo' => 'x', 'visibilidade' => ['modo' => 'nutricao']])['visibilidade'], ['modo' => 'nutricao', 'ids' => []]);
eq('lista de alunos só vale em "selecionados" e limpa lixo e repetidos', extras_limpar_visibilidade(['modo' => 'selecionados', 'ids' => [3, '3', 0, -1, 'abc', 5]]), ['modo' => 'selecionados', 'ids' => [3, 5]]);
eq('lista de alunos é ignorada nos outros modos', extras_limpar_visibilidade(['modo' => 'ativos', 'ids' => [3]]), ['modo' => 'ativos', 'ids' => []]);
$cat = extras_limpar_categoria(['titulo' => 'Cintura de Atleta', 'cor' => 'vermelho', 'visibilidade' => ['modo' => 'treino']]);
eq('módulo: emoji padrão, cor inválida cai no padrão, regra guardada', [$cat['emoji'], $cat['cor'], $cat['visibilidade']['modo'], $cat['publicado']], ['🎁', '#0ea5e9', 'treino', false]);
try { extras_limpar_categoria(['titulo' => 'x', 'capa' => 'ftp://x']); $r = 'aceitou'; } catch (InvalidArgumentException $e) { $r = 'recusou'; }
eq('capa que não é https é recusada', $r, 'recusou');

echo "\n══ O catálogo de fábrica ══\n";
$pdo = banco();
$d = extras_ler($pdo);
eq('vem com os 4 módulos do CDD, todos publicados para alunos ativos', [count($d['categorias']), array_column($d['categorias'], 'publicado'), array_unique(array_map(fn($c) => $c['visibilidade']['modo'], $d['categorias']))], [4, [true, true, true, true], ['ativos']]);
eq('na ordem pedida: cintura de atleta, treinos em casa, assista antes de treinar, lives e aulas exclusivas', array_column($d['categorias'], 'id'), ['cintura-de-atleta', 'treinos-em-casa', 'assista-antes-de-treinar', 'lives-e-aulas-exclusivas']);
eq('com 12, 5, 7 e 8 aulas', array_map('count', array_column($d['categorias'], 'itens')), [12, 5, 7, 8]);
eq('6 aulas entram como rascunho (as que o CDD já guardava assim)', array_sum(array_map(fn($c) => count(array_filter($c['itens'], fn($i) => !$i['publicado'])), $d['categorias'])), 6);
$rasc = []; foreach ($d['categorias'] as $c) foreach ($c['itens'] as $it) if (!$it['publicado']) $rasc[] = $it['titulo'];
eq('os rascunhos são os de tema sensível e os escondidos da aluna', array_map(fn($t) => (bool)preg_match('/Esteroides|Remédios|Molécula|cargas|Respiração|TUT/u', $t), $rasc), array_fill(0, 6, true));
eq('toda capa de módulo e de aula é https do próprio site', array_unique(array_merge(array_map(fn($c) => (bool)preg_match('#^https://intusfit\.com\.br/app/painel/extras/capas/#', $c['capa']), $d['categorias']), array_map(fn($i) => (bool)preg_match('#^https://intusfit\.com\.br/app/painel/extras/capas/#', $i['capa']), array_merge(...array_column($d['categorias'], 'itens'))))), [true]);
eq('todo conteúdo tem link do YouTube', array_unique(array_map(fn($i) => (bool)preg_match('#^https://www\.youtube\.com/watch\?v=[\w-]{11}$#', $i['url']), array_merge(...array_column($d['categorias'], 'itens')))), [true]);
eq('as capas existem como arquivo no site', array_unique(array_map(fn($u) => is_file(__DIR__ . '/../app/painel/extras/capas/' . basename(parse_url($u, PHP_URL_PATH))), array_merge(array_column($d['categorias'], 'capa'), array_column(array_merge(...array_column($d['categorias'], 'itens')), 'capa')))), [true]);
$todas = array_merge(...array_column($d['categorias'], 'itens'));
$ric = array_values(array_filter($todas, fn($i) => preg_match('/Rem[ée]dios para Emagrecer|Lipedema/u', $i['titulo'])));
eq('as aulas Remédios para Emagrecer e Lipedema dizem que são com o Prof. Ricardo', [count($ric), array_unique(array_column($ric, 'descricao'))], [2, ['Com o Prof. Ricardo.']]);
eq('e só essas duas têm essa descrição', count(array_filter($todas, fn($i) => strpos($i['descricao'], 'Ricardo') !== false)), 2);
$vis = fn($perfil, $id = 5) => array_map(fn($c) => [$c['id'], count($c['itens'])], extras_para_aluno($d, $perfil, $id));
eq('aluno ativo vê os 4 módulos só com as aulas publicadas (12, 5, 4 e 5)', $vis($ativo), [['cintura-de-atleta', 12], ['treinos-em-casa', 5], ['assista-antes-de-treinar', 4], ['lives-e-aulas-exclusivas', 5]]);
eq('aluno sem plano e aluno só no teste grátis não veem nada (a regra é "ativos")', [$vis($nada), $vis($teste)], [[], []]);
eq('o aluno recebe a capa de cada aula', array_unique(array_map(fn($c) => (bool)array_product(array_map(fn($i) => $i['capa'] !== '', $c['itens'])), extras_para_aluno($d, $ativo, 5))), [true]);
eq('nada foi gravado só por ler', (int)$pdo->query("SELECT COUNT(*) FROM intus_config")->fetchColumn(), 0);

echo "\n══ Criar, editar, ordenar e excluir ══\n";
extras_gravar($pdo, ['versao' => 1, 'categorias' => []]);   // parte de um catálogo vazio (a fábrica já traz os 4 módulos do CDD)
$m1 = extras_salvar_categoria($pdo, ['titulo' => 'Aulas ao vivo', 'emoji' => '🎥', 'publicado' => true, 'visibilidade' => ['modo' => 'todos']]);
$d = extras_ler($pdo);
eq('módulo novo entra no fim e o catálogo passa a ser o salvo', [count($d['categorias']), end($d['categorias'])['titulo'], !empty($d['salvo'])], [1, 'Aulas ao vivo', true]);
$m1b = extras_salvar_categoria($pdo, ['id' => $m1['id'], 'titulo' => 'Aulas ao vivo (novo nome)', 'visibilidade' => ['modo' => 'nutricao']]);
eq('editar mantém o id e os conteúdos', [$m1b['id'] === $m1['id'], $m1b['titulo']], [true, 'Aulas ao vivo (novo nome)']);
$i1 = extras_salvar_item($pdo, $m1['id'], ['titulo' => 'Aula 1', 'url' => 'https://youtu.be/a1', 'publicado' => true]);
$i2 = extras_salvar_item($pdo, $m1['id'], ['titulo' => 'Aula 2', 'url' => 'https://youtu.be/a2', 'publicado' => true]);
$i3 = extras_salvar_item($pdo, $m1['id'], ['titulo' => 'Aula 3', 'url' => 'https://youtu.be/a3', 'publicado' => false]);
$itens = fn() => array_column(array_values(array_filter(extras_ler($pdo)['categorias'], fn($c) => $c['id'] === $m1['id']))[0]['itens'], 'titulo');
eq('três conteúdos, na ordem em que entraram', $itens(), ['Aula 1', 'Aula 2', 'Aula 3']);
extras_salvar_item($pdo, $m1['id'], ['id' => $i2['id'], 'titulo' => 'Aula 2 corrigida', 'publicado' => true]);
eq('editar um conteúdo mantém a posição e a data de criação', [$itens(), extras_ler($pdo)['categorias'][0]['itens'][1]['criado_em'] === $i2['criado_em']], [['Aula 1', 'Aula 2 corrigida', 'Aula 3'], true]);
extras_reordenar($pdo, 'item', $m1['id'], [$i3['id'], $i1['id'], $i2['id']]);
eq('reordenar conteúdos', $itens(), ['Aula 3', 'Aula 1', 'Aula 2 corrigida']);
extras_reordenar($pdo, 'item', $m1['id'], [$i2['id']]);
eq('conteúdo que faltou na lista não some: vai para o fim', $itens(), ['Aula 2 corrigida', 'Aula 3', 'Aula 1']);
$m2 = extras_salvar_categoria($pdo, ['titulo' => 'Segundo']); $m3 = extras_salvar_categoria($pdo, ['titulo' => 'Terceiro']);
extras_reordenar($pdo, 'categoria', '', [$m3['id'], 'nao-existe', $m1['id']]);
eq('reordenar módulos (id desconhecido é ignorado, o resto segue)', array_column(extras_ler($pdo)['categorias'], 'id'), [$m3['id'], $m1['id'], $m2['id']]);
try { extras_reordenar($pdo, 'item', 'nao-existe', []); $r = 'aceitou'; } catch (InvalidArgumentException $e) { $r = 'recusou'; }
eq('ordenar conteúdos de módulo inexistente é recusado', $r, 'recusou');
eq('excluir um conteúdo', [extras_excluir_item($pdo, $m1['id'], $i3['id']), count($itens())], [true, 2]);
eq('excluir conteúdo que não existe devolve falso e não grava', extras_excluir_item($pdo, $m1['id'], 'zzz'), false);
try { extras_salvar_item($pdo, 'nao-existe', ['titulo' => 'x']); $r = 'aceitou'; } catch (InvalidArgumentException $e) { $r = 'recusou'; }
eq('conteúdo em módulo inexistente é recusado', $r, 'recusou');
eq('excluir um módulo leva os conteúdos dele', [extras_excluir_categoria($pdo, $m1['id']), in_array($m1['id'], array_column(extras_ler($pdo)['categorias'], 'id'), true)], [true, false]);
eq('excluir módulo que não existe devolve falso', extras_excluir_categoria($pdo, 'zzz'), false);

echo "\n══ O que o aluno recebe ══\n";
$pdo = banco();
extras_gravar($pdo, ['versao' => 1, 'categorias' => []]);
$mod = extras_salvar_categoria($pdo, ['titulo' => 'Treinos em casa', 'publicado' => true, 'visibilidade' => ['modo' => 'treino']]);
extras_salvar_item($pdo, $mod['id'], ['titulo' => 'Treino A', 'url' => 'https://youtu.be/ta', 'publicado' => true]);
extras_salvar_item($pdo, $mod['id'], ['titulo' => 'Rascunho', 'url' => 'https://youtu.be/rr', 'publicado' => false]);
extras_salvar_item($pdo, $mod['id'], ['titulo' => 'Só nutrição', 'url' => 'https://youtu.be/nn', 'publicado' => true, 'visibilidade' => ['modo' => 'nutricao']]);
extras_salvar_item($pdo, $mod['id'], ['titulo' => 'Só a Maria', 'url' => 'https://youtu.be/mm', 'publicado' => true, 'visibilidade' => ['modo' => 'selecionados', 'ids' => [42]]]);
$d = extras_ler($pdo);
$tit = fn($lista) => array_map(fn($c) => [$c['titulo'], array_column($c['itens'], 'titulo')], $lista);
eq('aluno de treino: vê o do módulo e nada mais', $tit(extras_para_aluno($d, $ativo, 7)), [['Treinos em casa', ['Treino A']]]);
eq('aluno de nutrição: o módulo (regra de treino) não vale, mas o conteúdo com regra própria sim', $tit(extras_para_aluno($d, $nutri, 8)), [['Treinos em casa', ['Só nutrição']]]);
eq('aluna escolhida: vê o conteúdo dela mesmo sem plano', $tit(extras_para_aluno($d, $nada, 42)), [['Treinos em casa', ['Só a Maria']]]);
eq('aluno sem plano e fora da lista: não vê nada (nem o módulo vazio)', extras_para_aluno($d, $nada, 99), []);
$json = json_encode(extras_para_aluno($d, $ativo, 7));
eq('a resposta do aluno NÃO carrega regra de acesso, lista de alunos nem rascunho', [strpos($json, 'visibilidade'), strpos($json, '"ids"'), strpos($json, 'Rascunho'), strpos($json, 'publicado')], [false, false, false, false]);
$mod2 = extras_salvar_categoria($pdo, ['titulo' => 'Escondido', 'publicado' => false, 'visibilidade' => ['modo' => 'todos']]);
extras_salvar_item($pdo, $mod2['id'], ['titulo' => 'X', 'publicado' => true]);
eq('módulo não publicado não aparece, mesmo com conteúdo publicado', count(extras_para_aluno(extras_ler($pdo), $ativo, 7)), 1);

echo "\n══ Quantos alunos cada regra alcança ══\n";
$pdo = banco();
foreach ([[1, 'a@x.com'], [2, 'b@x.com'], [3, 'c@x.com'], [4, 'd@x.com'], [5, 'modelo.feminino@intusfit.local']] as [$i, $em]) $pdo->exec("INSERT INTO atleta VALUES ($i, 'Aluno $i', '$em', " . ($i === 4 ? "'S'" : 'NULL') . ')');
$ins = $pdo->prepare('INSERT INTO intus_mensalidade (idatleta, dshistorico, tipo_plano, dtinicio, dtvencimento, stpgto) VALUES (?, ?, ?, ?, ?, ?)');
$ins->execute([1, 'Personal 4x', null, '2026-10-01', '2026-11-01', 'S']);
$ins->execute([2, 'Consultoria Recorrente', 'Combo (Treino + Dieta)', '2026-10-01', '2026-11-01', 'S']);
$ins->execute([3, 'Teste Grátis (3 dias)', null, '2026-10-20', '2026-10-23', 'S']);
eq('alcance: todos (sem bloqueado e sem aluno-modelo), ativos, nutrição, treino', extras_alcance($pdo, $hoje), ['todos' => 3, 'ativos' => 2, 'nutricao' => 1, 'treino' => 2]);
eq('banco sem a tabela de alunos devolve zeros e não quebra', extras_alcance(new PDO('sqlite::memory:'), $hoje), ['todos' => 0, 'ativos' => 0, 'nutricao' => 0, 'treino' => 0]);

echo "\n══ Estrutura do endpoint ══\n";
echo "\n══ Importar do CDD ══\n";
$pdo = banco();
$r = extras_importar_cdd($pdo);
eq('catálogo nunca salvo: já mostra os 4 módulos; importar não cria repetido', [$r, count(extras_ler($pdo)['categorias'])], [['criados' => 0, 'preenchidos' => 0], 4]);
$pdo = banco();
extras_gravar($pdo, ['versao' => 1, 'categorias' => [['id' => 'treinos-em-casa', 'titulo' => 'Treinos para fazer em casa', 'descricao' => '', 'emoji' => '🏠', 'cor' => '#22c55e', 'capa' => '', 'publicado' => false, 'visibilidade' => ['modo' => 'ativos', 'ids' => []], 'itens' => []],
    ['id' => 'cintura-de-atleta', 'titulo' => 'Meu módulo editado', 'descricao' => 'texto meu', 'emoji' => '🔥', 'cor' => '#f59e0b', 'capa' => '', 'publicado' => true, 'visibilidade' => ['modo' => 'todos', 'ids' => []], 'itens' => [['id' => 'x1', 'titulo' => 'Aula minha', 'tipo' => 'video', 'url' => '', 'publicado' => true, 'visibilidade' => null, 'criado_em' => '2026-01-01 00:00:00']]]]]);
$r = extras_importar_cdd($pdo);
$d = extras_ler($pdo); $por = []; foreach ($d['categorias'] as $c) $por[$c['id']] = $c;
eq('módulo vazio é preenchido, o que tem conteúdo é preservado, os que faltam são criados', [$r, count($d['categorias'])], [['criados' => 2, 'preenchidos' => 1], 4]);
eq('o módulo vazio ganhou as 5 aulas, a capa e foi publicado', [count($por['treinos-em-casa']['itens']), $por['treinos-em-casa']['capa'] !== '', $por['treinos-em-casa']['publicado']], [5, true, true]);
eq('o módulo que o administrador editou não foi tocado (nome, regra e aula dele)', [$por['cintura-de-atleta']['titulo'], $por['cintura-de-atleta']['visibilidade']['modo'], array_column($por['cintura-de-atleta']['itens'], 'titulo')], ['Meu módulo editado', 'todos', ['Aula minha']]);
eq('importar de novo não repete nada', [extras_importar_cdd($pdo), count(extras_ler($pdo)['categorias'])], [['criados' => 0, 'preenchidos' => 0], 4]);
extras_excluir_categoria($pdo, 'lives-e-aulas-exclusivas');
eq('módulo excluído volta ao importar (é para isso que o botão serve)', [extras_importar_cdd($pdo)['criados'], count(extras_ler($pdo)['categorias'])], [1, 4]);

echo "\n══ Capa do conteúdo ══\n";
eq('capa de aula só aceita https', [extras_limpar_item(['titulo' => 'x', 'capa' => 'https://a.com/i.jpg'])['capa'], extras_limpar_item(['titulo' => 'x'])['capa']], ['https://a.com/i.jpg', '']);
try { extras_limpar_item(['titulo' => 'x', 'capa' => 'http://a.com/i.jpg']); $r = 'aceitou'; } catch (InvalidArgumentException $e) { $r = 'recusou'; }
eq('capa de aula com http é recusada', $r, 'recusou');

$ep = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../app/api/extras.php'));
eq('o aluno só chama "lista"; todo o resto exige administrador', strpos($ep, "if (\$ehAluno || !\$admin) _exResp(403") > strpos($ep, "if (\$action === 'lista')"), true);
eq('"lista" é só de aluno (a equipe usa "admin")', strpos($ep, "if (!\$ehAluno) _exResp(403, ['ok' => false, 'error' => 'esta rota é do aluno'])") !== false, true);
eq('escrita só por POST', strpos($ep, "if (\$metodo !== 'POST') _exResp(405") !== false, true);
eq('a lista do aluno usa o id do TOKEN, nunca um id vindo da requisição', strpos($ep, "\$id = (int)(\$ctx['idatleta'] ?? \$ctx['idusuario']);") !== false && strpos($ep, "\$_GET['idatleta']") === false, true);
$lib = file_get_contents(__DIR__ . '/../app/api/_extras.php') . $ep;
eq('nenhum arquivo novo tem travessão no texto', preg_match('/[—–]/u', preg_replace('/\/\*.*?\*\//s', '', $lib)) === 1 ? 'tem' : 'nao', 'nao');

echo "\n" . ($ruim ? "$ruim FALHA(S)" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
