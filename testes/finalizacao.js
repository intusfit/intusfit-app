// Finalizar treino: duas falhas achadas no caso do Eduardo (06/11/2026), em que um treino de 44 min ficou sem pontos.
//  1) O gesto de voltar, com a foto do "Compartilhar treino" aberta, mostrava "Finalizou o treino sem querer?" por cima da foto.
//     Quem tocava em "Voltar e continuar" reabria o treino. As telas abertas por cima do fim têm de fechar ANTES da pergunta.
//  2) Treino reaberto e finalizado de novo em poucos minutos: o limite de 5 min era conferido só no trecho novo, e "Finalizar mesmo
//     assim" regravava o treino inteiro como "não conta". O limite vale para a duração TOTAL, no app e no servidor.
// Confere o código-fonte (não abre navegador): quebrar qualquer das travas de propósito faz o teste acusar.
const fs = require('fs'), path = require('path');
const dir = process.env.INTUS_DIR || path.join(__dirname, '..', 'app', 'painel');
const app = fs.readFileSync(path.join(dir, 'aluno.html'), 'utf8');
const php = fs.readFileSync(path.join(dir, '..', 'api', 'treinos.php'), 'utf8');
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

// 4) tela de fim avisa quando o treino não vale pontos (não conta, ou 3º do dia) e oferece continuar o treino
const mRf = app.match(/function renderFim\(duracao, sessaoSalva\) \{[\s\S]*?\n\}\n/);
ver('a tela de fim tem o aviso "não vale pontos" (nao_contar) com botão de continuar o treino', !!mRf && /Number\(s\.nao_contar\) === 1[\s\S]*?_voltarParaContinuarTreino\(\)/.test(mRf[0]));
ver('a tela de fim avisa o 3º treino do dia pela regra central (pontosSessaoRank e ordemMusculacaoNoDia)', !!mRf && /API\.pontosSessaoRank\(s, lista\) === 0[\s\S]*?API\.ordemMusculacaoNoDia\(s, lista\) >= 3/.test(mRf[0]));
ver('o aviso é mostrado na tela (${avisoSemPontos})', !!mRf && mRf[0].includes('${avisoSemPontos}'));

// 5) Frequência: médias de musculação e de cardio separadas
const freq = fs.readFileSync(path.join(dir, 'frequencia.html'), 'utf8');
ver('Frequência separa a média de musculação da de cardio', /_sessMus = sessoesAtleta\.filter\(s => !_ehCardioS\(s\) && !s\.nao_contar\)/.test(freq) && /_sessCar = sessoesAtleta\.filter\(s => _ehCardioS\(s\) && !s\.nao_contar\)/.test(freq));

console.log('\n' + (falhas ? 'FALHOU: ' + falhas : 'Tudo certo: ' + ok + ' conferências.'));
process.exit(falhas ? 1 : 0);
