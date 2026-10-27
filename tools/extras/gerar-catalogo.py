#!/usr/bin/env python
"""
Importa do CDD (cdd/api/dados/seed-conteudo.json) os módulos que viram Extras da Intus e gera:
  app/api/_extras_cdd.php        catálogo pronto (módulos e aulas), lido por _extras.php (fábrica e botão "Importar do CDD")
  tools/extras/quantidades.json  quantas aulas cada módulo tem (o gerador de capas usa)

Os módulos importados agora: Cintura de Atleta, Treinos em casa, Assista antes de treinar, Lives e aulas exclusivas.
Aula que o CDD já guardava como rascunho (tema sensível ou escondida da aluna) entra como RASCUNHO: o administrador vê no painel e decide.
Uso (na raiz do repositório):   python tools/extras/gerar-catalogo.py
"""
import json, os, re

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SEED = os.path.join(RAIZ, 'cdd', 'api', 'dados', 'seed-conteudo.json')
BASE_CAPAS = 'https://intusfit.com.br/app/painel/extras/capas/'
VERSAO = '?v=5'   # sobe quando as imagens forem refeitas, para o navegador e o Cloudflare não servirem a capa antiga
CRIADO = '2026-10-21 12:00:00'

# título no CDD, id, título no app, emoji, cor, descrição (sem promessa de resultado)
MODULOS = [
    ('Bônus: Cintura de Atleta - Método Sai Pochete', 'cintura-de-atleta', 'Cintura de Atleta', '🔥', '#f59e0b',
     'Passo a passo para trabalhar a região da cintura: respiração, bracing, vacuum abdominal, hipopressivos e a técnica de R.A.P.'),
    ('Treinos em Casa - CDD', 'treinos-em-casa', 'Treinos em casa', '🏠', '#22c55e',
     'Orientações, alongamentos e treinos para fazer em casa nos dias em que você não puder ir à academia.'),
    ('Assista antes de treinar', 'assista-antes-de-treinar', 'Assista antes de treinar', '🎬', '#0ea5e9',
     'Aulas curtas para começar bem: diário da definição, segurança nos treinos, pegadas e pisadas e ativação do bracing.'),
    ('Lives/Aulas Exclusivas', 'lives-e-aulas-exclusivas', 'Lives e aulas exclusivas', '🔴', '#ef4444',
     'Aulas e lives gravadas com a equipe: aeróbico, hipopressivo, lipedema, treino e dieta e mais.'),
]


# aulas com outra pessoa (código do vídeo -> descrição)
DESCRICOES = {'ZA-pZRy3_WU': 'Com o Prof. Ricardo.', 'f_asiwEChPw': 'Com o Prof. Ricardo.'}


def limpar_titulo(t):
    t = re.sub(r'\s*-\s*CDD\b', '', t).replace('  ', ' ').strip()
    return t


def main():
    seed = json.load(open(SEED, encoding='utf-8'))
    por_titulo = {m['titulo']: m for m in seed['modulos']}
    saida, qtd = [], {}
    for tit_cdd, mid, titulo, emoji, cor, desc in MODULOS:
        m = por_titulo[tit_cdd]
        itens = []
        for n, a in enumerate(m['aulas'], 1):
            video = a.get('video') or a.get('youtube') or a.get('video_url')
            itens.append({
                'id': 'cdd-%s-%d' % (mid, n), 'titulo': limpar_titulo(a['titulo']), 'descricao': DESCRICOES.get(video, ''), 'tipo': 'video',
                'url': ('https://www.youtube.com/watch?v=' + video) if video else '', 'duracao': '', 'data': '',
                'capa': '%s%s-aula-%d.jpg%s' % (BASE_CAPAS, mid, n, VERSAO), 'publicado': not a.get('rascunho'), 'visibilidade': None, 'criado_em': CRIADO,
            })
        qtd[mid] = len(itens)
        saida.append({'id': mid, 'titulo': titulo, 'descricao': desc, 'emoji': emoji, 'cor': cor, 'capa': BASE_CAPAS + mid + '.jpg' + VERSAO,
                      'publicado': True, 'visibilidade': {'modo': 'ativos', 'ids': []}, 'itens': itens})
    js = json.dumps(saida, ensure_ascii=False, indent=1)
    assert 'JSON_FIM' not in js
    php = ('<?php\n/**\n * GERADO por tools/extras/gerar-catalogo.py a partir do seed do CDD. Não edite à mão: rode o gerador de novo.\n'
           ' * Módulos que viram Extras da Intus (Cintura de Atleta, Treinos em casa, Assista antes de treinar, Lives e aulas exclusivas).\n'
           ' * Aula que o CDD guardava como rascunho entra como rascunho. Capas em app/painel/extras/capas (tools/extras/gerar-capas.py).\n */\n'
           'function extras_catalogo_cdd(): array {\n    static $c = null;\n    if ($c === null) $c = json_decode(<<<\'JSON_FIM\'\n' + js + '\nJSON_FIM\n, true);\n    return $c;\n}\n')
    open(os.path.join(RAIZ, 'app', 'api', '_extras_cdd.php'), 'w', encoding='utf-8', newline='\n').write(php)
    json.dump(qtd, open(os.path.join(RAIZ, 'tools', 'extras', 'quantidades.json'), 'w', encoding='utf-8'), indent=1)
    rasc = sum(1 for m in saida for i in m['itens'] if not i['publicado'])
    print('módulos:', len(saida), '| aulas:', sum(qtd.values()), '| como rascunho:', rasc)


if __name__ == '__main__':
    main()
