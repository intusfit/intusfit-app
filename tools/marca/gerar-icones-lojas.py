"""Gera os icones e a tela de abertura (splash) dos apps nativos (iOS e Android) e os materiais graficos das lojas, com a marca da Intus.
Substitui os desenhos de exemplo do Capacitor (X azul sobre grade) que vinham de fabrica no projeto de mobile/.

Saida (tudo versionado):
  mobile/ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png   1024x1024, RGB, SEM alfa e SEM cantos arredondados (a Apple aplica a mascara)
  mobile/ios/App/App/Assets.xcassets/Splash.imageset/splash-2732x2732*.png    tela de abertura do iOS (o storyboard usa "aspect fill": so o centro aparece)
  mobile/android/app/src/main/res/mipmap-*/ic_launcher_{foreground,fundo,monochrome}.png   icone adaptativo (camadas de 108 dp; marca dentro da zona segura de 66 dp)
  mobile/android/app/src/main/res/mipmap-*/ic_launcher{,_round}.png                       icone antigo (Android 7.x), quadrado arredondado e redondo
  mobile/android/app/src/main/res/drawable*/splash.png                                    abertura do Android (formato 9:18: estica pouco em 16:9 e em 20:9)
  mobile/store-assets/app-store-icon-1024.png, google-play-icon-512.png, google-play-feature-graphic-1024x500.png

Fontes: app/painel/logo-icon-light.png (simbolo, tons claros para fundo escuro) e app/painel/logo-intus-dark.png (marca com o nome).
Uso: python3 tools/marca/gerar-icones-lojas.py          (precisa de Pillow e numpy; a fonte Inter, ou Segoe UI no Windows)
Depois de mudar a marca: rode este script, o teste (node testes/icones_lojas.js) e "cd mobile && npm run sync" antes de gerar o build.
A composicao do icone repete a de tools/marca/gerar-icones-og.py (fundo #0A0A0A, brilho verde no canto, simbolo a 60% do quadro), entao o
icone do app, o do site instalado e o da aba do navegador sao a mesma marca.
"""
import math, os, random, sys
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
PAINEL = os.path.join(RAIZ, 'app', 'painel')
MOBILE = os.path.join(RAIZ, 'mobile')
IOS = os.path.join(MOBILE, 'ios', 'App', 'App', 'Assets.xcassets')
RES = os.path.join(MOBILE, 'android', 'app', 'src', 'main', 'res')
LOJAS = os.path.join(MOBILE, 'store-assets')
FUNDO = (10, 10, 10)
VERDE = (127, 255, 0)

# Android: fator de densidade. Icone antigo = 48 dp; camadas do icone adaptativo = 108 dp, dos quais so os 66 dp centrais sao zona segura.
DENSIDADES = {'mdpi': 1.0, 'hdpi': 1.5, 'xhdpi': 2.0, 'xxhdpi': 3.0, 'xxxhdpi': 4.0}
RAIO_SEGURO_DP = 33.0          # a marca inteira cabe num circulo de 66 dp de diametro (regra da Google)
RAIO_MARCA_DP = 29.0           # 4 dp de folga: com mascara redonda a marca nao encosta na borda e aguenta o efeito de paralaxe dos launchers

simbolo = Image.open(os.path.join(PAINEL, 'logo-icon-light.png')).convert('RGBA')
simbolo = simbolo.crop(simbolo.split()[-1].getbbox())
marca = Image.open(os.path.join(PAINEL, 'logo-intus-dark.png')).convert('RGBA')
marca = marca.crop(marca.split()[-1].getbbox())


def circulo_minimo(pontos):
    """Menor circulo que contem todos os pontos (algoritmo de Welzl, versao iterativa). Devolve (cx, cy, raio)."""
    def circ2(a, b):
        return ((a[0] + b[0]) / 2, (a[1] + b[1]) / 2, math.hypot(a[0] - b[0], a[1] - b[1]) / 2)

    def circ3(a, b, c):
        d = 2 * (a[0] * (b[1] - c[1]) + b[0] * (c[1] - a[1]) + c[0] * (a[1] - b[1]))
        if abs(d) < 1e-12:
            return None
        ux = ((a[0] ** 2 + a[1] ** 2) * (b[1] - c[1]) + (b[0] ** 2 + b[1] ** 2) * (c[1] - a[1]) + (c[0] ** 2 + c[1] ** 2) * (a[1] - b[1])) / d
        uy = ((a[0] ** 2 + a[1] ** 2) * (c[0] - b[0]) + (b[0] ** 2 + b[1] ** 2) * (a[0] - c[0]) + (c[0] ** 2 + c[1] ** 2) * (b[0] - a[0])) / d
        return (ux, uy, math.hypot(a[0] - ux, a[1] - uy))

    def dentro(c, p):
        return math.hypot(p[0] - c[0], p[1] - c[1]) <= c[2] + 1e-7

    pts = list(pontos)
    random.Random(7).shuffle(pts)
    c = None
    for i, p in enumerate(pts):
        if c is None or not dentro(c, p):
            c = (p[0], p[1], 0.0)
            for j in range(i):
                if not dentro(c, pts[j]):
                    c = circ2(p, pts[j])
                    for k in range(j):
                        if not dentro(c, pts[k]):
                            c3 = circ3(p, pts[j], pts[k])
                            if c3:
                                c = c3
    return c


def _circulo_do_simbolo():
    """Menor circulo em volta do simbolo (em pixels do simbolo recortado). Os vertices da casca convexa de uma figura em pixels sao os extremos
    de alguma linha, entao basta olhar o primeiro e o ultimo pixel opaco de cada linha."""
    alfa = np.array(simbolo.split()[-1]) > 127
    pontos = []
    for y in range(alfa.shape[0]):
        xs = np.flatnonzero(alfa[y])
        if xs.size:
            pontos += [(xs[0], y), (xs[-1] + 1, y), (xs[0], y + 1), (xs[-1] + 1, y + 1)]
    return circulo_minimo(pontos)


CIRC = _circulo_do_simbolo()


def brilho(tam, cx, cy, raio, cor, alfa):
    camada = Image.new('RGBA', tam, (0, 0, 0, 0))
    ImageDraw.Draw(camada).ellipse((cx - raio, cy - raio, cx + raio, cy + raio), fill=cor + (alfa,))
    return camada.filter(ImageFilter.GaussianBlur(raio / 2.4))


def fundo_marca(w, h, ref=None):
    """Fundo da marca: #0A0A0A com o brilho verde no canto de cima, a direita. `ref` = (x, y, lado) do quadrado de referencia onde o brilho
    deve cair (no icone adaptativo, a janela visivel de 72 dp dentro da camada de 108 dp); sem ele, o quadro inteiro."""
    base = Image.new('RGBA', (w, h), FUNDO + (255,))
    x0, y0, lado = ref if ref else (0, 0, min(w, h))
    base.alpha_composite(brilho((w, h), int(x0 + lado * .92), int(y0 + lado * .08), int(lado * .5), VERDE, 46))
    return base


def colocar(tela, camada, cx, cy):
    """Cola `camada` (RGBA) na `tela` com o centro em (cx, cy)."""
    tela.alpha_composite(camada, (int(round(cx - camada.width / 2)), int(round(cy - camada.height / 2))))


def simbolo_de_lado(lado):
    """Simbolo redimensionado para que o MAIOR lado do recorte valha `lado` pixels."""
    esc = lado / max(simbolo.size)
    return simbolo.resize((max(1, round(simbolo.width * esc)), max(1, round(simbolo.height * esc))), Image.LANCZOS)


def icone_quadrado(tam, margem=.2):
    """Icone completo (fundo + simbolo), quadrado, sem cantos arredondados, 100% opaco. margem=.2 deixa o simbolo com 60% do quadro,
    igual ao icone do site (marca/icons/icon-512.png)."""
    base = fundo_marca(tam, tam)
    colocar(base, simbolo_de_lado(tam * (1 - 2 * margem)), tam / 2, tam / 2)
    return base


def mascara(tam, forma, raio=.22):
    m = Image.new('L', (tam * 4, tam * 4), 0)       # 4x e reduz: borda suave
    d = ImageDraw.Draw(m)
    if forma == 'circulo':
        d.ellipse((0, 0, tam * 4 - 1, tam * 4 - 1), fill=255)
    else:
        d.rounded_rectangle((0, 0, tam * 4 - 1, tam * 4 - 1), radius=int(tam * 4 * raio), fill=255)
    return m.resize((tam, tam), Image.LANCZOS)


def recortar(img, forma, raio=.22):
    saida = Image.new('RGBA', img.size, (0, 0, 0, 0))
    saida.paste(img, (0, 0), mascara(img.width, forma, raio))
    return saida


def camada_adaptativa(lado, tipo):
    """Camada de 108 dp (`lado` px) do icone adaptativo do Android. A marca fica dentro do circulo de 66 dp: o centro do menor circulo que a
    contem vai para o centro da camada, e o raio dele vira RAIO_MARCA_DP."""
    esc = (RAIO_MARCA_DP / 108.0 * lado) / CIRC[2]
    w, h = max(1, round(simbolo.width * esc)), max(1, round(simbolo.height * esc))
    s = simbolo.resize((w, h), Image.LANCZOS)
    x, y = round(lado / 2 - CIRC[0] * esc), round(lado / 2 - CIRC[1] * esc)
    if tipo == 'fundo':
        janela = lado * 72.0 / 108.0
        return fundo_marca(lado, lado, ((lado - janela) / 2, (lado - janela) / 2, janela))
    if tipo == 'monocromatico':
        # o Android 13+ pinta so o alfa da camada com a cor do tema; o brilho de cada triangulo vira transparencia, para as tres camadas da marca
        # continuarem se distinguindo (do mais escuro, ~54% opaco, ao branco, 100%)
        a = np.array(s).astype(np.float32)
        luz = a[..., :3].mean(axis=2) / 255.0
        a[..., :3] = 255
        a[..., 3] = a[..., 3] * (0.30 + 0.70 * luz)
        s = Image.fromarray(a.astype(np.uint8), 'RGBA')
    camada = Image.new('RGBA', (lado, lado), (0, 0, 0, 0))
    camada.alpha_composite(s, (x, y))
    return camada


def salvar(img, caminho, modo=None):
    os.makedirs(os.path.dirname(caminho), exist_ok=True)
    if modo:
        img = img.convert(modo)
    img.save(caminho, optimize=True)
    return caminho


def abertura(w, h, lado_simbolo):
    """Tela de abertura: fundo da marca e o simbolo no centro."""
    img = fundo_marca(w, h)
    colocar(img, simbolo_de_lado(lado_simbolo), w / 2, h / 2)
    return img


def fonte(peso, tam):
    candidatos = {
        'forte': ['/usr/share/fonts/opentype/inter/Inter-ExtraBold.otf', 'C:/Windows/Fonts/seguibl.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
        'negrito': ['/usr/share/fonts/opentype/inter/Inter-Bold.otf', 'C:/Windows/Fonts/segoeuib.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'],
        'normal': ['/usr/share/fonts/opentype/inter/Inter-Regular.otf', 'C:/Windows/Fonts/segoeui.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
    }[peso]
    for c in candidatos:
        if os.path.exists(c):
            return ImageFont.truetype(c, tam)
    raise SystemExit('Nenhuma fonte encontrada para %s: %s' % (peso, candidatos))


def imagem_de_destaque():
    """Imagem de destaque da Google Play (1024x500, RGB). Texto so na area central (a Play corta as bordas em alguns lugares) e so o que o
    site ja diz de si mesmo: sem promessa de resultado, prazo ou numero."""
    W, H = 1024, 500
    img = fundo_marca(W, H)
    img.alpha_composite(brilho((W, H), 860, 90, 330, VERDE, 40))
    img.alpha_composite(brilho((W, H), 40, 540, 260, VERDE, 22))
    lg = marca.copy()
    lg.thumbnail((300, 90), Image.LANCZOS)
    img.alpha_composite(lg, (72, 62))
    d = ImageDraw.Draw(img)
    f_t = fonte('forte', 50)
    y = 176
    for linha in ('Consultoria online', 'de treino e nutrição'):
        d.text((72, y), linha, font=f_t, fill=(255, 255, 255))
        y += 62
    d.text((72, y + 22), 'Do iniciante ao atleta.', font=fonte('negrito', 32), fill=VERDE)
    colocar(img, simbolo_de_lado(330), 800, 262)
    return img.convert('RGB')


def principal():
    feitos = []

    # iOS: icone unico de 1024, sem alfa; a Apple arredonda os cantos e gera os outros tamanhos
    ios = icone_quadrado(1024)
    feitos.append(salvar(ios, os.path.join(IOS, 'AppIcon.appiconset', 'AppIcon-512@2x.png'), 'RGB'))
    feitos.append(salvar(ios, os.path.join(LOJAS, 'app-store-icon-1024.png'), 'RGB'))

    # iOS: abertura 2732x2732 (o mesmo desenho nas tres escalas do conjunto); marca com ~20% do lado
    splash_ios = abertura(2732, 2732, 560)
    for nome in ('splash-2732x2732.png', 'splash-2732x2732-1.png', 'splash-2732x2732-2.png'):
        feitos.append(salvar(splash_ios, os.path.join(IOS, 'Splash.imageset', nome), 'RGB'))

    # Google Play: icone de 512 (a Play aplica a mascara, entao quadrado cheio) e imagem de destaque
    feitos.append(salvar(icone_quadrado(512), os.path.join(LOJAS, 'google-play-icon-512.png'), 'RGBA'))
    feitos.append(salvar(imagem_de_destaque(), os.path.join(LOJAS, 'google-play-feature-graphic-1024x500.png'), 'RGB'))

    # Android: camadas adaptativas e icones antigos, por densidade
    for dens, f in DENSIDADES.items():
        pasta = os.path.join(RES, 'mipmap-' + dens)
        lado = round(108 * f)
        feitos.append(salvar(camada_adaptativa(lado, 'primeiro-plano'), os.path.join(pasta, 'ic_launcher_foreground.png')))
        feitos.append(salvar(camada_adaptativa(lado, 'fundo'), os.path.join(pasta, 'ic_launcher_fundo.png'), 'RGB'))
        feitos.append(salvar(camada_adaptativa(lado, 'monocromatico'), os.path.join(pasta, 'ic_launcher_monochrome.png')))
        antigo = round(48 * f)
        feitos.append(salvar(recortar(icone_quadrado(antigo * 4).resize((antigo, antigo), Image.LANCZOS), 'quadrado', .16), os.path.join(pasta, 'ic_launcher.png')))
        feitos.append(salvar(recortar(icone_quadrado(antigo * 4).resize((antigo, antigo), Image.LANCZOS), 'circulo'), os.path.join(pasta, 'ic_launcher_round.png')))

    # Android: abertura. Formato 9:18 (o Android estica a imagem para a tela toda); o paisagem so existe por compatibilidade (o app e so retrato).
    for dens, f in DENSIDADES.items():
        w, h = round(320 * f), round(640 * f)
        feitos.append(salvar(abertura(w, h, round(w * .30)), os.path.join(RES, 'drawable-port-' + dens, 'splash.png'), 'RGB'))
        feitos.append(salvar(abertura(h, w, round(w * .30)), os.path.join(RES, 'drawable-land-' + dens, 'splash.png'), 'RGB'))
    feitos.append(salvar(abertura(320, 640, 96), os.path.join(RES, 'drawable', 'splash.png'), 'RGB'))
    return feitos


if __name__ == '__main__':
    arquivos = principal()
    print('Menor circulo em volta da marca: centro (%.1f, %.1f), raio %.1f px (marca de %dx%d)' % (CIRC[0], CIRC[1], CIRC[2], simbolo.width, simbolo.height))
    for a in arquivos:
        im = Image.open(a)
        print('%-96s %dx%d %s %d KB' % (os.path.relpath(a, RAIZ), im.width, im.height, im.mode, os.path.getsize(a) // 1024 or 1))
    sys.exit(0)
