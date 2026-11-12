# Checklist de lançamento nas lojas — Intus Fit

Atualizada em **22 de setembro de 2026**; ícones, telas de abertura e materiais gráficos revisados em **11 de novembro de 2026**. Esta checklist não substitui a revisão das políticas vigentes no dia do envio.

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
- [x] Política de privacidade reescrita em 12/11/2026 (versão 2026-11): dados de saúde como sensíveis, quem vê (equipe, outros alunos no ranking e no Feed), prestadores (KingHost, Cloudflare, Google, plataformas de pagamento, Meta, OpenStreetMap, IA), transferência internacional, retenção (30 dias; cópias em até 45), direitos e ANPD, encarregado. **Falta o OK jurídico do Luiz.**
- [x] Idade mínima: cadastro direto só para 18+ (data de nascimento obrigatória, conferida no servidor) e aceite dos Termos e da Política com registro da versão. Menor só por professor, com o responsável. **Na App Store Connect, responder o questionário de classificação etária com conteúdo gerado por usuários = sim e moderação = sim; na Play, declarar o público-alvo adulto (18+) e a seção "Segurança dos dados" com os dados do item 2 da Política.**
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
