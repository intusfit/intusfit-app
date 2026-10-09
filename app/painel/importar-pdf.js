/* importar-pdf.js — lê o PDF de plano alimentar (o que a nutri exporta do sistema de dieta) e monta um plano no formato do Intus.
 *
 * Três etapas, todas no navegador (o PDF não sai do computador):
 *   1. lerItens(arrayBuffer)      pdf.js devolve os trechos de texto com a posição de cada um.
 *   2. interpretar(itens)         reconhece cabeçalho, refeições, alimentos, "OU", "Substituição N" e observações.
 *   3. montarPlano(estr, catalogo) casa cada alimento com a base do Intus e calcula quantidades e nutrientes.
 *
 * O layout lido é o do PDF exportado ("Plano Alimentar / Todos os dias / HH:MM - Refeição / tabela Kcal e Grupo").
 * Posições (pontos do PDF): nome do alimento a 72, Kcal a 345, Grupo a 436, observações a 60.
 * Funciona também em Node (testes/pdf_plano.js), recebendo trechos {str, x, y} na ordem em que o PDF os desenha.
 */
(function (root) {
  'use strict';

  var STOP = { de: 1, da: 1, do: 1, das: 1, dos: 1, e: 1, com: 1, sem: 1, em: 1, a: 1, o: 1, as: 1, os: 1, ao: 1, na: 1, no: 1, s: 1, c: 1, d: 1, tipo: 1, etc: 1, ou: 1, para: 1, um: 1, uma: 1 };
  // O que a nutri escreve e a tabela do Intus chama de outro jeito.
  var PENALIZA = { light: 1, diet: 1, organic: 1, instantane: 1, molho: 1, frit: 1, empanad: 1, calda: 1, sauté: 1, sute: 1, sabor: 1, vitamina: 1, suco: 1, bolo: 1 };
  var SINONIMOS = [[/\barroz branco\b/g, 'arroz tipo 1'], [/\bavocado\b/g, 'abacate'], [/\bpao de forma\b/g, 'pao de forma industrializado'], [/\bfrango peito\b/g, 'peito frango']];

  function norm(s) {
    return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[®™©´`']/g, '').replace(/[^a-z0-9 ]+/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function stem(t) {
    if (t.length > 3) t = t.replace(/s$/, '');
    if (t.length > 4) t = t.replace(/[aoe]$/, '');
    return t;
  }
  function tokens(s) {
    var n = norm(s);
    SINONIMOS.forEach(function (p) { n = n.replace(p[0], p[1]); });
    var out = [], vistos = {};
    n.split(' ').forEach(function (t) {
      if (!t || STOP[t]) return;
      t = stem(t);
      if (!vistos[t]) { vistos[t] = 1; out.push(t); }
    });
    return out;
  }
  function num(v) { var n = parseFloat(String(v).replace(/\./g, '').replace(',', '.')); return isFinite(n) ? n : 0; }
  function r1(v) { return Math.round((Number(v) || 0) * 10) / 10; }
  function maiuscula(t) { t = String(t || '').trim(); return t ? t.charAt(0).toUpperCase() + t.slice(1) : t; }
  function titulo(t) {
    return String(t || '').trim().toLowerCase().replace(/(^|\s)([a-zà-ú])/g, function (m, a, b) { return a + b.toUpperCase(); })
      .replace(/\b(De|Da|Do|Das|Dos|E)\b/g, function (m) { return m.toLowerCase(); });
  }

  // ───────────────────────── 1. leitura do PDF ─────────────────────────
  // pdfjsLib = window.pdfjsLib (vendor/pdfjs/pdf.min.js). Devolve os trechos de texto na ordem do arquivo, com a posição.
  async function lerItens(arrayBuffer, pdfjsLib) {
    var lib = pdfjsLib || root.pdfjsLib;
    if (!lib) throw new Error('Leitor de PDF não carregou.');
    var pdf = await lib.getDocument({ data: new Uint8Array(arrayBuffer) }).promise;
    var itens = [];
    for (var p = 1; p <= pdf.numPages; p++) {
      var pag = await pdf.getPage(p);
      var tc = await pag.getTextContent();
      tc.items.forEach(function (it) {
        if (it && typeof it.str === 'string' && it.transform) itens.push({ pg: p, str: it.str, x: it.transform[4], y: it.transform[5] });
      });
    }
    return itens;
  }

  // ───────────────────────── 2. interpretação ─────────────────────────
  function juntar(partes) {
    var s = '';
    partes.forEach(function (t) {
      if (!s) { s = t; return; }
      s += (/\s$/.test(s) || /^\s/.test(t)) ? t : ' ' + t;
    });
    return s.replace(/\s+/g, ' ').trim();
  }

  // "Mamão (Grama: 170) OU Kiwi (Grama: 150) OU outro pão" -> alimento principal + alternativas (com quantidade ou só texto).
  var RE_MED = /\(\s*([^():]+?)\s*:\s*([\d.,]+)\s*\)\s*$/;
  function dividirOU(texto) {
    var partes = String(texto).split(/\s+OU\s+/);
    var out = { principal: null, alternativas: [] };
    var pendente = [];
    partes.forEach(function (p) {
      p = p.trim();
      if (!p) return;
      var m = RE_MED.exec(p);
      if (m) {
        var nome = p.slice(0, m.index).trim();
        if (!out.principal) {
          if (pendente.length) { nome = pendente.join(' OU ') + ' OU ' + nome; pendente = []; }   // "Peito de galinha OU frango Cozido(a) (Grama: 100)" é um alimento só
          out.principal = { nome: nome, qtd: num(m[2]), unidade: m[1].trim() };
        } else {
          out.alternativas.push({ nome: nome, qtd: num(m[2]), unidade: m[1].trim() });
        }
      } else if (!out.principal) {
        pendente.push(p);
      } else {
        out.alternativas.push({ texto: p.replace(/[,;]+$/, '').trim() });   // "outro pão disponível": só texto
      }
    });
    if (!out.principal) out.principal = { nome: pendente.join(' OU ') || String(texto).trim(), qtd: 0, unidade: '' };
    return out;
  }

  // itens = [{ pg, str, x, y }] na ordem do PDF. Devolve { aluno, titulo, objetivo, data, refeicoes[], avisos[] }.
  function interpretar(itens) {
    var avisos = [], cab = { aluno: '', titulo: '', data: '' };
    var refeicoes = [], ref = null, sec = null, buf = [], nota = [];
    var RE_REF = /^(\d{1,2}:\d{2})\s*-\s*(.+)$/, RE_SUB = /^Substitui[cç][aã]o\s*(\d+)/i, RE_KCAL = /^([\d.]*\d,\d+|\d+)\s*kcal$/i;

    // Colunas lidas do próprio PDF: o x do cabeçalho "Grupo" e o x mais comum dos nomes de alimento.
    var xGrupo = 436, xs = {};
    var primeira = itens.findIndex(function (i) { return RE_REF.test(i.str.trim()); });
    itens.forEach(function (it, k) {
      if (it.str.trim() === 'Grupo') xGrupo = it.x;
      if (primeira >= 0 && k > primeira && it.x >= 50 && it.x < 130 && !RE_REF.test(it.str.trim())) { var kx = Math.round(it.x); xs[kx] = (xs[kx] || 0) + 1; }
    });
    var xNome = 72, melhor = 0;
    Object.keys(xs).forEach(function (k) { if (xs[k] > melhor) { melhor = xs[k]; xNome = Number(k); } });

    function fecharNota() {
      if (nota.length && sec) { var t = juntar(nota); sec.nota = sec.nota ? sec.nota + ' ' + t : t; }
      nota = [];
    }
    function abrirSecao(nome) { var s = { nome: nome, itens: [], nota: '' }; return s; }

    var viuTitulo = false;
    itens.forEach(function (it, k) {
      var s = it.str.replace(/ /g, ' ');
      var t = s.trim();
      if (!t) return;
      if (!ref) {                                   // cabeçalho antes da primeira refeição
        if (RE_REF.test(t)) { /* cai no tratamento abaixo */ }
        else {
          if (/^Plano alimentar para/i.test(t)) cab.titulo = t;
          else if (/^\d{2}\/\d{2}\/\d{4}$/.test(t)) cab.data = t;
          else if (/^Plano Alimentar$/i.test(t)) viuTitulo = true;
          else if (viuTitulo && !cab.aluno && !/^Todos os dias$/i.test(t)) cab.aluno = t;     // a linha logo depois do título é o nome do aluno
          return;
        }
      }
      var m = RE_REF.exec(t);
      if (m && it.x < 130) {
        fecharNota(); buf = [];
        ref = { horario: m[1].padStart(5, '0'), nome: maiuscula(m[2].trim().toLowerCase()), principal: abrirSecao('Principal'), subs: [] };
        refeicoes.push(ref); sec = ref.principal; return;
      }
      if (RE_SUB.test(t) && it.x < 130) {
        fecharNota(); buf = [];
        var ms = RE_SUB.exec(t);
        var sb = abrirSecao('Substituição ' + ms[1]); ref.subs.push(sb); sec = sb; return;
      }
      if (t === 'Kcal' || t === 'Grupo') return;
      if (it.x >= xGrupo - 8) return;               // coluna do grupo alimentar: não interessa
      var mk = RE_KCAL.exec(t);
      if (mk) {
        if (!sec) return;
        fecharNota();
        var texto = juntar(buf); buf = [];
        if (!texto) return;
        var d = dividirOU(texto);
        sec.itens.push({ principal: d.principal, alternativas: d.alternativas, kcal: num(mk[1]), original: texto });
        return;
      }
      if (it.x < xNome - 5) { nota.push(s); return; }    // observação da nutri (fica à esquerda dos alimentos)
      if (nota.length) fecharNota();
      buf.push(s);
    });
    fecharNota();
    if (buf.length) avisos.push('Sobrou texto sem calorias no fim do PDF: ' + juntar(buf).slice(0, 80));
    if (!refeicoes.length) avisos.push('Não encontrei nenhuma refeição neste PDF.');

    var objetivo = '';
    var tit = cab.titulo.replace(/\s*\((duplicado|importado)\)/gi, '').trim();
    var ia = cab.aluno ? tit.toLowerCase().indexOf(cab.aluno.trim().toLowerCase()) : -1;     // o PDF escreve o nome do jeito que foi digitado ("rosangela de souza")
    if (ia >= 0) tit = tit.slice(0, ia) + titulo(cab.aluno) + tit.slice(ia + cab.aluno.trim().length);
    var mo = / - (.+)$/.exec(tit);
    if (mo) objetivo = maiuscula(mo[1].trim());
    return { aluno: titulo(cab.aluno), titulo: tit, objetivo: objetivo, data: cab.data, refeicoes: refeicoes, avisos: avisos };
  }

  // ───────────────────────── 3. montagem do plano ─────────────────────────
  // catalogo = { todos(): alimentos[], medidas(f): [{id,nome,gramas}], por100(f): tabela de 100 g, escalar(por100, gramas) }
  function ehGrama(u) { var n = norm(u); return n === 'grama' || n === 'gramas' || n === 'g'; }
  function slug(u) { return norm(u).replace(/ /g, '_'); }

  function candidatos(nome, cat, indice) {
    var corte = String(nome).split(/\s+-\s+/)[0];
    var fora = corte.replace(/\([^)]*\)/g, ' '), dentro = (corte.match(/\(([^)]*)\)/g) || []).join(' ');
    var core = tokens(fora), extra = tokens(dentro).filter(function (t) { return core.indexOf(t) < 0; });
    if (!core.length) { core = extra; extra = []; }
    if (!core.length) return { lista: [], core: core };
    var preciso = Math.max(1, Math.ceil(core.length * 0.4));
    var lista = [];
    indice.forEach(function (e) {
      var ach = 0, achX = 0;
      core.forEach(function (t) { if (e.set[t]) ach++; });
      extra.forEach(function (t) { if (e.set[t]) achX++; });
      var cabeca = !!e.set[core[0]];
      if (!cabeca && ach + achX < 2) return;
      if (ach + achX < preciso) return;
      if (ach === 0 && achX === 0) return;
      var recall = core.length ? ach / core.length : 0;
      var prec = (ach + achX) / Math.max(1, e.n);
      // Variações que mudam o alimento (light, frito, em calda...) só valem se o PDF também as cita.
      var pena = 0;
      if (!(extra.length && achX)) Object.keys(e.set).forEach(function (t) { if (PENALIZA[t] && core.indexOf(t) < 0 && extra.indexOf(t) < 0) pena += 0.12; });
      var score = recall * 0.6 + (extra.length ? achX / extra.length : 0) * 0.15 + prec * 0.25 + (e.f.custom ? 0.05 : (e.f.id < 100000 ? 0.02 : 0)) - pena;
      lista.push({ f: e.f, score: score, recall: recall });
    });
    lista.sort(function (a, b) { return b.score - a.score; });
    return { lista: lista, core: core };
  }

  // gramas de 1 unidade da medida pedida no PDF, quando o catálogo conhece (senão null)
  function medidaDoCatalogo(f, unidade, cat) {
    if (ehGrama(unidade)) return { id: 'g', nome: 'gramas', gramas: 1 };
    var u = norm(unidade), achou = null, tam = /pequen|grand/.exec(u);
    cat.medidas(f).forEach(function (m) {
      var n = norm(m.nome);
      if (tam && n.indexOf(tam[0]) < 0) return;                  // "unidade pequena" não é a "unidade" do catálogo
      if (!achou && (n === u || n.indexOf(u) === 0 || u.indexOf(n) === 0)) achou = m;
    });
    return achou ? { id: achou.id, nome: achou.nome, gramas: achou.gramas } : null;
  }

  function criarItemCatalogo(f, qtd, med, cat) {
    var p100 = cat.por100(f), gramas = med.id === 'g' ? qtd : r1(qtd * med.gramas);
    var it = { food_id: f.id, nome: f.description, quantidade: qtd, medida_id: med.id, medida_nome: med.nome, medida_g: med.gramas, gramas: gramas, nutrientes: cat.escalar(p100, gramas) };
    var ms = cat.medidas(f).filter(function (m) { return m.gramas > 0; }).slice(0, 8).map(function (m) { return { n: m.nome, g: m.gramas }; });
    if (ms.length) it.medidas = ms;
    it.substitutos = [];
    return it;
  }
  function criarItemProprio(nome, qtd, med, kcalTotal, gramas, cat) {
    var base = cat.por100({});                                   // tabela zerada, com todas as chaves
    base.energia_kcal = gramas > 0 ? Math.round(kcalTotal / gramas * 100 * 100) / 100 : 0;
    return { food_id: null, nome: nome, quantidade: qtd, medida_id: med.id, medida_nome: med.nome, medida_g: med.gramas, gramas: gramas,
             nutrientes: cat.escalar(base, gramas), por100: base, substitutos: [] };
  }
  function itemTexto(a) {
    if (a.texto != null) return { nome: a.texto, quantidade: '', medida: '', food_id: null, manual: true };
    return { nome: a.nome, quantidade: a.qtd, medida: a.unidade, food_id: null, manual: true };
  }

  // Um alimento do PDF -> item do plano + uma linha do relatório de conferência.
  function converterAlimento(a, kcalPdf, cat, indice, relatorio, papel) {
    var cand = candidatos(a.nome, cat, indice);
    var escolhido = null, derivada = null;
    var ehG = ehGrama(a.unidade), q = a.qtd;
    var pool = cand.lista.length ? cand.lista.filter(function (c) { return c.score >= cand.lista[0].score - 0.2; }).slice(0, 25) : [];
    if (pool.length) {
      pool.forEach(function (c) {
        var med = medidaDoCatalogo(c.f, a.unidade, cat);
        c.med = med;
        c.kcal100 = cat.por100(c.f).energia_kcal || 0;
        c.dev = (kcalPdf > 0 && med && c.kcal100 > 0) ? Math.abs(c.kcal100 * (q * med.gramas) / 100 - kcalPdf) / kcalPdf : (kcalPdf > 0 ? 0.3 : 0);
        c.ordem = c.score - c.dev * 0.5 + (med ? 0.05 : 0);
      });
      pool.sort(function (x, y) { return y.ordem - x.ordem; });
      escolhido = pool[0];
      var limite = escolhido.recall >= 0.99 ? 0.5 : 0.25;
      var unico = escolhido.recall >= 0.99 && pool.filter(function (c) { return c.recall >= 0.99; }).length === 1;
      if (kcalPdf > 0) {
        if (ehG) {
          if (escolhido.dev > limite) escolhido = null;
        } else if (!escolhido.med) {
          if (!unico) escolhido = null;                              // medida caseira desconhecida e vários alimentos possíveis: não chuta
        } else if (escolhido.dev > limite) {
          // O PDF pesa a medida diferente do catálogo (ex.: colher de sopa de azeite = 7 g, não 13 g): vale o peso que bate com as calorias dele.
          var g1 = escolhido.kcal100 > 0 ? kcalPdf / escolhido.kcal100 * 100 / q : 0;
          if (unico && g1 > 0 && g1 / escolhido.med.gramas >= 0.3 && g1 / escolhido.med.gramas <= 3) { escolhido.med = null; }
          else escolhido = null;
        }
      }
    }
    if (escolhido) {
      var med = escolhido.med;
      if (!med && kcalPdf > 0 && escolhido.kcal100 > 0 && q > 0) {   // o PDF só dá a medida caseira: o peso sai das calorias dele
        med = { id: 'pers_' + slug(a.unidade), nome: maiuscula(a.unidade.toLowerCase()), gramas: r1(kcalPdf / escolhido.kcal100 * 100 / q) };
      }
      if (med) {
        var it = criarItemCatalogo(escolhido.f, q, med, cat);
        relatorio.push({ papel: papel, nome: a.nome, kcalPdf: kcalPdf || null, kcal: it.nutrientes.energia_kcal, tipo: 'catalogo', alimento: escolhido.f.description });
        return it;
      }
    }
    // Sem alimento parecido na base: entra com as calorias do PDF (se houver) e a nutri completa os macros.
    if (kcalPdf > 0 && q > 0) {
      var med2 = ehG ? { id: 'g', nome: 'gramas', gramas: 1 } : { id: 'pers_' + slug(a.unidade), nome: maiuscula(a.unidade.toLowerCase()), gramas: norm(a.unidade) === 'mililitro' ? 1 : 100 };
      var gr = ehG ? q : r1(q * med2.gramas);
      var it2 = criarItemProprio(a.nome, q, med2, kcalPdf, gr, cat);
      relatorio.push({ papel: papel, nome: a.nome, kcalPdf: kcalPdf, kcal: it2.nutrientes.energia_kcal, tipo: 'so_calorias', alimento: '' });
      return it2;
    }
    relatorio.push({ papel: papel, nome: a.nome, kcalPdf: null, kcal: null, tipo: 'texto', alimento: '' });
    return itemTexto(a);
  }

  function legado(itens) {
    return itens.map(function (i) { return i.nome + ' — ' + i.quantidade + (i.medida_id === 'g' ? 'g' : ' ' + i.medida_nome); }).join('\n');
  }

  function montarPlano(estr, cat) {
    var indice = cat.todos().map(function (f) { var t = tokens(f.description); var set = {}; t.forEach(function (x) { set[x] = 1; }); return { f: f, set: set, n: t.length }; });
    var relatorio = [];
    function converterSecao(sec, rotulo) {
      return sec.itens.map(function (row) {
        var p = row.principal, it;
        if (!(p.qtd > 0)) {                       // linha sem "(Medida: quantidade)": guarda como texto
          relatorio.push({ papel: rotulo, nome: row.original, kcalPdf: row.kcal || null, kcal: null, tipo: 'texto', alimento: '' });
          it = { nome: row.original, quantidade: '', medida: '', food_id: null, manual: true };
          return it;
        }
        it = converterAlimento(p, row.kcal, cat, indice, relatorio, rotulo);
        row.alternativas.forEach(function (alt) {
          if (alt.texto != null) { it.substitutos.push(itemTexto(alt)); relatorio.push({ papel: rotulo + ' (troca)', nome: alt.texto, kcalPdf: null, kcal: null, tipo: 'texto', alimento: '' }); return; }
          var sub = converterAlimento(alt, 0, cat, indice, relatorio, rotulo + ' (troca)');
          if (sub.por100 && !sub.nutrientes.energia_kcal) sub = itemTexto(alt);           // sem calorias para saber: só texto
          if (sub.substitutos) sub.substitutos = [];
          it.substitutos.push(sub);
        });
        return it;
      });
    }
    var refeicoes = estr.refeicoes.map(function (r) {
      var itens = converterSecao(r.principal, r.nome);
      var o = { nome: r.nome, horario: r.horario, itens: legado(itens.filter(function (i) { return !i.manual; })), itens_estruturados: itens, refeicoes_substitutas: [] };
      if (r.principal.nota) o.nota = maiuscula(r.principal.nota);
      r.subs.forEach(function (s) {
        var si = converterSecao(s, r.nome + ' · ' + s.nome);
        var so = { nome: s.nome, itens_estruturados: si };
        if (s.nota) so.nota = maiuscula(s.nota);
        o.refeicoes_substitutas.push(so);
      });
      return o;
    });
    var dt = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(estr.data || '');
    var plano = {
      formato: 'intus-plano-v1', aluno_nome: estr.aluno, origem: 'PDF importado no painel', titulo: estr.titulo || 'Plano Alimentar', objetivo: estr.objetivo || '',
      dtinicio: dt ? dt[3] + '-' + dt[2] + '-' + dt[1] : null, dtfim: null, refeicoes: refeicoes,
    };
    return { plano: plano, relatorio: relatorio, avisos: estr.avisos };
  }

  var api = { norm: norm, tokens: tokens, lerItens: lerItens, interpretar: interpretar, dividirOU: dividirOU, montarPlano: montarPlano };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  root.ImportarPDF = api;
})(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this));
