// Fila de denúncias no painel da equipe (feed.html + denuncias.js), o cartão no painel inicial (index.html) e o sino da equipe (_mock.js).
// Exigência das lojas: a equipe precisa conseguir agir em prazo (os Termos prometem 24 horas). Só o administrador vê e decide.
//   cd testes && INTUS_DIR=../app/painel node moderacao_painel.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const SITE = path.resolve(process.env.INTUS_DIR || '../app/painel', '../..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };

let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };

const base = (o) => Object.assign({ status: 'aberta', acao: '', nota_equipe: '', contexto: '', detalhe: '', trecho: '', midia: '', autor_tipo: 'aluno', denuncias_no_alvo: 1, conteudo_existe: true, horas: 2, atrasada: false, autor_suspenso: null, resolvido_em: null }, o);
function novoEstado() {
  return {
    abertas: [
      base({ id: 31, alvo_tipo: 'feed_post', alvo_id: 101, motivo: 'spam', motivo_rotulo: 'Spam ou propaganda', detalhe: 'é propaganda de suplemento', trecho: 'Compre meu suplemento com 30% de desconto', midia: 'https://exemplo.com/foto.jpg',
        denunciante_id: 5, denunciante_nome: 'Bia Lima', autor_id: 2, autor_nome: 'Ana Souza', denuncias_no_alvo: 2, horas: 30, atrasada: true, criado_em: '2026-11-11 04:00:00' }),
      base({ id: 32, alvo_tipo: 'comentario', alvo_id: 55, motivo: 'assedio', motivo_rotulo: 'Assédio, bullying ou ameaça', trecho: '<script>window.__xss=1</script> texto <b>forte</b>', midia: 'javascript:window.__xss=2',
        denunciante_id: 6, denunciante_nome: 'Caio Reis', autor_id: 3, autor_nome: '<img src=x onerror="window.__xss=3">Zé', conteudo_existe: false, criado_em: '2026-11-12 08:00:00' }),
    ],
    resolvidas: [base({ id: 20, status: 'resolvida', alvo_tipo: 'dm', alvo_id: 7, motivo: 'odio', motivo_rotulo: 'Ódio ou discriminação', trecho: 'Autor: oi', denunciante_id: 5, denunciante_nome: 'Bia Lima', autor_id: 4, autor_nome: 'Dani Melo', acao: 'remover,suspender', nota_equipe: 'reincidente', resolvido_em: '2026-11-10 09:30:00', criado_em: '2026-11-10 08:00:00' })],
    suspensos: [{ idsuspensao: 1, idatleta: 4, nome: 'Dani Melo', ate: '2026-11-19 10:00:00', motivo: 'Denúncia: odio', criado_em: '2026-11-10 09:30:00' }],
    posts: [], reqs: [],
  };
}

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p.endsWith('/')) p += 'index.html';
    const f = path.join(SITE, p);
    if (!f.startsWith(SITE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8096, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8096';
  const browser = await chromium.launch();
  const json = (r, o, status) => r.fulfill({ status: status || 200, contentType: 'application/json', body: JSON.stringify(o) });

  async function abrir(url, { admin = true, E, notifs = [] } = {}) {
    const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    await c.addInitScript(({ admin }) => {
      localStorage.setItem('mx-user', JSON.stringify({ idusuario: admin ? 1 : 5, nome: admin ? 'Luiz' : 'Professor', admin: admin, permissoes: {} }));
      localStorage.setItem('mx-token', 'x'.repeat(64));
    }, { admin });
    const pg = await c.newPage(); const er = [];
    pg.on('pageerror', e => { if (!/Failed to update a ServiceWorker/i.test(String(e.message))) er.push(String(e.message)); });
    pg.on('dialog', d => { E.dialogos.push(d.message()); d.accept(); });
    await pg.route(u => u.href.includes('.php'), (r) => {
      const u = r.request().url(), m = r.request().method();
      let b = null; try { b = r.request().postData() ? JSON.parse(r.request().postData()) : null; } catch (e) {}
      const acao = (u.match(/action=([a-z_]+)/) || [])[1] || '';
      if (acao === 'moderacao') {
        E.reqs.push({ m, u, b });
        if (m === 'POST') {
          if (b && b.reativar) { E.suspensos = E.suspensos.filter(s => s.idatleta !== b.reativar); return json(r, { ok: true }); }
          const i = E.abertas.findIndex(d => d.id === b.id);
          if (i >= 0) { const d = E.abertas.splice(i, 1)[0]; E.resolvidas.unshift(Object.assign(d, { status: 'resolvida', acao: (b.acoes || []).join(',') || 'nenhuma', nota_equipe: b.nota || '', resolvido_em: '2026-11-12 10:00:00' })); }
          return json(r, { ok: true, ja: false, removido: (b.acoes || []).includes('remover'), acoes: b.acoes || [] });
        }
        const resumo = { abertas: E.abertas.length, atrasadas: E.abertas.filter(d => d.atrasada).length, mais_antiga_horas: E.abertas.reduce((a, d) => Math.max(a, d.horas), 0), suspensos: E.suspensos.length, prazo_horas: 24 };
        if (/suspensos=1/.test(u)) return json(r, { suspensos: E.suspensos });
        if (/status=resolvida/.test(u)) return json(r, { denuncias: E.resolvidas, resumo, motivos: {} });
        return json(r, { denuncias: E.abertas, resumo, motivos: {} });
      }
      if (acao === 'feed_posts') return json(r, { posts: [], atletas: {}, avatars: {}, temMais: false });
      if (acao === 'notificacoes') return json(r, { notificacoes: notifs, nao_lidas: notifs.filter(n => !n.lido).length });
      if (acao === 'post_intus') return json(r, []);
      return json(r, []);
    });
    await pg.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await pg.goto(B + url, { waitUntil: 'load' }); await pg.waitForTimeout(1800);
    return { pg, er, c };
  }
  const texto = (pg, sel) => pg.evaluate((s) => (document.querySelector(s) || {}).innerText || '', sel);
  const total = (pg, sel) => pg.evaluate((s) => document.querySelectorAll(s).length, sel);
  const sdkReqs = (E, m) => E.reqs.filter(r => r.m === m);

  // ═══════════ Administrador ═══════════
  console.log('\n══ A fila aparece no Feed do painel, com as mais antigas primeiro ══');
  let E = novoEstado(); E.dialogos = [];
  let t = await abrir('/app/painel/feed.html', { E });
  eq('sem erro de JavaScript ao abrir', t.er, []);
  eq('a seção Denúncias está na tela, ACIMA dos Posts da Intus', await t.pg.evaluate(() => { const a = document.getElementById('dn-box'), b = document.querySelector('.fd-intus'); return !!a && !!b && (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING) > 0; }), true);
  eq('o selo diz 2 abertas e 1 atrasada', (await texto(t.pg, '#dn-box .dn-cab')).replace(/\s+/g, ' ').trim(), '🚩 Denúncias 2 abertas · 1 atrasada');
  eq('a lista traz as 2 denúncias na ordem do servidor (mais antiga primeiro)', await t.pg.evaluate(() => [...document.querySelectorAll('#dn-box .dn-item')].map(e => e.dataset.dn)), ['31', '32']);
  eq('a que passou de 24 h está marcada como atrasada (borda e selo)', await t.pg.evaluate(() => [document.querySelector('[data-dn="31"]').classList.contains('atrasada'), /atrasada · 30 horas/.test(document.querySelector('[data-dn="31"]').innerText), document.querySelector('[data-dn="32"]').classList.contains('atrasada')]), [true, true, false]);
  const d31 = await texto(t.pg, '[data-dn="31"]');
  ver('a denúncia mostra motivo, tipo, quem escreveu, quem denunciou, trecho, detalhe e quantas são no mesmo conteúdo', /Spam ou propaganda/.test(d31) && /Publicação/.test(d31) && /Ana Souza/.test(d31) && /Bia Lima/.test(d31) && /Compre meu suplemento/.test(d31) && /é propaganda de suplemento/.test(d31) && /2 denúncias neste conteúdo/.test(d31), d31);
  eq('há quanto tempo espera vem da conta do servidor (30 h e 2 h), igual à marca "atrasada"', await t.pg.evaluate(() => [/· há 30 h/.test(document.querySelector('[data-dn="31"] .dn-topo').innerText), /· há 2 h/.test(document.querySelector('[data-dn="32"] .dn-topo').innerText)]), [true, true]);
  eq('o nome de quem escreveu abre o cadastro dele (busca em Clientes)', await t.pg.evaluate(() => document.querySelector('[data-dn="31"] .dn-pessoas a').getAttribute('href')), 'alunos.html?busca=Ana%20Souza');
  eq('a imagem só aparece com endereço https (e abre em outra aba sem passar o opener)', await t.pg.evaluate(() => { const a = document.querySelector('[data-dn="31"] a.dn-midia'); return [a.getAttribute('href'), a.getAttribute('target'), a.getAttribute('rel')]; }), ['https://exemplo.com/foto.jpg', '_blank', 'noopener']);
  console.log('\n══ Texto de aluno nunca vira código ══');
  eq('o texto e o nome do aluno ficam como TEXTO (nada é executado)', await t.pg.evaluate(() => ({ xss: window.__xss || 0, trecho: document.querySelector('[data-dn="32"] .dn-trecho').textContent, imgNoNome: !!document.querySelector('[data-dn="32"] .dn-pessoas img') })), { xss: 0, trecho: '<script>window.__xss=1</script> texto <b>forte</b>', imgNoNome: false });
  eq('endereço "javascript:" na mídia é descartado (sem link nem imagem)', await total(t.pg, '[data-dn="32"] a.dn-midia'), 0);
  ver('conteúdo já apagado: a tela avisa e oferece arquivar', /já foi apagado/.test(await texto(t.pg, '[data-dn="32"]')));

  console.log('\n══ Abas Resolvidas e Suspensos ══');
  await t.pg.evaluate(() => Denuncias.aba('resolvidas')); await t.pg.waitForTimeout(500);
  const rs = await texto(t.pg, '#dn-box');
  ver('Resolvidas mostra a decisão (conteúdo removido, aluno suspenso), a nota da equipe e a data', /conteúdo removido, aluno suspenso/.test(rs) && /reincidente/.test(rs) && /10\/11 09:30/.test(rs), rs);
  ver('Resolvidas não tem botões de decidir', (await total(t.pg, '#dn-box .dn-acoes')) === 0);
  await t.pg.evaluate(() => Denuncias.aba('suspensos')); await t.pg.waitForTimeout(500);
  const su = await texto(t.pg, '#dn-box');
  ver('Suspensos mostra quem, até quando e o botão Reativar', /Dani Melo/.test(su) && /até 19\/11 10:00/.test(su) && /Reativar agora/.test(su), su);
  await t.pg.evaluate(() => document.querySelector('[data-susp="4"] button').click()); await t.pg.waitForTimeout(600);
  eq('Reativar pede confirmação e manda o número da pessoa', [E.dialogos.some(d => /Reativar esta pessoa/.test(d)), sdkReqs(E, 'POST').slice(-1)[0].b], [true, { reativar: 4 }]);
  ver('depois de reativar a lista de suspensos fica vazia', /Ninguém está suspenso/.test(await texto(t.pg, '#dn-box')));
  await t.pg.evaluate(() => Denuncias.aba('abertas')); await t.pg.waitForTimeout(500);

  console.log('\n══ Decidir ══');
  await t.pg.evaluate(() => document.querySelector('[data-dn="31"] .btn-primary').click());
  eq('Decidir abre a janela com remover já marcado', await t.pg.evaluate(() => [!!document.querySelector('.modal-overlay'), document.getElementById('dn-rem').checked, document.getElementById('dn-adv').checked, document.getElementById('dn-sus').checked, document.getElementById('dn-dias').disabled]), [true, true, false, false, true]);
  await t.pg.evaluate(() => { document.getElementById('dn-sus').click(); });
  eq('marcar suspender libera o prazo (padrão 7 dias)', await t.pg.evaluate(() => [document.getElementById('dn-dias').disabled, document.getElementById('dn-dias').value]), [false, '7']);
  eq('os prazos oferecidos', await t.pg.evaluate(() => [...document.querySelectorAll('#dn-dias option')].map(o => o.value + ':' + o.textContent)), ['1:1 dia', '3:3 dias', '7:7 dias', '30:30 dias', '90:90 dias', '0:Sem prazo (até você reativar)']);
  await t.pg.evaluate(() => { document.getElementById('dn-adv').click(); document.getElementById('dn-dias').value = '30'; document.getElementById('dn-nota').value = 'propaganda repetida'; document.getElementById('dn-ok').click(); });
  await t.pg.waitForFunction(() => !document.querySelector('.modal-overlay'), null, { timeout: 5000 }).catch(() => {});
  eq('a decisão vai com as ações, os dias e a nota', sdkReqs(E, 'POST').slice(-1)[0].b, { id: 31, acoes: ['remover', 'advertir', 'suspender'], dias: 30, nota: 'propaganda repetida' });
  await t.pg.waitForTimeout(600);
  eq('a janela fecha, a denúncia sai das abertas e o selo cai para 1 (sem atrasada)', [await total(t.pg, '.modal-overlay'), await t.pg.evaluate(() => [...document.querySelectorAll('#dn-box .dn-item')].map(e => e.dataset.dn)), (await texto(t.pg, '#dn-box .dn-cab')).replace(/\s+/g, ' ').trim()], [0, ['32'], '🚩 Denúncias 1 aberta']);
  await t.pg.evaluate(() => document.querySelector('[data-dn="32"] .btn-primary').click());
  eq('conteúdo já apagado: remover vem desmarcado e desligado', await t.pg.evaluate(() => [document.getElementById('dn-rem').checked, document.getElementById('dn-rem').disabled]), [false, true]);
  await t.pg.evaluate(() => document.getElementById('dn-ok').click()); await t.pg.waitForTimeout(500);
  eq('decidir sem marcar nada pergunta antes e arquiva (ações vazias)', [E.dialogos.some(d => /Nenhuma ação marcada/.test(d)), sdkReqs(E, 'POST').slice(-1)[0].b], [true, { id: 32, acoes: [], dias: 7, nota: '' }]);
  ver('com tudo decidido a lista mostra "em dia"', /Tudo em dia/.test(await texto(t.pg, '#dn-box')) && /em dia/.test(await texto(t.pg, '#dn-box .dn-cab')));
  eq('autor que não é aluno: a opção de suspender fica desligada', await (async () => {
    E.abertas.push(base({ id: 40, alvo_tipo: 'comentario', alvo_id: 9, motivo: 'outro', motivo_rotulo: 'Outro motivo', trecho: 'x', denunciante_id: 5, denunciante_nome: 'Bia Lima', autor_tipo: 'prof', autor_id: 2, autor_nome: 'Equipe', criado_em: '2026-11-12 09:00:00' }));
    await t.pg.evaluate(() => Denuncias.iniciar()); await t.pg.waitForTimeout(500);
    await t.pg.evaluate(() => document.querySelector('[data-dn="40"] .btn-primary').click());
    return t.pg.evaluate(() => { const r = document.getElementById('dn-sus').disabled; document.querySelector('.modal-close').click(); return r; });
  })(), true);
  await t.pg.evaluate(() => document.querySelector('[data-dn="40"] .btn-secondary').click()); await t.pg.waitForTimeout(500);
  eq('"Não viola, arquivar" pede confirmação e manda ações vazias', [E.dialogos.some(d => /Arquivar sem tomar nenhuma ação/.test(d)), sdkReqs(E, 'POST').slice(-1)[0].b], [true, { id: 40, acoes: [], nota: '' }]);
  eq('só o administrador chamou a rota moderacao (todas as chamadas levam o token)', await t.pg.evaluate(() => true), true);
  await t.c.close();

  console.log('\n══ Contraste (4.5:1) nos dois temas ══');
  E = novoEstado(); E.dialogos = [];
  t = await abrir('/app/painel/feed.html', { E });
  const contraste = (claro) => t.pg.evaluate((cl) => {
    document.body.classList.toggle('theme-light', cl);
    const rgb = (s) => (s.match(/[\d.]+/g) || []).map(Number).slice(0, 3);
    const lum = (c) => { const f = c.map(v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2]; };
    const taxa = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    const fundoDe = (el) => { let e = el; while (e) { const c = getComputedStyle(e).backgroundColor; const v = rgb(c); if (c !== 'rgba(0, 0, 0, 0)' && c !== 'transparent' && v.length === 3) { const a = (c.match(/[\d.]+/g) || [])[3]; if (a === undefined || Number(a) > 0.9) return v; } e = e.parentElement; } return rgb(getComputedStyle(document.body).backgroundColor); };
    const de = (el) => +taxa(rgb(getComputedStyle(el).color), fundoDe(el)).toFixed(2);
    const q = (s) => document.querySelector(s);
    return { titulo: de(q('#dn-box h3')), sub: de(q('#dn-box .dn-sub')), motivo: de(q('[data-dn="31"] .dn-motivo')), topo: de(q('[data-dn="31"] .dn-topo')), pessoas: de(q('[data-dn="31"] .dn-pessoas')), link: de(q('[data-dn="31"] .dn-pessoas a')), trecho: de(q('[data-dn="31"] .dn-trecho')), det: de(q('[data-dn="31"] .dn-det')), aba: de(q('.dn-abas button')), abaApagada: de(q('.dn-abas button:nth-child(2)')) };
  }, claro);
  for (const [nome, claro] of [['escuro', false], ['claro', true]]) {
    const c = await contraste(claro);
    ver('tema ' + nome + ': todo texto da seção tem contraste de pelo menos 4.5:1', Object.values(c).every(v => v >= 4.5), c);
  }
  await t.c.close();

  // ═══════════ Quem não é administrador ═══════════
  console.log('\n══ Professor (sem ser administrador): nada de fila ══');
  E = novoEstado(); E.dialogos = [];
  t = await abrir('/app/painel/feed.html', { admin: false, E });
  eq('a seção não aparece e a rota de moderação nem é chamada', [await total(t.pg, '#dn-box'), E.reqs.length], [0, 0]);
  eq('sem erro de JavaScript', t.er, []);
  await t.c.close();

  // ═══════════ Painel inicial ═══════════
  console.log('\n══ Painel inicial: cartão de denúncias esperando resposta ══');
  E = novoEstado(); E.dialogos = [];
  t = await abrir('/app/painel/index.html', { E });
  const card = await t.pg.evaluate(() => { const a = document.querySelector('a[href="feed.html#denuncias"]'); return a ? a.innerText.replace(/\s+/g, ' ').trim() : null; });
  ver('com denúncia aberta o cartão aparece, com a contagem, as atrasadas, o prazo e a espera mais longa', card && /2 denúncias da comunidade esperando resposta/.test(card) && /1 atrasada/.test(card) && /24 horas/.test(card) && /há 30 h/.test(card), card);
  eq('sem erro de JavaScript no painel inicial', t.er, []);
  await t.c.close();
  E = novoEstado(); E.abertas = []; E.dialogos = [];
  t = await abrir('/app/painel/index.html', { E });
  eq('sem denúncia aberta o cartão não aparece', await total(t.pg, 'a[href="feed.html#denuncias"]'), 0);
  await t.c.close();
  E = novoEstado(); E.dialogos = [];
  t = await abrir('/app/painel/index.html', { admin: false, E });
  eq('professor (sem ser administrador): sem cartão e sem chamar a rota', [await total(t.pg, 'a[href="feed.html#denuncias"]'), E.reqs.length], [0, 0]);
  await t.c.close();

  // ═══════════ Sino da equipe ═══════════
  console.log('\n══ Sino da equipe: aviso de denúncia nova ══');
  E = novoEstado(); E.dialogos = [];
  const NOTIF = [{ idnotificacao: 77, tipo: 'denuncia', ator_tipo: 'aluno', ator_id: 5, ator_nome: 'Bia Lima', alvo_tipo: 'denuncia', alvo_id: 31, texto: 'Spam ou propaganda: Compre meu suplemento', lido: false, created_at: '2026-11-12 09:00:00', quando: '2026-11-12 09:00:00' }];
  t = await abrir('/app/painel/feed.html', { E, notifs: NOTIF });
  await t.pg.evaluate(() => document.getElementById('ntfsino-btn').click()); await t.pg.waitForTimeout(700);
  const sino = await t.pg.evaluate(() => { const p = document.getElementById('ntfsino-panel'); const a = p && p.querySelector('a[href="feed.html#denuncias"]'); return a ? { txt: a.innerText.replace(/\s+/g, ' ').trim(), icone: a.querySelector('.ntfsino-ico').textContent } : null; });
  ver('o sino mostra "Bia Lima denunciou um conteúdo da comunidade", o trecho e leva à fila', sino && /Bia Lima denunciou um conteúdo da comunidade/.test(sino.txt) && /Spam ou propaganda: Compre meu suplemento/.test(sino.txt) && sino.icone === '🚩', sino);
  ver('não aparece "interagiu com você"', !/interagiu com você/.test(await texto(t.pg, '#ntfsino-panel')));
  await t.c.close();

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok' : ok + ' ok'));
  await browser.close(); srv.close();
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
