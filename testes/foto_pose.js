// Identificação das 18 posições e recorte automático (app/painel/foto-pose.js).
// O repositório só leva os pontos do corpo das 18 fotos do MODELO do protocolo (modelo-fotos-avaliacao.pdf,
// material da Intus). Fotos de aluno não entram no repositório: quem quiser rodar também contra casos reais
// aponta um arquivo local com o mesmo formato (lista de {rotulo, pose, w, h, bb, lm}):
//   node testes/foto_pose.js
//   FOTO_POSE_REAIS=/caminho/casos_reais.json node testes/foto_pose.js
const fs = require('fs');
const P = require('../app/painel/foto-pose.js');
const modelo = require('./foto_pose_modelo.json');
const reais = process.env.FOTO_POSE_REAIS ? JSON.parse(fs.readFileSync(process.env.FOTO_POSE_REAIS, 'utf8')) : [];
let ok = 0, ruim = 0;
function t(nome, cond, extra) { if (cond) ok++; else { ruim++; console.log('FALHOU: ' + nome + (extra ? ' ' + extra : '')); } }

// 1. Classificação: o modelo do protocolo, posição por posição
t('18 fotos do modelo', modelo.length === 18);
modelo.forEach(c => {
  const r = P.classificar(c);
  t(c.rotulo + ' (previu ' + r.pose + ')', r.pose === c.pose);
  t(c.rotulo + ' com confiança de aprovação', r.conf >= 0.75, 'conf=' + r.conf);
});
// casos reais (opcional): erro tem de sair com confiança baixa
if (reais.length) {
  let certos = 0;
  reais.forEach(c => {
    const r = P.classificar(c);
    if (r.pose === c.pose) certos++;
    else t('erro precisa sair com confiança baixa: ' + c.rotulo + ' (previu ' + r.pose + ', era ' + c.pose + ')', r.conf < 0.75, 'conf=' + r.conf);
  });
  t('acerto nos casos reais >= 95% (' + certos + '/' + reais.length + ')', certos / reais.length >= 0.95);
  console.log('casos reais: ' + certos + '/' + reais.length);
}

// o que não é pessoa
t('sem pontos = sem posição', P.classificar({ w: 10, h: 10, lm: null }).pose === null);
t('poucos pontos = sem posição', P.classificar({ w: 10, h: 10, lm: [[0, 0, 0, 1]] }).pose === null);

// pontos pouco visíveis baixam a confiança
const frontal = modelo[0];
const fraco = JSON.parse(JSON.stringify(frontal)); fraco.lm.forEach(p => { p[3] = 0.2; });
t('pontos invisíveis derrubam a confiança', P.classificar(fraco).conf < P.classificar(frontal).conf && P.classificar(fraco).conf < 0.75);

// espelhar uma foto de perfil troca o lado para onde o rosto aponta
const espelha = c => { const m = JSON.parse(JSON.stringify(c)); m.lm.forEach(p => { p[0] = 1 - p[0]; }); if (m.bb) m.bb = [1 - c.bb[2], c.bb[1], 1 - c.bb[0], c.bb[3]]; return m; };
t('perfil direito espelhado vira perfil esquerdo', P.classificar(espelha(modelo[3])).pose === 6);
t('perfil esquerdo espelhado vira perfil direito', P.classificar(espelha(modelo[5])).pose === 4);

// 2. Quadros
t('em pé = 9:16', JSON.stringify(P.quadroDaPose(1)) === '{"w":675,"h":1200}');
t('de bruços = 4:3 deitado', P.quadroDaPose(14).w === 1200 && P.quadroDaPose(14).h === 900);
t('sentado = 3:4 em pé', P.quadroDaPose(17).w === 900 && P.quadroDaPose(17).h === 1200);
t('de barriga para cima = 3:4 em pé', P.quadroDaPose(11).w === 900 && P.quadroDaPose(11).h === 1200);
t('rótulo', P.rotuloPose(5) === '05 Perfil direito, braço elevado' && P.rotuloPose(null) === 'Sem posição');

// 3. Recorte. A segunda foto do par é a mesma pessoa "de mais longe" e deslocada (o que acontece quando o
// celular fica em outra distância no dia da reavaliação): o recorte tem de igualar o tamanho do corpo.
// As fotos do modelo são recortes de formatos variados; aqui cada uma vira uma "foto de celular" 3:4 em pé
// (1200 x 1600), com o corpo ocupando quase toda a altura (em pé) ou a largura (deitado/sentado), como as reais.
function comoFotoDeCelular(c) {
  const W = 1200, H = 1600, aw = c.w, ah = c.h;
  const k = Math.min(W * 0.97 / aw, H * 0.95 / ah);
  const nw = aw * k, nh = ah * k, ox = (W - nw) / 2, oy = (H - nh) / 2;
  const m = JSON.parse(JSON.stringify(c));
  m.w = W; m.h = H;
  m.lm.forEach(p => { p[0] = (p[0] * nw + ox) / W; p[1] = (p[1] * nh + oy) / H; });
  m.bb = [(c.bb[0] * nw + ox) / W, (c.bb[1] * nh + oy) / H, (c.bb[2] * nw + ox) / W, (c.bb[3] * nh + oy) / H];
  return m;
}
function simular(c, k, dx, dy) { // reduz a pessoa por k em torno do centro e desloca (como outra distância/enquadramento)
  const m = JSON.parse(JSON.stringify(c));
  const f = v => v;
  m.lm.forEach(p => { p[0] = (p[0] - 0.5) * k + 0.5 + dx; p[1] = (p[1] - 0.5) * k + 0.5 + dy; });
  m.bb = [(c.bb[0] - 0.5) * k + 0.5 + dx, (c.bb[1] - 0.5) * k + 0.5 + dy, (c.bb[2] - 0.5) * k + 0.5 + dx, (c.bb[3] - 0.5) * k + 0.5 + dy];
  return m;
}
function caixaNoQuadro(an, r) {
  const cx = P.caixa(an), s = r.escala, sW = r.quadro.w / s, sH = r.quadro.h / s;
  const sx = r.panX * Math.max(0, an.w - sW), sy = r.panY * Math.max(0, an.h - sH);
  return { x0: (cx.x0 - sx) * s / r.quadro.w, x1: (cx.x1 - sx) * s / r.quadro.w, y0: (cx.y0 - sy) * s / r.quadro.h, y1: (cx.y1 - sy) * s / r.quadro.h };
}
modelo.forEach(c0 => {
  const c = comoFotoDeCelular(c0);
  const dep = simular(c, 0.78, 0.03, 0.02);
  const r = P.recortarPar(c, dep, c.pose);
  const q = c.pose;
  [['antes', c, r.antes], ['depois', dep, r.depois]].forEach(([nome, an, rc]) => {
    t('zoom entre 1 e 4 (' + q + ' ' + nome + ')', rc.zoom >= 1 && rc.zoom <= 4);
    t('pan entre 0 e 1 (' + q + ' ' + nome + ')', rc.panX >= 0 && rc.panX <= 1 && rc.panY >= 0 && rc.panY <= 1);
    const b = caixaNoQuadro(an, rc);
    t('corpo cabe no quadro (' + q + ' ' + nome + ')', b.x0 > -0.08 && b.x1 < 1.08 && b.y0 > -0.08 && b.y1 < 1.08, JSON.stringify(b));
  });
  // mesmo tamanho: o tronco ocupa a mesma fração do quadro nos dois lados (zoom mínimo 1 pode impedir)
  const tA = r.antes.escala * P.medir(c).tronco, tB = r.depois.escala * P.medir(dep).tronco;
  if (r.antes.zoom > 1 && r.depois.zoom > 1) t('mesmo tamanho de corpo no par (' + q + ')', Math.abs(tA / tB - 1) < 0.03, tA.toFixed(0) + ' x ' + tB.toFixed(0));
});
// em pé, pés alinhados na base nos dois lados
[1, 4, 8].forEach(n => {
  const c = comoFotoDeCelular(modelo[n - 1]), dep = simular(c, 0.78, 0.03, 0.02);
  const r = P.recortarPar(c, dep, n);
  const bA = caixaNoQuadro(c, r.antes), bB = caixaNoQuadro(dep, r.depois);
  t('pés alinhados na base (' + n + ')', Math.abs(bA.y1 - bB.y1) < 0.03, bA.y1.toFixed(3) + ' x ' + bB.y1.toFixed(3));
});

// Quando um dos lados "ocupa mais" (braços mais abertos, sombra, objeto na foto), ele limita o tamanho e o
// OUTRO lado tem de acompanhar, senão o comparativo mostra corpos de tamanhos diferentes.
[1, 8].forEach(n => {
  const c = comoFotoDeCelular(modelo[n - 1]), dep = simular(c, 0.78, 0, 0);
  dep.bb = [Math.max(0, dep.bb[0] - 0.3), dep.bb[1], Math.min(1, dep.bb[2] + 0.3), dep.bb[3]];
  const r = P.recortarPar(c, dep, n);
  const tA = r.antes.escala * P.medir(c).tronco, tB = r.depois.escala * P.medir(dep).tronco;
  t('lado mais largo limita e o outro acompanha (' + n + ')', Math.abs(tA / tB - 1) < 0.03, tA.toFixed(0) + ' x ' + tB.toFixed(0));
});

// 4. Escolha da melhor foto da posição
t('manual vence automática', P.melhorDaPose([{ origem: 'auto', conf: 0.99 }, { origem: 'manual', conf: 1, id: 'm' }]).id === 'm');
t('maior confiança vence', P.melhorDaPose([{ origem: 'auto', conf: 0.5, id: 'a' }, { origem: 'auto', conf: 0.9, id: 'b' }]).id === 'b');
t('empate fica com a última', P.melhorDaPose([{ origem: 'auto', conf: 0.9, id: 'a' }, { origem: 'auto', conf: 0.9, id: 'b' }]).id === 'b');
t('lista vazia', P.melhorDaPose([]) === null);

// 5. Chave da foto
t('chave de arquivo local', P.chaveDaFoto('/app/img/avaliacoes/av_1787157741_de68c4189ad6.jpg') === 'a:av_1787157741_de68c4189ad6.jpg');
t('chave de arquivo local com endereço completo e parâmetro', P.chaveDaFoto('https://intusfit.com.br/app/img/avaliacoes/av_1_ab.jpg?v=3') === 'a:av_1_ab.jpg');
t('chave de foto do Drive (link assinado)', P.chaveDaFoto('/app/api/foto_drive.php?f=1AbCdEfGhIjKlMn&t=xx&e=1') === 'd:1AbCdEfGhIjKlMn');
t('chave de drive:ID', P.chaveDaFoto('drive:1AbCdEfGhIjKlMn') === 'd:1AbCdEfGhIjKlMn');

// 6. Formato do servidor
const srv = P.paraServidor('a:av_1_abcdef.jpg', 7, { an: modelo[0], cls: P.classificar(modelo[0]) });
t('paraServidor: pose, origem e 33 pontos', srv.pose === 1 && srv.origem === 'auto' && srv.lm.length === 33 && srv.idavaliacao === 7);
const volta = P.deServidor({ w: 100, h: 200, bb: [0, 0, 1, 1], lm: srv.lm.map(q => [q[0], q[1], q[3]]) });
t('deServidor reconstrói [x, y, z, visibilidade] e a posição sai igual', volta && volta.lm[0].length === 4 && P.classificar(volta).pose === P.classificar({ w: 100, h: 200, bb: [0, 0, 1, 1], lm: modelo[0].lm }).pose);
t('deServidor sem pontos = null', P.deServidor({ w: 1, h: 1, lm: null }) === null);

// 7. Posições parecidas na mesma data: a pontuação por posição concorda com o classificador e a atribuição
// (uma foto por posição) resolve as duplicadas
const argmax = sc => { let b = 1; for (let i = 2; i <= 18; i++) if (sc[i] > sc[b]) b = i; return b; };
modelo.concat(reais).forEach(c => t('pontuação concorda com o classificador: ' + c.rotulo, argmax(P.pontuar(c)) === P.classificar(c).pose));
const poseDe = (lista) => P.atribuirPoses(lista.map(an => ({ an }))).map(x => x.pose);
t('modelo inteiro numa data: 18 de 18', poseDe(modelo).every((p, i) => p === modelo[i].pose));
const clonar = o => JSON.parse(JSON.stringify(o));
function ambigua(pose, mexe) {   // o modelo com UMA foto "no limite" entre duas posições vizinhas
  const g = modelo.map(clonar), i = g.findIndex(c => c.pose === pose); mexe(g[i]);
  return { g, i, duro: P.classificar(g[i]).pose, atrib: P.atribuirPoses(g.map(an => ({ an })))[i] };
}
let a = ambigua(5, c => { const tr = P.medir(c).tronco / c.h; [15, 16].forEach(k => { c.lm[k][1] = Math.max(c.lm[k][1], c.lm[0][1] - 0.09 * tr); }); });
t('braço elevado fraco: o classificador sozinho erra, a atribuição acerta e pede conferência', a.duro === 4 && a.atrib.pose === 5 && a.atrib.conf < 0.75, JSON.stringify(a.atrib));
a = ambigua(3, c => { const alto = c.lm[27][1] < c.lm[28][1] ? 27 : 28, baixo = alto === 27 ? 28 : 27; c.lm[alto][1] = c.lm[baixo][1] - 0.035 * (P.medir(c).bh / c.h); });
t('perna pouco fletida: a atribuição acerta o lado', a.atrib.pose === 3, JSON.stringify(a.atrib));
// posição confirmada à mão fica e reserva o lugar
const fx = P.atribuirPoses(modelo.map((an, i) => ({ an, fixa: i === 0 ? 8 : null })));
t('posição manual não muda e vale confiança total', fx[0].pose === 8 && fx[0].conf === 1 && fx.slice(1).every(x => x.pose));
// repetida de verdade (duas fotos iguais da mesma posição): não joga a segunda numa posição que não combina
const rep = P.atribuirPoses([{ an: modelo[0] }, { an: modelo[0] }]).map(x => x.pose);
t('duas fotos frontais: a segunda não é empurrada para uma posição sem sentido', rep[0] === 1 && (rep[1] === 1 || rep[1] === 2 || rep[1] === 3));
t('sem pontos e sem pessoa ficam sem posição', P.atribuirPoses([{ an: null }, { an: { w: 1, h: 1, lm: null } }]).every(x => x.pose === null));
t('mais fotos que posições não trava', P.atribuirPoses(modelo.concat(modelo).map(an => ({ an }))).length === 36);

// 8. Recorte em qualquer quadro: "afastar" mostra o corpo inteiro
const ped = modelo[0], foto = comoFotoDeCelular(ped);
const quadrado = { w: 1080, h: 1080 };
const rc = P.recorte(foto, 1, quadrado, null, false), ra = P.recorte(foto, 1, quadrado, null, true);
t('quadrado sem afastar fica com zoom 1 (corpo cortado)', rc.zoom === 1);
t('quadrado com afastar mostra o corpo inteiro (zoom < 1)', ra.zoom < 1 && ra.zoom >= 0.4);
const em9x16 = P.recorte(foto, 1, { w: 675, h: 1200 }, null, true);
t('9:16 não abre faixa vazia à toa (foto 3:4 em pé)', em9x16.zoom >= 1, 'zoom=' + em9x16.zoom);
const rv = P.recortarVarios([foto, comoFotoDeCelular(modelo[0])], [1, 1], quadrado, true);
t('recortarVarios: mesmo tamanho de corpo nas duas', Math.abs(rv[0].escala / rv[1].escala - 1) < 0.01);
t('recortarPar aceita quadro próprio', P.recortarPar(foto, foto, 1, quadrado).quadro.w === 1080);

console.log(ok + ' ok, ' + ruim + ' falhas');
process.exit(ruim ? 1 : 0);
