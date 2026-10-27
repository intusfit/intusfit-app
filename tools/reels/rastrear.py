# rastrear.py <video> <modelo.task> <saida.json>: pessoas (caixa, centro dos ombros, camisa escura) a cada 0,5 s
import sys, json, cv2, numpy as np, mediapipe as mp
from mediapipe.tasks.python import vision, BaseOptions
vid, mod, out = sys.argv[1:4]
lm = vision.PoseLandmarker.create_from_options(vision.PoseLandmarkerOptions(base_options=BaseOptions(model_asset_path=mod), running_mode=vision.RunningMode.IMAGE, num_poses=3, min_pose_detection_confidence=.4))
cap = cv2.VideoCapture(vid); fps = cap.get(cv2.CAP_PROP_FPS); passo = int(round(fps / 2)); res = []; i = 0
while True:
    ok = cap.grab()
    if not ok: break
    if i % passo == 0:
        ok, fr = cap.retrieve(); h, w = fr.shape[:2]
        r = lm.detect(mp.Image(image_format=mp.ImageFormat.SRGB, data=cv2.cvtColor(fr, cv2.COLOR_BGR2RGB)))
        ps = []
        for L in r.pose_landmarks:
            vis = [p for p in L if p.visibility > .5]
            if len(vis) < 5: continue
            xs = [p.x * w for p in vis]; ys = [p.y * h for p in vis]
            sx = (L[11].x + L[12].x) / 2 * w; sy = (L[11].y + L[12].y) / 2 * h; hx = (L[23].x + L[24].x) / 2 * w; hy = (L[23].y + L[24].y) / 2 * h
            x0, x1 = int(max(0, min(sx, hx) - 20)), int(min(w, max(sx, hx) + 20)); y0, y1 = int(max(0, sy)), int(min(h, max(sy + 10, hy)))
            tor = fr[y0:y1, x0:x1].reshape(-1, 3) if y1 > y0 and x1 > x0 else np.zeros((1, 3))
            ps.append({'x0': round(min(xs)), 'x1': round(max(xs)), 'y0': round(min(ys)), 'y1': round(max(ys)),
                       'cab': [round(L[0].x * w), round(L[0].y * h)], 'ombro': [round(sx), round(sy)], 'escuro': round(float(tor.mean()), 1)})
        res.append({'t': round(i / fps, 2), 'p': ps})
        if len(res) % 100 == 0: print(len(res), flush=True)
    i += 1
json.dump({'fps': fps, 'w': w, 'h': h, 'quadros': res}, open(out, 'w'))
print('FIM', len(res))
