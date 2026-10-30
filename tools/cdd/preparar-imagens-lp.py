"""Prepara as imagens da landing /lancamento em cdd/marca/lp/ (otimizadas para web).
Fontes: ilustracao da Molecula (Drive do Luiz, baixada para cdd-descoberta/lp-img), antes e depois e fotos do Luiz (site/img).
Uso: python tools/cdd/preparar-imagens-lp.py
"""
import os, io
from PIL import Image

RAIZ = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..'))
SAIDA = os.path.join(RAIZ, 'cdd', 'marca', 'lp')
os.makedirs(SAIDA, exist_ok=True)

def salvar(im, nome, largura, q=80, webp=False):
    im = im.convert('RGB')
    if im.width > largura:
        im = im.resize((largura, round(im.height * largura / im.width)), Image.LANCZOS)
    ext = 'webp' if webp else 'jpg'
    caminho = os.path.join(SAIDA, f'{nome}.{ext}')
    im.save(caminho, 'WEBP' if webp else 'JPEG', quality=q, **({'method': 6} if webp else {'optimize': True, 'progressive': True}))
    print(f'{nome}.{ext}', im.size, os.path.getsize(caminho) // 1024, 'KB')

# ilustracao da Molecula
base = os.path.abspath(os.path.join(RAIZ, '..', 'cdd-descoberta', 'lp-img'))
salvar(Image.open(os.path.join(base, 'molecula-ilustracao-b.png')), 'molecula', 900, 82, webp=True)
salvar(Image.open(os.path.join(base, 'molecula-ilustracao-a.png')), 'molecula-suave', 700, 80, webp=True)

# antes e depois de alunas: so imagens sem numero nem prazo escrito (regra da oferta: nada de resultado numerico ou de prazo)
for n, fonte in enumerate(['img_0975', 'img_0978', 'img_0979', 'img_0981', 'img_0982', 'img_0992', 'img_0996', 'img_0973'], 1):
    salvar(Image.open(os.path.join(RAIZ, 'img', fonte + '.png')), f'resultado-{n}', 720, 80)

# Luiz
salvar(Image.open(os.path.join(RAIZ, 'img', 'prof-luiz.jpg')), 'luiz', 900, 82)
salvar(Image.open(os.path.join(RAIZ, 'img', 'luiz.jpg')), 'luiz-2', 900, 82)
