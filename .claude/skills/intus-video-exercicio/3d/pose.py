"""Pose por ângulos no plano lateral (graus a partir da vertical para cima; + = para a frente do aluno).

Mesma convenção dos roteiros 2D: canela (tornozelo->joelho), coxa (joelho->quadril), braco
(ombro->cotovelo), antebraco (cotovelo->mão), pe (direção do pé). Coluna em 5 vértebras-osso:
`tronco` é a direção média, `lombar` e `toracica` curvam (+ = flexão, arredonda; - = extensão,
arqueia), `pelve` inclina a bacia (+ = anteversão, "bumbum para trás"), `pescoco` soma à cabeça.
`resp` de 0 (ar solto) a 1 (pulmão cheio) expande caixa torácica e abdômen.
"""
import bpy, math
from mathutils import Vector, Matrix

FRENTE = Vector((0, -1, 0)); CIMA = Vector((0, 0, 1))
def dirv(ang, lateral=0.0):
    a = math.radians(ang)
    v = FRENTE * math.sin(a) + CIMA * math.cos(a)
    if lateral: v = v + Vector((lateral, 0, 0))
    return v.normalized()

PADRAO = dict(canela=0, coxa=0, tronco=0, lombar=0, toracica=0, pelve=0, pescoco=0, cabecaInc=0,
              braco=180, antebraco=180, pe=90, dedos=None, resp=0.5, abreBraco=0.0, abrePerna=0.0)

def preparar(rig):
    # filhos das vértebras que respiram não herdam a escala (só a caixa torácica/abdômen expande)
    for n in ('spine01', 'breast.L', 'breast.R', 'spine02', 'spine03', 'neck01', 'clavicle.L', 'clavicle.R'):
        b = rig.data.bones.get(n)
        if b: b.inherit_scale = 'NONE'
    for pb in rig.pose.bones: pb.rotation_mode = 'QUATERNION'

def _apontar(rig, nome, d):
    pb = rig.pose.bones[nome]; rest = pb.bone.matrix_local.to_3x3()
    y = rest.col[1].normalized()
    q = y.rotation_difference(d)
    m = (q.to_matrix() @ rest).to_4x4(); m.translation = pb.head
    sc = pb.scale.copy(); pb.matrix = m; pb.scale = sc
    bpy.context.view_layer.update()

def aplicar(rig, p, delta=0.0, fixos=()):
    """Aplica a pose. `delta` gira o corpo inteiro (menos os segmentos em `fixos`)."""
    p = {**PADRAO, **p}
    for pb in rig.pose.bones:
        pb.rotation_quaternion = (1, 0, 0, 0); pb.location = (0, 0, 0); pb.scale = (1, 1, 1)
    bpy.context.view_layer.update()
    A = lambda k: p[k] + (0 if k in fixos else delta)
    T = A('tronco')
    _apontar(rig, 'root', dirv(T + p['pelve']))
    # coluna: lombar (spine05, spine04), torácica (spine03..01). Curvatura distribuída
    lomb = [T - p['lombar'] * .6, T - p['lombar'] * .1]
    tor = [T + p['toracica'] * .1, T + p['toracica'] * .45, T + p['toracica'] * .8]
    for n, a in zip(('spine05', 'spine04', 'spine03', 'spine02', 'spine01'), lomb + tor):
        _apontar(rig, n, dirv(a))
    cab = tor[-1] + p['pescoco']
    _apontar(rig, 'neck01', dirv(cab)); _apontar(rig, 'neck02', dirv(cab + p['cabecaInc'] * .4))
    _apontar(rig, 'neck03', dirv(cab + p['cabecaInc'] * .7)); _apontar(rig, 'head', dirv(cab + p['cabecaInc']))
    for lado, sx in (('L', 1), ('R', -1)):
        lat = p['abrePerna'] * sx
        _apontar(rig, 'upperleg01.' + lado, dirv(A('coxa') + 180, lat)); _apontar(rig, 'upperleg02.' + lado, dirv(A('coxa') + 180, lat))
        _apontar(rig, 'lowerleg01.' + lado, dirv(A('canela') + 180, lat)); _apontar(rig, 'lowerleg02.' + lado, dirv(A('canela') + 180, lat))
        _apontar(rig, 'foot.' + lado, dirv(A('pe')))
        if p['dedos'] is not None:
            for t in range(1, 6):
                for k in (1, 2, 3):
                    n = 'toe%d-%d.%s' % (t, k, lado)
                    if n in rig.pose.bones: _apontar(rig, n, dirv(A('dedos')))
        la = p['abreBraco'] * sx
        for n in ('upperarm01.', 'upperarm02.'): _apontar(rig, n + lado, dirv(A('braco'), la))
        for n in ('lowerarm01.', 'lowerarm02.'): _apontar(rig, n + lado, dirv(A('antebraco'), la))
    # respiração: caixa torácica e abdômen
    r = p['resp']
    for n, k in (('spine03', .035), ('spine02', .045), ('spine04', .03)):
        e = 1 + k * (r - .5) * 2
        rig.pose.bones[n].scale = (e, 1, e)
    bpy.context.view_layer.update()

def ponto(rig, nome, onde='head'):
    pb = rig.pose.bones[nome]
    return rig.matrix_world @ (pb.head if onde == 'head' else pb.tail)
