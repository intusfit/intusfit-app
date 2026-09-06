// Guarda contra o erro que já aconteceu: uma substituição com âncora repetida
// apagou 42 mil caracteres de aluno.html sem quebrar a sintaxe. Este teste
// compara o inventário de funções antes/depois e reprova qualquer sumiço.
const fs = require('fs');
const DIR = (process.env.INTUS_DIR || '.') + '/';
const ARQS = fs.readdirSync(DIR).filter(f => /\.(html|js)$/.test(f));
function inventario() {
  const out = {};
  ARQS.forEach(f => {
    const src = fs.readFileSync(DIR + f, 'utf8');
    const nomes = new Set();
    let m;
    const re1 = /\bfunction\s+([A-Za-z_$][\w$]*)\s*\(/g;
    while ((m = re1.exec(src))) nomes.add(m[1]);
    const re2 = /\b(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?(?:function\b|\([^)]*\)\s*=>)/g;
    while ((m = re2.exec(src))) nomes.add(m[1]);
    out[f] = [...nomes].sort();
    out[f + '::bytes'] = src.length;
  });
  return out;
}
const ARQ_BASE = (process.env.INTUS_BASE || __dirname) + '/funcoes.json';
const atual = inventario();
if (process.argv[2] === 'gravar' || !fs.existsSync(ARQ_BASE)) {
  fs.writeFileSync(ARQ_BASE, JSON.stringify(atual, null, 1));
  console.log('Inventário gravado: ' + Object.keys(atual).filter(k => !k.includes('::')).length + ' arquivos.');
  process.exit(0);
}
const base = JSON.parse(fs.readFileSync(ARQ_BASE, 'utf8'));
let falhas = 0;
Object.keys(base).forEach(k => {
  if (k.includes('::bytes')) {
    const f = k.replace('::bytes', '');
    const antes = base[k], depois = atual[k];
    if (depois == null) return;
    const queda = antes - depois;
    if (queda > antes * 0.05) { console.log('FALHA  ' + f + ' encolheu ' + queda + ' bytes (' + (100*queda/antes).toFixed(1) + '%)'); falhas++; }
    return;
  }
  const sumiram = (base[k] || []).filter(n => !(atual[k] || []).includes(n));
  if (sumiram.length) { console.log('FALHA  ' + k + ' perdeu: ' + sumiram.join(', ')); falhas++; }
});
Object.keys(atual).forEach(k => {
  if (k.includes('::bytes')) return;
  const novas = (atual[k] || []).filter(n => !(base[k] || []).includes(n));
  if (novas.length) console.log('nova   ' + k + ': ' + novas.join(', '));
});
console.log(falhas === 0 ? 'Nenhuma função sumiu.' : falhas + ' problema(s).');
process.exit(falhas === 0 ? 0 : 1);
