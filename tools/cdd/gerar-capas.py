#!/usr/bin/env python
"""
Gera as capas dos módulos do CDD a partir da foto de referência (Capa módulos cdd.png).

Para cada módulo cria dois arquivos em cdd/marca/capas/:
  <slug>.jpg        pôster vertical 2:3 (640x960) COM o título escrito e a logo do CDD (fila Netflix do app e painel)
  <slug>-wide.jpg   banner horizontal 16:9 (1280x720) SEM título (o app escreve o título por cima: destaque e cabeçalho do módulo)

Cada módulo tem enquadramento, cor e clima próprios (derivados da cor do módulo). Não cria foto nova: recorta,
regra de cor (duotone + brilho de borda) e escreve o título sobre a mesma foto.

Uso (na raiz do repositório):
    python tools/cdd/gerar-capas.py <foto-referencia.png> cdd/marca/capas
Requer Pillow. Fonte: Impact do Windows (ou passe --fonte caminho.ttf).
"""
import os, sys, textwrap
from PIL import Image, ImageChops, ImageDraw, ImageEnhance, ImageFont, ImageOps

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
LOGO = os.path.join(RAIZ, 'cdd', 'marca', 'logo-cdd-branca.png')

# (slug, kicker, titulo, cor, enquadramento do pôster, enquadramento do banner, força da cor, brilho, brilho de borda)
MODULOS = [
    ('comece-por-aqui',      'CDD',          'COMECE POR AQUI',                         '#e2272d', 'A', 'W1', .55, 1.00, .30),
    ('avaliacoes',           'CDD',          'AVALIAÇÕES E CONTROLE DE RESULTADOS',     '#0ea5e9', 'C', 'W2', .70, .95, .38),
    ('antes-de-treinar',     'CDD',          'ASSISTA ANTES DE TREINAR',                '#f59e0b', 'E', 'W3', .65, 1.00, .34),
    ('alongamentos',         'CDD',          'ALONGAMENTOS ESTÉTICOS E FUNCIONAIS',     '#14b8a6', 'F', 'W1', .70, .95, .36),
    ('protocolo-1',          'PROTOCOLO #1', 'FASE ADAPTATIVA',                         '#22c55e', 'A', 'W2', .72, 1.05, .34),
    ('protocolo-2',          'PROTOCOLO #2', 'ATIVAÇÃO METABÓLICA',                     '#f59e0b', 'C', 'W3', .68, 1.00, .36),
    ('protocolo-3',          'PROTOCOLO #3', 'ATIVAÇÃO “MOLÉCULA DA DEFINIÇÃO”',        '#a855f7', 'E', 'W1', .78, .92, .40),
    ('protocolo-4',          'PROTOCOLO #4', 'DEFINIÇÃO WELLNESS',                      '#ef4444', 'F', 'W2', .72, .95, .36),
    ('protocolo-5',          'PROTOCOLO #5', 'DEFINIÇÃO BIKINI',                        '#ec4899', 'A', 'W3', .74, .98, .38),
    ('protocolo-6',          'PROTOCOLO #6', 'FASE DE TRANSIÇÃO',                       '#06b6d4', 'C', 'W1', .72, .95, .38),
    ('protocolo-7',          'PROTOCOLO #7', 'ATIVAÇÃO METABÓLICA 2.0',                 '#f97316', 'E', 'W2', .74, .96, .38),
    ('protocolo-8',          'PROTOCOLO #8', 'ATIVAÇÃO “MOLÉCULA DA DEFINIÇÃO” 2.0',    '#8b5cf6', 'F', 'W3', .80, .90, .42),
    ('protocolo-9',          'PROTOCOLO #9', 'DEFINIÇÃO WELLNESS 2.0',                  '#e11d48', 'A', 'W1', .78, .90, .42),
    ('protocolo-10',         'PROTOCOLO #10','DEFINIÇÃO FIT MODEL',                     '#eab308', 'C', 'W2', .80, .88, .44),
    ('lives-exclusivas',     'BÔNUS',        'LIVES E AULAS EXCLUSIVAS',                '#6366f1', 'E', 'W3', .75, .95, .38),
    ('codigo-alimentacao',   'BÔNUS',        'CÓDIGO DA ALIMENTAÇÃO',                   '#22c55e', 'F', 'W1', .70, 1.00, .34),
    ('cintura-de-atleta',    'BÔNUS',        'CINTURA DE ATLETA|Método Sai Pochete',    '#06b6d4', 'A', 'W2', .72, .96, .36),
    ('mente-definida',       'BÔNUS',        'PROGRAMA MENTE DEFINIDA',                 '#a855f7', 'C', 'W3', .80, .90, .42),
    ('treinos-em-casa',      'BÔNUS',        'TREINOS EM CASA',                         '#f97316', 'E', 'W1', .70, 1.00, .34),
    ('marlene-mariano',      'BÔNUS',        'MÉTODO MARLENE MARIANO',                  '#ec4899', 'F', 'W2', .76, .96, .40),
    ('treinos-ao-vivo',      'BÔNUS',        'TREINOS AO VIVO',                         '#ef4444', 'A', 'W3', .72, .96, .38),
    ('pos-pascoa',           'DESAFIO',      'DEFINIÇÃO PÓS PÁSCOA',                    '#f59e0b', 'C', 'W1', .68, 1.00, .34),
    ('21-dias-verao',        'DESAFIO',      '21 DIAS PREPARATÓRIO PARA O VERÃO',       '#eab308', 'E', 'W2', .70, 1.00, .36),
    ('desafio-carnaval',     'DESAFIO',      'DESAFIO DE CARNAVAL',                     '#ec4899', 'F', 'W3', .78, .94, .42),
    ('ceia-de-natal',        'DESAFIO',      'CEIA DE NATAL 21 DIAS',                   '#22c55e', 'A', 'W1', .76, .92, .38),
    ('bbb-2024',             '2024',         'BBB: BORA BUSCAR O BALDE',                '#eab308', 'C', 'W2', .80, .90, .44),
]

# caixas de recorte na foto de 1080x1080 (x0, y0, x1, y1)
POSTER = {'A': (360, 0, 1080, 1080), 'C': (480, 240, 1040, 1080), 'E': (300, 0, 960, 990), 'F': (580, 330, 1060, 1050)}
WIDE = {'W1': (0, 36, 1080, 644), 'W2': (0, 170, 1080, 778), 'W3': (0, 0, 1080, 608)}


def rgb(hexa):
    h = hexa.lstrip('#')
    return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def misturar(a, b, t):
    return tuple(int(a[i] * (1 - t) + b[i] * t) for i in range(3))


def classe(img, cor, forca, brilho, brilho_borda):
    """Duotone da cor do módulo misturado à foto, brilho de borda na cor e vinheta."""
    c = rgb(cor)
    cinza = ImageOps.autocontrast(ImageOps.grayscale(img), cutoff=1)
    duo = ImageOps.colorize(cinza, black=tuple(int(x * .10) for x in c), mid=misturar(c, (0, 0, 0), .25),
                            white=misturar(c, (255, 255, 255), .82), midpoint=118)
    out = Image.blend(img.convert('RGB'), duo, forca)
    out = ImageEnhance.Brightness(out).enhance(brilho)
    out = ImageEnhance.Contrast(out).enhance(1.08)
    w, h = out.size
    # brilho de borda: luz da cor do módulo vinda do lado do atleta
    raio = Image.radial_gradient('L').resize((int(w * 1.5), int(h * 1.2)))
    centro = ImageOps.invert(raio).point(lambda v: int(v * brilho_borda))
    mascara = Image.new('L', (w, h), 0)
    mascara.paste(centro, (int(w * .30) - centro.width // 2, int(h * .30) - centro.height // 2))
    luz = Image.composite(Image.new('RGB', (w, h), c), Image.new('RGB', (w, h), (0, 0, 0)), mascara)
    out = ImageChops.screen(out, luz)
    # vinheta
    vig = Image.radial_gradient('L').resize((w, h)).point(lambda v: int(max(0, v - 120) * 1.45 * .75))
    out = Image.composite(Image.new('RGB', (w, h), (0, 0, 0)), out, vig)
    return out


def degrade_base(img, inicio=.42, forca=.92):
    """Escurece a parte de baixo para o título ficar legível."""
    w, h = img.size
    g = Image.linear_gradient('L').resize((w, h))
    corte = int(255 * inicio)
    m = g.point(lambda v: int(max(0, v - corte) * (255 / (255 - corte)) * forca))
    return Image.composite(Image.new('RGB', (w, h), (0, 0, 0)), img, m)


def fonte(caminho, tam):
    return ImageFont.truetype(caminho, tam)


def quebrar(draw, texto, f, largura):
    linhas, atual = [], ''
    for p in texto.split(' '):
        tent = (atual + ' ' + p).strip()
        if draw.textlength(tent, font=f) <= largura or not atual:
            atual = tent
        else:
            linhas.append(atual); atual = p
    if atual:
        linhas.append(atual)
    return linhas


def escrever_titulo(img, kicker, titulo, cor, caminho_fonte):
    d = ImageDraw.Draw(img)
    w, h = img.size
    margem = 44
    larg = w - margem * 2 - 18
    sub = None
    if '|' in titulo:
        titulo, sub = titulo.split('|', 1)
    # maior tamanho que cabe em no máximo 4 linhas
    for tam in range(104, 40, -4):
        f = fonte(caminho_fonte, tam)
        linhas = quebrar(d, titulo, f, larg)
        if len(linhas) <= 4 and max(d.textlength(l, font=f) for l in linhas) <= larg:
            break
    alt = int(tam * 1.04)
    f_k = fonte(caminho_fonte, 30)
    bloco = len(linhas) * alt + 54 + (44 if sub else 0)
    y = h - 52 - bloco
    c = rgb(cor)
    # barra de cor e kicker
    d.rectangle([margem, y + 6, margem + 8, y + bloco - (44 if sub else 0) - 8 + 44], fill=c) if False else None
    kx = margem + 22
    d.rounded_rectangle([margem, y, margem + d.textlength(kicker, font=f_k) + 36, y + 42], 8, fill=c)
    d.text((margem + 18, y + 3), kicker, font=f_k, fill=(255, 255, 255))
    y += 62
    for l in linhas:
        d.text((margem + 3, y + 3), l, font=fonte(caminho_fonte, tam), fill=(0, 0, 0))
        d.text((margem, y), l, font=fonte(caminho_fonte, tam), fill=(255, 255, 255))
        y += alt
    if sub:
        f_s = fonte(caminho_fonte, 36)
        d.text((margem, y + 6), sub, font=f_s, fill=misturar(c, (255, 255, 255), .35))
    return img


def logo_no_topo(img, largura=150):
    if not os.path.exists(LOGO):
        return img
    lg = Image.open(LOGO).convert('RGBA')
    # corta o espaço transparente em volta
    caixa = lg.getchannel('A').getbbox()
    if caixa:
        lg = lg.crop(caixa)
    r = largura / lg.width
    lg = lg.resize((largura, int(lg.height * r)), Image.LANCZOS)
    base = img.convert('RGBA')
    sombra = Image.new('RGBA', base.size, (0, 0, 0, 0))
    sombra.paste((0, 0, 0, 160), (34, 36), lg.getchannel('A'))
    base = Image.alpha_composite(base, sombra)
    base.alpha_composite(lg, (30, 30))
    return base.convert('RGB')


def limpar_logo(foto):
    """Apaga a logo do CDD que vem gravada no meio da foto de referência (a capa leva a própria logo, no topo).
    Preenche a região com o fundo escuro da esquerda (esticado e desfocado) e suaviza as bordas. A ponta da barra da logo
    passa um pouco por cima da roupa; essa ponta é coberta só abaixo da linha da roupa."""
    from PIL import ImageFilter
    x0, y0, x1, y1 = 296, 412, 742, 616
    w, h = x1 - x0, y1 - y0
    esq = foto.crop((x0 - 96, y0, x0 - 8, y1)).resize((w, h), Image.BILINEAR).filter(ImageFilter.GaussianBlur(10))
    # grão leve para não parecer mancha lisa
    grao = Image.effect_noise((w, h), 9).convert('RGB')
    esq = Image.blend(esq, ImageChops.add(esq, grao, 1.0, -128), .5)
    esq = ImageEnhance.Brightness(esq).enhance(.78)
    mascara = Image.new('L', (w, h), 0)
    d = ImageDraw.Draw(mascara)
    d.rectangle([16, 16, 708 - x0, h - 16], fill=255)          # área principal (até antes da roupa)
    d.rectangle([700 - x0, 470 - y0, 727 - x0, h - 16], fill=255)   # ponta da barra, ao lado da roupa
    d.rectangle([700 - x0, 504 - y0, w - 16, h - 16], fill=255)   # e abaixo da linha da roupa
    mascara = mascara.filter(ImageFilter.GaussianBlur(11)).point(lambda v: min(255, int(v * 2.2)))   # borda suave, miolo 100% opaco
    out = foto.copy()
    out.paste(esq, (x0, y0), mascara)
    return out


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    caminho_fonte = 'C:/Windows/Fonts/impact.ttf'
    for a in sys.argv[1:]:
        if a.startswith('--fonte='):
            caminho_fonte = a.split('=', 1)[1]
    if len(args) < 2:
        print(__doc__); sys.exit(1)
    ref, saida = args[0], args[1]
    os.makedirs(saida, exist_ok=True)
    foto = limpar_logo(Image.open(ref).convert('RGB'))
    foto.save(os.path.join(saida, '_base-sem-logo.png')) if '--salvar-base' in sys.argv else None
    for slug, kicker, titulo, cor, cp, cw, forca, brilho, borda in MODULOS:
        p = foto.crop(POSTER[cp]).resize((640, 960), Image.LANCZOS)
        p = classe(p, cor, forca, brilho, borda)
        p = degrade_base(p)
        p = logo_no_topo(p, 150)
        p = escrever_titulo(p, kicker, titulo, cor, caminho_fonte)
        p.save(os.path.join(saida, slug + '.jpg'), 'JPEG', quality=82, optimize=True, progressive=True)
        wd = foto.crop(WIDE[cw]).resize((1280, 720), Image.LANCZOS)
        wd = classe(wd, cor, forca, brilho, borda)
        wd = degrade_base(wd, .40, .85)
        wd.save(os.path.join(saida, slug + '-wide.jpg'), 'JPEG', quality=80, optimize=True, progressive=True)
        print(slug, 'ok')


if __name__ == '__main__':
    main()
