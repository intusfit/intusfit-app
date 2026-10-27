# Cortes de entrevista para reels

Usado em 27/10/2026 na entrevista da RIC ("Movimento que cabe na rotina"). Nenhum vídeo fica no repositório.

1. Vídeo em `<pasta>/orig/entrevista.mp4` (Drive: precisa dos domínios `drive.google.com` e `drive.usercontent.google.com` liberados).
2. Transcrição com tempo por palavra: `transcrever.py audio.wav transcricao.json` (faster-whisper large-v3-turbo; precisa de `huggingface.co` e `*.hf.co`; áudio 16 kHz mono via ffmpeg).
3. Pessoas na tela a cada 0,5 s: `rastrear.py video.mp4 pose_landmarker_full.task pessoas.json` (MediaPipe; precisa de `apt-get install libegl1 libgles2`). Cortes de câmera em `cortes.json` (diferença entre quadros).
4. Cada reel em `reels.json` (exemplo: `exemplo-entrevista-ric.json`): `partes` = início do primeiro e do último segmento da transcrição, `gancho`, `destaque_gancho` e `apresentadora` (trechos em que ela fala, para o enquadramento mostrar ela).
5. `reel.py reels.json <id> saida.mp4` (ou `--so-quadro 12.0 teste.png` para conferir um quadro): 1080x1920, recorte 4:5 acima da tarja da TV, pausas cortadas, zoom alternado nos cortes, legenda palavra a palavra (palavra falada alternando amarelo e verde Intus), assinatura @luiznunest e @intusfit e cartão final.

## Revisão de 27/10/2026 (pedido do Luiz)
- Enquadramento fixo por plano da TV (`cortes.json`), sem acompanhar quadro a quadro (balançava). Close (pessoa larga na tela, > 520 px) = recorte 4:5 no rosto; plano aberto = recorte largo de no mínimo 1250 px, para ninguém ficar com metade do corpo (o detector às vezes não vê a apresentadora). Plano aberto fica centrado sobre o próprio plano desfocado, com a legenda embaixo da imagem.
- Sem zoom alternado; pausas só cortadas acima de 0,8 s.
- `notas` no reel: `[início, fim, texto, cor]` em segundos do vídeo original (ex.: "ERRADO: ...", "CERTO: ...").
- Áudio: voz e música separadas com `audio-separator` (modelo UVR-MDX-NET-Voc_FT, baixado do GitHub); a música volta a 14% e a voz passa por filtro de graves, equalização leve, compressor e loudnorm -14 LUFS, sem mudar o tom. Resultado em `sep/audio_limpo.wav`, que o `reel.py` usa quando existe.
- Cartão final: @luiznunest branco, @intusfit verde, slogan "Te levamos ao próximo nível. Do iniciante ao atleta."

## Intus Cut (editor no navegador, 27/10/2026)

Página: https://claude.ai/artifact/Et9xVsHjrTYxxqug5UKJH6 (privada do Luiz). Fonte em `intus-cut/modelo.html`
(a logo entra no lugar de `__LOGO__` em base64 na hora de publicar).

- Cada projeto é um documento `projetos/<id>` no banco da página: `{nome, atualizado_em, render, dados}`,
  com `dados` = o JSON que `reel.py --projeto saida.json <base_s>` gera, mais `midia {video, voz, musica}`.
- A mídia publicada é uma prévia leve: vídeo 720p sem som, voz e música separadas (as `.m4a` são publicadas
  com nome `.mp4`, porque a página só serve esse tipo). O desenho do quadro na página copia o `reel.py`.
- "Exportar MP4" grava no próprio navegador (WebCodecs, Chrome/Edge no computador), em 1080x1920.
- "Pedir versão em alta" marca `render = 'pedido'`: o Claude lê o projeto e renderiza com o `reel.py`
  a partir do vídeo original da TV.
- Novo vídeo: até 20 MB pela página (sem legenda automática). Entrevista inteira: mandar ao Claude,
  que transcreve (`transcrever.py`), rastreia (`rastrear.py`), separa a voz e cria os projetos.
