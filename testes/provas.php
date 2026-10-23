<?php
/**
 * Testes do Banco de provas sociais (app/api/_provas.php e provas.php).
 *
 * Rodar (PHP 8.2 com mbstring, openssl, curl, gd e pdo_sqlite):
 *     php testes/provas.php
 * No Windows, sem php.ini, passe um ini com as extensoes: php -c php-teste.ini testes/provas.php
 *
 * Banco de teste: SQLite em memoria. Nada de rede nem de Google Drive.
 */

require_once __DIR__ . '/../app/api/_provas.php';

$ok = 0; $falhas = [];
function confere(string $nome, $esperado, $obtido): void {
    global $ok, $falhas;
    if ($esperado === $obtido) { $ok++; return; }
    $falhas[] = $nome . ' | esperado: ' . var_export($esperado, true) . ' | obtido: ' . var_export($obtido, true);
}
function verdade(string $nome, $cond): void { confere($nome, true, (bool)$cond); }
function banco(): PDO {
    return new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
function recusa(callable $f): ?string {
    try { $f(); return null; } catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
}
const PNG_1X1 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

$base = ['pessoa_nome' => 'Marina Alves Costa', 'sexo' => 'F', 'idade' => 34, 'objetivo' => 'emagrecimento', 'tempo_meses' => 4, 'tempo_txt' => '4 meses',
    'peso_antes' => '78,5', 'peso_depois' => '69', 'resultado_txt' => 'Perdeu 9,5 kg e voltou a usar as roupas antigas.', 'depoimento_txt' => 'Eu não acreditava que ia conseguir',
    'tags' => 'Mulher, 30+, emagrecimento, mulher', 'autorizacao' => 'divulgar', 'uso_robo' => 1, 'uso_marketing' => 1, 'uso_site' => 0, 'uso_ia' => 1, 'destaque' => 1];

// ── Validação ───────────────────────────────────────────────────────────────
$c = provas_limpar($base);
confere('limpar: peso com vírgula vira número', [78.5, 69.0], [$c['peso_antes'], $c['peso_depois']]);
confere('limpar: tags minúsculas, sem repetição', 'mulher, 30+, emagrecimento', $c['tags']);
confere('limpar: sem nome recusa', 'InvalidArgumentException: Diga quem é a pessoa (pode ser só o primeiro nome).', recusa(fn() => provas_limpar(['autorizacao' => 'divulgar'])));
verdade('limpar: autorização inválida recusa', recusa(fn() => provas_limpar(['pessoa_nome' => 'A', 'autorizacao' => 'tanto faz'])) !== null);
verdade('limpar: peso absurdo recusa', recusa(fn() => provas_limpar(['pessoa_nome' => 'A', 'autorizacao' => 'interna'] + ['peso_antes' => 900])) !== null);
verdade('limpar: idade fora do esperado recusa', recusa(fn() => provas_limpar(['pessoa_nome' => 'A', 'autorizacao' => 'interna', 'idade' => 3])) !== null);
$pend = provas_limpar(['pessoa_nome' => 'Ana', 'autorizacao' => 'pendente', 'uso_robo' => 1, 'uso_marketing' => 1, 'uso_site' => 1, 'uso_ia' => 1]);
confere('limpar: sem autorização nenhum uso fica ligado (nem a IA)', [0, 0, 0, 0], [$pend['uso_robo'], $pend['uso_marketing'], $pend['uso_site'], $pend['uso_ia']]);
$int = provas_limpar(['pessoa_nome' => 'Ana', 'autorizacao' => 'interna', 'uso_robo' => 1, 'uso_marketing' => 1, 'uso_site' => 1, 'uso_ia' => 1]);
confere('limpar: uso interno liga só a IA, nunca robô, marketing nem site', [0, 0, 0, 1], [$int['uso_robo'], $int['uso_marketing'], $int['uso_site'], $int['uso_ia']]);
confere('limpar: texto longo é cortado', 1000, mb_strlen(provas_limpar(['pessoa_nome' => 'Ana', 'autorizacao' => 'interna', 'resultado_txt' => str_repeat('x', 5000)])['resultado_txt']));

// ── Gravar, ler, permissões ─────────────────────────────────────────────────
$pdo = banco();
$id1 = provas_salvar($pdo, $base, 5, 'Prof. Bia', false);
$p1 = provas_obter($pdo, $id1);
confere('salvar: grava e devolve com o criador', [$id1, 'Marina Alves Costa', 5, 'Prof. Bia'], [$p1['idprova'], $p1['pessoa_nome'], $p1['criado_por'], $p1['criado_por_nome']]);
confere('salvar: título automático usa o primeiro nome e o tempo', 'Marina: 4 meses', $p1['titulo']);
confere('formatar: peso resumido e variação', ['de 78,5 kg para 69 kg (menos 9,5 kg)', -9.5], [$p1['peso_resumo'], $p1['variacao_peso']]);
confere('formatar: com autorização e chave ligada, robô e IA podem; site não', [true, true, false, true], [$p1['pode_robo'], $p1['pode_marketing'], $p1['pode_site'], $p1['pode_ia']]);
verdade('salvar: outro professor não altera a prova de quem criou', strpos((string)recusa(fn() => provas_salvar($pdo, $base, 6, 'Prof. Caio', false, $id1)), 'Só quem cadastrou') !== false);
verdade('salvar: o criador altera', recusa(fn() => provas_salvar($pdo, ['autorizacao' => 'divulgar'] + $base, 5, 'Prof. Bia', false, $id1)) === null);
verdade('salvar: administrador altera qualquer uma', recusa(fn() => provas_salvar($pdo, $base, 1, 'Luiz', true, $id1)) === null);
confere('salvar: id que não existe', 'RuntimeException: Prova não encontrada.', recusa(fn() => provas_salvar($pdo, $base, 1, 'Luiz', true, 9999)));
verdade('excluir: outro professor não exclui', recusa(fn() => provas_excluir($pdo, $id1, 6, false)) !== null);
verdade('obter: id que não existe devolve null', provas_obter($pdo, 777) === null);

// ── Fotos e vídeos ─────────────────────────────────────────────────────────
$pasta = sys_get_temp_dir() . '/provas_teste_' . bin2hex(random_bytes(4));
$f = provas_foto_adicionar($pdo, $id1, PNG_1X1, 'antes', 'Início', 5, false, $pasta);
verdade('foto: grava o arquivo e devolve endereço público', is_file($pasta . '/' . basename($f['url'])) && strpos($f['url'], 'https://intusfit.com.br/app/img/provas/prova_' . $id1 . '_') === 0);
verdade('foto: arquivo que não é imagem é recusado', recusa(fn() => provas_foto_adicionar($pdo, $id1, 'data:image/png;base64,' . base64_encode('<?php echo 1; ?>'), 'depois', '', 5, false, $pasta)) !== null);
verdade('foto: não é data URI, é recusado', recusa(fn() => provas_foto_adicionar($pdo, $id1, 'https://x.com/a.png', 'depois', '', 5, false, $pasta)) !== null);
verdade('foto: outro professor não adiciona', recusa(fn() => provas_foto_adicionar($pdo, $id1, PNG_1X1, 'depois', '', 6, false, $pasta)) !== null);
for ($i = 1; $i < PROVAS_MAX_FOTOS; $i++) provas_foto_adicionar($pdo, $id1, PNG_1X1, 'durante', '', 5, false, $pasta);
verdade('foto: passa do limite recusa', strpos((string)recusa(fn() => provas_foto_adicionar($pdo, $id1, PNG_1X1, 'depois', '', 5, false, $pasta)), 'Limite') !== false);
$l = provas_link_adicionar($pdo, $id1, 'https://youtu.be/abc123', 'depoimento', 'Depoimento', 5, false);
confere('link: vídeo por https vale', true, $l['idpm'] > 0);
foreach (['http://youtu.be/abc', 'javascript:alert(1)', 'ftp://x.com/v', 'youtu.be/abc'] as $ruim) verdade("link: recusa $ruim", recusa(fn() => provas_link_adicionar($pdo, $id1, $ruim, 'depois', '', 5, false)) !== null);
$p1 = provas_obter($pdo, $id1);
$tipos = array_count_values(array_column($p1['midias'], 'tipo'));
confere('mídias: 12 fotos e 1 vídeo por link, em ordem', [12, 1, 'foto', 'antes'], [$tipos['foto'], $tipos['link'], $p1['midias'][0]['tipo'], $p1['midias'][0]['momento']]);
$ex = provas_midia_excluir($pdo, $f['idpm'], 5, false);
confere('mídia: excluir tira só a referência e a foto some da prova', [true, 12], [$ex, count(provas_obter($pdo, $id1)['midias'])]);
verdade('mídia: outro professor não exclui', recusa(fn() => provas_midia_excluir($pdo, $l['idpm'], 6, false)) !== null);

// vídeo enviado (intus_midia) toca pelo token
$pdo->exec("CREATE TABLE intus_midia (idmidia INTEGER PRIMARY KEY AUTOINCREMENT, dono_tipo TEXT, dono_id INT, tipo TEXT, token_publico TEXT, status TEXT)");
$pdo->exec("INSERT INTO intus_midia (dono_tipo, dono_id, tipo, token_publico, status) VALUES ('prova', $id1, 'video', '" . str_repeat('ab', 16) . "', 'pronto')");
$mid = (int)$pdo->lastInsertId();
$pdo->exec("DELETE FROM intus_prova_midia WHERE tipo = 'link'");
provas_video_ligar($pdo, $id1, $mid, null, 'depois', 'Vídeo');
$vd = array_values(array_filter(provas_obter($pdo, $id1)['midias'], fn($m) => $m['tipo'] === 'video'))[0];
confere('vídeo enviado: toca pelo endereço público do token', 'https://intusfit.com.br/app/api/midia.php?action=video&t=' . str_repeat('ab', 16), $vd['url']);

// ── Privacidade: o que o robô e a IA enxergam ──────────────────────────────
$idP = provas_salvar($pdo, ['pessoa_nome' => 'Paula Sem Autorização', 'autorizacao' => 'pendente', 'uso_robo' => 1, 'uso_ia' => 1, 'resultado_txt' => 'segredo'], 1, 'Luiz', true);
$idI = provas_salvar($pdo, ['pessoa_nome' => 'Iara Interna', 'autorizacao' => 'interna', 'uso_ia' => 1, 'resultado_txt' => 'uso interno'], 1, 'Luiz', true);
$idD = provas_salvar($pdo, ['pessoa_nome' => 'Dora Desligada', 'autorizacao' => 'divulgar', 'uso_robo' => 0, 'uso_ia' => 0, 'resultado_txt' => 'chaves desligadas'], 1, 'Luiz', true);
$idX = provas_salvar($pdo, ['pessoa_nome' => 'Xuxa Inativa', 'autorizacao' => 'divulgar', 'uso_robo' => 1, 'uso_ia' => 1, 'ativo' => 0, 'resultado_txt' => 'inativa'], 1, 'Luiz', true);
// defesa em profundidade: mesmo se uma linha inconsistente chegar ao banco (chave ligada sem autorização), o servidor não deixa sair
$pdo->exec("INSERT INTO intus_prova (titulo, pessoa_nome, objetivo, programa, autorizacao, uso_robo, uso_ia, ativo, criado_em) VALUES ('X', 'Linha Inconsistente', 'outro', 'intus', 'pendente', 1, 1, 1, '2026-10-23 10:00:00')");
$idInc = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO intus_prova (titulo, pessoa_nome, objetivo, programa, autorizacao, uso_robo, uso_ia, ativo, criado_em) VALUES ('Y', 'Interna Com Robo', 'outro', 'intus', 'interna', 1, 1, 1, '2026-10-23 10:00:00')");
$robo = provas_carregar_robo($pdo);
confere('robô: só a prova com autorização, chave ligada e ativa (linha inconsistente ou só interna fica de fora)', [$id1], array_column($robo, 'idprova'));
$ia = provas_para_ia($pdo);
$nomesIa = array_column($ia, 'nome'); sort($nomesIa);
confere('IA: autorização interna ou de divulgação, chave ligada, ativa; pendente e inativa ficam de fora', ['Iara', 'Interna', 'Marina'], $nomesIa);
$iaMar = array_values(array_filter($ia, fn($x) => $x['nome'] === 'Marina'))[0];
verdade('IA: só o primeiro nome e as fotos como contagem, sem endereço', !isset($iaMar['pessoa_nome']) && $iaMar['midias'] === ['fotos' => 11, 'videos' => 1] && strpos(json_encode($iaMar), 'img/provas') === false && strpos(json_encode($iaMar), 'Alves') === false);
$iaIara = array_values(array_filter($ia, fn($x) => $x['nome'] === 'Iara'))[0];
confere('IA: prova só interna não traz texto pronto para divulgar', [false, null], [$iaIara['pode_divulgar'], $iaIara['texto_pronto']]);
verdade('listar: filtra por uso do robô', count(provas_listar($pdo, ['uso' => 'robo'])) >= 1 && count(array_filter(provas_listar($pdo, ['uso' => 'robo']), fn($x) => !$x['uso_robo'])) === 0);
confere('listar: padrão só mostra as ativas', false, in_array($idX, array_column(provas_listar($pdo), 'idprova'), true));
confere('listar: ativo=0 mostra as inativas', [$idX], array_column(provas_listar($pdo, ['ativo' => '0']), 'idprova'));
confere('listar: busca por trecho do resultado', [$idI], array_column(provas_listar($pdo, ['busca' => 'uso interno']), 'idprova'));
confere('listar: busca com curinga % não vira "todas"', [], array_column(provas_listar($pdo, ['busca' => '%']), 'idprova'));
confere('resumo: contagens', ['total' => 6, 'divulgar' => 2, 'pendentes' => 2, 'robo' => 1, 'destaques' => 1], provas_resumo($pdo));

// ── Texto e escolha para o robô ────────────────────────────────────────────
$txt = provas_texto($robo[0]);
verdade('texto: usa só o primeiro nome, o tempo, o peso e o depoimento', strpos($txt, 'A Marina (34 anos) treinou por 4 meses com a Intus e foi de 78,5 kg para 69 kg (menos 9,5 kg).') === 0 && strpos($txt, 'Nas palavras dela: “Eu não acreditava que ia conseguir”') !== false);
verdade('texto: sobrenome nunca aparece', strpos($txt, 'Alves') === false && strpos($txt, 'Costa') === false);
verdade('texto: sem travessão', !preg_match('/[\x{2014}\x{2013}]/u', $txt));
$lista = [
    ['idprova' => 1, 'objetivo' => 'hipertrofia', 'sexo' => 'M', 'destaque' => false, 'midias' => [], 'peso_antes' => null, 'peso_depois' => null],
    ['idprova' => 2, 'objetivo' => 'emagrecimento', 'sexo' => 'F', 'destaque' => false, 'midias' => [], 'peso_antes' => 80.0, 'peso_depois' => 70.0],
    ['idprova' => 3, 'objetivo' => 'emagrecimento', 'sexo' => 'M', 'destaque' => true, 'midias' => [1], 'peso_antes' => 90.0, 'peso_depois' => 80.0],
];
confere('escolher: objetivo e sexo iguais ganham', 2, provas_escolher($lista, ['objetivo' => 'Quero emagrecer', 'genero' => 'F'])['idprova']);
confere('escolher: sem dado da pessoa, o destaque com mídia ganha', 3, provas_escolher($lista, [])['idprova']);
confere('escolher: lista vazia', null, provas_escolher([], ['objetivo' => 'emagrecer']));
confere('objetivo em texto livre', ['emagrecimento', 'hipertrofia', 'definicao', ''], [provas_objetivo_de_texto('Quero perder peso'), provas_objetivo_de_texto('ganhar massa'), provas_objetivo_de_texto('definição'), provas_objetivo_de_texto('qualquer coisa')]);
confere('robô sem tabela devolve lista vazia, sem quebrar', [], provas_carregar_robo(new PDO('sqlite::memory:')) );

// ── Estrutura do endpoint e da tela ────────────────────────────────────────
$ep = file_get_contents(__DIR__ . '/../app/api/provas.php');
verdade('endpoint: aluno e sessão inválida são barrados antes de qualquer ação', strpos($ep, "!empty(\$ctx['is_aluno'])") !== false && strpos($ep, "prv_resp(401") < strpos($ep, 'switch ($action)'));
verdade('endpoint: o pacote da IA é só do administrador', (bool)preg_match("/case 'ia':\s+if \(!\\\$admin\) prv_resp\(403/", $ep));
verdade('endpoint: as ações que gravam exigem POST', (bool)preg_match("/\\\$soPost = \['salvar', 'excluir', 'foto', 'link', 'midia_excluir', 'video_iniciar', 'video_parte'\]/", $ep));
verdade('endpoint: vídeo enviado só vai ao Drive (nunca ao disco) e só para quem pode mexer na prova', strpos($ep, 'provas_checar_dono') !== false && strpos($ep, 'gdriveIniciarUploadGrande') !== false && strpos($ep, 'move_uploaded_file') === false);
$lib = file_get_contents(__DIR__ . '/../app/api/_provas.php');
verdade('biblioteca: SQL portável (sem NOW, sem ON DUPLICATE KEY)', !preg_match('/\bNOW\(\)|ON DUPLICATE KEY|INSERT IGNORE/i', $lib));
verdade('biblioteca: não faz rede nem chama a Meta', !preg_match('/wa_enviar|curl_|file_get_contents\(\s*[\'"]http/i', $lib));
$res = __DIR__ . '/../app/painel/resultados.html';
if (is_file($res)) {
    $h = file_get_contents($res);
    verdade('tela: a aba de provas existe e só aparece para a equipe', strpos($h, 'provas.js') !== false && strpos($h, 'id="rs-aba-provas"') !== false);
}
$pj = __DIR__ . '/../app/painel/provas.js';
if (is_file($pj)) {
    $j = file_get_contents($pj);
    verdade('provas.js: escapa HTML antes de inserir texto', strpos($j, 'function esc(') !== false);
    verdade('provas.js: sem travessão nos textos', !preg_match('/[\x{2014}\x{2013}]/u', $j));
    verdade('provas.js: usa a API certa', strpos($j, '/provas.php') !== false);
}

array_map('unlink', glob($pasta . '/*') ?: []); @rmdir($pasta);

echo "\n";
if ($falhas) {
    echo count($falhas) . " FALHA(S) de " . ($ok + count($falhas)) . " conferencias:\n";
    foreach ($falhas as $x) echo "  - $x\n";
    exit(1);
}
echo "OK: $ok conferencias passaram.\n";
