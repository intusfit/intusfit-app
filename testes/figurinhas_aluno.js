// Figurinha do fim do treino (aluno.html): se a lista do servidor falhar na abertura, não pode voltar o padrão antigo (Alcateia do Ferro).
// Regressão de 27/10 (Samantha Lara): busca única e silenciosa; quando falhava, o aluno recebia as 6 figurinhas de fábrica.
//   cd testes && INTUS_DIR=../app/painel node figurinhas_aluno.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
const LISTA = { itens: [{ tipo: 'img', src: '/app/img/uploads/fig_a.jpg', legenda: 'A' }, { tipo: 'img', src: '/app/img/uploads/fig_b.jpg', legenda: 'B' }] };

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8105, '127.0.0.1', r));
  const b = await chromium.launch();
  const erros = [];
  async function abrir(rede, cache) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => rede(r));
    await page.addInitScript((c) => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); if (c) localStorage.setItem('intus-fig-cfg', JSON.stringify(c)); }, cache);
    await page.goto('http://127.0.0.1:8105/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _figurinhasList === 'function').catch(() => false)) break; await page.waitForTimeout(250); }
    return { ctx, page };
  }
  const legendas = (page) => page.evaluate(() => _figurinhasList().map(i => i.legenda));

  console.log('\n══ Servidor fora do ar na abertura, nunca leu a lista ══');
  let s = await abrir(r => r.abort('failed'), null);
  let l = await legendas(s.page);
  ver('nunca aparece a Alcateia do Ferro', !l.includes('ALCATEIA DO FERRO'), l);
  eq('sem a lista do administrador não existe figurinha nenhuma (nada de padrão de fábrica)', l, []);
  // Resultado do treino guardado de antes com uma figurinha de fábrica: a tela de fim não pode reaproveitá-la.
  const FABRICA = { tipo: 'emoji', emoji: '💪🔥', legenda: 'TREINO CONCLUÍDO', cor: '#7FFF00' };
  let fim = await s.page.evaluate((fab) => {
    SessoesTreino.recordesDaSessao = () => []; sessaoAtual = { nomeFicha: 'Treino A', divisao: 'A', divisaoLabel: 'A - Peito' };
    localStorage.setItem(_fimResultadoKey(77001), JSON.stringify({ fig: fab, mensagem: 'Boa!' }));
    renderFim(1800, { idsessao: 77001, divisao: 'A', itens: [] });
    const h = document.getElementById('v-fim').innerHTML;
    return { fabrica: /TREINO CONCLU|💪🔥|ALCATEIA/.test(h), salvoIntacto: JSON.parse(localStorage.getItem(_fimResultadoKey(77001))).fig.legenda };
  }, FABRICA);
  ver('tela de fim sem lista: nem a figurinha de fábrica guardada aparece', !fim.fabrica, fim);
  eq('sem lista lida o que estava salvo não é apagado', fim.salvoIntacto, 'TREINO CONCLUÍDO');
  await s.ctx.close();

  console.log('\n══ Servidor fora do ar, mas o aparelho já tinha a lista ══');
  s = await abrir(r => r.abort('failed'), LISTA);
  l = await legendas(s.page);
  eq('usa a última lista boa guardada no aparelho', l, ['A', 'B']);
  await s.ctx.close();

  console.log('\n══ Servidor responde: lista vale e fica guardada ══');
  s = await abrir(r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(LISTA) }), null);
  await s.page.waitForTimeout(800);
  l = await legendas(s.page);
  eq('lista do servidor aplicada', l, ['A', 'B']);
  eq('lista guardada no aparelho', await s.page.evaluate(() => (JSON.parse(localStorage.getItem('intus-fig-cfg') || '{}').itens || []).length), 2);
  // Com a lista do administrador: figurinha de fábrica guardada de antes é trocada por uma da lista (e regravada), nunca exibida.
  fim = await s.page.evaluate((fab) => {
    SessoesTreino.recordesDaSessao = () => []; sessaoAtual = { nomeFicha: 'Treino A', divisao: 'A', divisaoLabel: 'A - Peito' };
    localStorage.setItem(_fimResultadoKey(77002), JSON.stringify({ fig: fab, mensagem: 'Boa!' }));
    renderFim(1800, { idsessao: 77002, divisao: 'A', itens: [] });
    const h = document.getElementById('v-fim').innerHTML;
    return { fabrica: /TREINO CONCLU|💪🔥|ALCATEIA/.test(h), daLista: /fig_[ab]\.jpg/.test(h), salvo: JSON.parse(localStorage.getItem(_fimResultadoKey(77002))).fig.legenda };
  }, FABRICA);
  ver('tela de fim com lista: a figurinha de fábrica guardada não aparece', !fim.fabrica, fim);
  ver('em vez dela aparece uma figurinha da lista do administrador', fim.daLista, fim);
  ver('e o resultado guardado passa a ter uma figurinha da lista', ['A', 'B'].includes(fim.salvo), fim);
  const guardada = await s.page.evaluate(() => _figValida({ tipo: 'emoji', emoji: '🐺', legenda: 'ALCATEIA DO FERRO' }));
  eq('figurinha que não está na lista não vale (compartilhar treino e cardio também)', guardada, null);
  await s.ctx.close();

  console.log('\n══ Primeira busca falha e a segunda funciona ══');
  let n = 0;
  s = await abrir(r => { n++; if (n === 1) return r.abort('failed'); r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(LISTA) }); }, null);
  await s.page.waitForTimeout(4500);
  l = await legendas(s.page);
  eq('nova tentativa traz a lista sozinha', l, ['A', 'B']);
  await s.ctx.close();

  console.log('\n══ Estrutura ══');
  const html = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  eq('o texto "ALCATEIA DO FERRO" não existe mais no app', /ALCATEIA DO FERRO/.test(html), false);
  eq('nenhuma figurinha de emoji fixa no código do app (só as do administrador)', /tipo:\s*'emoji'/.test(html), false);
  const painel = fs.readFileSync(path.join(RAIZ, 'notificacoes.html'), 'utf8');
  eq('o painel também não traz figurinhas de fábrica', /ALCATEIA DO FERRO|FIGURINHAS_DEFAULTS/.test(painel), false);
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
