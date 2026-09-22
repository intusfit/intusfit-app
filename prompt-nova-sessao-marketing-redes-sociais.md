# Prompt para nova sessão — Design e produção de peças de marketing para redes sociais

## Ferramenta recomendada
Claude Code (local ou Cowork — esta tarefa não depende do pipeline de deploy do site via
`git push`, então não tem a mesma restrição que as sessões de código têm). Cole este
arquivo inteiro como primeira mensagem da sessão. **Antes de produzir qualquer peça, rode
`ListSkills` (ou o equivalente desta ferramenta) e confirme que as skills da seção abaixo
estão realmente disponíveis** — elas podem ser da conta do Luiz e não deste projeto
específico; não assuma, confirme.

## Escopo
Design e produção de peças de marketing para os perfis do ecossistema INTUS no Instagram
e em outros canais que entrarem no "etc" (ver pergunta 2 abaixo). **Não é uma sessão de
código** — não precisa necessariamente abrir este repositório Git, mas os assets de marca
(logos, fotos já publicadas) vivem aqui, e vale reaproveitar em vez de recriar.

## Skills a carregar (nesta ordem de relevância)
1. **`intus-conteudo`** — é a fonte de verdade sobre a arquitetura de funil dos três
   perfis (`@luiznunest`, `@intusfit`, `@codigodadefinicao`), o padrão de copy
   validado/atual/simples e as regras anti-canibalização entre perfis. **Não tente
   re-derivar isso lendo posts antigos ou perguntando do zero — invoque a skill primeiro**,
   ela já carrega esse contexto. Use para qualquer carrossel, legenda, reels, grade
   editorial, story ou ideia de post — mesmo que o pedido do Luiz não cite o perfil nem a
   palavra "funil".
2. **`intus-oferta`** — sempre que a peça empurrar venda direta (Consultoria Premium,
   fechada por WhatsApp, ou Código da Definição/CDD, checkout na Eduzz): regras de oferta,
   diferencial MGF, limites de promessa em saúde.
3. **`dataviz`** — se alguma peça usar gráfico ou infográfico com dado real (evolução de
   aluno, estatística de resultado, comparativo antes/depois numérico).
4. **`artifact-design`** + **`artifact-diagramming`** — carregar antes de montar qualquer
   peça visual como Artifact (ver "Restrição técnica" abaixo).
5. Antes de montar cada card de carrossel à mão em HTML, veja se o tipo de Artifact de
   slides serve melhor: `Artifact({action:"quickstart", intent:"slides"})`. Um carrossel de
   Instagram é, na prática, um deck de slides em formato quadrado ou retrato.

## Restrição técnica que muda o que "produzir a peça" significa
**Claude não gera imagem nem vídeo final** — não há modelo de geração de imagem disponível
nesta conta. "Produção em alta qualidade" pode significar duas coisas bem diferentes, e a
sessão precisa saber qual está sendo pedida antes de começar:

- **Arte finalizada**: montar HTML/CSS no tamanho exato do canvas (1080×1080 feed,
  1080×1350 retrato, 1080×1920 stories/reels), com fotos e logos reais do projeto, e
  exportar como imagem via screenshot do navegador embutido. É a mesma técnica usada numa
  sessão anterior para mockar o redesign do `index.html` — funciona bem para peças
  estáticas, **não serve para vídeo/reels editado** (só dá para entregar roteiro/storyboard
  de reels, não o vídeo pronto).
- **Roteiro/brief para o Luiz (ou outra pessoa da equipe) montar**: copy pronta, ordem dos
  slides/cards, direção de arte descrita em texto, referência visual — quem finaliza no
  Canva, CapCut ou editor de vídeo é a pessoa, não o Claude.

Pergunte antes de assumir qual das duas — muda o volume de trabalho e a ferramenta certa
para cada peça, e provavelmente varia peça a peça (reels sempre vira roteiro; post estático
pode virar arte finalizada).

## O que já existe para reaproveitar (não recriar do zero)
- **5 variantes de logo** em `app/painel/`: `logo-intus-dark.png`, `logo-intus-light.png`,
  `logo-intus-branco.png`, `logo-icon-dark.png`, `logo-icon-light.png`.
- Fotos de equipe e de transformação de alunos já usadas no site institucional —
  referenciadas em `admin/content.json` (`equipe[].img`, `transformacoes[].img`,
  `depoimentos[].img1/img2`), arquivos físicos em `img/`. **`img/` não vai para o Git** (é
  conteúdo de produção, ignorado no `.gitignore`) — se esta sessão rodar num clone limpo do
  repositório, a pasta pode estar vazia ou incompleta; confirme com o Luiz antes de assumir
  que as fotos estão todas ali.
- Regra de estilo já validada com o Luiz, registrada em memória e que deve valer para
  **toda** copy da INTUS, em qualquer canal: **nunca usar travessão** e **nunca usar o
  padrão "não é X, é Y"**.

## Identidade visual — ponto em aberto, não decidido ainda
Uma sessão anterior estava redesenhando o site institucional (`index.html`) para sair do
vermelho/laranja + Barlow Condensed atual e adotar a identidade real do produto: preto
`#0f0f0f` + verde-limão `#7FFF00` + Inter (a mesma já usada no app do aluno e na landing
`teste-gratis.html`). **Esse redesign ainda não foi aprovado nem publicado** — é só um
mockup aguardando feedback do Luiz, sem data definida para ir ao ar. Não assuma que essa é
a identidade "oficial" para marketing de redes sociais. Pergunte se o Instagram já tem uma
identidade visual própria e estabelecida (grid, paleta, tipografia), independente do site,
e se essa identidade deve continuar como está, migrar para a linha nova, ou é uma decisão
separada que não precisa esperar o site ficar pronto.

## Perguntas concretas para o Luiz antes de produzir a primeira peça
1. **Quais peças exatamente?** (post de feed, carrossel, stories, capa de destaque, roteiro
   de reels, anúncio pago — provavelmente uma combinação; liste as que valem para este
   ciclo, não tente cobrir tudo de uma vez.)
2. **Além do Instagram, o que entra no "etc"?** (WhatsApp Status, Facebook, TikTok,
   e-mail marketing, material impresso?)
3. **Arte finalizada ou roteiro/brief para montar depois?** — provavelmente varia por tipo
   de peça (ver "Restrição técnica" acima); confirme caso a caso se não estiver óbvio.
4. **Para qual dos três perfis é a primeira leva** — `@luiznunest`, `@intusfit` ou
   `@codigodadefinicao` — ou é pauta para os três, cada um com seu ângulo? (A skill
   `intus-conteudo` decide o ângulo certo por perfil, mas alguém precisa dizer qual perfil
   publica primeiro e com que frequência.)
5. **A identidade visual do Instagram hoje é a mesma do site institucional atual
   (vermelho/laranja) ou já é outra coisa?** Migrar para preto/lima entra no escopo desta
   rodada, ou fica combinado com o redesign do site (mesma data de lançamento)?
