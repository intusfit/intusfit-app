#!/usr/bin/env python
"""
Gera as capas dos Extras (módulos e aulas) com a logo da Intus e fotos da própria equipe.

Para cada módulo cria em app/painel/extras/capas/:
  <slug>.jpg              capa do módulo 4:3 (960x720), com o título (grade de módulos do app e do painel)
  <slug>-aula-<n>.jpg     capa de cada aula 16:9 (640x360), com "AULA n" (lista de conteúdos)

Usa só imagens da Intus (fotos de equipe do site, a foto de treino em casa do Drive do Luiz já preparada por preparar-fotos.py e a logo). Não baixa nada da internet.
Uso (na raiz do repositório):
    python tools/extras/gerar-capas.py <pasta-com-as-fotos> app/painel/extras/capas
A pasta de fotos é a "img/" do site (fica fora do git); os nomes dos arquivos estão em MODULOS. Requer Pillow.
Fonte: Impact do Windows (ou passe --fonte caminho.ttf).
"""
import os, sys, json
from PIL import Image, ImageDraw, ImageFont, ImageOps, ImageChops, ImageEnhance, ImageFilter

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
LOGO = os.path.join(RAIZ, 'app', 'painel', 'logo-intus-dark.png')
LIMA = (127, 255, 0)
FUNDO = (11, 11, 11)

# slug, título (| quebra a linha), cor do módulo, foto, centralização da foto (x, y), layout
#   painel: foto no lado direito, com degradê para o fundo escuro (retratos)
#   cheia:  foto ocupa a capa inteira, escurecida à esquerda onde ficam o logo e o título (cenas largas)
# As fotos são procuradas primeiro em tools/extras/fotos/ (preparadas por preparar-fotos.py) e depois na pasta de fotos informada.
MODULOS = [
    ('cintura-de-atleta',        'CINTURA DE ATLETA',           '#f59e0b', 'prof-marlene.jpg',  (0.50, 0.30), 'painel'),
    ('treinos-em-casa',          'TREINOS|EM CASA',             '#22c55e', 'treinos-em-casa.jpg', (0.62, 0.30), 'cheia'),
    ('assista-antes-de-treinar', 'ASSISTA ANTES|DE TREINAR',    '#0ea5e9', 'luiz-academia.jpg', (0.50, 0.14), 'painel'),
    ('lives-e-aulas-exclusivas', 'LIVES E AULAS|EXCLUSIVAS',    '#ef4444', 'luiz.jpg',          (0.50, 0.22), 'painel'),
]


def caminho_foto(pasta_fotos, nome):
    c = os.path.join(RAIZ, 'tools', 'extras', 'fotos', nome)
    return c if os.path.exists(c) else os.path.join(pasta_fotos, nome)


def hexrgb(h):
    h = h.lstrip('#'); return tuple(int(h[i:i + 2], 16) for i in (0, 2, 4))


def fonte(tam, caminho=None):
    for c in [caminho, 'C:/Windows/Fonts/impact.ttf', '/usr/share/fonts/truetype/msttcorefonts/Impact.ttf', 'C:/Windows/Fonts/arialbd.ttf']:
        if c and os.path.exists(c):
            return ImageFont.truetype(c, tam)
    return ImageFont.load_default()


def logo_branca(largura):
    """Logo da Intus para fundo escuro (PNG com transparência): devolve a imagem e a máscara de transparência."""
    im = Image.open(LOGO).convert('RGBA')
    h = int(im.height * largura / im.width)
    im = im.resize((largura, h), Image.LANCZOS)
    return im.convert('RGB'), im.getchannel('A')


def foto_painel(caminho, tam, centro, cor, mais_escura=1.0):
    im = Image.open(caminho).convert('RGB')
    im = ImageOps.fit(im, tam, Image.LANCZOS, centering=centro)
    im = ImageEnhance.Contrast(im).enhance(1.08)
    im = ImageEnhance.Brightness(im).enhance(mais_escura)
    # leve tom da cor do módulo nas sombras (mantém a pele natural)
    tom = Image.new('RGB', im.size, cor)
    return Image.blend(im, ImageChops.multiply(im, tom), 0.18)


def degrade_horizontal(tam, de, ate):
    """Máscara L: 0 até `de`, sobe até 255 em `ate` (em pixels)."""
    w, h = tam
    linha = Image.new('L', (w, 1))
    px = linha.load()
    for x in range(w):
        px[x, 0] = 0 if x <= de else (255 if x >= ate else int(255 * (x - de) / (ate - de)))
    return linha.resize((w, h))


def compor_foto(base, caminho, caixa, centro, cor, escurecer=1.0, fade=0.45, cheia=False):
    x0, y0, x1, y1 = caixa
    if cheia:
        W, H = base.size
        foto = foto_painel(caminho, (W, H), centro, cor, escurecer)
        base.paste(foto, (0, 0))
        # escurece da esquerda para o meio: o logo e o título ficam sobre fundo escuro e o rosto fica livre
        esc = Image.new('L', (W, H), 0)
        px = esc.load()
        for x in range(W):
            t = 1.0 if x < W * 0.12 else max(0.0, 1.0 - (x - W * 0.12) / (W * 0.50))
            for y in range(H):
                px[x, y] = int(235 * t * t)
        base.paste(Image.new('RGB', (W, H), FUNDO), (0, 0), esc)
        return
    foto = foto_painel(caminho, (x1 - x0, y1 - y0), centro, cor, escurecer)
    m = degrade_horizontal(foto.size, 0, int(foto.size[0] * fade))
    base.paste(foto, (x0, y0), m)


def quebrar(titulo, fonte_, largura, draw):
    linhas = []
    for bloco in titulo.split('|'):
        atual = ''
        for p in bloco.split():
            t = (atual + ' ' + p).strip()
            if draw.textlength(t, font=fonte_) <= largura: atual = t
            else:
                if atual: linhas.append(atual)
                atual = p
        if atual: linhas.append(atual)
    return linhas


def capa_modulo(slug, titulo, cor_hex, foto, centro, layout, qtd, pasta_fotos, saida, fonte_arq):
    W, H = 960, 720
    cor = hexrgb(cor_hex)
    base = Image.new('RGB', (W, H), FUNDO)
    compor_foto(base, caminho_foto(pasta_fotos, foto), (330, 0, W, H), centro, cor, 0.95, 0.5, layout == 'cheia')
    # vinheta inferior
    v = Image.new('L', (W, H), 0); d = ImageDraw.Draw(v)
    for y in range(H // 2, H):
        d.line([(0, y), (W, y)], fill=int(150 * (y - H // 2) / (H // 2)))
    base.paste(Image.new('RGB', (W, H), (0, 0, 0)), (0, 0), v)
    dr = ImageDraw.Draw(base)
    # barra de cor do módulo na lateral esquerda
    dr.rectangle([0, 0, 12, H], fill=cor)
    # logo
    img, m = logo_branca(250); base.paste(img, (52, 46), m)
    # selo EXTRAS
    f_sel = fonte(26, fonte_arq); t = 'EXTRAS'; tw = dr.textlength(t, font=f_sel)
    dr.rectangle([54, 150, 54 + tw + 36, 150 + 44], fill=LIMA)
    dr.text((54 + 18, 150 + 5), t, font=f_sel, fill=(10, 20, 0))
    # título
    f_tit = fonte(88, fonte_arq)
    linhas = quebrar(titulo, f_tit, 520, dr)
    tam = 88
    while len(linhas) > 4 and tam > 60:
        tam -= 6; f_tit = fonte(tam, fonte_arq); linhas = quebrar(titulo, f_tit, 520, dr)
    alt = int(tam * 1.04)
    y = 250
    for l in linhas:
        dr.text((52 + 3, y + 3), l, font=f_tit, fill=(0, 0, 0))
        dr.text((52, y), l, font=f_tit, fill=(255, 255, 255))
        y += alt
    dr.rectangle([52, y + 14, 52 + 120, y + 22], fill=LIMA)
    f_n = fonte(30, fonte_arq)
    dr.text((52, y + 40), ('%d AULA%s' % (qtd, '' if qtd == 1 else 'S')), font=f_n, fill=(220, 220, 220))
    base.save(os.path.join(saida, slug + '.jpg'), quality=86, optimize=True, progressive=True)


def capa_aula(slug, n, cor_hex, foto, centro, layout, pasta_fotos, saida, fonte_arq):
    W, H = 640, 360
    cor = hexrgb(cor_hex)
    base = Image.new('RGB', (W, H), FUNDO)
    desloc = [(0.50, 0), (0.42, 0.04), (0.58, -0.03), (0.46, 0.07), (0.54, -0.05)][n % 5]
    c = (min(1.0, max(0.0, centro[0] + desloc[0] - 0.5)), min(1.0, max(0.0, centro[1] + desloc[1])))
    compor_foto(base, caminho_foto(pasta_fotos, foto), (250, 0, W, H), c, cor, 0.92, 0.5, layout == 'cheia')
    dr = ImageDraw.Draw(base)
    dr.rectangle([0, 0, 8, H], fill=cor)
    img, m = logo_branca(128); base.paste(img, (28, 22), m)
    f_a = fonte(24, fonte_arq); dr.text((30, 198), 'AULA', font=f_a, fill=(210, 210, 210))
    f_n = fonte(112, fonte_arq); txt = '%02d' % n
    dr.text((30 + 3, 214 + 3), txt, font=f_n, fill=(0, 0, 0)); dr.text((30, 214), txt, font=f_n, fill=(255, 255, 255))
    dr.rectangle([30, 338, 30 + 70, 344], fill=LIMA)
    base.save(os.path.join(saida, '%s-aula-%d.jpg' % (slug, n)), quality=84, optimize=True, progressive=True)


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    fonte_arq = None
    for a in sys.argv[1:]:
        if a.startswith('--fonte='): fonte_arq = a.split('=', 1)[1]
    if len(args) != 2:
        print(__doc__); sys.exit(1)
    pasta_fotos, saida = args
    os.makedirs(saida, exist_ok=True)
    quantidades = json.load(open(os.path.join(RAIZ, 'tools', 'extras', 'quantidades.json'), encoding='utf-8'))
    for slug, titulo, cor, foto, centro, layout in MODULOS:
        qtd = int(quantidades[slug])
        capa_modulo(slug, titulo, cor, foto, centro, layout, qtd, pasta_fotos, saida, fonte_arq)
        for n in range(1, qtd + 1):
            capa_aula(slug, n, cor, foto, centro, layout, pasta_fotos, saida, fonte_arq)
        print(slug, '-> capa + %d aulas' % qtd)


if __name__ == '__main__':
    main()
