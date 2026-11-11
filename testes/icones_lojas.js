// Ícones e telas de abertura dos apps nativos (mobile/): marca da Intus no lugar dos desenhos de exemplo do Capacitor, sem alfa onde a Apple
// proíbe, marca dentro da zona segura do ícone adaptativo do Android, todas as densidades presentes e documentação que não cita arquivo
// inexistente. Node puro (zlib): decodifica o PNG que o gerador grava (8 bits, RGB ou RGBA, sem entrelaçamento).
// Gerador: python3 tools/marca/gerar-icones-lojas.py. Este teste NÃO roda o gerador (a fonte das letras muda de um computador para outro).
const fs = require('fs'), path = require('path'), zlib = require('zlib'), crypto = require('crypto');
const RAIZ = path.resolve(__dirname, '..');
const MOBILE = path.join(RAIZ, 'mobile');
const IOS = path.join(MOBILE, 'ios', 'App', 'App', 'Assets.xcassets');
const RES = path.join(MOBILE, 'android', 'app', 'src', 'main', 'res');
const LOJAS = path.join(MOBILE, 'store-assets');
const DENS = { mdpi: 1, hdpi: 1.5, xhdpi: 2, xxhdpi: 3, xxxhdpi: 4 };
// Impressão digital (16 primeiros dígitos do SHA-256) de TODOS os PNGs de exemplo que o Capacitor trouxe (X azul sobre grade, fundo branco).
// Nenhum arquivo do projeto pode voltar a ser igual a um deles.
const PLACEHOLDERS = new Set(('1b5002b74a5500e6 5cf98b4451bd99b2 29e4777e319de3ee efc9f4424783e3f7 ed346eb1e3f0280f d35dbfff175b83c1 c5015f4ba3628392 bfcc1b0fa931b14b ' +
  'bd24fd383253bf8d b73049cb37fe76d6 ab93096331e7cd8e 87cb2f2ffe992652 72b71c3581ca3b5a 6f88083b8166cc55 60393ce8636fd263 58e78a618778926b 4a82bc1e99235762 ' +
  '42aa26392546fcde 40911a0092286868 3db071a03b2f8ffe 32baa10d2632a441 27ed3603010ebc27 22f87e1e3bc89aa0 1ee4cd9ff371dcb2 0c7f1212f25b7b90 08cc34ad7713fe7e ' +
  '07fa579e1c83e04b 0166fc333074c373').split(' '));

let ok = 0, falhas = 0;
function conferir(nome, cond, detalhe) {
  if (cond) { ok++; console.log('  OK    ' + nome); }
  else { falhas++; console.log('  FALHA ' + nome + (detalhe ? ' (' + detalhe + ')' : '')); }
}
const rel = (p) => path.relative(RAIZ, p).replace(/\\/g, '/');
const hash = (p) => crypto.createHash('sha256').update(fs.readFileSync(p)).digest('hex').slice(0, 16);

// ---------- leitor de PNG ----------
function lerPng(p, soCabecalho) {
  const b = fs.readFileSync(p);
  if (!b.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]))) throw new Error('não é PNG: ' + rel(p));
  let pos = 8, ihdr = null; const idat = [], tipos = [];
  while (pos < b.length) {
    const len = b.readUInt32BE(pos), tipo = b.toString('latin1', pos + 4, pos + 8);
    tipos.push(tipo);
    if (tipo === 'IHDR') ihdr = b.subarray(pos + 8, pos + 8 + len);
    if (tipo === 'IDAT') idat.push(b.subarray(pos + 8, pos + 8 + len));
    pos += 12 + len;
  }
  const w = ihdr.readUInt32BE(0), h = ihdr.readUInt32BE(4), prof = ihdr[8], cor = ihdr[9], entrelacado = ihdr[12];
  const info = { w, h, prof, cor, entrelacado, tRNS: tipos.includes('tRNS'), temAlfa: cor === 6 || cor === 4 || tipos.includes('tRNS'), bytes: b.length };
  if (soCabecalho) return info;
  if (prof !== 8 || (cor !== 2 && cor !== 6) || entrelacado) throw new Error('formato de PNG não suportado pelo teste: ' + rel(p));
  const bpp = cor === 6 ? 4 : 3, linha = w * bpp;
  const bruto = zlib.inflateSync(Buffer.concat(idat));
  const px = Buffer.alloc(h * linha);
  for (let y = 0; y < h; y++) {
    const f = bruto[y * (linha + 1)], src = y * (linha + 1) + 1, dst = y * linha;
    for (let i = 0; i < linha; i++) {
      const x = bruto[src + i];
      const a = i >= bpp ? px[dst + i - bpp] : 0, c = y > 0 ? px[dst - linha + i] : 0, d = (y > 0 && i >= bpp) ? px[dst - linha + i - bpp] : 0;
      let v;
      if (f === 0) v = x; else if (f === 1) v = x + a; else if (f === 2) v = x + c; else if (f === 3) v = x + ((a + c) >> 1);
      else { const pa = Math.abs(c - d), pb = Math.abs(a - d), pc = Math.abs(a + c - 2 * d); v = x + ((pa <= pb && pa <= pc) ? a : (pb <= pc ? c : d)); }
      px[dst + i] = v & 255;
    }
  }
  info.pixel = (x, y) => { const o = (y * w + x) * bpp; return { r: px[o], g: px[o + 1], b: px[o + 2], a: cor === 6 ? px[o + 3] : 255 }; };
  return info;
}
const luz = (q) => (q.r * 299 + q.g * 587 + q.b * 114) / 1000;

// Imagem com a marca: cantos escuros (fundo #0A0A0A, com o brilho verde, que tem luz < 60) e pixels claros (>200) no miolo.
function marcaSobreFundoEscuro(nome, p, miolo) {
  const im = lerPng(p);
  const cantos = [[2, 2], [im.w - 3, 2], [2, im.h - 3], [im.w - 3, im.h - 3]].map(([x, y]) => luz(im.pixel(x, y)));
  conferir(nome + ': fundo escuro nos quatro cantos', cantos.every((l) => l < 60), 'luz dos cantos: ' + cantos.map((l) => Math.round(l)).join('/'));
  let claros = 0, total = 0;
  const x0 = Math.floor(im.w * (0.5 - miolo)), x1 = Math.floor(im.w * (0.5 + miolo)), y0 = Math.floor(im.h * (0.5 - miolo)), y1 = Math.floor(im.h * (0.5 + miolo));
  for (let y = y0; y < y1; y += 2) for (let x = x0; x < x1; x += 2) { total++; if (luz(im.pixel(x, y)) > 200) claros++; }
  conferir(nome + ': a marca (pixels brancos) aparece no miolo', claros / total > 0.03, Math.round(claros / total * 1000) / 10 + '% do miolo');
  return im;
}

// ---------- iOS ----------
console.log('iOS');
const contAppIcon = JSON.parse(fs.readFileSync(path.join(IOS, 'AppIcon.appiconset', 'Contents.json'), 'utf8'));
const arqIcone = contAppIcon.images[0].filename;
const pIcone = path.join(IOS, 'AppIcon.appiconset', arqIcone);
conferir('o Contents.json do AppIcon aponta para um arquivo que existe', fs.existsSync(pIcone), arqIcone);
conferir('o AppIcon é um só, universal, de 1024x1024', contAppIcon.images.length === 1 && contAppIcon.images[0].size === '1024x1024');
const cabIcone = lerPng(pIcone, true);
conferir('ícone do iOS: 1024x1024', cabIcone.w === 1024 && cabIcone.h === 1024, cabIcone.w + 'x' + cabIcone.h);
conferir('ícone do iOS: SEM canal alfa e sem transparência (a Apple recusa o envio)', !cabIcone.temAlfa && cabIcone.cor === 2 && cabIcone.prof === 8, 'tipo de cor ' + cabIcone.cor + (cabIcone.tRNS ? ' + tRNS' : ''));
conferir('ícone do iOS não é o desenho de exemplo do Capacitor', !PLACEHOLDERS.has(hash(pIcone)));
const imgIcone = marcaSobreFundoEscuro('ícone do iOS', pIcone, 0.25);
// cantos quadrados: a Apple é quem arredonda. Se o canto fosse arredondado por nós, apareceria uma quina preta fora do desenho (aqui o fundo é #0A0A0A em todo o quadro)
const q = imgIcone.pixel(0, 0);
conferir('ícone do iOS: quadrado cheio (o canto tem a cor do fundo, não é arredondado)', q.r < 40 && q.g < 60 && q.b < 40, JSON.stringify(q));

const contSplash = JSON.parse(fs.readFileSync(path.join(IOS, 'Splash.imageset', 'Contents.json'), 'utf8'));
const arqsSplash = contSplash.images.map((i) => i.filename);
conferir('o conjunto Splash tem as 3 escalas apontando para arquivos que existem', arqsSplash.length === 3 && arqsSplash.every((f) => fs.existsSync(path.join(IOS, 'Splash.imageset', f))), arqsSplash.join(','));
const hashesSplash = new Set();
for (const f of arqsSplash) {
  const p = path.join(IOS, 'Splash.imageset', f), c = lerPng(p, true);
  conferir('splash do iOS ' + f + ': 2732x2732, sem exemplo do Capacitor', c.w === 2732 && c.h === 2732 && !PLACEHOLDERS.has(hash(p)), c.w + 'x' + c.h);
  hashesSplash.add(hash(p));
}
// as três escalas têm o mesmo desenho: decodifica uma vez só
marcaSobreFundoEscuro('splash do iOS', path.join(IOS, 'Splash.imageset', arqsSplash[0]), 0.1);
conferir('as 3 escalas da splash do iOS são o mesmo desenho', hashesSplash.size === 1);

// ---------- Android ----------
console.log('\nAndroid');
const xmlIcone = fs.readFileSync(path.join(RES, 'mipmap-anydpi-v26', 'ic_launcher.xml'), 'utf8');
const xmlRedondo = fs.readFileSync(path.join(RES, 'mipmap-anydpi-v26', 'ic_launcher_round.xml'), 'utf8');
for (const [nome, xml] of [['ic_launcher.xml', xmlIcone], ['ic_launcher_round.xml', xmlRedondo]]) {
  conferir(nome + ': camadas fundo, primeiro plano e monocromática da marca', /<background android:drawable="@mipmap\/ic_launcher_fundo"\/>/.test(xml) && /<foreground android:drawable="@mipmap\/ic_launcher_foreground"\/>/.test(xml) && /<monochrome android:drawable="@mipmap\/ic_launcher_monochrome"\/>/.test(xml));
}
const manifesto = fs.readFileSync(path.join(RES, '..', 'AndroidManifest.xml'), 'utf8');
conferir('o manifesto usa @mipmap/ic_launcher e @mipmap/ic_launcher_round', /android:icon="@mipmap\/ic_launcher"/.test(manifesto) && /android:roundIcon="@mipmap\/ic_launcher_round"/.test(manifesto));
conferir('os vetores de exemplo do Capacitor (fundo verde-água e desenho do foguete) foram removidos', !fs.existsSync(path.join(RES, 'drawable-v24', 'ic_launcher_foreground.xml')) && !fs.existsSync(path.join(RES, 'drawable', 'ic_launcher_background.xml')));
const corFundo = (fs.readFileSync(path.join(RES, 'values', 'ic_launcher_background.xml'), 'utf8').match(/name="ic_launcher_background">(#[0-9A-Fa-f]{6})</) || [])[1];
conferir('a cor ic_launcher_background é o preto da marca (#0A0A0A)', (corFundo || '').toUpperCase() === '#0A0A0A', corFundo);

// zona segura do ícone adaptativo: a marca inteira dentro do círculo de 66 dp (raio 33 dp) no centro da camada de 108 dp
function dentroDaZonaSegura(nome, p, lado) {
  const im = lerPng(p);
  const cx = (lado - 1) / 2, cy = (lado - 1) / 2, rMax = 33 / 108 * lado + 1;
  let fora = 0, opacos = 0, longe = 0;
  for (let y = 0; y < lado; y++) for (let x = 0; x < lado; x++) {
    const a = im.pixel(x, y).a;
    if (a > 16) { opacos++; const d = Math.hypot(x - cx, y - cy); if (d > longe) longe = d; if (d > rMax) fora++; }
  }
  conferir(nome + ': tem conteúdo (' + Math.round(opacos / (lado * lado) * 100) + '% da camada)', opacos / (lado * lado) > 0.08);
  conferir(nome + ': toda a marca dentro da zona segura de 66 dp', fora === 0, fora + ' pixels fora; mais longe ' + (longe / lado * 108).toFixed(1) + ' dp do centro (limite 33)');
  const cantos = [[0, 0], [lado - 1, 0], [0, lado - 1], [lado - 1, lado - 1]].map(([x, y]) => im.pixel(x, y).a);
  conferir(nome + ': cantos transparentes', cantos.every((a) => a === 0));
}
for (const [dens, f] of Object.entries(DENS)) {
  const pasta = path.join(RES, 'mipmap-' + dens);
  const camada = Math.round(108 * f), antigo = Math.round(48 * f);
  for (const nome of ['ic_launcher_foreground', 'ic_launcher_fundo', 'ic_launcher_monochrome', 'ic_launcher', 'ic_launcher_round']) {
    const p = path.join(pasta, nome + '.png');
    if (!fs.existsSync(p)) { conferir(dens + '/' + nome + '.png existe', false); continue; }
    const c = lerPng(p, true), esperado = (nome === 'ic_launcher' || nome === 'ic_launcher_round') ? antigo : camada;
    conferir(dens + '/' + nome + '.png: ' + esperado + 'x' + esperado + ', não é exemplo do Capacitor', c.w === esperado && c.h === esperado && !PLACEHOLDERS.has(hash(p)), c.w + 'x' + c.h);
  }
  dentroDaZonaSegura(dens + ' primeiro plano', path.join(pasta, 'ic_launcher_foreground.png'), camada);
  dentroDaZonaSegura(dens + ' monocromática', path.join(pasta, 'ic_launcher_monochrome.png'), camada);
  const fundo = lerPng(path.join(pasta, 'ic_launcher_fundo.png'));
  conferir(dens + ' fundo: opaco e escuro nos cantos', fundo.pixel(1, 1).a === 255 && luz(fundo.pixel(1, 1)) < 40 && luz(fundo.pixel(1, camada - 2)) < 40);
  const quad = lerPng(path.join(pasta, 'ic_launcher.png')), red = lerPng(path.join(pasta, 'ic_launcher_round.png'));
  conferir(dens + ' ícone antigo: cantos arredondados e miolo escuro/opaco', quad.pixel(0, 0).a === 0 && quad.pixel(Math.floor(antigo / 2), Math.floor(antigo * 0.85)).a === 255);
  conferir(dens + ' ícone antigo redondo: canto transparente, centro opaco', red.pixel(0, 0).a === 0 && red.pixel(Math.floor(antigo / 2), Math.floor(antigo / 2)).a === 255);
}
// a monocromática tem camadas de transparência (as três partes da marca continuam se distinguindo quando o Android pinta com a cor do tema)
{
  const m = lerPng(path.join(RES, 'mipmap-xxxhdpi', 'ic_launcher_monochrome.png'));
  const niveis = new Set();
  for (let y = 0; y < 432; y += 3) for (let x = 0; x < 432; x += 3) { const a = m.pixel(x, y).a; if (a > 16) niveis.add(Math.round(a / 32)); }
  conferir('monocromática: mais de um nível de transparência (não é uma mancha só)', niveis.size >= 4, niveis.size + ' níveis');
}
// abertura do Android: 9:18 por densidade (o Android estica a imagem até a tela toda)
for (const [dens, f] of Object.entries(DENS)) {
  const w = Math.round(320 * f), h = Math.round(640 * f);
  for (const [pasta, ew, eh] of [['drawable-port-' + dens, w, h], ['drawable-land-' + dens, h, w]]) {
    const p = path.join(RES, pasta, 'splash.png');
    const c = fs.existsSync(p) ? lerPng(p, true) : null;
    conferir(pasta + '/splash.png: ' + ew + 'x' + eh + ', não é exemplo do Capacitor', !!c && c.w === ew && c.h === eh && !PLACEHOLDERS.has(hash(p)), c ? c.w + 'x' + c.h : 'não existe');
  }
}
{
  const c = lerPng(path.join(RES, 'drawable', 'splash.png'), true);
  conferir('drawable/splash.png (reserva): retrato e não é exemplo do Capacitor', c.h > c.w && !PLACEHOLDERS.has(hash(path.join(RES, 'drawable', 'splash.png'))), c.w + 'x' + c.h);
}
marcaSobreFundoEscuro('splash Android retrato (xxxhdpi)', path.join(RES, 'drawable-port-xxxhdpi', 'splash.png'), 0.1);

// ---------- materiais das lojas ----------
console.log('\nMateriais das lojas');
const pApp = path.join(LOJAS, 'app-store-icon-1024.png'), pPlay = path.join(LOJAS, 'google-play-icon-512.png'), pDestaque = path.join(LOJAS, 'google-play-feature-graphic-1024x500.png');
const cApp = lerPng(pApp, true), cPlay = lerPng(pPlay), cDest = lerPng(pDestaque, true);
conferir('ícone do App Store Connect: 1024x1024, sem alfa, igual ao do Xcode', cApp.w === 1024 && cApp.h === 1024 && !cApp.temAlfa && hash(pApp) === hash(pIcone));
conferir('ícone da Google Play: 512x512, menos de 1 MB, quadrado cheio e opaco (a Play arredonda), não é exemplo do Capacitor', cPlay.w === 512 && cPlay.h === 512 && cPlay.bytes < 1024 * 1024 && cPlay.pixel(0, 0).a === 255 && luz(cPlay.pixel(2, 2)) < 60 && !PLACEHOLDERS.has(hash(pPlay)));
conferir('imagem de destaque da Google Play: 1024x500 sem alfa', fs.existsSync(pDestaque) && cDest.w === 1024 && cDest.h === 500 && !cDest.temAlfa, cDest.w + 'x' + cDest.h);
marcaSobreFundoEscuro('ícone da Google Play', pPlay, 0.25);

// ---------- documentação ----------
console.log('\nDocumentação');
// nomes de arquivo sem pasta (ex.: ic_launcher.png) valem se existir um arquivo com esse nome em qualquer lugar de mobile/
const nomesEmMobile = new Set();
(function varrer(dir) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (e.name === 'node_modules' || e.name === '.git') continue;
    if (e.isDirectory()) varrer(path.join(dir, e.name)); else nomesEmMobile.add(e.name);
  }
})(MOBILE);
const existeCitado = (n) => [path.join(LOJAS, n), path.join(RAIZ, n), path.join(MOBILE, n)].some((p) => fs.existsSync(p)) || (!n.includes('/') && nomesEmMobile.has(n));
const readme = fs.readFileSync(path.join(LOJAS, 'README.md'), 'utf8');
const citados = [...readme.matchAll(/`([\w@.\/-]+\.(?:png|svg|md|py))`/g)].map((m) => m[1]);
const inexistentes = citados.filter((n) => !existeCitado(n));
conferir('store-assets/README.md só cita arquivos que existem', citados.length >= 4 && inexistentes.length === 0, inexistentes.join(', '));
const lista = fs.readFileSync(path.join(MOBILE, 'STORE-LAUNCH-CHECKLIST.md'), 'utf8');
const feitosCitados = [];
for (const l of lista.split('\n')) if (/^- \[x\]/i.test(l)) for (const m of l.matchAll(/`([\w@.\/-]+\.(?:png|svg|md|py|json|html))`/g)) feitosCitados.push(m[1]);
const faltando = feitosCitados.filter((n) => !existeCitado(n));
conferir('o checklist não marca como feito (- [x]) nada que cita arquivo inexistente', faltando.length === 0, faltando.join(', '));
conferir('o gerador existe (tools/marca/gerar-icones-lojas.py)', fs.existsSync(path.join(RAIZ, 'tools', 'marca', 'gerar-icones-lojas.py')));

console.log('\n' + (falhas ? 'FALHOU: ' + falhas + ' (e ' + ok + ' conferências passaram)' : 'Tudo certo: ' + ok + ' conferências.'));
process.exit(falhas ? 1 : 0);
