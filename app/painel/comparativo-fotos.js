/* Comparativo de fotos (antes e depois) do aluno, 25/11/2026.
 *
 * O aluno escolhe duas fotos (das avaliações dele ou da câmera/galeria), monta a imagem e:
 *   - Linha divisória: as duas fotos na mesma moldura, com uma linha que ele arrasta;
 *   - Lado a lado: as duas fotos separadas, com datas e peso embaixo.
 * Cada foto pode ser movida e ampliada para os corpos ficarem alinhados. A imagem final (1080x1350) leva a logo da Intus.
 * Salvar cria uma AVALIAÇÃO do aluno (método "Comparativo do aluno"): o servidor avisa os professores responsáveis no sino e
 * por e-mail (catalogo.php, avaliacoes POST). Compartilhar: Feed da Intus ou perfil (sempre com confirmação e escolha de
 * quem pode ver), WhatsApp, Instagram, copiar e baixar.
 *
 * Depende de funções do aluno.html: toast, esc, API, idAtleta, _urlAbs, _comSheet, _busyMostrar/_busyFim, _travarEExecutar,
 * _dataUrlParaBlob, _blobParaPng, _shareMostrarParaSalvar, _shareSocialBtn, _shareActionBtn, _shareIconSvg(Solido), _soTesteBarra.
 */
(function () {
  'use strict';

  var W0 = 1080, H0 = 1350;
  var AX = 48, AY = 170, AW = 984, AH = 1000;     // moldura da linha divisória
  var PH = 850, GAP = 24;                          // lado a lado: altura de cada foto e espaço entre elas
  var FONTE = 'Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
  var C = null;                                    // estado da montagem
  var _logo = null;

  function novo() {
    return { a: vazio(), b: vazio(), modo: 'divisor', pos: 0.5, ferr: 'linha', mostrar: true, salvo: false, legenda: '', visib: 'todos', slotArq: 'a', opcoes: [] };
  }
  function vazio() { return { img: null, dt: '', peso: '', zoom: 1, ox: 0, oy: 0 }; }
  function luz() { return document.body.classList.contains('tema-claro'); }
  function $(id) { return document.getElementById(id); }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function dim(im) { return { w: im.naturalWidth || im.width, h: im.naturalHeight || im.height }; }

  function carregarImg(src, cors) {
    return new Promise(function (res) {
      var im = new Image();
      if (cors) im.crossOrigin = 'anonymous';
      im.onload = function () { res(im); };
      im.onerror = function () { res(null); };
      im.src = src;
    });
  }
  // Foto grande demais pesa na memória do celular: reduz para no máximo 1600 px no maior lado.
  function reduzir(im, max) {
    var d = dim(im), k = Math.min(1, max / Math.max(d.w, d.h));
    if (k >= 1) return im;
    var cv = document.createElement('canvas');
    cv.width = Math.round(d.w * k); cv.height = Math.round(d.h * k);
    cv.getContext('2d').drawImage(im, 0, 0, cv.width, cv.height);
    return cv;
  }

  // ── datas e números ──
  function dtBR(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : ''; }
  function dtMs(iso) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || ''); return m ? Date.UTC(+m[1], +m[2] - 1, +m[3]) : null; }
  function pesoNum(v) { var n = parseFloat(String(v == null ? '' : v).replace(',', '.')); return isFinite(n) && n > 0 ? n : null; }
  function fmtKg(n) { return n.toFixed(1).replace('.', ',') + ' kg'; }
  function hojeIso() { return (typeof dataHojeLocal === 'function') ? dataHojeLocal() : new Date().toISOString().slice(0, 10); }

  // ═══════════════ DESENHO (mesma função para a prévia e para a imagem final) ═══════════════
  function rr(ctx, x, y, w, h, r) {
    ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r);
    ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath();
  }
  function foto(ctx, f, x, y, w, h) {
    var d = dim(f.img), s = Math.max(w / d.w, h / d.h) * f.zoom, dw = d.w * s, dh = d.h * s;
    var cx = clamp(x + w / 2 + f.ox * w, x + w - dw / 2, x + dw / 2);
    var cy = clamp(y + h / 2 + f.oy * h, y + h - dh / 2, y + dh / 2);
    f.ox = (cx - (x + w / 2)) / w; f.oy = (cy - (y + h / 2)) / h;     // guarda já dentro do limite
    ctx.drawImage(f.img, cx - dw / 2, cy - dh / 2, dw, dh);
  }
  function selo(ctx, txt, x, y, alinhaDir) {
    ctx.save();
    ctx.font = '800 26px ' + FONTE;
    try { ctx.letterSpacing = '3px'; } catch (e) {}
    var w = ctx.measureText(txt).width + 36, h = 48, bx = alinhaDir ? x - w : x;
    ctx.fillStyle = 'rgba(0,0,0,.6)'; rr(ctx, bx, y, w, h, 24); ctx.fill();
    ctx.fillStyle = '#fff'; ctx.textAlign = 'left'; ctx.textBaseline = 'middle';
    ctx.fillText(txt, bx + 18, y + h / 2 + 1);
    ctx.restore();
  }
  function geometria() {
    if (C.modo === 'divisor') return { y: AY, h: AH, fw: AW };
    var ph = PH, py = C.mostrar ? AY : AY + (AH - PH) / 2;
    return { y: py, h: ph, fw: (AW - GAP) / 2 };
  }

  function compor(cv, k) {
    var ctx = cv.getContext('2d');
    cv.width = Math.round(W0 * k); cv.height = Math.round(H0 * k);
    ctx.setTransform(k, 0, 0, k, 0, 0);
    ctx.fillStyle = '#0f0f0f'; ctx.fillRect(0, 0, W0, H0);
    // topo: logo e título
    if (_logo) { var lw = 220, lh = lw * (_logo.height / _logo.width); ctx.drawImage(_logo, AX, 56, lw, lh); }
    ctx.fillStyle = '#ffffff'; ctx.textAlign = 'right'; ctx.textBaseline = 'alphabetic'; ctx.font = '800 32px ' + FONTE;
    try { ctx.letterSpacing = '6px'; } catch (e) {}
    ctx.fillText('MINHA EVOLUÇÃO', AX + AW, 98);
    try { ctx.letterSpacing = '0px'; } catch (e) {}

    var g = geometria();
    var vazioTxt = function (x, y, w, h) {
      ctx.fillStyle = '#1b1b1b'; ctx.fillRect(x, y, w, h);
      ctx.fillStyle = '#7a7a7a'; ctx.font = '600 30px ' + FONTE; ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
      ctx.fillText('Escolha a foto', x + w / 2, y + h / 2);
    };
    if (C.modo === 'divisor') {
      var lx = AX + AW * C.pos;
      ctx.save(); rr(ctx, AX, AY, AW, AH, 28); ctx.clip();
      if (C.a.img) { ctx.save(); ctx.beginPath(); ctx.rect(AX, AY, AW * C.pos, AH); ctx.clip(); foto(ctx, C.a, AX, AY, AW, AH); ctx.restore(); } else { ctx.save(); ctx.beginPath(); ctx.rect(AX, AY, AW * C.pos, AH); ctx.clip(); vazioTxt(AX, AY, AW, AH); ctx.restore(); }
      if (C.b.img) { ctx.save(); ctx.beginPath(); ctx.rect(lx, AY, AW * (1 - C.pos), AH); ctx.clip(); foto(ctx, C.b, AX, AY, AW, AH); ctx.restore(); } else { ctx.save(); ctx.beginPath(); ctx.rect(lx, AY, AW * (1 - C.pos), AH); ctx.clip(); ctx.fillStyle = '#242424'; ctx.fillRect(AX, AY, AW, AH); ctx.restore(); }
      ctx.restore();
      // linha e marcador
      ctx.fillStyle = '#ffffff'; ctx.fillRect(lx - 2.5, AY, 5, AH);
      var cy = AY + AH / 2;
      ctx.save(); ctx.shadowColor = 'rgba(0,0,0,.45)'; ctx.shadowBlur = 18;
      ctx.beginPath(); ctx.arc(lx, cy, 36, 0, Math.PI * 2); ctx.fillStyle = '#ffffff'; ctx.fill(); ctx.restore();
      ctx.fillStyle = '#0f0f0f';
      ctx.beginPath(); ctx.moveTo(lx - 10, cy); ctx.lineTo(lx - 22, cy - 11); ctx.lineTo(lx - 22, cy + 11); ctx.closePath(); ctx.fill();
      ctx.beginPath(); ctx.moveTo(lx + 10, cy); ctx.lineTo(lx + 22, cy - 11); ctx.lineTo(lx + 22, cy + 11); ctx.closePath(); ctx.fill();
      selo(ctx, 'ANTES', AX + 22, AY + 22, false);
      selo(ctx, 'DEPOIS', AX + AW - 22, AY + 22, true);
    } else {
      [[C.a, AX, 'ANTES'], [C.b, AX + g.fw + GAP, 'DEPOIS']].forEach(function (p) {
        ctx.save(); rr(ctx, p[1], g.y, g.fw, g.h, 28); ctx.clip();
        if (p[0].img) foto(ctx, p[0], p[1], g.y, g.fw, g.h); else vazioTxt(p[1], g.y, g.fw, g.h);
        ctx.restore();
        selo(ctx, p[2], p[1] + 18, g.y + 18, false);
      });
    }

    // rodapé: datas, peso e diferença
    var pa = pesoNum(C.a.peso), pb = pesoNum(C.b.peso), ma = dtMs(C.a.dt), mb = dtMs(C.b.dt);
    if (C.mostrar) {
      var y0 = C.modo === 'divisor' ? 1226 : g.y + g.h + 56;
      var bloco = function (f, x, dir) {
        ctx.textAlign = dir ? 'right' : 'left'; ctx.textBaseline = 'alphabetic';
        ctx.fillStyle = '#ffffff'; ctx.font = '800 ' + (C.modo === 'divisor' ? 36 : 40) + 'px ' + FONTE;
        if (f.dt) ctx.fillText(dtBR(f.dt), x, y0);
        var p = pesoNum(f.peso);
        if (p) { ctx.fillStyle = '#b5b5b5'; ctx.font = '600 32px ' + FONTE; ctx.fillText(fmtKg(p), x, y0 + 46); }
      };
      bloco(C.a, AX, false); bloco(C.b, AX + AW, true);
      var meio = [];
      if (pa && pb) { var df = pb - pa; meio.push((df > 0 ? '+' : (df < 0 ? '−' : '')) + Math.abs(df).toFixed(1).replace('.', ',') + ' kg'); }
      if (ma != null && mb != null && mb > ma) { var dd = Math.round((mb - ma) / 86400000); meio.push(dd === 1 ? '1 dia' : dd + ' dias'); }
      if (meio.length) {
        ctx.textAlign = 'center'; ctx.font = '800 32px ' + FONTE;
        var tw = ctx.measureText(meio.join('  ·  ')).width + 52;
        ctx.fillStyle = '#ffffff'; rr(ctx, W0 / 2 - tw / 2, y0 - 38, tw, 60, 30); ctx.fill();
        ctx.fillStyle = '#0f0f0f'; ctx.textBaseline = 'middle'; ctx.fillText(meio.join('  ·  '), W0 / 2, y0 - 7);
      }
    }
    ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic'; ctx.fillStyle = '#7d7d7d'; ctx.font = '600 26px ' + FONTE;
    ctx.fillText('@intusfit', W0 / 2, H0 - 34);
  }
  function dataUrlFinal() {
    var cv = document.createElement('canvas');
    compor(cv, 1);
    return cv.toDataURL('image/jpeg', 0.9);
  }

  // ═══════════════ TELA ═══════════════
  var _raf = 0;
  function redesenhar() {
    if (_raf) return;
    _raf = requestAnimationFrame(function () { _raf = 0; var cv = $('cf-prev'); if (cv && C) compor(cv, 0.5); });
  }
  function marcarAlterado() { if (C) C.salvo = false; var b = $('cf-btn-salvar'); if (b) { b.dataset.feito = ''; atualizarBotoes(); } }

  function abrir() {
    var cont = $('v-avaliacoes');
    if (!cont) return;
    C = novo();
    // logo (para a imagem) e, em paralelo, as fotos das avaliações já ficam à mão
    if (!_logo) carregarImg('logo-intus-dark.png', false).then(function (l) { _logo = l; redesenhar(); });
    // sugestão: foto mais antiga como Antes e a mais recente como Depois, quando houver ao menos duas
    montar();
  }

  function opcoesDeFotos() {
    var lista = (typeof _avaliacoesCache !== 'undefined' && Array.isArray(_avaliacoesCache)) ? _avaliacoesCache : [];
    var out = [];
    lista.forEach(function (av) {
      if (/comparativo/i.test(String(av.metodo || ''))) return;
      (Array.isArray(av.fotos) ? av.fotos : []).forEach(function (f) {
        var u = typeof f === 'string' ? f : (f && f.url) || '';
        if (u) out.push({ url: u, dt: String(av.dtavaliacao || '').slice(0, 10), peso: av.peso != null ? av.peso : '' });
      });
    });
    out.sort(function (x, y) { return (y.dt || '').localeCompare(x.dt || ''); });
    return out;
  }
  function comparativosSalvos() {
    var lista = (typeof _avaliacoesCache !== 'undefined' && Array.isArray(_avaliacoesCache)) ? _avaliacoesCache : [];
    var out = [];
    lista.forEach(function (av) {
      if (!/comparativo/i.test(String(av.metodo || ''))) return;
      var f = Array.isArray(av.fotos) && av.fotos[0];
      var u = typeof f === 'string' ? f : (f && f.url) || '';
      if (u) out.push({ url: u, dt: String(av.dtavaliacao || '').slice(0, 10) });
    });
    return out;
  }

  function estilo() {
    var l = luz();
    return { l: l, txt: l ? '#111' : '#f0f0f0', mut: l ? '#666' : '#999', card: l ? '#fff' : '#141414', borda: l ? '#e5e5e5' : '#262626', campo: l ? '#f5f5f5' : '#0d0d0d' };
  }

  function slotHtml(qual, rot, s) {
    var f = C[qual];
    var miniatura = f.img
      ? '<canvas id="cf-mini-' + qual + '" width="120" height="150" style="width:60px;height:75px;border-radius:10px;display:block;background:#000;"></canvas>'
      : '<div style="width:60px;height:75px;border-radius:10px;border:2px dashed ' + s.borda + ';display:flex;align-items:center;justify-content:center;font-size:24px;color:' + s.mut + ';">+</div>';
    return '<div style="background:' + s.card + ';border:1px solid ' + s.borda + ';border-radius:14px;padding:12px;">' +
      '<div style="display:flex;gap:12px;align-items:center;">' +
        '<div onclick="ComparativoFotos.escolher(\'' + qual + '\')" style="cursor:pointer;flex-shrink:0;">' + miniatura + '</div>' +
        '<div style="flex:1;min-width:0;">' +
          '<div style="font-size:12px;font-weight:800;letter-spacing:1px;color:' + s.txt + ';">' + rot + '</div>' +
          '<button onclick="ComparativoFotos.escolher(\'' + qual + '\')" style="margin-top:6px;padding:8px 12px;border-radius:10px;border:1px solid ' + s.borda + ';background:' + s.campo + ';color:' + s.txt + ';font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;">' + (f.img ? 'Trocar foto' : 'Escolher foto') + '</button>' +
        '</div>' +
      '</div>' +
      (f.img
        ? '<div style="display:grid;grid-template-columns:1.2fr 1fr;gap:8px;margin-top:10px;">' +
            '<label style="font-size:10px;font-weight:700;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.4px;">Data<input type="date" value="' + esc(f.dt) + '" max="' + hojeIso() + '" oninput="ComparativoFotos.campo(\'' + qual + '\',\'dt\',this.value)" style="display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:9px 8px;border-radius:10px;border:1px solid ' + s.borda + ';background:' + s.campo + ';color:' + s.txt + ';font-size:13px;font-family:inherit;"/></label>' +
            '<label style="font-size:10px;font-weight:700;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.4px;">Peso (kg)<input type="text" inputmode="decimal" value="' + esc(f.peso) + '" placeholder="opcional" oninput="ComparativoFotos.campo(\'' + qual + '\',\'peso\',this.value)" style="display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:9px 8px;border-radius:10px;border:1px solid ' + s.borda + ';background:' + s.campo + ';color:' + s.txt + ';font-size:13px;font-family:inherit;"/></label>' +
          '</div>'
        : '') +
    '</div>';
  }

  function montar() {
    var cont = $('v-avaliacoes'), s = estilo();
    var seg = function (ativo, onclick, rot) {
      return '<button onclick="' + onclick + '" style="flex:1;padding:10px 6px;border-radius:10px;border:1px solid ' + (ativo ? 'transparent' : s.borda) + ';background:' + (ativo ? (s.l ? '#111' : '#f0f0f0') : s.campo) + ';color:' + (ativo ? (s.l ? '#fff' : '#111') : s.txt) + ';font-size:12px;font-weight:800;font-family:inherit;cursor:pointer;">' + rot + '</button>';
    };
    var salvos = comparativosSalvos();
    var html = '<button class="btn-back" onclick="ComparativoFotos.voltar();">← Voltar</button>' +
      '<h2 class="sect-title">Comparar fotos</h2>' +
      '<p style="font-size:12px;color:' + s.mut + ';margin:0 0 14px;line-height:1.5;">Escolha uma foto de antes e uma de depois, ajuste até os dois corpos ficarem alinhados e salve. Seus professores recebem o aviso de uma nova avaliação.</p>' +
      '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">' + slotHtml('a', 'ANTES', s) + slotHtml('b', 'DEPOIS', s) + '</div>' +
      '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:4px 0 6px;">Estilo</div>' +
      '<div style="display:flex;gap:8px;margin-bottom:12px;">' + seg(C.modo === 'divisor', 'ComparativoFotos.modo(\'divisor\')', '↔ Linha divisória') + seg(C.modo === 'lado', 'ComparativoFotos.modo(\'lado\')', '▥ Lado a lado') + '</div>' +
      '<div style="display:flex;justify-content:center;"><canvas id="cf-prev" style="width:100%;max-width:340px;aspect-ratio:4/5;border-radius:14px;background:#0f0f0f;box-shadow:0 8px 28px rgba(0,0,0,.3);touch-action:pan-y;user-select:none;-webkit-user-select:none;"></canvas></div>' +
      '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:14px 0 6px;">O que arrastar</div>' +
      '<div id="cf-ferr" style="display:flex;gap:8px;"></div>' +
      '<div id="cf-ajuda" style="font-size:12px;color:' + s.mut + ';margin:8px 0 0;line-height:1.5;"></div>' +
      '<div id="cf-zoom-bloco" style="margin-top:10px;"><div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Zoom da foto</div><input id="cf-zoom" type="range" min="100" max="300" value="100" oninput="ComparativoFotos.zoom(this.value)" style="width:100%;accent-color:#7FFF00;"/></div>' +
      '<label style="display:flex;align-items:center;gap:10px;margin-top:14px;font-size:13px;color:' + s.txt + ';cursor:pointer;"><input type="checkbox" ' + (C.mostrar ? 'checked' : '') + ' onchange="ComparativoFotos.mostrar(this.checked)" style="width:18px;height:18px;accent-color:#7FFF00;"/> Mostrar datas e peso na imagem</label>' +
      '<div style="display:flex;flex-direction:column;gap:8px;margin-top:16px;">' +
        '<button id="cf-btn-salvar" class="btn btn-prim btn-full" style="padding:14px;font-size:14px;font-weight:700;" onclick="_travarEExecutar(event, ComparativoFotos.salvar)">💾 Salvar na minha avaliação</button>' +
        '<button id="cf-btn-comp" class="btn btn-sec btn-full" style="padding:13px;font-size:13px;font-weight:700;" onclick="ComparativoFotos.compartilhar()">📤 Compartilhar ou baixar</button>' +
      '</div>' +
      '<input type="file" id="cf-file" accept="image/*" style="display:none;" onchange="ComparativoFotos.arquivo(this)"/>' +
      (salvos.length
        ? '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:22px 0 8px;">Meus comparativos salvos</div>' +
          '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">' + salvos.map(function (v, i) {
            return '<div onclick="ComparativoFotos.abrirSalvo(' + i + ')" style="cursor:pointer;"><img src="' + esc(_urlAbs(v.url)) + '" alt="Comparativo" style="width:100%;aspect-ratio:4/5;object-fit:cover;border-radius:10px;background:#111;display:block;"/><div style="font-size:10px;color:' + s.mut + ';text-align:center;margin-top:3px;">' + esc(dtBR(v.dt)) + '</div></div>';
          }).join('') + '</div>'
        : '') +
      '<div style="height:60px;"></div>';
    cont.innerHTML = html;
    window.scrollTo(0, 0);
    C._salvos = salvos;
    ligarCanvas();
    pintarMinis();
    atualizarFerr();
    atualizarBotoes();
    redesenhar();
  }

  function pintarMinis() {
    ['a', 'b'].forEach(function (q) {
      var cv = $('cf-mini-' + q), f = C[q];
      if (!cv || !f.img) return;
      var ctx = cv.getContext('2d'), d = dim(f.img), s = Math.max(cv.width / d.w, cv.height / d.h);
      ctx.drawImage(f.img, (cv.width - d.w * s) / 2, (cv.height - d.h * s) / 2, d.w * s, d.h * s);
    });
  }
  function atualizarFerr() {
    var box = $('cf-ferr'); if (!box) return;
    var s = estilo();
    var seg = function (id, rot) {
      var ativo = C.ferr === id;
      return '<button onclick="ComparativoFotos.ferr(\'' + id + '\')" style="flex:1;padding:10px 6px;border-radius:10px;border:1px solid ' + (ativo ? 'transparent' : s.borda) + ';background:' + (ativo ? '#7FFF00' : s.campo) + ';color:' + (ativo ? '#0f1a00' : s.txt) + ';font-size:12px;font-weight:800;font-family:inherit;cursor:pointer;">' + rot + '</button>';
    };
    box.innerHTML = (C.modo === 'divisor' ? seg('linha', '↔ A linha') : '') + seg('a', '✋ Foto antes') + seg('b', '✋ Foto depois');
    var aj = $('cf-ajuda');
    if (aj) aj.textContent = C.ferr === 'linha' ? 'Arraste na imagem para mover a linha divisória.' : 'Arraste na imagem para mover a foto. Use o zoom ou a pinça com dois dedos para aproximar.';
    var zb = $('cf-zoom-bloco');
    if (zb) { zb.style.display = C.ferr === 'linha' ? 'none' : 'block'; var z = $('cf-zoom'); if (z && C.ferr !== 'linha') z.value = Math.round(C[C.ferr].zoom * 100); }
    var cv = $('cf-prev'); if (cv) cv.style.touchAction = C.ferr === 'linha' ? 'pan-y' : 'none';
  }
  function atualizarBotoes() {
    var pronto = !!(C && C.a.img && C.b.img);
    var sv = $('cf-btn-salvar'), cp = $('cf-btn-comp');
    [sv, cp].forEach(function (b) { if (b) { b.style.opacity = pronto ? '1' : '.45'; b.style.pointerEvents = pronto ? 'auto' : 'none'; } });
    if (sv && !sv.disabled) sv.innerHTML = C.salvo ? '✓ Salvo na sua avaliação' : '💾 Salvar na minha avaliação';
  }

  // ── gestos na prévia ──
  function ligarCanvas() {
    var cv = $('cf-prev'); if (!cv) return;
    var ptr = {}, ult = 0;
    function base(ev) { var r = cv.getBoundingClientRect(); return { x: (ev.clientX - r.left) / r.width * W0, y: (ev.clientY - r.top) / r.height * H0 }; }
    function n() { return Object.keys(ptr).length; }
    function dist() { var k = Object.keys(ptr); if (k.length < 2) return 0; var a = ptr[k[0]], b = ptr[k[1]]; return Math.hypot(a.x - b.x, a.y - b.y); }
    cv.addEventListener('pointerdown', function (ev) {
      ptr[ev.pointerId] = base(ev);
      try { cv.setPointerCapture(ev.pointerId); } catch (e) {}
      ult = dist();
      if (C.ferr === 'linha' && n() === 1) { C.pos = clamp((ptr[ev.pointerId].x - AX) / AW, 0.04, 0.96); marcarAlterado(); redesenhar(); }
    });
    cv.addEventListener('pointermove', function (ev) {
      if (!ptr[ev.pointerId]) return;
      var p = base(ev), antes = ptr[ev.pointerId];
      ptr[ev.pointerId] = p;
      if (C.ferr === 'linha') { if (n() === 1) { C.pos = clamp((p.x - AX) / AW, 0.04, 0.96); marcarAlterado(); redesenhar(); } return; }
      var f = C[C.ferr]; if (!f || !f.img) return;
      var g = geometria();
      if (n() >= 2) {
        var d = dist();
        if (ult > 0 && d > 0) { f.zoom = clamp(f.zoom * d / ult, 1, 3); var z = $('cf-zoom'); if (z) z.value = Math.round(f.zoom * 100); }
        ult = d;
      } else {
        f.ox += (p.x - antes.x) / g.fw; f.oy += (p.y - antes.y) / g.h;
      }
      marcarAlterado(); redesenhar();
    });
    function fim(ev) { delete ptr[ev.pointerId]; ult = dist(); }
    cv.addEventListener('pointerup', fim); cv.addEventListener('pointercancel', fim);
    cv.addEventListener('wheel', function (ev) {
      if (C.ferr === 'linha') return;
      var f = C[C.ferr]; if (!f || !f.img) return;
      ev.preventDefault();
      f.zoom = clamp(f.zoom * (ev.deltaY < 0 ? 1.06 : 0.94), 1, 3);
      var z = $('cf-zoom'); if (z) z.value = Math.round(f.zoom * 100);
      marcarAlterado(); redesenhar();
    }, { passive: false });
  }

  // ═══════════════ AÇÕES DA TELA ═══════════════
  // A montagem está na tela? A sincronização do app redesenha a tela de Avaliações quando termina e não pode apagar o que a pessoa está montando.
  function ativo() { return !!(C && $('cf-prev')); }
  function voltar() { C = null; renderAvaliacoesAluno(); }
  function ferr(id) { C.ferr = id; atualizarFerr(); }
  function modo(m) {
    if (C.modo === m) return;
    C.modo = m;
    C.ferr = m === 'lado' ? 'a' : 'linha';     // cada estilo começa na ferramenta que faz sentido nele
    C.a.ox = C.a.oy = C.b.ox = C.b.oy = 0;     // a moldura muda de proporção: volta ao centro
    C.salvo = false;
    montar();
  }
  function zoom(v) { var f = C[C.ferr]; if (!f || !f.img) return; f.zoom = clamp(Number(v) / 100, 1, 3); marcarAlterado(); redesenhar(); }
  function campo(q, k, v) { C[q][k] = v; marcarAlterado(); redesenhar(); }
  function mostrar(v) { C.mostrar = !!v; marcarAlterado(); redesenhar(); }

  // ── escolher foto ──
  function escolher(q) {
    C.slotArq = q;
    var s = estilo();
    C.opcoes = opcoesDeFotos();
    var html = '<button onclick="ComparativoFotos.novaFoto()" style="width:100%;padding:13px;border-radius:12px;border:none;background:#7FFF00;color:#0f1a00;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;margin-bottom:14px;">📷 Tirar foto ou escolher da galeria</button>';
    if (C.opcoes.length) {
      html += '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:0 0 8px;">Das minhas avaliações</div>' +
        '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">' + C.opcoes.map(function (o, i) {
          return '<div onclick="ComparativoFotos.daAvaliacao(' + i + ')" style="cursor:pointer;"><img loading="lazy" src="' + esc(_urlAbs(o.url)) + '" alt="Foto da avaliação" style="width:100%;aspect-ratio:3/4;object-fit:cover;border-radius:10px;background:#111;display:block;"/><div style="font-size:10px;color:' + s.mut + ';text-align:center;margin-top:3px;">' + esc(dtBR(o.dt)) + '</div></div>';
        }).join('') + '</div>';
    } else {
      html += '<div style="font-size:12px;color:' + s.mut + ';line-height:1.5;">Você ainda não tem fotos em avaliações. Tire uma foto agora ou escolha uma da galeria.</div>';
    }
    _comSheet('cf-pick', q === 'a' ? 'Foto de antes' : 'Foto de depois', html, 100010);
  }
  function fecharPick() { var e = $('cf-pick'); if (e) e.remove(); }
  function novaFoto() { var f = $('cf-file'); if (f) f.click(); }

  function aplicar(q, im, dt, peso) {
    C[q] = { img: reduzir(im, 1600), dt: dt || '', peso: peso != null && peso !== '' ? String(peso).replace('.', ',') : '', zoom: 1, ox: 0, oy: 0 };
    C.salvo = false;
    fecharPick();
    montar();
  }
  function arquivo(input) {
    var file = input.files && input.files[0]; input.value = '';
    if (!file) return;
    var q = C.slotArq;
    var rd = new FileReader();
    rd.onload = function (e) {
      carregarImg(e.target.result, false).then(function (im) {
        if (!im) { toast('Não consegui abrir essa foto.'); return; }
        var d = file.lastModified ? new Date(file.lastModified) : new Date();
        var iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        aplicar(q, im, iso, '');
      });
    };
    rd.readAsDataURL(file);
  }
  function daAvaliacao(i) {
    var o = C.opcoes[i]; if (!o) return;
    var q = C.slotArq;
    _busyMostrar('Abrindo a foto...', null, '');
    carregarImg(_urlAbs(o.url), true).then(function (im) {
      _busyFim();
      if (!im) { toast('Não consegui abrir essa foto. Tente de novo.'); return; }
      aplicar(q, im, o.dt, o.peso);
    });
  }

  // ═══════════════ SALVAR COMO AVALIAÇÃO ═══════════════
  async function salvar() {
    if (!C || !C.a.img || !C.b.img) { toast('Escolha as duas fotos.'); return; }
    var legenda = 'Comparativo de fotos montado pelo aluno' + (C.a.dt && C.b.dt ? ' (' + dtBR(C.a.dt) + ' e ' + dtBR(C.b.dt) + ')' : '');
    _busyMostrar('Salvando seu comparativo...', null, 'Seus professores serão avisados.');
    try {
      await API.criarAvaliacao({ idatleta: idAtleta, dtavaliacao: hojeIso(), observacao: legenda, metodo: 'Comparativo do aluno', fotos: [dataUrlFinal()], origem: 'aluno' });
      C.salvo = true;
      try { var l = await API.listarAvaliacoes(idAtleta); if (Array.isArray(l)) { l.sort(function (x, y) { return (y.dtavaliacao || '').localeCompare(x.dtavaliacao || ''); }); _avaliacoesCache = l; } } catch (e) {}
      _busyFim();
      toast('Comparativo salvo! Seu professor foi avisado. ✅');
      var s = $('cf-btn-salvar'); if (s) { s.disabled = false; s.style.opacity = ''; s.style.cursor = ''; }
      atualizarBotoes();
    } catch (e) {
      _busyFim();
      toast('Não consegui salvar: ' + ((e && e.message) || 'falha de conexão'));
    }
  }

  // ═══════════════ COMPARTILHAR ═══════════════
  var Sh = { dataUrl: null, legenda: '', visib: 'todos', publicando: false };
  function legendaPadrao() {
    var t = 'Minha evolução com a Intus 💪';
    if (C && C.a.dt && C.b.dt) t += ' De ' + dtBR(C.a.dt) + ' a ' + dtBR(C.b.dt) + '.';
    return t;
  }
  function compartilhar() {
    if (!C || !C.a.img || !C.b.img) { toast('Escolha as duas fotos.'); return; }
    Sh = { dataUrl: dataUrlFinal(), legenda: legendaPadrao(), visib: 'todos', publicando: false };
    folha();
  }
  function abrirSalvo(i) {
    var v = C && C._salvos && C._salvos[i]; if (!v) return;
    _busyMostrar('Abrindo o comparativo...', null, '');
    carregarImg(_urlAbs(v.url), true).then(function (im) {
      _busyFim();
      if (!im) { toast('Não consegui abrir agora. Tente de novo.'); return; }
      var cv = document.createElement('canvas'); cv.width = im.naturalWidth; cv.height = im.naturalHeight;
      cv.getContext('2d').drawImage(im, 0, 0);
      Sh = { dataUrl: cv.toDataURL('image/jpeg', 0.92), legenda: 'Minha evolução com a Intus 💪' + (v.dt ? ' ' + dtBR(v.dt) + '.' : ''), visib: 'todos', publicando: false };
      folha();
    });
  }
  function folha() {
    var s = estilo();
    var chip = function (v, rot) {
      return '<button onclick="ComparativoFotos.setVisib(\'' + v + '\')" style="flex:1;padding:9px 4px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;background:' + (Sh.visib === v ? '#7FFF00' : s.campo) + ';color:' + (Sh.visib === v ? '#0f1a00' : s.txt) + ';border:1px solid ' + (Sh.visib === v ? '#7FFF00' : s.borda) + ';">' + rot + '</button>';
    };
    var html =
      '<img src="' + Sh.dataUrl + '" alt="Comparativo" style="display:block;width:58%;max-width:230px;margin:2px auto 14px;border-radius:14px;box-shadow:0 8px 28px rgba(0,0,0,.35);"/>' +
      '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:0 0 6px;">✏️ Legenda do post</div>' +
      '<textarea id="cf-legenda" maxlength="1000" rows="3" oninput="ComparativoFotos.setLegenda(this.value)" style="width:100%;box-sizing:border-box;padding:10px 12px;border-radius:12px;border:1px solid ' + s.borda + ';background:' + s.campo + ';color:' + s.txt + ';font-size:14px;font-family:inherit;margin-bottom:12px;resize:vertical;">' + esc(Sh.legenda) + '</textarea>' +
      '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:0 0 6px;">🔒 Quem pode ver (Feed e perfil)</div>' +
      '<div style="display:flex;gap:8px;margin-bottom:12px;">' + chip('todos', '🌎 Todos') + chip('amigos', '👥 Amigos') + chip('eu', '🙋 Só eu') + '</div>' +
      '<button onclick="ComparativoFotos.confirmar(\'feed\')" style="width:100%;padding:13px;border-radius:12px;border:none;background:#7FFF00;color:#0f1a00;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;">🌎 Publicar no Feed da Intus</button>' +
      '<button onclick="ComparativoFotos.confirmar(\'perfil\')" style="width:100%;margin-top:8px;padding:12px;border-radius:12px;border:1px solid ' + s.borda + ';background:' + s.card + ';color:' + s.txt + ';font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;">🙋 Publicar só no meu perfil</button>' +
      '<div style="font-size:11px;font-weight:800;color:' + s.mut + ';text-transform:uppercase;letter-spacing:.5px;margin:18px 0 10px;">Compartilhar fora do app</div>' +
      '<div style="display:flex;justify-content:space-around;">' +
        _shareSocialBtn(_shareIconSvgSolido('whatsapp', 24), 'WhatsApp', 'ComparativoFotos.externo(\'whatsapp\')', '#25D366', '#fff', s.txt) +
        _shareSocialBtn(_shareIconSvgSolido('instagram', 20), 'Instagram', 'ComparativoFotos.externo(\'instagram\')', 'radial-gradient(circle at 30% 107%, #fdf497 0%, #fdf497 5%, #fd5949 45%, #d6249f 60%, #285AEB 90%)', '#fff', s.txt) +
        _shareActionBtn(_shareIconSvg('copiar'), 'Copiar', 'ComparativoFotos.externo(\'copiar\')', s.card, s.borda, s.txt) +
        _shareActionBtn(_shareIconSvg('salvar'), 'Baixar', 'ComparativoFotos.externo(\'salvar\')', s.card, s.borda, s.txt) +
      '</div>';
    _comSheet('cf-share', '📤 Compartilhar comparativo', html, 100010);
  }
  function setVisib(v) { Sh.visib = v; var t = $('cf-legenda'); if (t) Sh.legenda = t.value; folha(); }
  function setLegenda(v) { Sh.legenda = v; }

  var QUEM = { todos: 'Todos os alunos da Intus', amigos: 'Só os seus amigos na Intus', eu: 'Só você' };
  // Antes de publicar: aviso claro de onde vai aparecer e quem vai ver. Só publica no "Sim".
  function confirmar(destino) {
    if (Sh.publicando) return;
    var t = $('cf-legenda'); if (t) Sh.legenda = t.value;
    var s = estilo();
    var onde = destino === 'feed' ? 'no Feed da Intus' : 'no seu perfil';
    var html =
      '<img src="' + Sh.dataUrl + '" alt="Comparativo" style="display:block;width:42%;max-width:170px;margin:2px auto 12px;border-radius:12px;box-shadow:0 6px 22px rgba(0,0,0,.35);"/>' +
      '<div style="font-size:15px;font-weight:800;color:' + s.txt + ';text-align:center;margin-bottom:8px;">Publicar ' + onde + '?</div>' +
      '<div style="font-size:13px;color:' + s.mut + ';line-height:1.55;text-align:center;margin-bottom:6px;">Esta imagem tem fotos do seu corpo. Quem poderá ver: <b style="color:' + s.txt + ';">' + QUEM[Sh.visib] + '</b>.</div>' +
      (Sh.visib === 'todos' ? '<div style="font-size:12px;color:' + s.mut + ';text-align:center;margin-bottom:6px;">Se preferir mais privacidade, volte e escolha Amigos ou Só eu.</div>' : '') +
      '<button onclick="_travarEExecutar(event, function(){ return ComparativoFotos.publicar(\'' + destino + '\'); })" style="width:100%;margin-top:12px;padding:13px;border-radius:12px;border:none;background:#7FFF00;color:#0f1a00;font-size:14px;font-weight:800;font-family:inherit;cursor:pointer;">Sim, publicar</button>' +
      '<button onclick="document.getElementById(\'cf-confirma\').remove()" style="width:100%;margin-top:8px;padding:12px;border-radius:12px;border:1px solid ' + s.borda + ';background:' + s.card + ';color:' + s.txt + ';font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;">Voltar</button>';
    _comSheet('cf-confirma', 'Confirmar publicação', html, 100030);
  }
  async function publicar(destino) {
    if (Sh.publicando || !Sh.dataUrl) return;
    if (typeof _soTesteBarra === 'function' && !_soTesteBarra()) return;
    Sh.publicando = true;
    _busyMostrar('Publicando seu comparativo...', null, '');
    try {
      var r = await _fetchT(API_BASE + '/catalogo.php?action=feed_posts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('mx-token') || '') },
        body: JSON.stringify({ imagem: Sh.dataUrl, legenda: String(Sh.legenda || '').trim().slice(0, 1000), destino: destino, visib: Sh.visib })
      }, 60000);
      if (!r.ok) throw new Error('erro ' + r.status);
      _busyFim();
      ['cf-confirma', 'cf-share'].forEach(function (id) { var e = $(id); if (e) e.remove(); });
      toast(destino === 'feed' ? 'Publicado no Feed da Intus! 🎉' : 'Publicado no seu perfil! 🎉');
    } catch (e) {
      _busyFim();
      toast('Não consegui publicar. Tente de novo.');
    } finally { Sh.publicando = false; }
  }
  async function externo(tipo) {
    try {
      var blob = await _dataUrlParaBlob(Sh.dataUrl);
      var fname = 'intus-evolucao-' + hojeIso() + '.jpg';
      if (tipo === 'copiar') {
        if (!navigator.clipboard || !window.ClipboardItem) { toast('Seu navegador não permite copiar imagem. Use Baixar.'); return; }
        await navigator.clipboard.write([new ClipboardItem({ 'image/png': await _blobParaPng(blob) })]);
        toast('Imagem copiada! ✅');
        return;
      }
      if (tipo === 'whatsapp' || tipo === 'instagram') {
        var file = new File([blob], fname, { type: 'image/jpeg' });
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
          try { await navigator.share({ files: [file], title: 'Minha evolução na Intus', text: String(Sh.legenda || '').trim() || undefined }); return; }
          catch (e) { if (e && e.name === 'AbortError') return; }
        }
      }
      _shareMostrarParaSalvar(blob, fname);
    } catch (e) {
      if (e && e.name === 'AbortError') return;
      toast('Não consegui gerar a imagem agora.');
    }
  }

  window.ComparativoFotos = {
    abrir: abrir, ativo: ativo, voltar: voltar, escolher: escolher, novaFoto: novaFoto, arquivo: arquivo, daAvaliacao: daAvaliacao, campo: campo, mostrar: mostrar,
    modo: modo, ferr: ferr, zoom: zoom, salvar: salvar, compartilhar: compartilhar, abrirSalvo: abrirSalvo, setVisib: setVisib,
    setLegenda: setLegenda, confirmar: confirmar, publicar: publicar, externo: externo,
    _teste: { compor: compor, novo: function () { C = novo(); return C; }, estado: function () { return C; }, setLogo: function (l) { _logo = l; } }
  };
})();
