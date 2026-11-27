/* Lembretes de refeições e de água (aluno com plano alimentar ativo), 25/11/2026.
 *
 * Opcional (vem desligado). Os horários vêm do plano que a nutri montou: cada refeição avisa no horário dela (ou alguns minutos antes) e a
 * água avisa em intervalos entre dois horários. Quando a nutri muda o plano, os lembretes se ajustam sozinhos na próxima vez que o app abre.
 * Funciona no app das lojas (iPhone e Android) com notificações locais agendadas no sistema, inclusive com o app fechado. Na web o
 * navegador não agenda aviso com a página fechada, então a tela explica isso.
 *
 * Depende de aluno.html: idAtleta, API (nutri, listarPlanosNutricionais), _nutri, _nutriTemPlano, _nutriUltimoPeso, _nutriCores, _comSheet,
 * toast, esc, navegar.
 */
(function () {
  'use strict';

  var ID_REF = 92000, ID_AGUA = 93000, ID_TESTE = 92990;
  var PADRAO = { ativo: false, refeicoes: true, antes: 10, agua: true, aguaIni: '08:00', aguaFim: '21:00', aguaInt: 120 };

  function LN() { try { return (window.Capacitor && Capacitor.Plugins && Capacitor.Plugins.LocalNotifications) || null; } catch (e) { return null; } }
  function chave(s) { return 'intus-nutri-lemb' + (s || '') + '-' + idAtleta; }
  function ler() {
    try { var c = JSON.parse(localStorage.getItem(chave()) || 'null'); return Object.assign({}, PADRAO, c || {}); } catch (e) { return Object.assign({}, PADRAO); }
  }
  function gravar(c) { try { localStorage.setItem(chave(), JSON.stringify(c)); } catch (e) {} }
  function pad(n) { return String(n).padStart(2, '0'); }
  function minutos(hhmm) { var m = /^(\d{1,2}):(\d{2})$/.exec(String(hhmm || '').trim()); if (!m) return null; var h = +m[1], mi = +m[2]; return (h > 23 || mi > 59) ? null : h * 60 + mi; }
  function hhmm(min) { min = ((min % 1440) + 1440) % 1440; return pad(Math.floor(min / 60)) + ':' + pad(min % 60); }

  // ── o que será agendado (função pura: dá para testar sem o plugin) ──
  // plano: o plano ativo; cfg: as preferências; metaMl: meta de água do dia (para o texto).
  function montarLista(plano, cfg, metaMl) {
    var out = [];
    if (!plano || !cfg || !cfg.ativo) return out;
    if (cfg.refeicoes) {
      var vistos = {};
      API.nutri.refeicoes(plano).forEach(function (r, i) {
        var m = minutos(r.horario); if (m == null) return;
        var quando = m - (Number(cfg.antes) || 0);
        var id = ID_REF + i; if (vistos[quando + r.nome]) return; vistos[quando + r.nome] = 1;
        out.push({ id: id, tipo: 'refeicao', hora: hhmm(quando), titulo: '🍽️ ' + (r.nome || 'Refeição') + (cfg.antes > 0 ? ' em ' + cfg.antes + ' min' : ''),
          corpo: cfg.antes > 0 ? 'Prevista para as ' + r.horario + '. Toque para ver o seu plano alimentar.' : 'Hora de comer. Toque para ver o plano e registrar.' });
      });
    }
    if (cfg.agua) {
      var ini = minutos(cfg.aguaIni), fim = minutos(cfg.aguaFim), passo = Number(cfg.aguaInt) || 120;
      if (ini != null && fim != null && fim >= ini && passo >= 30) {
        var k = 0;
        for (var t = ini; t <= fim && k < 14; t += passo, k++) {
          out.push({ id: ID_AGUA + k, tipo: 'agua', hora: hhmm(t), titulo: '💧 Hora de beber água',
            corpo: (metaMl ? 'Sua meta de hoje: ' + (metaMl / 1000).toFixed(1).replace('.', ',') + ' L. ' : '') + 'Toque para registrar o copo.' });
        }
      }
    }
    return out;
  }

  async function cancelarTodos(ln) {
    try {
      var ids = [];
      try { ids = JSON.parse(localStorage.getItem(chave('-ids')) || '[]'); } catch (e) {}
      var pend = null; try { pend = await ln.getPending(); } catch (e) {}
      if (pend && Array.isArray(pend.notifications)) {
        pend.notifications.forEach(function (n) { if (n && n.extra && (n.extra.tipo === 'nutri-refeicao' || n.extra.tipo === 'nutri-agua')) ids.push(n.id); });
      }
      ids = ids.filter(function (v, i, a) { return a.indexOf(v) === i; });
      if (ids.length) await ln.cancel({ notifications: ids.map(function (id) { return { id: id }; }) });
      localStorage.setItem(chave('-ids'), '[]');
    } catch (e) {}
  }

  async function permissao(ln) {
    try {
      var p = await ln.checkPermissions();
      if (p && p.display === 'granted') return true;
      var r = await ln.requestPermissions();
      return !!(r && r.display === 'granted');
    } catch (e) { return false; }
  }

  // Canal de notificação só existe no Android: no iPhone o plugin responde "não implementado" e isso não pode impedir o agendamento
  // (era o que fazia o aviso de teste falhar no iPhone, 27/11/2026). Todo canal passa por aqui.
  async function criarCanal(ln) {
    try { if (ln.createChannel) await ln.createChannel({ id: 'nutricao', name: 'Refeições e água', description: 'Lembretes das refeições do plano alimentar e de beber água', importance: 4, visibility: 1, vibration: true }); } catch (e) {}
  }

  // Agenda (ou cancela) conforme as preferências e o plano. Devolve { ok, motivo, total }.
  async function agendar(plano) {
    var ln = LN(); if (!ln) return { ok: false, motivo: 'web' };
    var cfg = ler();
    await cancelarTodos(ln);
    if (!cfg.ativo) return { ok: true, total: 0 };
    if (!plano) return { ok: true, total: 0, motivo: 'sem-plano' };
    if (!(await permissao(ln))) return { ok: false, motivo: 'permissao' };
    await criarCanal(ln);
    var peso = 0; try { peso = _nutriUltimoPeso(); } catch (e) {}
    var meta = 0; try { meta = API.nutri.metaAguaMl(peso); } catch (e) {}
    var lista = montarLista(plano, cfg, meta);
    if (!lista.length) return { ok: true, total: 0 };
    var itens = lista.map(function (l) {
      var h = minutos(l.hora);
      return { id: l.id, channelId: 'nutricao', title: l.titulo, body: l.corpo, extra: { tipo: l.tipo === 'agua' ? 'nutri-agua' : 'nutri-refeicao' },
        schedule: { on: { hour: Math.floor(h / 60), minute: h % 60 }, repeats: true, allowWhileIdle: true } };
    });
    try {
      await ln.schedule({ notifications: itens });
      localStorage.setItem(chave('-ids'), JSON.stringify(lista.map(function (l) { return l.id; })));
      return { ok: true, total: lista.length };
    } catch (e) { return { ok: false, motivo: 'erro' }; }
  }

  // Aviso de teste em 5 segundos (para a pessoa ver como chega).
  async function testar() {
    var ln = LN(); if (!ln) { toast('O teste funciona no app da Intus no celular.'); return; }
    if (!(await permissao(ln))) { toast('Permita as notificações do Intus Fit nos ajustes do celular.'); return; }
    try {
      await criarCanal(ln);
      await ln.schedule({ notifications: [{ id: ID_TESTE, channelId: 'nutricao', title: '💧 Lembrete de teste', body: 'Assim chegam os avisos das suas refeições e da água.', extra: { tipo: 'nutri-agua' }, schedule: { at: new Date(Date.now() + 5000), allowWhileIdle: true } }] });
      toast('Pronto! O aviso de teste chega em 5 segundos. Pode sair do app para ver.');
    } catch (e) { toast('Não consegui agendar o teste.'); }
  }

  // ── sincronização: o plano pode ter mudado desde a última vez ──
  var _sincEm = 0;
  async function sincronizar(forcar) {
    try {
      if (typeof idAtleta === 'undefined' || !idAtleta || !LN()) return;
      var cfg = ler(); if (!cfg.ativo) return;
      if (!forcar && Date.now() - _sincEm < 20 * 60 * 1000) return;
      _sincEm = Date.now();
      var planos = await API.listarPlanosNutricionais(idAtleta);
      var plano = (Array.isArray(planos) ? planos : []).find(function (p) { return Number(p.ativo) === 1; }) || null;
      var assinatura = JSON.stringify([plano ? API.nutri.refeicoes(plano).map(function (r) { return r.horario + r.nome; }) : null, cfg]);
      if (!forcar && assinatura === localStorage.getItem(chave('-sig'))) return;      // nada mudou: o que já está agendado vale
      var r = await agendar(plano);
      if (r.ok) localStorage.setItem(chave('-sig'), assinatura);
    } catch (e) {}
  }

  // ── tela ──
  function cores() { try { return _nutriCores(); } catch (e) { var l = document.body.classList.contains('tema-claro'); return { card: l ? '#fff' : '#141414', borda: l ? '#e5e5e5' : '#262626', mut: l ? '#666' : '#999', ok: '#7FFF00' }; } }

  function resumo() {
    var c = ler();
    if (!c.ativo) return 'Desligados. Toque para ligar.';
    var partes = [];
    if (c.refeicoes) partes.push('refeições' + (c.antes > 0 ? ' (' + c.antes + ' min antes)' : ''));
    if (c.agua) partes.push('água a cada ' + (c.aguaInt >= 60 ? (c.aguaInt % 60 === 0 ? c.aguaInt / 60 + ' h' : (c.aguaInt / 60).toFixed(1).replace('.', ',') + ' h') : c.aguaInt + ' min'));
    return 'Ligados: ' + (partes.join(' e ') || 'nada selecionado');
  }
  function cartao() {
    try { if (!_nutriTemPlano()) return ''; } catch (e) { return ''; }
    var C = cores(), on = ler().ativo;
    return '<div onclick="LembretesNutri.abrir()" style="display:flex;align-items:center;gap:10px;background:' + C.card + ';border:1px solid ' + (on ? C.ok : C.borda) + ';border-radius:14px;padding:10px 12px;margin-bottom:10px;cursor:pointer;">' +
      '<span style="font-size:20px;line-height:1;">🔔</span>' +
      '<div style="flex:1;min-width:0;"><div style="font-size:12.5px;font-weight:800;">Lembretes de refeições e água</div><div style="font-size:10.5px;color:' + C.mut + ';line-height:1.35;margin-top:1px;">' + esc(resumo()) + '</div></div>' +
      '<span style="font-size:18px;color:' + C.mut + ';">›</span></div>';
  }

  function abrir() {
    var C = cores(), c = ler(), native = !!LN();
    var plano = null; try { plano = _nutri.plano; } catch (e) {}
    var luz = document.body.classList.contains('tema-claro');
    var campo = luz ? '#f5f5f5' : '#0d0d0d', txt = luz ? '#111' : '#f0f0f0';
    var chip = function (ativo, js, rot) {
      return '<button onclick="' + js + '" style="flex:1;padding:9px 4px;border-radius:10px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;background:' + (ativo ? '#7FFF00' : campo) + ';color:' + (ativo ? '#0f1a00' : txt) + ';border:1px solid ' + (ativo ? '#7FFF00' : C.borda) + ';">' + rot + '</button>';
    };
    var linhaSw = function (ativo, js, rot, sub) {
      return '<div onclick="' + js + '" style="display:flex;align-items:center;gap:10px;padding:12px 0;cursor:pointer;"><div style="flex:1;"><div style="font-size:14px;font-weight:800;">' + rot + '</div>' + (sub ? '<div style="font-size:11.5px;color:' + C.mut + ';line-height:1.4;margin-top:2px;">' + sub + '</div>' : '') + '</div>' +
        '<span style="flex-shrink:0;width:42px;height:24px;border-radius:99px;background:' + (ativo ? '#7FFF00' : 'rgba(128,128,128,.4)') + ';position:relative;"><span style="position:absolute;top:3px;left:' + (ativo ? '21px' : '3px') + ';width:18px;height:18px;border-radius:50%;background:#fff;"></span></span></div>';
    };
    var html = '';
    if (!native) html += '<div style="background:' + C.card + ';border:1px solid ' + C.borda + ';border-radius:12px;padding:12px 14px;font-size:12.5px;line-height:1.5;margin-bottom:10px;">Os lembretes com o app fechado funcionam no <b>app da Intus no celular</b> (iPhone e Android). Aqui no navegador você pode deixar as preferências prontas, mas o aviso só chega pelo app.</div>';
    html += linhaSw(c.ativo, 'LembretesNutri.alternar(\'ativo\')', 'Ligar lembretes', 'Avisos nos horários do seu plano alimentar. Se a nutri mudar o plano, os horários se ajustam sozinhos.');
    if (c.ativo) {
      html += '<div style="border-top:1px solid ' + C.borda + ';"></div>' + linhaSw(c.refeicoes, 'LembretesNutri.alternar(\'refeicoes\')', '🍽️ Refeições', 'Um aviso para cada refeição do plano.');
      if (c.refeicoes) html += '<div style="display:flex;gap:8px;margin:0 0 6px;">' + chip(c.antes === 0, 'LembretesNutri.antes(0)', 'Na hora') + chip(c.antes === 10, 'LembretesNutri.antes(10)', '10 min antes') + chip(c.antes === 30, 'LembretesNutri.antes(30)', '30 min antes') + '</div>';
      html += '<div style="border-top:1px solid ' + C.borda + ';margin-top:6px;"></div>' + linhaSw(c.agua, 'LembretesNutri.alternar(\'agua\')', '💧 Água', 'Avisos para beber água ao longo do dia.');
      if (c.agua) {
        html += '<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-bottom:8px;">' +
          '<label style="display:block;min-width:0;font-size:10px;font-weight:700;color:' + C.mut + ';text-transform:uppercase;">Começa às<input type="time" value="' + esc(c.aguaIni) + '" onchange="LembretesNutri.horario(\'aguaIni\', this.value)" style="display:block;width:100%;min-width:0;max-width:100%;height:42px;box-sizing:border-box;margin-top:4px;padding:0 6px;-webkit-appearance:none;appearance:none;text-align:center;line-height:40px;border-radius:10px;border:1px solid ' + C.borda + ';background:' + campo + ';color:' + txt + ';font-size:14px;font-family:inherit;"/></label>' +
          '<label style="display:block;min-width:0;font-size:10px;font-weight:700;color:' + C.mut + ';text-transform:uppercase;">Termina às<input type="time" value="' + esc(c.aguaFim) + '" onchange="LembretesNutri.horario(\'aguaFim\', this.value)" style="display:block;width:100%;min-width:0;max-width:100%;height:42px;box-sizing:border-box;margin-top:4px;padding:0 6px;-webkit-appearance:none;appearance:none;text-align:center;line-height:40px;border-radius:10px;border:1px solid ' + C.borda + ';background:' + campo + ';color:' + txt + ';font-size:14px;font-family:inherit;"/></label></div>' +
          '<div style="display:flex;gap:8px;margin-bottom:6px;">' + chip(c.aguaInt === 60, 'LembretesNutri.intervalo(60)', 'A cada 1 h') + chip(c.aguaInt === 90, 'LembretesNutri.intervalo(90)', '1h30') + chip(c.aguaInt === 120, 'LembretesNutri.intervalo(120)', '2 h') + chip(c.aguaInt === 180, 'LembretesNutri.intervalo(180)', '3 h') + '</div>';
      }
      var lista = montarLista(plano, c, 0);
      html += '<div style="border-top:1px solid ' + C.borda + ';margin-top:8px;padding-top:10px;font-size:11px;font-weight:800;color:' + C.mut + ';text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Horários dos avisos</div>';
      var ref = lista.filter(function (l) { return l.tipo === 'refeicao'; }), ag = lista.filter(function (l) { return l.tipo === 'agua'; });
      html += '<div style="font-size:12.5px;line-height:1.7;">' + (ref.length ? '<b>Refeições:</b> ' + ref.map(function (l) { return esc(l.hora); }).join(' · ') + '<br/>' : '') + (ag.length ? '<b>Água:</b> ' + ag.map(function (l) { return esc(l.hora); }).join(' · ') : '') + (!lista.length ? '<span style="color:' + C.mut + ';">Nenhum aviso selecionado.</span>' : '') + '</div>';
      html += '<button onclick="LembretesNutri.testar()" style="width:100%;margin-top:14px;padding:12px;border-radius:12px;border:1px solid ' + C.borda + ';background:' + C.card + ';color:' + txt + ';font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;">🔔 Enviar um aviso de teste agora</button>';
      html += '<div style="font-size:11px;color:' + C.mut + ';line-height:1.5;margin-top:10px;">Se os avisos não chegarem, confira se as notificações do Intus Fit estão permitidas nos ajustes do celular e se o modo Não Perturbe ou Foco não está bloqueando.</div>';
    }
    _comSheet('lnb-sheet', '🔔 Lembretes', html, 100010);
  }

  async function aplicar() {
    var plano = null; try { plano = _nutri.plano; } catch (e) {}
    var r = await agendar(plano);
    if (r.ok) { try { localStorage.setItem(chave('-sig'), JSON.stringify([plano ? API.nutri.refeicoes(plano).map(function (x) { return x.horario + x.nome; }) : null, ler()])); } catch (e) {} }
    if (!r.ok && r.motivo === 'permissao') {
      var c = ler(); c.ativo = false; gravar(c);
      toast('Sem permissão de notificação. Ative em Ajustes do celular > Intus Fit > Notificações.', 6000);
    } else if (!r.ok && r.motivo === 'erro') toast('Não consegui agendar os lembretes. Tente de novo.');
    abrir(); refrescarCartao();
  }
  function refrescarCartao() { try { if (window._viewAtual === 'nutricao' && _nutri.tela === 'home' && typeof _nutriRender === 'function') _nutriRender(); } catch (e) {} }
  function alternar(k) { var c = ler(); c[k] = !c[k]; gravar(c); aplicar(); }
  function antes(n) { var c = ler(); c.antes = n; gravar(c); aplicar(); }
  function intervalo(n) { var c = ler(); c.aguaInt = n; gravar(c); aplicar(); }
  function horario(k, v) { if (minutos(v) == null) return; var c = ler(); c[k] = v; gravar(c); aplicar(); }

  // Toque no aviso: abre a Nutrição.
  function ligarToque() {
    var ln = LN(); if (!ln || !ln.addListener) return;
    try {
      ln.addListener('localNotificationActionPerformed', function (ev) {
        var t = ev && ev.notification && ev.notification.extra && ev.notification.extra.tipo;
        if (t === 'nutri-refeicao' || t === 'nutri-agua') { try { _nutri.tela = 'home'; navegar('nutricao'); } catch (e) {} }
      });
    } catch (e) {}
  }

  setTimeout(function () { ligarToque(); sincronizar(false); }, 4000);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') sincronizar(false); });

  window.LembretesNutri = { cartao: cartao, abrir: abrir, alternar: alternar, antes: antes, intervalo: intervalo, horario: horario, testar: testar, sincronizar: sincronizar,
    _teste: { montarLista: montarLista, agendar: agendar, ler: ler } };
})();
