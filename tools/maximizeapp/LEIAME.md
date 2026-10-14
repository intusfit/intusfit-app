# Tirar tudo do domínio maximizeapp.com.br

O domínio `maximizeapp.com.br` não será renovado. Este roteiro lista o que pode depender dele e como desligar sem derrubar o Intus Fit.

## 1. O que o código do site faz hoje
Nenhum arquivo do repositório aponta para `maximizeapp.com.br` (conferido em 14/10/2026: PHP, JS, HTML e JSON). As referências que sobram estão só em documentos. Os pontos que podem depender dele estão **no servidor, fora do repositório**, e por isso o painel agora mostra a verdade:

> Painel do administrador > Configurações > Servidor > Backups > **Ver backups**: a linha **Banco de dados** mostra em qual servidor o banco está (e fica vermelha se for `maximizeapp`) e a linha **Servidor de e-mail** faz o mesmo. O topo do painel também avisa. (Para o servidor aparecer ali, é preciso ter um backup no registro: use "Fazer backup agora".)

## 2. O banco de produção (o ponto crítico)
O `CLAUDE.md` registra que o banco de produção era `maximizeapp03` em `mysql.maximizeapp.com.br`. O `app/config/db.php` que está neste computador (cópia local, de 05/09) aponta para outro servidor (`mysql50-farm1.kinghost.net`, base `intusfit`), o que sugere que a mudança foi feita pelo G4OS. **Só a tela "Ver backups" diz o que o servidor no ar usa de fato.**

Se aparecer `maximizeapp` na linha do banco, migrar assim (o Luiz faz os passos que pedem acesso dele; o resto o Claude prepara):
1. No painel da KingHost, do domínio **intusfit.com.br**, criar um banco MySQL novo (ex.: `intusfit`) e um usuário só dele.
2. Fazer um backup agora (botão no painel) e baixar a cópia do Drive.
3. Importar a cópia no banco novo com `tools/backup/restaurar.php` (ver `tools/backup/RESTAURAR.md`) e conferir contagens.
4. **Janela curta:** avisar a equipe, fazer um novo backup, importar de novo no banco novo e trocar o `app/config/db.php` do servidor para o banco novo (mexe em senha: é um passo do Luiz, o Claude não toca em senhas).
5. Verificar o painel e o app do aluno (login, treino, mensagens) e rodar "Fazer backup agora" para confirmar que o painel mostra o servidor novo.
6. Manter o banco antigo parado (sem apagar) por 30 dias e só então pedir a exclusão pelo painel da KingHost. Exclusão de dado é sempre manual.

## 3. A cópia velha do painel em maximizeapp.com.br
O domínio ainda serve `app/painel/` de maio, apontando para a API do intusfit. Quem tiver o link antigo grava dado real com regras velhas. Duas ações (a pasta desse domínio não faz parte deste repositório; é pelo FTP/gerenciador de arquivos da KingHost):
- Apagar `app/painel` da pasta de `maximizeapp.com.br` e colocar no lugar o arquivo `htaccess-redirecionar-para-intusfit.txt` (desta pasta) renomeado para `.htaccess`: todo acesso passa a ir para `https://intusfit.com.br/`.
- Depois de 30 dias sem uso, remover o domínio do plano.

## 4. Outros lugares onde o domínio pode estar (conferir no painel da KingHost e nos serviços)
- **E-mail**: contas `@maximizeapp.com.br` e o `smtp.php` do servidor (a tela de backups mostra o host de e-mail em uso). O aviso de backup e os e-mails do app já usam `intusfit.com.br`.
- **Google (chave do Drive, login social) e Eduzz**: URLs de retorno/webhook cadastradas com `maximizeapp.com.br`.
- **Meta/WhatsApp**: URL do webhook (`/app/api/whatsapp_webhook.php`) precisa ser a do intusfit.
- **DNS e certificados** do domínio antigo: nada além do redirecionamento deve ficar ativo.
- Apps nas lojas: `br.com.intusfit.app` aponta para `https://intusfit.com.br/app/api` (confirmado em `mobile/scripts/build-web.mjs`).
