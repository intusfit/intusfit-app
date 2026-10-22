---
name: intus-video-exercicio
description: Produz vídeos curtos (MP4 16:9, ~25 s, sem áudio) que explicam a execução de um exercício do catálogo da Intus, com um boneco animado fazendo o movimento, o passo a passo, o erro mais comum e a logo. Use quando o Luiz pedir vídeo de exemplo/demonstração/explicação de exercício, vídeo para exercício "sem vídeo" no sistema, ou quiser produzir vídeos de exercícios em lote.
---

# Vídeo explicativo de exercício (Intus)

Gera o MP4 **sem gravar tela**: `modelo.html` desenha cada quadro como função do tempo e
`renderizar.js` exporta quadro a quadro para o ffmpeg. Mesmo roteiro → mesmo vídeo, sempre.

```
.claude/skills/intus-video-exercicio/
├─ SKILL.md            este arquivo
├─ modelo.html         a animação (abre no navegador como prévia, em loop)
├─ renderizar.js       gera <saida>.mp4 + <saida>.jpg (capa para o YouTube)
└─ roteiros/*.json     um arquivo por exercício (o único que muda de vídeo para vídeo)
```

A pasta `.claude/` tem `.htaccess` com `Require all denied`: o repositório é publicado inteiro no
site, e isto aqui não é para ficar acessível pela web.

## Para onde o vídeo vai (e o formato que isso impõe)

- O app do aluno abre o vídeo do exercício a partir do campo **`videoyoutube`** do catálogo
  (`intus_exercicio`), num quadro **16:9** (`#video-modal`, `aluno.html`). Por isso o vídeo é
  **1920×1080 deitado**, não vertical.
- O **Luiz** sobe o MP4 no YouTube como **não listado** e cola o link em Painel → Exercícios →
  editar → "Vídeo YouTube". Claude nunca faz login no YouTube/Google dele (regra do CLAUDE.md).
- Tem que ser **vídeo comum, não Short**: `youtubeToEmbed()` no `aluno.html` só entende
  `watch?v=`, `youtu.be/` e `embed/`. Link `youtube.com/shorts/...` não toca no app.
- **MP4 nunca entra no repositório**: o `git push` na `main` publica tudo no site. Gere no
  scratchpad e entregue o arquivo ao Luiz (SendUserFile).

## Qual exercício está sem vídeo

O catálogo só existe no banco de produção (não há cópia no código). Peça ao Luiz a lista:
**Painel → Exercícios → filtro "Sem vídeo"** (print ou nomes). Não invente a lista.

## Preparar o ambiente (sessão na nuvem, uma vez por sessão)

```bash
apt-get install -y fonts-inter                      # a fonte da marca (o navegador daqui não baixa do Google Fonts)
pip install imageio-ffmpeg                          # traz um ffmpeg com libx264
python3 -c "import imageio_ffmpeg as f; print(f.get_ffmpeg_exe())"   # → caminho para FFMPEG
ls /opt/pw-browsers                                 # Chromium já instalado: veja a revisão (ex.: chromium-1194)
mkdir -p <scratchpad>/pw && cd <scratchpad>/pw && npm init -y && npm i playwright@<versão da mesma revisão>
#   1194 → playwright@1.56.1. Confira em node_modules/playwright-core/browsers.json. Não rode "playwright install".
```

Gerar:

```bash
cd .claude/skills/intus-video-exercicio
NODE_PATH=<scratchpad>/pw/node_modules FFMPEG=<caminho do ffmpeg> \
  CHROME=/opt/pw-browsers/chromium-<rev>/chrome-linux/chrome \
  node renderizar.js roteiros/agachamento-livre.json <scratchpad>/agachamento-livre.mp4
```

~30 s de processamento para ~25 s de vídeo. Avisos de "logo não carregou" ou "fonte Inter não
instalada" significam vídeo fora da identidade: corrija antes de entregar.

## Escrever um roteiro

Copie `roteiros/agachamento-livre.json` e troque:

| Campo | O que é |
|---|---|
| `nome`, `musculos` | Título e lista de músculos (aparecem na tela) |
| `musculosSegmentos` | O que acende em verde no boneco: `coxa`, `canela`, `tronco`, `gluteo` |
| `equipamento` | `barra_costas` (mãos presas na barra nas costas) ou ausente (braços pelos ângulos da pose) |
| `movimento.poses` | Poses-chave por nome, com os ângulos de cada segmento (ver abaixo) |
| `movimento.sequencia` | `[pose, segundos, texto da fase]` em ordem; repete em loop. A barra de progresso embaixo mostra a fase |
| `dicas` | 3 a 5 frases curtas (passo a passo). Cada uma fica 3,2 s em destaque, então o vídeo cresce com o número de dicas |
| `erro` | Pose do erro (`pose` + `ajuste` com os ângulos que mudam), ponto a circular (`marcar`: `calc`, `joelho`, `quadril`, `ombro`, `cabeca`...), textos `errado`/`porque`/`certo`/`comoCorrigir` |
| `fechamento` | Frase final (padrão: "Dúvida na execução? Fale com o seu professor pelo app.") |

**Ângulos da pose** (boneco de lado, virado para a direita): graus a partir da **vertical para
cima**; positivo = inclinado para a frente, negativo = para trás.
`canela` (tornozelo→joelho), `coxa` (joelho→quadril), `tronco` (quadril→ombro), `cabecaInc`
(soma ao tronco), `calcanhar` (graus de calcanhar levantado), `braco`/`antebraco` (ombro→cotovelo,
cotovelo→mão; 180 = pendurado), quando não há `equipamento`.
Referência do agachamento: em pé `canela 0, coxa -2, tronco 6`; embaixo `canela 34, coxa -98,
tronco 38` (coxa -90 = paralela ao chão, quadril atrás do joelho).

**Sempre confira quadros antes de gerar o vídeo inteiro.** Chame `desenhar(t)` em alguns instantes
e tire print (Playwright). Olhe a pose de baixo, a cena do erro e se algum texto passa por cima do
boneco. Pose errada ensina errado: o boneco precisa estar biomecanicamente plausível (ex.: no
agachamento, ombro/barra por cima do meio do pé).

## Limites atuais do modelo (estenda o modelo.html quando precisar)

- Só exercícios **em pé com os pés fixos no chão** (o boneco é montado a partir do tornozelo):
  agachamentos, levantamento terra, stiff, remadas curvadas, desenvolvimento, rosca, elevações.
- Deitado/sentado (supino, leg press, cadeira extensora), barra fixa e exercícios em máquina
  precisam de outra raiz do esqueleto (quadril/costas apoiados) e de desenho do aparelho. Implemente
  no `montar()`/`desenharBoneco()` e confira com quadros antes de gerar.
- Vista só de lado: erros vistos de frente (joelho para dentro, valgo) não aparecem bem. Prefira
  erros visíveis de perfil (calcanhar, coluna, amplitude, cotovelo).

## Conteúdo: quem decide

- Texto em português, vocabulário da Intus (aluno, professor; nunca "cliente", "aula").
- **Dicas e erro comum são prescrição técnica**: rascunhe a partir do conhecimento geral, mas
  apresente ao Luiz para validar antes de considerar o vídeo pronto. Nunca prometa resultado ou
  trate como orientação médica.
- Entregue: o MP4 + a capa `.jpg` + o roteiro usado, e diga o que ele deve revisar (dicas, erro,
  pose).
