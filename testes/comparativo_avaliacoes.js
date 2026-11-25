// Comparativo de avaliações do aluno (2 ou mais), 26/11/2026: escolher, ver a evolução (resumo, gráfico, tabela, fotos), voltar sem perder a escolha,
// dobras na ordem tradicional e o comparativo de fotos indo para o Quadro de Resultados.
//   cd testes && INTUS_DIR=../app/painel node comparativo_avaliacoes.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json', '.css': 'text/css' };
const AV = [
  { idavaliacao: 4, idatleta: 7, dtavaliacao: '2026-11-20', peso: 71.2, percgordura: 17.5, metodo: 'Pollock 7 dobras', medidas: { dc_coxa: 18, dc_subescapular: 14, dc_peitoral: 8, dc_abdominal: 20, dc_axilar: 9, dc_tricipital: 12, dc_suprailiaca: 16, cintura: 74, quadril: 98 }, fotos: ['FOTO_B'] },
  { idavaliacao: 3, idatleta: 7, dtavaliacao: '2026-09-02', peso: 73.4, percgordura: 20.1, metodo: 'Pollock 7 dobras', medidas: { dc_suprailiaca: 19, dc_coxa: 21, dc_tricipital: 14, dc_axilar: 11, dc_peitoral: 10, dc_subescapular: 16, dc_abdominal: 24, cintura: 77, quadril: 100 }, fotos: [] },
  { idavaliacao: 2, idatleta: 7, dtavaliacao: '2026-06-10', peso: 75.9, percgordura: 22.8, metodo: 'Bioimpedância', medidas: { cintura: 80 }, fotos: [] },
  { idavaliacao: 1, idatleta: 7, dtavaliacao: '2026-03-01', peso: 78.0, percgordura: 25.0, metodo: 'Composição', fotos: ['FOTO_A'] },
  { idavaliacao: 9, idatleta: 7, dtavaliacao: '2026-10-01', metodo: 'Fotos Postural', fotos: ['FOTO_A'] },              // só fotos: fica fora do comparativo de números
  { idavaliacao: 10, idatleta: 7, dtavaliacao: '2026-10-05', metodo: 'Comparativo do aluno', fotos: ['FOTO_A'] },     // imagem montada pelo aluno: também fora
];
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const pedidos = [];
  const png = await (async () => { const c = await b.newContext(); const p = await c.newPage(); const d = await p.evaluate(() => { const cv = document.createElement('canvas'); cv.width = 60; cv.height = 80; const x = cv.getContext('2d'); x.fillStyle = '#2980b9'; x.fillRect(0, 0, 60, 80); return cv.toDataURL('image/png'); }); await c.close(); return d; })();
  await page.route(/\/app\/api\//, r => {
    const u = r.request().url(), m = r.request().method();
    if (m !== 'GET') { pedidos.push({ m, a: (/action=([a-z_]+)/.exec(u) || [])[1], corpo: JSON.parse(r.request().postData() || '{}') }); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, idresultado: 31, idavaliacao: 9, idpost: 5 }) }); }
    if (/action=avaliacoes/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(AV.map(a => Object.assign({}, a, { fotos: a.fotos.map(f => png) }))) });
    if (/feedbacks\.php\?action=meus/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, feedbacks: [] }) });
    if (/action=resultados/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ resultados: [], atletas: {}, avatars: {} }) });
    if (/action=atleta_existe/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
    return r.abort('failed');
  });
  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-ajuda-v2', JSON.stringify({ visto: 1 }));
    const fim = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
    localStorage.setItem('intus-mensalidades', JSON.stringify([{ idmensalidade: 1, idatleta: 7, plano: 'Consultoria Premium', dtinicio: '2026-11-01', dtvencimento: fim, stmatricula: 'ativa', status: 'paga' }]));
  });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => !!window.ComparativoAvaliacoes && !!window.ComparativoFotos && typeof renderAvaliacoesAluno === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(500);
  const esp = (ms) => page.waitForTimeout(ms || 350);
  const ev = async (fn, arg) => { const r = await page.evaluate(fn, arg); await esp(); return r; };

  console.log('\n══ Contas (sem tela) ══');
  const contas = await page.evaluate((AV) => {
    const T = ComparativoAvaliacoes._teste;
    const cand = T.candidatas(AV);
    const ord = T.ordenar(cand);
    const ls = T.linhas(cand);
    const por = (id) => ls.filter(l => l.m.id === id)[0];
    return { ids: ord.map(a => a.idavaliacao), ordem: ls.map(l => l.m.id), peso: [por('peso').primeiro, por('peso').ultimo, +por('peso').delta.toFixed(1)], magra: por('magra').valores.map(v => v == null ? null : +v.toFixed(2)), soma: por('dobras').valores, delta_cintura: por('cintura').delta, n_cintura: por('cintura').n, periodo: T.periodoTxt(ord), sinal: [T.sinal(-6.8, 1), T.sinal(1.25, 1), T.sinal(0.01, 1), T.sinal(null, 1)] };
  }, AV);
  eq('só avaliações com números entram, da mais antiga para a mais nova (as só de fotos e o comparativo montado ficam fora)', contas.ids, [1, 2, 3, 4]);
  eq('as medidas vêm na ordem: composição, circunferências, dobras na ordem tradicional e a soma por último', contas.ordem, ['peso', 'gord', 'magra', 'gorda', 'cintura', 'quadril', 'dc_peitoral', 'dc_axilar', 'dc_tricipital', 'dc_subescapular', 'dc_abdominal', 'dc_suprailiaca', 'dc_coxa', 'dobras']);
  eq('peso: primeiro, último e variação', contas.peso, [78, 71.2, -6.8]);
  eq('massa magra = peso x (1 - % de gordura)', contas.magra, [58.5, 58.59, 58.65, 58.74]);
  eq('soma das dobras só existe onde há dobras (null nas outras)', contas.soma, [null, null, 115, 97]);
  eq('cintura: variação entre o primeiro e o último registro com número (3 de 4 avaliações têm cintura)', [contas.delta_cintura, contas.n_cintura], [-6, 3]);
  eq('período do primeiro ao último registro', contas.periodo, '9 meses');
  eq('sinais: queda com menos de verdade (−), alta com +, zero sem sinal, vazio com traço', contas.sinal, ['−6,8', '+1,3', '0', '–']);

  console.log('\n══ Escolher as avaliações ══');
  await ev(() => navegar('avaliacoes')); await esp(900);
  await ev(() => abrirComparativos());
  eq('a lista mostra as 4 avaliações com números, mais recente primeiro', await ev(() => Array.from(document.querySelectorAll('#ca-lista label')).map(l => l.textContent.trim().slice(0, 10))), ['20/11/2026', '02/09/2026', '10/06/2026', '01/03/2026']);
  eq('sem nenhuma marcada, comparar fica travado e o botão diz o que falta', await ev(() => { const b = document.getElementById('ca-btn'); return [b.textContent.trim(), b.style.pointerEvents]; }), ['Marque 2 ou mais avaliações', 'none']);
  await ev(() => { const c = document.querySelectorAll('.ca-check'); c[0].click(); });
  eq('com uma marcada continua travado', await ev(() => { const b = document.getElementById('ca-btn'); return [b.textContent.trim(), b.style.pointerEvents, document.getElementById('ca-cont').textContent]; }), ['Marque mais uma avaliação', 'none', '1 marcada']);
  await ev(() => { document.querySelectorAll('.ca-check')[2].click(); });
  eq('com duas libera', await ev(() => { const b = document.getElementById('ca-btn'); return [b.textContent.trim(), b.style.pointerEvents]; }), ['Comparar 2 avaliações', '']);
  await ev(() => { document.querySelectorAll('.ca-check')[1].click(); });
  eq('a terceira também entra (antes só dava para duas)', await ev(() => [document.getElementById('ca-btn').textContent.trim(), document.querySelectorAll('.ca-check:checked').length]), ['Comparar 3 avaliações', 3]);
  await ev(() => ComparativoAvaliacoes.limpar());
  eq('Limpar zera a escolha', await ev(() => document.querySelectorAll('.ca-check:checked').length), 0);
  await ev(() => ComparativoAvaliacoes.todas());
  eq('Selecionar todas marca as 4', await ev(() => [document.querySelectorAll('.ca-check:checked').length, document.getElementById('ca-btn').textContent.trim()]), [4, 'Comparar 4 avaliações']);
  await ev(() => { document.querySelectorAll('.ca-check')[3].click(); });      // desmarca a mais antiga: 3 restam

  console.log('\n══ A evolução ══');
  await ev(() => ComparativoAvaliacoes.comparar());
  const tela = await ev(() => {
    const cab = Array.from(document.querySelectorAll('#v-avaliacoes thead th')).map(t => t.textContent.trim().replace(/\s+/g, ' '));
    const rot = Array.from(document.querySelectorAll('#v-avaliacoes tbody tr')).map(tr => tr.children[0].textContent.trim());
    return { titulo: (document.querySelector('#v-avaliacoes .th-titulo') || {}).textContent, cab, rot, cartoes: document.querySelectorAll('#v-avaliacoes [style*="repeat(2"] > div').length, svg: !!document.querySelector('#ca-grafico svg'), fotos: document.querySelectorAll('#v-avaliacoes img[alt^="Foto de"]').length };
  });
  eq('a tela da evolução abre', tela.titulo.trim(), '📈 Sua evolução');
  eq('a tabela tem uma coluna por avaliação, da mais antiga para a mais nova, e a variação', tela.cab, ['Medida', '10/062026', '02/092026', '20/112026', 'Variação']);
  eq('as dobras seguem a ordem tradicional (peitoral, axilar média, tricipital, subescapular, abdominal, suprailíaca, coxa) e a soma fecha', tela.rot.slice(tela.rot.indexOf('Peitoral')), ['Peitoral', 'Axilar média', 'Tricipital', 'Subescapular', 'Abdominal', 'Suprailíaca', 'Coxa', 'Soma das dobras']);
  eq('4 cartões de resumo (peso, gordura, massa magra, massa gorda) e o gráfico desenhado', [tela.cartoes, tela.svg], [4, true]);
  const celulas = await ev(() => { const l = Array.from(document.querySelectorAll('#v-avaliacoes tbody tr')).find(tr => tr.children[0].textContent.trim() === 'Peso'); return Array.from(l.children).map(c => c.textContent.trim()); });
  eq('linha do peso: um valor por data e a variação do primeiro ao último', celulas, ['Peso', '75,9', '73,4', '71,2', '−4,7']);
  const cor = await ev(() => { const g = (n) => { const tr = Array.from(document.querySelectorAll('#v-avaliacoes tbody tr')).find(t => t.children[0].textContent.trim() === n); return getComputedStyle(tr.lastElementChild).color; }; return { gord: g('% Gordura'), magra: g('Massa magra'), peso: g('Peso') }; });
  eq('% de gordura caindo é verde; peso é azul (sem juízo); massa magra subindo é verde', [cor.gord === cor.magra, cor.gord !== cor.peso], [true, true]);
  eq('as 3 avaliações escolhidas e só elas: fotos só das datas com foto (aqui nenhuma das escolhidas tem)', tela.fotos, 1);
  // gráfico: trocar a medida redesenha só o gráfico
  await ev(() => ComparativoAvaliacoes.metrica('gord'));
  eq('trocar a medida do gráfico mostra o título e os valores dela', await ev(() => ({ t: (document.querySelector('#ca-grafico div') || {}).textContent.replace(/\s+/g, ' ').trim(), v: Array.from(document.querySelectorAll('#ca-grafico svg text')).map(t => t.textContent).filter(t => /,/.test(t) && !/\//.test(t)).slice(-3) })), { t: '% Gordura (%)', v: ['22,8', '20,1', '17,5'] });
  eq('o gráfico tem uma linha e um ponto por avaliação com valor', await ev(() => [document.querySelectorAll('#ca-grafico polyline').length, document.querySelectorAll('#ca-grafico circle').length]), [1, 3]);

  console.log('\n══ Ir e voltar ══');
  await ev(() => document.querySelector('#v-avaliacoes .btn-back').click());
  eq('Voltar da evolução volta para a escolha, com as 3 avaliações ainda marcadas', await ev(() => [(document.querySelector('#v-avaliacoes .th-titulo') || {}).textContent, document.querySelectorAll('.ca-check:checked').length]), ['📊 Comparativos', 3]);
  await ev(() => ComparativoAvaliacoes.comparar());
  await page.evaluate(() => history.back()); await esp(600);
  eq('o voltar do celular na evolução também volta para a escolha', await ev(() => [(document.querySelector('#v-avaliacoes .th-titulo') || {}).textContent, document.querySelectorAll('.ca-check:checked').length, window._viewAtual]), ['📊 Comparativos', 3, 'avaliacoes']);
  await page.evaluate(() => history.back()); await esp(600);
  eq('depois volta para a lista de Avaliações', await ev(() => [(document.querySelector('#v-avaliacoes .th-titulo') || {}).textContent.trim(), window._viewAtual]), ['📋 Minhas Avaliações', 'avaliacoes']);
  await page.evaluate(() => history.back()); await esp(600);
  eq('e por fim para a tela inicial', await ev(() => [window._viewAtual, viewStack.slice()]), ['home', ['home']]);

  console.log('\n══ Fotos da evolução ══');
  await ev(() => navegar('avaliacoes')); await esp(900);
  await ev(() => { abrirComparativos(); ComparativoAvaliacoes.todas(); ComparativoAvaliacoes.comparar(); });
  eq('com todas as avaliações escolhidas, as datas com foto aparecem numa faixa (20/11 e 01/03)', await ev(() => Array.from(document.querySelectorAll('#v-avaliacoes img[alt^="Foto de"]')).map(i => i.alt)), ['Foto de 01/03/2026', 'Foto de 20/11/2026']);
  await ev(() => document.querySelector('#v-avaliacoes img[alt^="Foto de"]').parentNode.click());
  eq('tocar na foto abre uma folha com a foto grande e a data', await ev(() => [!!document.getElementById('ca-foto'), (document.querySelector('#ca-foto b') || {}).textContent]), [true, '01/03/2026']);
  await page.evaluate(() => history.back()); await esp(500);
  eq('o voltar do celular fecha só a foto, a evolução continua', await ev(() => [!!document.getElementById('ca-foto'), (document.querySelector('#v-avaliacoes .th-titulo') || {}).textContent.trim()]), [false, '📈 Sua evolução']);

  console.log('\n══ Comparativo de fotos → Quadro de Resultados ══');
  await ev(() => { navegar('avaliacoes'); });
  await esp(900);
  await ev((png) => { _avaliacoesCache = [{ idavaliacao: 2, dtavaliacao: '2026-11-20', peso: 71.2, metodo: 'Fotos Postural', fotos: [png] }, { idavaliacao: 1, dtavaliacao: '2026-03-01', peso: 78, metodo: 'Fotos Postural', fotos: [png] }]; ComparativoFotos.abrir(); }, png);
  await ev(() => ComparativoFotos.escolher('a')); await ev(() => document.querySelectorAll('#cf-pick img')[1].parentNode.click()); await esp(500);
  await ev(() => ComparativoFotos.escolher('b')); await ev(() => document.querySelectorAll('#cf-pick img')[0].parentNode.click()); await esp(500);
  await ev(() => ComparativoFotos.compartilhar());
  const botoes = await ev(() => Array.from(document.querySelectorAll('#cf-share button')).map(x => x.textContent.trim()).filter(t => /Publicar|Quadro/.test(t)));
  eq('a folha de compartilhar tem o Quadro de Resultados junto do Feed e do perfil', botoes, ['🌎 Publicar no Feed da Intus', '🙋 Publicar só no meu perfil', '🌟 Compartilhar no Quadro de Resultados']);
  await ev(() => { const t = document.getElementById('cf-legenda'); t.value = 'Meus 9 meses de consultoria 💪'; t.dispatchEvent(new Event('input', { bubbles: true })); });
  await ev(() => ComparativoFotos.paraQuadro());
  const rc = await ev(() => ({ aberta: !!document.getElementById('res-compose-overlay'), folha: !!document.getElementById('cf-share'), itens: _rcItens.length, tipo: _rcTipo, periodo: _rcPeriodo, texto: _rcTexto, aceite: _rcAceite, img: _rcItens[0] && /^data:image\/jpeg/.test(_rcItens[0].src) }));
  eq('abre o "Compartilhar resultado" com a imagem, o tipo Evolução, o texto e o período (9 meses) já preenchidos', rc, { aberta: true, folha: false, itens: 1, tipo: 'evolucao', periodo: '9 meses', texto: 'Meus 9 meses de consultoria 💪', aceite: false, img: true });
  await ev(() => _rcPublicarInterno());
  eq('sem a autorização marcada nada é publicado', pedidos.filter(p => /resultado/.test(p.a || '')).length, 0);
  await ev(() => { _rcAceite = true; });
  await ev(() => _rcPublicarInterno()); await esp(900);
  const pub = pedidos.filter(p => /^resultado/.test(p.a || ''));
  eq('com a autorização, publica o resultado (evolução, 9 meses) e envia a imagem e a finalização', pub.map(p => p.a), ['resultados', 'resultado_midia', 'resultado_midia']);
  eq('o resultado vai como Evolução, com texto, período e a autorização do aluno', [pub[0].corpo.tipo, pub[0].corpo.texto, pub[0].corpo.periodo_txt, pub[0].corpo.aceite, pub[0].corpo.total_midias], ['evolucao', 'Meus 9 meses de consultoria 💪', '9 meses', true, 1]);
  eq('a imagem enviada é a do comparativo (JPEG) e a última chamada finaliza', [/^data:image\/jpeg/.test(pub[1].corpo.imagem || ''), pub[2].corpo.finalizar, pub[2].corpo.esperadas], [true, 1, 1]);
  eq('depois de publicar, a folha fecha e o app vai para o Quadro de Resultados', await ev(() => [!!document.getElementById('res-compose-overlay'), window._viewAtual]), [false, 'resultados']);
  // voltar do celular com a folha aberta fecha só a folha
  await ev(() => { navegar('avaliacoes'); }); await esp(900);
  await ev((png) => { _avaliacoesCache = [{ idavaliacao: 2, dtavaliacao: '2026-11-20', peso: 71.2, metodo: 'Fotos Postural', fotos: [png] }, { idavaliacao: 1, dtavaliacao: '2026-03-01', peso: 78, metodo: 'Fotos Postural', fotos: [png] }]; ComparativoFotos.abrir(); }, png);
  await ev(() => ComparativoFotos.escolher('a')); await ev(() => document.querySelectorAll('#cf-pick img')[1].parentNode.click()); await esp(500);
  await ev(() => ComparativoFotos.escolher('b')); await ev(() => document.querySelectorAll('#cf-pick img')[0].parentNode.click()); await esp(500);
  await ev(() => { ComparativoFotos.compartilhar(); ComparativoFotos.paraQuadro(); });
  await page.evaluate(() => history.back()); await esp(600);
  eq('o voltar do celular com a folha do Quadro aberta fecha só a folha e a montagem continua na tela', await ev(() => [!!document.getElementById('res-compose-overlay'), (document.querySelector('#v-avaliacoes .th-titulo') || {}).textContent, window._viewAtual]), [false, '📸 Comparar fotos', 'avaliacoes']);

  eq('nenhum erro de JavaScript', erros, []);
  console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
  await b.close(); srv.close();
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
