# Materiais de ficha da loja

Tudo aqui (e os ícones e telas de abertura dos projetos nativos) é gerado pelo mesmo script, a partir da marca oficial
(`app/painel/logo-icon-light.png` e `app/painel/logo-intus-dark.png`):

```bash
python3 tools/marca/gerar-icones-lojas.py
node testes/icones_lojas.js
```

O script precisa de Pillow e numpy e da fonte Inter (ou Segoe UI no Windows). O teste confere tamanhos, ausência de alfa onde a Apple
proíbe, a zona segura do ícone adaptativo do Android e que nenhum desenho de exemplo do Capacitor voltou. Depois de mudar a marca:
rodar os dois comandos e `cd mobile && npm run sync` antes de gerar o build.

## O que está nesta pasta

- `app-store-icon-1024.png`: ícone para o App Store Connect (ficha do app), 1024x1024, PNG **sem alfa**, quadrado cheio (a Apple aplica
  os cantos). É o mesmo arquivo do ícone do Xcode
  (`mobile/ios/App/App/Assets.xcassets/AppIcon.appiconset/AppIcon-512@2x.png`); o upload na ficha da loja é separado do build.
- `google-play-icon-512.png`: ícone de alta resolução do Play Console (Presença na loja, Recursos gráficos principais), 512x512, quadrado
  cheio e opaco (a Play aplica a máscara e a sombra).
- `google-play-feature-graphic-1024x500.png`: imagem de destaque da Google Play, 1024x500 sem alfa. Marca, "Consultoria online de
  treino e nutrição" e "Do iniciante ao atleta.", os mesmos dizeres do site, sem promessa de resultado, prazo ou número. Conferir a
  arte no Play Console antes de enviar (a Play corta as bordas em alguns lugares; o texto fica só na área central).

## O que o mesmo script grava nos projetos nativos

- iOS: `AppIcon-512@2x.png` (1024x1024, sem alfa) e as 3 escalas da `Splash.imageset` (2732x2732; o storyboard usa "aspect fill", então
  no iPhone só o centro aparece e a marca fica nele).
- Android: ícone adaptativo em `mipmap-*` com `ic_launcher_fundo.png` (fundo da marca), `ic_launcher_foreground.png` (marca dentro do
  círculo seguro de 66 dp) e `ic_launcher_monochrome.png` (Android 13+, ícone temático), mais os ícones antigos `ic_launcher.png` e
  `ic_launcher_round.png` (Android 7.x). A abertura (`drawable*/splash.png`) é 9:18 porque o Android estica a imagem até a tela toda.

## Ainda falta para a ficha completa

- Capturas de tela reais nos tamanhos exigidos por iOS e Android, feitas no app instalado em aparelho físico com uma conta de
  demonstração (não gerar com dados fictícios ou pessoais).
- Texto da ficha (nome, subtítulo, descrição curta e completa): ver o checklist de lançamento.
- Conferir os ícones e a abertura em aparelho ou emulador (o ambiente que gerou estes arquivos não compila o app nativo).
