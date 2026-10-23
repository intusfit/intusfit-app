/* Provas sociais: aba do Quadro de Resultados, só da equipe (administrador e professores).
 * Guarda a história de cada resultado (quem, quanto tempo, peso antes e depois, fotos, vídeos) e se a pessoa autorizou o uso.
 * O robô de atendimento, o marketing e a inteligência do ecossistema só usam o que está autorizado aqui (a regra mora no servidor,
 * em app/api/_provas.php). Esta tela só mostra e envia.
 *
 * Provas.montar(el, { api, apiJson, esc, abasHtml, toast, admin }) desenha dentro de `el`.
 */
(function () {
  var MOMENTOS = { antes: 'Antes', durante: 'Durante', depois: 'Depois', depoimento: 'Depoimento' };
  var S = { filtros: { busca: '', objetivo: '', autorizacao: '', uso: '', ativo: '1' }, lista: [], resumo: null, meta: null, ctx: null, aberta: null, carregando: false, erro: '' };
  var ENVIANDO = false;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function num(v) { return v === null || v === undefined ? '' : String(v).replace('.', ','); }
  function opcoes(map, sel, vazio) {
    return (vazio !== undefined ? '<option value="">' + esc(vazio) + '</option>' : '') + Object.keys(map).map(function (k) { return '<option value="' + esc(k) + '"' + (k === sel ? ' selected' : '') + '>' + esc(map[k]) + '</option>'; }).join('');
  }
  function jsonErro(r) { return r.json().then(function (j) { return (j && j.erro) || ('erro ' + r.status); }).catch(function () { return 'erro ' + r.status; }); }

  function chamar(path, corpo, metodo) {
    var req = corpo === undefined ? S.ctx.api('/provas.php' + path) : S.ctx.apiJson('/provas.php' + path, corpo, metodo);
    return req.then(function (r) { return r.ok ? r.json() : jsonErro(r).then(function (m) { throw new Error(m); }); });
  }

  function carregar() {
    S.carregando = true; S.erro = '';
    var q = Object.keys(S.filtros).filter(function (k) { return S.filtros[k] !== ''; }).map(function (k) { return k + '=' + encodeURIComponent(S.filtros[k]); }).join('&');
    var pedidos = [chamar('?action=listar&' + q + '&_=' + Date.now())];
    if (!S.meta) pedidos.push(chamar('?action=resumo'));
    return Promise.all(pedidos).then(function (r) {
      S.lista = r[0].provas || []; S.resumo = r[0].resumo; if (r[1]) S.meta = r[1];
    }).catch(function (e) { S.erro = e.message || 'Não consegui carregar.'; }).then(function () { S.carregando = false; desenhar(); });
  }

  // ── lista ──
  function selo(p) {
    var a = p.autorizacao === 'divulgar' ? '<span class="pv-b ok">Autorizou divulgar</span>' : (p.autorizacao === 'interna' ? '<span class="pv-b am">Só uso interno</span>' : '<span class="pv-b ru">Sem autorização</span>');
    var u = [];
    if (p.pode_robo) u.push('🤖 robô'); if (p.pode_marketing) u.push('📣 marketing'); if (p.pode_site) u.push('🌐 site'); if (p.pode_ia) u.push('🧠 IA');
    return a + (u.length ? ' <span class="pv-b">' + u.join(' · ') + '</span>' : '') + (p.ativo ? '' : ' <span class="pv-b ru">Inativa</span>');
  }
  function cartao(p) {
    var foto = (p.midias || []).filter(function (m) { return m.tipo === 'foto'; });
    var capa = foto.length ? '<img src="' + esc(foto[foto.length - 1].url) + '" alt="" loading="lazy"/>' : '<div class="pv-semfoto">' + ((p.midias || []).length ? '🎬' : '📷') + '</div>';
    var nVid = (p.midias || []).filter(function (m) { return m.tipo !== 'foto'; }).length;
    return '<div class="pv-card" data-id="' + p.idprova + '"><div class="pv-capa">' + capa + (p.destaque ? '<span class="pv-estr">⭐</span>' : '') + '</div>' +
      '<div class="pv-corpo"><div class="pv-nome">' + esc(p.pessoa_nome) + (p.idade ? ' <span class="pv-mut">' + p.idade + ' anos</span>' : '') + '</div>' +
      '<div class="pv-mut">' + esc((S.meta && S.meta.objetivos[p.objetivo]) || '') + (p.tempo_txt ? ' · ⏱ ' + esc(p.tempo_txt) : '') + '</div>' +
      (p.peso_resumo ? '<div class="pv-peso">⚖ ' + esc(p.peso_resumo) + '</div>' : '') +
      (p.resultado_txt ? '<div class="pv-res">' + esc(p.resultado_txt) + '</div>' : '') +
      '<div class="pv-sel">' + selo(p) + '</div>' +
      '<div class="pv-mut" style="margin-top:6px">' + foto.length + ' foto(s)' + (nVid ? ' · ' + nVid + ' vídeo(s)' : '') + ' · por ' + esc(p.criado_por_nome || 'equipe') + '</div>' +
      '<div class="pv-acoes"><button class="rs-btn on" data-a="editar">Abrir e editar</button><button class="rs-btn" data-a="copiar">Copiar texto pronto</button></div></div></div>';
  }
  function resumoHtml() {
    var r = S.resumo; if (!r) return '';
    return '<div class="pv-res-linha"><span><b>' + r.total + '</b> provas ativas</span><span><b>' + r.divulgar + '</b> autorizaram divulgar</span><span><b>' + r.robo + '</b> liberadas para o robô</span><span><b>' + r.pendentes + '</b> sem autorização ainda</span><span><b>' + r.destaques + '</b> em destaque</span></div>';
  }

  function desenhar() {
    var el = S.el; if (!el) return;
    var f = S.filtros, m = S.meta || { objetivos: {}, autorizacoes: {}, usos: {} };
    var foco = document.activeElement && document.activeElement.id === 'pv-busca';
    el.innerHTML = '<div class="pv-wrap">' + S.ctx.abasHtml() +
      '<div class="rs-intro">Aqui ficam as histórias reais de quem treinou com a Intus: quem é, quanto tempo levou, peso antes e depois, fotos e vídeos. <b>Só a equipe vê esta aba.</b> O robô de atendimento, o marketing e a inteligência só usam o que a pessoa autorizou. Sem autorização, a prova fica guardada e nunca sai daqui.</div>' +
      resumoHtml() +
      '<div class="rs-filtros"><button class="rs-add" id="pv-nova">➕ Nova prova</button>' +
      '<input id="pv-busca" class="rs-busca" placeholder="Buscar por nome, resultado ou etiqueta" value="' + esc(f.busca) + '"/>' +
      '<select id="pv-f-obj" class="pv-sel-f">' + opcoes(m.objetivos, f.objetivo, 'Todos os objetivos') + '</select>' +
      '<select id="pv-f-aut" class="pv-sel-f">' + opcoes(m.autorizacoes, f.autorizacao, 'Qualquer autorização') + '</select>' +
      '<select id="pv-f-uso" class="pv-sel-f">' + opcoes(m.usos, f.uso, 'Qualquer uso') + '</select>' +
      '<select id="pv-f-ativo" class="pv-sel-f"><option value="1"' + (f.ativo === '1' ? ' selected' : '') + '>Ativas</option><option value="0"' + (f.ativo === '0' ? ' selected' : '') + '>Inativas</option><option value="todos"' + (f.ativo === 'todos' ? ' selected' : '') + '>Todas</option></select>' +
      (S.ctx.admin ? '<button class="rs-btn" id="pv-ia" title="Mostra exatamente o que a inteligência do sistema enxerga">🧠 O que a IA vê</button>' : '') + '</div>' +
      (S.erro ? '<div class="rs-vazio">' + esc(S.erro) + '</div>' : (S.lista.length ? '<div class="pv-grade">' + S.lista.map(cartao).join('') + '</div>' : '<div class="rs-vazio">' + (S.carregando ? 'Carregando...' : 'Nenhuma prova por aqui ainda. Clique em "Nova prova" para começar.') + '</div>')) + '</div>';
    if (foco) { var b = document.getElementById('pv-busca'); if (b) { b.focus(); b.setSelectionRange(b.value.length, b.value.length); } }
    ligar();
  }

  var tBusca = null;
  function ligar() {
    var el = S.el;
    var on = function (id, ev, fn) { var n = document.getElementById(id); if (n) n.addEventListener(ev, fn); };
    on('pv-nova', 'click', function () { abrirForm(null); });
    on('pv-busca', 'input', function (e) { S.filtros.busca = e.target.value; clearTimeout(tBusca); tBusca = setTimeout(carregar, 350); });
    [['pv-f-obj', 'objetivo'], ['pv-f-aut', 'autorizacao'], ['pv-f-uso', 'uso'], ['pv-f-ativo', 'ativo']].forEach(function (x) { on(x[0], 'change', function (e) { S.filtros[x[1]] = e.target.value; carregar(); }); });
    on('pv-ia', 'click', verIA);
    var g = el.querySelector('.pv-grade');
    if (g) g.addEventListener('click', function (e) {
      var b = e.target.closest('[data-a]'); if (!b) return;
      var id = parseInt(b.closest('.pv-card').getAttribute('data-id'), 10);
      if (b.getAttribute('data-a') === 'editar') abrirForm(id);
      if (b.getAttribute('data-a') === 'copiar') copiarTexto(id);
    });
  }

  function copiarTexto(id) {
    chamar('?action=obter&id=' + id).then(function (r) {
      var t = r.texto_pronto || '';
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(t).then(function () { S.ctx.toast('Texto copiado.', 'success'); }, function () { prompt('Copie o texto:', t); });
      else prompt('Copie o texto:', t);
    }).catch(function (e) { S.ctx.toast(e.message, 'error'); });
  }

  function verIA() {
    chamar('?action=ia').then(function (r) {
      fechar();
      var m = document.createElement('div'); m.id = 'pv-modal'; m.className = 'rs-modal';
      m.innerHTML = '<div class="rs-form" style="max-width:720px"><h3>O que a inteligência vê</h3><div class="dica">Só provas ativas, com a chave "IA" ligada e autorização interna ou de divulgação. Primeiro nome, sem telefone nem endereço de foto.</div>' +
        '<pre class="pv-json">' + esc(JSON.stringify(r.provas, null, 2)) + '</pre><div style="margin-top:12px"><button class="rs-btn" id="pv-x">Fechar</button></div></div>';
      document.body.appendChild(m); document.getElementById('pv-x').onclick = fechar;
    }).catch(function (e) { S.ctx.toast(e.message, 'error'); });
  }

  // ── formulário ──
  function fechar() { var m = document.getElementById('pv-modal'); if (m) m.remove(); }
  function abrirForm(id) {
    if (id) chamar('?action=obter&id=' + id).then(function (r) { S.aberta = r.prova; modal(); }).catch(function (e) { S.ctx.toast(e.message, 'error'); });
    else { S.aberta = { idprova: 0, pessoa_nome: '', sexo: '', idade: null, objetivo: 'emagrecimento', programa: 'intus', plano_usado: '', professor: '', tempo_meses: null, tempo_txt: '', peso_antes: null, peso_depois: null, gordura_antes: null, gordura_depois: null, medidas_txt: '', resultado_txt: '', depoimento_txt: '', contexto_txt: '', tags: [], autorizacao: 'pendente', autorizacao_obs: '', uso_robo: false, uso_marketing: false, uso_site: false, uso_ia: false, destaque: false, ativo: true, midias: [], criado_por: S.meta ? S.meta.eu : 0 }; modal(); }
  }
  function campo(id, rot, valor, extra) { return '<div class="pv-c"><label for="' + id + '">' + rot + '</label><input type="text" id="' + id + '" value="' + esc(valor) + '" ' + (extra || '') + '/></div>'; }
  function area(id, rot, valor, dica) { return '<label for="' + id + '">' + rot + '</label><textarea id="' + id + '" maxlength="1000">' + esc(valor) + '</textarea>' + (dica ? '<div class="dica">' + dica + '</div>' : ''); }

  function midiasHtml(p) {
    if (!p.idprova) return '<div class="rs-aviso">Salve a prova primeiro. Depois você adiciona fotos e vídeos.</div>';
    var pode = !!(S.meta && (S.meta.admin || p.criado_por === S.meta.eu));
    var lista = (p.midias || []).map(function (m) {
      var tn = m.tipo === 'foto' ? '<img src="' + esc(m.url) + '" alt=""/>' : '<div class="pv-vid">🎬</div>';
      var alvo = m.tipo === 'foto' ? '<a href="' + esc(m.url) + '" target="_blank" rel="noopener">abrir</a>' : (m.url ? '<a href="' + esc(m.url) + '" target="_blank" rel="noopener">abrir vídeo</a>' : '<span class="pv-mut">enviando...</span>');
      return '<div class="pv-m">' + tn + '<div class="pv-m-i"><b>' + esc(MOMENTOS[m.momento] || m.momento) + '</b>' + (m.legenda ? '<br>' + esc(m.legenda) : '') + '<br>' + alvo + '</div>' + (pode ? '<button class="rs-btn" data-del="' + m.idpm + '" title="Tirar desta prova">🗑️</button>' : '') + '</div>';
    }).join('');
    var lim = S.meta ? S.meta.limites : { fotos: 12, videos: 4, video_seg: 60, video_mb: 60 };
    return '<div class="pv-ms">' + (lista || '<div class="pv-mut">Nenhuma foto ou vídeo ainda.</div>') + '</div>' + (pode ?
      '<div class="pv-up"><select id="pv-mom">' + opcoes(MOMENTOS, 'depois') + '</select><input type="text" id="pv-leg" maxlength="160" placeholder="Legenda (opcional)"/></div>' +
      '<div class="pv-up"><label class="rs-btn">📷 Adicionar fotos<input type="file" id="pv-foto" accept="image/*" multiple hidden/></label>' +
      '<label class="rs-btn">🎬 Enviar vídeo (até ' + lim.video_seg + ' s e ' + lim.video_mb + ' MB)<input type="file" id="pv-vid" accept="video/mp4,video/quicktime,video/webm" hidden/></label></div>' +
      '<div class="pv-up"><input type="text" id="pv-link" placeholder="Ou cole o link do vídeo (YouTube, Instagram, Drive...) https://"/><button class="rs-btn" id="pv-link-ok">Adicionar link</button></div>' +
      '<div class="dica">Até ' + lim.fotos + ' fotos e ' + lim.videos + ' vídeos por prova. Vídeo maior que o limite: suba no YouTube como "não listado" e cole o link.</div><div id="pv-prog" class="pv-prog"></div>' : '<div class="dica">Só quem cadastrou a prova (ou o administrador) altera as mídias.</div>');
  }

  function modal() {
    fechar();
    var p = S.aberta, m = S.meta || { objetivos: {}, autorizacoes: {}, usos: {} };
    var pode = !p.idprova || (S.meta && (S.meta.admin || p.criado_por === S.meta.eu));
    var el = document.createElement('div'); el.id = 'pv-modal'; el.className = 'rs-modal';
    el.innerHTML = '<div class="rs-form pv-form"><h3>' + (p.idprova ? 'Prova de ' + esc(p.pessoa_nome) : 'Nova prova social') + '</h3>' +
      (pode ? '' : '<div class="rs-aviso">Você só pode ler esta prova. Quem cadastrou (ou o administrador) altera.</div>') +
      '<div class="pv-sec">Quem é</div><div class="pv-g">' + campo('pv-nome', 'Nome', p.pessoa_nome, 'maxlength="120"') +
      '<div class="pv-c"><label for="pv-sexo">Sexo</label><select id="pv-sexo">' + opcoes({ F: 'Feminino', M: 'Masculino' }, p.sexo || '', 'Não informar') + '</select></div>' +
      campo('pv-idade', 'Idade', p.idade, 'inputmode="numeric"') +
      '<div class="pv-c"><label for="pv-obj">Objetivo</label><select id="pv-obj">' + opcoes(m.objetivos, p.objetivo) + '</select></div>' +
      '<div class="pv-c"><label for="pv-prog-s">Programa</label><select id="pv-prog-s">' + opcoes({ intus: 'Intus (consultoria)', cdd: 'Código da Definição' }, p.programa) + '</select></div>' +
      campo('pv-plano', 'Plano que usou', p.plano_usado, 'maxlength="80" placeholder="Ex.: Treino + Dieta trimestral"') + campo('pv-prof', 'Profissional', p.professor, 'maxlength="120"') + '</div>' +
      '<div class="dica">O robô e a IA usam só o primeiro nome. Sobrenome e telefone nunca vão em texto nenhum.</div>' +
      '<div class="pv-sec">O resultado</div><div class="pv-g">' + campo('pv-tempo-t', 'Quanto tempo', p.tempo_txt, 'maxlength="60" placeholder="Ex.: 4 meses"') + campo('pv-tempo-m', 'Tempo em meses (número)', num(p.tempo_meses), 'inputmode="decimal"') +
      campo('pv-pa', 'Peso antes (kg)', num(p.peso_antes), 'inputmode="decimal"') + campo('pv-pd', 'Peso depois (kg)', num(p.peso_depois), 'inputmode="decimal"') +
      campo('pv-ga', 'Gordura antes (%)', num(p.gordura_antes), 'inputmode="decimal"') + campo('pv-gd', 'Gordura depois (%)', num(p.gordura_depois), 'inputmode="decimal"') + '</div>' +
      campo('pv-med', 'Medidas ou outras conquistas', p.medidas_txt, 'maxlength="255" placeholder="Ex.: menos 8 cm de cintura"') +
      area('pv-res', 'A transformação, em uma ou duas frases', p.resultado_txt, 'Escreva só fatos. Sem promessa de resultado, de prazo ou de número para outra pessoa.') +
      area('pv-dep', 'Depoimento, nas palavras da pessoa (opcional)', p.depoimento_txt) +
      area('pv-ctx', 'Contexto para a equipe (opcional, nunca sai daqui)', p.contexto_txt, 'Ex.: rotina, dificuldades, o que mais ajudou. Serve de apoio para a inteligência entender o caso.') +
      campo('pv-tags', 'Etiquetas (separe por vírgula)', (p.tags || []).join(', '), 'maxlength="255" placeholder="mulher, 30+, rotina corrida"') +
      '<div class="pv-sec">Autorização de uso</div>' +
      '<div class="pv-c"><label for="pv-aut">A pessoa autorizou?</label><select id="pv-aut">' + opcoes(m.autorizacoes, p.autorizacao) + '</select></div>' +
      campo('pv-aut-o', 'Como foi autorizado (opcional)', p.autorizacao_obs, 'maxlength="255" placeholder="Ex.: áudio no WhatsApp em 10/2026"') +
      '<div class="pv-usos" id="pv-usos">' +
        ['robo', 'marketing', 'site', 'ia'].map(function (u) { return '<label class="chk"><input type="checkbox" id="pv-u-' + u + '"' + (p['uso_' + u] ? ' checked' : '') + '/> <span>' + esc(m.usos[u] || u) + '</span></label>'; }).join('') +
      '</div><div class="dica" id="pv-usos-d"></div>' +
      '<label class="chk"><input type="checkbox" id="pv-dest"' + (p.destaque ? ' checked' : '') + '/> <span>Prova em destaque (o robô prefere as de destaque)</span></label>' +
      '<label class="chk"><input type="checkbox" id="pv-ativo"' + (p.ativo ? ' checked' : '') + '/> <span>Prova ativa (desmarque para guardar sem usar em lugar nenhum)</span></label>' +
      '<div class="pv-sec">Fotos e vídeos</div><div id="pv-midias">' + midiasHtml(p) + '</div>' +
      '<div style="display:flex;gap:8px;margin-top:16px;flex-wrap:wrap"><button class="rs-btn" id="pv-fechar" style="flex:1">Fechar</button>' +
      (pode && p.idprova ? '<button class="rs-btn" id="pv-excluir" title="Excluir esta prova">🗑️ Excluir</button>' : '') +
      (pode ? '<button class="rs-btn on" id="pv-salvar" style="flex:2;padding:10px">Salvar</button>' : '') + '</div></div>';
    document.body.appendChild(el);
    document.getElementById('pv-fechar').onclick = function () { fechar(); carregar(); };
    var s = document.getElementById('pv-salvar'); if (s) s.onclick = salvar;
    var x = document.getElementById('pv-excluir'); if (x) x.onclick = excluir;
    document.getElementById('pv-aut').addEventListener('change', usosRegra);
    usosRegra();
    ligarMidias();
    if (!pode) Array.prototype.forEach.call(el.querySelectorAll('input,select,textarea'), function (n) { n.disabled = true; });
  }

  // O servidor repete esta regra: robô, marketing e site só com "autorizou divulgar"; IA com qualquer autorização.
  function usosRegra() {
    var a = document.getElementById('pv-aut').value, d = document.getElementById('pv-usos-d');
    var pode = { robo: a === 'divulgar', marketing: a === 'divulgar', site: a === 'divulgar', ia: a === 'divulgar' || a === 'interna' };
    Object.keys(pode).forEach(function (u) { var c = document.getElementById('pv-u-' + u); c.disabled = !pode[u]; if (!pode[u]) c.checked = false; });
    d.textContent = a === 'pendente' ? 'Sem autorização, nada sai desta tela: nem robô, nem marketing, nem a inteligência.' : (a === 'interna' ? 'Uso interno: só a inteligência pode estudar esta prova. Robô, marketing e site exigem "Autorizou divulgar".' : 'Marque onde esta prova pode aparecer.');
  }
  function val(id) { var n = document.getElementById(id); return n ? n.value.trim() : ''; }
  function coletar() {
    return { idprova: S.aberta.idprova || undefined, pessoa_nome: val('pv-nome'), sexo: val('pv-sexo'), idade: val('pv-idade'), objetivo: val('pv-obj'), programa: val('pv-prog-s'), plano_usado: val('pv-plano'), professor: val('pv-prof'),
      tempo_txt: val('pv-tempo-t'), tempo_meses: val('pv-tempo-m'), peso_antes: val('pv-pa'), peso_depois: val('pv-pd'), gordura_antes: val('pv-ga'), gordura_depois: val('pv-gd'), medidas_txt: val('pv-med'),
      resultado_txt: val('pv-res'), depoimento_txt: val('pv-dep'), contexto_txt: val('pv-ctx'), tags: val('pv-tags'), autorizacao: val('pv-aut'), autorizacao_obs: val('pv-aut-o'),
      uso_robo: document.getElementById('pv-u-robo').checked, uso_marketing: document.getElementById('pv-u-marketing').checked, uso_site: document.getElementById('pv-u-site').checked, uso_ia: document.getElementById('pv-u-ia').checked,
      destaque: document.getElementById('pv-dest').checked, ativo: document.getElementById('pv-ativo').checked };
  }
  function salvar() {
    if (ENVIANDO) return;
    ENVIANDO = true;
    var b = document.getElementById('pv-salvar'); b.disabled = true;
    chamar('?action=salvar', coletar()).then(function (r) {
      S.aberta = r.prova; S.ctx.toast('Prova salva.', 'success'); ENVIANDO = false; modal(); carregar();
    }).catch(function (e) { ENVIANDO = false; b.disabled = false; S.ctx.toast(e.message, 'error'); });
  }
  function excluir() {
    if (!confirm('Excluir esta prova? As fotos e vídeos saem dela e o robô e a inteligência deixam de usá-la. Essa ação não pode ser desfeita.')) return;
    chamar('?action=excluir', { idprova: S.aberta.idprova }).then(function () { fechar(); S.ctx.toast('Prova excluída.', 'success'); carregar(); }).catch(function (e) { S.ctx.toast(e.message, 'error'); });
  }

  // ── mídias ──
  function reduzir(file, lado) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var f = Math.min(1, lado / Math.max(img.naturalWidth, img.naturalHeight)), c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * f)); c.height = Math.max(1, Math.round(img.naturalHeight * f));
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url); resolve(c.toDataURL('image/jpeg', 0.88));
      };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('imagem ilegível')); };
      img.src = url;
    });
  }
  function duracao(file) {
    return new Promise(function (resolve) {
      var v = document.createElement('video'), url = URL.createObjectURL(file);
      v.preload = 'metadata'; v.onloadedmetadata = function () { var d = v.duration; URL.revokeObjectURL(url); resolve(isFinite(d) ? Math.round(d) : 0); };
      v.onerror = function () { URL.revokeObjectURL(url); resolve(0); }; v.src = url;
    });
  }
  function prog(t) { var n = document.getElementById('pv-prog'); if (n) n.textContent = t; }
  function refrescar() { return chamar('?action=obter&id=' + S.aberta.idprova).then(function (r) { S.aberta = r.prova; var n = document.getElementById('pv-midias'); if (n) { n.innerHTML = midiasHtml(S.aberta); ligarMidias(); } carregar(); }); }
  function ligarMidias() {
    var raiz = document.getElementById('pv-midias'); if (!raiz || !S.aberta.idprova) return;
    var id = S.aberta.idprova, mom = function () { return val('pv-mom') || 'depois'; }, leg = function () { return val('pv-leg'); };
    raiz.querySelectorAll('[data-del]').forEach(function (b) {
      b.onclick = function () { if (!confirm('Tirar esta mídia da prova?')) return; chamar('?action=midia_excluir', { idpm: parseInt(b.getAttribute('data-del'), 10) }).then(refrescar).catch(function (e) { S.ctx.toast(e.message, 'error'); }); };
    });
    var fo = document.getElementById('pv-foto');
    if (fo) fo.onchange = async function () {
      var arqs = Array.prototype.slice.call(fo.files || []); fo.value = '';
      for (var i = 0; i < arqs.length; i++) {
        prog('Enviando foto ' + (i + 1) + ' de ' + arqs.length + '...');
        try { var d = await reduzir(arqs[i], 1600); await chamar('?action=foto', { idprova: id, imagem: d, momento: mom(), legenda: leg() }); }
        catch (e) { S.ctx.toast('Foto ' + (i + 1) + ': ' + (e.message || 'erro'), 'error'); break; }
      }
      prog(''); refrescar();
    };
    var vi = document.getElementById('pv-vid');
    if (vi) vi.onchange = async function () {
      var f = vi.files && vi.files[0]; vi.value = ''; if (!f) return;
      try {
        var seg = await duracao(f);
        prog('Preparando o envio do vídeo...');
        var ini = await chamar('?action=video_iniciar', { idprova: id, mime: f.type, tamanho: f.size, duracao_seg: seg, momento: mom(), legenda: leg() });
        var pos = 0, tam = ini.parte_bytes, tentativas = 0;
        while (pos < f.size) {
          var fim = Math.min(f.size, pos + tam), r = await S.ctx.api('/provas.php?action=video_parte&id=' + ini.id + '&inicio=' + pos, { method: 'POST', headers: { 'Content-Type': 'application/octet-stream' }, body: f.slice(pos, fim) });
          if (!r.ok) { if (++tentativas > 3) throw new Error(await jsonErro(r)); continue; }
          var j = await r.json(); tentativas = 0; pos = j.recebido;
          prog('Enviando vídeo: ' + Math.round(pos * 100 / f.size) + '%');
          if (j.concluido) break;
        }
        S.ctx.toast('Vídeo enviado.', 'success');
      } catch (e) { S.ctx.toast('Vídeo: ' + (e.message || 'erro'), 'error'); }
      prog(''); refrescar();
    };
    var lk = document.getElementById('pv-link-ok');
    if (lk) lk.onclick = function () {
      var url = val('pv-link'); if (!url) return;
      chamar('?action=link', { idprova: id, url: url, momento: mom(), legenda: leg() }).then(refrescar).catch(function (e) { S.ctx.toast(e.message, 'error'); });
    };
  }

  window.Provas = {
    montar: function (el, ctx) { S.el = el; S.ctx = ctx; desenhar(); carregar(); },
    fechar: fechar
  };
})();
