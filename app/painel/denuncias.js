/* Fila de denúncias da comunidade, no painel (feed.html). Só o administrador vê e decide.

   Exigência das lojas (Apple 1.2 e Google Play): o app tem de permitir denunciar conteúdo, e a equipe tem de agir em prazo. Os Termos de Uso
   prometem resposta em até 24 horas; por isso o que passou do prazo aparece primeiro e em destaque, o painel inicial avisa e o sino da equipe toca
   a cada denúncia nova (o servidor também manda e-mail).

   A REGRA mora no servidor (app/api/_moderacao.php e catalogo.php?action=moderacao). Aqui ficam só a tela e as chamadas.
   Depende do que o feed.html já tem: _isAdmin, api(), apiJson(), esc(), quando(), showToast(), openModal(), closeModal() e render().

   API pública (window.Denuncias): painelHtml(), iniciar(), aba(nome), decidir(id), confirmar(id), arquivar(id), reativar(idatleta) */
(function () {
  'use strict';
  if (window.Denuncias) return;

  var ROTULO_TIPO = { feed_post: 'Publicação', comentario: 'Comentário', mural: 'Comentário do mural', resultado: 'Resultado', dm: 'Mensagem direta', perfil: 'Perfil' };
  var ROTULO_ACAO = { remover: 'conteúdo removido', advertir: 'aluno advertido', suspender: 'aluno suspenso', nenhuma: 'arquivada, sem violação' };
  var estado = { aba: 'abertas', abertas: [], resolvidas: [], suspensos: [], resumo: { abertas: 0, atrasadas: 0, suspensos: 0, mais_antiga_horas: 0, prazo_horas: 24 }, carregou: false, falhou: false, ocupado: false, rolou: false };

  function admin() { try { return typeof _isAdmin !== 'undefined' && !!_isAdmin; } catch (e) { return false; } }
  function h(s) { return typeof esc === 'function' ? esc(s) : String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function aviso(txt, tipo) { try { showToast(txt, tipo || 'success'); } catch (e) {} }
  function plural(n, um, varios) { return n + ' ' + (n === 1 ? um : varios); }

  // Estilo próprio da seção (só o que o painel ainda não tem). As cores de alerta têm valor nos DOIS temas: cor sem par no tema claro é texto ilegível.
  (function estilo() {
    if (document.getElementById('dn-estilo')) return;
    var st = document.createElement('style');
    st.id = 'dn-estilo';
    st.textContent =
      '.dn-box { background: var(--bg2); border: 1px solid var(--border); border-radius: 14px; padding: 14px; margin-bottom: 18px; --dn-vermelho: #ff6b6b; --dn-vermelho-fundo: rgba(255,107,107,.12); }' +
      'body.theme-light .dn-box { --dn-vermelho: #b91c1c; --dn-vermelho-fundo: rgba(185,28,28,.08); }' +
      '.dn-cab { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 4px; }' +
      '.dn-cab h3 { margin: 0; font-size: 14px; font-weight: 800; color: var(--text); }' +
      '.dn-sub { font-size: 12px; color: var(--text-muted); line-height: 1.5; margin-bottom: 12px; }' +
      '.dn-abas { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }' +
      '.dn-abas button { padding: 7px 12px; border-radius: 99px; border: 1px solid var(--border); background: var(--bg2); color: var(--text-muted); font-size: 12px; font-weight: 800; cursor: pointer; font-family: inherit; }' +
      '.dn-abas button[aria-pressed="true"] { border-color: var(--green); color: var(--text); }' +
      '.dn-item { border: 1px solid var(--border); border-left: 4px solid var(--border); border-radius: 10px; padding: 11px 12px; margin-bottom: 8px; background: var(--bg2); }' +
      '.dn-item.atrasada { border-left-color: var(--dn-vermelho); }' +
      '.dn-topo { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; color: var(--text-muted); }' +
      '.dn-motivo { font-weight: 800; color: var(--text); }' +
      '.dn-pessoas { font-size: 12.5px; color: var(--text); margin-top: 6px; line-height: 1.5; }' +
      '.dn-pessoas a { color: var(--text); font-weight: 700; text-decoration: underline; }' +
      '.dn-trecho { margin-top: 8px; padding: 8px 10px; border-radius: 8px; background: var(--bg3); border: 1px solid var(--border); font-size: 12.5px; line-height: 1.5; color: var(--text); white-space: pre-wrap; word-wrap: break-word; }' +
      '.dn-midia { display: inline-block; margin-top: 8px; }' +
      '.dn-midia img { max-width: 160px; max-height: 120px; border-radius: 8px; border: 1px solid var(--border); display: block; }' +
      '.dn-det { margin-top: 6px; font-size: 12px; color: var(--text-muted); line-height: 1.5; }' +
      '.dn-acoes { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }' +
      '.dn-vazio { text-align: center; padding: 22px 8px; color: var(--text-muted); font-size: 13px; line-height: 1.6; }' +
      '.dn-aviso-atraso { color: var(--dn-vermelho); font-weight: 800; }' +
      '.dn-opcao { display: flex; align-items: flex-start; gap: 10px; padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; color: var(--text); cursor: pointer; }' +
      '.dn-opcao input[type=checkbox] { width: 18px; height: 18px; margin-top: 2px; flex-shrink: 0; accent-color: var(--green); }' +
      '.dn-opcao small { display: block; color: var(--text-muted); font-size: 12px; line-height: 1.4; margin-top: 2px; }' +
      '.dn-opcao[aria-disabled="true"] { opacity: .5; cursor: not-allowed; }';
    document.head.appendChild(st);
  })();

  // ───────────────────────── dados ─────────────────────────
  function ler(caminho) {
    return api(caminho + '&_=' + Date.now()).then(function (r) {
      if (!r.ok) throw new Error('http ' + r.status);
      return r.json();
    });
  }
  function carregarAbertas() {
    return ler('/catalogo.php?action=moderacao&status=aberta&limite=200').then(function (j) {
      estado.abertas = j.denuncias || [];
      if (j.resumo) estado.resumo = j.resumo;
    });
  }
  function carregarAba(aba) {
    if (aba === 'resolvidas') return ler('/catalogo.php?action=moderacao&status=resolvida&limite=60').then(function (j) { estado.resolvidas = j.denuncias || []; });
    if (aba === 'suspensos') return ler('/catalogo.php?action=moderacao&suspensos=1').then(function (j) { estado.suspensos = j.suspensos || []; });
    return carregarAbertas();
  }
  function recarregar() {
    var aba = estado.aba;
    return Promise.all([carregarAbertas(), aba === 'abertas' ? null : carregarAba(aba)]).then(function () { estado.falhou = false; }).catch(function () { estado.falhou = true; }).then(function () {
      estado.carregou = true;
      repintar();
      if (!estado.rolou && /#denuncias/.test(location.hash)) { estado.rolou = true; var el = document.getElementById('dn-box'); if (el && el.scrollIntoView) el.scrollIntoView({ block: 'start' }); }
    });
  }
  function repintar() {
    var el = document.getElementById('dn-box');
    if (el) el.outerHTML = painelHtml();
    else if (typeof render === 'function') render();
  }

  // ───────────────────────── tela ─────────────────────────
  function dataCurta(s) {
    var m = String(s || '').match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    return m ? m[3] + '/' + m[2] + ' ' + m[4] + ':' + m[5] : '';
  }
  // Há quanto tempo a denúncia espera, pela conta do SERVIDOR (a mesma da marca "atrasada" e do cartão do painel inicial), não pelo relógio do navegador.
  function esperaTxt(horas) {
    horas = Number(horas) || 0;
    if (horas < 1) return 'há menos de 1 h';
    return horas < 48 ? 'há ' + horas + ' h' : 'há ' + Math.floor(horas / 24) + ' dias';
  }
  function urlSegura(u) { u = String(u || ''); return /^https:\/\//.test(u) || /^\/[^\/]/.test(u) ? u : ''; }

  function itemHtml(d, aberta) {
    var tipo = ROTULO_TIPO[d.alvo_tipo] || d.alvo_tipo;
    var autor = d.autor_tipo === 'aluno'
      ? '<a href="alunos.html?busca=' + encodeURIComponent(d.autor_nome || '') + '" title="Abrir o cadastro">' + h(d.autor_nome) + '</a>'
      : '<b>' + h(d.autor_nome) + '</b>';
    var suspenso = d.autor_suspenso ? ' <span class="badge badge-yellow">suspenso' + (d.autor_suspenso.ate ? ' até ' + h(dataCurta(d.autor_suspenso.ate).slice(0, 5)) : '') + '</span>' : '';
    var img = urlSegura(d.midia);
    var corpo =
      '<div class="dn-topo"><span class="dn-motivo">' + h(d.motivo_rotulo) + '</span><span>· ' + h(tipo) + ' · ' + (aberta ? esperaTxt(d.horas) : h(dataCurta(d.criado_em))) + '</span>' +
        (aberta && d.atrasada ? '<span class="badge badge-red">atrasada · ' + plural(d.horas, 'hora', 'horas') + '</span>' : '') +
        (d.denuncias_no_alvo > 1 ? '<span class="badge badge-yellow">' + plural(d.denuncias_no_alvo, 'denúncia', 'denúncias') + ' neste conteúdo</span>' : '') +
      '</div>' +
      '<div class="dn-pessoas">Escrito por ' + autor + suspenso + ' · denunciado por <b>' + h(d.denunciante_nome) + '</b></div>' +
      (d.trecho ? '<div class="dn-trecho">' + h(d.trecho) + '</div>' : '') +
      (img ? '<a class="dn-midia" href="' + h(img) + '" target="_blank" rel="noopener"><img src="' + h(img) + '" alt="Imagem denunciada" loading="lazy" onerror="this.parentNode.style.display=\'none\'"/></a>' : '') +
      (d.detalhe ? '<div class="dn-det"><b>O que a pessoa escreveu:</b> ' + h(d.detalhe) + '</div>' : '');
    if (aberta) {
      return '<div class="dn-item' + (d.atrasada ? ' atrasada' : '') + '" data-dn="' + d.id + '">' + corpo +
        (d.conteudo_existe ? '' : '<div class="dn-det">Este conteúdo já foi apagado (por quem escreveu ou por outra decisão). Dá para arquivar.</div>') +
        '<div class="dn-acoes"><button type="button" class="btn btn-primary" onclick="Denuncias.decidir(' + d.id + ')">Decidir</button>' +
        '<button type="button" class="btn btn-secondary" onclick="Denuncias.arquivar(' + d.id + ')" title="Não viola as regras: nada é removido">Não viola, arquivar</button></div></div>';
    }
    var acoes = String(d.acao || 'nenhuma').split(',').map(function (a) { return ROTULO_ACAO[a] || a; }).join(', ');
    return '<div class="dn-item" data-dn="' + d.id + '">' + corpo +
      '<div class="dn-det"><b>Decisão:</b> ' + h(acoes) + (d.resolvido_em ? ' · ' + h(dataCurta(d.resolvido_em)) : '') + (d.nota_equipe ? '<br><b>Nota da equipe:</b> ' + h(d.nota_equipe) : '') + '</div></div>';
  }
  function suspensoHtml(s) {
    return '<div class="dn-item" data-susp="' + Number(s.idatleta) + '"><div class="dn-topo"><span class="dn-motivo">' + h(s.nome) + '</span>' +
      '<span>· ' + (s.ate ? 'até ' + h(dataCurta(s.ate)) : 'sem prazo') + '</span></div>' +
      (s.motivo ? '<div class="dn-det">' + h(s.motivo) + '</div>' : '') +
      '<div class="dn-acoes"><button type="button" class="btn btn-secondary" onclick="Denuncias.reativar(' + Number(s.idatleta) + ')">Reativar agora</button></div></div>';
  }

  function painelHtml() {
    if (!admin()) return '';
    var r = estado.resumo || {};
    var nAb = Number(r.abertas || 0), nAt = Number(r.atrasadas || 0), nSu = Number(r.suspensos || 0);
    var selo = !estado.carregou ? '' : (nAb ? '<span class="badge ' + (nAt ? 'badge-red' : 'badge-yellow') + '">' + plural(nAb, 'aberta', 'abertas') + (nAt ? ' · ' + plural(nAt, 'atrasada', 'atrasadas') : '') + '</span>' : '<span class="badge badge-green">em dia</span>');
    var abas = [['abertas', 'Abertas' + (nAb ? ' (' + nAb + ')' : '')], ['resolvidas', 'Resolvidas'], ['suspensos', 'Suspensos' + (nSu ? ' (' + nSu + ')' : '')]];
    var lista;
    if (!estado.carregou) lista = '<div class="dn-vazio">Carregando...</div>';
    else if (estado.falhou) lista = '<div class="dn-vazio">Não consegui carregar as denúncias agora. <a href="#" onclick="Denuncias.iniciar();return false;" style="color:var(--text);font-weight:700;text-decoration:underline;">Tentar de novo</a></div>';
    else if (estado.aba === 'abertas') lista = estado.abertas.length ? estado.abertas.map(function (d) { return itemHtml(d, true); }).join('') : '<div class="dn-vazio">Nenhuma denúncia esperando resposta. Tudo em dia ✓</div>';
    else if (estado.aba === 'resolvidas') lista = estado.resolvidas.length ? estado.resolvidas.map(function (d) { return itemHtml(d, false); }).join('') : '<div class="dn-vazio">Nenhuma denúncia resolvida ainda.</div>';
    else lista = estado.suspensos.length ? estado.suspensos.map(suspensoHtml).join('') : '<div class="dn-vazio">Ninguém está suspenso no momento.</div>';
    return '<div class="dn-box" id="dn-box"><div class="dn-cab"><h3>🚩 Denúncias</h3>' + selo + '</div>' +
      '<div class="dn-sub">O que os alunos denunciam no app cai aqui. Os Termos de Uso prometem resposta em até ' + Number(r.prazo_horas || 24) + ' horas. Quem foi atingido e quem denunciou recebem um aviso no app, em nome da Equipe Intus.</div>' +
      '<div class="dn-abas">' + abas.map(function (a) { return '<button type="button" aria-pressed="' + (estado.aba === a[0]) + '" onclick="Denuncias.aba(\'' + a[0] + '\')">' + h(a[1]) + '</button>'; }).join('') + '</div>' +
      lista + '</div>';
  }

  function aba(nome) {
    if (['abertas', 'resolvidas', 'suspensos'].indexOf(nome) < 0) return;
    estado.aba = nome;
    repintar();
    carregarAba(nome).then(repintar).catch(function () { estado.falhou = true; repintar(); });
  }

  // ───────────────────────── decidir ─────────────────────────
  function achar(id) { for (var i = 0; i < estado.abertas.length; i++) if (estado.abertas[i].id === id) return estado.abertas[i]; return null; }

  function decidir(id) {
    var d = achar(id);
    if (!d) return;
    var podeSuspender = d.autor_tipo === 'aluno';
    var corpo =
      '<div class="modal-header"><div class="modal-title">Decidir sobre a denúncia</div><button class="modal-close" onclick="closeModal()">×</button></div>' +
      '<div class="modal-body">' +
        '<div class="dn-pessoas" style="margin-top:0;"><b>' + h(d.motivo_rotulo) + '</b> · ' + h(ROTULO_TIPO[d.alvo_tipo] || d.alvo_tipo) + ' de <b>' + h(d.autor_nome) + '</b></div>' +
        (d.trecho ? '<div class="dn-trecho">' + h(d.trecho) + '</div>' : '') +
        '<label class="dn-opcao"' + (d.conteudo_existe ? '' : ' aria-disabled="true"') + '><input type="checkbox" id="dn-rem"' + (d.conteudo_existe ? ' checked' : ' disabled') + '/><span>Remover o conteúdo<small>' + (d.conteudo_existe ? 'Sai do app na hora. A denúncia de outras pessoas sobre o mesmo conteúdo é resolvida junto.' : 'Já foi apagado.') + '</small></span></label>' +
        '<label class="dn-opcao"><input type="checkbox" id="dn-adv"/><span>Advertir ' + h(d.autor_nome) + '<small>Aviso no app: "Um conteúdo seu descumpriu as Regras da Comunidade". Não bloqueia nada.</small></span></label>' +
        '<label class="dn-opcao"' + (podeSuspender ? '' : ' aria-disabled="true"') + '><input type="checkbox" id="dn-sus"' + (podeSuspender ? '' : ' disabled') + ' onchange="document.getElementById(\'dn-dias\').disabled=!this.checked"/><span>Suspender de publicar, comentar e escrever<small>' + (podeSuspender ? 'Treino, cardio, ranking e nutrição seguem normais. A pessoa vê o motivo e a data quando tenta publicar.' : 'Só dá para suspender aluno.') + '</small></span></label>' +
        '<div class="form-group" style="margin-top:8px;"><label for="dn-dias" style="font-size:12px;color:var(--text-muted);">Por quanto tempo</label>' +
          '<select id="dn-dias" class="input" disabled><option value="1">1 dia</option><option value="3">3 dias</option><option value="7" selected>7 dias</option><option value="30">30 dias</option><option value="90">90 dias</option><option value="0">Sem prazo (até você reativar)</option></select></div>' +
        '<div class="form-group" style="margin-top:10px;"><label for="dn-nota" style="font-size:12px;color:var(--text-muted);">Nota interna (a pessoa não vê)</label><textarea id="dn-nota" class="input" rows="2" maxlength="500" placeholder="Opcional"></textarea></div>' +
      '</div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="closeModal()">Cancelar</button><button type="button" class="btn btn-primary" id="dn-ok" onclick="Denuncias.confirmar(' + id + ')">Confirmar decisão</button></div>';
    openModal(corpo);
  }

  function enviar(corpo, textoOk) {
    if (estado.ocupado) return;
    estado.ocupado = true;
    var btn = document.getElementById('dn-ok');
    if (btn) { btn.disabled = true; btn.textContent = 'Salvando...'; }
    return apiJson('/catalogo.php?action=moderacao', corpo).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) throw new Error((j && j.error) || 'falhou');
        try { closeModal(); } catch (e) {}
        aviso(j && j.ja ? 'Esta denúncia já tinha sido decidida.' : textoOk, 'success');
        return recarregar();
      });
    }).catch(function (e) {
      if (btn) { btn.disabled = false; btn.textContent = 'Confirmar decisão'; }
      aviso('Não consegui concluir: ' + ((e && e.message) || 'erro'), 'error');
    }).then(function () { estado.ocupado = false; });
  }

  function confirmar(id) {
    var d = achar(id);
    if (!d) return;
    var acoes = [];
    if (document.getElementById('dn-rem') && document.getElementById('dn-rem').checked) acoes.push('remover');
    if (document.getElementById('dn-adv') && document.getElementById('dn-adv').checked) acoes.push('advertir');
    if (document.getElementById('dn-sus') && document.getElementById('dn-sus').checked) acoes.push('suspender');
    if (!acoes.length && !confirm('Nenhuma ação marcada: a denúncia será arquivada como "não viola as regras" e nada muda para ninguém. Continuar?')) return;
    var dias = Number((document.getElementById('dn-dias') || {}).value || 7);
    var nota = ((document.getElementById('dn-nota') || {}).value || '').trim();
    enviar({ id: id, acoes: acoes, dias: dias, nota: nota }, acoes.length ? 'Decisão registrada. Os avisos já foram para o app.' : 'Denúncia arquivada.');
  }
  function arquivar(id) {
    if (!achar(id)) return;
    if (!confirm('Arquivar sem tomar nenhuma ação? Quem denunciou recebe o aviso de que a denúncia foi analisada.')) return;
    enviar({ id: id, acoes: [], nota: '' }, 'Denúncia arquivada.');
  }
  function reativar(idatleta) {
    if (!confirm('Reativar esta pessoa agora? Ela volta a poder publicar, comentar e escrever.')) return;
    apiJson('/catalogo.php?action=moderacao', { reativar: Number(idatleta) }).then(function (r) {
      if (!r.ok) throw new Error('falhou');
      aviso('Pessoa reativada.', 'success');
      return recarregar();
    }).catch(function () { aviso('Não consegui reativar agora.', 'error'); });
  }

  function iniciar() {
    if (!admin()) return Promise.resolve();
    return recarregar();
  }

  window.Denuncias = { painelHtml: painelHtml, iniciar: iniciar, aba: aba, decidir: decidir, confirmar: confirmar, arquivar: arquivar, reativar: reativar, _estado: estado };
})();
