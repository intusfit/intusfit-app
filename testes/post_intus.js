// Post da Intus: Top 5 da semana e Top 10 do mês (API.topDoPeriodo e API.periodosFechados).
// O número que aparece no post tem de ser o mesmo que o app usa para dizer "você ficou no Top 5" (aluno.html, _perfilRankingStats/_colocado),
// então o teste compara as duas contas sobre os mesmos dados, inclusive empates, bônus semanal e virada de mês.
const { carregar } = require('./_api.js');
const API = carregar({ hoje: '2026-11-03' });
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}
let seq = 1;
const mus = (id, dia, extra) => Object.assign({ idsessao: seq++, idatleta: id, dtsessao: dia + ' 07:00:00', tipo: 'musculacao', duracao_seg: 3600, pontos: 5, divisao: 'A' }, extra || {});
// o jeito do app: filtra por data, soma, ordena, posição densa, pega quem tem posição <= ate
function colocadosDoApp(sessoes, ini, fim, ate) {
  const lista = sessoes.filter(s => s.dtsessao.slice(0, 10) >= ini && s.dtsessao.slice(0, 10) <= fim);
  const ag = API.agregarPontosRank(lista);
  const rank = API.posicionarDenso(Object.values(ag).map(a => ({ id: a.id, pts: a.total })).sort((x, y) => y.pts - x.pts));
  return rank.filter(r => r.pos <= ate).map(r => [r.id, r.pos]);
}
const nomes = { 1: 'Ana', 2: 'Bia', 3: 'Caio', 4: 'Dani', 5: 'Edu', 6: 'Fê', 7: 'Gil' };

console.log('\n── Períodos encerrados (hoje = terça 03/11/2026) ──');
eq('última semana fechada é de domingo 25/10 a sábado 31/10', API.periodosFechados('semana', 1)[0], { ini: '2026-10-25', fim: '2026-10-31', rotulo: '25/10 a 31/10' });
eq('a semana em andamento (01/11 a 07/11) nunca é oferecida', API.periodosFechados('semana', 3).map(p => p.ini), ['2026-10-25', '2026-10-18', '2026-10-11']);
eq('último mês fechado é outubro de 2026', API.periodosFechados('mes', 1)[0], { ini: '2026-10-01', fim: '2026-10-31', rotulo: 'Outubro de 2026' });
eq('o mês em andamento (novembro) nunca é oferecido', API.periodosFechados('mes', 3).map(p => p.rotulo), ['Outubro de 2026', 'Setembro de 2026', 'Agosto de 2026']);
{
  const dom = carregar({ hoje: '2026-11-01' });   // domingo: a semana que acabou ontem já está fechada
  eq('no domingo, a semana que terminou ontem entra', dom.periodosFechados('semana', 1)[0].fim, '2026-10-31');
  const sab = carregar({ hoje: '2026-10-31' });   // sábado: ainda não fechou
  eq('no sábado, a semana do dia ainda não fechou', sab.periodosFechados('semana', 1)[0].fim, '2026-10-24');
  const jan = carregar({ hoje: '2026-01-10' });
  eq('virada de ano: o mês fechado é dezembro de 2025', jan.periodosFechados('mes', 1)[0], { ini: '2025-12-01', fim: '2025-12-31', rotulo: 'Dezembro de 2025' });
}

console.log('\n── Top 5 da semana ──');
{
  // Semana 25/10 (dom) a 31/10 (sáb). Ana treina 4 dias (40 + 5 de bônus = 45), Bia 4 dias (40+5 = 45)...
  const S = [];
  ['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29'].forEach(d => S.push(mus(1, d)));      // Ana: 4 dias = 45
  ['2026-10-26', '2026-10-27', '2026-10-28'].forEach(d => S.push(mus(2, d)));                    // Bia: 3 dias = 30
  ['2026-10-26', '2026-10-27'].forEach(d => S.push(mus(3, d)));                                  // Caio: 2 dias = 20
  S.push(mus(4, '2026-10-26'), mus(4, '2026-10-27'));                                            // Dani: 20 (empata com Caio)
  S.push(mus(5, '2026-10-26'));                                                                  // Edu: 10
  S.push(mus(6, '2026-10-26', { duracao_seg: 600 }));                                            // Fê: treino curto = 5
  S.push(mus(7, '2026-10-24'), mus(7, '2026-11-01'));                                            // Gil: só fora da semana
  const top = API.topDoPeriodo(S, '2026-10-25', '2026-10-31', 5, nomes);
  eq('ordem, pontos e posição (empate de Caio e Dani divide o 3º)', top.map(t => [t.nome, t.pts, t.pos]), [['Ana', 45, 1], ['Bia', 30, 2], ['Caio', 20, 3], ['Dani', 20, 3], ['Edu', 10, 4], ['Fê', 5, 5]]);
  eq('Gil, que treinou só antes e depois da semana, não aparece', top.some(t => t.id === 7), false);
  eq('bônus de 4 dias entra (Ana 45, não 40)', top[0].pts, 45);
  eq('igual ao jeito do app', top.map(t => [t.id, t.pos]), colocadosDoApp(S, '2026-10-25', '2026-10-31', 5));
}
{
  // Empate no 5º lugar: os dois entram (o Top 5 vira 6 nomes), como no app.
  const S = [];
  [1, 2, 3, 4].forEach((id, i) => { for (let k = 0; k <= 3 - i; k++) S.push(mus(id, '2026-10-' + String(26 + k))); });   // 40+5, 30, 20, 10
  S.push(mus(5, '2026-10-26'), mus(6, '2026-10-27'));                                                                   // 10 e 10: empatam com o 4º
  const top = API.topDoPeriodo(S, '2026-10-25', '2026-10-31', 5, nomes);
  eq('quem divide o último lugar entra junto', top.map(t => [t.id, t.pos]), [[1, 1], [2, 2], [3, 3], [4, 4], [5, 4], [6, 4]]);
}
{
  const S = [mus(1, '2026-10-26'), mus(2, '2026-10-26', { nao_contar: 1 })];
  eq('sessão marcada "não contar" não pontua nem entra', API.topDoPeriodo(S, '2026-10-25', '2026-10-31', 5, nomes).map(t => t.id), [1]);
  eq('sem sessões, sem ranking', API.topDoPeriodo([], '2026-10-25', '2026-10-31', 5, nomes), []);
  eq('lista inválida não quebra', API.topDoPeriodo(null, '2026-10-25', '2026-10-31', 5, nomes), []);
}

console.log('\n── Top 10 do mês ──');
{
  // Outubro de 2026: a semana de 25/10 a 31/10 está inteira dentro; a de 27/09 a 03/10 é cortada no dia 1º (só 01 a 03 contam).
  const S = [];
  for (let id = 1; id <= 12; id++) for (let k = 0; k < 13 - id; k++) S.push(mus(id, '2026-10-' + String(5 + (k % 20)).padStart(2, '0')));
  S.push(mus(1, '2026-09-30'), mus(1, '2026-11-01'));
  const top = API.topDoPeriodo(S, '2026-10-01', '2026-10-31', 10, nomes);
  eq('10 colocados no mês', top.length >= 10 && top.every(t => t.pos <= 10), true);
  eq('setembro e novembro não entram na conta de outubro', colocadosDoApp(S, '2026-10-01', '2026-10-31', 10), top.map(t => [t.id, t.pos]));
  const comFora = API.topDoPeriodo(S.filter(s => !/2026-(09|11)/.test(s.dtsessao)), '2026-10-01', '2026-10-31', 10, nomes);
  eq('tirar as sessões de fora do mês não muda nada', comFora, top);
}

console.log('\n── Concordância com o app, dados aleatórios (semente fixa) ──');
{
  let x = 12345; const rnd = () => (x = (x * 1103515245 + 12345) & 0x7fffffff) / 0x7fffffff;
  let divergiu = 0;
  for (let rodada = 0; rodada < 60; rodada++) {
    const S = [];
    const atletas = 4 + Math.floor(rnd() * 12);
    for (let id = 1; id <= atletas; id++) {
      const n = Math.floor(rnd() * 14);
      for (let k = 0; k < n; k++) {
        const dia = 1 + Math.floor(rnd() * 30);
        const cardio = rnd() < 0.3;
        S.push(cardio
          ? { idsessao: seq++, idatleta: id, dtsessao: '2026-10-' + String(dia).padStart(2, '0') + ' 18:00:00', tipo: 'cardio', duracao_seg: 1800, pontos: 4 + Math.floor(rnd() * 8), cardio_tipo: 'Corrida' }
          : mus(id, '2026-10-' + String(dia).padStart(2, '0'), { duracao_seg: rnd() < 0.2 ? 900 : 3600 }));
      }
    }
    for (const [ini, fim, n] of [['2026-10-25', '2026-10-31', 5], ['2026-10-01', '2026-10-31', 10], ['2026-10-18', '2026-10-24', 5]]) {
      const a = API.topDoPeriodo(S, ini, fim, n, nomes).map(t => [t.id, t.pos]);
      const b = colocadosDoApp(S, ini, fim, n).filter(([id]) => API.topDoPeriodo(S, ini, fim, 99, nomes).some(t => t.id === id));
      if (JSON.stringify(a) !== JSON.stringify(b)) divergiu++;
    }
  }
  eq('180 conferências (60 rodadas x 3 períodos): nenhuma diferença do jeito do app', divergiu, 0);
}

console.log('\n── Pendências no painel inicial (API.postsIntus.pendentes) ──');
{
  const sem = { tipo: 'ranking_semana', dados: { periodo: { ini: '2026-10-25' } } };
  const mes = { tipo: 'ranking_mes', dados: { periodo: { ini: '2026-10-01' } } };
  const pend = (posts, hoje) => (hoje ? carregar({ hoje }) : API).postsIntus.pendentes(posts).map(p => p.tipo + ':' + p.periodo.ini);
  eq('sem nenhum post: faltam a última semana e o último mês', pend([]), ['semana:2026-10-25', 'mes:2026-10-01']);
  eq('semana publicada: falta só o mês', pend([sem]), ['mes:2026-10-01']);
  eq('mês publicado: falta só a semana', pend([mes]), ['semana:2026-10-25']);
  eq('os dois publicados: nada em destaque', pend([sem, mes]), []);
  eq('post oculto (ativo 0) conta como publicado', pend([Object.assign({ ativo: 0 }, sem), mes]), []);
  eq('post de outra semana não vale para a última', pend([{ tipo: 'ranking_semana', dados: { periodo: { ini: '2026-10-18' } } }, mes]), ['semana:2026-10-25']);
  eq('Top 5 da semana não vale como Top 10 do mês', pend([{ tipo: 'ranking_mes', dados: { periodo: { ini: '2026-10-25' } } }, sem]), ['mes:2026-10-01']);
  eq('campanha e aviso não contam', pend([{ tipo: 'campanha', dados: {} }, { tipo: 'aviso' }]), ['semana:2026-10-25', 'mes:2026-10-01']);
  eq('lista inválida não quebra', pend(null), ['semana:2026-10-25', 'mes:2026-10-01']);
  eq('domingo 08/11: a semana nova (01 a 07/11) passa a faltar', pend([sem, mes], '2026-11-08'), ['semana:2026-11-01']);
  eq('1º de dezembro: o mês de novembro passa a faltar', pend([sem, mes], '2026-12-01').filter(x => x.startsWith('mes')), ['mes:2026-11-01']);
  eq('tamanhos: Top 5 e Top 10', API.postsIntus.pendentes([]).map(p => p.n), [5, 10]);
}

console.log('\n' + (ruim ? ruim + ' FALHA(S), ' + ok + ' ok.' : 'Tudo certo: ' + ok + ' conferências.'));
process.exit(ruim ? 1 : 0);
