/* Medição de visitas e cliques das páginas públicas da Intus (app/api/trafego.php).
 * Sem serviço de terceiros e sem cookie de rastreio: o visitante é um código anônimo guardado no próprio navegador. O IP não é gravado.
 * Não mede quem pediu "não rastrear" no navegador nem a equipe logada no painel (para os números não se misturarem com os testes).
 *
 * Uso: <script src="/trafego.js" defer></script>   (outro servidor: data-api="https://.../app/api/trafego.php")
 *   Trafego.evento('nome', 'rotulo', valorInteiro)    clique ou etapa própria da página
 *   data-trk="nome:rotulo" em qualquer botão ou link   registra o clique sozinho
 * Já registra sozinho: a visita, cliques em WhatsApp (whatsapp), ida ao app ou ao login (acessar_app) e links para #form-lead (cta).
 */
(function () {
  'use strict';
  var script = document.currentScript;
  var API = (script && script.getAttribute('data-api')) || '/app/api/trafego.php';
  try { if (navigator.doNotTrack === '1' || window.doNotTrack === '1') return; } catch (e) {}
  try { var u = JSON.parse(localStorage.getItem('mx-user') || 'null'); if (u && u.tipo !== 'aluno') return; } catch (e) {}   // equipe logada: não conta

  var mem = {};
  function ler(store, k) { try { return window[store].getItem(k); } catch (e) { return mem[store + k] || null; } }
  function gravar(store, k, v) { try { window[store].setItem(k, v); } catch (e) { mem[store + k] = v; } }
  function novoId() {
    var s = '';
    try { var a = new Uint8Array(12); crypto.getRandomValues(a); for (var i = 0; i < a.length; i++) s += (a[i] % 36).toString(36); } catch (e) {}
    if (s.length < 12) s = (Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2)).slice(0, 14);
    return s + Date.now().toString(36);
  }
  var vid = ler('localStorage', 'intus-vid'); if (!vid || !/^[a-z0-9]{8,40}$/.test(vid)) { vid = novoId(); gravar('localStorage', 'intus-vid', vid); }
  var sid = ler('sessionStorage', 'intus-sid'); if (!sid || !/^[a-z0-9]{8,40}$/.test(sid)) { sid = novoId(); gravar('sessionStorage', 'intus-sid', sid); }

  // Origem: o que vem na URL vale; sem nada na URL, vale o da sessão (UTM) e, para indicação, o dos últimos 30 dias.
  var q = new URLSearchParams(location.search), utm = {}, achou = false;
  ['source', 'medium', 'campaign', 'content', 'term'].forEach(function (k) { var v = (q.get('utm_' + k) || '').trim(); if (v) { utm['utm_' + k] = v.slice(0, 80); achou = true; } });
  if (achou) gravar('sessionStorage', 'intus-trk-utm', JSON.stringify(utm));
  else { try { utm = JSON.parse(ler('sessionStorage', 'intus-trk-utm') || '{}') || {}; } catch (e) { utm = {}; } }
  var ref = (q.get('ref') || '').trim();
  if (/^\d{1,10}$/.test(ref)) gravar('localStorage', 'intus-trk-ref', JSON.stringify({ v: ref, t: Date.now() }));
  else {
    ref = '';
    try { var r = JSON.parse(ler('localStorage', 'intus-trk-ref') || 'null'); if (r && r.v && Date.now() - r.t < 30 * 86400000) ref = r.v; } catch (e) {}
  }
  var referrer = document.referrer || '';

  function enviar(acao, dados) {
    dados.vid = vid; dados.sid = sid; dados.pagina = location.pathname;
    var corpo = JSON.stringify(dados), url = API + (API.indexOf('?') < 0 ? '?' : '&') + 'action=' + acao;
    try { if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([corpo], { type: 'text/plain' }))) return; } catch (e) {}
    try { fetch(url, { method: 'POST', body: corpo, headers: { 'Content-Type': 'text/plain' }, keepalive: true, credentials: 'omit' }).catch(function () {}); } catch (e) {}
  }
  function evento(nome, rotulo, valor) {
    try { enviar('evento', { evento: String(nome || '').slice(0, 40), rotulo: rotulo == null ? '' : String(rotulo).slice(0, 120), valor: valor == null ? null : valor }); } catch (e) {}
  }
  window.Trafego = { evento: evento, vid: vid, sid: sid };

  var visita = { referrer: referrer, ref: ref, w: window.screen ? window.screen.width : 0 };
  for (var k in utm) if (Object.prototype.hasOwnProperty.call(utm, k)) visita[k] = utm[k];
  enviar('visita', visita);

  // Cliques. Um único ouvinte no documento: funciona também para botões criados depois.
  document.addEventListener('click', function (e) {
    try {
      var el = e.target && e.target.closest ? e.target.closest('a,button,[data-trk]') : null;
      if (!el) return;
      var tk = el.closest('[data-trk]'), marca = tk ? tk.getAttribute('data-trk') : '';
      if (marca) { var p = marca.split(':'); evento(p[0], p.slice(1).join(':') || (el.textContent || '').trim().slice(0, 60)); return; }
      var href = el.getAttribute('href') || '';
      var texto = (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);
      if (/wa\.me|api\.whatsapp\.com|whatsapp:\/\//i.test(href)) {
        var m = href.match(/[?&]text=([^&]+)/), t = '';
        try { t = m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : ''; } catch (x) { t = ''; }
        evento('whatsapp', (t || texto).slice(0, 100));
      } else if (/login\.html|aluno\.html|\/app\/painel\/?$/i.test(href)) evento('acessar_app', texto || href.slice(0, 60));
      else if (href.charAt(0) === '#' && /form|lead|contrat|plano/i.test(href)) evento('cta', texto || href);
    } catch (x) {}
  }, true);
})();
