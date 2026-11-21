// Início do painel: card "Conteúdo sinalizado" (controle de spam, pornografia, violência e ofensa). O texto segue visível até o administrador decidir:
// Manter, Ocultar, Mostrar de novo e Excluir chamam as rotas certas, e quem não é administrador não vê nem consulta.
//   cd testes && INTUS_DIR=../app/painel node moderacao_painel.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const item = (idmod, extra) => Object.assign({ idmod, alvo_tipo: 'comentario', alvo_id: 100 + idmod, idatleta: 7, autor: 'Fulano Teste', categorias: ['violencia'], motivo: 'Violência: ameaça', trecho: 'vou te matar',
  texto: 'vou te matar <b>agora</b>', onde: 'Comentário em post do Feed', status: 'pendente', quando: '2026-11-21 10:30:00' }, extra);

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/index.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8171, '127.0.0.1', r));
  const b = await chromium.launch();

  async function abrir(admin) {
    const estado = { pend: [item(1), item(2, { alvo_tipo: 'post', alvo_id: 55, categorias: ['pornografia', 'spam'], motivo: 'Pornografia: conteúdo | Spam: link', texto: 'manda nudes www.x.com', onde: 'Post no Feed', autor: 'Beltrana' })], ocultos: [], chamadas: [], moderacaoGets: 0, alertas: [{ idalerta: 7, provedor: 'apple', nome: 'Carlos Lima', email: 'c@gmail.com', motivos: ['nome_igual', 'email_oculto'], candidatos: [{ tipo: 'aluno', id: 2, nome: 'Carlos Lima', email: 'carlos@exemplo.com', por: 'nome' }], quando: '2026-11-21 10:00:00' }] };
    const p = await (await b.newContext({ viewport: { width: 1300, height: 1400 }, serviceWorkers: 'block' })).newPage();
    const erros = []; p.on('pageerror', e => erros.push(e.message));
    await p.route(u => u.href.includes('.php'), r => {
      const req = r.request(), u = req.url(), m = req.method();
      const json = (o, s = 200) => r.fulfill({ status: s, contentType: 'application/json', body: JSON.stringify(o) });
      if (u.includes('action=login_alertas')) {
        if (m === 'GET') { estado.alertasGets = (estado.alertasGets || 0) + 1; return json({ pendentes: estado.alertas }); }
        const c = JSON.parse(req.postData() || '{}'); estado.chamadas.push(['POST login_alertas', c.idalerta]);
        estado.alertas = estado.alertas.filter(x => x.idalerta !== c.idalerta);
        return json({ ok: true });
      }
      if (u.includes('action=moderacao')) {
        if (m === 'GET') { estado.moderacaoGets++; return json({ pendentes: estado.pend, ocultos: estado.ocultos }); }
        const c = JSON.parse(req.postData() || '{}'); estado.chamadas.push(['POST moderacao', c.idmod, c.acao]);
        const i = estado.pend.concat(estado.ocultos).find(x => x.idmod === c.idmod);
        if (c.acao === 'manter') { estado.pend = estado.pend.filter(x => x !== i); }
        if (c.acao === 'ocultar') { estado.pend = estado.pend.filter(x => x !== i); estado.ocultos.push(Object.assign({}, i, { status: 'oculto' })); }
        if (c.acao === 'restaurar') { estado.ocultos = estado.ocultos.filter(x => x !== i); }
        return json({ ok: true });
      }
      if (m === 'DELETE' && (u.includes('action=comentarios_pub') || u.includes('action=feed_posts'))) {
        estado.chamadas.push(['DELETE', u.replace(/^.*action=/, '')]);
        const id = Number((u.match(/id=(\d+)/) || [])[1]);
        estado.pend = estado.pend.filter(x => x.alvo_id !== id); estado.ocultos = estado.ocultos.filter(x => x.alvo_id !== id);
        return json({ ok: true });
      }
      return json([]);
    });
    await p.addInitScript((adm) => { localStorage.setItem('mx-user', JSON.stringify({ idusuario: adm ? 1 : 99, nome: adm ? 'Luiz' : 'Professor', admin: adm, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); }, admin);
    p.on('dialog', d => d.accept());
    await p.goto('http://127.0.0.1:8171/index.html', { waitUntil: 'networkidle' });
    // o pré-carregamento do Início é lento (várias chamadas em fila): espera a consulta de moderação sair em vez de contar segundos
    for (let i = 0; i < 120 && admin && !estado.moderacaoGets; i++) await p.waitForTimeout(250);
    await p.waitForTimeout(admin ? 800 : 6000);
    return { p, estado, erros };
  }
  const texto = p => p.evaluate(() => (document.getElementById('mod-card') || { innerText: '' }).innerText.replace(/\s+/g, ' '));

  console.log('\n══ Administrador ══');
  let { p, estado, erros } = await abrir(true);
  let t = await texto(p);
  ver('o card aparece com a contagem', /2 conteúdos sinalizados para você decidir/.test(t), t);
  ver('mostra autor, onde, o texto e o motivo', /Fulano Teste/.test(t) && /Comentário em post do Feed/.test(t) && /Violência: ameaça/.test(t) && /Beltrana/.test(t) && /Post no Feed/.test(t), t);
  ver('as categorias viram etiquetas (violência; pornografia e spam)', /Violência/.test(t) && /Pornografia/.test(t) && /Spam/.test(t), t);
  ver('o texto do aluno é mostrado como texto (HTML não é interpretado)', await p.evaluate(() => !document.querySelector('#mod-card b') || document.querySelector('#mod-card').innerText.includes('<b>agora</b>')));
  eq('cada item tem Manter, Ocultar e Excluir', await p.$$eval('#mod-card button', bs => bs.map(x => x.textContent.trim())), ['Manter', 'Ocultar', 'Excluir', 'Manter', 'Ocultar', 'Excluir']);

  await p.click('#mod-card button:has-text("Manter") >> nth=0'); await p.waitForTimeout(700);
  eq('Manter chama a rota e o item sai da fila', [estado.chamadas.slice(-1)[0], /1 conteúdo sinalizado/.test(await texto(p))], [['POST moderacao', 1, 'manter'], true]);

  await p.click('#mod-card button:has-text("Ocultar") >> nth=0'); await p.waitForTimeout(700);
  t = await texto(p);
  eq('Ocultar chama a rota, o item sai da fila e vai para "Ocultos por você"', [estado.chamadas.slice(-1)[0], /Nenhum conteúdo aguardando decisão/.test(t), /Ocultos por você \(1\)/.test(t)], [['POST moderacao', 2, 'ocultar'], true, true]);

  await p.click('#mod-card summary'); await p.waitForTimeout(200);
  eq('o oculto oferece "Mostrar de novo" e "Excluir"', await p.$$eval('#mod-card details button', bs => bs.map(x => x.textContent.trim())), ['Mostrar de novo', 'Excluir']);
  await p.click('#mod-card button:has-text("Mostrar de novo")'); await p.waitForTimeout(700);
  eq('Mostrar de novo chama a rota e o item deixa a lista de ocultos', [estado.chamadas.slice(-1)[0], estado.ocultos.length], [['POST moderacao', 2, 'restaurar'], 0]);
  ver('com a fila vazia e nada oculto o card some', !(await p.$('#mod-card')));
  eq('sem erro de script', erros, []);
  await p.close();

  ({ p, estado, erros } = await abrir(true));
  await p.click('#mod-card button:has-text("Excluir") >> nth=1'); await p.waitForTimeout(700);
  eq('Excluir post usa a rota de exclusão do Feed (a de sempre) com o id do post', estado.chamadas.slice(-1)[0], ['DELETE', 'feed_posts&id=55']);
  await p.click('#mod-card button:has-text("Excluir") >> nth=0'); await p.waitForTimeout(700);
  eq('Excluir comentário usa a rota de exclusão de comentários com o id do comentário', estado.chamadas.slice(-1)[0], ['DELETE', 'comentarios_pub&id=101']);
  eq('depois de excluir os dois, a fila está vazia', estado.pend.length, 0);
  await p.close();

  console.log('\n══ Possíveis cadastros duplicados (Apple) ══');
  ({ p, estado, erros } = await abrir(true));
  for (let i = 0; i < 40 && !estado.alertasGets; i++) await p.waitForTimeout(250);
  await p.waitForTimeout(500);
  const dt = await p.evaluate(() => (document.getElementById('dup-card') || { innerText: '' }).innerText.replace(/\s+/g, ' '));
  eq('o administrador vê o alerta com o nome, o motivo e o aluno parecido', [/Carlos Lima/.test(dt), /E-mail oculto/.test(dt), /Nome igual/.test(dt), /Aluno #2/.test(dt)], [true, true, true, true]);
  await p.click('#dup-card button:has-text("Marcar como resolvido")'); await p.waitForTimeout(700);
  eq('Marcar como resolvido chama a rota e o card some', [estado.chamadas.slice(-1)[0], !!(await p.$('#dup-card'))], [['POST login_alertas', 7], false]);
  await p.close();

  console.log('\n══ Quem não é administrador ══');
  ({ p, estado, erros } = await abrir(false));
  eq('não vê o card e não consulta a rota', [!!(await p.$('#mod-card')), !!(await p.$('#dup-card')), estado.moderacaoGets, estado.alertasGets || 0], [false, false, 0, 0]);
  eq('sem erro de script', erros, []);
  await p.close();

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
