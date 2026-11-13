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

echo "\nLiberação manual de módulo por aluna\n";
[$s, $d] = req('a_aluna_modulos', 'GET', ['idaluna' => $idLiv], [], $tLiv);
eq('aluna não abre a tela de módulos da equipe (403)', $s === 403);
[$s, $d] = req('a_aluna_modulos', 'GET', ['idaluna' => $idLiv], [], $tAdmin);
$lm = null; foreach ($d['modulos'] ?? [] as $x) { if ((int)$x['idmodulo'] === (int)$mT) $lm = $x; }
eq('a equipe vê o módulo trancado pela regra e sem liberação manual', $s === 200 && $lm && $lm['trancado_pela_regra'] === true && $lm['liberado_manual'] === false, json_encode($lm));
[$s, $d] = req('a_aluna_modulo', 'POST', [], ['idaluna' => $idLiv, 'idmodulo' => $mT, 'liberar' => true], $tAdmin);
eq('a equipe libera o módulo à mão', $s === 200 && $d['liberado_manual'] === true);
[$s, $d] = req('aula', 'GET', ['id' => $aT], [], $tLiv);
eq('com a liberação manual a aula abre, mesmo com a data futura', $s === 200 && $d['aula']['titulo'] === 'Treino Trancado');
[$s, $d] = req('meus_modulos', 'GET', [], [], $tLiv);
$mm = null; foreach ($d['modulos'] as $x) { if ($x['titulo'] === 'Protocolo Trancado') $mm = $x; }
eq('a lista da aluna mostra o módulo liberado com as aulas', $mm && $mm['liberado'] === true && count($mm['aulas']) === 1);
[$idOut, $tOut] = novaAluna('Outra Aluna', 'outra.aluna@exemplo.com');
[$s, $d] = req('aula', 'GET', ['id' => $aT], [], $tOut);
eq('outra aluna continua trancada', $s === 403 && $d['error'] === 'modulo_bloqueado', $s . ' ' . json_encode($d));
[$s, $d] = req('a_aluna_modulo', 'POST', [], ['idaluna' => 999999, 'idmodulo' => $mT, 'liberar' => true], $tAdmin);
eq('aluna inexistente é recusada', $s === 404);
[$s, $d] = req('a_aluna_modulo', 'POST', [], ['idaluna' => $idLiv, 'idmodulo' => $mT, 'liberar' => false], $tAdmin);
[$s, $d] = req('aula', 'GET', ['id' => $aT], [], $tLiv);
eq('desfazer a liberação tranca de novo pela regra', $s === 403 && $d['error'] === 'modulo_bloqueado');
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
eq('esquema 3 sobe para o atual: tabelas novas, colunas novas e a aluna antiga entra no ranking por padrão', (int)$v3->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('cdd_anamnese', 'cdd_conquista')")->fetchColumn() === 2 && (int)$v3->query('SELECT ranking_visivel FROM cdd_aluna')->fetchColumn() === 1 && $v3->query('SELECT emoji, cor, capa_url FROM cdd_modulo')->fetchAll() === [] && $v3->query('SELECT metodo_tipo FROM cdd_ficha_item')->fetchAll() === [] && cddConfig($v3, 'esquema') === (string)CDD_ESQUEMA && (int)$v3->query("SELECT COUNT(*) FROM sqlite_master WHERE name IN ('cdd_cardio', 'cdd_agua', 'cdd_refeicao')")->fetchColumn() === 3);

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

echo "\nRecuperação da senha do administrador\n";
require_once __DIR__ . '/../api/_recuperar.php';
$adm = cddRecuperarAdmins($pdo);
eq('a lista traz só contas de administrador, sem hash', count($adm) === 1 && $adm[0]['email'] === CDD_EMAIL_INSTALADOR && !array_key_exists('senha_hash', $adm[0]));
$idAdm = (int)$adm[0]['idaluna'];
eq('confirmação diferente é recusada', cddRecuperarTrocarSenha($pdo, $idAdm, 'senhaNova123', 'outraCoisa123', true)[0] === false);
eq('senha curta é recusada', cddRecuperarTrocarSenha($pdo, $idAdm, 'curta', 'curta', true)[0] === false);
eq('conta de aluna não é alterada por esta rotina', cddRecuperarTrocarSenha($pdo, (int)$idPt, 'senhaNova123', 'senhaNova123', true)[0] === false);
eq('conta que não existe é recusada', cddRecuperarTrocarSenha($pdo, 999999, 'senhaNova123', 'senhaNova123', true)[0] === false);
$hashAluna = $pdo->query("SELECT senha_hash FROM cdd_aluna WHERE idaluna = " . (int)$idPt)->fetchColumn();
eq('a senha antiga ainda vale antes da troca', req('login', 'POST', [], ['email' => CDD_EMAIL_INSTALADOR, 'senha' => 'senhaboa123'], '', '10.9.9.1')[0] === 200);
[$trocou, $msgTroca] = cddRecuperarTrocarSenha($pdo, $idAdm, 'senhaNova123', 'senhaNova123', true);
eq('a troca funciona e avisa que encerrou as sessões', $trocou === true && strpos($msgTroca, 'sessões') !== false);
eq('a senha nova entra', req('login', 'POST', [], ['email' => CDD_EMAIL_INSTALADOR, 'senha' => 'senhaNova123'], '', '10.9.9.2')[0] === 200);
eq('a senha antiga deixa de valer', req('login', 'POST', [], ['email' => CDD_EMAIL_INSTALADOR, 'senha' => 'senhaboa123'], '', '10.9.9.3')[0] === 401);
eq('a sessão aberta antes da troca foi encerrada', req('eu', 'GET', [], [], $tAdmin)[0] === 401);
eq('o que fica no banco é só o hash (bcrypt), nunca a senha', strpos((string)$pdo->query("SELECT senha_hash FROM cdd_aluna WHERE idaluna = $idAdm")->fetchColumn(), '$2y$') === 0 && $pdo->query("SELECT senha_hash FROM cdd_aluna WHERE idaluna = " . (int)$idPt)->fetchColumn() === $hashAluna);
$pag = file_get_contents(__DIR__ . '/../../admin/cdd-recuperar.php');
eq('a página fica atrás do login do admin e confere o token CSRF', strpos($pag, "empty(\$_SESSION['auth'])") !== false && strpos($pag, 'hash_equals($csrf') !== false && strpos($pag, "header('Location: index.php')") !== false);

echo "\nVendas pelo gateway (Eduzz): cadastro e liberação automáticos\n";
[$s, $d] = req('login', 'POST', [], ['email' => CDD_EMAIL_INSTALADOR, 'senha' => 'senhaNova123'], '', '10.9.9.20'); $tAdmin = $d['token'] ?? '';
[$s, $d] = req('login', 'POST', [], ['email' => 'ana@exemplo.com', 'senha' => 'novasenha123'], '', '10.9.9.21'); $tAna = $d['token'] ?? '';
$aviso = function ($ev, $extra = []) { return array_merge(['origin_secret' => 'segredo-teste-123', 'event_name' => $ev, 'type' => 'invoice', 'product_cod' => '1344531', 'trans_cod' => '9001',
    'trans_status' => 3, 'trans_paid' => '698.00', 'trans_paymentmethod' => 32, 'trans_paiddate' => '2026-10-30', 'trans_paidtime' => '10:15',
    'cus_name' => 'Camila Souza', 'cus_email' => 'Camila.Souza@Exemplo.com', 'cus_cel' => '45999990000', 'discount_coupon_code' => 'CDD20OFF'], $extra); };
$qtCamila = function () use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'camila.souza@exemplo.com'")->fetchColumn(); };
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid'));
eq('sem segredo cadastrado o aviso é recusado (503)', $s === 503 && $d['error'] === 'gateway_nao_configurado');
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'eduzz', 'segredo' => 'segredo-teste-123'], $tAna);
eq('aluna não mexe no segredo (403)', $s === 403);
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'eduzz', 'segredo' => 'curto'], $tAdmin);
eq('segredo curto é recusado', $s === 422);
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'eduzz', 'segredo' => 'segredo-teste-123'], $tAdmin);
eq('admin cadastra o segredo', $s === 200, $s . ' ' . json_encode($d));
[$s, $d] = req('a_com_painel', 'GET', [], [], $tAdmin);
eq('o painel não confunde o webhook antigo com o novo (chave nova ainda vazia) e nunca mostra segredos', $s === 200 && $d['gateways']['eduzz3']['segredo_cadastrado'] === false && strpos(json_encode($d), 'segredo-teste-123') === false);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid', ['origin_secret' => 'errado-errado']));
eq('segredo errado é recusado (401) e não cria ninguém', $s === 401 && $qtCamila() === 0);
[$s, $d] = req('webhook', 'POST', ['g' => 'outro'], $aviso('invoice_paid'));
eq('gateway desconhecido (404)', $s === 404);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid'));
eq('produto sem oferta cadastrada só é registrado, sem criar aluna', $s === 200 && $d['resultado'] === 'produto_desconhecido' && $qtCamila() === 0);
// linhas que o Luiz já tinha carregado antes de cancelar estes dois cupons
$agoraP = cddAgora();
foreach ([['pttozkp4hcrl9cyx', 'CAMIDIVA'], ['zo15nqi1r8h6t5ih', 'ESPECIALCAMILA']] as $velho) {
    $pdo->exec("INSERT INTO cdd_oferta (nome, gateway, ref_gateway, meses, valor, link, ativo, ordem, criado_em) VALUES ('Código da Definição, plano anual, cupom {$velho[1]}', 'asaas', '{$velho[0]}', 12, 488.60, 'https://www.asaas.com/c/{$velho[0]}', 0, 15, '$agoraP')");
    $pdo->exec("INSERT INTO cdd_cupom (codigo, gateway, tipo, valor, idoferta, ativo, criado_em) VALUES ('{$velho[1]}', 'asaas', 'percentual', 30, " . (int)$pdo->lastInsertId() . ", 1, '$agoraP')");
    $pdo->exec("INSERT INTO cdd_cupom (codigo, gateway, tipo, valor, ativo, criado_em) VALUES ('{$velho[1]}', 'eduzz', 'percentual', 30, 1, '$agoraP')");
}
[$s, $d] = req('a_com_padroes', 'POST', [], [], $tAdmin);
eq('ofertas e cupons padrão entram (3 do Eduzz e 7 do Asaas desligadas; 4 cupons do Eduzz e 4 do Asaas)', $s === 200 && $d['ofertas_novas'] === 10 && $d['cupons_novos'] === 8, json_encode($d));
eq('os cupons CAMIDIVA e ESPECIALCAMILA e as ofertas deles somem (o Luiz cancelou)', (int)$pdo->query("SELECT COUNT(*) FROM cdd_cupom WHERE codigo IN ('CAMIDIVA', 'ESPECIALCAMILA')")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_oferta WHERE ref_gateway IN ('pttozkp4hcrl9cyx', 'zo15nqi1r8h6t5ih')")->fetchColumn() === 0);
eq('as ofertas do Asaas nascem desligadas (ligar antes do webhook perderia vendas) e o semestral tem valor', (int)$pdo->query("SELECT COUNT(*) FROM cdd_oferta WHERE gateway = 'asaas' AND ativo = 1")->fetchColumn() === 0 && (float)$pdo->query("SELECT valor FROM cdd_oferta WHERE gateway = 'eduzz' AND ref_gateway = '2947166'")->fetchColumn() === 447.0);
eq('cada cupom do Asaas aponta para a sua oferta com o link já descontado', (int)$pdo->query("SELECT COUNT(*) FROM cdd_cupom c JOIN cdd_oferta o ON o.idoferta = c.idoferta WHERE c.gateway = 'asaas' AND o.gateway = 'asaas' AND o.meses = 12 AND o.nome LIKE '%cupom ' || c.codigo")->fetchColumn() === 4);
[$s, $d] = req('a_com_padroes', 'POST', [], [], $tAdmin);
eq('repetir os padrões não duplica nada', $d['ofertas_novas'] === 0 && $d['cupons_novos'] === 0);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid', ['trans_cod' => '9002', 'product_cod' => '2947166']));
eq('oferta inativa (semestral) não libera ninguém', $d['resultado'] === 'oferta_inativa' && $qtCamila() === 0);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid'));
eq('venda paga cria a aluna e libera (o e-mail não sai no teste)', $s === 200 && strpos($d['resultado'], 'aluna_criada') === 0, json_encode($d));
$al = $pdo->query("SELECT * FROM cdd_aluna WHERE email = 'camila.souza@exemplo.com'")->fetch();
eq('aluna criada com e-mail em minúsculas, sem senha, papel aluna e ativa', $al && $al['senha_hash'] === null && $al['papel'] === 'aluna' && $al['status'] === 'ativa' && $al['nome'] === 'Camila Souza');
$mat = $pdo->query("SELECT * FROM cdd_matricula WHERE idaluna = " . (int)$al['idaluna'])->fetch();
$fimEsperado = cddSomarMeses(cddHoje(), 12, CDD_CARENCIA_DIAS);
eq('matrícula do plano anual vale por 12 meses mais a carência', $mat && $mat['status'] === 'ativa' && $mat['origem'] === 'eduzz' && $mat['expira_em'] === $fimEsperado, json_encode($mat));
eq('a matrícula dá acesso ao app', cddMatricula($pdo, (int)$al['idaluna'])['ativa'] === true);
$pg = $pdo->query("SELECT * FROM cdd_pagamento WHERE ref_externa = '9001'")->fetch();
eq('o pagamento fica registrado com valor, forma e cupom', $pg && (float)$pg['valor'] === 698.0 && $pg['forma'] === 'pix' && $pg['cupom'] === 'CDD20OFF' && $pg['status'] === 'pago');
eq('há um link de acesso pendente para ela criar a senha', (int)$pdo->query("SELECT COUNT(*) FROM cdd_ativacao WHERE idaluna = " . (int)$al['idaluna'] . " AND usado_em IS NULL")->fetchColumn() === 1);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid'));
eq('o mesmo aviso repetido não faz nada (idempotente)', $d['resultado'] === 'duplicado' && (int)$pdo->query("SELECT COUNT(*) FROM cdd_pagamento WHERE ref_externa = '9001'")->fetchColumn() === 1 && $qtCamila() === 1);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid', ['trans_cod' => '9003', 'product_cod' => '2411829', 'trans_paid' => '119.00', 'discount_coupon_code' => '']));
$mat2 = $pdo->query("SELECT expira_em FROM cdd_matricula WHERE idaluna = " . (int)$al['idaluna'])->fetchColumn();
$esp2 = cddSomarMeses($fimEsperado, 1, CDD_CARENCIA_DIAS);
eq('nova fatura soma 1 mês ao fim que já existia, sem perder o que já estava pago', $mat2 === $esp2, $mat2 . ' esperado ' . $esp2 . ' ' . json_encode($d));
eq('31 de janeiro mais 1 mês cai em fevereiro, nunca em março', cddSomarMeses('2027-01-31', 1, 0) === '2027-02-28');
$pdo->prepare("UPDATE cdd_aluna SET senha_hash = ? WHERE idaluna = ?")->execute([password_hash('senhaboa123', PASSWORD_BCRYPT), (int)$al['idaluna']]);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid', ['trans_cod' => '9004', 'product_cod' => '2411829']));
eq('quem já tem senha só é renovada (sem novo link)', $d['resultado'] === 'renovada');
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('contract_overdue', ['type' => 'contract', 'trans_cod' => '', 'recurrence_cod' => '777', 'trans_status' => 0, 'recurrence_status' => 7]));
eq('atraso só anota, o acesso segue até a data de fim', $d['resultado'] === 'cobranca_atrasada' && cddMatricula($pdo, (int)$al['idaluna'])['ativa'] === true);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('contract_canceled', ['type' => 'contract', 'trans_cod' => '', 'recurrence_cod' => '777', 'trans_status' => 0, 'recurrence_status' => 4]));
eq('assinatura cancelada mantém o acesso até o fim do período pago', $d['resultado'] === 'assinatura_cancelada' && cddMatricula($pdo, (int)$al['idaluna'])['ativa'] === true);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_refunded', ['trans_cod' => '9004', 'trans_status' => 7]));
eq('reembolso suspende o acesso', $d['resultado'] === 'suspensa_por_reembolso' && cddMatricula($pdo, (int)$al['idaluna'])['ativa'] === false);
eq('e marca o pagamento como reembolsado', $pdo->query("SELECT status FROM cdd_pagamento WHERE ref_externa = '9004'")->fetchColumn() === 'reembolsado');
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid', ['trans_cod' => '9005', 'product_cod' => '2411829']));
eq('nova compra depois do reembolso libera de novo', cddMatricula($pdo, (int)$al['idaluna'])['ativa'] === true);
[$s, $d] = req('webhook', 'POST', ['g' => 'eduzz'], $aviso('invoice_paid', ['trans_cod' => '9006', 'cus_email' => 'nao-e-email']));
eq('compra sem e-mail válido não cria nada e devolve falha para a equipe ver', $d['ok'] === false && $d['resultado'] === 'sem_email');
[$s, $d] = req('a_com_ofertas', 'POST', [], ['nome' => 'Teste', 'gateway' => 'eduzz', 'ref_gateway' => '1344531', 'meses' => 12], $tAdmin);
eq('oferta repetida no mesmo gateway é recusada', $s === 409);
[$s, $d] = req('a_com_cupons', 'POST', [], ['codigo' => 'novo10', 'tipo' => 'percentual', 'valor' => 10], $tAdmin);
eq('admin cria cupom (código em maiúsculas)', $s === 200 && $pdo->query("SELECT codigo FROM cdd_cupom WHERE idcupom = " . (int)$d['idcupom'])->fetchColumn() === 'NOVO10');
[$s, $d] = req('a_com_cupons', 'POST', [], ['codigo' => 'ruim', 'tipo' => 'percentual', 'valor' => 150], $tAdmin);
eq('desconto de mais de 100% é recusado', $s === 422);
[$s, $d] = req('a_com_cupons', 'GET', [], [], $tAdmin);
$cdd20 = array_values(array_filter($d['cupons'], function ($c) { return $c['codigo'] === 'CDD20OFF'; }));
eq('a lista de cupons mostra quantas vendas pagas usaram cada um (reembolsada não conta)', $cdd20 && $cdd20[0]['usos'] === 2, json_encode($cdd20));
[$s, $d] = req('a_com_pagamentos', 'GET', [], [], $tAdmin);
eq('a lista de pagamentos traz nome e e-mail da aluna', $s === 200 && count($d['pagamentos']) >= 3 && $d['pagamentos'][0]['email'] !== null);
[$s, $d] = req('a_com_painel', 'GET', [], [], $tAdmin);
eq('o painel soma vendas e receita dos últimos 30 dias', $d['vendas_30d'] >= 3 && $d['receita_30d'] > 698);

echo "\nWebhook novo do Eduzz (Developer Hub): aviso assinado com HMAC\n";
$chaveV3 = 'chave-secreta-v3-xyz';
$reqV3 = function (array $corpo, ?string $chave = null, string $codif = 'hex') use ($pdo, $ctx) {
    $raw = json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $bin = hash_hmac('sha256', $raw, $chave ?? 'chave-secreta-v3-xyz', true);
    $c = $ctx; $c['raw'] = $raw; $c['assinatura'] = $codif === 'b64' ? base64_encode($bin) : bin2hex($bin);
    return cddTratar($pdo, 'POST', 'webhook', ['g' => 'eduzz3'], $corpo, '', $c);
};
$fatura3 = function ($ev, $extra = []) { return array_merge(['id' => 'ev-' . mt_rand(), 'event' => $ev, 'sentDate' => '2026-10-30T14:45:00.000Z',
    'data' => ['id' => '5551', 'status' => 'paid', 'buyer' => ['name' => 'Bianca Lima', 'email' => 'Bianca.Lima@Exemplo.com', 'cellphone' => '45988887777'],
    'price' => ['currency' => 'BRL', 'value' => 119.0], 'paymentMethod' => 'creditCard', 'installments' => 1, 'utm' => ['source' => 'instagram'],
    'items' => [['productId' => '999', 'name' => 'Outro produto', 'price' => ['value' => 10]], ['productId' => '2411829', 'name' => 'CDD mensal', 'price' => ['currency' => 'BRL', 'value' => 119.0]]]]], $extra); };
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_paid'));
eq('chave do webhook novo ainda não cadastrada: 503', $s === 503);
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'eduzz3', 'segredo' => $chaveV3], $tAdmin);
eq('admin cadastra a chave do webhook novo', $s === 200);
[$s, $d] = req('a_com_painel', 'GET', [], [], $tAdmin);
eq('o painel mostra o endereço novo e que a chave está cadastrada, sem mostrá-la', $d['gateways']['eduzz3']['segredo_cadastrado'] === true && strpos($d['gateways']['eduzz3']['url_aviso'], 'g=eduzz3') !== false && strpos(json_encode($d), $chaveV3) === false);
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_paid'), 'chave-errada-123');
eq('assinatura com chave errada é recusada (401)', $s === 401);
$raw = json_encode($fatura3('myeduzz.invoice_paid'));
$c = $ctx; $c['raw'] = $raw; $c['assinatura'] = '';
eq('sem assinatura é recusado (401)', cddTratar($pdo, 'POST', 'webhook', ['g' => 'eduzz3'], json_decode($raw, true), '', $c)[0] === 401);
$c['raw'] = $raw; $c['assinatura'] = hash_hmac('sha256', $raw . ' ', $chaveV3);
eq('corpo alterado depois de assinado é recusado (401)', cddTratar($pdo, 'POST', 'webhook', ['g' => 'eduzz3'], json_decode($raw, true), '', $c)[0] === 401);
$qtBia = function () use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'bianca.lima@exemplo.com'")->fetchColumn(); };
eq('nada foi criado pelos avisos recusados', $qtBia() === 0);
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_paid'));
eq('fatura paga com vários itens acha o produto do CDD, cria a aluna e libera', $s === 200 && strpos($d['resultado'], 'aluna_criada') === 0 && $qtBia() === 1, json_encode($d));
$bia = $pdo->query("SELECT * FROM cdd_aluna WHERE email = 'bianca.lima@exemplo.com'")->fetch();
$mb = $pdo->query("SELECT * FROM cdd_matricula WHERE idaluna = " . (int)$bia['idaluna'])->fetch();
eq('plano mensal: matrícula de 1 mês mais carência', $mb['expira_em'] === cddSomarMeses(cddHoje(), 1, CDD_CARENCIA_DIAS) && $mb['origem'] === 'eduzz');
$pgb = $pdo->query("SELECT * FROM cdd_pagamento WHERE ref_externa = '5551'")->fetch();
eq('pagamento registrado: valor, cartão e origem do tráfego', $pgb && (float)$pgb['valor'] === 119.0 && $pgb['forma'] === 'cartao' && $pgb['utm'] === 'instagram' && $pgb['status'] === 'pago');
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_paid'));
eq('o mesmo aviso reenviado (assinatura em base64 também vale) é ignorado', $d['resultado'] === 'duplicado' || $s === 200);
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_paid'), null, 'b64');
eq('assinatura em base64 é aceita e o aviso repetido não duplica', $s === 200 && $d['resultado'] === 'duplicado' && (int)$pdo->query("SELECT COUNT(*) FROM cdd_pagamento WHERE ref_externa = '5551'")->fetchColumn() === 1);
$contrato3 = function ($status, $ev) { return ['id' => 'ct-' . mt_rand(), 'event' => $ev, 'sentDate' => '2026-11-30T10:00:00.000Z', 'data' => [
    'products' => [['id' => '2411829', 'name' => 'CDD mensal']], 'contract' => ['id' => '4111', 'status' => $status, 'payment' => ['method' => 'creditCard']],
    'customer' => ['name' => 'Bianca Lima', 'email' => 'bianca.lima@exemplo.com', 'phone' => ['countryCode' => '55', 'areaCode' => '45', 'number' => '988887777']]]]; };
[$s, $d] = $reqV3($contrato3('overdue', 'myeduzz.contract_updated'));
eq('contrato atrasado só anota; o acesso segue', $d['resultado'] === 'cobranca_atrasada' && cddMatricula($pdo, (int)$bia['idaluna'])['ativa'] === true);
[$s, $d] = $reqV3($contrato3('canceled', 'myeduzz.contract_updated'));
eq('contrato cancelado mantém o acesso até o fim do período pago', $d['resultado'] === 'assinatura_cancelada' && cddMatricula($pdo, (int)$bia['idaluna'])['ativa'] === true);
[$s, $d] = $reqV3($contrato3('upToDate', 'myeduzz.contract_created'));
eq('contrato novo em dia não muda nada (o acesso vem da fatura paga)', $d['resultado'] === 'ignorado');
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_refunded', ['data' => ['id' => '5551', 'status' => 'refunded', 'buyer' => ['name' => 'Bianca Lima', 'email' => 'bianca.lima@exemplo.com'], 'price' => ['value' => 119.0], 'items' => [['productId' => '2411829']]]]));
eq('reembolso pelo webhook novo suspende e marca o pagamento', $d['resultado'] === 'suspensa_por_reembolso' && cddMatricula($pdo, (int)$bia['idaluna'])['ativa'] === false && $pdo->query("SELECT status FROM cdd_pagamento WHERE ref_externa = '5551'")->fetchColumn() === 'reembolsado');
[$s, $d] = $reqV3(['id' => 'x', 'event' => 'myeduzz.invoice_paid', 'data' => ['id' => '5560', 'buyer' => ['name' => 'Fulana', 'email' => 'fulana@exemplo.com'], 'price' => ['value' => 49], 'items' => [['productId' => '2938605']]]]);
eq('produto que não é do CDD (outro produto da conta) é só registrado', $d['resultado'] === 'produto_desconhecido' && (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'fulana@exemplo.com'")->fetchColumn() === 0);

[$s, $d] = $reqV3($fatura3('myeduzz.invoice_paid', ['data' => ['id' => '5570', 'buyer' => ['name' => 'Carla', 'email' => 'carla@exemplo.com'], 'price' => ['value' => 119], 'items' => [['productId' => '2411829']]]]));
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_chargeback', ['data' => ['id' => '5570', 'buyer' => ['email' => 'carla@exemplo.com'], 'items' => [['productId' => '2411829']]]]));
eq('chargeback suspende o acesso como o reembolso', $d['resultado'] === 'suspensa_por_reembolso', json_encode($d));

echo "\nVendas pelo Asaas (link de pagamento): cadastro e liberação automáticos\n";
$tokAsaas = '';
$chamadasAsaas = [];
$GLOBALS['asaasFalha'] = '';
$GLOBALS['cddAsaasHttpFake'] = function ($m, $url, $corpo) use (&$chamadasAsaas) {
    $c = $m . ' ' . preg_replace('~^https://[^/]+/v3~', '', $url);
    $chamadasAsaas[] = $c;
    if ($GLOBALS['asaasFalha'] === 'rede') throw new RuntimeException('asaas_fora_do_ar', 502);
    if ($GLOBALS['asaasFalha'] === 'recusa') return [401, ['errors' => [['code' => 'invalid_access_token']]]];
    if (strpos($c, '/customers/cus_ana') !== false) return [200, ['name' => 'Ana Asaas', 'email' => 'Ana.Asaas@Exemplo.com', 'mobilePhone' => '45999991111']];
    if (strpos($c, '/customers/cus_bia') !== false) return [200, ['name' => 'Bia Asaas', 'email' => 'bia.asaas@exemplo.com', 'phone' => '4533334444']];
    if (strpos($c, '/customers/cus_nova') !== false) return [200, ['name' => 'Nova Asaas', 'email' => 'nova.asaas@exemplo.com']];
    if (strpos($c, '/installments/ins_1') !== false) return [200, ['installmentCount' => 3]];
    if (strpos($c, '/subscriptions/sub_1') !== false) return [200, ['paymentLink' => 'linkmensal123']];
    if ($m === 'PUT' && strpos($c, '/paymentLinks/') !== false) return [200, ['id' => 'ok']];
    if (strpos($c, '/customers?limit=1') !== false) return [200, ['data' => []]];
    return [404, []];
};
$reqAs = function (array $corpo, ?string $tok = null, bool $semTok = false) use ($pdo, $ctx, &$tokAsaas) {
    $c = $ctx; $c['token_gateway'] = $semTok ? '' : ($tok ?? $tokAsaas);
    return cddTratar($pdo, 'POST', 'webhook', ['g' => 'asaas'], $corpo, '', $c);
};
$pagAs = function ($ev, $id, $extra = []) {
    return ['id' => 'evt_' . mt_rand(), 'event' => $ev, 'dateCreated' => '2026-11-06 10:00:00', 'payment' => array_merge(['object' => 'payment', 'id' => $id, 'customer' => 'cus_ana',
        'paymentLink' => 'linkanual123', 'billingType' => 'PIX', 'value' => 698.0, 'netValue' => 696.0, 'status' => 'RECEIVED', 'confirmedDate' => '2026-11-06', 'paymentDate' => '2026-11-06'], $extra)];
};
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_1'));
eq('sem token cadastrado o aviso é recusado (503)', $s === 503 && $d['error'] === 'gateway_nao_configurado');
[$s, $d] = req('a_com_asaas_token', 'POST', [], [], $tAna);
eq('aluna não gera o token (403)', $s === 403);
[$s, $d] = req('a_com_asaas_token', 'POST', [], [], $tAdmin);
$tokAsaas = (string)($d['token'] ?? '');
eq('o painel gera um token de 48 caracteres (dentro dos 32 a 255 do Asaas) e o guarda', $s === 200 && strlen($tokAsaas) === 48 && cddConfig($pdo, 'asaas_token') === $tokAsaas);
[$s, $d] = req('a_com_painel', 'GET', [], [], $tAdmin);
eq('o painel mostra o endereço e que o token está cadastrado, mas nunca o token', $d['gateways']['asaas']['token_cadastrado'] === true && $d['gateways']['asaas']['apikey_cadastrada'] === false && strpos($d['gateways']['asaas']['url_aviso'], 'g=asaas') !== false && strpos(json_encode($d), $tokAsaas) === false);
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_1'), null, true);
eq('sem o cabeçalho do token é recusado (401)', $s === 401);
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_1'), 'token-errado-token-errado-token-errado');
eq('token errado é recusado (401)', $s === 401);
[$s, $d] = req('a_com_ofertas', 'POST', [], ['nome' => 'CDD anual (Asaas)', 'gateway' => 'asaas', 'ref_gateway' => '', 'link' => 'https://www.asaas.com/c/linkanual123', 'meses' => 12, 'valor' => '698', 'ativo' => true], $tAdmin);
eq('oferta do Asaas: o código sai do link de pagamento colado', $s === 200 && $pdo->query("SELECT ref_gateway FROM cdd_oferta WHERE idoferta = " . (int)$d['idoferta'])->fetchColumn() === 'linkanual123', json_encode($d));
[$s, $d] = req('a_com_ofertas', 'POST', [], ['nome' => 'CDD mensal (Asaas)', 'gateway' => 'asaas', 'ref_gateway' => 'https://www.asaas.com/c/linkmensal123', 'meses' => 1, 'valor' => '119', 'ativo' => true], $tAdmin);
eq('o link inteiro no campo do código também vira o ID', $s === 200 && $pdo->query("SELECT ref_gateway FROM cdd_oferta WHERE idoferta = " . (int)$d['idoferta'])->fetchColumn() === 'linkmensal123');
[$s, $d] = req('a_com_ofertas', 'POST', [], ['nome' => 'Sem link', 'gateway' => 'asaas', 'ref_gateway' => '', 'meses' => 1, 'ativo' => true], $tAdmin);
eq('oferta ligada do Asaas sem link é recusada', $s === 422);
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_1'));
eq('com token certo mas sem chave de API: 503 e nada é criado', $s === 503 && $d['error'] === 'chave_api_nao_configurada' && (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'ana.asaas@exemplo.com'")->fetchColumn() === 0);
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'asaas', 'campo' => 'apikey', 'segredo' => 'curta'], $tAdmin);
eq('chave de API curta é recusada', $s === 422);
$chaveApiAsaas = '$aact_hmlg_000FAKEchaveDeTeste1234567890';
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'asaas', 'campo' => 'apikey', 'segredo' => $chaveApiAsaas], $tAdmin);
eq('admin cadastra a chave de API', $s === 200);
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'asaas', 'campo' => 'ambiente', 'segredo' => 'sandbox'], $tAdmin);
eq('admin escolhe o ambiente de testes', $s === 200 && cddConfig($pdo, 'asaas_ambiente') === 'sandbox');
[$s, $d] = req('a_com_gateway', 'POST', [], ['gateway' => 'asaas', 'campo' => 'ambiente', 'segredo' => 'qualquer'], $tAdmin);
eq('ambiente inválido é recusado', $s === 422);
[$s, $d] = req('a_com_painel', 'GET', [], [], $tAdmin);
eq('o painel mostra chave cadastrada e ambiente, sem revelar a chave', $d['gateways']['asaas']['apikey_cadastrada'] === true && $d['gateways']['asaas']['ambiente'] === 'sandbox' && strpos(json_encode($d), 'FAKEchave') === false);
$chamadasAsaas = [];
[$s, $d] = req('a_com_asaas_testar', 'POST', [], [], $tAdmin);
eq('"Testar conexão" fala com o ambiente de testes e confirma', $s === 200 && $d['ok'] === true && $chamadasAsaas === ['GET /customers?limit=1']);
[$s, $d] = req('a_com_asaas_testar', 'POST', [], [], $tAna);
eq('aluna não testa a conexão (403)', $s === 403);
$chamadasAsaas = [];
[$s, $d] = req('a_com_asaas_retorno', 'POST', [], ['ref_gateway' => 'r8eb58ytv6woigu2'], $tAdmin);
eq('"Página de obrigado" põe o retorno no link do Asaas (PUT com successUrl e redirecionamento automático)', $s === 200 && $d['ok'] === true && $d['url'] === 'https://intusfit.com.br/cdd/obrigado' && $d['links'][0]['ok'] === true && $chamadasAsaas === ['PUT /paymentLinks/r8eb58ytv6woigu2'], json_encode([$d, $chamadasAsaas]));
[$s, $d] = req('a_com_asaas_retorno', 'POST', [], [], $tAna);
eq('aluna não mexe nos links do Asaas (403)', $s === 403);

$qtAsaas = function ($email) use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = '$email'")->fetchColumn(); };
$chamadasAsaas = [];
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_x', ['paymentLink' => 'linkdaintus999', 'customer' => 'cus_zzz']));
eq('cobrança de outro link (venda da Intus na mesma conta) só é registrada', $s === 200 && $d['resultado'] === 'produto_desconhecido');
eq('e o cliente dessa cobrança NÃO é consultado no Asaas', $chamadasAsaas === []);
[$s, $d] = $reqAs(['id' => 'e', 'event' => 'PAYMENT_CREATED', 'payment' => ['id' => 'pay_9', 'customer' => 'cus_ana', 'paymentLink' => 'linkanual123']]);
eq('evento que o CDD não usa (cobrança criada) é ignorado sem registro', $s === 200 && $d['resultado'] === 'sem_evento' && $chamadasAsaas === []);

[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_1'));
eq('Pix pago no link anual cria a aluna e libera', $s === 200 && strpos($d['resultado'], 'aluna_criada') === 0 && $qtAsaas('ana.asaas@exemplo.com') === 1, json_encode($d));
$ana = $pdo->query("SELECT * FROM cdd_aluna WHERE email = 'ana.asaas@exemplo.com'")->fetch();
eq('nome vem do cadastro no Asaas, e-mail em minúsculas, sem senha', $ana && $ana['nome'] === 'Ana Asaas' && $ana['senha_hash'] === null && $ana['papel'] === 'aluna');
$matA = $pdo->query("SELECT * FROM cdd_matricula WHERE idaluna = " . (int)$ana['idaluna'])->fetch();
$fimAnual = cddSomarMeses(cddHoje(), 12, CDD_CARENCIA_DIAS);
eq('matrícula anual: 12 meses mais a carência, origem asaas', $matA && $matA['expira_em'] === $fimAnual && $matA['origem'] === 'asaas' && cddMatricula($pdo, (int)$ana['idaluna'])['ativa'] === true);
$pgA = $pdo->query("SELECT * FROM cdd_pagamento WHERE ref_externa = 'pay_1'")->fetch();
eq('pagamento registrado: valor, Pix e data de pagamento', $pgA && (float)$pgA['valor'] === 698.0 && $pgA['forma'] === 'pix' && $pgA['gateway'] === 'asaas' && substr($pgA['pago_em'], 0, 10) === '2026-11-06');
[$s, $d] = $reqAs($pagAs('PAYMENT_CONFIRMED', 'pay_1'));
eq('CONFIRMED e RECEIVED da mesma cobrança liberam uma vez só (duplicado)', $d['resultado'] === 'duplicado' && (int)$pdo->query("SELECT COUNT(*) FROM cdd_pagamento WHERE ref_externa = 'pay_1'")->fetchColumn() === 1);

// cartão parcelado em 3x: um aviso por parcela, o mesmo parcelamento
$parc = function ($id) use ($pagAs) { return $pagAs('PAYMENT_CONFIRMED', $id, ['customer' => 'cus_bia', 'installment' => 'ins_1', 'billingType' => 'CREDIT_CARD', 'value' => 232.67, 'status' => 'CONFIRMED']); };
[$s, $d] = $reqAs($parc('pay_c1'));
eq('cartão parcelado: a primeira parcela libera', strpos($d['resultado'], 'aluna_criada') === 0 && $qtAsaas('bia.asaas@exemplo.com') === 1, json_encode($d));
[$s, $d] = $reqAs($parc('pay_c2')); [$s2, $d2] = $reqAs($parc('pay_c3'));
eq('as outras parcelas não renovam de novo', $d['resultado'] === 'duplicado' && $d2['resultado'] === 'duplicado');
$bia = $pdo->query("SELECT idaluna FROM cdd_aluna WHERE email = 'bia.asaas@exemplo.com'")->fetch();
eq('o acesso é de 12 meses (não 36)', $pdo->query("SELECT expira_em FROM cdd_matricula WHERE idaluna = " . (int)$bia['idaluna'])->fetchColumn() === $fimAnual);
$pgB = $pdo->query("SELECT * FROM cdd_pagamento WHERE idaluna = " . (int)$bia['idaluna'])->fetchAll();
eq('um pagamento só, com o valor total do parcelamento (3 x 232,67) e cartão', count($pgB) === 1 && (float)$pgB[0]['valor'] === 698.01 && $pgB[0]['forma'] === 'cartao' && $pgB[0]['ref_externa'] === 'iins_1', json_encode($pgB));

// assinatura mensal: a cobrança só traz o ID da assinatura; o link vem da consulta à assinatura
$pdo->prepare("UPDATE cdd_aluna SET senha_hash = ? WHERE idaluna = ?")->execute([password_hash('senhaboa123', PASSWORD_BCRYPT), (int)$ana['idaluna']]);
$mens = function ($ev, $id, $extra = []) use ($pagAs) { return $pagAs($ev, $id, array_merge(['paymentLink' => null, 'subscription' => 'sub_1', 'billingType' => 'CREDIT_CARD', 'value' => 119.0], $extra)); };
[$s, $d] = $reqAs($mens('PAYMENT_CONFIRMED', 'pay_s1'));
eq('cobrança de assinatura mensal renova a aluna existente', $d['resultado'] === 'renovada', json_encode($d));
eq('soma 1 mês ao fim que já existia', $pdo->query("SELECT expira_em FROM cdd_matricula WHERE idaluna = " . (int)$ana['idaluna'])->fetchColumn() === cddSomarMeses($fimAnual, 1, CDD_CARENCIA_DIAS));
[$s, $d] = $reqAs($mens('PAYMENT_OVERDUE', 'pay_s2'));
eq('mensalidade atrasada só anota; o acesso segue', $d['resultado'] === 'cobranca_atrasada' && cddMatricula($pdo, (int)$ana['idaluna'])['ativa'] === true, json_encode($d));
[$s, $d] = $reqAs(['id' => 'e2', 'event' => 'SUBSCRIPTION_DELETED', 'subscription' => ['id' => 'sub_1', 'customer' => 'cus_ana', 'object' => 'subscription']]);
eq('assinatura cancelada mantém o acesso até o fim do período pago', $d['resultado'] === 'assinatura_cancelada' && cddMatricula($pdo, (int)$ana['idaluna'])['ativa'] === true, json_encode($d));
[$s, $d] = $reqAs($pagAs('PAYMENT_REFUNDED', 'pay_c2', ['customer' => 'cus_bia', 'installment' => 'ins_1', 'billingType' => 'CREDIT_CARD', 'status' => 'REFUNDED']));
eq('reembolso de uma parcela suspende o acesso e marca o parcelamento como reembolsado', $d['resultado'] === 'suspensa_por_reembolso' && cddMatricula($pdo, (int)$bia['idaluna'])['ativa'] === false && $pdo->query("SELECT status FROM cdd_pagamento WHERE ref_externa = 'iins_1'")->fetchColumn() === 'reembolsado', json_encode($d));
[$s, $d] = $reqAs($pagAs('PAYMENT_CHARGEBACK_REQUESTED', 'pay_s1', ['paymentLink' => null, 'subscription' => 'sub_1']));
eq('chargeback suspende o acesso como o reembolso', $d['resultado'] === 'suspensa_por_reembolso' && cddMatricula($pdo, (int)$ana['idaluna'])['ativa'] === false, json_encode($d));

// falhas do Asaas: o aviso volta com erro para o Asaas tentar de novo, e nada é criado pela metade
$GLOBALS['asaasFalha'] = 'rede';
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_n1', ['customer' => 'cus_nova']));
eq('Asaas fora do ar na consulta: 502 (o aviso será reenviado) e ninguém é criado', $s === 502 && $qtAsaas('nova.asaas@exemplo.com') === 0);
$GLOBALS['asaasFalha'] = 'recusa';
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_n1', ['customer' => 'cus_nova']));
eq('chave de API recusada: 503 com o motivo', $s === 503 && $d['error'] === 'chave_api_recusada');
[$s, $d] = req('a_com_asaas_testar', 'POST', [], [], $tAdmin);
eq('"Testar conexão" explica que o Asaas recusou a chave', $s === 200 && $d['ok'] === false && strpos($d['motivo'], 'recusou') !== false);
$GLOBALS['asaasFalha'] = '';
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_n1', ['customer' => 'cus_nova']));
eq('passada a falha, o reenvio do mesmo aviso libera normalmente', strpos($d['resultado'], 'aluna_criada') === 0 && $qtAsaas('nova.asaas@exemplo.com') === 1, json_encode($d));

[$s, $d] = cddTratar($pdo, 'GET', 'ofertas_publicas', [], [], '', $ctx);
$linksPub = $d['ofertas'] ?? [];
$doAsaas = array_values(array_filter($linksPub, function ($o) { return $o['gateway'] === 'asaas'; }));
eq('as páginas de venda leem os links das ofertas ligadas, sem login', $s === 200 && count($doAsaas) === 2 && $doAsaas[0]['meses'] === 12 && strpos($doAsaas[0]['link'], 'asaas.com/c/') !== false);
eq('a lista pública não traz o código do produto no gateway', !array_key_exists('ref_gateway', $linksPub[0]) && !array_key_exists('idoferta', $linksPub[0]));

// cupom no Asaas: o link não tem campo de cupom, então o desconto é um link próprio; a oferta dele fica ligada ao cupom
[$s, $d] = req('a_com_ofertas', 'POST', [], ['nome' => 'CDD anual, cupom TESTE20 (Asaas)', 'gateway' => 'asaas', 'ref_gateway' => 'https://www.asaas.com/c/linkcupom20', 'meses' => 12, 'valor' => '558.40', 'ativo' => true], $tAdmin);
$idOfCupom = (int)($d['idoferta'] ?? 0);
[$s, $d] = req('a_com_cupons', 'POST', [], ['codigo' => 'teste20', 'gateway' => 'asaas', 'tipo' => 'percentual', 'valor' => 20, 'idoferta' => 0], $tAdmin);
eq('cupom do Asaas sem a oferta do link com desconto é recusado', $s === 422);
[$s, $d] = req('a_com_cupons', 'POST', [], ['codigo' => 'teste20', 'gateway' => 'asaas', 'tipo' => 'percentual', 'valor' => 20, 'idoferta' => (int)$pdo->query("SELECT idoferta FROM cdd_oferta WHERE gateway = 'eduzz' LIMIT 1")->fetchColumn()], $tAdmin);
eq('cupom do Asaas apontando para oferta de outro gateway é recusado', $s === 422);
[$s, $d] = req('a_com_cupons', 'POST', [], ['codigo' => 'teste20', 'gateway' => 'asaas', 'tipo' => 'percentual', 'valor' => 20, 'idoferta' => $idOfCupom], $tAdmin);
eq('cupom do Asaas ligado à oferta do link com desconto', $s === 200 && $idOfCupom > 0, json_encode($d));
[$s, $d] = cddTratar($pdo, 'GET', 'ofertas_publicas', [], [], '', $ctx);
$doAsaas2 = array_values(array_filter($d['ofertas'] ?? [], function ($o) { return $o['gateway'] === 'asaas'; }));
eq('a lista comum não traz o link do cupom (não vira o preço do plano anual)', count($doAsaas2) === 2 && strpos(json_encode($d['ofertas']), 'linkcupom20') === false);
eq('a lista de cupons traz código em maiúsculas, duração, valor com desconto e link', count($d['cupons'] ?? []) === 1 && $d['cupons'][0]['codigo'] === 'TESTE20' && $d['cupons'][0]['meses'] === 12 && $d['cupons'][0]['valor'] === 558.4 && strpos($d['cupons'][0]['link'], 'linkcupom20') !== false, json_encode($d['cupons'] ?? null));
eq('a lista de cupons não revela o código do produto nem o id', !array_key_exists('ref_gateway', $d['cupons'][0]) && !array_key_exists('idoferta', $d['cupons'][0]));
$pdo->exec("UPDATE cdd_cupom SET validade = '2020-01-01' WHERE codigo = 'TESTE20'");
[$s, $d] = cddTratar($pdo, 'GET', 'ofertas_publicas', [], [], '', $ctx);
eq('cupom vencido sai da lista pública', ($d['cupons'] ?? []) === []);
$pdo->exec("UPDATE cdd_cupom SET validade = NULL, limite_uso = 1 WHERE codigo = 'TESTE20'");
[$s, $d] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_cupom1', ['customer' => 'cus_ana', 'paymentLink' => 'linkcupom20', 'value' => 558.40, 'billingType' => 'CREDIT_CARD']));
eq('compra pelo link do cupom é liberada como plano anual', $s === 200 && $d['resultado'] !== 'produto_desconhecido' && $d['resultado'] !== 'oferta_inativa', json_encode($d));
$pgCp = $pdo->query("SELECT * FROM cdd_pagamento WHERE ref_externa = 'pay_cupom1'")->fetch();
eq('o pagamento guarda o código do cupom (vem da oferta) e o valor com desconto', $pgCp && $pgCp['cupom'] === 'TESTE20' && (float)$pgCp['valor'] === 558.4, json_encode($pgCp));
[$s, $d] = cddTratar($pdo, 'GET', 'ofertas_publicas', [], [], '', $ctx);
eq('cupom que chegou ao limite de usos sai da lista pública', ($d['cupons'] ?? []) === []);

$GLOBALS['cddAsaasHttpFake'] = null;

echo "\nEntrada da equipe vinda do painel da Intus (bilhete assinado)\n";
require_once __DIR__ . '/../../app/api/_cdd_sso.php';
$segSso = bin2hex(random_bytes(32));
[$s, $d] = req('sso', 'POST', [], ['bilhete' => cddSsoMontar($segSso, 'equipe@exemplo.com', 'Equipe Teste', 7)], '', '10.8.8.1');
eq('sem o vínculo preparado o bilhete é recusado', $s === 401 && $d['error'] === 'sso_nao_configurado');
cddConfigGravar($pdo, 'sso_segredo', $segSso);
$tk = cddSsoMontar($segSso, 'equipe@exemplo.com', 'Equipe Teste', 7);
[$s, $d] = req('sso', 'POST', [], ['bilhete' => $tk], '', '10.8.8.2');
eq('bilhete válido cria a conta de equipe e abre sessão', $s === 200 && $d['usuario']['papel'] === 'admin' && strlen($d['token']) === 64, json_encode($d));
$tEquipe = $d['token'] ?? '';
eq('a conta de equipe não tem senha (só entra pela Intus ou por link)', $pdo->query("SELECT senha_hash FROM cdd_aluna WHERE email = 'equipe@exemplo.com'")->fetchColumn() === null);
[$s, $d] = req('a_com_painel', 'GET', [], [], $tEquipe);
eq('a sessão da equipe abre o painel comercial', $s === 200);
[$s, $d] = req('sso', 'POST', [], ['bilhete' => $tk], '', '10.8.8.3');
eq('o mesmo bilhete não vale duas vezes', $s === 401 && $d['error'] === 'bilhete_usado');
[$s, $d] = req('sso', 'POST', [], ['bilhete' => cddSsoMontar($segSso, 'equipe@exemplo.com', 'Equipe Teste', 7)], '', '10.8.8.4');
eq('novo bilhete da mesma pessoa reaproveita a conta (sem duplicar)', $s === 200 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'equipe@exemplo.com'")->fetchColumn() === 1);
[$s, $d] = req('sso', 'POST', [], ['bilhete' => cddSsoMontar($segSso, 'equipe@exemplo.com', 'X', 7, time() - 3600)], '', '10.8.8.5');
eq('bilhete vencido (mais de 60 s) é recusado', $s === 401 && $d['error'] === 'bilhete_expirado');
[$s, $d] = req('sso', 'POST', [], ['bilhete' => cddSsoMontar('segredo-errado-' . $segSso, 'equipe@exemplo.com', 'X', 7)], '', '10.8.8.6');
eq('bilhete assinado com outro segredo é recusado', $s === 401 && $d['error'] === 'bilhete_invalido');
$tk2 = cddSsoMontar($segSso, 'equipe@exemplo.com', 'X', 7); [$c1, $c2] = explode('.', $tk2);
$adulterado = rtrim(strtr(base64_encode(str_replace('equipe@exemplo.com', 'ninguem-sso7@exemplo.com', base64_decode(strtr($c1, '-_', '+/') . str_repeat('=', (4 - strlen($c1) % 4) % 4)))), '+/', '-_'), '=') . '.' . $c2;
[$s, $d] = req('sso', 'POST', [], ['bilhete' => $adulterado], '', '10.8.8.7');
eq('bilhete com o e-mail trocado depois de assinado é recusado', $s === 401 && $d['error'] === 'bilhete_invalido' && (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'ninguem-sso7@exemplo.com'")->fetchColumn() === 0, $s . ' ' . json_encode($d));
[$s, $d] = req('sso', 'POST', [], ['bilhete' => cddSsoMontar($segSso, 'ana@exemplo.com', 'Ana', 9)], '', '10.8.8.8');
eq('e-mail de uma ALUNA nunca é promovido a equipe', $s === 403 && $d['error'] === 'conta_de_aluna');
eq('a aluna continua aluna', $pdo->query("SELECT papel FROM cdd_aluna WHERE email = 'ana@exemplo.com'")->fetchColumn() === 'aluna');
$pdo->prepare("UPDATE cdd_aluna SET status = 'bloqueada' WHERE email = 'equipe@exemplo.com'")->execute();
[$s, $d] = req('sso', 'POST', [], ['bilhete' => cddSsoMontar($segSso, 'equipe@exemplo.com', 'X', 7)], '', '10.8.8.9');
eq('conta de equipe bloqueada no CDD não entra pelo bilhete', $s === 403 && $d['error'] === 'conta_bloqueada');
[$s, $d] = req('sso', 'POST', [], ['bilhete' => 'lixo'], '', '10.8.8.10');
eq('lixo no lugar do bilhete é recusado', $s === 401);

echo "\nLeads: captura, funil e venda que marca ganho\n";
$leadOk = ['nome' => 'Helena Prado', 'email' => 'Helena.Prado@Exemplo.com', 'whatsapp' => '(45) 99811-2233', 'objetivo' => 'Definir o abdômen', 'aceite' => true, 'origem' => 'captura', 'utm_source' => 'instagram', 'utm_campaign' => 'cdd-out'];
[$s, $d] = req('lead', 'POST', [], $leadOk, '', '10.7.7.1');
eq('lead válido é gravado e devolve o link do WhatsApp com [CDD]', $s === 200 && $d['ok'] === true && strpos($d['zap'], 'wa.me/5545991156006') !== false && strpos(urldecode($d['zap']), '[CDD]') !== false, json_encode($d));
$l = $pdo->query("SELECT * FROM cdd_lead WHERE email = 'helena.prado@exemplo.com'")->fetch();
eq('e-mail em minúsculas, WhatsApp só com dígitos e 55, status novo, origem e utm guardados', $l && $l['whatsapp'] === '5545998112233' && $l['status'] === 'novo' && $l['origem'] === 'captura' && $l['utm_source'] === 'instagram' && $l['optin_em'] !== null);
[$s, $d] = req('lead', 'POST', [], array_merge($leadOk, ['nome' => 'Helena P.', 'objetivo' => '']), '', '10.7.7.2');
eq('o mesmo e-mail não duplica (atualiza o lead)', $s === 200 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_lead WHERE email = 'helena.prado@exemplo.com'")->fetchColumn() === 1);
eq('campo vazio não apaga o que já estava preenchido', $pdo->query("SELECT objetivo FROM cdd_lead WHERE email = 'helena.prado@exemplo.com'")->fetchColumn() === 'Definir o abdômen');
[$s, $d] = req('lead', 'POST', [], array_merge($leadOk, ['email' => '', 'nome' => 'Helena de novo']), '', '10.7.7.3');
eq('o mesmo WhatsApp com outro e-mail não duplica nem troca o e-mail que já estava', (int)$pdo->query("SELECT COUNT(*) FROM cdd_lead WHERE whatsapp = '5545998112233'")->fetchColumn() === 1);
[$s, $d] = req('lead', 'POST', [], array_merge($leadOk, ['aceite' => false, 'email' => 'x1@exemplo.com']), '', '10.7.7.4');
eq('sem o aceite de contato não grava', $s === 422 && $d['error'] === 'aceite_obrigatorio' && (int)$pdo->query("SELECT COUNT(*) FROM cdd_lead WHERE email = 'x1@exemplo.com'")->fetchColumn() === 0);
[$s, $d] = req('lead', 'POST', [], ['nome' => 'Sem contato', 'aceite' => true], '', '10.7.7.5');
eq('sem WhatsApp nem e-mail não grava', $s === 422 && $d['error'] === 'contato_obrigatorio');
[$s, $d] = req('lead', 'POST', [], ['nome' => 'A', 'email' => 'a@exemplo.com', 'aceite' => true], '', '10.7.7.6');
eq('nome curto demais não grava', $s === 422);
[$s, $d] = req('lead', 'POST', [], ['nome' => 'Telefone Ruim', 'whatsapp' => '12345', 'aceite' => true], '', '10.7.7.7');
eq('telefone que não parece telefone é recusado quando é o único contato', $s === 422);
[$s, $d] = req('lead', 'POST', [], array_merge($leadOk, ['site' => 'http://spam', 'email' => 'robo@exemplo.com']), '', '10.7.7.8');
eq('robô que preenche o campo-isca recebe sucesso e nada é gravado', $s === 200 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_lead WHERE email = 'robo@exemplo.com'")->fetchColumn() === 0);
$limite = 0; for ($i = 0; $i < 12; $i++) { [$s] = req('lead', 'POST', [], array_merge($leadOk, ['email' => 'rajada' . $i . '@exemplo.com']), '', '10.7.7.99'); if ($s === 429) $limite++; }
eq('rajada do mesmo IP é limitada (429)', $limite > 0);
[$s, $d] = req('a_leads', 'GET', [], [], $tAna);
eq('aluna não vê os leads (403)', $s === 403);
[$s, $d] = req('a_leads', 'GET', [], [], $tAdmin);
eq('admin vê os leads, com a contagem do funil e o link do WhatsApp', $s === 200 && $d['contagem']['novo'] >= 1 && count($d['leads']) >= 1 && strpos($d['leads'][count($d['leads']) - 1]['zap'], 'wa.me/') !== false, json_encode($d['contagem']));
$idHelena = (int)$pdo->query("SELECT idlead FROM cdd_lead WHERE whatsapp = '5545998112233'")->fetchColumn();
[$s, $d] = req('a_lead_atualizar', 'POST', [], ['idlead' => $idHelena, 'status' => 'contatado', 'obs' => 'Falei pelo WhatsApp'], $tAdmin);
eq('equipe marca como contatado e anota; o horário do contato fica gravado', $s === 200 && $pdo->query("SELECT status FROM cdd_lead WHERE idlead = $idHelena")->fetchColumn() === 'contatado' && $pdo->query("SELECT contato_em FROM cdd_lead WHERE idlead = $idHelena")->fetchColumn() !== null, $s . ' ' . json_encode($d));
[$s, $d] = req('a_lead_atualizar', 'POST', [], ['idlead' => $idHelena, 'status' => 'inventado'], $tAdmin);
eq('situação inventada é recusada', $s === 422);
[$s, $d] = req('a_leads', 'GET', ['status' => 'contatado'], [], $tAdmin);
eq('o filtro por situação funciona', count($d['leads']) === 1 && $d['leads'][0]['status'] === 'contatado');
// a Helena compra o mensal: o lead vira ganho sozinho
[$s, $d] = $reqV3($fatura3('myeduzz.invoice_paid', ['data' => ['id' => '6001', 'buyer' => ['name' => 'Helena Prado', 'email' => 'helena.prado@exemplo.com'], 'price' => ['value' => 119], 'items' => [['productId' => '2411829']]]]));
eq('venda paga com o e-mail do lead marca o lead como ganho e liga à aluna', $pdo->query("SELECT status FROM cdd_lead WHERE idlead = $idHelena")->fetchColumn() === 'ganho' && (int)$pdo->query("SELECT idaluna FROM cdd_lead WHERE idlead = $idHelena")->fetchColumn() > 0);
[$s, $d] = req('a_com_pendentes', 'GET', [], [], $tAdmin);
$pend = array_column($d['pendentes'] ?? [], 'email');
eq('quem foi liberada e ainda não criou a senha aparece na lista de pendentes', $s === 200 && in_array('helena.prado@exemplo.com', $pend, true));

echo "\nPáginas de venda (LPs): escolha, edição e rastreio\n";
[$s, $d] = req('login', 'POST', [], ['email' => CDD_EMAIL_INSTALADOR, 'senha' => 'senhaNova123']);
$tAdm2 = $d['token'] ?? '';
[$s, $d] = req('a_lps', 'GET', [], [], $tAna);
eq('aluna não vê as LPs (403)', $s === 403);
[$s, $d] = req('a_lps', 'GET', [], [], $tAdm2);
$slugs = array_column($d['lps'] ?? [], 'slug');
eq('as LPs já existem (escura ativa; mulheres, direta e treinador pausadas)', $s === 200 && $slugs === ['escura', 'mulheres', 'direta', 'treinador'] && $d['lps'][2]['status'] === 'pausada' && $d['lps'][3]['status'] === 'pausada' && $d['lps'][0]['status'] === 'ativa' && $d['lps'][1]['status'] === 'pausada', json_encode($slugs));
eq('os modelos lp-*.html são listados', in_array('lp-escura.html', $d['modelos'] ?? [], true) && in_array('lp-mulheres.html', $d['modelos'] ?? [], true));
$idEsc = (int)$d['lps'][0]['idlp']; $idMul = (int)$d['lps'][1]['idlp'];
foreach ([2, 3] as $ix) {
    $lpN = $pdo->query('SELECT * FROM cdd_lp WHERE idlp = ' . (int)$d['lps'][$ix]['idlp'])->fetch();
    $hN = cddLpRenderizar($pdo, $lpN);
    eq('modelo ' . $lpN['slug'] . ' renderiza com rastreio, 3 links de compra e formulário', strpos($hN, 'window.CDD_LP="' . $lpN['slug'] . '"') !== false && substr_count($hN, 'data-lk=') === 3 && strpos($hN, 'id="fl"') !== false && strpos($hN, 'id="planos"') !== false);
}
$lpEsc = $pdo->query("SELECT * FROM cdd_lp WHERE idlp = $idEsc")->fetch();
eq('a raiz serve a LP ativa', cddLpEscolher($pdo, '')['slug'] === 'escura');
req('a_lp_salvar', 'POST', [], ['idlp' => $idMul, 'nome' => 'Feminina rosé', 'arquivo' => 'lp-mulheres.html', 'status' => 'ativa', 'peso' => 50], $tAdm2);
req('a_lp_salvar', 'POST', [], ['idlp' => $idEsc, 'nome' => 'Escura (principal)', 'arquivo' => 'lp-escura.html', 'status' => 'ativa', 'peso' => 50], $tAdm2);
eq('sorteio respeita o peso (1 a 50 = escura, 51 a 100 = mulheres)', cddLpEscolher($pdo, '', 10)['slug'] === 'escura' && cddLpEscolher($pdo, '', 90)['slug'] === 'mulheres');
eq('o cookie mantém a mesma LP para a mesma visitante', cddLpEscolher($pdo, 'mulheres', 10)['slug'] === 'mulheres');
req('a_lp_salvar', 'POST', [], ['idlp' => $idMul, 'nome' => 'Feminina rosé', 'arquivo' => 'lp-mulheres.html', 'status' => 'pausada', 'peso' => 0], $tAdm2);
req('a_lp_salvar', 'POST', [], ['idlp' => $idEsc, 'nome' => 'Escura (principal)', 'arquivo' => 'lp-escura.html', 'status' => 'ativa', 'peso' => 100], $tAdm2);
eq('LP pausada não entra no sorteio, nem pelo cookie', cddLpEscolher($pdo, 'mulheres', 90)['slug'] === 'escura');
[$s, $d] = req('a_lp_salvar', 'POST', [], ['nome' => 'Hack', 'slug' => 'x', 'arquivo' => '../api/_nucleo.php', 'status' => 'ativa'], $tAdm2);
eq('modelo fora da lista é recusado (sem sair da pasta)', $s === 422);
[$s, $d] = req('a_lp_salvar', 'POST', [], ['nome' => 'Escura B', 'slug' => 'Escura B!', 'arquivo' => 'lp-escura.html', 'status' => 'pausada', 'peso' => 0, 'copiar_de' => $idEsc], $tAdm2);
$idB = (int)($d['idlp'] ?? 0);
eq('variante nova ganha endereço limpo', $s === 200 && $pdo->query("SELECT slug FROM cdd_lp WHERE idlp = $idB")->fetchColumn() === 'escura-b');
[$s, $d] = req('a_lp_salvar', 'POST', [], ['nome' => 'Repetida', 'slug' => 'escura', 'arquivo' => 'lp-escura.html'], $tAdm2);
eq('endereço repetido é recusado (409)', $s === 409);
[$s, $d] = req('a_lp_campos', 'GET', ['idlp' => $idB], [], $tAdm2);
$ch = array_column($d['campos'] ?? [], 'chave');
eq('o modelo expõe título principal e os dois links de compra', in_array('t1', $ch, true) && in_array('link.anual', $ch, true) && in_array('link.mensal', $ch, true), json_encode($ch));
$lpB = function () use ($pdo, $idB) { return $pdo->query("SELECT * FROM cdd_lp WHERE idlp = $idB")->fetch(); };
[$s, $d] = req('a_lp_campo', 'POST', [], ['idlp' => $idB, 'chave' => 't1', 'valor' => 'Texto de teste <script>alert(1)</script> com <em>ênfase</em>'], $tAdm2);
$h = cddLpRenderizar($pdo, $lpB());
eq('o texto editado aparece na LP e script é removido', $s === 200 && strpos($h, 'Texto de teste') !== false && strpos($h, '<script>alert') === false && strpos($h, '<em>ênfase</em>') !== false);
$hE = cddLpRenderizar($pdo, $lpEsc);
eq('a outra LP não é afetada pela edição da variante', strpos($hE, 'Texto de teste') === false);
[$s, $d] = req('a_lp_campo', 'POST', [], ['idlp' => $idB, 'chave' => 'link.anual', 'valor' => 'javascript:alert(1)'], $tAdm2);
eq('link que não é https é recusado', $s === 422);
[$s, $d] = req('a_lp_campo', 'POST', [], ['idlp' => $idB, 'chave' => 'link.anual', 'valor' => 'https://pay.exemplo.com.br/anual'], $tAdm2);
eq('o link de compra editado troca o href', $s === 200 && substr_count(cddLpRenderizar($pdo, $lpB()), 'https://pay.exemplo.com.br/anual') >= 1);
[$s, $d] = req('a_lp_campo', 'POST', [], ['idlp' => $idB, 'chave' => 'campo.que.nao.existe', 'valor' => 'x'], $tAdm2);
eq('campo que não existe no modelo é recusado', $s === 422);
[$s, $d] = req('a_lp_campo', 'POST', [], ['idlp' => $idB, 'chave' => 't1', 'valor' => ''], $tAdm2);
eq('valor vazio restaura o texto original', $s === 200 && $d['restaurado'] === true && strpos(cddLpRenderizar($pdo, $lpB()), 'Texto de teste') === false);
eq('a LP entregue leva o rastreio com o próprio endereço', strpos($hE, 'window.CDD_LP="escura"') !== false && strpos($hE, 'lp-track.js') !== false);
$ev = function ($tipo, $vid, $extra = []) { return req('lp_evento', 'POST', [], array_merge(['slug' => 'escura', 'tipo' => $tipo, 'vid' => $vid, 'utm_source' => 'instagram'], $extra), '', '10.9.9.' . rand(1, 200)); };
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) Mobile';
$ev('view', 'visitante0001'); $ev('view', 'visitante0001'); $ev('view', 'visitante0002');
$ev('click', 'visitante0001', ['alvo' => 'anual']); $ev('click', 'visitante0002', ['alvo' => 'hero']); $ev('scroll90', 'visitante0001');
[$s, $d] = $ev('view', 'curto');
eq('identificador curto demais é recusado', $s === 422);
[$s, $d] = req('lp_evento', 'POST', [], ['slug' => 'nao-existe', 'tipo' => 'view', 'vid' => 'visitante0003'], '', '10.9.9.250');
eq('LP desconhecida é recusada', $s === 404);
[$s, $d] = req('lp_evento', 'POST', [], ['slug' => 'escura', 'tipo' => 'apagar', 'vid' => 'visitante0003'], '', '10.9.9.251');
eq('tipo de evento inventado é recusado', $s === 422);
$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1';
$ev('view', 'visitante0009');
unset($_SERVER['HTTP_USER_AGENT']);
eq('robô não conta como visita', (int)$pdo->query("SELECT COUNT(*) FROM cdd_lp_evento WHERE vid = 'visitante0009'")->fetchColumn() === 0);
$agora = cddAgora();
$pdo->exec("INSERT INTO cdd_lead (nome, email, origem, status, idaluna, optin_em, criado_em, atualizado_em) VALUES ('Lead LP', 'leadlp@exemplo.com', 'escura', 'ganho', $idAna, '$agora', '$agora', '$agora')");
$pdo->exec("INSERT INTO cdd_lead (nome, email, origem, status, optin_em, criado_em, atualizado_em) VALUES ('Lead antigo', 'leadvendas@exemplo.com', 'vendas', 'novo', '$agora', '$agora', '$agora')");
[$s, $d] = req('a_lps', 'GET', ['dias' => 7], [], $tAdm2);
$e = $d['lps'][0]['estatisticas'] ?? [];
eq('visitantes contam pessoas, não recargas (2 pessoas, 3 visitas)', ($e['visitantes'] ?? 0) === 2 && ($e['visitas'] ?? 0) === 3, json_encode($e));
eq('checkout conta quem clicou num plano (1), e o clique do hero fica à parte', ($e['checkout'] ?? 0) === 1 && count($e['cliques'] ?? []) === 2);
eq('lead antigo com origem vendas entra na escura, e a venda vem do lead ganho', ($e['leads'] ?? 0) >= 2 && ($e['vendas'] ?? 0) === 1, json_encode([$e['leads'] ?? null, $e['vendas'] ?? null]));
eq('taxa de checkout sobre os visitantes', ($e['taxa_checkout'] ?? null) === 50.0);
eq('fonte e dispositivo aparecem', ($e['fontes'][0]['fonte'] ?? '') === 'instagram' && ($e['dispositivos']['mobile'] ?? 0) === 2);

echo "\nBoas-vindas, primeiro acesso e página de obrigado\n";
$camila = $pdo->query("SELECT * FROM cdd_aluna WHERE email = 'camila.souza@exemplo.com'")->fetch();
$idCam = (int)$camila['idaluna'];
eq('o telefone da compra fica guardado com o DDI 55', $camila && $camila['telefone'] === '5545999990000', json_encode($camila['telefone'] ?? null));
eq('telefone: celular, fixo com DDI e lixo', cddTelefoneBR('(45) 99999-0000') === '5545999990000' && cddTelefoneBR('+55 45 3333-4444') === '554533334444' && cddTelefoneBR('123') === '' && cddTelefoneBR('') === '');
[$assunto, $texto] = cddEmailAcesso('Camila Souza', 'https://x.test/index.html#ativar=abc');
eq('e-mail de boas-vindas traz o link, os 3 passos e o caminho para pedir outro link', strpos($texto, '#ativar=abc') !== false && strpos($texto, 'Comece por aqui') !== false && strpos($texto, 'Tela de Início') !== false && strpos($texto, 'Instalar app') !== false && strpos($texto, '/app/obrigado') !== false && strpos($texto, 'Oi, Camila!') === 0);
[$assuntoR, $textoR] = cddEmailAcesso('Camila Souza', 'https://x.test/#ativar=abc', true, true);
eq('reenvio para quem já tem senha fala em senha nova', strpos($assuntoR, 'senha nova') !== false && strpos($textoR, 'senha nova') !== false);
$zapTxt = cddZapBoasVindas('Camila Souza', 'camila.souza@exemplo.com');
eq('boas-vindas por WhatsApp: e-mail, caminho para pedir o link e o app, sem link de senha', strpos($zapTxt, 'camila.souza@exemplo.com') !== false && strpos($zapTxt, '/app/obrigado') !== false && strpos($zapTxt, 'Comece por aqui') !== false && strpos($zapTxt, '#ativar') === false);
eq('os textos de boas-vindas não usam travessão', strpos($texto . $textoR . $zapTxt, "\u{2014}") === false && strpos($texto . $textoR . $zapTxt, "\u{2013}") === false);
$ativ = function ($id) use ($pdo) { return (int)$pdo->query("SELECT COUNT(*) FROM cdd_ativacao WHERE idaluna = " . (int)$id)->fetchColumn(); };
$antes = $ativ($idCam);
[$s, $d] = req('acesso_reenviar', 'POST', [], ['email' => 'Camila.Souza@Exemplo.com'], '', '10.8.8.1');
eq('pedir o link de novo gera um link novo, com resposta genérica', $s === 200 && $d['ok'] === true && $ativ($idCam) === $antes + 1 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_ativacao WHERE idaluna = $idCam AND usado_em IS NULL")->fetchColumn() === 1);
[$s, $d2] = req('acesso_reenviar', 'POST', [], ['email' => 'ninguem@exemplo.com'], '', '10.8.8.2');
eq('e-mail sem compra recebe a mesma resposta e nada é criado', $s === 200 && $d2['mensagem'] === $d['mensagem'] && (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'ninguem@exemplo.com'")->fetchColumn() === 0);
[$s, $d3] = req('acesso_reenviar', 'POST', [], ['email' => 'isto-nao-e-email'], '', '10.8.8.3');
eq('e-mail inválido é recusado (422)', $s === 422);
$admId = (int)$pdo->query("SELECT idaluna FROM cdd_aluna WHERE papel = 'admin' LIMIT 1")->fetchColumn();
$admEmail = (string)$pdo->query("SELECT email FROM cdd_aluna WHERE idaluna = $admId")->fetchColumn();
$antesAdm = $ativ($admId);
[$s, $d4] = req('acesso_reenviar', 'POST', [], ['email' => $admEmail], '', '10.8.8.4');
eq('conta de administrador não recebe link por este caminho (a resposta é a mesma)', $s === 200 && $d4['mensagem'] === $d['mensagem'] && $ativ($admId) === $antesAdm);
req('acesso_reenviar', 'POST', [], ['email' => 'camila.souza@exemplo.com'], '', '10.8.8.5');
req('acesso_reenviar', 'POST', [], ['email' => 'camila.souza@exemplo.com'], '', '10.8.8.6');
$cheio = $ativ($idCam);
[$s, $d5] = req('acesso_reenviar', 'POST', [], ['email' => 'camila.souza@exemplo.com'], '', '10.8.8.7');
eq('depois de 3 pedidos em 15 minutos o e-mail para de gerar links (sem avisar quem pede)', $s === 200 && $d5['mensagem'] === $d['mensagem'] && $ativ($idCam) === $cheio && $cheio === $antes + 3);
$pdo->exec("UPDATE cdd_matricula SET status = 'cancelada' WHERE idaluna = $idCam");
for ($i = 0; $i < 1; $i++) $pdo->exec("DELETE FROM cdd_tentativa");
$semMat = $ativ($idCam);
req('acesso_reenviar', 'POST', [], ['email' => 'camila.souza@exemplo.com'], '', '10.8.8.8');
eq('quem não tem matrícula ativa não recebe link', $ativ($idCam) === $semMat);
$pdo->exec("UPDATE cdd_matricula SET status = 'ativa' WHERE idaluna = $idCam");

echo "\nCheckout próprio\n";
$pdo->exec("UPDATE cdd_oferta SET ativo = 1 WHERE gateway = 'asaas' AND nome NOT LIKE '%cupom%' AND nome NOT LIKE 'TESTE%'");
$ckChamadas = []; $ckCorpos = [];
$GLOBALS['asaasFalha'] = '';
$GLOBALS['cddAsaasHttpFake'] = function ($m, $url, $corpo) use (&$ckChamadas, &$ckCorpos) {
    $c = $m . ' ' . preg_replace('~^https://[^/]+/v3~', '', $url);
    $ckChamadas[] = $c; $ckCorpos[$c] = $corpo;
    if ($GLOBALS['asaasFalha'] === 'rede') throw new RuntimeException('asaas_fora_do_ar', 502);
    if ($m === 'POST' && $c === 'POST /customers') return [200, ['id' => 'cus_ck' . count($ckChamadas)]];
    if ($m === 'POST' && $c === 'POST /payments') return [200, ['id' => 'pay_ck' . count($ckChamadas), 'invoiceUrl' => 'https://asaas.test/i/' . count($ckChamadas), 'installment' => isset($corpo['installmentCount']) ? 'ins_ck' . count($ckChamadas) : null]];
    if ($m === 'GET' && strpos($c, '/pixQrCode') !== false) return [200, ['encodedImage' => 'QUJD', 'payload' => '00020126PIXCOPIA', 'expirationDate' => '2026-12-31 23:59:59']];
    if ($m === 'POST' && $c === 'POST /subscriptions') return [200, ['id' => 'sub_ck1']];
    if ($m === 'GET' && strpos($c, '/subscriptions/sub_ck1/payments') !== false) return [200, ['data' => [['id' => 'pay_sub1', 'invoiceUrl' => 'https://asaas.test/i/sub']]]];
    return [404, ['errors' => [['description' => 'nao mockado: ' . $c]]]];
};
[$s, $d] = req('checkout_info', 'GET', [], [], '', '10.7.7.1');
$slugs = array_column($d['planos'] ?? [], 'slug');
eq('o checkout lista os planos à venda (sem cupom e sem teste)', $s === 200 && in_array('anual', $slugs, true) && in_array('mensal', $slugs, true) && !array_filter($d['planos'], function ($p) { return stripos($p['nome'], 'cupom') !== false || stripos($p['nome'], 'TESTE') === 0; }), json_encode($slugs));
$anual = array_values(array_filter($d['planos'], function ($p) { return $p['slug'] === 'anual'; }))[0];
$mensal = array_values(array_filter($d['planos'], function ($p) { return $p['slug'] === 'mensal'; }))[0];
eq('sem bônus cadastrados a lista vem vazia', $d['bumps'] === []);
[$s, $x] = req('a_checkout_bumps', 'POST', [], ['bumps' => [['id' => 'guia', 'titulo' => 'Guia de compras', 'valor' => '29,90']]], $tAna);
eq('aluna não cadastra bônus (403)', $s === 403);
[$s, $x] = req('a_checkout_bumps', 'POST', [], ['bumps' => [['id' => 'guia', 'titulo' => 'Guia de compras', 'descricao' => 'PDF', 'valor' => '29,90'], ['id' => 'x', 'titulo' => '', 'valor' => 5]]], $tAdmin);
eq('bônus sem título é recusado (422)', $s === 422);
[$s, $x] = req('a_checkout_bumps', 'POST', [], ['bumps' => [['id' => 'guia', 'titulo' => 'Guia de compras', 'descricao' => 'PDF', 'valor' => '29,90']]], $tAdmin);
eq('admin cadastra o bônus', $s === 200 && $x['bumps'][0]['valor'] === 29.9);
$pdo->exec("INSERT INTO cdd_cupom (codigo, gateway, tipo, valor, ativo, criado_em) VALUES ('CKTESTE', 'eduzz', 'percentual', 10, 1, '" . cddAgora() . "')");
[$s, $d] = req('checkout_info', 'GET', ['plano' => 'anual', 'bumps' => 'guia,naoexiste', 'cupom' => 'ckteste'], [], '', '10.7.7.1');
$esp = round($anual['valor'] - round($anual['valor'] * 0.10, 2) + 29.90, 2);
eq('cotação: cupom de 10% no anual mais o bônus; bônus que não existe é ignorado', $d['cotacao']['total'] === $esp && count($d['cotacao']['bumps']) === 1 && $d['cotacao']['cupom'] === 'CKTESTE', json_encode($d['cotacao']));
[$s, $d] = req('checkout_info', 'GET', ['plano' => 'mensal', 'bumps' => 'guia'], [], '', '10.7.7.1');
eq('no mensal o bônus não entra (cobraria todo mês)', $d['cotacao']['total'] === $mensal['valor'] && $d['cotacao']['bumps'] === []);
[$s, $d] = req('checkout_info', 'GET', ['plano' => 'mensal', 'cupom' => 'CKTESTE'], [], '', '10.7.7.1');
eq('cupom só vale no anual', isset($d['cotacao']['erro']));

$dados = ['plano' => 'anual', 'nome' => 'Maria da Silva', 'email' => 'Maria.CK@Exemplo.com', 'telefone' => '(45) 99999-1234', 'cpf' => '529.982.247-25', 'forma' => 'pix', 'bumps' => ['guia'], 'cupom' => 'CKTESTE', 'utm' => ['utm_source' => 'instagram']];
[$s, $d] = req('checkout_criar', 'POST', [], array_merge($dados, ['cpf' => '111.111.111-11']), '', '10.7.7.2');
eq('CPF inválido é recusado (422)', $s === 422 && $d['error'] === 'cpf_invalido');
[$s, $d] = req('checkout_criar', 'POST', [], array_merge($dados, ['nome' => 'Maria']), '', '10.7.7.2');
eq('nome sem sobrenome é recusado (422)', $s === 422 && $d['error'] === 'nome_invalido');
[$s, $d] = req('checkout_criar', 'POST', [], array_merge($dados, ['plano' => 'nao-existe']), '', '10.7.7.2');
eq('plano que não existe é recusado (422)', $s === 422);
[$s, $d] = req('checkout_criar', 'POST', [], array_merge($dados, ['site' => 'http://spam']), '', '10.7.7.2');
eq('a isca de robô não cria nada', $s === 200 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_pedido")->fetchColumn() === 0 && $ckChamadas === []);
[$s, $d] = req('checkout_criar', 'POST', [], $dados, '', '10.7.7.3');
$ped = $pdo->query("SELECT * FROM cdd_pedido ORDER BY idpedido DESC LIMIT 1")->fetch();
eq('Pix: o QR Code e o copia e cola voltam na própria resposta', $s === 200 && $d['forma'] === 'pix' && $d['pix']['copia'] === '00020126PIXCOPIA' && $d['pix']['imagem'] === 'QUJD' && $d['valor'] === $esp, json_encode($d));
eq('o pedido guarda a conta feita pelo servidor, o CPF só em dígitos e o telefone com DDI', $ped && (float)$ped['valor'] === $esp && $ped['cpf'] === '52998224725' && $ped['telefone'] === '5545999991234' && $ped['email'] === 'maria.ck@exemplo.com' && $ped['bumps'] === 'Guia de compras' && $ped['cupom'] === 'CKTESTE');
$corpoPag = $ckCorpos['POST /payments'] ?? [];
eq('a cobrança vai ao Asaas como Pix, com o valor do servidor, a referência do pedido e o retorno', ($corpoPag['billingType'] ?? '') === 'PIX' && (float)$corpoPag['value'] === $esp && $corpoPag['externalReference'] === 'cddp:' . $ped['idpedido'] && strpos($corpoPag['callback']['successUrl'], '/cdd/obrigado') !== false, json_encode($corpoPag));
eq('o cliente é criado no Asaas com CPF e celular sem o 55', ($ckCorpos['POST /customers']['cpfCnpj'] ?? '') === '52998224725' && ($ckCorpos['POST /customers']['mobilePhone'] ?? '') === '45999991234');
[$s, $d2] = req('checkout_status', 'GET', ['t' => $ped['token']], [], '', '10.7.7.3');
eq('antes do pagamento o status é aguardando', $s === 200 && $d2['status'] === 'aguardando');
[$s, $d2] = req('checkout_status', 'GET', ['t' => 'naoexiste'], [], '', '10.7.7.3');
eq('pedido desconhecido (404)', $s === 404);
// o aviso do Asaas traz só a cobrança e a referência: o pedido diz quem comprou e qual plano
[$s, $d2] = $reqAs($pagAs('PAYMENT_RECEIVED', $ped['asaas_ref'], ['customer' => 'cus_qualquer', 'paymentLink' => null, 'externalReference' => 'cddp:' . $ped['idpedido'], 'value' => $esp]));
$alCk = $pdo->query("SELECT * FROM cdd_aluna WHERE email = 'maria.ck@exemplo.com'")->fetch();
$pgCk = $pdo->query("SELECT * FROM cdd_pagamento WHERE ref_externa = '" . $ped['asaas_ref'] . "'")->fetch();
eq('o pagamento do checkout cria a aluna com os dados do pedido, a matrícula e o telefone', $s === 200 && $alCk && $alCk['nome'] === 'Maria da Silva' && $alCk['telefone'] === '5545999991234' && cddMatricula($pdo, (int)$alCk['idaluna'])['ativa'] === true, json_encode($d2));
eq('o pagamento fica com o valor total e o cupom do pedido', $pgCk && (float)$pgCk['valor'] === $esp && $pgCk['cupom'] === 'CKTESTE');
[$s, $d2] = req('checkout_status', 'GET', ['t' => $ped['token']], [], '', '10.7.7.3');
eq('depois do aviso o pedido está pago (a página segue para a página de obrigado)', $d2['status'] === 'pago');
eq('o checkout não chamou o Asaas para buscar o cliente do aviso (o pedido já tem os dados)', !in_array('GET /customers/cus_qualquer', $ckChamadas, true));

// cartão parcelado: a cliente vai à tela do Asaas e o parcelamento vai por installmentCount e totalValue
$ckChamadas = [];
[$s, $d] = req('checkout_criar', 'POST', [], array_merge($dados, ['email' => 'joana.ck@exemplo.com', 'nome' => 'Joana Souza', 'forma' => 'cartao', 'parcelas' => 12, 'bumps' => [], 'cupom' => '']), '', '10.7.7.4');
$pedC = $pdo->query("SELECT * FROM cdd_pedido ORDER BY idpedido DESC LIMIT 1")->fetch();
$corpoC = $ckCorpos['POST /payments'] ?? [];
eq('cartão: vai para a tela do Asaas (sem dado de cartão aqui) em 12x sem juros', $s === 200 && $d['forma'] === 'redirecionar' && strpos($d['url'], 'https://asaas.test/i/') === 0 && ($corpoC['billingType'] ?? '') === 'CREDIT_CARD' && (int)$corpoC['installmentCount'] === 12 && (float)$corpoC['totalValue'] === (float)$anual['valor'] && !isset($corpoC['value']), json_encode($corpoC));
eq('o pedido guarda o parcelamento', (int)$pedC['parcelas'] === 12 && $pedC['asaas_parc'] !== null && $pedC['status'] === 'criado');
[$s, $d2] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_parcela1', ['customer' => 'cus_x', 'paymentLink' => null, 'installment' => $pedC['asaas_parc'], 'billingType' => 'CREDIT_CARD', 'value' => round($anual['valor'] / 12, 2)]));
$pgC = $pdo->query("SELECT * FROM cdd_pagamento WHERE ref_externa = 'i" . $pedC['asaas_parc'] . "'")->fetch();
eq('o aviso de uma parcela é achado pelo parcelamento e registra o valor TOTAL da compra', $s === 200 && $pgC && (float)$pgC['valor'] === (float)$pedC['valor'] && $pgC['forma'] === 'cartao', json_encode($d2));

// mensal: assinatura; a cliente escolhe a forma na tela do Asaas
$ckChamadas = [];
[$s, $d] = req('checkout_criar', 'POST', [], array_merge($dados, ['email' => 'lia.ck@exemplo.com', 'nome' => 'Lia Santos', 'plano' => 'mensal', 'forma' => 'cartao', 'bumps' => ['guia'], 'cupom' => '']), '', '10.7.7.5');
$pedM = $pdo->query("SELECT * FROM cdd_pedido ORDER BY idpedido DESC LIMIT 1")->fetch();
eq('mensal: cria a assinatura e leva à cobrança dela, sem bônus', $s === 200 && $d['url'] === 'https://asaas.test/i/sub' && in_array('POST /subscriptions', $ckChamadas, true) && (float)$ckCorpos['POST /subscriptions']['value'] === (float)$mensal['valor'] && $pedM['asaas_tipo'] === 'assinatura' && $pedM['bumps'] === null);
[$s, $d2] = $reqAs($pagAs('PAYMENT_RECEIVED', 'pay_sub_a', ['customer' => 'cus_y', 'paymentLink' => null, 'subscription' => 'sub_ck1', 'billingType' => 'PIX', 'value' => (float)$mensal['valor']]));
eq('a cobrança da assinatura é achada pelo id da assinatura (também nas renovações)', $s === 200 && (int)$pdo->query("SELECT COUNT(*) FROM cdd_aluna WHERE email = 'lia.ck@exemplo.com'")->fetchColumn() === 1, json_encode($d2));

// falha do Asaas
$GLOBALS['asaasFalha'] = 'rede';
[$s, $d] = req('checkout_criar', 'POST', [], array_merge($dados, ['email' => 'rede.ck@exemplo.com', 'nome' => 'Rede Falha', 'bumps' => [], 'cupom' => '']), '', '10.7.7.6');
eq('Asaas fora do ar: erro claro (502) e o pedido fica marcado como falhou', $s === 502 && $d['error'] === 'checkout_indisponivel' && $pdo->query("SELECT status FROM cdd_pedido WHERE email = 'rede.ck@exemplo.com'")->fetchColumn() === 'falhou');
$GLOBALS['asaasFalha'] = '';
// limite por IP: 10 pedidos em 15 minutos
for ($i = 0; $i < 12; $i++) $r = req('checkout_criar', 'POST', [], array_merge($dados, ['email' => "lim$i.ck@exemplo.com", 'nome' => 'Limite Teste', 'bumps' => [], 'cupom' => '']), '', '10.7.7.9');
eq('muitos pedidos do mesmo IP em pouco tempo são barrados (429)', $r[0] === 429);
// oferta de teste só por ?plano=teste
$pdo->exec("INSERT INTO cdd_oferta (nome, gateway, ref_gateway, meses, valor, link, ativo, ordem, criado_em) VALUES ('TESTE interno CDD (R\$ 5)', 'asaas', 'testelink123', 2, 5.00, 'https://www.asaas.com/c/testelink123', 1, 0, '" . cddAgora() . "')");
[$s, $d] = req('checkout_info', 'GET', [], [], '', '10.7.7.1');
eq('a oferta de teste não aparece na lista do checkout', !in_array('teste', array_column($d['planos'], 'slug'), true));
[$s, $d] = req('checkout_info', 'GET', ['plano' => 'teste'], [], '', '10.7.7.1');
eq('com ?plano=teste a oferta de teste aparece (R$ 5)', in_array('teste', array_column($d['planos'], 'slug'), true) && $d['cotacao']['total'] === 5.0);
[$s, $d] = req('a_checkout_pedidos', 'GET', [], [], $tAdmin);
eq('a equipe vê os pedidos', $s === 200 && count($d['pedidos']) >= 4);
$GLOBALS['cddAsaasHttpFake'] = null;

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
