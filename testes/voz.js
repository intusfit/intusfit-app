// Voz gravada dos programas de cardio: todo texto falado precisa ter áudio no manifesto, e o arquivo precisa existir e ser MP3 de verdade.
// Lê as falas direto do aluno.html (arrays "voz: [...]" de _PROG_ATIV, listas de _progFrasesBloco e falas fixas) e confere o app/painel/voz/manifest.json.
const fs = require('fs'), path = require('path');
const DIR = process.env.INTUS_DIR || path.join(__dirname, '..', 'app', 'painel');
const html = fs.readFileSync(path.join(DIR, 'aluno.html'), 'utf8');
const man = JSON.parse(fs.readFileSync(path.join(DIR, 'voz', 'manifest.json'), 'utf8'));
let ok = 0, ruim = 0;
function eq(rot, cond, det) { if (cond) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + (det ? '\n          ' + det : '')); } }
const RE_STR = /'((?:[^'\\]|\\.)*)'/g;
const strs = txt => (txt.match(RE_STR) || []).map(x => x.slice(1, -1).split("\\'").join("'"));
const falas = new Set(['Prepare-se! Vamos lá!', 'Três', 'Dois', 'Um', 'Faltam dez segundos!', 'Programa concluído! Parabéns, você mandou muito bem!']);
// arrays voz: [ ... ] de _PROG_ATIV
(html.match(/voz: \[([^\]]*)\]/g) || []).forEach(b => strs(b).forEach(f => falas.add(f)));
// listas de _progFrasesBloco (as linhas de if/return com texto falado)
const i = html.indexOf('function _progFrasesBloco'), j = html.indexOf('function _progFraseBloco(e)');
const NAO = ['forte', 'leve', 'aq', 'calma', 'corre', 'caminha', '.'];
html.slice(i, j).split('\n').filter(l => /^\s*(if|return)/.test(l) && l.indexOf('return e.voz') < 0).forEach(l => strs(l).forEach(f => { if (/[a-zà-ú]/i.test(f) && NAO.indexOf(f) < 0) falas.add(f); }));
// blocos contínuos falados pelo nome ("<nome>.")
['Inclinação 12%.', 'Ritmo de conversar.', 'Descanso entre blocos.'].forEach(f => falas.add(f));
console.log('\n── Cobertura das falas ──');
const sem = [...falas].filter(f => !Object.prototype.hasOwnProperty.call(man, f));
eq(falas.size + ' falas do app, todas com áudio no manifesto', sem.length === 0, 'sem áudio: ' + sem.join(' | '));
console.log('\n── Arquivos ──');
const chaves = Object.keys(man).filter(k => k[0] !== '_');
const faltaArq = [], naoMp3 = [], pequenos = [];
chaves.forEach(k => {
  const p = path.join(DIR, 'voz', man[k]);
  if (!fs.existsSync(p)) { faltaArq.push(man[k]); return; }
  const b = fs.readFileSync(p);
  if (!(b[0] === 0xff && (b[1] & 0xe0) === 0xe0) && b.slice(0, 3).toString() !== 'ID3') naoMp3.push(man[k]);
  if (b.length < 3000) pequenos.push(man[k]);
});
eq(chaves.length + ' arquivos existem', faltaArq.length === 0, faltaArq.join(', '));
eq('todos começam como MP3', naoMp3.length === 0, naoMp3.join(', '));
eq('nenhum áudio suspeito de vazio (menos de 3 KB)', pequenos.length === 0, pequenos.join(', '));
eq('o manifesto não tem áudio que o app não usa', chaves.every(k => falas.has(k)), chaves.filter(k => !falas.has(k)).join(' | '));
eq('manifesto declara a fonte e se é licenciado', !!(man._meta && typeof man._meta.licenciado === 'boolean' && man._meta.fonte));
console.log('\n' + (ruim ? ruim + ' FALHA(S)' : ok + ' ok'));
process.exit(ruim ? 1 : 0);
