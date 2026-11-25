/* "Preparar o app": pede as permissões do celular de uma vez só (25/11/2026).
 *
 * iPhone e Android NÃO deixam o app se marcar sozinho como "sempre permitir": a pessoa tem de tocar em Permitir. O que o app faz é juntar
 * os pedidos numa única tela, na primeira vez, para ela não ser interrompida depois no meio do treino ou do cardio. Cada permissão
 * aceita fica guardada pelo sistema e não é pedida de novo.
 *
 * Pede: notificações (lembretes, programas de cardio), localização precisa (cardio ao vivo, inclusive com a tela apagada) e câmera
 * (fotos do treino e do Feed). Só no app das lojas. Na web o navegador pergunta sozinho, na hora de usar.
 *
 * Depende de aluno.html: idAtleta, toast, esc, _comSheet, _progLN, _cardioLivePlugin.
 */
(function () {
  'use strict';

  function nativo() { try { return !!(window.INTUS_NATIVE_APP && window.Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform()); } catch (e) { return false; } }
  function chave() { return 'intus-perm-preparo-' + (typeof idAtleta !== 'undefined' ? idAtleta : ''); }
  function feito() { try { return localStorage.getItem(chave()) === '1'; } catch (e) { return false; } }
  function marcar() { try { localStorage.setItem(chave(), '1'); } catch (e) {} }

  var PASSOS = [
    { id: 'notif', icone: '🔔', titulo: 'Notificações', texto: 'Lembretes de refeição e água e avisos de troca de bloco nos programas de cardio.' },
    { id: 'local', icone: '📍', titulo: 'Localização precisa', texto: 'Mede distância, ritmo e trajeto do Cardio ao vivo, inclusive com a tela bloqueada. Só é usada durante o cardio.' },
    { id: 'camera', icone: '📷', titulo: 'Câmera', texto: 'Fotos do treino, do Feed e das avaliações.' }
  ];

  // Cada função devolve 'ok' | 'negado' | 'indisponivel'. Nunca lança erro.
  var PEDIR = {
    notif: async function () {
      try {
        var ln = (typeof _progLN === 'function') ? _progLN() : null; if (!ln) return 'indisponivel';
        var p = await ln.checkPermissions(); if (p && p.display === 'granted') return 'ok';
        var r = await ln.requestPermissions(); return (r && r.display === 'granted') ? 'ok' : 'negado';
      } catch (e) { return 'negado'; }
    },
    local: async function () {
      try {
        var plug = (typeof _cardioLivePlugin === 'function') ? _cardioLivePlugin() : null; if (!plug) return 'indisponivel';
        // O plugin só pede a permissão ao iniciar uma leitura: inicia e para em seguida.
        var resultado = await new Promise(function (resolve) {
          var terminou = false, idw = null;
          function fim(v) { if (terminou) return; terminou = true; try { if (idw != null) plug.removeWatcher({ id: idw }); } catch (e) {} resolve(v); }
          plug.addWatcher({ backgroundTitle: 'Intus Fit', backgroundMessage: 'Preparando a localização do cardio.', requestPermissions: true, stale: false, distanceFilter: 0 },
            function (loc, err) { if (err) fim((err.code === 'NOT_AUTHORIZED' || /permission|authori/i.test(String(err.message || ''))) ? 'negado' : 'ok'); else if (loc) fim('ok'); })
            .then(function (id) { idw = id; if (terminou) { try { plug.removeWatcher({ id: id }); } catch (e) {} } })
            .catch(function () { fim('negado'); });
          setTimeout(function () { fim('ok'); }, 6000);        // o sistema já respondeu (ou a pessoa ainda decide): não prende a tela
        });
        if (resultado === 'ok') { try { localStorage.setItem('intus-bg-loc', 'ok'); } catch (e) {} }
        return resultado;
      } catch (e) { return 'negado'; }
    },
    camera: async function () {
      try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return 'indisponivel';
        var st = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
        st.getTracks().forEach(function (t) { t.stop(); });
        return 'ok';
      } catch (e) { return (e && (e.name === 'NotAllowedError' || e.name === 'SecurityError')) ? 'negado' : 'indisponivel'; }
    }
  };

  async function pedirTudo() {
    var res = {};
    for (var i = 0; i < PASSOS.length; i++) {
      var id = PASSOS[i].id;
      res[id] = await PEDIR[id]();
      var el = document.getElementById('perm-st-' + id);
      if (el) el.textContent = res[id] === 'ok' ? '✅' : (res[id] === 'negado' ? '⚠️' : '–');
    }
    marcar();
    var negadas = PASSOS.filter(function (p) { return res[p.id] === 'negado'; });
    var s = document.getElementById('perm-sheet'); if (s) s.remove();
    if (negadas.length) toast('Faltou permitir: ' + negadas.map(function (p) { return p.titulo.toLowerCase(); }).join(', ') + '. Você pode liberar em Ajustes do celular > Intus Fit.', 7000);
    else toast('Tudo pronto! Você não vai ser interrompido com esses pedidos de novo. ✅');
    return res;
  }

  function abrir() {
    if (!nativo()) { toast('As permissões são pedidas pelo navegador na hora de usar. No app da Intus elas ficam todas num lugar só.'); return; }
    var L = document.body.classList.contains('tema-claro');
    var mut = L ? '#666' : '#999', borda = L ? '#e5e5e5' : '#262626';
    var html = '<div style="font-size:13px;line-height:1.55;color:' + mut + ';margin-bottom:12px;">Para você não ser interrompido depois, o Intus Fit pede agora, de uma vez, o que o app usa. No celular você precisa tocar em <b>Permitir</b> em cada pedido; o sistema guarda a resposta e não pergunta de novo.</div>' +
      PASSOS.map(function (p) {
        return '<div style="display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-top:1px solid ' + borda + ';"><div style="font-size:22px;line-height:1;">' + p.icone + '</div>' +
          '<div style="flex:1;"><div style="font-size:14px;font-weight:800;">' + esc(p.titulo) + '</div><div style="font-size:12px;line-height:1.45;color:' + mut + ';margin-top:2px;">' + esc(p.texto) + '</div></div>' +
          '<div id="perm-st-' + p.id + '" style="font-size:18px;min-width:22px;text-align:center;"></div></div>';
      }).join('') +
      '<button id="perm-ok" onclick="PermissoesApp.permitir(this)" style="width:100%;margin-top:14px;padding:14px;border-radius:12px;border:none;background:#7FFF00;color:#0f1a00;font-size:14.5px;font-weight:800;font-family:inherit;cursor:pointer;">Continuar e permitir</button>' +
      '<button onclick="PermissoesApp.agoraNao()" style="width:100%;margin-top:8px;padding:12px;border-radius:12px;border:none;background:transparent;color:' + mut + ';font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;">Agora não</button>' +
      '<div style="font-size:11px;line-height:1.5;color:' + mut + ';margin-top:10px;">Localização: no iPhone o sistema pode perguntar de novo, depois de alguns usos, se você quer "Sempre permitir"; escolha essa opção para o cardio continuar com a tela bloqueada. No Android, escolha "Permitir o tempo todo" se o sistema oferecer.</div>';
    _comSheet('perm-sheet', '🔐 Preparar o app', html, 100020);
  }
  async function permitir(btn) {
    if (btn) { btn.disabled = true; btn.textContent = 'Aguarde os pedidos do celular...'; btn.style.opacity = '.6'; }
    await pedirTudo();
  }
  function agoraNao() { marcar(); var s = document.getElementById('perm-sheet'); if (s) s.remove(); }

  // Primeira vez no app das lojas: oferece depois que a tela inicial carregou (e nunca em cima do treino ou do cardio em andamento).
  function oferecerNaPrimeiraVez() {
    try {
      if (!nativo() || feito() || document.getElementById('perm-sheet')) return;
      if (typeof user === 'undefined' || !user || user.tipo !== 'aluno') return;
      if ((window._viewAtual || 'home') !== 'home') return;
      if (typeof treinoTimer !== 'undefined' && treinoTimer) return;
      if (document.querySelector('.com-sheet')) return;
      abrir();
    } catch (e) {}
  }
  setTimeout(oferecerNaPrimeiraVez, 6000);

  window.PermissoesApp = { abrir: abrir, permitir: permitir, agoraNao: agoraNao, feito: feito, _teste: { pedirTudo: pedirTudo, PEDIR: PEDIR, PASSOS: PASSOS } };
})();
