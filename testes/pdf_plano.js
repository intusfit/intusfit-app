// Importador de plano em PDF (importar-pdf.js): interpretação do layout (refeições, "OU", substituições, observações) e casamento
// com a base de alimentos de verdade (alimentos-db.js + taco.json + extras). Os trechos abaixo imitam o PDF exportado do sistema de
// dieta (nome a x=72, Kcal a x=345, Grupo a x=436, observações a x=60); não há dado de aluno aqui.
const fs = require('fs'), vm = require('vm'), path = require('path');
const PAINEL = process.env.INTUS_DIR || '.';
const DATA = path.join(PAINEL, '..', 'data');
const IP = require(path.join(PAINEL, 'importar-pdf.js'));
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}

const T = (str, x, y, pg) => ({ pg: pg || 1, str, x, y });
let Y = 100;
const linha = (nome, kcal, grupo, x) => { Y += 14; return [T(nome, x || 72, Y), T(kcal, 345, Y), T(grupo || '-', 436, Y)]; };
const cab = (nome) => { Y += 20; return [T(nome, 72, Y), T('Kcal', 345, Y), T('Grupo', 436, Y)]; };
const nota = (txt) => { Y += 14; return [T(txt, 60, Y)]; };
const itens = [
  T('Plano Alimentar', 266, 37), T('maria teste', 264, 50), T('09/10/2026', 274, 64), T('Todos os dias', 58, 91),
  T('Plano alimentar para maria teste - emagrecimento (duplicado) (importado)', 58, 103),
  T('09:00 - Café da manhã', 70, 126), T('Kcal', 345, 149), T('Grupo', 436, 149),
  ...linha('Ovo de galinha (Unidade: 2)', '139,50 kcal', 'Aves e ovos'),
  // nome em duas linhas, com "OU" em trecho separado, e uma alternativa só de texto
  T('Mamão formosa (Grama: 170) ', 72, 179), T('OU', 192, 179), T(' Kiwi (Grama: 150) ', 205, 179), T('OU', 280, 179), T(' Morango', 293, 179),
  T('(Grama: 240) ', 72, 189), T('OU', 127, 189), T(' outro tipo de fruta', 140, 189), T('54,40 kcal', 345, 189), T('-', 436, 189),
  ...linha('Peito de galinha OU frango Cozido(a) (Grama: 100)', '173,00 kcal', 'Aves e ovos'),
  ...linha('Marca Inventada Super Protein (Grama: 30)', '120,00 kcal'),
  ...nota('Fruta é sugestão, pode consumir a que estiver disponível'), ...nota('e não precisa comer junto.'),
  ...cab('Substituição 1'),
  ...linha('Whey protein (Grama: 30)', '119,00 kcal'),
  ...linha('Iogurte Grego Natural Marca (Grama: 150)', '228,00 kcal', 'Farinhas, féculas e'),
  ...nota('Mingau: cozinhe por 5 min.'),
  T('12:00 - almoço', 70, 400), T('Kcal', 345, 420), T('Grupo', 436, 420),
  ...linha('Arroz branco (cozido) (Grama: 100) OU Batata inglesa cozida (Grama: 120) OU outro carboidrato', '124,69 kcal'),
  ...linha('Salmão (a vontade : 1)', '21,00 kcal'),
  T('Brócolis (cozido) (Grama: 100)', 72, 40, 2), T('28,00 kcal', 345, 40, 2), T('-', 436, 40, 2),   // continua na página 2
];

(async () => {
  const sandbox = {
    console: { log() {}, warn() {} }, setTimeout, Promise, JSON, Math, Number, String, Object, Array, Set, Map, Error,
    fetch: async (url) => { const arq = path.join(DATA, String(url).split('/').pop().split('?')[0]); if (!fs.existsSync(arq)) return { ok: false, status: 404, json: async () => ({}) }; const t = fs.readFileSync(arq, 'utf8'); return { ok: true, status: 200, json: async () => JSON.parse(t) }; },
    API: { listarAlimentosPersonalizados: async () => [], listarMedidasEquipe: async () => [] },
  };
  sandbox.window = sandbox; vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(path.join(PAINEL, 'alimentos-db.js'), 'utf8') + '\n;this.AlimentosDB = AlimentosDB;', sandbox);
  const A = sandbox.AlimentosDB; await A.load();

  console.log('\n── interpretar');
  const e = IP.interpretar(itens);
  eq('nome do aluno vem da linha depois do título', e.aluno, 'Maria Teste');
  eq('título sem as etiquetas (duplicado) e (importado)', e.titulo, 'Plano alimentar para Maria Teste - emagrecimento');
  eq('objetivo sai do que vem depois do " - "', e.objetivo, 'Emagrecimento');
  eq('data', e.data, '09/10/2026');
  eq('duas refeições, na ordem', e.refeicoes.map(r => r.horario + ' ' + r.nome), ['09:00 Café da manhã', '12:00 Almoço']);
  const cafe = e.refeicoes[0], almoco = e.refeicoes[1];
  eq('café: 4 alimentos principais e 1 substituição', [cafe.principal.itens.length, cafe.subs.length], [4, 1]);
  eq('substituição 1 tem 2 alimentos', cafe.subs[0].itens.length, 2);
  eq('calorias lidas com vírgula', cafe.principal.itens[0].kcal, 139.5);
  eq('nome em duas linhas com "OU": principal', cafe.principal.itens[1].principal, { nome: 'Mamão formosa', qtd: 170, unidade: 'Grama' });
  eq('nome em duas linhas com "OU": alternativas', cafe.principal.itens[1].alternativas, [{ nome: 'Kiwi', qtd: 150, unidade: 'Grama' }, { nome: 'Morango', qtd: 240, unidade: 'Grama' }, { texto: 'outro tipo de fruta' }]);
  eq('"Peito de galinha OU frango" é um alimento só', cafe.principal.itens[2].principal.nome, 'Peito de galinha OU frango Cozido(a)');
  eq('observação da refeição junta as linhas', cafe.principal.nota, 'Fruta é sugestão, pode consumir a que estiver disponível e não precisa comer junto.');
  eq('observação da substituição', cafe.subs[0].nota, 'Mingau: cozinhe por 5 min.');
  eq('grupo alimentar (coluna da direita) não entra no nome', cafe.subs[0].itens[1].principal.nome, 'Iogurte Grego Natural Marca');
  eq('almoço: alternativa com quantidade e outra só texto', almoco.principal.itens[0].alternativas, [{ nome: 'Batata inglesa cozida', qtd: 120, unidade: 'Grama' }, { texto: 'outro carboidrato' }]);
  eq('tabela que continua na página seguinte', almoco.principal.itens.length, 3);
  eq('unidade com espaço antes dos dois pontos', almoco.principal.itens[1].principal, { nome: 'Salmão', qtd: 1, unidade: 'a vontade' });

  console.log('\n── montarPlano (base de alimentos real)');
  const cat = { todos: () => A.todos(), medidas: f => A.getMedidasAlimento(f.id), por100: f => A.nutrientesPor100(f), escalar: (p, g) => A.escalar(p, g) };
  const r = IP.montarPlano(e, cat), p = r.plano;
  const it = p.refeicoes[0].itens_estruturados;
  eq('formato e data', [p.formato, p.dtinicio], ['intus-plano-v1', '2026-10-09']);
  eq('ovo casa com o catálogo na medida "unidade" (2 un = 100 g)', [it[0].food_id > 0, it[0].medida_id, it[0].gramas], [true, 'unidade', 100]);
  eq('mamão casa com o catálogo, em gramas', [it[1].food_id > 0, it[1].medida_id, it[1].gramas], [true, 'g', 170]);
  eq('alternativas com quantidade viram trocas completas (kiwi, morango)', it[1].substitutos.slice(0, 2).map(s => [s.food_id > 0, s.gramas]), [[true, 150], [true, 240]]);
  eq('alternativa só de texto vira troca manual', it[1].substitutos[2], { nome: 'outro tipo de fruta', quantidade: '', medida: '', food_id: null, manual: true });
  eq('frango cozido: casa pelo nome e pelas calorias', Math.round(it[2].nutrientes.energia_kcal), 173);
  eq('marca desconhecida entra só com as calorias do PDF', [it[3].food_id, it[3].nutrientes.energia_kcal, it[3].nutrientes.proteina_g], [null, 120, 0]);
  eq('whey casa com o catálogo', p.refeicoes[0].refeicoes_substitutas[0].itens_estruturados[0].food_id > 0, true);
  eq('iogurte de marca fica só com as calorias', p.refeicoes[0].refeicoes_substitutas[0].itens_estruturados[1].nutrientes.energia_kcal, 228);
  eq('observações passam para o plano', [p.refeicoes[0].nota, p.refeicoes[0].refeicoes_substitutas[0].nota], ['Fruta é sugestão, pode consumir a que estiver disponível e não precisa comer junto.', 'Mingau: cozinhe por 5 min.']);
  const alm = p.refeicoes[1].itens_estruturados;
  eq('arroz branco casa com "Arroz, tipo 1, cozido"', A.getById(alm[0].food_id).description, 'Arroz, tipo 1, cozido');
  eq('troca com quantidade e troca em texto', [alm[0].substitutos[0].food_id > 0, alm[0].substitutos[1].manual], [true, true]);
  eq('medida "a vontade" desconhecida e alimento ambíguo: só calorias, sem chute', [alm[1].food_id, alm[1].nutrientes.energia_kcal], [null, 21]);
  eq('relatório tem uma linha por alimento ou troca', r.relatorio.length >= 12, true);
  eq('relatório marca os três jeitos de entrar', [...new Set(r.relatorio.map(x => x.tipo))].sort(), ['catalogo', 'so_calorias', 'texto']);
  eq('nome da refeição em minúsculas vira com inicial maiúscula', p.refeicoes[1].nome, 'Almoço');

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
