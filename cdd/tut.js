/* "?" do T.U.T no treino da aluna do CDD: explica o tempo sob tensão, as 4 fases e mostra a cadência numa animação. Cópia adaptada de
   abrirAjudaTut (app/painel/aluno.html), que fica em outro domínio e não é alcançável daqui: se mudar um texto ou uma regra lá,
   mude aqui também (as regras de leitura têm teste em testes/tut.js no repositório principal). Cores vêm das variáveis do tema do CDD.
   Uso: CDDTut.abrir(valorDoTUT, abrirModal). */
(function () {
  "use strict";
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function _tutLer(bruto) {
    const t = String(bruto == null ? '' : bruto).trim();
    if (/^[\s\-–—xX]*$/.test(t)) return { tipo: 'iso', dig: '' };
    if (/^[0-9][0-9\s.:\-\/,]*$/.test(t)) { const d = t.replace(/[^0-9]/g, ''); if (d.length === 4) return { tipo: 'ok', dig: d }; }
    return { tipo: 'outro', dig: '' };
  }
  const _TUT_FASES = [
    { nome: 'Alongando o músculo', cel: 'alonga' },
    { nome: 'Pausa, músculo alongado', cel: 'pausa' },
    { nome: 'Contraindo o músculo', cel: 'contrai' },
    { nome: 'Pausa, músculo contraído', cel: 'pausa' },
  ];
  // Cotovelo/joelho de uma cadeia de 2 ossos (a-b), do lado mais perto de (hx, hy).
  function _tutIk(ax, ay, bx, by, l1, l2, hx, hy) {
    const dx = bx - ax, dy = by - ay, dist = Math.hypot(dx, dy) || 1;
    const d = Math.min(Math.max(dist, Math.abs(l1 - l2) + 0.5), l1 + l2 - 0.01);
    const a = (l1 * l1 - l2 * l2 + d * d) / (2 * d), h = Math.sqrt(Math.max(l1 * l1 - a * a, 0));
    const ux = dx / dist, uy = dy / dist, px = ax + ux * a, py = ay + uy * a;
    const c1 = [px - uy * h, py + ux * h], c2 = [px + uy * h, py - ux * h];
    return Math.hypot(c1[0] - hx, c1[1] - hy) <= Math.hypot(c2[0] - hx, c2[1] - hy) ? c1 : c2;
  }
  // k = 0: músculo contraído; k = 1: músculo alongado. Cada exercício devolve as linhas do corpo, a cabeça, o peso e o cenário.
  const _TUT_DEMOS = [
    { nome: 'Agachamento', t: [3, 1, 1, 0], nota: 'Ao descer, o músculo alonga segurando a barra (fase 1). Ao levantar, ele contrai (fase 3).',
      pose: function (k) {
        const A = [100, 146], hip = [100 - 28 * k, 66 + 44 * k], kn = _tutIk(A[0], A[1], hip[0], hip[1], 40, 40, hip[0] + 40, hip[1]);
        const lean = (5 + 40 * k) * Math.PI / 180, sh = [hip[0] + 44 * Math.sin(lean), hip[1] - 44 * Math.cos(lean)];
        return { fixo: [[88, 148, 116, 148]], l: [[A[0], A[1], kn[0], kn[1]], [kn[0], kn[1], hip[0], hip[1]], [hip[0], hip[1], sh[0], sh[1]]],
          cab: [sh[0] + 10 * Math.sin(lean), sh[1] - 10 * Math.cos(lean) - 4], peso: [sh[0], sh[1] - 5] };
      } },
    { nome: 'Supino reto', t: [3, 0, 1, 0], nota: 'A barra desce controlada, alongando o peito (fase 1), e sobe com força, contraindo (fase 3).',
      pose: function (k) {
        const S = [62, 100], H = [S[0], S[1] - (52 - 40 * k)], E = _tutIk(S[0], S[1], H[0], H[1], 28, 28, S[0] + 30, S[1] + 16);
        return { fixo: [[40, 108, 135, 108], [60, 108, 60, 148], [130, 108, 130, 148]],
          l: [[S[0], S[1], 118, 100], [118, 100, 142, 86], [142, 86, 150, 110], [S[0], S[1], E[0], E[1]], [E[0], E[1], H[0], H[1]]], cab: [48, 98], peso: H };
      } },
    { nome: 'Rosca direta', t: [3, 0, 2, 1], nota: 'O halter desce devagar, alongando o bíceps (fase 1), e sobe apertando no alto (fases 3 e 4).',
      pose: function (k) {
        const f = 150 * (1 - k) * Math.PI / 180, E = [104, 84], H = [E[0] + 28 * Math.sin(f), E[1] + 28 * Math.cos(f)];
        return { fixo: [[88, 148, 114, 148]], l: [[100, 146, 100, 92], [100, 92, 100, 54], [100, 56, E[0], E[1]], [E[0], E[1], H[0], H[1]]], cab: [100, 43], peso: H };
      } },
    { nome: 'Puxada na frente', t: [3, 1, 1, 0], nota: 'Aqui é o contrário: na fase 1 a barra volta para cima (o dorsal alonga). Puxá-la para baixo é a fase 3.',
      pose: function (k) {
        const S = [98, 82], H = [112, 92 - 62 * k], E = _tutIk(S[0], S[1], H[0], H[1], 28, 28, S[0] - 22, S[1] + 36);
        return { fixo: [[78, 126, 124, 126], [128, 148, 152, 148], [112, 4, 112, H[1]]],
          l: [[96, 120, 98, 82], [96, 120, 132, 118], [132, 118, 136, 148], [S[0], S[1], E[0], E[1]], [E[0], E[1], H[0], H[1]]], cab: [98, 68], peso: H };
      } },
    { nome: 'Prancha', iso: true, t: [0, 0, 0, 0], nota: 'Exercício isométrico: o músculo trabalha parado, sem fases. Por isso o T.U.T fica em branco. Segure a posição.',
      pose: function () {
        return { fixo: [[20, 146, 206, 146]], l: [[48, 140, 76, 140], [76, 140, 76, 112], [76, 112, 120, 122], [120, 122, 174, 138]], cab: [64, 103], peso: null };
      } },
  ];
  var _tutDemoEst = null;
  function _tutDemoEscolher(i) {
    const est = _tutDemoEst; if (!est) return;
    est.i = i; est.t0 = performance.now(); est.manual = true; est.fase = -2;
    _tutDemoMontar(est);
  }
  function _tutDemoMontar(est) {
    const d = _TUT_DEMOS[est.i], c = est.c;
    const chips = document.getElementById('tut-demo-chips'), cels = document.getElementById('tut-demo-cels'), nota = document.getElementById('tut-demo-nota');
    if (!chips) return;
    chips.innerHTML = '<span style="align-self:center;font-size:11px;font-weight:700;color:' + c.sub + ';margin-right:2px;">Exemplos:</span>' + _TUT_DEMOS.map(function (x, i) {
      const on = i === est.i;
      return '<button type="button" onclick="CDDTut.escolher(' + i + ')" style="flex:0 0 auto;border:none;cursor:pointer;font-family:inherit;font-size:12px;font-weight:800;padding:6px 11px;border-radius:99px;background:' + (on ? c.on : c.chip) + ';color:' + (on ? c.onTx : c.sub) + ';">' + x.nome + '</button>';
    }).join('');
    cels.style.display = d.iso ? 'none' : 'flex';
    cels.innerHTML = _TUT_FASES.map(function (f, j) {
      return '<div data-cel style="flex:1;position:relative;overflow:hidden;text-align:center;border-radius:10px;padding:6px 2px 9px;background:' + c.chip + ';color:' + c.ink + ';' + (d.t[j] === 0 ? 'opacity:.5;' : '') + '">' +
        '<div style="font-size:21px;font-weight:900;line-height:1.1;">' + d.t[j] + '</div>' +
        '<div style="font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;">' + f.cel + '</div>' +
        '<div data-prog style="position:absolute;left:0;bottom:0;height:3px;width:0;background:' + c.onTx + ';opacity:.55;"></div></div>';
    }).join('');
    nota.textContent = d.nota;
  }
  function _tutDemoIniciar(c) {
    const est = { i: 0, t0: performance.now(), manual: false, fase: -2, c: c };
    _tutDemoEst = est;
    _tutDemoMontar(est);
    const svg = document.getElementById('tut-demo-svg'), rot = document.getElementById('tut-demo-fase');
    const ln = function (l, cor, w) { return '<line x1="' + l[0].toFixed(1) + '" y1="' + l[1].toFixed(1) + '" x2="' + l[2].toFixed(1) + '" y2="' + l[3].toFixed(1) + '" stroke="' + cor + '" stroke-width="' + w + '" stroke-linecap="round"/>'; };
    function quadro(ts) {
      if (est !== _tutDemoEst || !document.getElementById('tut-demo-svg')) return;
      const d = _TUT_DEMOS[est.i], s = (ts - est.t0) / 1000;
      let k = 0, fase = -1, fr = 0;
      const T = d.t.reduce(function (a, b) { return a + b; }, 0);
      if (!d.iso && T > 0) {
        const ciclo = Math.floor(s / T);
        if (!est.manual && ciclo >= 2) { est.i = (est.i + 1) % _TUT_DEMOS.length; est.t0 = ts; est.fase = -2; _tutDemoMontar(est); requestAnimationFrame(quadro); return; }
        const resto = s - ciclo * T;
        let acc = 0;
        for (let j = 0; j < 4; j++) { if (resto < acc + d.t[j]) { fase = j; fr = (resto - acc) / d.t[j]; break; } acc += d.t[j]; }
        k = fase === 0 ? fr : fase === 1 ? 1 : fase === 2 ? 1 - fr : 0;
        document.querySelectorAll('#tut-demo-cels [data-cel]').forEach(function (el, j) {
          const on = j === fase;
          if (est.fase !== fase) { el.style.background = on ? c.on : c.chip; el.style.color = on ? c.onTx : c.ink; }
          const p = el.querySelector('[data-prog]'); if (p) p.style.width = (on ? fr * 100 : 0) + '%';
        });
        est.fase = fase;
        rot.textContent = _TUT_FASES[fase].nome + ' · ' + Math.max(1, Math.ceil(d.t[fase] * (1 - fr))) + ' s';
      } else {
        if (!est.manual && s > 7) { est.i = (est.i + 1) % _TUT_DEMOS.length; est.t0 = ts; est.fase = -2; _tutDemoMontar(est); requestAnimationFrame(quadro); return; }
        rot.textContent = 'Segurando a posição, sem fases';
      }
      const p = d.pose(k);
      let g = '<line x1="14" y1="150" x2="206" y2="150" stroke="' + c.sub + '" stroke-width="2" stroke-linecap="round" opacity=".5"/>';
      p.fixo.forEach(function (l) { g += ln(l, c.sub, 3); });
      p.l.forEach(function (l) { g += ln(l, c.ink, 5.5); });
      g += '<circle cx="' + p.cab[0].toFixed(1) + '" cy="' + p.cab[1].toFixed(1) + '" r="8.5" fill="' + c.ink + '"/>';
      if (p.peso) g += '<circle cx="' + p.peso[0].toFixed(1) + '" cy="' + p.peso[1].toFixed(1) + '" r="6.5" fill="' + c.on + '" stroke="' + c.ink + '" stroke-width="1.5"/>';
      svg.innerHTML = g;
      requestAnimationFrame(quadro);
    }
    requestAnimationFrame(quadro);
  }
  function abrir(bruto, abrirModal) {
    let tut = bruto; try { tut = decodeURIComponent(String(bruto == null ? '' : bruto)); } catch (e) {}
    const cs = getComputedStyle(document.documentElement), cor = function (n, def) { return (cs.getPropertyValue(n) || '').trim() || def; };
    const ink = cor('--fg', '#f4f2f0'), sub = cor('--muted', '#a09cab'), acento = cor('--accent', '#e2272d'), fundo = cor('--card2', '#1f1f28'), acentoTx = cor('--accent-fg', '#ffffff');
    const lido = _tutLer(tut), dig = lido.dig, valido = lido.tipo === 'ok', isometrico = lido.tipo === 'iso';
    const seg = (c) => c === '0' ? 'nenhuma' : c + (c === '1' ? ' segundo' : ' segundos');
    const fases = [
      ['Excêntrica', 'O músculo se alonga enquanto segura o peso, freando a carga. Quanto mais controle, mais ele trabalha.'],
      ['Pausa com o músculo alongado', 'Segura um instante antes de inverter o movimento. Zero: inverte direto.'],
      ['Concêntrica', 'O músculo se contrai e vence o peso: é o esforço de empurrar, puxar ou subir a carga.'],
      ['Pausa com o músculo contraído', 'Segura um instante apertando o músculo. Zero: já recomeça.'],
    ];
    const linhas = fases.map(function (f, i) {
      return '<div style="display:flex;gap:12px;align-items:flex-start;background:' + fundo + ';border-radius:12px;padding:10px 12px;margin-bottom:8px;">' +
        '<div style="flex-shrink:0;width:32px;height:32px;border-radius:50%;background:' + fundo + ';border:1.5px solid ' + acento + ';color:' + acento + ';font-weight:900;font-size:16px;display:flex;align-items:center;justify-content:center;">' + (valido ? esc(dig[i]) : (i + 1)) + '</div>' +
        '<div style="flex:1;min-width:0;"><div style="font-size:13.5px;font-weight:800;color:' + ink + ';">' + f[0] + (valido ? ' <span style="color:' + acento + ';font-weight:700;">· ' + esc(seg(dig[i])) + '</span>' : '') + '</div>' +
        '<div style="font-size:12.5px;line-height:1.5;color:' + sub + ';margin-top:2px;">' + f[1] + '</div></div></div>';
    }).join('');
    const caixa = 'border:1.5px solid ' + acento + ';border-radius:12px;padding:12px 14px;margin:4px 0 14px;font-size:13px;line-height:1.55;color:' + ink + ';';
    const caixaMini = 'border:1px solid ' + sub + '66;border-radius:10px;padding:8px 11px;margin:12px 0 0;font-size:11.5px;line-height:1.5;color:' + sub + ';';
    let pratica = '', avisoFim = '';
    if (valido) {
      const total = dig.split('').reduce(function (s, c) { return s + Number(c); }, 0);
      pratica = '<div style="' + caixa + '"><b>No seu exercício: ' + esc(dig) + '.</b> Em cada repetição: alongando o músculo, ' + esc(seg(dig[0])) + '; pausa alongado, ' + esc(seg(dig[1])) +
        '; contraindo, ' + esc(seg(dig[2])) + '; pausa contraído, ' + esc(seg(dig[3])) + '.' + (total > 0 ? ' Dá cerca de <b>' + total + ' segundos por repetição</b>.' : '') + '</div>';
    } else if (isometrico) {
      avisoFim = '<div style="' + caixaMini + '"><b>Exercício sem T.U.T.</b> Com o campo em branco, o exercício é isométrico, como a prancha: o músculo trabalha segurando a posição parada, sem as 4 fases. Mantenha a posição pelo tempo da prescrição, com o corpo firme e respirando normalmente.</div>';
    } else {
      avisoFim = '<div style="' + caixaMini + '">O T.U.T deste exercício está num formato diferente de 4 números. Se ficar em dúvida, pergunte ao seu professor.</div>';
    }
    const c = { ink: ink, sub: sub, on: acento, onTx: acentoTx, chip: cor('--line', '#2a2a35') };
    const demo = '<div style="background:' + fundo + ';border-radius:14px;padding:10px 10px 12px;margin:0 0 14px;">' +
      '<div id="tut-demo-chips" style="display:flex;flex-wrap:wrap;gap:6px;padding-bottom:6px;"></div>' +
      '<div style="position:relative;"><svg id="tut-demo-svg" viewBox="0 0 220 156" role="img" aria-label="Animação da cadência do movimento" style="width:100%;height:170px;display:block;"></svg><img class="logo-escuro" src="marca/logo-cdd-branca.png" alt="Código da Definição" style="position:absolute;top:2px;right:4px;width:76px;height:auto;opacity:.9;pointer-events:none;"><img class="logo-claro" src="marca/logo-cdd-preta.png" alt="Código da Definição" style="position:absolute;top:2px;right:4px;width:76px;height:auto;opacity:.9;pointer-events:none;"></div>' +
      '<div id="tut-demo-fase" style="text-align:center;font-size:14px;font-weight:800;color:' + ink + ';min-height:20px;margin:2px 0 8px;"></div>' +
      '<div id="tut-demo-cels" style="display:flex;gap:6px;"></div>' +
      '<div id="tut-demo-nota" style="font-size:12px;line-height:1.5;color:' + sub + ';margin-top:9px;"></div></div>';
    const corpo =
      '<div style="font-size:13.5px;line-height:1.6;color:' + ink + ';margin-bottom:12px;"><b>T.U.T</b> é o <b>Time Under Tension</b>, o tempo sob tensão: quanto tempo o músculo trabalha em cada repetição. ' +
      'Controlar a velocidade deixa o exercício mais eficiente do que só levantar o peso. O número tem 4 dígitos, os segundos de cada fase, sempre nesta ordem:</div>' +
      pratica + demo + linhas +
      '<div style="font-size:12px;line-height:1.5;color:' + sub + ';margin-top:6px;">Pense sempre no músculo, não na direção do peso: a fase 1 é a que alonga o músculo e a fase 3 a que o contrai. ' +
      'Em alguns exercícios, como a puxada, a fase 1 é a subida da barra. Dica: conte os segundos mentalmente ("um, dois, três") em cada fase.</div>' +
    '<div style="font-size:12.5px;line-height:1.5;color:' + ink + ';margin-top:12px;padding:10px 12px;border-left:3px solid ' + acento + ';background:' + fundo + ';border-radius:0 10px 10px 0;"><b>Atenção:</b> o T.U.T pode mudar conforme a orientação do seu professor, e algumas técnicas de treino têm variações no T.U.T. Por isso, preste sempre atenção na marcação de cada exercício.</div>' + avisoFim;
    const m = abrirModal('<h3 style="margin-bottom:12px">⏱️ Tempo sob tensão</h3>' + corpo + '<div class="espaco"></div><button class="btn grande" data-f>Entendi</button>');
    m.querySelector('[data-f]').onclick = m.fechar;
    _tutDemoIniciar(c);
  }

  window.CDDTut = { abrir: abrir, escolher: _tutDemoEscolher };
})();
