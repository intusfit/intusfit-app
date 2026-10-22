# Intus Fit — contexto do projeto

> **Onde colocar este arquivo:** na raiz da pasta do projeto, com o nome `CLAUDE.md`.
> O Claude Code lê esse arquivo sozinho no começo de toda sessão — não precisa colar nada.

Este documento é o repasse de duas sessões de trabalho feitas no Cowork (nuvem), entre 31/08 e
06/09/2026, com adições do Claude Code local a partir de 05/09/2026 (seção 16). **A pasta local pode
já estar à frente do que está descrito aqui** — outra IA (G4OS) e o próprio Claude Code já mexeram no
projeto depois. A seção 4 traz o estado do servidor **verificado hoje**, com hash arquivo por
arquivo, justamente para você conferir em vez de acreditar.

---

## 1. O negócio, em três frases

A Intus é uma empresa de **consultoria online de treino e nutrição**. **Não é academia** — não
existe unidade, recepção nem aula. Os alunos treinam onde quiserem e a Intus prescreve, acompanha e
cobra à distância. Use esse vocabulário na interface inteira: *aluno*, *consultoria*, *prescrição*,
*plano*, *matrícula*.

Dono e interlocutor: **Luiz Nunes** (contato@luiznunest.com.br). Produtos: **Consultoria Premium**
(ticket alto, fecha por WhatsApp) e **Código da Definição / CDD** (checkout na Eduzz).

O sistema **está em produção com alunos reais**. Isso muda tudo: o trabalho aqui não é construir, é
alterar um sistema vivo sem quebrar quem depende dele.

---

## 2. Regras invioláveis

Vieram do Luiz por escrito. Ele já pediu uma vez que fossem afrouxadas e a resposta correta foi
continuar recusando — ele aceitou e apontou um caminho alternativo.

- **Nunca digite senha dele em campo de login** (KingHost, FileZilla, webFTP, Google, GitHub). Ele faz
  os logins. Se ele disser "pode digitar minha senha", recuse e explique. Usar uma sessão que **ele já
  deixou logada** é permitido.
- **Nunca entre na conta Google dele.**
- **Nunca gere, baixe ou manipule a chave JSON da conta de serviço do Google Drive**, nem qualquer
  outra credencial/API key/token (isso inclui: SSH sem chave dedicada, PAT do GitHub, etc.). Ele mesmo
  coloca o arquivo no servidor.
- **Exclusão de dado ou de sessão só pelo painel**, nunca por script.
- **Não armazene senhas** em lugar nenhum — nem em código, nem em nota.
- **Migração de banco ou alteração de dado de aluno: descreva o que vai acontecer e espere ele
  confirmar antes de rodar.** Dado de aluno perdido não volta.

---

## 3. Onde tudo mora

Hospedagem compartilhada **KingHost**, servidor `web1108.kinghost.net`, Apache, PHP 8.2.

```
intusfit.com.br
├─ /                      site institucional
├─ /app/painel/           front-end inteiro (19 telas + api.js + _mock.js)
├─ /app/api/               back-end PHP (13+ endpoints)
└─ /app/config/db.php     credenciais do banco (pasta responde 403 — protegida; fora do git)

maximizeapp.com.br        domínio que NÃO será renovado
├─ /app/painel/           cópia velha e VIVA do painel (v=20260520d, de maio)
└─ mysql.maximizeapp.com.br → base maximizeapp03 = o banco de produção

codigodadefinicao.com.br  outra marca; recursos web BLOQUEADOS pela KingHost por
                          uso excessivo (ticket 411645) — mesma conta compartilhada
```

O banco de produção é **`maximizeapp03`** (33 MB, MySQL 5.6, único da conta liberado para "Qualquer
IP" — é assim que a API do intusfit alcança ele). O intusfit **não tem base MySQL própria**; a base
nova, quando criada, será MariaDB 11.4.

Endpoints vivos em `/app/api/`: `aluno_login.php`, `atletas.php`, `audit.php`, `auth.php`,
`catalogo.php`, `dashboard.php`, `desafios.php`, `healthcheck.php`, `mensagens.php`,
`mensalidades.php`, `profile_intus.php`, `treinos.php`, `usuarios.php`, mais os helpers privados
`_audit_log.php`, `_auth_context.php`, `_cors.php`, `_gdrive.php`, `_otp.php`, `_rate_limit.php`,
`_sessions.php` (prefixo `_` = biblioteca interna, não endpoint chamável direto).
~~`upload-drive.php` não existe~~ — **resolvido em 14-22/09/2026**, ver seção 11, problema 4 (endpoint
existe, chave mora no banco, e o aviso de falha por foto deixou de ser silencioso).

Configurações do sistema ficam no servidor via `catalogo.php?action=config`, nas chaves
`ranking_regras`, `cardio_regras`, `conquistas_config`, `figurinhas_config`, `frases_config`,
`notif_config`, `midia_upload`.

---

## 4. Estado do servidor verificado em 06/09/2026

Hash = primeiros 16 dígitos hexadecimais do SHA-256, medidos direto de
`https://intusfit.com.br/app/painel/`.

| Arquivo | Bytes | Hash no servidor |
|---|---:|---|
| `_mock.js` | 58267 | `457d6db23f89a9e2` |
| `alongamentos.html` | 13363 | `3329dc6b918cf7f4` |
| `aluno.html` | 648805 | `fa835d1fceb48efc` |
| `alunos.html` | 117895 | `ebdd3b90019e2160` |
| `api.js` | 189599 | `6aff9358a02a9b3d` |
| `avaliacoes.html` | 78632 | `cf839082e6ac229a` |
| `configuracoes.html` | 47542 | `52ecec148c64be12` |
| `desafios.html` | 23363 | `8e15b3346219208f` |
| `exercicios.html` | 29461 | `3d4527ee6812829d` |
| `financeiro.html` | 128259 | `327709ed3388bcf6` |
| `frequencia.html` | 58277 | `981d66bc00dc3b62` |
| `gestao.html` | 31015 | `3ca7631d15eb1497` |
| `index.html` | 70230 | `ac53a8770a03df84` |
| `login.html` | 61590 | `d1e1c5daab3bed91` |
| `mensagens.html` | 32522 | `dd951182c2354be2` |
| `mensalidades.html` | 120900 | `102787aef6830717` |
| `notificacoes.html` | 79308 | `b42224f5d58bf5c1` |
| `nutricao.html` | 97026 | `f0d685f52b7b19e7` |
| `premium.html` | 16103 | `56aaf7a1bc5349f7` |
| `treinos.html` | 103838 | `5ea85e801a52a70a` |
| `usuarios.html` | 31583 | `abd9a9b481d18ae7` |

**Verificado por Claude Code em 06/09/2026: os 21 hashes bateram 100% contra o servidor ao vivo.**
Zero divergência entre a checagem do Cowork, o servidor real e o repositório git (seção 16). O
conteúdo continua valendo como referência de bytes/hash; **o `?v=` da tabela abaixo mudou** (corrigido
em 06/09, ver seção 16).

**Confira a sua pasta contra essa tabela antes de mexer em qualquer coisa:**

```bash
for f in *.html *.js; do printf "%-20s %s\n" "$f" "$(sha256sum "$f" | cut -c1-16)"; done
```

Batendo o hash, sua cópia é idêntica ao que os alunos estão usando. Não batendo, **descubra por que
antes de subir** — pode ser trabalho seu ainda não publicado, ou trabalho de outra IA que você
apagaria por cima.

### O que cada parte significa

- **15 telas** (`alongamentos`, `alunos`, `avaliacoes`, `configuracoes`, `desafios`, `exercicios`,
  `financeiro`, `gestao`, `index`, `mensagens`, `nutricao`, `premium`, `treinos`, `usuarios`, e o
  `_mock.js`) estavam **intocadas desde 31/08** (na estrutura/conteúdo — o `?v=` mudou em 06/09).
- **`frequencia.html` e `notificacoes.html`** são exatamente a entrega de 01/09.
- **`aluno.html` (+2240 bytes), `api.js` (−1706), `login.html` (+361) e `mensalidades.html`
  (+2049)** receberam alterações do G4OS **por cima** da entrega de 01/09.

### O que foi testado em código que está no ar

Carregado o `api.js` vivo num navegador e rodadas 13 asserções da regra de pontuação: **todas
passaram**. A consolidação de 01/09 sobreviveu ao merge do G4OS. Também comparado o inventário de
funções: o servidor tem uma função a mais (`criarAvaliacaoVerificada`, do G4OS) e **não perdeu
nenhuma** das anteriores.

**A falha de autenticação foi fechada pelo G4OS.** Antes, qualquer Bearer token não vazio era
aceito. Hoje, `atletas.php`, `treinos.php`, `usuarios.php`, `mensalidades.php`, `dashboard.php` e
`catalogo.php` devolvem **401 tanto sem token quanto com token falso**. Isso era o problema nº 2 da
lista de pendências e pode ser riscado. Falta ainda conferir a **autorização** (ver seção 11.2).

### ~~🔴 Problema aberto~~ RESOLVIDO em 06/09/2026: a versão estava dividida

O `api.js` do servidor mudou duas vezes (01/09 e depois o G4OS), mas o `?v=` só tinha subido em 3
telas (`aluno.html`, `frequencia.html`, `notificacoes.html` em `20260901a`) enquanto as outras 15
ficaram em `20260831a`. O navegador guardava os dois como arquivos diferentes: quem já tinha o antigo
em cache continuava rodando o `api.js` velho em 15 telas do painel.

**Corrigido:** todas as 19 telas agora carregam `_mock.js?v=20260906a` e `api.js?v=20260906a` (exceto
`login.html`, que não usa `api.js` — comportamento esperado). Publicado via `git push` → deploy
automático KingHost, verificado ao vivo no servidor.

(Exceção conhecida, sem relação com este bug: `nutricao.html` carrega `alimentos-db.js?v=20260515i`.
Esse arquivo é o único que legitimamente fica noutra versão — mas vale alinhar um dia.)

---

## 5. Arquitetura do front-end

SPA estática. **Sem build, sem framework, sem bundler.** Um arquivo HTML por tela, cada um com o
próprio CSS e JS embutidos, mais dois compartilhados:

- **`api.js` (~190 KB)** — cliente HTTP, cache local e, principalmente, **as regras de negócio**.
  Tudo que decide alguma coisa mora aqui.
- **`_mock.js` (~58 KB)** — layout e sessão: `renderLayout`, `hasPerm`, `isAdmin`, `usuarioAtual`,
  `podeVerGestao`, `PERM_KEYS`, tema, avatares, toasts, modais. (Não confundir com dado de exemplo —
  dentro de `app/painel/aluno.html` há também um objeto `MOCK`/`_FIGURINHAS_FALLBACK` que é *fallback
  de emergência*, não dado de demonstração — ver seção 16.)

Toda tela carrega os dois com cache-buster:
`<script src="_mock.js?v=NNN">` e `<script src="api.js?v=NNN">`.

### Os quatro públicos

| Público | Onde | O que importa |
|---|---|---|
| **Aluno** | `aluno.html`, no celular, na academia, com pressa | Menos cliques, texto grande, funcionar com internet ruim |
| **Instrutor** | painel | Densidade de informação, achar aluno rápido, repetir prescrição |
| **Gestão (Luiz)** | `gestao.html`, `financeiro.html` | A resposta antes do detalhe |
| **Vitrine** | site | Carregar rápido e mandar para a rota certa (WhatsApp ou Eduzz) |

Quando o pedido não disser para qual público é, **pergunte**. É a única pergunta que sempre vale a
interrupção.

---

## 6. A doença crônica deste código

**A mesma regra reimplementada em cada tela, e depois divergindo.** Foi a causa de praticamente todo
bug que o Luiz reportou. Dois casos já curados:

1. **Regra de cobrança** — escrita em seis lugares. Alunos apareciam "em atraso" no Financeiro e
   "em dia" em Matrículas na mesma tarde.
2. **Pontuação do ranking** — escrita em três lugares. O app e o painel mostravam pontos diferentes
   da mesma pessoa na mesma semana.

**A regra de ouro que você herda: nenhuma decisão de negócio pode morar dentro de uma tela.** Regra
nova vai para `api.js`; as telas só perguntam — mesmo que hoje só uma tela use.

Se achar uma cópia sobrevivente, avise o Luiz e proponha consolidar. **Mas não consolide junto com
uma correção**: refatoração vem separada e anunciada.

Um terceiro caso, achado em 06/09/2026 (ver seção 16): **conteúdo apresentado ao aluno também pode
divergir entre "config real do admin" e "fallback fixo no código"** — não é bem a mesma doença (não é
regra de negócio duplicada), mas o mesmo sintoma de fundo: duas fontes de verdade para a mesma coisa.

---

## 7. Regra 1 — cobrança (`API.situacaoCobranca`)

**Na palavra do Luiz:** *todo aluno paga no INÍCIO do ciclo, e a data marcada como vencimento é
quando começa o ciclo novo — e nesse ciclo novo deve ser feito novo pagamento.*

Nunca infira "hábito de pagamento" a partir dos dados. Já foi tentado, deu errado, e o Luiz corrigiu.

`API.situacaoCobranca(mensalidade, listaCompleta)` é a **fonte única**. Devolve
`{estado, cobranca, dias, proxima, ehDivida, ehAcao, valor}`, com estado em:

```
paga  renovada  renovar  paga_no_anterior  programada
pendente  vencida  inativa  encerrada  cancelada  trancada
```

Só `pendente | vencida | inativa` contam como dívida (`ehDivida`). `renovar` é ação, não dívida.

Constantes: `COBRANCA_TOLERANCIA_DIAS: 5`, `PLANO_CARENCIA_DIAS: 5`, `ANTECEDENCIA_CURTA: 15`,
`ANTECEDENCIA_LONGA: 30`, `CORTE_CICLO_LONGO: 100`, `DIAS_PARA_INATIVO: 30`.
Apoio: `dtCobranca`, `cobrancaVencida`, `cicloJaPago`, `baixaQuePagou`, `pagamentoDoProximoCiclo`,
`proximaCobranca`, `chaveMatricula`, `mesesDoPlano`, `ehTestePlano`, `antecedenciaDias`,
`situacaoPlano`.

Consomem: `alunos.html`, `financeiro.html`, `gestao.html`, `index.html`, `mensalidades.html`.

Eduzz: retenção de 30 dias; `eduzzTaxaPlataforma`, `eduzzTaxaAntecipacao`, `eduzzLiquido`,
`eduzzLiberacao`, `eduzzStatus`; estados `retido | disponivel | sacado`. **Cuidado com contagem
dupla** quando dinheiro retido de mês anterior é liberado — `gestao.html` já trata isso.

---

## 8. Regra 2 — pontuação do ranking

Consolidada no `api.js` em 01/09/2026. Antes morava em `aluno.html`, `frequencia.html` e
`notificacoes.html`, cada uma com a sua cópia.

```
1º treino de musculação do dia .... musBase    = 10 pts (musMeia = 5 se durar < musCurtaMin = 20 min)
2º treino no MESMO dia ............ musSegundo =  5 pts
3º em diante no mesmo dia ......... musTerceiro=  0 pts
Cardio ............................ pontos brutos × cardioFator 0,55, teto cardioTeto = 7
Bônus semanal ..................... bonusPts = +5 ao treinar musculação em bonusDias = 4
                                    DIAS DIFERENTES da semana (não sessões)
```

Semana é **domingo a sábado**, em todo o sistema.

**Duas datas de corte, porque placar encerrado não se reescreve** (as medalhas são recalculadas do
zero toda vez que a tela abre):

- `vigorApartirDe: '2026-07-26'` — quando a fórmula de faixas passou a valer. Antes disso toda
  musculação vale 5 pts fixos.
- `vigorSegundoDia: '2026-08-30'` — quando o 2º treino do dia passou a valer diferente e o bônus
  passou a contar dias. Semanas anteriores continuam contando **sessões** no bônus, de propósito.

**API:** `pontosSessaoRank(s, lista)`, `agregarPontosRank(lista)`, `ordemMusculacaoNoDia(s, lista)`,
`diasComMusculacaoRank(lista)`, `regrasRanking()`, `aplicarRegrasRanking(cfg)`, `ptsRank(v)`,
`posicionarDenso(arr)`, `RANK_PADRAO`.

### Três armadilhas

1. **`pontosSessaoRank` PRECISA da lista.** Sem ela não há como saber se o treino foi o 1º ou o 2º
   do dia, e ela assume o 1º — **em silêncio**, dando 10 pts onde deveria dar 5. Passe sempre a
   lista mais completa que tiver, não a fatia exibida na tela.
2. **Sessão com `nao_contar = 1` não pontua E não ocupa lugar na fila do dia.** Um teste do
   professor não pode empurrar o treino real do aluno para a posição de segundo.
3. **A ordem do dia é a ordem em que os treinos foram feitos**, decidida por `idsessao` crescente;
   sessão ainda não sincronizada (sem `idsessao`) é a mais nova e vai para o fim. Não depende da
   ordenação com que a tela chamou.

### Empate

`ptsRank` arredonda para inteiro e `posicionarDenso` dá **posição densa**: quem mostra o mesmo
número divide a colocação e a seguinte não é pulada. Isso existe porque a tela mostrava "50" para
50,4 e 49,6 e mandava um para 2º e outro para 3º — parecia sorteio. **O número que decide tem que
ser o número que aparece.**

### O que o painel edita

`notificacoes.html` → aba Pontuação escreve a chave `ranking_regras`: `musBase`, `musCurtaMin`,
`musMeia`, `musSegundo`, `musTerceiro`, `cardioFator`, `cardioTeto`, `bonusDias`, `bonusPts`,
`vigorApartirDe`, `vigorSegundoDia`, `medalhaSemana`, `medalhaMes`, `medalhaDesde`. Valor quebrado
no painel **não pode derrubar o ranking**: o merge ignora o que não for número.

---

## 9. Convenções obrigatórias

**Temas.** O app do aluno usa `body.tema-claro`; o painel usa `body.theme-light`. São classes
diferentes, em arquivos diferentes. **Toda variável CSS nova precisa ser declarada nos DOIS temas** —
esquecer um produz texto invisível no tema que faltou. Paleta real do app do aluno (extraída em
06/09): fundo `#0f0f0f`, texto `#f0f0f0`, cartão `#1a1a1a`, borda `#2a2a2a`, muted `#888`, destaque
`#7FFF00`, fonte `Inter`.

**Cache-buster.** Alterou `api.js` ou `_mock.js`? Suba o `?v=` em **todas** as telas e confirme que
sobrou um valor só — ver seção 4, era o bug aberto até 06/09.

**Deploy passou a ser automático** (ver seção 16) — não é mais manual por FileZilla para o que passa
pelo Claude Code. `git push` na branch `main` publica sozinho em `/www/` via a integração "Publicação
via GIT" da KingHost.

**Semana:** domingo a sábado, em todo lugar.

**localStorage:** prefixo `intus-` para dados de domínio; `mx-` para sessão (`mx-token`, `mx-user`).
`mx-user.tipo` vale `'aluno'` ou outro; `aluno.html` chuta para `login.html` se não for `'aluno'`.

**Permissões:** `PERM_KEYS` no `_mock.js`, no formato `xx_ver|incluir|editar|excluir` para atletas,
treinos, exercícios, alongamentos, avaliações, nutrição, matrículas, pagamentos e mensagens. Mais
`fin_ver`, `fin_caixa` e `fin_empresa` (esta é `restrita: true` — fica fora do "marcar todas"). A
tela de Gestão usa `podeVerGestao()`, que é `idusuario === 1` **ou** a chave explícita, e
**deliberadamente não usa `hasPerm`**. Hoje só o Luiz vê o caixa da empresa; outros professores
entram depois, limitados aos próprios alunos.

**Visual:** fundo escuro, uma cor de destaque usada com parcimônia, hierarquia por tamanho e espaço
antes de por cor, contraste real de 4.5:1 medido — não no olho. Reaproveite componente existente;
botão novo "só desta tela" é dívida.

---

## 10. Como trabalhar aqui — e os desastres que ensinaram isso

**Melhorias pontuais, não redesenhos completos.** Luiz rejeitou uma rodada de propostas de redesign
de tela inteira (06/09/2026) — o caminho certo é ajuste específico e incremental.

**Mudança mínima que resolve.** Refatoração vem separada e anunciada, nunca de carona numa correção.
O Luiz não é desenvolvedor em tempo integral: o custo de manter o que você escrever recai sobre ele,
e simplicidade vale mais que elegância.

**Leia o trecho inteiro e quem chama antes de alterar.** Uma função de treino provavelmente é usada
pela tela do aluno e pela do instrutor ao mesmo tempo.

**Nunca substitua texto por âncora que apareça duas vezes.** *Uma sessão anterior apagou 42.451
caracteres e 46 funções de `aluno.html` porque a âncora de um `slice` aparecia duas vezes. A sintaxe
continuou válida e nenhum teste estático pegou.* Por isso existe `testes/funcoes.js` (seção 12): ele
guarda o inventário de funções de cada arquivo e reprova qualquer sumiço. **Rode antes e depois de
mexer em `aluno.html`** — é o arquivo mais perigoso do projeto, com ~650 KB. Em scripts de edição, use
`assert s.count(ancora) == 1` antes de todo `replace`.

**Teste no navegador de verdade.** Sintaxe válida não prova nada — foi só abrindo a página que o
erro acima apareceu. Dois detalhes que já custaram tempo: o cache-buster `?v=` **não funciona em
`file://`** (sirva por HTTP), e as telas chutam para `login.html` sem sessão — semeie
`mx-token`/`mx-user` antes de navegar (ver seção 16 para o passo a passo sem senha real), e **baseie
o papel no nome da tela, não no `location.pathname`**.

**Quando um teste falhar, primeiro pergunte se o teste é que está errado.** Já aconteceu quatro
vezes nesta base: a asserção esperava minúsculas onde o CSS aplicava `text-transform`; esperava 7
dias de atraso onde `proximoDiaUtil` empurrava sábado para segunda; um fixture de banco estava com
codificação errada e o detector é que estava certo; e um teste tinha rótulo "4º = 0" afirmando 10.
**Não "conserte" código que funciona para calar um teste.** Diga qual dos dois está errado.

**Teste que só sabe dizer OK não vale nada.** Quebre de propósito o que acabou de validar e confirme
que o teste acusa.

**Assuma seus próprios erros.** Várias correções aqui foram regressões introduzidas pelo próprio
assistente. O Luiz reage bem a "isso quebrou por minha causa, já corrigi" e mal a bug silencioso.

**Confronto construtivo é esperado.** Se ele pedir algo que vai criar problema — complexidade que ele
vai manter sozinho, tela que duplica outra, decisão que trava crescimento — diga em duas frases
**antes** de implementar e ofereça a alternativa.

**Pergunte o que muda o resultado.** Quando a regra tiver ponta solta, pergunte antes de codar — com
as opções e as consequências na mão, não em aberto. Ele decide rápido quando as opções já vêm com a
consequência de cada uma.

---

## 11. Problemas conhecidos, do mais grave para o menos

1. ~~O código da API existe num lugar só~~ — **RESOLVIDO em 05–06/09/2026**: `app/api/`, `app/painel/`
   e todo o front-end estão versionados em `github.com/intusfit/intusfit-app` (privado), com deploy
   automático. Backup deixou de ser um problema.

2. ~~Autenticação da API aceita qualquer token~~ — **RESOLVIDO pelo G4OS**, verificado em 06/09.
   ~~Autorização entre professores~~ — **RESOLVIDO**, verificado em 22/09/2026: `atletas.php` (lista
   e busca por id) e `treinos.php` (fichas/treinos) já filtram no servidor por
   `professores_responsaveis` via `getAtletasDoUsuario()` — professor A pedindo aluno de B recebe
   403, não é mais um filtro só do navegador. Vale ainda um teste ao vivo com dois usuários reais
   antes de publicar nas lojas, mas o código não mostra brecha.

3. **`maximizeapp.com.br` serve uma cópia viva e velha do painel** (v=20260520d, de maio) cujo
   `api.js` aponta para `https://intusfit.com.br/app/api` — **o mesmo banco de produção**. Quem tiver
   link antigo grava dado real com a lógica financeira de maio. Redirecionar ou apagar antes de o
   domínio expirar. **Ação é do Luiz** (gestão de domínio), não dá para resolver só no código.

4. ~~`upload-drive.php` não existe no servidor~~ — **RESOLVIDO**, verificado em 22/09/2026:
   `app/api/upload-drive.php` existe (criado 14/09), usa a biblioteca `_gdrive.php`, e a chave já
   mora no banco (`intus_secrets`, preenchida via `admin/gdrive-key.php` — não mais em
   `app/config/gdrive.json`, que sumia sozinho do servidor). O catch silencioso por arquivo também
   foi corrigido: `avaliacoes.html` (`uploadFotosDrive`) agora mostra um toast listando quais fotos
   falharam, em vez de descartar em silêncio.

5. **SMTP dentro da requisição** faz uma rota levar ~35,9 s. Precisa sair do caminho da resposta.

6. **Avatares servidos como base64**, inflando payload e memória.

7. **MySQL 5.6 fora de suporte**, e `codigodadefinicao.com.br` já bloqueado por uso excessivo de
   recurso na mesma conta compartilhada (ticket 411645).

8. **Menores:** o botão "Agendar" em Notificações não funciona (confirmar com o Luiz se remove); e
   `nutricao.html` referencia `alimentos-db.js?v=20260515i` fora do padrão de versão.

---

## 12. A suíte de testes

Oito arquivos, em `testes/` no repositório (trazidos do zip entregue em 04/09). Rodar tudo:

```bash
INTUS_DIR="/caminho/para/app/painel" ./testes/rodar.sh
```

| Arquivo | O que garante |
|---|---|
| `val.js` | Sintaxe de todo JS das 19 telas e dos 2 compartilhados. Erro aqui = tela em branco no celular |
| `funcoes.js` | Nenhuma função sumiu e nenhum arquivo encolheu >5%. **A guarda do desastre das 46 funções.** `node funcoes.js gravar` refaz a linha de base |
| `pontos.js` | 32 casos da fórmula de pontuação isolada, incluindo as duas datas de corte |
| `fonte_unica.js` | As quatro telas financeiras concordam sobre os mesmos alunos, com data congelada |
| `navegador.js` | As 3 telas de pontuação abrem num Chromium real, sem erro, e respondem igual ao `api.js` |
| `concordancia.js` | O mesmo conjunto de sessões nas 3 telas tem que dar números **idênticos** |
| `financeiro_tela.js` | O Financeiro rodando de verdade, com os alunos que apareciam em atraso errado |
| `_api.js` | Carrega o `api.js` real num sandbox VM, com "hoje" congelável |

Precisa de `node`, `python3` e `npm install playwright`.

**Quando criar uma regra nova, crie o teste junto.** É o que impede a doença da seção 6 de voltar.

---

## 13. Preferências do Luiz

- **Escreve e pensa em português.** Responda em português, com o jargão traduzido na primeira vez.
- **Quer confronto construtivo**, não concordância. Diga o que vai dar errado antes de fazer.
- **Quer ver o resultado, não o processo.** Ao entregar: o que mudou, onde, e o que ele deve testar
  para confirmar. Sem recapitular passo a passo.
- **Odeia bug silencioso.** Prefere ouvir "isso quebrou por minha causa" a descobrir sozinho depois.
- **Prefere melhorias pontuais a redesenhos completos** (confirmado 06/09/2026).
- **Decide quando perguntado com opções concretas.** Perguntas abertas travam; perguntas com 2-3
  opções e as consequências de cada uma ele responde na hora.
- Costuma pedir alterações descrevendo só o sintoma ("o gráfico do aluno está errado"). Investigue
  antes de perguntar.

---

## 14. Estrutural — como as duas IAs trabalham juntas

**Decidido e implementado em 05–06/09/2026** (ver seção 16 para os detalhes técnicos): repositório
Git privado como área comum, deploy automático substituindo upload manual por FileZilla. G4OS
continua com acesso direto ao servidor para funções específicas (auditoria de matrículas, publicação
nas lojas oficiais) — Luiz só libera o G4OS depois que o ambiente estiver sincronizado aqui.

Ainda em aberto: branch por IA com aprovação do Luiz no merge; o `api.js` como "costura" entre
front-end e os endpoints do G4OS ainda não tem contrato escrito formal. A suíte de testes (seção 12)
segue sendo o mecanismo de coordenação mais confiável — rode antes de aceitar qualquer sincronização
vinda de fora.

---

## 15. Por onde começar numa sessão nova

1. Rode o comando de hash da seção 4 e descubra em que pé a pasta local está em relação ao servidor
   (ou peça pro Claude Code rodar via SSH — ver seção 16).
2. Rode a suíte de testes e confirme que passa **antes** de mexer em qualquer coisa.
3. Se for mexer em `api.js`/`_mock.js`, lembre de alinhar o `?v=` nas 19 telas antes de publicar.

Uma última: **este arquivo fica desatualizado.** Quando mudar uma regra central, mudar a topologia ou
fechar um dos problemas da seção 11, atualize aqui — e diga ao Luiz que atualizou.

---

## 16. Atualizações do Claude Code local (05–06/09/2026)

O que mudou desde a última sessão do Cowork, registrado aqui para a próxima sessão não redescobrir:

**Pipeline de deploy implementado** (resolve problema 1 da seção 11 e parte do item da seção 14):
- Git local no computador do Luiz → GitHub privado `github.com/intusfit/intusfit-app`, branch `main`.
- KingHost "Publicação via GIT" habilitado, aponta pra `/www/`, webhook publica sozinho a cada push.
- Segredos que NUNCA vão pro git (ver `.gitignore` do repo): `admin/config.php`, `app/config/db.php`,
  `admin/content.json`, `admin/data.json`, uploads em `img/`, logs, `app/config/gdrive.json` (quando
  existir).
- Chaves SSH dedicadas: `~/.ssh/intus_deploy` (servidor KingHost) e `~/.ssh/github_intusfit` (GitHub).
  Nenhuma senha do Luiz passa pelo Claude Code neste fluxo.
- `git push` costuma ser bloqueado pelo classificador de auto-mode do Claude Code na primeira
  tentativa — rodar o comando direto no terminal do usuário resolve; tentativas seguintes às vezes
  passam direto.
- Pasta antiga `app/p-8f3k2` (parada desde maio, sem link nenhum apontando pra ela) renomeada para
  `app/_removido-p-8f3k2-20260905` — desativada sem apagar, por precaução.

**Achado: conteúdo "fixo" pode ser fallback, não a config real.** Em `app/painel/aluno.html`, a
constante `_FIGURINHAS_FALLBACK` (~linha 1052) inclui a figurinha "ALCATEIA DO FERRO" — **já excluída
há muito tempo** da configuração real, que fica em `admin/catalogo.php` (aba Figurinhas) e é servida
por `app/api/catalogo.php?action=figurinhas_config`. O fallback só aparece se essa busca falhar (rede
caída, ou testando localmente sem back-end). Vale conferir se `_frases_config` e `conquistas_config`
seguem o mesmo padrão antes de assumir que algo é "assim que é" — ver seção 6.

**Como testar uma tela do aluno sem senha real** (Claude Code nunca digita senha, nem de teste):
1. Suba um servidor estático apontando pra pasta local (ex: `python -m http.server`).
2. No console do navegador, sete `localStorage['mx-user']` com um objeto fake
   `{tipo:'aluno', idatleta:1, ...}` e `mx-token` com qualquer string, **antes** de navegar pra
   `aluno.html` (o guard de auth só olha se existe, não valida contra servidor de forma bloqueante —
   os guards de bloqueio por inadimplência/matrícula exigem confirmação do servidor pra bloquear, e
   sem back-end essa confirmação nunca chega, então nunca bloqueiam).
3. Para ver fichas/treinos, semear `localStorage['intus-fichas']` e `['intus-treinos']` no formato
   que `carregarFicha()` espera (~linha 2035 de `aluno.html`).
4. Chamar as funções de navegação direto no console (`navegar('ranking')`, `abrirMeusTreinos()`,
   `abrirQuadroMedalhas()`) é mais confiável que clicar — vários cliques não registraram durante os
   testes de 06/09/2026.
5. Os NÚMEROS que aparecem são de exemplo (localStorage semeado), nunca dados de aluno de verdade.

**Diagnóstico completo do problema 4 (fotos de avaliação)** — ver seção 11, item 4. Falta criar o
endpoint `app/api/upload-drive.php` (a biblioteca `_gdrive.php` já está pronta) e corrigir o catch
silencioso por arquivo em `avaliacoes.html`. A chave `gdrive.json` só o Luiz gera e coloca no
servidor.

---

## 17. Atualizações do Claude Code local (07/09/2026)

Tudo abaixo já está **em produção e verificado** (hash local = hash do servidor) até o momento em
que esta seção foi escrita, exceto onde marcado como "em andamento".

**Home (`aluno.html`):**
- Cards "Ranking" e "Medalhas" ganharam emoji (🥇/🏅) no título; o card de Medalhas não mostra mais
  "desde MM/AAAA" quando o aluno não tem medalha nenhuma (virou "ver quadro completo").
- Cards "Treinos" e "Cardio": ícone e título ficaram lado a lado (`.h-dest .head`, novo), em vez de
  empilhados — cabe melhor em tela estreita. Legenda do Cardio encurtada pra não deixar palavra
  sozinha na segunda linha.
- O espaço de "ver mais" virou grid 2×2: Conquistas/Evoluções/Avaliações diretos, e o 4º quadro abre
  "ver mais" (Central, Perfil).
- Botão "❓ Como usar o app" (`#tutorial-overlay`) foi **totalmente reescrito**: de um texto corrido
  em caixas expansíveis (que ainda pesava pra ler) virou uma experiência tipo Stories do Instagram —
  tela cheia, ícone animado em CSS puro, título curto, 1-2 frases, avança sozinho a cada 6s
  (`TUT_SLIDES`, `_tutIrPara()` e funções ao redor, perto de `abrirTutorial()`). Arrasta ou toca nas
  laterais pra navegar manualmente.
- `telaHeader()` (cabeçalho fixo usado em Ranking/Medalhas/Evoluções/etc.) trocou o fundo sólido por
  um fundo translúcido (`rgba(...,.72)`), sem borda — olhando feio antes, com o conteúdo passando
  atrás durante a rolagem.

**Cardio ao vivo por GPS (novo, `aluno.html`):** rastreio de corrida/caminhada/bike usando
`navigator.geolocation.watchPosition` — cronômetro, distância (haversine entre leituras, descarta
GPS impreciso e saltos acima de ~36 km/h) e ritmo/velocidade, com esboço da rota em SVG. Funciona
hoje, sem depender de app nativo (ver função `abrirCardioLive()` e o bloco "CARDIO AO VIVO" no
arquivo). Ao finalizar, os números preenchem o **mesmo** formulário manual de sempre — pontuação,
limite diário e checagem de plausibilidade continuam só no servidor, sem lógica nova duplicada. O
formulário manual passou a ficar dentro de uma caixa expansível ("Prefiro registrar manualmente"),
ainda necessária pra atividades sem GPS (natação, escada, elíptico etc).

**Bug do emoji virando "????" no banco — corrigido:** várias tabelas de texto livre (mural do
ranking, feed, central de mensagens, chat de desafio) foram criadas antes deste projeto especificar
`utf8mb4` no `CREATE TABLE`, e `IF NOT EXISTS` não corrige uma tabela que já existia com outro
charset — emoji de 4 bytes virava `?` ao salvar. Criado `app/api/_charset_fix.php`
(`_intusGarantirUtf8mb4($pdo, $tabela, $colunas)`): confere o charset real via `information_schema` e
só faz `ALTER TABLE ... CONVERT TO utf8mb4` quando necessário — chamado em `treinos.php`,
`catalogo.php`, `mensagens.php` e `desafios.php`. **Ao adicionar uma tabela nova de texto livre,
chame esse helper depois do `CREATE TABLE IF NOT EXISTS`.** Dois comentários que já tinham virado
"???" (de Luiz Nunes e Isabela Pazzinatto) foram reparados de volta pra 🔥, por texto exato, dentro
do próprio bootstrap de `treinos.php?action=ranking`.

**Substitutos de exercício — fonte única confirmada:** `intus_exercicio.substitutos` (catálogo,
editado em `app/painel/exercicios.html`) é a fonte correta. `intus_treino.substitutos` (override por
ficha individual) já teve um bug de corrupção por colisão de IDs de seed, com conserto manual em
`treinos.php?action=fix_subs_treino`. **Qualquer feature nova que precise de substituto de exercício
deve ler do catálogo, nunca duplicar essa lista.** O Luiz relatou (07/09) que alguns exercícios ainda
têm substituto errado no catálogo — correção pontual dos dados é dele/do professor, via
`exercicios.html`.

**Captura de leads (novo):**
- `teste-gratis.html` (raiz do site, fora de `app/`) — landing page pública com a mesma identidade
  visual do `index.html` institucional (mesma paleta, mesmas fontes). Formulário curto (nome,
  WhatsApp, e-mail opcional, objetivo) com honeypot anti-spam; ao enviar, abre o WhatsApp
  (`5545991156006`) com mensagem pronta.
- `app/api/leads.php` (novo, sem auth, mesmo padrão de `aluno_login.php`, protegido por
  `checkRateLimit`) — cria linha em `intus_lead` (nome, whatsapp, email, objetivo, origem, status,
  ip, criado_em). Não cria conta de aluno.
- QR code apontando pra `teste-gratis.html` foi gerado e entregue direto ao Luiz (não é servido pelo
  site — fica em `img/`, que é ignorado no git por guardar upload, não build).
- Existe uma linha de teste em `intus_lead` (`nome = 'TESTE Claude Code (apagar)'`, id 1) que pode
  ser apagada.

**Três frentes despachadas para fora desta sessão (07/09):**
1. **Login social (Google/Facebook) + unificação de cadastro duplicado** — em construção *nesta
   mesma sessão*, logo em seguida a esta atualização: novas ações `oauth_google`/`oauth_facebook` em
   `aluno_login.php`, casando por e-mail verificado (nunca confiar no e-mail que o cliente diga ser).
   Quem já é aluno com aquele e-mail entra na conta existente; quem não é vira lead (mesma tabela
   `intus_lead` acima), nunca conta nova duplicada. Se você está lendo isto numa sessão nova e essas
   ações ainda não existem no arquivo, o trabalho ficou incompleto — confira o estado real do
   arquivo antes de assumir qualquer coisa.
2. **Plano self-service (aluno monta a própria ficha)** — desenho completo (questionário
   individualizado, motor de regras que aprende com as fichas reais do Luiz, fila de revisão do
   professor) despachado para uma **sessão separada** do Claude Code, com prompt próprio
   (`prompt-nova-sessao-plano-self-service.md`, entregue ao Luiz, não commitado no repo). Nada disso
   foi construído ainda por esta sessão.
3. **App nativo + publicação nas lojas** — despachado para o **G4OS**, com prompt próprio
   (`g4os-prompt-publicacao-lojas.md`, entregue ao Luiz, não commitado no repo). Inclui o aviso de que
   a Apple exige "Sign in with Apple" se o app oferecer outro login social — relevante por causa do
   item 1 acima.

Se você é uma sessão nova (Claude Code, Cowork ou G4OS) chegando depois desta atualização: **as três
frentes acima podem já ter avançado em paralelo por outras sessões** — rode o hash-check da seção 4,
leia o código de verdade antes de assumir que algo descrito aqui ainda reflete o estado atual, e não
duplique o que already existe (`_charset_fix.php`, `intus_lead`, cardio ao vivo).

## 18. Revisão pra publicação nas lojas (22/09/2026)

**Achado importante: já existe um projeto Capacitor local**, em `mobile/` (fora do Git — pasta
inteira aparece como não rastreada). Tem `capacitor.config.json` (appId `br.com.intusfit.app`),
projetos iOS e Android já montados (`mobile/ios`, `mobile/android`), ícone iOS 1024×1024 já no lugar,
splash screens do Android já geradas, e um script (`mobile/scripts/build-web.mjs`) que copia
`aluno.html`/`login.html`/`api.js`/`_mock.js`/`privacidade.html` etc. de `app/painel/` pra dentro do
app nativo a cada build. Tem também `mobile/STORE-LAUNCH-CHECKLIST.md`, um checklist próprio de
lançamento — **leia esse arquivo primeiro** numa sessão nova antes de assumir o que falta.

**Importante pro fluxo de trabalho depois de publicado**: o Capacitor empacota o HTML/JS *localmente*
no binário (não carrega a tela ao vivo do site — sem `server.url` no config). Isso significa que só
mudanças em `app/api/*.php` chegam a quem já instalou o app via `git push` normal. Mudanças em
`aluno.html`/`api.js`/`_mock.js` exigem `npm run sync` + rebuild + reenvio pra loja (Android libera
rápido; iOS passa pela revisão da Apple de novo).

**Corrigido nesta sessão** (todos os itens abaixo já testados e no ar em `app/painel/`, exceto onde
marcado como só em `mobile/`, que fica fora do deploy automático):
- **Exclusão de conta dentro do app** (exigência Apple 5.1.1v) — não existia. Novo botão em Meu
  Perfil > "Excluir minha conta", pede confirmação digitando EXCLUIR, chama
  `aluno_login.php?action=excluir_conta` (aluno autenticado via `validateSession`). Não apaga dado na
  hora: bloqueia o acesso imediatamente, marca `exclusao_solicitada_em`, avisa a equipe por e-mail, e
  revoga a sessão. A exclusão definitiva dos dados continua manual, pelo painel, em até 30 dias — a
  regra de "exclusão só pelo painel, nunca por script" (seção 2) não mudou.
- **Termos de Uso sem URL pública** — criado `app/painel/termos.html`, cópia do texto que já existia
  só dentro do app (`mostrarTermos()`), agora também acessível como página pública.
- **Política de privacidade incompleta** — `app/painel/privacidade.html` não mencionava
  geolocalização (usada no Cardio ao vivo) nem notificações push; adicionado. Data de atualização
  também subiu.
- **Permissões nativas sem descrição** — `mobile/ios/App/App/Info.plist` (Info.plist) e
  `mobile/android/.../AndroidManifest.xml` não declaravam os textos de câmera/localização/fotos nem
  as permissões Android (câmera, localização, notificações, mídia) — adicionado. Fica só em
  `mobile/`, fora do deploy automático do site.
- **Ícone da Play Store faltando** — só existiam favicons pequenos nas pastas do Android; gerado
  `mobile/store-assets/google-play-icon-512.png` (e uma cópia 1024×1024 pra ficha do App Store
  Connect) a partir do master já usado no ícone do Xcode.

**Dois itens do checklist que a revisão apontou como abertos e acabaram já estando resolvidos** (o
`CLAUDE.md` estava desatualizado nesses dois pontos — ver seção 11, itens 2 e 4, já corrigidos acima):
autorização entre professores, e upload de foto de avaliação pro Drive.

**Ainda em aberto, não resolvido nesta sessão:**
- **Sign in with Apple** — não existe (`oauth_apple`). Mas Google e Facebook também estão **ambos
  desligados hoje** (Client ID/App ID ainda são placeholder em `aluno_login.php`/`aluno.html`), e a
  exigência da Apple só vale se outro login social estiver realmente ativo — não é bloqueio enquanto
  os dois continuarem desligados. Se algum dia forem ligados antes de submeter pro iOS, isso vira
  bloqueio de verdade.
- **Autenticação Google/Facebook desligada** — placeholders (`SUBSTITUA_PELO_CLIENT_ID_DO_GOOGLE`,
  `SUBSTITUA_PELO_APP_ID_DO_FACEBOOK`) nunca foram trocados pelas credenciais reais.
- Os outros itens do `mobile/STORE-LAUNCH-CHECKLIST.md` que dependem de conta/acesso do Luiz
  (Apple Developer Program, Google Play Console, papéis do G4OS, chaves de assinatura) continuam
  como estavam — nada disso é algo que código resolve.

## 19. Feedback em vídeo, estilo Loom (25/09/2026)

**Publicado na `main` em 25/09/2026, a pedido do Luiz.** Testado só contra um banco local e um Drive simulado; o primeiro teste com o Google Drive real foi feito por ele, já no ar.

- **`feedback-video/`** (raiz, irmã de `relatorios-fotos/`): o professor grava a **tela**, a
  **tela + câmera em bolha** ou **só a câmera**, com microfone (e o som do computador quando o
  navegador permite). Tem pausa, cronômetro, **limite de 10 min** (decisão do Luiz) e prévia antes de
  enviar. Depois vincula a um aluno (mesmo autocomplete de `atletas.php?busca=`), dá título e recado,
  e recebe o link. Mesmo login do painel (`mx-token`/`mx-user`), entrada no menu do `_mock.js`
  ("Feedback em Vídeo", ícone `ICONS.video`).
- **Revisão de 25/09 (depois do 1º teste do Luiz no ar):** ao escolher a tela "nada acontecia". A
  causa provável: a permissão de microfone/câmera era pedida DEPOIS da escolha da janela, num aviso
  pequeno do navegador, e a tela ficava esperando sem dizer nada. Agora a ordem é microfone/câmera →
  janela → contagem 3-2-1, com um aviso na tela em cada passo e o erro destacado. Tudo passa por um
  canvas (o "palco"), e é o palco que vira o vídeo. Isso permite **desenhar por cima** (caneta, linha,
  seta, retângulo, círculo, texto e ângulo com graus, na mesma ordem de clique do Relatório de Fotos),
  com desfazer/limpar e a bolha trocando de canto. Modos: **Foto ou vídeo do aluno** (padrão: abre
  vários arquivos, cada um guarda os próprios desenhos, e o vídeo tem câmera lenta), **Uma janela ou
  aba** (a própria aba e a tela inteira ficam fora da lista, senão vira espelho infinito) e **Só
  câmera**.
- **Revisão 2 de 25/09 (pedido do Luiz, usando Opera):** o modo "Foto ou vídeo do aluno" **saiu**.
  Ficaram **Tela + câmera** (padrão), **Só a tela** e **Só a câmera**. Voltou a **tela inteira**
  (só a aba da própria ferramenta fica fora da lista). Na tela inteira, o professor vê só um "mapa"
  do quadro, não a imagem da tela (senão a página se grava dentro dela mesma, o espelho infinito), e
  os **desenhos ficam desligados**: o navegador não desenha por cima de outros programas. Numa janela
  ou aba, os desenhos continuam. A bolha **arrasta com o mouse** e tem uma **régua de tamanho**, as
  duas durante a gravação; a posição e o tamanho ficam salvos em `localStorage['intus-fb-bolha']`.
  Para o "o pedido de microfone/câmera não aparece": a ferramenta consulta o estado da permissão antes
  de pedir. Se estiver bloqueada (o Opera/Chrome bloqueiam sozinhos depois de o pedido ser fechado
  algumas vezes), mostra o passo a passo do navegador em uso (`opera://settings/content/microphone`
  etc.). Se o pedido ficar 8 s sem resposta, avisa também. A linha "Diagnóstico" mostra
  navegador/versão, o estado de cada permissão e a política do site; peça print dela quando algo
  falhar. O `.htaccess` da raiz passou de `microphone=()` para `microphone=(self)`, porque não dava
  para confirmar daqui se a exceção da pasta valia na KingHost; `feedback-video/.htaccess` agora
  também manda `Cache-Control: no-cache`.
- **Revisão 4 (15/10/2026), pedido do Luiz:** (1) **limite subiu de 10 para 15 min** (`FB_DURACAO_MAX_SEG = 900` em
  `feedbacks.php`; o front lê de `action=eu`). (2) **Câmera flutuante**: janela Document Picture-in-Picture (Chrome/Edge/
  Opera recentes) por cima das outras janelas, com o quadro como está sendo gravado, tempo, pausar, parar e reiniciar;
  em tela inteira mostra só a câmera (o quadro gravado conteria a própria janela). Precisa de clique do usuário
  (`btn-pip`); o `mediaSession` `enterpictureinpicture` tenta abrir sozinho ao trocar de aba. (3) **Reiniciar**: apaga o
  que foi gravado e refaz a contagem 3-2-1 mantendo tela/câmera liberadas (2 cliques pra confirmar, sem `confirm()`,
  que travaria o desenho do quadro). (4) **Editor de cortes** (`feedback-video/editor.js`): linha do tempo com onda
  sonora e zoom, arrastar pra selecionar, cortar pontas e meio, prévia que já pula os cortes, "voltar ao original".
  Por baixo: mp4box.js lê o MP4, o vídeo é decodificado/recodificado só no que fica (WebCodecs, H.264, 1 Mbps), o áudio
  AAC é copiado quadro a quadro (sem recodificar) e mp4-muxer monta o arquivo. Só MP4 e só Chromium com WebCodecs.
  (5) **Depois de gravar, o MP4 é reorganizado sem recodificar** (`IntusEditorVideo.otimizar`): índice no começo e
  duração certa, o que também resolve a demora do Chromium pra abrir (ver `feedback/player.js`); só vale se o
  resultado passar em `validarVideo`, senão fica o original. (6) **Editar gravação já salva** (botão "✂ Editar" na lista):
  baixa o vídeo, abre no mesmo editor e **substitui o arquivo mantendo token, link, título, comentários e reação**
  (`editar_iniciar`/`editar_parte` em `feedbacks.php`, tabela nova `intus_feedback_edicao` com a sessão de upload; o
  arquivo antigo do Drive só é apagado depois que o novo chega inteiro). `video_url` ganhou `&r=` (muda a cada
  substituição) pro cache de 1 h do aluno não misturar trechos do vídeo antigo com o novo.
- **Revisão 3 (30/09), Opera 136 no Windows:** com as permissões já liberadas, apareceu "A câmera ou
  o microfone não abriu" (`NotReadableError`: o Windows não conseguiu ligar o aparelho). Agora
  `abrirMicCamera()` pede os dois juntos e, se o aparelho falhar, tenta cada um separado (a câmera
  também sem exigir resolução). Se separados funcionam, grava direto. Se não, diz QUAL falhou e
  oferece "Gravar sem a câmera" / "Gravar sem o microfone". O diagnóstico mostra quantas câmeras e
  microfones o sistema enxerga e o último erro exato do navegador. **A causa real no computador do
  Luiz ainda não foi confirmada**: peça o print do aviso novo.
- **Corrigido em 28/09, com o OK do Luiz:** o `.htaccess` da raiz tinha `geolocation=()`, o que
  recusava em silêncio o GPS na versão web do `aluno.html` (Cardio ao vivo e mapa de local do Feed).
  Passou para `geolocation=(self)`. No app nativo (Capacitor) nada muda: ele não passa por esse
  cabeçalho.
- **`feedback/?v=<token>`**: página pública que o aluno abre pelo WhatsApp, sem login. Token de 32
  hex, impossível de adivinhar. Excluir a gravação derruba o link na hora.
- **`app/api/feedbacks.php`** + tabela **`intus_feedback`** (tem `tipo`, pensada para receber
  também outros tipos de feedback depois). Detalhes técnicos no cabeçalho do arquivo.
- **Armazenamento: Google Drive**, na mesma conta de serviço e na mesma pasta das fotos de avaliação
  (`_gdrive.php` ganhou funções de upload em partes e de leitura por faixa). O vídeo **nunca fica no
  disco da KingHost**: cada pedaço de até 4 MB é repassado na hora ao Drive, e o player lê de volta
  em faixas de 4 MB. O YouTube foi descartado porque exigiria OAuth do canal do Luiz, que é uma
  credencial dele. Retenção: **só exclusão manual** (decisão do Luiz). A tela mostra o espaço total
  ocupado.
- **Microfone estava bloqueado no site inteiro** pelo `Permissions-Policy: microphone=()` do
  `.htaccess` da raiz. Foi liberado **só** em `feedback-video/.htaccess`.
- **Tela do aluno (05/10/2026)**: em `aluno.html`, na tela **Avaliações**, bloco "Feedbacks em vídeo"
  (`carregarFeedbacksAluno`, `abrirFeedbackVideo`; lista de `feedbacks.php?action=meus`, selo "Novo"
  enquanto `visto_em` é nulo, e abrir chama `action=ver` pra marcar como assistido). Na web já vale; no
  app nativo exige rebuild/envio pra loja (ver seção 18). Ainda falta a aba no perfil do aluno **no
  painel** do professor (`listar?idatleta=N` já existe).
- **`feedback/player.js` (05/10/2026)**: quem toca o vídeo, usado pela página do link e pela tela do
  aluno (no app nativo é carregado de `https://intusfit.com.br/feedback/player.js`, então correção no
  player não exige rebuild). **Bug que isto corrigiu:** a página do link só abria no celular. O vídeo é
  MP4 fragmentado (MediaRecorder) e o Chromium (Chrome/Edge/Opera, também o WebView do Android) pula
  fragmento a fragmento pelo arquivo todo pra achar a duração; cada pulo é um pedido ao Drive via PHP
  (1–3 s), então um vídeo de 5 min ficava em "carregando" pra sempre. Agora, no Chromium com MP4, baixa
  em faixas de 4 MB (3 em paralelo) e toca de um Blob; nos outros tenta direto e cai nisso se não abrir
  em 8 s. Se algum dia o vídeo passar a sair gravado com índice (ex.: remux no `feedback-video`), dá pra
  simplificar. `feedback/.htaccess` manda `no-cache` pro link não ficar preso na versão antiga.

**Achado na suíte (anterior a este trabalho):** `testes/funcoes.json` foi gravado de uma cópia local
que tinha as funções `_share*`/`_publicarPostFeed` em `aluno.html`, e elas **nunca estiveram no
git**. Por isso o `funcoes.js` acusa perda. Ou esse trabalho local ainda não foi publicado, ou a
linha de base precisa ser refeita. Confira antes de rodar `node funcoes.js gravar`.

## 20. Nutrição completa (06/10/2026)

- **Aluno (`aluno.html`, `renderNutricaoAluno`)**: dashboard do dia (anel de kcal estimadas, macros, próxima refeição,
  água com copo de 250 ml, aderência 7 dias, peso das avaliações) e 3 visões: **Hoje** (linha do dia), **Prato**
  (anéis e proporção por refeição) e **Semana** (grade 7 dias e lista de compras). Marca cada refeição como
  feita/parcial/fora, com observação e foto opcionais; registro sem internet fica em `intus-nutri-pend-<id>` e é
  reenviado (`_nutriEnviarPendentes`). Só dá para registrar hoje ou ontem. Na web já vale; no app nativo exige rebuild.
- **Painel (`nutricao.html`)**: abas **Dashboard** (KPIs, tabela de quem precisa de atenção, gaveta por aluno com
  grade de 14 dias, fotos, resposta ao aluno e remoção de foto) e **Planos** (a tela antiga, intacta).
- **Regras (fonte única, `api.js` → `API.nutri`, teste `testes/nutri.js`)**: aderência (feito 1, parcial 0,5, fora 0,
  refeição sem registro em dia encerrado 0, hoje só conta refeição cujo horário já passou), consumo estimado pelo
  plano, sequência, meta de água (35 ml/kg), alertas (sem registro 3+ dias, aderência < 50%, plano vencendo/vencido,
  proteína baixa só se o plano entrega a meta, peso parado 3 semanas, sem plano) e lista de compras. A chave da
  refeição no registro é `horario|nome` em minúsculas: **renomear ou mudar o horário de uma refeição do plano
  desvincula os registros antigos dela.**
- **Banco (só tabelas novas)**: `intus_nutri_registro` (um por aluno, dia e refeição; foto, observação, resposta da
  nutri) e `intus_nutri_dia` (água). Endpoints em `catalogo.php`: `nutri_registro`, `nutri_dia`, `nutri_painel`.
  Fotos em `app/img/nutri/` (só o aluno e a equipe responsável veem; remover pelo painel apaga a referência, o
  arquivo continua no disco). Resposta da nutri vira aviso no sino do aluno (`nutri_resposta`).
- **Limites conhecidos**: o plano é igual todos os dias (não há cardápio por dia); as calorias "consumidas" são
  estimativa do plano, não pesagem.

## 21. Perfil social e conexões entre alunos (06/10/2026)

- **Perfil social** (`aluno.html`, `abrirPerfilAluno(id)`): abre ao tocar no nome ou na foto de um aluno no Feed,
  nos comentários, no mural e nas curtidas. Abas: Posts (grade e visualizador), Conquistas (marcas pessoais),
  Mural de resultados e Semana. O ranking e as medalhas continuam abrindo a visão de sempre
  (`abrirPerfilRanking(id)`), com o botão "Ver perfil completo". As duas usam a mesma função
  (`abrirPerfilRanking(id, social)`).
- **Privacidade** (tabela `intus_perfil_config`, action `perfil_config`): `fotos_visib` (todos, amigos, eu) e três
  chaves (`mostrar_conquistas`, `mostrar_resultados`, `mostrar_semana`). Sem linha, tudo visível. Fotos privadas
  somem do Feed e do perfil para os outros alunos, no servidor (`feed_posts`). O mural escondido também é cortado
  no servidor (`resultados?idatleta=`). Conquistas e semana são só escondidas na tela (os dados vêm do ranking,
  que é público entre participantes). A equipe (professor) enxerga tudo. Se o app não conseguir ler a privacidade
  de outro aluno, ele esconde tudo em vez de mostrar (falha fecha).
- **Conexões, LIGADAS (06/10/2026)**: `INTUS_CONEXOES_ATIVO` (constante no topo de `catalogo.php`). **Para desligar:
  trocar para false e publicar** (os endpoints respondem 403 e o app esconde o menu Comunidade, os botões do perfil e
  o filtro do Feed, porque lê a chave em `perfil_config`). Tela **Comunidade** (menu, `v-amigos`) com abas Amigos,
  Pedidos, Parceiros, Turmas e Desafios.
  - **Amizade** (`amizades`): pedido, aceite, desfazer, busca por nome entre quem está no ranking, filtro Amigos no
    Feed (`feed_posts?so_amigos=1`), avisos no sino. Desfazer a amizade desfaz a parceria.
  - **Parceria de treino** (`parcerias`): só entre amigos, até 3. Sequência em dupla e treinos no mesmo dia vêm de
    `API.parceria` (teste `testes/parceria.js`). O aviso "seu parceiro treinou hoje" é registrado pelo próprio app
    (`notificacoes` aceita o tipo `parceiro`).
  - **Turmas** (`turmas`, tabelas `intus_turma` e `intus_turma_membro`): criadas por aluno, até 30 membros, cada aluno
    em até 5. Entrada por código de convite de 8 caracteres (só o dono vê e pode gerar outro). Ranking de pontos da
    semana e do mês calculado no app com `API.agregarPontosRank`. O dono sai passando a turma ao membro mais antigo;
    encerrar a turma só marca `ativo = 0`. Sem mural da turma por enquanto.
  - **Criação de desafio por aluno: DESLIGADA** (`INTUS_ALUNO_CRIA_DESAFIO`, `catalogo.php`, valor false). Hoje só a
    equipe cria desafios, pelo recurso Desafios do painel (`desafios.php`). Com a chave desligada o servidor recusa o
    POST (403), a aba Desafios da Comunidade mostra o atalho para a tela Desafios da equipe e o botão "Novo" da turma
    some. Para liberar aos alunos: trocar para true e publicar (o app lê `desafios_alunos` em `perfil_config`).
  - **Desafios entre alunos** (`desafios_aluno`, tabelas `intus_desafio_aluno` e `intus_desafio_aluno_part`): meta
    com prazo (treinos, cardios, atividades por dia distinto, ou pontos), até 120 dias, numa turma (os membros entram
    quando quiserem) ou com amigos escolhidos (entram direto e recebem aviso). Regras em `API.comunidade` (teste
    `testes/comunidade.js`). Cancelar só marca `ativo = 0`. Sem distintivo ao final por enquanto.
  - **ATENÇÃO, nomes de tabela**: `intus_desafio` e `intus_desafio_participante` são do recurso **Desafios do admin**
    (`desafios.php`), com outro formato. Os desafios entre alunos usam as tabelas `intus_desafio_aluno*`, de
    propósito. Não criar nada com os nomes antigos no `catalogo.php`.
  - Progresso e pontos saem das sessões do ranking (`_rankingData`): quem não participa do ranking aparece sem
    números. Com as conexões ligadas, "Só amigos" nas fotos funciona de verdade.
- **Privacidade por publicação (07/10/2026)**: cada post tem a sua (`intus_post_visib`: todos, amigos ou eu). O aluno
  escolhe ao publicar (campo `visib` no POST de `feed_posts`; sem ele vale o padrão do perfil naquele momento) e muda
  depois na **tela do post** (`abrirPostTela`; PUT `feed_posts {idpost, visib}`, só o dono), aberta pela grade do
  perfil (próprio ou de outro aluno) e pelo menu do post. Post sem linha usa o padrão do perfil
  (`intus_perfil_config.fotos_visib`), então mudar o padrão afeta só os posts ainda não ajustados. O filtro é feito na
  consulta de `feed_posts` (Feed e perfil); só o dono recebe o campo `visib` de volta. Post que não é de "todos" não
  gera aviso de @menção. A equipe enxerga tudo.
- **Compartilhar conquista**: a tela de conquista desbloqueada e o detalhe de uma conquista já desbloqueada têm a
  logo da Intus e o botão "Compartilhar conquista" (`abrirCompartilharConquista`). Gera um cartão 1080x1350 em canvas
  (medalha, nome, descrição, logo e @intusfit) e abre a folha com: Feed da Intus, só no perfil (com a privacidade da
  publicação), WhatsApp, Instagram (pelo menu de compartilhar do aparelho, Web Share API), copiar e salvar. Aviso
  (toast), "aguarde" e as folhas ficam acima das telas (z-index 100000+).
- **Rolagem**: perfil, post aberto e tela de privacidade travam a rolagem da página de trás (`_pkTravarFundo`).

## 22. Planos alimentares ativos e inativos (06/10/2026)

- `nutricao.html`, aba Planos: filtro **Ativos / Inativos** (como em Treinos), botão Ativar/Inativar no cartão e na
  visualização do plano (edição parcial só do campo `ativo`) e seletor Ativo/Inativo no editor. Não há vencimento
  automático (diferente das fichas): o alerta de plano vencido continua só avisando a nutri.
- **O aluno só recebe plano ativo**: `catalogo.php?action=nutricao` filtra `ativo = 1` para aluno e o app não cai mais
  no primeiro plano da lista. Inativar o único plano de um aluno deixa a Nutrição dele sem plano.

## 23. Tela sempre vertical (07/10/2026)

- App nativo travado em retrato: `mobile/android/app/src/main/AndroidManifest.xml` (`screenOrientation="portrait"` na
  MainActivity) e `mobile/ios/App/App/Info.plist` (iPhone só retrato; iPad retrato e retrato invertido com
  `UIRequiresFullScreen`, exigido pela Apple quando o iPad não aceita todas as orientações). **Só vale depois de novo
  build e envio às lojas.** O `manifest.json` do app web já pedia retrato e o `aluno.html` tenta `screen.orientation.lock`
  (reforço que só funciona em PWA ou tela cheia no Android).
- **Exceção: vídeo em tela cheia pode girar para horizontal (07/10/2026).** O `aluno.html` escuta a tela cheia
  (`fullscreenchange`, e `webkitbeginfullscreen` no iOS) e libera a rotação só enquanto durar. No Android nativo a
  ponte é `IntusNativo.telaCheia(bool)` em `MainActivity.java` (libera com `SCREEN_ORIENTATION_USER`, ou seja, segue o
  giro automático do aparelho, e volta ao retrato ao sair). No iOS o app continua só em retrato no `Info.plist` e o
  vídeo gira pelo player do sistema: **conferir num iPhone de verdade**; se não girar, será preciso liberar as
  orientações horizontais no `Info.plist` e travar o retrato por plugin. No Android também vale só depois de novo
  build.

- **Giro de tela (07/10/2026, substitui a cortina)**: o aluno segura o celular de qualquer jeito durante o treino, então NÃO há aviso
  para girar. Onde a trava não funciona (Safari no iPhone, navegador comum no Android, PWA do iOS) o `<html>` é desenhado em pé, girado
  90° para o lado contrário, por **CSS puro** (`@media (orientation: landscape) and (pointer: coarse) and (hover: none)`), o que vale no
  mesmo quadro em que o navegador gira, sem piscar a tela horizontal (a 1ª versão decidia por JS depois do evento e piscava ~0,3 s). O JS
  só informa: `data-gd` (90 ou 270, pelo ângulo; guarda o último em `localStorage['intus-gd']` para o 1º quadro), `--gf-w/--gf-h` (medidas
  exatas da janela) e as classes `teclado` (campo focado com o aparelho em pé: janela larga não pode girar nada) e `tela-cheia` (vídeo),
  que desligam o giro. A rolagem passa para o `<body>`; `window.scrollTo`, `scrollY` e `pageYOffset` foram redirecionados e o evento
  `scroll` é repassado; `window._giroAtivo()` diz se está girado. Limites: `vh`/`innerHeight` medem a janela horizontal e as margens de
  área segura (notch) ficam do lado errado. **Testado só no Chrome simulando; conferir num iPhone e num Android de verdade.**
## 24. Feed: busca, reações e Cardio (07/10/2026)

- **Home**: o título "Feed dos Alunos" é um link para a tela do Feed.
- **Busca no Feed** (`_feedBuscar`): alunos que participam do Feed ou do ranking (nomes já carregados no app) e equipe
  (professores e nutricionistas de `intus-usuarios`, no aparelho, com o nome público do Luiz). Aluno abre o perfil
  social; equipe abre uma folha (`abrirPerfilEquipe`) com cargo, bio, Instagram e atalho para o chat.
- **Reações no post**: além do coração (❤️ 🔥 💪 👏 😮 😂). Toque no botão curte ou tira a sua reação; segurar o botão
  ou tocar no "＋" abre a barra. **Uma reação por pessoa**: o POST de `reacoes` aceita `unica: true`, que troca a
  anterior. O sino diz "reagiu 🔥 ao seu post" quando não é coração.
- **Cardio**: "Registrar manualmente" e "Histórico de cardios" viraram cartões em destaque (ícone, descrição e seta),
  no lugar do texto cinza. O texto do Cardio ao vivo agora só diz que dá para monitorar e já registrar pelo app.
- **Botão flutuante 🏠 do Feed e da Home (07/10/2026)**: não aparecia porque contava os 3 primeiros posts do documento
  inteiro, e Início e Feed ficam montados ao mesmo tempo (a que não está aberta fica escondida, com posts de altura 0).
  Agora conta só os posts da tela aberta (`#v-<view>`). O clique não troca mais de tela: sobe rolando até o topo
  (`_rolarAoTopo`, animação própria de 450 a 1100 ms, interrompida por toque).
- **Reações v2 (07/10/2026)**: o bug de não conseguir remover era do app: `carregarReacoesFeed` só somava o que o servidor
  devolvia, e o servidor omite o post que ficou sem reação, então a reação antiga continuava na tela. Agora cada post
  pedido é esvaziado se não vier. O servidor ganhou ações explícitas em `reacoes`: `definir` (deixa só esta reação) e
  `remover` (tira todas as da pessoa no alvo); `unica` continua para apps antigos. Visual: botão "Curtir" (vira a
  reação escolhida, e tocar nele tira) + botão "Reagir" (barra com nome de cada reação e "Remover minha reação") +
  linha "Você, Maria e mais 3" acima. A tela muda na hora e volta atrás se o servidor recusar.
- **Atalhos da Home**: Conquistas, Evoluções, Avaliações e "Ver mais" têm a mesma estrutura (ícone, título e legenda) e o
  conteúdo centralizado na vertical; os cartões Treinos e Cardio também centralizam o conteúdo.
- **Reações, desenho final (07/10/2026)**: a linha do post é "reações já feitas + total" (toque abre quem reagiu),
  a sua reação (🤍 ou o emoji que você deu; tocar curte ou tira) e o "＋" com as outras reações. Os botões com texto e a
  linha de nomes foram descartados a pedido. Ficam os consertos: lista esvaziada quando o servidor omite o post,
  ações `definir`/`remover` no servidor e atualização imediata na tela.
- **Perfil social**: o cabeçalho não tem mais a linha de baixo (era ela que cortava a logo); a logo ficou na posição original.

- **Reações do Feed (07/10/2026)**: 13 emojis (`_REACOES_POST`) numa grade de 7 colunas, mais o seletor "Tom do 💪" (6 tons, guardado em
  `localStorage['intus-rx-tom']`). A reação gravada leva o modificador (💪🏽, 2 caracteres, cabe nos 8 do servidor e nos 16 da coluna);
  o resumo do post soma os tons do mesmo emoji (`_rxBase`). Só o 💪 tem tom. Servidor sem mudança (aceita qualquer emoji até 8 caracteres).

- **Tom do 💪 (07/10/2026)**: sem tabela de cores. Tocar no 💪 reage com o tom guardado; **segurar** abre uma fileira com os 6 tons
  (`_rxLigarSegurar`), soltar o dedo sobre um tom (ou tocar nele) guarda como padrão e já reage.
- **Última tela antes de postar, post de vídeo (07/10/2026)**: mostra o próprio vídeo (com som e controles), na proporção real dele
  (o item guarda `w` e `h`), com "Baixar vídeo" (`_composeBaixarVideo`, também um ⬇ em cada vídeo da lista) e "Editar capa". A prévia de
  foto também segue a proporção da foto (antes cortava tudo em ~4:5 por causa de `max-height`). A capa do vídeo não leva figurinha de
  novo (já vem no quadro) e o painel de informações fica oculto nesse caso.
- **Som do vídeo (07/10/2026)**: o editor agora confere o áudio do arquivo gravado (decodifica e mede o pico). Se o original tem som e o
  resultado saiu mudo, tenta o próximo modo. No iPhone a ordem é buffer, elemento, mudo. Quando sai sem som, o aviso traz um
  diagnóstico entre parênteses (modo, estado do áudio, pico): peça o texto ao usuário. No feed, tocar no play de propósito liga o som
  (o início automático continua mudo). Testado só no Chrome (os dois modos captam som); **iPhone não testado**.

- **Baixar vídeo de post saía com ~3 s (07/10/2026)**: `midia.php?action=video` sem cabeçalho Range devolve só o 1º megabyte (para o
  player começar logo). `baixarPost` agora usa `_baixarVideoEmPartes` (faixas de 4 MB até 416 ou fim) e `midia.php` expõe
  `Content-Range` por CORS. Qualquer novo código que baixe vídeo por esse endpoint precisa pedir por faixas.
- **Som no iPhone, 2ª rodada**: quando o modo não capta o som (áudio suspenso, decode falhou etc.) o editor passa ao próximo
  (antes só o modo `elemento` fazia isso), e o diagnóstico lista a falha de cada modo no aviso.

- **Avisos (toast) do aluno (07/10/2026)**: o tempo na tela agora cresce com o texto (2,2 s + 55 ms por letra, máximo 12 s; o segundo
  parâmetro só pode aumentar), a caixa vai até 420 px de largura e tocar no aviso fecha na hora. Vale para todos os `toast()` do `aluno.html`.

- **Notificação abre a publicação certa (07/10/2026)**: `_notifAbrir` (lista do sino e aba Notificações da Central) abre a própria
  publicação com `abrirPostTela`, via `_abrirPostPorNotificacao`: usa o post já em memória ou busca só ele em
  `catalogo.php?action=feed_posts&idpost=N` (mesma privacidade de sempre; o dono sempre se vê). Notificação de comentário, resposta ou
  menção abre com os comentários à mostra; post apagado ou invisível mostra "Esta publicação não está mais disponível". Antes ia sempre
  para o topo do Feed, porque só rolava até o card se ele já estivesse na página carregada. Resultado rola até o card; mural, amizade,
  parceria, turma, desafio, nutrição, medalha e distintivo seguem para as telas deles.

- **Respostas em qualquer nível (07/10/2026)**: todo comentário (e toda resposta) tem "Responder", no Feed e no Mural do app e no
  `feed.html` do painel. Os comentários formam uma árvore pelo `resposta_a`; o recuo cresce 26 px por nível até 3 e, do 4º nível em
  diante, aparece "↳ Nome" dizendo a quem responde. Comentário cujo pai foi apagado vira de nível 1. No servidor, excluir um
  comentário leva a cadeia inteira de respostas (`catalogo.php`, DELETE de `comentarios_pub`, desce até 30 níveis).

## 25. Último treino (07/10/2026)

- A Home dizia "Último: Treino C" quando o último tinha sido o D. Causa: `SessoesTreino.listarDoAtleta` ordenava só pela
  data, e com dois treinos no mesmo dia a ordem entre eles dependia da ordem de gravação. Agora desempata pela ordem
  real (`idsessao` maior é o mais novo; sessão ainda sem `idsessao` é a mais nova). A Home e a tela "Escolha seu
  treino" passaram a usar a mesma função, `_ultimaSessaoMusculacao(fichas)` (cardio não conta: o servidor grava o
  cardio com divisão 'C'), em vez de dois filtros separados.

## 26. Edição de vídeo, figurinhas com gestos e download (07/10/2026)

- **Editor de vídeo estilo Instagram** (`_vtAbrir` e funções `_vt*`, `aluno.html`): prévia na proporção do vídeo, faixa de
  quadros embaixo (10 miniaturas), bordas verdes arrastáveis e a faixa inteira para deslizar o trecho, linha branca que
  acompanha a reprodução, trecho em repetição, e abas **Cortar / Filtros / Figurinhas**. As regras do corte (mínimo 1 s,
  máximo 30 s, deslizar) estão em `API.trecho` (`testes/trecho.js`). Vídeo sem duração (comum em webm gravado por
  navegador) é tratado pulando para o fim.
- **Filtros no vídeo**: os mesmos 8 filtros da foto (`FEED_EDITOR_FILTROS`), aplicados quadro a quadro com `ctx.filter`
  ao gravar. Se o aparelho não suporta `ctx.filter` (iOS antigo), a aba avisa e o vídeo sai sem filtro.
- **Figurinha (informações do último treino ou cardio, `INFO_LAYOUTS`) sobre foto e vídeo**: um dedo arrasta, dois
  dedos fazem a pinça (tamanho e posição), a roda do mouse muda o tamanho. Só recebe o dedo no modo "Ajustar figurinha"
  (fora dele a tela rola). O motor é `_ovGestos` (estado `{x, y, s}` em fração da moldura) e `_ovDesenhar` desenha o mesmo
  estado na foto final (`_compositarInfoNaFoto`) e em cada quadro do vídeo (`_vtGravar`).
- **Logo da Intus em todo vídeo novo**: entra no canto, igual às fotos. A capa do vídeo sai com filtro e figurinha, sem
  logo (ela entra ao publicar). Vídeos publicados antes disso não têm a logo gravada.
- **Baixar post** (`baixarPost`): o dono baixa a foto ou o vídeo do próprio post (tela do post e menu ⋯). Foto passa por
  `catalogo.php?action=feed_baixar` (só o dono; o app nativo não tem CORS na pasta pública); vídeo vem de `midia.php`.
  No celular abre o menu de compartilhar do aparelho (Salvar imagem/vídeo); sem ele, foto abre para salvar e vídeo baixa.
- **iPhone, prévia do corte (07/10/2026)**: o Safari só decodifica o vídeo depois do primeiro play, então buscar posição antes disso
  era ignorado (o play saía do começo do arquivo e a faixa de miniaturas ficava preta). Agora o editor dá um play mudo e já pausa ao
  abrir (também no vídeo das miniaturas), e `onplaying`/`ontimeupdate` puxam para o início do trecho se tocar fora dele. **Conferir num
  iPhone de verdade.**
- **Figurinha: o que se move e o que fica fixo (07/10/2026)**: os 5 layouts (`INFO_LAYOUTS`) aceitam um 7º parâmetro `soBloco`. Com
  `true` desenham só o bloco (nome, estatísticas, recorde), SEM a logo e SEM a moldura do layout Moldura. Foto e vídeo usam sempre
  `soBloco = true` na camada movível; a logo (canto inferior direito) e a moldura (`_infoMolduraHtml` na prévia,
  `_infoMolduraDesenhar` no canvas, `_vtMontarFixos` no editor de vídeo) ficam fixas, em qualquer posição ou tamanho da figurinha.
  Sem `soBloco` (telas antigas de compartilhar) tudo sai junto como antes. Os textos não usam mais "...": a fonte das estatísticas
  encolhe pelo total de caracteres (`_shareStatsRow`) e nome do treino/aluno quebram em linhas.
- **Cuidado ao editar**: `val.js` só confere sintaxe. Uma chamada de função colocada numa linha de declaração `let`
  derrubou o script inteiro em teste (erro de inicialização). Depois de editar `aluno.html`, abra a página e olhe o
  console.

## 27. Vídeo de 30 s e armazenamento do Google Drive (07/10/2026)

**Meta: o Drive não passar de 1 TB.** Decisões do Luiz em 07/10/2026 e o que foi feito:

- **Vídeo de post: 30 s** (era 60), **no máximo 2 vídeos por post** (era 3; vale também para o Quadro de Resultados, que usa
  o mesmo `midia.php`). Cliente: `COMPOSE_VIDEO_MAX_SEG` e os dois limites de 2 em `aluno.html`. Servidor: `MD_DURACAO_MAX_SEG`,
  `MD_TAMANHO_MAX` (60 MB) e `MD_MAX_VIDEOS` em `midia.php`. App já instalado com a versão antiga recebe erro do servidor ao
  passar do limite até atualizar.
- **Gravação do vídeo do post: 1,8 Mbps** (era 2,5), áudio 80 kbps.
- **Fotos menores**: o app exporta no máximo 1440 px a 86% (era 1920 px a 94%, uns 1,5 MB por foto, agora uns 400 KB). O
  servidor também reduz sozinho qualquer foto grande que chegue (`_otimizarFotoBin` em `catalogo.php`, usa GD, só JPEG,
  guarda o original se algo falhar), o que cobre apps antigos.
- **Feedbacks em vídeo** (`feedback-video/index.html`): 650 kbps (era 1,2 Mbps), 15 quadros por segundo, largura máxima 1280
  (era 1920), áudio 64 kbps. Fica perto de 5,5 MB por minuto. O limite de 10 min continua.
- **Cota por professor** (`feedbacks.php`: `FB_LIMITE_VIDEOS = 40`, `FB_AVISO_PCT = 80`): `action=eu` e `action=listar`
  devolvem `cota`; `iniciar` recusa com 409 ao chegar no limite. A ferramenta mostra barra, aviso a partir de 80%, bloqueia
  "Começar a gravar" quando cheio e tem o botão "Apagar N já assistidas há mais de 30 dias" (confirmação, uma exclusão por
  vez pela rota `excluir` que já existia).
- **Painel de armazenamento** (Configurações > Servidor, `verUsoDrive`; também abre por `configuracoes.html#servidor`):
  `catalogo.php?action=drive_uso` (só admin) mede a pasta REAL do Drive (soma por nome: `feedvideo_` posts, `feedback_`
  feedbacks, `intus-backup-` backups, imagens = fotos), mostra por tipo, projeta quando chega a 1 TB em 3 cenários (ritmo de
  hoje, 5x e 20x) e grava uma medida por dia em `intus_armazenamento_hist` (tabela nova, aditiva). Níveis: atenção 60%, alerta
  80%, crítico 90% do limite, ou limite chegando em menos de 1 ano (atenção) / 90 dias (alerta) no ritmo atual. O aviso
  aparece no topo de todas as telas do painel para admin (`armazAvisoIniciar` em `_mock.js`, consulta
  `action=armazenamento_alerta`, no máximo uma medição por dia, dispensável por 24 h).
- `action=drive_limpar_envios` (POST, admin, botão com confirmação): apaga só o REGISTRO de envios de vídeo travados há mais
  de 1 dia. Envio retomável incompleto não vira arquivo no Drive, então isso não libera espaço dele.
- **Bug achado em `cron-backup.php`**: a retenção chamava `_gdriveConfigPath()`, que não existe desde que a chave foi para o
  banco (06/09). O erro era engolido e **nenhum backup antigo foi apagado até 07/10**. Corrigido (`_gdriveKey()`, mais
  `supportsAllDrives` na listagem e na exclusão). A partir daí vale a regra de `MANTER_DIAS = 45` e `MINIMO_COPIAS = 10`.
- **O que cada coisa é de fato**: foto de feed fica no disco da hospedagem (`app/img/feed`) e uma cópia no Drive
  (`gdriveBackup`, nome do arquivo); vídeo de post e feedback ficam SÓ no Drive; fotos de avaliação e avatares ficam em
  `app/uploads` e entram no zip diário de backup (zip completo a cada mudança). O backup do banco é diário.
- **Decisão do Luiz (07/10/2026):** limite de 40 gravações por professor mantido. Fotos e posts antigos NÃO precisam de otimização
  (não pesam o bastante). Retenção de backup ficou como está (45 dias, mínimo 10 cópias) até ele pedir mudança.
- **Estudo: versões menores de conteúdo com mais de 30 dias** (nada disso foi aplicado nem será por ora):
  - Foto: dá para regerar em 1080 px a 80% com GD, no mesmo nome de arquivo, por botão do painel com prévia da economia
    (corta uns 60 a 70% da foto antiga). Troca o arquivo original, então precisa de confirmação. A cópia no Drive teria que
    ser substituída ou apagada.
  - Vídeo: depois de enviado não dá para recomprimir, a hospedagem não tem ffmpeg (o painel mostra se mudar). Alternativas:
    prazo de validade do vídeo (apagar o vídeo e manter a capa), ou deixar como está porque agora são ~7 MB por vídeo.
  - Feedback: apagar os já assistidos há 30+ dias (botão já existe).
  - Backup: guardar 14 diários + 1 por semana em vez de 45 dias seguidos, e zip de uploads só a cada 7 dias.
- Apagar arquivo do Drive continua só pelo painel, com confirmação (regra do projeto).

## 28. Ajuda e tour interativo (08/10/2026)

- **`app/painel/ajuda.js`** (novo, carregado por `aluno.html` com `defer`) substitui o tutorial em histórias do botão "?". Cria as próprias camadas
  (`#ax-tour`, `#ax-help`) e não altera nenhuma tela do app. **Entrou na lista de arquivos do build nativo** (`mobile/scripts/build-web.mjs`) e no
  `sw.js` (cache v33): arquivo novo precisa entrar nas duas listas, senão o app das lojas fica sem ele.
- **API**: `Ajuda.abrir()` (central de ajuda), `Ajuda.tour(i)` (capítulo i, ou abertura), `Ajuda.bemVindo()`, `Ajuda.fechar()`. `abrirTutorial()` chama
  `Ajuda.abrir()` e só cai no tutorial antigo se o módulo não carregou.
- **Tour**: 9 capítulos (Treino, Cardio, Ranking, Conquistas, Feed, Amigos, Nutrição, Evolução, Central), 37 telas. Cada capítulo é um celular
  de demonstração em que o aluno toca nos botões que pulsam (`data-ir` = tela seguinte, `data-hint` = pulsa). As telas são funções em `capitulos()`;
  para adicionar um passo basta acrescentar um item em `telas`. Os textos de pontos usam `API.regrasRanking()` e as conquistas usam
  `CONQUISTAS_DEF`, então acompanham as regras reais.
- **Central de ajuda**: cartão do tour (continua de onde parou), "Sobre esta tela" (capítulo do `window._viewAtual`), busca (perguntas e
  mini-aulas, sem acento), primeiros passos (treino e cardio vêm das sessões, mensagem do `Store`, post do servidor) e 25 perguntas em `FAQ`.
  Quando uma regra do app mudar, atualize a pergunta correspondente em `FAQ`.
- **Aluno novo**: `renderWelcome` ganhou o cartão "Fazer o tour" no lugar da grade de cartões. A bolinha do "?" volta uma vez para quem só tinha visto o
  tutorial antigo (`intus-ajuda-v2-visto`). Estado em `localStorage['intus-ajuda-v2']`.

## 29. Plano parcelado: Pix, dinheiro, cartão (08/10/2026)

- **Sem migração de banco**: as colunas `parcela_num`, `parcela_total`, `forma_pgto` e `idmatricula_origem` já existiam em `intus_mensalidade`.
- **Modelo** (regras e criação em `api.js`, seção "PLANO PARCELADO"): um plano de P meses pago em N parcelas (2 a 12) vira N linhas. Cada
  parcela é uma janela que obedece à regra de cobrança de sempre (paga no início, vencimento = fim da cobertura): `dtinicio` = quando a parcela
  é cobrada, `dtvencimento` = quando a seguinte começa (a última vai até o fim do plano). `idmatricula_origem` das parcelas 2..N aponta para a
  parcela 1; `recorrencia` fica vazia (o plano não gera ciclo novo sozinho; depois da última aparece o estado "renovar").
- **API**: `gerarParcelas` (só calcula; centavos, o resto vai para a última), `criarPlanoParcelado` (cria, e desfaz o que criou se falhar no
  meio), `serieParcelas`, `resumoParcelas`, `parcelasAbertas`, `dadosRenovacaoParcelada`, `ehParcelada`, `rotuloParcela`, e as ações da série
  inteira `cancelarParcelas`, `trancarParcelas`, `destrancarParcelas`, `moverParcelas`. Testes em `testes/parcelas.js` (76 casos).
- **Regras ajustadas no `api.js`**: `mesesDoPlano` de uma parcela é a janela dela (antes lia "trimestral" da descrição e a parcela 2 aparecia
  vencida e a baixa gerava um ciclo de 3 meses); `pagamentoDoProximoCiclo` ignora parcela numerada; `_normalizarPlano` ignora o "(2/6)";
  `situacaoPlano` não deixa parcela que ainda não começou manter o aluno ativo enquanto ele deve a anterior.
- **Telas**: Nova matrícula em `mensalidades.html` e na ficha do aluno (`alunos.html`) ganharam Parcelas, Forma de recebimento e a prévia (a
  prévia mora em `_mock.js`, `parcPrevHtml`); com status "Paga" a 1ª parcela já entra recebida. Etiqueta "Parcela 2/6 · Pix" nas listas. Baixa de
  parcela (nas três telas que dão baixa) não gera ciclo novo e mostra quanto falta. Cancelar, trancar, destrancar e mover a data valem para as
  parcelas em aberto. ↻ em plano parcelado abre o mesmo parcelamento de novo a partir do fim do anterior. `financeiro.html` conta como recebida
  a parcela paga mesmo depois do plano acabar. O app ("Minha Matrícula") mostra pagas, restantes e a próxima cobrança.
- **Valores diferentes por parcela (08/10/2026)**: na prévia, cada parcela (menos a última, que é sempre o saldo) tem um campo de valor, para
  casos como R$ 700 de entrada e o resto no mês seguinte. `API.gerarParcelas` aceita `valores: [700]` (líquido da parcela, depois do desconto);
  o desconto é repartido na proporção, a soma sempre fecha com o plano e a renovação repete os valores da série anterior. Estado da edição em
  `PARC_ED` (`_mock.js`, `parcEditar`/`parcReset`); mudar a quantidade de parcelas zera os valores combinados.
- **Data de cada parcela (08/10/2026)**: na mesma prévia, cada parcela tem um campo de data (a 1ª fica travada: é a data de início do plano).
  `API.gerarParcelas` aceita `datas: [...]` (dia em que a parcela é COBRADA; vazio = padrão mensal). As janelas continuam contíguas (cada uma vai até
  a cobrança da seguinte, a última até o fim do plano), e a data tem de ser depois da anterior e antes do fim. Mudar a data de início ou a
  quantidade de parcelas esquece as datas combinadas ("restaurar padrão" volta tudo). Anos fora de 2020 a 2100 são ignorados na digitação
  (o navegador avisa valores parciais enquanto se digita o ano). Testes em `testes/parcelas.js` (76 casos).
- **Cópias que ganharam a mesma guarda** (parcela numerada nunca é "sobra"): `_cobrancaSuperada` em `mensalidades.html` e `_superada` em
  `index.html`. **Aviso**: parcelamentos feitos antes pelo modal antigo (sem ligação) passam a ser lidos pelas regras novas; o status deles pode
  mudar para o correto.
- **Corrigido em 09/10/2026**: no `financeiro.html`, linha PAGA de plano comum em estado `renovar`, `encerrada`, `cancelada` ou `trancada` agora entra em "recebido" (e no Caixa, no LTV e nos
  melhores clientes), como já valia para parcelas. A linha fica marcada `_pagoEncerrado` e não conta como aluno com plano em pé na retenção da aba Analytics.

## 30. Atendimento por WhatsApp com Claude (08/10/2026)

Projeto de atendimento e follow-up de leads pelo número da Intus (5545991156006), na **API oficial da Meta**. Decisões do Luiz: autonomia
**híbrida** (sequências automáticas, robô responde dúvidas, passa para uma pessoa em preço da Premium, lesão/dor/doença, reclamação, pedido de
humano e vídeo de exercício); todo lead vai para a **Consultoria Premium** por enquanto (o CDD entra depois, então o destino tem de ser
configurável); modelo do robô **Claude Opus 5.5** (`claude-opus-5-5`); atendente chama **Cora** ("assistente virtual da Intus"), nome guardado
em ajuste, não fixo no código.

**Pronto e testado (Base):**
- `app/api/_wa.php`: biblioteca com TODA a regra (horário 8h-21h em America/Sao_Paulo, janela de 24h, consentimento, no máximo 1 mensagem proativa
  por dia, pedido de saída "SAIR", normalização de telefone BR, agenda do teste de 7 passos, assinatura da Meta, envio de texto/template, registro).
  SQL portável de propósito (sem `ON DUPLICATE KEY`, sem `NOW()`; horário gravado pelo PHP) para rodar igual em SQLite nos testes e no MySQL 5.6.
- `app/api/whatsapp_webhook.php`: GET responde o desafio da Meta, POST exige `X-Hub-Signature-256` válido, é idempotente pelo `wa_id`. Erro nosso no
  processamento responde 200 (a Meta desativa webhook que falha muito) e registra um código no log. Sem chave configurada responde 503.
- `admin/whatsapp-keys.php` (login do admin): grava no banco (`intus_secrets`: `whatsapp_config`, `anthropic_config`, `cron_config`, `wa_ajustes`).
  Nunca mostra uma chave salva de volta, tem token CSRF e botão "Testar conexão com a Meta". **O Claude Code nunca vê esses valores.**
- Tabelas novas (criadas na primeira abertura da tela de chaves ou do webhook, com autorização do Luiz de 08/10/2026): `intus_wa_contato`,
  `intus_wa_mensagem`, `intus_wa_agenda`. Nenhuma tabela existente foi alterada.
- Testes: `testes/wa.php` (133 conferências). **Neste computador o PHP 8.2 foi instalado pelo winget** e fica fora do PATH da sessão; chame pelo caminho
  completo e passe um `php.ini` com as extensões `mbstring, openssl, curl, pdo_sqlite, sqlite3` (`php -c <ini> testes/wa.php`). Foram feitas quebras
  de propósito na biblioteca (horário, ordem de status, cancelamento de agenda, saída por trecho, janela) e os testes acusaram todas.
  Armadilhas já pagas: o PHP troca o ponto por sublinhado nos parâmetros (`hub.mode` vira `hub_mode`); `Set-Content -Encoding UTF8` do PowerShell 5
  grava BOM e isso quebra cabeçalhos HTTP em arquivo PHP.

**Falta (nesta ordem):** aceite de mensagens por WhatsApp na landing gravando o consentimento (`optin_em`) e disparando a agenda; sequência do teste
(worker por CronJob da KingHost, que é pago e por HTTP com `X-Cron-Auth`, a "carona" não serve para hora marcada) e os 7 templates na Meta; robô de
conversa (Claude, com manual de vendas e transferência para pessoa); tela "Funil de Leads" no painel. Texto de todas as mensagens segue o skill de
oferta (sem prometer resultado, prazo ou número; o teste é só de treino, sem dieta) e a regra de escrita do Luiz: sem travessão e sem "não é X, é Y".

## 30b. Nutrição do aluno: tela inicial, receitas, vídeos e dicas (08/10/2026)

- **Tela inicial da Nutrição** (`aluno.html`, `_nutriHomeHtml`): vale para TODOS os alunos, com ou sem plano ativo. Tem o controle de água
  (meta de 35 ml/kg do último peso, `API.nutri.metaAguaMl`; 2,5 L sem peso), atalhos (Meu plano, Receitas, Vídeos, Dicas), sugestões de
  receita pelo horário e a dica do dia. A água também continua DENTRO do plano alimentar: os dois botões gravam no mesmo registro do dia
  (`nutri_dia`); na tela inicial é sempre hoje (`nutriAgua(delta, true)`), no plano segue o dia escolhido (hoje ou ontem).
- **Navegação**: `_nutri.tela` = home | plano | receitas | receita | videos | dicas, trocada por `nutriTela(t, id)`. Entrar em Nutrição
  (`renderNutricaoAluno`) sempre cai na tela inicial. O "Voltar" das subtelas volta para a tela inicial (`_nutriHeaderSub`).
- **Conteúdo** vem de `app/painel/nutri-conteudo.json` (`categorias`, `receitas`, `dicas`, `videos`), buscado no site (no app nativo, em
  `https://intusfit.com.br/app/painel/`, então mudar o JSON vale sem rebuild) e guardado em `localStorage['intus-nutri-conteudo']` para
  funcionar offline. As fotos ficam em `app/painel/receitas/*.jpg` (fora de `app/img/`, que o git ignora).
- **Receitas**: 47 receitas do e-book "Nutri Rô: Receitas práticas" (café da manhã, lanche da tarde, café sem ovo, bebidas proteicas,
  saladas, molho, strogonoff e almoços e jantares), transcritas e com as fotos recortadas do PDF. Busca por nome ou ingrediente, categorias,
  favoritas (`intus-nutri-fav-<idatleta>`), ingredientes marcáveis, envio por WhatsApp e cópia. Campos de uma receita: `id`, `titulo`, `cat`,
  `tempo`, `porcoes`, `ingredientes[]`, `preparo[]`, `foto`, e opcionais `proteina`, `dica`, `descricao`, `dificuldade`, `nutricao{}`,
  `variacao{}`, `acompanhamentos[]`, `outras_opcoes[]`. O texto "Whey Nutrata Havanna" e "Rap10" vêm do material da nutri, sem mudança.
- **Vídeos**: lista `videos[]` com `id`, `titulo`, `url` (YouTube abre no player do app; outro link abre fora), `duracao`, `descricao`,
  `capa` opcional e `receita` (id de uma receita: aparece o botão "Ver o vídeo desta receita"). Hoje a lista está vazia: a tela mostra
  "Os vídeos estão chegando". Para publicar vídeos sem mexer em arquivo é preciso uma área de gestão no painel (ver pendências).
- **Ajuda** (`ajuda.js`): FAQ nova "Onde ficam as receitas, os vídeos e as dicas?" e textos de água atualizados.

## 31. Banco de alimentos e editor de plano (08/10/2026)

Defeitos de dados achados e corrigidos (todos confirmados no navegador):
- **Colisão de ids**: os "Meus alimentos" vêm do servidor com id 1, 2, 3... e a TACO usa 1..597. O 1º alimento cadastrado escondia o "Arroz
  integral cozido", e assim por diante, e os planos já montados recalculavam com o alimento errado. Agora o id de um "Meu alimento" no app é
  `AlimentosDB.CUSTOM_BASE (1.000.000) + id do servidor` (`dbid` guarda o do servidor). Plano antigo guardava o número cru:
  `AlimentosDB.resolver(item)` decide pelo NOME quando o número é ambíguo.
- **Medidas caseiras ligadas a alimentos errados**: 14 das 15 entradas de `medidas-caseiras.json` apontavam para outro alimento da TACO
  (as da "Banana prata" caíam em "Maracujá, suco concentrado"; a aveia herdava as do arroz). Esse arquivo deixou de ser fonte de medidas.
- **Unidade genérica de 100 g**: toda unidade valia 100 g (1 ovo = 143 kcal, o dobro do real). O catálogo novo é `app/data/unidades.json`:
  467 dos 597 alimentos da TACO com medidas caseiras de referência (ovo de galinha 1 unidade = 50 g, clara 33 g, gema 17 g, ovo de
  codorna 10 g, fatia de pão de forma 25 g...), gerado por script que CONFERE cada id pelo nome da TACO (não se repete o erro de ligação).
  Alimento sem medida cadastrada fica só em gramas, sem inventar. São valores de referência (média de tabelas brasileiras de medidas
  caseiras, parte comestível): a nutri pode ajustar a medida de um item e o plano guarda o que foi prescrito.

Como o item de plano é gravado agora (`itens_estruturados[]`): `food_id`, `nome`, `quantidade`, `medida_id`, `medida_nome`, `medida_g`
(gramas de 1 medida), `gramas`, `nutrientes` (20 nutrientes da porção), `substitutos[]` e, só para "Meus alimentos", `por100` (tabela de
100 g, porque outro profissional não enxerga o alimento). O item se recalcula sozinho ao mudar quantidade ou medida
(`_recalcItem` no `nutricao.html`, `AlimentosDB.escalar`). Plano antigo é "hidratado" ao abrir (`_hidratarItem`): acha o alimento, recupera
quanto valia 1 medida pelo que foi calculado na época e marca com ⚠ a medida antiga que não bate com o catálogo (ex.: unidade de ovo
com 100 g); a nutri decide se atualiza.

Substituições:
- **Substituto de alimento** (↔): item completo com quantidade, medida, kcal e macros. Ao escolher o substituto ele chega na quantidade
  EQUIVALENTE ao original, mantendo iguais calorias, proteína, carboidrato ou gordura (`API.nutri.gramasEquivalentes`), arredondada para
  meia unidade ou 5 g (`arredondarQtd`). Botão ⚖ refaz a conta; a diferença para o original aparece na linha. Substituto digitado à
  mão fica como texto, marcado "sem tabela nutricional".
- **Refeição substituta** (opção alternativa inteira): ganhou "Copiar da principal", "Igualar kcal" (multiplica todas as quantidades pelo
  mesmo fator) e a comparação de kcal e macros com a principal.
- O app do aluno mostra "2 unidades (100 g)" e os substitutos com quantidade (`API.nutri.rotuloQtd`).
- **Tabela nutricional** (ℹ): rótulo de 100 g e da porção para qualquer alimento da busca, item do plano ou substituto.
- **Cadastro rápido** (na busca) aceita fibra, sódio e uma medida caseira (ex.: 1 scoop = 30 g); sem kcal, calcula 4/4/9 kcal/g.

Testes: `testes/alimentos.js` (ids, resolução de plano antigo, medidas, catálogo conferido com a TACO; falha de verdade contra o código
antigo) e a seção 8 de `testes/nutri.js` (plural, rótulo de quantidade, equivalência, arredondamento).

- **Plano (painel, `nutricao.html`)**: botões "🖨 Imprimir / PDF" (`imprimirPlano`: abre uma versão limpa com quantidades em medida caseira, substitutos e totais e chama a impressão) e "⧉ Duplicar para outro aluno" (`duplicarPlano`: copia refeições, quantidades e substitutos; nasce INATIVA por padrão, para o aluno só ver depois de a nutri revisar e ativar).
- **Pendências da Nutrição** (precisam de decisão do Luiz): (1) gestão de receitas e vídeos pelo painel, que exige uma tabela nova (`intus_nutri_conteudo`) e um endpoint; (2) ampliar o banco de alimentos com IBGE/POF e USDA (download dos arquivos) ou licença da TBCA; (3) várias medidas caseiras por "Meu alimento" (coluna nova em `intus_alimento_personalizado`); (4) "Meus alimentos" hoje é por profissional, não compartilhado na equipe.

- **Voltar dentro da Nutrição (08/10/2026)**: cada passo para dentro (`nutriTela`) entra no histórico (`history.pushState`); o popstate central do `aluno.html` chama `_nutriVoltarGesto()` antes de desfazer a pilha de telas do app, então o botão/gesto de voltar do celular anda um passo na Nutrição (receita volta para a lista, lista volta para a tela inicial da Nutrição) e só da tela inicial da Nutrição sai para o início do app. O "Voltar" do cabeçalho (`nutriVoltar`) usa o mesmo histórico, para os dois caminhos andarem juntos.

- **Unidade como padrão e busca com porção (08/10/2026)**: na busca de alimentos cada resultado mostra kcal e P/C/G da PORÇÃO PADRÃO do alimento (ex.: ovo cru = 2 unidades, 100 g, 143 kcal), e não mais por 100 g (`_porcaoPadrao`, `_resultadoBuscaHtml`). Alimento com medida caseira entra no plano nela (ovo = 2 unidades) e sempre dá para trocar para gramas ou outra medida no seletor. Item que já estava em gramas mostra "≈ 2 unidades · usar unidades" (`itemUsarUnidade`), e o botão "⇄ Usar unidades onde der" (`converterTodosParaUnidade`) converte de uma vez os que fecham em meia unidade exata, sem mudar kcal. A opção "+ Definir outra medida..." do seletor cria uma medida própria do item (nome e gramas, id `pers_*`) para qualquer alimento, mesmo sem medida no catálogo.

## 32. Receitas, vídeos e dicas pelo painel; banco de alimentos ampliado (08/10/2026)

**Gestão de receitas, vídeos e dicas (autorizada pelo Luiz em 08/10/2026):**
- **Tabela nova `intus_nutri_conteudo`** (criada no bootstrap do `catalogo.php`; nenhum dado de aluno): `tipo` (receita | video | dica), `slug` (id público,
  único por tipo), `titulo`, `cat`, `ordem`, `ativo`, `foto`, `dados` (JSON com o resto). Rota `catalogo.php?action=nutri_conteudo`: GET (aluno só vê `ativo = 1`;
  equipe vê tudo), POST/PUT/DELETE só equipe, `foto_base64` vira arquivo em `/img/receitas`, e `POST {subacao:'importar'}` copia as 47 receitas e 8 dicas do
  `nutri-conteudo.json` (idempotente, por tipo + slug). Só entram campos conhecidos de cada tipo, com tamanho limitado (`_nutriLimparDados`).
- **Painel**: aba **Receitas e vídeos** em Nutrição (`nutricao.html`: `conteudoHtml`, `abrirFormConteudo`, `salvarConteudo`): formulários, foto com redução no navegador, ativar/inativar,
  ordem (▲▼), excluir e o botão de importar. A aba não depende da lista de planos.
- **App do aluno**: `_nutriConteudoCarregar` lê o arquivo e depois o servidor; para cada tipo, se o servidor tem algum item ativo, a lista do servidor SUBSTITUI a do arquivo
  (receitas importadas e editadas no painel passam a valer; sem nada no banco, vale o arquivo). Atualiza no máximo a cada minuto.
- **Teste em PHP de verdade**: `testes/conteudo.php` (20 conferências, SQLite, extrai o bloco do endpoint do `catalogo.php`). PHP deste computador: `php.exe` do winget
  (`...\WinGet\Packages\PHP.PHP.8.2_...\php.exe`), com `php.ini` que ligue `mbstring, openssl, pdo_sqlite, sqlite3` (`php -c <ini> testes/conteudo.php`). Já registrado em `rodar.sh` (só roda se houver php com as extensões).
  O arquivo deve ser deployado sem o botão de importar ter sido usado: a tabela só nasce na 1ª chamada autenticada depois do deploy.

**Banco de alimentos ampliado** (`app/data/alimentos-extra.json`, 1.143 alimentos, 620 KB, carregado por `AlimentosDB` depois da TACO):
- **IBGE, POF 2008-2009, Tabelas de Composição Nutricional dos Alimentos Consumidos no Brasil (2011)**, 1.020 itens. As 4 tabelas do PDF (macros e fibra, gorduras e açúcar, minerais,
  vitaminas) foram lidas por script e juntadas por alimento e preparo; o que a TACO já cobre (nome contido no nome da TACO) ficou de fora e preparos com macros iguais viraram um item só.
  Limite conhecido do IBGE: vários preparos repetem os mesmos valores do alimento cru, e a fonte mistura TACO, NDSR (EUA) e rótulos. Valor aproximado, não pesagem.
- **USDA FoodData Central, SR Legacy (2018)**, 126 itens escolhidos a dedo (quinoa, tilápia, cottage, tahine, whey, chia, kefir...), nome em português escrito por nós, com as medidas
  caseiras do USDA (xícara, colher, filé, fatia...). A página de download do USDA não traz o texto da licença; os dados do USDA são do governo dos EUA, mas **confirme antes de qualquer uso comercial fora do app**.
- **TBCA (USP)** NÃO foi usada: a consulta é aberta, mas usar a base dentro de outra ferramenta exige licença paga (tbca.contato@usp.br).
- **Ids**: de 100.000 em diante, entre a TACO (1..597) e "Meus alimentos" (1.000.000+). **Nunca reordenar nem reaproveitar** (planos guardam o id); alimento novo entra no fim. Na busca a TACO vem
  antes quando os dois têm o alimento; cada resultado mostra a etiqueta IBGE ou USDA. Teste: seção 6 de `testes/alimentos.js` (inclui a conferência calorias x macros, que acusaria colunas deslocadas na leitura do PDF).
- **Como regenerar**: os scripts ficaram na pasta temporária da sessão (não versionados). Em resumo: `pymupdf` lê as páginas 36-96, 97-157, 158-218 e 219-279 do PDF (linhas de 7 dígitos de código + preparo + valores);
  o USDA vem de `food.csv`, `food_nutrient.csv` e `food_portion.csv` do zip `FoodData_Central_sr_legacy_food_csv_2018-04.zip`. Alimentos que a TACO já cobre bem foram retirados da lista do USDA para não duplicar.

## 33. Alimentos da equipe, cadastro durante a prescrição e várias medidas (08/10/2026)

- **"Meus alimentos" virou "Alimentos da equipe"** (autorizado pelo Luiz): `catalogo.php?action=meus_alimentos` devolve o catálogo de TODOS os profissionais (campo `meu` diz quem
  criou); só quem criou ou o admin edita e apaga. Os alimentos antigos de cada profissional passaram a valer para a equipe sem mudança nos dados. Na busca a etiqueta é MEU ou EQUIPE.
  Plano antigo não muda: o item já guarda a tabela de 100 g do alimento personalizado (`por100`).
- **Tabela nova `intus_alimento_medida`** (`food_id`, `nome`, `gramas`, `idprofessor`): medidas caseiras que a equipe cadastra para QUALQUER alimento (TACO, extras ou da equipe; `food_id` é o número do
  alimento no app). Rota `catalogo.php?action=alimento_medidas` (só equipe; criar atualiza o peso se o nome já existe; apagar só quem criou ou o admin). Apagar um alimento da equipe leva as medidas dele.
  **Medida da equipe com o mesmo nome de uma do catálogo SUBSTITUI o peso dela** (ex.: "Unidade" do ovo de 50 g para 55 g), e a sugestão padrão do alimento acompanha (`getUnidadePadrao`).
  Id no app: `eq_<idmedida>`. Um alimento pode ter quantas medidas quiser; a coluna antiga `medida_padrao`/`porcao_padrao` de `intus_alimento_personalizado` ficou só como legado (novos ficam em gramas).
- **Cadastrar alimento durante a prescrição**: botão visível "➕ Novo alimento" ao lado de TODA busca do editor (refeição principal, opção substituta e substituto de um alimento). O formulário abre no dropdown
  (kcal/macros por 100 g, fibra, sódio e quantas medidas quiser), grava para a equipe e já inclui o alimento. `_iniciarCadastroRapido(refIdx, substIdx, itemIdx)`.
- **Várias medidas ao prescrever**: o seletor de medida do item lista todas as do alimento (catálogo + equipe) e "+ Definir outra medida...", que agora pergunta se a medida fica guardada para a equipe ou vale só
  para o item (`_perguntarMedida`). Janela "📏 Medidas caseiras" (`abrirMedidasAlimento`) pelo ℹ de qualquer alimento e na lista de Alimentos da equipe; o formulário do alimento tem as medidas em linhas.
- **O aluno vê**: o item gravado leva `medidas` (lista `{n, g}` do alimento na hora da prescrição) e o app mostra a quantidade prescrita e, embaixo, as equivalentes ("≈ 100 g · 7 colheres de sopa · 0,5 xícara de chá")
  (`_nutriEquiv`, arredondando como medida de cozinha). Plano antigo sem `medidas` continua como era.
- **Testes**: seção 7 de `testes/alimentos.js` e `testes/equipe.php` (21 conferências em PHP real: compartilhamento entre profissionais, quem edita e apaga, validações das medidas). Quebrar a permissão de edição de propósito faz o teste acusar.

## 34. Dashboard, links diretos, dicas nos gráficos e Frequência (09/10/2026)

- **Links diretos (nome clicável leva ao registro exato)**: `mensalidades.html?m=<idmensalidade>` limpa os filtros, busca pelo aluno, abre os ciclos da matrícula, rola até a linha e a destaca (`aplicarFoco`, faixa
  "Mostrando a matrícula de X · Ver todas as matrículas"); `treinos.html?ficha=<idficha>` abre a ficha no editor. No dashboard (`index.html`) o nome (com foto) de cada linha das quatro listas de atenção, o "Revisar"
  e a tarefa automática "Trocar treino de X" do checklist usam esses links. Nos cartões de insight de Treinos as linhas também abrem a ficha. Link que não existe mais avisa por toast e não quebra a tela.
- **Dashboard**: os cartões duplicados (KPIs) saíram; os 11 números da visão geral são links (cadastros, alunos, ativos e leads vão para Clientes; treinos ativos para Treinos; mensagens para Mensagens; vencidas e a vencer
  descem até a lista certa, com cor quando maior que zero). As listas de atenção vêm logo depois dos números, em duas colunas, em linhas de grade (foto, nome, plano ou ficha, data, selo, valor): nada quebra em duas linhas
  e no celular o valor desce para baixo do selo. Crescimento com botões 6, 12 e 24 meses (guardado em `localStorage['intus-viz-meses']`), gráficos sem distorção (o SVG mantém a proporção) e nota dizendo quantos
  cadastros ficam fora da curva por não terem nenhuma data. "Alunos ativos" do gráfico virou "Alunos com plano pago" (conta quem teve ciclo pago cobrindo o mês); o cartão "Ativos" do topo é a situação de HOJE
  (`API.situacaoPlano`), por isso os dois podem diferir e a dica do gráfico explica isso.
- **`Tip` (`_mock.js`)**: dica flutuante compartilhada. Qualquer elemento com `data-tip="<html>"` (use `Tip.attr(html)`, que escapa) mostra a dica ao passar o mouse ou tocar, sem ligar evento nenhum. `Tip.historico({titulo,
  rotulos[], valores[], i, sufixo, fmt, anteriores})` monta o miolo padrão: o mês sob o mouse, a variação contra o anterior e os meses anteriores com barrinha. Usada em: Crescimento do dashboard (guia vertical e ponto
  acendem por CSS, `.viz-hit:hover`), Gestão (`_tipMes`, substituiu o tooltip próprio), Financeiro (receita mensal agora de 12 meses, `Tip` por barra, valores mascarados com "Esconder valores"), cartões de
  Matrículas Ativas e Recebido (série de 12 meses calculada na própria tela), "Últimos prescritos" em Treinos (fichas por mês) e cartão de sessões da Frequência. O `IntusEvolucao` (`api.js`) já tinha cruzamento e
  dica próprios e ficou como está. Teste: `testes/dashboard.js` (Tip.historico e `calcCrescimento`, com quebra de propósito acusada).
- **Frequência e Ranking, visão Calendário (`frequencia.html`)**: resumo do mês (sessões com comparação contra o mesmo trecho do mês anterior, alunos que treinaram, dias com treino, dia mais forte) no lugar do
  resumo do período, calendário com células baixas pintadas pela intensidade (4 níveis, relativos ao dia mais cheio do mês), dica com quem treinou no dia, e uma coluna ao lado com quem treinou (barra, sessões, último
  treino) e quem não treinou (recolhido quando passa de 12). Calendário e detalhe do dia contam as mesmas sessões (`_sessoesValidas`: fora as marcadas "não contar" e as excluídas na tela). Também foi corrigido o
  texto branco sobre o verde-limão nos botões ativos (ilegível no tema escuro).
- **`--brand` nunca tinha sido declarada**: dezenas de telas usam `var(--brand)` (avatar, links, bordas). Sem valor, o avatar ficava transparente com a inicial preta sobre fundo escuro. `_mock.js` agora declara
  `--brand` igual à cor de destaque do tema (nos dois temas). O app do aluno (`aluno.html`) continua na versão antiga do `_mock.js`, sem mudança.

## 35. Importar plano alimentar e observações por refeição (09/10/2026)

- **Observação da refeição e da substituição**: `refeicoes[i].nota` e `refeicoes[i].refeicoes_substitutas[j].nota` (texto livre, dentro do JSON do plano, sem coluna nova). Editor da nutri: caixa de texto em cada
  refeição e em cada substituição; aparece no modal do plano, na impressão e no app do aluno (visão Hoje, embaixo dos alimentos da opção escolhida). Serve para receita, "fruta é sugestão" etc.
- **Total do dia no modal do plano** (`verPlano`): soma das refeições principais (kcal, proteína, carboidrato, gordura e barra de distribuição) aparece sempre, com ou sem metas; as metas, quando existem, vêm
  logo abaixo com o título "Metas do plano".
- **Importar plano** (`nutricao.html`, aba Planos, botão "⬆ Importar plano"): lê um `.json` no formato `intus-plano-v1` (`aluno_nome`, `titulo`, `objetivo`, `dtinicio`, `refeicoes[]` já no formato salvo, com
  `itens_estruturados`, `substitutos` por alimento e `refeicoes_substitutas`), sugere a aluna pelo nome, mostra o resumo (refeições, substituições, total do dia, itens só com calorias) e só grava ao clicar em
  Importar. Nasce INATIVO por padrão e avisa se a aluna já tem plano ativo. O arquivo é escolhido do computador: **plano de aluna não fica em pasta pública do site** (a pasta local `importar-local/` está no `.gitignore`).
- **Como o PDF vira o formato**: alimento da TACO/IBGE/USDA entra ligado ao catálogo (nome do catálogo, macros e medidas reais); alimento de marca ou prato (Oakberry, sanduíches, iogurte grego Vigor) entra
  como item próprio só com as calorias do PDF, ou com a tabela de um alimento parecido quando a caloria de 100 g fica a menos de 12% dela; "OU" vira `substitutos` do alimento (com a quantidade do PDF) e, sem
  quantidade ("OU outro pão disponível"), vira substituto manual só de texto; "Substituição N" vira `refeicoes_substitutas`. Medida caseira que o PDF só dá pelo nome (copo americano, colher de sopa) usa o peso
  que reproduz as calorias do PDF.
- **Cores dos macros (09/10/2026, `nutricao.html`)**: paleta única `--mc-kcal/prot/carb/gord` (e `-bg`) em todo o CSS, com tom vivo no tema escuro e tom escuro no claro (contraste medido: mínimo 5,7:1 nos dois),
  valores em negrito (800) e rótulo em letra ao lado. Cada refeição no modal do plano mostra os totais (kcal, P, C, G) logo abaixo do nome; o total da refeição no editor também fica sempre visível no cabeçalho.
  A barra de distribuição de macros estava com altura zero (encolhida pelo flex do modal) e passou a aparecer. **Cor nova de macro: usar as variáveis, nunca hex.**

## 36. Nutrição: fotos das refeições, visualização e editor do plano (09/10/2026)

- **Aba "📷 Fotos das refeições"** (`nutricao.html`, `fotosHtml`): galeria das fotos que os alunos mandam ao marcar a refeição (últimos 14 dias, da carteira da nutri, vindas de `nutri_painel`), agrupadas por dia, com
  filtros (aluno, feito/parcial/fora, só sem resposta), contador de "aguardando resposta" na aba e no cartão, visualização grande com anterior/próxima (`abrirFoto`), resposta com atalhos prontos e remoção da foto.
  O Dashboard ganhou a faixa "Fotos recentes dos alunos". Reaproveita `API.nutriResponder` e a remoção que já existiam.
- **Lista de planos**: cartões em grade com foto/inicial da aluna, título, objetivo, período, totais calculados do plano (kcal e macros), quantidade de refeições e substituições e a linha do dia (horários).
- **Visualização do plano** (`verPlano`): cabeçalho com a aluna, total do dia em quatro cartões com barra e % da meta quando há meta, barra de distribuição, atalhos por refeição (rolam só o corpo do modal) e
  "Recolher/Expandir tudo"; cada refeição é um bloco recolhível com horário, nome e totais, as substituições ficam em "↔ N opções de substituição" (recolhidas) e os micronutrientes em seção recolhida.
- **Editor** (`abrirModalForm`): cabeçalho fixo com as abas numeradas (1 Informações, 2 Refeições com contador, 3 Metas e cálculos) e a barra "Total do dia" sempre à vista (kcal, P, C, G, % da meta, distribuição),
  abre direto em Refeições quando o plano já tem refeições, atalhos por refeição, Recolher/Expandir tudo, tela inicial convidativa para plano vazio, legenda explicando a diferença entre trocar um alimento (↔ na
  linha) e trocar a refeição inteira, painel completo de totais recolhível, **aviso de alterações não salvas** (X, Cancelar, clique fora; `closeModal` é envolvido neste arquivo) e Ctrl+S para salvar.

## 37. Importar plano direto do PDF, modos de visualização e busca digitável (09/10/2026)

- **Importador em PDF** (`nutricao.html` > Planos > ⬆ Importar plano): aceita o PDF "Plano Alimentar" exportado do sistema de dieta da nutri (ou o `.json` `intus-plano-v1`). Tudo no navegador: `vendor/pdfjs/`
  (pdf.js 3.11.174, Mozilla, Apache 2.0, de cdnjs; só é carregado quando se escolhe um PDF) lê os trechos de texto com posição; `importar-pdf.js` (`ImportarPDF.interpretar` e `montarPlano`) reconhece
  cabeçalho, refeições (`HH:MM - Nome`), alimentos (`Nome (Medida: qtd)` + `kcal`), "OU", "Substituição N" e observações (texto à esquerda dos alimentos, x≈60 contra x≈72). O PDF não sai do computador.
  Layout lido: nome a x≈72, Kcal a x≈345, Grupo a x≈436 (as colunas são lidas do próprio arquivo). Se o sistema de dieta mudar o layout, o importador avisa que não reconheceu refeições.
- **Como cada alimento entra**: casado com a base do Intus por nome (palavras, sem acento, com sinônimos como "arroz branco" = "arroz tipo 1") e conferido pelas calorias do PDF (aceita até 50% de diferença quando o
  nome bate por inteiro, 25% quando bate em parte). Medida caseira que o catálogo não tem usa o peso que reproduz as calorias do PDF (só quando o alimento é único; senão não chuta). Sem alimento parecido, entra
  só com as calorias do PDF ("só calorias"); troca "OU" sem quantidade ou sem alimento conhecido vira texto. "Peito de galinha OU frango" no meio do nome é um alimento só. O resumo traz uma **conferência
  alimento por alimento** (kcal do PDF, kcal do Intus, como entrou). O plano nasce inativo. `AlimentosDB.todos()` foi criado para isso. Teste: `testes/pdf_plano.js` (33 conferências).
- **Componentes compartilhados em `_mock.js`**: `VerModo` (seletor Cartões / Compacto / Miniaturas / Lista, lembrado por tela em `localStorage['intus-vista-<tela>']`, grade `.vm-grid.m-*`) e `ComboBusca` (lista suspensa em
  que se DIGITA para filtrar, sem acento e em qualquer parte do nome, com setas, Enter, Esc e botão ×; mantém um `<input type=hidden id=...>` com o valor, então o código que lia o `<select>` continua valendo).
  Qualquer tela adota assim: `VerModo.barra(...)` + um renderizador por modo, e `ComboBusca.html({...})` no lugar do `<select>`.
- **Onde já está**: Nutrição > Planos (padrão Compacto, 4 por linha em tela larga; Miniaturas 8; Lista com totais), Nutrição > Fotos (Grandes/Pequenas), Treinos > fichas (padrão Cartões, como era), e os campos de aluno
  (filtro de planos, filtro de fotos, aluno do plano, aluna do importador) agora aceitam digitar. **Próximos candidatos**: Matrículas, Clientes (`alunos.html`), Avaliações, Mensagens, Usuários.

## 38. App do aluno: Nutrição na tela inicial, logo na figurinha e ranking mais compacto (09/10/2026)

- **Nutrição na Home** (`aluno.html`, `renderHome`): a faixa larga de Nutrição foi tirada (decisão do Luiz, 09/10/2026). Os atalhos ao lado do Ranking ficaram: Nutrição, Evoluções, Conquistas e "Ver mais" (subtítulo
  "Avaliações, Central, Perfil", que são o que está dentro dele). Avaliações foi para o "Ver mais".
- **Logo da Intus dentro da figurinha** (`_shareBarraInferior`): selo pequeno à direita do nome do treino, dentro da caixa que se move; vale nos layouts que usam a barra (Clássico, Destaque, Mascote), na prévia e na
  foto/vídeo final (a camada vem do mesmo HTML). A logo fixa do canto continua como estava.
- **Ranking**: cartão "Sua posição" com o título na mesma linha da posição, comparação com a semana (ou mês) passada em UMA linha (fonte `clamp`) e folga vertical menor. `telaHeader` (Voltar / título / logo) ficou
  mais baixo em todas as telas que o usam (ranking, medalhas, evoluções, conquistas...): menos espaço em branco antes do conteúdo.
- **Print de tela não leva figurinha (09/10/2026)**: o caso visto no Feed foi o print da tela de "Conquista desbloqueada" (com o botão Continuar aparecendo) escolhido na galeria dentro de "Compartilhar treino", que liga
  a figurinha e a legenda "Treino X concluído" por padrão. Agora, ao abrir uma foto no editor (`_abrirEditorFotoFeed`), imagem com proporção de celular (2:1 ou mais alta, `_ehCapturaDeTela`) desliga as informações do
  treino, esconde a opção e limpa a legenda automática, com um aviso na tela; câmera e fotos comuns não são afetadas. O compartilhamento da própria conquista (`_cqShareSheet`) nunca teve figurinha; o popup de
  conquista ganhou o convite "em vez de tirar print, compartilhe por aqui".
- **"Plano" da nutrição sempre como "plano alimentar" para o aluno (09/10/2026)**: para não confundir com o plano da matrícula, todo texto do app e da central de ajuda que fala do plano da Nutrição diz "plano alimentar" ("Meu plano alimentar",
  "Sem plano alimentar ativo", "Fora do plano alimentar", "Soma do plano alimentar por 7 dias", FAQ e tour). "Plano" sozinho continua só para a matrícula ("Seu plano terminou", "Plano Ativo" na Minha Matrícula). Texto novo de
  Nutrição para o aluno deve seguir a mesma regra.

## 39. Conquistas: evidências por semana/mês e fim das comemorações fora de hora (10/10/2026)
- **Evidência**: conquista de semanas ou períodos (Top 5, Tricampeão e afins, Top 10 do mês, semanas seguidas, semana cheia/perfeita, equilíbrio) mostra QUAIS semanas/meses e a colocação (`_conqEvidencia(def, alvo)`:
  `{titulo, itens:[{t,v}]}`), no cartão compartilhável (`_conqCartaoDataUrl`, até 5 caixas + "+N"), na legenda do post, no popup de comemoração e no detalhe da conquista própria. Mesmas regras de
  `_weeklyTop5Flags` e `_perfilRankingStats`; Top 5 e Top 10 usam a primeira janela que bateu a meta. `_conqSessoesAtleta` é o merge servidor+local usado por `_conquistaMetrics` e pelas evidências.
- **Bug das comemorações antigas** (pódio de semanas atrás, 50 h, Maratonista chegando juntos numa sexta à noite): `_ensureRankingData` guardava falha de rede como "ranking vazio de verdade" e `_ensureConquistasConfig`
  guardava `{}`; o baseline (`intus-conq-seen-<id>`) era gravado com poucas conquistas e, na abertura seguinte, tudo o que o aluno já tinha virava "novo" (comemoração + aviso no sino). Além disso, ao aparecer uma
  conquista nova o "já visto" era SUBSTITUÍDO pelo conjunto atual (encolhia se algo sumia por um instante). Agora: falha fica marcada (`_falhou`) e é tentada de novo; `_conqDadosCompletos()` barra baseline e
  detecção sem ranking e configuração inteiros; o "já visto" só cresce (`seen.concat(novos)`). Aviso já criado no servidor não é desfeito por script (exclusão só pelo painel).

## 40. Tela sempre ligada no treino e no cardio ao vivo (10/10/2026)
- Antes o pedido de Wake Lock era feito UMA vez ao iniciar o treino; se o sistema recusasse (economia de bateria) ou soltasse (ligação, troca de app), a tela voltava a apagar sozinha. Agora `requestWakeLock()`
  é idempotente (sem pedidos duplicados que deixavam a trava presa), só age com treino ou cardio rodando (`_wakePrecisa()`), renova quando o sistema solta (`release`), ao voltar ao app (`visibilitychange`, `focus`,
  `pageshow`), ao tocar na tela e a cada 10 s. Sem o recurso (iOS/WebView antigos) ou após 2 recusas, usa um vídeo mudo em loop gerado na hora pelo canvas (`_wakeVideoLigar`, truque do NoSleep.js).
  Só o botão de bloqueio do celular apaga a tela. O app das lojas leva `aluno.html` empacotado: vale após novo envio; plugin nativo de keep-awake do Capacitor seria a camada extra se algum aparelho ainda falhar.

## 41. Compartilhar conquista: legenda visível e editável (12/10/2026)
- A folha "Compartilhar conquista" (`_cqShareSheet`) ganhou o campo "Legenda do post" (até 1000 caracteres, igual ao limite do servidor), já preenchido com `_cqLegendaPadrao(def)` (nome, descrição e as semanas/colocações da
  seção 39). O texto digitado fica em `_cqShare.legenda` (sobrevive à troca de "Quem pode ver"), vai no post do Feed/perfil (`_cqPostar`) e como `text` no compartilhamento do aparelho (WhatsApp/Instagram).

## 42. Cartão da conquista: emoji centralizado pelos pixels (12/10/2026)
- No iPhone o emoji do cartão (canvas) saía deslocado para a direita e para baixo da medalha, porque cada aparelho mede o emoji de um jeito. `_cqEmojiCentrado` desenha o emoji numa tela à parte, mede os pixels
  visíveis e o posiciona pelo centro real (erro de 0,5 px para qualquer emoji testado). Cartões já publicados continuam com a imagem antiga.

## 43. Conquistas: total de distintivos em destaque (12/10/2026)
- No placar de "Minhas Conquistas" (`_conquistasGridHtml`) o total virou um bloco próprio, no topo: número grande de desbloqueados, "de N distintivos desbloqueados", barra de progresso e "X ainda para conquistar" (antes era um "18/34" pequeno no canto).

## 44. Nutrição: histórico de água (12/10/2026)
- O cartão "Água de hoje" ganhou o atalho "📅 Histórico ›" no canto (posição absoluta, a tela não cresce). Ele abre a tela `nutriTela('agua')` (`_nutriAguaHistHtml`): períodos de 7/30/90 dias, média por dia registrado,
  dias com a meta batida, melhor dia, gráfico de barras com a linha da meta e a lista dia a dia (barra + litros, "sem registro" nos dias vazios). Dados de `intus_nutri_dia` (busca 90 dias ao abrir; `nutri_registro` já devolve
  `agua`). Somente leitura; corrigir um dia continua só para hoje e ontem.
- **Direção futura (pedido do Luiz)**: um "Diário do dia" que reúna treino, cardio, refeições e água de uma data escolhida, mais uma visão macro (semanas/meses). Os dias já usam o formato AAAA-MM-DD e
  `_nutriAguaDoDia(data)`, `_nutriRegsDoDia(data)` e as sessões do ranking têm essa chave, então a tela de data pode reaproveitá-los em vez de nova fonte de dados.

## 45. Anamnese: "Respondida" só com as perguntas da anamnese (13/10/2026)
- No app do aluno, o item "Anamnese" da tela Avaliações usava `localStorage['intus-anamnese-<id>']` não vazio como "respondida", mas essa mesma cópia também guarda as seções comportamental, nutricional e rotina
  (`_salvarSecaoAnamnese`). Quem respondeu só as seções via "Anamnese: Respondida" sem nunca ter preenchido as 16 perguntas, e o painel (que mostra o que está no servidor) exibia só Comportamental e Nutricional (caso da Ana Luisa
  Mansour, 13/10). Agora `_anamnesePrincipalRespondida()` exige resposta de campo simples (ignora seções e chaves `_`). O painel (`avaliacoes.html`, aba Anamnese) avisa explicitamente quando só chegaram seções:
  "A anamnese principal ainda não foi respondida".
- Se a aluna jura que respondeu a principal, a causa possível é o defeito antigo (servidor substituindo a anamnese a cada seção, corrigido em 18/08): o app recupera sozinho a cópia local com `_somente_faltantes`.

## 46. Feed: responder comentário pela notificação (13/10/2026)
- Sintoma: abrir o post pela notificação, tocar em Responder, escrever e enviar não fazia nada. Causa: a tela do post (`abrirPostTela`, `#pk-post-ov`) fica por cima do Feed, e o mesmo post existe nos dois lugares; o envio
  usava `document.querySelector` e pegava o campo do cartão do Feed escondido (vazio), então saía em silêncio ("texto vazio"). `_campoComentarioFeed(idpost)` agora escolhe o campo em uso (focado, com texto, ou o da tela
  de cima), e o rascunho, o foco do Responder e a limpeza pós-envio usam o mesmo critério. Envio com campo vazio agora avisa por toast em vez de não fazer nada. Qualquer código novo que procure o campo de comentário por
  `data-feed-card` deve passar por essa função (o mural usa outro bloco, `_mural*`, sem esse problema).

## 47. Marcador de água na tela inicial (13/10/2026)
- Opcional, ligado pelo aluno em Nutrição > cartão de água ("🏠 Início"; preferência por aparelho em `localStorage['intus-home-agua-<id>']`). Com ele ligado a Home mostra o cartão `#h-agua-card` logo abaixo de
  "Sua semana": litros do dia, meta, barra, "−" e "+ copo" (250 ml, 1 toque, `homeAguaBeber` -> `nutriAgua` -> `_nutriSalvar`, com fila offline). A Home não carrega a Nutrição: `_homeAguaCarregar` busca só a água
  de hoje/ontem depois de pintar (no máximo a cada 60 s; `renderHome` roda várias vezes) e `_homeAguaRepintar` troca só o cartão. O último peso para a meta vem de `intus-nutri-peso-<id>` quando as avaliações não estão carregadas.
- Parte do plano de 13/10/2026 (posts colaborativos, Nutrição geral x plano, receitas x2, mural): este foi o passo 1.

## 48. Nutrição: acesso geral x plano alimentar ativo (13/10/2026)
- **Regra** (`aluno.html`, bloco "ACESSO: GERAL x PLANO ALIMENTAR ATIVO", todas as decisões em `_nutriTemPlano()`, `_nutriReceitaLiberada`, `_nutriVideoLiberado`, `_nutriDicaLiberada`): sem plano ativo o aluno tem a água do dia, **1 receita por
  categoria por dia**, **1 vídeo geral por dia** e **1 dica por dia**, por rodízio do dia (`_nutriDiaN` e `_nutriHash`, igual em todo aparelho, nada guardado no servidor). Com plano ativo, tudo. Itens fechados aparecem com 🔒 e
  levam ao convite (`nutriConvitePlano`); os do dia levam a etiqueta "🎁 grátis hoje". Receita fechada mostra título, foto desfocada e o convite (sem ingredientes e preparo). Histórico de água: 7 dias sem plano, 30/90 só com plano.
  Favoritos só com plano (o mecanismo visível e a avaliação vêm no passo 3). Plano ativo fica em `localStorage['intus-nutri-plano-<id>']` para funcionar offline.
- **Vídeo geral ou só plano**: o painel (Nutrição > Receitas e vídeos > vídeo) escolhe `visibilidade` (`geral` | `plano`, guardada em `dados`, sem coluna nova; `_payloadConteudo` inclui a chave para Ativar/Inativar/reordenar não
  apagarem). **No servidor** (`catalogo.php`, GET `nutri_conteudo`) o aluno sem plano ativo recebe o vídeo "plano" **sem `url` e sem `capa`** e com `bloqueado: 1`; a resposta do aluno traz `plano_ativo`. Receitas e dicas seguem
  abertas no JSON público (`nutri-conteudo.json`): o bloqueio delas é de experiência no app, não segredo. Se quiser blindar, mover para o banco só e servir recortado.
- Painel: botão "⬇ Importar receitas novas do arquivo" sempre disponível quando já há receitas no banco (idempotente por tipo e slug).
- Testes: `testes/conteudo.php` (26 conferências, inclui vídeo geral x plano; a quebra do `unset` é acusada). PHP deste computador: `php.exe` do winget com `php.ini` cujo `extension_dir` seja caminho de Windows (`cygpath -w`).

## 49. Receitas: favoritar visível, avaliação e "eu fiz" (13/10/2026)
- **Tabela nova `intus_nutri_avaliacao`** (`idconteudo`, `idatleta`, `nota` 1-5, `fez`, `fav`; única por conteúdo e aluno; criada no bootstrap do `catalogo.php`; aditiva) e rota `action=nutri_avaliacao`: GET devolve `stats` (média, total de notas e quantos
  fizeram, por item), `minhas` (as marcações de quem pede) e, com `?idconteudo=N`, os nomes de quem fez (só alunos com `feed_optin = 'S'`, não bloqueados e nunca quem o aluno ocultou em `intus_feed_pref`); POST `{idconteudo, nota?, fez?, fav?}` é só do
  aluno COM plano alimentar ativo (403 `recurso do plano alimentar`), só em receita ou vídeo ativo. Tirar nota, "fiz" e favorito apaga a linha. Excluir o conteúdo no painel apaga as avaliações dele.
- **App** (`aluno.html`, bloco "FAVORITO, NOTA E EU FIZ"): na receita, dois botões (⭐ Favoritar e 🍳 Eu fiz esta receita), cinco estrelas (tocar na mesma estrela tira a nota), média, total e "Quem fez: Ana, Bia e mais N". No cartão da grade há ☆/★ clicável
  (só plano) e a linha "★ 4,3 (3) · ✅ 2 fizeram". Sem plano, a média e o total aparecem só para leitura e os botões levam ao convite. A marcação aparece na hora e volta atrás se o servidor recusar. Os favoritos antigos do aparelho
  (`intus-nutri-fav-<id>`) sobem uma vez para o servidor (`_nutriAvMigrar`). **Receita só do arquivo (ainda sem `idconteudo`) não tem avaliação: use "Importar receitas e dicas do e-book" no painel para levar as receitas ao banco.**
- Testes: `testes/avaliacao.php` (16 conferências em PHP real; registrado em `rodar.sh`; tirar a trava do plano é acusado).

## 50. Mural de dicas e de vídeos da Nutrição (13/10/2026)
- Reaproveita `catalogo.php?action=comentarios_pub` (respostas em qualquer nível, curtida de comentário via `reacoes` `feed_comentario`) com `alvo_tipo` **`nutri_dica`** (um mural só de dicas, `alvo_id` = 1) e **`nutri_video`** (`alvo_id` = `idconteudo` do vídeo ativo). **Todos leem; escrever é só de aluno com
  plano alimentar ativo (403 `mural do plano alimentar`) e da equipe**, validado no servidor, que também confere o alvo (mural de dicas só com id 1; vídeo precisa existir e estar ativo). Avisos: resposta avisa o autor do comentário original
  (tipo `resposta`, `alvo_tipo` nutri_*); o que o aluno escreve avisa a equipe (professores responsáveis + administradores, `_resultadoDestinatariosProf`). Excluir um vídeo no painel apaga o mural dele.
- **App** (`aluno.html`, bloco "MURAL DA NUTRIÇÃO", funções `_nm*`, estado `_nm`): na tela Dicas o mural fica abaixo da lista ("Tem uma dica ou uma dúvida? Escreva aqui"); a lista de vídeos agora abre a **tela do vídeo** (`nutriTela('video', id)`:
  capa com play, descrição, receita relacionada e o mural dele; sem `idconteudo` mostra aviso). Sem plano aparece a faixa "escrever é do plano alimentar". O sino do aluno diz "respondeu seu comentário no mural de dicas/do vídeo" e leva à tela certa.
- **Painel** (`nutricao.html`, Receitas e vídeos > 💬 Mural, `muralHtml`; abre direto por `nutricao.html#mural`): lista as conversas do mural de dicas e de cada vídeo com comentário, a equipe responde (selo "equipe" no app) e exclui. O sino da equipe
  (`_mock.js`) mostra "escreveu no mural de dicas/de um vídeo" com link para lá. **`_mock.js?v=20261013a` em todas as 19 telas.**
- Testes: `testes/mural_nutri.php` (16 conferências em PHP real, registrado em `rodar.sh`; tirar a trava do plano é acusado). Os testes `avaliacao.php` e `mural_nutri.php` precisam ser salvos com fim de linha LF (gravar com Python em modo texto no Windows
  os transforma em CRLF e quebra a extração do bloco).

## 51. Posts colaborativos: servidor (13/10/2026)
- **Tabela nova `intus_post_colab`** (`idpost`, `idatleta_convidado`, `status` pendente|aceita|recusada, `criado_em`, `respondido_em`; única por post e convidado; aditiva, criada no bootstrap do `catalogo.php`).
- `feed_posts` POST aceita `colaboradores: [ids]`: só **amigos aceitos do autor**, até 4 (`_colabConvidar`); o aviso `colab_convite` sai quando o post passa a valer (publicação direta ou no `finalizar` do carrossel/vídeo, `_colabNotificar`, sem repetir).
  `action=post_colab` (só aluno): GET = convites pendentes que recebi (com miniatura), PUT `{idpost, aceitar}` (aceitar avisa o autor com `colab_aceito`; recusar fica gravado e não avisa), DELETE `?idpost=N[&idatleta=M]` (o autor tira um colaborador ou o colaborador sai).
- **Regra**: o post só é colaborativo para o público depois do ACEITE. `feed_posts` GET devolve `colaboradores: [{id, nome}]` (aceitos) e, só ao autor, `colab_pendentes`. No perfil do colaborador (`?idatleta=`) entram também os posts em que ele
  é colaborador aceito, se o autor estiver visível para quem pede; a privacidade do post (`intus_post_visib`) é a do autor e vale para os dois perfis; o colaborador sempre vê o post. No Feed geral o post aparece uma vez só.
  Excluir o post apaga as colaborações.
- `treinos.php?action=ranking` devolve `colab` por aluno (posts no Feed com colaboração aceita, contados para o autor e para cada colaborador aceito) para o distintivo "Dupla Dinâmica".
- Testes: `testes/colab.php` (29 conferências em PHP real; quebrar a regra "só amigos" ou "só aceitos" é acusado). O app (compositor, cartões, pedidos, distintivo) vem no passo seguinte.

## 52. Posts colaborativos: app e distintivo (13/10/2026)
- **Compositor** (`aluno.html`, bloco "POST COLABORATIVO"): botão "👥 Convidar colaboradores (quem treinou com você)" entre a localização e o destino, só com as conexões ligadas (`_conexoesOn`). A folha lista os amigos (parceiros de treino primeiro, `_amzDados.amigos`
  e `_pcDados.parceiros`), até 4; os ids vão em `colaboradores` no POST de `feed_posts` (`_composePublicarInterno`) e `_composeColab` zera a cada novo post. Vale para "Novo post" e para "Compartilhar treino/cardio" (mesmo compositor).
- **Convite = solicitação**: aba Pedidos da Comunidade ganha "Convites para posts colaborativos" (miniatura, Aceitar e Recusar), o badge do menu soma esses convites (`_amzBadge`), e o sino mostra "convidou você para um post colaborativo" e abre a folha
  do convite (`_colabConviteSheet`); `colab_aceito` ("aceitou colaborar no seu post") abre o post. Aceitar zera `_rankingData` e roda a detecção de conquistas.
- **Marcação**: o cartão do post mostra "👥 post colaborativo com Fulano e Beltrano" (e, para o autor, "⏳ convite enviado a ..."), a grade do perfil leva o selo 👥, e a tela do post tem o bloco "Post colaborativo" (o autor tira um colaborador, o colaborador
  sai do post). O post aparece no perfil do colaborador depois do aceite.
- **Distintivos** (`CONQUISTAS_DEF` e `CONQUISTAS_BASE` em `notificacoes.html`): `colab1` 👥 **Dupla Dinâmica** (1 post colaborativo no Feed), `colab5` ⛓️ **Parceria de Ferro** (5), `colab15` 🏟️ **Time Inseparável** (15); métrica `colab` vem de `treinos.php?action=ranking`
  (conta para autor e colaboradores aceitos, só posts com destino Feed).
- Opcional do aluno: o destino já existente. "Só meu perfil" faz o post aparecer só nos perfis dos dois; "Feed da Intus" também no Feed.

## 53. Receitas novas (46), destaques do dia, macros, toque duplo e mural (13/10/2026)
- **46 receitas novas** em `nutri-conteudo.json` (93 no total; versão 2026-10-13), no padrão do arquivo, com todos os ingredientes em g/ml e medida caseira (de `unidades.json`) e **informação nutricional por porção calculada pelo banco de alimentos do app**
  (TACO/IBGE/USDA; valores típicos de rótulo para leite desnatado, whey e goma de tapioca hidratada, documentados no script). Fotos livres da Unsplash redimensionadas para 800x500 em `app/painel/receitas/`, com autor e link em `receitas/LICENCAS.md`.
  A receita "Carne moída com abobrinha e tomate" ficou de fora por falta de foto correta (está nos arquivos de trabalho em `importar-local/receitas/`, fora do git). Fotos são aproximadas em algumas receitas: trocar pelo painel (Receitas e vídeos > Editar > foto).
  Para o app usar favoritar, avaliar e mural nas novas, a equipe clica em "⬇ Importar receitas novas do arquivo" (Nutrição > Receitas e vídeos).
- **Receitas antigas com quantidade em todos os ingredientes** ganharam `nutricao` marcada "Estimativa por porção" (14 receitas; as que já traziam "≈ N g de proteína" do e-book e as sem quantidade ficaram como estavam, para não contradizer a nutri).
- **Tela Receitas**: primeira aba "✨ Destaques do dia" (decidida pelo sistema, sem botão de sorteio: as receitas do acesso geral do dia, uma por categoria, `_nutriReceitaDoDia`, embaralhadas pelo número do dia com `_nutriRng`; igual para todo aluno e aparelho, muda todo dia; quem tem plano vê as mesmas e o resto em "Todas"), "Todas" por último; cartão e tela da receita mostram
  "🔥 ≈ N kcal · P · C · G"; a avaliação (favoritar, estrelas, "eu fiz") agora fica no FIM da receita.
- **Toque duplo**: CSS global `touch-action: manipulation` em `html, body` e controles: dois toques rápidos em + e − (séries, copo de água...) registram dois toques em vez de dar zoom (pinça continua funcionando).
- **Marcador de água na Home**: o interruptor na Nutrição agora é uma linha própria, "Deixar marcador de água na tela inicial", com explicação; e voltar para a Home por qualquer caminho (botão Voltar incluído) mostra o cartão
  (`trocarView('home')` confere `_homeAguaAtiva()`).
- **Mural dos Membros (ranking)**: escrever e responder só com plano de treino (matrícula) ativo (`_muralPodeEscrever`, no app; ler é aberto). O mural da Nutrição segue o plano alimentar (servidor). Escrever no mural do ranking não é checado no servidor
  (a regra de matrícula vive em `api.js`, no cliente).
- Teste novo `testes/receitas.js` (ids, fotos, ingredientes com quantidade, kcal coerente com macros).

## 54. Feed: aba Meu perfil, posts só de texto, parceiros e Ajuda como tela (13/10/2026)
- **Aba "Meu perfil" no Feed** (`_feedAba`: todos | amigos | meu): desenha, dentro da própria tela, o mesmo perfil social que abre ao tocar no nome em um post (foto, contadores, abas Posts, Conquistas, Mural de resultados e Semana, botão de privacidade, posts inclusive os "só no meu perfil"). É `abrirPerfilRanking(id, true, alvo)` com o 3º parâmetro `alvo` (elemento `#feed-meu-perfil`; sem ele abre a folha de sempre), carregado por `_feedMeuPerfilCarregar`. Privacidade e conexões também atualizam essa tela.
- **Post só de texto** (botão "✍️ Escrever" ao lado de "Novo post", `abrirPostTexto`): sem imagem, para a conversa acontecer nos comentários. O POST de `feed_posts` aceita `so_texto: 1` com o texto em `legenda`
  (obrigatório; `imagem` vai vazia, não cria linha em `intus_midia`, e o GET devolve `midias: []`). Sem `so_texto` a imagem continua obrigatória. A formatação é uma marcação simples dentro da própria legenda (sem coluna nova):
  `# ` título (21 px), `## ` subtítulo (16 px), texto (14 px), `**negrito**` e, na 1ª linha, `[serif]`, `[mono]` ou `[hand]` para a fonte (`_textoRicoParse/_textoRicoHtml`). O compositor tem botões N, Título, Subtítulo, Texto,
  4 fontes, prévia ao vivo, privacidade (todos, amigos, só eu) e destino (Feed ou só perfil); o limite é o da legenda (1000 caracteres, marcação inclusa). No cartão o texto longo fecha com "ver mais"; na grade do perfil
  vira uma miniatura de texto (`_pkTileTexto`, selo ✍️); sem botão "Baixar" (`_ehPostTexto`). O `feed.html` do painel mostra o texto sem a marcação. Reações, comentários e respostas são os de sempre.
  Teste: `testes/colab.php` (seção "Post só de texto"; tirar a exceção `!$soTexto` é acusado).
- **Parceiros de treino até 30** (`$LIMITE_PARCEIROS` no `catalogo.php`, `_pcDados.limite`): a lista agora é de quem você já treinou ou pretende treinar junto.
- **Ajuda como tela do app** (`ajuda.js`): o "?" abre a view `#v-ajuda` com o cabeçalho padrão (menu, tema, notificações), botão Voltar e o rodapé de sempre (`getFooterHtml`); o menu lateral ganhou o item "❓ Ajuda".

## 55. Fotos novas, dificuldade e kcal em todas as receitas, comentários, feedback com reação, Voltar e cardio (13/10/2026)
- **Receitas**: as 47 fotos antigas (recortes do PDF, pequenas ou esticadas) foram trocadas por fotos livres da Unsplash (800x500, créditos em `receitas/LICENCAS.md`; algumas são aproximadas ao prato). **Todas as 93 receitas têm `dificuldade`** (Fácil 79, Média 12, Elaborada 2) **e `nutricao`** (kcal e macros por porção, calculados com o banco de alimentos do app). As 25 sem quantidade completa no e-book usam quantidades usuais de uma porção
  (iogurte 170 g, leite 200 ml, whey 20 g...) e ficam marcadas "Estimativa por porção (quantidades usuais)". Teste: `testes/receitas.js` (toda receita com dificuldade, kcal e macros coerentes). Receita já importada para o banco recebe `nutricao` e `dificuldade` do arquivo no app (`_nutriConteudoCarregar`), sem reimportar.
- **Comentário ao avaliar receita**: o mural da receita (`alvo_tipo 'nutri_receita'`, `alvo_id` = `idconteudo`, mesmo mecanismo do mural do vídeo): depois de dar a nota o app rola até o campo "Escreva um comentário sobre a receita". Escrever é do plano alimentar ativo (servidor), ler é aberto, a equipe responde pelo painel (Nutrição > Receitas e vídeos > Mural). Excluir a receita apaga os comentários. Teste: `testes/mural_nutri.php`.
- **Feedback em vídeo com reação e comentário** (`feedbacks.php`, tabela nova `intus_feedback_interacao`; `feedback/interacao.js`): o aluno reage (6 emojis, 1 por feedback) e comenta pelo link OU pelo app, com o mesmo token, então um reflete no outro. A equipe responde na ferramenta (`feedback-video`, botão 💬 de cada gravação) e o aluno é avisado no sino; o comentário do aluno avisa o professor. Rotas
  públicas `interacoes`, `interagir`, `apagar_interacao`; o app carrega `interacao.js` do site (mudança nele vale sem rebuild). Teste: `testes/feedback_interacao.php`.
- **Voltar do celular e botão Voltar**: o gesto de voltar de subtelas desenhadas dentro da mesma tela (feedback em vídeo, avaliações, anamnese, desafio aberto...) usa o "← Voltar" delas em vez de pular para o início; o "← Voltar" do cabeçalho (`telaHeader`) usa `voltarTela()` (volta para a tela de onde veio).
- **Menu lateral com X**, **cartão de água mais compacto**, **tela de novo post com o cabeçalho padrão (Voltar)**, **Minhas conquistas sem o bloco "Seu placar"** (fica o total e os distintivos) e **"Excluir minha conta" só na aba Meus dados**.
- **Cardio**: o texto "Cardios que contam hoje" saiu; fica "Pontuam até N cardios por dia". **Cardio ao vivo, regra temporária até a publicação oficial nas lojas**: se o GPS ficou sem sinal (tela bloqueada ou app em segundo plano) por mais de 8% do tempo (mínimo 20 s), a distância do trecho sem sinal é estimada pelo ritmo médio do trecho medido (ver seção 56); a tela do cardio ao vivo explica que a distância só é precisa com a tela aberta. Pausar não conta como GPS cego. Remover esta regra quando o app nativo (com localização em segundo plano) estiver nas lojas.

## 56. Pontos do cardio: o tempo manda; GPS sem sinal estimado (13/10/2026)
- **Fórmula nova** (`API.cardioPontosBase`, `api.js`; o servidor repete em `treinos.php`, `_cardioPontosBase`, e grava o bruto ele mesmo no POST e no PUT do esforço, então app antigo também recebe a regra nova): `pontos-base = base da atividade x multiplicador de esforço x minutos/30`, com
  `base = 6 + 0,5 x (MET moderado - 6)` (aproxima as atividades: corrida pesa 1,47x a caminhada e a bike 1,35x, antes 2,3x e 1,9x) e `multiplicador = 1 + 0,03 x (RPE - 5)` (esforço 1 = -12%, esforço 10 = +15%, antes até +58%). O fator 0,55, o teto por sessão e o limite de 3 cardios por dia continuam como estavam. A tabela de METs agora mora no `api.js` (`API.CARDIO_METS`).
  **Vale só para sessões novas** (cada sessão guarda o bruto de quando foi registrada; semana fechada não muda). `api.js?v=20261013a` em todas as telas.
- **Testes**: `testes/cardio_pontos.js` (tempo manda, esforço pesa pouco, atividades coerentes) e `testes/cardio_pontos.php` (servidor reproduz a tabela `cardio_pontos.json`; já pegou uma divergência de arredondamento 8,05 JS x PHP, corrigida com +1e-7 no JS). Mudar a regra de propósito: `node cardio_pontos.js gravar` e conferir o PHP.
- **Cardio ao vivo sem sinal de GPS** (temporário até as lojas): se o GPS ficou cego por mais de 8% do tempo (mínimo 20 s), a distância do trecho sem sinal é **estimada pelo ritmo médio do trecho medido** (precisa de pelo menos 100 m e 60 s medidos e GPS acompanhando 40% do tempo); sem base, só o tempo é registrado. O aluno é avisado na tela de esforço e a tela do cardio ao vivo explica a regra.
- **Programas de cardio prontos**: estudo em `plano-programas-de-cardio.md` (nada construído).

## 57. Programas de cardio, treino fora do protocolo, reações com mais emojis, busca nas receitas, gotas de água (13/10/2026)
- **Programas de cardio (MVP, 6 fixos)** em `API.PROGRAMAS_CARDIO` (`api.js`): 12-5-30 (12% de inclinação, 5 km/h, em km/h por decisão do Luiz), HIIT 1:1, Tabata, 4x4, Zona 2 e Pirâmide. Tela própria (`#v-cardio-prog`, `aluno.html`, bloco "PROGRAMAS DE CARDIO"): lista, detalhe com aviso e escolha da atividade, **execução** com etapa grande,
  contagem regressiva, bip e vibração na troca, pausa, pular etapa, encerrar (registra só o que fez, sem ponto extra) e retomada se o app fechar (3 h). Só para quem tem matrícula ativa (`_progPermitido`). Entrada: cartão "Programas de cardio" na tela Meu Cardio. Registro: cardio comum (`salvarCardio` com `opts.comentario = "Programa: <titulo>"`, esforço padrão do programa), então limite de 3 por dia,
  plausibilidade e pontos do tempo seguem as regras de sempre. **Concluído = comentário `Programa: <titulo>` e 90% da duração** (`API.programaDaSessao`). **Programa concluído dá +1 ponto extra por DIA** (`API.PONTOS_PROGRAMA`, aditivo, fora do teto; dois no mesmo dia somam 1; `bonusProgramas` em `agregarPontosRank`)
  e conta para 4 distintivos novos (renomeados na seção 58: `prog1`, `prog5`, `prog15`, `progtodos`, métricas `programas` e `programasTipos`, espelhados em `notificacoes.html`). Estudo e decisões: `plano-programas-de-cardio.md` (fase 2: editar no painel e o professor indicar; fase 3: Fartlek, 8 semanas, desafios).
- **Treino fora do protocolo**: botão no fim de "Escolha seu treino" (`abrirTreinoForaProtocolo`): o aluno marca à mão motivo, dia (hoje ou ontem), duração (15 min a 4 h) e exercícios. Vira sessão de musculação com `divisao = 'F'`, sem ficha, **5 pontos fixos** (`API.PONTOS_FORA_PROTOCOLO`), não entra na ordem do dia (o treino da ficha continua o 1º) e conta como dia
  de treino para o bônus semanal. O servidor (`treinos.php`) valida duração, data e **1 por dia** (409 depois da checagem de duplicata, para o reenvio não virar erro). Testes: `testes/programas.js`.
- **Reações**: seletor com ~300 emojis em 7 categorias e "Recentes" (`abrirSeletorEmoji`), pelo "＋" da barra de reações do post e pelo "＋" de cada comentário (Feed, Mural do ranking e Mural da Nutrição). Em comentário, tocar no ícone curte (❤️) ou tira a sua reação, e o ícone mostra a sua reação ou a mais usada; o servidor já aceitava qualquer emoji (até 8 caracteres).
- **Receitas**: a busca agora procura em TODAS as receitas, em qualquer aba (a aba Destaques ignorava o texto); trocar de aba limpa a busca; no fim de cada aba aparece "Outras categorias".
- **Água**: os copos viraram gotas (cheia azul, vazia só o contorno). `api.js?v=20261013b`.

## 58. Sofá aos 5 km, Central em painel, fora do protocolo v2, emojis em páginas (13/10/2026)
- **Programa 7: "Do sofá aos 5 km"** (`sofa-5km` em `API.PROGRAMAS_CARDIO`): curso de 8 semanas, 3 treinos por semana (24 treinos, ids `S1T1` a `S8T3`), corrida e caminhada alternadas até 30 min seguidos, cada treino com 5 min de aquecimento e 5 min de volta à calma. Programa "curso" tem `sessoes[]` (os demais têm `etapas[]`); use
  `API.programaEtapas(p, idx)` e `API.programaDuracao(p, idx)`. Registro: comentário `Programa: Do sofá aos 5 km · S1T1`; concluído com 90% da duração DAQUELE treino (`API.programaSessaoInfo`). A tela mostra o progresso (semanas x treinos feitos) e sugere o próximo. Cada treino do curso conta como treino guiado (+1 ponto extra por dia, como os demais).
- **Distintivos de treinos guiados renomeados** (a palavra "programa" soava mal): `prog1` Missão Cumprida, `prog5` Foco Total, `prog15` Cardio Imparável, `progtodos` Coleção Completa (agora meta 7: os 6 programas de uma sessão + o curso completo) e **novo** `prog5km` 5 Km Conquistados (os 24 treinos do curso).
  Métricas em `API.metricasProgramas` (`programas`, `programasTipos`, `cursoCompleto`); o curso só conta como programa diferente quando os 24 treinos foram feitos. Espelhado em `notificacoes.html`.
- **Treino fora do protocolo v2** (`aluno.html`, bloco "TREINO FORA DO PROTOCOLO"): só de HOJE (sem "ontem"), "Outro motivo" abre o campo de descrição (obrigatório), séries com carga e repetições por exercício, busca do nome entre os exercícios cadastrados que o aluno já fez (traz as séries da última vez e abre o histórico completo,
  `abrirHistoricoExercicio`), diálogo de confirmação antes de registrar e tela de sucesso com atalho para o histórico. Testes em `testes/programas.js` (inclui a ordem em que o servidor devolve as sessões, F primeiro).
- **Central em painel** (`renderCentral`, `renderCentralPainel`): abre em "Painel" com 4 cartões (Mensagens, Feedbacks em vídeo, Comentários, Avisos), "Precisa da sua atenção", Avisos (mensalidade, treino/ficha vencendo, nova avaliação em 60 dias, mudanças na ficha) e Atividade recente com filtro. As abas Chat, Atividade (lista completa) e Treinos
  (comentários dos treinos) continuam. Dados: chat, `catalogo.php?action=notificacoes`, `feedbacks.php?action=meus` e avaliações; a Central sempre volta ao painel ao sair.
- **Seletor de emojis** em páginas que se arrastam para o lado (como o teclado do WhatsApp), com a barra de categorias embaixo e a folha com cerca de 50% da tela; só emojis até o Unicode 13.
- **Indicação**: os dois cartões "Indique um amigo" dizem que quem indica ganha um presente especial da Intus Fit (camisetas exclusivas, descontos, suplementos e até meses de graça treinando com acompanhamento).
- **Distintivos de post colaborativo** (`treinos.php?action=ranking`, `colab`): contam para o autor (1 por post com colaboração aceita) E para cada colaborador que aceitou; agora entram na conta mesmo quem não participa do ranking.

## 59. Correção: marcador do treino fora do protocolo e execução dos programas sem toque (13/10/2026)
- **Bug (Luiz Nunes, 13/10)**: o treino fora do protocolo era gravado com `divisao = 'F'`, a mesma letra do **Treino F de verdade** das fichas. Todo Treino F real passou a valer 5 pontos (fora da ordem do dia) em vez de 10. Agora o marcador é **`'FORA'`** (a coluna `divisao` tem 4 caracteres) e `API.foraProtocolo`
  só reconhece `FORA` (e, por compatibilidade, a sessão de teste antiga: divisão F, sem ficha e comentário "Fora do protocolo"). Treino F com ficha volta a valer 10. Painel (Frequência, Clientes) e histórico do aluno mostram "Fora do protocolo". Teste: `testes/programas.js` ("O Treino F de verdade NÃO é fora do protocolo").
  **Nunca usar uma letra de divisão como marcador.** A sessão de teste antiga continua no banco (remover só pelo painel).
- **Programas de cardio, execução sem toque** (`aluno.html`, bloco "Execução"): "Começar" abre a tela do programa com o tempo **parado** (fase `pronto`) e o botão ▶ Iniciar; depois, contagem 3, 2, 1 (fase `contagem`) e o programa segue sozinho: bip por intensidade (agudo duplo no forte, grave no leve), vibração, flash colorido de tela cheia com o nome do bloco, voz
  (pt-BR, liga e desliga) e bips nos 3 últimos segundos de cada bloco. Controles: pausar, ⏮ voltar o bloco (se já passaram mais de 3 s, recomeça o atual), ⏭ avançar, ⏹ encerrar. A tela mostra decorrido, restante e total, uma barra com um segmento por bloco, o bloco atual com contagem e os 3 blocos seguintes. A duração registrada é o tempo
  realmente em andamento (`ativoMs`), então avançar blocos para acabar cedo não conta como programa concluído. O cartão "Programas de cardio" fica abaixo do cardio manual.
- **Tela de conclusão do programa** com os botões padrão de fim de treino (Compartilhar cardio com o nome do programa, Início, Ranking, Conquistas, Histórico e Ver outros programas). **Histórico, perfil/ranking e painel (Frequência)** mostram o método em destaque com o tempo real (`API.programaDoComentario`: título, semana/treino do curso e "✓ concluído" ou "parcial").
- **Programa que marcava o tempo cheio (corrigido)**: na versão anterior a execução registrava a duração inteira do programa ao chegar ao último bloco, mesmo com os blocos avançados à mão. Hoje a duração registrada é o tempo realmente em andamento (`ativoMs`); abaixo de 90% o treino fica "parcial" (sem ponto extra nem distintivo) e abaixo de 5 min não é registrado.
  Sessões gravadas antes dessa correção continuam como foram (só se corrigem removendo pelo painel).


## 60. Cardio em segundo plano, navegação sem laço, Central com profissionais, motivo de exclusão (13/10/2026)
- **Navegação (laço de voltar/avançar entre telas)**: causa era `trocarView(bv)` empurrando entrada nova no histórico a cada re-render da mesma tela e a cada "voltar" explícito. Agora `trocarView` só empurra quando a tela MUDA (senão `history.replaceState`), `voltarTela(fallback)` desfaz o histórico até o `fallback` em `viewStack` (usa `window._popN`) e o popstate descarta `_passos` entradas. Testado no navegador: cardio, programas, detalhe, voltar.
- **Programas de cardio**: tela do programa com "Como funciona", "Onde fazer e o que precisa" (`aparelho` e `onde` em `API.PROGRAMAS_CARDIO`, mapa `ONDE`), botão "Ver todas as etapas" e, no curso Do sofá aos 5 km, o progresso por treino com a data (`_progFeitos`). Natação saiu do Zona 2. O distintivo `prog5km` agora se chama "Do Sofá aos 5 Km" (também em `notificacoes.html`).
- **Execução com tela apagada e em outras telas**: voz em pt-BR escolhida entre as melhores disponíveis (`_progVozEscolher`, frases por bloco, "Três, dois, um" e "Faltam dez segundos"), bips nos 3 últimos segundos (mais altos, com vibração), áudio silencioso em laço para manter a página viva, Media Session, notificação pelo service worker e, no app nativo, notificações locais agendadas (`_progAgendarNativo`; **exige instalar `@capacitor/local-notifications`, `npx cap sync` e refazer o build**). iOS: `UIBackgroundModes` = `audio` no `Info.plist`. Pílula `#prog-pill` ("🔥 bloco · tempo") aparece em qualquer outra tela enquanto um programa roda e toca volta ao programa. **Limite honesto: no navegador/PWA o sistema pode suspender a página com a tela apagada; voz e bip só são garantidos no app nativo com o build novo.**
- **Meu cardio**: cartões do mesmo tamanho (cardio ao vivo reduzido), o registro manual diz "Qualquer esporte, com a intensidade que você sentiu" (sem "GPS") e há um aviso sobre a importância do cardio (coração, condicionamento, gordura, metabolismo, saúde).
- **Sino**: a lista ficava em "Carregando" porque o modal e a aba da Central usavam o mesmo `id="notif-lista"`. O modal agora usa `notif-m-lista` e `notif-m-marcar`; `_notifMarcarTodas` cobre os dois. Notificação nova com o app aberto: `_notifAvisoNovo` (faixa verde no topo, 8 s, toque abre o sino), no lugar do toast curto.
- **Central**: o número em destaque dos cartões é sempre "N novos" (ou "em dia") e o texto mostra o total, para os dois números não divergirem. Aviso de avaliação a partir de 45 dias: "Sua última avaliação foi há X dias. Veja com o seu professor se já é hora da próxima." (a partir de 60 dias fica como urgente). Aba "Treinos" virou "Comentários": respostas e menções de outras áreas (Feed, receitas, vídeos, parcerias) mais os comentários dos treinos.
- **Chat com a equipe**: mostra quem acompanha o aluno (nome e função, de `professores_responsaveis` + `intus-usuarios`), o aluno escolhe o destinatário e a mensagem vai com `idusuario` = profissional escolhido (sem escolha = equipe, `idusuario` nulo). A conversa de cada profissional mostra as mensagens dele e as sem destinatário. No painel (`mensagens.html`) a mensagem do aluno mostra "Para Fulano". Mensagens entre alunos: só o atalho "Amigos · em breve" (nada no servidor ainda).
- **Matrícula**: botão "Conhecer outros planos" (hoje na aba Matrícula do perfil, não na tela interna) abre o WhatsApp da Intus com nome, matrícula e plano atual do aluno.
- **Exclusão de conta**: pede o motivo (obrigatório na tela; versões antigas do app gravam "Não informado") e uma observação opcional. `aluno_login.php?action=excluir_conta` grava em `intus_exclusao_conta` (tabela nova, criada no primeiro pedido, só recebe linhas) e inclui o motivo no e-mail da equipe. `dashboard.php` devolve `exclusoes` (60 dias; professor vê só os seus alunos) e o painel inicial (`index.html`) mostra um cartão vermelho em destaque com nome, data, motivo e observação; o nome abre Clientes já filtrado (`alunos.html?busca=`).
- **Textos**: legendas das redes sociais em todo lugar (rodapé, Feed, Início, `index.html`, `teste-gratis.html`): "Siga e marque a Intus Fit no Instagram", "Inscreva-se no nosso canal do YouTube", "Ouça a playlist de treino da Intus Fit". Revisão da copy da indicação e da mensagem de WhatsApp.

## 61. Chat da Central: profissionais com foto e conversa entre amigos (13/10/2026)
- **Chat** (`renderCentralChat`): duas faixas de escolha de destinatário, cada uma com foto e nome. "Profissionais que acompanham você" (de `professores_responsaveis` + `intus-usuarios`, fotos de `API.getUserAvatars`, recarregadas ao abrir a Central) e "Seus amigos" (de `_amzDados.amigos`, só com `_conexoesOn`). A mensagem do aluno para um profissional vai com `idusuario` = o profissional; a conversa de cada um mostra as mensagens dele e as sem destinatário. Destino do chat: id do usuário (profissional), `group` (equipe) ou `a:<idatleta>` (amigo).
- **Conversa entre alunos**: `catalogo.php?action=dm` (GET lista conversas com última mensagem e não lidas; `?com=ID` abre a conversa e marca como lida; `?so_contagem=1`; POST `{para_id, texto}`; DELETE `?id=` só da mensagem PRÓPRIA). **Só entre amigos** (amizade aceita, checado no servidor), até 1000 caracteres e 20 mensagens por minuto. Tabela nova `intus_dm` (criada no primeiro uso; só recebe linhas). Aviso `dm` no sino: um por conversa enquanto não for lido (some ao abrir a conversa); tocar abre o chat com aquela pessoa (`_notifAbrir`). A conversa aberta atualiza a cada 12 s (`_dmAtualizacaoLigar`); não lidas somam na aba Chat e no cartão Mensagens do painel. A tela Amigos tem o botão "💬 Mensagem". Amizade desfeita esconde a conversa (as mensagens ficam no banco).
- **Cardio**: espaço entre o título e o primeiro cartão e emojis nas atividades do registro manual e do histórico (`_cardioEmoji`).
- **"Conhecer outros planos"** fica na aba Matrícula do perfil (tela anterior), não mais dentro da tela da matrícula. O plano atual vem de `_mensalidadesAtivas()`.
- **Chat, lista de profissionais**: quando o aluno não tem professor responsável cadastrado (ou o id não existe em `intus-usuarios`), a faixa lista TODOS os profissionais ativos da Intus (professor, nutricionista...), com os responsáveis primeiro quando existem; sem responsável o destino padrão é "Equipe Intus". `usuarios.php` (GET para aluno) agora só devolve profissionais ativos.
- **Zumbido durante os programas (corrigido)**: o áudio "silencioso" que mantém a página viva com a tela apagada (`_progSilencioUrl`) tinha uma onda de 200 Hz de amplitude mínima, que alguns aparelhos tocavam como um som contínuo. Agora é silêncio de verdade (todas as amostras no ponto zero). Os únicos sons são os bips dos 3 últimos segundos, o sinal da troca de bloco e a voz nos pontos importantes.
- **Voz dos programas mais humana e animada**: escolha da voz prioriza as de rede/neurais (Microsoft Online/Natural, Google, Siri/Premium, Android "network") e descarta as locais/robóticas (`_progVozEscolher`); a fala é dividida em frases curtas com tom e velocidade diferentes por estilo (`_progFalar(txt, estilo)`: forte = enérgico, leve = calmo, fim = comemoração) e as frases ficaram mais animadas. **Limite**: continua sendo a voz instalada no aparelho; voz 100% humana exige áudios gravados/gerados (ver proposta ao Luiz).

## 62. Programas de cardio: texto por atividade e voz gravada (13/10/2026)
- **Escada, bike, elíptico e corda** (`_PROG_ATIV` e `_progEt` em `aluno.html`): nome do bloco, dica e fala viram o movimento de cada aparelho ("Sobe forte", "Pedala forte", "Acelera no elíptico", "Pula forte"; leves como "Desce e recupera"). Só troca os blocos de texto genérico (Forte, Leve, Aquecimento, Volta à calma, Descanso), nos programas com mais de uma atividade; corrida e caminhada ficam como estavam. Escada e corda mostram um aviso de segurança na tela do programa.
- **Voz gravada**: 50 áudios curtos (`app/painel/voz/*.mp3` + `manifest.json`, 0,9 MB) gerados uma vez com a voz neural `pt-BR-ThalitaMultilingualNeural` (pacote `edge-tts`, ritmo e tom ajustados por tipo de frase; gerador em `scratchpad/voz-gerador`, não versionado). `_progClipesCarregar` baixa todos ao abrir um programa e `_progFalar` toca o áudio da frase (sem o sufixo "Por X minutos", que aparece na tela); frase sem áudio cai na voz do aparelho. **Frase nova de voz = gerar o áudio novo e entrar no `manifest.json`.** **Licença**: o `edge-tts` usa o serviço de leitura do Microsoft Edge, sem contrato de uso comercial; para o uso definitivo trocar por Azure Speech ou Google Cloud TTS (mesmos textos, chave do Luiz).

## 63. Caixa de mensagens, só os responsáveis, programas sem aparelho e licença da voz (13/10/2026)
- **Chat como caixa de mensagens** (`renderCentralChat`: `_renderChatLista` e `_renderChatConversa`): ao abrir, nenhuma conversa fica aberta. A lista mostra, como nas redes sociais, foto, nome, última mensagem, horário (hoje = hora, ontem, dia da semana, data) e bolinha de não lidas, em dois grupos: "Seus profissionais" (mais a "Equipe Intus") e "Amigos". Tocar abre a conversa (`_chatAberta`) sem o cabeçalho da Central: topo com ← (volta à lista), foto e nome, balões com separador de dia ("Hoje", "Ontem") e campo de envio.
  `_chatMsgsDe(dest)`: cada profissional mostra as mensagens com o `idusuario` dele; a Equipe Intus mostra as sem destinatário. Sair da Central volta para a lista.
- **Só os "Professores responsáveis" do cadastro do aluno** (`_chatProfsLista`): o aluno só vê, para conversar, os profissionais marcados em `professores_responsaveis` (ativos). Substitui a regra anterior da seção 61 que listava todos os profissionais. Sem responsável cadastrado, só aparece a "Equipe Intus" (o cadastro certo do aluno é o que decide quem aparece).
- **Programas sem aparelho**: HIIT 1:1, Tabata e Pirâmide ganharam as atividades **Polichinelo** e **Corrida estática** (feitas em casa, sem aparelho), com nomes, dicas, avisos de segurança e fala próprios (`_PROG_ATIV`). Entraram em `API.CARDIO_METS` (METs 8,0, mesmos do servidor em `treinos.php`) e na tabela de referência dos pontos (`cardio_pontos.json`, regravada); aparecem também na lista do registro manual do cardio.
- **Licença da voz gravada** (`tools/voz/LEIAME.md` e `tools/voz/gerar_licenciado.py`): os 60 áudios atuais são provisórios (edge-tts, sem licença comercial). `app/painel/voz/manifest.json` tem `_meta.licenciado`: enquanto for `false`, o **app das lojas (Capacitor) ignora os áudios** e usa a voz do aparelho; site e PWA continuam usando. Para publicar nas lojas: criar um recurso Azure Speech, rodar `gerar_licenciado.py` (chave só por variável de ambiente) e fazer o commit; o script regera os mesmos arquivos pela API oficial e marca `licenciado: true`. O guia também traz as declarações da iOS (áudio em segundo plano) e do Android (notificações locais).
- **Zona 2 e 4x4 também sem aparelho** (Polichinelo e Corrida estática): blocos contínuos e fortes longos têm texto próprio (`cont` e `dlongo` em `_PROG_ATIV`); `api.js?v=20261013h`.
- **Áudio dos programas, motor** (`_progClipesCarregar`, `_progClipeFalar`): os áudios são decodificados no `AudioContext` já liberado pelo toque no Iniciar (toca do cronômetro mesmo com a tela apagada, sem depender de novo toque por arquivo, que o iOS exige); `<audio>` só como reserva e a voz do aparelho se faltar o áudio. Nova tentativa de download se algum arquivo falhou. As falas de cada bloco saem de `_progFrasesBloco` (fonte única) e `_progVozCobertura()` (no console) lista qualquer fala sem áudio. **Teste `testes/voz.js`**: toda fala do código tem áudio no manifesto, o arquivo existe e é MP3, e o manifesto não guarda áudio sem uso (quebrar um arquivo de propósito é acusado). Hoje: 62 falas e 62 áudios.
- **Voltar do celular com conversa aberta** fecha a conversa e mostra a lista de mensagens (`popstate` chama `_chatVoltarLista`), em vez de ir para a tela inicial. Cabeçalho da conversa redesenhado com cores explícitas nos dois temas (o anterior usava `var(--bg)`, que no tema claro ficava preto e escondia o nome e a seta).

## 64. Build nativo preparado com notificações locais (13/10/2026)
- `mobile/`: instalado `@capacitor/local-notifications@8.3.1` e rodados `npm run build:web` e `npx cap sync` (Android e iOS, sem erro). `build-web.mjs` leva a pasta `voz/` ao app. Android: canal `programa` (importância alta), ícone `ic_stat_intus` e permissão `SCHEDULE_EXACT_ALARM` (alarme exato; `POST_NOTIFICATIONS` já existia). `_progAgendarNativo` cria o canal, avisa/abre a configuração de alarme exato uma vez e agenda um aviso por troca de bloco.
- **Não compilado neste computador** (sem JDK/Android SDK; iOS exige Mac). Passos e o teste de aparelho (tela bloqueada, programa curto) estão no `mobile/README.md`, seção "Notificações locais dos programas de cardio". **Depois de qualquer mudança em `aluno.html`, `api.js` ou nos áudios, rode `cd mobile && npm run sync` antes de gerar o build.**

## 65. Troca de tema, programas sem aparelho, voz nativa e correções (13/10/2026)
- **Troca de tema deixava telas ilegíveis** (texto claro em fundo claro, como no detalhe dos programas): `redesenharViewAtual` só conhecia algumas telas e as outras ficavam com a cor do tema antigo, inclusive ao voltar pelo gesto (que não redesenha). Agora o mapa `_RENDER_POR_VIEW` cobre programas de cardio (lista, detalhe, execução; o resultado não se redesenha), amigos, resultados, treinos, mensagens (mantém aba e conversa) e a Nutrição mantém a subtela; cada tela guarda `data-tema` e, ao voltar pelo gesto, `_viewRefazerSeTemaVelho` redesenha se o tema mudou. Varredura de contraste automatizada (28 telas e subtelas, nos dois sentidos): sem texto ilegível por tema preso. Corrigidos tons fracos no tema claro: rodapé, avisos do Feed e do ranking, "Nenhum cardio registrado", azul da água, descrição do esforço percebido e o selo âmbar "N novos". **Armadilha de teste**: com a aba do navegador em segundo plano as transições CSS não avançam e a cor computada fica no valor antigo; desligue `transition` ao medir.
- **Aquecimento e volta à calma sem sentido** (Tabata com polichinelo abria com 5 min de polichinelo): para Polichinelo, Corrida estática, Pular Corda e Escada os blocos "Aquecimento" e "Volta à calma" viram passos de mobilidade (marcha no lugar, giro de braços, agachamento, afundo, a atividade em ritmo leve, aumento de ritmo; e caminhada, respiração e alongamento), com **o mesmo tempo total** (`_progMobilidade`; a duração e a regra dos 90% não mudam). `_progConferirDuracoes()` (console) confere todas as combinações.
- **Atividades sem aparelho só no HIIT 1:1 e no Tabata** (Polichinelo, Corrida estática; `ATIV_CASA` em `api.js`); 4x4, Zona 2 e Pirâmide não têm (blocos longos de esforço).
- **Voz gravada trocada** de `ThalitaMultilingual` (pronunciou "polichinelo" com sotaque espanhol) para **`pt-BR-FranciscaNeural`** (voz só em português do Brasil); 72 áudios regerados. O guia e o script de licença usam a mesma voz.
- **Voltar do celular em programa** (detalhe, execução ou resultado) leva à lista de programas; com programa rodando a lista mostra a faixa "Você tem um programa em andamento".
- **Chat só com o próprio profissional escolhido** (corrigido): `usuarios.php` para aluno filtrava por `ativo`, mas `outAtivo` só aceita 'S' ou nulo e escondia profissionais com outro valor; o filtro foi removido (e o do app também), então os responsáveis voltam a aparecer.
- **Ranking voltava ao topo ao fechar o perfil de um aluno**: `_pkTravarFundo` travava a rolagem com `overflow: hidden` no `html` e isso zerava a posição; agora guarda e restaura a posição ao fechar (perfil, post aberto e privacidade).
- **Chat mostrava só a Equipe Intus (causa real, 13/10)**: o app do aluno nunca carregava o PRÓPRIO cadastro (a lista de atletas é rota de professor), então `professores_responsaveis` vinha vazio e `getProfsResponsaveis()` não achava ninguém. Agora `_atletaPropriaCarregar()` busca `atletas.php?id=<eu>` (permitido ao aluno), guarda em `intus-atletas` (na sincronização inicial e ao abrir a Central, com cache de 5 min) e a Central também recarrega `usuarios.php`. Sem responsável marcado, a lista mostra o aviso "Seu cadastro ainda não tem um professor responsável marcado". Qualquer tela do aluno que leia `getAtletaCompleto()` agora recebe o cadastro completo.
- **Aviso de troca de bloco cortava nas bordas** (`_progFlash`): a fonte fixa de 54 px estourava a largura com nomes longos ("Agachamento livre", "Alongamento das pernas"). Agora o texto se ajusta à tela (diminui até a maior palavra caber, até 40 px nos nomes atuais, com quebra de linha). Conferidos todos os 34 nomes de bloco existentes e a tela de execução, sem corte em 375 px de largura.
- **Escada = máquina de escada** (14/10): o que o aluno faz é a máquina de escada da academia, não escada de prédio. Textos, dicas, avisos de segurança (sem se apoiar nas barras, tronco ereto, velocidade) e falas de `Escada` em `_PROG_ATIV` reescritos ("Acelera na escada" / "Escada leve", sem "descer"); o aquecimento e a volta à calma dela passam a ser progressivos NA máquina, como bike e elíptico (saiu de `_PROG_MOB`). O nome exibido é "Máquina de escada" (`_ativRotulo`, nos programas, no cardio ao vivo e na lista do registro manual) e o emoji é 🧗; a chave interna continua `Escada` (histórico, pontos, METs). Áudios das falas de escada regerados (71 no total). `api.js?v=20261014a`.
- **Títulos de tela estreitos**: o título do `telaHeader` agora escala (13 a 18 px) para não virar reticências em 320 px.

## 66. Financeiro do professor: só os alunos em que ele é responsável (14/10/2026)
- **Regra (decidida com o Luiz)**: professor (não administrador) com permissões de matrículas, pagamentos ou "Ver financeiro" enxerga e altera pagamentos SÓ dos alunos em que consta em `professores_responsaveis` (`getAtletasDoUsuario`). **Caixa da empresa, Eduzz e Analytics são da operação inteira**: só administrador e quem tem "Ver o caixa da empresa" (`fin_empresa`).
- **O que já valia**: a listagem de `mensalidades.php` (GET) e o `dashboard.php` já eram filtrados por responsável no servidor; `audit.php` é só do administrador; o aluno nunca tem acesso.
- **Brechas fechadas hoje** (verificadas lendo o código; antes só o navegador escondia): (1) `mensalidades.php?action=metricas` devolvia receita do mês, contagens e plano mais popular da base INTEIRA a qualquer professor, e agora calcula só dos alunos dele; (2) POST, PUT e DELETE de mensalidade só bloqueavam alunos "protegidos" (com administrador como responsável), então um professor podia criar, editar ou apagar pagamento de aluno que não era dele, e agora exigem que o aluno esteja na lista dele (403 "este aluno não é seu"); (3) `catalogo.php?action=caixa` (ler, lançar e apagar) só barrava aluno, e agora exige administrador ou `fin_empresa` (helper novo `usuarioTemPermissao` em `_auth_context.php`, lê a permissão do cadastro; na dúvida nega).
- **Painel (`financeiro.html`)**: sem `fin_empresa`, somem as abas Caixa, Eduzz e Analytics, os cartões Saldo do Caixa, Entradas extras e Saídas, e o lançamento manual; o cache local do caixa é ignorado. `usuarios.html` mostra nos rótulos das permissões o alcance de cada uma (`_mock.js?v=20261014a`).
- **Efeitos a saber**: aluno sem professor responsável marcado não recebe pagamento novo de professor (só do administrador); a permissão "Lançar entradas/saídas" só vale junto com "Ver o caixa da empresa"; as chaves `mt_*`, `pg_*`, `fin_ver` continuam sendo conferidas só no navegador (o servidor aplica o ESCOPO por aluno, não a chave de cada botão). Ids 1 e 2 são sempre administrador no servidor (`_auth_context.php`).

## 67. Fotos dos profissionais não chegavam ao app do aluno (14/10/2026)
- **Causa**: em `usuarios.php`, o bloco "GET listar" pegava TODO GET sem `id`, inclusive `?action=avatars_usuarios` (e `avatar`, `cargo`, `user_meta`), que têm blocos próprios mais abaixo e nunca eram alcançados. O GET das fotos devolvia a lista de usuários, então nenhuma foto cadastrada no painel chegava a outro aparelho: o admin via a foto só porque ela ficava no `localStorage` dele. Agora o GET da lista ignora essas quatro ações.
- Também: `API.getUserAvatars` guardava a resposta inteira (a lista de usuários virava "fotos" no `localStorage`) e podia falhar na cota; agora só aceita chaves numéricas e a gravação local não derruba mais a resposta. A Central do aluno busca só as fotos dos responsáveis (`avatars_usuarios&ids=3,5`, suportado no servidor) em `_avatarsProfissionaisCarregar`. `api.js?v=20261014b`.
- Fotos dos profissionais **já cadastradas** no painel ficaram só no navegador de quem cadastrou (o POST grava no servidor, então as que foram salvas com sucesso já estão no banco e passam a aparecer). Se alguma não aparecer, abrir o usuário no painel e salvar a foto de novo; `usuarios.html` só avisa falha no console (`Avatar save failed`), vale mostrar aviso na tela numa próxima revisão.
- **Foto da Equipe Intus no chat** (14/10): a marca da Intus (`logo-icon-light.png`) num círculo branco, na lista de conversas e no cabeçalho da conversa (`_equipeAvatarHtml`).

## 68. Backups visíveis e verificáveis, conferência de segurança e limpeza de exposição pública (14/10/2026)
- **Painel de backups** (administrador, Configurações > Servidor > Backups; `app/api/_backup_admin.php`, rota `catalogo.php?action=backup_status`): status do último backup (lido do `config/backup.log`), cópias no Drive, onde o banco está hospedado (a linha "Banco conectado" do log; vermelho se for `maximizeapp`), servidor de e-mail, disparo (carona ou CronJob), "Conferir a última cópia" (baixa do Drive e confere gzip, cabeçalho, rodapé, tabelas e dados), "Testar o e-mail" (devolve o erro exato do SMTP), e-mail de destino do aviso (guardado em `intus_secrets.backup_config`) e "Fazer backup agora". Aviso no topo do painel (`backupAvisoIniciar`, `_mock.js`) quando faz 2+ dias sem backup, na falha, ou se banco/e-mail estiverem no maximizeapp. Compara também as contagens por tabela do último backup com as de agora e avisa se alguma tabela ficou com MENOS linhas.
- **Por que o backup talvez nunca tenha chegado**: (1) a "carona" só pegava `dashboard.php` no resumo, que nenhuma tela chamava (só desde 14/10 o `index.html` chama); agora o sino do painel (`notificacoes` GET, staff) também dispara; (2) quando o script roda incluído por dentro de uma função (carona e botão), `blog()` e o resumo por e-mail liam variáveis `global` que nunca existiam, então o registro não era gravado e o e-mail saía sem log; corrigido com `$GLOBALS`. O destino padrão do e-mail era o próprio usuário do SMTP; agora dá para escolher.
- **Zip de arquivos** agora inclui `app/img/nutri` (fotos de refeição) e `app/img/receitas`. Vídeos de posts e feedbacks continuam só no Drive.
- **Restauração**: `tools/backup/restaurar.php` (`--so-ler` confere a cópia sem banco; importar exige `--confirmo-banco=<nome>` e a senha só por ambiente) e `tools/backup/RESTAURAR.md` (teste mensal e emergência). Teste `testes/backup_restaurar.php` (6 conferências: texto com `;`, aspas e `\n` dentro do dado não quebra o comando; cópia cortada e arquivo que não é gzip são reprovados; importar sem confirmar o nome do banco é recusado).
- **maximizeapp.com.br**: nenhum arquivo do repositório aponta para ele. O servidor de produção pode ainda usar `mysql.maximizeapp.com.br` (só o painel de backups mostra o host real). Roteiro de migração e redirecionamento do painel antigo em `tools/maximizeapp/LEIAME.md` (passos de senha, FTP e KingHost são do Luiz).
- **Segurança, achados desta rodada**: `_mock.js` (público) trazia a senha do administrador mestre e `RI985QjJZfUqjH` (texto aleatório no lugar da senha antiga) de outros usuários em texto puro; removidas (o login de verdade é no servidor, o fallback local não aceita conta sem senha). **Se aquela senha do administrador mestre é ou foi a real, trocar agora (ela ficou publicada no site até hoje).** `CLAUDE.md`, `tools/`, `mobile/` (código do app), os prompts `.md` e as cópias antigas das telas (`*-old-*`, `*-bug-*`) eram servidos publicamente; o `.htaccess` da raiz agora devolve 403 para eles (`tools|testes|mobile`, arquivos `.md/.py/.sh/.ps1/.bak/.sql/.lock` e `-old/-bug`).

## 69. Revisão de segurança (14/10/2026)
**Achados e correções** (verificados lendo o código e pelo site no ar; nada foi testado com login real de professor):
- **Alto, corrigido**: qualquer professor podia listar as sessões de todos e DERRUBAR a sessão de qualquer conta (inclusive a do administrador) ou podar sessões (`catalogo.php?action=sessoes`); agora só administrador. Qualquer professor também podia mudar as regras que valem para todos os alunos (pontuação do ranking, regras do cardio, conquistas, avisos, frases, figurinhas); a escrita dessas rotas agora é só do administrador (leitura continua).
- **Alto, corrigido**: `app/config/.htaccess` só barrava `*.php` e `*.json`; o dump temporário do backup (`.sql.gz`) e `backup-ultimo.txt` ficavam acessíveis. Agora a pasta inteira é fechada. Arquivos `_*.php` da API (bibliotecas, inclusive a "carona" do backup) respondiam pelo navegador; agora 403.
- **Alto, corrigido**: a área `admin/` (guarda a chave do Drive e as do WhatsApp) tinha só um `sleep(1)` contra força bruta; agora 5 erros por IP e 60 no total em 15 minutos bloqueiam o login, cookie de sessão `Secure`/`HttpOnly`/`SameSite=Strict` e `session_regenerate_id` ao entrar.
- **Médio, corrigido**: trava de tentativas só por IP; agora também por CONTA (8 erros em 15 min, de qualquer IP) nos três logins (`_rate_limit.php`: `checkRateLimitConta`, `recordAttemptConta`, `clearAttemptsConta`). Código de acesso de aluno novo tinha 4 dígitos (10 mil combinações); agora 6 caracteres aleatórios (os antigos continuam valendo; trocar os antigos é decisão do Luiz).
- **Médio, corrigido**: envio de imagem só olhava o texto `data:image/png;...`; agora o conteúdo precisa ser mesmo JPEG, PNG, WEBP ou GIF e a extensão do arquivo é a real (`_intusExtImagem`; `midia.php` igual). Pastas de upload não executam script (`app/.htaccess`). Teste `testes/seguranca.php`.
- **Médio, corrigido**: exclusão definitiva de cliente (`atletas.php?action=excluir`) e exclusão de pagamento (`mensalidades.php` DELETE) agora exigem a permissão no servidor (administrador, ou `at_excluir` / `mt_excluir`, `pg_excluir`, `mt_gerir`); antes só o painel escondia o botão. As demais chaves (`*_incluir`, `*_editar`) continuam só no navegador.
- **Baixo, corrigido**: SMTP sem limite de espera (prendia um processo por até 60 s por resposta); agora 6 s para conectar e 8 s por resposta.
- **Senhas e documentos expostos** (seção 68): senha do mestre em `_mock.js`, `CLAUDE.md`, `tools/`, `mobile/`, cópias antigas.
**Pendências, em ordem**: (1) o certificado do servidor de e-mail NÃO é verificado em `intus_enviar_email` (a senha do SMTP pode ser capturada por quem estiver no caminho): rodar "Testar o e-mail" no painel (ele já verifica) e, se passar, pôr `'verify_tls' => true` em `app/config/smtp.php`; (2) verificação em duas etapas para administrador e professores (hoje só senha); (3) conferir no servidor TODAS as chaves de permissão (`*_incluir`, `*_editar`) e as de leitura; (4) CSP no HTML (há muito script embutido, exige ajuste); (5) token de sessão no `localStorage` (um XSS o leria; o código escapa texto em todas as telas verificadas); (6) proteção na frente do site (Cloudflare) e limite de processos: a hospedagem tem poucos, e o backup diário segura um por minutos; (7) senhas digitadas por administradores ficam em texto no `localStorage` do navegador deles (`api.js`, cache de usuários); (8) `auth.php` (login antigo) usa `config/cors.php` com `*` (sem cookies, risco baixo); (9) antivírus nos envios.

## 70. Backup que nunca rodava: causa, barra de progresso e e-mail verificado (14/10/2026)
- **Causa raiz**: `cron-backup.php` incluía `config/db.php` com `include` simples. Esse arquivo declara `getDB()` e quem chamava (`catalogo.php`, `dashboard.php`) já o tinha carregado: erro FATAL "Cannot redeclare getDB()" antes da primeira linha do registro, sem nada gravado e sem nada na tela. Por isso a carona e o botão "Fazer backup agora" nunca produziram cópia. Corrigido com `include_once` (reproduzido e confirmado em teste local). **Nunca incluir `db.php` de novo com `include` simples.**
- **Progresso**: o backup grava `config/backup-progresso.json` (`_bkProg`, estado rodando | ok | falha, porcentagem, etapa, sinal de vida) e um `register_shutdown_function` transforma qualquer saída antes do fim (erro fatal, limite, `exit(1)`) em "falha" com o motivo. `catalogo.php?action=backup_status&progresso=1` devolve só o andamento; o painel desenha a barra (`backupAcompanhar`) e retoma se a tela for reaberta. Sem sinal de vida por 4 min = "o servidor encerrou o processo".
- **Pré-verificação** (`backupPreparar`): antes de começar confere pasta gravável, gzip, curl/openssl, banco e se o Google aceita a chave do Drive, e recusa com o motivo exato. O botão responde na hora e o trabalho segue depois (`_backupResponderEContinuar`). A carona só roda se o servidor tem `fastcgi_finish_request`.
- **Envio ao Drive** em pedaços de 8 MB lidos do disco (`_backupEnviarArquivoDrive`, upload retomável, 3 tentativas por pedaço, motivo exato do erro). Antes o zip inteiro ia para a memória com limite de 30 s. Teste: `testes/backup_envio.php`.
- **E-mail**: `smtp.intusfit.com.br` é CNAME de `smtp-vip.kinghost.net` e o certificado é `*.kinghost.net` (GlobalSign, até 04/2027), então conferir o nome contra o apelido sempre falhava (erro 0). `_smtp_seguro.php` (`intusSmtpAbrir`) confere a cadeia e o NOME REAL (destino do CNAME), tenta 465 e depois 587 com STARTTLS, e explica o certificado quando falha. Usado pelo backup, `aluno_login.php`, `usuarios.php` e `_catalogoSendEmail`. **Modo estrito** (painel: "Testar os dois e-mails e o DNS", depois "Ligar o modo estrito", guardado em `intus_secrets.backup_config.smtp_estrito`): com ele ligado, e-mail não sai por conexão sem certificado verificado; desligado, cai para o comportamento antigo e anota em `email_erros.log`.
- **DNS do e-mail (conferido por consulta pública)**: MX nos servidores da KingHost, SPF ok (`include:_spf.kinghost.net`), **sem DMARC** (recomendado criar TXT `_dmarc` com `v=DMARC1; p=none`). O encaminhamento do e-mail do site para o Gmail e o "Enviar como" do Gmail NÃO são visíveis pelo código: o teste manda uma mensagem para cada endereço e a caixa de destino prova.

## 71. Proteções, rodada 2 (14/10/2026)
- **Senha nunca no navegador**: `Usuarios._save` (`_mock.js`) zera `senha` de todo usuário antes de gravar em `intus-usuarios` (apaga também o que versões antigas deixaram), `api.js` não guarda mais a senha digitada ao criar/editar usuário, e a tela de usuários mostra aviso no lugar da "senha atual". `_mock.js?v=20261014d`, `api.js?v=20261014c`. Os textos com senha antiga em `CLAUDE.md` viraram texto aleatório (o histórico do git ainda os contém: a senha mestra precisa ser trocada, o Luiz já vai fazer).
- **IP real (Cloudflare)**: `_ip.php` (`intusClientIp`, `intusClientGeo`) só confia em `CF-Connecting-IP` e nos cabeçalhos de localização do Cloudflare quando `REMOTE_ADDR` está nas faixas publicadas do Cloudflare; antes disso nada muda. Trava de tentativas, sessões, OTP, leads e cadastro usam `intusClientIp()`. Teste: `testes/ip.php`. **Ao ligar o Cloudflare, ativar "Adicionar cabeçalhos de localização do visitante" (Regras > Transform Rules > Managed Transforms) para vir cidade, região e fuso.**
- **Arquivos enviados**: `_antivirus.php` (`intusAntivirus`) usa o ClamAV se o servidor tiver (a hospedagem compartilhada não tem), reconhece o teste EICAR e recusa arquivo com código escondido (`<?php`, `<script`, ASP, shell). Valendo em `_intusExtImagem` (fotos do Feed, avaliações, etc.), `_intusImagemOk` (avatares em base64), capa de vídeo (`midia.php`) e `relatorios_fotos.php`. Antes, imagem com PHP no fim era aceita (guardada como .png, que não executa); agora é recusada. Vídeos vão direto para o Drive e não passam pelo disco do servidor.
- **CORS do login antigo** (`auth.php`, `config/cors.php`): deixou de devolver `*`, usa a lista restrita de `_cors.php`. **CSP parcial** no `.htaccess` (`base-uri`, `object-src`, `frame-ancestors`); a CSP completa de scripts exige tirar os scripts embutidos.
- **ACHADO**: `app/config/smtp.php` está RASTREADO no git (snapshot inicial) e traz a senha do e-mail; o repositório é privado, mas a senha está no histórico. Não dá para simplesmente tirar do repositório: o deploy apagaria o arquivo no servidor e derrubaria todos os e-mails. Caminho seguro: mover as credenciais de SMTP para `intus_secrets` (como a chave do Drive), com tela em `admin/`, e só depois trocar a senha do e-mail na KingHost e tirar o arquivo do git.

## 72. Histórico de acessos, tela de acessos do admin e quadro da Frequência (14/10/2026)
- **Tabela nova `intus_acesso`** (autorizada pelo Luiz em 14/10/2026; só recebe linhas; criada em `_acesso.php` na primeira chamada): `user_tipo` (aluno | staff), `user_id`, `user_nome`, `evento` (login | abertura | treino | cardio), `ref_id` (idsessao), `ip`, `pais/regiao/cidade/fuso_cf` (Cloudflare), `fuso_local` (fuso do aparelho do aluno), `aparelho`, `criado_em` em **UTC**.
- **Quem grava** (nunca derruba o fluxo, falha em silêncio): `createSession` (`_sessions.php`, evento login); o sino `catalogo.php?action=notificacoes` GET (evento abertura, no máximo 1 a cada 20 min por pessoa; é consultado em toda tela do app e do painel, e o cliente manda `&tz=` com o fuso do aparelho); `treinos.php` ao registrar sessão de musculação ou cardio, **só quando quem registra é o próprio aluno** (treino lançado por professor não vira acesso do aluno). Treino e cardio herdam o último `fuso_local` informado.
- **Rotas** (`_acesso_rotas.php`, incluído pelo `catalogo.php`): `?action=acessos` só administrador (linha do tempo e resumo por pessoa, com IP); `?action=acessos_painel` equipe (tempo real, picos por hora em Brasília e no fuso de cada aluno, dia da semana, locais, fusos, últimos registros), escopo = os alunos que a pessoa pode ver (`getAtletasDoUsuario`), **IP só para administrador**.
- **Telas**: Configurações > Servidor > card "Acessos dos usuários" (`verAcessos`); Frequência e Ranking > botão "📡 Acessos" (`renderAcessosView`, atualiza a cada 15 s só a área do quadro). Sem Cloudflare não há cidade/país/fuso do site: aparecem horário, IP e aparelho, e o painel explica como ativar. Teste: `testes/acessos.php` (22 conferências; tirar o filtro do professor é acusado).
- **Aviso de país novo**: login de administrador vindo de um país que não aparecia nos últimos 90 dias manda e-mail (`intusAcessoAlertar`, só funciona com o Cloudflare informando o país). É a alternativa de baixo atrito à verificação em duas etapas, que o Luiz achou trabalhosa (decisão de 14/10/2026: aluno nunca terá; equipe só se ele pedir depois).
- **Permissões no servidor**: cadastrar cliente (`atletas.php` POST) agora exige `at_incluir` (administrador sempre pode). As demais chaves `*_incluir` e `*_editar` continuam só no navegador de propósito: várias telas (avaliações, nutrição) editam o cadastro como efeito colateral com a permissão da própria área, e travar no servidor sem testar com contas reais de professor poderia quebrar fluxos em produção. Fazer chave por chave com teste ao vivo.
- **Cloudflare**: roteiro completo em `tools/cloudflare/LEIAME.md` (registros de e-mail em "somente DNS", DMARC, Registro.br, "Add visitor location headers", regra de cache e de limite; Bot Fight Mode desligado por causa dos webhooks da Meta e da Eduzz). A troca é do Luiz (contas dele).

## 73. SMTP no banco, com tela no admin (14/10/2026)
- O valor de verdade do e-mail do site agora é `intus_secrets.smtp_config` (host, port, user, pass, from_name, notificar), lido por `intusSmtpConfig()` em `app/api/_smtp_config.php`; o banco manda e o arquivo antigo `app/config/smtp.php` só completa o que faltar (troca sem queda). Todos os leitores (`aluno_login.php`, `catalogo.php`, `usuarios.php`, backup, acessos, `_smtp_seguro.php`) passaram a usar a função.
- **Tela**: `admin/smtp-config.php` (login do admin, CSRF, nunca mostra a senha). Botão "Importar do arquivo atual" copia do arquivo para o banco no servidor sem a senha passar pela tela. Campos para servidor, usuário, senha, nome do remetente e e-mail dos avisos de cadastro; "Enviar e-mail de teste" com o motivo exato do erro. Link em Configurações > Servidor > Backups.
- **Sequência do Luiz**: abrir a tela > Importar > trocar a senha do e-mail na KingHost > digitar a nova senha na tela > Salvar > testar. Só depois disso `app/config/smtp.php` pode sair do git (a senha antiga fica inútil). Retirar do git antes disso derrubaria os e-mails: o deploy apaga o arquivo do servidor.
- Os dumps do banco passam a conter a senha do e-mail (como já continham a chave do Drive): manter a pasta do Drive e a caixa do aviso de backup restritas.

## 74. Cloudflare ligado (14/10/2026)
- Domínio ativo no Cloudflare (plano Free) desde 14/10/2026; servidores `molly.ns.cloudflare.com` e `ned.ns.cloudflare.com` no Registro.br (o provedor KingHost foi removido do cadastro do domínio, que passou à administração direta do Registro.br; **o domínio vence em 14/05/2027 e a renovação é paga no Registro.br**). DNSSEC desligado.
- DNS: só `intusfit.com.br` (A e AAAA) e `www` em Proxied; `autoconfig`, `autodiscover`, `ftp`, `imap`, `mail`, `pop`, `smtp`, `webmail`, os MX e os TXT em DNS only. TXT `_dmarc` criado (`v=DMARC1; p=none; rua=mailto:contato@luiznunest.com.br`).
- Configurado por Claude no navegador com a sessão do Luiz: SSL **Full** (não strict, de propósito: o certificado de origem é Let's Encrypt curinga, vence em 09/12/2026 e a renovação por DNS pode falhar com o DNS no Cloudflare; strict tiraria o site do ar com erro 526; **perguntar à KingHost como o curinga renova com DNS externo e, se renovar, dá para passar a strict**), Always Use HTTPS, TLS mínimo 1.2, **Add visitor location headers** (ativa cidade, país e fuso nos acessos), Cache Rule "API sem cache" (`/app/api/` com Bypass cache) e Rate limiting "Login com limite" (POST em `aluno_login.php` e `auth.php`, 10 pedidos em 10 s por IP, bloqueio de 10 s; o plano gratuito só permite 10 s e não filtra por query). Bot Fight Mode desligado (webhooks da Meta e da Eduzz).
- Conferido por fora: `Server: cloudflare`, API com `cf-cache-status: DYNAMIC`, http redireciona para https, login e painel respondem, MX, SPF e DMARC no DNS público. A trava de login do próprio site continua agindo primeiro (5 erros por IP).
- **`app/config/smtp.php` saiu do git (14/10/2026)**, depois de o Luiz importar o SMTP para o banco, trocar a senha do e-mail na KingHost e digitar a nova em `admin/smtp-config.php`. O arquivo está no `.gitignore`. O deploy apaga a cópia do servidor; o e-mail passa a depender só de `intus_secrets.smtp_config`. A senha antiga continua no histórico do git, mas já não vale. Se um dia o banco perder essa linha, o e-mail para: recadastrar em `admin/smtp-config.php`.
- **Varredura de 14/10/2026 (resultado)**: nenhum arquivo sensível exposto (config, logs, `.md`, `tools`, `testes`, `mobile`, bibliotecas `_*.php`: 403/404), sem listagem de diretório, tokens forjados e legados dão 401, CORS recusa origem estranha, cookie do admin `Secure/HttpOnly/SameSite`, TLS 1.1 recusado, sem chave privada real no histórico do git (só código que cita o texto), `maximizeapp.com.br` não responde mais, suíte inteira passando. Achados corrigidos: pasta velha `app/_removido-p-8f3k2-20260905/` (cópia do painel de maio, ainda servida pelo servidor) agora dá 403 (`app/.htaccess` e raiz; **as regras do `.htaccess` da raiz NÃO se herdam em `app/`, que tem regras próprias: bloqueio novo precisa ir nos dois**), trava de login do admin por IP real, botão para apagar acessos com mais de 180 dias (só admin, confirmação, `DELETE catalogo.php?action=acessos&dias=N`, mínimo 30; a tabela `intus_acesso` cresce ~1 linha por pessoa a cada 20 min).
- **Ainda em aberto**: permissões `*_incluir`/`*_editar`/leitura só no navegador (exceto `at_incluir`), CSP completa (scripts embutidos), token de sessão no `localStorage`, certificado de origem curinga vence em 09/12/2026 (SSL em Full, não strict), CronJob da KingHost não contratado (backup sai pela carona e pelo botão), apagar a pasta velha pelo gerenciador de arquivos da KingHost, e `npm run sync` + novo build nas lojas (o `sync` já foi rodado em 14/10).

## 75. Permissões de escrita conferidas no servidor (15/10/2026)
- Antes, só o painel escondia os botões: um professor com conhecimento técnico gravava por chamada direta à API. Agora `intusPermissaoEscrita`/`intusExigirPermissaoEscrita` (`_auth_context.php`) exigem, para POST e PUT, `<área>_incluir` OU `<área>_editar` (várias telas gravam com a chave vizinha) e, para DELETE, `<área>_excluir`. Administrador sempre passa; aluno não passa por esta regra (tem as próprias travas); GET não é checado.
- Onde vale: `treinos.php` (fichas, treinos e `treinos_replace` = `tr`, catálogo de exercícios = `ex`), `mensalidades.php` (`mt` ou `pg`, POST e PUT; DELETE já tinha a regra), `catalogo.php` (alongamentos `al`, avaliações `av`, plano e conteúdo de nutrição, alimentos e medidas `nt`; apagar o próprio alimento ou medida só exige poder editar nutrição) e `atletas.php` (POST = `at_incluir`; PUT = `at_editar`, com três exceções de efeito colateral: só `notas_professor` e `origem` [lead que ganha matrícula], ou só `stbloqueio` feito com permissão de matrícula/pagamento, que é como o Financeiro bloqueia o aluno ao encerrar um plano).
- **Se um professor reclamar de 403 "sem permissao para esta acao (xx)"**: falta a chave `xx_incluir/editar/excluir` dele em Usuários (o painel já escondia o botão; agora o servidor também recusa). Não afrouxar o servidor: ajustar a permissão do usuário. Teste: `testes/permissoes.php` (23 conferências, inclui a checagem de que cada endpoint continua chamando a regra).
- **Ainda sem checagem de escrita no servidor**: mensagens (`ms_`), desafios e dashboard (sem chave no painel). Leitura (`*_ver`) segue só no navegador, mas o escopo por aluno responsável (`getAtletasDoUsuario`) já é aplicado no servidor.
- `display_errors` é desligado em `_ip.php` (a KingHost deixa ligado por padrão e um aviso do PHP vazaria caminhos e nomes de tabela). Estado da KingHost em 15/10/2026: PHP 8.2 em php-fpm (2 processos de 128 MB), certificado Let's Encrypt gerenciado e renovado por eles, CronJob (R$ 7,90 por 20 tarefas) e Backup recorrente (R$ 10,90/mês) NÃO contratados, antivírus de arquivos disponível no painel (varredura manual, 3 por dia, move suspeitos para quarentena), MySQL na própria conta (1 base, 62 MB).

## 76. Travas opcionais e rotinas automáticas (15/10/2026)
- **Travas opcionais** (Configurações > Servidor > "Travas de segurança opcionais"; `intus_secrets.seg_config`; `catalogo.php?action=seguranca_config`, só administrador; nascem DESLIGADAS): (1) **mensagens**: `ms_ver`, `ms_incluir` e `ms_excluir` passam a valer no servidor (`mensagens.php`), mas só NEGAM quando a chave está explicitamente desligada no usuário (quem nunca teve a chave tocada segue com o chat), e a tela lista quem perderia o chat antes de ligar; (2) **desafios só do administrador**: criar, editar e apagar desafios, planos, participantes e professores (`desafios.php`, usa `_intusExigeSessao($pdo, true, true)`). Funções em `_auth_context.php` (`intusSegConfig`, `intusSegGravar`, `usuarioPermissaoNegada`, `intusUsuariosPermissoes`). Teste: `testes/seguranca_config.php`.
- **Rotinas automáticas** (`_rotinas.php`, gatilho `_carona_rotinas.php` no sino do painel, no máximo 1 vez a cada 6 h, depois da resposta, só com `fastcgi_finish_request`; estado em `config/rotinas.json`; e-mails para o endereço do aviso de backup): vigia de backup parado (2+ dias, 1 e-mail por dia), **conferência semanal** da última cópia do Drive (`backupConferir`; e-mail só se reprovar), vigia do certificado do servidor (menos de 15 dias, 1 e-mail a cada 3 dias) e **resumo mensal** (backup, conferência, certificado, logins por tipo e país, acessos guardados, travas). A situação aparece em Backups > "Rotinas automáticas". Como o backup, só roda se alguém da equipe abrir o painel; para rodar sem ninguém, seria preciso um agendador externo (CronJob da KingHost, R$ 7,90, ou um Worker do Cloudflare com token). Teste: `testes/rotinas.php` (17; mudar o intervalo é acusado).
- **Agendador do Claude (app desktop)**: tarefa mensal de varredura externa sem login (arquivos expostos, certificado, DNS, DMARC, cabeçalhos, suíte de testes). Só roda com o computador ligado e o app aberto; não acessa o painel, o Drive nem o banco.

## 77. Plano de hospedagem migrado para o Hospedagem III (15/10/2026)
- O Luiz migrou da Hospedagem II (2 processos de 128 MB, 26 GB) para a **Hospedagem III**: **4 processos PHP de 128 MB (512 MB)**, 36,3 GB de disco, MySQL com 12,5 GB alocados (1 base, 62 MB em uso). Conferido no painel da KingHost no mesmo dia: o limite de processos do php-fpm está em 4 (a própria KingHost recomenda 4), PHP 8.2, Publicação via GIT intacta (`git@github.com:intusfit/intusfit-app.git`, branch `main`, pasta `/www/`), servidor de origem continua `web1108` no IP 191.6.209.210 (o A do Cloudflare não precisou mudar), certificado Let's Encrypt de origem intacto (vence 09/12/2026), MX e e-mail iguais, login responde `credenciais invalidas` em JSON (o banco responde). Consumo depois da migração: processamento 15% e memória 3%, "consumo normal", diagnóstico indica Hospedagem III como recomendada.
- Medido de fora: rajada de 40 pedidos PHP com 8 em paralelo, tempo médio 0,29 s e máximo 0,47 s, nenhum erro. Os contadores do pool ("Máximo de processos atingidos 2", alertas "Estouro de Pool" e "Scripts lentos") são o histórico antigo e só vão mostrar o efeito real da mudança depois de alguns dias de uso, especialmente nos picos de treino (fim da tarde e noite, ver o quadro Acessos da Frequência).
- Com 4 processos, o backup e as rotinas automáticas (que seguram 1 processo por alguns minutos) pesam metade do que pesavam. Continua valendo evitar rotas longas e manter o SMTP com limite de espera. O CronJob (R$ 7,90) e o Backup recorrente da KingHost (R$ 10,90) continuam não contratados.
- **Conferir deploy por hash agora é direto na origem**: o Cloudflare injeta um script de ofuscação de e-mail (`email-decode.min.js`) em páginas que têm endereço de e-mail e muda os bytes, então o hash pelo domínio público pode diferir mesmo com o deploy certo. Use `curl -s -k --resolve intusfit.com.br:443:191.6.209.210 https://intusfit.com.br/CAMINHO | sha256sum` (sem passar pelo Cloudflare). A opção "Email Address Obfuscation" do Cloudflare (Security > Settings) foi deixada LIGADA: o classificador do Claude bloqueou desligar por reduzir proteção contra robôs de spam; se o Luiz quiser desligar, é uma decisão dele.

## 78. Banco: já é MySQL 11.4; conversão das tabelas antigas para InnoDB e utf8mb4 (15/10/2026)
- **Correção de uma informação antiga**: o banco de produção NÃO é MySQL 5.6. O painel da KingHost mostra "Versão do servidor: MySQL 11.4" (MariaDB), base `intusfit` ("Recuperacao Intus 2026-09-05"), host `mysql.intusfit.com.br` (alternativo `mysql50-farm1.kinghost.net`). Não há migração de versão a fazer.
- **O que sobrou do sistema antigo**: 23 de 91 tabelas eram MyISAM e/ou latin1 (`atleta`, `professor`, `mensalidade`, `treino`, `exercicio`, `serie`, `pagamento`, `fichatreino`, `composicao`, `perimetros` e outras; só 453 linhas no total). MyISAM trava a tabela inteira a cada gravação, não se recupera de queda no meio de uma gravação e latin1 não guarda emoji. As 68 tabelas novas já eram InnoDB `utf8mb4_general_ci` (por isso o destino é essa mesma collation: misturar daria "Illegal mix of collations"). Sem triggers, views, foreign keys nem fulltext.
- **Ensaio em cópia real** (MariaDB local, cópia do Drive de 15/10/2026): as 23 tabelas converteram em ~0,06 s cada, com a mesma impressão digital (contagem + soma de CRC32 do conteúdo normalizado em utf8mb4) antes e depois, `AUTO_INCREMENT` preservado, emoji e "Ł" aceitos, JOIN entre tabela convertida e moderna sem erro de collation, nenhuma tabela ficou com acento quebrado.
- **Ferramenta** (`_conversao_tabelas.php`; rota `catalogo.php?action=conversao_tabelas`, só administrador; tela em Configurações > Servidor > "Tabelas antigas do banco"): converte UMA tabela por vez. Antes cria `conv_bak_<tabela>` (cópia), confere a impressão digital, faz `ALTER TABLE ... ENGINE=InnoDB, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci`, confere de novo e, se o conteúdo mudar, devolve a tabela antiga por RENAME (a convertida fica como `conv_falhou_<tabela>`). **Nunca apaga nada**: as cópias `conv_bak_*` ficam até o administrador apagar pelo phpMyAdmin. O servidor recusa converter se o último backup do banco tem mais de 24 h. Cada conversão fica no `config/backup.log`. Teste: `testes/conversao_tabelas.php` (a parte com banco roda com `INTUS_TESTE_MYSQL="host:porta|usuario|senha"`; na suíte normal só a parte pura roda).
- Depois de converter tudo e de alguns dias de uso sem problema, apagar as `conv_bak_*` pelo phpMyAdmin (o backup diário também as leva no dump enquanto existirem).

## 79. Senha mestra trocada e tela de sessões ativas (15/10/2026)
- O Luiz trocou a senha mestra do administrador em 15/10/2026. A senha antiga ficou publicada no `_mock.js` por um tempo e continua no histórico do git, agora sem valor.
- **Trocar a senha NÃO derruba as sessões já abertas** (cada login cria um token válido por até 7 dias em `intus_sessions`). Por isso Configurações > Servidor ganhou o card **"Sessões ativas"** (`verSessoes`, `encerrarSessoes`; usa `catalogo.php?action=sessoes`, que já existia e é só do administrador): lista as contas conectadas (sessões, último uso, IPs) e "Encerrar" derruba todas as sessões da conta; nenhum dado é apagado. **Regra de rotina: depois de trocar qualquer senha de equipe, encerrar as sessões daquela conta.** Encerrar sessão continua sendo ação manual pelo painel, nunca por script.

## 80. Código da Definição (CDD) dentro da Intus: desenho aprovado (15/10/2026)
Só desenho, nenhum código ainda. Fase 0 e 1 em `C:\Users\User\IntusFit\cdd-descoberta\` (fora do repositório; Artifact do desenho: https://claude.ai/artifact/KNpfLE2EoHujYf4jXnPRMH). A construção será em branch `cdd`, começando por arquivos novos; `aluno.html`, `catalogo.php` e `api.js` só com aviso ao Luiz.
- **REVISÃO DO LUIZ (15/10/2026, vale sobre o resto desta seção): nada da Intus pode ser mexido.** A Intus serve só de referência. O CDD é construído primeiro como sistema próprio, em pasta nova (`cdd/`), com tabelas próprias de prefixo `cdd_` (só aditivas, autorização antes de rodar), login e sessão próprios e cópia (não `include`) dos auxiliares que precisar. Nenhum arquivo da Intus é editado (nem `_mock.js`, `api.js`, `catalogo.php`, `aluno.html`, `.htaccess` existentes). **Só depois de o CDD estar bem estruturado** fazem-se as conexões de feed, ranking, matrículas e conta única, por uma tabela de vínculo; o desenho abaixo de "um sistema, vários programas" fica como destino dessa conexão, não como ponto de partida.
- **Endereço**: durante a construção o CDD roda em `intusfit.com.br/cdd/`. O destino é tudo no domínio próprio `codigodadefinicao.com.br`, e `intusfit.com.br/cdd` passa a refletir o que existe lá (mesma pasta servida nos dois domínios, ou redirecionamento, dentro de `cdd/`). Por isso o código do CDD usa só caminhos relativos e uma base configurável, sem domínio fixo. Cookie de sessão é por domínio: o login vale em um domínio por vez.
- **Arquitetura (destino)**: um sistema só com vários programas (Intus, CDD e os que vierem). Programa tem marca, público, conteúdo e domínio. Matrícula por programa (status, origem, função). Tabelas novas só aditivas, com autorização do Luiz antes de rodar.
- **Fonte do conteúdo**: Nutror (19 módulos, 85 aulas, todas em vídeo do YouTube) e Drive (planilhas de treino, PDFs). Os IDs das planilhas na Nutror são os mesmos do Drive. 11 protocolos (o #11 Bikini 2.0 só no Drive).
- **Vídeo**: continua no YouTube embutido (decisão do Luiz). Sem servidor de vídeo próprio por enquanto.
- **Fichas de treino**: serão convertidas das planilhas para fichas dentro do sistema, no modelo das fichas e alongamentos que a Intus já tem. Isso exige ler as ~35 planilhas (exportar é download, só com o sim do Luiz para cada lote).
- **Conteúdo editável**: o Luiz edita módulos, aulas e bônus num painel admin do CDD, inclusive trocar aulas de tema sensível por outras sem risco.
- **Método Marlene Mariano**: criação própria, entra como módulo ou bônus, pode virar app à parte no futuro.
- **Marca**: identidade visual atual do CDD (logos e banners do Drive). Comunicação, promessas e branding a trabalhar à parte.
- **Login**: conta única; quem tem matrícula nos dois programas troca de programa na tela.
- **Comunidade**: ranking e feed exclusivos do CDD e da Intus, mais ranking e feed gerais de todo o ecossistema (previstos para programas futuros). O treino pertence à pessoa e conta uma vez; cada ranking é uma lista de membros. Trava de exclusividade feminina no servidor.
- **"Só mulheres"**: o cadastro mantém a escolha do sexo. Quem escolher masculino recebe aviso da exclusividade do CDD para mulheres e a indicação da Intus.
- **App nativo**: dois apps independentes nas lojas (identificadores e marcas diferentes), mesma base de código. Quem tem um app acessa o outro programa se tiver matrícula.
- **Domínio**: `codigodadefinicao.com.br` pelo Cloudflare, mais página de vendas e captura nova. Ticket KingHost 411645 (bloqueio) ainda a resolver. Sem plano de hospedagem novo por enquanto; medir o pool de 4 processos.
- **Alunas atuais**: ficam na Nutror até o acesso expirar; só alunas novas entram no sistema novo. Nunca migrar senha.
- **Pagamento**: NÃO vincular a Eduzz ao sistema por enquanto. O Luiz pretende trocar de plataforma de recebimento e integrar depois. Até lá a matrícula é manual ou cortesia. Desenhar a entrada de matrícula independente de plataforma (tabela de eventos e origem), para ligar o webhook novo depois.
- **Ordem**: conteúdo e acesso primeiro; comunidade com escopos e app depois.
- Dados de alunas (pastas de alunas, contratos, anamneses, fotos) não foram abertos e não são necessários para o desenho.

## 81. CDD: MVP no ar em intusfit.com.br/cdd (15/10/2026)
Pasta nova `cdd/` (nenhum arquivo da Intus foi editado). Endereços: `/cdd/` (aluna), `/cdd/painel.html` (equipe), `/cdd/termos.html` (rascunho jurídico), `/cdd/api/api.php?a=<rota>` (API).
- **Código**: `cdd/api/_nucleo.php` guarda TODA a regra (`cddTratar`), `api.php` é só a porta de entrada; `cdd/cdd.js` e `cdd/cdd.css` são compartilhados pelas telas, com caminhos relativos (funciona em qualquer domínio). `cdd/api/dados/seed-conteudo.json` é a estrutura levantada na Nutror (20 módulos, 86 aulas, 83 com vídeo). Gerador em `C:\Users\User\IntusFit\cdd-descoberta\gerar-seed.py` (fora do repositório). O único vínculo com a Intus é `require_once app/config/db.php` (só para as constantes do banco); nada de `include` de código da Intus.
- **Tabelas novas** (autorizadas pelo Luiz em 15/10/2026, criadas sozinhas no primeiro acesso, só recebem linhas): `cdd_config`, `cdd_aluna`, `cdd_sessao`, `cdd_tentativa`, `cdd_ativacao`, `cdd_matricula`, `cdd_evento`, `cdd_vinculo_intus` (vazia, reservada para a conexão futura com a Intus), `cdd_modulo`, `cdd_aula`, `cdd_anexo`, `cdd_ficha_item`, `cdd_progresso`. Entram no backup diário (que despeja o banco inteiro).
- **Regras**: sessão própria (token de 32 bytes, só o hash no banco, cabeçalho `X-CDD-Token`, 30 dias aluna e 7 dias administrador); senha em bcrypt, mínimo 8; trava de tentativas por IP e por conta; cadastro só para `sexo = feminino` (masculino recebe aviso de exclusividade e o link da Intus, e nenhuma conta é criada; checado no servidor); conteúdo só com matrícula ativa (`cdd_matricula`: ativa, dentro das datas) ou administrador; rascunho nunca chega à aluna; anexos só `https://`; texto das aulas é texto puro (escapado na tela).
- **Primeiro acesso**: `painel.html` > "Primeiro acesso" cria o administrador e FECHA a instalação. Só aceita o e-mail `CDD_EMAIL_INSTALADOR` (contato@luiznunest.com.br). Quem instala primeiro vira administrador: o Luiz deve fazer logo depois do deploy.
- **Liberar uma aluna**: painel > Alunas > Nova aluna (com matrícula) gera um link de ativação de 7 dias, uso único (só o hash fica no banco), para mandar pelo WhatsApp. Não há envio de e-mail nem "esqueci a senha" automático: nova senha = novo link pelo painel.
- **Conteúdo**: painel > Resumo > "Importar conteúdo" cria módulos e aulas do seed (idempotente, nunca apaga nem sobrescreve edição; aulas de esteroides, remédios e Molécula da Definição e o módulo do Método Marlene Mariano entram como RASCUNHO). Painel > Conteúdo edita módulos, aulas, anexos e fichas; ficha aceita "colar da planilha" (colunas Exercício, Repetições, Séries, Intervalo, Método; o link do "Ver vídeo" não vem na colagem do Google Sheets).
- **Fichas e base de exercícios (15/10/2026)**: as 24 planilhas de treino foram lidas (baixadas pelos links públicos, com OK do Luiz, em `cdd-descoberta/planilhas-tmp`, fora do repositório; extrator `cdd-descoberta/extrair-fichas.py`) e entram no seed: 235 itens de ficha com exercício, séries, repetições, intervalo, método e o vídeo "Como fazer" (YouTube) de cada um. "Importar conteúdo" cria a ficha só nas aulas que ainda não têm exercício (nunca sobrescreve edição). A orientação de frequência das planilhas ("Faça este treino de INFERIORES de 2 a 4x por semana") vira o texto da aula, e "Tarefa de casa" vira um grupo da ficha. Tabela nova **`cdd_exercicio`** (base de exercícios: nome, `nome_norm`, grupo, equipamento, descrição, vídeo, origem `cdd` ou `intus`, `id_intus`); a chave é o nome sem acento e pontuação (`cddNorm`). Na ficha da aluna, item sem vídeo usa o da base pelo mesmo nome; no editor da equipe o vídeo da base NÃO é gravado no item. Painel > Exercícios lista, busca, edita, cria e exclui; o campo de exercício da ficha sugere os nomes da base.
- **Sincronização com a Intus (fonte principal)**: o botão "Sincronizar com a Intus" faz UM `SELECT` em `intus_exercicio` (198 exercícios no backup de 15/10) e grava só em `cdd_exercicio`: nome, grupo, equipamento, descrição e vídeo da Intus substituem os do CDD quando existem; campo vazio na Intus nunca apaga o que o CDD tem; exercício criado só no CDD não é tocado. Nada é escrito na Intus (teste confere que a tabela da Intus fica idêntica). É a única leitura de tabela da Intus no CDD além das credenciais do banco.
- **Esquema versionado**: `CDD_ESQUEMA` (hoje 2) em `cdd_config.esquema`; `cddGarantirTabelas` roda os `CREATE TABLE IF NOT EXISTS` quando a versão do banco é menor (instalação do MVP sem a chave conta como versão 1). Tabela nova do CDD = subir `CDD_ESQUEMA`.
- **Armadilhas**: a pasta `img/` é ignorada pelo `.gitignore` da raiz (por isso os logos moram em `cdd/marca/`); ao editar arquivos com Python no Windows use `newline='\n'` (senão o git vê o arquivo inteiro mudado).
- **Rotina da aluna (16/10/2026, esquema 3)**: barra de baixo (Início, Treino, Jornada) e tela inicial com "Treino de hoje". Tabelas novas `cdd_treino`, `cdd_comentario`, `cdd_avaliacao` e duas colunas em `cdd_modulo` (`liberacao_dias`, `liberacao_data`, via `ALTER TABLE ... ADD COLUMN` só na tabela do CDD). Código novo em `cdd/api/_treino.php` (carregado por `_nucleo.php`).
  - **Treino com cargas e repetições**: `#/treino/<aula>` mostra a ficha, séries com carga e repetições (campos já com a última carga como dica), cronômetro, rascunho no aparelho (`localStorage`, por aula e dia), "Concluir treino" com esforço de 1 a 5 e comentário. Uma sessão por aluna, aula e dia (`UNIQUE idaluna, idaula, data`); salvar de novo no mesmo dia atualiza. A estrutura segue a `intus_sessao` da Intus: `itens` em JSON `[{exercicio, series:[{peso, reps}]}]`, `duracao_seg`, `comentario`, `divisao`; `idaula` equivale a `idficha`. Isso torna a conexão futura um mapeamento direto. Recorde pessoal por exercício (chave `cddNorm` do nome), só quando supera a maior carga anterior. Treino salvo marca a aula como concluída. O "treino de hoje" continua no módulo do último treino, na próxima ficha (A, B, C...), ou abre o primeiro protocolo liberado.
  - **Jornada** (`jornada`): dia do programa, treinos, treinos na semana (segunda a domingo), semanas seguidas, calendário de 8 semanas, linha do tempo dos módulos com estado, evolução de carga por exercício e treinos recentes.
  - **Liberação por tempo**: regra por módulo, em dias depois do início da matrícula e/ou data fixa (vale a mais tardia). O servidor trava aula, treino, comentário e avaliação de módulo trancado (403 `modulo_bloqueado`); a aluna vê o módulo trancado com a data. Editável no painel (Conteúdo > Editar módulo). Valores importados da Nutror (lidos em 15/10/2026 no painel do produtor, `my.nutror.com`, curso 246399, Conteúdo > Editar módulo > Agendamento): protocolo #3 após 20 dias, #4 após 45, #5 após 75, #6 após 100, #7 após 110, #8 após 140, #9 após 180, #10 em 03/11/2025 (data fixa); o resto na hora. Nada foi alterado na Nutror (só abri o formulário de edição e voltei, sem salvar).
  - **Comentários e avaliações** (versão enxuta do que a Intus tem, para um produto de entrada): estrelas de 1 a 5 por aluna e aula, comentários por aula com respostas de um nível, apagar o próprio, limite de 12 por 10 minutos, respostas da equipe aparecem como "Equipe CDD". Painel > Comentários (sem resposta ou todos, responder, ocultar) e etiquetas de nota, comentários e pendências em Conteúdo. Sem chat, DM nem feed (ficam para a conexão com a Intus).
  - **O que a Nutror tem e o seed não tem** (visível só na conta do produtor): aulas que a aluna não vê (Mentoria 4X, "Encontre as cargas ideais para os seus treinos", "Aprenda a respiração correta nos treinos", "Aprenda o TUT", "Ficha de Treino: Fase Ativação Metabólica", cardápios do Carnaval, 5 aulas do desafio no Mente Definida) e 6 módulos ocultos (Pós Páscoa, 21 Dias, Carnaval, Treinos ao vivo, Ceia de Natal, BBB), 25 módulos e 135 aulas no total. Não foram importados. Números agregados da Nutror (todos os cursos): 359 alunos; CDD: 123 alunos e 5 produtos associados.
- **Pagamento**: nenhuma integração (decisão do Luiz). `cdd_evento` (com `id_externo` único) é o ponto de encaixe para a futura plataforma.
- **Testes**: `php -d extension_dir=<ext do PHP> -d extension=pdo_sqlite -d extension=sqlite3 -d extension=mbstring cdd/testes/cdd.php` (172 conferências em SQLite na memória, sem tocar no banco real; inclui que o CDD não inclui código da Intus e que só toca tabelas `cdd_`). Servidor local: `cdd/testes/router-local.php` (SQLite temporário). A pasta `cdd/testes` responde 403 no servidor.
- **Pendências**: (1) logos CDD já em `cdd/marca/` (branca no tema escuro, preta no claro; marca vermelha com cadeado e barra); (2) todos os 83 vídeos de aula têm código; (3) conferir se os vídeos do YouTube permitem incorporação fora da Nutror; (4) texto jurídico (`termos.html` é rascunho); (5) fichas nativas a partir das ~35 planilhas (hoje cada aula de treino tem o link da planilha original como anexo); (6) `X-Robots-Tag: noindex` no `cdd/.htaccess` até o domínio próprio entrar no ar; (7) domínio `codigodadefinicao.com.br` (Cloudflare e KingHost, ticket 411645).

## 82. CDD v4: app de celular estilo Intus e Netflix (15/10/2026)
Tudo em `cdd/`, nenhum arquivo da Intus editado. Esquema do banco 4 (`CDD_ESQUEMA`), 213 conferências em `cdd/testes/cdd.php`.
- **App da aluna** (`cdd/index.html` casca + `cdd/app.js`, mobile primeiro): barra de baixo (Início, Aulas, Treino em destaque, Jornada, Ranking), tema claro/escuro sempre à vista (☀️/🌙 no topo, `cdd-tema` no `localStorage`), Início (treino de hoje, mini jornada, continue assistindo, quase lá), Aulas em capas verticais estilo Netflix (emoji + cor ou imagem `capa_url`, 🔒 com a data nos módulos trancados), Jornada em trilha (trilha, evolução, conquistas), Ranking (semana, mês, geral, pódio, opt-out `perfil_ranking`), Conta e **Anamnese dentro do app** (5 etapas, rascunho no aparelho e no servidor, envio confirmado pelo servidor).
- **Treino igual ao da Intus**: sem saída acidental (botão ✕ pede confirmação com frase motivacional, o voltar do celular e `beforeunload` também), cronômetro fixo, descanso que dispara ao marcar a série (alvo do intervalo, âmbar ao passar), séries com carga anterior, ± rápido, técnica (drop, rest-pause, cluster...), ⚡ super série (2), 🔥 triset (3), 🔄 circuito, histórico por exercício, tela ligada (wake lock renovado), rascunho por aula e dia, aviso de treino abaixo de 5 min que não conta ponto, esforço 1 a 5 e resumo com recordes e conquistas novas.
- **Conteúdo escondido**: módulos e aulas ocultos na Nutror entram como rascunho (`publicado = 0`): a aluna nunca vê, a equipe edita no painel. O seed tem 26 módulos e 136 aulas (54 escondidas). **Depois do deploy, clicar em "Importar conteúdo" no painel de novo** (traz os escondidos, capas e o tipo anamnese; nunca sobrescreve o que foi editado).
- **Painel** (`cdd/painel.html`): menu lateral (Resumo, Alunas, Conteúdo, Exercícios, Comentários, Anamneses, Ajustes), tema no cabeçalho, drawer no celular, editor de capa com emoji, cor e imagem, campo "Agrupar" (super série, triset, circuito) na ficha, aula tipo anamnese, lista de anamneses com respostas por etapa.
- **Regras novas no servidor** (`cdd/api/_app.php`): pontos e ranking com as regras da Intus (10, 5 se menos de 20 min, 5 o segundo treino do dia, bônus de 5 em 4 dias, semana de domingo a sábado, posição densa), 23 conquistas, anamnese de 16 campos em 5 etapas.
- **Ficou para depois**: cardio e nutrição no CDD, avaliação com foto dentro do app, app nativo, domínio próprio, conexão com a Intus.

## 83. CDD: cardio, água e refeições (15/10/2026)
- Esquema 5, tabelas novas (só aditivas, criadas sozinhas no primeiro acesso depois do deploy): `cdd_cardio`, `cdd_agua`, `cdd_refeicao`. Regra em `cdd/api/_bemestar.php`, rotas `cardio`, `cardio_salvar`, `cardio_excluir`, `nutri`, `agua`, `refeicao` (aluna com matrícula). Testes: 243 em `cdd/testes/cdd.php`.
- **Cardio** segue a fórmula da Intus (seção 56): base da atividade (MET) x esforço x minutos/30 x 0,55, teto de 7 por sessão, 10 min ou mais pontua, até 3 por dia (só sessões que pontuam entram na conta do limite). Esforço de 1 a 5 vira RPE de 2 a 10. Só hoje ou ontem. Os pontos somam no ranking do CDD (`cddPontosPorAluna`).
- **Nutrição** é um diário, sem plano alimentar individual: água (meta de 35 ml por kg do peso da anamnese, 2500 ml sem peso, copo de 250 ml) e 5 refeições por dia (fiz, em parte, pulei). Aderência: feito 1, parcial 0,5, pulei 0. Atalho para o módulo "Código da Alimentação".
- 6 conquistas novas (cardio 1, 10 e 30, meta de água 1 e 7 dias, 7 dias de diário), 29 no total. Telas `#/cardio` e `#/nutri` com cartões na Home.
- **Fica para depois**: programas guiados de cardio (HIIT, Tabata, cronômetro), cardio ao vivo por GPS, plano alimentar individual da nutricionista, foto da refeição.

## 84. CDD em servidor próprio (preparo, 15/10/2026)
- **Decisão do Luiz**: o CDD roda no plano da KingHost do próprio domínio (`codigodadefinicao.com.br/app`, servidor web10f80), com banco próprio; ranking e feed com a Intus entram depois por API entre servidores, sem banco compartilhado. Chamado de desbloqueio aberto: #3721217 (o bloqueio do domínio vem de 21/04/2024, chamado #3521626 "arquivos maliciosos em seu FTP", fechado sem resposta; o "411645" da faixa vermelha não aparece na lista de chamados).
- **Código**: `cddDB()` lê `cdd/api/config/banco.php` (fora do git; modelo em `banco.exemplo.php`; a pasta responde 403) com `CDD_DB_HOST/NAME/USER/PASS`. Sem esse arquivo continua usando o banco da Intus (hoje em produção). `cdd/.htaccess` só marca noindex fora do domínio próprio.
- **Publicação**: `bash tools/cdd/montar-publicacao.sh` monta o branch `cdd-site` (pasta `app/` sem testes, mais redirecionamento da raiz para `/app/`); `git push -f origin cdd-site`. No plano do CDD (Publicação via GIT da KingHost) apontar para o branch `cdd-site`, pasta `/www/`. A publicação NÃO usa FTP (foi por FTP que veio o malware).
- **DNS (Cloudflare)**: zona `codigodadefinicao.com.br` criada (plano Free, pendente) com só o registro `@` -> 191.6.209.210 (destino provisório). Quando o plano do CDD estiver liberado o destino passa a ser o IP dele (`web10f80`), `www`, e-mail da KingHost (MX, SPF, CNAMEs mail/smtp/imap/pop/webmail) e a troca dos servidores DNS no Registro.br (hoje `ns1/ns2.darth6010.com.br`, DNS público com erro) ficam para depois do desbloqueio. Não alterar DNS sem o Luiz acompanhar.
- **Integração futura com a Intus**: endpoint nos dois lados com chave compartilhada gerada pelo Luiz (eu não crio credenciais). O CDD envia à Intus os pontos e o resumo da aluna; a Intus devolve ranking geral e feed.

## 85. Refeição na Home, música junto nos programas, aviso do treino e correção do Voltar (16/10/2026)
- **Bug do Voltar nos programas de cardio (corrigido)**: com um programa rodando, abrir outro (ex.: Tabata durante o HIIT) sobrescrevia `_prog.id`, e o "Voltar" caía na execução do programa errado com o relógio do primeiro. Agora `abrirProgramaCardio` recusa com aviso e volta ao programa em andamento; o "← Voltar" do detalhe vai sempre à lista (`_progVoltarLista`); `_prog.tela` passa a valer `'execucao'` ao iniciar/retomar.
- **Cartão da refeição na tela inicial (opcional)**: liga em Nutrição ("Deixar a próxima refeição na tela inicial", só com plano ativo; preferência por aparelho em `intus-home-ref-<id>`). Ocupa a coluna da direita do bloco Ranking/Medalhas (por isso a Home não cresce); Nutrição e "Ver mais" viram uma faixa baixa embaixo. **Evoluções e Conquistas saíram da Home e ficam em "Ver mais"** (sempre, com ou sem o cartão). Regra única em `API.nutri.refeicaoDaHome(plano, regsDoDia, agora)`: só olha as refeições DEPOIS da última registrada, escolhe a mais perto do horário (empate: a que ainda vai chegar); estados `proxima`, `agora` (de 45 min antes a 90 min depois), `atrasada`, `completo`, `sem`. Botões Segui, Alterei e Fora gravam feito, parcial e fora pelo mesmo `_nutriSalvar` (fila offline incluída); após marcar, 6 s de "✓ Refeição · mudar" e já mostra a seguinte. Tocar no cartão abre o plano naquela refeição (`abrirRefeicaoDaHome`, `renderNutricaoAluno({plano, indice, deHome})`); o Voltar volta à Home. O plano é guardado resumido em `intus-home-ref-plano-<id>` para pintar na hora e funcionar sem internet. Teste: `testes/nutri.js` seção 9.
- **Minimizar (água e refeição)**: botão pequeno de seta no canto dos dois cartões. Preferência de 3 estados: `'1'` na Home, `'2'` minimizado (aparece como tile em "Ver mais", um toque fixa de novo), outro valor desligado. `homeMinimizar`, `homeRestaurar`, `_homeMaisHtml`.
- **Música em paralelo nos programas**: `intus-prog-audio` = `'musica'` (padrão) ou `'bloqueio'`, escolha na tela do programa e na tela de execução. Modo música NÃO cria o áudio silencioso nem a Media Session (eles tomam o áudio do celular e pausam o Spotify), usa `navigator.audioSession.type = 'ambient'` (iPhone) e mostra um aviso silencioso na barra. Modo bloqueio é o comportamento antigo (cartão de mídia, mas a música para). **Aviso silencioso de treino** (`statusAtivoMostrar/Parar`, id 92001): treino de musculação e programa em modo música; app das lojas = notificação local de importância baixa no canal `ativo`; navegador = notificação do service worker silenciosa; texto fixo (nome e hora de início) e limpeza de órfão 3 s depois de abrir. **Só vale no app das lojas depois de `cd mobile && npm run sync` e novo build; não testado em aparelho.** Música junto COM o cartão de mídia exigiria código nativo (sessão de áudio do iPhone, foco de áudio do Android), não feito.
- Versões: `api.js?v=20261016a` em todas as telas, `ajuda.js?v=20261016a`, `sw.js` cache `intus-v35`. Propostas de design do Cardio (telas de execução, botões, lista e tela Meu Cardio) estão num Artifact para aprovação; nada foi aplicado.

## 86. Cardio v2 aprovado, Home por modo e áudio com música (16/10/2026)
Escolhas do Luiz sobre o Artifact de propostas: tela de execução 2 (cor total), botões D, Meu Cardio opção 1 e lista no modelo 1.
- **Execução** (`_renderProgExecucao`): painel colorido grande no topo, tempos e "A seguir" num cartão, botões D (`.pg-dock`: Voltar, Pausar/Continuar maior, Pular, Encerrar; antes de iniciar, só "Iniciar"). **Aviso ao pular**: `_progIr(+1)` pergunta antes de um pulo que faria o programa ficar abaixo de 90% do tempo (`_progProjecao`, `_progAvisoPular`), com o texto de que perde o +1 ponto extra e os distintivos (o cardio comum continua valendo); depois de invalidado aparece uma faixa laranja fixa e não pergunta de novo. A regra dos 90% não mudou. Ajustar o aquecimento por atividade ficou como decisão pendente do Luiz.
- **Meu Cardio** (`renderCardio`): faixa pequena de novidade (some com ✕), resumo da semana pequeno (cardios que contam, minutos, pontos; domingo a sábado), Cardio ao vivo, grade com Programas (largo), "Registrar manualmente" e "Histórico de cardios". Os dois últimos abrem a seção logo abaixo (`cardioHubAbrir`, uma por vez; as seções são os mesmos `<details>` de antes, com a barra de título escondida por `.cd-oculto`, então os campos e o preenchimento pelo cardio ao vivo continuam iguais).
- **Lista de programas**: cartões como os de antes (ícone, título, tempo, 🧰 aparelho, nível) mais etiquetas (`_progEtiquetas`: Sem aparelho, Esteira, Bike, Elíptico), "Sugerido para hoje" (`_progSugerido`) e filtros que quebram em linhas, sem barra de rolagem lateral. Sem gráfico de esforço.
- **Home por modo** (substitui o que a seção 85 dizia): padrão (nada fixado) = Nutrição, Evoluções, Conquistas e "Ver mais", como sempre, com **miniaturas de Água e Refeição com "+"** em "Ver mais" para fixar na tela inicial (Refeição só aparece se o aluno tem plano). Com água ou refeição fixadas: Evoluções, Conquistas e Nutrição vão para "Ver mais" e o lugar da Nutrição mostra uma função sorteada a cada abertura do app (`_homeSorteio`, `_homeSeed`). Minimizar (setinha no cartão) devolve ao padrão e deixa a miniatura em "Ver mais". `_homeDirHtml`, `_homeMaisHtml`.
- **Áudio no modo "com a minha música"**: sem `<audio>` nem Media Session; o AudioContext é mantido vivo por um sinal inaudível (`_progKeepWebAudio`) e há o botão "🔊 Testar o som agora" no cartão de áudio. Relato do Luiz: sem som nenhum nesse modo. Causa provável: no iPhone pelo navegador, `audioSession.type = 'ambient'` obedece o botão lateral de silêncio (antes o `<audio>` ignorava), e o cartão agora avisa isso. **No app das lojas** o iOS foi configurado em `AppDelegate.swift` (`.playback` + `.mixWithOthers`: toca por cima da música, ignora o silêncio, segue vivo com a tela bloqueada), e o JS não força `ambient` no app nativo. Não testado em aparelho nem compilado (sem Mac). Cartão com botões e bips com tela apagada no Android exigem um serviço em primeiro plano nativo, ainda não escrito; no iPhone o cartão fixo junto com a música não é possível sem uma Live Activity (código nativo novo).

- **Tela do programa enxuta (16/10/2026, a pedido do Luiz: "muito texto, embaralhado")**: título, chips de tempo e nível, atividade, cartão de áudio curto (duas opções + "Testar o som"), botão Começar e duas seções recolhidas ("Como funciona e onde fazer" e "Ver todas as etapas"). Na lista o texto introdutório ficou numa linha e em Meu Cardio "Por que fazer cardio" ficou recolhido.

- **Home, correção (16/10/2026)**: o modo compacto (Evoluções, Conquistas e Nutrição em "Ver mais" e tile sorteado) vale SÓ com o cartão da refeição fixado. A água sozinha mantém o modelo padrão.

- **Rolagem nos programas (16/10/2026)**: abrir um programa a partir da lista herdava a rolagem da lista (a tela nova abria no fim) e voltar deixava tela fantasma no iPhone. `_progTopo()` sobe ao topo ao trocar de tela (detalhe, execução, lista) e a lista volta à posição em que estava (`_prog.listaY`).

- **Voltar do programa sem tela fantasma (16/10/2026)**: o detalhe de um programa agora empurra a própria entrada de histórico (`_prog.hist`) e o gesto de voltar do celular só desfaz ela e cai na lista. Antes o popstate empurrava de novo a mesma entrada e, no iPhone, o gesto mostrava a imagem de Meu Cardio por cima da lista, com toques abrindo programas. `_progLimparHist` desfaz a entrada quando se sai por botão (`window._ignorarPop` faz o popstate central ignorar essa volta). Testado: lista, detalhe, botão Voltar, gesto, iniciar e sair, e depois voltar até a Home, sempre com uma única tela ativa.

- **Execução, ajuste de leitura (16/10/2026)**: "A seguir" mostra 4 blocos em letra maior e o botão de música e o de voz viraram botões grandes, 30 px abaixo dos controles, para evitar toque errado.

- **Áudio do Cardio, novo padrão (16/10/2026, pedido do Luiz)**: o áudio do app vem LIGADO (bip e voz garantidos); o aluno escolhe "Mudo, só minha música" quando quer ouvir a própria música (sem bip nem voz, sem tomar o áudio do celular; vibração, cor da tela e aviso na barra continuam). `_progModoAudio()` devolve `mudo`, `mix` (app das lojas: o áudio do app toca por cima da música) ou `exclusivo` (navegador: áudio silencioso + cartão de mídia, a música dos outros apps pode pausar). Preferência `intus-prog-audio` (`mudo` ou `ligado`); os valores antigos `musica`/`bloqueio` viram ligado.

## 87. Treino e cardio à prova de perda e de duplicata (19/10/2026)
Pedido do Luiz: alunos relatando que só conseguiam postar o cardio indo direto no Feed, treinos que demoram para salvar, e a garantia de que ninguém perde registro nem marca treino duplicado.
- **Regra de ouro**: o treino ou cardio pronto fica guardado NO APARELHO antes de ir ao servidor e só sai de lá quando o servidor confirma. Reenviar é sempre seguro porque o servidor reconhece o mesmo registro. Código em `aluno.html`, bloco "Registros finalizados aguardando o servidor" (`_filaTreinos`, `_pendenteGravar`, `_enviarPendente`, `_cardioFilaAdd`, `_cardioEnviarItem`, `_cardioEnviarFila`, `_filaIndicador`).
- **Fila de treinos** (`intus-sessao-fila-<id>`): vários treinos, antes era um só (`intus-sessao-pendente-<id>`, ainda migrado ao abrir). Finalizar um 2º treino com o 1º sem enviar APAGAVA o 1º. O mesmo treino finalizado de novo (ficha, divisão e dia iguais) substitui o item.
- **Fila de cardios** (`intus-cardio-fila-<id>`): o cardio ia num `fetch` sem prazo, sem fila e sem repetir; com a conexão ruim virava "Registrado localmente" e nunca chegava ao ranking. Agora entra na fila, tenta 2 vezes (15 s e 25 s) e, se falhar, segue em segundo plano. A tela de resultado avisa que ficou guardado. A tela de esforço do cardio ao vivo, com o cardio ainda na fila, ajusta o esforço e a foto do próprio item da fila.
- **Quando reenvia**: ao abrir o app, ao voltar a internet (`online`), ao voltar para o app (`visibilitychange`) e a cada 40 s. Depois de 20 s aparece a faixa amarela "N registros guardados, aguardando envio. Toque para enviar agora" (`#fila-pend`). Recusa do servidor (4xx, menos 401/408/429) marca o item `bloqueado`: o envio automático não insiste, só o toque na faixa (nada é descartado). Prazos: aluno esperando 15 s + 25 s (antes 3 x 25 s = ~80 s de tela parada, que explicava "demora para salvar"); segundo plano 30 s.
- **Servidor (`treinos.php`)**: (1) o cardio ganhou proteção contra reenvio: mesma pessoa, dia, atividade, duração exata e `hora_conclusao` (o app manda a hora de quando terminou, igual nos reenvios) devolve o registro que já existe (`duplicata: true`); sem hora (app antigo), mesma coisa nos últimos 10 min. (2) o treino também reconhece o reenvio fora da janela de 30 min quando a `hora_conclusao` e os itens são iguais. (3) o bootstrap rodava 9 `ALTER TABLE ... ADD COLUMN` que falhavam a cada gravação (e a cada leitura) em `intus_sessao`; agora um `SHOW COLUMNS` decide e só altera o que falta (nenhuma coluna nova, nenhum dado mexido). (4) **achado de segurança**: POST de treino, POST de cardio, DELETE de treino, DELETE e PUT de cardio não conferiam de quem era o registro (qualquer pessoa autenticada podia registrar, apagar ou ajustar o de outro aluno pelo id); agora todos passam por `_checkAtletaAccess` (aluno só o próprio; professor só os alunos dele; administrador tudo).
- **Compartilhar cardio**: o cardio MANUAL não tinha o botão (só o ao vivo e os programas); agora a tela de resultado tem "📸 Compartilhar cardio". No cardio ao vivo o botão não gravava o esforço e a foto escolhidos (só "Concluir" gravava); agora grava antes de abrir. **Causa provável do "só consigo postar indo direto no Feed"**: Compartilhar treino/cardio liga a figurinha, que passa pelo `html2canvas`; se ele falhasse no aparelho, o post inteiro morria com "Não consegui publicar", enquanto o Novo post comum não usa figurinha. `_composeImagemFinal` agora publica só a foto (e avisa) quando a figurinha ou a logo falham. Não reproduzido em aparelho: se alguém ainda relatar, pedir o texto exato do aviso.
- **O que NÃO mudou**: o dia/ordem/pontos do treino continuam decididos no servidor; o limite de 3 cardios por dia também.
- **Testes**: `testes/fila.js` (Chromium com rede de mentira que cai, devolve 500 e 400: fila, reenvio igual, migração, compartilhar, figurinha que falha; 41 conferências, registrado em `rodar.sh`; quebrar a fila, a ordem do envio ou a proteção da figurinha de propósito é acusado) e `testes/permissoes.php` (as 5 conferências de dono do registro). Corrigida a asserção velha de `testes/seguranca_config.php` (a lista `$_SO_ADMIN` ganhou um item e o teste procurava o texto antigo). **Não há MariaDB neste computador**: o SQL novo do servidor (reconhecimento do reenvio) só foi conferido por leitura e pelo teste estático; vale conferir no ar com um cardio de teste.
- **App das lojas**: a fila vive em `aluno.html`, então só chega a quem instalou o app depois de `cd mobile && npm run sync` + novo build. O servidor (proteção contra duplicata, conferência de dono) vale na hora para todos. `sw.js` cache `intus-v36`, `ajuda.js?v=20261019a` (nova pergunta "Finalizei e apareceu erro de conexão. Perdi o treino ou o cardio?").

## 88. Tráfego e Captação: analytics das páginas públicas (19/10/2026)
Pedido do Luiz: métricas de acesso das landing pages, páginas de captura, links de indicação e teste grátis, "como um analytics completo". Feito sem serviço de terceiros e sem cookie de rastreio.
- **Rastreador** (`trafego.js`, na raiz do site; `<script src="/trafego.js" defer>` em `index.html`, `teste-gratis.html`, `app/painel/login.html`, `termos.html`, `privacidade.html`, `cdd/index.html` e no molde `admin/render-template.php`, então toda página nova feita no admin já nasce medida). Manda a visita e os cliques para `app/api/trafego.php` por `sendBeacon`. O visitante é um código anônimo no navegador (`intus-vid`, `localStorage`); a sessão é `intus-sid` (`sessionStorage`). **Não mede** quem ligou "não rastrear" nem quem está logado no painel neste navegador (`mx-user.tipo` diferente de aluno), para os testes da equipe não sujarem os números; **a visita à tela de login do painel conta** (ninguém está logado ainda), então esse número mistura equipe e alunos. UTM vale pela URL e, sem ela, pela sessão; a indicação (`?ref=`) vale por 30 dias.
- **Eventos automáticos**: `whatsapp` (rótulo = texto da mensagem do link, que identifica o plano), `acessar_app`, `cta` (links para `#form-lead`), e `data-trk="nome:rotulo"` em qualquer botão. A página do teste grátis manda o funil: `form_inicio`, `etapa1_ok`, `conta_criada` (valor = idatleta), `lead_enviado` (só quando a conta falha e cai no contato) e `email_ja_cadastrado`. **Pessoa captada = `conta_criada` + `lead_enviado`**. Nunca vai nome, e-mail, telefone nem senha.
- **Banco (autorizado em 19/10/2026, 2 tabelas novas, só recebem linhas, criadas na 1ª chamada)**: `intus_trafego_visita` (dia e hora em Brasília, página, canal, UTM, `ref_idatleta`, aparelho, navegador, sistema, país e cidade do Cloudflare, `novo`) e `intus_trafego_evento` (a origem da 1ª visita da sessão é copiada para o evento). **O IP não é gravado.** Limite de 200 visitas e 100 eventos por visitante por hora; visita repetida em 3 s é ignorada; robôs (`User-Agent`) são ignorados. A checagem de existência das tabelas roda uma vez por requisição (SELECT 1), não um CREATE a cada hit.
- **Código**: regra e SQL em `app/api/_trafego.php` (portável, roda em SQLite nos testes); `app/api/trafego.php` é o endpoint (público só para gravar; `relatorio`, `agora` e `limpar` só de administrador, conferido no servidor). Tela `app/painel/trafego.html` (item "Tráfego e Captação" no menu, só administrador; `_mock.js?v=20261019a` em todas as telas): visitantes, visitas, novos, cliques e pessoas captadas com comparação ao período anterior, gráfico por dia, funil do teste grátis, canais, páginas, campanhas UTM, **gerador de link com UTM** (para stories, bio, QR), ranking dos links de indicação por aluno, cliques e planos, sites de origem, aparelhos, navegadores, cidades, horário e dia da semana, "agora" (atualiza a cada 20 s) e a "Conferência com o banco": compara as contas criadas e os contatos sem conta que as páginas avisaram com os contatos de `intus_lead` vindos da página de teste grátis, e diz se batem (diferença pode ser de antes da medição, de quem bloqueia a medição ou de cadastro feito fora da página). **Apagar medições antigas** (cartão "Dados da medição guardados"): fluxo de 4 passos de propósito. (1) explica o que sai (só visitas e cliques medidos) e o que NÃO sai (clientes, leads, matrículas...) e escolhe guardar 12, 18, 24 ou 36 meses; (2) mostra a prévia com quantas visitas e cliques sairiam e quantos ficam; (3) duas caixas de ciência + digitar APAGAR; (4) botão final travado por 8 s. No servidor: `limpar_previa` só conta; `DELETE limpar` exige `dias` de pelo menos 365 (nunca apaga nada com menos de 12 meses), `confirmo=APAGAR` e `esperadas=<total da prévia>` (se o total mudou, devolve 409 e não apaga) e registra a limpeza no log do servidor.
- **Limites honestos**: mede só a partir da publicação (nada de antes existe). Cidade e país dependem do Cloudflare e podem faltar. Quem recusar o armazenamento do navegador conta como visitante novo a cada visita. Instagram e WhatsApp às vezes abrem o link sem informar de onde veio: por isso o gerador de UTM é o jeito confiável de medir cada story. `privacidade.html` (atualizada em 19/10/2026) ganhou o item "Visitas ao site e às páginas de cadastro" e a finalidade de medir o desempenho das páginas; o texto diz "mantidos só pelo tempo necessário", sem prometer prazo (a limpeza é manual, pelo painel). Qualquer mudança no que o rastreador coleta exige atualizar esse parágrafo.
- **Testes**: `testes/trafego.php` (49 conferências em SQLite: classificação, registro, repetição, limites, relatório, estrutura do endpoint) e `testes/trafego_front.js` (Chromium: o rastreador nas páginas reais, o funil, equipe logada e servidor fora do ar, e a tela do painel com dado de exemplo, inclusive texto malicioso numa campanha). Quebrar o filtro de robô, a repetição, a exclusão da equipe e o escape de HTML de propósito é acusado.
- **Ajuste de 19/10/2026 (2)**: a tela de Tráfego se **atualiza inteira a cada 1 minuto** (`INTERVALO_ATUALIZACAO_MS`, `atualizarSilencioso`): pula a rodada com a aba escondida, com o passo a passo de apagar aberto ou enquanto se digita no gerador de link, e preserva os campos do gerador, a seção aberta e a rolagem. O gráfico "Visitantes por dia" ficou com ~112 px de altura (era mais de 300), desenhado em pixels reais da largura da caixa (`pintarSerie`, barras com degradê, linha suave das captadas). O apagar dados ficou escondido em "Opções avançadas" no fim da página (`<details id="tf-avancado">`).

## 89. Central de atendimento: copys, funis, laboratório, referências, A/B e dashboard (19/10/2026)
Pedido do Luiz: uma página exclusiva (dele ou de um futuro assistente de atendimento) com as copys do teste grátis e das demais capturas, cronograma de mensagens, ferramentas para melhorar copy, campo de referências, testes de vários funis e um dashboard completo. **Endereço: `intusfit.com.br/atendimento/`** (link exclusivo; desde 20/10/2026 também o item **Atendimento** no menu do painel, só para administrador). Só administrador (mesmo login do painel); a ferramenta devolve 403 para professor. Liberar para um assistente de atendimento no futuro pede uma chave de permissão nova (`atd_ver`) em `usuarios.html`.
- **Arquivos**: `atendimento/index.html` (tela, 6 abas), `app/api/atendimento.php` (endpoint fino: login, admin, entrada e saída), `app/api/_atendimento.php` (TODA a regra), `app/api/_atendimento_conteudo.php` (só dados de fábrica), `testes/atendimento.php` (399 conferências, registrado em `rodar.sh`, junto com `wa.php`).
- **Nenhuma tabela nova.** O que o Luiz edita fica em `intus_config`, um JSON por chave: `atd_funis`, `atd_conceitos`, `atd_refs`, `atd_testes`, `atd_uso` (contagem de pedidos e tokens por dia) e `atd_arq_<id>` (PDF de referência em base64, dentro do banco, não em pasta pública). Enquanto nada foi salvo, a tela mostra o conteúdo de fábrica; "Restaurar" volta a ele. Chaves ficam em `intus_secrets` como antes (`anthropic_config`); a tela nunca mostra valor de chave.
- **Funis de fábrica** (editáveis): `teste-gratis` (os 7 textos que o Luiz aprovou em 08/10/2026, passos `t1..t7`, mesma agenda de `wa_agenda_do_teste`, conferida em teste), `chamou-whatsapp` (conversa de quem chama primeiro, texto livre na janela de 24 h mais uma reabertura por template), `indicacao` e `reativacao`. Só o `teste-gratis` está como "aprovado"; os outros três são RASCUNHO escritos por mim e esperam revisão dele. O funil fica codificado em `intus_wa_agenda.passo`: `t1..t7` para o teste grátis, `<funil>:<mensagem>` para os demais (`atd_passo`, `atd_passo_decodificar`).
- **Abas**: Painel (KPIs, série por dia, funis, etapas, desempenho de cada template, agenda e motivos de não envio, horário das respostas, uso do Claude; "resposta" = o contato escreveu em até 24 h depois da mensagem), Funis e copys (editar, ordenar, copiar pronto com variáveis de exemplo, copiar com `{{1}}` para a Meta, roteiro completo, JSON, duplicar como variante, nota das regras ao vivo), Laboratório (escrever com Claude em 5 modos, analisador grátis e instantâneo, base de conceitos editável: narrativa, dor, desejo, objeções, produtos, método, persuasão ética, estrutura, tom), Referências (links lidos pelo servidor, .txt/.md/.csv/.pdf até 3 MB, nota "o que aprender daqui", marcar "usar"), Testes A/B e Automação (prontidão, JSON único para a automação, templates a cadastrar na Meta).
- **Analisador de copy** (`atd_analisar_copy`, sem IA): travessão, "não é X, é Y", promessa de resultado/prazo/número, urgência falsa, dieta perto do teste, preço, assunto de saúde, clichês de IA, perguntas, emojis, caixa alta, tamanho, identificação como assistente virtual, SAIR na última mensagem e regras de template da Meta (1024 caracteres, variáveis em sequência, variável no começo ou no fim). **Achado**: quatro textos que o Luiz aprovou (t3, t5, t6, t7) COMEÇAM com `{{1}}`, e a Meta costuma recusar template assim; a tela avisa. Os textos aprovados foram mantidos como estão (não mexo em texto aprovado sem o OK dele); a correção é abrir com "Oi, {{1}}," e precisa de novo OK.
- **Claude** (`atd_gerar`): `claude-opus-5-5` lido de `wa_ajustes`, esforço `medium` explícito, `fallbacks: "default"` com o beta `server-side-fallback-2026-07-01` (se a API responder 400 por causa disso, repete sem o parâmetro), sem `thinking` (o Opus 5.5 recusa desligar). **Chamada por cURL puro, não pelo SDK oficial em PHP**: a hospedagem não tem Composer; se um dia houver, trocar `atd_http_claude`. Limite de 60 pedidos por dia, custo estimado por pedido na tela. As variantes voltam já conferidas pelo analisador. O sistema leva as regras da Intus, os conceitos escolhidos, os fatos de produto e as referências marcadas (até 40 mil caracteres de texto e 2 PDFs por pedido). **Precisa da chave da Anthropic em `admin/whatsapp-keys.php`; sem ela o botão fica desligado.**
- **Referências por link**: o servidor baixa a página (só http/https, portas 80 e 443, sem usuário na URL, host que resolve para IP público; cada redirecionamento é revalidado, no máximo 3; 1,5 MB) e guarda até 20 mil caracteres. YouTube e páginas que dependem de JavaScript não rendem texto: a tela avisa e pede para resumir na nota.
- **Testes A/B**: dois funis para o mesmo gatilho, parte para B de 5% a 95%, sorteio pelo telefone (`atd_variante`, crc32, a mesma pessoa cai sempre do mesmo lado), um teste rodando por gatilho, comparação por teste z de duas proporções só com 30 ou mais contatos por lado (`atd_comparar_taxas`), contando só contatos criados depois do início.
- **O que NÃO está ligado ainda** (a tela diz isso em vários lugares): o envio automático por funil (fila, CronJob, worker) e o robô de conversa não leem estes funis. Já estão prontos para eles: `atd_agenda_do_funil`, `atd_funil_para_contato` (aplica o A/B), `atd_renderizar`, `atd_exportar` (JSON único). Sem contatos no WhatsApp o painel mostra o aviso e números zerados. Os números do painel só passam a existir quando a Meta estiver conectada e houver contatos.
- **Verificação local**: PHP com SQLite (`php -c php-teste.ini testes/atendimento.php`), mais tela rodando em servidor local com a API real (conexão e login trocados por SQLite e token de teste) no desktop e a 375 px, sem erro no console. Foram feitas quebras de propósito (travessão, SSRF, revalidação de redirecionamento, janela de 24 h, sorteio A/B, limite diário, tamanho mínimo da amostra, assinatura de PDF, repetição sem fallback) e duas passaram em branco até os testes serem reforçados. **A chamada real ao Claude e a leitura de um link real não foram exercitadas** (sem chave e sem rede nos testes): o primeiro pedido de verdade é o teste final.

- **Leads, tema e voltar ao painel (20/10/2026)**: a Central ganhou a aba **Leads** e um bloco "Captação e leads" no Painel. **Não há regra nova de lead aqui**: `atd_leads` (`_atendimento.php`) chama `trLeads` (a mesma conta de Pessoas Captadas, em `_trafego.php`) e só acrescenta o que é da Central. (1) Clientes: `atd_ids_leads_clientes` aplica a regra de `alunos.html` (`isLead`: origem app, captura ou lead; legado sem origem e sem professor; nunca modelo do sistema nem origem professor/cliente) e passa os ids ao novo 6º parâmetro de `trLeads` (`$idsExtras`, aditivo, 3 linhas), então o lead de autocadastro aparece junto com o que Tráfego mede, com a MESMA etapa. (2) Tráfego: canal, campanha, indicação, uso do app, teste e matrícula vêm de `trLeads`; `atd_captacao` traz visitantes, captadas, taxa, funil do teste grátis, canais e campanhas de `trRelatorio` (variação contra o período anterior). (3) WhatsApp e controle de contato (`intus_lead_contato`, seção 91): `wa` e `controle` também vêm de `trLeads`; a Central só junta o funil da pessoa (pela agenda) e o `grupo_contato` (nunca, contatado, retorno, encerrado, cliente, pela constante `TR_CONTATO_ENCERRADOS` de Tráfego). (4) Contato sem conta cujo telefone ou e-mail já é de uma conta é a mesma pessoa e fica só a conta. (5) A mensagem sugerida de cada etapa vem dos funis EDITÁVEIS da Central (`ATD_MENSAGEM_DA_ETAPA`: contato = c4, criou acesso = t1, abriu o app = t2, treinou = t3, teste acabou = t6; matrícula a pagar e cliente são conversa de pessoa), com primeiro nome e link do app; sem nome a frase se ajusta; quem pediu para sair não recebe sugestão e ganha aviso. Clicar em **Chamar no WhatsApp** registra o contato pelo mesmo endpoint de Pessoas Captadas (`trafego.php?action=contato`, não repete em 10 min), então o que se faz numa tela aparece na outra. Testes (`testes/atendimento.php`, 467): quem entra, etapas, origem medida, dedupe, regras de Clientes, sugestões, controle de contato, "respondeu só vale depois de receber"; quebras de propósito acusadas.
- **Tema e navegação**: a página segue a chave `mx-theme` do painel (claro/escuro; sem chave, segue o sistema), com botão de alternar, logo por tema, e escuta mudança de tema em outra aba. Todas as cores são variáveis (teste proíbe cor fixa fora delas). O botão Sair virou **Voltar ao painel** (`/app/painel/index.html`); sair da conta continua pelo painel (o login é o mesmo). **Se uma sessão futura mudar a forma de `trLeads` (`pessoas[]`, `wa`, `controle`, `etapa`, `resumo_contato`), a Central depende dela**: rodar `testes/atendimento.php` e `testes/trafego_leads.php`.

## 89b. Login do aluno que caía e faixa de notificação fora de hora (19/10/2026)
Queixa: alunos precisando fazer login várias vezes na semana (muitos sem a senha salva e sem lembrar) e a faixa verde "Você tem uma notificação nova" aparecendo ao trocar de tela.
- **Causa principal do login que caía** (`api.js`, `_guardaDeSessao`): um `window.fetch` trocado derrubava o login assim que QUALQUER chamada à API respondia 401 com a palavra "token" no corpo. O servidor responde "token inválido" também quando a consulta da sessão falha por um instante (banco engasgado, `validateSession` devolve null no `catch`), então um soluço do servidor apagava o login. Agora, antes de derrubar, `window._intusSessaoInvalida()` faz até 3 consultas leves (a do sininho, em 0 s, 2 s e 5 s) e só se TODAS disserem "token inválido" o login é apagado; sem rede ou qualquer outra resposta, a sessão fica. O `apiFetch` também repete o pedido uma vez (1,5 s depois) e, se ainda for 401, usa a mesma confirmação (sessão válida mas servidor lento vira erro 503 e não logout).
- **Sessão do aluno** (`_sessions.php`): 90 dias (era 30 contados do login) e **renovada a cada uso** (`intusSessaoNovaValidade`: renova quando faltam menos de 75 dias, no máximo uma gravação a cada 15 dias de uso). Sessões antigas de 30 dias são esticadas no primeiro uso. Professor continua com 7 dias e sem renovação.
- **Cookie de retomada** (Safari do iPhone apaga o `localStorage` de sites sem interação por 7 dias e o login ia junto; os alunos usam a versão web, o app das lojas ainda não saiu): no login o servidor entrega a sessão num cookie `intus_rt` (HttpOnly, Secure, SameSite Lax, path `/app/api/`, 90 dias). `aluno_login.php?action=retomar` (POST) troca o cookie pela mesma sessão e o `login.html` chama isso sozinho quando não há login no aparelho (e vai para o app). `?action=esquecer` apaga a sessão e o cookie; `sair()` no app chama isso e vai para `login.html?saiu=1`, que NÃO retoma. Não vale no app nativo (outra origem, cookie não vai); lá o `localStorage` não é apagado. Quem já estava sem login precisa entrar uma vez depois do deploy para receber o cookie.
- **Faixa de notificação** (`aluno.html`, `_notifPintarBadge`): somava as notificações do servidor com as mensagens do professor que o app tem no aparelho; ao trocar de tela a lista de mensagens carregava, o total "subia" e a faixa acendia para coisa velha. Agora o aviso só aparece quando o SERVIDOR informa mais notificações do que antes (resposta sem número não vira zero), no máximo uma vez a cada 20 s, e a faixa ficou menor (6 s na tela). O número do sino continua somando as mensagens.
- Testes: `testes/sessao_aluno.php` (21: renovação, cookie, sessão de ponta a ponta em SQLite com `NOW()` emulado, estrutura) e `testes/login_aluno.js` (24 no Chromium: 401 isolado, sonda, retomar, sair, faixa). Quebrar de propósito cada trava é acusado. `api.js?v=20261019a` em todas as telas, `sw.js` cache `intus-v37`. App das lojas: `npm run sync` + rebuild para levar o cliente (o servidor vale na hora). **Não visto num iPhone de verdade.**

## 90. Pessoas Captadas: quem são os leads e em que etapa do funil estão (20/10/2026)
- **Onde**: `app/painel/captados.html` (menu "Pessoas Captadas", só administrador, tem telefone e e-mail). O cartão "Pessoas captadas" de Tráfego e Captação virou link para ela, com o mesmo período ("ver quem são"). Dados: `trafego.php?action=leads&de=&ate=` (admin), regra em `trLeads` (`_trafego.php`), só leitura.
- **Quem entra**: as pessoas dos eventos `conta_criada` (idatleta no `valor`) e `lead_enviado` (id do lead no `valor`; a `teste-gratis.html` passou a mandar o id que o `leads.php` devolve), mais as contas do teste grátis criadas no período que a medição NÃO viu (`atleta.origem` começando com `landing`, marcadas "origem não medida") e os contatos de `intus_lead` de origem `landing*`. Evento sem identificação vira pessoa anônima. Conta de outra origem e sem evento não entra. Horário dos eventos convertido de UTC para Brasília.
- **Etapas do funil** (de `atleta`, `intus_mensalidade`, `intus_sessao` e `intus_acesso`, por pessoa): contato sem conta, só criou a conta, abriu o app (acesso `abertura`/`login` registrado; sem a tabela, último acesso 10 min depois do cadastro), em teste já treinou (sessão que conta), **teste acabou sem comprar** (matrícula de teste vencida e nada pago; a melhor hora de abordar), matrícula criada e falta pagar, virou cliente (matrícula não-teste com `stpgto = S` e valor pago). Plano de teste = `tipo_plano`/`dshistorico` com teste, grátis, trial, cortesia, experimental ou degusta (mesma ideia do `API.ehTestePlano`). Matrícula cancelada é ignorada.
- **Na tela**: contadores por etapa (clicar filtra), busca por nome, telefone ou e-mail, ordem "quem contatar primeiro" (teste que acabou, matrícula a pagar, treinando, abriu o app, só conta, contato, cliente por último), origem (canal, campanha, arte, quem indicou), cadastro, uso do app (aberturas, treinos, situação do teste), ação sugerida, botão do WhatsApp com mensagem pronta da etapa (primeiro nome, sem travessão), "Copiar mensagem", planilha CSV da lista filtrada e atualização automática a cada minuto (preserva busca e filtro). Nome de conta leva ao cadastro (`alunos.html?busca=`).
- **Limites**: não há "marcar como contatado" (precisaria de tabela ou coluna nova; decisão do Luiz). Quem começou o formulário e desistiu não aparece (nunca informou contato). O funil de visitantes continua em Tráfego e Captação.
- Testes: `testes/trafego_leads.php` (31, SQLite com atleta, matrículas, sessões, acessos e leads: todas as etapas, quem entra, WhatsApp, mensagens) e a seção "Pessoas captadas" de `testes/trafego_front.js` (71 no total). `_mock.js?v=20261020a` em todas as telas.

## 91. Controle de contato com os leads e integração com o WhatsApp (20/10/2026)
- **Tabela nova `intus_lead_contato`** (autorizada pelo Luiz em 20/10/2026; só recebe linhas, nenhum UPDATE/DELETE no código; criada na primeira gravação): cada linha é um contato feito com uma pessoa captada (`chave` = a mesma da tela Pessoas Captadas, `a<idatleta>` ou `l<idlead>`; `telefone`, `canal`: whatsapp_link | whatsapp_api | ligacao | email | presencial | outro; `status`: chamado, respondeu, retornar, sem_resposta, sem_interesse, fechou, numero_errado, nota; `nota` até 500 caracteres; `retorno_em`; quem registrou; `criado_em` em UTC). **A situação atual de uma pessoa é a linha mais recente dela**; correção = nova linha.
- **Rotas** (`trafego.php`, só administrador): `POST ?action=contato` registra; `POST ?action=responder_wa` responde pelo WhatsApp da Intus; `GET ?action=leads` agora traz por pessoa `controle` (último contato, total e os 5 últimos) e `wa` (estado no WhatsApp), mais `resumo_contato` (não contatados, contatados, retornos vencidos, encerrados) e `wa_configurado`. Tudo validado no servidor (chave, situação, canal e data; anotação sem marcação e no máximo 500 caracteres).
- **Na tela** (`captados.html`): clicar em "Chamar no WhatsApp" já registra "chamado" por WhatsApp (não repete a cada clique em 10 min); "Anotar resultado" (respondeu, retornar com data, sem resposta, sem interesse, fechou, número errado, anotação, e por qual canal); coluna "Já falamos com a pessoa?" com quem falou, quando, a anotação, o retorno (vermelho se venceu) e o histórico; filtros "Ainda não contatados / Já contatados / Retornar hoje ou atrasado / Encerrados"; "Quem contatar primeiro" agora põe retorno vencido, depois quem nunca foi chamado, depois já contatados, encerrados e cliente por último; a planilha CSV leva a situação do contato.
- **WhatsApp da Intus (Meta)**: lê `intus_wa_contato` e `intus_wa_mensagem` pelo telefone normalizado (`wa_normalizar_telefone`) e mostra quantas mensagens a pessoa recebeu, se respondeu, se pediu atendimento humano e se **pediu para sair** (aviso vermelho, sem botão de chamar nem de responder). **Responder pelo painel** só aparece quando a Meta está configurada e a pessoa escreveu para a Intus nas últimas 24 h (regra `wa_decidir_envio` com texto livre, sem proativa): envia por `wa_enviar_texto` com origem `humano`, passa o contato para `modo = humano` (o robô para) e `precisa_humano = 0`, e registra o contato (canal whatsapp_api). Se a Meta recusar, nada é registrado nem o robô é desligado. **Não envia mensagem proativa nem modelo (template)** de propósito: a página do teste grátis ainda não grava o consentimento (`optin_em`); quando gravar, dá para oferecer o envio proativo.
- Não toquei no módulo Atendimento (`/atendimento/`, `_atendimento.php`), que tem a própria lista de leads com o estado do WhatsApp; os dois leem as mesmas tabelas, e a situação de contato desta tela ainda não aparece lá.
- Testes: `testes/trafego_leads.php` (66: controle, validação, estado do WhatsApp, envio com Meta simulada, recusas) e a seção "Controle de contato e WhatsApp" de `testes/trafego_front.js` (91 no total). Não testei contra a Meta de verdade (sem envio real).

## 92. CDD: capas prontas dos módulos (20/10/2026)
- **26 capas** em `cdd/marca/capas/`, uma por módulo do seed, em dois formatos: `<slug>.jpg` (pôster 2:3, 640x960, **com título, selo e logo CDD**) e `<slug>-wide.jpg` (banner 16:9, 1280x720, **sem título**, o app escreve o título por cima). Geradas por `tools/cdd/gerar-capas.py` a partir da foto de referência do Luiz ("Capa módulos cdd.png", do Drive; cópia local em `cdd-descoberta/capa-ref.png`, fora do repositório). Não há foto nova: cada capa é a mesma foto com enquadramento, cor (duotone da cor do módulo, brilho de borda, vinheta) e clima próprios. A logo do CDD que vinha gravada no meio da foto é apagada pelo script (`limpar_logo`) para a capa levar a logo no topo. Para refazer: `python tools/cdd/gerar-capas.py <foto> cdd/marca/capas` (precisa de Pillow e da fonte Impact do Windows).
- **Como o sistema usa** (`cdd_modulo.capa_url`, sem coluna nova): aceita link `https://` ou capa pronta relativa (`marca/capas/<slug>.jpg`, validada por `cddCapaUrl`; qualquer caminho que saia da pasta é recusado). `cdd.js`: `CDD.capaLarga(url)` troca por `-wide` no destaque, no cabeçalho do módulo e nas aulas sem vídeo. Na fila de capas o app só mostra o selo "N aulas" (e o cadeado), porque o título já está na imagem; capa por link externo continua mostrando o título do app por cima. O painel tem o seletor "Capa pronta do CDD" no editor do módulo.
- **O seed ganhou `capa_url`** por módulo e `cddImportarSeed` coloca a capa em módulo novo OU em módulo existente que ainda não tem (nunca troca capa já escolhida). **Depois do deploy, clicar em "Importar conteúdo" no painel** para as capas aparecerem nos módulos que já existem. Capa nova de módulo novo = adicionar ao `MODULOS` do script, gerar, e pôr `capa_url` no seed.
- Teste: `testes/cdd.php` (248), inclui "todo módulo do seed tem capa e os dois arquivos existem". O teste "ranking geral" dependia do dia da semana (criava treinos até quinta); corrigido.

## 93. Teste grátis: treina, faz cardio e pontua; não publica nem comenta (20/10/2026)
- **Regra do Luiz**: o aluno do teste grátis treina, marca cardio, entra no ranking e pontua normalmente. **Não publica no Feed nem comenta** (post, post só de texto, compartilhar treino/cardio, comentário e resposta no Feed, mural dos membros do ranking, mural da Nutrição) enquanto o único plano for o teste. Libera sozinho quando houver outro plano ativo. Reações, curtidas, editar e apagar o que já existe seguem livres.
- **Fonte única**: `app/api/_plano_aluno.php` (`intusPlanoAluno`, `intusPlanoAlunoDeLinhas`, `intusExigirPlanoParaPublicar`). **Teste** = matrícula com teste, grátis, trial, degusta ou experimental no histórico ou no tipo de plano, vigente (dtinicio <= hoje <= dtvencimento). **Plano ativo** = matrícula que não é teste, não cancelada, encerrada, trancada nem inativa, paga (`stpgto = S`) e com cobertura em dia (carência de 5 dias). **Cortesia NÃO é teste** (é plano dado de presente e libera tudo). Só bloqueia com teste vigente e nenhum plano ativo: sem matrícula, ou teste vencido, não bloqueia por esta regra. Erro de banco nunca bloqueia (falha aberta de propósito); a consulta usa `SELECT *` porque `tipo_plano` e `stmatricula` podem faltar num banco antigo.
- **Onde o servidor barra (403, `codigo: so_teste`)**: `catalogo.php`, antes das rotas, POST de aluno em `feed_posts`, `feed_midia` e `comentarios_pub`; `treinos.php`, mural dos membros (`ranking`, `subaction = comentario`). Registro de treino e de cardio NÃO passam por esta regra.
- **No app** (`aluno.html`): `_ehSoTeste()` e `_soTesteBarra()` (espelho da regra do servidor; só age depois de o servidor confirmar as matrículas, `_syncDone` e `_matSyncOk`) avisam por toast e não abrem nada em `abrirNovoPostFeed`, `abrirPostTexto`, `abrirCompartilharTreino` (cobre treino e cardio), `enviarComentarioFeed`, `enviarComentarioRanking`, `_nmEnviar` e `_cqPostar`. O texto digitado fica no campo. Mensagem única: "O teste grátis não inclui publicar no Feed nem comentar. Isso libera assim que você tiver um plano ativo." `sw.js` cache `intus-v38`.
- **Ranking**: conferido no backup de 15/10 (só contagens): 19 contas de teste, 18 com `ranking_optin = S`, nenhuma bloqueada, 11 com treino registrado. O ranking filtra só por `ranking_optin = 'S'` e `stbloqueio != 'S'`, não olha matrícula, então o teste entra e pontua (inclusive cardio). O cadastro novo (`aluno_login.php`) agora grava `ranking_optin = 'S'` de forma explícita.
- **Testes**: `testes/plano_aluno.php` (38: todos os casos da regra, SQLite, banco sem colunas, a rota parando com 403 em processo à parte, onde a trava está ligada) e `testes/teste_gratis_front.js` (28 no Chromium: avisos, nada vai ao servidor, cardio segue livre, plano/cortesia libera). Quebrar a trava do servidor, a carência e o guarda do Novo post de propósito é acusado.

## 94. Laboratório do WhatsApp: enviar cada mensagem do funil, ver se chega e escolher automático ou manual (20/10/2026)
- **Tela**: `intusfit.com.br/atendimento/teste.html` (só administrador, mesmo login do painel; não está no menu, o link fica em "Teste do WhatsApp" na descrição desta seção e na conversa com o Luiz). API: `app/api/wa_teste.php`; regra: `app/api/_wa_fila.php` (usa `_wa.php` e `_atendimento.php`, nenhuma regra duplicada); fila para agendador externo: `app/api/wa_cron.php`.
- **Contato de teste**: Fulano Teste, `5545999574107`, criado só quando o Luiz clica em "Criar o contato de teste" (nada roda sozinho no deploy). Consentimento registrado como `optin_origem = 'teste-admin'` (número do próprio Luiz). É o ÚNICO contato que a tela alcança (nenhuma ação aceita telefone ou id vindo da tela) e o único que ignora horário comercial, limite diário e condições do funil. Lead real nunca passa por esta exceção: valem `wa_decidir_envio`, consentimento, `saiu_em`, modo humano, funil aprovado e template aprovado.
- **Envio manual** (`waf_enviar_mensagem`): "Enviar agora" manda a mensagem do funil por template (o nome vem do funil, parâmetros `{{1}}` nome e `{{2}}` link do app, na ordem) ou, se a janela de 24 h estiver aberta, como texto livre. Registra em `intus_wa_mensagem` com o `wa_id` da Meta; status "Aceita pela Meta" só quer dizer que a Meta recebeu o pedido. **Entregue e Lida só chegam pelo webhook** (`whatsapp_webhook.php`, precisa estar cadastrado na Meta). O erro da Meta (ex.: template inexistente, token inválido) aparece na tela, sem mostrar o token. Pedido de saída do contato (`saiu_em`) NUNCA é ignorado, nem no envio manual. `hello_world` (modelo que toda conta Meta tem aprovado) prova que o número chega ao aparelho.
- **Automático x manual**: `intus_config` chave `atd_automacao` = `{mestre, passos: {"funil/mensagem": "auto"}}`. Nasce tudo manual e a chave geral DESLIGADA; sem a chave geral nada sai sozinho. Passo manual vencido só aparece na agenda como "aguardando você enviar".
- **Agenda e fila**: `waf_agendar` (teste rápido: a 1ª agora e as outras a cada N minutos; sem intervalo, a agenda de verdade do funil) grava em `intus_wa_agenda` (passo `t1..t7` do teste grátis, `<funil>:<msg>` nos demais; um passo por contato, não repete). `waf_processar` envia o que venceu e está em auto (até 20 por rodada), reservando a linha (`pendente` -> `enviando`) antes de chamar a Meta para duas execuções simultâneas não duplicarem. Fora do horário ou limite do dia: o passo espera. Sem consentimento, `saiu`, modo humano: cancelado. Condição não satisfeita (`sem_treino`, `com_treino`, `sem_resposta`): pulado. Falha da Meta: `falhou` com o motivo, sem travar a fila.
- **Quem roda a fila**: (1) a própria tela, a cada minuto, enquanto estiver aberta e a chave geral ligada; (2) o botão "Processar a fila agora"; (3) `wa_cron.php` com cabeçalho `X-Cron-Auth` igual ao token de `admin/whatsapp-keys.php` (sem token cadastrado responde 503; errado, 403; resposta sem nome nem telefone), para um CronJob da KingHost (pago) ou um agendador gratuito do Cloudflare. **Decisão pendente do Luiz**: sem um agendador externo, a automação só anda com a tela aberta.
- **O que continua sem ligar**: nenhum lead real entra na agenda (falta o aceite de WhatsApp na landing gravando `optin_em` e criando a agenda), então a automação não alcança ninguém além do Fulano. O robô de conversa não existe ainda.
- **Reiniciar o teste** (Opções avançadas, digitar REINICIAR): apaga só a agenda do Fulano, tira `saiu_em` e libera o robô; o histórico de mensagens fica. Recusa se o contato não for o de teste.
- **Testes**: `testes/wa_fila.php` (88, com a Meta de mentira: envio, recusas, janela, saída, agenda, processador, lead real, estado sem segredo, estrutura dos endpoints) e `testes/wa_teste_front.js` (28 no Chromium com a API de mentira). Quebrar de propósito a trava de `saiu`, a chave geral e as regras do lead real é acusado. A reserva contra envio duplicado só existe contra corrida real e não é exercitada em processo único. **Nada foi enviado à Meta de verdade**: o primeiro envio real é o teste final.

## 93. CDD: login e recuperação da senha do administrador (20/10/2026)
- O painel do CDD é `cdd/painel.html` (login próprio, separado do app da aluna `cdd/index.html`); os dois usam a mesma chave `cdd-token` no navegador, então sair por um sai do outro. O login da aluna ganhou "Acesso da equipe (painel)" e, para admin, a Conta mostra "Abrir o painel da equipe". "Esqueceu a senha?" virou dois botões (WhatsApp 45 99957-4107 e e-mail `contato@codigodadefinicao.com.br`, ambos com texto pronto): a equipe então gera um novo link em Alunas > Link. **Não existe "esqueci a senha" automático por e-mail** (precisaria de SMTP no servidor do CDD, tabela de links e tela de nova senha).
- **Recuperar a senha do administrador do CDD**: `admin/cdd-recuperar.php`, atrás do login do admin da Intus (`/admin/`, usuário `intusadmin`). Lista as contas `papel = admin` do CDD, o dono digita a senha nova duas vezes (mínimo 8), grava só o hash bcrypt e, se marcado, encerra as sessões abertas daquela conta. Só mexe em administrador do CDD, nunca em aluna. Regra em `cdd/api/_recuperar.php` (testada, 260 conferências). O e-mail da conta de administrador do CDD é o do instalador (`CDD_EMAIL_INSTALADOR`, contato@luiznunest.com.br). Quando o CDD mudar para o servidor próprio esta página tem de ir junto (hoje ela usa `app/config/db.php` e o login do admin da Intus).

## 95. Central de atendimento: fila de hoje, manual e sem a Meta (20/10/2026)
Pedido do Luiz: começar a testar as mensagens dos funis SEM sincronizar com a Meta, atendendo só os leads do orgânico. É a aba **Fila de hoje** (primeira da Central, `atendimento/index.html`, `telaFila`/`pintarFila`). **Nada é enviado automaticamente e nada chama a API da Meta**: a equipe abre o WhatsApp da Intus pelo link `wa.me/<telefone>?text=<mensagem pronta>`, envia e marca.
- **Arquivos**: `app/api/_atd_manual.php` (TODA a regra), rotas em `app/api/atendimento.php` (`manual_estado` GET, `manual_config` e `manual_acao` POST, só administrador), `testes/atd_manual.php` (86 conferências, registrado em `rodar.sh`). A mensagem de cada passo vem dos funis editáveis da Central (`atd_agenda_do_funil`, `atd_render_mensagem`, A/B por `atd_funil_para_contato`); quem entra e a etapa vêm de `trLeads` (a mesma conta de Pessoas Captadas). O modo manual só LÊ `atd_automacao.mestre` para avisar se a automação da Meta estiver ligada (seção 94).
- **Quem entra** (configurável na própria tela, `intus_config.atd_manual_cfg`): origem **orgânico** (padrão), pago ou todos, e "leads cadastrados desde" (primeira leitura = 2 dias atrás; no máximo 90 dias para trás, nunca no futuro). **Orgânico = qualquer lead sem `utm_medium` de anúncio** (`cpc`, `paid`, `ads`, `anuncio`, `ppc`: `ATD_M_MEDIUM_PAGO`); lead sem medição conta como orgânico. Para um anúncio não entrar na fila, o link dele tem de levar `utm_medium=cpc` (gerador de UTM em Tráfego e Captação). Ficam de fora: sem telefone válido, quem pediu para sair (`wa.saiu`), encerrados em Pessoas Captadas, cliente e matrícula a pagar (conversa de pessoa). O resumo diz quantos saíram por cada motivo.
- **Qual mensagem vale agora**: a do último passo do funil cujo horário já chegou e que ainda não foi tratada; vale até o horário do próximo passo, e o último passo vale por 2 dias (`ATD_M_ULTIMO_PASSO_DIAS`). Respeita a condição do passo (`sem_treino`, `com_treino`, `sem_resposta`). Conta (teste grátis) usa o funil `teste-gratis` (ou a variante B do teste A/B, sempre do mesmo lado pelo telefone); contato sem conta usa `chamou-whatsapp:c4`. Os funis que não são o `teste-gratis` ainda são RASCUNHO e a tela marca "rascunho" em cada mensagem. Fora das 8h às 21h a fila avisa, mas não esconde.
- **Ações** (`manual_acao`): **Já enviei** (guarda o passo como enviado, idempotente, e registra o contato "chamado" por `whatsapp_link` em Pessoas Captadas com a nota "Central: funil · mensagem", então as duas telas concordam), **Pular** (guarda o passo como pulado), **Respondeu** e **Encerrar** (registram `respondeu` e `sem_interesse`). Quem recebeu e não respondeu fica em "Aguardando resposta" por 3 dias (`ATD_M_ESPERA_RESPOSTA_DIAS`). O **placar** conta enviadas e respondidas por mensagem; a tela avisa que menos de 30 envios por mensagem não prova nada.
- **Escolher a mensagem (20/10/2026)**: cada pessoa da fila tem um seletor com TODAS as mensagens prontas de todos os funis (`biblioteca` + `opcoes` por pessoa, já com o nome dela; `atd_manual_biblioteca`, `atd_manual_opcoes`). Abrir no WhatsApp e Copiar usam a escolhida. "Já enviei" manda `passo` (a escolhida) e `passo_sugerido`: grava a escolhida como `enviada` e a sugerida como `substituida` (resolvida, não conta no placar nem volta à fila). Um passo que tinha sido pulado pode ser enviado depois; repetir o toque em um enviado não duplica.
- **Estado guardado** sem tabela nova: `intus_config` chaves `atd_manual_cfg` e `atd_m_<chave>` (um JSON por pessoa, `chave` = `a<idatleta>` ou `l<idlead>`, com os passos tratados, quando e por quem). O que a equipe marca no celular e no computador é o mesmo.
- **Limites a saber**: o fuso do servidor não foi conferido contra Brasília em produção (`wa_tz`); "Respondeu" é manual (não lê o WhatsApp); a atribuição orgânico x pago depende de UTM. Quando a Meta for ligada, o envio automático (seção 94) e esta fila usam as mesmas mensagens, mas controles separados: marcar "Já enviei" aqui NÃO cancela a agenda automática do contato.
- **Testes**: `testes/atd_manual.php` (SQLite: origem, data, horários, janelas, condições, ações, A/B, placar, estrutura sem chamada à Meta). Foram feitas 12 quebras de propósito (origem, saiu, encerrado, cliente, telefone, expiração da janela, repetição de passo, condição, idempotência, detecção de pago, prazo do último passo, prazo da espera) e as 12 são acusadas. Verificado no navegador (tema escuro e claro, celular): marcar enviada, respondeu, placar, salvar configuração e erro de data futura.

## 95. Publicar nunca fica girando para sempre (20/10/2026)
- **Relato**: aluna no iPhone, "não consigo publicar nada desde ontem", parada em "Preparando seu post..." (Compartilhar cardio, com figurinha). O rótulo só muda para "Enviando seu post..." quando o envio começa, então o travamento era ANTES do servidor, na montagem da imagem (`_composeImagemFinal`): figurinha pelo `html2canvas` (clona a página inteira, sem prazo próprio) e logo. **Causa exata não reproduzida** (no Chromium com 400 cartões de feed a figurinha sai em ~1 s): a correção torna impossível o travamento, e o que a aluna vir passa a aparecer em toast.
- **Correções (`aluno.html`)**: o `html2canvas` agora clona só a figurinha (`ignoreElements`, `imageTimeout: 8000`); `_comPrazo` põe prazo na figurinha (20 s, `_COMPOSE_PRAZO_FIGURINHA_MS`) e na logo (8 s): passou do prazo, publica a foto sem o enfeite e avisa; o POST de `feed_posts` e o de `feed_midia` usam `_fetchT` com 90 s (servidor mudo vira erro "A conexão está lenta..." e a tela de "Preparando" fecha, o texto e a foto ficam). `sw.js` cache `intus-v39`.
- **Se ela ainda reclamar**: pedir o texto exato do aviso (toast) e o modelo/versão do iPhone; o console registra `[compartilhar] figurinha falhou`. Teste: `testes/publicar_travado.js` (9, Chromium: figurinha travada, logo travada, servidor mudo).

## 96. Treino fora do protocolo: marcar outro aluno e já encaminhar ao post colaborativo (20/10/2026)
- **Pedido do Luiz**: em "Treinei com outra pessoa" dá para mencionar outro aluno, e isso já leva ao treino em colaboração.
- **Campo "Com quem você treinou?"** (`aluno.html`, `_fpDesenhar`): usa o mesmo mecanismo de @menção do resto do app (`data-mencoes="fp"`, lista de pessoas do Feed). Digitar `@` e escolher marca o aluno; nome digitado à mão (quem não é aluno) continua valendo como texto. `_fpMontar` grava no comentário só o nome ("Fora do protocolo (treino com outra pessoa): Isabela Pazzinatto"), SEM o código `@[Nome]{aluno:id}`, para ele não aparecer cru no histórico e no painel; a marcação vai à parte em `sessao.mencoes` (até 4 pessoas, nunca o próprio aluno). Só vale no motivo "com outra pessoa".
- **Servidor** (`treinos.php`, POST de sessão, só aluno logado e só divisão `FORA`): `_notificarMencoes` avisa cada marcado (aviso `mencao` com `alvo_tipo = 'sessao'`: "Fulano marcou você num treino fora do protocolo"; tocar abre o perfil de quem marcou). A checagem é a de sempre das menções (pessoa existe, está no Feed e não está bloqueada entre os dois). Reenvio do mesmo treino (duplicata) não avisa de novo. Falha ao avisar nunca derruba o registro.
- **Depois de registrar** (`_fpSucesso`, `_fpColabCartao`, `_fpPostarColab`; só com as conexões ligadas): a tela de sucesso mostra "Treinou com Fulano?". Quem já é **amigo** (servidor só aceita convite de colaboração entre amigos aceitos) entra no botão "📸 Postar em colaboração", que abre o "Compartilhar treino" com os amigos marcados já convidados (`abrirCompartilharTreino(info, { colab: [ids] })`; o convite só vira colaboração quando a pessoa aceita, regra da seção 51). Quem ainda não é amigo aparece com "Pedir amizade" e o botão do post só surge quando houver ao menos um amigo marcado. Aluno só no teste grátis continua sem postar (seção 93).
- **Testes**: `testes/fora_protocolo_colab.js` (23, Chromium: lista de @, comentário sem código, marcação à parte, amigo x não amigo, compositor abre com o convite, aviso na lista de notificações, estrutura do servidor). `sw.js` cache `intus-v40`. O app das lojas leva `aluno.html` empacotado: vale após novo envio; o aviso ao marcado (servidor) vale na hora para quem já usa a versão nova do app.
- **Correção do mesmo dia (20/10, iPhone)**: digitar `@isabel` no campo não mostrava nada para tocar, porque a lista de menções (`#mencao-dd`, z-index 100050) ficava ATRÁS da folha do treino fora do protocolo (100300). A lista agora é 100950, acima de qualquer folha do app (qualquer campo com `data-mencoes` dentro de uma folha sofria o mesmo). O teste passou a conferir que a lista está visível e tocável (`elementFromPoint`), não só que existe. `sw.js` cache `intus-v41`.
- **Correção definitiva (20/10, 2ª tentativa)**: mesmo acima da folha, a lista não aparecia no iPhone. Causas: (1) `window.addEventListener('scroll', _mencoesFechar, true)` fechava a lista na hora, porque o iPhone rola a página sozinho quando o teclado abre; (2) uma lista `position: fixed` pode sair da área visível com o teclado aberto; (3) quando a busca não achava ninguém ou a lista de alunos não carregava, não aparecia nada, então não dava para saber o motivo. Agora o campo do treino fora do protocolo usa `data-mencoes-inline="fp-mencao-box"`: a lista fica DENTRO da folha, logo abaixo do campo (não flutua), a rolagem não a fecha (`_mencoesInline`), só a última tecla digitada manda (`_mencSeq`, as buscas terminam fora de ordem) e o app diz "Buscando alunos...", "Nenhum aluno encontrado com ..." ou "Não consegui carregar a lista de alunos agora" (`_mencoesFalhou`). Os outros campos com `data-mencoes` (comentários, mural) continuam com a lista flutuante de sempre; qualquer campo novo dentro de uma folha deve usar o modo inline. Teste: `testes/fora_protocolo_colab.js` (27). `sw.js` cache `intus-v42`.
- **Diagnóstico da lista (20/10, 3ª rodada)**: com a lista já visível o Luiz buscou "@wen" e "@isabel" e o app disse "Nenhum aluno encontrado". No backup de 15/10 a lista teria 73 alunos (feed_optin = S e não bloqueados) e 3 nomes começando com "wen", então a causa em produção ainda não está confirmada. A mensagem agora diz quantos alunos vieram na lista ("(N na lista)"): N = 0 aponta o servidor (`catalogo.php?action=pessoas_mencionaveis` devolvendo vazio), N > 0 aponta a busca. Lista vazia guardada em cache é descartada e a busca refeita uma vez. `sw.js` cache `intus-v43`. Teste: `testes/fora_protocolo_colab.js` (29).
- **4ª rodada (20/10)**: a regra do servidor foi conferida de verdade (o bloco de `pessoas_mencionaveis` rodando em PHP contra os 77 alunos do backup devolve 73 pessoas, com "wen" e "isabel" achados), então a lista vazia no aparelho do Luiz não vem da lógica. Como não dá consultar a produção sem o login dele, o campo dentro de folha (`data-mencoes-inline`) agora também usa os alunos que o app já tem (ranking, Feed, amigos: `_rankingData.atletas`, `_feedAtletasMap`, `_amzDados.pessoas`), sem o próprio aluno. O servidor continua conferindo cada marcado antes de avisar. A mensagem diz "(N na lista do servidor, M do ranking)": N = 0 com M > 0 prova que o servidor devolve vazio. `sw.js` cache `intus-v44`; teste: `testes/fora_protocolo_colab.js` (32).

## 97. Faixa "Você tem N notificações novas" ficava cortada no alto (21/10/2026)
- **Sintoma (iPhone)**: depois de tocar na faixa verde, parte dela continuava à mostra no topo da tela. **Causa**: escondida, a faixa só subia `-180%` da própria altura (~60 px, ou seja ~108 px); no iPhone com ilha dinâmica a faixa nasce em `top: 8px + área segura (~59 px)`, então o fundo dela ficava perto de +19 px e a sombra à mostra. **Correção** (`aluno.html`, `_notifAvisoEstado`): escondida, sobe `100% + 60 px + área segura` e fica `opacity: 0` e sem toque; mostrada, volta ao normal (mesma função na entrada, no toque e no tempo de 6 s). Teste: `testes/aviso_notificacao.js` (5; troca o `env()` por 59 px para reproduzir o aparelho; com os valores antigos ele falha). `sw.js` cache `intus-v45`.

## 98. Ciclo "pago na linha de cima" agora avisa quando vence (21/10/2026)
- **Caso (Erick, 21/10)**: ele paga uns 2 dias antes do fim do ciclo, então a baixa fica na linha de cima e a linha atual nasce em aberto ("↑ pago na linha anterior", estado `paga_no_anterior`). Esse estado devolvia sempre "sem próxima cobrança" e as telas o ignoravam: o ciclo de 20/09 a 20/10 passou do fim e a matrícula não aparecia em "a vencer", em "vencidas" nem em "renovar" (o painel mostrava "Ativa · Paga"). A cada baixa o ciclo novo nascia igual, então o buraco se repetia todo mês.
- **Regra nova (`API.situacaoCobranca`, `api.js`)**: ciclo em aberto e pago na linha de cima, **sem ciclo seguinte registrado**: com a cobertura correndo continua `paga_no_anterior` mas agora com `proxima` = fim da cobertura (entra em "a vencer" e na previsão, como um ciclo pago); passada a cobertura vira **`renovar`** (ação sua, nunca dívida: `ehAcao`, não `ehDivida`), com `dias` contados do fim. Com ciclo seguinte registrado (ou parcela numerada) nada muda. `index.html` deixou de pular `paga_no_anterior` (só pula `renovada` e `programada`) e de excluí-lo da previsão de 30 dias. As demais telas já leem o estado: o painel de Matrículas mostra "Aguardando renovação" no lugar de "Ativa · Paga" e a tela inicial lista em "Matrículas vencidas (até 30 dias)" com "renovar · Nd". No backup de 15/10, avaliado em 21/10, havia 2 matrículas nesse buraco já vencidas (uma era o Erick) e 1 vencendo em até 15 dias.
- **Dar a baixa de hoje**: "✓ Pago" na linha, data de pagamento = dia em que ele pagou. A baixa grava na linha de 20/09 e `marcarPago` cria sozinho o ciclo 20/10 a 20/11 (a data do pagamento colada no fim do ciclo faz o sistema entender que o dinheiro é do ciclo seguinte, regra de sempre). O ciclo novo já nasce "pago na linha anterior", agora com próxima cobrança em 20/11: em 14/11 aparece em "a vencer" e em 21/11, sem nova baixa, em "renovar".
- **Aviso no sino do aluno** (`aluno.html`, `_mensalidadeNotificar`; `catalogo.php` aceita o tipo `mensalidade` em `registrar`, com o mesmo dedupe de sempre): 5 dias antes do fim da cobertura ("Seu plano vence em N dias") e na carência ("Seu plano venceu em DD/MM, regularize em até N dias"), um por fim de cobertura e fase, só depois de o servidor confirmar as matrículas e fora do teste grátis. Antes só havia a faixa na Home e na Central, que só aparecem se o aluno abrir essas telas. **Não existe aviso por WhatsApp, e-mail ou push** de cobrança: o aviso chega quando o aluno abre o app. O aviso ao PROFESSOR continua sendo a lista da tela inicial (não há sino de cobrança para a equipe).
- **Cache**: `api.js?v=20261021a` em todas as telas vivas; `sw.js` cache `intus-v47`. Testes: `testes/cobranca_paga_anterior.js` (21; com o `api.js` antigo ele falha em 11, inclui o painel inicial de verdade) e `testes/aviso_mensalidade_aluno.js` (15). `fonte_unica`, `parcelas`, `dashboard`, `financeiro_tela`, `pontos`, `concordancia` e `navegador` seguem passando.

## 99. Valor recebido editável na baixa e na renovação (21/10/2026)
- **Pedido do Luiz**: combinar outro valor com o aluno em um mês e poder registrar. Antes a janela de baixa só deixava escolher forma e data (e multa/juros), e o "↻ Próximo ciclo" usava um `confirm()` com o valor fixo.
- **Baixa** (`mensalidades.html`, `marcarPago`/`confirmarPagamento`; `alunos.html`, `marcarPagoMini`/`confirmarPagamentoMini`): novo campo **"Valor recebido (R$)"**, que nasce com o combinado (valor do ciclo + multa/juros − desconto) e acompanha a multa enquanto você não mexer nele. Mexeu: a janela mostra "Combinado R$ X, você está registrando R$ Y (±R$ Z)" e uma opção **"Cobrar este valor também nos próximos ciclos"** (desmarcada). Zero, negativo, texto, ou valor menor que a multa são recusados antes de fechar a janela.
- **Regra** (`API.calcularRecebimento(m, juros, recebido)`, `api.js`; `API.pagarMensalidade(m, data, total, ajuste)`): valor igual ao padrão não muda nada (comportamento de sempre). Valor diferente: `vlpagamento` = recebido e o próprio ciclo passa a valer o recebido menos a multa (`vlpagar`), com `vldesconto` = 0, para a lista de matrículas, o Financeiro, a Gestão e o Início (que leem `vlpagar` ou `vlpagamento`) mostrarem o mesmo dinheiro. **O ciclo seguinte (criado na baixa) nasce com o valor de PLANO anterior**, salvo se a opção "Cobrar este valor também nos próximos ciclos" estiver marcada. O valor do plano antigo não fica guardado em coluna: depois da baixa, o ciclo pago guarda só o valor combinado.
- **Renovação** (`renovarCiclo` → janela com "Valor deste ciclo (R$)", `confirmarRenovacaoCiclo`): o ciclo novo nasce com o valor digitado (vazio ou zero não cria). O Financeiro já perguntava o valor ao renovar (`prompt`) e não mudou.
- Teste: `testes/valor_recebido.js` (29: a conta, a janela no Chromium com a API de mentira, o que vai no PUT e no POST, renovação). `api.js?v=20261021b` em todas as telas, `sw.js` cache `intus-v48`.

## 100. Extras: conteúdos bônus no app do aluno, configurados pelo painel (21/10/2026)
- **Pedido do Luiz**: uma área "Extras" na tela inicial e no menu lateral com aulas gravadas, lives, treinos para fazer em casa, módulo Cintura de Atleta e outros, no modelo do CDD (módulos com conteúdos dentro), com visibilidade configurável pelo painel (todos, só ativos, nutrição ou combo, treino ou combo, alunos escolhidos) e poder reordenar, editar, incluir e excluir.
- **Armazenamento: NENHUMA tabela nova.** O catálogo inteiro é um JSON em `intus_config` (chave `extras_conteudo`), como os funis da Central. Enquanto o administrador não salvar nada, vale o modelo de fábrica (`extras_padrao`): 5 módulos (Aulas gravadas, Lives, Treinos para fazer em casa, Cintura de Atleta, Materiais e bônus), vazios e **não publicados** (o aluno não vê nada até ele publicar). Ler não grava.
- **Arquivos**: `app/api/_extras.php` (toda a regra), `app/api/extras.php` (endpoint), `app/painel/extras.html` (painel, só administrador, item "Extras" no menu: `_mock.js?v=20261021a`), e em `aluno.html` o bloco "EXTRAS" (cartão na Home logo acima do Feed, item no menu lateral, view `v-extras`, `navegar('extras')`).
- **Modelo**: módulo = {id, titulo, descricao, emoji, cor, capa (https), publicado, visibilidade {modo, ids}, itens[]}; conteúdo = {id, titulo, descricao, tipo video|live|material|link, url (só https), duracao, data (live), publicado, visibilidade|null (herda do módulo), criado_em}. Limites: 60 módulos, 200 conteúdos por módulo, 500 alunos escolhidos por regra.
- **Quem vê (decidido NO SERVIDOR)**: `extras.php?action=lista` (só aluno) devolve apenas o que aquele aluno pode ver, sem regra de acesso, lista de alunos nem rascunhos; o id vem do token. Regras: `todos` (qualquer aluno, inclusive teste grátis), `ativos` (plano pago em dia, carência de 5 dias, **não** conta o teste grátis), `nutricao` (plano de nutrição ou combo, OU plano alimentar ativo), `treino` (plano de treino ou combo), `selecionados`. Nutrição/treino/combo vêm do TEXTO da matrícula (tipo_plano + descrição): "nutri/dieta/combo" = nutrição; combo, "Treino + Dieta" e semelhantes = os dois; plano sem palavra de nutrição (Personal, Consultoria, Bonificado...) = treino. Cada conteúdo pode ter regra própria; o módulo só aparece se tiver ao menos um conteúdo publicado e visível para o aluno.
- **Painel**: módulos e conteúdos com ▲▼, Editar, Excluir (excluir módulo com conteúdos pede duas confirmações), publicar/rascunho, regra com "≈ N alunos hoje" (`extras_alcance`, só números), seletor de alunos com busca (sem aluno-modelo e sem bloqueado). Link que não for https:// é recusado com aviso.
- **App**: cartão "Extras" na Home (selo NOVO quando há conteúdo criado depois da última visita, guardado em `intus-extras-visto-<id>`), item no menu lateral, grade de módulos, lista de conteúdos e player: YouTube abre no player do app (`abrirVideoModal`), outros links abrem fora. Sem internet usa a cópia em `intus-extras-lista-<id>`. Sem nada visível, cartão, menu e tela não aparecem.
- **Testes**: `testes/extras.php` (69, SQLite) e `testes/extras_front.js` (38, Chromium: painel e app). Desfazer o filtro de visibilidade de propósito é acusado. `sw.js` cache `intus-v49`. O app das lojas leva `aluno.html` empacotado: a área só chega depois do próximo envio; o servidor e o painel valem na hora.
- **Não incluído (decisão pendente)**: upload de arquivos e capas (hoje só link https), progresso/marcar como visto por conteúdo e aviso push de conteúdo novo.
- **Importação do CDD e capas (21/10/2026)**: o catálogo de fábrica dos Extras passou a ser o dos 4 módulos importados do CDD, **publicados para alunos ativos**: Cintura de Atleta (12 aulas, vem de "Bônus: Cintura de Atleta - Método Sai Pochete"), Treinos em casa (5), Assista antes de treinar (7) e Lives e aulas exclusivas (8). São 32 vídeos do YouTube (conferidos: todos existem e permitem incorporar). **6 aulas entram como RASCUNHO**, as que o CDD já guardava assim: Esteroides Anabolizantes para Mulheres, Remédios para Emagrecer, Molécula da Definição (em Lives) e Encontre as cargas ideais, Respiração correta, TUT (em Assista antes de treinar); o administrador decide no painel. Conteúdo vem do CDD (público feminino): cada módulo pode ter a regra de acesso trocada no painel.
- **Como o catálogo chega ao ar**: `app/api/_extras_cdd.php` (GERADO por `tools/extras/gerar-catalogo.py` a partir do seed do CDD; não editar à mão) é o catálogo de fábrica enquanto nada foi salvo em `intus_config`. Se o administrador já tiver salvo algo antes, o botão **"Importar do CDD"** do painel (`extras.php?action=importar_cdd`, `extras_importar_cdd`) cria o módulo que falta e preenche o que existe vazio; módulo com conteúdo nunca é tocado; idempotente (excluiu um módulo, o botão o traz de volta).
- **Capas** (`tools/extras/gerar-capas.py`, Pillow, fonte Impact): `app/painel/extras/capas/<slug>.jpg` (módulo, 4:3, 960x720, com título, selo EXTRAS e logo da Intus) e `<slug>-aula-<n>.jpg` (cada aula, 16:9, 640x360, com "AULA nn"), 36 arquivos, 1,1 MB, servidos por `https://intusfit.com.br/app/painel/extras/capas/...`. Usam só fotos da própria equipe, que já estão no site (`img/`, fora do git: prof-marlene, 024d6d53 com a Marlene e o Luiz, prof-luiz e luiz) e a logo `logo-intus-dark.png`; nada foi baixado da internet nem do Drive. Para trocar a foto de um módulo, ajuste `MODULOS` no script e rode `python tools/extras/gerar-capas.py img app/painel/extras/capas` (e `gerar-catalogo.py` se mudar o número de aulas).
- **Capa do conteúdo**: novo campo `capa` (https) em cada conteúdo (painel: "Imagem de capa do conteúdo"; app: usa a capa, senão o quadro do YouTube, senão um ícone).
- Testes: `testes/extras.php` (85) e `testes/extras_front.js` (42). `sw.js` cache `intus-v50`.
- **Capas ajustadas (21/10/2026, 2ª rodada)**: (1) **Treinos em casa** passou a usar a foto do Drive do Luiz (`f4c64d21-…_Original.JPG`, quadro de vídeo de treino em casa), trabalhada por `tools/extras/preparar-fotos.py`: o logo antigo MAXIMIZE da camiseta preta foi apagado (camiseta totalmente preta, sem logo), a marca d'água do canto e o contador do vídeo ficaram fora do recorte 4:3, com redução de ruído, nitidez e ajuste leve de contraste e cor; layout `cheia` (foto inteira, escurecida à esquerda para o logo e o título). (2) **Assista antes de treinar** estava distorcida porque a `img/prof-luiz.jpg` do site (600x600) está achatada na horizontal; a capa agora usa o original em retrato (`259fdefd-….jpg`, cortado acima do texto do cartão) como `tools/extras/fotos/luiz-academia.jpg`, sem distorção. As fotos preparadas ficam em `tools/extras/fotos/` (versionadas; `gerar-capas.py` procura lá primeiro). **A `prof-luiz.jpg` do site continua achatada** (aparece assim em outros lugares do site institucional, se usada); substituir pelo recorte natural é decisão do Luiz. As URLs das capas ganharam `?v=2` (sobem em `VERSAO` de `gerar-catalogo.py` quando as imagens forem refeitas) para o navegador e o Cloudflare não servirem a antiga; catálogo já salvo pelo administrador mantém as URLs antigas (cache de algumas horas).
- **Aulas com o Prof. Ricardo (21/10/2026, 3ª rodada)**: em Lives e aulas exclusivas, as aulas 4 (Remédios para Emagrecer, rascunho) e 6 (Aula sobre Lipedema) são com o Prof. Ricardo: ganharam a descrição "Com o Prof. Ricardo." (`DESCRICOES` em `gerar-catalogo.py`, por código do vídeo) e capa própria com a foto dele (Drive do Luiz, `IMG_0608.JPG`, 3072x3072, tratada por `preparar-fotos.py` em `tools/extras/fotos/ricardo.jpg`: 1600 px, mais escura e contrastada, cor do piso e do fundo reduzida, vinheta, para casar com o fundo escuro das outras; `FOTOS_AULA` em `gerar-capas.py` troca a foto por aula). Para outra aula com outra pessoa: foto em `tools/extras/fotos/`, entrada em `FOTOS_AULA` e a descrição em `DESCRICOES`. URLs das capas agora `?v=3`. Teste: `testes/extras.php` (87).
- **Capas com duas pessoas e cache (21/10/2026, 4ª rodada)**: as aulas 1 (Aeróbico), 2 (Hipopressivo ao vivo), 7 (3 pilares) e 8 (Perguntas e respostas) de Lives e aulas exclusivas têm capa gerada a partir da MINIATURA do próprio vídeo no YouTube (`tools/extras/fotos/yt/<código>.jpg`, 1280x720, de `img.youtube.com`; `MINIATURAS_AULA` em `gerar-capas.py`): a miniatura inteira, barra da cor do módulo, selo "AULA nn" no canto de baixo e a logo da Intus num selo escuro no canto de cima (a da aula 1 já traz logo e título e fica sem a extra).
- **Capa antiga que não trocava**: o Cloudflare guarda a imagem do mesmo endereço por 4 h (`cf-cache-status: HIT`, `max-age=14400`) e o catálogo que o administrador já tinha salvo guardava a URL sem versão; trocar o arquivo não adiantava. Agora o **servidor** acrescenta `?v=<EXTRAS_CAPAS_VERSAO>` a toda capa do próprio site na hora de entregar (`extras_capa_versionada`, no que o aluno recebe e no que o painel recebe), inclusive em catálogo já salvo, e troca uma versão antiga (`?v=2`) pela atual. **Ao refazer capas: subir `EXTRAS_CAPAS_VERSAO` em `_extras.php` e `VERSAO` em `tools/extras/gerar-catalogo.py` juntos** (um teste confere). Imagem de outro site passa sem mudança.

## 101. Figurinhas: falha de leitura não vira o padrão de fábrica (22/10/2026)
- **Sintoma (22/10)**: Notificações > Figurinhas mostrava só as 6 de emoji do padrão de fábrica ("6 / 150", incluindo "Alcateia do Ferro"), no lugar das figurinhas de imagem do Luiz. No backup de 15/10 a lista real tinha **31 figurinhas de imagem** (`/app/img/uploads/fig_*.jpg`) e os 31 arquivos continuam no servidor (conferidos, 200). Os arquivos não foram apagados: o que mudou foi a lista em `intus_config.figurinhas_config`.
- **Falhas que deixavam isso acontecer**: (1) `catalogo.php` gravava QUALQUER corpo: um PUT vazio ou sem `itens` virava `[]` e, daí em diante, o painel e o app caíam nas 6 de fábrica (`_FIGURINHAS_FALLBACK`); (2) o painel tratava resposta sem `itens` como "nunca configurado" e mostrava as de fábrica como se fossem as reais, e "Salvar figurinhas" trocava a lista de todos os alunos por elas.
- **Correção**: servidor (`figurinhas_config`): PUT sem `itens` em lista responde 422; antes de gravar guarda a lista de antes em `intus_config.figurinhas_config_anterior` (só uma, a do salvamento anterior); `GET ?anterior=1` devolve essa cópia (só administrador). Painel (`notificacoes.html`, `carregarFigurinhas`): leitura própria (`_figLer`); se falhar mostra "Não consegui carregar", nada de lista de fábrica e sem botão Salvar; se o servidor devolver formato inesperado mostra as de fábrica com aviso vermelho e pede confirmação para salvar; reduzir a lista a menos da metade também pede; botão "↩ Voltar à lista anterior (N)" traz a cópia para a tela (só vale depois de Salvar).
- **Recuperação**: a lista antiga pode ser restaurada do backup do banco no Drive (a linha `figurinhas_config` da tabela `intus_config`) ou da cópia de 15/10, e regravada pelo painel (nunca por script).
- Testes: `testes/figurinhas_servidor.php` (16, SQLite; tirar a validação é acusado) e `testes/figurinhas_painel.js` (21, Chromium: 500, HTML, `[]`, `{}`, lista boa, restaurar, redução). Sem mudança em `api.js` nem em `aluno.html`.
