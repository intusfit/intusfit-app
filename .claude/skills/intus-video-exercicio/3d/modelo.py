"""A modelo da Intus: corpo MakeHuman (MPFB) com esqueleto, sobrancelhas, olhos, coque, top cropped
(abdômen e costas à mostra), short solto e tênis. Uma função só: criar_modelo() -> (malha, esqueleto, medidas)."""
import bpy, bmesh, addon_utils, importlib, math, os
from mathutils import Vector

def srgb(h):
    h = h.lstrip('#'); c = [int(h[i:i+2], 16)/255 for i in (0, 2, 4)]
    return tuple(((x+0.055)/1.055)**2.4 if x > 0.04045 else x/12.92 for x in c)

PELE = '#b07650'; ROUPA = '#0e0e10'; DESTAQUE = '#7fff00'; TENIS = '#dcdcd8'; CABELO = '#1a100a'

# ---------- montagem de shader por expressão (bordas nítidas a partir da posição de repouso) ----------
class Nos:
    def __init__(self, mat):
        self.nt = mat.node_tree; self.N = self.nt.nodes; self.L = self.nt.links
    def attr(self, n):
        a = self.N.new('ShaderNodeAttribute'); a.attribute_name = n; return a.outputs['Fac']
    def op(self, oper, a, b=None, c=None):
        m = self.N.new('ShaderNodeMath'); m.operation = oper
        for i, v in enumerate((a, b, c)):
            if v is None: continue
            if isinstance(v, (int, float)): m.inputs[i].default_value = v
            else: self.L.new(v, m.inputs[i])
        return m.outputs[0]
    def entre(self, x, a, b, s=.003):
        """1 dentro de [a, b] com borda suave de largura s (a e b podem ser nós)."""
        lo = self.op('SUBTRACT', x, a); hi = self.op('SUBTRACT', b, x)
        f = lambda d: self.op('MINIMUM', self.op('MAXIMUM', self.op('DIVIDE', self.op('ADD', d, s), 2 * s), 0.0), 1.0)
        return self.op('MULTIPLY', f(lo), f(hi))
    def menor(self, x, a, s=.003): return self.entre(x, -9, a, s)
    def maior(self, x, a, s=.003): return self.entre(x, a, 9, s)
    def e(self, *xs):
        r = xs[0]
        for x in xs[1:]: r = self.op('MULTIPLY', r, x)
        return r
    def ou(self, *xs):
        r = xs[0]
        for x in xs[1:]: r = self.op('MAXIMUM', r, x)
        return r
    def mixc(self, fac, a, cor):
        x = self.N.new('ShaderNodeMix'); x.data_type = 'RGBA'; self.L.new(fac, x.inputs['Factor'])
        if isinstance(a, tuple): x.inputs['A'].default_value = (*a, 1)
        else: self.L.new(a, x.inputs['A'])
        x.inputs['B'].default_value = (*cor, 1); return x.outputs['Result']
    def mixf(self, fac, a, v):
        x = self.N.new('ShaderNodeMix'); x.data_type = 'FLOAT'; self.L.new(fac, x.inputs['Factor'])
        if isinstance(a, float): x.inputs['A'].default_value = a
        else: self.L.new(a, x.inputs['A'])
        x.inputs['B'].default_value = v; return x.outputs['Result']

def material_corpo(m):
    mat = bpy.data.materials.new('corpo'); mat.use_nodes = True; n = Nos(mat); n.N.clear()
    out = n.N.new('ShaderNodeOutputMaterial'); b = n.N.new('ShaderNodeBsdfPrincipled')
    b.inputs['Subsurface Radius'].default_value = (1, .45, .3); b.inputs['Subsurface Scale'].default_value = .005
    z, ax, y, br, lb, hs = n.attr('zr'), n.attr('ax'), n.attr('yr'), n.attr('br'), n.attr('lb'), n.attr('hs')
    corpo = n.menor(br, .35, .05)
    frente = n.menor(y, m['yc'], .004); costas = n.op('SUBTRACT', 1.0, frente)
    z1 = m['zbusto']; zo = m['zombro']
    # frente: do busto ao decote (decote em U: mais baixo no meio) + alças até o ombro
    decote = n.op('ADD', zo - .088, n.op('MULTIPLY', n.op('MINIMUM', ax, .062), .55))
    top_f = n.e(frente, n.entre(z, z1, decote, .002))
    alca = n.e(frente, n.entre(ax, .049, .081, .002), n.entre(z, z1, zo + .11, .002))
    ombro = alca
    # costas: faixa embaixo + nadador (estreito no meio das costas, abre para os ombros)
    faixa = n.e(costas, n.entre(z, z1, z1 + .042, .002))
    # nadador: um V que sai do meio das costas e chega na alça em cima do ombro
    nad_c = n.op('MINIMUM', n.op('ADD', .006, n.op('MULTIPLY', n.op('MAXIMUM', n.op('SUBTRACT', z, zo - .10), 0.0), .47)), .065)
    nadador = n.e(costas, n.entre(ax, n.op('SUBTRACT', nad_c, .016), n.op('ADD', nad_c, .016), .002), n.entre(z, z1, zo + .11, .002))
    centro = n.e(costas, n.menor(ax, .022, .002), n.entre(z, z1, zo - .09, .002))
    top = n.e(corpo, n.ou(top_f, alca, ombro, faixa, nadador, centro))
    friso = n.e(corpo, n.entre(z, z1, z1 + .008, .0015))
    short = n.e(corpo, n.entre(z, m['zbarra'], m['zcintura'] + .03, .0015))
    barra = n.e(corpo, n.entre(z, m['zbarra'], m['zbarra'] + .006, .001))
    cos = n.e(corpo, n.entre(z, m['zcintura'] + .006, m['zcintura'] + .03, .0015))
    tenis = n.menor(z, m['ztorn'] + .012, .003)
    sola = n.menor(z, .014, .002)
    cor = srgb(PELE)
    cor = n.mixc(lb, cor, srgb('#8e4f43'))
    for fac, c in ((top, srgb(ROUPA)), (short, srgb(ROUPA)), (cos, srgb('#1c1c1f')), (friso, srgb(DESTAQUE)), (barra, srgb(DESTAQUE)),
                   (tenis, srgb(TENIS)), (sola, srgb('#2b2b2b'))):
        cor = n.mixc(fac, cor, c)
    cabelo = n.maior(hs, .5, .06)
    cor = n.mixc(cabelo, cor, srgb(CABELO))
    roupa = n.ou(top, short)
    n.L.new(cor, b.inputs['Base Color'])
    n.L.new(n.mixf(cabelo, n.mixf(roupa, .46, .7), .5), b.inputs['Roughness'])
    n.L.new(n.mixf(n.ou(roupa, tenis, cabelo), .14, 0.0), b.inputs['Subsurface Weight'])
    n.L.new(n.mixf(roupa, 0.0, .08), b.inputs['Sheen Weight'])
    n.L.new(b.outputs[0], out.inputs['Surface'])
    return mat

def material_simples(nome, cor, rough=.5, sheen=0.0, coat=0.0):
    m = bpy.data.materials.new(nome); m.use_nodes = True; b = m.node_tree.nodes['Principled BSDF']
    b.inputs['Base Color'].default_value = (*srgb(cor), 1); b.inputs['Roughness'].default_value = rough
    b.inputs['Sheen Weight'].default_value = sheen; b.inputs['Coat Weight'].default_value = coat
    return m

def material_short(m):
    mat = bpy.data.materials.new('short'); mat.use_nodes = True; n = Nos(mat); n.N.clear()
    out = n.N.new('ShaderNodeOutputMaterial'); b = n.N.new('ShaderNodeBsdfPrincipled')
    z, ax = n.attr('zr'), n.attr('ax')
    barra = n.menor(z, m['zbarra'] + .016, .0015)
    cos = n.entre(z, m['zcintura'] - .005, m['zcintura'] + .03, .002)
    cor = n.mixc(n.ou(barra, cos), srgb(ROUPA), srgb('#1f1f22'))
    cor = n.mixc(n.e(barra, n.menor(z, m['zbarra'] + .007, .001)), cor, srgb(DESTAQUE))
    n.L.new(cor, b.inputs['Base Color']); b.inputs['Roughness'].default_value = .8; b.inputs['Sheen Weight'].default_value = .1
    n.L.new(b.outputs[0], out.inputs['Surface']); return mat

def material_olho(raio):
    mat = bpy.data.materials.new('olho'); mat.use_nodes = True; n = Nos(mat); n.N.clear()
    out = n.N.new('ShaderNodeOutputMaterial'); b = n.N.new('ShaderNodeBsdfPrincipled')
    tc = n.N.new('ShaderNodeTexCoord'); sp = n.N.new('ShaderNodeSeparateXYZ'); n.L.new(tc.outputs['Object'], sp.inputs[0])
    fr = n.op('DIVIDE', n.op('MULTIPLY', sp.outputs['Y'], -1.0), raio)        # 1 = centro da frente
    cor = n.mixc(n.maior(fr, .80, .02), srgb('#e9e4dc'), srgb('#4a2e1c'))
    cor = n.mixc(n.maior(fr, .955, .008), cor, srgb('#0b0706'))
    n.L.new(cor, b.inputs['Base Color']); b.inputs['Roughness'].default_value = .25; b.inputs['Coat Weight'].default_value = 1
    n.L.new(b.outputs[0], out.inputs['Surface']); return mat

def _prender_na_cabeca(o, rig):
    m_ = o.matrix_world.copy(); o.parent = rig; o.parent_type = 'BONE'; o.parent_bone = 'head'
    bpy.context.view_layer.update(); o.matrix_world = m_

def criar_modelo():
    addon_utils.enable('bl_ext.user_default.mpfb', default_set=True)
    base = 'bl_ext.user_default.mpfb'
    hs = importlib.import_module(base + '.services.humanservice').HumanService
    ts = importlib.import_module(base + '.services.targetservice').TargetService
    dados = os.path.join(os.path.dirname(importlib.import_module(base).__file__), 'data', 'targets')
    mac = ts.get_default_macro_info_dict()
    mac.update(dict(gender=0.0, age=0.42, muscle=0.8, weight=0.56, height=0.55, proportions=1.0, cupsize=0.5, firmness=0.85))
    h = hs.create_human(macro_detail_dict=mac)
    # glúteo treinado, abdômen firme, cintura fina
    for cat, nome, peso in (('buttocks', 'buttocks-volume-incr', 1.0), ('hip', 'hip-scale-depth-incr', .55),
                            ('hip', 'hip-scale-horiz-incr', .25), ('pelvis', 'pelvis-tone-incr', .7),
                            ('stomach', 'stomach-tone-incr', .6), ('torso', 'measure-waist-circ-decr', .45),
                            ('stomach', 'stomach-navel-in', .5)):
        ts.load_target(h, os.path.join(dados, cat, nome + '.target.gz'), weight=peso)
    rig = hs.add_builtin_rig(h, 'default')
    for md in h.modifiers: md.show_viewport = False
    dg = bpy.context.evaluated_depsgraph_get(); ev = h.evaluated_get(dg); me = ev.to_mesh()
    co = [h.matrix_world @ v.co for v in me.vertices]; ev.to_mesh_clear()
    for md in h.modifiers: md.show_viewport = True
    gn = {g.index: g.name for g in h.vertex_groups}
    vg = [set() for _ in co]; pesos = [dict() for _ in co]
    for v in h.data.vertices:
        for g in v.groups:
            pesos[v.index][gn[g.group]] = g.weight
            if g.weight > 0.3: vg[v.index].add(gn[g.group])
    def media(nome):
        ids = [i for i, s in enumerate(vg) if nome in s]; return sum((co[i] for i in ids), Vector()) / len(ids), ids
    m = {}
    m['zjoelho'] = media('joint-l-knee')[0].z; m['ztorn'] = media('joint-l-ankle')[0].z
    m['zombro'] = media('joint-l-shoulder')[0].z; zp = media('joint-pelvis')[0].z
    m['zcintura'] = zp + (m['zombro'] - zp) * .34; m['zpelve'] = zp
    m['zbusto'] = m['zombro'] - .165
    m['zbarra'] = m['zjoelho'] + (zp - m['zjoelho']) * .58
    tronco = [co[i] for i, s in enumerate(vg) if 'body' in s and m['zbusto'] < co[i].z < m['zombro'] and abs(co[i].x) < .12]
    m['yc'] = (min(c.y for c in tronco) + max(c.y for c in tronco)) / 2
    m['altura'] = max(c.z for c in co)
    olho = media('helper-l-eye')[0]; olho_z, olho_y = olho.z, olho.y
    cabeca = [co[i] for i, s_ in enumerate(vg) if 'scalp' in s_]
    orelha_y = (min(c.y for c in cabeca) + max(c.y for c in cabeca)) / 2 + .025   # atrás da orelha
    # atributos de repouso para o shader
    braco = ('upperarm', 'lowerarm', 'wrist', 'finger', 'metacarpal')   # ombro/clavícula ficam com o tronco (alças do top)
    A = {k: h.data.attributes.new(k, 'FLOAT', 'POINT') for k in ('zr', 'yr', 'ax', 'br', 'lb', 'hs')}
    for i, c in enumerate(co):
        A['zr'].data[i].value = c.z; A['yr'].data[i].value = c.y; A['ax'].data[i].value = abs(c.x)
        A['br'].data[i].value = min(1.0, sum(w for g, w in pesos[i].items() if g.startswith(braco)))
        A['lb'].data[i].value = pesos[i].get('lips', 0.0)
        # cabelo: couro cabeludo, menos a lateral do rosto (o grupo do MakeHuman desce até a bochecha)
        rosto = c.z < olho_z + .03 and c.y < orelha_y
        A['hs'].data[i].value = 1.0 if pesos[i].get('scalp', 0.0) > .3 and not rosto else 0.0
    # linha do cabelo mais suave: média com os vizinhos (a malha da cabeça é grossa)
    viz = [[] for _ in co]
    for e_ in h.data.edges:
        a_, b_ = e_.vertices; viz[a_].append(b_); viz[b_].append(a_)
    for _ in range(3):
        val = [A['hs'].data[i].value for i in range(len(co))]
        for i in range(len(co)):
            if viz[i] and (val[i] > 0 or any(val[j] > 0 for j in viz[i])):
                A['hs'].data[i].value = .5 * val[i] + .5 * sum(val[j] for j in viz[i]) / len(viz[i])
    vis = h.vertex_groups.new(name='visivel'); vis.add([i for i, s in enumerate(vg) if s & {'body', 'helper-upper-teeth', 'helper-lower-teeth', 'helper-tongue'}], 1.0, 'REPLACE')
    for md in h.modifiers:
        if md.type == 'MASK': md.vertex_group = 'visivel'
    corpo = material_corpo(m); cab = material_simples('cabelo', CABELO, .5, coat=.08)
    dente = material_simples('dente', '#e8e2d6', .35); lingua = material_simples('lingua', '#9c4a4a', .45)
    h.data.materials.clear()
    for mm in (corpo, cab, dente, lingua): h.data.materials.append(mm)
    for p in h.data.polygons:
        p.use_smooth = True
        gu = set().union(*[vg[i] for i in p.vertices])
        p.material_index = 0 if 'body' in gu else (3 if 'helper-tongue' in gu else 2)
    sub = h.modifiers.new('suave', 'SUBSURF'); sub.levels = 1; sub.render_levels = 2

    tn = h.copy(); tn.data = h.data.copy(); tn.name = 'tenis'; bpy.context.scene.collection.objects.link(tn)
    bm = bmesh.new(); bm.from_mesh(tn.data); bm.verts.ensure_lookup_table()
    bmesh.ops.delete(bm, geom=[v for v in bm.verts if not ('body' in vg[v.index] and co[v.index].z < m['ztorn'] + .022)], context='VERTS')
    bm.to_mesh(tn.data); bm.free()
    for md in list(tn.modifiers):
        if md.type in ('MASK', 'SUBSURF'): tn.modifiers.remove(md)
    d = tn.modifiers.new('folga', 'DISPLACE'); d.direction = 'NORMAL'; d.strength = .01; d.mid_level = 0
    so = tn.modifiers.new('borda', 'SOLIDIFY'); so.thickness = .003; so.offset = 1
    s3 = tn.modifiers.new('suave', 'SUBSURF'); s3.levels = 2; s3.render_levels = 2
    mt = bpy.data.materials.new('tenis'); mt.use_nodes = True; nn = Nos(mt); nn.N.clear()
    o_ = nn.N.new('ShaderNodeOutputMaterial'); b_ = nn.N.new('ShaderNodeBsdfPrincipled'); zz = nn.attr('zr')
    cor = nn.mixc(nn.menor(zz, .018, .002), srgb(TENIS), srgb('#26262a'))
    cor = nn.mixc(nn.entre(zz, .018, .024, .001), cor, srgb(DESTAQUE))
    nn.L.new(cor, b_.inputs['Base Color']); b_.inputs['Roughness'].default_value = .6
    nn.L.new(b_.outputs[0], o_.inputs['Surface'])
    tn.data.materials.clear(); tn.data.materials.append(mt)
    for p in tn.data.polygons: p.use_smooth = True
    # ---- olhos (esferas presas na cabeça) e sobrancelhas (fitas curvas)
    for lado in ('l', 'r'):
        c, ids = media('helper-%s-eye' % lado)
        raio = max((co[i] - c).length for i in ids) * .98
        bpy.ops.mesh.primitive_uv_sphere_add(radius=raio, location=c, segments=32, ring_count=20)
        o = bpy.context.object; o.name = 'olho.' + lado; o.data.materials.append(material_olho(raio))
        for p in o.data.polygons: p.use_smooth = True
        _prender_na_cabeca(o, rig)
        s = 1 if c.x > 0 else -1
        face = [co[i] for i, g in enumerate(vg) if 'body' in g and co[i].y < c.y + .03 and abs(co[i].x - c.x) < .035 and c.z < co[i].z < c.z + .04]
        def na_pele(x, z):
            v = min(face, key=lambda q: (q.x - x) ** 2 + (q.z - z) ** 2)
            return Vector((x, v.y - .0012, z))
        pts = [na_pele(c.x - s * .016, c.z + .0165), na_pele(c.x - s * .003, c.z + .0195),
               na_pele(c.x + s * .010, c.z + .0198), na_pele(c.x + s * .021, c.z + .0155)]
        cu = bpy.data.curves.new('sobrancelha.' + lado, 'CURVE'); cu.dimensions = '3D'
        sp = cu.splines.new('BEZIER'); sp.bezier_points.add(len(pts) - 1)
        for bp, p, r in zip(sp.bezier_points, pts, (.85, .95, .7, .25)):
            bp.co = p; bp.handle_left_type = bp.handle_right_type = 'AUTO'; bp.radius = r
        cu.bevel_depth = .0017; cu.bevel_resolution = 2
        ob = bpy.data.objects.new('sobrancelha.' + lado, cu); bpy.context.scene.collection.objects.link(ob)
        ob.data.materials.append(material_simples('sobrancelha', '#1d120c', .7, sheen=.4))
        alvo_v = [min((i for i, g in enumerate(vg) if 'body' in g), key=lambda i: (co[i] - p).length) for p in pts]
        # a sobrancelha vai presa a 3 vértices do rosto: segue a cabeça e o levantar da testa
        tri = alvo_v[0], alvo_v[1], alvo_v[3]
        m_ = ob.matrix_world.copy(); ob.parent = h; ob.parent_type = 'VERTEX_3'; ob.parent_vertices = tri
        bpy.context.view_layer.update(); ob.matrix_world = m_
    # ---- cabelo de fios: penteado para trás num coque volumoso + mechas soltas na frente
    import cabelo
    couro = [i for i in range(len(co)) if A['hs'].data[i].value > .45]
    ids = [i for i, s_ in enumerate(vg) if 'scalp' in s_]
    topo = max((co[i] for i in ids), key=lambda v: v.z); tras = max((co[i] for i in ids), key=lambda v: v.y)
    bun = Vector((0, tras.y + .012, topo.z - .03))
    mfios = cabelo.material_cabelo()
    _, centro, _ = cabelo.criar(h, rig, co, couro, bun, mfios)
    m['mechas'] = cabelo.Mechas(rig, co, couro, centro, mfios)
    m['h'] = h
    return h, rig, m

# ---- expressões do rosto (alvos de unidade facial do MakeHuman, misturados por intensidade)
EXPRESSOES = {
    'neutra': {},
    'feliz': {'mouth-corner-puller': .75, 'mouth-elevation': .15, 'eye-left-slit': .3, 'eye-right-slit': .3,
              'eyebrows-left-up': .15, 'eyebrows-right-up': .15},
    'esforco': {'eyebrows-left-down': .55, 'eyebrows-right-down': .55, 'eye-left-slit': .5, 'eye-right-slit': .5,
                'mouth-compression': .6, 'nose-left-elevation': .35, 'nose-right-elevation': .35, 'mouth-retraction': .25},
    'sofrimento': {'eyebrows-left-inner-up': .85, 'eyebrows-right-inner-up': .85, 'eye-left-closure': .5, 'eye-right-closure': .5,
                   'mouth-depression-retraction': .55, 'mouth-parling': .35, 'nose-left-elevation': .45, 'nose-right-elevation': .45,
                   'neck-platysma': .35},
}
def expressao(h, mistura):
    """mistura: {'feliz': .6, 'esforco': .4} (pesos de 0 a 1). Carrega os alvos na primeira vez."""
    import importlib as _il
    ts = _il.import_module('bl_ext.user_default.mpfb.services.targetservice').TargetService
    base = os.path.join(os.path.dirname(_il.import_module('bl_ext.user_default.mpfb').__file__), 'data', 'targets', 'expression', 'units')
    alvo = {}
    for nome, w in mistura.items():
        for u, v in EXPRESSOES[nome].items(): alvo[u] = alvo.get(u, 0) + v * w
    kb = h.data.shape_keys.key_blocks
    todos = {u for e in EXPRESSOES.values() for u in e}
    for u in todos:
        for raca in ('caucasian', 'african'):
            nk = 'ex-%s-%s' % (raca, u)
            if nk not in kb:
                if alvo.get(u, 0) == 0: continue
                ts.load_target(h, os.path.join(base, raca, u + '.target.gz'), weight=0.0, name=nk)
            kb[nk].value = min(1.0, alvo.get(u, 0)) * .5
