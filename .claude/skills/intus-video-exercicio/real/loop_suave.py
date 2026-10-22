# loop_suave.py <ffmpeg> <video> <t0> <t1> <fundido_s> <saida.mp4>: uma repetição em loop sem salto (o fim dissolve no começo)
import sys, subprocess, numpy as np
F, V, t0, t1, d, out = sys.argv[1], sys.argv[2], float(sys.argv[3]), float(sys.argv[4]), float(sys.argv[5]), sys.argv[6]
W, H, FPS = 1280, 720, 30
raw = subprocess.run([F, '-loglevel', 'error', '-ss', str(t0 - d), '-t', str(t1 - t0 + d), '-i', V, '-vf', 'fps=30,scale=%d:%d' % (W, H), '-pix_fmt', 'rgb24', '-f', 'rawvideo', '-'], capture_output=True).stdout
B = np.frombuffer(raw, np.uint8).reshape(-1, H, W, 3).astype(np.float32)
D = int(round(d * FPS)); L = len(B) - D
quadros = []
for i in range(L):
    f = B[i + D]
    if i >= L - D:
        w = (i - (L - D) + 1) / D; w = w * w * (3 - 2 * w)
        f = f * (1 - w) + B[i - (L - D)] * w
    quadros.append(f.astype(np.uint8))
p = subprocess.Popen([F, '-loglevel', 'error', '-y', '-f', 'rawvideo', '-pix_fmt', 'rgb24', '-s', '%dx%d' % (W, H), '-r', str(FPS), '-i', '-', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '16', out], stdin=subprocess.PIPE)
for q in quadros: p.stdin.write(q.tobytes())
p.stdin.close(); p.wait(); print('loop', L / FPS, 's')
