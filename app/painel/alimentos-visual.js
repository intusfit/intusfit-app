/* Biblioteca de alimentos para a dieta visual do aluno (25/11/2026).
 *
 * Cada alimento do plano ganha uma imagem de apoio (ilustração com cor do grupo ou, quando houver, a foto da biblioteca), sem alterar o
 * cardápio: a faixa de imagens fica DENTRO da refeição aberta, compacta, e o aluno liga ou desliga em um toque (preferência guardada).
 * A lista com nomes, quantidades e substituições continua exatamente como a nutri montou.
 *
 * Como o alimento é reconhecido: pelo NOME (sem acento, em minúsculas), com a primeira regra que bater. A ordem das regras importa
 * (ex.: "batata doce" antes de "batata"; "pão de queijo" antes de "pão"). Nome sem regra usa a imagem genérica do prato, nunca uma errada.
 * Fotos: alimentos-fotos/manifest.json  {"arroz": "arroz.jpg", ...}  com as MESMAS chaves (id) das regras abaixo. Sem foto, usa a ilustração.
 *
 * Depende de aluno.html: esc, _urlAbs, idAtleta.
 */
(function () {
  'use strict';

  // grupo -> cor (rgb) usada como fundo suave; funciona nos temas claro e escuro (transparência sobre o cartão)
  var GRUPOS = {
    cereal: [245, 158, 11], tuberculo: [217, 119, 6], leguminosa: [180, 83, 9], carne: [239, 68, 68], peixe: [14, 165, 233], ovo: [250, 204, 21],
    laticinio: [148, 163, 184], fruta: [249, 115, 22], verdura: [34, 197, 94], oleaginosa: [161, 98, 7], gordura: [202, 138, 4], bebida: [59, 130, 246],
    doce: [236, 72, 153], suplemento: [139, 92, 246], prato: [120, 120, 120]
  };

  // [id, expressão (sobre o nome normalizado), emoji, grupo]. A primeira que bater vale.
  var REGRAS = [
    ['pizza', /\bpizza/, '🍕', 'prato'],
    ['hamburguer', /\bhamburguer|\bburger/, '🍔', 'prato'],
    ['sushi', /\bsushi|\bsashimi/, '🍣', 'peixe'],
    ['sanduiche', /\bsanduiche|\blanche\b|\bsandwich|\bhot dog|\bcachorro quente/, '🥪', 'prato'],
    ['sopa', /\bsopa\b|\bcaldo\b|\bcanja\b|\bcreme de (abobora|legumes|ervilha|mandioquinha)/, '🍲', 'prato'],
    ['wrap', /\byakisoba|\bacaraje|\bvatapa|\bvirado a paulista|\bvaca atolada|\bbarreado|\bsarapatel|\bmanicoba|\btabule|\btacaca|\brap10|\bwrap\b|\btortilha|\bburrito|pastel|\bempada|\bcoxinha|\besfiha|\bquibe/, '🌯', 'prato'],
    ['panqueca', /\bpanqueca|\bcrepe|\bwaffle/, '🥞', 'cereal'],
    ['salada', /\bsalada/, '🥗', 'verdura'],
    ['whey', /\bwhey|\bcreatina|\balbumina|\bhipercalorico|\bbcaa|\bcaseina|\bproteina (isolada|em po)|\bsuplemento|\bmaltodextrina|\bdextrose|\bpre treino|\bglutamina|\bproteina vegetal|\bcomplemento alimentar|\bsustagem|\bensure/, '🥤', 'suplemento'],
    ['batida', /\bshake\b|\bsmoothie|\bbatida|\bvitamina de/, '🥤', 'bebida'],
    ['ovo', /\bovos?\b|\bomelete|\bclara\b|\bgema\b|\bovo/, '🥚', 'ovo'],
    // oleaginosas e pastas antes de manteiga, coco e semente
    ['amendoim', /\bamendoim|\bpacoca|\bcastanha|\bamendoa|\bnozes?\b|\bpistache|\bavela\b|\bmacadamia|\bpecan|\bcashew|\bpasta de (amendoim|castanha|amendoa|avela)|\bcoco ralado|\bleite de (coco|amendoa)\b/, '🥜', 'oleaginosa'],
    ['semente', /\bsemente|\bchia\b|\blinhaca|\bgergelim|\bgirassol|\bquinoa/, '🌰', 'oleaginosa'],
    // preparos específicos antes dos ingredientes
    ['paodequeijo', /\bpao de queijo/, '🧀', 'laticinio'],
    ['batatafrita', /\bbatata frita|\bfritas\b|\bbatata palha/, '🍟', 'tuberculo'],
    ['batatadoce', /\bbatata doce|\bbatata-doce/, '🍠', 'tuberculo'],
    ['agua', /\bagua de coco|\bagua mineral|\bagua\b/, '💧', 'bebida'],
    ['suco', /\bsuco|\bnectar|\blimonada|\bisotonico|\bgatorade|\bpowerade|\bagua saborizada|\bisotonic/, '🧃', 'bebida'],
    ['cafe', /\bcafe\b|\bcappuccino|\bespresso|\bcapuccino/, '☕', 'bebida'],
    ['cha', /\bcha\b|\bmate\b|\bchimarrao|\binfusao|\bhibisco|\bcamomila|\bboldo|\bcidreira/, '🍵', 'bebida'],
    ['refri', /\brefrigerante|\bcoca\b|\bguarana\b|\bcerveja|\bvinho|\bchope|\bcachaca|\bvodka|\bwhisky|\bbebida alcoolica|\benergetico|\bcaipirinha|\baguardente|\bcana\b|\bconhaque|\blicor|\bsake\b/, '🥤', 'bebida'],
    ['chocolate', /\bchocolate|\bcacau|\bnutella|\bbombom|\bbrigadeiro|\bachocolatado|\bovomaltine|\bgoiabada|\bdoce de/, '🍫', 'doce'],
    ['mel', /\bmel\b|\bmelado|\bagave|\bxarope|\bgeleia de/, '🍯', 'doce'],
    ['sorvete', /\bsorvete|\bpicole|\bacai na tigela/, '🍨', 'doce'],
    ['pudim', /\bpudim|\bflan\b|\bmousse|\bgelatina|\bsobremesa|\bmarshmallow|\bcompota|\bcaramelo|\bdoce\b|\bbala\b|\bchiclete|\bpacoquinha|\bcocada|\bmaria mole|\bmarmelada|\bquindim|\brapadura|\bsuspiro|\bpe de moleque|\bbeijinho|\bdoce de leite|\bdrops|\bpastilha|\bpirulito|\bgoma de mascar|\bjujuba|\bconfete|\btoddynho|\btrufa|\bpessegada|\bfigada|\bmariola|\bmerengue|\bmanjar|\bquebra queixo|\bqueijadinha|\bpave\b|\bambrosia|\bchandele|\bgeladinho|\bchurro|\brabanada|\btorrone|\bcanudinho|\bbeijo de moca|\bgeleia/, '🍮', 'doce'],
    ['acucar', /\bacucar|\badocante|\bstevia|\bxilitol|\beritritol/, '🍬', 'doce'],
    // cereais, massas e pães
    ['arroz', /\barroz/, '🍚', 'cereal'],
    ['macarrao', /\bmacarrao|\bmassa\b|\bespaguete|\blasanha|\bnhoque|\bpenne|\bmiojo|\blamen|\bfettuccine|\bfusilli/, '🍝', 'cereal'],
    ['pao', /\bpao\b|\bpaes\b|\btorrada|\bbisnaguinha|\bcroissant|\bbaguete|\bciabatta|\bbrioche|\bbisnaga|\bpao sirio|\bpita\b/, '🍞', 'cereal'],
    ['tapioca', /\btapioca|\bbeiju|\bcuscuz|\bgoma de tapioca|\bcrepioca/, '🥞', 'cereal'],
    ['aveia', /\baveia|\bgranola|\bcereal matinal|\bflocos|\bmuesli|\bsucrilhos|\bcornflakes|\bfarelo\b|\bmusli|\bmingau|\bmucilon|\bcremogema|\bneston|\bvitaflocos|\bvitamilho|\bmix de cereais|\bcereais\b/, '🥣', 'cereal'],
    ['milho', /\bmilho|\bpipoca|\bfuba\b|\bpolenta|\bcanjica|\bcurau|\bangu\b|\bamido de milho|\bmaisena|\bquirera|\bpamonha|\bcruera/, '🌽', 'cereal'],
    ['biscoito', /\bbiscoito|\bbolacha|\bcookie|\bcracker|\bwafer|\brosquinha|\bcream cracker/, '🍪', 'cereal'],
    ['bolo', /\bbolo\b|\bbrownie|\bcupcake|\bmuffin|\btorta\b|\bdonut|\bsonho\b|\bpanetone/, '🍰', 'cereal'],
    ['trigo', /\btrigo|\bfarinha|\bcenteio|\bcevada|\bamaranto|\bsarraceno|\bgrao\b|\bcereal/, '🌾', 'cereal'],
    // tubérculos
    ['batata', /\bbatata|\bmandioca|\baipim|\bmacaxeira|\binhame|\bcara\b|\bmandioquinha|\bbaroa|\bpure\b/, '🥔', 'tuberculo'],
    // leguminosas
    ['feijao', /\bfeijao|\blentilha|\bgrao de bico|\bgrao-de-bico|\bervilha|\bsoja\b|\bandu\b|\bguandu|\btremoco|\bedamame|\bfava\b|\btofu|\bhummus|\bfeijoada|\bproteina de soja|\bproteina texturizada/, '🥘', 'leguminosa'],
    // carnes
    ['porco', /\bporco|\bsuin|\btoucinho|\bbacon|\bpresunto|\blombo|\blinguica|\bsalsicha|\bcalabresa|\bmortadela|\bpernil|\bsalame|\bpaio\b|\bapresuntado|\bpeito de peru defumado/, '🥓', 'carne'],
    ['frango', /\bfrango|\bgalinha|\bpeito de peru|\bperu\b|\bchester|\bsobrecoxa|\bcoxa\b|\basa de|\bgalinacea|\bsassami|\bpeito de ave|\bave\b/, '🍗', 'carne'],
    ['bovina', /\bcarne|\bbife\b|\bboi\b|\bbovin|\bpatinho|\balcatra|\bacem\b|\bcoxao|\bfile mignon|\bmusculo|\bfigado|\bcontrafile|\bmaminha|\bpicanha|\bcupim|\bcostela|\bfraldinha|\blagarto|\bpaleta|\bcharque|\balmondega|\bdobradinha|\bmocoto|\bcordeiro|\bcabrito|\bvitela|\bsteak|\bmiolo/, '🥩', 'carne'],
    ['camarao', /\bcamarao|\blula\b|\bmarisco|\bpolvo|\bostra|\bmexilhao|\bcaranguejo|\bsiri\b|\blagosta|\bberbigao|\bvieira/, '🍤', 'peixe'],
    ['peixe', /\bpeixe|\btilapia|\bsalmao|\batum\b|\bsardinha|\bmerluza|\bbacalhau|\bpescada|\bcorvina|\bpintado|\bcacao\b|\babadejo|\btruta|\blambari|\bcorimba|\btucunare|\bporquinho|\btambaqui|\bpirarucu|\bpiau\b|\bpacu\b|\btraira|\btainha|\brobalo|\blinguado|\bnamorado|\bcavala|\barraia|\bpiranha|\bmapara|\bjaraqui|\bmatrinxa|\bcurimata|\bcarpa|\bmanjuba|\bpescadinha|\bsurubim|\bdourado|\banchova|\bfile de peixe|\bbagre|\bsurimi|\bkani/, '🐟', 'peixe'],
    // vegetais (couve e brócolis antes de manteiga/leite)
    ['brocolis', /\bbrocolis|\bcouve flor|\bcouve-flor/, '🥦', 'verdura'],
    ['folha', /\balface|\brucula|\bagriao|\bespinafre|\bcouve|\brepolho|\bacelga|\bchicoria|\bendivia|\bmostarda|\bfolhas?\b|\bhortela|\bmanjericao|\bsalsa\b|\bcebolinha|\bcoentro|\bcheiro verde|\balmeirao|\btaioba|\bcaruru|\bbertalha|\bserralha|\baipo\b|\balfavaca|\bcatalonha|\bjurubeba|\bescarola|\bradite|\bvinagreira|\bcariru|\bjambu\b|\bbredo|\bsalsao|\boregano|\bjardineira|\balecrim|\blouro\b|\bmaricota|\bmimosa|\bbeldroega/, '🥬', 'verdura'],
    ['tomate', /\btomate|\bmolho de|\bketchup|\bextrato de/, '🍅', 'verdura'],
    ['cenoura', /\bcenoura|\bbeterraba|\brabanete|\bnabo\b/, '🥕', 'verdura'],
    ['pepino', /\bpepino|\bchuchu|\babobrinha|\bvagem|\bquiabo|\bjilo|\bmaxixe|\bpicles/, '🥒', 'verdura'],
    ['pimentao', /\bpimentao|\bpimenta/, '🫑', 'verdura'],
    ['cebola', /\bcebola|\balho\b|\bpuerro|\balho poro|\bchalota/, '🧅', 'verdura'],
    ['abobora', /\babobora|\bmoranga|\bjerimum|\bcabotian/, '🎃', 'verdura'],
    ['berinjela', /\bberinjela/, '🍆', 'verdura'],
    ['cogumelo', /\bcogumelo|\bchampignon|\bshimeji|\bshiitake/, '🍄', 'verdura'],
    ['palmito', /\bpalmito|\baspargo|\balcachofra|\bbroto\b|\bbambu|\bgengibre|\bacafrao|\bcurcuma/, '🌿', 'verdura'],
    ['legume', /\blegumes?|\bverduras?|\bvegetais|\bhortalicas?|\bmilho verde|\bchoucrute/, '🥗', 'verdura'],
    // laticínios e gorduras
    ['queijo', /\bqueijo|\bricota|\bcottage|\bmucarela|\bmussarela|\brequeijao|\bminas\b|\bparmesao|\bcoalho|\bprovolone|\bcream cheese|\bcatupiry|\bcheddar|\bgorgonzola|\bburrata|\bfeta\b/, '🧀', 'laticinio'],
    ['leite', /\bleite|\biogurte|\bcoalhada|\bkefir|\bdanone|\byogurt|\bnata\b|\bcreme de leite|\bchantilly|\bbebida lactea|\bpetit suisse|\bfermentado/, '🥛', 'laticinio'],
    ['manteiga', /\bmanteiga|\bmargarina|\bghee|\bcreme vegetal/, '🧈', 'gordura'],
    ['azeite', /\bazeite|\boleo\b|\bgordura|\bbanha|\bazeitona|\bdende|\bmaionese|\bóleo/, '🫒', 'gordura'],
    // frutas
    ['banana', /\bbanana/, '🍌', 'fruta'],
    ['maca', /\bmaca\b|\bmacas\b/, '🍎', 'fruta'],
    ['pera', /\bpera\b/, '🍐', 'fruta'],
    ['laranja', /\blaranja|\btangerina|\bmexerica|\bponkan|\bbergamota|\blimao|\blima\b|\bpomelo|\btoranja|\bgrapefruit|\bcitrica/, '🍊', 'fruta'],
    ['uva', /\buva|\bpassas?\b|\bfrutas secas|\btamara|\bdamasco seco|\bfigo\b/, '🍇', 'fruta'],
    ['morango', /\bmorango|\bcereja|\bframboesa|\bacerola|\bpitanga|\bgrosel|\bcranberry|\bromã|\broma\b/, '🍓', 'fruta'],
    ['melancia', /\bmelancia/, '🍉', 'fruta'],
    ['melao', /\bmelao|\bmamao|\bpapaia/, '🍈', 'fruta'],
    ['abacaxi', /\babacaxi/, '🍍', 'fruta'],
    ['manga', /\bmanga\b|\bmangaba|\bcaju\b|\bcaja\b|\bumbu\b|\bcaqui|\bmaracuja|\bgoiaba|\bcupuacu|\bgraviola|\bjaca\b|\bpitaya|\bpitaia/, '🥭', 'fruta'],
    ['pessego', /\bpessego|\bameixa|\bnectarina|\bdamasco|\bmarmelo|\bnespera|\bjabuticaba|\bciriguela/, '🍑', 'fruta'],
    ['mirtilo', /\bmirtilo|\bamora\b|\bacai|\bblueberry|\bjamelao|\bgoji/, '🫐', 'fruta'],
    ['kiwi', /\bkiwi|\bcarambola|\bfruta do conde|\bpinha\b|\batemoia/, '🥝', 'fruta'],
    ['abacate', /\babacate|\bguacamole/, '🥑', 'fruta'],
    ['coco', /\bcoco\b/, '🥥', 'fruta'],
    ['fruta', /\bfruta|\bpolpa|\babiu|\bjambo|\bmacauba|\bpequi|\btamarindo|\btucuma|\bbutia|\bbacaba|\bbacabucu|\bburiti|\bpatau|\bjussara|\buacai|\bananas|\bmanguito|\btanja|\blaranjinha|\bata\b|\bcajarana|\bpiqui|\bmurici|\bbacuri|\bcambuci|\bgabiroba|\bguabiroba|\bsapoti|\bsapota|\bseriguela|\bpitomba|\bcupu/, '🍎', 'fruta'],
    ['tempero', /\bsal\b|\bfermento|\bshoyu|\bvinagre|\btempero|\bcurry|\bcominho|\bpaprica|\bcanela|\bbaunilha|\bcaldo em/, '🧂', 'prato']
  ];

  var GENERICO = ['prato', '🍽️', 'prato'];
  var _fotos = null, _carregando = false;

  function norm(s) {
    return ' ' + String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim() + ' ';
  }
  // Nome curto para a legenda: as duas primeiras partes do nome da tabela ("Arroz, integral, cozido" -> "Arroz integral").
  function curto(nome) {
    var p = String(nome || '').split(',').map(function (x) { return x.trim(); }).filter(Boolean);
    var t = p.slice(0, 2).join(' ');
    if (t.length > 30) t = t.slice(0, 29).replace(/\s+\S*$/, '') + '…';
    return t || String(nome || '');
  }
  function acharRegra(nome) {
    var n = norm(nome);
    for (var i = 0; i < REGRAS.length; i++) if (REGRAS[i][1].test(n)) return REGRAS[i];
    return null;
  }
  function para(nome) {
    var r = acharRegra(nome), id = r ? r[0] : GENERICO[0], emoji = r ? r[2] : GENERICO[1], grupo = r ? r[3] : GENERICO[2];
    return { id: id, emoji: emoji, grupo: grupo, rgb: GRUPOS[grupo] || GRUPOS.prato, curto: curto(nome), reconhecido: !!r, foto: (_fotos && _fotos[id]) ? 'alimentos-fotos/' + _fotos[id] : null };
  }

  // ── preferência do aluno (ligar e desligar as imagens) ──
  function chave() { return 'intus-nutri-imagens-' + (typeof idAtleta !== 'undefined' ? idAtleta : ''); }
  function ligado() { try { return localStorage.getItem(chave()) !== '0'; } catch (e) { return true; } }
  function definir(v) { try { localStorage.setItem(chave(), v ? '1' : '0'); } catch (e) {} }

  // Fotos da biblioteca (opcional): lê o manifesto uma vez; sem ele, tudo segue em ilustração.
  function carregarFotos() {
    if (_fotos || _carregando) return;
    _carregando = true;
    var url = (typeof _urlAbs === 'function' ? _urlAbs('/app/painel/alimentos-fotos/manifest.json') : 'alimentos-fotos/manifest.json');
    fetch(url, { cache: 'no-cache' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) { _fotos = (j && typeof j === 'object') ? j : {}; })
      .catch(function () { _fotos = {}; }).then(function () { _carregando = false; });
  }
  function urlFoto(rel) { return (typeof _urlAbs === 'function') ? _urlAbs('/app/painel/' + rel) : rel; }

  // Cartão de um alimento: foto (se houver) ou ilustração sobre a cor do grupo. Pequeno de propósito.
  function cartaoHtml(it, tam) {
    var v = para(it && it.nome), t = tam || 76, c = v.rgb;
    var fundo = 'background:linear-gradient(150deg,rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',.34),rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',.10));';
    var miolo = v.foto
      ? '<img src="' + esc(urlFoto(v.foto)) + '" alt="' + esc(v.curto) + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;display:block;"/>'
      : '<span style="font-size:' + Math.round(t * 0.46) + 'px;line-height:1;filter:drop-shadow(0 2px 3px rgba(0,0,0,.25));" aria-hidden="true">' + v.emoji + '</span>';
    return '<div style="flex:0 0 ' + t + 'px;width:' + t + 'px;" title="' + esc(String(it && it.nome || '')) + '">' +
      '<div style="width:' + t + 'px;height:' + t + 'px;border-radius:18px;overflow:hidden;display:flex;align-items:center;justify-content:center;' + fundo + '">' + miolo + '</div>' +
      '<div style="margin-top:4px;font-size:10.5px;font-weight:700;line-height:1.2;text-align:center;opacity:.8;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;word-break:break-word;">' + esc(v.curto) + '</div></div>';
  }
  // Faixa de imagens da refeição (rolagem lateral). Vazia (sem nada) se o aluno desligou ou não há itens.
  function faixaHtml(itens, opts) {
    if (!ligado()) return '';
    var l = (itens || []).filter(function (x) { return x && x.nome; });
    if (!l.length) return '';
    carregarFotos();
    var vistos = {}, unicos = [];
    l.forEach(function (x) { var k = para(x.nome).curto; if (!vistos[k]) { vistos[k] = 1; unicos.push(x); } });
    return '<div class="alim-faixa" style="display:flex;gap:10px;overflow-x:auto;scrollbar-width:none;padding:2px 0 4px;margin:0 -2px 2px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;">' +
      unicos.slice(0, 12).map(function (x) { return '<div style="scroll-snap-align:start;">' + cartaoHtml(x, (opts && opts.tam) || 76) + '</div>'; }).join('') + '</div>';
  }

  window.AlimentoVisual = { para: para, faixaHtml: faixaHtml, cartaoHtml: cartaoHtml, ligado: ligado, definir: definir, curto: curto, carregarFotos: carregarFotos,
    _teste: { norm: norm, REGRAS: REGRAS, acharRegra: acharRegra } };
})();
