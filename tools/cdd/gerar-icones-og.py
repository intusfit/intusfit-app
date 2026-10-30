"""Gera os icones do CDD (aba do navegador, tela inicial do celular) e a imagem de pre-visualizacao de links (WhatsApp, Instagram, Facebook, X).
Saida: cdd/marca/icons/*.png, favicon.ico e cdd/marca/lp/og.jpg (1200x630).
Fontes: cdd/marca/logo-cdd-branca.png (logo com transparencia) e cdd/marca/lp/luiz.jpg.
Uso: python tools/cdd/gerar-icones-og.py
"""
import os
from PIL import Image, ImageDraw, ImageFont, ImageFilter

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
MARCA = os.path.join(RAIZ, 'cdd', 'marca')
ICONES = os.path.join(MARCA, 'icons')
os.makedirs(ICONES, exist_ok=True)
FUNDO = (11, 11, 15)

logo = Image.open(os.path.join(MARCA, 'logo-cdd-branca.png')).convert('RGBA')
logo_cheio = logo.crop(logo.split()[-1].getbbox())           # logo inteiro (cadeado, nome e anilha)
cadeado = logo_cheio.crop((0, 0, 250, logo_cheio.height))     # so o cadeado, que le bem em tamanho pequeno

def brilho(tam, cx, cy, raio, cor, alfa):
    camada = Image.new('RGBA', tam, (0, 0, 0, 0))
    d = ImageDraw.Draw(camada)
    d.ellipse((cx - raio, cy - raio, cx + raio, cy + raio), fill=cor + (alfa,))
    return camada.filter(ImageFilter.GaussianBlur(raio / 2.2))

def icone(tam, arredondado=True, margem=0.17):
    base = Image.new('RGBA', (tam, tam), FUNDO + (255,))
    base.alpha_composite(brilho((tam, tam), int(tam * .5), int(tam * 1.0), int(tam * .55), (226, 39, 45), 150))
    alvo_h = int(tam * (1 - 2 * margem))
    c = cadeado.copy(); c.thumbnail((alvo_h, alvo_h), Image.LANCZOS)
    base.alpha_composite(c, ((tam - c.width) // 2, (tam - c.height) // 2))
    if arredondado:
        mascara = Image.new('L', (tam, tam), 0)
        ImageDraw.Draw(mascara).rounded_rectangle((0, 0, tam - 1, tam - 1), radius=int(tam * .22), fill=255)
        saida = Image.new('RGBA', (tam, tam), (0, 0, 0, 0)); saida.paste(base, (0, 0), mascara)
        return saida
    return base

for nome, tam, arred in [('icon-512.png', 512, True), ('icon-192.png', 192, True), ('icon-32.png', 32, True), ('icon-16.png', 16, True), ('apple-touch-icon.png', 180, False)]:
    icone(tam, arred, 0.2 if tam <= 32 else 0.17).save(os.path.join(ICONES, nome), optimize=True)
    print(nome, tam)
# maskable: area segura maior (Android recorta em circulo)
icone(512, False, 0.26).save(os.path.join(ICONES, 'icon-maskable-512.png'), optimize=True)
imgs = [icone(s, True, 0.2 if s <= 32 else 0.17).convert('RGBA') for s in (16, 32, 48)]
imgs[2].save(os.path.join(ICONES, 'favicon.ico'), sizes=[(16, 16), (32, 32), (48, 48)], append_images=imgs[:2])
print('favicon.ico')

# imagem de pre-visualizacao dos links (1200x630)
W, H = 1200, 630
og = Image.new('RGBA', (W, H), FUNDO + (255,))
og.alpha_composite(brilho((W, H), 980, 120, 420, (226, 39, 45), 120))
og.alpha_composite(brilho((W, H), 40, 640, 380, (155, 18, 24), 130))
d = ImageDraw.Draw(og)
fonte_b = ImageFont.truetype('C:/Windows/Fonts/segoeuib.ttf', 50)
fonte_m = ImageFont.truetype('C:/Windows/Fonts/segoeuib.ttf', 30)
fonte_p = ImageFont.truetype('C:/Windows/Fonts/segoeui.ttf', 28)
lg = logo_cheio.copy(); lg.thumbnail((470, 200), Image.LANCZOS)
og.alpha_composite(lg, (70, 70))
d.text((70, 306), 'Programa de treino', font=fonte_b, fill=(255, 255, 255))
d.text((70, 366), 'em fases para mulheres', font=fonte_b, fill=(255, 255, 255))
d.text((70, 460), 'Com o treinador Luiz Nunes', font=fonte_m, fill=(255, 90, 79))
d.text((70, 508), 'CREF 25204-G/PR  ·  10 protocolos em fases', font=fonte_p, fill=(190, 186, 200))
foto = Image.open(os.path.join(MARCA, 'lp', 'luiz.jpg')).convert('RGB')
lado = 470
foto = foto.resize((lado, lado), Image.LANCZOS)
m = Image.new('L', (lado, lado), 0); ImageDraw.Draw(m).rounded_rectangle((0, 0, lado - 1, lado - 1), radius=36, fill=255)
fx, fy = W - lado - 70, (H - lado) // 2
borda = Image.new('RGBA', (lado + 8, lado + 8), (0, 0, 0, 0))
ImageDraw.Draw(borda).rounded_rectangle((0, 0, lado + 7, lado + 7), radius=40, fill=(226, 39, 45, 255))
og.alpha_composite(borda, (fx - 4, fy - 4))
og.paste(foto, (fx, fy), m)
og.convert('RGB').save(os.path.join(MARCA, 'lp', 'og.jpg'), quality=86, optimize=True, progressive=True)
print('og.jpg', os.path.getsize(os.path.join(MARCA, 'lp', 'og.jpg')) // 1024, 'KB')
