# Voz dos programas de cardio: o que falta para a publicação nas lojas

## Situação hoje
- Os 60 áudios de `app/painel/voz/` foram gerados de forma **provisória** com o serviço de leitura do Microsoft Edge (`edge-tts`), que não tem contrato de uso comercial.
- O app **das lojas** (Capacitor, iOS e Android) **ignora** esses áudios enquanto `app/painel/voz/manifest.json` tiver `"licenciado": false` e usa a voz do aparelho. O site e o app instalado pelo navegador continuam usando os áudios.
- Quando o manifesto passar a `"licenciado": true`, todos os lugares usam os áudios gravados.

## O que fazer (uma vez, cerca de 15 minutos)
1. **Criar o recurso de voz** no Azure: portal.azure.com, "Criar um recurso", "Speech" (Serviços de IA). Plano gratuito F0 (500 mil caracteres por mês; todas as frases juntas têm cerca de 2,5 mil). Anote a **chave** e a **região** (por exemplo `brazilsouth`).
2. **Rodar o gerador** (a chave só vai pelo ambiente, nunca para o código):
   ```powershell
   $env:AZURE_SPEECH_KEY = "<chave>"
   $env:AZURE_SPEECH_REGION = "brazilsouth"
   python tools/voz/gerar_licenciado.py
   ```
   Ele troca os 60 arquivos pela mesma voz (`pt-BR-ThalitaMultilingualNeural`) vinda da API oficial e marca o manifesto como licenciado.
3. **Ouvir** alguns arquivos de `app/painel/voz/`, fazer o commit da pasta e publicar. O app das lojas passa a usar as vozes gravadas sem mudar nada no código; para esse caminho o build precisa incluir (ou baixar do site) a pasta `voz/`.
4. **Guardar o comprovante** do recurso Azure (nome, assinatura, data) junto da documentação da loja: ele mostra de onde vem o áudio.
5. Conferir nos termos vigentes do Azure Speech que o áudio gerado pode ser distribuído no app (o uso comercial dos áudios sintetizados é permitido pelos termos de serviço do Azure, que mudam com o tempo; o texto que vale é o do dia do envio).

Frase nova de voz: acrescente a chave no `manifest.json` (valor qualquer) e rode o script de novo.

## Declarações nas lojas para esse recurso
- **iOS (App Store)**: o app usa `UIBackgroundModes` = `audio` (áudio com a tela apagada durante o programa). Na revisão, informar: "O app toca avisos de voz e sons de troca de bloco durante um treino guiado, com a tela apagada. O áudio só toca com um programa em andamento." Não há gravação de microfone.
- **Android (Google Play)**: as notificações de troca de bloco usam `@capacitor/local-notifications` (permissão `POST_NOTIFICATIONS` no Android 13+; alarmes exatos só se ativar o agendamento exato). Declarar o uso de notificações no formulário de permissões.
- **Privacidade**: as vozes são arquivos estáticos do próprio app; nenhum dado do aluno é enviado a terceiros para gerar ou tocar o áudio. A política de privacidade não precisa de mudança por causa da voz.
- O plugin `@capacitor/local-notifications` já está instalado em `mobile/` e o projeto nativo já foi sincronizado (ver `mobile/README.md`). Antes do envio: `cd mobile && npm run sync`, abrir no Android Studio e no Xcode e gerar o build.
