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
