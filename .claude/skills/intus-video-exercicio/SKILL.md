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

## Galeria (onde o Luiz assiste e baixa)

Tudo que for produzido entra na página **https://claude.ai/artifact/NHTznbab21EjEvrj4sxr5P**
(Vídeos de exercícios: filtros por região, posição e vista; MP4 e capa para baixar). Para
acrescentar um vídeo: gere o MP4 e a capa numa pasta `videos/` do scratchpad, acrescente o item no
array `VIDEOS` do `index.html` da galeria e republique com `url` dessa página (leia a página antes
com `action: "read"`, e mande nos `files` só os vídeos novos: os já publicados ficam).

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
| `vista` | `lado` (padrão), `frente` ou `costas`. Cada erro pode ter a própria `vista` (ex.: valgo do joelho visto de frente num vídeo de lado) |
| `equipamento` | `barra_costas` (barra nas costas), `halteres`, `rodinha` (mãos numa rodinha de abdominal no chão) ou ausente (braços pelos ângulos da pose) |
| `ancora` | `{junta, x, y}`: a junta que fica parada (padrão: tornozelo no chão). Deitado: `quadril`; de joelhos: `joelho`; prancha: `ombro`; ponta do pé: `ponta`. Isso permite exercícios deitados, sentados e de joelhos |
| `colchonete` | `[x1, x2]` em unidades do boneco: desenha o colchonete no chão |
| `palcoX`, `escala` | Posição horizontal (0 a 1) e tamanho do boneco no palco, só na vista de lado (exercício deitado ocupa mais largura) |
| `movimento.poses` | Poses-chave por nome, com os ângulos de cada segmento (ver abaixo) |
| `movimento.sequencia` | `[pose, segundos, texto da fase]` em ordem; repete em loop. A barra de progresso embaixo mostra a fase |
| `dicas` | 3 a 5 frases curtas (passo a passo). Cada uma fica 3,2 s em destaque, então o vídeo cresce com o número de dicas |
| `erros` (ou `erro`) | Lista de erros (cada um aparece como "ERRO COMUM i DE n"). Pose do erro (`pose` + `ajuste` com os ângulos que mudam), ponto a circular (`marcar`: `calc`, `joelho`, `quadril`, `ombro`, `cabeca`...), textos `errado`/`porque`/`certo`/`comoCorrigir` |
| | Em vez de `pose`+`ajuste`, o erro pode ter `errada` e `certa` (poses completas). `marcar` aceita lista (`["joelho","quadril"]`), `joelhos`/`ombros` na vista de frente |
| `fechamento` | Frase final (padrão: "Dúvida na execução? Fale com o seu professor pelo app.") |

**Ângulos da pose** (boneco de lado, virado para a direita): graus a partir da **vertical para
cima**; positivo = inclinado para a frente, negativo = para trás.
`canela` (tornozelo→joelho), `coxa` (joelho→quadril), `tronco` (quadril→ombro), `cabecaInc`
(soma ao tronco), `calcanhar` (graus de calcanhar levantado), `braco`/`antebraco` (ombro→cotovelo,
cotovelo→mão; 180 = pendurado), quando não há `equipamento`.
`pe` (direção do pé, em vez de pé apoiado no chão; ex.: 80 deitado com o pé para cima, -90 de joelhos com o peito do pé no chão).
Vista de frente/costas: `prof` (0 em pé a 1 agachado), `joelhoAbre` (negativo = joelhos para dentro), `pesAbre`,
`abd` (abertura dos braços em graus; 90 = altura do ombro), `cotovelo`, `encolher` (ombro subindo).
Referência do agachamento: em pé `canela 0, coxa -2, tronco 6`; embaixo `canela 34, coxa -98,
tronco 38` (coxa -90 = paralela ao chão, quadril atrás do joelho).

**Sempre confira quadros antes de gerar o vídeo inteiro.** Chame `desenhar(t)` em alguns instantes
e tire print (Playwright). Olhe a pose de baixo, a cena do erro e se algum texto passa por cima do
boneco. Pose errada ensina errado: o boneco precisa estar biomecanicamente plausível (ex.: no
agachamento, ombro/barra por cima do meio do pé).

## Limites atuais do modelo (estenda o modelo.html quando precisar)

- Feitos e conferidos: em pé (agachamento, elevação lateral), deitado (canivete), de joelhos
  (rodinha) e apoio no chão (prancha). Sentado funciona pela `ancora` no quadril, mas não tem banco
  nem aparelho desenhados: leg press, cadeira extensora, puxada e supino precisam do desenho do
  aparelho no `desenharLado()` antes.
- Vista de costas está implementada (linha da coluna, mesma pose da vista de frente), ainda sem
  exemplo publicado.
- A lista de músculos encolhe a letra para caber; prefira nomes curtos (até ~3 itens).
- Na vista de frente o boneco é menor (escala 320) para os braços acima da cabeça caberem.

## Conteúdo: quem decide

- Texto em português, vocabulário da Intus (aluno, professor; nunca "cliente", "aula").
- **Dicas e erro comum são prescrição técnica**: rascunhe a partir do conhecimento geral, mas
  apresente ao Luiz para validar antes de considerar o vídeo pronto. Nunca prometa resultado ou
  trate como orientação médica.
- Entregue: o MP4 + a capa `.jpg` + o roteiro usado, e diga o que ele deve revisar (dicas, erro,
  pose).
