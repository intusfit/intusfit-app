# Intus Fit — contexto do projeto

App e painel em produção (KingHost, domínio intusfit.com.br), quatro públicos: aluno, instrutor, gestão (Luiz), vitrine pública. Este arquivo existe para uma sessão nova não repetir erros já cometidos — mantenha-o atualizado quando descobrir algo novo sobre como o sistema realmente funciona.

## Regra mais importante: config do admin vs. fallback no código

Vários textos/imagens que parecem fixos no HTML são na verdade **configuráveis pelo admin** e só caem no valor fixo do código quando a busca ao servidor falha. Antes de tratar qualquer frase, figurinha, conquista etc. como "é assim que é", verifique se existe uma config por trás.

Exemplo real (achado em 2026-09-06): `app/painel/aluno.html` tem `_FIGURINHAS_FALLBACK` (linha ~1052), uma lista fixa incluindo "ALCATEIA DO FERRO" — uma figurinha **já excluída há muito tempo** da config real. Ela só aparece se `_fetchConfigDireto('figurinhas_config')` falhar (sem rede, ou testando localmente sem back-end). A config de verdade é editada em `admin/catalogo.php` (aba "Figurinhas" no painel do Luiz) e servida por `app/api/catalogo.php?action=figurinhas_config`. O mesmo comentário no código ("Busca uma config do admin: frases/figurinhas/conquistas") sugere que frases motivacionais e conquistas seguem o mesmo padrão — checar caso a caso antes de assumir.

## Arquitetura real (levantada em código, não suposta)

- **Vitrine pública**: `index.html` (raiz) — landing + login/cadastro do cliente.
- **App do aluno**: `app/painel/aluno.html` — um arquivo só, SPA, 19 telas alternadas via `trocarView(id)` / `navegar(destino)`. IDs reais: `v-home, v-treinos, v-along, v-muscu, v-fim, v-historico, v-perfil, v-exinfo, v-matricula, v-avaliacoes, v-nutricao, v-anamnese, v-termos, v-welcome, v-desafios, v-mensagens, v-ranking, v-cardio, v-quemsomos`.
- **App do instrutor**: arquivos separados em `app/painel/` (alunos.html, treinos.html, financeiro.html, mensalidades.html, usuarios.html, etc.) — cada tela é uma página própria, não uma SPA.
- **Painel de gestão (só Luiz)**: `admin/` — PHP puro (painel.php, catalogo.php). Não renderiza sem PHP+MySQL reais; não dá pra pré-visualizar localmente sem esses serviços.
- **Backend**: `app/api/*.php`, banco MySQL na própria KingHost. Credenciais em `app/config/db.php` (fora do git).
- **Autenticação do app**: token em `localStorage['mx-token']`, usuário em `localStorage['mx-user']` (`{tipo, idatleta, ...}`). Guards de bloqueio (`verificarInadimplencia`, `verificarSemMatricula`) só bloqueiam depois que o servidor confirma (`_syncDone && _matSyncOk`) — sem rede, nunca bloqueiam.
- Dados do dia a dia do app (fichas de treino, sessões) vivem em `localStorage` como cache (`intus-fichas`, `intus-treinos`, `intus-exercicios`) sincronizado do servidor — útil para testar telas localmente sem precisar de login real (ver seção abaixo).

## Testando telas localmente sem senha (Claude não pode digitar senhas)

Como não é permitido autenticar digitando senha real, para inspecionar uma tela do aluno:
1. Suba um servidor estático apontando pra pasta `site/` (ex: `python -m http.server`).
2. No console do navegador, sete `localStorage['mx-user']` com um objeto fake `{tipo:'aluno', idatleta:1, ...}` e `mx-token` com qualquer string, ANTES de navegar pra `aluno.html` (o guard de auth só olha se existe, não valida contra servidor de forma bloqueante).
3. Para ver fichas/treinos reais, semear `localStorage['intus-fichas']` e `intus-treinos']` no formato que `carregarFicha()` espera (ver aluno.html ~linha 2035).
4. Chamar as funções de navegação direto no console (`navegar('ranking')`, `abrirMeusTreinos()`, `abrirQuadroMedalhas()`) é mais confiável que clicar — vários cliques não registraram durante os testes de 2026-09-06.
5. Isso mostra o HTML/CSS/JS real do produto. Os NÚMEROS que aparecem são de exemplo (localStorage semeado), nunca dados de aluno de verdade.

## Deploy (montado em 2026-09-05/06)

- Git local em `site/` → GitHub privado `github.com/intusfit/intusfit-app`, branch `main`.
- KingHost "Publicação via GIT" está habilitado, aponta pra `/www/` (= pasta `site/` deste repo), webhook aciona sozinho a cada push.
- Segredos que NUNCA vão pro git (ver `.gitignore`): `admin/config.php`, `app/config/db.php`, `admin/content.json`, `admin/data.json`, uploads em `img/`, logs.
- Chave SSH do servidor: `~/.ssh/intus_deploy` (usuário `intusfit@ftp.intusfit.com.br`). Chave do GitHub: `~/.ssh/github_intusfit`.
- `git push` costuma ser bloqueado pelo classificador de auto-mode do Claude Code — nesse caso, rodar o comando direto no terminal do usuário resolve.

## Limites com G4OS

O G4OS também tem acesso direto ao servidor (SSH/FTP) e mexe nos mesmos arquivos (`app/api`, `app/config`, `app/painel`) para funções específicas: auditoria de matrículas e publicação nas lojas oficiais. Luiz só libera o G4OS depois que o ambiente estiver "empacotado" (ou seja: código já commitado/sincronizado aqui). Antes de assumir que um arquivo só muda por aqui, checar se não foi tocado por fora — arquivos `*.g4os-backup-*` no servidor são o rastro dele.

## Como preferimos trabalhar

- **Melhorias pontuais, não redesenhos completos.** Luiz rejeitou uma rodada de propostas de redesign de tela inteira (2026-09-06) — o caminho certo é ajuste específico e incremental, não recriar telas.
- Mudança mínima que resolve; nada de refatoração de carona.
- Sempre confirmar migração de banco ou dado de aluno antes de rodar.
