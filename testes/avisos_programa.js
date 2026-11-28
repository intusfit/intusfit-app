// Avisos de troca de bloco dos programas de cardio: blocos curtos em série viram um aviso só, e os avisos antigos saem da barra do celular.
const fs = require('fs'), path = require('path'), vm = require('vm');
const DIR = process.env.INTUS_DIR || path.resolve(__dirname, '../app/painel');
const html = fs.readFileSync(path.join(DIR, 'aluno.html'), 'utf8').replace(/\r\n/g, '\n');
const ini = html.indexOf('const _PROG_AVISO_MIN_SEG'), fim = html.indexOf('function _progCancelarNativo()');
let falhas = 0, total = 0;
const eq = (nome, ok, extra) => { total++; if (!ok) falhas++; console.log((ok ? '  ok    ' : '  FALHA ') + nome + (!ok && extra !== undefined ? '  -> ' + JSON.stringify(extra) : '')); };
if (ini < 0 || fim < ini) { console.log('  FALHA bloco dos avisos não encontrado'); process.exit(1); }

let entregues = [], removidos = [];
const LN = { getDeliveredNotifications: async () => ({ notifications: entregues }), removeDeliveredNotifications: async o => { removidos = o.notifications.map(n => n.id); } };
const ctx = { _progMin: s => s < 60 ? s + ' s' : Math.round(s / 60) + ' min', _progLN: () => LN, document: { addEventListener() {}, hidden: false }, setTimeout: () => 0 };
vm.createContext(ctx);
vm.runInContext(html.slice(ini, fim) + '\nthis.aviso = _progAvisoDoBloco; this.limpar = _progLimparEntregues;', ctx);

const B = (n, s, f) => ({ n, s, f });
const avisos = et => et.map((x, j) => ctx.aviso(et, j)).filter(Boolean);
const tabata = [B('Aquecimento', 300, 'aq')];
for (let i = 0; i < 8; i++) tabata.push(B('Pedala forte', 20, 'forte'), B('Pedala leve', 10, 'leve'));
tabata.push(B('Volta à calma', 180, 'calma'));
const t = avisos(tabata);
eq('Tabata de 18 blocos gera 3 avisos', t.length === 3, t);
eq('o aviso da série diz o que vai acontecer', t[1] && t[1].titulo === 'COMEÇAM OS TIROS' && t[1].corpo === '8x Pedala forte 20 s e Pedala leve 10 s', t[1]);
eq('a volta à calma continua avisando', t[2] && t[2].titulo === 'VOLTA À CALMA', t[2]);

const longos = [B('Aquecimento', 600, 'aq'), B('Forte', 240, 'forte'), B('Leve', 180, 'leve'), B('Forte', 240, 'forte'), B('Corra', 60, 'forte'), B('Caminhe', 90, 'leve')];
eq('blocos de 1 minuto ou mais avisam um a um', avisos(longos).length === longos.length, avisos(longos));

const mob = [B('Polichinelo', 30, 'aq'), B('Giro de braços', 30, 'aq'), B('Joelho alto', 30, 'aq'), B('Forte', 30, 'forte'), B('Leve', 30, 'leve'), B('Alongue as pernas', 40, 'calma'), B('Respire', 40, 'calma')];
const m = avisos(mob);
eq('aquecimento, tiros e volta à calma em blocos curtos: um aviso para cada parte', m.map(x => x.titulo).join('|') === 'AQUECIMENTO|COMEÇAM OS TIROS|VOLTA À CALMA', m);
eq('um bloco curto sozinho avisa normalmente', avisos([B('Aquecimento', 300, 'aq'), B('Descanso', 30, 'leve'), B('Forte', 120, 'forte')]).length === 3);

(async () => {
  entregues = [{ id: 91001 }, { id: 91005 }, { id: 91018 }, { id: 91999 }, { id: 92001 }, { id: 5 }];
  removidos = []; await ctx.limpar(91018);
  eq('na troca de bloco saem só os avisos de blocos anteriores', removidos.join() === '91001,91005', removidos);
  removidos = []; await ctx.limpar(92000);
  eq('ao voltar para o app saem todos os do programa e nenhum outro', removidos.join() === '91001,91005,91018,91999', removidos);
  console.log('\n' + (total - falhas) + '/' + total + ' ok');
  process.exit(falhas ? 1 : 0);
})();
