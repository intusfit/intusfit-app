#!/usr/bin/env node
/*
 * Junta os quadros 3D do Blender (3d/animar.py) com o texto do modelo3d.html e gera o MP4.
 *
 *   NODE_PATH=<node_modules com playwright> FFMPEG=<ffmpeg> CHROME=<chromium> \
 *     node renderizar3d.js 3d/roteiros/abdominal-prancha.json <pasta dos quadros> saida.mp4
 *
 * Também salva <saida>.jpg (capa): um quadro do meio da demonstração.
 */
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { chromium } = require('playwright');

const [roteiroArq, pasta, saida] = process.argv.slice(2);
if (!roteiroArq || !pasta || !saida) { console.error('uso: node renderizar3d.js <roteiro.json> <pasta dos quadros> <saida.mp4>'); process.exit(1); }
const FFMPEG = process.env.FFMPEG || 'ffmpeg';
const roteiro = JSON.parse(fs.readFileSync(roteiroArq, 'utf8'));
const meta = JSON.parse(fs.readFileSync(path.join(pasta, 'meta.json'), 'utf8'));

(async () => {
  const browser = await chromium.launch(process.env.CHROME ? { executablePath: process.env.CHROME } : {});
  const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
  const erros = []; page.on('pageerror', e => erros.push(e.message));
  const logoArq = path.join(__dirname, '../../../app/painel/logo-intus-dark.png');
  const logoData = fs.existsSync(logoArq) ? 'data:image/png;base64,' + fs.readFileSync(logoArq).toString('base64') : '';
  await page.addInitScript(([r, m, l]) => { window.ROTEIRO = r; window.META = m; window.LOGO_DATA = l; }, [roteiro, meta, logoData]);
  await page.goto('file://' + path.join(__dirname, 'modelo3d.html'));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForFunction(() => { const l = document.getElementById('logo'); return l.complete && (!window.LOGO_DATA || l.src.startsWith('data:')); });
  if (!(await page.evaluate(() => document.fonts.check('900 40px Inter')))) console.warn('aviso: fonte Inter não instalada (instale fonts-inter)');

  const faltando = [];
  for (let i = 0; i < meta.quadros; i++) if (!meta.q[String(i)] || !fs.existsSync(path.join(pasta, `q${String(i).padStart(5, '0')}.png`))) faltando.push(i);
  if (faltando.length) throw new Error(`${faltando.length} quadros 3D ainda não renderizados (ex.: ${faltando.slice(0, 5).join(', ')})`);

  const ff = spawn(FFMPEG, ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-c:v', 'mjpeg', '-r', String(meta.fps), '-i', '-',
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'medium', '-crf', '19', '-movflags', '+faststart', saida], { stdio: ['pipe', 'inherit', 'inherit'] });
  const fim = new Promise((ok, falha) => ff.on('close', c => c === 0 ? ok() : falha(new Error('ffmpeg saiu com ' + c))));

  // capa: meio do trecho "segurando" da demonstração
  const capa = Object.keys(meta.q).map(Number).find(k => { const q = meta.q[k]; return q.tipo === 'demo' && q.respira && q.progresso > .45; }) ?? Math.floor(meta.quadros / 3);
  for (let i = 0; i < meta.quadros; i++) {
    const png = fs.readFileSync(path.join(pasta, `q${String(i).padStart(5, '0')}.png`)).toString('base64');
    const b64 = await page.evaluate(async ([i, png]) => {
      const img = new Image(); img.src = 'data:image/png;base64,' + png; await img.decode();
      desenhar(i, img); return document.getElementById('c').toDataURL('image/jpeg', 0.93).split(',')[1];
    }, [i, png]);
    if (erros.length) throw new Error('erro no modelo: ' + erros.join(' | '));
    const buf = Buffer.from(b64, 'base64');
    if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r));
    if (i === capa) fs.writeFileSync(saida.replace(/\.mp4$/i, '') + '.jpg', buf);
    if (i % (meta.fps * 5) === 0) process.stdout.write(`  ${Math.round(i / meta.fps)}s/${Math.round(meta.duracao)}s\n`);
  }
  ff.stdin.end(); await fim; await browser.close();
  console.log(`ok: ${saida} (${meta.duracao.toFixed(1)} s, ${meta.quadros} quadros)`);
})().catch(e => { console.error('FALHOU:', e.message); process.exit(1); });
