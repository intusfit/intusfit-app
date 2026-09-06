// Valida a sintaxe do JavaScript embutido em cada tela. Erro de sintaxe aqui
// significa tela em branco no celular do aluno.
const fs = require('fs'), vm = require('vm');
const DIR = (process.env.INTUS_DIR || '.') + '/';
let falhas = 0, blocos = 0;
fs.readdirSync(DIR).filter(f => /\.(html|js)$/.test(f)).forEach(f => {
  const src = fs.readFileSync(DIR + f, 'utf8');
  const pedacos = f.endsWith('.js') ? [src]
    : [...src.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi)].map(m => m[1]);
  pedacos.forEach((js, i) => {
    if (!js.trim()) return;
    blocos++;
    try { new vm.Script(js, { filename: f + ' #' + (i + 1) }); }
    catch (e) { console.log('FALHA  ' + f + ' bloco ' + (i + 1) + ': ' + e.message); falhas++; }
  });
});
console.log(falhas === 0 ? blocos + ' blocos de script, nenhum erro de sintaxe.' : falhas + ' bloco(s) com erro.');
process.exit(falhas === 0 ? 0 : 1);
