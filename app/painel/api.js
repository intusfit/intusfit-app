// ── API CLIENT ────────────────────────────────────────────────────────────
// Camada de comunicação com o backend PHP + FALLBACK LOCAL (localStorage).
// Se o backend estiver indisponível, os dados são lidos/gravados no Store
// local — assim alterações em uma página são vistas imediatamente nas outras.
// Caminho RELATIVO de proposito. Antes isto era 'https://intusfit.com.br/app/api'
// fixo — e quem abrisse o site como www.intusfit.com.br virava outra origem: o
// navegador bloqueava a chamada por CORS (o servidor so libera a origem sem www)
// e o fetch estourava. O app interpretava isso como "sem internet" e caia no modo
// local, criando conta fantasma. Relativo, a chamada usa sempre a mesma origem da
// pagina — com ou sem www, e em qualquer dominio futuro.
// No PWA, a API continua relativa à origem atual. O bundle nativo injeta
// INTUS_API_BASE antes deste arquivo para falar com o mesmo back-end HTTPS.
const API_BASE = window.INTUS_API_BASE || '/app/api';
const _APP_VERSION = '20260521p';

// ── SESSAO EXPIRADA: 401 NUNCA PODE VIRAR TELA EM BRANCO ────────────────────
// Em 14/08/2026 o formato antigo de token foi desligado no servidor. Todas as
// chamadas passaram a responder 401 — e nenhuma tela sabia o que fazer com
// isso. O aluno.html engolia o erro num "catch {}" vazio e desenhava a tela
// vazia: o ranking apareceu em branco, sem uma linha de explicacao. Ninguem
// tinha como adivinhar que era so questao de entrar de novo.
// 401 quer dizer uma coisa so: esta sessao nao vale mais. A resposta certa e
// avisar e mandar para o login. O bloco abaixo troca o window.fetch da pagina
// por uma versao que enxerga o 401 venha ele de onde vier — inclusive das
// dezenas de fetch soltos que nao passam pelo apiFetch.
window.sessaoExpirou = function (motivo) {
  try {
    if (window._intusSessaoAvisada) return;
    if (/login\.html/i.test(location.pathname || '')) return;
    window._intusSessaoAvisada = true;
    try { localStorage.removeItem('mx-token'); localStorage.removeItem('mx-user'); } catch (e) {}

    var d = document.createElement('div');
    d.setAttribute('data-intus', 'sessao-expirada');
    d.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:rgba(8,10,14,.94);' +
      'display:flex;align-items:center;justify-content:center;padding:24px;' +
      'font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;';
    d.innerHTML =
      '<div style="max-width:340px;text-align:center;color:#fff;">' +
        '<div style="font-size:34px;margin-bottom:10px;">\uD83D\uDD11</div>' +
        '<div style="font-size:17px;font-weight:800;margin-bottom:8px;">Sua sess\u00e3o expirou</div>' +
        '<div style="font-size:13.5px;line-height:1.55;color:#c9cdd4;margin-bottom:18px;">' +
          'Nada foi perdido. \u00c9 s\u00f3 entrar de novo com seu c\u00f3digo e senha de sempre.' +
        '</div>' +
        '<a href="login.html" style="display:inline-block;background:#7FFF00;color:#111;font-weight:800;' +
          'text-decoration:none;padding:11px 22px;border-radius:10px;font-size:14px;">Entrar de novo</a>' +
      '</div>';
    if (document.body) document.body.appendChild(d);
    setTimeout(function () { location.href = 'login.html'; }, 4000);
  } catch (e) {
    try { location.href = 'login.html'; } catch (e2) {}
  }
};

(function _guardaDeSessao() {
  if (window._intusGuardaSessao) return;
  var original = (typeof window.fetch === 'function') ? window.fetch.bind(window) : null;
  if (!original) return;
  window._intusGuardaSessao = true;

  // Em rotas de login e recuperacao de senha, 401 significa "senha errada" —
  // nao "sessao expirada". Nunca redirecionar a partir delas.
  var ROTAS_DE_LOGIN = /(action=(validar|request_reset|verify_otp|reset_senha|send_reset_notification|check_email))|aluno_login\.php/i;

  function _url(entrada) {
    try {
      if (typeof entrada === 'string') return entrada;
      if (entrada && entrada.url) return String(entrada.url);
    } catch (e) {}
    return '';
  }
  function _temAutorizacao(entrada, opcoes) {
    try {
      var h = (opcoes && opcoes.headers) || (entrada && entrada.headers) || null;
      if (!h) return false;
      if (typeof h.get === 'function') return !!h.get('Authorization');
      for (var k in h) { if (String(k).toLowerCase() === 'authorization') return true; }
    } catch (e) {}
    return false;
  }

  window.fetch = function (entrada, opcoes) {
    return original(entrada, opcoes).then(function (resp) {
      try {
        if (resp && resp.status === 401) {
          var u = _url(entrada);
          if (u.indexOf('/app/api') !== -1 && !ROTAS_DE_LOGIN.test(u) && _temAutorizacao(entrada, opcoes)) {
            // Confere o motivo antes de derrubar a sessao. So um 401 que fala
            // de TOKEN significa login invalido; qualquer outro 401 e problema
            // daquela rota especifica e nao justifica expulsar o usuario. A
            // leitura e feita numa copia e sem segurar a resposta original.
            resp.clone().text().then(function (corpo) {
              if (/token/i.test(corpo || '')) window.sessaoExpirou('401 em ' + u);
            }).catch(function () {});
          }
        }
      } catch (e) {}
      return resp;
    });
  };
})();

// ── ESTADO DE TELA: CARREGANDO / ERRO / VAZIO DE VERDADE ────────────────────
// A doenca mais comum deste app: um "catch {}" vazio engole a falha de rede e a
// tela desenha o vazio como se fosse um fato. O aluno le "voce ainda nao
// preencheu a anamnese" quando na verdade a resposta nem chegou. O professor le
// "nenhuma sessao registrada" quando o servidor caiu. Sao tres situacoes
// diferentes e o app so sabia falar uma. IntusEstado da nome as tres.
//
//   var r = await IntusEstado.buscar(() => API.getAlgo());
//   if (!r.ok) { el.innerHTML = IntusEstado.erro(r.erro, 'carregar()'); return; }
//   if (!r.dados.length) { el.innerHTML = '<p>Nada por aqui ainda.</p>'; return; }
//
// Regra: so escreva "esta vazio" depois de um r.ok === true.
window.IntusEstado = {
  buscar: async function (fn) {
    try {
      var dados = await fn();
      return { ok: true, dados: dados, erro: null };
    } catch (e) {
      var msg = (e && e.message) ? String(e.message) : 'falha na conexao';
      try { console.warn('[IntusEstado] falha:', msg, e); } catch (_) {}
      return { ok: false, dados: null, erro: msg };
    }
  },

  // Marcador visual de carregamento. Recebe texto opcional.
  carregando: function (texto) {
    window.IntusEstado._css();
    var t = texto || 'Carregando…';
    return '<div class="intus-estado" data-estado="carregando">' +
             '<div class="intus-spinner" aria-hidden="true"></div>' +
             '<div class="intus-estado-txt">' + window.IntusEstado._esc(t) + '</div>' +
           '</div>';
  },

  // Mensagem honesta de falha + botao de tentar de novo.
  // aoTentar: string com o codigo a executar (ex.: 'carregar()') ou undefined.
  erro: function (texto, aoTentar) {
    window.IntusEstado._css();
    var t = texto || 'falha na conexao';
    var botao = aoTentar
      ? '<button type="button" class="intus-estado-btn" onclick="' +
          window.IntusEstado._esc(aoTentar) + '">Tentar de novo</button>'
      : '';
    return '<div class="intus-estado" data-estado="erro" role="alert">' +
             '<div class="intus-estado-icone" aria-hidden="true">⚠️</div>' +
             '<div class="intus-estado-txt"><strong>Não consegui carregar.</strong><br>' +
               '<span class="intus-estado-detalhe">' + window.IntusEstado._esc(t) + '</span></div>' +
             botao +
           '</div>';
  },

  // Vazio de verdade — so use depois de uma resposta bem-sucedida.
  vazio: function (texto) {
    window.IntusEstado._css();
    return '<div class="intus-estado" data-estado="vazio">' +
             '<div class="intus-estado-txt">' +
               window.IntusEstado._esc(texto || 'Nada por aqui ainda.') +
             '</div>' +
           '</div>';
  },

  _esc: function (s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  },

  _css: function () {
    if (window._intusEstadoCss) return;
    window._intusEstadoCss = true;
    try {
      var s = document.createElement('style');
      s.setAttribute('data-intus', 'estado');
      s.textContent =
        '.intus-estado{display:flex;flex-direction:column;align-items:center;justify-content:center;' +
        'gap:10px;padding:26px 18px;text-align:center;color:var(--txt-2,#9aa0a8);font-size:13.5px;' +
        'line-height:1.5;}' +
        '.intus-estado-icone{font-size:26px;line-height:1;}' +
        '.intus-estado-detalhe{opacity:.75;font-size:12.5px;word-break:break-word;}' +
        '.intus-estado-btn{margin-top:4px;background:#7FFF00;color:#111;border:0;border-radius:9px;' +
        'font-weight:800;font-size:13px;padding:9px 18px;cursor:pointer;min-height:40px;}' +
        '.intus-estado-btn:active{transform:scale(.97);}' +
        '.intus-spinner{width:26px;height:26px;border-radius:50%;border:3px solid rgba(127,255,0,.22);' +
        'border-top-color:#7FFF00;animation:intus-gira .8s linear infinite;}' +
        '@keyframes intus-gira{to{transform:rotate(360deg);}}' +
        '@media (prefers-reduced-motion:reduce){' +
          '.intus-spinner{animation:none;border-top-color:rgba(127,255,0,.55);}' +
        '}' +
        'body.theme-light .intus-estado,body.tema-claro .intus-estado{color:#5b626b;}' +
        'body.theme-light .intus-spinner,body.tema-claro .intus-spinner{border-color:rgba(26,120,60,.2);border-top-color:#1baf7a;}';
      (document.head || document.documentElement).appendChild(s);
    } catch (e) {}
  }
};

// ══════════════════════════════════════════════════════════════════════════
// EVOLUÇÃO — agregação e gráficos de progressão de treino
// ══════════════════════════════════════════════════════════════════════════
// Um lugar só para os três consumidores: a tela de histórico do aluno, a tela
// de histórico do professor (treinos.html) e o popup de histórico do exercício
// dentro do treino. Antes cada tela desenhava o seu próprio SVG à mão, e todas
// mostravam a mesma coisa pobre: a carga MÁXIMA da sessão, um ponto por dia.
// Isso esconde o que o treino realmente é — a 1ª série sobe, a 4ª cai, e a
// média conta uma história que o pico não conta.
//
// Fonte dos dados: as sessões gravadas em treinos.php?action=sessoes, no
// formato { dtsessao, divisao, itens:[{ idexercicio, nmexercicio,
// series:[{peso, reps}] }] }.
//
// Paleta: slots 1,2,3,4,5,7 da paleta categórica validada (validate_palette.js,
// modo claro sobre #fff e modo escuro sobre #111 — os dois passam em faixa de
// luminosidade, croma, separação para daltonismo e piso de visão normal). No
// tema claro três slots ficam abaixo de 3:1 de contraste, então a regra de
// alívio se aplica: todo gráfico traz rótulo direto no último ponto E uma
// tabela com os números — nada depende só da cor.
window.IntusEvolucao = (function () {

  var PALETA_CLARA  = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#4a3aa7'];
  var PALETA_ESCURA = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#9085e9'];

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function num(v) {
    var n = parseFloat(String(v == null ? '' : v).replace(',', '.'));
    return isFinite(n) ? n : 0;
  }
  function fmt(v, casas) {
    if (v == null || !isFinite(v)) return '—';
    var c = (casas == null) ? 1 : casas;
    var s = Number(v).toFixed(c);
    if (c > 0) s = s.replace(/\.?0+$/, '');
    return s.replace('.', ',');
  }
  function dtBR(ymd) {
    var s = String(ymd || '').slice(0, 10);
    if (s.length !== 10) return s;
    return s.slice(8, 10) + '/' + s.slice(5, 7);
  }
  function dtBRCompleta(ymd) {
    var s = String(ymd || '').slice(0, 10);
    if (s.length !== 10) return s;
    return s.slice(8, 10) + '/' + s.slice(5, 7) + '/' + s.slice(0, 4);
  }
  var MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

  // ── AGREGAÇÃO ───────────────────────────────────────────────────────────

  // Sessões válidas do atleta, da mais antiga para a mais nova (o gráfico lê
  // da esquerda para a direita; a lista da tela lê ao contrário).
  function _ordenadas(sessoes) {
    return (sessoes || [])
      .filter(function (s) { return s && s.dtsessao && !s.nao_contar; })
      .slice()
      .sort(function (a, b) { return String(a.dtsessao).localeCompare(String(b.dtsessao)); });
  }

  // Todos os exercícios que o atleta já registrou com carga, do mais recente
  // para o menos recente, com quantas sessões cada um tem.
  function exercicios(sessoes) {
    var mapa = {};
    _ordenadas(sessoes).forEach(function (s) {
      (s.itens || []).forEach(function (it) {
        var ser = (it.series || []).filter(function (x) { return num(x.peso) > 0 || num(x.reps) > 0; });
        if (!ser.length) return;
        var id = String(it.idexercicio == null ? it.nmexercicio : it.idexercicio);
        if (!mapa[id]) mapa[id] = { id: id, idexercicio: it.idexercicio, nome: it.nmexercicio || 'Exercício', sessoes: 0, ultima: '' };
        mapa[id].sessoes++;
        if (String(s.dtsessao) > mapa[id].ultima) mapa[id].ultima = String(s.dtsessao).slice(0, 10);
        if (it.nmexercicio) mapa[id].nome = it.nmexercicio;
      });
    });
    return Object.keys(mapa).map(function (k) { return mapa[k]; })
      .sort(function (a, b) { return String(b.ultima).localeCompare(String(a.ultima)); });
  }

  // Histórico de UM exercício: um ponto por sessão, com a carga de cada série
  // preservada individualmente (é isso que faltava em todos os gráficos).
  // ── SERIE COM TECNICA NAO ENTRA EM CONTA NENHUMA ─────────────────────────
  // Drop set, rest-pause, cluster e back-off terminam com uma carga que nao
  // representa o esforco da serie: no drop set o aluno anota a ULTIMA queda, no
  // back-off a carga e deliberadamente menor que a de trabalho. Somar isso na
  // media, no volume ou na maxima faz o grafico desenhar uma queda que nao
  // existiu, e o aluno le como perda de forca o que foi intensidade a mais.
  // A carga continua GRAVADA e continua aparecendo na lista serie a serie.
  // Ela so nao vira estatistica.
  function _contaNoCalculo(x) { return !x || !x.tec; }

  function historicoExercicio(sessoes, idexercicio) {
    var alvo = String(idexercicio);
    var pontos = [];
    _ordenadas(sessoes).forEach(function (s) {
      (s.itens || []).forEach(function (it) {
        var id = String(it.idexercicio == null ? it.nmexercicio : it.idexercicio);
        if (id !== alvo) return;
        var ser = (it.series || [])
          .map(function (x) { return { peso: num(x.peso), reps: num(x.reps), tec: x.tec || null }; })
          .filter(function (x) { return x.peso > 0 || x.reps > 0; });
        if (!ser.length) return;
        var conta   = ser.filter(_contaNoCalculo);
        var comCarga = conta.filter(function (x) { return x.peso > 0; });
        var pesos = comCarga.map(function (x) { return x.peso; });
        var volume = conta.reduce(function (t, x) { return t + x.peso * x.reps; }, 0);
        pontos.push({
          dt: String(s.dtsessao).slice(0, 10),
          divisao: s.divisao || '',
          nome: it.nmexercicio || 'Exercício',
          series: ser,
          // Quantas series ficaram de fora da conta, para a tela poder dizer
          // por que o numero nao bate com a lista logo abaixo dele.
          seriesTecnica: ser.length - conta.length,
          max: pesos.length ? Math.max.apply(null, pesos) : null,
          media: pesos.length ? (pesos.reduce(function (a, b) { return a + b; }, 0) / pesos.length) : null,
          repsTotal: conta.reduce(function (t, x) { return t + x.reps; }, 0),
          volume: volume > 0 ? volume : null,
        });
      });
    });
    return pontos;
  }

  // Volume total (kg × reps) por sessão, separado por divisão do treino.
  function porDivisao(sessoes) {
    var por = {};
    _ordenadas(sessoes).forEach(function (s) {
      var d = String(s.divisao || '—');
      var vol = 0;
      (s.itens || []).forEach(function (it) {
        (it.series || []).forEach(function (x) {
          if (!_contaNoCalculo(x)) return;   // serie de tecnica fica fora do volume
          vol += num(x.peso) * num(x.reps);
        });
      });
      if (vol <= 0) return;
      if (!por[d]) por[d] = [];
      por[d].push({ dt: String(s.dtsessao).slice(0, 10), valor: vol });
    });
    return por;
  }

  // Agrupa pontos por sessão (sem agrupar), mês ou ano. Ao agrupar, cada
  // medida vira a MÉDIA do período — somar cargas de meses diferentes não quer
  // dizer nada; a média diz.
  function agrupar(pontos, escala) {
    if (escala !== 'mes' && escala !== 'ano') {
      return pontos.map(function (p) { return Object.assign({}, p, { rotulo: dtBR(p.dt), detalhe: dtBRCompleta(p.dt) + (p.divisao ? ' · divisão ' + p.divisao : '') }); });
    }
    var chaves = [], mapa = {};
    pontos.forEach(function (p) {
      var k, rot, det;
      if (escala === 'ano') { k = p.dt.slice(0, 4); rot = k; det = 'Ano de ' + k; }
      else {
        k = p.dt.slice(0, 7);
        rot = MESES[parseInt(k.slice(5, 7), 10) - 1] + '/' + k.slice(2, 4);
        det = MESES[parseInt(k.slice(5, 7), 10) - 1] + ' de ' + k.slice(0, 4);
      }
      if (!mapa[k]) { mapa[k] = { rotulo: rot, detalhe: det, itens: [] }; chaves.push(k); }
      mapa[k].itens.push(p);
    });
    chaves.sort();
    return chaves.map(function (k) {
      var g = mapa[k], itens = g.itens;
      function medDe(fn) {
        var v = itens.map(fn).filter(function (x) { return x != null && isFinite(x) && x > 0; });
        return v.length ? v.reduce(function (a, b) { return a + b; }, 0) / v.length : null;
      }
      // Série a série: média daquela posição de série no período.
      var maxS = 0;
      itens.forEach(function (p) { if (p.series && p.series.length > maxS) maxS = p.series.length; });
      var series = [];
      for (var i = 0; i < maxS; i++) {
        (function (i) {
          var v = itens.map(function (p) { return p.series && p.series[i] ? p.series[i].peso : 0; })
                       .filter(function (x) { return x > 0; });
          series.push({ peso: v.length ? v.reduce(function (a, b) { return a + b; }, 0) / v.length : 0, reps: 0 });
        })(i);
      }
      return {
        dt: k, rotulo: g.rotulo, detalhe: g.detalhe + ' · ' + itens.length + (itens.length > 1 ? ' sessões' : ' sessão'),
        sessoes: itens.length, series: series,
        max: medDe(function (p) { return p.max; }),
        media: medDe(function (p) { return p.media; }),
        volume: medDe(function (p) { return p.volume; }),
      };
    });
  }

  // ── GRÁFICO ─────────────────────────────────────────────────────────────
  // Linha multi-série com eixo Y fixo à esquerda e área de plotagem que
  // desliza no dedo — é assim que a "linha do tempo" funciona sem inventar
  // gesto nenhum: o próprio scroll horizontal do navegador.
  var _seq = 0;
  var _regs = {};

  // list-style:none nao apaga o triangulo do <details> no Safari/iOS —
  // so ::-webkit-details-marker apaga. Sem isso o "Ver os numeros" aparece
  // com um marcador solto do lado no iPhone.
  function _cssUmaVez() {
    if (window._evoCss) return;
    window._evoCss = true;
    try {
      var st = document.createElement('style');
      st.setAttribute('data-intus', 'evolucao');
      st.textContent =
        '.evo-card summary::-webkit-details-marker{display:none;}' +
        '.evo-card summary::marker{content:"";}' +
        '.evo-card summary{min-height:32px;display:flex;align-items:center;}';
      (document.head || document.documentElement).appendChild(st);
    } catch (e) {}
  }

  function grafico(cfg) {
    _cssUmaVez();
    var isLight = !!cfg.isLight;
    var cores = isLight ? PALETA_CLARA : PALETA_ESCURA;
    var rotulos = cfg.rotulos || [];
    var series = (cfg.series || []).filter(function (s) {
      return (s.valores || []).some(function (v) { return v != null && isFinite(v) && v > 0; });
    });
    var unidade = cfg.unidade || '';
    var casas = (cfg.casas == null) ? 1 : cfg.casas;
    var id = cfg.id || ('evo' + (++_seq));

    var tinta   = isLight ? '#1a1a1a' : '#f0f0f0';
    var tinta2  = isLight ? '#5b626b' : '#9aa0a6';
    var grade   = isLight ? '#e8e8e8' : '#242424';
    var fundo   = isLight ? '#ffffff' : '#111111';
    var borda   = isLight ? '#e5e5e5' : '#1e1e1e';

    if (!series.length || rotulos.length < 1) {
      return '<div style="border:1px solid ' + borda + ';background:' + fundo + ';border-radius:12px;padding:22px;text-align:center;color:' + tinta2 + ';font-size:12.5px;line-height:1.5;">' +
        (cfg.titulo ? '<div style="font-size:13px;font-weight:800;color:' + tinta + ';margin-bottom:6px;">' + esc(cfg.titulo) + '</div>' : '') +
        esc(cfg.vazio || 'Ainda não há registros suficientes para desenhar a evolução.') +
      '</div>';
    }

    // Escala Y comum a todas as séries — nunca dois eixos.
    var todos = [];
    series.forEach(function (s) {
      (s.valores || []).forEach(function (v) { if (v != null && isFinite(v) && v > 0) todos.push(v); });
    });
    var vMax = Math.max.apply(null, todos);
    var vMin = Math.min.apply(null, todos);
    if (vMax === vMin) { vMax = vMax + Math.max(1, vMax * 0.1); vMin = Math.max(0, vMin - Math.max(1, vMin * 0.1)); }
    var folga = (vMax - vMin) * 0.12;
    var yMax = vMax + folga;
    var yMin = Math.max(0, vMin - folga);

    var alt   = cfg.altura || 200;
    var padT  = 14, padB = 26;
    var plotH = alt - padT - padB;
    var passo = cfg.pxPorPonto || 52;
    var padL  = 14, padR = 46;   // padR abre espaço para o rótulo direto do fim
    var largura = padL + Math.max(1, rotulos.length - 1) * passo + padR;

    function y(v) { return padT + plotH - ((v - yMin) / (yMax - yMin)) * plotH; }
    function x(i) { return padL + i * passo; }

    // Eixo Y fixo: fica parado enquanto o gráfico desliza.
    var ticks = [yMin, yMin + (yMax - yMin) / 2, yMax];
    var eixoY = '<svg width="46" height="' + alt + '" style="display:block;flex:0 0 46px;" aria-hidden="true">' +
      ticks.map(function (t) {
        return '<text x="42" y="' + (y(t) + 3.5) + '" text-anchor="end" font-size="9.5" fill="' + tinta2 + '" font-variant-numeric="tabular-nums">' + fmt(t, 0) + '</text>';
      }).join('') + '</svg>';

    // Grade: hairline sólida, um passo fora do fundo. Nunca tracejada.
    var svg = '<svg id="' + id + '-svg" width="' + largura + '" height="' + alt + '" style="display:block;touch-action:pan-x pan-y;">';
    ticks.forEach(function (t) {
      svg += '<line x1="0" y1="' + y(t) + '" x2="' + largura + '" y2="' + y(t) + '" stroke="' + grade + '" stroke-width="1"/>';
    });
    svg += '<line id="' + id + '-cross" x1="0" y1="' + padT + '" x2="0" y2="' + (padT + plotH) + '" stroke="' + tinta2 + '" stroke-width="1" opacity="0"/>';

    // Rótulos do eixo X — dilui quando há muitos pontos para não colidir.
    var salto = Math.max(1, Math.ceil(rotulos.length / Math.max(1, Math.floor(largura / 62))));
    rotulos.forEach(function (r, i) {
      if (i % salto !== 0 && i !== rotulos.length - 1) return;
      svg += '<text x="' + x(i) + '" y="' + (alt - 8) + '" text-anchor="middle" font-size="9.5" fill="' + tinta2 + '">' + esc(r) + '</text>';
    });

    // Linhas: 2px, junta/ponta redonda, quebrando onde não há dado.
    series.forEach(function (s, si) {
      var cor = cores[si % cores.length];
      var seg = [], atual = [];
      (s.valores || []).forEach(function (v, i) {
        if (v != null && isFinite(v) && v > 0) atual.push(x(i) + ',' + y(v));
        else if (atual.length) { seg.push(atual); atual = []; }
      });
      if (atual.length) seg.push(atual);
      seg.forEach(function (p) {
        if (p.length === 1) return;
        svg += '<polyline points="' + p.join(' ') + '" fill="none" stroke="' + cor + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
      });
      // Marcadores: r=4 com anel de 2px na cor do fundo, para não sumirem onde
      // as linhas se cruzam.
      (s.valores || []).forEach(function (v, i) {
        if (v == null || !isFinite(v) || v <= 0) return;
        svg += '<circle cx="' + x(i) + '" cy="' + y(v) + '" r="4" fill="' + cor + '" stroke="' + fundo + '" stroke-width="2"/>';
      });
      // Rótulo direto só no último ponto de cada série (nunca em todos).
      var ultIdx = -1;
      (s.valores || []).forEach(function (v, i) { if (v != null && isFinite(v) && v > 0) ultIdx = i; });
      if (ultIdx >= 0) {
        var vv = s.valores[ultIdx];
        svg += '<text x="' + (x(ultIdx) + 9) + '" y="' + (y(vv) + 3.5) + '" font-size="10" font-weight="700" fill="' + tinta + '" font-variant-numeric="tabular-nums">' + fmt(vv, casas) + '</text>';
      }
    });
    svg += '</svg>';

    // Legenda: obrigatória a partir de duas séries; com uma só, o título basta.
    var legenda = (series.length >= 2)
      ? '<div style="display:flex;flex-wrap:wrap;gap:6px 14px;margin-top:10px;">' +
          series.map(function (s, si) {
            var cor = cores[si % cores.length];
            return '<span style="display:inline-flex;align-items:center;gap:6px;font-size:11px;color:' + tinta2 + ';">' +
              '<span style="width:14px;height:2px;border-radius:2px;background:' + cor + ';display:inline-block;"></span>' + esc(s.nome) + '</span>';
          }).join('') +
        '</div>'
      : '';

    // Tabela: o par acessível de todo gráfico. Também é o que cumpre a regra de
    // alívio no tema claro, onde três cores ficam abaixo de 3:1 de contraste.
    var tabela = '<details style="margin-top:10px;">' +
      '<summary style="font-size:11.5px;color:' + tinta2 + ';cursor:pointer;list-style:none;">Ver os números</summary>' +
      '<div style="overflow-x:auto;margin-top:8px;">' +
      '<table style="border-collapse:collapse;font-size:11.5px;color:' + tinta + ';min-width:100%;font-variant-numeric:tabular-nums;">' +
      '<thead><tr><th style="text-align:left;padding:5px 10px 5px 0;font-weight:700;color:' + tinta2 + ';white-space:nowrap;">Quando</th>' +
      series.map(function (s) { return '<th style="text-align:right;padding:5px 10px;font-weight:700;color:' + tinta2 + ';white-space:nowrap;">' + esc(s.nome) + '</th>'; }).join('') +
      '</tr></thead><tbody>' +
      rotulos.map(function (r, i) {
        return '<tr style="border-top:1px solid ' + grade + ';">' +
          '<td style="padding:5px 10px 5px 0;white-space:nowrap;">' + esc(r) + '</td>' +
          series.map(function (s) {
            var v = (s.valores || [])[i];
            return '<td style="text-align:right;padding:5px 10px;">' + ((v != null && isFinite(v) && v > 0) ? fmt(v, casas) : '—') + '</td>';
          }).join('') + '</tr>';
      }).join('') +
      '</tbody></table></div></details>';

    _regs[id] = {
      rotulos: rotulos, series: series, cores: cores, unidade: unidade, casas: casas,
      padL: padL, passo: passo, alt: alt, isLight: isLight,
      detalhes: cfg.detalhes || null,
    };

    return '<div class="evo-card" data-evo="' + id + '" style="border:1px solid ' + borda + ';background:' + fundo + ';border-radius:12px;padding:14px;">' +
      (cfg.titulo ? '<div style="font-size:13px;font-weight:800;color:' + tinta + ';">' + esc(cfg.titulo) + '</div>' : '') +
      (cfg.sub ? '<div style="font-size:11.5px;color:' + tinta2 + ';margin-top:2px;line-height:1.45;">' + esc(cfg.sub) + '</div>' : '') +
      '<div style="position:relative;display:flex;align-items:flex-start;margin-top:12px;">' +
        eixoY +
        '<div id="' + id + '-scroll" style="flex:1;min-width:0;overflow-x:auto;overflow-y:hidden;-webkit-overflow-scrolling:touch;">' +
          '<div style="position:relative;width:' + largura + 'px;">' + svg +
            '<div id="' + id + '-tip" style="position:absolute;top:0;pointer-events:none;opacity:0;transition:opacity .12s;z-index:5;"></div>' +
          '</div>' +
        '</div>' +
      '</div>' +
      (rotulos.length > 6 ? '<div style="font-size:10.5px;color:' + tinta2 + ';margin-top:6px;text-align:center;">← deslize para ver todo o período →</div>' : '') +
      legenda + tabela +
    '</div>';
  }

  // Liga o toque/mouse dos gráficos que acabaram de entrar no DOM.
  function ativar(raiz) {
    var alvo = raiz || document;
    var cards = alvo.querySelectorAll ? alvo.querySelectorAll('[data-evo]') : [];
    Array.prototype.forEach.call(cards, function (card) {
      var id = card.getAttribute('data-evo');
      var reg = _regs[id];
      if (!reg || card._evoLigado) return;
      card._evoLigado = true;

      var svg   = document.getElementById(id + '-svg');
      var cross = document.getElementById(id + '-cross');
      var tip   = document.getElementById(id + '-tip');
      var rolar = document.getElementById(id + '-scroll');
      if (!svg || !tip) return;

      var tinta  = reg.isLight ? '#1a1a1a' : '#f0f0f0';
      var tinta2 = reg.isLight ? '#5b626b' : '#9aa0a6';
      var fundo  = reg.isLight ? '#ffffff' : '#191919';
      var borda  = reg.isLight ? '#e0e0e0' : '#2c2c2c';

      function mostrar(clientX) {
        var r = svg.getBoundingClientRect();
        var px = clientX - r.left;
        var i = Math.round((px - reg.padL) / reg.passo);
        i = Math.max(0, Math.min(reg.rotulos.length - 1, i));

        if (cross) {
          var cx = reg.padL + i * reg.passo;
          cross.setAttribute('x1', cx); cross.setAttribute('x2', cx);
          cross.setAttribute('opacity', '.35');
        }

        // textContent em tudo que vem dos dados — nome de exercício e divisão
        // são texto de terceiros, nunca entram como HTML.
        tip.innerHTML = '';
        var caixa = document.createElement('div');
        caixa.style.cssText = 'background:' + fundo + ';border:1px solid ' + borda + ';border-radius:9px;' +
          'padding:8px 10px;box-shadow:0 6px 18px rgba(0,0,0,.28);min-width:104px;';
        var cab = document.createElement('div');
        cab.style.cssText = 'font-size:10.5px;color:' + tinta2 + ';margin-bottom:5px;white-space:nowrap;';
        cab.textContent = (reg.detalhes && reg.detalhes[i]) ? reg.detalhes[i] : reg.rotulos[i];
        caixa.appendChild(cab);

        reg.series.forEach(function (s, si) {
          var v = (s.valores || [])[i];
          if (v == null || !isFinite(v) || v <= 0) return;
          var linha = document.createElement('div');
          linha.style.cssText = 'display:flex;align-items:center;gap:7px;font-size:11px;white-space:nowrap;margin-top:2px;';
          var key = document.createElement('span');
          key.style.cssText = 'width:12px;height:2px;border-radius:2px;flex:0 0 12px;background:' + reg.cores[si % reg.cores.length] + ';';
          var val = document.createElement('b');
          val.style.cssText = 'color:' + tinta + ';font-size:12.5px;font-variant-numeric:tabular-nums;';
          val.textContent = fmt(v, reg.casas) + (reg.unidade ? ' ' + reg.unidade : '');
          var nome = document.createElement('span');
          nome.style.cssText = 'color:' + tinta2 + ';';
          nome.textContent = s.nome;
          linha.appendChild(key); linha.appendChild(val); linha.appendChild(nome);
          caixa.appendChild(linha);
        });

        tip.appendChild(caixa);
        tip.style.left = (reg.padL + i * reg.passo) + 'px';
        tip.style.transform = 'translateX(-50%)';
        tip.style.opacity = '1';
        // Não deixa a caixa sair pela borda esquerda do gráfico.
        var lt = tip.getBoundingClientRect(), sr = svg.getBoundingClientRect();
        if (lt.left < sr.left + 2) tip.style.transform = 'translateX(-8px)';
        else if (lt.right > sr.right - 2) tip.style.transform = 'translateX(-100%) translateX(8px)';
      }
      function esconder() {
        tip.style.opacity = '0';
        if (cross) cross.setAttribute('opacity', '0');
      }

      var alvoEv = rolar || svg;
      alvoEv.addEventListener('pointermove', function (e) { mostrar(e.clientX); });
      alvoEv.addEventListener('pointerdown', function (e) { mostrar(e.clientX); });
      alvoEv.addEventListener('pointerleave', esconder);
      alvoEv.addEventListener('pointercancel', esconder);
      // Toque: some sozinho, senão a caixa fica presa na tela.
      alvoEv.addEventListener('pointerup', function (e) {
        if (e.pointerType === 'touch') setTimeout(esconder, 2600);
      });

      // Abre já mostrando o fim da linha do tempo — o que interessa é o agora.
      if (rolar) { try { rolar.scrollLeft = rolar.scrollWidth; } catch (err) {} }
    });
  }

  // ══════════════════════════════════════════════════════════════════════
  // BARRAS POR PERÍODO — "eu treinei o suficiente?"
  // ══════════════════════════════════════════════════════════════════════
  // Substitui o calendário de 12 semanas, que desenhava 84 quadradinhos de
  // 12px em quatro tons de verde. Para responder "estou treinando o
  // bastante?" a pessoa tinha que contar quadradinhos e ainda interpretar
  // uma legenda de intensidade de cor. Uma barra por semana é uma marca por
  // semana: doze marcas, altura comparável, e uma linha pontuando a meta.
  // A semana corrente sai destacada porque é a única em que ainda dá para
  // agir; as outras são contexto.
  var VERDE = { claro: '#3d7a00', escuro: '#7FFF00' };
  var VERDE_FRACO = { claro: '#c9e3aa', escuro: '#2b4a10' };

  function barras(cfg) {
    _cssUmaVez();
    var isLight = !!cfg.isLight;
    var itens = cfg.itens || [];          // [{rotulo, valor, atual?}]
    var meta = Number(cfg.meta) || 0;
    var id = cfg.id || ('bar' + (++_seq));

    var tinta  = isLight ? '#1a1a1a' : '#f0f0f0';
    var tinta2 = isLight ? '#5b626b' : '#9aa0a6';
    var grade  = isLight ? '#e8e8e8' : '#242424';
    var fundo  = isLight ? '#ffffff' : '#111111';
    var borda  = isLight ? '#e5e5e5' : '#1e1e1e';
    var cor    = isLight ? VERDE.claro : VERDE.escuro;
    var corCtx = isLight ? '#9bbf78' : '#3f6b1a';

    if (!itens.length) {
      return '<div style="border:1px solid ' + borda + ';background:' + fundo + ';border-radius:12px;padding:20px;text-align:center;color:' + tinta2 + ';font-size:12.5px;">' +
        esc(cfg.vazio || 'Ainda não há registros suficientes.') + '</div>';
    }

    var vMax = Math.max(meta, Math.max.apply(null, itens.map(function (i) { return i.valor; })), 1);
    var alt = cfg.altura || 128;
    var padT = 10, padB = 22;
    var plotH = alt - padT - padB;
    var passo = cfg.pxPorItem || 30;
    var larguraBarra = Math.min(20, passo - 8);
    var largura = itens.length * passo + 6;
    function y(v) { return padT + plotH - (v / vMax) * plotH; }

    var svg = '<svg width="' + largura + '" height="' + alt + '" style="display:block;">';
    // Linha da meta: sólida e discreta, nunca tracejada.
    if (meta > 0) {
      svg += '<line x1="0" y1="' + y(meta) + '" x2="' + largura + '" y2="' + y(meta) + '" stroke="' + (isLight ? '#c9a227' : '#8a6d1a') + '" stroke-width="1"/>';
    }
    svg += '<line x1="0" y1="' + (padT + plotH) + '" x2="' + largura + '" y2="' + (padT + plotH) + '" stroke="' + grade + '" stroke-width="1"/>';

    itens.forEach(function (it, i) {
      var x = 3 + i * passo + (passo - larguraBarra) / 2;
      // Semana sem treino ganha um traço fino colado na base. Sem ele, a coluna
      // simplesmente não existia e o vazio parecia falha de carregamento em vez
      // de uma semana em que ninguém treinou.
      var zerada = !(it.valor > 0);
      var h = zerada ? 2 : Math.max(3, (it.valor / vMax) * plotH);
      var topo = padT + plotH - h;
      var c = zerada ? (isLight ? '#dcdcdc' : '#2a2a2a') : (it.atual ? cor : corCtx);
      if (h > 0) {
        // Ponta arredondada em cima, quadrada na base.
        var r = Math.min(4, h);
        svg += '<path d="M' + x + ' ' + (topo + h) + ' L' + x + ' ' + (topo + r) +
               ' Q' + x + ' ' + topo + ' ' + (x + r) + ' ' + topo +
               ' L' + (x + larguraBarra - r) + ' ' + topo +
               ' Q' + (x + larguraBarra) + ' ' + topo + ' ' + (x + larguraBarra) + ' ' + (topo + r) +
               ' L' + (x + larguraBarra) + ' ' + (topo + h) + ' Z" fill="' + c + '"/>';
      }
      // Só a semana corrente e as que bateram a meta ganham número na tela.
      if (it.atual || (meta > 0 && it.valor >= meta)) {
        svg += '<text x="' + (x + larguraBarra / 2) + '" y="' + (topo - 3) + '" text-anchor="middle" font-size="9.5" font-weight="800" fill="' + tinta + '">' + it.valor + '</text>';
      }
      svg += '<text x="' + (x + larguraBarra / 2) + '" y="' + (alt - 7) + '" text-anchor="middle" font-size="8.5" fill="' + (it.atual ? tinta : tinta2) + '">' + esc(it.rotulo) + '</text>';
    });
    svg += '</svg>';

    var legenda = (meta > 0)
      ? '<div style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:10.5px;color:' + tinta2 + ';">' +
          '<span style="width:14px;height:1.5px;background:' + (isLight ? '#c9a227' : '#8a6d1a') + ';display:inline-block;"></span>' +
          'meta de ' + meta + ' treinos na semana' +
        '</div>'
      : '';

    return '<div style="border:1px solid ' + borda + ';background:' + fundo + ';border-radius:12px;padding:14px;">' +
      (cfg.titulo ? '<div style="font-size:13px;font-weight:800;color:' + tinta + ';">' + esc(cfg.titulo) + '</div>' : '') +
      (cfg.sub ? '<div style="font-size:11.5px;color:' + tinta2 + ';margin-top:2px;line-height:1.45;">' + esc(cfg.sub) + '</div>' : '') +
      '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;margin-top:10px;">' + svg + '</div>' +
      legenda +
    '</div>';
  }

  // ══════════════════════════════════════════════════════════════════════
  // COMEÇOU → ESTÁ HOJE  (dumbbell)
  // ══════════════════════════════════════════════════════════════════════
  // O formato certo para "antes e depois por item", que é exatamente a
  // pergunta do aluno: "eu evoluí neste exercício?". Uma linha por
  // exercício, o ponto vazado onde ele começou e o cheio onde está agora.
  // A distância entre os dois pontos É a evolução: não precisa ler eixo,
  // não precisa comparar altura de linha, não precisa entender escala.
  function comecouAgora(cfg) {
    _cssUmaVez();
    var isLight = !!cfg.isLight;
    var itens = (cfg.itens || []).filter(function (i) { return i.de > 0 && i.para > 0; });
    var tinta  = isLight ? '#1a1a1a' : '#f0f0f0';
    var tinta2 = isLight ? '#5b626b' : '#9aa0a6';
    var fundo  = isLight ? '#ffffff' : '#111111';
    var borda  = isLight ? '#e5e5e5' : '#1e1e1e';
    var corSubiu = isLight ? '#3d7a00' : '#7FFF00';
    var corCaiu  = isLight ? '#c2410c' : '#fb923c';
    var corIgual = isLight ? '#6b7280' : '#9aa0a6';
    var trilho   = isLight ? '#e6e6e6' : '#262626';

    if (!itens.length) {
      return '<div style="border:1px solid ' + borda + ';background:' + fundo + ';border-radius:12px;padding:20px;text-align:center;color:' + tinta2 + ';font-size:12.5px;line-height:1.55;">' +
        esc(cfg.vazio || 'Assim que você repetir um exercício em duas sessões diferentes, a comparação aparece aqui.') + '</div>';
    }

    // Cada linha tem a SUA escala, de zero até a maior carga daquele exercício.
    // Com escala compartilhada, um leg press de 120 kg comprimia o supino de
    // 18 kg num tracinho de dois pixels: a evolução de quem treina com cargas
    // menores ficava invisível. E comparar supino com leg press não é o que
    // esta tela faz — cada exercício é comparado com ele mesmo no passado.
    var linhas = itens.map(function (it) {
      var subiu = it.para > it.de, caiu = it.para < it.de;
      var c = subiu ? corSubiu : (caiu ? corCaiu : corIgual);
      var teto = Math.max(it.de, it.para) * 1.08;
      var pDe = (it.de / teto) * 100;
      var pPara = (it.para / teto) * 100;
      var esq = Math.min(pDe, pPara), dir = Math.max(pDe, pPara);
      var delta = it.de > 0 ? Math.round(((it.para - it.de) / it.de) * 100) : 0;
      var sinal = delta > 0 ? '+' : '';

      return '<div style="padding:9px 0;border-top:1px solid ' + borda + ';">' +
        '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:8px;margin-bottom:6px;">' +
          '<span style="font-size:12px;font-weight:700;color:' + tinta + ';flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + esc(it.nome) + '</span>' +
          '<span style="font-size:11.5px;font-weight:800;color:' + c + ';flex-shrink:0;font-variant-numeric:tabular-nums;">' +
            (caiu || subiu ? sinal + delta + '%' : 'igual') + '</span>' +
        '</div>' +
        // Trilho + haltere. Percentual em vez de pixel: acompanha a largura.
        '<div style="position:relative;height:16px;margin:0 6px;">' +
          '<div style="position:absolute;left:0;right:0;top:7px;height:2px;background:' + trilho + ';border-radius:2px;"></div>' +
          '<div style="position:absolute;top:7px;height:2px;background:' + c + ';border-radius:2px;left:' + esq + '%;width:' + Math.max(0, dir - esq) + '%;"></div>' +
          '<div title="começou" style="position:absolute;top:2px;left:' + pDe + '%;width:12px;height:12px;margin-left:-6px;border-radius:50%;background:' + fundo + ';border:2px solid ' + c + ';box-sizing:border-box;"></div>' +
          '<div title="agora" style="position:absolute;top:2px;left:' + pPara + '%;width:12px;height:12px;margin-left:-6px;border-radius:50%;background:' + c + ';border:2px solid ' + fundo + ';box-sizing:border-box;"></div>' +
        '</div>' +
        '<div style="display:flex;justify-content:space-between;font-size:10.5px;color:' + tinta2 + ';margin-top:3px;font-variant-numeric:tabular-nums;">' +
          '<span>começou com ' + fmt(it.de) + ' kg</span>' +
          '<span style="color:' + tinta + ';font-weight:700;">hoje ' + fmt(it.para) + ' kg</span>' +
        '</div>' +
      '</div>';
    }).join('');

    return '<div style="border:1px solid ' + borda + ';background:' + fundo + ';border-radius:12px;padding:14px;">' +
      (cfg.titulo ? '<div style="font-size:13px;font-weight:800;color:' + tinta + ';">' + esc(cfg.titulo) + '</div>' : '') +
      (cfg.sub ? '<div style="font-size:11.5px;color:' + tinta2 + ';margin-top:2px;line-height:1.45;">' + esc(cfg.sub) + '</div>' : '') +
      '<div style="margin-top:6px;">' + linhas + '</div>' +
      '<div style="display:flex;gap:14px;margin-top:10px;font-size:10.5px;color:' + tinta2 + ';flex-wrap:wrap;">' +
        '<span style="display:inline-flex;align-items:center;gap:5px;">' +
          '<span style="width:11px;height:11px;border-radius:50%;background:' + fundo + ';border:2px solid ' + corIgual + ';display:inline-block;box-sizing:border-box;"></span>onde começou</span>' +
        '<span style="display:inline-flex;align-items:center;gap:5px;">' +
          '<span style="width:11px;height:11px;border-radius:50%;background:' + corIgual + ';display:inline-block;"></span>onde está hoje</span>' +
      '</div>' +
    '</div>';
  }

  // Barra de escala (Sessões / Meses / Anos). É um controle de UI comum, numa
  // linha só, acima dos gráficos que ele governa.
  function barraEscala(escalaAtual, aoTrocar, isLight) {
    var tinta2 = isLight ? '#5b626b' : '#9aa0a6';
    var borda  = isLight ? '#e5e5e5' : '#252525';
    var opcoes = [['sessao', 'Sessões'], ['mes', 'Meses'], ['ano', 'Anos']];
    return '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;">' +
      opcoes.map(function (o) {
        var on = (escalaAtual === o[0]);
        return '<button type="button" onclick="' + esc(aoTrocar) + '(\'' + o[0] + '\')" style="' +
          'border:1px solid ' + (on ? '#7FFF00' : borda) + ';background:' + (on ? 'rgba(127,255,0,.12)' : 'transparent') + ';' +
          'color:' + (on ? (isLight ? '#3d7a00' : '#7FFF00') : tinta2) + ';border-radius:999px;padding:7px 15px;' +
          'font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;min-height:36px;">' + o[1] + '</button>';
      }).join('') + '</div>';
  }

  return {
    barras: barras,
    comecouAgora: comecouAgora,
    exercicios: exercicios,
    historicoExercicio: historicoExercicio,
    porDivisao: porDivisao,
    agrupar: agrupar,
    grafico: grafico,
    ativar: ativar,
    barraEscala: barraEscala,
    fmt: fmt,
    dtBR: dtBR,
    dtBRCompleta: dtBRCompleta,
    PALETA_CLARA: PALETA_CLARA,
    PALETA_ESCURA: PALETA_ESCURA,
  };
})();

// Force SW update — runs on every page load regardless of cached HTML version
(function _forceSwUpdate() {
  if (!('serviceWorker' in navigator)) return;
  const lastV = localStorage.getItem('intus-app-ver');
  if (lastV === _APP_VERSION) return;
  localStorage.setItem('intus-app-ver', _APP_VERSION);
  navigator.serviceWorker.getRegistrations().then(regs => {
    regs.forEach(r => r.unregister());
  }).then(() => {
    if (lastV) location.reload();
  });
})();

// Gerenciamento de token
const Auth = {
  getToken: () => localStorage.getItem('mx-token'),
  setToken: (t) => localStorage.setItem('mx-token', t),
  getUser:  () => JSON.parse(localStorage.getItem('mx-user') || '{}'),
  setUser:  (u) => localStorage.setItem('mx-user', JSON.stringify(u)),
  clear:    () => { localStorage.removeItem('mx-token'); localStorage.removeItem('mx-user'); },
  isLogged: () => !!localStorage.getItem('mx-token'),
  isLocalToken: () => {
    const t = localStorage.getItem('mx-token') || '';
    return t.startsWith('local-');
  },
  isStudentToken: () => {
    const t = localStorage.getItem('mx-token') || '';
    return t.startsWith('aluno-');
  },
};

// ── LOCAL STORE (fallback) ────────────────────────────────────────────────
// Seeds a partir do MOCK (definido em _mock.js) e mantém sincronia entre páginas.
const Store = {
  KEYS: {
    atletas:      'intus-atletas',
    exercicios:   'intus-exercicios',
    alongamentos: 'intus-alongamentos',
    fichas:       'intus-fichas',
    treinos:      'intus-treinos',       // exercícios dentro de ficha
    mensalidades: 'intus-mensalidades',
    avaliacoes:   'intus-avaliacoes',
    nutricao:     'intus-nutricao',
    mensagens:    'intus-mensagens',
  },

  _seed() {
    // Só semeia se ainda não existir no localStorage
    if (!localStorage.getItem(this.KEYS.atletas) && typeof MOCK !== 'undefined') {
      const mockAtletas = (MOCK.atletas || []).map(a => ({ ...a, origem: 'mock' }));
      localStorage.setItem(this.KEYS.atletas, JSON.stringify(mockAtletas));
    }
    // Exercícios: seed base se vazio, merge se existente (preserva adicionados)
    {
      const _exKey = this.KEYS.exercicios;
      const base = [
        // PEITO
        { idexercicio: 1,  nmexercicio: 'Supino Reto',               grupo: 'Peito',     observacao: 'Controle a descida',   substitutos: [2, 3], videoyoutube: 'https://youtube.com/watch?v=kj_yrgzG5WM' },
        { idexercicio: 2,  nmexercicio: 'Supino Reto (máquina)',     grupo: 'Peito',     observacao: 'Pegada na altura do peito', substitutos: [1, 3] },
        { idexercicio: 3,  nmexercicio: 'Crucifixo Peck Deck',       grupo: 'Peito',     observacao: 'Controle excêntrico',  substitutos: [1, 2], videoyoutube: 'https://youtube.com/watch?v=s2mo2doudcU' },
        { idexercicio: 4,  nmexercicio: 'Supino Inclinado',          grupo: 'Peito',     observacao: 'Banco 30°–45°',        substitutos: [5, 1], videoyoutube: 'https://youtube.com/watch?v=Id-yw7XLHrY' },
        { idexercicio: 5,  nmexercicio: 'Supino Inclinado (máquina)',grupo: 'Peito',     observacao: 'Pegada neutra opcional', substitutos: [4, 1]    },

        // PERNA / QUADRÍCEPS
        { idexercicio: 10, nmexercicio: 'Agachamento Livre',         grupo: 'Perna',     observacao: 'Descer até 90°',       substitutos: [11, 12, 13], videoyoutube: 'https://youtube.com/watch?v=oRqWo0iwwLc' },
        { idexercicio: 11, nmexercicio: 'Agachamento na Barra Guiada (Smith)', grupo: 'Perna', observacao: 'Pés à frente',   substitutos: [10, 12, 13], videoyoutube: 'https://youtube.com/watch?v=tTtSGKZCVq8' },
        { idexercicio: 12, nmexercicio: 'Agachamento na Máquina (Hack)', grupo: 'Perna', observacao: 'Costas apoiadas',      substitutos: [10, 11, 13], videoyoutube: 'https://youtube.com/watch?v=LRGOQprxXJw' },
        { idexercicio: 13, nmexercicio: 'Leg Press 45°',             grupo: 'Perna',     observacao: 'Não trave o joelho',   substitutos: [10, 11, 12], videoyoutube: 'https://youtube.com/watch?v=n9hinnSkwW8' },
        { idexercicio: 14, nmexercicio: 'Cadeira Extensora',         grupo: 'Perna',     observacao: 'Pausa no topo',        substitutos: [13], videoyoutube: 'https://youtube.com/watch?v=ICpPFzcmu7s' },

        // POSTERIOR / GLÚTEO
        { idexercicio: 20, nmexercicio: 'Stiff',                     grupo: 'Posterior', observacao: 'Coluna neutra',        substitutos: [21, 22], videoyoutube: 'https://youtube.com/watch?v=GaX-lj-v_Vk' },
        { idexercicio: 21, nmexercicio: 'Cadeira Flexora',           grupo: 'Posterior', observacao: 'Amplitude completa',   substitutos: [22, 20], videoyoutube: 'https://youtube.com/watch?v=00ZOrUy67w0' },
        { idexercicio: 22, nmexercicio: 'Mesa Flexora',              grupo: 'Posterior', observacao: 'Quadril apoiado',      substitutos: [21, 20], videoyoutube: 'https://youtube.com/watch?v=F3ZAU89fyTU' },
        { idexercicio: 23, nmexercicio: 'Elevação Pélvica (Hip Thrust)', grupo: 'Glúteo', observacao: 'Queixo para baixo',  substitutos: [24], videoyoutube: 'https://youtube.com/watch?v=NCbTBccyIDE' },
        { idexercicio: 24, nmexercicio: 'Glúteo na Máquina',         grupo: 'Glúteo',    observacao: 'Contração isolada',    substitutos: [23]         },

        // COSTAS
        { idexercicio: 30, nmexercicio: 'Puxada Frontal',            grupo: 'Costas',    observacao: 'Peito no banco',       substitutos: [31, 32], videoyoutube: 'https://youtube.com/watch?v=tubB9uvlaeY' },
        { idexercicio: 31, nmexercicio: 'Puxada Pegada Neutra',      grupo: 'Costas',    observacao: 'Cotovelos no corpo',   substitutos: [30, 32], videoyoutube: 'https://youtube.com/watch?v=PMEWTkx0DMo' },
        { idexercicio: 32, nmexercicio: 'Remada Curvada',            grupo: 'Costas',    observacao: 'Tronco 45°',           substitutos: [33, 30], videoyoutube: 'https://youtube.com/watch?v=zBKpcA-j_i4' },
        { idexercicio: 33, nmexercicio: 'Remada Máquina',            grupo: 'Costas',    observacao: 'Escápulas conectadas', substitutos: [32, 30], videoyoutube: 'https://youtube.com/watch?v=m9jUODXtpKc' },

        // BRAÇO
        { idexercicio: 40, nmexercicio: 'Rosca Direta',              grupo: 'Bíceps',    observacao: 'Cotovelos fixos',      substitutos: [41, 42], videoyoutube: 'https://youtube.com/watch?v=cvBp8EmufuY' },
        { idexercicio: 41, nmexercicio: 'Rosca Alternada',           grupo: 'Bíceps',    observacao: 'Supinação na subida',  substitutos: [40, 42]    },
        { idexercicio: 42, nmexercicio: 'Rosca Scott',               grupo: 'Bíceps',    observacao: 'Descer controlado',    substitutos: [40, 41]    },
        { idexercicio: 50, nmexercicio: 'Tríceps Pulley',            grupo: 'Tríceps',   observacao: 'Cotovelo fixo',        substitutos: [51, 52], videoyoutube: 'https://youtube.com/watch?v=X98HwHXAixY' },
        { idexercicio: 51, nmexercicio: 'Tríceps Francês',           grupo: 'Tríceps',   observacao: 'Cotovelos apontando pra cima', substitutos: [50, 52], videoyoutube: 'https://youtube.com/watch?v=86drIJvPyVI' },
        { idexercicio: 52, nmexercicio: 'Tríceps Testa',             grupo: 'Tríceps',   observacao: 'Barra ou halteres',    substitutos: [50, 51], videoyoutube: 'https://youtube.com/watch?v=GkcylDsflBQ' },

        // OMBRO
        { idexercicio: 60, nmexercicio: 'Desenvolvimento Máquina',   grupo: 'Ombro',     observacao: 'Escápulas apoiadas',   substitutos: [61]         },
        { idexercicio: 61, nmexercicio: 'Desenvolvimento Halteres',  grupo: 'Ombro',     observacao: 'Trajetória em V',      substitutos: [60], videoyoutube: 'https://youtube.com/watch?v=3xR5mhWF7k4' },
        { idexercicio: 62, nmexercicio: 'Elevação Lateral',          grupo: 'Ombro',     observacao: 'Cotovelos levemente flexionados', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=GSEGKvmtf7Y' },
        { idexercicio: 63, nmexercicio: 'Elevação Frontal',          grupo: 'Ombro',     observacao: 'Braços estendidos, sem embalo', substitutos: [62], videoyoutube: 'https://youtube.com/watch?v=BQCI0Pg7Gfw' },
        { idexercicio: 64, nmexercicio: 'Face Pull',                 grupo: 'Ombro',     observacao: 'Rotação externa no topo', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=aDNOocCD1Y0' },

        // ABDÔMEN / CORE
        { idexercicio: 70, nmexercicio: 'Abdominal Supra',           grupo: 'Abdômen',   observacao: 'Ombros saem do chão, lombar apoiada', substitutos: [71], videoyoutube: 'https://youtube.com/watch?v=lxUdlT9nNeY' },
        { idexercicio: 71, nmexercicio: 'Abdominal Infra',           grupo: 'Abdômen',   observacao: 'Eleve o quadril, não balance', substitutos: [70], videoyoutube: 'https://youtube.com/watch?v=Vt08ggZwIZg' },
        { idexercicio: 72, nmexercicio: 'Dead Bug',                  grupo: 'Abdômen',   observacao: 'Lombar no chão o tempo todo', substitutos: [70, 71], videoyoutube: 'https://youtube.com/watch?v=E2AZFDjwE6U' },
        { idexercicio: 73, nmexercicio: 'Bird Dog',                  grupo: 'Abdômen',   observacao: 'Extensão oposta braço-perna', substitutos: [72], videoyoutube: 'https://youtube.com/watch?v=qduHP6LwjBU' },
        { idexercicio: 74, nmexercicio: 'Manobra de Bracing',        grupo: 'Core',      observacao: 'Ative abdômen como se fosse levar um soco', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=shhJDv8aPOE' },

        // COSTAS (extras)
        { idexercicio: 80, nmexercicio: 'Puxada Supinada',           grupo: 'Costas',    observacao: 'Pegada supinada, ênfase no bíceps', substitutos: [30, 31], videoyoutube: 'https://youtube.com/watch?v=PMEWTkx0DMo' },
        { idexercicio: 81, nmexercicio: 'Remada Inclinada',          grupo: 'Costas',    observacao: 'Tronco inclinado, halteres ou barra', substitutos: [32, 33], videoyoutube: 'https://youtube.com/watch?v=zBKpcA-j_i4' },
        { idexercicio: 82, nmexercicio: 'Remada Baixa Supinada',     grupo: 'Costas',    observacao: 'Pegada supinada no cabo', substitutos: [33], videoyoutube: 'https://youtube.com/watch?v=zF2-ftDTIqs' },
        { idexercicio: 83, nmexercicio: 'Remada Baixa Neutra',       grupo: 'Costas',    observacao: 'Triângulo ou pegada neutra', substitutos: [32, 82], videoyoutube: 'https://youtube.com/watch?v=PY12MPCsHxg' },
        { idexercicio: 84, nmexercicio: 'Remada Articulada',         grupo: 'Costas',    observacao: 'Máquina articulada, escápulas', substitutos: [33], videoyoutube: 'https://youtube.com/watch?v=ySC8nMZqqUA' },
        { idexercicio: 85, nmexercicio: 'Remada Serrote',            grupo: 'Costas',    observacao: 'Unilateral com halter no banco', substitutos: [81], videoyoutube: 'https://youtube.com/watch?v=EzrmF3P-evc' },
        { idexercicio: 86, nmexercicio: 'Puxador Inclinado',         grupo: 'Costas',    observacao: 'Incline o tronco levemente para trás', substitutos: [30], videoyoutube: 'https://youtube.com/watch?v=ljc6Pv17bFg' },
        { idexercicio: 87, nmexercicio: 'Barra Fixa Adaptada',       grupo: 'Costas',    observacao: 'Com elástico ou gravitron', substitutos: [80], videoyoutube: 'https://youtube.com/watch?v=3fH-v6mfbkY' },

        // POSTERIOR (extras)
        { idexercicio: 90, nmexercicio: 'Good Morning',              grupo: 'Posterior', observacao: 'Barra nas costas, flexão de quadril', substitutos: [20], videoyoutube: 'https://youtube.com/watch?v=b-Jf_aBsdsQ' },
        { idexercicio: 91, nmexercicio: 'Levantamento Terra Sumo',   grupo: 'Posterior', observacao: 'Pés afastados, mãos entre as pernas', substitutos: [90, 20], videoyoutube: 'https://youtube.com/watch?v=Il6BsDkOx8I' },
        { idexercicio: 92, nmexercicio: 'Stiff no Cabo',             grupo: 'Posterior', observacao: 'Cabo baixo, mesma mecânica do stiff', substitutos: [20], videoyoutube: 'https://youtube.com/watch?v=qoOfhzMPP44' },
        { idexercicio: 93, nmexercicio: 'Flexão Nórdica',            grupo: 'Posterior', observacao: 'Excêntrica lenta, alto nível', substitutos: [21, 22], videoyoutube: 'https://youtube.com/watch?v=INYE6tuBpTM' },

        // GLÚTEO (extras)
        { idexercicio: 100, nmexercicio: 'Elevação Pélvica no Solo', grupo: 'Glúteo',    observacao: 'Sem carga, aperte no topo', substitutos: [23], videoyoutube: 'https://youtube.com/watch?v=btkguzN-j9Q' },
        { idexercicio: 101, nmexercicio: 'Extensão de Quadril no Cabo', grupo: 'Glúteo', observacao: 'Caneleira no cabo, perna estendida', substitutos: [24], videoyoutube: 'https://youtube.com/watch?v=XvTJOgGT9lo' },
        { idexercicio: 102, nmexercicio: 'Extensão de Quadril Cruzada', grupo: 'Glúteo', observacao: 'Cruze a perna por trás', substitutos: [101], videoyoutube: 'https://youtube.com/watch?v=TxfTZJJ1hO4' },
        { idexercicio: 103, nmexercicio: 'Abdução de Quadril no Cabo', grupo: 'Glúteo',  observacao: 'Lateral no cabo', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=lnRXk4PsrB0' },
        { idexercicio: 104, nmexercicio: 'Banco Abdutor 120°',       grupo: 'Glúteo',    observacao: 'Banco inclinado, maior amplitude', substitutos: [103], videoyoutube: 'https://youtube.com/watch?v=i8kYmb-eHLg' },
        { idexercicio: 105, nmexercicio: 'Glúteo Sapinho (Frog)',    grupo: 'Glúteo',    observacao: 'Solas dos pés juntas, eleve o quadril', substitutos: [100], videoyoutube: 'https://youtube.com/watch?v=vBPNDeMhX2o' },

        // PERNA (extras)
        { idexercicio: 110, nmexercicio: 'Agachamento Búlgaro',      grupo: 'Perna',     observacao: 'Pé de trás elevado no banco', substitutos: [10], videoyoutube: 'https://youtube.com/watch?v=v73t-Tp76ek' },
        { idexercicio: 111, nmexercicio: 'Agachamento Taça',         grupo: 'Perna',     observacao: 'Halter na frente do peito', substitutos: [10, 13], videoyoutube: 'https://youtube.com/watch?v=RIpj5ClWfRY' },
        { idexercicio: 112, nmexercicio: 'Agachamento Sumo no Smith',grupo: 'Perna',     observacao: 'Pés afastados, pontas para fora', substitutos: [11], videoyoutube: 'https://youtube.com/watch?v=smiTENjkuoI' },
        { idexercicio: 113, nmexercicio: 'Hack Sumo',                grupo: 'Perna',     observacao: 'Pés afastados no hack', substitutos: [12], videoyoutube: 'https://youtube.com/watch?v=vm2ATyXPSxs' },
        { idexercicio: 114, nmexercicio: 'Panturrilha no Smith',     grupo: 'Panturrilha', observacao: 'Barra baixa, amplitude máxima', substitutos: [115], videoyoutube: 'https://youtube.com/watch?v=PeW7O2a0fv8' },
        { idexercicio: 115, nmexercicio: 'Panturrilha no Leg Press', grupo: 'Panturrilha', observacao: 'Ponta dos pés na plataforma', substitutos: [114], videoyoutube: 'https://youtube.com/watch?v=k6OZKXkLCys' },
        { idexercicio: 116, nmexercicio: 'Panturrilha em Pé',        grupo: 'Panturrilha', observacao: 'Máquina ou step com carga', substitutos: [114, 115], videoyoutube: 'https://youtube.com/watch?v=EETLkztbjmM' },
        { idexercicio: 117, nmexercicio: 'Panturrilha Sentada',      grupo: 'Panturrilha', observacao: 'Sóleo isolado', substitutos: [114], videoyoutube: 'https://youtube.com/watch?v=-yw27qlJuVs' },

        // PEITO (extras)
        { idexercicio: 120, nmexercicio: 'Crucifixo Inverso',        grupo: 'Ombro',     observacao: 'Posterior do deltóide', substitutos: [64], videoyoutube: 'https://youtube.com/watch?v=92het1xEZ5k' },
        { idexercicio: 121, nmexercicio: 'Flexão de Braços',         grupo: 'Peito',     observacao: 'Corpo reto, descer até 90°', substitutos: [1], videoyoutube: 'https://youtube.com/watch?v=hHi2S92FGy0' },
        { idexercicio: 122, nmexercicio: 'Elevação Frontal Inclinada', grupo: 'Ombro',   observacao: 'No banco inclinado', substitutos: [63], videoyoutube: 'https://youtube.com/watch?v=HpSJYwFi76Q' },
        { idexercicio: 123, nmexercicio: 'Desenvolvimento Posterior', grupo: 'Ombro',    observacao: 'Foco no deltóide posterior', substitutos: [64, 120], videoyoutube: 'https://youtube.com/watch?v=E9h5eYFSKYs' },

        // BÍCEPS / TRÍCEPS (extras)
        { idexercicio: 130, nmexercicio: 'Rosca Direta com Halteres', grupo: 'Bíceps',   observacao: 'Alternada ou simultânea', substitutos: [40, 41], videoyoutube: 'https://youtube.com/watch?v=-FePv08WCD4' },
        { idexercicio: 131, nmexercicio: 'Tríceps Corda',            grupo: 'Tríceps',   observacao: 'Abra a corda na parte final', substitutos: [50], videoyoutube: 'https://youtube.com/watch?v=D_i8Jeh3Ga4' },
        { idexercicio: 132, nmexercicio: 'Rosca Direta no Cabo',     grupo: 'Bíceps',    observacao: 'Tensão constante no cabo', substitutos: [40], videoyoutube: 'https://youtube.com/watch?v=yN41UmcnP00' },

        // GLÚTEO / POSTERIOR (extras)
        { idexercicio: 133, nmexercicio: 'Banco Abdutor 90°',        grupo: 'Glúteo',    observacao: 'Abertura máxima, contraia no topo', substitutos: [104, 103], videoyoutube: 'https://youtube.com/watch?v=i8kYmb-eHLg' },
        { idexercicio: 134, nmexercicio: 'Banco Abdutor 45°',        grupo: 'Glúteo',    observacao: 'Incline o tronco à frente', substitutos: [133, 103], videoyoutube: 'https://youtube.com/watch?v=i8kYmb-eHLg' },
        { idexercicio: 135, nmexercicio: 'Extensão de Quadril no Banco Romano', grupo: 'Glúteo', observacao: 'Quadril como eixo, squeeze no topo', substitutos: [101], videoyoutube: 'https://youtube.com/watch?v=vBPNDeMhX2o' },
        { idexercicio: 136, nmexercicio: 'Recuo (Agachamento Unilateral para Trás)', grupo: 'Perna', observacao: 'Passo largo para trás', substitutos: [110], videoyoutube: 'https://youtube.com/watch?v=v73t-Tp76ek' },
        { idexercicio: 137, nmexercicio: 'Elevação Pélvica Unilateral', grupo: 'Glúteo', observacao: 'Uma perna só, máxima contração', substitutos: [23, 100], videoyoutube: 'https://youtube.com/watch?v=NCbTBccyIDE' },
      ];
      const existente = JSON.parse(localStorage.getItem(_exKey) || '[]');
      // Se já sincronizou com o backend, não adicionar seed — os IDs do seed colidem com os IDs reais do banco
      const jaSincronizou = !!localStorage.getItem('intus-exercicios-synced');
      if (existente.length === 0 && !jaSincronizou) {
        localStorage.setItem(_exKey, JSON.stringify(base));
      } else if (!jaSincronizou) {
        // Só adiciona do seed se o exercício não existe por ID nem nome
        const existenteIds = new Set(existente.map(e => Number(e.idexercicio)));
        const existenteNomes = new Set(existente.map(e => (e.nmexercicio||'').trim().toLowerCase()));
        const novosBase = base.filter(e => !existenteIds.has(Number(e.idexercicio)) && !existenteNomes.has((e.nmexercicio||'').trim().toLowerCase()));
        if (novosBase.length > 0) {
          localStorage.setItem(_exKey, JSON.stringify([...existente, ...novosBase]));
        }
      }
    }
    // Alongamentos: sempre sobrescreve com a versão oficial do catálogo
    const baseAlong = [
      { idalongamento: 1,  nome: 'Panturrilha',                    grupo: 'Panturrilha',        videoyoutube: 'https://youtube.com/watch?v=6_b4_DDjtuI' },
      { idalongamento: 2,  nome: 'Iliopsoas',                      grupo: 'Quadril',            videoyoutube: 'https://youtube.com/watch?v=MPKGmYBhWGM' },
      { idalongamento: 3,  nome: 'Posteriores de Coxa',            grupo: 'Posterior',          videoyoutube: 'https://youtube.com/watch?v=GzqhfLLTfPc' },
      { idalongamento: 4,  nome: 'Adutores',                       grupo: 'Quadril',            videoyoutube: 'https://youtube.com/watch?v=AkY8gVqWH2s' },
      { idalongamento: 5,  nome: 'Paravertebrais',                 grupo: 'Coluna',             videoyoutube: 'https://youtube.com/watch?v=73sXBlArHl0' },
      { idalongamento: 6,  nome: 'Glúteos',                        grupo: 'Glúteo',             videoyoutube: 'https://youtube.com/watch?v=1YFM-CMaa6w' },
      { idalongamento: 7,  nome: 'Quadríceps',                     grupo: 'Quadríceps',         videoyoutube: 'https://youtube.com/watch?v=0rbIMu1U_xg' },
      { idalongamento: 8,  nome: 'Manguito e Cintura Escapular',   grupo: 'Ombro',              videoyoutube: 'https://youtube.com/watch?v=CXVerwcJZak' },
      { idalongamento: 9,  nome: 'Esternocleidomastóideo',         grupo: 'Pescoço',            videoyoutube: 'https://youtube.com/watch?v=YZsuk9I22fw' },
      { idalongamento: 10, nome: 'Linha Lateral',                  grupo: 'Lateral',            videoyoutube: 'https://youtube.com/watch?v=Aax0TNBPK2k' },
      { idalongamento: 11, nome: 'Geral',                          grupo: 'Geral',              videoyoutube: 'https://youtube.com/watch?v=2ctb_SH0SmM' },
      { idalongamento: 12, nome: 'Alongamento Completo',           grupo: 'Geral',              videoyoutube: 'https://youtube.com/watch?v=t6RT_9SGVVE' },
    ];
    localStorage.setItem(this.KEYS.alongamentos, JSON.stringify(baseAlong));
    if (!localStorage.getItem(this.KEYS.fichas)) {
      localStorage.setItem(this.KEYS.fichas, JSON.stringify([]));
    }
    if (!localStorage.getItem(this.KEYS.treinos)) {
      localStorage.setItem(this.KEYS.treinos, JSON.stringify([]));
    }
    if (!localStorage.getItem(this.KEYS.mensalidades) && typeof MOCK !== 'undefined') {
      // copia do MOCK adicionando idatleta relacional
      const mens = (MOCK.mensalidades || []).map((m, i) => ({
        ...m,
        idatleta: ((MOCK.atletas || []).find(a => a.nome === m.nmathleta) || {}).idatleta || null,
      }));
      localStorage.setItem(this.KEYS.mensalidades, JSON.stringify(mens));
    }
    if (!localStorage.getItem(this.KEYS.avaliacoes)) {
      localStorage.setItem(this.KEYS.avaliacoes, JSON.stringify([]));
    }
    if (!localStorage.getItem(this.KEYS.mensagens) && typeof MOCK !== 'undefined') {
      localStorage.setItem(this.KEYS.mensagens, JSON.stringify(MOCK.mensagens || []));
    }
  },

  get(key) {
    if (!this._seeded) { this._seed(); this._seeded = true; this.dedup('exercicios', 'nmexercicio'); }
    try { return JSON.parse(localStorage.getItem(this.KEYS[key]) || '[]'); }
    catch { return []; }
  },

  set(key, arr) {
    localStorage.setItem(this.KEYS[key], JSON.stringify(arr));
    try { window.dispatchEvent(new Event('intus-' + key + '-change')); } catch {}
  },

  dedup(key, nameField) {
    const lista = this.get(key);
    const seen = new Set();
    const unique = [];
    for (const item of lista) {
      const nome = (item[nameField]||'').trim().toLowerCase();
      if (!nome || seen.has(nome)) continue;
      seen.add(nome);
      unique.push(item);
    }
    if (unique.length < lista.length) {
      this.set(key, unique);
      console.log('[Intus] Dedup ' + key + ': removidos ' + (lista.length - unique.length) + ' duplicados');
    }
  },

  nextId(key, idField) {
    const lista = this.get(key);
    const maxStore = lista.reduce((m, x) => Math.max(m, Number(x[idField]) || 0), 0);
    const minLocal = 900000;
    return Math.max(maxStore, minLocal) + 1;
  },
};

// semeia o store assim que o script carrega
try { Store._seed(); } catch(e) { /* ignora se MOCK não carregou ainda */ }

// Controle de conectividade com o backend.
// Em vez de sempre tentar e falhar, respeitamos o resultado da última checagem.
let USAR_API = false;              // começa assumindo OFFLINE para ser responsivo
let _apiCheckPromise = null;
let _apiCheckTime = 0;
async function checarAPI() {
  if (_apiCheckPromise && (Date.now() - _apiCheckTime) < 30000) return _apiCheckPromise;
  _apiCheckTime = Date.now();
  _apiCheckPromise = (async () => {
    if (Auth.isLocalToken()) {
      USAR_API = false;
      return USAR_API;
    }
    // ── O PING PERGUNTAVA A COISA ERRADA ────────────────────────────────
    // Ele batia em atletas.php, que é rota SÓ DE PROFESSOR. Com a sessão de um
    // aluno a resposta é 401, r.ok vira false, e USAR_API fica false para
    // sempre. Como _flushPendingSync começa com "if (!USAR_API) return", a
    // fila de envios pendentes do aluno nunca era processada: o aviso
    // "1 pendente, clique p/ sincronizar" ficava na tela por dias e o toque
    // não fazia absolutamente nada. Era isso que estava acontecendo no
    // aparelho da Wendy, e aconteceria com qualquer aluno que enfileirasse
    // alguma coisa.
    // A pergunta certa é "o servidor está no ar?", não "eu tenho permissão
    // nesta rota?". Qualquer resposta HTTP, inclusive 401, prova que está.
    // Só um fetch que estoura significa realmente sem conexão.
    try {
      const r = await fetch(API_BASE + '/catalogo.php?action=atleta_existe&atleta=1', {
        method: 'GET',
        headers: { 'Authorization': 'Bearer ' + (Auth.getToken() || '') },
        cache: 'no-store',
      });
      USAR_API = !!(r && r.status > 0);
    } catch {
      USAR_API = false;
    }
    return USAR_API;
  })();
  return _apiCheckPromise;
}

// Configs GLOBAIS (figurinhas, pontuação, frases, conquistas, cardio, notificações) vão
// SEMPRE direto ao servidor — nunca ficam presas no modo offline/localStorage (senão o
// admin "perde" os ajustes ao reiniciar ou em outro aparelho). Endpoint é público.
async function _cfgDireto(action, method, body) {
  const headers = { 'Content-Type': 'application/json', 'Cache-Control': 'no-cache' };
  const tk = (typeof Auth !== 'undefined' && Auth.getToken) ? (Auth.getToken() || '') : (localStorage.getItem('mx-token') || '');
  if (tk) headers['Authorization'] = 'Bearer ' + tk;
  const opts = { method: method || 'GET', cache: 'no-store', headers };
  if (body !== undefined) opts.body = JSON.stringify(body);
  const res = await fetch(API_BASE + '/catalogo.php?action=' + action, opts);
  if (!res.ok) throw Object.assign(new Error('http ' + res.status), { status: res.status });
  const t = await res.text();
  try { return JSON.parse(t); } catch (e) { return {}; }
}

// Carimbo da versao deste api.js. Serve para saber, olhando o painel, QUAL build
// esta realmente no ar — sem depender de conferir tamanho de arquivo no FTP.
const INTUS_BUILD = '20260802a';

// Leitura DIRETA de lista (ignora o modo offline/USAR_API).
// A lista de clientes nao pode cair em cache silencioso: se o ping de conectividade
// falha por um instante, o professor passa a ver uma lista ANTIGA — sem os alunos
// que se cadastraram depois — e nada na tela avisa. Aqui vamos sempre ao servidor;
// so caimos no cache se a rede realmente falhar, e nesse caso marcamos a flag
// API.listaDesatualizada para a tela poder avisar.
async function _listaDireta(path) {
  const headers = { 'Cache-Control': 'no-cache' };
  const tk = (typeof Auth !== 'undefined' && Auth.getToken) ? (Auth.getToken() || '') : (localStorage.getItem('mx-token') || '');
  if (tk) headers['Authorization'] = 'Bearer ' + tk;
  const res = await fetch(API_BASE + path + (path.includes('?') ? '&' : '?') + '_=' + Date.now(), { cache: 'no-store', headers });
  if (!res.ok) throw Object.assign(new Error('http ' + res.status), { status: res.status });
  return await res.json();
}

// Re-checar conectividade quando app volta ao foco
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) { _apiCheckPromise = null; _flushPendingSync(); }
});

// ── Fila de operações pendentes (offline → retry quando voltar online) ──
const PENDING_SYNC_KEY = 'intus-pending-sync';
function _getPendingSync() { try { return JSON.parse(localStorage.getItem(PENDING_SYNC_KEY) || '[]'); } catch { return []; } }
function _savePendingSync(q) { localStorage.setItem(PENDING_SYNC_KEY, JSON.stringify(q)); }
function _enqueuePending(endpoint, options) {
  const q = _getPendingSync();
  q.push({ endpoint, options, ts: Date.now() });
  _savePendingSync(q);
}
// Envio que SEMPRE termina num estado definido, e que devolve o que
// aconteceu para quem chamou poder dizer ao usuário. Antes ele podia sair pela
// porta dos fundos sem enviar, sem limpar e sem avisar.
const PENDENTE_VALIDADE_MS = 24 * 60 * 60 * 1000;

async function _flushPendingSync() {
  let q = _getPendingSync();
  if (!q.length) { _updatePendingBadge(); return { enviados: 0, restantes: 0, motivo: 'vazio' }; }

  // Envio velho demais é descartado mesmo sem conexão. Um treino de três dias
  // atrás gravado agora entraria com data e contexto errados: fica pior que
  // não gravar, e ainda deixa o aviso preso na tela para sempre.
  const antes = q.length;
  q = q.filter(i => (Date.now() - (i.ts || 0)) <= PENDENTE_VALIDADE_MS);
  const vencidos = antes - q.length;
  if (vencidos) _savePendingSync(q);
  if (!q.length) { _updatePendingBadge(); return { enviados: 0, restantes: 0, vencidos, motivo: 'vencidos' }; }

  await checarAPI();
  if (!USAR_API) { _updatePendingBadge(); return { enviados: 0, restantes: q.length, vencidos, motivo: 'sem-conexao' }; }

  const retry = [];
  let enviados = 0;
  for (const item of q) {
    try {
      await apiFetch(item.endpoint, item.options);
      enviados++;
    } catch (e) {
      const status = e.status || 0;
      const isServerReject = status >= 400 && status < 600;
      const isOld = (Date.now() - (item.ts || 0)) > PENDENTE_VALIDADE_MS;
      if (!isServerReject && !isOld && e.message !== 'offline') retry.push(item);
    }
  }
  _savePendingSync(retry);
  _updatePendingBadge();
  return { enviados, restantes: retry.length, vencidos, motivo: retry.length ? 'parcial' : 'ok' };
}
function _updatePendingBadge() {
  const q = _getPendingSync();
  let badge = document.getElementById('pending-sync-badge');
  if (!q.length) { if (badge) badge.remove(); return; }
  if (!badge) {
    badge = document.createElement('div');
    badge.id = 'pending-sync-badge';
    badge.style.cssText = 'position:fixed;bottom:16px;right:16px;z-index:9999;background:#f59e0b;color:#000;font-size:11px;font-weight:700;padding:6px 12px;border-radius:8px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.3);';
    // Toque sem resposta é o pior tipo de botão: a pessoa toca, nada muda, e
    // ela conclui que o app está quebrado. Agora o aviso mostra que está
    // trabalhando e depois diz no que deu.
    badge.onclick = async () => {
      if (badge._ocupado) return;
      badge._ocupado = true;
      const textoOriginal = badge.textContent;
      badge.textContent = '⏳ enviando…';
      let r = null;
      try { r = await _flushPendingSync(); } catch (e) { r = { motivo: 'erro' }; }
      badge._ocupado = false;
      const vivo = document.getElementById('pending-sync-badge');
      if (!vivo) return;                       // sumiu: deu tudo certo
      if (r && r.motivo === 'sem-conexao') {
        vivo.textContent = '📡 sem conexão com o servidor';
        vivo.style.background = '#9aa0a6';
        setTimeout(_updatePendingBadge, 3500);
      } else if (r && r.motivo === 'parcial') {
        vivo.textContent = '⚠️ ' + r.restantes + ' não foi enviado, tentando de novo';
        setTimeout(_updatePendingBadge, 3500);
      } else {
        vivo.textContent = textoOriginal;
      }
    };
    document.body.appendChild(badge);
  }
  badge.style.background = '#f59e0b';
  badge.textContent = `⏳ ${q.length} pendente${q.length > 1 ? 's' : ''}, toque para enviar`;
}
window.addEventListener('online', () => { _apiCheckPromise = null; _flushPendingSync(); });

// Auto-migrar token local- → prof- se backend estiver acessível
setTimeout(async () => {
  _updatePendingBadge();
  if (Auth.isLocalToken()) {
    try {
      const r = await fetch(API_BASE + '/atletas.php?busca=__ping__', {
        headers: { 'Authorization': 'Bearer ' + Auth.getToken() },
        cache: 'no-store',
      });
      if (r.ok) {
        const user = Auth.getUser();
        const newToken = 'prof-' + (user.idusuario || 0) + '-' + Date.now();
        Auth.setToken(newToken);
        USAR_API = true;
        _apiCheckPromise = null;
        _flushPendingSync();
      }
    } catch {}
  } else {
    _flushPendingSync();
  }
}, 1500);

// Fetch base com token (usado quando USAR_API=true)
async function apiFetch(endpoint, options = {}) {
  const token = Auth.getToken();

  if (Auth.isLocalToken()) {
    USAR_API = false;
    throw new Error('offline');
  }

  const res = await fetch(API_BASE + endpoint, {
    cache: 'no-store',
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-cache',
      ...(token ? { 'Authorization': 'Bearer ' + token } : {}),
      ...(options.headers || {}),
    },
  });

  if (res.status === 401) {
    if (options.skipAuthRedirect) {
      let data = {};
      try { data = await res.json(); } catch {}
      const err = new Error(data.error || 'Credenciais inválidas');
      err.status = 401;
      throw err;
    }
    // ANTES: para token de aluno ou local, um 401 era tratado como "o servidor
    // deve estar fora do ar" — o app ligava o modo local e passava a mostrar o
    // que estivesse guardado no aparelho. Foi assim que o aluno ficou olhando
    // uma tela vazia sem entender nada: o servidor tinha respondido, e a
    // resposta era "seu login nao vale mais". Modo offline nao conserta login.
    Auth.clear();
    if (typeof window.sessaoExpirou === 'function') window.sessaoExpirou('apiFetch ' + endpoint);
    else window.location.href = 'login.html';
    const err = new Error('Sessão expirada. Entre novamente para continuar.');
    err.status = 401;
    throw err;
  }

  let data = {};
  try { data = await res.json(); } catch {}
  if (!res.ok) {
    const err = new Error(data.error || 'Erro na requisição');
    err.status = res.status;
    throw err;
  }
  return data;
}

// Helper: tenta backend, em caso de falha cai para função local
// pendingInfo: { endpoint, options } — se fornecido, enfileira para retry quando voltar online
async function tryRemoteOrLocal(remote, local, pendingInfo) {
  try {
    await checarAPI();
    if (!USAR_API) throw new Error('offline');
    return await remote();
  } catch (e) {
    const status = e.status || 0;
    if (status === 403) throw e;
    if (pendingInfo && status === 0) _enqueuePending(pendingInfo.endpoint, pendingInfo.options);
    if (status >= 400) throw e;
    return local();
  }
}

// ── ENDPOINTS ────────────────────────────────────────────────────────────
const API = {

  // Auth ────────────────────────────────────────────────────────────────
  loginProfessor: (email, senha) =>
    apiFetch('/auth.php?action=login_professor', {
      method: 'POST',
      body: JSON.stringify({ email, senha }),
      skipAuthRedirect: true,
    }),

  logout: () => {
    Auth.clear();
    return Promise.resolve(true);
  },

  me: () => apiFetch('/auth.php?action=me'),

  // Atletas / Clientes ──────────────────────────────────────────────────
  // Helper: atleta esta bloqueado (excluido logicamente)?
  // Mantido como funcao no escopo de API para reuso
  // (definida abaixo via API._isBloqueado)

  // Normaliza professores_responsaveis: sempre array de Number
  _normalizarProfs: (atleta) => {
    let p = atleta.professores_responsaveis;
    if (typeof p === 'string') { try { p = JSON.parse(p); } catch { p = []; } }
    if (!Array.isArray(p)) p = [];
    atleta.professores_responsaveis = p.map(Number).filter(n => n > 0);
    return atleta;
  },

  // Filtra atletas pelo professor logado (retorna todos se admin)
  filtrarMeusAtletas: (lista) => {
    const _usr = Sessao.atual();
    if (!_usr || _usr.admin || !_usr.idusuario) return lista;
    const uid = Number(_usr.idusuario);
    return lista.filter(a => {
      API._normalizarProfs(a);
      const p = a.professores_responsaveis;
      return p && Array.isArray(p) && p.includes(uid);
    });
  },

  // listarAtletas(busca, incluirBloqueados=false)
  //   Por padrao NAO retorna bloqueados — assim treinos, mensalidades, avaliacoes etc
  //   nunca mostram aluno excluido. A pagina de gestao (alunos.html) passa true
  //   para ver bloqueados (e poder restaurar).
  listarAtletas: async (busca = '', incluirBloqueados = false) => {
    try {
      const remoto = await _listaDireta('/atletas.php' + (busca ? `?busca=${encodeURIComponent(busca)}` : ''));
      const arr = Array.isArray(remoto) ? remoto : (remoto && Array.isArray(remoto.data) ? remoto.data : []);
      const normalized = arr.map(a => API._normalizarProfs(a));
      if (!busca) Store.set('atletas', normalized);
      API.listaDesatualizada = false;
      return incluirBloqueados ? normalized : normalized.filter(a => !API._isBloqueado(a));
    } catch (e) {
      if (e && e.status === 403) throw e;
      // Cache local so como ultimo recurso — e SINALIZADO, nunca silencioso.
      API.listaDesatualizada = true;
      let lista = Store.get('atletas').map(a => API._normalizarProfs(a));
      if (busca) {
        const q = busca.toLowerCase();
        lista = lista.filter(a =>
          (a.nome  || '').toLowerCase().includes(q) ||
          (a.email || '').toLowerCase().includes(q));
      }
      return incluirBloqueados ? lista : lista.filter(a => !API._isBloqueado(a));
    }
  },

  // true quando a ultima listagem veio do cache local (servidor inacessivel)
  listaDesatualizada: false,

  // versao do api.js efetivamente carregada (ver INTUS_BUILD no topo)
  BUILD: INTUS_BUILD,

  // ── SITUACAO DO PLANO (regra unica do sistema) ──────────────────────────
  // Um aluno so conta como ATIVO se tiver matricula vigente. Fica BLOQUEADO se o
  // plano foi encerrado por nao renovacao, ou se passou da carencia.
  // O bloqueio e CALCULADO, nunca gravado na ficha: registrou o pagamento, o
  // aluno volta no mesmo instante, sem ninguem precisar desbloquear na mao.
  //
  // CARENCIA = 5 DIAS, AQUI E NO APP. Este numero estava em 30 aqui e em 5 no
  // aluno.html, e as duas telas contavam historias diferentes sobre a mesma
  // pessoa: com dez dias de atraso o painel dizia "ativo" e o celular do aluno
  // ja mostrava os treinos cadeados. O professor jurava que estava tudo certo,
  // o aluno jurava que estava travado, e os dois estavam lendo o sistema
  // corretamente. Agora e um numero so, e o aluno.html le DAQUI.
  // Nao confunda com a janela de cobranca do painel inicial (30 dias): aquela
  // e sobre por quanto tempo a divida continua aparecendo para voce cobrar, e
  // e proposital que seja mais longa do que o tempo de acesso do aluno.
  PLANO_CARENCIA_DIAS: 5,

  // ── EDUZZ: TAXA, LIQUIDO E LIBERACAO ──────────────────────────────────────
  // Esta conta vivia dentro do financeiro.html. Passou para ca quando a tela de
  // Gestao precisou dos mesmos numeros: duas copias da mesma formula financeira
  // e o comeco de duas telas discordarem sobre quanto dinheiro existe.
  EDUZZ_PRAZO_DIAS: 30,
  EDUZZ_ANTECIP_PCT: 2.99,
  EDUZZ_ANTECIP_DIA: 0.10,
  EDUZZ_PRESETS: {
    direta:   { nome: 'Venda direta',     pct: 4.9, fixo: 2.49 },
    afiliado: { nome: 'Afiliado',         pct: 8.9, fixo: 1.00 },
    menor30:  { nome: 'Produto até R$30', pct: 0.0, fixo: 2.49 },
    custom:   { nome: 'Personalizado',    pct: 0.0, fixo: 0.00 },
  },
  _addDias: (iso, n) => {
    const d = new Date(String(iso || '').slice(0, 10) + 'T00:00:00');
    if (isNaN(d)) return '';
    d.setDate(d.getDate() + Number(n || 0));
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  },
  eduzzTaxaPlataforma: (v) => {
    const bruto = parseFloat((v && v.valorBruto) || 0);
    const pct = (v && v.taxaPct != null) ? parseFloat(v.taxaPct) : API.EDUZZ_PRESETS.direta.pct;
    const fixo = (v && v.taxaFixo != null) ? parseFloat(v.taxaFixo) : API.EDUZZ_PRESETS.direta.fixo;
    return (bruto * pct / 100) + fixo;
  },
  eduzzTaxaAntecipacao: (v) => (v && v.antecipado) ? parseFloat(v.taxaAntecipValor || 0) : 0,
  eduzzLiquido: (v) => Math.max(0, parseFloat((v && v.valorBruto) || 0)
    - API.eduzzTaxaPlataforma(v) - API.eduzzTaxaAntecipacao(v)),
  eduzzLiberacao: (v) => {
    if (!v) return '';
    if (v.antecipado && v.dataAntecip) return String(v.dataAntecip).slice(0, 10);
    const prazo = (v.prazoDias != null) ? v.prazoDias : API.EDUZZ_PRAZO_DIAS;
    return API._addDias(v.dataVenda, prazo);
  },
  // 'sacado' = ja saiu da Eduzz e entrou no caixa. 'disponivel' = prazo
  // cumprido, esperando o saque. 'retido' = ainda dentro do prazo.
  eduzzStatus: (v) => {
    if (!v) return 'retido';
    if (v.sacado) return 'sacado';
    if (v.antecipado) return 'disponivel';
    const lib = API.eduzzLiberacao(v);
    if (!lib) return 'retido';
    const hoje = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    return lib <= hoje ? 'disponivel' : 'retido';
  },

  // ══ A DATA EM QUE UMA COBRANCA VENCE ═══════════════════════════════════════
  // Esta funcao mora aqui, e nao em cada tela, porque a versao anterior dela
  // vivia copiada em seis lugares — painel de matriculas, tela inicial,
  // financeiro, lista de clientes, dashboard.php e o app do aluno — e as copias
  // divergiram. O resultado foi o caso do Luiz: a mesma matricula aparecia
  // vencida no painel de matriculas, ativa no app e ausente da lista de
  // vencidas da tela inicial. Tres telas, tres respostas, todas "corretas"
  // segundo o proprio codigo. Agora existe uma resposta so, e todas leem DAQUI.
  //
  // A REGRA: o aluno paga o periodo que VAI usar, nao o que ja usou. A Vanessa
  // pagou em 30/07 o ciclo 30/07–30/08; o Diogo pagou em 17/08 o ciclo
  // 17/08–17/09. Entao a cobranca vence quando o periodo COMECA. A coluna
  // Vencimento e o fim da cobertura — a data em que o proximo ciclo comeca.
  //
  // O CICLO ESTICADO: muitas matriculas antigas tem um ciclo so, em que o
  // professor foi empurrando o vencimento a cada mes que recebia. Ficam com
  // inicio em abril e vencimento em setembro num plano mensal. Ler o inicio
  // desses como data de cobranca diria "vencida ha cinco meses" de um aluno em
  // dia. Entao a conta e feita ao contrario, a partir do vencimento: a cobranca
  // vence UM PERIODO antes do fim da cobertura. Num ciclo bem formado isso da
  // exatamente o inicio; num esticado, da o mes que esta correndo agora.
  COBRANCA_TOLERANCIA_DIAS: 5,
  _RECMESES: { mensal: 1, bimestral: 2, trimestral: 3, semestral: 6, anual: 12 },

  // Quantos meses dura um ciclo deste plano. null = o sistema nao sabe (avulso,
  // teste gratis, plano sem periodicidade definida).
  // Teste gratis nao tem periodicidade: ele acaba, nao se repete. Pedir para o
  // professor "definir a periodicidade" de um teste era pedir uma resposta que
  // nao existe, e a etiqueta aparecia ate em quem ja tinha migrado para plano
  // pago, porque o ciclo de teste continua no historico da matricula.
  ehTestePlano: (m) => {
    if (!m) return false;
    const alvo = (String(m.tipo_plano || '') + ' ' + String(m.dshistorico || '')).toLowerCase();
    return /\bteste\b|gratis|grátis|trial|degusta|experimental|cortesia/.test(alvo);
  },

  mesesDoPlano: (m) => {
    if (!m) return null;
    if (API.ehTestePlano(m)) return null;
    const rec = String(m.recorrencia || '').toLowerCase();
    if (rec === 'avulso') return null;
    if (API._RECMESES[rec]) return API._RECMESES[rec];
    const s = String(m.dshistorico || '').toLowerCase();
    if (/anual/.test(s)) return 12;
    if (/semestral/.test(s)) return 6;
    if (/trimestral|trimest/.test(s)) return 3;
    if (/bimestral/.test(s)) return 2;
    if (/mensal|mensalidade|personal/.test(s)) return 1;
    return null;
  },

  // Recua `meses` a partir de uma data ISO, respeitando mes curto.
  _recuarMeses: (iso, meses) => {
    const b = new Date(String(iso).slice(0, 10) + 'T00:00:00');
    if (isNaN(b)) return '';
    const dia = b.getDate();
    const y = b.getFullYear(), mo = b.getMonth() - meses;
    const ultimo = new Date(y, mo + 1, 0).getDate();
    const d = new Date(y, mo, Math.min(dia, ultimo));
    return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  },

  // ── TODO ALUNO PAGA NO COMECO DO CICLO ────────────────────────────────────
  // Eu cheguei a inferir que Erick e Samantha pagavam no fim do periodo, porque
  // os pagamentos deles ficavam colados no vencimento. Estava errado: a regra da
  // Intus e uma so — o aluno paga o periodo que vai usar, e a data marcada como
  // VENCIMENTO e o dia em que o proximo ciclo comeca e o proximo pagamento e
  // devido. O que os dados mostravam nao era um habito de pagamento: era a BAIXA
  // sendo lancada na linha do ciclo que estava aberto na tela, em vez de no
  // ciclo novo que estava nascendo naquele dia.
  //
  //   Erick     pagou 18/06, 21/07 e 19/08 — sempre a 1 ou 2 dias do vencimento
  //   Samantha  pagou 14/05, 15/06, 15/07 e 17/08 — idem
  //   Maud      pagou 23/08, um dia depois do vencimento de 22/08
  //
  // Cada uma dessas baixas e o dinheiro do ciclo que COMECA naquela data. Ler
  // isso e a diferenca entre a Maud aparecer devendo agosto e a Maud aparecer
  // paga ate 22/09, que e a verdade.
  //
  // O criterio e conservador: a data tem que estar mais perto do fim do que do
  // comeco E dentro (ou depois) da tolerancia do vencimento. Quem pagou no dia
  // do inicio, ou atrasado dentro do proprio periodo, continua pagando o proprio
  // ciclo — a Vanessa pagou 30/07 o ciclo 30/07–30/08 e nada muda para ela.
  pagamentoDoProximoCiclo: (c) => {
    if (!c || c.stpgto !== 'S') return false;
    const i = String(c.dtinicio || '').slice(0, 10);
    const v = String(c.dtvencimento || '').slice(0, 10);
    const p = String(c.dtpagamento || '').slice(0, 10);
    if (!i || !v || !p) return false;
    const dia = (a, b) => Math.round((new Date(a + 'T00:00:00') - new Date(b + 'T00:00:00')) / 86400000);
    const doInicio = Math.abs(dia(p, i));
    const doFim    = Math.abs(dia(p, v));
    if (doFim >= doInicio) return false;                       // colado no comeco: e deste ciclo
    return dia(p, v) >= -API.COBRANCA_TOLERANCIA_DIAS;         // colado no fim: e do proximo
  },

  // Este ciclo em aberto ja foi pago numa linha anterior? A baixa que paga o
  // ciclo que comeca em X e a que esta no ciclo cujo VENCIMENTO e X.
  // ── O QUE AGRUPA OS CICLOS DE UMA MATRICULA ───────────────────────────────
  // Esta chave morava so no mensalidades.html. A tela inicial tinha uma versao
  // ingenua propria — comparava o nome do plano — e por isso o Diogo aparecia
  // pedindo renovacao: o plano dele mudou de "Consultoria Recorrente Treino"
  // para "Consultoria Recorrente Mensal Especial", a tela nao viu que o ciclo
  // novo era da mesma matricula, e concluiu que o antigo tinha ficado orfao.
  //
  // A chave COSTURA dois criterios: mesmo aluno + mesmo plano (como sempre foi)
  // e a ligacao explicita idmatricula_origem (que sobrevive ao rename). So pode
  // juntar, nunca separar — nenhuma matricula que hoje aparece inteira pode se
  // quebrar amanha por causa desta funcao.
  _mapaMat: null,
  _mapaMatDe: null,
  _normalizarPlano: (t) => String(t || '').toLowerCase()
    .replace(/\b(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/g, ' ')
    .replace(/\bde\b/g, ' ').replace(/\b20\d{2}\b/g, ' ')
    .replace(/[—–-]+/g, ' ').replace(/\s+/g, ' ').trim(),

  _construirMapaMat: (lista) => {
    const pai = new Map();
    const acha = (a) => { while (pai.get(a) !== a) { pai.set(a, pai.get(pai.get(a))); a = pai.get(a); } return a; };
    const une = (a, b) => { a = acha(a); b = acha(b); if (a !== b) pai.set(a, b); };
    (lista || []).forEach(m => pai.set(Number(m.idmensalidade), Number(m.idmensalidade)));
    const porPlano = new Map();
    (lista || []).forEach(m => {
      const k = Number(m.idatleta) + '|' + API._normalizarPlano(m.dshistorico);
      if (porPlano.has(k)) une(Number(m.idmensalidade), porPlano.get(k));
      else porPlano.set(k, Number(m.idmensalidade));
    });
    (lista || []).forEach(m => {
      const raiz = Number(m.idmatricula_origem || 0);
      if (!raiz || !pai.has(raiz)) return;
      const dono = (lista || []).find(x => Number(x.idmensalidade) === raiz);
      if (dono && Number(dono.idatleta) === Number(m.idatleta)) une(Number(m.idmensalidade), raiz);
    });
    const mapa = new Map();
    (lista || []).forEach(m => mapa.set(Number(m.idmensalidade), 'm' + acha(Number(m.idmensalidade))));
    return mapa;
  },

  chaveMatricula: (m, lista) => {
    if (!m) return '';
    if (Array.isArray(lista)) {
      if (API._mapaMatDe !== lista) { API._mapaMat = API._construirMapaMat(lista); API._mapaMatDe = lista; }
      const k = API._mapaMat.get(Number(m.idmensalidade));
      if (k) return k;
    }
    return Number(m.idatleta) + '|' + API._normalizarPlano(m.dshistorico);
  },

  mesmaMatricula: (a, b, lista) => !!a && !!b &&
    Number(a.idatleta) === Number(b.idatleta) &&
    API.chaveMatricula(a, lista) === API.chaveMatricula(b, lista),

  // ── A PROXIMA COBRANCA DESTA MATRICULA ────────────────────────────────────
  // Existe mesmo quando nao ha ciclo em aberto registrado. Pela regra da Intus,
  // a data de VENCIMENTO do ultimo ciclo pago e o dia em que o proximo comeca e
  // o proximo pagamento e devido. A tela inicial so olhava cobranca em aberto,
  // entao quem esta em dia e vai vencer nos proximos dias simplesmente nao
  // aparecia em "a vencer" — a lista vivia vazia.
  proximaCobranca: (m, lista) => {
    if (!m) return '';
    if (m.stpgto !== 'S') return API.dtCobranca(m);
    // Ciclo pago: a proxima cobranca e o fim da cobertura, desde que ninguem
    // ja tenha registrado o ciclo seguinte (nesse caso ele e quem manda).
    const fim = String(m.dtvencimento || '').slice(0, 10);
    if (!fim) return '';
    const temSucessor = (Array.isArray(lista) ? lista : []).some(x =>
      Number(x.idmensalidade) !== Number(m.idmensalidade) &&
      API.mesmaMatricula(x, m, lista) &&
      x.stmatricula !== 'cancelada' && x.stmatricula !== 'encerrada' &&
      String(x.dtinicio || '').slice(0, 10) >= fim);
    return temSucessor ? '' : fim;
  },

  // ══ A SITUACAO DE UM CICLO — A UNICA FONTE DO SISTEMA ═════════════════════
  // Ate hoje, cada tela decidia sozinha se um ciclo era divida, e cada uma
  // decidia diferente: o painel de matriculas dizia uma coisa, a tela inicial
  // outra, o financeiro uma terceira e o app do aluno uma quarta. Eu vinha
  // consertando tela por tela, e cada conserto deixava as outras para tras —
  // por isso Vanessa, Maud, Erick e Samantha apareciam em atraso no Financeiro
  // depois de terem saido do vermelho em todo o resto.
  //
  // Agora existe UMA resposta. Toda tela pergunta aqui e obedece.
  //
  // ESTADOS
  //   paga              pago, cobertura correndo
  //   renovada          pago, cobertura acabou, ja existe o ciclo seguinte
  //   renovar           pago, cobertura acabou, ninguem registrou o seguinte
  //   paga_no_anterior  em aberto, mas a baixa foi lancada na linha de cima
  //   programada        em aberto, ainda falta para a cobranca
  //   pendente          em aberto, a cobranca chegou (dentro da tolerancia)
  //   vencida           em aberto, passou da tolerancia
  //   inativa           em aberto ha mais de 30 dias
  //   encerrada | cancelada | trancada
  //
  // ehDivida = alguem deve dinheiro. So isso entra em "em atraso" e na
  // inadimplencia. "renovar" precisa da sua acao mas NAO e divida: o aluno esta
  // quite, falta registrar o ciclo novo — somar isso ao atraso inflaria o
  // numero que voce usa para cobrar.
  ANTECEDENCIA_CURTA: 15,   // ciclo de ate 100 dias
  ANTECEDENCIA_LONGA: 30,   // acima disso
  CORTE_CICLO_LONGO: 100,
  DIAS_PARA_INATIVO: 30,

  antecedenciaDias: (m) => {
    const i = String((m && m.dtinicio) || '').slice(0, 10);
    const v = String((m && m.dtvencimento) || '').slice(0, 10);
    if (!i || !v) return API.ANTECEDENCIA_LONGA;
    const dur = Math.round((new Date(v + 'T00:00:00') - new Date(i + 'T00:00:00')) / 86400000);
    if (!(dur > 0)) return API.ANTECEDENCIA_LONGA;
    return dur <= API.CORTE_CICLO_LONGO ? API.ANTECEDENCIA_CURTA : API.ANTECEDENCIA_LONGA;
  },

  situacaoCobranca: (m, lista) => {
    const R = (estado, extra) => Object.assign({
      estado: estado, cobranca: '', dias: null, proxima: '',
      ehDivida: estado === 'pendente' || estado === 'vencida' || estado === 'inativa',
      ehAcao: estado === 'renovar',
      valor: parseFloat((m && m.vlpagar) || 0),
    }, extra || {});
    if (!m) return R('cancelada');
    if (m.stmatricula === 'cancelada') return R('cancelada');
    if (m.stmatricula === 'trancada')  return R('trancada');
    if (m.stmatricula === 'encerrada') return R('encerrada');

    const hoje = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const fim = String(m.dtvencimento || '').slice(0, 10);

    if (String(m.stpgto).toUpperCase() === 'S') {
      if (fim && fim >= hoje) return R('paga', { cobranca: API.dtCobranca(m), proxima: API.proximaCobranca(m, lista) });
      // Cobertura acabada: ou ja existe o ciclo seguinte, ou falta registrar.
      const prox = API.proximaCobranca(m, lista);
      if (!prox) return R('renovada', { cobranca: fim });
      const d = Math.round((new Date(hoje + 'T00:00:00') - new Date(prox + 'T00:00:00')) / 86400000);
      return R('renovar', { cobranca: prox, dias: d, proxima: prox });
    }

    // Em aberto. O dinheiro pode ter entrado na linha de cima.
    if (API.cicloJaPago(m, lista)) {
      const bx = API.baixaQuePagou(m, lista);
      return R('paga_no_anterior', { cobranca: API.dtCobranca(m), pagoEm: (bx && bx.dtpagamento) || '' });
    }
    const cob = API.dtCobranca(m);
    if (!cob) return R('pendente');
    const dias = Math.round((new Date(hoje + 'T00:00:00') - new Date(cob + 'T00:00:00')) / 86400000);
    if (dias < -API.antecedenciaDias(m)) return R('programada', { cobranca: cob, dias: dias, proxima: cob });
    if (dias > API.DIAS_PARA_INATIVO)     return R('inativa',    { cobranca: cob, dias: dias, proxima: cob });
    if (dias > API.COBRANCA_TOLERANCIA_DIAS) return R('vencida', { cobranca: cob, dias: dias, proxima: cob });
    return R('pendente', { cobranca: cob, dias: dias, proxima: cob });
  },

  // Devolve o CICLO que pagou este, ou null. A tela usa para mostrar a data do
  // pagamento na linha certa em vez de deixar a coluna vazia.
  baixaQuePagou: (o, lista) => {
    if (!o || o.stpgto === 'S') return null;
    const ini = String(o.dtinicio || '').slice(0, 10);
    if (!ini) return null;
    const dia = (a, b) => Math.round((new Date(a + 'T00:00:00') - new Date(b + 'T00:00:00')) / 86400000);
    return (Array.isArray(lista) ? lista : []).find(x =>
      Number(x.idatleta) === Number(o.idatleta) &&
      Number(x.idmensalidade) !== Number(o.idmensalidade) &&
      API.pagamentoDoProximoCiclo(x) &&
      String(x.dtvencimento || '').slice(0, 10) &&
      Math.abs(dia(String(x.dtvencimento).slice(0, 10), ini)) <= 2) || null;
  },
  cicloJaPago: (o, lista) => !!API.baixaQuePagou(o, lista),

  // A baixa deste ciclo pago: e dele mesmo, ou do ciclo seguinte?
  dtCobranca: (m) => {
    if (!m) return '';
    const ini  = String(m.dtinicio || '').slice(0, 10);
    const venc = String(m.dtvencimento || '').slice(0, 10);
    const meses = API.mesesDoPlano(m);
    // Sem periodicidade conhecida nao da para recuar: vale o inicio, e o
    // vencimento so quando nem inicio existe (registro antigo).
    if (!meses || !venc) return ini || venc;
    const esperado = API._recuarMeses(venc, meses);
    if (!ini) return esperado;
    // Ciclo bem formado: o inicio bate com o periodo. Uma folga de 5 dias
    // absorve data quebrada, mes de 28 e quem comecou dia 31.
    // Ciclo esticado (professor empurrando o vencimento a cada mes recebido):
    // vale o mes que esta correndo, nao o inicio la de tras.
    const dif = Math.abs(Math.round(
      (new Date(ini + 'T00:00:00') - new Date(esperado + 'T00:00:00')) / 86400000));
    return dif <= 5 ? ini : esperado;
  },

  // Dias desde que a cobranca venceu. Negativo = ainda vai vencer.
  diasDeCobranca: (m, lista) => {
    const cob = API.dtCobranca(m);
    if (!cob) return null;
    const h = new Date(); h.setHours(0, 0, 0, 0);
    return Math.round((h - new Date(cob + 'T00:00:00')) / 86400000);
  },

  // Este ciclo esta em atraso de verdade? (ja passou da tolerancia e nao foi pago)
  cobrancaVencida: (m, lista) => {
    if (!m || m.stpgto === 'S') return false;
    if (m.stmatricula === 'cancelada' || m.stmatricula === 'encerrada') return false;
    // O dinheiro deste ciclo pode ter entrado na linha de cima. Se entrou, nao
    // ha atraso nenhum — foi o caso do Erick, da Samantha e da Maud.
    if (API.cicloJaPago(m, lista)) return false;
    const d = API.diasDeCobranca(m, lista);
    return d != null && d > API.COBRANCA_TOLERANCIA_DIAS;
  },

  // Contas modelo do sistema (usadas no teste gratis) nunca sao bloqueadas.
  _ehModelo: (a) => !!a && (a.email === 'modelo.masculino@intusfit.local' || a.email === 'modelo.feminino@intusfit.local'),

  // Lead = veio do autocadastro/captura, ou (legado) nao tem professor vinculado.
  ehLead: (a) => {
    if (!a) return false;
    if (API._ehModelo(a)) return false;
    const o = String(a.origem || '').toLowerCase();
    if (o === 'app' || o === 'captura' || o === 'lead') return true;
    if (o === 'professor' || o === 'cliente') return false;
    const p = a.professores_responsaveis;
    return !(Array.isArray(p) && p.length > 0);
  },

  // Retorna { estado, ativo, inativo, bloqueado, motivo, diasVencido, lead }
  // estado: 'ativo' | 'inativo' | 'bloqueado'
  //   ativo     = matricula vigente, OU vencida ha ate PLANO_CARENCIA_DIAS
  //   inativo   = passou da carencia, ou nunca teve matricula
  //   bloqueado = plano encerrado sem renovacao, ou bloqueio manual do professor
  // Inativo e bloqueado ficam FORA da lista de ativos e perdem acesso ao app.
  situacaoPlano: (atleta, mensalidades) => {
    const hojeStr = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    const lead = API.ehLead(atleta);
    const R = (estado, motivo, diasVencido) => ({
      estado: estado, ativo: estado === 'ativo', inativo: estado === 'inativo',
      bloqueado: estado === 'bloqueado', motivo: motivo || '',
      diasVencido: (diasVencido === undefined ? null : diasVencido), lead: lead,
    });

    if (API._ehModelo(atleta)) return R('ativo', 'conta modelo do sistema');
    if (API._isBloqueado(atleta)) return R('bloqueado', 'bloqueado manualmente');

    const id = Number(atleta && atleta.idatleta);
    const todas = (Array.isArray(mensalidades) ? mensalidades : []).filter(m => Number(m.idatleta) === id);
    // Trancada = pausa combinada; cancelada = saida antes do fim. Fora da conta.
    const validas = todas.filter(m => m.stmatricula !== 'cancelada' && m.stmatricula !== 'trancada');

    // Vigente = algum ciclo NAO encerrado com vencimento hoje ou no futuro.
    // VIGENTE = a cobertura ainda esta correndo E a cobranca do periodo nao
    // esta em atraso. Antes esta linha olhava so o fim da cobertura, sem nunca
    // checar se o ciclo foi pago: um aluno com o ciclo inteiro em aberto
    // continuava "ativo" ate o fim do periodo mais a carencia. Era por isso que
    // a matricula do Luiz aparecia vencida no painel e ativa no app ao mesmo
    // tempo — cada tela olhava uma metade da verdade.
    const vigente = validas.some(m => m.stmatricula !== 'encerrada' && m.dtvencimento &&
      String(m.dtvencimento).slice(0, 10) >= hojeStr && !API.cobrancaVencida(m, todas));
    if (vigente) return R('ativo', '', 0);

    // Cobertura correndo, mas a cobranca deste periodo passou da tolerancia.
    // O aluno nao e "inativo" (o plano nao acabou): ele esta em atraso, e a
    // carencia conta a partir da COBRANCA, nao do fim da cobertura.
    const emAtraso = validas.filter(m => m.stmatricula !== 'encerrada' &&
      m.dtvencimento && String(m.dtvencimento).slice(0, 10) >= hojeStr && API.cobrancaVencida(m, todas));
    if (emAtraso.length) {
      const d = Math.min.apply(null, emAtraso.map(m => API.diasDeCobranca(m, todas)));
      if (d > API.PLANO_CARENCIA_DIAS) return R('inativo', 'cobranca em atraso ha ' + d + ' dias', d);
      return R('ativo', 'cobranca vencida ha ' + d + ' dias', d);
    }

    // Plano encerrado por nao renovacao: o aluno sai da lista de ativos e perde o
    // acesso ao app na hora — mas fica marcado como INATIVO, nao como bloqueado.
    // "Bloqueado" ficou reservado para a acao deliberada do professor no botao
    // Bloquear; encerramento e fim natural de relacao, nao punicao.
    if (validas.some(m => m.stmatricula === 'encerrada')) {
      return R('inativo', 'plano encerrado sem renovacao');
    }

    // Nenhuma cobertura correndo: a carencia conta do fim da ultima cobertura,
    // que aqui e mesmo a data certa — o que acabou foi o periodo, nao a cobranca.
    const vencs = validas.map(m => m.dtvencimento).filter(Boolean).map(v => String(v).slice(0, 10)).sort();
    if (!vencs.length) return R('inativo', 'sem matricula registrada');

    const ultimo = vencs[vencs.length - 1];
    const dias = Math.round((new Date(hojeStr + 'T00:00:00') - new Date(ultimo + 'T00:00:00')) / 86400000);
    if (dias > API.PLANO_CARENCIA_DIAS) return R('inativo', 'sem plano ativo ha ' + dias + ' dias', dias);
    // Dentro da carencia, segue aparecendo como ativo — e o aluno segue com acesso.
    return R('ativo', 'vencido ha ' + dias + ' dias', dias);
  },

  _isBloqueado: (a) => {
    if (!a) return false;
    const v = a.stbloqueio;
    return v === 'S' || v === 1 || v === '1' || v === true;
  },

  getAtleta: (id) => tryRemoteOrLocal(
    () => apiFetch(`/atletas.php?id=${id}`),
    () => Store.get('atletas').find(a => Number(a.idatleta) === Number(id)) || null
  ),

  criarAtleta: (data) => tryRemoteOrLocal(
    () => apiFetch('/atletas.php', { method: 'POST', body: JSON.stringify(data) }),
    () => {
      const lista = Store.get('atletas');
      const novo = {
        idatleta:   Store.nextId('atletas', 'idatleta'),
        nome:       data.nome || '',
        email:      data.email || '',
        genero:     data.genero || 'M',
        stbloqueio: data.stbloqueio || 'N',
        codacesso:  data.codacesso || ('INT' + Date.now().toString().slice(-4)),
        dtcadastro: new Date().toISOString().slice(0,10),
        dtnascimento: data.dtnascimento || null,
        telefone:   data.telefone || '',
        observacao: data.observacao || '',
      };
      lista.push(novo);
      Store.set('atletas', lista);
      return novo;
    }
  ),

  editarAtleta: (id, data) => tryRemoteOrLocal(
    () => apiFetch(`/atletas.php?id=${id}`, { method: 'PUT', body: JSON.stringify(data) }),
    () => {
      const lista = Store.get('atletas');
      const i = lista.findIndex(a => Number(a.idatleta) === Number(id));
      if (i < 0) throw new Error('Aluno não encontrado');
      lista[i] = Object.assign({}, lista[i], data);
      Store.set('atletas', lista);
      return lista[i];
    },
    { endpoint: `/atletas.php?id=${id}`, options: { method: 'PUT', body: JSON.stringify(data) } }
  ),

  // Versões VERIFICADAS (painel do professor): não caem em localStorage silenciosamente.
  // Se o servidor não confirmar, lançam erro — o professor precisa saber que NÃO salvou.
  criarAtletaVerificado: async (data) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — o cadastro NÃO foi salvo. Tente novamente online.'), { status: 0 });
    const res = await apiFetch('/atletas.php', { method: 'POST', body: JSON.stringify(data) });
    if (!res || res.error) throw new Error((res && res.error) || 'Falha ao criar');
    return res;
  },
  editarAtletaVerificado: async (id, data) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a alteração NÃO foi salva. Tente novamente online.'), { status: 0 });
    const res = await apiFetch(`/atletas.php?id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
    if (!res || res.error) throw new Error((res && res.error) || 'Falha ao salvar');
    return res;
  },

  bloquearAtleta: async (id) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — o bloqueio NÃO foi salvo. Tente novamente online.'), { status: 0 });
    const res = await apiFetch(`/atletas.php?id=${id}`, { method: 'DELETE' });
    if (!res || res.error || res.ok === false) throw new Error((res && res.error) || 'O servidor não confirmou o bloqueio.');
    return res;
  },

  excluirAtleta: async (id) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a exclusão NÃO foi salva. Tente novamente online.'), { status: 0 });
    const res = await apiFetch(`/atletas.php?id=${id}&action=excluir`, { method: 'DELETE' });
    if (!res || res.error || res.ok === false) throw new Error((res && res.error) || 'O servidor não confirmou a exclusão.');
    return res;
  },

  // Exercícios ──────────────────────────────────────────────────────────
  listarExercicios: () => tryRemoteOrLocal(
    async () => {
      const lista = await apiFetch('/treinos.php?action=exercicios');
      const local = Store.get('exercicios');
      if (Array.isArray(lista) && lista.length > 0) {
        // Backend é fonte de verdade para IDs. Remover do local qualquer exercício
        // cujo ID coincida com um ID do backend mas o nome seja diferente (colisão do seed hardcoded).
        const idsBackend = new Set(lista.map(e => Number(e.idexercicio)));
        const nomesBackend = new Set(lista.map(e => (e.nmexercicio||'').trim().toLowerCase()));
        const normNm = (s) => (s||'').trim().toLowerCase();
        const backendById = new Map(lista.map(e => [Number(e.idexercicio), normNm(e.nmexercicio)]));
        const somenteLocal = local.filter(e => {
          const id = Number(e.idexercicio);
          const nm = normNm(e.nmexercicio);
          // Descarta se o ID existe no backend com nome diferente (colisão de seed)
          if (idsBackend.has(id) && backendById.get(id) !== nm) return false;
          // Descarta se o nome já existe no backend (com qualquer ID)
          if (nomesBackend.has(nm)) return false;
          return true;
        });
        const merged = [...lista, ...somenteLocal];
        Store.set('exercicios', merged);
        localStorage.setItem('intus-exercicios-synced', new Date().toISOString());
        // Push exercícios locais faltantes para o backend em background
        if (somenteLocal.length > 0 && !API._syncingEx) {
          API._syncingEx = true;
          (async () => {
            for (const ex of somenteLocal) {
              if (!ex || !ex.nmexercicio) continue;
              try {
                await apiFetch('/treinos.php?action=exercicios', {
                  method: 'POST',
                  body: JSON.stringify({ nmexercicio: ex.nmexercicio, grupo: ex.grupo || '', grupos: ex.grupos || null, equipamento: ex.equipamento || null, observacao: ex.observacao || '', descricao: ex.descricao || '', videoyoutube: ex.videoyoutube || '', substitutos: ex.substitutos || null }),
                });
              } catch {}
            }
            API._syncingEx = false;
          })();
        }
        return merged;
      }
      if (local.length > 0) {
        if (!API._syncingEx) {
          API._syncingEx = true;
          API.syncExerciseCatalog().finally(() => { API._syncingEx = false; });
        }
        return local;
      }
      return [];
    },
    () => Store.get('exercicios')
  ),

  criarExercicio: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/treinos.php?action=exercicios', { method: 'POST', body: JSON.stringify(data) });
      // Sync local after create
      let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean) : null;
      if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
      const grupoStr = grupos && grupos.length ? grupos.join(', ') : (data.grupo || null);
      const novo = {
        idexercicio:  res.idexercicio || Store.nextId('exercicios', 'idexercicio'),
        nmexercicio:  data.nmexercicio || '',
        grupo:        grupoStr,
        grupos:       grupos && grupos.length ? grupos : null,
        equipamento:  data.equipamento || null,
        descricao:    data.descricao || null,
        videoyoutube: data.videoyoutube || null,
        observacao:   data.observacao || null,
        gifexercicio: data.gifexercicio || null,
        instrucao_ia: data.instrucao_ia || null,
        substitutos:  Array.isArray(data.substitutos) ? data.substitutos.map(Number) : [],
      };
      const lista = Store.get('exercicios');
      lista.push(novo);
      Store.set('exercicios', lista);
      return novo;
    },
    () => {
      const lista = Store.get('exercicios');
      let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean) : null;
      if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
      const grupoStr = grupos && grupos.length ? grupos.join(', ') : (data.grupo || null);
      const novo = {
        idexercicio:  Store.nextId('exercicios', 'idexercicio'),
        nmexercicio:  data.nmexercicio || '',
        grupo:        grupoStr,
        grupos:       grupos && grupos.length ? grupos : null,
        equipamento:  data.equipamento || null,
        descricao:    data.descricao || null,
        videoyoutube: data.videoyoutube || null,
        observacao:   data.observacao || null,
        gifexercicio: data.gifexercicio || null,
        instrucao_ia: data.instrucao_ia || null,
        substitutos:  Array.isArray(data.substitutos) ? data.substitutos.map(Number) : [],
      };
      lista.push(novo);
      Store.set('exercicios', lista);
      return novo;
    }
  ),

  editarExercicio: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/treinos.php?action=exercicios', { method: 'PUT', body: JSON.stringify({ ...data, idexercicio: id }) });
      // Sync local
      const lista = Store.get('exercicios');
      const i = lista.findIndex(e => Number(e.idexercicio) === Number(id));
      if (i >= 0) {
        const merged = Object.assign({}, lista[i], data);
        let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean)
                  : (Array.isArray(merged.grupos) ? merged.grupos.filter(Boolean) : null);
        if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
        if (grupos && grupos.length) { merged.grupos = grupos; merged.grupo = grupos.join(', '); }
        lista[i] = merged;
        Store.set('exercicios', lista);
        return merged;
      }
      return data;
    },
    () => {
      const lista = Store.get('exercicios');
      const i = lista.findIndex(e => Number(e.idexercicio) === Number(id));
      if (i < 0) throw new Error('Exercício não encontrado');
      const merged = Object.assign({}, lista[i], data);
      let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean)
                : (Array.isArray(merged.grupos) ? merged.grupos.filter(Boolean) : null);
      if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
      if (grupos && grupos.length) { merged.grupos = grupos; merged.grupo = grupos.join(', '); }
      lista[i] = merged;
      Store.set('exercicios', lista);
      return lista[i];
    }
  ),

  excluirExercicio: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/treinos.php?action=exercicios&id=' + id, { method: 'DELETE' });
      const lista = Store.get('exercicios').filter(e => Number(e.idexercicio) !== Number(id));
      Store.set('exercicios', lista);
      return true;
    },
    () => {
      const lista = Store.get('exercicios').filter(e => Number(e.idexercicio) !== Number(id));
      Store.set('exercicios', lista);
      return true;
    }
  ),

  // Alongamentos (backend-first via catalogo.php) ──────────────────────
  listarAlongamentos: () => tryRemoteOrLocal(
    async () => {
      const lista = await apiFetch('/catalogo.php?action=alongamentos');
      const local = Store.get('alongamentos');
      if (Array.isArray(lista) && lista.length > 0) {
        Store.set('alongamentos', lista);
        return lista;
      }
      return local.length > 0 ? local : (lista || []);
    },
    () => Store.get('alongamentos')
  ),

  criarAlongamento: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/catalogo.php?action=alongamentos', { method: 'POST', body: JSON.stringify(data) });
      const novo = { idalongamento: res.idalongamento, grupo: data.grupo || 'Geral', nome: data.nome || '', tempo: data.tempo || '30s', obs: data.obs || '', descricao: data.descricao || null, videoyoutube: data.videoyoutube || null, gifalongamento: data.gifalongamento || null, instrucao_ia: data.instrucao_ia || null, equipamento: data.equipamento || null, substitutos: Array.isArray(data.substitutos) ? data.substitutos : [] };
      const lista = Store.get('alongamentos'); lista.push(novo); Store.set('alongamentos', lista);
      return novo;
    },
    () => {
      const lista = Store.get('alongamentos');
      const novo = { idalongamento: Store.nextId('alongamentos', 'idalongamento'), grupo: data.grupo || 'Geral', nome: data.nome || '', tempo: data.tempo || '30s', obs: data.obs || '', descricao: data.descricao || null, videoyoutube: data.videoyoutube || null, equipamento: data.equipamento || null, substitutos: Array.isArray(data.substitutos) ? data.substitutos : [] };
      lista.push(novo); Store.set('alongamentos', lista);
      return novo;
    }
  ),

  editarAlongamento: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=alongamentos', { method: 'PUT', body: JSON.stringify({ ...data, idalongamento: id }) });
      const lista = Store.get('alongamentos');
      const i = lista.findIndex(x => Number(x.idalongamento) === Number(id));
      if (i >= 0) { lista[i] = Object.assign({}, lista[i], data); Store.set('alongamentos', lista); return lista[i]; }
      return data;
    },
    () => {
      const lista = Store.get('alongamentos');
      const i = lista.findIndex(x => Number(x.idalongamento) === Number(id));
      if (i < 0) throw new Error('Alongamento não encontrado');
      lista[i] = Object.assign({}, lista[i], data); Store.set('alongamentos', lista);
      return lista[i];
    }
  ),

  excluirAlongamento: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=alongamentos&id=' + id, { method: 'DELETE' });
      const lista = Store.get('alongamentos').filter(x => Number(x.idalongamento) !== Number(id));
      Store.set('alongamentos', lista);
      return true;
    },
    () => {
      const lista = Store.get('alongamentos').filter(x => Number(x.idalongamento) !== Number(id));
      Store.set('alongamentos', lista);
      return true;
    }
  ),

  // Treinos / Fichas (backend-first, espelha em Store p/ ficar offline-friendly) ─
  // Helper: faz upsert numa lista do Store por chave
  _upsertStore: (key, idField, item) => {
    const lista = Store.get(key);
    const i = lista.findIndex(x => Number(x[idField]) === Number(item[idField]));
    if (i >= 0) lista[i] = Object.assign({}, lista[i], item);
    else lista.push(item);
    Store.set(key, lista);
  },

  // Lista TODAS as fichas (cross-aluno) - para uso em treinos.html / dashboard
  listarFichas: () => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch('/treinos.php?action=fichas');
      const lista = Array.isArray(arr) ? arr : [];
      Store.set('fichas', lista); // espelha
      return lista;
    },
    () => Store.get('fichas')
  ),

  // Lista TODOS os exercicios prescritos (cross-ficha) - para uso em treinos.html
  listarTodosTreinos: () => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch('/treinos.php?action=treinos');
      const lista = Array.isArray(arr) ? arr : [];
      Store.set('treinos', lista);
      return lista;
    },
    () => Store.get('treinos')
  ),

  getFichaAtleta: (idatleta) => tryRemoteOrLocal(
    async () => {
      const f = await apiFetch(`/treinos.php?action=ficha&atleta=${idatleta}`);
      if (f && f.idficha) API._upsertStore('fichas', 'idficha', f);
      return f;
    },
    () => Store.get('fichas').find(f => Number(f.idatleta) === Number(idatleta)) || null
  ),

  criarFicha: async (data) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a ficha NÃO foi salva. Tente novamente online.'), { status: 0 });
    const f = await apiFetch('/treinos.php?action=ficha', { method: 'POST', body: JSON.stringify(data) });
    if (!f || f.error || !Number(f.idficha)) throw new Error((f && f.error) || 'O servidor não confirmou a criação da ficha.');
    API._upsertStore('fichas', 'idficha', f);
    return f;
  },

  editarFicha: async (id, data) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a ficha NÃO foi atualizada. Tente novamente online.'), { status: 0 });
    const f = await apiFetch(`/treinos.php?action=ficha&id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
    if (!f || f.error || !Number(f.idficha)) throw new Error((f && f.error) || 'O servidor não confirmou a atualização da ficha.');
    API._upsertStore('fichas', 'idficha', f);
    return f;
  },

  excluirFicha: async (id) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a exclusão da ficha NÃO foi salva. Tente novamente online.'), { status: 0 });
    const res = await apiFetch(`/treinos.php?action=ficha&id=${id}`, { method: 'DELETE' });
    if (!res || res.error || !res.ok) throw new Error((res && res.error) || 'O servidor não confirmou a exclusão da ficha.');
    const lista = Store.get('fichas').filter(f => Number(f.idficha) !== Number(id));
    Store.set('fichas', lista);
    return res;
  },

  getTreinosDaFicha: (idficha) => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch(`/treinos.php?action=treinos&ficha=${idficha}`);
      const lista = Array.isArray(arr) ? arr : [];
      // Espelha estes registros no Store mantendo os de outras fichas intactos
      const others = Store.get('treinos').filter(t => Number(t.idficha) !== Number(idficha));
      Store.set('treinos', others.concat(lista));
      return lista;
    },
    () => Store.get('treinos').filter(t => Number(t.idficha) === Number(idficha))
  ),

  adicionarExercicioFicha: async (data) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — o exercício prescrito NÃO foi salvo. Tente novamente online.'), { status: 0 });
    const t = await apiFetch('/treinos.php?action=treinos', { method: 'POST', body: JSON.stringify(data) });
    if (!t || t.error || !Number(t.idtreino)) throw new Error((t && t.error) || 'O servidor não confirmou o exercício prescrito.');
    API._upsertStore('treinos', 'idtreino', t);
    return t;
  },

  editarExercicioFicha: async (id, data) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — o exercício prescrito NÃO foi atualizado. Tente novamente online.'), { status: 0 });
    const t = await apiFetch(`/treinos.php?action=treinos&id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
    if (!t || t.error || !Number(t.idtreino)) throw new Error((t && t.error) || 'O servidor não confirmou a atualização do exercício prescrito.');
    API._upsertStore('treinos', 'idtreino', t);
    return t;
  },

  removerExercicioFicha: async (id) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a remoção do exercício NÃO foi salva. Tente novamente online.'), { status: 0 });
    const res = await apiFetch(`/treinos.php?action=treinos&id=${id}`, { method: 'DELETE' });
    if (!res || res.error || !res.ok) throw new Error((res && res.error) || 'O servidor não confirmou a remoção do exercício.');
    const lista = Store.get('treinos').filter(t => Number(t.idtreino) !== Number(id));
    Store.set('treinos', lista);
    return res;
  },

  // Sessões de treino (histórico) ──────────────────────────────────────
  listarSessoes: (idatleta) => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch(`/treinos.php?action=sessoes&atleta=${idatleta}`);
      return Array.isArray(arr) ? arr : [];
    },
    () => {
      const all = JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]');
      return all.filter(s => Number(s.idatleta) === Number(idatleta)).sort((a, b) => (b.dtsessao || '').localeCompare(a.dtsessao || ''));
    }
  ),

  listarTodasSessoes: () => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch('/treinos.php?action=sessoes');
      return Array.isArray(arr) ? arr : [];
    },
    () => JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]')
  ),

  // Histórico e ranking são compartilhados: IDs locais não podem representar
  // uma sessão concluída. O app do aluno usa a mesma regra em SessoesTreino.criar().
  criarSessao: async (data) => {
    const r = await apiFetch('/treinos.php?action=sessoes', { method: 'POST', body: JSON.stringify(data) });
    if (!r || !r.ok || !Number(r.idsessao)) {
      throw new Error((r && r.error) || 'O servidor não confirmou o salvamento da sessão.');
    }
    const sessaoSalva = { ...data, idsessao: Number(r.idsessao) };
    const all = JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]');
    // Evita duplicar no cache se a tela já tiver recebido a sessão por sincronização.
    if (!all.some(s => Number(s.idsessao) === sessaoSalva.idsessao)) {
      all.push(sessaoSalva);
      localStorage.setItem('intus-sessoes-treino', JSON.stringify(all));
    }
    return sessaoSalva;
  },

  excluirSessao: async (id) => {
    const r = await apiFetch(`/treinos.php?action=sessoes&id=${id}`, { method: 'DELETE' });
    if (!r || !r.ok) {
      throw new Error((r && r.error) || 'O servidor não confirmou a exclusão da sessão.');
    }
    // O cache só é atualizado depois da confirmação positiva do servidor.
    const all = JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]');
    const filtered = all.filter(s => Number(s.idsessao) !== Number(id));
    localStorage.setItem('intus-sessoes-treino', JSON.stringify(filtered));
    return true;
  },

  // Dashboard ───────────────────────────────────────────────────────────
  getDashboard: () => tryRemoteOrLocal(
    () => apiFetch('/dashboard.php'),
    () => {
      const atletas = Store.get('atletas');
      const mens    = Store.get('mensalidades');
      const fichas  = Store.get('fichas');
      const hoje    = new Date().toISOString().slice(0,10);

      const vencidas = mens
        .filter(m => m.stpgto !== 'S' && (m.dtvencimento || '') < hoje)
        .map(m => {
          const a = atletas.find(x => Number(x.idatleta) === Number(m.idatleta)) || {};
          return {
            nome: m.nmathleta || a.nome || '—',
            dtvencimento: m.dtvencimento,
            vlpagar: m.vlpagar,
            professor: ''
          };
        });

      const inativos = atletas
        .filter(a => !fichas.some(f => Number(f.idatleta) === Number(a.idatleta)))
        .slice(0, 10)
        .map(a => ({ nome: a.nome, dt: '—' }));

      return {
        resumo: {
          total_atletas: atletas.length,
          atletas_ativos: atletas.filter(a => a.stbloqueio !== 'S').length,
          mens_vencidas: vencidas.length,
          msgs_nao_lidas: Store.get('mensagens').filter(m => m.stlido === 'N').length,
        },
        treinos_inativos: inativos,
        mensalidades_vencidas: vencidas,
      };
    }
  ),

  // Mensalidades ────────────────────────────────────────────────────────
  listarMensalidades: (filtro = '') => tryRemoteOrLocal(
    () => apiFetch('/mensalidades.php' + (filtro ? `?status=${filtro}` : '')),
    () => {
      const atletas = Store.get('atletas');
      let mens = Store.get('mensalidades').map(m => {
        const a = atletas.find(x => Number(x.idatleta) === Number(m.idatleta));
        return { ...m, nmathleta: m.nmathleta || (a ? a.nome : '—') };
      });
      if (filtro === 'pago')     mens = mens.filter(m => m.stpgto === 'S');
      if (filtro === 'pendente') mens = mens.filter(m => m.stpgto !== 'S');
      return mens;
    }
  ),

  getMensalidadesAtleta: (idatleta) => tryRemoteOrLocal(
    () => apiFetch(`/mensalidades.php?atleta=${idatleta}`),
    () => Store.get('mensalidades').filter(m => Number(m.idatleta) === Number(idatleta))
  ),

  // Matrículas e pagamentos afetam acesso e receita. Nunca devem receber ID
  // local ou aparecer como baixa confirmada sem recibo positivo do servidor.
  criarMensalidade: async (data) => {
    const r = await apiFetch('/mensalidades.php', { method: 'POST', body: JSON.stringify(data) });
    if (!r || !r.ok || !Number(r.idmensalidade)) {
      throw new Error((r && r.error) || 'O servidor não confirmou a criação da matrícula.');
    }
    return r;
  },

  editarMensalidade: async (id, data) => {
    const r = await apiFetch(`/mensalidades.php?id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
    if (!r || !r.ok) {
      throw new Error((r && r.error) || 'O servidor não confirmou a atualização da matrícula.');
    }
    return r;
  },

  pagarMensalidade: (m, dtpagamento, vlpagamento) => API.editarMensalidade(m.idmensalidade, {
    dshistorico:  m.dshistorico || 'Mensalidade',
    dtvencimento: m.dtvencimento,
    vlpagar:      m.vlpagar,
    stpgto:       'S',
    vldesconto:   m.vldesconto || 0,
    vljuro:       m.vljuro || 0,
    dtpagamento:  dtpagamento || new Date().toISOString().slice(0,10),
    vlpagamento:  (vlpagamento != null ? vlpagamento : m.vlpagar),
    dspagamento:  'Pagamento registrado',
  }),

  excluirMensalidade: async (id) => {
    const r = await apiFetch(`/mensalidades.php?id=${id}`, { method: 'DELETE' });
    if (!r || !r.ok) {
      throw new Error((r && r.error) || 'O servidor não confirmou a exclusão da matrícula.');
    }
    return r;
  },

  // Mensagens / Chat ────────────────────────────────────────────────────
  listarMensagens: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/mensagens.php?action=listar' + (idatleta ? '&atleta=' + idatleta : '');
      const lista = await apiFetch(url);
      if (Array.isArray(lista)) Store.set('mensagens', lista);
      return lista;
    },
    () => {
      const todas = Store.get('mensagens');
      return idatleta ? todas.filter(m => Number(m.idatleta) === Number(idatleta)) : todas;
    }
  ),

  enviarMensagem: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/mensagens.php?action=enviar', { method: 'POST', body: JSON.stringify(data) });
      if (res.ok && res.mensagem) {
        const lista = Store.get('mensagens');
        lista.push(res.mensagem);
        Store.set('mensagens', lista);
      }
      return res.mensagem || res;
    },
    () => {
      const lista = Store.get('mensagens');
      const novo = {
        idmensagem: Store.nextId('mensagens', 'idmensagem'),
        idatleta:   data.idatleta,
        idusuario:  data.idusuario || null,
        remetente:  data.remetente || 'aluno',
        texto:      data.texto,
        stlido:     'N',
        dtmensagem: new Date().toISOString().replace('T',' ').slice(0,19),
      };
      lista.push(novo);
      Store.set('mensagens', lista);
      return novo;
    },
    { endpoint: '/mensagens.php?action=enviar', options: { method: 'POST', body: JSON.stringify(data) } }
  ),

  marcarMensagensLidas: (ids) => tryRemoteOrLocal(
    () => apiFetch('/mensagens.php?action=marcar_lida', { method: 'POST', body: JSON.stringify({ ids }) }),
    () => {
      const lista = Store.get('mensagens');
      ids.forEach(id => { const m = lista.find(x => Number(x.idmensagem) === Number(id)); if (m) m.stlido = 'S'; });
      Store.set('mensagens', lista);
      return { ok: true };
    }
  ),

  excluirMensagem: (id) => tryRemoteOrLocal(
    () => apiFetch(`/mensagens.php?action=excluir&id=${id}`, { method: 'DELETE' }),
    () => {
      const lista = Store.get('mensagens').filter(m => Number(m.idmensagem) !== Number(id));
      Store.set('mensagens', lista);
      return true;
    }
  ),

  listarComentarios: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/mensagens.php?action=comentarios' + (idatleta ? '&atleta=' + idatleta : '');
      return await apiFetch(url);
    },
    () => {
      const sessoes = JSON.parse(localStorage.getItem('intus-sessoes') || '[]');
      return sessoes.filter(s => s.comentario && (!idatleta || Number(s.idatleta) === Number(idatleta)))
        .map((s, i) => ({
          idcomentario: i + 1, idatleta: s.idatleta, idficha: s.idficha,
          divisao: s.divisao, dtsessao: s.dtsessao, texto: s.comentario,
          dtcriacao: s.dtsessao + ' 00:00:00', reacoes: [],
        }));
    }
  ),

  reagirComentario: (data) => tryRemoteOrLocal(
    () => apiFetch('/mensagens.php?action=reagir', { method: 'POST', body: JSON.stringify(data) }),
    () => { return { ok: true }; },
    { endpoint: '/mensagens.php?action=reagir', options: { method: 'POST', body: JSON.stringify(data) } }
  ),

  salvarComentarioBackend: (data) => tryRemoteOrLocal(
    () => apiFetch('/mensagens.php?action=salvar_comentario', { method: 'POST', body: JSON.stringify(data) }),
    () => { return { ok: true }; },
    { endpoint: '/mensagens.php?action=salvar_comentario', options: { method: 'POST', body: JSON.stringify(data) } }
  ),

  // Desafios ────────────────────────────────────────────────────────────
  listarPlanosCatalogo: () => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/desafios.php?action=planos');
      if (Array.isArray(res)) localStorage.setItem('intus-planos-catalogo', JSON.stringify(res));
      return res;
    },
    () => JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]')
  ),

  criarPlanoCatalogo: (data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=planos', { method: 'POST', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]');
      const novo = { idplano: Date.now(), ...data, ativo: true };
      lista.push(novo);
      localStorage.setItem('intus-planos-catalogo', JSON.stringify(lista));
      return { ok: true, idplano: novo.idplano };
    }
  ),

  editarPlanoCatalogo: (id, data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=planos&id=' + id, { method: 'PUT', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]');
      const p = lista.find(x => Number(x.idplano) === Number(id));
      if (p) Object.assign(p, data);
      localStorage.setItem('intus-planos-catalogo', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  excluirPlanoCatalogo: (id) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=planos&id=' + id, { method: 'DELETE' }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]').filter(x => Number(x.idplano) !== Number(id));
      localStorage.setItem('intus-planos-catalogo', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  listarDesafios: () => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/desafios.php?action=desafios');
      if (Array.isArray(res)) localStorage.setItem('intus-desafios', JSON.stringify(res));
      return res;
    },
    () => JSON.parse(localStorage.getItem('intus-desafios') || '[]')
  ),

  obterDesafio: (id) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafio&id=' + id),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]');
      return lista.find(d => Number(d.iddesafio) === Number(id)) || null;
    }
  ),

  criarDesafio: (data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafios', { method: 'POST', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]');
      const novo = { iddesafio: Date.now(), ...data, ativo: true, participantes_count: (data.participantes || []).length };
      lista.push(novo);
      localStorage.setItem('intus-desafios', JSON.stringify(lista));
      return { ok: true, iddesafio: novo.iddesafio };
    }
  ),

  editarDesafio: (id, data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafios&id=' + id, { method: 'PUT', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]');
      const d = lista.find(x => Number(x.iddesafio) === Number(id));
      if (d) Object.assign(d, data);
      localStorage.setItem('intus-desafios', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  excluirDesafio: (id) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafios&id=' + id, { method: 'DELETE' }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]').filter(x => Number(x.iddesafio) !== Number(id));
      localStorage.setItem('intus-desafios', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  listarMensagensGrupo: (iddesafio) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=mensagens_grupo&desafio=' + iddesafio),
    () => JSON.parse(localStorage.getItem('intus-desafio-msgs-' + iddesafio) || '[]')
  ),

  enviarMensagemGrupo: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/desafios.php?action=mensagens_grupo', { method: 'POST', body: JSON.stringify(data) });
      return res.mensagem || res;
    },
    () => {
      const key = 'intus-desafio-msgs-' + data.iddesafio;
      const lista = JSON.parse(localStorage.getItem(key) || '[]');
      const novo = { idmensagem: Date.now(), ...data, dtmensagem: new Date().toISOString().replace('T',' ').slice(0,19) };
      lista.push(novo);
      localStorage.setItem(key, JSON.stringify(lista));
      return novo;
    }
  ),

  rankingDesafio: (iddesafio) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=ranking&desafio=' + iddesafio),
    () => []
  ),

  meusDesafios: (idatleta) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=meus_desafios&atleta=' + idatleta),
    () => JSON.parse(localStorage.getItem('intus-desafios') || '[]').filter(d => d.ativo)
  ),

  // Sessões cruas do período do desafio + as regras próprias dele, pra quem
  // chama montar o ranking com API.agregarPontosRank(sessoes, regras) — a
  // mesma função do ranking geral, só que com os números deste desafio.
  rankingPontosDesafio: (iddesafio) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=ranking_pontos&desafio=' + iddesafio),
    () => ({ participantes: [], sessoes: [], regras_pontos: null })
  ),

  anunciarVencedorDesafio: (data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=anunciar_vencedor', { method: 'POST', body: JSON.stringify(data) }),
    () => ({ ok: true })
  ),

  desafiosAbertos: (idatleta) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafios_abertos' + (idatleta ? '&atleta=' + idatleta : '')),
    () => []
  ),

  solicitarEntradaDesafio: (iddesafio, idatleta) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=solicitar_entrada', { method: 'POST', body: JSON.stringify({ iddesafio, idatleta }) }),
    () => ({ ok: true, status: 'participante' })
  ),

  listarSolicitacoesDesafio: (iddesafio) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=solicitacoes&desafio=' + iddesafio),
    () => []
  ),

  responderSolicitacaoDesafio: (idsolicitacao, aprovar) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=solicitacoes', { method: 'POST', body: JSON.stringify({ idsolicitacao, aprovar }) }),
    () => ({ ok: true })
  ),

  // Avaliações (backend-first via catalogo.php) ────────────────────────
  listarAvaliacoes: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/catalogo.php?action=avaliacoes' + (idatleta ? '&atleta=' + idatleta : '');
      const lista = await apiFetch(url);
      return Array.isArray(lista) ? lista : [];
    },
    () => {
      const todas = Store.get('avaliacoes');
      return idatleta ? todas.filter(x => Number(x.idatleta) === Number(idatleta)) : todas;
    }
  ),

  // Lista todas as anamneses (dashboard do professor)
  listarAnamneses: () => tryRemoteOrLocal(
    async () => {
      const lista = await apiFetch('/catalogo.php?action=anamneses');
      return Array.isArray(lista) ? lista : [];
    },
    () => []
  ),

  // Avaliação é um registro clínico compartilhado entre painel e aluno.
  // Nunca confirmar criação local: se o servidor não recebeu, ela não existe
  // para o outro lado e o usuário precisa saber para tentar novamente.
  criarAvaliacaoVerificada: async (data) => {
    await checarAPI();
    if (!USAR_API) {
      throw Object.assign(new Error('Sem conexão com o servidor — a avaliação NÃO foi salva. Verifique a internet e tente novamente.'), { status: 0 });
    }
    const res = await apiFetch('/catalogo.php?action=avaliacoes', { method: 'POST', body: JSON.stringify(data) });
    if (!res || !res.ok || !Number(res.idavaliacao)) {
      throw new Error((res && res.error) || 'O servidor não confirmou o salvamento da avaliação.');
    }
    return { idavaliacao: Number(res.idavaliacao), ...data };
  },

  // Alias mantido para chamadas existentes: criação de avaliação é sempre
  // confirmada pelo servidor, sem fallback silencioso para localStorage.
  criarAvaliacao: (data) => API.criarAvaliacaoVerificada(data),

  editarAvaliacao: async (id, data) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a avaliação NÃO foi atualizada. Tente novamente online.'), { status: 0 });
    const res = await apiFetch('/catalogo.php?action=avaliacoes', { method: 'PUT', body: JSON.stringify({ ...data, idavaliacao: id }) });
    if (!res || res.error || res.ok === false) throw new Error((res && res.error) || 'O servidor não confirmou a atualização da avaliação.');
    return { idavaliacao: id, ...data };
  },

  excluirAvaliacao: async (id) => {
    await checarAPI();
    if (!USAR_API) throw Object.assign(new Error('Sem conexão com o servidor — a exclusão da avaliação NÃO foi salva. Tente novamente online.'), { status: 0 });
    const res = await apiFetch('/catalogo.php?action=avaliacoes&id=' + id, { method: 'DELETE' });
    if (!res || res.error || res.ok === false) throw new Error((res && res.error) || 'O servidor não confirmou a exclusão da avaliação.');
    return res;
  },

  // Nutrição — Planos Nutricionais (backend-first via catalogo.php) ────
  listarPlanosNutricionais: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/catalogo.php?action=nutricao' + (idatleta ? '&atleta=' + idatleta : '');
      const lista = await apiFetch(url);
      return Array.isArray(lista) ? lista : [];
    },
    () => {
      const todos = Store.get('nutricao');
      return idatleta ? todos.filter(x => Number(x.idatleta) === Number(idatleta)) : todos;
    }
  ),

  criarPlanoNutricional: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/catalogo.php?action=nutricao', { method: 'POST', body: JSON.stringify(data) });
      return { idplano: res.idplano, ...data };
    },
    () => {
      const lista = Store.get('nutricao');
      const novo = {
        idplano:     Store.nextId('nutricao', 'idplano'),
        idatleta:    data.idatleta,
        titulo:      data.titulo || 'Plano Alimentar',
        objetivo:    data.objetivo || '',
        calorias:    data.calorias || null,
        proteina:    data.proteina || null,
        carboidrato: data.carboidrato || null,
        gordura:     data.gordura || null,
        refeicoes:   data.refeicoes || null,
        observacao:  data.observacao || '',
        comentario_inicio: data.comentario_inicio || '',
        comentario_fim:    data.comentario_fim || '',
        ativo:       data.ativo != null ? data.ativo : 1,
        dtinicio:    data.dtinicio || null,
        dtfim:       data.dtfim || null,
      };
      lista.push(novo); Store.set('nutricao', lista);
      return novo;
    }
  ),

  editarPlanoNutricional: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=nutricao', { method: 'PUT', body: JSON.stringify({ ...data, idplano: id }) });
      return { idplano: id, ...data };
    },
    () => {
      const lista = Store.get('nutricao');
      const i = lista.findIndex(x => Number(x.idplano) === Number(id));
      if (i < 0) throw new Error('Plano não encontrado');
      lista[i] = Object.assign({}, lista[i], data); Store.set('nutricao', lista);
      return lista[i];
    }
  ),

  excluirPlanoNutricional: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=nutricao&id=' + id, { method: 'DELETE' });
      return true;
    },
    () => {
      const lista = Store.get('nutricao').filter(x => Number(x.idplano) !== Number(id));
      Store.set('nutricao', lista);
      return true;
    }
  ),

  // Caixa (financeiro.html mantém seu próprio cache local em localStorage e
  // decide quando chamar cada uma destas; aqui é só a chamada de rede crua —
  // sem isso, lançamentos (principalmente as saídas) só existiam no aparelho
  // que os criou e desapareciam ao trocar de navegador/computador).
  listarCaixa: () => apiFetch('/catalogo.php?action=caixa'),
  salvarLancamentoCaixa: (item) => apiFetch('/catalogo.php?action=caixa', { method: 'POST', body: JSON.stringify(item) }),
  excluirLancamentoCaixa: (id) => apiFetch('/catalogo.php?action=caixa&id=' + encodeURIComponent(id), { method: 'DELETE' }),

  // Planos Config (backend-synced via catalogo.php) ───────────────────
  getPlanosConfig: () => tryRemoteOrLocal(
    async () => {
      const cfg = await apiFetch('/catalogo.php?action=planos_config');
      if (cfg && typeof cfg === 'object') localStorage.setItem('intus-planos-config', JSON.stringify(cfg));
      return cfg || {};
    },
    () => JSON.parse(localStorage.getItem('intus-planos-config') || '{}')
  ),

  savePlanosConfig: (cfg) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=planos_config', { method: 'PUT', body: JSON.stringify(cfg) });
      localStorage.setItem('intus-planos-config', JSON.stringify(cfg));
      return true;
    },
    () => { localStorage.setItem('intus-planos-config', JSON.stringify(cfg)); return true; }
  ),

  // Frases do app (tela inicial + fim de treino) — editáveis pelo admin ──
  getFrasesConfig: async () => {
    try { const cfg = await _cfgDireto('frases_config'); localStorage.setItem('intus-frases-config', JSON.stringify(cfg)); return cfg || {}; }
    catch { return JSON.parse(localStorage.getItem('intus-frases-config') || '{}'); }
  },

  saveFrasesConfig: (cfg) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=frases_config', { method: 'PUT', body: JSON.stringify(cfg) });
      localStorage.setItem('intus-frases-config', JSON.stringify(cfg));
      return true;
    },
    () => { localStorage.setItem('intus-frases-config', JSON.stringify(cfg)); return true; }
  ),

  getFigurinhasConfig: async () => {
    try { const cfg = await _cfgDireto('figurinhas_config'); localStorage.setItem('intus-figurinhas-config', JSON.stringify(cfg)); return cfg || { itens: [] }; }
    catch { return JSON.parse(localStorage.getItem('intus-figurinhas-config') || '{"itens":[]}'); }
  },

  saveFigurinhasConfig: (cfg) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=figurinhas_config', { method: 'PUT', body: JSON.stringify(cfg) });
      localStorage.setItem('intus-figurinhas-config', JSON.stringify(cfg));
      return true;
    },
    () => { localStorage.setItem('intus-figurinhas-config', JSON.stringify(cfg)); return true; }
  ),

  // Sobe uma imagem (data URL) como ARQUIVO no servidor e devolve a URL pública.
  // NÃO cai em localStorage: se falhar, lança erro para o admin saber (mídia precisa ir pro servidor).
  uploadMidia: async (dataUrl) => {
    // Direto ao servidor (não depende do modo online): a mídia precisa ficar no servidor.
    const res = await _cfgDireto('midia_upload', 'POST', { data: dataUrl });
    if (!res || !res.url) throw new Error((res && res.error) || 'Falha no upload da imagem');
    return res.url;
  },

  // Salva uma config GLOBAL e CONFIRMA lendo de volta do servidor. Retorna { ok, persistido }.
  // Vai SEMPRE direto ao servidor (ignora o modo offline) — senão o admin perde os ajustes ao reiniciar.
  saveConfigVerificado: async (action, cfg, chaveLocal) => {
    try {
      await _cfgDireto(action, 'PUT', cfg);
      if (chaveLocal) localStorage.setItem(chaveLocal, JSON.stringify(cfg));
      // read-back de confirmação real no servidor
      let confirmado = true;
      try { const back = await _cfgDireto(action); if (back == null) confirmado = false; } catch { confirmado = false; }
      return { ok: true, persistido: confirmado };
    } catch (e) {
      if (chaveLocal) localStorage.setItem(chaveLocal, JSON.stringify(cfg));
      return { ok: true, persistido: false, erro: e.message || 'falha' };
    }
  },

  // Config de notificações (Automáticas + Config) — leitura direta do servidor.
  getNotifConfig: async () => {
    try { return await _cfgDireto('notif_config') || {}; }
    catch { return JSON.parse(localStorage.getItem('intus-notificacoes-config') || '{}'); }
  },

  getConquistasConfig: async () => {
    try { const cfg = await _cfgDireto('conquistas_config'); localStorage.setItem('intus-conquistas-config', JSON.stringify(cfg)); return cfg || { overrides: {} }; }
    catch { return JSON.parse(localStorage.getItem('intus-conquistas-config') || '{"overrides":{}}'); }
  },

  saveConquistasConfig: async (cfg) => {
    try { await _cfgDireto('conquistas_config', 'PUT', cfg); localStorage.setItem('intus-conquistas-config', JSON.stringify(cfg)); return true; }
    catch { localStorage.setItem('intus-conquistas-config', JSON.stringify(cfg)); return true; }
  },

  getCardioRegras: async () => {
    try { const cfg = await _cfgDireto('cardio_regras'); if (cfg && typeof cfg === 'object') localStorage.setItem('intus-cardio-regras', JSON.stringify(cfg)); return cfg || {}; }
    catch { return JSON.parse(localStorage.getItem('intus-cardio-regras') || '{}'); }
  },

  saveCardioRegras: async (cfg) => {
    try { await _cfgDireto('cardio_regras', 'PUT', cfg); localStorage.setItem('intus-cardio-regras', JSON.stringify(cfg)); return true; }
    catch { localStorage.setItem('intus-cardio-regras', JSON.stringify(cfg)); return true; }
  },

  // Regras de pontuação do ranking (pesos configuráveis) ───────────────
  getRankingRegras: async () => {
    try {
      const cfg = await _cfgDireto('ranking_regras');
      if (cfg && typeof cfg === 'object') localStorage.setItem('intus-ranking-regras', JSON.stringify(cfg));
      API.aplicarRegrasRanking(cfg);   // as telas passam a pontuar com o valor real
      return cfg || {};
    } catch {
      let cfg = {};
      try { cfg = JSON.parse(localStorage.getItem('intus-ranking-regras') || '{}'); } catch (e) {}
      API.aplicarRegrasRanking(cfg);
      return cfg;
    }
  },

  saveRankingRegras: async (cfg) => {
    API.aplicarRegrasRanking(cfg);
    try { await _cfgDireto('ranking_regras', 'PUT', cfg); localStorage.setItem('intus-ranking-regras', JSON.stringify(cfg)); return true; }
    catch { localStorage.setItem('intus-ranking-regras', JSON.stringify(cfg)); return true; }
  },

  // ═══════════════ PONTUAÇÃO DO RANKING — A FONTE ÚNICA ═══════════════
  // Esta fórmula estava escrita TRÊS vezes: no app do aluno, na tela de
  // frequência do professor e na tela de configuração do painel. Três cópias
  // da mesma regra é o mesmo que três regras: bastava mexer em uma para as
  // telas passarem a discordar sobre os pontos da mesma pessoa na mesma
  // semana. Agora existe um lugar só, e as três telas perguntam para cá.
  //
  // A regra, em português:
  //   • Musculação é a âncora. O 1º treino do dia vale musBase (metade se for
  //     mais curto que musCurtaMin). O 2º treino do MESMO DIA vale musSegundo.
  //     Do 3º em diante vale musTerceiro — por padrão zero, para ninguém
  //     empilhar sessão curta só para pontuar.
  //   • Cardio entra como complemento, com fator e teto.
  //   • Bônus semanal por constância: bonusPts ao treinar musculação em
  //     bonusDias DIAS DIFERENTES da semana (domingo a sábado).
  //   • Sessão marcada como nao_contar não pontua e não ocupa lugar na
  //     ordem do dia: um teste do professor não pode empurrar o treino de
  //     verdade do aluno para a posição de "segundo".
  //
  // Duas datas de corte, porque placar encerrado não se reescreve:
  //   vigorApartirDe  — quando a fórmula de faixas passou a valer.
  //   vigorSegundoDia — quando o 2º treino do dia passou a valer diferente e
  //                     o bônus passou a contar dias em vez de sessões.
  RANK_PADRAO: {
    vigorApartirDe: '2026-07-26',
    vigorSegundoDia: '2026-08-30',
    musBase: 10,
    musMeia: 5,
    musCurtaMin: 20,
    musSegundo: 5,
    musTerceiro: 0,
    cardioFator: 0.55,
    cardioTeto: 7,
    bonusDias: 4,
    bonusPts: 5,
    // Quadro de medalhas: pontos do 1º lugar em diante. A semana premia até o
    // 5º, o mês até o 10º — ganhar um mês exige constância de quatro semanas,
    // então vale bem mais que uma semana boa.
    medalhaSemana: [25, 18, 12, 7, 4],
    medalhaMes: [60, 42, 30, 20, 16, 13, 10, 8, 6, 4],
    // Data em que o quadro passou a contar. Separada da vigorApartirDe: aquela
    // é quando a fórmula de pontos mudou, esta é quando a turma passou a
    // disputar medalha.
    medalhaDesde: '2026-08-01',
  },

  _rankCache: null,

  // Regras em vigor. Lê o que o painel salvou (localStorage, alimentado por
  // getRankingRegras) por cima do padrão. Nunca devolve nulo: um valor
  // quebrado no painel não pode derrubar o ranking inteiro.
  regrasRanking: () => {
    if (API._rankCache) return API._rankCache;
    const R = Object.assign({}, API.RANK_PADRAO);
    let cfg = null;
    try { cfg = JSON.parse(localStorage.getItem('intus-ranking-regras') || 'null'); } catch (e) { cfg = null; }
    API._rankCache = API.mesclarRegrasRanking(R, cfg);
    return API._rankCache;
  },

  mesclarRegrasRanking: (base, cfg) => {
    const R = Object.assign({}, base || API.RANK_PADRAO);
    if (!cfg || typeof cfg !== 'object') return R;
    ['musBase','musMeia','musCurtaMin','musSegundo','musTerceiro','cardioFator','cardioTeto','bonusDias','bonusPts'].forEach((k) => {
      const v = cfg[k];
      if (v !== undefined && v !== null && v !== '' && !isNaN(Number(v))) R[k] = Number(v);
    });
    ['vigorApartirDe','vigorSegundoDia'].forEach((k) => {
      if (cfg[k] && /^\d{4}-\d{2}-\d{2}$/.test(String(cfg[k]))) R[k] = String(cfg[k]);
    });
    ['medalhaSemana','medalhaMes'].forEach((k) => {
      const v = cfg[k];
      if (v == null || v === '') return;
      let arr = Array.isArray(v) ? v : String(v).split(/[,;\s]+/);
      arr = arr.map((x) => Number(String(x).replace(',', '.'))).filter((x) => isFinite(x) && x >= 0);
      if (arr.length) R[k] = arr;
    });
    if (cfg.medalhaDesde && /^\d{4}-\d{2}-\d{2}$/.test(String(cfg.medalhaDesde))) R.medalhaDesde = String(cfg.medalhaDesde);
    return R;
  },

  // Chamar depois de buscar a config do servidor, para as telas recalcularem.
  aplicarRegrasRanking: (cfg) => {
    API._rankCache = API.mesclarRegrasRanking(API.RANK_PADRAO, cfg);
    API._ordemDiaCache = (typeof WeakMap !== 'undefined') ? new WeakMap() : null;
    return API._rankCache;
  },

  // ── classificação de uma sessão ──────────────────────────────────────
  tipoRank: (s) => (s && (s.tipo || (s.cardio_tipo ? 'cardio' : 'musculacao'))) || 'musculacao',
  ehMusculacaoRank: (s) => API.tipoRank(s) === 'musculacao',
  contaNoRank: (s) => Number(s && s.nao_contar) !== 1,
  dataRank: (s) => String((s && (s.dtsessao || s.dt)) || '').slice(0, 10),
  dtRank: (s) => (s && s.dt instanceof Date) ? s.dt : new Date(API.dataRank(s) + 'T00:00:00'),
  semanaRank: (dt) => {
    const d = new Date(dt); d.setHours(0, 0, 0, 0); d.setDate(d.getDate() - d.getDay());
    const mm = String(d.getMonth() + 1).padStart(2, '0'), dd = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + mm + '-' + dd;
  },
  // R opcional: passa as regras de um desafio específico (mescladas sobre o
  // padrão via mesclarRegrasRanking) em vez das regras globais do painel.
  pontosCardioRank: (raw, R) => {
    R = R || API.regrasRanking();
    return Math.min(Math.round((Number(raw) || 0) * R.cardioFator * 10) / 10, R.cardioTeto);
  },

  // ── ordem da musculação dentro do dia ────────────────────────────────
  // 1 = primeiro treino do dia, 2 = segundo, e assim por diante. O índice é
  // montado uma vez por lista e guardado num WeakMap: sem isso, perguntar a
  // ordem de cada sessão varreria a lista inteira toda vez, e a tela de
  // frequência percorre as sessões de todos os alunos de uma vez.
  _ordemDiaCache: (typeof WeakMap !== 'undefined') ? new WeakMap() : null,

  _chaveSessaoRank: (s) => (s && s.idsessao != null && s.idsessao !== '')
    ? 'id' + s.idsessao
    : 'c|' + API.dataRank(s) + '|' + (s && s.duracao_seg || 0) + '|' + (s && s.idficha || '') + '|' + (s && s.divisao || ''),

  _indiceOrdemDia: (lista) => {
    if (!Array.isArray(lista) || !lista.length) return null;
    if (API._ordemDiaCache) {
      const pronto = API._ordemDiaCache.get(lista);
      if (pronto) return pronto;
    }
    const grupos = new Map();
    lista.forEach((s, i) => {
      if (!API.ehMusculacaoRank(s) || !API.contaNoRank(s)) return;
      const g = Number((s && s.idatleta) || 0) + '|' + API.dataRank(s);
      if (!grupos.has(g)) grupos.set(g, []);
      grupos.get(g).push({ s: s, id: Number(s.idsessao) || null, i: i });
    });
    // Ordem = ordem em que os treinos foram feitos. idsessao cresce com o
    // tempo; sessão ainda não sincronizada não tem id e é a mais nova, então
    // vai para o fim. Empate resolve pela posição na lista, para o resultado
    // não depender da ordenação com que a tela chamou.
    const porObj = new Map(), porChave = new Map();
    grupos.forEach((arr) => {
      arr.sort((a, b) => {
        if (a.id != null && b.id != null) return a.id - b.id;
        if (a.id != null) return -1;
        if (b.id != null) return 1;
        return a.i - b.i;
      });
      arr.forEach((x, n) => {
        porObj.set(x.s, n + 1);
        const k = API._chaveSessaoRank(x.s);
        if (!porChave.has(k)) porChave.set(k, n + 1);
      });
    });
    const idx = { porObj: porObj, porChave: porChave };
    if (API._ordemDiaCache) API._ordemDiaCache.set(lista, idx);
    return idx;
  },

  ordemMusculacaoNoDia: (s, lista) => {
    if (!API.ehMusculacaoRank(s) || !API.contaNoRank(s)) return 0;
    const idx = API._indiceOrdemDia(lista);
    if (!idx) return 1;
    const porObj = idx.porObj.get(s);
    if (porObj) return porObj;
    const porChave = idx.porChave.get(API._chaveSessaoRank(s));
    return porChave || 1;
  },

  // ── pontos de UMA sessão ─────────────────────────────────────────────
  // `lista` é o conjunto de sessões do contexto (as do aluno, ou as de todos).
  // Sem ela não dá para saber se este é o primeiro ou o segundo treino do dia,
  // e a função devolve o valor do primeiro.
  // R opcional: regras de um desafio específico. Sem ela, usa o ranking geral
  // (comportamento de sempre — todo chamador existente continua igual).
  pontosSessaoRank: (s, lista, R) => {
    if (!s || !API.contaNoRank(s)) return 0;
    R = R || API.regrasRanking();
    const dt = API.dtRank(s);
    const legado = !(dt >= new Date(R.vigorApartirDe + 'T00:00:00'));
    if (API.ehMusculacaoRank(s)) {
      if (legado) return 5.0;
      if (dt >= new Date(R.vigorSegundoDia + 'T00:00:00')) {
        const ordem = API.ordemMusculacaoNoDia(s, lista);
        if (ordem >= 3) return Number(R.musTerceiro) || 0;
        if (ordem === 2) return Number(R.musSegundo) || 0;
      }
      const min = (Number(s.duracao_seg) || 0) / 60;
      if (min > 0 && min < R.musCurtaMin) return R.musMeia;
      return R.musBase;
    }
    const raw = parseFloat(s.pontos || 0);
    return legado ? raw : API.pontosCardioRank(raw, R);
  },

  // ── agregação por atleta (base + bônus) ──────────────────────────────
  // R opcional: regras de um desafio específico (ver rankingPontosDesafio).
  agregarPontosRank: (lista, R) => {
    R = R || API.regrasRanking();
    const corte = new Date(R.vigorApartirDe + 'T00:00:00');
    const corteDia = new Date(R.vigorSegundoDia + 'T00:00:00');
    const acc = {};
    (Array.isArray(lista) ? lista : []).forEach((s) => {
      const id = Number(s && s.idatleta);
      if (!acc[id]) acc[id] = { id: id, base: 0, bonus: 0, _sem: {} };
      acc[id].base += API.pontosSessaoRank(s, lista, R);
      if (API.ehMusculacaoRank(s) && API.contaNoRank(s)) {
        const k = API.semanaRank(API.dtRank(s));
        if (!acc[id]._sem[k]) {
          const ini = new Date(k + 'T00:00:00');
          acc[id]._sem[k] = { sessoes: 0, dias: {}, vale: ini >= corte, porDia: ini >= corteDia };
        }
        acc[id]._sem[k].sessoes++;
        acc[id]._sem[k].dias[API.dataRank(s)] = 1;
      }
    });
    Object.keys(acc).forEach((id) => {
      const a = acc[id];
      Object.keys(a._sem).forEach((k) => {
        const w = a._sem[k];
        if (!w.vale) return;
        // Semanas anteriores ao corte novo continuam contando SESSÕES, para o
        // pódio já encerrado não mudar de dono retroativamente.
        const quantos = w.porDia ? Object.keys(w.dias).length : w.sessoes;
        if (quantos >= R.bonusDias) a.bonus += R.bonusPts;
      });
      a.base = Math.round(a.base * 10) / 10;
      a.total = Math.round((a.base + a.bonus) * 10) / 10;
    });
    return acc;
  },

  // Dias distintos com musculação numa lista já filtrada por período.
  diasComMusculacaoRank: (lista) => {
    const dias = {};
    (Array.isArray(lista) ? lista : []).forEach((s) => {
      if (API.ehMusculacaoRank(s) && API.contaNoRank(s)) dias[API.dataRank(s)] = 1;
    });
    return Object.keys(dias);
  },

  // ── O NÚMERO QUE DECIDE TEM QUE SER O NÚMERO QUE APARECE ─────────────
  // A tela mostra pontos inteiros, então a comparação de empate também olha
  // o inteiro. Comparar duas casas decimais fazia 50,4 e 49,6 virarem 2º e 3º
  // mostrando "50" os dois — empate invisível que parecia sorteio.
  ptsRank: (v) => Math.round(Number(v) || 0),

  // Posição densa: quem mostra o mesmo número divide a colocação e a seguinte
  // não é pulada. A lista já chega ordenada por pontos.
  posicionarDenso: (arr) => {
    let pos = 0, anterior = null;
    (Array.isArray(arr) ? arr : []).forEach((r) => {
      const v = API.ptsRank(r.pts);
      if (v !== anterior) { pos += 1; anterior = v; r.igual = false; }
      else { r.igual = true; }
      r.pos = pos;
    });
    return arr;
  },

  // Planos Admin (personal, promoções, condições especiais) ────────────
  getPlanosAdmin: () => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch('/catalogo.php?action=planos_admin');
      if (Array.isArray(arr)) localStorage.setItem('intus-planos-admin', JSON.stringify(arr));
      return arr || [];
    },
    () => JSON.parse(localStorage.getItem('intus-planos-admin') || '[]')
  ),

  criarPlanoAdmin: (data) => tryRemoteOrLocal(
    async () => {
      const r = await apiFetch('/catalogo.php?action=planos_admin', { method: 'POST', body: JSON.stringify(data) });
      return r;
    },
    () => ({ ok: false })
  ),

  editarPlanoAdmin: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=planos_admin&id=' + id, { method: 'PUT', body: JSON.stringify(data) });
      return { ok: true };
    },
    () => ({ ok: false })
  ),

  excluirPlanoAdmin: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=planos_admin&id=' + id, { method: 'DELETE' });
      return { ok: true };
    },
    () => ({ ok: false })
  ),

  // Profile (avatar + prefs, backend-synced via catalogo.php) ─────────
  getAvatars: () => tryRemoteOrLocal(
    async () => {
      const map = await apiFetch('/catalogo.php?action=avatars');
      if (map && typeof map === 'object') localStorage.setItem('intus-avatars', JSON.stringify(map));
      return map || {};
    },
    () => JSON.parse(localStorage.getItem('intus-avatars') || '{}')
  ),

  getUserAvatars: () => tryRemoteOrLocal(
    async () => {
      const map = await apiFetch('/usuarios.php?action=avatars_usuarios');
      if (map && typeof map === 'object') {
        const local = JSON.parse(localStorage.getItem('intus-user-avatars') || '{}');
        const merged = { ...local, ...map };
        localStorage.setItem('intus-user-avatars', JSON.stringify(merged));
        return merged;
      }
      return JSON.parse(localStorage.getItem('intus-user-avatars') || '{}');
    },
    () => JSON.parse(localStorage.getItem('intus-user-avatars') || '{}')
  ),

  saveUserAvatar: (idusuario, avatar) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/usuarios.php?action=avatar&uid=' + idusuario, { method: 'POST', body: JSON.stringify({ idusuario, avatar }) });
      const map = JSON.parse(localStorage.getItem('intus-user-avatars') || '{}');
      if (avatar) map[idusuario] = avatar; else delete map[idusuario];
      localStorage.setItem('intus-user-avatars', JSON.stringify(map));
      return true;
    },
    () => {
      const map = JSON.parse(localStorage.getItem('intus-user-avatars') || '{}');
      if (avatar) map[idusuario] = avatar; else delete map[idusuario];
      localStorage.setItem('intus-user-avatars', JSON.stringify(map));
      return true;
    },
    { endpoint: '/usuarios.php?action=avatar&uid=' + idusuario, options: { method: 'POST', body: JSON.stringify({ idusuario, avatar }) } }
  ),

  saveUserCargo: (idusuario, cargo) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/usuarios.php?action=cargo&uid=' + idusuario, { method: 'POST', body: JSON.stringify({ idusuario, cargo }) });
      return true;
    },
    () => true
  ),

  saveUserMeta: (idusuario, meta) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/usuarios.php?action=user_meta&uid=' + idusuario, { method: 'POST', body: JSON.stringify({ idusuario, ...meta }) });
      return true;
    },
    () => true
  ),

  getProfile: (idatleta) => tryRemoteOrLocal(
    async () => {
      const p = await apiFetch('/catalogo.php?action=profile&atleta=' + idatleta);
      if (p) localStorage.setItem('intus-profile-' + idatleta, JSON.stringify(p));
      return p || {};
    },
    () => JSON.parse(localStorage.getItem('intus-profile-' + idatleta) || '{}')
  ),

  saveProfile: (idatleta, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=profile&atleta=' + idatleta, { method: 'PUT', body: JSON.stringify({ idatleta, ...data }) });
      const ex = JSON.parse(localStorage.getItem('intus-profile-' + idatleta) || '{}');
      localStorage.setItem('intus-profile-' + idatleta, JSON.stringify(Object.assign({}, ex, data)));
      return true;
    },
    () => {
      const ex = JSON.parse(localStorage.getItem('intus-profile-' + idatleta) || '{}');
      localStorage.setItem('intus-profile-' + idatleta, JSON.stringify(Object.assign({}, ex, data)));
      return true;
    }
  ),

  // Usuários (BACKEND-FIRST com cache local) ───────────────────────────
  // Backend é a fonte de verdade quando a sessão é remota. Quando offline
  // ou em sessão local, cai para o store local (Usuarios de _mock.js).
  usuarios: (function() {
    const URL = API_BASE + '/usuarios.php';

    function _useBackend() {
      // usuarios.php aceita qualquer Bearer não-vazio — inclusive os tokens
      // "local-{id}-{ts}" criados quando o login passa pela rota validar.
      // Isso garante sincronização entre dispositivos sempre que houver sessão.
      return Auth.isLogged();
    }
    function _headers() {
      return {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + (Auth.getToken() || ''),
      };
    }
    async function _backend(method, query, body) {
      const opts = { method, headers: _headers(), cache: 'no-store' };
      if (body) opts.body = JSON.stringify(body);
      const url = URL + (query ? ('?' + query) : '');
      const res = await fetch(url, opts);
      let json = null;
      try { json = await res.json(); } catch { json = null; }
      if (!res.ok) {
        const msg = (json && (json.error || json.erro)) || ('HTTP ' + res.status);
        throw new Error(msg);
      }
      return json;
    }
    function _cacheSync(lista) {
      // Sincroniza o store local com a lista do backend para que outras
      // páginas (que ainda usam o store) e o fallback offline fiquem em dia.
      try {
        if (typeof Usuarios !== 'undefined' && Usuarios._save) {
          Usuarios._save(lista);
        }
      } catch {}
    }

    return {
      listar: async () => {
        if (_useBackend()) {
          try {
            const r = await _backend('GET', '');
            if (r && r.ok && Array.isArray(r.usuarios)) {
              _cacheSync(r.usuarios);
              return r.usuarios;
            }
          } catch (e) { /* cai para local */ }
        }
        return Usuarios.listar();
      },

      get: async (id) => {
        if (_useBackend()) {
          try {
            const r = await _backend('GET', 'id=' + encodeURIComponent(id));
            if (r && r.ok && r.usuario) return r.usuario;
          } catch (e) { /* cai para local */ }
        }
        return Usuarios.get(id);
      },

      criar: async (d) => {
        if (_useBackend()) {
          try {
            const r = await _backend('POST', 'action=criar', d);
            if (r && r.ok && r.usuario) {
              // Atualiza cache
              try {
                const lista = Usuarios.listar().filter(u => Number(u.idusuario) !== Number(r.usuario.idusuario));
                lista.push(Object.assign({}, r.usuario, { senha: d.senha || '' }));
                Usuarios._save(lista);
              } catch {}
              return r.usuario;
            }
          } catch (e) { throw e; }
        }
        return Usuarios.criar(d);
      },

      editar: async (id, d) => {
        if (_useBackend()) {
          try {
            const r = await _backend('POST', 'action=editar&id=' + encodeURIComponent(id), d);
            if (r && r.ok && r.usuario) {
              // Atualiza cache, preservando a senha localmente para exibir no modal
              try {
                const lista = Usuarios.listar();
                const idx = lista.findIndex(u => Number(u.idusuario) === Number(id));
                const senhaCache = d.senha || (idx >= 0 ? (lista[idx].senha || '') : '');
                const merged = Object.assign({}, lista[idx] || {}, r.usuario, { senha: senhaCache });
                if (idx >= 0) lista[idx] = merged; else lista.push(merged);
                Usuarios._save(lista);
              } catch {}
              return r.usuario;
            }
          } catch (e) { throw e; }
        }
        return Usuarios.editar(id, d);
      },

      excluir: async (id) => {
        if (_useBackend()) {
          try {
            const r = await _backend('DELETE', 'id=' + encodeURIComponent(id));
            if (r && r.ok) {
              try { Usuarios.excluir(id); } catch {}
              return true;
            }
          } catch (e) { throw e; }
        }
        return Usuarios.excluir(id);
      },

      // Validação de login via backend (sem token) — usada no login.html
      validar: async (email, senha) => {
        try {
          const res = await fetch(URL + '?action=validar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, senha }),
            cache: 'no-store',
          });
          let json = null;
          try { json = await res.json(); } catch { json = null; }
          if (res.ok && json && json.ok && json.usuario) return json.usuario;
          return null;
        } catch (e) {
          return null;
        }
      },
    };
  })(),

  // ── MIGRAÇÃO localStorage → backend (one-shot) ──────────────────────
  // Empurra atletas e mensalidades do Store local para o backend, evitando
  // duplicar (matches por email para atletas, e por chave composta para mens).
  // Roda só se sessão for backend real (token não-local) e ainda não migrou.
  migrarLocalParaBackend: async function(opts) {
    opts = opts || {};
    if (Auth.isLocalToken()) return { skip: 'sessao_local' };
    if (!Auth.isLogged())   return { skip: 'sem_sessao' };

    const FLAG = 'intus-migrado-v1';
    if (!opts.force && localStorage.getItem(FLAG)) return { skip: 'ja_migrado' };

    const log = {
      atletas:      { criados: 0, ja_existem: 0, erros: 0, detalhes: [] },
      mensalidades: { criados: 0, ja_existem: 0, erros: 0, detalhes: [] },
    };

    // 1) Atletas
    let backendAtletas = [];
    try {
      backendAtletas = await apiFetch('/atletas.php');
    } catch (e) {
      return { erro: 'falha_listar_backend_atletas', detalhe: e.message };
    }
    const idxByEmail = new Map(
      (backendAtletas || [])
        .filter(a => a.email)
        .map(a => [String(a.email).toLowerCase(), a])
    );
    const idxByName = new Map(
      (backendAtletas || [])
        .filter(a => a.nome)
        .map(a => [String(a.nome).trim().toLowerCase(), a])
    );
    const localAtletas = Store.get('atletas') || [];
    const mapaIds = new Map(); // id local → id backend

    for (const a of localAtletas) {
      if (!a || !a.nome) continue;
      // Skip mock-origin athletes (from _mock.js seed)
      if (a.origem === 'mock') { log.atletas.ja_existem++; continue; }
      const chave = String(a.email || '').toLowerCase();
      const nomeNorm = String(a.nome).trim().toLowerCase();
      // Check by email OR by exact name match
      if (chave && idxByEmail.has(chave)) {
        log.atletas.ja_existem++;
        mapaIds.set(Number(a.idatleta), Number(idxByEmail.get(chave).idatleta));
        continue;
      }
      if (idxByName.has(nomeNorm)) {
        log.atletas.ja_existem++;
        mapaIds.set(Number(a.idatleta), Number(idxByName.get(nomeNorm).idatleta));
        continue;
      }
      try {
        const novo = await apiFetch('/atletas.php', {
          method: 'POST',
          body: JSON.stringify({
            nome:         a.nome,
            email:        a.email || '',
            telefone:     a.telefone || '',
            genero:       a.genero || 'M',
            dtnascimento: a.dtnascimento || null,
            codacesso:    a.codacesso || '',
            stbloqueio:   a.stbloqueio || 'N',
            observacao:   a.observacao || '',
          }),
        });
        log.atletas.criados++;
        if (novo && novo.idatleta) {
          mapaIds.set(Number(a.idatleta), Number(novo.idatleta));
        }
      } catch (e) {
        log.atletas.erros++;
        log.atletas.detalhes.push({ nome: a.nome, erro: e.message });
      }
    }

    // 2) Mensalidades
    let backendMens = [];
    try { backendMens = await apiFetch('/mensalidades.php'); } catch {}
    const setBackend = new Set(
      (backendMens || []).map(m =>
        Number(m.idatleta) + '|' + (m.dtvencimento || m.dtinicio || '') + '|' + Number(m.vlpagar || m.valor || 0)
      )
    );
    const localMens = Store.get('mensalidades') || [];
    for (const m of localMens) {
      const idAtletaBack = mapaIds.get(Number(m.idatleta)) || Number(m.idatleta);
      const k = idAtletaBack + '|' + (m.dtvencimento || '') + '|' + Number(m.vlpagar || 0);
      if (setBackend.has(k)) { log.mensalidades.ja_existem++; continue; }
      try {
        await apiFetch('/mensalidades.php', {
          method: 'POST',
          body: JSON.stringify({
            idatleta:     idAtletaBack,
            dshistorico:  m.dshistorico || 'Mensalidade',
            dtvencimento: m.dtvencimento,
            vlpagar:      Number(m.vlpagar || 0),
            vldesconto:   Number(m.vldesconto || 0),
            vljuro:       Number(m.vljuro || 0),
            stpgto:       m.stpgto || 'N',
            dtpagamento:  m.dtpagamento || null,
            vlpagamento:  m.vlpagamento != null ? Number(m.vlpagamento) : null,
            dspagamento:  m.dspagamento || null,
          }),
        });
        log.mensalidades.criados++;
      } catch (e) {
        log.mensalidades.erros++;
        log.mensalidades.detalhes.push({ idmensalidade: m.idmensalidade, erro: e.message });
      }
    }

    // 3) Exercise catalog — push local to backend if backend is empty
    let exLog = { pushed: 0, erros: 0 };
    const EX_FLAG = 'intus-exercicios-synced';
    if (!localStorage.getItem(EX_FLAG)) {
      try {
        const backendEx = await apiFetch('/treinos.php?action=exercicios').catch(() => null);
        if (!backendEx || !Array.isArray(backendEx) || backendEx.length === 0) {
          const localEx = Store.get('exercicios') || [];
          for (const ex of localEx) {
            if (!ex || !ex.nmexercicio) continue;
            try {
              await apiFetch('/treinos.php?action=exercicios', {
                method: 'POST',
                body: JSON.stringify({
                  nmexercicio:  ex.nmexercicio,
                  grupo:        ex.grupo || '',
                  grupos:       ex.grupos || null,
                  observacao:   ex.observacao || '',
                  descricao:    ex.descricao || '',
                  videoyoutube: ex.videoyoutube || '',
                  gifexercicio: ex.gifexercicio || '',
                  instrucao_ia: ex.instrucao_ia || '',
                  substitutos:  ex.substitutos || null,
                }),
              });
              exLog.pushed++;
            } catch { exLog.erros++; }
          }
          if (exLog.erros === 0) localStorage.setItem(EX_FLAG, new Date().toISOString());
        } else {
          localStorage.setItem(EX_FLAG, new Date().toISOString());
        }
      } catch {}
    }
    log.exercicios = exLog;

    if (log.atletas.erros === 0 && log.mensalidades.erros === 0) {
      localStorage.setItem(FLAG, new Date().toISOString());
    }
    return log;
  },
};

// ── ANAMNESE ─────────────────────────────────────────────────────────────
// Aqui moravam dois defeitos que, juntos, APAGAVAM o trabalho do aluno:
//
//   1. saveAnamnese engolia a falha num "catch {}" vazio e devolvia true. A
//      tela dizia "Anamnese salva com sucesso!" mesmo quando nada tinha
//      chegado ao servidor. O aluno saia da tela convencido de que respondeu.
//   2. getAnamnese gravava por cima da copia local o que viesse do servidor.
//      Entao, na vez seguinte que a tela abria, a resposta do aluno — que so
//      existia no aparelho, porque o envio tinha falhado — era substituida
//      pelo conteudo velho do servidor. O rascunho dele sumia sem aviso.
//
// Agora: o erro sobe para quem chamou, e a copia local nunca e sobrescrita por
// uma versao mais antiga. O rascunho fica guardado em chave propria ate o
// servidor confirmar o recebimento.
const RASCUNHO_ANAMNESE = 'intus-anamnese-rascunho-';

function _tsAnamnese(o) {
  try { return o && o._timestamp ? Date.parse(o._timestamp) || 0 : 0; } catch (e) { return 0; }
}

API.getAnamnese = async function(idatleta) {
  const chave = 'intus-anamnese-' + idatleta;
  let local = null;
  try { local = JSON.parse(localStorage.getItem(chave) || 'null'); } catch (e) {}
  try {
    const resp = await apiFetch('/catalogo.php?action=anamnese&atleta=' + idatleta);
    if (resp && typeof resp === 'object' && Object.keys(resp).length > 0) {
      // So sobrescreve o que esta no aparelho se o servidor tiver algo MAIS
      // NOVO. Vindo mais velho, o aparelho manda — e o mais novo dali e
      // justamente a resposta que ainda nao subiu.
      if (_tsAnamnese(resp) >= _tsAnamnese(local)) {
        localStorage.setItem(chave, JSON.stringify(resp));
        return resp;
      }
      return local;
    }
  } catch (e) {}
  return local;
};

API.saveAnamnese = async function(idatleta, data) {
  data._idatleta = idatleta;
  data._timestamp = data._timestamp || new Date().toISOString();
  const chave = 'intus-anamnese-' + idatleta;
  localStorage.setItem(chave, JSON.stringify(data));
  // Rascunho: some so quando o servidor confirmar. Enquanto existir, a tela
  // sabe que ha resposta neste aparelho que o servidor nao tem.
  try { localStorage.setItem(RASCUNHO_ANAMNESE + idatleta, JSON.stringify(data)); } catch (e) {}
  const resp = await apiFetch('/catalogo.php?action=anamnese', { method: 'POST', body: JSON.stringify(data) });
  try { localStorage.removeItem(RASCUNHO_ANAMNESE + idatleta); } catch (e) {}
  // Devolve a resposta do servidor. O campo "alterado" diz se a gravacao mudou
  // alguma coisa — quem sincroniza usa isso para parar de reenviar a mesma
  // copia a cada abertura do aplicativo.
  return (resp && typeof resp === 'object') ? resp : { ok: true };
};

// Rascunhos guardados NESTE aparelho que nunca chegaram ao servidor —
// inclusive os de outra conta, no caso de aparelho que trocou de dono ou que
// ficou entrado no login de outra pessoa.
API.rascunhosAnamneseLocais = function() {
  const out = [];
  try {
    for (let i = 0; i < localStorage.length; i++) {
      const k = localStorage.key(i);
      if (!k || k.indexOf(RASCUNHO_ANAMNESE) !== 0) continue;
      let o = null;
      try { o = JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { continue; }
      if (!o || typeof o !== 'object') continue;
      const n = Object.keys(o).filter(x => x[0] !== '_' && String(o[x] == null ? '' : o[x]).trim() !== '').length;
      if (!n) continue;
      out.push({ chave: k, idatleta: Number(k.slice(RASCUNHO_ANAMNESE.length)) || 0, respostas: n, quando: o._timestamp || '', dados: o });
    }
  } catch (e) {}
  return out.sort((a, b) => String(b.quando).localeCompare(String(a.quando)));
};

// ─────────────────────────────────────────────────────────────────────────────
// EXPOR NO WINDOW — nao remova.
// API, Auth, Sessao e Store sao declarados com `const`, e const de topo NAO cria
// propriedade em window. Varias telas testam `if (window.API && ...)` antes de
// usar; como window.API era undefined, o teste dava falso SEMPRE e a tela caia
// no plano B silencioso — mostrando valores padrao em vez dos salvos no
// servidor. Era por isso que Notificacoes exibia figurinhas ja excluidas e
// sessao curta de 20min, e o Inicio contava ativos pela regra velha.
// ─────────────────────────────────────────────────────────────────────────────
try {
  window.API = API;
  if (typeof Auth   !== 'undefined') window.Auth   = Auth;
  if (typeof Sessao !== 'undefined') window.Sessao = Sessao;
  if (typeof Store  !== 'undefined') window.Store  = Store;
} catch (e) {}

// ── SYNC EXERCÍCIOS (roda independente da migração geral) ────────────────
API.syncExerciseCatalog = async function() {
  if (Auth.isLocalToken() || !Auth.isLogged()) return;
  const EX_FLAG = 'intus-exercicios-synced';
  if (localStorage.getItem(EX_FLAG)) return;
  try {
    const backendEx = await apiFetch('/treinos.php?action=exercicios').catch(() => null);
    if (!backendEx || !Array.isArray(backendEx) || backendEx.length === 0) {
      const localEx = Store.get('exercicios') || [];
      if (localEx.length === 0) return;
      let ok = 0;
      for (const ex of localEx) {
        if (!ex || !ex.nmexercicio) continue;
        try {
          await apiFetch('/treinos.php?action=exercicios', {
            method: 'POST',
            body: JSON.stringify({
              nmexercicio: ex.nmexercicio, grupo: ex.grupo || '', grupos: ex.grupos || null,
              observacao: ex.observacao || '', descricao: ex.descricao || '',
              videoyoutube: ex.videoyoutube || '', gifexercicio: ex.gifexercicio || '',
              instrucao_ia: ex.instrucao_ia || '', substitutos: ex.substitutos || null,
            }),
          });
          ok++;
        } catch {}
      }
      if (ok > 0) localStorage.setItem(EX_FLAG, new Date().toISOString());
    } else {
      Store.set('exercicios', backendEx);
      localStorage.setItem(EX_FLAG, new Date().toISOString());
    }
  } catch {}
};

// ── GUARD DE AUTENTICAÇÃO ────────────────────────────────────────────────
// Chame no topo de cada página protegida (exceto login.html)
function requireLogin() {
  if (!Auth.isLogged()) {
    window.location.href = 'login.html';
    return;
  }
  // Aluno tentando acessar painel do professor → redireciona para app do aluno
  try {
    const u = JSON.parse(localStorage.getItem('mx-user') || '{}');
    if (u.tipo === 'aluno' && !window.location.pathname.includes('aluno.html')) {
      window.location.href = 'aluno.html';
    }
  } catch {}
  // Sync usuários do backend para manter permissões atualizadas
  if (!Auth.isStudentToken()) {
    _syncUsuariosBackground();
  }
}

function _syncUsuariosBackground() {
  const lastSync = Number(localStorage.getItem('intus-usuarios-sync-ts') || 0);
  if (Date.now() - lastSync < 300000) return;
  localStorage.setItem('intus-usuarios-sync-ts', String(Date.now()));
  fetch(API_BASE + '/usuarios.php', {
    headers: { 'Authorization': 'Bearer ' + (Auth.getToken() || ''), 'Cache-Control': 'no-cache' },
    cache: 'no-store',
  }).then(r => r.json()).then(d => {
    if (d && d.ok && Array.isArray(d.usuarios)) {
      if (typeof Usuarios !== 'undefined' && Usuarios._save) {
        Usuarios._save(d.usuarios);
      }
      // Se permissões do usuário atual mudaram, atualiza mx-user
      try {
        const cur = JSON.parse(localStorage.getItem('mx-user') || '{}');
        if (cur.idusuario) {
          const fresh = d.usuarios.find(u => Number(u.idusuario) === Number(cur.idusuario));
          if (fresh && fresh.permissoes) {
            const curPerms = JSON.stringify(cur.permissoes || {});
            const newPerms = JSON.stringify(fresh.permissoes);
            if (curPerms !== newPerms) {
              cur.permissoes = fresh.permissoes;
              if (fresh.admin !== undefined) cur.admin = fresh.admin;
              localStorage.setItem('mx-user', JSON.stringify(cur));
              window.location.reload();
            }
          }
        }
      } catch {}
    }
  }).catch(() => {});
}
