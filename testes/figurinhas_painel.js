// Figurinhas do painel (notificacoes.html > Figurinhas) no Chromium, com a API de mentira.
// Garante que uma falha de leitura ou uma lista danificada NUNCA aparece como se fossem as figurinhas reais
// (o painel mostrava as 6 de emoji de fábrica e um "Salvar" apagava a lista do administrador).
//   cd testes && INTUS_DIR=../app/painel node figurinhas_painel.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

const img = n => ({ tipo: 'img', src: '/app/img/uploads/fig_' + n + '.jpg', legenda: '' });
const lista = n => Array.from({ length: n }, (_, i) => img(i + 1));

async function cenario(b, nome, cfg) {
  // cfg: { get: (corpo|status), anterior: obj|null, dialogoAceita: bool }
  const ctx = await b.newContext({ viewport: { width: 1300, height: 1000 }, serviceWorkers: 'block' });
  const pg = await ctx.newPage();
  const S = { puts: [], dialogos: [] };
  pg.on('dialog', d => { S.dialogos.push(d.message()); cfg.dialogoAceita ? d.accept() : d.dismiss(); });
  await pg.route(u => u.href.includes('.php'), async (r) => {
    const req = r.request(), u = req.url(), met = req.method();
    const json = (o, st = 200) => r.fulfill({ status: st, contentType: 'application/json', body: typeof o === 'string' ? o : JSON.stringify(o) });
    if (u.includes('action=figurinhas_config')) {
      if (met === 'PUT') { S.puts.push(JSON.parse(req.postData() || '{}')); return json({ ok: true }); }
      if (u.includes('anterior=1')) return json(cfg.anterior || { itens: [] });
      if (typeof cfg.get === 'number') return r.fulfill({ status: cfg.get, contentType: 'application/json', body: '{"error":"x"}' });
      if (typeof cfg.get === 'string') return r.fulfill({ status: 200, contentType: 'text/html', body: cfg.get });
      return json(cfg.get);
    }
    return json([]);
  });
  await pg.addInitScript(() => { localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); });
  await pg.goto('http://127.0.0.1:8110/notificacoes.html', { waitUntil: 'networkidle' });
  await pg.evaluate(() => { const t = document.querySelector('.ntf-tab[data-tab="figurinhas"]'); if (t) t.click(); });
  await pg.waitForTimeout(700);
  return { pg, S };
}

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8110, '127.0.0.1', r));
  const b = await chromium.launch();
  const cards = pg => pg.evaluate(() => document.querySelectorAll('#figurinhas-container img').length);
  const texto = pg => pg.evaluate(() => document.getElementById('figurinhas-container').innerText);

  console.log('\n══ Lista salva e legível ══');
  let { pg, S } = await cenario(b, 'ok', { get: { itens: lista(8) }, anterior: { itens: lista(31) }, dialogoAceita: false });
  eq('mostra as 8 figurinhas salvas', await cards(pg), 8);
  ver('sem aviso de lista danificada', !/padrão de fábrica/.test(await texto(pg)));
  ver('oferece voltar à lista anterior com a contagem', /Voltar à lista anterior \(31\)/.test(await texto(pg)));
  // apagar 5 de 8 (menos da metade) pede confirmação; recusar não grava
  for (let i = 0; i < 5; i++) await pg.evaluate(() => removeFigurinha(0));
  await pg.evaluate(() => salvarFigurinhas()); await pg.waitForTimeout(300);
  eq('reduzir a lista a menos da metade pergunta e, recusado, não grava nada', [S.dialogos.length, S.puts.length], [1, 0]);
  // restaurar a anterior traz 31 para a tela mas só grava ao salvar
  S.dialogos.length = 0;
  await pg.close();

  ({ pg, S } = await cenario(b, 'restaurar', { get: { itens: lista(2) }, anterior: { itens: lista(31) }, dialogoAceita: true }));
  await pg.evaluate(() => restaurarFigurinhasAnterior()); await pg.waitForTimeout(500);
  eq('"Voltar à lista anterior" carrega as 31 na tela', await cards(pg), 31);
  eq('e ainda não grava nada no servidor', S.puts.length, 0);
  await pg.evaluate(() => salvarFigurinhas()); await pg.waitForTimeout(600);
  eq('só ao salvar manda as 31', [S.puts.length, S.puts[0] && S.puts[0].itens.length], [1, 31]);
  await pg.close();

  console.log('\n══ Falha de leitura (servidor 500) ══');
  ({ pg, S } = await cenario(b, '500', { get: 500, dialogoAceita: true }));
  eq('não mostra figurinha nenhuma (nem as de fábrica)', await cards(pg) + (await pg.evaluate(() => /MODO MONSTRO/.test(document.getElementById('figurinhas-container').innerText) ? 1 : 0)), 0);
  const t500 = await texto(pg);
  ver('diz que não conseguiu carregar e que nada foi salvo', /Não consegui carregar/.test(t500) && /nada foi salvo/i.test(t500), t500);
  ver('não existe botão Salvar figurinhas', !/Salvar figurinhas/.test(t500));
  ver('tem "Tentar de novo"', /Tentar de novo/.test(t500));
  eq('nada foi gravado', S.puts.length, 0);
  await pg.close();

  console.log('\n══ Resposta ilegível (200 com HTML) ══');
  ({ pg, S } = await cenario(b, 'html', { get: '<html>Cloudflare</html>', dialogoAceita: true }));
  ver('trata como falha de leitura', /Não consegui carregar/.test(await texto(pg)));
  await pg.close();

  console.log('\n══ Lista danificada no servidor ([] ou sem itens) ══');
  for (const corpo of ['[]', '{}', '{"ok":true}']) {
    ({ pg, S } = await cenario(b, 'dano', { get: corpo, dialogoAceita: false }));
    ver('(' + corpo + ') avisa em vermelho que é só o padrão de fábrica', /padrão de fábrica/.test(await texto(pg)) && /Não clique em Salvar/.test(await texto(pg)));
    await pg.evaluate(() => salvarFigurinhas()); await pg.waitForTimeout(300);
    eq('(' + corpo + ') salvar pergunta e, recusado, não grava', [S.dialogos.length, S.puts.length], [1, 0]);
    await pg.close();
  }
  ({ pg, S } = await cenario(b, 'dano-aceita', { get: '[]', dialogoAceita: true }));
  await pg.evaluate(() => salvarFigurinhas()); await pg.waitForTimeout(500);
  eq('aceitando de propósito, grava as 6 e o aviso some', [S.puts.length, /padrão de fábrica/.test(await texto(pg))], [1, false]);
  await pg.close();

  console.log('\n══ Lista vazia salva de propósito ══');
  ({ pg, S } = await cenario(b, 'vazia', { get: { itens: [] }, dialogoAceita: true }));
  ver('é escolha válida: mostra "Nenhuma figurinha ainda", sem aviso', /Nenhuma figurinha ainda/.test(await texto(pg)) && !/padrão de fábrica/.test(await texto(pg)));
  await pg.close();

  console.log('\n══ Colar vários links de uma vez (restaurar uma lista) ══');
  ({ pg, S } = await cenario(b, 'colar', { get: { itens: [img(1), img(2)] }, dialogoAceita: true }));
  const colar = txt => pg.evaluate(t => { document.getElementById('fig-url').value = t; _figAddUrl(); return _figurinhasData.map(f => f.src); }, txt);
  let srcs = await colar('/app/img/uploads/fig_3.jpg\n/app/img/uploads/fig_4.jpg\r\nhttps://exemplo.com/a.gif, /app/img/uploads/fig_1.jpg');
  eq('cola 4 links de uma vez, ignora o que já estava na lista e completa o caminho do site', srcs.length, 5);
  ver('caminho /app/img/... vira link completo', srcs.some(x => /^http:\/\/127\.0\.0\.1:\d+\/app\/img\/uploads\/fig_3\.jpg$/.test(x)), srcs);
  eq('colar o mesmo conjunto de novo não duplica', (await colar('/app/img/uploads/fig_3.jpg\n/app/img/uploads/fig_4.jpg')).length, 5);
  eq('texto que não é link não adiciona nada', (await colar('qualquer coisa')).length, 5);
  eq('nada é gravado até clicar em Salvar', S.puts.length, 0);
  await pg.evaluate(() => salvarFigurinhas()); await pg.waitForTimeout(400);
  eq('ao salvar, o servidor recebe a lista com os 5', S.puts.length === 1 && S.puts[0].itens.length, 5);
  await pg.close();

  await b.close(); srv.close();
  console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
