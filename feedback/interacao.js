/* Reação e comentários de um feedback em vídeo.
 *
 * Usado pela página do link (/feedback/?v=TOKEN) e pelo app do aluno (aluno.html carrega este arquivo do site, como o player.js, então
 * correção aqui vale sem novo envio às lojas). Os dois falam com o mesmo servidor e o mesmo token, por isso o que o aluno faz por um
 * aparece no outro. API: IntusFeedbackInteracao.montar(elemento, { api, token, headers, claro }).
 *   api     = URL base do feedbacks.php, ex.: '/app/api/feedbacks.php' ou 'https://intusfit.com.br/app/api/feedbacks.php'
 *   headers = cabeçalhos extras (Authorization), opcional
 */
(function () {
  'use strict';
  var REACOES = ['❤️', '🔥', '👏', '💪', '🙏', '👍'];
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function tempo(s) {
    var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return '';
    var min = Math.round((Date.now() - d.getTime()) / 60000);
    if (min < 1) return 'agora'; if (min < 60) return 'há ' + min + ' min';
    var h = Math.round(min / 60); if (h < 24) return 'há ' + h + ' h';
    var dias = Math.round(h / 24); if (dias < 8) return 'há ' + dias + ' d';
    return d.toLocaleDateString('pt-BR');
  }

  function montar(el, op) {
    var st = { reacao: '', coms: [], carregou: false, enviando: false };
    var claro = !!op.claro;
    var cor = claro ? { txt: '#1a1a1a', mut: '#777', borda: '#e3e3e3', card: '#fff', ok: '#2d8a00', campo: '#fff' } : { txt: '#f0f0f0', mut: '#a5a5a5', borda: '#2a2a2a', card: '#1a1a1a', ok: '#7FFF00', campo: '#141414' };
    function url(acao, extra) { return op.api + '?action=' + acao + (extra || ''); }
    function hdr(json) { var h = {}; var k; for (k in (op.headers || {})) h[k] = op.headers[k]; if (json) h['Content-Type'] = 'application/json'; return h; }

    function desenhar() {
      var rea = REACOES.map(function (e) {
        var on = st.reacao === e;
        return '<button type="button" data-rea="' + e + '" aria-label="Reagir com ' + e + '" style="flex:1;padding:9px 0;border-radius:12px;font-size:20px;line-height:1;cursor:pointer;font-family:inherit;border:1.5px solid ' + (on ? cor.ok : cor.borda) + ';background:' + (on ? 'rgba(127,255,0,.14)' : 'transparent') + ';opacity:' + (st.reacao && !on ? '.5' : '1') + ';">' + e + '</button>';
      }).join('');
      var lista = st.coms.length ? st.coms.map(function (c) {
        var equipe = c.autor_tipo === 'prof';
        return '<div style="padding:9px 0;border-top:1px solid ' + cor.borda + ';display:flex;gap:8px;">' +
          '<div style="flex:1;min-width:0;"><div style="font-size:13px;line-height:1.5;word-break:break-word;"><b>' + esc(c.autor_nome || (equipe ? 'Equipe' : 'Aluno')) + '</b>' +
          (equipe ? ' <span style="color:' + cor.ok + ';font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;">professor</span>' : '') + ' ' + esc(c.texto) + '</div>' +
          '<div style="font-size:11px;color:' + cor.mut + ';margin-top:2px;">' + tempo(c.criado_em) + (c.meu ? ' · <span data-apagar="' + c.id + '" style="cursor:pointer;text-decoration:underline;">Apagar</span>' : '') + '</div></div></div>';
      }).join('') : '<div style="font-size:12.5px;color:' + cor.mut + ';padding:6px 0 2px;">Nenhum comentário ainda. Conte o que achou ou tire uma dúvida.</div>';
      el.innerHTML = '<div style="margin-top:16px;color:' + cor.txt + ';">' +
        '<div style="font-size:12px;font-weight:800;color:' + cor.mut + ';text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">Sua reação</div>' +
        '<div style="display:flex;gap:6px;">' + rea + '</div>' +
        '<div style="font-size:12px;font-weight:800;color:' + cor.mut + ';text-transform:uppercase;letter-spacing:.05em;margin:18px 0 4px;">Comentários' + (st.coms.length ? ' (' + st.coms.length + ')' : '') + '</div>' +
        lista +
        '<div style="display:flex;gap:6px;margin-top:10px;align-items:flex-end;">' +
          '<textarea data-campo maxlength="500" rows="2" placeholder="Escreva um comentário para o seu professor..." style="flex:1;min-width:0;padding:10px 12px;border-radius:12px;border:1px solid ' + cor.borda + ';background:' + cor.campo + ';color:' + cor.txt + ';font-size:14px;line-height:1.4;font-family:inherit;resize:vertical;"></textarea>' +
          '<button type="button" data-enviar style="padding:11px 16px;border-radius:12px;border:none;background:' + cor.ok + ';color:#0f1a00;font-size:13.5px;font-weight:800;cursor:pointer;font-family:inherit;">Enviar</button>' +
        '</div>' +
        '<div data-aviso style="font-size:12px;color:#f59e0b;margin-top:6px;" hidden></div></div>';
    }
    function aviso(t) { var a = el.querySelector('[data-aviso]'); if (a) { a.textContent = t || ''; a.hidden = !t; } }

    function carregar() {
      return fetch(url('interacoes', '&v=' + encodeURIComponent(op.token) + '&_=' + Date.now()), { headers: hdr(false), cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.ok) { st.reacao = j.reacao || ''; st.coms = j.comentarios || []; st.carregou = true; var rasc = (el.querySelector('[data-campo]') || {}).value || ''; desenhar(); if (rasc) el.querySelector('[data-campo]').value = rasc; } })
        .catch(function () {});
    }
    function enviar(corpo, acao) {
      corpo.v = op.token;
      return fetch(url(acao || 'interagir'), { method: 'POST', headers: hdr(true), body: JSON.stringify(corpo) }).then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
    }

    el.addEventListener('click', function (ev) {
      var t = ev.target;
      var b = t.closest ? t.closest('[data-rea]') : null;
      if (b) {   // toque na mesma reação tira
        var e = b.getAttribute('data-rea'), antes = st.reacao, nova = antes === e ? '' : e;
        st.reacao = nova; desenhar();
        enviar({ tipo: 'reacao', emoji: nova }).then(function (j) { if (!j.ok) { st.reacao = antes; desenhar(); aviso(j.erro || 'Não consegui registrar. Tente de novo.'); } });
        return;
      }
      var ap = t.closest ? t.closest('[data-apagar]') : null;
      if (ap) { enviar({ id: Number(ap.getAttribute('data-apagar')) }, 'apagar_interacao').then(function () { carregar(); }); return; }
      if (t.closest && t.closest('[data-enviar]')) {
        if (st.enviando) return;
        var campo = el.querySelector('[data-campo]'), texto = (campo.value || '').trim();
        if (!texto) { aviso('Escreva algo antes de enviar.'); return; }
        st.enviando = true; aviso('');
        enviar({ tipo: 'comentario', texto: texto }).then(function (j) {
          st.enviando = false;
          if (j.ok) { campo.value = ''; carregar(); } else aviso(j.erro === 'muitas_tentativas' ? 'Muitos envios seguidos. Tente de novo em alguns minutos.' : (j.erro || 'Não consegui enviar. Tente de novo.'));
        }).catch(function () { st.enviando = false; aviso('Sem conexão. Tente de novo.'); });
      }
    });
    desenhar();
    carregar();
    return { atualizar: carregar };
  }
  window.IntusFeedbackInteracao = { montar: montar };
})();
