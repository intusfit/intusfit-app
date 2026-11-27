// Lembretes de refeições e água (25/11/2026): horários vêm do plano, antecedência, água por intervalo, cancelamento sem plano,
// permissão negada e ajuste quando a nutri muda o plano.
//   cd testes && INTUS_DIR=../app/painel node lembretes_nutri.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
const T = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.png': 'image/png', '.json': 'application/json' };
(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end(); } res.writeHead(200, { 'content-type': T[path.extname(f)] || 'application/octet-stream' }); res.end(fs.readFileSync(f)); });
  await new Promise(r => srv.listen(0, '127.0.0.1', r)); const base = 'http://127.0.0.1:' + srv.address().port;
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const page = await ctx.newPage(); const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => {
    localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste', genero: 'F' })); localStorage.setItem('intus-termos-aceito-7', '1'); localStorage.setItem('intus-dica-tema-ts', String(Date.now()));
    window.__ln = { agendados: [], cancelados: [], perm: 'granted', canais: [], ouvintes: {} };
    window.Capacitor = { Plugins: { LocalNotifications: {
      checkPermissions: async () => ({ display: window.__ln.perm }),
      requestPermissions: async () => ({ display: window.__ln.perm }),
      createChannel: async (c) => { window.__ln.canais.push(c.id); },
      getPending: async () => ({ notifications: window.__ln.agendados.map(a => ({ id: a.id, extra: a.extra })) }),
      cancel: async (o) => { o.notifications.forEach(n => { window.__ln.cancelados.push(n.id); window.__ln.agendados = window.__ln.agendados.filter(a => a.id !== n.id); }); },
      schedule: async (o) => { o.notifications.forEach(n => window.__ln.agendados.push(n)); },
      addListener: (nome, fn) => { window.__ln.ouvintes[nome] = fn; return Promise.resolve({ remove() {} }); },
    } } };
  });
  await page.goto(base + '/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => !!window.LembretesNutri && typeof _comSheet === 'function' && typeof API !== 'undefined' && !!API.nutri).catch(() => false)) break; await page.waitForTimeout(250); }

  const plano = { idplano: 1, ativo: 1, refeicoes: [{ horario: '19:45', nome: 'Jantar' }, { horario: '07:30', nome: 'Café da manhã' }, { horario: '12:00', nome: 'Almoço' }, { horario: '00:05', nome: 'Ceia' }, { horario: 'sem hora', nome: 'Lanche' }] };
  console.log('\n══ Lista de avisos (a partir do plano) ══');
  const lista = (cfg) => page.evaluate(([p, c]) => LembretesNutri._teste.montarLista(p, Object.assign({ ativo: true, refeicoes: true, antes: 10, agua: false, aguaIni: '08:00', aguaFim: '21:00', aguaInt: 120 }, c), 2800).map(l => l.tipo + ' ' + l.hora), [plano, cfg]);
  eq('refeições 10 min antes do horário, na ordem do dia (sem hora válida fica de fora; passa da meia-noite)', await lista({}), ['refeicao 23:55', 'refeicao 07:20', 'refeicao 11:50', 'refeicao 19:35']);
  eq('na hora', await lista({ antes: 0 }), ['refeicao 00:05', 'refeicao 07:30', 'refeicao 12:00', 'refeicao 19:45']);
  eq('água de 2 em 2 h entre 08:00 e 21:00', await lista({ refeicoes: false, agua: true }), ['agua 08:00', 'agua 10:00', 'agua 12:00', 'agua 14:00', 'agua 16:00', 'agua 18:00', 'agua 20:00']);
  eq('água de hora em hora respeita o limite de avisos por dia', (await lista({ refeicoes: false, agua: true, aguaInt: 60, aguaIni: '06:00', aguaFim: '23:00' })).length, 14);
  eq('desligado não gera nada', await lista({ ativo: false }), []);
  eq('janela de água invertida (fim antes do começo) não gera aviso', await lista({ refeicoes: false, agua: true, aguaIni: '21:00', aguaFim: '08:00' }), []);

  console.log('\n══ Agendamento no sistema ══');
  const ag = (cfg, p) => page.evaluate(async ([cfg, p]) => {
    window.__ln.agendados = []; window.__ln.cancelados = [];
    localStorage.setItem('intus-nutri-lemb-7', JSON.stringify(cfg));
    const r = await LembretesNutri._teste.agendar(p);
    return { r, n: window.__ln.agendados.length, primeiro: window.__ln.agendados[0] && { on: window.__ln.agendados[0].schedule.on, repeats: window.__ln.agendados[0].schedule.repeats, canal: window.__ln.agendados[0].channelId, tipo: window.__ln.agendados[0].extra.tipo, titulo: window.__ln.agendados[0].title }, canais: window.__ln.canais };
  }, [cfg, p]);
  let r = await ag({ ativo: true, refeicoes: true, antes: 10, agua: true, aguaIni: '08:00', aguaFim: '20:00', aguaInt: 180 }, plano);
  eq('agenda 4 refeições e 5 de água (08, 11, 14, 17, 20), repetindo todo dia', [r.r.ok, r.n, r.primeiro.on, r.primeiro.repeats, r.primeiro.canal, r.primeiro.tipo], [true, 9, { hour: 23, minute: 55 }, true, 'nutricao', 'nutri-refeicao']);
  eq('o texto do aviso traz o nome da refeição e a antecedência', r.primeiro.titulo, '🍽️ Ceia em 10 min');
  r = await ag({ ativo: true, refeicoes: true, antes: 0, agua: false, aguaIni: '08:00', aguaFim: '20:00', aguaInt: 120 }, null);
  eq('sem plano ativo nada é agendado (e o que havia é cancelado)', [r.r.ok, r.n], [true, 0]);
  r = await ag({ ativo: false, refeicoes: true, antes: 0, agua: true, aguaIni: '08:00', aguaFim: '20:00', aguaInt: 120 }, plano);
  eq('desligado não agenda', [r.r.ok, r.n], [true, 0]);
  await page.evaluate(() => { window.__ln.perm = 'denied'; });
  r = await ag({ ativo: true, refeicoes: true, antes: 0, agua: true, aguaIni: '08:00', aguaFim: '20:00', aguaInt: 120 }, plano);
  eq('sem permissão de notificação: avisa e não agenda', [r.r.ok, r.r.motivo, r.n], [false, 'permissao', 0]);
  await page.evaluate(() => { window.__ln.perm = 'granted'; });
  r = await ag({ ativo: true, refeicoes: true, antes: 0, agua: true, aguaIni: '08:00', aguaFim: '20:00', aguaInt: 120 }, plano);
  const mudar = await page.evaluate(async (p) => {      // reagendar não duplica: cancela o que era dele antes
    const antes = window.__ln.agendados.length; window.__ln.cancelados = [];
    await LembretesNutri._teste.agendar(p);
    return { antes, depois: window.__ln.agendados.length, cancelou: window.__ln.cancelados.length };
  }, plano);
  eq('reagendar substitui os avisos antigos (sem duplicar)', [mudar.antes, mudar.depois, mudar.cancelou], [mudar.depois, mudar.depois, mudar.depois]);

  console.log('\n══ A nutri muda o plano ══');
  const sinc = await page.evaluate(async (p) => {
    const total = () => window.__ln.agendados.filter(a => a.extra.tipo === 'nutri-refeicao').map(a => a.schedule.on.hour * 60 + a.schedule.on.minute);
    localStorage.setItem('intus-nutri-lemb-7', JSON.stringify({ ativo: true, refeicoes: true, antes: 0, agua: false, aguaIni: '08:00', aguaFim: '20:00', aguaInt: 120 }));
    localStorage.removeItem('intus-nutri-lemb-sig-7');
    let atual = p;
    API.listarPlanosNutricionais = async () => [atual];
    await LembretesNutri.sincronizar(true);
    const a = total().sort((x, y) => x - y);
    atual = JSON.parse(JSON.stringify(p)); atual.refeicoes[1].horario = '08:15';    // o café passou de 07:30 para 08:15
    await LembretesNutri.sincronizar(true);
    const b = total().sort((x, y) => x - y);
    atual = Object.assign({}, p, { ativo: 0 });                                    // plano inativado
    await LembretesNutri.sincronizar(true);
    return { a, b, c: window.__ln.agendados.length };
  }, plano);
  eq('antes da mudança: café às 07:30', sinc.a.includes(450), true);
  eq('depois da mudança: o aviso do café passa para 08:15 e o de 07:30 some', [sinc.b.includes(495), sinc.b.includes(450)], [true, false]);
  eq('plano inativado: todos os lembretes são cancelados', sinc.c, 0);

  console.log('\n══ Tela ══');
  eq('o cartão só aparece para quem tem plano alimentar ativo', await page.evaluate(() => {
    _nutri.carregado = true; _nutri.plano = null; const sem = LembretesNutri.cartao();
    _nutri.plano = { idplano: 1, ativo: 1, refeicoes: [] }; const com = LembretesNutri.cartao();
    return [sem === '', /Lembretes de refeições e água/.test(com)];
  }), [true, true]);
  await page.evaluate(() => { localStorage.setItem('intus-nutri-lemb-7', JSON.stringify({ ativo: false })); _nutri.plano = { idplano: 1, ativo: 1, refeicoes: [{ horario: '07:30', nome: 'Café' }] }; LembretesNutri.abrir(); });
  eq('desligado: a folha mostra só o interruptor principal', await page.evaluate(() => { const t = document.getElementById('lnb-sheet').textContent; return [/Ligar lembretes/.test(t), /Horários dos avisos/.test(t)]; }), [true, false]);
  await page.evaluate(() => LembretesNutri.alternar('ativo'));
  await page.waitForTimeout(400);
  eq('ao ligar, a folha mostra os horários que serão avisados e o botão de teste', await page.evaluate(() => { const t = document.getElementById('lnb-sheet').textContent; return [/Refeições: 07:20/.test(t), /Água: 08:00/.test(t), /aviso de teste/.test(t)]; }), [true, true, true]);
  // iPhone: o plugin responde "não implementado" ao criar canal (canal só existe no Android). O teste e os lembretes têm de funcionar assim mesmo.
  await page.evaluate(() => { Capacitor.Plugins.LocalNotifications.createChannel = () => Promise.reject(Object.assign(new Error('Not implemented on iOS.'), { code: 'UNIMPLEMENTED' })); });
  eq('no iPhone (sem canal de notificação) o teste também agenda', await page.evaluate(async () => { window.__ln.agendados = []; await LembretesNutri.testar(); return window.__ln.agendados.map(n => n.id); }), [92990]);
  eq('o teste agenda um aviso para daqui a 5 segundos', await page.evaluate(async () => { window.__ln.agendados = []; await LembretesNutri.testar(); const t = window.__ln.agendados[0]; return t ? [t.id, t.schedule.at instanceof Date || typeof t.schedule.at === 'object'] : null; }), [92990, true]);
  for (let i = 0; i < 40; i++) { if (await page.evaluate(() => !!window.__ln.ouvintes.localNotificationActionPerformed)) break; await page.waitForTimeout(250); }
  eq('tocar no aviso abre a Nutrição', await page.evaluate(() => { let aberto = null; window.navegar = (d) => { aberto = d; }; window.__ln.ouvintes.localNotificationActionPerformed({ notification: { extra: { tipo: 'nutri-agua' } } }); return aberto; }), 'nutricao');
  eq('sem erro de JavaScript', erros, []);
  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  await b.close(); srv.close(); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
