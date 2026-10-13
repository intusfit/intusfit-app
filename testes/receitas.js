// Receitas e dicas do app (nutri-conteudo.json): integridade, fotos e coerência da informação nutricional.
const fs = require('fs'), path = require('path');
const PAINEL = process.env.INTUS_DIR || '.';
const d = JSON.parse(fs.readFileSync(path.join(PAINEL, 'nutri-conteudo.json'), 'utf8'));
let ok = 0, ruim = 0;
function eq(rot, cond, detalhe) { if (cond) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + (detalhe ? '\n          ' + detalhe : '')); } }
const cats = new Set(d.categorias.map(c => c.id));
const ids = d.receitas.map(r => r.id);
console.log('\n── Estrutura ──');
eq('ids de receita são únicos', new Set(ids).size === ids.length, ids.filter((x, i) => ids.indexOf(x) !== i).join(','));
eq('toda receita tem categoria válida, ingredientes e preparo', d.receitas.every(r => cats.has(r.cat) && r.ingredientes.length && r.preparo.length && r.titulo && r.tempo && r.porcoes));
eq('toda foto existe e tem tamanho razoável (5 KB a 400 KB)', d.receitas.every(r => { const f = path.join(PAINEL, r.foto || '__x'); return r.foto && fs.existsSync(f) && fs.statSync(f).size > 5000 && fs.statSync(f).size < 400000; }),
   d.receitas.filter(r => !(r.foto && fs.existsSync(path.join(PAINEL, r.foto)))).map(r => r.id).join(','));
console.log('\n── Dificuldade e informação nutricional em todas ──');
const _niv = ['Fácil', 'Média', 'Elaborada'], _desvio = r => { const n = r.nutricao; return Math.abs(4 * n.proteina + 4 * n.carboidrato + 9 * n.gordura - n.kcal) > Math.max(30, 0.2 * n.kcal); };
eq('toda receita tem dificuldade (Fácil, Média ou Elaborada)', d.receitas.every(r => _niv.includes(r.dificuldade)), d.receitas.filter(r => !_niv.includes(r.dificuldade)).map(r => r.id).join(','));
eq('toda receita tem kcal e macros por porção', d.receitas.every(r => r.nutricao && r.nutricao.kcal > 0), d.receitas.filter(r => !(r.nutricao && r.nutricao.kcal > 0)).map(r => r.id).join(','));
eq('kcal bate com os macros em todas (tolerância de 20% ou 30 kcal)', d.receitas.filter(r => r.nutricao).every(r => !_desvio(r)), d.receitas.filter(r => r.nutricao && _desvio(r)).map(r => r.id).join(','));
console.log('\n── Receitas novas (as com informação nutricional completa por porção) ──');
const novas = d.receitas.filter(r => r.nutricao && r.nutricao.kcal != null && r.id !== 'strogonoff-light-frango' && !/^Estimativa/.test(r.nutricao.porcao || ''));
const estimadas = d.receitas.filter(r => r.nutricao && /^Estimativa/.test(r.nutricao.porcao || ''));
eq('há receitas novas com informação nutricional', novas.length >= 46, 'achou ' + novas.length);
eq('todo ingrediente tem quantidade ou é tempero "a gosto"/opcional',
   novas.every(r => r.ingredientes.every(i => /[\d½¼¾]|a gosto|opcional|para dar ponto/i.test(i))), novas.flatMap(r => r.ingredientes.filter(i => !/[\d½¼¾]|a gosto|opcional|para dar ponto/i.test(i))).join(' | '));
eq('calorias batem com os macros (4P + 4C + 9G, tolerância de 15% ou 25 kcal)', novas.every(r => {
  const n = r.nutricao, est = 4 * n.proteina + 4 * n.carboidrato + 9 * n.gordura; return Math.abs(est - n.kcal) <= Math.max(25, 0.15 * n.kcal); }),
  novas.filter(r => { const n = r.nutricao, est = 4 * n.proteina + 4 * n.carboidrato + 9 * n.gordura; return Math.abs(est - n.kcal) > Math.max(25, 0.15 * n.kcal); }).map(r => r.id + ' ' + r.nutricao.kcal).join(', '));
eq('a proteína do texto ("≈ N g") é a mesma da informação nutricional', novas.every(r => !r.proteina || Number((r.proteina.match(/\d+/) || [])[0]) === r.nutricao.proteina));
eq('nenhuma receita promete resultado de saúde ou emagrecimento', novas.every(r => !/emagrec|queima|detox|milagr|cura/i.test(JSON.stringify(r))));
console.log('\n' + (ruim ? ruim + ' FALHA(S), ' : '') + ok + ' ok');
process.exit(ruim ? 1 : 0);
