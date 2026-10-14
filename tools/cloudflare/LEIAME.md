# Cloudflare gratuito na frente do intusfit.com.br

O que muda para o Intus: o Cloudflare passa a receber os acessos antes da KingHost. Ele guarda cópia de imagens, scripts e estilos (menos pedidos para os poucos processos da hospedagem), segura ataques e bots, e informa de onde cada acesso vem (cidade, país, fuso), que alimenta a tela "Acessos dos usuários" e o quadro da Frequência e Ranking. O código do site já está preparado: só confia no IP e na localização que o Cloudflare informa quando a conexão vem mesmo de um servidor dele (`app/api/_ip.php`).

**Quem faz o quê:** os passos abaixo mexem em contas do Luiz (Cloudflare, Registro.br, KingHost). O Claude não entra nelas nem digita senha. Dá para fazer em ~30 minutos. O e-mail e o site continuam funcionando durante a troca, desde que os registros de e-mail fiquem como "somente DNS" (item 2).

## 1. Criar a conta e adicionar o site
1. Entre em cloudflare.com e crie a conta (plano **Free**).
2. "Add a site" > `intusfit.com.br` > plano Free. O Cloudflare lê os registros atuais do DNS da KingHost sozinho.

## 2. Conferir os registros importados (o passo mais importante)
Na tela de DNS, cada registro tem uma nuvem laranja ("Proxied") ou cinza ("DNS only"):

| Registro | Nuvem |
|---|---|
| `intusfit.com.br` (A) e `www` | **Laranja** (passa pelo Cloudflare) |
| `smtp`, `pop`, `imap`, `mail`, `webmail`, `ftp` e qualquer outro de e-mail ou FTP | **Cinza (DNS only)**. Se ficarem laranja, **o e-mail para de funcionar** |
| `MX` (mx-vip-01 e mx-vip-02 da KingHost) | O Cloudflare já deixa cinza |
| `TXT` do SPF (`v=spf1 include:_spf.kinghost.net -all`) | Manter como está |

Conferido em 14/10/2026 no DNS público: MX nos servidores da KingHost, SPF ok, `smtp.intusfit.com.br` é CNAME de `smtp-vip.kinghost.net`. **Falta o DMARC**: crie um registro `TXT` com nome `_dmarc` e valor `v=DMARC1; p=none; rua=mailto:contato@luiznunest.com.br` (só observa e manda relatórios; melhora a entrega dos e-mails da Intus).

## 3. Trocar os servidores de nome no Registro.br
1. O Cloudflare mostra dois nomes (algo como `xxx.ns.cloudflare.com`).
2. No **Registro.br** (domínio .com.br), abra `intusfit.com.br` > DNS > "Alterar servidores DNS" e troque `dns1.kinghost.com.br` e `dns2.kinghost.com.br` pelos dois do Cloudflare.
3. Se o domínio tiver **DNSSEC** ligado no Registro.br, desligue antes de trocar (e ligue de novo depois pelo Cloudflare, se quiser).
4. A troca leva de minutos a algumas horas. O Cloudflare avisa por e-mail quando ficar ativo.

## 4. Ajustes no Cloudflare (depois de ativo)
- **SSL/TLS > Overview:** modo **Full (strict)** (a KingHost tem certificado válido, Let's Encrypt). **Edge Certificates:** "Always Use HTTPS" ligado, "Minimum TLS Version" 1.2.
- **Rules > Transform Rules > Managed Transforms:** ligue **"Add visitor location headers"**. É isso que faz cidade, região, país e fuso aparecerem nos acessos.
- **Caching > Cache Rules:** crie uma regra "se o caminho começa com `/app/api/` então **Bypass cache**". A API nunca deve ser guardada em cópia. O resto (imagens, js, css) pode ser guardado.
- **Security > Settings:** nível de segurança "Medium". **Bot Fight Mode: deixe DESLIGADO** (ele pode bloquear os avisos do WhatsApp/Meta e da Eduzz, que são robôs de verdade). Se quiser ligar, crie antes uma regra "Skip" para `/app/api/whatsapp_webhook.php` e para o caminho do webhook da Eduzz.
- **Security > WAF > Rate limiting rules** (o plano gratuito tem 1 regra): "se o caminho é `/app/api/aluno_login.php` ou `/app/api/usuarios.php` e passar de 20 pedidos em 10 segundos pelo mesmo IP, bloquear por 10 minutos". Complementa as travas que o site já tem.
- **Speed > Optimization:** Brotli ligado. Não ligue "Rocket Loader" nem "Auto Minify" (o site tem muito script embutido).

## 4.1 O que NÃO passa pelo Cloudflare
Conexões ao banco, SMTP, FTP e o Git da KingHost vão direto ao servidor (não são web). O app das lojas continua apontando para `https://intusfit.com.br/app/api`, que agora é atendido pelo Cloudflare; não precisa rebuild.

## 5. Conferir depois de ativo (10 minutos)
1. Abrir `https://intusfit.com.br/` e o painel; entrar como aluno e como professor.
2. Configurações > Servidor > Backups > **Fazer backup agora** (a barra deve andar até 100%) e **Testar os dois e-mails e o DNS**.
3. Entrar de novo no painel e abrir Configurações > **Acessos dos usuários**: o IP deve ser o seu (não um IP do Cloudflare) e a cidade deve aparecer. Se aparecer só o IP e "sem localização", falta ligar o "Add visitor location headers" (item 4).
4. Fazer uma compra de teste ou conferir o próximo aviso da Eduzz e do WhatsApp (os webhooks).
5. Se algo falhar e for urgente: no Cloudflare, "Pause Cloudflare on Site" desliga o proxy em segundos sem mexer no Registro.br.

## 6. Limites honestos
O Cloudflare não substitui um servidor maior: o banco e o PHP continuam na KingHost. Ele reduz o que chega lá (arquivos estáticos e ataques), mas um pico de usuários logados no app ainda usa os processos do PHP. O backup (barra de progresso) e as telas já foram ajustados para segurar o processo o mínimo possível.
