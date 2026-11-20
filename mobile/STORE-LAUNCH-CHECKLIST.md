# Checklist de lançamento nas lojas — Intus Fit

Atualizada em **22 de setembro de 2026**. Esta checklist não substitui a revisão das políticas vigentes no dia do envio.

## Bloqueios de acesso

- [ ] Luiz aceitou os termos e mantém o papel de titular da conta Apple Developer Program.
- [ ] G4 OS recebeu papel necessário no App Store Connect, sem compartilhar senha, chave privada ou certificado.
- [ ] Luiz tem conta Google Play Console verificada e concedeu o papel necessário ao G4 OS.
- [ ] Identificador `br.com.intusfit.app` confirmado ou substituído antes da primeira build assinada.
- [ ] Chave de assinatura Android, certificados e perfis iOS foram criados e guardados fora do Git.

## Produto e conformidade

- [ ] Build de release testada em aparelho Android físico e iPhone físico.
- [ ] Fluxo de exclusão de conta e dados confirmado dentro do app ou por URL pública, se exigido pela loja.
- [x] Política de privacidade publicada e verificada em URL pública: `https://intusfit.com.br/app/painel/privacidade.html`. Ela declara localização em primeiro plano, câmera/fotos, notificações, dados de treino e autenticação.
- [ ] Permissões pedidas no momento de uso, com explicação compreensível antes do diálogo do sistema — declarações nativas existem; falta validar em aparelhos físicos.
- [ ] Não há dados de aluno, token, chave de API, credencial ou captura de tela real em materiais de teste.
- [x] Login por Google/Facebook está oculto enquanto as credenciais forem placeholders. Se qualquer provedor for ativado no iOS, implementar e testar **Sign in with Apple** de forma equivalente antes do envio.
- [x] HealthKit e Health Connect não fazem parte da release atual; não declarar dados de Saúde nos formulários.

## Materiais da ficha

- [x] Nome: Intus Fit.
- [x] Subtítulo/descrição curta preparado dentro dos limites atuais de cada loja em `STORE-LISTING-PT-BR.md`.
- [x] Descrição completa em português, sem promessas médicas ou métricas não verificadas, preparada em `STORE-LISTING-PT-BR.md`.
- [ ] Categoria e classificação etária: concluir os questionários oficiais das lojas; URL de suporte e contatos já foram definidos.
- [x] Ícones finais exportados nos tamanhos exigidos por iOS e Android, sem alfa.
- [x] Imagem de destaque Google Play 1024×500 preparada em `store-assets/google-play-feature-graphic-1024x500.png`.
- [ ] Capturas de tela feitas no app real, sem dados pessoais, nos aparelhos/tamanhos exigidos.
- [x] E-mail de suporte e endereço de política de privacidade acessíveis publicamente.
- [ ] Conta de demonstração e instruções de revisão fornecidas apenas se a revisão exigir login; não usar conta real de aluno.

## Formulários e envio

### App Store Connect

- [ ] Preencher App Privacy com base no comportamento real da release.
- [ ] Informar uso de localização (precisa, também em segundo plano durante o Cardio ao vivo; vinculada ao usuário; não usada para rastreamento entre apps). Dados de Saúde: não usados.
- [ ] Notas de revisão: usar o texto de `STORE-LISTING-PT-BR.md` (passo a passo do Cardio ao vivo com a tela bloqueada) e a conta de demonstração. O modo `location` em `UIBackgroundModes` é justificado por esse uso.
- [ ] Preencher informações de export compliance, conteúdo e classificação etária.
- [ ] Anexar build assinada pelo Xcode/Transporter e selecionar a versão correta.
- [ ] Revisar App Review Guidelines, especialmente autenticação, privacidade, pagamentos e funcionalidades mínimas.

### Google Play Console

- [ ] Criar app, package name e track de testes interno fechado.
- [ ] Enviar AAB assinado; não publicar APK de produção.
- [ ] Preencher Data Safety com os dados coletados/compartilhados de fato.
- [ ] Declarar permissões sensíveis, anúncios (se houver), conteúdo e classificação etária.
- [ ] **Serviço em primeiro plano do tipo localização** (Conteúdo do app > Permissões de serviço em primeiro plano): `FOREGROUND_SERVICE_LOCATION`. Descrição: "Medir distância, ritmo e trajeto do Cardio ao vivo com a tela bloqueada. O aluno inicia, vê uma notificação fixa e para quando quiser." Anexar um vídeo curto (tela do app: tela de aviso, permissão, Começar, bloquear a tela, notificação fixa, desbloquear, Finalizar).
- [ ] O app NÃO declara `ACCESS_BACKGROUND_LOCATION` (o rastreio nasce com o app aberto e o serviço em primeiro plano o mantém), então o formulário de "localização em segundo plano" do Play não se aplica. Se o Google pedir, a justificativa é a mesma acima.
- [ ] Confirmar a tela de aviso (`_cardioLiveBgAviso`) nas capturas: é a "divulgação em destaque" exigida pela política de dados do usuário.
- [ ] Testar instalação e atualização via track interno antes de produção.

## Evidência de aprovação interna

Antes de enviar à revisão, registrar:

- versão, commit Git e hash do AAB/IPA;
- modelos e versões de sistema usados no teste;
- permissões solicitadas e motivo;
- resultados de login, treino, cardio, logout, atualização e exclusão;
- diferenças conhecidas entre PWA e app nativo.

## Localização com a tela apagada (20/11/2026)

Plugin `@capacitor-community/background-geolocation@1.3.1` (MIT, suporta Capacitor 8) instalado e sincronizado nos dois projetos. Preparado no repositório:

- `capacitor.config.json`: `android.useLegacyBridge = true` (sem isso o Android para de entregar posições depois de 5 minutos em segundo plano).
- Android: o plugin traz `FOREGROUND_SERVICE`, `FOREGROUND_SERVICE_LOCATION`, `POST_NOTIFICATIONS` e o `<service ... foregroundServiceType="location">`. Canal, ícone (`ic_stat_intus`) e cor do aviso fixo em `strings.xml`.
- iOS: `UIBackgroundModes` = `audio` + `location`, e `NSLocationAlwaysAndWhenInUseUsageDescription` no `Info.plist`.
- App (`aluno.html`): tela de aviso antes da permissão, plugin no rastreio, sem estimativa de distância quando o plugin mede; se o aluno recusar ou o sistema negar, cai no GPS do navegador com a regra de estimativa. Teste: `testes/cardio_tela.js`.

**Falta (só nas lojas e em aparelho real):** compilar (Android Studio e Xcode, num Mac), testar num Android e num iPhone de verdade com a tela bloqueada por 10 minutos (distância, aviso fixo, bateria), gravar o vídeo do formulário do Play e enviar para revisão. O resto do que depende de código está pronto.
