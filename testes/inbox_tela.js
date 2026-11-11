// Caixa de entrada da Central (atendimento/index.html) no Chromium, com a API de mentira (nenhum dado real, nada de Meta nem de Claude).
//   cd testes && INTUS_DIR=../app/painel node inbox_tela.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel', '..', '..');   // raiz do site: /atendimento/ e /app/painel/
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

// API de mentira com estado: duas conversas, uma esperando resposta.
function criarApi() {
  const S = { chamadas: [], conversas: {}, proximo: 3 };
  const cora = c => (c.cora === undefined ? null : c.cora);
  S.conversas[1] = { id: 1, nome: 'Ana Lead', telefone: '5545991110001', msgs: [{ idmensagem: 1, direcao: 'E', origem: 'lead', tipo: 'text', texto: 'Oi, como funciona o teste?', status: 'recebida', criado_em: '2026-11-11 09:00:00' }], rascunho: null, sugestao: null, resolvida: false };
  S.conversas[2] = { cora: 'cadastro', id: 2, nome: 'Bia Respondida', telefone: '5545991110002', msgs: [{ idmensagem: 2, direcao: 'E', origem: 'lead', tipo: 'text', texto: 'Oi', status: 'recebida', criado_em: '2026-11-11 08:00:00' }, { idmensagem: 3, direcao: 'S', origem: 'pessoa', tipo: 'text', texto: 'Oi, Bia!', status: 'enviada', criado_em: '2026-11-11 08:05:00' }], rascunho: null, sugestao: null, resolvida: false };
  const espera = c => { const u = c.msgs.map(m => m.direcao).lastIndexOf('S'); return c.msgs.slice(u + 1).filter(m => m.direcao === 'E').length; };
  S.lista = () => {
    const conversas = Object.values(S.conversas).map(c => { const u = c.msgs[c.msgs.length - 1], e = espera(c) > 0 && !c.resolvida; return { id: c.id, nome: c.nome, telefone: c.telefone, ultima_em: u.criado_em, ultima_direcao: u.direcao, trecho: u.texto, espera_voce: e, sem_resposta: espera(c), desde: u.criado_em, para_voce: e && !!c.sugestao, motivo: c.sugestao ? c.sugestao.motivo : null, tem_rascunho: !!c.rascunho, saiu: false, resolvida: c.resolvida, cora: cora(c) }; });
    conversas.sort((a, b) => (b.espera_voce - a.espera_voce));
    return { ok: true, conversas, aguardando: conversas.filter(x => x.espera_voce).length };
  };
  S.conversa = id => { const c = S.conversas[id]; return { ok: true, contato: { id: c.id, nome: c.nome, telefone: c.telefone, whatsapp: c.telefone, objetivo: '', resumo: c.resumo || '', saiu: false, idatleta: null, idlead: null }, mensagens: c.msgs, rascunho: c.rascunho, sugestao: c.sugestao, resolvido_em: null, cora: cora(c), esperando_resposta: c.resolvida ? 0 : espera(c) }; };
  S.tratar = (action, corpo, qs) => {
    S.chamadas.push({ action, corpo });
    const agora = '2026-11-11 10:00:00';
    switch (action) {
      case 'resumo': return { ok: true, prontidao: {}, funis: [], analises: {}, funis_padrao: [], conceitos: [], conceitos_padrao: [], refs: [], tags: [], testes: [], planos: {} };
      case 'inbox_lista': return S.lista();
      case 'inbox_conversa': return S.conversa(Number(new URLSearchParams(qs).get('id')));
      case 'inbox_registrar': { const c = S.conversas[corpo.id]; c.msgs.push({ idmensagem: S.proximo++, direcao: 'E', origem: 'lead', tipo: 'text', texto: corpo.texto, status: 'recebida', criado_em: agora }); c.rascunho = null; c.sugestao = null; c.resolvida = false; return { ok: true, saiu: false }; }
      case 'inbox_sugerir': { const c = S.conversas[corpo.id], t = c.msgs[c.msgs.length - 1].texto;
        if (/custa|valor/i.test(t)) { c.sugestao = { acao: 'passar_para_humano', motivo: 'preco', resumo: '' }; c.rascunho = null; return { ok: true, acao: 'passar_para_humano', motivo: 'preco', regra_fixa: true, custo_usd: 0 }; }
        c.rascunho = { texto: 'Oi, Ana! O teste grátis é só de treino, por 3 dias. Qual é o seu objetivo?', original: 'Oi, Ana! O teste grátis é só de treino, por 3 dias. Qual é o seu objetivo?', avisos: [] }; c.sugestao = null; return { ok: true, acao: 'responder', motivo: '', texto: c.rascunho.texto, regra_fixa: false, custo_usd: 0.0083 }; }
      case 'inbox_enviei': { const c = S.conversas[corpo.id]; c.msgs.push({ idmensagem: S.proximo++, direcao: 'S', origem: c.rascunho && c.rascunho.original === corpo.texto ? 'robo' : 'pessoa', tipo: 'text', texto: corpo.texto, status: 'enviada', criado_em: agora }); c.rascunho = null; c.sugestao = null; return { ok: true, origem: 'x' }; }
      case 'inbox_cora': S.conversas[corpo.id].cora = corpo.liberar ? 'manual' : null; return { ok: true };
      case 'inbox_resolver': S.conversas[corpo.id].resolvida = true; return { ok: true };
      case 'inbox_nova': { const id = S.proximo++; S.conversas[id] = { id, nome: corpo.nome || corpo.telefone, telefone: '55' + String(corpo.telefone).replace(/\D/g, ''), msgs: corpo.texto ? [{ idmensagem: S.proximo++, direcao: 'E', origem: 'lead', tipo: 'text', texto: corpo.texto, status: 'recebida', criado_em: agora }] : [{ idmensagem: S.proximo++, direcao: 'E', origem: 'lead', tipo: 'text', texto: '(sem texto)', status: 'recebida', criado_em: agora }], rascunho: null, sugestao: null, resolvida: false }; return { ok: true, id }; }
      case 'inbox_lead': { const id = S.proximo++; S.conversas[id] = { id, nome: 'Lia Contato', telefone: '5545991110012', msgs: [{ idmensagem: S.proximo++, direcao: 'S', origem: 'pessoa', tipo: 'text', texto: 'Oi, Lia!', status: 'enviada', criado_em: agora }], rascunho: null, sugestao: null, resolvida: false }; return { ok: true, id }; }
      case 'manual_estado': return { ok: true, resumo: { leads_no_periodo: 1, antes_da_data: 0, fora_da_origem: 0, sem_telefone: 0, pediram_para_sair: 0, encerrados: 0, conversa_de_pessoa: 0, sem_passo_agora: 0, em_recuperacao: 0, para_enviar: 1, aguardando: 0, enviadas_hoje: 0 },
        cfg: { origem: 'organico', desde: '2026-11-09' }, agora, no_horario: true, horario: [8, 21], meta: {}, biblioteca: [{ passo: 'p1', funil: 'f', funil_nome: 'F', funil_status: 'aprovado', nome: 'M1', aprovada: true }], aguardando: [], placar: [],
        para_enviar: [{ chave: 'l7', nome: 'Lia Contato', tipo: 'lead', etapa: 'contato', etapa_rotulo: 'Contato', origem_tipo: 'organico', canal: 'direto', criado_em: '2026-11-10 10:00:00', funil_nome: 'F', mensagem_nome: 'M1', passo: 'p1', devida_em: '2026-11-10 10:00:00', atraso_horas: 0, opcoes: { p1: 'Oi, Lia!' }, whatsapp: '5545991110012', telefone: '45991110012', treinos: 0 }] };
      default: return { ok: true };
    }
  };
  return S;
}

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p.endsWith('/')) p += 'index.html';
    const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f));
  });
  await new Promise(r => srv.listen(8112, '127.0.0.1', r));
  const b = await chromium.launch();
  const erros = [];
  async function abrir(largura) {
    const API = criarApi();
    const ctx = await b.newContext({ viewport: { width: largura, height: 900 }, serviceWorkers: 'block' });
    const page = await ctx.newPage(); page.on('pageerror', e => erros.push(String(e.message)));
    await page.route(/\/app\/api\/atendimento\.php/, r => {
      const u = new URL(r.request().url()), corpo = r.request().postData() ? JSON.parse(r.request().postData()) : null;
      r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(API.tratar(u.searchParams.get('action'), corpo, u.search)) });
    });
    await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-theme', 'dark'); });
    await page.goto('http://127.0.0.1:8112/atendimento/index.html', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.aba[data-aba="inbox"]'); await page.waitForTimeout(500);
    return { ctx, page, API };
  }

  console.log('\n══ Aba e lista (computador) ══');
  let { ctx, page, API } = await abrir(1200);
  eq('a aba existe e o contador mostra quantas esperam você', await page.textContent('#n-inbox'), '1');
  await page.click('.aba[data-aba="inbox"]'); await page.waitForSelector('.inbox');
  const nomes = await page.$$eval('.ib-lista .funil-item b', e => e.map(x => x.textContent));
  eq('as duas conversas aparecem, quem espera primeiro', nomes, ['Ana Lead', 'Bia Respondida']);
  ver('quem espera tem o selo "espera você"', (await page.textContent('.ib-lista .funil-item')).includes('espera você'));
  eq('sem conversa escolhida, o painel da direita só convida a escolher', (await page.textContent('.ib-conv')).includes('Escolha uma conversa'), true);

  console.log('\n══ Conversa, registrar e sugerir ══');
  await page.click('.ib-lista .funil-item'); await page.waitForSelector('#ib-chat');
  eq('a conversa mostra as mensagens, a da pessoa à esquerda', await page.$$eval('#ib-chat .bolha', e => e.map(x => x.className + ' | ' + x.firstChild.textContent)), ['bolha lead | Oi, como funciona o teste?']);
  ver('o link do WhatsApp aponta para o número da pessoa', (await page.getAttribute('#ib-wa', 'href')).startsWith('https://wa.me/5545991110001'));
  await page.fill('#ib-lead', 'Quero treinar em casa com pouco tempo');
  await page.click('#ib-reg-sug'); await page.waitForSelector('#ib-resp');
  await page.waitForFunction(() => document.querySelector('#ib-resp').value.length > 0);
  eq('as chamadas foram registrar e depois sugerir', API.chamadas.filter(c => c.action === 'inbox_registrar' || c.action === 'inbox_sugerir').map(c => c.action), ['inbox_registrar', 'inbox_sugerir']);
  eq('a sugestão da Cora entra na caixa de resposta', (await page.inputValue('#ib-resp')).startsWith('Oi, Ana! O teste grátis'), true);
  eq('a mensagem colada aparece na conversa', (await page.$$eval('#ib-chat .bolha', e => e.length)), 2);
  await page.fill('#ib-resp', 'Oi, Ana! O teste grátis é só de treino. Qual é o seu objetivo hoje?');
  const href = await page.getAttribute('#ib-wa', 'href');
  eq('editar o texto atualiza o link do WhatsApp na hora', decodeURIComponent(href.split('?text=')[1]), 'Oi, Ana! O teste grátis é só de treino. Qual é o seu objetivo hoje?');
  await page.click('#ib-enviei'); await page.waitForFunction(() => document.querySelectorAll('#ib-chat .bolha').length === 3);
  const env = API.chamadas.filter(c => c.action === 'inbox_enviei')[0];
  eq('"Já enviei" manda o texto editado', env.corpo.texto, 'Oi, Ana! O teste grátis é só de treino. Qual é o seu objetivo hoje?');
  eq('a resposta entra à direita, como da equipe', (await page.$$eval('#ib-chat .bolha.nos small', e => e.map(x => x.textContent)))[0].startsWith('equipe'), true);
  eq('depois de enviar, a conversa não espera mais e o contador zera', [await page.textContent('#n-inbox'), (await page.textContent('.ib-conv .card')).includes('espera você')], ['0', false]);

  console.log('\n══ Liberar a Cora por conversa e filtro ══');
  ver('a lista mostra o selo "Cora liberada" só em quem está liberado', await page.$$eval('.ib-lista .funil-item', e => e.map(x => x.textContent.includes('Cora liberada'))).then(a => JSON.stringify(a) === JSON.stringify([false, true])));
  eq('na conversa, o selo diz que a Cora não está liberada e o botão oferece liberar', [(await page.textContent('.ib-conv .badge:has-text("Cora")')).trim(), await page.textContent('#ib-cora')], ['Cora não liberada', 'Liberar a Cora nesta conversa']);
  await page.click('#ib-cora'); await page.waitForFunction(() => document.querySelector('#ib-cora').textContent.startsWith('Tirar'));
  eq('liberar chama o servidor e o botão passa a oferecer tirar', [API.chamadas.filter(c => c.action === 'inbox_cora').map(c => c.corpo.liberar), (await page.textContent('.ib-conv .badge:has-text("Cora")')).trim()], [[true], 'Cora liberada por você']);
  await page.selectOption('#ib-filtro', 'sem_cora');
  eq('filtro "Cora não liberada" esconde as liberadas', await page.$$eval('.ib-lista .funil-item b', e => e.length), 0);
  await page.selectOption('#ib-filtro', 'cora');
  eq('filtro "Cora liberada" mostra as duas agora', await page.$$eval('.ib-lista .funil-item b', e => e.map(x => x.textContent)), ['Ana Lead', 'Bia Respondida']);
  await page.click('#ib-cora'); await page.waitForFunction(() => document.querySelector('#ib-cora').textContent.startsWith('Liberar'));
  await page.selectOption('#ib-filtro', 'todas');

  console.log('\n══ É para você (regra fixa) ══');
  await page.fill('#ib-lead', 'Quanto custa a consultoria?');
  await page.click('#ib-reg-sug'); await page.waitForSelector('.aviso.warn');
  const aviso = await page.textContent('.aviso.warn');
  eq('aviso claro de que é para você, com o motivo', [aviso.includes('Esta é para você'), aviso.includes('valores')], [true, true]);
  eq('a caixa de resposta fica vazia para você escrever', await page.inputValue('#ib-resp'), '');
  eq('a lista marca "para você"', (await page.textContent('.ib-lista .funil-item')).includes('para você'), true);
  await page.click('#ib-resolver'); await page.waitForFunction(() => !document.querySelector('.ib-conv .badge.ambar'));
  eq('"Sem resposta" tira a conversa de esperando você', await page.textContent('#n-inbox'), '0');

  console.log('\n══ Nova conversa e conversa a partir da fila ══');
  await page.click('#ib-nova-bt');
  await page.fill('#ib-n-tel', '45 99999-0000'); await page.fill('#ib-n-nome', 'Caio Novo'); await page.fill('#ib-n-txt', 'Oi, vi vocês no Instagram');
  await page.click('#ib-n-criar'); await page.waitForFunction(() => document.querySelector('.ib-conv h2') && document.querySelector('.ib-conv h2').textContent === 'Caio Novo');
  eq('conversa nova criada com a mensagem colada e já aberta', [API.chamadas.filter(c => c.action === 'inbox_nova')[0].corpo.nome, (await page.$$eval('#ib-chat .bolha', e => e.length))], ['Caio Novo', 1]);
  await page.click('.aba[data-aba="fila"]'); await page.waitForSelector('[data-fila="conversa"]');
  await page.click('[data-fila="conversa"]'); await page.waitForFunction(() => document.querySelector('.ib-conv h2') && document.querySelector('.ib-conv h2').textContent === 'Lia Contato');
  eq('"Conversa" na Fila de hoje abre a caixa de entrada naquela pessoa', [API.chamadas.filter(c => c.action === 'inbox_lead')[0].corpo.chave, await page.getAttribute('.aba[data-aba="inbox"]', 'aria-selected')], ['l7', 'true']);
  await ctx.close();

  console.log('\n══ Celular ══');
  ({ ctx, page, API } = await abrir(390));
  await page.click('.aba[data-aba="inbox"]'); await page.waitForSelector('.inbox');
  eq('sem conversa escolhida só a lista aparece', [await page.isVisible('.ib-lista'), await page.isVisible('.ib-conv')], [true, false]);
  await page.click('.ib-lista .funil-item'); await page.waitForSelector('#ib-chat');
  eq('com conversa escolhida só ela aparece, com o botão de voltar', [await page.isVisible('.ib-lista'), await page.isVisible('.ib-conv'), await page.isVisible('#ib-voltar')], [false, true, true]);
  const larg = await page.evaluate(() => [document.documentElement.scrollWidth, innerWidth]);
  eq('nada vaza para o lado', larg[0] <= larg[1], true);
  await page.click('#ib-voltar'); eq('voltar mostra a lista', await page.isVisible('.ib-lista'), true);
  await ctx.close();

  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S) de ' + (ok + ruim) : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
