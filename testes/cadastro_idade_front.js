// Idade minima e aceite dos Termos nos dois formularios de cadastro (teste-gratis.html e app/painel/login.html), num Chromium com servidor de mentira.
//   cd testes && node cadastro_idade_front.js          (INTUS_RAIZ aponta para a raiz do site; usado nas quebras de proposito)
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_RAIZ || path.join(__dirname, '..'));
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon', '.webp': 'image/webp' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };

// Datas relativas a hoje (o relogio do teste e o do navegador).
function dataAnos(anos, diasExtra = 0) {
  const d = new Date(); d.setFullYear(d.getFullYear() - anos); d.setDate(d.getDate() + diasExtra);
  const z = n => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate());
}
const ATLETA = { idatleta: 9, nome: 'Aluna Teste', email: 'a@t.com', telefone: '45999', genero: 'F', server_token: 'b'.repeat(64) };

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/index.html';
    const f = path.join(RAIZ, p);
    if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8097, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8097';
  const browser = await chromium.launch();

  async function nova(servidor) {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const criar = [], leads = [], erros = [], dialogos = [];
    page.on('pageerror', e => { if (!/Failed to update a ServiceWorker/i.test(String(e.message))) erros.push(String(e.message)); });
    page.on('dialog', d => { dialogos.push(d.message()); d.accept(); });
    await page.route(/\/(app\/api|api)\//, route => {
      const req = route.request(), u = req.url();
      const json = (status, corpo) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(corpo) });
      if (/aluno_login\.php\?action=criar/.test(u)) { let corpo = {}; try { corpo = JSON.parse(req.postData() || '{}'); } catch (e) {} criar.push(corpo); return servidor(json, corpo); }
      if (/leads\.php\?action=criar/.test(u)) { leads.push(req.postData()); return json(200, { ok: true, id: 3 }); }
      return json(200, { ok: true });
    });
    return { ctx, page, criar, leads, erros, dialogos };
  }
  const aceita = json => json(200, { ok: true, atleta: ATLETA });

  // ── Landing do teste gratis ──
  async function preencherEtapa1(page, nasc) {
    await page.goto(B + '/teste-gratis.html', { waitUntil: 'domcontentloaded' });
    await page.fill('#lead-nome', 'Maria Teste');
    await page.fill('#lead-whats', '45999112233');
    await page.fill('#lead-email', 'maria@teste.com');
    await page.check('input[name="genero"][value="F"]', { force: true });
    if (nasc) await page.fill('#lead-nasc', nasc);
    await page.click('#btn-continuar');
  }
  const aviso1 = page => page.evaluate(() => { const e = document.getElementById('aviso-erro-1'); return e.classList.contains('on') ? e.textContent : ''; });
  const aviso2 = page => page.evaluate(() => { const e = document.getElementById('aviso-erro'); return e.classList.contains('on') ? e.textContent : ''; });
  const etapa2Visivel = page => page.evaluate(() => getComputedStyle(document.getElementById('step-2')).display !== 'none');

  console.log('\n══ Landing: etapa 1 ══');
  let t = await nova(aceita);
  await preencherEtapa1(t.page, '');
  ver('sem data de nascimento: pede a data e nao avanca', /data de nascimento/i.test(await aviso1(t.page)) && !(await etapa2Visivel(t.page)));
  await t.ctx.close();

  t = await nova(aceita);
  await preencherEtapa1(t.page, dataAnos(17));
  const m17 = await aviso1(t.page);
  ver('17 anos: avisa que e so para maiores de 18, indica o responsavel e nao avanca', /18 anos/.test(m17) && /respons/i.test(m17) && !(await etapa2Visivel(t.page)), m17);
  eq('17 anos: nada vai ao servidor (nem conta, nem lead)', [t.criar.length, t.leads.length], [0, 0]);
  await t.ctx.close();

  t = await nova(aceita);
  await preencherEtapa1(t.page, dataAnos(18, 1));
  ver('falta 1 dia para os 18: nao avanca', !(await etapa2Visivel(t.page)));
  await t.ctx.close();

  t = await nova(aceita);
  await preencherEtapa1(t.page, dataAnos(18));
  ver('faz 18 anos hoje: avanca', await etapa2Visivel(t.page), await aviso1(t.page));
  await t.ctx.close();

  console.log('\n══ Landing: etapa 2, aceite e envio ══');
  t = await nova(aceita);
  await preencherEtapa1(t.page, '1990-05-20');
  await t.page.fill('#lead-senha', 'senha123'); await t.page.fill('#lead-senha-confirma', 'senha123');
  await t.page.click('#btn-enviar');
  ver('sem marcar o aceite: pede o aceite e nao cria a conta', /Termos/.test(await aviso2(t.page)) && t.criar.length === 0, await aviso2(t.page));
  const links = await t.page.evaluate(() => [...document.querySelectorAll('.campo.aceite a')].map(a => [a.getAttribute('href'), a.target]));
  eq('os Termos e a Privacidade abrem em outra aba', links, [['/app/painel/termos.html', '_blank'], ['/app/painel/privacidade.html', '_blank']]);
  await t.page.check('#lead-aceite');
  await t.page.click('#btn-enviar');
  await t.page.waitForFunction(() => document.getElementById('confirmacao').classList.contains('on'), null, { timeout: 5000 }).catch(() => {});
  ver('com o aceite: a conta e criada uma vez, com a data de nascimento e o aceite', t.criar.length === 1 && t.criar[0].dtnascimento === '1990-05-20' && t.criar[0].aceite_termos === true, t.criar);
  eq('com o aceite: nenhum lead de reserva', t.leads.length, 0);
  eq('sem erro de script', t.erros, []);
  await t.ctx.close();

  console.log('\n══ Landing: servidor recusa ══');
  t = await nova(json => json(403, { ok: false, codigo: 'idade_minima', erro: 'O cadastro direto e so para maiores de 18 anos.', idade_minima: 18 }));
  await preencherEtapa1(t.page, '1990-05-20');
  await t.page.fill('#lead-senha', 'senha123'); await t.page.fill('#lead-senha-confirma', 'senha123'); await t.page.check('#lead-aceite');
  await t.page.click('#btn-enviar');
  await t.page.waitForTimeout(700);
  const recusa = await aviso2(t.page);
  ver('recusa por idade vinda do servidor: mostra o aviso para maiores de 18', /18 anos/.test(recusa), recusa);
  eq('recusa por idade: o contato NAO vira lead de reserva e o botao volta a funcionar', [t.leads.length, await t.page.evaluate(() => document.getElementById('btn-enviar').disabled)], [0, false]);
  await t.ctx.close();

  t = await nova(json => json(500, { ok: false, erro: 'erro interno' }));
  await preencherEtapa1(t.page, '1990-05-20');
  await t.page.fill('#lead-senha', 'senha123'); await t.page.fill('#lead-senha-confirma', 'senha123'); await t.page.check('#lead-aceite');
  await t.page.click('#btn-enviar');
  await t.page.waitForTimeout(900);
  eq('falha do servidor (500): continua guardando o contato como lead (o plano B de antes)', t.leads.length, 1);
  await t.ctx.close();

  // ── Cadastro do app (login.html) ──
  async function abrirCadastro(page) {
    await page.goto(B + '/app/painel/login.html', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#btn-criar-conta', { state: 'visible' });
    await page.click('#btn-criar-conta');
    await page.waitForSelector('#nc-nome', { state: 'visible' });
    await page.fill('#nc-nome', 'Maria Teste'); await page.fill('#nc-email', 'maria@teste.com');
  }
  const etapaAtual = page => page.evaluate(() => ncCurrentStep);

  console.log('\n══ App: cadastro em etapas ══');
  t = await nova(aceita);
  await abrirCadastro(t.page);
  await t.page.click('#nc-step-1 .submit-btn');
  eq('sem data de nascimento: alerta e continua na etapa 1', [/data de nascimento/i.test(t.dialogos.join('|')), await etapaAtual(t.page)], [true, 1]);
  await t.ctx.close();

  t = await nova(aceita);
  await abrirCadastro(t.page);
  await t.page.fill('#nc-dtnascimento', dataAnos(17));
  await t.page.click('#nc-step-1 .submit-btn');
  eq('17 anos: alerta de maiores de 18 e continua na etapa 1', [/18 anos/.test(t.dialogos.join('|')), await etapaAtual(t.page)], [true, 1]);
  await t.ctx.close();

  t = await nova(aceita);
  await abrirCadastro(t.page);
  await t.page.fill('#nc-dtnascimento', dataAnos(18));
  await t.page.click('#nc-step-1 .submit-btn');
  eq('18 anos hoje: avanca para a etapa 2', [t.dialogos.length, await etapaAtual(t.page)], [0, 2]);
  await t.ctx.close();

  console.log('\n══ App: aceite e envio ══');
  async function ateOFim(t, nasc) {
    await abrirCadastro(t.page);
    await t.page.fill('#nc-dtnascimento', nasc);
    await t.page.evaluate(() => { ncNextStep(2); ncNextStep(3); ncNextStep(4); });
    await t.page.fill('#nc-senha', 'senha123'); await t.page.fill('#nc-senha-confirm', 'senha123');
  }
  t = await nova(aceita);
  await ateOFim(t, '1990-05-20');
  await t.page.click('#nc-salvar');
  await t.page.waitForTimeout(400);
  eq('sem o aceite: alerta e a conta nao e criada', [/Termos de Uso/.test(t.dialogos.join('|')), t.criar.length], [true, 0]);
  const linksApp = await t.page.evaluate(() => [...document.querySelectorAll('#nc-aceite ~ span a')].map(a => [a.getAttribute('href'), a.target]));
  eq('os Termos e a Privacidade abrem em outra aba', linksApp, [['termos.html', '_blank'], ['privacidade.html', '_blank']]);
  await t.page.check('#nc-aceite');
  await t.page.click('#nc-salvar');
  await t.page.waitForTimeout(700);
  ver('com o aceite: cria a conta com a data de nascimento e o aceite', t.criar.length === 1 && t.criar[0].dtnascimento === '1990-05-20' && t.criar[0].aceite_termos === true, t.criar);
  eq('sem erro de script', t.erros, []);
  await t.ctx.close();

  t = await nova(json => json(403, { ok: false, codigo: 'idade_minima', erro: 'x', idade_minima: 18 }));
  await ateOFim(t, '1990-05-20');
  await t.page.check('#nc-aceite');
  await t.page.click('#nc-salvar');
  await t.page.waitForTimeout(700);
  eq('recusa por idade vinda do servidor: mostra o aviso de maiores de 18 e o botao volta', [/18 anos/.test(t.dialogos.join('|')), await t.page.evaluate(() => document.getElementById('nc-salvar').disabled)], [true, false]);
  await t.ctx.close();

  await browser.close(); srv.close();
  console.log('\n' + (ruim ? 'FALHARAM ' + ruim + ' de ' + (ok + ruim) : 'Todas as ' + ok + ' conferencias passaram'));
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
