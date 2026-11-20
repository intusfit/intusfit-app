# Ficha de loja — Intus Fit (rascunho PT-BR)

Preparada em **22 de setembro de 2026** para preenchimento no App Store Connect e Google Play Console. Validar os limites e os formulários vigentes dentro de cada console antes do envio.

## Identidade e contatos

| Campo | Valor proposto |
|---|---|
| Nome do app | Intus Fit |
| Package / Bundle ID | `br.com.intusfit.app` |
| Categoria principal | Saúde e fitness |
| Site de suporte | https://intusfit.com.br |
| E-mail de suporte | contato@intusfit.com.br |
| Política de privacidade | https://intusfit.com.br/app/painel/privacidade.html |
| Termos de uso | https://intusfit.com.br/app/painel/termos.html |
| Modelo de acesso | Conta fornecida pelo profissional/consultoria; não há compra de serviços digitais dentro do app. |

## App Store Connect

### Subtítulo (até 30 caracteres)

`Seu treino, sua evolução`

### Texto promocional (até 170 caracteres)

`Treinos prescritos, evolução acompanhada e cardio ao vivo para você seguir seu plano onde estiver.`

### Descrição

```text
O Intus Fit é o app para acompanhar sua consultoria de treino de um jeito simples, direto e no ritmo da sua rotina.

Com ele, você pode:

• Ver sua ficha de treino prescrita pelo profissional
• Registrar séries, cargas e a conclusão de cada treino
• Acompanhar seu histórico e sua evolução
• Registrar corrida, caminhada e pedalada no Cardio ao vivo, com distância, ritmo e trajeto enquanto o recurso estiver ativo
• Consultar desafios, pontuação e conquistas da sua jornada
• Trocar informações com o profissional responsável pelo seu acompanhamento

Seu treino é individual. As fichas e orientações exibidas no app são definidas pelo profissional de educação física responsável pela sua consultoria.

O Intus Fit não substitui avaliação médica. Em caso de dor, mal-estar ou limitação, interrompa o exercício e procure orientação profissional.
```

### Palavras-chave (até 100 caracteres; separar por vírgula)

`treino,academia,personal,cardio,corrida,musculação,evolução,consultoria fitness`

### URL de suporte

`https://intusfit.com.br`

### Notas para revisão

```text
O Intus Fit é uma plataforma de acompanhamento de treinos para alunos de consultoria. O acesso é feito com credenciais fornecidas pelo profissional responsável.

A função Cardio ao vivo usa a localização precisa do aparelho, inclusive com a tela bloqueada ou o app em segundo plano, apenas do início ao fim de um cardio que o aluno inicia (corrida, caminhada ou pedalada), para medir distância, ritmo e trajeto. Antes da permissão do sistema o app mostra uma tela de aviso explicando isso, com a opção "Agora não" (medir só com a tela aberta). No Android o serviço em primeiro plano exibe uma notificação fixa durante o cardio; no iOS vale o indicador de localização do sistema. Ao finalizar, pausar ou descartar, a localização deixa de ser usada. Não há rastreamento fora do Cardio ao vivo.

Para testar: faça login com a conta de demonstração, abra Meu Cardio > Cardio ao vivo, escolha Corrida, toque em Continuar na tela de aviso, permita a localização, toque em Começar, bloqueie a tela e caminhe alguns minutos. Ao desbloquear, a distância e o trajeto continuam registrados.

Não há compra de conteúdo ou serviço digital dentro do aplicativo. A solicitação de exclusão de conta está disponível em Meu Perfil > Excluir minha conta; o acesso é bloqueado imediatamente e a remoção definitiva dos dados é concluída em até 30 dias, conforme a política de privacidade.

[ANTES DO ENVIO: incluir conta de demonstração temporária e instruções atualizadas para o revisor. Nunca usar credenciais ou dados reais de aluno.]
```

### Classificação etária

Preencher o questionário do App Store Connect conforme o conteúdo efetivamente distribuído. Não declarar uma classificação final sem concluir o questionário da Apple; a presença de conteúdo social e de saúde/fitness deve ser respondida de forma fiel.

## Google Play Console

### Descrição curta (até 80 caracteres)

`Seu treino personalizado, evolução e cardio ao vivo em um só app.`

### Descrição completa (até 4.000 caracteres)

```text
O Intus Fit reúne sua rotina de treino e o acompanhamento da sua consultoria em um só lugar.

Acesse sua ficha de treino, registre séries e cargas, acompanhe seu histórico e mantenha sua evolução sempre por perto.

Principais recursos:

• Fichas de treino personalizadas pelo profissional responsável
• Registro de treinos, séries, cargas e histórico
• Cardio ao vivo para corrida, caminhada e pedalada, com distância, ritmo e trajeto enquanto o recurso está ativo
• Acompanhamento de desafios, pontuação e conquistas
• Dados de evolução e avaliações físicas
• Comunicação e acompanhamento junto ao profissional responsável

O uso de localização é opcional e acontece somente do começo ao fim de um Cardio ao vivo que você inicia. Para medir com a tela bloqueada, o app usa a localização em segundo plano durante o cardio e mostra um aviso fixo enquanto isso. O Intus Fit não rastreia sua localização fora do Cardio ao vivo.

O conteúdo de treino é definido pelo profissional de educação física responsável pelo acompanhamento. O app não substitui avaliação médica. Em caso de dor, desconforto ou mal-estar, interrompa a atividade e procure orientação profissional.
```

### E-mail e site

- E-mail: `contato@intusfit.com.br`
- Site: `https://intusfit.com.br`
- Política de privacidade: `https://intusfit.com.br/app/painel/privacidade.html`

### Classificação de conteúdo

Responder o questionário IARC dentro do Play Console. Não selecionar uma idade manualmente antes do resultado do questionário.

## Dados e privacidade — base para os formulários

A política publicada foi verificada em 22/09/2026. Os formulários **App Privacy** e **Data Safety** precisam refletir a release final, não este rascunho.

| Dado/permite | Uso declarado | Observação para o formulário |
|---|---|---|
| Nome, e-mail, telefone, data de nascimento | Cadastro e conta | Identificadores pessoais |
| Treinos, cardio, pontuação, desafios | Funcionalidade do app | Atividade física / conteúdo do usuário |
| Peso, altura, medidas e fotos de avaliação | Acompanhamento físico | Informação pessoal potencialmente sensível; declarar fielmente conforme o formulário |
| Localização precisa | Cardio ao vivo iniciado pelo aluno | Coletada com o app aberto e também em segundo plano/tela bloqueada, só durante o cardio; não compartilhada nem usada para publicidade; processada no servidor da Intus para o histórico do aluno |
| Câmera e fotos | Perfil, feed e avaliações | Apenas após ação explícita do aluno |
| Dados de mensalidade | Gestão da relação aluno–professor | Não há coleta de cartão no app |
| Logs de acesso | Segurança e auditoria | Data e hora de login |
| Notificações locais | Avisos durante a sessão de treino | Não há infraestrutura de push remoto na release atual |

Declarar a localização em segundo plano (Cardio ao vivo). Não declarar HealthKit, Health Connect, publicidade, rastreamento entre apps/sites, venda de dados ou compras no app: esses recursos não fazem parte da release atual.

## Recursos gráficos

| Asset | Arquivo | Situação |
|---|---|---|
| Ícone App Store | `store-assets/app-store-icon-1024.png` | Pronto |
| Ícone Google Play | `store-assets/google-play-icon-512.png` | Pronto |
| Imagem de destaque Google Play | `store-assets/google-play-feature-graphic-1024x500.png` | Pronta (1024 × 500) |
| Capturas de tela iOS | A capturar em iPhone real | Bloqueado por teste físico |
| Capturas de tela Android | A capturar em Android real | Bloqueado por teste físico |

As capturas devem usar uma conta de demonstração sem dados pessoais, mostrar a interface real e corresponder à build enviada. Não criar mockups ou sobrepor informação que não exista no app.

## Links oficiais consultados

- Apple — [informações de versão](https://developer.apple.com/help/app-store-connect/reference/app-information/platform-version-information)
- Apple — [capturas de tela](https://developer.apple.com/help/app-store-connect/manage-app-information/upload-app-previews-and-screenshots)
- Google Play — [criar e configurar o app](https://support.google.com/googleplay/android-developer/answer/9859152)
- Google Play — [assets de prévia](https://support.google.com/googleplay/android-developer/answer/9866151)
