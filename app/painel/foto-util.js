// Prepara fotos pra enviar: abre qualquer formato comum (inclusive HEIC do iPhone), respeita a
// orientação da câmera, reduz pra no máximo 1600 px no lado maior e devolve JPEG (data URL).
// Usado pelo cadastro do aluno (alunos.html > "Fotos de avaliação"). HEIC: o navegador não abre
// sozinho, então libheif-js é baixado sob demanda (~2 MB, só na 1ª foto HEIC) — é a mesma
// técnica de /relatorios-fotos/ (lá tem o motivo de não usar o heic2any).
(function () {
  'use strict';
  var LIBHEIF_URL = 'https://cdn.jsdelivr.net/npm/libheif-js@1.23.2/libheif-wasm/libheif-bundle.js';
  // SRI: o navegador só executa o arquivo se ele for byte a byte o do pacote libheif-js@1.23.2 (conferido em testes/terceiros.js).
  var LIBHEIF_SRI = 'sha384-39BRDvCV5eZ5AgrY3Nr01nkQ/yIWMpfKufwt1PG2rnp3m/0IfzgyMosM0NUb7Pmf';
  var EXT_OK = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'gif', 'bmp'];

  function extensao(f) { var m = /\.([a-z0-9]+)$/i.exec((f && f.name) || ''); return m ? m[1].toLowerCase() : ''; }
  function ehImagem(f) { return !!f && ((f.type && f.type.indexOf('image/') === 0) || EXT_OK.indexOf(extensao(f)) !== -1); }
  function ehHeic(f) { var e = extensao(f); return f.type === 'image/heic' || f.type === 'image/heif' || e === 'heic' || e === 'heif'; }

  var libPromessa = null;
  function carregarLibheif() {
    if (libPromessa) return libPromessa;
    libPromessa = new Promise(function (resolve, reject) {
      if (typeof window.__libheifFactory === 'function') { resolve(window.__libheifFactory()); return; }
      var modAnt = window.module, expAnt = window.exports;
      window.module = { exports: {} }; window.exports = window.module.exports;
      var s = document.createElement('script');
      s.src = LIBHEIF_URL;
      s.integrity = LIBHEIF_SRI; s.crossOrigin = 'anonymous';
      s.onload = function () {
        var fab = window.module && window.module.exports;
        window.module = modAnt; window.exports = expAnt;
        if (typeof fab !== 'function') { reject(new Error('formato inesperado da biblioteca HEIC')); return; }
        window.__libheifFactory = fab; resolve(fab());
      };
      s.onerror = function () { window.module = modAnt; window.exports = expAnt; libPromessa = null; reject(new Error('não consegui baixar a biblioteca de conversão HEIC (confira a internet)')); };
      document.head.appendChild(s);
    });
    return libPromessa;
  }

  // HEIC -> canvas com a imagem inteira
  function heicParaCanvas(file) {
    return file.arrayBuffer().then(function (buf) {
      return carregarLibheif().then(function (lib) {
        var imgs = new lib.HeifDecoder().decode(buf);
        if (!imgs || !imgs.length) throw new Error('nenhuma imagem dentro do arquivo HEIC');
        var im = imgs[0], w = im.get_width(), h = im.get_height();
        var c = document.createElement('canvas'); c.width = w; c.height = h;
        var ctx = c.getContext('2d'), dados = ctx.createImageData(w, h);
        return new Promise(function (resolve, reject) {
          im.display(dados, function (d) {
            im.free();
            if (!d) { reject(new Error('não consegui decodificar a foto HEIC')); return; }
            ctx.putImageData(d, 0, 0); resolve(c);
          });
        });
      });
    });
  }

  function arquivoParaFonte(file) {
    if (ehHeic(file)) return heicParaCanvas(file);
    if (window.createImageBitmap) return createImageBitmap(file, { imageOrientation: 'from-image' });
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('não consegui abrir a imagem')); };
      img.src = url;
    });
  }

  // preparar(file, {maxLado, qualidade}) -> Promise<string data URL image/jpeg>
  function preparar(file, opts) {
    opts = opts || {};
    var maxLado = opts.maxLado || 1600, q = opts.qualidade || 0.85;
    return arquivoParaFonte(file).then(function (fonte) {
      var w = fonte.width || fonte.naturalWidth, h = fonte.height || fonte.naturalHeight;
      var k = Math.min(1, maxLado / Math.max(w, h));
      var c = document.createElement('canvas'); c.width = Math.max(1, Math.round(w * k)); c.height = Math.max(1, Math.round(h * k));
      c.getContext('2d').drawImage(fonte, 0, 0, c.width, c.height);
      if (fonte.close) fonte.close();
      return c.toDataURL('image/jpeg', q);
    });
  }

  window.IntusFotos = { preparar: preparar, ehImagem: ehImagem, ehHeic: ehHeic };
})();
