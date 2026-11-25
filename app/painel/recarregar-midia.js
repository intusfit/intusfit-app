/* Foto ou vídeo que não carregou: toque para tentar de novo (25/11/2026).
 *
 * Quando uma <img> ou um <video> do app falha (internet ruim, servidor lento), o quadro vira "↻ Toque para recarregar" no mesmo tamanho,
 * sem mexer no layout. Tocar tenta de novo (com um parâmetro novo na URL para não pegar o erro guardado em cache). Quando a internet volta,
 * os quadros pendentes tentam sozinhos. Não mexe em imagens com tratamento de erro próprio (onerror), nem nas muito pequenas (ícones), nem nas escondidas.
 *
 * Carregar ANTES do resto da página (sem defer), para pegar erros de imagens que falham logo no início. Para o app não tratar uma imagem,
 * use o atributo data-sem-recarregar.
 */
(function () {
  'use strict';
  if (window.__recarregarMidia) return;
  var pendentes = [];                 // quadros que estão mostrando "toque para recarregar"

  var css = document.createElement('style');
  css.textContent = '.midia-ph{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;box-sizing:border-box;background:rgba(127,127,127,.16);' +
    'color:#9a9a9a;font:700 11px/1.25 Inter,system-ui,-apple-system,sans-serif;text-align:center;cursor:pointer;border-radius:inherit;overflow:hidden;padding:6px;' +
    '-webkit-tap-highlight-color:transparent;user-select:none;-webkit-user-select:none;}' +
    '.midia-ph b{font-size:20px;line-height:1;color:#bdbdbd;}.midia-ph.carregando b{animation:midiaGira .8s linear infinite;}' +
    '@keyframes midiaGira{to{transform:rotate(360deg);}}';
  (document.head || document.documentElement).appendChild(css);

  function ehVideo(el) { return el && el.tagName === 'VIDEO'; }
  function comBusca(u) {
    if (!u || /^(data|blob):/i.test(u)) return u;
    var base = u.replace(/([?&])_r=\d+(&|$)/, function (m, a, b) { return b === '&' ? a : ''; }).replace(/[?&]$/, '');
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + '_r=' + Date.now();
  }
  function recarregar(el) {
    if (ehVideo(el)) {
      var s = el.getAttribute('src');
      if (s) el.setAttribute('src', comBusca(s));
      Array.prototype.forEach.call(el.querySelectorAll('source'), function (x) { var u = x.getAttribute('src'); if (u) x.setAttribute('src', comBusca(u)); });
      var p = el.getAttribute('poster'); if (p) el.setAttribute('poster', comBusca(p));
      try { el.load(); } catch (e) {}
    } else {
      var u = el.getAttribute('src'); if (u) el.setAttribute('src', comBusca(u));
    }
  }

  function marcar(el) {
    if (!el || !el.parentNode || el.__ph) return;
    var r = el.getBoundingClientRect();
    if (r.width < 40 || r.height < 40) return;                               // ícone ou escondida: deixa como está
    var ph = document.createElement('div');
    ph.className = (el.className ? el.className + ' ' : '') + 'midia-ph';
    ph.setAttribute('style', el.getAttribute('style') || '');
    ph.style.minWidth = Math.round(r.width) + 'px'; ph.style.minHeight = Math.round(r.height) + 'px';
    ph.style.maxWidth = '100%';
    ph.setAttribute('role', 'button'); ph.setAttribute('tabindex', '0');
    ph.setAttribute('aria-label', ehVideo(el) ? 'Recarregar o vídeo' : 'Recarregar a foto');
    ph.innerHTML = '<b>↻</b><span>' + (ehVideo(el) ? 'Toque para recarregar o vídeo' : 'Toque para recarregar') + '</span>';
    ph.__orig = el; el.__ph = ph;
    el.parentNode.replaceChild(ph, el);
    pendentes.push(ph);
  }
  function restaurar(ph) {
    var el = ph.__orig; if (!el || !ph.parentNode) return;
    ph.classList.add('carregando');
    recarregar(el);
    el.__ph = null;
    ph.parentNode.replaceChild(el, ph);
    var i = pendentes.indexOf(ph); if (i >= 0) pendentes.splice(i, 1);
  }

  document.addEventListener('error', function (ev) {
    var el = ev.target; if (!el) return;
    if (el.tagName === 'SOURCE' && el.parentNode && ehVideo(el.parentNode)) el = el.parentNode;
    if (!el || (el.tagName !== 'IMG' && el.tagName !== 'VIDEO')) return;
    if (el.hasAttribute('data-sem-recarregar') || el.hasAttribute('onerror') || el.onerror) return;
    var src = el.getAttribute('src') || (ehVideo(el) && (el.currentSrc || el.getAttribute('poster'))) || '';
    if (!src || /^(data|blob):/i.test(src)) return;
    el.__falhas = (el.__falhas || 0) + 1;
    // O app das lojas tenta uma vez o mesmo endereço no site (outro tratador de erro, registrado depois deste): essa 1ª falha não conta.
    // O setTimeout dá a vez a ele antes de decidirmos.
    var falhas = el.__falhas;
    setTimeout(function () {
      if (window.INTUS_NATIVE_APP && el.__tentouSite && falhas === 1) return;
      marcar(el);
    }, 0);
  }, true);

  function tocou(ev) {
    var ph = ev.target && ev.target.closest && ev.target.closest('.midia-ph'); if (!ph || !ph.__orig) return;
    ev.preventDefault(); ev.stopPropagation();
    restaurar(ph);
  }
  document.addEventListener('click', tocou, true);
  document.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') tocou(ev); }, true);

  // A internet voltou: tenta de novo, escalonado para não disparar tudo de uma vez.
  window.addEventListener('online', function () {
    pendentes.slice(0, 40).forEach(function (ph, i) { setTimeout(function () { if (ph.parentNode) restaurar(ph); }, 300 + i * 150); });
  });

  window.__recarregarMidia = { pendentes: function () { return pendentes.length; }, _comBusca: comBusca };
})();
