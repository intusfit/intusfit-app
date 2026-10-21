// Ciclo "em aberto, mas pago na linha de cima" (aluno que paga uns 2 dias antes do fim do ciclo: toda linha nasce assim) tem que avisar quando
// vai vencer e quando venceu. Caso real de 21/10/2026: a matrícula do Erick passou do vencimento e não aparecia em "a vencer", em "vencidas" nem em
// "renovar", e depois da baixa o próximo ciclo repetiria o mesmo buraco todo mês.
//   cd testes && INTUS_DIR=../app/painel node cobranca_paga_anterior.js
const { carregar } = require('./_api.js');
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const RAIZ = path.resolve(process.env.INTUS_DIR || '../app/painel');
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml' };
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }
function ver(rot, cond, extra) { eq(rot, !!cond, true); if (!cond && extra !== undefined) console.log('          ' + JSON.stringify(extra)); }

let id = 1;
const C = (at, nome, ini, venc, pg, valor, extra) => Object.assign({ idmensalidade: id++, idatleta: at, nmathleta: nome, dshistorico: 'Personal 4x', recorrencia: 'mensal', dtinicio: ini, dtvencimento: venc, dtpagamento: pg || null, vlpagar: valor, stpgto: pg ? 'S' : 'N', stmatricula: 'ativa', forma_pgto: 'pix' }, extra || {});
// Quem paga ~2 dias antes do fim do ciclo: a baixa fica na linha de cima e a linha atual nasce em aberto ("pago na linha anterior").
const ERICK = () => [
  C(1, 'Aluno Paga Cedo', '2026-07-21', '2026-08-20', '2026-08-19', 1000),
  C(1, 'Aluno Paga Cedo', '2026-08-20', '2026-09-20', '2026-09-18', 1000),
  C(1, 'Aluno Paga Cedo', '2026-09-20', '2026-10-20', null, 1000),      // em aberto, paga pela baixa de 18/09
];
const api = (hoje) => { const r = carregar({ hoje }); return r.API || r; };
const sit = (hoje, lista, i) => api(hoje).situacaoCobranca(lista[i], lista);

(async () => {
  console.log('\n══ O motor de cobrança (api.js) ══');
  let L = ERICK();
  let s = sit('2026-10-10', L, 2);
  eq('faltando 10 dias: continua pago, e agora tem a PRÓXIMA cobrança (entra em "a vencer")', [s.estado, s.proxima, s.ehDivida, s.ehAcao], ['paga_no_anterior', '2026-10-20', false, false]);
  s = sit('2026-10-20', L, 2);
  eq('no último dia da cobertura: ainda pago, próxima cobrança hoje', [s.estado, s.proxima], ['paga_no_anterior', '2026-10-20']);
  s = sit('2026-10-21', L, 2);
  eq('um dia depois: "renovar" (ação sua), sem virar dívida', [s.estado, s.ehAcao, s.ehDivida, s.dias, s.proxima], ['renovar', true, false, 1, '2026-10-20']);
  s = sit('2026-11-05', L, 2);
  eq('quinze dias depois continua "renovar", com os dias contados do fim da cobertura', [s.estado, s.dias], ['renovar', 16]);

  console.log('\n══ Depois da baixa de hoje (21/10): o próximo ciclo não repete o buraco ══');
  L = ERICK();
  L[2] = Object.assign({}, L[2], { stpgto: 'S', dtpagamento: '2026-10-21' });                       // marcarPago na linha de 20/09
  L.push(C(1, 'Aluno Paga Cedo', '2026-10-20', '2026-11-20', null, 1000, { idmatricula_origem: 1 })); // o ciclo que nasce na baixa
  eq('a linha paga agora é "ciclo concluído" (tem sucessor)', sit('2026-10-21', L, 2).estado, 'renovada');
  s = sit('2026-10-21', L, 3);
  eq('o ciclo novo está pago pela baixa de hoje e a próxima cobrança é 20/11', [s.estado, s.proxima], ['paga_no_anterior', '2026-11-20']);
  s = sit('2026-11-14', L, 3);
  eq('em 14/11 ele entra em "a vencer" (antes ficava invisível)', [s.estado, s.proxima], ['paga_no_anterior', '2026-11-20']);
  s = sit('2026-11-21', L, 3);
  eq('em 21/11, sem a baixa seguinte, vira "renovar" de novo (o aviso se repete todo mês)', [s.estado, s.ehAcao], ['renovar', true]);

  console.log('\n══ O que não pode mudar ══');
  L = ERICK(); L.push(C(1, 'Aluno Paga Cedo', '2026-10-20', '2026-11-20', null, 1000, { idmatricula_origem: 1 }));
  s = sit('2026-10-21', L, 2);
  eq('com o ciclo seguinte já registrado, o antigo não pede renovação nem tem próxima', [s.estado, s.proxima], ['paga_no_anterior', '']);
  L = ERICK(); L[2].stmatricula = 'encerrada';
  eq('matrícula encerrada continua encerrada', sit('2026-10-21', L, 2).estado, 'encerrada');
  L = ERICK(); L[2].stmatricula = 'cancelada';
  eq('matrícula cancelada continua cancelada', sit('2026-10-21', L, 2).estado, 'cancelada');
  L = ERICK(); L[2].stmatricula = 'trancada';
  eq('matrícula trancada continua trancada', sit('2026-10-21', L, 2).estado, 'trancada');
  const P = [C(2, 'Aluno Parcelado', '2026-09-01', '2026-10-01', '2026-09-01', 200, { parcela_num: 1, parcela_total: 3, recorrencia: null }), C(2, 'Aluno Parcelado', '2026-10-01', '2026-11-01', null, 200, { parcela_num: 2, parcela_total: 3, recorrencia: null, idmatricula_origem: 4 })];
  eq('parcela numerada não vira "renovar" (o plano parcelado tem regra própria)', sit('2026-10-05', P, 1).estado === 'renovar', false);
  L = [C(3, 'Aluno Pagou No Dia', '2026-10-01', '2026-11-01', '2026-10-01', 700)];
  eq('quem pagou no próprio ciclo segue "paga" como antes', sit('2026-10-21', L, 0).estado, 'paga');
  L = [C(4, 'Aluno Devedor', '2026-08-01', '2026-09-01', null, 500)];
  eq('quem de fato deve segue como dívida (vencida)', [sit('2026-10-21', L, 0).estado, sit('2026-10-21', L, 0).ehDivida], ['inativa', true]);

  console.log('\n══ O painel inicial de verdade (Chromium, hoje = 21/10) ══');
  id = 1;
  const MENS = [
    ...ERICK(),
    C(2, 'Aluno Vence Logo', '2026-08-28', '2026-09-28', '2026-09-26', 800), C(2, 'Aluno Vence Logo', '2026-09-28', '2026-10-27', null, 800),
    C(3, 'Aluno Em Dia', '2026-10-01', '2026-11-01', '2026-10-01', 700),
  ];
  MENS[3].nmathleta = MENS[3].nmathleta; // (mesmo aluno, linhas 4 e 5)
  const srv = http.createServer((req, res) => { let p = decodeURIComponent(req.url.split('?')[0]); if (p === '/') p = '/index.html'; const f = path.join(RAIZ, p); if (!f.startsWith(RAIZ) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.writeHead(404); return res.end('n'); } res.writeHead(200, { 'Content-Type': MIME[path.extname(f)] || 'application/octet-stream' }); fs.createReadStream(f).pipe(res); });
  await new Promise(r => srv.listen(8106, '127.0.0.1', r));
  const b = await chromium.launch(); const p = await b.newPage({ viewport: { width: 1400, height: 1000 } });
  const erros = []; p.on('pageerror', e => erros.push(e.message));
  await p.route(u => u.href.includes('.php'), r => { const u = r.request().url();
    if (u.includes('mensalidades.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(MENS) });
    if (u.includes('atletas.php')) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify([...new Set(MENS.map(m => m.idatleta))].map(i => ({ idatleta: i, nome: MENS.find(m => m.idatleta === i).nmathleta, email: 'a' + i + '@x.com', origem: 'professor', professores_responsaveis: [1], stbloqueio: 'N' }))) });
    return r.fulfill({ status: 200, contentType: 'application/json', body: '[]' }); });
  await p.addInitScript(({ MENS, HOJE }) => { const _R = Date; window.Date = function (...a) { return a.length ? new _R(...a) : new _R(HOJE + 'T12:00:00'); }; window.Date.prototype = _R.prototype; window.Date.now = () => new _R(HOJE + 'T12:00:00').getTime(); window.Date.UTC = _R.UTC; window.Date.parse = _R.parse;
    localStorage.setItem('mx-user', JSON.stringify({ idusuario: 1, nome: 'Luiz', admin: true, permissoes: {} })); localStorage.setItem('mx-token', 'x'.repeat(64)); localStorage.setItem('intus-mensalidades', JSON.stringify(MENS)); }, { MENS, HOJE: '2026-10-21' });
  await p.goto('http://127.0.0.1:8106/index.html', { waitUntil: 'networkidle' });
  await p.waitForTimeout(2500);
  const t = await p.evaluate(() => document.body.innerText.replace(/\s+/g, ' '));
  const secao = (ini, fim) => { const i = t.search(ini); if (i < 0) return ''; const r = t.slice(i + 10); const j = r.search(fim); return t.slice(i, j >= 0 ? i + 10 + j : i + 600); };
  const vencidas = secao(/MATRÍCULAS VENCIDAS/, /MATRÍCULAS A VENCER/), aVencer = secao(/MATRÍCULAS A VENCER/, /TREINOS VENCIDOS/);
  ver('"Matrículas vencidas" mostra quem pagou cedo e passou do fim da cobertura (o caso do Erick)', /Aluno Paga Cedo/.test(vencidas) && /renovar/.test(vencidas), vencidas);
  ver('...e a linha diz que é renovação, não dívida', /renovar · 1d/.test(vencidas), vencidas);
  ver('"Matrículas a vencer" mostra quem vence em 6 dias e está pago na linha de cima', /Aluno Vence Logo/.test(aVencer), aVencer);
  ver('quem está em dia não aparece em nenhuma das duas', !/Aluno Em Dia/.test(vencidas + aVencer), vencidas + aVencer);
  ver('"Em atraso" do resumo financeiro NÃO soma renovação (não é dívida)', /EM ATRASO R\$ 0,00/.test(t), t.slice(t.search(/EM ATRASO/), t.search(/EM ATRASO/) + 40));
  eq('sem erro de script no painel', erros, []);
  await b.close(); srv.close();

  console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok'));
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
