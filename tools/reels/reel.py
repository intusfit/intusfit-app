# reel.py <cortes.json> <id do corte> <saida.mp4> [--so-quadro t]
# Monta um reel vertical (1080x1920) de um trecho da entrevista:
# cortes só nas pausas longas, enquadramento fixo por plano da TV (sem balançar), largo o bastante
# para mostrar o corpo de quem está no plano, gancho, legenda palavra a palavra
# (palavra falada alternando amarelo e verde Intus), assinatura @luiznunest / @intusfit e cartão final.
import sys, os, json, math, subprocess, numpy as np
from PIL import Image, ImageDraw, ImageFont, ImageFilter

E = os.path.dirname(os.path.abspath(__file__))
VID = os.path.join(E, 'orig', 'entrevista.mp4')
FPS = 30; W, H = 1080, 1920
JAN_Y, JAN_W, JAN_H = 230, 1080, 1350          # área do vídeo no reel (fundo desfocado quando o plano é largo)
SRC_H = 780                                     # só acima da tarja da TV (a tarja começa em y 780)
SRC_MIN, SRC_MAX = 624, 1500                    # largura do recorte no original: 624 = 4:5 (close), até 1500 (dois no plano)
AUDIO_LIMPO = os.path.join(E, 'sep', 'audio_limpo.wav')
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
    if trechos and ini - trechos[-1][1] < .8 and trechos[-1][2] == w['parte']: trechos[-1][1] = max(trechos[-1][1], fim)
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

# ---------- 2. enquadramento: um recorte fixo por plano da TV ----------
Q = PE['quadros']
def plano_de(t): return sum(1 for k in CORTES if k <= t)
_PLANO = {}
def enquadra(t):
    pid = plano_de(t)
    if pid in _PLANO: return _PLANO[pid]
    a = CORTES[pid - 1] if pid > 0 else 0.0; b = CORTES[pid] if pid < len(CORTES) else 1e9
    xs0, xs1, larg, cab = [], [], [], []
    for q in Q:
        if not (a + .2 <= q['t'] <= b - .2): continue
        ps = sorted([p for p in q['p'] if (p['y1'] - p['y0']) > 180], key=lambda p: -(p['x1'] - p['x0']))[:2]
        if not ps: continue
        xs0.append(min(p['x0'] for p in ps)); xs1.append(max(p['x1'] for p in ps))
        larg.append(ps[0]['x1'] - ps[0]['x0']); cab.append(ps[0]['cab'][0])
    if not larg: r = (960, 1300)
    elif np.median(larg) > 520:
        r = (float(np.median(cab)), SRC_MIN)                         # close (pessoa larga na tela): 4:5 no rosto
    else:
        # plano aberto: o detector às vezes não vê a apresentadora (roupa clara no fundo claro), então o recorte
        # é largo de propósito (mínimo 1250 px) e puxado para o centro da cena, para ninguém ficar cortado
        x0, x1 = min(xs0) - 70, max(xs1) + 70
        lw = float(min(max(x1 - x0, 1250), SRC_MAX)); cx = (x0 + x1) / 2 * .55 + 960 * .45
        r = (cx, lw)
    _PLANO[pid] = r; return r

# ---------- projeto para o editor Intus Cut (--projeto saida.json base_s) ----------
if '--projeto' in args:
    out, base = args[args.index('--projeto') + 1], float(args[args.index('--projeto') + 2])
    ini_t, fim_t = trechos[0][0], trechos[-1][1]
    planos = []
    for k in range(len(CORTES) + 1):
        a = CORTES[k - 1] if k > 0 else 0.0; b = CORTES[k] if k < len(CORTES) else 1e9
        if b < ini_t - 1 or a > fim_t + 1: continue
        cx, lw = enquadra((max(a, ini_t) + min(b, fim_t)) / 2)
        planos.append({'ini': round(max(a, base) - base, 3), 'fim': round(min(b, fim_t + 1.5) - base, 3), 'cx': round(cx, 1), 'larg': round(lw, 1)})
    json.dump({'id': CFG['id'], 'nome': CFG['gancho'], 'gancho': CFG['gancho'], 'destaque': CFG.get('destaque_gancho', []),
               'trechos': [{'ini': round(a - base, 3), 'fim': round(b - base, 3)} for a, b, _ in trechos],
               'palavras': [{'p': w['p'].strip(), 'i': round(w['i'] - base, 3), 'f': round(w['f'] - base, 3)} for w in palavras if w['p'].strip()],
               'planos': planos, 'notas': [{'ini': round(a - base, 3), 'fim': round(b - base, 3), 'texto': t, 'cor': c} for a, b, t, c in CFG.get('notas', [])],
               'audio': {'voz': 1.0, 'musica': .14}, 'estilo': {'cores': ['amarelo', 'verde'], 'tamanho': 84, 'maiusculas': True, 'palavras_por_grupo': 3},
               'final': {'ativo': True, 'chamada': 'SIGA PARA MAIS DICAS', 'slogan': 'Te levamos ao próximo nível. Do iniciante ao atleta.'},
               'origem': {'video': 'entrevista RIC', 'base_s': base}}, open(out, 'w'), ensure_ascii=False)
    print('projeto', out, 'de', round(ini_t, 2), 'a', round(fim_t, 2)); sys.exit()

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
def desenha_legenda(d, t, y_topo):
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
    y = y_topo
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
GRAD = Image.linear_gradient('L').resize((W, 200)).point(lambda v: int(v * .45))
NOTAS = CFG.get('notas', [])
def desenha_nota(d, t_src, y):
    for a, b, txt, cor in NOTAS:
        if a <= t_src <= b:
            f = fonte(FONTE_X, 46); larg = d.textlength(txt, font=f)
            cc = {'verde': VERDE, 'vermelho': (255, 77, 77), 'branco': BRANCO}[cor]
            d.rounded_rectangle(((W - larg) / 2 - 28, y, (W + larg) / 2 + 28, y + 76), 38, fill=(15, 15, 15))
            d.text(((W - larg) / 2, y + 12), txt, font=f, fill=cc)
def quadro_fala(frame, t, idx_trecho, t_src):
    c = base_canvas()
    cx, sw = enquadra(t_src)
    cx = min(max(cx, sw / 2), 1920 - sw / 2)
    img = Image.fromarray(frame)
    jh = int(round(SRC_H * W / sw))                  # altura da janela no reel (1350 no close)
    jan = img.crop((int(cx - sw / 2), 0, int(cx + sw / 2), SRC_H)).resize((W, jh), Image.LANCZOS)
    if jh < JAN_H - 4:
        # fundo: o mesmo plano ampliado e desfocado, para não sobrar faixa preta
        bw = SRC_H * W / JAN_H; bx = min(max(cx, bw / 2), 1920 - bw / 2)
        fundo = img.crop((int(bx - bw / 2), 0, int(bx + bw / 2), SRC_H)).resize((W // 6, JAN_H // 6)).filter(ImageFilter.GaussianBlur(6)).resize((W, JAN_H))
        fundo = Image.blend(fundo, Image.new('RGB', (W, JAN_H), (0, 0, 0)), .55)
        c.paste(fundo, (0, JAN_Y)); jy = int(JAN_Y + (JAN_H - jh) / 2 - 110)     # centrado, com a legenda logo abaixo
    else: jy = JAN_Y
    c.paste(jan, (0, jy))
    d = ImageDraw.Draw(c)
    if jh >= JAN_H - 4:      # close: legenda por cima, com uma sombra curta
        c.paste(Image.new('RGB', (W, 200), (0, 0, 0)), (0, JAN_Y + JAN_H - 200), GRAD); y_leg = JAN_Y + JAN_H - 175
    else:                    # plano largo: legenda embaixo da imagem, sem cobrir ninguém
        y_leg = min(jy + jh + 60, JAN_Y + JAN_H - 175)
    if t < T_GANCHO:
        esc = Image.new('RGBA', (W, JAN_H), (0, 0, 0, 120)); c.paste(esc, (0, JAN_Y), esc); d = ImageDraw.Draw(c)
        desenha_gancho(d, True, t)
    else: desenha_gancho(d, False, t)
    desenha_nota(d, t_src, jy + 30)
    desenha_legenda(d, t, y_leg)
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
    texto_centro(d, '@luiznunest', 900, fonte(FONTE_X, 96), BRANCO, 0)
    texto_centro(d, '@intusfit', 1020, fonte(FONTE_X, 96), VERDE, 0)
    texto_centro(d, 'Te levamos ao próximo nível.', 1180, fonte(FONTE_B, 46), (215, 215, 215), 0)
    texto_centro(d, 'Do iniciante ao atleta.', 1245, fonte(FONTE_B, 46), (215, 215, 215), 0)
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
subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-i', AUDIO_LIMPO if os.path.exists(AUDIO_LIMPO) else VID, '-filter_complex', fil, '-map', '[ao]', '-ar', '48000', '-t', '%.3f' % DUR, aud], check=True)
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
