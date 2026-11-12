/* Moderação no app do aluno: denunciar, bloquear, Pessoas bloqueadas, aceite das Regras da Comunidade e o aviso com o MOTIVO quando o servidor recusa.
   Exigência das lojas: Apple (Guideline 1.2) e Google Play (conteúdo gerado por usuário) pedem filtro, denúncia dentro do app com resposta em prazo,
   bloqueio de usuário abusivo e contato publicado.

   A REGRA mora no servidor (app/api/_moderacao.php). Aqui ficam só a tela e as chamadas: os motivos da denúncia, o prazo de resposta e o contato
   vêm do servidor (action=regras_comunidade), então mudar um deles não exige publicar o app. As cores vêm das variáveis do aluno.html (--card-bg,
   --texto, --borda, --muted, --alerta), declaradas nos dois temas.

   Carregado por aluno.html com defer (mesmo padrão do ajuda.js). Arquivo novo precisa estar em sw.js e em mobile/scripts/build-web.mjs.

   API pública (window.Moderacao):
     denunciar(tipo, id, {autorId, nome})   abre a folha de denúncia. tipo: feed_post | comentario | mural | resultado | dm | perfil
     denunciarEl(el)                        o mesmo, lendo data-mod-* do elemento clicado (nome de terceiro nunca vai dentro de onclick)
     bloquear(idAlvo, nome)                 confirma e bloqueia
     menuPessoa(id, nome) / menuPessoaEl(el) folha com "Denunciar" e "Bloquear" de uma pessoa
     abrirBloqueados()                      tela "Pessoas bloqueadas", com Desbloquear
     regrasBarra()                          true = pode publicar; false = abriu as Regras da Comunidade para o aluno aceitar
     verRegras()                            abre as regras (com o botão de aceitar se ainda não aceitou)
     linkHtml / iconeHtml / linkPerfilHtml / botaoMaisHtml   pedaços de HTML para as telas
     carregar()                             lê do servidor o aceite das regras e os motivos */
(function () {
  'use strict';
  if (window.Moderacao) return;

  // Motivos de reserva (a lista de verdade vem do servidor, em regras_comunidade; esta só cobre o caso de a leitura falhar).
  var MOTIVOS_RESERVA = {
    spam: 'Spam ou propaganda',
    assedio: 'Assédio, bullying ou ameaça',
    odio: 'Ódio ou discriminação',
    sexual: 'Nudez ou conteúdo sexual',
    violencia: 'Violência ou conteúdo chocante',
    substancias: 'Venda ou incentivo de substâncias proibidas',
    falso: 'Perfil falso, golpe ou fraude',
    outro: 'Outro motivo'
  };
  var ROTULO_TIPO = {
    feed_post: 'publicação', comentario: 'comentário', mural: 'comentário do mural', resultado: 'resultado',
    dm: 'mensagem', perfil: 'perfil'
  };
  var ARTIGO_TIPO = { feed_post: 'uma', comentario: 'um', mural: 'um', resultado: 'um', dm: 'uma', perfil: 'o' };   // "uma publicação", "um comentário", "o perfil"
  var Z = 100200;          // acima das folhas do app (100000+); o aviso (toast) fica por cima de tudo que não é folha
  var estado = { aceito: null, versao: '', motivos: null, contato: 'contato@intusfit.com.br', prazo: 24, carregando: null };

  // ───────────────────────── utilidades ─────────────────────────
  function h(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function meuId() { try { return typeof idAtleta !== 'undefined' && idAtleta ? Number(idAtleta) : 0; } catch (e) { return 0; } }
  function aviso(txt, ms) { try { if (typeof toast === 'function') toast(txt, ms); } catch (e) {} }
  function chaveAceite() { return 'intus-regras-ok-' + meuId(); }
  function lsAceite() { try { return localStorage.getItem(chaveAceite()) || ''; } catch (e) { return ''; } }
  function lsGravar(v) { try { if (v) localStorage.setItem(chaveAceite(), v); else localStorage.removeItem(chaveAceite()); } catch (e) {} }
  function motivos() { return estado.motivos && Object.keys(estado.motivos).length ? estado.motivos : MOTIVOS_RESERVA; }
  function nomeDe(id, nome) {
    if (nome) return String(nome);
    try { if (typeof _feedAtletasMap !== 'undefined' && _feedAtletasMap[String(id)]) return String(_feedAtletasMap[String(id)]); } catch (e) {}
    try { if (typeof _rankingData !== 'undefined' && _rankingData && _rankingData.atletas && _rankingData.atletas[String(id)]) return String(_rankingData.atletas[String(id)]); } catch (e) {}
    return 'esta pessoa';
  }
  function primeiro(nome) { return String(nome || '').trim().split(/\s+/)[0] || 'esta pessoa'; }

  // Chamada à API com o token do aluno; erro vira Error com .status e .data (a mensagem do servidor fica em .message).
  function api(acao, metodo, corpo, query) {
    var url = API_BASE + '/catalogo.php?action=' + acao + (query || '') + '&_=' + Date.now();
    var opts = { method: metodo || 'GET', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + (localStorage.getItem('mx-token') || '') }, body: corpo ? JSON.stringify(corpo) : undefined };
    var chamada = typeof _fetchT === 'function' ? _fetchT(url, opts, 15000) : fetch(url, opts);
    return chamada.then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { var e = new Error((j && j.error) || 'falhou'); e.status = r.status; e.data = j; throw e; }
        return j;
      });
    });
  }

  // ───────────────────────── a recusa do servidor chega com o MOTIVO ─────────────────────────
  // Suspensão, texto impróprio e bloqueio voltam do servidor com a mensagem pronta (error) e um código. Cada tela do app tem o seu "Não consegui..."
  // genérico; sem isto o aluno nunca saberia o porquê. O aviso entra 350 ms depois, para ficar POR CIMA do genérico (toast troca o texto do anterior).
  function avisoDoServidor(resp) {
    resp.json().then(function (j) {
      if (!j || !j.error) return;
      if (j.codigo === 'suspenso' || j.codigo === 'conteudo_improprio' || j.codigo === 'bloqueado') {
        setTimeout(function () { aviso(j.error, j.codigo === 'suspenso' ? 12000 : 6000); }, 350);
      }
    }).catch(function () {});
  }
  (function envolverFetch() {
    var original = window.fetch;
    if (typeof original !== 'function' || original._intusMod) return;
    var novo = function () {
      var p = original.apply(this, arguments);
      try {
        var alvo = arguments[0];
        var url = String(alvo && alvo.url ? alvo.url : alvo || '');
        if (url.indexOf('/catalogo.php') >= 0 || url.indexOf('/treinos.php') >= 0) {
          p.then(function (r) { if (r && (r.status === 403 || r.status === 422)) avisoDoServidor(r.clone()); }).catch(function () {});
        }
      } catch (e) {}
      return p;   // quem chamou recebe a MESMA promessa, intacta
    };
    novo._intusMod = true;
    window.fetch = novo;
  })();

  // ───────────────────────── folhas (bottom sheet) ─────────────────────────
  function fecharFolha(id) { var el = document.getElementById(id); if (el && el.parentNode) el.parentNode.removeChild(el); }
  function abrirFolha(id, titulo, corpoHtml, opts) {
    opts = opts || {};
    fecharFolha(id);
    var ov = document.createElement('div');
    ov.id = id;
    ov.className = 'pk-trava';   // o aluno.html mantém a rolagem da tela de trás travada enquanto houver um elemento com esta classe (_pkTravarFundo)
    ov.setAttribute('role', 'dialog');
    ov.setAttribute('aria-modal', 'true');
    ov.style.cssText = 'position:fixed;inset:0;z-index:' + (opts.z || Z) + ';background:rgba(0,0,0,.6);display:flex;align-items:flex-end;justify-content:center;overscroll-behavior:contain;';
    ov.onclick = function (e) { if (e.target === ov) fecharFolha(id); };
    ov.addEventListener('touchmove', function (e) { if (e.target === ov) e.preventDefault(); }, { passive: false });
    ov.innerHTML =
      '<div style="background:var(--card-bg,#1a1a1a);color:var(--texto,#fff);border-radius:22px 22px 0 0;width:100%;max-width:460px;max-height:90vh;max-height:90dvh;display:flex;flex-direction:column;padding-bottom:env(safe-area-inset-bottom,0px);">' +
        '<div style="flex-shrink:0;padding:14px 18px 10px;display:flex;align-items:center;justify-content:space-between;gap:10px;border-bottom:1px solid var(--borda,#2a2a2a);">' +
          '<b style="font-size:16px;line-height:1.3;">' + titulo + '</b>' +
          '<button type="button" onclick="Moderacao._fechar(\'' + id + '\')" aria-label="Fechar" style="width:34px;height:34px;flex-shrink:0;border-radius:50%;border:none;background:var(--borda,#2a2a2a);color:var(--texto,#fff);font-size:16px;cursor:pointer;">✕</button>' +
        '</div>' +
        '<div style="overflow-y:auto;min-height:0;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;padding:6px 18px 20px;">' + corpoHtml + '</div>' +
      '</div>';
    document.body.appendChild(ov);
    try { if (typeof _pkTravarFundo === 'function') _pkTravarFundo(); } catch (e) {}
    return ov;
  }
  var BTN_BASE = 'width:100%;padding:13px;border-radius:12px;font-size:14.5px;font-weight:800;cursor:pointer;font-family:inherit;margin-top:10px;';
  var BTN_PRIM = BTN_BASE + 'border:none;background:#7FFF00;color:#0f1a00;';
  var BTN_PERIGO = BTN_BASE + 'border:1.5px solid var(--alerta,#ff8a3d);background:transparent;color:var(--alerta,#ff8a3d);';
  var BTN_SEC = BTN_BASE + 'border:1px solid var(--borda,#2a2a2a);background:transparent;color:var(--muted,#888);';

  // ───────────────────────── denunciar ─────────────────────────
  var _den = null;   // denúncia em andamento: { tipo, id, autorId, nome, enviando }

  function denunciar(tipo, id, opts) {
    opts = opts || {};
    id = Number(id);
    if (!ROTULO_TIPO[tipo] || !(id > 0)) return;
    _den = { tipo: tipo, id: id, autorId: Number(opts.autorId || 0), nome: nomeDe(opts.autorId, opts.nome), enviando: false };
    pintarDenuncia();
    if (!estado.motivos) carregar().then(function () {
      var det = document.getElementById('mod-detalhe');
      if (_den && estado.motivos && document.getElementById('mod-den') && !document.querySelector('#mod-den input[name="mod-motivo"]:checked') && !(det && det.value)) pintarDenuncia();
    });
  }
  function denunciarEl(el) {
    var d = (el && el.dataset) || {};
    denunciar(d.modTipo, d.modId, { autorId: d.modAutor, nome: d.modNome });
  }
  function pintarDenuncia() {
    if (!_den) return;
    var pode = _den.autorId > 0 && _den.autorId !== meuId();
    var lista = motivos();
    var radios = Object.keys(lista).map(function (k) {
      return '<label style="display:flex;align-items:center;gap:10px;padding:11px 0;border-bottom:1px solid var(--borda,#2a2a2a);cursor:pointer;font-size:14px;">' +
        '<input type="radio" name="mod-motivo" value="' + h(k) + '" style="width:20px;height:20px;flex-shrink:0;accent-color:#7FFF00;"/><span>' + h(lista[k]) + '</span></label>';
    }).join('');
    var corpo =
      '<div style="font-size:12.5px;color:var(--muted,#888);line-height:1.5;margin:8px 0 2px;">Você está denunciando ' + ARTIGO_TIPO[_den.tipo] + ' <b>' + h(ROTULO_TIPO[_den.tipo]) + '</b>' +
        (_den.autorId > 0 ? ' de <b>' + h(_den.nome) + '</b>' : '') + '. Escolha o motivo:</div>' +
      '<div style="margin-bottom:4px;">' + radios + '</div>' +
      '<textarea id="mod-detalhe" maxlength="500" rows="3" placeholder="Quer explicar melhor? (opcional)" style="width:100%;box-sizing:border-box;margin-top:12px;padding:10px 12px;border-radius:12px;border:1px solid var(--borda,#2a2a2a);background:transparent;color:var(--texto,#fff);font-size:14px;font-family:inherit;resize:vertical;"></textarea>' +
      (pode ? '<label style="display:flex;align-items:center;gap:10px;margin-top:12px;font-size:13.5px;cursor:pointer;"><input type="checkbox" id="mod-bloquear" style="width:20px;height:20px;flex-shrink:0;accent-color:#7FFF00;"/><span>Bloquear também <b>' + h(primeiro(_den.nome)) + '</b></span></label>' : '') +
      '<div id="mod-erro" style="font-size:12.5px;color:var(--alerta,#ff8a3d);min-height:16px;margin-top:8px;" role="alert"></div>' +
      '<button type="button" id="mod-enviar" onclick="Moderacao._enviarDenuncia()" style="' + BTN_PRIM + '">Enviar denúncia</button>' +
      '<div style="font-size:11.5px;color:var(--muted,#888);line-height:1.5;margin-top:10px;text-align:center;">A equipe da Intus analisa em até ' + Number(estado.prazo || 24) + ' horas. A pessoa denunciada não sabe quem denunciou.</div>';
    abrirFolha('mod-den', '🚩 Denunciar', corpo);
  }
  function enviarDenuncia() {
    if (!_den || _den.enviando) return;
    var sel = document.querySelector('#mod-den input[name="mod-motivo"]:checked');
    var erro = document.getElementById('mod-erro');
    if (!sel) { if (erro) erro.textContent = 'Escolha um motivo para continuar.'; return; }
    var det = document.getElementById('mod-detalhe'), blq = document.getElementById('mod-bloquear');
    var btn = document.getElementById('mod-enviar');
    var d = _den;
    d.enviando = true;
    if (btn) { btn.disabled = true; btn.textContent = 'Enviando...'; }
    if (erro) erro.textContent = '';
    api('denuncias', 'POST', { alvo_tipo: d.tipo, alvo_id: d.id, motivo: sel.value, detalhe: det ? det.value.trim() : '', bloquear: !!(blq && blq.checked) }).then(function (j) {
      fecharFolha('mod-den');
      _den = null;
      ocultarDepoisDeDenunciar(d.tipo, d.id);
      if (j && j.bloqueado) {
        aviso('Denúncia enviada e ' + primeiro(d.nome) + ' foi bloqueado(a). A equipe analisa em até ' + Number(estado.prazo || 24) + ' horas.', 6000);
        depoisDeMudarBloqueio(d.autorId);
      } else if (j && j.ja) {
        aviso('Você já denunciou isto. A equipe está analisando.', 5000);
      } else {
        aviso('Denúncia enviada. A equipe analisa em até ' + Number(estado.prazo || 24) + ' horas. Obrigado por ajudar!', 6000);
      }
    }).catch(function (e) {
      d.enviando = false;
      if (btn) { btn.disabled = false; btn.textContent = 'Enviar denúncia'; }
      var cod = (e && e.data && e.data.codigo) || '';
      var msg = cod === 'proprio' ? 'Você não pode denunciar o seu próprio conteúdo.'
        : cod === 'nao_encontrado' ? 'Este conteúdo não está mais disponível.'
        : cod === 'limite' ? 'Você já fez muitas denúncias hoje. A equipe está analisando as anteriores.'
        : (e && e.status) ? 'Não consegui enviar agora. Tente de novo em instantes.'
        : 'Sem conexão. Verifique a internet e tente de novo.';
      if (erro) erro.textContent = msg;
    });
  }
  // Quem denuncia não precisa continuar vendo o que denunciou, enquanto a equipe analisa (só nesta tela; volta se a equipe disser que não viola).
  function ocultarDepoisDeDenunciar(tipo, id) {
    var seletor = tipo === 'feed_post' ? '[data-feed-card="' + id + '"]' : (tipo === 'resultado' ? '[data-resultado-card="' + id + '"]' : (tipo === 'mural' ? '[data-mural-card="' + id + '"]' : ''));
    if (!seletor) return;
    try { document.querySelectorAll(seletor).forEach(function (el) { el.style.display = 'none'; }); } catch (e) {}
    if (tipo === 'feed_post') fecharFolha('pk-post-ov');   // a tela do post aberta por cima também fecha
  }

  // ───────────────────────── bloquear ─────────────────────────
  function bloquear(idAlvo, nome) {
    idAlvo = Number(idAlvo);
    if (!(idAlvo > 0) || idAlvo === meuId()) return;
    var n = nomeDe(idAlvo, nome);
    abrirFolha('mod-blq', '⛔ Bloquear ' + h(primeiro(n)) + '?',
      '<div style="font-size:13.5px;line-height:1.6;margin:10px 0 4px;">Vocês deixam de se ver no Feed, nos comentários, no mural, nos resultados e nas mensagens, e ' + h(primeiro(n)) + ' não consegue mais interagir com você. Se vocês são amigos, a amizade acaba.</div>' +
      '<div style="font-size:12.5px;color:var(--muted,#888);line-height:1.5;">Você pode desbloquear quando quiser em <b>Meu perfil › Privacidade › Pessoas bloqueadas</b>. A pessoa não é avisada.</div>' +
      '<button type="button" id="mod-blq-ok" data-id="' + idAlvo + '" data-nome="' + h(n) + '" onclick="Moderacao._confirmarBloqueio(this)" style="' + BTN_PERIGO + '">Bloquear</button>' +
      '<button type="button" onclick="Moderacao._fechar(\'mod-blq\')" style="' + BTN_SEC + '">Cancelar</button>');
  }
  function confirmarBloqueio(el) {
    var id = Number(el.dataset.id), n = el.dataset.nome || 'a pessoa';
    el.disabled = true; el.textContent = 'Bloqueando...';
    api('bloqueios', 'POST', { idalvo: id }).then(function () {
      fecharFolha('mod-blq');
      aviso(primeiro(n) + ' foi bloqueado(a). Você não vê mais essa pessoa.', 5000);
      depoisDeMudarBloqueio(id);
    }).catch(function (e) {
      el.disabled = false; el.textContent = 'Bloquear';
      aviso((e && e.status) ? 'Não consegui bloquear agora. Tente de novo em instantes.' : 'Sem conexão. Verifique a internet e tente de novo.', 5000);
    });
  }
  // O servidor já filtra tudo; aqui o app só refaz o que está na tela e fecha o que ficou aberto de quem foi bloqueado.
  function depoisDeMudarBloqueio(idAlvo) {
    try {
      ['mod-menu', 'mod-den', 'overlay-perfil-rk', 'pk-post-ov'].forEach(fecharFolha);   // o perfil e o post de quem foi bloqueado não ficam abertos
      if (typeof _chatAberta !== 'undefined' && _chatAberta && typeof _chatDestino === 'function' && Number(_chatDestino().id) === Number(idAlvo) && typeof _chatVoltarLista === 'function') _chatVoltarLista();
      var v = window._viewAtual;
      if (v === 'feed' && typeof renderFeedCompleto === 'function') renderFeedCompleto();
      else if (v === 'home' && typeof renderHome === 'function') renderHome();
      else if (v === 'amigos' && typeof renderAmigos === 'function') renderAmigos();
      if (typeof _conexoesOn !== 'undefined' && _conexoesOn && typeof _amzCarregar === 'function') _amzCarregar().catch(function () {});
    } catch (e) {}
  }

  // Folha com as duas opções de uma pessoa (perfil e cabeçalho da conversa).
  function menuPessoa(id, nome) {
    id = Number(id);
    if (!(id > 0) || id === meuId()) return;
    var n = nomeDe(id, nome);
    abrirFolha('mod-menu', h(primeiro(n)),
      '<button type="button" data-id="' + id + '" data-nome="' + h(n) + '" onclick="Moderacao._viaMenu(this,\'denunciar\')" style="' + BTN_SEC + 'color:var(--texto,#fff);text-align:left;">🚩 Denunciar ' + h(primeiro(n)) + '</button>' +
      '<button type="button" data-id="' + id + '" data-nome="' + h(n) + '" onclick="Moderacao._viaMenu(this,\'bloquear\')" style="' + BTN_SEC + 'color:var(--alerta,#ff8a3d);text-align:left;">⛔ Bloquear ' + h(primeiro(n)) + '</button>' +
      '<button type="button" onclick="Moderacao._fechar(\'mod-menu\')" style="' + BTN_SEC + '">Cancelar</button>');
  }
  function menuPessoaEl(el) { var d = (el && el.dataset) || {}; menuPessoa(d.modId, d.modNome); }
  function viaMenu(el, o) {
    var id = Number(el.dataset.id), n = el.dataset.nome || '';
    fecharFolha('mod-menu');
    if (o === 'bloquear') bloquear(id, n); else denunciar('perfil', id, { autorId: id, nome: n });
  }

  // ───────────────────────── Pessoas bloqueadas ─────────────────────────
  function abrirBloqueados() {
    abrirFolha('mod-lista', '⛔ Pessoas bloqueadas', '<div id="mod-lista-corpo" style="padding:18px 0;text-align:center;font-size:13px;color:var(--muted,#888);">Carregando...</div>');
    api('bloqueios').then(function (j) { pintarBloqueados((j && j.bloqueados) || []); }).catch(function () {
      var c = document.getElementById('mod-lista-corpo');
      if (c) c.textContent = 'Não consegui carregar agora. Verifique a internet e tente de novo.';
    });
  }
  function pintarBloqueados(lista) {
    var c = document.getElementById('mod-lista-corpo');
    if (!c) return;
    if (!lista.length) {
      c.innerHTML = '<div style="font-size:30px;margin-bottom:6px;">🙂</div>Você não bloqueou ninguém.<div style="font-size:12px;line-height:1.5;margin-top:8px;">Para bloquear, abra o perfil da pessoa e toque em <b>Denunciar ou bloquear</b>.</div>';
      return;
    }
    c.style.padding = '0';
    c.style.textAlign = 'left';
    c.style.color = 'var(--texto,#fff)';   // o "Carregando..." era cinza de apoio; os nomes têm a cor do texto
    c.innerHTML = '<div style="font-size:12px;color:var(--muted,#888);line-height:1.5;margin:8px 0 4px;">Vocês não se veem no Feed, nos comentários, no mural e nas mensagens. A pessoa não é avisada.</div>' +
      lista.map(function (p) {
        return '<div data-linha="' + Number(p.id) + '" style="display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid var(--borda,#2a2a2a);">' +
          '<div style="width:36px;height:36px;border-radius:50%;background:var(--borda,#2a2a2a);display:flex;align-items:center;justify-content:center;font-weight:800;flex-shrink:0;">' + h(String(p.nome || '?').charAt(0).toUpperCase()) + '</div>' +
          '<div style="flex:1;min-width:0;font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + h(p.nome) + '</div>' +
          '<button type="button" data-id="' + Number(p.id) + '" onclick="Moderacao._desbloquear(this)" style="padding:8px 14px;border-radius:20px;border:1px solid var(--borda-forte,#444);background:transparent;color:var(--texto,#fff);font-size:12.5px;font-weight:800;cursor:pointer;font-family:inherit;flex-shrink:0;">Desbloquear</button>' +
        '</div>';
      }).join('');
  }
  function desbloquear(el) {
    var id = Number(el.dataset.id);
    el.disabled = true; el.textContent = '...';
    api('bloqueios', 'DELETE', null, '&idalvo=' + id).then(function () {
      var linha = document.querySelector('#mod-lista [data-linha="' + id + '"]');
      if (linha && linha.parentNode) linha.parentNode.removeChild(linha);
      if (!document.querySelector('#mod-lista [data-linha]')) pintarBloqueados([]);
      aviso('Pessoa desbloqueada. Os conteúdos voltam a aparecer.', 4000);
      depoisDeMudarBloqueio(0);
    }).catch(function () {
      el.disabled = false; el.textContent = 'Desbloquear';
      aviso('Não consegui desbloquear agora. Tente de novo em instantes.', 4000);
    });
  }

  // ───────────────────────── Regras da Comunidade ─────────────────────────
  // Quem ainda não aceitou a versão atual das regras não publica, comenta nem manda mensagem a outro aluno (exigência das lojas: termos aceitos antes de
  // conteúdo de usuário). O servidor guarda o aceite por versão; mudar o texto das regras = subir INTUS_MOD_VERSAO_REGRAS em _moderacao.php.
  function carregar() {
    if (!meuId()) return Promise.resolve(estado);
    if (estado.carregando) return estado.carregando;
    estado.carregando = api('regras_comunidade').then(function (j) {
      // Resposta sem o campo "aceito" (servidor fora, página de erro com status 200, mock): não decide nada. Nunca apaga um aceite guardado por causa de uma resposta estranha.
      if (!j || typeof j.aceito !== 'boolean') return estado;
      estado.aceito = j.aceito;
      estado.versao = (j && j.versao) || '';
      if (j && j.motivos && typeof j.motivos === 'object') estado.motivos = j.motivos;
      if (j && j.contato) estado.contato = j.contato;
      if (j && j.prazo_horas) estado.prazo = Number(j.prazo_horas);
      lsGravar(estado.aceito ? estado.versao : '');
      return estado;
    }).catch(function () { return estado; }).then(function (s) { estado.carregando = null; return s; });
    return estado.carregando;
  }
  // true = pode seguir; false = abriu as regras (mesmo sentido do _soTesteBarra do aluno.html).
  function regrasBarra() {
    if (!meuId()) return true;
    var ok = estado.aceito === true || (estado.aceito === null && lsAceite() !== '');
    if (ok) return true;
    abrirRegras(true);
    return false;
  }
  function verRegras() { abrirRegras(estado.aceito !== true && lsAceite() === ''); }
  function abrirRegras(comAceite) {
    if (estado.aceito === null && !estado.carregando) carregar();
    var contato = h(estado.contato);
    var item = function (t) { return '<li style="margin:5px 0;">' + t + '</li>'; };
    var corpo =
      '<div style="font-size:13.5px;line-height:1.6;margin-top:8px;">' +
        '<p style="margin:6px 0 8px;">A Intus é um lugar para treinar e se apoiar. Para manter a comunidade segura, <b>não é permitido</b> publicar, comentar ou mandar mensagem com:</p>' +
        '<ul style="margin:0 0 10px;padding-left:20px;">' +
          item('ofensa, assédio, bullying, ameaça ou discriminação (cor, origem, religião, gênero, orientação sexual, aparência ou deficiência);') +
          item('nudez, conteúdo sexual ou imagem íntima;') +
          item('violência, automutilação ou incentivo a transtorno alimentar;') +
          item('venda, compra ou incentivo ao uso de anabolizante, droga ou remédio sem prescrição;') +
          item('spam, golpe, propaganda não autorizada ou perfil falso;') +
          item('foto ou texto de outra pessoa sem a autorização dela.') +
        '</ul>' +
        '<p style="margin:8px 0;"><b>Tolerância zero.</b> O que descumpre estas regras é removido, e quem insiste pode ser suspenso ou excluído da comunidade.</p>' +
        '<p style="margin:8px 0;"><b>Denunciar e bloquear.</b> Toque em <b>⋯</b> ou em <b>Denunciar</b> em qualquer publicação, comentário, mensagem ou perfil. Para bloquear alguém, abra o perfil. A equipe analisa as denúncias em até ' + Number(estado.prazo || 24) + ' horas.</p>' +
        '<p style="margin:8px 0;font-size:12.5px;color:var(--muted,#888);">Dúvidas ou recursos: <a href="mailto:' + contato + '" style="color:var(--link-ex,#7FFF00);">' + contato + '</a></p>' +
      '</div>' +
      (comAceite
        ? '<label style="display:flex;align-items:flex-start;gap:10px;margin-top:10px;padding:12px;border:1px solid var(--borda,#2a2a2a);border-radius:12px;cursor:pointer;font-size:13px;line-height:1.5;">' +
            '<input type="checkbox" id="mod-aceite" style="width:20px;height:20px;flex-shrink:0;margin-top:1px;accent-color:#7FFF00;"/><span>Li e concordo com as <b>Regras da Comunidade</b>.</span></label>' +
          '<div id="mod-aceite-erro" style="font-size:12.5px;color:var(--alerta,#ff8a3d);min-height:16px;margin-top:6px;" role="alert"></div>' +
          '<button type="button" id="mod-aceite-btn" onclick="Moderacao._aceitar()" style="' + BTN_PRIM + '">Aceitar e continuar</button>' +
          '<button type="button" onclick="Moderacao._fechar(\'mod-regras\')" style="' + BTN_SEC + '">Agora não</button>'
        : '<button type="button" onclick="Moderacao._fechar(\'mod-regras\')" style="' + BTN_SEC + '">Fechar</button>');
    abrirFolha('mod-regras', '📜 Regras da Comunidade', corpo, { z: Z + 10 });
  }
  function aceitar() {
    var chk = document.getElementById('mod-aceite'), erro = document.getElementById('mod-aceite-erro'), btn = document.getElementById('mod-aceite-btn');
    if (!chk || !chk.checked) { if (erro) erro.textContent = 'Marque a caixa para continuar.'; return; }
    if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }
    api('regras_comunidade', 'POST').then(function (j) {
      estado.aceito = true;
      estado.versao = (j && j.versao) || estado.versao;
      lsGravar(estado.versao || '1');
      fecharFolha('mod-regras');
      aviso('Obrigado! Agora é só repetir o que você ia fazer.', 4500);
    }).catch(function (e) {
      if (btn) { btn.disabled = false; btn.textContent = 'Aceitar e continuar'; }
      if (erro) erro.textContent = (e && e.status) ? 'Não consegui salvar agora. Tente de novo em instantes.' : 'Sem conexão. Verifique a internet e tente de novo.';
    });
  }

  // ───────────────────────── pedaços de HTML para as telas ─────────────────────────
  // Nome e ids vão em data-*, nunca dentro do onclick: nome de terceiro é texto de terceiro.
  function dados(tipo, id, autorId, nome) {
    return ' data-mod-tipo="' + h(tipo) + '" data-mod-id="' + Number(id) + '" data-mod-autor="' + Number(autorId || 0) + '" data-mod-nome="' + h(nome || '') + '"';
  }
  function linkHtml(tipo, id, autorId, nome, cor) {
    return '<span role="button" tabindex="0"' + dados(tipo, id, autorId, nome) + ' onclick="event.stopPropagation();Moderacao.denunciarEl(this)" style="font-size:10.5px;color:' + (cor || 'var(--muted,#888)') + ';font-weight:700;cursor:pointer;">Denunciar</span>';
  }
  function iconeHtml(tipo, id, autorId, nome, cor, estiloExtra) {
    return '<span role="button" tabindex="0" title="Denunciar" aria-label="Denunciar"' + dados(tipo, id, autorId, nome) + ' onclick="event.stopPropagation();Moderacao.denunciarEl(this)" style="flex-shrink:0;cursor:pointer;font-size:13px;padding:4px;opacity:.75;color:' + (cor || 'inherit') + ';' + (estiloExtra || '') + '">🚩</span>';
  }
  function linkPerfilHtml(id, nome) {
    return '<div style="margin-top:10px;"><span role="button" tabindex="0" data-mod-id="' + Number(id) + '" data-mod-nome="' + h(nome || '') + '" onclick="Moderacao.menuPessoaEl(this)" style="font-size:12px;font-weight:700;color:var(--muted,#888);cursor:pointer;text-decoration:underline;">Denunciar ou bloquear</span></div>';
  }
  function botaoMaisHtml(id, nome) {
    return '<span role="button" tabindex="0" aria-label="Mais opções" data-mod-id="' + Number(id) + '" data-mod-nome="' + h(nome || '') + '" onclick="event.stopPropagation();Moderacao.menuPessoaEl(this)" style="flex-shrink:0;cursor:pointer;font-size:22px;line-height:1;padding:4px 8px;font-weight:900;color:var(--muted,#888);">⋯</span>';
  }

  window.Moderacao = {
    denunciar: denunciar, denunciarEl: denunciarEl, bloquear: bloquear, menuPessoa: menuPessoa, menuPessoaEl: menuPessoaEl,
    abrirBloqueados: abrirBloqueados, regrasBarra: regrasBarra, verRegras: verRegras, carregar: carregar,
    linkHtml: linkHtml, iconeHtml: iconeHtml, linkPerfilHtml: linkPerfilHtml, botaoMaisHtml: botaoMaisHtml,
    // usados só pelos onclick das próprias folhas
    _fechar: fecharFolha, _enviarDenuncia: enviarDenuncia, _confirmarBloqueio: confirmarBloqueio, _viaMenu: viaMenu, _desbloquear: desbloquear, _aceitar: aceitar,
    // para teste e depuração
    _estado: estado
  };

  // Teclado: Enter e Espaço nos "botões" feitos com span.
  document.addEventListener('keydown', function (e) {
    var t = e.target;
    if ((e.key === 'Enter' || e.key === ' ') && t && t.getAttribute && t.getAttribute('role') === 'button' && (t.dataset.modTipo || t.dataset.modId) && t.tagName === 'SPAN') { e.preventDefault(); t.click(); }
  });

  // Lê o estado das regras logo depois de o app abrir (sem competir com o carregamento inicial).
  if (meuId()) setTimeout(function () { carregar(); }, 1500);
})();
