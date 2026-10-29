// Clientes (alunos.html): ordenar pela data de cadastro, principalmente na lista de leads.
// Navegador de verdade, API bloqueada, lista montada na hora. Uso: node clientes_ordem.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = process.env.INTUS_DIR || path.join(__dirname, '..', 'app', 'painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
const falhas = [];
const ok = (c, m) => { if (!c) { falhas.push(m); console.log('FALHA', m); } else console.log('ok   ', m); };
(async () => {
  const srv = http.createServer((req, res) => { const f = path.join(RAIZ, decodeURIComponent(req.url.split('?')[0])); if (!fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8128, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1400, height: 1000 }, serviceWorkers: 'block', timezoneId: 'America/Sao_Paulo' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', tipo: 'admin', admin: true })); });
  await page.goto('http://127.0.0.1:8128/alunos.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  // leads (origem app, sem professor, sem matrícula): dois no mesmo dia, um antigo, um sem data
  await page.evaluate(() => {
    const lead = (id, nome, dt, hora) => ({ idatleta: id, nome, email: nome.toLowerCase() + '@x.com', telefone: '45999' + id, dtcadastro: dt, dtcadastro_hora: hora || null, origem: 'app', professores_responsaveis: [] });
    // Bia (id menor) se cadastrou DEPOIS de Caio no mesmo dia: a hora manda, não o id. Fábio e Ana: mesmo dia sem hora, vale o id.
    atletas = [lead(11, 'Ana', '2026-10-20'), lead(12, 'Bia', '2026-10-28', '2026-10-28 14:35:10'), lead(13, 'Caio', '2026-10-28', '2026-10-28 09:05:00'), lead(14, 'Davi', '2026-09-01'), lead(15, 'Eva', null), lead(16, 'Fabio', '2026-10-20')];
    // treino do teste grátis: Bia tem ficha ativa no prazo, Caio tem ficha vencida, Ana tem inativa, Davi nenhuma
    const hoje = new Date(); const dia = n => { const d = new Date(hoje); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
    fichas = [{ idficha: 1, idatleta: 12, ativo: 1, dtinicio: dia(0), dtfim: dia(3) }, { idficha: 2, idatleta: 13, ativo: 1, dtinicio: dia(-6), dtfim: dia(-2) }, { idficha: 3, idatleta: 11, ativo: 0, dtinicio: dia(0), dtfim: dia(3) }];
    fichasOk = true;
    mensalidades = []; loading = false; erro = null; filtro = 'ativos'; ordenacao = 'alfabetica'; render();
  });
  const nomes = () => page.evaluate(() => filtrados().map(a => a.nome).join(','));
  const col = () => page.evaluate(() => [...document.querySelectorAll('table.data-table tbody tr')].map(tr => tr.children[3].textContent.trim()).join(','));
  // abrir os leads troca o A-Z padrão pelo cadastro mais recente
  await page.evaluate(() => setFiltro('leads'));
  ok(await page.evaluate(() => ordenacao) === 'cadastro', 'filtro Leads muda a ordem padrão para cadastro mais recente');
  ok(await nomes() === 'Bia,Caio,Fabio,Ana,Davi,Eva', 'mais recente primeiro: no mesmo dia a hora decide (Bia 14:35 antes de Caio 09:05), sem hora vale o id, sem data no fim: ' + await nomes());
  ok(await page.evaluate(() => document.getElementById('sel-ordem').value) === 'cadastro', 'a caixa de ordem mostra o cadastro');
  ok(await page.evaluate(() => /Cadastro: mais recente primeiro/.test(document.getElementById('sel-ordem').outerHTML)), 'o texto da opção diz que é pelo cadastro');
  ok(await col() === '28/10/2026 14:35,28/10/2026 09:05,20/10/2026,20/10/2026,01/09/2026,—', 'data e hora do cadastro na coluna, sem recuar um dia; sem hora mostra só a data: ' + await col());
  const treino = () => page.evaluate(() => [...document.querySelectorAll('table.data-table tbody tr')].map(tr => (tr.querySelector('td:nth-child(9) div') || {}).textContent || '').join('|'));
  ok(await treino() === '🏋 treino ativo|⚠ sem treino ativo|⚠ sem treino ativo|⚠ sem treino ativo|⚠ sem treino ativo|⚠ sem treino ativo', 'lead mostra se tem treino ativo no prazo (ficha vencida ou inativa conta como sem treino): ' + await treino());
  ok(await page.evaluate(() => { fichasOk = false; render(); return !document.querySelector('table.data-table tbody tr td:nth-child(9) div'); }), 'sem conseguir ler as fichas, não mostra alarme de "sem treino"');
  await page.evaluate(() => { fichasOk = true; render(); });
  // mais antigo primeiro (pelo seletor)
  await page.selectOption('#sel-ordem', 'cadastro_asc');
  ok(await nomes() === 'Davi,Ana,Fabio,Caio,Bia,Eva', 'mais antigo primeiro: ' + await nomes());
  // cabeçalho da coluna Cadastro alterna
  await page.evaluate(() => toggleSort('cadastro'));
  ok(await nomes() === 'Bia,Caio,Fabio,Ana,Davi,Eva', 'clicar em Cadastro alterna para o mais recente: ' + await nomes());
  // quem já escolheu outra ordem não é mexido ao abrir Leads
  await page.evaluate(() => { ordenacao = 'alfabetica_desc'; setFiltro('todos'); setFiltro('leads'); });
  ok(await page.evaluate(() => ordenacao) === 'alfabetica_desc', 'ordem escolhida pelo usuário é respeitada ao abrir Leads');
  // quadro: o cartão mostra a data de cadastro
  await page.evaluate(() => { ordenacao = 'cadastro'; VerModo.set ? VerModo.set('clientes', 'quadro') : localStorage.setItem('intus-vista-clientes', 'quadro'); render(); });
  await page.waitForTimeout(300);
  ok(await page.evaluate(() => /cadastro 28\/10\/2026 14:35/.test(document.getElementById('cli-quadro')?.textContent || '')), 'no quadro o cartão do lead mostra a data de cadastro');
  ok(erros.length === 0, 'sem erro de página ' + erros.join(';'));
  await b.close(); srv.close();
  console.log(falhas.length ? 'FALHAS: ' + falhas.length : 'TUDO OK');
  process.exit(falhas.length ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
