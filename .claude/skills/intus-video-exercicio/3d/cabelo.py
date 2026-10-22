"""Cabelo de fios (curvas de cabelo do Blender, renderizadas pelo Cycles): penteado para trás até um
coque volumoso, e mechas soltas na frente que caem com a gravidade a cada quadro (podem cobrir o rosto)."""
import bpy, math, random
import numpy as np
from mathutils import Vector, Matrix

def material_cabelo():
    m = bpy.data.materials.new('fios'); m.use_nodes = True; nt = m.node_tree; nt.nodes.clear()
    out = nt.nodes.new('ShaderNodeOutputMaterial'); hb = nt.nodes.new('ShaderNodeBsdfHairPrincipled')
    hb.parametrization = 'MELANIN'
    hb.inputs['Melanin'].default_value = .95; hb.inputs['Melanin Redness'].default_value = .18
    hb.inputs['Roughness'].default_value = .32; hb.inputs['Radial Roughness'].default_value = .4
    hb.inputs['Random Roughness'].default_value = .2; hb.inputs['Random Color'].default_value = .12
    nt.links.new(hb.outputs[0], out.inputs['Surface']); return m

def _curvas(nome, strands, raio_raiz=.0007, raio_ponta=.0003):
    cv = bpy.data.hair_curves.new(nome)
    n = len(strands[0]); cv.add_curves([n] * len(strands))
    pos = np.array(strands, dtype=np.float32).reshape(-1, 3)
    cv.position_data.foreach_set('vector', pos.ravel())
    if 'radius' not in cv.attributes: cv.attributes.new('radius', 'FLOAT', 'POINT')
    r = np.tile(np.linspace(raio_raiz, raio_ponta, n, dtype=np.float32), len(strands))
    cv.attributes['radius'].data.foreach_set('value', r)
    ob = bpy.data.objects.new(nome, cv); bpy.context.scene.collection.objects.link(ob)
    return ob

def _prender(o, rig):
    m_ = o.matrix_world.copy(); o.parent = rig; o.parent_type = 'BONE'; o.parent_bone = 'head'
    bpy.context.view_layer.update(); o.matrix_world = m_

def criar(h, rig, co, couro, bun, mat=None, n_fios=26000, semente=7):
    """co: vértices em repouso; couro: índices dos vértices do couro cabeludo; bun: centro do coque."""
    rnd = random.Random(semente); rng = np.random.default_rng(semente)
    mat = mat or material_cabelo()
    P = np.array([[co[i].x, co[i].y, co[i].z] for i in couro])
    c = P.mean(0); c[2] -= .045                               # centro aproximado do crânio
    D = P - c; R = np.linalg.norm(D, axis=1); Dn = D / R[:, None]
    # superfície lisa da cabeça: elipsoide ajustado aos vértices do couro (sem degraus da malha)
    M = D ** 2; eixos2 = np.linalg.lstsq(M, np.ones(len(M)), rcond=None)[0]
    eixos2 = np.clip(eixos2, 1e-3, None)
    def raio_cranio(d):                    # média dos raios reais vizinhos (suave e sem inflar as laterais)
        w = np.exp((Dn @ d - 1) / .012); return float((w @ R) / max(w.sum(), 1e-9))
    b = np.array([bun.x, bun.y, bun.z]); rb = .058
    # faces do couro cabeludo para sortear raízes proporcionalmente à área
    S = set(couro); tris = []
    for p in h.data.polygons:
        vs = list(p.vertices)
        if all(v in S for v in vs):
            for k in range(1, len(vs) - 1): tris.append((vs[0], vs[k], vs[k + 1]))
    A = np.array([np.array(co[t[0]]), np.array(co[t[1]]), np.array(co[t[2]])] for t in tris) if False else None
    T = np.array([[[co[t[j]].x, co[t[j]].y, co[t[j]].z] for j in range(3)] for t in tris])
    area = np.linalg.norm(np.cross(T[:, 1] - T[:, 0], T[:, 2] - T[:, 0]), axis=1) / 2
    escolha = rng.choice(len(tris), size=n_fios, p=area / area.sum())
    u = rng.random(n_fios); v = rng.random(n_fios); troca = u + v > 1; u[troca] = 1 - u[troca]; v[troca] = 1 - v[troca]
    raiz = T[escolha, 0] + (T[escolha, 1] - T[escolha, 0]) * u[:, None] + (T[escolha, 2] - T[escolha, 0]) * v[:, None]
    K = 16; fios = []
    db = (b - c) / np.linalg.norm(b - c)
    for i in range(n_fios):
        p0 = raiz[i]; d0 = (p0 - c) / np.linalg.norm(p0 - c)
        ang = math.acos(max(-1, min(1, float(d0 @ db))))
        eixo = np.cross(d0, db); en = np.linalg.norm(eixo); eixo = eixo / en if en > 1e-6 else np.array([1., 0, 0])
        vol = .003 + .005 * rnd.random(); jit = rng.normal(0, .0012, 3); camada = .002 + .006 * rnd.random()
        pts = []
        for k in range(K):
            s = k / (K - 1)
            th = ang * (s ** .85)
            d = d0 * math.cos(th) + np.cross(eixo, d0) * math.sin(th) + eixo * (eixo @ d0) * (1 - math.cos(th))
            rr = raio_cranio(d) + camada * min(1, s * 6) + vol * math.sin(math.pi * min(1, s * 1.15))
            p = c + d * rr + jit * s
            if s > .82:                                     # entra no coque
                w = (s - .82) / .18; alvo = b + (p - b) / np.linalg.norm(p - b) * rb * .8
                p = p * (1 - w) + alvo * w
            pts.append(p if k else p0)
        fios.append(pts)
    # coque: voltas de fios em espiral, com bagunça
    for i in range(int(n_fios * .45)):
        eixo = rng.normal(0, 1, 3); eixo /= np.linalg.norm(eixo)
        a1 = np.cross(eixo, [0, 0, 1.]); a1 /= max(np.linalg.norm(a1), 1e-6); a2 = np.cross(eixo, a1)
        rr = rb * (.45 + .55 * rnd.random()); fase = rnd.random() * 6.28; voltas = .9 + .6 * rnd.random()
        pts = []
        for k in range(K):
            t = fase + voltas * 6.283 * k / (K - 1)
            p = b + (a1 * math.cos(t) + a2 * math.sin(t)) * rr * (1 + .08 * math.sin(3 * t)) + rng.normal(0, .002, 3)
            pts.append(p)
        fios.append(pts)
    ob = _curvas('cabelo', fios); ob.data.materials.append(mat); _prender(ob, rig)
    # núcleo escuro do coque e do couro (sem buracos entre os fios)
    bpy.ops.mesh.primitive_uv_sphere_add(radius=rb * .62, location=tuple(b), segments=24, ring_count=12)
    nucleo = bpy.context.object; nucleo.name = 'coque-nucleo'
    nm = bpy.data.materials.new('nucleo'); nm.use_nodes = True
    nm.node_tree.nodes['Principled BSDF'].inputs['Base Color'].default_value = (.012, .007, .004, 1)
    nucleo.data.materials.append(nm); _prender(nucleo, rig)
    return ob, c, b

class Mechas:
    """Mechas soltas nas têmporas, cada uma um cacho de fios agrupados. Recalculadas a cada quadro: a
    raiz acompanha a cabeça e o resto cai com a gravidade, sem atravessar o crânio (esfera aproximada)."""
    def __init__(self, rig, co, couro, centro, mat, semente=11, por_lado=6, fios=22, comp=.15):
        rnd = random.Random(semente); self.rig = rig; self.c = Vector(centro)
        P = [co[i] for i in couro]
        frente = sorted(P, key=lambda v: v.y)[:max(12, len(P) // 5)]   # linha do cabelo (rosto olha para -Y)
        zmed = sorted(v.z for v in frente)[len(frente) // 2]
        frente = [v for v in frente if v.z <= zmed + .01]                  # parte de baixo: têmporas
        self.guias = []
        for lado in (1, -1):
            cand = [v for v in frente if .035 < v.x * lado < .075] or frente
            for _ in range(por_lado):
                r = rnd.choice(cand) + Vector((rnd.gauss(0, .004), rnd.gauss(0, .003), rnd.gauss(0, .005)))
                d = Vector((lado * (.08 + .12 * rnd.random()), .05 + .15 * rnd.random(), -1)).normalized()
                L = comp * (.7 + .5 * rnd.random()); fase = rnd.random() * 6.28; amp = .003 + .004 * rnd.random()
                offs = [(rnd.gauss(0, .0045), rnd.gauss(0, .0045)) for _ in range(fios)]
                self.guias.append((r, d, L, fase, amp, offs))
        self.K = 14; dist = sorted((v - self.c).length for v in P); self.R = dist[len(dist) // 3]
        n = sum(len(g[5]) for g in self.guias)
        self.ob = _curvas('mechas', [[tuple(self.c)] * self.K for _ in range(n)], .0007, .00025)
        self.ob.data.materials.append(mat)
        self.atualizar()
    def atualizar(self):
        pb = self.rig.pose.bones['head']
        T = self.rig.matrix_world @ pb.matrix @ pb.bone.matrix_local.inverted()
        c = T @ self.c; g = Vector((0, 0, -1)); R3 = T.to_3x3(); pos = []
        lat = (R3 @ Vector((1, 0, 0))).normalized()
        for r, d0, L, fase, amp, offs in self.guias:
            p = T @ r; d = (R3 @ d0).normalized(); seg = L / (self.K - 1); guia = [p]
            for k in range(1, self.K):
                d = (d * .8 + g * .2).normalized()
                p = p + d * seg + lat * amp * (math.sin(fase + k * .8) - math.sin(fase + (k - 1) * .8))
                q = p - c
                if k > 2 and q.length < self.R: p = c + q.normalized() * self.R     # não entra na cabeça
                guia.append(p)
            for ox, oy in offs:                                           # fios do cacho, juntando na ponta
                for k, p in enumerate(guia):
                    t = guia[min(k + 1, self.K - 1)] - guia[max(k - 1, 0)]
                    a = t.cross(lat).normalized() if t.length > 1e-6 else Vector((0, 1, 0))
                    b = t.cross(a).normalized() if t.length > 1e-6 else lat
                    k2 = 1 - .65 * k / (self.K - 1)
                    pos.append(p + (a * ox + b * oy) * k2)
        self.ob.data.position_data.foreach_set('vector', np.array([tuple(v) for v in pos], dtype=np.float32).ravel())
        self.ob.data.update_tag()
