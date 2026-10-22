"""Monta a cena 3D: corpo MakeHuman com esqueleto, roupa por região, chão, luzes e câmera."""
import bpy, addon_utils, importlib, math
from mathutils import Vector

def carregar_mpfb():
    addon_utils.enable('bl_ext.user_default.mpfb', default_set=True)
    hs = importlib.import_module('bl_ext.user_default.mpfb.services.humanservice').HumanService
    ts = importlib.import_module('bl_ext.user_default.mpfb.services.targetservice').TargetService
    return hs, ts

def mat(nome, cor, rough=.5, sss=0.0, sheen=0.0, metal=0.0, coat=0.0):
    m = bpy.data.materials.new(nome); m.use_nodes = True
    b = m.node_tree.nodes['Principled BSDF']
    b.inputs['Base Color'].default_value = (*cor, 1)
    b.inputs['Roughness'].default_value = rough
    b.inputs['Metallic'].default_value = metal
    if sss:
        b.inputs['Subsurface Weight'].default_value = sss
        b.inputs['Subsurface Radius'].default_value = (1.0, .35, .2)
        b.inputs['Subsurface Scale'].default_value = .012
    if sheen: b.inputs['Sheen Weight'].default_value = sheen
    if coat: b.inputs['Coat Weight'].default_value = coat
    return m

def srgb(h):
    h = h.lstrip('#'); c = [int(h[i:i+2], 16)/255 for i in (0, 2, 4)]
    return tuple(((x+0.055)/1.055)**2.4 if x > 0.04045 else x/12.92 for x in c)

def roupa_material(zcintura, zombro, ztorn, zjoelho):
    m = bpy.data.materials.new('corpo'); m.use_nodes = True; nt = m.node_tree; N = nt.nodes; L = nt.links
    N.clear(); out = N.new('ShaderNodeOutputMaterial')
    def attr(n):
        a = N.new('ShaderNodeAttribute'); a.attribute_name = n; return a.outputs['Fac']
    def faixa(x, a, b, suave=.006):
        """1 dentro de [a, b], bordas suaves."""
        r1 = N.new('ShaderNodeMapRange'); r1.interpolation_type = 'SMOOTHSTEP'
        L.new(x, r1.inputs['Value']); r1.inputs['From Min'].default_value = a - suave; r1.inputs['From Max'].default_value = a + suave
        r2 = N.new('ShaderNodeMapRange'); r2.interpolation_type = 'SMOOTHSTEP'
        L.new(x, r2.inputs['Value']); r2.inputs['From Min'].default_value = b + suave; r2.inputs['From Max'].default_value = b - suave
        mm = N.new('ShaderNodeMath'); mm.operation = 'MULTIPLY'; L.new(r1.outputs[0], mm.inputs[0]); L.new(r2.outputs[0], mm.inputs[1])
        return mm.outputs[0]
    def mult(a, b):
        mm = N.new('ShaderNodeMath'); mm.operation = 'MULTIPLY'
        (L.new(a, mm.inputs[0]) if not isinstance(a, float) else None); (L.new(b, mm.inputs[1]) if not isinstance(b, float) else None)
        return mm.outputs[0]
    # um BSDF só, com as entradas misturadas por região (vários BSDFs estouram o limite do Cycles)
    b = N.new('ShaderNodeBsdfPrincipled')
    b.inputs['Subsurface Radius'].default_value = (1, .45, .3); b.inputs['Subsurface Scale'].default_value = .005
    def mixc(fac, a, c2):
        x = N.new('ShaderNodeMix'); x.data_type = 'RGBA'; L.new(fac, x.inputs['Factor'])
        (L.new(a, x.inputs['A']) if not isinstance(a, tuple) else setattr(x.inputs['A'], 'default_value', (*a, 1)))
        x.inputs['B'].default_value = (*c2, 1); return x.outputs['Result']
    def mixf(fac, a, v2):
        x = N.new('ShaderNodeMix'); x.data_type = 'FLOAT'; L.new(fac, x.inputs['Factor'])
        (L.new(a, x.inputs['A']) if not isinstance(a, float) else setattr(x.inputs['A'], 'default_value', a))
        x.inputs['B'].default_value = v2; return x.outputs['Result']
    z = attr('zr'); bx = attr('br')
    semBraco = N.new('ShaderNodeMath'); semBraco.operation = 'LESS_THAN'; L.new(bx, semBraco.inputs[0]); semBraco.inputs[1].default_value = .35
    z1 = zcintura + .07; z2 = zombro - .05
    top = mult(faixa(z, z1, z2), semBraco.outputs[0])
    friso = mult(faixa(z, z1, z1 + .012, .002), semBraco.outputs[0])
    tenis = faixa(z, -1, ztorn + .012, .004)
    zleg = ztorn + (zjoelho - ztorn) * .3
    legging = mult(faixa(z, zleg, zcintura + .025, .004), semBraco.outputs[0])
    friso2 = mult(faixa(z, zcintura + .012, zcintura + .025, .002), semBraco.outputs[0])
    roupa = N.new('ShaderNodeMath'); roupa.operation = 'MAXIMUM'; L.new(top, roupa.inputs[0]); L.new(legging, roupa.inputs[1])
    cor = srgb('#9c6c50')
    for fac, c in ((top, srgb('#17191e')), (legging, srgb('#121317')), (friso, srgb('#7fff00')), (friso2, srgb('#7fff00')), (tenis, srgb('#e6e6e2'))):
        cor = mixc(fac, cor, c)
    L.new(cor, b.inputs['Base Color'])
    L.new(mixf(roupa.outputs[0], .5, .64), b.inputs['Roughness'])
    L.new(mixf(roupa.outputs[0], .12, 0.0), b.inputs['Subsurface Weight'])
    L.new(mixf(roupa.outputs[0], 0.0, .22), b.inputs['Sheen Weight'])
    sh = b.outputs[0]
    L.new(sh, out.inputs['Surface'])
    return m

def criar_humano(macros=None):
    hs, ts = carregar_mpfb()
    m = ts.get_default_macro_info_dict()
    m.update(dict(gender=0.0, muscle=0.7, weight=0.48, height=0.5, proportions=0.8, age=0.45))
    if macros: m.update(macros)
    h = hs.create_human(macro_detail_dict=m)
    rig = hs.add_builtin_rig(h, 'default')
    # vértices em repouso já com o formato do corpo (shape keys aplicadas)
    for md in h.modifiers: md.show_viewport = False
    dg = bpy.context.evaluated_depsgraph_get(); ev = h.evaluated_get(dg); me = ev.to_mesh()
    co = [h.matrix_world @ v.co for v in me.vertices]
    ev.to_mesh_clear()
    for md in h.modifiers: md.show_viewport = True
    grupos = {g.index: g.name for g in h.vertex_groups}
    vg = [set() for _ in co]
    for v in h.data.vertices:
        for g in v.groups:
            if g.weight > 0.3: vg[v.index].add(grupos[g.group])
    def z_de(nome):
        ids = [i for i, s in enumerate(vg) if nome in s]
        return sum(co[i].z for i in ids)/len(ids), ids
    zjoelho, _ = z_de('joint-l-knee'); ztorn, _ = z_de('joint-l-ankle')
    zombro, _ = z_de('joint-l-shoulder'); zpelve, _ = z_de('joint-pelvis')
    zcintura = zpelve + (zombro - zpelve) * 0.36
    altura = max(c.z for c in co)
    # visíveis: corpo, legging (helper-tights), cabelo (helper-hair) e olhos
    vis = h.vertex_groups.new(name='visivel')
    keep = {'body', 'helper-l-eye', 'helper-r-eye'}
    # a "tights" do MakeHuman é um macacão inteiro: fica só a parte de baixo (legging até a cintura)
    vis.add([i for i, s in enumerate(vg) if (s & keep)], 1.0, 'REPLACE')
    for md in h.modifiers:
        if md.type == 'MASK': md.vertex_group = 'visivel'
    # Roupa desenhada no shader a partir da posição de repouso de cada vértice (atributos que
    # acompanham a deformação): bordas suaves em vez do recorte por face.
    nome_braco = ('shoulder01', 'upperarm', 'lowerarm', 'wrist', 'finger', 'metacarpal', 'clavicle')
    zr = h.data.attributes.new('zr', 'FLOAT', 'POINT'); ax = h.data.attributes.new('ax', 'FLOAT', 'POINT')
    br = h.data.attributes.new('br', 'FLOAT', 'POINT')
    gnome = {g.index: g.name for g in h.vertex_groups}
    for v in h.data.vertices:
        zr.data[v.index].value = co[v.index].z
        ax.data[v.index].value = abs(co[v.index].x)
        br.data[v.index].value = min(1.0, sum(g.weight for g in v.groups if gnome[g.group].startswith(nome_braco)))
    cabelo = mat('cabelo', srgb('#1a120d'), rough=.42, sheen=.6, coat=.2)
    olho = mat('olho', srgb('#1c1410'), rough=.08, coat=1)
    leg = mat('legging', srgb('#1b1d22'), rough=.62, sheen=.4)
    corpo = roupa_material(zcintura, zombro, ztorn, zjoelho)
    h.data.materials.clear()
    for mm in (corpo, leg, cabelo, olho): h.data.materials.append(mm)
    idx = {m.name: i for i, m in enumerate(h.data.materials)}
    for p in h.data.polygons:
        vs = p.vertices; c = sum((co[i] for i in vs), Vector()) / len(vs)
        g = set.intersection(*[vg[i] for i in vs]) if vs else set()
        gu = set().union(*[vg[i] for i in vs])
        if 'helper-tights' in gu and 'body' not in gu: p.material_index = idx['legging']
        elif 'scalp' in g: p.material_index = idx['cabelo']
        elif ('helper-l-eye' in gu or 'helper-r-eye' in gu) and 'body' not in gu: p.material_index = idx['olho']
        else: p.material_index = idx['corpo']
        p.use_smooth = True
    sub = h.modifiers.new('suave', 'SUBSURF'); sub.levels = 1; sub.render_levels = 1
    # coque: esfera presa no osso da cabeça
    ids = [i for i, s in enumerate(vg) if 'scalp' in s]
    topo = max((co[i] for i in ids), key=lambda v: v.z)
    tras = max((co[i] for i in ids), key=lambda v: v.y)
    bpy.ops.mesh.primitive_uv_sphere_add(radius=.052, location=(0, tras.y + .01, topo.z - .045), segments=32, ring_count=16)
    coque = bpy.context.object; coque.scale = (1, .9, .85); coque.data.materials.append(cabelo)
    for p in coque.data.polygons: p.use_smooth = True
    m_ = coque.matrix_world.copy(); coque.parent = rig; coque.parent_type = 'BONE'; coque.parent_bone = 'head'
    bpy.context.view_layer.update(); coque.matrix_world = m_
    return h, rig, altura

def cenario(largura=1280, alturapx=720):
    sc = bpy.context.scene
    sc.render.engine = 'CYCLES'; sc.cycles.device = 'CPU'
    sc.cycles.samples = 40; sc.cycles.use_denoising = True; sc.cycles.denoiser = 'OPENIMAGEDENOISE'
    sc.cycles.max_bounces = 6
    sc.render.resolution_x = largura; sc.render.resolution_y = alturapx; sc.render.resolution_percentage = 100
    sc.view_settings.view_transform = 'AgX'; sc.view_settings.look = 'AgX - Medium High Contrast'
    sc.render.film_transparent = False
    w = bpy.data.worlds.new('mundo'); sc.world = w; w.use_nodes = True
    w.node_tree.nodes['Background'].inputs['Color'].default_value = (*srgb('#131313'), 1)
    w.node_tree.nodes['Background'].inputs['Strength'].default_value = .5
    # chão e colchonete
    bpy.ops.mesh.primitive_plane_add(size=40, location=(0, 0, 0)); chao = bpy.context.object
    chao.data.materials.append(mat('chao', srgb('#1a1a1a'), rough=.85))
    bpy.ops.mesh.primitive_cube_add(size=1, location=(0, 0, .006)); col = bpy.context.object
    col.scale = (.62, 1.9, .012); col.name = 'colchonete'
    col.data.materials.append(mat('colchonete', srgb('#2c3a24'), rough=.9))
    bev = col.modifiers.new('b', 'BEVEL'); bev.width = .004; bev.segments = 3
    col.hide_render = True
    def luz(nome, tipo, loc, energia, tam, cor='#ffffff'):
        d = bpy.data.lights.new(nome, tipo); d.energy = energia; d.color = srgb(cor)
        if tipo == 'AREA': d.size = tam
        o = bpy.data.objects.new(nome, d); sc.collection.objects.link(o); o.location = loc
        o.rotation_euler = (Vector((0, 0, 1)).rotation_difference((Vector(loc) - Vector((0, 0, .9))).normalized())).to_euler()
        return o
    luz('chave', 'AREA', (3.2, -2.6, 3.4), 900, 2.4, '#fff4ea')
    luz('preench', 'AREA', (-3.4, -2.2, 1.6), 220, 3, '#e8f0ff')
    luz('recorte', 'AREA', (-1.6, 3.4, 2.6), 650, 1.6, '#f4fff0')
    cam = bpy.data.objects.new('cam', bpy.data.cameras.new('cam')); sc.collection.objects.link(cam); sc.camera = cam
    cam.data.lens = 50
    return cam, col

def mirar(cam, alvo, dist, az, el):
    """Câmera olhando o alvo de uma distância, ângulo azimutal (0 = de lado, vista do lado direito do aluno) e elevação."""
    a = math.radians(az); e = math.radians(el)
    pos = Vector(alvo) + Vector((math.cos(e)*math.cos(a), math.cos(e)*math.sin(a), math.sin(e))) * dist
    cam.location = pos
    cam.rotation_euler = (Vector(alvo) - pos).to_track_quat('-Z', 'Y').to_euler()
