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
**`upload-drive.php` não existe** (ver seção 11, problema 4 — diagnosticado por completo em 06/09).

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
   Falta ainda conferir a **autorização**: se um professor autenticado consegue ler aluno de outro
   professor. A permissão por dono era conferida só no navegador. Vale testar com dois usuários.

3. **`maximizeapp.com.br` serve uma cópia viva e velha do painel** (v=20260520d, de maio) cujo
   `api.js` aponta para `https://intusfit.com.br/app/api` — **o mesmo banco de produção**. Quem tiver
   link antigo grava dado real com a lógica financeira de maio. Redirecionar ou apagar antes de o
   domínio expirar. **Ação é do Luiz** (gestão de domínio), não dá para resolver só no código.

4. **`upload-drive.php` não existe no servidor** (nem a pasta `vendor/` do Google). Diagnosticado por
   completo em 06/09/2026: `avaliacoes.html` (linha ~1452) chama esse endpoint para subir fotos ao
   Drive; ele nunca existiu. O que existe é `app/api/_gdrive.php`, uma **biblioteca pronta**
   (`gdriveDisponivel()`, `gdriveBackup()`) que fica inativa até existir `app/config/gdrive.json`
   — mas falta o arquivo endpoint que liga o front-end a ela. Além disso, o erro é **duplamente
   silencioso**: cada foto falha dentro do próprio laço de envio (`catch` por arquivo) e nunca chega
   ao aviso que já existe pronto no código ("Fotos não enviadas..."). Resultado: toda foto de
   avaliação enviada até hoje foi descartada sem que nenhum professor visse aviso nenhum. Conserto
   avaliado tem duas partes independentes (criar o endpoint; corrigir o silêncio do erro) — a chave
   do Drive quem gera e coloca no servidor é o Luiz (ver seção 2).

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
