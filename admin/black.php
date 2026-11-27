<?php
// Editor da página /black: cores, textos, seções, fotos, prazo e links, com prévia de celular e computador.
require_once 'config.php';
require_once __DIR__ . '/_black_cfg.php';
session_name(SESSION_KEY);
session_start();

$_doAdmin = !empty($_SESSION['auth']) && (time() - (int)($_SESSION['login_time'] ?? 0)) <= SESSION_TIMEOUT;
if (!$_doAdmin && !blackPeloPainel($_SESSION, time())) {
    if (isset($_GET['acao'])) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => 'Sua sessão terminou. Em outra aba, abra o editor de novo pelo menu do painel e depois clique em salvar aqui.']); exit; }
    header('Location: /app/painel/black.html'); exit;
}
if (empty($_SESSION['black_token'])) $_SESSION['black_token'] = bin2hex(random_bytes(24));

if (isset($_GET['acao'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['black_token'], (string)($_SERVER['HTTP_X_BLACK_TOKEN'] ?? ''))) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Pedido recusado. Recarregue o editor.']); exit;
    }
    if ($_GET['acao'] === 'salvar') {
        $corpo = (string)file_get_contents('php://input');
        $dados = strlen($corpo) < 200000 ? json_decode($corpo, true) : null;
        if (!is_array($dados) || !isset($dados['cfg']) || !is_array($dados['cfg'])) { echo json_encode(['ok' => false, 'error' => 'Dados inválidos.']); exit; }
        $ok = blackGravar($dados['cfg']);
    } elseif ($_GET['acao'] === 'desfazer') {
        if (!is_file(BLACK_ANTERIOR)) { echo json_encode(['ok' => false, 'error' => 'Ainda não há publicação para desfazer.']); exit; }
        $ok = blackGravar(blackLer(BLACK_ANTERIOR));
    } else { echo json_encode(['ok' => false, 'error' => 'Ação desconhecida.']); exit; }
    echo json_encode($ok ? ['ok' => true, 'cfg' => (object)blackLer()] : ['ok' => false, 'error' => 'Não consegui gravar o arquivo no servidor.']);
    exit;
}
header('Cache-Control: no-store');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex,nofollow">
<title>Editor da página Black Friday · Intus</title>
<link rel="icon" type="image/x-icon" href="/favicon.ico">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--bg:#0a0a0a;--bg2:#121212;--bg3:#191919;--borda:#2c2c2c;--txt:#f5f5f5;--muted:#a3a3a3;--acao:#ffc53d}
body{font-family:Inter,system-ui,sans-serif;background:var(--bg);color:var(--txt);font-size:14px;height:100vh;display:flex;flex-direction:column;overflow:hidden}
button,input,select,textarea{font:inherit;color:inherit}
.barra{display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:10px 14px;background:var(--bg2);border-bottom:1px solid var(--borda)}
.barra h1{font-size:14px;margin-right:6px}
.barra a{color:var(--muted);text-decoration:none;font-size:13px}
.barra .esp{flex:1}
.grupo-btn{display:flex;border:1px solid var(--borda);border-radius:9px;overflow:hidden}
.grupo-btn button{background:transparent;border:0;padding:8px 14px;cursor:pointer;color:var(--muted);font-weight:600}
.grupo-btn button[aria-pressed="true"]{background:var(--acao);color:#000}
.b{background:transparent;border:1px solid var(--borda);border-radius:9px;padding:8px 13px;cursor:pointer;font-weight:600;font-size:13px}
.b:hover{border-color:var(--muted)}
.b.pri{background:var(--acao);border-color:var(--acao);color:#000}
.b:disabled{opacity:.45;cursor:default}
select,input[type=text],input[type=url],input[type=datetime-local],textarea{background:var(--bg);border:1px solid var(--borda);border-radius:8px;padding:9px 10px;width:100%}
.barra select{width:auto;padding:7px 8px}
textarea{resize:vertical;line-height:1.45}
.corpo{flex:1;display:flex;min-height:0}
.painel{width:390px;flex:none;overflow-y:auto;background:var(--bg2);border-right:1px solid var(--borda);padding:10px 12px 60px}
.palco{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;padding:14px;overflow:hidden}
.moldura{position:relative;overflow:hidden;border:1px solid var(--borda);background:#000;flex:none}
.moldura.celular{border:8px solid #2e2e2e;border-radius:34px}
.moldura iframe{position:absolute;top:0;left:0;border:0;transform-origin:0 0;background:#0b0b0b}
.medida{color:var(--muted);font-size:12px;margin-top:8px}
details.bloco{border:1px solid var(--borda);border-radius:11px;margin-bottom:8px;background:var(--bg3)}
details.bloco>summary{cursor:pointer;padding:12px 13px;font-weight:700}
.dentro{padding:0 13px 13px;display:grid;gap:12px}
label.c{display:grid;gap:5px;font-size:12px;font-weight:600;color:var(--muted)}
label.c>*{color:var(--txt);font-size:14px;font-weight:400}
.cor{display:grid;grid-template-columns:48px 1fr 94px;gap:9px;align-items:center}
.cor input[type=color]{width:48px;height:34px;border:1px solid var(--borda);border-radius:7px;background:none;cursor:pointer;padding:2px}
.prontas{display:flex;flex-wrap:wrap;gap:6px}
.prontas button{display:flex;align-items:center;gap:6px}
.prontas i{width:13px;height:13px;border-radius:50%;display:block;border:1px solid #777}
.nota{font-size:12px;color:var(--muted);line-height:1.5}
.alerta{font-size:12px;line-height:1.5;color:#ffb4a8;border:1px solid #6b2b22;background:#2a1310;border-radius:8px;padding:8px 10px}
.marca{display:flex;gap:9px;align-items:center;font-size:14px;cursor:pointer}
.marca input{width:17px;height:17px;accent-color:var(--acao)}
.foto{display:grid;grid-template-columns:64px 1fr;gap:10px;align-items:start}
.foto img{width:64px;height:64px;object-fit:cover;border-radius:9px;border:1px solid var(--borda)}
.foto div{display:grid;gap:6px;min-width:0}
.duvida{border:1px solid var(--borda);border-radius:9px;padding:10px;display:grid;gap:7px}
.duvida .acoes{display:flex;gap:6px;justify-content:flex-end}
.destacar{animation:piscar 1.6s}
@keyframes piscar{0%,60%{box-shadow:0 0 0 3px #4da3ff}100%{box-shadow:0 0 0 3px transparent}}
#aviso{position:fixed;left:50%;bottom:20px;transform:translateX(-50%);background:#fff;color:#000;border-radius:10px;padding:12px 18px;font-weight:700;max-width:92vw;z-index:9}
#aviso.erro{background:#b3261e;color:#fff}
[hidden]{display:none!important}
@media(max-width:860px){
  body{height:auto;overflow:auto}
  .corpo{flex-direction:column-reverse}
  .painel{width:100%;border-right:0;overflow:visible}
  .palco{height:75vh}
}
</style>
</head>
<body>
<div class="barra">
  <a href="<?= $_doAdmin ? 'painel.php' : '/app/painel/index.html' ?>">← Painel</a>
  <h1>Página Black Friday</h1>
  <div class="grupo-btn" role="group" aria-label="Tamanho da prévia">
    <button type="button" id="v-celular" aria-pressed="true">📱 Celular</button>
    <button type="button" id="v-pc" aria-pressed="false">🖥 Computador</button>
  </div>
  <select id="situacao" aria-label="Situação mostrada na prévia">
    <option value="">Prévia: como está agora</option>
    <option value="aberta">Prévia: promoção aberta</option>
    <option value="encerrada">Prévia: depois do prazo</option>
  </select>
  <span class="esp"></span>
  <a href="/black/" target="_blank" rel="noopener">Ver no ar ↗</a>
  <button type="button" class="b" id="desfazer" title="Volta a página para como estava antes do último salvamento">Desfazer última publicação</button>
  <button type="button" class="b" id="restaurar">Voltar ao original</button>
  <button type="button" class="b pri" id="salvar" disabled>Salvar e publicar</button>
</div>

<div class="corpo">
  <aside class="painel" id="painel"><p class="nota" id="carregando">Carregando a página...</p></aside>
  <main class="palco" id="palco">
    <div class="moldura celular" id="moldura"><iframe id="previa" title="Prévia da página" src="/black/?editor=1"></iframe></div>
    <div class="medida" id="medida"></div>
  </main>
</div>
<input type="file" id="arquivo" accept="image/jpeg,image/png,image/webp,image/gif" hidden>
<div id="aviso" hidden role="status"></div>

<script>
(function () {
  'use strict';
  var TOKEN = <?= json_encode($_SESSION['black_token']) ?>;
  var salvo = <?= json_encode((object)blackLer(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var campos = [], padrao = null, precos = null, ed = null, vista = 'celular', sujo = false, imagens = [];

  var $ = function (id) { return document.getElementById(id); };
  var iframe = $('previa');
  function copia(o) { return JSON.parse(JSON.stringify(o)); }
  function igual(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
  function novo(tag, props, filhos) {
    var e = document.createElement(tag), k;
    for (k in (props || {})) { if (k === 'cls') e.className = props[k]; else if (k === 'txt') e.textContent = props[k]; else e[k] = props[k]; }
    (filhos || []).forEach(function (f) { if (f) e.appendChild(f); });
    return e;
  }
  function avisar(msg, erro) {
    var a = $('aviso'); a.textContent = msg; a.className = erro ? 'erro' : ''; a.hidden = false;
    clearTimeout(avisar.t); avisar.t = setTimeout(function () { a.hidden = true; }, erro ? 8000 : 3500);
  }

  /* ── estado ── */
  function juntar(cfg) {
    var e = copia(padrao);
    ['textos', 'listas', 'imagens', 'cores', 'ajustes'].forEach(function (g) {
      Object.keys(cfg[g] || {}).forEach(function (k) { if (k in e[g]) e[g][k] = copia(cfg[g][k]); });
    });
    Object.keys(cfg.links || {}).forEach(function (p) { Object.keys(cfg.links[p]).forEach(function (per) { if (e.links[p]) e.links[p][per] = cfg.links[p][per]; }); });
    if (cfg.duvidas) e.duvidas = copia(cfg.duvidas);
    e.ocultos = (cfg.ocultos || []).slice();
    return e;
  }
  /* Só o que mudou em relação ao original vai para o servidor. */
  function diferenca() {
    var d = {};
    ['textos', 'listas', 'imagens', 'cores', 'ajustes'].forEach(function (g) {
      Object.keys(ed[g]).forEach(function (k) { if (!igual(ed[g][k], padrao[g][k])) { (d[g] = d[g] || {})[k] = ed[g][k]; } });
    });
    Object.keys(ed.links).forEach(function (p) {
      Object.keys(ed.links[p]).forEach(function (per) { if (ed.links[p][per] !== padrao.links[p][per]) { d.links = d.links || {}; (d.links[p] = d.links[p] || {})[per] = ed.links[p][per]; } });
    });
    if (!igual(ed.duvidas, padrao.duvidas)) d.duvidas = ed.duvidas;
    if (ed.ocultos.length) d.ocultos = ed.ocultos;
    return d;
  }
  function enviarPrevia() {
    if (ed) iframe.contentWindow.postMessage({ black: 'aplicar', cfg: diferenca(), situacao: $('situacao').value }, location.origin);
  }
  function mudou() { sujo = true; $('salvar').disabled = false; enviarPrevia(); conferirContraste(); }

  /* ── celular x computador ── */
  function ajustar() {
    var palco = $('palco'), m = $('moldura'), celular = vista === 'celular';
    var larg = celular ? 390 : 1280, dispL = palco.clientWidth - 28, dispA = palco.clientHeight - 58;
    var escala, altura;
    if (celular) {
      altura = 800; escala = Math.min(1, (dispL - 16) / larg, (dispA - 16) / altura);
      escala = Math.max(escala, 0.4);
    } else {
      escala = Math.min(1, dispL / larg); altura = Math.round(dispA / escala);
    }
    m.className = 'moldura' + (celular ? ' celular' : '');
    m.style.width = Math.round(larg * escala) + (celular ? 16 : 2) + 'px';
    m.style.height = Math.round(altura * escala) + (celular ? 16 : 2) + 'px';
    iframe.style.width = larg + 'px'; iframe.style.height = altura + 'px';
    iframe.style.transform = 'scale(' + escala + ')';
    $('medida').textContent = (celular ? 'Celular, 390 px de largura' : 'Computador, 1280 px de largura') + (escala < 0.99 ? ' · reduzido a ' + Math.round(escala * 100) + '% para caber' : '');
  }
  function escolherVista(v) {
    vista = v;
    $('v-celular').setAttribute('aria-pressed', String(v === 'celular'));
    $('v-pc').setAttribute('aria-pressed', String(v === 'pc'));
    ajustar();
  }
  $('v-celular').onclick = function () { escolherVista('celular'); };
  $('v-pc').onclick = function () { escolherVista('pc'); };
  window.addEventListener('resize', ajustar);
  $('situacao').onchange = enviarPrevia;

  /* ── painel ── */
  var CORES = [['principal', 'Cor da página (títulos, selos e destaques)'], ['botao', 'Cor dos botões'], ['graca', 'Cor do “de graça”'], ['zap', 'Cor do WhatsApp e do Pix'], ['sobre', 'Texto dentro das cores'], ['bg', 'Fundo da página'], ['card', 'Fundo dos cartões'], ['borda', 'Linhas e bordas'], ['txt', 'Texto']];
  var PRONTAS = [
    ['Amarelo e verde', {}],
    ['Tudo amarelo', { botao: '#ffc53d', graca: '#ffc53d', zap: '#ffc53d' }],
    ['Tudo verde', { principal: '#c1ff72' }],
    ['Laranja', { principal: '#ff8a3d' }],
    ['Azul', { principal: '#4cc9ff' }],
    ['Fundo claro', { botao: '#2f6b00', graca: '#2f6b00', zap: '#2f6b00', principal: '#8a5a00', bg: '#ffffff', card: '#f3f3f1', borda: '#d6d6d2', txt: '#111111', sobre: '#ffffff' }]
  ];
  var SECOES = [['relogio', 'Cronômetro'], ['como', 'Como funciona'], ['garantia', 'Garantia'], ['banda', 'Faixa “2026 de graça” do meio'], ['recebe', 'O que você recebe'], ['equipe', 'Quem vai te acompanhar'], ['resultados', 'Antes e depois'], ['duvidas', 'Dúvidas'], ['contato', 'Chamada final do WhatsApp'], ['slogan', 'Slogan']];
  var abertos = { Cores: true };

  function bloco(titulo, filhos) {
    var d = novo('details', { cls: 'bloco', open: !!abertos[titulo] }, [novo('summary', { txt: titulo }), novo('div', { cls: 'dentro' }, filhos)]);
    d.addEventListener('toggle', function () { abertos[titulo] = d.open; });
    return d;
  }
  function rotulado(rotulo, entrada) { return novo('label', { cls: 'c' }, [novo('span', { txt: rotulo, cls: 'nota' }), entrada]); }
  function entradaTexto(valor, aoMudar, longo) {
    var e = longo ? novo('textarea', { rows: Math.min(8, Math.max(2, Math.ceil(valor.length / 42), valor.split('\n').length)) }) : novo('input', { type: 'text' });
    e.value = valor; e.addEventListener('input', function () { aoMudar(e.value); mudou(); });
    return e;
  }

  function luz(hex) {
    var c = [1, 3, 5].map(function (i) { var v = parseInt(hex.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  }
  function contraste(a, b) { var x = luz(a), y = luz(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function conferirContraste() {
    var alvo = $('contraste'); if (!alvo) return;
    var pares = [['txt', 'bg', 'Texto sobre o fundo'], ['txt', 'card', 'Texto sobre os cartões'], ['sobre', 'botao', 'Texto dos botões'], ['sobre', 'zap', 'Texto do botão do WhatsApp'], ['sobre', 'principal', 'Texto dos selos'], ['principal', 'bg', 'Cor da página sobre o fundo'], ['principal', 'card', 'Cor da página sobre os cartões'], ['graca', 'bg', '“De graça” sobre o fundo'], ['graca', 'card', '“De graça” sobre os cartões'], ['zap', 'bg', 'Link do Pix sobre o fundo'], ['zap', 'card', 'Link do Pix sobre os cartões']];
    var ruins = pares.filter(function (p) { return contraste(ed.cores[p[0]], ed.cores[p[1]]) < 4.5; }).map(function (p) { return p[2]; });
    alvo.hidden = !ruins.length;
    alvo.textContent = ruins.length ? 'Pode ficar difícil de ler: ' + ruins.join('; ') + '.' : '';
  }

  function montarPainel() {
    var painel = $('painel'); painel.textContent = '';
    painel.appendChild(novo('p', { cls: 'nota', txt: 'Toque em um texto ou foto na prévia para abrir o campo dele. Nada muda no ar antes de "Salvar e publicar".', style: 'margin:2px 2px 10px' }));

    var prontas = novo('div', { cls: 'prontas' }, PRONTAS.map(function (p) {
      var c = Object.assign({}, padrao.cores, p[1]);
      var bola = novo('i'); bola.style.background = 'linear-gradient(90deg,' + c.principal + ' 50%,' + c.bg + ' 50%)';
      return novo('button', { type: 'button', cls: 'b', onclick: function () { ed.cores = c; montarPainel(); mudou(); } }, [bola, novo('span', { txt: p[0] })]);
    }));
    var cores = CORES.map(function (par) {
      var k = par[0];
      var seletor = novo('input', { type: 'color', value: ed.cores[k] }), codigo = novo('input', { type: 'text', value: ed.cores[k], maxLength: 7 });
      seletor.setAttribute('aria-label', par[1]); codigo.setAttribute('aria-label', par[1] + ', código');
      seletor.oninput = function () { ed.cores[k] = codigo.value = seletor.value; mudou(); };
      codigo.oninput = function () { var v = codigo.value.trim().toLowerCase(); if (/^#[0-9a-f]{6}$/.test(v)) { ed.cores[k] = seletor.value = v; mudou(); } };
      return novo('div', { cls: 'cor' }, [seletor, novo('span', { txt: par[1] }), codigo]);
    });
    painel.appendChild(bloco('Cores', [novo('div', { cls: 'nota', txt: 'Combinações prontas:' }), prontas].concat(cores, [novo('div', { cls: 'alerta', id: 'contraste', hidden: true }), novo('div', { cls: 'nota', txt: 'A logo troca sozinha entre branca e preta conforme o fundo.' })])));

    var fim = novo('input', { type: 'datetime-local', value: ed.ajustes.fim });
    fim.onchange = function () { if (fim.value) { ed.ajustes.fim = fim.value.slice(0, 16); mudou(); } };
    var whats = entradaTexto(ed.ajustes.whats, function (v) { v = v.replace(/\D/g, ''); if (v.length >= 10 && v.length <= 15) ed.ajustes.whats = v; });
    function escolha(chave, opcoes) {
      var s = novo('select', {}, opcoes.map(function (o) { return novo('option', { value: o[0], txt: o[1] }); }));
      s.value = ed.ajustes[chave]; s.onchange = function () { ed.ajustes[chave] = s.value; mudou(); };
      return s;
    }
    painel.appendChild(bloco('Prazo, contato e destaque', [
      rotulado('Fim da promoção (horário de Brasília). Vale até o último segundo do minuto escolhido.', fim),
      novo('div', { cls: 'alerta', txt: 'Mudar o prazo não reescreve os textos que citam "30/11" nem muda o encerramento dos links no Asaas (30/11).' }),
      rotulado('WhatsApp (com 55 e DDD, só números)', whats),
      rotulado('Período que abre selecionado', escolha('aba', [['tri', 'Trimestral'], ['sem', 'Semestral'], ['anual', 'Anual']])),
      rotulado('Plano em destaque', escolha('destaque', [['combo', ed.textos['planos.combo.nome']], ['treino', ed.textos['planos.treino.nome']], ['dieta', ed.textos['planos.dieta.nome']], ['nenhum', 'Nenhum']]))
    ]));

    painel.appendChild(bloco('Mostrar ou esconder partes', SECOES.map(function (s) {
      var m = novo('input', { type: 'checkbox', checked: ed.ocultos.indexOf(s[0]) < 0 });
      m.onchange = function () { ed.ocultos = ed.ocultos.filter(function (x) { return x !== s[0]; }); if (!m.checked) ed.ocultos.push(s[0]); mudou(); };
      return novo('label', { cls: 'marca' }, [m, novo('span', { txt: s[1] })]);
    })));

    var grupos = {}, ordem = [];
    campos.forEach(function (c) { if (!grupos[c.grupo]) { grupos[c.grupo] = []; ordem.push(c.grupo); } grupos[c.grupo].push(c); });
    var preferida = ['Topo', 'Como funciona', 'Planos', 'Garantia', 'O que você recebe', 'Equipe', 'Dúvidas', 'Chamada final', 'Depois do prazo'];
    ordem.sort(function (a, b) { return preferida.indexOf(a) - preferida.indexOf(b); });
    ordem.forEach(function (g) {
      var itens = grupos[g].map(function (c) {
        var el;
        if (c.tipo === 'texto') {
          el = rotulado(c.rotulo, entradaTexto(ed.textos[c.chave], function (v) { ed.textos[c.chave] = v; }, ed.textos[c.chave].length > 38));
        } else if (c.tipo === 'lista') {
          el = rotulado(c.rotulo, entradaTexto(ed.listas[c.chave].join('\n'), function (v) { ed.listas[c.chave] = v.split('\n').filter(function (l) { return l.trim(); }); }, true));
        } else el = campoFoto(c);
        el.dataset.campo = c.chave;
        return el;
      });
      if (g === 'Dúvidas') itens = itens.concat(editorDuvidas());
      painel.appendChild(bloco(g === 'Dúvidas' ? 'Dúvidas' : 'Textos: ' + g, itens));
    });

    var nomes = { treino: 'Treino', combo: 'Treino + Dieta', dieta: 'Dieta' }, per = { tri: 'trimestral', sem: 'semestral', anual: 'anual' };
    var links = [novo('div', { cls: 'alerta', txt: 'Cada link do Asaas cobra um valor fixo, por isso os preços não são editáveis aqui. Ao trocar um link, confira se o valor dele é o mesmo mostrado na página. Campo vazio faz o botão levar ao WhatsApp.' })];
    Object.keys(nomes).forEach(function (p) {
      Object.keys(per).forEach(function (k) {
        var aviso = novo('span', { cls: 'alerta', txt: 'Este endereço não começa com https:// e não será usado.', hidden: true });
        var campo = entradaTexto(ed.links[p][k], function (v) {
          v = v.trim(); var ok = v === '' || /^https:\/\/[^\s"'<>]+$/.test(v);
          aviso.hidden = ok; ed.links[p][k] = ok ? v : padrao.links[p][k];
        });
        var r = rotulado(nomes[p] + ' ' + per[k] + ' · R$ ' + precos[p][k].toLocaleString('pt-BR'), campo); r.appendChild(aviso);
        links.push(r);
      });
    });
    painel.appendChild(bloco('Links de pagamento', links));
    conferirContraste();
  }

  function campoFoto(c) {
    var mini = novo('img', { src: ed.imagens[c.chave], alt: '' });
    var caminho = novo('input', { type: 'text', value: ed.imagens[c.chave], readOnly: true });
    function usar(url) { ed.imagens[c.chave] = url; mini.src = url; caminho.value = url; mudou(); }
    var lista = novo('select', {}, [novo('option', { value: '', txt: 'Escolher uma imagem já enviada' }), novo('option', { value: padrao.imagens[c.chave], txt: 'Foto original' })]
      .concat(imagens.map(function (n) { return novo('option', { value: '/img/' + encodeURIComponent(n), txt: n }); })));
    lista.onchange = function () { if (lista.value) usar(lista.value); };
    var enviar = novo('button', { type: 'button', cls: 'b', txt: 'Enviar foto nova' });
    enviar.onclick = function () {
      var arq = $('arquivo');
      arq.onchange = function () {
        if (!arq.files[0]) return;
        var fd = new FormData(); fd.append('img', arq.files[0]); arq.value = '';
        enviar.disabled = true;
        fetch('upload.php', { method: 'POST', body: fd }).then(function (r) { return r.json(); }).then(function (j) {
          if (!j.ok) throw new Error(j.error);
          imagens.push(j.filename); usar('/img/' + encodeURIComponent(j.filename)); avisar('Foto enviada. Salve para publicar.');
        }).catch(function (e) { avisar('Não consegui enviar a foto. ' + (e.message || ''), true); }).then(function () { enviar.disabled = false; });
      };
      arq.click();
    };
    return novo('div', { cls: 'c' }, [novo('span', { cls: 'nota', txt: c.rotulo }), novo('div', { cls: 'foto' }, [mini, novo('div', {}, [caminho, lista, enviar])])]);
  }

  function editorDuvidas() {
    function mover(i, para) { var x = ed.duvidas.splice(i, 1)[0]; ed.duvidas.splice(para, 0, x); montarPainel(); mudou(); }
    var itens = ed.duvidas.map(function (d, i) {
      return novo('div', { cls: 'duvida' }, [
        rotulado('Pergunta ' + (i + 1), entradaTexto(d.p, function (v) { d.p = v; })),
        rotulado('Resposta', entradaTexto(d.r, function (v) { d.r = v; }, true)),
        novo('div', { cls: 'acoes' }, [
          novo('button', { type: 'button', cls: 'b', txt: '▲', title: 'Subir', disabled: i === 0, onclick: function () { mover(i, i - 1); } }),
          novo('button', { type: 'button', cls: 'b', txt: '▼', title: 'Descer', disabled: i === ed.duvidas.length - 1, onclick: function () { mover(i, i + 1); } }),
          novo('button', { type: 'button', cls: 'b', txt: 'Tirar', onclick: function () { if (confirm('Tirar a pergunta "' + d.p + '"?')) { ed.duvidas.splice(i, 1); montarPainel(); mudou(); } } })
        ])
      ]);
    });
    itens.push(novo('button', { type: 'button', cls: 'b', txt: '+ Nova pergunta', onclick: function () { ed.duvidas.push({ p: 'Nova pergunta', r: '' }); montarPainel(); mudou(); } }));
    return itens;
  }

  /* ── conversa com a prévia ── */
  window.addEventListener('message', function (ev) {
    if (ev.origin !== location.origin || ev.source !== iframe.contentWindow || !ev.data) return;
    if (ev.data.black === 'pronta') {
      campos = ev.data.campos; padrao = ev.data.padrao; padrao.ocultos = []; precos = ev.data.precos;
      if (!ed) { ed = juntar(salvo); montarPainel(); }
      enviarPrevia();
    }
    if (ev.data.black === 'campo') {
      var alvo = document.querySelector('[data-campo="' + ev.data.chave + '"]'); if (!alvo) return;
      var d = alvo.closest('details'); d.open = true;
      alvo.scrollIntoView({ block: 'center' });
      alvo.classList.remove('destacar'); void alvo.offsetWidth; alvo.classList.add('destacar');
      var campo = alvo.querySelector('textarea,input:not([readonly])'); if (campo) campo.focus({ preventScroll: true });
    }
  });

  /* ── publicar ── */
  function pedir(acao, corpo) {
    return fetch('black.php?acao=' + acao, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Black-Token': TOKEN }, body: JSON.stringify(corpo || {}) })
      .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) throw new Error(j.error); return j; });
  }
  $('salvar').onclick = function () {
    $('salvar').disabled = true;
    pedir('salvar', { cfg: diferenca() }).then(function (j) { salvo = j.cfg; sujo = false; avisar('Publicado. A página no ar já mostra as alterações.'); })
      .catch(function (e) { $('salvar').disabled = false; avisar('Não salvou. ' + e.message, true); });
  };
  $('desfazer').onclick = function () {
    if (!confirm('Voltar a página no ar para como estava antes do último salvamento?' + (sujo ? ' As alterações não salvas serão perdidas.' : ''))) return;
    pedir('desfazer').then(function (j) { salvo = j.cfg; ed = juntar(salvo); sujo = false; $('salvar').disabled = true; montarPainel(); enviarPrevia(); avisar('Última publicação desfeita.'); })
      .catch(function (e) { avisar(e.message, true); });
  };
  $('restaurar').onclick = function () {
    if (!ed || !confirm('Voltar todos os campos ao original? No ar só muda quando você salvar.')) return;
    ed = juntar({}); montarPainel(); mudou();
  };
  window.addEventListener('beforeunload', function (e) { if (sujo) { e.preventDefault(); e.returnValue = ''; } });
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); if (!$('salvar').disabled) $('salvar').click(); } });

  fetch('imglist.php').then(function (r) { return r.json(); }).then(function (l) { imagens = Array.isArray(l) ? l : []; if (ed) montarPainel(); }).catch(function () {});
  ajustar();
  /* Se a prévia avisou antes de este código começar a ouvir, recarrega. */
  setTimeout(function () { if (!ed) iframe.src = '/black/?editor=1&r=' + Date.now(); }, 6000);
})();
</script>
</body>
</html>
