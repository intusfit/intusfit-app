// Quadro de medalhas: apuração única (API.apurarMedalhas, usada pelo app do aluno e pela aba "Medalhas" da Frequência e Ranking do painel)
// e a aba do painel funcionando de verdade num Chromium, com a API simulada.
//   cd testes && INTUS_DIR=../app/painel node medalhas.js
const { carregar } = require('./_api.js');
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

console.log('\n══ Apuração (api.js, hoje congelado em segunda 09/11/2026) ══');
const A = carregar({ hoje: '2026-11-09' });
let _id = 1;
const mus = (id, data) => ({ idsessao: _id++, idatleta: id, tipo: 'musculacao', dtsessao: data, duracao_seg: 3600 });
// semana fechada 01/11 a 07/11: aluno 1 treina 3 dias, alunos 2 e 3 treinam 1 dia (empatam), aluno 4 não treina.
// semana de 08/11 (atual) NÃO pode premiar. Mês de outubro fechado: aluno 2 treina mais.
const sess = [
  mus(1, '2026-11-02'), mus(1, '2026-11-03'), mus(1, '2026-11-04'), mus(2, '2026-11-05'), mus(3, '2026-11-06'),
  mus(3, '2026-11-08'), mus(3, '2026-11-09'), mus(3, '2026-11-10'),       // semana em andamento: ignorada
  mus(2, '2026-10-05'), mus(2, '2026-10-06'), mus(1, '2026-10-07'),
];
const build = (lista) => Object.values(A.agregarPontosRank(lista)).filter(a => a.total > 0).map(a => ({ id: a.id, pts: a.total })).sort((x, y) => y.pts - x.pts);
const ap = A.apurarMedalhas(sess, build, { semana: [25, 18, 12], mes: [60, 42], desde: '2026-10-01' });
const por = (id, esc) => { const p = ap.por[id]; return p ? p.podios.filter(x => x.escala === esc).map(x => x.pos + ':' + x.pts) : null; };
eq('a semana em andamento não vira medalha (o aluno 3 só aparece na semana fechada)', ap.por[3] && ap.por[3].podios.filter(x => x.escala === 'semana').length, 1);
eq('aluno 1: 1º na semana fechada (25 pts)', por(1, 'semana').includes('1:25'), true);
eq('alunos 2 e 3 empatam e dividem o 2º lugar (18 pts cada)', [por(2, 'semana').includes('2:18'), por(3, 'semana').includes('2:18')], [true, true]);
eq('outubro (mês inteiro): aluno 2 em 1º (60 pts) e aluno 1 em 2º (42 pts)', [por(2, 'mes'), por(1, 'mes')], [['1:60'], ['2:42']]);
eq('novembro ainda não fechou: só 1 mês', ap.meses, 1);
eq('ordem olímpica: quem tem mais ouros vem primeiro, e o total soma semana + mês', [ap.quadro[0].id, ap.por[2].pts, ap.por[2].mes.ouro], [2, ap.por[2].sem.pts + 60, 1]);
eq('"desde" posterior a tudo: nada a premiar', A.apurarMedalhas(sess, build, { semana: [25], mes: [60], desde: '2026-11-08' }).quadro.length, 0);
eq('mês só conta INTEIRO: com início em 15/10 outubro não entra', A.apurarMedalhas(sess, build, { semana: [25], mes: [60], desde: '2026-10-15' }).meses, 0);

console.log('\n══ Aba "Medalhas" na Frequência e Ranking (painel) ══');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
(async () => {
  // semana fechada mais recente (domingo a sábado), calculada a partir de hoje
  const h = new Date(); h.setHours(0, 0, 0, 0);
  const dom = new Date(h); dom.setDate(h.getDate() - h.getDay() - 7);
  const dia = (n) => { const d = new Date(dom); d.setDate(dom.getDate() + n); return iso(d); };
  const SESS = [
    { idsessao: 1, idatleta: 7, tipo: 'musculacao', dtsessao: dia(1), duracao_seg: 3600, pontos: 0 },
    { idsessao: 2, idatleta: 7, tipo: 'musculacao', dtsessao: dia(2), duracao_seg: 3600, pontos: 0 },
    { idsessao: 3, idatleta: 8, tipo: 'musculacao', dtsessao: dia(3), duracao_seg: 3600, pontos: 0 },
  ];
  const srv = http.createServer((req, res) => { const p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8134, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, async r => {
    const u = r.request().url(), m = r.request().method();
    if (/atletas\.php/.test(u) && m === 'GET') return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idatleta: 7, nome: 'Ana Souza', email: 'a@x.com', professores_responsaveis: [1] }, { idatleta: 8, nome: 'Bruno Lima', email: 'b@x.com', professores_responsaveis: [1] }, { idatleta: 9, nome: 'Carla Dias', email: 'c@x.com', professores_responsaveis: [1] }]) });
    if (/treinos\.php\?action=sessoes/.test(u) && m === 'GET') return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(SESS) });
    return r.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
  });
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); });
  await page.goto('http://127.0.0.1:8134/frequencia.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 60; i++) { if (await page.evaluate(() => typeof loading !== 'undefined' && loading === false && atletas.length > 0 && sessoes.length > 0).catch(() => false)) break; await page.waitForTimeout(250); }
  const abas = await page.evaluate(() => [...document.querySelectorAll('.freq-view-toggle button')].map(x => x.textContent.trim()));
  ver('a aba "Medalhas" aparece ao lado de Calendário e Acessos', abas.some(x => /Medalhas/.test(x)) && abas.some(x => /Calendário/.test(x)) && abas.some(x => /Acessos/.test(x)), abas);
  await page.evaluate(() => { view = 'medalhas'; render(); });
  const t = await page.evaluate(() => ({ txt: document.getElementById('main-content').innerText, linhas: document.querySelectorAll('#main-content .freq-list-table tbody tr').length, cards: document.querySelectorAll('.freq-summary').length }));
  eq('o quadro lista só quem pontuou na semana fechada (Ana e Bruno; Carla não treinou)', t.linhas, 2);
  ver('Ana (2 treinos) vem antes de Bruno e o quadro mostra a semana apurada', t.txt.indexOf('Ana Souza') > -1 && t.txt.indexOf('Ana Souza') < t.txt.indexOf('Bruno Lima') && /semana.* e .*(mês|meses)/i.test(t.txt), t.txt.slice(0, 400));
  ver('o aviso explica que o quadro do painel considera a lista do painel (pode diferir do app)', /pode diferir/.test(t.txt));
  eq('a aba não mostra os cartões de resumo de sessões', t.cards, 0);
  const ouros = await page.evaluate(() => [...document.querySelectorAll('#main-content .freq-list-table tbody tr')].map(tr => [...tr.children].slice(2, 5).map(td => td.textContent.trim())));
  eq('Ana tem 1 ouro, Bruno 1 prata', ouros, [['1', '0', '0'], ['0', '1', '0']]);
  await page.evaluate(() => { view = 'acessos'; render(); }); await page.evaluate(() => { view = 'medalhas'; render(); });
  if (process.env.FOTO) await page.screenshot({ path: process.env.FOTO });
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? 'FALHARAM ' + ruim : 'Tudo certo') + ' (' + ok + ' ok)');
  process.exit(ruim ? 1 : 0);
})();
