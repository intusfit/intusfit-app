// Aviso no sino do aluno: "seu plano vence em N dias" e "seu plano venceu" (aluno.html + catalogo.php).
//   cd testes && INTUS_DIR=../app/painel node aviso_mensalidade_aluno.js
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.jpg': 'image/jpeg', '.svg': 'image/svg+xml', '.mp3': 'audio/mpeg' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }
const dia = (n) => { const d = new Date(); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10); };
const M = (extra) => Object.assign({ idmensalidade: 1, idatleta: 7, dshistorico: 'Personal 4x', tipo_plano: 'mensal', dtinicio: dia(-27), dtvencimento: dia(3), stpgto: 'S', stmatricula: 'ativa', vlpagar: 1000, recorrencia: 'mensal' }, extra || {});

(async () => {
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/aluno.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8107, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(String(e.message)));
  await page.route(/\/app\/api\//, r => r.abort('failed'));
  await page.addInitScript(() => { localStorage.setItem('mx-token', 't'); localStorage.setItem('mx-user', JSON.stringify({ tipo: 'aluno', idatleta: 7, nome: 'Aluna Teste' })); });
  await page.goto('http://127.0.0.1:8107/aluno.html', { waitUntil: 'domcontentloaded' });
  for (let i = 0; i < 80; i++) { if (await page.evaluate(() => typeof _mensalidadeNotificar === 'function' && typeof API !== 'undefined').catch(() => false)) break; await page.waitForTimeout(250); }
  await page.waitForTimeout(1200);

  const rodar = (linhas, sincronizado = true) => page.evaluate(([l, s]) => {
    localStorage.removeItem('intus-mens-avisada-7');
    localStorage.setItem('intus-mensalidades', JSON.stringify(l));
    _syncDone = s; _matSyncOk = s;
    window.__avisos = []; const orig = window.__origReg || _notifRegistrar; window.__origReg = orig;
    window._notifRegistrar = function (p) { window.__avisos.push(p); };
    _mensalidadeNotificar();
    return window.__avisos.slice();
  }, [linhas, sincronizado]);

  console.log('\n══ Vai vencer ══');
  let a = await rodar([M({ dtvencimento: dia(3) })]);
  eq('faltando 3 dias: um aviso "vence"', [a.length, a[0] && a[0].tipo, a[0] && a[0].chave.split(':')[0]], [1, 'mensalidade', 'vence']);
  ver('o texto diz quantos dias faltam e não tem travessão', a[0] && /vence em 3 dias/.test(a[0].texto) && !/[—–]/.test(a[0].texto), a[0]);
  a = await rodar([M({ dtvencimento: dia(0) })]);
  ver('no dia: "vence hoje"', a[0] && /vence hoje/.test(a[0].texto), a[0]);
  a = await rodar([M({ dtvencimento: dia(1) })]);
  ver('faltando 1 dia: singular', a[0] && /vence em 1 dia /.test(a[0].texto), a[0]);
  a = await rodar([M({ dtvencimento: dia(20) })]);
  eq('faltando 20 dias: nenhum aviso', a.length, 0);

  console.log('\n══ Venceu ══');
  a = await rodar([M({ dtvencimento: dia(-1) })]);
  eq('venceu há 1 dia (na carência): um aviso "venceu"', [a.length, a[0] && a[0].chave.split(':')[0]], [1, 'venceu']);
  ver('o texto diz a data e quantos dias restam, sem prometer bloqueio sem prazo', a[0] && /venceu em \d\d\/\d\d/.test(a[0].texto) && /até \d dias/.test(a[0].texto), a[0]);
  a = await rodar([M({ dtvencimento: dia(-20) })]);
  eq('venceu há 20 dias (fora da carência, o app já bloqueia por outra tela): nenhum aviso do sino', a.length, 0);
  console.log('\n══ Quem não recebe ══');
  a = await rodar([M({ dtvencimento: dia(2), dshistorico: 'Teste Grátis (3 dias)', tipo_plano: null })]);
  eq('teste grátis tem o próprio aviso: nenhum daqui', a.length, 0);
  a = await rodar([M({ dtvencimento: dia(2) })], false);
  eq('antes de o servidor confirmar as matrículas: nenhum aviso', a.length, 0);
  a = await rodar([]);
  eq('sem matrícula: nenhum aviso', a.length, 0);

  console.log('\n══ Um aviso por fase e fim de cobertura ══');
  await rodar([M({ dtvencimento: dia(3) })]);
  const repetido = await page.evaluate(() => { window.__avisos = []; _mensalidadeNotificar(); return window.__avisos.length; });
  eq('abrir o app de novo no mesmo dia não repete o aviso', repetido, 0);

  console.log('\n══ A lista do sino e o servidor ══');
  const l = await page.evaluate(() => _notifLinhaHtml({ idnotificacao: 3, tipo: 'mensalidade', alvo_tipo: 'mensalidade', alvo_id: 5, ator_id: 1, ator_nome: 'Intus', texto: 'Seu plano vence em 3 dias (24/10). Combine a renovação com a equipe.', quando: '2026-10-21 09:00:00', lido: 0 }, false));
  ver('a linha mostra a frase inteira e o ícone de pagamento', /Seu plano vence em 3 dias/.test(l) && /💳/.test(l), l.slice(0, 260));
  const cat = fs.readFileSync(path.join(RAIZ, '../api/catalogo.php'), 'utf8');
  ver('o servidor aceita o tipo "mensalidade" (e segue recusando outros)', /\['medalha', 'distintivo', 'parceiro', 'mensalidade'\], true\)/.test(cat));
  eq('sem erro de script', erros, []);
  await b.close(); srv.close();
  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok')); process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
