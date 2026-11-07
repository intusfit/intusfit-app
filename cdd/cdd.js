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

  /**
   * Troca o link de compra dos botões [data-meses] pelo da oferta ligada no painel (Asaas, em produção). Sem oferta ou sem rede, fica o link que já
   * está escrito na página. Assim trocar de gateway é cadastrar a oferta no painel, sem publicar a página de novo.
   */
  function brl(v) { return Number(v).toLocaleString('pt-BR', { minimumFractionDigits: Math.abs(v - Math.round(v)) < 0.005 ? 0 : 2, maximumFractionDigits: 2 }); }
  async function linksDeCompra(raiz) {
    var r = await api('ofertas_publicas', { prazo: 6000 });
    if (!r.ok || !r.data || !Array.isArray(r.data.ofertas)) return;
    var ehLink = function (l) { return /^https:\/\/(www\.)?asaas\.com\/c\//.test(l || ''); };
    var por = {};   // meses -> {link, valor}
    r.data.ofertas.forEach(function (o) { if (o.gateway === 'asaas' && ehLink(o.link) && !por[o.meses]) por[o.meses] = { link: o.link, valor: o.valor }; });
    // Cupom (?cupom=CODIGO): o Asaas não tem campo de cupom, então cada cupom é um link já com o desconto. Fica na sessão para a visitante poder navegar.
    var cod = '';
    try {
      var qc = new URLSearchParams(location.search).get('cupom');
      if (qc) sessionStorage.setItem('cdd-cupom', qc.toUpperCase().replace(/[^A-Z0-9_-]/g, '').slice(0, 40));
      cod = sessionStorage.getItem('cdd-cupom') || '';
    } catch (e) {}
    var cp = null;
    (Array.isArray(r.data.cupons) ? r.data.cupons : []).forEach(function (c) { if (!cp && cod && c.codigo === cod && ehLink(c.link)) cp = c; });
    if (cp) por[cp.meses] = { link: cp.link, valor: cp.valor };
    var base = raiz || document;
    base.querySelectorAll('[data-meses]').forEach(function (a) {
      var o = por[a.getAttribute('data-meses')];
      if (o) { a.href = o.link; a.removeAttribute('data-eduzz'); }
    });
    // plano que só existe no Asaas (o semestral) nasce escondido e aparece quando a oferta é ligada
    base.querySelectorAll('[data-so-asaas]').forEach(function (el) { if (por[el.getAttribute('data-so-asaas')]) el.hidden = false; });
    // valor e parcelas vêm da oferta ligada (única fonte): preço, "12x sem juros" e quanto dá por mês
    base.querySelectorAll('[data-asaas-meses]').forEach(function (el) {
      var m = parseInt(el.getAttribute('data-asaas-meses'), 10), o = por[m];
      if (!o || o.valor === null || !(o.valor > 0)) return;
      var preco = el.querySelector('.preco'), nota = el.querySelector('.nota'), n = parseInt(el.getAttribute('data-asaas-parcelas') || '0', 10);
      if (preco && preco.firstChild) preco.firstChild.nodeValue = 'R$ ' + brl(o.valor) + ' ';
      if (nota && n > 1) nota.textContent = 'Ou em até ' + n + 'x de R$ ' + brl(Math.round(o.valor / n * 100) / 100) + ' no cartão de crédito, sem juros.';
    });
    base.querySelectorAll('[data-cupom-aviso]').forEach(function (el) {
      if (cp) { el.textContent = 'Cupom ' + cp.codigo + ' aplicado no plano anual.'; el.hidden = false; } else el.hidden = true;
    });
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

  /** Botão de ver/ocultar senha em TODO campo de senha, de qualquer tela (vale para telas feitas depois também). */
  function decorarSenhas(raiz) {
    (raiz || document).querySelectorAll('input[type=password]').forEach(function (inp) {
      if (inp.getAttribute('data-ver') || !inp.parentNode) return;
      inp.setAttribute('data-ver', '1');
      var cx = document.createElement('span'); cx.className = 'pw';
      inp.parentNode.insertBefore(cx, inp); cx.appendChild(inp);
      var b = document.createElement('button'); b.type = 'button'; b.className = 'pw-ver'; b.setAttribute('aria-label', 'Mostrar a senha'); b.title = 'Mostrar a senha'; b.textContent = '👁️';
      b.onclick = function () {
        var vendo = inp.type === 'text';
        inp.type = vendo ? 'password' : 'text';
        b.textContent = vendo ? '👁️' : '🙈';
        b.setAttribute('aria-label', vendo ? 'Mostrar a senha' : 'Ocultar a senha'); b.title = b.getAttribute('aria-label');
        inp.focus();
      };
      cx.appendChild(b);
    });
  }
  try {
    var obs = new MutationObserver(function () { decorarSenhas(document); });
    var ligar = function () { decorarSenhas(document); obs.observe(document.body, { childList: true, subtree: true }); };
    if (document.body) ligar(); else document.addEventListener('DOMContentLoaded', ligar);
  } catch (e) {}

  window.CDD = { api: api, linksDeCompra: linksDeCompra, esc: esc, ytId: ytId, ytEmbed: ytEmbed, ytThumb: ytThumb, dataBR: dataBR, copiar: copiar, guardar: guardar, token: token, tema: tema, alternarTema: alternarTema, aplicarCapas: aplicarCapas, capaLarga: capaLarga, aplicarMeta: aplicarMeta, claro: claro };
})();
