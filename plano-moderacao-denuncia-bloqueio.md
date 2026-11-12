# Plano: denúncia, bloqueio e moderação (bloqueio L2 das lojas)

**Etapa 1 de 2: só desenho.** Nenhuma tabela foi criada e nenhum arquivo de código foi alterado. A etapa 2 só começa depois do aval do Luiz nas decisões da seção 2.
Revisão de 11/11/2026, commit-base `eb70509`. Números de linha são do `catalogo.php`/`aluno.html` nesse commit (servem de guia, podem andar).

---

## 1. Resumo

**O que as lojas pedem** (Apple 1.2 e política de conteúdo de usuário do Google Play): (1) denunciar conteúdo dentro do app; (2) bloquear usuário abusivo; (3) moderação que responda em prazo razoável; (4) contato publicado. A Apple também cita (5) "filtrar conteúdo ofensivo antes de publicar", que não estava no pedido (ver D7).

**Onde estamos:** só existe "ocultar posts de alguém" (`intus_feed_pref`), e só o Feed a consulta. Comentários, mural, Quadro de Resultados, mensagens, amizade e perfil não consultam bloqueio nenhum. Os Termos não têm regra de conduta.

**O que será entregue (etapa 2):** 2 tabelas novas e aditivas (`intus_denuncia`, `intus_bloqueio`), uma biblioteca com **toda a regra num lugar só** (`app/api/_moderacao.php`, a mesma lição da seção 6 do `CLAUDE.md`), botões Denunciar e Bloquear no app, tela "Moderação" no painel (só administrador), cláusula nos Termos e testes. Nenhuma tabela existente é alterada e nenhum dado de aluno é mexido.

---

## 2. Decisões que preciso do Luiz

| # | Pergunta | Opções e consequência | Recomendo |
|---|---|---|---|
| **D1** | Posso criar as 2 tabelas? | Só criam linhas novas, nascem no primeiro uso depois do deploy, padrão de sempre. Denúncias guardam **cópia do conteúdo** (a prova some se o autor apagar); sugiro guardar 12 meses, sem apagar sozinho (exclusão só pelo painel). | **Sim** |
| **D2** | O que significa "Ocultar conteúdo"? | **A)** Post e resultado: `ativo = 0` (reversível, arquivos ficam). Comentário, mural e mensagem: some só aquela linha, com o texto inteiro preservado na denúncia (não é reversível, mas nada se perde). Sem coluna nova. **B)** Colocar coluna `oculto` em 3 tabelas vivas (reversível em tudo, mexe em tabela de produção). | **A** |
| **D3** | "Bloquear aluno" pela equipe é o quê? | **A)** *Suspender da comunidade*: a pessoa continua treinando, mas não posta, comenta, reage nem manda mensagem, e some para os outros; reversível. **B)** Bloquear a conta inteira (`stbloqueio`, que já existe em Clientes; a pessoa perde o acesso ao treino). A fila terá o botão de A e um atalho "Abrir cadastro" para B (sem código novo mexendo em dado de aluno). | **A + atalho para B** |
| **D4** | Prazo e contato | Prazo de resposta **24 h**. Contato público: sugiro `contato@intusfit.com.br` (já está nos Termos) e o WhatsApp de suporte (45 99115-6006). Para onde vai o e-mail de cada denúncia? Padrão: o mesmo do aviso de backup. | **24 h; confirmar e-mail** |
| **D5** | Ranking e medalhas escondem a pessoa bloqueada? | **A)** Não: o placar é igual para todos (posição e medalha são calculadas de todos; filtrar por pessoa faria o mesmo aluno ter posições diferentes, a doença da seção 6). A pessoa bloqueada some de posts, comentários, mural, mensagens, @ e perfil (tocar na linha do ranking mostra "Perfil indisponível"). **B)** Esconder também no ranking (posições divergem). | **A** |
| **D6** | "Regras da comunidade" na 1ª abertura | Folha única, 1 vez por aluno, ao entrar no Feed/Comunidade/Mensagens depois da atualização (guardada no aparelho, como o aceite dos Termos). Sem isso, quem já aceitou os Termos antigos nunca vê a regra nova. Pouco código, ajuda o revisor da loja. | **Sim** |
| **D7** | Filtro de palavras ofensivas (Apple 1.2 cita) | Lista editável por você (em `intus_config`, sem tabela), checada no servidor em post, comentário, mural e mensagem; palavra inteira, sem acento, para evitar falso positivo. Lista inicial curta que você revisa. Custo: ~meio dia. Sem isso o risco é a Apple pedir na revisão. | **Incluir** |
| **D8** | Corrigir o mural (achado, seção 3.2) | O servidor aceita o autor do comentário **vindo do corpo da requisição**; qualquer aluno logado posta ou apaga em nome de outro. Denúncia de mural acusaria a pessoa errada. Correção pequena em commit separado: usar a identidade do token. | **Incluir, commit separado** |

Para D5 a D8, se você não disser o contrário sigo a recomendação. D1 a D3 e D4 (e-mail) preciso confirmados antes de criar qualquer coisa.

---

## 3. O que existe hoje

### 3.1 Mapa das superfícies

| Superfície | Rota | Bloqueio hoje | Lacuna |
|---|---|---|---|
| Feed, perfil, post único | `feed_posts` GET (~L3335) | `intus_feed_pref` nos dois sentidos | só posts; o mapa `atletas` devolve todos os nomes (alimenta a busca do app) |
| Comentários (Feed, post da Intus, mural da Nutrição, respostas do mural) | `comentarios_pub` (~L2647) | nenhum | lista e grava sem olhar bloqueio |
| Mural dos membros | `treinos.php?action=ranking` (L1128, POST L1252) | nenhum | **autoria vem do corpo** (3.2) |
| Quadro de Resultados | `resultados` (~L4531) | só `stbloqueio` | idem |
| Mensagens entre alunos | `dm` (~L3753) | nenhum, mas **só entre amigos** | contador `so_contagem` conta quem deixou de ser amigo |
| Amizade, parceria | `amizades` (~L3690), `parcerias` | nenhum | qualquer um pede amizade |
| @menção | `pessoas_mencionaveis` (~L2838), `_mencaoBloqueada` (`_notificacoes.php` L131) | `feed_pref` | só ele |
| Reações | `reacoes` (~L2487) | nenhum | reação gera aviso a quem bloqueou |
| Avisos (sino) | `_criarNotificacao` (`_notificacoes.php` L44) | nenhum | ponto único por onde passa todo aviso social |
| Perfil | `perfil_config` (~L3633) | nenhum | abre perfil de qualquer um |
| Busca no app | `_feedBuscar` (`aluno.html` L15846) | nenhum | junta `_rankingData.atletas` e `_feedAtletasMap` no navegador |
| Turmas, desafios | `turmas`, `desafios_aluno` | nenhum | entram só por código ou amizade |

Ponto útil: como a mensagem só existe entre amigos, **bloquear = desfazer a amizade já fecha a conversa**; o que falta é impedir novo pedido e limpar o contador.

### 3.2 Achados (existiam antes deste trabalho)

1. **Mural, autoria forjável.** `treinos.php` POST `comentario` grava `idatleta` e `nome` do corpo; `excluir_comentario` de aluno usa `idatleta` do corpo no `WHERE`. Confirmado em `aluno.html` L19055/L19076 (o app manda o próprio id). Ver D8.
2. `comentarios_pub` GET e `reacoes` GET não conferem a privacidade do post: quem adivinha o id lê comentários de um post "só eu". **Fora deste PR**, registrado para decisão.
3. Os Termos existem em **duas cópias que já divergem**: `termos.html` ("23 de setembro de 2026") e `mostrarTermos()` no `aluno.html` (L23635, "Maio de 2026"). A cláusula nova entra nas duas; unificar é refatoração, fica separada e anunciada.
4. O aceite dos Termos é só `localStorage['intus-termos-aceito-<id>']`, sem versão: por isso D6.

---

## 4. Desenho

### 4.1 Tabelas (aditivas; criadas no primeiro uso, `utf8mb4`, depois `_intusGarantirUtf8mb4` por causa do emoji)

```sql
CREATE TABLE IF NOT EXISTS intus_denuncia (
  iddenuncia     INT AUTO_INCREMENT PRIMARY KEY,
  alvo_tipo      VARCHAR(20)  NOT NULL,  -- feed_post | comentario | mural | resultado | dm | perfil
  alvo_id        INT          NOT NULL,
  autor_tipo     VARCHAR(10)  NOT NULL,  -- aluno | prof (derivado do SERVIDOR, nunca do app)
  autor_id       INT          NOT NULL,
  autor_nome     VARCHAR(200) NOT NULL DEFAULT '',   -- nome no momento da denúncia
  denunciante_id INT          NOT NULL,  -- idatleta; só a equipe vê
  motivo         VARCHAR(20)  NOT NULL,  -- lista fixa, 4.4
  texto          VARCHAR(500) NULL,      -- relato opcional (obrigatório em "outro")
  snapshot       TEXT         NULL,      -- JSON: cópia do conteúdo (texto, mídia, data; DM: + 4 mensagens anteriores)
  status         VARCHAR(12)  NOT NULL DEFAULT 'aberta',   -- aberta | tratada | descartada
  acoes          VARCHAR(60)  NULL,      -- ocultou,advertiu,suspendeu
  nota_equipe    VARCHAR(500) NULL,
  tratada_por    INT          NULL,      -- idusuario do administrador
  tratada_em     DATETIME     NULL,
  criado_em      DATETIME     NOT NULL,  -- gravado pelo PHP (mesmo relógio da conta das 24 h)
  UNIQUE KEY uniq_denuncia (denunciante_id, alvo_tipo, alvo_id),
  KEY idx_fila (status, criado_em),
  KEY idx_alvo (alvo_tipo, alvo_id),
  KEY idx_autor (autor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS intus_bloqueio (
  idbloqueio   INT AUTO_INCREMENT PRIMARY KEY,
  idbloqueador INT NOT NULL,   -- idatleta de quem bloqueou; 0 = a equipe (suspensão da comunidade, D3-A)
  idbloqueado  INT NOT NULL,   -- idatleta de quem foi bloqueado
  iddenuncia   INT NULL,       -- só na suspensão: denúncia que a originou
  criado_em    DATETIME NOT NULL,
  UNIQUE KEY uniq_par (idbloqueador, idbloqueado),
  KEY idx_bloqueado (idbloqueado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Notas: datas gravadas pelo PHP e SQL portável (sem `ON DUPLICATE KEY`/`NOW()`), como `_wa.php` e `_provas.php`, para o teste rodar em SQLite. Uma denúncia por pessoa e item (reenviar devolve "já recebemos"). `intus_feed_pref` **continua como está** ("não ver posts de alguém" é outra coisa, mais leve); o bloqueio vale mais e as duas listas se somam no Feed.

### 4.2 Biblioteca única `app/api/_moderacao.php`

Todas as decisões moram aqui; `catalogo.php` só chama. Funções previstas: `modGarantirTabelas`, `modIdsInvisiveis($pdo,$idatleta)` (bloqueei, me bloquearam, suspensos; 1 consulta indexada, cache por requisição, falha = lista vazia), `modBloqueioEntre`, `modSuspenso`, `modBloquear` / `modDesbloquear` / `modListarBloqueados`, `modDenunciar`, `modFila`, `modAplicarAcao`, `modAvisarEquipe`. **Falha aberta** nas leituras e nas travas (na dúvida não derruba o Feed nem bloqueia ninguém), igual a `_plano_aluno.php`. Professor e administrador nunca são filtrados (precisam ver tudo para moderar).

### 4.3 Rotas (em `catalogo.php`, padrão de `post_intus`/`feed_pref`; não dependem de `INTUS_CONEXOES_ATIVO`)

- `denuncia` POST `{alvo_tipo, alvo_id, motivo, texto}` (só aluno). GET `?motivos=1` devolve a lista de motivos (**fonte única no servidor**: o app e o painel leem dela, então não mexo no `api.js` e não preciso subir o `?v=` das 24 telas por isso).
- `bloqueio` GET (minha lista com nome e foto), POST `{idalvo}`, DELETE `?idalvo=`. **Nunca devolve quem bloqueou o aluno.** Alvo precisa ser aluno existente e diferente de si; profissional não se bloqueia (é o serviço pago).
- `moderacao` (entra em `$_SO_ADMIN`, só administrador): GET fila (`aberta|tratada|descartada|suspensos`), GET `?resumo=1` (abertas, vencidas, mais antiga), POST `{iddenuncia, acao, nota, mensagem}`, POST `reativar`.

### 4.4 Denúncia

- **Alvos:** post, comentário (cobre Feed, post da Intus e mural da Nutrição), comentário do mural do ranking, resultado, mensagem, perfil.
- **Motivos (8):** ofensa/assédio/bullying; nudez ou sexual; ameaça ou violência; discriminação/ódio; spam, golpe ou perfil falso; expõe foto, dado ou conversa de alguém; incentivo a conduta perigosa (dieta extrema, anabolizante sem prescrição); outro.
- **O servidor decide tudo:** acha o item, deriva o autor e tira o snapshot. Não denuncia o próprio conteúdo. **Mensagem: só quem participa da conversa denuncia** (senão a equipe leria conversa alheia); o snapshot leva a mensagem e as 4 anteriores, e a folha avisa isso antes de enviar.
- Limites: 10 por hora e 30 por dia por aluno. O autor **nunca** sabe quem denunciou (nem no aviso, nem em resposta de API).
- Ao denunciar: sino (`denuncia`) para todos os administradores (`_intusPostAdmins`) e e-mail imediato **sem o texto denunciado** (só motivo, tipo e link da fila), no máximo 1 a cada 10 min.

### 4.5 Bloqueio: o que acontece

Ao bloquear: grava a linha; apaga amizade (pendente ou aceita) e parceria nos dois sentidos; apaga do sino do bloqueador os avisos vindos do bloqueado (derivados, apagar é seguro); convites pendentes de post colaborativo entre os dois são recusados. **Nada de dado de aluno é apagado** (mensagens ficam no banco, só deixam de aparecer).

| Onde | Efeito (nos dois sentidos, decidido no servidor) |
|---|---|
| `feed_posts` | posts somem; nome e foto saem do mapa `atletas`/`avatars` devolvido |
| `comentarios_pub` GET | comentários do bloqueado somem (respostas de terceiros viram nível 1, comportamento que já existe para comentário apagado) |
| `comentarios_pub` POST, `reacoes` POST | 403 genérico ao comentar/reagir em conteúdo de quem bloqueou |
| mural, resultados | filtrados por autor |
| `pessoas_mencionaveis`, `_mencaoBloqueada` | soma o bloqueio |
| `dm`, `amizades` | pedido e mensagem: 403 genérico ("não foi possível"); contador só conta amigos |
| `_criarNotificacao` | **um só `if`** descarta qualquer aviso entre bloqueados (reação, comentário, menção, pedido, convite) |
| `perfil_config` | devolve `indisponivel`; o app mostra "Perfil indisponível" |
| ranking, medalhas, turmas | **não mudam** (D5-A) |

Limite honesto: quem foi bloqueado ainda pode ver o *nome* de quem bloqueou na linha do ranking (placar público entre participantes), mas não abre perfil, nem vê publicação, comenta, reage, manda mensagem ou pede amizade. No campo de busca do Feed o app esconde quem **eu** bloqueei; o sentido contrário vem dos filtros do servidor.

### 4.6 Suspensão da comunidade (D3-A)

Linha em `intus_bloqueio` com `idbloqueador = 0`. Vale como "bloqueado por todos" na leitura (some para todo mundo) e uma trava no servidor **antes das rotas**, no mesmo molde do teste grátis (`catalogo.php` ~L1213 e `treinos.php` L1256): POST de aluno suspenso em `feed_posts`, `feed_midia`, `comentarios_pub`, `reacoes`, `dm`, `amizades`, `parcerias`, `turmas`, `desafios_aluno`, `resultados`, `resultado_midia` e no mural → 403 com `codigo: suspenso` e o contato. Ler, treinar, ranking e apagar o próprio conteúdo seguem livres.

### 4.7 Moderação (painel)

Tela nova **`moderacao.html`** (item "Moderação" no menu, só administrador, com contador; uma tela própria porque cobre mensagem, mural e perfil, não só o Feed). Fila agrupada por item (3 denúncias do mesmo post = 1 cartão), com: cópia do conteúdo (texto escapado, miniaturas), motivos com contagem, quem denunciou (só a equipe vê), histórico do autor (denúncias e ações anteriores, denúncias descartadas do mesmo denunciante), tempo desde a 1ª denúncia (**vermelho passando de 24 h**).

Ações (cada uma pede confirmação e permite mensagem e nota):
- **Ocultar conteúdo** (D2-A) e avisa o autor.
- **Advertir** (aviso formal no sino do autor, 160 caracteres, sem dizer quem denunciou; o toque abre as regras).
- **Suspender da comunidade** (D3-A). Aba "Suspensos" com **Reativar**; "Abrir cadastro" para bloquear a conta (D3-B).
- **Descartar** (fecha sem ação).

A decisão vale para todas as denúncias do mesmo item. O denunciante recebe aviso no sino ("analisamos sua denúncia", sem detalhar ação). Cada ação grava em `intus_audit` (`acao = 'moderar'`; fora da lista do "desfazer", sem risco).

**Prazo de 24 h:** sino e e-mail na hora; vermelho na fila; banner no topo do painel para administrador (padrão de `backupAvisoIniciar`) e lembrete na rotina de 6 h (`_rotinas.php`) se algo passar de 18 h. Limite: sem CronJob, o lembrete só roda quando alguém abre o painel; **o e-mail imediato é a garantia**.

### 4.8 App (`aluno.html`)

Pontos de entrada, todos abrindo a mesma folha (`openModalGenerico`; sem `confirm()` nativo nos fluxos novos):
- menu ⋯ do post (`_abrirMenuPost`, L3935): para quem não é dono, **Denunciar publicação** e **Bloquear {nome}** ao lado de "Não ver posts";
- cada comentário (Feed, Intus, mural, Nutrição): um ⋯ discreto na linha de ações, só em comentário de outra pessoa;
- balão recebido na conversa e ⋯ no cabeçalho (Denunciar, Bloquear);
- cabeçalho do perfil social (`abrirPerfilRanking`);
- cartão do Quadro de Resultados.

Folha de denúncia: motivos, relato opcional, aviso "a equipe analisa em até 24 h; a pessoa não saberá que foi você", enviar; depois do envio oferece "Bloquear {nome} também". Bloqueio: folha explicando o efeito (não é avisado, amizade desfeita). **Meu Perfil > Privacidade** ganha "Pessoas bloqueadas (N)" com Desbloquear. Resposta 403 `suspenso` ou `indisponivel` vira mensagem clara. Ao bloquear, o app tira os cartões da pessoa da tela na hora e guarda a lista para a busca. O servidor revalida tudo; o app só esconde botões.

### 4.9 Termos, privacidade, contato

**Cláusula proposta (nova seção "Regras da comunidade", nas duas cópias dos Termos):**

> O Feed, os comentários, o mural, o Quadro de Resultados e as mensagens entre alunos são espaços de convivência. Ao usá-los, você concorda em não publicar, enviar ou compartilhar conteúdo que: (a) ofenda, humilhe, ameace, persiga ou constranja outra pessoa, inclusive por comentários sobre corpo, peso, aparência, gênero, orientação sexual, religião, origem ou deficiência; (b) contenha nudez, conteúdo sexual ou violência; (c) incentive ódio, discriminação, automutilação, transtornos alimentares ou o uso de anabolizantes, hormônios e outros medicamentos sem prescrição; (d) exponha foto, dado ou conversa de outra pessoa sem autorização; (e) seja spam, propaganda não autorizada, golpe ou perfil falso.
> **Tolerância zero.** Conteúdo que viole estas regras é removido, e quem o publicou pode ser advertido, suspenso da comunidade ou ter a conta bloqueada, sem aviso prévio nos casos graves.
> **Denúncia e bloqueio.** Qualquer aluno pode denunciar publicação, comentário, mensagem ou perfil pelo menu ⋯ e bloquear outra pessoa. Analisamos cada denúncia em até 24 horas e tomamos as providências cabíveis. Quem denuncia não é identificado a quem foi denunciado. Ao denunciar uma mensagem privada, você autoriza a equipe a lê-la, junto das 4 anteriores da conversa, para avaliar o caso.
> **Contato.** contato@intusfit.com.br · WhatsApp (45) 99115-6006.

A `privacidade.html` ganha um parágrafo: denúncias guardam cópia do conteúdo denunciado e o registro por 12 meses, e a equipe pode ler mensagens denunciadas. A "Última atualização" das duas telas sobe. Fica de fora (decisão jurídica sua): cláusula de licença do conteúdo publicado.

### 4.10 Ajuda e documentação

`ajuda.js`: perguntas novas (como denunciar, como bloquear e desbloquear, o que acontece depois) e ajuste da FAQ "Não quero ver o post de alguém" (L489); sobe o `?v=` do `ajuda.js`. `CLAUDE.md`: seção nova, e correção da seção 54/21 onde disser que só existe ocultar. `mobile/STORE-LAUNCH-CHECKLIST.md`: item L2 (denúncia, bloqueio, prazo, contato) marcado só depois do teste em aparelho.

---

## 5. Testes (junto da regra, com quebra de propósito)

**PHP em SQLite** (`testes/moderacao.php`, registrado no `rodar.sh`; usa a `_moderacao.php` de verdade): bloquear (amizade e parceria caem, aviso do bloqueado some, não bloqueia a si, só aluno existente, idempotente); invisíveis nos dois sentidos e suspensão; filtros do Feed, comentários, resultados, mural, @ e contador de mensagens; 403 ao comentar, reagir ou pedir amizade a quem bloqueou; aviso entre bloqueados descartado; suspensão barra todos os POST e libera GET/DELETE próprio; denúncia de cada tipo (autor derivado do servidor e não do corpo, DM só por participante, não denuncia o próprio, duplicata, limite por hora, "outro" exige texto, snapshot sobrevive à exclusão); moderação só administrador, ocultar post (e restaurar), ocultar comentário **sem apagar respostas**, advertir sem vazar o denunciante, suspender, descartar, ação em grupo, `vencida` após 24 h com relógio injetável, auditoria; testes estruturais (`moderacao` em `$_SO_ADMIN`, guarda nas rotas, `bloqueio` GET nunca devolve quem me bloqueou).
**Quebras de propósito que precisam ser acusadas:** tirar um sentido do filtro, usar o autor do corpo, tirar a checagem de participante da DM, tirar o `$_SO_ADMIN`, tirar a limpeza da amizade, tirar a trava de suspensão, devolver o denunciante ao autor.

**Navegador (Chromium, API de mentira):** `testes/moderacao_app.js` (⋯ no post e no comentário só para outra pessoa, folha de motivos, "outro" exige texto, envio com os campos certos, oferta de bloquear, bloqueio some da tela, lista e desbloqueio, 403 `suspenso`, busca esconde bloqueado, perfil indisponível, denunciar mensagem recebida, 375 px sem rolagem lateral, tema claro e escuro) e `testes/moderacao_painel.js` (fila agrupada, vencidas em vermelho, ações chamam a rota certa, professor sem acesso, **texto malicioso escapado**).

**Guardas do projeto:** `testes/funcoes.js` antes e depois de mexer no `aluno.html` (nunca âncora que aparece duas vezes; `assert count == 1` em qualquer script de edição); `val.js`; abrir a página e olhar o console. **Impacto em testes existentes:** `colab.php`, `post_intus.php`, `mural_nutri.php`, `avaliacao.php` e `plano_aluno.php` extraem e executam blocos que vou tocar; passam a carregar a biblioteca real (ajuste mínimo, conferido com a suíte inteira).

---

## 6. Riscos e cuidados

- `aluno.html` (~25 mil linhas): mudanças pequenas e localizadas, sem refatorar. O app nativo só recebe com `cd mobile && npm run sync` e novo build (a Apple e o Google revisam o binário, então **sem build novo o bloqueio L2 continua**). O servidor vale na hora para quem já usa a versão com os botões.
- Cache: `_mock.js` (menu e sino) sobe o `?v=` nas 23 telas do painel; `sw.js` e `ajuda.js` sobem a versão. Sem mudança em `api.js`.
- Desempenho: 1 a 2 consultas indexadas por requisição de Feed, comentário, resultado e mural; cache por requisição.
- Reversão: tudo aditivo; reverter o PR basta. As tabelas ficam (inofensivas).
- Excluir um aluno pelo painel não apaga as denúncias que o citam (guardam o nome na época, por ser prova); política de retenção em D1.

## 7. Ordem da etapa 2 (um commit por passo)

1. `_moderacao.php` + `testes/moderacao.php` (sem mudar comportamento).
2. Servidor: rotas, filtros, travas, `_criarNotificacao`; ajuste dos testes existentes.
3. Mural: autoria pelo token (D8), commit separado.
4. App: denunciar, bloquear, lista de bloqueados, mensagens de erro, folha de regras (D6).
5. Painel: `moderacao.html`, menu, sino, banner e lembrete.
6. Filtro de palavras (D7), se aprovado.
7. Termos, privacidade, ajuda, `CLAUDE.md`, checklist das lojas.
8. Testes de navegador e suíte inteira.

## 8. Fora do escopo

Ranking e medalhas sem filtro por pessoa (D5); chat com a equipe e feedback em vídeo (não é entre alunos); privacidade de comentários por id (3.2-2); unificar as duas cópias dos Termos; cláusula de licença de conteúdo; moderação por IA; CronJob para o lembrete (R$ 7,90, decisão dele).
