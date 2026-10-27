import sys, json, time, wave
import numpy as np
from faster_whisper import WhisperModel
t = time.time()
m = WhisperModel('deepdml/faster-whisper-large-v3-turbo-ct2', device='cpu', compute_type='int8', cpu_threads=4)
w_ = wave.open(sys.argv[1]); audio = np.frombuffer(w_.readframes(w_.getnframes()), np.int16).astype(np.float32) / 32768
segs, info = m.transcribe(audio, language='pt', word_timestamps=True, vad_filter=True, beam_size=5,
                          initial_prompt='Entrevista na TV com o treinador Luiz Nunes, da Intus Fit, sobre treino em casa em pouco espaço.')
out = []
for s in segs:
    out.append({'ini': round(s.start, 2), 'fim': round(s.end, 2), 'texto': s.text.strip(),
                'palavras': [{'p': w.word, 'i': round(w.start, 2), 'f': round(w.end, 2)} for w in (s.words or [])]})
    print('%7.1f %s' % (s.start, s.text.strip()), flush=True)
json.dump(out, open(sys.argv[2], 'w'), ensure_ascii=False)
print('FIM', round(time.time() - t), 's', flush=True)
