// Identifica a posição de uma foto de avaliação postural (as 18 do Protocolo INTUS, ver
// modelo-fotos-avaliacao.pdf) e calcula o recorte no padrão dos relatórios de comparação.
//
// Como funciona (tudo no navegador, a foto NÃO sai do computador):
//   1. O MediaPipe Pose Landmarker (Google, roda local em WebAssembly) acha 33 pontos do corpo e
//      uma máscara da pessoa. Biblioteca e modelo são baixados na 1ª vez e ficam no cache.
//   2. `classificar` decide qual das 18 posições é, só com geometria desses pontos (sem "adivinhar"
//      por nome de arquivo ou ordem de envio) e devolve uma confiança de 0 a 1.
//   3. `recorte` calcula zoom e deslocamento para a foto caber no quadro como o professor faz nos
//      relatórios: corpo inteiro, centralizado, pés alinhados na base; e, num comparativo, as duas
//      fotos com o corpo do MESMO tamanho (ver `recortarPar`).
// Os números (limiares e margens) foram calibrados com os relatórios reais já montados pela equipe.
// O que o classificador não decide com segurança sai com confiança baixa e o painel pede conferência.
//
// Parte pura (classificar, recorte, chave): roda também no Node (testes/foto_pose.js).
(function (raiz) {
  'use strict';

  // ---------- As 18 posições ----------
  // grupo: pe (em pé), deitado, sentado. quadro: formato do quadro nos relatórios (largura x altura).
  var POSES = {
    1:  { nome: 'Frontal',                         grupo: 'pe' },
    2:  { nome: 'Frontal, perna direita fletida',  grupo: 'pe' },
    3:  { nome: 'Frontal, perna esquerda fletida', grupo: 'pe' },
    4:  { nome: 'Perfil direito',                  grupo: 'pe' },
    5:  { nome: 'Perfil direito, braço elevado',   grupo: 'pe' },
    6:  { nome: 'Perfil esquerdo',                 grupo: 'pe' },
    7:  { nome: 'Perfil esquerdo, braço elevado',  grupo: 'pe' },
    8:  { nome: 'Costas',                          grupo: 'pe' },
    9:  { nome: 'Costas, braços a 30°',            grupo: 'pe' },
    10: { nome: 'Costas, braços elevados',         grupo: 'pe' },
    11: { nome: 'Deitado, flexão do quadril direito',  grupo: 'deitado' },
    12: { nome: 'Deitado, flexão do quadril esquerdo', grupo: 'deitado' },
    13: { nome: 'Bruços, relaxado',                grupo: 'deitado' },
    14: { nome: 'Bruços, joelhos a 45°',           grupo: 'deitado' },
    15: { nome: 'Bruços, joelhos ao máximo',       grupo: 'deitado' },
    16: { nome: 'Sentado, pernas abertas',         grupo: 'sentado' },
    17: { nome: 'Sentado, borboleta',              grupo: 'sentado' },
    18: { nome: 'Sentado, alcance dos pés',        grupo: 'sentado' }
  };
  var TOTAL_POSES = 18;

  function rotuloPose(n) {
    if (!POSES[n]) return 'Sem posição';
    return (n < 10 ? '0' : '') + n + ' ' + POSES[n].nome;
  }

  // Quadro usado nos relatórios (visto nos relatórios reais): em pé = 9:16; deitado de barriga para
  // cima e sentado = 3:4 em pé; de bruços = 4:3 deitado.
  function quadroDaPose(n) {
    var p = POSES[n];
    if (!p || p.grupo === 'pe') return { w: 675, h: 1200 };
    if (n >= 13 && n <= 15) return { w: 1200, h: 900 };
    return { w: 900, h: 1200 };
  }

  // ---------- Chave estável de uma foto (mesma regra de app/api/fotos_analise.php) ----------
  // Arquivo local da avaliação -> a:<arquivo>; foto do Drive (link assinado) -> d:<id>; resto -> u:<url>.
  function chaveDaFoto(url) {
    url = String(url || '');
    var m = /\/img\/avaliacoes\/([A-Za-z0-9_.-]+?)(?:[?#].*)?$/.exec(url);
    if (m) return 'a:' + m[1];
    m = /foto_drive\.php\?[^#]*\bf=([A-Za-z0-9_-]{10,})/.exec(url);
    if (m) return 'd:' + m[1];
    m = /^drive:([A-Za-z0-9_-]{10,})$/.exec(url);
    if (m) return 'd:' + m[1];
    return 'u:' + url.replace(/[?#].*$/, '').slice(-170);
  }

  // ---------- Geometria ----------
  // Pontos do MediaPipe usados: 0 nariz, 11/12 ombros, 13/14 cotovelos, 15/16 punhos, 23/24 quadris,
  // 25/26 joelhos, 27/28 tornozelos, 29/30 calcanhares, 31/32 ponta do pé. Esquerda = lado esquerdo
  // da PESSOA (numa foto de frente, aparece à direita da imagem).
  function pt(an, i) { var p = an.lm[i]; return { x: p[0] * an.w, y: p[1] * an.h, v: p[3] }; }
  function dist(a, b) { return Math.sqrt((a.x - b.x) * (a.x - b.x) + (a.y - b.y) * (a.y - b.y)); }
  function meio(a, b) { return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, v: Math.min(a.v, b.v) }; }
  function grausDaVertical(de, para) { // ângulo da reta com a vertical da imagem: 0 = em pé, 90 = deitada
    var a = Math.abs(Math.atan2(para.x - de.x, para.y - de.y)) * 180 / Math.PI;
    return a > 90 ? 180 - a : a;
  }
  function anguloEm(a, b, c) { // ângulo interno em b, em graus
    var v1x = a.x - b.x, v1y = a.y - b.y, v2x = c.x - b.x, v2y = c.y - b.y;
    var d = Math.sqrt(v1x * v1x + v1y * v1y) * Math.sqrt(v2x * v2x + v2y * v2y);
    if (!d) return 180;
    return Math.acos(Math.max(-1, Math.min(1, (v1x * v2x + v1y * v2y) / d))) * 180 / Math.PI;
  }
  function media(a) { var s = 0; for (var i = 0; i < a.length; i++) s += a[i]; return s / a.length; }

  // Caixa da pessoa em pixels: a da máscara (cabelo, mãos e pés inclusos) ou, sem máscara, a dos pontos.
  function caixa(an) {
    if (an.bb && an.bb.length === 4) return { x0: an.bb[0] * an.w, y0: an.bb[1] * an.h, x1: an.bb[2] * an.w, y1: an.bb[3] * an.h };
    var x0 = 1e9, y0 = 1e9, x1 = -1e9, y1 = -1e9;
    for (var i = 0; i < an.lm.length; i++) {
      var p = an.lm[i]; x0 = Math.min(x0, p[0]); y0 = Math.min(y0, p[1]); x1 = Math.max(x1, p[0]); y1 = Math.max(y1, p[1]);
    }
    var mx = (x1 - x0) * 0.04, my = (y1 - y0) * 0.06;
    return { x0: (x0 - mx) * an.w, y0: (y0 - my * 2) * an.h, x1: (x1 + mx) * an.w, y1: (y1 + my) * an.h };
  }

  // Medidas usadas pelo classificador (todas relativas, sem depender do tamanho da foto).
  function medir(an) {
    var nariz = pt(an, 0), oE = pt(an, 11), oD = pt(an, 12), qE = pt(an, 23), qD = pt(an, 24);
    var ombro = meio(oE, oD), quadril = meio(qE, qD);
    var tronco = Math.max(1, dist(ombro, quadril));
    var cx = caixa(an), bw = Math.max(1, cx.x1 - cx.x0), bh = Math.max(1, cx.y1 - cx.y0);
    var tE = pt(an, 27), tD = pt(an, 28), jE = pt(an, 25), jD = pt(an, 26);
    var pE = pt(an, 15), pD = pt(an, 16);
    var calc = meio(pt(an, 29), pt(an, 30)), ponta = meio(pt(an, 31), pt(an, 32));
    var m = {
      tronco: tronco, bw: bw, bh: bh, asp: bw / bh, cx: cx,
      angTronco: grausDaVertical(quadril, ombro),
      largOmbro: dist(oE, oD) / tronco,
      // perna: ângulo do eixo quadril->tornozelo com a vertical; a perna de apoio é a mais vertical
      angPernaMin: Math.min(grausDaVertical(qE, tE), grausDaVertical(qD, tD)),
      // de frente a pessoa tem a esquerda (11) à direita da imagem; de costas ao contrário
      esqADireita: oE.x > oD.x,
      // dedos dos pés mais perto da câmera (mais baixos na imagem) que o calcanhar: de frente
      pontaMenosCalc: (ponta.y - calc.y) / bh,
      // lado para onde o rosto aponta, em pé de perfil (+ = direita da imagem)
      narizX: (nariz.x - ombro.x) / tronco,
      punhoAcimaDoNariz: Math.min(pE.y, pD.y) < nariz.y - 0.15 * tronco,
      doisPunhosAcima: Math.max(pE.y, pD.y) < nariz.y - 0.05 * tronco,
      // afastamento dos punhos em relação ao quadril, em larguras de ombro (de frente/costas)
      abertura: (Math.abs(pE.x - qE.x) + Math.abs(pD.x - qD.x)) / 2 / Math.max(1, dist(oE, oD)),
      // diferença de altura entre os tornozelos (+ = esquerdo mais alto), relativa à altura do corpo
      difTornozelo: (tD.y - tE.y) / bh,
      tornozeloEsqX: tE.x, tornozeloDirX: tD.x, centroX: ombro.x,
      nariz: nariz, ombro: ombro, quadril: quadril,
      visMedia: media([oE.v, oD.v, qE.v, qD.v, tE.v, tD.v])
    };
    // pernas (para deitado e sentado): quanto cada tornozelo está acima do quadril em comprimentos de perna,
    // quanto o joelho sobe e o ângulo interno do joelho
    ['E', 'D'].forEach(function (L) {
      var q = L === 'E' ? qE : qD, j = L === 'E' ? jE : jD, t = L === 'E' ? tE : tD;
      var perna = Math.max(1, dist(q, j) + dist(j, t));
      m['tornAcima' + L] = (q.y - t.y) / perna;
      m['joelhoAcima' + L] = (q.y - j.y) / Math.max(1, dist(q, j));
      m['angJoelho' + L] = anguloEm(q, j, t);
      m['tornX' + L] = t.x;
    });
    // cosseno entre o tronco (quadril->ombro) e a coxa (quadril->joelho): deitado a coxa vai para o lado oposto
    // ao da cabeça (negativo); sentado com o tronco inclinado para as pernas, para o mesmo lado (positivo)
    function cosEntre(o, a, b) {
      var ax = a.x - o.x, ay = a.y - o.y, bx = b.x - o.x, by = b.y - o.y, d = Math.sqrt(ax * ax + ay * ay) * Math.sqrt(bx * bx + by * by);
      return d ? (ax * bx + ay * by) / d : 0;
    }
    m.cosCoxa = Math.min(cosEntre(quadril, ombro, jE), cosEntre(quadril, ombro, jD));
    m.cosCoxaMax = Math.max(cosEntre(quadril, ombro, jE), cosEntre(quadril, ombro, jD));
    m.distPunhoPe = Math.min(dist(pE, tE), dist(pD, tD), dist(pE, tD), dist(pD, tE)) / tronco;
    m.distTornozelos = dist(tE, tD) / tronco;
    m.pernaMax = Math.max(1, Math.max(dist(qE, jE) + dist(jE, tE), dist(qD, jD) + dist(jD, tD)));
    return m;
  }

  // ---------- Classificador ----------
  // Devolve { pose: 1..18 ou null, conf: 0..1, grupo, motivo }. conf >= 0.75 = pode confiar;
  // abaixo disso o painel marca "conferir".
  function classificar(an) {
    if (!an || !an.lm || an.lm.length < 33) return { pose: null, conf: 0, grupo: null, motivo: 'pessoa não encontrada na foto' };
    var m = medir(an);
    var res = { pose: null, conf: 0, grupo: null, motivo: '' };
    var vis = Math.max(0, Math.min(1, (m.visMedia - 0.35) / 0.4)); // pontos pouco visíveis baixam a confiança

    var deitado = m.angTronco > 72 || (m.angTronco > 55 && m.cosCoxa < -0.3);
    var emPe = !deitado && m.angPernaMin < 28 && m.tronco > 0 && (m.bh / m.bw) > 1.2;
    var conf;

    if (emPe) {
      res.grupo = 'pe';
      var perfil = m.largOmbro < 0.3;
      if (perfil) {
        var paraDireita = m.narizX > 0;
        var braco = m.punhoAcimaDoNariz;
        res.pose = paraDireita ? (braco ? 5 : 4) : (braco ? 7 : 6);
        conf = Math.min(1, 0.55 + Math.abs(m.narizX) * 2.2);
        res.motivo = 'de perfil, ' + (paraDireita ? 'olhando para a direita da foto' : 'olhando para a esquerda da foto') + (braco ? ', braço elevado' : '');
      } else {
        // frente ou costas: dois sinais independentes
        var votos = (m.esqADireita ? 1 : -1) + (m.pontaMenosCalc > 0.035 ? 1 : (m.pontaMenosCalc < 0.015 ? -1 : 0));
        var frente = votos > 0;
        conf = votos === 0 ? 0.4 : (Math.abs(votos) === 2 ? 1 : 0.7);
        if (frente) {
          // perna fletida: um tornozelo bem mais alto que o outro; o lado é o da imagem (esquerda da foto = perna direita)
          var dif = Math.abs(m.difTornozelo);
          if (dif > 0.06) {
            var levantadoEsquerdaDaFoto = (m.difTornozelo > 0 ? m.tornozeloEsqX : m.tornozeloDirX) < m.centroX;
            res.pose = levantadoEsquerdaDaFoto ? 2 : 3;
            conf = Math.min(conf, 0.6 + Math.min(0.4, (dif - 0.06) * 4));
            res.motivo = 'de frente, perna ' + (res.pose === 2 ? 'direita' : 'esquerda') + ' fletida';
          } else {
            res.pose = 1;
            conf = Math.min(conf, 0.7 + Math.min(0.3, (0.06 - dif) * 8));
            res.motivo = 'de frente';
          }
        } else {
          if (m.doisPunhosAcima) { res.pose = 10; res.motivo = 'de costas, braços elevados'; }
          else if (m.abertura > 0.55) { res.pose = 9; res.motivo = 'de costas, braços afastados'; conf = Math.min(conf, 0.6 + Math.min(0.4, (m.abertura - 0.55) * 2)); }
          else { res.pose = 8; res.motivo = 'de costas'; conf = Math.min(conf, 0.6 + Math.min(0.4, (0.55 - m.abertura) * 2)); }
        }
      }
    } else if (deitado) {
      res.grupo = 'deitado';
      var altE = m.tornAcimaE, altD = m.tornAcimaD;
      var ladoAlto = altE > altD ? 'E' : 'D';
      var alto = Math.max(altE, altD);
      var joelhoAlto = m['joelhoAcima' + ladoAlto]; // coxa levantada (de barriga para cima) x coxa no chão (de bruços)
      var angJ = m['angJoelho' + ladoAlto];
      if (alto > 0.45 && joelhoAlto > 0.45) {
        // de barriga para cima, uma perna esticada para o alto
        res.pose = ladoAlto === 'D' ? 11 : 12;
        conf = Math.min(1, 0.5 + (alto - 0.45) * 2 + (angJ > 150 ? 0.2 : 0));
        // os dois tornozelos "no alto" = o modelo confundiu as pernas; o lado fica incerto
        if (Math.min(altE, altD) > 0.45) { conf = Math.min(conf, 0.4); res.motivo = 'lado da perna incerto, '; }
        res.motivo = (res.motivo || '') + 'de barriga para cima, flexão do quadril ' + (ladoAlto === 'D' ? 'direito' : 'esquerdo');
      } else if (alto > 0.2 && joelhoAlto <= 0.45) {
        // de bruços, joelho dobrado: 45° x máximo pelo ângulo interno do joelho
        var flex = 180 - angJ; // flexão do joelho em graus
        res.pose = flex < 100 ? 14 : 15;
        conf = Math.min(1, 0.55 + Math.abs(flex - 100) / 70);
        res.motivo = 'de bruços, joelhos flexionados (' + Math.round(flex) + '°)';
      } else {
        res.pose = 13; conf = m.asp > 3 ? 0.8 : 0.7;
        res.motivo = 'de bruços, relaxado';
      }
    } else {
      // sentado: separa pelo alcance (mãos nos pés), pelo joelho dobrado (borboleta) e pelo tronco ereto
      res.grupo = 'sentado';
      var joelhoMin = Math.min(m.angJoelhoE, m.angJoelhoD);
      var pernasEstendidas = joelhoMin > 125;
      if (m.distPunhoPe < 0.55 && pernasEstendidas) {
        res.pose = 18; conf = Math.min(1, 0.6 + (0.55 - m.distPunhoPe)); res.motivo = 'sentado, mãos nos pés';
      } else if (joelhoMin < 100 && m.distTornozelos < 0.6) {
        res.pose = 17; conf = Math.min(1, 0.6 + (100 - joelhoMin) / 100); res.motivo = 'sentado, joelhos dobrados e solas juntas';
      } else {
        res.pose = 16; conf = (m.angTronco < 25 && pernasEstendidas) ? 0.8 : 0.55; res.motivo = 'sentado, pernas estendidas';
      }
    }
    res.conf = Math.round(Math.max(0, Math.min(1, conf)) * vis * 100) / 100;
    return res;
  }

  // ---------- Recorte ----------
  // Margens medidas nos relatórios reais: em pé, o corpo ocupa ~95% da altura com ~3% no topo e ~3% na base
  // (pés alinhados embaixo); deitado/sentado o corpo ocupa quase toda a largura.
  var MARG = { topo: 0.03, base: 0.03, lado: 0.02 };

  // Tamanho de referência do corpo, igual nas duas fotos de um par, para os corpos saírem do mesmo tamanho:
  // em pé = do nariz ao tornozelo mais baixo; nas outras posições = comprimento do tronco.
  function referencia(an, pose) {
    var p = POSES[pose];
    if (p && p.grupo === 'pe') {
      var n = pt(an, 0), a = Math.max(pt(an, 27).y, pt(an, 28).y);
      return Math.max(1, a - n.y);
    }
    return medir(an).tronco * 3.2; // tronco x 3,2 ~ nariz ao tornozelo; só precisa ser proporcional
  }

  // Zoom/deslocamento (no modelo do editor: cobre o quadro, depois zoom e pan 0..1) para UMA foto, dado o
  // tamanho de corpo desejado `escala` (pixels do quadro por pixel da foto). escala null = melhor encaixe.
  function recorte(an, pose, quadro, escala) {
    quadro = quadro || quadroDaPose(pose);
    var cx = caixa(an), bw = cx.x1 - cx.x0, bh = cx.y1 - cx.y0;
    var emPe = !POSES[pose] || POSES[pose].grupo === 'pe';
    var cobre = Math.max(quadro.w / an.w, quadro.h / an.h);
    var ideal = Math.min((1 - MARG.topo - MARG.base) * quadro.h / bh, (1 - 2 * MARG.lado) * quadro.w / bw);
    var s = escala || ideal;
    var zoom = Math.max(1, Math.min(4, s / cobre));
    s = cobre * zoom;
    var sW = quadro.w / s, sH = quadro.h / s;
    var folgaX = Math.max(0, an.w - sW), folgaY = Math.max(0, an.h - sH);
    var centroX = (cx.x0 + cx.x1) / 2;
    var sx = centroX - sW / 2;
    var sy = emPe ? (cx.y1 + MARG.base * sH - sH) : ((cx.y0 + cx.y1) / 2 - sH * 0.52);
    function pan(v, folga) { return folga > 0 ? Math.max(0, Math.min(1, v / folga)) : 0.5; }
    return { zoom: Math.round(zoom * 100) / 100, panX: pan(sx, folgaX), panY: pan(sy, folgaY), escala: s, ideal: ideal, quadro: quadro };
  }

  // Recorte de um par Antes/Depois da mesma posição: mesmo tamanho de corpo nos dois lados.
  function recortarPar(anA, anB, pose) {
    var quadro = quadroDaPose(pose);
    var rA = recorte(anA, pose, quadro, null), rB = recorte(anB, pose, quadro, null);
    // tamanho do corpo no quadro = escala x referência; fica o MENOR dos dois (assim cabe nas duas)
    var kA = rA.ideal * referencia(anA, pose), kB = rB.ideal * referencia(anB, pose);
    var k = Math.min(kA, kB);
    // o editor não afasta além de "cobrir o quadro" (zoom mínimo 1): se o tamanho combinado pedir menos que
    // isso para algum lado, vale o menor possível dos dois (corpos iguais, com um pouco de corte, como nos
    // relatórios reais) em vez de corpos de tamanhos diferentes
    function minimo(an) { return Math.max(quadro.w / an.w, quadro.h / an.h) * referencia(an, pose); }
    k = Math.max(k, minimo(anA), minimo(anB));
    return {
      quadro: quadro,
      antes: recorte(anA, pose, quadro, k / referencia(anA, pose)),
      depois: recorte(anB, pose, quadro, k / referencia(anB, pose))
    };
  }

  // Escolhe, entre várias fotos da mesma avaliação com a mesma posição, a melhor (maior confiança;
  // confirmada à mão vale mais que a automática; empate: a última enviada).
  function melhorDaPose(lista) {
    var melhor = null;
    lista.forEach(function (f, i) {
      var pontos = (f.origem === 'manual' ? 10 : 0) + (f.conf || 0) + i * 1e-6;
      if (!melhor || pontos > melhor.pontos) melhor = { f: f, pontos: pontos };
    });
    return melhor ? melhor.f : null;
  }

  // ---------- Detector (só no navegador) ----------
  // MediaPipe Pose Landmarker, versão fixa e modelo "full" (~9 MB, baixado na 1ª vez e guardado pelo navegador).
  // Roda na GPU quando dá e cai para a CPU. A foto não é enviada a ninguém.
  var MP_BASE = 'https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14';
  var MP_MODELO = 'https://storage.googleapis.com/mediapipe-models/pose_landmarker/pose_landmarker_full/float16/1/pose_landmarker_full.task';
  var LADO_MAX = 1024;
  var _detector = null;

  function carregarDetector() {
    if (_detector) return _detector;
    _detector = import(MP_BASE + '/vision_bundle.mjs').then(function (mod) {
      return mod.FilesetResolver.forVisionTasks(MP_BASE + '/wasm').then(function (fileset) {
        function criar(delegate) {
          return mod.PoseLandmarker.createFromOptions(fileset, {
            baseOptions: { modelAssetPath: MP_MODELO, delegate: delegate },
            runningMode: 'IMAGE', numPoses: 1, outputSegmentationMasks: true,
            minPoseDetectionConfidence: 0.3, minPosePresenceConfidence: 0.3
          });
        }
        return criar('GPU').catch(function () { return criar('CPU'); });
      });
    }).catch(function (e) {
      _detector = null;
      throw new Error('não consegui carregar o detector de pose (confira a internet): ' + (e && e.message || e));
    });
    return _detector;
  }

  // Caixa da pessoa pela máscara (ignora respingos soltos): [x0, y0, x1, y1] em 0..1.
  function caixaDaMascara(mask) {
    var w = mask.width, h = mask.height, a = mask.getAsFloat32Array();
    var cx = new Uint32Array(w), cy = new Uint32Array(h);
    for (var y = 0; y < h; y++) for (var x = 0; x < w; x++) if (a[y * w + x] > 0.5) { cx[x]++; cy[y]++; }
    var lim = Math.max(2, Math.round(Math.min(w, h) * 0.004));
    var x0 = 0, x1 = w - 1, y0 = 0, y1 = h - 1;
    while (x0 < w && cx[x0] < lim) x0++;
    while (x1 > 0 && cx[x1] < lim) x1--;
    while (y0 < h && cy[y0] < lim) y0++;
    while (y1 > 0 && cy[y1] < lim) y1--;
    if (x1 <= x0 || y1 <= y0) return null;
    function r(v) { return Math.round(v * 10000) / 10000; }
    return [r(x0 / w), r(y0 / h), r((x1 + 1) / w), r((y1 + 1) / h)];
  }

  // fonte: <img>, canvas ou ImageBitmap já na orientação certa. Devolve { w, h, lm, bb } ou null (sem pessoa).
  function detectar(fonte) {
    return carregarDetector().then(function (det) {
      var w0 = fonte.naturalWidth || fonte.width, h0 = fonte.naturalHeight || fonte.height;
      var k = Math.min(1, LADO_MAX / Math.max(w0, h0));
      var c = document.createElement('canvas');
      c.width = Math.max(1, Math.round(w0 * k)); c.height = Math.max(1, Math.round(h0 * k));
      c.getContext('2d').drawImage(fonte, 0, 0, c.width, c.height);
      var r = det.detect(c);
      var p = r.landmarks && r.landmarks[0];
      var bb = null;
      var m = r.segmentationMasks && r.segmentationMasks[0];
      if (m) { try { bb = caixaDaMascara(m); } finally { m.close(); } }
      if (!p) return null;
      return {
        w: c.width, h: c.height, bb: bb,
        lm: p.map(function (q) { return [Math.round(q.x * 10000) / 10000, Math.round(q.y * 10000) / 10000, 0, Math.round((q.visibility == null ? 0 : q.visibility) * 100) / 100]; })
      };
    });
  }

  function abrirFoto(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('foto indisponível (http ' + r.status + ')');
      return r.blob();
    }).then(function (blob) {
      if (window.createImageBitmap) return createImageBitmap(blob, { imageOrientation: 'from-image' });
      return new Promise(function (resolve, reject) {
        var u = URL.createObjectURL(blob), img = new Image();
        img.onload = function () { URL.revokeObjectURL(u); resolve(img); };
        img.onerror = function () { URL.revokeObjectURL(u); reject(new Error('não consegui abrir a foto')); };
        img.src = u;
      });
    });
  }

  // Analisa uma foto pela URL: devolve { an, cls } (an = pontos e caixa; cls = posição e confiança).
  function analisarUrl(url) {
    return abrirFoto(url).then(function (img) {
      return detectar(img).then(function (an) {
        if (img.close) img.close();
        return { an: an, cls: classificar(an) };
      });
    });
  }

  // Item vindo do servidor (lm em [x, y, visibilidade]) -> formato interno ([x, y, z, visibilidade]).
  function deServidor(it) {
    if (!it || !it.lm || it.lm.length !== 33 || !it.w || !it.h) return null;
    return {
      w: it.w, h: it.h, bb: it.bb || null,
      lm: it.lm.map(function (q) { return q.length >= 4 ? q : [q[0], q[1], 0, q[2]]; })
    };
  }

  // Corpo da gravação no servidor para um resultado de analisarUrl.
  function paraServidor(chave, idavaliacao, res) {
    var an = res.an, cls = res.cls;
    return {
      chave: chave, idavaliacao: idavaliacao || null,
      pose: cls.pose, conf: cls.conf, origem: 'auto',
      w: an ? an.w : 0, h: an ? an.h : 0, bb: an ? an.bb : null, lm: an ? an.lm : null
    };
  }

  var api = {
    POSES: POSES, TOTAL_POSES: TOTAL_POSES, rotuloPose: rotuloPose, quadroDaPose: quadroDaPose,
    chaveDaFoto: chaveDaFoto, medir: medir, classificar: classificar, recorte: recorte,
    recortarPar: recortarPar, melhorDaPose: melhorDaPose, caixa: caixa,
    carregarDetector: carregarDetector, detectar: detectar, analisarUrl: analisarUrl,
    deServidor: deServidor, paraServidor: paraServidor
  };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else raiz.IntusPose = api;
})(typeof window !== 'undefined' ? window : this);
