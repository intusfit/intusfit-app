/* Pixel da Meta do CDD (conjunto de dados "Pixel CDD Site 2026"). Carrega nas LPs junto com lp-track.js.
   Eventos: PageView (ao abrir), InitiateCheckout (clique nos botões de plano), Lead (formulário enviado com sucesso, via cddPixelLead chamado em cdd.js).
   Guarda utm_content e fbclid do anúncio no navegador para gravar junto com o lead (cddAds). Compra (Purchase) entra quando as LPs apontarem para o checkout próprio. */
(function () {
  var PIXEL_ID = '1519184060270437';
  if (window.cddPixelId) return; window.cddPixelId = PIXEL_ID;

  var ads = {};
  try { ads = JSON.parse(localStorage.getItem('cdd_ads') || '{}') || {}; } catch (e) {}
  try {
    var q = new URLSearchParams(location.search), mudou = false;
    ['utm_content', 'fbclid'].forEach(function (k) { var v = q.get(k); if (v) { ads[k] = String(v).slice(0, k === 'fbclid' ? 255 : 120); mudou = true; } });
    if (mudou) localStorage.setItem('cdd_ads', JSON.stringify(ads));
  } catch (e) {}
  window.cddAds = function () { return ads; };

  !function (f, b, e, v, n, t, s) {
    if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); };
    if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = [];
    t = b.createElement(e); t.async = !0; t.src = v; s = b.getElementsByTagName(e)[0]; s.parentNode.insertBefore(t, s);
  }(window, document, 'script', 'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', PIXEL_ID);
  fbq('track', 'PageView');

  var PLANOS = { anual: 1, semestral: 1, mensal: 1 };
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-cta]'); if (!a) return;
    var p = a.getAttribute('data-cta'); if (!PLANOS[p]) return;
    fbq('track', 'InitiateCheckout', { content_name: 'cdd_' + p, content_category: 'consultoria' });
  }, true);

  window.cddPixelLead = function () { try { fbq('track', 'Lead', { content_name: window.CDD_LP || 'cdd' }); } catch (e) {} };
})();
