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
              braco=180, antebraco=180, pe=90, dedos=None, resp=0.5, abreBraco=0.0, abrePerna=0.0,
              abdBraco=0.0, abdAnte=0.0, mao=0.0, polegar=None, planoBraco=0.0)
# Qualquer chave de braço/perna aceita um sufixo _L ou _R para valer só de um lado (ex.: braco_L=60 na
# rosca alternada). abdBraco/abdAnte: abertura lateral em graus (gira a direção do segmento para fora,
# em torno do eixo frente-trás). mao: 0 = mão aberta, 1 = fechada segurando (pegada).
# pe: 90 = pé apoiado no chão como na posição de repouso (sola plana); o osso do pé aponta ~24° para baixo
#   e isso é compensado aqui, então 90 com a canela vertical deixa a sola no chão.
# polegar: giro do antebraço (pronação/supinação), em graus, dizendo para onde aponta o lado do polegar.
#   0 = para fora; com o antebraço para cima, + gira para trás (90 = polegar para trás, 180 = para dentro);
#   com o antebraço pendurado, + gira para a frente (90 = pegada neutra, palma virada para a coxa).
#   Ex.: rosca alternada 90 -> 0 (palma vira para cima ao subir); Arnold 0 -> 180 (palmas para você ->
#   palmas para a frente). None = não gira (como antes).
# planoBraco: gira o braço inteiro em torno do eixo vertical do ombro, + = para a frente (30 = plano da
#   escápula, cotovelo 30° à frente do corpo).

def preparar(rig):
    # filhos das vértebras que respiram não herdam a escala (só a caixa torácica/abdômen expande)
    for n in ('spine01', 'breast.L', 'breast.R', 'spine02', 'spine03', 'neck01', 'clavicle.L', 'clavicle.R'):
        b = rig.data.bones.get(n)
        if b: b.inherit_scale = 'NONE'
    for pb in rig.pose.bones: pb.rotation_mode = 'QUATERNION'

def _abrir(v, graus, lado_x):
    """gira o vetor em torno do eixo Y (frente-trás) para o lado de fora (lado_x = +1 esquerda, -1 direita)"""
    if not graus: return v
    a = math.radians(graus) * lado_x
    return Vector((v.x * math.cos(a) - v.z * math.sin(a), v.y, v.x * math.sin(a) + v.z * math.cos(a))).normalized()

def _fechar_mao(rig, lado, k):
    if k <= 0: return
    for f in range(1, 6):
        for j in (1, 2, 3):
            nb = 'finger%d-%d.%s' % (f, j, lado)
            if nb not in rig.pose.bones: continue
            pb = rig.pose.bones[nb]; ang = math.radians((35 if f == 1 else 75) * k * (1.0 if j > 1 else .8))
            pb.rotation_quaternion = pb.rotation_quaternion @ Matrix.Rotation(ang, 4, 'X').to_quaternion()

def _apontar(rig, nome, d):
    pb = rig.pose.bones[nome]; rest = pb.bone.matrix_local.to_3x3()
    y = rest.col[1].normalized()
    q = y.rotation_difference(d)
    m = (q.to_matrix() @ rest).to_4x4(); m.translation = pb.head
    sc = pb.scale.copy(); pb.matrix = m; pb.scale = sc
    bpy.context.view_layer.update()

_PE_OFF = {}
def _pe_off(rig, lado):
    """quanto o osso do pé aponta para baixo da horizontal na posição de repouso (graus)"""
    if lado not in _PE_OFF:
        y = rig.data.bones['foot.' + lado].matrix_local.col[1].to_3d().normalized()
        _PE_OFF[lado] = math.degrees(math.atan2(-y.z, -y.y))
    return _PE_OFF[lado]

def _girar_z(v, graus):
    a = math.radians(graus); c, s_ = math.cos(a), math.sin(a)
    return Vector((v.x * c - v.y * s_, v.x * s_ + v.y * c, v.z)).normalized()

def _torcer(rig, lado, alvo, sx):
    """gira o antebraço em torno do próprio eixo até o lado do polegar apontar para `alvo` (graus)"""
    from mathutils import Quaternion
    a = (ponto(rig, 'lowerarm02.' + lado, 't') - ponto(rig, 'lowerarm01.' + lado)).normalized()
    fora = Vector((sx, 0, 0))
    ref = fora - a * fora.dot(a)
    if ref.length < .25: ref = Vector((0, 0, 1)) - a * a.z     # antebraço apontando para o lado
    ref.normalize()
    alvo_v = Quaternion(a, math.radians(alvo) * sx) @ ref
    for _ in range(3):
        e = ponto(rig, 'finger2-1.' + lado) - ponto(rig, 'finger5-1.' + lado); e = e - a * e.dot(a)
        if e.length < 1e-6: return
        e.normalize()
        ang = math.atan2(a.dot(e.cross(alvo_v)), e.dot(alvo_v))
        if abs(ang) < math.radians(.5): return
        for n, k in (('lowerarm01.', .4), ('lowerarm02.', .6)):
            pb = rig.pose.bones[n + lado]
            eixo_local = pb.matrix.to_3x3().inverted() @ (rig.matrix_world.to_3x3().inverted() @ a)
            pb.rotation_quaternion = pb.rotation_quaternion @ Quaternion(eixo_local.normalized(), ang * k)
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
        L_ = lambda k: p.get(k + '_' + lado, p[k]) + (0 if k in fixos else delta)    # valor do lado (ou o comum)
        lat = p.get('abrePerna_' + lado, p['abrePerna']) * sx
        _apontar(rig, 'upperleg01.' + lado, dirv(L_('coxa') + 180, lat)); _apontar(rig, 'upperleg02.' + lado, dirv(L_('coxa') + 180, lat))
        _apontar(rig, 'lowerleg01.' + lado, dirv(L_('canela') + 180, lat)); _apontar(rig, 'lowerleg02.' + lado, dirv(L_('canela') + 180, lat))
        _apontar(rig, 'foot.' + lado, dirv(L_('pe') + _pe_off(rig, lado)))
        if p['dedos'] is not None:
            for t in range(1, 6):
                for k in (1, 2, 3):
                    n = 'toe%d-%d.%s' % (t, k, lado)
                    if n in rig.pose.bones: _apontar(rig, n, dirv(A('dedos')))
        la = p.get('abreBraco_' + lado, p['abreBraco']) * sx
        ab = p.get('abdBraco_' + lado, p['abdBraco']); aa = p.get('abdAnte_' + lado, p['abdAnte'])
        pl = p.get('planoBraco_' + lado, p['planoBraco']) * -sx       # + = para a frente (-Y)
        for n in ('upperarm01.', 'upperarm02.'): _apontar(rig, n + lado, _girar_z(_abrir(dirv(L_('braco'), la), ab, sx), pl))
        for n in ('lowerarm01.', 'lowerarm02.'): _apontar(rig, n + lado, _girar_z(_abrir(dirv(L_('antebraco'), la), aa, sx), pl))
        _fechar_mao(rig, lado, p.get('mao_' + lado, p['mao']))
        pg = p.get('polegar_' + lado, p['polegar'])
        if pg is not None: _torcer(rig, lado, pg, sx)
    # respiração: caixa torácica e abdômen
    r = p['resp']
    for n, k in (('spine03', .035), ('spine02', .045), ('spine04', .03)):
        e = 1 + k * (r - .5) * 2
        rig.pose.bones[n].scale = (e, 1, e)
    bpy.context.view_layer.update()

def ponto(rig, nome, onde='head'):
    pb = rig.pose.bones[nome]
    return rig.matrix_world @ (pb.head if onde == 'head' else pb.tail)
