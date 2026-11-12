// Bibliotecas de terceiros: o app abre sem pedir nada a CDN, e nada de terceiro roda sem hash.
//   - cópias em app/painel/vendor/ são byte a byte as registradas em INTEGRIDADE.json (nada foi editado por engano);
//   - nenhuma tela do painel (nem o relatório de fotos) carrega <script>/<link>/@import de fora sem integrity;
//   - o SRI do libheif (HEIC) usado no código é o do manifesto;
//   - o service worker só lista arquivos que existem, com o mesmo ?v= que as telas pedem;
//   - o pacote nativo leva as pastas de que o aluno.html e o login.html precisam;
//   - no navegador, SEM rede: o aluno.html e o login.html abrem sem nenhum pedido a domínio externo, html2canvas e Leaflet carregam,
//     a fonte Inter vem do site, e os SDKs do Google e do Facebook só são pedidos quando o ID do app está configurado.
//   cd testes && INTUS_DIR=../app/painel node terceiros.js
const fs = require('fs'), path = require('path'), crypto = require('crypto'), http = require('http');
const PAINEL = path.resolve(process.env.INTUS_DIR || '../app/painel');
const RAIZ = path.resolve(PAINEL, '..', '..');
const VENDOR = path.join(PAINEL, 'vendor');
let ok = 0, ruim = 0;
function ver(rot, cond, extra) {
  if (cond) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot); if (extra !== undefined) console.log('          ' + JSON.stringify(extra).slice(0, 300)); }
}
const ler = (p) => fs.readFileSync(p, 'utf8');
const sri = (b) => 'sha384-' + crypto.createHash('sha384').update(b).digest('base64');

(async () => {
  console.log('\n══ Cópias locais iguais às originais (INTEGRIDADE.json) ══');
  const man = JSON.parse(ler(path.join(VENDOR, 'INTEGRIDADE.json')));
  for (const grupo of ['arquivos', 'copias_de_outra_fonte']) {
    for (const [rel, info] of Object.entries(man[grupo])) {
      const f = path.join(VENDOR, rel);
      const existe = fs.existsSync(f);
      ver(rel + ': existe e o hash confere', existe && sri(fs.readFileSync(f)) === info.sha384, existe ? sri(fs.readFileSync(f)) : 'ausente');
    }
  }
  for (const pasta of ['html2canvas', 'leaflet', 'jszip', 'jspdf']) ver(pasta + ': tem o texto da licença', fs.existsSync(path.join(VENDOR, pasta, 'LICENSE.txt')));
  ver('inter: tem a licença OFL', fs.existsSync(path.join(VENDOR, 'inter', 'OFL.txt')));

  console.log('\n══ Nenhuma tela carrega script, estilo ou fonte de fora sem SRI ══');
  const telas = fs.readdirSync(PAINEL).filter(f => f.endsWith('.html')).map(f => path.join(PAINEL, f));
  telas.push(path.join(RAIZ, 'relatorios-fotos', 'index.html'));
  const externo = /^(https?:)?\/\//i;
  const achados = [];
  for (const t of telas) {
    const html = ler(t);
    for (const m of html.matchAll(/<script\b[^>]*\bsrc=["']([^"']+)["'][^>]*>/gi)) {
      if (externo.test(m[1]) && !/\bintegrity=/i.test(m[0])) achados.push(path.basename(t) + ' script ' + m[1]);
    }
    for (const m of html.matchAll(/<link\b[^>]*>/gi)) {
      const h = /\bhref=["']([^"']+)["']/i.exec(m[0]);
      if (h && /rel=["']?stylesheet/i.test(m[0]) && externo.test(h[1]) && !/\bintegrity=/i.test(m[0])) achados.push(path.basename(t) + ' css ' + h[1]);
    }
    for (const m of html.matchAll(/@import\s+(?:url\()?["']?(https?:[^"')\s]+)/gi)) achados.push(path.basename(t) + ' @import ' + m[1]);
  }
  ver('nenhuma das ' + telas.length + ' telas (painel + relatório de fotos) tem recurso de fora sem integrity', achados.length === 0, achados);
  ver('_mock.js (injetado em todas as telas) não pede nada ao Google Fonts', !/fonts\.googleapis\.com|fonts\.gstatic\.com/.test(ler(path.join(PAINEL, '_mock.js'))));
  const aluno = ler(path.join(PAINEL, 'aluno.html')), login = ler(path.join(PAINEL, 'login.html'));
  ver('aluno.html e login.html: nada de CDN, Google Fonts, Google ou Facebook escrito direto no HTML',
    !/cdn\.jsdelivr|cdnjs|unpkg|fonts\.googleapis|fonts\.gstatic/.test(aluno + login) && !/<script[^>]+(accounts\.google|connect\.facebook)/.test(login));
  ver('aluno.html carrega html2canvas, Leaflet (js e css) e Inter de vendor/',
    /src="vendor\/html2canvas\/html2canvas\.min\.js\?v=/.test(aluno) && /src="vendor\/leaflet\/leaflet\.js\?v=/.test(aluno) && /href="vendor\/leaflet\/leaflet\.css\?v=/.test(aluno) && /href="vendor\/inter\/inter\.css\?v=/.test(aluno));
  ver('relatorios-fotos carrega jszip e jspdf de vendor/', /src="\/app\/painel\/vendor\/jszip\/jszip\.min\.js/.test(ler(path.join(RAIZ, 'relatorios-fotos/index.html'))) && /src="\/app\/painel\/vendor\/jspdf\/jspdf\.umd\.min\.js/.test(ler(path.join(RAIZ, 'relatorios-fotos/index.html'))));
  const css = ler(path.join(VENDOR, 'inter', 'inter.css'));
  const cssSem = css.replace(/\/\*[\s\S]*?\*\//g, '');
  ver('inter.css: font-display swap e os dois arquivos da fonte', (cssSem.match(/font-display:\s*swap/g) || []).length === 2 && /inter-latin\.woff2/.test(css) && /inter-latin-ext\.woff2/.test(css));

  console.log('\n══ libheif (HEIC): SRI do código = SRI do manifesto ══');
  const heif = man.terceiros_remotos['libheif-bundle'];
  for (const f of heif.usado_em) {
    const src = ler(path.join(RAIZ, f));
    ver(f + ': usa o hash do manifesto, integrity e crossOrigin', src.includes("'" + heif.sha384 + "'") && /\.integrity\s*=\s*LIBHEIF_SRI/.test(src) && /\.crossOrigin\s*=\s*'anonymous'/.test(src) && src.includes(heif.url));
  }

  console.log('\n══ Service worker e pacote nativo ══');
  const sw = ler(path.join(PAINEL, 'sw.js'));
  const lista = (/const ASSETS = \[([\s\S]*?)\];/.exec(sw) || [, ''])[1];
  const itens = [...lista.matchAll(/'([^']+)'/g)].map(m => m[1]);
  const faltam = itens.filter(i => !fs.existsSync(path.join(PAINEL, i.split('?')[0])));
  ver('todo arquivo do ASSETS do sw.js existe (um 404 derruba a instalação do service worker)', itens.length > 10 && faltam.length === 0, faltam);
  const vers = [...aluno.matchAll(/(?:src|href)="(vendor\/[^"]+\?v=[^"]+)"/g)].map(m => m[1]);
  const semPrecache = vers.filter(v => !itens.includes(v));
  ver('o ?v= que o aluno.html pede é o mesmo do sw.js', vers.length === 4 && semPrecache.length === 0, semPrecache);
  const build = ler(path.join(RAIZ, 'mobile/scripts/build-web.mjs'));
  ver('o pacote das lojas leva vendor/html2canvas, leaflet e inter (e não o pdfjs)', ['html2canvas', 'leaflet', 'inter'].every(p => build.includes("'" + p + "'")) && !/pdfjs/.test(build.replace(/\/\/.*$/gm, '')));
  const versoes = new Set(); telas.forEach(t => { const m = /_mock\.js\?v=([0-9a-z]+)/.exec(ler(t)); if (m) versoes.add(m[1]); });
  ver('_mock.js: um ?v= só em todas as telas do painel', versoes.size === 1, [...versoes]);

  console.log('\n══ No navegador, sem rede ══');
  let chromium = null; try { ({ chromium } = require('playwright')); } catch (e) {}
  if (!chromium) { console.log('  (playwright ausente: parte de navegador pulada)'); } else {
    const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.woff2': 'font/woff2', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
    const srv = http.createServer((req, res) => {
      let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(PAINEL, p);
      if (!f.startsWith(PAINEL) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); res.end('nao'); return; }
      res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f));
    });
    await new Promise(r => srv.listen(0, '127.0.0.1', r));
    const B = 'http://127.0.0.1:' + srv.address().port;
    const b = await chromium.launch();
    const nova = async () => {
      const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
      const page = await ctx.newPage();
      const pedidos = [], erros = [];
      page.on('request', r => { if (!r.url().startsWith(B) && !r.url().startsWith('data:') && !r.url().startsWith('blob:')) pedidos.push(r.url()); });
      page.on('pageerror', e => erros.push(String(e.message)));
      return { ctx, page, pedidos, erros };
    };
    try {
      // aluno.html
      let t = await nova();
      await t.page.route(/\/app\/api\//, r => r.abort('failed'));
      await t.page.addInitScript(() => { try { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); } catch (e) {} });
      const respostas = []; t.page.on('response', r => { if (r.url().startsWith(B + '/vendor/')) respostas.push(r.status() + ' ' + r.url().slice(B.length)); });
      await t.page.goto(B + '/aluno.html', { waitUntil: 'load' });
      await t.page.waitForTimeout(800);
      const est = await t.page.evaluate(async () => {
        await document.fonts.ready;
        let inter = false; document.fonts.forEach(f => { if (f.family.replace(/["']/g, '') === 'Inter' && f.status === 'loaded') inter = true; });
        return { h2c: typeof html2canvas, L: typeof L === 'object' && typeof L.map === 'function', interCarregada: inter, url: location.pathname };
      });
      ver('aluno.html ficou na própria tela (não foi chutado para o login)', est.url === '/aluno.html', est);
      ver('aluno.html: nenhum pedido a domínio externo ao abrir', t.pedidos.length === 0, t.pedidos);
      ver('aluno.html: html2canvas e Leaflet (L.map) carregaram do site', est.h2c === 'function' && est.L, est);
      ver('aluno.html: a fonte Inter foi carregada do site (vendor/inter)', est.interCarregada && respostas.some(r => /^200 \/vendor\/inter\/inter-latin\.woff2/.test(r)), respostas);
      ver('aluno.html: todo arquivo de vendor/ respondeu 200', respostas.length >= 5 && respostas.every(r => r.startsWith('200 ')), respostas);
      ver('aluno.html: sem erro de JavaScript na abertura', t.erros.length === 0, t.erros);
      await t.ctx.close();

      // login.html com os IDs ainda de mentira: nada do Google nem do Facebook
      t = await nova();
      await t.page.route(/\/app\/api\//, r => r.abort('failed'));
      await t.page.goto(B + '/login.html', { waitUntil: 'load' });
      await t.page.waitForTimeout(500);
      ver('login.html (login social desligado): nenhum pedido ao Google, ao Facebook nem a qualquer domínio externo', t.pedidos.length === 0, t.pedidos);
      ver('login.html: sem erro de JavaScript', t.erros.length === 0, t.erros);
      await t.ctx.close();

      // login.html com os IDs configurados: os SDKs são pedidos (com o endereço servido de mentira, sem sair da máquina)
      t = await nova();
      await t.page.route(/\/app\/api\//, r => r.abort('failed'));
      await t.page.route(B + '/login.html', async r => {
        const resp = await r.fetch(); let html = await resp.text();
        html = html.replace('SUBSTITUA_PELO_CLIENT_ID_DO_GOOGLE', '123.apps.googleusercontent.com').replace('SUBSTITUA_PELO_APP_ID_DO_FACEBOOK', '999');
        await r.fulfill({ response: resp, body: html });
      });
      const sdk = [];
      await t.page.route(/accounts\.google\.com\/gsi\/client/, r => { sdk.push('google'); r.fulfill({ contentType: 'text/javascript', body: 'window.__gsi=1;' }); });
      await t.page.route(/connect\.facebook\.net\/pt_BR\/sdk\.js/, r => { sdk.push('facebook'); r.fulfill({ contentType: 'text/javascript', body: 'window.__fb=1; if (window.fbAsyncInit) window.fbAsyncInit();' }); });
      await t.page.goto(B + '/login.html', { waitUntil: 'load' });
      await t.page.waitForTimeout(500);
      ver('login.html com os dois IDs configurados: pede os dois SDKs, cada um uma vez', sdk.slice().sort().join(',') === 'facebook,google', sdk);
      ver('login.html configurado: os scripts entraram na página sem erro', t.erros.length === 0 && await t.page.evaluate(() => window.__gsi === 1 && window.__fb === 1), t.erros);
      await t.ctx.close();
    } finally { await b.close(); srv.close(); }
  }

  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : 'tudo certo') + ' (' + ok + ' conferências)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
