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

// ---------- edições do Claude: esqueletos em /intus-cut/claude/ ----------
// claude/index.json lista as edições ({id, nome, versao, fonte:{drive_id, nome, dur, w, h}}); claude/<id>.json é o projeto sem vídeo.
// Cada uma vira o projeto 'cl-<id>'. Versão maior do mesmo id sobrescreve o projeto (a situação do painel de vídeos fica).
// O vídeo vem do Drive do Luiz pelo link compartilhado e é copiado para o Drive da Intus uma vez por arquivo (drive_iniciar/drive_parte).
var CLAUDE_PEND = [];
async function sincronizarClaude(lista){
  var ix; try { ix = await (await fetch('claude/index.json', { cache:'no-store' })).json(); } catch (e){ return; }
  var tem = {}; (lista || []).forEach(function(p){ tem[p.id] = p; });
  CLAUDE_PEND = (ix.projetos || []).filter(function(e){ var x = tem['cl-' + e.id]; return e && e.id && e.fonte && (!x || (+x.claude || 0) < (+e.versao || 1)); });
  if (!CLAUDE_PEND.length) return;
  var box = el('div', 'grupo');
  CLAUDE_PEND.forEach(function(e){ box.appendChild(el('div', 'item', [el('b', null, (tem['cl-' + e.id] ? '↻ Atualizada: ' : '＋ Nova: ') + e.nome), el('span', 't', e.resumo || '')])); });
  box.appendChild(el('p', 'nota-ajuda', 'O vídeo original vem do seu Google Drive para o Drive da Intus (só na primeira vez de cada vídeo). Edição atualizada substitui o projeto com o mesmo nome; o que você mudou nele à mão é trocado pela versão nova.'));
  dialogo(CLAUDE_PEND.length === 1 ? 'O Claude preparou uma edição' : 'O Claude preparou ' + CLAUDE_PEND.length + ' edições', 'Cada uma abre aqui como projeto editável: cortes, legendas, etiquetas e cartão final.', [['Depois', fecha], ['Trazer agora', function(){ trazerClaude(CLAUDE_PEND); }]], box);
}
async function trazerClaude(pend){
  var prog = janelaProgresso('Trazendo as edições do Claude'), ult = null;
  try {
    for (var i = 0; i < pend.length; i++){ var e = pend[i];
      var esq = await (await fetch('claude/' + e.id + '.json', { cache:'no-store' })).json();
      var m = await videoDoDrive(e.fonte, function(f){ prog((i + f) / pend.length, 'Vídeo de "' + e.nome + '": ' + Math.round(f * 100) + '%'); });
      var p = clone(esq); p.id = 'cl-' + e.id; p.claude = { id:e.id, versao:+e.versao || 1 }; p.midia = m;
      await api('salvar', { projeto:p }); delete p._parcial; projetos[p.id] = p; ult = p.id;
      if (P && P.id === p.id) P = null;
    }
    CLAUDE_PEND = []; fecha(); nomesSelect(); if (ult){ $('vazio').hidden = true; abrir(ult); } estado('Edições do Claude prontas para editar', 'ok');
  } catch (err){ if (err && err.code === 'sem_acesso') return; dialogo('Não terminou', ((err && err.message) || 'Tente de novo.') + ' O que já foi copiado não se perde: tente de novo e ele continua de onde parou.', [['Fechar', fecha], ['Tentar de novo', function(){ trazerClaude(pend); }]]); }
}
async function videoDoDrive(f, aoProg){
  var r = await api('drive_iniciar', { drive_id:f.drive_id, nome:f.nome || 'video', duracao:f.dur || 0 }), tent = 0;
  aoProg(r.total ? r.recebido / r.total : 0);
  while (!r.concluido){
    var x = null; try { x = await api('drive_parte&id=' + r.id, {}); } catch (e){ if (e && e.code === 'sem_acesso') throw e; if (++tent > 6) throw e; await espera(2000 * tent); continue; }
    tent = 0; r.concluido = x.concluido; r.recebido = x.recebido; aoProg(r.total ? r.recebido / r.total : 1);
  }
  aoProg(1);
  return { video:r.url, voz:r.url, musica:'', duracao:f.dur || 0, alt:f.w && f.h ? ORIG_W * f.h / f.w : 1080 };
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
