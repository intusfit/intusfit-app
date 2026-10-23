/* Bloco de atendimento de uma pessoa (lead ou aluno): etapa do funil, temperatura (ver e fixar), mensagens prontas já com o nome dela
 * (abrir no WhatsApp, copiar, marcar como enviada), contatos já feitos e anotação do resultado. Só administrador (a API recusa os outros).
 * Usado no cadastro do cliente (aba Atendimento, em Clientes) e na página de perfil (perfil.html).
 *
 *   PerfilAtendimento.montar(elemento, 'a12' | 'l3', { aoMudar })   // bloco completo
 *   PerfilAtendimento.faixa(elemento, 'a12', function () {...})     // faixa curta: etapa + temperatura + botão "Mensagem"
 *
 * Nenhuma regra mora aqui: a temperatura, a mensagem sugerida e o histórico vêm de atendimento.php?action=perfil (a mesma lógica da
 * Central de atendimento e de Pessoas Captadas). Aqui só se desenha e se manda para os mesmos endpoints.
 */
(function (global) {
  var CSS = ''
    + '.pa{display:flex;flex-direction:column;gap:14px;font-size:13px;color:var(--text)}'
    + '.pa-sec{background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:14px}'
    + '.pa-sec h4{margin:0 0 8px;font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)}'
    + '.pa-linha{display:flex;flex-wrap:wrap;gap:8px;align-items:center}'
    + '.pa-pill{display:inline-block;font-size:11px;font-weight:700;padding:3px 9px;border-radius:99px;border:1px solid var(--border);color:var(--text-muted)}'
    + '.pa-pill.quente{color:#f87171;border-color:#f8717166}.pa-pill.morno{color:#fbbf24;border-color:#fbbf2466}.pa-pill.frio{color:#38bdf8;border-color:#38bdf866}.pa-pill.cliente{color:var(--green);border-color:var(--green)}'
    + '.pa-aviso{padding:10px 12px;border-radius:10px;border:1px solid #fbbf2466;background:#fbbf2414;font-size:12.5px;line-height:1.5}'
    + '.pa-aviso.erro{border-color:#f8717166;background:#f871711a}'
    + '.pa select,.pa input[type=text],.pa input[type=date],.pa textarea{background:var(--bg);color:var(--text);border:1px solid var(--border);border-radius:8px;padding:7px 10px;font:inherit;font-size:13px;max-width:100%}'
    + '.pa-prev{white-space:pre-wrap;background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:10px 12px;margin:8px 0;line-height:1.5;overflow-wrap:anywhere}'
    + '.pa-btn{font:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border-radius:8px;border:1px solid var(--border);background:transparent;color:var(--text);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px}'
    + '.pa-btn.zap{background:var(--green);border-color:var(--green);color:#0c1a00}.pa-btn:disabled{opacity:.5;cursor:default}'
    + '.pa-mut{font-size:11.5px;color:var(--text-muted);line-height:1.45}'
    + '.pa-hist{display:flex;flex-direction:column;gap:6px;margin-top:8px}.pa-hist div{padding:7px 10px;border-radius:8px;background:var(--bg);border:1px solid var(--border);font-size:12px}'
    + '.pa-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin-top:10px}.pa-form .larga{grid-column:1/-1}'
    + '.pa-form label{display:flex;flex-direction:column;gap:3px;font-size:11px;font-weight:700;color:var(--text-muted)}';

  var ROT_ETAPA = { contato: 'Deixou só o contato', so_conta: 'Criou a conta, não abriu o app', abriu_app: 'Abriu o app', treinando: 'Treinando no teste', teste_expirado: 'Teste acabou, sem comprar', negociando: 'Matrícula a pagar', cliente: 'Cliente' };
  var ROT_TEMP = { quente: 'Quente', morno: 'Morno', frio: 'Frio', cliente: 'Cliente' };
  var ROT_STATUS = { chamado: 'Chamado', respondeu: 'Respondeu', retornar: 'Retornar depois', sem_resposta: 'Sem resposta', sem_interesse: 'Sem interesse', fechou: 'Fechou', numero_errado: 'Número errado', nota: 'Anotação' };

  function css() { if (document.getElementById('pa-css')) return; var s = document.createElement('style'); s.id = 'pa-css'; s.textContent = CSS; document.head.appendChild(s); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function token() { try { if (typeof Auth !== 'undefined' && Auth.getToken) return Auth.getToken() || ''; } catch (e) {} try { return localStorage.getItem('mx-token') || ''; } catch (e) { return ''; } }
  function base() { return (typeof API_BASE !== 'undefined' && API_BASE) ? API_BASE : '/app/api'; }
  function chamar(arq, qs, corpo) {
    var o = { headers: { 'Authorization': 'Bearer ' + token() }, cache: 'no-store' };
    if (corpo) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(corpo); }
    return fetch(base() + '/' + arq + '?' + qs, o).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) { if (!r.ok || j.ok === false) throw new Error(j.erro || j.error || ('Erro ' + r.status)); return j; });
    });
  }
  function aviso(m) { var el = document.createElement('div'); el.textContent = m; el.style.cssText = 'position:fixed;left:50%;bottom:30px;transform:translateX(-50%);background:var(--bg2);color:var(--text);border:1px solid var(--border);padding:10px 16px;border-radius:10px;z-index:999999;font-size:13px;max-width:90vw'; document.body.appendChild(el); setTimeout(function () { el.remove(); }, 2800); }
  function copiar(t) { var ok = function () { aviso('Mensagem copiada, pronta para colar'); }; if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(ok, ok); else { var a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); } catch (e) {} a.remove(); ok(); } }
  function fmt(s) { var m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(String(s || '')); return m ? m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] + ':' + m[5] : '') : ''; }
  function maisDias(n) { var d = new Date(Date.now() + n * 86400000); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); }
  function tempBadge(t) { return '<span class="pa-pill ' + esc(t.nivel) + '" title="' + esc((t.motivos || []).join(', ') || 'sem sinais de interesse') + '">' + esc(ROT_TEMP[t.nivel] || t.nivel) + (t.manual ? ' · fixada por ' + esc(t.por || 'alguém') : '') + '</span>'; }
  function tempSelect(id, t) {
    if (t.nivel === 'cliente') return '';
    return '<select id="' + id + '" aria-label="Temperatura"><option value="auto"' + (t.manual ? '' : ' selected') + '>Automática (' + esc((ROT_TEMP[t.auto] || t.auto).toLowerCase()) + ')</option>'
      + ['quente', 'morno', 'frio'].map(function (n) { return '<option value="' + n + '"' + (t.manual && t.nivel === n ? ' selected' : '') + '>' + ROT_TEMP[n] + '</option>'; }).join('') + '</select>';
  }
  function fixarTemp(chave, v) { return chamar('atendimento.php', 'action=manual_acao', { chave: chave, acao: 'temperatura', valor: v }); }

  function opcoesMsg(bib, p, sel) {
    var h = '', funil = null;
    bib.forEach(function (b) {
      if (p.opcoes[b.passo] === undefined) return;
      if (b.funil !== funil) { if (funil !== null) h += '</optgroup>'; h += '<optgroup label="' + esc(b.funil_nome + (b.funil_status === 'aprovado' ? '' : ' (rascunho)')) + '">'; funil = b.funil; }
      h += '<option value="' + esc(b.passo) + '"' + (b.passo === sel ? ' selected' : '') + '>' + esc(b.nome + (b.passo === p.passo_sugerido ? ' (sugerida agora)' : '') + (b.aprovada ? '' : ' · rascunho')) + '</option>';
    });
    return h + (funil !== null ? '</optgroup>' : '');
  }

  function montar(el, chave, opts) {
    css(); opts = opts || {};
    var estado = { d: null, sel: null };
    el.innerHTML = '<div class="pa-mut" style="padding:14px">Carregando o atendimento...</div>';
    function carregar() {
      return chamar('atendimento.php', 'action=perfil&chave=' + encodeURIComponent(chave)).then(function (d) {
        estado.d = d; if (!estado.sel || d.pessoa.opcoes[estado.sel] === undefined) estado.sel = d.pessoa.passo_sugerido || null;
        desenhar();
      }).catch(function (e) { el.innerHTML = '<div class="pa-aviso erro" style="margin:14px">Não consegui carregar o atendimento: ' + esc(e.message) + '</div>'; });
    }
    function desenhar() {
      var d = estado.d, p = d.pessoa, w = p.wa || {}, t = p.temperatura, c = p.controle || { ultimo: null, total: 0, historico: [] }, u = c.ultimo;
      var texto = estado.sel ? p.opcoes[estado.sel] : '';
      var link = (p.whatsapp && !w.saiu) ? 'https://wa.me/' + p.whatsapp + (texto ? '?text=' + encodeURIComponent(texto) : '') : '';
      var h = '<div class="pa">';
      h += '<div class="pa-sec"><div class="pa-linha"><span class="pa-pill">' + esc(p.etapa_rotulo || ROT_ETAPA[p.etapa] || p.etapa) + '</span>' + tempBadge(t) + tempSelect('pa-temp', t)
        + '<span class="pa-pill">' + esc(p.origem_tipo === 'pago' ? 'veio de anúncio' : 'orgânico') + '</span></div>'
        + '<div class="pa-mut" style="margin-top:8px">' + esc(['origem: ' + (p.canal || 'sem medição'), p.utm && p.utm.campaign ? 'campanha ' + p.utm.campaign : '', p.indicado_por ? 'indicado por ' + p.indicado_por : '', 'cadastro ' + fmt(p.criado_em), p.tipo === 'conta' ? (p.treinos ? p.treinos + ' treino(s)' : 'nenhum treino') : 'sem conta no app'].filter(Boolean).join(' · ')) + '</div>'
        + (p.acao ? '<div class="pa-mut" style="margin-top:4px">Sugestão: ' + esc(p.acao) + '</div>' : '') + '</div>';
      if (p.recuperacao) h += '<div class="pa-aviso">♻ <b>Recuperação do teste grátis.</b> O teste terminou há ' + esc(p.recuperacao.dias) + ' dia(s)' + (p.recuperacao.estimado ? ' (data estimada: a conta não tem matrícula de teste registrada)' : '') + ' e ninguém falou com esta pessoa. A mensagem sugerida abaixo é a da sequência de recuperação.</div>';
      if (w.saiu) h += '<div class="pa-aviso erro">🚫 Esta pessoa pediu para não receber mensagens da Intus. Não chame.</div>';
      h += '<div class="pa-sec"><h4>Mandar mensagem</h4>';
      if (!p.whatsapp) h += '<div class="pa-aviso">Sem telefone válido: não dá para abrir o WhatsApp.</div>';
      else if (!estado.sel) h += '<div class="pa-mut">Nenhuma mensagem pronta para a etapa desta pessoa. Escolha uma da lista.</div>';
      h += '<select id="pa-msg" aria-label="Mensagem">' + (estado.sel ? '' : '<option value="">Escolha uma mensagem...</option>') + opcoesMsg(d.biblioteca, p, estado.sel) + '</select>'
        + '<div class="pa-prev" id="pa-prev">' + esc(texto) + '</div>'
        + '<div class="pa-linha">' + (link ? '<a class="pa-btn zap" id="pa-zap" target="_blank" rel="noopener noreferrer" href="' + esc(link) + '">💬 Abrir no WhatsApp</a>' : '')
        + '<button type="button" class="pa-btn" id="pa-copiar"' + (texto ? '' : ' disabled') + '>Copiar</button>'
        + (p.whatsapp && !w.saiu ? '<button type="button" class="pa-btn" id="pa-enviada"' + (estado.sel ? '' : ' disabled') + '>Já enviei esta mensagem</button>' : '') + '</div>'
        + '<div class="pa-mut" style="margin-top:6px">"Já enviei" registra a mensagem e o contato (aparece em Pessoas Captadas e na Fila de hoje).</div></div>';
      h += '<div class="pa-sec"><h4>Contatos já feitos' + (c.total ? ' (' + c.total + ')' : '') + '</h4>';
      if (u) h += '<div class="pa-linha"><span class="pa-pill">' + esc(ROT_STATUS[u.status] || u.status) + '</span><span class="pa-mut">' + esc(fmt(u.criado_em)) + ' · ' + esc(u.usuario || 'alguém') + (u.nota ? ' · “' + esc(u.nota) + '”' : '') + (u.status === 'retornar' && u.retorno_em ? ' · retornar em ' + esc(fmt(u.retorno_em)) : '') + '</span></div>';
      else h += '<div class="pa-mut">Ninguém falou com esta pessoa ainda.</div>';
      if ((c.historico || []).length > 1) h += '<div class="pa-hist">' + c.historico.slice(0, 6).map(function (x) { return '<div><b>' + esc(ROT_STATUS[x.status] || x.status) + '</b> · ' + esc(x.usuario || '') + ' · ' + esc(fmt(x.criado_em)) + (x.nota ? ' · “' + esc(x.nota) + '”' : '') + '</div>'; }).join('') + '</div>';
      h += '<div class="pa-form"><label>Resultado<select id="pa-st">' + ['respondeu', 'retornar', 'sem_resposta', 'sem_interesse', 'fechou', 'numero_errado', 'nota'].map(function (k) { return '<option value="' + k + '">' + ROT_STATUS[k] + '</option>'; }).join('') + '</select></label>'
        + '<label>Como falou<select id="pa-canal"><option value="whatsapp_link">WhatsApp</option><option value="ligacao">Ligação</option><option value="email">E-mail</option><option value="presencial">Pessoalmente</option><option value="outro">Outro</option></select></label>'
        + '<label id="pa-retl" style="display:none">Retornar em<input type="date" id="pa-ret" value="' + maisDias(2) + '"></label>'
        + '<label class="larga">Anotação (opcional)<input type="text" id="pa-nota" maxlength="500" placeholder="Ex.: quer saber o preço do trimestral"></label>'
        + '<div class="larga"><button type="button" class="pa-btn" id="pa-salvar">Anotar resultado</button></div></div></div>';
      h += '<div class="pa-sec"><h4>WhatsApp da Intus e mensagens da Central</h4><div class="pa-linha">'
        + (w.contato ? '<span class="pa-pill">contato no WhatsApp</span>' : '<span class="pa-pill">ainda não é contato no WhatsApp</span>')
        + (w.enviadas ? '<span class="pa-pill">recebeu ' + w.enviadas + '</span>' : '') + (w.respondeu ? '<span class="pa-pill cliente">respondeu</span>' : '') + (w.precisa_humano ? '<span class="pa-pill morno">pediu atendimento humano</span>' : '') + '</div>';
      if ((p.passos_tratados || []).length) h += '<div class="pa-hist">' + p.passos_tratados.map(function (x) { return '<div>' + esc(x.funil_nome) + ' · <b>' + esc(x.mensagem_nome) + '</b> · ' + esc({ enviada: 'enviada', pulada: 'pulada', substituida: 'trocada por outra' }[x.estado] || x.estado) + ' · ' + esc(fmt(x.em)) + (x.por ? ' · ' + esc(x.por) : '') + '</div>'; }).join('') + '</div>';
      else h += '<div class="pa-mut" style="margin-top:6px">Nenhuma mensagem da Central marcada como enviada.</div>';
      h += '</div></div>';
      el.innerHTML = h;

      var q = function (id) { return el.querySelector('#' + id); };
      if (q('pa-temp')) q('pa-temp').addEventListener('change', function () { var v = this.value; fixarTemp(chave, v).then(function () { aviso(v === 'auto' ? 'Temperatura automática' : 'Temperatura fixada: ' + ROT_TEMP[v].toLowerCase()); if (opts.aoMudar) opts.aoMudar(); return carregar(); }).catch(function (e) { aviso(e.message); }); });
      q('pa-msg').addEventListener('change', function () { estado.sel = this.value || null; desenhar(); });
      if (q('pa-copiar')) q('pa-copiar').addEventListener('click', function () { copiar(texto); });
      if (q('pa-zap')) q('pa-zap').addEventListener('click', function () {
        if (u && u.status === 'chamado' && u.canal === 'whatsapp_link' && Date.now() - new Date(String(u.criado_em).replace(' ', 'T')).getTime() < 600000) return;   // não repete a cada clique
        chamar('trafego.php', 'action=contato', { chave: chave, status: 'chamado', canal: 'whatsapp_link', telefone: p.whatsapp }).then(carregar).catch(function (e) { aviso('Abri o WhatsApp, mas não consegui registrar o contato: ' + e.message); });
      });
      if (q('pa-enviada')) q('pa-enviada').addEventListener('click', function () {
        this.disabled = true;
        chamar('atendimento.php', 'action=manual_acao', { chave: chave, telefone: p.whatsapp, acao: 'enviada', passo: estado.sel, passo_sugerido: p.passo_sugerido || '' })
          .then(function (r) { aviso(r.erro || (r.ja ? 'Esta mensagem já estava marcada como enviada' : 'Marcada como enviada')); estado.sel = null; return carregar(); }).catch(function (e) { aviso(e.message); desenhar(); });
      });
      q('pa-st').addEventListener('change', function () { q('pa-retl').style.display = this.value === 'retornar' ? '' : 'none'; });
      q('pa-salvar').addEventListener('click', function () {
        var st = q('pa-st').value, b = { chave: chave, status: st, canal: q('pa-canal').value, nota: q('pa-nota').value, telefone: p.whatsapp };
        if (st === 'retornar') b.retorno_em = q('pa-ret').value;
        this.disabled = true;
        chamar('trafego.php', 'action=contato', b).then(function () { aviso('Contato registrado'); if (opts.aoMudar) opts.aoMudar(); return carregar(); }).catch(function (e) { aviso('Não consegui salvar: ' + e.message); desenhar(); });
      });
    }
    carregar();
    return { recarregar: carregar };
  }

  // Faixa curta (cabeçalho do cadastro): etapa, temperatura que dá para mudar ali mesmo e atalho para a aba de mensagens
  function faixa(el, chave, aoAbrir) {
    css();
    function carregar() {
      chamar('atendimento.php', 'action=perfil&chave=' + encodeURIComponent(chave)).then(function (d) {
        var p = d.pessoa, t = p.temperatura;
        el.innerHTML = '<div class="pa-linha" style="padding:10px 24px;border-bottom:1px solid var(--border)"><span class="pa-pill">' + esc(p.etapa_rotulo || ROT_ETAPA[p.etapa] || p.etapa) + '</span>' + tempBadge(t) + tempSelect('pa-ft', t)
          + (p.recuperacao ? '<span class="pa-pill morno" title="Teste terminado e ninguém falou com a pessoa">♻ recuperação</span>' : '')
          + '<button type="button" class="pa-btn" id="pa-fmsg" style="margin-left:auto">💬 Mensagem e contatos</button></div>';
        var s = el.querySelector('#pa-ft'); if (s) s.addEventListener('change', function () { fixarTemp(chave, this.value).then(function () { aviso('Temperatura atualizada'); carregar(); }).catch(function (e) { aviso(e.message); }); });
        el.querySelector('#pa-fmsg').addEventListener('click', function () { if (aoAbrir) aoAbrir(); });
      }).catch(function () { el.innerHTML = ''; });
    }
    carregar();
    return { recarregar: carregar };
  }

  global.PerfilAtendimento = { montar: montar, faixa: faixa };
})(window);
