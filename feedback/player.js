// Toca o vídeo de um feedback (feedbacks.php?action=video). Usado pela página do
// link (/feedback/) e pela área "Feedbacks" do app do aluno — uma lógica só.
//
// Por quê não é só <video src>: o vídeo é gravado no navegador como MP4
// "fragmentado" (sem índice no começo). O Chrome/Edge/Opera, pra descobrir a
// duração, pulam de fragmento em fragmento pelo arquivo inteiro, e cada pulo é um
// pedido ao servidor (que busca no Google Drive, ~1-3 s cada). Num vídeo de
// alguns minutos são dezenas de pulos: a tela ficava em "carregando" pra sempre.
// iPhone/Safari não faz isso e abria normal. Aqui, no Chromium com MP4, baixamos
// o arquivo em faixas (3 em paralelo) e tocamos de um Blob, mostrando o andamento.
// Nos demais navegadores tenta o jeito normal e, se não abrir em 8 s, cai nesse.
(function () {
  'use strict';

  var FAIXA = 4 * 1024 * 1024;      // igual ao teto por pedido do servidor (FB_FAIXA_VIDEO)
  var PARALELO = 3;
  var ESPERA_NATIVO_MS = 8000;
  var ESPERA_BLOB_MS = 15000;

  function motorChromium() {
    var ua = navigator.userAgent || '';
    return /Chrome\//.test(ua) && !/CriOS|FxiOS/.test(ua);
  }

  function aguardarMetadados(v, ms) {
    return new Promise(function (resolve) {
      var feito = false, t = null;
      function fim(r) {
        if (feito) return;
        feito = true; clearTimeout(t);
        v.removeEventListener('loadedmetadata', ok);
        v.removeEventListener('error', erro);
        resolve(r);
      }
      function ok() { fim('ok'); }
      function erro() { fim('erro'); }
      v.addEventListener('loadedmetadata', ok);
      v.addEventListener('error', erro);
      if (ms) t = setTimeout(function () { fim('tempo'); }, ms);
      if (v.readyState >= 1) fim('ok');
    });
  }

  // WebM gravado no navegador vem sem duração no cabeçalho; pular pro fim uma vez corrige a barra.
  function corrigirDuracao(v) {
    if (v.duration !== Infinity) return;
    v.currentTime = 1e101;
    v.addEventListener('timeupdate', function g() { v.removeEventListener('timeupdate', g); v.currentTime = 0; });
  }

  function pedirFaixa(url, ini, fim) {
    return fetch(url, { headers: { Range: 'bytes=' + ini + '-' + fim } }).then(function (r) {
      if (r.status !== 206 && r.status !== 200) throw new Error('http ' + r.status);
      var cr = /bytes (\d+)-(\d+)\/(\d+)/.exec(r.headers.get('Content-Range') || '');
      return r.arrayBuffer().then(function (buf) {
        return { buf: buf, total: cr ? parseInt(cr[3], 10) : buf.byteLength, inteiro: r.status === 200 };
      });
    });
  }

  function baixarTudo(url, mime, aoProgredir) {
    return pedirFaixa(url, 0, FAIXA - 1).then(function (primeira) {
      if (primeira.inteiro) return new Blob([primeira.buf], { type: mime });
      var total = primeira.total;
      var qtd = Math.ceil(total / FAIXA);
      var partes = new Array(qtd);
      var baixados = 0, proximo = 1, falha = null;

      function progresso() { if (aoProgredir) aoProgredir(Math.min(1, baixados / total)); }

      // Uma faixa pode chegar menor que o pedido; pede o resto até completar.
      function faixa(i, jaTem) {
        var ini = i * FAIXA, fim = Math.min(ini + FAIXA, total) - 1;
        var got = jaTem ? [jaTem] : [], pos = ini + (jaTem ? jaTem.byteLength : 0), tentativas = 0;
        function passo() {
          if (pos > fim) return Promise.resolve();
          return pedirFaixa(url, pos, fim).then(function (r) {
            if (!r.buf.byteLength) throw new Error('faixa vazia');
            got.push(r.buf); pos += r.buf.byteLength; baixados += r.buf.byteLength; tentativas = 0;
            progresso();
          }).catch(function (e) {
            if (++tentativas > 2) throw e;
          }).then(passo);
        }
        return passo().then(function () { partes[i] = new Blob(got); });
      }

      baixados = primeira.buf.byteLength;
      progresso();
      function trabalhador() {
        if (falha || proximo >= qtd) return Promise.resolve();
        var i = proximo++;
        return faixa(i).then(trabalhador, function (e) { falha = e; });
      }
      var fila = [faixa(0, primeira.buf).catch(function (e) { falha = e; })];
      for (var k = 0; k < PARALELO; k++) fila.push(trabalhador());
      return Promise.all(fila).then(function () {
        if (falha) throw falha;
        return new Blob(partes, { type: mime });
      });
    });
  }

  // v: elemento <video>; url: endereço do vídeo; opts.mime: tipo do arquivo;
  // opts.aoMudarEstado(texto|null): avisos de andamento ("Preparando o vídeo... 40%").
  // Devolve uma Promise que resolve quando o player abriu e rejeita se não tocou.
  function carregar(v, url, opts) {
    opts = opts || {};
    var mime = String(opts.mime || 'video/mp4').replace(/;.*$/, '');
    var avisar = opts.aoMudarEstado || function () {};
    var pularNativo = motorChromium() && /mp4/.test(mime);

    var tentativa = pularNativo ? Promise.resolve('pulou') : (function () {
      v.src = url;
      return aguardarMetadados(v, ESPERA_NATIVO_MS);
    })();

    return tentativa.then(function (r) {
      if (r === 'ok') { corrigirDuracao(v); return 'nativo'; }
      v.removeAttribute('src');
      v.load();
      avisar('Preparando o vídeo...');
      return baixarTudo(url, mime, function (p) { avisar('Preparando o vídeo... ' + Math.round(p * 100) + '%'); })
        .then(function (blob) {
          v.src = URL.createObjectURL(blob);
          return aguardarMetadados(v, ESPERA_BLOB_MS);
        })
        .then(function (r2) {
          if (r2 !== 'ok') throw new Error('o navegador não abriu o vídeo');
          corrigirDuracao(v);
          avisar(null);
          return 'blob';
        });
    });
  }

  window.IntusFeedbackPlayer = { carregar: carregar };
})();
