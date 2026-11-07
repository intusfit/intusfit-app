// Meu Cardio (aluno.html): lista simples, registrar manualmente em uma tela, confirmação com "Corrigir", tela de "cardio registrado" e o voltar/avançar
// (botão do cabeçalho e voltar do celular) em todas as ordens que um aluno inexperiente pode seguir.
//   cd testes && INTUS_DIR=../app/painel node cardio_tela.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const hoje = (() => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
const HIST = [{ idsessao: 11, dtsessao: hoje, duracao_seg: 2400, pontos: 6, cardio_tipo: 'Corrida', cardio_rpe: 6, nao_contar: 0, ao_vivo: 0, comentario: 'Corrida · esforço 6/10 · 40min || Cansei nos últimos minutos' },
              { idsessao: 10, dtsessao: '2026-10-01', duracao_seg: 1500, pontos: 3, cardio_tipo: 'Pular Corda', cardio_rpe: 5, nao_contar: 0, ao_vivo: 0, comentario: 'Pular Corda' },
              { idsessao: 9, dtsessao: '2026-09-20', duracao_seg: 1800, pontos: 3, cardio_tipo: 'Padel', cardio_rpe: 5, nao_contar: 0, ao_vivo: 0, comentario: 'Padel' }];

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8111, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 375, height: 800 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const posts = [], puts = [];
  await page.route(/\/app\/api\/treinos\.php\?action=cardio/, async r => {
    const req = r.request();
    if (req.method() === 'POST') { const body = JSON.parse(req.postData() || '{}'); posts.push(body); return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ idsessao: 700 + posts.length, pontos: body.pontos || 0, nao_contar: body.nao_contar || 0 }) }); }
    if (req.method() === 'PUT') { puts.push(JSON.parse(req.postData() || '{}')); return r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }); }
    if (req.method() === 'GET') return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(HIST) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '{}' });
  });
  await page.route(/\/app\/api\/(?!treinos\.php\?action=cardio)/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); });
  await page.goto('http://127.0.0.1:8111/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(500);

  const tela = () => page.evaluate(() => ({ view: window._viewAtual, pilha: _cardioUi.pilha.slice(), titulo: (document.querySelector('#v-cardio .th-titulo') || {}).innerText || '', txt: document.getElementById('v-cardio').innerText }));
  const ir = async (js) => { await page.evaluate(js); await page.waitForTimeout(250); };
  const voltarCel = async () => { await page.goBack(); await page.waitForTimeout(350); };
  const clicar = async (sel) => { await page.click(sel); await page.waitForTimeout(250); };

  console.log('\n══ Lista simples ══');
  await ir('abrirCardio()'); await page.waitForTimeout(500);
  let t = await tela();
  eq('abre na lista do Meu Cardio', [t.view, t.pilha], ['cardio', ['home']]);
  const linhas = await page.evaluate(() => [...document.querySelectorAll('#v-cardio button b')].map(e => e.innerText.trim()).slice(0, 4));
  eq('ordem das linhas: ao vivo, manual, programas, histórico', linhas, ['Cardio ao vivo', 'Registrar manualmente', 'Programas guiados', 'Histórico']);
  ver('o texto do manual diz "Já fez o cardio?"', /Já fez o cardio\?/.test(t.txt) && !/Já treinou/.test(t.txt), t.txt.slice(0, 400));
  ver('o gráfico da semana fica depois da lista e antes do "Por que fazer cardio"', await page.evaluate(() => { const x = document.getElementById('v-cardio').innerText; return x.search(/esta semana/i) > x.indexOf('Histórico') && x.indexOf('Por que fazer cardio') > x.search(/esta semana/i); }));
  eq('o gráfico tem 7 barras (Dom a Sáb) e mostra os minutos de hoje', await page.evaluate((h) => { const x = document.getElementById('v-cardio').innerText; return [/Dom[\s\S]*Sáb/.test(x), /40\s*min/.test(x)]; }, hoje), [true, true]);
  const pq = await page.evaluate(() => { const d = [...document.querySelectorAll('#v-cardio details')].pop(); const s = d.querySelector('summary'); return { aberto: d.open, fonte: parseFloat(getComputedStyle(s.querySelector('b')).fontSize), altura: Math.round(s.getBoundingClientRect().height) }; });
  ver('"Por que fazer cardio" é uma aba expansível, fechada, com título grande (16 px ou mais)', !pq.aberto && pq.fonte >= 16 && pq.altura >= 56, pq);
  await page.evaluate(() => { [...document.querySelectorAll('#v-cardio details')].pop().open = true; });
  ver('aberta, mostra os cinco motivos e o aviso de saúde', await page.evaluate(() => { const x = [...document.querySelectorAll('#v-cardio details')].pop().innerText; return /coração/.test(x) && /metabolismo/.test(x) && /médico/.test(x); }));

  console.log('\n══ Atividades do formulário ══');
  await clicar('#v-cardio button:has-text("Registrar manualmente")');
  t = await tela();
  eq('abre o formulário (um passo à frente na pilha)', [t.view, t.pilha], ['cardio', ['home', 'form']]);
  const tiles = await page.evaluate(() => [...document.querySelectorAll('#v-cardio .cf-tile')].map(e => e.dataset.k));
  eq('as 14 opções, com dança, trilha, em casa e raquete', tiles.slice().sort(), ['Caminhada', 'Corrida', 'Bike', 'Natação', 'Dança', 'Pular Corda', 'Elíptico', 'Escada', 'Futebol', 'Esportes com raquete', 'Luta', 'Esportes na natureza', 'Cardio em casa', '__outro__'].sort());
  eq('7 principais em pílulas e o resto em "Todas"', await page.evaluate(() => [document.querySelectorAll('#v-cardio .cf-pil').length, document.querySelectorAll('#v-cardio .cf-gra').length, getComputedStyle(document.getElementById('cf-grade')).display]), [7, 7, 'none']);
  eq('Tênis e Padel viraram uma opção só; Polichinelo e Corrida estática também', await page.evaluate(() => [...document.querySelectorAll('#v-cardio .cf-tile')].filter(e => /Tênis|Padel|Polichinelo|estática/i.test(e.dataset.k)).length), 0);
  ver('Esportes com raquete, Cardio em casa e Esportes na natureza trazem os exemplos embaixo', await page.evaluate(() => ['Esportes com raquete|tênis, padel', 'Cardio em casa|polichinelo', 'Esportes na natureza|trilha'].every(x => { const [k, ex] = x.split('|'); const b = document.querySelector('.cf-tile[data-k="' + k + '"]'); return b && b.innerText.includes(k) && b.innerText.includes(ex); })));
  ver('o ícone de pular corda é desenhado (SVG), não o nó 🪢', await page.evaluate(() => { const b = document.querySelector('#v-cardio .cf-tile[data-k="Pular Corda"]'); return !!b.querySelector('svg') && !/🪢/.test(b.innerText); }));
  ver('as pílulas têm pelo menos 38 px de altura', await page.evaluate(() => [...document.querySelectorAll('#v-cardio .cf-pil')].every(e => e.getBoundingClientRect().height >= 38)));
  await clicar('#cf-todas');
  eq('"Todas" abre o resto em grade de 3 colunas', await page.evaluate(() => [getComputedStyle(document.getElementById('cf-grade')).display, getComputedStyle(document.getElementById('cf-grade')).gridTemplateColumns.split(' ').length, document.getElementById('cf-todas').innerText]), ['grid', 3, 'Menos ▴']);
  await clicar('#cf-todas');
  const tela1 = await page.evaluate(() => { const r = id => document.getElementById(id).getBoundingClientRect(); return { fimEsforco: Math.round(r('cf-rpe-desc').bottom), alturaJanela: innerHeight, topoBarra: Math.round(r('cf-barra').top) }; });
  ver('com "Todas" fechado, atividade, duração e esforço cabem juntos na primeira tela (800 px)', tela1.fimEsforco <= tela1.alturaJanela, tela1);
  eq('nenhum elemento do formulário passa da largura da tela (375 px)', await page.evaluate(() => [...document.querySelectorAll('#cf-root *, #cf-barra, #cf-barra *')].filter(e => e.getBoundingClientRect().right > 375.5).length), 0);

  console.log('\n══ Tempo, distância e foto digitáveis e visíveis ══');
  eq('sem atividade escolhida, o botão pede a atividade', await page.evaluate(() => document.getElementById('cf-btn').innerText.trim()), 'Escolha a atividade');
  eq('a distância só aparece quando a atividade permite', await page.evaluate(() => getComputedStyle(document.getElementById('cf-s-dist')).display), 'none');
  await clicar('.cf-tile[data-k="Corrida"]');
  eq('Corrida mostra a distância em km', await page.evaluate(() => [getComputedStyle(document.getElementById('cf-s-dist')).display !== 'none', document.getElementById('cf-dist-un').innerText]), [true, 'km']);
  await clicar('.cf-tile[data-k="Natação"]');
  eq('Natação mostra a distância em metros', await page.evaluate(() => document.getElementById('cf-dist-un').innerText), 'm');
  await clicar('.cf-tile[data-k="Dança"]');
  eq('Dança não pede distância', await page.evaluate(() => getComputedStyle(document.getElementById('cf-s-dist')).display), 'none');
  await ir("_cardioForm.todas = true; _cardioFormAtualizar()");
  await clicar('.cf-tile[data-k="Esportes na natureza"]');
  eq('Esportes na natureza pede distância em km', await page.evaluate(() => [getComputedStyle(document.getElementById('cf-s-dist')).display !== 'none', document.getElementById('cf-dist-un').innerText]), [true, 'km']);
  await clicar('.cf-tile[data-k="Corrida"]');
  await page.fill('#cf-min', '4'); await page.waitForTimeout(150);
  eq('4 minutos: o botão avisa do mínimo', await page.evaluate(() => document.getElementById('cf-btn').innerText.trim()), 'O mínimo é de 5 minutos');
  await page.fill('#cf-min', '45x'); await page.waitForTimeout(150);
  eq('o campo de tempo aceita só números', await page.evaluate(() => [document.getElementById('cf-min').value, _cardioForm.min]), ['45', '45']);
  await page.fill('#cf-min', '9999'); await page.waitForTimeout(150);
  eq('passa de 300: limita em 300', await page.evaluate(() => _cardioForm.min), '300');
  await page.fill('#cf-min', '75'); await page.waitForTimeout(150);
  eq('75 min mostra "= 1 h 15 min"', await page.evaluate(() => document.getElementById('cf-min-h').innerText), '= 1 h 15 min');
  await clicar('.cf-chip[data-m="30"]');
  eq('o atalho de 30 min preenche o campo', await page.evaluate(() => document.getElementById('cf-min').value), '30');
  await page.click('#cf-s-min button[aria-label="Mais"]'); await page.click('#cf-s-min button[aria-label="Mais"]');
  eq('o + soma de 5 em 5', await page.evaluate(() => document.getElementById('cf-min').value), '40');
  await page.click('#cf-s-min button[aria-label="Menos"]');
  eq('o − tira de 5 em 5', await page.evaluate(() => document.getElementById('cf-min').value), '35');
  await page.fill('#cf-min', ''); await page.click('#cf-s-min button[aria-label="Mais"]');
  eq('o + no campo vazio começa em 30', await page.evaluate(() => document.getElementById('cf-min').value), '30');
  await page.fill('#cf-dist', '5,5'); await page.waitForTimeout(100);
  eq('a distância é digitável', await page.evaluate(() => _cardioForm.dist), '5,5');
  await page.fill('#cf-dist', '3,000'); await page.evaluate(() => document.getElementById('cf-dist').blur()); await page.waitForTimeout(150);
  eq('3,000 vira 3 ao sair do campo (mesma regra de sempre)', await page.evaluate(() => document.getElementById('cf-dist').value), '3');
  const foto = await page.evaluate(() => { const b = document.querySelector('#cf-foto button'); const r = b.getBoundingClientRect(), d = document.getElementById('cf-dist').getBoundingClientRect(); return { svg: !!b.querySelector('svg'), alt: Math.round(r.height), altCampo: Math.round(d.height), larg: Math.round(r.width), texto: b.innerText.replace(/\s+/g, ' ').trim() }; });
  ver('o botão da foto é visível, tem ícone e texto e a mesma altura do campo de distância', foto.svg && foto.alt >= 44 && Math.abs(foto.alt - foto.altCampo) <= 2 && /Adicionar foto/.test(foto.texto), foto);
  eq('o esforço começa em 5 (Moderado)', await page.evaluate(() => [_cardioRpe, /^5\s*·\s*Moderado/.test(document.getElementById('cf-rpe-desc').innerText.replace(/\s+/g, ' ').trim())]), [5, true]);
  await clicar('.cf-rpe[data-n="8"]');
  eq('tocar no 8 muda o esforço', await page.evaluate(() => [_cardioRpe, document.querySelector('.cf-rpe[data-n="8"]').getAttribute('aria-pressed'), document.querySelector('.cf-rpe[data-n="5"]').getAttribute('aria-pressed')]), [8, 'true', 'false']);
  eq('o botão resume o que será registrado', await page.evaluate(() => document.getElementById('cf-btn').innerText.trim()), 'Continuar: Corrida, 30 min');

  console.log('\n══ Voltar e corrigir (nada se perde) ══');
  await page.fill('#cf-nota', 'Senti o joelho   um pouco || no fim');
  await clicar('#cf-btn');
  t = await tela();
  eq('"Continuar" leva à confirmação (3º passo da pilha)', [t.pilha, t.titulo.replace(/^\S+\s/, '')], [['home', 'form', 'confirma'], 'Confira os dados']);
  ver('a confirmação mostra o comentário do aluno (sem quebra e sem o separador)', /Senti o joelho um pouco \| no fim/.test(t.txt), t.txt);
  ver('a confirmação mostra atividade, tempo, distância e esforço, sem pontos', /Corrida/.test(t.txt) && /30 min/.test(t.txt) && /3 km/.test(t.txt) && /Muito forte/.test(t.txt) && !/pontos somados/i.test(t.txt), t.txt.slice(0, 500));
  eq('há os dois botões: registrar e corrigir', await page.evaluate(() => [...document.querySelectorAll('#v-cardio button')].map(b => b.innerText.trim()).filter(x => /registrar|Corrigir/.test(x))), ['✅ Está certo, registrar', '✏️ Corrigir']);
  await clicar('#v-cardio button:has-text("Corrigir")');
  t = await tela();
  eq('Corrigir volta ao formulário', t.pilha, ['home', 'form']);
  eq('e o que foi digitado continua lá', await page.evaluate(() => [document.querySelector('.cf-tile[aria-pressed="true"]').dataset.k, document.getElementById('cf-min').value, document.getElementById('cf-dist').value, _cardioRpe]), ['Corrida', '30', '3', 8]);
  await page.fill('#cf-min', '35'); await clicar('#cf-btn');
  await voltarCel();
  t = await tela();
  eq('voltar do celular na confirmação também volta ao formulário', t.pilha, ['home', 'form']);
  eq('com o tempo corrigido preservado', await page.evaluate(() => document.getElementById('cf-min').value), '35');
  await voltarCel();
  t = await tela();
  eq('voltar do celular no formulário volta à lista (e continua no Meu Cardio)', [t.view, t.pilha], ['cardio', ['home']]);
  await voltarCel();
  eq('mais um voltar sai do Meu Cardio, para a tela de onde veio', await page.evaluate(() => window._viewAtual), 'home');
  await page.goForward(); await page.waitForTimeout(350);
  eq('avançar do navegador não deixa o app em estado quebrado (fica na tela de início)', await page.evaluate(() => [window._viewAtual, _cardioUi.pilha.length]), ['home', 1]);
  await ir('abrirCardio()'); await page.waitForTimeout(300);
  await ir("cardioAbrirForm()");
  await clicar('#v-cardio .th-titulo ~ img, #v-cardio .btn-back');
  t = await tela();
  eq('o "← Voltar" do cabeçalho do formulário volta à lista', t.pilha, ['home']);
  await ir("cardioAbrirForm()");
  ver('ao reabrir o formulário o que não foi registrado continua preenchido (não perdeu ao voltar)', await page.evaluate(() => _cardioForm.tipo === 'Corrida' && document.getElementById('cf-min').value === '35'));

  console.log('\n══ Registro ══');
  await clicar('#cf-btn'); await clicar('#v-cardio button:has-text("Está certo, registrar")');
  await page.waitForTimeout(800);
  const res = await page.evaluate(() => { const o = document.getElementById('cardio-result-overlay'); return o ? { txt: o.innerText, botoes: [...o.querySelectorAll('button')].map(b => b.innerText.trim()), cheia: o.getBoundingClientRect().height >= innerHeight - 2 } : null; });
  ver('aparece a tela de cardio registrado, cobrindo tudo', res && res.cheia, res);
  ver('com frase de parabéns e o resumo do que foi feito', res && /Parabéns!/.test(res.txt) && /Corrida · 35 min · 3 km/.test(res.txt), res && res.txt);
  ver('e com a pontuação somada', res && /pontos somados/i.test(res.txt) && /\+\d/.test(res.txt), res && res.txt);
  eq('opções: compartilhar, início, ranking, conquistas, histórico e voltar ao cardio', res && res.botoes, ['📸 Compartilhar cardio', '🏠 Início', '🏆 Ranking', '🏅 Conquistas', '📊 Histórico', '🏃 Voltar ao Meu Cardio']);
  ver('o comentário vai junto, depois do texto automático', posts.length && /^Corrida · esforço 8\/10 · 35min · 3 km \|\| Senti o joelho um pouco \| no fim$/.test(posts[0].comentario), posts[0] && posts[0].comentario);
  eq('o servidor recebeu a atividade, o tempo e o esforço digitados', posts.length ? [posts[0].cardio_tipo, posts[0].duracao_seg, posts[0].cardio_rpe, posts[0].cardio_metrica] : null, ['Corrida', 2100, 8, '3 km']);
  t = await tela();
  eq('depois de registrar a pilha volta ao começo', t.pilha, ['home']);
  eq('e o formulário foi zerado', await page.evaluate(() => [_cardioForm.tipo, _cardioForm.min, _cardioForm.dist, _cardioRpe]), ['', '', '', 5]);
  await voltarCel();
  ver('voltar do celular na tela de cardio registrado só a fecha', await page.evaluate(() => !document.getElementById('cardio-result-overlay') && window._viewAtual === 'cardio'));
  await voltarCel();
  eq('e o voltar seguinte sai do Meu Cardio (sem passar por formulário ou confirmação velhos)', await page.evaluate(() => window._viewAtual), 'home');

  console.log('\n══ Caminhos da tela de cardio registrado ══');
  for (const [alvo, esperado] of [['🏠 Início', 'home'], ['🏆 Ranking', 'ranking'], ['📊 Histórico', 'historico'], ['🏃 Voltar ao Meu Cardio', 'cardio']]) {
    await ir('abrirCardio()'); await page.waitForTimeout(300);
    await ir("_cardioForm = { tipo: 'Bike', outro: '', min: '30', dist: '' }; mostrarResultadoCardio(3.2, 'Bike', 30, null, null, { tipo: 'Bike', min: 30, distKm: 0 })");
    await page.click('#cardio-result-overlay button:has-text("' + alvo.split(' ').slice(1).join(' ') + '")'); await page.waitForTimeout(500);
    eq(alvo + ' leva para ' + esperado, await page.evaluate(() => [window._viewAtual, !!document.getElementById('cardio-result-overlay')]), [esperado, false]);
  }

  console.log('\n══ Casos de borda ══');
  await ir('abrirCardio()'); await page.waitForTimeout(300);
  await ir('_cardioFormLimpar()');
  await ir('cardioAbrirForm()');
  await clicar('#cf-btn');
  ver('sem atividade, "Continuar" não avança: avisa e destaca', await page.evaluate(() => _cardioUi.pilha.length === 2 && getComputedStyle(document.getElementById('cf-erro')).display !== 'none'));
  await clicar('.cf-tile[data-k="__outro__"]');
  ver('"Outra" abre o campo para escrever qual foi', await page.evaluate(() => getComputedStyle(document.getElementById('cf-outro')).display !== 'none'));
  await page.fill('#cf-min', '20'); await clicar('#cf-btn');
  ver('"Outra" sem nome também não avança', await page.evaluate(() => _cardioUi.pilha.length === 2));
  await page.fill('#cf-outro', 'Remo'); await clicar('#cf-btn');
  ver('com o nome, avança e a confirmação mostra "Remo"', await page.evaluate(() => _cardioUi.pilha.length === 3 && /Remo/.test(document.getElementById('v-cardio').innerText)));
  await clicar('#v-cardio button:has-text("Corrigir")');
  await ir("_cardioForm.todas = true; _cardioFormAtualizar()");
  await clicar('.cf-tile[data-k="Cardio em casa"]');
  eq('trocar de "Outra" para "Em casa" limpa o nome digitado', await page.evaluate(() => [_cardioForm.outro, getComputedStyle(document.getElementById('cf-outro')).display, cardioTipoValor()]), ['', 'none', 'Cardio em casa']);
  await clicar('#cf-btn');
  ver('Cardio em casa vai direto para a confirmação com a distância "não informada"', await page.evaluate(() => /Cardio em casa/.test(document.getElementById('v-cardio').innerText) && /não informada/.test(document.getElementById('v-cardio').innerText)));
  await ir("document.querySelector('#v-cardio button').click()");   // "← Voltar" do cabeçalho da confirmação
  eq('"← Voltar" da confirmação volta ao formulário', (await tela()).pilha, ['home', 'form']);
  await ir("_cardioForm.tipo='Corrida'; _cardioForm.min='500'; _cardioFormAtualizar(); toggleMenu()");
  await page.evaluate(() => { try { fecharMenuDireto(); } catch (e) {} });

  console.log('\n══ Cardio ao vivo com as mesmas atividades do manual ══');
  await ir('abrirCardio()'); await page.waitForTimeout(300);
  await clicar('#v-cardio button:has-text("Cardio ao vivo")'); await page.waitForTimeout(300);
  const chipsLive = await page.evaluate(() => [...document.querySelectorAll('#v-cardio-live .cl-tipo-chip')].map(b => b.innerText.replace(/\s+/g, ' ').trim()).slice(0, 14));
  eq('o ao vivo oferece as mesmas 14 opções do registro manual', chipsLive.length, 14);
  ver('com Dança, Pular Corda, Raquete, Esportes na natureza, Em casa e Outra', ['Dança', 'Pular Corda', 'Esportes com raquete', 'Esportes na natureza', 'Cardio em casa', 'Outra'].every(n => chipsLive.some(c => c.includes(n))), chipsLive);
  ver('o ícone de pular corda também é o desenhado', await page.evaluate(() => !!document.querySelector('#v-cardio-live .cl-tipo-chip svg')));
  await ir("_cardioLiveEscolherTipo('Natação')");
  eq('Natação: sem GPS (herói é o tempo)', await page.evaluate(() => [_cardioLiveUsaGpsAgora(), !!document.getElementById('cl-tempo-hero')]), [false, true]);
  await ir("_cardioLiveEscolherTipo('Esportes na natureza')");
  eq('Esportes na natureza: com GPS e sem a opção "num aparelho"', await page.evaluate(() => [_cardioLiveUsaGpsAgora(), /Num aparelho/.test(document.getElementById('v-cardio-live').innerText)]), [true, false]);
  await ir("_cardioLiveEscolherTipo('__outro__')");
  ver('Outra mostra o campo para escrever o nome', await page.evaluate(() => !!document.getElementById('cl-outro')));
  await ir('_cardioLiveIniciarRastreio()');
  eq('sem o nome, não começa', await page.evaluate(() => [!!_cardioLiveInicioMs, _cardioLiveTipo]), [false, '__outro__']);
  await page.fill('#cl-outro', 'Remo'); await ir('_cardioLiveIniciarRastreio()');
  eq('com o nome, começa sem GPS e a atividade vira "Remo"', await page.evaluate(() => [!!_cardioLiveInicioMs, _cardioLiveTipo, _cardioLiveUsaGpsAgora()]), [true, 'Remo', false]);
  await ir('_cardioLivePararTudo(); _cardioLiveLimparAtivo(); _cardioLiveInicioMs = 0; _cardioLiveTipo = "Caminhada"; _cardioLiveOutro = ""; abrirCardio()');

  console.log('\n══ Limpar ══');
  await ir('abrirCardio()'); await page.waitForTimeout(300); await ir('cardioAbrirForm()');
  await ir("_cardioForm = { tipo: 'Bike', outro: '', min: '25', dist: '8' }; _cardioRpe = 7; _cardioFormAtualizar()");
  await page.click('#v-cardio button:has-text("Limpar")'); await page.waitForTimeout(200);
  eq('"Limpar" zera atividade, tempo, distância e esforço', await page.evaluate(() => [_cardioForm.tipo, _cardioForm.min, _cardioForm.dist, _cardioRpe, document.getElementById('cf-min').value, document.querySelectorAll('.cf-tile[aria-pressed="true"]').length]), ['', '', '', 5, '', 0]);

  console.log('\n══ Cardio ao vivo e programa guiado gravam pelo mesmo caminho ══');
  const antes = posts.length;
  await ir("_cardioLiveTipo = 'Corrida'; _cardioLiveKm = () => 3.2; _cardioLiveTempoMs = () => 25 * 60000; _cardioLiveParado = true; _cardioLiveFinalizar()");
  await page.waitForTimeout(900);
  const live = posts[posts.length - 1];
  eq('o ao vivo grava atividade, tempo, distância e a marca de "ao vivo"', posts.length === antes + 1 ? [live.cardio_tipo, live.duracao_seg, live.cardio_metrica, live.ao_vivo, live.cardio_rpe] : null, ['Corrida', 1500, '3.2 km', 1, 5]);
  ver('e abre a tela de esforço do ao vivo', await page.evaluate(() => !!document.getElementById('cardio-esforco-overlay')));
  eq('o formulário fica zerado depois do ao vivo', await page.evaluate(() => [_cardioForm.tipo, _cardioForm.min, _cardioForm.dist]), ['', '', '']);
  await page.fill('#cle-nota', 'Cansativo'); await ir('_confirmarEsforcoCardioLive()'); await page.waitForTimeout(700);
  ver('o comentário do ao vivo vai no ajuste do esforço, no mesmo formato do manual', puts.length === 1 && /^Corrida · esforço 5\/10 · 25min · 3\.2 km \|\| Cansativo$/.test(puts[0].comentario), puts);
  ver('"Concluir" do ao vivo mostra a tela de parabéns com os atalhos', await page.evaluate(() => { const o = document.getElementById('cardio-result-overlay'); return !!o && /Parabéns!/.test(o.innerText) && /Ranking/.test(o.innerText) && /Início/.test(o.innerText) && /Histórico/.test(o.innerText); }));
  await ir('_fecharResultadoCardio()');
  const antes2 = posts.length;
  await ir("(function () { const p = API.PROGRAMAS_CARDIO[0]; _prog.id = p.id; _prog.ativ = 'Bike'; _prog.sessao = null; _progAtivoSeg = () => 40 * 60; API.programaDuracao = () => 40 * 60; })(); _progConcluir(true)");
  await page.waitForTimeout(900);
  const prog = posts[posts.length - 1];
  eq('o programa grava a atividade escolhida, o tempo e o comentário "Programa: ..."', posts.length === antes2 + 1 ? [prog.cardio_tipo, prog.duracao_seg, /^Programa: /.test(prog.comentario)] : null, ['Bike', 2400, true]);
  ver('e a tela de resultado do programa tem frase de parabéns', await page.evaluate(() => /Parabéns!/.test(document.getElementById('v-cardio-prog').innerText)));

  console.log('\n══ Semana abre o calendário; ícone da escada ══');
  await ir('abrirCardio()'); await page.waitForTimeout(300);
  await clicar('#v-cardio [aria-label="Abrir o calendário"]'); await page.waitForTimeout(300);
  eq('tocar no gráfico da semana abre o histórico em calendário (como a "Sua semana" da tela inicial)', await page.evaluate(() => [window._viewAtual, _historicoTab]), ['historico', 'linha']);
  await ir('abrirCardio()'); await page.waitForTimeout(300);
  ver('a escada tem ícone de degraus, não o escalador', await page.evaluate(() => /<svg/.test(_cardioEmoji({ cardio_tipo: 'Escada' })) && !/🧗/.test(_cardioEmoji({ cardio_tipo: 'Escada' }))));

  console.log('\n══ Histórico ══');
  await ir('abrirCardio()'); await page.waitForTimeout(400);
  eq('abrir o Meu Cardio de novo começa na lista', (await tela()).pilha, ['home']);
  await clicar('#v-cardio button:has-text("Histórico")');
  t = await tela();
  eq('o histórico é um passo da pilha', t.pilha, ['home', 'hist']);
  ver('lista as atividades com os ícones (corda desenhada, padel antigo com raquete)', await page.evaluate(() => { const x = document.getElementById('v-cardio'); return /Corrida/.test(x.innerText) && /Pular Corda/.test(x.innerText) && /Padel/.test(x.innerText) && !!x.querySelector('svg') && !/🪢/.test(x.innerText); }));
  ver('o histórico mostra o comentário do aluno', await page.evaluate(() => /💬 Cansei nos últimos minutos/.test(document.getElementById('v-cardio').innerText)));
  eq('e os outros alunos (perfil e ranking) só veem o texto automático', await page.evaluate(() => [_atvCardioTxt(_cardioHistorico[0], 40).includes('Cansei'), _atvCardioTxt(_cardioHistorico[0], 40).includes('Corrida · esforço 6/10')]), [false, true]);
  await voltarCel();
  eq('voltar do celular no histórico volta à lista', (await tela()).pilha, ['home']);

  console.log('\n══ Tema claro ══');
  await page.evaluate(() => { document.body.classList.add('tema-claro'); renderCardio(); });
  await clicar('#v-cardio button:has-text("Registrar manualmente")');
  const cor = await page.evaluate(() => { const b = document.querySelector('.cf-tile'); const t = document.querySelector('#v-cardio .th-titulo'); return { fundoTile: getComputedStyle(b).backgroundColor, tituloSecao: getComputedStyle(document.querySelector('#cf-s-ativ > div')).color }; });
  ver('no tema claro os botões são claros e o título escuro', cor.fundoTile === 'rgb(255, 255, 255)' && cor.tituloSecao === 'rgb(26, 26, 26)', cor);
  await page.evaluate(() => { document.body.classList.remove('tema-claro'); });

  console.log('\n══ Erros de página ══');
  eq('nenhum erro de JavaScript durante o teste', erros, []);

  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
