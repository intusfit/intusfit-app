"""Cabelo de fios (curvas de cabelo do Blender, renderizadas pelo Cycles).

Estilos (aparência['cabelo']['estilo']): 'coque_alto', 'coque_baixo', 'rabo' (rabo de cavalo) e 'solto'.
- Parte presa: sai do couro cabeludo e acompanha a cabeça até o coque, o elástico do rabo ou a altura
  da orelha (solto). Fica presa ao osso da cabeça.
- Parte livre (rabo, cabelo solto e mechas da frente): recalculada a cada quadro com gravidade, sem
  atravessar a cabeça, o pescoço, o tronco nem o chão. Chame `Cabelo.atualizar()` depois de posar.
"""
import bpy, math, random
import numpy as np
from mathutils import Vector

PADRAO = dict(estilo='coque_alto', cor='#1a100a', comprimento=.5, volume=.5, mechas=.5)

def _rgb(hx):
    hx = hx.lstrip('#'); c = [int(hx[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    return [((x + .055) / 1.055) ** 2.4 if x > .04045 else x / 12.92 for x in c]

def material_cabelo(cor):
    m = bpy.data.materials.new('fios'); m.use_nodes = True; nt = m.node_tree; nt.nodes.clear()
    out = nt.nodes.new('ShaderNodeOutputMaterial'); hb = nt.nodes.new('ShaderNodeBsdfHairPrincipled')
    hb.parametrization = 'COLOR'
    hb.inputs['Color'].default_value = (*_rgb(cor), 1)
    hb.inputs['Roughness'].default_value = .32; hb.inputs['Radial Roughness'].default_value = .4
    hb.inputs['Random Roughness'].default_value = .2
    if 'Random Color' in hb.inputs: hb.inputs['Random Color'].default_value = .1
    nt.links.new(hb.outputs[0], out.inputs['Surface']); return m

def _curvas(nome, n_pts, n_fios, raio_raiz=.0007, raio_ponta=.0003):
    cv = bpy.data.hair_curves.new(nome); cv.add_curves([n_pts] * n_fios)
    if 'radius' not in cv.attributes: cv.attributes.new('radius', 'FLOAT', 'POINT')
    r = np.tile(np.linspace(raio_raiz, raio_ponta, n_pts, dtype=np.float32), n_fios)
    cv.attributes['radius'].data.foreach_set('value', r)
    ob = bpy.data.objects.new(nome, cv); bpy.context.scene.collection.objects.link(ob)
    return ob

def _pos(ob, P):
    ob.data.position_data.foreach_set('vector', np.ascontiguousarray(P, dtype=np.float32).ravel()); ob.data.update_tag()

def _prender(o, rig):
    m_ = o.matrix_world.copy(); o.parent = rig; o.parent_type = 'BONE'; o.parent_bone = 'head'
    bpy.context.view_layer.update(); o.matrix_world = m_

def _slerp(d0, d1, t):
    ang = math.acos(max(-1, min(1, float(d0 @ d1))))
    if ang < 1e-5: return d0
    return (math.sin((1 - t) * ang) * d0 + math.sin(t * ang) * d1) / math.sin(ang)


class Cabelo:
    def __init__(self, h, rig, co, couro, mapa, ap=None, semente=7):
        """co: vértices em repouso; couro: índices do couro cabeludo; mapa: pontos de referência da
        cabeça (dict com 'coque_alto', 'coque_baixo', 'rabo', 'nuca', 'orelha_z')."""
        self.ap = {**PADRAO, **(ap or {})}; self.rig = rig
        rng = np.random.default_rng(semente); self.rng = rng
        est = self.ap['estilo']; vol = float(self.ap['volume']); comp = float(self.ap['comprimento'])
        self.mat = material_cabelo(self.ap['cor'])
        P = np.array([[co[i].x, co[i].y, co[i].z] for i in couro])
        c = P.mean(0); c[2] -= .045; self.c = c
        D = P - c; R = np.linalg.norm(D, axis=1); Dn = D / R[:, None]
        def raio(d): w = np.exp((Dn @ d - 1) / .012); return float((w @ R) / max(w.sum(), 1e-9))
        self.raio = raio
        # raízes sorteadas proporcionalmente à área do couro cabeludo
        S = set(couro); T = []
        for p in h.data.polygons:
            vs = list(p.vertices)
            if sum(v in S for v in vs) >= len(vs) - 1:          # inclui a borda: a linha do cabelo fica coberta
                for k in range(1, len(vs) - 1): T.append([[co[vs[j]].x, co[vs[j]].y, co[vs[j]].z] for j in (0, k, k + 1)])
        T = np.array(T); area = np.linalg.norm(np.cross(T[:, 1] - T[:, 0], T[:, 2] - T[:, 0]), axis=1) / 2
        n_fios = int(18000 + 14000 * vol)
        ch = rng.choice(len(T), n_fios, p=area / area.sum()); u = rng.random(n_fios); v = rng.random(n_fios)
        tr = u + v > 1; u[tr] = 1 - u[tr]; v[tr] = 1 - v[tr]
        raiz = T[ch, 0] + (T[ch, 1] - T[ch, 0]) * u[:, None] + (T[ch, 2] - T[ch, 0]) * v[:, None]
        K = 16; camada = .0015 + (.004 + .008 * vol) * rng.random(n_fios)

        def ate(p0, alvo, s_fim=1.0, entra=None, rb=0.0):
            """fio sobre a cabeça, da raiz p0 em direção ao ponto alvo (ângulo esférico em torno de c)."""
            d0 = (p0 - c) / np.linalg.norm(p0 - c); d1 = (alvo - c) / np.linalg.norm(alvo - c)
            pts = []
            for k in range(K):
                s = k / (K - 1) * s_fim; d = _slerp(d0, d1, s ** .85)
                lev = min(1.0, max(0.0, (s - .12) / .5)); lev = lev * lev * (3 - 2 * lev)      # rente na linha do cabelo, volume depois
                p = c + d * (raio(d) + .0008 + camada_i * lev)
                if entra is not None and s > .8:
                    w = (s - .8) / .2; q = entra + (p - entra) / max(np.linalg.norm(p - entra), 1e-6) * rb * .8; p = p * (1 - w) + q * w
                pts.append(p if k else p0)
            return pts

        presos = []; self.livres = []   # livres: (âncora no espaço da cabeça, direção inicial, comprimento)
        if est in ('coque_alto', 'coque_baixo'):
            b = np.array(mapa[est]); rb = .045 + .025 * vol
            for i in range(n_fios):
                camada_i = camada[i]; presos.append(ate(raiz[i], b, entra=b, rb=rb))
            for i in range(int(n_fios * .45)):                     # voltas do coque
                ei = rng.normal(0, 1, 3); ei /= np.linalg.norm(ei)
                a1 = np.cross(ei, [0, 0, 1.])
                if np.linalg.norm(a1) < .2: a1 = np.cross(ei, [1., 0, 0])
                a1 /= np.linalg.norm(a1); a2 = np.cross(ei, a1)
                rr = rb * (.45 + .55 * rng.random()); fa = rng.random() * 6.28; vt = .9 + .6 * rng.random()
                presos.append([b + (a1 * math.cos(t) + a2 * math.sin(t)) * rr * (1 + .08 * math.sin(3 * t)) + rng.normal(0, .002, 3)
                               for t in (fa + vt * 6.283 * k / (K - 1) for k in range(K))])
            self.nucleo = (b, rb * .62)
        elif est == 'rabo':
            b = np.array(mapa['rabo'])
            for i in range(n_fios):
                camada_i = camada[i]; presos.append(ate(raiz[i], b, s_fim=.97))
            L = .18 + .4 * comp
            for i in range(int(4000 + 3000 * vol)):                # rabo: sai do elástico e cai
                off = rng.normal(0, .007 + .006 * vol, 3)
                self.livres.append((b + off, np.array([0, .35, -1.]) + rng.normal(0, .12, 3), L * (.8 + .3 * rng.random())))
            self.nucleo = (b, .012)
        else:                                                         # solto
            L = .12 + .5 * comp
            for i in range(n_fios):
                camada_i = camada[i]; p0 = raiz[i]; d0 = (p0 - c) / np.linalg.norm(p0 - c)
                # passa por cima e para trás (w1) e desce pelas laterais/nuca (w2): nunca pelo rosto
                w1 = d0 * .5 + np.array([0, .6, .8]); w1 /= np.linalg.norm(w1)
                w2 = np.array([d0[0] * 1.6, .7, -.6]); w2 /= np.linalg.norm(w2)
                if d0[1] > .1 or d0[2] < .3: w1 = d0                 # raiz já atrás/embaixo: vai direto
                pts = []
                for k in range(K):
                    s_ = k / (K - 1)
                    d = _slerp(d0, w1, s_ * 2) if s_ < .5 else _slerp(w1, w2, (s_ - .5) * 2)
                    lev = min(1.0, max(0.0, (s_ - .12) / .5)); lev = lev * lev * (3 - 2 * lev)
                    p = c + d * (raio(d) + .0008 + camada_i * lev)
                    if k > 1 and p[2] < mapa['orelha_z'] - .02: break
                    pts.append(p if k else p0)
                n_reais = len(pts)
                while len(pts) < K: pts.append(pts[-1])
                presos.append(pts)
                if i % 2 == 0 and n_reais >= 3:
                    d = pts[n_reais - 1] - pts[n_reais - 3] + np.array([0, .004, -.01])
                    self.livres.append((pts[-1], d / max(np.linalg.norm(d), 1e-6), L * (.75 + .35 * rng.random())))
            self.nucleo = None
        # mechas soltas nas têmporas (cachos de fios)
        n_m = int(round(10 * float(self.ap['mechas'])))
        if n_m:
            frente = P[np.argsort(P[:, 1])[:max(12, len(P) // 5)]]
            zmed = np.median(frente[:, 2]); frente = frente[frente[:, 2] <= zmed + .01]
            for j in range(n_m):
                lado = 1 if j % 2 else -1
                cand = frente[(frente[:, 0] * lado > .03) & (frente[:, 0] * lado < .075)]
                if not len(cand): cand = frente
                r0 = cand[rng.integers(len(cand))]; Lm = (.1 + .1 * comp) * (.7 + .5 * rng.random())
                d0 = np.array([lado * (.08 + .12 * rng.random()), .05 + .15 * rng.random(), -1.])
                for _ in range(14): self.livres.append((r0 + rng.normal(0, .003, 3), d0, Lm))

        self.presos = _curvas('cabelo', K, len(presos)); _pos(self.presos, np.array(presos))
        self.presos.data.materials.append(self.mat); _prender(self.presos, rig)
        if self.nucleo is not None:
            bpy.ops.mesh.primitive_uv_sphere_add(radius=self.nucleo[1], location=tuple(self.nucleo[0]), segments=24, ring_count=12)
            nu = bpy.context.object; nu.name = 'cabelo-nucleo'
            nm = bpy.data.materials.new('nucleo'); nm.use_nodes = True
            nm.node_tree.nodes['Principled BSDF'].inputs['Base Color'].default_value = (*[x * .35 for x in _rgb(self.ap['cor'])], 1)
            nu.data.materials.append(nm); _prender(nu, rig)
        self.KL = 14
        if self.livres:
            self.A0 = np.array([l[0] for l in self.livres]); self.D0 = np.array([l[1] / np.linalg.norm(l[1]) for l in self.livres])
            self.LL = np.array([l[2] for l in self.livres]); self.ruido = rng.normal(0, .12, (len(self.livres), self.KL, 3))
            self.livre = _curvas('cabelo-solto', self.KL, len(self.livres), .00045, .0002)
            self.livre.data.materials.append(self.mat)
        self.atualizar()

    def _esferas(self, T):
        """corpos que o cabelo solto não atravessa: cabeça, pescoço e tronco (pelo esqueleto atual)."""
        rig = self.rig; W = rig.matrix_world
        def p(nome, onde='head'):
            pb = rig.pose.bones[nome]; return np.array(W @ (pb.head if onde == 'head' else pb.tail))
        esf = [(np.array(T @ Vector(self.c)), self.raio(np.array([0, 0, 1.])) * .97)]
        esf += [(p('neck01'), .055), (p('spine01'), .1), (p('spine02'), .105), (p('spine03'), .1),
                ((p('upperarm01.L') + p('spine01')) / 2, .07), ((p('upperarm01.R') + p('spine01')) / 2, .07)]
        return esf

    def atualizar(self):
        if not self.livres: return
        pb = self.rig.pose.bones['head']
        T = self.rig.matrix_world @ pb.matrix @ pb.bone.matrix_local.inverted()
        M = np.array(T); R3 = M[:3, :3]; t = M[:3, 3]
        A = self.A0 @ R3.T + t; d = self.D0 @ R3.T
        g = np.array([0, 0, -1.]); seg = (self.LL / (self.KL - 1))[:, None]
        gp = {'solto': .42, 'rabo': .5}.get(self.ap['estilo'], .26)
        esf = self._esferas(T); pts = [A]; p = A.copy()
        for k in range(1, self.KL):
            d = d * (1 - gp) + g * gp + self.ruido[:, k] * .25
            d /= np.linalg.norm(d, axis=1)[:, None]
            p = p + d * seg
            for c, r in esf:
                q = p - c; dist = np.linalg.norm(q, axis=1); dentro = dist < r
                if dentro.any(): p[dentro] = c + q[dentro] / dist[dentro, None] * r
            p[:, 2] = np.maximum(p[:, 2], .004)                      # chão
            pts.append(p.copy())
        _pos(self.livre, np.stack(pts, 1))
