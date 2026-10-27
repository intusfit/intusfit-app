// ---------- sobreposições de vídeo (react, comparativo, janela no canto) ----------
// Cada camada toca um vídeo (de P.fontes) por cima do vídeo principal, num retângulo ou bolha,
// do segundo `ini` ao `fim` do vídeo final, começando no segundo `src` da gravação dela.
function camadaPadrao(key, nome, durFonte, srcIni){
  var J = lay('janela'), w = Math.round(W * .46), h = Math.round(w * 9 / 16), resta = Math.max(1, (durFonte || 10) - (srcIni || 0));
  return { de:key, nome:nome, ini:+T.toFixed(2), fim:+Math.min(Math.max(dur(), T + 1), T + resta).toFixed(2), src:+(srcIni || 0).toFixed(2),
    x:W - w - 36, y:J.y + 36, w:w, h:h, forma:'ret', borda:true, corBorda:'#ffffff', espessura:6, raio:18, volume:.8, zoom:1, fx:.5, fy:.5, espelho:false };
}
function adicionarCamada(k){ P.camadas.push(k); selEl = 'camada:' + (P.camadas.length - 1); atualizaTudo(); agendaSalvar('Sobrepor ' + (k.nome || 'vídeo')); seleciona(); }
function sobreporProjeto(id){ var X = projetos[id]; if (!X) return; var key = garantirFonte(X.id, '', snapshot(X)), t0 = (X.trechos && X.trechos[0]) ? X.trechos[0].ini : 0;
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
  if (n === 'NotAllowedError' || n === 'SecurityError') return 'O navegador ou o claude.ai não deixou esta página usar a câmera e o microfone. Confira a permissão de câmera do navegador para o claude.ai. Se continuar bloqueado, avise o Claude: ele monta o mesmo estúdio no site da Intus, onde a câmera funciona.';
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
    ['Baixar uma cópia', function(){ if (downloads) downloads.save({ filename:nome.replace(/[\/:]/g, '-') + '.' + ext, data:blob }).catch(function(){}); }],
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
// O vídeo gravado pode passar de 20 MB (limite de cada arquivo): vai em partes de 18 MB e é remontado ao abrir.
async function enviarEmPartes(blob, tipo, rotulo){
  var TAM = 18 * 1048576, n = Math.max(1, Math.ceil(blob.size / TAM)), urls = [], ids = [], ext = tipo.indexOf('mp4') >= 0 ? 'mp4' : 'webm';
  for (var i = 0; i < n; i++){
    if (rotulo) rotulo('Enviando parte ' + (i + 1) + ' de ' + n + '...', i / n);
    var f = new File([blob.slice(i * TAM, Math.min(blob.size, (i + 1) * TAM))], 'gravacao-parte' + (i + 1) + '.' + ext, { type:tipo });
    var r = await assets.upload(f); urls.push(r.url); ids.push(r.id);
  }
  if (rotulo) rotulo('Pronto', 1); return { partes:urls, ids:ids };
}
async function guardarGravacao(blob, d, nome, tipo, rascunho){
  if (!assets){ dialogo('Não dá para guardar aqui', 'Só quem edita esta página pode guardar vídeos. Baixe uma cópia para não perder.', [['Ok', fecha]]); return; }
  var barra = el('div', 'prog', [el('i')]), info = el('p', 'nota-ajuda', 'Preparando...');
  dialogo('Guardando a gravação', 'Não feche esta aba.', [], el('div', 'grupo', [barra, info]));
  try {
    var up = await enviarEmPartes(blob, tipo, function(t, f){ info.textContent = t; barra.firstChild.style.width = Math.round(f * 100) + '%'; });
    var id = novoId('b'), item = { tipo:'video', nome:nome, url:up.partes[0], partes:up.partes, asset:up.ids[0], assets:up.ids, mime:tipo, w:W, h:H, dur:+d.toFixed(2), rascunho:!!rascunho, react:true, criado_em:new Date().toISOString() };
    BIB[id] = Object.assign({ id:id }, item); if (db) db.doc('biblioteca/' + id).set(item).catch(function(){});
    PARTES[up.partes.join('|')] = Promise.resolve(URL.createObjectURL(blob));
    fecha();
    if (rascunho){ estado('Rascunho guardado na Biblioteca', 'ok'); dialogo('Rascunho guardado', 'Está na Biblioteca, em Vídeos, com a etiqueta "rascunho". Dá para abrir para editar quando quiser.', [['Ok', fecha]]); if (aba === 'biblioteca') mostraAba(); }
    else projetoDeGravacao(BIB[id]);
  } catch (e) {
    dialogo('Não consegui guardar', 'O envio falhou (' + ((e && e.code) || 'erro') + '). Baixe uma cópia para não perder a gravação.', [['Fechar', fecha], ['Baixar uma cópia', function(){ if (downloads) downloads.save({ filename:nome.replace(/[\/:]/g, '-') + (tipo.indexOf('mp4') >= 0 ? '.mp4' : '.webm'), data:blob }).catch(function(){}); }]]);
  }
}
function midiaDeItem(item){ var m = { duracao:item.dur || 5, alt:item.w && item.h ? ORIG_W * item.h / item.w : 1080, musica:'' };
  if (item.partes && item.partes.length > 1){ m.partes = item.partes.slice(); m.tipo = item.mime || 'video/mp4'; } else { m.video = item.url; m.voz = item.url; } return m; }
function projetoDeGravacao(item){
  var id = novoId('react-'), m = midiaDeItem(item), fmtK = P ? (P.formato || 'reel') : 'reel';
  projetos[id] = { id:id, nome:item.nome, formato:fmtK, gancho:'', destaque:[], trechos:[{ ini:0, fim:+(item.dur || 5).toFixed(2), cheia:true, limpo:true }], palavras:[],
    planos:[{ ini:0, fim:(item.dur || 5) + 1, cx:960, larg:ORIG_W, y:0, tarja:true }], notas:[], textos:[], sombras:[], imagens:[], camadas:[], audio:{ voz:1, musica:0 },
    estilo:{ cores:['amarelo', 'verde'], tamanho:84, maiusculas:true, palavras_por_grupo:3 }, final:{ ativo:true, chamada:'SIGA PARA MAIS DICAS', slogan:'Te levamos ao próximo nível. Do iniciante ao atleta.' }, midia:m };
  if (item.id && BIB[item.id] && BIB[item.id].rascunho){ BIB[item.id].rascunho = false; if (db) db.doc('biblioteca/' + item.id).update({ rascunho:false }).catch(function(){}); }
  abrir(id); salvar(); estado('Gravação aberta para edição', 'ok');
}
