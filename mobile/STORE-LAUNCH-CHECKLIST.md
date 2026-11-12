# Checklist de lançamento nas lojas — Intus Fit

Atualizada em **11 de novembro de 2026** (revisão de marca, privacidade e pacote). Esta checklist não substitui a revisão das políticas vigentes no dia do envio.

> Na revisão de 11/11/2026 vários itens estavam marcados como feitos sem estar (ícones e splash eram o padrão do Capacitor, o arquivo da ficha e a imagem de destaque não existiam, e a nota sobre dados de saúde estava errada). Os itens abaixo já refletem o estado real; cada correção diz o que mudou.

## Bloqueios de acesso

- [ ] Luiz aceitou os termos e mantém o papel de titular da conta Apple Developer Program.
- [ ] G4 OS recebeu papel necessário no App Store Connect, sem compartilhar senha, chave privada ou certificado.
- [ ] Luiz tem conta Google Play Console verificada e concedeu o papel necessário ao G4 OS.
- [ ] Identificador `br.com.intusfit.app` confirmado ou substituído antes da primeira build assinada.
- [ ] Chave de assinatura Android, certificados e perfis iOS foram criados e guardados fora do Git.

## Produto e conformidade

- [ ] Build de release testada em aparelho Android físico e iPhone físico.
- [ ] Fluxo de exclusão de conta e dados confirmado dentro do app ou por URL pública, se exigido pela loja.
- [x] Política de privacidade existe em `app/painel/privacidade.html` (URL: `https://intusfit.com.br/app/painel/privacidade.html`) e declara localização em primeiro plano, câmera/fotos, notificações, dados de treino e autenticação. Conteúdo conferido no repositório em 11/11/2026; **a publicação não foi reverificada** (sem acesso à URL na revisão): abrir a URL antes de enviar.
- [ ] **Atualizar a política de privacidade** antes de enviar: ela não cita CPF, gênero, a anamnese (dados de saúde: cirurgias, doenças, medicamentos), o conteúdo da comunidade (feed, comentários, mensagens), os registros de nutrição nem os vídeos de feedback; e fala em notificações push, mas o app usa só avisos locais. Texto jurídico é decisão do Luiz.
- [ ] Permissões pedidas no momento de uso, com explicação compreensível antes do diálogo do sistema — declarações nativas existem; falta validar em aparelhos físicos.
- [ ] Não há dados de aluno, token, chave de API, credencial ou captura de tela real em materiais de teste.
- [x] Login por Google/Facebook está oculto enquanto as credenciais forem placeholders, e o pacote nativo (`npm run build:web`) não carrega os SDKs do Google e do Facebook nem mostra os botões sociais. Se qualquer provedor for ativado no iOS, implementar e testar **Sign in with Apple** de forma equivalente antes do envio.
- [x] HealthKit e Health Connect não fazem parte da release atual (sem entitlement nem permissão de Saúde).
- [ ] **Declarar dados de saúde mesmo assim**: a anamnese pede cirurgias, doenças cardíacas e de pressão, medicamentos e limitações, e as avaliações guardam medidas e peso. Para as lojas isso é dado de saúde informado pelo usuário (App Privacy: Health; Data Safety: Health info). O `PrivacyInfo.xcprivacy` já declara; falta marcar nos formulários online. Tabela pronta em `STORE-LISTING-PT-BR.md`.
- [ ] Pacote nativo sem voz gravada enquanto `app/painel/voz/manifest.json` tiver `"licenciado": false` (áudio gerado sem licença comercial; `npm run build:web` já deixa a pasta de fora). Para incluir: licenciar as vozes (`tools/voz/LEIAME.md`).

## Materiais da ficha

- [x] Nome: Intus Fit.
- [x] Subtítulo/descrição curta preparado dentro dos limites atuais de cada loja em `STORE-LISTING-PT-BR.md` (arquivo criado em 11/11/2026; antes constava como feito mas não existia). **Rascunho: falta o Luiz aprovar.**
- [x] Descrição completa em português, sem promessas médicas ou métricas não verificadas, em `STORE-LISTING-PT-BR.md` (mesma ressalva: rascunho para aprovação).
- [ ] Categoria e classificação etária: concluir os questionários oficiais das lojas; URL de suporte e contatos já foram definidos.
- [x] Ícones iOS e Android e splash com a marca da Intus, gerados por `tools/mobile/gerar-assets-nativos.py` (iOS 1024 e lojas sem alfa; Android adaptativo com o símbolo dentro do círculo de 66%). Antes eram o ícone padrão do Capacitor (X azul sobre grade), apesar de o item constar como feito. **Falta conferir no Xcode, no Android Studio e num aparelho: nada disso foi compilado na revisão.**
- [x] Imagem de destaque Google Play 1024×500 em `store-assets/google-play-feature-graphic-1024x500.png` (gerada em 11/11/2026; o arquivo citado antes não existia).
- [ ] Capturas de tela feitas no app real, sem dados pessoais, nos aparelhos/tamanhos exigidos.
- [x] E-mail de suporte e endereço de política de privacidade acessíveis publicamente.
- [ ] Conta de demonstração e instruções de revisão fornecidas apenas se a revisão exigir login; não usar conta real de aluno.

## Formulários e envio

### App Store Connect

- [ ] Preencher App Privacy com base no comportamento real da release.
- [ ] Informar uso de localização e dados de Saúde, se e somente se habilitados.
- [ ] Preencher informações de export compliance (o `Info.plist` já traz `ITSAppUsesNonExemptEncryption = false`: o app só usa HTTPS), conteúdo e classificação etária.
- [ ] **Decidir iPad ou só iPhone** (`TARGETED_DEVICE_FAMILY` hoje `1,2` em `project.pbxproj`). Com iPad a Apple exige capturas de iPad e revisa o layout nele. Ver pergunta no PR de 11/11/2026.
- [ ] Revisar o botão "Conhecer outros planos" (abre o WhatsApp) frente à diretriz 3.1.1 da Apple.
- [ ] Anexar build assinada pelo Xcode/Transporter e selecionar a versão correta.
- [ ] Revisar App Review Guidelines, especialmente autenticação, privacidade, pagamentos e funcionalidades mínimas.

### Google Play Console

- [ ] Criar app, package name e track de testes interno fechado.
- [ ] Enviar AAB assinado; não publicar APK de produção.
- [ ] Preencher Data Safety com os dados coletados/compartilhados de fato.
- [ ] Declarar permissões sensíveis, anúncios (se houver), conteúdo e classificação etária. Alarme exato (`SCHEDULE_EXACT_ALARM`): texto da declaração pronto em `STORE-LISTING-PT-BR.md`. O manifesto não pede mais `READ_MEDIA_IMAGES` nem `READ_EXTERNAL_STORAGE` (o seletor do sistema dispensa).
- [ ] Testar instalação e atualização via track interno antes de produção.

## Evidência de aprovação interna

Antes de enviar à revisão, registrar:

- versão, commit Git e hash do AAB/IPA;
- modelos e versões de sistema usados no teste;
- permissões solicitadas e motivo;
- resultados de login, treino, cardio, logout, atualização e exclusão;
- diferenças conhecidas entre PWA e app nativo.
