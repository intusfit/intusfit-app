# Backups do Intus Fit: como funcionam, como conferir e como restaurar

## O que é copiado e para onde
| O quê | Quando | Destino |
|---|---|---|
| Banco inteiro (todas as tabelas, estrutura e dados), compactado `.sql.gz` | 1 vez por dia | Pasta do Google Drive do app (`intus-backup-db-AAAA-MM-DD_HHMMSS.sql.gz`) e anexo no e-mail de aviso (se tiver até 8 MB) |
| Fotos de avaliação e avatares (`app/uploads`, `app/painel/uploads`) | Quando mudam | Drive, zip `intus-backup-...-uploads-...zip` |
| Fotos das refeições dos alunos (`app/img/nutri`) e fotos de receitas do painel (`app/img/receitas`) | Quando mudam | Drive (novo em 14/10/2026) |
| Código do site e do app | A cada `git push` | GitHub privado `intusfit/intusfit-app` |

Retenção: cópias com mais de 45 dias saem do Drive, mas sempre ficam pelo menos 10. Vídeos de posts e de feedback existem só no Drive (sem segunda cópia).

## Como o backup dispara
1. **Carona** (sem custo): o primeiro acesso do dia de um professor ou administrador ao painel dispara o backup depois de entregar a tela. Desde 14/10/2026 isso acontece pelo sino de notificações, que toda tela do painel consulta (antes dependia de `dashboard.php`, que quase nenhuma tela chamava).
2. **CronJob da KingHost** (pago, roda todo dia no horário, mesmo sem ninguém abrir o painel): no painel da KingHost, CronJob, cadastre a URL `https://intusfit.com.br/app/api/cron-backup.php` e copie o "Header de validação X-Cron-Auth". Crie `app/config/cron.php` no servidor (modelo em `app/config/cron-exemplo.php`) com esse valor. **O Luiz faz esse passo (é um acesso dele).**

## Conferir (todo mês, 5 minutos)
No painel, Configurações > Servidor > Backups:
- **Ver backups**: mostra o último backup, as cópias no Drive, onde o banco está hospedado e para onde vai o e-mail.
- **Conferir a última cópia**: baixa a cópia mais recente do Drive e confere que o arquivo está inteiro (compactação, cabeçalho, rodapé, tabelas e dados).
- **Testar o e-mail**: manda um e-mail de teste e mostra o erro exato do servidor de e-mail, se houver. Escolha ali o e-mail que recebe o aviso.
- **Fazer backup agora**: roda o backup completo na hora.

O painel avisa no topo (só administrador) se faz 2 dias ou mais sem backup.

## Teste de restauração (de verdade)
Restaurar a cópia mais recente num banco VAZIO de teste e conferir:
1. Baixe `intus-backup-db-...sql.gz` do Drive para o seu computador.
2. Confira o arquivo sem tocar em banco nenhum:
   `php tools/backup/restaurar.php --arquivo=intus-backup-db-AAAA-MM-DD_HHMMSS.sql.gz --so-ler`
3. Crie um banco de teste vazio (painel da KingHost, MySQL, novo banco, por exemplo `intusfit_teste`) e importe. A senha vai só pelo ambiente:
   ```powershell
   $env:DB_HOST="mysql..."; $env:DB_NAME="intusfit_teste"; $env:DB_USER="..."; $env:DB_PASS="..."
   php tools/backup/restaurar.php --arquivo=ARQ.sql.gz --confirmo-banco=intusfit_teste
   ```
4. Compare o número de tabelas e as contagens (alunos, sessões, mensalidades) com o banco de produção. Apague o banco de teste depois.

## Emergência (voltar o banco de produção)
Só com o OK do Luiz e depois de baixar uma cópia de segurança do estado atual. O import faz `DROP TABLE` e recria cada tabela do arquivo: tudo o que mudou depois do backup é perdido. Repita o passo 3 acima apontando para o banco de produção e o nome correto em `--confirmo-banco`.

## Riscos conhecidos
- O dump é feito linha a linha, sem travar as tabelas: uma gravação no meio do backup pode ficar de fora (a cópia do dia seguinte pega).
- O arquivo do banco tem dados pessoais, anamneses, financeiro e hashes de senha. Quem acessa a pasta do Drive e a caixa de e-mail do aviso acessa tudo isso: mantenha o acesso restrito e use verificação em duas etapas nessas contas.

## Teste de restauração feito em 15/10/2026
- **Cópia sintética**: o script real do backup (`cron-backup.php`) gerou o dump de um banco MariaDB local com os casos difíceis (aspas simples e duplas, `;` e `);` dentro do texto, barra invertida, emoji, NULL, texto de 70 KB, tabela vazia, 1.200 linhas), o `restaurar.php` importou num banco vazio e a comparação linha a linha (soma de verificação por tabela) deu **idêntica** nas 4 tabelas.
- **Cópia real do Drive**: baixar o `intus-backup-db-*.sql.gz` mais recente da pasta do Drive para `C:\Users\User\IntusFit\restore-teste\` e rodar `--so-ler` e depois a importação num banco de teste. A cópia real vem de MySQL 5.6 e o teste local usa MariaDB: diferenças de versão aparecem aqui antes de uma emergência.
- **Como repetir**: `winget install MariaDB.Server`, criar um banco vazio de teste e seguir o passo 3 acima, com `DB_HOST=127.0.0.1:PORTA`. Apagar o banco de teste depois.
