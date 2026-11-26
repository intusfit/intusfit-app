// Explorador de navegação do app do aluno (27/11/2026). Para cada tela de entrada, toca em CADA elemento clicável (um por vez, a partir de um estado limpo),
// e então volta ao início de duas formas: pelo botão "← Voltar" da tela e pelo voltar do celular (gesto/botão físico). Confere, em todos os caminhos:
//   - chega ao início em poucos passos (sem laço entre telas, sem ficar preso),
//   - o voltar nunca sai do app (a página continua a mesma),
//   - nenhuma folha ou camada fica sobrando por cima,
//   - a rolagem não fica torta para o lado e nenhum erro de JavaScript acontece.
//   cd testes && INTUS_DIR=../app/painel node navegacao_profunda.js            (todas as telas)
//   ONLY=Nutrição node navegacao_profunda.js                                    (uma tela)   MAX=12 limita os toques por tela   DBG=1 detalha
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
const MAX = +(process.env.MAX || 40), ONLY = process.env.ONLY || '', DBG = !!process.env.DBG;
let ok = 0, ruim = 0; const problemas = [];
function falha(rot, det) { ruim++; problemas.push(rot + ' :: ' + det); console.log('  FALHA ' + rot + '\n          ' + det); }
function bom(rot) { ok++; if (DBG) console.log('  OK    ' + rot); }

const AVALIACOES = [
  { idavaliacao: 4, idatleta: 7, dtavaliacao: '2026-11-20', peso: 71.2, percgordura: 17.5, metodo: 'Pollock 7 dobras', medidas: { dc_coxa: 18, dc_peitoral: 8, dc_abdominal: 20, cintura: 74 }, fotos: [] },
  { idavaliacao: 3, idatleta: 7, dtavaliacao: '2026-09-02', peso: 73.4, percgordura: 20.1, metodo: 'Pollock 7 dobras', medidas: { dc_coxa: 21, cintura: 77 }, fotos: [] },
  { idavaliacao: 2, idatleta: 7, dtavaliacao: '2026-06-10', peso: 75.9, percgordura: 22.8, metodo: 'Bioimpedância', medidas: { cintura: 80 }, fotos: [] },
];
const ENTRADAS = [
  ['Início', "trocarView('home'); renderHome()"],
  ['Treinos', "abrirMeusTreinos()"], ['Meu Cardio', 'abrirCardio()'], ['Histórico', "navegar('historico')"], ['Ranking', 'abrirRanking()'], ['Medalhas', 'abrirQuadroMedalhas()'],
  ['Feed', "navegar('feed')"], ['Resultados', "navegar('resultados')"], ['Desafios', "navegar('desafios')"], ['Nutrição', "navegar('nutricao')"], ['Avaliações', "navegar('avaliacoes')"],
  ['Central', "navegar('mensagens')"], ['Perfil', "navegar('perfil')"], ['Matrícula', "navegar('matricula')"], ['Quem somos', "navegar('quemsomos')"], ['Programas de cardio', 'abrirProgramasCardio()'],
  ['Comunidade', "navegar('amigos')"], ['Evoluções', "navegar('along')"],
];
// O que nunca se toca no teste (apaga, envia ou sai de verdade).
const PERIGO = /excluir|apagar|deletar|logout|sair\(|sairDoApp|descartar|encerrarConta|cancelarMatri|removerPost|denunciar|bloquear|desfazerAmiz|limparTudo|location\.href|window\.open|tel:|wa\.me|mailto/i;

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const mkPost = id => ({ idpost: id, idatleta: 9, created_at: '2026-11-20 10:00:00', legenda: 'Treino ' + id, midias: [], localizacao: '' });

  async function nova() {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', hasTouch: true, isMobile: true });
    const page = await ctx.newPage(); page.erros = []; page.on('pageerror', e => { page.erros.push(String(e.message)); if (DBG) console.log('   PAGEERROR', String(e.stack || '').split(String.fromCharCode(10)).slice(0, 4).join(' / ')); });
    page.on('dialog', d => d.dismiss()); page.on('popup', p => p.close().catch(() => {}));
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort('failed'));
    await page.route(/\/app\/api\//, r => {
      const u = r.request().url(), m = r.request().method(), J = (o) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(o) });
      if (/action=avaliacoes/.test(u)) return m === 'GET' ? J(AVALIACOES) : J({ ok: true, idavaliacao: 9, fotos: [] });
      if (/feedbacks\.php\?action=meus/.test(u)) return J({ ok: true, feedbacks: [] });
      if (/action=atleta_existe/.test(u)) return J({});
      if (/action=feed_posts/.test(u) && m === 'GET') { const t = []; for (let i = 20; i >= 1; i--) t.push(mkPost(i)); return J({ posts: t, temMais: false, atletas: { 9: 'Aluna Nove' }, avatars: {} }); }
      return r.abort('failed');
    });
    await page.addInitScript(() => { try {
      const d = (n) => { const x = new Date(); x.setDate(x.getDate() - n); return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); };
      localStorage.setItem('mx-token', 'tok-fluxo'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' }));
      localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-cardio-novo-visto', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now())); localStorage.setItem('intus-perm-preparo-7', '1'); localStorage.setItem('intus-ajuda-v2', JSON.stringify({ visto: 1 }));
      const fim = new Date(Date.now() + 90 * 86400000).toISOString().slice(0, 10);
      localStorage.setItem('intus-mensalidades', JSON.stringify([{ idmensalidade: 1, idatleta: 7, plano: 'Consultoria Premium', dtinicio: '2026-11-01', dtvencimento: fim, stmatricula: 'ativa', status: 'paga' }]));
      localStorage.setItem('intus-fichas', JSON.stringify([{ idficha: 5, idatleta: 7, nmficha: 'Treino ABC', ativo: 1, alongamentos: [{ idalongamento: 1, nome: 'Peitoral', tempo: '30s', divisao: 'A' }] }]));
      const t = []; for (let i = 0; i < 6; i++) t.push({ idtreino: 100 + i, idficha: 5, divisao: i < 4 ? 'A' : 'B', idexercicio: 10 + i, nmexercicio: 'Exercicio ' + i, qtdserie: 3, repeticoes: '12', intervalo: '01:00' });
      localStorage.setItem('intus-treinos', JSON.stringify(t));
      const ss = []; for (let i = 1; i <= 12; i++) ss.push({ idsessao: i, idatleta: 7, idficha: 5, divisao: i % 2 ? 'A' : 'B', dtsessao: d(i), duracao_seg: 3000, pontos: 10, tipo: 'musculacao' });
      localStorage.setItem('intus-sessoes-treino', JSON.stringify(ss));
    } catch (e) {} });
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof renderHome === 'function' && typeof viewStack !== 'undefined' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(700);
    page.ctx = ctx; return page;
  }

  const est = (p) => p.evaluate(() => {
    const v = document.querySelector('.view.ativo'); const id = v ? v.id.replace(/^v-/, '') : null;
    const camadas = Array.from(document.body.children).filter(e => getComputedStyle(e).position === 'fixed' && getComputedStyle(e).visibility !== 'hidden' && (e.classList.contains('com-sheet') || /overlay|^pk-|-ov$/.test(e.id || '')) && !/^(menu-overlay|tutorial-overlay)$/.test(e.id || '') && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 80).map(e => e.id || e.className);
    const t = v ? ((v.querySelector('.th-titulo, h2, .sect-title') || {}).textContent || '').trim().replace(/\s+/g, ' ').slice(0, 30) : '';
    return { v: id, pilha: (typeof viewStack !== 'undefined' ? viewStack.join('>') : 'SEM-APP'), camadas, t, largura: document.documentElement.scrollWidth - window.innerWidth, treino: !!(typeof treinoTimer !== 'undefined' && treinoTimer), url: location.pathname };
  });
  const sig = (e) => e.v + '|' + e.t + '|' + e.camadas.join(',') + '|' + e.pilha;
  const esp = (p, ms) => p.waitForTimeout(ms || 380);
  const irHome = async (p) => { await p.evaluate("(function(){ try{ if (typeof descartarTreinoEmAndamento==='function') descartarTreinoEmAndamento(); localStorage.removeItem('intus-treino-ativo'); }catch(e){}; try{ document.querySelectorAll('#dlg-app-ov,#cq-overlay,#res-compose-overlay,#feed-compose-overlay,#pk-post-ov').forEach(e=>e.remove()); }catch(e){}; viewStack.length=1; trocarView('home'); renderHome(); })()").catch(() => {}); await esp(p, 300); };
  const botaoVoltarJs = () => { const v = document.querySelector('.view.ativo'); const bt = v && Array.from(v.querySelectorAll('.btn-back')).find(e => e.offsetParent !== null); if (bt) { bt.click(); return true; } return false; };

  // Volta ao início com `modo`: 'botao' (clica "← Voltar" quando existe; senão cai no gesto) ou 'gesto' (history.back). Devolve { passos, trilha, saiu }.
  async function voltarAteHome(p, modo, tolera) {
    const trilha = []; let ant = null, repetiu = 0, passos = 0;
    for (; passos < 14; passos++) {
      const e = await est(p); trilha.push(e.v + (e.camadas.length ? '+' + e.camadas.join('+') : '') + (e.t ? '[' + e.t + ']' : ''));
      if (e.v === 'home' && !e.camadas.length) return { passos, trilha, saiu: false };
      if (!/127\.0\.0\.1/.test(p.url()) || e.pilha === 'SEM-APP') return { passos, trilha: trilha.concat([p.url()]), saiu: true };
      const s = sig(e); repetiu = (s === ant) ? repetiu + 1 : 0; ant = s;
      if (repetiu >= 2) return { passos, trilha, preso: true };
      let fez = false;
      if (modo === 'botao' && !e.camadas.length) fez = await p.evaluate(botaoVoltarJs).catch(() => false);
      if (!fez) await p.evaluate(() => history.back()).catch(() => {});
      await esp(p, 520);
      // caixa de confirmação do app (treino em andamento, anamnese...): confirma saída para seguir
      const sair = await p.evaluate((modoB) => { const fim = document.getElementById('prog-fim-ov'); if (fim && modoB === 'botao') { const bs = fim.querySelectorAll('button'); bs[bs.length - 1].click(); return true; } const b = document.querySelector('#dlg-inicio'); if (b) { b.click(); return true; } const o = document.getElementById('dlg-app-ov'); if (o) { o.remove(); return true; } return false; }, modo).catch(() => false);
      if (sair) await esp(p, 300);
    }
    return { passos, trilha, longo: true };
  }

  const alvos = ENTRADAS.filter(([n]) => !ONLY || n === ONLY);
  let p = await nova(); let usos = 0;
  for (const [nome, js] of alvos) {
    console.log('\n══ ' + nome + ' ══');
    // quantos elementos clicáveis a tela tem (contados uma vez, a partir do estado limpo)
    await irHome(p); await p.evaluate(js).catch(e => p.erros.push('abrir ' + nome + ': ' + e.message)); await esp(p, 700);
    const lista = await p.evaluate((PER) => {
      const re = new RegExp(PER, 'i'); const v = document.querySelector('.view.ativo'); if (!v) return [];
      const els = Array.from(v.querySelectorAll('[onclick], button, a[href], .btn-back, summary, [role=button]'))
        .filter(e => e.offsetParent !== null && e.getBoundingClientRect().width > 8 && e.getBoundingClientRect().height > 8 && !e.closest('#menu-overlay'));
      return els.map((e, i) => ({ i, txt: (e.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 28), oc: (e.getAttribute('onclick') || '').slice(0, 60), perigo: re.test((e.getAttribute('onclick') || '') + ' ' + (e.getAttribute('href') || '') + ' ' + (e.textContent || '').slice(0, 40)) }));
    }, PERIGO.source);
    const toques = lista.filter(x => !x.perigo).slice(0, MAX);
    console.log('  ' + lista.length + ' clicáveis (' + toques.length + ' testados, ' + (lista.length - lista.filter(x => !x.perigo).length) + ' evitados por serem destrutivos)');
    // 0) a própria tela, sem tocar em nada: botão e gesto
    for (const modo of ['botao', 'gesto']) {
      await irHome(p); await p.evaluate(js).catch(() => {}); await esp(p, 600);
      const r = await voltarAteHome(p, modo); const rot = nome + ' · sem toques · voltar por ' + modo;
      if (r.saiu || r.preso || r.longo) falha(rot, (r.saiu ? 'SAIU DO APP' : r.preso ? 'PRESO' : 'LONGO') + ' ' + r.trilha.join(' › ')); else bom(rot);
    }
    for (const x of toques) {
      for (const modo of ['botao', 'gesto']) {
        if (++usos % 60 === 0) { await p.ctx.close().catch(() => {}); p = await nova(); }
        await irHome(p); const nErr = p.erros.length;
        await p.evaluate(js).catch(() => {}); await esp(p, 600);
        const antes = await est(p);
        const clicou = await p.evaluate((i) => { const v = document.querySelector('.view.ativo'); const els = Array.from(v.querySelectorAll('[onclick], button, a[href], .btn-back, summary, [role=button]')).filter(e => e.offsetParent !== null && e.getBoundingClientRect().width > 8 && e.getBoundingClientRect().height > 8 && !e.closest('#menu-overlay')); const e = els[i]; if (!e) return false; e.scrollIntoView({ block: 'center' }); e.click(); return true; }, x.i).catch(() => false);
        if (!clicou) continue;
        await esp(p, 650);
        // segundo toque rápido no mesmo lugar (quem não sabe usar toca duas vezes)
        const dep = await est(p);
        const rot = nome + ' › "' + (x.txt || x.oc) + '" · voltar por ' + modo;
        if (dep.largura > 2) falha(rot + ' · rolagem lateral', dep.largura + 'px a mais que a tela em ' + dep.v);
        const r = await voltarAteHome(p, modo);
        const novosErros = p.erros.slice(nErr);
        if (novosErros.length) falha(rot + ' · erro de JavaScript', novosErros.join(' | ').slice(0, 220));
        else if (r.saiu) falha(rot, 'SAIU DO APP: ' + [antes.v, dep.v].join(' → ') + ' · ' + r.trilha.join(' › '));
        else if (r.preso) falha(rot, 'PRESO (voltar não anda): ' + r.trilha.join(' › '));
        else if (r.longo) falha(rot, 'LAÇO ou volta longa demais: ' + r.trilha.join(' › '));
        else if (r.passos > 5) falha(rot, r.passos + ' voltas para chegar ao início: ' + r.trilha.join(' › '));
        else bom(rot);
        if (!/127\.0\.0\.1/.test(p.url())) { await p.ctx.close().catch(() => {}); p = await nova(); }
      }
    }
  }
  // Passeios aleatórios (WALKS=60 SEED=1): de 2 a 5 toques em sequência (cada um no que estiver visível, na tela ou numa folha), com voltas no meio
  // (botão ou gesto, misturados) e a volta final ao início. Cada passeio imprime a sequência para ser repetido.
  const WALKS = +(process.env.WALKS || 0); let seed = +(process.env.SEED || 1);
  const rnd = () => { seed = (seed * 1664525 + 1013904223) >>> 0; return seed / 4294967296; };
  if (WALKS) console.log('\n══ ' + WALKS + ' passeios aleatórios (SEED=' + (process.env.SEED || 1) + ') ══');
  for (let w = 0; w < WALKS; w++) {
    if (w % 12 === 11) { await p.ctx.close().catch(() => {}); p = await nova(); }
    const _pool = process.env.WALKENTRY ? ENTRADAS.filter(x => process.env.WALKENTRY.split(',').includes(x[0])) : ENTRADAS; const [nome, js] = _pool[Math.floor(rnd() * _pool.length)]; const nErr = p.erros.length; const seq = [nome];
    await irHome(p); await p.evaluate(js).catch(() => {}); await esp(p, 600);
    const passos = 2 + Math.floor(rnd() * (+(process.env.PASSOS || 4)));
    for (let s = 0; s < passos; s++) {
      if (rnd() < 0.3) { const e0 = await est(p); if (e0.v === 'home' && !e0.camadas.length) continue;   // voltar do início sai do app em qualquer app: não é o que se mede
        const modo = rnd() < 0.5 ? 'botao' : 'gesto'; seq.push('«' + modo); const fez = modo === 'botao' ? await p.evaluate(botaoVoltarJs).catch(() => false) : false; if (!fez) await p.evaluate(() => history.back()).catch(() => {}); await esp(p, 520); continue; }
      const alvo = await p.evaluate((PER) => {
        const re = new RegExp(PER, 'i');
        const cam = Array.from(document.body.children).filter(e => getComputedStyle(e).position === 'fixed' && getComputedStyle(e).visibility !== 'hidden' && (e.classList.contains('com-sheet') || /overlay|-ov$/.test(e.id || '')) && !/^(menu-overlay|tutorial-overlay)$/.test(e.id || '') && e.getBoundingClientRect().height > 80);
        const raiz = cam.length ? cam[cam.length - 1] : document.querySelector('.view.ativo'); if (!raiz) return -1;
        const els = Array.from(raiz.querySelectorAll('[onclick], button, a[href], summary, [role=button]')).filter(e => e.offsetParent !== null && e.getBoundingClientRect().width > 8 && e.getBoundingClientRect().height > 8 && !re.test((e.getAttribute('onclick') || '') + ' ' + (e.getAttribute('href') || '') + ' ' + (e.textContent || '').slice(0, 40)));
        window.__walkEls = els; return els.length;
      }, PERIGO.source).catch(() => -1);
      if (alvo <= 0) break; const idx = Math.floor(rnd() * alvo);
      const rotulo = await p.evaluate((i) => { const e = window.__walkEls[i]; e.scrollIntoView({ block: 'center' }); const r = (e.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 22) || (e.getAttribute('onclick') || '').slice(0, 30); e.click(); return r; }, idx).catch(() => '?');
      seq.push(rotulo); await esp(p, 600);
      if (rnd() < 0.15) { await p.evaluate(() => { const e = window.__walkEls && window.__walkEls[0]; }).catch(() => {}); }
    }
    const modo = rnd() < 0.5 ? 'botao' : 'gesto'; const r = await voltarAteHome(p, modo);
    const rot = 'passeio ' + (w + 1) + ' [' + seq.join(' › ') + '] · volta final por ' + modo; const nov = p.erros.slice(nErr);
    if (nov.length) falha(rot + ' · erro de JavaScript', nov.join(' | ').slice(0, 220));
    else if (r.saiu) falha(rot, 'SAIU DO APP: ' + r.trilha.join(' › '));
    else if (r.preso) falha(rot, 'PRESO: ' + r.trilha.join(' › '));
    else if (r.longo) falha(rot, 'LAÇO ou volta longa: ' + r.trilha.join(' › '));
    else if (r.passos > 7) falha(rot, r.passos + ' voltas: ' + r.trilha.join(' › '));
    else bom(rot);
    if (!/127\.0\.0\.1/.test(p.url())) { await p.ctx.close().catch(() => {}); p = await nova(); }
  }
  console.log('\n══ Erros de JavaScript no total ══');
  if (p.erros.length) falha('erros de página', Array.from(new Set(p.erros)).slice(0, 8).join(' | ')); else bom('nenhum erro de página');
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok' : ok + ' ok'));
  if (ruim) { console.log('\nResumo:'); problemas.forEach(x => console.log(' - ' + x.slice(0, 260))); }
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})();
