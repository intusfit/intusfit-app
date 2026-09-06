// O pedido do Luiz foi "válido e sincronizado em todas as frentes". Este teste
// não olha se cada tela acerta sozinha: joga o MESMO conjunto de sessões nas
// três telas e cobra que os números saiam idênticos. Se alguém voltar a
// escrever a fórmula localmente, é aqui que estoura.
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/';
const TELAS = [
  { arq: 'aluno.html', papel: 'aluno', marco: 'pontosSessaoRank' },
  { arq: 'frequencia.html', papel: 'admin', marco: 'pontosSessaoRank' },
  { arq: 'notificacoes.html', papel: 'admin', marco: 'carregarRankingRegras' },
];

// Semana de 30/08 (dom) a 05/09 (sáb). Casos de propósito misturados.
const SESSOES = [
  { idsessao: 1, idatleta: 7, tipo: 'musculacao', dtsessao: '2026-08-30', duracao_seg: 3600 },
  { idsessao: 2, idatleta: 7, tipo: 'musculacao', dtsessao: '2026-08-30', duracao_seg: 2400 },
  { idsessao: 3, idatleta: 7, tipo: 'musculacao', dtsessao: '2026-08-30', duracao_seg: 1800 },
  { idsessao: 4, idatleta: 7, tipo: 'musculacao', dtsessao: '2026-08-31', duracao_seg: 900  },
  { idsessao: 5, idatleta: 7, tipo: 'cardio',     dtsessao: '2026-08-31', duracao_seg: 2100, pontos: 11.7 },
  { idsessao: 6, idatleta: 7, tipo: 'musculacao', dtsessao: '2026-09-01', duracao_seg: 3600, nao_contar: 1 },
  { idsessao: 7, idatleta: 7, tipo: 'musculacao', dtsessao: '2026-09-01', duracao_seg: 3600 },
  { idsessao: 8, idatleta: 9, tipo: 'musculacao', dtsessao: '2026-08-30', duracao_seg: 3600 },
  { idsessao: 9, idatleta: 9, tipo: 'musculacao', dtsessao: '2026-09-02', duracao_seg: 3600 },
];

(async () => {
  const browser = await chromium.launch();
  const resultados = {};
  for (const t of TELAS) {
    const page = await browser.newPage();
    await page.addInitScript((papel) => {
      localStorage.setItem('mx-token', 'teste');
      localStorage.setItem('mx-user', JSON.stringify(papel === 'aluno'
        ? { tipo: 'aluno', idatleta: 7, nome: 'Aluno Teste' }
        : { tipo: 'admin', idusuario: 1, admin: 1, nome: 'Luiz Nunes', permissoes: {} }));
    }, t.papel);
    await page.goto(BASE + t.arq, { waitUntil: 'domcontentloaded' });
    let pronto = false;
    for (let i = 0; i < 60 && !pronto; i++) {
      pronto = await page.evaluate((m) => typeof window.API !== 'undefined' &&
        typeof window.API.pontosSessaoRank === 'function' && typeof window[m] === 'function', t.marco).catch(() => false);
      if (!pronto) await page.waitForTimeout(250);
    }
    if (!pronto) throw new Error(t.arq + ' não carregou');
    resultados[t.arq] = await page.evaluate((s) => {
      const ag = API.agregarPontosRank(s);
      return {
        porSessao: s.map(x => API.pontosSessaoRank(x, s)),
        atleta7: { base: ag[7].base, bonus: ag[7].bonus, total: ag[7].total },
        atleta9: { base: ag[9].base, bonus: ag[9].bonus, total: ag[9].total },
        dias7: API.diasComMusculacaoRank(s.filter(x => x.idatleta === 7)).length,
      };
    }, SESSOES);
    await page.close();
  }
  await browser.close();

  const ref = JSON.stringify(resultados['aluno.html']);
  let ruim = 0;
  console.log('Sessões da semana do atleta 7:');
  console.log('  30/08  treino 60min .......... ' + resultados['aluno.html'].porSessao[0] + ' pts   (1º do dia)');
  console.log('  30/08  treino 40min .......... ' + resultados['aluno.html'].porSessao[1] + ' pts   (2º do dia)');
  console.log('  30/08  treino 30min .......... ' + resultados['aluno.html'].porSessao[2] + ' pts   (3º do dia)');
  console.log('  31/08  treino 15min .......... ' + resultados['aluno.html'].porSessao[3] + ' pts   (curto, 1º do dia)');
  console.log('  31/08  cardio ................ ' + resultados['aluno.html'].porSessao[4] + ' pts   (fator + teto)');
  console.log('  01/09  treino marcado teste .. ' + resultados['aluno.html'].porSessao[5] + ' pts   (não conta)');
  console.log('  01/09  treino 60min .......... ' + resultados['aluno.html'].porSessao[6] + ' pts   (1º do dia que conta)');
  console.log('  dias com musculação na semana: ' + resultados['aluno.html'].dias7 + '  (meta 4 → sem bônus)');
  console.log('  TOTAL do atleta 7: base ' + resultados['aluno.html'].atleta7.base +
              ' + bônus ' + resultados['aluno.html'].atleta7.bonus +
              ' = ' + resultados['aluno.html'].atleta7.total);
  console.log('');
  TELAS.forEach(t => {
    const igual = JSON.stringify(resultados[t.arq]) === ref;
    console.log((igual ? '  OK    ' : '  FALHA ') + t.arq + (igual ? ' concorda' : ' DISCORDA das outras'));
    if (!igual) { ruim++; console.log('        ' + JSON.stringify(resultados[t.arq])); }
  });
  console.log('\n' + (ruim === 0 ? 'AS TRÊS TELAS FALAM A MESMA LÍNGUA.' : ruim + ' tela(s) discordando.'));
  process.exit(ruim === 0 ? 0 : 1);
})();
