// Abre as telas de verdade num Chromium e cobra a pontuação DENTRO da página.
// Sintaxe válida não prova nada: já houve caso de 46 funções apagadas com o
// arquivo continuando "válido". Só o navegador pega isso.
const { chromium } = require('playwright');
const DIR = 'http://127.0.0.1:8099/';

let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}

(async () => {
  const browser = await chromium.launch();
  // marco = símbolo que só existe depois que o bloco de script grande da tela
  // terminou de executar. Esperar só pelo api.js não basta: ele é externo e
  // chega antes do inline de 650 KB do app do aluno.
  const TELAS = [
    { arq: 'aluno.html',        marco: 'pontosSessaoRank', temRankReg: true  },
    { arq: 'frequencia.html',   marco: 'pontosSessaoRank', temRankReg: true  },
    { arq: 'notificacoes.html', marco: 'carregarRankingRegras', temRankReg: false },
  ];
  for (const { arq: tela, marco, temRankReg } of TELAS) {
    console.log('\n══ ' + tela + ' ══');
    const page = await browser.newPage();
    // As telas chutam para o login sem sessao. Semeamos uma sessao de mentira
    // so para a pagina desenhar — o teste e sobre a pontuacao, nao sobre login.
    // O papel vem do NOME DA TELA, nunca do pathname atual: o init script roda
    // de novo a cada navegacao, e ao passar pelo login ele re-semeava como
    // admin, o que mandava a tela do aluno para o painel.
    await page.addInitScript((papel) => {
      localStorage.setItem('mx-token', 'teste');
      localStorage.setItem('mx-user', JSON.stringify(papel === 'aluno'
        ? { tipo: 'aluno', idatleta: 7, nome: 'Aluno Teste' }
        : { tipo: 'admin', idusuario: 1, admin: 1, nome: 'Luiz Nunes', permissoes: {} }));
    }, tela === 'aluno.html' ? 'aluno' : 'admin');
    const erros = [];
    page.on('pageerror', e => erros.push(String(e.message)));
    page.on('console', m => { if (m.type() === 'error') erros.push('console: ' + m.text()); });
    await page.goto(DIR + tela, { waitUntil: 'domcontentloaded' });
    // A tela do aluno tem 650 KB de script: esperar tempo fixo dava falso
    // negativo. Espera-se o sinal de que a pontuacao ja esta montada.
    // Espera ativa do lado do Node: o waitForFunction do Playwright fica
    // intermitente enquanto o bloco de 650 KB da tela do aluno executa.
    let pronto = false;
    for (let tentativa = 0; tentativa < 60 && !pronto; tentativa++) {
      pronto = await page.evaluate((m) =>
        typeof window.API !== 'undefined' &&
        typeof window.API.pontosSessaoRank === 'function' &&
        typeof window[m] === 'function', marco).catch((e) => { if (tentativa === 0 || tentativa === 59) console.log('    [poll] ' + String(e.message).split('\n')[0]); return false; });
      if (!pronto && tentativa === 59) {
        const diag = await page.evaluate(() => ({ url: location.href, API: typeof window.API, chaves: Object.keys(window).filter(k => /pontos|rank/i.test(k)).slice(0, 10) })).catch(e => String(e.message));
        console.log('    [diag] ' + JSON.stringify(diag));
      }
      if (!pronto) await page.waitForTimeout(250);
    }
    if (!pronto) throw new Error(tela + ' nao terminou de carregar (marco "' + marco + '" nunca apareceu em 15s)');
    eq(tela + ': continuou na tela certa (nao caiu no login)', new URL(page.url()).pathname.replace('/', ''), tela);
    const r = await page.evaluate(() => {
      const out = { temAPI: typeof API !== 'undefined' };
      if (!out.temAPI) return out;
      let id = 900;
      const mus = (d, min) => ({ idsessao: id++, idatleta: 7, tipo: 'musculacao', dtsessao: d, duracao_seg: (min == null ? 60 : min) * 60 });
      const l = [mus('2026-09-01'), mus('2026-09-01'), mus('2026-09-01')];
      out.viaAPI = [API.pontosSessaoRank(l[0], l), API.pontosSessaoRank(l[1], l), API.pontosSessaoRank(l[2], l)];
      out.temWrapper = typeof pontosSessaoRank === 'function';
      if (out.temWrapper) out.viaTela = [pontosSessaoRank(l[0], l), pontosSessaoRank(l[1], l), pontosSessaoRank(l[2], l)];
      // o Proxy de regras responde?
      try {
        out.regras = { musBase: _rankReg.musBase, musSegundo: _rankReg.musSegundo, musTerceiro: _rankReg.musTerceiro, bonusDias: _rankReg.bonusDias };
      } catch (e) { out.regras = 'sem _rankReg: ' + e.message; }
      // bônus por dias
      const semana = ['2026-08-30','2026-08-31','2026-09-01','2026-09-02'].map(d => mus(d));
      const dobrado = [mus('2026-08-31'), mus('2026-08-31'), mus('2026-09-01'), mus('2026-09-01')];
      const _temAgr = (function(){ try { return typeof _agregarPontosPorAtleta === 'function'; } catch(e){ return false; } })();
      if (_temAgr) {
        out.bonus4dias = _agregarPontosPorAtleta(semana)[7].bonus;
        out.bonus2dias = _agregarPontosPorAtleta(dobrado)[7].bonus;
        out.base2dias = _agregarPontosPorAtleta(dobrado)[7].base;
      }
      out.dias = API.diasComMusculacaoRank(dobrado).length;
      try {
        out.padraoDaTela = { musSegundo: RANK_REGRAS_PADRAO.musSegundo, musTerceiro: RANK_REGRAS_PADRAO.musTerceiro, bonusDias: RANK_REGRAS_PADRAO.bonusDias };
      } catch (e) { out.padraoDaTela = 'sem RANK_REGRAS_PADRAO'; }
      return out;
    });

    eq(tela + ': api.js carregou', r.temAPI, true);
    eq(tela + ': 1º/2º/3º treino do dia', r.viaAPI, [10, 5, 0]);
    if (r.temWrapper) eq(tela + ': a tela dá a MESMA resposta que o api.js', r.viaTela, r.viaAPI);
    if (temRankReg) eq(tela + ': regras chegam pelo Proxy', r.regras, { musBase: 10, musSegundo: 5, musTerceiro: 0, bonusDias: 4 });
    else eq(tela + ': padrão vem do api.js', r.padraoDaTela, { musSegundo: 5, musTerceiro: 0, bonusDias: 4 });
    eq(tela + ': 2 dias com treino duplo = 1 dia contado por dia', r.dias, 2);
    if (r.bonus4dias !== undefined) {
      eq(tela + ': bônus com 4 dias distintos', r.bonus4dias, 5);
      eq(tela + ': SEM bônus com 4 sessões em 2 dias', r.bonus2dias, 0);
      eq(tela + ': base de 4 sessões em 2 dias = 30', r.base2dias, 30);
    }
    const graves = erros.filter(e => !/Failed to load|net::|fetch|NetworkError|sem rede|Load failed|404/i.test(e));
    eq(tela + ': sem erro de JavaScript na página', graves, []);
    await page.close();
  }
  await browser.close();
  console.log('\n' + (ruim === 0 ? 'TODOS OS ' + ok + ' TESTES DE NAVEGADOR PASSARAM' : ruim + ' FALHA(S)'));
  process.exit(ruim === 0 ? 0 : 1);
})();
