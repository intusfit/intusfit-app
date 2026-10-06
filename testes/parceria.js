// Regras do treino em parceria (API.parceria): sequência da dupla e treinos no mesmo dia.
// "Hoje" é segunda-feira 2026-10-05; a semana vai de domingo 10-04 a sábado 10-10.
const { carregar } = require('./_api.js');
const P = carregar({ hoje: '2026-10-05' }).parceria;
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
const s = (d, extra) => Object.assign({ dtsessao: d + ' 07:00:00' }, extra || {});
const HOJE = '2026-10-05';

console.log('\n── Sequência em dupla ──');
{
  // Os dois treinaram nas semanas de 09-20, 09-27 e na atual (10-04): 3 semanas seguidas.
  const a = [s('2026-09-21'), s('2026-09-29'), s('2026-10-05')];
  const b = [s('2026-09-22'), s('2026-09-30'), s('2026-10-04')];
  eq('3 semanas seguidas, incluindo a atual', P.resumo(a, b, HOJE).semanasEmDupla, 3);
}
{
  // Semana atual ainda sem o parceiro: a sequência das anteriores continua valendo.
  const a = [s('2026-09-21'), s('2026-09-29'), s('2026-10-05')];
  const b = [s('2026-09-22'), s('2026-09-30')];
  eq('semana atual aberta não zera a sequência', P.resumo(a, b, HOJE).semanasEmDupla, 2);
}
{
  // Faltou uma semana de um dos dois: a sequência para ali.
  const a = [s('2026-09-14'), s('2026-09-21'), s('2026-09-29'), s('2026-10-05')];
  const b = [s('2026-09-15'), s('2026-09-30'), s('2026-10-05')];
  eq('semana em branco quebra a sequência (09-20 sem o parceiro)', P.resumo(a, b, HOJE).semanasEmDupla, 2);
}
eq('sem sessões', P.resumo([], [], HOJE).semanasEmDupla, 0);
eq('só um treinou', P.resumo([s('2026-10-05')], [], HOJE).semanasEmDupla, 0);

console.log('\n── Treinos no mesmo dia e último treino ──');
{
  const a = [s('2026-10-01'), s('2026-10-02'), s('2026-09-30'), s('2026-10-05')];
  const b = [s('2026-10-01'), s('2026-10-03'), s('2026-09-30'), s('2026-10-05')];
  const r = P.resumo(a, b, HOJE);
  eq('juntos no mês só conta o mês atual (01 e 05)', r.juntosNoMes, 2);
  eq('último treino do parceiro', r.ultimoParceiro, '2026-10-05');
  eq('parceiro treinou hoje', r.parceiroTreinouHoje, true);
  eq('eu treinei hoje', r.euTreineiHoje, true);
}
{
  const r = P.resumo([s('2026-10-02')], [s('2026-10-02', { nao_contar: true }), s('2026-10-03')], HOJE);
  eq('sessão marcada para não contar é ignorada', r.juntosNoMes, 0);
  eq('último treino ignora a não contada', r.ultimoParceiro, '2026-10-03');
  eq('parceiro não treinou hoje', r.parceiroTreinouHoje, false);
}
eq('sem treino do parceiro, último é nulo', P.resumo([s('2026-10-02')], [], HOJE).ultimoParceiro, null);
eq('data com hora no formato do servidor', P.resumo([{ dtsessao: '2026-10-05 23:59:59' }], [{ dtsessao: '2026-10-05T00:01:00' }], HOJE).juntosNoMes, 1);

console.log('\n' + ok + ' ok, ' + ruim + ' com falha');
process.exit(ruim ? 1 : 0);
