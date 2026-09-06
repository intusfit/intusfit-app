<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_cors.php';
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$raw = file_get_contents('php://input');
$body = json_decode($raw, true) ?: [];
$nome = trim($body['nome'] ?? '');
$grupo = trim($body['grupo'] ?? '');
$video = trim($body['videoyoutube'] ?? '');

if (!$nome) {
    echo json_encode(['error' => 'Nome do exercício obrigatório.']);
    exit;
}

// Base de instruções por padrão de nome
$instrucoes = [
    '/supino.*reto/i' => 'Deite no banco reto com os pés firmes no chão. Segure a barra com pegada um pouco mais larga que os ombros. Desça a barra até tocar levemente o peito (linha dos mamilos), mantendo os cotovelos a ~75°. Empurre de volta até a extensão completa dos braços. Mantenha escápulas retraídas e peito projetado durante todo o movimento.',
    '/supino.*inclinado/i' => 'Ajuste o banco entre 30° e 45°. Desça a barra até a parte superior do peito. Empurre para cima e levemente para trás. Mantenha os cotovelos a ~75° e escápulas retraídas.',
    '/crucifixo|peck.?deck|fly/i' => 'Mantenha os cotovelos levemente flexionados durante todo o movimento. Abra os braços até sentir um alongamento confortável no peito. Junte na frente contraindo o peitoral. Controle a fase excêntrica (abertura).',
    '/agachamento|squat/i' => 'Posicione a barra na parte superior do trapézio. Pés na largura dos ombros, pontas levemente para fora. Desça quebrando primeiro nos quadris, depois nos joelhos. Vá até pelo menos 90° ou abaixo do paralelo. Mantenha o tronco ereto e o core ativado. Empurre pelo calcanhar para subir.',
    '/leg.?press/i' => 'Posicione os pés na plataforma na largura dos ombros. Desça controladamente até ~90° nos joelhos (não deixe o quadril sair do apoio). Empurre sem travar os joelhos no topo. Mantenha as costas totalmente apoiadas no encosto.',
    '/extensora/i' => 'Ajuste o encosto e o rolo na altura do tornozelo. Estenda as pernas até a contração completa do quadríceps. Segure 1 segundo no topo. Desça controladamente sem deixar o peso bater.',
    '/flexora/i' => 'Deite de bruços e posicione o rolo acima dos calcanhares. Flexione os joelhos trazendo o rolo em direção aos glúteos. Segure a contração no topo. Desça de forma controlada.',
    '/rosca.*direta|curl.*bar/i' => 'Em pé, segure a barra com pegada supinada (palmas para cima) na largura dos ombros. Cotovelos fixos ao lado do corpo. Flexione os braços até a contração máxima do bíceps. Desça de forma controlada até extensão quase completa.',
    '/rosca.*alter|curl.*dumb/i' => 'Segure os halteres ao lado do corpo. Flexione um braço de cada vez (ou ambos), girando o pulso para supinação durante a subida. Cotovelos fixos. Controle a descida.',
    '/tríceps.*pulley|pushdown/i' => 'Em pé, segure a barra/corda do pulley alto. Cotovelos fixos ao lado do corpo. Estenda os braços até a contração total do tríceps. Retorne controladamente até ~90° nos cotovelos.',
    '/puxada|pulldown|lat.?pull/i' => 'Segure a barra com pegada pronada (palmas para frente), um pouco mais larga que os ombros. Puxe a barra em direção ao peito superior, liderando com os cotovelos. Projete o peito para frente e contraia as escápulas. Retorne de forma controlada.',
    '/remada|row/i' => 'Mantenha o tronco estável (inclinado ou apoiado, conforme variação). Puxe o peso em direção ao abdômen/peito inferior, liderando com os cotovelos. Contraia as escápulas no final do movimento. Desça de forma controlada.',
    '/elevação.?lateral|lateral.?raise/i' => 'Em pé, segure os halteres ao lado do corpo. Eleve os braços lateralmente até a altura dos ombros, mantendo os cotovelos levemente flexionados. Não use impulso. Desça controladamente. Polegar levemente para baixo no topo ativa mais o deltóide medial.',
    '/desenvolvimento|shoulder.?press|militar/i' => 'Segure os halteres/barra na altura dos ombros. Empurre para cima até a extensão quase completa. Não trave os cotovelos. Desça até a linha das orelhas. Mantenha o core ativado para estabilidade.',
    '/stiff|romeno|rdl/i' => 'Segure a barra/halteres à frente das coxas. Com joelhos levemente flexionados (fixos), incline o tronco à frente empurrando o quadril para trás. Desça até sentir o alongamento nos posteriores. Retorne contraindo glúteos e posteriores. Mantenha as costas retas.',
    '/terra|deadlift/i' => 'Pés na largura do quadril, barra sobre o meio do pé. Agache segurando a barra. Peito para cima, costas retas. Levante empurrando o chão com os pés, mantendo a barra próxima ao corpo. Estenda quadris e joelhos simultaneamente. No topo, contraia glúteos.',
    '/panturrilha|calf/i' => 'Posicione a parte anterior do pé na plataforma, calcanhares livres. Suba na ponta dos pés até a contração máxima. Segure 1-2 segundos no topo. Desça alongando bem a panturrilha abaixo do nível da plataforma.',
    '/abdom|crunch|prancha|plank/i' => 'Ative o core antes de iniciar. Foque na contração do abdômen, não na velocidade. Mantenha a coluna neutra. Na prancha: corpo reto da cabeça aos calcanhares, sem deixar o quadril subir ou cair.',
    // Alongamentos
    '/ponte.*glut|glute.*bridge/i' => 'Deite de barriga para cima com joelhos flexionados e pés apoiados no chão. Eleve o quadril até alinhar com joelhos e ombros. Segure a posição mantendo glúteos contraídos. Desça controladamente. Mantenha 20-30s.',
    '/gato.*vaca|cat.*cow/i' => 'Em quatro apoios, alterne entre arquear as costas para cima (gato) e afundar para baixo (vaca), coordenando com a respiração. Movimente lentamente sentindo cada vértebra.',
    '/along.*pesco|cervic.*along|along.*cervic/i' => 'Incline a cabeça lateralmente levando a orelha em direção ao ombro oposto. Segure 20-30s cada lado. Mantenha os ombros relaxados e abaixados.',
    '/along.*ombro|along.*trap|along.*deltoid/i' => 'Cruze um braço à frente do corpo na altura do peito. Com a outra mão, puxe gentilmente o cotovelo em direção ao corpo. Segure 20-30s cada lado. Mantenha o ombro oposto relaxado.',
    '/along.*peitor|along.*peito/i' => 'Posicione o braço contra uma parede ou batente em ângulo de 90°. Gire o tronco para o lado oposto até sentir o alongamento no peitoral. Segure 20-30s cada lado.',
    '/along.*quadri|reto.?fem/i' => 'Em pé, segure o tornozelo levando o calcanhar em direção ao glúteo. Mantenha os joelhos juntos e o tronco ereto. Segure 20-30s cada perna. Use apoio se necessário.',
    '/along.*posterior|isquio|hamstr/i' => 'Sentado ou em pé, estenda a perna e incline o tronco à frente mantendo as costas retas. Vá até sentir o alongamento na parte posterior da coxa. Segure 20-30s cada perna.',
    '/along.*panturr|along.*soleo/i' => 'Apoie as mãos na parede, uma perna à frente flexionada e a outra estendida atrás com o calcanhar no chão. Empurre o calcanhar para baixo. Segure 20-30s cada lado.',
    '/adutor|virilha|borboleta/i' => 'Sentado, junte as solas dos pés (posição borboleta) e empurre os joelhos para baixo com os cotovelos. Mantenha a coluna ereta. Segure 30-40s.',
    '/along.*lombar|along.*coluna|along.*costas/i' => 'Deite de barriga para cima, puxe os joelhos em direção ao peito envolvendo com os braços. Balance suavemente de um lado para o outro. Segure 20-30s.',
    '/cobra|along.*abdo/i' => 'Deite de bruços e estenda os braços elevando o tronco (posição cobra). Mantenha o quadril no chão e os ombros afastados das orelhas. Segure 15-20s.',
];

$instrucao = '';
foreach ($instrucoes as $pattern => $texto) {
    if (preg_match($pattern, $nome)) {
        $instrucao = $texto;
        break;
    }
}

if (!$instrucao) {
    // Instrução genérica baseada no grupo muscular
    $instrucao = "Execute o movimento de forma controlada, priorizando a amplitude completa. ";
    $instrucao .= "Respire: expire na fase concêntrica (esforço) e inspire na excêntrica (retorno). ";
    $instrucao .= "Mantenha o core ativado durante toda a execução. ";
    if ($grupo) $instrucao .= "Foco muscular: $grupo.";
}

echo json_encode(['instrucao' => $instrucao, 'fonte' => 'local']);
