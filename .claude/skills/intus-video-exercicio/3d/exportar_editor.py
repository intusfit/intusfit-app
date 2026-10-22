"""Gera o arquivo do Editor da Modelo (modelo.json): malha do corpo em repouso, cores por região e,
para cada controle de 3d/ajustes.json, o deslocamento de cada vértice (alvo de forma) nos dois sentidos.
  python exportar_editor.py <pasta 3d> <saida.json>"""
import sys, os, json, base64, math, bpy
import numpy as np
sys.path.insert(0, sys.argv[-2]); import modelo
from mathutils import Vector
bpy.ops.wm.read_factory_settings(use_empty=True)
r = modelo.criar_modelo({}, para_editor=True)
h, co, vg, m, A, ts, hs, dados = r['h'], r['co'], r['vg'], r['m'], r['A'], r['ts'], r['hs'], r['dados']
oprops = __import__('importlib').import_module('bl_ext.user_default.mpfb.entities.objectproperties').HumanObjectProperties

def posicoes():
    for md in h.modifiers: md.show_viewport = False
    dg = bpy.context.evaluated_depsgraph_get(); ev = h.evaluated_get(dg); me = ev.to_mesh()
    p = np.empty(len(me.vertices) * 3, dtype=np.float32); me.vertices.foreach_get('co', p); ev.to_mesh_clear()
    return p.reshape(-1, 3)
base = posicoes()

corpo = [i for i, s in enumerate(vg) if 'body' in s]
novo = {v: k for k, v in enumerate(corpo)}
tri = []
for p in h.data.polygons:
    vs = list(p.vertices)
    if all(v in novo for v in vs):
        for k in range(1, len(vs) - 1): tri += [novo[vs[0]], novo[vs[k]], novo[vs[k + 1]]]

# região de cada vértice (a página pinta com as cores escolhidas): 0 pele, 1 lábios, 2 cabelo pintado,
# 3 top, 4 short, 5 tênis, 6 detalhe (friso/barra), 7 sola
reg = []
zo, z1 = m['zombro'], m['zbusto']
for i in corpo:
    z = A['zr'].data[i].value; y = A['yr'].data[i].value; ax = A['ax'].data[i].value
    br = A['br'].data[i].value; lb = A['lb'].data[i].value; hsv = A['hs'].data[i].value
    tronco = br < .35; frente = y < m['yc']; costas = not frente
    decote = zo - .088 + min(ax, .062) * .55
    nad = min(.006 + max(z - (zo - .10), 0) * .47, .065)
    top = tronco and ((frente and z1 < z < decote) or (frente and .049 < ax < .081 and z1 < z < zo + .11)
                      or (costas and z1 < z < z1 + .042) or (costas and abs(ax - nad) < .016 and z1 < z < zo + .11)
                      or (costas and ax < .022 and z1 < z < zo - .09))
    r_ = 0
    if lb > .3: r_ = 1
    if hsv > .78: r_ = 2
    if top: r_ = 3
    if tronco and m['zbarra'] < z < m['zcintura'] + .03: r_ = 4
    if tronco and (z1 < z < z1 + .008 or m['zbarra'] - .006 < z < m['zbarra'] + .008): r_ = 6
    if z < m['ztorn'] + .012: r_ = 5
    if z < .014: r_ = 7
    reg.append(r_)
# olhos e sobrancelhas (pontos de cada tipo, presos na pele, para a página desenhar)
olhos = []; sobr = {t: [] for t in modelo.SOBRANCELHAS}
for lado in ('l', 'r'):
    ids = [i for i, s in enumerate(vg) if 'helper-%s-eye' % lado in s]
    c = sum((co[i] for i in ids), Vector()) / len(ids); rr = max((co[i] - c).length for i in ids) * .98
    olhos.append([c.x, c.y, c.z, rr])
    s_ = 1 if c.x > 0 else -1
    face = [co[i] for i, g in enumerate(vg) if 'body' in g and co[i].y < c.y + .03 and abs(co[i].x - c.x) < .035 and c.z < co[i].z < c.z + .04]
    def na_pele(x, z):
        v = min(face, key=lambda q: (q.x - x) ** 2 + (q.z - z) ** 2); return [x, v.y - .0012, z]
    for t, (alturas, grossuras) in modelo.SOBRANCELHAS.items():
        sobr[t].append([na_pele(c.x + s_ * dx, c.z + dz) for dx, dz in zip((-.016, -.003, .010, .021), alturas)])
grossuras = {t: g for t, (_, g) in modelo.SOBRANCELHAS.items()}
# cabelo: raízes sorteadas no couro cabeludo, pontos do couro (raio da cabeça), referências e corpos de colisão
couro = [i for i in range(len(co)) if A['hs'].data[i].value > .45]
Sset = set(couro); T = []
for p in h.data.polygons:
    vs = list(p.vertices)
    if sum(v in Sset for v in vs) >= len(vs) - 1:
        for k in range(1, len(vs) - 1): T.append([[co[vs[j]].x, co[vs[j]].y, co[vs[j]].z] for j in (0, k, k + 1)])
T = np.array(T); area = np.linalg.norm(np.cross(T[:, 1] - T[:, 0], T[:, 2] - T[:, 0]), axis=1) / 2
rng = np.random.default_rng(3); NR = 3500
ch = rng.choice(len(T), NR, p=area / area.sum()); uu = rng.random(NR); vv = rng.random(NR); tr_ = uu + vv > 1; uu[tr_] = 1 - uu[tr_]; vv[tr_] = 1 - vv[tr_]
raizes = T[ch, 0] + (T[ch, 1] - T[ch, 0]) * uu[:, None] + (T[ch, 2] - T[ch, 0]) * vv[:, None]
Pc = np.array([[co[i].x, co[i].y, co[i].z] for i in couro])
orelha = [co[i] for i, s_ in enumerate(vg) if 'ears' in s_]; orelha_topo = max(c.z for c in orelha)
nuca_z = sum((co[i] for i, s_ in enumerate(vg) if 'joint-neck' in s_), Vector()).z / max(1, sum(1 for s_ in vg if 'joint-neck' in s_)) + .035
idsS = [i for i, s_ in enumerate(vg) if 'scalp' in s_]
topo = max((co[i] for i in idsS), key=lambda v: v.z); tras = max((co[i] for i in idsS), key=lambda v: v.y)
rig = r['rig']; ossos = {n_: list(rig.data.bones[n_].head_local) for n_ in ('neck01', 'spine01', 'spine02', 'spine03', 'upperarm01.L', 'upperarm01.R')}
cab = {'raizes': base64.b64encode(raizes.astype(np.float32).tobytes()).decode(),
       'couro': base64.b64encode(Pc.astype(np.float32).tobytes()).decode(),
       'mapa': {'coque_alto': [0, tras.y + .012, topo.z - .03], 'coque_baixo': [0, tras.y + .006, nuca_z + .035],
                'rabo': [0, tras.y + .004, topo.z - .055], 'orelha_z': orelha_topo}, 'ossos': ossos}

ESC = 2e-5
def empacotar(delta):
    d = delta[corpo]; mexe = np.where(np.abs(d).max(1) > 2e-5)[0]
    q = np.clip(np.round(d[mexe] / ESC), -32767, 32767).astype(np.int16)
    return {'i': base64.b64encode(mexe.astype(np.uint16).tobytes()).decode(), 'd': base64.b64encode(q.tobytes()).decode()}

def delta_alvo(rels, peso=1.0, nomes=None):
    kb = []
    for rel in rels:
        for cat, nome in modelo.arquivos_do_alvo(rel):
            kb.append(ts.load_target(h, os.path.join(dados, cat, nome + '.target.gz'), weight=peso, name='tmp-' + nome))
    d = posicoes() - base
    for k in kb: h.shape_key_remove(k)
    return d

morfos = {}
for c in modelo.controles():
    if 'mais' in c:
        morfos[c['id'] + '+'] = empacotar(delta_alvo([c['mais']])); morfos[c['id'] + '-'] = empacotar(delta_alvo([c['menos']]))
    elif 'macro' in c:
        b = modelo.MACROS_BASE[c['macro']]
        for sinal, alvo in (('+', c['max']), ('-', c['min'])):
            oprops.set_value(c['macro'], alvo, entity_reference=h); ts.reapply_macro_details(h)
            morfos[c['id'] + sinal] = empacotar(posicoes() - base)
            oprops.set_value(c['macro'], b, entity_reference=h); ts.reapply_macro_details(h)
    elif 'expressao' in c:
        base_ex = os.path.join(dados, 'expression', 'units')
        kb = []
        for u, v in modelo.EXPRESSOES[c['expressao']].items():
            for raca in ('caucasian', 'african'):
                kb.append(ts.load_target(h, os.path.join(base_ex, raca, u + '.target.gz'), weight=v * .5, name='tmp-%s-%s' % (raca, u)))
        morfos[c['id'] + '+'] = empacotar(posicoes() - base)
        for k in kb: h.shape_key_remove(k)
    print('ok', c['id'], flush=True)
erro = np.abs(posicoes() - base).max()
assert erro < 1e-4, 'a malha não voltou ao estado base: %g' % erro

out = {'versao': 1, 'escala_delta': ESC, 'n': len(corpo),
       'pos': base64.b64encode(base[corpo].astype(np.float32).tobytes()).decode(),
       'tri': base64.b64encode(np.array(tri, dtype=np.uint16).tobytes()).decode(),
       'regiao': base64.b64encode(bytes(reg)).decode(),
       'olhos': olhos, 'sobrancelhas': sobr, 'grossuras': grossuras, 'cabelo': cab,
       'aparencia': {k: v for k, v in modelo.APARENCIA_PADRAO.items()}, 'controles': modelo.controles(), 'morfos': morfos}
json.dump(out, open(sys.argv[-1], 'w'))
print('tamanho', os.path.getsize(sys.argv[-1]) // 1024, 'KB', 'vertices', len(corpo), 'triangulos', len(tri) // 3)
