// Trilha da jornada na tela inicial, escolha de experiência de treino e folha de integrações (aluno.html).
//   cd testes && INTUS_DIR=../app/painel node jornada.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  for (const tema of ['escuro', 'claro']) {
    console.log('\n══ Tema ' + tema + ' ══');
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', hasTouch: true, isMobile: true });
    const page = await ctx.newPage(); const erros = [], salvos = [];
    page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));   // a rota registrada por último vale primeiro: o aborto vai antes, a de anamnese depois
    await page.route(/\/app\/api\/catalogo\.php\?action=anamnese/, r => { if (r.request().method() !== 'GET') salvos.push(r.request().postData()); r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); });
    await page.addInitScript((t) => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-theme', t); }, tema === 'claro' ? 'claro' : 'escuro');
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _jornadaHtml === 'function' && typeof renderHome === 'function' && !!document.getElementById('v-home')).catch(() => false)) break; await page.waitForTimeout(250); }
    const r1 = await page.evaluate(() => {
      const p = _jnPassos();
      // simula uma home liberada: monta só o cartão
      document.getElementById('v-home').innerHTML = _jornadaHtml();
      const card = document.getElementById('jn-card');
      return { passos: p.map(x => x.id + ':' + (x.ok ? 1 : 0)), cartao: !!card, progresso: card && card.querySelector('.jn-cab .p').textContent, itens: card && card.querySelectorAll('.jn-it').length, atual: card && card.querySelector('.jn-it.atual .a').textContent };
    });
    eq('os 6 passos existem e a jornada começa sem nada feito', r1.passos, ['perfil:0', 'avaliacao:0', 'treino:0', 'cardio:0', 'nutri:0', 'saude:0']);
    eq('fechada, o cartão mostra só o próximo passo (perfil de treino) e 0 de 6', [r1.cartao, r1.itens, r1.atual, r1.progresso], [true, 1, 'Seu perfil de treino', '0 DE 6']);
    const r2 = await page.evaluate(() => {
      localStorage.setItem('intus-jornada-aberta', '1'); document.getElementById('v-home').innerHTML = _jornadaHtml();
      return document.querySelectorAll('#jn-card .jn-it').length;
    });
    eq('aberto ("Ver todo o caminho") mostra os 6 passos', r2, 6);
    // experiência
    await page.evaluate(() => abrirJornadaExperiencia());
    const rotulos = await page.evaluate(() => Array.from(document.querySelectorAll('.jn-nivel .n')).map(x => x.textContent));
    eq('a folha de experiência tem os 4 níveis', rotulos, ['Ainda não treino', 'Iniciante', 'Intermediário', 'Avançado']);
    eq('Salvar começa desligado (sem nível e sem dias)', await page.evaluate(() => document.getElementById('jn-salvar').disabled), true);
    await page.evaluate(() => { _jnEscolher('intermediario'); _jnAlternarDia(1); _jnAlternarDia(3); _jnAlternarDia(5); });
    eq('com nível e dias, Salvar liga', await page.evaluate(() => document.getElementById('jn-salvar').disabled), false);
    await page.evaluate(() => _jnSalvarExperiencia()); await page.waitForTimeout(600);
    const dados = await page.evaluate(() => _jnDados());
    eq('salvou na seção "jornada" da anamnese (nível e dias)', [dados.experiencia, dados.dias], ['intermediario', [1, 3, 5]]);
    eq('mandou ao servidor a seção "jornada" (objeto, não campo simples)', salvos.length >= 1 && JSON.parse(salvos[salvos.length - 1]).jornada.experiencia, 'intermediario');
    eq('a experiência NÃO conta como "anamnese principal respondida"', await page.evaluate(() => _anamnesePrincipalRespondida()), false);
    eq('o passo "perfil" agora está feito e o próximo é a avaliação', await page.evaluate(() => { const p = _jnPassos(); return [p[0].ok, p.findIndex(x => !x.ok)]; }), [true, 1]);
    // integrações
    await page.evaluate(() => abrirIntegracoes());
    eq('a folha de integrações lista as 8 conexões', await page.evaluate(() => document.querySelectorAll('.jn-int').length), 8);
    await page.evaluate(() => _jnInteresse('strava')); await page.waitForTimeout(400);
    const d2 = await page.evaluate(() => _jnDados());
    eq('"Avisar" no Strava grava o interesse e marca o passo de integrações como visto', [d2.integracoes_interesse, d2.integracoes_visto], [['strava'], true]);
    eq('o botão do Strava mostra "Avisaremos"', await page.evaluate(() => document.getElementById('jn-i-strava').textContent), 'Avisaremos');
    // contraste do botão/numeração no tema (texto do botão sobre o verde)
    const cor = await page.evaluate(() => { document.getElementById('v-home').innerHTML = _jornadaHtml(); const el = document.querySelector('#jn-card .jn-go'); if (!el) return null; const cs = getComputedStyle(el); return [cs.color, cs.backgroundColor]; });
    eq('o botão do próximo passo tem cor de texto diferente do fundo', cor && cor[0] !== cor[1], true);
    eq('sem erro de JavaScript', erros, []);
    await ctx.close();
  }
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
