# Cortes de entrevista para reels

Usado em 27/10/2026 na entrevista da RIC ("Movimento que cabe na rotina"). Nenhum vídeo fica no repositório.

1. Vídeo em `<pasta>/orig/entrevista.mp4` (Drive: precisa dos domínios `drive.google.com` e `drive.usercontent.google.com` liberados).
2. Transcrição com tempo por palavra: `transcrever.py audio.wav transcricao.json` (faster-whisper large-v3-turbo; precisa de `huggingface.co` e `*.hf.co`; áudio 16 kHz mono via ffmpeg).
3. Pessoas na tela a cada 0,5 s: `rastrear.py video.mp4 pose_landmarker_full.task pessoas.json` (MediaPipe; precisa de `apt-get install libegl1 libgles2`). Cortes de câmera em `cortes.json` (diferença entre quadros).
4. Cada reel em `reels.json` (exemplo: `exemplo-entrevista-ric.json`): `partes` = início do primeiro e do último segmento da transcrição, `gancho`, `destaque_gancho` e `apresentadora` (trechos em que ela fala, para o enquadramento mostrar ela).
5. `reel.py reels.json <id> saida.mp4` (ou `--so-quadro 12.0 teste.png` para conferir um quadro): 1080x1920, recorte 4:5 acima da tarja da TV, pausas cortadas, zoom alternado nos cortes, legenda palavra a palavra (palavra falada alternando amarelo e verde Intus), assinatura @luiznunest e @intusfit e cartão final.
