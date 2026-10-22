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

# cores (mesma regra do shader de modelo.material_corpo, por vértice)
def srgb8(hx): hx = hx.lstrip('#'); return [int(hx[i:i + 2], 16) for i in (0, 2, 4)]
cor = []
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
    c = modelo.PELE
    if lb > .3: c = '#8e4f43'
    if hsv > .5: c = modelo.CABELO
    if top: c = modelo.ROUPA
    if tronco and m['zbarra'] < z < m['zcintura'] + .03: c = modelo.ROUPA
    if tronco and (z1 < z < z1 + .008 or m['zbarra'] < z < m['zbarra'] + .006): c = modelo.DESTAQUE
    if z < m['ztorn'] + .012: c = modelo.TENIS
    cor += srgb8(c)
# sobrancelhas: escurece a pele em volta da curva (só na prévia; no render são fios de verdade)
olhos = []
for lado in ('l', 'r'):
    ids = [i for i, s in enumerate(vg) if 'helper-%s-eye' % lado in s]
    c = sum((co[i] for i in ids), Vector()) / len(ids); rr = max((co[i] - c).length for i in ids) * .98
    olhos.append([c.x, c.y, c.z, rr])
    s_ = 1 if c.x > 0 else -1
    arco = [Vector((c.x + s_ * dx, 0, c.z + dz)) for dx, dz in ((-.016, .0165), (-.003, .0195), (.010, .0198), (.021, .0155))]
    for k, i in enumerate(corpo):
        q = co[i]
        if q.y < c.y + .03 and abs(q.x - c.x) < .03 and min(math.hypot(q.x - a.x, q.z - a.z) for a in arco) < .0035:
            cor[3 * k:3 * k + 3] = srgb8('#1d120c')
ids = [i for i, s in enumerate(vg) if 'scalp' in s]
topo = max((co[i] for i in ids), key=lambda v: v.z); tras = max((co[i] for i in ids), key=lambda v: v.y)
coque = [0, tras.y + .012, topo.z - .03, .058]

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
       'cor': base64.b64encode(bytes(cor)).decode(),
       'olhos': olhos, 'coque': coque, 'controles': modelo.controles(), 'morfos': morfos}
json.dump(out, open(sys.argv[-1], 'w'))
print('tamanho', os.path.getsize(sys.argv[-1]) // 1024, 'KB', 'vertices', len(corpo), 'triangulos', len(tri) // 3)
