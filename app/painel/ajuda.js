/* ═══════════════════════════════════════════════════════════════════════════
   AJUDA DO INTUS FIT: tour interativo + central de ajuda (botão "?" do topo)
   ───────────────────────────────────────────────────────────────────────────
   Carregado por aluno.html. Não mexe em nenhuma tela existente: cria as próprias
   camadas (#ax-tour e #ax-help) por cima do app e só usa funções que o app já tem
   (navegar, abrirCardio, abrirRanking, abrirSuporte...).

   • Ajuda.abrir()      central de ajuda (busca, mini-aulas, primeiros passos, perguntas)
   • Ajuda.tour(i)      tour interativo, começando no capítulo i (undefined = abertura)
   • Ajuda.bemVindo()   abertura do tour para aluno novo

   Cada capítulo do tour é uma mini-demonstração do app em que o aluno TOCA nos botões que
   pulsam (marcar a série, escolher uma reação...). O conteúdo dos capítulos e das perguntas
   usa as regras reais do app (API.regrasRanking, CONQUISTAS_DEF) para não ficar desatualizado.
   Estado em localStorage['intus-ajuda-v2']. Sem dependências.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';
  if (window.Ajuda) return;

  var KEY = 'intus-ajuda-v2';
  var estado = { vistos: {}, tourFim: 0, abriu: 0 };
  try { var salvo = JSON.parse(localStorage.getItem(KEY) || 'null'); if (salvo && typeof salvo === 'object') estado = Object.assign(estado, salvo); } catch (e) {}
  function salvar() { try { localStorage.setItem(KEY, JSON.stringify(estado)); } catch (e) {} }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  function negrito(t) { return String(t).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>'); }
  function claro() { return document.body.classList.contains('tema-claro'); }
  function primeiroNome() { try { return String((window.user && user.nome) || '').trim().split(/\s+/)[0] || ''; } catch (e) { return ''; } }
  function regras() { try { return API.regrasRanking(); } catch (e) { return { musBase: 10, musMeia: 5, musCurtaMin: 20, musSegundo: 5, cardioTeto: 7, bonusDias: 4, bonusPts: 5, medalhaSemana: [25, 18, 12, 7, 4] }; } }

  /* ───────────────────────────── CSS ───────────────────────────── */
  var css = document.createElement('style');
  css.textContent = [
    /* ===== camadas ===== */
    '#ax-tour,#ax-help{position:fixed;inset:0;z-index:100040;display:none;flex-direction:column;font-family:Inter,system-ui,sans-serif;-webkit-tap-highlight-color:transparent;}',
    '#ax-tour.on,#ax-help.on{display:flex;}',
    '.ax-ico{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex-shrink:0;}',
    /* ===== TOUR (sempre escuro, cinematográfico) ===== */
    '#ax-tour{background:#050505;color:#fff;overflow:hidden;}',
    '#ax-tour::before{content:"";position:absolute;inset:-20% -30% auto -30%;height:70%;background:radial-gradient(closest-side,rgba(127,255,0,.20),transparent 70%);pointer-events:none;}',
    '.ax-t-top{position:relative;z-index:3;padding:calc(10px + env(safe-area-inset-top,0px)) 14px 0;display:flex;flex-direction:column;gap:10px;flex-shrink:0;}',
    '.ax-seg{display:flex;gap:4px;}',
    '.ax-seg i{flex:1;height:3px;border-radius:3px;background:rgba(255,255,255,.2);position:relative;overflow:hidden;cursor:pointer;}',
    '.ax-seg i.ok{background:rgba(127,255,0,.55);}',
    '.ax-seg i.cur{background:#7FFF00;}',
    '.ax-t-bar{display:flex;align-items:center;justify-content:space-between;min-height:34px;}',
    '.ax-t-bar .qual{font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#7FFF00;}',
    '.ax-x{width:34px;height:34px;border-radius:50%;border:none;background:rgba(255,255,255,.12);color:#fff;font-size:20px;line-height:1;cursor:pointer;display:grid;place-items:center;}',
    '.ax-t-main{position:relative;z-index:2;flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;display:flex;flex-direction:column;align-items:center;padding:6px 20px 10px;}',
    '.ax-h{font-size:clamp(22px,6.2vw,28px);font-weight:900;letter-spacing:-.02em;line-height:1.1;text-align:center;text-wrap:balance;margin:6px 0 4px;}',
    '.ax-sub{font-size:13.5px;color:rgba(255,255,255,.66);text-align:center;max-width:320px;line-height:1.45;margin-bottom:12px;}',
    '.ax-leg{min-height:64px;max-width:330px;text-align:center;font-size:14.5px;line-height:1.5;color:rgba(255,255,255,.92);margin-top:14px;}',
    '.ax-leg b{color:#7FFF00;font-weight:800;}',
    '.ax-passos{display:flex;gap:6px;justify-content:center;margin-top:8px;}',
    '.ax-passos i{width:7px;height:7px;border-radius:50%;background:rgba(255,255,255,.22);transition:all .25s;}',
    '.ax-passos i.on{background:#7FFF00;width:20px;border-radius:5px;}',
    '.ax-passos i.ok{background:rgba(127,255,0,.55);}',
    '.ax-t-rod{position:relative;z-index:3;display:flex;gap:10px;padding:10px 16px calc(14px + env(safe-area-inset-bottom,0px));flex-shrink:0;}',
    '.ax-b{flex:1;border:none;border-radius:14px;padding:14px 10px;font-family:inherit;font-weight:800;font-size:14.5px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;}',
    '.ax-b.pri{background:linear-gradient(135deg,#7FFF00,#5fd000);color:#0b1400;}',
    '.ax-b.sec{background:rgba(255,255,255,.1);color:#fff;border:1px solid rgba(255,255,255,.14);}',
    '.ax-b.peq{flex:0 0 auto;padding:14px 16px;}',
    '.ax-link{background:none;border:none;color:rgba(255,255,255,.6);font-family:inherit;font-size:12.5px;font-weight:700;text-decoration:underline;cursor:pointer;margin-top:8px;}',
    /* abertura e encerramento */
    '.ax-cap{width:100%;max-width:340px;margin:0 auto;text-align:center;}',
    '.ax-logo{height:40px;margin:18px auto 14px;display:block;}',
    '.ax-big{font-size:clamp(28px,8vw,36px);font-weight:900;letter-spacing:-.03em;line-height:1.05;margin-bottom:10px;text-wrap:balance;}',
    '.ax-big em{font-style:normal;color:#7FFF00;}',
    '.ax-lista{display:flex;flex-wrap:wrap;gap:7px;justify-content:center;margin:16px 0 4px;}',
    '.ax-pill{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:99px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:#fff;font-size:12.5px;font-weight:700;font-family:inherit;cursor:pointer;}',
    '.ax-pill .ax-ico{width:15px;height:15px;color:#7FFF00;}',
    '.ax-pill.ok{border-color:rgba(127,255,0,.45);}',
    /* ===== celular da demonstração ===== */
    '.ax-phone{width:244px;height:336px;border-radius:32px;background:#0a0a0a;border:6px solid #1d1d1d;box-shadow:0 24px 60px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.06),0 0 80px rgba(127,255,0,.10);position:relative;overflow:hidden;flex-shrink:0;color:#f0f0f0;font-size:11px;line-height:1.35;}',
    '@media (max-height:720px){.ax-phone{transform:scale(.88);margin:-20px 0 -20px;}}',
    '@media (max-height:620px){.ax-phone{transform:scale(.76);margin:-40px 0 -40px;}}',
    '.ax-tela{position:absolute;inset:0;padding:12px 11px;display:none;overflow:hidden;}',
    '.ax-tela.on{display:block;animation:axEntra .38s cubic-bezier(.2,.8,.2,1) both;}',
    '@keyframes axEntra{from{opacity:0;transform:translateY(14px) scale(.98)}to{opacity:1;transform:none}}',
    '.axm-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:9px;}',
    '.axm-top b{font-size:13px;font-weight:900;}',
    '.axm-mut{color:#8a8a8a;}',
    '.axm-card{background:#161616;border:1px solid #262626;border-radius:14px;padding:10px;margin-bottom:8px;}',
    '.axm-big{font-size:19px;font-weight:900;letter-spacing:-.02em;line-height:1.1;}',
    '.axm-btn{display:block;width:100%;border:none;border-radius:11px;padding:10px;background:linear-gradient(135deg,#7FFF00,#5fd000);color:#0b1400;font-weight:900;font-size:12px;text-align:center;font-family:inherit;margin-top:8px;}',
    '.axm-btn.gh{background:#1b1b1b;color:#e8e8e8;border:1px solid #2c2c2c;font-weight:800;}',
    '.axm-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 9px;border-radius:99px;background:rgba(127,255,0,.13);color:#7FFF00;font-weight:800;font-size:10.5px;}',
    '.axm-chip.g{background:#1d1d1d;color:#bdbdbd;}',
    '.axm-set{display:grid;grid-template-columns:42px 1fr 1fr 34px;gap:5px;align-items:center;background:#0d0d0d;border:1.5px solid #1a1a1a;border-radius:9px;padding:6px;margin-bottom:5px;opacity:.85;}',
    '.axm-set.cur{border-color:#7FFF00;background:rgba(127,255,0,.04);opacity:1;}',
    '.axm-set.fe{border-color:rgba(127,255,0,.3);background:rgba(127,255,0,.05);opacity:.7;}',
    '.axm-cel{text-align:center;background:rgba(127,255,0,.07);border:1px solid rgba(127,255,0,.2);border-radius:7px;padding:2px;line-height:1.1;}',
    '.axm-cel b{display:block;font-size:8px;color:#8a8a8a;letter-spacing:.06em;}',
    '.axm-cel i{display:block;font-style:normal;font-size:14px;font-weight:900;color:#7FFF00;}',
    '.axm-in{background:#111;border:1px solid #222;border-radius:7px;padding:6px 2px;text-align:center;font-weight:800;font-size:11.5px;}',
    '.axm-in.ph{color:#6e7276;}',
    '.axm-ck{width:34px;height:34px;border-radius:8px;border:1px solid #2a2a2a;background:#161616;color:#7a7f85;display:grid;place-items:center;font-family:inherit;padding:0;}',
    '.axm-ck.on{background:linear-gradient(135deg,#7FFF00,#5fd000);color:#000;border-color:transparent;}',
    '.axm-ck svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round;}',
    '.axm-bar{height:7px;border-radius:7px;background:#2a2a2a;overflow:hidden;margin:6px 0 4px;}',
    '.axm-bar i{display:block;height:100%;border-radius:7px;background:linear-gradient(90deg,#7FFF00,#40c000);}',
    '.axm-row{display:flex;align-items:center;gap:7px;}',
    '.axm-av{width:24px;height:24px;border-radius:50%;background:#252525;display:grid;place-items:center;font-size:9px;font-weight:900;color:#7FFF00;flex-shrink:0;}',
    '.axm-img{background:linear-gradient(160deg,#2a3a1c,#0e130a 70%);border-radius:10px;}',
    '.axm-pop{position:absolute;left:50%;transform:translateX(-50%);background:#7FFF00;color:#0b1400;font-weight:900;padding:5px 11px;border-radius:99px;font-size:11px;box-shadow:0 8px 20px rgba(0,0,0,.5);animation:axPop .6s cubic-bezier(.2,1.4,.3,1) both;white-space:nowrap;}',
    '@keyframes axPop{from{opacity:0;transform:translate(-50%,10px) scale(.7)}to{opacity:1;transform:translate(-50%,0) scale(1)}}',
    '.axm-gr{display:grid;gap:5px;}',
    /* dica pulsante: o aluno toca aqui */
    '[data-ir]{cursor:pointer;}',
    '[data-hint]{position:relative;z-index:2;animation:axPulso 1.5s ease-out infinite;border-radius:inherit;}',
    '@keyframes axPulso{0%{box-shadow:0 0 0 0 rgba(127,255,0,.75)}70%{box-shadow:0 0 0 11px rgba(127,255,0,0)}100%{box-shadow:0 0 0 0 rgba(127,255,0,0)}}',
    '[data-hint]::after{content:"";position:absolute;right:-5px;bottom:-9px;width:22px;height:22px;background:url("data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'white\' stroke=\'black\' stroke-width=\'1.2\'><path d=\'M9 11V5a1.5 1.5 0 0 1 3 0v5.2l5.4 1.1A2.5 2.5 0 0 1 19.4 14l-.6 5a2.5 2.5 0 0 1-2.5 2.2H11a2.5 2.5 0 0 1-2-1l-3.4-4.6a1.6 1.6 0 0 1 2.5-2L9 14.2z\'/></svg>") center/contain no-repeat;animation:axDedo 1.5s ease-in-out infinite;pointer-events:none;z-index:5;}',
    '@keyframes axDedo{0%,100%{transform:translate(0,0)}50%{transform:translate(-4px,-5px) scale(.92)}}',
    '.ax-shake{animation:axShake .35s;}',
    '@keyframes axShake{0%,100%{transform:translateX(0)}25%{transform:translateX(-5px)}75%{transform:translateX(5px)}}',
    /* ===== CENTRAL DE AJUDA (acompanha o tema do app) ===== */
    '#ax-help{--h-bg:#0a0a0a;--h-card:#161616;--h-line:#262626;--h-txt:#f0f0f0;--h-mut:#8e8e8e;--h-acc:#7FFF00;--h-accsoft:rgba(127,255,0,.12);--h-accfg:#0b1400;background:var(--h-bg);color:var(--h-txt);}',
    'body.tema-claro #ax-help{--h-bg:#f5f5f5;--h-card:#ffffff;--h-line:#e4e4e4;--h-txt:#1a1a1a;--h-mut:#6b6b6b;--h-acc:#2f8a00;--h-accsoft:rgba(92,184,0,.12);--h-accfg:#fff;}',
    '.ax-h-top{display:flex;align-items:center;gap:10px;padding:calc(12px + env(safe-area-inset-top,0px)) 14px 10px;flex-shrink:0;border-bottom:1px solid var(--h-line);background:var(--h-bg);}',
    '.ax-h-top h2{font-size:17px;font-weight:900;margin:0;flex:1;letter-spacing:-.01em;}',
    '.ax-h-top .ax-x{background:var(--h-card);border:1px solid var(--h-line);color:var(--h-txt);}',
    '.ax-h-scroll{flex:1;overflow-y:auto;padding:14px 14px calc(30px + env(safe-area-inset-bottom,0px));-webkit-overflow-scrolling:touch;overscroll-behavior:contain;}',
    '.ax-h-wrap{max-width:520px;margin:0 auto;}',
    '.ax-hero{position:relative;overflow:hidden;border-radius:20px;padding:18px;background:linear-gradient(135deg,#102006,#0a0a0a 70%);border:1px solid rgba(127,255,0,.28);color:#fff;margin-bottom:12px;}',
    '.ax-hero::after{content:"";position:absolute;right:-40px;top:-40px;width:150px;height:150px;border-radius:50%;background:radial-gradient(closest-side,rgba(127,255,0,.28),transparent);}',
    '.ax-hero small{display:block;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#7FFF00;margin-bottom:5px;}',
    '.ax-hero h3{font-size:20px;font-weight:900;letter-spacing:-.02em;margin:0 0 6px;line-height:1.15;position:relative;z-index:1;}',
    '.ax-hero p{font-size:12.5px;color:rgba(255,255,255,.72);margin:0 0 12px;line-height:1.45;max-width:300px;position:relative;z-index:1;}',
    '.ax-hero .ax-b{flex:none;display:inline-flex;padding:11px 18px;font-size:13px;position:relative;z-index:1;}',
    '.ax-ctx{display:flex;align-items:center;gap:11px;background:var(--h-accsoft);border:1px solid color-mix(in srgb,var(--h-acc) 35%,transparent);border-radius:16px;padding:12px 14px;margin-bottom:12px;cursor:pointer;}',
    '.ax-ctx b{display:block;font-size:13px;}',
    '.ax-ctx span{font-size:12px;color:var(--h-mut);}',
    '.ax-ctx .ax-ico{color:var(--h-acc);width:26px;height:26px;}',
    '.ax-busca{display:flex;align-items:center;gap:9px;background:var(--h-card);border:1px solid var(--h-line);border-radius:14px;padding:0 13px;margin-bottom:14px;}',
    '.ax-busca .ax-ico{width:18px;height:18px;color:var(--h-mut);}',
    '.ax-busca input{flex:1;background:none;border:none;outline:none;color:var(--h-txt);font-family:inherit;font-size:14px;padding:13px 0;min-width:0;}',
    '.ax-busca input::placeholder{color:var(--h-mut);}',
    '.ax-sec{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--h-mut);margin:18px 2px 8px;}',
    '.ax-aulas{display:grid;grid-template-columns:1fr 1fr;gap:9px;}',
    '.ax-aula{background:var(--h-card);border:1px solid var(--h-line);border-radius:16px;padding:12px;cursor:pointer;text-align:left;font-family:inherit;color:var(--h-txt);position:relative;}',
    '.ax-aula .ax-ico{color:var(--h-acc);margin-bottom:8px;width:24px;height:24px;}',
    '.ax-aula b{display:block;font-size:13px;line-height:1.2;margin-bottom:2px;}',
    '.ax-aula span{font-size:11px;color:var(--h-mut);}',
    '.ax-aula .vis{position:absolute;top:10px;right:10px;width:18px;height:18px;border-radius:50%;background:var(--h-acc);color:var(--h-accfg);display:grid;place-items:center;}',
    '.ax-aula .vis svg{width:11px;height:11px;fill:none;stroke:currentColor;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;}',
    '.ax-pp{background:var(--h-card);border:1px solid var(--h-line);border-radius:16px;overflow:hidden;}',
    '.ax-pp-l{display:flex;align-items:center;gap:11px;padding:12px 14px;border-bottom:1px solid var(--h-line);cursor:pointer;}',
    '.ax-pp-l:last-child{border-bottom:none;}',
    '.ax-pp-c{width:22px;height:22px;border-radius:50%;border:2px solid var(--h-line);display:grid;place-items:center;flex-shrink:0;color:var(--h-accfg);}',
    '.ax-pp-c svg{width:12px;height:12px;fill:none;stroke:currentColor;stroke-width:3.2;stroke-linecap:round;stroke-linejoin:round;opacity:0;}',
    '.ax-pp-l.ok .ax-pp-c{background:var(--h-acc);border-color:var(--h-acc);}',
    '.ax-pp-l.ok .ax-pp-c svg{opacity:1;}',
    '.ax-pp-l.ok .t{color:var(--h-mut);text-decoration:line-through;}',
    '.ax-pp-l .t{font-size:13.5px;font-weight:700;flex:1;}',
    '.ax-pp-l .go{font-size:11.5px;font-weight:800;color:var(--h-acc);}',
    '.ax-prog{height:6px;border-radius:6px;background:var(--h-line);overflow:hidden;margin:0 2px 8px;}',
    '.ax-prog i{display:block;height:100%;background:var(--h-acc);border-radius:6px;transition:width .5s;}',
    '.ax-grupo{font-size:12px;font-weight:800;color:var(--h-acc);margin:16px 2px 6px;}',
    '.ax-q{background:var(--h-card);border:1px solid var(--h-line);border-radius:14px;margin-bottom:7px;overflow:hidden;}',
    '.ax-q summary{list-style:none;cursor:pointer;padding:13px 14px;font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:10px;min-height:46px;}',
    '.ax-q summary::-webkit-details-marker{display:none;}',
    '.ax-q summary::after{content:"+";margin-left:auto;font-size:18px;font-weight:500;color:var(--h-mut);transition:transform .2s;}',
    '.ax-q[open] summary::after{transform:rotate(45deg);}',
    '.ax-q .r{padding:0 14px 14px;font-size:13px;line-height:1.6;color:var(--h-mut);}',
    '.ax-q .r b{color:var(--h-txt);}',
    '.ax-q .r a,.ax-q .r .lk{color:var(--h-acc);font-weight:800;cursor:pointer;text-decoration:underline;}',
    '.ax-q .r .lk-b{display:inline-block;margin-top:8px;padding:8px 14px;border-radius:10px;background:var(--h-accsoft);color:var(--h-acc);font-weight:800;font-size:12.5px;cursor:pointer;}',
    '.ax-vazio{text-align:center;color:var(--h-mut);font-size:13px;padding:26px 10px;}',
    '.ax-fale{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:6px;}',
    '.ax-fale button{background:var(--h-card);border:1px solid var(--h-line);border-radius:14px;padding:13px 10px;color:var(--h-txt);font-family:inherit;font-weight:800;font-size:12.5px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;}',
    '.ax-fale .ax-ico{color:var(--h-acc);width:18px;height:18px;}',
    '@media (prefers-reduced-motion:reduce){[data-hint],[data-hint]::after,.ax-tela.on,.axm-pop{animation:none!important;}}'
  ].join('\n');
  document.head.appendChild(css);

  /* ───────────────────────────── ícones ───────────────────────────── */
  var P = {
    treino: '<path d="M6.5 6.5v11M17.5 6.5v11M3 9.5v5M21 9.5v5M6.5 12h11"/>',
    cardio: '<circle cx="14" cy="5" r="2"/><path d="M8 21l3-6 3 2 2 4M11 15l-1-5 4-2 3 3 3 1M10 10l-4 2"/>',
    ranking: '<path d="M8 4h8v5a4 4 0 0 1-8 0z"/><path d="M8 6H4v2a3 3 0 0 0 4 2M16 6h4v2a3 3 0 0 1-4 2M12 13v4M8 21h8M10 17h4"/>',
    medalha: '<circle cx="12" cy="9" r="5"/><path d="M9 13.5L7 21l5-3 5 3-2-7.5"/>',
    feed: '<path d="M4 5h16v11H9l-5 4z"/>',
    amigos: '<circle cx="9" cy="8" r="3.5"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20c0-3.5 3-6 7-6s7 2.5 7 6M16 14c3 0 6 1.5 6 5"/>',
    nutri: '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3.5"/>',
    evol: '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
    central: '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M4 7l8 6 8-6"/>',
    busca: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/>',
    seta: '<path d="M9 6l6 6-6 6"/>',
    voltar: '<path d="M15 6l-6 6 6 6"/>',
    ajuda: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.6 2.6 0 0 1 5 1c0 1.8-2.5 2.2-2.5 4M12 17.5h.01"/>',
    zap: '<path d="M13 3L5 14h6l-1 7 8-11h-6z"/>',
    play: '<path d="M8 5v14l11-7z"/>'
  };
  function ico(n, cls) { return '<svg class="ax-ico ' + (cls || '') + '" viewBox="0 0 24 24" aria-hidden="true">' + (P[n] || '') + '</svg>'; }
  var CK = '<svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>';

  /* ───────────────────── blocos do celular de demonstração ───────────────────── */
  function topo(t, dir) { return '<div class="axm-top"><b>' + t + '</b>' + (dir || '') + '</div>'; }
  function serie(n, alvo, kg, reps, st, attr, kgPh) {
    return '<div class="axm-set ' + st + '"><div class="axm-cel"><b>S' + n + '</b><i>' + alvo + '</i></div>' +
      '<div class="axm-in' + (kgPh ? ' ph' : '') + '">' + kg + '</div><div class="axm-in' + (kgPh ? ' ph' : '') + '">' + reps + '</div>' +
      '<button class="axm-ck ' + (st === 'fe' ? 'on' : '') + '" ' + (attr || '') + '>' + CK + '</button></div>';
  }
  function barra(p) { return '<div class="axm-bar"><i style="width:' + p + '%"></i></div>'; }
  function av(t) { return '<div class="axm-av">' + t + '</div>'; }

  /* ───────────────────────────── CAPÍTULOS ───────────────────────────── */
  function capitulos() {
    var R = regras();
    var conq = (typeof CONQUISTAS_DEF !== 'undefined' && CONQUISTAS_DEF.length) ? CONQUISTAS_DEF : [
      { e: '🎉', nome: 'Bem-vindo ao jogo!', desc: 'Você registrou sua primeira atividade no app.' },
      { e: '🔥', nome: 'Esquentando os motores', desc: '5 atividades registradas.' },
      { e: '💪', nome: 'Dedicado', desc: '25 atividades no total.' },
      { e: '💯', nome: 'Centurião', desc: '100 atividades registradas.' },
      { e: '🏃', nome: 'Primeiro fôlego', desc: 'Seu primeiro cardio registrado.' },
      { e: '🧱', nome: 'Pedreiro do shape', desc: '25 treinos de musculação concluídos.' }
    ];
    var ex = conq.filter(function (c) { return c.e; }).slice(0, 6);
    var mus4 = 4 * R.musBase;
    return [
      /* 1 ─ TREINO */
      {
        id: 'treino', icone: 'treino', titulo: 'Seu treino do dia', curto: 'Treino', promessa: 'Do toque em Iniciar até a série marcada, em um minuto.',
        abrir: ['Abrir meus treinos', function () { abrirMeusTreinos(); }],
        telas: [
          { leg: 'No **Início** aparece o treino de hoje. Toque em **Iniciar treino**.',
            html: function () { return topo('Hoje', '<span class="axm-chip">seg</span>') + '<div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:700;">Seu treino</div><div class="axm-big" style="margin:3px 0;">Treino A</div><div class="axm-mut">Peito e tríceps · 8 exercícios</div><div class="axm-btn" data-ir="1" data-hint>Iniciar treino</div></div><div class="axm-card"><div class="axm-row"><span class="axm-chip">Semana</span><span class="axm-mut">1 de 4 treinos</span></div>' + barra(25) + '</div>'; } },
          { leg: 'Cada série mostra as **repetições sugeridas**, a carga e o que você fez. Toque no quadrado para **marcar a série**.',
            html: function () { return topo('Supino reto', '<span class="axm-chip g">4 séries</span>') + serie(1, '10', '40', '10', 'cur', 'data-ir="2" data-hint', true) + serie(2, '10', 'kg', 'reps', '', '', true) + serie(3, '10', 'kg', 'reps', '', '', true) + serie(4, '10', 'kg', 'reps', '', '', true) + '<div class="axm-mut" style="text-align:center;margin-top:6px;">As setas ajustam carga e repetições</div>'; } },
          { leg: 'Ao marcar, o **descanso** começa sozinho. Depois é só seguir para a próxima série.',
            html: function () { return topo('Supino reto', '<span class="axm-chip g">4 séries</span>') + serie(1, '10', '40', '10', 'fe', '', false) + '<div class="axm-card" style="text-align:center;border-color:rgba(127,255,0,.4);margin:6px 0;"><div class="axm-mut" style="font-size:9.5px;font-weight:800;letter-spacing:.08em;">DESCANSO</div><div class="axm-big" style="color:#7FFF00;">01:00</div></div>' + serie(2, '10', '40', '10', 'cur', 'data-ir="3" data-hint', true) + serie(3, '10', 'kg', 'reps', '', '', true); } },
          { leg: 'Terminou todos os exercícios? Toque em **Finalizar treino**. Se ele foi muito curto, o app pede confirmação.',
            html: function () { return topo('Resumo', '<span class="axm-chip">8 de 8</span>') + '<div class="axm-card"><div class="axm-row"><div class="axm-big" style="color:#7FFF00;">100%</div><div class="axm-mut">concluído</div></div>' + barra(100) + '<div class="axm-mut">24 de 24 séries feitas</div></div><div class="axm-btn" data-ir="4" data-hint style="margin-top:14px;">Finalizar treino</div>'; } },
          { leg: 'Pronto. O treino vai para o **Histórico**, soma **' + R.musBase + ' pontos** no ranking e você já pode compartilhar a figurinha com os seus números.',
            html: function () { return '<div style="text-align:center;padding-top:8px;"><div style="font-size:34px;">🎉</div><div class="axm-big" style="margin:6px 0 2px;">Treino concluído</div><div class="axm-chip" style="margin:6px 0 10px;">+' + R.musBase + ' pts no ranking</div></div><div class="axm-card"><div class="axm-row" style="justify-content:space-around;text-align:center;"><div><div class="axm-big" style="font-size:15px;">52:10</div><div class="axm-mut">tempo</div></div><div><div class="axm-big" style="font-size:15px;">8.420</div><div class="axm-mut">kg</div></div><div><div class="axm-big" style="font-size:15px;">24/24</div><div class="axm-mut">séries</div></div></div></div><div class="axm-btn gh">Compartilhar no Feed</div>'; } }
        ]
      },
      /* 2 ─ CARDIO */
      {
        id: 'cardio', icone: 'cardio', titulo: 'Cardio ao vivo ou manual', curto: 'Cardio', promessa: 'Monitore a atividade pelo GPS ou registre depois.',
        abrir: ['Abrir meu cardio', function () { abrirCardio(); }],
        telas: [
          { leg: 'Em **Meu Cardio** você escolhe: monitorar ao vivo ou registrar manualmente, para natação, esteira, escada e outras.',
            html: function () { return topo('Meu Cardio') + '<div class="axm-card" style="border-color:rgba(127,255,0,.45);"><div class="axm-row"><div class="axm-av" style="width:34px;height:34px;background:linear-gradient(135deg,#7FFF00,#40c000);color:#000;font-size:15px;">📍</div><div><b style="font-size:12px;">Monitore seu cardio ao vivo</b><div class="axm-mut" style="font-size:10px;">Distância, tempo e ritmo na hora</div></div></div><div class="axm-btn" data-ir="1" data-hint>Abrir</div></div><div class="axm-card"><div class="axm-row"><span>✍️</span><div><b>Registrar manualmente</b><div class="axm-mut" style="font-size:10px;">Atividades sem GPS</div></div></div></div>'; } },
          { leg: 'Escolha a atividade e toque em **Começar a rastrear**. O app pede a permissão de localização.',
            html: function () { return topo('Cardio ao vivo') + '<div class="axm-row" style="gap:5px;margin-bottom:10px;"><span class="axm-chip">Corrida</span><span class="axm-chip g">Caminhada</span><span class="axm-chip g">Bike</span></div><div class="axm-card axm-img" style="height:130px;position:relative;"><div style="position:absolute;inset:0;background:radial-gradient(circle at 60% 40%,rgba(127,255,0,.25),transparent 55%);"></div><div style="position:absolute;left:10px;bottom:8px;" class="axm-chip">📍 Sinal GPS ok</div></div><div class="axm-btn" data-ir="2" data-hint>▶ Começar a rastrear</div>'; } },
          { leg: 'Durante a atividade você acompanha **tempo, distância e ritmo**. Quando terminar, toque em **Finalizar**.',
            html: function () { return topo('Correndo') + '<div style="text-align:center;margin:6px 0 10px;"><div class="axm-big" style="font-size:34px;">00:24:10</div><div class="axm-mut">duração</div></div><div class="axm-row" style="justify-content:space-around;text-align:center;margin-bottom:10px;"><div><div class="axm-big" style="font-size:16px;">3,8</div><div class="axm-mut">km</div></div><div><div class="axm-big" style="font-size:16px;">6\'22"</div><div class="axm-mut">ritmo</div></div></div><div class="axm-btn" data-ir="3" data-hint style="background:#ff453a;color:#fff;">Finalizar</div>'; } },
          { leg: 'O cardio soma pontos no ranking (até **' + R.cardioTeto + ' por sessão**) e ainda rende **+1 ponto extra a cada hora feita ao vivo** na semana.',
            html: function () { return '<div style="text-align:center;padding-top:6px;"><div style="font-size:32px;">🏃</div><div class="axm-big" style="margin:6px 0 4px;">Cardio salvo</div></div><div class="axm-card"><div class="axm-row" style="justify-content:space-between;"><span>Corrida · 24 min</span><span class="axm-chip">3,8 km</span></div></div><div class="axm-card" style="border-color:rgba(127,255,0,.4);"><div class="axm-row"><span style="color:#7FFF00;">🔴</span><span>+1 ponto extra por hora ao vivo na semana</span></div></div>'; } }
        ]
      },
      /* 3 ─ PONTOS E RANKING */
      {
        id: 'ranking', icone: 'ranking', titulo: 'Pontos e ranking', curto: 'Ranking', promessa: 'Entenda de onde vêm os pontos e como ganhar medalha.',
        abrir: ['Abrir o ranking', function () { abrirRanking(); }],
        telas: [
          { leg: 'O **Ranking** mostra quem mais se manteve constante na semana. Toque no **seu cartão** para ver de onde vieram os seus pontos.',
            html: function () { return topo('Ranking da semana') + ['Ana S. · 62', 'Bia M. · 55'].map(function (t, i) { return '<div class="axm-card axm-row"><b style="width:14px;">' + (i + 1) + '</b>' + av(t.slice(0, 2)) + '<span>' + t + '</span></div>'; }).join('') + '<div class="axm-card axm-row" style="border-color:#7FFF00;" data-ir="1" data-hint><b style="width:14px;">3</b>' + av('VC') + '<span><b>Você</b> · 51</span></div><div class="axm-card axm-row"><b style="width:14px;">4</b>' + av('CD') + '<span>Caio D. · 40</span></div>'; } },
          { leg: 'Exemplo de uma semana: **musculação** é a base, o **cardio** complementa e treinar em **' + R.bonusDias + ' dias** diferentes dá um **bônus**. Toque no bônus.',
            html: function () { return topo('Sua semana', '<span class="axm-chip">51 pts</span>') + '<div class="axm-card"><div class="axm-row" style="justify-content:space-between;"><span>💪 Musculação · 4 dias</span><b>' + mus4 + '</b></div></div><div class="axm-card"><div class="axm-row" style="justify-content:space-between;"><span>🏃 Cardio · 2 sessões</span><b>6</b></div></div><div class="axm-card axm-row" style="justify-content:space-between;border-color:rgba(127,255,0,.45);" data-ir="2" data-hint><span>🔥 Bônus de frequência</span><b style="color:#7FFF00;">+' + R.bonusPts + '</b></div>'; } },
          { leg: 'As regras: **' + R.musBase + ' pontos** por treino de musculação no dia (curto, abaixo de ' + R.musCurtaMin + ' min, vale ' + R.musMeia + '). O 2º treino do dia soma ' + R.musSegundo + ' e do 3º em diante não soma. Toque para ver o fechamento.',
            html: function () { return topo('Regras') + '<div class="axm-card"><div class="axm-row"><span style="font-size:18px;">💪</span><div><b>1º treino do dia</b><div class="axm-mut">' + R.musBase + ' pts (curto: ' + R.musMeia + ')</div></div></div></div><div class="axm-card"><div class="axm-row"><span style="font-size:18px;">🔁</span><div><b>2º treino do dia</b><div class="axm-mut">' + R.musSegundo + ' pts</div></div></div></div><div class="axm-card"><div class="axm-row"><span style="font-size:18px;">🏃</span><div><b>Cardio</b><div class="axm-mut">até ' + R.cardioTeto + ' pts por sessão</div></div></div></div><div class="axm-btn gh" data-ir="3" data-hint>Como fecha a semana</div>'; } },
          { leg: 'A semana vai de **domingo a sábado**. Fecha no sábado e todo domingo começa uma nova, **com todo mundo empatado**. Quem fecha no topo ganha **medalha**.',
            html: function () { var m = R.medalhaSemana || [25, 18, 12]; return topo('Semana e medalhas') + '<div class="axm-row" style="justify-content:space-between;margin-bottom:10px;">' + ['D', 'S', 'T', 'Q', 'Q', 'S', 'S'].map(function (d, i) { return '<div style="text-align:center;"><div class="axm-mut" style="font-size:9px;">' + d + '</div><div class="axm-ck ' + (i < 5 ? 'on' : '') + '" style="width:26px;height:26px;margin-top:3px;">' + (i < 5 ? CK : '') + '</div></div>'; }).join('') + '</div><div class="axm-card"><div class="axm-row" style="align-items:flex-end;justify-content:center;gap:10px;height:90px;"><div style="text-align:center;"><div>🥈</div><div class="axm-card" style="height:40px;margin:0;padding:6px 10px;">' + m[1] + '</div></div><div style="text-align:center;"><div>🥇</div><div class="axm-card" style="height:58px;margin:0;padding:6px 10px;border-color:#7FFF00;color:#7FFF00;font-weight:900;">' + m[0] + '</div></div><div style="text-align:center;"><div>🥉</div><div class="axm-card" style="height:30px;margin:0;padding:6px 10px;">' + m[2] + '</div></div></div><div class="axm-mut" style="text-align:center;margin-top:6px;">pontos de medalha da semana</div></div>'; } }
        ]
      },
      /* 4 ─ CONQUISTAS */
      {
        id: 'conquistas', icone: 'medalha', titulo: 'Conquistas e medalhas', curto: 'Conquistas', promessa: 'Distintivos que marcam cada etapa da sua jornada.',
        abrir: ['Abrir meu perfil', function () { navegar('perfil'); }],
        telas: [
          { leg: 'Você ganha **distintivos** por marcos: primeira atividade, 25 treinos, constância e mais. Toque em um deles.',
            html: function () { return topo('Conquistas') + '<div class="axm-gr" style="grid-template-columns:1fr 1fr 1fr;">' + ex.map(function (c, i) { return '<div class="axm-card" style="text-align:center;padding:10px 4px;margin:0;" data-ir="1" data-sel="' + i + '"' + (i === 1 ? ' data-hint' : '') + '><div style="font-size:24px;">' + c.e + '</div><div style="font-size:9px;font-weight:800;margin-top:3px;">' + esc(c.nome) + '</div></div>'; }).join('') + '</div>'; } },
          { leg: 'Cada distintivo diz **como foi conquistado** e quanto falta. Toque em **Compartilhar** para criar uma imagem com a logo da Intus.',
            html: function (s) { var c = ex[s.sel || 0] || ex[0]; return '<div style="text-align:center;padding-top:4px;"><div style="font-size:46px;">' + c.e + '</div><div class="axm-big" style="font-size:16px;margin:6px 0 4px;">' + esc(c.nome) + '</div><div class="axm-mut" style="font-size:10.5px;line-height:1.45;padding:0 4px;">' + esc(String(c.desc || '').slice(0, 130)) + '</div></div><div class="axm-card" style="margin-top:10px;"><div class="axm-row" style="justify-content:space-between;"><span class="axm-mut">Progresso</span><b>conquistado</b></div>' + barra(100) + '</div><div class="axm-btn" data-ir="2" data-hint>Compartilhar</div>'; } },
          { leg: 'A imagem pode ir para o **Feed da Intus**, para o seu **perfil** e para outros apps. A logo vai junto, em qualquer lugar que você postar.',
            html: function (s) { var c = ex[s.sel || 0] || ex[0]; return topo('Compartilhar') + '<div class="axm-card axm-img" style="text-align:center;padding:18px 8px;"><div style="font-size:40px;">' + c.e + '</div><div class="axm-big" style="font-size:14px;margin-top:4px;">' + esc(c.nome) + '</div><div class="axm-mut" style="margin-top:8px;font-size:9px;letter-spacing:.2em;">INTUS FIT</div></div><div class="axm-gr"><div class="axm-btn gh" style="margin:0;">Feed da Intus</div><div class="axm-btn gh" style="margin:0;">Meu perfil</div><div class="axm-btn gh" style="margin:0;">Instagram e outros</div></div>'; } }
        ]
      },
      /* 5 ─ FEED */
      {
        id: 'feed', icone: 'feed', titulo: 'Feed e comunidade', curto: 'Feed', promessa: 'Poste, reaja, comente e escolha quem vê.',
        abrir: ['Abrir o Feed', function () { navegar('feed'); }],
        telas: [
          { leg: 'No **Feed** você vê os treinos de quem participa. Toque no coração para curtir ou no **＋** para outras reações.',
            html: function () { return topo('Feed dos Alunos') + '<div class="axm-row" style="margin-bottom:7px;">' + av('AS') + '<div><b>Ana Souza</b><div class="axm-mut" style="font-size:9.5px;">há 3h</div></div></div><div class="axm-img" style="height:120px;"></div><div class="axm-row" style="margin-top:9px;gap:12px;font-size:15px;"><span>🤍</span><span style="border:1px solid #444;border-radius:50%;width:22px;height:22px;display:grid;place-items:center;font-size:14px;" data-ir="1" data-hint>＋</span><span class="axm-mut" style="font-size:11px;">Comentar</span></div>'; } },
          { leg: 'São 13 reações. Para o **💪**, dá para escolher o **tom de pele**: toque e **segure** nele.',
            html: function () { return topo('Reagir') + '<div class="axm-card"><div style="display:grid;grid-template-columns:repeat(5,1fr);gap:4px;font-size:21px;text-align:center;">' + ['❤️', '🔥', '💪', '🦾', '👏', '✅', '💥', '👀', '😮', '😂', '🥹', '😤', '🥲'].map(function (e) { return e === '💪' ? '<span data-ir="2" data-hint style="border-radius:50%;">' + e + '</span>' : '<span>' + e + '</span>'; }).join('') + '</div></div><div class="axm-mut" style="text-align:center;">Segure o 💪 para mudar o tom</div>'; } },
          { leg: 'Aparecem os **6 tons**. Escolha o seu: ele vira o padrão e a reação já é dada.',
            html: function () { return topo('Tom do 💪') + '<div class="axm-card" style="padding:12px 6px;"><div style="display:flex;justify-content:space-around;font-size:24px;">' + ['💪', '💪🏻', '💪🏼', '💪🏽', '💪🏾', '💪🏿'].map(function (e, i) { return '<span' + (i === 3 ? ' data-ir="3" data-hint style="border-radius:50%;"' : '') + '>' + e + '</span>'; }).join('') + '</div></div><div class="axm-mut" style="text-align:center;">A escolha fica guardada no aparelho</div>'; } },
          { leg: 'Nos comentários, **responda a qualquer comentário**, inclusive às respostas, quantas vezes quiser.',
            html: function () { return topo('Comentários') + '<div class="axm-row" style="margin-bottom:6px;">' + av('BM') + '<div><b>Bia</b> Que treino!<div class="axm-mut" style="font-size:9.5px;">Responder</div></div></div><div class="axm-row" style="margin:0 0 6px 28px;">' + av('AS') + '<div><b>Ana</b> Foi pesado!<div class="axm-mut" style="font-size:9.5px;"><span data-ir="4" data-hint style="color:#7FFF00;font-weight:800;">Responder</span></div></div></div><div class="axm-card axm-row"><span class="axm-mut">Escreva um comentário...</span></div>'; } },
          { leg: 'Para postar: **foto** ou **vídeo de até 30 segundos**, com a figurinha do seu treino. Escolha **quem pode ver** e publique.',
            html: function () { return topo('Novo post') + '<div class="axm-img" style="height:96px;position:relative;"><div class="axm-card" style="position:absolute;left:8px;right:8px;bottom:8px;margin:0;padding:6px 8px;background:rgba(10,10,10,.72);"><b style="font-size:10px;">Treino A · 100%</b><div class="axm-mut" style="font-size:9px;">52:10 · 8.420 kg · 24/24</div></div></div><div class="axm-row" style="gap:5px;margin:8px 0;"><span class="axm-chip">📷 Foto</span><span class="axm-chip g">🎬 Vídeo 30 s</span></div><div class="axm-mut" style="font-size:9.5px;font-weight:800;">QUEM PODE VER</div><div class="axm-row" style="gap:5px;margin-top:4px;"><span class="axm-chip g">Todos</span><span class="axm-chip" data-ir="5" data-hint>Amigos</span><span class="axm-chip g">Só eu</span></div>'; } },
          { leg: 'Depois de postar, abra a publicação para **mudar quem vê** a qualquer momento ou **baixar** a foto e o vídeo, sempre com a logo da Intus.',
            html: function () { return topo('Sua publicação') + '<div class="axm-card"><div class="axm-mut" style="font-size:9.5px;font-weight:800;">🔒 QUEM PODE VER</div><div class="axm-row" style="gap:5px;margin-top:5px;"><span class="axm-chip g">Todos</span><span class="axm-chip">Amigos</span><span class="axm-chip g">Só eu</span></div><div class="axm-btn gh">⬇️ Baixar</div></div><div class="axm-img" style="height:110px;"></div>'; } }
        ]
      },
      /* 6 ─ AMIGOS, TURMAS, DESAFIOS */
      {
        id: 'conexoes', icone: 'amigos', titulo: 'Amigos, turmas e desafios', curto: 'Amigos', promessa: 'Treinar acompanhado deixa a rotina mais leve.',
        abrir: ['Abrir amigos e turmas', function () { navegar('amigos'); }],
        telas: [
          { leg: 'No perfil de outro aluno, toque em **Adicionar amigo**. A pessoa precisa aceitar.',
            html: function () { return '<div style="text-align:center;padding-top:6px;"><div class="axm-av" style="width:44px;height:44px;font-size:15px;margin:0 auto;">AS</div><div class="axm-big" style="font-size:16px;margin:6px 0 2px;">Ana Souza</div><div class="axm-mut">12 treinos no mês</div></div><div class="axm-btn" data-ir="1" data-hint style="margin-top:14px;">+ Adicionar amigo</div><div class="axm-btn gh">Ver publicações</div>'; } },
          { leg: 'Com a amizade aceita, você pode convidar para **treinar em parceria** (até 3 parceiros) e acompanhar a sequência em dupla.',
            html: function () { return topo('Amigos') + '<div class="axm-card axm-row">' + av('AS') + '<div style="flex:1;"><b>Ana Souza</b><div class="axm-mut">Amiga</div></div><span class="axm-chip" data-ir="2" data-hint>Treinar juntos</span></div><div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:800;">SEQUÊNCIA EM DUPLA</div><div class="axm-big" style="margin-top:3px;">4 semanas 🔥</div></div>'; } },
          { leg: '**Turmas** reúnem até 30 alunos por um código e têm ranking próprio, da semana e do mês.',
            html: function () { return topo('Turma Madrugada', '<span class="axm-chip g">Código 7QK2</span>') + [['Ana', 62], ['Você', 51], ['Caio', 40]].map(function (l, i) { return '<div class="axm-card axm-row" ' + (l[0] === 'Você' ? 'style="border-color:#7FFF00;"' : '') + '><b style="width:12px;">' + (i + 1) + '</b>' + av(l[0].slice(0, 2).toUpperCase()) + '<span style="flex:1;">' + l[0] + '</span><b>' + l[1] + '</b></div>'; }).join('') + '<div class="axm-btn gh" data-ir="3" data-hint>Ver desafios</div>'; } },
          { leg: '**Desafios** são metas com prazo, criadas pela equipe da consultoria. O seu progresso conta sozinho, a cada treino.',
            html: function () { return topo('Desafio') + '<div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:800;">CRIADO PELA EQUIPE</div><div class="axm-big" style="font-size:16px;margin:4px 0;">20 treinos em outubro</div>' + barra(60) + '<div class="axm-row" style="justify-content:space-between;"><b>12 de 20</b><span class="axm-mut">até 31/10</span></div></div><div class="axm-card axm-row"><span>👥</span><span>8 alunos participando</span></div>'; } }
        ]
      },
      /* 7 ─ NUTRIÇÃO */
      {
        id: 'nutricao', icone: 'nutri', titulo: 'Plano alimentar', curto: 'Nutrição', promessa: 'Marque o que comeu e acompanhe a semana.',
        abrir: ['Abrir nutrição', function () { navegar('nutricao'); }],
        telas: [
          { leg: 'Seu plano mostra as refeições do dia. Em cada uma, marque **Feito**, **Parcial** ou **Fora do plano**. A foto é opcional.',
            html: function () { return topo('Hoje', '<span class="axm-chip">2 de 5</span>') + '<div class="axm-card axm-row" style="opacity:.7;"><span class="axm-ck on" style="width:22px;height:22px;">' + CK + '</span><div><b>Café da manhã</b><div class="axm-mut">Feito</div></div></div><div class="axm-card"><b>Almoço</b><div class="axm-mut" style="font-size:10px;">Arroz, frango, salada</div><div class="axm-row" style="gap:5px;margin-top:7px;"><span class="axm-chip" data-ir="1" data-hint>Feito</span><span class="axm-chip g">Parcial</span><span class="axm-chip g">Fora</span></div></div>'; } },
          { leg: 'Marque também a **água** do dia, na tela inicial da Nutrição ou aqui no plano. Toque no **+** a cada copo de 250 ml, até chegar na sua meta.',
            html: function () { return topo('Hoje', '<span class="axm-chip">3 de 5</span>') + '<div class="axm-card"><div class="axm-row" style="justify-content:space-between;"><div><div class="axm-mut" style="font-size:10px;font-weight:700;">Água</div><div class="axm-big">1,0 L</div><div class="axm-mut">de 2,5 L</div></div><div class="axm-ck on" data-ir="2" data-hint style="width:40px;height:40px;font-size:20px;">+</div></div>' + barra(40) + '</div><div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:700;">Calorias estimadas pelo plano</div><div class="axm-big" style="font-size:16px;">1.240 kcal</div></div>'; } },
          { leg: 'Na aba **Semana** você vê a aderência dos 7 dias e uma **lista de compras** somada do seu plano.',
            html: function () { return topo('Semana') + '<div class="axm-gr" style="grid-template-columns:repeat(7,1fr);margin-bottom:8px;">' + [1, 1, 0.5, 1, 1, 0, 0.5].map(function (v) { return '<div style="height:24px;border-radius:6px;background:' + (v === 1 ? '#7FFF00' : v === 0.5 ? 'rgba(127,255,0,.4)' : '#2a2a2a') + ';"></div>'; }).join('') + '</div><div class="axm-card"><div class="axm-row" style="justify-content:space-between;"><b>Aderência 7 dias</b><span class="axm-chip">78%</span></div></div><div class="axm-btn" data-ir="3" data-hint>🛒 Lista de compras</div>'; } },
          { leg: 'A lista soma por alimento e pode ser **copiada ou compartilhada**. Se sua nutricionista responder um registro, você recebe um aviso.',
            html: function () { return topo('Lista de compras') + ['Frango · 1,4 kg', 'Arroz · 700 g', 'Ovos · 14 un', 'Banana · 7 un'].map(function (t) { return '<div class="axm-card axm-row"><span class="axm-ck" style="width:20px;height:20px;"></span><span>' + t + '</span></div>'; }).join('') + '<div class="axm-btn gh">Copiar lista</div>'; } }
        ]
      },
      /* 8 ─ EVOLUÇÃO */
      {
        id: 'evolucao', icone: 'evol', titulo: 'Avaliações e evolução', curto: 'Evolução', promessa: 'Veja se você está mesmo evoluindo.',
        abrir: ['Abrir avaliações', function () { navegar('avaliacoes'); }],
        telas: [
          { leg: 'Seu professor registra **fotos e medidas** nas avaliações. Elas ficam guardadas em **Avaliações**.',
            html: function () { return topo('Avaliações') + '<div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:800;">AVALIAÇÃO · 12/09</div><div class="axm-gr" style="grid-template-columns:1fr 1fr 1fr;margin:8px 0;"><div class="axm-img" style="height:60px;"></div><div class="axm-img" style="height:60px;"></div><div class="axm-img" style="height:60px;"></div></div><div class="axm-row" style="justify-content:space-between;"><span>Peso <b>78,2 kg</b></span><span>Gordura <b>18%</b></span></div></div><div class="axm-btn" data-ir="1" data-hint>Comparar avaliações</div>'; } },
          { leg: 'O **comparativo** coloca duas avaliações lado a lado, com a diferença de cada medida.',
            html: function () { return topo('Comparativo') + '<div class="axm-gr" style="grid-template-columns:1fr 1fr;"><div><div class="axm-img" style="height:90px;"></div><div class="axm-mut" style="text-align:center;margin-top:3px;">Junho</div></div><div><div class="axm-img" style="height:90px;"></div><div class="axm-mut" style="text-align:center;margin-top:3px;">Setembro</div></div></div><div class="axm-card" style="margin-top:8px;"><div class="axm-row" style="justify-content:space-between;"><span>Peso</span><b style="color:#7FFF00;">-2,1 kg</b></div><div class="axm-row" style="justify-content:space-between;margin-top:4px;"><span>Cintura</span><b style="color:#7FFF00;">-3 cm</b></div></div><div class="axm-btn gh" data-ir="2" data-hint>Ver meu histórico</div>'; } },
          { leg: 'No **Histórico** estão o calendário de frequência e os gráficos de carga: o jeito mais direto de saber se você está progredindo.',
            html: function () { return topo('Histórico') + '<div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:700;">Carga no supino</div><div style="display:flex;align-items:flex-end;gap:5px;height:70px;margin-top:6px;">' + [26, 30, 28, 36, 40, 44, 52].map(function (h, i) { return '<div style="flex:1;height:' + h + 'px;background:' + (i === 6 ? '#7FFF00' : '#3a3a3a') + ';border-radius:3px 3px 0 0;"></div>'; }).join('') + '</div></div><div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:700;margin-bottom:5px;">Frequência do mês</div><div class="axm-gr" style="grid-template-columns:repeat(7,1fr);">' + Array.apply(null, Array(14)).map(function (_, i) { return '<div style="height:12px;border-radius:3px;background:' + ([0, 2, 3, 5, 7, 9, 10, 12].indexOf(i) >= 0 ? '#7FFF00' : '#2a2a2a') + ';"></div>'; }).join('') + '</div></div>'; } }
        ]
      },
      /* 9 ─ CENTRAL, AVISOS E PERFIL */
      {
        id: 'central', icone: 'central', titulo: 'Avisos, Central e perfil', curto: 'Central', promessa: 'Fale com o professor e controle sua privacidade.',
        abrir: ['Abrir a Central', function () { navegar('mensagens'); }],
        telas: [
          { leg: 'O **sino** avisa curtidas, comentários, medalhas e pedidos de amizade. Toque nele.',
            html: function () { return '<div class="axm-top"><b>Início</b><div class="axm-row" style="gap:8px;"><span class="axm-chip g">?</span><span style="position:relative;" data-ir="1" data-hint><span class="axm-chip g">🔔</span><span style="position:absolute;top:-4px;right:-4px;background:#ff3b30;color:#fff;border-radius:99px;font-size:8px;font-weight:900;padding:1px 5px;">2</span></span></div></div><div class="axm-card"><div class="axm-mut" style="font-size:10px;">Hoje</div><div class="axm-big">Treino A</div></div>'; } },
          { leg: 'Cada aviso leva **direto** para o que aconteceu: a publicação, o comentário ou a tela certa.',
            html: function () { return topo('🔔 Notificações') + '<div class="axm-card axm-row" style="background:rgba(127,255,0,.06);" data-ir="2" data-hint><span style="font-size:17px;">💬</span><div><b>Ana</b> comentou no seu post<div class="axm-mut" style="font-size:10px;">“Boa, campeão!”</div></div></div><div class="axm-card axm-row"><span style="font-size:17px;">🏅</span><div><b>Medalha da semana</b><div class="axm-mut" style="font-size:10px;">Você ficou em 3º</div></div></div>'; } },
          { leg: 'Na **Central** você conversa direto com o seu professor: tire dúvidas e receba os avisos da consultoria.',
            html: function () { return topo('Central') + '<div style="display:flex;flex-direction:column;gap:6px;"><div style="background:#262626;border-radius:12px 12px 12px 3px;padding:7px 10px;max-width:80%;">Posso trocar o treino de hoje?</div><div style="align-self:flex-end;background:#7FFF00;color:#0b1400;border-radius:12px 12px 3px 12px;padding:7px 10px;max-width:80%;font-weight:700;">Pode sim! 💪</div></div><div class="axm-card axm-row" style="margin-top:10px;" data-ir="3" data-hint><span class="axm-mut" style="flex:1;">Escreva uma mensagem...</span><span class="axm-chip">Enviar</span></div>'; } },
          { leg: 'No **Perfil** você escolhe **quem vê as suas fotos** e se participa do Feed e do ranking. Dá para mudar quando quiser.',
            html: function () { return topo('Perfil') + '<div class="axm-card"><div class="axm-mut" style="font-size:10px;font-weight:800;">QUEM VÊ MINHAS FOTOS</div><div class="axm-row" style="gap:5px;margin-top:6px;"><span class="axm-chip g">Todos</span><span class="axm-chip">Amigos</span><span class="axm-chip g">Só eu</span></div></div><div class="axm-card axm-row" style="justify-content:space-between;"><span>Participar do Feed</span><span class="axm-chip">Ligado</span></div><div class="axm-card axm-row" style="justify-content:space-between;"><span>Participar do ranking</span><span class="axm-chip">Ligado</span></div>'; } }
        ]
      }
    ];
  }

  /* ───────────────────────────── TOUR ───────────────────────────── */
  var T = { caps: null, cap: -1, tela: 0, sel: 0, raiz: null };

  function raizTour() {
    var el = document.getElementById('ax-tour');
    if (el) return el;
    el = document.createElement('div'); el.id = 'ax-tour'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Tour do app');
    document.body.appendChild(el);
    var x0 = null;
    el.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    el.addEventListener('touchend', function (e) {
      if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 70 && !e.target.closest('.ax-phone')) { dx < 0 ? tourProximo() : tourAnterior(); }
    }, { passive: true });
    return el;
  }
  function segmentos() {
    var n = T.caps.length;
    var h = '<div class="ax-seg">';
    for (var i = 0; i < n; i++) h += '<i class="' + (i === T.cap ? 'cur' : (estado.vistos[T.caps[i].id] ? 'ok' : '')) + '" onclick="Ajuda.tour(' + i + ')"></i>';
    return h + '</div>';
  }
  function tourAbrirCap(i) {
    T.caps = capitulos(); T.cap = i; T.tela = 0; T.sel = 0;
    var raiz = raizTour();
    var c = T.caps[i];
    raiz.innerHTML =
      '<div class="ax-t-top">' + segmentos() + '<div class="ax-t-bar"><span class="qual">' + (i + 1) + ' de ' + T.caps.length + ' · ' + esc(c.curto) + '</span><button class="ax-x" aria-label="Fechar" onclick="Ajuda.fechar()">&times;</button></div></div>' +
      '<div class="ax-t-main">' +
        '<div class="ax-h">' + esc(c.titulo) + '</div><div class="ax-sub">' + esc(c.promessa) + '</div>' +
        '<div class="ax-phone" id="ax-fone">' + c.telas.map(function (_, k) { return '<div class="ax-tela" data-k="' + k + '"></div>'; }).join('') + '</div>' +
        '<div class="ax-passos" id="ax-passos">' + c.telas.map(function () { return '<i></i>'; }).join('') + '</div>' +
        '<div class="ax-leg" id="ax-leg"></div>' +
        '<button class="ax-link" id="ax-pular" onclick="Ajuda.passo()">Avançar sem tocar</button>' +
      '</div>' +
      '<div class="ax-t-rod"><button class="ax-b sec peq" onclick="Ajuda.anterior()" aria-label="Capítulo anterior">' + ico('voltar') + '</button><button class="ax-b sec" id="ax-abrir" onclick="Ajuda.abrirTela()">' + esc(c.abrir[0]) + '</button><button class="ax-b pri" id="ax-prox" onclick="Ajuda.proximo()">Próximo ' + ico('seta') + '</button></div>';
    raiz.classList.add('on');
    document.getElementById('ax-fone').addEventListener('click', function (e) {
      var alvo = e.target.closest('[data-ir]');
      if (!alvo) { this.classList.remove('ax-shake'); void this.offsetWidth; this.classList.add('ax-shake'); return; }
      if (alvo.getAttribute('data-sel') !== null) T.sel = Number(alvo.getAttribute('data-sel')) || 0;
      tourIrTela(Number(alvo.getAttribute('data-ir')));
    });
    tourIrTela(0);
    estado.abriu = 1; salvar();
  }
  function tourIrTela(k) {
    var c = T.caps[T.cap]; if (!c) return;
    if (k < 0) k = 0; if (k >= c.telas.length) k = c.telas.length - 1;
    T.tela = k;
    var fone = document.getElementById('ax-fone');
    fone.querySelectorAll('.ax-tela').forEach(function (el) { el.classList.remove('on'); });
    var alvo = fone.querySelector('.ax-tela[data-k="' + k + '"]');
    alvo.innerHTML = c.telas[k].html({ sel: T.sel });
    alvo.classList.add('on');
    document.getElementById('ax-leg').innerHTML = negrito(c.telas[k].leg);
    document.querySelectorAll('#ax-passos i').forEach(function (p, j) { p.className = j === k ? 'on' : (j < k ? 'ok' : ''); });
    var ultima = k === c.telas.length - 1;
    document.getElementById('ax-pular').style.display = ultima ? 'none' : '';
    if (ultima) { estado.vistos[c.id] = 1; salvar(); var seg = document.querySelectorAll('.ax-seg i')[T.cap]; if (seg) seg.classList.add('ok'); }
    var p = document.getElementById('ax-prox');
    var fim = T.cap >= T.caps.length - 1;
    p.innerHTML = (fim ? 'Concluir ' : 'Próximo ') + ico('seta');
  }
  function tourPasso() { tourIrTela(T.tela + 1); }
  function tourProximo() {
    if (T.cap < 0) { tourAbrirCap(0); return; }
    if (T.cap >= T.caps.length - 1) { tourFim(); return; }
    tourAbrirCap(T.cap + 1);
  }
  function tourAnterior() { if (T.cap <= 0) { tourAbertura(); return; } tourAbrirCap(T.cap - 1); }

  function tourAbertura(boasVindas) {
    T.caps = capitulos(); T.cap = -1;
    var raiz = raizTour();
    var nome = primeiroNome();
    var pills = T.caps.map(function (c, i) { return '<button class="ax-pill ' + (estado.vistos[c.id] ? 'ok' : '') + '" onclick="Ajuda.tour(' + i + ')">' + ico(c.icone) + esc(c.curto) + '</button>'; }).join('');
    raiz.innerHTML =
      '<div class="ax-t-top"><div class="ax-t-bar"><span class="qual">Tour do app</span><button class="ax-x" aria-label="Fechar" onclick="Ajuda.fechar()">&times;</button></div></div>' +
      '<div class="ax-t-main" style="justify-content:center;"><div class="ax-cap">' +
        '<img class="ax-logo" src="' + 'logo-intus-dark.png' + '" alt="Intus Fit" onerror="this.style.display=\'none\'"/>' +
        '<div class="ax-big">' + (boasVindas && nome ? 'Bem-vindo, <em>' + esc(nome) + '</em>.' : 'Conheça o <em>Intus Fit</em> em 3 minutos.') + '</div>' +
        '<div class="ax-sub" style="margin:0 auto 6px;">Nove mini-aulas, uma para cada parte do app. Em cada uma você toca nos botões que pulsam, como se estivesse usando de verdade.</div>' +
        '<div class="ax-lista">' + pills + '</div>' +
      '</div></div>' +
      '<div class="ax-t-rod"><button class="ax-b sec" onclick="Ajuda.fechar()">' + (boasVindas ? 'Agora não' : 'Fechar') + '</button><button class="ax-b pri" onclick="Ajuda.tour(0)">Começar ' + ico('seta') + '</button></div>';
    raiz.classList.add('on');
  }
  function tourFim() {
    estado.tourFim = 1; salvar();
    var raiz = raizTour(); var nome = primeiroNome();
    raiz.innerHTML =
      '<div class="ax-t-top"><div class="ax-t-bar"><span class="qual">Tour concluído</span><button class="ax-x" aria-label="Fechar" onclick="Ajuda.fechar()">&times;</button></div></div>' +
      '<div class="ax-t-main" style="justify-content:center;"><div class="ax-cap">' +
        '<div style="font-size:56px;margin-top:10px;">💪</div>' +
        '<div class="ax-big">Tudo pronto' + (nome ? ', <em>' + esc(nome) + '</em>' : '') + '.</div>' +
        '<div class="ax-sub" style="margin:0 auto;">Qualquer dúvida, toque no <b style="color:#7FFF00;">?</b> no topo do app: lá estão as mini-aulas, as perguntas frequentes e o atalho para falar com seu professor.</div>' +
        '<div class="ax-lista">' + T.caps.map(function (c) { return '<button class="ax-pill ok" onclick="Ajuda.tour(' + T.caps.indexOf(c) + ')">' + ico(c.icone) + esc(c.curto) + '</button>'; }).join('') + '</div>' +
      '</div></div>' +
      '<div class="ax-t-rod"><button class="ax-b sec" onclick="Ajuda.fechar();Ajuda.abrir()">Ver a ajuda</button><button class="ax-b pri" onclick="Ajuda.fechar();try{abrirMeusTreinos()}catch(e){}">Ir treinar ' + ico('seta') + '</button></div>';
    raiz.classList.add('on');
  }

  /* ───────────────────── CENTRAL DE AJUDA ───────────────────── */
  var FAQ = [
    { g: 'Treino', q: 'Como começo um treino?', a: 'No **Início**, toque no treino do dia. Você também pode abrir **Meus Treinos** no menu e escolher outro. Depois é só tocar em **Iniciar**.', ir: ['Abrir meus treinos', 'abrirMeusTreinos()'] },
    { g: 'Treino', q: 'Como registro carga e repetições?', a: 'Cada série tem a repetição sugerida, um campo de **carga (kg)** e um de **repetições**. As setas ajustam rápido. Toque no quadrado da série para **marcar como feita**; o descanso começa sozinho.' },
    { g: 'Treino', q: 'Fechei o app no meio do treino. Perdi tudo?', a: 'Não. O treino em andamento fica guardado e o app avisa que ele continua aberto. Volte para ele e termine de onde parou.' },
    { g: 'Treino', q: 'Como termino o treino?', a: 'Toque em **Finalizar treino**. Se o treino foi muito curto, o app pergunta se você quer mesmo finalizar. Depois ele aparece no **Histórico**.' },
    { g: 'Treino', q: 'Dá para ver como se faz um exercício?', a: 'Sim. Em cada exercício há a opção de **informações**, com explicação e vídeo. Se precisar trocar um exercício, use a opção de **substituir** para ver alternativas.' },
    { g: 'Cardio', q: 'Qual a diferença entre cardio ao vivo e manual?', a: 'No **ao vivo** o app monitora pelo GPS: distância, tempo e ritmo. No **manual** você registra depois, ideal para natação, esteira, escada e outras atividades sem GPS.', ir: ['Abrir meu cardio', 'abrirCardio()'] },
    { g: 'Cardio', q: 'O cardio ao vivo pede localização. Por quê?', a: 'Para calcular a distância e o ritmo. Se você negou, libere a **localização** do Intus Fit nos ajustes do celular ou do navegador.' },
    { g: 'Pontos e ranking', q: 'Como funcionam os pontos?', a: function () { var R = regras(); return '**Musculação = ' + R.musBase + ' pts** por treino do dia (treino bem curto, abaixo de ' + R.musCurtaMin + ' min, vale ' + R.musMeia + '). O **2º treino do dia** soma ' + R.musSegundo + ' e do 3º em diante não soma. **Cardio** complementa, com teto de ' + R.cardioTeto + ' pts por sessão. Treinar musculação em **' + R.bonusDias + ' dias** diferentes da semana dá **+' + R.bonusPts + ' pts**.'; }, ir: ['Abrir o ranking', 'abrirRanking()'] },
    { g: 'Pontos e ranking', q: 'Quando a semana começa e termina?', a: 'De **domingo a sábado**. Fecha no sábado e todo domingo começa uma nova, com todo mundo empatado. Constância ganha de intensidade.' },
    { g: 'Pontos e ranking', q: 'Como ganho medalha?', a: 'Cada semana e cada mês encerrados premiam os primeiros colocados. Veja o quadro completo em **Medalhas**, no topo do Ranking.' },
    { g: 'Pontos e ranking', q: 'Posso sair do ranking?', a: 'Pode. Em **Meu Perfil** você desliga a participação no ranking quando quiser.', ir: ['Abrir meu perfil', "navegar('perfil')"] },
    { g: 'Feed', q: 'Como faço um post?', a: 'No **Feed**, toque em **Novo post**. Escolha a câmera ou a galeria. Você pode postar **até 10 fotos e vídeos**, com **vídeo de até 30 segundos** e no máximo **2 vídeos por post**. Dá para colocar a figurinha com os dados do último treino ou cardio e a localização.', ir: ['Abrir o Feed', "navegar('feed')"] },
    { g: 'Feed', q: 'Quem pode ver o que eu posto?', a: 'Você escolhe: **Todos**, **Amigos** ou **Só eu**. Para mudar depois, toque na sua publicação pelo seu perfil e use **Quem pode ver esta publicação**.' },
    { g: 'Feed', q: 'Como escolho o tom de pele do 💪?', a: 'Toque no **＋** de um post para abrir as reações e **segure o 💪**. Aparecem os 6 tons; o escolhido vira o padrão.' },
    { g: 'Feed', q: 'Como respondo um comentário?', a: 'Toque em **Responder** abaixo do comentário. Vale para qualquer comentário, inclusive respostas. Quem escreveu o comentário e o dono do post podem excluí-lo.' },
    { g: 'Feed', q: 'Como baixo minha foto ou vídeo?', a: 'Abra a sua publicação pelo seu perfil e toque em **Baixar**, ou use o menu **⋯** do post. A foto e o vídeo saem com a logo da Intus.' },
    { g: 'Feed', q: 'Não quero ver o post de alguém.', a: 'No menu **⋯** do post você pode ocultar essa pessoa. A participação no Feed também é opcional e pode ser desligada em **Meu Perfil**.' },
    { g: 'Amigos e desafios', q: 'Como adiciono amigos e treino em parceria?', a: 'Abra o perfil da pessoa e toque em **Adicionar amigo**. Quando ela aceitar, você pode convidá-la para **treinar em parceria** (até 3 parceiros).', ir: ['Abrir amigos', "navegar('amigos')"] },
    { g: 'Amigos e desafios', q: 'O que são turmas e desafios?', a: '**Turmas** reúnem até 30 alunos por um código e têm ranking próprio. **Desafios** são metas com prazo criadas pela equipe da consultoria; o progresso conta sozinho.' },
    { g: 'Nutrição', q: 'Como registro minhas refeições e a água?', a: 'Em **Nutrição**, marque cada refeição como **Feito**, **Parcial** ou **Fora do plano**, com observação e foto se quiser. A água você marca na **tela inicial da Nutrição** (mesmo sem plano ativo) ou dentro do plano: toque em **+ copo** a cada 250 ml. A foto só você e a equipe veem.', ir: ['Abrir nutrição', "navegar('nutricao')"] },
    { g: 'Nutrição', q: 'Onde ficam as receitas, os vídeos e as dicas?', a: 'Na tela inicial da **Nutrição**, toque em **Receitas**, **Vídeos** ou **Dicas**. Elas valem para todos os alunos, mesmo sem plano alimentar ativo. Nas receitas você busca por nome ou ingrediente, favorita com a estrela e envia pelo WhatsApp.', ir: ['Abrir nutrição', "navegar('nutricao')"] },
    { g: 'Nutrição', q: 'Onde fica a lista de compras?', a: 'Na aba **Semana** da Nutrição. Ela soma os alimentos do seu plano e pode ser copiada ou compartilhada.' },
    { g: 'Avisos e conta', q: 'Toquei numa notificação e não abriu o post.', a: 'Se a publicação foi apagada ou ficou restrita para você, aparece o aviso de que ela não está mais disponível. Nos demais casos, o toque leva direto à publicação ou à tela do aviso.' },
    { g: 'Avisos e conta', q: 'Como mudo o tema ou o tamanho da letra?', a: 'No **menu**, ao lado do seu nome, há o tema claro e escuro e o **zoom** do app.' },
    { g: 'Avisos e conta', q: 'Como excluo minha conta?', a: 'Em **Meu Perfil**, na área de dados da conta. Seu acesso é bloqueado na hora e seus dados pessoais são removidos em até 30 dias, conforme a política de privacidade.', ir: ['Abrir meu perfil', "navegar('perfil')"] },
    { g: 'Avisos e conta', q: 'A tela gira quando viro o celular.', a: 'O Intus Fit foi feito para ficar em pé: a tela se mantém na vertical em qualquer posição. Só vídeos em tela cheia giram para a horizontal.' }
  ];
  var ctxPorView = {
    muscu: 'treino', 'treinos-menu': 'treino', treinos: 'treino', fim: 'treino', along: 'treino', exinfo: 'treino',
    cardio: 'cardio', 'cardio-live': 'cardio', ranking: 'ranking', feed: 'feed', amigos: 'conexoes', desafios: 'conexoes',
    nutricao: 'nutricao', historico: 'evolucao', avaliacoes: 'evolucao', mensagens: 'central', perfil: 'conquistas', home: null
  };

  function checklist() {
    var itens = [];
    var temMus = false, temCardio = false, temMsg = false;
    try {
      var ses = (typeof SessoesTreino !== 'undefined' && SessoesTreino.listarDoAtleta) ? SessoesTreino.listarDoAtleta(idAtleta) : [];
      (ses || []).forEach(function (s) { if (API.ehMusculacaoRank && API.ehMusculacaoRank(s)) temMus = true; else temCardio = true; });
    } catch (e) {}
    try { temMsg = Store.get('mensagens').some(function (m) { return Number(m.idatleta) === Number(idAtleta) && m.remetente === 'aluno'; }); } catch (e) {}
    itens.push({ t: 'Fazer o tour do app', ok: !!estado.tourFim, ir: function () { Ajuda.tour(); } });
    itens.push({ t: 'Concluir o primeiro treino', ok: temMus, ir: function () { Ajuda.fechar(); try { abrirMeusTreinos(); } catch (e) {} } });
    itens.push({ t: 'Registrar um cardio', ok: temCardio, ir: function () { Ajuda.fechar(); try { abrirCardio(); } catch (e) {} } });
    itens.push({ t: 'Publicar no Feed', ok: !!estado.postou, id: 'post', ir: function () { Ajuda.fechar(); try { navegar('feed'); } catch (e) {} } });
    itens.push({ t: 'Mandar uma mensagem ao professor', ok: temMsg, ir: function () { Ajuda.fechar(); try { navegar('mensagens'); } catch (e) {} } });
    return itens;
  }

  function raizAjuda() {
    var el = document.getElementById('ax-help');
    if (el) return el;
    el = document.createElement('div'); el.id = 'ax-help'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Ajuda');
    document.body.appendChild(el);
    return el;
  }
  var H = { itens: [], caps: [] };

  function aulasHtml() {
    return '<div class="ax-aulas">' + H.caps.map(function (c, i) {
      return '<button class="ax-aula" onclick="Ajuda.tour(' + i + ')">' + (estado.vistos[c.id] ? '<span class="vis">' + CK + '</span>' : '') + ico(c.icone) + '<b>' + esc(c.curto) + '</b><span>' + c.telas.length + ' passos</span></button>';
    }).join('') + '</div>';
  }
  function checklistHtml() {
    H.itens = checklist();
    var feitos = H.itens.filter(function (x) { return x.ok; }).length;
    return '<div class="ax-sec" style="margin-top:6px;">Primeiros passos · ' + feitos + ' de ' + H.itens.length + '</div><div class="ax-prog"><i style="width:' + Math.round(feitos * 100 / H.itens.length) + '%"></i></div><div class="ax-pp">' +
      H.itens.map(function (x, i) { return '<div class="ax-pp-l ' + (x.ok ? 'ok' : '') + '" onclick="Ajuda._ir(' + i + ')"><span class="ax-pp-c">' + CK + '</span><span class="t">' + esc(x.t) + '</span>' + (x.ok ? '' : '<span class="go">Ir</span>') + '</div>'; }).join('') + '</div>';
  }
  function faqHtml(lista) {
    var g = '', h = '';
    lista.forEach(function (f) {
      if (f.g !== g) { g = f.g; h += '<div class="ax-grupo">' + esc(g) + '</div>'; }
      var resp = typeof f.a === 'function' ? f.a() : f.a;
      h += '<details class="ax-q"><summary>' + esc(f.q) + '</summary><div class="r">' + negrito(resp) + (f.ir ? '<br><span class="lk-b" onclick="Ajuda.fechar();' + f.ir[1] + '">' + esc(f.ir[0]) + '</span>' : '') + '</div></details>';
    });
    return h || '<div class="ax-vazio">Nada encontrado para essa busca.<br>Tente outra palavra ou fale com o seu professor logo abaixo.</div>';
  }
  function falarHtml() {
    return '<div class="ax-sec">Ainda com dúvida?</div><div class="ax-fale">' +
      '<button onclick="Ajuda.fechar();try{navegar(\'mensagens\')}catch(e){}">' + ico('central') + 'Falar com o professor</button>' +
      '<button onclick="Ajuda.fechar();try{abrirSuporte()}catch(e){}">' + ico('ajuda') + 'Suporte do app</button></div>';
  }
  function ajudaAbrir() {
    H.caps = capitulos();
    var raiz = raizAjuda();
    var vistos = H.caps.filter(function (c) { return estado.vistos[c.id]; }).length;
    var cap = null, v = ''; try { v = window._viewAtual || ''; } catch (e) {}
    if (ctxPorView[v]) cap = H.caps.filter(function (c) { return c.id === ctxPorView[v]; })[0];
    var ctx = cap ? '<div class="ax-ctx" onclick="Ajuda.tour(' + H.caps.indexOf(cap) + ')">' + ico(cap.icone) + '<div style="flex:1;"><b>Sobre esta tela: ' + esc(cap.curto) + '</b><span>' + esc(cap.promessa) + '</span></div>' + ico('seta') + '</div>' : '';
    raiz.innerHTML =
      '<div class="ax-h-top"><button class="ax-x" aria-label="Fechar" onclick="Ajuda.fechar()">' + ico('voltar') + '</button><h2>Ajuda</h2></div>' +
      '<div class="ax-h-scroll"><div class="ax-h-wrap">' +
        '<div class="ax-hero"><small>Tour interativo</small><h3>' + (estado.tourFim ? 'Rever o tour do app' : 'Aprenda o app em 3 minutos') + '</h3><p>' + (vistos ? vistos + ' de ' + H.caps.length + ' mini-aulas vistas. ' : '') + 'Toque nos botões que pulsam e veja como cada parte funciona.</p><button class="ax-b pri" onclick="Ajuda.tour(' + (vistos && vistos < H.caps.length ? Math.max(0, H.caps.findIndex(function (c) { return !estado.vistos[c.id]; })) : '') + ')">' + (vistos && vistos < H.caps.length ? 'Continuar' : 'Começar') + ' ' + ico('seta') + '</button></div>' +
        ctx +
        '<label class="ax-busca">' + ico('busca') + '<input id="ax-q" type="search" placeholder="Buscar: pontos, cardio, post, água..." autocomplete="off"/></label>' +
        '<div id="ax-corpo"></div>' +
      '</div></div>';
    raiz.classList.add('on');
    corpoPadrao();
    var inp = document.getElementById('ax-q');
    inp.addEventListener('input', function () {
      var t = norm(inp.value).trim();
      if (!t) { corpoPadrao(); return; }
      var achou = FAQ.filter(function (f) { var r = typeof f.a === 'function' ? f.a() : f.a; return norm(f.q + ' ' + r + ' ' + f.g).indexOf(t) >= 0; });
      var aulas = H.caps.map(function (c, i) { return { c: c, i: i }; }).filter(function (o) { return norm(o.c.titulo + ' ' + o.c.curto + ' ' + o.c.promessa).indexOf(t) >= 0; });
      document.getElementById('ax-corpo').innerHTML =
        (aulas.length ? '<div class="ax-sec">Mini-aulas</div><div class="ax-aulas">' + aulas.map(function (o) { return '<button class="ax-aula" onclick="Ajuda.tour(' + o.i + ')">' + ico(o.c.icone) + '<b>' + esc(o.c.curto) + '</b><span>' + esc(o.c.promessa) + '</span></button>'; }).join('') + '</div>' : '') +
        '<div class="ax-sec">Perguntas</div>' + faqHtml(achou) + falarHtml();
    });
    estado.abriu = 1; salvar();
    try { var dot = document.querySelector('.tutorial-btn .notif-dot'); if (dot) dot.classList.add('hide'); localStorage.setItem('intus-tutorial-visto', '1'); localStorage.setItem('intus-ajuda-v2-visto', '1'); } catch (e) {}
    // "Publicou no Feed?" só se descobre perguntando ao servidor; atualiza o cartão quando a resposta chega.
    if (!estado.postou) {
      try {
        fetch(API_BASE + '/catalogo.php?action=feed_posts&idatleta=' + Number(idAtleta) + '&limite=1&_=' + Date.now(), { headers: { 'Authorization': 'Bearer ' + (localStorage.getItem('mx-token') || '') }, cache: 'no-store' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) { if (j && j.posts && j.posts.length) { estado.postou = 1; salvar(); if (document.getElementById('ax-corpo') && !document.getElementById('ax-q').value) corpoPadrao(); } })
          .catch(function () {});
      } catch (e) {}
    }
  }
  function corpoPadrao() {
    var c = document.getElementById('ax-corpo'); if (!c) return;
    c.innerHTML = '<div class="ax-sec" style="margin-top:6px;">Mini-aulas</div>' + aulasHtml() + checklistHtml() + '<div class="ax-sec">Perguntas frequentes</div>' + faqHtml(FAQ) + falarHtml();
  }

  /* ───────────────────────────── API pública ───────────────────────────── */
  function fechar() {
    var a = document.getElementById('ax-tour'); if (a) { a.classList.remove('on'); a.innerHTML = ''; }
    var b = document.getElementById('ax-help'); if (b) { b.classList.remove('on'); b.innerHTML = ''; }
  }
  window.Ajuda = {
    abrir: function () { fechar(); ajudaAbrir(); },
    tour: function (i) {
      var h = document.getElementById('ax-help'); if (h) { h.classList.remove('on'); h.innerHTML = ''; }
      if (i === undefined || i === '' || isNaN(i)) { tourAbertura(false); return; }
      tourAbrirCap(Number(i));
    },
    bemVindo: function () { fechar(); tourAbertura(true); },
    fechar: fechar,
    proximo: tourProximo,
    anterior: tourAnterior,
    passo: tourPasso,
    abrirTela: function () { var c = T.caps && T.caps[T.cap]; if (!c) return; var f = c.abrir[1]; fechar(); try { f(); } catch (e) {} },
    _ir: function (i) { var x = H.itens[i]; if (x && x.ir) x.ir(); },
    estado: function () { return estado; }
  };
})();
