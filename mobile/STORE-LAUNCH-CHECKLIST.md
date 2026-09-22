# Checklist de lançamento nas lojas — Intus Fit

Atualizada em **7 de setembro de 2026**. Esta checklist não substitui a revisão das políticas vigentes no dia do envio.

## Bloqueios de acesso

- [ ] Luiz aceitou os termos e mantém o papel de titular da conta Apple Developer Program.
- [ ] G4 OS recebeu papel necessário no App Store Connect, sem compartilhar senha, chave privada ou certificado.
- [ ] Luiz tem conta Google Play Console verificada e concedeu o papel necessário ao G4 OS.
- [ ] Identificador `br.com.intusfit.app` confirmado ou substituído antes da primeira build assinada.
- [ ] Chave de assinatura Android, certificados e perfis iOS foram criados e guardados fora do Git.

## Produto e conformidade

- [ ] Build de release testada em aparelho Android físico e iPhone físico.
- [ ] Fluxo de exclusão de conta e dados confirmado dentro do app ou por URL pública, se exigido pela loja.
- [ ] Política de privacidade publicada em URL pública e atualizada para localização, saúde, câmera/fotos, dados de treino e autenticação que forem efetivamente usados. Ponto de partida atual: `https://intusfit.com.br/app/painel/privacidade.html`.
- [ ] Permissões pedidas no momento de uso, com explicação compreensível antes do diálogo do sistema.
- [ ] Não há dados de aluno, token, chave de API, credencial ou captura de tela real em materiais de teste.
- [ ] Caso exista login por Google, Facebook ou outro provedor no iOS, **Sign in with Apple** está disponível de forma equivalente e foi testado. O vínculo com a matrícula existente não cria conta duplicada.
- [ ] Se houver HealthKit/Health Connect, os tipos de dados, finalidade, retenção, exclusão e compartilhamento estão declarados na política e nos formulários das lojas.

## Materiais da ficha

- [ ] Nome: Intus Fit.
- [ ] Subtítulo/descrição curta preparado dentro dos limites atuais de cada loja.
- [ ] Descrição completa em português, sem promessas médicas ou métricas não verificadas.
- [ ] Categoria, classificação etária e URL de suporte definidas.
- [ ] Ícone final exportado nos tamanhos exigidos por iOS e Android, sem transparência onde a loja vedar.
- [ ] Capturas de tela feitas no app real, sem dados pessoais, nos aparelhos/tamanhos exigidos.
- [ ] E-mail de suporte e endereço de política de privacidade acessíveis publicamente.
- [ ] Conta de demonstração e instruções de revisão fornecidas apenas se a revisão exigir login; não usar conta real de aluno.

## Formulários e envio

### App Store Connect

- [ ] Preencher App Privacy com base no comportamento real da release.
- [ ] Informar uso de localização e dados de Saúde, se e somente se habilitados.
- [ ] Preencher informações de export compliance, conteúdo e classificação etária.
- [ ] Anexar build assinada pelo Xcode/Transporter e selecionar a versão correta.
- [ ] Revisar App Review Guidelines, especialmente autenticação, privacidade, pagamentos e funcionalidades mínimas.

### Google Play Console

- [ ] Criar app, package name e track de testes interno fechado.
- [ ] Enviar AAB assinado; não publicar APK de produção.
- [ ] Preencher Data Safety com os dados coletados/compartilhados de fato.
- [ ] Declarar permissões sensíveis, anúncios (se houver), conteúdo e classificação etária.
- [ ] Testar instalação e atualização via track interno antes de produção.

## Evidência de aprovação interna

Antes de enviar à revisão, registrar:

- versão, commit Git e hash do AAB/IPA;
- modelos e versões de sistema usados no teste;
- permissões solicitadas e motivo;
- resultados de login, treino, cardio, logout, atualização e exclusão;
- diferenças conhecidas entre PWA e app nativo.
