# Google Play Console: respostas prontas (21/11/2026)

Baseadas na política de privacidade (`app/painel/privacidade.html`) e no que o app faz. Confira cada resposta na tela antes de enviar: os formulários mudam.

## Dados do app
- Nome: Intus Fit. Pacote: `br.com.intusfit.app`. Versão 1.0 (código 1). Categoria sugerida: Saúde e fitness. Gratuito.
- Política de privacidade: https://intusfit.com.br/app/painel/privacidade.html
- Exclusão de conta (URL): https://intusfit.com.br/app/painel/excluir-conta.html
- Contato: contato@luiznunest.com.br. Site: https://intusfit.com.br
- Anúncios: não. Compras no app: não (a cobrança da consultoria é feita fora do app, pelo site).
- Acesso ao app: precisa de login. Informar a conta de demonstração (e-mail e senha) e que ela tem matrícula ativa. Nunca usar conta de aluno real.

## Segurança dos dados (Data safety)
Coleta dados: sim. Todos criptografados em trânsito (HTTPS): sim. O usuário pode pedir exclusão: sim (no app e pela URL acima). Compartilhamento com terceiros: não (só provedores que operam o serviço, como hospedagem e Google Drive para arquivos, o que o Google trata como "processamento", não compartilhamento).

| Categoria | Tipo | Coletado | Finalidade | Opcional |
|---|---|---|---|---|
| Informações pessoais | Nome, e-mail, telefone, data de nascimento | Sim | Funcionalidade do app, gerenciamento de conta | Não |
| Saúde e fitness | Informações de fitness (treinos, cardio, medidas, peso, altura), dados de saúde (anamnese e avaliação) | Sim | Funcionalidade do app | Não (anamnese e medidas são do serviço) |
| Localização | Localização aproximada e precisa | Sim, só no Cardio ao vivo | Funcionalidade do app | Sim |
| Fotos e vídeos | Fotos e vídeos | Sim | Funcionalidade do app (feed, avaliação, perfil) | Sim |
| Mensagens | Outras mensagens no app (chat e comentários) | Sim | Funcionalidade do app | Sim |
| Atividade no app | Interações no app | Sim | Funcionalidade do app, análise | Não |
| Informações financeiras | Histórico de compras/pagamentos | Sim (status de mensalidade) | Gerenciamento de conta | Não |
| IDs de dispositivo ou outros | Não | | | |

Não coletamos: contatos, calendário, microfone (o app não grava áudio), dados de cartão (a cobrança é externa), histórico de navegação.

## Classificação de conteúdo (questionário IARC)
App de fitness com conteúdo gerado por usuários (posts, comentários, fotos com moderação e botão Denunciar). Violência, sexo, linguagem imprópria, drogas e jogos: nenhum conteúdo próprio. Responder "sim" a conteúdo gerado por usuários e interação entre usuários, e informar que há moderação e denúncia. Compartilha localização com outros usuários: não.

## Público-alvo
18 anos ou mais (a política exige responsável legal para menores). Não é app para crianças.

## Permissões sensíveis
- Serviço em primeiro plano, tipo localização (`FOREGROUND_SERVICE_LOCATION`): "Medir distância, ritmo e trajeto do Cardio ao vivo com a tela bloqueada. O aluno inicia, vê uma notificação fixa e para quando quiser." Vídeo curto: abrir o app, Meu Cardio, Cardio ao vivo, tela de aviso, permitir localização, Começar, bloquear a tela, ver a notificação fixa, desbloquear, Finalizar.
- Alarmes exatos (`SCHEDULE_EXACT_ALARM`): "Avisar a troca de bloco dos programas de cardio (timer) na hora exata, mesmo com a tela apagada."
- Localização: só com o Cardio ao vivo aberto. Não usa `ACCESS_BACKGROUND_LOCATION`.
- Câmera: foto de treino, avaliação e perfil. Notificações: mensagens do professor e lembretes.

## Conteúdo gerado por usuários (política UGC)
Há denúncia de posts e comentários dentro do app, moderação automática com alerta ao administrador e decisão de manter, ocultar ou excluir, e a equipe pode remover conteúdo e bloquear contas.
