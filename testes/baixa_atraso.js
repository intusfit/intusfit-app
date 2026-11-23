// Atraso na janela de baixa: quem paga uns dias antes do fim do ciclo (linha "paga na linha anterior") nao pode aparecer com atraso contado do INICIO do ciclo.
// Caso real (23/11/2026): plano do Erick vencia em 20/11, a janela dizia "vencida ha 34 dia(s)" (conta de 20/10).
//   cd testes && INTUS_DIR=../app/painel node baixa_atraso.js
const { carregar } = require('./_api.js');
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

const HOJE = '2026-11-23';
let id = 1;
const C = (ini, venc, pg, extra) => Object.assign({ idmensalidade: id++, idatleta: 1, nmathleta: 'Erick Teste', dshistorico: 'Personal 4x', recorrencia: 'mensal', dtinicio: ini, dtvencimento: venc, dtpagamento: pg || null, vlpagar: 1000, vldesconto: 0, vljuro: 0, stpgto: pg ? 'S' : 'N', stmatricula: 'ativa', forma_pgto: 'pix', professor: 'Luiz' }, extra || {});
// o Erick paga uns 2 dias antes do fim: a baixa fica na linha do ciclo anterior e paga o ciclo seguinte
const ERICK = [C('2026-09-20', '2026-10-20', '2026-10-18'), C('2026-10-20', '2026-11-20', null)];
// quem esta mesmo atrasado: ciclo em aberto sem nenhum pagamento anterior
const ATRASADO = [C('2026-10-20', '2026-11-20', null, { idatleta: 2, nmathleta: 'Aluno Atrasado' })];

(async () => {
  console.log('\n══ A regra (API.diasAtrasoBaixa) ══');
  const API = (carregar({ hoje: HOJE }).API || carregar({ hoje: HOJE }));
  const lista = ERICK.concat(ATRASADO);
  eq('situação do Erick: aguardando renovação, sem dívida', [API.situacaoCobranca(ERICK[1], lista).estado, API.situacaoCobranca(ERICK[1], lista).ehDivida], ['renovar', false]);
  eq('o atraso da baixa do Erick conta do fim da cobertura, não do início do ciclo: 20/11 é feriado (Consciência Negra), a cobrança vale em 23/11 e hoje o atraso é 0 (antes dava 34)', API.diasAtrasoBaixa(ERICK[1], lista), 0);
  const semana = (carregar({ hoje: '2026-11-30' }).API || carregar({ hoje: '2026-11-30' }));
  eq('uma semana depois (30/11) ele está 7 dias atrasado na renovação, contados de 23/11', semana.diasAtrasoBaixa(ERICK[1], lista), 7);
  eq('quem está atrasado de verdade segue contando do dia da cobrança (20/10 -> 23/11 = 34)', API.diasAtrasoBaixa(ATRASADO[0], lista), 34);
  const dentro = (carregar({ hoje: '2026-11-10' }).API || carregar({ hoje: '2026-11-10' }));
  eq('dentro da cobertura (10/11), paga na linha anterior: sem atraso', dentro.diasAtrasoBaixa(ERICK[1], lista), null);
  eq('linha já paga não tem atraso', API.diasAtrasoBaixa(ERICK[0], lista), null);

  console.log('\n══ A janela de pagamento e os cartões (mensalidades.html) ══');
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/mensalidades.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8110, '127.0.0.1', r));
  const b = await chromium.launch(); const page = await b.newPage({ viewport: { width: 1400, height: 1000 }, serviceWorkers: 'block' });
  const erros = []; page.on('pageerror', e => erros.push(e.message));
  await page.route(u => u.href.includes('.php'), async (r) => {
    const u = r.request().url();
    if (u.includes('mensalidades.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(lista) });
    if (u.includes('atletas.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([{ idatleta: 1, nome: 'Erick Teste', origem: 'professor', professores_responsaveis: [1], stbloqueio: 'N' }, { idatleta: 2, nome: 'Aluno Atrasado', origem: 'professor', professores_responsaveis: [1], stbloqueio: 'N' }]) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '[]' });
  });
  await page.addInitScript(({ lista, HOJE }) => { const _R = Date; window.Date = function (...a) { return a.length ? new _R(...a) : new _R(HOJE + 'T12:00:00'); }; window.Date.prototype = _R.prototype; window.Date.now = () => new _R(HOJE + 'T12:00:00').getTime(); window.Date.UTC = _R.UTC; window.Date.parse = _R.parse;
    localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); localStorage.setItem('intus-mensalidades', JSON.stringify(lista)); }, { lista, HOJE });
  await page.goto('http://127.0.0.1:8110/mensalidades.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1800);
  const modal = async (idm) => { await page.evaluate((i) => { closeModal && closeModal(); marcarPago(i); }, idm); await page.waitForSelector('#pg-valor', { timeout: 4000 }); return page.evaluate(() => { const j = document.getElementById('pg-juros'); return j ? j.closest('.form-group').querySelector('label').textContent : null; }); };
  const rotErick = await modal(ERICK[1].idmensalidade);
  eq('Erick (hoje, 20/11 foi feriado): a janela não mostra multa nem "34 dias"', rotErick, null);
  const rotAtr = await modal(ATRASADO[0].idmensalidade);
  eq('aluno realmente atrasado: segue dizendo 34 dias', /há 34 dia/.test(rotAtr || ''), true);
  await page.evaluate(() => closeModal && closeModal());
  const vencidasErick = await page.evaluate((i) => _diasAtraso(mensalidades.find(m => Number(m.idmensalidade) === i)), ERICK[1].idmensalidade);
  eq('o cartão "Vencidas" não conta a linha do Erick (aguardando renovação não é dívida)', vencidasErick, null);
  eq('sem erro de JavaScript', erros, []);
  await b.close(); srv.close();
  console.log('\n' + ok + ' ok' + (ruim ? ', ' + ruim + ' FALHA(S)' : ''));
  process.exit(ruim ? 1 : 0);
})();
