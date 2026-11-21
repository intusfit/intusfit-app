<?php
// Controle de spam, pornografia, violência e ofensas (app/api/_moderacao.php), em SQLite na memória.
// Dois lados: a linguagem normal de academia NÃO pode ser sinalizada, e o que é ruim precisa ser. Mais a fila: decisão, ocultar/mostrar, e-mail limitado.
//   php -c <ini com pdo_sqlite e mbstring> testes/moderacao.php
require __DIR__ . '/../app/api/_moderacao.php';
$ok = 0; $ruim = 0;
function eq($rot, $obtido, $esperado) {
    global $ok, $ruim;
    if ($obtido === $esperado) { $ok++; echo "  OK    $rot\n"; }
    else { $ruim++; echo "  FALHA $rot\n          esperado " . json_encode($esperado, JSON_UNESCAPED_UNICODE) . "\n          obtido   " . json_encode($obtido, JSON_UNESCAPED_UNICODE) . "\n"; }
}
function cats(string $t) { $a = intusModAnalisar($t); return $a ? $a['categorias'] : []; }

echo "\n══ Linguagem de academia: NÃO sinaliza ══\n";
$limpos = [
    'Que treino foda!', 'que treino foda', 'Foi de matar hoje', 'foi de matar esse treino de perna', 'Esse treino vai te matar', 'esse cardio vai te matar kkkk',
    'Hoje eu vou matar o treino', 'matei o treino de costas', 'Vou matar essa série', 'vou matar a fome com um frango', 'tô morrendo de saudade da academia',
    'Puta treino, caralho!', 'Porra, que carga pesada', 'Caralho, bateu meu recorde', 'puta que pariu, 100kg no supino', 'foda demais esse resultado',
    'Você é louco, que carga!', 'Tu é doente, parabéns', 'Você é uma fera', 'Monstro!', 'Você é um animal, arrasou', 'tá uma besta de forte',
    'Destruí o treino hoje', 'Quebrei tudo no agachamento', 'Mandou muito bem, arrebentou', 'me matou esse treino', 'morri na última série',
    'vou socar o saco de pancadas', 'treino de boxe hoje, vou socar tudo', 'esse leg day é covardia', 'esse treino vai te quebrar',
    'vai te matar de rir esse vídeo', 'vou te matar de rir com essa foto', 'morra de inveja, bati meu PR', 'vou me matar de treinar essa semana',
    'Parabéns pelo resultado!', 'Que evolução, continue assim', 'tô vendo seu progresso, incrível', 'Bom dia, bora treinar', 'Quem treina hoje?',
    'Segue o treino que montei: 4x12 supino, 3x15 crucifixo', 'Fiz 5km em 28min, 120kg no leg press, 10 reps', 'Chama o personal pra ajustar a carga',
    'Gostei do seu perfil na Intus', 'Instagram: intusfit.com.br/app', 'olha esse vídeo youtube.com/watch?v=abc', 'Minha meta é perder 10kg até 20/12',
    'Combinado, me avisa quando chegar', 'Que treino puxado, mas valeu a pena', 'eu odeio esse exercício, é horrível', 'essa dieta é uma merda de difícil',
    'Mataram a charada, o resultado apareceu', 'tô com muita fome, vou matar um prato de arroz',
];
foreach ($limpos as $t) eq('limpo: ' . $t, cats($t), []);

echo "\n══ Violência (só o que não tem leitura de academia) ══\n";
foreach (['Vou te esfaquear', 'vou te esfaquear seu desgraçado', 'vou estuprar ela', 'te estrangulo', 'vou te dar um tiro', 'tiro na cara', 'morre logo, desgraçado', 'quero te ver morto', 'tomara que ela morra', 'mando te matar', 'Vou me matar', 'quero me suicidar'] as $t)
    eq('violência: ' . $t, cats($t), ['violencia']);
eq('ameaça com leet e letra repetida', cats('vou te esf4qu34rrr'), ['violencia']);
eq('ameaça com arma mesmo citando a academia como lugar', cats('vou te dar um tiro na saída da academia'), ['violencia']);
echo "\n══ Brincadeira de treino entre aluno e personal: NÃO sinaliza ══\n";
foreach (['Vou te matar', 'vou te matar amanhã', 'Vou te matar!', 'te arrebento no treino', 'te arrebento', 'te mato de tanto treino', 'Você merece morrer, que perna!', 'merece morrer quem pula cardio', 'vou te espancar na barra', 'te quebro no leg day', 'morra de inveja', 'morra!', 'vou te encher de porrada na carga'] as $t)
    eq('brincadeira: ' . $t, cats($t), []);

echo "\n══ Pornografia ══\n";
foreach (['manda nudes', 'xvideos tem tudo', 'tem onlyfans?', 'p0rn0 gratis', 'sexo anal', 'quero te comer', 'manda foto pelada', 'conteúdo adulto no meu perfil', 'vamos fazer putaria'] as $t)
    eq('pornografia: ' . $t, cats($t), ['pornografia']);

echo "\n══ Ofensas ══\n";
foreach (['Você é um idiota', 'vc é burra demais', 'seu otário', 'sua vagabunda', 'arrombado', 'filho da puta', 'vai se foder', 'vai tomar no cu', 'você é gorda e nojenta', 'sua puta', 'seu macaco', 'retardado', 'sua baleia', 'você é um lixo', 'FDP'] as $t)
    eq('ofensa: ' . $t, cats($t), ['ofensiva']);
eq('xingamento com letra repetida', cats('seu idiotaaaa'), ['ofensiva']);

echo "\n══ Spam ══\n";
foreach (['Ganhe dinheiro em casa, clique aqui', 'acesse www.promo-fit.com', 'compre em https://loja.xyz/oferta', 'me chama no whats 11 98877-6655', 'link na bio', 'siga meu perfil', 'vendo seguidores', 'Aposte no tigrinho agora', 'renda extra garantida', 'vendo oxandrolona, chama no zap', 'bit.ly/abc123', 'wa.me/5511999998888', 'seu dinheiro dobra: bitcoin', 'fale comigo: contato@exemplo.com.br'] as $t)
    eq('spam: ' . $t, cats($t), ['spam']);
eq('venda de pack é pornografia e venda', cats('vendo pack de fotos'), ['pornografia', 'spam']);
eq('anabolizante só citado não é spam', cats('li que a oxandrolona prejudica o fígado'), []);

echo "\n══ Mais de uma categoria, do mais grave ao menos grave ══\n";
eq('ameaça + spam', cats('vou te esfaquear! www.promo.com'), ['violencia', 'spam']);
eq('ofensa + pornografia', cats('sua puta, manda nudes'), ['pornografia', 'ofensiva']);
$a = intusModAnalisar('Seu idiota, vou te esfaquear');
eq('trecho e motivo vêm da regra', [$a['categorias'], $a['trecho'] !== '', count($a['motivos'])], [['violencia', 'ofensiva'], true, 2]);
eq('texto vazio', intusModAnalisar('   '), null);

echo "\n══ Fila no banco ══\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE intus_comentario_pub (idcomentario INTEGER PRIMARY KEY AUTOINCREMENT, alvo_tipo TEXT, alvo_id INT, resposta_a INT NULL, autor_tipo TEXT, autor_id INT, autor_nome TEXT, texto TEXT, oculto INT NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE intus_feed_post (idpost INTEGER PRIMARY KEY AUTOINCREMENT, idatleta INT, legenda TEXT, localizacao TEXT NULL, ativo INT NOT NULL DEFAULT 1)");
$emails = [];
$GLOBALS['INTUS_MOD_EMAIL_STUB'] = function ($assunto, $corpo) use (&$emails) { $emails[] = [$assunto, $corpo]; return true; };
$com = function ($texto, $resp = null, $alvo = 5, $autor = 7) use ($pdo) {
    $pdo->prepare("INSERT INTO intus_comentario_pub (alvo_tipo, alvo_id, resposta_a, autor_tipo, autor_id, autor_nome, texto) VALUES ('feed_post', ?, ?, 'aluno', ?, 'Fulano de Tal', ?)")->execute([$alvo, $resp, $autor, $texto]);
    return (int)$pdo->lastInsertId();
};
$c1 = $com('Que treino foda!');
eq('comentário limpo não entra na fila nem gera e-mail', [intusModRegistrar($pdo, 'comentario', $c1, 7, 'Fulano de Tal', 'Que treino foda!', 'feed_post', 5), count($emails)], [null, 0]);
$c2 = $com('Vou te esfaquear');
$m2 = intusModRegistrar($pdo, 'comentario', $c2, 7, 'Fulano de Tal', 'Vou te esfaquear', 'feed_post', 5);
eq('comentário ameaçador entra na fila; quem registra não manda e-mail (isso é da varredura)', [is_int($m2), count($emails)], [true, 0]);
$l = intusModListar($pdo);
eq('aparece como pendente, ainda sem ocultar', [count($l['pendentes']), $l['pendentes'][0]['status'], (int)$pdo->query("SELECT oculto FROM intus_comentario_pub WHERE idcomentario = $c2")->fetchColumn()], [1, 'pendente', 0]);
eq('Manter tira da fila e não oculta', [intusModDecidir($pdo, $m2, 'manter')['status'], count(intusModListar($pdo)['pendentes']), (int)$pdo->query("SELECT oculto FROM intus_comentario_pub WHERE idcomentario = $c2")->fetchColumn()], ['mantido', 0, 0]);
// ocultar leva as respostas; mostrar de novo traz de volta
$c3 = $com('seu idiota');
$r3 = $com('concordo!', $c3);
$r33 = $com('também', $r3);
$outro = $com('outro comentário qualquer');
$m3 = intusModRegistrar($pdo, 'comentario', $c3, 7, 'Fulano de Tal', 'seu idiota', 'feed_post', 5);
eq('Ocultar esconde o comentário e as respostas dele, nenhum outro', [intusModDecidir($pdo, $m3, 'ocultar')['status'], array_map('intval', $pdo->query('SELECT oculto FROM intus_comentario_pub WHERE idcomentario IN (' . "$c3,$r3,$r33,$outro" . ') ORDER BY idcomentario')->fetchAll(PDO::FETCH_COLUMN))], ['oculto', [1, 1, 1, 0]]);
eq('os ocultos ficam numa lista para desfazer', array_column(intusModListar($pdo)['ocultos'], 'idmod'), [$m3]);
eq('Mostrar de novo devolve tudo', [intusModDecidir($pdo, $m3, 'restaurar')['status'], array_sum(array_map('intval', $pdo->query("SELECT oculto FROM intus_comentario_pub")->fetchAll(PDO::FETCH_COLUMN)))], ['mantido', 0]);
// post
$pdo->exec("INSERT INTO intus_feed_post (idatleta, legenda) VALUES (7, 'manda nudes')");
$p1 = (int)$pdo->lastInsertId();
$mp = intusModRegistrar($pdo, 'post', $p1, 7, 'Fulano de Tal', 'manda nudes', 'feed_post', $p1);
eq('post sinalizado entra como "Post no Feed"', [is_int($mp), intusModListar($pdo)['pendentes'][0]['onde']], [true, 'Post no Feed']);
intusModDecidir($pdo, $mp, 'ocultar');
eq('Ocultar post muda ativo para 3 (nenhuma lista do Feed mostra)', (int)$pdo->query("SELECT ativo FROM intus_feed_post WHERE idpost = $p1")->fetchColumn(), 3);
intusModDecidir($pdo, $mp, 'restaurar');
eq('Mostrar de novo volta a ativo 1', (int)$pdo->query("SELECT ativo FROM intus_feed_post WHERE idpost = $p1")->fetchColumn(), 1);
$pdo->exec("UPDATE intus_feed_post SET ativo = 0 WHERE idpost = $p1");
$pdo->exec("UPDATE intus_moderacao SET status = 'pendente' WHERE idmod = $mp");
eq('item cujo post foi excluído sai da fila sozinho', [count(array_filter(intusModListar($pdo)['pendentes'], fn($x) => $x['idmod'] === $mp)), $pdo->query("SELECT status FROM intus_moderacao WHERE idmod = $mp")->fetchColumn()], [0, 'excluido']);
$pdo->exec("DELETE FROM intus_comentario_pub WHERE idcomentario = $c2");
$pdo->exec("UPDATE intus_moderacao SET status = 'pendente' WHERE idmod = $m2");
intusModListar($pdo);
eq('item cujo comentário foi excluído também', $pdo->query("SELECT status FROM intus_moderacao WHERE idmod = $m2")->fetchColumn(), 'excluido');
eq('ação inválida e item inexistente são recusados', [intusModDecidir($pdo, $m3, 'apagar')['ok'], intusModDecidir($pdo, 9999, 'manter')['ok']], [false, false]);

echo "\n══ Repetição (spam sem palavra suspeita) ══\n";
$txt = 'Parabéns pelo treino, continue assim!';
$n0 = count($emails);
for ($i = 0; $i < 3; $i++) { $id = $com($txt, null, 20 + $i, 9); eq("repetição $i: ainda não é spam", intusModRegistrar($pdo, 'comentario', $id, 9, 'Beltrano', $txt, 'feed_post', 20 + $i), null); }
$id = $com($txt, null, 30, 9);
$mr = intusModRegistrar($pdo, 'comentario', $id, 9, 'Beltrano', $txt, 'feed_post', 30);
eq('na quarta repetição entra na fila como spam', [is_int($mr), intusModListar($pdo)['pendentes'][0]['categorias']], [true, ['spam']]);
$curto = 'kkkkkkkk';
for ($i = 0; $i < 5; $i++) $com($curto, null, 40 + $i, 10);
eq('texto curto repetido (risada) não conta', intusModRegistrar($pdo, 'comentario', $com($curto, null, 50, 10), 10, 'Ciclano', $curto, 'feed_post', 50), null);

echo "\n══ Varredura (roda a cada poucos minutos, nunca na hora de publicar) ══\n";
$estado = sys_get_temp_dir() . '/intus_mod_estado_' . getmypid() . '.json';
@unlink($estado);
$GLOBALS['INTUS_MOD_ESTADO_ARQ'] = $estado;
$pdo->exec('DELETE FROM intus_moderacao'); $pdo->exec('DELETE FROM intus_comentario_pub'); $pdo->exec('DELETE FROM intus_feed_post');
$emails = [];
$com('vou te esfaquear (antigo, de antes da varredura existir)');
$r = intusModVarrer($pdo);
eq('a primeira rodada só marca o ponto de partida: não varre o histórico, não manda e-mail', [$r['inicio'] ?? false, $r['novos'], count($emails), count(intusModListar($pdo)['pendentes'])], [true, 0, 0, 0]);
$r = intusModVarrer($pdo);
eq('sem nada novo: nada acontece', [$r['novos'], $r['comentarios'], $r['email'], count($emails)], [0, 0, false, 0]);
$com('Que treino foda!'); $com('Foi de matar'); $com('Vou te esfaquear'); $com('seu idiota');
$pdo->exec("INSERT INTO intus_comentario_pub (alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, texto) VALUES ('feed_post', 5, 'prof', 1, 'Luiz', 'vou te esfaquear se faltar')");   // equipe não entra
$pdo->exec("INSERT INTO intus_feed_post (idatleta, legenda, localizacao) VALUES (7, 'manda nudes', 'Academia X')");
$pdo->exec("INSERT INTO intus_feed_post (idatleta, legenda, localizacao) VALUES (7, 'Dia de perna, foi de matar', 'Academia X')");
$pdo->exec("INSERT INTO intus_feed_post (idatleta, legenda, ativo) VALUES (7, 'post apagado: vou te esfaquear', 0)");
$r = intusModVarrer($pdo);
eq('a rodada analisa só o que chegou: 4 comentários de aluno (o da equipe nem é lido) e 2 posts vivos', [$r['comentarios'], $r['posts']], [4, 2]);
eq('sinaliza 2 comentários e 1 post (gíria, equipe e post apagado ficam de fora)', [$r['novos'], count(intusModListar($pdo)['pendentes'])], [3, 3]);
eq('um ÚNICO e-mail-resumo por rodada', [$r['email'], count($emails)], [true, 1]);
eq('o resumo diz quem, onde, o texto, que continua visível e quantos aguardam', [str_contains($emails[0][0], '3 conteúdos sinalizados'), str_contains($emails[0][1], 'Fulano de Tal'), str_contains($emails[0][1], 'Comentário em post do Feed'), str_contains($emails[0][1], 'Post no Feed'), str_contains($emails[0][1], 'manda nudes'), str_contains($emails[0][1], 'continuam visíveis'), str_contains($emails[0][1], 'aguardando decisão: 3')], [true, true, true, true, true, true, true]);
$r = intusModVarrer($pdo);
eq('a rodada seguinte não repete nada (o ponto de partida avançou)', [$r['comentarios'], $r['posts'], $r['novos'], count($emails)], [0, 0, 0, 1]);
$com('Vou te esfaquear');
$r = intusModVarrer($pdo);
eq('um item novo gera e-mail com assunto no singular', [$r['novos'], count($emails), str_contains($emails[1][0], 'conteúdo sinalizado (Violência) de Fulano de Tal')], [1, 2, true]);
$GLOBALS['INTUS_MOD_EMAIL_STUB'] = function () { throw new RuntimeException('smtp caiu'); };
$com('vou te esfaquear de novo');
$r = intusModVarrer($pdo);
eq('e-mail que falha não derruba a varredura: o item fica no painel', [$r['novos'], $r['email'], count(intusModListar($pdo)['pendentes'])], [1, false, 5]);
$GLOBALS['INTUS_MOD_EMAIL_STUB'] = function ($a, $b) use (&$emails) { $emails[] = [$a, $b]; return true; };
for ($i = 0; $i < 12; $i++) $com('vou te esfaquear ' . $i);
$r = intusModVarrer($pdo);
eq('12 de uma vez: um e-mail só, com as 10 primeiras e "mais 2 no painel"', [$r['novos'], count($emails), str_contains(end($emails)[1], 'e mais 2 no painel')], [12, 3, true]);
@unlink($estado);

echo "\n══ Denúncia de aluno (botão Denunciar do app) ══\n";
$pdo->exec('DELETE FROM intus_moderacao'); $pdo->exec('DELETE FROM intus_comentario_pub'); $pdo->exec('DELETE FROM intus_feed_post');
$emails = [];
@unlink($estado); intusModVarrer($pdo);   // ponto de partida novo (a 1ª rodada só marca)
$d1 = $com('texto normalzinho, nada de errado', null, 5, 7);          // autor 7
$pdo->exec("INSERT INTO intus_feed_post (idatleta, legenda) VALUES (7, '')");   // post só com imagem
$pd = (int)$pdo->lastInsertId();
eq('motivo inventado é recusado', intusModDenunciar($pdo, 'comentario', $d1, 8, 'Bia', 'xingou minha mãe')['codigo'], 400);
eq('conteúdo que não existe: 404', intusModDenunciar($pdo, 'comentario', 9999, 8, 'Bia', 'spam')['codigo'], 404);
eq('não dá para denunciar o próprio comentário', intusModDenunciar($pdo, 'comentario', $d1, 7, 'Fulano', 'spam')['codigo'], 400);
eq('nem o próprio post', intusModDenunciar($pdo, 'post', $pd, 7, 'Fulano', 'spam')['codigo'], 400);
eq('a 1ª denúncia entra na fila, mesmo com o texto limpo para o detector', [intusModDenunciar($pdo, 'comentario', $d1, 8, 'Bia', 'ofensa')['ok'], count(intusModListar($pdo)['pendentes'])], [true, 1]);
$l = intusModListar($pdo)['pendentes'][0];
eq('categoria, motivo e contagem', [$l['categorias'], $l['motivo'], $l['denuncias'], $l['autor'], $l['onde']], [['denuncia'], 'Denúncia de 1 aluno: ofensa ou assédio', 1, 'Fulano de Tal', 'Comentário em post do Feed']);
eq('a mesma pessoa denunciando de novo não conta duas vezes', [intusModDenunciar($pdo, 'comentario', $d1, 8, 'Bia', 'spam')['denuncias'], intusModListar($pdo)['pendentes'][0]['denuncias']], [1, 1]);
intusModDenunciar($pdo, 'comentario', $d1, 9, 'Caio', 'violencia');
$l = intusModListar($pdo)['pendentes'][0];
eq('outra pessoa soma no MESMO item (um item só, com os dois motivos)', [count(intusModListar($pdo)['pendentes']), $l['denuncias'], $l['motivo']], [1, 2, 'Denúncia de 2 alunos: ofensa ou assédio, violência ou ameaça']);
$r = intusModVarrer($pdo);   // 1ª rodada nesse estado: ponto de partida já existe
eq('a varredura avisa por e-mail a denúncia, uma vez', [$r['email'], count($emails), str_contains($emails[0][0], 'Denúncia')], [true, 1, true]);
eq('o e-mail traz o motivo e o texto denunciado', [str_contains($emails[0][1], 'Denúncia de 2 alunos'), str_contains($emails[0][1], 'texto normalzinho')], [true, true]);
$r = intusModVarrer($pdo);
eq('a rodada seguinte não repete o aviso', [$r['email'], count($emails)], [false, 1]);
intusModDecidir($pdo, $l['idmod'], 'manter');
eq('Manter tira da fila', count(intusModListar($pdo)['pendentes']), 0);
intusModDenunciar($pdo, 'comentario', $d1, 9, 'Caio', 'spam');
eq('quem já tinha denunciado não reabre o que você manteve', count(intusModListar($pdo)['pendentes']), 0);
intusModDenunciar($pdo, 'comentario', $d1, 10, 'Dani', 'spam');
$l = intusModListar($pdo)['pendentes'][0] ?? null;
eq('uma pessoa NOVA reabre o item mantido, com 3 denúncias', [$l['denuncias'] ?? 0], [3]);
$r = intusModVarrer($pdo);
eq('e o aviso por e-mail sai de novo', [$r['email'], count($emails)], [true, 2]);
eq('denúncia de post só com imagem entra com um texto explicativo', [intusModDenunciar($pdo, 'post', $pd, 8, 'Bia', 'sexual')['ok'], str_contains(intusModListar($pdo)['pendentes'][0]['texto'], 'post só com imagem'), intusModListar($pdo)['pendentes'][0]['onde']], [true, true, 'Post no Feed']);
// conteúdo já marcado pelo detector e depois denunciado: as duas coisas aparecem num item só
$d2 = $com('seu idiota', null, 5, 7);
$m = intusModRegistrar($pdo, 'comentario', $d2, 7, 'Fulano de Tal', 'seu idiota', 'feed_post', 5);
intusModDenunciar($pdo, 'comentario', $d2, 8, 'Bia', 'ofensa');
$l = array_values(array_filter(intusModListar($pdo)['pendentes'], fn($x) => $x['idmod'] === $m))[0];
eq('detector + denúncia no mesmo item', [$l['categorias'], $l['motivo']], [['ofensiva', 'denuncia'], 'Ofensa: ofensa dirigida | Denúncia de 1 aluno: ofensa ou assédio']);
intusModDecidir($pdo, $m, 'ocultar');
eq('comentário já oculto não aceita nova denúncia (404)', intusModDenunciar($pdo, 'comentario', $d2, 11, 'Edu', 'spam')['codigo'], 404);
$pdo->exec("UPDATE intus_feed_post SET ativo = 0 WHERE idpost = $pd");
eq('post apagado: 404', intusModDenunciar($pdo, 'post', $pd, 12, 'Fê', 'spam')['codigo'], 404);
@unlink($estado);

echo "\n══ Falhas não derrubam ══\n";
$pdoVazio = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
eq('banco sem a tabela de comentários: a fila se cria e registra', is_int(intusModRegistrar($pdoVazio, 'post', 1, 7, 'X', 'manda nudes', 'feed_post', 1)), true);
echo "\n" . ($ruim ? $ruim . ' FALHA(S), ' : '') . $ok . " ok\n";
exit($ruim ? 1 : 0);
