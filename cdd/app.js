/* CDD: app da aluna (mobile primeiro). Início, Aulas (estilo Netflix), Treino (mesma lógica do treino da Intus), Jornada, Ranking, Conquistas e Anamnese. */
(function () {
  var $app = document.getElementById('app');
  var esc = CDD.esc;
  var estado = { eu: null, matricula: null, acesso: false, modulos: null, home: null };
  var TR = null;           // treino em andamento
  var WL = null;           // trava de tela ligada
  CDD.tema();

  var IMG2 = '<img class="logo-escuro" src="marca/logo-cdd-branca.png" alt="Código da Definição"><img class="logo-claro" src="marca/logo-cdd-preta.png" alt="Código da Definição">';
  var LOGO = '<div class="logo"><div class="logo-caixa">' + IMG2 + '</div></div>';
  var MARCA = '<a class="marca" href="#/"><span class="marca-img">' + IMG2 + '</span></a>';
  var MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
  // WhatsApp de atendimento da Intus/CDD. Toda mensagem sai com [CDD] e, logada, com nome e e-mail da aluna, para a equipe identificar de quem é.
  var ZAP_NUM = '5545991156006';
  function zapAjuda() {
    var eu = (typeof estado !== 'undefined' && estado && estado.eu) ? estado.eu : null;
    var quem = eu ? ' Sou ' + (eu.nome || 'aluna') + (eu.email ? ' (' + eu.email + ')' : '') + '.' : '';
    return 'https://wa.me/' + ZAP_NUM + '?text=' + encodeURIComponent('[CDD] Oi!' + quem + ' Preciso de ajuda no app do Código da Definição.');
  }
  var LINK_SENHA_ZAP = 'https://wa.me/' + ZAP_NUM + '?text=' + encodeURIComponent('[CDD] Oi! Esqueci a minha senha do app do Código da Definição. Pode me enviar um novo link de acesso? Meu nome e meu e-mail de cadastro são: ');
  var LINK_SENHA_EMAIL = 'mailto:contato@codigodadefinicao.com.br?subject=' + encodeURIComponent('Esqueci a minha senha do app') + '&body=' + encodeURIComponent('Oi! Esqueci a senha do app do Código da Definição. Pode me enviar um novo link de acesso?' + String.fromCharCode(10, 10) + 'Meu nome:' + String.fromCharCode(10) + 'Meu e-mail de cadastro: ');

  /* ───────── utilidades ───────── */
  function msg(t, tipo) { return t ? '<div class="msg ' + (tipo || 'erro') + '">' + esc(t) + '</div>' : ''; }
  function dm(d) { var p = String(d || '').slice(0, 10).split('-'); return p.length === 3 ? parseInt(p[2], 10) + ' ' + MESES[parseInt(p[1], 10) - 1] : ''; }
  function diasAte(d) { var a = new Date(String(d).slice(0, 10) + 'T00:00:00'), h = new Date(); h.setHours(0, 0, 0, 0); return Math.round((a - h) / 86400000); }
  function hojeISO() { var h = new Date(); return h.getFullYear() + '-' + ('0' + (h.getMonth() + 1)).slice(-2) + '-' + ('0' + h.getDate()).slice(-2); }
  function mmss(s) { s = Math.max(0, Math.floor(s)); var m = Math.floor(s / 60); return (m > 99 ? m : ('0' + m).slice(-2)) + ':' + ('0' + (s % 60)).slice(-2); }
  function num(v) { return String(v).replace('.', ','); }
  function curto(t, n) { t = String(t || ''); return t.length > n ? t.slice(0, n) + '...' : t; }
  function norm(t) { return String(t || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '').slice(0, 200); }
  function primeiro(n) { return String(n || '').split(' ')[0]; }
  function nTitulo(t) { var m = /#(\d+)/.exec(t || ''); return m ? '#' + m[1] : ''; }
  function tituloLimpo(t) { return String(t || '').replace(/^Protocolo de Treino #\d+\s*:?\s*/i, '').replace(/^Bônus:\s*/i, '') || t; }
  function vibrar(ms) { try { if (navigator.vibrate) navigator.vibrate(ms); } catch (e) {} }
  function rolagem() { window.scrollTo(0, 0); }

  function toast(emoji, titulo, texto) {
    var t = document.createElement('div'); t.className = 'toast';
    t.innerHTML = '<span class="em">' + emoji + '</span><div><b>' + esc(titulo) + '</b>' + (texto ? '<div class="mut">' + esc(texto) + '</div>' : '') + '</div>';
    document.body.appendChild(t); vibrar(30);
    setTimeout(function () { t.style.transition = 'opacity .4s'; t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 450); }, 4200);
  }
  function celebrar(novas) {
    if (!novas || !novas.length) return;
    novas.forEach(function (c, i) { setTimeout(function () { toast(c.emoji, 'Conquista: ' + c.titulo, c.desc); }, i * 1500); });
  }

  /** Janela. opcoes.centro = caixa no meio (confirmações). */
  function abrirModal(html, centro) {
    var m = document.createElement('div'); m.className = 'modal' + (centro ? ' centro' : '');
    m.innerHTML = '<div class="caixa">' + html + '</div>';
    m.addEventListener('click', function (e) { if (e.target === m && !m.getAttribute('data-fixo')) m.remove(); });
    document.body.appendChild(m);
    m.fechar = function () { m.remove(); };
    return m;
  }
  function confirmar(o, ok) {
    var m = abrirModal('<div style="font-size:40px">' + (o.emoji || '❓') + '</div><h3 style="margin:8px 0">' + esc(o.titulo) + '</h3><p class="mut" style="margin-bottom:16px;line-height:1.5">' + (o.html || esc(o.texto || '')) + '</p>' +
      '<div class="linha" style="justify-content:center"><button class="btn" data-n>' + esc(o.nao || 'Cancelar') + '</button><button class="btn pri" data-s>' + esc(o.sim || 'Confirmar') + '</button></div>', true);
    m.setAttribute('data-fixo', '1');
    m.querySelector('[data-n]').onclick = function () { m.remove(); if (o.aoNao) o.aoNao(); };
    m.querySelector('[data-s]').onclick = function () { m.remove(); ok(); };
    return m;
  }
  function botaoTema() { return '<button class="iconbtn" data-tema-btn aria-label="Alternar tema"></button>'; }
  function ligarTema(raiz) { (raiz || document).querySelectorAll('[data-tema-btn]').forEach(function (b) { b.onclick = function () { CDD.alternarTema(); }; }); CDD.aplicarMeta(); }

  /* ───────── entrada ───────── */
  function telaLogin(aviso, tipo) {
    $app.innerHTML = '<div class="login">' + LOGO + '<div class="card"><div class="linha" style="justify-content:space-between"><h2>Entrar</h2>' + botaoTema() + '</div>' +
      '<div id="aviso">' + msg(aviso, tipo) + '</div>' +
      '<form id="f"><label for="em">E-mail</label><input id="em" type="email" autocomplete="username" required>' +
      '<label for="se">Senha</label><input id="se" type="password" autocomplete="current-password" required>' +
      '<div class="espaco"></div><button class="btn pri grande" id="ok">Entrar</button></form>' +
      '<p class="sep">Ainda não tem conta? <a href="#/cadastro">Criar conta</a></p>' +
      '<p class="sep mut" style="margin-bottom:8px">Esqueceu a senha? A equipe envia um novo link de acesso.</p><div class="linha" style="justify-content:center"><a class="btn peq" href="' + LINK_SENHA_ZAP + '" target="_blank" rel="noopener">💬 Pedir pelo WhatsApp</a><a class="btn peq" href="' + LINK_SENHA_EMAIL + '">✉️ Pedir por e-mail</a></div></div><p class="sep"><a href="painel.html">Acesso da equipe (painel)</a></p></div>';
    ligarTema();
    document.getElementById('f').onsubmit = async function (e) {
      e.preventDefault();
      var b = document.getElementById('ok'); b.disabled = true;
      var r = await CDD.api('login', { body: { email: document.getElementById('em').value, senha: document.getElementById('se').value } });
      b.disabled = false;
      if (!r.ok) { document.getElementById('aviso').innerHTML = msg(r.data.mensagem); return; }
      CDD.guardar(r.data.token); iniciar();
    };
  }

  function telaCadastro() {
    $app.innerHTML = '<div class="login">' + LOGO + '<div class="card"><div class="linha" style="justify-content:space-between"><h2>Criar conta</h2>' + botaoTema() + '</div>' +
      '<p class="mut">O Código da Definição é um programa exclusivo para mulheres. 💪</p><div id="aviso"></div>' +
      '<form id="f"><label for="no">Nome</label><input id="no" autocomplete="name" required maxlength="160">' +
      '<label for="em">E-mail</label><input id="em" type="email" autocomplete="username" required>' +
      '<label for="se">Senha (mínimo 8 caracteres)</label><input id="se" type="password" autocomplete="new-password" minlength="8" required>' +
      '<label for="sx">Sexo</label><select id="sx" required><option value="">Escolha</option><option value="feminino">Feminino</option><option value="masculino">Masculino</option></select>' +
      '<label class="chk"><input type="checkbox" id="ac"><span>Li e aceito os <a href="termos.html" target="_blank" rel="noopener">termos e a política de privacidade</a>.</span></label>' +
      '<button class="btn pri grande" id="ok">Criar conta</button></form>' +
      '<p class="sep">Já tem conta? <a href="#/">Entrar</a></p></div></div>';
    ligarTema();
    document.getElementById('f').onsubmit = async function (e) {
      e.preventDefault();
      var b = document.getElementById('ok'); b.disabled = true;
      var r = await CDD.api('cadastro', { body: { nome: document.getElementById('no').value, email: document.getElementById('em').value, senha: document.getElementById('se').value, sexo: document.getElementById('sx').value, aceite: document.getElementById('ac').checked } });
      b.disabled = false;
      if (r.status === 403 && r.data.error === 'exclusivo_mulheres') {
        document.getElementById('aviso').innerHTML = '<div class="msg aviso">' + esc(r.data.mensagem) + '<div class="espaco"></div><a class="btn pri" href="' + esc(r.data.intus_url) + '" rel="noopener">Conhecer a Intus Fit</a></div>';
        return;
      }
      if (!r.ok) { document.getElementById('aviso').innerHTML = msg(r.data.mensagem); return; }
      CDD.guardar(r.data.token); location.hash = '#/'; iniciar();
    };
  }

  async function telaAtivar(token) {
    $app.innerHTML = '<div class="login">' + LOGO + '<div class="card" id="c">Conferindo o link...</div></div>';
    var r = await CDD.api('ativar_info', { q: { t: token } });
    var c = document.getElementById('c');
    if (!r.ok) { c.innerHTML = msg(r.data.mensagem) + '<p class="sep"><a href="#/">Ir para o login</a></p>'; return; }
    c.innerHTML = '<h2 style="margin-bottom:6px">Olá, ' + esc(r.data.nome) + ' 👋</h2><p class="mut">Crie a sua senha para entrar no Código da Definição.</p><div id="aviso"></div>' +
      '<form id="f"><label for="se">Senha (mínimo 8 caracteres)</label><input id="se" type="password" autocomplete="new-password" minlength="8" required>' +
      '<label class="chk"><input type="checkbox" id="ac"><span>Li e aceito os <a href="termos.html" target="_blank" rel="noopener">termos e a política de privacidade</a>.</span></label>' +
      '<button class="btn pri grande" id="ok">Criar senha e entrar</button></form>';
    document.getElementById('f').onsubmit = async function (e) {
      e.preventDefault();
      if (!document.getElementById('ac').checked) { document.getElementById('aviso').innerHTML = msg('É preciso aceitar os termos.'); return; }
      var b = document.getElementById('ok'); b.disabled = true;
      var x = await CDD.api('ativar', { body: { token: token, senha: document.getElementById('se').value } });
      b.disabled = false;
      if (!x.ok) { document.getElementById('aviso').innerHTML = msg(x.data.mensagem); return; }
      CDD.guardar(x.data.token); location.hash = '#/'; iniciar();
    };
  }

  /* ───────── estrutura ───────── */
  function topo() {
    return '<div class="app-top">' + MARCA + '<div class="acoes">' + botaoTema() + '<a class="iconbtn av" href="#/conta" aria-label="Minha conta">' + esc((estado.eu.nome || '?').charAt(0).toUpperCase()) + '</a></div></div>';
  }
  function navBaixo(ativa) {
    var t = estado.home && estado.home.treino_hoje ? '#/treino/' + estado.home.treino_hoje.idaula : '#/aulas';
    function it(id, href, ic, rot, cls) { return '<a href="' + href + '" class="' + (ativa === id ? 'ativo ' : '') + (cls || '') + '"><span class="ic">' + ic + '</span>' + rot + '</a>'; }
    return '<div class="nav-baixo">' + it('inicio', '#/', '🏠', 'Início') + it('aulas', '#/aulas', '🎬', 'Aulas') + it('treino', t, '🏋️', 'Treino', 'centro') + it('jornada', '#/jornada', '🗺️', 'Jornada') + it('ranking', '#/ranking', '🏆', 'Ranking') + '</div>';
  }
  function tela(ativa, conteudo, semNav) {
    $app.innerHTML = topo() + '<div class="wrap" id="v">' + (conteudo || '<div class="vazio">Carregando...</div>') + '</div>' + (semNav ? '' : navBaixo(ativa));
    ligarTema(); CDD.aplicarCapas($app);
    return document.getElementById('v');
  }
  function semAcesso() {
    var m = estado.matricula || {};
    var texto = m.status === 'expirada' ? 'Seu acesso expirou. Fale com a equipe do CDD para renovar.'
      : m.status === 'sem_matricula' ? 'Sua conta está criada. Assim que a equipe liberar a sua matrícula, o conteúdo aparece aqui.'
      : 'Seu acesso está pausado. Fale com a equipe do CDD.';
    return '<div class="card hero"><h2>Olá, ' + esc(primeiro(estado.eu.nome)) + ' 👋</h2><p style="margin-top:6px">' + esc(texto) + '</p><span class="grande-emoji">⏳</span></div>' +
      (estado.anamneseEnviada ? '' : '<a class="banner" href="#/anamnese" style="margin-top:12px"><span class="em">📝</span><div><b>Adiante a sua anamnese</b><span class="mut">Enquanto a matrícula não sai, você já pode responder. Leva uns 5 minutos.</span></div></a>') +
      '<a class="btn grande" style="margin-top:12px" href="' + zapAjuda() + '" target="_blank" rel="noopener">💬 Falar com a equipe</a>';
  }
  function sessaoExpirou(r) { if (r.status === 401) { CDD.guardar(''); estado = { eu: null }; telaLogin('Sua sessão expirou. Entre de novo.', 'aviso'); return true; } return false; }

  async function carregarHome() {
    var r = await CDD.api('meus_modulos');
    if (sessaoExpirou(r)) return null;
    if (!r.ok) return null;
    estado.modulos = r.data.modulos; estado.home = r.data;
    return estado.modulos;
  }

  /* ───────── capas e filas (estilo Netflix) ───────── */
  function capaHtml(m, larga) {
    var n = nTitulo(m.titulo), pct = m.total ? Math.round(m.feitas * 100 / m.total) : 0;
    var cor = /^#[0-9a-fA-F]{6}$/.test(m.cor || '') ? m.cor : '#e2272d';
    var estilo = '--c:' + cor;
    var cap = m.capa_url ? ' data-capa="' + esc(m.capa_url) + '"' : '';
    var emoji = m.capa_url ? '' : '<span class="em">' + esc(m.emoji || '🎬') + '</span>';
    var sub = m.liberado ? m.total + (m.total === 1 ? ' aula' : ' aulas') : (m.libera_em ? 'Libera em ' + dm(m.libera_em) : 'Em breve');
    var tem = !!m.capa_url;   // capa com título e logo já na imagem: o app só mostra o selo de aulas
    var corpo = emoji + (n && !tem ? '<span class="num">' + n + '</span>' : '') + '<span class="ti">' + (tem ? '' : esc(tituloLimpo(m.titulo))) + '<small>' + esc(sub) + '</small></span>' +
      (m.liberado && pct ? '<span class="pg"><i style="width:' + pct + '%"></i></span>' : '') + (!m.liberado ? '<span class="cad">🔒</span>' : '');
    if (!m.liberado) return '<div class="capa ' + (tem ? 'com-img ' : '') + (larga ? 'larga ' : '') + 'trancado" style="' + estilo + '"' + cap + ' role="img" aria-label="' + esc(m.titulo) + ' (trancado)">' + corpo + '</div>';
    return '<a class="capa ' + (tem ? 'com-img ' : '') + (larga ? 'larga' : '') + '" style="' + estilo + '"' + cap + ' href="#/modulo/' + m.idmodulo + '">' + corpo + '</a>';
  }
  function fila(titulo, lista, extra) {
    if (!lista.length) return '';
    return '<div class="sec"><h3>' + titulo + '</h3>' + (extra || '') + '</div><div class="fila">' + lista.map(function (m) { return capaHtml(m); }).join('') + '</div>';
  }

  function moduloAtual(ms) {
    var jornada = ms.filter(function (m) { return m.grupo !== 'bonus'; });
    for (var i = 0; i < jornada.length; i++) { var m = jornada[i]; if (m.liberado && m.total && m.feitas < m.total) return m; }
    return jornada.filter(function (m) { return m.liberado; }).pop() || null;
  }
  function proximaAula() {
    var ms = estado.modulos || [];
    var cur = moduloAtual(ms);
    var ordem = cur ? [cur].concat(ms.filter(function (m) { return m !== cur; })) : ms;
    for (var i = 0; i < ordem.length; i++) { if (!ordem[i].liberado) continue; for (var j = 0; j < ordem[i].aulas.length; j++) if (!ordem[i].aulas[j].concluida) return { aula: ordem[i].aulas[j], modulo: ordem[i] }; }
    return null;
  }
  function proximoDesbloqueio(ms) {
    var t = ms.filter(function (m) { return !m.liberado && m.libera_em; }).sort(function (a, b) { return a.libera_em < b.libera_em ? -1 : 1; });
    return t[0] || null;
  }

  /* ───────── início ───────── */
  async function viewHome() {
    var v = tela('inicio');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var ms = await carregarHome();
    if (!ms) { if (estado.eu) v.innerHTML = '<div class="msg erro">Não foi possível carregar o conteúdo. Tente de novo.</div>'; return; }
    var h = estado.home, t = h.treino_hoje;
    var nb = $app.querySelector('.nav-baixo'); if (nb) nb.outerHTML = navBaixo('inicio');
    var cur = moduloAtual(ms), prox = proximaAula(), desb = proximoDesbloqueio(ms);
    var jm = ms.filter(function (m) { return m.grupo !== 'bonus'; });
    var tot = 0, fe = 0; jm.forEach(function (m) { if (m.liberado) { tot += m.total; fe += m.feitas; } });
    var pct = tot ? Math.round(fe * 100 / tot) : 0;
    var html = '<h2 style="margin:2px 0 10px">Oi, ' + esc(primeiro(estado.eu.nome)) + ' 👋</h2><div class="chips">' +
      '<span class="chip">🔥 ' + h.treinos_semana + (h.treinos_semana === 1 ? ' treino' : ' treinos') + ' na semana</span>' +
      '<a class="chip" href="#/ranking" style="text-decoration:none;color:inherit">⭐ ' + h.pontos_semana + ' pts' + (h.posicao_semana ? ' · ' + h.posicao_semana + 'º' : '') + '</a>' +
      '<a class="chip" href="#/jornada/conquistas" style="text-decoration:none;color:inherit">🏅 ' + h.conquistas_ganhas + '/' + h.conquistas_total + '</a></div>';
    if (!h.anamnese_enviada) html += '<a class="banner" href="#/anamnese" style="margin-top:14px"><span class="em">📝</span><div><b>Complete o seu perfil</b><span class="mut">A anamnese ajuda a equipe a acompanhar você. Leva uns 5 minutos.</span></div></a>';
    if (t) {
      html += '<div class="card hero" style="margin-top:14px"><div class="mut" style="text-transform:uppercase;letter-spacing:.12em;font-size:11px;font-weight:800">Treino de hoje</div>' +
        '<h2 style="margin:6px 0 2px;overflow-wrap:anywhere;max-width:78%">' + esc(t.titulo) + '</h2><div class="mut">' + esc(tituloLimpo(t.modulo)) + '</div>' +
        (t.treinou_hoje ? '<div style="margin-top:10px;font-weight:700">✅ Treino de hoje registrado</div>' : '') +
        '<div class="linha" style="margin-top:14px"><a class="btn pri" href="#/treino/' + t.idaula + '">' + (t.treinou_hoje ? 'Ver ou editar' : '🏋️ Começar treino') + '</a>' +
        (t.alternativas.length > 1 ? '<button class="btn" id="outro">Trocar</button>' : '') + '</div><span class="grande-emoji">🏋️</span></div>';
    }
    if (cur) {
      var pc = cur.total ? Math.round(cur.feitas * 100 / cur.total) : 0;
      html += '<a class="card" href="#/jornada" style="display:flex;gap:14px;align-items:center;margin-top:12px;text-decoration:none;color:inherit"><div class="anel" style="--p:' + pct + ';--s:70px"><b style="font-size:16px">' + pct + '%</b></div>' +
        '<div style="min-width:0;flex:1"><div class="mut" style="font-weight:700;text-transform:uppercase;letter-spacing:.08em;font-size:11px">Sua jornada</div><b style="display:block;overflow-wrap:anywhere">' + esc(cur.emoji || '') + ' ' + esc(tituloLimpo(cur.titulo)) + '</b>' +
        '<span class="mut">' + (desb ? '🔓 Próximo módulo em ' + Math.max(0, diasAte(desb.libera_em)) + ' dias' : 'Todos os módulos liberados') + '</span></div><span class="mut">›</span></a>';
    }
    html += '<div class="habs" id="habs"></div>';
    if (prox) {
      var a = prox.aula;
      html += '<div class="sec"><h3>▶️ Continue de onde parou</h3></div><a class="vid" href="#/aula/' + a.idaula + '"><span class="th"' + (a.yt ? ' style="background-image:url(\'' + esc(CDD.ytThumb(a.yt)) + '\')"' : '') + '>' + (a.yt ? '<span class="pl">▶</span>' : esc(prox.modulo.emoji || '🎬')) + '</span><span class="tx"><b>' + esc(a.titulo) + '</b><span class="est">' + esc(tituloLimpo(prox.modulo.titulo)) + '</span></span></a>';
    }
    if (h.proximas_conquistas && h.proximas_conquistas.length) {
      html += '<div class="sec"><h3>🏅 Quase lá</h3><a href="#/jornada/conquistas">ver todas</a></div><div class="conq-g">' + h.proximas_conquistas.map(function (c) {
        return '<div class="conq off"><span class="em">' + c.emoji + '</span><b>' + esc(c.titulo) + '</b><small>' + c.atual + '/' + c.meta + '</small></div>'; }).join('') + '</div>';
    }
    html += fila('🔥 Protocolos de treino', ms.filter(function (m) { return m.grupo === 'protocolo'; }), '<a href="#/aulas">ver tudo</a>');
    v.innerHTML = html;
    CDD.aplicarCapas(v);
    habitosHome(document.getElementById('habs'));
    var ou = document.getElementById('outro');
    if (ou) ou.onclick = function () {
      var m = abrirModal('<h3>Escolher treino</h3><p class="mut">' + esc(tituloLimpo(t.modulo)) + '</p><div id="alts"></div><div class="espaco"></div><button class="btn grande" data-f>Fechar</button>');
      m.querySelector('#alts').innerHTML = t.alternativas.map(function (x) { return '<a class="vid" href="#/treino/' + x.idaula + '"><span class="tx"><b>🏋️ ' + esc(x.titulo) + '</b></span></a>'; }).join('');
      m.querySelector('[data-f]').onclick = m.fechar; m.querySelectorAll('a').forEach(function (a) { a.onclick = m.fechar; });
    };
  }

  /* ───────── aulas (Netflix) ───────── */
  async function viewAulas() {
    var v = tela('aulas');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var ms = estado.modulos || await carregarHome();
    if (!ms) return;
    var nb = $app.querySelector('.nav-baixo'); if (nb) nb.outerHTML = navBaixo('aulas');
    var cur = moduloAtual(ms), prox = proximaAula();
    var dest = prox ? prox.modulo : cur;
    var html = '<h2 style="margin:2px 0 12px">🎬 Aulas e conteúdos</h2>';
    if (dest) {
      var cor = /^#[0-9a-fA-F]{6}$/.test(dest.cor || '') ? dest.cor : '#e2272d';
      html += '<div class="destaque" style="--c:' + cor + '"' + (dest.capa_url ? ' data-capa="' + esc(CDD.capaLarga(dest.capa_url)) + '"' : '') + '>' + (dest.capa_url ? '' : '<span class="em">' + esc(dest.emoji || '🎬') + '</span>') +
        '<span class="mut" style="color:rgba(255,255,255,.85);font-weight:800;text-transform:uppercase;letter-spacing:.1em;font-size:11px">' + (prox ? 'Continue assistindo' : 'Em destaque') + '</span><h2>' + esc(tituloLimpo(dest.titulo)) + '</h2>' +
        '<span style="font-size:13px;opacity:.9">' + dest.feitas + ' de ' + dest.total + ' aulas</span><a class="btn pri" href="' + (prox ? '#/aula/' + prox.aula.idaula : '#/modulo/' + dest.idmodulo) + '">▶️ ' + (prox ? 'Assistir agora' : 'Abrir') + '</a></div>';
    }
    html += fila('📚 Seu programa', ms.filter(function (m) { return m.grupo === 'curso'; }));
    html += fila('🔥 Protocolos de treino', ms.filter(function (m) { return m.grupo === 'protocolo'; }));
    html += fila('✨ Bônus e conteúdos extras', ms.filter(function (m) { return m.grupo === 'bonus'; }));
    v.innerHTML = html;
    CDD.aplicarCapas(v);
  }

  async function viewModulo(id) {
    var v = tela('aulas');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var ms = estado.modulos || await carregarHome();
    if (!ms) return;
    var m = ms.filter(function (x) { return x.idmodulo === id; })[0];
    if (!m) { v.innerHTML = '<div class="msg erro">Módulo não encontrado.</div><a class="btn" href="#/aulas">Voltar</a>'; return; }
    var cor = /^#[0-9a-fA-F]{6}$/.test(m.cor || '') ? m.cor : '#e2272d', pct = m.total ? Math.round(m.feitas * 100 / m.total) : 0;
    var h = '<a class="voltar" href="#/aulas">‹ Aulas</a><div class="cabmod" style="--c:' + cor + '"' + (m.capa_url ? ' data-capa="' + esc(CDD.capaLarga(m.capa_url)) + '"' : '') + '>' + (m.capa_url ? '' : '<span class="em">' + esc(m.emoji || '🎬') + '</span>') +
      '<h2>' + esc(tituloLimpo(m.titulo)) + '</h2><span style="font-size:13px;opacity:.9">' + m.total + (m.total === 1 ? ' aula' : ' aulas') + ' · ' + m.feitas + ' concluída' + (m.feitas === 1 ? '' : 's') + '</span><div class="barra"><i style="width:' + pct + '%"></i></div></div>';
    if (m.descricao) h += '<p class="mut" style="margin:12px 2px 0">' + esc(m.descricao) + '</p>';
    if (!m.liberado) h += '<div class="msg aviso" style="margin-top:14px">🔒 Este módulo será liberado em ' + esc(dm(m.libera_em)) + '. Você já pode seguir com os outros conteúdos.</div>';
    m.aulas.forEach(function (a) {
      var ic = a.tipo === 'anamnese' ? '📝' : a.tem_ficha ? '🏋️' : a.tipo === 'link' ? '🔗' : a.tipo === 'texto' ? '📄' : '🎬';
      h += '<a class="vid' + (a.concluida ? ' feita' : '') + '" href="#/aula/' + a.idaula + '"><span class="th"' + (a.yt ? ' style="background-image:url(\'' + esc(CDD.ytThumb(a.yt)) + '\')"' : (m.capa_url ? ' data-capa="' + esc(CDD.capaLarga(m.capa_url)) + '"' : '')) + '>' + (a.yt ? '<span class="pl">▶</span>' : ic) + (a.concluida ? '<span class="ok">✓</span>' : '') + '</span>' +
        '<span class="tx"><b>' + esc(a.titulo) + '</b><span class="est">' + (a.tem_ficha ? '🏋️ ficha de treino' : a.concluida ? 'Concluída' : 'Aula') + '</span></span></a>';
    });
    v.innerHTML = h; CDD.aplicarCapas(v);
  }

  /* ───────── aula ───────── */
  function botaoVideo(video) {
    if (CDD.ytId(video)) return '<button class="btn peq" data-yt="' + esc(video) + '">▶ Como fazer</button>';
    if (video) return '<a class="btn peq" href="' + esc(video) + '" target="_blank" rel="noopener">▶ Como fazer</a>';
    return '';
  }
  function fichaHtml(f) {
    if (!f.length) return '';
    var h = '<div class="tbl"><table><thead><tr><th>Exercício</th><th>Séries</th><th>Reps</th><th>Intervalo</th><th>Método</th><th></th></tr></thead><tbody>', g = null;
    f.forEach(function (i) {
      if ((i.grupo || '') !== g && i.grupo) { g = i.grupo; h += '<tr class="grupo"><td colspan="6">' + esc(g) + '</td></tr>'; }
      h += '<tr><td>' + esc(i.exercicio) + (i.obs ? '<div class="mut">' + esc(i.obs) + '</div>' : '') + '</td><td class="num">' + esc(i.series) + '</td><td class="num">' + esc(i.repeticoes) + '</td><td class="num">' + esc(i.intervalo) + '</td><td>' + esc(i.metodo) + '</td><td>' + botaoVideo(i.video) + '</td></tr>';
    });
    return h + '</tbody></table></div>';
  }
  function ligarVideos(raiz) { raiz.querySelectorAll('[data-yt]').forEach(function (b) { b.onclick = function () { abrirVideo(b.getAttribute('data-yt')); }; }); }
  function abrirVideo(id) {
    var m = abrirModal('<div class="video"><iframe src="' + esc(CDD.ytEmbed(id)) + '" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div><div class="espaco"></div><button class="btn grande" data-f>Fechar</button>');
    m.querySelector('[data-f]').onclick = m.fechar;
  }
  function estrelas(nota) { var h = ''; for (var i = 1; i <= 5; i++) h += '<button class="estrela' + (nota >= i ? ' on' : '') + '" data-n="' + i + '" aria-label="' + i + ' estrela' + (i > 1 ? 's' : '') + '">★</button>'; return h; }
  function quando(s) {
    var d = new Date(String(s).replace(' ', 'T')); if (isNaN(d)) return '';
    var dias = Math.round((new Date().setHours(0, 0, 0, 0) - new Date(d).setHours(0, 0, 0, 0)) / 86400000);
    return dias <= 0 ? 'hoje' : dias === 1 ? 'ontem' : dias < 30 ? 'há ' + dias + ' dias' : dm(String(s).slice(0, 10));
  }
  function comentarioHtml(c, resposta) {
    return '<div class="coment' + (c.equipe ? ' equipe' : '') + (resposta ? ' resp' : '') + '"><div class="av">' + esc(c.autor.charAt(0)) + '</div><div class="cc"><div class="cn"><b>' + esc(c.autor) + '</b>' + (c.equipe ? ' <span class="tag ok">equipe</span>' : '') + ' <span class="mut">' + quando(c.quando) + '</span></div><div class="texto">' + esc(c.texto) + '</div>' +
      '<div class="cac">' + (!resposta ? '<button class="lk" data-resp="' + c.id + '">Responder</button>' : '') + (c.meu ? '<button class="lk" data-del="' + c.id + '">Apagar</button>' : '') + '</div>' + (!resposta ? '<div id="rf-' + c.id + '"></div>' : '') + '</div></div>';
  }
  async function blocoInteracao(a, el) {
    el.innerHTML = '<div class="sec"><h3>⭐ O que você achou desta aula?</h3></div><div class="card"><div class="linha" id="est" style="gap:2px"></div><div class="mut" id="media" style="margin-top:6px"></div></div>' +
      '<div class="sec"><h3>💬 Comentários</h3></div><div class="card"><form id="cf"><textarea id="ct" maxlength="1000" placeholder="Compartilhe sua experiência ou tire uma dúvida" style="min-height:70px"></textarea><div class="linha" style="margin-top:8px;justify-content:space-between"><span class="mut" id="ca"></span><button class="btn pri peq">Comentar</button></div></form></div><div id="lc" style="margin-top:12px"></div>';
    var minha = a.minha_nota || 0;
    function pintar(d) {
      document.getElementById('est').innerHTML = estrelas(minha);
      document.getElementById('media').textContent = (d.votos ? 'Média ' + num(d.media) + ' (' + d.votos + (d.votos === 1 ? ' avaliação' : ' avaliações') + ')' : 'Seja a primeira a avaliar') + (minha ? ' · sua nota: ' + minha : '');
      document.querySelectorAll('#est .estrela').forEach(function (b) { b.onclick = async function () {
        var x = await CDD.api('avaliar', { body: { id: a.idaula, nota: parseInt(b.getAttribute('data-n'), 10) } });
        if (x.ok) { minha = x.data.minha_nota; celebrar(x.data.novas); carregar(); } }; });
    }
    function lista(d) {
      var lc = document.getElementById('lc');
      if (!d.comentarios.length) { lc.innerHTML = '<div class="vazio" style="padding:12px">Ainda não há comentários. Comece a conversa. 💬</div>'; return; }
      lc.innerHTML = d.comentarios.map(function (c) { return '<div class="card">' + comentarioHtml(c, false) + c.respostas.map(function (r) { return comentarioHtml(r, true); }).join('') + '</div>'; }).join('');
      lc.querySelectorAll('[data-del]').forEach(function (b) { b.onclick = function () { confirmar({ emoji: '🗑️', titulo: 'Apagar comentário?', texto: 'Ele some para todas as alunas.', sim: 'Apagar' }, async function () { await CDD.api('comentario_excluir', { body: { id: parseInt(b.getAttribute('data-del'), 10) } }); carregar(); }); }; });
      lc.querySelectorAll('[data-resp]').forEach(function (b) { b.onclick = function () {
        var id = b.getAttribute('data-resp'), box = document.getElementById('rf-' + id);
        if (box.innerHTML) { box.innerHTML = ''; return; }
        box.innerHTML = '<form class="rf"><textarea maxlength="1000" placeholder="Escreva sua resposta" style="min-height:60px;margin-top:6px"></textarea><div class="linha" style="margin-top:6px"><button class="btn pri peq">Enviar</button></div><div class="ra"></div></form>';
        box.querySelector('form').onsubmit = async function (e) {
          e.preventDefault();
          var x = await CDD.api('comentar', { body: { id: a.idaula, texto: box.querySelector('textarea').value, parent: parseInt(id, 10) } });
          if (!x.ok) { box.querySelector('.ra').innerHTML = msg(x.data.mensagem); return; }
          celebrar(x.data.novas); carregar();
        };
      }; });
    }
    async function carregar() { var r = await CDD.api('comentarios', { q: { id: a.idaula } }); if (!r.ok) { el.innerHTML = ''; return; } pintar(r.data); lista(r.data); }
    document.getElementById('cf').onsubmit = async function (e) {
      e.preventDefault();
      var t = document.getElementById('ct');
      var x = await CDD.api('comentar', { body: { id: a.idaula, texto: t.value } });
      document.getElementById('ca').innerHTML = x.ok ? '' : '<span style="color:var(--erro)">' + esc(x.data.mensagem) + '</span>';
      if (x.ok) { t.value = ''; celebrar(x.data.novas); carregar(); }
    };
    carregar();
  }

  async function viewAula(id) {
    var v = tela('aulas');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var r = await CDD.api('aula', { q: { id: id } });
    if (sessaoExpirou(r)) return;
    if (!r.ok) { v.innerHTML = '<div class="msg ' + (r.data.error === 'modulo_bloqueado' ? 'aviso' : 'erro') + '">' + esc(r.data.mensagem || 'Aula não encontrada.') + '</div><a class="btn" href="#/aulas">Voltar</a>'; return; }
    var a = r.data.aula;
    var h = '<a class="voltar" href="#/modulo/' + a.idmodulo + '">‹ ' + esc(curto(tituloLimpo(a.modulo), 34)) + '</a><h2 style="margin-bottom:12px;overflow-wrap:anywhere">' + esc(a.titulo) + '</h2>';
    if (CDD.ytId(a.youtube_id)) h += '<div class="video"><iframe src="' + esc(CDD.ytEmbed(a.youtube_id)) + '" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" title="' + esc(a.titulo) + '"></iframe></div>';
    else if (a.tipo === 'video') h += '<div class="semvideo">O vídeo desta aula ainda não foi publicado.</div>';
    if (a.tipo === 'anamnese') h += '<div class="card hero" style="margin-top:14px"><h3>📝 Anamnese</h3><p style="margin:6px 0 12px">' + (estado.anamneseEnviada ? 'Você já enviou a sua. Dá para atualizar quando algo mudar.' : 'Responda direto aqui no app. Leva uns 5 minutos e fica guardada com segurança.') + '</p><a class="btn pri" href="#/anamnese">' + (estado.anamneseEnviada ? 'Revisar anamnese' : 'Responder agora') + '</a><span class="grande-emoji">📝</span></div>';
    if (a.texto) h += '<div class="card texto" style="margin-top:14px">' + esc(a.texto) + '</div>';
    if (a.ficha.length) h += '<div class="card hero" style="margin-top:14px"><h3>🏋️ Este treino tem ficha</h3><p style="margin:4px 0 12px">Anote cargas e repetições, acompanhe o descanso e veja a sua evolução.</p><a class="btn pri" href="#/treino/' + a.idaula + '">Fazer este treino</a><span class="grande-emoji">💪</span></div>';
    if (a.anexos.length) { h += '<div class="linha" style="margin-top:14px">'; a.anexos.forEach(function (x) { h += '<a class="btn" href="' + esc(x.url) + '" target="_blank" rel="noopener">🔗 ' + esc(x.titulo) + '</a>'; }); h += '</div>'; }
    if (a.ficha.length) h += '<div class="sec"><h3>📋 Ficha</h3></div>' + fichaHtml(a.ficha);
    h += '<div class="nav2"><div>' + (a.anterior ? '<a class="btn" href="#/aula/' + a.anterior + '">‹ Anterior</a>' : '') + '</div>' +
      '<button class="btn ' + (a.concluida ? '' : 'pri') + '" id="bc">' + (a.concluida ? '✅ Concluída' : 'Marcar como concluída') + '</button>' +
      '<div>' + (a.proxima ? '<a class="btn" href="#/aula/' + a.proxima + '">Próxima ›</a>' : '') + '</div></div><div id="inter"></div>';
    v.innerHTML = h; ligarVideos(v);
    document.getElementById('bc').onclick = async function () {
      this.disabled = true;
      var x = await CDD.api('concluir', { body: { id: a.idaula, feito: !a.concluida } });
      estado.modulos = null;
      if (x.ok) celebrar(x.data.novas);
      if (x.ok && !a.concluida && a.proxima) location.hash = '#/aula/' + a.proxima; else viewAula(id);
    };
    blocoInteracao(a, document.getElementById('inter'));
  }

  /* ───────── treino: mesma lógica do treino da Intus ───────── */
  var TECNICA = {
    drop: { nome: 'Drop set', cor: '#e11d48', txt: 'Ao falhar, tire peso na hora e continue sem descanso. Repita até não sair mais.' },
    restpause: { nome: 'Rest-pause', cor: '#c2410c', txt: 'Ao falhar, descanse de 10 a 15 segundos e volte com a mesma carga. Repita 2 ou 3 vezes.' },
    cluster: { nome: 'Cluster', cor: '#7c3aed', txt: 'Faça poucas repetições, pare de 10 a 20 segundos e siga, até completar o total.' },
    backoff: { nome: 'Back-off', cor: '#0891b2', txt: 'Reduza a carga em relação às séries anteriores e faça mais repetições.' },
    falha: { nome: 'Até a falha', cor: '#b45309', txt: 'Vá até não conseguir mais uma repetição com técnica correta.' },
    isometria: { nome: 'Isometria final', cor: '#4f46e5', txt: 'Na última repetição, segure a posição parado pelo tempo que aguentar.' },
    negativa: { nome: 'Negativa lenta', cor: '#0d9488', txt: 'Desça o peso bem devagar, de 4 a 6 segundos em cada repetição.' }
  };
  var METODO = { super: { rot: '⚡ Super Série', dica: 'Faça 1 série de cada exercício, depois descanse e repita.', n: 2 }, triset: { rot: '🔥 Triset', dica: 'Faça 1 série de cada exercício (3), depois descanse e repita.', n: 3 }, circuito: { rot: '🔄 Circuito', dica: 'Complete 1 série de cada exercício em sequência, descanse e repita.', n: 0 } };
  var MOTIVA = ['Cada série conta! Tem certeza que quer parar?', 'Falta pouco! Continue firme 💪', 'Você já começou, termine o que veio fazer!', 'Desistir agora? A você de amanhã agradece se ficar!'];

  /** Primeiro tempo do intervalo, em segundos: 0:30" vira 30, 1:30 vira 90, 45seg vira 45, 1min vira 60. */
  function parseSeg(t) {
    t = String(t || '');
    var m = /(\d+):(\d{2})/.exec(t); if (m) return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
    m = /(\d+)\s*min/i.exec(t); if (m) return parseInt(m[1], 10) * 60;
    m = /(\d+)\s*(s\b|seg)/i.exec(t); if (m) return parseInt(m[1], 10);
    return 60;
  }
  function metaReps(f) { var m = /\d+/.exec(String(f.repeticoes || '')); return /^\d+$/.test(String(f.repeticoes || '').trim()) ? m[0] : ''; }
  function tut(f) { var m = /T\.?U\.?T\.?\s*\(?\s*([\d:]+)/i.exec((f.metodo || '') + ' ' + (f.obs || '')); return m ? m[1] : ''; }

  function pedirTela() {
    if (!('wakeLock' in navigator)) return;
    navigator.wakeLock.request('screen').then(function (s) { WL = s; s.addEventListener('release', function () { if (WL === s) WL = null; }); }).catch(function () {});
  }
  function soltarTela() { try { if (WL) WL.release(); } catch (e) {} WL = null; }
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible' && TR && !TR.jaSalvo && !WL) pedirTela(); });

  function chaveRasc(id) { return 'cdd-rasc-' + id + '-' + hojeISO(); }
  function guardarRasc() {
    if (!TR || TR.jaSalvo) return;
    try { localStorage.setItem(chaveRasc(TR.a.idaula), JSON.stringify({ inicio: TR.inicio, ativo: TR.ativo, comentario: TR.comentario, dados: TR.itens.map(function (i) { return i.tarefa ? null : i.series; }) })); } catch (e) {}
  }
  function limparRasc() { if (TR) { try { localStorage.removeItem(chaveRasc(TR.a.idaula)); } catch (e) {} } }
  function pararTimers() { if (TR) { if (TR.cron) clearInterval(TR.cron); if (TR.rest) clearInterval(TR.rest); TR.cron = TR.rest = null; } }
  function sairDoTreino() { pararTimers(); soltarTela(); TR = null; window.onbeforeunload = null; }

  function serieDoItem(it, ix) {
    var qtd = it.f.qtd_series || 3, meta = metaReps(it.f), iv = parseSeg(it.f.intervalo), out = [];
    for (var i = 0; i < qtd; i++) out.push({ peso: '', reps: '', feita: false, meta: meta, iv: iv, tecnica: (it.f.tecnica && i === qtd - 1) ? it.f.tecnica : '' });
    return out;
  }
  function exFeito(it) { return !it.tarefa && it.series.length > 0 && it.series.every(function (s) { return s.feita; }); }
  function serieAtual(it) { for (var i = 0; i < it.series.length; i++) if (!it.series[i].feita) return i; return it.series.length - 1; }
  function prevDe(it) { return TR.hist[norm(it.exercicio)] || null; }

  async function viewTreino(id) {
    var corrente = TR && TR.a && TR.a.idaula === id;
    if (corrente) { renderTreino(); return; }
    var v = tela('treino');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var r = await CDD.api('treino', { q: { id: id } });
    if (sessaoExpirou(r)) return;
    if (!r.ok) { v.innerHTML = '<div class="msg ' + (r.data.error === 'modulo_bloqueado' ? 'aviso' : 'erro') + '">' + esc(r.data.mensagem || 'Treino não encontrado.') + '</div><a class="btn" href="#/">Voltar</a>'; return; }
    var a = r.data.aula, hoje = r.data.hoje, hist = r.data.historico || {};
    var itens = a.ficha.map(function (f, i) { return { ix: i, exercicio: f.exercicio, f: f, tarefa: f.grupo === 'TAREFA DE CASA', series: [] }; });
    itens.forEach(function (it) { if (!it.tarefa) it.series = serieDoItem(it); });
    var rasc = null; try { rasc = JSON.parse(localStorage.getItem(chaveRasc(a.idaula)) || 'null'); } catch (e) {}
    var jaSalvo = !!hoje;
    TR = { a: a, hist: hist, itens: itens, hoje: hoje, jaSalvo: jaSalvo, ativo: 0, inicio: Date.now(), comentario: '', duracaoSalva: hoje ? hoje.duracao_seg : 0, cron: null, rest: null, restInicio: 0, restAlvo: 0, aberto: -1 };
    function aplicar(dados) {
      itens.forEach(function (it, i) { var d = dados && dados[i]; if (it.tarefa || !d) return; d.forEach(function (s, k) { if (!it.series[k]) it.series.push({ peso: '', reps: '', feita: false, meta: metaReps(it.f), iv: parseSeg(it.f.intervalo), tecnica: '' }); var x = it.series[k]; x.peso = s.peso || ''; x.reps = s.reps || ''; x.feita = !!s.feita; if (s.ts) x.ts = s.ts; }); });
    }
    if (hoje) {   // treino já registrado hoje: abre com o que foi salvo
      var porNome = {}; (hoje.itens || []).forEach(function (s) { porNome[s.exercicio] = s.series || []; });
      itens.forEach(function (it) { if (it.tarefa) return; var s = porNome[it.exercicio] || []; s.forEach(function (x, k) { if (!it.series[k]) it.series.push({ peso: '', reps: '', feita: false, meta: metaReps(it.f), iv: parseSeg(it.f.intervalo), tecnica: '' }); it.series[k].peso = x.peso || ''; it.series[k].reps = x.reps || ''; it.series[k].feita = !!(x.peso || x.reps); }); });
      TR.comentario = hoje.comentario || '';
    } else if (rasc && rasc.inicio && Date.now() - rasc.inicio < 14 * 3600 * 1000) {
      aplicar(rasc.dados); TR.inicio = rasc.inicio; TR.comentario = rasc.comentario || '';
    }
    var primeiroAberto = itens.findIndex(function (it) { return !it.tarefa && !exFeito(it); });
    TR.ativo = primeiroAberto < 0 ? 0 : primeiroAberto; TR.aberto = TR.ativo;
    if (!jaSalvo) { pedirTela(); TR.cron = setInterval(tic, 1000); window.onbeforeunload = function (e) { e.preventDefault(); e.returnValue = ''; return ''; }; }
    renderTreino();
  }

  function tic() {
    var el = document.getElementById('tt'); if (!el || !TR) return;
    el.textContent = mmss(TR.jaSalvo ? TR.duracaoSalva : (Date.now() - TR.inicio) / 1000);
  }
  function ticRest() {
    var el = document.getElementById('rt'); if (!el || !TR) return;
    var s = Math.floor((Date.now() - TR.restInicio) / 1000);
    el.textContent = mmss(s); el.style.color = (TR.restAlvo > 0 && s >= TR.restAlvo) ? '#fbbf24' : '';
  }
  function iniciarRest(alvo) {
    if (TR.rest) clearInterval(TR.rest);
    TR.restInicio = Date.now(); TR.restAlvo = alvo || 0;
    var box = document.getElementById('desc'); if (box) { box.classList.add('on'); document.getElementById('ra').textContent = alvo ? '/ ' + mmss(alvo) : ''; }
    ticRest(); TR.rest = setInterval(ticRest, 1000);
  }
  function pararRest() { if (TR && TR.rest) { clearInterval(TR.rest); TR.rest = null; } var box = document.getElementById('desc'); if (box) box.classList.remove('on'); }

  function renderTreino() {
    if (!TR) return;
    var y = window.scrollY;
    var a = TR.a, itens = TR.itens;
    var feitos = itens.filter(exFeito).length, tot = itens.filter(function (i) { return !i.tarefa; }).length;
    var h = '<div class="timer-bar"><button class="sair" id="sair">✕ Sair do treino</button><div class="tt">⏱ <span id="tt">00:00</span></div><div class="desc" id="desc">⏳ <span id="rt">00:00</span><span class="al" id="ra"></span><button class="lk" id="rx" aria-label="Parar descanso" style="padding:0 0 0 4px">✕</button></div></div>' +
      '<div class="wrap treino-corpo" id="v"><div class="mut" style="margin-bottom:4px">' + esc(tituloLimpo(a.modulo)) + '</div><h2 style="overflow-wrap:anywhere">' + esc(a.titulo) + '</h2>' +
      '<div class="chips" style="margin:10px 0"><span class="chip">💪 ' + feitos + '/' + tot + ' exercícios</span>' + (TR.jaSalvo ? '<span class="chip">✅ já registrado hoje</span>' : '') + '</div>';
    if (CDD.ytId(a.youtube_id)) h += '<button class="btn" id="vexp" style="width:100%">🎥 Ver vídeo explicativo do treino</button>';
    if (a.texto) h += '<div class="card texto suave" style="margin-top:10px">' + esc(a.texto) + '</div>';
    h += '<div class="ex-list-title">📋 Lista de exercícios</div><div class="mut" style="margin:-4px 0 6px;font-size:12px">Toque no exercício para ver os detalhes. Marque ✅ em cada série feita.</div>';
    var i = 0, grupoAtual = null;
    while (i < itens.length) {
      var it = itens[i];
      if (it.tarefa) { i++; continue; }
      var gp = it.f.grupo || '';
      if (gp && gp !== grupoAtual) { grupoAtual = gp; h += '<h3 style="font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:var(--accent2);margin:18px 0 0">' + esc(gp) + '</h3>'; }
      var mt = it.f.metodo_tipo;
      if (mt && METODO[mt]) {
        var fim = i + 1; while (fim < itens.length && !itens[fim].tarefa && itens[fim].f.metodo_tipo === mt) fim++;
        var tam = METODO[mt].n || (fim - i);
        for (var ini = i; ini < fim; ini += tam) {
          var f2 = Math.min(ini + tam, fim);
          if (f2 - ini === 1) { h += linhaEx(itens[ini]); continue; }
          h += '<div class="metodo ' + mt + '"><span class="ml">' + METODO[mt].rot + '</span><div class="mh">' + METODO[mt].dica + '</div>';
          for (var g2 = ini; g2 < f2; g2++) h += linhaEx(itens[g2]);
          h += '</div>';
        }
        i = fim;
      } else { h += linhaEx(it); i++; }
    }
    var tarefas = itens.filter(function (x) { return x.tarefa; });
    if (tarefas.length) {
      h += '<div class="ex-list-title" style="margin-top:20px">🏡 Tarefa de casa</div>' + tarefas.map(function (t) { return '<div class="card suave" style="margin-top:8px"><b>' + esc(t.exercicio) + '</b>' + (t.f.obs ? '<div class="mut">' + esc(t.f.obs) + '</div>' : '') + (t.f.video ? '<div style="margin-top:8px">' + botaoVideo(t.f.video) + '</div>' : '') + '</div>'; }).join('');
    }
    h += '<div class="comment-box card" style="margin-top:18px"><h3>💬 Comentário do treino</h3><p class="mut" style="margin-bottom:6px">Visível para a equipe.</p><textarea id="comentario-treino" placeholder="Como foi o treino hoje? Deixe uma nota..."></textarea></div>' +
      '<div class="fim-box"><button class="btn pri grande" id="fim">' + (TR.jaSalvo ? '✅ Atualizar treino' : '✅ Finalizar treino') + '</button><a class="btn grande" style="margin-top:10px;opacity:.85" href="' + zapAjuda() + '" target="_blank" rel="noopener">💬 Precisa de ajuda? Chama a gente</a></div></div>';
    $app.innerHTML = h; ligarVideos($app);
    document.getElementById('comentario-treino').value = TR.comentario;
    document.getElementById('comentario-treino').oninput = function () { TR.comentario = this.value; guardarRasc(); };
    document.getElementById('sair').onclick = pedirSaida;
    document.getElementById('fim').onclick = confirmarFim;
    var rx = document.getElementById('rx'); if (rx) rx.onclick = pararRest;
    var vx = document.getElementById('vexp'); if (vx) vx.onclick = function () { abrirVideo(a.youtube_id); };
    $app.querySelectorAll('[data-act]').forEach(function (el) { el.onclick = acaoTreino; });
    $app.querySelectorAll('.serie-row input').forEach(function (inp) { inp.onchange = function () { anotar(inp); }; inp.oninput = function () { anotar(inp, true); }; inp.onclick = function (e) { e.stopPropagation(); }; inp.onkeydown = avancar; });
    tic(); if (TR.rest) { var box = document.getElementById('desc'); if (box) { box.classList.add('on'); document.getElementById('ra').textContent = TR.restAlvo ? '/ ' + mmss(TR.restAlvo) : ''; } ticRest(); }
    window.scrollTo(0, y);
  }

  function linhaEx(it) {
    var done = exFeito(it), ativo = it.ix === TR.aberto && !done || it.ix === TR.aberto;
    var cls = done ? 'done' : (ativo ? 'active' : '');
    var f = it.f, prev = prevDe(it), sa = serieAtual(it);
    var presc = [f.series ? f.series + (/^\d+$/.test(f.series) ? ' séries' : '') : '', f.repeticoes ? f.repeticoes + (/^\d+( a \d+)?$/.test(String(f.repeticoes).trim()) ? ' reps' : '') : ''].filter(Boolean).join(' · ');
    var h = '<div class="ex-row ' + cls + '" data-act="exp" data-ix="' + it.ix + '"><div class="ex-num">' + (done ? '✓' : (it.ix + 1)) + '</div><div class="ex-name">' + esc(it.exercicio) + (presc ? '<div class="mut" style="font-weight:600;font-size:12px">' + esc(presc) + '</div>' : '') + '</div>' +
      '<button class="ex-btn" data-act="info" data-ix="' + it.ix + '" aria-label="Informações do exercício">?</button></div>';
    if (ativo) {
      var tutv = tut(f), antes = prev && prev.ultimo && prev.ultimo.series && prev.ultimo.series[sa] && prev.ultimo.series[sa].peso ? prev.ultimo.series[sa].peso + ' kg' : '—';
      h += '<div class="ex-detail open"><div class="dgrid"><div class="l">Série</div><div class="l">Carga anterior</div><div class="l">Intervalo</div><div class="v">' + (sa + 1) + ' de ' + it.series.length + '</div><div class="v">' + esc(antes) + '</div><div class="v">' + esc(f.intervalo || '1:00') + '</div></div>' +
        '<div class="dgrid c2"><div class="l">Repetições</div><div class="l">T.U.T. (e.t.c.t)</div><div class="v">' + esc(f.repeticoes || '—') + '</div><div class="v">' + (tutv ? esc(tutv) : '—') + '</div></div>' +
        (f.metodo && !tutv ? '<div class="mut" style="margin-top:8px;text-align:center">' + esc(f.metodo) + '</div>' : '') + (f.obs ? '<div class="obs">💡 ' + esc(f.obs) + '</div>' : '') +
        '<div style="text-align:right;margin-top:6px"><button class="lk" data-act="hist" data-ix="' + it.ix + '">📈 Ver histórico deste exercício</button></div><div style="margin-top:6px">';
      it.series.forEach(function (s, k) {
        var cur = !s.feita && k === sa, pv = prev && prev.ultimo && prev.ultimo.series ? prev.ultimo.series[k] : null;
        var tec = s.tecnica && TECNICA[s.tecnica] ? TECNICA[s.tecnica] : null;
        h += '<div class="serie-row' + (s.feita ? ' feita' : cur ? ' current' : '') + '">' + (tec ? '<div class="tec" data-act="tec" data-t="' + s.tecnica + '" style="background:' + tec.cor + '22;color:' + tec.cor + ';border:1px solid ' + tec.cor + '66">' + esc(tec.nome) + ' <span style="flex:1;font-weight:600;text-transform:none;opacity:.85;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + esc(tec.txt) + '</span> ?</div>' : '') +
          '<div class="lbl">S' + (k + 1) + (s.meta ? '<small>' + esc(s.meta) + ' reps</small>' : '') + '</div>' +
          '<input type="text" inputmode="decimal" autocomplete="off" data-ix="' + it.ix + '" data-k="' + k + '" data-c="peso" value="' + esc(s.peso) + '" placeholder="' + esc(pv && pv.peso ? pv.peso : 'kg') + '" aria-label="Carga da série ' + (k + 1) + '">' +
          '<input type="text" inputmode="numeric" autocomplete="off" data-ix="' + it.ix + '" data-k="' + k + '" data-c="reps" value="' + esc(s.reps) + '" placeholder="' + esc(pv && pv.reps ? pv.reps : (s.meta || 'reps')) + '" aria-label="Repetições da série ' + (k + 1) + '">' +
          '<button class="check-btn' + (s.feita ? ' done' : '') + '" data-act="chk" data-ix="' + it.ix + '" data-k="' + k + '" aria-label="Marcar série ' + (k + 1) + ' como feita">✓</button>';
        if (cur && pv && (pv.peso || pv.reps)) h += '<div class="quick"><b>Carga</b><button data-act="q" data-ix="' + it.ix + '" data-k="' + k + '" data-c="peso" data-d="-1">−</button><button data-act="q" data-ix="' + it.ix + '" data-k="' + k + '" data-c="peso" data-d="1">+</button><b>Reps</b><button data-act="q" data-ix="' + it.ix + '" data-k="' + k + '" data-c="reps" data-d="-1">−</button><button data-act="q" data-ix="' + it.ix + '" data-k="' + k + '" data-c="reps" data-d="1">+</button></div>';
        h += '</div>';
      });
      h += '</div><div style="text-align:right"><button class="lk" data-act="add" data-ix="' + it.ix + '">+ série</button></div></div>';
    }
    return h;
  }

  function anotar(inp, soDraft) {
    var it = TR.itens[parseInt(inp.getAttribute('data-ix'), 10)], s = it.series[parseInt(inp.getAttribute('data-k'), 10)];
    s[inp.getAttribute('data-c')] = inp.value.replace(',', '.'); guardarRasc();
  }
  function avancar(e) {
    if (e.key !== 'Enter') return; e.preventDefault();
    var campos = [].slice.call($app.querySelectorAll('.serie-row input')), i = campos.indexOf(e.target);
    if (i >= 0 && campos[i + 1]) campos[i + 1].focus(); else e.target.blur();
  }

  function acaoTreino(e) {
    var el = e.currentTarget, act = el.getAttribute('data-act'), ix = parseInt(el.getAttribute('data-ix'), 10);
    if (act !== 'exp') e.stopPropagation();
    if (act === 'exp') { if (e.target.closest && e.target.closest('[data-act="info"]')) return; TR.aberto = TR.aberto === ix ? -1 : ix; renderTreino(); return; }
    if (act === 'info') { abrirInfo(TR.itens[ix]); return; }
    if (act === 'hist') { abrirHistorico(TR.itens[ix]); return; }
    if (act === 'tec') { var t = TECNICA[el.getAttribute('data-t')]; if (t) { var m = abrirModal('<div style="font-size:15px;font-weight:900;color:' + t.cor + ';margin-bottom:8px">' + esc(t.nome) + '</div><p style="line-height:1.6">' + esc(t.txt) + '</p><div class="espaco"></div><button class="btn grande" data-f>Entendi</button>', true); m.querySelector('[data-f]').onclick = m.fechar; } return; }
    var it = TR.itens[ix];
    if (act === 'add') { if (it.series.length < 12) { it.series.push({ peso: '', reps: '', feita: false, meta: metaReps(it.f), iv: parseSeg(it.f.intervalo), tecnica: '' }); guardarRasc(); renderTreino(); } return; }
    var k = parseInt(el.getAttribute('data-k'), 10), s = it.series[k];
    if (act === 'q') {
      var c = el.getAttribute('data-c'), d = parseInt(el.getAttribute('data-d'), 10);
      var pv = prevDe(it), base = parseFloat(s[c] !== '' ? s[c] : (pv && pv.ultimo.series[k] ? pv.ultimo.series[k][c] : 0)) || 0, passo = c === 'peso' ? 2.5 : 1;
      s[c] = String(Math.max(0, Math.round((base + d * passo) * 10) / 10)); guardarRasc(); renderTreino(); return;
    }
    if (act === 'chk') {
      s.feita = !s.feita; vibrar(15);
      if (s.feita) {
        var pv2 = prevDe(it), ant = pv2 && pv2.ultimo.series ? pv2.ultimo.series[k] : null;   // quem repete a carga só marca ✅: grava o que estava sugerido
        if (s.peso === '' && ant && ant.peso) s.peso = String(ant.peso);
        if (s.reps === '') { if (ant && ant.reps) s.reps = String(ant.reps); else if (s.meta) s.reps = s.meta; }
        s.ts = Date.now(); iniciarRest(s.iv);
        if (exFeito(it)) {
          var prox = TR.itens.findIndex(function (x, j) { return j > ix && !x.tarefa && !exFeito(x); });
          TR.aberto = prox >= 0 ? prox : -1;
          guardarRasc(); renderTreino();
          if (prox >= 0) setTimeout(function () { var r = $app.querySelector('[data-act="exp"][data-ix="' + prox + '"]'); if (r) r.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 100);
          return;
        }
      } else { delete s.ts; pararRest(); }
      guardarRasc(); renderTreino();
    }
  }

  function abrirInfo(it) {
    var f = it.f, vid = CDD.ytId(f.video) ? f.video : '';
    var m = abrirModal('<h3>📖 ' + esc(it.exercicio) + '</h3>' + (vid ? '<div class="video" style="margin-top:10px"><iframe src="' + esc(CDD.ytEmbed(vid)) + '" allow="accelerometer; autoplay; encrypted-media; picture-in-picture" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe></div>' : (f.video ? '<div style="margin-top:10px"><a class="btn" href="' + esc(f.video) + '" target="_blank" rel="noopener">▶ Ver vídeo</a></div>' : '<p class="mut" style="margin:10px 0">Vídeo deste exercício em breve.</p>')) +
      (f.descricao ? '<h3 style="margin-top:14px">📝 Como executar</h3><p class="texto" style="margin-top:4px">' + esc(f.descricao) + '</p>' : '') + (f.obs ? '<div class="obs">💡 ' + esc(f.obs) + '</div>' : '') + (f.metodo ? '<div class="mut" style="margin-top:8px">Método: ' + esc(f.metodo) + '</div>' : '') + '<div class="espaco"></div><button class="btn grande" data-f>Voltar ao treino</button>');
    m.querySelector('[data-f]').onclick = m.fechar;
  }
  function abrirHistorico(it) {
    var h = prevDe(it), cor = '';
    var corpo = !h ? '<p class="mut" style="margin:10px 0">Ainda não há treinos anteriores com este exercício. A sua primeira anotação vira a referência.</p>' :
      (h.max.peso ? '<div class="chips" style="margin:10px 0"><span class="chip">🏆 Recorde: ' + num(h.max.peso) + ' kg' + (h.max.reps ? ' x ' + h.max.reps : '') + '</span><span class="chip">📅 ' + h.sessoes + ' treino' + (h.sessoes === 1 ? '' : 's') + '</span></div>' : '') +
      (h.lista || []).map(function (s) { return '<div class="rec"><b>' + esc(dm(s.data)) + '</b><span>' + (s.series || []).filter(function (x) { return x.peso || x.reps; }).map(function (x) { return (x.peso ? num(x.peso) + ' kg' : '') + (x.peso && x.reps ? ' x ' : '') + (x.reps || ''); }).join(' · ') + '</span></div>'; }).join('');
    var m = abrirModal('<h3>📈 ' + esc(it.exercicio) + '</h3>' + corpo + '<div class="espaco"></div><button class="btn grande" data-f>Fechar</button>');
    m.querySelector('[data-f]').onclick = m.fechar;
  }

  function pedirSaida() {
    var m = confirmar({ emoji: '🏃‍♀️', titulo: 'Sair do treino?', texto: MOTIVA[Math.floor(Math.random() * MOTIVA.length)] + ' Se sair, o treino em andamento é descartado.', nao: 'Continuar treino', sim: 'Sair mesmo assim' }, function () { limparRasc(); sairDoTreino(); location.hash = '#/'; });
    return m;
  }
  function confirmarFim() {
    var dur = Math.round((Date.now() - TR.inicio) / 1000); if (TR.jaSalvo) dur = TR.duracaoSalva;
    var feitos = TR.itens.filter(function (i) { return !i.tarefa && i.series.some(function (s) { return s.peso || s.reps; }); }).length, tot = TR.itens.filter(function (i) { return !i.tarefa; }).length;
    confirmar({ emoji: '🏋️', titulo: 'Finalizar treino?', html: '⏱️ <b>' + Math.max(1, Math.round(dur / 60)) + ' min</b> de treino · 💪 <b>' + feitos + '/' + tot + '</b> exercício' + (tot === 1 ? '' : 's') + ' registrado' + (tot === 1 ? '' : 's'), nao: 'Continuar treino', sim: 'Confirmar ✅' }, function () {
      if (!TR.jaSalvo && dur < 300) {
        confirmar({ emoji: '⏱️', titulo: 'Treino muito rápido', html: 'Seu treino durou <b>' + mmss(dur) + '</b>. Treinos com menos de 5 minutos <b>não contam</b> nos pontos do ranking. Quer registrar mesmo assim?', nao: 'Voltar ao treino', sim: 'Registrar mesmo assim' }, function () { pedirEsforco(dur); });
        return;
      }
      pedirEsforco(dur);
    });
  }
  function pedirEsforco(dur) {
    var esf = TR.hoje && TR.hoje.esforco ? parseInt(TR.hoje.esforco, 10) : 0, rot = ['', 'Leve 😌', 'Moderado 🙂', 'Forte 💪', 'Muito forte 🔥', 'No limite 🥵'];
    var m = abrirModal('<h3>Como foi o treino?</h3><div class="linha esf" style="margin-top:10px">' + [1, 2, 3, 4, 5].map(function (n) { return '<button class="btn" data-e="' + n + '">' + n + '</button>'; }).join('') + '</div><div class="mut" id="esfr" style="margin-top:8px;min-height:20px"></div><div id="av"></div><div class="espaco"></div><button class="btn pri grande" id="sv">Salvar treino</button>');
    m.setAttribute('data-fixo', '1');
    function pinta() { m.querySelectorAll('[data-e]').forEach(function (b) { b.classList.toggle('pri', parseInt(b.getAttribute('data-e'), 10) === esf); }); m.querySelector('#esfr').textContent = esf ? 'Esforço: ' + rot[esf] : 'Esforço percebido, de 1 (leve) a 5 (no limite). Opcional.'; }
    pinta();
    m.querySelectorAll('[data-e]').forEach(function (b) { b.onclick = function () { esf = parseInt(b.getAttribute('data-e'), 10); pinta(); }; });
    m.querySelector('#sv').onclick = async function () {
      this.disabled = true;
      var itens = TR.itens.filter(function (i) { return !i.tarefa; }).map(function (i) { return { exercicio: i.exercicio, series: i.series.map(function (s) { return { peso: s.peso, reps: s.reps }; }) }; });
      var x = await CDD.api('treino_salvar', { body: { idaula: TR.a.idaula, itens: itens, duracao_seg: dur, esforco: esf || null, comentario: TR.comentario, data: hojeISO() } });
      this.disabled = false;
      if (!x.ok) { m.querySelector('#av').innerHTML = msg(x.data.mensagem); return; }
      m.remove(); limparRasc(); var a = TR.a; sairDoTreino(); estado.modulos = null; resultadoTreino(a, x.data, dur);
    };
  }
  function resultadoTreino(a, d, dur) {
    var v = tela('treino'), r = d.resumo;
    v.innerHTML = '<div class="card hero" style="text-align:center"><div class="grande-ok">✓</div><h2>Treino registrado! 🎉</h2><div class="mut">' + esc(a.titulo) + '</div>' +
      '<div class="grid g4" style="margin-top:14px;text-align:left"><div class="card suave"><b class="n">' + r.exercicios + '</b><div class="mut">exercícios</div></div><div class="card suave"><b class="n">' + r.series + '</b><div class="mut">séries</div></div>' +
      '<div class="card suave"><b class="n">' + (r.volume_kg ? r.volume_kg.toLocaleString('pt-BR') : '0') + '</b><div class="mut">kg de volume</div></div><div class="card suave"><b class="n">' + mmss(dur) + '</b><div class="mut">de treino</div></div></div>' +
      '<div style="margin-top:12px;font-weight:700">' + (d.conta_ranking ? '⭐ Este treino conta pontos no ranking' : 'ℹ️ Treino abaixo de 5 minutos: não conta pontos') + '</div></div>' +
      (d.recordes.length ? '<div class="card" style="margin-top:12px"><h3 style="margin-bottom:8px">🏆 Novos recordes</h3>' + d.recordes.map(function (x) { return '<div class="rec"><b>' + esc(x.exercicio) + '</b><span>' + num(x.peso) + ' kg' + (x.reps ? ' x ' + x.reps : '') + ' <span class="mut">(antes ' + num(x.anterior) + ')</span></span></div>'; }).join('') + '</div>' : '') +
      '<div class="linha" style="margin-top:14px"><a class="btn pri" href="#/jornada">🗺️ Ver minha jornada</a><a class="btn" href="#/ranking">🏆 Ranking</a><a class="btn" href="#/">Início</a></div>';
    vibrar([40, 40, 60]); celebrar(d.novas);
  }

  /* ───────── cardio ───────── */
  var CARDIO_RASC = { ativ: 'caminhada', min: 30, esf: 3, dia: 'hoje' };
  async function viewCardio() {
    var v = tela('inicio');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var r = await CDD.api('cardio');
    if (sessaoExpirou(r)) return;
    if (!r.ok) { v.innerHTML = msg(r.data.mensagem); return; }
    var d = r.data, S = CARDIO_RASC, ontem = new Date(); ontem.setDate(ontem.getDate() - 1);
    var ISO = function (x) { return x.getFullYear() + '-' + ('0' + (x.getMonth() + 1)).slice(-2) + '-' + ('0' + x.getDate()).slice(-2); };
    var h = '<a class="voltar" href="#/">‹ Início</a><h2 style="margin-bottom:10px">🏃‍♀️ Cardio</h2>' +
      '<div class="card hero" style="display:flex;gap:14px;align-items:center"><div><div class="mut" style="font-weight:800;text-transform:uppercase;letter-spacing:.1em;font-size:11px">Nesta semana</div><h2 style="margin:2px 0">' + d.semana.minutos + ' min</h2><div class="mut">' + d.semana.sessoes + (d.semana.sessoes === 1 ? ' cardio' : ' cardios') + ' · ⭐ ' + num(d.semana.pontos) + ' pts</div></div><span class="grande-emoji">🏃‍♀️</span></div>' +
      '<div class="sec"><h3>➕ Registrar cardio</h3></div><div class="card"><div class="mut" style="margin-bottom:8px">Qual atividade?</div><div class="atv">' +
      d.atividades.map(function (a) { return '<button type="button" class="' + (S.ativ === a.chave ? 'on' : '') + '" data-at="' + a.chave + '"><span>' + a.emoji + '</span>' + esc(a.nome) + '</button>'; }).join('') + '</div>' +
      '<div class="mut" style="margin:14px 0 6px">Quanto tempo? (minutos)</div><div class="ops" id="mins">' + [15, 20, 30, 45, 60].map(function (m) { return '<button type="button" data-m="' + m + '" class="' + (S.min === m ? 'on' : '') + '">' + m + '</button>'; }).join('') + '</div>' +
      '<input id="mn" inputmode="numeric" placeholder="Outro valor" style="margin-top:8px" value="' + ([15, 20, 30, 45, 60].indexOf(S.min) < 0 ? S.min : '') + '">' +
      '<div class="mut" style="margin:14px 0 6px">Como foi o esforço?</div><div class="ops" id="esf">' + [['1', '😌 Leve'], ['2', '🙂 Moderado'], ['3', '💪 Forte'], ['4', '🔥 Muito forte'], ['5', '🥵 No limite']].map(function (x) { return '<button type="button" data-e="' + x[0] + '" class="' + (String(S.esf) === x[0] ? 'on' : '') + '">' + x[1] + '</button>'; }).join('') + '</div>' +
      '<div class="mut" style="margin:14px 0 6px">Quando?</div><div class="ops" id="dia"><button type="button" data-d="hoje" class="' + (S.dia === 'hoje' ? 'on' : '') + '">Hoje</button><button type="button" data-d="ontem" class="' + (S.dia === 'ontem' ? 'on' : '') + '">Ontem</button></div>' +
      '<div id="av"></div><div class="espaco"></div><button class="btn pri grande" id="sv">✅ Registrar cardio</button><p class="mut" style="margin-top:10px;font-size:12px">' + esc(d.regra) + '</p></div>';
    h += '<div class="sec"><h3>🕒 Últimos cardios</h3></div>';
    if (!d.cardios.length) h += '<div class="card vazio">Nenhum cardio ainda. O primeiro vale uma conquista! 🏅</div>';
    else h += '<div class="card">' + d.cardios.slice(0, 15).map(function (c) {
      return '<div class="rec" style="align-items:center"><span><b>' + c.emoji + ' ' + esc(c.nome) + '</b><div class="mut" style="font-size:12px">' + esc(dm(c.data)) + ' · ' + c.minutos + ' min · esforço ' + c.esforco + '</div></span><span style="text-align:right">' + (c.pontos ? '⭐ ' + num(c.pontos) : '<span class="mut">sem pontos</span>') + '<div><button class="lk" data-del="' + c.id + '">Apagar</button></div></span></div>'; }).join('') + '</div>';
    v.innerHTML = h;
    v.querySelectorAll('[data-at]').forEach(function (b) { b.onclick = function () { S.ativ = b.getAttribute('data-at'); v.querySelectorAll('[data-at]').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
    v.querySelectorAll('#mins [data-m]').forEach(function (b) { b.onclick = function () { S.min = parseInt(b.getAttribute('data-m'), 10); document.getElementById('mn').value = ''; v.querySelectorAll('#mins button').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
    document.getElementById('mn').oninput = function () { var n = parseInt(this.value, 10); if (n > 0) { S.min = n; v.querySelectorAll('#mins button').forEach(function (x) { x.classList.remove('on'); }); } };
    v.querySelectorAll('#esf [data-e]').forEach(function (b) { b.onclick = function () { S.esf = parseInt(b.getAttribute('data-e'), 10); v.querySelectorAll('#esf button').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
    v.querySelectorAll('#dia [data-d]').forEach(function (b) { b.onclick = function () { S.dia = b.getAttribute('data-d'); v.querySelectorAll('#dia button').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });
    v.querySelectorAll('[data-del]').forEach(function (b) { b.onclick = function () { confirmar({ emoji: '🗑️', titulo: 'Apagar este cardio?', texto: 'Os pontos dele saem do ranking.', sim: 'Apagar' }, async function () { await CDD.api('cardio_excluir', { body: { id: parseInt(b.getAttribute('data-del'), 10) } }); viewCardio(); }); }; });
    document.getElementById('sv').onclick = async function () {
      this.disabled = true;
      var x = await CDD.api('cardio_salvar', { body: { atividade: S.ativ, minutos: S.min, esforco: S.esf, data: S.dia === 'ontem' ? ISO(ontem) : hojeISO() } });
      this.disabled = false;
      if (!x.ok) { document.getElementById('av').innerHTML = msg(x.data.mensagem); return; }
      vibrar(30); estado.modulos = null;
      toast(x.data.conta_ranking ? '⭐' : '✅', x.data.conta_ranking ? '+' + num(x.data.pontos) + ' pontos no ranking' : 'Cardio registrado', x.data.conta_ranking ? '' : 'Abaixo de 10 minutos ou acima de 3 no dia: sem pontos.');
      celebrar(x.data.novas); viewCardio();
    };
  }

  /* ───────── nutrição ───────── */
  async function viewNutri() {
    var v = tela('inicio');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var r = await CDD.api('nutri');
    if (sessaoExpirou(r)) return;
    if (!r.ok) { v.innerHTML = msg(r.data.mensagem); return; }
    var n = r.data.nutri, pct = Math.min(100, Math.round(n.agua_ml * 100 / n.meta_ml));
    var alim = ((estado.modulos || []).filter(function (m) { return /aliment/i.test(m.titulo) && m.liberado; })[0]) || null;
    var h = '<a class="voltar" href="#/">‹ Início</a><h2 style="margin-bottom:10px">🥗 Nutrição</h2>' +
      '<div class="card hero" style="display:flex;gap:16px;align-items:center"><div class="anel" style="--p:' + pct + ';--s:96px"><b style="font-size:18px">💧</b></div><div style="min-width:0"><div class="mut" style="font-weight:800;text-transform:uppercase;letter-spacing:.1em;font-size:11px">Água de hoje</div><h2 style="margin:2px 0" id="ag">' + (n.agua_ml / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' L</h2><div class="mut">meta de ' + (n.meta_ml / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 2 }) + ' L' + (pct >= 100 ? ' · ✅ batida!' : '') + '</div></div></div>' +
      '<div class="linha" style="margin-top:10px"><button class="btn grande" data-a="-250" style="flex:1">− copo</button><button class="btn pri grande" data-a="250" style="flex:2">💧 + 1 copo (250 ml)</button></div>' +
      '<div class="sec"><h3>🍽️ Refeições de hoje</h3></div><div class="card" id="refs">' + n.refeicoes.map(function (x) {
        return '<div class="ref"><div class="ref-n"><span class="em">' + x.emoji + '</span><b>' + esc(x.nome) + '</b></div><div class="ops" data-r="' + x.chave + '">' +
          [['feito', '✅ Fiz'], ['parcial', '🟡 Em parte'], ['fora', '❌ Pulei']].map(function (o) { return '<button type="button" data-s="' + o[0] + '" class="' + (x.status === o[0] ? 'on' : '') + '">' + o[1] + '</button>'; }).join('') + '</div></div>'; }).join('') +
        '<div class="mut" style="margin-top:8px;font-size:12px">Toque de novo na opção marcada para limpar.</div></div>' +
      '<div class="card" style="margin-top:12px"><div class="linha" style="justify-content:space-between"><b>📊 Últimos 7 dias</b><span class="mut">' + (n.aderencia_semana !== null ? 'aderência média ' + n.aderencia_semana + '%' : 'sem registros') + '</span></div><div class="sem7">' +
      n.semana.map(function (d) { var di = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'][new Date(d.data + 'T12:00:00').getDay()];
        return '<div class="d7"><div class="bar"><i style="height:' + (d.aderencia === null ? 0 : d.aderencia) + '%"></i></div><span class="gota' + (d.meta_batida ? ' on' : '') + '">💧</span><small>' + di + '</small></div>'; }).join('') + '</div><div class="mut" style="font-size:12px">Barras: refeições do dia. 💧 aceso: meta de água batida.</div></div>';
    if (alim) h += '<a class="banner" href="#/modulo/' + alim.idmodulo + '" style="margin-top:12px;border-left-color:var(--ok)"><span class="em">' + esc(alim.emoji || '🥗') + '</span><div><b>' + esc(tituloLimpo(alim.titulo)) + '</b><span class="mut">Cardápios e aulas de alimentação do programa.</span></div></a>';
    h += '<p class="mut" style="margin-top:14px;font-size:12px">A meta de água usa 35 ml por kg do peso da sua anamnese. Este diário não substitui o acompanhamento de uma nutricionista.</p>';
    v.innerHTML = h;
    v.querySelectorAll('[data-a]').forEach(function (b) { b.onclick = async function () { var x = await CDD.api('agua', { body: { delta: parseInt(b.getAttribute('data-a'), 10) } }); if (x.ok) { vibrar(15); celebrar(x.data.novas); viewNutri(); } }; });
    v.querySelectorAll('[data-r] [data-s]').forEach(function (b) { b.onclick = async function () {
      var ch = b.parentNode.getAttribute('data-r'), s = b.getAttribute('data-s'), ja = b.classList.contains('on');
      var x = await CDD.api('refeicao', { body: { refeicao: ch, status: ja ? '' : s } });
      if (x.ok) { vibrar(15); celebrar(x.data.novas); viewNutri(); } }; });
  }

  /** Cartões de hábitos na Home: preenchidos depois, sem travar a tela. */
  async function habitosHome(el) {
    var a = await CDD.api('cardio'), b = await CDD.api('nutri');
    if (!el.isConnected) return;
    var h = '';
    if (a.ok) h += '<a class="card hab" href="#/cardio"><span class="em">🏃‍♀️</span><b>Cardio</b><span class="mut">' + a.data.semana.minutos + ' min na semana</span></a>';
    if (b.ok) { var n = b.data.nutri, p = Math.min(100, Math.round(n.agua_ml * 100 / n.meta_ml));
      h += '<a class="card hab" href="#/nutri"><span class="em">🥗</span><b>Nutrição</b><span class="mut">💧 ' + p + '% da água' + (n.aderencia_hoje !== null ? ' · ' + n.aderencia_hoje + '% refeições' : '') + '</span></a>'; }
    el.innerHTML = h;
  }

  /* ───────── jornada ───────── */
  async function viewJornada(aba) {
    var v = tela('jornada');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    var r = await CDD.api('jornada');
    if (sessaoExpirou(r)) return;
    if (!r.ok) { v.innerHTML = '<div class="msg erro">' + esc(r.data.mensagem || 'Não foi possível carregar a jornada.') + '</div>'; return; }
    var j = r.data.jornada, ms = estado.modulos || await carregarHome() || [];
    var info = {}; ms.forEach(function (m) { info[m.idmodulo] = m; });
    aba = aba || 'trilha';
    var main = j.modulos.filter(function (m) { return m.grupo !== 'bonus'; });
    var tot = 0, fe = 0, concl = 0; main.forEach(function (m) { if (m.liberado) { tot += m.total; fe += m.feitas; } if (m.liberado && m.total && m.feitas === m.total) concl++; });
    var pct = tot ? Math.round(fe * 100 / tot) : 0;
    var h = '<div class="card hero" style="display:flex;gap:16px;align-items:center"><div class="anel" style="--p:' + pct + '"><b>' + pct + '%</b></div><div style="min-width:0"><div class="mut" style="font-weight:800;text-transform:uppercase;letter-spacing:.1em;font-size:11px">Minha jornada</div><h2 style="margin:2px 0">Dia ' + j.dia_do_programa + '</h2><div class="mut">' + concl + ' de ' + main.length + ' módulos concluídos</div></div><span class="grande-emoji">🗺️</span></div>' +
      '<div class="chips" style="margin-top:12px"><span class="chip">🏋️ ' + j.treinos_total + ' treinos</span><span class="chip">🔥 ' + j.treinos_semana + ' na semana</span><span class="chip">📆 ' + j.sequencia_semanas + (j.sequencia_semanas === 1 ? ' semana seguida' : ' semanas seguidas') + '</span></div>' +
      '<div class="abas"><button data-a="trilha" class="' + (aba === 'trilha' ? 'on' : '') + '">🗺️ Trilha</button><button data-a="evolucao" class="' + (aba === 'evolucao' ? 'on' : '') + '">📈 Evolução</button><button data-a="conquistas" class="' + (aba === 'conquistas' ? 'on' : '') + '">🏅 Conquistas</button></div><div id="aba"></div>';
    v.innerHTML = h;
    v.querySelectorAll('[data-a]').forEach(function (b) { b.onclick = function () { history.replaceState(null, '', '#/jornada' + (b.getAttribute('data-a') === 'trilha' ? '' : '/' + b.getAttribute('data-a'))); viewJornada(b.getAttribute('data-a')); }; });
    var box = document.getElementById('aba');
    if (aba === 'trilha') {
      var atual = -1; main.forEach(function (m, i) { if (atual < 0 && m.liberado && m.total && m.feitas < m.total) atual = i; });
      box.innerHTML = '<ul class="trilha">' + main.map(function (m, i) {
        var mi = info[m.idmodulo] || m, p = m.total ? Math.round(m.feitas * 100 / m.total) : 0, st, cls;
        if (!m.liberado) { var n = m.libera_em ? diasAte(m.libera_em) : null; st = '🔒 Libera em ' + dm(m.libera_em) + (n > 0 ? ' (em ' + n + (n === 1 ? ' dia)' : ' dias)') : ''); cls = 'trav'; }
        else if (m.total && m.feitas === m.total) { st = '✅ Concluído'; cls = 'ok'; }
        else if (i === atual) { st = m.feitas + ' de ' + m.total + ' aulas'; cls = 'and'; }
        else if (m.feitas) { st = m.feitas + ' de ' + m.total + ' aulas'; cls = 'lib'; } else { st = '🔓 Liberado'; cls = 'lib'; }
        return '<li class="' + cls + '" style="--p:' + p + '%"><span class="no">' + (cls === 'ok' ? '✓' : esc(mi.emoji || '🎬')) + '</span><div class="tc">' + (m.liberado ? '<a href="#/modulo/' + m.idmodulo + '">' + esc(tituloLimpo(m.titulo)) + '</a>' : '<b>' + esc(tituloLimpo(m.titulo)) + '</b>') + (cls === 'and' ? '<span class="qui">Você está aqui</span>' : '') + '<div class="mut">' + st + '</div>' + (m.liberado && m.total && cls !== 'ok' ? '<div class="barra"><i style="width:' + p + '%"></i></div>' : '') + '</div></li>';
      }).join('') + '</ul>';
    } else if (aba === 'evolucao') {
      var dias = ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'], hoje = hojeISO();
      var ev = '<div class="card"><h3 style="margin-bottom:10px">📅 Constância nas últimas 8 semanas</h3><div class="cal"><div class="cal-d">' + dias.map(function (d) { return '<span>' + d + '</span>'; }).join('') + '</div>';
      for (var w = 0; w < 8; w++) { ev += '<div class="cal-s">'; for (var d = 0; d < 7; d++) { var c = j.calendario[w * 7 + d]; ev += '<span class="cel n' + Math.min(c.n, 3) + (c.futuro ? ' fut' : '') + (c.d === hoje ? ' hoje' : '') + '" title="' + esc(dm(c.d)) + (c.n ? ': ' + c.n + (c.n > 1 ? ' treinos' : ' treino') : '') + '"></span>'; } ev += '</div>'; }
      ev += '</div></div>';
      if (j.evolucao.length) {
        ev += '<div class="card" style="margin-top:12px"><h3 style="margin-bottom:6px">📈 Evolução das cargas</h3>' + j.evolucao.map(function (e) {
          var dif = Math.round((e.atual - e.primeira) * 10) / 10, pc = e.maxima ? Math.round(e.atual * 100 / e.maxima) : 0;
          return '<div class="evo"><div class="linha" style="justify-content:space-between;gap:8px"><b style="min-width:0;overflow-wrap:anywhere">' + esc(e.exercicio) + '</b><span class="' + (dif > 0 ? 'up' : dif < 0 ? 'dn' : 'mut') + '">' + (dif > 0 ? '+' : '') + num(dif) + ' kg</span></div><div class="mut">' + num(e.primeira) + ' kg → ' + num(e.atual) + ' kg · recorde ' + num(e.maxima) + ' kg · ' + e.sessoes + (e.sessoes === 1 ? ' treino' : ' treinos') + '</div><div class="barra"><i style="width:' + pc + '%"></i></div></div>';
        }).join('') + '</div>';
      }
      if (j.recentes.length) {
        ev += '<div class="card" style="margin-top:12px"><h3 style="margin-bottom:4px">🕒 Treinos recentes</h3>' + j.recentes.map(function (t) {
          return '<a class="vid" href="#/treino/' + t.idaula + '" style="border:0;border-top:1px solid var(--line);border-radius:0;margin:0;padding:10px 0"><span class="tx"><b>' + esc(t.titulo) + '</b><span class="est">' + esc(dm(t.data)) + ' · ' + t.series + ' séries' + (t.volume_kg ? ' · ' + t.volume_kg.toLocaleString('pt-BR') + ' kg' : '') + (t.duracao_seg ? ' · ' + mmss(t.duracao_seg) : '') + (t.esforco ? ' · esforço ' + t.esforco : '') + '</span></span></a>';
        }).join('') + '</div>';
      } else ev += '<div class="card vazio" style="margin-top:12px">Quando você anotar o primeiro treino, a sua evolução aparece aqui. 💪</div>';
      box.innerHTML = ev;
    } else {
      box.innerHTML = '<div class="vazio">Carregando conquistas...</div>';
      var q = await CDD.api('conquistas');
      if (!q.ok) { box.innerHTML = msg(q.data.mensagem); return; }
      box.innerHTML = '<div class="mut" style="margin-bottom:10px">' + q.data.ganhas + ' de ' + q.data.total + ' conquistas</div><div class="conq-g">' + q.data.conquistas.map(function (c) {
        return '<div class="conq ' + (c.conquistada ? 'on' : 'off') + '"><span class="em">' + c.emoji + '</span><b>' + esc(c.titulo) + '</b><small>' + (c.conquistada ? '✓ ' + esc(dm(c.em)) : c.atual + '/' + c.meta) + '</small></div>'; }).join('') + '</div>';
    }
  }

  /* ───────── ranking ───────── */
  async function viewRanking(periodo) {
    var v = tela('ranking');
    if (!estado.acesso) { v.innerHTML = semAcesso(); return; }
    periodo = periodo || 'semana';
    var r = await CDD.api('ranking', { q: { periodo: periodo } });
    if (sessaoExpirou(r)) return;
    if (!r.ok) { v.innerHTML = msg(r.data.mensagem); return; }
    var k = r.data.ranking, top = k.top, med = ['🥇', '🥈', '🥉'];
    var h = '<h2 style="margin:2px 0 4px">🏆 Ranking CDD</h2><p class="mut">Só entre as alunas do Código da Definição.</p>' +
      '<div class="abas"><button data-p="semana" class="' + (periodo === 'semana' ? 'on' : '') + '">Semana</button><button data-p="mes" class="' + (periodo === 'mes' ? 'on' : '') + '">Mês</button><button data-p="geral" class="' + (periodo === 'geral' ? 'on' : '') + '">Geral</button></div>';
    h += '<div class="card hero" style="display:flex;justify-content:space-between;align-items:center"><div><div class="mut" style="font-weight:800;text-transform:uppercase;letter-spacing:.1em;font-size:11px">Sua posição</div><h2 style="font-size:30px">' + (k.eu.pos ? k.eu.pos + 'º' : '—') + '</h2><div class="mut">' + k.participantes + (k.participantes === 1 ? ' aluna pontuando' : ' alunas pontuando') + '</div></div><div style="text-align:right"><b class="n">' + k.eu.pontos + '</b><div class="mut">pontos</div></div><span class="grande-emoji">🏆</span></div>';
    if (!top.length) h += '<div class="card vazio" style="margin-top:12px">Ninguém pontuou neste período ainda. Registre um treino e abra o ranking! 🔥</div>';
    else {
      var p3 = top.slice(0, 3);
      if (p3.length >= 3) { var ordem = [p3[1], p3[0], p3[2]], med3 = [med[1], med[0], med[2]];
        h += '<div class="podio">' + ordem.map(function (x, i) { return '<div class="p' + (i === 1 ? ' p1' : '') + '"><div class="m">' + med3[i] + '</div><b>' + esc(x.nome) + '</b><span>' + x.pontos + ' pts</span></div>'; }).join('') + '</div>'; }
      h += top.slice(p3.length >= 3 ? 3 : 0).map(function (x) { return '<div class="rk' + (x.eu ? ' eu' : '') + '"><span class="pos">' + (x.pos <= 3 ? med[x.pos - 1] : x.pos + 'º') + '</span><span class="nm">' + esc(x.nome) + '</span><span class="pt">' + x.pontos + ' pts</span></div>'; }).join('');
    }
    h += '<div class="card suave" style="margin-top:14px"><b>Como pontua</b><p class="mut" style="margin-top:4px">' + esc(k.regras) + '</p></div>' +
      '<label class="chk" style="margin-top:14px"><input type="checkbox" id="vis"' + (k.visivel ? ' checked' : '') + '><span>Aparecer no ranking para as outras alunas (só o seu primeiro nome e a inicial do sobrenome)</span></label>';
    v.innerHTML = h;
    v.querySelectorAll('[data-p]').forEach(function (b) { b.onclick = function () { history.replaceState(null, '', '#/ranking' + (b.getAttribute('data-p') === 'semana' ? '' : '/' + b.getAttribute('data-p'))); viewRanking(b.getAttribute('data-p')); }; });
    document.getElementById('vis').onchange = async function () { await CDD.api('perfil_ranking', { body: { visivel: this.checked } }); toast('👀', this.checked ? 'Você aparece no ranking' : 'Você saiu do ranking', 'Dá para mudar quando quiser.'); };
  }

  /* ───────── anamnese ───────── */
  async function viewAnamnese() {
    var v = tela('inicio', null, true);
    var r = await CDD.api('anamnese');
    if (sessaoExpirou(r)) return;
    if (!r.ok) { v.innerHTML = msg(r.data.mensagem); return; }
    var etapas = r.data.etapas, resp = r.data.respostas || {}, enviado = r.data.enviado_em, passo = 0, sujo = false, timer = null;
    var rasc = null; try { rasc = JSON.parse(localStorage.getItem('cdd-anamnese-' + estado.eu.idaluna) || 'null'); } catch (e) {}
    if (rasc && (!r.data.atualizado_em || rasc._em > String(r.data.atualizado_em).replace(' ', 'T'))) resp = Object.assign({}, resp, rasc.r || {});
    function guardarLocal() { try { localStorage.setItem('cdd-anamnese-' + estado.eu.idaluna, JSON.stringify({ r: resp, _em: new Date().toISOString() })); } catch (e) {} }
    async function salvarServidor(enviar) {
      var x = await CDD.api('anamnese_salvar', { body: { respostas: resp, enviar: !!enviar } });
      if (x.ok) { sujo = false; try { localStorage.removeItem('cdd-anamnese-' + estado.eu.idaluna); } catch (e) {} }
      return x;
    }
    window.onbeforeunload = function (e) { if (sujo) { e.preventDefault(); e.returnValue = ''; return ''; } };
    function desenhar() {
      var e = etapas[passo];
      var h = '<button class="voltar" id="sai">‹ Sair</button><h2>' + e.emoji + ' ' + esc(e.titulo) + '</h2><div class="mut">Etapa ' + (passo + 1) + ' de ' + etapas.length + (enviado ? ' · já enviada, você pode atualizar' : '') + '</div><div class="passos">' + etapas.map(function (_, i) { return '<i class="' + (i <= passo ? 'on' : '') + '"></i>'; }).join('') + '</div>';
      e.perguntas.forEach(function (p) {
        h += '<div class="pergunta"><b>' + esc(p.label) + (p.obrig ? ' <span style="color:var(--accent2)">*</span>' : '') + '</b>' + (p.dica ? '<div class="dica">' + esc(p.dica) + '</div>' : '');
        var val = resp[p.id] || '';
        if (p.tipo === 'texto') h += '<textarea data-id="' + p.id + '" rows="3" maxlength="2000">' + esc(val) + '</textarea>';
        else if (p.tipo === 'numero') h += '<div class="un"><input data-id="' + p.id + '" inputmode="decimal" value="' + esc(val) + '" placeholder="0"><span>' + esc(p.un) + '</span></div>';
        else h += '<div class="ops" data-op="' + p.id + '">' + p.opcoes.map(function (o) { return '<button type="button" class="' + (val === o ? 'on' : '') + '" data-v="' + esc(o) + '">' + esc(o) + '</button>'; }).join('') + '</div>';
        h += '</div>';
      });
      h += '<div id="av"></div><div class="linha" style="margin-top:20px;justify-content:space-between">' + (passo > 0 ? '<button class="btn" id="ant">‹ Voltar</button>' : '<span></span>') + (passo < etapas.length - 1 ? '<button class="btn pri" id="prox">Continuar ›</button>' : '<button class="btn pri" id="env">✅ ' + (enviado ? 'Atualizar anamnese' : 'Enviar anamnese') + '</button>') + '</div>' +
        '<p class="mut" style="margin-top:14px;font-size:12px">🔒 Suas respostas são vistas só pela equipe responsável pelo seu acompanhamento. As suas respostas ficam guardadas neste aparelho enquanto você preenche.</p>';
      v.innerHTML = h; rolagem();
      v.querySelectorAll('[data-id]').forEach(function (el) { el.oninput = function () { resp[el.getAttribute('data-id')] = el.value; sujo = true; guardarLocal(); clearTimeout(timer); timer = setTimeout(function () { salvarServidor(false); }, 1500); }; });
      v.querySelectorAll('[data-op]').forEach(function (box) { box.querySelectorAll('button').forEach(function (b) { b.onclick = function () { resp[box.getAttribute('data-op')] = b.getAttribute('data-v'); sujo = true; guardarLocal(); box.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); }); }; }); });
      document.getElementById('sai').onclick = function () { if (sujo) { confirmar({ emoji: '📝', titulo: 'Sair da anamnese?', texto: 'As respostas ficam guardadas neste aparelho e voltam quando você abrir de novo.', nao: 'Continuar', sim: 'Sair' }, function () { window.onbeforeunload = null; location.hash = '#/'; }); } else { window.onbeforeunload = null; location.hash = '#/'; } };
      var ant = document.getElementById('ant'), prox = document.getElementById('prox'), env = document.getElementById('env');
      if (ant) ant.onclick = function () { passo--; desenhar(); };
      if (prox) prox.onclick = async function () { await salvarServidor(false); passo++; desenhar(); };
      if (env) env.onclick = async function () {
        env.disabled = true;
        var x = await salvarServidor(true);
        env.disabled = false;
        if (!x.ok) {
          var i = etapas.findIndex(function (et) { return et.perguntas.some(function (p) { return p.obrig && !resp[p.id]; }); });
          document.getElementById('av').innerHTML = msg(x.data.mensagem);
          if (i >= 0 && i !== passo) { setTimeout(function () { passo = i; desenhar(); document.getElementById('av').innerHTML = msg(x.data.mensagem); }, 900); }
          return;
        }
        window.onbeforeunload = null; estado.anamneseEnviada = true;
        if (estado.home) estado.home.anamnese_enviada = true;
        v.innerHTML = '<div class="card hero" style="text-align:center"><div class="grande-ok">✓</div><h2>Anamnese enviada! 🎉</h2><p style="margin-top:6px">Obrigada! A equipe já pode acompanhar você de perto.</p></div><div class="linha" style="margin-top:14px"><a class="btn pri" href="#/">Ir para o início</a></div>';
        celebrar(x.data.novas);
      };
    }
    desenhar();
  }

  /* ───────── conta ───────── */
  function viewConta() {
    var v = tela('inicio');
    var h = estado.home || {};
    v.innerHTML = '<div class="card" style="display:flex;gap:14px;align-items:center"><div class="iconbtn av" style="width:56px;height:56px;font-size:22px">' + esc((estado.eu.nome || '?').charAt(0).toUpperCase()) + '</div><div style="min-width:0"><h2 style="font-size:19px;overflow-wrap:anywhere">' + esc(estado.eu.nome) + '</h2><div class="mut" style="overflow-wrap:anywhere">' + esc(estado.eu.email) + '</div>' +
      '<div class="mut">' + (estado.matricula && estado.matricula.ativa ? '✅ Acesso ativo' + (estado.matricula.expira_em ? ' até ' + CDD.dataBR(estado.matricula.expira_em) : '') : 'Sem acesso no momento') + '</div></div></div>' +
      '<a class="banner" href="#/anamnese" style="margin-top:12px"><span class="em">📝</span><div><b>Anamnese</b><span class="mut">' + (h.anamnese_enviada ? 'Enviada. Toque para revisar ou atualizar.' : 'Ainda não enviada. Toque para responder.') + '</span></div></a>' +
      '<a class="banner" href="#/jornada/conquistas" style="border-left-color:var(--accent)"><span class="em">🏅</span><div><b>Conquistas</b><span class="mut">' + (h.conquistas_ganhas || 0) + ' de ' + (h.conquistas_total || 0) + ' conquistadas</span></div></a>' +
      '<div class="card" style="margin-top:12px"><h3>🌗 Aparência</h3><p class="mut" style="margin:2px 0 10px">Tema claro ou escuro, como ficar melhor para você.</p><button class="btn" data-tema-btn id="tm"></button></div>' +
      '<div class="card"><h3>🔑 Trocar senha</h3><div id="aviso"></div><form id="f"><label for="at">Senha atual</label><input id="at" type="password" autocomplete="current-password" required><label for="nv">Nova senha (mínimo 8 caracteres)</label><input id="nv" type="password" autocomplete="new-password" minlength="8" required><div class="espaco"></div><button class="btn pri">Trocar senha</button></form></div>' +
      (estado.eu.papel === 'admin' ? '<a class="btn pri grande" style="margin-top:12px" href="painel">⚙️ Abrir o painel da equipe</a>' : '') +
      '<a class="btn grande" style="margin-top:12px" href="' + zapAjuda() + '" target="_blank" rel="noopener">💬 Falar com a equipe</a><button class="btn perigo grande" id="sai" style="margin-top:10px">Sair da conta</button>';
    ligarTema(v);
    document.getElementById('sai').onclick = async function () { await CDD.api('logout', { body: {} }); CDD.guardar(''); estado = { eu: null }; TR = null; location.hash = '#/'; telaLogin(); };
    document.getElementById('f').onsubmit = async function (e) {
      e.preventDefault();
      var r = await CDD.api('trocar_senha', { body: { atual: document.getElementById('at').value, nova: document.getElementById('nv').value } });
      document.getElementById('aviso').innerHTML = msg(r.ok ? 'Senha trocada.' : r.data.mensagem, r.ok ? 'ok' : 'erro');
      if (r.ok) document.getElementById('f').reset();
    };
  }

  /* ───────── roteador ───────── */
  function rota() {
    var h = location.hash || '#/';
    var ativ = h.match(/^#ativar=(.+)$/);
    if (ativ) return telaAtivar(ativ[1]);
    if (!estado.eu) { return h === '#/cadastro' ? telaCadastro() : telaLogin(); }
    var m, treinoId = (m = h.match(/^#\/treino\/(\d+)$/)) ? parseInt(m[1], 10) : 0;
    if (TR && !TR.jaSalvo && (!treinoId || treinoId !== TR.a.idaula)) {   // treino em andamento: não sai sem confirmar (botão voltar do celular incluído)
      history.pushState(null, '', '#/treino/' + TR.a.idaula);
      pedirSaida(); return;
    }
    if (TR && (TR.jaSalvo || !treinoId)) sairDoTreino();
    window.onbeforeunload = null; rolagem();
    if (treinoId) return viewTreino(treinoId);
    if ((m = h.match(/^#\/modulo\/(\d+)$/))) return viewModulo(parseInt(m[1], 10));
    if ((m = h.match(/^#\/aula\/(\d+)$/))) return viewAula(parseInt(m[1], 10));
    if (h === '#/aulas') return viewAulas();
    if ((m = h.match(/^#\/jornada(?:\/(\w+))?$/))) return viewJornada(m[1]);
    if ((m = h.match(/^#\/ranking(?:\/(\w+))?$/))) return viewRanking(m[1]);
    if (h === '#/anamnese') return viewAnamnese();
    if (h === '#/conta') return viewConta();
    if (h === '#/cardio') return viewCardio();
    if (h === '#/nutri') return viewNutri();
    return viewHome();
  }

  async function iniciar() {
    if (/^#ativar=/.test(location.hash)) { estado = { eu: null }; return rota(); }
    if (!CDD.token()) { estado = { eu: null }; return rota(); }
    var r = await CDD.api('eu');
    if (!r.ok) { CDD.guardar(''); estado = { eu: null }; return rota(); }
    estado.eu = r.data.usuario; estado.matricula = r.data.matricula; estado.acesso = r.data.acesso;
    if (estado.acesso && !estado.modulos) await carregarHome(); else if (!estado.acesso) { var an = await CDD.api('anamnese'); estado.anamneseEnviada = !!(an.ok && an.data.enviado_em); }
    if (estado.home) estado.anamneseEnviada = estado.home.anamnese_enviada;
    rota();
  }

  window.addEventListener('hashchange', rota);
  iniciar();
})();
