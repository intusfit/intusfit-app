#!/usr/bin/env node
/*
 * Vídeo final a partir de um clipe gerado por IA: corta UMA repetição do clipe, repete em loop e
 * põe por cima o texto do modelo-ia.html (passo a passo, fase, respiração, erros, logo).
 *
 *   NODE_PATH=<node_modules com playwright> FFMPEG=<ffmpeg> CHROME=<chromium> \
 *     node renderizar-ia.js roteiros-ia/abdominal-prancha.json <clipe.mp4> <saida.mp4>
 *
 * No roteiro: "rep": {"inicio": 0.4, "fim": 4.6, "vaiEvolta": false}, em segundos do clipe.
 * vaiEvolta = true toca a repetição para a frente e depois de trás para a frente (bom para
 * isometria e quando o fim não encaixa no começo). Erro com "clipe" usa o clipe do erro.
 * Também salva <saida>.jpg (capa).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn, spawnSync } = require('child_process');
const { chromium } = require('playwright');

const [roteiroArq, clipe, saida] = process.argv.slice(2);
if (!roteiroArq || !clipe || !saida) { console.error('uso: node renderizar-ia.js <roteiro.json> <clipe.mp4> <saida.mp4>'); process.exit(1); }
const FFMPEG = process.env.FFMPEG || 'ffmpeg', FPS = Number(process.env.FPS || 30);
const R = JSON.parse(fs.readFileSync(roteiroArq, 'utf8'));

function quadros(arq, rep, pasta) {
  fs.mkdirSync(pasta, { recursive: true });
  const r = spawnSync(FFMPEG, ['-loglevel', 'error', '-y', '-ss', String(rep.inicio || 0), '-to', String(rep.fim), '-i', arq,
    '-vf', `fps=${FPS},scale=1280:-2`, '-q:v', '2', path.join(pasta, 'f%05d.jpg')]);
  if (r.status) throw new Error('ffmpeg não leu o clipe: ' + r.stderr);
  const lista = fs.readdirSync(pasta).filter(f => f.endsWith('.jpg')).sort().map(f => path.join(pasta, f));
  if (lista.length < 10) throw new Error('trecho da repetição curto demais em ' + arq);
  return { lista, inicio: rep.inicio || 0, vaiEvolta: !!rep.vaiEvolta };
}
// índice do quadro no instante t (segundos desde o início da cena), em loop
function noLoop(q, t) {
  const n = q.lista.length, i = Math.floor(t * FPS);
  if (!q.vaiEvolta) return i % n;
  const p = i % (2 * n - 2); return p < n ? p : 2 * n - 2 - p;
}

(async () => {
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'intus-ia-'));
  const base = path.dirname(roteiroArq);
  const principal = quadros(clipe, R.rep, path.join(tmp, 'rep'));
  const errosQ = (R.erros || []).map((e, i) => e.clipe ? quadros(path.resolve(base, e.clipe), e.rep || { inicio: 0, fim: 5 }, path.join(tmp, 'erro' + i)) : null);

  const browser = await chromium.launch(process.env.CHROME ? { executablePath: process.env.CHROME } : {});
  const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
  const erros = []; page.on('pageerror', e => erros.push(e.message));
  const logoArq = path.join(__dirname, '../../../app/painel/logo-intus-dark.png');
  const logoData = fs.existsSync(logoArq) ? 'data:image/png;base64,' + fs.readFileSync(logoArq).toString('base64') : '';
  await page.addInitScript(([r, l]) => { window.ROTEIRO = r; window.LOGO_DATA = l; }, [R, logoData]);
  await page.goto('file://' + path.join(__dirname, 'modelo-ia.html'));
  await page.evaluate(() => document.fonts.ready);
  if (!(await page.evaluate(() => document.fonts.check('900 40px Inter')))) console.warn('aviso: fonte Inter não instalada (instale fonts-inter)');
  const LT = await page.evaluate(() => LT);
  const total = Math.ceil(LT.duracao * FPS);

  const ff = spawn(FFMPEG, ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-c:v', 'mjpeg', '-r', String(FPS), '-i', '-',
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'medium', '-crf', '19', '-movflags', '+faststart', saida], { stdio: ['pipe', 'inherit', 'inherit'] });
  const fim = new Promise((ok, falha) => ff.on('close', c => c === 0 ? ok() : falha(new Error('ffmpeg saiu com ' + c))));
  const demo = LT.cenas.find(c => c.tipo === 'demo'), capa = Math.round((demo.ini + demo.dur * .4) * FPS);

  for (let k = 0; k < total; k++) {
    const t = k / FPS, c = LT.cenas.find((c, i) => t < c.ini + c.dur || i === LT.cenas.length - 1), tl = t - c.ini;
    let q = principal, tq = t - LT.cenas[0].ini, clipeErro = false;
    if (c.tipo === 'erro' && errosQ[c.i] && tl < c.dur / 2) { q = errosQ[c.i]; tq = tl; clipeErro = true; }
    const idx = c.tipo === 'intro' ? 0 : noLoop(q, c.tipo === 'intro' ? 0 : tq);
    const jpg = fs.readFileSync(q.lista[idx]).toString('base64');
    const tr = idx / FPS;
    const b64 = await page.evaluate(async ([t, jpg, info]) => {
      const img = new Image(); img.src = 'data:image/jpeg;base64,' + jpg; await img.decode();
      desenhar(t, img, info); return document.getElementById('c').toDataURL('image/jpeg', 0.93).split(',')[1];
    }, [t, jpg, { tr, clipeErro }]);
    if (erros.length) throw new Error('erro no modelo: ' + erros.join(' | '));
    const buf = Buffer.from(b64, 'base64');
    if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r));
    if (k === capa) fs.writeFileSync(saida.replace(/\.mp4$/i, '') + '.jpg', buf);
  }
  ff.stdin.end(); await fim; await browser.close();
  fs.rmSync(tmp, { recursive: true, force: true });
  console.log(`ok: ${saida} (${LT.duracao.toFixed(1)} s)`);
})().catch(e => { console.error('FALHOU:', e.message); process.exit(1); });
