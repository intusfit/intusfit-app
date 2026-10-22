# pose_ler.py <modelo.task> <rep.mp4> <saida.json> <previa.png>: lê as juntas quadro a quadro (MediaPipe) e desenha uma prévia
import sys, json, cv2, numpy as np, mediapipe as mp
from mediapipe.tasks.python import vision, BaseOptions
mod, vid, out, prev = sys.argv[1:5]
op = vision.PoseLandmarkerOptions(base_options=BaseOptions(model_asset_path=mod), running_mode=vision.RunningMode.VIDEO, num_poses=2)
lm = vision.PoseLandmarker.create_from_options(op)
cap = cv2.VideoCapture(vid); fps = cap.get(cv2.CAP_PROP_FPS) or 30; quadros = []; i = 0; amostras = []
while True:
    ok, fr = cap.read()
    if not ok: break
    r = lm.detect_for_video(mp.Image(image_format=mp.ImageFormat.SRGB, data=cv2.cvtColor(fr, cv2.COLOR_BGR2RGB)), int(i * 1000 / fps))
    if r.pose_landmarks:
        # a pessoa maior na tela (o professor, não quem está atrás)
        k = max(range(len(r.pose_landmarks)), key=lambda j: (max(p.y for p in r.pose_landmarks[j]) - min(p.y for p in r.pose_landmarks[j])))
        n2 = [[p.x, p.y, p.z, p.visibility] for p in r.pose_landmarks[k]]; w3 = [[p.x, p.y, p.z] for p in r.pose_world_landmarks[k]]
        quadros.append({'i': i, 'img': n2, 'mundo': w3})
        if i % 12 == 0:
            g = fr.copy()
            for a, b in ((11, 13), (13, 15), (12, 14), (14, 16), (11, 12), (11, 23), (12, 24), (23, 24), (23, 25), (25, 27), (24, 26), (26, 28)):
                pa = (int(n2[a][0] * g.shape[1]), int(n2[a][1] * g.shape[0])); pb = (int(n2[b][0] * g.shape[1]), int(n2[b][1] * g.shape[0]))
                cv2.line(g, pa, pb, (0, 255, 0), 4)
            amostras.append(cv2.resize(g, (427, 240)))
    else: quadros.append({'i': i})
    i += 1
json.dump({'fps': fps, 'quadros': quadros}, open(out, 'w'))
if amostras: cv2.imwrite(prev, np.hstack(amostras[:5]))
print('quadros', i, 'com pose', sum(1 for q in quadros if 'img' in q))
