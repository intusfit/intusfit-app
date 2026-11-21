// Botão "Denunciar" do app do aluno (exigência da Apple 1.2 e do Google para conteúdo de usuários): no menu "⋯" do post de OUTRA pessoa e na linha de cada comentário
// de outro aluno; nunca no próprio conteúdo. Só motivos de uma lista, vai para catalogo.php?action=denuncia, e o app avisa o resultado.
//   cd testes && INTUS_DIR=../app/painel node denunciar.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const post = (id, atleta) => ({ idpost: id, idatleta: atleta, created_at: '2026-11-21 10:00:00', legenda: 'Treino ' + id, midias: [], localizacao: '' });
const com = (id, idpost, autor, nome, meu, tipo) => ({ idcomentario: id, resposta_a: null, autor_tipo: tipo || 'aluno', autor_id: autor, autor_nome: nome, texto: 'comentário ' + id, created_at: '2026-11-21 10:05:00', meu: meu });

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8173, '127.0.0.1', r));
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  const enviados = []; let respostaDenuncia = { status: 200, body: { ok: true } };
  await page.route(/\/app\/api\/catalogo\.php\?action=denuncia/, r => { enviados.push(JSON.parse(r.request().postData() || '{}')); return r.fulfill({ status: respostaDenuncia.status, contentType: 'application/json', body: JSON.stringify(respostaDenuncia.body) }); });
  await page.route(/\/app\/api\/catalogo\.php\?action=feed_posts/, r => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ posts: [post(9, 9), post(8, 7)], temMais: false, atletas: { 9: 'Aluna Nove', 7: 'Aluna Teste' }, avatars: {} }) }));
  await page.route(/\/app\/api\/catalogo\.php\?action=comentarios_pub/, r => r.request().method() === 'GET'
    ? r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ 9: [com(1, 9, 9, 'Aluna Nove', false), com(2, 9, 7, 'Aluna Teste', true), com(3, 9, 1, 'Professor', false, 'prof')] }) })
    : r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }));
  await page.route(/\/app\/api\/(?!catalogo\.php\?action=(feed_posts|comentarios_pub|denuncia))/, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); });
  await page.goto('http://127.0.0.1:8173/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(800);
  await page.evaluate(() => { navegar('feed'); });
  for (let i = 0; i < 40; i++) { if (await page.evaluate(() => document.querySelectorAll('#v-feed [data-feed-card]').length >= 2)) break; await page.waitForTimeout(250); }
  const menu = async (idpost, dono) => { await page.evaluate(([i, d]) => _abrirMenuPost(i, d), [idpost, dono]); await page.waitForTimeout(150); return page.evaluate(() => (document.getElementById('ov-menu-generico') || { innerText: '' }).innerText.replace(/\s+/g, ' ')); };

  console.log('\n══ Menu do post ══');
  let t = await menu(9, 9);
  eq('post de outra pessoa oferece "Denunciar publicação" (e continua oferecendo "Não ver posts")', [/Denunciar publicação/.test(t), /Não ver posts de Aluna/.test(t)], [true, true]);
  await page.evaluate(() => closeModal());
  t = await menu(8, 7);
  eq('o PRÓPRIO post não tem Denunciar (tem Excluir)', [/Denunciar/.test(t), /Excluir post/.test(t)], [false, true]);
  await page.evaluate(() => closeModal());

  console.log('\n══ Escolher o motivo ══');
  await page.evaluate(() => _denunciarAbrir('post', 9)); await page.waitForTimeout(150);
  eq('cinco motivos, só da lista', await page.$$eval('[data-denuncia-motivo]', es => es.map(e => e.getAttribute('data-denuncia-motivo') + '=' + e.textContent.trim())),
    ['spam=Spam ou propaganda', 'sexual=Conteúdo sexual', 'violencia=Violência ou ameaça', 'ofensa=Ofensa ou assédio', 'outro=Outro motivo']);
  await page.click('[data-denuncia-motivo="sexual"]'); await page.waitForTimeout(400);
  eq('envia alvo, id e motivo', enviados.slice(-1)[0], { alvo_tipo: 'post', alvo_id: 9, motivo: 'sexual' });
  eq('o modal fecha e o aluno recebe a confirmação', [await page.evaluate(() => !document.getElementById('ov-menu-generico')), await page.evaluate(() => /Denúncia enviada/.test(document.body.innerText))], [true, true]);

  console.log('\n══ Comentários ══');
  await page.evaluate(() => { toggleComentariosFeed(9); });
  for (let i = 0; i < 20; i++) { if (await page.evaluate(() => /comentário 1/.test(document.body.innerText))) break; await page.waitForTimeout(250); }
  const quem = await page.evaluate(() => [...document.querySelectorAll('#v-feed [onclick^="_denunciarAbrir(\'comentario\'"]')].map(e => e.getAttribute('onclick')));
  eq('só o comentário de OUTRO aluno tem Denunciar (não o meu, não o do professor)', quem, ["_denunciarAbrir('comentario',1)"]);
  await page.evaluate(() => _denunciarAbrir('comentario', 1)); await page.waitForTimeout(150);
  await page.click('[data-denuncia-motivo="spam"]'); await page.waitForTimeout(400);
  eq('denúncia de comentário vai com o id do comentário', enviados.slice(-1)[0], { alvo_tipo: 'comentario', alvo_id: 1, motivo: 'spam' });

  console.log('\n══ Respostas do servidor ══');
  respostaDenuncia = { status: 404, body: { error: 'conteúdo não encontrado' } };
  await page.evaluate(() => _denunciarEnviar('post', 9, 'spam')); await page.waitForTimeout(400);
  eq('conteúdo que já saiu do ar: aviso claro', await page.evaluate(() => /já não está mais disponível/.test(document.body.innerText)), true);
  respostaDenuncia = { status: 500, body: { error: 'x' } };
  await page.evaluate(() => _denunciarEnviar('post', 9, 'spam')); await page.waitForTimeout(400);
  eq('erro do servidor: o aluno sabe que não foi enviada', await page.evaluate(() => /Não consegui enviar a denúncia/.test(document.body.innerText)), true);
  eq('sem erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
