# mocap_lado.py <pose.json do pose_ler> <largura> <altura> <fundido_s> <saida.json>
# Para vídeo filmado de lado: lê os ângulos no plano da imagem (o plano sagital), no mesmo formato
# da pose da modelo (graus a partir da vertical para cima, + = para a frente da pessoa).
# A profundidade do MediaPipe (de frente para a câmera) erra muito nesse ângulo, por isso não é usada.
import sys, json, math, numpy as np
D = json.load(open(sys.argv[1])); W, H = float(sys.argv[2]), float(sys.argv[3]); fund = float(sys.argv[4]); fps = D['fps']
Q = [q for q in D['quadros'] if 'img' in q]
def pt(q, i): return np.array([q['img'][i][0] * W, q['img'][i][1] * H])
def ang(a, b, s):
    d = b - a; return math.degrees(math.atan2(s * d[0], -d[1]))
SEG = {'braco_L': (11, 13), 'antebraco_L': (13, 15), 'braco_R': (12, 14), 'antebraco_R': (14, 16), 'tronco': ('q', 's'), 'cabeca': ('s', 0)}
out = {k: [] for k in SEG}
for q in Q:
    ms = (pt(q, 11) + pt(q, 12)) / 2; mq = (pt(q, 23) + pt(q, 24)) / 2
    s = 1.0 if pt(q, 0)[0] > ms[0] else -1.0         # para onde a pessoa olha na imagem = frente
    for k, (a, b) in SEG.items():
        A = mq if a == 'q' else ms if a == 's' else pt(q, a); B = ms if b == 's' else pt(q, b)
        out[k].append(ang(A, B, s))
res = {}
for k, v in out.items():
    a = np.unwrap(np.radians(v)); n = len(a)
    a = np.array([a[max(0, i - 2):i + 3].mean() for i in range(n)])          # média móvel de 5 quadros
    Df = int(round(fund * fps)); L = n - Df; r = []
    for i in range(L):
        x = a[i + Df]
        if i >= L - Df:
            w = (i - (L - Df) + 1) / Df; w = w * w * (3 - 2 * w); x = x * (1 - w) + a[i - (L - Df)] * w
        r.append(round(math.degrees(x), 2))
    res[k] = r
json.dump({'fps': fps, 'n': len(res['tronco']), 'angulos': res}, open(sys.argv[5], 'w'))
for k, v in res.items(): print(k, 'min', round(min(v)), 'max', round(max(v)))
