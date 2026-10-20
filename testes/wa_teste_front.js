// Tela do laboratório do WhatsApp (atendimento/teste.html) num Chromium de verdade, com a API de mentira:
// o que aparece, o que cada botão manda, o escape de HTML e o aviso quando a Meta recusa.
//   cd testes && node wa_teste_front.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(__dirname, '..');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };

let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const msgs = (modo) => ['t1', 't2', 't3'].map((id, i) => ({ id, nome: ['Boas-vindas', 'Ainda não treinou', 'Parabéns'][i], tipo: 'agendada', dia: i, hora: i ? 10 : null, condicao: 'todos', template: 'intus_' + id, aprovada: true, texto: i === 0 ? 'Oi Fulano <b>negrito</b>, aqui é a Cora.' : 'Texto ' + id, modo: modo[id] || 'manual' }));

(async () => {
  const srv = http.createServer((req, res) => {
    let p = decodeURIComponent(req.url.split('?')[0]);
    const f = path.join(RAIZ, p);
    if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('nao'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(8099, '127.0.0.1', r));
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 420, height: 900 } });
  const page = await ctx.newPage();
  const erros = [];
  page.on('pageerror', e => erros.push(String(e.message)));
  page.on('dialog', d => d.accept());

  const S = { contato: null, modo: {}, mestre: false, chamadas: [], enviarOk: true, mensagens: [], agenda: [], sessao: true };
  const estado = () => ({
    ok: true, whatsapp_configurado: true, webhook_configurado: false, agora: '2026-10-21 12:00:00', telefone_teste: '5545999574107', nome_teste: 'Fulano Teste',
    automacao: { mestre: S.mestre, passos: Object.fromEntries(Object.entries(S.modo).filter(([, v]) => v === 'auto').map(([k]) => ['teste-gratis/' + k, 'auto'])) },
    funis: [{ id: 'teste-gratis', nome: 'Teste grátis de 3 dias', status: 'aprovado', mensagens: msgs(S.modo) }, { id: 'rascunho-x', nome: 'Outro funil', status: 'rascunho', mensagens: [] }],
    contato: S.contato, mensagens: S.mensagens, agenda: S.agenda, janela_aberta: false,
  });
  await page.route('**/app/api/wa_teste.php**', async (route) => {
    const req = route.request(); const acao = (req.url().match(/action=(\w+)/) || [])[1];
    const corpo = req.postData() ? JSON.parse(req.postData()) : null;
    if (acao !== 'estado') S.chamadas.push({ acao, corpo });
    const json = (o, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(o) });
    if (!S.sessao) return json({ ok: false, erro: 'Sessão inválida' }, 401);
    switch (acao) {
      case 'estado': return json(estado());
      case 'contato_criar': S.contato = { idcontato: 1, nome: 'Fulano Teste', telefone: '5545999574107', optin_em: '2026-10-21 12:00:00', saiu_em: null }; return json({ ok: true, contato: S.contato });
      case 'conexao': return json({ ok: true, conexao: { ok: true, numero: '+55 45 99115-6006', nome: 'Intus Fit', qualidade: 'GREEN' } });
      case 'config_salvar': if ('mestre' in corpo) S.mestre = corpo.mestre; if (corpo.passos) { S.modo = {}; Object.keys(corpo.passos).forEach(k => { S.modo[k.split('/')[1]] = 'auto'; }); } return json({ ok: true });
      case 'enviar_passo':
        if (!S.enviarOk) return json({ ok: false, erro: 'Meta 400: (#132001) Template name does not exist', resultado: {} }, 422);
        S.mensagens.push({ direcao: 'S', origem: 'sequencia', tipo: 'template', texto: 'Oi Fulano', template: 'intus_t1', status: 'enviada', rotulo: 'Aceita pela Meta', criado_em: '2026-10-21 12:00:00', erro: null });
        return json({ ok: true, resultado: { ok: true } });
      case 'agendar': S.agenda = [{ passo: 't1', enviar_em: '2026-10-21 11:00:00', condicao: 'todos', status: 'pendente', motivo: null }, { passo: 't2', enviar_em: '2026-10-21 11:05:00', condicao: 'todos', status: 'pendente', motivo: null }]; return json({ ok: true, criados: 2, ja_existiam: 0, itens: [] });
      case 'processar': return json({ ok: true, resultado: { mestre: S.mestre, processadas: S.mestre ? 1 : 0, enviadas: S.mestre ? 1 : 0, manuais_vencidas: 1, itens: S.mestre ? [{ passo: 't1', resultado: 'enviada', motivo: null }] : [] } });
      case 'reiniciar': return json({ ok: true, agenda_apagada: 2 });
      default: return json({ ok: true });
    }
  });

  console.log('\n══ Sem login ══');
  await page.goto('http://127.0.0.1:8099/atendimento/teste.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(400);
  ver('sem sessão mostra o aviso e esconde o laboratório', await page.evaluate(() => !document.getElementById('sem-sessao').hidden && document.getElementById('conteudo').hidden));

  console.log('\n══ Com login ══');
  await page.evaluate(() => localStorage.setItem('mx-token', 'teste'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#lista-passos .passo', { timeout: 5000 });
  eq('3 mensagens do funil na tela', await page.locator('.passo').count(), 3);
  eq('todas nascem em Manual', await page.$$eval('select[data-modo]', s => s.map(x => x.value)), ['manual', 'manual', 'manual']);
  ver('automação geral aparece desligada, com aviso', await page.evaluate(() => !document.getElementById('c-mestre').checked && !document.getElementById('av-mestre').hidden));
  ver('chip do WhatsApp mostra configurado e o do webhook avisa', await page.evaluate(() => /WhatsApp configurado/.test(document.getElementById('chip-wa').textContent) && /incompleto/.test(document.getElementById('chip-hook').textContent)));
  ver('sem contato, os botões de enviar ficam travados', await page.evaluate(() => document.getElementById('b-hello').disabled && document.querySelector('[data-enviar]').disabled));
  ver('HTML dentro da mensagem aparece como texto, sem virar tag', await page.evaluate(() => !document.querySelector('.passo b') && /<b>negrito<\/b>/.test(document.querySelector('.passo .txt').textContent)));

  console.log('\n══ Criar o contato e enviar ══');
  await page.click('#b-criar'); await page.waitForTimeout(500);
  eq('mandou contato_criar', S.chamadas.at(-1).acao, 'contato_criar');
  ver('contato pronto e botões liberados', await page.evaluate(() => /consentimento registrado/.test(document.getElementById('chip-contato').textContent) && !document.getElementById('b-hello').disabled));
  await page.click('[data-enviar="t1"][data-via="auto"]'); await page.waitForTimeout(600);
  eq('Enviar agora manda funil, mensagem e via', S.chamadas.at(-1), { acao: 'enviar_passo', corpo: { funil: 'teste-gratis', msg: 't1', via: 'auto' } });
  ver('a conversa mostra "Aceita pela Meta"', await page.evaluate(() => /Aceita pela Meta/.test(document.getElementById('conversa').textContent)));
  ver('o botão de texto livre fica travado com a janela fechada', await page.evaluate(() => document.querySelector('[data-via="texto"]').disabled));
  S.enviarOk = false;
  await page.click('[data-enviar="t1"][data-via="auto"]'); await page.waitForTimeout(600);
  ver('quando a Meta recusa, o erro aparece na tela', await page.evaluate(() => /132001/.test(document.getElementById('r-agenda').textContent)));

  console.log('\n══ Automático x manual ══');
  await page.selectOption('select[data-modo="t2"]', 'auto'); await page.waitForTimeout(500);
  eq('trocar um passo para Automático grava só aquele passo', S.chamadas.at(-1), { acao: 'config_salvar', corpo: { passos: { 'teste-gratis/t2': 'auto' } } });
  eq('o passo continua automático depois de recarregar o estado', await page.$eval('select[data-modo="t2"]', s => s.value), 'auto');
  await page.selectOption('select[data-modo="t2"]', 'manual'); await page.waitForTimeout(400);
  eq('voltar a Manual limpa o passo', S.chamadas.at(-1).corpo, { passos: {} });
  await page.check('#c-mestre'); await page.waitForTimeout(500);
  eq('ligar a automação geral manda mestre true', S.chamadas.at(-1), { acao: 'config_salvar', corpo: { mestre: true } });
  ver('o aviso de automação desligada some', await page.evaluate(() => document.getElementById('av-mestre').hidden));

  console.log('\n══ Agenda e fila ══');
  await page.fill('#n-intervalo', '5'); await page.click('#b-agendar'); await page.waitForTimeout(500);
  eq('agendar manda o funil e o intervalo', S.chamadas.at(-1), { acao: 'agendar', corpo: { funil: 'teste-gratis', intervalo_min: 5 } });
  eq('tabela mostra os 2 passos agendados', await page.locator('#t-agenda tr').count(), 3);
  ver('passo vencido e manual diz que espera você', await page.evaluate(() => /aguardando você enviar/.test(document.getElementById('t-agenda').textContent)));
  await page.click('#b-processar'); await page.waitForTimeout(500);
  ver('processar mostra o que foi enviado', await page.evaluate(() => /1 enviada/.test(document.getElementById('r-agenda').textContent)));

  console.log('\n══ Reiniciar exige confirmação ══');
  await page.evaluate(() => { document.querySelector('details').open = true; });
  const antes = S.chamadas.length;
  await page.click('#b-reiniciar'); await page.waitForTimeout(300);
  eq('sem digitar REINICIAR nada é enviado', S.chamadas.length, antes);
  await page.fill('#t-reiniciar', 'REINICIAR'); await page.click('#b-reiniciar'); await page.waitForTimeout(400);
  eq('digitando, manda a confirmação', S.chamadas.at(-1), { acao: 'reiniciar', corpo: { confirmo: 'REINICIAR' } });

  console.log('\n══ Sessão que cai ══');
  S.sessao = false;
  await page.click('#b-conexao'); await page.waitForTimeout(500);
  ver('401 durante o uso mostra o aviso de entrar no painel', await page.evaluate(() => !document.getElementById('sem-sessao').hidden || /Sessão inválida/.test(document.getElementById('toast').textContent)));

  console.log('\n══ Tema e texto ══');
  const html = fs.readFileSync(path.join(RAIZ, 'atendimento/teste.html'), 'utf8');
  eq('sem travessão na tela', /[—–]/.test(html), false);
  ver('tem tema claro e escuro (variáveis)', /data-tema="light"/.test(html) && /--bg:/.test(html));
  eq('sem erro de script na página', erros, []);
  if (process.env.FOTO) { S.sessao = true; await page.reload({ waitUntil: 'domcontentloaded' }); await page.waitForSelector('#lista-passos .passo'); await page.screenshot({ path: process.env.FOTO, fullPage: true }); }
  await browser.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok'));
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
