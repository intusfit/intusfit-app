# Materiais de ficha da loja

Gerados por `python tools/mobile/gerar-assets-nativos.py` (Pillow e numpy), a partir do símbolo e do logotipo em `app/painel/`. O mesmo script refaz os ícones e a splash dos projetos nativos. Para mudar a arte, edite o script e rode de novo: os arquivos abaixo não são editados à mão.

| Arquivo | Tamanho | Onde vai |
|---|---|---|
| `app-store-icon-1024.png` | 1024×1024, RGB, sem alfa | App Store Connect (ficha do app). É o mesmo ícone do Xcode (`AppIcon-512@2x.png`); a Apple aplica a máscara arredondada |
| `google-play-icon-512.png` | 512×512, RGB, sem alfa | Play Console > Presença na loja > Ícone do app. Borda a borda, sem cantos arredondados |
| `google-play-feature-graphic-1024x500.png` | 1024×500, RGB, sem alfa | Play Console > Imagem de destaque |

Texto da ficha (nome, descrições, palavras-chave, notas de revisão, respostas de privacidade): `../STORE-LISTING-PT-BR.md`.

Ainda falta para a ficha completa (não gerar com dados fictícios ou pessoais):

- Capturas de tela reais nos tamanhos exigidos por iOS e Android, feitas no app instalado em aparelho físico com uma conta de demonstração.
