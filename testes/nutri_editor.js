// Editor do plano v2, seletor de itens (alimento, grupo, receita, modelo), liberar plano e aba Biblioteca (nutricao.html), no navegador com a API de mentira.
//   cd testes && INTUS_DIR=../app/painel node nutri_editor.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const SITE = path.resolve(process.env.INTUS_DIR || '../app/painel', '../..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };

const PLANO = { idplano: 21, idatleta: 1, titulo: 'Plano Ana', ativo: 0, dtinicio: '2026-11-01', dtfim: null, calorias: null, proteina: null, carboidrato: null, gordura: null,
  refeicoes: [{ nome: 'Café da manhã', horario: '07:30', itens: '', itens_estruturados: [], refeicoes_substitutas: [] }, { nome: 'Almoço', horario: '12:30', itens: '', itens_estruturados: [], refeicoes_substitutas: [] }] };
const ATLETAS = [{ idatleta: 1, nome: 'Ana Souza', peso: 70, altura: 165, genero: 'F', professores_responsaveis: [1], stbloqueio: 'N' }];
const MODELO_ITENS = [{ food_id: null, nome: 'Ovo cozido (modelo)', quantidade: 2, medida_id: 'pers_unid', medida_nome: 'Unidade', medida_g: 50, gramas: 100, por100: { energia_kcal: 155, proteina_g: 13, carboidrato_g: 1.1, lipidio_g: 11 }, nutrientes: { energia_kcal: 155, proteina_g: 13, carboidrato_g: 1.1, lipidio_g: 11 }, substitutos: [] }];
// Dieta como aparece no print do Gaia (Café da manhã, Opção 1), já na forma em que o Claude devolve
const GAIA_IA = { titulo: 'Cutting', refeicoes: [{ nome: 'Café da manhã', horario: '05:00', opcoes: [{ nome: '', observacao: '', itens: [
  { nome: 'Ovo de galinha cozido', quantidade: 4, unidade: 'Unidades', gramas: 200, kcal: 310, proteina: 25, lipidio: 21, carboidrato: 2, substitutos: [] },
  { nome: 'Tomate', quantidade: 0.5, unidade: 'A vontade', gramas: 50, kcal: 9, proteina: 1, lipidio: 0, carboidrato: 2, substitutos: [] },
  { nome: 'Queijo muçarela', quantidade: 1, unidade: 'Fatia', gramas: 15, kcal: 38, proteina: 4, lipidio: 2, carboidrato: 0, substitutos: [] },
  { nome: 'Doce de leite', quantidade: 1, unidade: 'Colher de sopa rasa', gramas: 25, kcal: 77, proteina: 2, lipidio: 1, carboidrato: 14, substitutos: [] },
  { nome: 'Pão de forma', quantidade: 3, unidade: 'Fatias', gramas: 75, kcal: 183, proteina: 9, lipidio: 2, carboidrato: 33, substitutos: [{ nome: 'Tapioca', quantidade: 40, unidade: 'g', gramas: 40 }] },
  { nome: 'Uva', quantidade: 100, unidade: 'g', gramas: 100, kcal: 57, proteina: 1, lipidio: 0, carboidrato: 14, substitutos: [] }] },
  { nome: 'Sem preparo', observacao: '', itens: [{ nome: 'Banana prata', quantidade: 1, unidade: 'Unidade', gramas: 70, kcal: 62, proteina: 1, lipidio: 0, carboidrato: 16, substitutos: [] }] }] }] };
const PNG_1X1 = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const RECEITA = { idconteudo: 3, id: 'panqueca', titulo: 'Panqueca de banana', ativo: 1, nutricao: { kcal: 240, proteina: 14, carboidrato: 28, gordura: 7 } };

(async () => {
  const srv = http.createServer((req, res) => { const p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(SITE, p); if (!f.startsWith(SITE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8123, '127.0.0.1', r));
  const browser = await chromium.launch();
  const json = (r, o, status) => r.fulfill({ status: status || 200, contentType: 'application/json', body: JSON.stringify(o) });
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 900 }, serviceWorkers: 'block' });
  await ctx.addInitScript(() => {
    localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} }));
    localStorage.setItem('mx-token', 'x'.repeat(64));
  });
  const pg = await ctx.newPage(); const er = [], posts = [];
  pg.on('pageerror', e => { if (!/ServiceWorker/i.test(String(e.message))) er.push(String(e.message)); });
  pg.on('dialog', d => d.accept('Modelo de teste'));
  let bibItens = [{ idbib: 5, tipo: 'refeicao', titulo: 'Café proteico', ativo: 1, meu: true, dados: { nome: 'Café', horario: '07:30', itens_estruturados: MODELO_ITENS } },
                  { idbib: 6, tipo: 'grupo', titulo: 'Carboidratos do café', ativo: 1, meu: true, dados: { funcao: 'CHO', itens: [] } }];
  await pg.route(u => u.href.includes('.php'), r => {
    const u = r.request().url(), m = r.request().method();
    if (u.includes('action=nutri_importar_ia')) { let b = {}; try { b = JSON.parse(r.request().postData() || '{}'); } catch (e) {} posts.push({ m, u, b }); return json(r, { ok: true, plano: GAIA_IA, custo_usd: 0.041, modelo: 'claude-sonnet-5-5' }); }
    if (m !== 'GET') { let b = {}; try { b = JSON.parse(r.request().postData() || '{}'); } catch (e) {} posts.push({ m, u, b }); return json(r, { ok: true, idbib: 99, idplano: 21 }); }
    if (u.includes('action=nutri_biblioteca')) return json(r, { itens: bibItens });
    if (u.includes('action=nutri_conteudo')) return json(r, { receitas: [RECEITA], videos: [], dicas: [] });
    if (u.includes('action=nutri_painel')) return json(r, { planos: [Object.assign({}, PLANO, { ativo: 1 })], atletas: { 1: 'Ana Souza' }, registros: [], pesos: [{ idatleta: 1, peso: 70, dtavaliacao: '2026-10-01' }, { idatleta: 1, peso: 69, dtavaliacao: '2026-11-01' }], agua: [{ idatleta: 1, data: '2026-11-12', agua_ml: 2000 }] });
    if (u.includes('action=nutricao')) return json(r, [PLANO]);
    if (u.includes('atletas.php')) return json(r, ATLETAS);
    if (u.includes('action=meus_alimentos') || u.includes('action=alimento_medidas')) return json(r, []);
    return json(r, []);
  });
  await pg.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
  await pg.goto('http://127.0.0.1:8123/app/painel/nutricao.html', { waitUntil: 'load' });
  await pg.waitForTimeout(2500);
  await pg.evaluate(() => AlimentosDB.load());

  console.log('\n══ Editor: estado, desfazer e refazer ══');
  await pg.evaluate(() => { aba = 'planos'; render(); abrirModalForm(21); });
  await pg.waitForTimeout(1900);
  eq('cabeçalho mostra "Salvo", desfazer e refazer desligados e o botão Liberar plano', await pg.evaluate(() => [document.getElementById('ed-salvo').textContent, document.getElementById('ed-undo').disabled, document.getElementById('ed-redo').disabled, !!document.querySelector('.modal-header .btn-primary')]), ['● Salvo', true, true, true]);
  await pg.evaluate(() => { const f = AlimentosDB.buscar('Arroz, tipo 1, cozido', 1)[0]; addAlimento(0, f.id, -1); });
  await pg.waitForTimeout(400);
  eq('depois de adicionar um alimento: alterações pendentes e desfazer ligado', await pg.evaluate(() => [document.getElementById('ed-salvo').textContent, document.getElementById('ed-undo').disabled, _formRefeicoes[0].itens_estruturados.length]), ['● Alterações pendentes', false, 1]);
  await pg.evaluate(() => edDesfazer()); await pg.waitForTimeout(300);
  eq('desfazer tira o alimento e liga o refazer', await pg.evaluate(() => [_formRefeicoes[0].itens_estruturados.length, document.getElementById('ed-redo').disabled, document.querySelectorAll('#ref-itens-0 .meal-item-row:not(.header)').length]), [0, false, 0]);
  await pg.evaluate(() => edRefazer()); await pg.waitForTimeout(300);
  eq('refazer devolve o alimento', await pg.evaluate(() => [_formRefeicoes[0].itens_estruturados.length, document.querySelectorAll('#ref-itens-0 .meal-item-row:not(.header)').length]), [1, 1]);

  console.log('\n══ Totais e acesso rápido ══');
  await pg.evaluate(() => { document.getElementById('nt-calorias').value = '1800'; updateMetasDist(); });
  await pg.waitForTimeout(300);
  ver('barra de totais mostra o progresso da meta', await pg.evaluate(() => !!document.querySelector('#totais-barra .tb-prog i')));
  ver('mostra proteína em g/kg (aluna tem 70 kg)', await pg.evaluate(() => /g\/kg de proteína/.test(document.getElementById('totais-barra').innerText)));
  eq('acesso rápido: água 2,5 L (35 ml por kg de 70 kg) e a meta do plano', await pg.evaluate(() => [/2,5 L por dia/.test(document.getElementById('ed-rapido').innerText), /1800 kcal/.test(document.getElementById('ed-rapido').innerText)]), [true, true]);

  console.log('\n══ Seletor: alimento, grupo, receita e modelo ══');
  await pg.evaluate(() => abrirSeletor(0, -1)); await pg.waitForTimeout(800);
  eq('o seletor abre com 4 abas', await pg.evaluate(() => [...document.querySelector('#sel-ov .sel-tabs').querySelectorAll('.sel-chip')].map(b => b.textContent.replace(/\s*\(\d+\)/, ''))), ['Alimento', 'Grupo alimentar', 'Receita', 'Modelo de refeição']);
  await pg.fill('#sel-q', 'frango'); await pg.waitForTimeout(500);
  const nBusca = await pg.evaluate(() => document.querySelectorAll('#sel-corpo .food-result-item').length);
  ver('buscar "frango" traz alimentos', nBusca > 0, nBusca);
  await pg.evaluate(() => selFonte('equipe')); await pg.waitForTimeout(200);
  eq('filtro "Alimentos da equipe" esconde os da TACO', await pg.evaluate(() => document.querySelectorAll('#sel-corpo .food-result-item').length), 0);
  await pg.evaluate(() => selFonte('todos')); await pg.waitForTimeout(200);
  const antes = await pg.evaluate(() => _formRefeicoes[0].itens_estruturados.length);
  await pg.click('#sel-corpo .food-result-item'); await pg.waitForTimeout(300);
  eq('clicar no resultado adiciona o alimento e o seletor continua aberto', await pg.evaluate(() => [_formRefeicoes[0].itens_estruturados.length, !!document.getElementById('sel-ov')]), [antes + 1, true]);

  await pg.evaluate(() => selTab('grupo')); await pg.waitForTimeout(300);
  const grupos = await pg.evaluate(() => [...document.querySelectorAll('#sel-corpo .sel-card')].map(c => c.querySelector('div > div').innerText.replace(/\s+/g, ' ').trim()));
  ver('os grupos padrão aparecem (carboidrato, proteína, frutas...)', grupos.length >= 4 && grupos.some(g => /Fontes de carboidrato/.test(g)), grupos);
  const n0 = await pg.evaluate(() => _formRefeicoes[0].itens_estruturados.length);
  await pg.evaluate(() => selUsarGrupo('pad-cho')); await pg.waitForTimeout(300);
  const g = await pg.evaluate(() => { const l = _formRefeicoes[0].itens_estruturados; const it = l[l.length - 1]; return { n: l.length, subs: (it.substitutos || []).length, kcalSub: (it.substitutos || []).map(s => Math.round(s.nutrientes.energia_kcal)), kcal: Math.round(it.nutrientes.energia_kcal) }; });
  eq('grupo entra como 1 alimento principal com os demais como substitutos', [g.n, g.subs >= 3], [n0 + 1, true]);
  ver('os substitutos chegam equivalentes em calorias ao principal (±15%)', g.kcalSub.every(k => Math.abs(k - g.kcal) <= g.kcal * 0.15 + 3), g);

  await pg.evaluate(() => selTab('receita')); await pg.waitForTimeout(600);
  eq('aba Receita lista a receita com a informação nutricional', await pg.evaluate(() => [...document.querySelectorAll('#sel-corpo .sel-card')].map(c => /Panqueca de banana/.test(c.innerText) && /240 kcal/.test(c.innerText))), [true]);
  await pg.evaluate(() => selUsarReceita(0)); await pg.waitForTimeout(300);
  eq('a receita entra como 1 porção com as calorias dela', await pg.evaluate(() => { const l = _formRefeicoes[0].itens_estruturados; const it = l[l.length - 1]; return [it.nome, Math.round(it.nutrientes.energia_kcal), it.quantidade, it.medida_nome]; }), ['Panqueca de banana (receita)', 240, 1, 'Porção']);

  await pg.evaluate(() => selTab('modelo')); await pg.waitForTimeout(300);
  eq('aba Modelo lista o modelo salvo pela equipe', await pg.evaluate(() => [...document.querySelectorAll('#sel-corpo .sel-card')].map(c => /Café proteico/.test(c.innerText))), [true]);
  const n1 = await pg.evaluate(() => _formRefeicoes[0].itens_estruturados.length);
  await pg.evaluate(() => selUsarModelo(5)); await pg.waitForTimeout(300);
  eq('o modelo adiciona uma cópia dos itens', await pg.evaluate(() => { const l = _formRefeicoes[0].itens_estruturados; return [l.length, l[l.length - 1].nome]; }), [n1 + 1, 'Ovo cozido (modelo)']);
  await pg.evaluate(() => selFechar());
  eq('fechar o seletor tira a janela da tela', await pg.evaluate(() => !!document.getElementById('sel-ov')), false);

  console.log('\n══ Salvar refeição como modelo ══');
  posts.length = 0;
  await pg.evaluate(() => salvarRefeicaoModelo(0)); await pg.waitForTimeout(500);
  const pm = posts.find(p => /nutri_biblioteca/.test(p.u));
  eq('envia o modelo para a biblioteca com título, tipo e itens', [pm && pm.m, pm && pm.b.tipo, pm && pm.b.titulo, pm && pm.b.dados.itens_estruturados.length > 0], ['POST', 'refeicao', 'Modelo de teste', true]);
  eq('refeição vazia não vira modelo', await pg.evaluate(async () => { const antes = _formRefeicoes[1].itens_estruturados.length; await salvarRefeicaoModelo(1); return antes; }), 0);
  eq('...e nada foi enviado para ela', posts.filter(p => /nutri_biblioteca/.test(p.u)).length, 1);

  console.log('\n══ Liberar plano ══');
  await pg.evaluate(() => liberarPlano(21)); await pg.waitForTimeout(300);
  const lib = await pg.evaluate(() => document.getElementById('lib-ov').innerText);
  ver('a conferência lista a meta, as refeições vazias e o que é opcional', /Total em \d+% da meta/.test(lib) && /Refeições sem alimento: Almoço/.test(lib) && /Opcional/.test(lib), lib);
  ver('com pendência o botão diz "Liberar mesmo assim" (só avisa, não impede)', /Liberar mesmo assim/.test(lib), lib);
  posts.length = 0;
  await pg.click('#lib-ov .btn-primary'); await pg.waitForTimeout(800);
  const pp = posts.find(p => /action=nutricao/.test(p.u));
  eq('liberar grava o plano como ativo', [pp && pp.m, pp && pp.b.ativo, pp && pp.b.idatleta], ['PUT', 1, 1]);

  console.log('\n══ Aba Biblioteca ══');
  await pg.evaluate(() => { closeModal(); trocarAba('biblioteca'); }); await pg.waitForTimeout(1200);
  eq('a aba mostra os 4 cartões', await pg.evaluate(() => document.querySelectorAll('.bib-card').length), 4);
  const bib = await pg.evaluate(() => document.querySelector('.bib-grid').innerText);
  ver('lista o modelo salvo e os grupos padrão', /Café proteico/.test(bib) && /Fontes de proteína/.test(bib) && /Padrão/.test(bib), bib.slice(0, 300));
  posts.length = 0;
  await pg.evaluate(() => bibFormGrupo(0)); await pg.waitForTimeout(300);
  await pg.evaluate(() => { document.getElementById('bg-titulo').value = 'Grupo novo'; _bibF.titulo = 'Grupo novo'; bibGrupoAdd(AlimentosDB.buscar('banana prata', 1)[0].id); bibGrupoAdd(AlimentosDB.buscar('maçã', 1)[0].id); });
  eq('o formulário do grupo lista os 2 alimentos escolhidos', await pg.evaluate(() => document.querySelectorAll('#bg-lista .bib-lin').length), 2);
  await pg.evaluate(() => bibSalvarGrupo()); await pg.waitForTimeout(500);
  const pg2 = posts.find(p => /nutri_biblioteca/.test(p.u));
  eq('salvar grupo envia tipo, título, função e os alimentos', [pg2 && pg2.m, pg2 && pg2.b.tipo, pg2 && pg2.b.titulo, pg2 && pg2.b.dados.itens.length], ['POST', 'grupo', 'Grupo novo', 2]);
  posts.length = 0;
  await pg.evaluate(async () => { bibFormGrupo(0); document.getElementById('bg-titulo').value = 'Vazio'; _bibF.titulo = 'Vazio'; await bibSalvarGrupo(); });
  await pg.waitForTimeout(300);
  eq('grupo sem alimento não é enviado', posts.filter(p => /nutri_biblioteca/.test(p.u)).length, 0);

  console.log('\n══ Importar a dieta do Gaia por print ══');
  await pg.evaluate(() => { aba = 'planos'; render(); abrirImportarPlano(); });
  await pg.waitForTimeout(300);
  posts.length = 0;
  await pg.setInputFiles('#imp-ia', { name: 'print-gaia.png', mimeType: 'image/png', buffer: PNG_1X1 });
  for (let i = 0; i < 40; i++) { if (await pg.evaluate(() => !!_imp)) break; await pg.waitForTimeout(250); }
  if (!(await pg.evaluate(() => !!_imp))) console.log('          prévia vazia: ' + (await pg.evaluate(() => document.getElementById('imp-resumo').innerText)) + ' | erros: ' + JSON.stringify(er));
  const pedidoIA = posts.find(x => /nutri_importar_ia/.test(x.u));
  eq('o print vai ao servidor como imagem JPEG, uma só', [pedidoIA && pedidoIA.b.arquivos.length, pedidoIA && pedidoIA.b.arquivos[0].mime, pedidoIA && pedidoIA.b.arquivos[0].dados.length > 50], [1, 'image/jpeg', true]);
  const imp = await pg.evaluate(() => ({ refs: _imp.refeicoes.map(r => ({ nome: r.nome, horario: r.horario, subs: (r.refeicoes_substitutas || []).map(s => s.nome), itens: r.itens_estruturados.map(i => ({ nome: i.nome, food: i.food_id, kcal: Math.round(i.nutrientes.energia_kcal), g: i.gramas, q: i.quantidade, medida: i.medida_nome, subst: (i.substitutos || []).length })) })), origem: _imp.origem, resumo: document.getElementById('imp-resumo').innerText }));
  eq('refeição com nome e horário, e a opção 2 como refeição substituta', [imp.refs[0].nome, imp.refs[0].horario, imp.refs[0].subs], ['Café da manhã', '05:00', ['Sem preparo']]);
  eq('os 6 alimentos entraram', imp.refs[0].itens.map(i => i.q), [4, 0.5, 1, 1, 3, 100]);
  eq('todos ligados a um alimento da base (food_id), nenhum só texto', imp.refs[0].itens.every(i => i.food), true);
  const esperado = [310, 9, 38, 77, 183, 57];
  ver('as calorias de cada item ficam perto das do Gaia (±25%)', imp.refs[0].itens.every((i, k) => Math.abs(i.kcal - esperado[k]) <= esperado[k] * 0.25 + 4), imp.refs[0].itens.map(i => i.nome + ' ' + i.kcal));
  ver('o peso em gramas do Gaia é respeitado (ovo 200 g, pão 75 g)', imp.refs[0].itens[0].g === 200 && imp.refs[0].itens[4].g === 75, imp.refs[0].itens.map(i => i.g));
  eq('o ovo é um ovo (não outro alimento parecido) e o pão de forma é pão', [/ovo/i.test(imp.refs[0].itens[0].nome), /pão/i.test(imp.refs[0].itens[4].nome)], [true, true]);
  eq('o substituto visível (tapioca) fica no pão', imp.refs[0].itens[4].subst, 1);
  ver('a prévia diz que foi lido pelo Claude, com o custo, e traz a conferência', /Lido pelo Claude/.test(imp.resumo) && /0,041/.test(imp.resumo) && /Conferência dos alimentos/i.test(imp.resumo), imp.resumo.slice(0, 200));
  eq('a origem do plano registra que veio de PDF/print', imp.origem, 'Importado de PDF/print (lido pelo Claude)');
  posts.length = 0;
  await pg.evaluate(() => { document.getElementById('imp-atleta').value = '1'; });
  await pg.click('#imp-ok'); await pg.waitForTimeout(800);
  const criou = posts.find(x => /action=nutricao/.test(x.u));
  eq('importar grava o plano para a aluna escolhida, com a refeição e a substituta', [criou && criou.m, criou && criou.b.idatleta, criou && criou.b.refeicoes.length, criou && criou.b.refeicoes[0].refeicoes_substitutas.length, criou && criou.b.refeicoes[0].itens_estruturados.length], ['POST', 1, 1, 1, 6]);

  console.log('\n══ Ficha do aluno com menu lateral ══');
  await pg.evaluate(() => { aba = 'dashboard'; return carregarPainel(); }); await pg.waitForTimeout(800);
  await pg.evaluate(() => abrirAluno(1)); await pg.waitForTimeout(300);
  eq('o menu lateral tem os grupos e a Visão geral aberta', await pg.evaluate(() => [[...document.querySelectorAll('.fc-grp')].map(g => g.textContent), document.querySelector('.fc-sec.on').id]), [['RESUMO', 'PLANO', 'ACOMPANHAMENTO', 'CONTATO'], 'fc-resumo']);
  await pg.click('.fc-it[data-fc="plano"]'); await pg.waitForTimeout(200);
  eq('clicar em Plano alimentar troca a seção e marca o item', await pg.evaluate(() => [document.querySelector('.fc-sec.on').id, document.querySelector('.fc-it.on').getAttribute('data-fc'), /plano ana/i.test(document.getElementById("fc-plano").innerText), /2,5 L/.test(document.getElementById('fc-plano').innerText)]), ['fc-plano', 'plano', true, true]);
  await pg.click('.fc-it[data-fc="peso"]'); await pg.waitForTimeout(200);
  ver('Peso e água mostra o peso atual e a água média', await pg.evaluate(() => /70 kg/.test(document.getElementById('fc-peso').innerText) && /2,0 L/.test(document.getElementById('fc-peso').innerText)));
  await pg.evaluate(() => closeModal());

  eq('sem erro de script', er, []);
  await browser.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
