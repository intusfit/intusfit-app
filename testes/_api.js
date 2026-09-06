// Carrega o api.js REAL num sandbox, com o mínimo de browser que ele precisa.
const fs = require('fs'), vm = require('vm'), path = require('path');
const ARQ = (process.env.INTUS_DIR || '.') + '/api.js';

// Congela "hoje" dentro do sandbox. Sem isso, um teste de cobrança que passa
// numa terça falha na quarta, e teste que muda de resposta sozinho não vale nada.
function _dataCongelada(iso) {
  const fixo = new Date(iso + 'T12:00:00');
  class DataFixa extends Date {
    constructor(...a) { if (a.length === 0) super(fixo.getTime()); else super(...a); }
    static now() { return fixo.getTime(); }
  }
  return DataFixa;
}

function carregar(opcoes) {
  // Aceita carregar({ranking, hoje}) e a forma antiga carregar(configRanking).
  opcoes = opcoes || {};
  const usaNovo = ('ranking' in opcoes || 'hoje' in opcoes);
  const configRanking = usaNovo ? opcoes.ranking : opcoes;
  const hoje = usaNovo ? (opcoes.hoje || null) : null;
  const store = {};
  if (configRanking) store['intus-ranking-regras'] = JSON.stringify(configRanking);
  const localStorage = {
    getItem: (k) => (k in store ? store[k] : null),
    setItem: (k, v) => { store[k] = String(v); },
    removeItem: (k) => { delete store[k]; },
  };
  const noop = () => {};
  const sandbox = {
    console, localStorage, setTimeout, clearTimeout, setInterval, clearInterval,
    fetch: () => Promise.reject(new Error('sem rede no teste')),
    location: { href: '', hostname: 'teste', pathname: '/', search: '', reload: noop },
    navigator: { onLine: true, userAgent: 'node' },
    document: {
      addEventListener: noop, removeEventListener: noop, querySelector: () => null,
      querySelectorAll: () => [], getElementById: () => null, createElement: () => ({ style: {}, classList: { add: noop, remove: noop } }),
      body: { appendChild: noop }, cookie: '',
    },
    WeakMap, Map, Set, JSON, Math, Date: hoje ? _dataCongelada(hoje) : Date, Number, String, Object, Array, isNaN, parseFloat, parseInt, isFinite,
    Promise, Error, encodeURIComponent, decodeURIComponent, btoa: (x) => Buffer.from(x).toString('base64'),
    atob: (x) => Buffer.from(x, 'base64').toString(),
  };
  sandbox.addEventListener = noop; sandbox.removeEventListener = noop;
  sandbox.window = sandbox;
  sandbox.self = sandbox;
  sandbox.globalThis = sandbox;
  vm.createContext(sandbox);
  vm.runInContext(fs.readFileSync(ARQ, 'utf8'), sandbox, { filename: 'api.js' });
  return sandbox.API || sandbox.window.API;
}
// Forma antiga usada por fonte_unica.js: carregarAPI(ctx, 'YYYY-MM-DD').
function carregarAPI(_ctx, hoje) { return carregar({ hoje: hoje }); }
module.exports = { carregar, carregarAPI };
