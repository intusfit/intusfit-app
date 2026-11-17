"""Gera os ícones, a splash e os materiais de loja do app nativo (Capacitor) com a marca da Intus.
Saída (tudo dentro de mobile/):
  ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png   ícone iOS 1024x1024, RGB, sem alfa e sem cantos arredondados (a Apple aplica a máscara)
  ios/App/App/Assets.xcassets/Splash.imageset/splash-2732x2732*.png    splash iOS (3 arquivos iguais, como no modelo do Capacitor)
  android/app/src/main/res/mipmap-*/ic_launcher_foreground.png         camada de frente do ícone adaptativo (108 dp; o miolo cabe no círculo de 66%)
  android/app/src/main/res/mipmap-*/ic_launcher.png e ic_launcher_round.png   ícones das versões antigas do Android (API 24 e 25)
  android/app/src/main/res/drawable*/splash.png                        splash Android (retrato e paisagem, todas as densidades)
  store-assets/app-store-icon-1024.png, google-play-icon-512.png, google-play-feature-graphic-1024x500.png
Fontes: app/painel/logo-icon-light.png (símbolo em claro, para fundo escuro) e app/painel/logo-intus-dark.png (logotipo em claro).
Uso: python tools/mobile/gerar-assets-nativos.py   (precisa de Pillow e numpy)
A cor de fundo do ícone adaptativo e a da splash estão em mobile/android/app/src/main/res/values/ (ic_launcher_background.xml e
colors_intus.xml): se mudar FUNDO ou FUNDO_SPLASH aqui, mude lá também (o script confere e avisa).
"""
import os, re, sys
import numpy as np
from PIL import Image, ImageDraw, ImageFilter, ImageFont

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
PAINEL = os.path.join(RAIZ, 'app', 'painel')
MOBILE = os.path.join(RAIZ, 'mobile')
RES = os.path.join(MOBILE, 'android', 'app', 'src', 'main', 'res')
ASSETS_IOS = os.path.join(MOBILE, 'ios', 'App', 'App', 'Assets.xcassets')
LOJA = os.path.join(MOBILE, 'store-assets')

FUNDO = (10, 10, 10)          # mesmo fundo dos ícones do site (marca/icons)
FUNDO_SPLASH = (13, 13, 13)   # igual ao fundo da tela de login do app (#0d0d0d): sem salto de cor da splash para o app
VERDE = (127, 255, 0)


def recortar(img):
    img = img.convert('RGBA')
    return img.crop(img.split()[-1].getbbox())


simbolo = recortar(Image.open(os.path.join(PAINEL, 'logo-icon-light.png')))
logotipo = recortar(Image.open(os.path.join(PAINEL, 'logo-intus-dark.png')))


def hexa(cor):
    return '#%02X%02X%02X' % cor


def brilho(tam, cx, cy, raio, cor, alfa):
    camada = Image.new('RGBA', tam, (0, 0, 0, 0))
    ImageDraw.Draw(camada).ellipse((cx - raio, cy - raio, cx + raio, cy + raio), fill=cor + (alfa,))
    return camada.filter(ImageFilter.GaussianBlur(raio / 2.4))


def fundo_marca(tam):
    """Quadrado escuro com o brilho verde no canto, como os ícones do site."""
    base = Image.new('RGBA', (tam, tam), FUNDO + (255,))
    base.alpha_composite(brilho((tam, tam), int(tam * .92), int(tam * .08), int(tam * .5), VERDE, 46))
    return base


def colar_no_centro(base, img, cx=None, cy=None):
    cx = base.width / 2 if cx is None else cx
    cy = base.height / 2 if cy is None else cy
    base.alpha_composite(img, (int(round(cx - img.width / 2)), int(round(cy - img.height / 2))))


def simbolo_por_largura(largura):
    s = simbolo.copy()
    s.thumbnail((largura, largura), Image.LANCZOS)
    return s


# ── símbolo dentro de um círculo ─────────────────────────────────────────────────────────────────────────────
# O ícone adaptativo do Android é recortado pelo launcher (círculo, quadrado arredondado...). A área garantida é o círculo de
# 66% do lado, então o ponto mais distante do símbolo precisa ficar a até 33% do lado do centro. O símbolo não é simétrico (o
# vértice do triângulo de cima fica no canto), então o centro do círculo é o que MENOS afasta o ponto mais distante.
_alpha = np.array(simbolo.split()[-1]) > 20
_ys, _xs = np.nonzero(_alpha)
_pts = np.stack([_xs[::3], _ys[::3]], 1).astype(float)


def _melhor_centro():
    h, w = _alpha.shape
    melhor = None
    for cy in np.arange(h * .35, h * .65, 2.0):
        for cx in np.arange(w * .35, w * .65, 2.0):
            d = np.sqrt(((_pts - (cx, cy)) ** 2).sum(1)).max()
            if melhor is None or d < melhor[0]:
                melhor = (d, cx, cy)
    return melhor


_R, _CX, _CY = _melhor_centro()


def camada_no_circulo(tam, fracao_raio):
    """Camada transparente tam x tam com o símbolo inteiro dentro do círculo de raio fracao_raio*tam, centrado."""
    k = fracao_raio * tam / _R
    s = simbolo.resize((max(1, round(simbolo.width * k)), max(1, round(simbolo.height * k))), Image.LANCZOS)
    camada = Image.new('RGBA', (tam, tam), (0, 0, 0, 0))
    camada.alpha_composite(s, (round(tam / 2 - _CX * k), round(tam / 2 - _CY * k)))
    # conferência de verdade: nenhum pixel visível fora do círculo (folga de 1,5 px por arredondamento)
    a = np.array(camada.split()[-1]) > 20
    yy, xx = np.nonzero(a)
    dist = np.sqrt((xx + .5 - tam / 2) ** 2 + (yy + .5 - tam / 2) ** 2).max()
    assert dist <= fracao_raio * tam + 1.5, 'símbolo fora do círculo de segurança: %.1f > %.1f' % (dist, fracao_raio * tam)
    return camada


# ── ícones ───────────────────────────────────────────────────────────────────────────────────────────────────
def icone_cheio(tam, margem=.2):
    """Ícone quadrado de borda a borda (sem cantos arredondados e sem alfa): o que a App Store e o Google Play pedem."""
    base = fundo_marca(tam)
    colar_no_centro(base, simbolo_por_largura(int(tam * (1 - 2 * margem))))
    return base.convert('RGB')


def salvar(img, caminho):
    os.makedirs(os.path.dirname(caminho), exist_ok=True)
    img.save(caminho, optimize=True)
    print(os.path.relpath(caminho, RAIZ), img.size, img.mode)


def icone_legado(tam, redondo):
    """Ícone das versões antigas do Android (API 24 e 25, sem ícone adaptativo). Desenha em 4x e reduz, para a borda ficar suave."""
    g = tam * 4
    base = fundo_marca(g)
    if redondo:
        base.alpha_composite(camada_no_circulo(g, .40))
        m = Image.new('L', (g, g), 0)
        ImageDraw.Draw(m).ellipse((0, 0, g - 1, g - 1), fill=255)
    else:
        colar_no_centro(base, simbolo_por_largura(int(g * .6)))
        m = Image.new('L', (g, g), 0)
        ImageDraw.Draw(m).rounded_rectangle((0, 0, g - 1, g - 1), radius=int(g * .22), fill=255)
    saida = Image.new('RGBA', (g, g), (0, 0, 0, 0))
    saida.paste(base, (0, 0), m)
    return saida.resize((tam, tam), Image.LANCZOS)


def gerar_icones():
    ios = icone_cheio(1024)
    salvar(ios, os.path.join(ASSETS_IOS, 'AppIcon.appiconset', 'AppIcon-512@2x.png'))
    salvar(ios, os.path.join(LOJA, 'app-store-icon-1024.png'))
    salvar(icone_cheio(512), os.path.join(LOJA, 'google-play-icon-512.png'))
    for dens, d in (('mdpi', 1), ('hdpi', 1.5), ('xhdpi', 2), ('xxhdpi', 3), ('xxxhdpi', 4)):
        pasta = os.path.join(RES, 'mipmap-' + dens)
        salvar(camada_no_circulo(round(108 * d), .33), os.path.join(pasta, 'ic_launcher_foreground.png'))
        salvar(icone_legado(round(48 * d), False), os.path.join(pasta, 'ic_launcher.png'))
        salvar(icone_legado(round(48 * d), True), os.path.join(pasta, 'ic_launcher_round.png'))


# ── splash ───────────────────────────────────────────────────────────────────────────────────────────────────
def splash(w, h, fracao):
    """Fundo liso com o símbolo no centro. No iOS a imagem é 'preencher' e no Android pode ser esticada: por isso o símbolo é pequeno,
    fica no meio e o fundo não tem nenhum detalhe que apareça cortado ou deformado."""
    img = Image.new('RGBA', (w, h), FUNDO_SPLASH + (255,))
    colar_no_centro(img, simbolo_por_largura(int(min(w, h) * fracao)))
    return img.convert('RGB')


def gerar_splash():
    for nome in ('splash-2732x2732.png', 'splash-2732x2732-1.png', 'splash-2732x2732-2.png'):
        salvar(splash(2732, 2732, .17), os.path.join(ASSETS_IOS, 'Splash.imageset', nome))
    tamanhos = {
        'drawable': (480, 320),
        'drawable-port-mdpi': (320, 480), 'drawable-port-hdpi': (480, 800), 'drawable-port-xhdpi': (720, 1280),
        'drawable-port-xxhdpi': (960, 1600), 'drawable-port-xxxhdpi': (1280, 1920),
        'drawable-land-mdpi': (480, 320), 'drawable-land-hdpi': (800, 480), 'drawable-land-xhdpi': (1280, 720),
        'drawable-land-xxhdpi': (1600, 960), 'drawable-land-xxxhdpi': (1920, 1280),
    }
    for pasta, (w, h) in tamanhos.items():
        salvar(splash(w, h, .24), os.path.join(RES, pasta, 'splash.png'))


# ── imagem de destaque do Google Play (1024x500, PNG sem alfa) ──────────────────────────────────────────────
def fonte(nome_linux, nome_windows, tam):
    for caminho in ('/usr/share/fonts/opentype/inter/' + nome_linux, 'C:/Windows/Fonts/' + nome_windows):
        if os.path.exists(caminho):
            return ImageFont.truetype(caminho, tam)
    sys.exit('Fonte não encontrada (Inter ou Segoe UI).')


def quebrar(d, texto, fnt, largura):
    linhas, atual = [], ''
    for p in texto.split():
        t = (atual + ' ' + p).strip()
        if d.textlength(t, font=fnt) <= largura:
            atual = t
        else:
            linhas.append(atual)
            atual = p
    if atual:
        linhas.append(atual)
    return linhas


def gerar_destaque_play():
    W, H = 1024, 500
    img = Image.new('RGBA', (W, H), FUNDO + (255,))
    img.alpha_composite(brilho((W, H), 930, 40, 380, VERDE, 40))
    img.alpha_composite(brilho((W, H), 20, 520, 280, VERDE, 22))
    d = ImageDraw.Draw(img)
    # símbolo grande à direita (a imagem pode ser cortada nas bordas pelo Play: nada importante nos 15 px das margens)
    s = simbolo_por_largura(300)
    img.alpha_composite(s, (W - 64 - s.width, (H - s.height) // 2))
    # texto à esquerda
    lg = logotipo.copy()
    lg.thumbnail((380, 110), Image.LANCZOS)
    img.alpha_composite(lg, (64, 96))
    f_t = fonte('Inter-Bold.otf', 'segoeuib.ttf', 36)
    y = 96 + lg.height + 38
    for linha in quebrar(d, 'Te levamos ao próximo nível', f_t, 540):
        d.text((64, y), linha, font=f_t, fill=(255, 255, 255))
        y += 52
    d.rectangle((64, y + 12, 64 + 72, y + 16), fill=VERDE)
    f_s = fonte('Inter-Regular.otf', 'segoeui.ttf', 24)
    for linha in ('Consultoria online de treino e nutrição.', 'Do iniciante ao atleta.'):   # uma frase por linha
        d.text((64, y + 34), linha, font=f_s, fill=(200, 200, 200))
        y += 32
    salvar(img.convert('RGB'), os.path.join(LOJA, 'google-play-feature-graphic-1024x500.png'))


def conferir_xml():
    """As cores do ícone e da splash moram nos XMLs do Android; avisa se não baterem com as daqui."""
    erros = []
    for arq, nome, cor in (('ic_launcher_background.xml', 'ic_launcher_background', FUNDO), ('colors_intus.xml', 'intus_splash_background', FUNDO_SPLASH)):
        caminho = os.path.join(RES, 'values', arq)
        achado = re.search(r'name="%s">(#[0-9A-Fa-f]{6})<' % nome, open(caminho, encoding='utf-8').read()) if os.path.exists(caminho) else None
        if not achado or achado.group(1).upper() != hexa(cor):
            erros.append('%s: esperado %s, achei %s' % (arq, hexa(cor), achado.group(1) if achado else 'nada'))
    if erros:
        sys.exit('Cor diferente entre o script e o XML do Android: ' + '; '.join(erros))


if __name__ == '__main__':
    gerar_icones()
    gerar_splash()
    gerar_destaque_play()
    conferir_xml()
    print('ok')
