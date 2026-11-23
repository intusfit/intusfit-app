// Troca de tema: cada tela, trocada do escuro para o claro, tem que ficar IGUAL à mesma tela aberta já no tema claro (e o contrário). Diferença = cor presa
// no tema antigo (era o que deixava "vários ícones com as cores bugadas"). Compara cor do texto, do fundo e da borda de TODOS os elementos da tela.
//   cd testes && INTUS_DIR=../app/painel node tema_troca.js            (mostra os piores casos de cada tela)
//   cd testes && INTUS_DIR=../app/painel node tema_troca.js --salvar     (grava o estado atual como linha de base)
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
const VIEWS = ['home', 'cardio', 'perfil', 'central', 'matricula', 'avaliacoes', 'nutricao', 'feed', 'amigos', 'extras', 'treinos', 'historico', 'ranking', 'desafios', 'quemsomos', 'resultados', 'exinfo', 'muscu'];
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();

  async function abrir(tema) {
    const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block', hasTouch: true, isMobile: true, reducedMotion: 'reduce' });
    const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\//, r => r.abort('failed'));
    await page.route(/fonts\.|youtube|google|facebook|trafego/, r => r.abort());
    await page.addInitScript((t) => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-theme', t); localStorage.setItem('intus-exercicios', JSON.stringify([{ idexercicio: 1, nmexercicio: 'Crucifixo Peck Deck', grupo: 'Peito', observacao: 'Controle excêntrico', videoyoutube: '', gifexercicio: '', descricao: '', instrucao_ia: '' }])); }, tema);
    await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
    for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof trocarView === 'function' && typeof toggleTheme === 'function' && typeof renderHome === 'function' && !!document.getElementById('h-dir')).catch(() => false)) break; await page.waitForTimeout(250); }
    await page.waitForTimeout(500);
    return { ctx, page, erros };
  }
  // cores de todos os elementos visíveis da tela (e do cabeçalho fixo), na ordem do documento
  const coletar = (page, v) => page.evaluate((v) => {
    const raiz = document.getElementById('v-' + v); const lista = [];
    const nos = Array.from(raiz.querySelectorAll('*')); nos.unshift(raiz);
    for (const el of nos) {
      if (!el.offsetParent && el !== raiz) continue;
      const cs = getComputedStyle(el); const r = el.getBoundingClientRect(); if (r.width === 0 || r.height === 0) continue;
      const rotulo = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
      lista.push({ rotulo, texto: (el.children.length === 0 ? (el.textContent || '').trim().slice(0, 24) : ''), cor: cs.color, fundo: cs.backgroundColor, borda: cs.borderTopWidth !== '0px' ? cs.borderTopColor : '' });
    }
    return lista;
  }, v);

  const pior = {};
  for (const sentido of [['escuro', 'claro'], ['claro', 'escuro']]) {
    const [de, para] = sentido;
    console.log('\n══ Trocando de ' + de + ' para ' + para + ' ══');
    const A = await abrir(para);   // referência: a tela aberta já no tema de destino
    const B = await abrir(de);     // teste: aberta no tema de origem e depois trocada
    for (const v of VIEWS) {
      let refs, trocada;
      try {
        const abrirTela = (page) => page.evaluate((v) => { try { if (v === 'muscu') { sessaoAtual = { idficha: 1, divisao: 'A', divisaoLabel: 'A', nomeFicha: 'Treino A', activeIdx: 0, inicio: Date.now(), comentario: '', alongamentos: [],
          exercicios: [{ idtreino: 1, idexercicio: 1, nmexercicio: 'Supino Reto', qtdserie: 4, repeticoes: '8-10', tempopausa: 90, observacao: 'Controle a descida', metodo: '' }, { idtreino: 2, idexercicio: 2, nmexercicio: 'Crucifixo Peck Deck', qtdserie: 3, repeticoes: '10 a 12', tempopausa: 60, observacao: '', metodo: '' }],
          dados: { 1: [0, 1, 2, 3].map(() => ({ peso: '', reps: '', feita: false, repTarget: '8-10' })), 2: [0, 1, 2].map(() => ({ peso: '', reps: '', feita: false, repTarget: '10 a 12' })) } }; renderMusculacao(); } else if (v === 'exinfo') abrirInfoExercicio(1, 'Crucifixo Peck Deck'); else if (_RENDER_POR_VIEW[v]) _RENDER_POR_VIEW[v](); } catch (e) { console.log('erro ao abrir ' + v + ': ' + e.message); } trocarView(v); return window._viewAtual === v && !!document.getElementById('v-' + v).offsetParent; }, v);
        const aOk = await abrirTela(A.page); await A.page.waitForTimeout(450);
        const bOk = await abrirTela(B.page); await B.page.waitForTimeout(450);
        if (!aOk || !bOk) { console.log('  (pulou ' + v + ': a tela não abre sem dados do servidor)'); continue; }
        refs = await coletar(A.page, v);
        await B.page.evaluate(() => { toggleTheme(); }); await B.page.waitForTimeout(450);
        trocada = await coletar(B.page, v);
        await B.page.evaluate(() => { toggleTheme(); }); await B.page.waitForTimeout(250);   // volta ao tema de origem para a próxima tela (senão o estado se inverteria a cada tela)
      } catch (e) { console.log('  (pulou ' + v + ': ' + String(e.message).slice(0, 60) + ')'); continue; }
      if (refs.length !== trocada.length) { console.log('  (pulou ' + v + ': a tela mudou de tamanho, ' + refs.length + ' x ' + trocada.length + ' elementos)'); continue; }
      const dif = [];
      for (let i = 0; i < refs.length; i++) {
        const x = refs[i], y = trocada[i];
        if (x.cor !== y.cor || x.fundo !== y.fundo || x.borda !== y.borda) dif.push(x.rotulo + (x.texto ? ' "' + x.texto + '"' : '') + '  esperado ' + [x.cor, x.fundo, x.borda].join(' / ') + '  obtido ' + [y.cor, y.fundo, y.borda].join(' / '));
      }
      pior[de + '>' + para + ' ' + v] = dif;
      eq('tela ' + v + ' (' + de + ' para ' + para + '): ' + refs.length + ' elementos sem cor presa no tema antigo', dif.length, 0);
      if (dif.length) dif.slice(0, 6).forEach(d => console.log('          ' + d.slice(0, 230)));
    }
    await A.ctx.close(); await B.ctx.close();
  }
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
