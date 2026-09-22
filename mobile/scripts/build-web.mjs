import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';

const mobileRoot = resolve(import.meta.dirname, '..');
const projectRoot = resolve(mobileRoot, '..');
const panelRoot = join(projectRoot, 'app', 'painel');
const webRoot = join(mobileRoot, 'www');

// Apenas o runtime acessível ao aluno. Páginas de gestão não entram no binário.
const files = [
  'aluno.html', 'login.html', 'privacidade.html',
  'api.js', '_mock.js', 'alimentos-db.js',
  'manifest.json', 'sw.js',
  'favicon.ico', 'favicon-192.png',
  'logo-icon-dark.png', 'logo-icon-light.png',
  'logo-intus-branco.png', 'logo-intus-dark.png', 'logo-intus-light.png',
  'foto-luiz.jpg', 'foto-ricardo.jpg', 'foto-rosangela.jpg',
  'modelo-fotos-avaliacao.pdf'
];

await rm(webRoot, { recursive: true, force: true });
await mkdir(webRoot, { recursive: true });
for (const file of files) {
  await mkdir(dirname(join(webRoot, file)), { recursive: true });
  await cp(join(panelRoot, file), join(webRoot, file));
}

// Tem de ser carregado antes do api.js em ambas as telas. A origem web continua
// relativa; no WebView a API fica absoluta para alcançar o servidor de produção.
const runtime = `// Gerado por mobile/scripts/build-web.mjs — não editar no diretório www.\nwindow.INTUS_NATIVE_APP = true;\nwindow.INTUS_API_BASE = 'https://intusfit.com.br/app/api';\n`;
await writeFile(join(webRoot, 'intus-native-runtime.js'), runtime, 'utf8');

// Capacitor exige index.html como ponto de entrada. A tela real permanece login.html.
await writeFile(join(webRoot, 'index.html'), `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="0; url=login.html"><title>Intus Fit</title></head><body></body></html>`, 'utf8');

for (const htmlFile of ['aluno.html', 'login.html']) {
  const path = join(webRoot, htmlFile);
  const html = await readFile(path, 'utf8');
  const marker = '<script src="_mock.js';
  if (!html.includes(marker)) throw new Error(`Marcador de scripts não encontrado em ${htmlFile}`);
  const updated = html.replace(marker, '<script src="intus-native-runtime.js"></script>\n  ' + marker);
  await writeFile(path, updated, 'utf8');
}

console.log(`Runtime nativo preparado em ${webRoot}`);
