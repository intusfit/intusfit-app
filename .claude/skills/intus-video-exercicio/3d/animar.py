"""Renderiza os quadros 3D de um roteiro e grava meta.json (cena, fase, respiração, juntas na tela).

  python animar.py <pasta b3d> <roteiro.json> <pasta saída> [--fps 30] [--so 0,45,90] [--amostras 12]
"""
import sys, os, json, math, time, bpy
sys.path.insert(0, sys.argv[sys.argv.index('--') + 1] if '--' in sys.argv else sys.argv[1])
args = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else sys.argv[1:]
import cena, pose
from mathutils import Vector
from bpy_extras.object_utils import world_to_camera_view

PASTA, ROT, SAIDA = args[0], args[1], args[2]
opt = lambda n, d: args[args.index(n) + 1] if n in args else d
FPS = int(opt('--fps', 30)); SO = opt('--so', None); AMOSTRAS = int(opt('--amostras', 12))
LARG, ALT = [int(x) for x in opt('--tam', '1200x750').split('x')]
R = json.load(open(ROT, encoding='utf-8')); os.makedirs(SAIDA, exist_ok=True)

def suave(x):  # aceleração e desaceleração naturais
    x = max(0.0, min(1.0, x)); return x * x * x * (x * (x * 6 - 15) + 10)
def mistura(a, b, k):
    out = {}
    for key in set(a) | set(b):
        va, vb = a.get(key, pose.PADRAO.get(key)), b.get(key, pose.PADRAO.get(key))
        if va is None or vb is None: out[key] = vb if k > .5 else va
        elif isinstance(va, (int, float)): out[key] = va + (vb - va) * k
        else: out[key] = vb if k > .5 else va
    return out

# ---- linha do tempo: para cada instante, pose, câmera e o que a tela mostra
P = R['poses']; CAM = R['camera']
segs = []; t0 = 0.0
for c in R['cenas']:
    if c['tipo'] == 'demo':
        dur = sum(p[1] for p in c['passos'])
    else:
        dur = c['dur']
    segs.append((t0, dur, c)); t0 += dur
DURACAO = t0

def no_tempo(t):
    for i, (ini, dur, c) in enumerate(segs):
        if t < ini + dur or i == len(segs) - 1:
            return i, t - ini, dur, c

def estado(t):
    i, tl, dur, c = no_tempo(t)
    cam = dict(CAM); info = {'cena': i, 'tipo': c['tipo'], 'tl': round(tl, 3), 'dur': dur}
    resp = .5 + .5 * math.sin(2 * math.pi * t / 4.6)        # respiração de fundo, sempre
    micro = {'tronco': .35 * math.sin(t * 1.3), 'cabecaInc': 1.2 * math.sin(t * .9 + 1)}
    if c['tipo'] == 'intro':
        p = dict(P[c['pose']]); k = suave(tl / dur)
        for kk in ('az', 'el', 'dist'): cam[kk] = c['cam'][kk] + (CAM[kk] - c['cam'][kk]) * k
    elif c['tipo'] == 'demo':
        acc = 0.0; ant = c['passos'][0][0]
        for j, ps in enumerate(c['passos']):
            nome, d, txt = ps[0], ps[1], ps[2]; extra = ps[3] if len(ps) > 3 else {}
            if tl < acc + d or j == len(c['passos']) - 1:
                k = suave((tl - acc) / (min(d, 1.6) if nome != ant else 1e-9))
                p = mistura(P[ant], P[nome], k if nome != ant else 1)
                info.update(passo=j, fase=txt, progresso=round(min(1, (tl - acc) / d), 3))
                if extra.get('respira'):
                    fase_r = ((tl - acc) % 5.0) / 5.0                   # 2,2 s inspira, 2,8 s solta
                    resp = suave(fase_r / .44) if fase_r < .44 else 1 - suave((fase_r - .44) / .56)
                    info['respira'] = 'inspira' if fase_r < .44 else 'expira'
                    info['respK'] = round(fase_r, 3)
                break
            acc += d; ant = nome
        info['dica'] = min(len(c['dicas']) - 1, int(tl / dur * len(c['dicas'])))
    elif c['tipo'] == 'erro':
        # 0–15%: vai para o erro; 15–55%: segura errado; 55–70%: corrige; 70–100%: certo
        f = tl / dur
        if f < .15: k = suave(f / .15)
        elif f < .55: k = 1
        elif f < .70: k = 1 - suave((f - .55) / .15)
        else: k = 0
        p = mistura(P[c['de']], P[c['pose']], k)
        info['estado'] = 'errado' if f < .62 else 'certo'; info['k'] = round(k, 3)
    else:
        p = dict(P[c['pose']])
    for kk, v in micro.items(): p[kk] = p.get(kk, pose.PADRAO[kk]) + v
    p['resp'] = resp; info['resp'] = round(resp, 3)
    return p, cam, info

# ---- cena
bpy.ops.wm.read_factory_settings(use_empty=True)
h, rig, alt = cena.criar_humano(R.get('macros')); camobj, col = cena.cenario(LARG, ALT); pose.preparar(rig)
sc = bpy.context.scene; sc.cycles.samples = AMOSTRAS; sc.cycles.adaptive_threshold = .02
sc.render.use_persistent_data = True; sc.cycles.max_bounces = 4

COT0 = None
MALHAS = [o for o in bpy.data.objects if o.type == 'MESH' and o.parent == rig]
def assentar(p):
    """Antebraço preso no chão (cotovelo parado); gira o corpo até o ponto mais baixo das pernas tocar o chão."""
    global COT0
    def erro(d):
        pose.aplicar(rig, p, d, fixos=('antebraco',))
        cot = pose.ponto(rig, 'lowerarm01.L')
        dedo = pose.ponto(rig, 'toe3-2.L').z - .018; joe = pose.ponto(rig, 'lowerleg01.L').z - .05
        return min(dedo, joe) - (cot.z - .055), cot
    lo, hi = -30.0, 30.0; elo, _ = erro(lo)
    for _ in range(22):
        mid = (lo + hi) / 2; e, _c = erro(mid)
        if (e > 0) == (elo > 0): lo, elo = mid, e
        else: hi = mid
    e, cot = erro((lo + hi) / 2)
    if COT0 is None: COT0 = Vector((0, cot.y - rig.location.y, 0))
    rig.location = rig.location + Vector((0, -cot.y, .055 - cot.z)); bpy.context.view_layer.update()

JUNTAS = {'ombro': ('upperarm01', 'head'), 'quadril': ('upperleg01', 'head'), 'joelho': ('lowerleg01', 'head'),
          'tornozelo': ('foot', 'head'), 'cotovelo': ('lowerarm01', 'head'), 'cabeca': ('head', 'tail')}
def juntas():
    out = {}
    for n, (b, onde) in JUNTAS.items():
        nomes = [b] if b == 'head' else [b + '.L', b + '.R']
        w = sum((pose.ponto(rig, x, onde) for x in nomes), Vector()) / len(nomes)
        v = world_to_camera_view(sc, camobj, w)
        out[n] = [round(v.x, 4), round(1 - v.y, 4)]
    return out

quadros = int(math.ceil(DURACAO * FPS))
lista = [int(x) for x in SO.split(',')] if SO else range(quadros)
meta = {'duracao': DURACAO, 'fps': FPS, 'tam': [LARG, ALT], 'quadros': quadros, 'cenas': [s[2]['tipo'] for s in segs], 'q': {}}
mp = os.path.join(SAIDA, 'meta.json')
if os.path.exists(mp) and SO is None:
    try: meta['q'] = json.load(open(mp))['q']
    except Exception: pass
t_ini = time.time()
for n, qi in enumerate(lista):
    arq = os.path.join(SAIDA, 'q%05d.png' % qi)
    t = qi / FPS; p, cam, info = estado(t)
    if os.path.exists(arq) and SO is None and str(qi) in meta['q']: continue
    t1 = time.time()
    for o in MALHAS: o.hide_viewport = True          # sem avaliar a malha enquanto procura a pose
    rig.location = (0, 0, 0); bpy.context.view_layer.update()
    assentar(p)
    for o in MALHAS: o.hide_viewport = False
    info['tpose'] = round(time.time() - t1, 2)
    cena.mirar(camobj, CAM['alvo'], cam['dist'], cam['az'], cam['el'])
    bpy.context.view_layer.update()
    info['j'] = juntas(); meta['q'][str(qi)] = info
    sc.render.filepath = arq; bpy.ops.render.render(write_still=True)
    if n % 10 == 0:
        json.dump(meta, open(mp, 'w'))
        el = time.time() - t_ini; print('quadro %d (%d/%d) %.1fs/q pose %.2fs' % (qi, n + 1, len(lista), el / (n + 1), info['tpose']), flush=True)
json.dump(meta, open(mp, 'w'))
print('ok', DURACAO, 's')
