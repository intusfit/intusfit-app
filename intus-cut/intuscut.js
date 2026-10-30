(function(){
'use strict';
// ---------- formatos e constantes ----------
var FORMATOS = {
  reel:     { n:'Reel Instagram 9:16', w:1080, h:1920, guia:'reel' },
  story:    { n:'Story 9:16', w:1080, h:1920, guia:'story' },
  shorts:   { n:'Shorts / TikTok 9:16', w:1080, h:1920, guia:'reel' },
  feed45:   { n:'Feed 4:5', w:1080, h:1350 },
  quadrado: { n:'Quadrado 1:1', w:1080, h:1080 },
  youtube:  { n:'YouTube 16:9', w:1920, h:1080 }
};
var W = 1080, H = 1920, FPS = 30, SRC_H = 780, ORIG_W = 1920;
var T_GANCHO_PADRAO = 2.6, T_FIM = 2.8;
var COR = { amarelo:'#ffd60a', verde:'#7fff00', branco:'#f5f5f5', vermelho:'#ff4d4d', fundo:'#0f0f0f' };
var CORES_FONTE = ['#4f7a24', '#2d6f8c', '#8a5a2b', '#6b3f8f', '#8f2f4f', '#3f7f6f'];
var COR_FAIXA = { texto:'#2d6f8c', imagem:'#8a5a2b', sombra:'#4c525c', el:'#8c6d1f', camada:'#6b3f8f' };
var NOMES = { janela:'Vídeo', legenda:'Legenda', gancho:'Título do topo', gancho_grande:'Gancho grande', arrobas:'@ do rodapé', logo:'Logo', notas:'Marcação', barra:'Barra de progresso', texto:'Texto', sombra:'Sombra', imagem:'Imagem', geral:'Imagem e fundo', final:'Cartão final', camada:'Sobreposição' };
var BIB_FIXOS = [
  { id:'fixo-1', tipo:'logo', nome:'Intus, branca', url:'/app/painel/logo-intus-dark.png', fixo:true },
  { id:'fixo-2', tipo:'logo', nome:'Intus, branca grande', url:'/app/painel/logo-intus-branco.png', fixo:true },
  { id:'fixo-3', tipo:'logo', nome:'Intus, escura', url:'/app/painel/logo-intus-light.png', fixo:true },
  { id:'fixo-4', tipo:'logo', nome:'Ícone claro', url:'/app/painel/logo-icon-light.png', fixo:true },
  { id:'fixo-5', tipo:'logo', nome:'Ícone escuro', url:'/app/painel/logo-icon-dark.png', fixo:true }
];
var LOGO = new Image(); LOGO.src = 'logo.png';
var $ = function(id){ return document.getElementById(id); };
var tela = $('tela'), ctx = tela.getContext('2d'), guia = $('guia'), gctx = guia.getContext('2d');

// ---------- estado ----------
var assets = true;
var projetos = {}, P = null, T = 0, tocando = false, selTrecho = 0, aba = 'midia', salvarTimer = null, lacunaSel = null;
var CAIXAS = [], selEl = null, mostrarIG = true, zoomTL = 1, BIB = {}, RENDERS = {};
try { mostrarIG = localStorage.getItem('intuscut-ig') !== '0'; } catch (e) {}

function clone(o){ return o == null ? o : JSON.parse(JSON.stringify(o)); }
function cl(v, a, b){ return Math.min(Math.max(v, a), b); }
function fmt(t){ t = Math.max(0, t || 0); var m = Math.floor(t / 60), s = t - m * 60; return (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s.toFixed(2); }
function fmtc(t){ var m = Math.floor(t / 60), s = Math.round(t - m * 60); if (s === 60){ m++; s = 0; } return m + ':' + (s < 10 ? '0' : '') + s; }
function estado(txt, tipo){ var e = $('estado'); e.textContent = txt; e.className = 'pilula' + (tipo ? ' ' + tipo : ''); }
function cor(x){ return COR[x] || x || COR.branco; }
function rgba(hex, a){ var m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '#000000') || [0, '00', '00', '00'];
  return 'rgba(' + parseInt(m[1], 16) + ',' + parseInt(m[2], 16) + ',' + parseInt(m[3], 16) + ',' + a + ')'; }
function novoId(p){ return p + Date.now().toString(36) + Math.random().toString(36).slice(2, 5); }

// ---------- formato e posições de fábrica ----------
function formato(){ return FORMATOS[(P && P.formato) || 'reel'] || FORMATOS.reel; }
function aplicaFormato(){
  var F = formato(); W = F.w; H = F.h; [tela, guia].forEach(function(c){ c.width = W; c.height = H; });
  var b = $('telabox'); b.style.setProperty('--ar', W + '/' + H); b.classList.toggle('largo', W > H);
  $('b-ig').disabled = !F.guia; $('sel-formato').value = (P && P.formato) || 'reel';
}
// Cada formato tem as próprias posições de fábrica; o projeto guarda o que foi mexido.
function padrao(k){
  var vert = H / W > 1.5;
  var d = {
    geral:   { fundo:'#0f0f0f', brilho:1, contraste:1, saturacao:1 },
    janela:  vert ? { y:230, h:1350, preencher:'desfoque', alinhar:-110 } : { y:0, h:H, preencher:'desfoque', alinhar:0 },
    legenda: { visivel:true, dx:0, dy:vert ? 0 : -50, borda:7, fundo:false, largura:W - 80, por_linha:0, car_linha:0, entrelinha:1.31 },
    gancho:  { visivel:true, x:W / 2, y:vert ? 118 : Math.round(H * .1), escala:vert ? 1 : .9, cor:'#7fff00', largura:W - 120, por_linha:0, car_linha:0, entrelinha:1.17 },
    gancho_grande: { visivel:true, dx:0, dy:vert ? 0 : Math.round(H * .42 - 330), escala:vert ? 1 : .9, escurecer:.47, largura:W - 120, por_linha:0, car_linha:0, entrelinha:1.13 },
    arrobas: { visivel:true, x:W / 2, y:vert ? 1660 : H - 56, escala:vert ? 1 : .8, texto:'@luiznunest   ·   @intusfit', cor:'#c8c8c8' },
    logo:    { visivel:true, x:vert ? W / 2 : W - 140, y:vert ? 1740 : 28, largura:vert ? 240 : 200, url:'' },
    notas:   { dx:0, dy:0, escala:1 },
    barra:   { visivel:true, cor:'#7fff00', altura:10, embaixo:true },
    sombra:  { visivel:true, altura:200, opacidade:.45 }
  };
  return d[k];
}
function lay(k){ P.layout = P.layout || {}; var base = padrao(k), o = P.layout[k] || {};
  for (var c in base) if (!(c in o)) o[c] = base[c]; P.layout[k] = o; return o; }
function mudaFormato(k){
  if (!P || k === (P.formato || 'reel')) return;
  var W1 = W, H1 = H; P.formato = k; aplicaFormato();
  var fx = W / W1, fy = H / H1;
  (P.textos || []).concat(P.imagens || []).forEach(function(x){ x.x = Math.round(x.x * fx); x.y = Math.round(x.y * fy); if (x.w) x.w = Math.round(x.w * Math.min(fx, fy)); });
  (P.sombras || []).forEach(function(s){ s.x = Math.round(s.x * fx); s.y = Math.round(s.y * fy); s.w = Math.round(s.w * fx); s.h = Math.round(s.h * fy); });
  var logoUrl = P.layout && P.layout.logo && P.layout.logo.url; P.layout = {}; if (logoUrl) lay('logo').url = logoUrl;
  selEl = null; recalc(); desenhaTimeline(); irPara(T); mostraAba(); agendaSalvar('Formato: ' + FORMATOS[k].n);
}

// ---------- imagens (logos, fotos) ----------
var IMG = {};
function imgDe(url){ if (!url) return LOGO; if (!IMG[url]){ var i = new Image(); i.crossOrigin = 'anonymous'; i.onload = function(){ if (P) pinta(); }; i.src = url; IMG[url] = i; } return IMG[url]; }
function logoAtual(){ var u = P && P.layout && P.layout.logo && P.layout.logo.url; return u ? imgDe(u) : LOGO; }

// ---------- fontes de vídeo ----------
// '' é o vídeo do próprio projeto. Outros vídeos da montagem ficam em P.fontes[chave], cada um com
// mídia, palavras, planos e marcações próprios (cópia tirada na hora em que entraram).
function fonte(key){ return key && P.fontes && P.fontes[key] ? P.fontes[key] : P; }
function nomeFonte(key){ return key ? (fonte(key).nome || key) : 'Vídeo principal'; }
function chavesUsadas(){ var v = ['']; trechos().forEach(function(s){ var k = s.de || ''; if (v.indexOf(k) < 0) v.push(k); }); return v; }
function corFonte(key){ var ks = chavesUsadas(); return CORES_FONTE[Math.max(0, ks.indexOf(key || '')) % CORES_FONTE.length]; }
function snapshot(Q){ return { nome:Q.nome || Q.id, midia:clone(Q.midia || {}), palavras:clone(Q.palavras || []), planos:clone(Q.planos || []), notas:clone(Q.notas || []) }; }
function garantirFonte(proj, key, dados){ var k = key || proj; if (!k || k === P.id) return '';
  P.fontes = P.fontes || {}; if (!P.fontes[k] && dados) P.fontes[k] = clone(dados); return P.fontes[k] ? k : ''; }
var MID = {};
function midiaDe(key){
  var m = fonte(key).midia || {}, k = m.video || '';
  if (!MID[k]){
    var v = document.createElement('video'); v.muted = true; v.playsInline = true; v.preload = 'auto'; v.crossOrigin = 'anonymous'; v.hidden = true; if (k) v.src = k; document.body.appendChild(v);
    v.addEventListener('seeked', function(){ if (!tocando) pinta(); }); v.addEventListener('loadeddata', function(){ pinta(); });
    v.addEventListener('loadedmetadata', function(){ if (P) desenhaTimeline(); });
    var av = new Audio(), am = new Audio(); av.preload = am.preload = 'auto'; av.crossOrigin = am.crossOrigin = 'anonymous'; if (m.voz) av.src = m.voz; if (m.musica) am.src = m.musica;
    MID[k] = { v:v, av:av, am:am, voz:!!m.voz, mus:!!m.musica };
    precarregar(k).then(function(b){ if (!b) return; trocaSrc(v, b); if (m.voz === k) trocaSrc(av, b); });
    if (m.voz && m.voz !== k) precarregar(m.voz).then(function(b){ trocaSrc(av, b); });
    if (m.musica) precarregar(m.musica).then(function(b){ trocaSrc(am, b); });
  }
  return MID[k];
}
var CAM = {};
function camadaMid(key){
  var m = fonte(key).midia || {}, k = m.video || '';
  if (!CAM[k]){
    var v = document.createElement('video'); v.muted = true; v.playsInline = true; v.preload = 'auto'; v.crossOrigin = 'anonymous'; v.hidden = true; if (k) v.src = k; document.body.appendChild(v);
    v.addEventListener('seeked', function(){ if (!tocando) pinta(); }); v.addEventListener('loadeddata', function(){ pinta(); });
    var av = new Audio(); av.preload = 'auto'; av.crossOrigin = 'anonymous'; if (m.voz) av.src = m.voz;
    CAM[k] = { v:v, av:av, voz:!!m.voz };
    precarregar(k).then(function(b){ if (!b) return; trocaSrc(v, b); if (m.voz === k) trocaSrc(av, b); });
    if (m.voz && m.voz !== k) precarregar(m.voz).then(function(b){ trocaSrc(av, b); });
  }
  return CAM[k];
}
function pausaTudo(exceto){ Object.keys(MID).forEach(function(k){ var x = MID[k]; if (x === exceto) return; x.v.pause(); x.av.pause(); x.am.pause(); }); if (!exceto) Object.keys(CAM).forEach(function(k){ CAM[k].v.pause(); CAM[k].av.pause(); }); }
function srcAltura(key){ var v = midiaDe(key).v; return v.videoWidth ? ORIG_W * v.videoHeight / v.videoWidth : (fonte(key).midia && fonte(key).midia.alt) || 1080; }
function fonteDur(key){ var m = fonte(key).midia || {}, v = midiaDe(key).v;
  return m.duracao || v.duration || Math.max.apply(null, trechos().filter(function(s){ return (s.de || '') === (key || ''); }).map(function(s){ return s.fim; }).concat([1])); }
function srcH(pl, key){ var a = srcAltura(key); return pl && pl.tarja ? a : Math.min(SRC_H, a); }

// ---------- linha do tempo (a ordem da lista é a ordem do vídeo) ----------
function trechos(){ return (P && P.trechos) || []; }
function mapa(){ var o = 0; return trechos().map(function(s, i){ var r = { ini:s.ini, fim:s.fim, o:o, de:s.de || '', i:i, s:s }; o += s.fim - s.ini; return r; }); }
function durFala(){ return mapa().reduce(function(a, m){ return a + m.fim - m.ini; }, 0); }
function dur(){ return durFala() + (P && P.final && P.final.ativo ? T_FIM : 0); }
function fonteEm(t){ var M = mapa(); for (var i = 0; i < M.length; i++){ var m = M[i]; if (t < m.o + m.fim - m.ini - 1e-6) return { i:i, ts:m.ini + t - m.o, de:m.de, s:m.s, m:m }; } return null; }
function fAgora(){ return fonteEm(Math.min(T, durFala() - 1e-3)); }
function reelEmM(ts, de){ de = de || ''; var M = mapa(); for (var i = 0; i < M.length; i++){ var m = M[i]; if (m.de === de && ts >= m.ini && ts <= m.fim) return { r:m.o + ts - m.ini, m:m }; } return null; }
function reelEm(ts, de){ var a = reelEmM(ts, de); return a ? a.r : null; }
function plano(ts, de){
  var ps = fonte(de).planos || [], melhor = null;
  for (var i = 0; i < ps.length; i++){ if (ts >= ps[i].ini && ts < ps[i].fim) return ps[i]; if (!melhor || Math.abs(ps[i].ini - ts) < Math.abs(melhor.ini - ts)) melhor = ps[i]; }
  if (melhor) return melhor; var f = fonte(de); f.planos = [{ ini:0, fim:1e6, cx:960, larg:ORIG_W, y:0, tarja:true }]; return f.planos[0];
}
function grupos(){
  var est = P.estilo || {}, max = est.palavras_por_grupo || 3, ws = [];
  mapa().forEach(function(m){ if (m.s.limpo) return; (fonte(m.de).palavras || []).forEach(function(w){ if (w.i < m.ini || w.i > m.fim || !String(w.p).trim()) return;
    var ri = m.o + w.i - m.ini; ws.push({ p:String(w.p).trim(), ri:ri, rf:ri + Math.max(.05, Math.min(w.f, m.fim) - w.i), de:m.de, si:w.i }); }); });
  ws.sort(function(a, b){ return a.ri - b.ri; });
  var gs = [], g = [];
  ws.forEach(function(w){
    if (g.length && (g.length >= max || (g.concat([w]).map(function(x){ return x.p; }).join(' ').length > (est.car_vez || 18)) || w.ri - g[g.length - 1].rf > .35)){ gs.push(g); g = []; }
    g.push(w); if (/[.?!,]$/.test(w.p)){ gs.push(g); g = []; }
  });
  if (g.length) gs.push(g); return gs;
}
function limitaRec(r, key){ var a = srcAltura(key); r.w = cl(r.w, 60, ORIG_W); r.h = cl(r.h, 60, a); r.x = cl(r.x, 0, ORIG_W - r.w); r.y = cl(r.y, 0, a - r.h); return r; }
function limita(pl, key){ var J = lay('janela'), sH = srcH(pl, key); pl.larg = cl(pl.larg, 300, ORIG_W); var sh = Math.min(sH, pl.larg * J.h / W);
  pl.cx = cl(pl.cx, pl.larg / 2, ORIG_W - pl.larg / 2); pl.y = cl(pl.y || 0, 0, Math.max(0, sH - sh)); }
// De onde a imagem do corte sai e onde entra na tela. Recorte livre e "tela inteira" valem só para aquele corte.
function geometria(f){
  var J = lay('janela'), s = f.s, r = s.recorte, sx, sy, sw, sh;
  if (r){ limitaRec(r, f.de); sx = r.x; sy = r.y; sw = r.w; sh = r.h; }
  else if (s.cheia){ sx = 0; sy = 0; sw = ORIG_W; sh = srcAltura(f.de); }
  else { var pl = plano(f.ts, f.de), sH = srcH(pl, f.de); sw = cl(pl.larg, 300, ORIG_W); sh = Math.min(sH, sw * J.h / W);
    var cx = cl(pl.cx, sw / 2, ORIG_W - sw / 2); sx = cx - sw / 2; sy = cl(pl.y || 0, 0, Math.max(0, sH - sh)); }
  if (s.cheia){ var e = Math.max(W / sw, H / sh), dw0 = sw * e, dh0 = sh * e; return { sx:sx, sy:sy, sw:sw, sh:sh, dx:(W - dw0) / 2, dy:(H - dh0) / 2, dw:dw0, dh:dh0, cheio:true, tela:true }; }
  var dw = W, dh = sh * W / sw; if (dh > J.h){ dh = J.h; dw = sw * J.h / sh; }
  var cheio = dh >= J.h - 4 && dw >= W - 4, dx = (W - dw) / 2, dy = dh >= J.h - 4 ? J.y : Math.round(Math.max(J.y, J.y + (J.h - dh) / 2 + J.alinhar));
  return { sx:sx, sy:sy, sw:sw, sh:sh, dx:dx, dy:dy, dw:dw, dh:dh, cheio:cheio };
}

// ---------- desenho (prévia e exportação usam o mesmo; caixas só na prévia) ----------
var aux = document.createElement('canvas'), auxc = aux.getContext('2d');
// Fontes que cada texto pode usar (Google Fonts carregadas na página). peso: o mais forte que a fonte tem.
var FONTES = [['inter', 'Inter', 'Inter', 800], ['montserrat', 'Montserrat', 'Montserrat', 800], ['poppins', 'Poppins', 'Poppins', 800], ['sora', 'Sora', 'Sora', 800],
  ['roboto-cond', 'Roboto Condensed', 'Roboto Condensed', 800], ['oswald', 'Oswald', 'Oswald', 700], ['anton', 'Anton', 'Anton', 400], ['bebas', 'Bebas Neue', 'Bebas Neue', 400],
  ['archivo-black', 'Archivo Black', 'Archivo Black', 400], ['playfair', 'Playfair Display', 'Playfair Display', 800], ['marker', 'Permanent Marker', 'Permanent Marker', 400],
  ['lobster', 'Lobster', 'Lobster', 400], ['mono', 'JetBrains Mono', 'JetBrains Mono', 800]];
var FONTE_ATUAL = null;
function fonteDe(id){ for (var i = 0; i < FONTES.length; i++) if (FONTES[i][0] === id) return FONTES[i]; return FONTES[0]; }
function fonteCss(tam, peso){ var f = fonteDe(FONTE_ATUAL); return Math.min(peso || 800, f[3]) + ' ' + Math.round(tam) + 'px "' + f[2] + '", Inter, system-ui, sans-serif'; }
function carregaFonte(id){ var f = fonteDe(id); if (!document.fonts || !document.fonts.load) return Promise.resolve(); return document.fonts.load(f[3] + ' 60px "' + f[2] + '"').catch(function(){}); }
// Antes de exportar e ao abrir um projeto: garante que as fontes usadas já estão carregadas.
function garanteFontes(){ if (!P) return Promise.resolve(); var ids = {}; JSON.stringify(P).replace(/"fonte":"([a-z-]+)"/g, function(_, f){ ids[f] = 1; return _; });
  return Promise.all(Object.keys(ids).map(carregaFonte)).then(function(){ if (P) pinta(); }); }
function selFonte(o, k, hist){ var s = sel(FONTES.map(function(f){ return [f[0], f[1]]; }), o[k] || 'inter');
  Array.prototype.forEach.call(s.options, function(op){ op.style.fontFamily = '"' + fonteDe(op.value)[2] + '"'; });
  s.onchange = function(){ o[k] = s.value; carregaFonte(s.value).then(function(){ recalc(); pinta(); }); pinta(); agendaSalvar(hist); }; return campo('Fonte', s); }
// Contorno e sombra do texto que está sendo desenhado (cada elemento guarda os seus; null = como sempre foi).
var ESTILO_ATUAL = null;
// Fundo colorido atrás do texto: cor e opacidade de cada elemento (padrões = como sempre foi).
function fundoCor(o, corPad, opPad){ return rgba(/^#[0-9a-f]{6}$/i.test((o && o.cor_fundo) || '') ? o.cor_fundo : corPad, o && o.fundo_opac != null && o.fundo_opac !== '' ? +o.fundo_opac : opPad); }
function fundoLinha(c, o, t, x, y, tam, peso, alin){ if (!o || !o.fundo || !t) return; c.font = fonteCss(tam, peso); var w = c.measureText(t).width, x0 = alin === 'center' ? x - w / 2 : x;
  pill(c, x0 - tam * .35, y - tam * .18, w + tam * .7, tam * 1.36, tam * .3, fundoCor(o, '#000000', .6)); }
function bordaDe(o, padrao){ return o && o.contorno != null && o.contorno !== '' ? +o.contorno : padrao; }
function texto(c, t, x, y, tam, peso, cr, borda, alin){
  var E = ESTILO_ATUAL;
  c.font = fonteCss(tam, peso); c.textAlign = alin || 'left'; c.textBaseline = 'top'; c.save();
  if (E && E.sombra){ c.shadowColor = rgba(E.cor_sombra || '#000000', E.sombra_forca == null ? .7 : +E.sombra_forca); c.shadowBlur = E.sombra_desf == null ? 12 : +E.sombra_desf; c.shadowOffsetX = c.shadowOffsetY = E.sombra_dist == null ? 6 : +E.sombra_dist; }
  if (borda){ c.lineJoin = 'round'; c.lineWidth = borda * 2; c.strokeStyle = (E && E.cor_contorno) || '#000'; c.strokeText(t, x, y); c.shadowColor = 'rgba(0,0,0,0)'; }
  c.fillStyle = cr; c.fillText(t, x, y); c.restore(); return c.measureText(t).width;
}
function quebra(c, txt, tam, larg, porLinha, carLinha, peso){ c.font = fonteCss(tam, peso); var ls = [], at = '', n = 0;
  String(txt).split(/\s+/).filter(Boolean).forEach(function(p){ var t = (at + ' ' + p).trim();
    if (at && (c.measureText(t).width > larg || (porLinha > 0 && n >= porLinha) || (carLinha > 0 && t.length > carLinha))){ ls.push(at); at = p; n = 1; } else { at = t; n++; } });
  return ls.concat([at]); }
// Quebra respeitando também as linhas que a pessoa separou com Enter.
function quebraTudo(c, txt, tam, larg, porLinha, carLinha, peso){ var out = []; String(txt).split('\n').forEach(function(l){ out = out.concat(l.trim() ? quebra(c, l, tam, larg, porLinha, carLinha, peso) : ['']); }); return out; }
function caixa(reg, id, x, y, w, h, opc){ if (reg) reg.push({ id:id, x:x, y:y, w:w, h:h, borda:!!(opc && opc.borda), redim:!!(opc && opc.redim) }); }
function pill(c, x, y, w, h, r, cr){ c.fillStyle = cr; c.beginPath(); if (c.roundRect) c.roundRect(x, y, w, h, r); else c.rect(x, y, w, h); c.fill(); }
function noTempo(x, t){ return x.sempre || (t >= x.ini && t <= x.fim); }

function desenha(c, t, reg){
  var G = lay('geral');
  c.fillStyle = G.fundo; c.fillRect(0, 0, W, H);
  var DF = durFala();
  if (t >= DF && P.final && P.final.ativo){ desenhaFim(c, t - DF, reg); return; }
  var f = fonteEm(Math.min(t, DF - 1e-3)); if (!f) return;
  var J = lay('janela'), g = geometria(f), video = midiaDe(f.de).v, k = video.videoWidth ? video.videoWidth / ORIG_W : 1, limpo = !!f.s.limpo;
  if (video.readyState >= 2){
    var filtro = 'brightness(' + G.brilho + ') contrast(' + G.contraste + ') saturate(' + G.saturacao + ')';
    if (!g.cheio && J.preencher === 'desfoque'){
      var A = W / J.h, a = srcAltura(f.de), bh = Math.min(a, Math.max(g.sh, g.sw / A)), bw = Math.min(ORIG_W, bh * A); bh = bw / A;
      var bx = cl(g.sx + g.sw / 2 - bw / 2, 0, ORIG_W - bw), by = cl(g.sy + g.sh / 2 - bh / 2, 0, Math.max(0, a - bh));
      aux.width = 36; aux.height = Math.max(8, Math.round(36 * J.h / W));
      auxc.drawImage(video, bx * k, by * k, bw * k, bh * k, 0, 0, aux.width, aux.height);
      c.imageSmoothingEnabled = true; c.filter = filtro; c.drawImage(aux, 0, J.y, W, J.h); c.filter = 'none';
      c.fillStyle = 'rgba(0,0,0,.55)'; c.fillRect(0, J.y, W, J.h);
    }
    c.filter = filtro; c.drawImage(video, g.sx * k, g.sy * k, g.sw * k, g.sh * k, g.dx, g.dy, g.dw, g.dh); c.filter = 'none';
  }
  caixa(reg, 'janela', Math.max(0, g.dx), Math.max(0, g.dy), Math.min(W, g.dw), Math.min(H, g.dh));
  desenhaSombras(c, t, reg);
  desenhaCamadas(c, t, reg);
  var L = lay('legenda'), S = lay('sombra'), yLeg;
  if (!limpo){
    if (g.dh >= J.h - 4 && !g.tela){
      if (S.visivel){ var gr = c.createLinearGradient(0, J.y + J.h - S.altura, 0, J.y + J.h); gr.addColorStop(0, 'rgba(0,0,0,0)'); gr.addColorStop(1, 'rgba(0,0,0,' + S.opacidade + ')');
        c.fillStyle = gr; c.fillRect(0, J.y + J.h - S.altura, W, S.altura); }
      yLeg = J.y + J.h - 175;
    } else yLeg = g.tela ? J.y + J.h - 175 : Math.min(g.dy + g.dh + 60, J.y + J.h - 175);
    yLeg += L.dy + ((P.estilo && P.estilo.ajuste_y) || 0);
    var tg = P.t_gancho == null ? T_GANCHO_PADRAO : P.t_gancho, GG = lay('gancho_grande');
    if (t < tg && GG.visivel){ c.fillStyle = 'rgba(0,0,0,' + GG.escurecer + ')'; c.fillRect(0, J.y, W, J.h); desenhaGancho(c, true, reg, J); }
    else if (lay('gancho').visivel) desenhaGancho(c, false, reg, J);
    desenhaNotas(c, f, g.dy + 30, reg);
  }
  desenhaImagens(c, t, reg);
  if (!limpo && L.visivel) desenhaLegenda(c, t, yLeg, reg);
  desenhaTextos(c, t, reg);
  if (limpo) return;
  var Ar = lay('arrobas');
  if (Ar.visivel && Ar.texto){ var faA = FONTE_ATUAL; FONTE_ATUAL = Ar.fonte || null; ESTILO_ATUAL = Ar; var tam = 36 * Ar.escala, lhA = tam * (Ar.entrelinha || 1.2);
    var lsA = quebraTudo(c, Ar.texto, tam, Ar.largura || 1e9, Ar.por_linha || 0, Ar.car_linha || 0, 700), w = 0;
    c.font = fonteCss(tam, 700); lsA.forEach(function(l){ w = Math.max(w, c.measureText(l).width); });
    var thA = lsA.length * lhA, hbA = Math.max(thA, +Ar.altura || 0), topA = Ar.y + thA / 2 - hbA / 2;
    if (Ar.fundo && hbA > thA + 1) pill(c, Ar.x - w / 2 - tam * .35, topA - tam * .18, w + tam * .7, hbA + tam * .36, tam * .3, fundoCor(Ar, '#000000', .6));
    lsA.forEach(function(l, n){ if (!(Ar.fundo && hbA > thA + 1)) fundoLinha(c, Ar, l, Ar.x, Ar.y + n * lhA, tam, 700, 'center'); texto(c, l, Ar.x, Ar.y + n * lhA, tam, 700, Ar.cor, bordaDe(Ar, 0), 'center'); });
    FONTE_ATUAL = faA; ESTILO_ATUAL = null; var lbA = Math.max(w, Ar.largura ? Math.min(W, Ar.largura) : 0); caixa(reg, 'arrobas', Ar.x - lbA / 2, topA, lbA, hbA, { redim:true }); }
  var LG = lay('logo'), lo = logoAtual();
  if (LG.visivel && lo.complete && lo.naturalWidth){ var lh = LG.largura * lo.naturalHeight / lo.naturalWidth; c.drawImage(lo, LG.x - LG.largura / 2, LG.y, LG.largura, lh); caixa(reg, 'logo', LG.x - LG.largura / 2, LG.y, LG.largura, lh); }
  var B = lay('barra');
  if (B.visivel){ c.fillStyle = B.cor; c.fillRect(0, B.embaixo ? H - B.altura : 0, W * Math.min(1, t / dur()), B.altura); }
}
function desenhaSombras(c, t, reg){
  (P.sombras || []).forEach(function(s, i){
    if (!noTempo(s, t)) return;
    var a = s.forca, cr = s.cor || '#000000';
    c.save(); c.beginPath(); c.rect(s.x, s.y, s.w, s.h); c.clip();
    if (s.tipo === 'vinheta'){
      c.translate(s.x + s.w / 2, s.y + s.h / 2); c.scale(s.w / 2, s.h / 2);
      var gv = c.createRadialGradient(0, 0, s.miolo == null ? .4 : s.miolo, 0, 0, 1.42); gv.addColorStop(0, rgba(cr, 0)); gv.addColorStop(1, rgba(cr, a));
      c.fillStyle = gv; c.fillRect(-1, -1, 2, 2);
    } else if (s.tipo === 'gradiente'){
      var d = s.dir || 'baixo', x0 = s.x, y0 = s.y, x1 = s.x, y1 = s.y;
      if (d === 'baixo') y1 = s.y + s.h; else if (d === 'cima') y0 = s.y + s.h; else if (d === 'dir') x1 = s.x + s.w; else x0 = s.x + s.w;
      var gl = c.createLinearGradient(x0, y0, x1, y1); gl.addColorStop(0, rgba(cr, 0)); gl.addColorStop(1, rgba(cr, a)); c.fillStyle = gl; c.fillRect(s.x, s.y, s.w, s.h);
    } else { c.fillStyle = rgba(cr, a); c.fillRect(s.x, s.y, s.w, s.h); }
    c.restore();
    caixa(reg, 'sombra:' + i, s.x, s.y, s.w, s.h, { borda:true, redim:true });
  });
}
function desenhaImagens(c, t, reg){
  (P.imagens || []).forEach(function(m, i){
    if (!noTempo(m, t)) return; var im = imgDe(m.url); if (!im.complete || !im.naturalWidth) return;
    var w = m.w, h = w * im.naturalHeight / im.naturalWidth, x = m.x - w / 2, y = m.y - h / 2;
    c.save(); c.globalAlpha = m.opacidade == null ? 1 : m.opacidade;
    if (m.raio){ c.beginPath(); if (c.roundRect) c.roundRect(x, y, w, h, m.raio); else c.rect(x, y, w, h); c.clip(); }
    c.drawImage(im, x, y, w, h); c.restore();
    caixa(reg, 'imagem:' + i, x, y, w, h, { redim:true });
  });
}
function desenhaGancho(c, grande, reg, J){
  var g = grande ? lay('gancho_grande') : lay('gancho'), e = g.escala, fa = FONTE_ATUAL; FONTE_ATUAL = g.fonte || null; ESTILO_ATUAL = g;
  var tam = (grande ? 92 : 58) * e, alt = tam * (g.entrelinha || (grande ? 1.13 : 1.17)), dest = (P.destaque || []).map(function(x){ return x.toLowerCase(); });
  var ls = quebraTudo(c, (P.gancho || '').toUpperCase(), tam, g.largura || W - 120, g.por_linha || 0, g.car_linha || 0), corD = g.cor || lay('gancho').cor, corT = g.cor_texto || COR.branco;
  var cxm = grande ? W / 2 + g.dx : g.x, y = grande ? J.y + 330 + g.dy - ls.length * alt / 2 : g.y - ls.length * alt / 2, y0 = y, wmax = 0;
  var thG = ls.length * alt, hbG = Math.max(thG, +g.altura || 0), topG = y + thG / 2 - hbG / 2;
  if (g.fundo){ c.font = fonteCss(tam); var wf = Math.max.apply(null, ls.map(function(l){ return c.measureText(l).width; }).concat([0])); if (hbG > thG + 1) wf = Math.max(wf, Math.min(W, g.largura || wf) - tam * .8); pill(c, cxm - wf / 2 - tam * .4, topG - tam * .2, wf + tam * .8, hbG + tam * .25, tam * .35, fundoCor(g, '#000000', .6)); }
  ls.forEach(function(ln){
    c.font = fonteCss(tam); var lw = c.measureText(ln).width, x = cxm - lw / 2; wmax = Math.max(wmax, lw);
    ln.split(' ').forEach(function(p){ var limpa = p.replace(/[.,?!:]/g, '').toLowerCase();
      texto(c, p, x, y, tam, 800, dest.indexOf(limpa) >= 0 || dest.indexOf(p.toLowerCase()) >= 0 ? corD : corT, bordaDe(g, grande ? 7 : 5) * e);
      x += c.measureText(p + ' ').width; });
    y += alt;
  });
  var lb = Math.max(wmax, Math.min(W, g.largura || wmax)); caixa(reg, grande ? 'gancho_grande' : 'gancho', cxm - lb / 2, topG, lb, hbG, { redim:true }); FONTE_ATUAL = fa; ESTILO_ATUAL = null;
}
function desenhaNotas(c, f, y, reg){
  var N = lay('notas'), tam = 46 * N.escala, fa = FONTE_ATUAL; FONTE_ATUAL = N.fonte || null; ESTILO_ATUAL = N;
  (fonte(f.de).notas || []).forEach(function(n){ if (f.ts < n.ini || f.ts > n.fim) return;
    c.font = fonteCss(tam); var l = c.measureText(n.texto).width, pw = Math.max(l + 56 * N.escala, +N.largura || 0), ph = Math.max(76 * N.escala, +N.altura || 0), x0 = W / 2 + N.dx - pw / 2, yy = y + N.dy;
    if (N.fundo !== false) pill(c, x0, yy, pw, ph, Math.min(38 * N.escala, ph / 2), fundoCor(N, '#0f0f0f', 1));
    texto(c, n.texto, W / 2 + N.dx, yy + (ph - tam) / 2 - N.escala, tam, 800, cor(n.cor), bordaDe(N, 0), 'center');
    caixa(reg, 'notas', x0, yy, pw, ph, { redim:true }); });
  FONTE_ATUAL = fa; ESTILO_ATUAL = null;
}
function desenhaLegenda(c, t, y, reg){
  var gs = GRUPOS || [], gi = -1;
  for (var k = 0; k < gs.length; k++){ var g = gs[k], fim = k + 1 < gs.length ? gs[k + 1][0].ri - .05 : g[g.length - 1].rf + .4; if (t >= g[0].ri - .05 && t <= fim) gi = k; }
  if (gi < 0) return;
  var est = P.estilo || {}, L = lay('legenda'), cores = (est.cores && est.cores.length ? est.cores : ['amarelo', 'verde']), tam = est.tamanho || 84, tg = Math.round(tam * (est.aumento || 1.1)), fa = FONTE_ATUAL; FONTE_ATUAL = est.fonte || null; ESTILO_ATUAL = L;
  var g = gs[gi], cr = cor(cores[gi % cores.length]), maius = est.maiusculas !== false, base = est.cor_base || COR.branco;
  var ps = g.map(function(w){ return maius ? w.p.toUpperCase() : w.p; }), ativa = 0;
  g.forEach(function(w, i){ if (w.ri <= t + .03) ativa = i; });
  function larg(idx){ var s = 0; idx.forEach(function(i, n){ c.font = fonteCss(i === ativa ? tg : tam); s += c.measureText(ps[i]).width + (n ? c.measureText(' ').width : 0); }); return s; }
  var linhas = [], at = [], LW = L.largura || W - 80, PL = L.por_linha || 0, CL = L.car_linha || 0;
  ps.forEach(function(_, i){ var t = at.concat([i]), txt = t.map(function(j){ return ps[j]; }).join(' ');
    if (at.length && (larg(t) > LW || (PL > 0 && at.length >= PL) || (CL > 0 && txt.length > CL))){ linhas.push(at); at = [i]; } else at = t; });
  if (at.length) linhas.push(at);
  if (!PL && !CL && linhas.length === 2){ var todas = ps.map(function(_, i){ return i; }), meio = Math.max(1, ps.length >> 1); linhas = [todas.slice(0, meio), todas.slice(meio)]; }
  var y0 = y, xmin = W, xmax = 0, lh = Math.round(tam * (L.entrelinha || 1.31)), cxm = W / 2 + L.dx;
  var thL = linhas.length * lh, hbL = Math.max(thL, +L.altura || 0), topL = y + thL / 2 - hbL / 2;
  if (L.fundo){ var wl = Math.max.apply(null, linhas.map(larg)); if (hbL > thL + 1) wl = Math.max(wl, Math.min(W, LW) - 52); pill(c, cxm - wl / 2 - 26, topL - 14, wl + 52, hbL + 14, 22, fundoCor(L, '#000000', .72)); }
  linhas.forEach(function(ln){
    var lw = larg(ln), x = cxm - lw / 2; xmin = Math.min(xmin, x); xmax = Math.max(xmax, x + lw);
    ln.forEach(function(i){ var tt = i === ativa ? tg : tam;
      texto(c, ps[i], x, y - (i === ativa ? 6 : 0), tt, 800, i === ativa ? cr : base, L.borda);
      c.font = fonteCss(tt); x += c.measureText(ps[i] + ' ').width; });
    y += lh;
  });
  var lbl = Math.max(xmax - xmin, Math.min(W, LW)); caixa(reg, 'legenda', cxm - lbl / 2, topL - 8, lbl, hbL, { redim:true }); FONTE_ATUAL = fa; ESTILO_ATUAL = null;
}
function desenhaTextos(c, t, reg){
  var fa = FONTE_ATUAL;
  (P.textos || []).forEach(function(x, i){ if (!noTempo(x, t) || !x.texto) return; FONTE_ATUAL = x.fonte || null; ESTILO_ATUAL = x;
    var tam = x.tam || 60, lh = tam * (x.entrelinha || 1.2), wmax = 0, ls = quebraTudo(c, x.texto, tam, x.largura || 1e9, x.por_linha || 0, x.car_linha || 0, x.peso || 800);
    c.font = fonteCss(tam, x.peso || 800); ls.forEach(function(l){ wmax = Math.max(wmax, c.measureText(l).width); });
    var y0 = x.y - ls.length * lh / 2, thT = ls.length * lh, hbT = Math.max(thT, +x.altura || 0), topT = x.y - hbT / 2, wfT = hbT > thT + 1 ? Math.max(wmax, Math.min(W, x.largura || wmax) - tam * .8) : wmax;
    if (x.fundo) pill(c, x.x - wfT / 2 - tam * .4, topT - tam * .2, wfT + tam * .8, hbT + tam * .3, tam * .35, fundoCor(x, '#0f0f0f', .88));
    ls.forEach(function(l, n){ texto(c, l, x.x, y0 + n * lh, tam, x.peso || 800, x.cor || COR.branco, bordaDe(x, x.borda ? 5 : 0), 'center'); });
    var lbt = Math.max(wmax, x.largura ? Math.min(W, x.largura) : 0); caixa(reg, 'texto:' + i, x.x - lbt / 2, topT, lbt, hbT, { redim:true }); });
  FONTE_ATUAL = fa; ESTILO_ATUAL = null;
}
function desenhaFim(c, t, reg){
  c.globalAlpha = Math.min(1, t / .35);
  var vert = H / W > 1.5, e = vert ? 1 : Math.min(W, H) / 1920 * 1.6, cy = vert ? 0 : (H - 1920 * e) / 2, lo = logoAtual();
  if (lo.complete && lo.naturalWidth){ var lw = 520 * e, lh = lw * lo.naturalHeight / lo.naturalWidth; c.drawImage(lo, (W - lw) / 2, cy + 560 * e, lw, lh); }
  var fi = P.final || {}, fa = FONTE_ATUAL, bf = bordaDe(fi, 0), fs = +fi.escala || 1; FONTE_ATUAL = fi.fonte || null; ESTILO_ATUAL = fi;
  fundoLinha(c, fi, fi.chamada || 'SIGA PARA MAIS DICAS', W / 2, cy + 790 * e, 64 * e * fs, 800, 'center'); texto(c, fi.chamada || 'SIGA PARA MAIS DICAS', W / 2, cy + 790 * e, 64 * e * fs, 800, fi.cor_chamada || COR.branco, bf, 'center');
  fundoLinha(c, fi, fi.linha1 || '@luiznunest', W / 2, cy + 900 * e, 96 * e * fs, 800, 'center'); texto(c, fi.linha1 || '@luiznunest', W / 2, cy + 900 * e, 96 * e * fs, 800, fi.cor_linha1 || COR.branco, bf, 'center');
  fundoLinha(c, fi, fi.linha2 || '@intusfit', W / 2, cy + 1020 * e, 96 * e * fs, 800, 'center'); texto(c, fi.linha2 || '@intusfit', W / 2, cy + 1020 * e, 96 * e * fs, 800, fi.cor_linha2 || COR.verde, bf, 'center');
  String(fi.slogan || '').split(/(?<=\.)\s+/).forEach(function(l, i){ fundoLinha(c, fi, l, W / 2, cy + (1180 + i * 65) * e, 46 * e * fs, 700, 'center'); texto(c, l, W / 2, cy + (1180 + i * 65) * e, 46 * e * fs, 700, fi.cor_slogan || '#d7d7d7', bf, 'center'); });
  c.globalAlpha = 1; FONTE_ATUAL = fa; ESTILO_ATUAL = null; caixa(reg, 'final', 40, cy + 540 * e, W - 80, 820 * e);
}

// ---------- guia por cima da prévia (nunca sai no vídeo) ----------
function pintaGuia(){
  gctx.clearRect(0, 0, W, H);
  var F = formato();
  if (mostrarIG && F.guia){
    gctx.save(); gctx.setLineDash([14, 10]); gctx.lineWidth = 3; gctx.strokeStyle = 'rgba(255,92,92,.95)';
    var areas = F.guia === 'story' ? [[0, 0, W, 210, 'perfil · tempo do story'], [0, H - 230, W, 230, 'responder · reações']]
      : [[0, 0, W, 160, 'Reels · câmera'], [948, 1040, 120, 700, ''], [24, 1560, 900, 210, 'perfil · legenda · música'], [0, 1800, W, 120, 'barra do app']];
    areas.forEach(function(a){
      gctx.fillStyle = 'rgba(255,92,92,.10)'; gctx.fillRect(a[0], a[1], a[2], a[3]); gctx.strokeRect(a[0] + 1.5, a[1] + 1.5, a[2] - 3, a[3] - 3);
      if (a[4]){ gctx.font = '600 30px Inter, sans-serif'; gctx.fillStyle = 'rgba(255,150,150,1)'; gctx.textBaseline = 'top'; gctx.textAlign = 'left'; gctx.fillText(a[4], a[0] + 16, a[1] + 14); }
    });
    if (F.guia === 'reel'){
      gctx.setLineDash([]); gctx.fillStyle = 'rgba(255,255,255,.6)'; gctx.font = '600 24px Inter, sans-serif'; gctx.textAlign = 'center'; gctx.textBaseline = 'top';
      ['curtir', 'comentar', 'enviar', 'salvar', 'áudio'].forEach(function(n, i){ var y = 1080 + i * 128;
        gctx.beginPath(); gctx.arc(1008, y + 30, 30, 0, Math.PI * 2); gctx.strokeStyle = 'rgba(255,255,255,.6)'; gctx.lineWidth = 4; gctx.stroke(); gctx.fillText(n, 1008, y + 68); });
    }
    gctx.restore();
  }
  var b = selEl && CAIXAS.filter(function(x){ return x.id === selEl; })[0];
  if (b){ var esc = W / 1080; gctx.save(); gctx.setLineDash([12, 8]); gctx.lineWidth = 4 * esc; gctx.strokeStyle = '#7fff00'; gctx.strokeRect(b.x - 10, b.y - 10, b.w + 20, b.h + 20);
    gctx.setLineDash([]);
    if (b.redim){ gctx.fillStyle = '#7fff00'; gctx.fillRect(b.x + b.w - 26, b.y + b.h - 26, 36, 36); }
    var nm = NOMES[b.id.split(':')[0]] || b.id; gctx.font = '700 ' + Math.round(28 * esc) + 'px Inter, sans-serif'; gctx.textAlign = 'left'; var lw = gctx.measureText(nm).width + 24;
    var lx = cl(b.x - 10, 0, W - lw), ly = b.y - 52 < 0 ? Math.min(b.y + 14, H - 52) : b.y - 52;
    gctx.fillStyle = '#7fff00'; gctx.fillRect(lx, ly, lw, 40 * esc); gctx.fillStyle = '#0a0b09'; gctx.textBaseline = 'top'; gctx.fillText(nm, lx + 12, ly + 6 * esc); gctx.restore(); }
  pintaImas();
}
$('b-ig').onclick = function(){ mostrarIG = !mostrarIG; try { localStorage.setItem('intuscut-ig', mostrarIG ? '1' : '0'); } catch (e) {} marcaIG(); pintaGuia(); };
function marcaIG(){ $('b-ig').classList.toggle('on', mostrarIG); }

// ---------- arrastar e redimensionar direto na prévia ----------
function ponto(e){ var r = guia.getBoundingClientRect(); return { x:(e.clientX - r.left) / r.width * W, y:(e.clientY - r.top) / r.height * H }; }
function dentro(p, b, m){ return p.x >= b.x - m && p.x <= b.x + b.w + m && p.y >= b.y - m && p.y <= b.y + b.h + m; }
function acha(p){
  for (var i = CAIXAS.length - 1; i >= 0; i--){ var b = CAIXAS[i];
    if (!dentro(p, b, 14)) continue;
    // Sombra só pega pela borda (para não roubar o clique do vídeo), a não ser que já esteja selecionada.
    if (b.borda && selEl !== b.id && b.w > 120 && b.h > 120 && p.x > b.x + 40 && p.x < b.x + b.w - 40 && p.y > b.y + 40 && p.y < b.y + b.h - 40) continue;
    return b; }
  return null;
}
function noCanto(p, b){ return b.redim && p.x > b.x + b.w - 50 && p.y > b.y + b.h - 50; }
function alvo(id){
  var t = id.split(':'), n = +t[1];
  if (t[0] === 'texto') return (P.textos || [])[n];
  if (t[0] === 'sombra') return (P.sombras || [])[n];
  if (t[0] === 'imagem') return (P.imagens || [])[n];
  if (t[0] === 'camada') return (P.camadas || [])[n];
  if (id === 'janela'){ var f = fAgora(); return f ? (f.s.recorte || plano(f.ts, f.de)) : null; }
  return lay(id);
}
// Ímã do enquadramento: ao arrastar, a caixa gruda no centro da tela, nos terços, na margem de segurança e nas bordas
// e centros dos outros elementos, e as linhas aparecem enquanto o dedo estiver na tela. Segurar Alt desliga o ímã.
function ima(a, dx, dy){
  var r = guia.getBoundingClientRect(), tol = Math.max(6, 9 * W / (r.width || W)), b = a.b0, m = Math.round(W * .055), g = [];
  var alvosX = [[W / 2, 'centro'], [W / 3, 'terço'], [W * 2 / 3, 'terço'], [m, 'margem'], [W - m, 'margem']],
      alvosY = [[H / 2, 'centro'], [H / 3, 'terço'], [H * 2 / 3, 'terço'], [m, 'margem'], [H - m, 'margem']];
  a.outros.forEach(function(o){ [o.x, o.x + o.w / 2, o.x + o.w].forEach(function(v){ alvosX.push([v, 'elemento']); }); [o.y, o.y + o.h / 2, o.y + o.h].forEach(function(v){ alvosY.push([v, 'elemento']); }); });
  function melhor(pontos, alvos){ var mel = null; pontos.forEach(function(pt){ alvos.forEach(function(al){ var d = al[0] - pt; if (Math.abs(d) <= tol && (!mel || (al[1] === 'centro' ? Math.abs(d) <= Math.abs(mel.d) + 3 : mel.tipo !== 'centro' && Math.abs(d) < Math.abs(mel.d)) || (mel.tipo === 'centro' && al[1] !== 'centro' && Math.abs(d) + 3 < Math.abs(mel.d)))) mel = { d:d, v:al[0], tipo:al[1] }; }); }); return mel; }
  var bx = b.x + dx, by = b.y + dy, mx = melhor([bx, bx + b.w / 2, bx + b.w], alvosX), my = melhor([by, by + b.h / 2, by + b.h], alvosY);
  if (mx){ dx += Math.round(mx.d); g.push({ x:mx.v, tipo:mx.tipo }); }
  if (my){ dy += Math.round(my.d); g.push({ y:my.v, tipo:my.tipo }); }
  var chave = g.map(function(q){ return (q.x != null ? 'x' + q.x : 'y' + q.y); }).join();
  if (chave && chave !== a.ultIma && navigator.vibrate) try { navigator.vibrate(8); } catch (e) {}
  a.ultIma = chave; a.caixaAgora = { x:b.x + dx, y:b.y + dy, w:b.w, h:b.h };
  return { dx:dx, dy:dy, guias:g };
}
function pintaImas(){
  if (!arr || !arr.mexeu || arr.redim || arr.id === 'janela' || arr.id === 'final') return;
  var r = guia.getBoundingClientRect(), esc = W / (r.width || W); gctx.save();
  gctx.setLineDash([4 * esc, 5 * esc]); gctx.lineWidth = 1 * esc; gctx.strokeStyle = 'rgba(255,255,255,.35)';
  gctx.beginPath(); gctx.moveTo(W / 2, 0); gctx.lineTo(W / 2, H); gctx.moveTo(0, H / 2); gctx.lineTo(W, H / 2); gctx.stroke();
  gctx.setLineDash([]); gctx.lineWidth = 1.5 * esc; gctx.font = '700 ' + Math.round(10 * esc) + 'px Inter, sans-serif'; gctx.textBaseline = 'top';
  (arr.guias || []).forEach(function(q){
    var cor = q.tipo === 'centro' ? '#ff3df2' : q.tipo === 'elemento' ? '#3db8ff' : '#ffb020'; gctx.strokeStyle = cor; gctx.fillStyle = cor;
    gctx.beginPath(); if (q.x != null){ gctx.moveTo(q.x, 0); gctx.lineTo(q.x, H); } else { gctx.moveTo(0, q.y); gctx.lineTo(W, q.y); } gctx.stroke();
    var rot = q.tipo === 'elemento' ? 'alinhado' : q.tipo; gctx.textAlign = 'left';
    if (q.x != null) gctx.fillText(rot, Math.min(q.x + 4 * esc, W - 60 * esc), 4 * esc); else gctx.fillText(rot, 4 * esc, Math.max(4, q.y - 14 * esc));
  });
  gctx.restore();
}
var arr = null, ultToque = { t:0, id:'' };
guia.addEventListener('pointerdown', function(e){
  if (!P) return; var p = ponto(e), b = acha(p);
  var agora = Date.now(); if (b && b.id === ultToque.id && temTexto(b.id) && agora - ultToque.t < 450){ ultToque = { t:0, id:'' }; editarTextoDe(b.id); return; } ultToque = { t:agora, id:b ? b.id : '' }; selEl = b ? b.id : null; pintaGuia();
  if (!b){ inspetor(); desenhaTimeline(); return; }
  guia.setPointerCapture(e.pointerId); var o = alvo(b.id), f = fAgora();
  arr = { id:b.id, p:p, o:o, ini:clone(o), jy:lay('janela').y, shift:e.shiftKey, mexeu:false, livre:!!(f && f.s.recorte), geo:f ? geometria(f) : null, de:f ? f.de : '', redim:noCanto(p, b),
    b0:{ x:b.x, y:b.y, w:b.w, h:b.h }, outros:CAIXAS.filter(function(x){ return x.id !== b.id && x.id !== 'janela' && x.id !== 'final'; }).map(function(x){ return { x:x.x, y:x.y, w:x.w, h:x.h }; }), guias:[] };
  seleciona();
});
guia.addEventListener('pointermove', function(e){
  if (!arr){ if (P){ var pp = ponto(e), b = acha(pp); guia.style.cursor = b ? (selEl === b.id && noCanto(pp, b) ? 'nwse-resize' : 'move') : 'default'; } return; }
  if (!arr.o) return; var p = ponto(e), dx = Math.round(p.x - arr.p.x), dy = Math.round(p.y - arr.p.y), o = arr.o, i = arr.ini;
  if (Math.abs(dx) + Math.abs(dy) > 2) arr.mexeu = true;
  arr.guias = [];
  if (!arr.redim && arr.id !== 'janela' && arr.id !== 'final' && !e.altKey){ var im = ima(arr, dx, dy); dx = im.dx; dy = im.dy; arr.guias = im.guias; }
  switch (arr.id.split(':')[0]){
    case 'janela':
      if (arr.shift){ lay('janela').y = arr.jy + dy; break; }
      var k = arr.geo.sw / arr.geo.dw;
      if (arr.livre){ o.x = Math.round(i.x - dx * k); o.y = Math.round(i.y - dy * k); limitaRec(o, arr.de); }
      else { o.cx = Math.round(i.cx - dx * k); o.y = Math.round((i.y || 0) - dy * k); limita(o, arr.de); }
      break;
    case 'sombra':
      if (arr.redim){ o.w = Math.max(40, i.w + dx); o.h = Math.max(40, i.h + dy); } else { o.x = i.x + dx; o.y = i.y + dy; } break;
    case 'imagem':
      if (arr.redim){ o.w = Math.max(30, i.w + dx * 2); } else { o.x = i.x + dx; o.y = i.y + dy; } break;
    case 'camada':
      if (arr.redim){ o.w = Math.max(60, i.w + dx); o.h = o.forma === 'circulo' ? o.w : Math.max(60, i.h + dy); } else { o.x = i.x + dx; o.y = i.y + dy; } break;
    case 'legenda': if (arr.redim && !arr.shift){ o.largura = Math.round(cl((i.largura || W - 80) + dx * 2, 160, W)); o.altura = altDe(dy); break; } if (arr.redim){ o.largura = Math.round(cl((i.largura || W - 80) + dx * 2, 160, W)); P.estilo = P.estilo || {}; if (arr.tam0 == null) arr.tam0 = P.estilo.tamanho || 84; P.estilo.tamanho = Math.round(cl(arr.tam0 + dy / 3, 30, 160)); recalc(); break; } o.dx = i.dx + dx; o.dy = i.dy + dy; break;
    case 'gancho': case 'gancho_grande': if (arr.redim && !arr.shift){ o.largura = Math.round(cl((i.largura || W - 120) + dx * 2, 160, W)); o.altura = altDe(dy); break; } if (arr.redim){ o.largura = Math.round(cl((i.largura || W - 120) + dx * 2, 160, W)); o.escala = +cl((i.escala || 1) + dy / 300, .3, 3).toFixed(2); break; }
      if (arr.id === 'gancho'){ o.x = i.x + dx; o.y = i.y + dy; } else { o.dx = i.dx + dx; o.dy = i.dy + dy; } break;
    case 'texto': if (arr.redim && !arr.shift){ o.largura = Math.round(cl((i.largura || arr.b0.w || 600) + dx * 2, 120, W)); o.altura = altDe(dy); break; } if (arr.redim){ o.largura = Math.round(cl((i.largura || 600) + dx * 2, 120, W)); o.tam = Math.round(cl((i.tam || 60) + dy / 3, 16, 240)); break; } o.x = i.x + dx; o.y = i.y + dy; break;
    case 'notas': if (arr.redim && !arr.shift){ o.largura = Math.round(cl(arr.b0.w + dx * 2, 40, W)); o.altura = altDe(dy); break; } if (arr.redim){ o.escala = +cl((i.escala || 1) + dy / 300, .3, 3).toFixed(2); break; } o.dx = i.dx + dx; o.dy = i.dy + dy; break;
    case 'arrobas': if (arr.redim && !arr.shift){ o.largura = Math.round(cl((i.largura || arr.b0.w) + dx * 2, 80, W)); o.altura = altDe(dy); break; } if (arr.redim){ o.escala = +cl((i.escala || 1) + dy / 300, .3, 4).toFixed(2); break; } o.x = i.x + dx; o.y = i.y + dy; break;
    case 'final': break;
    default: o.x = i.x + dx; o.y = i.y + dy;
  }
  pinta();
  // Altura da caixa: o canto desce e a caixa cresce para cima e para baixo (o texto fica no meio).
  function altDe(d){ return Math.round(cl(arr.b0.h + d * 2, 0, H)); }
});
guia.addEventListener('pointerup', function(){ var tinha = arr && arr.guias && arr.guias.length; if (tinha){ arr.guias = []; } if (arr && arr.mexeu){ var nm = NOMES[arr.id.split(':')[0]];
  agendaSalvar(arr.redim ? 'Redimensionar ' + nm.toLowerCase() : (arr.id === 'janela' && !arr.shift ? 'Enquadrar ' : 'Mover ') + nm.toLowerCase()); inspetor(); } arr = null; if (tinha) pintaGuia(); });
guia.addEventListener('wheel', function(e){
  if (!P) return; var b = acha(ponto(e)); if (!b) return; e.preventDefault(); selEl = b.id;
  var s = e.deltaY < 0 ? 1 : -1, o = alvo(b.id), id = b.id.split(':')[0], f = fAgora(); if (!o) return;
  if (id === 'janela'){
    var fat = s > 0 ? .95 : 1.05;
    if (f.s.recorte){ var cx = o.x + o.w / 2, cy = o.y + o.h / 2; o.w *= fat; o.h *= fat; o.x = cx - o.w / 2; o.y = cy - o.h / 2; limitaRec(o, f.de); ['x', 'y', 'w', 'h'].forEach(function(q){ o[q] = Math.round(o[q]); }); }
    else { o.larg = Math.round(cl(o.larg * fat, 300, ORIG_W)); limita(o, f.de); }
  }
  else if (id === 'legenda'){ P.estilo = P.estilo || {}; P.estilo.tamanho = cl((P.estilo.tamanho || 84) + s * 2, 40, 140); }
  else if (id === 'logo') o.largura = cl(o.largura + s * 10, 60, 900);
  else if (id === 'texto') o.tam = cl((o.tam || 60) + s * 3, 20, 220);
  else if (id === 'imagem') o.w = Math.round(cl(o.w * (s > 0 ? 1.05 : .95), 30, W * 2));
  else if (id === 'camada'){ var fz = s > 0 ? 1.05 : .95, mx2 = o.x + o.w / 2, my2 = o.y + o.h / 2; o.w = Math.round(o.w * fz); o.h = Math.round(o.h * fz); o.x = Math.round(mx2 - o.w / 2); o.y = Math.round(my2 - o.h / 2); }
  else if (id === 'sombra'){ var f2 = s > 0 ? 1.05 : .95, mx = o.x + o.w / 2, my = o.y + o.h / 2; o.w = Math.round(o.w * f2); o.h = Math.round(o.h * f2); o.x = Math.round(mx - o.w / 2); o.y = Math.round(my - o.h / 2); }
  else if ('escala' in o) o.escala = cl(+(o.escala + s * .04).toFixed(2), .3, 3);
  recalc(); pinta(); agendaSalvar('Tamanho: ' + (NOMES[id] || id).toLowerCase());
}, { passive:false });

// ---------- reprodução ----------
var GRUPOS = [], relogio = 0, trechoAtual = -1;
function recalc(){ GRUPOS = P ? grupos() : []; }
function sinc(M, ts, forcar){ [[M.av, M.voz], [M.am, M.mus]].forEach(function(x){ var a = x[0]; if (!x[1]) return; if (forcar || Math.abs(a.currentTime - ts) > .15){ try { a.currentTime = ts; } catch (e) {} } }); }
function irPara(t){
  T = cl(t, 0, Math.max(0, dur() - 1 / FPS));
  var f = fAgora();
  if (f){ var M = midiaDe(f.de); if (Math.abs(M.v.currentTime - f.ts) > .04) M.v.currentTime = f.ts; sinc(M, f.ts, true); }
  sincCamadas(false); pinta();
}
function volumes(){ var au = P.audio || {}; Object.keys(MID).forEach(function(k){ MID[k].av.volume = Math.min(1, au.voz == null ? 1 : au.voz); MID[k].am.volume = Math.min(1, au.musica == null ? .14 : au.musica); }); }
function tocar(){ if (!P) return; if (T >= dur() - .05) irPara(0); tocando = true; trechoAtual = -1; $('b-play').textContent = 'Pausar'; relogio = performance.now() - T * 1000; requestAnimationFrame(passo); }
function pausar(){ tocando = false; $('b-play').textContent = 'Reproduzir'; pausaTudo(); }
function passo(){
  if (!tocando) return;
  T = (performance.now() - relogio) / 1000;
  if (T >= dur()){ pausar(); irPara(dur() - 1 / FPS); return; }
  var f = fonteEm(Math.min(T, durFala() - 1e-3));
  if (T >= durFala()) pausaTudo();
  else if (f){
    var M = midiaDe(f.de); volumes();
    if (f.i !== trechoAtual || Math.abs(M.v.currentTime - f.ts) > .25){ pausaTudo(M); M.v.currentTime = f.ts; sinc(M, f.ts, true); trechoAtual = f.i; }
    else sinc(M, f.ts, false);
    if (M.v.paused) M.v.play().catch(function(){});
    [[M.av, M.voz], [M.am, M.mus]].forEach(function(x){ if (x[1] && x[0].paused) x[0].play().catch(function(){}); });
  }
  sincCamadas(true); pinta(); requestAnimationFrame(passo);
}
function pinta(){
  if (!P) return; CAIXAS = []; desenha(ctx, T, CAIXAS); pintaGuia();
  $('tc').textContent = fmt(T) + ' / ' + fmt(dur());
  var p = pps(), cab = $('tlint').querySelector(':scope > .cabeca');
  if (cab){ cab.style.left = (T * p) + 'px'; if (tocando){ var r = $('rolo'), x = T * p; if (x < r.scrollLeft || x > r.scrollLeft + r.clientWidth - 30) r.scrollLeft = Math.max(0, x - 60); } }
  var fb = $('fonteb'); if (fb){ var f = fAgora(), fk = fonteBarKey(), fc = fb.querySelector('.cabeca'); if (fc){ fc.hidden = !(f && f.de === fk); if (f && f.de === fk) fc.style.left = (f.ts / fonteDur(fk) * 100) + '%'; } }
  pintaFonte();
  if (aba === 'legenda') marcaPalavra(fAgora());
}

// ---------- linha do tempo em faixas ----------
function pps(){ var w = Math.max(200, $('rolo').clientWidth - 70); return Math.max(1, w / Math.max(dur(), 1)) * zoomTL; }
function itemTempo(x){ return x.sempre ? { ini:0, fim:dur() } : { ini:x.ini || 0, fim:x.fim || 0 }; }
function faixas(){ return [['camada', 'Sobrepor', P.camadas || []], ['texto', 'Texto', P.textos || []], ['imagem', 'Imagem', P.imagens || []], ['sombra', 'Sombra', P.sombras || []]]; }
function desenhaTimeline(){
  if (!P) return;
  var int = $('tlint'), rot = $('tl-rot'), p = pps(), d = dur(), larg = Math.max($('rolo').clientWidth, d * p + 70), M = mapa();
  int.innerHTML = ''; rot.innerHTML = ''; int.style.width = larg + 'px';
  function rotulo(txt, h, corB){ var r = el('div', null, txt); r.style.height = h + 'px'; if (corB) r.style.borderLeft = '3px solid ' + corB; rot.appendChild(r); }
  var rg = el('div', 'regua'); rg.style.width = larg + 'px';
  var passoR = [.5, 1, 2, 5, 10, 15, 30, 60].filter(function(s){ return s * p >= 64; })[0] || 120;
  for (var t = 0; t <= d + .001; t += passoR){ var tk = el('i'); tk.style.left = (t * p) + 'px'; rg.appendChild(tk); var sp = el('span', null, fmtc(t)); sp.style.left = (t * p) + 'px'; rg.appendChild(sp); }
  int.appendChild(rg); rotulo('', 22);
  var fv = el('div', 'faixa video'); fv.style.width = larg + 'px';
  M.forEach(function(m){ var cf = corFonte(m.de), c = el('div', 'clip' + (m.i === selTrecho && !selEl ? ' sel' : ''));
    c.dataset.k = 'trecho'; c.dataset.i = m.i; c.style.left = (m.o * p) + 'px'; c.style.width = Math.max(4, (m.fim - m.ini) * p) + 'px';
    c.style.background = rgba(cf, .55); c.style.borderColor = cf;
    c.appendChild(el('span', 'rot', (m.i + 1) + ' · ' + nomeFonte(m.de)));
    c.appendChild(el('span', 'sub', (m.fim - m.ini).toFixed(1) + ' s' + (m.s.recorte ? ' · recorte' : '') + (m.s.cheia ? ' · tela inteira' : '') + (m.s.limpo ? ' · sem elementos' : '')));
    fv.appendChild(c); });
  if (P.final && P.final.ativo){ var cfim = el('div', 'clip fim' + (selEl === 'final' ? ' sel' : '')); cfim.style.left = (durFala() * p) + 'px'; cfim.style.width = (T_FIM * p) + 'px'; cfim.appendChild(el('span', 'rot', 'Cartão final')); fv.appendChild(cfim); }
  var mais = el('button', 'mais', '+'); mais.type = 'button'; mais.title = 'Adicionar vídeo, imagem ou logo no fim'; mais.setAttribute('aria-label', 'Adicionar no fim da linha do tempo');
  mais.style.left = (d * p + 8) + 'px'; mais.onclick = function(ev){ ev.stopPropagation(); abrirAdicionar(); }; fv.appendChild(mais);
  int.appendChild(fv); rotulo('Vídeo', 54, '#4f7a24');
  faixas().forEach(function(fx){
    var tipo = fx[0], itens = fx[2], fins = [], vias = itens.map(function(x){ var it = itemTempo(x), v = 0; while (fins[v] != null && fins[v] > it.ini + .01) v++; fins[v] = it.fim; return v; });
    var n = Math.max(1, fins.length), h = 8 + n * 24, f = el('div', 'faixa'); f.style.height = h + 'px'; f.style.width = larg + 'px';
    itens.forEach(function(x, i){ var it = itemTempo(x), c = el('div', 'clip' + (selEl === tipo + ':' + i ? ' sel' : ''));
      c.dataset.k = tipo; c.dataset.i = i; c.style.top = (4 + vias[i] * 24) + 'px'; c.style.bottom = 'auto'; c.style.height = '20px';
      c.style.left = (it.ini * p) + 'px'; c.style.width = Math.max(6, (it.fim - it.ini) * p) + 'px'; c.style.background = rgba(COR_FAIXA[tipo], .6); c.style.borderColor = COR_FAIXA[tipo];
      var r = el('span', 'rot', tipo === 'camada' ? (x.nome || 'Vídeo') : tipo === 'texto' ? String(x.texto || '').split('\n')[0] : tipo === 'imagem' ? (x.nome || 'Imagem') : ({ vinheta:'Vinheta', gradiente:'Sombra', retangulo:'Faixa escura' })[x.tipo] || 'Sombra');
      r.style.top = '2px'; c.appendChild(r); f.appendChild(c); });
    int.appendChild(f); rotulo(fx[1], h, COR_FAIXA[tipo]);
  });
  // Faixa "Elementos": tudo o que aparece na prévia e não tem faixa própria (gancho, título, marcações, @ e logo do rodapé).
  // Tocar seleciona o elemento; a borda direita do gancho grande muda quanto tempo ele fica na tela.
  var fala = durFala(), tg = P.t_gancho == null ? T_GANCHO_PADRAO : P.t_gancho, els = [];
  els.push({ id:'gancho_grande', ini:0, fim:Math.min(tg, fala), n:'Gancho', vis:lay('gancho_grande').visivel, borda:true });
  els.push({ id:'gancho', ini:Math.min(tg, fala), fim:fala, n:'Título do topo', vis:lay('gancho').visivel });
  M.forEach(function(m){ (fonte(m.de).notas || []).forEach(function(nt){ var a = Math.max(nt.ini, m.ini), z = Math.min(nt.fim, m.fim); if (z > a) els.push({ id:'notas', ini:m.o + a - m.ini, fim:m.o + z - m.ini, n:nt.texto || 'Marcação', vis:true }); }); });
  els.push({ id:'arrobas', ini:0, fim:fala, n:'@ do rodapé', vis:lay('arrobas').visivel && !!lay('arrobas').texto, via:1 });
  els.push({ id:'logo', ini:0, fim:fala, n:'Logo do rodapé', vis:lay('logo').visivel, via:1, meio:true });
  var fe = el('div', 'faixa'); fe.style.height = '56px'; fe.style.width = larg + 'px';
  els.forEach(function(x){ if (x.fim - x.ini < .05) return; var c = el('div', 'clip el' + (selEl === x.id ? ' sel' : ''));
    c.dataset.k = 'el'; c.dataset.id = x.id; if (x.borda) c.dataset.borda = '1';
    var ini = x.meio ? x.ini + (x.fim - x.ini) / 2 : x.ini, fim = x.meio ? x.fim : (x.via ? x.ini + (x.fim - x.ini) / 2 - .02 : x.fim);
    c.style.top = (4 + (x.via || 0) * 26) + 'px'; c.style.bottom = 'auto'; c.style.height = '22px'; c.style.left = (ini * p) + 'px'; c.style.width = Math.max(6, (fim - ini) * p) + 'px';
    c.style.background = rgba(COR_FAIXA.el, x.vis ? .6 : .18); c.style.borderColor = COR_FAIXA.el; if (!x.vis) c.title = 'Escondido (ligue "Mostrar" no painel da direita)';
    var r = el('span', 'rot', x.n + (x.vis ? '' : ' · escondido')); r.style.top = '2px'; c.appendChild(r); fe.appendChild(c); });
  int.appendChild(fe); rotulo('Elementos', 56, COR_FAIXA.el);
  var fl = el('div', 'faixa'); fl.style.height = '30px'; fl.style.width = larg + 'px';
  GRUPOS.forEach(function(g){ var a = g[0].ri, z = g[g.length - 1].rf, c = el('div', 'clip leg' + (selEl === 'legenda' && T >= a && T <= z ? ' sel' : '')); c.style.left = (a * p) + 'px'; c.style.width = Math.max(4, (z - a) * p) + 'px'; c.style.top = '5px'; c.style.bottom = '5px';
    var r = el('span', 'rot', g.map(function(w){ return w.p; }).join(' ')); r.style.top = '2px'; c.appendChild(r); c.title = 'Toque duas vezes para editar o texto'; fl.appendChild(c); });
  int.appendChild(fl); rotulo('Legenda', 30, '#353c2e');
  var ins = el('div', 'insere'); ins.hidden = true; int.appendChild(ins);
  var cab = el('div', 'cabeca'); cab.style.left = (T * p) + 'px'; int.appendChild(cab);
  $('dur-rotulo').textContent = 'duração ' + d.toFixed(1) + ' s' + (d > 90 ? ' · acima de 90 s' : '');
  $('rolo').onscroll = function(){ rot.style.transform = 'translateY(' + (-$('rolo').scrollTop) + 'px)'; };
}
function atualizaTudo(){ recalc(); desenhaTimeline(); pinta(); }
var tl = null;
$('tlint').addEventListener('pointerdown', function(e){
  if (!P || e.target.closest('.mais')) return; var int = $('tlint'), p = pps(), t = (e.clientX - int.getBoundingClientRect().left) / p, c = e.target.closest('.clip');
  var alvoTq = c && c.classList.contains('leg') ? 'leg' : c && c.dataset.k === 'texto' ? 'texto:' + c.dataset.i : c && c.classList.contains('fim') ? 'final' : '';
  if (alvoTq){ var ag = Date.now(); if (alvoTq === window._tqAlvo && ag - (window._tqLeg || 0) < 450){ window._tqLeg = 0; window._tqAlvo = ''; if (alvoTq === 'leg') editarFraseEm(t); else editarTextoDe(alvoTq); return; } window._tqLeg = ag; window._tqAlvo = alvoTq; } else { window._tqLeg = 0; window._tqAlvo = ''; }
  int.setPointerCapture(e.pointerId);
  if (c && c.dataset.k === 'el'){ var rr = c.getBoundingClientRect(); selEl = c.dataset.id; selTrecho = null;
    if (c.dataset.borda && rr.right - e.clientX <= 10){ pausar(); tl = { modo:'tgancho', x0:e.clientX, v0:P.t_gancho == null ? T_GANCHO_PADRAO : P.t_gancho, p:p }; }
    else { tl = { modo:'scrub' }; scrub(t, true); }
    desenhaTimeline(); seleciona(); return; }
  if (c && c.classList.contains('leg')){ selEl = 'legenda'; tl = { modo:'scrub' }; scrub(t, true); desenhaTimeline(); inspetor(); pintaGuia(); return; }
  if (!c || !c.dataset.k){ tl = { modo:'scrub' }; scrub(t); return; }
  var k = c.dataset.k, i = +c.dataset.i, r = c.getBoundingClientRect(), borda = e.clientX - r.left <= 8 ? 'ini' : r.right - e.clientX <= 8 ? 'fim' : null;
  var item = k === 'trecho' ? trechos()[i] : alvo(k + ':' + i);
  if (k === 'trecho'){ selTrecho = i; selEl = null; } else selEl = k + ':' + i;
  tl = { modo:borda || 'pega', k:k, i:i, x0:e.clientX, s0:clone(item), it0:itemTempo(item), p:p };
  if (!borda){ if (k === 'trecho') scrub(t, true); else { var it = itemTempo(item); if (T < it.ini || T > it.fim) irPara(it.ini + .01); } }
  else pausar();
  lacunaSel = null; desenhaTimeline(); seleciona();
});
$('tlint').addEventListener('pointermove', function(e){
  if (!tl){ if (P){ var c = e.target.closest('.clip'), cur = 'pointer'; if (c && c.dataset.k === 'el'){ var r0 = c.getBoundingClientRect(); cur = c.dataset.borda && r0.right - e.clientX <= 10 ? 'ew-resize' : 'pointer'; } else if (c && c.dataset.k){ var r = c.getBoundingClientRect(); cur = (e.clientX - r.left <= 8 || r.right - e.clientX <= 8) ? 'ew-resize' : 'grab'; } $('tlint').style.cursor = cur; } return; }
  var int = $('tlint');
  if (tl.modo === 'scrub'){ if (e.buttons) scrub((e.clientX - int.getBoundingClientRect().left) / pps()); return; }
  var dx = e.clientX - tl.x0, dt = dx / tl.p;
  if (tl.modo === 'tgancho'){ P.t_gancho = +cl(tl.v0 + dt, 0, 8).toFixed(1); tl.mexeu = true; desenhaTimeline(); irPara(Math.max(0, P.t_gancho - .05)); return; }
  if (tl.k === 'trecho'){
    var s = trechos()[tl.i];
    if (tl.modo === 'ini'){ s.ini = +cl(tl.s0.ini + dt, 0, s.fim - .1).toFixed(3); atualizaTudo(); irPara(mapa()[tl.i].o); }
    else if (tl.modo === 'fim'){ s.fim = +cl(tl.s0.fim + dt, s.ini + .1, fonteDur(s.de || '')).toFixed(3); atualizaTudo(); var m = mapa()[tl.i]; irPara(m.o + m.fim - m.ini - 1 / FPS); }
    else {
      if (tl.modo === 'pega' && Math.abs(dx) > 8) tl.modo = 'mover';
      if (tl.modo !== 'mover') return;
      var x = e.clientX - int.getBoundingClientRect().left, M = mapa(), alvoI = 0;
      M.forEach(function(mm){ if (mm.i !== tl.i && (mm.o + (mm.fim - mm.ini) / 2) * tl.p < x) alvoI++; });
      tl.alvo = alvoI; var b = int.querySelector('.clip[data-k="trecho"][data-i="' + tl.i + '"]'); if (b){ b.style.transform = 'translateX(' + dx + 'px)'; b.style.opacity = '.75'; b.style.zIndex = 3; }
      var resto = M.filter(function(mm){ return mm.i !== tl.i; }), ins = int.querySelector('.insere'); ins.hidden = false;
      ins.style.left = ((alvoI < resto.length ? resto[alvoI].o : durFala()) * tl.p) + 'px';
    }
  } else {
    var o = alvo(tl.k + ':' + tl.i), a = tl.it0, D = dur(); if (!o) return;
    if (tl.modo === 'pega' && Math.abs(dx) > 4) tl.modo = 'mover';
    if (tl.modo === 'pega') return;
    o.sempre = false;
    if (tl.modo === 'ini'){ o.ini = +cl(a.ini + dt, 0, a.fim - .2).toFixed(2); o.fim = a.fim; if (tl.k === 'camada'){ o.src = +Math.max(0, tl.s0.src + (o.ini - a.ini)).toFixed(2); if (o.src === 0) o.ini = +(a.ini - tl.s0.src).toFixed(2); } }
    else if (tl.modo === 'fim'){ o.ini = a.ini; o.fim = +cl(a.fim + dt, a.ini + .2, D).toFixed(2); }
    else { var Ld = a.fim - a.ini, ni = cl(a.ini + dt, 0, Math.max(0, D - Ld)); o.ini = +ni.toFixed(2); o.fim = +(ni + Ld).toFixed(2); }
    desenhaTimeline(); irPara(cl(tl.modo === 'fim' ? o.fim - .05 : o.ini + .01, 0, D));
  }
});
$('tlint').addEventListener('pointerup', function(){
  if (!tl) return; var t0 = tl; tl = null;
  if (t0.modo === 'tgancho'){ if (t0.mexeu) agendaSalvar('Tempo do gancho'); desenhaTimeline(); inspetor(); return; }
  if (t0.k === 'trecho'){
    if (t0.modo === 'ini' || t0.modo === 'fim') mudou('Aparar trecho ' + (t0.i + 1));
    else if (t0.modo === 'mover' && t0.alvo != null){ var ts = trechos(), s = ts.splice(t0.i, 1)[0]; ts.splice(t0.alvo, 0, s); selTrecho = t0.alvo; mudou('Mover trecho na linha do tempo'); irPara(mapa()[selTrecho].o); }
  } else if (t0.k && t0.modo !== 'pega') agendaSalvar((t0.modo === 'mover' ? 'Mover no tempo: ' : 'Ajustar duração: ') + NOMES[t0.k].toLowerCase());
  desenhaTimeline(); mostraAba();
});
function scrub(t, semSel){ pausar(); irPara(t); if (!semSel){ mapa().forEach(function(m){ if (T >= m.o && T < m.o + m.fim - m.ini) selTrecho = m.i; }); } }
$('t-zoom').oninput = function(){ zoomTL = +this.value; desenhaTimeline(); };
$('t-zm').onclick = function(){ zoomTL = Math.max(1, zoomTL / 1.4); $('t-zoom').value = zoomTL; desenhaTimeline(); };
$('t-zp').onclick = function(){ zoomTL = Math.min(12, zoomTL * 1.4); $('t-zoom').value = zoomTL; desenhaTimeline(); };
$('t-fit').onclick = function(){ zoomTL = 1; $('t-zoom').value = 1; desenhaTimeline(); };
$('t-div').onclick = function(){ dividir(); };
$('t-cop').onclick = function(){ copiar(false); };
$('t-col').onclick = function(){ colar('depois'); };
$('t-colc').onclick = function(){ colar('cursor'); };
$('t-dup').onclick = function(){ duplicar(); };
$('t-del').onclick = function(){ apagarSel(); };
(function(){ var puxa = $('tl-puxa'), y0 = 0, h0 = 0;
  try { var h = +localStorage.getItem('intuscut-alt-tl'); if (h > 120) document.documentElement.style.setProperty('--alt-tl', h + 'px'); } catch (e) {}
  puxa.addEventListener('pointerdown', function(e){ puxa.setPointerCapture(e.pointerId); y0 = e.clientY; h0 = document.querySelector('.tl').getBoundingClientRect().height; });
  puxa.addEventListener('pointermove', function(e){ if (!e.buttons || !h0) return; var h = cl(h0 - (e.clientY - y0), 130, innerHeight * .6); document.documentElement.style.setProperty('--alt-tl', h + 'px'); });
  puxa.addEventListener('pointerup', function(){ var h = document.querySelector('.tl').getBoundingClientRect().height; h0 = 0; try { localStorage.setItem('intuscut-alt-tl', Math.round(h)); } catch (e) {} desenhaTimeline(); });
})();
window.addEventListener('resize', function(){ if (P){ desenhaTimeline(); pinta(); } });

// ---------- cortes, copiar e colar, montagem ----------
function mudou(rot){ recalc(); desenhaTimeline(); agendaSalvar(rot); pinta(); }
function dividir(){ var f = fonteEm(T); if (!f) return false; var ts = trechos(), s = ts[f.i]; if (f.ts - s.ini < .15 || s.fim - f.ts < .15) return false;
  var novo = clone(s); novo.ini = +f.ts.toFixed(3); s.fim = novo.ini; ts.splice(f.i + 1, 0, novo); selTrecho = f.i + 1; selEl = null; mudou('Dividir trecho'); mostraAba(); return true; }
function remover(i){ var ts = trechos(); if (ts.length <= 1 || !ts[i]) return; ts.splice(i, 1); selTrecho = Math.min(i, ts.length - 1); mudou('Remover trecho ' + (i + 1)); irPara(Math.min(T, dur())); mostraAba(); }
function apagarSel(){ if (selEl && /^(texto|sombra|imagem|camada):/.test(selEl)){ var t = selEl.split(':'), lista = t[0] === 'texto' ? P.textos : t[0] === 'sombra' ? P.sombras : t[0] === 'camada' ? P.camadas : P.imagens;
    lista.splice(+t[1], 1); selEl = null; atualizaTudo(); agendaSalvar('Apagar ' + NOMES[t[0]].toLowerCase()); mostraAba(); } else remover(selTrecho); }
function mover(i, d){ var ts = trechos(), j = i + d; if (j < 0 || j >= ts.length) return; var x = ts[i]; ts[i] = ts[j]; ts[j] = x; selTrecho = j; mudou('Mudar ordem dos trechos'); mostraAba(); }
function incluirLacuna(){ if (!lacunaSel) return; var ts = trechos(), pos = Math.min(selTrecho + 1, ts.length), n = { ini:lacunaSel.ini, fim:lacunaSel.fim };
  if (lacunaSel.de) n.de = lacunaSel.de;
  var ant = ts[pos - 1], dep = ts[pos];
  if (ant && (ant.de || '') === (n.de || '') && !ant.recorte && Math.abs(ant.fim - n.ini) < .02) ant.fim = n.fim;
  else if (dep && (dep.de || '') === (n.de || '') && !dep.recorte && Math.abs(dep.ini - n.fim) < .02) dep.ini = n.ini;
  else { ts.splice(pos, 0, n); selTrecho = pos; }
  lacunaSel = null; mudou('Trazer trecho de volta'); mostraAba(); }
// Área de transferência guardada no navegador: dá para copiar num projeto e colar em outro.
function clipSalva(c){ try { localStorage.setItem('intuscut-clip', JSON.stringify(c)); } catch (e) {} window.__intusClip = c; }
function clipLe(){ try { var c = JSON.parse(localStorage.getItem('intuscut-clip') || 'null'); if (c) return c; } catch (e) {} return window.__intusClip || null; }
function copiar(cortar){
  if (selEl && /^(texto|sombra|imagem|camada):/.test(selEl)){ var tp = selEl.split(':')[0], it = alvo(selEl); if (!it) return;
    clipSalva({ tipo:tp, item:clone(it), proj:P.id, fonte:tp === 'camada' && it.de ? clone(fonte(it.de)) : null }); estado(NOMES[tp] + ' copiado', 'ok'); if (cortar) apagarSel(); return; }
  var s = trechos()[selTrecho]; if (!s) return; var key = s.de || '';
  clipSalva({ tipo:'trecho', item:clone(s), proj:P.id, key:key, fonte:key ? clone(P.fontes[key]) : snapshot(P), nome:nomeFonte(key) });
  estado('Trecho ' + (selTrecho + 1) + ' copiado', 'ok'); if (cortar) remover(selTrecho); else mostraAba();
}
function posCursor(){ var f = fonteEm(T); if (!f) return trechos().length; var m = mapa()[f.i];
  if (f.ts - m.ini < .15) return f.i; if (m.fim - f.ts < .15) return f.i + 1; dividir(); return f.i + 1; }
function colar(onde){
  var c = clipLe(); if (!c){ estado('Nada copiado ainda'); return; }
  if (c.tipo !== 'trecho'){
    var it = clone(c.item), nome = c.tipo === 'texto' ? 'textos' : c.tipo === 'sombra' ? 'sombras' : c.tipo === 'camada' ? 'camadas' : 'imagens', lista = P[nome] = P[nome] || [];
    if (!it.sempre){ var dd = (it.fim || 0) - (it.ini || 0); it.ini = +T.toFixed(2); it.fim = +(T + Math.max(.5, dd || 3)).toFixed(2); }
    if (c.tipo === 'camada'){ var kf = garantirFonte(c.proj, it.de, c.fonte && c.fonte.midia ? c.fonte : null); it.de = kf; }
    it.x += 30; it.y += 30; lista.push(it); selEl = c.tipo + ':' + (lista.length - 1); atualizaTudo(); agendaSalvar('Colar ' + NOMES[c.tipo].toLowerCase()); seleciona(); return; }
  var s = clone(c.item), key = garantirFonte(c.proj, c.key, c.fonte); delete s.de; if (key) s.de = key;
  var pos = onde === 'cursor' ? posCursor() : onde === 'inicio' ? 0 : onde === 'fim' ? trechos().length : Math.min(selTrecho + 1, trechos().length);
  trechos().splice(pos, 0, s); selTrecho = pos; selEl = null; mudou('Colar trecho'); irPara(mapa()[pos].o); mostraAba();
}
function duplicar(){
  if (selEl && /^(texto|sombra|imagem|camada):/.test(selEl)){ copiar(false); colar(); return; }
  var s = trechos()[selTrecho]; if (!s) return; trechos().splice(selTrecho + 1, 0, clone(s)); selTrecho++; mudou('Duplicar trecho'); irPara(mapa()[selTrecho].o); mostraAba(); }
function inserirTrechos(novos, onde, rot){
  if (!novos.length) return; var pos = onde === 'cursor' ? posCursor() : onde === 'inicio' ? 0 : trechos().length;
  Array.prototype.splice.apply(trechos(), [pos, 0].concat(novos)); selTrecho = pos; selEl = null; mudou(rot); irPara(mapa()[pos].o); mostraAba();
}
async function adicionarProjeto(id, onde){
  var X = await carregarProjeto(id); if (!X) return;
  var novos = (X.trechos || []).map(function(t){ var n = clone(t), dados = t.de ? (X.fontes || {})[t.de] : snapshot(X); delete n.de;
    var k = garantirFonte(X.id, t.de || '', dados); if (k) n.de = k; return n; });
  inserirTrechos(novos, onde, 'Adicionar ' + (X.nome || X.id));
}
async function carregarProjeto(id){
  var X = projetos[id]; if (X && X.trechos && !X._parcial) return X;
  try { var r = await api('projeto&id=' + encodeURIComponent(id)); X = r.projeto; projetos[id] = X; return X; } catch (e){ if (e.code !== 'sem_acesso') dialogo('Não consegui abrir o projeto', e.message || '', [['Ok', fecha]]); return null; }
}
function fonteDeVideo(chave, nome, midia){ P.fontes = P.fontes || {};
  if (!P.fontes[chave]) P.fontes[chave] = { nome:nome, midia:midia, palavras:[], planos:[{ ini:0, fim:(midia.duracao || 5) + 1, cx:960, larg:ORIG_W, y:0, tarja:true }], notas:[] };
  return chave; }
function inserirVideoBib(item, onde){
  var k = fonteDeVideo('bib-' + item.id, item.nome, midiaDeItem(item));
  inserirTrechos([{ ini:0, fim:+(item.dur || 5).toFixed(2), de:k, cheia:true, limpo:!item.react && !item.exportado ? true : false }], onde, 'Inserir ' + item.nome);
}
function painelMidia(C){
  var ts = trechos(), c = clipLe();
  var onde = sel([['fim', 'no final'], ['inicio', 'no início'], ['cursor', 'no cursor']]);
  var proj = sel(Object.keys(projetos).sort().map(function(id){ return [id, (projetos[id].nome || id) + (id === P.id ? ' (este)' : '')]; }));
  var nv = el('button', 'btn pri', '＋ Nova edição com um vídeo seu'); nv.type = 'button'; nv.onclick = function(){ $('b-novo').click(); };
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Começar uma edição nova'), nv, el('p', 'nota-ajuda', 'Escolha um vídeo do celular ou do computador. Ele vira um projeto novo e você decide se edita à mão ou pede a edição à IA.')]));
  var gr = el('button', 'btn rec', '● Gravar react ou explicação'); gr.type = 'button'; gr.onclick = abrirEstudio;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Gravar ao vivo'), gr, el('p', 'nota-ajuda', 'Abre a câmera por cima deste vídeo. Você pausa, volta e avança o fundo enquanto explica, até 5 minutos.')]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Sobrepor um vídeo (react, comparativo)'), el('div', 'linha', [botao('Projeto escolhido abaixo', function(){ sobreporProjetoAsync(proj.value); }), botao('Enviar vídeo e sobrepor', function(){ enviarVideoMontagem('fim', true); })]),
    el('p', 'nota-ajuda', 'Entra no cursor, por cima do vídeo principal. Depois escolha a arrumação: janela no canto, bolha, em cima e embaixo ou lado a lado.')]));
  if (!Object.keys(projetos).some(function(k){ return /^ric-/.test(k); })) C.appendChild(el('div', 'cartao', [el('b', null, 'Cortes da entrevista na RIC'), el('p', 'nota-ajuda', 'Os 5 cortes que o Claude montou. Envie o vídeo da entrevista (e, se tiver, a voz e a música separadas) e eles viram projetos aqui.'), botao('Trazer os cortes', importarModelo)]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Juntar vídeos (montagem)'), el('div', 'linha', [campo('Projeto', proj), campo('Onde', onde)]),
    el('div', 'linha', [botao('Adicionar este projeto', function(){ adicionarProjeto(proj.value, onde.value); }), botao('Enviar vídeo do aparelho', function(){ enviarVideoMontagem(onde.value); })]),
    el('p', 'nota-ajuda', 'Entram todos os trechos do projeto escolhido, com legendas e enquadramento. Vinhetas e introduções ficam na Biblioteca. ' + (c ? 'Copiado agora: ' + (c.tipo === 'trecho' ? 'trecho de ' + (c.item.fim - c.item.ini).toFixed(1) + ' s (' + (c.nome || 'vídeo') + ')' : NOMES[c.tipo].toLowerCase()) + '.' : ''))]));
  var key = fonteBarKey(), fb = el('div', 'fonteb'); fb.id = 'fonteb'; desenhaFonteBar(fb, key);
  fb.addEventListener('click', function(e){ var g = e.target.closest('.g'); if (!g){ lacunaSel = null; mostraAba(); return; } lacunaSel = { ini:+g.dataset.ini, fim:+g.dataset.fim, de:key }; mostraAba(); });
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Gravação: ' + nomeFonte(key)), fb, el('p', 'nota-ajuda', 'Verde entra no vídeo, listrado ficou de fora. Clique num listrado para trazer de volta.')]));
  if (lacunaSel) C.appendChild(el('div', 'cartao', [el('b', null, 'Trecho fora do vídeo: ' + fmt(lacunaSel.ini) + ' a ' + fmt(lacunaSel.fim) + ' (' + (lacunaSel.fim - lacunaSel.ini).toFixed(1) + ' s)'),
    el('div', 'linha', [botao('Trazer para depois do trecho selecionado', incluirLacuna), botao('Ver na gravação', function(){ pausar(); midiaDe(lacunaSel.de).v.currentTime = lacunaSel.ini; })])]));
  var lista = el('div', 'lista');
  ts.forEach(function(s, i){
    var k = s.de || '', marca = el('span', 't', String(i + 1)); marca.style.borderLeft = '4px solid ' + corFonte(k); marca.style.paddingLeft = '6px';
    var it = el('div', 'item' + (i === selTrecho ? ' ativo' : ''), [marca, el('div', 'grupo', [el('b', null, nomeFonte(k)), el('span', 't', fmt(s.ini) + ' a ' + fmt(s.fim) + ' · ' + (s.fim - s.ini).toFixed(1) + ' s' + (s.recorte ? ' · recorte' : ''))]),
      el('span', 'linha', [botao('▲', function(){ mover(i, -1); }, 'Subir trecho'), botao('▼', function(){ mover(i, 1); }, 'Descer trecho'), botao('×', function(){ remover(i); }, 'Remover trecho ' + (i + 1))])]);
    it.addEventListener('click', function(e){ if (e.target.tagName === 'BUTTON') return; selTrecho = i; selEl = null; irPara(mapa()[i].o); seleciona(); mostraAba(); });
    lista.appendChild(it);
  });
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Trechos (' + ts.length + ')'), lista]));
}
function desenhaFonteBar(fb, key){
  var fd = fonteDur(key), prev = 0; fb.innerHTML = '';
  function lac(a, z){ var g = el('div', 'g' + (lacunaSel && lacunaSel.de === key && Math.abs(lacunaSel.ini - a) < .01 ? ' sel' : '')); g.style.left = (a / fd * 100) + '%'; g.style.width = ((z - a) / fd * 100) + '%'; g.dataset.ini = a; g.dataset.fim = z; fb.appendChild(g); }
  trechos().filter(function(s){ return (s.de || '') === key; }).sort(function(a, b){ return a.ini - b.ini; }).forEach(function(s){
    if (s.ini - prev > .05) lac(prev, s.ini);
    var k = el('div', 'k'); k.style.background = corFonte(key); k.style.left = (s.ini / fd * 100) + '%'; k.style.width = ((s.fim - s.ini) / fd * 100) + '%'; fb.appendChild(k); prev = Math.max(prev, s.fim); });
  if (fd - prev > .05) lac(prev, fd);
  fb.appendChild(el('div', 'cabeca'));
}
function fonteBarKey(){ var s = trechos()[selTrecho]; return s ? (s.de || '') : ''; }
function enviarVideoMontagem(onde, sobrepor){
  escolherArquivo('video/*', function(r, nome, info){
    var k = fonteDeVideo('up-' + r.token.slice(0, 10), nome, { video:r.url, voz:r.url, musica:'', duracao:info.dur || 5, alt:info.w ? ORIG_W * info.h / info.w : 1080 });
    guardaNaBib({ tipo:'video', nome:nome, url:r.url, dur:info.dur, w:info.w, h:info.h });
    if (sobrepor) adicionarCamada(camadaPadrao(k, nome, info.dur, 0));
    else inserirTrechos([{ ini:0, fim:+(info.dur || 5).toFixed(2), de:k, cheia:info.h > info.w }], onde, 'Adicionar vídeo ' + nome); });
}
function guardaNaBib(item){ return api('bib_salvar', { item:item }).then(function(r){ BIB[r.item.id] = r.item; if (aba === 'biblioteca') mostraAba(); return r.item; }).catch(function(){ return null; }); }
// ---------- propriedades do corte: enquadramento, recorte livre, tela inteira ----------
var fcv = null, fcx = null;
function inspCorte(C){
  fcv = null;
  var f = fAgora(); if (!f){ C.appendChild(el('p', 'nota-ajuda', 'Leve o cursor para um trecho com vídeo.')); return; }
  var s = f.s, key = f.de, alt = srcAltura(key), livre = !!s.recorte;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Corte ' + (f.i + 1) + ' · ' + nomeFonte(key)), el('span', 't', fmt(s.ini) + ' a ' + fmt(s.fim) + ' da gravação'),
    el('div', 'linha', [campo('Início', num(s.ini, function(v){ s.ini = cl(v, 0, s.fim - .1); mudou('Ajustar início do trecho'); inspetor(); })), nudge(s, 'ini'),
      campo('Fim', num(s.fim, function(v){ s.fim = cl(v, s.ini + .1, fonteDur(key)); mudou('Ajustar fim do trecho'); inspetor(); })), nudge(s, 'fim')]),
    el('div', 'linha', [chk(s, 'cheia', 'Ocupar a tela inteira', 'Tela inteira', function(){ setTimeout(function(){ desenhaTimeline(); inspetor(); }); }), chk(s, 'limpo', 'Sem legenda, logo e @ por cima', 'Sem elementos', function(){ setTimeout(desenhaTimeline); })])]));
  var box = el('div', 'fonte-ve'); box.style.aspectRatio = ORIG_W + '/' + Math.round(alt);
  fcv = document.createElement('canvas'); fcv.width = 560; fcv.height = Math.round(560 * alt / ORIG_W); fcx = fcv.getContext('2d'); box.appendChild(fcv);
  var modo = el('div', 'linha', [
    botao('Plano da câmera', function(){ if (!livre) return; delete s.recorte; mudou('Voltar ao enquadramento do plano'); inspetor(); }),
    botao('Recorte livre deste corte', function(){ if (livre) return; var g = geometria(f); s.recorte = { x:Math.round(g.sx), y:Math.round(g.sy), w:Math.round(g.sw), h:Math.round(g.sh) }; mudou('Recorte livre no corte ' + (f.i + 1)); inspetor(); })]);
  modo.children[livre ? 1 : 0].classList.add('on');
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Enquadramento'), modo, box]));
  if (livre) quadroLivre(C, box, f); else quadroPlano(C, box, f);
  pintaFonte();
}
function quadroPlano(C, box, f){
  var pl = plano(f.ts, f.de), a2 = null;
  var zoom = range(300, ORIG_W, 10, pl.larg, function(v){ pl.larg = v; limita(pl, f.de); pinta(); agendaSalvar('Zoom do vídeo'); });
  box.addEventListener('pointerdown', function(e){ box.setPointerCapture(e.pointerId); a2 = { x:e.clientX, y:e.clientY, cx:pl.cx, yy:pl.y || 0 }; });
  box.addEventListener('pointermove', function(e){ if (!a2) return; var r = box.getBoundingClientRect(), k = ORIG_W / r.width;
    pl.cx = Math.round(a2.cx + (e.clientX - a2.x) * k); pl.y = Math.round(a2.yy + (e.clientY - a2.y) * k); limita(pl, f.de); pinta(); });
  box.addEventListener('pointerup', function(){ if (a2){ a2 = null; agendaSalvar('Enquadrar vídeo'); } });
  function aplica(fn, rot){ fn(); limita(pl, f.de); zoom.value = pl.larg; pinta(); agendaSalvar(rot); }
  var planos = fonte(f.de).planos || [];
  C.appendChild(el('p', 'nota-ajuda', 'Vale para o plano da câmera inteiro (' + fmt(pl.ini) + ' a ' + fmt(Math.min(pl.fim, fonteDur(f.de))) + '), então não balança. Arraste o retângulo aqui ou o vídeo na prévia; Shift + arrastar na prévia move a janela; a roda do mouse dá zoom.'));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Zoom'), zoom, el('div', 'linha', [
    botao('Close', function(){ aplica(function(){ pl.larg = 624; }, 'Close'); }), botao('Corpo inteiro', function(){ aplica(function(){ pl.larg = 1250; pl.y = 0; }, 'Plano aberto'); }),
    botao('Tela toda', function(){ aplica(function(){ pl.larg = ORIG_W; pl.cx = 960; pl.y = 0; }, 'Tela toda'); }), botao('Centralizar', function(){ aplica(function(){ pl.cx = 960; }, 'Centralizar'); })])]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Tarja da TV'), chk(pl, 'tarja', 'Mostrar a tarja neste plano', 'Tarja da TV', function(){ limita(pl, f.de); }),
    el('div', 'linha', [botao('Mostrar em todos', function(){ planos.forEach(function(x){ x.tarja = true; limita(x, f.de); }); pinta(); agendaSalvar('Mostrar tarja em todos'); inspetor(); }),
      botao('Esconder em todos', function(){ planos.forEach(function(x){ x.tarja = false; limita(x, f.de); }); pinta(); agendaSalvar('Esconder tarja em todos'); inspetor(); })])]));
  C.appendChild(el('div', 'linha', [botao('Copiar para o próximo plano', function(){ var i = planos.indexOf(pl); if (planos[i + 1]){ ['cx', 'larg', 'y', 'tarja'].forEach(function(k){ planos[i + 1][k] = pl[k]; }); agendaSalvar('Copiar enquadramento'); } }),
    botao('Para todos os planos', function(){ planos.forEach(function(x){ ['cx', 'larg', 'y', 'tarja'].forEach(function(k){ x[k] = pl[k]; }); }); pinta(); agendaSalvar('Enquadramento em todos'); })]));
}
function quadroLivre(C, box, f){
  var r = f.s.recorte, key = f.de, a2 = null, trava = null;
  function campos(){ [cx, cy, cw, ch].forEach(function(i, n){ i.value = Math.round(r[['x', 'y', 'w', 'h'][n]]); }); }
  box.addEventListener('pointerdown', function(e){ var b = box.getBoundingClientRect(), k = ORIG_W / b.width, px = (e.clientX - b.left) * k, py = (e.clientY - b.top) * k, m = 16 * k;
    var cantos = { nw:[r.x, r.y], ne:[r.x + r.w, r.y], sw:[r.x, r.y + r.h], se:[r.x + r.w, r.y + r.h] }, modo = null;
    Object.keys(cantos).forEach(function(c){ if (Math.abs(px - cantos[c][0]) < m && Math.abs(py - cantos[c][1]) < m) modo = c; });
    if (!modo && px > r.x && px < r.x + r.w && py > r.y && py < r.y + r.h) modo = 'mover';
    if (!modo) return; box.setPointerCapture(e.pointerId); a2 = { modo:modo, px:px, py:py, r0:clone(r) };
  });
  box.addEventListener('pointermove', function(e){
    var b = box.getBoundingClientRect(), k = ORIG_W / b.width;
    if (!a2){ var px = (e.clientX - b.left) * k, py = (e.clientY - b.top) * k, m = 16 * k, perto = [[r.x, r.y], [r.x + r.w, r.y], [r.x, r.y + r.h], [r.x + r.w, r.y + r.h]].some(function(q){ return Math.abs(px - q[0]) < m && Math.abs(py - q[1]) < m; });
      box.style.cursor = perto ? 'nwse-resize' : 'grab'; return; }
    var dx = (e.clientX - b.left) * k - a2.px, dy = (e.clientY - b.top) * k - a2.py, o = a2.r0;
    if (a2.modo === 'mover'){ r.x = o.x + dx; r.y = o.y + dy; }
    else { var x0 = o.x, y0 = o.y, x1 = o.x + o.w, y1 = o.y + o.h;
      if (a2.modo.indexOf('w') >= 0) x0 += dx; else x1 += dx; if (a2.modo.indexOf('n') >= 0) y0 += dy; else y1 += dy;
      var w = Math.max(60, x1 - x0), h = Math.max(60, y1 - y0); if (trava) h = w / trava;
      r.x = a2.modo.indexOf('w') >= 0 ? x1 - w : x0; r.y = a2.modo.indexOf('n') >= 0 ? y1 - h : y0; r.w = w; r.h = h; }
    limitaRec(r, key); ['x', 'y', 'w', 'h'].forEach(function(q){ r[q] = Math.round(r[q]); }); campos(); pinta();
  });
  box.addEventListener('pointerup', function(){ if (a2){ a2 = null; agendaSalvar('Recorte livre'); } });
  function numInt(rot, q){ var i = el('input'); i.type = 'number'; i.step = '1'; i.style.width = '76px'; i.value = Math.round(r[q]); i.onchange = function(){ var v = parseFloat(i.value); if (!isNaN(v)){ r[q] = v; limitaRec(r, key); campos(); pinta(); agendaSalvar('Recorte livre'); } }; return campo(rot, i); }
  var fx = numInt('Esquerda', 'x'), fy = numInt('Topo', 'y'), fw = numInt('Largura', 'w'), fh = numInt('Altura', 'h'), cx = fx.lastChild, cy = fy.lastChild, cw = fw.lastChild, ch = fh.lastChild;
  function prop(rot, ar){ return botao(rot, function(){ trava = ar; if (ar){ var cxm = r.x + r.w / 2, cym = r.y + r.h / 2, a = srcAltura(key);
      var h = Math.min(r.h, r.w / ar, a), w = h * ar; if (w > ORIG_W){ w = ORIG_W; h = w / ar; } r.w = w; r.h = h; r.x = cxm - w / 2; r.y = cym - h / 2; }
    limitaRec(r, key); ['x', 'y', 'w', 'h'].forEach(function(q){ r[q] = Math.round(r[q]); }); campos(); pinta(); agendaSalvar('Proporção do recorte'); }); }
  C.appendChild(el('p', 'nota-ajuda', 'Arraste o retângulo para mover e os cantos para mudar o tamanho, em qualquer formato. Vale só para este corte. A sobra da janela recebe o fundo desfocado (ou a cor escolhida no Layout).'));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Proporção'), el('div', 'linha', [prop('Livre', null), prop('Preencher a janela', W / lay('janela').h), prop('9:16', 9 / 16), prop('4:5', .8), prop('1:1', 1), prop('16:9', 16 / 9),
    botao('Gravação inteira', function(){ r.x = 0; r.y = 0; r.w = ORIG_W; r.h = srcAltura(key); campos(); pinta(); agendaSalvar('Recorte da gravação inteira'); })])]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Medidas na gravação (px)'), el('div', 'linha', [fx, fy, fw, fh])]));
  C.appendChild(el('div', 'linha', [botao('Copiar para o próximo corte', function(){ var n = trechos()[f.i + 1]; if (n){ n.recorte = clone(r); mudou('Copiar recorte'); } }),
    botao('Para todos os cortes', function(){ trechos().forEach(function(t){ t.recorte = clone(r); }); mudou('Recorte em todos os cortes'); })]));
}
function pintaFonte(){
  if (!fcx || !fcv || !document.body.contains(fcv)) return; var f = fAgora(); if (!f) return;
  var V = midiaDe(f.de).v, cw = fcv.width, chh = fcv.height, k = cw / ORIG_W, g = geometria(f);
  fcx.fillStyle = '#000'; fcx.fillRect(0, 0, cw, chh); if (V.readyState >= 2) fcx.drawImage(V, 0, 0, cw, chh);
  if (!f.s.recorte && !f.s.cheia && !plano(f.ts, f.de).tarja && srcAltura(f.de) > SRC_H){ fcx.fillStyle = 'rgba(0,0,0,.55)'; fcx.fillRect(0, SRC_H * k, cw, chh - SRC_H * k);
    fcx.fillStyle = 'rgba(255,214,10,.9)'; fcx.font = '600 12px Inter'; fcx.textBaseline = 'top'; fcx.fillText('tarja da TV fica de fora', 8, SRC_H * k + 6); }
  var sx = g.sx, sy = g.sy, sw = g.sw, sh = g.sh;
  if (g.tela){ var vw = W / (g.dw / g.sw), vh = H / (g.dh / g.sh); sx = g.sx + (g.sw - vw) / 2; sy = g.sy + (g.sh - vh) / 2; sw = vw; sh = vh; }
  fcx.save(); fcx.fillStyle = 'rgba(0,0,0,.45)'; fcx.beginPath(); fcx.rect(0, 0, cw, chh); fcx.rect(sx * k, sy * k, sw * k, sh * k); fcx.fill('evenodd'); fcx.restore();
  fcx.strokeStyle = '#7fff00'; fcx.lineWidth = 3; fcx.strokeRect(sx * k, sy * k, sw * k, sh * k);
  if (f.s.recorte){ fcx.fillStyle = '#7fff00'; [[g.sx, g.sy], [g.sx + g.sw, g.sy], [g.sx, g.sy + g.sh], [g.sx + g.sw, g.sy + g.sh]].forEach(function(q){ fcx.fillRect(q[0] * k - 6, q[1] * k - 6, 12, 12); }); }
}

// ---------- controles reaproveitados ----------
function el(tag, cls, filhos){ var e = document.createElement(tag); if (cls) e.className = cls;
  if (typeof filhos === 'string') e.textContent = filhos; else (filhos || []).forEach(function(f){ e.appendChild(typeof f === 'string' ? document.createTextNode(f) : f); }); return e; }
function botao(txt, fn, aria){ var b = el('button', 'btn peq', txt); b.type = 'button'; if (aria) b.setAttribute('aria-label', aria); b.onclick = fn; return b; }
function num(v, fn){ var i = el('input'); i.type = 'number'; i.step = '0.05'; i.value = (+v).toFixed(2); i.style.width = '80px'; i.onchange = function(){ var x = parseFloat(i.value); if (!isNaN(x)) fn(x); }; return i; }
function range(mi, ma, st, v, fn){ var r = el('input'); r.type = 'range'; r.min = mi; r.max = ma; r.step = st; r.value = v; r.oninput = function(){ fn(+r.value, r); }; return r; }
function campo(rot, ctrl){ return el('div', 'campo', [el('span', null, rot), ctrl]); }
function sel(lista, v){ var s = el('select'); lista.forEach(function(x){ var op = document.createElement('option'); op.value = x[0]; op.textContent = x[1]; s.appendChild(op); }); if (v != null) s.value = v; return s; }
function nudge(s, k){ var r = k === 'ini' ? 'Ajustar início do trecho' : 'Ajustar fim do trecho';
  return el('span', 'linha', [botao('−', function(){ s[k] = +(s[k] - .1).toFixed(2); if (s.fim - s.ini < .1) s[k] += .1; mudou(r); inspetor(); }, 'Recuar 0,1 s'), botao('+', function(){ s[k] = +(s[k] + .1).toFixed(2); if (s.fim - s.ini < .1) s[k] -= .1; mudou(r); inspetor(); }, 'Avançar 0,1 s')]); }
function chk(o, k, rot, hist, depois){ var i = el('input'); i.type = 'checkbox'; i.checked = !!o[k]; i.onchange = function(){ o[k] = i.checked; if (depois) depois(); recalc(); pinta(); agendaSalvar(hist + (i.checked ? ' ligado' : ' desligado')); if (k === 'sempre'){ desenhaTimeline(); inspetor(); } }; return el('label', 'chk', [i, ' ' + rot]); }
function deslize(o, k, rot, mi, ma, st, hist, fmtv){ var s = el('span', null, rot + ': ' + (fmtv ? fmtv(o[k]) : o[k]));
  var r = range(mi, ma, st, o[k], function(v){ o[k] = v; s.textContent = rot + ': ' + (fmtv ? fmtv(v) : v); recalc(); pinta(); agendaSalvar(hist); });
  return el('div', 'campo', [s, r]); }
function corIn(o, k, rot, hist){ var i = el('input'); i.type = 'color'; i.value = /^#[0-9a-f]{6}$/i.test(o[k] || '') ? o[k] : '#ffffff'; i.oninput = function(){ o[k] = i.value; pinta(); agendaSalvar(hist); }; return el('label', 'chk', [i, ' ' + rot]); }
function opcoes(o, k, lista, hist){ var s = sel(lista, o[k]); s.onchange = function(){ o[k] = s.value; pinta(); agendaSalvar(hist); inspetor(); }; return s; }
function chips(lista, atual, fn){ var c = el('div', 'chips'); lista.forEach(function(x){ var b = el('button', null, x[1]); b.type = 'button'; b.setAttribute('aria-pressed', String(x[0] === atual)); b.onclick = function(){ fn(x[0]); }; c.appendChild(b); }); return c; }
var pct = function(v){ return Math.round(v * 100) + '%'; };
function tempoItem(o, rot){ var f = [chk(o, 'sempre', 'No vídeo todo', rot + ' no vídeo todo')];
  if (!o.sempre) f.push(el('div', 'linha', [campo('Início (s)', num(o.ini || 0, function(v){ o.ini = Math.max(0, v); if (o.fim <= o.ini) o.fim = o.ini + 1; atualizaTudo(); agendaSalvar('Tempo: ' + rot.toLowerCase()); })),
    campo('Fim (s)', num(o.fim || 0, function(v){ o.fim = Math.max((o.ini || 0) + .2, v); atualizaTudo(); agendaSalvar('Tempo: ' + rot.toLowerCase()); })),
    botao('Começar no cursor', function(){ var d = (o.fim || 0) - (o.ini || 0); o.ini = +T.toFixed(2); o.fim = +(T + Math.max(.5, d || 3)).toFixed(2); atualizaTudo(); agendaSalvar('Tempo: ' + rot.toLowerCase()); inspetor(); }),
    botao('Até o fim do trecho', function(){ var f2 = fonteEm((o.ini || 0) + .01); if (f2){ var m = mapa()[f2.i]; o.fim = +(m.o + m.fim - m.ini).toFixed(2); atualizaTudo(); agendaSalvar('Tempo: ' + rot.toLowerCase()); inspetor(); } })]));
  return f; }
function acoesItem(id){ return el('span', 'linha', [botao('Copiar', function(){ selEl = id; copiar(false); }), botao('Duplicar', function(){ selEl = id; duplicar(); }), botao('Apagar', function(){ selEl = id; apagarSel(); })]); }

// ---------- cartões de cada elemento (usados no Layout e nas Propriedades) ----------
function cartao(id, titulo, filhos){ return el('div', 'cartao' + (selEl === id ? ' sel' : ''), [el('div', 'topo', [el('b', null, titulo),
  botao('Padrão', function(){ P.layout[id] = clone(padrao(id)); recalc(); pinta(); agendaSalvar('Restaurar ' + titulo.toLowerCase()); mostraAba(); }, 'Restaurar ' + titulo)])].concat(filhos)); }
var CARDS = {
  janela: function(){ var J = lay('janela'); return cartao('janela', 'Janela do vídeo', [el('div', 'grade2', [deslize(J, 'y', 'Topo (px)', -400, H, 2, 'Mover janela do vídeo'), deslize(J, 'h', 'Altura (px)', 300, H, 2, 'Altura da janela do vídeo'),
    deslize(J, 'alinhar', 'Vídeo dentro da janela', -400, 400, 2, 'Alinhar vídeo na janela'), campo('Sobra da janela', opcoes(J, 'preencher', [['desfoque', 'Imagem desfocada'], ['cor', 'Cor do fundo']], 'Fundo da janela'))])]); },
  geral: function(){ var G = lay('geral'); return cartao('geral', 'Imagem e fundo', [el('div', 'grade2', [deslize(G, 'brilho', 'Brilho', .5, 1.6, .01, 'Brilho', pct), deslize(G, 'contraste', 'Contraste', .5, 1.8, .01, 'Contraste', pct),
    deslize(G, 'saturacao', 'Saturação', 0, 2, .01, 'Saturação', pct), corIn(G, 'fundo', 'Cor do fundo', 'Cor do fundo')])]); },
  legenda: function(){ var Lg = lay('legenda'); P.estilo = P.estilo || {}; if (P.estilo.tamanho == null) P.estilo.tamanho = 84; return cartao('legenda', 'Legenda', camposLegendaAgora().concat([selFonte(P.estilo, 'fonte', 'Fonte da legenda'), deslize(P.estilo, 'tamanho', 'Tamanho da fonte', 30, 160, 1, 'Tamanho da legenda', function(v){ return v + ' px'; }), coresLegenda(P.estilo), chk(Lg, 'visivel', 'Mostrar legenda', 'Legenda'), el('div', 'grade2', [deslize(Lg, 'dx', 'Horizontal', -W / 2, W / 2, 2, 'Mover legenda'), deslize(Lg, 'dy', 'Vertical', -H, 500, 2, 'Mover legenda'),
])], camposFundo(Lg, 'legenda', '#000000', .72), camposEstilo(Lg, 'legenda', 7, 'borda')).concat(camposCaixa(Lg, 'legenda', W - 80, 1.31), [ el('div', 'linha', [botao('✏️ Editar esta frase', function(){ editarFraseEm(T); }), botao('Todo o texto da legenda', function(){ aba = 'legenda'; abrePainel(); })]), el('p', 'nota-ajuda', 'Tamanho da letra e palavras por vez: painel Legendas.')])); },
  gancho: function(){ var Gc = lay('gancho'); return cartao('gancho', 'Título do topo (depois do gancho)', camposGancho(false).concat([selFonte(Gc, 'fonte', 'Fonte do título'), coresGancho(Gc), chk(Gc, 'visivel', 'Mostrar', 'Título do topo'), el('div', 'grade2', [deslize(Gc, 'x', 'Horizontal', 0, W, 2, 'Mover título'), deslize(Gc, 'y', 'Vertical', 0, H, 2, 'Mover título'),
    deslize(Gc, 'escala', 'Tamanho da fonte', .4, 2.5, .02, 'Tamanho do título', function(v){ return Math.round(58 * v) + ' px'; })])], camposCaixa(Gc, 'título', W - 120, 1.17), camposFundo(Gc, 'título', '#000000', .6), camposEstilo(Gc, 'título', 5))); },
  gancho_grande: function(){ var GG = lay('gancho_grande'); return cartao('gancho_grande', 'Gancho grande (primeiros segundos)', camposGancho(true).concat([selFonte(GG, 'fonte', 'Fonte do gancho'), coresGancho(GG), chk(GG, 'visivel', 'Mostrar', 'Gancho grande'), el('div', 'grade2', [deslize(GG, 'dx', 'Horizontal', -W / 2, W / 2, 2, 'Mover gancho'), deslize(GG, 'dy', 'Vertical', -800, H, 2, 'Mover gancho'),
    deslize(GG, 'escala', 'Tamanho da fonte', .4, 2.5, .02, 'Tamanho do gancho', function(v){ return Math.round(92 * v) + ' px'; }), deslize(GG, 'escurecer', 'Escurecer o vídeo', 0, .9, .01, 'Escurecer gancho', pct)])], camposCaixa(GG, 'gancho', W - 120, 1.13), camposFundo(GG, 'gancho', '#000000', .6), camposEstilo(GG, 'gancho', 7))); },
  arrobas: function(){ var A = lay('arrobas'), at = el('input'); at.type = 'text'; at.value = A.texto; at.oninput = function(){ A.texto = at.value; pinta(); agendaSalvar('Texto do @'); };
    return cartao('arrobas', '@ do rodapé', [chk(A, 'visivel', 'Mostrar', '@ do rodapé'), campo('Texto', at), selFonte(A, 'fonte', 'Fonte do @'), el('div', 'grade2', [deslize(A, 'x', 'Horizontal', 0, W, 2, 'Mover @'), deslize(A, 'y', 'Vertical', 0, H, 2, 'Mover @'),
      deslize(A, 'escala', 'Tamanho da fonte', .5, 4, .02, 'Tamanho do @', function(v){ return Math.round(36 * v) + ' px'; }), corIn(A, 'cor', 'Cor', 'Cor do @')])].concat(camposCaixa(A, '@ do rodapé', 0, 1.2), camposFundo(A, '@ do rodapé', '#000000', .6), camposEstilo(A, '@ do rodapé', 0), [rodapeBotoes()])); },
  logo: function(){ var LG = lay('logo'); return cartao('logo', 'Logo do rodapé', [chk(LG, 'visivel', 'Mostrar', 'Logo'), el('div', 'grade2', [deslize(LG, 'x', 'Horizontal', 0, W, 2, 'Mover logo'), deslize(LG, 'y', 'Vertical', 0, H - 20, 2, 'Mover logo'),
    deslize(LG, 'largura', 'Largura (px)', 60, 900, 2, 'Tamanho da logo')]), el('p', 'nota-ajuda', 'Para trocar a logo: Biblioteca, "Usar no rodapé".'), rodapeBotoes()]); },
  notas: function(){ var N = lay('notas'); return cartao('notas', 'Marcações (CERTO, ERRADO...)', camposNotasAgora().concat([selFonte(N, 'fonte', 'Fonte das marcações'), el('div', 'grade2', [deslize(N, 'dx', 'Horizontal', -W / 2, W / 2, 2, 'Mover marcações'), deslize(N, 'dy', 'Vertical', -400, H, 2, 'Mover marcações'),
    deslize(N, 'escala', 'Tamanho da fonte', .4, 3, .02, 'Tamanho das marcações', function(v){ return Math.round(46 * v) + ' px'; }), deslize(N, 'largura', 'Largura da caixa', 0, W, 2, 'Largura das marcações', function(v){ return +v ? v + ' px' : 'automático'; }), deslize(N, 'altura', 'Altura da caixa', 0, 500, 2, 'Altura das marcações', function(v){ return +v ? v + ' px' : 'automático'; })])], camposFundo(N, 'marcações', '#0f0f0f', 1, true), camposEstilo(N, 'marcações', 0))); },
  final: function(){ return cartao('final', 'Cartão final', camposFinal()); },
  barra: function(){ var B = lay('barra'); return cartao('barra', 'Barra de progresso', [chk(B, 'visivel', 'Mostrar', 'Barra de progresso'), chk(B, 'embaixo', 'Embaixo (desmarcado = no topo)', 'Posição da barra'),
    el('div', 'grade2', [deslize(B, 'altura', 'Espessura', 2, 40, 1, 'Espessura da barra'), corIn(B, 'cor', 'Cor', 'Cor da barra')])]); },
  sombra: function(){ var S = lay('sombra'); return cartao('sombra', 'Sombra automática atrás da legenda', [chk(S, 'visivel', 'Mostrar', 'Sombra da legenda'), el('div', 'grade2', [deslize(S, 'altura', 'Altura', 40, 800, 5, 'Altura da sombra da legenda'),
    deslize(S, 'opacidade', 'Intensidade', 0, 1, .01, 'Intensidade da sombra da legenda', pct)])]); }
};
// Texto do gancho: o mesmo texto vira o gancho grande dos primeiros segundos e, depois, o título do topo.
function camposGancho(comTempo){
  var g = el('textarea'); g.rows = 2; g.value = P.gancho || ''; g.placeholder = 'Escreva o gancho';
  g.oninput = function(){ P.gancho = g.value; P.nome = g.value || P.nome; pinta(); agendaSalvar('Texto do gancho'); nomesSelect(); };
  var d = el('input'); d.type = 'text'; d.value = (P.destaque || []).join(', '); d.placeholder = 'ex.: CASA, ROTINA';
  d.oninput = function(){ P.destaque = d.value.split(',').map(function(x){ return x.trim(); }).filter(Boolean); pinta(); agendaSalvar('Destaque do gancho'); };
  var out = [campo('Texto do gancho', g), campo('Palavras em destaque (separe por vírgula)', d)];
  if (comTempo){ if (P.t_gancho == null) P.t_gancho = T_GANCHO_PADRAO; out.push(deslize(P, 't_gancho', 'Tempo do gancho grande (s)', 0, 8, .1, 'Tempo do gancho')); }
  return out;
}
// Marcações (CERTO, ERRADO...) que aparecem neste ponto do vídeo.
function camposNotasAgora(){
  var f = fAgora(); if (!f) return [];
  var F = fonte(f.de), agora = (F.notas || []).filter(function(n){ return f.ts >= n.ini && f.ts <= n.fim; });
  var out = agora.map(function(n){ var tx = el('input'); tx.type = 'text'; tx.value = n.texto;
    tx.oninput = function(){ n.texto = tx.value; pinta(); agendaSalvar('Texto da marcação'); };
    var cs = sel([['verde', 'verde'], ['vermelho', 'vermelho'], ['branco', 'branco'], ['amarelo', 'amarelo']], n.cor); cs.onchange = function(){ n.cor = cs.value; pinta(); agendaSalvar('Cor da marcação'); };
    return el('div', 'linha', [campo('Texto da marcação', tx), campo('Cor', cs)]); });
  if (!agora.length) out.push(el('p', 'nota-ajuda', 'Nenhuma marcação neste ponto. Crie no painel Textos.'));
  return out;
}
function camposFinal(){
  var fi = P.final = P.final || { ativo:true };
  function tin(k, rot, pad){ var i = el('input'); i.type = 'text'; i.value = fi[k] || ''; i.placeholder = pad || ''; i.oninput = function(){ fi[k] = i.value; pinta(); agendaSalvar('Cartão final'); }; return campo(rot, i); }
  if (fi.escala == null) fi.escala = 1; if (!fi.cor_chamada) fi.cor_chamada = COR.branco; if (!fi.cor_linha1) fi.cor_linha1 = COR.branco; if (!fi.cor_linha2) fi.cor_linha2 = COR.verde; if (!fi.cor_slogan) fi.cor_slogan = '#d7d7d7';
  return [chk(fi, 'ativo', 'Mostrar o cartão final (2,8 s)', 'Cartão final', function(){ setTimeout(atualizaTudo); }), tin('chamada', 'Chamada', 'SIGA PARA MAIS DICAS'),
    el('div', 'grade2', [tin('linha1', 'Primeiro @', '@luiznunest'), tin('linha2', 'Segundo @', '@intusfit')]), tin('slogan', 'Slogan'), selFonte(fi, 'fonte', 'Fonte do cartão final'), deslize(fi, 'escala', 'Tamanho da fonte', .6, 1.5, .01, 'Tamanho do cartão final', pct),
    el('div', 'linha', [corIn(fi, 'cor_chamada', 'Chamada', 'Cor da chamada'), corIn(fi, 'cor_linha1', '1º @', 'Cor do 1º @'), corIn(fi, 'cor_linha2', '2º @', 'Cor do 2º @'), corIn(fi, 'cor_slogan', 'Slogan', 'Cor do slogan')])].concat(camposFundo(fi, 'cartão final', '#000000', .6), camposEstilo(fi, 'cartão final', 0));
}
function temTexto(id){ return /^(legenda|gancho|gancho_grande|arrobas|notas|final|texto:\d+)$/.test(id || ''); }
// Toque duplo num texto da prévia ou da linha do tempo: abre o texto numa janela, em qualquer aparelho.
function editarTextoDe(id){
  if (!P || !id) return; pausar();
  selEl = id; seleciona(); abreInsp();
  setTimeout(function(){ var c = $('insp-corpo'); if (!c) return; var a = c.querySelector('textarea, input[type=text]'); if (a){ a.focus(); try { a.select(); } catch (e) {} a.scrollIntoView({ block:'center' }); } }, 60);
}
function coresGancho(g){ if (!g.cor) g.cor = lay('gancho').cor || COR.verde; if (!g.cor_texto) g.cor_texto = COR.branco;
  return el('div', 'linha', [corIn(g, 'cor_texto', 'Cor do texto', 'Cor do texto do gancho'), corIn(g, 'cor', 'Cor das palavras em destaque', 'Cor do destaque')]); }
function coresLegenda(est){
  var cs = (est.cores && est.cores.length ? est.cores : ['amarelo', 'verde']).map(cor);
  var hex = function(v){ v = cor(v); return /^#[0-9a-f]{6}$/i.test(v) ? v : '#ffffff'; };
  var c1 = el('input'); c1.type = 'color'; c1.value = hex(cs[0]); var c2 = el('input'); c2.type = 'color'; c2.value = hex(cs[1] || cs[0]); var cb = el('input'); cb.type = 'color'; cb.value = hex(est.cor_base || COR.branco);
  var duas = el('input'); duas.type = 'checkbox'; duas.checked = cs.length > 1;
  function upd(){ est.cores = duas.checked ? [c1.value, c2.value] : [c1.value]; est.cor_base = cb.value; c2.disabled = !duas.checked; recalc(); pinta(); agendaSalvar('Cores da legenda'); }
  c1.oninput = c2.oninput = cb.oninput = duas.onchange = upd; c2.disabled = !duas.checked;
  return el('div', 'linha', [el('label', 'chk', [c1, ' palavra falada']), el('label', 'chk', [c2, ' 2ª cor']), el('label', 'chk', [duas, ' alternar']), el('label', 'chk', [cb, ' demais palavras'])]);
}
// A frase da legenda que está no ponto do cursor, para editar direto no painel da direita.
function fraseAgora(){ var f = fonteEm(T); if (!f) return null;
  var fr = frasesDe(f.de), q = null; fr.forEach(function(x){ if (!q && f.ts >= x.ini - .05 && f.ts <= x.fim + .3) q = x; });
  if (!q){ var melhor = null; fr.forEach(function(x){ var dd = Math.min(Math.abs(x.ini - f.ts), Math.abs(x.fim - f.ts)); if (dd < 1.5 && (!melhor || dd < melhor.d)) melhor = { q:x, d:dd }; }); q = melhor && melhor.q; }
  return { f:f, q:q }; }
function camposLegendaAgora(){
  var a = fraseAgora(), tx = el('textarea'); tx.rows = 2; tx.id = 'insp-frase';
  if (!a){ return [el('p', 'nota-ajuda', 'Ponha o cursor num trecho de vídeo para editar a legenda daquele ponto.')]; }
  if (a.q){ tx.value = a.q.texto; tx.oninput = function(){ reescreveFrase(a.f.de, a.q, tx.value); recalc(); pinta(); desenhaTimeline(); agendaSalvar('Editar legenda'); }; }
  else { tx.placeholder = 'Não há legenda neste ponto. Escreva para criar uma frase de 2,5 s.'; var criada = null;
    tx.oninput = function(){ var F = fonte(a.f.de); F.palavras = F.palavras || [];
      if (!criada){ criada = { ini:a.f.ts, fim:a.f.ts + 2.5, ultimo:a.f.ts }; }
      reescreveFrase(a.f.de, criada, tx.value); recalc(); pinta(); desenhaTimeline(); agendaSalvar('Nova legenda'); }; }
  return [campo('Texto da legenda neste ponto (' + fmt(T) + ')', tx), el('div', 'linha', [botao('Frase do ponto atual', function(){ inspetor(); }), botao('Todo o texto da legenda', function(){ aba = 'legenda'; abrePainel(); })])];
}
// Fundo colorido atrás de um texto: liga/desliga, cor e opacidade.
function camposFundo(o, nome, corPad, opPad, ligadoPad){
  if (o.fundo == null) o.fundo = !!ligadoPad; if (!o.cor_fundo) o.cor_fundo = corPad; if (o.fundo_opac == null || o.fundo_opac === '') o.fundo_opac = opPad;
  var out = [chk(o, 'fundo', 'Fundo colorido atrás do texto', 'Fundo: ' + nome, function(){ setTimeout(inspetor); })];
  if (o.fundo) out.push(el('div', 'grade2', [corIn(o, 'cor_fundo', 'Cor do fundo', 'Cor do fundo: ' + nome), deslize(o, 'fundo_opac', 'Opacidade do fundo', 0, 1, .01, 'Opacidade do fundo: ' + nome, pct)]));
  return out;
}
// Contorno e sombra de um texto: cor e espessura do contorno; sombra com cor, força, desfoque e distância.
function camposEstilo(o, nome, padrao, k){ k = k || 'contorno';
  if (o[k] == null || o[k] === '') o[k] = padrao; if (!o.cor_contorno) o.cor_contorno = '#000000'; if (!o.cor_sombra) o.cor_sombra = '#000000';
  if (o.sombra_forca == null) o.sombra_forca = .7; if (o.sombra_desf == null) o.sombra_desf = 12; if (o.sombra_dist == null) o.sombra_dist = 6;
  var px = function(v){ return +v ? v + ' px' : 'sem contorno'; };
  var out = [el('div', 'campo', [el('b', null, 'Contorno e sombra')]), el('div', 'grade2', [deslize(o, k, 'Contorno', 0, 24, 1, 'Contorno: ' + nome, px), corIn(o, 'cor_contorno', 'Cor do contorno', 'Cor do contorno: ' + nome)]),
    chk(o, 'sombra', 'Sombra atrás do texto', 'Sombra: ' + nome, function(){ setTimeout(inspetor); })];
  if (o.sombra) out.push(el('div', 'grade2', [corIn(o, 'cor_sombra', 'Cor da sombra', 'Cor da sombra: ' + nome), deslize(o, 'sombra_forca', 'Força', 0, 1, .01, 'Força da sombra: ' + nome, pct),
    deslize(o, 'sombra_desf', 'Desfoque', 0, 60, 1, 'Desfoque da sombra: ' + nome, function(v){ return v + ' px'; }), deslize(o, 'sombra_dist', 'Distância', -30, 40, 1, 'Distância da sombra: ' + nome, function(v){ return v + ' px'; })]));
  return out;
}
// Controles da caixa de texto: largura, palavras e caracteres por linha, espaço entre linhas.
function camposCaixa(o, nome, largPadrao, entrePadrao){
  if (o.largura == null) o.largura = largPadrao; if (o.por_linha == null) o.por_linha = 0; if (o.car_linha == null) o.car_linha = 0; if (o.entrelinha == null) o.entrelinha = entrePadrao;
  if (o.altura == null) o.altura = 0;
  var auto = function(v){ return +v ? v : 'automático'; }, cx = function(v){ return +v ? Math.round(v / W * 100) + '% da tela' : 'automático'; }, px = function(v){ return +v ? v + ' px' : 'automático'; };
  return [el('div', 'grade2', [deslize(o, 'largura', 'Largura da caixa', 0, W, 2, 'Largura: ' + nome, cx), deslize(o, 'altura', 'Altura da caixa', 0, Math.round(H * .6), 2, 'Altura: ' + nome, px), deslize(o, 'entrelinha', 'Espaço entre linhas', .8, 2.2, .01, 'Entrelinha: ' + nome, function(v){ return (+v).toFixed(2); }),
    deslize(o, 'por_linha', 'Palavras por linha', 0, 12, 1, 'Palavras por linha: ' + nome, auto), deslize(o, 'car_linha', 'Caracteres por linha', 0, 60, 1, 'Caracteres por linha: ' + nome, auto)]),
    el('p', 'nota-ajuda', 'Na prévia, puxe o quadradinho verde do canto da caixa: para o lado muda a largura, para cima ou para baixo muda a altura (o texto fica no meio). Segurando Shift no computador, o canto muda o tamanho da letra. Em "automático" a linha quebra só pela largura. Enter no texto também quebra a linha.')];
}
function rodapeBotoes(){ var A = lay('arrobas'), LG = lay('logo'); return el('div', 'linha', [
  botao('▲ Rodapé', function(){ A.y -= 20; LG.y -= 20; pinta(); agendaSalvar('Subir rodapé'); inspetor(); }), botao('▼ Rodapé', function(){ A.y += 20; LG.y += 20; pinta(); agendaSalvar('Descer rodapé'); inspetor(); }),
  formato().guia === 'reel' ? botao('Tirar da área do Instagram', function(){ A.y = 1420; LG.y = 1490; pinta(); agendaSalvar('Rodapé acima do Instagram'); inspetor(); }) : el('span')]); }
function cardTexto(i){ var x = P.textos[i], id = 'texto:' + i, tx = el('textarea'); tx.rows = 2; tx.value = x.texto; tx.oninput = function(){ x.texto = tx.value; pinta(); desenhaTimeline(); agendaSalvar('Texto livre'); };
  return el('div', 'cartao' + (selEl === id ? ' sel' : ''), [el('div', 'topo', [el('b', null, 'Texto ' + (i + 1)), acoesItem(id)]), tx, selFonte(x, 'fonte', 'Fonte do texto'),
    el('div', 'grade2', [deslize(x, 'tam', 'Tamanho da fonte', 16, 240, 1, 'Tamanho do texto', function(v){ return v + ' px'; }), deslize(x, 'x', 'Horizontal', 0, W, 2, 'Mover texto'), deslize(x, 'y', 'Vertical', 0, H, 2, 'Mover texto')]),
    el('div', 'linha', [corIn(x, 'cor', 'Cor', 'Cor do texto'), ])].concat(camposFundo(x, 'texto', '#0f0f0f', .88), camposEstilo(x, 'texto', x.borda ? 5 : 0), camposCaixa(x, 'texto', W, 1.2), tempoItem(x, 'Texto'))); }
function cardSombra(i){ var s = P.sombras[i], id = 'sombra:' + i;
  return el('div', 'cartao' + (selEl === id ? ' sel' : ''), [el('div', 'topo', [el('b', null, 'Sombra ' + (i + 1)), acoesItem(id)]),
    el('div', 'linha', [campo('Tipo', opcoes(s, 'tipo', [['vinheta', 'Vinheta'], ['gradiente', 'Degradê'], ['retangulo', 'Retângulo']], 'Tipo da sombra')),
      s.tipo === 'gradiente' ? campo('Escuro em', opcoes(s, 'dir', [['baixo', 'baixo'], ['cima', 'cima'], ['esq', 'esquerda'], ['dir', 'direita']], 'Direção da sombra')) : el('span'), corIn(s, 'cor', 'Cor', 'Cor da sombra')]),
    el('div', 'grade2', [deslize(s, 'forca', 'Intensidade', 0, 1, .01, 'Intensidade da sombra', pct), s.tipo === 'vinheta' ? deslize(s, 'miolo', 'Centro limpo', 0, 1, .01, 'Centro da vinheta', pct) : el('span'),
      deslize(s, 'x', 'Esquerda', -W / 2, W, 2, 'Mover sombra'), deslize(s, 'y', 'Topo', -H / 3, H, 2, 'Mover sombra'), deslize(s, 'w', 'Largura', 40, W * 2, 2, 'Largura da sombra'), deslize(s, 'h', 'Altura', 40, H * 1.4, 2, 'Altura da sombra')])].concat(tempoItem(s, 'Sombra'))); }
function cardImagem(i){ var m = P.imagens[i], id = 'imagem:' + i;
  return el('div', 'cartao' + (selEl === id ? ' sel' : ''), [el('div', 'topo', [el('b', null, m.nome || 'Imagem ' + (i + 1)), acoesItem(id)]),
    el('div', 'grade2', [deslize(m, 'w', 'Largura', 30, W * 2, 2, 'Tamanho da imagem'), deslize(m, 'x', 'Horizontal', 0, W, 2, 'Mover imagem'), deslize(m, 'y', 'Vertical', 0, H, 2, 'Mover imagem'),
      deslize(m, 'opacidade', 'Opacidade', 0, 1, .01, 'Opacidade da imagem', pct), deslize(m, 'raio', 'Cantos arredondados', 0, 200, 1, 'Cantos da imagem')]),
    el('div', 'linha', [botao('Centralizar', function(){ m.x = W / 2; m.y = H / 2; pinta(); agendaSalvar('Centralizar imagem'); inspetor(); }), botao('Tela inteira', function(){ var im = imgDe(m.url), ar = im.naturalWidth ? im.naturalHeight / im.naturalWidth : 1; m.w = Math.max(W, H / ar); m.x = W / 2; m.y = H / 2; pinta(); agendaSalvar('Imagem na tela inteira'); inspetor(); })])].concat(tempoItem(m, 'Imagem'))); }

// ---------- painéis da esquerda ----------
var TITULOS = { midia:'Mídia e montagem', biblioteca:'Biblioteca', ia:'Edição automática (IA)', textos:'Textos', legenda:'Legendas', efeitos:'Sombras e efeitos', layout:'Layout', audio:'Áudio', historico:'Histórico', ajustes:'Propriedades' };
function painelTextos(C){
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Gancho')].concat(camposGancho(true), [el('p', 'nota-ajuda', 'Dica: toque duas vezes em qualquer texto da prévia (gancho, legenda, textos, @, marcações, cartão final) para editar ali mesmo.')])));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Textos na tela'), el('div', 'linha', [
      botao('+ Texto no cursor', function(){ P.textos.push({ texto:'SEU TEXTO', ini:+T.toFixed(2), fim:+Math.min(dur(), T + 3).toFixed(2), x:W / 2, y:Math.round(H * .47), tam:64, cor:'#ffffff', fundo:true, borda:false });
        selEl = 'texto:' + (P.textos.length - 1); atualizaTudo(); agendaSalvar('Adicionar texto'); seleciona(); }),
      botao('+ Chamada no fim', function(){ P.textos.push({ texto:'COMENTE "EU QUERO"', ini:+Math.max(0, durFala() - 4).toFixed(2), fim:+durFala().toFixed(2), x:W / 2, y:Math.round(H * .62), tam:70, cor:'#7fff00', fundo:true, borda:false });
        selEl = 'texto:' + (P.textos.length - 1); atualizaTudo(); agendaSalvar('Adicionar chamada'); seleciona(); })]),
    el('p', 'nota-ajuda', 'Cada texto vira um bloco azul na faixa Texto da linha do tempo: arraste para mudar quando aparece e puxe as bordas para mudar a duração.')].concat(P.textos.map(function(_, i){ return cardTexto(i); }))));
  var f = fAgora(), F = fonte(f ? f.de : ''), lista = el('div', 'lista');
  (F.notas || []).forEach(function(n, i){
    var tx = el('input'); tx.type = 'text'; tx.value = n.texto; tx.oninput = function(){ n.texto = tx.value; pinta(); agendaSalvar('Texto da marcação'); };
    var cs = sel([['verde', 'verde'], ['vermelho', 'vermelho'], ['branco', 'branco'], ['amarelo', 'amarelo']], n.cor); cs.onchange = function(){ n.cor = cs.value; pinta(); agendaSalvar('Cor da marcação'); };
    lista.appendChild(el('div', 'item', [el('span', 't', fmt(n.ini)), el('div', 'linha', [tx, cs, campo('dura (s)', num(n.fim - n.ini, function(v){ n.fim = n.ini + Math.max(.3, v); pinta(); agendaSalvar('Duração da marcação'); }))]),
      botao('×', function(){ F.notas.splice(i, 1); pinta(); agendaSalvar('Apagar marcação'); mostraAba(); }, 'Apagar marcação')]));
  });
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Marcações presas ao vídeo · ' + nomeFonte(f ? f.de : '')), lista,
    botao('+ Marcação no cursor', function(){ var f2 = fonteEm(T); if (!f2) return; var F2 = fonte(f2.de); F2.notas = F2.notas || []; F2.notas.push({ ini:+f2.ts.toFixed(2), fim:+(f2.ts + 3).toFixed(2), texto:'CERTO', cor:'verde' }); pinta(); agendaSalvar('Adicionar marcação'); mostraAba(); })]));
  var fi = P.final = P.final || { ativo:true };
  function tin(k, rot, pad){ var i = el('input'); i.type = 'text'; i.value = fi[k] || ''; i.placeholder = pad || ''; i.oninput = function(){ fi[k] = i.value; pinta(); agendaSalvar('Cartão final'); }; return campo(rot, i); }
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Cartão final'), chk(fi, 'ativo', 'Mostrar o cartão final (2,8 s)', 'Cartão final', function(){ setTimeout(atualizaTudo); }), tin('chamada', 'Chamada', 'SIGA PARA MAIS DICAS'),
    el('div', 'grade2', [tin('linha1', 'Primeiro @', '@luiznunest'), tin('linha2', 'Segundo @', '@intusfit')]), tin('slogan', 'Slogan'), botao('Ver o cartão final', function(){ pausar(); irPara(durFala() + 1); })]));
}
function presetsSombra(){ return [
  ['Vinheta na tela toda', { tipo:'vinheta', x:0, y:0, w:W, h:H, forca:.75, miolo:.45 }],
  ['Sombra embaixo', { tipo:'gradiente', dir:'baixo', x:0, y:Math.round(H * .68), w:W, h:Math.round(H * .32), forca:.85 }],
  ['Sombra em cima', { tipo:'gradiente', dir:'cima', x:0, y:0, w:W, h:Math.round(H * .27), forca:.8 }],
  ['Sombra na lateral', { tipo:'gradiente', dir:'esq', x:0, y:0, w:Math.round(W * .4), h:H, forca:.7 }],
  ['Faixa escura', { tipo:'retangulo', x:0, y:Math.round(H * .43), w:W, h:Math.round(H * .15), forca:.55 }]
]; }
function painelEfeitos(C){
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Adicionar sombra ou vinheta'), el('div', 'linha', presetsSombra().map(function(p){ return botao('+ ' + p[0], function(){ var s = clone(p[1]); s.cor = '#000000'; s.sempre = true; s.ini = 0; s.fim = +dur().toFixed(2);
      P.sombras.push(s); selEl = 'sombra:' + (P.sombras.length - 1); atualizaTudo(); agendaSalvar('Adicionar ' + p[0].toLowerCase()); seleciona(); }); })),
    el('p', 'nota-ajuda', 'Na prévia, pegue a sombra pela borda para mover e pelo quadrado verde do canto para mudar o tamanho. Na faixa Sombra da linha do tempo você escolhe quando ela aparece.')]));
  P.sombras.forEach(function(_, i){ C.appendChild(cardSombra(i)); });
  var G = lay('geral');
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Cor da imagem'), el('div', 'grade2', [deslize(G, 'brilho', 'Brilho', .5, 1.6, .01, 'Brilho', pct), deslize(G, 'contraste', 'Contraste', .5, 1.8, .01, 'Contraste', pct), deslize(G, 'saturacao', 'Saturação', 0, 2, .01, 'Saturação', pct)])]));
}
function painelLayout(C){
  C.appendChild(el('p', 'nota-ajuda', 'Clique num elemento da prévia para selecionar e arraste para mover; a roda do mouse muda o tamanho; Alt + setas move de 2 em 2 pixels.'));
  ['janela', 'legenda', 'gancho', 'gancho_grande', 'arrobas', 'logo', 'notas', 'barra', 'sombra', 'geral'].forEach(function(k){ C.appendChild(CARDS[k]()); });
  C.appendChild(botao('Restaurar todas as posições de fábrica', function(){ var u = P.layout && P.layout.logo && P.layout.logo.url; P.layout = {}; if (u) lay('logo').url = u; if (P.estilo) P.estilo.ajuste_y = 0; selEl = null; atualizaTudo(); agendaSalvar('Restaurar layout de fábrica'); mostraAba(); }));
}
function painelLegenda(C){
  var est = P.estilo = P.estilo || {};
  var cs = (est.cores || ['amarelo', 'verde']).map(cor), alt = sel([['2', 'Alternar duas cores'], ['1', 'Uma cor só']], cs.length > 1 ? '2' : '1');
  var c1 = el('input'); c1.type = 'color'; c1.value = cs[0];
  var c2 = el('input'); c2.type = 'color'; c2.value = cs[1] || COR.verde;
  function upd(){ est.cores = alt.value === '2' ? [c1.value, c2.value] : [c1.value]; c2.disabled = alt.value !== '2'; recalc(); pinta(); agendaSalvar('Cores da legenda'); }
  alt.onchange = upd; c1.oninput = upd; c2.oninput = upd; c2.disabled = alt.value !== '2';
  var cb = el('input'); cb.type = 'color'; cb.value = est.cor_base || COR.branco; cb.oninput = function(){ est.cor_base = cb.value; pinta(); agendaSalvar('Cor base da legenda'); };
  var porG = sel([1, 2, 3, 4, 5, 6, 7, 8, 10, 12].map(function(n){ return [n, n + (n > 1 ? ' palavras' : ' palavra')]; }), est.palavras_por_grupo || 3); porG.onchange = function(){ est.palavras_por_grupo = +porG.value; mudou('Palavras por vez'); };
  if (est.car_vez == null) est.car_vez = 18;
  if (est.aumento == null) est.aumento = 1.1; if (est.tamanho == null) est.tamanho = 84; if (est.maiusculas == null) est.maiusculas = true;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Estilo'), el('div', 'linha', [campo('Palavra falada', alt), el('label', 'chk', [c1, ' cor 1']), el('label', 'chk', [c2, ' cor 2']), el('label', 'chk', [cb, ' demais'])]),
    el('div', 'linha', [campo('Por vez na tela', porG), chk(est, 'maiusculas', 'Maiúsculas', 'Maiúsculas', function(){ setTimeout(atualizaTudo); })]),
    el('div', 'grade2', [deslize(est, 'tamanho', 'Tamanho', 40, 140, 2, 'Tamanho da legenda'), deslize(est, 'aumento', 'Destaque da palavra', 1, 1.5, .01, 'Destaque da palavra', pct),
      deslize(est, 'car_vez', 'Caracteres na tela por vez', 6, 80, 1, 'Caracteres por vez')]),
    el('p', 'nota-ajuda', 'Largura da caixa, palavras e caracteres por linha e espaço entre linhas: toque na legenda da prévia (ou em Layout > Legenda).')]));
  var fino = !!est._fino;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Texto da legenda'), el('p', 'nota-ajuda', 'Escreva o que quiser em cada frase: corrigir, trocar, juntar ou apagar palavras. O tempo da frase continua o mesmo. Deixe a frase vazia para ela sumir. Dica: toque duas vezes na legenda do vídeo para editar a frase que está na tela.'),
    el('label', 'chk', [(function(){ var c = el('input'); c.type = 'checkbox'; c.checked = fino; c.onchange = function(){ est._fino = c.checked; mostraAba(); }; return c; })(), ' Ajuste fino, palavra por palavra'])]));
  chavesUsadas().forEach(function(key){
    var F = fonte(key); F.palavras = F.palavras || [];
    if (fino){
      var box = el('div', 'palavras');
      F.palavras.forEach(function(w, i){
        var inp = el('input'); inp.type = 'text'; inp.value = w.p; inp.size = Math.max(2, w.p.length); inp.dataset.i = i; inp.dataset.de = key; inp.title = fmt(w.i);
        if (reelEm(w.i, key) == null) inp.className = 'fora';
        inp.onfocus = function(){ var r = reelEm(w.i, key); if (r != null){ pausar(); irPara(r); } };
        inp.oninput = function(){ w.p = inp.value; inp.size = Math.max(2, inp.value.length); recalc(); pinta(); agendaSalvar('Corrigir legenda'); };
        box.appendChild(inp);
      });
      C.appendChild(el('div', 'grupo', [el('h3', null, nomeFonte(key)), F.palavras.length ? box : el('p', 'nota-ajuda', 'Sem legenda neste vídeo.')]));
      return;
    }
    var lista = el('div', 'frases'), fr = frasesDe(key), vis = 0;
    fr.forEach(function(q){
      var r = reelEm(q.ini, key), dentro = r != null; if (dentro) vis++;
      var tx = el('textarea'); tx.rows = 1; tx.value = q.texto; tx.spellcheck = true;
      var tempo = el('button', 'tempo', dentro ? fmt(r) : 'cortado'); tempo.type = 'button'; tempo.title = 'Ir para este ponto';
      tempo.onclick = function(){ var rr = reelEm(q.ini, key); if (rr != null){ pausar(); irPara(rr); } };
      var x = el('button', 'apaga', '✕'); x.type = 'button'; x.title = 'Apagar esta frase';
      var linha = el('div', 'frase' + (dentro ? '' : ' fora'), [tempo, tx, x]); linha.dataset.de = key; linha.dataset.ini = q.ini; linha.dataset.fim = q.fim;
      function ajusta(){ tx.style.height = 'auto'; tx.style.height = tx.scrollHeight + 'px'; }
      tx.onfocus = function(){ var rr = reelEm(q.ini, key); if (rr != null){ pausar(); irPara(rr); } };
      tx.oninput = function(){ ajusta(); var n = reescreveFrase(key, q, tx.value); recalc(); pinta(); desenhaTimeline(); agendaSalvar('Editar legenda'); if (!n) linha.classList.add('vazia'); else linha.classList.remove('vazia'); };
      x.onclick = function(){ reescreveFrase(key, q, ''); mudou('Apagar frase da legenda'); mostraAba(); };
      lista.appendChild(linha); requestAnimationFrame(ajusta);
    });
    C.appendChild(el('div', 'grupo', [el('h3', null, nomeFonte(key) + (fr.length ? ' · ' + vis + ' frases no vídeo' : '')), fr.length ? lista : el('p', 'nota-ajuda', 'Sem legenda neste vídeo. Escreva abaixo para inserir no cursor.')]));
  });
  var nova = el('textarea'); nova.rows = 2; nova.placeholder = 'Nova frase para entrar no ponto do cursor';
  var segs = el('input'); segs.type = 'number'; segs.min = .5; segs.max = 30; segs.step = .5; segs.value = 2.5;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Inserir frase'), nova, el('div', 'linha', [campo('Duração (s)', segs), botao('Inserir no cursor', function(){
      var f = fonteEm(T), tx = nova.value.trim(); if (!f || !tx){ estado(f ? 'Escreva a frase' : 'Ponha o cursor num trecho de vídeo', 'erro'); return; }
      var F = fonte(f.de); F.palavras = F.palavras || []; var d = cl(+segs.value || 2.5, .3, 30);
      F.palavras = F.palavras.filter(function(w){ return w.i < f.ts - 1e-3 || w.i > f.ts + d; });
      distribui(tx, f.ts, f.ts + d).forEach(function(w){ F.palavras.push(w); }); F.palavras.sort(function(a, b){ return a.i - b.i; });
      nova.value = ''; mudou('Inserir frase na legenda'); mostraAba(); })]),
    el('p', 'nota-ajuda', 'A frase nova substitui o que já havia de legenda nesse intervalo.')]));
}
// Frases da legenda de um vídeo: quebra no ponto final, na vírgula longa, em pausa da fala ou a cada 8 palavras.
function frasesDe(key){
  var ws = fonte(key).palavras || [], out = [], cur = null;
  ws.forEach(function(w){ if (!String(w.p).trim()) return;
    if (cur && (w.i - cur.fim > .6 || cur.n >= 12)){ out.push(cur); cur = null; }
    if (!cur) cur = { ini:w.i, fim:w.f, ultimo:w.i, partes:[], n:0 };
    cur.partes.push(String(w.p).trim()); cur.fim = Math.max(cur.fim, w.f); cur.ultimo = w.i; cur.n++;
    var pw = String(w.p).trim(); if (w.fq || /[.?!]$/.test(pw) || (cur.n >= 6 && /[,;:]$/.test(pw))){ out.push(cur); cur = null; } });
  if (cur) out.push(cur);
  out.forEach(function(q){ q.texto = q.partes.join(' '); delete q.partes; }); return out;
}
// Espalha as palavras de um texto entre ini e fim, cada uma com tempo proporcional ao tamanho.
function distribui(texto, ini, fim){
  var ps = String(texto).trim().split(/\s+/).filter(Boolean); if (!ps.length) return [];
  var pesos = ps.map(function(p){ return Math.max(2, p.length) + 1; }), tot = pesos.reduce(function(a, b){ return a + b; }, 0), t = ini, d = Math.max(.1, fim - ini), out = [];
  ps.forEach(function(p, k){ var dd = d * pesos[k] / tot; out.push({ p:p, i:+t.toFixed(3), f:+(t + dd).toFixed(3) }); t += dd; }); out[out.length - 1].fq = 1; return out;
}
// Troca o texto de uma frase sem mexer no tempo dela. Devolve quantas palavras ficaram.
function reescreveFrase(key, q, texto){
  var F = fonte(key); F.palavras = (F.palavras || []).filter(function(w){ return w.i < q.ini - 1e-4 || w.i > q.ultimo + 1e-4; });
  var novas = distribui(texto, q.ini, q.fim); novas.forEach(function(w){ F.palavras.push(w); }); F.palavras.sort(function(a, b){ return a.i - b.i; });
  q.ultimo = novas.length ? novas[novas.length - 1].i : q.ini; return novas.length;
}
// Abre a frase que está tocando (ou a do tempo pedido) para editar numa janela.
function editarFraseEm(t){ if (t != null && Math.abs(t - T) > .01) irPara(t); editarTextoDe('legenda'); }
function marcaPalavra(f){ if (!f) return; $('conteudo').querySelectorAll('.frase').forEach(function(l){ l.classList.toggle('agora', l.dataset.de === f.de && f.ts >= +l.dataset.ini - .02 && f.ts <= +l.dataset.fim + .05); }); $('conteudo').querySelectorAll('.palavras input').forEach(function(inp){ var w = (fonte(inp.dataset.de).palavras || [])[+inp.dataset.i]; inp.classList.toggle('agora', !!w && inp.dataset.de === f.de && f.ts >= w.i && f.ts <= w.f + .05); }); }
function painelAudio(C){
  var au = P.audio = P.audio || { voz:1, musica:.14 }; if (au.musica == null) au.musica = .14;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Volumes'), deslize(au, 'voz', 'Voz', 0, 1.5, .01, 'Volume da voz', pct), deslize(au, 'musica', 'Música de fundo', 0, 1, .01, 'Volume da música', pct),
    el('p', 'nota-ajuda', 'A música separada só existe nos vídeos que o Claude preparou. Vídeos enviados por aqui tocam o som original no controle de Voz. Acima de 100% só vale na exportação.')]));
  C.querySelectorAll('input[type=range]').forEach(function(r){ r.addEventListener('input', volumes); });
}

// ---------- biblioteca ----------
var bibFiltro = 'todos';
function itensBib(){ return BIB_FIXOS.concat(Object.keys(BIB).map(function(k){ return BIB[k]; }).sort(function(a, b){ return (b.criado_em || '').localeCompare(a.criado_em || ''); })); }
function painelBiblioteca(C){
  var tipoNovo = sel([['logo', 'Logo'], ['foto', 'Foto'], ['video', 'Vídeo (intro, vinheta, encerramento)']], 'foto');
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Enviar para a biblioteca'), el('div', 'linha', [campo('Salvar como', tipoNovo), botao('Escolher arquivo', function(){ enviarBib(tipoNovo.value); })]),
    el('p', 'nota-ajuda', 'Logos, fotos e vídeos (introduções, vinhetas, encerramentos) ficam para toda a equipe, em todos os projetos. Vídeos vão para o Drive da Intus; podem ser enviados pelo celular.')]));
  C.appendChild(chips([['todos', 'Tudo'], ['logo', 'Logos'], ['foto', 'Fotos'], ['video', 'Vídeos']], bibFiltro, function(v){ bibFiltro = v; mostraAba(); }));
  var g = el('div', 'bib'), itens = itensBib().filter(function(x){ return bibFiltro === 'todos' || x.tipo === bibFiltro; });
  itens.forEach(function(x){
    var mini = el('div', 'mini'), m;
    if (x.tipo === 'video'){ m = el('video'); m.src = x.url + '#t=0.5'; m.muted = true; m.preload = 'metadata'; m.playsInline = true; m.onmouseenter = function(){ m.play().catch(function(){}); }; m.onmouseleave = function(){ m.pause(); }; }
    else { m = el('img'); m.src = x.url; m.alt = x.nome; m.loading = 'lazy'; }
    mini.appendChild(m);
    var acoes = el('div', 'acoes');
    if (x.tipo === 'video'){ acoes.appendChild(botao('No cursor', function(){ inserirVideoBib(x, 'cursor'); })); acoes.appendChild(botao('Fim', function(){ inserirVideoBib(x, 'fim'); })); acoes.appendChild(botao('Sobrepor', function(){ sobreporBib(x); }));
      if (x.react || x.exportado) acoes.appendChild(botao('Abrir para editar', function(){ projetoDeGravacao(x); }));
      acoes.appendChild(botao('Baixar', function(){ var pr = janelaProgresso('Baixando ' + x.nome); arquivoInteiro(x.url).then(function(ab){ fecha(); if (ab) baixarBlob(new Blob([ab], { type:'video/mp4' }), x.nome + '.mp4'); }).catch(function(){ dialogo('Não consegui baixar', '', [['Ok', fecha]]); }); void pr; })); }
    else { acoes.appendChild(botao('Pôr na tela', function(){ porImagem(x); }));
      if (x.tipo === 'logo') acoes.appendChild(botao('Usar no rodapé', function(){ lay('logo').url = x.url; lay('logo').visivel = true; pinta(); agendaSalvar('Trocar logo do rodapé'); estado('Logo do rodapé trocada', 'ok'); })); }
    if (!x.fixo) acoes.appendChild(botao('×', function(){ apagarBib(x); }, 'Apagar ' + x.nome));
    g.appendChild(el('div', 'cel', [mini, el('div', 'info', [el('b', null, x.nome), el('span', 't', ({ logo:'logo', foto:'foto', video:'vídeo' })[x.tipo] + (x.dur ? ' · ' + (+x.dur).toFixed(1) + ' s' : '') + (x.rascunho ? ' · rascunho' : '') + (x.exportado ? ' · exportado' : '') + (x.por_nome ? ' · ' + x.por_nome : '')), acoes])]));
  });
  C.appendChild(itens.length ? g : el('p', 'nota-ajuda', bibFiltro === 'video' ? 'Nenhum vídeo ainda. Envie introduções, vinhetas e encerramentos para usar em qualquer projeto.' : 'Nada aqui ainda.'));
}
// Botão "+" no fim da linha do tempo: tudo o que dá para acrescentar, num lugar só.
var addFiltro = 'video';
function abrirAdicionar(){
  if (!P) return; pausar();
  var box = el('div', 'grupo');
  function pinta2(){
    box.innerHTML = '';
    box.appendChild(chips([['video', 'Vídeos da biblioteca'], ['imagem', 'Logos e fotos'], ['projeto', 'Outros projetos']], addFiltro, function(v){ addFiltro = v; pinta2(); }));
    if (addFiltro === 'projeto'){
      var l = el('div', 'lista');
      Object.keys(projetos).sort().forEach(function(id){ var X = projetos[id], d = (X.trechos || []).reduce(function(a, s){ return a + s.fim - s.ini; }, 0);
        l.appendChild(el('div', 'item', [el('span', 't', d ? d.toFixed(0) + ' s' : ''), el('b', null, (X.nome || id) + (id === P.id ? ' (este)' : '')),
          el('span', 'linha', [botao('Adicionar no fim', function(){ fecha(); adicionarProjeto(id, 'fim'); }), botao('Sobrepor no cursor', function(){ fecha(); sobreporProjetoAsync(id); })])])); });
      box.appendChild(l);
    } else {
      var itens = itensBib().filter(function(x){ return addFiltro === 'video' ? x.tipo === 'video' : x.tipo !== 'video'; }), g = el('div', 'bib');
      itens.forEach(function(x){
        var mini = el('div', 'mini'), m;
        if (x.tipo === 'video'){ m = el('video'); m.src = x.url + '#t=0.5'; m.muted = true; m.preload = 'metadata'; m.playsInline = true; }
        else { m = el('img'); m.src = x.url; m.alt = x.nome; }
        mini.appendChild(m);
        var b = x.tipo === 'video' ? el('span', 'acoes', [botao('No fim', function(){ fecha(); inserirVideoBib(x, 'fim'); }), botao('Sobrepor', function(){ fecha(); sobreporBib(x); })])
          : botao('Pôr no fim', function(){ fecha(); porImagem(x, Math.max(0, durFala() - 4)); });
        g.appendChild(el('div', 'cel', [mini, el('div', 'info', [el('b', null, x.nome), el('span', 't', x.tipo === 'video' ? 'vídeo' + (x.dur ? ' · ' + x.dur.toFixed(1) + ' s' : '') : x.tipo), el('div', 'acoes', [b])])]));
      });
      box.appendChild(itens.length ? g : el('p', 'nota-ajuda', addFiltro === 'video' ? 'Nenhum vídeo na biblioteca ainda. Envie um aqui embaixo.' : 'Nada aqui ainda.'));
    }
    box.appendChild(el('div', 'linha', [
      botao('Enviar vídeo do aparelho', function(){ fecha(); enviarVideoMontagem('fim'); }),
      botao('● Gravar react', function(){ fecha(); abrirEstudio(); }),
      botao('Guardar arquivo na biblioteca', function(){ fecha(); aba = 'biblioteca'; abrePainel(); })]));
  }
  pinta2();
  dialogo('Adicionar no fim do vídeo', 'Vídeos entram como um trecho novo depois do último. Logos e fotos entram por cima dos últimos segundos (dá para arrastar na faixa Imagem depois).', [['Fechar', fecha]], box);
  $('caixa').style.maxWidth = '720px';
}
function porImagem(x, inicio){
  P.imagens.push({ url:x.url, nome:x.nome, x:W / 2, y:Math.round(H * .4), w:Math.round(W * (x.tipo === 'logo' ? .4 : .6)), ini:+(inicio == null ? T : inicio).toFixed(2), fim:+Math.min(dur(), (inicio == null ? T : inicio) + 4).toFixed(2), sempre:false, opacidade:1, raio:x.tipo === 'foto' ? 24 : 0 });
  imgDe(x.url); selEl = 'imagem:' + (P.imagens.length - 1); atualizaTudo(); agendaSalvar('Pôr ' + x.nome + ' na tela'); seleciona(); }
function enviarBib(tipo){
  escolherArquivo(tipo === 'video' ? 'video/*' : 'image/png,image/jpeg,image/webp,image/gif', function(r, nome, info){
    guardaNaBib({ tipo:tipo, nome:nome, url:r.url, w:info.w || 0, h:info.h || 0, dur:info.dur || 0 }).then(function(it){ if (!it) dialogo('O arquivo subiu, mas não entrou na lista', 'Tente de novo.', [['Ok', fecha]]); });
  });
}
function apagarBib(x){
  var ehVideo = x.tipo === 'video';
  dialogo('Tirar "' + x.nome + '" da biblioteca?', 'Projetos que já usam este arquivo continuam funcionando.' + (ehVideo ? ' Se apagar o vídeo também do Drive, esses projetos perdem o vídeo.' : ''), [['Cancelar', fecha]].concat(ehVideo ? [['Tirar e apagar do Drive', function(){ fecha(); tirarBib(x, true); }]] : []).concat([['Tirar da biblioteca', function(){ fecha(); tirarBib(x, false); }]]));
}
function tirarBib(x, apagar){ api('bib_excluir', { id:x.id, apagar_arquivo:!!apagar }).then(function(){ delete BIB[x.id]; mostraAba(); estado('Tirado da biblioteca', 'ok'); }).catch(function(e){ dialogo('Não consegui tirar', e.message || '', [['Ok', fecha]]); }); }
function escolherArquivo(accept, pronto){
  var inp = document.createElement('input'); inp.type = 'file'; inp.accept = accept; inp.style.display = 'none'; document.body.appendChild(inp);
  inp.onchange = async function(){ var f = inp.files[0]; setTimeout(function(){ inp.remove(); }, 0); if (!f) return; var nome = f.name.replace(/\.[^.]+$/, '');
    try {
      if (/^image\//.test(f.type)){ estado('Enviando ' + f.name + '...'); var im = await reduzirImagem(f), r = await api('imagem', { dataurl:im.dataurl, nome:nome }); estado('Enviado', 'ok'); pronto({ url:r.url, token:'' }, nome, { w:r.w, h:r.h }); return; }
      if (f.size > (LIMITES.video_bytes || 2147483648)){ dialogo('Arquivo grande demais', 'O limite é ' + Math.round((LIMITES.video_bytes || 2147483648) / 1073741824) + ' GB por vídeo.', [['Ok', fecha]]); return; }
      var info = await sondarVideo(f), prog = janelaProgresso('Enviando ' + f.name + ' (' + (f.size / 1048576).toFixed(0) + ' MB)');
      var up = await enviarArquivo(f, nome, info, function(x){ prog(x, 'Enviando ao Drive da Intus: ' + Math.round(x * 100) + '%'); });
      BLOBS[up.url] = Promise.resolve(info.local); fecha(); estado('Vídeo enviado', 'ok'); pronto(up, nome, info);
    } catch (e){ if (e && e.code === 'sem_acesso') return; dialogo('O envio não terminou', (e && e.message) || 'Tente de novo.', [['Ok', fecha]]); }
  };
  inp.click();
}
// ---------- edição automática por IA ----------
var TIPOS_IA = [['reel', 'Corte para reel', [30, 60]], ['anuncio', 'Anúncio', [15, 30]], ['informativo', 'Informativo / dica', [40, 90]], ['treino', 'Vídeo de treino', [30, 60]], ['depoimento', 'Depoimento', [20, 45]], ['convite', 'Convite para o teste grátis', [15, 30]]];
var iaPed = { tipo:'reel', formato:'reel', dur:'auto', obs:'' }, iaJob = null;
try { Object.assign(iaPed, JSON.parse(localStorage.getItem('intuscut-ia') || '{}')); } catch (e) {}
function guardaIa(){ try { localStorage.setItem('intuscut-ia', JSON.stringify(iaPed)); } catch (e) {} }
function palavrasTimeline(){ return chavesUsadas().reduce(function(a, k){ return a + ((fonte(k).palavras || []).length); }, 0); }
function etaIa(){ return Math.round(35 + palavrasTimeline() / 30); }
// Quadros dos vídeos da linha do tempo, para a IA "ver" o que acontece (vídeos sem fala também ganham edição).
async function quadrosParaIA(){
  var ks = chavesUsadas(), MAX = 24, lista = [], imagens = [];
  var pesos = ks.map(function(k){ return Math.max(1, fonteDur(k)); }), tot = pesos.reduce(function(a, b){ return a + b; }, 0);
  for (var i = 0; i < ks.length; i++){
    var k = ks[i], d = fonteDur(k), n = Math.max(2, Math.min(16, Math.round(MAX * pesos[i] / tot))), src = midiaDe(k).v.currentSrc || midiaDe(k).v.src;
    if (!src || !d) continue;
    var v = document.createElement('video'); v.muted = true; v.playsInline = true; v.preload = 'auto'; v.crossOrigin = 'anonymous'; v.src = src;
    var ok = await new Promise(function(r){ var t = setTimeout(function(){ r(false); }, 15000); v.onloadeddata = function(){ clearTimeout(t); r(true); }; v.onerror = function(){ clearTimeout(t); r(false); }; });
    if (!ok) continue;
    for (var j = 0; j < n && imagens.length < MAX; j++){
      if (!iaJob) return { lista:[], imagens:[] };
      var t = +(d * (j + .5) / n).toFixed(1);
      var foi = await new Promise(function(r){ var to = setTimeout(function(){ r(false); }, 6000); v.onseeked = function(){ clearTimeout(to); r(true); }; v.currentTime = t; });
      if (!foi || !v.videoWidth) continue;
      var e = Math.min(1, 448 / Math.max(v.videoWidth, v.videoHeight)), cv = document.createElement('canvas'); cv.width = Math.round(v.videoWidth * e); cv.height = Math.round(v.videoHeight * e);
      try { cv.getContext('2d').drawImage(v, 0, 0, cv.width, cv.height); imagens.push(cv.toDataURL('image/jpeg', .62)); lista.push([k || 'principal', t]); } catch (er) { break; }
    }
    v.removeAttribute('src'); v.load();
  }
  return { lista:lista, imagens:imagens };
}
function tipoIa(){ return TIPOS_IA.filter(function(t){ return t[0] === iaPed.tipo; })[0] || TIPOS_IA[0]; }
function painelIA(C){
  C.appendChild(el('p', 'nota-ajuda', 'A IA assiste a quadros dos vídeos que estão na linha do tempo e lê a fala, quando houver. Depois monta uma versão nova: escolhe os trechos, a ordem, o gancho e os textos. O projeto atual não muda: a versão nova abre como outro projeto para você revisar.'));
  var nv = botao('＋ Começar com outro vídeo', function(){ $('b-novo').click(); }); C.appendChild(nv);
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Tipo de vídeo'), chips(TIPOS_IA.map(function(t){ return [t[0], t[1]]; }), iaPed.tipo, function(v){ iaPed.tipo = v; guardaIa(); mostraAba(); })]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Formato'), chips(Object.keys(FORMATOS).map(function(k){ return [k, FORMATOS[k].n]; }), iaPed.formato, function(v){ iaPed.formato = v; guardaIa(); mostraAba(); })]));
  var dsel = sel([['auto', 'Automática (' + tipoIa()[2].join(' a ') + ' s)'], ['15', '15 s'], ['30', '30 s'], ['45', '45 s'], ['60', '60 s'], ['90', '90 s'], ['180', '3 min']], iaPed.dur);
  dsel.onchange = function(){ iaPed.dur = dsel.value; guardaIa(); };
  var obs = el('textarea'); obs.rows = 4; obs.placeholder = 'Ex.: começar pela pergunta da Débora, terminar chamando para o teste grátis, sem a parte do ombro.'; obs.value = iaPed.obs; obs.oninput = function(){ iaPed.obs = obs.value; guardaIa(); };
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Duração'), dsel]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Orientações para a edição'), obs]));
  C.appendChild(el('p', 'nota-ajuda', 'Vídeos usados: ' + chavesUsadas().map(function(k){ var n = (fonte(k).palavras || []).length; return nomeFonte(k) + ' (' + (n ? n + ' palavras' : 'sem fala: a IA usa só as imagens') + ')'; }).join(', ') + '.'));
  var b = el('button', 'btn pri', iaJob ? 'A IA está montando...' : 'Criar versão com IA · previsão ~' + etaIa() + ' s'); b.type = 'button'; b.disabled = !!iaJob || !IA_OK;
  b.onclick = rodarIA; C.appendChild(b);
  C.appendChild(el('p', 'nota-ajuda', 'Usa o Claude da Intus (a mesma chave da Central de atendimento). Até ' + (LIMITES.ia_dia || 40) + ' montagens por dia; hoje: ' + (LIMITES.ia_hoje || 0) + '.'));
  if (iaJob) C.appendChild(cartaoJob());
}
function cartaoJob(){
  var c = el('div', 'cartao'), bar = el('div', 'prog', [el('i')]), info = el('p', 'nota-ajuda', '');
  c.appendChild(el('b', null, (iaJob && iaJob.fase) || 'Montando a versão de visualização')); c.appendChild(bar); c.appendChild(info);
  c.appendChild(botao('Cancelar', function(){ iaJob = null; faixaIA(); mostraAba(); estado('Cancelado'); }));
  function atual(){ if (!iaJob || !document.body.contains(c)) return; var s = (Date.now() - iaJob.ini) / 1000;
    bar.firstChild.style.width = Math.min(96, s / iaJob.eta * 100) + '%';
    info.textContent = 'Previsão: ~' + iaJob.eta + ' s · passaram ' + Math.round(s) + ' s' + (s > iaJob.eta ? ' (está levando mais que o previsto)' : '') ;
    setTimeout(atual, 500); }
  atual(); return c;
}
function faixaIA(){
  var fx = $('faixa-ia'); fx.innerHTML = '';
  if (iaJob){ fx.hidden = false; fx.appendChild(el('span', null, 'IA montando o vídeo · previsão ~' + iaJob.eta + ' s')); var pr = el('div', 'prog', [el('i')]); pr.style.flex = '1'; pr.style.minWidth = '80px'; fx.appendChild(pr);
    (function tic(){ if (!iaJob) return; pr.firstChild.style.width = Math.min(96, (Date.now() - iaJob.ini) / 10 / iaJob.eta) + '%'; setTimeout(tic, 500); })(); return; }
  if (P && P.ia && P.ia.status === 'previa'){ fx.hidden = false;
    fx.appendChild(el('span', null, 'Versão de visualização feita pela IA' + (P.ia.explicacao ? ': ' + P.ia.explicacao : '') + '. Revise e aprove.'));
    fx.appendChild(botao('Aprovar e exportar', aprovar)); fx.appendChild(botao('Refazer', function(){ aba = 'ia'; abrePainel(); }));
    fx.appendChild(botao('×', function(){ P.ia.status = 'editando'; agendaSalvar(null, true); faixaIA(); }, 'Fechar aviso')); return; }
  fx.hidden = true;
}
function falasDe(key){ var ws = fonte(key).palavras || [], out = [], cur = null;
  ws.forEach(function(w){ var p = String(w.p).trim(); if (!p) return;
    if (!cur || w.i - cur[1] > .5 || w.f - cur[0] > 7){ if (cur) out.push(cur); cur = [+w.i.toFixed(2), +w.f.toFixed(2), p]; } else { cur[1] = +w.f.toFixed(2); cur[2] += ' ' + p; }
    if (/[.?!]$/.test(p)){ out.push(cur); cur = null; } });
  if (cur) out.push(cur); return out; }
async function rodarIA(){
  if (iaJob) return;
  var tipo = tipoIa(), F = FORMATOS[iaPed.formato] || FORMATOS.reel, alvoS = iaPed.dur === 'auto' ? tipo[2][0] + ' a ' + tipo[2][1] : iaPed.dur;
  var fontesIa = chavesUsadas().map(function(k){ return { id:k || 'principal', nome:nomeFonte(k), duracao:+fonteDur(k).toFixed(1), falas:falasDe(k) }; });
  iaJob = { ini:Date.now(), eta:etaIa(), ctrl:new AbortController(), chars:0, fase:'Olhando o vídeo' }; mostraAba(); faixaIA();
  var Q = await quadrosParaIA().catch(function(){ return { lista:[], imagens:[] }; }); if (!iaJob) return;
  var prompt = ['Você edita vídeos curtos da Intus Fit, consultoria online de treino e nutrição do Luiz Nunes (é consultoria online, não academia).',
    'Monte um vídeo do tipo "' + tipo[1] + '", formato ' + F.n + ', com ' + alvoS + ' segundos no total.',
    Q.lista.length ? 'Junto com este pedido vão ' + Q.lista.length + ' imagens (quadros dos vídeos), nesta ordem, no formato [id do vídeo, segundo]: ' + JSON.stringify(Q.lista) + '. Use as imagens para entender o que acontece em cada momento, principalmente nos vídeos sem fala (exercício, demonstração, bastidores): escolha os melhores momentos e corte as partes paradas ou repetidas.' : '',
    'Regras: quando houver fala, escolha trechos de fala completos, com começo, meio e fim claros; corte em pausas, nunca no meio de palavra; pode mudar a ordem se a história ficar melhor; os 3 primeiros segundos precisam prender; não prometa resultado, prazo ou número; não use travessão nem a construção "não é X, é Y"; português do Brasil.',
    'Vídeos sem fala (vinheta, introdução) podem entrar inteiros no começo ou no fim, se fizer sentido.',
    iaPed.obs ? 'Orientações do Luiz: ' + iaPed.obs : '',
    'Vídeos disponíveis, com as falas no formato [início_s, fim_s, "texto"] no tempo da gravação de cada um:',
    JSON.stringify(fontesIa),
    'Responda só com JSON: {"nome":"título curto do projeto","gancho":"frase de até 8 palavras para a tela nos primeiros segundos","destaque":["1 ou 2 palavras do gancho"],"trechos":[{"fonte":"id do vídeo","ini":segundos,"fim":segundos}],"textos":[{"texto":"texto curto na tela","ini":segundos_no_video_final,"fim":segundos_no_video_final,"posicao":"topo|meio|baixo"}],"explicacao":"uma frase dizendo a lógica da montagem"}. Os trechos ficam na ordem em que vão aparecer.'
  ].filter(Boolean).join('\n\n');
  iaJob.fase = 'Montando a edição'; mostraAba(); faixaIA();
  try {
    var rr = await api('ia', { pedido:prompt, imagens:Q.imagens }); if (!iaJob) return; LIMITES.ia_hoje = (LIMITES.ia_hoje || 0) + 1; var r = rr.resposta;
    var seg = Math.round((Date.now() - iaJob.ini) / 1000); iaJob = null; aplicarIA(r, tipo, seg);
  } catch (e) {
    iaJob = null; faixaIA(); mostraAba();
    if (e && e.code === 'sem_acesso') return;
    dialogo('Edição automática não concluída', (e && e.message) || 'A IA não conseguiu montar agora. Tente de novo.', [['Ok', fecha]]);
  }
}
function encaixa(key, ini, fim){
  var ws = fonte(key).palavras || [], d = fonteDur(key); ini = cl(+ini || 0, 0, d); fim = cl(+fim || 0, 0, d); if (!ws.length) return [ini, fim];
  var a = ws.filter(function(w){ return w.f > ini + .05; })[0], z = ws.filter(function(w){ return w.i < fim - .05; }).pop();
  if (a) ini = Math.max(0, Math.min(ini, a.i) - .08); if (z) fim = Math.min(d, Math.max(fim, z.f) + .16); return [+ini.toFixed(2), +fim.toFixed(2)];
}
function aplicarIA(r, tipo, seg){
  if (!r || !Array.isArray(r.trechos)){ dialogo('Edição automática não concluída', 'A IA não devolveu trechos. Tente de novo.', [['Ok', fecha]]); return; }
  var base = P, keys = chavesUsadas(), novos = [];
  r.trechos.forEach(function(t){ var k = t.fonte === 'principal' ? '' : String(t.fonte || ''); if (keys.indexOf(k) < 0) return;
    var e = encaixa(k, t.ini, t.fim); if (e[1] - e[0] < .4) return; var n = { ini:e[0], fim:e[1] };
    if (k){ n.de = k; var orig = base.trechos.filter(function(x){ return (x.de || '') === k; })[0]; if (orig && orig.cheia){ n.cheia = true; n.limpo = !!orig.limpo; } } novos.push(n); });
  if (!novos.length){ dialogo('Edição automática não concluída', 'Os trechos que a IA escolheu não existem nos vídeos. Tente de novo.', [['Ok', fecha]]); return; }
  var N = clone(base); N.id = novoId('ia-'); N.nome = 'IA · ' + tipo[1] + ' · ' + (r.nome || r.gancho || base.nome || ''); N.trechos = novos;
  if (r.gancho) N.gancho = String(r.gancho); if (Array.isArray(r.destaque)) N.destaque = r.destaque.map(String);
  N.render = ''; N.textos = []; N.imagens = (base.imagens || []).filter(function(x){ return x.sempre; }); N.sombras = (base.sombras || []).filter(function(x){ return x.sempre; });
  N.ia = { tipo:tipo[0], formato:iaPed.formato, obs:iaPed.obs, explicacao:String(r.explicacao || '').slice(0, 240), de:base.id, criado_em:new Date().toISOString(), segundos:seg, status:'previa' };
  var fmtAnt = base.formato || 'reel'; N.formato = fmtAnt; projetos[N.id] = N; abrir(N.id);
  if (iaPed.formato !== fmtAnt) mudaFormato(iaPed.formato);
  var posY = { topo:.2, meio:.45, baixo:.7 }, D = durFala();
  (Array.isArray(r.textos) ? r.textos : []).slice(0, 12).forEach(function(x){ if (!x || !x.texto) return; var a = cl(+x.ini || 0, 0, D), z = cl(+x.fim || a + 2.5, a + .5, D + T_FIM);
    P.textos.push({ texto:String(x.texto).slice(0, 120), ini:+a.toFixed(2), fim:+z.toFixed(2), x:W / 2, y:Math.round(H * (posY[x.posicao] || .45)), tam:62, cor:'#ffffff', fundo:true, borda:false }); });
  atualizaTudo(); histPush(); salvar(); faixaIA(); aba = 'midia'; mostraAba(); estado('Versão da IA pronta em ' + seg + ' s', 'ok');
}
function aprovar(){
  if (!P) return; P.ia = P.ia || {}; P.ia.status = 'aprovado'; salvar(); faixaIA();
  dialogo('Aprovado', 'Agora é exportar: o vídeo sai em alta, feito a partir dos vídeos originais, e pode ir direto para o Drive da Intus.', [['Depois', fecha], ['Exportar agora', function(){ fecha(); abreExportar(); }]]);
}
// ---------- propriedades (direita) ----------
function seleciona(){ desenhaTimeline(); inspetor(); pintaGuia(); if (estreito() && selEl){ abreInsp(); } }
function inspetor(){
  var C = $('insp-corpo'); if (!C || !P) return; var y = C.scrollTop; C.innerHTML = '';
  var t = selEl ? selEl.split(':') : null, tit = 'Corte selecionado';
  if (t && t[0] === 'texto' && P.textos[+t[1]]){ tit = 'Texto'; C.appendChild(cardTexto(+t[1])); }
  else if (t && t[0] === 'sombra' && P.sombras[+t[1]]){ tit = 'Sombra'; C.appendChild(cardSombra(+t[1])); }
  else if (t && t[0] === 'imagem' && P.imagens[+t[1]]){ tit = 'Imagem'; C.appendChild(cardImagem(+t[1])); }
  else if (t && t[0] === 'camada' && P.camadas[+t[1]]){ tit = 'Sobreposição de vídeo'; C.appendChild(cardCamada(+t[1])); }
  else if (t && CARDS[t[0]] && t[0] !== 'janela'){ tit = NOMES[t[0]] || 'Elemento'; C.appendChild(CARDS[t[0]]()); }
  else inspCorte(C);
  $('insp-tit').textContent = tit; C.scrollTop = y;
}
function estreito(){ return innerWidth <= 1100; }
function abreInsp(){ $('app').classList.add('insp-aberto'); }
function fechaInsp(){ $('app').classList.remove('insp-aberto'); }
$('b-fecha-insp').onclick = fechaInsp;

// ---------- histórico e desfazer ----------
var hist = [], hi = -1, histTimer = null, histRot = 'Edição', restaurando = false;
function estadoTxt(){ return JSON.stringify(P, function(k, v){ return k === 'atualizado_em' || k === 'render' ? undefined : v; }); }
function histCarrega(id){ try { var h = JSON.parse(localStorage.getItem('intuscut-hist-' + id) || 'null'); if (h && h.lista) return h; } catch (e) {} return null; }
function histGuarda(){ if (!P) return;
  for (var tent = 0; tent < 5; tent++){ try { localStorage.setItem('intuscut-hist-' + P.id, JSON.stringify({ lista:hist, i:hi })); return; }
    catch (e) { var corte = Math.max(1, Math.floor(hist.length / 2)); hist = hist.slice(corte); hi = Math.max(0, hi - corte); } } }
function histAbrir(){ var h = histCarrega(P.id), s = estadoTxt(); hist = h ? h.lista : [];
  if (!hist.length || hist[hist.length - 1].s !== s){ hist.push({ r:'Projeto aberto', q:Date.now(), s:s }); if (hist.length > 60) hist = hist.slice(-60); histGuarda(); }
  hi = hist.length - 1; marcaDesfazer(); }
function histMarca(rot){ if (restaurando) return; histRot = rot || TITULOS[aba] || 'Edição'; clearTimeout(histTimer); histTimer = setTimeout(histPush, 450); }
function histPush(){ histTimer = null; if (!P) return; var s = estadoTxt(); if (hi >= 0 && hist[hi].s === s) return;
  hist = hist.slice(0, hi + 1);
  if (hi > 0 && hist[hi].r === histRot && Date.now() - hist[hi].q < 2500) hist[hi] = { r:histRot, q:Date.now(), s:s }; else hist.push({ r:histRot, q:Date.now(), s:s });
  if (hist.length > 60) hist = hist.slice(-60); hi = hist.length - 1; histGuarda(); marcaDesfazer(); if (aba === 'historico') mostraAba(); }
function irHist(i){ if (histTimer){ clearTimeout(histTimer); histPush(); } if (i < 0 || i >= hist.length || i === hi) return;
  hi = i; var o = JSON.parse(hist[i].s); o.id = P.id; o.render = P.render || ''; restaurando = true;
  P = o; projetos[o.id] = o; P.textos = P.textos || []; P.imagens = P.imagens || []; P.sombras = P.sombras || []; P.camadas = P.camadas || []; aplicaFormato(); selEl = null; selTrecho = cl(selTrecho, 0, Math.max(0, trechos().length - 1)); recalc(); desenhaTimeline(); irPara(Math.min(T, dur())); mostraAba(); nomesSelect(); faixaIA();
  restaurando = false; histGuarda(); marcaDesfazer(); agendaSalvar(null, true); }
function desfazer(){ if (histTimer){ clearTimeout(histTimer); histPush(); } irHist(hi - 1); }
function refazer(){ irHist(hi + 1); }
function marcaDesfazer(){ $('b-desf').disabled = hi <= 0; $('b-ref').disabled = hi >= hist.length - 1; }
$('b-desf').onclick = desfazer; $('b-ref').onclick = refazer;
function painelHistorico(C){
  var l = el('div', 'hist');
  for (var i = hist.length - 1; i >= 0; i--) (function(i){
    var h = hist[i], d = new Date(h.q), q = d.toLocaleDateString('pt-BR', { day:'2-digit', month:'2-digit' }) + ' ' + d.toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit', second:'2-digit' });
    var b = el('button', i === hi ? 'atual' : (i > hi ? 'futuro' : ''), [el('span', 't', '#' + (i + 1)), el('span', null, h.r), el('span', 't', i === hi ? 'agora' : q)]);
    b.type = 'button'; b.onclick = function(){ irHist(i); }; l.appendChild(b);
  })(i);
  C.appendChild(el('p', 'nota-ajuda', 'Clique num passo para voltar o projeto àquele momento. Ctrl+Z desfaz, Ctrl+Shift+Z refaz. Fica guardado neste navegador (60 passos por projeto).'));
  C.appendChild(el('div', 'linha', [botao('Desfazer', desfazer), botao('Refazer', refazer)])); C.appendChild(l);
}

// ---------- navegação dos painéis ----------
var PAINEIS = { midia:painelMidia, biblioteca:painelBiblioteca, ia:painelIA, textos:painelTextos, legenda:painelLegenda, efeitos:painelEfeitos, layout:painelLayout, audio:painelAudio, historico:painelHistorico };
function marcaAba(){ var fechado = $('app').classList.contains('sem-painel'); document.querySelectorAll('.rail button').forEach(function(b){ b.setAttribute('aria-selected', String(b.dataset.aba === 'ajustes' ? $('app').classList.contains('insp-aberto') : (b.dataset.aba === aba && !fechado))); }); }
function mostraAba(){
  marcaAba(); if (!P) return; var C = $('conteudo'), y = C.scrollTop;
  if (PAINEIS[aba]){ C.innerHTML = ''; $('painel-tit').textContent = TITULOS[aba]; PAINEIS[aba](C); C.scrollTop = y; }
  inspetor();
}
function abrePainel(){ $('app').classList.remove('sem-painel'); fechaInsp(); mostraAba(); }
document.querySelectorAll('.rail button').forEach(function(b){ b.onclick = function(){
  if (b.dataset.aba === 'ajustes'){ $('app').classList.add('sem-painel'); if ($('app').classList.contains('insp-aberto')) fechaInsp(); else { abreInsp(); inspetor(); } marcaAba(); return; }
  if (aba === b.dataset.aba && !$('app').classList.contains('sem-painel')){ $('app').classList.add('sem-painel'); marcaAba(); requestAnimationFrame(desenhaTimeline); return; }
  aba = b.dataset.aba; abrePainel(); requestAnimationFrame(desenhaTimeline); }; });
$('b-fecha-painel').onclick = function(){ $('app').classList.add('sem-painel'); marcaAba(); requestAnimationFrame(desenhaTimeline); };

// ---------- projetos: abrir, salvar ----------
function nomesSelect(){ var s = $('sel-proj'), atual = P && P.id; s.innerHTML = '';
  Object.keys(projetos).sort(function(a, b){ return (projetos[b].atualizado_em || '').localeCompare(projetos[a].atualizado_em || '') || a.localeCompare(b); })
    .forEach(function(id){ var o = document.createElement('option'); o.value = id; o.textContent = projetos[id].nome || id; s.appendChild(o); });
  if (atual) s.value = atual; }
async function abrir(id){
  if (histTimer){ clearTimeout(histTimer); histPush(); }
  var X = await carregarProjeto(id); if (!X) return;
  pausar(); P = X; $('vazio').hidden = true; T = 0; selTrecho = 0; lacunaSel = null; trechoAtual = -1; selEl = null;
  P.midia = P.midia || {}; P.textos = P.textos || []; P.imagens = P.imagens || []; P.sombras = P.sombras || []; P.camadas = P.camadas || []; aplicaFormato();
  var M = midiaDe('');
  if (M.v.readyState >= 1 && !P.midia.duracao) P.midia.duracao = M.v.duration;
  else M.v.addEventListener('loadedmetadata', function f(){ M.v.removeEventListener('loadedmetadata', f); if (P && !P.midia.duracao && isFinite(M.v.duration)) P.midia.duracao = M.v.duration; desenhaTimeline(); irPara(T); });
  recalc(); nomesSelect(); histAbrir(); desenhaTimeline(); mostraAba(); faixaIA(); irPara(0); garanteFontes(); try { localStorage.setItem('intuscut-ultimo', id); } catch (e) {}
}
$('sel-proj').onchange = function(){ abrir($('sel-proj').value); };
(function(){ var s = $('sel-formato'); Object.keys(FORMATOS).forEach(function(k){ var o = document.createElement('option'); o.value = k; o.textContent = FORMATOS[k].n; s.appendChild(o); });
  s.onchange = function(){ mudaFormato(s.value); }; })();
function agendaSalvar(rot, semHist){ if (!semHist) histMarca(rot); estado('Alterações não salvas'); clearTimeout(salvarTimer); salvarTimer = setTimeout(salvar, 1200); }
var salvando = null, salvarDeNovo = false;
function salvar(){
  if (!P) return; if (salvando){ salvarDeNovo = true; return; }
  var meu = P; meu.atualizado_em = new Date().toISOString(); estado('Salvando...');
  salvando = api('salvar', { projeto:meu }).then(function(){ if (meu === P) estado('Salvo', 'ok'); })
    .catch(function(e){ if (e.code !== 'sem_acesso') estado('Não salvou: ' + (e.message || 'sem conexão') + '. Tentando de novo...', 'erro'); salvarDeNovo = true; })
    .then(function(){ salvando = null; if (salvarDeNovo){ salvarDeNovo = false; clearTimeout(salvarTimer); salvarTimer = setTimeout(salvar, 2500); } });
}
$('b-dup').onclick = function(){ if (!P) return; var c = clone(P); c.id = P.id.replace(/-v\w+$/, '') + '-v' + Date.now().toString(36).slice(-4); c.nome = (P.nome || P.id) + ' (cópia)'; c.render = ''; delete c.ia;
  projetos[c.id] = c; abrir(c.id); salvar(); };
$('b-novo').onclick = function(){
  escolherArquivo('video/*', function(r, nome, info){ var id = novoId('video-'), d = info.dur || 5, vert = info.h > info.w; guardaNaBib({ tipo:'video', nome:nome, url:r.url, dur:info.dur, w:info.w, h:info.h });
    projetos[id] = { id:id, nome:nome, formato:vert ? 'reel' : 'youtube', gancho:'Escreva o gancho aqui', destaque:[], trechos:[{ ini:0, fim:+d.toFixed(2), cheia:vert }], palavras:[],
      planos:[{ ini:0, fim:d + 1, cx:960, larg:ORIG_W, y:0, tarja:true }], notas:[], textos:[], sombras:[], imagens:[], audio:{ voz:1, musica:0 }, estilo:{ cores:['amarelo', 'verde'], tamanho:84, maiusculas:true, palavras_por_grupo:3 },
      camadas:[], final:{ ativo:true, chamada:'SIGA PARA MAIS DICAS', slogan:'Te levamos ao próximo nível. Do iniciante ao atleta.' }, midia:{ video:r.url, voz:r.url, musica:'', duracao:d, alt:info.w ? ORIG_W * info.h / info.w : 1080 } };
    Promise.resolve(abrir(id)).then(function(){ salvar(); oferecerIA(nome); }); });
};
function oferecerIA(nome){
  dialogo('Vídeo pronto para editar', '"' + nome + '" virou um projeto novo. Você pode editar à mão ou pedir à IA uma primeira versão (ela assiste a quadros do vídeo e, se houver, lê a fala).',
    [['Editar eu mesmo', fecha], ['Pedir edição à IA', function(){ fecha(); aba = 'ia'; abrePainel(); }]]);
}

// ---------- exportar, Drive e versões em alta ----------
function dialogo(tit, txt, botoes, extra){ var c = $('caixa'); c.style.maxWidth = ''; c.innerHTML = ''; c.appendChild(el('h2', null, tit)); if (txt) c.appendChild(el('p', 'nota-ajuda', txt)); if (extra) c.appendChild(extra);
  var l = el('div', 'linha'); botoes.forEach(function(b, i){ var x = botao(b[0], b[1]); if (i === botoes.length - 1 && botoes.length > 1) x.className = 'btn pri'; l.appendChild(x); }); c.appendChild(l); $('modal').hidden = false; }
function fecha(){ $('modal').hidden = true; }
function esperaSeek(V, t){ return new Promise(function(ok){ if (Math.abs(V.currentTime - t) < .001 && V.readyState >= 2){ ok(); return; } var f = function(){ V.removeEventListener('seeked', f); ok(); }; V.addEventListener('seeked', f); V.currentTime = t; }); }
$('b-capa').onclick = function(){ if (!P) return; pausar(); var cv = document.createElement('canvas'); cv.width = W; cv.height = H; desenha(cv.getContext('2d'), T, null);
  cv.toBlob(function(b){ baixarBlob(b, (P.id || 'video') + '-' + T.toFixed(1) + 's.png'); }, 'image/png'); };
$('b-exp').onclick = function(){ abreExportar(); };
function abreExportar(){
  if (!P) return; pausar();
  var temWC = !!(window.VideoEncoder && window.AudioEncoder && window.Mp4Muxer);
  var q = sel([['alta', 'Alta qualidade'], ['leve', 'Leve (para mandar por WhatsApp)']], 'alta'), box = el('div', 'grupo');
  box.appendChild(el('div', 'linha', [campo('Qualidade', q), el('span', 't', formato().n + ' · ' + W + 'x' + H + ' · ' + dur().toFixed(1) + ' s')]));
  box.appendChild(el('p', 'nota-ajuda', temWC ? 'Feito a partir dos vídeos originais. Um vídeo de 1 minuto leva de 1 a 3 minutos.' : 'Este navegador grava o vídeo enquanto ele toca: leva o tempo do vídeo, com som. Deixe a tela ligada.'));
  dialogo('Exportar', '', [['Fechar', fecha], ['Exportar', function(){ exportar(q.value); }]], box);
}
function baixarUrl(url, nome){ arquivoInteiro(url).then(function(ab){ if (ab) baixarBlob(new Blob([ab], { type:'video/mp4' }), nome); }).catch(function(){ estado('Não consegui baixar', 'erro'); }); }
async function exportar(qual){
  await garanteFontes();
  if (!window.VideoEncoder || !window.AudioEncoder || !window.Mp4Muxer){ exportarTempoReal(qual); return; }
  pausar(); var barra = el('div', 'prog', [el('i')]), info = el('p', 'nota-ajuda', 'Preparando...'), cancelado = false, box = el('div', 'grupo', [barra, info]);
  dialogo('Exportando', 'Deixe esta aba aberta. Um vídeo de 1 minuto leva de 1 a 3 minutos.', [['Cancelar', function(){ cancelado = true; fecha(); }]], box);
  try {
    var SR = 48000, d = dur(), DF = durFala(), N = Math.round(d * FPS);
    var vbr = qual === 'leve' ? 2500000 : (W * H > 2e6 ? 10000000 : 8000000);
    var vcfg = { codec:'avc1.640028', width:W, height:H, bitrate:vbr, framerate:FPS };
    if (!(await VideoEncoder.isConfigSupported(vcfg)).supported) vcfg.codec = 'avc1.4d0028';
    var acfg = { codec:'mp4a.40.2', sampleRate:SR, numberOfChannels:2, bitrate:qual === 'leve' ? 96000 : 160000 };
    var temAac = (await AudioEncoder.isConfigSupported(acfg)).supported;
    var muxer = new Mp4Muxer.Muxer({ target:new Mp4Muxer.ArrayBufferTarget(), video:{ codec:'avc', width:W, height:H }, audio:temAac ? { codec:'aac', sampleRate:SR, numberOfChannels:2 } : undefined, fastStart:'in-memory' });
    var ve = new VideoEncoder({ output:function(ch, meta){ muxer.addVideoChunk(ch, meta); }, error:function(e){ throw e; } }); ve.configure(vcfg);
    var cv = document.createElement('canvas'); cv.width = W; cv.height = H; var c2 = cv.getContext('2d');
    for (var k = 0; k < N; k++){
      if (cancelado) return;
      var t = k / FPS; if (t < DF){ var f = fonteEm(t); if (f) await esperaSeek(midiaDe(f.de).v, f.ts); }
      for (var ci = 0; ci < P.camadas.length; ci++){ var kc = P.camadas[ci]; if (t >= kc.ini && t <= kc.fim) await esperaSeek(camadaMid(kc.de).v, srcCamada(kc, t)); }
      desenha(c2, t, null);
      var vf = new VideoFrame(cv, { timestamp:Math.round(k * 1e6 / FPS), duration:Math.round(1e6 / FPS) }); ve.encode(vf, { keyFrame:k % 60 === 0 }); vf.close();
      while (ve.encodeQueueSize > 8) await new Promise(function(r){ setTimeout(r, 5); });
      if (k % 10 === 0){ barra.firstChild.style.width = (k / N * 85) + '%'; info.textContent = 'Imagem: quadro ' + k + ' de ' + N; }
    }
    await ve.flush();
    if (temAac){
      info.textContent = 'Áudio...'; var ac = new AudioContext({ sampleRate:SR }), cache = {};
      var buf = async function(url){ if (!url) return null; if (!(url in cache)){ try { info.textContent = 'Áudio: lendo os arquivos...'; cache[url] = await ac.decodeAudioData(await arquivoInteiro(url)); } catch (e) { cache[url] = null; } } return cache[url]; };
      var au = P.audio || {}, total = Math.round(d * SR), L = new Float32Array(total), R = new Float32Array(total), gv = au.voz == null ? 1 : au.voz, gm = au.musica || 0, pos = 0, rampa = Math.round(.015 * SR), M = mapa();
      for (var q2 = 0; q2 < M.length; q2++){ var m = M[q2], md = fonte(m.de).midia || {}, bv = await buf(md.voz), bm = md.musica ? await buf(md.musica) : null;
        var a = Math.round(m.ini * SR), n = Math.round((m.fim - m.ini) * SR);
        for (var i = 0; i < n && pos + i < total; i++){ var e = Math.min(1, i / rampa, (n - i) / rampa);
          for (var z = 0; z < 2; z++){ var b = z ? bm : bv, gg = z ? gm : gv; if (!b || !gg) continue; var j = a + i; if (j >= b.length) continue;
            var l = b.getChannelData(0)[j], r = b.numberOfChannels > 1 ? b.getChannelData(1)[j] : l; L[pos + i] += l * gg * e; R[pos + i] += r * gg * e; } }
        pos += n; }
      for (var cq = 0; cq < P.camadas.length; cq++){ var kq = P.camadas[cq], mq = fonte(kq.de).midia || {}, bq = await buf(mq.voz || mq.video), vq = kq.volume == null ? .8 : kq.volume; if (!bq || !vq) continue;
        var a0 = Math.round(kq.ini * SR), nq = Math.round((Math.min(kq.fim, d) - kq.ini) * SR), s0 = Math.round(kq.src * SR);
        for (var iq = 0; iq < nq; iq++){ var jq = s0 + iq, oq = a0 + iq; if (jq >= bq.length || oq >= total) break; var eq = Math.min(1, iq / rampa, (nq - iq) / rampa), lq = bq.getChannelData(0)[jq], rq = bq.numberOfChannels > 1 ? bq.getChannelData(1)[jq] : lq; L[oq] += lq * vq * eq; R[oq] += rq * vq * eq; } }
      ac.close();
      for (var i2 = 0; i2 < total; i2++){ L[i2] = cl(L[i2], -1, 1); R[i2] = cl(R[i2], -1, 1); }
      var ae = new AudioEncoder({ output:function(ch, meta){ muxer.addAudioChunk(ch, meta); }, error:function(e){ throw e; } }); ae.configure(acfg);
      for (var s = 0; s < total; s += 1024){ var nn = Math.min(1024, total - s), dados = new Float32Array(nn * 2); dados.set(L.subarray(s, s + nn), 0); dados.set(R.subarray(s, s + nn), nn);
        var ad = new AudioData({ format:'f32-planar', sampleRate:SR, numberOfFrames:nn, numberOfChannels:2, timestamp:Math.round(s * 1e6 / SR), data:dados }); ae.encode(ad); ad.close(); }
      await ae.flush();
    }
    muxer.finalize(); barra.firstChild.style.width = '100%';
    var blob = new Blob([muxer.target.buffer], { type:'video/mp4' }), nome = String(P.nome || P.id || 'video').replace(/[\\/:*?"<>|]+/g, '').slice(0, 60).trim() + '.mp4', mb = (blob.size / 1048576).toFixed(1);
    var vid = el('video'); vid.src = URL.createObjectURL(blob); vid.controls = true; vid.style.width = '100%'; vid.style.maxHeight = '45vh';
    prontoExportado(blob, nome, mb, temAac ? '' : 'Este navegador não grava áudio AAC: o vídeo saiu sem som.', d);
  } catch (e) { info.textContent = 'A exportação falhou: ' + (e && (e.message || e.code) || e) + '. Tente no Chrome do computador, ou peça a versão em alta ao Claude.'; }
}
async function salvarDrive(blob, nome, d){
  var prog = janelaProgresso('Salvando no Drive da Intus');
  try {
    var f = new File([blob], nome, { type:blob.type || 'video/mp4' }), up = await enviarArquivo(f, 'exportado-' + nome.replace(/\.[^.]+$/, ''), { dur:d, w:W, h:H }, function(x){ prog(x, 'Enviando: ' + Math.round(x * 100) + '%'); });
    BLOBS[up.url] = Promise.resolve(URL.createObjectURL(blob));
    await guardaNaBib({ tipo:'video', nome:'Exportado · ' + nome.replace(/\.[^.]+$/, ''), url:up.url, dur:d, w:W, h:H, exportado:true });
    if (P){ P.exportado = { url:up.url, em:new Date().toISOString() }; salvar(); }
    if (P && window.cutAutoPronto) cutAutoPronto(P.id);
    dialogo('Salvo no Drive da Intus', 'O vídeo está guardado no Google Drive da Intus e na Biblioteca, em Vídeos (com a etiqueta "exportado"), para baixar de qualquer aparelho.', [['Ok', fecha]]);
  } catch (e){ if (e && e.code === 'sem_acesso') return; dialogo('Não salvou no Drive', (e && e.message) || 'Tente de novo.', [['Ok', fecha]]); }
}
function prontoExportado(blob, nome, mb, aviso, d){
  var vid = el('video'); vid.src = URL.createObjectURL(blob); vid.controls = true; vid.playsInline = true; vid.style.width = '100%'; vid.style.maxHeight = '45vh';
  dialogo('Vídeo pronto (' + mb + ' MB)', aviso, [['Fechar', fecha], ['Salvar no Drive da Intus', function(){ salvarDrive(blob, nome, d); }], ['Baixar', function(){ baixarBlob(blob, nome); }]], vid);
}
async function exportarTempoReal(qual){
  if (!window.MediaRecorder){ dialogo('Exportação indisponível neste navegador', 'Use o Chrome, o Edge ou o Safari atualizados.', [['Ok', fecha]]); return; }
  var g = grafoAudio(); await g.ac.resume().catch(function(){}); ligaAudioFundo();
  var cv = document.createElement('canvas'); cv.width = W; cv.height = H; var c2 = cv.getContext('2d'), tipo = tipoGravacao(), partes = [], parar = false;
  var rec = new MediaRecorder(new MediaStream([cv.captureStream(30).getVideoTracks()[0]].concat(g.dest.stream.getAudioTracks())), { mimeType:tipo || undefined, videoBitsPerSecond:qual === 'leve' ? 2500000 : 7000000, audioBitsPerSecond:128000 });
  rec.ondataavailable = function(e){ if (e.data && e.data.size) partes.push(e.data); };
  var barra = el('div', 'prog', [el('i')]), info = el('p', 'nota-ajuda', 'Gravando enquanto toca...');
  dialogo('Exportando', 'Não troque de aba nem apague a tela.', [['Cancelar', function(){ parar = true; rec.stop(); pausar(); fecha(); }]], el('div', 'grupo', [barra, info]));
  irPara(0); rec.onstop = function(){ if (parar) return; var tp = (tipo || 'video/webm').split(';')[0], b = new Blob(partes, { type:tp }); prontoExportado(b, String(P.nome || 'video').replace(/[\\/:*?"<>|]+/g, '').slice(0, 60) + (tp.indexOf('mp4') >= 0 ? '.mp4' : '.webm'), (b.size / 1048576).toFixed(1), '', dur()); };
  rec.start(1000); tocar();
  (function laco(){ if (parar) return; desenha(c2, T, null); barra.firstChild.style.width = Math.min(100, T / dur() * 100) + '%'; info.textContent = 'Gravando: ' + fmt(T) + ' de ' + fmt(dur());
    if (!tocando || T >= dur() - .05){ setTimeout(function(){ if (rec.state !== 'inactive') rec.stop(); }, 300); return; } requestAnimationFrame(laco); })();
}

// ---------- servidor da Intus (login do painel, projetos, biblioteca, envios ao Drive, IA) ----------
var API = '/app/api/intuscut.php', TOKEN = '', EU = null, IA_OK = true, LIMITES = {};
try { TOKEN = localStorage.getItem('mx-token') || ''; } catch (e) {}
function espera(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }
async function api(acao, corpo){
  var r = await fetch(API + '?action=' + acao, { method:corpo ? 'POST' : 'GET', headers:Object.assign({ Authorization:'Bearer ' + TOKEN }, corpo ? { 'Content-Type':'application/json' } : {}), body:corpo ? JSON.stringify(corpo) : undefined, cache:'no-store' });
  var j = null; try { j = await r.json(); } catch (e) {}
  if (r.status === 401 || r.status === 403){ semAcesso(r.status, j && j.erro); throw { code:'sem_acesso', message:(j && j.erro) || '' }; }
  if (!r.ok || !j || j.ok === false) throw { code:'erro', status:r.status, message:(j && (j.erro || j.error)) || ('falha ' + r.status) };
  return j;
}
function semAcesso(st, msg){
  var v = $('vazio'); v.hidden = false; estado(st === 401 ? 'Sem login' : 'Sem acesso', 'erro');
  v.innerHTML = '';
  v.appendChild(el('div', 'grupo', [el('b', null, st === 401 ? 'Entre no painel da Intus para usar o Intus Cut.' : 'Seu usuário não tem acesso ao Intus Cut.'),
    el('p', 'nota-ajuda', msg || (st === 401 ? 'O Intus Cut usa o mesmo login do painel.' : 'Peça ao administrador a permissão "Usar o Intus Cut", em Usuários.')),
    (function(){ var a = el('a', 'btn pri', st === 401 ? 'Entrar no painel' : 'Voltar ao painel'); a.href = st === 401 ? '/app/painel/login.html' : '/app/painel/index.html'; return a; })()]));
}
// Vídeo que chega do Drive vem em pedaços de até 4 MB; para editar sem travar, o vídeo inteiro é baixado uma vez (até 450 MB).
var BLOBS = {}, CARGA = {}, PRECARGA_MAX = 450 * 1048576;
async function baixarFaixas(url, cap){
  var r = await fetch(url, { headers:{ Range:'bytes=0-' } });
  if (!r.ok && r.status !== 206) throw new Error('falha ' + r.status);
  var cr = r.headers.get('Content-Range'), total = cr ? +cr.split('/')[1] : 0, tipo = r.headers.get('Content-Type') || 'video/mp4', primeiro = await r.arrayBuffer();
  if (!total) return new Blob([primeiro], { type:tipo });
  if (cap && total > cap) return null;
  var faixa = 4 * 1048576, filas = [], res = {}, k = 0; CARGA[url] = { feito:primeiro.byteLength, total:total }; mostraCarga();
  for (var p = primeiro.byteLength; p < total; p += faixa) filas.push(p);
  async function trabalha(){ while (k < filas.length){ var ini = filas[k++], fim = Math.min(total - 1, ini + faixa - 1), got = [], at = ini, tent = 0;
      while (at <= fim){ try { var rr = await fetch(url, { headers:{ Range:'bytes=' + at + '-' + fim } }); if (!rr.ok && rr.status !== 206) throw 0; var b = await rr.arrayBuffer(); if (!b.byteLength) throw 0; got.push(b); at += b.byteLength; tent = 0; }
        catch (e){ if (++tent > 4) throw new Error('rede'); await espera(1500 * tent); } }
      res[ini] = got; CARGA[url].feito += fim - ini + 1; mostraCarga(); } }
  await Promise.all([trabalha(), trabalha(), trabalha()]);
  var partes = [primeiro]; filas.forEach(function(ini){ partes.push.apply(partes, res[ini]); });
  delete CARGA[url]; mostraCarga();
  return new Blob(partes, { type:tipo });
}
function precarregar(url){
  if (!url || !/midia\.php/.test(url)) return Promise.resolve(null);
  if (!BLOBS[url]) BLOBS[url] = baixarFaixas(url, PRECARGA_MAX).then(function(b){ return b ? URL.createObjectURL(b) : null; }).catch(function(){ delete CARGA[url]; mostraCarga(); return null; });
  return BLOBS[url];
}
function trocaSrc(elm, nova){ if (!elm || !nova) return; var t = elm.currentTime || 0, tocava = !elm.paused; elm.src = nova;
  elm.addEventListener('loadedmetadata', function f(){ elm.removeEventListener('loadedmetadata', f); try { elm.currentTime = t; } catch (e) {} if (tocava) elm.play().catch(function(){}); }); }
function mostraCarga(){ var p = $('carga'); if (!p) return; var ks = Object.keys(CARGA); if (!ks.length){ p.hidden = true; return; }
  var f = 0, t = 0; ks.forEach(function(k){ f += CARGA[k].feito; t += CARGA[k].total; }); p.hidden = false; p.textContent = 'Carregando vídeo para editar sem travar: ' + Math.round(f / t * 100) + '%'; }
async function arquivoInteiro(url){ if (!url) return null; var b = BLOBS[url] ? await BLOBS[url] : null; if (b) return await (await fetch(b)).arrayBuffer(); var bl = await baixarFaixas(url, 0); return bl ? await bl.arrayBuffer() : null; }
async function baixarBlob(blob, nome){ var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = nome; document.body.appendChild(a); a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 4000); }
// Envio de vídeo ou áudio ao Google Drive da Intus, em pedaços; continua de onde parou se a internet cair.
async function enviarArquivo(file, nome, info, aoProgresso){
  var r = await api('midia_iniciar', { mime:file.type || 'video/mp4', tamanho:file.size, duracao:(info && info.dur) || 0, nome:nome, w:(info && info.w) || 0, h:(info && info.h) || 0 });
  var parte = r.parte_bytes || 4194304, pos = 0, tent = 0;
  while (true){
    var fim = Math.min(file.size, pos + parte), resp = null;
    try { var x = await fetch(API + '?action=midia_parte&id=' + r.id + '&inicio=' + pos, { method:'POST', headers:{ Authorization:'Bearer ' + TOKEN, 'Content-Type':'application/octet-stream' }, body:file.slice(pos, fim) }); resp = await x.json(); } catch (e) {}
    if (!resp || !resp.ok){ if (++tent > 6) throw { code:'erro', message:(resp && resp.erro) || 'a internet caiu durante o envio' }; await espera(1500 * tent); continue; }
    tent = 0; pos = resp.recebido; if (aoProgresso) aoProgresso(pos / file.size); if (resp.concluido) break;
  }
  return { url:r.url, token:r.token };
}
function janelaProgresso(titulo){ var barra = el('div', 'prog', [el('i')]), info = el('p', 'nota-ajuda', 'Começando...');
  dialogo(titulo, 'Pode continuar com a tela aberta. Se a internet cair, o envio continua de onde parou.', [], el('div', 'grupo', [barra, info]));
  return function(f, txt){ barra.firstChild.style.width = Math.round(f * 100) + '%'; info.textContent = txt || (Math.round(f * 100) + '%'); }; }
function sondarVideo(file){ return new Promise(function(ok){ var v = document.createElement('video'), u = URL.createObjectURL(file); v.preload = 'metadata'; v.muted = true; v.src = u;
  v.onloadedmetadata = function(){ ok({ dur:isFinite(v.duration) ? v.duration : 0, w:v.videoWidth, h:v.videoHeight, local:u }); }; v.onerror = function(){ ok({ dur:0, w:0, h:0, local:u }); }; }); }
function reduzirImagem(file){ return new Promise(function(ok, ruim){ var im = new Image(), u = URL.createObjectURL(file);
  im.onload = function(){ var max = 2400, e = Math.min(1, max / Math.max(im.naturalWidth, im.naturalHeight)), c = document.createElement('canvas'); c.width = Math.round(im.naturalWidth * e); c.height = Math.round(im.naturalHeight * e);
    c.getContext('2d').drawImage(im, 0, 0, c.width, c.height); var png = /png|gif|webp|svg/.test(file.type); ok({ dataurl:c.toDataURL(png ? 'image/png' : 'image/jpeg', .9), w:c.width, h:c.height }); URL.revokeObjectURL(u); };
  im.onerror = function(){ ruim(new Error('imagem')); }; im.src = u; }); }

// ---------- modelos prontos (cortes da entrevista na RIC, feitos pelo Claude) ----------
async function importarModelo(){
  var m; try { m = await (await fetch('modelos/entrevista-ric.json', { cache:'no-store' })).json(); } catch (e){ dialogo('Modelo indisponível', 'Não consegui abrir o arquivo do modelo.', [['Ok', fecha]]); return; }
  var campos = {}, box = el('div', 'grupo');
  [['video', 'Vídeo da entrevista (obrigatório)', 'video/*'], ['voz', 'Voz separada (opcional)', 'audio/*,video/mp4'], ['musica', 'Música separada (opcional)', 'audio/*,video/mp4']].forEach(function(c){
    var i = el('input'); i.type = 'file'; i.accept = c[2]; campos[c[0]] = i; box.appendChild(campo(c[1] + ' · sugestão: ' + m.arquivos[c[0]], i)); });
  box.appendChild(el('p', 'nota-ajuda', 'Os arquivos que o Claude mandou na conversa já vêm com a voz e a música separadas. Se usar só o vídeo original, a música de fundo fica no volume da TV.'));
  dialogo('Trazer os ' + m.projetos.length + ' cortes da ' + m.nome, 'Cada arquivo vai uma vez para o Drive da Intus; os ' + m.projetos.length + ' projetos usam os mesmos arquivos.', [['Cancelar', fecha], ['Enviar e criar projetos', async function(){
    var fv = campos.video.files[0]; if (!fv){ alert('Escolha o vídeo da entrevista.'); return; }
    var prog = janelaProgresso('Enviando a entrevista'), urls = {}, lista = ['video', 'voz', 'musica'].filter(function(k){ return campos[k].files[0]; }), info = await sondarVideo(fv);
    try {
      for (var i = 0; i < lista.length; i++){ var k = lista[i], f = campos[k].files[0];
        var up = await enviarArquivo(f, 'entrevista-ric-' + k, k === 'video' ? info : { dur:info.dur }, function(x){ prog((i + x) / lista.length, 'Enviando ' + k + ': ' + Math.round(x * 100) + '%'); }); urls[k] = up.url; }
      for (var j = 0; j < m.projetos.length; j++){ var p = clone(m.projetos[j]); p.midia = { video:urls.video, voz:urls.voz || urls.video, musica:urls.musica || '', duracao:info.dur || 0, alt:info.w ? ORIG_W * info.h / info.w : 1080 };
        if (!urls.musica && p.audio) p.audio.musica = 0; await api('salvar', { projeto:p }); projetos[p.id] = p; }
      await api('bib_salvar', { item:{ tipo:'video', nome:'Entrevista RIC (inteira)', url:urls.video, dur:info.dur, w:info.w, h:info.h } }).then(function(r){ BIB[r.item.id] = r.item; }).catch(function(){});
      fecha(); nomesSelect(); abrir(m.projetos[0].id); estado('Cortes da entrevista criados', 'ok');
    } catch (e){ dialogo('O envio não terminou', (e && e.message) || 'Tente de novo.', [['Ok', fecha]]); }
  }]], box);
}

// Celular: o que não cabe no topo (formato, gravar, novo com vídeo, duplicar, quadro PNG) fica no menu "Mais".
$('b-mais').onclick = function(){
  var sf = $('sel-formato'), s2 = sel([].map.call(sf.options, function(o){ return [o.value, o.textContent]; }), sf.value);
  s2.onchange = function(){ sf.value = s2.value; sf.onchange(); fecha(); };
  function item(rot, id){ var x = botao(rot, function(){ fecha(); $(id).click(); }); x.style.justifyContent = 'flex-start'; return x; }
  var lista = el('div', 'mais-lista', [campo('Formato do vídeo', s2), item('● Gravar react ou explicação', 'b-rec'), item('＋ Nova edição com um vídeo seu', 'b-novo'), item('Duplicar este projeto', 'b-dup'), item('Baixar o quadro atual (PNG)', 'b-capa'), item('🎬 Meus vídeos (painel)', 'b-videos')]);
  dialogo('Mais opções', $('estado').textContent, [['Fechar', fecha]], lista);
};

// ---------- Painel de vídeos: em edição, prontos, agendados, postados e arquivados ----------
// A situação fica no índice do servidor (action=status), então todo aparelho vê a mesma coisa.
var PV = { lista:[], filtro:'ativos', busca:'' };
var PV_ST = [['editando', 'Em edição', '✏️'], ['pronto', 'Pronto para postar', '✅'], ['agendado', 'Agendado', '📅'], ['postado', 'Postado', '🚀'], ['arquivado', 'Arquivado', '🗄️']];
var PV_REDES = [['instagram', 'Instagram'], ['tiktok', 'TikTok'], ['youtube', 'YouTube'], ['facebook', 'Facebook'], ['whatsapp', 'WhatsApp'], ['outro', 'Outro']];
function pvSt(x){ return x.status || 'editando'; }
function pvData(v){ if (!v) return ''; var m = /^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}))?/.exec(v); if (!m) return ''; return m[3] + '/' + m[2] + (m[4] && v.length <= 16 ? ' ' + m[4] + ':' + m[5] : ''); }
function pvQuando(iso){ if (!iso) return ''; var d = new Date(iso); if (isNaN(d)) return ''; return d.toLocaleDateString('pt-BR', { day:'2-digit', month:'2-digit' }) + ' ' + d.toLocaleTimeString('pt-BR', { hour:'2-digit', minute:'2-digit' }); }
async function abrirPainelVideos(){
  var ov = $('pv'); if (!ov){ ov = el('div', 'pv'); ov.id = 'pv'; document.body.appendChild(ov); }
  ov.hidden = false; ov.innerHTML = ''; ov.appendChild(el('div', 'pv-topo', [el('b', null, '🎬 Meus vídeos'), el('span', 'nota-ajuda', 'Carregando...')]));
  try { var r = await api('inicio'); PV.lista = r.projetos || []; (r.projetos || []).forEach(function(p){ if (!projetos[p.id]) projetos[p.id] = { id:p.id, nome:p.nome, formato:p.formato, atualizado_em:p.atualizado_em, _parcial:true }; }); nomesSelect(); }
  catch (e){ if (e && e.code === 'sem_acesso') return; ov.innerHTML = ''; ov.appendChild(el('div', 'pv-topo', [el('b', null, '🎬 Meus vídeos'), botao('Fechar', fechaPainelVideos)])); ov.appendChild(el('p', 'nota-ajuda', 'Não consegui ler a lista. Confira a internet.')); return; }
  pintaPainelVideos();
}
function fechaPainelVideos(){ var ov = $('pv'); if (ov) ov.hidden = true; }
function pintaPainelVideos(){
  var ov = $('pv'); if (!ov) return; ov.innerHTML = '';
  var conta = {}; PV_ST.forEach(function(s){ conta[s[0]] = 0; }); PV.lista.forEach(function(x){ conta[pvSt(x)] = (conta[pvSt(x)] || 0) + 1; });
  var busca = el('input'); busca.type = 'search'; busca.placeholder = 'Buscar pelo nome'; busca.value = PV.busca; busca.oninput = function(){ PV.busca = busca.value; pintaLista(); };
  var novo = botao('＋ Nova edição', function(){ fechaPainelVideos(); $('b-novo').click(); }); novo.className = 'btn pri';
  ov.appendChild(el('div', 'pv-topo', [el('b', null, '🎬 Meus vídeos'), el('span', 'pv-esp'), novo, botao('✕ Fechar', fechaPainelVideos, 'Fechar o painel de vídeos')]));
  var cards = el('div', 'pv-num');
  [['ativos', 'Todos ativos', PV.lista.length - conta.arquivado, '🎞️']].concat(PV_ST.map(function(s){ return [s[0], s[1], conta[s[0]], s[2]]; })).forEach(function(c){
    var b = el('button', 'pv-n' + (PV.filtro === c[0] ? ' on' : ''), [el('span', 'pv-ic', c[3]), el('b', null, String(c[2])), el('span', null, c[1])]); b.type = 'button';
    b.onclick = function(){ PV.filtro = c[0]; pintaPainelVideos(); }; cards.appendChild(b); });
  ov.appendChild(cards); ov.appendChild(busca);
  var corpo = el('div', 'pv-corpo'); ov.appendChild(corpo);
  function pintaLista(){
    corpo.innerHTML = ''; var q = PV.busca.trim().toLowerCase();
    var itens = PV.lista.filter(function(x){ var st = pvSt(x); return (PV.filtro === 'ativos' ? st !== 'arquivado' : st === PV.filtro) && (!q || String(x.nome || '').toLowerCase().indexOf(q) >= 0); });
    if (!itens.length){ corpo.appendChild(el('p', 'nota-ajuda', PV.lista.length ? 'Nada nesta situação.' : 'Nenhum vídeo ainda. Comece em "＋ Nova edição".')); return; }
    var grupos = PV.filtro === 'ativos' ? PV_ST.filter(function(s){ return s[0] !== 'arquivado'; }) : PV_ST.filter(function(s){ return s[0] === PV.filtro; });
    grupos.forEach(function(g){
      var dela = itens.filter(function(x){ return pvSt(x) === g[0]; });
      if (g[0] === 'agendado') dela.sort(function(a, b){ return String(a.agendado_para || '').localeCompare(String(b.agendado_para || '')); });
      else if (g[0] === 'postado') dela.sort(function(a, b){ return String(b.postado_em || '').localeCompare(String(a.postado_em || '')); });
      var col = el('section', 'pv-col', [el('h3', null, g[2] + ' ' + g[1] + ' · ' + dela.length)]);
      if (!dela.length) col.appendChild(el('p', 'nota-ajuda', 'Nenhum.'));
      dela.forEach(function(x){ col.appendChild(cartaoVideo(x)); }); corpo.appendChild(col); });
  }
  pintaLista();
}
function cartaoVideo(x){
  var st = pvSt(x), meta = [(FORMATOS[x.formato] ? FORMATOS[x.formato].n : 'Reel'), 'editado ' + pvQuando(x.atualizado_em) + (x.por_nome ? ' por ' + x.por_nome.split(' ')[0] : '')];
  var info = [];
  if (st === 'agendado' && x.agendado_para) info.push('📅 ' + pvData(x.agendado_para));
  if (st === 'postado' && x.postado_em) info.push('🚀 ' + pvData(x.postado_em));
  if (x.redes && x.redes.length) info.push(x.redes.map(function(r){ var n = PV_REDES.filter(function(q){ return q[0] === r; })[0]; return n ? n[1] : r; }).join(', '));
  if (x.ia) info.push('IA: ' + x.ia);
  var s = sel(PV_ST.map(function(q){ return [q[0], q[2] + ' ' + q[1]]; }), st); s.setAttribute('aria-label', 'Situação de ' + (x.nome || 'vídeo'));
  s.onchange = function(){ var v = s.value; if (v === 'agendado' || v === 'postado') detalhesVideo(x, v); else mudaStatusVideo(x, { status:v }); };
  var abrirB = botao('Abrir', function(){ fechaPainelVideos(); abrir(x.id); }); abrirB.className = 'btn pri peq';
  var kids = [el('b', 'pv-nome', x.nome || x.id), el('span', 'nota-ajuda', meta.join(' · '))];
  if (info.length) kids.push(el('span', 'pv-info', info.join(' · ')));
  if (x.obs) kids.push(el('span', 'nota-ajuda', '📝 ' + x.obs));
  var acoes = el('div', 'linha', [abrirB, s, botao('Detalhes', function(){ detalhesVideo(x, st); })]);
  if (x.link){ var a = el('a', 'btn peq', '🔗 Ver post'); a.href = x.link; a.target = '_blank'; a.rel = 'noopener'; acoes.appendChild(a); }
  kids.push(acoes);
  return el('div', 'pv-card st-' + st, kids);
}
function detalhesVideo(x, st){
  var cx = el('div', 'grupo'), stSel = sel(PV_ST.map(function(q){ return [q[0], q[2] + ' ' + q[1]]; }), st);
  var ag = el('input'); ag.type = 'datetime-local'; ag.value = (x.agendado_para || '').slice(0, 16);
  var po = el('input'); po.type = 'date'; po.value = (x.postado_em || '').slice(0, 10) || new Date().toISOString().slice(0, 10);
  var lk = el('input'); lk.type = 'url'; lk.placeholder = 'https://instagram.com/p/...'; lk.value = x.link || '';
  var ob = el('textarea'); ob.rows = 2; ob.maxLength = 300; ob.value = x.obs || ''; ob.placeholder = 'Legenda usada, ideia de repostar, etc.';
  var redes = el('div', 'linha'), marc = {}; PV_REDES.forEach(function(r){ var c = el('input'); c.type = 'checkbox'; c.checked = (x.redes || []).indexOf(r[0]) >= 0; marc[r[0]] = c; redes.appendChild(el('label', 'chk', [c, ' ' + r[1]])); });
  var cAg = campo('Data e hora do agendamento', ag), cPo = campo('Data da postagem', po);
  function mostra(){ cAg.hidden = stSel.value !== 'agendado'; cPo.hidden = stSel.value !== 'postado'; }
  stSel.onchange = mostra; mostra();
  [campo('Situação', stSel), cAg, cPo, el('div', 'campo', [el('span', null, 'Onde vai ou foi postado'), redes]), campo('Link do post (opcional)', lk), campo('Anotação (opcional)', ob)].forEach(function(n){ cx.appendChild(n); });
  dialogo(x.nome || 'Vídeo', '', [['Cancelar', function(){ fecha(); pintaPainelVideos(); }], ['Salvar', async function(){
    var d = { status:stSel.value, link:lk.value.trim(), obs:ob.value, redes:PV_REDES.map(function(r){ return r[0]; }).filter(function(r){ return marc[r].checked; }) };
    if (d.link && !/^https:\/\//.test(d.link)){ alert('O link precisa começar com https://'); return; }
    if (d.status === 'agendado'){ if (!ag.value){ alert('Escolha a data do agendamento.'); return; } d.agendado_para = ag.value; }
    if (d.status === 'postado') d.postado_em = po.value;
    if (await mudaStatusVideo(x, d)) fecha();
  }]], cx);
}
async function mudaStatusVideo(x, d){
  d.id = x.id;
  try { var r = await api('status', d); var i = PV.lista.findIndex(function(q){ return q.id === x.id; }); if (i >= 0) PV.lista[i] = Object.assign({}, PV.lista[i], r.item); estado('Situação salva', 'ok'); pintaPainelVideos(); return true; }
  catch (e){ if (e && e.code === 'sem_acesso') return false; alert((e && e.message) || 'Não consegui salvar a situação.'); pintaPainelVideos(); return false; }
}
// Ao salvar a exportação no Drive, o vídeo que estava em edição passa a "Pronto para postar".
window.cutAutoPronto = function(id){
  api('inicio').then(function(r){ var x = (r.projetos || []).filter(function(q){ return q.id === id; })[0]; if (x && pvSt(x) === 'editando') return api('status', { id:id, status:'pronto' }); }).catch(function(){});
};
$('b-videos').onclick = abrirPainelVideos;

// ---------- sobreposições de vídeo (react, comparativo, janela no canto) ----------
// Cada camada toca um vídeo (de P.fontes) por cima do vídeo principal, num retângulo ou bolha,
// do segundo `ini` ao `fim` do vídeo final, começando no segundo `src` da gravação dela.
function camadaPadrao(key, nome, durFonte, srcIni){
  var J = lay('janela'), w = Math.round(W * .46), h = Math.round(w * 9 / 16), resta = Math.max(1, (durFonte || 10) - (srcIni || 0));
  return { de:key, nome:nome, ini:+T.toFixed(2), fim:+Math.min(Math.max(dur(), T + 1), T + resta).toFixed(2), src:+(srcIni || 0).toFixed(2),
    x:W - w - 36, y:J.y + 36, w:w, h:h, forma:'ret', borda:true, corBorda:'#ffffff', espessura:6, raio:18, volume:.8, zoom:1, fx:.5, fy:.5, espelho:false };
}
function adicionarCamada(k){ P.camadas.push(k); selEl = 'camada:' + (P.camadas.length - 1); atualizaTudo(); agendaSalvar('Sobrepor ' + (k.nome || 'vídeo')); seleciona(); }
async function sobreporProjetoAsync(id){ var X = await carregarProjeto(id); if (!X) return; var key = garantirFonte(X.id, '', snapshot(X)), t0 = (X.trechos && X.trechos[0]) ? X.trechos[0].ini : 0;
  adicionarCamada(camadaPadrao(key, X.nome || X.id, (X.midia && X.midia.duracao) || fonteDur(key), t0)); }
function sobreporBib(item){ var key = fonteDeVideo('bib-' + item.id, item.nome, midiaDeItem(item)); adicionarCamada(camadaPadrao(key, item.nome, item.dur, 0)); }
function srcCamada(k, t){ return k.src + (t - k.ini); }
function sincCamadas(rodando){
  (P.camadas || []).forEach(function(k){ var M = camadaMid(k.de), ativo = T >= k.ini && T <= k.fim, alvo = srcCamada(k, T);
    M.av.volume = cl(k.volume == null ? .8 : k.volume, 0, 1);
    if (!ativo){ if (!M.v.paused) M.v.pause(); if (!M.av.paused) M.av.pause(); return; }
    if (rodando){ if (Math.abs(M.v.currentTime - alvo) > .3){ M.v.currentTime = alvo; try { M.av.currentTime = alvo; } catch (e) {} }
      if (M.v.paused) M.v.play().catch(function(){}); if (M.voz && M.av.paused) M.av.play().catch(function(){}); }
    else { if (Math.abs(M.v.currentTime - alvo) > .04) M.v.currentTime = alvo; if (!M.av.paused) M.av.pause(); try { M.av.currentTime = alvo; } catch (e) {} }
  });
}
function desenhaCamadas(c, t, reg){
  (P.camadas || []).forEach(function(k, i){
    if (t < k.ini || t > k.fim) return; var v = camadaMid(k.de).v, x = k.x, y = k.y, w = k.w, h = k.forma === 'circulo' ? k.w : k.h;
    c.save(); c.beginPath();
    if (k.forma === 'circulo') c.arc(x + w / 2, y + h / 2, w / 2, 0, Math.PI * 2); else if (c.roundRect) c.roundRect(x, y, w, h, k.raio || 0); else c.rect(x, y, w, h);
    c.clip();
    if (v.readyState >= 2 && v.videoWidth){
      var vw = v.videoWidth, vh = v.videoHeight, ar = w / h, sw, sh, z = Math.max(1, k.zoom || 1);
      if (vw / vh > ar){ sh = vh; sw = vh * ar; } else { sw = vw; sh = vw / ar; } sw /= z; sh /= z;
      var sx = (vw - sw) * cl(k.fx == null ? .5 : k.fx, 0, 1), sy = (vh - sh) * cl(k.fy == null ? .5 : k.fy, 0, 1);
      if (k.espelho){ c.translate(x * 2 + w, 0); c.scale(-1, 1); }
      c.drawImage(v, sx, sy, sw, sh, x, y, w, h);
    } else { c.fillStyle = '#20241c'; c.fillRect(x, y, w, h); }
    c.restore();
    if (k.borda){ c.save(); c.lineWidth = k.espessura || 6; c.strokeStyle = k.corBorda || '#ffffff'; c.beginPath();
      if (k.forma === 'circulo') c.arc(x + w / 2, y + h / 2, w / 2, 0, Math.PI * 2); else if (c.roundRect) c.roundRect(x, y, w, h, k.raio || 0); else c.rect(x, y, w, h); c.stroke(); c.restore(); }
    caixa(reg, 'camada:' + i, x, y, w, h, { redim:true });
  });
}
function presetCamada(k, tipo){
  var J = lay('janela');
  if (tipo === 'canto'){ k.forma = 'ret'; k.w = Math.round(W * .46); k.h = Math.round(k.w * 9 / 16); k.x = W - k.w - 36; k.y = J.y + 36; }
  else if (tipo === 'bolha'){ k.forma = 'circulo'; k.w = k.h = Math.round(Math.min(W, H) * .36); k.x = W - k.w - 40; k.y = Math.round(H * .6); }
  else if (tipo === 'baixo'){ k.forma = 'ret'; k.raio = 0; k.borda = false; var meio = Math.round(H / 2); J.y = 0; J.h = meio; J.alinhar = 0; k.x = 0; k.y = meio; k.w = W; k.h = H - meio; }
  else if (tipo === 'lado'){ k.forma = 'ret'; k.raio = 0; k.borda = false; k.x = Math.round(W / 2); k.y = J.y; k.w = W - k.x; k.h = J.h; }
  else if (tipo === 'cheia'){ k.forma = 'ret'; k.raio = 0; k.borda = false; k.x = 0; k.y = 0; k.w = W; k.h = H; }
}
function cardCamada(i){
  var k = P.camadas[i], id = 'camada:' + i, ttl = el('div', 'linha');
  ttl.appendChild(campo('Começa no vídeo final (s)', num(k.ini, function(v){ var d0 = k.fim - k.ini; k.ini = Math.max(0, v); k.fim = +(k.ini + d0).toFixed(2); atualizaTudo(); agendaSalvar('Tempo da sobreposição'); inspetor(); })));
  ttl.appendChild(campo('Termina (s)', num(k.fim, function(v){ k.fim = Math.max(k.ini + .3, v); atualizaTudo(); agendaSalvar('Tempo da sobreposição'); })));
  ttl.appendChild(campo('A partir do segundo da gravação', num(k.src, function(v){ k.src = Math.max(0, v); sincCamadas(false); pinta(); agendaSalvar('Ponto de início da sobreposição'); })));
  return el('div', 'cartao' + (selEl === id ? ' sel' : ''), [el('div', 'topo', [el('b', null, k.nome || 'Sobreposição ' + (i + 1)), acoesItem(id)]),
    el('div', 'grupo', [el('h3', null, 'Arrumação'), el('div', 'linha', [
      botao('Janela no canto', function(){ presetCamada(k, 'canto'); pinta(); agendaSalvar('Sobreposição no canto'); inspetor(); }),
      botao('Bolha redonda', function(){ presetCamada(k, 'bolha'); pinta(); agendaSalvar('Sobreposição em bolha'); inspetor(); }),
      botao('Comparar: em cima e embaixo', function(){ presetCamada(k, 'baixo'); atualizaTudo(); agendaSalvar('Comparativo em cima e embaixo'); inspetor(); }),
      botao('Comparar: lado a lado', function(){ presetCamada(k, 'lado'); pinta(); agendaSalvar('Comparativo lado a lado'); inspetor(); }),
      botao('Tela inteira', function(){ presetCamada(k, 'cheia'); pinta(); agendaSalvar('Sobreposição em tela inteira'); inspetor(); })]),
      el('p', 'nota-ajuda', 'Em cima e embaixo também sobe a janela do vídeo principal para a metade de cima. Lado a lado cobre a metade direita: use o recorte livre do corte principal para mostrar o movimento na metade esquerda.')]),
    el('div', 'linha', [campo('Forma', opcoes(k, 'forma', [['ret', 'Retângulo'], ['circulo', 'Bolha redonda']], 'Forma da sobreposição')), chk(k, 'espelho', 'Espelhar', 'Espelhar sobreposição'),
      chk(k, 'borda', 'Borda', 'Borda da sobreposição'), corIn(k, 'corBorda', 'Cor da borda', 'Cor da borda')]),
    el('div', 'grade2', [deslize(k, 'x', 'Horizontal', -W / 2, W, 2, 'Mover sobreposição'), deslize(k, 'y', 'Vertical', -H / 3, H, 2, 'Mover sobreposição'),
      deslize(k, 'w', 'Largura', 80, W, 2, 'Largura da sobreposição'), k.forma === 'circulo' ? el('span') : deslize(k, 'h', 'Altura', 80, H, 2, 'Altura da sobreposição'),
      deslize(k, 'zoom', 'Zoom dentro', 1, 3, .01, 'Zoom da sobreposição', pct), deslize(k, 'fx', 'Foco horizontal', 0, 1, .01, 'Foco da sobreposição', pct), deslize(k, 'fy', 'Foco vertical', 0, 1, .01, 'Foco da sobreposição', pct),
      deslize(k, 'raio', 'Cantos', 0, 200, 1, 'Cantos da sobreposição'), deslize(k, 'espessura', 'Espessura da borda', 1, 24, 1, 'Borda da sobreposição'), deslize(k, 'volume', 'Volume dela', 0, 1, .01, 'Volume da sobreposição', pct)]),
    el('div', 'grupo', [el('h3', null, 'Tempo'), ttl, el('p', 'nota-ajuda', 'Na faixa Sobreposição da linha do tempo: arraste o bloco para mudar quando ela entra; puxe a borda da esquerda para cortar o começo e a da direita para cortar o fim.')])]);
}

// ---------- gravação ao vivo (react): câmera por cima do vídeo, com controle do fundo ----------
var EST = null, AG = null, REC_MAX = 300;
function grafoAudio(){
  if (AG) return AG; var Ctx = window.AudioContext || window.webkitAudioContext, ac = new Ctx(), dest = ac.createMediaStreamDestination(), bg = ac.createGain();
  bg.gain.value = 1; bg.connect(ac.destination); bg.connect(dest); AG = { ac:ac, dest:dest, bg:bg, ligados:[] }; return AG;
}
function ligaAudioFundo(){
  var g = grafoAudio(), els = [];
  Object.keys(MID).forEach(function(k){ els.push(MID[k].av, MID[k].am); }); Object.keys(CAM).forEach(function(k){ els.push(CAM[k].av); });
  els.forEach(function(a){ if (g.ligados.indexOf(a) >= 0) return; try { var s = g.ac.createMediaElementSource(a); s.connect(g.bg); g.ligados.push(a); } catch (e) {} });
}
function msgCamera(e){
  var n = e && e.name;
  if (n === 'NotAllowedError' || n === 'SecurityError') return 'O navegador não deixou usar a câmera e o microfone. Toque no cadeado ao lado do endereço do site e permita câmera e microfone para intusfit.com.br.';
  if (n === 'NotFoundError' || n === 'OverconstrainedError') return 'Não encontrei câmera ou microfone neste aparelho.';
  if (n === 'NotReadableError') return 'A câmera ou o microfone está sendo usado por outro programa (Zoom, Meet, outra aba). Feche e tente de novo.';
  return 'A câmera não abriu (' + (n || 'erro') + ').';
}
async function abrirEstudio(){
  if (!P) return; pausar();
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder){ dialogo('Gravação indisponível neste navegador', 'Use o Chrome ou o Edge no computador, ou o Chrome no Android.', [['Ok', fecha]]); return; }
  var stream;
  try { stream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:'user', width:{ ideal:1280 }, height:{ ideal:720 } }, audio:{ echoCancellation:true, noiseSuppression:true, autoGainControl:true } }); }
  catch (e){ dialogo('Não consegui abrir a câmera', msgCamera(e), [['Ok', fecha]]); return; }
  var g = grafoAudio(); g.ac.resume().catch(function(){}); ligaAudioFundo();
  var cam = el('video'); cam.muted = true; cam.playsInline = true; cam.srcObject = stream; cam.play().catch(function(){});
  var mic = g.ac.createMediaStreamSource(stream), micG = g.ac.createGain(); mic.connect(micG); micG.connect(g.dest);
  var cv = document.createElement('canvas'); cv.width = W; cv.height = H; var off = document.createElement('canvas'); off.width = W; off.height = H;
  var tam = Math.round(Math.min(W, H) * .38);
  EST = { stream:stream, cam:cam, mic:mic, micG:micG, cv:cv, cx:cv.getContext('2d'), off:off, ox:off.getContext('2d'), rec:null, partes:[], seg:0, ini:0, estado:'pronto', blob:null, tipo:'',
    caixa:{ forma:'bolha', x:W - tam - 40, y:Math.round(H * .6), w:tam, h:tam, espelho:true }, bgVol:.6, fundo:true, loop:0 };
  g.bg.gain.value = EST.bgVol;
  montaEstudio(); EST.loop = requestAnimationFrame(desenhaEstudio);
}
function desenhaEstudio(){
  if (!EST) return; var c = EST.cx, b = EST.caixa, J = lay('janela');
  c.fillStyle = lay('geral').fundo; c.fillRect(0, 0, W, H);
  if (EST.fundo && b.forma !== 'cheia'){
    if (b.forma === 'metade'){ desenha(EST.ox, T, null); var mh = H / 2, sc = Math.min(W / W, mh / J.h), dw = W * sc, dh = J.h * sc; c.drawImage(EST.off, 0, J.y, W, J.h, (W - dw) / 2, (mh - dh) / 2, dw, dh); }
    else desenha(c, T, null);
  }
  var v = EST.cam, x = b.x, y = b.y, w = b.w, h = b.h;
  if (b.forma === 'metade'){ x = 0; y = H / 2; w = W; h = H / 2; } else if (b.forma === 'cheia'){ x = 0; y = 0; w = W; h = H; } else if (b.forma === 'bolha') h = w;
  if (v.readyState >= 2 && v.videoWidth){
    c.save(); c.beginPath(); if (b.forma === 'bolha') c.arc(x + w / 2, y + h / 2, w / 2, 0, Math.PI * 2); else if (b.forma === 'ret' && c.roundRect) c.roundRect(x, y, w, h, 24); else c.rect(x, y, w, h); c.clip();
    var vw = v.videoWidth, vh = v.videoHeight, ar = w / h, sw, sh; if (vw / vh > ar){ sh = vh; sw = vh * ar; } else { sw = vw; sh = vw / ar; }
    if (b.espelho){ c.translate(x * 2 + w, 0); c.scale(-1, 1); }
    c.drawImage(v, (vw - sw) / 2, (vh - sh) / 2, sw, sh, x, y, w, h); c.restore();
    if (b.forma === 'bolha' || b.forma === 'ret'){ c.save(); c.lineWidth = 7; c.strokeStyle = '#ffffff'; c.beginPath(); if (b.forma === 'bolha') c.arc(x + w / 2, y + h / 2, w / 2, 0, Math.PI * 2); else if (c.roundRect) c.roundRect(x, y, w, h, 24); else c.rect(x, y, w, h); c.stroke(); c.restore(); }
  }
  var t = tempoGravado(); if (EST.rec && t >= REC_MAX) pararGravacao();
  var tc = $('est-tempo'); if (tc){ tc.textContent = fmtc(t) + ' / ' + fmtc(REC_MAX); tc.className = 'est-tempo' + (EST.estado === 'gravando' ? ' rec' : '') + (t > REC_MAX - 30 ? ' fim' : ''); }
  var bt = $('est-bg-t'); if (bt) bt.textContent = fmt(T) + ' / ' + fmt(dur()); var sl = $('est-bg-pos'); if (sl && !sl.matches(':active')){ sl.max = dur(); sl.value = T; }
  var pb = $('est-bg-play'); if (pb) pb.textContent = tocando ? '⏸ Pausar fundo' : '▶ Tocar fundo';
  EST.loop = requestAnimationFrame(desenhaEstudio);
}
function tempoGravado(){ if (!EST) return 0; return EST.seg + (EST.estado === 'gravando' ? (performance.now() - EST.ini) / 1000 : 0); }
function montaEstudio(){
  var ov = el('div', 'estudio'); ov.id = 'estudio';
  var area = el('div', 'est-area'), box = el('div', 'est-tela'); box.style.aspectRatio = W + '/' + H; if (W > H) box.classList.add('largo'); box.appendChild(EST.cv); area.appendChild(box);
  var arr2 = null;
  EST.cv.addEventListener('pointerdown', function(e){ var b = EST.caixa; if (b.forma === 'metade' || b.forma === 'cheia') return; var r = EST.cv.getBoundingClientRect(), k = W / r.width; EST.cv.setPointerCapture(e.pointerId); arr2 = { x:e.clientX, y:e.clientY, bx:b.x, by:b.y, k:k }; });
  EST.cv.addEventListener('pointermove', function(e){ if (!arr2) return; var b = EST.caixa; b.x = Math.round(cl(arr2.bx + (e.clientX - arr2.x) * arr2.k, -b.w / 2, W - b.w / 2)); b.y = Math.round(cl(arr2.by + (e.clientY - arr2.y) * arr2.k, -b.h / 2, H - b.h / 2)); });
  EST.cv.addEventListener('pointerup', function(){ arr2 = null; });
  var lado = el('div', 'est-lado');
  var formas = chips([['bolha', 'Bolha'], ['ret', 'Retângulo'], ['metade', 'Metade de baixo'], ['cheia', 'Só a câmera']], EST.caixa.forma, function(v){ EST.caixa.forma = v; if (v === 'ret'){ EST.caixa.h = Math.round(EST.caixa.w * 1.25); } montaControles(); });
  function montaControles(){
    lado.innerHTML = '';
    lado.appendChild(el('div', 'linha', [el('b', null, 'Gravar react'), el('span', 'esp'), botao('Fechar', fecharEstudio)]));
    lado.appendChild(el('div', 'est-rec', [el('span', 'est-tempo', '0:00 / 5:00')])); lado.lastChild.firstChild.id = 'est-tempo';
    var acoes = el('div', 'linha');
    if (EST.estado === 'pronto'){ var g = botao('● Começar a gravar', comecarGravacao); g.className = 'btn rec'; acoes.appendChild(g); }
    if (EST.estado === 'gravando' || EST.estado === 'pausado'){
      acoes.appendChild(botao(EST.estado === 'gravando' ? '⏸ Pausar gravação' : '● Continuar gravação', pausarGravacao));
      var s = botao('■ Terminar', pararGravacao); s.className = 'btn pri'; acoes.appendChild(s); }
    lado.appendChild(acoes);
    var pos = range(0, dur(), .01, T, function(v){ irPara(v); }); pos.id = 'est-bg-pos';
    var pb = botao('▶ Tocar fundo', function(){ tocando ? pausar() : tocar(); }); pb.id = 'est-bg-play'; pb.className = 'btn pri';
    var bt = el('span', 't', ''); bt.id = 'est-bg-t';
    var vol = deslize(EST, 'bgVol', 'Volume do fundo', 0, 1, .01, null, pct); vol.lastChild.oninput = function(){ EST.bgVol = +this.value; AG.bg.gain.value = EST.bgVol; vol.firstChild.textContent = 'Volume do fundo: ' + pct(EST.bgVol); };
    lado.appendChild(el('div', 'grupo', [el('h3', null, 'Vídeo do fundo (' + (P.nome || 'projeto') + ')'), el('div', 'linha', [botao('⏮', function(){ irPara(0); }, 'Voltar ao início'), botao('−5 s', function(){ irPara(T - 5); }), pb, botao('+5 s', function(){ irPara(T + 5); })]), pos, bt, vol,
      el('p', 'nota-ajuda', 'Pause, volte e avance o fundo à vontade enquanto grava: tudo entra no vídeo do jeito que aparece aqui. Atalhos: espaço toca ou pausa, setas voltam ou avançam 5 s.')]));
    var tamS = deslize(EST.caixa, 'w', 'Tamanho da câmera', 160, W, 2, null); tamS.lastChild.oninput = function(){ var b = EST.caixa, cxm = b.x + b.w / 2, cym = b.y + b.h / 2; b.w = +this.value; b.h = b.forma === 'ret' ? Math.round(b.w * 1.25) : b.w; b.x = Math.round(cxm - b.w / 2); b.y = Math.round(cym - b.h / 2); tamS.firstChild.textContent = 'Tamanho da câmera: ' + b.w; };
    var esp = el('input'); esp.type = 'checkbox'; esp.checked = EST.caixa.espelho; esp.onchange = function(){ EST.caixa.espelho = esp.checked; };
    lado.appendChild(el('div', 'grupo', [el('h3', null, 'Câmera'), formas, tamS, el('label', 'chk', [esp, ' Espelhar']), el('p', 'nota-ajuda', 'Arraste a câmera na tela para mudar o lugar. Use fone de ouvido para o som do vídeo não vazar no microfone.')]));
    formas.querySelectorAll('button').forEach(function(b){ b.setAttribute('aria-pressed', String(b.textContent === ({ bolha:'Bolha', ret:'Retângulo', metade:'Metade de baixo', cheia:'Só a câmera' })[EST.caixa.forma])); });
  }
  EST.montaControles = montaControles; montaControles();
  ov.appendChild(area); ov.appendChild(lado); document.body.appendChild(ov);
  EST.teclas = function(e){ if (!EST || /INPUT|TEXTAREA|SELECT/.test(e.target.tagName)) return; if (e.code === 'Space'){ e.preventDefault(); e.stopPropagation(); tocando ? pausar() : tocar(); }
    else if (e.key === 'ArrowLeft'){ e.preventDefault(); e.stopPropagation(); irPara(T - 5); } else if (e.key === 'ArrowRight'){ e.preventDefault(); e.stopPropagation(); irPara(T + 5); } };
  document.addEventListener('keydown', EST.teclas, true);
}
function tipoGravacao(){ var l = ['video/mp4;codecs=avc1.640028,mp4a.40.2', 'video/mp4;codecs=avc1,mp4a', 'video/mp4', 'video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm'];
  for (var i = 0; i < l.length; i++) if (MediaRecorder.isTypeSupported(l[i])) return l[i]; return ''; }
function comecarGravacao(){
  if (!EST || EST.rec) return; var n = 3, lado = $('est-tempo');
  (function conta(){ if (!EST) return; if (n > 0){ if (lado) lado.textContent = 'Começa em ' + n; n--; setTimeout(conta, 800); return; }
    ligaAudioFundo(); EST.tipo = tipoGravacao();
    var trilhas = [EST.cv.captureStream(30).getVideoTracks()[0]].concat(AG.dest.stream.getAudioTracks());
    try { EST.rec = new MediaRecorder(new MediaStream(trilhas), { mimeType:EST.tipo || undefined, videoBitsPerSecond:W * H > 1.5e6 ? 4000000 : 3000000, audioBitsPerSecond:128000 }); }
    catch (e){ dialogo('A gravação não começou', 'O navegador não aceitou gravar (' + (e.name || 'erro') + ').', [['Ok', fecha]]); return; }
    EST.partes = []; EST.rec.ondataavailable = function(ev){ if (ev.data && ev.data.size) EST.partes.push(ev.data); };
    EST.rec.onstop = function(){ revisarGravacao(); };
    EST.rec.start(1000); EST.estado = 'gravando'; EST.ini = performance.now(); EST.seg = 0; EST.montaControles();
  })();
}
function pausarGravacao(){
  if (!EST || !EST.rec) return;
  if (EST.estado === 'gravando'){ EST.rec.pause(); EST.seg += (performance.now() - EST.ini) / 1000; EST.estado = 'pausado'; }
  else { EST.rec.resume(); EST.ini = performance.now(); EST.estado = 'gravando'; }
  EST.montaControles();
}
function pararGravacao(){ if (!EST || !EST.rec || EST.rec.state === 'inactive') return; EST.durFinal = tempoGravado(); EST.estado = 'parado'; pausar(); EST.rec.stop(); }
function revisarGravacao(){
  if (!EST) return; var tipo = (EST.tipo || 'video/webm').split(';')[0], blob = new Blob(EST.partes, { type:tipo }), d = EST.durFinal || tempoGravado(); EST.blob = blob; EST.rec = null;
  var url = URL.createObjectURL(blob), vid = el('video'); vid.src = url; vid.controls = true; vid.playsInline = true; vid.style.width = '100%'; vid.style.maxHeight = '52vh'; vid.style.background = '#000';
  var ext = tipo.indexOf('mp4') >= 0 ? 'mp4' : 'webm', nome = 'React ' + new Date().toLocaleString('pt-BR', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit' }).replace(',', '');
  var info = el('p', 'nota-ajuda', fmtc(d) + ' gravados · ' + (blob.size / 1048576).toFixed(1) + ' MB');
  fecharEstudio(true);
  dialogo('Gravação pronta', 'O que fazer com ela?', [
    ['Descartar', function(){ dialogo('Descartar a gravação?', 'Ela some e não dá para recuperar.', [['Voltar', function(){ revisarDeNovo(); }], ['Descartar', function(){ fecha(); URL.revokeObjectURL(url); estado('Gravação descartada'); }]]); }],
    ['Baixar uma cópia', function(){ baixarBlob(blob, nome.replace(/[\/:]/g, '-') + '.' + ext); }],
    ['Guardar como rascunho', function(){ guardarGravacao(blob, d, nome, tipo, true); }],
    ['Ir para a edição', function(){ guardarGravacao(blob, d, nome, tipo, false); }]], el('div', 'grupo', [vid, info]));
  $('caixa').style.maxWidth = '640px';
  function revisarDeNovo(){ dialogo('Gravação pronta', 'O que fazer com ela?', [['Descartar', function(){ fecha(); URL.revokeObjectURL(url); estado('Gravação descartada'); }], ['Guardar como rascunho', function(){ guardarGravacao(blob, d, nome, tipo, true); }], ['Ir para a edição', function(){ guardarGravacao(blob, d, nome, tipo, false); }]], el('div', 'grupo', [vid, info])); }
}
function fecharEstudio(semPerguntar){
  if (!EST) return;
  if (!semPerguntar && (EST.estado === 'gravando' || EST.estado === 'pausado')){ if (!window.confirm('Fechar agora descarta o que foi gravado. Fechar mesmo?')) return; try { EST.rec.onstop = null; EST.rec.stop(); } catch (e) {} }
  cancelAnimationFrame(EST.loop); EST.stream.getTracks().forEach(function(t){ t.stop(); }); try { EST.mic.disconnect(); } catch (e) {}
  if (AG) AG.bg.gain.value = 1; document.removeEventListener('keydown', EST.teclas, true);
  var ov = $('estudio'); if (ov) ov.remove(); pausar(); EST = null;
}
async function guardarGravacao(blob, d, nome, tipo, rascunho){
  var prog = janelaProgresso('Guardando a gravação no Drive da Intus');
  try {
    var ext = tipo.indexOf('mp4') >= 0 ? 'mp4' : 'webm', f = new File([blob], nome.replace(/[\/:]/g, '-') + '.' + ext, { type:tipo });
    var up = await enviarArquivo(f, 'react', { dur:d, w:W, h:H }, function(x){ prog(x, 'Enviando: ' + Math.round(x * 100) + '%'); });
    BLOBS[up.url] = Promise.resolve(URL.createObjectURL(blob));
    var item = await guardaNaBib({ tipo:'video', nome:nome, url:up.url, dur:+d.toFixed(2), w:W, h:H, rascunho:!!rascunho, react:true });
    fecha();
    if (!item){ dialogo('A gravação subiu, mas não entrou na biblioteca', 'Tente guardar de novo ou baixe uma cópia.', [['Baixar uma cópia', function(){ baixarBlob(blob, f.name); }], ['Ok', fecha]]); return; }
    if (rascunho){ estado('Rascunho guardado na Biblioteca', 'ok'); dialogo('Rascunho guardado', 'Está na Biblioteca, em Vídeos, com a etiqueta "rascunho". Dá para abrir para editar de qualquer aparelho.', [['Ok', fecha]]); }
    else projetoDeGravacao(item);
  } catch (e){
    if (e && e.code === 'sem_acesso') return;
    dialogo('Não consegui guardar', ((e && e.message) || 'O envio falhou') + '. Baixe uma cópia para não perder a gravação.', [['Fechar', fecha], ['Baixar uma cópia', function(){ baixarBlob(blob, nome.replace(/[\/:]/g, '-') + (tipo.indexOf('mp4') >= 0 ? '.mp4' : '.webm')); }]]);
  }
}
function midiaDeItem(item){ return { video:item.url, voz:item.url, musica:'', duracao:item.dur || 5, alt:item.w && item.h ? ORIG_W * item.h / item.w : 1080 }; }
function projetoDeGravacao(item){
  var id = novoId('react-'), m = midiaDeItem(item), fmtK = P ? (P.formato || 'reel') : 'reel';
  projetos[id] = { id:id, nome:item.nome, formato:fmtK, gancho:'', destaque:[], trechos:[{ ini:0, fim:+(item.dur || 5).toFixed(2), cheia:true, limpo:true }], palavras:[],
    planos:[{ ini:0, fim:(item.dur || 5) + 1, cx:960, larg:ORIG_W, y:0, tarja:true }], notas:[], textos:[], sombras:[], imagens:[], camadas:[], audio:{ voz:1, musica:0 },
    estilo:{ cores:['amarelo', 'verde'], tamanho:84, maiusculas:true, palavras_por_grupo:3 }, final:{ ativo:true, chamada:'SIGA PARA MAIS DICAS', slogan:'Te levamos ao próximo nível. Do iniciante ao atleta.' }, midia:m };
  if (item.id && BIB[item.id] && BIB[item.id].rascunho){ var cp = Object.assign({}, BIB[item.id], { rascunho:false }); guardaNaBib(cp); }
  abrir(id).then(function(){ salvar(); estado('Gravação aberta para edição', 'ok'); });
}

// ---------- teclado e transporte ----------
$('b-play').onclick = function(){ tocando ? pausar() : tocar(); };
$('b-ini').onclick = function(){ pausar(); irPara(0); };
$('b-q-').onclick = function(){ pausar(); irPara(T - 1 / FPS); };
$('b-q+').onclick = function(){ pausar(); irPara(T + 1 / FPS); };
document.addEventListener('keydown', function(e){
  if (!P || !$('modal').hidden || EST) return;
  var emCampo = /INPUT|TEXTAREA|SELECT/.test(e.target.tagName) && e.target.type !== 'range' && e.target.type !== 'checkbox';
  var mod = e.ctrlKey || e.metaKey, tecla = (e.key || '').toLowerCase();
  if (mod && (tecla === 'z' || tecla === 'y')){ if (emCampo) return; e.preventDefault(); if (tecla === 'y' || e.shiftKey) refazer(); else desfazer(); return; }
  if (emCampo) return;
  if (mod && tecla === 'c'){ e.preventDefault(); copiar(false); return; }
  if (mod && tecla === 'x'){ e.preventDefault(); copiar(true); return; }
  if (mod && tecla === 'v'){ e.preventDefault(); colar(e.shiftKey ? 'cursor' : 'depois'); return; }
  if (mod && tecla === 'd'){ e.preventDefault(); duplicar(); return; }
  if (e.altKey && selEl && /^Arrow/.test(e.key)){ e.preventDefault(); var o = alvo(selEl), id = selEl.split(':')[0], dx = e.key === 'ArrowLeft' ? -2 : e.key === 'ArrowRight' ? 2 : 0, dy = e.key === 'ArrowUp' ? -2 : e.key === 'ArrowDown' ? 2 : 0;
    if (!o) return;
    if (id === 'janela') lay('janela').y += dy; else if (/legenda|gancho_grande|notas/.test(id)){ o.dx += dx; o.dy += dy; } else { o.x += dx; o.y += dy; }
    pinta(); agendaSalvar('Mover ' + (NOMES[id] || id).toLowerCase()); return; }
  if (e.code === 'Space'){ e.preventDefault(); tocando ? pausar() : tocar(); }
  else if (e.key === 'ArrowLeft'){ pausar(); irPara(T - (e.shiftKey ? 1 : 1 / FPS)); }
  else if (e.key === 'ArrowRight'){ pausar(); irPara(T + (e.shiftKey ? 1 : 1 / FPS)); }
  else if (tecla === 's' && !mod) dividir();
  else if (e.key === 'Escape'){ selEl = null; seleciona(); }
  else if (e.key === 'Delete' || e.key === 'Backspace') apagarSel();
});

// ---------- carregar ----------
function pronto(){
  nomesSelect(); var ids = Object.keys(projetos);
  if (!ids.length){ var v = $('vazio'); v.hidden = false; v.innerHTML = ''; v.appendChild(el('div', 'grupo', [el('b', null, 'Nenhum projeto ainda.'), el('div', 'linha', [botao('Novo com vídeo', function(){ $('b-novo').click(); }), botao('Trazer os cortes da entrevista na RIC', importarModelo)]), el('p', 'nota-ajuda', 'Os projetos que você editou na versão antiga do Intus Cut (a página do claude.ai) não vêm sozinhos: use "Trazer os cortes da entrevista na RIC" no computador, com o vídeo da entrevista, e eles voltam com as suas edições.'), el('div', 'linha', [botao('🎬 Meus vídeos', abrirPainelVideos)])])); estado('Sem projetos'); return; }
  var ult = null; try { ult = localStorage.getItem('intuscut-ultimo'); } catch (e) {}
  if (!P) abrir(projetos[ult] ? ult : $('sel-proj').value || ids[0]);
}
if (innerWidth <= 820) $('app').classList.add('sem-painel');
marcaIG(); marcaDesfazer(); marcaAba();
ctx.fillStyle = '#0f0f0f'; ctx.fillRect(0, 0, W, H);
LOGO.onload = function(){ if (P) pinta(); };
document.fonts && document.fonts.ready.then(function(){ if (P) pinta(); });
$('b-rec').onclick = function(){ if (!P){ estado('Abra um projeto primeiro', 'erro'); return; } abrirEstudio(); };
window.addEventListener('beforeunload', function(e){ if (salvando || salvarTimer && $('estado').textContent !== 'Salvo'){ salvar(); } if (EST && EST.rec){ e.preventDefault(); e.returnValue = ''; } });
(async function(){
  if (!TOKEN){ semAcesso(401); return; }
  try {
    var r = await api('inicio'); EU = r.eu; LIMITES = r.limites || {};
    (r.projetos || []).forEach(function(p){ projetos[p.id] = { id:p.id, nome:p.nome, formato:p.formato, atualizado_em:p.atualizado_em, _parcial:true }; });
    BIB = {}; (r.biblioteca || []).forEach(function(x){ BIB[x.id] = x; });
    if (!r.drive) estado('O Drive não está configurado: dá para editar, mas não enviar vídeos', 'erro');
    pronto(); if (r.drive) estado(EU && EU.nome ? 'Olá, ' + EU.nome.split(' ')[0] : 'Pronto', 'ok');
  } catch (e){ if (e.code !== 'sem_acesso') { estado('Sem conexão com o servidor', 'erro'); var v = $('vazio'); v.hidden = false; v.textContent = 'Não consegui falar com o servidor da Intus. Confira a internet e recarregue a página.'; } }
})();
})();
