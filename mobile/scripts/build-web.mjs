import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';

const mobileRoot = resolve(import.meta.dirname, '..');
const projectRoot = resolve(mobileRoot, '..');
const panelRoot = join(projectRoot, 'app', 'painel');
const webRoot = join(mobileRoot, 'www');

// Apenas o runtime acessível ao aluno. Páginas de gestão não entram no binário.
// Fora de propósito: alimentos-db.js (usado só por nutricao.html, do painel; o aluno.html não o carrega),
// icon-192.png / apple-touch-icon.png / trafego.js (só do site e do PWA) e voz/ (ver abaixo).
const files = [
  'aluno.html', 'login.html', 'privacidade.html', 'termos.html',
  'api.js', '_mock.js', 'ajuda.js',
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

// Vozes gravadas dos programas de cardio (aluno.html as lê em voz/). Os áudios atuais foram gerados com edge-tts, que não tem licença
// comercial: enquanto voz/manifest.json tiver "licenciado": false eles NÃO vão no binário (o app usa a voz do aparelho; o aluno.html
// já trata a ausência de voz/manifest.json). Com "licenciado": true (ver tools/voz/LEIAME.md) a pasta entra, para tocar sem internet.
// Qualquer dúvida (manifesto ausente, ilegível ou sem a marca) conta como NÃO licenciado.
let vozLicenciada = false;
try {
  const manifestoVoz = JSON.parse(await readFile(join(panelRoot, 'voz', 'manifest.json'), 'utf8'));
  vozLicenciada = !!(manifestoVoz && manifestoVoz._meta && manifestoVoz._meta.licenciado === true);
} catch (e) {
  console.warn(`Aviso: não consegui ler voz/manifest.json (${e.message}); voz/ fica fora do pacote.`);
}
if (vozLicenciada) {
  await cp(join(panelRoot, 'voz'), join(webRoot, 'voz'), { recursive: true });
  console.log('voz/ incluída no pacote (licenciado: true).');
} else {
  console.log('voz/ NÃO incluída no pacote (voz/manifest.json com "licenciado": false): o app usa a voz do aparelho.');
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

// ── Limpeza das páginas empacotadas ──────────────────────────────────────────────────────────────────────────────
// Só a cópia em www/ é alterada; as páginas de app/painel/ (PWA e site) continuam como são.
// Tira, linha a linha: (1) a medição de visitas do site (trafego.js: não existe no pacote e o app não mede ninguém);
// (2) os ícones do PWA (icon-192.png e apple-touch-icon.png: não vão no pacote); (3) no login, os SDKs do Google e do Facebook
// (login social desligado: sem "Entrar com a Apple" não pode haver login de terceiros no iOS, ver README).
const LINHAS_REMOVIDAS = [
  /^[ \t]*<script[^>]*\bsrc="\/trafego\.js"[^>]*><\/script>[ \t]*\r?\n/gm,
  /^[ \t]*<link[^>]*\bhref="icon-192\.png"[^>]*>[ \t]*\r?\n/gm,
  /^[ \t]*<link[^>]*\bhref="apple-touch-icon\.png"[^>]*>[ \t]*\r?\n/gm,
  /^[ \t]*<script[^>]*\bsrc="https:\/\/accounts\.google\.com\/gsi\/client"[^>]*><\/script>[ \t]*\r?\n/gm,
  /^[ \t]*<script[^>]*\bsrc="https:\/\/connect\.facebook\.net\/[^"]*"[^>]*><\/script>[ \t]*\r?\n/gm,
  /^[ \t]*<div id="fb-root"><\/div>[ \t]*\r?\n/gm,
];
// O aviso "Em breve teremos app nas lojas" é do site; dentro do app das lojas não faz sentido.
const AVISO_SO_DO_SITE = /^[ \t]*<p [^>]*>\s*Em breve teremos app disponível nas lojas\.<br>[^<]*<\/p>[ \t]*\r?\n/gm;
// Garantia extra: mesmo com SDKs ausentes, se alguém configurar os IDs no futuro os botões sociais não aparecem no app.
const CSS_SEM_LOGIN_SOCIAL = '  <style>/* app nativo: login social desligado (ver mobile/README.md) */.social-divider,.social-login-row{display:none!important}</style>\n';
// O que não pode sobrar nas páginas empacotadas (se sobrar, o build falha em vez de publicar sem avisar).
const PROIBIDO = ['/trafego.js', 'href="icon-192.png"', 'href="apple-touch-icon.png"'];   // favicon-192.png (notificações) vai no pacote
const PROIBIDO_NO_LOGIN = ['accounts.google.com', 'connect.facebook.net', 'fb-root', 'Em breve teremos app'];

for (const htmlFile of ['aluno.html', 'login.html', 'privacidade.html', 'termos.html']) {
  const path = join(webRoot, htmlFile);
  let html = await readFile(path, 'utf8');
  for (const re of LINHAS_REMOVIDAS) html = html.replace(re, '');
  if (htmlFile === 'login.html') {
    html = html.replace(AVISO_SO_DO_SITE, '');
    if (!html.includes('</head>')) throw new Error('login.html: </head> não encontrado');
    html = html.replace('</head>', CSS_SEM_LOGIN_SOCIAL + '</head>');
  }
  const sobras = PROIBIDO.concat(htmlFile === 'login.html' ? PROIBIDO_NO_LOGIN : []).filter((t) => html.includes(t));
  if (sobras.length) throw new Error(`${htmlFile}: referências que não podem ir no pacote nativo ainda presentes: ${sobras.join(', ')}. Atualize as regras de limpeza em mobile/scripts/build-web.mjs.`);
  await writeFile(path, html, 'utf8');
}

console.log(`Runtime nativo preparado em ${webRoot}`);
