// Gera as capturas de tela das lojas a partir da interface REAL do app (aluno.html) com dados de demonstração (nada de aluno de verdade).
//   cd testes && node ../tools/gerar-capturas-lojas.js
// Saída: mobile/store-assets/capturas-ios (1290x2796) e capturas-android (1080x1920). Precisa de internet (mapa do cardio ao vivo).
const { chromium } = require('../testes/node_modules/playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(__dirname, '../app/painel');
const SAIDA = path.resolve(__dirname, '../mobile/store-assets');
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json', '.jpg': 'image/jpeg' };
const DISPOSITIVOS = [
  { pasta: 'capturas-ios', w: 430, h: 932, dpr: 3 },       // 1290x2796 (iPhone 6,7")
  { pasta: 'capturas-android', w: 360, h: 640, dpr: 3 },   // 1080x1920
];
const PLANO = () => {
  const it = (n, q, g, k, p, c, f) => ({ nome: n, quantidade: q, gramas: g, medida_id: 'g', nutrientes: { energia_kcal: k, proteina_g: p, carboidrato_g: c, lipidio_g: f } });
  return { idplano: 1, ativo: 1, titulo: 'Plano alimentar', calorias: 2200, proteina: 160, carboidrato: 240, gordura: 65, refeicoes: [
    { horario: '07:30', nome: 'Café da manhã', itens_estruturados: [it('Pão, trigo, francês', 50, 50, 150, 4.5, 29, 1.5), it('Ovo, de galinha, inteiro, cozido', 100, 100, 143, 13, 0.6, 9.5), it('Banana, prata, crua', 80, 80, 70, 0.9, 18, 0.1), it('Café, infusão', 100, 100, 9, 0.6, 1.5, 0)] },
    { horario: '12:30', nome: 'Almoço', itens_estruturados: [it('Arroz, integral, cozido', 150, 150, 185, 3.9, 38.7, 1.5), it('Feijão, carioca, cozido', 100, 100, 76, 4.8, 13.6, 0.5), it('Peito de frango sem pele grelhado', 120, 120, 190, 37, 0, 4), it('Alface, americana, crua', 50, 50, 8, 0.6, 1.5, 0.1)] },
    { horario: '16:30', nome: 'Lanche da tarde', itens_estruturados: [it('Iogurte natural', 170, 170, 105, 7, 8, 5), it('Aveia em flocos', 30, 30, 114, 4.2, 20, 2.1)] },
    { horario: '20:00', nome: 'Jantar', itens_estruturados: [it('Salmão grelhado', 130, 130, 250, 28, 0, 15), it('Batata doce cozida', 150, 150, 115, 1.5, 27, 0.2)] }] };
};
const TELAS = [
  { nome: '01-inicio', espera: 3500, fn: async (page) => { await page.evaluate(() => { navegar('home'); setTimeout(() => { Array.from(document.querySelectorAll('#v-home *')).filter(e => e.children.length === 0 && /não consegui carregar os posts/i.test(e.textContent || '')).forEach(e => { e.style.display = 'none'; }); }, 2500); }); } },
  { nome: '02-treino', espera: 5800, fn: async (page) => { await page.evaluate(() => selecionarTreino(5, 'A')); } },
  { nome: '03-programas-cardio', espera: 900, fn: async (page) => { await page.evaluate(() => { window._progPermitido = () => true; _prog.tela = 'lista'; _renderProgLista(); trocarView('cardio-prog'); }); } },
  { nome: '04-nutricao', espera: 1500, fn: async (page) => {
    await page.evaluate((plano) => {
      const hoje = API.nutri.hojeIso();
      API.listarPlanosNutricionais = async () => [plano];
      window._nutriGet = async (q) => /nutri_registro/.test(q) ? { registros: [{ data: hoje, refeicao: '07:30|café da manhã', status: 'feito' }], agua: [{ data: hoje, agua_ml: 1500 }] } : null;
      navegar('nutricao');
    }, PLANO());
    await page.waitForTimeout(1800);
    await page.evaluate(() => { nutriTela('plano'); });
    await page.waitForTimeout(700);
    await page.evaluate(() => { _nutri.visao = 'hoje'; _nutri.aberta = '12:30|almoço'; _nutriRender(); });
  } },
  { nome: '05-cardio-ao-vivo', espera: 13000, fn: async (page) => {
    await page.evaluate(() => {
      window._cardioLivePlugin = () => ({});
      _cardioLiveTipo = 'Corrida'; _cardioLiveParado = false; _cardioLiveInicioMs = Date.now() - 18 * 60000 - 24000; _cardioLiveRodando = true; _cardioLiveNativoOk = true;
      const base = { lat: -26.9186, lng: -49.0658 }; _cardioLivePontos.length = 0; _cardioLiveDistM = 3140;
      for (let i = 0; i <= 40; i++) { const a = i / 40; _cardioLivePontos.push({ lat: base.lat + a * 0.012, lng: base.lng + Math.sin(a * 5) * 0.003 + a * 0.004, t: Date.now() - (40 - i) * 27000, alt: 28 + Math.sin(a * 4) * 12 + a * 30 }); }
      const u = _cardioLivePontos[_cardioLivePontos.length - 1]; _cardioLiveUltimaPos = { lat: u.lat, lng: u.lng, t: u.t, acc: 5 };
      _cardioLiveRumo = 20; _renderCardioLive(); trocarView('cardio-live');
    });
  } },
];
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch({ args: ['--use-gl=swiftshader', '--enable-webgl', '--ignore-gpu-blocklist'] });
  for (const d of DISPOSITIVOS) {
    fs.mkdirSync(path.join(SAIDA, d.pasta), { recursive: true });
    for (const tela of TELAS) {
      const ctx = await b.newContext({ viewport: { width: d.w, height: d.h }, deviceScaleFactor: d.dpr, serviceWorkers: 'block', locale: 'pt-BR', hasTouch: true, isMobile: true });   // contexto novo: nada de treino ou cardio de outra tela
      const page = await ctx.newPage();
      await page.route(/\/app\/api\//, r => r.abort('failed'));
      await page.addInitScript(() => {
        localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluno Demonstração', genero: 'M' }));
        localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-ajuda-v2', JSON.stringify({ visto: 1 })); localStorage.setItem('intus-nutri-plano-7', '1');
        const fim = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
        localStorage.setItem('intus-fichas', JSON.stringify([{ idficha: 5, idatleta: 7, nmficha: 'Treino ABC', ativo: 1, dtinicio: '2026-10-01', dtvalidade: fim }]));
        const ex = (id, div, nome, s, r) => ({ idtreino: id, idficha: 5, divisao: div, idexercicio: id, nmexercicio: nome, qtdserie: s, repeticoes: r, intervalo: '01:00' });
        localStorage.setItem('intus-treinos', JSON.stringify([ex(11, 'A', 'Supino reto com barra', 4, '10'), ex(12, 'A', 'Supino inclinado com halteres', 3, '12'), ex(13, 'A', 'Crucifixo na polia', 3, '12'), ex(14, 'A', 'Tríceps testa', 3, '12'), ex(21, 'B', 'Puxada frontal', 4, '10'), ex(22, 'B', 'Remada curvada', 3, '12'), ex(31, 'C', 'Agachamento livre', 4, '10'), ex(32, 'C', 'Leg press 45', 3, '12')]));
        localStorage.setItem('intus-mensalidades', JSON.stringify([{ idmensalidade: 1, idatleta: 7, plano: 'Consultoria Premium', dtinicio: '2026-11-01', dtvencimento: fim, stmatricula: 'ativa', status: 'paga' }]));
      });
      await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
      for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
      await page.waitForTimeout(1500);
      await tela.fn(page);
      await page.waitForTimeout(tela.espera);
      await page.screenshot({ path: path.join(SAIDA, d.pasta, tela.nome + '.png') });
      console.log(d.pasta + '/' + tela.nome);
      await ctx.close();
    }
  }
  await b.close(); srv.close();
})().catch(e => { console.error(e); process.exit(1); });
