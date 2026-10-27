# reel.py <cortes.json> <id do corte> <saida.mp4> [--so-quadro t]
# Monta um reel vertical (1080x1920) de um trecho da entrevista:
# cortes nas pausas, enquadramento 4:5 que segue quem está na tela, gancho, legenda palavra a palavra
# (palavra falada alternando amarelo e verde Intus), assinatura @luiznunest / @intusfit e cartão final.
import sys, os, json, math, subprocess, numpy as np
from PIL import Image, ImageDraw, ImageFont, ImageFilter

E = os.path.dirname(os.path.abspath(__file__))
VID = os.path.join(E, 'orig', 'entrevista.mp4')
FPS = 30; W, H = 1080, 1920
JAN_Y, JAN_W, JAN_H = 230, 1080, 1350          # janela do vídeo no reel
SRC_W, SRC_H = 624, 780                         # recorte 4:5 acima da tarja da TV (a tarja começa em y 780)
FAIXA_Y0, FAIXA_Y1 = 1150, 1390                 # legendas por cima do vídeo, na parte de baixo
AMARELO, VERDE, BRANCO, FUNDO = (255, 214, 10), (127, 255, 0), (245, 245, 245), (15, 15, 15)
FONTE_X = '/usr/share/fonts/opentype/inter/Inter-ExtraBold.otf'
FONTE_B = '/usr/share/fonts/opentype/inter/Inter-Bold.otf'
FONTE_M = '/usr/share/fonts/opentype/inter/Inter-Medium.otf'
LOGO = '/home/user/intusfit-app/app/painel/logo-intus-dark.png'
T_GANCHO, T_FIM = 2.6, 2.8

args = sys.argv[1:]
CFG = {c['id']: c for c in json.load(open(args[0]))}[args[1]]; SAIDA = args[2]
SO = float(args[args.index('--so-quadro') + 1]) if '--so-quadro' in args else None
TR = json.load(open(os.path.join(E, 'transcricao.json')))
PE = json.load(open(os.path.join(E, 'pessoas.json')))
CORTES = json.load(open(os.path.join(E, 'cortes.json')))
fonte = lambda f, t: ImageFont.truetype(f, t)

# ---------- 1. palavras e trechos (corta as pausas) ----------
def fim_do_segmento(t):
    s = min(TR, key=lambda s: abs(s['ini'] - t)); return s['fim']
palavras = []
for a, b in CFG['partes']:
    fb = fim_do_segmento(b)
    for s in TR:
        for w in s['palavras']:
            if w['i'] >= a - .05 and w['i'] < fb - .02: palavras.append(dict(w, parte=(a, fb)))
palavras.sort(key=lambda w: w['i'])
trechos = []
for w in palavras:
    ini, fim = w['i'] - .08, w['f'] + .16
    if trechos and ini - trechos[-1][1] < .45 and trechos[-1][2] == w['parte']: trechos[-1][1] = max(trechos[-1][1], fim)
    else: trechos.append([ini, fim, w['parte']])
# linha do tempo do reel: cada trecho em sequência
mapa, t_out = [], 0.0
for a, b, _ in trechos: mapa.append((a, b, t_out)); t_out += b - a
DUR_FALA = t_out; DUR = DUR_FALA + T_FIM
def para_reel(t):
    for a, b, o in mapa:
        if a <= t <= b: return o + t - a
    return None
for w in palavras: w['ri'], w['rf'] = para_reel(w['i']), para_reel(min(w['f'], max(b for a, b, o in mapa if a <= w['i'] <= b)))

# grupos de legenda: até 3 palavras / 18 letras, quebra em pausa ou pontuação
grupos, g = [], []
for w in palavras:
    txt = w['p'].strip()
    if not txt: continue
    if g and (len(g) >= 3 or len(' '.join(x['p'].strip() for x in g + [w])) > 18 or w['ri'] - g[-1]['rf'] > .35): grupos.append(g); g = []
    g.append(w)
    if txt[-1] in '.?!,': grupos.append(g); g = []
if g: grupos.append(g)

# ---------- 2. enquadramento ----------
Q = PE['quadros']
def alvo(q, ant):
    ps = [p for p in q['p'] if (p['y1'] - p['y0']) > 220]
    if not ps: return ant
    ps.sort(key=lambda p: -(p['x1'] - p['x0']) * (p['y1'] - p['y0'])); ps = ps[:2]
    if len(ps) == 1: return ps[0]['cab'][0]
    x0, x1 = min(p['x0'] for p in ps), max(p['x1'] for p in ps)
    if x1 - x0 < SRC_W - 70: return (x0 + x1) / 2
    luiz = min(ps, key=lambda p: p['escuro'])          # camisa preta
    if any(a <= q['t'] <= b for a, b in CFG.get('apresentadora', [])):   # ela está falando: mostra ela
        return max(ps, key=lambda p: p['escuro'])['cab'][0]
    return luiz['ombro'][0]
cx_src, ant, ult_corte = [], 960, -1
for q in Q:
    t = q['t']; c = alvo(q, ant)
    novo = any(ult_corte < k <= t for k in CORTES)
    ant = c if novo or not cx_src else ant * .6 + c * .4
    cx_src.append((t, ant, novo)); ult_corte = t
def cx_em(t):
    i = max(0, min(len(cx_src) - 1, int(t / .5)))
    j = min(len(cx_src) - 1, i + 1)
    if cx_src[j][2]: return cx_src[i][1]
    k = (t - cx_src[i][0]) / .5
    return cx_src[i][1] * (1 - k) + cx_src[j][1] * k

# ---------- 3. desenho ----------
def texto_centro(d, txt, y, f, cor, borda=6):
    w = d.textlength(txt, font=f); d.text(((W - w) / 2, y), txt, font=f, fill=cor, stroke_width=borda, stroke_fill=(0, 0, 0))
def quebra(txt, f, larg, d):
    linhas, at = [], ''
    for p in txt.split():
        tst = (at + ' ' + p).strip()
        if d.textlength(tst, font=f) > larg and at: linhas.append(at); at = p
        else: at = tst
    return linhas + [at]
GANCHO = CFG['gancho']; DEST = set(x.lower() for x in CFG.get('destaque_gancho', []))
logo = Image.open(LOGO).convert('RGBA')
def desenha_gancho(d, grande, t):
    f = fonte(FONTE_X, 92 if grande else 58)
    linhas = quebra(GANCHO.upper(), f, W - 120, d)
    alt = (104 if grande else 68)
    y = (JAN_Y + 330 - len(linhas) * alt / 2) if grande else (118 - len(linhas) * alt / 2)
    for ln in linhas:
        # palavra de destaque em verde
        partes = ln.split(); larg = d.textlength(ln, font=f); x = (W - larg) / 2
        for p in partes:
            cor = VERDE if p.strip('.,?!:').lower() in DEST else BRANCO
            d.text((x, y), p, font=f, fill=cor, stroke_width=7 if grande else 5, stroke_fill=(0, 0, 0))
            x += d.textlength(p + ' ', font=f)
        y += alt
def desenha_legenda(d, t):
    gi = None
    for k, g in enumerate(grupos):
        if g[0]['ri'] - .05 <= t <= (grupos[k + 1][0]['ri'] - .05 if k + 1 < len(grupos) else g[-1]['rf'] + .4): gi = k
    if gi is None: return
    g = grupos[gi]; cor_dest = AMARELO if gi % 2 == 0 else VERDE
    f = fonte(FONTE_X, 84); fg = fonte(FONTE_X, 92)
    ps = [w['p'].strip().upper() for w in g]
    ativa = max([i for i, w in enumerate(g) if w['ri'] <= t + .03] or [0])
    larg = sum(d.textlength(p, font=fg if i == ativa else f) for i, p in enumerate(ps)) + d.textlength(' ', font=f) * (len(ps) - 1)
    if larg > W - 80:                       # grupo largo demais: duas linhas
        meio = max(1, len(ps) // 2); linhas = [list(range(meio)), list(range(meio, len(ps)))]
    else: linhas = [list(range(len(ps)))]
    y = (FAIXA_Y0 + FAIXA_Y1) / 2 - len(linhas) * 52 - 10
    for ln in linhas:
        larg = sum(d.textlength(ps[i], font=fg if i == ativa else f) for i in ln) + d.textlength(' ', font=f) * (len(ln) - 1)
        x = (W - larg) / 2
        for i in ln:
            ff = fg if i == ativa else f
            d.text((x, y - (6 if i == ativa else 0)), ps[i], font=ff, fill=cor_dest if i == ativa else BRANCO, stroke_width=7, stroke_fill=(0, 0, 0))
            x += d.textlength(ps[i] + ' ', font=ff if i == ativa else f)
        y += 110
def base_canvas():
    c = Image.new('RGB', (W, H), FUNDO); return c
GRAD420 = Image.linear_gradient('L').resize((W, 420)).point(lambda v: int(v * .62))
def quadro_fala(frame, t, idx_trecho, t_src):
    c = base_canvas()
    z = 1.0 if idx_trecho % 2 == 0 else 1.08
    sw, sh = SRC_W / z, SRC_H / z
    cx = min(max(cx_em(t_src), sw / 2), 1920 - sw / 2)
    y0 = (SRC_H - sh) * .3
    im = Image.fromarray(frame).crop((int(cx - sw / 2), int(y0), int(cx + sw / 2), int(y0 + sh))).resize((JAN_W, JAN_H), Image.LANCZOS)
    c.paste(im, (0, JAN_Y))
    d = ImageDraw.Draw(c)
    # escurece de leve a parte de baixo do vídeo para a legenda ler bem
    c.paste(Image.new('RGB', (W, 420), (0, 0, 0)), (0, JAN_Y + JAN_H - 420), GRAD420)
    if t < T_GANCHO:
        esc = Image.new('RGBA', (W, JAN_H), (0, 0, 0, 120)); c.paste(esc, (0, JAN_Y), esc); d = ImageDraw.Draw(c)
        desenha_gancho(d, True, t)
    else: desenha_gancho(d, False, t)
    desenha_legenda(d, t)
    # assinatura e barra de progresso
    fa = fonte(FONTE_B, 36); s = '@luiznunest   ·   @intusfit'
    d.text(((W - d.textlength(s, font=fa)) / 2, 1660), s, font=fa, fill=(200, 200, 200))
    lg = logo.copy(); lg.thumbnail((240, 70)); c.paste(lg, ((W - lg.width) // 2, 1740), lg)
    d.rectangle((0, H - 10, W * min(1, t / DUR), H), fill=VERDE)
    return c
def quadro_fim(t):
    c = base_canvas(); d = ImageDraw.Draw(c); k = min(1, t / .35)
    lg = logo.copy(); lg.thumbnail((520, 160)); c.paste(lg, ((W - lg.width) // 2, 560), lg)
    texto_centro(d, 'SIGA PARA MAIS DICAS', 790, fonte(FONTE_X, 64), BRANCO, 0)
    texto_centro(d, '@luiznunest', 900, fonte(FONTE_X, 96), AMARELO, 0)
    texto_centro(d, '@intusfit', 1020, fonte(FONTE_X, 96), VERDE, 0)
    texto_centro(d, 'Treinos e o app com treinos grátis no link da bio', 1170, fonte(FONTE_M, 40), (190, 190, 190), 0)
    if k < 1: c = Image.blend(Image.new('RGB', (W, H), FUNDO), c, k)
    return c

# ---------- 4. saída ----------
if SO is not None:
    a, b, o = next((m for m in mapa if m[2] <= SO < m[2] + (m[1] - m[0])), mapa[-1])
    ts = a + SO - o
    raw = subprocess.run(['ffmpeg', '-loglevel', 'error', '-ss', str(ts), '-i', VID, '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], capture_output=True).stdout
    fr = np.frombuffer(raw, np.uint8).reshape(1080, 1920, 3)
    quadro_fala(fr, SO, mapa.index((a, b, o)), ts).save(SAIDA); print('quadro ok'); sys.exit()

aud = SAIDA + '.wav'
fil = ''.join('[0:a]atrim=%.3f:%.3f,asetpts=PTS-STARTPTS,afade=t=in:d=0.02,afade=t=out:st=%.3f:d=0.03[a%d];' % (a, b, max(0, b - a - .03), i) for i, (a, b, _) in enumerate(trechos))
fil += ''.join('[a%d]' % i for i in range(len(trechos))) + 'concat=n=%d:v=0:a=1,apad=pad_dur=%.2f[ao]' % (len(trechos), T_FIM)
subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-i', VID, '-filter_complex', fil, '-map', '[ao]', '-ar', '48000', '-t', '%.3f' % DUR, aud], check=True)
enc = subprocess.Popen(['ffmpeg', '-loglevel', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-s', '%dx%d' % (W, H), '-r', str(FPS), '-i', '-', '-i', aud,
                        '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '160k', '-shortest', '-movflags', '+faststart', SAIDA], stdin=subprocess.PIPE)
n_out = 0
for idx, (a, b, o) in enumerate(mapa):
    n = int(round((o + b - a) * FPS)) - n_out
    p = subprocess.Popen(['ffmpeg', '-loglevel', 'error', '-ss', '%.3f' % a, '-i', VID, '-t', '%.3f' % (b - a + .2), '-vf', 'fps=%d' % FPS, '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-'], stdout=subprocess.PIPE)
    ult = None
    for k in range(n):
        buf = p.stdout.read(1920 * 1080 * 3)
        if len(buf) == 1920 * 1080 * 3: ult = np.frombuffer(buf, np.uint8).reshape(1080, 1920, 3)
        t = (n_out + k) / FPS
        enc.stdin.write(quadro_fala(ult, t, idx, a + k / FPS).tobytes())
    p.kill(); n_out += n
for k in range(int(T_FIM * FPS)): enc.stdin.write(quadro_fim(k / FPS).tobytes())
enc.stdin.close(); enc.wait(); os.remove(aud)
capa = SAIDA[:-4] + '.jpg'
subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-ss', '0.8', '-i', SAIDA, '-frames:v', '1', '-q:v', '3', capa])
print('ok', SAIDA, '%.1f s' % DUR, len(trechos), 'trechos')
