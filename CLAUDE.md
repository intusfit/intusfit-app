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

- **Cortina de giro (07/10/2026)**: onde a trava não funciona (Safari no iPhone, navegador comum no Android, PWA do iOS, ou app nativo antigo),
  o `aluno.html` mostra uma tela "Gire o celular para a vertical" (`#giro-aviso`) quando o aparelho está em paisagem e não há vídeo em
  tela cheia. Usa a orientação FÍSICA (`screen.orientation.type` / `window.orientation`), não a forma da janela, para o teclado aberto não
  acender a cortina. A trava também é reaplicada a cada `resize`, `orientationchange` e volta da aba. O treino segue rodando por trás.

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
