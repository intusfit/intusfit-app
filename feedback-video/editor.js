// Edição do vídeo gravado, direto no navegador: cortar trechos (pontas e meio) e
// reorganizar o MP4 pra tocar rápido. Usado por /feedback-video/.
//
// Como funciona: o vídeo gravado (MP4 do MediaRecorder) é aberto com mp4box.js; o vídeo
// é decodificado e codificado de novo SÓ nos trechos que ficam (WebCodecs, com a placa de
// vídeo, bem mais rápido que o tempo do vídeo) e o áudio é copiado quadro a quadro, sem
// recodificar; mp4-muxer monta o MP4 final. O arquivo novo sai "progressivo" (índice no
// começo, duração certa), então também abre rápido no Chrome/Edge/Opera — o MP4 que o
// MediaRecorder gera obriga esses navegadores a varrer o arquivo todo pra achar a duração.
(function () {
  'use strict';

  var LIB_MP4BOX = 'https://cdn.jsdelivr.net/npm/mp4box@0.5.2/dist/mp4box.all.min.js';
  var LIB_MUXER = 'https://cdn.jsdelivr.net/npm/mp4-muxer@5.1.3/build/mp4-muxer.js';
  var BPS_REENCODE = 1000000;     // a gravação sai a ~0,65 Mbps; folga pra não perder qualidade ao recodificar
  var CHAVE_A_CADA = 150;         // quadros entre quadros-chave (10 s a 15 fps)

  function suportado() {
    return !!(window.VideoDecoder && window.VideoEncoder && window.EncodedVideoChunk && window.VideoFrame);
  }

  var promessasLib = {};
  function carregarScript(url, global) {
    if (window[global]) return Promise.resolve();
    if (promessasLib[url]) return promessasLib[url];
    promessasLib[url] = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = url;
      s.onload = function () { window[global] ? resolve() : reject(new Error('biblioteca de edição carregou sem o esperado')); };
      s.onerror = function () { promessasLib[url] = null; reject(new Error('não consegui baixar a biblioteca de edição (confira a internet)')); };
      document.head.appendChild(s);
    });
    return promessasLib[url];
  }
  function carregarLibs() {
    return Promise.all([carregarScript(LIB_MP4BOX, 'MP4Box'), carregarScript(LIB_MUXER, 'Mp4Muxer')]);
  }

  function pausa(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  // ---------- ler o MP4 ----------
  function lerMp4(blob) {
    return blob.arrayBuffer().then(function (ab) {
      return new Promise(function (resolve, reject) {
        var arq = MP4Box.createFile(), amostras = {}, info = null;
        arq.onError = function () { reject(new Error('o arquivo de vídeo não pôde ser lido')); };
        arq.onReady = function (i) {
          info = i;
          i.tracks.forEach(function (t) {
            if (t.video || t.audio) { amostras[t.id] = []; arq.setExtractionOptions(t.id, null, { nbSamples: 1000 }); }
          });
          arq.start();
        };
        arq.onSamples = function (id, u, lista) { for (var k = 0; k < lista.length; k++) amostras[id].push(lista[k]); };
        ab.fileStart = 0;
        arq.appendBuffer(ab);
        arq.flush();
        setTimeout(function () {
          if (!info) { reject(new Error('o arquivo de vídeo não tem o formato esperado (precisa ser MP4)')); return; }
          var vt = info.tracks.filter(function (t) { return t.video; })[0];
          var at = info.tracks.filter(function (t) { return t.audio; })[0];
          if (!vt || !amostras[vt.id] || !amostras[vt.id].length) { reject(new Error('o vídeo não tem imagem')); return; }
          resolve({ arq: arq, vt: vt, at: at, vid: amostras[vt.id], aud: at ? (amostras[at.id] || []) : [] });
        }, 0);
      });
    });
  }

  function caixaDescricao(arq, trackId, nomes) {
    var trak = arq.getTrackById(trackId), entradas = trak.mdia.minf.stbl.stsd.entries;
    for (var i = 0; i < entradas.length; i++) {
      for (var j = 0; j < nomes.length; j++) {
        var box = entradas[i][nomes[j]];
        if (box) return box;
      }
    }
    return null;
  }
  function descricaoVideo(arq, trackId) {
    var box = caixaDescricao(arq, trackId, ['avcC', 'hvcC']);
    if (!box) return null;
    var ds = new DataStream(undefined, 0, DataStream.BIG_ENDIAN);
    box.write(ds);
    return new Uint8Array(ds.buffer, 8);   // sem o cabeçalho da caixa (8 bytes)
  }
  function descricaoAudio(arq, trackId) {
    var esds = caixaDescricao(arq, trackId, ['esds']);
    try { return new Uint8Array(esds.esd.descs[0].descs[0].data); } catch (e) { return null; }
  }

  function segs(am, campo) { return am[campo] / am.timescale; }

  // ---------- cortes ----------
  // cortes: [{ini, fim}] em segundos, no tempo do vídeo atual. Junta sobrepostos.
  function normalizarCortes(cortes, duracao) {
    var l = (cortes || []).map(function (c) { return { ini: Math.max(0, Math.min(c.ini, c.fim)), fim: Math.min(duracao, Math.max(c.ini, c.fim)) }; })
      .filter(function (c) { return c.fim - c.ini > 0.001; })
      .sort(function (a, b) { return a.ini - b.ini; });
    var out = [];
    l.forEach(function (c) {
      var u = out[out.length - 1];
      if (u && c.ini <= u.fim) u.fim = Math.max(u.fim, c.fim); else out.push({ ini: c.ini, fim: c.fim });
    });
    return out;
  }
  // tempo no vídeo novo, ou null se o instante cai dentro de um corte
  function criarMapa(cortes) {
    return function (t) {
      var tirado = 0;
      for (var i = 0; i < cortes.length; i++) {
        if (t < cortes[i].ini) break;
        if (t < cortes[i].fim) return null;
        tirado += cortes[i].fim - cortes[i].ini;
      }
      return t - tirado;
    };
  }

  // ---------- aplicar cortes ----------
  // opts.copiar = true: não recodifica nada (só reorganiza o MP4; ignora cortes).
  function aplicarCortes(blob, cortesBrutos, aoProgredir, opts) {
    opts = opts || {};
    var progresso = aoProgredir || function () {};
    var estado = {};
    return carregarLibs().then(function () { return lerMp4(blob); }).then(function (m) {
      estado.m = m;
      var vt = m.vt, at = m.at, vid = m.vid, aud = m.aud;
      var t0 = Math.min(segs(vid[0], 'cts'), aud.length ? segs(aud[0], 'cts') : Infinity);
      var ultimo = vid[vid.length - 1];
      var duracao = segs(ultimo, 'cts') + segs(ultimo, 'duration') - t0;
      var cortes = opts.copiar ? [] : normalizarCortes(cortesBrutos, duracao);
      var mapa = criarMapa(cortes);
      var W = vt.video.width, H = vt.video.height;

      var muxer = new Mp4Muxer.Muxer({
        target: new Mp4Muxer.ArrayBufferTarget(),
        video: { codec: 'avc', width: W, height: H },
        audio: at ? { codec: 'aac', numberOfChannels: at.audio.channel_count, sampleRate: at.audio.sample_rate } : undefined,
        fastStart: 'in-memory',
        firstTimestampBehavior: 'cross-track-offset'
      });
      estado.muxer = muxer;

      // ----- áudio: copia os quadros que ficam, sem recodificar -----
      if (at) {
        var asc = descricaoAudio(m.arq, at.id), primeiroAudio = true;
        aud.forEach(function (a) {
          var t = segs(a, 'cts') - t0, d = segs(a, 'duration');
          var ini = mapa(t), fim = mapa(t + d - 0.0005);
          if (ini === null || fim === null || fim - ini > d * 1.5) return;
          var meta = primeiroAudio ? { decoderConfig: { codec: at.codec, numberOfChannels: at.audio.channel_count, sampleRate: at.audio.sample_rate, description: asc || undefined } } : undefined;
          primeiroAudio = false;
          muxer.addAudioChunkRaw(a.data, 'key', Math.round(ini * 1e6), Math.round(d * 1e6), meta);
        });
      }

      // ----- vídeo -----
      var descVideo = descricaoVideo(m.arq, vt.id);
      if (opts.copiar) {
        var primeiroVideo = true;
        vid.forEach(function (a, i) {
          var t = segs(a, 'cts') - t0, d = segs(a, 'duration');
          var meta = primeiroVideo ? { decoderConfig: { codec: vt.codec, codedWidth: W, codedHeight: H, description: descVideo || undefined } } : undefined;
          primeiroVideo = false;
          muxer.addVideoChunkRaw(a.data, a.is_sync ? 'key' : 'delta', Math.round(t * 1e6), Math.round(d * 1e6), meta);
          if (i % 500 === 0) progresso(i / vid.length);
        });
        return finalizar(estado, duracao, progresso);
      }
      return recodificarVideo(estado, vt, vid, t0, mapa, descVideo, W, H, progresso).then(function () {
        var tirado = cortes.reduce(function (s, c) { return s + (c.fim - c.ini); }, 0);
        return finalizar(estado, duracao - tirado, progresso);
      });
    });
  }

  function recodificarVideo(estado, vt, vid, t0, mapa, descVideo, W, H, progresso) {
    var erro = null, precisaChave = true, desdeChave = 0, feitos = 0;
    var area = W * H;
    var codec = area <= 921600 ? 'avc1.42001f' : area <= 2073600 ? 'avc1.420028' : 'avc1.420033';
    var cfgEnc = { codec: codec, width: W, height: H, bitrate: BPS_REENCODE, framerate: 15, latencyMode: 'quality', avc: { format: 'avc' } };
    return VideoEncoder.isConfigSupported(cfgEnc).then(function (r) {
      if (!r.supported) throw new Error('este navegador não consegue recodificar o vídeo');
      var cfgDec = { codec: vt.codec, codedWidth: W, codedHeight: H, description: descVideo || undefined };
      return VideoDecoder.isConfigSupported(cfgDec).then(function (d) {
        if (!d.supported) throw new Error('este navegador não consegue ler o vídeo gravado');
        var enc = new VideoEncoder({
          output: function (chunk, meta) { try { estado.muxer.addVideoChunk(chunk, meta); } catch (e) { erro = e; } },
          error: function (e) { erro = e; }
        });
        enc.configure(cfgEnc);
        var dec = new VideoDecoder({
          output: function (frame) {
            try {
              var tm = mapa(frame.timestamp / 1e6 - t0);
              if (tm === null) { precisaChave = true; frame.close(); return; }
              var novo = new VideoFrame(frame, { timestamp: Math.round(tm * 1e6) });
              frame.close();
              var chave = precisaChave || desdeChave >= CHAVE_A_CADA;
              enc.encode(novo, { keyFrame: chave });
              novo.close();
              if (chave) { precisaChave = false; desdeChave = 0; } else desdeChave++;
            } catch (e) { erro = e; }
          },
          error: function (e) { erro = e; }
        });
        dec.configure(cfgDec);
        estado.dec = dec; estado.enc = enc;

        var i = 0;
        function alimentar() {
          if (erro) throw erro;
          if (i >= vid.length) return dec.flush().then(function () { return enc.flush(); }).then(function () { if (erro) throw erro; });
          if (dec.decodeQueueSize > 10 || enc.encodeQueueSize > 10) return pausa(5).then(alimentar);
          var lote = Math.min(vid.length, i + 20);
          for (; i < lote; i++) {
            var a = vid[i];
            dec.decode(new EncodedVideoChunk({
              type: a.is_sync ? 'key' : 'delta',
              timestamp: Math.round(a.cts * 1e6 / a.timescale),
              duration: Math.round(a.duration * 1e6 / a.timescale),
              data: a.data
            }));
          }
          feitos = i; progresso(feitos / vid.length);
          return pausa(0).then(alimentar);
        }
        return alimentar();
      });
    }).then(function () {
      try { estado.dec.close(); estado.enc.close(); } catch (e) {}
    }, function (e) {
      try { estado.dec && estado.dec.close(); estado.enc && estado.enc.close(); } catch (e2) {}
      throw e;
    });
  }

  function finalizar(estado, duracao, progresso) {
    estado.muxer.finalize();
    var blob = new Blob([estado.muxer.target.buffer], { type: 'video/mp4' });
    progresso(1);
    return { blob: blob, duracao: duracao };
  }

  // ---------- onda sonora (pra achar as palavras na linha do tempo) ----------
  // Devolve { porSeg, picos: Uint8Array (0-255) } ou null se não deu pra decodificar o áudio.
  function picos(blob, porSeg) {
    porSeg = porSeg || 50;
    var Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return Promise.resolve(null);
    var ctx;
    try { ctx = new Ctx({ sampleRate: 8000 }); } catch (e) { return Promise.resolve(null); }
    return blob.arrayBuffer().then(function (ab) { return ctx.decodeAudioData(ab); }).then(function (buf) {
      var sr = buf.sampleRate, n = buf.length, canais = [];
      for (var c = 0; c < buf.numberOfChannels; c++) canais.push(buf.getChannelData(c));
      var bin = Math.max(1, Math.floor(sr / porSeg)), total = Math.ceil(n / bin), out = new Uint8Array(total), max = 0, tmp = new Float32Array(total);
      for (var b = 0; b < total; b++) {
        var m = 0, fim = Math.min(n, (b + 1) * bin);
        for (var i = b * bin; i < fim; i++) for (var k = 0; k < canais.length; k++) { var v = Math.abs(canais[k][i]); if (v > m) m = v; }
        tmp[b] = m; if (m > max) max = m;
      }
      for (var q = 0; q < total; q++) out[q] = max ? Math.min(255, Math.round(Math.sqrt(tmp[q] / max) * 255)) : 0;
      ctx.close();
      return { porSeg: sr / bin, picos: out, duracao: buf.duration };
    }).then(function (r) { return r; }, function () { try { ctx.close(); } catch (e) {} return null; });
  }

  window.IntusEditorVideo = {
    suportado: suportado,
    carregarLibs: carregarLibs,
    aplicarCortes: aplicarCortes,
    otimizar: function (blob, aoProgredir) { return aplicarCortes(blob, [], aoProgredir, { copiar: true }); },
    picos: picos
  };
})();
