import re, sys
import os
S = os.path.dirname(os.path.abspath(__file__)) + '/'
js = open(S + 'app.js').read()
novo = open(S + 'novo.js').read()
extra = open(S + 'site_extra.js').read()

def rep(a, b, n=1):
    global js
    c = js.count(a)
    assert c == n, (c, a[:90])
    js = js.replace(a, b)

def troca_funcao(nome, novo_txt):
    """Troca a função de topo `nome` (do 'function nome(' até a próxima linha de topo) pelo texto novo."""
    global js
    m = re.search(r'^(async )?function ' + re.escape(nome) + r'\(', js, re.M)
    assert m, nome
    ini = m.start()
    fim_m = re.compile(r'^(?:(?:async )?function |var |\$\(|// ----------|\(function|document\.|window\.|if \(|LOGO|marcaIG|ctx\.)', re.M)
    m2 = fim_m.search(js, m.end())
    fim = m2.start() if m2 else len(js)
    js = js[:ini] + novo_txt.rstrip() + '\n' + js[fim:]

# ---------- constantes ----------
rep("var COR_FAIXA = { texto:'#2d6f8c', imagem:'#8a5a2b', sombra:'#4c525c', el:'#8c6d1f' };", "var COR_FAIXA = { texto:'#2d6f8c', imagem:'#8a5a2b', sombra:'#4c525c', el:'#8c6d1f', camada:'#6b3f8f' };")
rep("geral:'Imagem e fundo', final:'Cartão final' };", "geral:'Imagem e fundo', final:'Cartão final', camada:'Sobreposição' };")
js = js.replace("url:'lib/logo-", "url:'/app/painel/logo-")
rep("var db = null, assets = null, downloads = null, sampleNs = null;", "var assets = true;")

# ---------- mídia: pré-carga para editar sem travar, e camadas com elementos próprios ----------
troca_funcao('midiaDe', r"""function midiaDe(key){
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
}""")
rep("function pausaTudo(exceto){ Object.keys(MID).forEach(function(k){ var x = MID[k]; if (x === exceto) return; x.v.pause(); x.av.pause(); x.am.pause(); }); }",
    "function pausaTudo(exceto){ Object.keys(MID).forEach(function(k){ var x = MID[k]; if (x === exceto) return; x.v.pause(); x.av.pause(); x.am.pause(); }); if (!exceto) Object.keys(CAM).forEach(function(k){ CAM[k].v.pause(); CAM[k].av.pause(); }); }")

# ---------- desenho, linha do tempo e seleção com camadas ----------
rep("  desenhaSombras(c, t, reg);\n  var L = lay('legenda')", "  desenhaSombras(c, t, reg);\n  desenhaCamadas(c, t, reg);\n  var L = lay('legenda')")
rep("function faixas(){ return [['texto', 'Texto', P.textos || []], ['imagem', 'Imagem', P.imagens || []], ['sombra', 'Sombra', P.sombras || []]]; }",
    "function faixas(){ return [['camada', 'Sobrepor', P.camadas || []], ['texto', 'Texto', P.textos || []], ['imagem', 'Imagem', P.imagens || []], ['sombra', 'Sombra', P.sombras || []]]; }")
rep("var r = el('span', 'rot', tipo === 'texto' ?", "var r = el('span', 'rot', tipo === 'camada' ? (x.nome || 'Vídeo') : tipo === 'texto' ?")
rep("  if (t[0] === 'imagem') return (P.imagens || [])[n];\n", "  if (t[0] === 'imagem') return (P.imagens || [])[n];\n  if (t[0] === 'camada') return (P.camadas || [])[n];\n")
rep("    case 'imagem':\n      if (arr.redim){ o.w = Math.max(30, i.w + dx * 2); } else { o.x = i.x + dx; o.y = i.y + dy; } break;",
    "    case 'imagem':\n      if (arr.redim){ o.w = Math.max(30, i.w + dx * 2); } else { o.x = i.x + dx; o.y = i.y + dy; } break;\n    case 'camada':\n      if (arr.redim){ o.w = Math.max(60, i.w + dx); o.h = o.forma === 'circulo' ? o.w : Math.max(60, i.h + dy); } else { o.x = i.x + dx; o.y = i.y + dy; } break;")
rep("  else if (id === 'imagem') o.w = Math.round(cl(o.w * (s > 0 ? 1.05 : .95), 30, W * 2));",
    "  else if (id === 'imagem') o.w = Math.round(cl(o.w * (s > 0 ? 1.05 : .95), 30, W * 2));\n  else if (id === 'camada'){ var fz = s > 0 ? 1.05 : .95, mx2 = o.x + o.w / 2, my2 = o.y + o.h / 2; o.w = Math.round(o.w * fz); o.h = Math.round(o.h * fz); o.x = Math.round(mx2 - o.w / 2); o.y = Math.round(my2 - o.h / 2); }")
rep("  if (f){ var M = midiaDe(f.de); if (Math.abs(M.v.currentTime - f.ts) > .04) M.v.currentTime = f.ts; sinc(M, f.ts, true); }\n  pinta();",
    "  if (f){ var M = midiaDe(f.de); if (Math.abs(M.v.currentTime - f.ts) > .04) M.v.currentTime = f.ts; sinc(M, f.ts, true); }\n  sincCamadas(false); pinta();")
rep("  pinta(); requestAnimationFrame(passo);", "  sincCamadas(true); pinta(); requestAnimationFrame(passo);")
rep("    if (tl.modo === 'ini'){ o.ini = +cl(a.ini + dt, 0, a.fim - .2).toFixed(2); o.fim = a.fim; }",
    "    if (tl.modo === 'ini'){ o.ini = +cl(a.ini + dt, 0, a.fim - .2).toFixed(2); o.fim = a.fim; if (tl.k === 'camada'){ o.src = +Math.max(0, tl.s0.src + (o.ini - a.ini)).toFixed(2); if (o.src === 0) o.ini = +(a.ini - tl.s0.src).toFixed(2); } }")
js = js.replace("/^(texto|sombra|imagem):/", "/^(texto|sombra|imagem|camada):/")
rep("var t = selEl.split(':'), lista = t[0] === 'texto' ? P.textos : t[0] === 'sombra' ? P.sombras : P.imagens;",
    "var t = selEl.split(':'), lista = t[0] === 'texto' ? P.textos : t[0] === 'sombra' ? P.sombras : t[0] === 'camada' ? P.camadas : P.imagens;")
rep("var it = clone(c.item), nome = c.tipo === 'texto' ? 'textos' : c.tipo === 'sombra' ? 'sombras' : 'imagens', lista = P[nome] = P[nome] || [];",
    "var it = clone(c.item), nome = c.tipo === 'texto' ? 'textos' : c.tipo === 'sombra' ? 'sombras' : c.tipo === 'camada' ? 'camadas' : 'imagens', lista = P[nome] = P[nome] || [];")
rep("    clipSalva({ tipo:tp, item:clone(it) });", "    clipSalva({ tipo:tp, item:clone(it), proj:P.id, fonte:tp === 'camada' && it.de ? clone(fonte(it.de)) : null });")
rep("    it.x += 30; it.y += 30; lista.push(it);", "    if (c.tipo === 'camada'){ var kf = garantirFonte(c.proj, it.de, c.fonte && c.fonte.midia ? c.fonte : null); it.de = kf; }\n    it.x += 30; it.y += 30; lista.push(it);")
rep("  else if (t && t[0] === 'imagem' && P.imagens[+t[1]]){ tit = 'Imagem'; C.appendChild(cardImagem(+t[1])); }",
    "  else if (t && t[0] === 'imagem' && P.imagens[+t[1]]){ tit = 'Imagem'; C.appendChild(cardImagem(+t[1])); }\n  else if (t && t[0] === 'camada' && P.camadas[+t[1]]){ tit = 'Sobreposição de vídeo'; C.appendChild(cardCamada(+t[1])); }")
rep("P = o; projetos[o.id] = o; P.textos = P.textos || []; P.imagens = P.imagens || []; P.sombras = P.sombras || [];",
    "P = o; projetos[o.id] = o; P.textos = P.textos || []; P.imagens = P.imagens || []; P.sombras = P.sombras || []; P.camadas = P.camadas || [];")

# ---------- fontes vindas da biblioteca ou de envio ----------
troca_funcao('fonteDeVideo', r"""function fonteDeVideo(chave, nome, midia){ P.fontes = P.fontes || {};
  if (!P.fontes[chave]) P.fontes[chave] = { nome:nome, midia:midia, palavras:[], planos:[{ ini:0, fim:(midia.duracao || 5) + 1, cx:960, larg:ORIG_W, y:0, tarja:true }], notas:[] };
  return chave; }""")
troca_funcao('inserirVideoBib', r"""function inserirVideoBib(item, onde){
  var k = fonteDeVideo('bib-' + item.id, item.nome, midiaDeItem(item));
  inserirTrechos([{ ini:0, fim:+(item.dur || 5).toFixed(2), de:k, cheia:true, limpo:!item.react && !item.exportado ? true : false }], onde, 'Inserir ' + item.nome);
}""")
troca_funcao('enviarVideoMontagem', r"""function enviarVideoMontagem(onde, sobrepor){
  escolherArquivo('video/*', function(r, nome, info){
    var k = fonteDeVideo('up-' + r.token.slice(0, 10), nome, { video:r.url, voz:r.url, musica:'', duracao:info.dur || 5, alt:info.w ? ORIG_W * info.h / info.w : 1080 });
    guardaNaBib({ tipo:'video', nome:nome, url:r.url, dur:info.dur, w:info.w, h:info.h });
    if (sobrepor) adicionarCamada(camadaPadrao(k, nome, info.dur, 0));
    else inserirTrechos([{ ini:0, fim:+(info.dur || 5).toFixed(2), de:k, cheia:info.h > info.w }], onde, 'Adicionar vídeo ' + nome); });
}
function guardaNaBib(item){ return api('bib_salvar', { item:item }).then(function(r){ BIB[r.item.id] = r.item; if (aba === 'biblioteca') mostraAba(); return r.item; }).catch(function(){ return null; }); }""")
troca_funcao('adicionarProjeto', r"""async function adicionarProjeto(id, onde){
  var X = await carregarProjeto(id); if (!X) return;
  var novos = (X.trechos || []).map(function(t){ var n = clone(t), dados = t.de ? (X.fontes || {})[t.de] : snapshot(X); delete n.de;
    var k = garantirFonte(X.id, t.de || '', dados); if (k) n.de = k; return n; });
  inserirTrechos(novos, onde, 'Adicionar ' + (X.nome || X.id));
}
async function carregarProjeto(id){
  var X = projetos[id]; if (X && X.trechos) return X;
  try { var r = await api('projeto&id=' + encodeURIComponent(id)); X = r.projeto; projetos[id] = X; return X; } catch (e){ if (e.code !== 'sem_acesso') dialogo('Não consegui abrir o projeto', e.message || '', [['Ok', fecha]]); return null; }
}""")

# ---------- painel Mídia: gravar react, sobrepor, modelos ----------
rep("  C.appendChild(el('div', 'grupo', [el('h3', null, 'Juntar vídeos (montagem)'),",
    """  var gr = el('button', 'btn rec', '● Gravar react ou explicação'); gr.type = 'button'; gr.onclick = abrirEstudio;
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Gravar ao vivo'), gr, el('p', 'nota-ajuda', 'Abre a câmera por cima deste vídeo. Você pausa, volta e avança o fundo enquanto explica, até 5 minutos.')]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Sobrepor um vídeo (react, comparativo)'), el('div', 'linha', [botao('Projeto escolhido abaixo', function(){ sobreporProjetoAsync(proj.value); }), botao('Enviar vídeo e sobrepor', function(){ enviarVideoMontagem('fim', true); })]),
    el('p', 'nota-ajuda', 'Entra no cursor, por cima do vídeo principal. Depois escolha a arrumação: janela no canto, bolha, em cima e embaixo ou lado a lado.')]));
  if (!Object.keys(projetos).some(function(k){ return /^ric-/.test(k); })) C.appendChild(el('div', 'cartao', [el('b', null, 'Cortes da entrevista na RIC'), el('p', 'nota-ajuda', 'Os 5 cortes que o Claude montou. Envie o vídeo da entrevista (e, se tiver, a voz e a música separadas) e eles viram projetos aqui.'), botao('Trazer os cortes', importarModelo)]));
  C.appendChild(el('div', 'grupo', [el('h3', null, 'Juntar vídeos (montagem)'),""")
rep("botao('Enviar vídeo (até 20 MB)', function(){ enviarVideoMontagem(onde.value); })", "botao('Enviar vídeo do aparelho', function(){ enviarVideoMontagem(onde.value); })")

# ---------- janela do + ----------
rep("""        l.appendChild(el('div', 'item', [el('span', 't', d.toFixed(0) + ' s'), el('b', null, (X.nome || id) + (id === P.id ? ' (este)' : '')),
          botao('Adicionar no fim', function(){ fecha(); adicionarProjeto(id, 'fim'); })])); });""",
"""        l.appendChild(el('div', 'item', [el('span', 't', d ? d.toFixed(0) + ' s' : ''), el('b', null, (X.nome || id) + (id === P.id ? ' (este)' : '')),
          el('span', 'linha', [botao('Adicionar no fim', function(){ fecha(); adicionarProjeto(id, 'fim'); }), botao('Sobrepor no cursor', function(){ fecha(); sobreporProjetoAsync(id); })])])); });""")
rep("""        var b = x.tipo === 'video' ? botao('Adicionar no fim', function(){ fecha(); inserirVideoBib(x, 'fim'); })
          : botao('Pôr no fim', function(){ fecha(); porImagem(x, Math.max(0, durFala() - 4)); });""",
"""        var b = x.tipo === 'video' ? el('span', 'acoes', [botao('No fim', function(){ fecha(); inserirVideoBib(x, 'fim'); }), botao('Sobrepor', function(){ fecha(); sobreporBib(x); })])
          : botao('Pôr no fim', function(){ fecha(); porImagem(x, Math.max(0, durFala() - 4)); });""")
rep("botao('Enviar vídeo do computador', function(){ fecha(); enviarVideoMontagem('fim'); }),", "botao('Enviar vídeo do aparelho', function(){ fecha(); enviarVideoMontagem('fim'); }),\n      botao('● Gravar react', function(){ fecha(); abrirEstudio(); }),")

# ---------- biblioteca no servidor ----------
rep("""    el('p', 'nota-ajuda', assets ? 'Imagens e vídeos até 20 MB. Ficam guardados para todos os projetos.' : 'Só quem edita esta página pode enviar arquivos.')]));""",
    """    el('p', 'nota-ajuda', 'Logos, fotos e vídeos (introduções, vinhetas, encerramentos) ficam para toda a equipe, em todos os projetos. Vídeos vão para o Drive da Intus; podem ser enviados pelo celular.')]));""")
rep("""    if (x.tipo === 'video'){ acoes.appendChild(botao('No cursor', function(){ inserirVideoBib(x, 'cursor'); })); acoes.appendChild(botao('Início', function(){ inserirVideoBib(x, 'inicio'); })); acoes.appendChild(botao('Fim', function(){ inserirVideoBib(x, 'fim'); })); }""",
    """    if (x.tipo === 'video'){ acoes.appendChild(botao('No cursor', function(){ inserirVideoBib(x, 'cursor'); })); acoes.appendChild(botao('Fim', function(){ inserirVideoBib(x, 'fim'); })); acoes.appendChild(botao('Sobrepor', function(){ sobreporBib(x); }));
      if (x.react || x.exportado) acoes.appendChild(botao('Abrir para editar', function(){ projetoDeGravacao(x); }));
      acoes.appendChild(botao('Baixar', function(){ var pr = janelaProgresso('Baixando ' + x.nome); arquivoInteiro(x.url).then(function(ab){ fecha(); if (ab) baixarBlob(new Blob([ab], { type:'video/mp4' }), x.nome + '.mp4'); }).catch(function(){ dialogo('Não consegui baixar', '', [['Ok', fecha]]); }); void pr; })); }""")
rep("""el('span', 't', ({ logo:'logo', foto:'foto', video:'vídeo' })[x.tipo] + (x.dur ? ' · ' + x.dur.toFixed(1) + ' s' : '')), acoes])]));""",
    """el('span', 't', ({ logo:'logo', foto:'foto', video:'vídeo' })[x.tipo] + (x.dur ? ' · ' + (+x.dur).toFixed(1) + ' s' : '') + (x.rascunho ? ' · rascunho' : '') + (x.exportado ? ' · exportado' : '') + (x.por_nome ? ' · ' + x.por_nome : '')), acoes])]));""")
troca_funcao('enviarBib', r"""function enviarBib(tipo){
  escolherArquivo(tipo === 'video' ? 'video/*' : 'image/png,image/jpeg,image/webp,image/gif', function(r, nome, info){
    guardaNaBib({ tipo:tipo, nome:nome, url:r.url, w:info.w || 0, h:info.h || 0, dur:info.dur || 0 }).then(function(it){ if (!it) dialogo('O arquivo subiu, mas não entrou na lista', 'Tente de novo.', [['Ok', fecha]]); });
  });
}""")
troca_funcao('apagarBib', r"""function apagarBib(x){
  var ehVideo = x.tipo === 'video';
  dialogo('Tirar "' + x.nome + '" da biblioteca?', 'Projetos que já usam este arquivo continuam funcionando.' + (ehVideo ? ' Se apagar o vídeo também do Drive, esses projetos perdem o vídeo.' : ''), [['Cancelar', fecha]].concat(ehVideo ? [['Tirar e apagar do Drive', function(){ fecha(); tirarBib(x, true); }]] : []).concat([['Tirar da biblioteca', function(){ fecha(); tirarBib(x, false); }]]));
}
function tirarBib(x, apagar){ api('bib_excluir', { id:x.id, apagar_arquivo:!!apagar }).then(function(){ delete BIB[x.id]; mostraAba(); estado('Tirado da biblioteca', 'ok'); }).catch(function(e){ dialogo('Não consegui tirar', e.message || '', [['Ok', fecha]]); }); }""")
troca_funcao('escolherArquivo', r"""function escolherArquivo(accept, pronto){
  var inp = document.createElement('input'); inp.type = 'file'; inp.accept = accept; inp.style.display = 'none'; document.body.appendChild(inp);
  inp.onchange = async function(){ var f = inp.files[0]; setTimeout(function(){ inp.remove(); }, 0); if (!f) return; var nome = f.name.replace(/\.[^.]+$/, '');
    try {
      if (/^image\//.test(f.type)){ estado('Enviando ' + f.name + '...'); var im = await reduzirImagem(f), r = await api('imagem', { dataurl:im.dataurl, nome:nome }); estado('Enviado', 'ok'); pronto({ url:r.url, token:'' }, nome, { w:r.w, h:r.h }); return; }
      if (f.size > (LIMITES.video_bytes || 2147483648)){ dialogo('Arquivo grande demais', 'O limite é ' + Math.round((LIMITES.video_bytes || 2147483648) / 1073741824) + ' GB por vídeo.', [['Ok', fecha]]); return; }
      var info = await sondarVideo(f), prog = janelaProgresso('Enviando ' + f.name + ' (' + (f.size / 1048576).toFixed(0) + ' MB)');
      if (/^video\//.test(f.type) && f.size <= CONV_MAX && await ehHevc(f)){
        if (await leHevc()){ try { var h = await paraH264(f, function(x){ prog(x, 'Preparando a versão que abre em qualquer computador: ' + Math.round(x * 100) + '%'); });
            if (h){ f = new File([h], nome + '.mp4', { type:'video/mp4' }); info = await sondarVideo(f); } } catch (x) { estado('Não consegui converter do formato do iPhone; enviando o original', 'erro'); } }
        else estado('Vídeo no formato do iPhone (HEVC): este computador pode não conseguir mostrá-lo', 'erro');
      }
      var up = await enviarArquivo(f, nome, info, function(x){ prog(x, 'Enviando ao Drive da Intus: ' + Math.round(x * 100) + '%'); });
      BLOBS[up.url] = Promise.resolve(info.local); fecha(); estado('Vídeo enviado', 'ok'); pronto(up, nome, info);
    } catch (e){ if (e && e.code === 'sem_acesso') return; dialogo('O envio não terminou', (e && e.message) || 'Tente de novo.', [['Ok', fecha]]); }
  };
  inp.click();
}""")

# ---------- IA no servidor ----------
rep("b.disabled = !!iaJob || !sampleNs;", "b.disabled = !!iaJob || !IA_OK;")
rep("  if (!sampleNs) C.appendChild(el('p', 'nota-ajuda', 'A IA não está disponível nesta visualização. Abra a página no claude.ai e permita que ela use o Claude.'));",
    "  C.appendChild(el('p', 'nota-ajuda', 'Usa o Claude da Intus (a mesma chave da Central de atendimento). Até ' + (LIMITES.ia_dia || 40) + ' montagens por dia; hoje: ' + (LIMITES.ia_hoje || 0) + '.'));")
rep("  if (!sampleNs || iaJob) return;", "  if (iaJob) return;")
rep("""    var r = await sampleNs.json(prompt, { signal:iaJob.ctrl.signal, cache:false, modelTier:'complex', onText:function(u){ if (iaJob) iaJob.chars = (u.text || '').length; } });""",
    """    var rr = await api('ia', { pedido:prompt, imagens:Q.imagens }); if (!iaJob) return; LIMITES.ia_hoje = (LIMITES.ia_hoje || 0) + 1; var r = rr.resposta;""")
rep("""    var msg = { cancelled:'Cancelado.', not_granted:'A página não tem permissão para usar o Claude. Abra no claude.ai e permita.', rate_limited:'Muitos pedidos agora. Espere um pouco e tente de novo.', invalid_json:'A IA respondeu num formato que não deu para ler. Tente de novo, talvez com orientações mais curtas.', prompt_too_large:'Os vídeos têm fala demais para um pedido só. Deixe menos vídeos na linha do tempo.' }[e && e.code] || ('A IA não conseguiu montar agora (' + ((e && e.code) || 'erro') + ').');
    if (!(e && e.code === 'cancelled')) dialogo('Edição automática não concluída', msg, [['Ok', fecha]]); else estado(msg);""",
"""    if (e && e.code === 'sem_acesso') return;
    dialogo('Edição automática não concluída', (e && e.message) || 'A IA não conseguiu montar agora. Tente de novo.', [['Ok', fecha]]);""")
rep("c.appendChild(botao('Cancelar', function(){ if (iaJob) iaJob.ctrl.abort(); }));", "c.appendChild(botao('Cancelar', function(){ iaJob = null; faixaIA(); mostraAba(); estado('Cancelado'); }));")
rep("+ (iaJob.chars ? ' · roteiro: ' + iaJob.chars + ' caracteres' : '')", "")
troca_funcao('aprovar', r"""function aprovar(){
  if (!P) return; P.ia = P.ia || {}; P.ia.status = 'aprovado'; salvar(); faixaIA();
  dialogo('Aprovado', 'Agora é exportar: o vídeo sai em alta, feito a partir dos vídeos originais, e pode ir direto para o Drive da Intus.', [['Depois', fecha], ['Exportar agora', function(){ fecha(); abreExportar(); }]]);
}""")
rep("fx.appendChild(botao('Aprovar e pedir versão em alta', aprovar));", "fx.appendChild(botao('Aprovar e exportar', aprovar));")

# ---------- abrir, salvar, novo ----------
troca_funcao('abrir', r"""async function abrir(id){
  if (histTimer){ clearTimeout(histTimer); histPush(); }
  var X = await carregarProjeto(id); if (!X) return;
  pausar(); P = X; $('vazio').hidden = true; T = 0; selTrecho = 0; lacunaSel = null; trechoAtual = -1; selEl = null;
  P.midia = P.midia || {}; P.textos = P.textos || []; P.imagens = P.imagens || []; P.sombras = P.sombras || []; P.camadas = P.camadas || []; aplicaFormato();
  var M = midiaDe('');
  if (M.v.readyState >= 1 && !P.midia.duracao) P.midia.duracao = M.v.duration;
  else M.v.addEventListener('loadedmetadata', function f(){ M.v.removeEventListener('loadedmetadata', f); if (P && !P.midia.duracao && isFinite(M.v.duration)) P.midia.duracao = M.v.duration; desenhaTimeline(); irPara(T); });
  recalc(); nomesSelect(); histAbrir(); desenhaTimeline(); mostraAba(); faixaIA(); irPara(0); garanteFontes(); try { localStorage.setItem('intuscut-ultimo', id); } catch (e) {}
}""")
troca_funcao('salvar', r"""var salvando = null, salvarDeNovo = false;
function salvar(){
  if (!P) return; if (salvando){ salvarDeNovo = true; return; }
  var meu = P; meu.atualizado_em = new Date().toISOString(); estado('Salvando...');
  salvando = api('salvar', { projeto:meu }).then(function(){ if (meu === P) estado('Salvo', 'ok'); })
    .catch(function(e){ if (e.code !== 'sem_acesso') estado('Não salvou: ' + (e.message || 'sem conexão') + '. Tentando de novo...', 'erro'); salvarDeNovo = true; })
    .then(function(){ salvando = null; if (salvarDeNovo){ salvarDeNovo = false; clearTimeout(salvarTimer); salvarTimer = setTimeout(salvar, 2500); } });
}""")
rep("""$('b-novo').onclick = function(){
  if (!assets){ dialogo('Envio de vídeo indisponível', 'Só quem edita esta página pode enviar vídeos. Mande o vídeo ao Claude, que ele prepara o projeto com legenda e música separada.', [['Ok', fecha]]); return; }
  escolherArquivo('video/mp4,video/quicktime,video/webm', function(r, nome, info){ var id = novoId('video-'), d = info.dur, vert = info.h > info.w;""",
"""$('b-novo').onclick = function(){
  escolherArquivo('video/*', function(r, nome, info){ var id = novoId('video-'), d = info.dur || 5, vert = info.h > info.w; guardaNaBib({ tipo:'video', nome:nome, url:r.url, dur:info.dur, w:info.w, h:info.h });""")
rep("final:{ ativo:true, chamada:'SIGA PARA MAIS DICAS', slogan:'Te levamos ao próximo nível. Do iniciante ao atleta.' }, midia:{ video:r.url, voz:r.url, musica:'', duracao:d, asset:r.id, alt:info.w ? ORIG_W * info.h / info.w : 1080 } };",
    "camadas:[], final:{ ativo:true, chamada:'SIGA PARA MAIS DICAS', slogan:'Te levamos ao próximo nível. Do iniciante ao atleta.' }, midia:{ video:r.url, voz:r.url, musica:'', duracao:d, alt:info.w ? ORIG_W * info.h / info.w : 1080 } };")
rep("""  cv.toBlob(function(b){ if (downloads) downloads.save({ filename:(P.id || 'video') + '-' + T.toFixed(1) + 's.png', data:b }).catch(function(){});
    else { var i = el('img'); i.src = URL.createObjectURL(b); i.style.width = '100%'; dialogo('Quadro', 'Clique com o botão direito para salvar.', [['Fechar', fecha]], i); } }, 'image/png'); };""",
"""  cv.toBlob(function(b){ baixarBlob(b, (P.id || 'video') + '-' + T.toFixed(1) + 's.png'); }, 'image/png'); };""")

# ---------- exportar: WebCodecs ou gravação em tempo real; baixar ou Drive da Intus ----------
troca_funcao('abreExportar', r"""function abreExportar(){
  if (!P) return; pausar();
  var temWC = !!(window.VideoEncoder && window.AudioEncoder && window.Mp4Muxer);
  var q = sel([['alta', 'Alta qualidade'], ['leve', 'Leve (para mandar por WhatsApp)']], 'alta'), box = el('div', 'grupo');
  box.appendChild(el('div', 'linha', [campo('Qualidade', q), el('span', 't', formato().n + ' · ' + W + 'x' + H + ' · ' + dur().toFixed(1) + ' s')]));
  box.appendChild(el('p', 'nota-ajuda', temWC ? 'Feito a partir dos vídeos originais. Um vídeo de 1 minuto leva de 1 a 3 minutos.' : 'Este navegador grava o vídeo enquanto ele toca: leva o tempo do vídeo, com som. Deixe a tela ligada.'));
  dialogo('Exportar', '', [['Fechar', fecha], ['Exportar', function(){ exportar(q.value); }]], box);
}""")
troca_funcao('baixarUrl', "function baixarUrl(url, nome){ arquivoInteiro(url).then(function(ab){ if (ab) baixarBlob(new Blob([ab], { type:'video/mp4' }), nome); }).catch(function(){ estado('Não consegui baixar', 'erro'); }); }")
rep("  if (!window.VideoEncoder || !window.AudioEncoder || !window.Mp4Muxer){ dialogo('Exportação indisponível neste navegador', 'Use o Chrome ou o Edge no computador, ou peça a versão em alta ao Claude.', [['Ok', fecha]]); return; }",
    "  if (!window.VideoEncoder || !window.AudioEncoder || !window.Mp4Muxer){ exportarTempoReal(qual); return; }")
rep("var vbr = qual === 'drive' ? Math.max(600000, Math.floor(15 * 1048576 * 8 / d) - 140000) : 8000000; vbr = Math.min(vbr, 9000000);", "var vbr = qual === 'leve' ? 2500000 : (W * H > 2e6 ? 10000000 : 8000000);")
rep("bitrate:qual === 'drive' ? 96000 : 160000", "bitrate:qual === 'leve' ? 96000 : 160000")
rep("      var t = k / FPS; if (t < DF){ var f = fonteEm(t); if (f) await esperaSeek(midiaDe(f.de).v, f.ts); }\n      desenha(c2, t, null);",
    "      var t = k / FPS; if (t < DF){ var f = fonteEm(t); if (f) await esperaSeek(midiaDe(f.de).v, f.ts); }\n      for (var ci = 0; ci < P.camadas.length; ci++){ var kc = P.camadas[ci]; if (t >= kc.ini && t <= kc.fim) await esperaSeek(camadaMid(kc.de).v, srcCamada(kc, t)); }\n      desenha(c2, t, null);")
rep("      var buf = async function(url){ if (!url) return null; if (!(url in cache)){ try { cache[url] = await ac.decodeAudioData(await (await fetch(url)).arrayBuffer()); } catch (e) { cache[url] = null; } } return cache[url]; };",
    "      var buf = async function(url){ if (!url) return null; if (!(url in cache)){ try { info.textContent = 'Áudio: lendo os arquivos...'; cache[url] = await ac.decodeAudioData(await arquivoInteiro(url)); } catch (e) { cache[url] = null; } } return cache[url]; };")
rep("        pos += n; }\n      ac.close();",
    """        pos += n; }
      for (var cq = 0; cq < P.camadas.length; cq++){ var kq = P.camadas[cq], mq = fonte(kq.de).midia || {}, bq = await buf(mq.voz || mq.video), vq = kq.volume == null ? .8 : kq.volume; if (!bq || !vq) continue;
        var a0 = Math.round(kq.ini * SR), nq = Math.round((Math.min(kq.fim, d) - kq.ini) * SR), s0 = Math.round(kq.src * SR);
        for (var iq = 0; iq < nq; iq++){ var jq = s0 + iq, oq = a0 + iq; if (jq >= bq.length || oq >= total) break; var eq = Math.min(1, iq / rampa, (nq - iq) / rampa), lq = bq.getChannelData(0)[jq], rq = bq.numberOfChannels > 1 ? bq.getChannelData(1)[jq] : lq; L[oq] += lq * vq * eq; R[oq] += rq * vq * eq; } }
      ac.close();""")
rep("""    dialogo('Vídeo pronto (' + mb + ' MB)', temAac ? '' : 'Este navegador não grava áudio AAC: o vídeo saiu sem som.', [['Fechar', fecha], ['Salvar no Drive', function(){ salvarDrive(blob, nome, P.id); }], ['Baixar', function(){ if (downloads) downloads.save({ filename:nome, data:blob }).catch(function(){}); }]], vid);""",
    """    prontoExportado(blob, nome, mb, temAac ? '' : 'Este navegador não grava áudio AAC: o vídeo saiu sem som.', d);""")
troca_funcao('salvarDrive', r"""async function salvarDrive(blob, nome, d){
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
}""")
troca_funcao('pastaDrive', "")

# ---------- carregar: login do painel e dados do servidor ----------
m = re.search(r'^function carregarLocais\(\)', js, re.M)
js = js[:m.start()] + r"""function pronto(){
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
window.addEventListener('beforeunload', function(e){ if (salvando || salvarTimer && $('estado').textContent !== 'Salvo'){ salvar(); } if (EST && EST.rec){ e.preventDefault(); e.returnValue = ''; } });
(async function(){
  if (!TOKEN){ semAcesso(401); return; }
  try {
    var r = await api('inicio'); EU = r.eu; LIMITES = r.limites || {};
    (r.projetos || []).forEach(function(p){ projetos[p.id] = { id:p.id, nome:p.nome, formato:p.formato, atualizado_em:p.atualizado_em, _parcial:true }; });
    BIB = {}; (r.biblioteca || []).forEach(function(x){ BIB[x.id] = x; });
    if (!r.drive) estado('O Drive não está configurado: dá para editar, mas não enviar vídeos', 'erro');
    pronto(); if (r.drive) estado(EU && EU.nome ? 'Olá, ' + EU.nome.split(' ')[0] : 'Pronto', 'ok');
    sincronizarClaude(r.projetos || []);
  } catch (e){ if (e.code !== 'sem_acesso') { estado('Sem conexão com o servidor', 'erro'); var v = $('vazio'); v.hidden = false; v.textContent = 'Não consegui falar com o servidor da Intus. Confira a internet e recarregue a página.'; } }
})();
})();
"""

# carregarProjeto precisa reconhecer o resumo da lista como "ainda não carregado"
rep("  var X = projetos[id]; if (X && X.trechos) return X;", "  var X = projetos[id]; if (X && X.trechos && !X._parcial) return X;")
# atalhos do teclado do editor não valem com o estúdio aberto
rep("  if (!P || !$('modal').hidden) return;\n  var emCampo", "  if (!P || !$('modal').hidden || EST) return;\n  var emCampo")
# sobrepor projeto carregando sob demanda
novo2 = novo.replace("function sobreporProjeto(id){ var X = projetos[id]; if (!X) return;", "async function sobreporProjetoAsync(id){ var X = await carregarProjeto(id); if (!X) return;")

# estúdio: guardar a gravação no Drive (um arquivo só) e a biblioteca no servidor
i0 = novo2.index('// O vídeo gravado pode passar de 20 MB')
i1 = novo2.index('function midiaDeItem(')
novo2 = novo2[:i0] + r"""async function guardarGravacao(blob, d, nome, tipo, rascunho){
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
""" + novo2[i1:]
novo2 = novo2.replace("""function midiaDeItem(item){ var m = { duracao:item.dur || 5, alt:item.w && item.h ? ORIG_W * item.h / item.w : 1080, musica:'' };
  if (item.partes && item.partes.length > 1){ m.partes = item.partes.slice(); m.tipo = item.mime || 'video/mp4'; } else { m.video = item.url; m.voz = item.url; } return m; }""",
"""function midiaDeItem(item){ return { video:item.url, voz:item.url, musica:'', duracao:item.dur || 5, alt:item.w && item.h ? ORIG_W * item.h / item.w : 1080 }; }""")
novo2 = novo2.replace("""  if (item.id && BIB[item.id] && BIB[item.id].rascunho){ BIB[item.id].rascunho = false; if (db) db.doc('biblioteca/' + item.id).update({ rascunho:false }).catch(function(){}); }
  abrir(id); salvar(); estado('Gravação aberta para edição', 'ok');""",
"""  if (item.id && BIB[item.id] && BIB[item.id].rascunho){ var cp = Object.assign({}, BIB[item.id], { rascunho:false }); guardaNaBib(cp); }
  abrir(id).then(function(){ salvar(); estado('Gravação aberta para edição', 'ok'); });""")
novo2 = novo2.replace("['Baixar uma cópia', function(){ if (downloads) downloads.save({ filename:nome.replace(/[\\/:]/g, '-') + '.' + ext, data:blob }).catch(function(){}); }],",
                      "['Baixar uma cópia', function(){ baixarBlob(blob, nome.replace(/[\\/:]/g, '-') + '.' + ext); }],")
assert 'downloads' not in novo2 and 'PARTES' not in novo2 and ' db.' not in novo2, [l for l in novo2.split('\n') if 'downloads' in l or 'PARTES' in l or ' db.' in l]
novo2 = novo2.replace("O navegador ou o claude.ai não deixou esta página usar a câmera e o microfone. Confira a permissão de câmera do navegador para o claude.ai. Se continuar bloqueado, avise o Claude: ele monta o mesmo estúdio no site da Intus, onde a câmera funciona.",
                      "O navegador não deixou usar a câmera e o microfone. Toque no cadeado ao lado do endereço do site e permita câmera e microfone para intusfit.com.br.")

# junta: o código novo entra antes do teclado
m = js.index('// ---------- teclado e transporte ----------')
js = js[:m] + extra + '\n' + novo2 + '\n' + js[m:]

for proibido in ['claude.use', 'sampleNs', 'downloads.save', 'db.doc', 'db.collection', 'RENDERS[']:
    restos = [l for l in js.split('\n') if proibido in l]
    assert not restos, (proibido, restos[:3])
open(S + 'site.js', 'w').write(js)
print('ok', len(js))
