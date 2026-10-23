/* Kanban compartilhado do painel: quadro de colunas com cartões que se arrastam (computador) ou se movem pelo
 * seletor "Mover para" (celular e teclado). Sem dependências.
 *
 * Uso:
 *   var q = Kanban.montar(elemento, {
 *     colunas: [{ id, titulo, tom: 'verde|ambar|azul|verm|', dica, soltar: true|false }],
 *     cartoes: [{ id, coluna, html, bloqueado }],     // html já escapado por quem chama
 *     mover: function (idCartao, deColuna, paraColuna) { return Promise<cartoes novos | undefined>; },   // sem isto o quadro é só leitura
 *     vazio: 'texto da coluna sem cartão',
 *     porColuna: 25                                   // quantos cartões mostrar antes do "mostrar mais"
 *   });
 *   q.atualizar(novosCartoes);  q.destruir();
 *
 * Telas do painel usam a classe .kb-painel no elemento (já mapeia as variáveis do painel). Em outras páginas, defina --kb-bg (fundo do quadro), --kb-col, --kb-card, --kb-borda, --kb-texto, --kb-muted, --kb-accent
 * (e, se quiser, --kb-verde, --kb-ambar, --kb-azul, --kb-verm) a partir das variáveis dela. Só a classe .kb-painel usa tons fixos (nas bordas das colunas).
 */
(function (global) {
  var CSS = ''
    + '.kb-conter{contain:inline-size;max-width:100%}'
    + '.kb{display:flex;gap:12px;align-items:flex-start;overflow-x:auto;padding:2px 2px 12px;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity}'
    + '.kb-topo{overflow-x:auto;overflow-y:hidden;height:16px;margin:0 2px 6px;-webkit-overflow-scrolling:touch}.kb-topo>div{height:1px}'
    + '.kb-col{flex:0 0 288px;max-width:288px;background:var(--kb-col);border:1px solid var(--kb-borda);border-top:3px solid var(--kb-borda);border-radius:12px;display:flex;flex-direction:column;scroll-snap-align:start}'
    + '.kb-t-verde{border-top-color:var(--kb-verde,var(--kb-accent))}.kb-t-ambar{border-top-color:var(--kb-ambar,var(--kb-accent))}'
    + '.kb-t-azul{border-top-color:var(--kb-azul,var(--kb-accent))}.kb-t-verm{border-top-color:var(--kb-verm,var(--kb-accent))}'
    + '.kb-head{display:flex;align-items:center;gap:8px;padding:10px 12px 4px}'
    + '.kb-head b{font-size:13px;color:var(--kb-texto);flex:1;min-width:0;overflow-wrap:anywhere}'
    + '.kb-n{font-size:11px;font-weight:700;background:var(--kb-card);border:1px solid var(--kb-borda);border-radius:99px;padding:1px 8px;color:var(--kb-muted)}'
    + '.kb-dica{font-size:11.5px;color:var(--kb-muted);padding:0 12px 6px;line-height:1.4}'
    + '.kb-corpo{display:flex;flex-direction:column;gap:8px;padding:6px 8px 10px;min-height:56px;max-height:72vh;overflow-y:auto;border-radius:0 0 12px 12px}'
    + '.kb-col.kb-sobre .kb-corpo{outline:2px dashed var(--kb-accent);outline-offset:-4px;background:var(--kb-card)}'
    + '.kb-card{background:var(--kb-card);border:1px solid var(--kb-borda);border-radius:10px;padding:10px;color:var(--kb-texto);font-size:13px;line-height:1.45;cursor:grab}'
    + '.kb-card[draggable=false]{cursor:default}'
    + '.kb-card.kb-arrastando{opacity:.45}.kb-card.kb-ocupado{opacity:.6;pointer-events:none}'
    + '.kb-mv{display:flex;flex-direction:column;gap:3px;margin-top:8px;font-size:11px;color:var(--kb-muted);font-weight:600}'
    + '.kb-mv select{width:100%;min-height:34px;font-size:12.5px;background:var(--kb-bg);color:var(--kb-texto);border:1px solid var(--kb-borda);border-radius:8px;padding:4px 8px}'
    + '.kb-vazio{font-size:12px;color:var(--kb-muted);text-align:center;padding:14px 6px}'
    + '.kb-mais{margin:2px 0 0;min-height:34px;border-radius:8px;border:1px solid var(--kb-borda);background:var(--kb-bg);color:var(--kb-texto);cursor:pointer;font-size:12.5px;font-weight:600}'
    + '.kb-painel{--kb-bg:var(--bg);--kb-col:var(--bg2);--kb-card:var(--bg3,var(--bg));--kb-borda:var(--border);--kb-texto:var(--text);--kb-muted:var(--text-muted);--kb-accent:var(--green);--kb-verde:#4ade80;--kb-ambar:#fbbf24;--kb-azul:#38bdf8;--kb-verm:#f87171}'
    + '.kb-cartao-nome{font-weight:800;font-size:13.5px;color:var(--kb-texto);text-decoration:none;overflow-wrap:anywhere}.kb-meta{font-size:11.5px;color:var(--kb-muted);margin-top:2px}'
    + '.kb-pill{display:inline-block;font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;border:1px solid var(--kb-borda);color:var(--kb-muted);margin:4px 4px 0 0}'
    + '.kb-acoes{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.kb-acoes button,.kb-acoes a{font:inherit;font-size:12px;font-weight:700;padding:5px 10px;border-radius:8px;border:1px solid var(--kb-borda);background:transparent;color:var(--kb-texto);cursor:pointer;text-decoration:none}'
    + '@media (max-width:640px){.kb-col{flex-basis:84vw;max-width:84vw}}';

  function injetarCss() {
    if (document.getElementById('kb-css')) return;
    var s = document.createElement('style'); s.id = 'kb-css'; s.textContent = CSS; (document.head || document.documentElement).appendChild(s);
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function montar(alvo, cfg) {
    injetarCss();
    // o quadro rola por dentro: sem isto a largura das colunas empurra o painel inteiro para o lado em telas com layout flexível
    alvo.classList.add('kb-conter');
    var porColuna = cfg.porColuna || 25;
    var mostrar = {};                                        // coluna -> quantos cartões aparecem
    var editavel = typeof cfg.mover === 'function';
    var arrastando = null, ajustarTopo = function () {};
    var aoRedimensionar = function () { ajustarTopo(); };

    function colunaDe(id) { for (var i = 0; i < cfg.colunas.length; i++) { if (cfg.colunas[i].id === id) return cfg.colunas[i]; } return null; }
    function destinos(exceto) { return cfg.colunas.filter(function (c) { return c.soltar !== false && c.id !== exceto; }); }

    function desenhar() {
      var antes = {}, x = 0, quadro = alvo.querySelector('.kb');
      if (quadro) { x = quadro.scrollLeft; [].forEach.call(quadro.querySelectorAll('.kb-corpo'), function (b) { antes[b.parentNode.getAttribute('data-col')] = b.scrollTop; }); }
      var h = '<div class="kb-topo" aria-hidden="true"><div></div></div><div class="kb" role="list">';
      cfg.colunas.forEach(function (col) {
        var lista = cfg.cartoes.filter(function (c) { return c.coluna === col.id; });
        var lim = mostrar[col.id] || porColuna;
        h += '<section class="kb-col' + (col.tom ? ' kb-t-' + esc(col.tom) : '') + '" role="listitem" data-col="' + esc(col.id) + '" aria-label="' + esc(col.titulo) + '">'
          + '<div class="kb-head"><b>' + esc(col.titulo) + '</b><span class="kb-n">' + lista.length + '</span></div>'
          + (col.dica ? '<div class="kb-dica">' + esc(col.dica) + '</div>' : '')
          + '<div class="kb-corpo">';
        if (!lista.length) h += '<div class="kb-vazio">' + esc(cfg.vazio || 'Ninguém aqui.') + '</div>';
        lista.slice(0, lim).forEach(function (c) {
          var movivel = editavel && !c.bloqueado;
          h += '<article class="kb-card" data-id="' + esc(c.id) + '" data-col="' + esc(col.id) + '" draggable="' + (movivel ? 'true' : 'false') + '">' + c.html;
          if (movivel) {
            var op = destinos(col.id);
            if (op.length) h += '<label class="kb-mv">Mover para<select data-kb-mover>' + '<option value="">Escolha...</option>' + op.map(function (d) { return '<option value="' + esc(d.id) + '">' + esc(d.titulo) + '</option>'; }).join('') + '</select></label>';
          }
          h += '</article>';
        });
        if (lista.length > lim) h += '<button type="button" class="kb-mais" data-kb-mais="' + esc(col.id) + '">Mostrar mais ' + Math.min(porColuna, lista.length - lim) + '</button>';
        h += '</div></section>';
      });
      alvo.innerHTML = h + '</div>';
      var q2 = alvo.querySelector('.kb'), topo = alvo.querySelector('.kb-topo'), lock = null;
      q2.scrollLeft = x;
      // barra de rolagem também em cima: espelha a de baixo (e some quando o quadro cabe na tela)
      ajustarTopo = function () {
        if (!document.body.contains(q2)) return;
        topo.firstChild.style.width = q2.scrollWidth + 'px';
        topo.style.display = q2.scrollWidth > q2.clientWidth + 1 ? '' : 'none';
        topo.scrollLeft = q2.scrollLeft;
      };
      topo.addEventListener('scroll', function () { if (lock === 'q') { lock = null; return; } lock = 't'; q2.scrollLeft = topo.scrollLeft; });
      q2.addEventListener('scroll', function () { if (lock === 't') { lock = null; return; } lock = 'q'; topo.scrollLeft = q2.scrollLeft; });
      ajustarTopo(); setTimeout(ajustarTopo, 200);
      [].forEach.call(q2.querySelectorAll('.kb-col'), function (s) { var b = s.querySelector('.kb-corpo'), v = antes[s.getAttribute('data-col')]; if (v) b.scrollTop = v; });
    }

    function executar(id, de, para) {
      if (!editavel || de === para) return;
      var col = colunaDe(para); if (!col || col.soltar === false) return;
      var card = alvo.querySelector('.kb-card[data-id="' + String(id).replace(/"/g, '') + '"]');
      if (card) card.classList.add('kb-ocupado');
      var r;
      try { r = cfg.mover(id, de, para); } catch (e) { r = null; }
      if (r && typeof r.then === 'function') r.then(function (novos) { if (Array.isArray(novos)) cfg.cartoes = novos; desenhar(); }, function () { desenhar(); });
      else desenhar();
    }

    function aoDragStart(ev) {
      var card = ev.target.closest && ev.target.closest('.kb-card'); if (!card || card.getAttribute('draggable') !== 'true') return;
      arrastando = { id: card.getAttribute('data-id'), de: card.getAttribute('data-col') };
      try { ev.dataTransfer.setData('text/plain', arrastando.id); ev.dataTransfer.effectAllowed = 'move'; } catch (e) {}
      card.classList.add('kb-arrastando');
    }
    function aoDragEnd() { arrastando = null; [].forEach.call(alvo.querySelectorAll('.kb-arrastando,.kb-sobre'), function (e) { e.classList.remove('kb-arrastando'); e.classList.remove('kb-sobre'); }); }
    function colunaSob(ev) { var s = ev.target.closest && ev.target.closest('.kb-col'); return s ? colunaDe(s.getAttribute('data-col')) && s : null; }
    function aoDragOver(ev) {
      if (!arrastando) return;
      var s = colunaSob(ev); if (!s) return;
      var col = colunaDe(s.getAttribute('data-col'));
      if (col.soltar === false || col.id === arrastando.de) return;
      ev.preventDefault(); try { ev.dataTransfer.dropEffect = 'move'; } catch (e) {}
      [].forEach.call(alvo.querySelectorAll('.kb-sobre'), function (e) { if (e !== s) e.classList.remove('kb-sobre'); });
      s.classList.add('kb-sobre');
    }
    function aoDrop(ev) {
      if (!arrastando) return;
      var s = colunaSob(ev); if (!s) return;
      ev.preventDefault();
      var a = arrastando; aoDragEnd();
      executar(a.id, a.de, s.getAttribute('data-col'));
    }
    function aoMudar(ev) {
      var sel = ev.target.closest && ev.target.closest('[data-kb-mover]'); if (!sel || !sel.value) return;
      var card = sel.closest('.kb-card');
      executar(card.getAttribute('data-id'), card.getAttribute('data-col'), sel.value);
    }
    function aoClicar(ev) {
      var m = ev.target.closest && ev.target.closest('[data-kb-mais]'); if (!m) return;
      var id = m.getAttribute('data-kb-mais'); mostrar[id] = (mostrar[id] || porColuna) + porColuna; desenhar();
    }

    window.addEventListener('resize', aoRedimensionar);
    alvo.addEventListener('dragstart', aoDragStart);
    alvo.addEventListener('dragend', aoDragEnd);
    alvo.addEventListener('dragover', aoDragOver);
    alvo.addEventListener('drop', aoDrop);
    alvo.addEventListener('change', aoMudar);
    alvo.addEventListener('click', aoClicar);
    desenhar();

    return {
      atualizar: function (cartoes) { cfg.cartoes = cartoes; desenhar(); },
      destruir: function () {
        alvo.removeEventListener('dragstart', aoDragStart); alvo.removeEventListener('dragend', aoDragEnd);
        alvo.removeEventListener('dragover', aoDragOver); alvo.removeEventListener('drop', aoDrop);
        alvo.removeEventListener('change', aoMudar); alvo.removeEventListener('click', aoClicar); window.removeEventListener('resize', aoRedimensionar); alvo.innerHTML = '';
      }
    };
  }

  global.Kanban = { montar: montar, esc: esc };
})(window);
