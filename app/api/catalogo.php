<?php
// ── LIMITE PARA IMAGEM EM BASE64 ───────────────────────────────────────────
// Estes campos gravam a imagem inteira dentro do banco, e nao tinham teto nem
// conferencia de formato. Alguns megabytes por chamada, em laco, enchem a
// tabela e derrubam o banco por espaco. O projeto ja valida MIME e tamanho no
// upload de midia; aqui a validacao nunca foi aplicada.
function _intusImagemOk($v, int $maxBytes = 3145728) {
    if ($v === null || $v === '') return null;
    if (!is_string($v)) return false;
    if (strlen($v) > $maxBytes) return false;
    // aceita data URI de imagem ou caminho/URL simples
    if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#i', $v)) return $v;
    if (preg_match('#^(https?://|/)[^\s"\'<>]{1,500}$#i', $v)) return $v;
    return false;
}

// ── DETALHE DE ERRO NAO VAI PARA O CLIENTE ─────────────────────────────────
// As respostas devolviam $e->getMessage() direto. Em falha de conexao isso
// traz host, nome do banco e usuario; em erro de SQL, o nome das tabelas e
// colunas. E um mapa do sistema entregue de graca a quem estiver sondando.
// Agora a mensagem completa vai para o log do servidor e o cliente recebe
// so um numero para voce cruzar com o log.
function _intusLogErro(Throwable $e): string {
    $ref = substr(hash('crc32b', $e->getMessage() . '|' . $e->getFile() . '|' . $e->getLine()), 0, 8);
    @error_log('[intus ' . $ref . '] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return 'ref ' . $ref;
}

/**
 * Endpoint unificado para dados que antes eram local-only:
 *   - alongamentos (catálogo)
 *   - avaliacoes (medidas corporais)
 *   - planos-config (config premium)
 *   - profile (avatar + prefs do aluno)
 *
 * Rotas:
 *   GET/POST/PUT/DELETE  ?action=alongamentos
 *   GET/POST/PUT/DELETE  ?action=avaliacoes
 *   GET/PUT              ?action=planos_config
 *   GET/PUT              ?action=profile&atleta=ID
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_charset_fix.php';
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Cache-Control');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

// ---------- Conexao ----------
$cfg = @include __DIR__ . '/../config/db.php';
$pdo = null;
try {
    if (is_array($cfg) && isset($cfg['host'])) {
        $dsn = "mysql:host={$cfg['host']};dbname={$cfg['database']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } elseif (defined('DB_HOST')) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } else {
        throw new Exception('sem credenciais');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'falha conexao', 'detalhe' => _intusLogErro($e)]);
    exit;
}

// ---------- Bootstrap ----------
function ensureCatalogoTables(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_alongamento (
            idalongamento INT AUTO_INCREMENT PRIMARY KEY,
            grupo         VARCHAR(120) NOT NULL DEFAULT 'Geral',
            nome          VARCHAR(200) NOT NULL,
            tempo         VARCHAR(40) NOT NULL DEFAULT '30s',
            obs           TEXT NULL,
            descricao     TEXT NULL,
            videoyoutube  VARCHAR(500) NULL,
            gifalongamento VARCHAR(500) NULL,
            instrucao_ia  TEXT NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Auto-add new columns
    $cols = array_column($pdo->query("SHOW COLUMNS FROM intus_alongamento")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('gifalongamento', $cols)) $pdo->exec("ALTER TABLE intus_alongamento ADD COLUMN gifalongamento VARCHAR(500) NULL AFTER videoyoutube");
    if (!in_array('instrucao_ia', $cols)) $pdo->exec("ALTER TABLE intus_alongamento ADD COLUMN instrucao_ia TEXT NULL AFTER gifalongamento");
    // Mesmo par equipamento+substitutos do catalogo de exercicios (intus_exercicio),
    // pro plano self-service trocar alongamento tambem quando o aluno nao tem o
    // equipamento (ex.: alongamento com elastico vs so peso corporal).
    if (!in_array('equipamento', $cols)) $pdo->exec("ALTER TABLE intus_alongamento ADD COLUMN equipamento VARCHAR(30) NULL AFTER grupo");
    if (!in_array('substitutos', $cols)) $pdo->exec("ALTER TABLE intus_alongamento ADD COLUMN substitutos TEXT NULL AFTER instrucao_ia");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_avaliacao (
            idavaliacao  INT AUTO_INCREMENT PRIMARY KEY,
            idatleta     INT NOT NULL,
            dtavaliacao  DATE NOT NULL,
            peso         DECIMAL(5,2) NULL,
            altura       DECIMAL(4,2) NULL,
            percgordura  DECIMAL(4,1) NULL,
            observacao   TEXT NULL,
            medidas      TEXT NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $acols = array_column($pdo->query("SHOW COLUMNS FROM intus_avaliacao")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('medidas', $acols)) $pdo->exec("ALTER TABLE intus_avaliacao ADD COLUMN medidas TEXT NULL AFTER observacao");
    if (!in_array('metodo', $acols)) $pdo->exec("ALTER TABLE intus_avaliacao ADD COLUMN metodo VARCHAR(120) NULL AFTER medidas");
    if (!in_array('fotos', $acols)) $pdo->exec("ALTER TABLE intus_avaliacao ADD COLUMN fotos LONGTEXT NULL AFTER metodo");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_plano_nutricional (
            idplano      INT AUTO_INCREMENT PRIMARY KEY,
            idatleta     INT NOT NULL,
            titulo       VARCHAR(200) NOT NULL DEFAULT 'Plano Alimentar',
            objetivo     VARCHAR(200) NULL,
            calorias     INT NULL,
            proteina     DECIMAL(5,1) NULL,
            carboidrato  DECIMAL(5,1) NULL,
            gordura      DECIMAL(5,1) NULL,
            refeicoes    LONGTEXT NULL,
            observacao   TEXT NULL,
            ativo        TINYINT(1) NOT NULL DEFAULT 1,
            dtinicio     DATE NULL,
            dtfim        DATE NULL,
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $ncols = array_column($pdo->query("SHOW COLUMNS FROM intus_plano_nutricional")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('comentario_inicio', $ncols)) $pdo->exec("ALTER TABLE intus_plano_nutricional ADD COLUMN comentario_inicio TEXT NULL AFTER observacao");
    if (!in_array('comentario_fim', $ncols)) $pdo->exec("ALTER TABLE intus_plano_nutricional ADD COLUMN comentario_fim TEXT NULL AFTER comentario_inicio");

    // Caixa (entradas/saídas manuais do financeiro) — antes só existia no
    // localStorage do navegador, então lançamentos (principalmente as saídas,
    // que ninguém mais lança automaticamente) simplesmente desapareciam ao
    // trocar de aparelho/navegador ou limpar o cache. Agora é tabela de
    // verdade, com o mesmo id que o front-end já gera (cx-...) como chave.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_caixa (
            id            VARCHAR(40) PRIMARY KEY,
            tipo          ENUM('entrada','saida') NOT NULL DEFAULT 'entrada',
            data          DATE NOT NULL,
            descricao     VARCHAR(255) NOT NULL DEFAULT '',
            categoria     VARCHAR(120) NULL,
            valor         DECIMAL(10,2) NOT NULL DEFAULT 0,
            obs           TEXT NULL,
            idusuario     INT NULL,
            origem_venda  VARCHAR(40) NULL,
            recorrente_id VARCHAR(40) NULL,
            created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_data (data),
            INDEX idx_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Feed dos Alunos — só post feito de propósito pelo aluno (foto/filtro +
    // legenda), diferente do que já existia antes (que virava post sozinho a
    // partir de qualquer treino registrado). Comentário reaproveita o mesmo
    // desenho genérico alvo_tipo/alvo_id que a reação já usa.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_feed_post (
            idpost      INT AUTO_INCREMENT PRIMARY KEY,
            idatleta    INT NOT NULL,
            imagem      VARCHAR(500) NOT NULL,
            legenda     VARCHAR(500) NULL,
            ativo       TINYINT(1) NOT NULL DEFAULT 1,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta), INDEX idx_ativo (ativo, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_feed_post', ['legenda', 'localizacao']);
    // 'feed' = entra na lista geral (sujeito ao feed_optin de cada um);
    // 'perfil' = fica só na grade do próprio perfil, nunca aparece no feed
    // geral de ninguém (nem do próprio autor).
    $fpCols = array_column($pdo->query("SHOW COLUMNS FROM intus_feed_post")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('destino', $fpCols)) $pdo->exec("ALTER TABLE intus_feed_post ADD COLUMN destino ENUM('feed','perfil') NOT NULL DEFAULT 'feed' AFTER legenda");
    // Texto livre digitado pelo aluno (não é geolocalização real nem lista de
    // academias — a Intus não tem unidade física, cada aluno treina onde
    // quiser, então é só uma marcação livre tipo "Academia X" ou "Casa").
    if (!in_array('localizacao', $fpCols)) $pdo->exec("ALTER TABLE intus_feed_post ADD COLUMN localizacao VARCHAR(120) NULL AFTER destino");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_comentario_pub (
            idcomentario  INT AUTO_INCREMENT PRIMARY KEY,
            alvo_tipo     VARCHAR(20)  NOT NULL,
            alvo_id       INT          NOT NULL,
            autor_tipo    VARCHAR(10)  NOT NULL,
            autor_id      INT          NOT NULL,
            autor_nome    VARCHAR(200) NOT NULL DEFAULT '',
            texto         VARCHAR(500) NOT NULL,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_alvo (alvo_tipo, alvo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_comentario_pub', ['autor_nome', 'texto']);
    // Mídia de um post (carrossel de fotos e, depois, vídeo) ou de um resultado.
    // intus_feed_post.imagem continua sendo a CAPA (apps antigos só leem ela);
    // post sem linhas aqui é devolvido como uma mídia só, a partir da capa.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_midia (
            idmidia        INT AUTO_INCREMENT PRIMARY KEY,
            dono_tipo      VARCHAR(20)  NOT NULL,
            dono_id        INT          NOT NULL,
            ordem          TINYINT      NOT NULL DEFAULT 0,
            tipo           VARCHAR(10)  NOT NULL DEFAULT 'imagem',
            url            VARCHAR(500) NULL,
            poster         VARCHAR(500) NULL,
            drive_id       VARCHAR(120) NULL,
            token_publico  VARCHAR(40)  NULL,
            mime           VARCHAR(60)  NULL,
            tamanho        BIGINT       NULL,
            duracao_seg    INT          NULL,
            status         VARCHAR(10)  NOT NULL DEFAULT 'pronto',
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dono (dono_tipo, dono_id, ordem),
            INDEX idx_token (token_publico)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Resposta a outro comentário do mesmo alvo — só 1 nível (responde sempre
    // ao comentário-pai, nunca a outra resposta), igual Instagram/feed comum.
    $cpCols = array_column($pdo->query("SHOW COLUMNS FROM intus_comentario_pub")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('resposta_a', $cpCols)) $pdo->exec("ALTER TABLE intus_comentario_pub ADD COLUMN resposta_a INT NULL AFTER alvo_id, ADD INDEX idx_resposta (resposta_a)");
    // Limite de texto: legenda do post e comentário passaram de 500 para 1000
    // caracteres. Só alarga (nenhum dado muda) e só se a coluna ainda é 500.
    foreach ([['intus_feed_post', 'legenda', 'NULL'], ['intus_comentario_pub', 'texto', 'NOT NULL']] as $_alarga) {
        try {
            $_colAl = $pdo->query("SHOW COLUMNS FROM `{$_alarga[0]}` LIKE '{$_alarga[1]}'")->fetch(PDO::FETCH_ASSOC);
            if ($_colAl && stripos((string)$_colAl['Type'], 'varchar(500)') === 0) {
                $pdo->exec("ALTER TABLE `{$_alarga[0]}` MODIFY COLUMN `{$_alarga[1]}` VARCHAR(1000) CHARACTER SET utf8mb4 {$_alarga[2]}");
            }
        } catch (Throwable $e) {}
    }

    // Quadro de Resultados: depoimento/evolução publicado pelo aluno. Foto e vídeo
    // ficam em intus_midia (dono_tipo 'resultado'). autoriza_app é o aceite
    // obrigatório (exibir pros outros alunos); autoriza_site/destaque_site só
    // preparam o terreno pras páginas públicas do site (nada é exposto ainda).
    // ativo: 1 = visível, 2 = montando (mídia subindo), 0 = apagado.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_resultado (
            idresultado   INT AUTO_INCREMENT PRIMARY KEY,
            idatleta      INT NOT NULL,
            tipo          VARCHAR(12)  NOT NULL DEFAULT 'depoimento',
            texto         VARCHAR(1000) NULL,
            periodo_txt   VARCHAR(60)  NULL,
            autoriza_app  TINYINT(1) NOT NULL DEFAULT 1,
            autoriza_site TINYINT(1) NOT NULL DEFAULT 0,
            destaque_site TINYINT(1) NOT NULL DEFAULT 0,
            ativo         TINYINT(1) NOT NULL DEFAULT 1,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_atleta (idatleta), INDEX idx_ativo (ativo, idresultado)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Resultados cadastrados pela EQUIPE (admin): vídeo do YouTube/Instagram, print de conversa e
    // antes/depois de ex-aluno que autorizou a divulgação. Não pertencem a um aluno do app
    // (idatleta = 0): o nome de exibição e a observação da autorização ficam na própria linha.
    $rsCols = array_column($pdo->query("SHOW COLUMNS FROM intus_resultado")->fetchAll(PDO::FETCH_ASSOC), 'Field');
    if (!in_array('origem', $rsCols)) $pdo->exec("ALTER TABLE intus_resultado ADD COLUMN origem VARCHAR(10) NOT NULL DEFAULT 'aluno'");
    if (!in_array('autor_nome', $rsCols)) $pdo->exec("ALTER TABLE intus_resultado ADD COLUMN autor_nome VARCHAR(120) NULL");
    if (!in_array('link_tipo', $rsCols)) $pdo->exec("ALTER TABLE intus_resultado ADD COLUMN link_tipo VARCHAR(12) NULL");
    if (!in_array('link_id', $rsCols)) $pdo->exec("ALTER TABLE intus_resultado ADD COLUMN link_id VARCHAR(80) NULL");
    if (!in_array('autorizacao_obs', $rsCols)) $pdo->exec("ALTER TABLE intus_resultado ADD COLUMN autorizacao_obs VARCHAR(255) NULL");
    _intusGarantirUtf8mb4($pdo, 'intus_resultado', ['texto', 'periodo_txt', 'autor_nome', 'autorizacao_obs']);

    // Nutrição: o aluno registra cada refeição do plano (feito/parcial/fora, com foto opcional) e a
    // água do dia. A nutri vê, responde a registros e acompanha a aderência. Só tabelas novas.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_nutri_registro (
            idregistro     INT AUTO_INCREMENT PRIMARY KEY,
            idatleta       INT NOT NULL,
            idplano        INT NULL,
            data           DATE NOT NULL,
            refeicao       VARCHAR(80) NOT NULL,
            status         VARCHAR(10) NOT NULL DEFAULT 'feito',
            obs            VARCHAR(300) NULL,
            foto           VARCHAR(500) NULL,
            resposta_nutri VARCHAR(300) NULL,
            respondido_em  DATETIME NULL,
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reg (idatleta, data, refeicao),
            INDEX idx_data (data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_nutri_registro', ['refeicao', 'obs', 'resposta_nutri']);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_nutri_dia (
            idatleta  INT NOT NULL,
            data      DATE NOT NULL,
            agua_ml   INT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (idatleta, data)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Nutrição: receitas, vídeos e dicas que a nutri publica para os alunos (área Nutrição do app). Uma linha por item:
    // tipo + slug (id público) + título/categoria/ordem/ativo/foto e o resto dos campos em JSON (dados).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_nutri_conteudo (
            idconteudo INT AUTO_INCREMENT PRIMARY KEY,
            tipo       VARCHAR(10)  NOT NULL,
            slug       VARCHAR(80)  NOT NULL,
            titulo     VARCHAR(200) NOT NULL,
            cat        VARCHAR(40)  NULL,
            ordem      INT          NOT NULL DEFAULT 0,
            ativo      TINYINT(1)   NOT NULL DEFAULT 1,
            foto       VARCHAR(500) NULL,
            dados      LONGTEXT     NULL,
            created_by INT          NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_conteudo (tipo, slug),
            INDEX idx_tipo_ordem (tipo, ativo, ordem)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_nutri_conteudo', ['titulo', 'dados']);

    // Privacidade do perfil social: quem vê as fotos (todos | amigos | eu) e quais partes do perfil
    // ficam à mostra. Sem linha = tudo visível para todos (o comportamento de antes).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_perfil_config (
            idatleta           INT PRIMARY KEY,
            fotos_visib        VARCHAR(10) NOT NULL DEFAULT 'todos',
            mostrar_conquistas TINYINT(1)  NOT NULL DEFAULT 1,
            mostrar_resultados TINYINT(1)  NOT NULL DEFAULT 1,
            mostrar_semana     TINYINT(1)  NOT NULL DEFAULT 1,
            updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Privacidade de cada publicação (todos | amigos | eu). Sem linha, vale o padrão do perfil (intus_perfil_config).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_post_visib (
            idpost     INT PRIMARY KEY,
            visib      VARCHAR(10) NOT NULL DEFAULT 'todos',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // ── Conexões entre alunos: ESTRUTURAS PRONTAS, DESLIGADAS ──────────────────────────────────
    // Nada abaixo é usado enquanto INTUS_CONEXOES_ATIVO for false (ver a constante mais adiante).
    // As tabelas existem para a ativação ser só ligar a chave, sem migração na hora.
    // Amizade: um pedido (de -> para) que vira 'aceita'; vale nos dois sentidos.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_amizade (
            idamizade  INT AUTO_INCREMENT PRIMARY KEY,
            de_id      INT NOT NULL,
            para_id    INT NOT NULL,
            status     VARCHAR(10) NOT NULL DEFAULT 'pendente',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            aceita_em  DATETIME NULL,
            UNIQUE KEY uniq_par (de_id, para_id),
            INDEX idx_para (para_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Turma: grupo com ranking e mural internos, entrada por convite.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_turma (
            idturma    INT AUTO_INCREMENT PRIMARY KEY,
            nome       VARCHAR(80) NOT NULL,
            descricao  VARCHAR(300) NULL,
            criador_tipo VARCHAR(10) NOT NULL DEFAULT 'prof',
            criador_id INT NOT NULL,
            convite    VARCHAR(32) NULL,
            ativo      TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_convite (convite)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_turma', ['nome', 'descricao']);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_turma_membro (
            idturma    INT NOT NULL,
            idatleta   INT NOT NULL,
            papel      VARCHAR(10) NOT NULL DEFAULT 'membro',
            entrou_em  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (idturma, idatleta),
            INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Desafio entre alunos: meta com prazo (dias de treino, de cardio, de atividade ou pontos), numa turma ou entre amigos.
    // ATENÇÃO: os nomes intus_desafio e intus_desafio_participante pertencem ao recurso Desafios do admin (desafios.php),
    // com outro formato. Estas são tabelas à parte, de propósito.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_desafio_aluno (
            iddesafio  INT AUTO_INCREMENT PRIMARY KEY,
            idturma    INT NULL,
            titulo     VARCHAR(80) NOT NULL,
            descricao  VARCHAR(200) NULL,
            metrica    VARCHAR(12) NOT NULL DEFAULT 'treinos',
            meta       INT NOT NULL DEFAULT 12,
            inicio     DATE NOT NULL,
            fim        DATE NOT NULL,
            criador_id INT NOT NULL,
            ativo      TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_periodo (inicio, fim),
            INDEX idx_turma (idturma)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_desafio_aluno', ['titulo', 'descricao']);
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_desafio_aluno_part (
            iddesafio  INT NOT NULL,
            idatleta   INT NOT NULL,
            entrou_em  DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (iddesafio, idatleta),
            INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    // Parceria de treino: dupla com pedido e aceite.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_parceria (
            idparceria INT AUTO_INCREMENT PRIMARY KEY,
            de_id      INT NOT NULL,
            para_id    INT NOT NULL,
            status     VARCHAR(10) NOT NULL DEFAULT 'pendente',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            aceita_em  DATETIME NULL,
            UNIQUE KEY uniq_dupla (de_id, para_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Preferências de visibilidade do feed: 'ver' = eu não quero VER os posts
    // de idalvo; 'mostrar' = eu não quero que idalvo veja os MEUS posts.
    // As duas direções cabem na mesma tabela porque a regra de leitura é
    // idêntica dos dois lados — só troca quem é o dono da preferência.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_feed_pref (
            idpref      INT AUTO_INCREMENT PRIMARY KEY,
            idatleta    INT NOT NULL,
            idalvo      INT NOT NULL,
            tipo        ENUM('ver','mostrar') NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pref (idatleta, idalvo, tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_config (
            chave   VARCHAR(60) PRIMARY KEY,
            valor   LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_profile (
            idatleta   INT PRIMARY KEY,
            avatar     LONGTEXT NULL,
            prefs      TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_anamnese (
            idatleta   INT PRIMARY KEY,
            dados      LONGTEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // "Meus Alimentos" (banco de alimentos personalizado do profissional, usado
    // pelo editor de plano alimentar). Antes só existia em localStorage do
    // navegador — sumia ao trocar de aparelho/navegador ou limpar dados, e o
    // alimento cadastrado no computador não aparecia buscando pelo celular.
    // Agora é tabela de verdade, uma por profissional (idprofessor).
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_alimento_personalizado (
            id                 INT AUTO_INCREMENT PRIMARY KEY,
            idprofessor        INT NOT NULL,
            description        VARCHAR(255) NOT NULL,
            category           VARCHAR(120) NULL,
            energy_kcal        DECIMAL(8,2)  NOT NULL DEFAULT 0,
            protein_g          DECIMAL(8,2)  NOT NULL DEFAULT 0,
            carbohydrate_g     DECIMAL(8,2)  NOT NULL DEFAULT 0,
            lipid_g            DECIMAL(8,2)  NOT NULL DEFAULT 0,
            fiber_g            DECIMAL(8,2)  NOT NULL DEFAULT 0,
            sodium_mg          DECIMAL(9,2)  NOT NULL DEFAULT 0,
            calcium_mg         DECIMAL(9,2)  NOT NULL DEFAULT 0,
            iron_mg            DECIMAL(8,3)  NOT NULL DEFAULT 0,
            potassium_mg       DECIMAL(9,2)  NOT NULL DEFAULT 0,
            magnesium_mg       DECIMAL(8,2)  NOT NULL DEFAULT 0,
            phosphorus_mg      DECIMAL(9,2)  NOT NULL DEFAULT 0,
            zinc_mg            DECIMAL(8,3)  NOT NULL DEFAULT 0,
            vitaminC_mg        DECIMAL(8,2)  NOT NULL DEFAULT 0,
            cholesterol_mg     DECIMAL(8,2)  NOT NULL DEFAULT 0,
            saturated_g        DECIMAL(8,2)  NOT NULL DEFAULT 0,
            monounsaturated_g  DECIMAL(8,2)  NOT NULL DEFAULT 0,
            polyunsaturated_g  DECIMAL(8,2)  NOT NULL DEFAULT 0,
            thiamine_mg        DECIMAL(8,3)  NOT NULL DEFAULT 0,
            riboflavin_mg      DECIMAL(8,3)  NOT NULL DEFAULT 0,
            niacin_mg          DECIMAL(8,2)  NOT NULL DEFAULT 0,
            porcao_padrao      DECIMAL(8,2)  NOT NULL DEFAULT 100,
            medida_padrao      VARCHAR(60)   NOT NULL DEFAULT 'g',
            created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_professor (idprofessor)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_alimento_personalizado', ['description', 'category', 'medida_padrao']);

    // Medidas caseiras que a equipe cadastra para qualquer alimento do banco (ex.: "pote" de 170 g de um iogurte, "concha" de 80 g do feijão).
    // food_id é o número do alimento no app: TACO (1 a 597), extras (a partir de 100000) ou Meus alimentos (1000000 + id). Só tabela nova.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS intus_alimento_medida (
            idmedida    INT AUTO_INCREMENT PRIMARY KEY,
            food_id     INT NOT NULL,
            nome        VARCHAR(60) NOT NULL,
            gramas      DECIMAL(8,2) NOT NULL,
            idprofessor INT NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_food (food_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    _intusGarantirUtf8mb4($pdo, 'intus_alimento_medida', ['nome']);
}
// A checagem de tabelas/colunas (dezenas de comandos no banco) rodava em TODO pedido, inclusive nos
// avisos do sino que cada app aberto faz a cada 45 s. Agora roda uma vez por versão deste arquivo
// (a marca muda sempre que o arquivo é publicado) e fica registrada na pasta temporária do servidor.
// Se a pasta não aceitar escrita, tudo continua como antes (roda a cada pedido).
$_bootMarca = sys_get_temp_dir() . '/intus_catalogo_boot_' . md5(__FILE__) . '_' . (int)@filemtime(__FILE__);
if (!is_file($_bootMarca)) {
    try { ensureCatalogoTables($pdo); } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'bootstrap falhou', 'detalhe' => _intusLogErro($e)]);
        exit;
    }
    @touch($_bootMarca);
}

// Resolve tipo/id/nome de quem está autenticado, pra reações/comentários/posts
// nunca aceitarem o nome que o próprio cliente diga que é. Extraída daqui pra
// não copiar essa busca de coluna (tabela de atleta/professor não tem nome
// de coluna garantido) em cada ação nova que precisar assinar algo.
// Chave das conexões entre alunos (amizade, turmas, desafios, parceiro de treino).
// LIGADA em 06/10/2026 a pedido do Luiz. Para desligar (esconde tudo no app e os endpoints respondem 403),
// troque para false e publique. Desligada, ninguém consegue pedir amizade, então "só amigos" funciona como "só eu".
if (!defined('INTUS_CONEXOES_ATIVO')) define('INTUS_CONEXOES_ATIVO', true);
// Aluno criar desafio (aba Desafios da Comunidade). DESLIGADO de propósito: por enquanto só a equipe cria desafios,
// pelo recurso Desafios do painel (desafios.php). Para liberar aos alunos no futuro, troque para true e publique.
if (!defined('INTUS_ALUNO_CRIA_DESAFIO')) define('INTUS_ALUNO_CRIA_DESAFIO', false);

// Configuração de privacidade do perfil de um aluno. Sem linha = tudo visível para todos.
function _perfilConfig(PDO $pdo, int $idatleta): array {
    $cfg = ['fotos_visib' => 'todos', 'mostrar_conquistas' => 1, 'mostrar_resultados' => 1, 'mostrar_semana' => 1];
    if ($idatleta <= 0) return $cfg;
    try {
        $st = $pdo->prepare("SELECT fotos_visib, mostrar_conquistas, mostrar_resultados, mostrar_semana FROM intus_perfil_config WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $cfg['fotos_visib'] = in_array($r['fotos_visib'], ['todos', 'amigos', 'eu'], true) ? $r['fotos_visib'] : 'todos';
            foreach (['mostrar_conquistas', 'mostrar_resultados', 'mostrar_semana'] as $k) $cfg[$k] = (int)$r[$k] ? 1 : 0;
        }
    } catch (Throwable $e) {}
    return $cfg;
}
// Ids de alunos que são amigos de $idatleta (pedido aceito, nos dois sentidos).
function _amigosDe(PDO $pdo, int $idatleta): array {
    if ($idatleta <= 0) return [];
    try {
        $st = $pdo->prepare("SELECT de_id, para_id FROM intus_amizade WHERE status = 'aceita' AND (de_id = ? OR para_id = ?)");
        $st->execute([$idatleta, $idatleta]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ((int)$r['de_id'] === $idatleta) ? (int)$r['para_id'] : (int)$r['de_id'];
        return array_values(array_unique($out));
    } catch (Throwable $e) { return []; }
}

// Parceiros de treino de $idatleta (convite aceito, nos dois sentidos).
function _parceirosDe(PDO $pdo, int $idatleta): array {
    if ($idatleta <= 0) return [];
    try {
        $st = $pdo->prepare("SELECT de_id, para_id FROM intus_parceria WHERE status = 'aceita' AND (de_id = ? OR para_id = ?)");
        $st->execute([$idatleta, $idatleta]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ((int)$r['de_id'] === $idatleta) ? (int)$r['para_id'] : (int)$r['de_id'];
        return array_values(array_unique($out));
    } catch (Throwable $e) { return []; }
}
// Situação entre o espectador ($a) e o outro aluno ($b) numa tabela de pares (amizade ou parceria):
// 'aceita', 'enviado' (eu pedi), 'recebido' (ele pediu) ou null.
function _estadoPar(PDO $pdo, string $tabela, int $a, int $b): ?string {
    if (!in_array($tabela, ['intus_amizade', 'intus_parceria'], true) || $a <= 0 || $b <= 0) return null;
    try {
        $st = $pdo->prepare("SELECT de_id, status FROM `$tabela` WHERE (de_id = ? AND para_id = ?) OR (de_id = ? AND para_id = ?) LIMIT 1");
        $st->execute([$a, $b, $b, $a]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        if ($r['status'] === 'aceita') return 'aceita';
        return ((int)$r['de_id'] === $a) ? 'enviado' : 'recebido';
    } catch (Throwable $e) { return null; }
}
// Nome e foto de perfil de uma lista de alunos (para as telas de amigos e parceiros).
function _pessoasMapa(PDO $pdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = [];
    if (!$ids) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $tbl = _feedDetectarTabelaAtleta($pdo);
    if ($tbl) {
        try {
            $st = $pdo->prepare("SELECT idatleta, nome FROM `$tbl` WHERE idatleta IN ($ph)");
            $st->execute($ids);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['idatleta']] = ['nome' => (string)$r['nome'], 'avatar' => ''];
        } catch (Throwable $e) {}
    }
    try {
        $st = $pdo->prepare("SELECT idatleta, avatar FROM intus_profile WHERE idatleta IN ($ph) AND avatar IS NOT NULL AND avatar != ''");
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $k = (int)$r['idatleta']; if (isset($out[$k])) $out[$k]['avatar'] = $r['avatar']; }
    } catch (Throwable $e) {}
    return $out;
}

// Privacidade efetiva de uma publicação: a dela, ou o padrão do perfil do dono, ou 'todos'.
function _visibPost(PDO $pdo, int $idpost): string {
    try {
        $st = $pdo->prepare("SELECT COALESCE(v.visib, c.fotos_visib, 'todos') FROM intus_feed_post p
                             LEFT JOIN intus_post_visib v ON v.idpost = p.idpost
                             LEFT JOIN intus_perfil_config c ON c.idatleta = p.idatleta WHERE p.idpost = ?");
        $st->execute([$idpost]);
        $v = (string)$st->fetchColumn();
        return in_array($v, ['todos', 'amigos', 'eu'], true) ? $v : 'todos';
    } catch (Throwable $e) { return 'todos'; }
}

// Turmas ativas em que o aluno está.
function _turmasDe(PDO $pdo, int $idatleta): array {
    if ($idatleta <= 0) return [];
    try {
        $st = $pdo->prepare("SELECT m.idturma FROM intus_turma_membro m JOIN intus_turma t ON t.idturma = m.idturma WHERE m.idatleta = ? AND t.ativo = 1");
        $st->execute([$idatleta]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) { return []; }
}
// Código de convite da turma: 8 letras e números sem os que se confundem (0, O, 1, I, L).
function _novoCodigoTurma(PDO $pdo): string {
    $alf = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($t = 0; $t < 8; $t++) {
        $cod = '';
        for ($i = 0; $i < 8; $i++) $cod .= $alf[random_int(0, strlen($alf) - 1)];
        $st = $pdo->prepare("SELECT 1 FROM intus_turma WHERE convite = ?");
        $st->execute([$cod]);
        if (!$st->fetchColumn()) return $cod;
    }
    return strtoupper(substr(md5(uniqid('', true)), 0, 8));
}

// Nome com que um professor aparece PARA OS ALUNOS (comentário, curtida, aviso do sino).
// O cadastro interno do Luiz é "Luiz Nunes (Master)"; para a turma ele é o treinador.
function _nomePublicoProf(int $id, string $nome): string {
    return $id === 1 ? 'Luiz Nunes - Treinador' : $nome;
}

function _resolverAutorPub(PDO $pdo, array $_ctx, bool $_ehAluno) {
    $tipo = $_ehAluno ? 'aluno' : 'prof';
    $id   = $_ehAluno
        ? (int)($_ctx['idatleta'] ?? $_ctx['idusuario'] ?? 0)
        : (int)($_ctx['idusuario'] ?? 0);
    $nome = '';
    if ($id > 0) {
        try {
            $tabelas = $_ehAluno ? ['atleta', 'atletas', 'aluno', 'alunos'] : ['professor', 'usuario', 'usuarios', 'professores'];
            $idCols  = $_ehAluno ? ['idatleta','idaluno','id'] : ['idusuario','idprofessor','id'];
            $nmCols  = $_ehAluno ? ['nome','nmathleta','nmatleta','name'] : ['nome','nmusuario','nmprofessor','name'];
            foreach ($tabelas as $_t) {
                try {
                    $_cols = array_column($pdo->query("SHOW COLUMNS FROM `$_t`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                } catch (Throwable $e) { continue; }
                $_ci = null; foreach ($idCols as $_c) { if (in_array($_c, $_cols, true)) { $_ci = $_c; break; } }
                $_cn = null; foreach ($nmCols as $_c) { if (in_array($_c, $_cols, true)) { $_cn = $_c; break; } }
                if (!$_ci || !$_cn) continue;
                try {
                    $q = $pdo->prepare("SELECT `$_cn` FROM `$_t` WHERE `$_ci` = ? LIMIT 1");
                    $q->execute([$id]);
                    $nome = (string)$q->fetchColumn();
                } catch (Throwable $e) {}
                break;
            }
        } catch (Throwable $e) {}
    }
    if ($nome === '') $nome = $_ehAluno ? 'Aluno' : 'Professor';
    if (!$_ehAluno) $nome = _nomePublicoProf($id, $nome);
    return [$tipo, $id, $nome];
}

require_once __DIR__ . '/_notificacoes.php';   // helpers do sino (_criarNotificacao, menções)

// Salva uma imagem base64 (data URI) em app/img/feed e devolve a URL pública.
// Lança Exception com mensagem curta se for inválida. Mesma regra do POST do Feed.
// Reduz uma foto para o tamanho que a tela do celular realmente mostra. Devolve os bytes originais se não der ou não compensar.
function _otimizarFotoBin(string $bin, string $ext, int $maxLado = 1440, int $qualidade = 86): string {
    try {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg') || $ext === 'webp') return $bin;
        $info = @getimagesizefromstring($bin);
        if (!$info || empty($info[0]) || empty($info[1])) return $bin;
        $w = (int)$info[0]; $h = (int)$info[1];
        if ($w * $h > 40000000) return $bin;            // evita estourar a memória do PHP com foto gigante
        $png = ($ext === 'png');
        if ($png && ($info['channels'] ?? 3) == 4) return $bin;   // PNG com transparência fica como está
        if (max($w, $h) <= $maxLado && strlen($bin) < 600 * 1024) return $bin;   // já é pequena
        $src = @imagecreatefromstring($bin);
        if (!$src) return $bin;
        $f = min(1, $maxLado / max($w, $h));
        $nw = max(1, (int)round($w * $f)); $nh = max(1, (int)round($h * $f));
        $dst = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($src);
        ob_start(); imagejpeg($dst, null, $qualidade); $novo = (string)ob_get_clean();
        imagedestroy($dst);
        return ($novo !== '' && strlen($novo) < strlen($bin) && !$png) ? $novo : $bin;
    } catch (Throwable $e) { return $bin; }
}

function _salvarImagemFeedB64(string $raw, int $autorId, string $pasta = 'feed', string $prefixo = 'feed'): string {
    if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#is', $raw, $m)) throw new Exception('formato de imagem invalido');
    if (!preg_match('/^[a-z]{3,12}$/', $pasta)) $pasta = 'feed';
    $dir = __DIR__ . '/../img/' . $pasta;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 8 * 1024 * 1024) throw new Exception('imagem invalida ou grande demais');
    // 07/10/2026: foto grande demais (app antigo manda até 1920 px a 94%) é reduzida no servidor antes de guardar:
    // lado maior 1440 px, JPEG 86%. Só mexe em JPEG/PNG sem transparência quando a GD existe e só se o resultado ficar
    // menor; qualquer falha guarda o original, como sempre foi.
    $bin = _otimizarFotoBin($bin, $ext);
    if (!is_dir($dir) || !is_writable($dir)) throw new Exception('sem permissao de escrita');
    try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
    $fname = preg_replace('/[^a-z]/', '', $prefixo) . '_' . $autorId . '_' . time() . '_' . $rand . '.' . $ext;
    if (@file_put_contents($dir . '/' . $fname, $bin) === false) throw new Exception('falha ao salvar imagem');
    if (function_exists('gdriveBackup')) { try { gdriveBackup($bin, $fname, 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)); } catch (Throwable $e) {} }
    return _appBaseUrl() . '/img/' . $pasta . '/' . $fname;
}

// Aceita só link de vídeo do YouTube ou post/reel do Instagram e guarda apenas o IDENTIFICADOR
// (nunca a URL digitada): quem monta o endereço do player é o próprio app. Devolve [tipo, id] ou null.
function _resultadoParseLink(string $url): ?array {
    $url = trim($url);
    if ($url === '') return null;
    if (preg_match('~^https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^#]*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $url, $m)) return ['youtube', $m[1]];
    if (preg_match('~^https?://(?:www\.)?instagram\.com/(p|reel|reels|tv)/([A-Za-z0-9_-]{5,40})~i', $url, $m)) {
        $k = strtolower($m[1]) === 'reels' ? 'reel' : strtolower($m[1]);
        return ['instagram', $k . '/' . $m[2]];
    }
    return null;
}

// Quem avisar quando um aluno publica um resultado: professores responsáveis
// pelo aluno + administradores. Devolve ids de professor (destino_tipo 'prof').
function _resultadoDestinatariosProf(PDO $pdo, int $idatleta): array {
    $ids = [];
    $tbl = _feedDetectarTabelaAtleta($pdo);
    if ($tbl) {
        try {
            $st = $pdo->prepare("SELECT professores_responsaveis FROM `$tbl` WHERE idatleta = ? LIMIT 1");
            $st->execute([$idatleta]);
            $raw = (string)$st->fetchColumn();
            $tmp = json_decode($raw, true);
            if (!is_array($tmp)) $tmp = preg_split('/\D+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($tmp as $x) $ids[] = (int)$x;
        } catch (Throwable $e) {}
    }
    $achouAdmin = false;
    try {
        foreach ($pdo->query("SELECT idprofessor FROM professor WHERE tpacesso = 'A'")->fetchAll(PDO::FETCH_COLUMN) as $a) { $ids[] = (int)$a; $achouAdmin = true; }
    } catch (Throwable $e) {}
    if (!$achouAdmin) $ids[] = 1;
    return array_values(array_unique(array_filter($ids)));
}

// Resultado passou a valer (texto puro, ou mídia toda enviada): avisa a equipe.
function _resultadoPublicar(PDO $pdo, int $idresultado, int $idatleta, string $nome, string $texto): void {
    $trecho = $texto !== '' ? $texto : 'compartilhou um resultado';
    foreach (_resultadoDestinatariosProf($pdo, $idatleta) as $idprof) {
        _criarNotificacao($pdo, 'prof', $idprof, 'resultado_novo', 'aluno', $idatleta, $nome, 'resultado', $idresultado, $trecho);
    }
}

// Mídias de vários posts de uma vez: [idpost => [{tipo,url,poster,token?,duracao?}...]].
// Nunca devolve drive_id. Só mídia 'pronto'.
function _midiasDosPosts(PDO $pdo, string $donoTipo, array $ids): array {
    $out = [];
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return $out;
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT dono_id, tipo, url, poster, token_publico, duracao_seg FROM intus_midia
                             WHERE dono_tipo = ? AND dono_id IN ($ph) AND status = 'pronto' ORDER BY dono_id, ordem, idmidia");
        $st->execute(array_merge([$donoTipo], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $item = ['tipo' => $r['tipo'], 'url' => $r['url'], 'poster' => $r['poster']];
            if ($r['tipo'] === 'video') { $item['token'] = $r['token_publico']; $item['duracao'] = (int)$r['duracao_seg']; }
            $out[(int)$r['dono_id']][] = $item;
        }
    } catch (Throwable $e) {}
    return $out;
}

// Dono (aluno) de um post do Feed, ou 0 se não existe/foi apagado.
function _donoDoPostFeed(PDO $pdo, int $idpost): int {
    try {
        $q = $pdo->prepare("SELECT idatleta FROM intus_feed_post WHERE idpost = ? AND ativo = 1 LIMIT 1");
        $q->execute([$idpost]);
        return (int)$q->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// Mensagem do CHAT (intus_mensagem, em mensagens.php) a que quem pergunta tem
// acesso: o aluno só da própria conversa, o professor da própria carteira,
// admin de todas. null = não existe ou sem acesso.
function _chatMensagem(PDO $pdo, array $ctx, bool $ehAluno, int $id) {
    try {
        $q = $pdo->prepare("SELECT idmensagem, idatleta, idusuario, remetente, texto FROM intus_mensagem WHERE idmensagem = ? LIMIT 1");
        $q->execute([$id]);
        $m = $q->fetch(PDO::FETCH_ASSOC);
        if (!$m) return null;
        if ($ehAluno) return ((int)$m['idatleta'] === (int)($ctx['idatleta'] ?? $ctx['idusuario'] ?? 0)) ? $m : null;
        if (!empty($ctx['admin'])) return $m;
        return in_array((int)$m['idatleta'], getAtletasDoUsuario($pdo, (int)($ctx['idusuario'] ?? 0)), true) ? $m : null;
    } catch (Throwable $e) { return null; }
}

// Dono (aluno) do "alvo" de um comentário: post do Feed ou comentário do MURAL
// do ranking (tabela própria em treinos.php). O mural segue a mesma lógica do
// Feed: cada comentário dele é o "post" e as respostas são intus_comentario_pub
// com alvo_tipo='mural'. 0 = não existe.
function _donoDoAlvoPub(PDO $pdo, string $alvoTipo, int $alvoId): int {
    if ($alvoTipo === 'feed_post') return _donoDoPostFeed($pdo, $alvoId);
    if ($alvoTipo === 'mural') {
        try {
            $q = $pdo->prepare("SELECT idatleta FROM intus_ranking_comentario WHERE idcomentario = ? LIMIT 1");
            $q->execute([$alvoId]);
            return (int)$q->fetchColumn();
        } catch (Throwable $e) { return 0; }
    }
    return 0;
}

function jsonBody() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

// ── Substitutos de alongamento: mesmo esquema de nomesToIds/subsToNomes de
// treinos.php (catalogo de exercicios) — grava ID no banco, devolve nome pro
// front-end. Fonte unica: renomear um alongamento nao quebra quem o referencia
// como substituto, porque a referencia e por ID, resolvida contra o catalogo
// atual a cada leitura.
$_alongNomeMap = null;
function getAlongNomeMap(PDO $pdo): array {
    global $_alongNomeMap;
    if ($_alongNomeMap !== null) return $_alongNomeMap;
    $_alongNomeMap = [];
    try {
        $rows = $pdo->query("SELECT idalongamento, nome FROM intus_alongamento")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) $_alongNomeMap[(int)$row['idalongamento']] = $row['nome'];
    } catch (Throwable $e) {}
    return $_alongNomeMap;
}
function alongSubsToNomes(array $raw, PDO $pdo): array {
    $nomeMap = getAlongNomeMap($pdo);
    $nomes = [];
    foreach ($raw as $v) {
        if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
            $id = (int)$v;
            if (isset($nomeMap[$id])) $nomes[] = $nomeMap[$id];
        } elseif (is_string($v) && trim($v) !== '') {
            $nomes[] = trim($v);
        }
    }
    return array_values(array_unique($nomes));
}
function alongNomesToIds(array $raw, PDO $pdo): array {
    $nomeMap = getAlongNomeMap($pdo);
    $normMap = [];
    foreach ($nomeMap as $id => $nm) $normMap[mb_strtolower(trim($nm), 'UTF-8')] = $id;
    $ids = [];
    foreach ($raw as $v) {
        if (is_int($v) || (is_string($v) && ctype_digit(trim($v)))) {
            $ids[] = (int)$v;
        } elseif (is_string($v) && trim($v) !== '') {
            $key = mb_strtolower(trim($v), 'UTF-8');
            if (isset($normMap[$key])) $ids[] = $normMap[$key];
        }
    }
    return array_values(array_unique($ids));
}

// Backup no Google Drive (plugável): fica inativo até existir /app/config/gdrive.json
@include_once __DIR__ . '/_gdrive.php';

function _appBaseUrl() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/app/api/catalogo.php';
    $b = rtrim(dirname(dirname($script)), '/');
    if ($b === '' || $b === '.' || $b === '/') $b = '/app';
    return $b;
}

// Persiste fotos como ARQUIVOS no servidor (seguro) e faz backup no Drive se configurado.
// Recebe um array (data URLs e/ou URLs já salvas) e devolve um array só de URLs. Nunca perde:
// se algo falhar, mantém o valor original.
function _persistirFotos($fotos) {
    if (!is_array($fotos)) return [];
    $dir = __DIR__ . '/../img/avaliacoes';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $base = _appBaseUrl();
    $out = [];
    foreach ($fotos as $f) {
        $f = is_string($f) ? $f : (is_array($f) && isset($f['url']) ? $f['url'] : '');
        if ($f === '') continue;
        if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', $f, $m)) {
            $out[] = $f; // já é URL/arquivo — mantém
            continue;
        }
        if (!is_dir($dir) || !is_writable($dir)) { $out[] = $f; continue; } // não perde
        $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > 6 * 1024 * 1024) { $out[] = $f; continue; }
        try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
        $fname = 'av_' . time() . '_' . $rand . '.' . $ext;
        if (@file_put_contents($dir . '/' . $fname, $bin) === false) { $out[] = $f; continue; }
        if (function_exists('gdriveBackup')) { try { gdriveBackup($bin, $fname, 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)); } catch (Throwable $e) {} }
        $out[] = $base . '/img/avaliacoes/' . $fname;
    }
    return $out;
}

function _notificarFotosAvaliacao(PDO $pdo, int $idatleta, int $qtdFotos, string $data) {
    try {
        $nome = 'Atleta #' . $idatleta;
        foreach (['atleta', 'atletas'] as $t) {
            try {
                $st = $pdo->prepare("SELECT nome FROM `$t` WHERE idatleta = ? LIMIT 1");
                $st->execute([$idatleta]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                if ($r && !empty($r['nome'])) { $nome = $r['nome']; break; }
            } catch (Throwable $e) {}
        }

        $to = 'contato@intusfit.com.br';
        $subject = "Novas fotos de avaliação: $nome";
        $body  = "O aluno $nome enviou $qtdFotos foto(s) de avaliação.\n\n";
        $body .= "Data da avaliação: " . date('d/m/Y', strtotime($data)) . "\n";
        $body .= "Acesse o painel para visualizar.\n\n";
        $body .= "— Intus Fit (notificação automática)";

        _catalogoSendEmail($to, $subject, $body);
    } catch (Throwable $e) {}
}

function _catalogoSendEmail(string $to, string $subject, string $body) {
    $smtpCfg = @include __DIR__ . '/../config/smtp.php';
    if (!is_array($smtpCfg)) $smtpCfg = [];
    $smtpHost = $smtpCfg['host'] ?? 'smtp.intusfit.com.br';
    $smtpUser = $smtpCfg['user'] ?? 'contato@intusfit.com.br';
    $smtpPass = $smtpCfg['pass'] ?? '';
    $fromName = $smtpCfg['from_name'] ?? 'Intus Fit';

    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("ssl://$smtpHost:465", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        @file_put_contents(__DIR__ . '/../config/email_erros.log',
            date('c') . " | SMTP inacessivel ($errno $errstr) | para=$to | assunto=$subject\n", FILE_APPEND);
        return @mail($to, $subject, $body, "From: $fromName <$smtpUser>\r\n");
    }

    $read = function() use ($sock) { $r = ''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
    // Antes, nenhuma resposta do SMTP era lida: se a autenticacao falhasse (senha
    // vazia por falta de /app/config/smtp.php, por exemplo) a mensagem sumia em
    // silencio e ninguem ficava sabendo. Agora conferimos o codigo de cada etapa.
    $falhou = function($resp, $esperado) { return (int)substr(trim((string)$resp), 0, 3) !== $esperado; };
    $logErro = function($etapa, $resp) use ($to, $subject) {
        $linha = date('c') . " | SMTP FALHOU em $etapa | para=$to | assunto=$subject | resposta=" . trim(substr((string)$resp, 0, 200)) . "\n";
        @file_put_contents(__DIR__ . '/../config/email_erros.log', $linha, FILE_APPEND);
        @error_log('[intus-email] ' . $linha);
    };

    $read();
    $send("EHLO intusfit.com.br");
    $send("AUTH LOGIN");
    $send(base64_encode($smtpUser));
    $rAuth = $send(base64_encode($smtpPass));
    if ($falhou($rAuth, 235)) {                        // 235 = autenticado
        $logErro('AUTH (senha SMTP ausente ou invalida)', $rAuth);
        @fclose($sock);
        return @mail($to, $subject, $body, "From: $fromName <$smtpUser>\r\n");
    }
    $rFrom = $send("MAIL FROM:<$smtpUser>");
    if ($falhou($rFrom, 250)) { $logErro('MAIL FROM', $rFrom); @fclose($sock); return false; }
    $rRcpt = $send("RCPT TO:<$to>");
    if ($falhou($rRcpt, 250)) { $logErro('RCPT TO', $rRcpt); @fclose($sock); return false; }
    $rData = $send("DATA");
    if ($falhou($rData, 354)) { $logErro('DATA', $rData); @fclose($sock); return false; }

    $msg = "From: $fromName <$smtpUser>\r\n";
    $msg .= "To: $to\r\n";
    $msg .= "Subject: $subject\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/plain; charset=utf-8\r\n";
    $msg .= "\r\n";
    $msg .= $body;

    $rFim = $send($msg . "\r\n.");
    if ($falhou($rFim, 250)) { $logErro('envio final', $rFim); @fclose($sock); return false; }
    $send("QUIT");
    fclose($sock);
    return true;   // so chega aqui se o servidor confirmou o recebimento
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ═══════════════ CONTROLE DE ACESSO ═══════════════
// Ate aqui, TUDO neste arquivo era publico: qualquer pessoa na internet podia ler
// action=anamneses e baixar a anamnese completa de todos os alunos (cirurgias,
// doencas, medicamentos), sobrescrever a anamnese de qualquer aluno sabendo o id,
// reescrever as regras de ranking/figurinhas e subir arquivos via midia_upload.
// Passamos a exigir token nas rotas sensiveis. Leitura de configuracao do app
// (frases, figurinhas, regras) segue publica de proposito: nao e dado pessoal e o
// app do aluno depende dela em telas antes do login.
if (!function_exists('catalogoBearerToken')) {
    function catalogoBearerToken() {
        $h = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
        elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
            }
        }
        if (preg_match('/Bearer\s+(.+)$/i', trim($h), $m)) return trim($m[1]);
        return '';
    }
}
$_tok = catalogoBearerToken();

// Contexto do token (admin / professor / aluno). Se o helper nao existir no
// servidor, seguimos com contexto vazio — o token continua sendo exigido.
$_ctx = ['admin' => false, 'idusuario' => 0, 'is_aluno' => false];
if (@file_exists(__DIR__ . '/_auth_context.php')) {
    @require_once __DIR__ . '/_auth_context.php';
    if (function_exists('getAuthContext')) {
        try { $c = getAuthContext($pdo, $_tok); if (is_array($c)) $_ctx = array_merge($_ctx, $c); }
        catch (Throwable $e) { /* mantem contexto vazio */ }
    }
}
$_ehAluno = !empty($_ctx['is_aluno']);

// ─── SEGURANCA: token presente porem nao reconhecido ────────────────────────
// Ate aqui as rotas protegidas so conferiam se o cabecalho Authorization
// existia. Nao conferiam se o token QUERIA DIZER alguma coisa. Um texto
// qualquer no lugar do token passava por todas elas: a lista de anamneses —
// dados de saude dos alunos — saia inteira para quem mandasse "Bearer xxxx".
// Token valido e o que devolve um usuario real: sessao gravada em
// intus_sessions, ou formato antigo enquanto PERMITIR_TOKEN_LEGADO estiver
// ligado. Sem isso, $_tokValido e falso e a rota protegida devolve 401.
$_tokValido = ((int)($_ctx['idusuario'] ?? 0) > 0);

$_negar = function ($codigo, $msg) {
    http_response_code($codigo);
    echo json_encode(['error' => $msg]);
    exit;
};

// ═══════════════ CONTROLE DE ACESSO ═══════════════
// ATE 20/08/2026 ISTO ERA UMA LISTA DE ROTAS PROTEGIDAS, E O RESTO PASSAVA.
// Quem nao aparecesse em nenhuma lista caia direto no corpo do arquivo, sem
// token nenhum. Foi assim que "avaliacoes" — peso, gordura, dobras, observacoes
// e o endereco das FOTOS corporais dos alunos — ficou aberta na internet, junto
// com "nutricao", "profile" e "alongamentos". Nao era uma rota esquecida: era o
// desenho do controle, que exigia lembrar de cadastrar cada rota nova.
//
// Agora e o contrario: NADA passa sem token, exceto o que estiver escrito
// explicitamente em $_PUBLICAS. Rota nova nasce protegida. Esquecer de
// cadastrar passou a fechar a porta em vez de abrir.
$_PUBLICAS = [
    'atleta_existe',   // so responde sim/nao sobre existir um id; usada antes do login
];

// Rotas que so professor/admin acessa, em qualquer metodo.
$_SO_PROFESSOR = ['anamneses', 'avisos', 'sessoes', 'email_diag', 'email_teste', 'alimento_medidas'];

// Leitura liberada para aluno logado; escrita so professor. Catalogos e
// configuracoes que o app do aluno precisa ler para desenhar as telas.
$_LEITURA_ALUNO = ['alongamentos', 'frases_config', 'figurinhas_config', 'conquistas_config',
                   'cardio_regras', 'ranking_regras', 'notif_config', 'planos_config',
                   'planos_admin', 'avatars', 'nutri_conteudo'];

// Porta de entrada unica.
if (!in_array($action, $_PUBLICAS, true)) {
    if (!$_tokValido) $_negar(401, 'token ausente ou invalido');
}
if (in_array($action, $_SO_PROFESSOR, true) && $_ehAluno) {
    $_negar(403, 'acesso restrito ao professor');
}
if (in_array($action, $_LEITURA_ALUNO, true) && $method !== 'GET' && $_ehAluno) {
    $_negar(403, 'apenas o professor pode alterar');
}

// ── ESCOPO: aluno so mexe no que e dele ────────────────────────────────────
// Rotas que recebem um id de atleta e nao tinham dono nenhum. "profile"
// aceitava PUT com ?atleta=N sem conferir nada: dava para trocar a foto de
// qualquer aluno, sem sequer estar logado.
$_POR_ATLETA = ['avaliacoes', 'nutricao', 'profile', 'anamnese'];
// A listagem geral, sem id de atleta, e so do professor — a checagem vem ANTES
// de o id do token ser aplicado, senao "sem atleta" nunca acontece.
// Para GET o aluno precisa ter atleta definido; no POST o endpoint fixa o
// atleta pelo token. Exigir ?atleta antes de ler o JSON bloqueava a autoavaliação.
if ($method === 'GET' && in_array($action, ['avaliacoes', 'nutricao'], true) && $_ehAluno && (int)($_GET['atleta'] ?? 0) <= 0) {
    $_negar(400, 'informe o atleta');
}
if (in_array($action, $_POR_ATLETA, true) && $_ehAluno) {
    $_meuId = (int)($_ctx['idatleta'] ?? $_ctx['idusuario'] ?? 0);
    if ($_meuId <= 0) $_negar(401, 'sessao de aluno sem identidade');
    $_pedido = (int)($_GET['atleta'] ?? 0);
    if ($_pedido > 0 && $_pedido !== $_meuId) $_negar(403, 'dados de outro aluno');
    $_GET['atleta'] = $_meuId;   // o id passa a vir do token, nunca da URL
}


try {

// ═══════════════ ALONGAMENTOS ═══════════════
if ($action === 'alongamentos') {
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT * FROM intus_alongamento ORDER BY grupo, nome")->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r) use ($pdo) {
            $subs = null;
            if (isset($r['substitutos']) && $r['substitutos'] !== null && $r['substitutos'] !== '') {
                $tmp = json_decode($r['substitutos'], true);
                if (is_array($tmp) && count($tmp) > 0) $subs = alongSubsToNomes($tmp, $pdo);
            }
            return [
                'idalongamento' => (int)$r['idalongamento'],
                'grupo'         => $r['grupo'],
                'nome'          => $r['nome'],
                'tempo'         => $r['tempo'],
                'obs'           => $r['obs'] ?? '',
                'descricao'     => $r['descricao'] ?? '',
                'videoyoutube'  => $r['videoyoutube'] ?? '',
                'gifalongamento'=> $r['gifalongamento'] ?? '',
                'instrucao_ia'  => $r['instrucao_ia'] ?? '',
                'equipamento'   => $r['equipamento'] ?? '',
                'substitutos'   => $subs,
            ];
        }, $rows);
        echo json_encode($out);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $nome = trim($b['nome'] ?? '');
        if (!$nome) { http_response_code(400); echo json_encode(['error' => 'nome obrigatorio']); exit; }
        $subs = (isset($b['substitutos']) && is_array($b['substitutos']) && count($b['substitutos']) > 0) ? json_encode(alongNomesToIds($b['substitutos'], $pdo)) : null;
        $st = $pdo->prepare("INSERT INTO intus_alongamento (grupo, nome, tempo, obs, descricao, videoyoutube, gifalongamento, instrucao_ia, equipamento, substitutos) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $b['grupo'] ?? 'Geral', $nome, $b['tempo'] ?? '30s',
            $b['obs'] ?? '', $b['descricao'] ?? '', $b['videoyoutube'] ?? '',
            $b['gifalongamento'] ?? '', $b['instrucao_ia'] ?? '',
            $b['equipamento'] ?? null, $subs,
        ]);
        echo json_encode(['ok' => true, 'idalongamento' => (int)$pdo->lastInsertId()]);
        exit;
    }
    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['idalongamento'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $sets = []; $vals = [];
        foreach (['grupo','nome','tempo','obs','descricao','videoyoutube','gifalongamento','instrucao_ia','equipamento'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = $b[$k]; }
        }
        if (array_key_exists('substitutos', $b)) {
            $sets[] = "substitutos = ?";
            $vals[] = (is_array($b['substitutos']) && count($b['substitutos']) > 0) ? json_encode(alongNomesToIds($b['substitutos'], $pdo)) : null;
        }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $vals[] = $id;
        $pdo->prepare("UPDATE intus_alongamento SET " . implode(', ', $sets) . " WHERE idalongamento = ?")->execute($vals);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { $b = jsonBody(); $id = (int)($b['idalongamento'] ?? 0); }
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        // Mesma limpeza de substituto fantasma que o catalogo de exercicios faz.
        try {
            $refs = $pdo->query("SELECT idalongamento, substitutos FROM intus_alongamento WHERE substitutos LIKE '%\"" . $id . "\"%' OR substitutos LIKE '%," . $id . ",%' OR substitutos LIKE '%[" . $id . ",%' OR substitutos LIKE '%," . $id . "]%' OR substitutos = '[" . $id . "]'")->fetchAll(PDO::FETCH_ASSOC);
            $upd = $pdo->prepare("UPDATE intus_alongamento SET substitutos = ? WHERE idalongamento = ?");
            foreach ($refs as $r) {
                $arr = json_decode($r['substitutos'], true);
                if (!is_array($arr)) continue;
                $novo = array_values(array_filter($arr, fn($v) => (int)$v !== $id));
                $upd->execute([count($novo) ? json_encode($novo) : null, $r['idalongamento']]);
            }
        } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM intus_alongamento WHERE idalongamento = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ AVALIACOES ═══════════════
if ($action === 'avaliacoes') {
    // O aluno pode registrar apenas uma avaliação em seu próprio prontuário.
    // Alterar ou apagar avaliações é operação do professor; sem esta guarda,
    // o idatleta vinha do JSON e um aluno autenticado podia gravar em outro ID.
    if ($_ehAluno && $method !== 'GET' && $method !== 'POST') {
        $_negar(403, 'apenas o professor pode alterar ou excluir avaliações');
    }
    if ($method === 'GET') {
        $idatleta = (int)($_GET['atleta'] ?? 0);
        if ($idatleta > 0) {
            $st = $pdo->prepare("SELECT * FROM intus_avaliacao WHERE idatleta = ? ORDER BY dtavaliacao DESC");
            $st->execute([$idatleta]);
        } else {
            $st = $pdo->query("SELECT * FROM intus_avaliacao ORDER BY dtavaliacao DESC");
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r) {
            $medidas = null;
            if (!empty($r['medidas'])) { $tmp = json_decode($r['medidas'], true); if (is_array($tmp)) $medidas = $tmp; }
            $fotos = null;
            if (!empty($r['fotos'])) { $tmp = json_decode($r['fotos'], true); if (is_array($tmp)) $fotos = $tmp; }
            return [
                'idavaliacao' => (int)$r['idavaliacao'],
                'idatleta'    => (int)$r['idatleta'],
                'dtavaliacao' => $r['dtavaliacao'],
                'peso'        => $r['peso'] !== null ? (float)$r['peso'] : null,
                'altura'      => $r['altura'] !== null ? (float)$r['altura'] : null,
                'percgordura' => $r['percgordura'] !== null ? (float)$r['percgordura'] : null,
                'observacao'  => $r['observacao'] ?? '',
                'medidas'     => $medidas,
                'metodo'      => $r['metodo'] ?? null,
                'fotos'       => $fotos,
                'created_at'  => $r['created_at'] ?? null,
                'updated_at'  => $r['updated_at'] ?? null,
            ];
        }, $rows);
        echo json_encode($out);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $idatleta = (int)($b['idatleta'] ?? 0);
        if ($_ehAluno) {
            $meuId = (int)($_ctx['idatleta'] ?? $_ctx['idusuario'] ?? 0);
            if ($meuId <= 0) $_negar(401, 'sessao de aluno sem identidade');
            if ($idatleta > 0 && $idatleta !== $meuId) $_negar(403, 'não é permitido gravar avaliação para outro aluno');
            $idatleta = $meuId;
        }
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
        $medidas = isset($b['medidas']) && is_array($b['medidas']) ? json_encode($b['medidas']) : null;
        $fotosArr = isset($b['fotos']) && is_array($b['fotos']) ? _persistirFotos($b['fotos']) : [];
        $fotos = count($fotosArr) ? json_encode($fotosArr) : null;
        $st = $pdo->prepare("INSERT INTO intus_avaliacao (idatleta, dtavaliacao, peso, altura, percgordura, observacao, medidas, metodo, fotos) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $idatleta,
            $b['dtavaliacao'] ?? date('Y-m-d'),
            isset($b['peso']) ? (float)$b['peso'] : null,
            isset($b['altura']) ? (float)$b['altura'] : null,
            isset($b['percgordura']) ? (float)$b['percgordura'] : null,
            $b['observacao'] ?? '',
            $medidas,
            $b['metodo'] ?? null,
            $fotos,
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Notificar admin por email quando fotos são enviadas
        if ($fotos) {
            _notificarFotosAvaliacao($pdo, $idatleta, count($fotosArr), $b['dtavaliacao'] ?? date('Y-m-d'));
        }

        echo json_encode(['ok' => true, 'idavaliacao' => $newId]);
        exit;
    }
    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['idavaliacao'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $sets = []; $vals = [];
        foreach (['idatleta','dtavaliacao','peso','altura','percgordura','observacao','metodo'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = $b[$k]; }
        }
        if (array_key_exists('medidas', $b)) { $sets[] = "medidas = ?"; $vals[] = is_array($b['medidas']) ? json_encode($b['medidas']) : null; }
        $fotosArr = null;
        if (array_key_exists('fotos', $b)) { $fotosArr = is_array($b['fotos']) ? _persistirFotos($b['fotos']) : []; $sets[] = "fotos = ?"; $vals[] = count($fotosArr) ? json_encode($fotosArr) : null; }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $vals[] = $id;
        $pdo->prepare("UPDATE intus_avaliacao SET " . implode(', ', $sets) . " WHERE idavaliacao = ?")->execute($vals);

        // Notificar admin se novas fotos foram adicionadas
        if (is_array($fotosArr) && count($fotosArr) > 0) {
            $idatleta = (int)($b['idatleta'] ?? 0);
            if ($idatleta <= 0) {
                $stA = $pdo->prepare("SELECT idatleta FROM intus_avaliacao WHERE idavaliacao = ? LIMIT 1");
                $stA->execute([$id]);
                $rowA = $stA->fetch(PDO::FETCH_ASSOC);
                $idatleta = $rowA ? (int)$rowA['idatleta'] : 0;
            }
            if ($idatleta > 0) {
                _notificarFotosAvaliacao($pdo, $idatleta, count($fotosArr), $b['dtavaliacao'] ?? date('Y-m-d'));
            }
        }

        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { $b = jsonBody(); $id = (int)($b['idavaliacao'] ?? 0); }
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_avaliacao WHERE idavaliacao = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ NUTRIÇÃO (Planos Nutricionais) ═══════════════
if ($action === 'nutricao') {
    if ($method === 'GET') {
        $idatleta = (int)($_GET['atleta'] ?? 0);
        if ($idatleta > 0) {
            // Aluno só recebe plano ativo; a equipe vê todos (para poder ativar de novo).
            $st = $pdo->prepare("SELECT * FROM intus_plano_nutricional WHERE idatleta = ?" . ($_ehAluno ? " AND ativo = 1" : "") . " ORDER BY ativo DESC, dtinicio DESC");
            $st->execute([$idatleta]);
        } else {
            $st = $pdo->query("SELECT * FROM intus_plano_nutricional ORDER BY ativo DESC, dtinicio DESC");
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r) {
            $refeicoes = null;
            if (!empty($r['refeicoes'])) { $tmp = json_decode($r['refeicoes'], true); if (is_array($tmp)) $refeicoes = $tmp; }
            return [
                'idplano'     => (int)$r['idplano'],
                'idatleta'    => (int)$r['idatleta'],
                'titulo'      => $r['titulo'],
                'objetivo'    => $r['objetivo'] ?? '',
                'calorias'    => $r['calorias'] !== null ? (int)$r['calorias'] : null,
                'proteina'    => $r['proteina'] !== null ? (float)$r['proteina'] : null,
                'carboidrato' => $r['carboidrato'] !== null ? (float)$r['carboidrato'] : null,
                'gordura'     => $r['gordura'] !== null ? (float)$r['gordura'] : null,
                'refeicoes'   => $refeicoes,
                'observacao'  => $r['observacao'] ?? '',
                'comentario_inicio' => $r['comentario_inicio'] ?? '',
                'comentario_fim'    => $r['comentario_fim'] ?? '',
                'ativo'       => (int)$r['ativo'],
                'dtinicio'    => $r['dtinicio'],
                'dtfim'       => $r['dtfim'],
            ];
        }, $rows);
        echo json_encode($out);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $idatleta = (int)($b['idatleta'] ?? 0);
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'idatleta obrigatorio']); exit; }
        $refeicoes = isset($b['refeicoes']) && is_array($b['refeicoes']) ? json_encode($b['refeicoes'], JSON_UNESCAPED_UNICODE) : null;
        $st = $pdo->prepare("INSERT INTO intus_plano_nutricional (idatleta, titulo, objetivo, calorias, proteina, carboidrato, gordura, refeicoes, observacao, comentario_inicio, comentario_fim, ativo, dtinicio, dtfim) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([
            $idatleta,
            $b['titulo'] ?? 'Plano Alimentar',
            $b['objetivo'] ?? null,
            isset($b['calorias']) ? (int)$b['calorias'] : null,
            isset($b['proteina']) ? (float)$b['proteina'] : null,
            isset($b['carboidrato']) ? (float)$b['carboidrato'] : null,
            isset($b['gordura']) ? (float)$b['gordura'] : null,
            $refeicoes,
            $b['observacao'] ?? '',
            $b['comentario_inicio'] ?? '',
            $b['comentario_fim'] ?? '',
            isset($b['ativo']) ? (int)$b['ativo'] : 1,
            $b['dtinicio'] ?? null,
            $b['dtfim'] ?? null,
        ]);
        echo json_encode(['ok' => true, 'idplano' => (int)$pdo->lastInsertId()]);
        exit;
    }
    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['idplano'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $sets = []; $vals = [];
        foreach (['idatleta','titulo','objetivo','calorias','proteina','carboidrato','gordura','observacao','comentario_inicio','comentario_fim','ativo','dtinicio','dtfim'] as $k) {
            if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = $b[$k]; }
        }
        if (array_key_exists('refeicoes', $b)) { $sets[] = "refeicoes = ?"; $vals[] = is_array($b['refeicoes']) ? json_encode($b['refeicoes'], JSON_UNESCAPED_UNICODE) : null; }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $vals[] = $id;
        $pdo->prepare("UPDATE intus_plano_nutricional SET " . implode(', ', $sets) . " WHERE idplano = ?")->execute($vals);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { $b = jsonBody(); $id = (int)($b['idplano'] ?? 0); }
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_plano_nutricional WHERE idplano = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ MEUS ALIMENTOS (banco de alimentos personalizado) ═══════════════
// Ferramenta do profissional (quem monta o plano), nunca do aluno. Desde 08/10/2026 o catalogo e DA EQUIPE: todo profissional ve e usa os alimentos de
// todos (cada um sabe quem criou pelo campo meu), mas so quem criou (ou o admin) edita e apaga.
if ($action === 'meus_alimentos') {
    if ($_ehAluno) { $_negar(403, 'apenas o profissional usa o catalogo de alimentos'); }
    $idprofessor = (int)($_ctx['idusuario'] ?? 0);
    if ($idprofessor <= 0) { $_negar(401, 'sessao sem identidade'); }

    $camposNum = [
        'energy_kcal','protein_g','carbohydrate_g','lipid_g','fiber_g','sodium_mg','calcium_mg',
        'iron_mg','potassium_mg','magnesium_mg','phosphorus_mg','zinc_mg','vitaminC_mg','cholesterol_mg',
        'saturated_g','monounsaturated_g','polyunsaturated_g','thiamine_mg','riboflavin_mg','niacin_mg',
        'porcao_padrao',
    ];

    $montarSaida = function($r) use ($idprofessor) {
        $out = [
            'id' => (int)$r['id'], 'description' => $r['description'], 'category' => $r['category'] ?? '',
            'medida_padrao' => $r['medida_padrao'] ?? 'g', 'custom' => true,
            'idprofessor' => (int)($r['idprofessor'] ?? 0), 'meu' => ((int)($r['idprofessor'] ?? 0) === $idprofessor),
        ];
        foreach ([
            'energy_kcal','protein_g','carbohydrate_g','lipid_g','fiber_g','sodium_mg','calcium_mg',
            'iron_mg','potassium_mg','magnesium_mg','phosphorus_mg','zinc_mg','vitaminC_mg','cholesterol_mg',
            'saturated_g','monounsaturated_g','polyunsaturated_g','thiamine_mg','riboflavin_mg','niacin_mg',
            'porcao_padrao',
        ] as $k) { $out[$k] = (float)$r[$k]; }
        return $out;
    };

    if ($method === 'GET') {
        $st = $pdo->query("SELECT * FROM intus_alimento_personalizado ORDER BY description");
        echo json_encode(array_map($montarSaida, $st->fetchAll(PDO::FETCH_ASSOC)));
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $desc = trim($b['description'] ?? '');
        if (!$desc) { http_response_code(400); echo json_encode(['error' => 'description obrigatorio']); exit; }
        $cols = ['idprofessor', 'description', 'category', 'medida_padrao'];
        $vals = [$idprofessor, $desc, $b['category'] ?? 'Meus Alimentos', $b['medida_padrao'] ?? 'g'];
        foreach ($camposNum as $k) { $cols[] = $k; $vals[] = isset($b[$k]) ? (float)$b[$k] : ($k === 'porcao_padrao' ? 100 : 0); }
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $pdo->prepare("INSERT INTO intus_alimento_personalizado (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
        $novoId = (int)$pdo->lastInsertId();
        $st = $pdo->prepare("SELECT * FROM intus_alimento_personalizado WHERE id = ?");
        $st->execute([$novoId]);
        echo json_encode(['ok' => true] + $montarSaida($st->fetch(PDO::FETCH_ASSOC)));
        exit;
    }
    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        // Confere dono antes de alterar: o alimento e da equipe, mas so quem criou (ou o admin) muda o que ja existe.
        $stD = $pdo->prepare("SELECT idprofessor FROM intus_alimento_personalizado WHERE id = ?");
        $stD->execute([$id]);
        $dono = $stD->fetchColumn();
        if ($dono === false) { http_response_code(404); echo json_encode(['error' => 'nao encontrado']); exit; }
        if ((int)$dono !== $idprofessor && empty($_ctx['admin'])) { $_negar(403, 'alimento criado por outro profissional: so ele ou o admin altera'); }
        $sets = []; $vals = [];
        if (array_key_exists('description', $b) && trim($b['description']) !== '') { $sets[] = 'description = ?'; $vals[] = trim($b['description']); }
        if (array_key_exists('category', $b)) { $sets[] = 'category = ?'; $vals[] = $b['category']; }
        if (array_key_exists('medida_padrao', $b)) { $sets[] = 'medida_padrao = ?'; $vals[] = $b['medida_padrao']; }
        foreach ($camposNum as $k) { if (array_key_exists($k, $b)) { $sets[] = "$k = ?"; $vals[] = (float)$b[$k]; } }
        if (empty($sets)) { echo json_encode(['ok' => true]); exit; }
        $vals[] = $id;
        $pdo->prepare("UPDATE intus_alimento_personalizado SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { $b = jsonBody(); $id = (int)($b['id'] ?? 0); }
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $stD = $pdo->prepare("SELECT idprofessor FROM intus_alimento_personalizado WHERE id = ?");
        $stD->execute([$id]);
        $dono = $stD->fetchColumn();
        if ($dono === false) { echo json_encode(['ok' => true]); exit; }
        if ((int)$dono !== $idprofessor && empty($_ctx['admin'])) { $_negar(403, 'alimento criado por outro profissional: so ele ou o admin apaga'); }
        $pdo->prepare("DELETE FROM intus_alimento_personalizado WHERE id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM intus_alimento_medida WHERE food_id = ?")->execute([1000000 + $id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ MEDIDAS CASEIRAS DA EQUIPE (qualquer alimento) ═══════════════
// GET: todas as medidas cadastradas pela equipe. POST {food_id, nome, gramas}: cria (ou atualiza o peso se o alimento ja tem uma medida com esse nome).
// DELETE ?id=: so quem criou ou o admin. Plano ja prescrito guarda a medida no proprio item, entao apagar aqui nao muda plano nenhum.
if ($action === 'alimento_medidas') {
    if ($_ehAluno) { $_negar(403, 'apenas a equipe'); }
    $idprof = (int)($_ctx['idusuario'] ?? 0);
    if ($idprof <= 0) { $_negar(401, 'sessao sem identidade'); }
    $saida = function($r) use ($idprof) {
        return ['idmedida' => (int)$r['idmedida'], 'food_id' => (int)$r['food_id'], 'nome' => $r['nome'], 'gramas' => (float)$r['gramas'],
                'idprofessor' => (int)$r['idprofessor'], 'meu' => ((int)$r['idprofessor'] === $idprof)];
    };
    if ($method === 'GET') {
        $rows = $pdo->query("SELECT idmedida, food_id, nome, gramas, idprofessor FROM intus_alimento_medida ORDER BY food_id, nome")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_map($saida, $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $food = (int)($b['food_id'] ?? 0);
        $nome = mb_substr(trim((string)($b['nome'] ?? '')), 0, 60);
        $gramas = round((float)($b['gramas'] ?? 0), 2);
        if ($food <= 0 || $nome === '' || !($gramas > 0) || $gramas > 5000) { http_response_code(400); echo json_encode(['error' => 'informe alimento, nome e gramas (maior que zero)']); exit; }
        $st = $pdo->prepare("SELECT idmedida, idprofessor FROM intus_alimento_medida WHERE food_id = ? AND LOWER(nome) = ?");
        $st->execute([$food, mb_strtolower($nome, 'UTF-8')]);
        $ex = $st->fetch(PDO::FETCH_ASSOC);
        if ($ex) {
            $pdo->prepare("UPDATE intus_alimento_medida SET gramas = ? WHERE idmedida = ?")->execute([$gramas, (int)$ex['idmedida']]);
            $id = (int)$ex['idmedida'];
        } else {
            $pdo->prepare("INSERT INTO intus_alimento_medida (food_id, nome, gramas, idprofessor) VALUES (?,?,?,?)")->execute([$food, $nome, $gramas, $idprof]);
            $id = (int)$pdo->lastInsertId();
        }
        $st = $pdo->prepare("SELECT idmedida, food_id, nome, gramas, idprofessor FROM intus_alimento_medida WHERE idmedida = ?");
        $st->execute([$id]);
        echo json_encode(['ok' => true] + $saida($st->fetch(PDO::FETCH_ASSOC)), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $st = $pdo->prepare("SELECT idprofessor FROM intus_alimento_medida WHERE idmedida = ?");
        $st->execute([$id]);
        $dono = $st->fetchColumn();
        if ($dono === false) { echo json_encode(['ok' => true]); exit; }
        if ((int)$dono !== $idprof && empty($_ctx['admin'])) { $_negar(403, 'medida criada por outro profissional: so ele ou o admin apaga'); }
        $pdo->prepare("DELETE FROM intus_alimento_medida WHERE idmedida = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ CAIXA (financeiro) ═══════════════
// Restrito ao professor/admin, igual às outras ações sensíveis desta rota.
if ($action === 'caixa') {
    if ($_ehAluno) { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }

    if ($method === 'GET') {
        $st = $pdo->query("SELECT * FROM intus_caixa ORDER BY data DESC, created_at DESC");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $out = array_map(function($r) {
            return [
                'id'           => $r['id'],
                'tipo'         => $r['tipo'],
                'data'         => $r['data'],
                'descricao'    => $r['descricao'],
                'categoria'    => $r['categoria'],
                'valor'        => (float)$r['valor'],
                'obs'          => $r['obs'] ?? '',
                'idusuario'    => $r['idusuario'] !== null ? (int)$r['idusuario'] : null,
                'origemVenda'  => $r['origem_venda'],
                'recorrenteId' => $r['recorrente_id'],
            ];
        }, $rows);
        echo json_encode($out);
        exit;
    }
    // POST faz upsert (INSERT ... ON DUPLICATE KEY UPDATE): o id já vem pronto
    // do front-end (cx-...), então criar e ressincronizar um lançamento que já
    // existe é a mesma chamada — importante pra migração dos dados que só
    // existiam no localStorage de quem já usava o Caixa antes desta tabela existir.
    if ($method === 'POST') {
        $b = jsonBody();
        $id = trim((string)($b['id'] ?? ''));
        if ($id === '') { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $tipo = ($b['tipo'] ?? '') === 'saida' ? 'saida' : 'entrada';
        $st = $pdo->prepare("INSERT INTO intus_caixa (id, tipo, data, descricao, categoria, valor, obs, idusuario, origem_venda, recorrente_id)
            VALUES (?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE tipo=VALUES(tipo), data=VALUES(data), descricao=VALUES(descricao), categoria=VALUES(categoria),
                valor=VALUES(valor), obs=VALUES(obs), idusuario=VALUES(idusuario), origem_venda=VALUES(origem_venda), recorrente_id=VALUES(recorrente_id)");
        $st->execute([
            $id, $tipo,
            $b['data'] ?? date('Y-m-d'),
            $b['descricao'] ?? '',
            $b['categoria'] ?? null,
            (float)($b['valor'] ?? 0),
            $b['obs'] ?? '',
            isset($b['idusuario']) && $b['idusuario'] !== null ? (int)$b['idusuario'] : null,
            $b['origemVenda'] ?? null,
            $b['recorrenteId'] ?? null,
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') { $b = jsonBody(); $id = trim((string)($b['id'] ?? '')); }
        if ($id === '') { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_caixa WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ BUSCA DE ALIMENTO ONLINE (Open Food Facts) ═══════════════
// A TACO (já embutida no app) cobre bem alimento fresco/preparo caseiro, mas
// não tem produto de marca (whey de marca X, barrinha Y) — por isso esta
// consulta ao vivo num banco colaborativo aberto (licença ODbL, permite uso
// comercial), sem guardar nada aqui: é so repasse. Feito no servidor (não no
// navegador do nutricionista) porque a busca por texto do Open Food Facts não
// libera CORS pra chamada direta do navegador — só a consulta por código de
// barras libera, e aqui a busca é por nome.
if ($action === 'busca_alimento_online') {
    if ($_ehAluno) { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }
    if ($method !== 'GET') { http_response_code(405); echo json_encode(['error' => 'metodo invalido']); exit; }
    $termo = trim((string)($_GET['termo'] ?? ''));
    if (mb_strlen($termo) < 2) { echo json_encode([]); exit; }
    if (!function_exists('curl_init')) { echo json_encode([]); exit; }

    $url = 'https://search.openfoodfacts.org/search?' . http_build_query([
        'q' => $termo,
        'page_size' => 8,
        'fields' => 'product_name,brands,nutriments',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['User-Agent: IntusFit/1.0 (contato@intusfit.com.br)'],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$resp) { echo json_encode([]); exit; }
    $data = json_decode($resp, true);
    $hits = is_array($data['hits'] ?? null) ? $data['hits'] : [];
    $out = [];
    foreach ($hits as $h) {
        $n = $h['nutriments'] ?? null;
        if (empty($h['product_name']) || !is_array($n) || !isset($n['energy-kcal_100g'])) continue;
        $marca = is_array($h['brands'] ?? null) ? ($h['brands'][0] ?? '') : (is_string($h['brands'] ?? null) ? $h['brands'] : '');
        $out[] = [
            'description'     => $h['product_name'] . ($marca ? ' — ' . $marca : ''),
            'category'        => 'Produto de marca (Open Food Facts)',
            'energy_kcal'     => (float)($n['energy-kcal_100g'] ?? 0),
            'protein_g'       => (float)($n['proteins_100g'] ?? 0),
            'carbohydrate_g'  => (float)($n['carbohydrates_100g'] ?? 0),
            'lipid_g'         => (float)($n['fat_100g'] ?? 0),
            'fiber_g'         => (float)($n['fiber_100g'] ?? 0),
            'sodium_mg'       => (float)($n['sodium_100g'] ?? 0) * 1000,
            'saturated_g'     => (float)($n['saturated-fat_100g'] ?? 0),
        ];
    }
    echo json_encode($out);
    exit;
}

// ═══════════════ PLANOS CONFIG ═══════════════
if ($action === 'planos_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'planos_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('planos_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ FRASES DO APP (tela inicial + fim de treino) ═══════════════
if ($action === 'frases_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'frases_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('frases_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ UPLOAD DE MÍDIA (figurinhas etc. salvas como ARQUIVO, não base64) ═══════════════
// Guardar imagens grandes como base64 dentro de um registro de config estoura os limites do
// MySQL/PHP e faz o save falhar silenciosamente. Aqui a imagem vira um arquivo em /app/img/uploads
// e a config guarda só a URL (pequena e confiável).
if ($action === 'midia_upload') {
    if ($method !== 'POST' && $method !== 'PUT') { http_response_code(405); echo json_encode(['error' => 'metodo']); exit; }
    $b = jsonBody();
    $data = (string)($b['data'] ?? '');
    if ($data === '') { http_response_code(400); echo json_encode(['error' => 'sem dados']); exit; }
    // Aceita data URL (data:image/xxx;base64,....)
    if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#is', $data, $m)) {
        http_response_code(400); echo json_encode(['error' => 'formato invalido (use PNG, JPG, GIF ou WEBP)']); exit;
    }
    $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
    $bin = base64_decode($m[2], true);
    if ($bin === false) { http_response_code(400); echo json_encode(['error' => 'base64 invalido']); exit; }
    if (strlen($bin) > 3 * 1024 * 1024) { http_response_code(413); echo json_encode(['error' => 'imagem grande demais (max 3MB)']); exit; }
    // Pasta física: /app/img/uploads  (catalogo.php está em /app/api)
    $dir = __DIR__ . '/../img/uploads';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    if (!is_dir($dir) || !is_writable($dir)) { http_response_code(500); echo json_encode(['error' => 'pasta de upload indisponivel']); exit; }
    // Nome único
    try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
    $fname = 'fig_' . time() . '_' . $rand . '.' . $ext;
    if (@file_put_contents($dir . '/' . $fname, $bin) === false) {
        http_response_code(500); echo json_encode(['error' => 'falha ao gravar arquivo']); exit;
    }
    // URL pública relativa à raiz do app (funciona no /app e no /app/painel)
    $script = $_SERVER['SCRIPT_NAME'] ?? '/app/api/catalogo.php';
    $appBase = rtrim(dirname(dirname($script)), '/'); // -> /app
    if ($appBase === '' || $appBase === '.' || $appBase === '/') $appBase = '/app';
    echo json_encode(['ok' => true, 'url' => $appBase . '/img/uploads/' . $fname]);
    exit;
}

// ═══════════════ FIGURINHAS (tela de conclusão de treino) ═══════════════
if ($action === 'figurinhas_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'figurinhas_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(['itens' => []]);
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('figurinhas_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ CONQUISTAS (edições do admin: nome, descrição, emoji, meta, ativa) ═══════════════
if ($action === 'conquistas_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'conquistas_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(['overrides' => new stdClass()]);
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('conquistas_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ REGRAS DO CARDIO (limite diário, duração máx, velocidades máximas) ═══════════════
if ($action === 'cardio_regras') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'cardio_regras'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('cardio_regras', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ REGRAS DE PONTUAÇÃO DO RANKING ═══════════════
// Pesos configuráveis: base/faixa da musculação, fator+teto do cardio, bônus de frequência
// e a data a partir da qual a nova fórmula passa a valer (semanas anteriores ficam congeladas).
if ($action === 'ranking_regras') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'ranking_regras'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['valor']) {
            echo $row['valor'];
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('ranking_regras', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ CONFIG DE NOTIFICAÇÕES (Automáticas + Config) — persistência no servidor ═══════════════
if ($action === 'notif_config') {
    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT valor FROM intus_config WHERE chave = 'notif_config'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        echo ($row && $row['valor']) ? $row['valor'] : json_encode(new stdClass());
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $valor = json_encode($b);
        $st = $pdo->prepare("REPLACE INTO intus_config (chave, valor) VALUES ('notif_config', ?)");
        $st->execute([$valor]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PLANOS ADMIN (personal, promoções, condições especiais) ═══════════════
if ($action === 'planos_admin') {
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_plano_admin (
        idplano     INT AUTO_INCREMENT PRIMARY KEY,
        nome        VARCHAR(200) NOT NULL,
        descricao   VARCHAR(500) DEFAULT '',
        valor       DECIMAL(10,2) NOT NULL DEFAULT 0,
        meses       INT NOT NULL DEFAULT 1,
        tipo        VARCHAR(50) DEFAULT 'personal',
        ativo       CHAR(1) DEFAULT 'S',
        dtcriacao   DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($method === 'GET') {
        $rows = $pdo->query("SELECT * FROM intus_plano_admin ORDER BY tipo, nome")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        if (empty($b['nome'])) { http_response_code(400); echo json_encode(['error' => 'nome obrigatorio']); exit; }
        $st = $pdo->prepare("INSERT INTO intus_plano_admin (nome, descricao, valor, meses, tipo) VALUES (?, ?, ?, ?, ?)");
        $st->execute([
            $b['nome'],
            $b['descricao'] ?? '',
            floatval($b['valor'] ?? 0),
            intval($b['meses'] ?? 1),
            $b['tipo'] ?? 'personal',
        ]);
        echo json_encode(['ok' => true, 'idplano' => (int)$pdo->lastInsertId()]);
        exit;
    }
    if ($method === 'PUT') {
        $idp = (int)($_GET['id'] ?? 0);
        if (!$idp) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $b = jsonBody();
        $sets = []; $params = [];
        foreach (['nome','descricao','valor','meses','tipo','ativo'] as $col) {
            if (array_key_exists($col, $b)) {
                $sets[] = "`$col` = ?";
                $params[] = $b[$col];
            }
        }
        if (empty($sets)) { echo json_encode(['ok' => true, 'noop' => true]); exit; }
        $params[] = $idp;
        $pdo->prepare("UPDATE intus_plano_admin SET " . implode(', ', $sets) . " WHERE idplano = ?")->execute($params);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $idp = (int)($_GET['id'] ?? 0);
        if (!$idp) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_plano_admin WHERE idplano = ?")->execute([$idp]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PROFILE (avatar + prefs) ═══════════════
if ($action === 'profile') {
    $idatleta = (int)($_GET['atleta'] ?? 0);
    if ($idatleta <= 0) { $b = jsonBody(); $idatleta = (int)($b['idatleta'] ?? 0); }
    if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT avatar, prefs FROM intus_profile WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $prefs = $row['prefs'] ? json_decode($row['prefs'], true) : [];
            echo json_encode(['avatar' => $row['avatar'], 'prefs' => $prefs]);
        } else {
            echo json_encode(['avatar' => null, 'prefs' => new stdClass()]);
        }
        exit;
    }
    if ($method === 'PUT' || $method === 'POST') {
        $b = jsonBody();
        $avatar = $b['avatar'] ?? null;
    $avatar = _intusImagemOk($avatar);
    if ($avatar === false) { http_response_code(413); echo json_encode(['error' => 'imagem invalida ou grande demais (max 3 MB)']); exit; }
        $prefs = isset($b['prefs']) ? json_encode($b['prefs']) : null;

        $st = $pdo->prepare("SELECT 1 FROM intus_profile WHERE idatleta = ?");
        $st->execute([$idatleta]);
        if ($st->fetch()) {
            $sets = []; $vals = [];
            if (array_key_exists('avatar', $b)) { $sets[] = "avatar = ?"; $vals[] = $avatar; }
            if (array_key_exists('prefs', $b)) { $sets[] = "prefs = ?"; $vals[] = $prefs; }
            if (!empty($sets)) {
                $vals[] = $idatleta;
                $pdo->prepare("UPDATE intus_profile SET " . implode(', ', $sets) . " WHERE idatleta = ?")->execute($vals);
            }
        } else {
            $pdo->prepare("INSERT INTO intus_profile (idatleta, avatar, prefs) VALUES (?, ?, ?)")->execute([$idatleta, $avatar, $prefs]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ DIAGNOSTICO DE E-MAIL (so professor) ═══════════════
// Nao envia mensagem nenhuma: apenas abre a conexao SMTP, tenta autenticar e
// relata o codigo de cada etapa. Serve para saber POR QUE os e-mails de
// recuperacao de senha e de novo cadastro nao chegam — antes isso falhava em
// silencio absoluto. Nunca devolve a senha do SMTP, so se ela existe.
if ($action === 'email_diag') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }

    $out = [];
    $cfgPath = __DIR__ . '/../config/smtp.php';
    $out['arquivo_smtp_existe'] = @file_exists($cfgPath);
    $cfg = @include $cfgPath;
    if (!is_array($cfg)) $cfg = [];
    $host = $cfg['host'] ?? 'smtp.intusfit.com.br';
    $user = $cfg['user'] ?? 'contato@intusfit.com.br';
    $pass = $cfg['pass'] ?? '';
    $out['host']            = $host;
    $out['usuario']         = $user;
    $out['tem_senha']       = ($pass !== '');
    $out['tamanho_senha']   = strlen($pass);   // so o tamanho, nunca o valor
    $out['destino_notificacao'] = $cfg['notificar'] ?? 'contato@intusfit.com.br';

    $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("ssl://$host:465", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) {
        $out['conexao'] = 'FALHOU';
        $out['detalhe'] = trim($errno . ' ' . $errstr);
        $out['mail_nativo_disponivel'] = function_exists('mail');
        echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
    }
    $out['conexao'] = 'ok';
    $read = function() use ($sock) { $r=''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
    $cod  = function($r) { return (int)substr(trim((string)$r), 0, 3); };

    $out['saudacao'] = $cod($read());
    $out['ehlo']     = $cod($send('EHLO intusfit.com.br'));
    $send('AUTH LOGIN');
    $send(base64_encode($user));
    $rAuth = $send(base64_encode($pass));
    $out['auth_codigo']   = $cod($rAuth);
    $out['auth_ok']       = ($out['auth_codigo'] === 235);
    $out['auth_resposta'] = trim(substr((string)$rAuth, 0, 120));
    @fwrite($sock, "QUIT\r\n");
    @fclose($sock);

    $log = __DIR__ . '/../config/email_erros.log';
    if (@file_exists($log)) {
        $linhas = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($linhas)) $out['ultimas_falhas'] = array_slice($linhas, -5);
    } else {
        $out['ultimas_falhas'] = 'sem log (nenhuma falha registrada ou versao antiga no ar)';
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════ ENVIO DE TESTE (so professor) ═══════════════
// Envia UMA mensagem de teste para o endereco informado pelo professor e relata
// o codigo de CADA etapa do SMTP. E a unica forma de descobrir se a mensagem e
// aceita, recusada no destinatario, ou aceita e descartada depois (spam/SPF).
// Uso: POST /catalogo.php?action=email_teste  { "para": "voce@dominio.com" }
if ($action === 'email_teste') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }
    $b = jsonBody();
    $para = trim((string)($b['para'] ?? ''));
    if ($para === '' || !filter_var($para, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400); echo json_encode(['error' => 'informe um e-mail valido em "para"']); exit;
    }
    $cfg = @include __DIR__ . '/../config/smtp.php';
    if (!is_array($cfg)) $cfg = [];
    $host = $cfg['host'] ?? 'smtp.intusfit.com.br';
    $user = $cfg['user'] ?? 'contato@intusfit.com.br';
    $pass = $cfg['pass'] ?? '';
    $nome = $cfg['from_name'] ?? 'Intus Fit';

    $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $sock = @stream_socket_client("ssl://$host:465", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$sock) { echo json_encode(['etapa' => 'conexao', 'ok' => false, 'detalhe' => trim($errno.' '.$errstr)]); exit; }
    $read = function() use ($sock) { $r=''; while ($l = fgets($sock, 512)) { $r .= $l; if (strlen($l) >= 4 && $l[3] === ' ') break; } return $r; };
    $send = function($cmd) use ($sock, $read) { fwrite($sock, $cmd . "\r\n"); return $read(); };
    $cod  = function($r) { return (int)substr(trim((string)$r), 0, 3); };
    $etapas = [];
    $etapas['saudacao'] = $cod($read());
    $etapas['ehlo']     = $cod($send('EHLO intusfit.com.br'));
    $send('AUTH LOGIN'); $send(base64_encode($user));
    $etapas['entrada']  = $cod($send(base64_encode($pass)));
    $etapas['remetente']    = $cod($send("MAIL FROM:<$user>"));
    $etapas['destinatario'] = $cod($send("RCPT TO:<$para>"));
    $etapas['abre_dados']   = $cod($send('DATA'));
    $msg  = "From: $nome <$user>\r\nTo: $para\r\nSubject: Teste de envio - Intus Fit\r\n";
    $msg .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n";
    $msg .= "Mensagem de teste enviada pelo painel em " . date('d/m/Y H:i') . ".\n";
    $msg .= "Se voce recebeu isto, o envio do sistema esta funcionando.\n";
    $rFim = $send($msg . "\r\n.");
    $etapas['aceita_pelo_servidor'] = $cod($rFim);
    $etapas['resposta_final']       = trim(substr((string)$rFim, 0, 140));
    $send('QUIT'); @fclose($sock);
    $etapas['entregue_ao_servidor'] = ($etapas['aceita_pelo_servidor'] === 250);
    $etapas['destino'] = $para;
    $etapas['observacao'] = 'Aceita pelo servidor nao garante caixa de entrada: verifique tambem o spam.';
    echo json_encode($etapas, JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════ AGENDA / EVENTOS ═══════════════
// Compromissos da equipe: reuniao, ligar para lead, palestra, avaliacao presencial.
// Podem ser so seus ou com outros professores marcados como participantes.
// Restrito a professor — aluno nao ve nem cria evento.
if ($action === 'eventos') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_evento (
            idevento INT AUTO_INCREMENT PRIMARY KEY,
            titulo VARCHAR(160) NOT NULL,
            descricao TEXT NULL,
            dt DATE NOT NULL,
            hora VARCHAR(5) NULL,
            tipo VARCHAR(24) NOT NULL DEFAULT 'geral',
            participantes TEXT NULL,
            criado_por INT NULL,
            criado_por_nome VARCHAR(120) NULL,
            concluido TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dt (dt)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    $_saidaEv = function ($r) {
        $part = [];
        if (!empty($r['participantes'])) {
            $tmp = json_decode($r['participantes'], true);
            if (is_array($tmp)) $part = array_map('intval', $tmp);
        }
        return [
            'idevento'  => (int)$r['idevento'],
            'titulo'    => (string)$r['titulo'],
            'descricao' => (string)($r['descricao'] ?? ''),
            'dt'        => $r['dt'],
            'hora'      => (string)($r['hora'] ?? ''),
            'tipo'      => (string)($r['tipo'] ?? 'geral'),
            'participantes'   => $part,
            'criado_por'      => (int)($r['criado_por'] ?? 0),
            'criado_por_nome' => (string)($r['criado_por_nome'] ?? ''),
            'concluido' => (int)($r['concluido'] ?? 0) === 1,
        ];
    };

    if ($method === 'GET') {
        $de  = trim((string)($_GET['de']  ?? ''));
        $ate = trim((string)($_GET['ate'] ?? ''));
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $de) && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $ate)) {
            $st = $pdo->prepare("SELECT * FROM intus_evento WHERE dt BETWEEN ? AND ? ORDER BY dt, hora, idevento");
            $st->execute([$de, $ate]);
        } else {
            $st = $pdo->query("SELECT * FROM intus_evento WHERE dt >= DATE_SUB(CURDATE(), INTERVAL 120 DAY) ORDER BY dt, hora, idevento");
        }
        echo json_encode(array_map($_saidaEv, $st->fetchAll(PDO::FETCH_ASSOC)), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();

        if (!empty($b['excluir'])) {
            $idv = (int)($b['idevento'] ?? 0);
            if ($idv <= 0) { http_response_code(400); echo json_encode(['error' => 'idevento obrigatorio']); exit; }
            $pdo->prepare("DELETE FROM intus_evento WHERE idevento = ?")->execute([$idv]);
            echo json_encode(['ok' => true]); exit;
        }

        if (array_key_exists('concluido', $b) && !empty($b['idevento'])) {
            $pdo->prepare("UPDATE intus_evento SET concluido = ? WHERE idevento = ?")
                ->execute([!empty($b['concluido']) ? 1 : 0, (int)$b['idevento']]);
            echo json_encode(['ok' => true]); exit;
        }

        $titulo = trim((string)($b['titulo'] ?? ''));
        $dt     = trim((string)($b['dt'] ?? ''));
        if ($titulo === '' || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dt)) {
            http_response_code(400);
            echo json_encode(['error' => 'titulo e data (AAAA-MM-DD) obrigatorios']);
            exit;
        }
        $hora = trim((string)($b['hora'] ?? ''));
        if ($hora !== '' && !preg_match('/^\\d{2}:\\d{2}$/', $hora)) $hora = '';
        $desc = trim((string)($b['descricao'] ?? ''));
        $tipo = trim((string)($b['tipo'] ?? 'geral'));
        $part = $b['participantes'] ?? [];
        $part = is_array($part) ? json_encode(array_values(array_unique(array_map('intval', $part)))) : null;

        $idv = (int)($b['idevento'] ?? 0);
        if ($idv > 0) {
            $st = $pdo->prepare("UPDATE intus_evento SET titulo=?, descricao=?, dt=?, hora=?, tipo=?, participantes=? WHERE idevento=?");
            $st->execute([mb_substr($titulo,0,160), $desc, $dt, $hora, mb_substr($tipo,0,24), $part, $idv]);
            echo json_encode(['ok' => true, 'idevento' => $idv]); exit;
        }
        $st = $pdo->prepare("INSERT INTO intus_evento (titulo, descricao, dt, hora, tipo, participantes, criado_por, criado_por_nome)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([mb_substr($titulo,0,160), $desc, $dt, $hora, mb_substr($tipo,0,24), $part,
                      (int)($_ctx['idusuario'] ?? 0), mb_substr((string)($_ctx['nmusuario'] ?? ''), 0, 120)]);
        echo json_encode(['ok' => true, 'idevento' => (int)$pdo->lastInsertId()]);
        exit;
    }
}

// ═══════════════ APARELHOS CONECTADOS ═══════════════
// Em 18/08/2026 uma aluna preencheu a anamnese inteira num aparelho que estava
// entrado com a conta de OUTRA pessoa. Do ponto de vista do servidor nada
// estava errado — o token era daquela conta, e foi nela que gravou. Nenhuma
// checagem de permissao pega isso: quem estava trocado era a pessoa na frente
// do celular, nao o token.
// O que resolve e conseguir VER quais aparelhos estao entrados em cada conta, e
// poder derrubar os que nao deviam estar. Sem isso, a unica saida era pedir
// para a aluna mexer nas configuracoes dela — constrangedor e nada confiavel.
// So professor. Nao devolve o token nem o hash dele em hipotese alguma.
if ($action === 'sessoes') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }

    // Faxina barata e sem efeito colateral: sessao vencida ja nao valia nada,
    // so ocupava espaco e poluia a leitura. Ninguem e desconectado por isto.
    try { $pdo->exec("DELETE FROM intus_sessions WHERE expires_at <= NOW()"); } catch (Throwable $e) {}

    if ($method === 'GET') {
        try {
            $cols = array_column($pdo->query("SHOW COLUMNS FROM intus_sessions")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $sel = ['user_id', 'user_type'];
            foreach (['user_name', 'nome', 'admin', 'created_at', 'expires_at', 'ip', 'user_agent', 'last_seen'] as $c) {
                if (in_array($c, $cols, true)) $sel[] = $c;
            }
            $ordem = in_array('created_at', $cols, true) ? 'created_at DESC' : 'user_id ASC';
            $rows = $pdo->query("SELECT " . implode(',', array_map(function ($c) { return "`$c`"; }, $sel)) .
                                " FROM intus_sessions WHERE expires_at > NOW() ORDER BY $ordem LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['ok' => true, 'colunas' => $cols, 'sessoes' => $rows], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao ler sessoes', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }

    // Derruba as sessoes de um usuario: todo aparelho entrado naquela conta cai
    // na tela de login na proxima acao. E o caminho para tirar um aparelho de
    // uma conta que nao e dele sem depender da pessoa do outro lado.
    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();

        // ── PODA: manter so as sessoes mais recentes de cada conta ──────────
        // Cada login cria uma sessao nova e nenhuma antiga era removida. Uma
        // aluna que passou semanas com dificuldade para entrar acumulou 39
        // sessoes validas, de 13 aparelhos, todas com 30 dias de validade. Nao
        // foi invasao — foi tentativa repetida — mas sao 39 chaves vivas para
        // uma conta so, e nao deveria haver nem a decima.
        // Esta operacao mantem as N mais recentes de cada conta e descarta o
        // resto. Quem for descartado cai na tela de login e entra de novo.
        if (($b['acao'] ?? '') === 'podar') {
            $manter = max(1, min(20, (int)($b['manter'] ?? 3)));
            try {
                $rows = $pdo->query("SELECT id, user_id, user_type FROM intus_sessions
                                     WHERE expires_at > NOW() ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
                $vistos = [];
                $apagar = [];
                foreach ($rows as $r) {
                    $k = $r['user_type'] . ':' . $r['user_id'];
                    $vistos[$k] = ($vistos[$k] ?? 0) + 1;
                    if ($vistos[$k] > $manter) $apagar[] = (int)$r['id'];
                }
                $n = 0;
                foreach (array_chunk($apagar, 200) as $lote) {
                    $ph = implode(',', array_fill(0, count($lote), '?'));
                    $st = $pdo->prepare("DELETE FROM intus_sessions WHERE id IN ($ph)");
                    $st->execute($lote);
                    $n += $st->rowCount();
                }
                echo json_encode(['ok' => true, 'removidas' => $n, 'mantidas_por_conta' => $manter]);
            } catch (Throwable $e) {
                http_response_code(500);
                echo json_encode(['error' => 'falha ao podar', 'detalhe' => _intusLogErro($e)]);
            }
            exit;
        }

        $uid  = (int)($b['user_id'] ?? 0);
        $tipo = trim((string)($b['user_type'] ?? ''));
        if ($uid <= 0 || ($tipo !== 'aluno' && $tipo !== 'prof')) {
            http_response_code(400);
            echo json_encode(['error' => 'user_id e user_type (aluno|prof) obrigatorios']);
            exit;
        }
        try {
            $st = $pdo->prepare("DELETE FROM intus_sessions WHERE user_id = ? AND user_type = ?");
            $st->execute([$uid, $tipo]);
            echo json_encode(['ok' => true, 'encerradas' => $st->rowCount()]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao encerrar', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }
}

// ═══════════════ REACOES ═══════════════
// Reagir a uma publicacao — hoje o mural do ranking, amanha o feed dos alunos.
// A tabela e generica de proposito: guarda o TIPO do alvo e o id dele, entao
// quando o feed existir e so passar outro alvo_tipo, sem tabela nova.
//
// Duas decisoes que mudam o comportamento em relacao ao que existia:
//   1. Cada pessoa pode deixar VARIAS reacoes diferentes no mesmo item. Antes,
//      no painel, reagir de novo TROCAVA a reacao anterior — dar risada apagava
//      o joinha. Clicar na mesma reacao de novo remove; clicar em outra soma.
//   2. Quem reagiu fica gravado com nome, e a lista volta para todo mundo. Uma
//      reacao anonima nao serve para nada num mural entre colegas.
//
// O autor NUNCA vem do corpo da requisicao — vem do token. Sem isso, qualquer
// um poderia reagir no lugar de outra pessoa.
if ($action === 'reacoes') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    try {
        // Nome proprio de proposito. Ja existia uma tabela "intus_reacao" no
        // banco, com outro desenho — provavelmente das reacoes de comentario do
        // mensagens.php. Como CREATE TABLE IF NOT EXISTS nao faz nada quando a
        // tabela existe, a rota nova caiu em cima do esquema antigo e quebrou
        // com "coluna alvo_id desconhecida". Em vez de alterar uma tabela que
        // nao e minha e que outra tela usa, esta funcionalidade tem a sua.
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_reacao_pub (
            idreacao    INT AUTO_INCREMENT PRIMARY KEY,
            alvo_tipo   VARCHAR(20)  NOT NULL,
            alvo_id     INT          NOT NULL,
            autor_tipo  VARCHAR(10)  NOT NULL,
            autor_id    INT          NOT NULL,
            autor_nome  VARCHAR(200) NOT NULL DEFAULT '',
            emoji       VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reacao (alvo_tipo, alvo_id, autor_tipo, autor_id, emoji),
            INDEX idx_alvo (alvo_tipo, alvo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    // ── POR QUE A COLUNA emoji E BINARIA ───────────────────────────────────
    // Na collation padrao do MySQL (utf8mb4_general_ci) emojis de quatro bytes
    // sao considerados IGUAIS entre si: 😂 e 🔥 comparam como o mesmo texto. O
    // efeito no teste foi imediato — reagir com 🔥 depois de 😂 apagava o 😂 em
    // vez de somar, porque o SELECT achava a linha da outra reacao. Com
    // utf8mb4_bin cada emoji e ele mesmo. O ALTER abaixo conserta a tabela que
    // ja foi criada com a collation errada; em base nova ele nao faz nada.
    // O ALTER so roda se a coluna ainda estiver na collation errada. Antes ele
    // rodava em TODA chamada da rota — e ALTER TABLE reescreve a tabela inteira
    // no MySQL, o que a cada reacao custava tempo de servidor a troco de nada.
    try {
        $_col = $pdo->query("SHOW FULL COLUMNS FROM intus_reacao_pub LIKE 'emoji'")->fetch(PDO::FETCH_ASSOC);
        if ($_col && stripos((string)($_col['Collation'] ?? ''), 'utf8mb4_bin') === false) {
            $pdo->exec("ALTER TABLE intus_reacao_pub
                        MODIFY COLUMN emoji VARCHAR(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL");
        }
    } catch (Throwable $e) {}

    [$_autorTipo, $_autorId, ] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        $tipo = trim((string)($_GET['alvo_tipo'] ?? 'mural'));
        $idsRaw = trim((string)($_GET['ids'] ?? ''));
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), function ($n) { return $n > 0; }));
        if (!count($ids)) { echo json_encode(new stdClass()); exit; }
        if (count($ids) > 500) $ids = array_slice($ids, 0, 500);
        if ($tipo === 'chat') {
            $ids = array_values(array_filter($ids, function ($i) use ($pdo, $_ctx, $_ehAluno) { return _chatMensagem($pdo, $_ctx, $_ehAluno, $i) !== null; }));
            if (!count($ids)) { echo json_encode(new stdClass()); exit; }
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT alvo_id, emoji, autor_tipo, autor_id, autor_nome
                             FROM intus_reacao_pub WHERE alvo_tipo = ? AND alvo_id IN ($ph)
                             ORDER BY idreacao ASC");
        $st->execute(array_merge([$tipo], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)(int)$r['alvo_id'];
            if (!isset($out[$k])) $out[$k] = [];
            $out[$k][] = [
                'emoji'      => $r['emoji'],
                'autor_tipo' => $r['autor_tipo'],
                'autor_id'   => (int)$r['autor_id'],
                'autor_nome' => $r['autor_tipo'] === 'prof' ? _nomePublicoProf((int)$r['autor_id'], (string)$r['autor_nome']) : $r['autor_nome'],
                'meu'        => ($r['autor_tipo'] === $_autorTipo && (int)$r['autor_id'] === $_autorId),
            ];
        }
        echo json_encode($out ?: new stdClass(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();
        $tipo  = trim((string)($b['alvo_tipo'] ?? 'mural'));
        $alvo  = (int)($b['alvo_id'] ?? 0);
        $emoji = trim((string)($b['emoji'] ?? ''));
        if ($alvo <= 0 || $emoji === '') { http_response_code(400); echo json_encode(['error' => 'alvo_id e emoji obrigatorios']); exit; }
        if (mb_strlen($emoji) > 8)       { http_response_code(400); echo json_encode(['error' => 'emoji invalido']); exit; }
        $msgChat = null;
        if ($tipo === 'chat') {
            $msgChat = _chatMensagem($pdo, $_ctx, $_ehAluno, $alvo);
            if (!$msgChat) { http_response_code(403); echo json_encode(['error' => 'sem acesso a esta mensagem']); exit; }
        }

        // Nome de quem reagiu, buscado no servidor — nao aceita o que o cliente diz.
        [, , $nome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);

        // Ações explícitas (não alternam, então repetir o pedido é seguro):
        //  remover: apaga TODAS as reações da pessoa neste alvo.
        //  definir: deixa só esta reação (apaga as outras; se já é esta, não faz mais nada).
        // "unica" (apps antigos): igual a definir, mas pedir a mesma reação de novo ainda remove.
        if (!empty($b['remover'])) {
            $pdo->prepare("DELETE FROM intus_reacao_pub WHERE alvo_tipo = ? AND alvo_id = ? AND autor_tipo = ? AND autor_id = ?")->execute([$tipo, $alvo, $_autorTipo, $_autorId]);
            echo json_encode(['ok' => true, 'estado' => 'removida']);
            exit;
        }
        if (!empty($b['unica']) || !empty($b['definir'])) {
            $pdo->prepare("DELETE FROM intus_reacao_pub WHERE alvo_tipo = ? AND alvo_id = ? AND autor_tipo = ? AND autor_id = ? AND emoji <> ?")->execute([$tipo, $alvo, $_autorTipo, $_autorId, $emoji]);
            if (!empty($b['definir'])) {
                $sj = $pdo->prepare("SELECT 1 FROM intus_reacao_pub WHERE alvo_tipo = ? AND alvo_id = ? AND autor_tipo = ? AND autor_id = ? AND emoji = ? LIMIT 1");
                $sj->execute([$tipo, $alvo, $_autorTipo, $_autorId, $emoji]);
                if ($sj->fetchColumn()) { echo json_encode(['ok' => true, 'estado' => 'definida']); exit; }
            }
        }
        $st = $pdo->prepare("SELECT idreacao FROM intus_reacao_pub WHERE alvo_tipo = ? AND alvo_id = ? AND autor_tipo = ? AND autor_id = ? AND emoji = ? LIMIT 1");
        $st->execute([$tipo, $alvo, $_autorTipo, $_autorId, $emoji]);
        $existe = $st->fetchColumn();
        if ($existe) {
            $pdo->prepare("DELETE FROM intus_reacao_pub WHERE idreacao = ?")->execute([$existe]);
            echo json_encode(['ok' => true, 'estado' => 'removida']);
        } else {
            $pdo->prepare("INSERT INTO intus_reacao_pub (alvo_tipo, alvo_id, autor_tipo, autor_id, autor_nome, emoji) VALUES (?,?,?,?,?,?)")
                ->execute([$tipo, $alvo, $_autorTipo, $_autorId, $nome, $emoji]);
            // Curtida em post do Feed avisa o dono (só nesta fase: o mural tem
            // outro desenho). _criarNotificacao nunca lança.
            if ($tipo === 'feed_post') {
                _criarNotificacao($pdo, 'aluno', _donoDoPostFeed($pdo, $alvo), 'curtida', $_autorTipo, $_autorId, $nome, 'feed_post', $alvo, $emoji, true);
            } elseif ($tipo === 'resultado') {
                try {
                    $stR = $pdo->prepare("SELECT idatleta, texto FROM intus_resultado WHERE idresultado = ? AND ativo = 1");
                    $stR->execute([$alvo]);
                    $res = $stR->fetch(PDO::FETCH_ASSOC);
                    if ($res) _criarNotificacao($pdo, 'aluno', (int)$res['idatleta'], 'curtida', $_autorTipo, $_autorId, $nome, 'resultado', $alvo, (string)($res['texto'] ?? ''), true);
                } catch (Throwable $e) {}
            } elseif ($tipo === 'chat' && $msgChat) {
                // Curtida numa mensagem do chat avisa quem escreveu a mensagem.
                if ($msgChat['remetente'] === 'aluno') {
                    _criarNotificacao($pdo, 'aluno', (int)$msgChat['idatleta'], 'curtida_chat', $_autorTipo, $_autorId, $nome, 'chat', $alvo, (string)$msgChat['texto'], true);
                } elseif ((int)$msgChat['idusuario'] > 0) {
                    _criarNotificacao($pdo, 'prof', (int)$msgChat['idusuario'], 'curtida_chat', $_autorTipo, $_autorId, $nome, 'chat', $alvo, (string)$msgChat['texto'], true);
                }
            } elseif ($tipo === 'mural') {
                // Reação ao comentário do MURAL (o "post" do mural) avisa o autor dele.
                try {
                    $stM = $pdo->prepare("SELECT idatleta, texto FROM intus_ranking_comentario WHERE idcomentario = ?");
                    $stM->execute([$alvo]);
                    $mur = $stM->fetch(PDO::FETCH_ASSOC);
                    if ($mur) _criarNotificacao($pdo, 'aluno', (int)$mur['idatleta'], 'curtida', $_autorTipo, $_autorId, $nome, 'mural', $alvo, (string)$mur['texto'], true);
                } catch (Throwable $e) {}
            } elseif ($tipo === 'feed_comentario') {
                // Curtida em COMENTÁRIO avisa o autor dele. O alvo da notificação
                // é o POST (é pra onde o toque leva); o texto é o trecho curtido.
                try {
                    $stC = $pdo->prepare("SELECT autor_tipo, autor_id, alvo_tipo, alvo_id, texto FROM intus_comentario_pub WHERE idcomentario = ? AND alvo_tipo IN ('feed_post', 'mural')");
                    $stC->execute([$alvo]);
                    $com = $stC->fetch(PDO::FETCH_ASSOC);
                    if ($com) _criarNotificacao($pdo, $com['autor_tipo'], (int)$com['autor_id'], 'curtida_comentario', $_autorTipo, $_autorId, $nome, $com['alvo_tipo'], (int)$com['alvo_id'], (string)$com['texto'], true);
                } catch (Throwable $e) {}
            }
            echo json_encode(['ok' => true, 'estado' => 'adicionada']);
        }
        exit;
    }
}

// ═══════════════ COMENTÁRIOS (genérico, mesmo desenho da reação) ═══════════
if ($action === 'comentarios_pub') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, ] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        $tipo = trim((string)($_GET['alvo_tipo'] ?? ''));
        $idsRaw = trim((string)($_GET['ids'] ?? ''));
        $ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw)), function ($n) { return $n > 0; }));
        if ($tipo === '' || !count($ids)) { echo json_encode(new stdClass()); exit; }
        if (count($ids) > 200) $ids = array_slice($ids, 0, 200);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT idcomentario, alvo_id, resposta_a, autor_tipo, autor_id, autor_nome, texto, created_at
                             FROM intus_comentario_pub WHERE alvo_tipo = ? AND alvo_id IN ($ph)
                             ORDER BY idcomentario ASC");
        $st->execute(array_merge([$tipo], $ids));
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)(int)$r['alvo_id'];
            if (!isset($out[$k])) $out[$k] = [];
            $out[$k][] = [
                'idcomentario' => (int)$r['idcomentario'],
                'resposta_a'   => $r['resposta_a'] !== null ? (int)$r['resposta_a'] : null,
                'autor_tipo'   => $r['autor_tipo'],
                'autor_id'     => (int)$r['autor_id'],
                'autor_nome'   => $r['autor_tipo'] === 'prof' ? _nomePublicoProf((int)$r['autor_id'], (string)$r['autor_nome']) : $r['autor_nome'],
                'texto'        => $r['texto'],
                'created_at'   => $r['created_at'],
                'meu'          => ($r['autor_tipo'] === $_autorTipo && (int)$r['autor_id'] === $_autorId),
            ];
        }
        echo json_encode($out ?: new stdClass(), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $tipo  = trim((string)($b['alvo_tipo'] ?? ''));
        $alvo  = (int)($b['alvo_id'] ?? 0);
        $texto = trim((string)($b['texto'] ?? ''));
        if ($tipo === '' || $alvo <= 0 || $texto === '') { http_response_code(400); echo json_encode(['error' => 'dados incompletos']); exit; }
        $texto = _mencoesLimitar($texto, 1000);
        // Se responde a outro comentário, confirma que o pai é do MESMO alvo —
        // sem isso um id de comentário de outro post viraria resposta aqui.
        $respostaA = (int)($b['resposta_a'] ?? 0);
        if ($respostaA > 0) {
            $stChk = $pdo->prepare("SELECT 1 FROM intus_comentario_pub WHERE idcomentario = ? AND alvo_tipo = ? AND alvo_id = ?");
            $stChk->execute([$respostaA, $tipo, $alvo]);
            if (!$stChk->fetchColumn()) $respostaA = 0;
        }
        [, , $nome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
        $pdo->prepare("INSERT INTO intus_comentario_pub (alvo_tipo, alvo_id, resposta_a, autor_tipo, autor_id, autor_nome, texto) VALUES (?,?,?,?,?,?,?)")
            ->execute([$tipo, $alvo, $respostaA > 0 ? $respostaA : null, $_autorTipo, $_autorId, $nome, $texto]);
        $novoIdComentario = (int)$pdo->lastInsertId();

        // Notificações (só Feed nesta fase). Resposta avisa o autor do
        // comentário-pai; comentário (ou resposta) também avisa o dono do post,
        // sem repetir a mesma pessoa duas vezes pro mesmo comentário. Qualquer
        // falha aqui é engolida: o comentário já foi gravado.
        if ($tipo === 'feed_post' || $tipo === 'mural') {
            try {
                $avisados = [];
                if ($respostaA > 0) {
                    $stPai = $pdo->prepare("SELECT autor_tipo, autor_id FROM intus_comentario_pub WHERE idcomentario = ?");
                    $stPai->execute([$respostaA]);
                    $pai = $stPai->fetch(PDO::FETCH_ASSOC);
                    if ($pai) {
                        $avisados[$pai['autor_tipo'] . ':' . (int)$pai['autor_id']] = 1;
                        _criarNotificacao($pdo, $pai['autor_tipo'], (int)$pai['autor_id'], 'resposta', $_autorTipo, $_autorId, $nome, $tipo, $alvo, $texto);
                    }
                }
                $dono = _donoDoAlvoPub($pdo, $tipo, $alvo);
                if ($dono > 0 && empty($avisados['aluno:' . $dono])) {
                    _criarNotificacao($pdo, 'aluno', $dono, 'comentario', $_autorTipo, $_autorId, $nome, $tipo, $alvo, $texto);
                }
                if ($dono > 0) $avisados['aluno:' . $dono] = 1;
                // @menções no texto: avisa cada pessoa marcada que ainda não foi avisada por este comentário.
                _notificarMencoes($pdo, $texto, $_autorTipo, $_autorId, $nome, $tipo, $alvo, $avisados);
            } catch (Throwable $e) {}
        }

        echo json_encode(['ok' => true, 'idcomentario' => $novoIdComentario]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        // Apaga: o autor do comentário, o DONO DO POST (modera o que aparece no
        // próprio post, só no Feed) ou o professor (moderando).
        $st = $pdo->prepare("SELECT autor_tipo, autor_id, alvo_tipo, alvo_id FROM intus_comentario_pub WHERE idcomentario = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['ok' => true]); exit; }
        $ehDono = ($row['autor_tipo'] === $_autorTipo && (int)$row['autor_id'] === $_autorId);
        $ehDonoDoPost = ($_ehAluno && in_array($row['alvo_tipo'], ['feed_post', 'mural'], true) && _donoDoAlvoPub($pdo, $row['alvo_tipo'], (int)$row['alvo_id']) === $_autorId);
        if (!$ehDono && !$ehDonoDoPost && $_ehAluno) { http_response_code(403); echo json_encode(['error' => 'sem permissao']); exit; }
        // Apagar um comentário leva junto as respostas dele e as curtidas de
        // ambos: sem isso as respostas ficariam órfãs (somem da tela mas
        // continuam contando em "Ver N comentários").
        // Respostas podem ter respostas, em qualquer nível: desce a cadeia inteira (com teto de segurança).
        $idsApagar = [$id];
        $fronteira = [$id];
        for ($nivel = 0; $nivel < 30 && count($fronteira); $nivel++) {
            $phF = implode(',', array_fill(0, count($fronteira), '?'));
            $stF = $pdo->prepare("SELECT idcomentario FROM intus_comentario_pub WHERE resposta_a IN ($phF)");
            $stF->execute($fronteira);
            $filhos = array_values(array_diff(array_map('intval', $stF->fetchAll(PDO::FETCH_COLUMN)), $idsApagar));
            $idsApagar = array_merge($idsApagar, $filhos);
            $fronteira = $filhos;
        }
        if (count($idsApagar)) {
            $phA = implode(',', array_fill(0, count($idsApagar), '?'));
            $pdo->prepare("DELETE FROM intus_comentario_pub WHERE idcomentario IN ($phA)")->execute($idsApagar);
            try { $pdo->prepare("DELETE FROM intus_reacao_pub WHERE alvo_tipo = 'feed_comentario' AND alvo_id IN ($phA)")->execute($idsApagar); } catch (Throwable $e) {}
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ PESSOAS QUE PODEM SER MENCIONADAS (@) ═══════════════
// Alunos que entraram no Feed (feed_optin) e não estão bloqueados, mais os
// professores. Quem tem bloqueio de feed com quem pergunta (nos dois sentidos)
// não aparece. Só nome e id: nada de contato.
if ($action === 'pessoas_mencionaveis') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_pmTipo, $_pmId, ] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_pmId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }
    $pessoas = [];
    try {
        $ocultos = [];
        if ($_pmTipo === 'aluno') {
            try {
                $stP = $pdo->prepare("SELECT idalvo AS o FROM intus_feed_pref WHERE idatleta = ? UNION SELECT idatleta AS o FROM intus_feed_pref WHERE idalvo = ?");
                $stP->execute([$_pmId, $_pmId]);
                $ocultos = array_map('intval', $stP->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {}
        }
        $tblA = _feedDetectarTabelaAtleta($pdo);
        if ($tblA) {
            try {
                $rowsA = $pdo->query("SELECT idatleta, nome, dtcadastro FROM `$tblA` WHERE feed_optin = 'S' AND (stbloqueio IS NULL OR stbloqueio != 'S')")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                try { $rowsA = $pdo->query("SELECT idatleta, nome FROM `$tblA` WHERE feed_optin = 'S' AND (stbloqueio IS NULL OR stbloqueio != 'S')")->fetchAll(PDO::FETCH_ASSOC); }
                catch (Throwable $e2) { $rowsA = []; }
            }
            foreach ($rowsA as $r) {
                $ida = (int)$r['idatleta'];
                if ($ida <= 0 || trim((string)$r['nome']) === '' || ($_pmTipo === 'aluno' && $ida === $_pmId) || in_array($ida, $ocultos, true)) continue;
                // "desde MM/AAAA" só serve pra a pessoa distinguir dois alunos de mesmo nome na lista.
                $desde = (!empty($r['dtcadastro']) && preg_match('/^(\d{4})-(\d{2})/', (string)$r['dtcadastro'], $mm)) ? ($mm[2] . '/' . $mm[1]) : '';
                $pessoas[] = ['tipo' => 'aluno', 'id' => $ida, 'nome' => trim((string)$r['nome']), 'desde' => $desde];
            }
        }
        foreach (['professor', 'usuario', 'usuarios', 'professores'] as $tblU) {
            try { $colsU = array_column($pdo->query("SHOW COLUMNS FROM `$tblU`")->fetchAll(PDO::FETCH_ASSOC), 'Field'); } catch (Throwable $e) { continue; }
            $cId = null; foreach (['idusuario', 'idprofessor', 'id'] as $c) { if (in_array($c, $colsU, true)) { $cId = $c; break; } }
            $cNm = null; foreach (['nome', 'nmusuario', 'nmprofessor', 'name'] as $c) { if (in_array($c, $colsU, true)) { $cNm = $c; break; } }
            if (!$cId || !$cNm) continue;
            foreach ($pdo->query("SELECT `$cId` AS i, `$cNm` AS n FROM `$tblU`")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $idp = (int)$r['i'];
                if ($idp <= 0 || trim((string)$r['n']) === '' || ($_pmTipo === 'prof' && $idp === $_pmId)) continue;
                $pessoas[] = ['tipo' => 'prof', 'id' => $idp, 'nome' => _nomePublicoProf($idp, trim((string)$r['n']))];
            }
            break;
        }
    } catch (Throwable $e) {}
    usort($pessoas, function ($x, $y) { return strcasecmp($x['nome'], $y['nome']); });
    echo json_encode(['pessoas' => array_slice($pessoas, 0, 500)], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══════════════ NOTIFICAÇÕES (sino) ═══════════════
// Cada pessoa só vê e só marca como lidas as PRÓPRIAS (WHERE sempre com
// destino_tipo + destino_id vindos do token, nunca do cliente).
if ($action === 'notificacoes') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_nTipo, $_nId, ] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_nId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }
    try { _notifGarantirTabela($pdo); } catch (Throwable $e) {
        http_response_code(500); echo json_encode(['error' => 'falha ao preparar notificacoes']); exit;
    }

    if ($method === 'GET') {
        // Notificação de post apagado (ou de comentário do mural apagado) não
        // aparece nem conta no sino. Filtra em vez de só apagar: cobre também o
        // que já ficou órfão antes desta regra, sem mexer em nenhuma linha.
        $filtroAlvo = " AND (alvo_tipo <> 'feed_post' OR EXISTS (SELECT 1 FROM intus_feed_post p WHERE p.idpost = intus_notificacao.alvo_id AND p.ativo = 1))";
        $filtroAlvo .= " AND (alvo_tipo <> 'resultado' OR EXISTS (SELECT 1 FROM intus_resultado r WHERE r.idresultado = intus_notificacao.alvo_id AND r.ativo = 1))";
        try {
            $pdo->query("SELECT 1 FROM intus_mensagem LIMIT 1");
            $filtroAlvo .= " AND (alvo_tipo <> 'chat' OR EXISTS (SELECT 1 FROM intus_mensagem m WHERE m.idmensagem = intus_notificacao.alvo_id))";
        } catch (Throwable $e) {}
        try {
            $pdo->query("SELECT 1 FROM intus_ranking_comentario LIMIT 1");
            $filtroAlvo .= " AND (alvo_tipo <> 'mural' OR EXISTS (SELECT 1 FROM intus_ranking_comentario c WHERE c.idcomentario = intus_notificacao.alvo_id))";
        } catch (Throwable $e) {}
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_notificacao WHERE destino_tipo = ? AND destino_id = ? AND lido = 0" . $filtroAlvo);
        $st->execute([$_nTipo, $_nId]);
        $naoLidas = (int)$st->fetchColumn();
        // Polling pede só o número (barato); a lista só quando o sino abre.
        if (!empty($_GET['so_contagem'])) { echo json_encode(['nao_lidas' => $naoLidas]); exit; }
        $st = $pdo->prepare("SELECT idnotificacao, tipo, ator_tipo, ator_id, ator_nome, alvo_tipo, alvo_id, texto, lido, created_at
                             FROM intus_notificacao WHERE destino_tipo = ? AND destino_id = ?" . $filtroAlvo . "
                             ORDER BY idnotificacao DESC LIMIT 50");
        $st->execute([$_nTipo, $_nId]);
        $lista = array_map(function ($r) {
            return [
                'idnotificacao' => (int)$r['idnotificacao'],
                'tipo'          => $r['tipo'],
                'ator_tipo'     => $r['ator_tipo'],
                'ator_id'       => (int)$r['ator_id'],
                'ator_nome'     => $r['ator_tipo'] === 'prof' ? _nomePublicoProf((int)$r['ator_id'], (string)$r['ator_nome']) : $r['ator_nome'],
                'alvo_tipo'     => $r['alvo_tipo'],
                'alvo_id'       => (int)$r['alvo_id'],
                'texto'         => $r['texto'] ?? '',
                'lido'          => (int)$r['lido'] === 1,
                'quando'        => $r['created_at'],
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['notificacoes' => $lista, 'nao_lidas' => $naoLidas], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();
        // Medalha e distintivo são calculados no app (não há evento no servidor
        // pra disparar): o app avisa aqui e a notificação nasce PARA QUEM PEDIU.
        // Só esses dois tipos, nunca pra outra pessoa, e o mesmo (tipo + chave)
        // só entra uma vez (dedupe), então reenviar é seguro.
        if (!empty($b['registrar']) && is_array($b['registrar'])) {
            $rg = $b['registrar'];
            $rTipo  = (string)($rg['tipo'] ?? '');
            $rChave = trim((string)($rg['chave'] ?? ''));
            $rTexto = trim((string)($rg['texto'] ?? ''));
            if (!in_array($rTipo, ['medalha', 'distintivo', 'parceiro'], true) || $rChave === '' || mb_strlen($rChave) > 80 || $rTexto === '') {
                http_response_code(400); echo json_encode(['error' => 'registro invalido']); exit;
            }
            $rAlvo = crc32($rTipo . ':' . $rChave) & 0x7fffffff;
            _criarNotificacao($pdo, $_nTipo, $_nId, $rTipo, 'sistema', 1, 'Intus', $rTipo, $rAlvo, $rTexto, true);
            echo json_encode(['ok' => true]); exit;
        }
        if (!empty($b['marcar_todos'])) {
            $pdo->prepare("UPDATE intus_notificacao SET lido = 1 WHERE destino_tipo = ? AND destino_id = ? AND lido = 0")->execute([$_nTipo, $_nId]);
            echo json_encode(['ok' => true]); exit;
        }
        $idn = (int)($b['idnotificacao'] ?? 0);
        if ($idn <= 0) { http_response_code(400); echo json_encode(['error' => 'idnotificacao obrigatorio']); exit; }
        $pdo->prepare("UPDATE intus_notificacao SET lido = 1 WHERE idnotificacao = ? AND destino_tipo = ? AND destino_id = ?")->execute([$idn, $_nTipo, $_nId]);
        echo json_encode(['ok' => true]); exit;
    }
}

// ═══════════════ FEED DOS ALUNOS ═══════════════
// Só posts feitos de propósito (foto/filtro + legenda) entram aqui — nada é
// gerado automaticamente a partir de treino. Vê o feed quem está com
// feed_optin='S' (opt-in próprio, separado do ranking) e não está bloqueado
// nas duas direções (intus_feed_pref).
function _feedDetectarTabelaAtleta(PDO $pdo) {
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $_t) {
        try { $pdo->query("SELECT 1 FROM `$_t` LIMIT 1"); return $_t; } catch (Throwable $e) {}
    }
    return null;
}

if ($action === 'feed_posts') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        $tbl = _feedDetectarTabelaAtleta($pdo);
        $optinIds = [];
        $nomeMap = [];
        $avatarMap = [];
        if ($tbl) {
            try {
                $rows = $pdo->query("SELECT idatleta, nome FROM `$tbl` WHERE feed_optin = 'S' AND (stbloqueio IS NULL OR stbloqueio != 'S')")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows = $pdo->query("SELECT idatleta, nome FROM `$tbl`")->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as $r) { $optinIds[] = (int)$r['idatleta']; $nomeMap[(int)$r['idatleta']] = $r['nome']; }
            try {
                $avRows = $pdo->query("SELECT idatleta, avatar FROM intus_profile WHERE avatar IS NOT NULL AND avatar != ''")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($avRows as $av) { $avatarMap[(int)$av['idatleta']] = $av['avatar']; }
            } catch (Throwable $e) {}
        }
        // Quem EU escolhi não ver, e quem escolheu não me mostrar.
        // Preferências de bloqueio são entre ALUNOS. Professor (id de outra
        // tabela) não passa por elas: o número dele não pode ser lido como se
        // fosse o de um aluno.
        $ocultarDeles = [];
        $meOcultaram  = [];
        if ($_autorTipo === 'aluno') {
            try {
                $st = $pdo->prepare("SELECT idalvo FROM intus_feed_pref WHERE idatleta = ? AND tipo = 'ver'");
                $st->execute([$_autorId]);
                $ocultarDeles = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                $st = $pdo->prepare("SELECT idatleta FROM intus_feed_pref WHERE idalvo = ? AND tipo = 'mostrar'");
                $st->execute([$_autorId]);
                $meOcultaram = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            } catch (Throwable $e) {}
        }

        $visiveis = array_values(array_diff($optinIds, $ocultarDeles, $meOcultaram));
        // Privacidade: cada publicação tem a sua (intus_post_visib); sem ela vale o padrão do perfil do dono.
        // O filtro é feito na consulta, mais abaixo. O próprio dono sempre se vê; a equipe (professor) enxerga tudo.
        // Filtro "Amigos" do Feed (só com as conexões ligadas): os amigos e o próprio aluno.
        if (INTUS_CONEXOES_ATIVO && $_autorTipo === 'aluno' && ($_GET['so_amigos'] ?? '') === '1' && (int)($_GET['idatleta'] ?? 0) <= 0) {
            $visiveis = array_values(array_intersect($visiveis, array_merge(_amigosDe($pdo, $_autorId), [$_autorId])));
        }
        $somenteAutor = (int)($_GET['idatleta'] ?? 0);
        if ($somenteAutor > 0) $visiveis = (($_autorTipo === 'aluno' && $somenteAutor === $_autorId) || in_array($somenteAutor, $visiveis, true)) ? [$somenteAutor] : [];
        // Uma publicação só (notificação que abre o post): vale a mesma privacidade de sempre; o próprio dono sempre se vê.
        $umPost = (int)($_GET['idpost'] ?? 0);
        if ($umPost > 0 && $_autorTipo === 'aluno' && !in_array($_autorId, $visiveis, true)) $visiveis[] = $_autorId;

        if (!count($visiveis)) {
            // Perfil de alguém que deixou as fotos privadas: avisa o app para mostrar "fotos privadas" em vez de "sem posts".
            echo json_encode(['posts' => [], 'atletas' => $nomeMap, 'avatars' => $avatarMap, 'temMais' => false, 'restrito' => null]);
            exit;
        }
        $ph = implode(',', array_fill(0, count($visiveis), '?'));
        $limite = min(100, max(1, (int)($_GET['limite'] ?? 30)));
        // Cursor de paginação: idpost é AUTO_INCREMENT, então cresce junto com
        // created_at na prática — "antes_de" evita precisar de cursor composto.
        $antesDe = (int)($_GET['antes_de'] ?? 0);
        // Sem filtro de atleta = feed geral: só posts com destino 'feed'. Com
        // filtro (perfil de alguém, inclusive o próprio) mostra os dois — é
        // a grade do perfil, que é o único lugar onde um post 'perfil' aparece.
        $filtroDestino = ($somenteAutor > 0 || $umPost > 0) ? '' : " AND p.destino = 'feed'";
        $filtroCursor = $umPost > 0 ? " AND p.idpost = $umPost" : ($antesDe > 0 ? " AND p.idpost < $antesDe" : "");
        $ef = "COALESCE(v.visib, c.fotos_visib, 'todos')";
        $filtroPriv = '';
        if ($_autorTipo === 'aluno') {
            $amigosIn = implode(',', array_map('intval', _amigosDe($pdo, $_autorId))) ?: '0';
            $filtroPriv = " AND (p.idatleta = " . (int)$_autorId . " OR $ef = 'todos' OR ($ef = 'amigos' AND p.idatleta IN ($amigosIn)))";
        }
        // Busca um a mais que o pedido só pra saber se tem mais página depois
        // desta, sem precisar de um COUNT(*) separado.
        $st = $pdo->prepare("SELECT p.idpost, p.idatleta, p.imagem, p.legenda, p.destino, p.localizacao, p.created_at, $ef AS visib FROM intus_feed_post p
                             LEFT JOIN intus_post_visib v ON v.idpost = p.idpost
                             LEFT JOIN intus_perfil_config c ON c.idatleta = p.idatleta
                             WHERE p.ativo = 1 AND p.idatleta IN ($ph)$filtroDestino$filtroCursor$filtroPriv ORDER BY p.created_at DESC, p.idpost DESC LIMIT " . ($limite + 1));
        $st->execute($visiveis);
        $posts = $st->fetchAll(PDO::FETCH_ASSOC);
        $temMais = count($posts) > $limite;
        if ($temMais) $posts = array_slice($posts, 0, $limite);
        $_midiasMap = _midiasDosPosts($pdo, 'feed_post', array_column($posts, 'idpost'));
        foreach ($posts as &$p) {
            $p['idpost'] = (int)$p['idpost']; $p['idatleta'] = (int)$p['idatleta'];
            if (!($_autorTipo === 'aluno' && $p['idatleta'] === $_autorId)) unset($p['visib']);   // só o dono vê a privacidade da própria publicação
            $p['midias'] = $_midiasMap[$p['idpost']] ?? [['tipo' => 'imagem', 'url' => $p['imagem'], 'poster' => null]];
            foreach ($p['midias'] as &$_mm) { if ($_mm['tipo'] === 'video' && empty($_mm['poster'])) $_mm['poster'] = $p['imagem']; }
            unset($_mm);
        }
        unset($p);
        $restritoAgora = null;
        if (!$posts && $somenteAutor > 0 && $_autorTipo === 'aluno' && $somenteAutor !== $_autorId) {
            try {
                $sq = $pdo->prepare("SELECT COUNT(*) FROM intus_feed_post WHERE idatleta = ? AND ativo = 1");
                $sq->execute([$somenteAutor]);
                if ((int)$sq->fetchColumn() > 0) $restritoAgora = 'privado';
            } catch (Throwable $e) {}
        }
        echo json_encode(['posts' => $posts, 'atletas' => $nomeMap, 'avatars' => $avatarMap, 'temMais' => $temMais, 'restrito' => $restritoAgora], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $b = jsonBody();
        $imagemRaw = (string)($b['imagem'] ?? '');
        $legenda = trim((string)($b['legenda'] ?? ''));
        $legenda = _mencoesLimitar($legenda, 1000);
        if ($imagemRaw === '') { http_response_code(400); echo json_encode(['error' => 'imagem obrigatoria']); exit; }
        $destino = (string)($b['destino'] ?? 'feed');
        if (!in_array($destino, ['feed', 'perfil'], true)) $destino = 'feed';
        $localizacao = trim((string)($b['localizacao'] ?? ''));
        if (mb_strlen($localizacao) > 120) $localizacao = mb_substr($localizacao, 0, 120);
        // Carrossel: o post nasce invisível (ativo=2) e só aparece no "finalizar",
        // depois que todas as fotos extras chegaram (uma por requisição).
        $totalMidias = max(1, min(10, (int)($b['total_midias'] ?? 1)));
        // Post de vídeo: a "imagem" é só a capa do vídeo; o vídeo sobe depois (midia.php).
        $capaDeVideo = !empty($b['capa_de_video']);
        $ativoNovo = ($totalMidias > 1 || $capaDeVideo) ? 2 : 1;
        // Post preso pela metade há mais de 24 h (celular sem sinal no meio do envio) é descartado.
        try { $pdo->exec("UPDATE intus_feed_post SET ativo = 0 WHERE ativo = 2 AND created_at < (NOW() - INTERVAL 1 DAY)"); } catch (Throwable $e) {}

        // Salva como arquivo (mesmo padrão de _persistirFotos em avaliações):
        // nunca guardamos a imagem inteira em base64 dentro do banco.
        $url = null;
        if (preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#is', $imagemRaw, $m)) {
            $dir = __DIR__ . '/../img/feed';
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $ext = strtolower($m[1]); if ($ext === 'jpeg') $ext = 'jpg';
            $bin = base64_decode($m[2], true);
            if ($bin === false || strlen($bin) > 8 * 1024 * 1024) { http_response_code(400); echo json_encode(['error' => 'imagem invalida ou grande demais']); exit; }
            if (!is_dir($dir) || !is_writable($dir)) { http_response_code(500); echo json_encode(['error' => 'sem permissao de escrita']); exit; }
            try { $rand = bin2hex(random_bytes(6)); } catch (Throwable $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }
            $fname = 'feed_' . $_autorId . '_' . time() . '_' . $rand . '.' . $ext;
            if (@file_put_contents($dir . '/' . $fname, $bin) === false) { http_response_code(500); echo json_encode(['error' => 'falha ao salvar imagem']); exit; }
            if (function_exists('gdriveBackup')) { try { gdriveBackup($bin, $fname, 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext)); } catch (Throwable $e) {} }
            $url = _appBaseUrl() . '/img/feed/' . $fname;
        } elseif (preg_match('#^https?://#i', $imagemRaw)) {
            $url = $imagemRaw; // já é uma URL salva (ex.: reenvio) — mantém
        } else {
            http_response_code(400); echo json_encode(['error' => 'formato de imagem invalido']); exit;
        }

        // BUG real relatado em producao: "erro 500" sem motivo nenhum ao
        // publicar no Feed. O INSERT era o unico passo deste bloco sem
        // try/catch — qualquer falha de banco virava erro fatal cru (sem
        // JSON), e o front so sabe mostrar "erro 500" quando nao consegue
        // interpretar a resposta como JSON. A imagem ja tinha sido salva em
        // disco nesse ponto, entao o aluno perdia so o registro do post, nao
        // a foto. Mesmo padrao de log+referencia ja usado na conexao do banco.
        $visibNovo = (string)($b['visib'] ?? '');
        if (!in_array($visibNovo, ['todos', 'amigos', 'eu'], true)) $visibNovo = ($_autorTipo === 'aluno') ? _perfilConfig($pdo, $_autorId)['fotos_visib'] : 'todos';
        try {
            $st = $pdo->prepare("INSERT INTO intus_feed_post (idatleta, imagem, legenda, destino, localizacao, ativo) VALUES (?,?,?,?,?,?)");
            $st->execute([$_autorId, $url, $legenda !== '' ? $legenda : null, $destino, $localizacao !== '' ? $localizacao : null, $ativoNovo]);
            $novoPost = (int)$pdo->lastInsertId();
            try { $pdo->prepare("INSERT INTO intus_post_visib (idpost, visib) VALUES (?,?) ON DUPLICATE KEY UPDATE visib = VALUES(visib)")->execute([$novoPost, $visibNovo]); } catch (Throwable $e) {}
            if (!$capaDeVideo) {
                try {
                    $pdo->prepare("INSERT INTO intus_midia (dono_tipo, dono_id, ordem, tipo, url) VALUES ('feed_post', ?, 0, 'imagem', ?)")->execute([$novoPost, $url]);
                } catch (Throwable $e) {}
            }
            // @menções na legenda avisam as pessoas marcadas (não em post só do perfil).
            // Em carrossel isso espera o "finalizar", quando o post de fato aparece.
            if ($ativoNovo === 1 && $legenda !== '' && $destino === 'feed' && $visibNovo === 'todos') _notificarMencoes($pdo, $legenda, $_autorTipo, $_autorId, (string)$_autorNome, 'feed_post', $novoPost);
            echo json_encode(['ok' => true, 'idpost' => $novoPost, 'imagem' => $url, 'destino' => $destino, 'pendente' => $ativoNovo === 2]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao gravar o post', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }

    if ($method === 'PUT') {
        $b = jsonBody();
        $id = (int)($b['idpost'] ?? 0);
        $v = (string)($b['visib'] ?? '');
        if ($id <= 0 || !in_array($v, ['todos', 'amigos', 'eu'], true)) { http_response_code(400); echo json_encode(['error' => 'dados invalidos']); exit; }
        $st = $pdo->prepare("SELECT idatleta FROM intus_feed_post WHERE idpost = ? AND ativo > 0");
        $st->execute([$id]);
        $dono = (int)$st->fetchColumn();
        if (!$dono || $_autorTipo !== 'aluno' || $dono !== $_autorId) { http_response_code(403); echo json_encode(['error' => 'so o dono altera a privacidade']); exit; }
        $pdo->prepare("INSERT INTO intus_post_visib (idpost, visib) VALUES (?,?) ON DUPLICATE KEY UPDATE visib = VALUES(visib)")->execute([$id, $v]);
        echo json_encode(['ok' => true, 'visib' => $v]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $st = $pdo->prepare("SELECT idatleta FROM intus_feed_post WHERE idpost = ?");
        $st->execute([$id]);
        $dono = (int)$st->fetchColumn();
        if ($dono && $dono !== $_autorId && $_ehAluno) { http_response_code(403); echo json_encode(['error' => 'sem permissao']); exit; }
        $pdo->prepare("UPDATE intus_feed_post SET ativo = 0 WHERE idpost = ?")->execute([$id]);
        // Vídeos do post saem do Drive junto (a linha fica, sem arquivo, com status 'apagado').
        try {
            $sv = $pdo->prepare("SELECT idmidia, drive_id FROM intus_midia WHERE dono_tipo = 'feed_post' AND dono_id = ? AND tipo = 'video' AND drive_id IS NOT NULL");
            $sv->execute([$id]);
            foreach ($sv->fetchAll(PDO::FETCH_ASSOC) as $vv) {
                $ok = false;
                try { if (function_exists('gdriveExcluirArquivo')) $ok = gdriveExcluirArquivo($vv['drive_id']); } catch (Throwable $e) {}
                if ($ok) $pdo->prepare("UPDATE intus_midia SET status = 'apagado', drive_id = NULL WHERE idmidia = ?")->execute([(int)$vv['idmidia']]);
            }
        } catch (Throwable $e) {}
        // Notificações do post (curtida, comentário, resposta) só fazem sentido
        // enquanto ele existe; são derivadas, então apagar é seguro.
        try { $pdo->prepare("DELETE FROM intus_notificacao WHERE alvo_tipo = 'feed_post' AND alvo_id = ?")->execute([$id]); } catch (Throwable $e) {}
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Privacidade do perfil social ──────────────────────────────────────────
// GET ?idatleta=ID  -> o que o espectador pode ver do perfil de ID (o dono vê tudo).
// POST              -> o aluno grava as próprias escolhas (fotos_visib e os três mostrar_*).
if ($action === 'perfil_config') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        $alvo = (int)($_GET['idatleta'] ?? 0);
        if ($alvo <= 0 && $_autorTipo === 'aluno') $alvo = $_autorId;
        $cfg = _perfilConfig($pdo, $alvo);
        $meu = ($_autorTipo === 'aluno' && $alvo === $_autorId);
        $equipe = ($_autorTipo === 'prof');
        $amigo = ($_autorTipo === 'aluno' && !$meu) ? in_array($alvo, _amigosDe($pdo, $_autorId), true) : false;
        $verFotos = $meu || $equipe || $cfg['fotos_visib'] === 'todos' || ($cfg['fotos_visib'] === 'amigos' && $amigo);
        // Para quem olha de fora, só o resultado final: o que está escondido vem como false.
        $out = [
            'meu'                => $meu,
            'fotos_visib'        => $meu ? $cfg['fotos_visib'] : null,
            'ver_fotos'          => true,   // quem decide é cada publicação (feed_posts filtra); o padrão do perfil só vale para as sem ajuste
            'mostrar_conquistas' => ($meu || $equipe || $cfg['mostrar_conquistas']) ? true : false,
            'mostrar_resultados' => ($meu || $equipe || $cfg['mostrar_resultados']) ? true : false,
            'mostrar_semana'     => ($meu || $equipe || $cfg['mostrar_semana']) ? true : false,
            'amigo'              => $amigo,
            'conexoes'           => INTUS_CONEXOES_ATIVO ? true : false,
            'desafios_alunos'    => (INTUS_CONEXOES_ATIVO && INTUS_ALUNO_CRIA_DESAFIO) ? true : false,
        ];
        $out['amizade'] = null; $out['parceria'] = null;
        if (INTUS_CONEXOES_ATIVO && $_autorTipo === 'aluno' && !$meu && $alvo > 0) {
            $out['amizade'] = _estadoPar($pdo, 'intus_amizade', $_autorId, $alvo);
            $out['parceria'] = _estadoPar($pdo, 'intus_parceria', $_autorId, $alvo);
        }
        if ($meu) { $out['cfg'] = $cfg; }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'POST') {
        if ($_autorTipo !== 'aluno') { http_response_code(403); echo json_encode(['error' => 'somente aluno']); exit; }
        $b = jsonBody();
        $fv = (string)($b['fotos_visib'] ?? 'todos');
        if (!in_array($fv, ['todos', 'amigos', 'eu'], true)) $fv = 'todos';
        $mc = !empty($b['mostrar_conquistas']) ? 1 : 0;
        $mr = !empty($b['mostrar_resultados']) ? 1 : 0;
        $ms = !empty($b['mostrar_semana']) ? 1 : 0;
        try {
            $pdo->prepare("INSERT INTO intus_perfil_config (idatleta, fotos_visib, mostrar_conquistas, mostrar_resultados, mostrar_semana) VALUES (?,?,?,?,?)
                           ON DUPLICATE KEY UPDATE fotos_visib = VALUES(fotos_visib), mostrar_conquistas = VALUES(mostrar_conquistas), mostrar_resultados = VALUES(mostrar_resultados), mostrar_semana = VALUES(mostrar_semana)")
                ->execute([$_autorId, $fv, $mc, $mr, $ms]);
            echo json_encode(['ok' => true, 'fotos_visib' => $fv, 'mostrar_conquistas' => (bool)$mc, 'mostrar_resultados' => (bool)$mr, 'mostrar_semana' => (bool)$ms]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao gravar', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }
}

// ── Amizades (DESLIGADO: ver INTUS_CONEXOES_ATIVO) ────────────────────────
// GET: amigos e pedidos; POST {para_id}: pedir; PUT {de_id, aceitar}: aceitar/recusar; DELETE ?id=: desfazer.
if ($action === 'amizades') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if (!INTUS_CONEXOES_ATIVO) { http_response_code(403); echo json_encode(['error' => 'recurso ainda nao liberado']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorTipo !== 'aluno' || $_autorId <= 0) { http_response_code(403); echo json_encode(['error' => 'somente aluno']); exit; }

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT de_id FROM intus_amizade WHERE para_id = ? AND status = 'pendente' ORDER BY idamizade DESC LIMIT 100");
        $st->execute([$_autorId]);
        $rec = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $st = $pdo->prepare("SELECT para_id FROM intus_amizade WHERE de_id = ? AND status = 'pendente' ORDER BY idamizade DESC LIMIT 100");
        $st->execute([$_autorId]);
        $env = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $amigos = _amigosDe($pdo, $_autorId);
        echo json_encode(['amigos' => $amigos, 'recebidos' => $rec, 'enviados' => $env, 'pessoas' => (object)_pessoasMapa($pdo, array_merge($amigos, $rec, $env))], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $b = jsonBody();
    if ($method === 'POST') {
        $para = (int)($b['para_id'] ?? 0);
        if ($para <= 0 || $para === $_autorId) { http_response_code(400); echo json_encode(['error' => 'aluno invalido']); exit; }
        // Se a outra pessoa já tinha pedido, o pedido vira amizade na hora.
        $st = $pdo->prepare("SELECT idamizade FROM intus_amizade WHERE de_id = ? AND para_id = ? AND status = 'pendente'");
        $st->execute([$para, $_autorId]);
        if ($idp = $st->fetchColumn()) {
            $pdo->prepare("UPDATE intus_amizade SET status = 'aceita', aceita_em = NOW() WHERE idamizade = ?")->execute([(int)$idp]);
            try { _criarNotificacao($pdo, 'aluno', $para, 'amizade_aceita', 'aluno', $_autorId, (string)$_autorNome, 'amizade', $_autorId, ''); } catch (Throwable $e) {}
            echo json_encode(['ok' => true, 'status' => 'aceita']); exit;
        }
        // Teto de pedidos em aberto, para não virar spam.
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_amizade WHERE de_id = ? AND status = 'pendente'");
        $st->execute([$_autorId]);
        if ((int)$st->fetchColumn() >= 50) { http_response_code(429); echo json_encode(['error' => 'pedidos demais em aberto']); exit; }
        $pdo->prepare("INSERT IGNORE INTO intus_amizade (de_id, para_id, status) VALUES (?,?, 'pendente')")->execute([$_autorId, $para]);
        try { _criarNotificacao($pdo, 'aluno', $para, 'amizade_pedido', 'aluno', $_autorId, (string)$_autorNome, 'amizade', $_autorId, ''); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'status' => 'pendente']);
        exit;
    }
    if ($method === 'PUT') {
        $de = (int)($b['de_id'] ?? 0);
        if (!empty($b['aceitar'])) {
            $up = $pdo->prepare("UPDATE intus_amizade SET status = 'aceita', aceita_em = NOW() WHERE de_id = ? AND para_id = ? AND status = 'pendente'");
            $up->execute([$de, $_autorId]);
            if ($up->rowCount() > 0) { try { _criarNotificacao($pdo, 'aluno', $de, 'amizade_aceita', 'aluno', $_autorId, (string)$_autorNome, 'amizade', $_autorId, ''); } catch (Throwable $e) {} }
        } else {
            $pdo->prepare("DELETE FROM intus_amizade WHERE de_id = ? AND para_id = ? AND status = 'pendente'")->execute([$de, $_autorId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $outro = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM intus_amizade WHERE (de_id = ? AND para_id = ?) OR (de_id = ? AND para_id = ?)")->execute([$_autorId, $outro, $outro, $_autorId]);
        $pdo->prepare("DELETE FROM intus_parceria WHERE (de_id = ? AND para_id = ?) OR (de_id = ? AND para_id = ?)")->execute([$_autorId, $outro, $outro, $_autorId]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Parcerias de treino (DESLIGADO: ver INTUS_CONEXOES_ATIVO) ─────────────
// Só entre amigos. GET: parceiros e convites; POST {para_id}: convidar; PUT {de_id, aceitar}: aceitar ou recusar;
// DELETE ?id=: desfazer. No máximo 3 parceiros por aluno.
if ($action === 'parcerias') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if (!INTUS_CONEXOES_ATIVO) { http_response_code(403); echo json_encode(['error' => 'recurso ainda nao liberado']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorTipo !== 'aluno' || $_autorId <= 0) { http_response_code(403); echo json_encode(['error' => 'somente aluno']); exit; }
    $LIMITE_PARCEIROS = 3;

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT de_id FROM intus_parceria WHERE para_id = ? AND status = 'pendente' ORDER BY idparceria DESC LIMIT 50");
        $st->execute([$_autorId]);
        $rec = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $st = $pdo->prepare("SELECT para_id FROM intus_parceria WHERE de_id = ? AND status = 'pendente' ORDER BY idparceria DESC LIMIT 50");
        $st->execute([$_autorId]);
        $env = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $par = _parceirosDe($pdo, $_autorId);
        echo json_encode(['parceiros' => $par, 'recebidos' => $rec, 'enviados' => $env, 'limite' => $LIMITE_PARCEIROS, 'pessoas' => (object)_pessoasMapa($pdo, array_merge($par, $rec, $env))], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $b = jsonBody();
    if ($method === 'POST') {
        $para = (int)($b['para_id'] ?? 0);
        if ($para <= 0 || $para === $_autorId) { http_response_code(400); echo json_encode(['error' => 'aluno invalido']); exit; }
        if (!in_array($para, _amigosDe($pdo, $_autorId), true)) { http_response_code(400); echo json_encode(['error' => 'so entre amigos']); exit; }
        if (count(_parceirosDe($pdo, $_autorId)) >= $LIMITE_PARCEIROS || count(_parceirosDe($pdo, $para)) >= $LIMITE_PARCEIROS) {
            http_response_code(409); echo json_encode(['error' => 'limite de parceiros atingido']); exit;
        }
        $estado = _estadoPar($pdo, 'intus_parceria', $_autorId, $para);
        if ($estado === 'aceita' || $estado === 'enviado') { echo json_encode(['ok' => true, 'status' => $estado === 'aceita' ? 'aceita' : 'pendente']); exit; }
        if ($estado === 'recebido') {
            $pdo->prepare("UPDATE intus_parceria SET status = 'aceita', aceita_em = NOW() WHERE de_id = ? AND para_id = ? AND status = 'pendente'")->execute([$para, $_autorId]);
            try { _criarNotificacao($pdo, 'aluno', $para, 'parceria_aceita', 'aluno', $_autorId, (string)$_autorNome, 'parceria', $_autorId, ''); } catch (Throwable $e) {}
            echo json_encode(['ok' => true, 'status' => 'aceita']); exit;
        }
        $pdo->prepare("INSERT IGNORE INTO intus_parceria (de_id, para_id, status) VALUES (?,?, 'pendente')")->execute([$_autorId, $para]);
        try { _criarNotificacao($pdo, 'aluno', $para, 'parceria_pedido', 'aluno', $_autorId, (string)$_autorNome, 'parceria', $_autorId, ''); } catch (Throwable $e) {}
        echo json_encode(['ok' => true, 'status' => 'pendente']);
        exit;
    }
    if ($method === 'PUT') {
        $de = (int)($b['de_id'] ?? 0);
        if (!empty($b['aceitar'])) {
            if (count(_parceirosDe($pdo, $_autorId)) >= $LIMITE_PARCEIROS || count(_parceirosDe($pdo, $de)) >= $LIMITE_PARCEIROS) {
                http_response_code(409); echo json_encode(['error' => 'limite de parceiros atingido']); exit;
            }
            $up = $pdo->prepare("UPDATE intus_parceria SET status = 'aceita', aceita_em = NOW() WHERE de_id = ? AND para_id = ? AND status = 'pendente'");
            $up->execute([$de, $_autorId]);
            if ($up->rowCount() > 0) { try { _criarNotificacao($pdo, 'aluno', $de, 'parceria_aceita', 'aluno', $_autorId, (string)$_autorNome, 'parceria', $_autorId, ''); } catch (Throwable $e) {} }
        } else {
            $pdo->prepare("DELETE FROM intus_parceria WHERE de_id = ? AND para_id = ? AND status = 'pendente'")->execute([$de, $_autorId]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $outro = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM intus_parceria WHERE (de_id = ? AND para_id = ?) OR (de_id = ? AND para_id = ?)")->execute([$_autorId, $outro, $outro, $_autorId]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Turmas de alunos (chave INTUS_CONEXOES_ATIVO) ─────────────────────────
// Grupo com ranking interno, criado por um aluno e aberto por código de convite. Até 30 membros; cada aluno em até 5 turmas.
// GET: minhas turmas. POST {nome, descricao}: criar. POST {entrar: CODIGO}: entrar. PUT {idturma, nome, descricao, novo_convite}: só o dono.
// DELETE ?id=T: dono encerra a turma. DELETE ?id=T&membro=M: dono tira alguém, ou o próprio aluno sai.
if ($action === 'turmas') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if (!INTUS_CONEXOES_ATIVO) { http_response_code(403); echo json_encode(['error' => 'recurso ainda nao liberado']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorTipo !== 'aluno' || $_autorId <= 0) { http_response_code(403); echo json_encode(['error' => 'somente aluno']); exit; }
    $MAX_MEMBROS = 30; $MAX_TURMAS = 5;

    if ($method === 'GET') {
        $ids = _turmasDe($pdo, $_autorId);
        $turmas = []; $todos = [];
        foreach ($ids as $idt) {
            $st = $pdo->prepare("SELECT idturma, nome, descricao, criador_id, convite FROM intus_turma WHERE idturma = ? AND ativo = 1");
            $st->execute([$idt]);
            $t = $st->fetch(PDO::FETCH_ASSOC);
            if (!$t) continue;
            $sm = $pdo->prepare("SELECT idatleta FROM intus_turma_membro WHERE idturma = ? ORDER BY entrou_em, idatleta");
            $sm->execute([$idt]);
            $mem = array_map('intval', $sm->fetchAll(PDO::FETCH_COLUMN));
            $todos = array_merge($todos, $mem);
            $souDono = ((int)$t['criador_id'] === $_autorId);
            $turmas[] = ['idturma' => (int)$t['idturma'], 'nome' => $t['nome'], 'descricao' => (string)($t['descricao'] ?? ''), 'dono' => (int)$t['criador_id'],
                         'sou_dono' => $souDono, 'convite' => $souDono ? $t['convite'] : null, 'membros' => $mem];
        }
        echo json_encode(['turmas' => $turmas, 'limite_membros' => $MAX_MEMBROS, 'limite_turmas' => $MAX_TURMAS, 'pessoas' => (object)_pessoasMapa($pdo, $todos)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $b = jsonBody();
    if ($method === 'POST') {
        if (!empty($b['entrar'])) {
            $cod = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$b['entrar']));
            $st = $pdo->prepare("SELECT idturma, nome, criador_id FROM intus_turma WHERE convite = ? AND ativo = 1");
            $st->execute([$cod]);
            $t = $cod !== '' ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$t) { http_response_code(404); echo json_encode(['error' => 'codigo nao encontrado']); exit; }
            $idt = (int)$t['idturma'];
            if (in_array($idt, _turmasDe($pdo, $_autorId), true)) { echo json_encode(['ok' => true, 'idturma' => $idt, 'ja_membro' => true]); exit; }
            if (count(_turmasDe($pdo, $_autorId)) >= $MAX_TURMAS) { http_response_code(409); echo json_encode(['error' => 'limite de turmas atingido']); exit; }
            $st = $pdo->prepare("SELECT COUNT(*) FROM intus_turma_membro WHERE idturma = ?");
            $st->execute([$idt]);
            if ((int)$st->fetchColumn() >= $MAX_MEMBROS) { http_response_code(409); echo json_encode(['error' => 'turma cheia']); exit; }
            $pdo->prepare("INSERT IGNORE INTO intus_turma_membro (idturma, idatleta, papel) VALUES (?,?, 'membro')")->execute([$idt, $_autorId]);
            try { _criarNotificacao($pdo, 'aluno', (int)$t['criador_id'], 'turma_entrou', 'aluno', $_autorId, (string)$_autorNome, 'turma', $idt, (string)$t['nome']); } catch (Throwable $e) {}
            echo json_encode(['ok' => true, 'idturma' => $idt]);
            exit;
        }
        $nome = trim(mb_substr((string)($b['nome'] ?? ''), 0, 60));
        $desc = trim(mb_substr((string)($b['descricao'] ?? ''), 0, 200));
        if (mb_strlen($nome) < 2) { http_response_code(400); echo json_encode(['error' => 'nome muito curto']); exit; }
        if (count(_turmasDe($pdo, $_autorId)) >= $MAX_TURMAS) { http_response_code(409); echo json_encode(['error' => 'limite de turmas atingido']); exit; }
        try {
            $cod = _novoCodigoTurma($pdo);
            $pdo->prepare("INSERT INTO intus_turma (nome, descricao, criador_tipo, criador_id, convite) VALUES (?,?, 'aluno', ?, ?)")->execute([$nome, $desc !== '' ? $desc : null, $_autorId, $cod]);
            $idt = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO intus_turma_membro (idturma, idatleta, papel) VALUES (?,?, 'dono')")->execute([$idt, $_autorId]);
            echo json_encode(['ok' => true, 'idturma' => $idt, 'convite' => $cod]);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => 'falha ao criar', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }
    if ($method === 'PUT') {
        $idt = (int)($b['idturma'] ?? 0);
        $st = $pdo->prepare("SELECT criador_id FROM intus_turma WHERE idturma = ? AND ativo = 1");
        $st->execute([$idt]);
        $dono = (int)$st->fetchColumn();
        if (!$dono || $dono !== $_autorId) { http_response_code(403); echo json_encode(['error' => 'so o dono altera a turma']); exit; }
        if (isset($b['nome'])) {
            $nome = trim(mb_substr((string)$b['nome'], 0, 60));
            if (mb_strlen($nome) >= 2) $pdo->prepare("UPDATE intus_turma SET nome = ? WHERE idturma = ?")->execute([$nome, $idt]);
        }
        if (isset($b['descricao'])) $pdo->prepare("UPDATE intus_turma SET descricao = ? WHERE idturma = ?")->execute([trim(mb_substr((string)$b['descricao'], 0, 200)), $idt]);
        $novo = null;
        if (!empty($b['novo_convite'])) { $novo = _novoCodigoTurma($pdo); $pdo->prepare("UPDATE intus_turma SET convite = ? WHERE idturma = ?")->execute([$novo, $idt]); }
        echo json_encode(['ok' => true, 'convite' => $novo]);
        exit;
    }
    if ($method === 'DELETE') {
        $idt = (int)($_GET['id'] ?? 0);
        $membro = (int)($_GET['membro'] ?? 0);
        $st = $pdo->prepare("SELECT criador_id FROM intus_turma WHERE idturma = ? AND ativo = 1");
        $st->execute([$idt]);
        $dono = (int)$st->fetchColumn();
        if (!$dono) { http_response_code(404); echo json_encode(['error' => 'turma nao encontrada']); exit; }
        if ($membro <= 0) {
            if ($dono !== $_autorId) { http_response_code(403); echo json_encode(['error' => 'so o dono encerra a turma']); exit; }
            $pdo->prepare("UPDATE intus_turma SET ativo = 0 WHERE idturma = ?")->execute([$idt]);   // a turma sai de cena; nada é apagado
            echo json_encode(['ok' => true]); exit;
        }
        if ($membro !== $_autorId && $dono !== $_autorId) { http_response_code(403); echo json_encode(['error' => 'sem permissao']); exit; }
        $pdo->prepare("DELETE FROM intus_turma_membro WHERE idturma = ? AND idatleta = ?")->execute([$idt, $membro]);
        if ($membro === $dono) {
            // O dono saiu: o membro mais antigo assume. Sem ninguém, a turma é encerrada.
            $st = $pdo->prepare("SELECT idatleta FROM intus_turma_membro WHERE idturma = ? ORDER BY entrou_em, idatleta LIMIT 1");
            $st->execute([$idt]);
            $prox = (int)$st->fetchColumn();
            if ($prox) {
                $pdo->prepare("UPDATE intus_turma SET criador_id = ? WHERE idturma = ?")->execute([$prox, $idt]);
                $pdo->prepare("UPDATE intus_turma_membro SET papel = 'dono' WHERE idturma = ? AND idatleta = ?")->execute([$idt, $prox]);
            } else {
                $pdo->prepare("UPDATE intus_turma SET ativo = 0 WHERE idturma = ?")->execute([$idt]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Desafios entre alunos (chave INTUS_CONEXOES_ATIVO) ────────────────────
// Não confundir com o recurso Desafios do admin (desafios.php). Meta com prazo, numa turma ou entre amigos.
// O progresso é calculado no app a partir das sessões do ranking de cada participante.
// GET: meus desafios (inclusive os da minha turma que ainda não entrei) e os encerrados nos últimos 30 dias.
// POST {titulo, descricao, metrica, meta, inicio, fim, idturma?, convidados[]}: criar. POST {entrar: ID}: entrar (turma).
// DELETE ?id=D&sair=1: sair. DELETE ?id=D: o criador cancela.
if ($action === 'desafios_aluno') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if (!INTUS_CONEXOES_ATIVO) { http_response_code(403); echo json_encode(['error' => 'recurso ainda nao liberado']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorTipo !== 'aluno' || $_autorId <= 0) { http_response_code(403); echo json_encode(['error' => 'somente aluno']); exit; }

    if ($method === 'GET') {
        $minhasTurmas = _turmasDe($pdo, $_autorId);
        $params = [$_autorId];
        $porTurma = '';
        if ($minhasTurmas) { $porTurma = " OR d.idturma IN (" . implode(',', array_fill(0, count($minhasTurmas), '?')) . ")"; $params = array_merge($params, $minhasTurmas); }
        $st = $pdo->prepare("SELECT d.iddesafio, d.idturma, d.titulo, d.descricao, d.metrica, d.meta, d.inicio, d.fim, d.criador_id
                             FROM intus_desafio_aluno d
                             WHERE d.ativo = 1 AND d.fim >= (CURDATE() - INTERVAL 30 DAY)
                               AND (EXISTS (SELECT 1 FROM intus_desafio_aluno_part p WHERE p.iddesafio = d.iddesafio AND p.idatleta = ?)$porTurma)
                             ORDER BY d.fim DESC, d.iddesafio DESC LIMIT 60");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $lista = []; $todos = [];
        $nomesTurma = [];
        foreach ($rows as $r) {
            $sp = $pdo->prepare("SELECT idatleta FROM intus_desafio_aluno_part WHERE iddesafio = ? ORDER BY entrou_em, idatleta");
            $sp->execute([(int)$r['iddesafio']]);
            $part = array_map('intval', $sp->fetchAll(PDO::FETCH_COLUMN));
            $todos = array_merge($todos, $part, [(int)$r['criador_id']]);
            if ($r['idturma'] && !isset($nomesTurma[(int)$r['idturma']])) {
                $sn = $pdo->prepare("SELECT nome FROM intus_turma WHERE idturma = ?"); $sn->execute([(int)$r['idturma']]);
                $nomesTurma[(int)$r['idturma']] = (string)$sn->fetchColumn();
            }
            $lista[] = ['iddesafio' => (int)$r['iddesafio'], 'idturma' => $r['idturma'] ? (int)$r['idturma'] : null,
                        'turma' => $r['idturma'] ? ($nomesTurma[(int)$r['idturma']] ?? '') : null,
                        'titulo' => $r['titulo'], 'descricao' => (string)($r['descricao'] ?? ''), 'metrica' => $r['metrica'], 'meta' => (int)$r['meta'],
                        'inicio' => $r['inicio'], 'fim' => $r['fim'], 'criador' => (int)$r['criador_id'], 'participantes' => $part,
                        'participo' => in_array($_autorId, $part, true)];
        }
        echo json_encode(['desafios' => $lista, 'pessoas' => (object)_pessoasMapa($pdo, $todos)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $b = jsonBody();
    if ($method === 'POST') {
        if (!empty($b['entrar'])) {
            $idd = (int)$b['entrar'];
            $st = $pdo->prepare("SELECT idturma, titulo, fim FROM intus_desafio_aluno WHERE iddesafio = ? AND ativo = 1");
            $st->execute([$idd]);
            $d = $st->fetch(PDO::FETCH_ASSOC);
            if (!$d) { http_response_code(404); echo json_encode(['error' => 'desafio nao encontrado']); exit; }
            if ($d['fim'] < date('Y-m-d')) { http_response_code(409); echo json_encode(['error' => 'desafio encerrado']); exit; }
            if (!$d['idturma'] || !in_array((int)$d['idturma'], _turmasDe($pdo, $_autorId), true)) { http_response_code(403); echo json_encode(['error' => 'so membros da turma entram']); exit; }
            $pdo->prepare("INSERT IGNORE INTO intus_desafio_aluno_part (iddesafio, idatleta) VALUES (?,?)")->execute([$idd, $_autorId]);
            echo json_encode(['ok' => true]); exit;
        }
        if (!INTUS_ALUNO_CRIA_DESAFIO) { http_response_code(403); echo json_encode(['error' => 'criacao de desafio ainda nao liberada para alunos']); exit; }
        $titulo = trim(mb_substr((string)($b['titulo'] ?? ''), 0, 80));
        $desc = trim(mb_substr((string)($b['descricao'] ?? ''), 0, 200));
        $metrica = (string)($b['metrica'] ?? 'treinos');
        if (!in_array($metrica, ['treinos', 'cardios', 'atividades', 'pontos'], true)) $metrica = 'treinos';
        $meta = (int)($b['meta'] ?? 0);
        $maxMeta = $metrica === 'pontos' ? 2000 : 120;
        $inicio = (string)($b['inicio'] ?? date('Y-m-d'));
        $fim = (string)($b['fim'] ?? '');
        $hoje = date('Y-m-d');
        $okData = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false; };
        if (mb_strlen($titulo) < 3) { http_response_code(400); echo json_encode(['error' => 'titulo muito curto']); exit; }
        if ($meta < 1 || $meta > $maxMeta) { http_response_code(400); echo json_encode(['error' => 'meta invalida']); exit; }
        if (!$okData($inicio) || !$okData($fim) || $fim < $inicio || $fim < $hoje) { http_response_code(400); echo json_encode(['error' => 'datas invalidas']); exit; }
        if ((strtotime($fim) - strtotime($inicio)) / 86400 > 120) { http_response_code(400); echo json_encode(['error' => 'prazo maximo de 120 dias']); exit; }
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_desafio_aluno WHERE criador_id = ? AND ativo = 1 AND fim >= ?");
        $st->execute([$_autorId, $hoje]);
        if ((int)$st->fetchColumn() >= 10) { http_response_code(409); echo json_encode(['error' => 'limite de desafios em andamento']); exit; }
        $idturma = (int)($b['idturma'] ?? 0);
        if ($idturma > 0 && !in_array($idturma, _turmasDe($pdo, $_autorId), true)) { http_response_code(403); echo json_encode(['error' => 'voce nao esta nessa turma']); exit; }
        $amigos = _amigosDe($pdo, $_autorId);
        $conv = [];
        foreach ((array)($b['convidados'] ?? []) as $x) { $x = (int)$x; if ($x > 0 && in_array($x, $amigos, true)) $conv[$x] = $x; }
        $conv = array_slice(array_values($conv), 0, 20);
        try {
            $pdo->prepare("INSERT INTO intus_desafio_aluno (idturma, titulo, descricao, metrica, meta, inicio, fim, criador_id) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$idturma > 0 ? $idturma : null, $titulo, $desc !== '' ? $desc : null, $metrica, $meta, $inicio, $fim, $_autorId]);
            $idd = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT IGNORE INTO intus_desafio_aluno_part (iddesafio, idatleta) VALUES (?,?)")->execute([$idd, $_autorId]);
            foreach ($conv as $amigo) {
                $pdo->prepare("INSERT IGNORE INTO intus_desafio_aluno_part (iddesafio, idatleta) VALUES (?,?)")->execute([$idd, $amigo]);
                try { _criarNotificacao($pdo, 'aluno', $amigo, 'desafio_convite', 'aluno', $_autorId, (string)$_autorNome, 'desafio', $idd, $titulo); } catch (Throwable $e) {}
            }
            echo json_encode(['ok' => true, 'iddesafio' => $idd]);
        } catch (Throwable $e) {
            http_response_code(500); echo json_encode(['error' => 'falha ao criar', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }
    if ($method === 'DELETE') {
        $idd = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare("SELECT criador_id FROM intus_desafio_aluno WHERE iddesafio = ? AND ativo = 1");
        $st->execute([$idd]);
        $criador = (int)$st->fetchColumn();
        if (!$criador) { http_response_code(404); echo json_encode(['error' => 'desafio nao encontrado']); exit; }
        if (!empty($_GET['sair'])) {
            $pdo->prepare("DELETE FROM intus_desafio_aluno_part WHERE iddesafio = ? AND idatleta = ?")->execute([$idd, $_autorId]);
            echo json_encode(['ok' => true]); exit;
        }
        if ($criador !== $_autorId) { http_response_code(403); echo json_encode(['error' => 'so quem criou cancela']); exit; }
        $pdo->prepare("UPDATE intus_desafio_aluno SET ativo = 0 WHERE iddesafio = ?")->execute([$idd]);   // sai de cena; nada é apagado
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ── Armazenamento do Google Drive: relatório, projeção e alertas (só administrador) ──────────────────────────────
// Orçamento total de 1 TB. O relatório mede a pasta REAL do Drive (soma dos arquivos que a conta de serviço enxerga) e
// separa por tipo pelo nome do arquivo; se o Drive não responder, cai para os números do banco. Guarda uma medida por
// dia (intus_armazenamento_hist) para calcular o ritmo de crescimento e estimar quando o limite seria atingido.
// Só lê. Nunca apaga nada do Drive (apagar é sempre pelos botões do painel, com confirmação).
define('ARMAZ_LIMITE_BYTES', 1099511627776);    // 1 TB
define('ARMAZ_ATENCAO_PCT', 60);
define('ARMAZ_ALERTA_PCT', 80);
define('ARMAZ_CRITICO_PCT', 90);

function _armazGarantirTabela(PDO $pdo): void {
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_armazenamento_hist (
        dia DATE NOT NULL PRIMARY KEY,
        total_bytes BIGINT NOT NULL DEFAULT 0,
        videos_posts BIGINT NOT NULL DEFAULT 0,
        videos_feedback BIGINT NOT NULL DEFAULT 0,
        fotos BIGINT NOT NULL DEFAULT 0,
        backups BIGINT NOT NULL DEFAULT 0,
        origem VARCHAR(10) NOT NULL DEFAULT 'drive',
        nivel VARCHAR(10) NOT NULL DEFAULT 'ok',
        dias_limite INT NULL,
        medido_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

// Soma os arquivos da pasta do Drive por tipo. Devolve null se o Drive não responder.
function _armazEscanearDrive(): ?array {
    try {
        if (!function_exists('_gdriveKey') || !function_exists('_gdriveToken')) return null;
        $key = _gdriveKey();
        if (!is_array($key) || empty($key['folder_id'])) return null;
        $token = _gdriveToken($key);
        if (!$token) return null;
        $r = ['total' => 0, 'arquivos' => 0, 'videos_posts' => 0, 'videos_feedback' => 0, 'backups' => 0, 'fotos' => 0, 'outros' => 0, 'maior' => 0, 'cobertura' => 'pasta'];
        $q = rawurlencode("'" . $key['folder_id'] . "' in parents and trashed = false");
        $pagina = '';
        for ($i = 0; $i < 30; $i++) {
            $url = 'https://www.googleapis.com/drive/v3/files?q=' . $q . '&pageSize=1000&supportsAllDrives=true&includeItemsFromAllDrives=true'
                 . '&fields=nextPageToken,files(name,size,mimeType)' . ($pagina !== '' ? '&pageToken=' . rawurlencode($pagina) : '');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || !$resp) return $i === 0 ? null : $r;
            $j = json_decode($resp, true);
            foreach (($j['files'] ?? []) as $f) {
                $tam = (int)($f['size'] ?? 0);
                $nome = (string)($f['name'] ?? '');
                $r['arquivos']++; $r['total'] += $tam;
                if ($tam > $r['maior']) $r['maior'] = $tam;
                if (strpos($nome, 'intus-backup-') === 0) $r['backups'] += $tam;
                elseif (strpos($nome, 'feedvideo_') === 0) $r['videos_posts'] += $tam;
                elseif (strpos($nome, 'feedback_') === 0) $r['videos_feedback'] += $tam;
                elseif (preg_match('/\.(jpe?g|png|webp)$/i', $nome)) $r['fotos'] += $tam;
                else $r['outros'] += $tam;
            }
            $pagina = (string)($j['nextPageToken'] ?? '');
            if ($pagina === '') break;
        }
        return $r;
    } catch (Throwable $e) { return null; }
}

// Fotos que ficam no disco da hospedagem (img/feed etc.): total, as com mais de 30 dias e o que entrou nos últimos 28 dias.
function _armazFotosDisco(): array {
    $r = ['n' => 0, 'bytes' => 0, 'antigas_n' => 0, 'antigas_bytes' => 0, 'ultimos28d_bytes' => 0];
    try {
        $base = __DIR__ . '/../img';
        if (!is_dir($base)) return $r;
        $agora = time();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || !preg_match('/\.(jpe?g|png|webp)$/i', $f->getFilename())) continue;
            $t = $f->getSize(); $m = $f->getMTime();
            $r['n']++; $r['bytes'] += $t;
            if ($m < $agora - 30 * 86400) { $r['antigas_n']++; $r['antigas_bytes'] += $t; }
            if ($m >= $agora - 28 * 86400) $r['ultimos28d_bytes'] += $t;
        }
    } catch (Throwable $e) {}
    return $r;
}

// Calcula o quadro completo e grava a medida do dia. $forcar = true ignora a medida de hoje já guardada.
function _armazCalcular(PDO $pdo, bool $forcar = true): array {
    _armazGarantirTabela($pdo);
    $um = function ($sql) use ($pdo) { try { return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; } };
    $varios = function ($sql) use ($pdo) { try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) { return []; } };

    $posts = $um("SELECT COUNT(*) AS n, COALESCE(SUM(tamanho),0) AS bytes, COALESCE(AVG(tamanho),0) AS media, COALESCE(MAX(tamanho),0) AS maior
                  FROM intus_midia WHERE tipo = 'video' AND status = 'pronto' AND drive_id IS NOT NULL");
    $fb = $um("SELECT COUNT(*) AS n, COALESCE(SUM(tamanho_bytes),0) AS bytes, COALESCE(AVG(tamanho_bytes),0) AS media, COALESCE(MAX(tamanho_bytes),0) AS maior
               FROM intus_feedback WHERE status = 'pronto' AND drive_file_id IS NOT NULL");
    $fbVistos = $um("SELECT COUNT(*) AS n, COALESCE(SUM(tamanho_bytes),0) AS bytes FROM intus_feedback
                     WHERE status = 'pronto' AND drive_file_id IS NOT NULL AND visto_em IS NOT NULL AND visto_em < (NOW() - INTERVAL 30 DAY)");
    $fbSemVer = $um("SELECT COUNT(*) AS n, COALESCE(SUM(tamanho_bytes),0) AS bytes FROM intus_feedback
                     WHERE status = 'pronto' AND drive_file_id IS NOT NULL AND visto_em IS NULL AND criado_em < (NOW() - INTERVAL 30 DAY)");
    $abPosts = $um("SELECT COUNT(*) AS n FROM intus_midia WHERE tipo = 'video' AND status = 'enviando' AND created_at < (NOW() - INTERVAL 1 DAY)");
    $abFb = $um("SELECT COUNT(*) AS n FROM intus_feedback WHERE status = 'enviando' AND criado_em < (NOW() - INTERVAL 1 DAY)");
    $porMes = $varios("SELECT DATE_FORMAT(created_at, '%Y-%m') AS mes, COUNT(*) AS n, COALESCE(SUM(tamanho),0) AS bytes FROM intus_midia
                       WHERE tipo = 'video' AND status = 'pronto' AND drive_id IS NOT NULL GROUP BY mes ORDER BY mes DESC LIMIT 6");
    $maioresFb = $varios("SELECT id, titulo, aluno_nome, tamanho_bytes, duracao_seg, criado_em, visto_em FROM intus_feedback
                          WHERE status = 'pronto' AND drive_file_id IS NOT NULL ORDER BY tamanho_bytes DESC LIMIT 8");
    $novos28 = $um("SELECT
        (SELECT COALESCE(SUM(tamanho),0) FROM intus_midia WHERE tipo = 'video' AND status = 'pronto' AND drive_id IS NOT NULL AND created_at >= (NOW() - INTERVAL 28 DAY)) AS vp,
        (SELECT COALESCE(SUM(tamanho_bytes),0) FROM intus_feedback WHERE status = 'pronto' AND drive_file_id IS NOT NULL AND criado_em >= (NOW() - INTERVAL 28 DAY)) AS vf");
    $alunos = $um("SELECT COUNT(DISTINCT idatleta) AS n FROM intus_feed_post WHERE ativo = 1 AND created_at >= (NOW() - INTERVAL 28 DAY)");

    $driveReal = _armazEscanearDrive();
    $fotos = _armazFotosDisco();
    $n = function ($a) { return ['n' => (int)($a['n'] ?? 0), 'bytes' => (int)($a['bytes'] ?? 0)]; };

    $vpBytes = (int)($posts['bytes'] ?? 0); $vfBytes = (int)($fb['bytes'] ?? 0);
    if ($driveReal) {
        $total = $driveReal['total'];
        $parte = ['videos_posts' => $driveReal['videos_posts'], 'videos_feedback' => $driveReal['videos_feedback'], 'fotos' => $driveReal['fotos'], 'backups' => $driveReal['backups'], 'outros' => $driveReal['outros']];
        $origem = 'drive';
    } else {
        $total = $vpBytes + $vfBytes + $fotos['bytes'];   // aproximação: vídeos do banco + fotos (cópias) estimadas pelo disco
        $parte = ['videos_posts' => $vpBytes, 'videos_feedback' => $vfBytes, 'fotos' => $fotos['bytes'], 'backups' => 0, 'outros' => 0];
        $origem = 'banco';
    }

    // Histórico (dias anteriores) + a medida de hoje, que entra no fim desta função.
    $hist = $varios("SELECT dia, total_bytes FROM intus_armazenamento_hist WHERE dia < CURDATE() ORDER BY dia DESC LIMIT 60");
    array_unshift($hist, ['dia' => date('Y-m-d'), 'total_bytes' => $total]);

    // Ritmo de crescimento por dia: pelo histórico (quando já cobre 7+ dias do mesmo tipo de medida); senão pelo que entrou
    // nos últimos 28 dias (vídeos pelo banco + fotos pelo disco).
    $ritmo = null; $ritmoDe = 'entradas';
    if (count($hist) >= 2) {
        $ult = $hist[0]; $ant = end($hist);
        $dias = (strtotime($ult['dia']) - strtotime($ant['dia'])) / 86400;
        if ($dias >= 7) { $ritmo = max(0, ((int)$ult['total_bytes'] - (int)$ant['total_bytes']) / $dias); $ritmoDe = 'historico'; }
    }
    if ($ritmo === null) $ritmo = (((int)($novos28['vp'] ?? 0) + (int)($novos28['vf'] ?? 0) + $fotos['ultimos28d_bytes'])) / 28;

    $livre = max(0, ARMAZ_LIMITE_BYTES - $total);
    $cenarios = [];
    foreach ([['x1', 'Ritmo de hoje', 1], ['x5', '5 vezes mais uso', 5], ['x20', '20 vezes mais uso', 20]] as $c) {
        $r = $ritmo * $c[2];
        $cenarios[] = ['id' => $c[0], 'nome' => $c[1], 'bytes_dia' => (int)$r, 'dias' => $r > 0 ? (int)min(36500, floor($livre / $r)) : null];
    }
    $pct = $total > 0 ? round($total * 100 / ARMAZ_LIMITE_BYTES, 2) : 0;
    $nivel = $pct >= ARMAZ_CRITICO_PCT ? 'critico' : ($pct >= ARMAZ_ALERTA_PCT ? 'alerta' : ($pct >= ARMAZ_ATENCAO_PCT ? 'atencao' : 'ok'));
    $diasRitmo = $cenarios[0]['dias'];
    // Alerta também pelo ritmo: se no ritmo de hoje o limite chega em menos de 1 ano, avisa mesmo com pouco espaço usado.
    if ($nivel === 'ok' && $diasRitmo !== null && $diasRitmo < 365) $nivel = 'atencao';
    if (in_array($nivel, ['ok', 'atencao'], true) && $diasRitmo !== null && $diasRitmo < 90) $nivel = 'alerta';

    try {
        $pdo->prepare("INSERT INTO intus_armazenamento_hist (dia, total_bytes, videos_posts, videos_feedback, fotos, backups, origem, nivel, dias_limite) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE total_bytes = VALUES(total_bytes), videos_posts = VALUES(videos_posts), videos_feedback = VALUES(videos_feedback),
                       fotos = VALUES(fotos), backups = VALUES(backups), origem = VALUES(origem), nivel = VALUES(nivel), dias_limite = VALUES(dias_limite), medido_em = NOW()")
            ->execute([$total, $parte['videos_posts'], $parte['videos_feedback'], $parte['fotos'], $parte['backups'], $origem, $nivel, $diasRitmo]);
    } catch (Throwable $e) {}

    $ffmpeg = false;
    try {
        $dis = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (function_exists('shell_exec') && !in_array('shell_exec', $dis, true)) $ffmpeg = stripos((string)@shell_exec('ffmpeg -version 2>&1'), 'ffmpeg version') !== false;
    } catch (Throwable $e) {}

    return [
        'limite_bytes' => ARMAZ_LIMITE_BYTES, 'total_bytes' => $total, 'pct' => $pct, 'nivel' => $nivel, 'origem' => $origem,
        'faixas' => ['atencao' => ARMAZ_ATENCAO_PCT, 'alerta' => ARMAZ_ALERTA_PCT, 'critico' => ARMAZ_CRITICO_PCT],
        'partes' => $parte, 'arquivos_drive' => $driveReal ? $driveReal['arquivos'] : null,
        'ritmo_bytes_dia' => (int)$ritmo, 'ritmo_de' => $ritmoDe, 'cenarios' => $cenarios,
        'alunos_ativos_28d' => (int)($alunos['n'] ?? 0),
        'posts' => ['n' => (int)($posts['n'] ?? 0), 'bytes' => $vpBytes, 'media' => (int)($posts['media'] ?? 0), 'maior' => (int)($posts['maior'] ?? 0)],
        'feedbacks' => ['n' => (int)($fb['n'] ?? 0), 'bytes' => $vfBytes, 'media' => (int)($fb['media'] ?? 0), 'maior' => (int)($fb['maior'] ?? 0)],
        'feedbacks_vistos_30d' => $n($fbVistos), 'feedbacks_sem_ver_30d' => $n($fbSemVer),
        'registros_pendentes' => ['posts' => (int)($abPosts['n'] ?? 0), 'feedbacks' => (int)($abFb['n'] ?? 0)],
        'fotos_disco' => $fotos,
        'recursos' => ['gd' => function_exists('imagecreatefromstring'), 'ffmpeg' => $ffmpeg],
        'posts_por_mes' => array_map(function ($r) { return ['mes' => $r['mes'], 'n' => (int)$r['n'], 'bytes' => (int)$r['bytes']]; }, $porMes),
        'maiores_feedbacks' => array_map(function ($r) { return ['id' => (int)$r['id'], 'titulo' => $r['titulo'], 'aluno' => $r['aluno_nome'], 'bytes' => (int)$r['tamanho_bytes'], 'seg' => (int)$r['duracao_seg'], 'criado_em' => $r['criado_em'], 'visto_em' => $r['visto_em']]; }, $maioresFb),
        'historico' => array_reverse(array_map(function ($r) { return ['dia' => $r['dia'], 'bytes' => (int)$r['total_bytes']]; }, $hist)),
    ];
}

// GET: relatório completo (mede o Drive agora). Só administrador.
if ($action === 'drive_uso' && $method === 'GET') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if (empty($_ctx['admin'])) { http_response_code(403); echo json_encode(['error' => 'somente administrador']); exit; }
    echo json_encode(_armazCalcular($pdo, true), JSON_UNESCAPED_UNICODE);
    exit;
}

// GET: só o nível de alerta, para o aviso no topo do painel. Usa a medida de hoje se já existir; senão mede (uma vez por dia).
if ($action === 'armazenamento_alerta' && $method === 'GET') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if (empty($_ctx['admin'])) { http_response_code(403); echo json_encode(['error' => 'somente administrador']); exit; }
    $resumo = null;
    try {
        _armazGarantirTabela($pdo);
        $h = $pdo->query("SELECT total_bytes, nivel, dias_limite FROM intus_armazenamento_hist WHERE dia = CURDATE()")->fetch(PDO::FETCH_ASSOC);
        if ($h) {
            $total = (int)$h['total_bytes'];
            $resumo = ['total_bytes' => $total, 'limite_bytes' => ARMAZ_LIMITE_BYTES, 'pct' => round($total * 100 / ARMAZ_LIMITE_BYTES, 2), 'nivel' => $h['nivel'], 'dias_ate_limite' => $h['dias_limite'] === null ? null : (int)$h['dias_limite']];
        }
    } catch (Throwable $e) {}
    if ($resumo === null) {
        // Primeira abertura do dia por um administrador: mede e guarda (as seguintes só leem).
        $q = _armazCalcular($pdo, true);
        $resumo = ['total_bytes' => $q['total_bytes'], 'limite_bytes' => $q['limite_bytes'], 'pct' => $q['pct'], 'nivel' => $q['nivel'], 'dias_ate_limite' => $q['cenarios'][0]['dias']];
    }
    echo json_encode($resumo, JSON_UNESCAPED_UNICODE);
    exit;
}

// POST: limpa só o REGISTRO de envios de vídeo que não terminaram há mais de 1 dia (nunca chegaram a virar arquivo no Drive;
// o Google descarta sessão de envio incompleta sozinho). Não toca em vídeo concluído. Só administrador, pelo botão do painel.
if ($action === 'drive_limpar_envios' && $method === 'POST') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if (empty($_ctx['admin'])) { http_response_code(403); echo json_encode(['error' => 'somente administrador']); exit; }
    $a = 0; $b = 0;
    try {
        $s1 = $pdo->prepare("DELETE FROM intus_midia WHERE tipo = 'video' AND status = 'enviando' AND drive_id IS NULL AND created_at < (NOW() - INTERVAL 1 DAY)");
        $s1->execute(); $a = $s1->rowCount();
    } catch (Throwable $e) {}
    try {
        $s2 = $pdo->prepare("DELETE FROM intus_feedback WHERE status = 'enviando' AND drive_file_id IS NULL AND criado_em < (NOW() - INTERVAL 1 DAY)");
        $s2->execute(); $b = $s2->rowCount();
    } catch (Throwable $e) {}
    echo json_encode(['ok' => true, 'posts' => $a, 'feedbacks' => $b]);
    exit;
}

// ── Baixar a foto de um post ──────────────────────────────────────────────
// GET ?idpost=ID&url=URL_DA_FOTO. Só o dono do post. O app nativo não consegue buscar a imagem direto da pasta pública
// (sem CORS), então ela passa por aqui. A foto já sai com a logo da Intus, que é aplicada ao publicar. Vídeo não passa
// por aqui: o app busca em midia.php, que já tem CORS.
if ($action === 'feed_baixar' && $method === 'GET') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, ] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    $idp = (int)($_GET['idpost'] ?? 0);
    $urlF = (string)($_GET['url'] ?? '');
    $st = $pdo->prepare("SELECT idatleta, imagem FROM intus_feed_post WHERE idpost = ? AND ativo > 0");
    $st->execute([$idp]);
    $po = $st->fetch(PDO::FETCH_ASSOC);
    if (!$po || $_autorTipo !== 'aluno' || (int)$po['idatleta'] !== $_autorId) { http_response_code(403); echo json_encode(['error' => 'so o dono baixa o proprio post']); exit; }
    // A foto pedida precisa ser mesmo deste post (a capa ou uma das mídias dele).
    $ok = ($urlF !== '' && $urlF === (string)$po['imagem']);
    if (!$ok) {
        $sm = $pdo->prepare("SELECT 1 FROM intus_midia WHERE dono_tipo = 'feed_post' AND dono_id = ? AND url = ? LIMIT 1");
        $sm->execute([$idp, $urlF]);
        $ok = (bool)$sm->fetchColumn();
    }
    $nome = basename((string)parse_url($urlF, PHP_URL_PATH));
    $arq = __DIR__ . '/../img/feed/' . $nome;
    if (!$ok || strpos($urlF, '/img/feed/') === false || !preg_match('/^[A-Za-z0-9_.-]+\.(jpg|jpeg|png|webp)$/i', $nome) || !is_file($arq)) {
        http_response_code(404); echo json_encode(['error' => 'foto nao encontrada']); exit;
    }
    $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext];
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="intus-post-' . $idp . '.' . ($ext === 'jpeg' ? 'jpg' : $ext) . '"');
    header('Content-Length: ' . filesize($arq));
    readfile($arq);
    exit;
}

// ── Carrossel: foto extra de um post (uma por requisição) e finalização ────
// O dono envia o post com a capa (feed_posts POST, total_midias > 1), manda cada
// foto extra aqui e por fim chama com finalizar=1, que torna o post visível.
if ($action === 'feed_midia' && $method === 'POST') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }
    $b = jsonBody();
    $idpost = (int)($b['idpost'] ?? 0);
    $st = $pdo->prepare("SELECT idatleta, ativo, legenda, destino FROM intus_feed_post WHERE idpost = ? LIMIT 1");
    $st->execute([$idpost]);
    $post = $st->fetch(PDO::FETCH_ASSOC);
    if (!$post || (int)$post['idatleta'] !== $_autorId || (int)$post['ativo'] === 0) { http_response_code(403); echo json_encode(['error' => 'post invalido']); exit; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM intus_midia WHERE dono_tipo = 'feed_post' AND dono_id = ?");
    $st->execute([$idpost]);
    $qtd = (int)$st->fetchColumn();

    if (!empty($b['finalizar'])) {
        // Só publica quando tudo que o aparelho disse que ia mandar já está pronto.
        $esperadas = max(0, min(10, (int)($b['esperadas'] ?? 0)));
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_midia WHERE dono_tipo = 'feed_post' AND dono_id = ? AND status = 'pronto'");
        $st->execute([$idpost]);
        $prontas = (int)$st->fetchColumn();
        if ($prontas < $esperadas) { http_response_code(409); echo json_encode(['error' => 'midias incompletas', 'prontas' => $prontas]); exit; }
        if ((int)$post['ativo'] === 2) {
            $pdo->prepare("UPDATE intus_feed_post SET ativo = 1 WHERE idpost = ? AND ativo = 2")->execute([$idpost]);
            $leg = (string)($post['legenda'] ?? '');
            if ($leg !== '' && $post['destino'] === 'feed' && _visibPost($pdo, $idpost) === 'todos') _notificarMencoes($pdo, $leg, $_autorTipo, $_autorId, (string)$_autorNome, 'feed_post', $idpost);
        }
        echo json_encode(['ok' => true, 'idpost' => $idpost, 'midias' => $qtd]);
        exit;
    }

    if ($qtd >= 10) { http_response_code(400); echo json_encode(['error' => 'limite de 10 midias por post']); exit; }
    try { $url = _salvarImagemFeedB64((string)($b['imagem'] ?? ''), $_autorId); }
    catch (Exception $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); exit; }
    try {
        $pdo->prepare("INSERT INTO intus_midia (dono_tipo, dono_id, ordem, tipo, url) VALUES ('feed_post', ?, ?, 'imagem', ?)")->execute([$idpost, $qtd, $url]);
        echo json_encode(['ok' => true, 'idpost' => $idpost, 'ordem' => $qtd, 'url' => $url]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'falha ao gravar a midia', 'detalhe' => _intusLogErro($e)]);
    }
    exit;
}

// ── Quadro de Resultados ───────────────────────────────────────────────────
// GET  ?action=resultados[&idatleta=N][&tipo=depoimento|evolucao][&antes_de=ID][&limite=N]
// POST ?action=resultados {tipo, texto, periodo_txt, aceite, autoriza_site, total_midias}  (só aluno)
//        com mídia nasce invisível (ativo=2) e vira visível em resultado_midia {finalizar}
// DELETE ?action=resultados&id=N  (dono ou professor)
if ($action === 'resultados') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        $limite = min(50, max(1, (int)($_GET['limite'] ?? 20)));
        $antesDe = (int)($_GET['antes_de'] ?? 0);
        $idAt = (int)($_GET['idatleta'] ?? 0);
        $tipoF = (string)($_GET['tipo'] ?? '');
        // Mural escondido pelo dono: outro aluno abrindo o perfil dele não recebe a lista (a equipe e o próprio dono sim).
        if ($idAt > 0 && $_autorTipo === 'aluno' && $idAt !== $_autorId && !_perfilConfig($pdo, $idAt)['mostrar_resultados']) {
            echo json_encode(['resultados' => [], 'atletas' => new stdClass(), 'avatars' => new stdClass(), 'temMais' => false, 'restrito' => true]);
            exit;
        }
        $tbl = _feedDetectarTabelaAtleta($pdo);
        $onde = "r.ativo = 1"; $args = [];
        if ($idAt > 0) { $onde .= " AND r.idatleta = ?"; $args[] = $idAt; }
        if (in_array($tipoF, ['depoimento', 'evolucao', 'print', 'video'], true)) { $onde .= " AND r.tipo = ?"; $args[] = $tipoF; }
        if ($antesDe > 0) { $onde .= " AND r.idresultado < ?"; $args[] = $antesDe; }
        $colunas = "r.idresultado, r.idatleta, r.tipo, r.texto, r.periodo_txt, r.autoriza_site, r.destaque_site, r.created_at, r.origem, r.autor_nome, r.link_tipo, r.link_id, r.autorizacao_obs";
        $sql = "SELECT $colunas FROM intus_resultado r WHERE %s ORDER BY r.idresultado DESC LIMIT " . ($limite + 1);
        $rows = null;
        if ($tbl) {
            // Aluno bloqueado/inativo não aparece no quadro dos outros.
            try {
                $st = $pdo->prepare(sprintf($sql, $onde . " AND r.idatleta NOT IN (SELECT idatleta FROM `$tbl` WHERE stbloqueio = 'S')"));
                $st->execute($args);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $rows = null; }
        }
        if ($rows === null) {
            $st = $pdo->prepare(sprintf($sql, $onde));
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        $temMais = count($rows) > $limite;
        if ($temMais) $rows = array_slice($rows, 0, $limite);
        $idsR = array_map(function ($r) { return (int)$r['idresultado']; }, $rows);
        $midiasMap = _midiasDosPosts($pdo, 'resultado', $idsR);
        $autores = array_values(array_filter(array_unique(array_map(function ($r) { return (int)$r['idatleta']; }, $rows))));
        $nomes = []; $avatars = [];
        if ($autores && $tbl) {
            $phA = implode(',', array_fill(0, count($autores), '?'));
            try {
                $stN = $pdo->prepare("SELECT idatleta, nome FROM `$tbl` WHERE idatleta IN ($phA)");
                $stN->execute($autores);
                foreach ($stN->fetchAll(PDO::FETCH_ASSOC) as $n) $nomes[(int)$n['idatleta']] = $n['nome'];
            } catch (Throwable $e) {}
            try {
                $stV = $pdo->prepare("SELECT idatleta, avatar FROM intus_profile WHERE idatleta IN ($phA) AND avatar IS NOT NULL AND avatar != ''");
                $stV->execute($autores);
                foreach ($stV->fetchAll(PDO::FETCH_ASSOC) as $v) $avatars[(int)$v['idatleta']] = $v['avatar'];
            } catch (Throwable $e) {}
        }
        $lista = [];
        foreach ($rows as $r) {
            $it = [
                'idresultado' => (int)$r['idresultado'], 'idatleta' => (int)$r['idatleta'], 'tipo' => $r['tipo'],
                'texto' => (string)($r['texto'] ?? ''), 'periodo_txt' => (string)($r['periodo_txt'] ?? ''),
                'created_at' => $r['created_at'], 'midias' => $midiasMap[(int)$r['idresultado']] ?? [],
                'origem' => $r['origem'] ?: 'aluno', 'autor_nome' => (string)($r['autor_nome'] ?? ''),
                'link' => ($r['link_tipo'] && $r['link_id']) ? ['tipo' => $r['link_tipo'], 'id' => $r['link_id']] : null,
            ];
            // Consentimento de uso no site só interessa à equipe.
            if (!$_ehAluno) { $it['autoriza_site'] = (int)$r['autoriza_site']; $it['destaque_site'] = (int)$r['destaque_site']; $it['autorizacao_obs'] = (string)($r['autorizacao_obs'] ?? ''); }
            $lista[] = $it;
        }
        echo json_encode(['resultados' => $lista, 'atletas' => (object)$nomes, 'avatars' => (object)$avatars, 'temMais' => $temMais], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST' && !$_ehAluno) {
        // Cadastro pela equipe (só administrador): conteúdo externo ou de ex-aluno que autorizou.
        if (empty($_ctx['admin'])) { http_response_code(403); echo json_encode(['error' => 'somente administrador cadastra resultado externo']); exit; }
        $b = jsonBody();
        if (empty($b['autorizou'])) { http_response_code(400); echo json_encode(['error' => 'confirme que a pessoa autorizou a divulgacao']); exit; }
        $tipoR = in_array((string)($b['tipo'] ?? ''), ['depoimento', 'evolucao', 'print', 'video'], true) ? (string)$b['tipo'] : 'depoimento';
        $texto = mb_substr(trim((string)($b['texto'] ?? '')), 0, 1000);
        $periodo = mb_substr(trim((string)($b['periodo_txt'] ?? '')), 0, 60);
        $nomeExib = mb_substr(trim((string)($b['autor_nome'] ?? '')), 0, 120);
        $obs = mb_substr(trim((string)($b['autorizacao_obs'] ?? '')), 0, 255);
        $linkRaw = trim((string)($b['link'] ?? ''));
        $link = _resultadoParseLink($linkRaw);
        if ($linkRaw !== '' && !$link) { http_response_code(400); echo json_encode(['error' => 'link invalido: use um video do YouTube ou um post/reel do Instagram']); exit; }
        $totalMidias = max(0, min(10, (int)($b['total_midias'] ?? 0)));
        if ($texto === '' && !$link && $totalMidias === 0) { http_response_code(400); echo json_encode(['error' => 'informe um texto, um link ou imagens']); exit; }
        $ativoNovo = $totalMidias > 0 ? 2 : 1;
        try { $pdo->exec("UPDATE intus_resultado SET ativo = 0 WHERE ativo = 2 AND created_at < (NOW() - INTERVAL 1 DAY)"); } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("INSERT INTO intus_resultado (idatleta, tipo, texto, periodo_txt, autoriza_app, autoriza_site, ativo, origem, autor_nome, link_tipo, link_id, autorizacao_obs) VALUES (0,?,?,?,1,?,?,'admin',?,?,?,?)");
            $st->execute([$tipoR, $texto !== '' ? $texto : null, $periodo !== '' ? $periodo : null, !empty($b['autoriza_site']) ? 1 : 0, $ativoNovo,
                $nomeExib !== '' ? $nomeExib : null, $link ? $link[0] : null, $link ? $link[1] : null, $obs !== '' ? $obs : null]);
            echo json_encode(['ok' => true, 'idresultado' => (int)$pdo->lastInsertId(), 'pendente' => $ativoNovo === 2]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao gravar o resultado', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }

    if ($method === 'POST') {
        if (!$_ehAluno) { http_response_code(403); echo json_encode(['error' => 'somente aluno publica resultado']); exit; }
        $b = jsonBody();
        if (empty($b['aceite'])) { http_response_code(400); echo json_encode(['error' => 'aceite obrigatorio']); exit; }
        $tipoR = in_array((string)($b['tipo'] ?? ''), ['depoimento', 'evolucao'], true) ? (string)$b['tipo'] : 'depoimento';
        $texto = mb_substr(trim((string)($b['texto'] ?? '')), 0, 1000);
        $periodo = mb_substr(trim((string)($b['periodo_txt'] ?? '')), 0, 60);
        $totalMidias = max(0, min(10, (int)($b['total_midias'] ?? 0)));
        if ($texto === '' && $totalMidias === 0) { http_response_code(400); echo json_encode(['error' => 'escreva um texto ou envie foto/video']); exit; }
        $autorizaSite = !empty($b['autoriza_site']) ? 1 : 0;
        $ativoNovo = $totalMidias > 0 ? 2 : 1;
        try { $pdo->exec("UPDATE intus_resultado SET ativo = 0 WHERE ativo = 2 AND created_at < (NOW() - INTERVAL 1 DAY)"); } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare("INSERT INTO intus_resultado (idatleta, tipo, texto, periodo_txt, autoriza_app, autoriza_site, ativo) VALUES (?,?,?,?,1,?,?)");
            $st->execute([$_autorId, $tipoR, $texto !== '' ? $texto : null, $periodo !== '' ? $periodo : null, $autorizaSite, $ativoNovo]);
            $novo = (int)$pdo->lastInsertId();
            if ($ativoNovo === 1) _resultadoPublicar($pdo, $novo, $_autorId, (string)$_autorNome, $texto);
            echo json_encode(['ok' => true, 'idresultado' => $novo, 'pendente' => $ativoNovo === 2]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao gravar o resultado', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $st = $pdo->prepare("SELECT idatleta FROM intus_resultado WHERE idresultado = ?");
        $st->execute([$id]);
        $linhaR = $st->fetch(PDO::FETCH_ASSOC);
        if (!$linhaR) { http_response_code(404); echo json_encode(['error' => 'nao encontrado']); exit; }
        $dono = (int)$linhaR['idatleta'];   // 0 = cadastrado pela equipe
        if ($_ehAluno && $dono !== $_autorId) { http_response_code(403); echo json_encode(['error' => 'sem permissao']); exit; }
        $pdo->prepare("UPDATE intus_resultado SET ativo = 0, destaque_site = 0 WHERE idresultado = ?")->execute([$id]);
        try {
            $sv = $pdo->prepare("SELECT idmidia, drive_id FROM intus_midia WHERE dono_tipo = 'resultado' AND dono_id = ? AND tipo = 'video' AND drive_id IS NOT NULL");
            $sv->execute([$id]);
            foreach ($sv->fetchAll(PDO::FETCH_ASSOC) as $vv) {
                $ok = false;
                try { if (function_exists('gdriveExcluirArquivo')) $ok = gdriveExcluirArquivo($vv['drive_id']); } catch (Throwable $e) {}
                if ($ok) $pdo->prepare("UPDATE intus_midia SET status = 'apagado', drive_id = NULL WHERE idmidia = ?")->execute([(int)$vv['idmidia']]);
            }
        } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM intus_notificacao WHERE alvo_tipo = 'resultado' AND alvo_id = ?")->execute([$id]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM intus_reacao_pub WHERE alvo_tipo = 'resultado' AND alvo_id = ?")->execute([$id]); } catch (Throwable $e) {}
        echo json_encode(['ok' => true]);
        exit;
    }
}

// Foto de um resultado (uma por requisição) e finalização — mesmo desenho do feed_midia.
if ($action === 'resultado_midia' && $method === 'POST') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade']); exit; }
    $b = jsonBody();
    $idr = (int)($b['idresultado'] ?? 0);
    $st = $pdo->prepare("SELECT idatleta, ativo, texto FROM intus_resultado WHERE idresultado = ? LIMIT 1");
    $st->execute([$idr]);
    $res = $st->fetch(PDO::FETCH_ASSOC);
    // Dono (aluno) do resultado, ou administrador num resultado cadastrado pela equipe (idatleta 0).
    $podeMexer = $res && (($_ehAluno && (int)$res['idatleta'] === $_autorId) || (!$_ehAluno && !empty($_ctx['admin']) && (int)$res['idatleta'] === 0));
    if (!$podeMexer || (int)$res['ativo'] === 0) { http_response_code(403); echo json_encode(['error' => 'resultado invalido']); exit; }
    $ehEquipe = !$_ehAluno;
    $st = $pdo->prepare("SELECT COUNT(*) FROM intus_midia WHERE dono_tipo = 'resultado' AND dono_id = ?");
    $st->execute([$idr]);
    $qtd = (int)$st->fetchColumn();

    if (!empty($b['finalizar'])) {
        $esperadas = max(0, min(10, (int)($b['esperadas'] ?? 0)));
        $st = $pdo->prepare("SELECT COUNT(*) FROM intus_midia WHERE dono_tipo = 'resultado' AND dono_id = ? AND status = 'pronto'");
        $st->execute([$idr]);
        $prontas = (int)$st->fetchColumn();
        if ($prontas < $esperadas) { http_response_code(409); echo json_encode(['error' => 'midias incompletas', 'prontas' => $prontas]); exit; }
        if ((int)$res['ativo'] === 2) {
            $pdo->prepare("UPDATE intus_resultado SET ativo = 1 WHERE idresultado = ? AND ativo = 2")->execute([$idr]);
            if (!$ehEquipe) _resultadoPublicar($pdo, $idr, $_autorId, (string)$_autorNome, (string)($res['texto'] ?? ''));   // equipe não avisa a si mesma
        }
        echo json_encode(['ok' => true, 'idresultado' => $idr, 'midias' => $qtd]);
        exit;
    }

    if ($qtd >= 10) { http_response_code(400); echo json_encode(['error' => 'limite de 10 midias']); exit; }
    try { $url = _salvarImagemFeedB64((string)($b['imagem'] ?? ''), $ehEquipe ? 0 : $_autorId); }
    catch (Exception $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); exit; }
    try {
        $pdo->prepare("INSERT INTO intus_midia (dono_tipo, dono_id, ordem, tipo, url) VALUES ('resultado', ?, ?, 'imagem', ?)")->execute([$idr, $qtd, $url]);
        echo json_encode(['ok' => true, 'idresultado' => $idr, 'ordem' => $qtd, 'url' => $url]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'falha ao gravar a midia', 'detalhe' => _intusLogErro($e)]);
    }
    exit;
}

// Professor marca um resultado como "destaque para o site" (só grava a marca;
// nada é publicado). Só vale se o aluno autorizou o uso no site.
if ($action === 'resultado_destaque' && $method === 'POST') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno) { http_response_code(403); echo json_encode(['error' => 'sem permissao']); exit; }
    $b = jsonBody();
    $idr = (int)($b['idresultado'] ?? 0);
    $valor = !empty($b['destaque_site']) ? 1 : 0;
    $st = $pdo->prepare("SELECT autoriza_site FROM intus_resultado WHERE idresultado = ? AND ativo = 1");
    $st->execute([$idr]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { http_response_code(404); echo json_encode(['error' => 'nao encontrado']); exit; }
    if ($valor && !(int)$r['autoriza_site']) { http_response_code(400); echo json_encode(['error' => 'o aluno nao autorizou o uso no site']); exit; }
    $pdo->prepare("UPDATE intus_resultado SET destaque_site = ? WHERE idresultado = ?")->execute([$valor, $idr]);
    echo json_encode(['ok' => true, 'destaque_site' => $valor]);
    exit;
}

// ═══════════════ NUTRIÇÃO: registro do aluno, água e painel da nutri ═══════════════
function _nutriData($d): ?string {
    $d = substr(trim((string)$d), 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    return $d;
}
// Admin vê todos; professor só a própria carteira (mesma regra do resto do painel).
function _nutriPodeVer(PDO $pdo, array $_ctx, int $idatleta): bool {
    if (!empty($_ctx['admin'])) return true;
    try { return in_array($idatleta, getAtletasDoUsuario($pdo, (int)($_ctx['idusuario'] ?? 0)), true); } catch (Throwable $e) { return false; }
}

if ($action === 'nutri_registro') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [$_autorTipo, $_autorId, $_autorNome] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        if ($_ehAluno) $idat = $_autorId;
        else {
            $idat = (int)($_GET['atleta'] ?? 0);
            if ($idat <= 0 || !_nutriPodeVer($pdo, $_ctx, $idat)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
        }
        $desde = _nutriData($_GET['desde'] ?? '') ?: date('Y-m-d', strtotime('-30 days'));
        $st = $pdo->prepare("SELECT idregistro, data, refeicao, status, obs, foto, resposta_nutri, respondido_em FROM intus_nutri_registro WHERE idatleta = ? AND data >= ? ORDER BY data DESC, idregistro DESC LIMIT 600");
        $st->execute([$idat, $desde]);
        $regs = array_map(function ($r) { $r['idregistro'] = (int)$r['idregistro']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
        $sa = $pdo->prepare("SELECT data, agua_ml FROM intus_nutri_dia WHERE idatleta = ? AND data >= ? ORDER BY data DESC LIMIT 120");
        $sa->execute([$idat, $desde]);
        $agua = array_map(function ($r) { $r['agua_ml'] = (int)$r['agua_ml']; return $r; }, $sa->fetchAll(PDO::FETCH_ASSOC));
        echo json_encode(['registros' => $regs, 'agua' => $agua], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        $b = jsonBody();
        // A nutri (professor) responde a um registro; o aluno é avisado no sino.
        if (!$_ehAluno) {
            $subacao = (string)($b['subacao'] ?? '');
            if ($subacao !== 'resposta' && $subacao !== 'remover_foto') { http_response_code(403); echo json_encode(['error' => 'somente o aluno registra refeicoes']); exit; }
            $idr = (int)($b['idregistro'] ?? 0);
            if ($subacao === 'remover_foto') {
                $st = $pdo->prepare("SELECT idatleta FROM intus_nutri_registro WHERE idregistro = ?");
                $st->execute([$idr]);
                $dono = (int)$st->fetchColumn();
                if (!$dono || !_nutriPodeVer($pdo, $_ctx, $dono)) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
                $pdo->prepare("UPDATE intus_nutri_registro SET foto = NULL WHERE idregistro = ?")->execute([$idr]);
                echo json_encode(['ok' => true]);
                exit;
            }
            $texto = mb_substr(trim((string)($b['texto'] ?? '')), 0, 300);
            $st = $pdo->prepare("SELECT idatleta, refeicao FROM intus_nutri_registro WHERE idregistro = ?");
            $st->execute([$idr]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !_nutriPodeVer($pdo, $_ctx, (int)$row['idatleta'])) { http_response_code(403); echo json_encode(['error' => 'acesso negado']); exit; }
            $pdo->prepare("UPDATE intus_nutri_registro SET resposta_nutri = ?, respondido_em = " . ($texto !== '' ? 'NOW()' : 'NULL') . " WHERE idregistro = ?")
                ->execute([$texto !== '' ? $texto : null, $idr]);
            if ($texto !== '') _criarNotificacao($pdo, 'aluno', (int)$row['idatleta'], 'nutri_resposta', 'prof', $_autorId, (string)$_autorNome, 'nutri', $idr, $texto);
            echo json_encode(['ok' => true]);
            exit;
        }
        $data = _nutriData($b['data'] ?? '');
        // Hoje ou ontem (com 1 dia de folga para o fuso do aparelho).
        if (!$data || $data < date('Y-m-d', strtotime('-2 days')) || $data > date('Y-m-d', strtotime('+1 day'))) { http_response_code(400); echo json_encode(['error' => 'so da para registrar hoje ou ontem']); exit; }
        $refeicao = mb_substr(trim((string)($b['refeicao'] ?? '')), 0, 80);
        $status = (string)($b['status'] ?? '');
        if ($refeicao === '' || !in_array($status, ['feito', 'parcial', 'fora'], true)) { http_response_code(400); echo json_encode(['error' => 'refeicao e status obrigatorios']); exit; }
        $obs = mb_substr(trim((string)($b['obs'] ?? '')), 0, 300);
        $idplano = isset($b['idplano']) ? (int)$b['idplano'] : null;
        $st = $pdo->prepare("SELECT idregistro, foto FROM intus_nutri_registro WHERE idatleta = ? AND data = ? AND refeicao = ?");
        $st->execute([$_autorId, $data, $refeicao]);
        $exist = $st->fetch(PDO::FETCH_ASSOC);
        $foto = $exist ? $exist['foto'] : null;
        if (!empty($b['foto_remover'])) $foto = null;
        if (!empty($b['foto'])) {
            try { $foto = _salvarImagemFeedB64((string)$b['foto'], $_autorId, 'nutri', 'refeicao'); }
            catch (Exception $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); exit; }
        }
        try {
            if ($exist) {
                $pdo->prepare("UPDATE intus_nutri_registro SET status = ?, obs = ?, foto = ?, idplano = ? WHERE idregistro = ?")
                    ->execute([$status, $obs !== '' ? $obs : null, $foto, $idplano, (int)$exist['idregistro']]);
                $id = (int)$exist['idregistro'];
            } else {
                $pdo->prepare("INSERT INTO intus_nutri_registro (idatleta, idplano, data, refeicao, status, obs, foto) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$_autorId, $idplano, $data, $refeicao, $status, $obs !== '' ? $obs : null, $foto]);
                $id = (int)$pdo->lastInsertId();
            }
            echo json_encode(['ok' => true, 'idregistro' => $id, 'foto' => $foto]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao gravar o registro', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$_ehAluno || $id <= 0) { http_response_code(403); echo json_encode(['error' => 'sem permissao']); exit; }
        $pdo->prepare("DELETE FROM intus_nutri_registro WHERE idregistro = ? AND idatleta = ?")->execute([$id, $_autorId]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// Água do dia (só o aluno, o próprio). GET ?desde=; POST {data, agua_ml}
if ($action === 'nutri_dia') {
    if (!$_tokValido || !$_ehAluno) { http_response_code(401); echo json_encode(['error' => 'somente aluno']); exit; }
    [, $_autorId, ] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }
    if ($method === 'POST') {
        $b = jsonBody();
        $data = _nutriData($b['data'] ?? '');
        if (!$data || $data < date('Y-m-d', strtotime('-2 days')) || $data > date('Y-m-d', strtotime('+1 day'))) { http_response_code(400); echo json_encode(['error' => 'so da para registrar hoje ou ontem']); exit; }
        $ml = max(0, min(10000, (int)($b['agua_ml'] ?? 0)));
        $pdo->prepare("INSERT INTO intus_nutri_dia (idatleta, data, agua_ml) VALUES (?,?,?) ON DUPLICATE KEY UPDATE agua_ml = VALUES(agua_ml)")->execute([$_autorId, $data, $ml]);
        echo json_encode(['ok' => true, 'agua_ml' => $ml]);
        exit;
    }
}

// ═══════════════ NUTRIÇÃO: receitas, vídeos e dicas (conteúdo que a nutri publica) ═══════════════
// GET: o aluno recebe só o que está ativo; a equipe recebe tudo (ou ?ativos=1). POST/PUT/DELETE: só a equipe.
// POST {subacao:'importar'} copia para a tabela as receitas e dicas do arquivo nutri-conteudo.json (sem repetir o que já existe).
function _nutriSlug(string $t): string {
    $t = mb_strtolower(trim($t), 'UTF-8');
    $t = strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                    'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
    $t = trim((string)preg_replace('/[^a-z0-9]+/', '-', $t), '-');
    return substr($t !== '' ? $t : 'item', 0, 60);
}
function _nutriTxt($v, int $max): string { return mb_substr(trim((string)$v), 0, $max); }
function _nutriLista($v, int $max = 60, int $maxTxt = 400): array {
    $out = [];
    if (is_array($v)) foreach ($v as $x) { $t = _nutriTxt(is_scalar($x) ? $x : '', $maxTxt); if ($t !== '') $out[] = $t; if (count($out) >= $max) break; }
    return $out;
}
// Só entram os campos conhecidos de cada tipo, com tamanho limitado.
function _nutriLimparDados(string $tipo, $d): array {
    if (!is_array($d)) $d = [];
    $o = [];
    if ($tipo === 'receita') {
        foreach (['tempo' => 60, 'porcoes' => 60, 'proteina' => 60, 'dificuldade' => 40, 'descricao' => 600, 'dica' => 600] as $k => $m) { $t = _nutriTxt($d[$k] ?? '', $m); if ($t !== '') $o[$k] = $t; }
        $o['ingredientes'] = _nutriLista($d['ingredientes'] ?? []);
        $o['preparo'] = _nutriLista($d['preparo'] ?? [], 40, 800);
        foreach (['acompanhamentos', 'outras_opcoes'] as $k) { $l = _nutriLista($d[$k] ?? [], 20); if ($l) $o[$k] = $l; }
        if (!empty($d['variacao']) && is_array($d['variacao'])) {
            $vt = _nutriTxt($d['variacao']['titulo'] ?? '', 120); $vx = _nutriTxt($d['variacao']['texto'] ?? '', 1500);
            if ($vt !== '' || $vx !== '') $o['variacao'] = ['titulo' => $vt, 'texto' => $vx];
        }
        if (!empty($d['nutricao']) && is_array($d['nutricao'])) {
            $n = ['porcao' => _nutriTxt($d['nutricao']['porcao'] ?? '', 80)];
            foreach (['kcal', 'proteina', 'carboidrato', 'gordura', 'saturada', 'fibra'] as $k) { if (isset($d['nutricao'][$k]) && is_numeric($d['nutricao'][$k])) $n[$k] = (float)$d['nutricao'][$k]; }
            $o['nutricao'] = $n;
        }
    } elseif ($tipo === 'video') {
        foreach (['url' => 500, 'duracao' => 20, 'descricao' => 600, 'capa' => 500, 'receita' => 80] as $k => $m) { $t = _nutriTxt($d[$k] ?? '', $m); if ($t !== '') $o[$k] = $t; }
    } elseif ($tipo === 'dica') {
        foreach (['icone' => 16, 'texto' => 1200] as $k => $m) { $t = _nutriTxt($d[$k] ?? '', $m); if ($t !== '') $o[$k] = $t; }
    }
    return $o;
}
function _nutriConteudoLinha(array $r): array {
    $d = json_decode((string)($r['dados'] ?? ''), true);
    if (!is_array($d)) $d = [];
    return array_merge($d, [
        'idconteudo' => (int)$r['idconteudo'], 'id' => $r['slug'], 'titulo' => $r['titulo'], 'cat' => $r['cat'],
        'ordem' => (int)$r['ordem'], 'ativo' => (int)$r['ativo'], 'foto' => $r['foto'],
    ]);
}

if ($action === 'nutri_conteudo') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    $_tiposOk = ['receita', 'video', 'dica'];
    if ($method === 'GET') {
        $sql = "SELECT idconteudo, tipo, slug, titulo, cat, ordem, ativo, foto, dados FROM intus_nutri_conteudo"
             . (($_ehAluno || !empty($_GET['ativos'])) ? " WHERE ativo = 1" : "") . " ORDER BY ordem, idconteudo";
        $out = ['receitas' => [], 'videos' => [], 'dicas' => []];
        $chave = ['receita' => 'receitas', 'video' => 'videos', 'dica' => 'dicas'];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $r) { if (isset($chave[$r['tipo']])) $out[$chave[$r['tipo']]][] = _nutriConteudoLinha($r); }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_ehAluno) { http_response_code(403); echo json_encode(['error' => 'somente a equipe']); exit; }
    $_autorEquipe = (int)($_ctx['idusuario'] ?? 0);

    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();

        // Importar do arquivo (receitas e dicas do e-book). Idempotente: o que já existe (mesmo tipo e slug) não é tocado.
        if ($method === 'POST' && (($b['subacao'] ?? '') === 'importar')) {
            $arq = __DIR__ . '/../painel/nutri-conteudo.json';
            $json = is_file($arq) ? json_decode((string)file_get_contents($arq), true) : null;
            if (!is_array($json)) { http_response_code(500); echo json_encode(['error' => 'arquivo de conteudo nao encontrado']); exit; }
            $ins = $pdo->prepare("INSERT IGNORE INTO intus_nutri_conteudo (tipo, slug, titulo, cat, ordem, ativo, foto, dados, created_by) VALUES (?,?,?,?,?,1,?,?,?)");
            $novos = 0; $ja = 0;
            foreach ([['receita', 'receitas'], ['dica', 'dicas'], ['video', 'videos']] as [$tp, $kk]) {
                $i = 0;
                foreach (is_array($json[$kk] ?? null) ? $json[$kk] : [] as $it) {
                    if (!is_array($it) || empty($it['id']) || empty($it['titulo'])) continue;
                    $i++;
                    $dados = _nutriLimparDados($tp, $it);
                    $ins->execute([$tp, _nutriSlug((string)$it['id']), _nutriTxt($it['titulo'], 200), $tp === 'receita' ? _nutriTxt($it['cat'] ?? '', 40) : null,
                                   $i * 10, !empty($it['foto']) ? _nutriTxt($it['foto'], 500) : null, json_encode($dados, JSON_UNESCAPED_UNICODE), $_autorEquipe]);
                    if ($ins->rowCount() > 0) $novos++; else $ja++;
                }
            }
            echo json_encode(['ok' => true, 'novos' => $novos, 'ja_existiam' => $ja]);
            exit;
        }

        $tipo = (string)($b['tipo'] ?? '');
        $id = (int)($b['idconteudo'] ?? 0);
        if ($method === 'PUT' && $id > 0) {
            $st = $pdo->prepare("SELECT tipo FROM intus_nutri_conteudo WHERE idconteudo = ?");
            $st->execute([$id]);
            $tipoAtual = $st->fetchColumn();
            if ($tipoAtual === false) { http_response_code(404); echo json_encode(['error' => 'nao encontrado']); exit; }
            $tipo = (string)$tipoAtual;
        }
        if (!in_array($tipo, $_tiposOk, true)) { http_response_code(400); echo json_encode(['error' => 'tipo invalido']); exit; }
        $titulo = _nutriTxt($b['titulo'] ?? '', 200);
        if ($titulo === '') { http_response_code(400); echo json_encode(['error' => 'titulo obrigatorio']); exit; }
        $cat = $tipo === 'receita' ? _nutriTxt($b['cat'] ?? '', 40) : null;
        $ordem = (int)($b['ordem'] ?? 0);
        $ativo = isset($b['ativo']) ? (empty($b['ativo']) ? 0 : 1) : 1;
        $dados = json_encode(_nutriLimparDados($tipo, $b['dados'] ?? []), JSON_UNESCAPED_UNICODE);
        if (strlen((string)$dados) > 60000) { http_response_code(400); echo json_encode(['error' => 'conteudo grande demais']); exit; }
        // Foto: nova (data URL) vira arquivo em /img/receitas; senão mantém o texto enviado (caminho ou link já existente).
        $foto = isset($b['foto']) ? _nutriTxt($b['foto'], 500) : null;
        if (!empty($b['foto_base64'])) {
            try { $foto = _salvarImagemFeedB64((string)$b['foto_base64'], $_autorEquipe, 'receitas', 'receita'); }
            catch (Exception $e) { http_response_code(400); echo json_encode(['error' => $e->getMessage()]); exit; }
        }
        try {
            if ($method === 'PUT' && $id > 0) {
                $pdo->prepare("UPDATE intus_nutri_conteudo SET titulo = ?, cat = ?, ordem = ?, ativo = ?, foto = ?, dados = ? WHERE idconteudo = ?")
                    ->execute([$titulo, $cat, $ordem, $ativo, ($foto !== null && $foto !== '') ? $foto : null, $dados, $id]);
                echo json_encode(['ok' => true, 'idconteudo' => $id, 'foto' => $foto]);
                exit;
            }
            $slug = _nutriSlug((string)($b['slug'] ?? '') !== '' ? (string)$b['slug'] : $titulo);
            $base = $slug; $n = 1;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM intus_nutri_conteudo WHERE tipo = ? AND slug = ?");
            while (true) { $chk->execute([$tipo, $slug]); if ((int)$chk->fetchColumn() === 0) break; $n++; $slug = substr($base, 0, 55) . '-' . $n; }
            $pdo->prepare("INSERT INTO intus_nutri_conteudo (tipo, slug, titulo, cat, ordem, ativo, foto, dados, created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$tipo, $slug, $titulo, $cat, $ordem, $ativo, ($foto !== null && $foto !== '') ? $foto : null, $dados, $_autorEquipe]);
            echo json_encode(['ok' => true, 'idconteudo' => (int)$pdo->lastInsertId(), 'id' => $slug, 'foto' => $foto]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => 'falha ao gravar o conteudo', 'detalhe' => _intusLogErro($e)]);
        }
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['error' => 'id obrigatorio']); exit; }
        $pdo->prepare("DELETE FROM intus_nutri_conteudo WHERE idconteudo = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// Painel da nutri: planos ativos + registros, água e peso dos últimos 14 dias da carteira.
if ($action === 'nutri_painel' && $method === 'GET') {
    if (!$_tokValido || $_ehAluno) { http_response_code(403); echo json_encode(['error' => 'somente equipe']); exit; }
    $tblA = _feedDetectarTabelaAtleta($pdo);
    $nomes = [];
    if ($tblA) {
        try { foreach ($pdo->query("SELECT idatleta, nome FROM `$tblA` WHERE (stbloqueio IS NULL OR stbloqueio != 'S')")->fetchAll(PDO::FETCH_ASSOC) as $r) $nomes[(int)$r['idatleta']] = $r['nome']; }
        catch (Throwable $e) { foreach ($pdo->query("SELECT idatleta, nome FROM `$tblA`")->fetchAll(PDO::FETCH_ASSOC) as $r) $nomes[(int)$r['idatleta']] = $r['nome']; }
    }
    $ids = !empty($_ctx['admin']) ? array_keys($nomes) : array_values(array_intersect(array_keys($nomes), getAtletasDoUsuario($pdo, (int)($_ctx['idusuario'] ?? 0))));
    if (!$ids) { echo json_encode(['atletas' => (object)[], 'planos' => [], 'registros' => [], 'agua' => [], 'pesos' => [], 'sem_plano' => []]); exit; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $planos = [];
    $comPlano = [];
    $st = $pdo->prepare("SELECT idplano, idatleta, titulo, objetivo, calorias, proteina, carboidrato, gordura, refeicoes, dtinicio, dtfim FROM intus_plano_nutricional WHERE ativo = 1 AND idatleta IN ($ph) ORDER BY dtinicio DESC, idplano DESC");
    $st->execute($ids);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ida = (int)$r['idatleta'];
        if (isset($comPlano[$ida])) continue;   // um plano ativo por aluno (o mais recente)
        $comPlano[$ida] = true;
        $ref = null; if (!empty($r['refeicoes'])) { $tmp = json_decode($r['refeicoes'], true); if (is_array($tmp)) $ref = $tmp; }
        $planos[] = ['idplano' => (int)$r['idplano'], 'idatleta' => $ida, 'titulo' => $r['titulo'], 'objetivo' => $r['objetivo'] ?? '',
            'calorias' => $r['calorias'] !== null ? (int)$r['calorias'] : null, 'proteina' => $r['proteina'] !== null ? (float)$r['proteina'] : null,
            'carboidrato' => $r['carboidrato'] !== null ? (float)$r['carboidrato'] : null, 'gordura' => $r['gordura'] !== null ? (float)$r['gordura'] : null,
            'refeicoes' => $ref, 'dtinicio' => $r['dtinicio'], 'dtfim' => $r['dtfim']];
    }
    $desde = date('Y-m-d', strtotime('-14 days'));
    $regs = []; $agua = []; $pesos = [];
    try {
        $st = $pdo->prepare("SELECT idregistro, idatleta, data, refeicao, status, obs, foto, resposta_nutri FROM intus_nutri_registro WHERE data >= ? AND idatleta IN ($ph) ORDER BY data DESC, idregistro DESC");
        $st->execute(array_merge([$desde], $ids));
        $regs = array_map(function ($r) { $r['idregistro'] = (int)$r['idregistro']; $r['idatleta'] = (int)$r['idatleta']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
        $st = $pdo->prepare("SELECT idatleta, data, agua_ml FROM intus_nutri_dia WHERE data >= ? AND idatleta IN ($ph)");
        $st->execute(array_merge([$desde], $ids));
        $agua = array_map(function ($r) { $r['idatleta'] = (int)$r['idatleta']; $r['agua_ml'] = (int)$r['agua_ml']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {}
    try {
        $st = $pdo->prepare("SELECT idatleta, dtavaliacao, peso FROM intus_avaliacao WHERE peso IS NOT NULL AND dtavaliacao >= ? AND idatleta IN ($ph) ORDER BY dtavaliacao ASC");
        $st->execute(array_merge([date('Y-m-d', strtotime('-120 days'))], $ids));
        $pesos = array_map(function ($r) { $r['idatleta'] = (int)$r['idatleta']; $r['peso'] = (float)$r['peso']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {}
    $semPlano = [];
    foreach ($ids as $i) if (!isset($comPlano[$i])) $semPlano[] = $i;
    $atl = [];
    foreach ($ids as $i) $atl[$i] = $nomes[$i] ?? ('Aluno #' . $i);
    echo json_encode(['atletas' => (object)$atl, 'planos' => $planos, 'registros' => $regs, 'agua' => $agua, 'pesos' => $pesos, 'sem_plano' => $semPlano], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Preferências do feed: quem eu não quero ver, e quem não pode me ver ────
if ($action === 'feed_pref') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    [, $_autorId, ] = _resolverAutorPub($pdo, $_ctx, $_ehAluno);
    if ($_autorId <= 0) { http_response_code(401); echo json_encode(['error' => 'sem identidade no token']); exit; }

    if ($method === 'GET') {
        $st = $pdo->prepare("SELECT idalvo, tipo FROM intus_feed_pref WHERE idatleta = ?");
        $st->execute([$_autorId]);
        echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
        exit;
    }
    if ($method === 'POST') {
        $b = jsonBody();
        $idalvo = (int)($b['idalvo'] ?? 0);
        $tipo = ($b['tipo'] ?? '') === 'mostrar' ? 'mostrar' : 'ver';
        if ($idalvo <= 0 || $idalvo === $_autorId) { http_response_code(400); echo json_encode(['error' => 'idalvo invalido']); exit; }
        $pdo->prepare("INSERT IGNORE INTO intus_feed_pref (idatleta, idalvo, tipo) VALUES (?,?,?)")->execute([$_autorId, $idalvo, $tipo]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $idalvo = (int)($_GET['idalvo'] ?? 0);
        $tipo = ($_GET['tipo'] ?? '') === 'mostrar' ? 'mostrar' : 'ver';
        if ($idalvo <= 0) { http_response_code(400); echo json_encode(['error' => 'idalvo invalido']); exit; }
        $pdo->prepare("DELETE FROM intus_feed_pref WHERE idatleta = ? AND idalvo = ? AND tipo = ?")->execute([$_autorId, $idalvo, $tipo]);
        echo json_encode(['ok' => true]);
        exit;
    }
}

// ═══════════════ AVISOS PARA O PAINEL ═══════════════
// Lista os avisos gerados quando um aluno preenche anamnese/avaliacao, e permite
// marcar como lido. So professor — o aluno nao ve avisos de ninguem.
if ($action === 'avisos') {
    if (!$_tokValido) { http_response_code(401); echo json_encode(['error' => 'token ausente ou invalido']); exit; }
    if ($_ehAluno)    { http_response_code(403); echo json_encode(['error' => 'restrito ao professor']); exit; }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_avisos (
            idaviso INT AUTO_INCREMENT PRIMARY KEY,
            idatleta INT NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            detalhe VARCHAR(255) NULL,
            lido TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lido (lido), INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    if ($method === 'GET') {
        $rows = $pdo->query("SELECT idaviso, idatleta, tipo, titulo, detalhe, lido, created_at
                             FROM intus_avisos ORDER BY created_at DESC, idaviso DESC LIMIT 100")
                    ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_map(function ($r) {
            return [
                'idaviso'  => (int)$r['idaviso'],
                'idatleta' => (int)$r['idatleta'],
                'tipo'     => $r['tipo'],
                'titulo'   => $r['titulo'],
                'detalhe'  => $r['detalhe'] ?? '',
                'lido'     => (int)$r['lido'] === 1,
                'quando'   => $r['created_at'],
            ];
        }, $rows), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($method === 'POST' || $method === 'PUT') {
        $b = jsonBody();
        if (!empty($b['marcar_todos'])) {
            $pdo->exec("UPDATE intus_avisos SET lido = 1 WHERE lido = 0");
            echo json_encode(['ok' => true]); exit;
        }
        $idv = (int)($b['idaviso'] ?? 0);
        if ($idv <= 0) { http_response_code(400); echo json_encode(['error' => 'idaviso obrigatorio']); exit; }
        $pdo->prepare("UPDATE intus_avisos SET lido = 1 WHERE idaviso = ?")->execute([$idv]);
        echo json_encode(['ok' => true]); exit;
    }
}

// ═══════════════ CONTA AINDA EXISTE? ═══════════════
// O app do aluno guarda o login no navegador. Se a conta for excluida/recriada, o
// celular continua achando que e o id antigo e grava tudo numa conta fantasma
// (foi o que aconteceu com a anamnese do atleta #1). Este endpoint so responde
// se o id existe — nao devolve nenhum dado pessoal.
if ($action === 'atleta_existe') {
    $id = (int)($_GET['atleta'] ?? 0);
    if ($id <= 0) { echo json_encode(['existe' => false]); exit; }
    $tab = null;
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tab = $t; break; } catch (Throwable $e) {}
    }
    if (!$tab) { echo json_encode(['existe' => true, 'indeterminado' => true]); exit; }
    $col = null;
    foreach (['idatleta', 'idaluno', 'id'] as $c) {
        try { $pdo->query("SELECT `$c` FROM `$tab` LIMIT 1"); $col = $c; break; } catch (Throwable $e) {}
    }
    if (!$col) { echo json_encode(['existe' => true, 'indeterminado' => true]); exit; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM `$tab` WHERE `$col` = ? LIMIT 1");
        $st->execute([$id]);
        echo json_encode(['existe' => (bool)$st->fetch()]);
    } catch (Throwable $e) {
        // Na duvida, nunca deslogar o aluno.
        echo json_encode(['existe' => true, 'indeterminado' => true]);
    }
    exit;
}

// ═══════════════ AVISO AO PROFESSOR RESPONSAVEL ═══════════════
// Quando o aluno preenche anamnese ou qualquer formulario de avaliacao, quem
// precisa saber e o professor dele — nao uma caixa geral que ninguem abre.
// Descobre os responsaveis pela coluna professores_responsaveis do atleta e
// devolve os e-mails. Se nao houver vinculo, cai no endereco de notificacao.
function _catalogoDestinatariosDoAtleta(PDO $pdo, int $idatleta): array {
    $tabAt = null;
    foreach (['atleta','atletas','aluno','alunos'] as $t) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tabAt = $t; break; } catch (Throwable $e) {}
    }
    $nome = ''; $profs = [];
    if ($tabAt) {
        try {
            $st = $pdo->prepare("SELECT * FROM `$tabAt` WHERE idatleta = ? LIMIT 1");
            $st->execute([$idatleta]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ($r) {
                $nome = $r['nome'] ?? ($r['nmatleta'] ?? '');
                $raw = $r['professores_responsaveis'] ?? '';
                if (is_string($raw) && $raw !== '' && $raw !== '[]') {
                    $tmp = json_decode($raw, true);
                    if (is_array($tmp)) $profs = array_map('intval', $tmp);
                }
            }
        } catch (Throwable $e) {}
    }
    $emails = [];
    if ($profs) {
        $tabUs = null;
        foreach (['professor','usuario','usuarios','professores'] as $t) {
            try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $tabUs = $t; break; } catch (Throwable $e) {}
        }
        if ($tabUs) {
            $colId = null;
            foreach (['idusuario','idprofessor','id'] as $c) {
                try { $pdo->query("SELECT `$c` FROM `$tabUs` LIMIT 1"); $colId = $c; break; } catch (Throwable $e) {}
            }
            if ($colId) {
                $ph = implode(',', array_fill(0, count($profs), '?'));
                try {
                    $st = $pdo->prepare("SELECT email FROM `$tabUs` WHERE `$colId` IN ($ph)");
                    $st->execute($profs);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $e = trim((string)($u['email'] ?? ''));
                        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[] = $e;
                    }
                } catch (Throwable $e) {}
            }
        }
    }
    if (!$emails) {
        $cfg = @include __DIR__ . '/../config/smtp.php';
        $fb = (is_array($cfg) && !empty($cfg['notificar'])) ? $cfg['notificar'] : 'contato@intusfit.com.br';
        $emails[] = $fb;
    }
    return ['emails' => array_values(array_unique($emails)), 'nome' => $nome];
}

// Registra o aviso NO SISTEMA (aparece no painel) e dispara o e-mail.
function _catalogoAvisarProfessor(PDO $pdo, int $idatleta, string $oQue, int $qtdRespostas = 0): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_avisos (
            idaviso INT AUTO_INCREMENT PRIMARY KEY,
            idatleta INT NOT NULL,
            tipo VARCHAR(40) NOT NULL,
            titulo VARCHAR(160) NOT NULL,
            detalhe VARCHAR(255) NULL,
            lido TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_lido (lido), INDEX idx_atleta (idatleta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}

    $info = _catalogoDestinatariosDoAtleta($pdo, $idatleta);
    $nome = $info['nome'] !== '' ? $info['nome'] : ('Aluno #' . $idatleta);
    $titulo  = $nome . ' preencheu: ' . $oQue;
    $detalhe = $qtdRespostas > 0 ? ($qtdRespostas . ' respostas') : '';

    // Segunda rede: nao repetir o MESMO aviso do MESMO aluno dentro de seis
    // horas. A primeira rede (so avisar quando o conteudo muda) resolve o caso
    // do reenvio automatico; esta pega o resto — aluno que salva tres vezes
    // seguidas ajustando uma frase nao precisa virar tres avisos iguais.
    try {
        $jaTem = $pdo->prepare("SELECT 1 FROM intus_avisos
                                WHERE idatleta = ? AND titulo = ? AND detalhe = ?
                                  AND created_at > DATE_SUB(NOW(), INTERVAL 6 HOUR) LIMIT 1");
        $jaTem->execute([$idatleta, mb_substr($titulo, 0, 160), mb_substr($detalhe, 0, 255)]);
        if ($jaTem->fetchColumn()) return;
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare("INSERT INTO intus_avisos (idatleta, tipo, titulo, detalhe) VALUES (?, ?, ?, ?)");
        $st->execute([$idatleta, 'avaliacao', mb_substr($titulo, 0, 160), mb_substr($detalhe, 0, 255)]);
    } catch (Throwable $e) {}

    $corpo  = $nome . " preencheu " . $oQue . " no app.\n\n";
    if ($qtdRespostas > 0) $corpo .= "Respostas preenchidas: " . $qtdRespostas . "\n";
    $corpo .= "Data: " . date('d/m/Y H:i') . "\n\n";
    $corpo .= "Abra o painel em Avaliacoes para ver o conteudo.\n";
    foreach ($info['emails'] as $para) {
        @_catalogoSendEmail($para, 'Nova avaliacao preenchida: ' . $nome, $corpo);
    }
}

// ═══════════════ ANAMNESE ═══════════════
if ($action === 'anamnese') {
    $idatleta = (int)($_GET['atleta'] ?? 0);
    $b = null;
    if ($idatleta <= 0) { $b = jsonBody(); $idatleta = (int)($b['_idatleta'] ?? $b['idatleta'] ?? 0); }

    // Aluno logado so pode ler/gravar a propria anamnese. Se o contexto de auth nao
    // souber dizer qual e o id dele, nao bloqueia (para nao quebrar o app) — o token
    // ja foi exigido acima, entao anonimo nao chega aqui.
    if ($_ehAluno) {
        // ── O DONO E O TOKEN, NAO O QUE O APLICATIVO PEDIU ───────────────────
        // Antes o id vinha da URL e so era comparado com o do token QUANDO desse
        // para resolver o do token. Se nao desse, passava. Agora nao se compara:
        // para sessao de aluno o id do token SUBSTITUI o que veio na requisicao.
        // Nao existe mais caminho, nem por erro nem por ma-fe, que grave a
        // anamnese de um aluno na conta de outro.
        $meu = (int)($_ctx['idatleta'] ?? 0);
        if ($meu <= 0) $meu = (int)($_ctx['idusuario'] ?? 0);
        if ($meu <= 0) {
            http_response_code(401);
            echo json_encode(['error' => 'sessao de aluno sem identidade']);
            exit;
        }
        if ($idatleta > 0 && $meu !== $idatleta && $method !== 'GET') {
            // Nao e so bloquear: registrar, porque isto indica aparelho com a
            // sessao de outra pessoa — o tipo de coisa que passa despercebida.
            @error_log('[intus] anamnese: token do atleta ' . $meu . ' tentou gravar no atleta ' . $idatleta);
        }
        $idatleta = $meu;
    }

    if ($method === 'GET') {
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }
        $st = $pdo->prepare("SELECT dados FROM intus_anamnese WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['dados']) {
            $dados = json_decode($row['dados'], true);
            echo is_array($dados) ? json_encode($dados, JSON_UNESCAPED_UNICODE) : json_encode(new stdClass());
        } else {
            echo json_encode(new stdClass());
        }
        exit;
    }
    if ($method === 'POST' || $method === 'PUT') {
        if ($b === null) $b = jsonBody();
        if ($idatleta <= 0) { http_response_code(400); echo json_encode(['error' => 'atleta obrigatorio']); exit; }

        // ── O ALUNO PRECISA EXISTIR ─────────────────────────────────────────
        // Ate aqui o servidor gravava a anamnese no numero que viesse do
        // aplicativo, sem perguntar se aquele aluno existe. Foi assim que
        // nasceram as anamneses orfas nos ids 1 e 999999: respostas de gente de
        // verdade guardadas numa conta que nunca existiu, invisiveis no cadastro
        // de quem respondeu e aparecendo no painel como "atleta 1". Pior: como a
        // gravacao e por idatleta, a segunda pessoa a cair no mesmo numero
        // sobrescrevia as respostas da primeira.
        $_existe = false;
        foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $_t) {
            try {
                $_q = $pdo->prepare("SELECT 1 FROM `$_t` WHERE idatleta = ? LIMIT 1");
                $_q->execute([$idatleta]);
                $_existe = (bool)$_q->fetchColumn();
                break;
            } catch (Throwable $e) { /* tabela com outro nome: tenta a proxima */ }
        }
        if (!$_existe) {
            http_response_code(404);
            echo json_encode(['error' => 'aluno inexistente', 'idatleta' => $idatleta,
                              'detalhe' => 'A anamnese nao foi gravada porque este cadastro nao existe. Entre novamente no aplicativo.']);
            exit;
        }

        // ── QUEM GRAVOU ─────────────────────────────────────────────────────
        // Guardado com prefixo "_", entao nao conta como resposta nem aparece
        // como pergunta no painel. Serve para responder "quem respondeu isto?"
        // sem ter que adivinhar depois.
        if (is_array($b)) {
            $b['_auditoria'] = [
                'em'         => date('Y-m-d H:i:s'),
                'ctx_id'     => (int)($_ctx['idusuario'] ?? 0),
                'ctx_atleta' => (int)($_ctx['idatleta'] ?? 0),
                'aluno'      => $_ehAluno ? 'S' : 'N',
                'ip'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                'ua'         => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            ];
        }

        // ── GRAVACAO MESCLA, NAO SUBSTITUI ──────────────────────────────────
        // Este era o defeito mais caro da tela de avaliacoes. O aplicativo do
        // aluno tem quatro formularios que gravam na mesma anamnese: o
        // principal, o comportamental, o nutricional e o de rotina. Tres deles
        // enviavam so a propria secao — e o servidor fazia UPDATE do campo
        // inteiro. Resultado: o aluno respondia as 16 perguntas da anamnese,
        // depois respondia o comportamental, e as 16 respostas sumiam. Ficava
        // so a secao recem enviada. Era isso que os avisos mostravam: "16
        // respostas", depois "13", depois "2", depois "1" — o registro
        // encolhendo a cada formulario salvo.
        // Agora o que chega e mesclado por chave sobre o que ja existe. Enviar
        // uma secao nunca mais apaga as outras. Para substituir tudo de
        // proposito, mande "_substituir": true no corpo.
        $st = $pdo->prepare("SELECT dados FROM intus_anamnese WHERE idatleta = ?");
        $st->execute([$idatleta]);
        $linha = $st->fetch(PDO::FETCH_ASSOC);

        $substituir = !empty($b['_substituir']);
        unset($b['_substituir']);

        // Reenvio de recuperacao: preenche SO o que falta no servidor, nunca
        // sobrescreve o que ja esta la. O aplicativo reenvia a copia guardada no
        // aparelho para recuperar respostas perdidas — e a copia do aparelho
        // pode ser mais VELHA que a do servidor. Sem esta trava, a recuperacao
        // vira regressao: respostas novas apagadas por respostas antigas.
        $somenteFaltantes = !empty($b['_somente_faltantes']);
        unset($b['_somente_faltantes']);

        $final = $b;
        if ($linha && !$substituir) {
            $antigo = json_decode((string)$linha['dados'], true);
            if (is_array($antigo)) {
                $final = $antigo;
                foreach ($b as $k => $v) {
                    if ($somenteFaltantes && array_key_exists($k, $final) && strpos((string)$k, '_') !== 0) continue;
                    // Secao vazia nao apaga secao preenchida: se o novo valor
                    // for vazio e ja existir conteudo, mantem o que estava.
                    $vazio = ($v === null || $v === '' || $v === [] ||
                              (is_string($v) && trim($v) === '') ||
                              (is_array($v) && count(array_filter($v, function ($z) {
                                  return !($z === null || $z === '' || (is_string($z) && trim($z) === ''));
                              })) === 0));
                    if ($vazio && array_key_exists($k, $final) && strpos((string)$k, '_') !== 0) continue;
                    $final[$k] = $v;
                }
            }
        }
        $b = $final;
        $dados = json_encode($final, JSON_UNESCAPED_UNICODE);

        // ── SO AVISA SE MUDOU ALGUMA COISA ──────────────────────────────────
        // O aplicativo reenvia a copia guardada no aparelho toda vez que abre —
        // e faz bem, foi assim que as respostas da Diely voltaram. So que cada
        // reenvio disparava um aviso novo no painel dizendo que o aluno "tinha
        // preenchido a anamnese", mesmo quando nada tinha mudado. O resultado
        // era o painel enchendo de aviso de resposta que ninguem escreveu.
        // A comparacao ignora o bloco de auditoria, que muda sempre por
        // natureza (data, IP, aparelho) e nao e resposta de ninguem.
        $_semRuido = function ($arr) {
            if (!is_array($arr)) return '';
            unset($arr['_auditoria']);
            ksort($arr);
            return json_encode($arr, JSON_UNESCAPED_UNICODE);
        };
        $_antes = $linha ? json_decode((string)$linha['dados'], true) : null;
        $_mudou = ($_semRuido($_antes) !== $_semRuido($final));

        // BUG real: intus_anamnese.updated_at tem ON UPDATE CURRENT_TIMESTAMP, e
        // este UPDATE rodava sempre, mesmo quando $_mudou era falso (reenvio da
        // cópia local, sem nada novo — ver comentário acima). Resultado: um
        // registro de teste antigo (ex.: a anamnese de um aluno usada só pra
        // testar o app) reaparecia como "atividade recente" toda vez que o
        // dono reabria aquela tela, mesmo sem responder nada de novo — o
        // UPDATE gravava o MESMO valor, mas "hoje" mesmo assim. Só grava de
        // verdade quando algo mudou (ou quando o registro ainda não existe).
        if (!$linha) {
            $pdo->prepare("INSERT INTO intus_anamnese (idatleta, dados) VALUES (?, ?)")->execute([$idatleta, $dados]);
        } elseif ($_mudou) {
            $pdo->prepare("UPDATE intus_anamnese SET dados = ? WHERE idatleta = ?")->execute([$dados, $idatleta]);
        }
        // Conta respostas de verdade, inclusive as que estao dentro de secoes
        // (comportamental, nutricional, rotina). Antes uma secao inteira com 11
        // respostas contava como 1, e o aviso dizia "preencheu a anamnese (1
        // resposta)" para quem tinha escrito onze paragrafos.
        $_contar = function ($arr) use (&$_contar) {
            $n = 0;
            foreach ((array)$arr as $k => $v) {
                if (strpos((string)$k, '_') === 0) continue;
                if ($v === '' || $v === null || $v === []) continue;
                if (is_string($v) && trim($v) === '') continue;
                if (is_array($v)) { $n += $_contar($v); continue; }
                $n++;
            }
            return $n;
        };
        $_qtd = is_array($b) ? $_contar($b) : 0;
        if ($_mudou) {
            @_catalogoAvisarProfessor($pdo, $idatleta, 'a anamnese', $_qtd);
        }

        // "alterado" deixa o aplicativo saber que o reenvio nao acrescentou
        // nada, e parar de insistir.
        echo json_encode(['ok' => true, 'alterado' => $_mudou, 'respostas' => $_qtd]);
        exit;
    }
}

// Lista as anamneses (para o dashboard do professor detectar respostas novas)
if ($action === 'anamneses') {
    $rows = $pdo->query("SELECT idatleta, dados, updated_at FROM intus_anamnese ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $out = array_map(function ($r) {
        $d = null; if (!empty($r['dados'])) { $tmp = json_decode($r['dados'], true); if (is_array($tmp)) $d = $tmp; }
        // Conta so respostas de verdade: ignora campos internos (_timestamp, _idatleta) e vazios.
        $qtd = 0;
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                if (strpos((string)$k, '_') === 0) continue;
                if ($v === '' || $v === null || $v === []) continue;
                if (is_string($v) && trim($v) === '') continue;
                $qtd++;
            }
        }
        return [ 'idatleta' => (int)$r['idatleta'], 'updated_at' => $r['updated_at'] ?? null, 'campos_preenchidos' => $qtd, 'dados' => $d ];
    }, $rows);
    echo json_encode($out);
    exit;
}

if ($action === 'avatars') {
    $rows = $pdo->query("SELECT idatleta, avatar FROM intus_profile WHERE avatar IS NOT NULL AND avatar != ''")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) { $map[(int)$r['idatleta']] = $r['avatar']; }
    echo json_encode($map);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'action invalida', 'action' => $action]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'falha interna', 'detalhe' => _intusLogErro($e)]);
}
