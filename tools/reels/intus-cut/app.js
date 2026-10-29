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
var COR_FAIXA = { texto:'#2d6f8c', imagem:'#8a5a2b', sombra:'#4c525c' };
var NOMES = { janela:'Vídeo', legenda:'Legenda', gancho:'Título do topo', gancho_grande:'Gancho grande', arrobas:'@ do rodapé', logo:'Logo', notas:'Marcação', barra:'Barra de progresso', texto:'Texto', sombra:'Sombra', imagem:'Imagem', geral:'Imagem e fundo', final:'Cartão final' };
var BIB_FIXOS = [
  { id:'fixo-1', tipo:'logo', nome:'Intus, branca', url:'lib/logo-intus-dark.png', fixo:true },
  { id:'fixo-2', tipo:'logo', nome:'Intus, branca grande', url:'lib/logo-intus-branco.png', fixo:true },
  { id:'fixo-3', tipo:'logo', nome:'Intus, escura', url:'lib/logo-intus-light.png', fixo:true },
  { id:'fixo-4', tipo:'logo', nome:'Ícone claro', url:'lib/logo-icon-light.png', fixo:true },
  { id:'fixo-5', tipo:'logo', nome:'Ícone escuro', url:'lib/logo-icon-dark.png', fixo:true }
];
var LOGO = new Image(); LOGO.src = 'data:image/png;base64,__LOGO__';
var $ = function(id){ return document.getElementById(id); };
var tela = $('tela'), ctx = tela.getContext('2d'), guia = $('guia'), gctx = guia.getContext('2d');

// ---------- estado ----------
var db = null, assets = null, downloads = null, sampleNs = null;
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
    legenda: { visivel:true, dx:0, dy:vert ? 0 : -50, borda:7, fundo:false },
    gancho:  { visivel:true, x:W / 2, y:vert ? 118 : Math.round(H * .1), escala:vert ? 1 : .9, cor:'#7fff00' },
    gancho_grande: { visivel:true, dx:0, dy:vert ? 0 : Math.round(H * .42 - 330), escala:vert ? 1 : .9, escurecer:.47 },
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
  }
  return MID[k];
}
function pausaTudo(exceto){ Object.keys(MID).forEach(function(k){ var x = MID[k]; if (x === exceto) return; x.v.pause(); x.av.pause(); x.am.pause(); }); }
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
    if (g.length && (g.length >= max || (g.concat([w]).map(function(x){ return x.p; }).join(' ').length > 18) || w.ri - g[g.length - 1].rf > .35)){ gs.push(g); g = []; }
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
function fonteCss(tam, peso){ return (peso || 800) + ' ' + Math.round(tam) + 'px Inter, system-ui, sans-serif'; }
function texto(c, t, x, y, tam, peso, cr, borda, alin){
  c.font = fonteCss(tam, peso); c.textAlign = alin || 'left'; c.textBaseline = 'top';
  if (borda){ c.lineJoin = 'round'; c.lineWidth = borda * 2; c.strokeStyle = '#000'; c.strokeText(t, x, y); }
  c.fillStyle = cr; c.fillText(t, x, y); return c.measureText(t).width;
}
function quebra(c, txt, tam, larg){ c.font = fonteCss(tam); var ls = [], at = '';
  txt.split(/\s+/).forEach(function(p){ var t = (at + ' ' + p).trim(); if (c.measureText(t).width > larg && at){ ls.push(at); at = p; } else at = t; });
  return ls.concat([at]); }
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
  if (Ar.visivel && Ar.texto){ var tam = 36 * Ar.escala, w = texto(c, Ar.texto, Ar.x, Ar.y, tam, 700, Ar.cor, 0, 'center'); caixa(reg, 'arrobas', Ar.x - w / 2, Ar.y, w, tam * 1.2); }
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
  var g = grande ? lay('gancho_grande') : lay('gancho'), e = g.escala;
  var tam = (grande ? 92 : 58) * e, alt = (grande ? 104 : 68) * e, dest = (P.destaque || []).map(function(x){ return x.toLowerCase(); });
  var ls = quebra(c, (P.gancho || '').toUpperCase(), tam, W - 120), corD = lay('gancho').cor;
  var cxm = grande ? W / 2 + g.dx : g.x, y = grande ? J.y + 330 + g.dy - ls.length * alt / 2 : g.y - ls.length * alt / 2, y0 = y, wmax = 0;
  ls.forEach(function(ln){
    c.font = fonteCss(tam); var lw = c.measureText(ln).width, x = cxm - lw / 2; wmax = Math.max(wmax, lw);
    ln.split(' ').forEach(function(p){ var limpa = p.replace(/[.,?!:]/g, '').toLowerCase();
      texto(c, p, x, y, tam, 800, dest.indexOf(limpa) >= 0 || dest.indexOf(p.toLowerCase()) >= 0 ? corD : COR.branco, (grande ? 7 : 5) * e);
      x += c.measureText(p + ' ').width; });
    y += alt;
  });
  caixa(reg, grande ? 'gancho_grande' : 'gancho', cxm - wmax / 2, y0, wmax, y - y0);
}
function desenhaNotas(c, f, y, reg){
  var N = lay('notas'), tam = 46 * N.escala;
  (fonte(f.de).notas || []).forEach(function(n){ if (f.ts < n.ini || f.ts > n.fim) return;
    c.font = fonteCss(tam); var l = c.measureText(n.texto).width, x0 = W / 2 + N.dx - l / 2 - 28 * N.escala, yy = y + N.dy;
    pill(c, x0, yy, l + 56 * N.escala, 76 * N.escala, 38 * N.escala, '#0f0f0f');
    texto(c, n.texto, W / 2 + N.dx, yy + 14 * N.escala, tam, 800, cor(n.cor), 0, 'center');
    caixa(reg, 'notas', x0, yy, l + 56 * N.escala, 76 * N.escala); });
}
function desenhaLegenda(c, t, y, reg){
  var gs = GRUPOS || [], gi = -1;
  for (var k = 0; k < gs.length; k++){ var g = gs[k], fim = k + 1 < gs.length ? gs[k + 1][0].ri - .05 : g[g.length - 1].rf + .4; if (t >= g[0].ri - .05 && t <= fim) gi = k; }
  if (gi < 0) return;
  var est = P.estilo || {}, L = lay('legenda'), cores = (est.cores && est.cores.length ? est.cores : ['amarelo', 'verde']), tam = est.tamanho || 84, tg = Math.round(tam * (est.aumento || 1.1));
  var g = gs[gi], cr = cor(cores[gi % cores.length]), maius = est.maiusculas !== false, base = est.cor_base || COR.branco;
  var ps = g.map(function(w){ return maius ? w.p.toUpperCase() : w.p; }), ativa = 0;
  g.forEach(function(w, i){ if (w.ri <= t + .03) ativa = i; });
  function larg(idx){ var s = 0; idx.forEach(function(i, n){ c.font = fonteCss(i === ativa ? tg : tam); s += c.measureText(ps[i]).width + (n ? c.measureText(' ').width : 0); }); return s; }
  var todas = ps.map(function(_, i){ return i; }), linhas = larg(todas) > W - 80 ? [todas.slice(0, Math.max(1, ps.length >> 1)), todas.slice(Math.max(1, ps.length >> 1))] : [todas];
  var y0 = y, xmin = W, xmax = 0, lh = Math.round(tam * 1.31), cxm = W / 2 + L.dx;
  if (L.fundo){ var wl = Math.max.apply(null, linhas.map(larg)); pill(c, cxm - wl / 2 - 26, y - 14, wl + 52, linhas.length * lh + 14, 22, 'rgba(0,0,0,.72)'); }
  linhas.forEach(function(ln){
    var lw = larg(ln), x = cxm - lw / 2; xmin = Math.min(xmin, x); xmax = Math.max(xmax, x + lw);
    ln.forEach(function(i){ var tt = i === ativa ? tg : tam;
      texto(c, ps[i], x, y - (i === ativa ? 6 : 0), tt, 800, i === ativa ? cr : base, L.borda);
      c.font = fonteCss(tt); x += c.measureText(ps[i] + ' ').width; });
    y += lh;
  });
  caixa(reg, 'legenda', xmin, y0 - 8, xmax - xmin, y - y0);
}
function desenhaTextos(c, t, reg){
  (P.textos || []).forEach(function(x, i){ if (!noTempo(x, t) || !x.texto) return;
    var ls = String(x.texto).split('\n'), tam = x.tam || 60, lh = tam * 1.2, wmax = 0;
    c.font = fonteCss(tam, x.peso || 800); ls.forEach(function(l){ wmax = Math.max(wmax, c.measureText(l).width); });
    var y0 = x.y - ls.length * lh / 2;
    if (x.fundo) pill(c, x.x - wmax / 2 - tam * .4, y0 - tam * .2, wmax + tam * .8, ls.length * lh + tam * .3, tam * .35, 'rgba(15,15,15,.88)');
    ls.forEach(function(l, n){ texto(c, l, x.x, y0 + n * lh, tam, x.peso || 800, x.cor || COR.branco, x.borda ? 5 : 0, 'center'); });
    caixa(reg, 'texto:' + i, x.x - wmax / 2, y0, wmax, ls.length * lh); });
}
function desenhaFim(c, t, reg){
  c.globalAlpha = Math.min(1, t / .35);
  var vert = H / W > 1.5, e = vert ? 1 : Math.min(W, H) / 1920 * 1.6, cy = vert ? 0 : (H - 1920 * e) / 2, lo = logoAtual();
  if (lo.complete && lo.naturalWidth){ var lw = 520 * e, lh = lw * lo.naturalHeight / lo.naturalWidth; c.drawImage(lo, (W - lw) / 2, cy + 560 * e, lw, lh); }
  var fi = P.final || {};
  texto(c, fi.chamada || 'SIGA PARA MAIS DICAS', W / 2, cy + 790 * e, 64 * e, 800, COR.branco, 0, 'center');
  texto(c, fi.linha1 || '@luiznunest', W / 2, cy + 900 * e, 96 * e, 800, COR.branco, 0, 'center');
  texto(c, fi.linha2 || '@intusfit', W / 2, cy + 1020 * e, 96 * e, 800, COR.verde, 0, 'center');
  String(fi.slogan || '').split(/(?<=\.)\s+/).forEach(function(l, i){ texto(c, l, W / 2, cy + (1180 + i * 65) * e, 46 * e, 700, '#d7d7d7', 0, 'center'); });
  c.globalAlpha = 1; caixa(reg, 'final', 40, cy + 540 * e, W - 80, 820 * e);
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
  if (id === 'janela'){ var f = fAgora(); return f ? (f.s.recorte || plano(f.ts, f.de)) : null; }
  return lay(id);
}
var arr = null, ultToque = { t:0, id:'' };
guia.addEventListener('pointerdown', function(e){
  if (!P) return; var p = ponto(e), b = acha(p);
  var agora = Date.now(); if (b && b.id === ultToque.id && temTexto(b.id) && agora - ultToque.t < 450){ ultToque = { t:0, id:'' }; editarTextoDe(b.id); return; } ultToque = { t:agora, id:b ? b.id : '' }; selEl = b ? b.id : null; pintaGuia();
  if (!b){ inspetor(); desenhaTimeline(); return; }
  guia.setPointerCapture(e.pointerId); var o = alvo(b.id), f = fAgora();
  arr = { id:b.id, p:p, o:o, ini:clone(o), jy:lay('janela').y, shift:e.shiftKey, mexeu:false, livre:!!(f && f.s.recorte), geo:f ? geometria(f) : null, de:f ? f.de : '', redim:noCanto(p, b) };
  seleciona();
});
guia.addEventListener('pointermove', function(e){
  if (!arr){ if (P){ var pp = ponto(e), b = acha(pp); guia.style.cursor = b ? (selEl === b.id && noCanto(pp, b) ? 'nwse-resize' : 'move') : 'default'; } return; }
  if (!arr.o) return; var p = ponto(e), dx = Math.round(p.x - arr.p.x), dy = Math.round(p.y - arr.p.y), o = arr.o, i = arr.ini;
  if (Math.abs(dx) + Math.abs(dy) > 2) arr.mexeu = true;
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
    case 'legenda': case 'gancho_grande': case 'notas': o.dx = i.dx + dx; o.dy = i.dy + dy; break;
    case 'final': break;
    default: o.x = i.x + dx; o.y = i.y + dy;
  }
  pinta();
});
guia.addEventListener('pointerup', function(){ if (arr && arr.mexeu){ var nm = NOMES[arr.id.split(':')[0]];
  agendaSalvar(arr.redim ? 'Redimensionar ' + nm.toLowerCase() : (arr.id === 'janela' && !arr.shift ? 'Enquadrar ' : 'Mover ') + nm.toLowerCase()); inspetor(); } arr = null; });
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
  pinta();
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
  pinta(); requestAnimationFrame(passo);
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
function faixas(){ return [['texto', 'Texto', P.textos || []], ['imagem', 'Imagem', P.imagens || []], ['sombra', 'Sombra', P.sombras || []]]; }
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
  if (P.final && P.final.ativo){ var cfim = el('div', 'clip fim'); cfim.style.left = (durFala() * p) + 'px'; cfim.style.width = (T_FIM * p) + 'px'; cfim.appendChild(el('span', 'rot', 'Cartão final')); fv.appendChild(cfim); }
  var mais = el('button', 'mais', '+'); mais.type = 'button'; mais.title = 'Adicionar vídeo, imagem ou logo no fim'; mais.setAttribute('aria-label', 'Adicionar no fim da linha do tempo');
  mais.style.left = (d * p + 8) + 'px'; mais.onclick = function(ev){ ev.stopPropagation(); abrirAdicionar(); }; fv.appendChild(mais);
  int.appendChild(fv); rotulo('Vídeo', 54, '#4f7a24');
  faixas().forEach(function(fx){
    var tipo = fx[0], itens = fx[2], fins = [], vias = itens.map(function(x){ var it = itemTempo(x), v = 0; while (fins[v] != null && fins[v] > it.ini + .01) v++; fins[v] = it.fim; return v; });
    var n = Math.max(1, fins.length), h = 8 + n * 24, f = el('div', 'faixa'); f.style.height = h + 'px'; f.style.width = larg + 'px';
    itens.forEach(function(x, i){ var it = itemTempo(x), c = el('div', 'clip' + (selEl === tipo + ':' + i ? ' sel' : ''));
      c.dataset.k = tipo; c.dataset.i = i; c.style.top = (4 + vias[i] * 24) + 'px'; c.style.bottom = 'auto'; c.style.height = '20px';
      c.style.left = (it.ini * p) + 'px'; c.style.width = Math.max(6, (it.fim - it.ini) * p) + 'px'; c.style.background = rgba(COR_FAIXA[tipo], .6); c.style.borderColor = COR_FAIXA[tipo];
      var r = el('span', 'rot', tipo === 'texto' ? String(x.texto || '').split('\n')[0] : tipo === 'imagem' ? (x.nome || 'Imagem') : ({ vinheta:'Vinheta', gradiente:'Sombra', retangulo:'Faixa escura' })[x.tipo] || 'Sombra');
      r.style.top = '2px'; c.appendChild(r); f.appendChild(c); });
    int.appendChild(f); rotulo(fx[1], h, COR_FAIXA[tipo]);
  });
  var fl = el('div', 'faixa'); fl.style.height = '30px'; fl.style.width = larg + 'px';
  GRUPOS.forEach(function(g){ var a = g[0].ri, z = g[g.length - 1].rf, c = el('div', 'clip leg'); c.style.left = (a * p) + 'px'; c.style.width = Math.max(4, (z - a) * p) + 'px'; c.style.top = '5px'; c.style.bottom = '5px';
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
  if (!tl){ if (P){ var c = e.target.closest('.clip'), cur = 'pointer'; if (c && c.dataset.k){ var r = c.getBoundingClientRect(); cur = (e.clientX - r.left <= 8 || r.right - e.clientX <= 8) ? 'ew-resize' : 'grab'; } $('tlint').style.cursor = cur; } return; }
  var int = $('tlint');
  if (tl.modo === 'scrub'){ if (e.buttons) scrub((e.clientX - int.getBoundingClientRect().left) / pps()); return; }
  var dx = e.clientX - tl.x0, dt = dx / tl.p;
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
    if (tl.modo === 'ini'){ o.ini = +cl(a.ini + dt, 0, a.fim - .2).toFixed(2); o.fim = a.fim; }
    else if (tl.modo === 'fim'){ o.ini = a.ini; o.fim = +cl(a.fim + dt, a.ini + .2, D).toFixed(2); }
    else { var Ld = a.fim - a.ini, ni = cl(a.ini + dt, 0, Math.max(0, D - Ld)); o.ini = +ni.toFixed(2); o.fim = +(ni + Ld).toFixed(2); }
    desenhaTimeline(); irPara(cl(tl.modo === 'fim' ? o.fim - .05 : o.ini + .01, 0, D));
  }
});
$('tlint').addEventListener('pointerup', function(){
  if (!tl) return; var t0 = tl; tl = null;
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
function apagarSel(){ if (selEl && /^(texto|sombra|imagem):/.test(selEl)){ var t = selEl.split(':'), lista = t[0] === 'texto' ? P.textos : t[0] === 'sombra' ? P.sombras : P.imagens;
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
  if (selEl && /^(texto|sombra|imagem):/.test(selEl)){ var tp = selEl.split(':')[0], it = alvo(selEl); if (!it) return;
    clipSalva({ tipo:tp, item:clone(it) }); estado(NOMES[tp] + ' copiado', 'ok'); if (cortar) apagarSel(); return; }
  var s = trechos()[selTrecho]; if (!s) return; var key = s.de || '';
  clipSalva({ tipo:'trecho', item:clone(s), proj:P.id, key:key, fonte:key ? clone(P.fontes[key]) : snapshot(P), nome:nomeFonte(key) });
  estado('Trecho ' + (selTrecho + 1) + ' copiado', 'ok'); if (cortar) remover(selTrecho); else mostraAba();
}
function posCursor(){ var f = fonteEm(T); if (!f) return trechos().length; var m = mapa()[f.i];
  if (f.ts - m.ini < .15) return f.i; if (m.fim - f.ts < .15) return f.i + 1; dividir(); return f.i + 1; }
function colar(onde){
  var c = clipLe(); if (!c){ estado('Nada copiado ainda'); return; }
  if (c.tipo !== 'trecho'){
    var it = clone(c.item), nome = c.tipo === 'texto' ? 'textos' : c.tipo === 'sombra' ? 'sombras' : 'imagens', lista = P[nome] = P[nome] || [];
    if (!it.sempre){ var dd = (it.fim || 0) - (it.ini || 0); it.ini = +T.toFixed(2); it.fim = +(T + Math.max(.5, dd || 3)).toFixed(2); }
    it.x += 30; it.y += 30; lista.push(it); selEl = c.tipo + ':' + (lista.length - 1); atualizaTudo(); agendaSalvar('Colar ' + NOMES[c.tipo].toLowerCase()); seleciona(); return; }
  var s = clone(c.item), key = garantirFonte(c.proj, c.key, c.fonte); delete s.de; if (key) s.de = key;
  var pos = onde === 'cursor' ? posCursor() : onde === 'inicio' ? 0 : onde === 'fim' ? trechos().length : Math.min(selTrecho + 1, trechos().length);
  trechos().splice(pos, 0, s); selTrecho = pos; selEl = null; mudou('Colar trecho'); irPara(mapa()[pos].o); mostraAba();
}
function duplicar(){
  if (selEl && /^(texto|sombra|imagem):/.test(selEl)){ copiar(false); colar(); return; }
  var s = trechos()[selTrecho]; if (!s) return; trechos().splice(selTrecho + 1, 0, clone(s)); selTrecho++; mudou('Duplicar trecho'); irPara(mapa()[selTrecho].o); mostraAba(); }
function inserirTrechos(novos, onde, rot){
  if (!novos.length) return; var pos = onde === 'cursor' ? posCursor() : onde === 'inicio' ? 0 : trechos().length;
  Array.prototype.splice.apply(trechos(), [pos, 0].concat(novos)); selTrecho = pos; selEl = null; mudou(rot); irPara(mapa()[pos].o); mostraAba();
}
function adicionarProjeto(id, onde){
  var X = projetos[id]; if (!X) return;
  var novos = (X.trechos || []).map(function(t){ var n = clone(t), dados = t.de ? (X.fontes || {})[t.de] : snapshot(X); delete n.de;
    var k = garantirFonte(X.id, t.de || '', dados); if (k) n.de = k; return n; });
  inserirTrechos(novos, onde, 'Adicionar ' + (X.nome || X.id));
}
function fonteDeVideo(chave, nome, url, d, w, h){ P.fontes = P.fontes || {};
  if (!P.fontes[chave]) P.fontes[chave] = { nome:nome, midia:{ video:url, voz:url, musica:'', duracao:d, alt:w && h ? ORIG_W * h / w : 1080 }, palavras:[], planos:[{ ini:0, fim:d + 1, cx:960, larg:ORIG_W, y:0, tarja:true }], notas:[] };
  return chave; }
function inserirVideoBib(item, onde){
  var k = fonteDeVideo('bib-' + item.id, item.nome, item.url, item.dur || 5, item.w, item.h);
  inserirTrechos([{ ini:0, fim:+(item.dur || 5).toFixed(2), de:k, cheia:true, limpo:true }], onde, 'Inserir ' + item.nome);
}
function painelMidia(C){
  var ts = trechos(), c = clipLe();
  var onde = sel([['fim', 'no final'], ['inicio', 'no início'], ['cursor', 'no cursor']]);
  var proj = sel(Object.keys(projetos).sort().map(function(id){ return [id, (projetos[id].nome || id) + (id === P.id ? ' (este)' : '')]; }));
  var nv = el('button', 'btn pri', '＋ Nova edição com um vídeo seu'); nv.type = 'button'; nv.onclick = function(){ $('b-novo').click(); };
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Começar uma edição nova'), nv, el('p', 'nota-ajuda', 'Escolha um vídeo do celular ou do computador. Ele vira um projeto novo e você decide se edita à mão ou pede a edição à IA.')]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Juntar vídeos (montagem)'), el('div', 'linha', [campo('Projeto', proj), campo('Onde', onde)]),
    el('div', 'linha', [botao('Adicionar este projeto', function(){ adicionarProjeto(proj.value, onde.value); }), botao('Enviar vídeo (até 20 MB)', function(){ enviarVideoMontagem(onde.value); })]),
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
function enviarVideoMontagem(onde){
  if (!assets){ dialogo('Envio de vídeo indisponível', 'Só quem edita esta página pode enviar vídeos.', [['Ok', fecha]]); return; }
  escolherArquivo('video/mp4,video/quicktime,video/webm', function(r, nome, info){
    var k = fonteDeVideo('up-' + r.id.slice(0, 10), nome, r.url, info.dur, info.w, info.h);
    inserirTrechos([{ ini:0, fim:+info.dur.toFixed(2), de:k }], onde, 'Adicionar vídeo ' + nome); });
}

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
  legenda: function(){ var Lg = lay('legenda'); return cartao('legenda', 'Legenda', [chk(Lg, 'visivel', 'Mostrar legenda', 'Legenda'), el('div', 'grade2', [deslize(Lg, 'dx', 'Horizontal', -W / 2, W / 2, 2, 'Mover legenda'), deslize(Lg, 'dy', 'Vertical', -H, 500, 2, 'Mover legenda'),
    deslize(Lg, 'borda', 'Contorno', 0, 14, 1, 'Contorno da legenda')]), chk(Lg, 'fundo', 'Caixa escura atrás', 'Caixa da legenda'), el('div', 'linha', [botao('✏️ Editar esta frase', function(){ editarFraseEm(T); }), botao('Todo o texto da legenda', function(){ aba = 'legenda'; abrePainel(); })]), el('p', 'nota-ajuda', 'Tamanho e cores: painel Legendas.')]); },
  gancho: function(){ var Gc = lay('gancho'); return cartao('gancho', 'Título do topo (depois do gancho)', camposGancho(false).concat([chk(Gc, 'visivel', 'Mostrar', 'Título do topo'), el('div', 'grade2', [deslize(Gc, 'x', 'Horizontal', 0, W, 2, 'Mover título'), deslize(Gc, 'y', 'Vertical', 0, H, 2, 'Mover título'),
    deslize(Gc, 'escala', 'Tamanho', .4, 2.5, .02, 'Tamanho do título', pct), corIn(Gc, 'cor', 'Cor do destaque', 'Cor do destaque')])])); },
  gancho_grande: function(){ var GG = lay('gancho_grande'); return cartao('gancho_grande', 'Gancho grande (primeiros segundos)', camposGancho(true).concat([chk(GG, 'visivel', 'Mostrar', 'Gancho grande'), el('div', 'grade2', [deslize(GG, 'dx', 'Horizontal', -W / 2, W / 2, 2, 'Mover gancho'), deslize(GG, 'dy', 'Vertical', -800, H, 2, 'Mover gancho'),
    deslize(GG, 'escala', 'Tamanho', .4, 2, .02, 'Tamanho do gancho', pct), deslize(GG, 'escurecer', 'Escurecer o vídeo', 0, .9, .01, 'Escurecer gancho', pct)])])); },
  arrobas: function(){ var A = lay('arrobas'), at = el('input'); at.type = 'text'; at.value = A.texto; at.oninput = function(){ A.texto = at.value; pinta(); agendaSalvar('Texto do @'); };
    return cartao('arrobas', '@ do rodapé', [chk(A, 'visivel', 'Mostrar', '@ do rodapé'), campo('Texto', at), el('div', 'grade2', [deslize(A, 'x', 'Horizontal', 0, W, 2, 'Mover @'), deslize(A, 'y', 'Vertical', 0, H, 2, 'Mover @'),
      deslize(A, 'escala', 'Tamanho', .5, 3, .02, 'Tamanho do @', pct), corIn(A, 'cor', 'Cor', 'Cor do @')]), rodapeBotoes()]); },
  logo: function(){ var LG = lay('logo'); return cartao('logo', 'Logo do rodapé', [chk(LG, 'visivel', 'Mostrar', 'Logo'), el('div', 'grade2', [deslize(LG, 'x', 'Horizontal', 0, W, 2, 'Mover logo'), deslize(LG, 'y', 'Vertical', 0, H - 20, 2, 'Mover logo'),
    deslize(LG, 'largura', 'Largura (px)', 60, 900, 2, 'Tamanho da logo')]), el('p', 'nota-ajuda', 'Para trocar a logo: Biblioteca, "Usar no rodapé".'), rodapeBotoes()]); },
  notas: function(){ var N = lay('notas'); return cartao('notas', 'Marcações (CERTO, ERRADO...)', camposNotasAgora().concat([el('div', 'grade2', [deslize(N, 'dx', 'Horizontal', -W / 2, W / 2, 2, 'Mover marcações'), deslize(N, 'dy', 'Vertical', -400, H, 2, 'Mover marcações'),
    deslize(N, 'escala', 'Tamanho', .4, 2.5, .02, 'Tamanho das marcações', pct)])])); },
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
    return campo('Texto da marcação', tx); });
  if (!agora.length) out.push(el('p', 'nota-ajuda', 'Nenhuma marcação neste ponto. Crie no painel Textos.'));
  return out;
}
function camposFinal(){
  var fi = P.final = P.final || { ativo:true };
  function tin(k, rot, pad){ var i = el('input'); i.type = 'text'; i.value = fi[k] || ''; i.placeholder = pad || ''; i.oninput = function(){ fi[k] = i.value; pinta(); agendaSalvar('Cartão final'); }; return campo(rot, i); }
  return [chk(fi, 'ativo', 'Mostrar o cartão final (2,8 s)', 'Cartão final', function(){ setTimeout(atualizaTudo); }), tin('chamada', 'Chamada', 'SIGA PARA MAIS DICAS'),
    el('div', 'grade2', [tin('linha1', 'Primeiro @', '@luiznunest'), tin('linha2', 'Segundo @', '@intusfit')]), tin('slogan', 'Slogan')];
}
function temTexto(id){ return /^(legenda|gancho|gancho_grande|arrobas|notas|final|texto:\d+)$/.test(id || ''); }
// Toque duplo num texto da prévia ou da linha do tempo: abre o texto numa janela, em qualquer aparelho.
function editarTextoDe(id){
  if (!P || !id) return; pausar();
  if (id === 'legenda'){ editarFraseEm(T); return; }
  var t = id.split(':'), box = el('div', 'grupo'), salvarFn = null, titulo = 'Editar texto';
  if (t[0] === 'texto' && P.textos[+t[1]]){ var x = P.textos[+t[1]], tx = el('textarea'); tx.rows = 3; tx.value = x.texto; box.appendChild(tx); titulo = 'Editar texto';
    salvarFn = function(){ x.texto = tx.value; }; setTimeout(function(){ tx.focus(); tx.select(); }, 50); }
  else if (id === 'gancho' || id === 'gancho_grande'){ titulo = 'Editar o gancho'; camposGancho(id === 'gancho_grande').forEach(function(n){ box.appendChild(n); });
    box.appendChild(el('p', 'nota-ajuda', 'O mesmo texto aparece grande nos primeiros segundos e depois como título do topo.')); setTimeout(function(){ var a = box.querySelector('textarea'); if (a){ a.focus(); a.select(); } }, 50); }
  else if (id === 'arrobas'){ var A = lay('arrobas'), at = el('input'); at.type = 'text'; at.value = A.texto; box.appendChild(campo('Texto do rodapé', at)); titulo = 'Editar o @ do rodapé';
    salvarFn = function(){ A.texto = at.value; }; setTimeout(function(){ at.focus(); at.select(); }, 50); }
  else if (id === 'notas'){ titulo = 'Editar marcação'; camposNotasAgora().forEach(function(n){ box.appendChild(n); }); }
  else if (id === 'final'){ titulo = 'Editar o cartão final'; camposFinal().forEach(function(n){ box.appendChild(n); }); }
  else return;
  dialogo(titulo, '', [['Pronto', function(){ if (salvarFn){ salvarFn(); agendaSalvar(titulo); } fecha(); atualizaTudo(); inspetor(); if (aba === 'textos') mostraAba(); }]], box);
}
function rodapeBotoes(){ var A = lay('arrobas'), LG = lay('logo'); return el('div', 'linha', [
  botao('▲ Rodapé', function(){ A.y -= 20; LG.y -= 20; pinta(); agendaSalvar('Subir rodapé'); inspetor(); }), botao('▼ Rodapé', function(){ A.y += 20; LG.y += 20; pinta(); agendaSalvar('Descer rodapé'); inspetor(); }),
  formato().guia === 'reel' ? botao('Tirar da área do Instagram', function(){ A.y = 1420; LG.y = 1490; pinta(); agendaSalvar('Rodapé acima do Instagram'); inspetor(); }) : el('span')]); }
function cardTexto(i){ var x = P.textos[i], id = 'texto:' + i, tx = el('textarea'); tx.rows = 2; tx.value = x.texto; tx.oninput = function(){ x.texto = tx.value; pinta(); desenhaTimeline(); agendaSalvar('Texto livre'); };
  return el('div', 'cartao' + (selEl === id ? ' sel' : ''), [el('div', 'topo', [el('b', null, 'Texto ' + (i + 1)), acoesItem(id)]), tx,
    el('div', 'grade2', [deslize(x, 'tam', 'Tamanho', 20, 220, 1, 'Tamanho do texto'), deslize(x, 'x', 'Horizontal', 0, W, 2, 'Mover texto'), deslize(x, 'y', 'Vertical', 0, H, 2, 'Mover texto')]),
    el('div', 'linha', [corIn(x, 'cor', 'Cor', 'Cor do texto'), chk(x, 'fundo', 'Caixa escura', 'Caixa do texto'), chk(x, 'borda', 'Contorno', 'Contorno do texto')])].concat(tempoItem(x, 'Texto'))); }
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
  var porG = sel([1, 2, 3, 4, 5].map(function(n){ return [n, n + (n > 1 ? ' palavras' : ' palavra')]; }), est.palavras_por_grupo || 3); porG.onchange = function(){ est.palavras_por_grupo = +porG.value; mudou('Palavras por vez'); };
  if (est.aumento == null) est.aumento = 1.1; if (est.tamanho == null) est.tamanho = 84; if (est.maiusculas == null) est.maiusculas = true;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Estilo'), el('div', 'linha', [campo('Palavra falada', alt), el('label', 'chk', [c1, ' cor 1']), el('label', 'chk', [c2, ' cor 2']), el('label', 'chk', [cb, ' demais'])]),
    el('div', 'linha', [campo('Por vez na tela', porG), chk(est, 'maiusculas', 'Maiúsculas', 'Maiúsculas', function(){ setTimeout(atualizaTudo); })]),
    el('div', 'grade2', [deslize(est, 'tamanho', 'Tamanho', 40, 140, 2, 'Tamanho da legenda'), deslize(est, 'aumento', 'Destaque da palavra', 1, 1.5, .01, 'Destaque da palavra', pct)])]));
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
function editarFraseEm(t){
  var f = fonteEm(t == null ? T : t); if (!f){ estado('Nenhuma legenda neste ponto', 'erro'); return; }
  var fr = frasesDe(f.de), q = null; fr.forEach(function(x){ if (!q && f.ts >= x.ini - .05 && f.ts <= x.fim + .3) q = x; });
  if (!q){ var melhor = null; fr.forEach(function(x){ var dd = Math.min(Math.abs(x.ini - f.ts), Math.abs(x.fim - f.ts)); if (dd < 1.5 && (!melhor || dd < melhor.d)) melhor = { q:x, d:dd }; }); q = melhor && melhor.q; }
  pausar(); var tx = el('textarea'); tx.rows = 3; tx.style.width = '100%';
  if (q) tx.value = q.texto; else tx.placeholder = 'Não há legenda aqui. Escreva para criar uma frase de 2,5 s neste ponto.';
  dialogo(q ? 'Editar a legenda' : 'Nova legenda neste ponto', 'Deixe vazio para apagar a frase.', [['Cancelar', fecha], ['Salvar', function(){
    if (q) reescreveFrase(f.de, q, tx.value);
    else if (tx.value.trim()){ var F = fonte(f.de); F.palavras = F.palavras || []; distribui(tx.value, f.ts, f.ts + 2.5).forEach(function(w){ F.palavras.push(w); }); F.palavras.sort(function(a, b){ return a.i - b.i; }); }
    fecha(); mudou('Editar legenda'); if (aba === 'legenda') mostraAba(); }]], tx);
  setTimeout(function(){ tx.focus(); tx.select(); }, 50);
}
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
    el('p', 'nota-ajuda', assets ? 'Imagens e vídeos até 20 MB. Ficam guardados para todos os projetos.' : 'Só quem edita esta página pode enviar arquivos.')]));
  C.appendChild(chips([['todos', 'Tudo'], ['logo', 'Logos'], ['foto', 'Fotos'], ['video', 'Vídeos']], bibFiltro, function(v){ bibFiltro = v; mostraAba(); }));
  var g = el('div', 'bib'), itens = itensBib().filter(function(x){ return bibFiltro === 'todos' || x.tipo === bibFiltro; });
  itens.forEach(function(x){
    var mini = el('div', 'mini'), m;
    if (x.tipo === 'video'){ m = el('video'); m.src = x.url + '#t=0.5'; m.muted = true; m.preload = 'metadata'; m.playsInline = true; m.onmouseenter = function(){ m.play().catch(function(){}); }; m.onmouseleave = function(){ m.pause(); }; }
    else { m = el('img'); m.src = x.url; m.alt = x.nome; m.loading = 'lazy'; }
    mini.appendChild(m);
    var acoes = el('div', 'acoes');
    if (x.tipo === 'video'){ acoes.appendChild(botao('No cursor', function(){ inserirVideoBib(x, 'cursor'); })); acoes.appendChild(botao('Início', function(){ inserirVideoBib(x, 'inicio'); })); acoes.appendChild(botao('Fim', function(){ inserirVideoBib(x, 'fim'); })); }
    else { acoes.appendChild(botao('Pôr na tela', function(){ porImagem(x); }));
      if (x.tipo === 'logo') acoes.appendChild(botao('Usar no rodapé', function(){ lay('logo').url = x.url; lay('logo').visivel = true; pinta(); agendaSalvar('Trocar logo do rodapé'); estado('Logo do rodapé trocada', 'ok'); })); }
    if (!x.fixo) acoes.appendChild(botao('×', function(){ apagarBib(x); }, 'Apagar ' + x.nome));
    g.appendChild(el('div', 'cel', [mini, el('div', 'info', [el('b', null, x.nome), el('span', 't', ({ logo:'logo', foto:'foto', video:'vídeo' })[x.tipo] + (x.dur ? ' · ' + x.dur.toFixed(1) + ' s' : '')), acoes])]));
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
        l.appendChild(el('div', 'item', [el('span', 't', d.toFixed(0) + ' s'), el('b', null, (X.nome || id) + (id === P.id ? ' (este)' : '')),
          botao('Adicionar no fim', function(){ fecha(); adicionarProjeto(id, 'fim'); })])); });
      box.appendChild(l);
    } else {
      var itens = itensBib().filter(function(x){ return addFiltro === 'video' ? x.tipo === 'video' : x.tipo !== 'video'; }), g = el('div', 'bib');
      itens.forEach(function(x){
        var mini = el('div', 'mini'), m;
        if (x.tipo === 'video'){ m = el('video'); m.src = x.url + '#t=0.5'; m.muted = true; m.preload = 'metadata'; m.playsInline = true; }
        else { m = el('img'); m.src = x.url; m.alt = x.nome; }
        mini.appendChild(m);
        var b = x.tipo === 'video' ? botao('Adicionar no fim', function(){ fecha(); inserirVideoBib(x, 'fim'); })
          : botao('Pôr no fim', function(){ fecha(); porImagem(x, Math.max(0, durFala() - 4)); });
        g.appendChild(el('div', 'cel', [mini, el('div', 'info', [el('b', null, x.nome), el('span', 't', x.tipo === 'video' ? 'vídeo' + (x.dur ? ' · ' + x.dur.toFixed(1) + ' s' : '') : x.tipo), el('div', 'acoes', [b])])]));
      });
      box.appendChild(itens.length ? g : el('p', 'nota-ajuda', addFiltro === 'video' ? 'Nenhum vídeo na biblioteca ainda. Envie um aqui embaixo.' : 'Nada aqui ainda.'));
    }
    box.appendChild(el('div', 'linha', [
      botao('Enviar vídeo do computador', function(){ fecha(); enviarVideoMontagem('fim'); }),
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
  if (!assets){ dialogo('Envio indisponível', 'Só quem edita esta página pode enviar arquivos.', [['Ok', fecha]]); return; }
  escolherArquivo(tipo === 'video' ? 'video/mp4,video/quicktime,video/webm' : 'image/png,image/jpeg,image/webp,image/svg+xml', function(r, nome, info){
    var id = novoId('b'), doc = { tipo:tipo, nome:nome, url:r.url, asset:r.id, w:info.w || 0, h:info.h || 0, dur:info.dur || 0, criado_em:new Date().toISOString() };
    BIB[id] = Object.assign({ id:id }, doc); mostraAba();
    if (db) db.doc('biblioteca/' + id).set(doc).catch(function(){ estado('O arquivo foi enviado, mas não entrou na lista salva', 'erro'); });
  });
}
function apagarBib(x){
  dialogo('Apagar "' + x.nome + '" da biblioteca?', 'O arquivo some da biblioteca e deixa de aparecer nos projetos que o usam. Não dá para desfazer.', [['Cancelar', fecha], ['Apagar', function(){
    fecha(); delete BIB[x.id]; mostraAba();
    if (db) db.doc('biblioteca/' + x.id).delete().catch(function(){});
    if (assets && x.asset) assets.delete(x.asset).catch(function(){});
  }]]);
}
function escolherArquivo(accept, pronto){
  var inp = document.createElement('input'); inp.type = 'file'; inp.accept = accept;
  inp.onchange = function(){ var f = inp.files[0]; if (!f) return;
    if (f.size > 20 * 1024 * 1024){ dialogo('Arquivo grande demais', 'O limite aqui é 20 MB. Para vídeos maiores, mande ao Claude que ele prepara.', [['Ok', fecha]]); return; }
    estado('Enviando ' + f.name + '...');
    assets.upload(f).then(function(r){ var nome = f.name.replace(/\.[^.]+$/, '');
      if (/^video/.test(f.type)){ var v = document.createElement('video'); v.preload = 'metadata'; v.src = r.url;
        v.onloadedmetadata = function(){ estado('Enviado', 'ok'); pronto(r, nome, { dur:v.duration || 5, w:v.videoWidth, h:v.videoHeight }); };
        v.onerror = function(){ estado('Este formato de vídeo não abre no navegador', 'erro'); }; }
      else { var im = new Image(); im.onload = function(){ estado('Enviado', 'ok'); pronto(r, nome, { w:im.naturalWidth, h:im.naturalHeight }); }; im.onerror = function(){ estado('Imagem não abriu', 'erro'); }; im.src = r.url; }
    }).catch(function(e){ estado('O envio falhou: ' + ((e && e.code) || 'erro'), 'erro'); });
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
  var b = el('button', 'btn pri', iaJob ? 'A IA está montando...' : 'Criar versão com IA · previsão ~' + etaIa() + ' s'); b.type = 'button'; b.disabled = !!iaJob || !sampleNs;
  b.onclick = rodarIA; C.appendChild(b);
  if (!sampleNs) C.appendChild(el('p', 'nota-ajuda', 'A IA não está disponível nesta visualização. Abra a página no claude.ai e permita que ela use o Claude.'));
  if (iaJob) C.appendChild(cartaoJob());
}
function cartaoJob(){
  var c = el('div', 'cartao'), bar = el('div', 'prog', [el('i')]), info = el('p', 'nota-ajuda', '');
  c.appendChild(el('b', null, (iaJob && iaJob.fase) || 'Montando a versão de visualização')); c.appendChild(bar); c.appendChild(info);
  c.appendChild(botao('Cancelar', function(){ if (iaJob) iaJob.ctrl.abort(); }));
  function atual(){ if (!iaJob || !document.body.contains(c)) return; var s = (Date.now() - iaJob.ini) / 1000;
    bar.firstChild.style.width = Math.min(96, s / iaJob.eta * 100) + '%';
    info.textContent = 'Previsão: ~' + iaJob.eta + ' s · passaram ' + Math.round(s) + ' s' + (s > iaJob.eta ? ' (está levando mais que o previsto)' : '') + (iaJob.chars ? ' · roteiro: ' + iaJob.chars + ' caracteres' : '');
    setTimeout(atual, 500); }
  atual(); return c;
}
function faixaIA(){
  var fx = $('faixa-ia'); fx.innerHTML = '';
  if (iaJob){ fx.hidden = false; fx.appendChild(el('span', null, 'IA montando o vídeo · previsão ~' + iaJob.eta + ' s')); var pr = el('div', 'prog', [el('i')]); pr.style.flex = '1'; pr.style.minWidth = '80px'; fx.appendChild(pr);
    (function tic(){ if (!iaJob) return; pr.firstChild.style.width = Math.min(96, (Date.now() - iaJob.ini) / 10 / iaJob.eta) + '%'; setTimeout(tic, 500); })(); return; }
  if (P && P.ia && P.ia.status === 'previa'){ fx.hidden = false;
    fx.appendChild(el('span', null, 'Versão de visualização feita pela IA' + (P.ia.explicacao ? ': ' + P.ia.explicacao : '') + '. Revise e aprove.'));
    fx.appendChild(botao('Aprovar e pedir versão em alta', aprovar)); fx.appendChild(botao('Refazer', function(){ aba = 'ia'; abrePainel(); }));
    fx.appendChild(botao('×', function(){ P.ia.status = 'editando'; agendaSalvar(null, true); faixaIA(); }, 'Fechar aviso')); return; }
  fx.hidden = true;
}
function falasDe(key){ var ws = fonte(key).palavras || [], out = [], cur = null;
  ws.forEach(function(w){ var p = String(w.p).trim(); if (!p) return;
    if (!cur || w.i - cur[1] > .5 || w.f - cur[0] > 7){ if (cur) out.push(cur); cur = [+w.i.toFixed(2), +w.f.toFixed(2), p]; } else { cur[1] = +w.f.toFixed(2); cur[2] += ' ' + p; }
    if (/[.?!]$/.test(p)){ out.push(cur); cur = null; } });
  if (cur) out.push(cur); return out; }
async function rodarIA(){
  if (!sampleNs || iaJob) return;
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
    var r = await sampleNs.json(prompt, { signal:iaJob.ctrl.signal, cache:false, modelTier:'complex', onText:function(u){ if (iaJob) iaJob.chars = (u.text || '').length; } });
    var seg = Math.round((Date.now() - iaJob.ini) / 1000); iaJob = null; aplicarIA(r, tipo, seg);
  } catch (e) {
    iaJob = null; faixaIA(); mostraAba();
    var msg = { cancelled:'Cancelado.', not_granted:'A página não tem permissão para usar o Claude. Abra no claude.ai e permita.', rate_limited:'Muitos pedidos agora. Espere um pouco e tente de novo.', invalid_json:'A IA respondeu num formato que não deu para ler. Tente de novo, talvez com orientações mais curtas.', prompt_too_large:'Os vídeos têm fala demais para um pedido só. Deixe menos vídeos na linha do tempo.' }[e && e.code] || ('A IA não conseguiu montar agora (' + ((e && e.code) || 'erro') + ').');
    if (!(e && e.code === 'cancelled')) dialogo('Edição automática não concluída', msg, [['Ok', fecha]]); else estado(msg);
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
  if (!P) return; P.ia = P.ia || {}; P.ia.status = 'aprovado'; P.render = 'pedido'; salvar(); faixaIA();
  var doc = { projeto:P.id, nome:P.nome || P.id, formato:P.formato || 'reel', status:'pedido', pedido_em:new Date().toISOString() };
  RENDERS[P.id] = Object.assign({ id:P.id }, doc); if (db) db.doc('renders/' + P.id).set(doc).catch(function(){});
  dialogo('Aprovado. Versão em alta pedida', 'Diga ao Claude "renderiza os pedidos do Intus Cut". Ele gera o MP4 a partir das gravações originais, com a sua edição. Quando ficar pronto, aparece em Exportar com os botões Baixar e Salvar no Drive. Se preferir não esperar, exporte agora pelo navegador.', [['Ok', fecha], ['Exportar agora', function(){ fecha(); abreExportar(); }]]);
}

// ---------- propriedades (direita) ----------
function seleciona(){ desenhaTimeline(); inspetor(); pintaGuia(); if (estreito() && selEl){ abreInsp(); } }
function inspetor(){
  var C = $('insp-corpo'); if (!C || !P) return; var y = C.scrollTop; C.innerHTML = '';
  var t = selEl ? selEl.split(':') : null, tit = 'Corte selecionado';
  if (t && t[0] === 'texto' && P.textos[+t[1]]){ tit = 'Texto'; C.appendChild(cardTexto(+t[1])); }
  else if (t && t[0] === 'sombra' && P.sombras[+t[1]]){ tit = 'Sombra'; C.appendChild(cardSombra(+t[1])); }
  else if (t && t[0] === 'imagem' && P.imagens[+t[1]]){ tit = 'Imagem'; C.appendChild(cardImagem(+t[1])); }
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
  P = o; projetos[o.id] = o; P.textos = P.textos || []; P.imagens = P.imagens || []; P.sombras = P.sombras || []; aplicaFormato(); selEl = null; selTrecho = cl(selTrecho, 0, Math.max(0, trechos().length - 1)); recalc(); desenhaTimeline(); irPara(Math.min(T, dur())); mostraAba(); nomesSelect(); faixaIA();
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
function abrir(id){
  if (histTimer){ clearTimeout(histTimer); histPush(); }
  pausar(); P = projetos[id]; if (!P) return; $('vazio').hidden = true; T = 0; selTrecho = 0; lacunaSel = null; trechoAtual = -1; selEl = null;
  P.midia = P.midia || {}; P.textos = P.textos || []; P.imagens = P.imagens || []; P.sombras = P.sombras || []; aplicaFormato();
  var M = midiaDe('');
  if (M.v.readyState >= 1 && !P.midia.duracao) P.midia.duracao = M.v.duration;
  else M.v.addEventListener('loadedmetadata', function f(){ M.v.removeEventListener('loadedmetadata', f); if (P && !P.midia.duracao) P.midia.duracao = M.v.duration; desenhaTimeline(); irPara(T); });
  recalc(); nomesSelect(); histAbrir(); desenhaTimeline(); mostraAba(); faixaIA(); irPara(0); try { localStorage.setItem('intuscut-ultimo', id); } catch (e) {}
}
$('sel-proj').onchange = function(){ abrir($('sel-proj').value); };
(function(){ var s = $('sel-formato'); Object.keys(FORMATOS).forEach(function(k){ var o = document.createElement('option'); o.value = k; o.textContent = FORMATOS[k].n; s.appendChild(o); });
  s.onchange = function(){ mudaFormato(s.value); }; })();
function agendaSalvar(rot, semHist){ if (!semHist) histMarca(rot); estado('Alterações não salvas'); clearTimeout(salvarTimer); salvarTimer = setTimeout(salvar, 1200); }
function salvar(){
  if (!P) return; P.atualizado_em = new Date().toISOString();
  if (!db){ try { localStorage.setItem('intuscut-' + P.id, JSON.stringify(P)); estado('Salvo só neste navegador'); } catch (e) { estado('Não foi possível salvar', 'erro'); } return; }
  var meu = P;
  db.doc('projetos/' + meu.id).set({ nome:meu.nome || meu.id, atualizado_em:meu.atualizado_em, render:meu.render || '', dados:JSON.stringify(meu) })
    .then(function(){ if (meu === P) estado('Salvo', 'ok'); })
    .catch(function(e){ estado(e && e.code === 'permission_denied' ? 'Sem permissão para salvar' : 'Falhou ao salvar, tente de novo', 'erro'); });
}
$('b-dup').onclick = function(){ if (!P) return; var c = clone(P); c.id = P.id.replace(/-v\w+$/, '') + '-v' + Date.now().toString(36).slice(-4); c.nome = (P.nome || P.id) + ' (cópia)'; c.render = ''; delete c.ia;
  projetos[c.id] = c; abrir(c.id); salvar(); };
$('b-novo').onclick = function(){
  if (!assets){ dialogo('Envio de vídeo indisponível', 'Só quem edita esta página pode enviar vídeos. Mande o vídeo ao Claude, que ele prepara o projeto com legenda e música separada.', [['Ok', fecha]]); return; }
  escolherArquivo('video/mp4,video/quicktime,video/webm', function(r, nome, info){ var id = novoId('video-'), d = info.dur, vert = info.h > info.w;
    projetos[id] = { id:id, nome:nome, formato:vert ? 'reel' : 'youtube', gancho:'Escreva o gancho aqui', destaque:[], trechos:[{ ini:0, fim:+d.toFixed(2), cheia:vert }], palavras:[],
      planos:[{ ini:0, fim:d + 1, cx:960, larg:ORIG_W, y:0, tarja:true }], notas:[], textos:[], sombras:[], imagens:[], audio:{ voz:1, musica:0 }, estilo:{ cores:['amarelo', 'verde'], tamanho:84, maiusculas:true, palavras_por_grupo:3 },
      final:{ ativo:true, chamada:'SIGA PARA MAIS DICAS', slogan:'Te levamos ao próximo nível. Do iniciante ao atleta.' }, midia:{ video:r.url, voz:r.url, musica:'', duracao:d, asset:r.id, alt:info.w ? ORIG_W * info.h / info.w : 1080 } };
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
  cv.toBlob(function(b){ if (downloads) downloads.save({ filename:(P.id || 'video') + '-' + T.toFixed(1) + 's.png', data:b }).catch(function(){});
    else { var i = el('img'); i.src = URL.createObjectURL(b); i.style.width = '100%'; dialogo('Quadro', 'Clique com o botão direito para salvar.', [['Fechar', fecha]], i); } }, 'image/png'); };
$('b-exp').onclick = function(){ abreExportar(); };
function abreExportar(){
  if (!P) return; pausar();
  var q = sel([['alta', 'Alta qualidade (para baixar)'], ['drive', 'Leve, até 16 MB (cabe no Drive)']], 'alta'), box = el('div', 'grupo');
  box.appendChild(el('div', 'linha', [campo('Qualidade', q), el('span', 't', formato().n + ' · ' + W + 'x' + H + ' · ' + dur().toFixed(1) + ' s')]));
  var ren = Object.keys(RENDERS).map(function(k){ return RENDERS[k]; }).sort(function(a, b){ return (b.pedido_em || '').localeCompare(a.pedido_em || ''); });
  if (ren.length){ var l = el('div', 'lista'); ren.slice(0, 8).forEach(function(r){
      var ac = el('span', 'linha');
      if (r.url){ ac.appendChild(botao('Baixar', function(){ baixarUrl(r.url, (r.nome || r.projeto) + '.mp4'); })); ac.appendChild(botao('Salvar no Drive', function(){ fetch(r.url).then(function(x){ return x.blob(); }).then(function(b){ salvarDrive(b, (r.nome || r.projeto) + '.mp4', r.projeto); }); })); }
      if (r.drive){ var a = el('a', null, 'Abrir no Drive'); a.href = r.drive; a.target = '_blank'; a.rel = 'noopener'; ac.appendChild(a); }
      l.appendChild(el('div', 'item', [el('span', 't', ({ pedido:'na fila', andamento:'renderizando', pronto:'pronto', erro:'erro' })[r.status] || r.status), el('b', null, r.nome || r.projeto), ac])); });
    box.appendChild(el('h3', null, 'Versões em alta pedidas ao Claude')); box.appendChild(l); }
  dialogo('Exportar', 'O navegador grava o MP4 a partir das prévias (720p). A versão em alta é feita pelo Claude a partir das gravações originais.', [['Fechar', fecha], ['Pedir versão em alta', function(){ fecha(); aprovar(); }], ['Exportar agora', function(){ exportar(q.value); }]], box);
}
function baixarUrl(url, nome){ fetch(url).then(function(x){ return x.blob(); }).then(function(b){ if (downloads) return downloads.save({ filename:nome, data:b }); window.open(url, '_blank'); }).catch(function(){ estado('Não consegui baixar', 'erro'); }); }
async function exportar(qual){
  if (!window.VideoEncoder || !window.AudioEncoder || !window.Mp4Muxer){ dialogo('Exportação indisponível neste navegador', 'Use o Chrome ou o Edge no computador, ou peça a versão em alta ao Claude.', [['Ok', fecha]]); return; }
  pausar(); var barra = el('div', 'prog', [el('i')]), info = el('p', 'nota-ajuda', 'Preparando...'), cancelado = false, box = el('div', 'grupo', [barra, info]);
  dialogo('Exportando', 'Deixe esta aba aberta. Um vídeo de 1 minuto leva de 1 a 3 minutos.', [['Cancelar', function(){ cancelado = true; fecha(); }]], box);
  try {
    var SR = 48000, d = dur(), DF = durFala(), N = Math.round(d * FPS);
    var vbr = qual === 'drive' ? Math.max(600000, Math.floor(15 * 1048576 * 8 / d) - 140000) : 8000000; vbr = Math.min(vbr, 9000000);
    var vcfg = { codec:'avc1.640028', width:W, height:H, bitrate:vbr, framerate:FPS };
    if (!(await VideoEncoder.isConfigSupported(vcfg)).supported) vcfg.codec = 'avc1.4d0028';
    var acfg = { codec:'mp4a.40.2', sampleRate:SR, numberOfChannels:2, bitrate:qual === 'drive' ? 96000 : 160000 };
    var temAac = (await AudioEncoder.isConfigSupported(acfg)).supported;
    var muxer = new Mp4Muxer.Muxer({ target:new Mp4Muxer.ArrayBufferTarget(), video:{ codec:'avc', width:W, height:H }, audio:temAac ? { codec:'aac', sampleRate:SR, numberOfChannels:2 } : undefined, fastStart:'in-memory' });
    var ve = new VideoEncoder({ output:function(ch, meta){ muxer.addVideoChunk(ch, meta); }, error:function(e){ throw e; } }); ve.configure(vcfg);
    var cv = document.createElement('canvas'); cv.width = W; cv.height = H; var c2 = cv.getContext('2d');
    for (var k = 0; k < N; k++){
      if (cancelado) return;
      var t = k / FPS; if (t < DF){ var f = fonteEm(t); if (f) await esperaSeek(midiaDe(f.de).v, f.ts); }
      desenha(c2, t, null);
      var vf = new VideoFrame(cv, { timestamp:Math.round(k * 1e6 / FPS), duration:Math.round(1e6 / FPS) }); ve.encode(vf, { keyFrame:k % 60 === 0 }); vf.close();
      while (ve.encodeQueueSize > 8) await new Promise(function(r){ setTimeout(r, 5); });
      if (k % 10 === 0){ barra.firstChild.style.width = (k / N * 85) + '%'; info.textContent = 'Imagem: quadro ' + k + ' de ' + N; }
    }
    await ve.flush();
    if (temAac){
      info.textContent = 'Áudio...'; var ac = new AudioContext({ sampleRate:SR }), cache = {};
      var buf = async function(url){ if (!url) return null; if (!(url in cache)){ try { cache[url] = await ac.decodeAudioData(await (await fetch(url)).arrayBuffer()); } catch (e) { cache[url] = null; } } return cache[url]; };
      var au = P.audio || {}, total = Math.round(d * SR), L = new Float32Array(total), R = new Float32Array(total), gv = au.voz == null ? 1 : au.voz, gm = au.musica || 0, pos = 0, rampa = Math.round(.015 * SR), M = mapa();
      for (var q2 = 0; q2 < M.length; q2++){ var m = M[q2], md = fonte(m.de).midia || {}, bv = await buf(md.voz), bm = md.musica ? await buf(md.musica) : null;
        var a = Math.round(m.ini * SR), n = Math.round((m.fim - m.ini) * SR);
        for (var i = 0; i < n && pos + i < total; i++){ var e = Math.min(1, i / rampa, (n - i) / rampa);
          for (var z = 0; z < 2; z++){ var b = z ? bm : bv, gg = z ? gm : gv; if (!b || !gg) continue; var j = a + i; if (j >= b.length) continue;
            var l = b.getChannelData(0)[j], r = b.numberOfChannels > 1 ? b.getChannelData(1)[j] : l; L[pos + i] += l * gg * e; R[pos + i] += r * gg * e; } }
        pos += n; }
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
    dialogo('Vídeo pronto (' + mb + ' MB)', temAac ? '' : 'Este navegador não grava áudio AAC: o vídeo saiu sem som.', [['Fechar', fecha], ['Salvar no Drive', function(){ salvarDrive(blob, nome, P.id); }], ['Baixar', function(){ if (downloads) downloads.save({ filename:nome, data:blob }).catch(function(){}); }]], vid);
  } catch (e) { info.textContent = 'A exportação falhou: ' + (e && (e.message || e.code) || e) + '. Tente no Chrome do computador, ou peça a versão em alta ao Claude.'; }
}
async function salvarDrive(blob, nome, projId){
  var mcp = await claude.use('mcp').catch(function(){ return null; });
  if (!mcp){ dialogo('Drive indisponível aqui', 'Abra a página no claude.ai com o Google Drive conectado (Configurações, Conectores).', [['Ok', fecha]]); return; }
  if (blob.size > 16 * 1048576){ dialogo('Arquivo grande demais para o Drive por aqui', 'O envio direto aceita até 16 MB. Exporte de novo com a qualidade "Leve, até 16 MB", ou baixe e envie ao Drive pelo computador.', [['Ok', fecha]]); return; }
  var lt = await mcp.listTools().catch(function(){ return null; });
  if (!lt || !lt.fileArgs){ dialogo('Envio de arquivo indisponível', 'Esta visualização não envia arquivos ao Drive. Baixe o vídeo e envie pelo computador.', [['Ok', fecha]]); return; }
  estado('Enviando ao Drive...');
  try {
    var pasta = await pastaDrive(mcp);
    var input = { title:nome, base64Content:{ $file:{ data:blob, name:'video.mp4', type:'video/mp4' } }, contentMimeType:'video/mp4', disableConversionToGoogleType:true };
    if (pasta) input.parentId = pasta;
    var r = await mcp.callTool('Google Drive', 'create_file', input), pl = r && (r.payload || r), id = pl && (pl.id || (pl.file && pl.file.id));
    var link = (pl && (pl.webViewLink || pl.alternateLink || (pl.file && pl.file.webViewLink))) || (id ? 'https://drive.google.com/file/d/' + id + '/view' : '');
    estado('Salvo no Drive', 'ok');
    if (projId && link){ RENDERS[projId] = Object.assign(RENDERS[projId] || { projeto:projId, nome:nome }, { drive:link }); if (db) db.doc('renders/' + projId).set(RENDERS[projId]).catch(function(){}); }
    var a = el('a', null, 'Abrir no Drive'); if (link){ a.href = link; a.target = '_blank'; a.rel = 'noopener'; }
    dialogo('Salvo no Drive', 'Pasta "Intus Cut" no seu Google Drive.', [['Ok', fecha]], link ? a : null);
  } catch (e) {
    estado('Não salvou no Drive', 'erro');
    var m = { needs_reauth:'A conexão com o Google Drive expirou. Reconecte em claude.ai, Configurações, Conectores.', server_not_connected:'O Google Drive não está conectado. Adicione em claude.ai, Configurações, Conectores.', approval_required:'O envio precisa da sua aprovação. Abra a página no claude.ai e aprove.', blocked_by_policy:'A sua organização não permite este envio.', tool_error:'O Drive recusou: ' + ((e && e.message) || '') }[e && e.code]
      || 'O envio pode ter dado certo ou não. Confira a pasta "Intus Cut" no Drive antes de tentar de novo.';
    dialogo('Não salvou no Drive', m, [['Ok', fecha]]);
  }
}
async function pastaDrive(mcp){
  var doc = db ? await db.doc('config/drive').get().catch(function(){ return null; }) : null, d = doc && typeof doc.data === 'function' ? doc.data() : doc;
  if (d && d.pasta) return d.pasta;
  try { var r = await mcp.callTool('Google Drive', 'create_file', { title:'Intus Cut', mimeType:'application/vnd.google-apps.folder' }), pl = r && (r.payload || r), id = pl && (pl.id || (pl.file && pl.file.id));
    if (id && db) db.doc('config/drive').set({ pasta:id }).catch(function(){}); return id || ''; } catch (e) { return ''; }
}

// ---------- teclado e transporte ----------
$('b-play').onclick = function(){ tocando ? pausar() : tocar(); };
$('b-ini').onclick = function(){ pausar(); irPara(0); };
$('b-q-').onclick = function(){ pausar(); irPara(T - 1 / FPS); };
$('b-q+').onclick = function(){ pausar(); irPara(T + 1 / FPS); };
document.addEventListener('keydown', function(e){
  if (!P || !$('modal').hidden) return;
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
function carregarLocais(){ try { for (var i = 0; i < localStorage.length; i++){ var k = localStorage.key(i); if (/^intuscut-(?!ultimo$|ig$|clip$|ia$|alt-tl$|hist-)/.test(k)){ var p = JSON.parse(localStorage.getItem(k)); if (p && p.id && !projetos[p.id]) projetos[p.id] = p; } } } catch (e) {} }
function pronto(){
  nomesSelect(); var ids = Object.keys(projetos);
  if (!ids.length){ $('vazio').hidden = false; estado(db ? 'Nenhum projeto' : 'Sem conexão com o banco'); return; }
  var ult = null; try { ult = localStorage.getItem('intuscut-ultimo'); } catch (e) {}
  if (!P) abrir(projetos[ult] ? ult : $('sel-proj').value || ids[0]);
  estado(db ? 'Salvo' : 'Salvando só neste navegador', db ? 'ok' : '');
}
if (innerWidth <= 820) $('app').classList.add('sem-painel');
marcaIG(); marcaDesfazer(); marcaAba();
ctx.fillStyle = '#0f0f0f'; ctx.fillRect(0, 0, W, H);
LOGO.onload = function(){ if (P) pinta(); };
document.fonts && document.fonts.ready.then(function(){ if (P) pinta(); });
if (window.claude && claude.use){
  Promise.all([claude.use('db'), claude.use('assets'), claude.use('downloads'), claude.use('sample')]).then(function(r){
    db = r[0]; assets = r[1]; downloads = r[2]; sampleNs = r[3];
    if (!db){ carregarLocais(); pronto(); return; }
    var primeiro = true;
    db.collection('projetos').onSnapshot(function(snap){
      snap.docs.forEach(function(dc){ var x = dc.data(); if (!x || !x.dados) return; try { var p = JSON.parse(x.dados); p.id = dc.id;
        if (!projetos[dc.id] || !P || P.id !== dc.id) projetos[dc.id] = p; } catch (e) {} });
      if (primeiro){ primeiro = false; pronto(); } else nomesSelect();
    }, function(){ estado('Sem acesso ao banco', 'erro'); carregarLocais(); pronto(); });
    db.collection('biblioteca').onSnapshot(function(snap){ BIB = {}; snap.docs.forEach(function(dc){ var x = dc.data(); if (x && x.url) BIB[dc.id] = Object.assign({ id:dc.id }, x); }); if (aba === 'biblioteca' && P) mostraAba(); }, function(){});
    db.collection('renders').onSnapshot(function(snap){ RENDERS = {}; snap.docs.forEach(function(dc){ RENDERS[dc.id] = Object.assign({ id:dc.id }, dc.data()); }); }, function(){});
  }).catch(function(){ carregarLocais(); pronto(); });
} else { carregarLocais(); pronto(); }
})();
