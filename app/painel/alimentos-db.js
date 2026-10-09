// ── BANCO DE ALIMENTOS INTUS ──────────────────────────────────────────
// Carrega TACO (597 alimentos) + medidas caseiras + grupos de equivalência
// Fornece busca fuzzy, cálculo de macros por porção, e sugestões
// GEB/TMB: Harris-Benedict, Mifflin-St Jeor, Katch-McArdle, Cunningham, FAO/OMS, Schofield
// Populações especiais: gestantes, lactantes, idosos, crianças/adolescentes
// Extras: TEF, proteína por objetivo, ingestão hídrica, IMC, distribuição de macros por meta

const AlimentosDB = (() => {
  let _taco = [];
  let _medidas = {};
  let _medidasPadrao = [];
  let _grupos = [];
  let _loaded = false;
  let _loadPromise = null;

  const DATA_PATH = '../data/';
  let _customFoods = [];
  // Medidas caseiras por alimento da TACO (unidades.json). Chave = id da TACO, conferido pelo nome na geracao do arquivo.
  let _unidades = {};
  // Alimentos extras (alimentos-extra.json): IBGE (POF 2008-2009) e USDA SR Legacy traduzido. Ids a partir de 100.000, entre a TACO
  // (1..597) e os "Meus alimentos" (1.000.000+). Entram na busca depois da TACO.
  let _extra = [];
  const EXTRA_BASE = 100000;

  // Os "Meus Alimentos" vêm do servidor com id 1, 2, 3... e a TACO também usa 1..597. Antes isso fazia o 1º alimento
  // cadastrado esconder o "Arroz integral cozido", o 2º esconder o "Arroz integral cru" e assim por diante (e os planos
  // já montados passavam a usar os nutrientes do alimento errado). Agora o id de um "Meu alimento" no app é 1.000.000 + id
  // do servidor (dbid guarda o id do servidor) e nunca colide com a TACO.
  const CUSTOM_BASE = 1000000;
  function _prepCustom(f) {
    const dbid = Number(f.dbid != null ? f.dbid : f.id);
    return Object.assign({}, f, { dbid: dbid, id: CUSTOM_BASE + dbid, custom: true });
  }
  function _nsId(id) { id = Number(id); return id >= CUSTOM_BASE ? id : CUSTOM_BASE + id; }
  function _dbidDe(id) { id = Number(id); return id >= CUSTOM_BASE ? id - CUSTOM_BASE : id; }

  async function load() {
    if (_loaded) return;
    if (_loadPromise) return _loadPromise;
    _loadPromise = _doLoad();
    return _loadPromise;
  }

  async function _doLoad() {
    try {
      const fetchJson = async (url) => {
        const r = await fetch(url);
        if (!r.ok) throw new Error(`HTTP ${r.status} ao buscar ${url}`);
        return r.json();
      };
      const [tacoResp, medidasResp, gruposResp, unidResp, extraResp] = await Promise.all([
        fetchJson(DATA_PATH + 'taco.json'),
        fetchJson(DATA_PATH + 'medidas-caseiras.json'),
        fetchJson(DATA_PATH + 'grupos-equivalencia.json'),
        fetchJson(DATA_PATH + 'unidades.json?v=20261008a').catch(() => ({ itens: {} })),
        fetchJson(DATA_PATH + 'alimentos-extra.json?v=20261008a').catch(() => ({ alimentos: [], medidas: {} })),
      ]);
      _unidades = Object.assign({}, (unidResp && unidResp.itens) || {}, (extraResp && extraResp.medidas) || {});
      _extra = Array.isArray(extraResp && extraResp.alimentos) ? extraResp.alimentos : [];
      _taco = Array.isArray(tacoResp) ? tacoResp : [];
      _medidasPadrao = medidasResp.medidas_padrao || [];
      _medidas = medidasResp.alimentos_medidas || {};
      _grupos = gruposResp.grupos || [];
      _loaded = true;
      // "Meus Alimentos" mora no servidor (tabela intus_alimento_personalizado)
      // desde 17/09/2026 — antes vivia só no localStorage do navegador e
      // sumia ao trocar de aparelho ou limpar dados. Falha aqui não derruba o
      // resto do banco (TACO já carregou); só fica sem os personalizados.
      try {
        const remotos = (typeof API !== 'undefined' && API.listarAlimentosPersonalizados)
          ? await API.listarAlimentosPersonalizados() : [];
        _customFoods = Array.isArray(remotos) ? remotos.map(_prepCustom) : [];
      } catch (e) {
        console.error('[AlimentosDB] Erro ao carregar Meus Alimentos do servidor:', e);
        _customFoods = [];
      }
      console.log(`[AlimentosDB] Carregado: ${_taco.length} TACO + ${_extra.length} extras + ${_customFoods.length} personalizados`);
    } catch (e) {
      console.error('[AlimentosDB] Erro ao carregar dados:', e);
      _taco = [];
      _loadPromise = null;
    }
  }

  function normalizar(str) {
    return String(str || '').toLowerCase()
      .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^a-z0-9 ]/g, '').trim();
  }

  function buscar(termo, limite = 20) {
    if (!termo || termo.length < 2) return [];
    const termoN = normalizar(termo);
    const palavras = termoN.split(/\s+/);

    const allFoods = [..._customFoods, ..._taco, ..._extra];

    const resultados = allFoods
      .map(food => {
        const descN = normalizar(food.description);
        const descPalavras = descN.split(/\s+/);
        let score = 0;
        let allMatch = true;
        for (const p of palavras) {
          if (descPalavras.includes(p)) {
            // Palavra inteira bate (ex.: "maca" == a palavra "maca" de "Maçã, Fuji").
            // Vale muito mais que um match parcial, senão "maçã" empata com
            // "Macarrão" (que também contém "maca" como prefixo) e a ordem de
            // desempate vira sorteio — foi o que fazia "maçã" sugerir macarrão.
            score += p.length * 2 + 10;
            if (descN.startsWith(p)) score += 5;
          } else if (descN.includes(p)) {
            score += p.length;
          } else {
            allMatch = false;
          }
        }
        if (!allMatch) return null;
        if (food.custom) score += 10;
        else if (food.id >= EXTRA_BASE) score -= 3;   // a TACO vem antes quando os dois têm o alimento
        return { food, score };
      })
      .filter(Boolean)
      .sort((a, b) => b.score - a.score)
      .slice(0, limite)
      .map(r => r.food);

    return resultados;
  }

  // ── BUSCA ONLINE (Open Food Facts) — produtos de marca ────────────────
  // A TACO cobre bem alimento fresco/preparo caseiro (arroz, frango, banana),
  // mas não tem como ter "Whey Protein da marca X" ou "barra de proteína Y" —
  // são milhares de produtos comerciais que mudam de formulação o tempo todo.
  // O Open Food Facts é um banco colaborativo aberto (licença ODbL, permite
  // uso comercial) com forte cobertura de produto de marca, inclusive
  // brasileiro. A busca em si passa pelo NOSSO servidor (catalogo.php) — a
  // busca por texto do Open Food Facts não libera CORS pra chamada direta do
  // navegador (testado: só a consulta por código de barras libera). Nunca
  // guardamos a base deles aqui — o profissional decide alimento por
  // alimento, e o que ele escolhe vira um alimento personalizado normal.
  async function buscarOnline(termo, limite = 8) {
    if (!termo || termo.length < 2) return [];
    try {
      const token = localStorage.getItem('mx-token') || '';
      const ctrl = new AbortController();
      const timeout = setTimeout(() => ctrl.abort(), 8000);
      const base = (typeof API_BASE !== 'undefined' && API_BASE) ? API_BASE : '/app/api';
      const r = await fetch(
        base + '/catalogo.php?action=busca_alimento_online&termo=' + encodeURIComponent(termo),
        { headers: { Authorization: 'Bearer ' + token }, signal: ctrl.signal, cache: 'no-store' }
      );
      clearTimeout(timeout);
      if (!r.ok) return [];
      const data = await r.json();
      return Array.isArray(data) ? data.slice(0, limite) : [];
    } catch (e) {
      console.warn('[AlimentosDB] busca online indisponível:', e);
      return [];
    }
  }

  function getById(id) {
    const numId = Number(id);
    if (numId >= CUSTOM_BASE) return _customFoods.find(f => f.id === numId) || null;
    if (numId >= EXTRA_BASE) return _extra.find(f => f.id === numId) || null;
    return _taco.find(f => f.id === numId) || null;
  }

  // Acha o alimento de um item de plano salvo. Plano antigo guardava só o número, que podia ser de um "Meu alimento" ou da
  // TACO com o mesmo número; o nome do item resolve a dúvida. Devolve null se não existe mais (o item segue com os valores
  // que foram salvos nele).
  function resolver(item) {
    if (!item) return null;
    const id = Number(item.food_id);
    const nome = normalizar(item.nome || item.description || '');
    if (id >= CUSTOM_BASE) return getById(id);
    if (id >= EXTRA_BASE) {
      const e = _extra.find(f => f.id === id);
      if (e && (!nome || normalizar(e.description) === nome)) return e;
      return nome ? (_customFoods.find(f => normalizar(f.description) === nome) || _taco.find(f => normalizar(f.description) === nome) || _extra.find(f => normalizar(f.description) === nome) || null) : null;
    }
    const t = _taco.find(f => f.id === id);
    if (t && (!nome || normalizar(t.description) === nome)) return t;
    const c = _customFoods.find(f => f.dbid === id && (!nome || normalizar(f.description) === nome));
    if (c) return c;
    if (nome) return _customFoods.find(f => normalizar(f.description) === nome) || _taco.find(f => normalizar(f.description) === nome) || _extra.find(f => normalizar(f.description) === nome) || null;
    return t || null;
  }

  function getCategories() {
    const cats = new Set();
    _taco.forEach(f => { if (f.category) cats.add(f.category); });
    _extra.forEach(f => { if (f.category) cats.add(f.category); });
    return [...cats].sort();
  }

  function getByCategory(category) {
    return _taco.filter(f => f.category === category);
  }

  function parseNumeric(val) {
    if (val === '' || val === null || val === undefined || val === 'NA' || val === 'Tr') return 0;
    const n = parseFloat(val);
    return isNaN(n) ? 0 : n;
  }

  // [chave de saída, campo da TACO, casas decimais do resultado]
  const _NUT = [
    ['energia_kcal', 'energy_kcal', 1], ['proteina_g', 'protein_g', 1], ['carboidrato_g', 'carbohydrate_g', 1], ['lipidio_g', 'lipid_g', 1],
    ['fibra_g', 'fiber_g', 1], ['colesterol_mg', 'cholesterol_mg', 1], ['sodio_mg', 'sodium_mg', 1], ['calcio_mg', 'calcium_mg', 1],
    ['ferro_mg', 'iron_mg', 1], ['potassio_mg', 'potassium_mg', 1], ['magnesio_mg', 'magnesium_mg', 1], ['fosforo_mg', 'phosphorus_mg', 1],
    ['zinco_mg', 'zinc_mg', 1], ['vitamina_c_mg', 'vitaminC_mg', 1], ['tiamina_mg', 'thiamine_mg', 2], ['riboflavina_mg', 'riboflavin_mg', 2],
    ['niacina_mg', 'niacin_mg', 1], ['saturados_g', 'saturated_g', 1], ['monoinsaturados_g', 'monounsaturated_g', 1], ['poliinsaturados_g', 'polyunsaturated_g', 1],
  ];
  // Tabela nutricional de 100 g do alimento, já nas chaves usadas pelos itens do plano.
  function nutrientesPor100(food) {
    const o = {};
    _NUT.forEach(function (n) { o[n[0]] = Math.round(parseNumeric(food[n[1]]) * 10000) / 10000; });
    return o;
  }
  // Escala uma tabela de 100 g para uma quantidade em gramas.
  function escalar(por100, gramas) {
    const fator = (Number(gramas) || 0) / 100, o = {};
    _NUT.forEach(function (n) { const m = n[2] === 2 ? 100 : 10; o[n[0]] = Math.round((Number(por100 && por100[n[0]]) || 0) * fator * m) / m; });
    return o;
  }
  function calcNutrientes(foodId, quantidadeGramas) {
    const food = getById(foodId);
    if (!food) return null;
    return escalar(nutrientesPor100(food), quantidadeGramas);
  }

  // Medidas caseiras do alimento, com gramas por 1 medida. Só entra o que é conhecido para aquele alimento: antes valia uma
  // lista genérica (unidade = 100 g, fatia = 30 g...) que dava 1 ovo = 143 kcal. Sem medida cadastrada, o alimento fica em gramas.
  function getMedidasAlimento(foodId) {
    const numId = Number(foodId);
    if (numId >= CUSTOM_BASE) {
      const c = _customFoods.find(f => f.id === numId);
      if (!c) return [];
      const medidas = [];
      if (c.medida_padrao && c.medida_padrao !== 'g') {
        medidas.push({ id: 'custom_porcao', nome: c.medida_padrao, abrev: c.medida_padrao, gramas: c.porcao_padrao || 100 });
      }
      return medidas;
    }
    const u = _unidades[String(numId)];
    if (!u || !Array.isArray(u.m)) return [];
    return u.m.map(m => ({ id: m[0], nome: m[1], abrev: m[1], gramas: m[2] }));
  }

  // Medida e quantidade sugeridas ao incluir o alimento num plano (ex.: ovo = 2 unidades). null = usar 100 g.
  function getUnidadePadrao(foodId) {
    const meds = getMedidasAlimento(foodId);
    if (!meds.length) return null;
    const numId = Number(foodId);
    const u = numId < CUSTOM_BASE ? _unidades[String(numId)] : null;
    const alvo = u && u.p ? meds.find(m => m.id === u.p[0]) : null;
    const m = alvo || meds[0];
    return { medida_id: m.id, medida_nome: m.nome, medida_g: m.gramas, quantidade: (u && u.p && alvo) ? u.p[1] : 1 };
  }

  function getMedidasPadrao() {
    return _medidasPadrao;
  }

  function getGrupos() {
    return _grupos;
  }

  function getGrupo(id) {
    return _grupos.find(g => g.id === id) || null;
  }

  function getSugestaoSubstituicao(foodName) {
    const termoN = normalizar(foodName);
    for (const grupo of _grupos) {
      const match = grupo.itens.find(item => normalizar(item.alimento).includes(termoN) || termoN.includes(normalizar(item.alimento)));
      if (match) {
        return {
          grupo: grupo.nome,
          grupoId: grupo.id,
          alternativas: grupo.itens.filter(item => item.alimento !== match.alimento),
        };
      }
    }
    return null;
  }

  function calcDistribuicaoMacros(kcal, proteina_g, carb_g, gord_g) {
    const totalKcalMacros = (proteina_g * 4) + (carb_g * 4) + (gord_g * 9);
    const ref = totalKcalMacros || kcal || 1;
    return {
      proteina_pct: Math.round((proteina_g * 4 / ref) * 100),
      carboidrato_pct: Math.round((carb_g * 4 / ref) * 100),
      gordura_pct: Math.round((gord_g * 9 / ref) * 100),
    };
  }

  // ─── GEB/TMB FORMULAS ────────────────────────────────────────────────

  /**
   * Harris-Benedict (1919, revisada 1984)
   * @param {number} peso - kg
   * @param {number} altura_cm - cm
   * @param {number} idade - anos
   * @param {string} genero - 'M' ou 'F'
   * @returns {number} GEB em kcal/dia
   */
  function calcGEB(peso, altura_cm, idade, genero) {
    if (genero === 'M') {
      return 66.5 + (13.75 * peso) + (5.003 * altura_cm) - (6.755 * idade);
    }
    return 655.1 + (9.563 * peso) + (1.850 * altura_cm) - (4.676 * idade);
  }

  /**
   * Mifflin-St Jeor (1990) — fórmula moderna mais utilizada
   * @param {number} peso - kg
   * @param {number} altura_cm - cm
   * @param {number} idade - anos
   * @param {string} genero - 'M' ou 'F'
   * @returns {number} TMB em kcal/dia
   */
  function calcGEB_MifflinStJeor(peso, altura_cm, idade, genero) {
    if (genero === 'M') {
      return (10 * peso) + (6.25 * altura_cm) - (5 * idade) + 5;
    }
    return (10 * peso) + (6.25 * altura_cm) - (5 * idade) - 161;
  }

  /**
   * Katch-McArdle (1983) — usa massa magra (lean body mass)
   * @param {number} massa_magra_kg - massa magra em kg (peso * (1 - %gordura/100))
   * @returns {number} TMB em kcal/dia
   */
  function calcGEB_KatchMcArdle(massa_magra_kg) {
    return 370 + (21.6 * massa_magra_kg);
  }

  /**
   * Cunningham (1980) — para atletas, baseada em massa magra
   * @param {number} massa_magra_kg - massa magra em kg
   * @returns {number} TMB em kcal/dia
   */
  function calcGEB_Cunningham(massa_magra_kg) {
    return 500 + (22 * massa_magra_kg);
  }

  /**
   * FAO/OMS (WHO) — equações por faixa etária
   * @param {number} peso - kg
   * @param {number} idade - anos
   * @param {string} genero - 'M' ou 'F'
   * @returns {number} TMB em kcal/dia
   */
  function calcGEB_FAO(peso, idade, genero) {
    if (genero === 'M') {
      if (idade < 3) return (60.9 * peso) - 54;
      if (idade < 10) return (22.7 * peso) + 495;
      if (idade < 18) return (17.5 * peso) + 651;
      if (idade < 30) return (15.3 * peso) + 679;
      if (idade < 60) return (11.6 * peso) + 879;
      return (13.5 * peso) + 487;
    }
    // Feminino
    if (idade < 3) return (61.0 * peso) - 51;
    if (idade < 10) return (22.5 * peso) + 499;
    if (idade < 18) return (12.2 * peso) + 746;
    if (idade < 30) return (14.7 * peso) + 496;
    if (idade < 60) return (8.7 * peso) + 829;
    return (10.5 * peso) + 596;
  }

  /**
   * Schofield (1985) — equações por faixa etária (usa peso e altura)
   * @param {number} peso - kg
   * @param {number} altura_cm - cm
   * @param {number} idade - anos
   * @param {string} genero - 'M' ou 'F'
   * @returns {number} TMB em kcal/dia
   */
  function calcGEB_Schofield(peso, altura_cm, idade, genero) {
    const altura_m = altura_cm / 100;
    if (genero === 'M') {
      if (idade < 3) return (0.167 * peso) + (1517.4 * altura_m) - 617.6;
      if (idade < 10) return (19.59 * peso) + (130.3 * altura_m) + 414.9;
      if (idade < 18) return (16.25 * peso) + (137.2 * altura_m) + 515.5;
      if (idade < 30) return (15.057 * peso) + (692.2 * altura_m) - 568.2;
      if (idade < 60) return (11.472 * peso) + (873.1 * altura_m) - 517.6;
      return (11.711 * peso) + (587.7 * altura_m) - 284.8;
    }
    // Feminino
    if (idade < 3) return (16.252 * peso) + (1023.2 * altura_m) - 413.5;
    if (idade < 10) return (16.969 * peso) + (161.8 * altura_m) + 371.2;
    if (idade < 18) return (8.365 * peso) + (465.6 * altura_m) - 200.0;
    if (idade < 30) return (13.623 * peso) + (283.0 * altura_m) - 98.2;
    if (idade < 60) return (8.126 * peso) + (884.7 * altura_m) - 829.3;
    return (9.082 * peso) + (658.5 * altura_m) - 297.5;
  }

  /**
   * Calcula GEB com opções avançadas (fórmula, composição corporal, população especial)
   * @param {object} params
   * @param {number} params.peso - kg
   * @param {number} params.altura_cm - cm
   * @param {number} params.idade - anos
   * @param {string} params.genero - 'M' ou 'F'
   * @param {string} [params.formula] - 'harris_benedict'|'mifflin'|'katch_mcardle'|'cunningham'|'fao'|'schofield'|'auto'
   * @param {number} [params.gordura_pct] - % de gordura corporal (para Katch-McArdle/Cunningham)
   * @param {number} [params.massa_magra] - massa magra em kg (alternativa a gordura_pct)
   * @param {string} [params.populacao] - 'gestante_2tri'|'gestante_3tri'|'lactante'|'idoso'|'crianca'
   * @param {string} [params.trimestre] - '1'|'2'|'3' (se populacao='gestante')
   * @returns {object} {geb, formula_usada, ajuste_populacao, geb_ajustado}
   */
  function calcGEB_Avancado(params) {
    const { peso, altura_cm, idade, genero, formula, gordura_pct, massa_magra, populacao } = params;

    // Determinar massa magra se necessário
    let lbm = massa_magra;
    if (!lbm && gordura_pct != null) {
      lbm = peso * (1 - gordura_pct / 100);
    }

    // Auto-select formula baseado na população/dados disponíveis
    let formulaUsada = formula || 'auto';
    if (formulaUsada === 'auto') {
      if (populacao === 'crianca' || idade < 18) {
        formulaUsada = 'fao';
      } else if (populacao === 'idoso' || idade >= 65) {
        formulaUsada = 'mifflin';
      } else if (lbm) {
        formulaUsada = 'katch_mcardle';
      } else {
        formulaUsada = 'mifflin';
      }
    }

    // Calcular GEB com a fórmula selecionada
    let geb;
    switch (formulaUsada) {
      case 'harris_benedict':
        geb = calcGEB(peso, altura_cm, idade, genero);
        break;
      case 'mifflin':
        geb = calcGEB_MifflinStJeor(peso, altura_cm, idade, genero);
        break;
      case 'katch_mcardle':
        if (!lbm) throw new Error('Katch-McArdle requer massa_magra ou gordura_pct');
        geb = calcGEB_KatchMcArdle(lbm);
        break;
      case 'cunningham':
        if (!lbm) throw new Error('Cunningham requer massa_magra ou gordura_pct');
        geb = calcGEB_Cunningham(lbm);
        break;
      case 'fao':
        geb = calcGEB_FAO(peso, idade, genero);
        break;
      case 'schofield':
        geb = calcGEB_Schofield(peso, altura_cm, idade, genero);
        break;
      default:
        geb = calcGEB_MifflinStJeor(peso, altura_cm, idade, genero);
        formulaUsada = 'mifflin';
    }

    // Ajustes para populações especiais
    let ajuste = 0;
    let ajusteDesc = null;
    if (populacao === 'gestante_2tri') {
      ajuste = 300;
      ajusteDesc = 'Gestante 2o trimestre (+300 kcal)';
    } else if (populacao === 'gestante_3tri') {
      ajuste = 500;
      ajusteDesc = 'Gestante 3o trimestre (+500 kcal)';
    } else if (populacao === 'lactante') {
      ajuste = 500;
      ajusteDesc = 'Lactante (+500 kcal)';
    }

    return {
      geb: Math.round(geb * 10) / 10,
      formula_usada: formulaUsada,
      ajuste_populacao: ajusteDesc,
      ajuste_kcal: ajuste,
      geb_ajustado: Math.round((geb + ajuste) * 10) / 10,
      massa_magra_kg: lbm ? Math.round(lbm * 10) / 10 : null,
    };
  }

  // ─── GET (Gasto Energético Total) ─────────────────────────────────────

  function calcGET(geb, fatorAtividade) {
    return Math.round(geb * fatorAtividade);
  }

  /**
   * Calcula GET com TEF (efeito térmico dos alimentos)
   * @param {number} geb - gasto energético basal em kcal
   * @param {number} fatorAtividade - fator multiplicador
   * @param {object} [opcoes]
   * @param {boolean} [opcoes.incluir_tef=true] - incluir efeito térmico (~10% do GET)
   * @param {number} [opcoes.tef_pct=10] - percentual do TEF (padrão 10%)
   * @returns {object} {get_sem_tef, tef_kcal, get_total}
   */
  function calcGET_Completo(geb, fatorAtividade, opcoes = {}) {
    const incluirTef = opcoes.incluir_tef !== false;
    const tefPct = opcoes.tef_pct || 10;

    const getSemTef = Math.round(geb * fatorAtividade);
    const tefKcal = incluirTef ? Math.round(getSemTef * tefPct / 100) : 0;
    const getTotal = getSemTef + tefKcal;

    return {
      get_sem_tef: getSemTef,
      tef_kcal: tefKcal,
      tef_pct: tefPct,
      get_total: getTotal,
    };
  }

  // ─── FATORES DE ATIVIDADE (expandido) ──────────────────────────────────

  const FATORES_ATIVIDADE = [
    { id: 'sedentario', nome: 'Sedentário', fator: 1.2, desc: 'Pouco ou nenhum exercício' },
    { id: 'leve', nome: 'Levemente ativo', fator: 1.375, desc: '1-3 dias/semana' },
    { id: 'moderado', nome: 'Moderadamente ativo', fator: 1.55, desc: '3-5 dias/semana' },
    { id: 'ativo', nome: 'Muito ativo', fator: 1.725, desc: '6-7 dias/semana' },
    { id: 'muito_ativo', nome: 'Extremamente ativo', fator: 1.9, desc: '2x/dia ou trabalho físico' },
    { id: 'atleta_competitivo', nome: 'Atleta competitivo', fator: 2.2, desc: 'Treino intenso 2x/dia, competição (2.0-2.4)', fator_min: 2.0, fator_max: 2.4 },
    { id: 'trabalho_pesado_treino', nome: 'Trabalho pesado + treino', fator: 2.2, desc: 'Trabalho braçal intenso + treinamento adicional' },
  ];

  // ─── PROTEÍNA POR OBJETIVO ─────────────────────────────────────────────

  /**
   * Calcula recomendação de proteína por objetivo e população
   * @param {number} peso - kg
   * @param {string} objetivo - 'sedentario'|'manutencao'|'hipertrofia'|'cutting'|'endurance'|'idoso'|'gestante'
   * @returns {object} {min_g, max_g, g_per_kg_min, g_per_kg_max, objetivo, descricao}
   */
  function calcProteinTarget(peso, objetivo) {
    const recomendacoes = {
      sedentario: { min: 0.8, max: 1.0, desc: 'Mínimo para manutenção da saúde' },
      manutencao: { min: 1.2, max: 1.6, desc: 'Manutenção de massa com atividade física regular' },
      hipertrofia: { min: 1.6, max: 2.2, desc: 'Ganho de massa muscular com treino resistido' },
      cutting: { min: 1.8, max: 2.4, desc: 'Preservação muscular em déficit calórico' },
      endurance: { min: 1.2, max: 1.6, desc: 'Atletas de resistência (corrida, ciclismo, natação)' },
      idoso: { min: 1.2, max: 1.5, desc: 'Prevenção de sarcopenia em idosos (>65 anos)' },
      gestante: { min: 1.1, max: 1.5, desc: 'Suporte ao desenvolvimento fetal e tecidos maternos' },
    };

    const rec = recomendacoes[objetivo] || recomendacoes.manutencao;

    return {
      min_g: Math.round(peso * rec.min),
      max_g: Math.round(peso * rec.max),
      g_per_kg_min: rec.min,
      g_per_kg_max: rec.max,
      objetivo: objetivo,
      descricao: rec.desc,
    };
  }

  // ─── INGESTÃO HÍDRICA ──────────────────────────────────────────────────

  /**
   * Calcula estimativa de ingestão hídrica diária
   * @param {number} peso - kg
   * @param {string} [atividade='moderado'] - nível de atividade (id do FATORES_ATIVIDADE)
   * @param {string} [clima='temperado'] - 'frio'|'temperado'|'quente'|'muito_quente'
   * @returns {object} {ml_dia, ml_por_kg, litros_dia, recomendacao}
   */
  function calcWaterIntake(peso, atividade, clima) {
    atividade = atividade || 'moderado';
    clima = clima || 'temperado';

    // Base: 35ml/kg para adultos
    let mlPorKg = 35;

    // Ajuste por atividade
    const ajustesAtividade = {
      sedentario: 0,
      leve: 3,
      moderado: 5,
      ativo: 8,
      muito_ativo: 10,
      atleta_competitivo: 15,
      trabalho_pesado_treino: 12,
    };
    mlPorKg += (ajustesAtividade[atividade] || 5);

    // Ajuste por clima
    const ajustesClima = {
      frio: -3,
      temperado: 0,
      quente: 5,
      muito_quente: 10,
    };
    mlPorKg += (ajustesClima[clima] || 0);

    const mlDia = Math.round(peso * mlPorKg);
    const litrosDia = Math.round(mlDia / 100) / 10; // 1 casa decimal

    return {
      ml_dia: mlDia,
      ml_por_kg: mlPorKg,
      litros_dia: litrosDia,
      recomendacao: `Aproximadamente ${litrosDia}L por dia (${Math.round(mlDia / 250)} copos de 250ml)`,
    };
  }

  // ─── IMC ───────────────────────────────────────────────────────────────

  /**
   * Calcula IMC e classificação segundo OMS
   * @param {number} peso - kg
   * @param {number} altura_cm - cm
   * @returns {object} {imc, classificacao, faixa, risco}
   */
  function calcIMC(peso, altura_cm) {
    const alturaM = altura_cm / 100;
    const imc = peso / (alturaM * alturaM);
    const imcRound = Math.round(imc * 10) / 10;

    let classificacao, faixa, risco;

    if (imc < 16) {
      classificacao = 'Magreza grau III';
      faixa = 'abaixo';
      risco = 'muito elevado';
    } else if (imc < 17) {
      classificacao = 'Magreza grau II';
      faixa = 'abaixo';
      risco = 'elevado';
    } else if (imc < 18.5) {
      classificacao = 'Magreza grau I';
      faixa = 'abaixo';
      risco = 'moderado';
    } else if (imc < 25) {
      classificacao = 'Eutrofia';
      faixa = 'normal';
      risco = 'normal';
    } else if (imc < 30) {
      classificacao = 'Sobrepeso';
      faixa = 'acima';
      risco = 'moderado';
    } else if (imc < 35) {
      classificacao = 'Obesidade grau I';
      faixa = 'acima';
      risco = 'elevado';
    } else if (imc < 40) {
      classificacao = 'Obesidade grau II';
      faixa = 'acima';
      risco = 'muito elevado';
    } else {
      classificacao = 'Obesidade grau III';
      faixa = 'acima';
      risco = 'gravissimo';
    }

    return {
      imc: imcRound,
      classificacao,
      faixa,
      risco,
    };
  }

  // ─── DISTRIBUIÇÃO DE MACROS POR META ───────────────────────────────────

  /**
   * Calcula distribuição de macronutrientes por meta/objetivo
   * @param {number} get - gasto energético total em kcal
   * @param {string} objetivo - 'manutencao'|'cutting_leve'|'cutting_agressivo'|'bulking_limpo'|'bulking_agressivo'|'endurance'|'cetogenica'
   * @returns {object} {kcal, proteina_g, carboidrato_g, gordura_g, proteina_pct, carb_pct, gord_pct, descricao}
   */
  function calcMacrosByGoal(get, objetivo) {
    const metas = {
      manutencao: {
        kcal_ajuste: 1.0,
        prot_pct: 25,
        carb_pct: 50,
        gord_pct: 25,
        desc: 'Manutenção do peso e composição corporal',
      },
      cutting_leve: {
        kcal_ajuste: 0.85, // -15%
        prot_pct: 30,
        carb_pct: 40,
        gord_pct: 30,
        desc: 'Déficit calórico leve (-15%) com preservação muscular',
      },
      cutting_agressivo: {
        kcal_ajuste: 0.75, // -25%
        prot_pct: 35,
        carb_pct: 35,
        gord_pct: 30,
        desc: 'Déficit calórico agressivo (-25%) com alta proteína',
      },
      bulking_limpo: {
        kcal_ajuste: 1.10, // +10%
        prot_pct: 25,
        carb_pct: 50,
        gord_pct: 25,
        desc: 'Superávit controlado (+10%) para ganho de massa limpa',
      },
      bulking_agressivo: {
        kcal_ajuste: 1.20, // +20%
        prot_pct: 22,
        carb_pct: 55,
        gord_pct: 23,
        desc: 'Superávit agressivo (+20%) para ganho de massa rápido',
      },
      endurance: {
        kcal_ajuste: 1.0,
        prot_pct: 20,
        carb_pct: 55,
        gord_pct: 25,
        desc: 'Alta carga de carboidrato para desempenho em resistência',
      },
      cetogenica: {
        kcal_ajuste: 1.0,
        prot_pct: 20,
        carb_pct: 5,
        gord_pct: 75,
        desc: 'Dieta cetogênica (<5% carboidrato, alta gordura)',
      },
    };

    const meta = metas[objetivo] || metas.manutencao;
    const kcalFinal = Math.round(get * meta.kcal_ajuste);

    const proteinaG = Math.round((kcalFinal * meta.prot_pct / 100) / 4);
    const carboidratoG = Math.round((kcalFinal * meta.carb_pct / 100) / 4);
    const gorduraG = Math.round((kcalFinal * meta.gord_pct / 100) / 9);

    return {
      kcal: kcalFinal,
      proteina_g: proteinaG,
      carboidrato_g: carboidratoG,
      gordura_g: gorduraG,
      proteina_pct: meta.prot_pct,
      carb_pct: meta.carb_pct,
      gord_pct: meta.gord_pct,
      objetivo: objetivo,
      descricao: meta.desc,
    };
  }

  // ─── MEUS ALIMENTOS (custom foods) ──────────────────────────────────────
  // Vive no servidor (intus_alimento_personalizado), carregado uma vez em
  // load() e mantido em _customFoods daqui pra frente — as três funções
  // abaixo confirmam no servidor antes de mexer nesse cache local, então
  // getCustomFoods()/buscar()/getById() continuam síncronas normalmente.

  function getCustomFoods() {
    return _customFoods;
  }

  async function addCustomFood(food) {
    const salvo = await API.criarAlimentoPersonalizado({
      description: food.description || 'Alimento personalizado',
      category: food.category || 'Meus Alimentos',
      energy_kcal: food.energy_kcal || 0,
      protein_g: food.protein_g || 0,
      carbohydrate_g: food.carbohydrate_g || 0,
      lipid_g: food.lipid_g || 0,
      fiber_g: food.fiber_g || 0,
      sodium_mg: food.sodium_mg || 0,
      calcium_mg: food.calcium_mg || 0,
      iron_mg: food.iron_mg || 0,
      potassium_mg: food.potassium_mg || 0,
      magnesium_mg: food.magnesium_mg || 0,
      phosphorus_mg: food.phosphorus_mg || 0,
      zinc_mg: food.zinc_mg || 0,
      vitaminC_mg: food.vitaminC_mg || 0,
      cholesterol_mg: food.cholesterol_mg || 0,
      saturated_g: food.saturated_g || 0,
      monounsaturated_g: food.monounsaturated_g || 0,
      polyunsaturated_g: food.polyunsaturated_g || 0,
      thiamine_mg: food.thiamine_mg || 0,
      riboflavin_mg: food.riboflavin_mg || 0,
      niacin_mg: food.niacin_mg || 0,
      porcao_padrao: food.porcao_padrao || 100,
      medida_padrao: food.medida_padrao || 'g',
    });
    const novo = _prepCustom(salvo);
    _customFoods.push(novo);
    return novo;
  }

  async function editCustomFood(id, updates) {
    const nid = _nsId(id);
    await API.editarAlimentoPersonalizado(_dbidDe(nid), updates);
    const idx = _customFoods.findIndex(f => f.id === nid);
    if (idx < 0) return null;
    Object.assign(_customFoods[idx], updates);
    return _customFoods[idx];
  }

  async function deleteCustomFood(id) {
    const nid = _nsId(id);
    await API.excluirAlimentoPersonalizado(_dbidDe(nid));
    _customFoods = _customFoods.filter(f => f.id !== nid);
  }

  // ─── UTILIDADES ────────────────────────────────────────────────────────

  function isLoaded() { return _loaded; }
  function totalAlimentos() { return _taco.length + _extra.length + _customFoods.length; }

  // ─── MÓDULO EXPORTADO ──────────────────────────────────────────────────

  return {
    // Dados e busca
    load,
    isLoaded,
    totalAlimentos,
    buscar,
    buscarOnline,
    getById,
    EXTRA_BASE,
    resolver,
    getCategories,
    getByCategory,
    calcNutrientes,
    nutrientesPor100,
    escalar,
    getMedidasAlimento,
    getUnidadePadrao,
    CUSTOM_BASE,
    getMedidasPadrao,
    getGrupos,
    getGrupo,
    getSugestaoSubstituicao,
    calcDistribuicaoMacros,
    parseNumeric,

    // Meus Alimentos (custom)
    getCustomFoods,
    addCustomFood,
    editCustomFood,
    deleteCustomFood,

    // GEB/TMB — fórmulas individuais
    calcGEB,
    calcGEB_MifflinStJeor,
    calcGEB_KatchMcArdle,
    calcGEB_Cunningham,
    calcGEB_FAO,
    calcGEB_Schofield,

    // GEB avançado (seleção automática + populações especiais)
    calcGEB_Avancado,

    // GET (gasto energético total)
    calcGET,
    calcGET_Completo,

    // Fatores de atividade
    FATORES_ATIVIDADE,

    // Proteína por objetivo
    calcProteinTarget,

    // Ingestão hídrica
    calcWaterIntake,

    // IMC
    calcIMC,

    // Macros por meta
    calcMacrosByGoal,
  };
})();
