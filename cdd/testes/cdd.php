<?php
/**
 * Testes do núcleo do CDD, em SQLite na memória (sem servidor e sem tocar no banco de produção).
 *   php -d extension_dir=<pasta ext do PHP> -d extension=pdo_sqlite -d extension=sqlite3 -d extension=mbstring cdd/testes/cdd.php
 */
require_once __DIR__ . '/../api/_nucleo.php';

$ok = 0; $ruim = 0;
function eq($rot, $c, $d = '') { global $ok, $ruim; if ($c) { $ok++; echo "  OK    $rot\n"; } else { $ruim++; echo "  FALHA $rot" . ($d ? "\n          $d" : '') . "\n"; } }

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ctx = ['ip' => '10.0.0.1', 'base' => 'https://exemplo.test/cdd', 'seed' => ''];
function req($acao, $metodo = 'GET', $q = [], $b = [], $token = '', $ip = '10.0.0.1') {
    global $pdo, $ctx; $c = $ctx; $c['ip'] = $ip;
    return cddTratar($pdo, $metodo, $acao, $q, $b, $token, $c);
}

echo "Instalação do primeiro administrador\n";
[$s, $d] = req('saude'); eq('saude responde e a instalação começa aberta', $s === 200 && $d['instalacao_aberta'] === true);
[$s, $d] = req('instalar', 'POST', [], ['nome' => 'Intruso', 'email' => 'outro@exemplo.com', 'senha' => 'senhaboa123']);
eq('e-mail que não é o do dono não instala', $s === 403 && $d['error'] === 'email_nao_autorizado');
[$s, $d] = req('instalar', 'POST', [], ['nome' => 'Luiz', 'email' => CDD_EMAIL_INSTALADOR, 'senha' => 'curta']);
eq('senha curta é recusada na instalação', $s === 422);
[$s, $d] = req('instalar', 'POST', [], ['nome' => 'Luiz', 'email' => CDD_EMAIL_INSTALADOR, 'senha' => 'senhaboa123']);
eq('o dono instala e recebe sessão de administrador', $s === 200 && $d['usuario']['papel'] === 'admin' && strlen($d['token']) === 64);
$tAdmin = $d['token'];
[$s, $d] = req('instalar', 'POST', [], ['nome' => 'Luiz2', 'email' => CDD_EMAIL_INSTALADOR, 'senha' => 'senhaboa123']);
eq('depois de instalado, a instalação fecha (403)', $s === 403 && $d['error'] === 'instalacao_fechada');
eq('há um único administrador', (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE papel = 'admin'")->fetchColumn() === 1);

echo "\nCadastro e regra 'só mulheres'\n";
[$s, $d] = req('cadastro', 'POST', [], ['nome' => 'Homem', 'email' => 'h@exemplo.com', 'senha' => 'senhaboa123', 'sexo' => 'masculino', 'aceite' => true]);
eq('masculino é recusado com aviso e indicação da Intus', $s === 403 && $d['error'] === 'exclusivo_mulheres' && strpos($d['intus_url'], 'intusfit.com.br') !== false);
eq('masculino não cria conta', (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'h@exemplo.com'")->fetchColumn() === 0);
[$s, $d] = req('cadastro', 'POST', [], ['nome' => 'Ana', 'email' => 'ana@exemplo.com', 'senha' => 'senhaboa123', 'aceite' => true]);
eq('sexo é obrigatório', $s === 422 && $d['error'] === 'sexo_obrigatorio');
[$s, $d] = req('cadastro', 'POST', [], ['nome' => 'Ana', 'email' => 'ana@exemplo.com', 'senha' => 'senhaboa123', 'sexo' => 'feminino']);
eq('sem aceite dos termos não cadastra', $s === 422 && $d['error'] === 'aceite_obrigatorio');
[$s, $d] = req('cadastro', 'POST', [], ['nome' => 'Ana', 'email' => 'ana@exemplo.com', 'senha' => '123', 'sexo' => 'feminino', 'aceite' => true]);
eq('senha curta é recusada', $s === 422 && $d['error'] === 'senha_curta');
[$s, $d] = req('cadastro', 'POST', [], ['nome' => 'Ana', 'email' => 'ana@exemplo.com', 'senha' => 'senhaboa123', 'sexo' => 'feminino', 'aceite' => true]);
eq('mulher cadastra e recebe sessão de aluna', $s === 200 && $d['usuario']['papel'] === 'aluna');
$tAna = $d['token']; $idAna = $d['usuario']['idaluna'];
$hash = $pdo->query("SELECT senha_hash FROM cdd_aluna WHERE email = 'ana@exemplo.com'")->fetchColumn();
eq('senha é guardada em bcrypt, nunca em texto', strpos($hash, '$2y$') === 0 && strpos($hash, 'senhaboa123') === false);
[$s, $d] = req('cadastro', 'POST', [], ['nome' => 'Ana', 'email' => 'ANA@exemplo.com', 'senha' => 'senhaboa123', 'sexo' => 'feminino', 'aceite' => true]);
eq('e-mail repetido (mesmo em maiúsculas) é recusado', $s === 409);
[$s, $d] = req('admin_nao_existe_a_x');
eq('rota desconhecida sem sessão pede login', $s === 401);

echo "\nLogin e trava de tentativas\n";
[$s, $d] = req('login', 'POST', [], ['email' => 'ana@exemplo.com', 'senha' => 'errada123']);
eq('senha errada = 401 com mensagem genérica', $s === 401 && $d['error'] === 'credenciais_invalidas');
[$s, $d] = req('login', 'POST', [], ['email' => 'naoexiste@exemplo.com', 'senha' => 'errada123']);
eq('conta que não existe responde igual (não revela cadastro)', $s === 401 && $d['error'] === 'credenciais_invalidas');
[$s, $d] = req('login', 'POST', [], ['email' => 'ana@exemplo.com', 'senha' => 'senhaboa123']);
eq('login certo devolve token', $s === 200 && strlen($d['token']) === 64);
for ($i = 0; $i < 9; $i++) req('login', 'POST', [], ['email' => 'bia@exemplo.com', 'senha' => 'x' . $i . 'xxxxxxx'], '', '10.0.0.' . (50 + $i));
[$s, $d] = req('login', 'POST', [], ['email' => 'bia@exemplo.com', 'senha' => 'qualquer123'], '', '10.0.0.99');
eq('8 erros na mesma conta de IPs diferentes bloqueiam a conta (429)', $s === 429);

echo "\nAcesso ao conteúdo exige matrícula\n";
[$s, $d] = req('meus_modulos', 'GET', [], [], $tAna);
eq('conta sem matrícula não vê conteúdo (403 sem_matricula)', $s === 403 && $d['error'] === 'sem_matricula');
[$s, $d] = req('eu', 'GET', [], [], $tAna);
eq('"eu" informa que não há acesso ainda', $s === 200 && $d['acesso'] === false && $d['matricula']['status'] === 'sem_matricula');
[$s, $d] = req('a_resumo', 'GET', [], [], $tAna);
eq('aluna não entra na administração (403)', $s === 403);
[$s, $d] = req('a_importar_seed', 'POST', [], [], $tAna);
eq('aluna não importa conteúdo (403)', $s === 403);
[$s, $d] = req('a_resumo', 'GET', [], [], 'x' . str_repeat('0', 63));
eq('token inventado é recusado (401)', $s === 401);

echo "\nConteúdo, rascunho e ficha (administrador)\n";
[$s, $d] = req('a_modulo_salvar', 'POST', [], ['titulo' => 'Comece por aqui', 'publicado' => 1], $tAdmin); $m1 = $d['idmodulo'];
[$s, $d] = req('a_modulo_salvar', 'POST', [], ['titulo' => 'Rascunho', 'publicado' => 0], $tAdmin); $m2 = $d['idmodulo'];
eq('administrador cria módulos', $s === 200 && $m1 > 0 && $m2 > 0);
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $m1, 'titulo' => 'Boas vindas', 'youtube' => 'https://youtu.be/ESItkjgzn34', 'publicado' => 1,
    'anexos' => [['titulo' => 'Ficha', 'url' => 'https://docs.google.com/spreadsheets/d/abc'], ['titulo' => 'Mau', 'url' => 'javascript:alert(1)']]], $tAdmin);
$a1 = $d['idaula'];
eq('aula criada com link do YouTube convertido em código', $s === 200 && $pdo->query("SELECT youtube_id FROM cdd_aula WHERE idaula = $a1")->fetchColumn() === 'ESItkjgzn34');
eq('anexo com javascript: é descartado, https é mantido', (int)$pdo->query("SELECT COUNT(*) FROM cdd_anexo WHERE idaula = $a1")->fetchColumn() === 1);
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $m1, 'titulo' => 'Com erro', 'youtube' => 'não é link'], $tAdmin);
eq('link do YouTube inválido é recusado', $s === 422 && $d['error'] === 'youtube_invalido');
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $m1, 'titulo' => 'Aula escondida', 'youtube' => 'BSkBbni41pM', 'publicado' => 0], $tAdmin); $a2 = $d['idaula'];
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $m2, 'titulo' => 'Em módulo rascunho', 'youtube' => 'RicEA2i0vUI', 'publicado' => 1], $tAdmin); $a3 = $d['idaula'];

$tsv = "Exercício\t\t\t\t\tRepetições\tSéries\tIntervalo\tMétodo\t\t\tComo fazer\n" .
       "TREINO A - GLÚTEOS\n" .
       "Elevação Espeto\t\t\t\t\t12\t4\t0:30\"\t\t\t\tVer vídeo\n" .
       "\t\t\t\t\t\t\t\t\t\t\t\n" .
       "Extensão de Quadril Cruzada na Polia\t\t\t\t\t8 a 12\t4\t0:45\" a 1min\tDrop set\t\t\thttps://youtu.be/BSkBbni41pM\n";
[$s, $d] = req('a_ficha_colar', 'POST', [], ['texto' => $tsv], $tAdmin);
eq('colar da planilha: lê 2 exercícios e ignora linha vazia', $s === 200 && count($d['itens']) === 2, json_encode($d, JSON_UNESCAPED_UNICODE));
eq('colar da planilha: colunas certas (reps, séries, intervalo)', ($d['itens'][0]['exercicio'] ?? '') === 'Elevação Espeto' && $d['itens'][0]['repeticoes'] === '12' && $d['itens'][0]['series'] === '4' && $d['itens'][0]['intervalo'] === '0:30"');
eq('colar da planilha: "Ver vídeo" sem link vira vazio e link do YouTube vira código', $d['itens'][0]['video'] === '' && $d['itens'][1]['video'] === 'BSkBbni41pM' && $d['itens'][1]['metodo'] === 'Drop set');
eq('colar da planilha: título de treino vira o grupo', $d['itens'][0]['grupo'] === 'TREINO A - GLÚTEOS');
[$s, $d2] = req('a_ficha_salvar', 'POST', [], ['idaula' => $a1, 'itens' => $d['itens']], $tAdmin);
eq('ficha salva com 2 itens', $s === 200 && $d2['itens'] === 2);

echo "\nMatrícula, expiração e leitura da aluna\n";
[$s, $d] = req('a_matricula', 'POST', [], ['idaluna' => $idAna, 'status' => 'ativa', 'expira_em' => date('Y-m-d', time() + 86400 * 30)], $tAdmin);
eq('administrador matricula a aluna', $s === 200 && $d['matricula']['ativa'] === true);
[$s, $d] = req('meus_modulos', 'GET', [], [], $tAna);
eq('aluna matriculada vê só módulo e aula publicados', $s === 200 && count($d['modulos']) === 1 && count($d['modulos'][0]['aulas']) === 1 && $d['modulos'][0]['aulas'][0]['idaula'] === $a1, json_encode($d));
[$s, $d] = req('aula', 'GET', ['id' => $a1], [], $tAna);
eq('aula traz vídeo, anexos e ficha', $s === 200 && $d['aula']['youtube_id'] === 'ESItkjgzn34' && count($d['aula']['anexos']) === 1 && count($d['aula']['ficha']) === 2);
[$s, $d] = req('aula', 'GET', ['id' => $a2], [], $tAna);
eq('aula em rascunho é 404 para a aluna', $s === 404);
[$s, $d] = req('aula', 'GET', ['id' => $a3], [], $tAna);
eq('aula de módulo em rascunho é 404 para a aluna', $s === 404);
[$s, $d] = req('a_aula', 'GET', ['id' => $a2], [], $tAdmin);
eq('o administrador vê o rascunho', $s === 200);
[$s, $d] = req('concluir', 'POST', [], ['id' => $a1, 'feito' => true], $tAna);
[$s, $d] = req('concluir', 'POST', [], ['id' => $a1, 'feito' => true], $tAna);
eq('concluir duas vezes grava uma só', (int)$pdo->query("SELECT COUNT(*) FROM cdd_progresso WHERE idaula = $a1")->fetchColumn() === 1);
[$s, $d] = req('meus_modulos', 'GET', [], [], $tAna);
eq('progresso aparece no módulo (1 de 1)', $d['modulos'][0]['feitas'] === 1 && $d['modulos'][0]['total'] === 1);
[$s, $d] = req('concluir', 'POST', [], ['id' => $a2, 'feito' => true], $tAna);
eq('não conclui aula em rascunho', $s === 404);
[$s, $d] = req('concluir', 'POST', [], ['id' => $a1, 'feito' => false], $tAna);
eq('desmarcar remove o progresso', (int)$pdo->query("SELECT COUNT(*) FROM cdd_progresso WHERE idaula = $a1")->fetchColumn() === 0);

req('a_matricula', 'POST', [], ['idaluna' => $idAna, 'status' => 'ativa', 'expira_em' => date('Y-m-d', time() - 86400)], $tAdmin);
[$s, $d] = req('meus_modulos', 'GET', [], [], $tAna);
eq('matrícula vencida bloqueia (expirada)', $s === 403 && $d['status'] === 'expirada');
req('a_matricula', 'POST', [], ['idaluna' => $idAna, 'status' => 'suspensa'], $tAdmin);
[$s, $d] = req('meus_modulos', 'GET', [], [], $tAna);
eq('matrícula suspensa bloqueia', $s === 403 && $d['status'] === 'suspensa');
[$s, $d] = req('a_matricula', 'POST', [], ['idaluna' => $idAna, 'status' => 'ativa', 'expira_em' => '31/12/2026'], $tAdmin);
eq('data em formato errado é recusada', $s === 422);
req('a_matricula', 'POST', [], ['idaluna' => $idAna, 'status' => 'ativa'], $tAdmin);
eq('matrícula sem data de fim fica ativa', req('meus_modulos', 'GET', [], [], $tAna)[0] === 200);
eq('cada aluna tem uma única matrícula', (int)$pdo->query("SELECT COUNT(*) FROM cdd_matricula WHERE idaluna = $idAna")->fetchColumn() === 1);

echo "\nAluna criada pelo painel e link de ativação\n";
[$s, $d] = req('a_aluna_criar', 'POST', [], ['nome' => 'Carla', 'email' => 'carla@exemplo.com', 'matricular' => true, 'expira_em' => date('Y-m-d', time() + 86400 * 365)], $tAdmin);
eq('painel cria aluna com matrícula e devolve link', $s === 200 && strpos($d['link_ativacao'], 'https://exemplo.test/cdd/index.html#ativar=') === 0);
$tokenAtiv = substr($d['link_ativacao'], strpos($d['link_ativacao'], '#ativar=') + 8); $idCarla = $d['idaluna'];
eq('o token do link não fica guardado em texto (só o hash)', (int)$pdo->query("SELECT COUNT(*) FROM cdd_ativacao WHERE token_hash = '" . $tokenAtiv . "'")->fetchColumn() === 0);
[$s, $d] = req('login', 'POST', [], ['email' => 'carla@exemplo.com', 'senha' => 'qualquercoisa']);
eq('conta sem senha ainda não entra', $s === 401);
[$s, $d] = req('ativar_info', 'GET', ['t' => $tokenAtiv]);
eq('link válido mostra o nome', $s === 200 && $d['nome'] === 'Carla');
[$s, $d] = req('ativar', 'POST', [], ['token' => $tokenAtiv, 'senha' => 'curta']);
eq('ativar com senha curta é recusado', $s === 422);
[$s, $d] = req('ativar', 'POST', [], ['token' => $tokenAtiv, 'senha' => 'senhanova123']);
eq('ativar cria a senha e já entra', $s === 200 && strlen($d['token']) === 64);
[$s, $d] = req('ativar', 'POST', [], ['token' => $tokenAtiv, 'senha' => 'outrasenha123']);
eq('o mesmo link não vale duas vezes', $s === 404);
[$s, $d] = req('login', 'POST', [], ['email' => 'carla@exemplo.com', 'senha' => 'senhanova123']);
eq('depois de ativar, o login funciona', $s === 200); $tCarla = $d['token'];
eq('Carla (matriculada) vê o conteúdo', req('meus_modulos', 'GET', [], [], $tCarla)[0] === 200);
[$s, $d] = req('a_aluna_criar', 'POST', [], ['nome' => 'Carla de novo', 'email' => 'carla@exemplo.com'], $tAdmin);
eq('e-mail repetido no painel é recusado', $s === 409);
[$s, $d] = req('a_aluna_link', 'POST', [], ['idaluna' => $idCarla], $tAdmin);
eq('novo link de acesso pode ser gerado', $s === 200 && isset($d['link_ativacao']));
$tok2 = substr($d['link_ativacao'], strpos($d['link_ativacao'], '#ativar=') + 8);
req('ativar', 'POST', [], ['token' => $tok2, 'senha' => 'senhatrocada123']);
eq('senha nova pelo link derruba a sessão antiga', req('eu', 'GET', [], [], $tCarla)[0] === 401);
$pdo->exec("UPDATE cdd_ativacao SET expira_em = '2000-01-01 00:00:00'");
[$s, $d] = req('a_aluna_link', 'POST', [], ['idaluna' => $idCarla], $tAdmin); $tok3 = substr($d['link_ativacao'], strpos($d['link_ativacao'], '#ativar=') + 8);
$pdo->exec("UPDATE cdd_ativacao SET expira_em = '2000-01-01 00:00:00'");
[$s, $d] = req('ativar_info', 'GET', ['t' => $tok3]);
eq('link vencido é recusado', $s === 404);

echo "\nTrocar senha e bloquear conta\n";
[$s, $d] = req('trocar_senha', 'POST', [], ['atual' => 'errada', 'nova' => 'novasenha123'], $tAna);
eq('trocar senha exige a atual correta', $s === 403);
[$s, $d] = req('trocar_senha', 'POST', [], ['atual' => 'senhaboa123', 'nova' => 'novasenha123'], $tAna);
eq('troca de senha funciona', $s === 200);
[$s, $d] = req('login', 'POST', [], ['email' => 'ana@exemplo.com', 'senha' => 'novasenha123']);
eq('senha nova entra', $s === 200);
[$s, $d] = req('a_aluna_status', 'POST', [], ['idaluna' => $idAna, 'status' => 'bloqueada'], $tAdmin);
eq('bloquear conta derruba as sessões', $s === 200 && req('eu', 'GET', [], [], $tAna)[0] === 401);
eq('conta bloqueada não entra', req('login', 'POST', [], ['email' => 'ana@exemplo.com', 'senha' => 'novasenha123'])[0] === 401);
[$s, $d] = req('a_aluna_status', 'POST', [], ['idaluna' => 1, 'status' => 'bloqueada'], $tAdmin);
eq('o administrador não bloqueia a si mesmo', $s === 422);

echo "\nImportação do conteúdo\n";
$seed = ['modulos' => [['titulo' => 'Módulo Seed', 'aulas' => [['titulo' => 'A', 'youtube' => 'AAAAAAAAAAA', 'anexos' => [['titulo' => 'x', 'url' => 'https://exemplo.com/a']]], ['titulo' => 'B'], ['titulo' => 'Sensível', 'youtube' => 'BBBBBBBBBBB', 'rascunho' => true]]]]];
$r = cddImportarSeed($pdo, $seed);
eq('importa módulo e 3 aulas', $r['modulos_novos'] === 1 && $r['aulas_novas'] === 3);
$r = cddImportarSeed($pdo, $seed);
eq('importar de novo não duplica nada', $r['modulos_novos'] === 0 && $r['aulas_novas'] === 0 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_anexo WHERE url = 'https://exemplo.com/a'")->fetchColumn() === 1);
$seed['modulos'][0]['aulas'][1]['youtube'] = 'CCCCCCCCCCC';
$r = cddImportarSeed($pdo, $seed);
eq('importar de novo preenche o vídeo que faltava', $r['aulas_atualizadas'] === 1);
eq('aula marcada como rascunho entra escondida', (int)$pdo->query("SELECT publicado FROM cdd_aula WHERE titulo = 'Sensível'")->fetchColumn() === 0);
$antes = (int)$pdo->query("SELECT COUNT(*) FROM cdd_ficha_item WHERE idaula = $a1")->fetchColumn();
cddImportarSeed($pdo, ['modulos' => [['titulo' => 'Comece por aqui', 'aulas' => [['titulo' => 'Boas vindas', 'youtube' => 'ZZZZZZZZZZZ']]]]]);
eq('importar não apaga a ficha editada nem troca vídeo que já existe', (int)$pdo->query("SELECT COUNT(*) FROM cdd_ficha_item WHERE idaula = $a1")->fetchColumn() === $antes && $pdo->query("SELECT youtube_id FROM cdd_aula WHERE idaula = $a1")->fetchColumn() === 'ESItkjgzn34');

echo "\nOrdem e exclusão (painel)\n";
[$s, $d] = req('a_ordem', 'POST', [], ['tipo' => 'modulos', 'ids' => [$m2, $m1]], $tAdmin);
eq('reordenar módulos', $s === 200 && (int)$pdo->query("SELECT ordem FROM cdd_modulo WHERE idmodulo = $m2")->fetchColumn() < (int)$pdo->query("SELECT ordem FROM cdd_modulo WHERE idmodulo = $m1")->fetchColumn());
[$s, $d] = req('a_aula_excluir', 'POST', [], ['idaula' => $a1], $tAdmin);
eq('excluir aula leva anexos e ficha junto', (int)$pdo->query("SELECT COUNT(*) FROM cdd_anexo WHERE idaula = $a1")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_ficha_item WHERE idaula = $a1")->fetchColumn() === 0);
[$s, $d] = req('a_config', 'POST', [], ['cadastro_aberto' => false], $tAdmin);
[$s2, $d2] = req('cadastro', 'POST', [], ['nome' => 'Nova', 'email' => 'nova@exemplo.com', 'senha' => 'senhaboa123', 'sexo' => 'feminino', 'aceite' => true]);
eq('cadastro pode ser fechado pelo painel', $s2 === 403 && $d2['error'] === 'cadastro_fechado');

echo "\nUtilidades\n";
eq('YouTube: código puro', cddYoutubeId('BSkBbni41pM') === 'BSkBbni41pM');
eq('YouTube: link youtu.be', cddYoutubeId('https://youtu.be/BSkBbni41pM?t=10') === 'BSkBbni41pM');
eq('YouTube: link watch', cddYoutubeId('https://www.youtube.com/watch?v=BSkBbni41pM&list=x') === 'BSkBbni41pM');
eq('YouTube: embed', cddYoutubeId('https://www.youtube.com/embed/BSkBbni41pM?rel=0') === 'BSkBbni41pM');
eq('YouTube: texto qualquer vira null', cddYoutubeId('oi') === null);
eq('URL: só https', cddUrl('http://x.com') === '' && cddUrl('javascript:alert(1)') === '' && cddUrl('https://x.com/a') === 'https://x.com/a');

echo "\nFichas importadas e base de exercícios\n";
$seed2 = ['exercicios' => [['nome' => 'Elevação Pélvica', 'video' => 'https://youtu.be/btkguzN-j9Q'], ['nome' => 'Stiff', 'video' => '']],
    'modulos' => [['titulo' => 'Protocolo X', 'grupo' => 'protocolo', 'aulas' => [
        ['titulo' => 'Treino A', 'youtube' => 'AAAAAAAAAAA', 'ficha' => [
            ['grupo' => 'TREINO A', 'exercicio' => 'Elevação Pélvica', 'series' => '4', 'repeticoes' => '12', 'intervalo' => '0:30"', 'metodo' => '', 'video' => ''],
            ['grupo' => 'TREINO A', 'exercicio' => 'Stiff', 'series' => '3', 'repeticoes' => '8 a 12', 'video' => 'https://youtu.be/nncwJiJDivc'],
            ['grupo' => 'TREINO A', 'exercicio' => 'Tarefa de casa', 'video' => '']]]]]]];
$r = cddImportarSeed($pdo, $seed2);
eq('importa a ficha da aula (3 itens) e 2 exercícios novos na base', $r['fichas_novas'] === 1 && $r['exercicios_novos'] === 2 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_ficha_item")->fetchColumn() >= 3);
$r = cddImportarSeed($pdo, $seed2);
eq('importar de novo não duplica ficha nem exercício', $r['fichas_novas'] === 0 && $r['exercicios_novos'] === 0);
$idTreino = (int)$pdo->query("SELECT idaula FROM cdd_aula WHERE titulo = 'Treino A'")->fetchColumn();
$pdo->exec("UPDATE cdd_ficha_item SET exercicio = 'Stiff editado' WHERE idaula = $idTreino AND exercicio = 'Stiff'");
cddImportarSeed($pdo, $seed2);
eq('importar não desfaz a edição da ficha', (int)$pdo->query("SELECT COUNT(*) FROM cdd_ficha_item WHERE idaula = $idTreino AND exercicio = 'Stiff editado'")->fetchColumn() === 1);
$pdo->exec("UPDATE cdd_ficha_item SET exercicio = 'Stiff' WHERE idaula = $idTreino AND exercicio = 'Stiff editado'");
req('a_matricula', 'POST', [], ['idaluna' => $idAna, 'status' => 'ativa'], $tAdmin);
req('a_aluna_status', 'POST', [], ['idaluna' => $idAna, 'status' => 'ativa'], $tAdmin);
[$s, $d] = req('login', 'POST', [], ['email' => 'ana@exemplo.com', 'senha' => 'novasenha123']); $tAna2 = $d['token'] ?? '';
[$s, $d] = req('aula', 'GET', ['id' => $idTreino], [], $tAna2);
eq('aluna vê a ficha com 3 itens', $s === 200 && count($d['aula']['ficha']) === 3, json_encode($d));
$f = []; foreach ($d['aula']['ficha'] ?? [] as $it) $f[$it['exercicio']] = $it;
eq('item sem vídeo herda o vídeo da base', ($f['Elevação Pélvica']['video'] ?? '') === 'btkguzN-j9Q');
eq('item com vídeo próprio mantém o seu', ($f['Stiff']['video'] ?? '') === 'nncwJiJDivc');
[$s, $d] = req('a_aula', 'GET', ['id' => $idTreino], [], $tAdmin);
$fa = []; foreach ($d['aula']['ficha'] ?? [] as $it) $fa[$it['exercicio']] = $it;
eq('no editor da equipe o item continua vazio (o vídeo da base não é gravado na ficha)', ($fa['Elevação Pélvica']['video'] ?? 'x') === '');
eq('cddNorm ignora acento, caixa e pontuação', cddNorm('Elevação Pélvica (deitada)') === cddNorm('elevacao pelvica deitada') && cddNorm('Stiff') === 'stiff');

[$s, $d] = req('a_exercicios', 'GET', ['busca' => 'stiff'], [], $tAdmin);
eq('lista e busca exercícios da base', $s === 200 && count($d['exercicios']) === 1 && $d['total'] >= 2);
[$s, $d] = req('a_exercicios', 'GET', [], [], $tAna2);
eq('aluna não acessa a base de exercícios da equipe', $s === 403);
[$s, $d] = req('a_exercicio_salvar', 'POST', [], ['nome' => 'Remada Curvada', 'grupo' => 'Costas', 'video' => 'https://youtu.be/BSkBbni41pM'], $tAdmin); $idRem = $d['idexercicio'];
eq('cria exercício na base', $s === 200 && $idRem > 0);
[$s, $d] = req('a_exercicio_salvar', 'POST', [], ['nome' => 'remada  curvada!'], $tAdmin);
eq('nome repetido (mesmo com acento ou pontuação diferente) é recusado', $s === 409);
[$s, $d] = req('a_exercicio_salvar', 'POST', [], ['idexercicio' => $idRem, 'nome' => 'Remada Curvada', 'video' => 'não é link'], $tAdmin);
eq('vídeo inválido é recusado', $s === 422);
[$s, $d] = req('a_exercicio_salvar', 'POST', [], ['idexercicio' => $idRem, 'nome' => 'Remada Curvada Barra', 'grupo' => 'Costas', 'video' => 'BSkBbni41pM'], $tAdmin);
eq('edita exercício', $s === 200 && $pdo->query("SELECT nome FROM cdd_exercicio WHERE idexercicio = $idRem")->fetchColumn() === 'Remada Curvada Barra');

// Sincronização com a Intus (catálogo simulado; só leitura)
[$s, $d] = req('a_exercicios_sync_intus', 'POST', [], [], $tAdmin);
eq('sem o catálogo da Intus no banco, avisa em vez de quebrar (404)', $s === 404 && $d['error'] === 'intus_indisponivel');
$pdo->exec("CREATE TABLE intus_exercicio (idexercicio INTEGER PRIMARY KEY, nmexercicio TEXT, grupo TEXT, equipamento TEXT, descricao TEXT, videoyoutube TEXT)");
$pdo->exec("INSERT INTO intus_exercicio VALUES (1, 'Supino Reto', 'Peito', 'barra', 'Controle a descida', 'https://youtube.com/watch?v=kj_yrgzG5WM'), (2, 'Stiff', 'Posterior', NULL, NULL, 'https://youtube.com/watch?v=AAAAAAAAAAB'), (3, 'Elevação Pélvica', 'Glúteo', 'peso do corpo', NULL, ''), (4, '   ', 'x', NULL, NULL, NULL)");
$antesIntus = $pdo->query("SELECT COUNT(*) || '|' || SUM(idexercicio) || '|' || GROUP_CONCAT(nmexercicio) FROM intus_exercicio")->fetchColumn();
[$s, $d] = req('a_exercicios_sync_intus', 'POST', [], [], $tAdmin);
eq('sincroniza: lê 4, cria 1, atualiza 2, ignora a linha sem nome', $s === 200 && $d['lidos'] === 4 && $d['novos'] === 1 && $d['atualizados'] === 2 && $d['ignorados'] === 1, json_encode($d));
$st = $pdo->query("SELECT * FROM cdd_exercicio WHERE nome_norm = 'stiff'")->fetch();
eq('a Intus é a fonte principal: o vídeo e o grupo dela substituem os do CDD', $st['video'] === 'AAAAAAAAAAB' && $st['grupo'] === 'Posterior' && $st['origem'] === 'intus' && (int)$st['id_intus'] === 2);
$st = $pdo->query("SELECT * FROM cdd_exercicio WHERE nome_norm = 'elevacaopelvica'")->fetch();
eq('campo vazio na Intus não apaga o vídeo que o CDD já tinha', $st['video'] === 'btkguzN-j9Q' && $st['grupo'] === 'Glúteo' && $st['equipamento'] === 'peso do corpo');
eq('exercício que veio só da Intus entra com origem intus', $pdo->query("SELECT origem FROM cdd_exercicio WHERE nome_norm = 'supinoreto'")->fetchColumn() === 'intus');
eq('exercício criado só no CDD continua intacto', $pdo->query("SELECT origem FROM cdd_exercicio WHERE idexercicio = $idRem")->fetchColumn() === 'cdd');
$depoisIntus = $pdo->query("SELECT COUNT(*) || '|' || SUM(idexercicio) || '|' || GROUP_CONCAT(nmexercicio) FROM intus_exercicio")->fetchColumn();
eq('a tabela da Intus não é alterada (só leitura)', $antesIntus === $depoisIntus);
[$s, $d] = req('a_exercicios_sync_intus', 'POST', [], [], $tAdmin);
eq('sincronizar de novo não muda nada', $d['novos'] === 0 && $d['atualizados'] === 0 && $d['iguais'] === 3, json_encode($d));
[$s, $d] = req('a_exercicios_sync_intus', 'POST', [], [], $tAna2);
eq('aluna não sincroniza', $s === 403);
[$s, $d] = req('a_exercicio_excluir', 'POST', [], ['idexercicio' => $idRem], $tAdmin);
eq('exclui exercício da base', (int)$pdo->query("SELECT COUNT(*) FROM cdd_exercicio WHERE idexercicio = $idRem")->fetchColumn() === 0);
$pdo->exec('DROP TABLE intus_exercicio');

echo "\nAtualização do esquema (instalação que já existia)\n";
$novo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
cddGarantirTabelas($novo);
eq('instalação nova cria todas as tabelas e grava a versão do esquema', (int)$novo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'cdd%'")->fetchColumn() === count(cddDdl()) && cddConfig($novo, 'esquema') === (string)CDD_ESQUEMA && cddConfig($novo, 'instalacao_aberta') === '1');
$antigo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ddl = cddDdl();
$ddl['cdd_modulo'] = str_replace([",\n            liberacao_dias INT NULL", ",\n            liberacao_data DATE NULL"], '', $ddl['cdd_modulo']);   // como era no esquema 1
foreach ($ddl as $nome => $sql) { if (!in_array($nome, ['cdd_exercicio', 'cdd_treino', 'cdd_comentario', 'cdd_avaliacao'], true)) $antigo->exec(cddDdlSqlite($sql)); }
$antigo->exec("INSERT INTO cdd_config (chave, valor) VALUES ('instalacao_aberta', '0')");
$antigo->exec("INSERT INTO cdd_aluna (nome, email, papel, status, criado_em) VALUES ('Existente', 'e@e.com', 'aluna', 'ativa', '2026-10-15 10:00:00')");
$antigo->exec("INSERT INTO cdd_modulo (titulo, grupo, ordem, publicado) VALUES ('Modulo Antigo', 'curso', 10, 1)");
cddGarantirTabelas($antigo);
eq('instalação antiga (esquema 1) ganha as tabelas e colunas novas sem perder nada', (int)$antigo->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('cdd_exercicio', 'cdd_treino', 'cdd_comentario', 'cdd_avaliacao')")->fetchColumn() === 4 && (int)$antigo->query('SELECT COUNT(*) FROM cdd_aluna')->fetchColumn() === 1 && cddConfig($antigo, 'instalacao_aberta') === '0' && cddConfig($antigo, 'esquema') === (string)CDD_ESQUEMA);
$antigo->exec("UPDATE cdd_modulo SET liberacao_dias = 5");
eq('a coluna de liberação existe e o módulo antigo continua lá', $antigo->query("SELECT titulo FROM cdd_modulo WHERE liberacao_dias = 5")->fetchColumn() === 'Modulo Antigo');

function novaAluna($nome, $email, $matricular = true) {
    global $tAdmin;
    [$s, $d] = req('a_aluna_criar', 'POST', [], ['nome' => $nome, 'email' => $email, 'matricular' => $matricular], $tAdmin);
    $tk = substr($d['link_ativacao'], strpos($d['link_ativacao'], '#ativar=') + 8);
    [$s2, $d2] = req('ativar', 'POST', [], ['token' => $tk, 'senha' => 'senhasegura123']);
    return [$d['idaluna'], $d2['token']];
}

echo "\nLiberação de módulos por tempo\n";
eq('regra: sem dias nem data = liberado', cddModuloLiberacao(['liberacao_dias' => null, 'liberacao_data' => null], '2026-10-01')['liberado'] === true);
$r = cddModuloLiberacao(['liberacao_dias' => 20, 'liberacao_data' => null], date('Y-m-d', time() - 25 * 86400));
eq('regra: 20 dias depois de uma matrícula de 25 dias atrás = liberado', $r['liberado'] === true);
$r = cddModuloLiberacao(['liberacao_dias' => 20, 'liberacao_data' => null], date('Y-m-d', time() - 5 * 86400));
eq('regra: 20 dias depois de uma matrícula de 5 dias atrás = libera em 15 dias', $r['liberado'] === false && $r['libera_em'] === date('Y-m-d', time() + 15 * 86400));
$r = cddModuloLiberacao(['liberacao_dias' => 1, 'liberacao_data' => date('Y-m-d', time() + 40 * 86400)], date('Y-m-d', time() - 30 * 86400));
eq('regra: com dias e data vale a mais tardia', $r['liberado'] === false && $r['libera_em'] === date('Y-m-d', time() + 40 * 86400));

[$idLiv, $tLiv] = novaAluna('Livia Teste', 'livia@exemplo.com');
[$s, $d] = req('a_modulo_salvar', 'POST', [], ['titulo' => 'Protocolo Trancado', 'grupo' => 'protocolo', 'publicado' => 1, 'liberacao_dias' => 10], $tAdmin); $mT = $d['idmodulo'];
eq('painel salva a regra de liberação por dias', $s === 200 && (int)$pdo->query("SELECT liberacao_dias FROM cdd_modulo WHERE idmodulo = $mT")->fetchColumn() === 10);
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Treino Trancado', 'youtube' => 'AAAAAAAAAAA', 'publicado' => 1], $tAdmin); $aT = $d['idaula'];
req('a_ficha_salvar', 'POST', [], ['idaula' => $aT, 'itens' => [['exercicio' => 'Agachamento', 'series' => '3', 'repeticoes' => '10']]], $tAdmin);
[$s, $d] = req('a_modulo_salvar', 'POST', [], ['titulo' => 'Protocolo Data Ruim', 'liberacao_data' => '31/12/2026', 'publicado' => 1], $tAdmin);
eq('data de liberação em formato errado é recusada', $s === 422);
[$s, $d] = req('meus_modulos', 'GET', [], [], $tLiv);
$mm = null; foreach ($d['modulos'] as $x) { if ($x['titulo'] === 'Protocolo Trancado') $mm = $x; }
eq('aluna nova vê o módulo trancado com a data, sem as aulas', $mm && $mm['liberado'] === false && $mm['libera_em'] === date('Y-m-d', time() + 10 * 86400) && $mm['aulas'] === [] && $mm['total'] === 1, json_encode($mm));
[$s, $d] = req('aula', 'GET', ['id' => $aT], [], $tLiv);
eq('abrir aula de módulo trancado = 403 com a data (o servidor trava, não só a tela)', $s === 403 && $d['error'] === 'modulo_bloqueado' && $d['libera_em'] === date('Y-m-d', time() + 10 * 86400));
foreach ([['treino', 'GET', ['id' => $aT], []], ['treino_salvar', 'POST', [], ['idaula' => $aT, 'itens' => [['exercicio' => 'Agachamento', 'series' => [['peso' => '10', 'reps' => '10']]]]]],
          ['concluir', 'POST', [], ['id' => $aT, 'feito' => true]], ['comentarios', 'GET', ['id' => $aT], []], ['comentar', 'POST', [], ['id' => $aT, 'texto' => 'oi tudo bem']], ['avaliar', 'POST', [], ['id' => $aT, 'nota' => 5]]] as $x) {
    [$s, $d] = req($x[0], $x[1], $x[2], $x[3], $tLiv);
    eq('módulo trancado também bloqueia: ' . $x[0], $s === 403 && $d['error'] === 'modulo_bloqueado');
}
[$s, $d] = req('a_conteudo', 'GET', [], [], $tAdmin);
$mA = null; foreach ($d['modulos'] as $x) { if ($x['titulo'] === 'Protocolo Trancado') $mA = $x; }
eq('a equipe vê o módulo com a regra e as aulas', $mA && $mA['liberacao_dias'] === 10 && count($mA['aulas']) === 1);
$pdo->exec("UPDATE cdd_matricula SET inicio = '" . date('Y-m-d', time() - 11 * 86400) . "' WHERE idaluna = $idLiv");
[$s, $d] = req('aula', 'GET', ['id' => $aT], [], $tLiv);
eq('passados os 10 dias da matrícula, a aula abre', $s === 200 && $d['aula']['titulo'] === 'Treino Trancado');
req('a_modulo_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Protocolo Trancado', 'grupo' => 'protocolo', 'publicado' => 1, 'liberacao_dias' => '', 'liberacao_data' => date('Y-m-d', time() + 5 * 86400)], $tAdmin);
[$s, $d] = req('aula', 'GET', ['id' => $aT], [], $tLiv);
eq('liberação por data futura volta a trancar', $s === 403);
req('a_modulo_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Protocolo Trancado', 'grupo' => 'protocolo', 'publicado' => 1, 'liberacao_dias' => '', 'liberacao_data' => ''], $tAdmin);
eq('limpar a regra libera o módulo', req('aula', 'GET', ['id' => $aT], [], $tLiv)[0] === 200);
$seedLib = ['modulos' => [['titulo' => 'Módulo do Seed', 'liberacao_dias' => 45, 'aulas' => [['titulo' => 'X']]], ['titulo' => 'Módulo do Seed 2', 'liberacao_data' => '2025-11-03', 'aulas' => [['titulo' => 'Y']]]]];
cddImportarSeed($pdo, $seedLib);
eq('importação grava a regra de liberação (dias e data)', (int)$pdo->query("SELECT liberacao_dias FROM cdd_modulo WHERE titulo = 'Módulo do Seed'")->fetchColumn() === 45 && $pdo->query("SELECT liberacao_data FROM cdd_modulo WHERE titulo = 'Módulo do Seed 2'")->fetchColumn() === '2025-11-03');
req('a_modulo_salvar', 'POST', [], ['idmodulo' => (int)$pdo->query("SELECT idmodulo FROM cdd_modulo WHERE titulo = 'Módulo do Seed'")->fetchColumn(), 'titulo' => 'Módulo do Seed', 'liberacao_dias' => 7, 'publicado' => 1], $tAdmin);
cddImportarSeed($pdo, $seedLib);
eq('importar de novo não desfaz a regra editada pela equipe', (int)$pdo->query("SELECT liberacao_dias FROM cdd_modulo WHERE titulo = 'Módulo do Seed'")->fetchColumn() === 7);

echo "\nTreino do dia: cargas, repetições, recordes e jornada\n";
[$s, $d] = req('a_modulo_salvar', 'POST', [], ['titulo' => 'Protocolo Treino', 'grupo' => 'protocolo', 'publicado' => 1], $tAdmin); $mTr = $d['idmodulo'];
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $mTr, 'titulo' => 'Treino A', 'youtube' => 'AAAAAAAAAAA', 'publicado' => 1], $tAdmin); $aA = $d['idaula'];
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $mTr, 'titulo' => 'Treino B', 'youtube' => 'BBBBBBBBBBB', 'publicado' => 1], $tAdmin); $aB = $d['idaula'];
$fichaTreino = [['grupo' => 'TREINO', 'exercicio' => 'Agachamento Livre', 'series' => '4', 'repeticoes' => '8 a 12', 'intervalo' => '0:45"'], ['grupo' => 'TREINO', 'exercicio' => 'Stiff', 'series' => 'Super-Série 3x cada', 'repeticoes' => '12'], ['grupo' => 'TREINO', 'exercicio' => 'Panturrilha', 'series' => '', 'repeticoes' => '15']];
req('a_ficha_salvar', 'POST', [], ['idaula' => $aA, 'itens' => $fichaTreino], $tAdmin);
req('a_ficha_salvar', 'POST', [], ['idaula' => $aB, 'itens' => [['exercicio' => 'Remada Baixa', 'series' => '3', 'repeticoes' => '12']]], $tAdmin);
[$idTre, $tTre] = novaAluna('Tatiana Treino', 'tatiana@exemplo.com');
[$s, $d] = req('meus_modulos', 'GET', [], [], $tTre);
eq('aluna nova: o treino de hoje sugere uma ficha de protocolo liberado', $s === 200 && $d['treino_hoje'] !== null && $d['treino_hoje']['treinou_hoje'] === false, json_encode($d['treino_hoje'] ?? null));
[$s, $d] = req('treino', 'GET', ['id' => $aA], [], $tTre);
eq('tela do treino traz a ficha com a quantidade de séries de cada exercício', $s === 200 && $d['aula']['ficha'][0]['qtd_series'] === 4 && $d['aula']['ficha'][1]['qtd_series'] === 3 && $d['aula']['ficha'][2]['qtd_series'] === 3 && $d['historico'] === [] && $d['hoje'] === null, json_encode($d['aula']['ficha'] ?? null));
[$s, $d] = req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '', 'reps' => ''], ['peso' => '', 'reps' => '']]]]], $tTre);
eq('treino sem nenhuma carga ou repetição não salva', $s === 422 && $d['error'] === 'treino_vazio');
$itens1 = [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '20', 'reps' => '12'], ['peso' => '22,5', 'reps' => '10'], ['peso' => 'abc', 'reps' => '1000'], ['peso' => '', 'reps' => '8']]],
           ['exercicio' => 'Stiff', 'series' => [['peso' => '30', 'reps' => '12']]], ['exercicio' => 'Panturrilha', 'series' => []]];
[$s, $d] = req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => $itens1, 'duracao_seg' => 3300, 'esforco' => 4, 'comentario' => 'Foi bom'], $tTre);
eq('salva o treino com resumo de séries e volume', $s === 200 && $d['resumo']['exercicios'] === 2 && $d['resumo']['series'] === 4 && $d['resumo']['volume_kg'] === 825 && $d['recordes'] === [], json_encode($d));
$linha = $pdo->query("SELECT * FROM cdd_treino WHERE idaluna = $idTre")->fetch();
$it = json_decode($linha['itens'], true);
eq('carga com vírgula vira ponto e valor inválido vira vazio', $it[0]['series'][1]['peso'] === '22.5' && $it[0]['series'][2]['peso'] === '' && $it[0]['series'][2]['reps'] === '');
eq('o treino guarda a mesma estrutura da Intus (itens com exercicio e series peso/reps), duração, esforço e comentário', $linha['duracao_seg'] == 3300 && $linha['esforco'] == 4 && $linha['comentario'] === 'Foi bom' && isset($it[0]['series'][0]['peso'], $it[0]['series'][0]['reps']));
eq('treino salvo marca a aula como concluída', (int)$pdo->query("SELECT COUNT(*) FROM cdd_progresso WHERE idaluna = $idTre AND idaula = $aA")->fetchColumn() === 1);
[$s, $d] = req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => $itens1], $tTre);
eq('salvar de novo no mesmo dia atualiza a sessão em vez de duplicar', (int)$pdo->query("SELECT COUNT(*) FROM cdd_treino WHERE idaluna = $idTre")->fetchColumn() === 1);
[$s, $d] = req('treino', 'GET', ['id' => $aA], [], $tTre);
eq('ao reabrir o treino do dia, as cargas anotadas voltam preenchidas', $d['hoje'] !== null && $d['hoje']['itens'][0]['series'][0]['peso'] === '20');
$pdo->exec("UPDATE cdd_treino SET data = '" . date('Y-m-d', time() - 7 * 86400) . "' WHERE idaluna = $idTre");
[$s, $d] = req('treino', 'GET', ['id' => $aA], [], $tTre);
$ag = $d['historico'][cddNorm('Agachamento Livre')] ?? null;
eq('na semana seguinte a tela mostra a última carga e o recorde de cada exercício', $ag && $ag['ultimo']['series'][0]['peso'] === '20' && $ag['max']['peso'] === 22.5 && $d['hoje'] === null, json_encode($ag));
$itens2 = [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '25', 'reps' => '10']]], ['exercicio' => 'Stiff', 'series' => [['peso' => '30', 'reps' => '12']]], ['exercicio' => 'Exercício Novo', 'series' => [['peso' => '50', 'reps' => '8']]]];
[$s, $d] = req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => $itens2], $tTre);
eq('recorde pessoal só quando bate a maior carga anterior (exercício novo e carga igual não contam)', $s === 200 && count($d['recordes']) === 1 && $d['recordes'][0]['exercicio'] === 'Agachamento Livre' && $d['recordes'][0]['peso'] === 25.0 && $d['recordes'][0]['anterior'] === 22.5, json_encode($d['recordes'] ?? null));
[$s, $d] = req('meus_modulos', 'GET', [], [], $tTre);
eq('depois do treino A, o treino de hoje sugere a próxima ficha do mesmo protocolo (B)', $d['treino_hoje']['idaula'] === $aB && $d['treino_hoje']['treinou_hoje'] === true && $d['treinos_semana'] === 1, json_encode($d['treino_hoje']));
[$s, $d] = req('jornada', 'GET', [], [], $tTre); $j = $d['jornada'];
eq('jornada: totais, calendário de 8 semanas e sequência', $s === 200 && $j['treinos_total'] === 2 && count($j['calendario']) === 56 && $j['treinos_semana'] === 1 && $j['sequencia_semanas'] >= 1 && $j['dia_do_programa'] >= 1);
$ev = []; foreach ($j['evolucao'] as $e) $ev[$e['exercicio']] = $e;
eq('jornada: evolução de carga por exercício (primeira, atual e máxima)', ($ev['Agachamento Livre']['primeira'] ?? 0) == 22.5 && ($ev['Agachamento Livre']['atual'] ?? 0) == 25 && $ev['Agachamento Livre']['sessoes'] === 2, json_encode($ev));
eq('jornada: lista os treinos recentes e os módulos com estado de liberação', count($j['recentes']) === 2 && count($j['modulos']) >= 2 && isset($j['modulos'][0]['liberado']));
[$idOut, $tOut] = novaAluna('Outra Aluna', 'outra@exemplo.com');
[$s, $d] = req('treino_excluir', 'POST', [], ['idtreino' => (int)$pdo->query("SELECT MAX(idtreino) FROM cdd_treino")->fetchColumn()], $tOut);
eq('uma aluna não apaga o treino de outra', (int)$pdo->query("SELECT COUNT(*) FROM cdd_treino")->fetchColumn() === 2);
[$s, $d] = req('treino_excluir', 'POST', [], ['idtreino' => (int)$pdo->query("SELECT MAX(idtreino) FROM cdd_treino")->fetchColumn()], $tTre);
eq('a aluna apaga o próprio treino', (int)$pdo->query("SELECT COUNT(*) FROM cdd_treino")->fetchColumn() === 1);
[$idSem, $tSem] = novaAluna('Sem Matricula', 'sem@exemplo.com', false);
eq('sem matrícula, nenhuma rota de treino abre', req('treino', 'GET', ['id' => $aA], [], $tSem)[0] === 403 && req('jornada', 'GET', [], [], $tSem)[0] === 403 && req('comentarios', 'GET', ['id' => $aA], [], $tSem)[0] === 403);

echo "\nComentários e avaliações\n";
[$s, $d] = req('comentar', 'POST', [], ['id' => $aA, 'texto' => 'a'], $tTre);
eq('comentário curto demais é recusado', $s === 422 && $d['error'] === 'comentario_curto');
[$s, $d] = req('comentar', 'POST', [], ['id' => $aA, 'texto' => 'Adorei este treino, o glúteo ficou queimando!'], $tTre);
eq('aluna comenta e recebe a lista', $s === 200 && count($d['comentarios']) === 1 && $d['comentarios'][0]['autor'] === 'Tatiana T.' && $d['comentarios'][0]['meu'] === true && $d['comentarios'][0]['equipe'] === false, json_encode($d));
$idC = $d['comentarios'][0]['id'];
[$s, $d] = req('comentarios', 'GET', ['id' => $aA], [], $tOut);
eq('outra aluna vê o comentário, que não é dela', $s === 200 && count($d['comentarios']) === 1 && $d['comentarios'][0]['meu'] === false && !isset($d['comentarios'][0]['email']));
[$s, $d] = req('comentar', 'POST', [], ['id' => $aA, 'texto' => 'Também amei, treino puxado!', 'parent' => $idC], $tOut);
eq('aluna responde a um comentário', $s === 200 && count($d['comentarios'][0]['respostas']) === 1);
$idResp = $d['comentarios'][0]['respostas'][0]['id'];
[$s, $d] = req('comentar', 'POST', [], ['id' => $aA, 'texto' => 'Resposta da resposta', 'parent' => $idResp], $tOut);
eq('responder a uma resposta é recusado (um nível só)', $s === 422);
[$s, $d] = req('comentar', 'POST', [], ['id' => $aB, 'texto' => 'Resposta em outra aula', 'parent' => $idC], $tOut);
eq('responder a comentário de outra aula é recusado', $s === 422);
[$s, $d] = req('a_comentarios', 'GET', ['filtro' => 'sem_resposta'], [], $tTre);
eq('aluna não acessa a moderação (403)', $s === 403);
[$s, $d] = req('a_comentarios', 'GET', ['filtro' => 'sem_resposta'], [], $tAdmin);
eq('a equipe vê o comentário sem resposta da equipe, com a aula e a aluna', $s === 200 && count($d['comentarios']) === 1 && $d['comentarios'][0]['aula'] === 'Treino A' && $d['comentarios'][0]['email'] === 'tatiana@exemplo.com' && count($d['comentarios'][0]['respostas']) === 1);
[$s, $d] = req('a_resumo', 'GET', [], [], $tAdmin);
eq('resumo conta comentários pendentes e treinos da semana', $d['comentarios_sem_resposta'] === 1 && $d['treinos_7_dias'] >= 0);
[$s, $d] = req('a_comentario_responder', 'POST', [], ['id' => $idC, 'texto' => 'Que bom, Tatiana! Continue assim.'], $tAdmin);
eq('a equipe responde', $s === 200);
[$s, $d] = req('comentarios', 'GET', ['id' => $aA], [], $tTre);
$eqResp = array_values(array_filter($d['comentarios'][0]['respostas'], function ($r) { return $r['equipe']; }));
eq('resposta da equipe aparece como "Equipe CDD"', count($eqResp) === 1 && $eqResp[0]['autor'] === 'Equipe CDD');
[$s, $d] = req('a_comentarios', 'GET', ['filtro' => 'sem_resposta'], [], $tAdmin);
eq('depois de respondido, sai da lista de pendentes', $s === 200 && count($d['comentarios']) === 0);
[$s, $d] = req('comentario_excluir', 'POST', [], ['id' => $idC], $tOut);
eq('aluna não apaga comentário de outra', $s === 403);
[$s, $d] = req('comentario_excluir', 'POST', [], ['id' => $idResp], $tOut);
[$s, $d] = req('comentarios', 'GET', ['id' => $aA], [], $tTre);
eq('aluna apaga o próprio comentário (some da lista)', count(array_filter($d['comentarios'][0]['respostas'], function ($r) { return !$r['equipe']; })) === 0);
[$s, $d] = req('a_comentario_ocultar', 'POST', [], ['id' => $idC, 'oculto' => true], $tAdmin);
[$s, $d] = req('comentarios', 'GET', ['id' => $aA], [], $tTre);
eq('a equipe oculta um comentário e as respostas dele saem junto', $d['comentarios'] === []);
[$s, $d] = req('a_comentario_ocultar', 'POST', [], ['id' => $idC, 'oculto' => false], $tAdmin);
eq('a equipe desfaz a ocultação', count(req('comentarios', 'GET', ['id' => $aA], [], $tTre)[1]['comentarios']) === 1);
for ($i = 0; $i < 12; $i++) $r = req('comentar', 'POST', [], ['id' => $aA, 'texto' => 'Comentário número ' . $i . ' de spam'], $tOut);
eq('muitos comentários seguidos da mesma aluna são barrados (429)', $r[0] === 429);

[$s, $d] = req('avaliar', 'POST', [], ['id' => $aA, 'nota' => 0], $tTre);
eq('nota fora de 1 a 5 é recusada', $s === 422);
req('avaliar', 'POST', [], ['id' => $aA, 'nota' => 4], $tTre);
req('avaliar', 'POST', [], ['id' => $aA, 'nota' => 5], $tTre);
eq('avaliar de novo troca a nota (uma por aluna e aula)', (int)$pdo->query("SELECT COUNT(*) FROM cdd_avaliacao WHERE idaluna = $idTre AND idaula = $aA")->fetchColumn() === 1 && (int)$pdo->query("SELECT nota FROM cdd_avaliacao WHERE idaluna = $idTre AND idaula = $aA")->fetchColumn() === 5);
[$s, $d] = req('avaliar', 'POST', [], ['id' => $aA, 'nota' => 3], $tOut);
[$s, $d] = req('comentarios', 'GET', ['id' => $aA], [], $tTre);
eq('média e total de votos aparecem junto da minha nota', $d['media'] === 4.0 && $d['votos'] === 2 && $d['minha_nota'] === 5);
[$s, $d] = req('aula', 'GET', ['id' => $aA], [], $tTre);
eq('a aula já devolve a minha nota', $d['aula']['minha_nota'] === 5);
[$s, $d] = req('a_conteudo', 'GET', [], [], $tAdmin);
$aulaA = null; foreach ($d['modulos'] as $m) foreach ($m['aulas'] as $a) { if ($a['idaula'] === $aA) $aulaA = $a; }
eq('o painel mostra nota média, votos e comentários de cada aula', $aulaA && $aulaA['nota_media'] === 4.0 && $aulaA['votos'] === 2 && $aulaA['comentarios'] >= 1);

echo "\nSemana de domingo a sábado e pontos (mesmas regras da Intus)\n";
eq('semana começa no domingo (sábado 17/10/2026 pertence à semana de 11/10)', cddInicioSemana('2026-10-17') === '2026-10-11' && cddInicioSemana('2026-10-18') === '2026-10-18' && cddInicioSemana('2026-10-11') === '2026-10-11');
$sem0 = cddInicioSemana();
$dia = function ($n) use ($sem0) { return date('Y-m-d', strtotime($sem0 . ' +' . $n . ' days')); };
[$idPt, $tPt] = novaAluna('Paula Pontos', 'paula@exemplo.com');
$ins = function ($idaluna, $data, $dur) use ($pdo) { static $n = 900; $n++; $pdo->prepare("INSERT INTO cdd_treino (idaluna, idaula, data, duracao_seg, itens, criado_em, atualizado_em) VALUES (?, ?, ?, ?, '[]', '2026-01-01 00:00:00', '2026-01-01 00:00:00')")->execute([$idaluna, $n, $data, $dur]); };
$ins($idPt, $dia(0), 3600); $ins($idPt, $dia(0), 3600); $ins($idPt, $dia(0), 3600);   // 10 + 5 + 0
$ins($idPt, $dia(1), 600);                                                           // 10 min: conta, mas vale 5
$ins($idPt, $dia(2), 120);                                                           // menos de 5 min: não conta
$ins($idPt, $dia(3), 3000); $ins($idPt, $dia(4), 3000);                              // 10 + 10; chega ao 4º dia na sexta = bônus 5
$pts = cddPontosPorAluna($pdo, $sem0, date('Y-m-d', strtotime($sem0 . ' +6 days')));
eq('pontos: 1º treino do dia 10, 2º 5, 3º 0, treino curto 5, menos de 5 min nada, +5 no 4º dia da semana', isset($pts[$idPt]) && (int)$pts[$idPt]['pontos'] === 10 + 5 + 0 + 5 + 10 + 10 + 5, json_encode($pts[$idPt] ?? null));
eq('treino de 2 minutos não entra na contagem de treinos do ranking', $pts[$idPt]['treinos'] === 6);
[$idQ, $tQ] = novaAluna('Quiteria Quase', 'quiteria@exemplo.com');
$ins($idQ, $dia(0), 3000); $ins($idQ, $dia(1), 3000); $ins($idQ, $dia(2), 3000);        // 30 pontos, sem bônus (3 dias)
[$idR, $tR] = novaAluna('Rita Empate', 'rita@exemplo.com');
$ins($idR, $dia(0), 3000); $ins($idR, $dia(1), 3000); $ins($idR, $dia(2), 3000);        // empate com a Quitéria
[$s, $d] = req('ranking', 'GET', ['periodo' => 'semana'], [], $tPt); $rk = $d['ranking'];
eq('ranking da semana: líder com 45 pontos e posição 1', $s === 200 && $rk['top'][0]['pontos'] === 45 && $rk['top'][0]['pos'] === 1 && $rk['top'][0]['nome'] === 'Você' && $rk['eu']['pos'] === 1, json_encode($rk, JSON_UNESCAPED_UNICODE));
eq('empate divide a colocação e a seguinte não é pulada (posição densa)', $rk['top'][1]['pos'] === 2 && $rk['top'][2]['pos'] === 2 && $rk['participantes'] >= 3, json_encode($rk['top']));
eq('os outros aparecem só com nome e inicial do sobrenome', in_array($rk['top'][1]['nome'], ['Quiteria Q.', 'Rita E.'], true));
[$s, $d] = req('perfil_ranking', 'POST', [], ['visivel' => false], $tQ);
[$s, $d] = req('ranking', 'GET', ['periodo' => 'semana'], [], $tPt); $nomes = array_column($d['ranking']['top'], 'nome');
eq('quem sai do ranking deixa de aparecer para as outras', !in_array('Quiteria Q.', $nomes, true) && in_array('Rita E.', $nomes, true));
[$s, $d] = req('ranking', 'GET', ['periodo' => 'semana'], [], $tQ);
eq('mas ela continua vendo a própria posição', $d['ranking']['eu']['pontos'] === 30 && $d['ranking']['visivel'] === false);
[$s, $d] = req('ranking', 'GET', ['periodo' => 'mes'], [], $tPt); eq('ranking do mês responde', $s === 200 && $d['ranking']['periodo'] === 'mes');
[$s, $d] = req('ranking', 'GET', ['periodo' => 'geral'], [], $tPt); eq('ranking geral responde (só conta até hoje: no mínimo os 3 treinos do domingo, 15 pontos)', $s === 200 && $d['ranking']['eu']['pontos'] >= 15);
eq('sem matrícula não vê o ranking', req('ranking', 'GET', [], [], $tSem)[0] === 403);

echo "\nConquistas\n";
[$s, $d] = req('conquistas', 'GET', [], [], $tPt);
$cq = []; foreach ($d['conquistas'] as $c) $cq[$c['chave']] = $c;
eq('conquistas: lista o modelo todo e marca as alcançadas', $s === 200 && $d['total'] >= 20 && $cq['treino_1']['conquistada'] === true && $cq['semana_4dias']['conquistada'] === true && $cq['treino_10']['conquistada'] === false && $cq['treino_10']['atual'] === 7, json_encode($cq['treino_10']));
[$idCo, $tCo] = novaAluna('Camila Conquista', 'camila@exemplo.com');
[$s, $d] = req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '20', 'reps' => '10']]]], 'duracao_seg' => 1800], $tCo);
$chaves = array_column($d['novas'] ?? [], 'chave');
eq('o primeiro treino devolve as conquistas novas (primeiro treino e primeira aula)', in_array('treino_1', $chaves, true) && in_array('aula_1', $chaves, true) && $d['conta_ranking'] === true, json_encode($d['novas'] ?? null));
[$s, $d] = req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '25', 'reps' => '10']]]], 'duracao_seg' => 1800], $tCo);
eq('salvar de novo não repete a conquista', !in_array('treino_1', array_column($d['novas'], 'chave'), true));
[$s, $d] = req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '25', 'reps' => '10']]]], 'duracao_seg' => 100], $tCo);
eq('treino com menos de 5 minutos avisa que não conta no ranking', $d['conta_ranking'] === false);
[$s, $d] = req('comentar', 'POST', [], ['id' => $aA, 'texto' => 'Meu primeiro comentário aqui'], $tCo);
eq('comentar também gera conquista nova', in_array('comentou', array_column($d['novas'], 'chave'), true));
$pdo->exec("UPDATE cdd_treino SET data = '" . date('Y-m-d', time() - 86400 * 3) . "' WHERE idaluna = $idCo");
req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '30', 'reps' => '8']]]], 'duracao_seg' => 1800], $tCo);
$cq = []; foreach (req('conquistas', 'GET', [], [], $tCo)[1]['conquistas'] as $c) $cq[$c['chave']] = $c;
eq('recorde de carga conta como conquista (25 depois 30 kg)', $cq['recorde_1']['conquistada'] === true);

echo "\nAnamnese no app\n";
[$idAn, $tAn] = novaAluna('Ana Anamnese', 'ana.anamnese@exemplo.com', false);
[$s, $d] = req('anamnese', 'GET', [], [], $tAn);
$campos = []; foreach ($d['etapas'] as $e) foreach ($e['perguntas'] as $p) $campos[$p['id']] = $p;
eq('anamnese abre mesmo sem matrícula e traz as etapas com perguntas obrigatórias', $s === 200 && count($d['etapas']) === 5 && $campos['objetivo']['obrig'] === true && $campos['peso']['tipo'] === 'numero' && $campos['nivel']['tipo'] === 'opcao' && $d['enviado_em'] === null);
[$s, $d] = req('anamnese_salvar', 'POST', [], ['respostas' => ['objetivo' => 'Definir o abdômen', 'peso' => '62,5', 'nivel' => 'Qualquer', 'inventada' => 'x'], 'enviar' => false], $tAn);
eq('rascunho salva sem exigir tudo', $s === 200 && $d['enviada'] === false);
[$s, $d] = req('anamnese', 'GET', [], [], $tAn);
eq('rascunho volta ao reabrir; número com vírgula vira ponto; opção inválida e campo inventado são descartados', $d['respostas']['objetivo'] === 'Definir o abdômen' && $d['respostas']['peso'] === '62.5' && $d['respostas']['nivel'] === '' && !isset($d['respostas']['inventada']));
[$s, $d] = req('anamnese_salvar', 'POST', [], ['respostas' => ['objetivo' => 'Definir o abdômen'], 'enviar' => true], $tAn);
eq('enviar com obrigatórias em branco é recusado e diz o que falta', $s === 422 && $d['error'] === 'anamnese_incompleta' && count($d['faltam']) >= 5);
$completa = ['idade' => '34', 'peso' => '62', 'altura' => '165', 'nivel' => 'Intermediária', 'gestacao' => 'Não', 'objetivo' => 'Definir o abdômen e ganhar força.', 'local_treino' => 'Em casa',
             'doencas' => 'nenhum', 'articulares' => 'dor leve no joelho direito', 'medicamentos' => 'nenhum', 'sono' => '7h, boa'];
[$s, $d] = req('anamnese_salvar', 'POST', [], ['respostas' => $completa, 'enviar' => true], $tAn);
eq('anamnese completa é enviada e gera a conquista Perfil completo', $s === 200 && $d['enviada'] === true && in_array('anamnese', array_column($d['novas'], 'chave'), true));
$enviadoEm = $pdo->query("SELECT enviado_em FROM cdd_anamnese WHERE idaluna = $idAn")->fetchColumn();
[$s, $d] = req('anamnese_salvar', 'POST', [], ['respostas' => array_merge($completa, ['peso' => '61']), 'enviar' => false], $tAn);
eq('editar depois de enviada mantém o envio e atualiza as respostas', $d['enviada'] === true && $pdo->query("SELECT enviado_em FROM cdd_anamnese WHERE idaluna = $idAn")->fetchColumn() === $enviadoEm && json_decode($pdo->query("SELECT respostas FROM cdd_anamnese WHERE idaluna = $idAn")->fetchColumn(), true)['peso'] === '61');
[$s, $d] = req('a_anamneses', 'GET', [], [], $tAn);
eq('aluna não lê as anamneses da equipe (403)', $s === 403);
[$s, $d] = req('a_anamneses', 'GET', [], [], $tAdmin);
eq('a equipe vê a lista de anamneses', $s === 200 && count($d['anamneses']) === 1 && $d['anamneses'][0]['email'] === 'ana.anamnese@exemplo.com');
[$s, $d] = req('a_anamnese', 'GET', ['id' => $idAn], [], $tAdmin); $flat = [];
foreach ($d['etapas'] as $e) foreach ($e['itens'] as $it) $flat[$it['pergunta']] = $it['resposta'];
eq('a equipe lê as respostas com as perguntas e as unidades', $s === 200 && $flat['Peso atual'] === '61 kg' && $flat['Altura'] === '165 cm' && $flat['Como você se considera no treino?'] === 'Intermediária' && $d['enviado_em'] === $enviadoEm);
[$s, $d] = req('a_resumo', 'GET', [], [], $tAdmin);
eq('resumo conta as anamneses enviadas', $d['anamneses_enviadas'] === 1);
[$s, $d] = req('meus_modulos', 'GET', [], [], $tCo);
eq('a tela inicial sabe se a anamnese foi enviada, os pontos da semana e as conquistas', $d['anamnese_enviada'] === false && isset($d['pontos_semana']) && $d['conquistas_total'] >= 20 && count($d['proximas_conquistas']) === 3);

echo "\nCapas, aulas escondidas, métodos e técnicas\n";
$seedCapa = ['modulos' => [['titulo' => 'Módulo com Capa', 'emoji' => '🔥', 'cor' => '#e2272d', 'aulas' => [['titulo' => 'Aula Visível', 'youtube' => 'AAAAAAAAAAA'], ['titulo' => 'Aula Escondida', 'youtube' => 'BBBBBBBBBBB', 'rascunho' => true]]],
                                ['titulo' => 'Módulo Escondido', 'rascunho' => true, 'aulas' => [['titulo' => 'Dentro do escondido', 'youtube' => 'CCCCCCCCCCC']]],
                                ['titulo' => 'Avaliações', 'aulas' => [['titulo' => 'Responda a anamnese', 'tipo' => 'anamnese', 'anexos' => [['titulo' => 'Form', 'url' => 'https://forms.gle/x']]]]]]];
$pdo->exec("INSERT INTO cdd_modulo (titulo, grupo, ordem, publicado) VALUES ('Avaliações', 'curso', 5, 1)");
$idAv = (int)$pdo->lastInsertId(); $pdo->exec("INSERT INTO cdd_aula (idmodulo, titulo, tipo, ordem, publicado) VALUES ($idAv, 'Responda a anamnese', 'link', 10, 1)");
cddImportarSeed($pdo, $seedCapa);
eq('importação grava emoji e cor da capa', $pdo->query("SELECT emoji FROM cdd_modulo WHERE titulo = 'Módulo com Capa'")->fetchColumn() === '🔥' && $pdo->query("SELECT cor FROM cdd_modulo WHERE titulo = 'Módulo com Capa'")->fetchColumn() === '#e2272d');
eq('a aula antiga de anamnese (link) passa a abrir a anamnese do app', $pdo->query("SELECT tipo FROM cdd_aula WHERE titulo = 'Responda a anamnese'")->fetchColumn() === 'anamnese');
[$s, $d] = req('meus_modulos', 'GET', [], [], $tCo); $mc = null; $nomesMod = [];
foreach ($d['modulos'] as $m) { $nomesMod[] = $m['titulo']; if ($m['titulo'] === 'Módulo com Capa') $mc = $m; }
eq('a aluna vê o módulo com capa, sem a aula escondida, e não vê o módulo escondido', $mc && $mc['emoji'] === '🔥' && count($mc['aulas']) === 1 && $mc['thumb_yt'] === 'AAAAAAAAAAA' && !in_array('Módulo Escondido', $nomesMod, true));
[$s, $d] = req('a_conteudo', 'GET', [], [], $tAdmin); $mAd = []; foreach ($d['modulos'] as $m) $mAd[$m['titulo']] = $m;
eq('a equipe vê o módulo escondido e a aula escondida como rascunho', isset($mAd['Módulo Escondido']) && $mAd['Módulo Escondido']['publicado'] == 0 && count($mAd['Módulo com Capa']['aulas']) === 2);
$idEsc = (int)$pdo->query("SELECT idaula FROM cdd_aula WHERE titulo = 'Aula Escondida'")->fetchColumn();
eq('a aula escondida não abre para a aluna nem por link direto (404)', req('aula', 'GET', ['id' => $idEsc], [], $tCo)[0] === 404 && req('treino', 'GET', ['id' => $idEsc], [], $tCo)[0] === 404);
[$s, $d] = req('a_modulo_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Protocolo Trancado', 'grupo' => 'protocolo', 'publicado' => 1, 'emoji' => '🧠', 'cor' => '#7c3aed', 'capa_url' => 'https://exemplo.com/capa.jpg'], $tAdmin);
eq('painel salva emoji, cor e capa', $s === 200 && $pdo->query("SELECT capa_url FROM cdd_modulo WHERE idmodulo = $mT")->fetchColumn() === 'https://exemplo.com/capa.jpg');
eq('capa com javascript: é recusada', req('a_modulo_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Protocolo Trancado', 'capa_url' => 'javascript:alert(1)'], $tAdmin)[0] === 422);
eq('cor inválida é ignorada', (function () use ($pdo, $mT, $tAdmin) { req('a_modulo_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Protocolo Trancado', 'cor' => 'vermelho'], $tAdmin); return $pdo->query("SELECT cor FROM cdd_modulo WHERE idmodulo = $mT")->fetchColumn() === null; })());
eq('método do bloco: deduz super, triset e circuito do texto', cddMetodoTipoDoItem(['series' => 'Super-Série 3x cada']) === 'super' && cddMetodoTipoDoItem(['metodo' => 'Tri-set com o próximo']) === 'triset' && cddMetodoTipoDoItem(['obs' => 'em circuito']) === 'circuito' && cddMetodoTipoDoItem(['series' => '4']) === '' && cddMetodoTipoDoItem(['metodo_tipo' => 'triset', 'series' => 'x']) === 'triset');
eq('técnica de série: drop, rest-pause, cluster, back-off, falha, isometria, negativa', cddInferirTecnica(['metodo' => 'Drop set']) === 'drop' && cddInferirTecnica(['metodo' => 'Rest-pause 3x']) === 'restpause' && cddInferirTecnica(['obs' => 'cluster']) === 'cluster' && cddInferirTecnica(['metodo' => 'Back off']) === 'backoff' && cddInferirTecnica(['metodo' => 'até a falha']) === 'falha' && cddInferirTecnica(['metodo' => 'Isometria final']) === 'isometria' && cddInferirTecnica(['metodo' => 'Negativa lenta']) === 'negativa' && cddInferirTecnica(['metodo' => 'Repetições lentas']) === '');
[$s, $d] = req('a_aula_salvar', 'POST', [], ['idmodulo' => $mTr, 'titulo' => 'Treino Métodos', 'youtube' => 'AAAAAAAAAAA', 'publicado' => 1], $tAdmin); $aM = $d['idaula'];
req('a_ficha_salvar', 'POST', [], ['idaula' => $aM, 'itens' => [['exercicio' => 'Supino', 'series' => 'Super-Série 3x cada', 'repeticoes' => '10'], ['exercicio' => 'Remada', 'series' => '3', 'repeticoes' => '10', 'metodo_tipo' => 'super', 'metodo' => 'Drop set'], ['exercicio' => 'Crucifixo', 'series' => '3']]], $tAdmin);
[$s, $d] = req('treino', 'GET', ['id' => $aM], [], $tCo); $fm = $d['aula']['ficha'];
eq('a ficha da aluna traz o tipo de bloco e a técnica de cada exercício', $fm[0]['metodo_tipo'] === 'super' && $fm[1]['metodo_tipo'] === 'super' && $fm[1]['tecnica'] === 'drop' && $fm[2]['metodo_tipo'] === '' && $fm[2]['tecnica'] === '');
req('treino_salvar', 'POST', [], ['idaula' => $aA, 'itens' => [['exercicio' => 'Agachamento Livre', 'series' => [['peso' => '40', 'reps' => '8']]]], 'duracao_seg' => 1800], $tCo);
[$s, $d] = req('treino', 'GET', ['id' => $aA], [], $tCo); $hh = $d['historico'][cddNorm('Agachamento Livre')];
eq('o histórico do exercício traz as últimas sessões', isset($hh['lista']) && count($hh['lista']) >= 1 && $hh['lista'][0]['series'][0]['peso'] === '40');
$v3 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$ddl3 = cddDdl();
$ddl3['cdd_modulo'] = str_replace([",\n            emoji VARCHAR(8) NULL", ",\n            cor VARCHAR(9) NULL", ",\n            capa_url VARCHAR(500) NULL"], '', $ddl3['cdd_modulo']);
$ddl3['cdd_aluna'] = str_replace("            ranking_visivel TINYINT NOT NULL DEFAULT 1,\n", '', $ddl3['cdd_aluna']);
$ddl3['cdd_ficha_item'] = str_replace("            metodo_tipo VARCHAR(10) NULL,\n", '', $ddl3['cdd_ficha_item']);
foreach ($ddl3 as $n3 => $sql3) { if (!in_array($n3, ['cdd_anamnese', 'cdd_conquista'], true)) $v3->exec(cddDdlSqlite($sql3)); }
$v3->exec("INSERT INTO cdd_config (chave, valor) VALUES ('instalacao_aberta', '0'), ('esquema', '3')");
$v3->exec("INSERT INTO cdd_aluna (nome, email, papel, status, criado_em) VALUES ('Antiga', 'a@a.com', 'aluna', 'ativa', '2026-10-15 10:00:00')");
cddGarantirTabelas($v3);
eq('esquema 3 sobe para o 5: tabelas novas, colunas novas e a aluna antiga entra no ranking por padrão', (int)$v3->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('cdd_anamnese', 'cdd_conquista')")->fetchColumn() === 2 && (int)$v3->query('SELECT ranking_visivel FROM cdd_aluna')->fetchColumn() === 1 && $v3->query('SELECT emoji, cor, capa_url FROM cdd_modulo')->fetchAll() === [] && $v3->query('SELECT metodo_tipo FROM cdd_ficha_item')->fetchAll() === [] && cddConfig($v3, 'esquema') === '5' && (int)$v3->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('cdd_cardio', 'cdd_agua', 'cdd_refeicao')")->fetchColumn() === 3);

echo "\nCardio, água e refeições\n";
[$idBm, $tBm] = novaAluna('Bia Bem Estar', 'bia.bem@teste.com');
[$idSm, $tSm] = novaAluna('Sem Matricula Bem', 'sm.bem@teste.com', false);
$ontemBm = date('Y-m-d', time() - 86400);
eq('pontos do cardio: o tempo manda (60 min vale o dobro de 30)', cddCardioPontos('corrida', 60, 3) > cddCardioPontos('corrida', 30, 3) && abs(cddCardioPontos('caminhada', 60, 3) - 2 * cddCardioPontos('caminhada', 30, 3)) < 0.2);
eq('pontos do cardio: o esforço pesa pouco (menos de 30% entre 1 e 5)', cddCardioPontos('bike', 30, 5) / cddCardioPontos('bike', 30, 1) < 1.3);
eq('pontos do cardio: teto de 7 por sessão', cddCardioPontos('corda', 240, 5) === 7.0);
[$s, $d] = req('cardio', 'GET', [], [], $tSm); eq('cardio sem matrícula é recusado', $s === 403);
[$s, $d] = req('cardio_salvar', 'POST', [], ['atividade' => 'corrida', 'minutos' => 30, 'esforco' => 3], ''); eq('cardio sem login é recusado', $s === 401);
[$s, $d] = req('cardio_salvar', 'POST', [], ['atividade' => 'voar', 'minutos' => 30], $tBm); eq('atividade desconhecida é recusada', $s === 422);
[$s, $d] = req('cardio_salvar', 'POST', [], ['atividade' => 'corrida', 'minutos' => 0], $tBm); eq('duração zero é recusada', $s === 422);
[$s, $d] = req('cardio_salvar', 'POST', [], ['atividade' => 'corrida', 'minutos' => 30, 'data' => date('Y-m-d', time() - 5 * 86400)], $tBm); eq('cardio de 5 dias atrás é recusado (só hoje ou ontem)', $s === 422);
[$s, $d] = req('cardio_salvar', 'POST', [], ['atividade' => 'corrida', 'minutos' => 30, 'esforco' => 3], $tBm);
eq('cardio salvo pontua e conquista o primeiro', $s === 200 && $d['pontos'] > 0 && $d['conta_ranking'] === true && in_array('cardio_1', array_column($d['novas'], 'chave'), true), json_encode($d));
[$s, $d] = req('cardio_salvar', 'POST', [], ['atividade' => 'caminhada', 'minutos' => 5, 'esforco' => 2], $tBm);
eq('cardio com menos de 10 minutos não pontua', $s === 200 && $d['pontos'] === 0.0 && $d['conta_ranking'] === false);
req('cardio_salvar', 'POST', [], ['atividade' => 'bike', 'minutos' => 30], $tBm); req('cardio_salvar', 'POST', [], ['atividade' => 'bike', 'minutos' => 30], $tBm);
[$s, $d] = req('cardio_salvar', 'POST', [], ['atividade' => 'bike', 'minutos' => 30], $tBm);
eq('a partir do 4º cardio do dia não pontua', $d['pontos'] === 0.0, json_encode($d));
[$s, $d] = req('cardio', 'GET', [], [], $tBm);
eq('lista do cardio traz sessões, atividades e resumo da semana', $s === 200 && count($d['cardios']) === 5 && count($d['atividades']) === 10 && $d['semana']['sessoes'] >= 1 && $d['sessoes_hoje'] === 5, json_encode($d['semana']));
$idCard = $d['cardios'][0]['id'];
[$s2, $dOut] = req('ranking', 'GET', ['periodo' => 'semana'], [], $tBm);
$esperado = cddCardioPontos('corrida', 30, 3) + 2 * cddCardioPontos('bike', 30, 3);
eq('o cardio entra nos pontos do ranking (limite de 3 por dia)', abs($dOut['ranking']['eu']['pontos'] - $esperado) <= 1.5, json_encode($dOut['ranking']['eu']) . ' esperado ' . $esperado);
[$sOutra, $tOutra] = novaAluna('Outra Bem', 'outra.bem@teste.com');
req('cardio_excluir', 'POST', [], ['id' => $idCard], $tOutra);
[$s, $d] = req('cardio', 'GET', [], [], $tBm); eq('uma aluna não apaga o cardio de outra', count($d['cardios']) === 5);
req('cardio_excluir', 'POST', [], ['id' => $idCard], $tBm);
[$s, $d] = req('cardio', 'GET', [], [], $tBm); eq('apagar o próprio cardio funciona', count($d['cardios']) === 4);

[$s, $d] = req('nutri', 'GET', [], [], $tBm);
eq('nutrição: meta de água padrão de 2500 ml sem peso e 5 refeições', $s === 200 && $d['nutri']['meta_ml'] === 2500 && count($d['nutri']['refeicoes']) === 5 && $d['nutri']['agua_ml'] === 0 && count($d['nutri']['semana']) === 7);
req('anamnese_salvar', 'POST', [], ['respostas' => ['peso' => '70'], 'enviar' => false], $tBm);
[$s, $d] = req('nutri', 'GET', [], [], $tBm); eq('meta de água usa 35 ml por kg do peso da anamnese (70 kg = 2450 ml)', $d['nutri']['meta_ml'] === 2450, (string)$d['nutri']['meta_ml']);
[$s, $d] = req('agua', 'POST', [], ['delta' => 250], $tBm); eq('beber um copo soma 250 ml', $d['ml'] === 250);
[$s, $d] = req('agua', 'POST', [], ['delta' => -1000], $tBm); eq('a água nunca fica negativa', $d['ml'] === 0);
[$s, $d] = req('agua', 'POST', [], ['ml' => 2450], $tBm); eq('bater a meta de água conquista', in_array('agua_1', array_column($d['novas'], 'chave'), true), json_encode($d));
[$s, $d] = req('agua', 'POST', [], ['delta' => 250, 'data' => date('Y-m-d', time() - 3 * 86400)], $tBm); eq('água de 3 dias atrás é recusada', $s === 422);
[$s, $d] = req('agua', 'POST', [], ['delta' => 250, 'data' => $ontemBm], $tBm); eq('água de ontem é aceita', $s === 200 && $d['ml'] === 250);
[$s, $d] = req('refeicao', 'POST', [], ['refeicao' => 'almoco', 'status' => 'feito'], $tBm); eq('marcar refeição como feita', $s === 200 && $d['aderencia'] === 20);
[$s, $d] = req('refeicao', 'POST', [], ['refeicao' => 'cafe', 'status' => 'parcial'], $tBm); eq('parcial vale metade', $d['aderencia'] === 30);
[$s, $d] = req('refeicao', 'POST', [], ['refeicao' => 'almoco', 'status' => ''], $tBm); eq('limpar a marcação remove o registro', $d['aderencia'] === 10);
[$s, $d] = req('refeicao', 'POST', [], ['refeicao' => 'brunch', 'status' => 'feito'], $tBm); eq('refeição desconhecida é recusada', $s === 422);
[$s, $d] = req('refeicao', 'POST', [], ['refeicao' => 'jantar', 'status' => 'feito', 'data' => date('Y-m-d', time() - 4 * 86400)], $tBm); eq('refeição de 4 dias atrás é recusada', $s === 422);
[$s, $d] = req('nutri', 'GET', [], [], $tBm); eq('resumo reflete água de hoje e aderência', $d['nutri']['agua_ml'] === 2450 && $d['nutri']['aderencia_hoje'] === 10 && $d['nutri']['semana'][6]['meta_batida'] === true);
[$s, $d] = req('nutri', 'GET', [], [], $tOutra); eq('os dados de nutrição são só da própria aluna', $d['nutri']['agua_ml'] === 0 && $d['nutri']['aderencia_hoje'] === null);
[$s, $d] = req('conquistas', 'GET', [], [], $tBm); eq('há 29 conquistas no total, com as de cardio e nutrição', $d['total'] === 29, (string)$d['total']);

echo "\nCapas prontas dos módulos\n";
$seedC = json_decode(file_get_contents(__DIR__ . '/../api/dados/seed-conteudo.json'), true);
$semArq = []; $semCapa = [];
foreach ($seedC['modulos'] as $mc) {
    $cu = $mc['capa_url'] ?? '';
    if ($cu === '') { $semCapa[] = $mc['titulo']; continue; }
    $arq = __DIR__ . '/../' . $cu;
    if (!is_file($arq) || !is_file(preg_replace('/\.jpg$/', '-wide.jpg', $arq))) $semArq[] = $cu;
}
eq('todo módulo do seed tem capa pronta (pôster e banner) e os arquivos existem', !$semCapa && !$semArq, json_encode([$semCapa, $semArq]));
eq('capa pronta e https são aceitos; caminho fora da pasta, php e javascript são recusados', cddCapaUrl('marca/capas/protocolo-3.jpg') === 'marca/capas/protocolo-3.jpg' && cddCapaUrl('https://x.com/a.jpg') === 'https://x.com/a.jpg' && cddCapaUrl('marca/capas/../../app/config/db.php') === '' && cddCapaUrl('../marca/capas/a.jpg') === '' && cddCapaUrl('/etc/passwd') === '' && cddCapaUrl('marca/capas/a.php') === '' && cddCapaUrl('javascript:alert(1)') === '');
[$s, $d] = req('a_modulo_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Protocolo Trancado', 'grupo' => 'protocolo', 'publicado' => 1, 'capa_url' => 'marca/capas/protocolo-4.jpg'], $tAdmin);
eq('painel salva uma capa pronta', $s === 200 && $pdo->query("SELECT capa_url FROM cdd_modulo WHERE idmodulo = $mT")->fetchColumn() === 'marca/capas/protocolo-4.jpg');
eq('painel recusa caminho que sai da pasta de capas', req('a_modulo_salvar', 'POST', [], ['idmodulo' => $mT, 'titulo' => 'Protocolo Trancado', 'capa_url' => 'marca/capas/../x.jpg'], $tAdmin)[0] === 422);
cddImportarSeed($pdo, $seedC);
eq('o importador do seed deixa as capas prontas nos módulos', (int)$pdo->query("SELECT COUNT(*) FROM cdd_modulo WHERE capa_url LIKE 'marca/capas/%'")->fetchColumn() >= count($seedC['modulos']));

echo "\nIsolamento da Intus\n";
$fonte = file_get_contents(__DIR__ . '/../api/_nucleo.php') . file_get_contents(__DIR__ . '/../api/api.php');
eq('o CDD não inclui nenhum arquivo PHP da Intus (só lê as constantes do banco)', preg_match_all('~(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*\'([^\']+)\'~', $fonte, $mm) && count(array_filter($mm[1], function ($p) { return strpos($p, 'app/api') !== false; })) === 0);
eq('todas as tabelas do CDD têm prefixo cdd_', count(array_filter(array_keys(cddDdl()), function ($t) { return strpos($t, 'cdd_') !== 0; })) === 0);
$cod = file_get_contents(__DIR__ . '/../api/_nucleo.php');
preg_match_all('/(?:FROM|JOIN|INTO|UPDATE)\s+([a-z_]+)/', $cod, $tb);
$fora = array_values(array_unique(array_filter($tb[1], function ($t) { return strpos($t, 'cdd_') !== 0; })));
eq('o SQL do CDD só toca tabelas cdd_, exceto a leitura do catálogo intus_exercicio', $fora === ['intus_exercicio'], json_encode($fora));
eq('intus_exercicio só aparece em SELECT (nunca INSERT, UPDATE ou DELETE)', preg_match_all('/intus_exercicio/', $cod) === preg_match_all('/SELECT [^;]*FROM intus_exercicio/', $cod) + 0 || preg_match('/(INSERT INTO|UPDATE|DELETE FROM)\s+intus_exercicio/i', $cod) === 0);

echo "\n" . ($ruim ? "$ruim FALHA(S), $ok ok" : "$ok ok") . "\n";
exit($ruim ? 1 : 0);
