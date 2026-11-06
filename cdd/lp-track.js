/* Rastreio das páginas de venda do CDD: visita, cliques nos botões, rolagem e vídeo. Sem cookie de terceiros; o "vid" é um sorteio guardado no navegador. */
(function () {
  var slug = window.CDD_LP; if (!slug) return;
  var vid; try { vid = localStorage.getItem('cdd_vid'); if (!vid) { vid = (Math.random().toString(36).slice(2) + Date.now().toString(36)).slice(0, 20); localStorage.setItem('cdd_vid', vid); } } catch (e) { vid = (Math.random().toString(36).slice(2) + 'x0000000').slice(0, 14); }
  var u = {}; try { new URLSearchParams(location.search).forEach(function (v, k) { u[k] = v; }); } catch (e) {}
  var ja = {};
  function enviar(tipo, alvo, unico) {
    var k = tipo + (alvo || ''); if (unico && ja[k]) return; ja[k] = 1;
    var corpo = JSON.stringify({ slug: slug, tipo: tipo, alvo: alvo || '', vid: vid, utm_source: u.utm_source, utm_medium: u.utm_medium, utm_campaign: u.utm_campaign, ref: document.referrer });
    try {
      var url = 'api/api.php?a=lp_evento';
      if (navigator.sendBeacon) { if (navigator.sendBeacon(url, new Blob([corpo], { type: 'text/plain' }))) return; }
      fetch(url, { method: 'POST', body: corpo, headers: { 'Content-Type': 'application/json' }, keepalive: true });
    } catch (e) {}
  }
  enviar('view', '', true);
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-cta]'); if (a) enviar('click', a.getAttribute('data-cta'), false);
  }, true);
  var t = 0;
  window.addEventListener('scroll', function () {
    if (t) return; t = setTimeout(function () { t = 0;
      var d = document.documentElement, p = (window.scrollY + window.innerHeight) / Math.max(1, d.scrollHeight) * 100;
      if (p >= 50) enviar('scroll50', '', true); if (p >= 90) enviar('scroll90', '', true);
    }, 400);
  }, { passive: true });
  document.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('.yt, [data-yt], .video, .vid')) enviar('video', '', true); }, true);
})();
