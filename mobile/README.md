# Intus Fit Mobile

Wrapper nativo do **aplicativo do aluno** para Android e iOS, mantido com Capacitor 8. O web app continua sendo a fonte de verdade: `app/painel/` e o back-end PHP não foram reescritos.

## O que este projeto faz

- empacota somente o runtime acessível ao aluno (`login.html`, `aluno.html`, privacidade, assets e scripts compartilhados);
- gera uma cópia local do front-end em `www/` a cada build — essa pasta não é versionada;
- configura o WebView nativo para usar `https://intusfit.com.br/app/api`;
- preserva o PWA existente: no navegador, `API_BASE` continua relativo (`/app/api`).

As origens `https://localhost` (Android) e `capacitor://localhost` (iOS) foram permitidas em `app/api/_cors.php`, sem abrir CORS para qualquer origem.

## Rotina de desenvolvimento

Na pasta `mobile/`:

```bash
npm install
npm run sync
npm run android  # abre o Android Studio
npm run ios      # deve ser executado num Mac com Xcode
```

`npm run sync` sempre recompõe `www/` a partir de `../app/painel/` e sincroniza os projetos nativos. Portanto, não edite `mobile/www/` nem as cópias em `android/.../public` e `ios/.../public`.

## Teste mínimo antes de uma release

1. Atualize a branch `main` e rode `npm run sync`.
2. Abra o app em Android Studio e, no macOS, no Xcode.
3. Valide login, encerramento de sessão, treino, registro de cardio, fotos, tema, voltar do sistema e links externos.
4. Confirme no inspector de rede que chamadas autenticadas vão para `https://intusfit.com.br/app/api` e não entram no fallback local.
5. Gere builds assinadas somente depois de configurar as contas das lojas e a política de privacidade.

## Recursos nativos: escopo incremental

O wrapper base **não pede permissões sensíveis antecipadamente**. Isso evita solicitar localização em segundo plano, Saúde ou sensores antes de a funcionalidade e sua tela de consentimento existirem.

| Recurso | Quando implementar | Dependências e validações obrigatórias |
|---|---|---|
| GPS em segundo plano | quando o fluxo de cardio nativo estiver definido | plugin com manutenção ativa, permissões de localização em primeiro e segundo plano, indicador persistente Android, texto de uso iOS, pausa/retomada, consumo de bateria e teste com tela bloqueada |
| HealthKit | quando houver casos de leitura/escrita aprovados | entitlement HealthKit, `NSHealthShareUsageDescription`/`NSHealthUpdateUsageDescription`, autorização por tipo de dado e formulário de privacidade da App Store |
| Health Connect | quando houver escopo Android aprovado | SDK/integração compatível, declaração de permissões por tipo de dado, teste em Android compatível e formulário Data Safety do Google Play |
| Frequência/atividade em musculação | somente com wearable ou métrica definida | não prometer medição por movimento do celular; validar fabricante, consentimento, precisão e limitações antes de integrar |
| Login social | quando o fluxo web estiver pronto | OAuth via navegador seguro; se houver Google/Facebook/outro login de terceiros no iOS, implementar **Sign in with Apple** no mesmo fluxo e ligar a conta existente pelo e-mail/identificador verificado, sem criar aluno duplicado |

## Notificações locais dos programas de cardio (instalado em 13/10/2026)

Plugin oficial `@capacitor/local-notifications@8.3.1` (já em `package.json`; `npm run sync` o inclui nos projetos Android e iOS). O que o app faz com ele (`_progAgendarNativo` em `aluno.html`):
ao iniciar, pausar, pular ou voltar um bloco, agenda no sistema um aviso para CADA troca de bloco e para o fim do programa (até 60; o iOS aceita 64 pendentes), então os avisos tocam com a tela apagada e até com o app fechado. Pausar ou encerrar cancela os agendados.

- **Android**: canal `programa` (importância alta, vibração, aparece na tela bloqueada), ícone `res/drawable/ic_stat_intus.xml` (cor `#7FFF00`, em `capacitor.config.json > plugins.LocalNotifications`), permissões `POST_NOTIFICATIONS` (Android 13+, o app pede na primeira vez) e `SCHEDULE_EXACT_ALARM` (Android 12+: sem ela o aviso pode atrasar minutos; o app avisa uma vez e abre "Alarmes e lembretes"). A Play Store pede a declaração do alarme exato: o uso é "avisos de troca de bloco de treino na hora marcada pelo aluno".
- **iOS**: sem chave nova no `Info.plist` (a permissão é pedida em tempo de uso). `UIBackgroundModes` = `audio` já está lá para o som da voz e dos bips com a tela apagada.
- **Voz gravada**: `build-web.mjs` agora leva a pasta `voz/` (63 arquivos, 1,1 MB) para o app. Enquanto `voz/manifest.json` tiver `"licenciado": false` o app das lojas usa a voz do aparelho; rode `tools/voz/gerar_licenciado.py` antes de enviar (ver `tools/voz/LEIAME.md`).
- **Preparado neste computador**: `npm install`, `npm run build:web` e `npx cap sync` (Android e iOS) rodaram sem erro, e o `AndroidManifest.xml`, o ícone e o `Info.plist` são XML válido. **Não foi compilado aqui** (sem JDK nem Android SDK, e o iOS exige um Mac). Falta: abrir no Android Studio (`npm run android`) e no Xcode (`npm run ios`), gerar o build e testar num aparelho real, com a tela bloqueada, um programa de 2 a 3 minutos (blocos curtos): conferir o aviso e a vibração a cada troca, o som da voz com a tela apagada e o fim do programa.

Para cada plugin, criar uma feature branch, instalar a dependência, registrar permissões mínimas e testar em aparelhos reais antes de solicitar revisão de loja.

## Segurança e publicação

- `appId` atual: `br.com.intusfit.app`. Confirmar disponibilidade e titularidade antes de registrar o identificador definitivo nas duas lojas.
- Nunca incluir chaves, senhas, certificados, keystores ou arquivos de provisão no repositório. Use os cofre/contas das lojas mantidos pelo Luiz.
- O build não altera os artefatos implantados pela KingHost. Alterações em `app/painel/api.js` e `app/api/_cors.php` seguem o fluxo Git normal e exigem a suíte de testes do projeto.
- A publicação em App Store Connect e Google Play Console continua bloqueada até o Luiz conceder os acessos de titular/admin apropriados.

Consulte [STORE-LAUNCH-CHECKLIST.md](STORE-LAUNCH-CHECKLIST.md) para a checklist de material, privacidade e submissão.
