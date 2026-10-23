// Quadro (kanban) de leads na Central de atendimento e em Pessoas Captadas, no navegador, com a API de mentira.
//   cd testes && INTUS_DIR=../app/painel node kanban.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const SITE = path.resolve(process.env.INTUS_DIR || '../app/painel', '../..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.ico': 'image/x-icon' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const ver = (rot, c, extra) => { eq(rot, !!c, true); if (!c && extra !== undefined) console.log('          ' + JSON.stringify(extra)); };

const ctl = (status, extra) => ({ ultimo: Object.assign({ status, status_rotulo: status, canal: 'whatsapp_link', nota: '', retorno_em: null, usuario: 'Luiz Nunes', criado_em: '2026-10-18 15:00:00' }, extra || {}), total: 1, historico: [] });
const temp = (nivel, extra) => Object.assign({ nivel, manual: false, auto: nivel, motivos: nivel === 'quente' ? ['respondeu'] : [] }, extra || {});
function pessoa(o) {
  return Object.assign({ tipo: 'conta', id: 1, medido: true, criado_em: '2026-10-18 10:00:00', canal: 'instagram', utm: {}, indicado_por: null, telefone: '(45) 99999-1111', whatsapp: '5545999991111', email: 'a@t.com', objetivo: '', treinos: 0, ultimo_treino: null, abriu_app: false, aberturas: 0, teste: null, matricula: null, bloqueado: false, acao: '', mensagem: 'Oi, tudo bem?',
    wa: { contato: false, telefone_valido: true, saiu: false, enviadas: 0, recebidas: 0, respondeu: false }, controle: { ultimo: null, total: 0, historico: [] }, grupo_contato: 'nunca', temperatura: temp('frio') }, o);
}
const PESSOAS = () => [
  pessoa({ chave: 'a1', id: 1, nome: 'Ana Souza', etapa: 'so_conta', etapa_rotulo: 'Criou o acesso', controle: ctl('retornar', { retorno_em: '2026-10-10' }), grupo_contato: 'retorno', temperatura: temp('morno') }),
  pessoa({ chave: 'a2', id: 2, nome: 'Daniel <b>Rocha</b>', etapa: 'teste_expirado', etapa_rotulo: 'Teste acabou, sem comprar', treinos: 3, abriu_app: true, temperatura: temp('quente') }),
  pessoa({ chave: 'a3', id: 3, nome: 'Carla Dias', etapa: 'treinando', etapa_rotulo: 'Em teste: já treinou', wa: { contato: true, telefone_valido: true, saiu: true, enviadas: 3, recebidas: 0, respondeu: false }, controle: ctl('sem_interesse'), grupo_contato: 'encerrado' }),
  pessoa({ chave: 'a5', id: 5, nome: 'Elisa Prado', etapa: 'cliente', etapa_rotulo: 'Virou cliente', grupo_contato: 'cliente', temperatura: temp('cliente') }),
  pessoa({ chave: 'l1', tipo: 'contato', id: 1, nome: 'Gabriela Alves', telefone: '45933337777', whatsapp: '5545933337777', etapa: 'contato', etapa_rotulo: 'Deixou só o contato' }),
  pessoa({ chave: 'a8', id: 8, nome: 'Heitor Cruz', etapa: 'so_conta', etapa_rotulo: 'Criou o acesso', telefone: '', whatsapp: '', wa: { contato: false, telefone_valido: false, saiu: false, enviadas: 0, recebidas: 0, respondeu: false } }),
  pessoa({ chave: 'a10', id: 10, nome: 'Julia Saiu', etapa: 'so_conta', etapa_rotulo: 'Criou o acesso', wa: { contato: true, telefone_valido: true, saiu: true, enviadas: 1, recebidas: 0, respondeu: false } }),
  pessoa({ chave: 'a9', id: 9, nome: 'Iara Melo', etapa: 'abriu_app', etapa_rotulo: 'Abriu o app', abriu_app: true, controle: ctl('respondeu'), grupo_contato: 'contatado', temperatura: temp('morno') }),
];

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]); if (p.endsWith('/')) p += 'index.html';
    const f = path.join(SITE, p);
    if (!f.startsWith(SITE) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8097, '127.0.0.1', r));
  const B = 'http://127.0.0.1:8097';
  const browser = await chromium.launch();
  const json = (r, o, status) => r.fulfill({ status: status || 200, contentType: 'application/json', body: JSON.stringify(o) });

  async function abrir(url, usuario) {
    const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, serviceWorkers: 'block' });
    await c.addInitScript((u) => { try { localStorage.setItem('mx-token', 'teste'); localStorage.setItem('mx-user', JSON.stringify(u)); } catch (e) {} }, usuario);
    const pg = await c.newPage(); const er = [], posts = [];
    pg.on('pageerror', e => { if (!/Failed to update a ServiceWorker/i.test(String(e.message))) er.push(String(e.message)); });
    let pessoas = PESSOAS();
    await pg.route('**/trafego.php**', r => {
      const u = r.request().url();
      if (r.request().method() === 'POST') {
        const b = JSON.parse(r.request().postData() || '{}'); posts.push({ via: 'trafego', b });
        const reg = { status: b.status, status_rotulo: b.status, canal: b.canal, nota: b.nota || '', retorno_em: b.retorno_em || null, usuario: 'Luiz Nunes', criado_em: '2026-10-20 12:00:00' };
        const p = pessoas.find(x => x.chave === b.chave);
        if (p) { p.controle = { ultimo: reg, total: 1, historico: [reg] }; p.grupo_contato = ['sem_interesse', 'fechou', 'numero_errado'].includes(b.status) ? 'encerrado' : 'contatado'; }
        return json(r, { ok: true, registro: reg, controle: { ultimo: reg, total: 1, historico: [reg] } });
      }
      if (/action=leads/.test(u)) return json(r, { ok: true, periodo: { de: '2026-09-20', ate: '2026-10-19' }, captadas_medidas: 6, sem_medicao: 0, gerado_em: '', hoje: '2026-10-19', etapas: [], resumo: {}, pessoas });
      return json(r, { ok: true });
    });
    await pg.route('**/atendimento.php**', r => {
      const u = new URL(r.request().url()), a = u.searchParams.get('action');
      if (r.request().method() === 'POST') {
        const b = JSON.parse(r.request().postData() || '{}'); posts.push({ via: 'atendimento', a, b });
        if (a === 'manual_acao' && b.acao === 'temperatura') { const p = pessoas.find(x => x.chave === b.chave); if (p) p.temperatura = b.valor === 'auto' ? temp(p.temperatura.auto) : temp(b.valor, { manual: true, auto: p.temperatura.auto }); }
        return json(r, { ok: true, erro: null, ja: false });
      }
      if (a === 'resumo') return json(r, { ok: true, funis: [], refs: [] });
      if (a === 'leads') return json(r, { ok: true, periodo: {}, resumo_contato: { nao_contatados: 0, contatados: 0, retornos_pendentes: 0, encerrados: 0 }, whatsapp: {}, por_canal: [], pessoas });
      if (a === 'manual_estado') return json(r, { ok: true, cfg: { origem: 'organico', desde: '2026-10-18' }, agora: '2026-10-20 12:00:00', no_horario: true, horario: [8, 21], para_enviar: [], aguardando: [], placar: [], biblioteca: [], resumo: { leads_no_periodo: 0, fora_da_origem: 0, sem_telefone: 0, pediram_para_sair: 0, encerrados: 0, conversa_de_pessoa: 0, sem_passo_agora: 0, para_enviar: 0, aguardando: 0, enviadas_hoje: 0, em_recuperacao: 0, antes_da_data: 0 }, meta: { usada: false, configurada: false, automacao_ligada: false } });
      return json(r, { ok: true });
    });
    await pg.route('**/usuarios.php**', r => json(r, { ok: true, usuario: { server_token: 'teste' } }));
    await pg.route(/\/app\/api\/(?!trafego\.php|atendimento\.php|usuarios\.php)/, r => r.abort('failed'));
    await pg.route(/fonts\.(googleapis|gstatic)\.com/, r => r.abort());
    await pg.goto(B + url, { waitUntil: 'load' }); await pg.waitForTimeout(1500);
    return { pg, er, posts, c };
  }
  const cols = (pg) => pg.evaluate(() => [...document.querySelectorAll('.kb-col')].map(c => c.getAttribute('aria-label') + ':' + c.querySelector('.kb-n').textContent));
  const dentro = (pg, col) => pg.evaluate((c) => [...document.querySelectorAll('.kb-col[data-col="' + c + '"] .kb-card')].map(x => x.getAttribute('data-id')), col);
  async function arrastar(pg, id, paraCol) {
    return pg.evaluate(({ id, paraCol }) => {
      const card = document.querySelector('.kb-card[data-id="' + id + '"]'), alvo = document.querySelector('.kb-col[data-col="' + paraCol + '"] .kb-corpo');
      const dt = new DataTransfer();
      card.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: dt }));
      const ev = new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }); alvo.dispatchEvent(ev);
      const aceitou = ev.defaultPrevented;
      alvo.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }));
      card.dispatchEvent(new DragEvent('dragend', { bubbles: true }));
      return aceitou;
    }, { id, paraCol });
  }

  console.log('\n══ Pessoas Captadas: quadro ══');
  const cap = await abrir('/app/painel/captados.html?de=2026-09-20&ate=2026-10-19', { tipo: 'admin', idatleta: 0, idusuario: 1, admin: 1, nome: 'Luiz Nunes', permissoes: {} });
  ver('abre em lista, como sempre', await cap.pg.evaluate(() => !!document.querySelector('#cp-lista table.cp-tab') && !document.querySelector('.kb')));
  await cap.pg.selectOption('select[aria-label="Como mostrar"]', 'etapa'); await cap.pg.waitForTimeout(300);
  eq('quadro por etapa: 7 colunas na ordem do funil, com as pessoas', await cols(cap.pg), ['Contato sem conta:1', 'Só criou a conta:3', 'Abriu o app:1', 'Treinando no teste:1', 'Teste acabou, sem comprar:1', 'Matrícula a pagar:0', 'Cliente:1']);
  eq('por etapa é só leitura: ninguém arrasta e não há "Mover para"', await cap.pg.evaluate(() => [document.querySelectorAll('.kb-card[draggable="true"]').length, document.querySelectorAll('[data-kb-mover]').length]), [0, 0]);
  eq('nome vindo da internet nunca vira HTML', await cap.pg.evaluate(() => document.querySelectorAll('.kb-card b').length), 0);
  await cap.pg.selectOption('select[aria-label="Como mostrar"]', 'atendimento'); await cap.pg.waitForTimeout(300);
  eq('quadro do atendimento: onde cada pessoa está pelo último contato', await cols(cap.pg), ['Para chamar:3', 'Chamado, aguardando:0', 'Respondeu:1', 'Retornar depois:1', 'Fechou:0', 'Sem interesse:2', 'Já é cliente:1']);
  eq('quem pediu para sair e cliente não se movem', await cap.pg.evaluate(() => [document.querySelector('.kb-card[data-id="a3"]').getAttribute('draggable'), document.querySelector('.kb-card[data-id="a5"]').getAttribute('draggable')]), ['false', 'false']);
  const aceitouCliente = await arrastar(cap.pg, 'a2', 'cliente');
  eq('"Para chamar" e "Já é cliente" não aceitam cartão arrastado', [aceitouCliente, await arrastar(cap.pg, 'a9', 'para_chamar')], [false, false]);
  eq('nada foi gravado por soltar onde não pode', cap.posts.length, 0);
  const aceitou = await arrastar(cap.pg, 'a2', 'chamado'); await cap.pg.waitForTimeout(500);
  eq('arrastar para "Chamado" é aceito e grava o contato (status chamado, canal outro)', [aceitou, cap.posts.length, cap.posts[0] && cap.posts[0].b.status, cap.posts[0] && cap.posts[0].b.canal, cap.posts[0] && cap.posts[0].b.chave], [true, 1, 'chamado', 'outro', 'a2']);
  eq('depois de gravar o cartão aparece na coluna nova', await dentro(cap.pg, 'chamado'), ['a2']);
  await cap.pg.evaluate(() => { const s = document.querySelector('.kb-card[data-id="l1"] [data-kb-mover]'); s.value = 'retornar'; s.dispatchEvent(new Event('change', { bubbles: true })); }); await cap.pg.waitForTimeout(500);
  const pr = cap.posts[1] && cap.posts[1].b;
  eq('"Mover para" (celular) funciona, e retornar marca daqui a 2 dias', [pr && pr.status, pr && /^\d{4}-\d{2}-\d{2}$/.test(pr.retorno_em || ''), (await dentro(cap.pg, 'retornar')).includes('l1')], ['retornar', true, true]);
  cap.pg.on('dialog', d => d.dismiss());
  await cap.pg.evaluate(() => { const s = document.querySelector('.kb-card[data-id="a8"] [data-kb-mover]'); s.value = 'encerrado'; s.dispatchEvent(new Event('change', { bubbles: true })); }); await cap.pg.waitForTimeout(400);
  eq('"sem interesse" pede confirmação e, recusada, não grava nada', [cap.posts.length, (await dentro(cap.pg, 'encerrado')).includes('a8')], [2, false]);
  await cap.pg.reload({ waitUntil: 'load' }); await cap.pg.waitForTimeout(1200);
  eq('a escolha da visão fica guardada neste navegador', await cap.pg.evaluate(() => document.querySelector('select[aria-label="Como mostrar"]').value), 'atendimento');
  await cap.pg.fill('#cp-busca', 'gabriela'); await cap.pg.waitForTimeout(250);
  eq('a busca também filtra o quadro', await cap.pg.evaluate(() => [...document.querySelectorAll('.kb-card')].map(c => c.getAttribute('data-id'))), ['l1']);
  await cap.pg.fill('#cp-busca', '');
  await cap.pg.selectOption('select[aria-label="Como mostrar"]', 'lista'); await cap.pg.waitForTimeout(250);
  ver('voltar para a lista mostra a tabela de sempre', await cap.pg.evaluate(() => !!document.querySelector('#cp-lista table.cp-tab') && !document.querySelector('.kb')));
  eq('sem erro de script em Pessoas Captadas', cap.er, []);

  console.log('\n══ Central de atendimento: Quadro ══');
  const ce = await abrir('/atendimento/', { tipo: 'admin', idusuario: 1, admin: 1, nome: 'Luiz Nunes', permissoes: {} });
  await ce.pg.click('[data-aba="quadro"]'); await ce.pg.waitForTimeout(800);
  eq('por etapa do funil: colunas e contagens', await cols(ce.pg), ['Contato sem conta:1', 'Só criou a conta:3', 'Abriu o app:1', 'Treinando no teste:1', 'Teste acabou, sem comprar:1', 'Matrícula a pagar:0', 'Cliente:1']);
  eq('cada cartão mostra a temperatura (quente, morno, frio, cliente)', await ce.pg.evaluate(() => [...document.querySelectorAll('.kb-card')].map(c => c.getAttribute('data-id') + ':' + c.querySelector('.badge').textContent.trim()).sort()), ['a10:Frio', 'a1:Morno', 'a2:Quente', 'a3:Frio', 'a5:Cliente', 'a8:Frio', 'a9:Morno', 'l1:Frio']);
  eq('por etapa é só leitura', await ce.pg.evaluate(() => document.querySelectorAll('[data-kb-mover]').length), 0);
  eq('nome com HTML não vira HTML', await ce.pg.evaluate(() => document.querySelectorAll('.kb-card b').length), 0);
  const zap = await ce.pg.evaluate(() => [...document.querySelectorAll('.kb-card[data-id="a2"] a[data-q-chamar]')].map(a => a.getAttribute('href')));
  ver('botão WhatsApp com número e mensagem', zap.length === 1 && zap[0].startsWith('https://wa.me/5545999991111?text='), zap);
  eq('cliente e quem pediu para sair não têm botão de WhatsApp', await ce.pg.evaluate(() => [document.querySelectorAll('.kb-card[data-id="a5"] a[data-q-chamar]').length, document.querySelectorAll('.kb-card[data-id="a3"] a[data-q-chamar]').length]), [0, 0]);
  await ce.pg.selectOption('#q-agrupar', 'temperatura'); await ce.pg.waitForTimeout(300);
  eq('por temperatura: quente, morno, frio e clientes', await cols(ce.pg), ['Quente:1', 'Morno:2', 'Frio:4', 'Já é cliente:1']);
  eq('arrastar para "Quente" fixa a temperatura no servidor', [await arrastar(ce.pg, 'a9', 'quente')], [true]);
  await ce.pg.waitForTimeout(500);
  const pt = ce.posts.find(p => p.via === 'atendimento' && p.b.acao === 'temperatura');
  eq('o pedido leva a pessoa e o valor, sem telefone nem nome', [pt && pt.a, pt && pt.b.chave, pt && pt.b.valor, Object.keys(pt ? pt.b : {}).sort()], ['manual_acao', 'a9', 'quente', ['acao', 'chave', 'valor']]);
  eq('o cartão muda de coluna e fica marcado como fixada', [(await dentro(ce.pg, 'quente')).sort(), await ce.pg.evaluate(() => /fixada/.test(document.querySelector('.kb-card[data-id="a9"] .badge').textContent))], [['a2', 'a9'], true]);
  await ce.pg.evaluate(() => { const s = document.querySelector('.kb-card[data-id="a9"] [data-q-temp]'); s.value = 'auto'; s.dispatchEvent(new Event('change', { bubbles: true })); }); await ce.pg.waitForTimeout(500);
  eq('"Automática" devolve ao cálculo (volta para Morno)', (await dentro(ce.pg, 'morno')).includes('a9'), true);
  eq('cliente não tem seletor de temperatura nem se move', await ce.pg.evaluate(() => [document.querySelectorAll('.kb-card[data-id="a5"] [data-q-temp]').length, document.querySelector('.kb-card[data-id="a5"]').getAttribute('draggable')]), [0, 'false']);
  await ce.pg.selectOption('#q-agrupar', 'atendimento'); await ce.pg.waitForTimeout(300);
  eq('por atendimento: colunas', await cols(ce.pg), ['Para chamar:3', 'Chamado, aguardando:0', 'Respondeu:1', 'Retornar depois:1', 'Fechou:0', 'Sem interesse:2', 'Já é cliente:1']);
  const antes = ce.posts.length;
  await arrastar(ce.pg, 'l1', 'respondeu'); await ce.pg.waitForTimeout(700);
  const pc = ce.posts.slice(antes).find(p => p.via === 'trafego');
  eq('arrastar para "Respondeu" registra o contato em Pessoas Captadas (mesmo endpoint)', [pc && pc.b.status, pc && pc.b.chave, pc && pc.b.canal], ['respondeu', 'l1', 'outro']);
  eq('e o cartão passa a aparecer em "Respondeu" depois de o servidor confirmar', (await dentro(ce.pg, 'respondeu')).includes('l1'), true);
  eq('quem pediu para sair, mesmo sem nenhum contato registrado, aparece em "Sem interesse"', (await dentro(ce.pg, 'encerrado')).sort(), ['a10', 'a3']);
  await ce.pg.evaluate(() => { const s = document.querySelector('.kb-card[data-id="a2"] [data-kb-mover]'); s.value = 'retornar'; s.dispatchEvent(new Event('change', { bubbles: true })); }); await ce.pg.waitForTimeout(700);
  const pr2 = ce.posts.filter(p => p.via === 'trafego').pop();
  eq('na Central, "Retornar depois" grava o retorno para daqui a 2 dias', [pr2.b.status, /^\d{4}-\d{2}-\d{2}$/.test(pr2.b.retorno_em || '')], ['retornar', true]);
  await ce.pg.fill('#q-busca', 'daniel'); await ce.pg.waitForTimeout(250);
  eq('a busca filtra o quadro', await ce.pg.evaluate(() => [...document.querySelectorAll('.kb-card')].map(c => c.getAttribute('data-id'))), ['a2']);
  // barra de rolagem horizontal também em cima, espelhando a de baixo
  await ce.pg.selectOption('#q-agrupar', 'etapa'); await ce.pg.waitForTimeout(500);
  const topo = await ce.pg.evaluate(() => { const t = document.querySelector('.kb-topo'), q = document.querySelector('.kb'); return { acima: !!t && !!q && t.compareDocumentPosition(q) & Node.DOCUMENT_POSITION_FOLLOWING ? true : false, visivel: !!t && getComputedStyle(t).display !== 'none', larguraIgual: !!t && Math.abs(t.firstChild.getBoundingClientRect().width - q.scrollWidth) <= 1 }; });
  eq('com mais colunas do que cabem, a barra de cima aparece, acima do quadro e com a mesma largura útil', topo, { acima: true, visivel: true, larguraIgual: true });
  const sinc = await ce.pg.evaluate(async () => {
    const t = document.querySelector('.kb-topo'), q = document.querySelector('.kb'), w = ms => new Promise(r => setTimeout(r, ms));
    // o quadro encaixa nas colunas (a cada 300 px), então os testes usam posições de encaixe
    t.scrollLeft = 300; await w(150); const aCima = Math.abs(q.scrollLeft - 300) <= 3 && Math.abs(t.scrollLeft - 300) <= 3;
    q.scrollLeft = 600; await w(150); const aBaixo = Math.abs(t.scrollLeft - 600) <= 3 && Math.abs(q.scrollLeft - 600) <= 3;
    return { aCima, aBaixo };
  });
  eq('mexer na barra de cima move o quadro e mexer no quadro move a barra de cima', sinc, { aCima: true, aBaixo: true });
  const cabe = await ce.pg.evaluate(() => {
    const d = document.createElement('div'); d.style.width = '900px'; document.body.appendChild(d);
    const q = Kanban.montar(d, { colunas: [{ id: 'a', titulo: 'A' }, { id: 'b', titulo: 'B' }], cartoes: [], vazio: '-' });
    const t = d.querySelector('.kb-topo'); const some = getComputedStyle(t).display === 'none'; q.destruir(); d.remove(); return some;
  });
  eq('quando o quadro cabe na tela a barra de cima some', cabe, true);
  await ce.pg.setViewportSize({ width: 375, height: 800 }); await ce.pg.waitForTimeout(250);
  eq('no celular a página não rola para o lado (só o quadro)', await ce.pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  eq('sem erro de script na Central', ce.er, []);

  await browser.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S) de ' + (ok + ruim) : 'OK: ' + ok + ' conferencias passaram.'));
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
