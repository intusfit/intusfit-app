// Analytics de captação, lado do navegador: o rastreador (trafego.js) nas páginas públicas e a tela do painel (trafego.html).
//   cd testes && INTUS_DIR=../app/painel node trafego_front.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const SITE = path.resolve(process.env.INTUS_DIR || '../app/painel', '../..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p.endsWith('/')) p += 'index.html';
    const f = path.join(SITE, p);
    if (!f.startsWith(SITE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8096, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8096';
  const browser = await chromium.launch();

  // ── rastreador ──
  async function novaPagina(ctx, opts = {}) {
    const page = await ctx.newPage();
    const envios = [], erros = [];
    page.on('pageerror', e => erros.push(String(e.message)));
    await page.route('**/trafego.php**', route => {
      const req = route.request(), u = new URL(req.url());
      let corpo = {}; try { corpo = JSON.parse(req.postData() || '{}'); } catch (e) {}
      envios.push({ acao: u.searchParams.get('action'), corpo });
      if (opts.cair) return route.abort('failed');
      route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
    });
    await page.route('**/app/api/aluno_login.php**', route => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, atleta: { idatleta: 321, nome: 'Teste', email: 't@t.com', server_token: 'tok' } }) }));
    await page.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    return { page, envios, erros };
  }
  console.log('\n══ Rastreador nas páginas públicas ══');
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
  let { page, envios, erros } = await novaPagina(ctx);
  await page.goto(B + '/teste-gratis.html?utm_source=instagram&utm_medium=stories&utm_campaign=Outubro&utm_content=story-a5&ref=42', { waitUntil: 'load' });
  await page.waitForTimeout(700);
  const v1 = envios.find(e => e.acao === 'visita');
  ver('envia a visita ao abrir a página', v1, envios);
  eq('com UTM e indicação da URL', [v1.corpo.utm_source, v1.corpo.utm_medium, v1.corpo.utm_campaign, v1.corpo.utm_content, v1.corpo.ref], ['instagram', 'stories', 'Outubro', 'story-a5', '42']);
  eq('na página certa, com os identificadores anônimos', [v1.corpo.pagina, /^[a-z0-9]{8,40}$/.test(v1.corpo.vid), /^[a-z0-9]{8,40}$/.test(v1.corpo.sid), v1.corpo.w], ['/teste-gratis.html', true, true, 390]);
  eq('NÃO manda nome, e-mail, telefone nem nada pessoal', Object.keys(v1.corpo).filter(k => !['vid', 'sid', 'pagina', 'referrer', 'ref', 'w', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].includes(k)), []);
  // funil: preencher o formulário
  await page.click('#lead-nome'); await page.click('#lead-whats');
  await page.fill('#lead-nome', 'Maria Teste'); await page.fill('#lead-whats', '45999999999'); await page.fill('#lead-email', 'maria@teste.com');
  await page.check('input[name="genero"] >> nth=0', { force: true }).catch(() => {});
  await page.click('#btn-continuar');
  await page.waitForTimeout(200);
  await page.fill('#lead-senha', 'segredo123'); await page.fill('#lead-senha-confirma', 'segredo123');
  await page.click('#btn-enviar');
  await page.waitForTimeout(800);
  const evs = envios.filter(e => e.acao === 'evento').map(e => e.corpo.evento);
  eq('começar o formulário vale uma vez só', evs.filter(x => x === 'form_inicio').length, 1);
  ver('passar da 1ª etapa é registrado', evs.includes('etapa1_ok'), evs);
  const conta = envios.find(e => e.corpo.evento === 'conta_criada');
  ver('criar o acesso grátis é registrado, com o id e o rótulo "indicado"', conta && conta.corpo.valor === 321 && conta.corpo.rotulo === 'indicado', conta);
  eq('nenhum dado digitado vai para a medição', JSON.stringify(envios).includes('maria@teste.com') || JSON.stringify(envios).includes('segredo123') || JSON.stringify(envios).includes('45999999999'), false);
  await page.evaluate(() => { document.getElementById('link-whats').addEventListener('click', e => e.preventDefault(), true); });
  await page.click('#link-whats');
  await page.waitForTimeout(300);
  const zap = envios.find(e => e.corpo.evento === 'whatsapp' && /Maria Teste|teste gr/i.test(e.corpo.rotulo || ''));
  ver('clique no WhatsApp registra o texto da mensagem', zap, envios.map(e => e.corpo));
  eq('sem erro de script na página', erros, []);

  console.log('\n══ Mesma pessoa em outra página ══');
  envios.length = 0;
  await page.goto(B + '/index.html', { waitUntil: 'load' });
  await page.waitForTimeout(600);
  const v2 = envios.find(e => e.acao === 'visita');
  eq('mesmo visitante e mesma sessão', [v2.corpo.vid === v1.corpo.vid, v2.corpo.sid === v1.corpo.sid], [true, true]);
  eq('a indicação continua valendo por 30 dias e o UTM durante a sessão', [v2.corpo.ref, v2.corpo.utm_campaign], ['42', 'Outubro']);
  eq('manda o caminho cru (o servidor trata /index.html como "/")', v2.corpo.pagina, '/index.html');
  await page.evaluate(() => document.querySelector('a[href*="wa.me"]').addEventListener('click', e => e.preventDefault(), true));
  await page.click('a[href*="wa.me"] >> nth=0', { force: true });
  await page.waitForTimeout(300);
  const z2 = envios.find(e => e.corpo.evento === 'whatsapp');
  ver('clique em plano no site registra o texto do botão', z2 && /contratar|saber mais/i.test(z2.corpo.rotulo), z2);
  const outraAba = await novaPagina(ctx);
  await outraAba.page.goto(B + '/index.html', { waitUntil: 'load' }); await outraAba.page.waitForTimeout(500);
  const v3 = outraAba.envios.find(e => e.acao === 'visita');
  eq('aba nova = sessão nova do mesmo visitante, sem o UTM antigo e com a indicação', [v3.corpo.vid === v1.corpo.vid, v3.corpo.sid !== v1.corpo.sid, v3.corpo.utm_campaign || null, v3.corpo.ref], [true, true, null, '42']);

  console.log('\n══ Equipe logada e falha do servidor ══');
  const ctxEq = await browser.newContext();
  await ctxEq.addInitScript(() => localStorage.setItem('mx-user', JSON.stringify({ tipo: 'professor', idusuario: 3, admin: 0 })));
  const pe = await novaPagina(ctxEq);
  await pe.page.goto(B + '/teste-gratis.html', { waitUntil: 'load' }); await pe.page.waitForTimeout(500);
  eq('quem está logado no painel não é contado', pe.envios.length, 0);
  const ctxCai = await browser.newContext();
  const pc = await novaPagina(ctxCai, { cair: true });
  await pc.page.goto(B + '/teste-gratis.html', { waitUntil: 'load' }); await pc.page.waitForTimeout(500);
  await pc.page.click('#lead-nome');
  eq('servidor de medição fora do ar não derruba a página', [pc.erros, await pc.page.evaluate(() => !!document.getElementById('form-lead'))], [[], true]);

  // ── tela do painel ──
  console.log('\n══ Tela do painel (trafego.html) ══');
  const relatorio = {
    periodo: { de: '2026-09-20', ate: '2026-10-19', dias: 30, anterior_de: '2026-08-21', anterior_ate: '2026-09-19', pagina: null },
    kpis: { visitas: 1200, visitantes: 800, sessoes: 900, novos: 600, conversoes: 40, cliques: 150, taxa: 5.0 },
    kpis_anterior: { visitas: 1000, visitantes: 700, sessoes: 800, novos: 500, conversoes: 20, cliques: 100, taxa: 2.9 },
    serie: Array.from({ length: 30 }, (_, i) => ({ dia: '2026-09-' + String(20 + i > 30 ? 30 : 20 + i).padStart(2, '0'), visitas: 30 + i, visitantes: 20 + i, conversoes: i % 5 })),
    dia_semana: [10, 20, 30, 40, 50, 60, 70], horas: Array.from({ length: 24 }, (_, i) => i * 3),
    paginas: [{ pagina: '/teste-gratis.html', visitas: 700, visitantes: 500, cliques: 90, conversoes: 40, taxa: 8.0 }, { pagina: '/', visitas: 500, visitantes: 400, cliques: 60, conversoes: 0, taxa: 0 }],
    canais: [{ canal: 'instagram', visitas: 500, visitantes: 400, conversoes: 25, taxa: 6.3 }, { canal: 'indicacao', visitas: 100, visitantes: 80, conversoes: 10, taxa: 12.5 }],
    campanhas: [{ utm_source: 'instagram', utm_medium: 'stories', utm_campaign: '<img src=x onerror=window.__xss=1>', utm_content: 'story-a5', visitas: 300, visitantes: 250, conversoes: 15, taxa: 6.0 }],
    sites_origem: [{ host: 'l.instagram.com', visitas: 300, visitantes: 250 }],
    indicacoes: [{ ref_idatleta: 42, nome: 'Aluna Exemplo', visitas: 50, visitantes: 40, conversoes: 6 }],
    funil: [{ etapa: 'visita', rotulo: 'Visitaram a página', n: 500 }, { etapa: 'form_inicio', rotulo: 'Começaram a preencher', n: 120 }, { etapa: 'etapa1_ok', rotulo: 'Passaram da 1ª etapa', n: 80 }, { etapa: 'conta_criada', rotulo: 'Criaram o acesso grátis', n: 40 }],
    eventos: [{ evento: 'whatsapp', rotulo: 'Quero contratar o plano de Treino', n: 30, visitantes: 25 }],
    aparelhos: [{ nome: 'celular', visitantes: 700 }], navegadores: [{ nome: 'Instagram', visitantes: 300 }], sistemas: [{ nome: 'iOS', visitantes: 400 }],
    paises: [{ nome: 'BR', visitantes: 790 }], cidades: [{ nome: 'Cascavel - PR', visitantes: 200 }],
    agora: { ativos: 3, ultimas: [{ criado_em: '2026-10-19 15:00:00', pagina: '/', canal: 'instagram', utm_campaign: null, ref_idatleta: null, aparelho: 'celular', cidade: 'Cascavel', regiao: 'PR', novo: 1 }] },
    banco: { leads: 12, leads_por_origem: [{ origem: 'landing-teste-gratis', n: 12 }], leads_indicados: 3 }, gerado_em: '2026-10-19 15:00:00',
  };
  async function painel(usuario) {
    const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    await c.addInitScript((u) => { localStorage.setItem('mx-token', 'teste'); localStorage.setItem('mx-user', JSON.stringify(u)); }, usuario);
    const pg = await c.newPage(); const er = []; pg.on('pageerror', e => er.push(String(e.message)));
    const chamadas = [];
    await pg.route('**/trafego.php**', r => { chamadas.push(r.request().url()); r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(r.request().url().includes('action=agora') ? { agora: relatorio.agora, hoje: relatorio.kpis } : relatorio) }); });
    await pg.route(/\/app\/api\/(?!trafego\.php)/, r => r.abort('failed'));
    await pg.goto(B + '/app/painel/trafego.html', { waitUntil: 'load' });
    await pg.waitForTimeout(1500);
    return { pg, er, chamadas, c };
  }
  const adm = await painel({ tipo: 'admin', idusuario: 1, admin: 1, nome: 'Luiz Nunes', permissoes: {} });
  const texto = await adm.pg.evaluate(() => document.getElementById('main-content').innerText);
  ver('mostra os números principais', /800/.test(texto) && /1\.200/.test(texto) && /pessoas captadas/i.test(texto), texto.slice(0, 300));
  ver('compara com o período anterior', /vs período anterior/.test(texto));
  ver('mostra o funil, as páginas, as indicações e as campanhas', /funil do teste grátis/i.test(texto) && /teste grátis/i.test(texto) && /Aluna Exemplo/.test(texto) && /story-a5/.test(texto));
  eq('texto vindo da internet nunca vira HTML (campanha maliciosa)', await adm.pg.evaluate(() => window.__xss || null), null);
  const link = await adm.pg.evaluate(() => { document.getElementById('g-camp').value = 'Teste Grátis Outubro'; document.getElementById('g-cont').value = 'Story A5'; gerarLink(); return document.getElementById('g-url').textContent; });
  eq('gerador de link: UTM correto e sem acento ou espaço', link, 'https://intusfit.com.br/teste-gratis.html?utm_source=instagram&utm_medium=stories&utm_campaign=teste-gratis-outubro&utm_content=story-a5');
  await adm.pg.click('.tf-chip >> text=7 dias'); await adm.pg.waitForTimeout(400);
  ver('trocar o período pede o relatório de novo com as datas', adm.chamadas.some(u => /action=relatorio&de=\d{4}-\d{2}-\d{2}&ate=\d{4}-\d{2}-\d{2}/.test(u)) && adm.chamadas.length >= 2, adm.chamadas);
  eq('sem erro de script', adm.er, []);
  const prof = await painel({ tipo: 'professor', idusuario: 9, admin: 0, nome: 'Prof', permissoes: {} });
  const negado = await prof.pg.evaluate(() => document.getElementById('main-content').innerText);
  ver('professor vê "Acesso restrito" e nenhuma chamada de dados', /Acesso restrito/.test(negado) && prof.chamadas.length === 0, [negado.slice(0, 80), prof.chamadas]);
  const menuAdm = await adm.pg.evaluate(() => [...document.querySelectorAll('a')].some(a => /trafego\.html/.test(a.getAttribute('href') || '')));
  const menuProf = await prof.pg.evaluate(() => [...document.querySelectorAll('a')].some(a => /trafego\.html/.test(a.getAttribute('href') || '')));
  eq('o item do menu só aparece para o administrador', [menuAdm, menuProf], [true, false]);

  await browser.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
