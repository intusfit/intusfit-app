# Ficha das lojas — Intus Fit (pt-BR)

Texto pronto para colar no App Store Connect e no Google Play Console. Criado em 11/11/2026 a partir do que o app faz hoje (`app/painel/aluno.html`) e do que a política de privacidade publicada declara. **Nada aqui foi aprovado pelo Luiz ainda**: leia antes de enviar. Vocabulário do projeto: *aluno*, *consultoria*, *prescrição*, *plano* (a Intus não é academia).

Regras que o texto segue: sem promessa de resultado, prazo ou número; sem alegação médica; sem dado de aluno real.

## Dados fixos

| Campo | Valor |
|---|---|
| Nome do app | Intus Fit |
| Identificador | `br.com.intusfit.app` (confirmar titularidade, ver checklist) |
| Idioma principal | Português (Brasil) |
| Categoria principal | Saúde e fitness (Health & Fitness) |
| Site / URL de marketing | https://intusfit.com.br |
| URL de suporte | https://intusfit.com.br |
| E-mail de suporte | o mesmo contato público da política de privacidade (`contato@luiznunest.com.br`); trocar se houver um e-mail só da Intus |
| Política de privacidade | https://intusfit.com.br/app/painel/privacidade.html |
| Termos de uso | https://intusfit.com.br/app/painel/termos.html |
| Preço | Gratuito, sem compras dentro do app e sem anúncios |

## App Store (iOS)

Limites atuais: nome 30, subtítulo 30, texto promocional 170, palavras-chave 100, descrição 4000 caracteres.

**Subtítulo** (28)
```
Treino e nutrição no celular
```

**Texto promocional** (editável sem nova versão)
```
Registre seus treinos e cardios, acompanhe sua nutrição e fale com a sua equipe da Intus Fit, tudo no mesmo app.
```

**Palavras-chave**
```
treino,musculação,cardio,nutrição,consultoria,personal,ficha de treino,academia,corrida,dieta
```

**Descrição**
```
A Intus Fit é uma consultoria online de treino e nutrição. Este é o aplicativo do aluno: aqui você vê o que seu professor e sua nutricionista prescreveram e registra o que fez.

TREINO
• Veja sua ficha de treino com séries, repetições, carga, intervalo e vídeos de execução
• Registre cada série, use o cronômetro de descanso e acompanhe a evolução das suas cargas
• Alongamentos indicados pela sua equipe

CARDIO
• Registre corrida, caminhada, bike e outras atividades
• Cardio ao vivo com GPS: distância, ritmo e trajeto (a localização só é usada enquanto você está nessa tela)
• Programas guiados com avisos de voz e vibração a cada troca de bloco, como HIIT, Tabata e Do sofá aos 5 km

NUTRIÇÃO
• Seu plano alimentar, refeição por refeição
• Marque o que você seguiu, controle a água do dia e veja receitas e dicas

ACOMPANHAMENTO
• Avaliações físicas, fotos de evolução e anamnese
• Mensagens e feedbacks em vídeo da sua equipe
• Gráficos de evolução e histórico de treinos

COMUNIDADE
• Ranking e medalhas por frequência, conquistas e desafios
• Feed para compartilhar seu treino, amigos, parceiros de treino e turmas

COMO COMEÇAR
Entre com o e-mail e a senha da sua conta. Ainda não é aluno? Crie sua conta pelo app.

AVISO
As informações do aplicativo não substituem a avaliação de um profissional de saúde. Converse com seu médico antes de começar ou mudar sua atividade física.
```

**O que há de novo (1.0)**
```
Primeira versão do Intus Fit para iPhone.
```

## Google Play (Android)

Limites atuais: título 30, descrição curta 80, descrição completa 4000 caracteres.

**Título**: `Intus Fit`

**Descrição curta** (≤ 80)
```
Seu treino, cardio e nutrição com a sua equipe da Intus Fit.
```

**Descrição completa**: a mesma da App Store (sem o trecho "Primeira versão para iPhone").

**Imagem de destaque**: `store-assets/google-play-feature-graphic-1024x500.png`. **Ícone**: `store-assets/google-play-icon-512.png`.

## Notas para a revisão (colar nos campos de review das duas lojas)

```
O Intus Fit é o aplicativo do aluno de uma consultoria online de treino e nutrição. O acesso é por conta (e-mail e senha). Os planos são contratados fora do aplicativo; o app não vende nada e não tem compras dentro dele.

Conta de demonstração: [preencher direto no App Store Connect / Play Console: e-mail e senha de uma conta de TESTE criada só para a revisão. Não registrar credenciais neste arquivo nem no repositório.]

Permissões, todas pedidas só no momento de uso:
• Localização (apenas com o app aberto): na tela Cardio ao vivo, para calcular distância, ritmo e trajeto. Não há localização em segundo plano.
• Câmera, fotos e microfone: para fotos e vídeos de treino que o aluno publica e para fotos de avaliação. O microfone grava o som dos vídeos tirados pelo app.
• Notificações: avisos locais de troca de bloco nos programas de cardio e aviso de treino em andamento. Não usamos notificação push.
• Áudio em segundo plano (iOS, UIBackgroundModes = audio): as instruções de voz e os sinais sonoros dos programas de cardio precisam continuar com a tela bloqueada.
• Alarmes exatos (Android, SCHEDULE_EXACT_ALARM): ver a declaração abaixo.

Exclusão de conta: Meu Perfil > Excluir minha conta (dentro do app). O acesso é bloqueado na hora e os dados pessoais são removidos em até 30 dias.
Login por Google e Facebook não está habilitado nesta versão.
```

## Declaração de alarme exato (Google Play Console > Conteúdo do app)

O formulário pede a funcionalidade principal que depende do alarme exato. Escolher a opção mais próxima de **temporizador / cronômetro de treino** (as opções do formulário mudam com o tempo; conferir no dia).

Português:
```
O Intus Fit usa SCHEDULE_EXACT_ALARM somente para os programas de cardio guiados (por exemplo HIIT e Tabata). O aluno toca em Iniciar e o app agenda um aviso local, com vibração, para cada troca de bloco de esforço e de descanso, na hora exata que o programa define. Com a tela apagada, um aviso atrasado em minutos torna o programa inútil. Os alarmes só existem enquanto o programa está em andamento: são cancelados ao pausar ou encerrar e nunca disparam sem uma ação do aluno. Se a permissão não for concedida, o app funciona com agendamento aproximado e avisa o aluno uma única vez, com um atalho para a tela Alarmes e lembretes.
```
English:
```
Intus Fit uses SCHEDULE_EXACT_ALARM only for guided cardio programs (for example HIIT and Tabata). When the user taps Start, the app schedules a local notification with vibration for each effort/rest block change at the exact time the program defines. With the screen off, a notification that arrives minutes late makes the program useless. Alarms exist only while a program is running: they are cancelled on pause or stop and never fire without a user action. If the permission is not granted, the app falls back to approximate scheduling and tells the user once, with a shortcut to the Alarms & reminders settings.
```

## Respostas prontas para App Privacy (Apple) e Data Safety (Google)

Base: o que o app coleta de fato (cadastro, `login.html`; anamnese, `aluno.html`; recursos do app) e `app/painel/privacidade.html`. Nada é usado para rastreamento nem publicidade; nenhuma ferramenta de análise de terceiros dentro do app. O arquivo `ios/App/App/PrivacyInfo.xcprivacy` declara os mesmos tipos para a Apple.

| Dado | O que é no app | App Privacy (Apple) | Data Safety (Google) | Vinculado à conta | Finalidade |
|---|---|---|---|---|---|
| Nome | Cadastro | Contact Info > Name | Personal info > Name | Sim | Funcionamento do app |
| E-mail | Cadastro e login | Contact Info > Email Address | Personal info > Email address | Sim | Funcionamento do app |
| Telefone | Cadastro | Contact Info > Phone Number | Personal info > Phone number | Sim | Funcionamento do app |
| CPF, data de nascimento, gênero | Cadastro | Other Data > Other Data Types | Personal info > Other info | Sim | Funcionamento do app |
| Identificador da conta | Código do aluno e sessão | Identifiers > User ID | Personal info > User IDs | Sim | Funcionamento do app |
| Treinos, cardio, frequência, pontuação, nutrição registrada | Registros do aluno | Health & Fitness > Fitness | Health and fitness > Fitness info | Sim | Funcionamento do app |
| Anamnese (cirurgias, doenças, medicamentos, limitações), medidas, peso e altura | Perguntas da anamnese e avaliação | Health & Fitness > Health | Health and fitness > Health info | Sim | Funcionamento do app |
| Fotos e vídeos | Feed, avaliação, perfil, refeições | User Content > Photos or Videos | Photos and videos | Sim | Funcionamento do app |
| Posts, comentários, mensagens | Feed, chat com a equipe, mensagens entre amigos | User Content > Other User Content | Messages > Other in-app messages | Sim | Funcionamento do app |
| Localização precisa | Cardio ao vivo e local de posts, só em primeiro plano | Location > Precise Location | Location > Precise location | Sim | Funcionamento do app |

- **Rastreamento (Apple)**: não. **Compartilhamento com terceiros (Google)**: não declarar compartilhamento para publicidade. Os vídeos de exercício são incorporados do YouTube e as fontes vêm do Google Fonts: conferir com o Luiz se isso conta como compartilhamento no formulário.
- **Criptografia em trânsito (Google)**: sim (HTTPS). **Exclusão de dados**: sim, pelo app; o Google também pede uma URL pública com as instruções (usar a política de privacidade, seção 5 e 6, ou uma página própria).
- **Mensalidades** (valor, vencimento, status) são lançadas pela equipe e só exibidas no app; não são coletadas do aparelho, por isso não entram na tabela.
- **Faixa etária**: o app tem conteúdo gerado por usuários (feed, comentários, mensagens entre alunos) e perguntas de saúde; responder os questionários oficiais com isso em mente. A política já pede supervisão de responsável para menores de 18 anos.

## Pontos de atenção antes de enviar (não decididos)

1. **A política de privacidade publicada está incompleta** para essas respostas: não cita CPF, gênero, a anamnese (dados de saúde, dado sensível na LGPD), o conteúdo da comunidade (feed, comentários, mensagens), os registros de nutrição nem os vídeos de feedback. Atualizar o texto antes de enviar; a Apple compara a política com as respostas do App Privacy.
2. **"Conhecer outros planos" abre o WhatsApp** com os dados da matrícula (aba Matrícula do perfil). A Apple pode questionar chamadas para contratação fora do app (diretriz 3.1.1). Decidir com o Luiz se o botão fica na versão das lojas.
3. **Notificações**: a política fala em notificações push; o app usa só avisos locais. Ajustar o texto de uma das duas pontas.
4. **iPad**: ver a pergunta sobre `TARGETED_DEVICE_FAMILY` no PR. Se o app aceitar iPad, a Apple exige capturas de tela de iPad.
5. **Capturas de tela** ainda precisam ser feitas num aparelho real, com conta de demonstração e sem dado pessoal.
