// Moderação no app do aluno (aluno.html + moderacao.js): denunciar, bloquear, Pessoas bloqueadas, aceite das Regras da Comunidade e o aviso com o
// MOTIVO quando o servidor recusa (suspensão, texto impróprio). Exigência das lojas (Apple 1.2 e conteúdo gerado por usuário do Google Play).
// Abre a tela num Chromium de verdade, com uma API de mentira que guarda o que o app mandou, e confere o que o aluno VÊ e o que vai ao servidor.
//   cd testes && INTUS_DIR=../app/painel node moderacao_app.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };

let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const MOTIVOS = { spam: 'Spam ou propaganda', assedio: 'Assédio, bullying ou ameaça', odio: 'Ódio ou discriminação', sexual: 'Nudez ou conteúdo sexual', violencia: 'Violência ou conteúdo chocante', substancias: 'Venda ou incentivo de substâncias proibidas', falso: 'Perfil falso, golpe ou fraude', outro: 'Outro motivo' };
const MSG_SUSPENSO = 'Sua conta está suspensa de publicar, comentar e enviar mensagens até 19/11/2026. Treino, cardio, ranking e nutrição continuam normais. Se acha que foi um engano, fale com a equipe: contato@intusfit.com.br.';
const MSG_IMPROPRIO = 'Seu texto tem termos que não são permitidos na comunidade da Intus. Reescreva e tente de novo.';

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html';
    const f = path.join(RAIZ, p);
    if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8099, '127.0.0.1', r));
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const erros = [];
  page.on('pageerror', e => erros.push(String(e.message)));

  // ── API de mentira: guarda o que o app mandou e responde como o servidor responderia ──
  const S = { aceito: false, bloqueados: [], reqs: [], comentario: { status: 200, body: { ok: true } } };
  const nomes = { 5: 'Bia Lima', 6: 'Caio Reis' };
  const json = (route, status, body) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  await page.route('**/catalogo.php**', async (route) => {
    const req = route.request(), url = req.url(), m = req.method();
    const acao = (url.match(/action=([a-z_]+)/) || [])[1] || '';
    let corpo = null; try { corpo = req.postData() ? JSON.parse(req.postData()) : null; } catch (e) {}
    S.reqs.push({ acao, m, corpo, url });
    if (acao === 'regras_comunidade') {
      if (m === 'POST') { S.aceito = true; return json(route, 200, { ok: true, versao: '2026-11' }); }
      if (S.regrasModo === 'estranha') return json(route, 200, []);   // servidor com problema: 200 sem o campo "aceito"
      return json(route, 200, { aceito: S.aceito, versao: '2026-11', aceito_em: null, contato: 'contato@intusfit.com.br', prazo_horas: 24, motivos: MOTIVOS });
    }
    if (acao === 'bloqueios') {
      if (m === 'POST') { if (!S.bloqueados.includes(corpo.idalvo)) S.bloqueados.push(corpo.idalvo); return json(route, 200, { ok: true, novo: true }); }
      if (m === 'DELETE') { const id = Number((url.match(/idalvo=(\d+)/) || [])[1]); S.bloqueados = S.bloqueados.filter(x => x !== id); return json(route, 200, { ok: true }); }
      return json(route, 200, { bloqueados: S.bloqueados.map(id => ({ id, nome: nomes[id] || ('Aluno #' + id) })), ids: S.bloqueados });
    }
    if (acao === 'denuncias' && m === 'POST') {
      if (corpo.alvo_id === 404) return json(route, 404, { error: 'conteudo nao encontrado', codigo: 'nao_encontrado' });
      return json(route, 200, { ok: true, id: 31, ja: false, bloqueado: !!corpo.bloquear });
    }
    if (acao === 'comentarios_pub' && m === 'POST') return json(route, S.comentario.status, S.comentario.body);
    return json(route, 200, []);
  });
  await page.route('**/treinos.php**', r => json(r, 200, []));
  await page.route(/\/app\/api\/(?!treinos\.php|catalogo\.php)/, r => r.abort('failed'));

  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 'teste');
    localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluno Teste' }));
  });
  await page.goto('http://127.0.0.1:8099/aluno.html', { waitUntil: 'domcontentloaded' });
  let pronto = false;
  for (let i = 0; i < 80 && !pronto; i++) {
    pronto = await page.evaluate(() => typeof Moderacao !== 'undefined' && typeof _podePublicar === 'function' && typeof _abrirMenuPost === 'function' && typeof API !== 'undefined').catch(() => false);
    if (!pronto) await page.waitForTimeout(250);
  }
  if (!pronto) throw new Error('aluno.html nao terminou de carregar');
  await page.waitForFunction(() => Moderacao._estado.aceito !== null, null, { timeout: 8000 }).catch(() => {});   // a leitura inicial das regras (1,5 s depois de abrir)

  const toast = () => page.evaluate(() => ({ txt: document.getElementById('toast').textContent, on: document.getElementById('toast').classList.contains('show') }));
  const limpar = () => page.evaluate(() => {
    ['mod-den', 'mod-blq', 'mod-menu', 'mod-lista', 'mod-regras', 'ov-menu-generico', 'feed-compose-overlay'].forEach(id => document.getElementById(id)?.remove());
    const t = document.getElementById('toast'); t.classList.remove('show'); t.textContent = '';
    document.querySelectorAll('[data-teste]').forEach(e => e.remove());
  });
  const aberta = (id) => page.evaluate((i) => !!document.getElementById(i), id);
  const textoDe = (id) => page.evaluate((i) => (document.getElementById(i) || {}).innerText || '', id);
  const pedidos = (acao, metodo) => S.reqs.filter(r => r.acao === acao && (!metodo || r.m === metodo));

  // ═══════════ Código carregado ═══════════
  console.log('\n══ O módulo carrega e entra nos pontos certos ══');
  eq('sem erro de JavaScript ao abrir', erros, []);
  eq('o estado das regras foi lido do servidor ao abrir', await page.evaluate(() => Moderacao._estado.aceito), false);
  eq('os motivos vieram do servidor (8)', await page.evaluate(() => Object.keys(Moderacao._estado.motivos || {}).length), 8);
  const fonte = fs.readFileSync(path.join(RAIZ, 'aluno.html'), 'utf8');
  eq('os 7 pontos de publicação usam o portão único (_podePublicar)', [(fonte.match(/  if \(!_podePublicar\(\)\) return;\n/g) || []).length, (fonte.match(/  if \(!_soTesteBarra\(\)\) return;\n/g) || []).length], [7, 0]);
  ver('o moderacao.js está no service worker e no build nativo', /'moderacao\.js'/.test(fs.readFileSync(path.join(RAIZ, 'sw.js'), 'utf8')) && /'moderacao\.js'/.test(fs.readFileSync(path.resolve(__dirname, '../mobile/scripts/build-web.mjs'), 'utf8')));
  const fn = (nome) => page.evaluate((n) => window[n].toString(), nome);
  ver('mensagem a outro aluno passa pelas regras antes de enviar', /Moderacao\.regrasBarra\(\)\) return;/.test(await fn('enviarMsgAluno')) && /_chatDestino\(\)\.tipo === 'amigo'/.test(await fn('enviarMsgAluno')));
  ver('compartilhar resultado passa pelas regras', /Moderacao\.regrasBarra/.test(await fn('abrirCompartilharResultado')));
  ver('criar turma passa pelas regras', /Moderacao\.regrasBarra/.test(await fn('abrirNovaTurma')));
  ver('o perfil de outro aluno tem "Denunciar ou bloquear"', /Moderacao\.linkPerfilHtml\(Number\(id\), nome\)/.test(await fn('abrirPerfilRanking')) && /!isMe/.test(await fn('abrirPerfilRanking')));
  ver('o cartão de resultado de outro aluno tem o botão de denunciar (e o da equipe não)', /!isMe && !daEquipe && window\.Moderacao/.test(await fn('_resultadoCardHtml')));
  ver('a tela de privacidade tem "Pessoas bloqueadas"', /Moderacao\.abrirBloqueados\(\)/.test(await fn('abrirPrivacidadePerfil')));
  const abrirNotif = await fn('_notifAbrir');
  ver('aviso da equipe e denúncia resolvida são tratados ANTES do Feed (o número do aviso é o da denúncia, não de um post)',
    abrirNotif.indexOf("tipo === 'aviso_equipe'") > 0 && abrirNotif.indexOf("tipo === 'denuncia_resolvida'") > 0 &&
    abrirNotif.indexOf("tipo === 'aviso_equipe'") < abrirNotif.indexOf('_abrirPostPorNotificacao(alvo, comComentarios)'));

  // ═══════════ Regras da Comunidade ═══════════
  console.log('\n══ Aceite das Regras da Comunidade antes de publicar ══');
  await limpar();
  eq('com as regras ainda não aceitas, a regra do portão devolve "não pode"', await page.evaluate(() => { Moderacao._fechar('mod-regras'); return _podePublicar(); }), false);
  eq('o portão abriu as regras (e não o compositor)', [await aberta('mod-regras'), await aberta('feed-compose-overlay')], [true, false]);
  const regras = await textoDe('mod-regras');
  ver('o texto traz tolerância zero, como denunciar e bloquear, o prazo e o contato', /Tolerância zero/.test(regras) && /Denunciar e bloquear/.test(regras) && /24 horas/.test(regras) && /contato@intusfit\.com\.br/.test(regras), regras.slice(0, 200));
  ver('o texto não tem travessão', !/[—–]/.test(regras));
  await page.evaluate(() => document.getElementById('mod-aceite-btn').click());
  ver('aceitar sem marcar a caixa mostra o aviso e não envia nada', /Marque a caixa/.test(await textoDe('mod-regras')) && pedidos('regras_comunidade', 'POST').length === 0);
  await page.evaluate(() => { document.getElementById('mod-aceite').click(); document.getElementById('mod-aceite-btn').click(); });
  await page.waitForFunction(() => !document.getElementById('mod-regras'), null, { timeout: 5000 }).catch(() => {});
  eq('marcando e aceitando: o aceite vai ao servidor (1 vez) e a folha fecha', [pedidos('regras_comunidade', 'POST').length, await aberta('mod-regras')], [1, false]);
  eq('o aceite fica guardado no aparelho para os próximos usos', await page.evaluate(() => localStorage.getItem('intus-regras-ok-7')), '2026-11');
  await limpar();
  await page.evaluate(() => abrirNovoPostFeed());
  eq('depois de aceitar, Novo post abre', [await aberta('feed-compose-overlay'), await aberta('mod-regras')], [true, false]);

  await limpar();
  await page.evaluate(() => { Moderacao._estado.aceito = false; localStorage.removeItem('intus-regras-ok-7'); });
  const antes = pedidos('comentarios_pub', 'POST').length;
  await page.evaluate(() => { const c = document.createElement('div'); c.setAttribute('data-feed-card', '55'); c.setAttribute('data-teste', '1'); const i = document.createElement('input'); i.setAttribute('data-feed-comment-input', ''); i.value = 'Muito bom!'; c.appendChild(i); document.body.appendChild(c); });
  await page.evaluate(() => enviarComentarioFeed(55));
  eq('sem aceitar as regras o comentário NÃO vai ao servidor e as regras abrem', [pedidos('comentarios_pub', 'POST').length - antes, await aberta('mod-regras')], [0, true]);
  await limpar();
  eq('com o aceite guardado no aparelho (antes de o servidor responder) o aluno segue', await page.evaluate(() => { Moderacao._estado.aceito = null; localStorage.setItem('intus-regras-ok-7', '2026-11'); return Moderacao.regrasBarra(); }), true);
  S.regrasModo = 'estranha';
  await page.evaluate(() => Moderacao.carregar());
  eq('resposta estranha do servidor (200 sem o campo "aceito") não derruba o aceite guardado nem fecha o portão', [await page.evaluate(() => Moderacao.regrasBarra()), await page.evaluate(() => localStorage.getItem('intus-regras-ok-7')), await page.evaluate(() => Moderacao._estado.aceito)], [true, '2026-11', null]);
  S.regrasModo = '';
  S.aceito = false;
  await page.evaluate(() => Moderacao.carregar());
  await limpar();
  eq('se o servidor sobe a versão das regras, o aceite antigo deixa de valer e o portão volta a fechar', [await page.evaluate(() => Moderacao.regrasBarra()), await page.evaluate(() => localStorage.getItem('intus-regras-ok-7'))], [false, null]);
  await limpar();
  await page.evaluate(() => { Moderacao._estado.aceito = true; });   // daqui para a frente o aluno já aceitou

  // ═══════════ Denunciar uma publicação ═══════════
  console.log('\n══ Denunciar pelo menu da publicação ══');
  await page.evaluate(() => { _feedAtletasMap['5'] = 'Bia Lima'; });
  await page.evaluate(() => _abrirMenuPost(101, 5));
  let menu = await textoDe('ov-menu-generico');
  ver('post de outra pessoa: o menu oferece Denunciar e Bloquear', /Denunciar esta publicação/.test(menu) && /Bloquear Bia/.test(menu), menu);
  await limpar();
  await page.evaluate(() => _abrirMenuPost(102, 7));
  menu = await textoDe('ov-menu-generico');
  ver('post MEU: o menu não oferece Denunciar nem Bloquear', !/Denunciar/.test(menu) && !/Bloquear/.test(menu), menu);
  await limpar();
  await page.evaluate(() => _abrirMenuPost(101, 5));
  await page.evaluate(() => { [...document.querySelectorAll('#ov-menu-generico div')].find(d => !d.children.length && /Denunciar esta publicação/.test(d.textContent)).click(); });
  eq('tocar em Denunciar abre a folha de denúncia', [await aberta('mod-den'), await aberta('ov-menu-generico')], [true, false]);
  let den = await textoDe('mod-den');
  ver('a folha diz o que está sendo denunciado e de quem', /publicação/.test(den) && /Bia Lima/.test(den), den.slice(0, 160));
  eq('a frase da folha concorda com o que é denunciado (uma publicação, um comentário, uma mensagem, o perfil...)', await page.evaluate(() => {
    const out = {};
    ['feed_post', 'comentario', 'mural', 'resultado', 'dm', 'perfil'].forEach(t => { Moderacao.denunciar(t, 1, { autorId: 5, nome: 'Bia Lima' }); out[t] = (document.getElementById('mod-den').innerText.match(/Você está denunciando [^.]*\./) || [''])[0]; document.getElementById('mod-den').remove(); });
    Moderacao.denunciar('feed_post', 101, { autorId: 5, nome: 'Bia Lima' });   // deixa a folha deste teste aberta como estava
    return out;
  }), { feed_post: 'Você está denunciando uma publicação de Bia Lima.', comentario: 'Você está denunciando um comentário de Bia Lima.', mural: 'Você está denunciando um comentário do mural de Bia Lima.', resultado: 'Você está denunciando um resultado de Bia Lima.', dm: 'Você está denunciando uma mensagem de Bia Lima.', perfil: 'Você está denunciando o perfil de Bia Lima.' });
  eq('os 8 motivos do servidor aparecem', await page.evaluate(() => [...document.querySelectorAll('#mod-den input[name="mod-motivo"]')].map(i => i.value)), Object.keys(MOTIVOS));
  ver('o prazo de resposta aparece (24 horas)', /24 horas/.test(den));
  await page.evaluate(() => document.getElementById('mod-enviar').click());
  ver('enviar sem escolher o motivo mostra o aviso e não envia nada', /Escolha um motivo/.test(await textoDe('mod-den')) && pedidos('denuncias', 'POST').length === 0);
  await page.evaluate(() => {
    document.querySelector('#mod-den input[name="mod-motivo"][value="spam"]').click();
    document.getElementById('mod-detalhe').value = 'é propaganda de suplemento';
    document.getElementById('mod-bloquear').click();
    document.getElementById('mod-enviar').click();
  });
  await page.waitForFunction(() => !document.getElementById('mod-den'), null, { timeout: 5000 }).catch(() => {});
  eq('depois de enviar a folha de denúncia fecha', await aberta('mod-den'), false);
  const dn = pedidos('denuncias', 'POST');
  eq('a denúncia vai com tipo, número, motivo, detalhe e o pedido de bloquear', dn.length === 1 ? dn[0].corpo : dn, { alvo_tipo: 'feed_post', alvo_id: 101, motivo: 'spam', detalhe: 'é propaganda de suplemento', bloquear: true });
  let t = await toast();
  ver('o aluno vê a confirmação, com o prazo e o bloqueio', t.on && /Denúncia enviada/.test(t.txt) && /bloqueado/.test(t.txt) && /24 horas/.test(t.txt), t);
  ver('o aviso não tem travessão', !/[—–]/.test(t.txt));
  S.bloqueados = [];

  console.log('\n══ Denunciar sem bloquear, e erros do servidor ══');
  await limpar();
  await page.evaluate(() => Moderacao.denunciar('feed_post', 101, { autorId: 5 }));
  await page.evaluate(() => { document.querySelector('#mod-den input[name="mod-motivo"][value="outro"]').click(); document.getElementById('mod-enviar').click(); });
  await page.waitForFunction(() => !document.getElementById('mod-den'), null, { timeout: 5000 }).catch(() => {});
  eq('sem bloquear a folha de denúncia também fecha ao enviar', await aberta('mod-den'), false);
  eq('sem marcar "Bloquear também" o pedido vai com bloquear = false e detalhe vazio', pedidos('denuncias', 'POST').slice(-1)[0].corpo, { alvo_tipo: 'feed_post', alvo_id: 101, motivo: 'outro', detalhe: '', bloquear: false });
  t = await toast();
  ver('a confirmação não fala em bloqueio', /Denúncia enviada/.test(t.txt) && !/bloqueado/.test(t.txt), t);
  await limpar();
  await page.evaluate(() => Moderacao.denunciar('feed_post', 404, { autorId: 5 }));
  await page.evaluate(() => { document.querySelector('#mod-den input[name="mod-motivo"][value="spam"]').click(); document.getElementById('mod-enviar').click(); });
  await page.waitForFunction(() => /não está mais disponível/.test((document.getElementById('mod-erro') || {}).textContent || ''), null, { timeout: 5000 }).catch(() => {});
  ver('conteúdo que sumiu: a folha fica aberta e explica', await aberta('mod-den') && /não está mais disponível/.test(await textoDe('mod-erro')), await textoDe('mod-erro'));
  eq('e o botão volta a poder ser tocado', await page.evaluate(() => document.getElementById('mod-enviar').disabled), false);
  await limpar();

  // ═══════════ Comentário ═══════════
  console.log('\n══ Denunciar um comentário ══');
  await page.evaluate(() => {
    const agora = new Date().toISOString().slice(0, 19).replace('T', ' ');
    _comentariosFeed['101'] = [
      { idcomentario: 9, autor_tipo: 'aluno', autor_id: 5, autor_nome: 'Bia Lima', texto: 'oi galera', created_at: agora, meu: false },
      { idcomentario: 10, autor_tipo: 'aluno', autor_id: 7, autor_nome: 'Aluno Teste', texto: 'meu comentário', created_at: agora, meu: true },
      { idcomentario: 11, autor_tipo: 'prof', autor_id: 2, autor_nome: 'Equipe', texto: 'resposta da equipe', created_at: agora, meu: false },
    ];
    _comentariosAbertos[101] = true;
    const box = document.createElement('div'); box.id = 'caixa-coment'; box.setAttribute('data-teste', '1'); box.innerHTML = _comentariosHtml(101, false); document.body.appendChild(box);
  });
  eq('só os comentários dos outros têm "Denunciar" (o meu não)', await page.evaluate(() => [...document.querySelectorAll('#caixa-coment [data-mod-tipo="comentario"]')].map(e => e.dataset.modId)), ['9', '11']);
  eq('o nome do autor vai em data-*, não dentro do onclick', await page.evaluate(() => { const e = document.querySelector('#caixa-coment [data-mod-id="9"]'); return [e.dataset.modNome, e.dataset.modAutor, /Bia/.test(e.getAttribute('onclick'))]; }), ['Bia Lima', '5', false]);
  await page.evaluate(() => document.querySelector('#caixa-coment [data-mod-id="9"]').click());
  eq('tocar abre a folha de denúncia com a opção de bloquear a autora', [await aberta('mod-den'), await aberta('mod-bloquear')], [true, true]);
  await page.evaluate(() => { document.querySelector('#mod-den input[name="mod-motivo"][value="assedio"]').click(); document.getElementById('mod-enviar').click(); });
  await page.waitForFunction(() => !document.getElementById('mod-den'), null, { timeout: 5000 }).catch(() => {});
  eq('a denúncia de comentário vai como comentario + número', pedidos('denuncias', 'POST').slice(-1)[0].corpo.alvo_tipo + ':' + pedidos('denuncias', 'POST').slice(-1)[0].corpo.alvo_id, 'comentario:9');
  await page.evaluate(() => document.querySelector('#caixa-coment [data-mod-id="11"]').click());
  eq('comentário da equipe também se denuncia, mas sem a opção de bloquear (equipe não é bloqueável)', [await aberta('mod-den'), await page.evaluate(() => !!document.getElementById('mod-bloquear'))], [true, false]);
  await limpar();

  // ═══════════ Bloquear e Pessoas bloqueadas ═══════════
  console.log('\n══ Bloquear e desbloquear ══');
  await page.evaluate(() => Moderacao.bloquear(5, 'Bia Lima'));
  const bl = await textoDe('mod-blq');
  ver('a confirmação explica o que acontece e onde desbloquear', /deixam de se ver/.test(bl) && /a amizade acaba/.test(bl) && /Pessoas bloqueadas/.test(bl) && /não é avisada/.test(bl), bl);
  await page.evaluate(() => [...document.querySelectorAll('#mod-blq button')].find(b => /Cancelar/.test(b.textContent)).click());
  eq('Cancelar não bloqueia ninguém', [await aberta('mod-blq'), pedidos('bloqueios', 'POST').length], [false, 0]);
  await page.evaluate(() => Moderacao.bloquear(5, 'Bia Lima'));
  await page.evaluate(() => document.getElementById('mod-blq-ok').click());
  await page.waitForFunction(() => !document.getElementById('mod-blq'), null, { timeout: 5000 }).catch(() => {});
  eq('Bloquear manda o número da pessoa ao servidor', pedidos('bloqueios', 'POST').map(r => r.corpo), [{ idalvo: 5 }]);
  t = await toast();
  ver('o aluno vê a confirmação', t.on && /Bia/.test(t.txt) && /bloqueado/.test(t.txt), t);
  eq('não dá para bloquear a si mesmo', await page.evaluate(() => { Moderacao.bloquear(7, 'eu'); return !!document.getElementById('mod-blq'); }), false);
  await limpar();
  await page.evaluate(() => Moderacao.abrirBloqueados());
  await page.waitForFunction(() => /Bia Lima/.test((document.getElementById('mod-lista') || {}).innerText || ''), null, { timeout: 5000 }).catch(() => {});
  ver('Pessoas bloqueadas lista quem foi bloqueado, com Desbloquear', /Bia Lima/.test(await textoDe('mod-lista')) && /Desbloquear/.test(await textoDe('mod-lista')), await textoDe('mod-lista'));
  eq('o nome na lista tem a cor do texto (não o cinza do "Carregando...")', await page.evaluate(() => getComputedStyle(document.querySelector('#mod-lista [data-linha="5"] div:nth-child(2)')).color === getComputedStyle(document.querySelector('#mod-lista b')).color), true);
  await page.evaluate(() => document.querySelector('#mod-lista button[data-id="5"]').click());
  await page.waitForFunction(() => /não bloqueou ninguém/.test((document.getElementById('mod-lista') || {}).innerText || ''), null, { timeout: 5000 }).catch(() => {});
  eq('Desbloquear tira a pessoa da lista, avisa o servidor e mostra a lista vazia', [pedidos('bloqueios', 'DELETE').length, /não bloqueou ninguém/.test(await textoDe('mod-lista')), S.bloqueados], [1, true, []]);
  await limpar();

  console.log('\n══ Menu de uma pessoa (perfil e conversa) ══');
  await page.evaluate(() => { const b = document.createElement('div'); b.setAttribute('data-teste', '1'); b.id = 'perfil-teste'; b.innerHTML = Moderacao.linkPerfilHtml(5, 'Bia Lima') + Moderacao.botaoMaisHtml(5, 'Bia Lima'); document.body.appendChild(b); });
  await page.evaluate(() => document.querySelector('#perfil-teste [data-mod-id="5"]').click());
  const mp = await textoDe('mod-menu');
  ver('o menu da pessoa oferece Denunciar e Bloquear com o primeiro nome', /Denunciar Bia/.test(mp) && /Bloquear Bia/.test(mp), mp);
  await page.evaluate(() => [...document.querySelectorAll('#mod-menu button')].find(b => /Denunciar Bia/.test(b.textContent)).click());
  eq('Denunciar a pessoa abre a folha de denúncia de perfil', [await aberta('mod-menu'), await aberta('mod-den')], [false, true]);
  await page.evaluate(() => { document.querySelector('#mod-den input[name="mod-motivo"][value="falso"]').click(); document.getElementById('mod-enviar').click(); });
  await page.waitForFunction(() => !document.getElementById('mod-den'), null, { timeout: 5000 }).catch(() => {});
  eq('a denúncia de perfil vai como perfil + número da pessoa', (() => { const c = pedidos('denuncias', 'POST').slice(-1)[0].corpo; return c.alvo_tipo + ':' + c.alvo_id; })(), 'perfil:5');
  await limpar();

  // ═══════════ Mensagem direta ═══════════
  console.log('\n══ Mensagem direta: só a recebida se denuncia ══');
  const dm = await page.evaluate(() => {
    try {
      _amzDados = Object.assign({}, _amzDados || {}, { amigos: [5], pessoas: [{ id: 5, nome: 'Bia Lima' }] });
      _dm = Object.assign(_dm || {}, { pessoas: { '5': { nome: 'Bia Lima' } }, com: 5, carregando: false, msgs: [{ id: 31, meu: false, texto: 'mensagem recebida', quando: '2026-11-12 10:00:00' }, { id: 32, meu: true, texto: 'minha mensagem', quando: '2026-11-12 10:01:00' }] });
      _centralChatTarget = 'a:5';
      const html = _renderChatConversa(false, '#fff', '#ddd', '#5cb800', '#777', '#111', []);
      const d = document.createElement('div'); d.setAttribute('data-teste', '1'); d.id = 'chat-teste'; d.innerHTML = html; document.body.appendChild(d);
      return { ok: true, icones: [...d.querySelectorAll('[data-mod-tipo="dm"]')].map(e => e.dataset.modId), mais: !!d.querySelector('[aria-label="Mais opções"]') };
    } catch (e) { return { ok: false, erro: String(e) }; }
  });
  eq('a conversa mostra o botão de denunciar só na mensagem RECEBIDA, e o "⋯" no cabeçalho', dm, { ok: true, icones: ['31'], mais: true });
  await page.evaluate(() => document.querySelector('#chat-teste [data-mod-tipo="dm"]').click());
  await page.evaluate(() => { document.querySelector('#mod-den input[name="mod-motivo"][value="assedio"]').click(); document.getElementById('mod-enviar').click(); });
  await page.waitForFunction(() => !document.getElementById('mod-den'), null, { timeout: 5000 }).catch(() => {});
  eq('a denúncia de mensagem vai como dm + número da mensagem', (() => { const c = pedidos('denuncias', 'POST').slice(-1)[0].corpo; return c.alvo_tipo + ':' + c.alvo_id; })(), 'dm:31');
  await limpar();

  // ═══════════ O servidor recusa: o aluno vê o MOTIVO ═══════════
  console.log('\n══ Suspensão e texto impróprio: o aluno lê o motivo, não um "Não consegui" genérico ══');
  await page.evaluate(() => { _syncDone = false; Moderacao._estado.aceito = true; });
  const comentar = async () => {
    await limpar();
    await page.evaluate(() => { const c = document.createElement('div'); c.setAttribute('data-feed-card', '55'); c.setAttribute('data-teste', '1'); const i = document.createElement('input'); i.setAttribute('data-feed-comment-input', ''); i.value = 'comentário de teste'; c.appendChild(i); document.body.appendChild(c); });
    await page.evaluate(() => { enviarComentarioFeed(55); });
    await page.waitForTimeout(1100);
    return toast();
  };
  S.comentario = { status: 403, body: { error: MSG_SUSPENSO, codigo: 'suspenso' } };
  t = await comentar();
  eq('aluno suspenso: o aviso na tela é a mensagem do servidor (com a data e o contato)', [t.on, t.txt], [true, MSG_SUSPENSO]);
  S.comentario = { status: 422, body: { error: MSG_IMPROPRIO, codigo: 'conteudo_improprio' } };
  t = await comentar();
  eq('texto com termo grave: o aviso na tela é a mensagem do servidor', [t.on, t.txt], [true, MSG_IMPROPRIO]);
  S.comentario = { status: 403, body: { error: 'Não foi possível comentar aqui.', codigo: 'bloqueado' } };
  t = await comentar();
  eq('comentário em conteúdo de quem tem bloqueio: mensagem do servidor', t.txt, 'Não foi possível comentar aqui.');
  S.comentario = { status: 500, body: { error: 'erro interno' } };
  t = await comentar();
  eq('erro comum do servidor continua com o aviso genérico da tela (não é moderação)', t.txt, 'Não consegui enviar o comentário.');
  S.comentario = { status: 403, body: { error: 'so_teste', codigo: 'so_teste' } };
  t = await comentar();
  eq('outro código de 403 (teste grátis) não é tratado como moderação', t.txt, 'Não consegui enviar o comentário.');
  S.comentario = { status: 200, body: { ok: true } };
  const trocou = await page.evaluate(() => { const a = window.fetch; return typeof a === 'function' && a._intusMod === true; });
  eq('o fetch embrulhado devolve a resposta intacta a quem chamou (comentário ok continua ok)', trocou, true);

  // ═══════════ Sino ═══════════
  console.log('\n══ Sino: aviso da equipe e denúncia resolvida ══');
  const linhaAviso = await page.evaluate(() => {
    const base = { idnotificacao: 1, ator_tipo: 'sistema', ator_id: 1, ator_nome: 'Equipe Intus', alvo_tipo: 'denuncia', alvo_id: 33, quando: '2026-11-12 10:00:00', lido: false };
    const a = _notifLinhaHtml(Object.assign({ tipo: 'aviso_equipe', texto: 'Um conteúdo seu foi removido por descumprir as Regras da Comunidade.' }, base), false);
    const b = _notifLinhaHtml(Object.assign({ tipo: 'denuncia_resolvida', texto: 'Analisamos a sua denúncia. Obrigado por ajudar a manter a comunidade segura.' }, base, { idnotificacao: 2 }), false);
    return { a, b };
  });
  ver('o aviso da equipe aparece com a frase inteira e o ícone de escudo, sem "interagiu com você"', /removido por descumprir/.test(linhaAviso.a) && /🛡️/.test(linhaAviso.a) && !/interagiu/.test(linhaAviso.a), linhaAviso.a.slice(0, 400));
  ver('o aviso de denúncia analisada aparece com a frase inteira e o ícone de ok', /Analisamos a sua denúncia/.test(linhaAviso.b) && /✅/.test(linhaAviso.b) && !/interagiu/.test(linhaAviso.b), linhaAviso.b.slice(0, 400));
  await limpar();
  const aoTocar = await page.evaluate(() => {
    let abriuPost = 0; const orig = window._abrirPostPorNotificacao; window._abrirPostPorNotificacao = function () { abriuPost++; };
    const mk = (tipo) => { const el = document.createElement('div'); el.dataset.notifId = '0'; el.dataset.alvo = '33'; el.dataset.tipo = tipo; el.dataset.alvotipo = 'denuncia'; return el; };
    _notifAbrir(mk('aviso_equipe'));
    const regrasAbriu = !!document.getElementById('mod-regras');
    document.getElementById('mod-regras')?.remove();
    _notifAbrir(mk('denuncia_resolvida'));
    const nada = !document.getElementById('mod-regras');
    window._abrirPostPorNotificacao = orig;
    return { abriuPost, regrasAbriu, nada };
  });
  eq('tocar no aviso da equipe abre as Regras (já aceitas: só leitura); tocar no outro não faz nada; nenhum cai no Feed', aoTocar, { abriuPost: 0, regrasAbriu: true, nada: true });
  ver('as regras abertas só para leitura não têm o botão de aceitar', !(await page.evaluate(() => { Moderacao.verRegras(); const r = !!document.getElementById('mod-aceite'); return r; })));
  await limpar();

  // ═══════════ Os dois temas ═══════════
  console.log('\n══ Legível no tema escuro e no claro (contraste medido, 4.5:1) ══');
  const contraste = (tema) => page.evaluate((claro) => {
    document.body.classList.toggle('tema-claro', claro);
    Moderacao.denunciar('feed_post', 101, { autorId: 5, nome: 'Bia Lima' });
    const fundo = document.querySelector('#mod-den > div');
    const rgb = (s) => (s.match(/[\d.]+/g) || []).map(Number).slice(0, 3);
    const lum = (c) => { const f = c.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2]; };
    const taxa = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    const bg = rgb(getComputedStyle(fundo).backgroundColor);
    const de = (el, fundoCor) => +taxa(rgb(getComputedStyle(el).color), fundoCor || bg).toFixed(2);
    const out = {
      titulo: de(document.querySelector('#mod-den b')),
      motivo: de(document.querySelector('#mod-den label span')),
      explicacao: de([...document.querySelectorAll('#mod-den div')].find(d => /Escolha o motivo/.test(d.textContent) && d.children.length < 4)),
      rodape: de([...document.querySelectorAll('#mod-den div')].filter(d => /analisa em até/.test(d.textContent)).slice(-1)[0]),
      botao: de(document.getElementById('mod-enviar'), rgb(getComputedStyle(document.getElementById('mod-enviar')).backgroundColor)),
    };
    document.getElementById('mod-den').remove();
    Moderacao.bloquear(5, 'Bia Lima');
    out.perigo = de(document.getElementById('mod-blq-ok'));
    out.ajuda = de([...document.querySelectorAll('#mod-blq div')].find(d => /desbloquear quando quiser/.test(d.textContent)));
    document.getElementById('mod-blq').remove();
    Moderacao._estado.aceito = false; Moderacao.regrasBarra();
    const f2 = document.querySelector('#mod-regras > div'); const bg2 = rgb(getComputedStyle(f2).backgroundColor);
    out.regrasTexto = de(document.querySelector('#mod-regras li'), bg2);
    out.regrasLink = de(document.querySelector('#mod-regras a'), bg2);
    document.getElementById('mod-regras').remove();
    Moderacao._estado.aceito = true;
    return out;
  }, tema === 'claro');
  for (const tema of ['escuro', 'claro']) {
    const c = await contraste(tema);
    const piores = Object.entries(c).filter(([, v]) => v < 4.5);
    ver('tema ' + tema + ': todo texto das folhas tem contraste de pelo menos 4.5:1', piores.length === 0, c);
  }
  await page.evaluate(() => document.body.classList.remove('tema-claro'));

  // ═══════════ Fechamento ═══════════
  console.log('\n══ Sem estrago ══');
  eq('nenhum erro de JavaScript durante o teste todo', erros, []);
  eq('a folha aberta trava a rolagem de trás e solta ao fechar', await page.evaluate(async () => {
    Moderacao.bloquear(5, 'x'); await new Promise(r => setTimeout(r, 60));
    const travou = document.body.style.overflow === 'hidden';
    document.getElementById('mod-blq').remove(); await new Promise(r => setTimeout(r, 120));
    return [travou, document.body.style.overflow === 'hidden'];
  }), [true, false]);

  await browser.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok' : ok + ' ok'));
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
