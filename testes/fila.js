// Fila de envio de treinos e cardios (aluno.html): o que o aluno fez não pode se perder nem duplicar.
// Abre a tela do aluno num Chromium de verdade, troca a rede por uma de mentira (que cai, demora ou recusa quando o teste manda)
// e confere: o registro fica guardado, é reenviado igual (mesma hora de conclusão, que é o que o servidor usa para reconhecer o
// reenvio), sai da fila só quando o servidor confirma, e o compartilhar do cardio manual e do cardio ao vivo funciona.
//   cd testes && INTUS_DIR=../app/painel node fila.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };

let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html';
    const f = path.join(RAIZ, p);
    if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8097, '127.0.0.1', r));
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  const erros = [];
  page.on('pageerror', e => { if (!/Failed to update a ServiceWorker/i.test(String(e.message))) erros.push(String(e.message)); });

  // ── servidor de mentira ──
  const S = { modo: 'ok', posts: [], proxId: 5000, vistos: {} };
  await page.route('**/treinos.php**', async (route) => {
    const req = route.request(); const url = req.url();
    const ehPost = req.method() === 'POST';
    const cardio = /action=cardio/.test(url), sessao = /action=sessoes/.test(url);
    if (!(ehPost && (cardio || sessao))) {
      if (req.method() === 'PUT') return route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
      return route.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
    }
    const corpo = JSON.parse(req.postData() || '{}');
    S.posts.push({ tipo: cardio ? 'cardio' : 'sessao', corpo });
    if (S.modo === 'offline') return route.abort('failed');
    if (S.modo === '500') return route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"falha"}' });
    if (S.modo === '400') return route.fulfill({ status: 400, contentType: 'application/json', body: '{"error":"dado invalido"}' });
    // reconhece o reenvio como o servidor faz: mesma chave = mesmo registro
    const chave = cardio ? ['c', corpo.dtsessao, corpo.cardio_tipo, corpo.duracao_seg, corpo.hora_conclusao].join('|') : ['s', corpo.dtsessao, corpo.divisao, corpo.idficha, corpo.hora_conclusao].join('|');
    const dup = S.vistos[chave] != null;
    if (!dup) S.vistos[chave] = ++S.proxId;
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, idsessao: S.vistos[chave], duplicata: dup, pontos: 4.2, nao_contar: 0, limite_dia: 3 }) });
  });
  // o resto da API fica fora do ar (como nos outros testes de tela)
  await page.route(/\/app\/api\/(?!treinos\.php)/, r => r.abort('failed'));

  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 'teste');
    localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluno Teste' }));
  });
  await page.goto('http://127.0.0.1:8097/aluno.html', { waitUntil: 'domcontentloaded' });
  let pronto = false;
  for (let i = 0; i < 80 && !pronto; i++) {
    pronto = await page.evaluate(() => typeof salvarCardio === 'function' && typeof _cardioEnviarFila === 'function' && typeof API !== 'undefined').catch(() => false);
    if (!pronto) await page.waitForTimeout(250);
  }
  if (!pronto) throw new Error('aluno.html nao terminou de carregar');
  await page.waitForTimeout(1500);
  const limpar = () => page.evaluate(() => { ['intus-cardio-fila-7', 'intus-sessao-fila-7', 'intus-sessao-pendente-7', 'intus-cardios-7'].forEach(k => localStorage.removeItem(k)); _cardioHistorico = []; document.getElementById('cardio-result-overlay')?.remove(); document.getElementById('busy-ov')?.remove(); });

  console.log('\n══ Cardio manual sem conexão ══');
  await limpar();
  await page.evaluate(() => { renderCardio(); trocarView('cardio'); const t = document.getElementById('cardio-tipo'); t.value = 'Corrida'; onCardioTipoChange && onCardioTipoChange(); document.getElementById('cardio-min').value = '30'; });
  S.modo = 'offline'; S.posts = [];
  await page.evaluate(() => salvarCardio());
  let est = await page.evaluate(() => ({ fila: _cardioFilaLer().length, sol: document.getElementById('cardio-result-overlay')?.innerText || '', ocupado: !!document.getElementById('busy-ov') }));
  eq('sem conexão: o cardio fica guardado na fila', est.fila, 1);
  ver('tela de resultado avisa que ficou guardado', /guardado/.test(est.sol), est.sol);
  ver('tela de resultado tem o botão de compartilhar', /Compartilhar cardio/.test(est.sol), est.sol);
  eq('tela de "salvando" fechou', est.ocupado, false);
  eq('tentou 2 vezes antes de desistir', S.posts.length, 2);
  eq('as duas tentativas mandam exatamente o mesmo registro', JSON.stringify(S.posts[0].corpo), JSON.stringify(S.posts[1].corpo));
  ver('o registro leva hora de conclusão (chave do reenvio)', /^\d\d:\d\d$/.test(S.posts[0].corpo.hora_conclusao), S.posts[0].corpo.hora_conclusao);

  console.log('\n══ Volta a conexão: reenvio ══');
  S.modo = 'ok'; S.posts = [];
  await page.evaluate(() => document.getElementById('cardio-result-overlay')?.remove());
  const enviou = await page.evaluate(() => _cardioEnviarFila(true));
  est = await page.evaluate(() => ({ fila: _cardioFilaLer().length, hist: _cardioHistorico.length, id: (_cardioHistorico[0] || {}).idsessao }));
  eq('reenvio devolve "fila vazia"', enviou, true);
  eq('saiu da fila só depois da confirmação', est.fila, 0);
  eq('entrou no histórico do Cardio', est.hist, 1);
  eq('enviou um único POST', S.posts.length, 1);
  // de novo (toque duplo em "enviar agora"): nada a enviar
  S.posts = [];
  await page.evaluate(() => _cardioEnviarFila(true));
  eq('fila vazia não envia nada de novo', S.posts.length, 0);

  console.log('\n══ Servidor com erro 500 e recusa 400 ══');
  await limpar();
  await page.evaluate(() => { renderCardio(); document.getElementById('cardio-tipo').value = 'Caminhada'; onCardioTipoChange && onCardioTipoChange(); document.getElementById('cardio-min').value = '40'; });
  S.modo = '500'; S.posts = [];
  await page.evaluate(() => salvarCardio());
  est = await page.evaluate(() => { const f = _cardioFilaLer(); return { n: f.length, bloq: !!(f[0] && f[0].bloqueado), t: f[0] && f[0].tentativas }; });
  eq('erro 500: continua na fila', est.n, 1);
  eq('erro 500: não é marcado como recusado (vale nova tentativa)', est.bloq, false);
  S.modo = '400'; S.posts = [];
  await page.evaluate(() => document.getElementById('cardio-result-overlay')?.remove());
  await page.evaluate(() => _cardioEnviarFila(true));
  est = await page.evaluate(() => { const f = _cardioFilaLer(); return { n: f.length, bloq: !!(f[0] && f[0].bloqueado), msg: f[0] && f[0].ultimoErro }; });
  eq('recusa 400: o cardio continua guardado (nada é descartado)', est.n, 1);
  eq('recusa 400: fica marcado para não insistir sozinho', est.bloq, true);
  S.posts = [];
  await page.evaluate(() => _cardioEnviarFila(false));
  eq('envio em segundo plano pula o item recusado', S.posts.length, 0);
  S.modo = 'ok';
  await page.evaluate(() => _cardioEnviarFila(true));
  eq('"enviar agora" tenta o item recusado e, com o servidor ok, ele sai da fila', await page.evaluate(() => _cardioFilaLer().length), 0);

  console.log('\n══ Compartilhar depois do cardio manual ══');
  await limpar();
  await page.evaluate(() => { renderCardio(); document.getElementById('cardio-tipo').value = 'Corrida'; onCardioTipoChange && onCardioTipoChange(); document.getElementById('cardio-min').value = '25'; });
  S.modo = 'ok';
  await page.evaluate(() => salvarCardio());
  ver('resultado do cardio manual tem "Compartilhar cardio"', await page.evaluate(() => /Compartilhar cardio/.test(document.getElementById('cardio-result-overlay')?.innerText || '')));
  await page.evaluate(() => _cardioResultadoCompartilhar());
  await page.waitForTimeout(400);
  est = await page.evaluate(() => ({ origem: _composeOrigem, info: _composeInfo && _composeInfo.tipo, min: _composeInfo && _composeInfo.min, tela: !!document.getElementById('feed-compose-overlay') || !!document.querySelector('[id*=compose]') }));
  eq('abriu o compartilhar como cardio', est.origem, 'cardio');
  eq('com os minutos do cardio', est.min, 25);
  await page.evaluate(() => { try { _fecharComposeFeed(); } catch (e) {} });

  console.log('\n══ Figurinha que falha não impede o post ══');
  est = await page.evaluate(async () => {
    const png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    _feedComposeImg = png; _composeInfoOn = true; _composeInfo = { tipo: 'cardio', min: 10 }; _composeVideoCapa = null;
    const orig = window._compositarInfoNaFoto;
    window._compositarInfoNaFoto = async function () { throw new Error('html2canvas falhou de propósito'); };
    let saida = null, erro = null;
    try { saida = await _composeImagemFinal(); } catch (e) { erro = String(e.message); }
    window._compositarInfoNaFoto = orig;
    return { erro, ehImagem: typeof saida === 'string' && saida.indexOf('data:image') === 0 };
  });
  eq('a imagem final sai mesmo com a figurinha falhando', est.erro, null);
  ver('e é uma imagem', est.ehImagem);

  console.log('\n══ Cardio ao vivo: esforço e foto antes de compartilhar ══');
  await limpar();
  await page.evaluate(() => {
    const item = _cardioFilaAdd({ idatleta: 7, dtsessao: dataHojeLocal(), duracao_seg: 1800, pontos: 5, nao_contar: 0, cardio_tipo: 'Corrida', cardio_rpe: 5, cardio_foto: '', hora_conclusao: '10:15', ao_vivo: 1, comentario: 'x' });
    _renderTelaEsforcoCardioLive({ idsessao: null, uid: item.uid, pendente: true, pts: 5, ptsExib: 4, tipo: 'Corrida', min: 30, distKm: 4.2, pontos: [], motivo: null, rpe: 5 });
    document.getElementById('cle-rpe').value = '8';
    _cardioFoto = 'data:image/jpeg;base64,AAAA';
  });
  ver('tela de esforço avisa que está guardado (sem conexão)', await page.evaluate(() => /Guardado neste aparelho/.test(document.getElementById('cardio-esforco-overlay')?.innerText || '')));
  await page.evaluate(() => _compartilharCardioAoVivo());
  await page.waitForTimeout(300);
  est = await page.evaluate(() => { const f = _cardioFilaLer()[0]; return { rpe: f && f.payload.cardio_rpe, foto: f && f.payload.cardio_foto, origem: _composeOrigem }; });
  eq('compartilhar já grava o esforço escolhido na fila', est.rpe, 8);
  eq('e a foto', est.foto, 'data:image/jpeg;base64,AAAA');
  eq('e abre o compartilhar', est.origem, 'cardio');
  await page.evaluate(() => { try { _fecharComposeFeed(); } catch (e) {} });

  console.log('\n══ Fila de treinos de musculação ══');
  await limpar();
  est = await page.evaluate(() => {
    const base = (f, d) => ({ sessao: { idatleta: 7, idficha: f, divisao: d, dtsessao: dataHojeLocal(), duracao_seg: 3000, hora_conclusao: '09:0' + f, itens: [], comentario: '', nao_contar: 0 }, idParaContinuar: null, duracaoTotal: 3000, comentario: '', ficha: f, divisao: d, dia: dataHojeLocal(), criadoEm: Date.now() - 60000 });
    _pendenteGravar(base(1, 'A'));
    _pendenteGravar(base(2, 'B'));
    const dois = _filaTreinos().length;                 // antes: o 2º treino apagava o 1º
    _pendenteGravar(base(1, 'A'));                      // o mesmo treino finalizado de novo substitui o anterior
    return { dois, depois: _filaTreinos().length, pend: _pendenteDoTreino(2, 'B'), naoPend: _pendenteDoTreino(3, 'C') };
  });
  eq('dois treinos diferentes ficam os dois na fila', est.dois, 2);
  eq('o mesmo treino de novo não vira outro item', est.depois, 2);
  eq('sabe qual treino está pendente', [est.pend, est.naoPend], [true, false]);
  S.modo = 'offline'; S.posts = [];
  await page.evaluate(() => _enviarPendente(false));
  eq('sem conexão a fila continua inteira', await page.evaluate(() => _filaTreinos().length), 2);
  S.modo = 'ok'; S.posts = [];
  const rEnv = await page.evaluate(() => _enviarPendente(true));
  eq('com conexão envia os dois, o mais antigo primeiro', S.posts.map(p => p.corpo.divisao).sort(), ['A', 'B']);
  eq('fila esvazia depois da confirmação', await page.evaluate(() => _filaTreinos().length), 0);
  eq('envio devolve sucesso', rEnv, true);
  await page.evaluate(() => document.getElementById('busy-ov')?.remove());

  console.log('\n══ Migração do formato antigo (um treino só) ══');
  est = await page.evaluate(() => {
    localStorage.setItem('intus-sessao-pendente-7', JSON.stringify({ sessao: { idatleta: 7, idficha: 9, divisao: 'Z', dtsessao: '2026-10-01', duracao_seg: 1000 }, ficha: 9, divisao: 'Z', dia: '2026-10-01', criadoEm: 1 }));
    const l = _filaTreinos();
    return { n: l.length, div: l[0] && l[0].divisao, velhoGone: localStorage.getItem('intus-sessao-pendente-7') === null, uid: !!(l[0] && l[0].uid) };
  });
  eq('o treino guardado no formato antigo vai para a fila', [est.n, est.div, est.velhoGone, est.uid], [1, 'Z', true, true]);

  console.log('\n══ Servidor (treinos.php): protegido contra reenvio e sem ajuste de tabela a cada gravação ══');
  const php = fs.readFileSync(path.resolve(RAIZ, '../api/treinos.php'), 'utf8');
  eq('o bootstrap não roda ALTER TABLE às cegas em intus_sessao (um SHOW COLUMNS decide)', (php.match(/exec\("ALTER TABLE intus_sessao ADD COLUMN [a-z_]+ /g) || []).length, 0);
  ver('o bootstrap consulta as colunas de intus_sessao antes de alterar', /SHOW COLUMNS FROM intus_sessao/.test(php));
  const cardioPost = php.slice(php.indexOf("if ($action === 'cardio')"), php.indexOf("// Foto do cardio (para conferência do professor)"));
  ver('o POST de cardio reconhece o reenvio (devolve o registro que já existe)', /'duplicata' => true/.test(cardioPost) && /hora_conclusao = \?/.test(cardioPost), cardioPost.length);
  ver('o reconhecimento do cardio vem ANTES do INSERT', cardioPost.indexOf("'duplicata' => true") > 0 && cardioPost.indexOf("'duplicata' => true") < cardioPost.indexOf('INSERT INTO intus_sessao'));
  ver('o POST de treino reconhece o reenvio também fora dos 30 min (mesma hora de conclusão)', /OR \(\? IS NOT NULL AND hora_conclusao = \?\)/.test(php));

  eq('nenhum erro de script na página', erros, []);
  await browser.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falha(s)');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
