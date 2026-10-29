// Treino em andamento que o app perdeu ao ser reaberto (caso da Rosângela, 29/10/2026: treino de 1h14 terminou com 0:36).
// O iPhone descarta a página em segundo plano; o treino fica guardado no aparelho (intus-treino-ativo). Se o aluno volta
// depois de mais de 45 min a retomada automática não entra sozinha, e escolher o MESMO treino na lista abria uma sessão nova
// (relógio zerado e séries marcadas apagadas). Agora a escolha do mesmo treino retoma o que estava guardado, e trocar por
// outro treino com progresso pede confirmação.
//   cd testes && INTUS_DIR=../app/painel node treino_retomar.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8131, '127.0.0.1', r));
  const b = await chromium.launch();

  // Abre o app com um treino A guardado: começou há 74 min, a última série foi marcada há `salvoHaMin` min.
  async function abrir(salvoHaMin, divisaoSalva) {
    const ctx = await b.newContext({ viewport: { width: 375, height: 900 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    page.on('dialog', d => { page._dialogos = (page._dialogos || 0) + 1; d.dismiss(); });
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.addInitScript(([salvoHa, divSalva]) => {
      const agora = Date.now(), inicio = agora - 74 * 60000;
      localStorage.setItem('mx-token', 't');
      localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
      localStorage.setItem('intus-termos-aceito-7', '1');
      localStorage.setItem('intus-fichas', JSON.stringify([{ idficha: 5, idatleta: 7, nmficha: 'Treino ABC', ativo: 1 }]));
      localStorage.setItem('intus-treinos', JSON.stringify([
        { idtreino: 11, idficha: 5, divisao: 'A', idexercicio: 1, nmexercicio: 'Agachamento', qtdserie: 3, repeticoes: '12', intervalo: '01:00' },
        { idtreino: 12, idficha: 5, divisao: 'A', idexercicio: 2, nmexercicio: 'Afundo', qtdserie: 3, repeticoes: '12', intervalo: '01:00' },
        { idtreino: 21, idficha: 5, divisao: 'B', idexercicio: 3, nmexercicio: 'Remada', qtdserie: 3, repeticoes: '12', intervalo: '01:00' },
      ]));
      const exs = [{ idtreino: 11, idficha: 5, divisao: divSalva, idexercicio: 1, nmexercicio: 'Agachamento', qtdserie: 3 }];
      const serie = (feita) => ({ peso: feita ? '40' : '', reps: feita ? '12' : '', repTarget: '12', intervalo: '01:00', tecnica: '', feita, ts: feita ? inicio + 5 * 60000 : undefined });
      localStorage.setItem('intus-treino-ativo', JSON.stringify({
        sessao: { idficha: 5, divisao: divSalva, divisaoLabel: 'Divisão ' + divSalva, nomeFicha: 'Treino ABC', exercicios: exs, alongamentos: [{ nome: 'Peitoral', tempo: '30s', divisao: divSalva }], dados: { 11: [serie(true), serie(true), serie(false)] }, inicio: inicio, comentario: '', activeIdx: 0 },
        treinoStart: inicio, emMusculacao: true, ultimaAtividade: agora - salvoHa * 60000, salvoEm: agora - salvoHa * 60000,
      }));
    }, [salvoHaMin, divisaoSalva]);
    await page.goto('http://127.0.0.1:8131/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(800);
    return { page, erros, ctx };
  }
  const estado = (page) => page.evaluate(() => ({
    rodando: !!treinoTimer,
    decorridoMin: Math.round((Date.now() - (_treinoStartSaved || treinoStart || 0)) / 60000),
    divisao: sessaoAtual.divisao,
    feitas: Object.values(sessaoAtual.dados || {}).reduce((t, arr) => t + arr.filter(s => s.feita).length, 0),
    tela: window._viewAtual,
  }));

  console.log('\n══ Reabriu o app com mais de 45 min: a retomada automática não entra, o banner espera ══');
  let { page, erros, ctx } = await abrir(50, 'A');
  let e = await estado(page);
  eq('o app abriu na Início, sem treino rodando (passou de 45 min)', [e.rodando, !e.tela || e.tela === 'home'], [false, true]);
  ver('o banner "treino em andamento" aparece', await page.evaluate(() => getComputedStyle(document.getElementById('treino-banner')).display !== 'none'));

  console.log('\n══ Escolher o MESMO treino na lista retoma o que estava guardado ══');
  await page.evaluate(() => selecionarTreino(5, 'A'));
  await page.waitForTimeout(500);
  e = await estado(page);
  ver('o relógio continua em cerca de 74 min (e não volta a zero)', e.decorridoMin >= 73 && e.decorridoMin <= 75, e);
  eq('as 2 séries já marcadas continuam marcadas', e.feitas, 2);
  eq('o cronômetro está rodando na tela de musculação', [e.rodando, e.tela], [true, 'muscu']);
  const salvo = await page.evaluate(() => { const s = JSON.parse(localStorage.getItem('intus-treino-ativo')); return Math.round((Date.now() - s.treinoStart) / 60000); });
  ver('o que fica guardado no aparelho também mantém o início original', salvo >= 73 && salvo <= 75, salvo);
  const dur = await page.evaluate(() => Math.round((Date.now() - (_treinoStartSaved || treinoStart)) / 1000));
  ver('a duração que seria gravada ao finalizar passa de 1 h (era 0:36)', dur > 4300, dur);
  eq('sem erro de script', erros, []);
  await ctx.close();

  console.log('\n══ Trocar por OUTRO treino com progresso guardado pede confirmação ══');
  ({ page, erros, ctx } = await abrir(50, 'A'));
  await page.evaluate(() => selecionarTreino(5, 'B'));
  await page.waitForTimeout(400);
  eq('abriu a pergunta (dismiss = cancelou)', page._dialogos || 0, 1);
  e = await estado(page);
  eq('cancelando, o treino A guardado não foi apagado', [e.rodando, JSON.parse(await page.evaluate(() => localStorage.getItem('intus-treino-ativo'))).sessao.divisao], [false, 'A']);
  await ctx.close();

  console.log('\n══ Treino rodando: voltar à tela de alongamentos e tocar em "Iniciar Musculação" de novo não zera o relógio ══');
  ({ page, erros, ctx } = await abrir(5, 'A'));
  e = await estado(page);
  ver('a retomada automática entrou (5 min) e o relógio está em cerca de 74 min', e.rodando && e.decorridoMin >= 73 && e.decorridoMin <= 75, e);
  await page.evaluate(() => { trocarView('along'); renderAlongamentos(); iniciarMusculacao(); });
  await page.waitForTimeout(6500); // passaria a contagem 3, 2, 1 e o relógio recomeçaria
  e = await estado(page);
  ver('o relógio continua em cerca de 74 min (antes voltava a zero)', e.decorridoMin >= 73 && e.decorridoMin <= 76, e);
  eq('as séries continuam marcadas e a tela é a da musculação', [e.feitas, e.tela], [2, 'muscu']);
  eq('sem contagem 3, 2, 1 na tela', await page.evaluate(() => document.getElementById('countdown-overlay').classList.contains('open')), false);
  eq('sem erro de script', erros, []);

  console.log('\n══ Rede de segurança: a duração nunca é menor que o tempo desde a 1ª série marcada ══');
  await page.evaluate(() => { _treinoStartSaved = Date.now() - 36000; treinoStart = _treinoStartSaved; });   // relógio reiniciado há 36 s, como no caso da Rosângela
  const seg = await page.evaluate(() => Math.round((Date.now() - _treinoInicioMs()) / 1000));
  ver('mesmo com o relógio reiniciado, a duração sai de cerca de 69 min (1ª série marcada), e não de 36 s', seg >= 4100 && seg <= 4200, seg);
  await page.evaluate(() => { Object.values(sessaoAtual.dados).forEach(a => a.forEach(x => { delete x.ts; })); });
  const seg2 = await page.evaluate(() => Math.round((Date.now() - _treinoInicioMs()) / 1000));
  ver('sem horário de série, vale o relógio (comportamento de sempre)', seg2 >= 30 && seg2 <= 45, seg2);
  await ctx.close();

  console.log('\n══ Sem nada guardado, escolher um treino começa um novo (comportamento de sempre) ══');
  ctx = await b.newContext({ viewport: { width: 375, height: 900 }, serviceWorkers: 'block' });
  page = await ctx.newPage(); erros = [];
  page.on('pageerror', x => erros.push(String(x.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 't');
    localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
    localStorage.setItem('intus-termos-aceito-7', '1');
    localStorage.setItem('intus-fichas', JSON.stringify([{ idficha: 5, idatleta: 7, nmficha: 'Treino ABC', ativo: 1 }]));
    localStorage.setItem('intus-treinos', JSON.stringify([{ idtreino: 11, idficha: 5, divisao: 'A', idexercicio: 1, nmexercicio: 'Agachamento', qtdserie: 3, repeticoes: '12', intervalo: '01:00' }]));
  });
  await page.goto('http://127.0.0.1:8131/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(600);
  await page.evaluate(() => selecionarTreino(5, 'A'));
  await page.waitForTimeout(5500); // contagem 3, 2, 1 antes de o relógio começar
  e = await estado(page);
  ver('treino novo: relógio perto de zero e rodando', e.rodando && e.decorridoMin === 0, e);
  eq('sem erro de script', erros, []);

  await b.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})();
