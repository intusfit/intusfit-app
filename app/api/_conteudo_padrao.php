<?php
/**
 * Central de conteúdo (/conteudo/): só dados de fábrica. A regra mora em _conteudo.php.
 * Enquanto nada foi salvo na tela, vale o que está aqui. "Restaurar" volta a isto.
 *
 * Origem dos links e das ofertas: página INTUS FIT - CENTRAL do Notion (maio a agosto de 2025),
 * só o que ainda vale. Mentoria 4X, Sai Pochete e Mentoria de Dieta não são mais vendidos e ficaram de fora.
 */

/** Etapas da peça, na ordem do fluxo. Fixas no código: as exigências de cada etapa dependem delas. */
const CONT_STATUS = [
    ['id' => 'ideia',     'nome' => 'Ideia',     'fim' => false],
    ['id' => 'roteiro',   'nome' => 'Roteiro',   'fim' => false],
    ['id' => 'gravacao',  'nome' => 'Gravação',  'fim' => false],
    ['id' => 'edicao',    'nome' => 'Edição',    'fim' => false],
    ['id' => 'revisao',   'nome' => 'Revisão',   'fim' => false],
    ['id' => 'aprovado',  'nome' => 'Aprovado',  'fim' => false],
    ['id' => 'agendado',  'nome' => 'Agendado',  'fim' => false],
    ['id' => 'publicado', 'nome' => 'Publicado', 'fim' => true],
    ['id' => 'arquivado', 'nome' => 'Arquivado', 'fim' => true],
];

/** Canais de publicação. Fixos: a publicação automática (2ª etapa) vai falar com cada um. */
const CONT_CANAIS = [
    ['id' => 'instagram', 'nome' => 'Instagram'],
    ['id' => 'youtube',   'nome' => 'YouTube'],
    ['id' => 'tiktok',    'nome' => 'TikTok'],
    ['id' => 'whatsapp',  'nome' => 'WhatsApp (status ou canal)'],
    ['id' => 'site',      'nome' => 'Site ou blog'],
];

const CONT_PAPEIS = [
    ['id' => 'apresentador', 'nome' => 'Apresenta'],
    ['id' => 'participante', 'nome' => 'Aluno ou aluna na peça'],
    ['id' => 'edicao',       'nome' => 'Edição'],
    ['id' => 'design',       'nome' => 'Design'],
    ['id' => 'outro',        'nome' => 'Outro'],
];

const CONT_TIPOS_EVENTO = [
    ['id' => 'gravacao',   'nome' => 'Gravação'],
    ['id' => 'evento',     'nome' => 'Evento'],
    ['id' => 'campeonato', 'nome' => 'Campeonato'],
    ['id' => 'reuniao',    'nome' => 'Reunião'],
    ['id' => 'outro',      'nome' => 'Outro'],
];

/** Parte editável na tela (chave cont_cfg). */
function cont_cfg_padrao(): array {
    return [
        'marcas' => [
            ['id' => 'luiznunest',        'nome' => 'Luiz Nunes',          'handle' => '@luiznunest',        'meta_semana' => 3, 'cor' => 1, 'papel' => 'Autoridade, bastidor e prova social do Luiz'],
            ['id' => 'intusfit',          'nome' => 'Intus Fit',           'handle' => '@intusfit',          'meta_semana' => 4, 'cor' => 2, 'papel' => 'A marca: consultoria de treino e nutrição'],
            ['id' => 'codigodadefinicao', 'nome' => 'Código da Definição', 'handle' => '@codigodadefinicao', 'meta_semana' => 3, 'cor' => 3, 'papel' => 'CDD: método para mulheres, vende no Eduzz'],
        ],
        'formatos' => [
            ['id' => 'reel',        'nome' => 'Reel',                     'midia' => true, 'roteiro' => true],
            ['id' => 'carrossel',   'nome' => 'Carrossel',                'midia' => true, 'roteiro' => true],
            ['id' => 'imagem',      'nome' => 'Imagem única',             'midia' => true, 'roteiro' => false],
            ['id' => 'story',       'nome' => 'Story',                    'midia' => true, 'roteiro' => false],
            ['id' => 'short',       'nome' => 'Short ou TikTok',          'midia' => true, 'roteiro' => true],
            ['id' => 'video-longo', 'nome' => 'Vídeo longo (YouTube)',    'midia' => true, 'roteiro' => true],
            ['id' => 'live',        'nome' => 'Live',                     'midia' => false, 'roteiro' => true],
            ['id' => 'texto',       'nome' => 'Só texto',                 'midia' => false, 'roteiro' => false],
        ],
        'tipos' => [
            ['id' => 'educativo',     'nome' => 'Educativo',            'venda' => false],
            ['id' => 'autoridade',    'nome' => 'Autoridade',           'venda' => false],
            ['id' => 'prova-social',  'nome' => 'Prova social',         'venda' => false],
            ['id' => 'antes-depois',  'nome' => 'Antes e depois',       'venda' => false],
            ['id' => 'bastidor',      'nome' => 'Bastidor',             'venda' => false],
            ['id' => 'engajamento',   'nome' => 'Engajamento',          'venda' => false],
            ['id' => 'meme-viral',    'nome' => 'Meme ou viral',        'venda' => false],
            ['id' => 'oferta',        'nome' => 'Oferta ou venda',      'venda' => true],
            ['id' => 'lancamento',    'nome' => 'Lançamento ou campanha', 'venda' => true],
        ],
        'funis' => [
            ['id' => 'atrair',    'nome' => 'Atrair (gente nova)'],
            ['id' => 'nutrir',    'nome' => 'Nutrir (quem já segue)'],
            ['id' => 'converter', 'nome' => 'Converter (levar à oferta)'],
            ['id' => 'reter',     'nome' => 'Reter (quem já é aluno)'],
        ],
        'pessoas' => [
            ['id' => 'luiz-nunes', 'nome' => 'Luiz Nunes', 'papel' => 'apresentador'],
        ],
        'reciclar_dias' => 60,   // peça publicada marcada para reciclar volta à fila depois deste prazo
        'parado_dias'   => 7,    // peça sem mexer há tanto tempo numa etapa conta como parada
        'venda_max_pct' => 30,   // acima disso, a grade dos últimos 30 dias avisa que está vendendo demais
    ];
}

function cont_ofertas_padrao(): array {
    return [
        ['id' => 'cdd-mensal', 'marca' => 'codigodadefinicao', 'nome' => 'CDD mensal', 'preco' => 'R$ 119', 'link' => 'https://chk.eduzz.com/2411829', 'cupom' => '', 'ativo' => true, 'obs' => ''],
        ['id' => 'cdd-anual', 'marca' => 'codigodadefinicao', 'nome' => 'CDD anual', 'preco' => 'R$ 698', 'link' => 'https://chk.eduzz.com/1344531', 'cupom' => '', 'ativo' => true, 'obs' => ''],
        ['id' => 'cdd-anual-20off', 'marca' => 'codigodadefinicao', 'nome' => 'CDD anual com 20% off', 'preco' => 'R$ 558,40', 'link' => 'https://sun.eduzz.com/G961N76AW1?cupom=CDD20OFF', 'cupom' => 'CDD20OFF', 'ativo' => true, 'obs' => 'Conferir no Eduzz a quais produtos o cupom vale.'],
        ['id' => 'consultoria-premium', 'marca' => 'intusfit', 'nome' => 'Consultoria Premium', 'preco' => 'Só com a equipe', 'link' => 'https://wa.me/5545991156006', 'cupom' => '', 'ativo' => true, 'obs' => 'Fecha por WhatsApp. Não publicar preço.'],
        ['id' => 'teste-gratis', 'marca' => 'intusfit', 'nome' => 'Teste grátis de 7 dias', 'preco' => 'Grátis', 'link' => 'https://intusfit.com.br/teste-gratis.html', 'cupom' => '', 'ativo' => true, 'obs' => 'Só treino, sem dieta.'],
    ];
}

function cont_links_padrao(): array {
    return [
        ['id' => 'drive-posts',   'grupo' => 'Pastas do Drive', 'nome' => 'Posts e gravações 2025', 'url' => 'https://drive.google.com/drive/folders/1OyzqnPgVB4leR7Pjtjj8qKHA8-j8opT7?usp=drive_link', 'obs' => ''],
        ['id' => 'drive-memes',   'grupo' => 'Pastas do Drive', 'nome' => 'Pack de memes e virais', 'url' => 'https://drive.google.com/drive/folders/1ZLTlh0aZ7K_jupmRPuB4UAaUJu8LBMA_?usp=drive_link', 'obs' => ''],
        ['id' => 'drive-ensaio',  'grupo' => 'Pastas do Drive', 'nome' => 'Ensaio Rosângela, material gráfico e web', 'url' => 'https://drive.google.com/drive/folders/1nWc9bkc73xGS4F33Rbtn4oz-5SRclt9W?usp=sharing', 'obs' => ''],
        ['id' => 'drive-antes-mentoria', 'grupo' => 'Pastas do Drive', 'nome' => 'Antes e depois: alunos da mentoria', 'url' => 'https://drive.google.com/drive/folders/1YYGAsSVAN-J1G2Nvrip_318VUTKoB-BF?usp=sharing', 'obs' => 'Tem imagem de aluno: só publicar com autorização.'],
        ['id' => 'drive-antes-cdd', 'grupo' => 'Pastas do Drive', 'nome' => 'Antes e depois: alunas do CDD', 'url' => 'https://drive.google.com/drive/folders/1-OSQkGZl5J6rqOBpwXXKJ9Lk-v_cbLGM?usp=drive_link', 'obs' => 'Tem imagem de aluna: só publicar com autorização.'],
        ['id' => 'drive-cdd',     'grupo' => 'Pastas do Drive', 'nome' => 'Material do Código da Definição', 'url' => 'https://drive.google.com/drive/folders/1JL2DtkYYnkTpXJA8UywPtoGMUHo6VaWW?usp=drive_link', 'obs' => ''],
        ['id' => 'yt-intus',      'grupo' => 'Canais', 'nome' => 'YouTube Intus Fit', 'url' => 'https://www.youtube.com/@intusfit', 'obs' => ''],
        ['id' => 'ig-luiz',       'grupo' => 'Canais', 'nome' => 'Instagram @luiznunest', 'url' => 'https://www.instagram.com/luiznunest/', 'obs' => ''],
        ['id' => 'ig-intus',      'grupo' => 'Canais', 'nome' => 'Instagram @intusfit', 'url' => 'https://www.instagram.com/intusfit/', 'obs' => ''],
        ['id' => 'ig-cdd',        'grupo' => 'Canais', 'nome' => 'Instagram @codigodadefinicao', 'url' => 'https://www.instagram.com/codigodadefinicao/', 'obs' => ''],
        ['id' => 'lp-cdd',        'grupo' => 'Páginas', 'nome' => 'Landing do Código da Definição', 'url' => 'https://codigodadefinicao.com.br', 'obs' => ''],
        ['id' => 'lp-teste',      'grupo' => 'Páginas', 'nome' => 'Teste grátis (captura)', 'url' => 'https://intusfit.com.br/teste-gratis.html', 'obs' => ''],
    ];
}
