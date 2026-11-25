# Respostas prontas para App Store Connect e Google Play (25/11/2026)

Substitui as partes desatualizadas de `STORE-LISTING-PT-BR.md`, `ANDROID-PLAY-RESPOSTAS.md` e `STORE-LAUNCH-CHECKLIST.md` (que ainda dizem que Saúde e Health Connect não fazem parte da release: agora fazem). Baseado no código desta data e em `app/painel/privacidade.html` (atualizada em 25/11/2026). Confira cada resposta na tela de cada loja: os formulários mudam.

Dados fixos: nome **Intus Fit**, pacote/bundle `br.com.intusfit.app`, categoria **Saúde e fitness**, gratuito, sem anúncios, sem compra no app (a mensalidade é paga fora do app). Política: https://intusfit.com.br/app/painel/privacidade.html. Termos: https://intusfit.com.br/app/painel/termos.html. Exclusão de conta: dentro do app (Meu Perfil > Excluir minha conta) e em https://intusfit.com.br/app/painel/excluir-conta.html. Suporte: contato@intusfit.com.br. Site: https://intusfit.com.br.

## 1. Textos da ficha (atualizados)

### Descrição curta do Google Play (80 caracteres)
`Treino, cardio ao vivo, nutrição e evolução em um só app.`

### Subtítulo da App Store (30 caracteres)
`Treino, cardio e nutrição`

### Texto promocional da App Store (170 caracteres)
`Seu treino, seu cardio ao vivo e sua alimentação acompanhados pelo profissional da sua consultoria, com a sua evolução sempre à mão.`

### Descrição completa (as duas lojas)
```text
O Intus Fit é o app da sua consultoria de treino e nutrição. Tudo o que o seu profissional prescreve e acompanha fica num só lugar, simples de usar na academia, na rua ou em casa.

TREINO
• Sua ficha de treino com séries, cargas e vídeos de execução
• Registro de cada treino, com histórico e evolução
• Pontos, ranking, conquistas e desafios para manter a rotina

CARDIO
• Cardio ao vivo para corrida, caminhada e pedalada: distância, ritmo, trajeto no mapa e altitude, inclusive com a tela bloqueada
• Programas de cardio guiados, com voz e avisos de troca de bloco, que tocam junto com a sua música

NUTRIÇÃO
• Plano alimentar da sua nutricionista, com horários, quantidades e substituições
• Marque cada refeição, registre a água do dia e veja a sua aderência
• Lembretes opcionais de refeição e de água nos horários do seu plano
• Receitas práticas e dicas

EVOLUÇÃO
• Avaliações físicas, medidas e fotos
• Monte um comparativo de fotos (antes e depois) e escolha se quer compartilhar
• Integração opcional com Apple Saúde (iPhone) e Health Connect (Android)

COMUNIDADE
• Feed, mural de resultados, amigos e turmas, com controle de quem pode ver cada publicação

O acesso é para alunos da consultoria Intus. O conteúdo de treino e de nutrição é definido pelos profissionais responsáveis. O app não substitui avaliação médica: em caso de dor, mal-estar ou limitação, interrompa o exercício e procure orientação profissional.
```

### Palavras-chave da App Store (100 caracteres)
`treino,academia,cardio,corrida,musculação,dieta,nutrição,evolução,consultoria,personal`

## 2. Notas para o revisor (App Store e Play: "Acesso ao app")

```text
O Intus Fit é o aplicativo de alunos de uma consultoria de treino e nutrição. O acesso é por conta criada pelo profissional. Para a revisão, use a conta de demonstração informada nos campos de login do App Store Connect / Play Console (aluno de teste com matrícula e plano alimentar ativos, sem dados reais).

O que testar:
1) Treino: Meus Treinos > escolha uma ficha > Iniciar.
2) Cardio ao vivo: Meu Cardio > Cardio ao vivo > Corrida > Continuar na tela de aviso > permita a localização > Começar. A localização precisa é usada só durante o cardio que o aluno inicia, inclusive com a tela bloqueada, e o sistema mostra um aviso fixo enquanto isso acontece. Ao finalizar, a coleta para.
3) Nutrição: menu > Nutrição > Meu plano alimentar. Os lembretes de refeição e de água são opcionais (cartão "Lembretes") e são notificações locais agendadas no aparelho.
4) Evolução: Avaliações > Comparar minhas fotos. O comparativo só é publicado no Feed depois de uma tela de confirmação.
5) Integrações (opcional): Início > "Conectar saúde e apps" abre as Integrações e conecta Apple Saúde / Health Connect. O app lê passos, distância, calorias ativas, sono, frequência cardíaca de repouso, peso e treinos, e grava apenas os treinos feitos pelo próprio Intus Fit. Nada de saúde é compartilhado com terceiros nem usado para publicidade.
6) Exclusão de conta: Meu Perfil > Excluir minha conta (o acesso é bloqueado na hora e os dados são removidos em até 30 dias).

Conteúdo gerado por usuários: posts, fotos e comentários têm botão Denunciar, moderação com alerta ao administrador e possibilidade de ocultar, excluir e bloquear contas. Cada publicação tem controle de quem pode ver (todos, amigos ou só o autor).

Não há compra de conteúdo ou serviço digital dentro do app; a mensalidade da consultoria é contratada e paga fora dele. Não há anúncios nem rastreamento entre apps. Login com Google e Facebook está desativado nesta versão.
```

## 3. App Store Connect: App Privacy (Privacidade do app)

Rastreamento (tracking): **Não** (não há anúncios, nem cruzamento de dados com terceiros).

| Tipo de dado | Coletado | Vinculado ao usuário | Usado para rastrear | Finalidade |
|---|---|---|---|---|
| Informações de contato (nome, e-mail, telefone) | Sim | Sim | Não | Funcionalidade do app, gerenciamento de conta |
| Saúde e fitness (saúde: avaliações, anamnese, plano alimentar, dados de Saúde; fitness: treinos, cardio) | Sim | Sim | Não | Funcionalidade do app |
| Localização precisa | Sim (só no Cardio ao vivo) | Sim | Não | Funcionalidade do app |
| Fotos ou vídeos | Sim | Sim | Não | Funcionalidade do app |
| Conteúdo do usuário (mensagens, posts, comentários) | Sim | Sim | Não | Funcionalidade do app |
| Informações financeiras (status de mensalidade) | Sim | Sim | Não | Funcionalidade do app |
| Identificadores (ID de usuário do app) | Sim | Sim | Não | Funcionalidade do app |
| Dados de uso (interações no app) | Sim | Sim | Não | Funcionalidade do app |

Não coleta: contatos, histórico de navegação, compras no app, dados de diagnóstico de terceiros, identificador de publicidade.

Informações adicionais: exportação de criptografia já declarada no `Info.plist` (`ITSAppUsesNonExemptEncryption`). iPhone apenas (ver item 7).

## 4. Google Play: Segurança dos dados (Data safety)

Coleta dados: **sim**. Criptografados em trânsito: **sim** (HTTPS). Usuário pode pedir exclusão: **sim** (no app e pela URL de exclusão). Dados compartilhados com terceiros: **não** (os prestadores citados na política, como hospedagem e Google Drive, atuam em nosso nome e são "processadores": não contam como compartilhamento).

| Categoria | Tipo | Coletado | Finalidade | Opcional |
|---|---|---|---|---|
| Informações pessoais | Nome, e-mail, telefone, data de nascimento | Sim | Funcionalidade do app, gerenciamento de conta | Não |
| Saúde e fitness | Informações de saúde (avaliação, anamnese, nutrição, Health Connect) e de fitness (treinos, cardio) | Sim | Funcionalidade do app | Dados do Health Connect: sim, opcional |
| Localização | Localização precisa | Sim (só no Cardio ao vivo) | Funcionalidade do app | Sim |
| Fotos e vídeos | Fotos e vídeos | Sim | Funcionalidade do app | Sim |
| Mensagens | Outras mensagens no app | Sim | Funcionalidade do app | Sim |
| Informações financeiras | Histórico de pagamentos (status da mensalidade) | Sim | Gerenciamento de conta | Não |
| Atividade no app | Interações no app | Sim | Funcionalidade do app | Não |
| IDs de dispositivo ou outros | Não | | | |

Não coletamos: contatos, calendário, microfone, dados de cartão, histórico de navegação.

## 5. Google Play: declaração do Health Connect

Uso: o app mostra a evolução do aluno (passos, distância, calorias ativas, sono, frequência cardíaca de repouso, peso, treinos) e grava os treinos feitos pelo Intus Fit para o aluno ter tudo no mesmo lugar. É opcional: só liga quando o aluno conecta em Integrações (Início > Conectar saúde e apps).

| Permissão | Justificativa (texto para o formulário) |
|---|---|
| `READ_STEPS` | Mostrar os passos do aluno na evolução diária. |
| `READ_DISTANCE` | Mostrar a distância percorrida e sugerir o registro de cardios feitos fora do app. |
| `READ_ACTIVE_CALORIES_BURNED` | Mostrar o gasto de calorias ativas na evolução. |
| `READ_EXERCISE` | Listar treinos feitos no celular ou relógio e sugerir o registro como cardio. |
| `READ_SLEEP` | Mostrar o sono na evolução, para o aluno e o profissional acompanharem a recuperação. |
| `READ_RESTING_HEART_RATE` | Mostrar a frequência cardíaca de repouso como indicador de condicionamento. |
| `READ_WEIGHT` | Mostrar a evolução do peso ao lado das avaliações. |
| `WRITE_EXERCISE` | Gravar no Health Connect os treinos que o aluno faz pelo Intus Fit (tipo, data, duração). |
| `WRITE_DISTANCE` | Gravar a distância dos cardios ao vivo feitos no Intus Fit. |
| `WRITE_ACTIVE_CALORIES_BURNED` | Gravar as calorias ativas estimadas dos treinos feitos no Intus Fit. |

Os dados de saúde ficam visíveis somente para o aluno, não são compartilhados com terceiros nem usados para publicidade, e a desconexão em Integrações apaga o que foi lido dos nossos servidores. A política de privacidade descreve isso no item 1.

## 6. Google Play: permissões e declarações

- **Serviço em primeiro plano, tipo localização** (`FOREGROUND_SERVICE_LOCATION`): "Medir distância, ritmo e trajeto do Cardio ao vivo com a tela bloqueada. O aluno inicia, vê uma notificação fixa e para quando quiser."
- **Alarmes exatos** (`SCHEDULE_EXACT_ALARM`): "Avisar a troca de bloco dos programas de cardio e os lembretes de refeição e de água que o aluno liga, na hora exata, mesmo com a tela apagada."
- **Localização em segundo plano**: o app não declara `ACCESS_BACKGROUND_LOCATION` (o rastreio nasce com o app aberto e o serviço em primeiro plano o mantém). Se o console pedir o formulário, use o vídeo curto do cardio com a tela bloqueada.
- **Câmera**: foto do treino, avaliação, comparativo e perfil. **Notificações**: lembretes e avisos locais. **Internet**: tudo.
- **Divulgação em destaque da localização**: a tela "Medir o cardio com a tela apagada" (`_cardioLiveBgAviso`) e a folha "Preparar o app" aparecem antes do pedido do sistema. Anexe uma captura delas se o formulário pedir.
- **Anúncios**: não. **Público-alvo**: 18 anos ou mais. **Classificação (IARC)**: app de fitness com conteúdo de usuários moderado; responder o questionário no console e aceitar o resultado.
- **Acesso ao app**: informar a conta de demonstração e as instruções do item 2.

## 7. iPhone apenas

O projeto iOS estava para iPhone e iPad (`TARGETED_DEVICE_FAMILY = 1,2`), o que exigiria capturas de iPad e testes em iPad. O app foi desenhado em retrato para celular; passou a ser **só iPhone** (`TARGETED_DEVICE_FAMILY = 1`). Quem tem iPad ainda consegue abrir o app no modo de compatibilidade do iPhone.

## 8. Riscos de revisão a conferir

- **Apple 5.1.1(ix) (saúde):** pode pedir conta de organização. Se a Apple pedir, é o caso de abrir a conta como empresa; não há como contornar por código.
- **Apple 3.1.1 / 3.1.3 (pagamentos):** o app não vende nada. O botão "Conhecer outros planos" abre o WhatsApp. Se a revisão recusar por isso, trocar o botão por um texto sem link de compra.
- **Apple 1.2 (conteúdo de usuário):** exige denúncia, bloqueio e moderação. Existem denúncia e moderação; cite isso nas notas.
- **Apple 4.0 e 5.1.5:** localização em segundo plano precisa de justificativa clara: está nas notas do revisor.
- **Google, conta pessoal nova:** pode exigir teste fechado com cerca de 12 testadores por 14 dias antes da produção.
