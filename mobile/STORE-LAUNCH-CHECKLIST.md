# Checklist de lançamento nas lojas — Intus Fit

Atualizada em **22 de setembro de 2026**; ícones, telas de abertura e materiais gráficos revisados em **11 de novembro de 2026**; configuração nativa de privacidade, backup, permissões e assinatura revisada em **12 de novembro de 2026**. Esta checklist não substitui a revisão das políticas vigentes no dia do envio.

## Bloqueios de acesso

- [ ] Luiz aceitou os termos e mantém o papel de titular da conta Apple Developer Program.
- [ ] G4 OS recebeu papel necessário no App Store Connect, sem compartilhar senha, chave privada ou certificado.
- [ ] Luiz tem conta Google Play Console verificada e concedeu o papel necessário ao G4 OS.
- [ ] Identificador `br.com.intusfit.app` confirmado ou substituído antes da primeira build assinada.
- [ ] Chave de assinatura Android, certificados e perfis iOS foram criados e guardados fora do Git. O `build.gradle` já lê caminho e senhas de variáveis de ambiente ou de `~/.gradle/gradle.properties` (`INTUS_KEYSTORE_FILE`, `INTUS_KEYSTORE_PASSWORD`, `INTUS_KEY_ALIAS`, `INTUS_KEY_PASSWORD`; ver `README.md`, seção Assinatura e privacidade) e o `mobile/.gitignore` bloqueia chaves e perfis; falta o Luiz criar e guardar a chave.

## Produto e conformidade

- [ ] Build de release testada em aparelho Android físico e iPhone físico.
- [ ] Fluxo de exclusão de conta e dados confirmado dentro do app ou por URL pública, se exigido pela loja.
- [x] Política de privacidade publicada e verificada em URL pública: `https://intusfit.com.br/app/painel/privacidade.html`. Ela declara localização em primeiro plano, câmera/fotos, notificações, dados de treino e autenticação.
- [x] Configuração nativa de privacidade e permissões (12/11/2026, conferida por `testes/nativo_config.js`, **sem compilar**): iOS com `NSMicrophoneUsageDescription` em português (vídeos gravados na câmera do sistema para posts e feedbacks), `ITSAppUsesNonExemptEncryption = false`, região `pt-BR` e `PrivacyInfo.xcprivacy` do app (dados coletados declarados, nenhuma API de motivo obrigatório, incluído no target pelo `project.pbxproj`); Android com `allowBackup="false"`, regras de extração e backup que excluem tudo, e **sem** `READ_MEDIA_IMAGES` nem `READ_EXTERNAL_STORAGE` (o app usa o seletor do sistema).
- [ ] Permissões pedidas no momento de uso, com explicação compreensível antes do diálogo do sistema — declarações nativas existem; falta validar em aparelhos físicos.
- [ ] Não há dados de aluno, token, chave de API, credencial ou captura de tela real em materiais de teste.
- [x] Login por Google/Facebook está oculto enquanto as credenciais forem placeholders. Se qualquer provedor for ativado no iOS, implementar e testar **Sign in with Apple** de forma equivalente antes do envio.
- [x] HealthKit e Health Connect não fazem parte da release atual; não declarar dados de Saúde nos formulários.
- [x] Conteúdo gerado por usuário (Apple 1.2 e Google Play): filtro de termos graves no servidor; **Denunciar** em publicação, comentário, mural, resultado, mensagem e perfil; **Bloquear** nos dois sentidos, com a lista Pessoas bloqueadas em Meu Perfil › Privacidade; Regras da Comunidade aceitas antes do primeiro post, comentário ou mensagem; Termos de Uso com tolerância zero e resposta em até 24 horas; fila de denúncias no painel (Feed › Denúncias), aviso no sino e e-mail à equipe; suspensão por aluno. Contato publicado: contato@intusfit.com.br. Detalhes e texto para as notas da revisão: `CLAUDE.md`, seção 145.
- [ ] Antes de enviar: Luiz confirma as 4 tabelas novas (`intus_bloqueio`, `intus_denuncia`, `intus_suspensao`, `intus_aceite_regras`), roda `cd mobile && npm run sync` e gera a build nova (o app nativo só ganha a moderação assim) e testa num aparelho: denunciar, bloquear, desbloquear e aceitar as regras.

## Materiais da ficha

- [x] Nome: Intus Fit.
- [ ] Subtítulo/descrição curta dentro dos limites atuais de cada loja. O arquivo `STORE-LISTING-PT-BR.md`, citado antes aqui, não está no repositório (não há histórico dele no Git): escrever e versionar.
- [ ] Descrição completa em português, sem promessas médicas ou métricas não verificadas (mesmo arquivo `STORE-LISTING-PT-BR.md`, que ainda precisa existir).
- [ ] Categoria e classificação etária: concluir os questionários oficiais das lojas; URL de suporte e contatos já foram definidos.
- [x] Ícones e telas de abertura da marca Intus no lugar dos desenhos de exemplo do Capacitor: iOS 1024 sem alfa, Android adaptativo (fundo, primeiro plano, monocromático) e antigo, Play 512. Gerados por `tools/marca/gerar-icones-lojas.py` e conferidos por `testes/icones_lojas.js`. **Falta ver num aparelho ou emulador** (o ambiente que os gerou não compila o app nativo).
- [x] Imagem de destaque Google Play 1024×500 em `store-assets/google-play-feature-graphic-1024x500.png` (gerada pelo mesmo script; revisar a arte no Play Console).
- [ ] Capturas de tela feitas no app real, sem dados pessoais, nos aparelhos/tamanhos exigidos.
- [x] E-mail de suporte e endereço de política de privacidade acessíveis publicamente.
- [ ] Conta de demonstração e instruções de revisão fornecidas apenas se a revisão exigir login; não usar conta real de aluno.

## Formulários e envio

### App Store Connect

- [ ] Preencher App Privacy com base no comportamento real da release. O `PrivacyInfo.xcprivacy` do app já lista os dados coletados (nome, e-mail, telefone, localização exata e aproximada, fotos e vídeos, saúde, fitness, código de usuário, conteúdo e mensagens do aluno); o formulário no App Store Connect precisa dizer o mesmo. CPF e data de nascimento também são coletados no cadastro: o Luiz confirma a categoria no formulário.
- [ ] Informar uso de localização e dados de Saúde, se e somente se habilitados.
- [ ] Preencher informações de export compliance, conteúdo e classificação etária. O `Info.plist` já declara `ITSAppUsesNonExemptEncryption = false` (só HTTPS); o Luiz confirma a resposta no App Store Connect.
- [ ] Anexar build assinada pelo Xcode/Transporter e selecionar a versão correta.
- [ ] Revisar App Review Guidelines, especialmente autenticação, privacidade, pagamentos e funcionalidades mínimas.

### Google Play Console

- [ ] Criar app, package name e track de testes interno fechado.
- [ ] Enviar AAB assinado; não publicar APK de produção.
- [ ] Preencher Data Safety com os dados coletados/compartilhados de fato (usar a mesma lista do `PrivacyInfo.xcprivacy`).
- [ ] Declarar permissões sensíveis, anúncios (se houver), conteúdo e classificação etária. Declaração de `SCHEDULE_EXACT_ALARM` (texto no `README.md`, seção Notificações locais) ainda precisa ser escrita no formulário.
- [ ] Testar instalação e atualização via track interno antes de produção.

## Decisões e pontos abertos que dependem do Luiz ou de aparelho (12/11/2026)

- [ ] **iPad:** o projeto iOS tem `TARGETED_DEVICE_FAMILY = "1,2"` (iPhone e iPad) e o `Info.plist` trava o iPad em retrato com `UIRequiresFullScreen`. Decidir: publicar para iPhone e iPad (a Apple pede capturas de tela de iPad e a revisão testa no iPad) ou só iPhone (`TARGETED_DEVICE_FAMILY = 1`). Não alterado.
- [ ] Texto de declaração de `UIBackgroundModes = audio` (voz e bips dos programas de cardio com a tela apagada) para a revisão da Apple.
- [ ] Texto de declaração de `SCHEDULE_EXACT_ALARM` (avisos de troca de bloco na hora exata) para o Google Play.
- [ ] Localização em segundo plano: não existe nem foi pedida; o app só lê a localização com a tela aberta (Cardio ao vivo e local de post).
- [ ] Abrir `ios/App/App.xcodeproj` no Xcode e conferir que `PrivacyInfo.xcprivacy` aparece no target App (o arquivo foi ligado ao projeto à mão, sem Xcode) e que a região do projeto (`developmentRegion = en` no `project.pbxproj`) não gera aviso diante do `pt-BR` do `Info.plist`.
- [ ] Gerar o AAB com as variáveis de assinatura e conferir que sai assinado; sem elas o `build.gradle` avisa e gera sem assinatura. `minifyEnabled` segue `false` de propósito.
- [ ] Num aparelho Android: escolher foto e vídeo na galeria sem a permissão de mídia (deve abrir o seletor do sistema) e gravar um vídeo pela câmera.
- [ ] Num iPhone: o aviso de microfone aparece em português ao gravar vídeo pelo seletor de arquivo (`accept` com vídeo), e o app abre sem erro de privacidade.

## Evidência de aprovação interna

Antes de enviar à revisão, registrar:

- versão, commit Git e hash do AAB/IPA;
- modelos e versões de sistema usados no teste;
- permissões solicitadas e motivo;
- resultados de login, treino, cardio, logout, atualização e exclusão;
- diferenças conhecidas entre PWA e app nativo.
