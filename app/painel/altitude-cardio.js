/* Altitude e relevo do cardio ao vivo (25/11/2026).
 *
 * A altitude de cada ponto da rota vem do relevo (tiles "Terrarium" do AWS Open Data / Mapzen, sem chave, gratuitos) e, quando o relevo não
 * está disponível, da altitude do GPS. O relevo é bem mais estável que o GPS (que erra 10 a 30 m na vertical). Daí saem altitude atual,
 * ganho e descida acumulados (com folga para ignorar tremido) e o perfil de elevação (gráfico distância x altitude).
 *
 * Funções puras (resumo, perfil) não dependem da tela e têm teste. `amostrar` busca a altitude de um ponto de forma assíncrona.
 */
(function () {
  'use strict';

  var Z = 13;                                          // ~19 m por pixel no equador: bom para trilhas e ruas, tile pequeno
  var URL_TILE = 'https://elevation-tiles-prod.s3.amazonaws.com/terrarium/{z}/{x}/{y}.png';
  var cache = {};                                      // 'x/y' -> { dados:ImageData } | { falhou:true, em:ts } | Promise
  var ordem = [];
  var LIMITE = 24;
  var FOLGA_M = 2.5;                                   // variação menor que isso não conta como subida nem descida

  function tileDe(lat, lng) {
    var n = Math.pow(2, Z), x = (lng + 180) / 360 * n;
    var r = lat * Math.PI / 180, y = (1 - Math.log(Math.tan(r) + 1 / Math.cos(r)) / Math.PI) / 2 * n;
    return { x: Math.floor(x), y: Math.floor(y), fx: x - Math.floor(x), fy: y - Math.floor(y) };
  }
  function carregarTile(x, y) {
    var k = x + '/' + y, c = cache[k];
    if (c && c.dados) return Promise.resolve(c.dados);
    if (c && c.falhou && Date.now() - c.em < 60000) return Promise.resolve(null);   // falhou há pouco: não insiste a cada ponto
    if (c && c.promessa) return c.promessa;
    var p = new Promise(function (resolve) {
      var im = new Image(); im.crossOrigin = 'anonymous';
      im.onload = function () {
        try {
          var cv = document.createElement('canvas'); cv.width = im.width; cv.height = im.height;
          var g = cv.getContext('2d'); g.drawImage(im, 0, 0);
          var d = g.getImageData(0, 0, cv.width, cv.height);
          cache[k] = { dados: d }; ordem.push(k);
          while (ordem.length > LIMITE) delete cache[ordem.shift()];
          resolve(d);
        } catch (e) { cache[k] = { falhou: true, em: Date.now() }; resolve(null); }
      };
      im.onerror = function () { cache[k] = { falhou: true, em: Date.now() }; resolve(null); };
      im.src = URL_TILE.replace('{z}', Z).replace('{x}', x).replace('{y}', y);
    });
    cache[k] = { promessa: p };
    return p;
  }
  function pixel(d, px, py) {
    px = Math.max(0, Math.min(d.width - 1, px)); py = Math.max(0, Math.min(d.height - 1, py));
    var i = (py * d.width + px) * 4;
    return d.data[i] * 256 + d.data[i + 1] + d.data[i + 2] / 256 - 32768;     // codificação Terrarium
  }
  // Altitude (m) do relevo num ponto, com interpolação entre os 4 pixels vizinhos. null se não deu para obter.
  function demAltitude(lat, lng) {
    if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 85) return Promise.resolve(null);
    var t = tileDe(lat, lng);
    return carregarTile(t.x, t.y).then(function (d) {
      if (!d) return null;
      var fx = t.fx * d.width - 0.5, fy = t.fy * d.height - 0.5, x0 = Math.floor(fx), y0 = Math.floor(fy), ax = fx - x0, ay = fy - y0;
      var v = pixel(d, x0, y0) * (1 - ax) * (1 - ay) + pixel(d, x0 + 1, y0) * ax * (1 - ay) + pixel(d, x0, y0 + 1) * (1 - ax) * ay + pixel(d, x0 + 1, y0 + 1) * ax * ay;
      return (isFinite(v) && v > -500 && v < 9000) ? v : null;
    });
  }

  // Preenche p.alt (ponto da rota {lat,lng,alt?}) com a altitude do relevo; chama depois() quando atualizar.
  function amostrar(p, depois) {
    if (!p || p.altDem) return;
    p.altDem = 1;
    demAltitude(p.lat, p.lng).then(function (a) { if (a != null) { p.alt = Math.round(a * 10) / 10; p.altFonte = 'relevo'; if (depois) depois(p); } }).catch(function () {});
  }
  // Altitude vinda do GPS do aparelho (coords.altitude), só quando o relevo não responde.
  function altGps(coords) {
    var a = coords && coords.altitude;
    return (typeof a === 'number' && isFinite(a) && a > -500 && a < 9000) ? a : null;
  }

  // ── contas (puras) ──
  // Média móvel com janela simétrica que encolhe nas pontas: reta continua reta e o primeiro e o último ponto ficam como estão
  // (uma janela fixa achatava as extremidades e tirava metros do ganho em rotas curtas).
  function suavizar(v, j) {
    j = j || 2;
    return v.map(function (_, i) {
      var w = Math.min(j, i, v.length - 1 - i), s = 0, n = 0;
      for (var k = i - w; k <= i + w; k++) { s += v[k]; n++; }
      return s / n;
    });
  }
  function dist(a, b) {
    var R = 6371000, r = Math.PI / 180, dLat = (b.lat - a.lat) * r, dLng = (b.lng - a.lng) * r;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * r) * Math.cos(b.lat * r) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.sqrt(h));
  }
  // pontos: [{lat,lng,alt?,novoTrecho?}]. Devolve null quando não há altitude suficiente.
  function resumo(pontos) {
    var l = (pontos || []).filter(function (p) { return p && typeof p.alt === 'number' && isFinite(p.alt); });
    if (l.length < 2) return l.length ? { atual: Math.round(l[0].alt), ganho: 0, perda: 0, min: Math.round(l[0].alt), max: Math.round(l[0].alt), perfil: [{ d: 0, alt: l[0].alt }] } : null;
    var alt = suavizar(l.map(function (p) { return p.alt; }));
    var ganho = 0, perda = 0, ref = alt[0], d = 0, perfil = [{ d: 0, alt: alt[0] }];
    for (var i = 1; i < l.length; i++) {
      if (!l[i].novoTrecho) d += dist(l[i - 1], l[i]);
      perfil.push({ d: d, alt: alt[i] });
      var dif = alt[i] - ref;
      if (dif >= FOLGA_M) { ganho += dif; ref = alt[i]; }
      else if (dif <= -FOLGA_M) { perda += -dif; ref = alt[i]; }
    }
    var min = Infinity, max = -Infinity; alt.forEach(function (a) { if (a < min) min = a; if (a > max) max = a; });
    return { atual: Math.round(alt[alt.length - 1]), ganho: Math.round(ganho), perda: Math.round(perda), min: Math.round(min), max: Math.round(max), perfil: perfil };
  }
  // Reduz o perfil para no máximo n pontos (o gráfico não precisa de mais).
  function reduzir(perfil, n) {
    if (perfil.length <= n) return perfil;
    var out = [], passo = (perfil.length - 1) / (n - 1);
    for (var i = 0; i < n; i++) out.push(perfil[Math.round(i * passo)]);
    return out;
  }
  // Gráfico de elevação em SVG (área + linha), do tamanho pedido. cor: cor da linha.
  function svgPerfil(res, w, h, cor, corFundo) {
    if (!res || !res.perfil || res.perfil.length < 2) return '';
    var p = reduzir(res.perfil, 120), dmax = p[p.length - 1].d || 1;
    var min = res.min, max = res.max, esp = Math.max(max - min, 10), pad = 3;
    var X = function (d) { return (pad + d / dmax * (w - 2 * pad)).toFixed(1); };
    var Y = function (a) { return (h - pad - (a - min) / esp * (h - 2 * pad)).toFixed(1); };
    var linha = p.map(function (q, i) { return (i ? 'L' : 'M') + X(q.d) + ' ' + Y(q.alt); }).join(' ');
    var area = linha + ' L' + X(p[p.length - 1].d) + ' ' + (h - pad) + ' L' + X(0) + ' ' + (h - pad) + ' Z';
    return '<svg viewBox="0 0 ' + w + ' ' + h + '" width="100%" height="' + h + '" preserveAspectRatio="none" role="img" aria-label="Perfil de altitude da rota">' +
      '<path d="' + area + '" fill="' + (corFundo || 'rgba(10,10,10,.12)') + '"/><path d="' + linha + '" fill="none" stroke="' + (cor || '#0a0a0a') + '" stroke-width="2.2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
  }

  window.AltitudeCardio = { demAltitude: demAltitude, amostrar: amostrar, altGps: altGps, resumo: resumo, svgPerfil: svgPerfil, _teste: { tileDe: tileDe, suavizar: suavizar, reduzir: reduzir, FOLGA_M: FOLGA_M } };
})();
