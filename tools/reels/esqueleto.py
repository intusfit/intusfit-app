# esqueleto.py <edicao.json> <transcricao.json> <pasta intus-cut/claude>
# Transforma uma edição feita pelo Claude num projeto editável do Intus Cut (sem vídeo).
# O resultado vai para intus-cut/claude/<id>.json e entra em intus-cut/claude/index.json.
# Mesmo id = mesmo projeto: se o conteúdo mudou, a versão sobe e o Intus Cut oferece substituir o projeto 'cl-<id>'.
# Id novo = projeto novo. O vídeo fica no Drive do Luiz (compartilhado por link) e é copiado pelo servidor ao trazer.
#
# edicao.json: {id, nome, resumo, gancho, destaque[], t_gancho, fonte:{drive_id, nome, dur, w, h, codec ('hevc' = vídeo do iPhone)},
#               trechos:[{ini, fim, legenda:bool, etiqueta:'A'|'M'|null}], notas_dy, final:{...}}
import sys, json, os, hashlib, datetime

E = json.load(open(sys.argv[1])); TR = json.load(open(sys.argv[2])); PASTA = sys.argv[3]
ETQ = {'A': ('ALONGAMENTO · ESTÁTICO', 'branco'), 'M': ('MOBILIDADE · DINÂMICO', 'verde')}
ETQ.update({k: tuple(v) for k, v in E.get('etiquetas', {}).items()})
palavras, notas = [], []
for t in E['trechos']:
    if t.get('legenda'):
        for s in TR:
            for w in s['palavras']:
                if t['ini'] <= w['i'] < t['fim'] and w['p'].strip():
                    palavras.append({'p': w['p'].strip(), 'i': round(w['i'], 2), 'f': round(min(w['f'], t['fim']), 2)})
    if t.get('etiqueta'):
        txt, cor = ETQ[t['etiqueta']]
        notas.append({'ini': round(t['ini'], 2), 'fim': round(t['fim'] - .01, 2), 'texto': txt, 'cor': cor})
palavras.sort(key=lambda w: w['i'])
dur = float(E['fonte']['dur'])
proj = {
    'id': E['id'], 'nome': E['nome'], 'formato': E.get('formato', 'reel'),
    'gancho': E['gancho'], 'destaque': E.get('destaque', []), 't_gancho': E.get('t_gancho', 1.6),
    'trechos': [{'ini': round(t['ini'], 2), 'fim': round(t['fim'], 2), 'cheia': True} for t in E['trechos']],
    'palavras': palavras,
    'planos': [{'ini': 0, 'fim': round(dur + 1, 2), 'cx': 960, 'larg': 1920, 'y': 0, 'tarja': True}],
    'notas': notas, 'textos': [], 'imagens': [], 'sombras': [], 'camadas': [],
    'audio': {'voz': 1, 'musica': 0},
    'estilo': {'cores': ['amarelo', 'verde'], 'tamanho': 84, 'maiusculas': True, 'palavras_por_grupo': 3},
    'final': E.get('final', {'ativo': True, 'chamada': 'SIGA PARA MAIS DICAS', 'slogan': 'Te levamos ao próximo nível. Do iniciante ao atleta.'}),
    'layout': {'notas': {'dx': 0, 'dy': E.get('notas_dy', 250), 'escala': 1}},
}
FONTE = {k: E['fonte'][k] for k in ('drive_id', 'nome', 'dur', 'w', 'h', 'codec') if k in E['fonte']}
corpo = json.dumps(proj, ensure_ascii=False, separators=(',', ':'))
hsh = hashlib.sha256((corpo + json.dumps(FONTE, sort_keys=True)).encode()).hexdigest()[:16]
os.makedirs(PASTA, exist_ok=True)
ix_path = os.path.join(PASTA, 'index.json')
ix = json.load(open(ix_path)) if os.path.exists(ix_path) else {'projetos': []}
ant = next((x for x in ix['projetos'] if x['id'] == E['id']), None)
if ant and ant.get('hash') == hsh:
    print('sem mudança:', E['id'], 'versão', ant['versao']); sys.exit()
open(os.path.join(PASTA, E['id'] + '.json'), 'w').write(corpo)
item = {'id': E['id'], 'nome': E['nome'], 'resumo': E.get('resumo', ''), 'versao': (ant['versao'] + 1) if ant else 1, 'hash': hsh,
        'atualizado_em': datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
        'fonte': FONTE}
ix['projetos'] = [x for x in ix['projetos'] if x['id'] != E['id']] + [item]
json.dump(ix, open(ix_path, 'w'), ensure_ascii=False, indent=1)
print(('atualizado' if ant else 'novo') + ':', E['id'], 'versão', item['versao'], len(palavras), 'palavras', len(notas), 'etiquetas')
