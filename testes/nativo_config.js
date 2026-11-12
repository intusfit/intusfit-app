// Configuração nativa dos apps das lojas (mobile/): o que a revisão pré-lojas de 11/11/2026 pediu e que dá para provar SEM Xcode, SEM Android SDK
// e SEM conta de loja. Node puro: lê Info.plist, PrivacyInfo.xcprivacy, project.pbxproj, AndroidManifest.xml, os XML de backup, build.gradle,
// .gitignore, README e package.json e falha se qualquer ajuste for desfeito. NÃO compila nem abre nada em aparelho.
// Cada bloco foi quebrado de propósito (ver CLAUDE.md, seção 146) para provar que o teste acusa.
const fs = require('fs'), path = require('path');
const RAIZ = path.resolve(__dirname, '..');
const MOBILE = path.join(RAIZ, 'mobile');
const IOS_APP = path.join(MOBILE, 'ios', 'App', 'App');
const PBX = path.join(MOBILE, 'ios', 'App', 'App.xcodeproj', 'project.pbxproj');
const AND_APP = path.join(MOBILE, 'android', 'app');
const MANIFEST = path.join(AND_APP, 'src', 'main', 'AndroidManifest.xml');
const XML_RES = path.join(AND_APP, 'src', 'main', 'res', 'xml');

let ok = 0, falhas = 0;
function conferir(nome, cond, detalhe) {
  if (cond) { ok++; console.log('  OK    ' + nome); }
  else { falhas++; console.log('  FALHA ' + nome + (detalhe ? ' (' + detalhe + ')' : '')); }
}
const rel = (p) => path.relative(RAIZ, p).replace(/\\/g, '/');
function ler(p) {
  try { return fs.readFileSync(p, 'utf8'); }
  catch (e) { conferir('arquivo existe: ' + rel(p), false, e.code); return null; }
}
const semComentarios = (s) => s.replace(/<!--[\s\S]*?-->/g, '');

// ---------- leitor mínimo de plist XML (dict, array, string, true, false, integer, real) ----------
function lerPlist(xml) {
  const s = semComentarios(xml);
  const re = /<(\/?)(dict|array|key|string|integer|real)(\s*\/)?>|<(true|false)\s*\/>|([^<]+)/g;
  let m;
  const raiz = { tipo: 'raiz', v: [] };
  const pilha = [raiz];
  let aberto = null; // key/string/integer/real em leitura
  const colocar = (v) => { const topo = pilha[pilha.length - 1]; topo.v.push(v); };
  const dec = (t) => t.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&apos;/g, "'").replace(/&amp;/g, '&');
  const iniPlist = s.indexOf('<plist');
  re.lastIndex = iniPlist >= 0 ? iniPlist : 0;
  while ((m = re.exec(s))) {
    if (m[4]) { colocar(m[4] === 'true'); continue; }
    if (m[5] !== undefined) { if (aberto) aberto.texto += m[5]; continue; }
    const fecha = m[1] === '/', nome = m[2], vazio = !!m[3];
    if (nome === 'dict' || nome === 'array') {
      if (fecha) { const no = pilha.pop(); colocar(no.tipo === 'dict' ? paraObj(no.v) : no.v); }
      else if (vazio) colocar(nome === 'dict' ? {} : []);
      else pilha.push({ tipo: nome, v: [] });
    } else {
      if (!fecha && !vazio) aberto = { nome, texto: '' };
      else if (!fecha && vazio) colocar(nome === 'key' ? { __key: '' } : '');
      else if (fecha && aberto) {
        const t = dec(aberto.texto);
        colocar(aberto.nome === 'key' ? { __key: t } : aberto.nome === 'integer' ? parseInt(t, 10) : aberto.nome === 'real' ? parseFloat(t) : t);
        aberto = null;
      }
    }
  }
  function paraObj(lista) {
    const o = {};
    for (let i = 0; i < lista.length; i += 2) o[lista[i] && lista[i].__key] = lista[i + 1];
    return o;
  }
  // o primeiro elemento colocado na raiz depois de <plist> é o dict principal
  return raiz.v[0];
}

// ====================================================================================================================
console.log('iOS: Info.plist');
const infoXml = ler(path.join(IOS_APP, 'Info.plist'));
let info = {};
if (infoXml) { info = lerPlist(infoXml) || {}; conferir('Info.plist lê como plist válido (dict com chaves)', Object.keys(info).length > 10, Object.keys(info).length + ' chaves'); }

const mic = info.NSMicrophoneUsageDescription;
conferir('NSMicrophoneUsageDescription existe e não está vazia', typeof mic === 'string' && mic.trim().length > 20);
conferir('NSMicrophoneUsageDescription está em português e diz que é para o som de vídeo de post e feedback',
  typeof mic === 'string' && /microfone/i.test(mic) && /v[ií]deo/i.test(mic) && /post/i.test(mic) && /feedback/i.test(mic), mic);
conferir('ITSAppUsesNonExemptEncryption = false (só HTTPS, criptografia isenta)', info.ITSAppUsesNonExemptEncryption === false, String(info.ITSAppUsesNonExemptEncryption));
conferir('CFBundleDevelopmentRegion = pt-BR (avisos do sistema em português)', info.CFBundleDevelopmentRegion === 'pt-BR', String(info.CFBundleDevelopmentRegion));
conferir('CFBundleLocalizations inclui pt-BR', Array.isArray(info.CFBundleLocalizations) && info.CFBundleLocalizations.includes('pt-BR'), JSON.stringify(info.CFBundleLocalizations));
// Os avisos já existentes continuam no lugar, em português e sem texto de exemplo.
['NSCameraUsageDescription', 'NSPhotoLibraryUsageDescription', 'NSPhotoLibraryAddUsageDescription', 'NSLocationWhenInUseUsageDescription'].forEach((k) => {
  const v = info[k];
  conferir(k + ' existe, em português', typeof v === 'string' && v.trim().length > 20 && /\b(usamos|para|sua|seu|você)\b/i.test(v), String(v));
});
const todosAvisos = Object.keys(info).filter((k) => /UsageDescription$/.test(k));
conferir('nenhum aviso de uso em inglês nem texto de exemplo', todosAvisos.every((k) => !/\b(the|we use|this app|allow)\b/i.test(info[k])), todosAvisos.join(','));
conferir('iPhone continua só em retrato e UIBackgroundModes = audio (não mexidos aqui)',
  Array.isArray(info.UISupportedInterfaceOrientations) && info.UISupportedInterfaceOrientations.length === 1 &&
  Array.isArray(info.UIBackgroundModes) && info.UIBackgroundModes.join() === 'audio');

// ====================================================================================================================
console.log('iOS: PrivacyInfo.xcprivacy');
const privXml = ler(path.join(IOS_APP, 'PrivacyInfo.xcprivacy'));
let priv = {};
if (privXml) { priv = lerPlist(privXml) || {}; conferir('PrivacyInfo.xcprivacy lê como plist válido', Object.keys(priv).length >= 4, Object.keys(priv).join(',')); }
conferir('NSPrivacyTracking = false', priv.NSPrivacyTracking === false);
conferir('NSPrivacyTrackingDomains é lista vazia', Array.isArray(priv.NSPrivacyTrackingDomains) && priv.NSPrivacyTrackingDomains.length === 0);
conferir('NSPrivacyAccessedAPITypes é uma lista', Array.isArray(priv.NSPrivacyAccessedAPITypes));
const coletados = Array.isArray(priv.NSPrivacyCollectedDataTypes) ? priv.NSPrivacyCollectedDataTypes : [];
const nomesColetados = coletados.map((c) => String(c.NSPrivacyCollectedDataType || '').replace('NSPrivacyCollectedDataType', ''));
['Name', 'EmailAddress', 'PhoneNumber', 'PreciseLocation', 'CoarseLocation', 'PhotosorVideos', 'Health', 'Fitness', 'UserID'].forEach((t) =>
  conferir('declara o dado coletado: ' + t, nomesColetados.includes(t)));
conferir('nenhum dado coletado declarado duas vezes', new Set(nomesColetados).size === nomesColetados.length, nomesColetados.join(','));
conferir('todo dado coletado: ligado ao usuário, sem rastreamento e com finalidade declarada',
  coletados.length > 0 && coletados.every((c) => c.NSPrivacyCollectedDataTypeLinked === true && c.NSPrivacyCollectedDataTypeTracking === false &&
    Array.isArray(c.NSPrivacyCollectedDataTypePurposes) && c.NSPrivacyCollectedDataTypePurposes.length > 0 &&
    c.NSPrivacyCollectedDataTypePurposes.every((p) => /^NSPrivacyCollectedDataTypePurpose[A-Za-z]+$/.test(p))));
conferir('nenhuma finalidade de rastreamento ou anúncio (o app não rastreia nem veicula anúncio)',
  coletados.every((c) => (c.NSPrivacyCollectedDataTypePurposes || []).every((p) => !/ThirdPartyAdvertising|DeveloperAdvertising/.test(p))));
// Coerência entre o aviso de localização do Info.plist e a declaração de localização.
if (info.NSLocationWhenInUseUsageDescription) conferir('há aviso de localização no Info.plist, então a localização precisa está declarada', nomesColetados.includes('PreciseLocation'));
// APIs de motivo obrigatório: se o Swift do app passar a usar alguma, a lista não pode continuar vazia.
const RAZAO = /\b(UserDefaults|NSUserDefaults|creationDate|modificationDate|NSFileCreationDate|NSFileModificationDate|contentModificationDateKey|creationDateKey|systemFreeSize|systemSize|volumeAvailableCapacity|volumeTotalCapacity|systemUptime|mach_absolute_time|activeInputModes|getattrlist|fstat|lstat|stat)\s*(\(|\b)/;
const swifts = fs.existsSync(IOS_APP) ? fs.readdirSync(IOS_APP).filter((f) => /\.(swift|m|h)$/.test(f)) : [];
conferir('há arquivos Swift do app para conferir (AppDelegate e SceneDelegate)', swifts.includes('AppDelegate.swift') && swifts.includes('SceneDelegate.swift'), swifts.join(','));
const usaRazao = swifts.filter((f) => RAZAO.test(semComentariosSwift(fs.readFileSync(path.join(IOS_APP, f), 'utf8'))));
function semComentariosSwift(t) { return t.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, ''); }
conferir('Swift do app não usa API de motivo obrigatório (ou, se usa, está declarada em NSPrivacyAccessedAPITypes)',
  usaRazao.length === 0 || (Array.isArray(priv.NSPrivacyAccessedAPITypes) && priv.NSPrivacyAccessedAPITypes.length > 0), usaRazao.join(','));
// Plugins: um plugin Capacitor novo obriga a repetir a conferência das APIs de motivo obrigatório.
const pkg = JSON.parse(ler(path.join(MOBILE, 'package.json')) || '{}');
const PLUGINS_CONFERIDOS = ['@capacitor/android', '@capacitor/core', '@capacitor/ios', '@capacitor/local-notifications'];
const plugins = Object.keys(pkg.dependencies || {});
conferir('só há dependências nativas já conferidas (plugin novo exige refazer a conferência do PrivacyInfo e das permissões Android)',
  plugins.every((p) => PLUGINS_CONFERIDOS.includes(p)), plugins.filter((p) => !PLUGINS_CONFERIDOS.includes(p)).join(','));

// ====================================================================================================================
console.log('iOS: PrivacyInfo.xcprivacy dentro do target App (project.pbxproj)');
const pbx = ler(PBX);
if (pbx) {
  const refM = pbx.match(/^\s*([0-9A-F]{24}) \/\* PrivacyInfo\.xcprivacy \*\/ = \{isa = PBXFileReference;[^}]*path = PrivacyInfo\.xcprivacy;/m);
  conferir('PBXFileReference do PrivacyInfo.xcprivacy existe', !!refM);
  const refId = refM && refM[1];
  const bfM = refId && pbx.match(new RegExp('^\\s*([0-9A-F]{24}) /\\* PrivacyInfo\\.xcprivacy in Resources \\*/ = \\{isa = PBXBuildFile; fileRef = ' + refId + ' ', 'm'));
  conferir('PBXBuildFile aponta para o mesmo arquivo', !!bfM);
  const bfId = bfM && bfM[1];
  const resFase = pbx.match(/\/\* Begin PBXResourcesBuildPhase section \*\/([\s\S]*?)\/\* End PBXResourcesBuildPhase section \*\//);
  conferir('o arquivo entra na fase Resources do target (sem isso a Apple não o recebe)', !!(resFase && bfId && resFase[1].includes(bfId + ' /* PrivacyInfo.xcprivacy in Resources */')));
  const grupo = pbx.match(/\/\* Begin PBXGroup section \*\/([\s\S]*?)\/\* End PBXGroup section \*\//);
  const appGrupo = grupo && grupo[1].match(/isa = PBXGroup;\s*children = \(([\s\S]*?)\);\s*path = App;/);
  conferir('o arquivo está no grupo "App" ao lado do Info.plist', !!(appGrupo && refId && appGrupo[1].includes(refId + ' /* PrivacyInfo.xcprivacy */') && appGrupo[1].includes('Info.plist')));
  if (refId) conferir('o id do arquivo aparece só nos 3 lugares esperados (referência, grupo, build)', (pbx.match(new RegExp(refId, 'g')) || []).length === 3, String((pbx.match(new RegExp(refId, 'g')) || []).length));
  // Todo id usado como fileRef tem referência declarada (a edição à mão não pode ter deixado id solto).
  const declarados = new Set([...pbx.matchAll(/^\s*([0-9A-F]{24}) \/\* .*? \*\/ = \{(?:isa = PBX(?:FileReference|Group|VariantGroup)|\s*$)/gm)].map((x) => x[1]));
  const usados = [...pbx.matchAll(/fileRef = ([0-9A-F]{24})/g)].map((x) => x[1]);
  conferir('todo fileRef do projeto aponta para um arquivo declarado', usados.every((u) => declarados.has(u)), usados.filter((u) => !declarados.has(u)).join(','));
  conferir('a pasta do arquivo (ios/App/App) tem mesmo o PrivacyInfo.xcprivacy', fs.existsSync(path.join(IOS_APP, 'PrivacyInfo.xcprivacy')));
  // Item do iPad: só registramos o estado, a decisão é do Luiz (CLAUDE.md, seção 146). O teste NÃO fixa o valor.
  conferir('TARGETED_DEVICE_FAMILY existe (decisão do iPad pendente do Luiz; o teste não fixa o valor)', /TARGETED_DEVICE_FAMILY = "?[0-9,]+"?;/.test(pbx));
}

// ====================================================================================================================
console.log('Android: AndroidManifest.xml');
const manifestXml = ler(MANIFEST);
if (manifestXml) {
  const man = semComentarios(manifestXml);
  conferir('android:allowBackup="false" (o token de sessão fica no localStorage)', /<application\b[^>]*android:allowBackup="false"/.test(man));
  const dex = man.match(/android:dataExtractionRules="@xml\/([A-Za-z0-9_]+)"/);
  const fbc = man.match(/android:fullBackupContent="@xml\/([A-Za-z0-9_]+)"/);
  conferir('dataExtractionRules aponta para um XML que existe (Android 12+)', !!dex && fs.existsSync(path.join(XML_RES, dex[1] + '.xml')), dex && dex[1]);
  conferir('fullBackupContent aponta para um XML que existe (Android 11 e anteriores)', !!fbc && fs.existsSync(path.join(XML_RES, fbc[1] + '.xml')), fbc && fbc[1]);
  const DOMINIOS = ['root', 'file', 'database', 'sharedpref', 'external'];
  const temTodos = (trecho) => DOMINIOS.every((d) => new RegExp('<exclude\\s+domain="' + d + '"').test(trecho));
  if (dex && fs.existsSync(path.join(XML_RES, dex[1] + '.xml'))) {
    const x = semComentarios(fs.readFileSync(path.join(XML_RES, dex[1] + '.xml'), 'utf8'));
    const cloud = (x.match(/<cloud-backup[^>]*>([\s\S]*?)<\/cloud-backup>/) || [])[1] || '';
    const transf = (x.match(/<device-transfer[^>]*>([\s\S]*?)<\/device-transfer>/) || [])[1] || '';
    conferir('regras de extração excluem todos os domínios do backup na nuvem', temTodos(cloud));
    conferir('regras de extração excluem todos os domínios da transferência entre aparelhos', temTodos(transf));
    conferir('regras de extração não incluem nada (<include>)', !/<include\b/.test(x));
  }
  if (fbc && fs.existsSync(path.join(XML_RES, fbc[1] + '.xml'))) {
    const x = semComentarios(fs.readFileSync(path.join(XML_RES, fbc[1] + '.xml'), 'utf8'));
    conferir('regras de backup antigas excluem todos os domínios', /<full-backup-content/.test(x) && temTodos(x));
    conferir('regras de backup antigas não incluem nada (<include>)', !/<include\b/.test(x));
  }
  const perms = [...man.matchAll(/<uses-permission\s+android:name="android\.permission\.([A-Z_]+)"/g)].map((m) => m[1]);
  ['READ_MEDIA_IMAGES', 'READ_MEDIA_VIDEO', 'READ_MEDIA_AUDIO', 'READ_MEDIA_VISUAL_USER_SELECTED', 'READ_EXTERNAL_STORAGE', 'WRITE_EXTERNAL_STORAGE', 'MANAGE_EXTERNAL_STORAGE'].forEach((p) =>
    conferir('não declara ' + p + ' (o app usa o seletor do sistema; o Google Play restringe essa permissão)', !perms.includes(p)));
  ['INTERNET', 'CAMERA', 'ACCESS_FINE_LOCATION', 'ACCESS_COARSE_LOCATION', 'POST_NOTIFICATIONS', 'VIBRATE'].forEach((p) =>
    conferir('continua declarando ' + p + ' (o app usa)', perms.includes(p)));
  conferir('não pede localização em segundo plano (fora do escopo desta release)', !perms.includes('ACCESS_BACKGROUND_LOCATION'));
  // Quem lê a galeria por conta própria precisaria da permissão de volta: plugin de câmera, arquivos ou mídia.
  const PLUGINS_MIDIA = ['@capacitor/camera', '@capacitor/filesystem', '@capacitor/media', '@capacitor-community/media', '@capacitor-community/camera-preview'];
  conferir('nenhum plugin de câmera, arquivos ou galeria instalado (se entrar, reavaliar as permissões de mídia)', plugins.every((p) => !PLUGINS_MIDIA.includes(p)), plugins.join(','));
}
// O campo de arquivo do web app tem de continuar sendo o seletor do sistema (nada de leitura direta da galeria).
const aluno = ler(path.join(RAIZ, 'app', 'painel', 'aluno.html'));
if (aluno) {
  conferir('aluno.html usa campos <input type="file"> (seletor do sistema) para foto e vídeo', /<input type="file"[^>]*accept="[^"]*image\/\*/.test(aluno) && /<input type="file"[^>]*accept="[^"]*video\/mp4/.test(aluno));
  conferir('aluno.html nunca pede o microfone em tempo real (getUserMedia com audio: true); o aviso de microfone do iOS é só pela câmera do sistema', !/getUserMedia\([^)]*audio:\s*true/.test(aluno));
}

// ====================================================================================================================
console.log('Android: build.gradle (assinatura sem chave no repositório, release coerente)');
const gradle = ler(path.join(AND_APP, 'build.gradle'));
if (gradle) {
  const g = gradle.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  conferir('há bloco signingConfigs', /signingConfigs\s*\{/.test(g));
  ['INTUS_KEYSTORE_FILE', 'INTUS_KEYSTORE_PASSWORD', 'INTUS_KEY_ALIAS', 'INTUS_KEY_PASSWORD'].forEach((v) =>
    conferir('lê ' + v + ' de variável de ambiente ou de gradle.properties', new RegExp("intusSeg\\('" + v + "'\\)").test(g)));
  conferir('a leitura usa System.getenv e project.findProperty (~/.gradle/gradle.properties)', /System\.getenv\(/.test(g) && /project\.findProperty\(/.test(g));
  conferir('senha da chave e do keystore nunca em texto no gradle', !/\b(storePassword|keyPassword)\s*=?\s*['"][^'"]*['"]/.test(g));
  conferir('caminho do keystore nunca em texto no gradle', !/\bstoreFile\s*=?\s*file\(\s*['"]/.test(g));
  conferir('alias da chave nunca em texto no gradle', !/\bkeyAlias\s*=?\s*['"][^'"]*['"]/.test(g));
  conferir('o signingConfig só é aplicado ao release quando as variáveis existem', /if\s*\(intusAssina\)\s*\{\s*signingConfig\s+signingConfigs\.release/.test(g));
  conferir('bloco release existe e usa o proguard-android.txt', /proguardFiles\s+getDefaultProguardFile\('proguard-android\.txt'\)/.test(g));
  conferir('release não é debuggable', !/debuggable\s*=?\s*true/.test(g));
  const minify = (g.match(/minifyEnabled\s+(true|false)/) || [])[1];
  conferir('minifyEnabled declarado de propósito (hoje false: sem regras de manter, o R8 quebraria os plugins e a ponte IntusNativo)', minify === 'false' || minify === 'true', String(minify));
  if (minify === 'true') {
    const pr = ler(path.join(AND_APP, 'proguard-rules.pro')) || '';
    conferir('se o R8 estiver ligado, proguard-rules.pro mantém a ponte IntusNativo e o Capacitor',
      /JavascriptInterface/.test(pr) && /com\.getcapacitor/.test(pr));
  }
  conferir('versionCode e versionName declarados', /versionCode\s+\d+/.test(g) && /versionName\s+"[^"]+"/.test(g));
}
// Segredos de assinatura nunca versionados.
function varrer(dir, achados) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    if (['node_modules', 'www', '.gradle', 'build', 'Pods', 'public'].includes(e.name)) continue;
    const p = path.join(dir, e.name);
    if (e.isDirectory()) varrer(p, achados);
    else if (/\.(jks|keystore|p12|p8|pem|mobileprovision|cer)$/i.test(e.name) || /^key\.properties$/i.test(e.name)) achados.push(rel(p));
  }
}
const segredos = []; varrer(MOBILE, segredos);
conferir('nenhuma chave, certificado ou perfil de provisão dentro de mobile/', segredos.length === 0, segredos.join(','));
const propsFiles = ['gradle.properties', 'local.properties', 'key.properties'].map((f) => path.join(MOBILE, 'android', f)).filter((f) => fs.existsSync(f));
const propsRuins = propsFiles.filter((f) => /^\s*(INTUS_[A-Z_]*|[A-Za-z.]*(password|keystore|storeFile|keyAlias)[A-Za-z.]*)\s*=\s*\S/im.test(fs.readFileSync(f, 'utf8')));
conferir('gradle.properties do projeto não guarda senha nem caminho de keystore (isso vai em ~/.gradle/gradle.properties)', propsRuins.length === 0, propsRuins.map(rel).join(','));
const gi = ler(path.join(MOBILE, '.gitignore'));
if (gi) ['*.jks', '*.keystore', '*.p12', '*.pem', '*.mobileprovision', 'key.properties'].forEach((pad) =>
  conferir('mobile/.gitignore bloqueia ' + pad, gi.split(/\r?\n/).map((l) => l.trim()).includes(pad)));
const readme = ler(path.join(MOBILE, 'README.md'));
if (readme) {
  ['INTUS_KEYSTORE_FILE', 'INTUS_KEYSTORE_PASSWORD', 'INTUS_KEY_ALIAS', 'INTUS_KEY_PASSWORD'].forEach((v) => conferir('README documenta ' + v, readme.includes(v)));
  conferir('README manda guardar a assinatura em ~/.gradle/gradle.properties e nunca no do projeto', readme.includes('~/.gradle/gradle.properties') && /Nunca\*\* em `mobile\/android\/gradle\.properties`/.test(readme));
}

// ---------- registro ----------
const rodar = ler(path.join(__dirname, 'rodar.sh'));
if (rodar) conferir('testes/rodar.sh executa nativo_config.js', /node nativo_config\.js/.test(rodar));

console.log('\n' + ok + ' ok, ' + falhas + ' falha(s)');
process.exit(falhas ? 1 : 0);
