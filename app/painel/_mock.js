// ── MOCK DATA ────────────────────────────────────────────────────────────
const MOCK = {
  professor: { nome: 'Luiz Nunes', email: 'contato@intusfit.com.br' },

  dashboard: {
    resumo: { total_atletas: 15, atletas_ativos: 8, mens_vencidas: 3, msgs_nao_lidas: 2 },
    treinos_inativos: [
      { nome: 'Carlos Souza',         dt: '31/05/2025' },
      { nome: 'Ana Lima',             dt: '31/05/2025' },
      { nome: 'Pedro Alves',          dt: '31/05/2025' },
    ],
    mensalidades_vencidas: [
      { nome: 'Cliente Exemplo 1',            dtvenc: '31/05/2025', professor: 'Ana K.' },
      { nome: 'Cliente Exemplo 2',   dtvenc: '31/05/2025', professor: 'Griebe' },
      { nome: 'Cliente Exemplo 3',        dtvenc: '31/05/2025', professor: 'Ana' },
      { nome: 'Cliente Exemplo 4',               dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 5',                 dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 6',            dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 7',                dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 8',   dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 9',  dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 10',                  dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 11',      dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 12',     dtvenc: '31/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 13',               dtvenc: '30/05/2025', professor: '' },
      { nome: 'Cliente Exemplo 14',          dtvenc: '29/05/2025', professor: '' },
    ],
  },

  atletas: [
    { idatleta: 1, nome: 'Carlos Souza',  email: 'carlos@email.com',  genero: 'M', stbloqueio: 'N', codacesso: 'CAR001', dtcadastro: '2026-04-01' },
    { idatleta: 2, nome: 'Ana Lima',      email: 'ana@email.com',     genero: 'F', stbloqueio: 'N', codacesso: 'ANA002', dtcadastro: '2026-03-15' },
    { idatleta: 3, nome: 'Pedro Alves',   email: 'pedro@email.com',   genero: 'M', stbloqueio: 'N', codacesso: 'PED003', dtcadastro: '2026-03-10' },
    { idatleta: 4, nome: 'Julia Costa',   email: 'julia@email.com',   genero: 'F', stbloqueio: 'N', codacesso: 'JUL004', dtcadastro: '2026-02-20' },
    { idatleta: 5, nome: 'Marcos Faria',  email: 'marcos@email.com',  genero: 'M', stbloqueio: 'S', codacesso: 'MAR005', dtcadastro: '2026-02-05' },
    { idatleta: 6, nome: 'Fernanda Reis', email: 'fer@email.com',     genero: 'F', stbloqueio: 'N', codacesso: 'FER006', dtcadastro: '2026-01-15' },
  ],

  treinos: {
    'A': [
      { idtreino: 1, nmexercicio: 'Supino Reto',         qtdserie: 4, qtdrepeticao: 12, tempopausa: 60, observacao: 'Controle a descida' },
      { idtreino: 2, nmexercicio: 'Crucifixo Inclinado',  qtdserie: 3, qtdrepeticao: 15, tempopausa: 45, observacao: '' },
      { idtreino: 3, nmexercicio: 'Tríceps Pulley',       qtdserie: 3, qtdrepeticao: 15, tempopausa: 45, observacao: 'Cotovelo fixo' },
    ],
    'B': [
      { idtreino: 4, nmexercicio: 'Agachamento Livre',   qtdserie: 4, qtdrepeticao: 10, tempopausa: 90, observacao: 'Descer até 90°' },
      { idtreino: 5, nmexercicio: 'Leg Press 45°',       qtdserie: 3, qtdrepeticao: 12, tempopausa: 60, observacao: '' },
      { idtreino: 6, nmexercicio: 'Cadeira Extensora',   qtdserie: 3, qtdrepeticao: 15, tempopausa: 45, observacao: '' },
    ],
    'C': [
      { idtreino: 7, nmexercicio: 'Puxada Frontal',      qtdserie: 4, qtdrepeticao: 12, tempopausa: 60, observacao: 'Peito no banco' },
      { idtreino: 8, nmexercicio: 'Remada Curvada',      qtdserie: 3, qtdrepeticao: 12, tempopausa: 60, observacao: '' },
      { idtreino: 9, nmexercicio: 'Rosca Direta',        qtdserie: 3, qtdrepeticao: 12, tempopausa: 45, observacao: '' },
    ],
  },

  mensalidades: [],

  mensagens: [
    { idmensagem: 1, idatleta: 1, idprofessor: null, dsmensagem: 'Professor, posso treinar com dor muscular?', dtmensagem: '2026-04-17T10:30:00', stlido: 'S' },
    { idmensagem: 2, idatleta: 1, idprofessor: 1,    dsmensagem: 'Sim! Dor muscular tardia é normal. Pode treinar normalmente.', dtmensagem: '2026-04-17T11:00:00', stlido: 'S' },
    { idmensagem: 3, idatleta: 1, idprofessor: null, dsmensagem: 'Obrigado! Vou treinar então 💪', dtmensagem: '2026-04-17T11:05:00', stlido: 'N' },
  ],
};

// ── SVG ICONS ─────────────────────────────────────────────────────────────
const ICONS = {
  home:     `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>`,
  users:    `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>`,
  shield:   `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>`,
  workout:  `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"/></svg>`,
  chart:    `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>`,
  money:    `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>`,
  chat:     `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>`,
  dumbbell: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 10.5v3M3 11.25v1.5M17.25 10.5v3M21 11.25v1.5M10 8v8M14 8v8M10 12h4"/></svg>`,
  stretch:  `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16M4 8l8 4 8-4M4 16l8-4 8 4"/></svg>`,
  food:     `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5c1.38 0 2.5 1.12 2.5 2.5 0 .98-.56 1.83-1.38 2.24-.53.27-.87.82-.87 1.41V14m0 2h.01M18 8a6 6 0 01-6 6 6 6 0 01-6-6c0-3.31 2.69-6 6-6s6 2.69 6 6zM9 18h6m-5 2h4"/></svg>`,
  crown:    `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l4 4 5-6 5 6 4-4v11H3V7zM3 20h18"/></svg>`,
  frequency:`<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/></svg>`,
  bell:     `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>`,
  trophy:   `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 21h8m-4-4v4m-4-8a4 4 0 014-4 4 4 0 014 4m-8 0H5a2 2 0 01-2-2V5h4m10 8h3a2 2 0 002-2V5h-4M7 5h10V3H7v2z"/></svg>`,
  gear:     `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.573-1.066z"/><circle cx="12" cy="12" r="3"/></svg>`,
  logout:   `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>`,
};

// ── LOGO INTUS ────────────────────────────────────────────────────────────
// Usa as imagens PNG oficiais — mesmas do app do aluno.
// Troca automaticamente entre dark/light conforme tema ativo.
function _isLightTheme() {
  return document.body.classList.contains('theme-light');
}
const INTUS_LOGO_SVG = `<img class="intus-logo-icon" src="logo-icon-dark.png" alt="Intus" style="width:38px;height:38px;object-fit:contain;"/>`;
const INTUS_LOGO_FULL_SVG = `<img class="intus-logo-full" src="logo-intus-dark.png" alt="Intus" style="height:32px;object-fit:contain;"/>`;

// ── PERMISSÕES & USUÁRIOS ────────────────────────────────────────────────
// Store local (localStorage) para usuários/professores com controle de acessos.
// Integra com as páginas do painel — uma alteração aqui reflete em todas.
const PERM_KEYS = [
  // Clientes
  { key: 'at_ver',      label: 'Ver clientes',         grupo: 'Clientes'     },
  { key: 'at_incluir',  label: 'Incluir clientes',     grupo: 'Clientes'     },
  { key: 'at_editar',   label: 'Editar clientes',      grupo: 'Clientes'     },
  { key: 'at_excluir',  label: 'Excluir clientes',     grupo: 'Clientes'     },
  // Treinos
  { key: 'tr_ver',      label: 'Ver treinos',          grupo: 'Treinos'      },
  { key: 'tr_incluir',  label: 'Incluir treinos',      grupo: 'Treinos'      },
  { key: 'tr_editar',   label: 'Editar treinos',       grupo: 'Treinos'      },
  { key: 'tr_excluir',  label: 'Excluir treinos',      grupo: 'Treinos'      },
  // Exercícios
  { key: 'ex_ver',      label: 'Ver exercícios',       grupo: 'Exercícios'   },
  { key: 'ex_incluir',  label: 'Incluir exercícios',   grupo: 'Exercícios'   },
  { key: 'ex_editar',   label: 'Editar exercícios',    grupo: 'Exercícios'   },
  { key: 'ex_excluir',  label: 'Excluir exercícios',   grupo: 'Exercícios'   },
  // Alongamentos
  { key: 'al_ver',      label: 'Ver alongamentos',     grupo: 'Alongamentos' },
  { key: 'al_incluir',  label: 'Incluir alongamentos', grupo: 'Alongamentos' },
  { key: 'al_editar',   label: 'Editar alongamentos',  grupo: 'Alongamentos' },
  { key: 'al_excluir',  label: 'Excluir alongamentos', grupo: 'Alongamentos' },
  // Avaliações
  { key: 'av_ver',      label: 'Ver avaliações',       grupo: 'Avaliações'   },
  { key: 'av_incluir',  label: 'Incluir avaliações',   grupo: 'Avaliações'   },
  { key: 'av_editar',   label: 'Editar avaliações',    grupo: 'Avaliações'   },
  { key: 'av_excluir',  label: 'Excluir avaliações',   grupo: 'Avaliações'   },
  // Nutrição
  { key: 'nt_ver',      label: 'Ver nutrição',          grupo: 'Nutrição'     },
  { key: 'nt_incluir',  label: 'Incluir plano nutri',   grupo: 'Nutrição'     },
  { key: 'nt_editar',   label: 'Editar plano nutri',    grupo: 'Nutrição'     },
  { key: 'nt_excluir',  label: 'Excluir plano nutri',   grupo: 'Nutrição'     },
  // Matrículas / Pagamentos
  { key: 'mt_ver',      label: 'Ver matrículas',       grupo: 'Matrículas'   },
  { key: 'mt_incluir',  label: 'Incluir matrículas',   grupo: 'Matrículas'   },
  { key: 'mt_editar',   label: 'Editar matrículas',    grupo: 'Matrículas'   },
  { key: 'mt_excluir',  label: 'Excluir matrículas',   grupo: 'Matrículas'   },
  // Pagamentos
  { key: 'pg_ver',      label: 'Ver pagamentos',       grupo: 'Pagamentos'   },
  { key: 'pg_incluir',  label: 'Incluir pagamentos',   grupo: 'Pagamentos'   },
  { key: 'pg_editar',   label: 'Editar pagamentos',    grupo: 'Pagamentos'   },
  { key: 'pg_excluir',  label: 'Excluir pagamentos',   grupo: 'Pagamentos'   },
  // Mensagens
  { key: 'ms_ver',      label: 'Ver mensagens',        grupo: 'Mensagens'    },
  { key: 'ms_incluir',  label: 'Enviar mensagens',     grupo: 'Mensagens'    },
  { key: 'ms_editar',   label: 'Editar mensagens',     grupo: 'Mensagens'    },
  { key: 'ms_excluir',  label: 'Excluir mensagens',    grupo: 'Mensagens'    },
  // Financeiro
  { key: 'fin_ver',     label: 'Ver financeiro',       grupo: 'Financeiro'   },
  { key: 'fin_caixa',   label: 'Lançar entradas/saídas', grupo: 'Financeiro'},
  // RESTRITA de proposito: nao entra no pacote automatico do administrador. O
  // caixa da empresa inteira e outra coisa que "ver o financeiro dos meus
  // alunos" — quando outros professores tiverem acesso, cada um vera so os
  // numeros dos clientes dele, e essa separacao precisa comecar fechada.
  { key: 'fin_empresa', label: 'Ver o caixa da empresa', grupo: 'Financeiro', restrita: true },
];

function _permsAllTrue() {
  const o = {};
  // Chave restrita nao entra: se entrasse, todo admin ganharia o caixa da
  // empresa no ato do cadastro e a permissao nao restringiria nada.
  PERM_KEYS.forEach(p => { if (!p.restrita) o[p.key] = true; });
  return o;
}
function _permsAllFalse() {
  const o = {};
  PERM_KEYS.forEach(p => o[p.key] = false);
  return o;
}

// Usuários padrão — semeados na primeira execução
const DEFAULT_USUARIOS = [
  {
    idusuario: 1,
    nome: 'Luiz Nunes Admin',
    email: 'contato@intusfit.com.br',
    senha: 'Senhadoluiz99.9',
    admin: true,                 // conta mestra — bypass de qualquer checagem
    ativo: true,
    permissoes: _permsAllTrue(),
    dtcadastro: '2026-04-18',
  },
  {
    idusuario: 2,
    nome: 'Luiz Nunes',
    email: 'luiz.nunes@intus.com.br',
    senha: 'intus2026',
    admin: false,
    ativo: true,
    permissoes: _permsAllTrue(),   // professor com todos os acessos (mas não é mestre)
    dtcadastro: '2026-04-18',
  },
  {
    idusuario: 3,
    nome: 'Cliente Exemplo 14',
    email: 'ricardo.schmoeller@intus.com.br',
    senha: 'intus2026',
    admin: false,
    ativo: true,
    permissoes: {
      ex_incluir: true,  ex_editar: true,  ex_excluir: false,
      al_incluir: true,  al_editar: true,  al_excluir: false,
      tr_incluir: true,  tr_editar: true,  tr_excluir: false,
      at_incluir: true,  at_editar: true,
      mt_gerir: false,   av_gerir: true,
    },
    dtcadastro: '2026-04-18',
  },
  {
    idusuario: 4,
    nome: 'Vanessa Massoni',
    email: 'vanessa.massoni@intus.com.br',
    senha: 'intus2026',
    admin: false,
    ativo: true,
    permissoes: {
      ex_incluir: true,  ex_editar: true,  ex_excluir: false,
      al_incluir: true,  al_editar: true,  al_excluir: false,
      tr_incluir: true,  tr_editar: true,  tr_excluir: false,
      at_incluir: true,  at_editar: true,
      mt_gerir: false,   av_gerir: true,
    },
    dtcadastro: '2026-04-18',
  },
];

const Usuarios = {
  KEY: 'intus-usuarios',

  _load() {
    try {
      const raw = localStorage.getItem(this.KEY);
      if (!raw) { this._save(DEFAULT_USUARIOS); return DEFAULT_USUARIOS.slice(); }
      const arr = JSON.parse(raw);
      return Array.isArray(arr) && arr.length ? arr : (this._save(DEFAULT_USUARIOS), DEFAULT_USUARIOS.slice());
    } catch {
      this._save(DEFAULT_USUARIOS);
      return DEFAULT_USUARIOS.slice();
    }
  },

  _save(lista) {
    localStorage.setItem(this.KEY, JSON.stringify(lista));
    // notifica outras abas abertas
    try { window.dispatchEvent(new Event('intus-usuarios-change')); } catch {}
  },

  listar() { return this._load(); },

  get(id) { return this._load().find(u => Number(u.idusuario) === Number(id)) || null; },

  findByEmail(email) {
    const e = String(email||'').trim().toLowerCase();
    return this._load().find(u => String(u.email||'').trim().toLowerCase() === e) || null;
  },

  validarLogin(email, senha) {
    const u = this.findByEmail(email);
    if (!u) return null;
    if (u.senha !== senha) return null;
    if (!u.ativo) return { erro: 'Usuário desativado. Procure o administrador.' };
    return u;
  },

  criar(dados) {
    const lista = this._load();
    if (lista.some(u => String(u.email||'').toLowerCase() === String(dados.email||'').toLowerCase())) {
      throw new Error('Já existe um usuário com esse e-mail.');
    }
    const nextId = (lista.reduce((m,u)=>Math.max(m, u.idusuario||0), 0) + 1) || 1;
    const novo = {
      idusuario: nextId,
      nome:     String(dados.nome||'').trim(),
      email:    String(dados.email||'').trim().toLowerCase(),
      senha:    String(dados.senha||'').trim() || 'intus2026',
      admin:    !!dados.admin,
      ativo:    dados.ativo !== false,
      permissoes: dados.admin ? _permsAllTrue() : Object.assign(_permsAllFalse(), dados.permissoes || {}),
      dtcadastro: new Date().toISOString().slice(0,10),
    };
    if (!novo.nome)  throw new Error('Informe o nome do usuário.');
    if (!novo.email) throw new Error('Informe o e-mail.');
    lista.push(novo);
    this._save(lista);
    return novo;
  },

  editar(id, dados) {
    const lista = this._load();
    const idx = lista.findIndex(u => Number(u.idusuario) === Number(id));
    if (idx < 0) throw new Error('Usuário não encontrado.');
    const atual = lista[idx];

    if (dados.email && dados.email.toLowerCase() !== atual.email.toLowerCase()) {
      if (lista.some(u => u.idusuario !== atual.idusuario && String(u.email||'').toLowerCase() === String(dados.email||'').toLowerCase())) {
        throw new Error('Já existe um usuário com esse e-mail.');
      }
    }

    const novo = Object.assign({}, atual, {
      nome:  dados.nome  != null ? String(dados.nome).trim()  : atual.nome,
      email: dados.email != null ? String(dados.email).trim().toLowerCase() : atual.email,
      senha: dados.senha ? String(dados.senha) : atual.senha,
      admin: dados.admin != null ? !!dados.admin : atual.admin,
      ativo: dados.ativo != null ? !!dados.ativo : atual.ativo,
      permissoes: dados.admin ? _permsAllTrue() : Object.assign(_permsAllFalse(), dados.permissoes || atual.permissoes || {}),
    });

    // a conta mestra (idusuario=1) nunca pode ser desativada ou perder admin
    if (atual.idusuario === 1) {
      novo.admin = true;
      novo.ativo = true;
      novo.permissoes = _permsAllTrue();
    }

    lista[idx] = novo;
    this._save(lista);
    return novo;
  },

  excluir(id) {
    if (Number(id) === 1) throw new Error('A conta mestra não pode ser excluída.');
    const lista = this._load().filter(u => Number(u.idusuario) !== Number(id));
    this._save(lista);
    return true;
  },

  resetarSeed() {
    this._save(DEFAULT_USUARIOS);
  },
};

// Atletas (clientes) — login de aluno + auto-cadastro + reset de senha
// Os atletas vivem no mesmo store que api.js usa (intus-atletas).
const Atletas = {
  KEY: 'intus-atletas',

  _load() {
    try { return JSON.parse(localStorage.getItem(this.KEY) || '[]'); }
    catch { return []; }
  },
  _save(lista) {
    localStorage.setItem(this.KEY, JSON.stringify(lista));
    try { window.dispatchEvent(new Event('intus-atletas-change')); } catch {}
  },

  findByEmail(email) {
    const e = String(email||'').trim().toLowerCase();
    return this._load().find(a => String(a.email||'').trim().toLowerCase() === e) || null;
  },

  validarLogin(email, senha) {
    const a = this.findByEmail(email);
    if (!a) return null;
    if (String(a.senha||'') !== String(senha||'')) return null;
    if (a.stbloqueio === 'S') return { erro: 'Sua conta está bloqueada. Fale com seu professor.' };
    return a;
  },

  criarAutoCadastro(dados) {
    const lista = this._load();
    const e = String(dados.email||'').trim().toLowerCase();
    if (!e) throw new Error('Informe um e-mail válido.');
    if (!dados.senha || dados.senha.length < 4) throw new Error('A senha precisa ter ao menos 4 caracteres.');
    if (lista.some(a => String(a.email||'').toLowerCase() === e)) {
      throw new Error('Já existe uma conta com esse e-mail.');
    }
    // ATENCAO: ids criados aqui existem SO neste aparelho. Antes esta linha fazia
    // (max + 1) || 1 — e num celular sem lista local isso dava SEMPRE idatleta = 1.
    // Resultado: todo cadastro que caia no modo local virava o "atleta 1", e todos
    // eles gravavam anamnese por cima uns dos outros no servidor. Agora usamos uma
    // faixa alta, reservada, que nunca colide com id real do banco.
    const LOCAL_ID_MIN = 900000;
    const nextId = Math.max(lista.reduce((m,a)=>Math.max(m, a.idatleta||0), 0), LOCAL_ID_MIN) + 1;
    const novo = {
      idatleta:     nextId,
      nome:         String(dados.nome||'').trim(),
      email:        e,
      senha:        String(dados.senha||''),
      telefone:     String(dados.telefone||'').trim(),
      genero:       dados.genero || 'M',
      stbloqueio:   'N',
      codacesso:    'APP' + String(nextId).padStart(4,'0'),
      dtcadastro:   new Date().toISOString().slice(0,10),
      dtnascimento: dados.dtnascimento || null,
      observacao:   '',
      professores_responsaveis: Array.isArray(dados.professores_responsaveis) ? dados.professores_responsaveis : [],
      objetivos: Array.isArray(dados.objetivos) ? dados.objetivos : [],
      dificuldades: Array.isArray(dados.dificuldades) ? dados.dificuldades : [],
      // self-signup — ainda precisa ser revisado pelo professor
      origem:       'auto',
    };
    if (!novo.nome) throw new Error('Informe o seu nome.');
    lista.push(novo);
    this._save(lista);
    return novo;
  },

  excluir(id) {
    const lista = this._load();
    const idx = lista.findIndex(a => Number(a.idatleta) === Number(id));
    if (idx < 0) throw new Error('Cliente não encontrado.');
    const nome = lista[idx].nome;
    lista.splice(idx, 1);
    this._save(lista);
    // limpa matrículas/fichas/treinos/avaliações/mensagens vinculadas ao cliente
    try {
      const limparLista = (key, campo) => {
        const arr = JSON.parse(localStorage.getItem(key) || '[]');
        if (!Array.isArray(arr)) return;
        const filtrada = arr.filter(x => Number(x?.[campo]) !== Number(id));
        if (filtrada.length !== arr.length) {
          localStorage.setItem(key, JSON.stringify(filtrada));
        }
      };
      limparLista('intus-mensalidades', 'idatleta');
      limparLista('intus-fichas', 'idatleta');
      limparLista('intus-avaliacoes', 'idatleta');
      // treinos são filhos de ficha — reaproveita fichas restantes
      const fichasRestantes = JSON.parse(localStorage.getItem('intus-fichas') || '[]');
      const idsFichas = new Set((fichasRestantes || []).map(f => Number(f.idficha)));
      const treinos = JSON.parse(localStorage.getItem('intus-treinos') || '[]');
      if (Array.isArray(treinos)) {
        const t2 = treinos.filter(t => idsFichas.has(Number(t.idficha)));
        if (t2.length !== treinos.length) {
          localStorage.setItem('intus-treinos', JSON.stringify(t2));
        }
      }
    } catch {}
    return { ok: true, nome };
  },

  resetarSenha(email, novaSenha) {
    const e = String(email||'').trim().toLowerCase();
    if (!e) throw new Error('Informe um e-mail válido.');
    if (!novaSenha || novaSenha.length < 4) throw new Error('A nova senha precisa ter ao menos 4 caracteres.');
    // 1) tenta atleta
    const lista = this._load();
    const i = lista.findIndex(a => String(a.email||'').toLowerCase() === e);
    if (i >= 0) {
      lista[i].senha = novaSenha;
      this._save(lista);
      return { tipo: 'aluno', nome: lista[i].nome };
    }
    // 2) tenta usuário (professor/admin)
    const usuarios = Usuarios.listar();
    const j = usuarios.findIndex(u => String(u.email||'').toLowerCase() === e);
    if (j >= 0) {
      if (usuarios[j].idusuario === 1) throw new Error('A conta mestra deve trocar a senha via "Editar Usuário" no painel.');
      Usuarios.editar(usuarios[j].idusuario, { senha: novaSenha });
      return { tipo: 'professor', nome: usuarios[j].nome };
    }
    throw new Error('Não encontramos nenhuma conta com esse e-mail.');
  },
};

// Sessão ativa (usuário logado — pode ser professor/admin OU atleta)
const Sessao = {
  atual() {
    try {
      const raw = localStorage.getItem('mx-user');
      if (!raw) return null;
      const u = JSON.parse(raw);
      // reidratação — se o usuário existir no store, recarrega permissões atualizadas
      if (u && u.idusuario) {
        const fresh = Usuarios.get(u.idusuario);
        if (fresh) return fresh;
      }
      return u;
    } catch { return null; }
  },
  set(u) {
    localStorage.setItem('mx-user', JSON.stringify(u));
    localStorage.setItem('mx-token', 'local-' + u.idusuario + '-' + Date.now());
  },
  clear() {
    localStorage.removeItem('mx-user');
    localStorage.removeItem('mx-token');
  },
  isLogged() { return !!localStorage.getItem('mx-token'); },
};

// Helpers de permissão globais
function isAdmin() {
  const u = Sessao.atual();
  return !!(u && u.admin);
}
function hasPerm(key) {
  const u = Sessao.atual();
  if (!u) return false;
  if (u.admin) return true;
  return !!(u.permissoes && u.permissoes[key]);
}
function usuarioAtual() { return Sessao.atual(); }

// Quem ve o caixa da empresa. NAO usa hasPerm de proposito: hasPerm libera tudo
// para quem e admin, e aqui ser admin nao basta. A conta mestra (id 1) entra
// direto; qualquer outro precisa da chave ligada uma a uma em Usuarios.
function podeVerGestao() {
  const u = Sessao.atual();
  if (!u) return false;
  if (Number(u.idusuario) === 1) return true;
  return !!(u.permissoes && u.permissoes.fin_empresa === true);
}

// ── LAYOUT ENGINE ──────────────────────────────────────────────────────────
function renderLayout(activePage) {
  const user = Sessao.atual() || { nome: MOCK.professor.nome, admin: false };
  const nome = user.nome || MOCK.professor.nome;
  const initials = nome.split(' ').map(n=>n[0]).slice(0,2).join('').toUpperCase();
  const role = user.admin ? 'Administrador' : 'Professor';

  const nav = [
    { href: 'index.html',        icon: ICONS.home,     label: 'Início',       show: true },
    { href: 'alunos.html',       icon: ICONS.users,    label: 'Clientes',     show: true },
    { href: 'treinos.html',      icon: ICONS.workout,  label: 'Treinos',      show: true },
    { href: 'exercicios.html',   icon: ICONS.dumbbell, label: 'Exercícios',   show: true },
    { href: 'alongamentos.html', icon: ICONS.stretch,  label: 'Alongamentos', show: true },
    { href: 'avaliacoes.html',   icon: ICONS.chart,    label: 'Avaliações',   show: true },
    { href: 'nutricao.html',     icon: ICONS.food,     label: 'Nutrição',     show: true },
    { href: 'mensalidades.html', icon: ICONS.money,    label: 'Matrículas',   show: true },
    { href: 'frequencia.html',   icon: ICONS.frequency,label: 'Frequência e Ranking', show: true },
    { href: 'desafios.html',     icon: ICONS.trophy,   label: 'Desafios',     show: !!user.admin },
    { href: 'financeiro.html',   icon: ICONS.money,    label: 'Financeiro',   show: !!user.admin },
    { href: 'gestao.html',       icon: ICONS.chart,    label: 'Gestão',       show: podeVerGestao() },
    { href: 'mensagens.html',    icon: ICONS.chat,     label: 'Central do Aluno', show: true },
    { href: 'usuarios.html',     icon: ICONS.shield,   label: 'Usuários',     show: !!user.admin },
    { href: 'notificacoes.html', icon: ICONS.bell,     label: 'Notificações', show: !!user.admin },
    { href: 'configuracoes.html',icon: ICONS.gear,     label: 'Configurações',show: !!user.admin },
    { href: 'premium.html',      icon: ICONS.crown,    label: 'Planos',       show: true },
  ].filter(n => n.show);

  const links = nav.map(n => {
    const active = activePage === n.label;
    return `
    <a href="${n.href}" class="nav-link${active?' active':''}">
      <span class="nav-icon">${n.icon}</span>
      <span class="nav-label">${n.label}</span>
    </a>`;
  }).join('');

  // Garante viewport mobile-friendly (caso a página não tenha definido)
  if (!document.querySelector('meta[name="viewport"]')) {
    const m = document.createElement('meta');
    m.name = 'viewport';
    m.content = 'width=device-width,initial-scale=1,viewport-fit=cover';
    document.head.appendChild(m);
  }

  document.body.insertAdjacentHTML('afterbegin', `
    <div class="app-shell">
      <div class="sidebar-backdrop" onclick="closeSidebar()"></div>
      <aside class="sidebar">
        <div class="sidebar-logo">
          <div>
            <img class="intus-logo-full" src="logo-intus-dark.png" alt="Intus" style="height:34px;object-fit:contain;"/>
          </div>
          <button class="sidebar-close" onclick="closeSidebar()" aria-label="Fechar menu">×</button>
        </div>

        <nav class="sidebar-nav">${links}</nav>

        <div class="sidebar-footer">
          <div class="avatar-pill">
            <div class="avatar-circle">${initials}</div>
            <div class="avatar-info">
              <div class="avatar-name">${nome}</div>
              <div class="avatar-role">${role}</div>
            </div>
          </div>
          <a href="#" onclick="event.preventDefault(); Sessao.clear(); location.href='login.html';" class="logout-btn" title="Sair">${ICONS.logout}</a>
        </div>
      </aside>
      <div class="main-wrap">
        <header class="topbar">
          <button class="hamburger" onclick="openSidebar()" aria-label="Abrir menu">
            <span></span><span></span><span></span>
          </button>
          <div class="topbar-title">${activePage}</div>
          <div style="display:flex;align-items:center;gap:10px;margin-left:auto;">
            <div class="topbar-badge">Intus</div>
            <button id="theme-toggle" onclick="toggleTheme()" title="Alternar tema" class="theme-toggle-btn">
              <span id="theme-icon">☀️</span>
              <span id="theme-label">LIGHT</span>
            </button>
          </div>
        </header>
        <main class="main-content" id="main-content"></main>
      </div>
    </div>
  `);
  // Sincroniza ícone do botão e logos depois que o DOM foi injetado
  _syncThemeBtn();
  _updateLogosForTheme();
  // Fecha sidebar ao clicar em qualquer link de navegação (mobile)
  document.querySelectorAll('.sidebar-nav .nav-link').forEach(a => {
    a.addEventListener('click', () => closeSidebar());
  });
  loadAvatars();
}

function openSidebar()  { document.body.classList.add('sidebar-open'); }
function closeSidebar() { document.body.classList.remove('sidebar-open'); }

// ── SHARED CSS ─────────────────────────────────────────────────────────────
(function injectCSS() {
  const style = document.createElement('style');
  style.textContent = `
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ── DARK (default) ── */
    :root {
      --green: #7FFF00;
      --green-dark: #5ecc00;
      --green-dim: rgba(127,255,0,.12);
      --bg: #111111;
      --bg2: #1a1a1a;
      --bg3: #222222;
      --border: #2a2a2a;
      --text: #f0f0f0;
      --text-muted: #888;
      --text-dim: #555;
    }

    /* ── LIGHT ── */
    body.theme-light {
      --green: #3a9900;
      --green-dark: #2d7a00;
      --green-dim: rgba(58,153,0,.1);
      --bg: #f4f5f7;
      --bg2: #ffffff;
      --bg3: #f0f1f3;
      --border: #e0e0e0;
      --text: #111827;
      --text-muted: #6b7280;
      --text-dim: #9ca3af;
    }
    body.theme-light .sidebar { background: #1a1a1a; }
    body.theme-light .sidebar-logo { border-color: #2a2a2a; }
    body.theme-light .sidebar-footer { border-color: #2a2a2a; }
    body.theme-light .sidebar-nav .nav-link { color: #9ca3af; }
    body.theme-light .sidebar-nav .nav-link:hover { background: #222; color: #fff; }
    body.theme-light .sidebar-nav .nav-link.active { background: rgba(58,153,0,.2); color: #3a9900; border-color: rgba(58,153,0,.35); }
    body.theme-light .avatar-circle { background: #3a9900; }
    body.theme-light .topbar { background: #fff; border-color: #e0e0e0; }
    body.theme-light .topbar-title { color: #111827; }
    body.theme-light .topbar-badge { background: rgba(58,153,0,.1); color: #3a9900; border-color: rgba(58,153,0,.3); }
    body.theme-light #theme-toggle { background: #f0f1f3; color: #374151; border-color: #d1d5db; }
    body.theme-light .modal-box { background: #fff; border-color: #e0e0e0; }
    body.theme-light .modal-header { border-color: #e0e0e0; }
    body.theme-light .modal-footer { border-color: #e0e0e0; }
    body.theme-light .modal-title { color: #111827; }
    body.theme-light .modal-close { background: #f3f4f6; color: #6b7280; }
    body.theme-light .input { background: #fff; border-color: #d1d5db; color: #111827; }
    body.theme-light .label { color: #374151; }
    body.theme-light .btn-secondary { background: #f3f4f6; color: #374151; border-color: #d1d5db; }
    body.theme-light .data-table th { background: #f9fafb; color: #6b7280; }
    body.theme-light .data-table td { color: #374151; border-color: #f3f4f6; }
    body.theme-light .data-table tr:hover td { background: #f9fafb; }
    body.theme-light .td-name { color: #111827; }
    body.theme-light .card { background: #fff; border-color: #e5e7eb; }
    body.theme-light .card-header { border-color: #f3f4f6; }
    body.theme-light .card-title { color: #111827; }
    body.theme-light .pill-green { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    body.theme-light .pill-red   { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
    body.theme-light .pill-yellow { background: #fef9c3; color: #854d0e; border-color: #fef08a; }
    body.theme-light .pill-blue  { background: #dbeafe; color: #1d4ed8; border-color: #bfdbfe; }
    body.theme-light .badge-red    { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
    body.theme-light .badge-yellow { background: #fef9c3; color: #854d0e; border-color: #fef08a; }
    body.theme-light .badge-green  { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
    body.theme-light .toast-success { background: #3a9900; color: #fff; }
    body.theme-light .stat-card { background: #fff; border-color: #e5e7eb; }
    body.theme-light .stat-value { color: #111827; }
    body.theme-light .stat-label { color: #6b7280; }

    body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text); transition: background 0.2s, color 0.2s; }

    /* ── SHELL ── */
    .app-shell { display: flex; min-height: 100vh; }

    /* ── SIDEBAR ── */
    .sidebar {
      width: 220px; flex-shrink: 0;
      background: #0a0a0a;
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0; height: 100vh;
      z-index: 100; border-right: 1px solid var(--border);
    }
    .sidebar-logo {
      display: flex; align-items: center; gap: 8px;
      padding: 22px 18px 18px;
      border-bottom: 1px solid var(--border);
    }
    .intus-logo-full { display: block; height: 34px; object-fit: contain; }

    /* ── NAV ── */
    .sidebar-nav { flex: 1; padding: 12px 10px; display: flex; flex-direction: column; gap: 2px; overflow-y: auto; }
    .nav-link {
      display: flex; align-items: center; gap: 10px;
      padding: 9px 12px; border-radius: 8px;
      color: var(--text-muted); text-decoration: none;
      font-size: 13px; font-weight: 500;
      transition: background 0.15s, color 0.15s;
    }
    .nav-link:hover  { background: var(--bg3); color: #fff; }
    .nav-link.active { background: var(--green-dim); color: var(--green); border: 1px solid rgba(127,255,0,.25); }
    .nav-link.active .nav-icon svg { stroke: var(--green); }
    .nav-icon { display: flex; align-items: center; opacity: 0.85; }
    .nav-link.active .nav-icon { opacity: 1; }

    /* ── FOOTER ── */
    .sidebar-footer {
      padding: 12px 10px; border-top: 1px solid var(--border);
      display: flex; align-items: center; gap: 8px;
    }
    .avatar-pill { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 0; }
    .avatar-circle {
      width: 32px; height: 32px; border-radius: 50%;
      background: var(--green); color: #000;
      font-size: 12px; font-weight: 800;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .avatar-name  { font-size: 12px; font-weight: 600; color: #e5e7eb; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .avatar-role  { font-size: 10px; color: var(--text-dim); }
    .logout-btn {
      display: flex; align-items: center; justify-content: center;
      width: 30px; height: 30px; border-radius: 6px;
      color: var(--text-dim); text-decoration: none; flex-shrink: 0;
      transition: background 0.15s, color 0.15s;
    }
    .logout-btn:hover { background: var(--bg3); color: #ef4444; }

    /* ── MAIN WRAP ── */
    .main-wrap { margin-left: 220px; flex: 1; display: flex; flex-direction: column; }

    /* ── TOPBAR ── */
    .topbar {
      background: var(--bg2); border-bottom: 1px solid var(--border);
      padding: 14px 28px; display: flex; align-items: center; justify-content: space-between;
      position: sticky; top: 0; z-index: 50;
    }
    .topbar-title { font-size: 15px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; }
    .topbar-badge {
      font-size: 11px; background: var(--green-dim); color: var(--green);
      border: 1px solid rgba(127,255,0,.3);
      padding: 3px 10px; border-radius: 20px; font-weight: 600;
    }

    /* ── CONTENT ── */
    .main-content { padding: 24px 28px; flex: 1; }

    /* ── CARDS ── */
    .card {
      background: var(--bg2); border-radius: 12px;
      border: 1px solid var(--border); overflow: hidden;
    }
    .card-header {
      padding: 14px 20px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .card-title { font-size: 13px; font-weight: 600; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; }
    .card-badge {
      font-size: 11px; font-weight: 700;
      padding: 2px 8px; border-radius: 20px;
    }
    .badge-red    { background: rgba(220,38,38,.2); color: #f87171; border: 1px solid rgba(220,38,38,.3); }
    .badge-yellow { background: rgba(234,179,8,.2); color: #fde047; border: 1px solid rgba(234,179,8,.3); }
    .badge-green  { background: var(--green-dim); color: var(--green); border: 1px solid rgba(127,255,0,.25); }
    .badge-blue   { background: rgba(59,130,246,.2); color: #93c5fd; border: 1px solid rgba(59,130,246,.3); }

    /* ── TABLE ── */
    .data-table { width: 100%; border-collapse: collapse; }
    .data-table th {
      text-align: left; font-size: 10px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.8px;
      color: var(--text-dim); padding: 10px 20px; background: var(--bg3);
      border-bottom: 1px solid var(--border);
    }
    .data-table td {
      padding: 11px 20px; font-size: 13px; color: #ccc;
      border-bottom: 1px solid var(--border);
    }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: var(--bg3); }
    .td-name { font-weight: 600; color: #fff; }
    .td-date { color: var(--text-muted); font-size: 12px; }
    .td-prof { font-size: 11px; color: var(--text-dim); }

    /* ── STAT CARDS ── */
    .stat-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 16px; margin-bottom: 24px; }
    @media (max-width: 900px) { .stat-grid { grid-template-columns: repeat(2,1fr); } }
    .stat-card {
      background: var(--bg2); border-radius: 12px; border: 1px solid var(--border);
      padding: 18px 20px; display: flex; align-items: center; gap: 14px;
    }
    .stat-icon {
      width: 42px; height: 42px; border-radius: 10px;
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .stat-icon svg { width: 20px; height: 20px; }
    .stat-label { font-size: 11px; color: var(--text-muted); font-weight: 500; }
    .stat-value { font-size: 22px; font-weight: 700; color: #fff; line-height: 1.2; }

    /* ── GRID 2 COLS ── */
    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

    /* ── BUTTONS ── */
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px; border: none; cursor: pointer;
      font-size: 13px; font-weight: 700; transition: opacity 0.15s;
      text-decoration: none; font-family: inherit;
    }
    .btn:hover { opacity: 0.85; }
    .btn-primary   { background: var(--green); color: #000; }
    .btn-secondary { background: var(--bg3); color: #ccc; border: 1px solid var(--border); }
    .btn-success   { background: #16a34a; color: #fff; }
    .btn-danger    { background: #dc2626; color: #fff; }
    .btn-sm { padding: 5px 12px; font-size: 12px; }

    /* ── INPUT ── */
    .input {
      width: 100%; min-width: 0; padding: 9px 12px; border: 1px solid var(--border);
      border-radius: 8px; font-size: 13px; color: #fff;
      outline: none; font-family: inherit; background: var(--bg3);
    }
    /* <select> e <input> tem largura mínima de conteúdo por padrão do navegador
       (min-width:auto), que width:100% não sobrepõe. Dentro de grid/flex isso
       empurra o container inteiro pra largura do texto mais longo da opção,
       cortando o resto da tela em vez de quebrar linha -- achado em telas
       estreitas do painel (nutrição, refeições) em 06/09/2026. */
    .input:focus { border-color: var(--green); box-shadow: 0 0 0 3px var(--green-dim); }
    .label { font-size: 12px; font-weight: 600; color: #ccc; display: block; margin-bottom: 5px; }
    .form-group { display: flex; flex-direction: column; gap: 4px; }

    /* ── MODAL ── */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,.75);
      z-index: 200; display: flex; align-items: center; justify-content: center; padding: 16px;
    }
    .modal-box {
      background: var(--bg2); border: 1px solid var(--border);
      border-radius: 16px; width: 100%; max-width: 480px;
      max-height: 90vh; overflow-y: auto; box-shadow: 0 25px 50px rgba(0,0,0,.6);
    }
    .modal-header {
      padding: 18px 22px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .modal-title { font-size: 15px; font-weight: 700; color: #fff; text-transform: uppercase; letter-spacing: 0.5px; }
    .modal-close {
      width: 28px; height: 28px; border-radius: 6px; border: none; background: var(--bg3);
      cursor: pointer; font-size: 16px; display: flex; align-items: center; justify-content: center;
      color: var(--text-muted);
    }
    .modal-close:hover { background: rgba(220,38,38,.2); color: #f87171; }
    .modal-body { padding: 22px; display: flex; flex-direction: column; gap: 16px; }
    .modal-footer { padding: 16px 22px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }

    /* ── STATUS PILL ── */
    .pill {
      display: inline-flex; align-items: center;
      padding: 3px 10px; border-radius: 20px;
      font-size: 11px; font-weight: 700;
    }
    .pill-green  { background: var(--green-dim); color: var(--green); border: 1px solid rgba(127,255,0,.25); }
    .pill-red    { background: rgba(220,38,38,.2); color: #f87171; border: 1px solid rgba(220,38,38,.3); }
    .pill-yellow { background: rgba(234,179,8,.2); color: #fde047; border: 1px solid rgba(234,179,8,.3); }
    .pill-blue   { background: rgba(96,165,250,.15); color: #60a5fa; border: 1px solid rgba(96,165,250,.3); }

    /* ── SEARCH BAR ── */
    .search-bar {
      display: flex; align-items: center; gap: 12px;
      padding: 12px 20px; border-bottom: 1px solid var(--border);
    }
    .search-bar .input { max-width: 300px; }

    /* ── TOAST ── */
    .toast {
      position: fixed; bottom: 24px; right: 24px;
      padding: 12px 20px; border-radius: 10px;
      color: #000; font-size: 13px; font-weight: 700;
      box-shadow: 0 8px 24px rgba(0,0,0,.4); z-index: 999;
      animation: toastIn .25s ease;
    }
    @keyframes toastIn { from { transform: translateY(20px); opacity: 0; } to { transform: none; opacity: 1; } }
    .toast-success { background: var(--green); color: #000; }
    .toast-error   { background: #dc2626; color: #fff; }

    /* ── HAMBURGER + BACKDROP (escondidos no desktop) ── */
    .hamburger {
      display: none;
      width: 40px; height: 40px; padding: 0;
      border: 1px solid var(--border); background: var(--bg3);
      border-radius: 8px; cursor: pointer;
      flex-direction: column; align-items: center; justify-content: center; gap: 4px;
      flex-shrink: 0; margin-right: 12px;
    }
    .hamburger span { display: block; width: 18px; height: 2px; background: var(--text); border-radius: 2px; }
    .sidebar-backdrop {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,.55); z-index: 99; opacity: 0; pointer-events: none;
      transition: opacity .2s;
    }
    .sidebar-close {
      display: none;
      margin-left: auto; width: 32px; height: 32px;
      border: none; background: transparent; color: #888;
      font-size: 28px; line-height: 1; cursor: pointer; padding: 0;
      border-radius: 6px;
    }
    .sidebar-close:hover { background: var(--bg3); color: #f0f0f0; }
    .theme-toggle-btn {
      display: flex; align-items: center; gap: 6px;
      padding: 4px 12px; border-radius: 20px; border: 1px solid var(--border);
      background: var(--bg3); color: var(--text-muted);
      font-size: 11px; font-weight: 700; cursor: pointer; font-family: inherit;
      transition: all 0.15s; flex-shrink: 0;
    }

    /* ── TABLE WRAP (overflow horizontal no mobile) ── */
    .table-wrap, .data-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .data-table { min-width: 0; }

    /* ── TABLET (≤ 1024px) ── */
    @media (max-width: 1024px) {
      .stat-grid { grid-template-columns: repeat(2,1fr); }
      .main-content { padding: 20px 18px; }
      .topbar { padding: 12px 18px; }
    }

    /* ── MOBILE (≤ 768px) ── */
    @media (max-width: 768px) {
      .hamburger { display: flex; }
      .sidebar-close { display: flex; align-items: center; justify-content: center; }

      /* Sidebar vira drawer */
      .sidebar {
        transform: translateX(-100%);
        transition: transform .25s ease;
        width: 260px; max-width: 84vw;
        box-shadow: 0 0 30px rgba(0,0,0,.5);
      }
      body.sidebar-open .sidebar { transform: translateX(0); }
      body.sidebar-open .sidebar-backdrop { display: block; opacity: 1; pointer-events: auto; }

      .main-wrap { margin-left: 0; }

      .topbar {
        padding: 10px 14px;
        gap: 6px;
      }
      .topbar-title { font-size: 13px; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
      .topbar-badge { display: none; }
      .theme-toggle-btn { padding: 4px 8px; font-size: 10px; }
      .theme-toggle-btn #theme-label { display: none; }

      .main-content { padding: 16px 12px; }

      .card-header { padding: 12px 14px; flex-wrap: wrap; gap: 8px; }
      .card-title { font-size: 12px; }
      /* Cards com tabelas viram scrolladores horizontais no mobile */
      .card:has(.data-table) { overflow-x: auto; -webkit-overflow-scrolling: touch; }
      .data-table th { padding: 8px 12px; font-size: 9px; white-space: nowrap; }
      .data-table td { padding: 9px 12px; font-size: 12px; }

      .stat-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
      .stat-card { padding: 14px; gap: 10px; }
      .stat-icon { width: 36px; height: 36px; }
      .stat-icon svg { width: 16px; height: 16px; }
      .stat-value { font-size: 18px; }
      .stat-label { font-size: 10px; }

      .modal-overlay { padding: 0; align-items: flex-end; }
      .modal-box { max-width: 100%; max-height: 92dvh; border-radius: 16px 16px 0 0; }
      .modal-header { padding: 14px 16px; }
      .modal-body { padding: 16px; gap: 12px; }
      .modal-footer { padding: 12px 16px; flex-wrap: wrap; }
      .modal-footer .btn { flex: 1; justify-content: center; }

      .btn { padding: 9px 14px; font-size: 13px; min-height: 40px; }
      .btn-sm { padding: 6px 10px; font-size: 12px; min-height: 32px; }
      .input { padding: 10px 12px; font-size: 16px; /* evita zoom iOS */ }

      .search-bar { flex-wrap: wrap; padding: 10px 14px; }
      .search-bar .input { max-width: 100%; }
    }

    /* ── SMARTPHONE PEQUENO (≤ 380px) ── */
    @media (max-width: 380px) {
      .stat-grid { grid-template-columns: 1fr; }
      .topbar-title { font-size: 12px; }
      .main-content { padding: 14px 10px; }
    }

    /* ── ORIENTAÇÃO LANDSCAPE em phone (altura curta) ── */
    @media (max-height: 480px) and (max-width: 900px) {
      .sidebar-logo { padding: 14px 16px 12px; }
      .sidebar-nav { padding: 6px 8px; }
      .nav-link { padding: 7px 10px; font-size: 12px; }
      .sidebar-footer { padding: 8px 10px; }
    }
  `;
  document.head.appendChild(style);
})();

// ── THEME TOGGLE ─────────────────────────────────────────────────────────
// Aplica tema salvo o mais cedo possível — funciona mesmo com body ainda não montado
// usando um <style> inline para evitar flash
(function applyStoredTheme() {
  if (localStorage.getItem('mx-theme') === 'light') {
    // Injeta a classe no <html> (sempre existe) e também em body quando disponível
    document.documentElement.classList.add('theme-light-pre');
    // Adiciona CSS temporário para aplicar o tema antes do body estar pronto
    const s = document.createElement('style');
    s.id = 'theme-pre';
    s.textContent = `
      html.theme-light-pre body,
      body.theme-light {
        background: #f4f5f7 !important;
        color: #111827 !important;
      }
    `;
    document.head.appendChild(s);

    // Quando body estiver disponível, migra a classe para lá
    function applyToBody() {
      if (document.body) {
        document.body.classList.add('theme-light');
        document.documentElement.classList.remove('theme-light-pre');
        document.getElementById('theme-pre')?.remove();
      } else {
        requestAnimationFrame(applyToBody);
      }
    }
    applyToBody();
  }
})();

function _syncThemeBtn() {
  const isLight = document.body.classList.contains('theme-light');
  const icon  = document.getElementById('theme-icon');
  const label = document.getElementById('theme-label');
  if (icon)  icon.textContent  = isLight ? '🌙' : '☀️';
  if (label) label.textContent = isLight ? 'DARK' : 'LIGHT';
}

function toggleTheme() {
  const isLight = document.body.classList.toggle('theme-light');
  localStorage.setItem('mx-theme', isLight ? 'light' : 'dark');
  _syncThemeBtn();
  _updateLogosForTheme();
}

function _updateLogosForTheme() {
  const isLight = document.body.classList.contains('theme-light');
  document.querySelectorAll('.intus-logo-icon').forEach(img => {
    img.src = isLight ? 'logo-icon-light.png' : 'logo-icon-dark.png';
  });
  document.querySelectorAll('.intus-logo-full').forEach(img => {
    img.src = isLight ? 'logo-intus-light.png' : 'logo-intus-dark.png';
  });
  document.querySelectorAll('img[src*="logo-intus-"]').forEach(img => {
    if (img.classList.contains('intus-logo-full')) return;
    img.src = isLight ? 'logo-intus-light.png' : 'logo-intus-dark.png';
  });
  document.querySelectorAll('img[src*="logo-icon-"]').forEach(img => {
    if (img.classList.contains('intus-logo-icon')) return;
    img.src = isLight ? 'logo-icon-light.png' : 'logo-icon-dark.png';
  });
}

// ── HELPERS ───────────────────────────────────────────────────────────────
function fmtMoeda(v) {
  return 'R$ ' + parseFloat(v||0).toFixed(2).replace('.',',');
}
function fmtData(d) {
  if (!d) return '—';
  return new Date(d + (d.length === 10 ? 'T00:00:00' : '')).toLocaleDateString('pt-BR');
}
function openModal(html, extraClass) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.innerHTML = `<div class="modal-box ${extraClass||''}">${html}</div>`;
  let _mdTarget = null;
  overlay.addEventListener('mousedown', e => { _mdTarget = e.target; });
  overlay.addEventListener('mouseup', e => { if (_mdTarget === overlay && e.target === overlay) closeModal(); _mdTarget = null; });
  document.body.appendChild(overlay);
}
function closeModal() { document.querySelector('.modal-overlay')?.remove(); }

// Avatar helper — renders photo or initials
let _avatarsMap = JSON.parse(localStorage.getItem('intus-avatars') || '{}');
let _userAvatarsMap = JSON.parse(localStorage.getItem('intus-user-avatars') || '{}');
function loadAvatars() {
  if (typeof API !== 'undefined' && API.getAvatars) {
    API.getAvatars().then(m => { if (m && typeof m === 'object') _avatarsMap = m; }).catch(() => {});
  }
  if (typeof API !== 'undefined' && API.getUserAvatars) {
    API.getUserAvatars().then(m => { if (m && typeof m === 'object') _userAvatarsMap = m; }).catch(() => {});
  }
}
function avatarHtml(idatleta, nome, size) {
  size = size || 36;
  const src = _avatarsMap[String(idatleta)] || _avatarsMap[Number(idatleta)];
  if (src) {
    return `<img src="${src}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt=""/>`;
  }
  const iniciais = (nome || '?').split(' ').filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
  const fs = Math.round(size * 0.38);
  return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:var(--brand);display:flex;align-items:center;justify-content:center;font-size:${fs}px;font-weight:700;color:#000;flex-shrink:0;">${iniciais}</div>`;
}
function userAvatarHtml(idusuario, nome, size) {
  size = size || 36;
  const src = _userAvatarsMap[String(idusuario)] || _userAvatarsMap[Number(idusuario)];
  if (src) {
    return `<img src="${src}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;flex-shrink:0;" alt=""/>`;
  }
  const iniciais = (nome || '?').split(' ').filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
  const fs = Math.round(size * 0.38);
  return `<div style="width:${size}px;height:${size}px;border-radius:50%;background:var(--brand);display:flex;align-items:center;justify-content:center;font-size:${fs}px;font-weight:700;color:#000;flex-shrink:0;">${iniciais}</div>`;
}

function showToast(msg, type='success') {
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => el.remove(), 10000);
}

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js?v=8').then(reg => {
    reg.addEventListener('updatefound', () => {
      const nw = reg.installing;
      if (nw) nw.addEventListener('statechange', () => {
        if (nw.state === 'activated') {
          // Trava anti-loop: recarrega no máximo 1x por minuto
          const last = Number(sessionStorage.getItem('sw-reload-ts') || 0);
          if (Date.now() - last > 60000) {
            sessionStorage.setItem('sw-reload-ts', String(Date.now()));
            location.reload();
          }
        }
      });
    });
    reg.update();
  }).catch(() => {});
}
