// Extras no Chromium: o painel do administrador (extras.html) e a área do aluno (aluno.html), com a API de mentira.
//   cd testes && INTUS_DIR=../app/painel node extras_front.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8109, '127.0.0.1', r));
  const b = await chromium.launch();

  // ───────────────────────── PAINEL ─────────────────────────
  console.log('\n══ Painel do administrador (extras.html) ══');
  const S = { cats: [{ id: 'aulas', titulo: 'Aulas gravadas', descricao: '', emoji: '🎬', cor: '#0ea5e9', capa: '', publicado: false, visibilidade: { modo: 'ativos', ids: [] }, itens: [] },
                      { id: 'lives', titulo: 'Lives', descricao: '', emoji: '🔴', cor: '#ef4444', capa: '', publicado: true, visibilidade: { modo: 'todos', ids: [] }, itens: [{ id: 'i1', titulo: 'Live 1', tipo: 'live', url: 'https://youtu.be/abc', duracao: '40 min', data: '2026-10-27T19:30', publicado: true, visibilidade: null, criado_em: '2026-10-20 10:00:00' }, { id: 'i2', titulo: 'Live 2', tipo: 'live', url: '', duracao: '', data: '', publicado: false, visibilidade: { modo: 'nutricao', ids: [] }, criado_em: '2026-10-21 10:00:00' }] }],
              salvo: false, chamadas: [] };
  const pg = await (await b.newContext({ viewport: { width: 1300, height: 1000 }, serviceWorkers: 'block' })).newPage();
  const erros = []; pg.on('pageerror', e => erros.push(e.message));
  let dialogos = []; pg.on('dialog', d => { dialogos.push(d.message()); d.accept(); });
  await pg.route(u => u.href.includes('.php'), async (r) => {
    const req = r.request(), u = req.url(), met = req.method();
    const json = (o, st = 200) => r.fulfill({ status: st, contentType: 'application/json', body: JSON.stringify(o) });
    if (u.includes('extras.php')) {
      const acao = (u.match(/action=(\w+)/) || [])[1];
      if (met === 'GET') return json({ ok: true, categorias: S.cats, salvo: S.salvo, alcance: { todos: 40, ativos: 30, nutricao: 12, treino: 25 }, modos: {} });
      const d = JSON.parse(req.postData() || '{}'); S.chamadas.push({ acao, d }); S.salvo = true;
      if (acao === 'categoria_salvar') {
        const c = d.categoria; if (!c.titulo || !c.titulo.trim()) return json({ ok: false, error: 'Dê um nome ao módulo.' }, 422);
        const i = c.id ? S.cats.findIndex(x => x.id === c.id) : -1;
        const nova = Object.assign({ id: c.id || 'novo' + S.cats.length, itens: [] }, i >= 0 ? S.cats[i] : {}, c);
        if (i >= 0) S.cats[i] = nova; else S.cats.push(nova); return json({ ok: true, categoria: nova });
      }
      if (acao === 'categoria_excluir') { S.cats = S.cats.filter(c => c.id !== d.id); return json({ ok: true }); }
      if (acao === 'item_salvar') {
        const c = S.cats.find(x => x.id === d.categoria); if (!c) return json({ ok: false, error: 'Módulo não encontrado.' }, 422);
        if (d.item.url && !/^https:\/\//.test(d.item.url)) return json({ ok: false, error: 'O link precisa começar com https://.' }, 422);
        const i = d.item.id ? c.itens.findIndex(x => x.id === d.item.id) : -1;
        const novo = Object.assign({ id: d.item.id || 'it' + c.itens.length, criado_em: '2026-10-21 12:00:00' }, d.item); if (i >= 0) c.itens[i] = novo; else c.itens.push(novo); return json({ ok: true, item: novo });
      }
      if (acao === 'item_excluir') { const c = S.cats.find(x => x.id === d.categoria); c.itens = c.itens.filter(x => x.id !== d.id); return json({ ok: true }); }
      if (acao === 'reordenar') {
        const lista = d.tipo === 'categoria' ? S.cats : S.cats.find(x => x.id === d.categoria).itens; const m = {}; lista.forEach(x => m[x.id] = x);
        const nova = d.ids.map(id => m[id]).filter(Boolean); if (d.tipo === 'categoria') S.cats = nova; else S.cats.find(x => x.id === d.categoria).itens = nova; return json({ ok: true });
      }
      if (acao === 'importar_cdd') { S.cats.push({ id: 'cdd-novo', titulo: 'Importado do CDD', descricao: '', emoji: '🎬', cor: '#0ea5e9', capa: '', publicado: true, visibilidade: { modo: 'ativos', ids: [] }, itens: [] }); return json({ ok: true, criados: 1, preenchidos: 0 }); }
      return json({ ok: false, error: 'acao' }, 400);
    }
    if (u.includes('atletas.php')) return json([{ idatleta: 1, nome: 'Ana Souza', email: 'a@x.com', stbloqueio: 'N' }, { idatleta: 2, nome: 'Bruno Lima', email: 'b@x.com', stbloqueio: 'N' }, { idatleta: 3, nome: 'Modelo', email: 'modelo.feminino@intusfit.local' }, { idatleta: 4, nome: 'Bloqueado', email: 'c@x.com', stbloqueio: 'S' }]);
    return json([]);
  });
  await pg.addInitScript(() => { localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); });
  await pg.goto('http://127.0.0.1:8109/extras.html', { waitUntil: 'networkidle' });
  await pg.waitForSelector('.ex-mod', { timeout: 5000 });
  eq('lista os módulos', await pg.locator('.ex-mod').count(), 2);
  ver('avisa que são os módulos importados do CDD, já no ar, com os rascunhos escondidos (nada salvo ainda)', await pg.evaluate(() => /importados do CDD/.test(document.body.innerText) && /já estão no ar/.test(document.body.innerText) && /Rascunho/.test(document.body.innerText)));
  ver('mostra "Rascunho" e "Publicado" e a regra com o alcance (≈ 30)', await pg.evaluate(() => /Rascunho: o aluno não vê/.test(document.body.innerText) && /Publicado/.test(document.body.innerText) && /Só alunos ativos \(≈ 30\)/.test(document.body.innerText)));
  ver('o menu lateral tem "Extras"', await pg.evaluate(() => /Extras/.test(document.querySelector('.sidebar, nav, #sidebar, body').innerText)));

  // importar do CDD
  dialogos = [];
  await pg.click('text=Importar do CDD'); await pg.waitForTimeout(600);
  eq('Importar do CDD pede confirmação e chama a importação', [dialogos.length, S.chamadas.at(-1).acao], [1, 'importar_cdd']);
  ver('os módulos importados aparecem na lista', await pg.evaluate(() => /Importado do CDD/.test(document.body.innerText)));
  // reordenar módulos
  await pg.locator('.ex-mod').nth(1).locator('button[title="Subir"]').click(); await pg.waitForTimeout(300);
  eq('subir um módulo manda a nova ordem ao servidor', S.chamadas.at(-1), { acao: 'reordenar', d: { tipo: 'categoria', categoria: '', ids: ['lives', 'aulas', 'cdd-novo'] } });
  eq('e a tela já mostra na nova ordem', await pg.$$eval('.ex-mod .ex-tit b', e => e.map(x => x.textContent).slice(0, 2)), ['Lives', 'Aulas gravadas']);

  // novo módulo com "alunos escolhidos"
  await pg.click('text=+ Novo módulo'); await pg.waitForSelector('#em-titulo');
  await pg.fill('#em-titulo', 'Cintura de Atleta'); await pg.fill('#em-emoji', '🔥');
  await pg.selectOption('#em-modo', 'selecionados'); await pg.waitForSelector('#em-lista label');
  eq('o seletor lista só alunos de verdade (sem modelo nem bloqueado)', await pg.$$eval('#em-lista label', e => e.map(x => x.textContent.trim())), ['Ana Souza', 'Bruno Lima']);
  await pg.fill('#em-busca', 'bru');
  eq('a busca filtra pelo nome', await pg.$$eval('#em-lista label', e => e.filter(x => x.style.display !== 'none').map(x => x.textContent.trim())), ['Bruno Lima']);
  await pg.check('#em-lista input[value="2"]');
  eq('o contador acompanha', await pg.$eval('#em-cont', e => e.textContent), '1 aluno com acesso');
  await pg.check('#em-pub');
  await pg.click('.modal-footer .btn-success'); await pg.waitForTimeout(500);
  const sv = S.chamadas.at(-1);
  eq('salva o módulo com a regra "alunos escolhidos" e o id do aluno', [sv.acao, sv.d.categoria.titulo, sv.d.categoria.visibilidade, sv.d.categoria.publicado], ['categoria_salvar', 'Cintura de Atleta', { modo: 'selecionados', ids: [2] }, true]);
  eq('o módulo novo aparece na lista', await pg.locator('.ex-mod').count(), 4);

  // módulo sem nome
  await pg.click('text=+ Novo módulo'); await pg.waitForSelector('#em-titulo');
  await pg.click('.modal-footer .btn-success'); await pg.waitForTimeout(400);
  ver('módulo sem nome mostra o aviso do servidor e não fecha', await pg.evaluate(() => /Dê um nome ao módulo/.test(document.body.innerText) && !!document.getElementById('em-titulo')));
  await pg.evaluate(() => closeModal());

  // conteúdos
  await pg.locator('.ex-mod', { hasText: 'Lives' }).first().locator('button', { hasText: 'Conteúdos' }).click(); await pg.waitForSelector('.ex-item');
  eq('abre os conteúdos do módulo', await pg.locator('.ex-item').count(), 2);
  if (process.env.FOTO) await pg.screenshot({ path: process.env.FOTO + '-painel.png' });
  ver('mostra "sem link" no conteúdo sem link e a regra própria do outro', await pg.evaluate(() => /sem link/.test(document.body.innerText) && /Alunos de nutrição ou combo/.test(document.body.innerText)));
  await pg.locator('.ex-item').nth(0).locator('button', { hasText: '▼' }).click(); await pg.waitForTimeout(300);
  eq('descer um conteúdo manda a ordem do módulo', S.chamadas.at(-1), { acao: 'reordenar', d: { tipo: 'item', categoria: 'lives', ids: ['i2', 'i1'] } });
  await pg.locator('button', { hasText: '+ Novo conteúdo' }).click(); await pg.waitForSelector('#ei-titulo');
  await pg.fill('#ei-titulo', 'Treino em casa 1'); await pg.selectOption('#ei-tipo', 'video'); await pg.fill('#ei-url', 'http://inseguro.com');
  await pg.click('.modal-footer .btn-success'); await pg.waitForTimeout(400);
  ver('link http é recusado com o aviso do servidor', await pg.evaluate(() => /começar com https/.test(document.body.innerText) && !!document.getElementById('ei-titulo')));
  await pg.fill('#ei-url', 'https://youtu.be/treino1'); await pg.fill('#ei-capa', 'https://intusfit.com.br/app/painel/extras/capas/x.jpg'); await pg.selectOption('#ei-modo', 'treino'); await pg.check('#ei-pub');
  await pg.click('.modal-footer .btn-success'); await pg.waitForTimeout(500);
  const si = S.chamadas.at(-1);
  eq('a capa do conteúdo vai junto', si.d.item.capa, 'https://intusfit.com.br/app/painel/extras/capas/x.jpg');
  eq('salva o conteúdo com regra própria (treino) e publicado', [si.acao, si.d.categoria, si.d.item.titulo, si.d.item.visibilidade, si.d.item.publicado], ['item_salvar', 'lives', 'Treino em casa 1', { modo: 'treino', ids: [] }, true]);
  await pg.locator('.ex-item').nth(2).locator('button', { hasText: 'Editar' }).click(); await pg.waitForSelector('#ei-titulo');
  await pg.selectOption('#ei-modo', 'herdar');
  await pg.click('.modal-footer .btn-success'); await pg.waitForTimeout(500);
  eq('"Igual ao módulo" volta a regra do conteúdo para herdar (null)', S.chamadas.at(-1).d.item.visibilidade, null);

  // excluir
  dialogos = [];
  await pg.locator('.ex-item').nth(2).locator('button', { hasText: 'Excluir' }).click(); await pg.waitForTimeout(500);
  eq('excluir um conteúdo pede confirmação e exclui', [dialogos.length, S.chamadas.at(-1).acao], [1, 'item_excluir']);
  dialogos = [];
  await pg.locator('.ex-mod[data-mod="lives"] .ex-mod-cab button', { hasText: 'Excluir' }).click(); await pg.waitForTimeout(500);
  eq('excluir um módulo COM conteúdos pede duas confirmações', [dialogos.length, S.chamadas.at(-1).acao], [2, 'categoria_excluir']);
  ver('o aviso diz quantos conteúdos vão junto', /conteúdo\(s\)/.test(dialogos[0] || ''), dialogos);

  // ───────────────────────── APP DO ALUNO ─────────────────────────
  console.log('\n══ App do aluno (aluno.html) ══');
  const A = { lista: [{ id: 'm1', titulo: 'Treinos em casa', descricao: 'Para os dias sem academia.', emoji: '🏠', cor: '#22c55e', capa: '', itens: [{ id: 'a', titulo: 'Treino A', descricao: 'Corpo todo', tipo: 'video', url: 'https://youtu.be/abcdef12345', capa: 'https://intusfit.com.br/app/painel/extras/capas/treinos-em-casa-aula-1.jpg', duracao: '20 min', data: '', criado_em: '2026-10-21 10:00:00' }, { id: 'b', titulo: 'Live de terça', descricao: '', tipo: 'live', url: '', duracao: '', data: '2030-01-01T19:30', criado_em: '2026-10-20 10:00:00' }, { id: 'c', titulo: 'Planilha', descricao: '', tipo: 'material', url: 'https://drive.google.com/x', duracao: '', data: '', criado_em: '2026-10-19 10:00:00' }] }], chamadas: 0 };
  const ctx2 = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const ap = await ctx2.newPage(); const erros2 = []; ap.on('pageerror', e => erros2.push(e.message));
  let abertos = []; await ctx2.route('https://drive.google.com/**', r => { abertos.push(r.request().url()); r.fulfill({ status: 200, body: 'ok' }); });
  await ap.route(/\/app\/api\/extras\.php/, async (r) => { A.chamadas++; if (A.fora) return r.abort('failed'); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, categorias: A.lista }) }); });
  await ap.route(/\/app\/api\/(?!extras\.php)/, r => r.abort('failed'));
  await ap.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); });
  await ap.goto('http://127.0.0.1:8109/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await ap.evaluate(() => typeof _extrasCarregar === 'function' && typeof API !== 'undefined').catch(() => false)) break; await ap.waitForTimeout(250); }
  await ap.waitForTimeout(800);
  await ap.evaluate(() => { localStorage.removeItem('intus-extras-visto-7'); _extras.lista = null; _extras.ts = 0; });
  ver('o menu lateral tem o item Extras, escondido até haver algo visível', await ap.evaluate(() => { const m = document.getElementById('menu-extras'); return !!m && m.style.display === 'none'; }));
  eq('sem nada carregado, "Ver mais" não tem o atalho do Extras', await ap.evaluate(() => /Extras/.test(_homeMaisHtml())), false);
  await ap.evaluate(() => _extrasCarregar(true));
  ver('com conteúdo visível: o item do menu aparece', await ap.evaluate(() => document.getElementById('menu-extras').style.display !== 'none'));
  const home = await ap.evaluate(() => { document.getElementById('v-home') && renderHome(); return { mais: _homeMaisHtml(), barra: !!document.getElementById('h-extras'), noPainel: document.getElementById('h-maisatalhos') ? document.getElementById('h-maisatalhos').innerText : '', toggle: _homeMaisTileHtml(false, false) }; });
  ver('o atalho "Extras" está em Ver mais, quadrado como os outros, com a etiqueta novo', /navegar\('extras'\)/.test(home.mais) && /Extras/.test(home.mais) && /class="h-novo">novo</.test(home.mais), home.mais.slice(0, 400));
  ver('o atalho não tem subtítulo (só o título)', !/conteúdos? em/.test(home.mais) && !/aulas, lives e bônus/.test(home.mais));
  ver('a barra larga do Extras não existe mais', home.barra === false);
  ver('o "Ver mais" fechado mostra + e a etiqueta novo quando há Extras novo', /<div class="ic"[^>]*>\+<\/div>/.test(home.toggle) && /h-novo/.test(home.toggle), home.toggle);
  ver('a Home chama o carregamento do Extras (está no renderHome)', fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8').includes('${_extrasHomeHtml()}'));
  await ap.evaluate(() => navegar('extras')); await ap.waitForTimeout(400);
  const tela = await ap.evaluate(() => ({ ativa: window._viewAtual, txt: document.getElementById('v-extras').innerText }));
  ver('a tela Extras abre e lista o módulo com a contagem', tela.ativa === 'extras' && /Treinos em casa/.test(tela.txt) && /3 conteúdos/.test(tela.txt), tela);
  if (process.env.FOTO) await ap.screenshot({ path: process.env.FOTO + '-app-lista.png' });
  await ap.evaluate(() => extrasModulo('m1')); await ap.waitForTimeout(200);
  if (process.env.FOTO) await ap.screenshot({ path: process.env.FOTO + '-app-modulo.png' });
  const mod = await ap.evaluate(() => document.getElementById('v-extras').innerText);
  ver('o módulo lista os conteúdos com tipo, duração e descrição', /Treino A/.test(mod) && /20 min/.test(mod) && /Corpo todo/.test(mod) && /Live de terça/.test(mod) && /Planilha/.test(mod), mod);
  ver('a live futura mostra "Ao vivo em"', /Ao vivo em 01\/01/.test(mod), mod);
  eq('a aula usa a capa própria (e não o quadro do YouTube)', await ap.evaluate(() => { const bg = Array.from(document.querySelectorAll('#v-extras [role=button] > div:first-child')).map(e => e.getAttribute('style') || ''); return [bg.some(x => x.includes('treinos-em-casa-aula-1.jpg')), bg.some(x => x.includes('img.youtube.com'))]; }), [true, false]);
  eq('abrir o módulo marca como visto (o selo NOVO some da Home)', await ap.evaluate(() => _extrasTemNovo()), false);
  await ap.evaluate(() => extrasAbrirItem('m1', 'a')); await ap.waitForTimeout(300);
  ver('vídeo do YouTube abre no player do app', await ap.evaluate(() => document.getElementById('video-modal').classList.contains('open') && /embed\/abcdef12345/.test(document.getElementById('video-iframe').src)));
  await ap.evaluate(() => fecharVideoModal());
  await ap.evaluate(() => extrasAbrirItem('m1', 'b')); await ap.waitForTimeout(200);
  ver('conteúdo sem link avisa, em vez de abrir nada', await ap.evaluate(() => /ainda não tem link/.test(document.getElementById('toast').textContent) && !document.getElementById('video-modal').classList.contains('open')));
  const popup = ap.waitForEvent('popup', { timeout: 3000 }).catch(() => null);
  await ap.evaluate(() => extrasAbrirItem('m1', 'c'));
  const pp = await popup; ver('link que não é YouTube abre fora do app', !!pp && /drive\.google\.com/.test(pp.url()), pp && pp.url());
  await ap.evaluate(() => extrasModulo(null));
  // sem internet usa a cópia
  A.fora = true; await ap.evaluate(() => { _extras.lista = null; _extras.ts = 0; });
  const lista = await ap.evaluate(() => _extrasCarregar(true).then(l => l.length));
  eq('sem internet, usa a cópia guardada no aparelho', lista, 1);
  A.lista = []; A.fora = false; await ap.evaluate(() => _extrasCarregar(true));
  ver('quando a equipe tira tudo do aluno, o item do menu some', await ap.evaluate(() => document.getElementById('menu-extras').style.display === 'none'));
  await ap.evaluate(() => navegar('extras')); await ap.waitForTimeout(300);
  ver('e a tela diz que não há nada disponível', await ap.evaluate(() => /Nenhum extra disponível/.test(document.getElementById('v-extras').innerText)));
  const html = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  eq('nenhum texto novo tem travessão', /[—–]/.test(html.slice(html.indexOf('EXTRAS (conteúdos bônus)'), html.indexOf('MENÇÕES (@)')) .replace(/\/\/.*$/gm, '')), false);
  eq('sem erro de script no painel nem no app', [erros, erros2], [[], []]);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
