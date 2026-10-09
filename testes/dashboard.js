// Dica de grafico (Tip, _mock.js) e curva de crescimento do dashboard (calcCrescimento, index.html).
// Extrai o codigo REAL dos arquivos e roda num sandbox: se alguem mexer na conta, o teste acusa.
const fs = require('fs'), path = require('path'), vm = require('vm');
const DIR = process.env.INTUS_DIR || path.join(__dirname, '..', 'app', 'painel');
const lerArq = f => fs.readFileSync(path.join(DIR, f), 'utf8').replace(/\r\n/g, '\n');

let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
function tem(rot, texto, trecho) {
  if (String(texto).includes(trecho)) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          faltou  ' + JSON.stringify(trecho) + '\n          em      ' + JSON.stringify(String(texto).slice(0, 300))); }
}
function naoTem(rot, texto, trecho) {
  if (!String(texto).includes(trecho)) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          nao devia ter ' + JSON.stringify(trecho)); }
}

// ── Tip ──────────────────────────────────────────────────────────────────────
{
  const src = lerArq('_mock.js');
  const i = src.indexOf('window.Tip = (function () {');
  const j = src.indexOf('})();', i) + 5;
  if (i < 0 || j < 5) { console.log('FALHA bloco Tip nao encontrado'); process.exit(1); }
  const noop = () => {};
  const sandbox = { window: { addEventListener: noop, innerWidth: 1200, innerHeight: 800 }, document: { addEventListener: noop, getElementById: () => null, createElement: () => ({ style: {}, classList: { add: noop, remove: noop } }), head: { appendChild: noop }, body: { appendChild: noop } } };
  vm.createContext(sandbox);
  vm.runInContext(src.slice(i, j), sandbox);
  const Tip = sandbox.window.Tip;
  console.log('\n── Tip.historico');
  const rot = ['jan/26', 'fev/26', 'mar/26', 'abr/26', 'mai/26', 'jun/26', 'jul/26', 'ago/26'];
  const val = [10, 12, 12, 15, 11, 20, 18, 25];
  let h = Tip.historico({ titulo: 'Vendas', rotulos: rot, valores: val, i: 7, sufixo: 'pessoas' });
  tem('titulo com o mes sob o mouse', h, 'Vendas · ago/26');
  tem('valor do mes', h, '25');
  tem('variacao contra o mes anterior', h, '▲ +7 vs mês anterior');
  tem('mostra os 5 meses anteriores (jul a mar)', h, 'jul/26');
  tem('inclui o quinto anterior', h, 'mar/26');
  naoTem('nao inclui o sexto anterior', h, 'fev/26');
  tem('avisa dos meses que ficaram de fora', h, 'e 2 mês(es) antes destes');
  h = Tip.historico({ titulo: 'Vendas', rotulos: rot, valores: val, i: 4, sufixo: 'x' });
  tem('queda e marcada', h, '▼ -4 vs mês anterior');
  h = Tip.historico({ titulo: 'Vendas', rotulos: rot, valores: val, i: 2, sufixo: 'x' });
  tem('igual ao anterior', h, 'igual ao mês anterior');
  h = Tip.historico({ titulo: 'Vendas', rotulos: rot, valores: val, i: 0, sufixo: 'x' });
  naoTem('primeiro mes nao tem variacao', h, 'vs mês anterior');
  naoTem('primeiro mes nao tem lista de anteriores', h, 'tip-h');
  h = Tip.historico({ titulo: 'T', rotulos: ['<b>x</b>', 'y'], valores: [1, 2], i: 1 });
  naoTem('rotulo e escapado', h, '<b>x</b>');
  h = Tip.historico({ titulo: 'R', rotulos: rot, valores: val, i: 7, fmt: v => 'R$ ' + v, anteriores: 1 });
  tem('formatador proprio', h, 'R$ 25');
  tem('limite de anteriores', h, 'e 6 mês(es) antes destes');
  eq('attr escapa aspas e sinais', Tip.attr('<a href="x">&</a>'), ' data-tip="&lt;a href=&quot;x&quot;&gt;&amp;&lt;/a&gt;"');
}

// ── calcCrescimento ───────────────────────────────────────────────────────────
{
  const src = lerArq('index.html');
  const a = src.indexOf("const _MESES_PT = [");
  const b = src.indexOf('function calcCrescimento(');
  const fim = src.indexOf('\n}\n', b) + 3;
  if (a < 0 || b < 0) { console.log('FALHA calcCrescimento nao encontrado'); process.exit(1); }
  const sandbox = {};
  vm.createContext(sandbox);
  vm.runInContext(src.slice(a, fim) + '\nthis.calc = calcCrescimento;', sandbox);
  const calc = sandbox.calc;
  const hoje = new Date();
  const mesAtras = n => { const d = new Date(hoje.getFullYear(), hoje.getMonth() - n, 15); return d.toISOString().slice(0, 10); };
  const atletas = [
    { idatleta: 1, dtcadastro: mesAtras(5) },
    { idatleta: 2, dtcadastro: mesAtras(2) },
    { idatleta: 3, dtcadastro: mesAtras(2) },
    { idatleta: 4 },                                   // sem data nenhuma
    { idatleta: 5 },                                   // sem dtcadastro, mas tem matricula: entra pela matricula
    { idatleta: 6, dtcadastro: new Date(hoje.getFullYear(), hoje.getMonth() - 3 + 1, 0).toISOString().slice(0, 10) }, // ultimo dia do mes -3
  ];
  const mens = [{ idatleta: 5, dtinicio: mesAtras(1), dtvencimento: mesAtras(-1), stpgto: 'S' }];
  console.log('\n── calcCrescimento');
  for (const n of [6, 12, 24]) eq('janela de ' + n + ' meses', calc(atletas, mens, [], n).janela.length, n);
  const c = calc(atletas, mens, [], 12);
  const ult = c.janela[c.janela.length - 1];
  eq('ultimo mes e o mes atual', ult.chave, hoje.getFullYear() + '-' + String(hoje.getMonth() + 1).padStart(2, '0'));
  tem('rotulo longo traz o ano', ult.completo, '/' + String(hoje.getFullYear()).slice(2));
  eq('cadastros acumulados no fim = quem tem alguma data', c.acum[11], 5);
  eq('quem entrou no ultimo dia do mes ja conta naquele mes', [c.acum[8], c.acum[9]], [2, 4]);
  eq('quem nao tem data nenhuma fica fora e e contado', c.semData, 1);
  eq('novos no mes de entrada (mes -2)', c.novos[9], 2);
  eq('entrada estimada pela matricula (mes -1)', c.novos[10], 1);
  eq('curva acumulada nunca diminui', c.acum.every((v, i) => i === 0 || v >= c.acum[i - 1]), true);
  eq('ativos: matricula paga cobre o mes atual', c.ativos[11], 1);
}

console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
process.exit(ruim ? 1 : 0);
