"""Renderiza os clipes curtos de um exercício com a modelo salva no Editor: uma repetição que fecha em
loop ('rep') e um clipe por erro ('erro1', 'erro2'...).

  python clipes.py <pasta 3d> <modelo salvo.json> <roteiro 3d.json> <saida> [--so rep:0,erro1:10] [--fps 24]

Roteiro 3d (3d/roteiros/*-clipes.json):
  camera {alvo, dist, az, el}; contato: 'antebraco' (prancha) | 'pes' (em pé) | 'quadril' (deitada/sentada,
  com 'quadril' = [y, z] do centro da bacia) | 'joelhos_maos' (rodinha: joelhos no chão, mãos na rodinha);
  equipamentos: lista de 'halteres', 'barra', 'rodinha', 'banco', 'colchonete';
  poses {nome: ângulos (ver pose.py)}; clipes {nome: {dur, chaves: [[fração 0-1, pose, {resp, ex}], ...]}}.
  As chaves são cíclicas (a última deve repetir a primeira): o clipe fecha em loop.
"""
import sys, os, json, math, time, bpy
args = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else sys.argv[1:]
sys.path.insert(0, args[0]); import cena, pose, modelo
from mathutils import Vector, Matrix
SALVO = json.load(open(args[1], encoding='utf-8')); R = json.load(open(args[2], encoding='utf-8')); SAIDA = args[3]
opt = lambda k, d: args[args.index(k) + 1] if k in args else d
FPS = int(opt('--fps', 24)); SO = opt('--so', None); AMOSTRAS = int(opt('--amostras', 12))
P = R['poses']; CONTATO = R.get('contato', 'antebraco'); EQ = R.get('equipamentos', [])

def suave(x): x = max(0., min(1., x)); return x * x * (3 - 2 * x)
def mistura(a, b, k):
    out = {}
    for key in set(a) | set(b):
        base = key.split('_')[0] if key.endswith(('_L', '_R')) else key
        va = a.get(key, a.get(base, pose.PADRAO.get(base))); vb = b.get(key, b.get(base, pose.PADRAO.get(base)))
        if isinstance(va, (int, float)) and isinstance(vb, (int, float)): out[key] = va + (vb - va) * k
        else: out[key] = vb if k > .5 else va
    return out

bpy.ops.wm.read_factory_settings(use_empty=True)
AP = json.loads(json.dumps(SALVO.get('aparencia') or {}))
if R.get('cabelo'):      # exercício de cabeça para baixo: o roteiro pode prender o cabelo (ex.: coque_alto)
    AP.setdefault('cabelo', {})['estilo'] = R['cabelo']
h, rig, m = modelo.criar_modelo(SALVO.get('valores'), aparencia=AP or None); pose.preparar(rig)
cam, col = cena.cenario(1280, 720)
sc = bpy.context.scene; sc.cycles.samples = AMOSTRAS; sc.render.use_persistent_data = True; sc.cycles.max_bounces = 4
E = m['escala']

def mat(nome, cor, rough=.4, metal=0.):
    mm = bpy.data.materials.new(nome); mm.use_nodes = True; b = mm.node_tree.nodes['Principled BSDF']
    b.inputs['Base Color'].default_value = (*modelo.srgb(cor), 1); b.inputs['Roughness'].default_value = rough; b.inputs['Metallic'].default_value = metal
    return mm
def cilindro(r, comp, m_, nome):
    bpy.ops.mesh.primitive_cylinder_add(radius=r, depth=comp, vertices=40); o = bpy.context.object; o.name = nome
    o.data.materials.append(m_); bpy.ops.object.shade_smooth(); return o
def juntar(objs, nome):
    bpy.ops.object.select_all(action='DESELECT')
    for o in objs: o.select_set(True)
    bpy.context.view_layer.objects.active = objs[0]; bpy.ops.object.join(); objs[0].name = nome; return objs[0]

aco = mat('aco', '#9a9ca0', .25, 1.); borracha = mat('borracha', '#161617', .6); grip = mat('grip', '#2a2a2c', .7)
equip = {}
if 'colchonete' in EQ:
    col.hide_render = False; c_ = R.get('colchonete', [0, .55, .62, 1.9]); col.location = (c_[0], c_[1], .006); col.scale = (c_[2], c_[3], .012)
if 'halteres' in EQ:
    for lado in ('L', 'R'):
        raio = R.get('halter_raio', .048)                  # halter sextavado de uns 5 kg
        partes = [cilindro(.015, .12, grip, 'cabo')]
        for s_ in (-1, 1):
            bpy.ops.mesh.primitive_cylinder_add(radius=raio, depth=.045, vertices=6); o = bpy.context.object
            o.data.materials.append(borracha); o.location.z = s_ * .078; o.rotation_euler.z = math.radians(30); partes.append(o)
        hb = juntar(partes, 'halter.' + lado); equip['halter.' + lado] = hb
if 'barra' in EQ:
    partes = [cilindro(.014, 2.0, aco, 'barra')]
    for s_ in (-1, 1):
        o = cilindro(.225, .06, borracha, 'anilha'); o.location.z = s_ * .78; partes.append(o)
        o = cilindro(.026, .3, aco, 'manga'); o.location.z = s_ * .86; partes.append(o)
    equip['barra'] = juntar(partes, 'barra')
if 'rodinha' in EQ:
    partes = [cilindro(.09, .06, borracha, 'roda'), cilindro(.012, .42, aco, 'eixo')]
    for s_ in (-1, 1):
        o = cilindro(.016, .1, grip, 'pegador'); o.location.z = s_ * .16; partes.append(o)
    equip['rodinha'] = juntar(partes, 'rodinha')
if 'banco' in EQ:
    b_ = R.get('banco', [0, 0, .44, .32, 1.1])     # x, y do centro, altura, largura, comprimento
    bpy.ops.mesh.primitive_cube_add(size=1); bn = bpy.context.object; bn.name = 'banco'
    bn.scale = (b_[3], b_[4], .06); bn.location = (b_[0], b_[1], b_[2] - .03); bn.data.materials.append(mat('estofado', '#1c1c1e', .55))
    bv = bn.modifiers.new('b', 'BEVEL'); bv.width = .015; bv.segments = 4
    bpy.ops.mesh.primitive_cube_add(size=1); pe_ = bpy.context.object; pe_.scale = (.06, .9 * b_[4], b_[2] - .06); pe_.location = (b_[0], b_[1], (b_[2] - .06) / 2)
    pe_.data.materials.append(aco)

for _n in filter(None, os.environ.get('ESCONDER', '').split(',')):
    for _o in bpy.data.objects:
        if _o.name.startswith(_n): _o.hide_render = True; print('escondido', _o.name)
OCULTAR = [o for o in bpy.data.objects if (o.type in ('MESH', 'CURVES', 'CURVE') and o.parent is not None) or o.name.startswith('cabelo')]

def mao(lado):
    """centro da palma (pegada), no mundo"""
    a = pose.ponto(rig, 'finger2-1.' + lado); b = pose.ponto(rig, 'finger5-1.' + lado); w = pose.ponto(rig, 'wrist.' + lado)
    return (a + b) / 2 * .6 + w * .4
def eixo_mao(lado):
    return (pose.ponto(rig, 'finger2-1.' + lado) - pose.ponto(rig, 'finger5-1.' + lado)).normalized()

_BUMBUM = None
def bumbum_z():
    """z mais baixo (mundo) da pele da bacia e do alto das coxas, com a pose atual (só a armadura deformando)"""
    global _BUMBUM
    if _BUMBUM is None:
        gs = {h.vertex_groups[n].index for n in ('pelvis.L', 'pelvis.R', 'upperleg01.L', 'upperleg01.R', 'root') if n in h.vertex_groups}
        corpo = h.vertex_groups['body'].index
        _BUMBUM = [v.index for v in h.data.vertices if any(e.group == corpo for e in v.groups)
                   and sum(e.weight for e in v.groups if e.group in gs) > .5]
    vis = h.hide_viewport; h.hide_viewport = False
    outros = [(md, md.show_viewport) for md in h.modifiers if md.type != 'ARMATURE']
    for md, _ in outros: md.show_viewport = False
    bpy.context.view_layer.update()
    dg = bpy.context.evaluated_depsgraph_get(); ev = h.evaluated_get(dg); me = ev.to_mesh()
    mw = ev.matrix_world; z = min((mw @ me.vertices[i].co).z for i in _BUMBUM)
    ev.to_mesh_clear()
    for md, v in outros: md.show_viewport = v
    h.hide_viewport = vis
    return z

def comprimento_braco():
    return sum(rig.data.bones[n].length for n in ('upperarm01.L', 'upperarm02.L', 'lowerarm01.L', 'lowerarm02.L')) * E

def assentar(p):
    if CONTATO == 'antebraco':
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
        rig.location = rig.location + Vector((0, -cot.y, .055 - cot.z))
    elif CONTATO == 'pes':
        pose.aplicar(rig, p)
        a = (pose.ponto(rig, 'foot.L') + pose.ponto(rig, 'foot.R')) / 2
        zmin = min(pose.ponto(rig, 'foot.L').z, pose.ponto(rig, 'foot.R').z)
        rig.location = rig.location + Vector((-a.x, -a.y, m['ztorn'] * E - zmin))
    elif CONTATO == 'quadril':
        pose.aplicar(rig, p)
        q = pose.ponto(rig, 'root'); y0, z0 = R['quadril']
        rig.location = rig.location + Vector((-q.x, y0 - q.y, z0 - q.z))
        if 'apoio_z' in R:          # o ponto mais baixo do bumbum encosta no apoio (colchonete ou banco)
            bpy.context.view_layer.update()
            rig.location.z += R['apoio_z'] - bumbum_z()
    elif CONTATO == 'joelhos_maos':
        pose.aplicar(rig, p)
        j = pose.ponto(rig, 'lowerleg01.L'); jr = pose.ponto(rig, 'lowerleg01.R')
        t = Vector((-(j.x + jr.x) / 2, -j.y, .05 - j.z))
        S = pose.ponto(rig, 'upperarm01.L') + t; La = comprimento_braco() * .97; zh = R.get('mao_z', .1)
        a = math.degrees(math.acos(max(-1, min(1, (zh - S.z) / La))))
        p = dict(p, braco=a, antebraco=a)
        pose.aplicar(rig, p); rig.location = rig.location + t
    bpy.context.view_layer.update()

def equipamentos():
    if 'halteres' in EQ:
        for lado in ('L', 'R'):
            o = equip['halter.' + lado]; c = mao(lado); ax = eixo_mao(lado)
            o.matrix_world = Matrix.Translation(c) @ Vector((0, 0, 1)).rotation_difference(ax).to_matrix().to_4x4()
    if 'barra' in EQ or 'rodinha' in EQ:
        cL, cR = mao('L'), mao('R'); c = (cL + cR) / 2
        o = equip['barra' if 'barra' in EQ else 'rodinha']
        if 'rodinha' in EQ: c = Vector((c.x, c.y, .09))
        o.matrix_world = Matrix.Translation(c) @ Vector((0, 0, 1)).rotation_difference(Vector((1, 0, 0))).to_matrix().to_4x4()

_MOCAP = {}
def estado_mocap(c, t):
    """clipe com movimento capturado de vídeo real: braços e inclinação do tronco vêm do arquivo, o resto da pose base"""
    arq = os.path.join(os.path.dirname(os.path.abspath(args[2])), c['mocap'])
    if arq not in _MOCAP: _MOCAP[arq] = json.load(open(arq))
    M = _MOCAP[arq]; n = M['n']; x = (t * M['fps']) % n; i = int(x); j = (i + 1) % n; k = x - i
    if 'angulos' in M:      # vídeo filmado de lado (real/mocap_lado.py): ângulos já no formato da pose
        A = {nome: lst[i] + (lst[j] - lst[i]) * k for nome, lst in M['angulos'].items()}
        p = dict(P[c['pose']])
        for nome in ('braco_L', 'antebraco_L', 'braco_R', 'antebraco_R'):
            if nome in A: p[nome] = A[nome]
        med = sum(M['angulos']['tronco']) / n
        p['tronco'] = p.get('tronco', 0) + (A['tronco'] - med) * c.get('tronco_ganho', 1.0)
        # giro do antebraço pelo quanto o cotovelo dobrou: [ângulo embaixo, ângulo em cima, polegar embaixo, polegar em cima]
        for lado, regra in (c.get('polegar') or {}).items():
            if isinstance(regra, list):
                a0, a1, p0, p1 = regra; u = max(0.0, min(1.0, (A['antebraco_' + lado] - a0) / (a1 - a0)))
                p['polegar_' + lado] = p0 + (p1 - p0) * suave(u)
            else: p['polegar_' + lado] = regra
        f = (t / c['dur']) % 1.0; w = 2 * math.pi * f
        resp = .5 + .5 * math.cos(w) if 'inspira' not in c else (suave(f / c['inspira']) if f < c['inspira'] else 1 - suave((f - c['inspira']) / (1 - c['inspira'])))
        ex = {kk: v[0] + (v[1] - v[0]) * (.5 - .5 * math.cos(w)) for kk, v in c.get('expressao', {}).items()}
        p['resp'] = resp; return p, ex
    dirs = {}
    for nome, lst in M['dirs'].items():
        v = Vector(lst[i]).lerp(Vector(lst[j]), k); dirs[nome] = list(v.normalized())
    p = dict(P[c['pose']]); p['_dir'] = {kk: v for kk, v in dirs.items() if kk != 'tronco'}
    tr = Vector(dirs['tronco']); med = Vector(M.get('tronco_medio') or [0, 0, 1])
    p['tronco'] = p.get('tronco', 0) + (math.degrees(math.atan2(-tr.y, tr.z)) - math.degrees(math.atan2(-med.y, med.z))) * c.get('tronco_ganho', 1.0)
    f = (t / c['dur']) % 1.0; w = 2 * math.pi * f
    resp = .5 + .5 * math.cos(w) if 'inspira' not in c else (suave(f / c['inspira']) if f < c['inspira'] else 1 - suave((f - c['inspira']) / (1 - c['inspira'])))
    ex = {kk: v[0] + (v[1] - v[0]) * (.5 - .5 * math.cos(w)) for kk, v in c.get('expressao', {}).items()}
    p['resp'] = resp; return p, ex

def estado(clipe, t):
    c = R['clipes'][clipe]; dur = c['dur']; f = (t / dur) % 1.0
    if 'mocap' in c: return estado_mocap(c, t)
    ch = c.get('chaves') or [[0, c['pose'], {}], [1, c['pose'], {}]]
    for a, b in zip(ch, ch[1:]):
        if a[0] <= f <= b[0]:
            k = suave((f - a[0]) / max(b[0] - a[0], 1e-6)); p = mistura(P[a[1]], P[b[1]], k)
            xa = a[2] if len(a) > 2 else {}; xb = b[2] if len(b) > 2 else {}
            resp = xa.get('resp', .5) + (xb.get('resp', .5) - xa.get('resp', .5)) * k
            exs = set(xa.get('ex', {})) | set(xb.get('ex', {}))
            ex = {e: xa.get('ex', {}).get(e, 0) + (xb.get('ex', {}).get(e, 0) - xa.get('ex', {}).get(e, 0)) * k for e in exs}
            break
    w = 2 * math.pi * f
    p['tronco'] = p.get('tronco', 0) + c.get('balanco', .35) * math.sin(w); p['cabecaInc'] = p.get('cabecaInc', 0) + 1.2 * math.sin(w + 1)
    if 'inspira' in c and not c.get('chaves'):          # clipe parado: respiração pelo tempo
        ins = c['inspira']; resp = suave(f / ins) if f < ins else 1 - suave((f - ins) / (1 - ins))
        ex = {k: v[0] + (v[1] - v[0]) * (.5 - .5 * math.cos(w)) for k, v in c.get('expressao', {}).items()}
    p['resp'] = resp
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
    modelo.expressao(h, ex); bpy.context.view_layer.update(); m['cabelo'].atualizar(); equipamentos()
    cena.mirar(cam, cm['alvo'], cm['dist'], cm['az'], cm['el'])
    if os.environ.get('PONTA'):
        from mathutils.bvhtree import BVHTree
        dg = bpy.context.evaluated_depsgraph_get(); hb = h.evaluated_get(dg)
        bt = BVHTree.FromObject(hb, dg); sh = bpy.data.objects[os.environ['PONTA']]; se = sh.evaluated_get(dg); me = se.to_mesh()
        ds = sorted(((bt.find_nearest(hb.matrix_world.inverted() @ (se.matrix_world @ v.co))[3], v.index, tuple(round(c, 3) for c in se.matrix_world @ v.co)) for v in me.vertices), reverse=True)[:5]
        print('PONTA', ds, len(me.vertices), len(sh.data.vertices)); se.to_mesh_clear()
        v = sh.data.vertices[140]; print('V140', tuple(round(c, 3) for c in v.co), sorted(((round(e.weight, 3), sh.vertex_groups[e.group].name) for e in v.groups), reverse=True)[:6])
        import bmesh as _b; bm = _b.new(); bm.from_mesh(sh.data); bm.verts.ensure_lookup_table(); print('V140 borda', bm.verts[140].is_boundary, [round((e.other_vert(bm.verts[140]).co - bm.verts[140].co).length, 3) for e in bm.verts[140].link_edges]); bm.free()
    sc.render.filepath = arq; bpy.ops.render.render(write_still=True)
    if 'SONDA' in os.environ:
        print('sonda', clipe, i, {n: [round(v, 3) for v in pose.ponto(rig, n)] for n in os.environ['SONDA'].split(',')})
    print('quadro %s %d (%d/%d) %.1fs/q' % (clipe, i, n_ + 1, len(quadros), (time.time() - t0) / (n_ + 1)), flush=True)
print('ok')
