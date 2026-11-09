// Avaliações (painel), "Nova avaliação": arrastar fotos do computador para a caixa de fotos (antes o navegador abria cada foto numa aba nova).
//   cd testes && INTUS_DIR=../app/painel node avaliacoes_fotos.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { const p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8135, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => {
    const u = r.request().url(), m = r.request().method();
    if (/atletas\.php/.test(u) && m === 'GET') return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idatleta: 7, nome: 'Ana Souza', email: 'a@x.com', professores_responsaveis: [1] }]) });
    return r.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  });
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); });
  await page.goto('http://127.0.0.1:8135/avaliacoes.html', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => typeof abrirModalForm === 'function', null, { timeout: 15000 });
  await page.evaluate(() => abrirModalForm());
  await page.waitForSelector('.foto-upload-area');

  // PNG 1x1 de verdade, para a miniatura carregar
  const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
  async function soltar(alvoSel, nomes) {
    return page.evaluate(({ alvoSel, nomes, PNG }) => {
      const dt = new DataTransfer();
      nomes.forEach(n => { const bin = Uint8Array.from(atob(PNG), c => c.charCodeAt(0)); dt.items.add(new File([bin], n, { type: n.endsWith('.txt') ? 'text/plain' : 'image/png', lastModified: 1000 })); });
      const alvo = document.querySelector(alvoSel);
      let padraoCancelado = false;
      ['dragenter', 'dragover', 'drop'].forEach(t => { const ev = new DragEvent(t, { bubbles: true, cancelable: true, dataTransfer: dt }); alvo.dispatchEvent(ev); if (t === 'drop') padraoCancelado = ev.defaultPrevented; });
      return padraoCancelado;
    }, { alvoSel, nomes, PNG });
  }
  const estado = () => page.evaluate(() => ({ n: fotosParaUpload.length, miniaturas: document.querySelectorAll('#fotos-preview img.foto-thumb').length, nomes: fotosParaUpload.map(f => f.name) }));

  console.log('\n══ Soltar na caixa de fotos ══');
  const cancelou = await soltar('.foto-upload-area', ['a.png', 'b.png']);
  eq('o navegador não abre a foto em outra aba (o soltar é cancelado)', cancelou, true);
  eq('as duas fotos entram na lista e aparecem como miniaturas', await estado(), { n: 2, miniaturas: 2, nomes: ['a.png', 'b.png'] });
  await soltar('.foto-upload-area', ['b.png', 'c.png']);
  eq('soltar de novo soma as novas e não repete a mesma foto', (await estado()).nomes, ['a.png', 'b.png', 'c.png']);
  await soltar('#fotos-preview', ['d.png']);
  eq('soltar em qualquer ponto do formulário também funciona', (await estado()).n, 4);
  await soltar('.foto-upload-area', ['nota.txt']);
  eq('arquivo que não é imagem é ignorado', (await estado()).n, 4);
  await page.click('#fotos-preview button >> nth=0');
  eq('o × tira uma foto da lista', (await estado()).nomes, ['b.png', 'c.png', 'd.png']);
  ver('mostra quantas fotos serão salvas', await page.evaluate(() => /3 fotos para salvar/.test(document.getElementById('fotos-preview').innerText)));

  console.log('\n══ Escolher pelo botão continua valendo ══');
  await page.setInputFiles('#foto-input', { name: 'e.png', mimeType: 'image/png', buffer: Buffer.from(PNG, 'base64') });
  eq('a foto escolhida pelo seletor soma às que já estavam', (await estado()).nomes, ['b.png', 'c.png', 'd.png', 'e.png']);

  console.log('\n══ Fora do formulário, o soltar não é interceptado ══');
  await page.evaluate(() => closeModal());
  const fora = await page.evaluate(() => { const dt = new DataTransfer(); dt.items.add(new File(['x'], 'z.png', { type: 'image/png' })); const ev = new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }); document.body.dispatchEvent(ev); return ev.defaultPrevented; });
  eq('com o formulário fechado a página não mexe no soltar', fora, false);
  await page.evaluate(() => abrirModalForm()); await page.waitForSelector('.foto-upload-area');
  eq('abrir o formulário de novo começa sem fotos escondidas', (await estado()).n, 0);
  console.log('\n══ Cadastro do aluno (alunos.html): fotos de avaliação ══');
  await page.goto('http://127.0.0.1:8135/alunos.html', { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => typeof abrirFormFotosAvaliacao === 'function', null, { timeout: 15000 });
  await page.evaluate(() => { const d = document.createElement('div'); d.id = 'mini-tab-content'; document.body.appendChild(d); abrirFormFotosAvaliacao(7); });
  const soltar2 = (alvoSel, nomes) => page.evaluate(({ alvoSel, nomes, PNG }) => {
    const dt = new DataTransfer();
    nomes.forEach(n => { const bin = Uint8Array.from(atob(PNG), c => c.charCodeAt(0)); dt.items.add(new File([bin], n, { type: 'image/png', lastModified: 1000 })); });
    let cancelado = false;
    ['dragenter', 'dragover', 'drop'].forEach(t => { const ev = new DragEvent(t, { bubbles: true, cancelable: true, dataTransfer: dt }); document.querySelector(alvoSel).dispatchEvent(ev); if (t === 'drop') cancelado = ev.defaultPrevented; });
    return cancelado;
  }, { alvoSel, nomes, PNG });
  const n2 = () => page.evaluate(() => _fotosNovas.length);
  eq('soltar dentro da caixa adiciona as fotos', [await soltar2('#fa-drop', ['a.png', 'b.png']), await n2()], [true, 2]);
  eq('soltar fora da caixa (na área das miniaturas) também adiciona, sem abrir aba', [await soltar2('#fa-previas', ['c.png']), await n2()], [true, 3]);
  eq('soltar no resto da página também adiciona', [await soltar2('#fa-status', ['d.png']), await n2()], [true, 4]);
  eq('sem erro de script no cadastro', erros, []);
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? 'FALHARAM ' + ruim : 'Tudo certo') + ' (' + ok + ' ok)');
  process.exit(ruim ? 1 : 0);
})();
