/* Comparativo de avaliações do aluno (2 ou mais), 26/11/2026.
 *
 * O aluno marca quantas avaliações quiser (a partir de 2) e vê a evolução no tempo: resumo do primeiro ao último registro, gráfico de linha de
 * uma medida por vez, tabela com uma coluna por avaliação (primeira coluna fixa) e a variação, mais as fotos de cada data.
 * Antes só dava para comparar DUAS avaliações, lado a lado, sem ver o que veio no meio.
 *
 * As contas ficam em funções puras (ordenar, linhas, resumo) para testar sem tela. A tela desenha dentro de #v-avaliacoes, como os outros
 * passos da área de Avaliações: o primeiro elemento é o botão Voltar (o voltar do celular usa esse botão).
 *
 * Depende de funções do aluno.html: esc, toast, getFooterHtml, _urlAbs, _comSheet, abrirComparativoFotos, renderAvaliacoesAluno, API.ordenarDobras.
 */
(function () {
  'use strict';

  var S = { sel: {}, metrica: 'peso', resultado: false };       // sel: chave da avaliação -> true

  var CIRC = [
    ['pescoco', 'Pescoço'], ['ombro', 'Ombro'], ['torax', 'Tórax'], ['cintura', 'Cintura'], ['abdomen', 'Abdômen'], ['quadril', 'Quadril'],
    ['braco_d', 'Braço D'], ['braco_e', 'Braço E'], ['antebraco_d', 'Antebraço D'], ['antebraco_e', 'Antebraço E'],
    ['coxa_d', 'Coxa D'], ['coxa_e', 'Coxa E'], ['panturrilha_d', 'Panturrilha D'], ['panturrilha_e', 'Panturrilha E']
  ];
  var ROT_DOBRA = { dc_peitoral: 'Peitoral', dc_axilar: 'Axilar média', dc_tricipital: 'Tricipital', dc_subescapular: 'Subescapular', dc_abdominal: 'Abdominal', dc_suprailiaca: 'Suprailíaca', dc_coxa: 'Coxa' };

  function $(id) { return document.getElementById(id); }
  function luz() { return document.body.classList.contains('tema-claro'); }
  function num(v) { if (v == null || v === '') return null; var n = parseFloat(String(v).replace(',', '.')); return isFinite(n) ? n : null; }
  function fmt(n, d) { return n == null ? '–' : Number(n).toFixed(d == null ? 1 : d).replace('.', ','); }
  function dtBR(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : '–'; }
  function dtCurta(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '/' + m[2] : '–'; }
  function dtMs(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : null; }
  function chave(av, i) { return String(av.idavaliacao != null ? av.idavaliacao : 'i' + i); }

  // ═══════════════ CONTAS (funções puras) ═══════════════
  function medidasDe(av) { return av && av.medidas && typeof av.medidas === 'object' ? av.medidas : {}; }
  function somaDobras(av) {
    var m = medidasDe(av), t = 0, n = 0;
    Object.keys(m).forEach(function (k) { if (k.indexOf('dc_') === 0 && num(m[k]) != null) { t += num(m[k]); n++; } });
    return n ? t : null;
  }
  function temDados(av) {
    if (num(av.peso) != null || num(av.percgordura) != null) return true;
    var m = medidasDe(av);
    return Object.keys(m).some(function (k) { return num(m[k]) != null; });
  }
  // Só entram avaliações com números. O comparativo de fotos montado pelo aluno e as avaliações só de fotos ficam de fora (têm tela própria).
  function candidatas(lista) {
    return (lista || []).filter(function (av) { return av && !/comparativo/i.test(String(av.metodo || '')) && temDados(av); });
  }
  function ordenar(avs) {          // da mais antiga para a mais nova
    return avs.slice().sort(function (a, b) { return String(a.dtavaliacao || '').localeCompare(String(b.dtavaliacao || '')) || (Number(a.idavaliacao) || 0) - (Number(b.idavaliacao) || 0); });
  }
  function valorDe(av, id) {
    var p = num(av.peso), g = num(av.percgordura);
    if (id === 'peso') return p;
    if (id === 'gord') return g;
    if (id === 'magra') return p != null && g != null ? p * (1 - g / 100) : null;
    if (id === 'gorda') return p != null && g != null ? p * (g / 100) : null;
    if (id === 'dobras') return somaDobras(av);
    return num(medidasDe(av)[id]);
  }
  // melhor: 'menos' (queda é bom), 'mais' (alta é bom) ou null (sem julgamento: o objetivo de cada pessoa é diferente)
  var METRICAS = [
    { id: 'peso', rot: 'Peso', un: 'kg', d: 1, melhor: null, grupo: 'comp' },
    { id: 'gord', rot: '% Gordura', un: '%', d: 1, melhor: 'menos', grupo: 'comp' },
    { id: 'magra', rot: 'Massa magra', un: 'kg', d: 1, melhor: 'mais', grupo: 'comp' },
    { id: 'gorda', rot: 'Massa gorda', un: 'kg', d: 1, melhor: 'menos', grupo: 'comp' }
  ];
  function metricasDe(avs) {
    var out = METRICAS.slice();
    var m = {}; avs.forEach(function (av) { Object.keys(medidasDe(av)).forEach(function (k) { m[k] = 1; }); });
    CIRC.forEach(function (c) { if (m[c[0]]) out.push({ id: c[0], rot: c[1], un: 'cm', d: 1, melhor: null, grupo: 'circ' }); });
    var dk = API.ordenarDobras(Object.keys(m).filter(function (k) { return k.indexOf('dc_') === 0; }));
    dk.forEach(function (k) { out.push({ id: k, rot: ROT_DOBRA[k] || k.replace('dc_', ''), un: 'mm', d: 1, melhor: 'menos', grupo: 'dobra' }); });
    if (dk.length) out.push({ id: 'dobras', rot: 'Soma das dobras', un: 'mm', d: 1, melhor: 'menos', grupo: 'dobra', soma: true });
    return out;
  }
  // Uma linha por medida: um valor por avaliação, primeiro e último registro com número, e a variação entre eles.
  function linhas(avs) {
    var ord = ordenar(avs);
    return metricasDe(ord).map(function (m) {
      var v = ord.map(function (av) { return valorDe(av, m.id); });
      var idx = []; v.forEach(function (x, i) { if (x != null) idx.push(i); });
      var l = { m: m, valores: v, n: idx.length, primeiro: null, ultimo: null, delta: null };
      if (idx.length >= 1) { l.primeiro = v[idx[0]]; l.ultimo = v[idx[idx.length - 1]]; }
      if (idx.length >= 2) l.delta = l.ultimo - l.primeiro;
      return l;
    }).filter(function (l) { return l.n > 0; });
  }
  function periodoTxt(ord) {
    var a = dtMs(ord[0] && ord[0].dtavaliacao), b = dtMs(ord[ord.length - 1] && ord[ord.length - 1].dtavaliacao);
    if (a == null || b == null || b <= a) return '';
    var dias = Math.round((b - a) / 86400000);
    if (dias < 45) return dias + (dias === 1 ? ' dia' : ' dias');
    var meses = Math.round(dias / 30.44);
    if (meses < 24) return meses + (meses === 1 ? ' mês' : ' meses');
    var anos = Math.floor(meses / 12), r = meses - anos * 12;
    return anos + (anos === 1 ? ' ano' : ' anos') + (r ? ' e ' + r + (r === 1 ? ' mês' : ' meses') : '');
  }
  function sinal(d, casas) {
    if (d == null) return '–';
    var a = Math.abs(d), t = fmt(a, casas);
    if (/^0[,.]?0*$/.test(t)) return '0';
    return (d > 0 ? '+' : '−') + t;
  }

  // ═══════════════ ESTILO ═══════════════
  function estilo() {
    var l = luz();
    return { l: l, txt: l ? '#111' : '#f0f0f0', mut: l ? '#666' : '#9a9a9a', card: l ? '#fff' : '#141414', borda: l ? '#e5e5e5' : '#262626', campo: l ? '#f5f5f5' : '#0d0d0d', bom: l ? '#15803d' : '#4ade80', ruim: l ? '#b91c1c' : '#f87171', neutro: l ? '#1d4ed8' : '#60a5fa', acento: l ? '#4d9a00' : '#7FFF00' };
  }
  function corDelta(l, s) {
    if (l.delta == null || Math.abs(l.delta) < 0.05) return s.mut;
    if (!l.m.melhor) return s.neutro;
    var bom = l.m.melhor === 'menos' ? l.delta < 0 : l.delta > 0;
    return bom ? s.bom : s.ruim;
  }
  function tit(t, s, mt) { return '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:' + (mt || '18px 0 8px') + ';">' + t + '</div>'; }

  // ═══════════════ TELA 1: ESCOLHER AS AVALIAÇÕES ═══════════════
  function lista() {
    var c = (typeof _avaliacoesCache !== 'undefined' && Array.isArray(_avaliacoesCache)) ? _avaliacoesCache : [];
    return ordenar(candidatas(c)).reverse();       // mais recente primeiro
  }
  function abrir() {
    var cont = $('v-avaliacoes'); if (!cont) return;
    S.resultado = false;
    desenharLista();
  }
  function selecionadas() { var l = lista(); return l.filter(function (av, i) { return S.sel[chave(av, i)]; }); }
  function desenharLista() {
    var cont = $('v-avaliacoes'), s = estilo(), l = lista();
    var html = telaHeader('📊 Comparativos', null, 'renderAvaliacoesAluno();') +
      '<p style="font-size:12.5px;color:' + s.mut + ';margin:0 0 14px;line-height:1.55;">Escolha <b style="color:' + s.txt + ';">2 ou mais avaliações</b> para ver a sua evolução ao longo do tempo: gráfico, tabela e fotos.</p>';
    if (l.length < 2) {
      html += '<div style="text-align:center;padding:34px 18px;background:' + s.card + ';border:1px solid ' + s.borda + ';border-radius:14px;"><div style="font-size:34px;margin-bottom:8px;">📊</div>' +
        '<div style="font-size:14px;font-weight:800;color:' + s.txt + ';margin-bottom:6px;">Ainda não dá para comparar</div>' +
        '<div style="font-size:12.5px;color:' + s.mut + ';line-height:1.55;">É preciso ter pelo menos 2 avaliações com peso, % de gordura ou medidas. Quando você registrar a próxima, o comparativo aparece aqui.</div></div>';
    } else {
      var n = 0;
      var cartoes = l.map(function (av, i) {
        var k = chave(av, i), marcado = !!S.sel[k]; if (marcado) n++;
        var g = num(av.percgordura), p = num(av.peso);
        var resumo = [p != null ? fmt(p) + ' kg' : '', g != null ? fmt(g) + '% gordura' : '', somaDobras(av) != null ? 'dobras ' + fmt(somaDobras(av)) + ' mm' : ''].filter(Boolean).join(' · ');
        return '<label data-av="' + esc(k) + '" style="display:flex;align-items:center;gap:12px;background:' + (marcado ? (s.l ? 'rgba(77,154,0,.08)' : 'rgba(127,255,0,.07)') : s.card) + ';border:1.5px solid ' + (marcado ? s.acento : s.borda) + ';border-radius:12px;padding:12px 14px;margin-bottom:8px;cursor:pointer;">' +
          '<input type="checkbox" class="ca-check" ' + (marcado ? 'checked' : '') + ' onchange="ComparativoAvaliacoes.alternar(\'' + esc(k) + '\', this.checked)" style="width:20px;height:20px;accent-color:#7FFF00;flex-shrink:0;"/>' +
          '<div style="flex:1;min-width:0;"><div style="font-size:13.5px;font-weight:800;color:' + s.txt + ';">' + dtBR(av.dtavaliacao) + (av.metodo ? ' <span style="font-weight:600;color:' + s.mut + ';">· ' + esc(av.metodo) + '</span>' : '') + '</div>' +
          '<div style="font-size:11.5px;color:' + s.mut + ';margin-top:2px;overflow-wrap:anywhere;">' + (resumo || 'Sem peso nem medidas') + '</div></div></label>';
      }).join('');
      var chip = function (rot, fn) { return '<button onclick="' + fn + '" style="padding:7px 12px;border-radius:999px;border:1px solid ' + s.borda + ';background:' + s.campo + ';color:' + s.txt + ';font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;">' + rot + '</button>'; };
      html += '<div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap;">' + chip('Selecionar todas', 'ComparativoAvaliacoes.todas()') + chip('Limpar', 'ComparativoAvaliacoes.limpar()') +
        '<span id="ca-cont" style="margin-left:auto;font-size:12px;font-weight:700;color:' + s.mut + ';">' + contTxt(n) + '</span></div>' +
        '<div id="ca-lista">' + cartoes + '</div>' +
        '<div style="position:sticky;bottom:calc(12px + env(safe-area-inset-bottom,0px));margin-top:6px;z-index:2;">' +
          '<button id="ca-btn" class="btn btn-prim btn-full" onclick="ComparativoAvaliacoes.comparar()" style="padding:14px;font-size:14px;font-weight:800;box-shadow:0 6px 22px rgba(0,0,0,.35);' + (n >= 2 ? '' : 'opacity:.45;pointer-events:none;') + '">' + btnTxt(n) + '</button></div>';
    }
    cont.innerHTML = html + (typeof getFooterHtml === 'function' ? getFooterHtml() : '');
    window.scrollTo(0, 0);
  }
  function contTxt(n) { return n === 0 ? 'nenhuma marcada' : (n === 1 ? '1 marcada' : n + ' marcadas'); }
  function btnTxt(n) { return n >= 2 ? 'Comparar ' + n + ' avaliações' : (n === 1 ? 'Marque mais uma avaliação' : 'Marque 2 ou mais avaliações'); }
  function atualizarContagem() {
    var n = selecionadas().length, b = $('ca-btn'), c = $('ca-cont');
    if (c) c.textContent = contTxt(n);
    if (b) { b.textContent = btnTxt(n); b.style.opacity = n >= 2 ? '' : '.45'; b.style.pointerEvents = n >= 2 ? '' : 'none'; }
    var s = estilo();
    document.querySelectorAll('#ca-lista label[data-av]').forEach(function (el) {
      var m = !!S.sel[el.getAttribute('data-av')];
      el.style.borderColor = m ? s.acento : s.borda; el.style.background = m ? (s.l ? 'rgba(77,154,0,.08)' : 'rgba(127,255,0,.07)') : s.card;
    });
  }
  function alternar(k, v) { if (v) S.sel[k] = true; else delete S.sel[k]; atualizarContagem(); }
  function todas() { S.sel = {}; lista().forEach(function (av, i) { S.sel[chave(av, i)] = true; }); desenharLista(); }
  function limpar() { S.sel = {}; desenharLista(); }

  // ═══════════════ TELA 2: A EVOLUÇÃO ═══════════════
  function comparar() {
    var sel = selecionadas();
    if (sel.length < 2) { toast('Marque pelo menos 2 avaliações.'); return; }
    S.resultado = true;
    if (!S.metrica) S.metrica = 'peso';
    desenharResultado();
  }
  function voltarLista() { S.resultado = false; desenharLista(); }

  function grafico(ord, l, s) {
    var pts = []; ord.forEach(function (av, i) { var v = l.valores[i]; if (v != null) pts.push({ v: v, ms: dtMs(av.dtavaliacao), dt: av.dtavaliacao, i: i }); });
    if (pts.length < 2) return '<div style="font-size:12px;color:' + s.mut + ';padding:18px 4px;text-align:center;">Para o gráfico desta medida é preciso ter registro em pelo menos 2 das avaliações escolhidas.</div>';
    var W = 340, H = 196, ml = 38, mr = 16, mt = 26, mb = 34, pw = W - ml - mr, ph = H - mt - mb;
    var vs = pts.map(function (p) { return p.v; }), lo = Math.min.apply(null, vs), hi = Math.max.apply(null, vs);
    var folga = (hi - lo) * 0.18 || Math.max(1, hi * 0.05); lo -= folga; hi += folga;
    var m0 = pts[0].ms, m1 = pts[pts.length - 1].ms, ok = m0 != null && m1 != null && m1 > m0;
    var X = function (p, k) { return ml + (ok && p.ms != null ? (p.ms - m0) / (m1 - m0) : k / (pts.length - 1)) * pw; };
    var Y = function (v) { return mt + (1 - (v - lo) / (hi - lo)) * ph; };
    var grade = '';
    for (var g = 0; g <= 3; g++) {
      var gv = lo + (hi - lo) * g / 3, gy = Y(gv);
      grade += '<line x1="' + ml + '" x2="' + (W - mr) + '" y1="' + gy.toFixed(1) + '" y2="' + gy.toFixed(1) + '" stroke="' + s.borda + '" stroke-width="1"/>' +
        '<text x="' + (ml - 6) + '" y="' + (gy + 3.5).toFixed(1) + '" text-anchor="end" font-size="10" fill="' + s.mut + '">' + fmt(gv, l.m.d) + '</text>';
    }
    var linha = pts.map(function (p, k) { return X(p, k).toFixed(1) + ',' + Y(p.v).toFixed(1); }).join(' ');
    var anoMuda = pts[0].dt && pts[pts.length - 1].dt && String(pts[0].dt).slice(0, 4) !== String(pts[pts.length - 1].dt).slice(0, 4);
    var rotX = function (dt) { return anoMuda ? dtCurta(dt) + '/' + String(dt).slice(2, 4) : dtCurta(dt); };
    var pontos = pts.map(function (p, k) {
      var x = X(p, k), y = Y(p.v), ult = k === pts.length - 1;
      var mostraVal = pts.length <= 8 || k === 0 || ult;
      var anc = k === 0 ? 'start' : (ult ? 'end' : 'middle');
      return '<circle cx="' + x.toFixed(1) + '" cy="' + y.toFixed(1) + '" r="' + (ult ? 5 : 4) + '" fill="' + (ult ? s.acento : s.card) + '" stroke="' + s.acento + '" stroke-width="2.2"/>' +
        (mostraVal ? '<text x="' + x.toFixed(1) + '" y="' + (y - 10).toFixed(1) + '" text-anchor="' + anc + '" font-size="11" font-weight="800" fill="' + s.txt + '">' + fmt(p.v, l.m.d) + '</text>' : '') +
        ((pts.length <= 7 || k === 0 || ult) ? '<text x="' + x.toFixed(1) + '" y="' + (H - 12) + '" text-anchor="' + anc + '" font-size="10" fill="' + s.mut + '">' + rotX(p.dt) + '</text>' : '');
    }).join('');
    return '<svg viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="Gráfico de ' + esc(l.m.rot) + ' ao longo do tempo" style="width:100%;height:auto;display:block;">' + grade +
      '<polyline points="' + linha + '" fill="none" stroke="' + s.acento + '" stroke-width="2.6" stroke-linejoin="round" stroke-linecap="round"/>' + pontos + '</svg>';
  }

  function cartaoResumo(l, s) {
    var un = l.m.un === '%' ? '%' : ' ' + l.m.un;
    return '<div style="min-width:0;background:' + s.card + ';border:1px solid ' + s.borda + ';border-radius:14px;padding:12px;">' +
      '<div style="font-size:10.5px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.4px;">' + l.m.rot + '</div>' +
      '<div style="font-size:22px;font-weight:900;color:' + s.txt + ';margin-top:3px;line-height:1.1;">' + fmt(l.ultimo, l.m.d) + '<span style="font-size:12px;font-weight:700;color:' + s.mut + ';">' + un + '</span></div>' +
      '<div style="font-size:12.5px;font-weight:800;color:' + corDelta(l, s) + ';margin-top:5px;">' + (l.delta == null ? 'sem comparação' : sinal(l.delta, l.m.d) + un) + '</div>' +
      '<div style="font-size:10.5px;color:' + s.mut + ';margin-top:1px;">' + (l.delta == null ? '' : 'antes ' + fmt(l.primeiro, l.m.d)) + '</div></div>';
  }

  function tabela(ord, ls, s) {
    var th = 'padding:8px 10px;font-size:11px;font-weight:800;color:' + s.mut + ';text-align:right;white-space:nowrap;background:' + s.card + ';';
    var fixa = 'position:sticky;left:0;z-index:1;background:' + s.card + ';box-shadow:6px 0 8px -6px rgba(0,0,0,' + (s.l ? '.12' : '.45') + ');';
    var cab = '<tr><th style="' + th + 'text-align:left;' + fixa + 'z-index:2;">Medida</th>' + ord.map(function (av) { return '<th style="' + th + '">' + dtCurta(av.dtavaliacao) + '<div style="font-weight:600;font-size:9.5px;opacity:.8;">' + String(av.dtavaliacao || '').slice(0, 4) + '</div></th>'; }).join('') + '<th style="' + th + 'color:' + s.txt + ';">Variação</th></tr>';
    var grupos = { comp: 'Composição', circ: 'Circunferências (cm)', dobra: 'Dobras cutâneas (mm)' };
    var corpo = '', atual = '';
    ls.forEach(function (l) {
      if (l.m.grupo !== atual) { atual = l.m.grupo; corpo += '<tr><td colspan="' + (ord.length + 2) + '" style="padding:12px 10px 6px;font-size:10.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:' + s.acento + ';border-bottom:1px solid ' + s.borda + ';">' + grupos[atual] + '</td></tr>'; }
      var forte = l.m.soma ? 'font-weight:900;' : '';
      corpo += '<tr><td style="padding:9px 10px;font-size:12.5px;font-weight:' + (l.m.soma ? 900 : 700) + ';color:' + s.txt + ';white-space:nowrap;border-bottom:1px solid ' + s.borda + ';' + fixa + '">' + l.m.rot + '</td>' +
        l.valores.map(function (v) { return '<td style="padding:9px 10px;font-size:12.5px;text-align:right;color:' + (v == null ? s.mut : s.txt) + ';white-space:nowrap;border-bottom:1px solid ' + s.borda + ';' + forte + '">' + fmt(v, l.m.d) + '</td>'; }).join('') +
        '<td style="padding:9px 10px;font-size:12.5px;font-weight:800;text-align:right;white-space:nowrap;border-bottom:1px solid ' + s.borda + ';color:' + corDelta(l, s) + ';">' + sinal(l.delta, l.m.d) + '</td></tr>';
    });
    return '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid ' + s.borda + ';border-radius:14px;background:' + s.card + ';"><table style="border-collapse:separate;border-spacing:0;min-width:100%;"><thead>' + cab + '</thead><tbody>' + corpo + '</tbody></table></div>' +
      '<div style="font-size:11px;color:' + s.mut + ';margin:8px 2px 0;line-height:1.5;">Variação = último registro menos o primeiro, do mais antigo ao mais novo. Verde é a mudança na direção boa (menos gordura, mais massa magra); azul é uma mudança sem juízo de valor.</div>';
  }

  function fotosDe(av) {
    var f = Array.isArray(av.fotos) ? av.fotos : [], out = [];
    f.forEach(function (x) { var u = typeof x === 'string' ? x : (x && x.url) || ''; if (u) out.push(u); });
    return out;
  }
  function faixaDeFotos(ord, s) {
    var itens = []; ord.forEach(function (av) { var f = fotosDe(av); if (f.length) itens.push({ dt: av.dtavaliacao, url: f[0], todas: f }); });
    S._fotos = itens;
    if (!itens.length) return '';
    return tit('Fotos de cada data', s) +
      '<div style="display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;-webkit-overflow-scrolling:touch;">' + itens.map(function (it, i) {
        return '<div onclick="ComparativoAvaliacoes.foto(' + i + ')" style="flex:0 0 92px;cursor:pointer;"><img loading="lazy" src="' + esc(_urlAbs(it.url)) + '" alt="Foto de ' + esc(dtBR(it.dt)) + '" style="width:92px;aspect-ratio:3/4;object-fit:cover;border-radius:10px;background:#111;display:block;"/><div style="font-size:10.5px;font-weight:700;color:' + s.mut + ';text-align:center;margin-top:4px;">' + dtBR(it.dt) + '</div></div>';
      }).join('') + '</div>' +
      '<button onclick="abrirComparativoFotos()" style="width:100%;margin-top:10px;padding:12px;border-radius:12px;border:1px solid ' + s.borda + ';background:' + s.card + ';color:' + s.txt + ';font-size:13px;font-weight:800;font-family:inherit;cursor:pointer;">📸 Montar um antes e depois com as fotos</button>';
  }
  function foto(i) {
    var it = S._fotos && S._fotos[i]; if (!it) return;
    _comSheet('ca-foto', dtBR(it.dt), '<img src="' + esc(_urlAbs(it.url)) + '" alt="Foto" style="display:block;width:100%;max-height:70vh;object-fit:contain;border-radius:12px;background:#000;"/>', 100010);
  }

  function metrica(id) { S.metrica = id; desenharResultado(true); }

  function desenharResultado(soGrafico) {
    var cont = $('v-avaliacoes'), s = estilo();
    var ord = ordenar(selecionadas()), ls = linhas(ord);
    var chaveM = ls.some(function (l) { return l.m.id === S.metrica && l.n >= 2; }) ? S.metrica : (ls.filter(function (l) { return l.n >= 2; })[0] || {m: {id: ''}}).m.id;
    S.metrica = chaveM || S.metrica;
    var lAtual = ls.filter(function (l) { return l.m.id === S.metrica; })[0];
    var chips = ls.filter(function (l) { return l.n >= 2; }).map(function (l) {
      var ativo = l.m.id === S.metrica;
      return '<button data-m="' + l.m.id + '" onclick="ComparativoAvaliacoes.metrica(\'' + l.m.id + '\')" style="flex-shrink:0;padding:8px 13px;border-radius:999px;border:1px solid ' + (ativo ? 'transparent' : s.borda) + ';background:' + (ativo ? (s.l ? '#111' : '#f0f0f0') : s.campo) + ';color:' + (ativo ? (s.l ? '#fff' : '#111') : s.txt) + ';font-size:12px;font-weight:800;font-family:inherit;cursor:pointer;white-space:nowrap;">' + l.m.rot + '</button>';
    }).join('');
    var blocoGrafico = '<div id="ca-grafico" style="background:' + s.card + ';border:1px solid ' + s.borda + ';border-radius:14px;padding:12px 8px 8px;">' + (lAtual ? '<div style="font-size:13px;font-weight:800;color:' + s.txt + ';padding:0 8px 4px;">' + lAtual.m.rot + ' <span style="color:' + s.mut + ';font-weight:600;">(' + lAtual.m.un + ')</span></div>' + grafico(ord, lAtual, s) : '') + '</div>';
    if (soGrafico) { var g = $('ca-grafico'); if (g) { g.outerHTML = blocoGrafico; document.querySelectorAll('[data-m]').forEach(function (b) { var a = b.getAttribute('data-m') === S.metrica; b.style.background = a ? (s.l ? '#111' : '#f0f0f0') : s.campo; b.style.color = a ? (s.l ? '#fff' : '#111') : s.txt; b.style.borderColor = a ? 'transparent' : s.borda; }); return; } }
    var resumo = ['peso', 'gord', 'magra', 'gorda'].map(function (id) { return ls.filter(function (l) { return l.m.id === id; })[0]; }).filter(Boolean);
    var per = periodoTxt(ord);
    var html = telaHeader('📈 Sua evolução', null, 'ComparativoAvaliacoes.voltarLista();') +
      '<div style="font-size:12.5px;color:' + s.mut + ';margin:0 0 14px;line-height:1.5;">' + ord.length + ' avaliações, de <b style="color:' + s.txt + ';">' + dtBR(ord[0].dtavaliacao) + '</b> a <b style="color:' + s.txt + ';">' + dtBR(ord[ord.length - 1].dtavaliacao) + '</b>' + (per ? ' (' + per + ')' : '') + '</div>' +
      (resumo.length ? '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">' + resumo.map(function (l) { return cartaoResumo(l, s); }).join('') + '</div>' : '') +
      tit('Gráfico', s) +
      '<div style="display:flex;gap:8px;overflow-x:auto;padding-bottom:8px;-webkit-overflow-scrolling:touch;">' + chips + '</div>' +
      blocoGrafico +
      tit('Tabela completa', s) + tabela(ord, ls, s) +
      faixaDeFotos(ord, s) +
      '<div style="height:24px;"></div>' + (typeof getFooterHtml === 'function' ? getFooterHtml() : '');
    cont.innerHTML = html;
    window.scrollTo(0, 0);
  }

  window.ComparativoAvaliacoes = {
    abrir: abrir, alternar: alternar, todas: todas, limpar: limpar, comparar: comparar, voltarLista: voltarLista, metrica: metrica, foto: foto,
    ativo: function () { return !!($('ca-lista') || $('ca-grafico')); },
    _teste: { ordenar: ordenar, linhas: linhas, candidatas: candidatas, periodoTxt: periodoTxt, sinal: sinal, estado: function () { return S; }, resetar: function () { S = { sel: {}, metrica: 'peso', resultado: false }; } }
  };
})();
