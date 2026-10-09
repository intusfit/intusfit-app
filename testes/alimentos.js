// Banco de alimentos de verdade (alimentos-db.js + taco.json + unidades.json): ids, medidas caseiras e plano antigo.
const fs = require('fs'), vm = require('vm'), path = require('path');
const PAINEL = process.env.INTUS_DIR || '.';
const DATA = path.join(PAINEL, '..', 'data');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}

function carregar(meusAlimentos) {
  const sandbox = {
    console, setTimeout, clearTimeout, Promise, JSON, Math, Number, String, Object, Array, Set, Map, Error,
    fetch: async (url) => {
      const arq = path.join(DATA, String(url).split('/').pop().split('?')[0]);
      if (!fs.existsSync(arq)) return { ok: false, status: 404, json: async () => ({}) };
      const txt = fs.readFileSync(arq, 'utf8');
      return { ok: true, status: 200, json: async () => JSON.parse(txt) };
    },
    API: {
      listarAlimentosPersonalizados: async () => JSON.parse(JSON.stringify(meusAlimentos || [])),
      criarAlimentoPersonalizado: async (d) => Object.assign({ id: 7 }, d),
      editarAlimentoPersonalizado: async () => ({ ok: true }),
      excluirAlimentoPersonalizado: async () => ({ ok: true }),
    },
  };
  sandbox.window = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(path.join(PAINEL, 'alimentos-db.js'), 'utf8') + '\n;this.AlimentosDB = AlimentosDB;', sandbox, { filename: 'alimentos-db.js' });
  return sandbox.AlimentosDB;
}

(async () => {
  const meus = [{ id: 1, description: 'Whey Teste', energy_kcal: 400, protein_g: 80, carbohydrate_g: 8, lipid_g: 6, medida_padrao: 'scoop', porcao_padrao: 30 },
                { id: 489, description: 'Barrinha Teste', energy_kcal: 380, protein_g: 20, carbohydrate_g: 40, lipid_g: 12, medida_padrao: 'g', porcao_padrao: 100 }];
  const DB = carregar(meus);
  await DB.load();
  const taco = JSON.parse(fs.readFileSync(path.join(DATA, 'taco.json'), 'utf8'));
  const unid = JSON.parse(fs.readFileSync(path.join(DATA, 'unidades.json'), 'utf8')).itens;

  console.log('\n── 1. Meus alimentos não escondem a TACO ──');
  eq('TACO 1 continua sendo o arroz', DB.getById(1).description, 'Arroz, integral, cozido');
  eq('TACO 489 continua sendo o ovo', DB.getById(489).description, 'Ovo, de galinha, inteiro, cru');
  const w = DB.getCustomFoods().find(f => f.description === 'Whey Teste');
  eq('meu alimento tem id fora da faixa da TACO', w.id >= DB.CUSTOM_BASE, true);
  eq('guarda o id do servidor', w.dbid, 1);
  eq('busca "arroz integral" acha o arroz mesmo com meu alimento de id 1', DB.buscar('arroz integral', 3)[0].description, 'Arroz, integral, cozido');
  eq('getById do meu alimento', DB.getById(w.id).description, 'Whey Teste');
  const novo = await DB.addCustomFood({ description: 'Novo', energy_kcal: 100 });
  eq('alimento criado também entra com id novo', novo.id, DB.CUSTOM_BASE + 7);

  console.log('\n── 2. Plano antigo: o nome decide quando o número é ambíguo ──');
  eq('id 1 + nome da TACO = arroz', DB.resolver({ food_id: 1, nome: 'Arroz, integral, cozido' }).description, 'Arroz, integral, cozido');
  eq('id 1 + nome do meu alimento = whey (plano antigo guardava o número cru)', DB.resolver({ food_id: 1, nome: 'Whey Teste' }).description, 'Whey Teste');
  eq('id novo de meu alimento', DB.resolver({ food_id: w.id, nome: 'Whey Teste' }).description, 'Whey Teste');
  eq('nome que não existe em lugar nenhum = null', DB.resolver({ food_id: 1, nome: 'Alimento fantasma' }), null);
  eq('sem nome, o número vale como TACO', DB.resolver({ food_id: 7 }).description, 'Aveia, flocos, crua');
  eq('meu alimento apagado = null', DB.resolver({ food_id: DB.CUSTOM_BASE + 999, nome: 'Qualquer' }), null);

  console.log('\n── 3. Medidas caseiras ──');
  eq('ovo de galinha: 1 unidade = 50 g', DB.getMedidasAlimento(489).map(m => m.id + '=' + m.gramas), ['unidade=50']);
  eq('ovo entra por padrão com 2 unidades', DB.getUnidadePadrao(489), { medida_id: 'unidade', medida_nome: 'Unidade', medida_g: 50, quantidade: 2 });
  eq('2 ovos = 143 kcal (100 g), não 286', DB.calcNutrientes(489, 100).energia_kcal, 143.1);
  eq('clara 33 g, gema 17 g', [DB.getMedidasAlimento(486)[0].gramas, DB.getMedidasAlimento(487)[0].gramas], [33, 17]);
  eq('aveia não herda as medidas do arroz', DB.getMedidasAlimento(7).map(m => m.id), ['colher_sopa', 'xicara']);
  eq('banana prata: 1 unidade 65 g', DB.getMedidasAlimento(182)[0].gramas, 65);
  eq('alimento sem medida cadastrada fica só em gramas', DB.getMedidasAlimento(65), []);
  eq('sem medida, sem padrão (usa 100 g)', DB.getUnidadePadrao(65), null);
  eq('meu alimento com medida: 1 scoop = 30 g', DB.getMedidasAlimento(w.id).map(m => m.nome + '=' + m.gramas), ['scoop=30']);
  eq('meu alimento só em gramas não inventa medida', DB.getMedidasAlimento(DB.getCustomFoods().find(f => f.description === 'Barrinha Teste').id), []);

  console.log('\n── 4. Catálogo de medidas confere com a TACO ──');
  eq('todo id do catálogo existe na TACO', Object.keys(unid).filter(id => !taco.find(f => String(f.id) === id)), []);
  eq('a medida padrão de cada alimento existe na lista dele', Object.keys(unid).filter(id => !unid[id].m.some(m => m[0] === unid[id].p[0])), []);
  eq('toda medida pesa mais que zero', Object.keys(unid).filter(id => unid[id].m.some(m => !(m[2] > 0))), []);
  eq('nenhum ovo de galinha pesa mais de 60 g por unidade', Object.keys(unid).filter(id => /^ovo, de galinha/i.test(taco.find(f => String(f.id) === id).description) && unid[id].m[0][2] > 60), []);

  console.log('\n── 5. Nutrientes ──');
  const p100 = DB.nutrientesPor100(DB.getById(489));
  eq('tabela de 100 g do ovo (4 casas guardadas)', [p100.energia_kcal, p100.proteina_g, p100.lipidio_g].map(v => Math.round(v * 10) / 10), [143.1, 13, 8.9]);
  eq('escalar 150 g', DB.escalar(p100, 150).energia_kcal, 214.7);
  eq('escalar bate com calcNutrientes', DB.escalar(p100, 150), DB.calcNutrientes(489, 150));
  eq('tem os 20 nutrientes', Object.keys(DB.calcNutrientes(489, 100)).length, 20);
  eq('alimento inexistente = null', DB.calcNutrientes(99999, 100), null);

  console.log('\n── 6. Alimentos extras (IBGE POF 2008-2009 e USDA SR Legacy) ──');
  const extra = JSON.parse(fs.readFileSync(path.join(DATA, 'alimentos-extra.json'), 'utf8'));
  const ex = extra.alimentos;
  eq('mais de mil alimentos extras', ex.length > 1000, true);
  eq('ids únicos, entre a TACO e os Meus alimentos', [new Set(ex.map(f => f.id)).size === ex.length, ex.every(f => f.id >= DB.EXTRA_BASE && f.id < DB.CUSTOM_BASE)], [true, true]);
  eq('todo item tem fonte, categoria e calorias', ex.filter(f => !f.source || !f.category || !(f.energy_kcal >= 0)).length, 0);
  eq('nenhum valor negativo', ex.filter(f => Object.keys(f).some(k => typeof f[k] === 'number' && f[k] < 0 && k !== 'id')).length, 0);
  // Se a leitura do PDF tivesse deslocado colunas, as calorias deixariam de bater com os macros (4 P + 4 C + 9 G).
  const fora = ex.filter(f => f.energy_kcal > 20 && Math.abs(f.energy_kcal - (4 * (f.protein_g || 0) + 4 * (f.carbohydrate_g || 0) + 9 * (f.lipid_g || 0))) / f.energy_kcal > 0.35);
  eq('calorias batem com os macros em 97% ou mais (o resto é álcool, fibra e adoçante)', fora.length / ex.length < 0.03, true);
  eq('busca por "quinoa" acha o USDA traduzido', DB.buscar('quinoa cozida', 3).map(f => f.description)[0], 'Quinoa, cozida');
  const q = DB.buscar('quinoa cozida', 1)[0];
  eq('quinoa tem medida caseira (xícara)', DB.getMedidasAlimento(q.id).map(m => m.id), ['xicara']);
  eq('a TACO continua na frente quando os dois têm o alimento', DB.buscar('arroz integral', 2)[0].id < DB.EXTRA_BASE, true);
  eq('alimento do IBGE entra na busca', DB.buscar('mucilon', 3).length > 0, true);
  eq('resolver acha extra pelo número e pelo nome', [DB.resolver({ food_id: q.id, nome: 'Quinoa, cozida' }).id, DB.resolver({ food_id: 1, nome: 'Quinoa, cozida' }).id], [q.id, q.id]);
  eq('escalar um extra', DB.calcNutrientes(q.id, 100).energia_kcal, 120);
  eq('o catálogo de medidas dos extras só aponta para extras', Object.keys(extra.medidas).filter(id => !ex.some(f => String(f.id) === id)), []);
  eq('alimento com id de extra e nome diferente não é confundido', DB.resolver({ food_id: q.id, nome: 'Arroz, integral, cozido' }).id, 1);

  console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
  process.exit(ruim ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
