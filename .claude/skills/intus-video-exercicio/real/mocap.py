# mocap.py <pose.json do pose_ler> <fundido_s> <saida.json>
# Converte as juntas do MediaPipe (mundo, em metros) em direções no sistema da modelo 3D
# (x = esquerda da pessoa, y = para trás, z = para cima), quadro a quadro, suavizadas, com o fim
# dissolvendo no começo (loop sem salto, igual ao loop_suave.py do vídeo).
import sys, json, numpy as np
D = json.load(open(sys.argv[1])); fund = float(sys.argv[2]); fps = D['fps']
Q = [q for q in D['quadros'] if 'mundo' in q]
P = np.array([q['mundo'] for q in Q])           # quadros x 33 x 3 (x direita da imagem, y para baixo, z para longe)
up = np.array([0., -1., 0.])
def base(p):
    r = p[23] - p[24]; r = r - up * r.dot(up); r /= np.linalg.norm(r)        # quadril: direita -> esquerda da pessoa
    f = np.cross(r, up); f /= np.linalg.norm(f)                                # frente da pessoa
    return r, up, f
SEG = {'braco_L': (11, 13), 'antebraco_L': (13, 15), 'braco_R': (12, 14), 'antebraco_R': (14, 16),
       'mao_L': (17, 19), 'mao_R': (18, 20), 'tronco': ('q', 'o')}
out = {k: [] for k in SEG}
for p in P:
    r, u, f = base(p)
    for k, (a, b) in SEG.items():
        if a == 'q': va, vb = (p[23] + p[24]) / 2, (p[11] + p[12]) / 2
        else: va, vb = p[a], p[b]
        d = vb - va; d = d / (np.linalg.norm(d) + 1e-9)
        out[k].append([d.dot(r), -d.dot(f), d.dot(u)])            # modelo: x esquerda, y para trás, z para cima
for k in out:
    a = np.array(out[k]); n = len(a)
    s = np.array([a[max(0, i - 2):i + 3].mean(0) for i in range(n)])            # média móvel de 5 quadros
    s /= np.linalg.norm(s, axis=1, keepdims=True); out[k] = s
Df = int(round(fund * fps)); L = len(P) - Df
res = {k: [] for k in out}
for k, a in out.items():
    for i in range(L):
        v = a[i + Df]
        if i >= L - Df:
            w = (i - (L - Df) + 1) / Df; w = w * w * (3 - 2 * w); v = v * (1 - w) + a[i - (L - Df)] * w; v = v / np.linalg.norm(v)
        res[k].append([round(float(x), 4) for x in v])
json.dump({'fps': fps, 'n': L, 'dirs': res}, open(sys.argv[3], 'w'))
z = np.array(res['antebraco_R'])[:, 2]; zl = np.array(res['antebraco_L'])[:, 2]
print('quadros', L, 'antebraco_R z min/max', z.min().round(2), z.max().round(2), 'antebraco_L', zl.min().round(2), zl.max().round(2))
print('tronco medio', np.array(res['tronco']).mean(0).round(2))
