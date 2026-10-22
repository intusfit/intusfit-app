#!/usr/bin/env node
/*
 * Gera o MP4 de um roteiro de exercício, quadro a quadro (sem gravar tela, resultado sempre igual).
 *
 *   NODE_PATH=<pasta com node_modules/playwright> FFMPEG=<binário do ffmpeg> \
 *     node renderizar.js roteiros/agachamento-livre.json saida.mp4 [--fps 30] [--chrome <executável>]
 *
 * Também salva <saida>.jpg: um quadro do meio da demonstração, para usar de capa no YouTube.
 * Veja o SKILL.md para o passo a passo de instalar playwright/ffmpeg numa sessão nova.
 */
const fs = require('fs');
const path = require('path');
const { spawn } = require('child_process');
const { chromium } = require('playwright');

const args = process.argv.slice(2);
const opt = (n, d) => { const i = args.indexOf(n); return i >= 0 ? args[i + 1] : d; };
const [roteiroArq, saida] = args.filter((a, i) => !a.startsWith('--') && !(i > 0 && args[i - 1].startsWith('--')));
if (!roteiroArq || !saida) { console.error('uso: node renderizar.js <roteiro.json> <saida.mp4> [--fps 30]'); process.exit(1); }
const FPS = Number(opt('--fps', 30));
const FFMPEG = process.env.FFMPEG || 'ffmpeg';
const roteiro = JSON.parse(fs.readFileSync(roteiroArq, 'utf8'));

(async () => {
  const browser = await chromium.launch(opt('--chrome', process.env.CHROME) ? { executablePath: opt('--chrome', process.env.CHROME) } : {});
  const page = await browser.newPage({ viewport: { width: 1920, height: 1080 } });
  const erros = [];
  page.on('pageerror', e => erros.push(e.message));
  const logoArq = path.join(__dirname, '../../../app/painel/logo-intus-dark.png');
  const logoData = fs.existsSync(logoArq) ? 'data:image/png;base64,' + fs.readFileSync(logoArq).toString('base64') : '';
  await page.addInitScript(([r, l]) => { window.ROTEIRO = r; window.LOGO_DATA = l; window.__RENDER = true; }, [roteiro, logoData]);
  await page.goto('file://' + path.join(__dirname, 'modelo.html'));
  await page.evaluate(() => document.fonts.ready);
  await page.waitForFunction(() => { const l = document.getElementById('logo'); return l.complete && (!window.LOGO_DATA || l.src.startsWith('data:')); });
  if (!(await page.evaluate(() => document.getElementById('logo').naturalWidth))) console.warn('aviso: logo não carregou (o vídeo sai sem a logo)');
  if (!(await page.evaluate(() => document.fonts.check('900 40px Inter')))) console.warn('aviso: fonte Inter não instalada (instale fonts-inter)');
  if (erros.length) throw new Error('erro no modelo: ' + erros.join(' | '));
  const duracao = await page.evaluate(() => DURACAO);
  const quadros = Math.ceil(duracao * FPS);

  const ff = spawn(FFMPEG, ['-y', '-loglevel', 'error', '-f', 'image2pipe', '-c:v', 'mjpeg', '-r', String(FPS), '-i', '-',
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'medium', '-crf', '20', '-movflags', '+faststart', saida], { stdio: ['pipe', 'inherit', 'inherit'] });
  process.on('exit', () => { try { ff.kill(); } catch (e) {} });
  const fim = new Promise((ok, falha) => ff.on('close', c => c === 0 ? ok() : falha(new Error('ffmpeg saiu com ' + c))));

  const capa = await page.evaluate(() => LT.iniDemo + 1.6);
  for (let i = 0; i < quadros; i++) {
    const t = i / FPS;
    const b64 = await page.evaluate(t => { desenhar(t); return document.getElementById('c').toDataURL('image/jpeg', 0.93).split(',')[1]; }, t);
    const buf = Buffer.from(b64, 'base64');
    if (!ff.stdin.write(buf)) await new Promise(r => ff.stdin.once('drain', r));
    if (Math.abs(t - capa) < 0.5 / FPS) fs.writeFileSync(saida.replace(/\.mp4$/i, '') + '.jpg', buf);
    if (i % (FPS * 5) === 0) process.stdout.write(`  ${Math.round(t)}s/${Math.round(duracao)}s\n`);
  }
  ff.stdin.end();
  await fim;
  await browser.close();
  console.log(`ok: ${saida} (${duracao.toFixed(1)} s, ${quadros} quadros)`);
})().catch(e => { console.error('FALHOU:', e.message); process.exit(1); });
