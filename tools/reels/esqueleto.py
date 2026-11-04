# esqueleto.py <edicao.json> <transcricao.json> <pasta intus-cut/claude> [--video original.mov --saida fonte.mp4]
# Transforma uma edição feita pelo Claude num projeto editável do Intus Cut (sem vídeo).
# O resultado vai para intus-cut/claude/<id>.json e entra em intus-cut/claude/index.json.
# Mesmo id = mesmo projeto: se o conteúdo mudou, a versão sobe e o Intus Cut oferece substituir o projeto 'cl-<id>'.
# Id novo = projeto novo. O vídeo fica no Drive do Luiz (compartilhado por link) e é copiado pelo servidor ao trazer.
#
# Com --video/--saida (modo recorte): grava um MP4 H.264 (abre em qualquer navegador, inclusive o Chrome do PC, que não lê
# o HEVC do iPhone) só com os trechos usados, com MARGEM s de folga em cada ponta, e o esqueleto passa a apontar para esse
# arquivo (fonte.arquivo). O Claude manda o arquivo ao Luiz pela conversa; no Intus Cut, "Trazer agora" pede o arquivo,
# confere o tamanho e sobe ao Drive da Intus. Nenhum vídeo vai para o repositório.
#
# edicao.json: {id, nome, resumo, gancho, destaque[], t_gancho, fonte:{drive_id, nome, dur, w, h, codec ('hevc' = vídeo do iPhone)},
#               trechos:[{ini, fim, legenda:bool, etiqueta:'A'|'M'|null}], notas_dy, final:{...}}
import sys, json, os, hashlib, datetime, subprocess

E = json.load(open(sys.argv[1])); TR = json.load(open(sys.argv[2])); PASTA = sys.argv[3]
ARGS = sys.argv[4:]
VIDEO = ARGS[ARGS.index('--video') + 1] if '--video' in ARGS else None
SAIDA = ARGS[ARGS.index('--saida') + 1] if '--saida' in ARGS else None
MARGEM = 1.0
DUR_ORIG = float(E['fonte']['dur'])
# trechos do vídeo original que entram no arquivo de recorte (em ordem cronológica, juntando os que se encostam)
FAIXAS = []
if VIDEO:
    for a, b in sorted((max(0, t['ini'] - MARGEM), min(DUR_ORIG, t['fim'] + MARGEM)) for t in E['trechos']):
        if FAIXAS and a <= FAIXAS[-1][1] + .05: FAIXAS[-1][1] = max(FAIXAS[-1][1], b)
        else: FAIXAS.append([a, b])
def mapa(t):
    if not VIDEO: return t
    o = 0.0
    for a, b in FAIXAS:
        if a - 1e-6 <= t <= b + 1e-6: return o + min(max(t, a), b) - a
        o += b - a
    raise SystemExit('tempo %.2f fora do recorte' % t)
R = lambda t: round(mapa(t), 2)
ETQ = {'A': ('ALONGAMENTO · ESTÁTICO', 'branco'), 'M': ('MOBILIDADE · DINÂMICO', 'verde')}
ETQ.update({k: tuple(v) for k, v in E.get('etiquetas', {}).items()})
palavras, notas = [], []
for t in E['trechos']:
    if t.get('legenda'):
        for s in TR:
            for w in s['palavras']:
                if t['ini'] <= w['i'] < t['fim'] and w['p'].strip():
                    palavras.append({'p': w['p'].strip(), 'i': R(w['i']), 'f': R(min(w['f'], t['fim']))})
    if t.get('etiqueta'):
        txt, cor = ETQ[t['etiqueta']]
        notas.append({'ini': R(t['ini']), 'fim': R(t['fim'] - .01), 'texto': txt, 'cor': cor})
palavras.sort(key=lambda w: w['i'])
dur = sum(b - a for a, b in FAIXAS) if VIDEO else DUR_ORIG
proj = {
    'id': E['id'], 'nome': E['nome'], 'formato': E.get('formato', 'reel'),
    'gancho': E['gancho'], 'destaque': E.get('destaque', []), 't_gancho': E.get('t_gancho', 1.6),
    'trechos': [{'ini': R(t['ini']), 'fim': R(t['fim']), 'cheia': True} for t in E['trechos']],
    'palavras': palavras,
    'planos': [{'ini': 0, 'fim': round(dur + 1, 2), 'cx': 960, 'larg': 1920, 'y': 0, 'tarja': True}],
    'notas': notas, 'textos': [], 'imagens': [], 'sombras': [], 'camadas': [],
    'audio': {'voz': 1, 'musica': 0},
    'estilo': {'cores': ['amarelo', 'verde'], 'tamanho': 84, 'maiusculas': True, 'palavras_por_grupo': 3},
    'final': E.get('final', {'ativo': True, 'chamada': 'SIGA PARA MAIS DICAS', 'slogan': 'Te levamos ao próximo nível. Do iniciante ao atleta.'}),
    'layout': {'notas': {'dx': 0, 'dy': E.get('notas_dy', 250), 'escala': 1}},
}
FONTE = {k: E['fonte'][k] for k in ('drive_id', 'nome', 'dur', 'w', 'h', 'codec') if k in E['fonte']}
if VIDEO:
    n = len(FAIXAS)
    fil = ''.join('[0:v]trim=%.3f:%.3f,setpts=PTS-STARTPTS[v%d];[0:a]atrim=%.3f:%.3f,asetpts=PTS-STARTPTS[a%d];' % (a, b, i, a, b, i) for i, (a, b) in enumerate(FAIXAS))
    fil += ''.join('[v%d][a%d]' % (i, i) for i in range(n)) + 'concat=n=%d:v=1:a=1[vc][ac];[vc]fps=30,scale=%d:%d,format=yuv420p[vo]' % (n, E['fonte']['w'], E['fonte']['h'])
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-i', VIDEO, '-filter_complex', fil, '-map', '[vo]', '-map', '[ac]',
                    '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-maxrate', '9M', '-bufsize', '18M', '-profile:v', 'high', '-g', '60',
                    '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', SAIDA], check=True)
    FONTE = {'arquivo': os.path.basename(SAIDA), 'tamanho': os.path.getsize(SAIDA), 'nome': os.path.basename(SAIDA),
             'dur': round(dur, 2), 'w': E['fonte']['w'], 'h': E['fonte']['h'], 'codec': 'h264',
             'original': {k: E['fonte'][k] for k in ('drive_id', 'nome') if k in E['fonte']},
             'recorte': [[round(a, 2), round(b, 2)] for a, b in FAIXAS]}
    print('recorte:', SAIDA, round(dur, 1), 's,', round(FONTE['tamanho'] / 1048576, 1), 'MB,', n, 'trechos')
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
