# teste_corpo.py <pasta 3d> <salvo.json> <saida_prefixo> [json de ajustes extra]
import sys, json, bpy
sys.path.insert(0, sys.argv[1]); import cena, pose, modelo
S = json.load(open(sys.argv[2])); pref = sys.argv[3]
val = dict(S.get('valores') or {}); val.update(json.loads(sys.argv[4]) if len(sys.argv) > 4 else {})
bpy.ops.wm.read_factory_settings(use_empty=True)
h, rig, m = modelo.criar_modelo(val, aparencia=S.get('aparencia')); cam, col = cena.cenario(700, 900); pose.preparar(rig)
sc = bpy.context.scene; sc.cycles.samples = 10; E = m['escala']
pose.aplicar(rig, dict(braco=180, antebraco=176, abreBraco=.16, abrePerna=.04, pe=90, cabecaInc=-4, mao=.3))
low = min(pose.ponto(rig, 'foot.L').z, pose.ponto(rig, 'foot.R').z); rig.location.z += m['ztorn_mundo'] - low
bpy.context.view_layer.update(); m['cabelo'].atualizar()
for nome, az, zz, d in (('lado', 180, .85, 3.3), ('diag', -40, .85, 3.3), ('logo', -25, m['zbarra'] * E + .02, .8), ('pe', 90, .1, 1.0), ('frente', -20, m['zcintura'] * E - .03, .8)):
    cena.mirar(cam, (0, 0, zz), d, az, 3); sc.render.filepath = pref + nome + '.png'; bpy.ops.render.render(write_still=True)
