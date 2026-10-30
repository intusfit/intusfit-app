"""Gera os icones da Intus (aba do navegador, tela inicial do celular, app instalado) e as imagens de pre-visualizacao de links
(WhatsApp, Instagram, Facebook, X, Telegram, Google).
Saida: marca/icons/*, marca/og/teste-gratis.jpg e marca/og/site.jpg (1200x630), alem dos icones nas pastas que as paginas ja usam.
Fontes: app/painel/logo-icon-light.png, app/painel/logo-intus-dark.png (marca em claro, para fundo escuro) e as fotos dos profissionais.
Uso: python tools/marca/gerar-icones-og.py
A pasta img/ nao vai para o git (guarda upload), por isso tudo mora em marca/, que e versionada e publicada pelo deploy.
"""
import os, shutil
from PIL import Image, ImageDraw, ImageFont, ImageFilter

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
PAINEL = os.path.join(RAIZ, 'app', 'painel')
ICONES = os.path.join(RAIZ, 'marca', 'icons')
OG = os.path.join(RAIZ, 'marca', 'og')
os.makedirs(ICONES, exist_ok=True); os.makedirs(OG, exist_ok=True)
FUNDO = (10, 10, 10)
VERDE = (127, 255, 0)

simbolo = Image.open(os.path.join(PAINEL, 'logo-icon-light.png')).convert('RGBA')
simbolo = simbolo.crop(simbolo.split()[-1].getbbox())
marca = Image.open(os.path.join(PAINEL, 'logo-intus-dark.png')).convert('RGBA')
marca = marca.crop(marca.split()[-1].getbbox())


def brilho(tam, cx, cy, raio, cor, alfa):
    camada = Image.new('RGBA', tam, (0, 0, 0, 0))
    ImageDraw.Draw(camada).ellipse((cx - raio, cy - raio, cx + raio, cy + raio), fill=cor + (alfa,))
    return camada.filter(ImageFilter.GaussianBlur(raio / 2.4))


def icone(tam, arredondado=True, margem=0.2):
    base = Image.new('RGBA', (tam, tam), FUNDO + (255,))
    base.alpha_composite(brilho((tam, tam), int(tam * .92), int(tam * .08), int(tam * .5), VERDE, 46))
    alvo = int(tam * (1 - 2 * margem))
    s = simbolo.copy(); s.thumbnail((alvo, alvo), Image.LANCZOS)
    base.alpha_composite(s, ((tam - s.width) // 2, (tam - s.height) // 2))
    if arredondado:
        m = Image.new('L', (tam, tam), 0)
        ImageDraw.Draw(m).rounded_rectangle((0, 0, tam - 1, tam - 1), radius=int(tam * .22), fill=255)
        saida = Image.new('RGBA', (tam, tam), (0, 0, 0, 0)); saida.paste(base, (0, 0), m)
        return saida
    return base


def gravar_icones():
    for nome, tam, arred, marg in [('icon-512.png', 512, True, .2), ('icon-192.png', 192, True, .2), ('icon-32.png', 32, True, .14), ('icon-16.png', 16, True, .12), ('apple-touch-icon.png', 180, False, .2)]:
        icone(tam, arred, marg).save(os.path.join(ICONES, nome), optimize=True)
    icone(512, False, .3).save(os.path.join(ICONES, 'icon-maskable-512.png'), optimize=True)   # Android recorta em circulo: area segura maior
    ico = [icone(s, True, .14 if s <= 32 else .18) for s in (16, 32, 48)]
    ico[2].save(os.path.join(ICONES, 'favicon.ico'), sizes=[(16, 16), (32, 32), (48, 48)], append_images=ico[:2])
    # os endereços que o site e o painel ja usam (favicon.ico, favicon-192.png, apple-touch-icon) passam a mostrar a marca inteira
    for pasta in (RAIZ, PAINEL):
        shutil.copy(os.path.join(ICONES, 'favicon.ico'), os.path.join(pasta, 'favicon.ico'))
        shutil.copy(os.path.join(ICONES, 'icon-192.png'), os.path.join(pasta, 'favicon-192.png'))
    for nome in ('icon-192.png', 'icon-512.png', 'icon-maskable-512.png', 'apple-touch-icon.png'):
        shutil.copy(os.path.join(ICONES, nome), os.path.join(PAINEL, nome))


def fonte(nome, tam):
    return ImageFont.truetype('C:/Windows/Fonts/' + nome, tam)


def foto_retrato(arquivo, w, h, raio=26):
    f = Image.open(os.path.join(PAINEL, arquivo)).convert('RGB')
    lado = min(f.size); esq = (f.width - lado) // 2
    f = f.crop((esq, 0, esq + lado, lado))
    fw = int(lado * w / h)   # retrato: corta dos lados, mantem o rosto inteiro
    f = f.crop(((lado - fw) // 2, 0, (lado - fw) // 2 + fw, lado)).resize((w, h), Image.LANCZOS)
    m = Image.new('L', (w, h), 0); ImageDraw.Draw(m).rounded_rectangle((0, 0, w - 1, h - 1), radius=raio, fill=255)
    borda = Image.new('RGBA', (w + 8, h + 8), (0, 0, 0, 0))
    ImageDraw.Draw(borda).rounded_rectangle((0, 0, w + 7, h + 7), radius=raio + 4, fill=VERDE + (255,))
    return f, m, borda


def quebrar(d, texto, fnt, largura):
    linhas, atual = [], ''
    for p in texto.split():
        t = (atual + ' ' + p).strip()
        if d.textlength(t, font=fnt) <= largura: atual = t
        else: linhas.append(atual); atual = p
    if atual: linhas.append(atual)
    return linhas


def og_base():
    W, H = 1200, 630
    img = Image.new('RGBA', (W, H), FUNDO + (255,))
    img.alpha_composite(brilho((W, H), 1100, 60, 430, VERDE, 38))
    img.alpha_composite(brilho((W, H), 30, 650, 360, VERDE, 22))
    return img, ImageDraw.Draw(img), W, H


def og_teste_gratis():
    img, d, W, H = og_base()
    lg = marca.copy(); lg.thumbnail((330, 110), Image.LANCZOS)
    img.alpha_composite(lg, (64, 52))
    # selo
    f_selo = fonte('segoeuib.ttf', 25)
    txt = 'TESTE GRÁTIS DE 3 DIAS'
    tw = int(d.textlength(txt, font=f_selo))
    d.rounded_rectangle((64, 176, 64 + tw + 44, 176 + 52), radius=26, fill=VERDE)
    d.text((64 + 22, 176 + 8), txt, font=f_selo, fill=(10, 20, 0))
    # titulo
    f_t = fonte('seguibl.ttf', 56)
    y = 258
    for linha, cor in (('Treine com', (255, 255, 255)), ('acompanhamento', (255, 255, 255)), ('profissional de verdade', VERDE)):
        d.text((64, y), linha, font=f_t, fill=cor)
        y += 70
    f_s = fonte('segoeui.ttf', 28)
    d.text((64, y + 14), 'Acesso ao app agora. Sem cartão, sem compromisso.', font=f_s, fill=(190, 190, 190))
    d.text((64, H - 62), 'intusfit.com.br', font=fonte('segoeuib.ttf', 26), fill=VERDE)
    # profissionais
    pw, ph = 180, 290
    for i, (arq, nome, cargo) in enumerate((('foto-luiz.jpg', 'Luiz Nunes', 'Personal Trainer'), ('foto-ricardo.jpg', 'Ricardo Schmoeller', 'Personal Trainer'))):
        f, m, borda = foto_retrato(arq, pw, ph)
        x = 776 + i * (pw + 28)
        y0 = 130
        img.alpha_composite(borda, (x - 4, y0 - 4))
        img.paste(f, (x, y0), m)
        fn = fonte('segoeuib.ttf', 21)
        linhas = quebrar(d, nome, fn, pw + 20)
        for k, linha in enumerate(linhas):
            d.text((x, y0 + ph + 16 + k * 26), linha, font=fn, fill=(255, 255, 255))
        d.text((x, y0 + ph + 16 + len(linhas) * 26), cargo, font=fonte('segoeui.ttf', 18), fill=(170, 170, 170))
    img.convert('RGB').save(os.path.join(OG, 'teste-gratis.jpg'), quality=88, optimize=True, progressive=True)


def og_site():
    img, d, W, H = og_base()
    lg = marca.copy(); lg.thumbnail((700, 240), Image.LANCZOS)
    img.alpha_composite(lg, (70, 110))
    d.text((70, 340), 'Te levamos ao próximo nível', font=fonte('seguibl.ttf', 58), fill=(255, 255, 255))
    d.text((70, 422), 'Consultoria online de treino e nutrição.', font=fonte('segoeui.ttf', 32), fill=(200, 200, 200))
    d.text((70, 472), 'Do iniciante ao atleta.', font=fonte('segoeuib.ttf', 32), fill=VERDE)
    d.text((70, H - 62), 'intusfit.com.br', font=fonte('segoeuib.ttf', 26), fill=(170, 170, 170))
    img.convert('RGB').save(os.path.join(OG, 'site.jpg'), quality=88, optimize=True, progressive=True)


gravar_icones()
og_teste_gratis()
og_site()
for nome in sorted(os.listdir(OG)):
    print(nome, os.path.getsize(os.path.join(OG, nome)) // 1024, 'KB')
