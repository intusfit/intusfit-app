/* CDD: cliente da API e utilidades das telas. Só caminhos relativos: funciona em intusfit.com.br/cdd e no domínio próprio. */
(function () {
  var script = document.currentScript;
  var API = new URL('api/api.php', script.src).href;
  var CHAVE = 'cdd-token';

  function guardar(t) { try { if (t) localStorage.setItem(CHAVE, t); else localStorage.removeItem(CHAVE); } catch (e) {} }
  function token() { try { return localStorage.getItem(CHAVE) || ''; } catch (e) { return ''; } }

  async function api(acao, opc) {
    opc = opc || {};
    var url = API + '?a=' + encodeURIComponent(acao);
    if (opc.q) Object.keys(opc.q).forEach(function (k) { url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opc.q[k]); });
    var h = { 'Content-Type': 'application/json' };
    var t = token(); if (t) h['X-CDD-Token'] = t;
    var ctl = typeof AbortController !== 'undefined' ? new AbortController() : null;
    var prazo = ctl ? setTimeout(function () { ctl.abort(); }, opc.prazo || 25000) : null;
    var r;
    try {
      r = await fetch(url, { method: opc.body !== undefined ? 'POST' : 'GET', headers: h, body: opc.body !== undefined ? JSON.stringify(opc.body) : undefined, cache: 'no-store', signal: ctl ? ctl.signal : undefined });
    } catch (e) { return { ok: false, status: 0, data: { error: 'rede', mensagem: 'Sem conexão. Verifique a internet e tente de novo.' } }; }
    finally { if (prazo) clearTimeout(prazo); }
    var d = {}; try { d = await r.json(); } catch (e) { d = { error: 'resposta', mensagem: 'Resposta inesperada do servidor.' }; }
    return { ok: r.ok, status: r.status, data: d };
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function ytId(v) { return /^[\w-]{11}$/.test(v || '') ? v : ''; }
  function ytEmbed(id) { return 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?rel=0&modestbranding=1&playsinline=1'; }
  function ytThumb(id) { return 'https://i.ytimg.com/vi/' + encodeURIComponent(id) + '/mqdefault.jpg'; }
  function dataBR(s) { if (!s) return ''; var p = String(s).slice(0, 10).split('-'); return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : s; }
  function copiar(txt, botao) {
    function ok() { if (botao) { var o = botao.textContent; botao.textContent = 'Copiado'; setTimeout(function () { botao.textContent = o; }, 1500); } }
    try { navigator.clipboard.writeText(txt).then(ok, function () { sel(); }); } catch (e) { sel(); }
    function sel() { var i = document.getElementById('cdd-copia'); if (i) { i.focus(); i.select(); } }
  }
  /** Fundo da capa: aplicado por JS (data-capa) para não montar CSS com texto vindo do painel. */
  function aplicarCapas(raiz) {
    (raiz || document).querySelectorAll('[data-capa]').forEach(function (el) {
      var u = el.getAttribute('data-capa');
      if (/^marca\/capas\/[a-z0-9-]+(-wide)?\.(jpe?g|png|webp)$/i.test(u)) el.style.backgroundImage = 'url(' + JSON.stringify(u) + ')';   // capa pronta: já vem com o degradê e o título na imagem
      else if (/^https:\/\//i.test(u)) el.style.backgroundImage = 'linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.25)),url(' + JSON.stringify(u) + ')';
    });
  }
  /** Versão horizontal (sem título) de uma capa pronta, para o destaque e o cabeçalho do módulo. Link de fora fica como está. */
  function capaLarga(u) { return /^marca\/capas\/[a-z0-9-]+\.(jpe?g|png|webp)$/i.test(u || '') ? u.replace(/\.(jpe?g|png|webp)$/i, '-wide.$1') : (u || ''); }
  function claro() { return document.body.classList.contains('tema-claro'); }
  function aplicarMeta() {
    var m = document.querySelector('meta[name=theme-color]'); if (m) m.setAttribute('content', claro() ? '#f4f2ef' : '#0b0b0f');
    document.querySelectorAll('[data-tema-btn]').forEach(function (b) { b.textContent = claro() ? '🌙' : '☀️'; b.setAttribute('aria-label', claro() ? 'Mudar para o tema escuro' : 'Mudar para o tema claro'); });
  }
  function tema() {
    try { if (localStorage.getItem('cdd-tema') === 'claro') document.body.classList.add('tema-claro'); } catch (e) {}
    aplicarMeta();
  }
  function alternarTema() {
    document.body.classList.toggle('tema-claro');
    try { localStorage.setItem('cdd-tema', claro() ? 'claro' : 'escuro'); } catch (e) {}
    aplicarMeta();
    return claro();
  }

  window.CDD = { api: api, esc: esc, ytId: ytId, ytEmbed: ytEmbed, ytThumb: ytThumb, dataBR: dataBR, copiar: copiar, guardar: guardar, token: token, tema: tema, alternarTema: alternarTema, aplicarCapas: aplicarCapas, capaLarga: capaLarga, aplicarMeta: aplicarMeta, claro: claro };
})();
