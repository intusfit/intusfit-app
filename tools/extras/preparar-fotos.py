#!/usr/bin/env python
"""
Prepara as fotos que o gerador de capas dos Extras usa (tools/extras/fotos/):

  treinos-em-casa.jpg   foto do Drive do Luiz (quadro de vídeo de treino em casa), trabalhada:
                        - apaga o logo antigo (MAXIMIZE) da camiseta preta, deixando a camiseta totalmente preta;
                        - recorta fora a marca d'água do canto e o contador do vídeo, em proporção 4:3;
                        - reduz o ruído de compressão, amplia, aplica nitidez e ajuste leve de contraste e cor.
  ricardo.jpg           foto do Prof. Ricardo (Drive do Luiz, IMG_0608.JPG, 3072x3072) no padrão das outras: reduzida para 1600 px, mais escura e
                        contrastada (a original tem parede clara e piso verde vivo), para combinar com o fundo escuro das capas.
  luiz-academia.jpg     a foto do Luiz na academia SEM distorção (a prof-luiz.jpg do site está achatada na horizontal; esta vem do
                        original em retrato, cortado acima do texto do cartão).

Uso (na raiz do repositório):
    python tools/extras/preparar-fotos.py <foto-treino-em-casa.jpg> <pasta img do site> [<foto-do-ricardo.jpg>]
Requer Pillow (sem numpy).
"""
import os, random, sys
from PIL import Image, ImageEnhance, ImageFilter, ImageOps, ImageDraw

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SAIDA = os.path.join(RAIZ, 'tools', 'extras', 'fotos')


def apagar_logo_camiseta(im, caixa=(458, 207, 594, 264)):
    """Cobre a estampa com o preto da própria camiseta: cor de cada coluna tirada logo acima da estampa, levemente mais escura para baixo,
    com o mesmo grão da foto e borda suavizada. A pele (braços e o punho, que começa em y~258) fica de fora da área coberta."""
    x0, y0, x1, y1 = caixa
    px = im.load()
    topo = []
    for x in range(x0, x1):
        amostra = [px[x, y] for y in range(y0 - 7, y0 - 1)]
        topo.append(tuple(sum(c[i] for c in amostra) / len(amostra) for i in range(3)))
    suave = []
    for i in range(len(topo)):
        a, b = max(0, i - 8), min(len(topo), i + 9)
        suave.append(tuple(sum(t[k] for t in topo[a:b]) / (b - a) for k in range(3)))
    larg, alt = x1 - x0, y1 - y0
    patch = Image.new('RGB', (larg, alt))
    pp = patch.load()
    rnd = random.Random(7)
    for j in range(alt):
        f = 1.0 - 0.14 * (j / max(1, alt - 1))
        for i in range(larg):
            r = rnd.randint(-3, 3)
            c = suave[i]
            pp[i, j] = tuple(max(0, min(255, int(c[k] * f + r))) for k in range(3))
    patch = patch.filter(ImageFilter.GaussianBlur(0.7))
    mascara = Image.new('L', (larg, alt), 0)
    d = ImageDraw.Draw(mascara)
    # só a estampa: as letras (até y=243) e o subtítulo logo abaixo (até y=257, entre o braço esquerdo e o punho). Nada encosta na pele.
    d.rectangle([463 - x0, 211 - y0, 584 - x0, 243 - y0], fill=255)
    d.rectangle([470 - x0, 243 - y0, 574 - x0, 250 - y0], fill=255)
    d.rectangle([476 - x0, 250 - y0, 560 - x0, 257 - y0], fill=255)
    d.rectangle([482 - x0, 257 - y0, 512 - x0, 260 - y0], fill=255)
    mascara = mascara.filter(ImageFilter.GaussianBlur(1.5))
    im.paste(patch, (x0, y0), mascara)
    return im


def treinos_em_casa(origem):
    im = Image.open(origem).convert('RGB')
    im = apagar_logo_camiseta(im)
    # recorte 4:3 sem a marca d'água (canto inferior esquerdo) nem o contador/barra do vídeo (a partir de y=575)
    im = im.crop((59, 0, 824, 574))
    im = im.filter(ImageFilter.GaussianBlur(0.55))
    im = im.resize((1530, 1148), Image.LANCZOS)
    im = im.filter(ImageFilter.UnsharpMask(radius=2.0, percent=95, threshold=3))
    im = ImageEnhance.Contrast(im).enhance(1.10)
    im = ImageEnhance.Color(im).enhance(1.12)
    return im


def ricardo(origem):
    im = ImageOps.exif_transpose(Image.open(origem)).convert('RGB')
    im = im.resize((1600, 1600), Image.LANCZOS)
    im = ImageEnhance.Brightness(im).enhance(0.80)
    im = ImageEnhance.Contrast(im).enhance(1.16)
    im = ImageEnhance.Color(im).enhance(0.86)   # o verde do piso e o vermelho do fundo chamam mais atenção que o rosto
    # vinheta: bordas e canto de baixo mais escuros, rosto em destaque
    W, H = im.size
    v = Image.new('L', (W, H), 0)
    px = v.load()
    for y in range(0, H, 1):
        for x in range(0, W, 1):
            dx, dy = (x - W * 0.50) / (W * 0.62), (y - H * 0.34) / (H * 0.78)
            d = (dx * dx + dy * dy) ** 0.5
            px[x, y] = int(max(0.0, min(1.0, (d - 0.55) / 0.70)) * 150)
    im.paste(Image.new('RGB', (W, H), (8, 8, 8)), (0, 0), v)
    return im


def luiz_academia(pasta_img):
    im = Image.open(os.path.join(pasta_img, '259fdefd-32ea-4dac-952f-213279238ece.jpg')).convert('RGB')   # 720x1280, retrato
    return im.crop((0, 0, 720, 940))


def main():
    if len(sys.argv) not in (3, 4):
        print(__doc__); sys.exit(1)
    origem, pasta_img = sys.argv[1], sys.argv[2]
    origem_ricardo = sys.argv[3] if len(sys.argv) == 4 else None
    os.makedirs(SAIDA, exist_ok=True)
    treinos_em_casa(origem).save(os.path.join(SAIDA, 'treinos-em-casa.jpg'), quality=92, optimize=True)
    if origem_ricardo:
        ricardo(origem_ricardo).save(os.path.join(SAIDA, 'ricardo.jpg'), quality=90, optimize=True)
    luiz_academia(pasta_img).save(os.path.join(SAIDA, 'luiz-academia.jpg'), quality=92, optimize=True)
    print('fotos preparadas em', SAIDA)


if __name__ == '__main__':
    main()
