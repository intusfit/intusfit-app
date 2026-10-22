"""Renderiza os clipes curtos de um exercício (uma repetição que fecha em loop + um clipe por erro)
com a modelo salva no Editor.  python clipes.py <pasta 3d> <modelo salvo.json> <roteiro 3d.json> <saida> [--so clipe:quadro,...] [--fps 24]"""
import sys, os, json, math, time, bpy
args = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else sys.argv[1:]
sys.path.insert(0, args[0]); import cena, pose, modelo
from mathutils import Vector
SALVO = json.load(open(args[1], encoding='utf-8')); R = json.load(open(args[2], encoding='utf-8')); SAIDA = args[3]
opt = lambda k, d: args[args.index(k) + 1] if k in args else d
FPS = int(opt('--fps', 24)); SO = opt('--so', None); AMOSTRAS = int(opt('--amostras', 12))
P = R['poses']

def suave(x): x = max(0., min(1., x)); return x * x * (3 - 2 * x)

bpy.ops.wm.read_factory_settings(use_empty=True)
h, rig, m = modelo.criar_modelo(SALVO.get('valores'), aparencia=SALVO.get('aparencia')); pose.preparar(rig)
cam, col = cena.cenario(1280, 720)
sc = bpy.context.scene; sc.cycles.samples = AMOSTRAS; sc.render.use_persistent_data = True; sc.cycles.max_bounces = 4
OCULTAR = [o for o in bpy.data.objects if o.type in ('MESH', 'CURVES', 'CURVE') and o.name not in ('colchonete',) and o.parent is not None or o.name.startswith('cabelo')]

def assentar(p):
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
    rig.location = rig.location + Vector((0, -cot.y, .055 - cot.z)); bpy.context.view_layer.update()

def estado(clipe, t):
    c = R['clipes'][clipe]; dur = c['dur']; p = dict(P[c['pose']]); f = t / dur
    w = 2 * math.pi * f                                     # tudo periódico na duração: o clipe fecha em loop
    p['tronco'] = p.get('tronco', 0) + .35 * math.sin(w); p['cabecaInc'] = p.get('cabecaInc', 0) + 1.2 * math.sin(w + 1)
    ins = c.get('inspira', .44)
    p['resp'] = suave(f / ins) if f < ins else 1 - suave((f - ins) / (1 - ins))
    ex = {k: v[0] + (v[1] - v[0]) * (.5 - .5 * math.cos(w)) for k, v in c.get('expressao', {}).items()}
    return p, ex

cm = R['camera']
quadros = [(cl, i) for cl, c in R['clipes'].items() for i in range(int(round(c['dur'] * FPS)))]
if SO: quadros = [(s.split(':')[0], int(s.split(':')[1])) for s in SO.split(',')]
t0 = time.time()
for n_, (clipe, i) in enumerate(quadros):
    pasta = os.path.join(SAIDA, clipe); os.makedirs(pasta, exist_ok=True)
    arq = os.path.join(pasta, 'q%04d.png' % i)
    if os.path.exists(arq) and not SO: continue
    p, ex = estado(clipe, i / FPS)
    for o in OCULTAR: o.hide_viewport = True
    rig.location = (0, 0, 0); bpy.context.view_layer.update(); assentar(p)
    for o in OCULTAR: o.hide_viewport = False
    modelo.expressao(h, ex); bpy.context.view_layer.update(); m['cabelo'].atualizar()
    cena.mirar(cam, cm['alvo'], cm['dist'], cm['az'], cm['el'])
    sc.render.filepath = arq; bpy.ops.render.render(write_still=True)
    print('quadro %s %d (%d/%d) %.1fs/q' % (clipe, i, n_ + 1, len(quadros), (time.time() - t0) / (n_ + 1)), flush=True)
print('ok')
