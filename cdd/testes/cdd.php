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
foreach (cddDdl() as $n => $sql) { if ($n !== 'cdd_exercicio') $antigo->exec(cddDdlSqlite($sql)); }
$antigo->exec("INSERT INTO cdd_config (chave, valor) VALUES ('instalacao_aberta', '0')");
$antigo->exec("INSERT INTO cdd_aluna (nome, email, papel, status, criado_em) VALUES ('Existente', 'e@e.com', 'aluna', 'ativa', '2026-10-15 10:00:00')");
cddGarantirTabelas($antigo);
eq('instalação antiga (sem a chave de versão) ganha a tabela nova sem perder nada', (int)$antigo->query("SELECT COUNT(*) FROM sqlite_master WHERE name = 'cdd_exercicio'")->fetchColumn() === 1 && (int)$antigo->query('SELECT COUNT(*) FROM cdd_aluna')->fetchColumn() === 1 && cddConfig($antigo, 'instalacao_aberta') === '0' && cddConfig($antigo, 'esquema') === '2');

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
