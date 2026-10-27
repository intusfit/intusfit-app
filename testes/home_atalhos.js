// Tela inicial do aluno (aluno.html): Ranking e Medalhas num cartão só (metade esquerda), à direita cinco atalhos mais o Ver mais, só com o título; Extras como atalho (com etiqueta novo); + e − no Ver mais.
// E a tela Ranking e Medalhas, com o seletor único no topo das duas abas.
//   cd testes && INTUS_DIR=../app/painel node home_atalhos.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8110, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 375, height: 900 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
  await page.goto('http://127.0.0.1:8110/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(600);

  console.log('\n══ Ranking e Medalhas num cartão só ══');
  const rk = await page.evaluate(() => { const c = document.querySelector('.h-rk'); return { existe: !!c, metades: c ? c.children.length : 0, txt: c ? c.innerText : '', cliques: c ? [...c.children].map(e => e.getAttribute('onclick')) : [] }; });
  eq('um cartão com duas partes (Ranking sobre Medalhas)', [rk.existe, rk.metades], [true, 2]);
  const prop = await page.evaluate(() => { const c = document.querySelector('.h-rk').getBoundingClientRect(), d = document.getElementById('h-dir').getBoundingClientRect(); return { cartao: Math.round(c.width), direita: Math.round(d.width), esq: c.left < d.left, mesmoTopo: Math.abs(c.top - d.top) < 2, mesmaAltura: Math.abs(c.height - d.height) < 3 }; });
  ver('o cartão ocupa a metade esquerda e os atalhos a metade direita, na mesma altura', Math.abs(prop.cartao - prop.direita) <= 4 && prop.esq && prop.mesmoTopo && prop.mesmaAltura, prop);
  eq('cada metade abre a sua aba', rk.cliques, ['abrirRanking()', 'abrirQuadroMedalhas()']);
  ver('o cartão traz Ranking e Medalhas', /Ranking/.test(rk.txt) && /Medalhas/.test(rk.txt), rk.txt);
  eq('não sobraram os dois cartões separados (.h-mini)', await page.evaluate(() => document.querySelectorAll('#v-home .h-mini').length), 0);

  console.log('\n══ Atalhos só com o título ══');
  const at = await page.evaluate(() => [...document.querySelectorAll('#h-dir .h-atalhos > .h-at')].map(e => ({ tt: e.querySelector('.tt').innerText.trim(), filhos: e.children.length, ic: e.querySelector('.ic').innerText.trim() })));
  eq('cinco atalhos mais o Ver mais (sem Extras, o quinto é a Central do Aluno)', at.map(x => x.tt), ['Nutrição', 'Evoluções', 'Conquistas', 'Avaliações', 'Central do Aluno', 'Ver mais']);
  eq('em duas colunas', await page.evaluate(() => getComputedStyle(document.querySelector('#h-dir .h-atalhos')).gridTemplateColumns.split(' ').length), 2);
  ver('cada atalho tem só ícone e título (nada de subtítulo)', at.every(x => x.filhos === 2), at);
  eq('o ícone do Ver mais é um +', at[5].ic, '+');
  const tam = await page.evaluate(() => { const i = document.querySelector('#h-dir .h-at .ic'); const r = i.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
  ver('ícones levemente menores (25 px, antes 28)', tam[0] === 25 && tam[1] === 25, tam);
  const larg = await page.evaluate(() => document.documentElement.scrollWidth);
  ver('sem rolagem lateral a 375 px', larg <= 375, larg);

  console.log('\n══ Ver mais abre e fecha com + e − ══');
  await page.evaluate(() => _toggleMaisAtalhosHome());
  let t = await page.evaluate(() => ({ ic: document.getElementById('h-maisatalhos-ic').textContent, lbl: document.getElementById('h-maisatalhos-label').textContent, vis: getComputedStyle(document.getElementById('h-maisatalhos')).display }));
  eq('aberto: ícone − e "Ver menos"', t, { ic: '−', lbl: 'Ver menos', vis: 'grid' });
  await page.evaluate(() => _toggleMaisAtalhosHome());
  t = await page.evaluate(() => ({ ic: document.getElementById('h-maisatalhos-ic').textContent, lbl: document.getElementById('h-maisatalhos-label').textContent, vis: getComputedStyle(document.getElementById('h-maisatalhos')).display }));
  eq('fechado: ícone + e "Ver mais"', t, { ic: '+', lbl: 'Ver mais', vis: 'none' });
  const painel = await page.evaluate(() => [...document.querySelectorAll('#h-maisatalhos .tt')].map(e => e.innerText.trim()));
  ver('nome novo: Meu Perfil (e nada de "Perfil" ou "Central" soltos)', painel.includes('Meu Perfil') && !painel.includes('Central') && !painel.includes('Perfil'), painel);
  eq('o painel Ver mais não tem subtítulos', await page.evaluate(() => [...document.querySelectorAll('#h-maisatalhos .h-at')].every(e => e.querySelector('.tt') && e.querySelectorAll('div').length === 2)), true);

  console.log('\n══ Extras ══');
  eq('sem Extras liberado, não há atalho nem etiqueta', await page.evaluate(() => [/Extras/.test(document.getElementById('h-dir').innerText + document.getElementById('h-maisatalhos').innerText), !!document.querySelector('.h-novo')]), [false, false]);
  await page.evaluate(() => { _extras.lista = [{ id: 'm1', titulo: 'Aulas', emoji: '🎁', itens: [{ id: 'a', titulo: 'x', criado_em: new Date().toISOString() }], criado_em: new Date().toISOString() }]; try { localStorage.removeItem(_extrasChave('visto')); } catch (e) {} _extrasAtualizarEntradas(); });
  const ex = await page.evaluate(() => { const t = [...document.querySelectorAll('#h-dir .h-atalhos > .h-at')].find(e => /Extras/.test(e.innerText)); return { existe: !!t, novo: t ? !!t.querySelector('.h-novo') : false, onclick: t ? t.getAttribute('onclick') : '', tiles: [...document.querySelectorAll('#h-dir .h-atalhos > .h-at')].map(e => e.querySelector('.tt').innerText.trim()), painel: [...document.querySelectorAll('#h-maisatalhos .tt')].map(e => e.innerText.trim()), barra: !!document.getElementById('h-extras'), tagMais: !!document.getElementById('h-maisatalhos-novo') }; });
  eq('com Extras: atalho quadrado na grade principal, com a etiqueta novo', [ex.existe, ex.novo, ex.onclick], [true, true, "navegar('extras')"]);
  eq('a grade principal fica Nutrição, Evoluções, Conquistas, Avaliações, Extras e Ver mais', ex.tiles, ['Nutrição', 'Evoluções', 'Conquistas', 'Avaliações', 'Extras', 'Ver mais']);
  ver('a Central do Aluno passa para o Ver mais', ex.painel.includes('Central do Aluno') && ex.painel.includes('Meu Perfil'), ex.painel);
  eq('a barra larga antiga não existe e o Ver mais não repete a etiqueta', [ex.barra, ex.tagMais], [false, false]);
  await page.evaluate(() => { _extras.lista[0].itens[0].criado_em = '2020-01-01T00:00:00Z'; localStorage.setItem(_extrasChave('visto'), new Date().toISOString()); _extrasAtualizarEntradas(); });
  eq('depois de visto, a etiqueta some', await page.evaluate(() => !!document.querySelector('.h-novo')), false);

  console.log('\n══ Tela Ranking e Medalhas ══');
  await page.evaluate(() => abrirRanking()); await page.waitForTimeout(1500);
  let rt = await page.evaluate(() => { const v = document.getElementById('v-ranking'); const bs = [...v.querySelectorAll('button[aria-pressed]')]; return { titulo: v.innerText.includes('Ranking e Medalhas'), seg: bs.map(x => [x.innerText.trim(), x.getAttribute('aria-pressed')]), dourado: !!v.querySelector('button[aria-label="Quadro de medalhas"]'), voltar: /‹ Ranking/.test(v.innerText) }; });
  eq('aba Ranking: título único e seletor com Ranking ativo', [rt.titulo, rt.seg], [true, [['🥇 Ranking', 'true'], ['🏅 Medalhas', 'false']]]);
  eq('o botão dourado duplicado saiu', rt.dourado, false);
  await page.evaluate(() => { document.querySelector('#v-ranking button[aria-pressed="false"]').click(); }); await page.waitForTimeout(500);
  rt = await page.evaluate(() => { const v = document.getElementById('v-ranking'); const bs = [...v.querySelectorAll('button[aria-pressed]')]; return { aba: _rankingTab, titulo: v.innerText.includes('Ranking e Medalhas'), seg: bs.map(x => x.getAttribute('aria-pressed')), voltar: /‹ Ranking/.test(v.innerText), quadro: /Quadro de medalhas/i.test(v.innerText) }; });
  eq('tocar em Medalhas abre o quadro com o mesmo título e o seletor', [rt.aba, rt.titulo, rt.seg, rt.voltar, rt.quadro], ['medalhas', true, ['false', 'true'], false, true]);
  await page.evaluate(() => { document.querySelector('#v-ranking button[aria-pressed="false"]').click(); }); await page.waitForTimeout(500);
  eq('tocar em Ranking volta para a semana', await page.evaluate(() => _rankingTab), 'semana');
  await page.evaluate(() => { _rankingTab = 'historico'; _renderRankingView(); });
  eq('Top 5 continua dentro de Ranking (seletor em Ranking)', await page.evaluate(() => [...document.querySelectorAll('#v-ranking button[aria-pressed]')].map(x => x.getAttribute('aria-pressed'))), ['true', 'false']);
  await page.evaluate(() => { _rankingTab = 'semana'; trocarView('home'); abrirQuadroMedalhas(); }); await page.waitForTimeout(1200);
  eq('a metade Medalhas da Home abre direto no quadro', await page.evaluate(() => _rankingTab), 'medalhas');

  console.log('\n══ Estrutura ══');
  const html = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  eq('nenhum "—" ou "não é X, é Y" novo nos textos da Home', /Ver mais.*—|não é [^.]{1,40}, é /.test(html.slice(html.indexOf('function _homeMaisHtml'), html.indexOf('function _homeMaisHtml') + 1500)), false);
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
