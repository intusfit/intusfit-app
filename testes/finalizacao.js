// Finalizar treino: duas falhas achadas no caso do Eduardo (06/11/2026), em que um treino de 44 min ficou sem pontos.
//  1) O gesto de voltar, com a foto do "Compartilhar treino" aberta, mostrava "Finalizou o treino sem querer?" por cima da foto.
//     Quem tocava em "Voltar e continuar" reabria o treino. As telas abertas por cima do fim têm de fechar ANTES da pergunta.
//  2) Treino reaberto e finalizado de novo em poucos minutos: o limite de 5 min era conferido só no trecho novo, e "Finalizar mesmo
//     assim" regravava o treino inteiro como "não conta". O limite vale para a duração TOTAL, no app e no servidor.
// Confere o código-fonte (não abre navegador): quebrar qualquer das travas de propósito faz o teste acusar.
const fs = require('fs'), path = require('path');
const dir = process.env.INTUS_DIR || path.join(__dirname, '..', 'app', 'painel');
const base = process.env.INTUS_BASE || path.join(__dirname, '..');
const app = fs.readFileSync(path.join(dir, 'aluno.html'), 'utf8');
const php = fs.readFileSync(path.join(base, 'app', 'api', 'treinos.php'), 'utf8');
let falhas = 0, ok = 0;
function ver(nome, cond) { if (cond) { ok++; console.log('  OK    ' + nome); } else { falhas++; console.log('  FALHA ' + nome); } }

// 1) ordem no popstate
const iFim = app.indexOf("window._viewAtual === 'fim' && _ultimaSessaoFinalizada");
const iCompose = app.indexOf("document.getElementById('feed-compose-overlay')) {\n    _fecharComposeFeed();");
const iHist = app.indexOf("document.getElementById('ov-hist-ex')) {\n    fecharHistoricoExercicio();");
ver('a pergunta "Finalizou sem querer?" existe no gesto de voltar', iFim > 0);
ver('a foto/compartilhar fecha ANTES da pergunta', iCompose > 0 && iFim > 0 && iCompose < iFim);
ver('a folha do histórico fecha ANTES da pergunta', iHist > 0 && iFim > 0 && iHist < iFim);

// 2) limite de 5 min sobre o total, no app
const mFin = app.match(/function finalizarTreino\(\) \{[\s\S]*?\n\}\n/);
ver('finalizarTreino soma a duração já salva antes de falar em "treino muito rápido"', !!mFin && /_continuarDuracaoAnterior[\s\S]*\+ duracao < 300/.test(mFin[0]));
ver('"não conta" só é gravado quando a duração TOTAL é curta', /nao_contar: \(naoContar && duracaoTotal < 300\) \? 1 : 0/.test(app));

// 3) servidor: POST e PUT de musculação
const guarda = (php.match(/if \(\$naoContar && \(int\)\(\$b\['duracao_seg'\] \?\? 0\) >= 300\) \$naoContar = 0;/g) || []).length;
ver('o servidor ignora "não conta" em treino de 5 min ou mais (criar e atualizar)', guarda === 2);

console.log('\n' + (falhas ? 'FALHOU: ' + falhas : 'Tudo certo: ' + ok + ' conferências.'));
process.exit(falhas ? 1 : 0);
