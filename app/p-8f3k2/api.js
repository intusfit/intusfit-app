// ── API CLIENT ────────────────────────────────────────────────────────────
// Camada de comunicação com o backend PHP + FALLBACK LOCAL (localStorage).
// Se o backend estiver indisponível, os dados são lidos/gravados no Store
// local — assim alterações em uma página são vistas imediatamente nas outras.
const API_BASE = 'https://intusfit.com.br/app/api';
const _APP_VERSION = '20260520d';

// Force SW update — runs on every page load regardless of cached HTML version
(function _forceSwUpdate() {
  if (!('serviceWorker' in navigator)) return;
  const lastV = localStorage.getItem('intus-app-ver');
  if (lastV === _APP_VERSION) return;
  localStorage.setItem('intus-app-ver', _APP_VERSION);
  navigator.serviceWorker.getRegistrations().then(regs => {
    regs.forEach(r => r.unregister());
  }).then(() => {
    if (lastV) location.reload();
  });
})();

// Gerenciamento de token
const Auth = {
  getToken: () => localStorage.getItem('mx-token'),
  setToken: (t) => localStorage.setItem('mx-token', t),
  getUser:  () => JSON.parse(localStorage.getItem('mx-user') || '{}'),
  setUser:  (u) => localStorage.setItem('mx-user', JSON.stringify(u)),
  clear:    () => { localStorage.removeItem('mx-token'); localStorage.removeItem('mx-user'); },
  isLogged: () => !!localStorage.getItem('mx-token'),
  isLocalToken: () => {
    const t = localStorage.getItem('mx-token') || '';
    return t.startsWith('local-');
  },
  isStudentToken: () => {
    const t = localStorage.getItem('mx-token') || '';
    return t.startsWith('aluno-');
  },
};

// ── LOCAL STORE (fallback) ────────────────────────────────────────────────
// Seeds a partir do MOCK (definido em _mock.js) e mantém sincronia entre páginas.
const Store = {
  KEYS: {
    atletas:      'intus-atletas',
    exercicios:   'intus-exercicios',
    alongamentos: 'intus-alongamentos',
    fichas:       'intus-fichas',
    treinos:      'intus-treinos',       // exercícios dentro de ficha
    mensalidades: 'intus-mensalidades',
    avaliacoes:   'intus-avaliacoes',
    nutricao:     'intus-nutricao',
    mensagens:    'intus-mensagens',
  },

  _seed() {
    // Só semeia se ainda não existir no localStorage
    if (!localStorage.getItem(this.KEYS.atletas) && typeof MOCK !== 'undefined') {
      const mockAtletas = (MOCK.atletas || []).map(a => ({ ...a, origem: 'mock' }));
      localStorage.setItem(this.KEYS.atletas, JSON.stringify(mockAtletas));
    }
    // Exercícios: seed base se vazio, merge se existente (preserva adicionados)
    {
      const _exKey = this.KEYS.exercicios;
      const base = [
        // PEITO
        { idexercicio: 1,  nmexercicio: 'Supino Reto',               grupo: 'Peito',     observacao: 'Controle a descida',   substitutos: [2, 3], videoyoutube: 'https://youtube.com/watch?v=kj_yrgzG5WM' },
        { idexercicio: 2,  nmexercicio: 'Supino Reto (máquina)',     grupo: 'Peito',     observacao: 'Pegada na altura do peito', substitutos: [1, 3] },
        { idexercicio: 3,  nmexercicio: 'Crucifixo Peck Deck',       grupo: 'Peito',     observacao: 'Controle excêntrico',  substitutos: [1, 2], videoyoutube: 'https://youtube.com/watch?v=s2mo2doudcU' },
        { idexercicio: 4,  nmexercicio: 'Supino Inclinado',          grupo: 'Peito',     observacao: 'Banco 30°–45°',        substitutos: [5, 1], videoyoutube: 'https://youtube.com/watch?v=Id-yw7XLHrY' },
        { idexercicio: 5,  nmexercicio: 'Supino Inclinado (máquina)',grupo: 'Peito',     observacao: 'Pegada neutra opcional', substitutos: [4, 1]    },

        // PERNA / QUADRÍCEPS
        { idexercicio: 10, nmexercicio: 'Agachamento Livre',         grupo: 'Perna',     observacao: 'Descer até 90°',       substitutos: [11, 12, 13], videoyoutube: 'https://youtube.com/watch?v=oRqWo0iwwLc' },
        { idexercicio: 11, nmexercicio: 'Agachamento na Barra Guiada (Smith)', grupo: 'Perna', observacao: 'Pés à frente',   substitutos: [10, 12, 13], videoyoutube: 'https://youtube.com/watch?v=tTtSGKZCVq8' },
        { idexercicio: 12, nmexercicio: 'Agachamento na Máquina (Hack)', grupo: 'Perna', observacao: 'Costas apoiadas',      substitutos: [10, 11, 13], videoyoutube: 'https://youtube.com/watch?v=LRGOQprxXJw' },
        { idexercicio: 13, nmexercicio: 'Leg Press 45°',             grupo: 'Perna',     observacao: 'Não trave o joelho',   substitutos: [10, 11, 12], videoyoutube: 'https://youtube.com/watch?v=n9hinnSkwW8' },
        { idexercicio: 14, nmexercicio: 'Cadeira Extensora',         grupo: 'Perna',     observacao: 'Pausa no topo',        substitutos: [13], videoyoutube: 'https://youtube.com/watch?v=ICpPFzcmu7s' },

        // POSTERIOR / GLÚTEO
        { idexercicio: 20, nmexercicio: 'Stiff',                     grupo: 'Posterior', observacao: 'Coluna neutra',        substitutos: [21, 22], videoyoutube: 'https://youtube.com/watch?v=GaX-lj-v_Vk' },
        { idexercicio: 21, nmexercicio: 'Cadeira Flexora',           grupo: 'Posterior', observacao: 'Amplitude completa',   substitutos: [22, 20], videoyoutube: 'https://youtube.com/watch?v=00ZOrUy67w0' },
        { idexercicio: 22, nmexercicio: 'Mesa Flexora',              grupo: 'Posterior', observacao: 'Quadril apoiado',      substitutos: [21, 20], videoyoutube: 'https://youtube.com/watch?v=F3ZAU89fyTU' },
        { idexercicio: 23, nmexercicio: 'Elevação Pélvica (Hip Thrust)', grupo: 'Glúteo', observacao: 'Queixo para baixo',  substitutos: [24], videoyoutube: 'https://youtube.com/watch?v=NCbTBccyIDE' },
        { idexercicio: 24, nmexercicio: 'Glúteo na Máquina',         grupo: 'Glúteo',    observacao: 'Contração isolada',    substitutos: [23]         },

        // COSTAS
        { idexercicio: 30, nmexercicio: 'Puxada Frontal',            grupo: 'Costas',    observacao: 'Peito no banco',       substitutos: [31, 32], videoyoutube: 'https://youtube.com/watch?v=tubB9uvlaeY' },
        { idexercicio: 31, nmexercicio: 'Puxada Pegada Neutra',      grupo: 'Costas',    observacao: 'Cotovelos no corpo',   substitutos: [30, 32], videoyoutube: 'https://youtube.com/watch?v=PMEWTkx0DMo' },
        { idexercicio: 32, nmexercicio: 'Remada Curvada',            grupo: 'Costas',    observacao: 'Tronco 45°',           substitutos: [33, 30], videoyoutube: 'https://youtube.com/watch?v=zBKpcA-j_i4' },
        { idexercicio: 33, nmexercicio: 'Remada Máquina',            grupo: 'Costas',    observacao: 'Escápulas conectadas', substitutos: [32, 30], videoyoutube: 'https://youtube.com/watch?v=m9jUODXtpKc' },

        // BRAÇO
        { idexercicio: 40, nmexercicio: 'Rosca Direta',              grupo: 'Bíceps',    observacao: 'Cotovelos fixos',      substitutos: [41, 42], videoyoutube: 'https://youtube.com/watch?v=cvBp8EmufuY' },
        { idexercicio: 41, nmexercicio: 'Rosca Alternada',           grupo: 'Bíceps',    observacao: 'Supinação na subida',  substitutos: [40, 42]    },
        { idexercicio: 42, nmexercicio: 'Rosca Scott',               grupo: 'Bíceps',    observacao: 'Descer controlado',    substitutos: [40, 41]    },
        { idexercicio: 50, nmexercicio: 'Tríceps Pulley',            grupo: 'Tríceps',   observacao: 'Cotovelo fixo',        substitutos: [51, 52], videoyoutube: 'https://youtube.com/watch?v=X98HwHXAixY' },
        { idexercicio: 51, nmexercicio: 'Tríceps Francês',           grupo: 'Tríceps',   observacao: 'Cotovelos apontando pra cima', substitutos: [50, 52], videoyoutube: 'https://youtube.com/watch?v=86drIJvPyVI' },
        { idexercicio: 52, nmexercicio: 'Tríceps Testa',             grupo: 'Tríceps',   observacao: 'Barra ou halteres',    substitutos: [50, 51], videoyoutube: 'https://youtube.com/watch?v=GkcylDsflBQ' },

        // OMBRO
        { idexercicio: 60, nmexercicio: 'Desenvolvimento Máquina',   grupo: 'Ombro',     observacao: 'Escápulas apoiadas',   substitutos: [61]         },
        { idexercicio: 61, nmexercicio: 'Desenvolvimento Halteres',  grupo: 'Ombro',     observacao: 'Trajetória em V',      substitutos: [60], videoyoutube: 'https://youtube.com/watch?v=3xR5mhWF7k4' },
        { idexercicio: 62, nmexercicio: 'Elevação Lateral',          grupo: 'Ombro',     observacao: 'Cotovelos levemente flexionados', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=GSEGKvmtf7Y' },
        { idexercicio: 63, nmexercicio: 'Elevação Frontal',          grupo: 'Ombro',     observacao: 'Braços estendidos, sem embalo', substitutos: [62], videoyoutube: 'https://youtube.com/watch?v=BQCI0Pg7Gfw' },
        { idexercicio: 64, nmexercicio: 'Face Pull',                 grupo: 'Ombro',     observacao: 'Rotação externa no topo', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=aDNOocCD1Y0' },

        // ABDÔMEN / CORE
        { idexercicio: 70, nmexercicio: 'Abdominal Supra',           grupo: 'Abdômen',   observacao: 'Ombros saem do chão, lombar apoiada', substitutos: [71], videoyoutube: 'https://youtube.com/watch?v=lxUdlT9nNeY' },
        { idexercicio: 71, nmexercicio: 'Abdominal Infra',           grupo: 'Abdômen',   observacao: 'Eleve o quadril, não balance', substitutos: [70], videoyoutube: 'https://youtube.com/watch?v=Vt08ggZwIZg' },
        { idexercicio: 72, nmexercicio: 'Dead Bug',                  grupo: 'Abdômen',   observacao: 'Lombar no chão o tempo todo', substitutos: [70, 71], videoyoutube: 'https://youtube.com/watch?v=E2AZFDjwE6U' },
        { idexercicio: 73, nmexercicio: 'Bird Dog',                  grupo: 'Abdômen',   observacao: 'Extensão oposta braço-perna', substitutos: [72], videoyoutube: 'https://youtube.com/watch?v=qduHP6LwjBU' },
        { idexercicio: 74, nmexercicio: 'Manobra de Bracing',        grupo: 'Core',      observacao: 'Ative abdômen como se fosse levar um soco', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=shhJDv8aPOE' },

        // COSTAS (extras)
        { idexercicio: 80, nmexercicio: 'Puxada Supinada',           grupo: 'Costas',    observacao: 'Pegada supinada, ênfase no bíceps', substitutos: [30, 31], videoyoutube: 'https://youtube.com/watch?v=PMEWTkx0DMo' },
        { idexercicio: 81, nmexercicio: 'Remada Inclinada',          grupo: 'Costas',    observacao: 'Tronco inclinado, halteres ou barra', substitutos: [32, 33], videoyoutube: 'https://youtube.com/watch?v=zBKpcA-j_i4' },
        { idexercicio: 82, nmexercicio: 'Remada Baixa Supinada',     grupo: 'Costas',    observacao: 'Pegada supinada no cabo', substitutos: [33], videoyoutube: 'https://youtube.com/watch?v=zF2-ftDTIqs' },
        { idexercicio: 83, nmexercicio: 'Remada Baixa Neutra',       grupo: 'Costas',    observacao: 'Triângulo ou pegada neutra', substitutos: [32, 82], videoyoutube: 'https://youtube.com/watch?v=PY12MPCsHxg' },
        { idexercicio: 84, nmexercicio: 'Remada Articulada',         grupo: 'Costas',    observacao: 'Máquina articulada, escápulas', substitutos: [33], videoyoutube: 'https://youtube.com/watch?v=ySC8nMZqqUA' },
        { idexercicio: 85, nmexercicio: 'Remada Serrote',            grupo: 'Costas',    observacao: 'Unilateral com halter no banco', substitutos: [81], videoyoutube: 'https://youtube.com/watch?v=EzrmF3P-evc' },
        { idexercicio: 86, nmexercicio: 'Puxador Inclinado',         grupo: 'Costas',    observacao: 'Incline o tronco levemente para trás', substitutos: [30], videoyoutube: 'https://youtube.com/watch?v=ljc6Pv17bFg' },
        { idexercicio: 87, nmexercicio: 'Barra Fixa Adaptada',       grupo: 'Costas',    observacao: 'Com elástico ou gravitron', substitutos: [80], videoyoutube: 'https://youtube.com/watch?v=3fH-v6mfbkY' },

        // POSTERIOR (extras)
        { idexercicio: 90, nmexercicio: 'Good Morning',              grupo: 'Posterior', observacao: 'Barra nas costas, flexão de quadril', substitutos: [20], videoyoutube: 'https://youtube.com/watch?v=b-Jf_aBsdsQ' },
        { idexercicio: 91, nmexercicio: 'Levantamento Terra Sumo',   grupo: 'Posterior', observacao: 'Pés afastados, mãos entre as pernas', substitutos: [90, 20], videoyoutube: 'https://youtube.com/watch?v=Il6BsDkOx8I' },
        { idexercicio: 92, nmexercicio: 'Stiff no Cabo',             grupo: 'Posterior', observacao: 'Cabo baixo, mesma mecânica do stiff', substitutos: [20], videoyoutube: 'https://youtube.com/watch?v=qoOfhzMPP44' },
        { idexercicio: 93, nmexercicio: 'Flexão Nórdica',            grupo: 'Posterior', observacao: 'Excêntrica lenta, alto nível', substitutos: [21, 22], videoyoutube: 'https://youtube.com/watch?v=INYE6tuBpTM' },

        // GLÚTEO (extras)
        { idexercicio: 100, nmexercicio: 'Elevação Pélvica no Solo', grupo: 'Glúteo',    observacao: 'Sem carga, aperte no topo', substitutos: [23], videoyoutube: 'https://youtube.com/watch?v=btkguzN-j9Q' },
        { idexercicio: 101, nmexercicio: 'Extensão de Quadril no Cabo', grupo: 'Glúteo', observacao: 'Caneleira no cabo, perna estendida', substitutos: [24], videoyoutube: 'https://youtube.com/watch?v=XvTJOgGT9lo' },
        { idexercicio: 102, nmexercicio: 'Extensão de Quadril Cruzada', grupo: 'Glúteo', observacao: 'Cruze a perna por trás', substitutos: [101], videoyoutube: 'https://youtube.com/watch?v=TxfTZJJ1hO4' },
        { idexercicio: 103, nmexercicio: 'Abdução de Quadril no Cabo', grupo: 'Glúteo',  observacao: 'Lateral no cabo', substitutos: [], videoyoutube: 'https://youtube.com/watch?v=lnRXk4PsrB0' },
        { idexercicio: 104, nmexercicio: 'Banco Abdutor 120°',       grupo: 'Glúteo',    observacao: 'Banco inclinado, maior amplitude', substitutos: [103], videoyoutube: 'https://youtube.com/watch?v=i8kYmb-eHLg' },
        { idexercicio: 105, nmexercicio: 'Glúteo Sapinho (Frog)',    grupo: 'Glúteo',    observacao: 'Solas dos pés juntas, eleve o quadril', substitutos: [100], videoyoutube: 'https://youtube.com/watch?v=vBPNDeMhX2o' },

        // PERNA (extras)
        { idexercicio: 110, nmexercicio: 'Agachamento Búlgaro',      grupo: 'Perna',     observacao: 'Pé de trás elevado no banco', substitutos: [10], videoyoutube: 'https://youtube.com/watch?v=v73t-Tp76ek' },
        { idexercicio: 111, nmexercicio: 'Agachamento Taça',         grupo: 'Perna',     observacao: 'Halter na frente do peito', substitutos: [10, 13], videoyoutube: 'https://youtube.com/watch?v=RIpj5ClWfRY' },
        { idexercicio: 112, nmexercicio: 'Agachamento Sumo no Smith',grupo: 'Perna',     observacao: 'Pés afastados, pontas para fora', substitutos: [11], videoyoutube: 'https://youtube.com/watch?v=smiTENjkuoI' },
        { idexercicio: 113, nmexercicio: 'Hack Sumo',                grupo: 'Perna',     observacao: 'Pés afastados no hack', substitutos: [12], videoyoutube: 'https://youtube.com/watch?v=vm2ATyXPSxs' },
        { idexercicio: 114, nmexercicio: 'Panturrilha no Smith',     grupo: 'Panturrilha', observacao: 'Barra baixa, amplitude máxima', substitutos: [115], videoyoutube: 'https://youtube.com/watch?v=PeW7O2a0fv8' },
        { idexercicio: 115, nmexercicio: 'Panturrilha no Leg Press', grupo: 'Panturrilha', observacao: 'Ponta dos pés na plataforma', substitutos: [114], videoyoutube: 'https://youtube.com/watch?v=k6OZKXkLCys' },
        { idexercicio: 116, nmexercicio: 'Panturrilha em Pé',        grupo: 'Panturrilha', observacao: 'Máquina ou step com carga', substitutos: [114, 115], videoyoutube: 'https://youtube.com/watch?v=EETLkztbjmM' },
        { idexercicio: 117, nmexercicio: 'Panturrilha Sentada',      grupo: 'Panturrilha', observacao: 'Sóleo isolado', substitutos: [114], videoyoutube: 'https://youtube.com/watch?v=-yw27qlJuVs' },

        // PEITO (extras)
        { idexercicio: 120, nmexercicio: 'Crucifixo Inverso',        grupo: 'Ombro',     observacao: 'Posterior do deltóide', substitutos: [64], videoyoutube: 'https://youtube.com/watch?v=92het1xEZ5k' },
        { idexercicio: 121, nmexercicio: 'Flexão de Braços',         grupo: 'Peito',     observacao: 'Corpo reto, descer até 90°', substitutos: [1], videoyoutube: 'https://youtube.com/watch?v=hHi2S92FGy0' },
        { idexercicio: 122, nmexercicio: 'Elevação Frontal Inclinada', grupo: 'Ombro',   observacao: 'No banco inclinado', substitutos: [63], videoyoutube: 'https://youtube.com/watch?v=HpSJYwFi76Q' },
        { idexercicio: 123, nmexercicio: 'Desenvolvimento Posterior', grupo: 'Ombro',    observacao: 'Foco no deltóide posterior', substitutos: [64, 120], videoyoutube: 'https://youtube.com/watch?v=E9h5eYFSKYs' },

        // BÍCEPS / TRÍCEPS (extras)
        { idexercicio: 130, nmexercicio: 'Rosca Direta com Halteres', grupo: 'Bíceps',   observacao: 'Alternada ou simultânea', substitutos: [40, 41], videoyoutube: 'https://youtube.com/watch?v=-FePv08WCD4' },
        { idexercicio: 131, nmexercicio: 'Tríceps Corda',            grupo: 'Tríceps',   observacao: 'Abra a corda na parte final', substitutos: [50], videoyoutube: 'https://youtube.com/watch?v=D_i8Jeh3Ga4' },
        { idexercicio: 132, nmexercicio: 'Rosca Direta no Cabo',     grupo: 'Bíceps',    observacao: 'Tensão constante no cabo', substitutos: [40], videoyoutube: 'https://youtube.com/watch?v=yN41UmcnP00' },

        // GLÚTEO / POSTERIOR (extras)
        { idexercicio: 133, nmexercicio: 'Banco Abdutor 90°',        grupo: 'Glúteo',    observacao: 'Abertura máxima, contraia no topo', substitutos: [104, 103], videoyoutube: 'https://youtube.com/watch?v=i8kYmb-eHLg' },
        { idexercicio: 134, nmexercicio: 'Banco Abdutor 45°',        grupo: 'Glúteo',    observacao: 'Incline o tronco à frente', substitutos: [133, 103], videoyoutube: 'https://youtube.com/watch?v=i8kYmb-eHLg' },
        { idexercicio: 135, nmexercicio: 'Extensão de Quadril no Banco Romano', grupo: 'Glúteo', observacao: 'Quadril como eixo, squeeze no topo', substitutos: [101], videoyoutube: 'https://youtube.com/watch?v=vBPNDeMhX2o' },
        { idexercicio: 136, nmexercicio: 'Recuo (Agachamento Unilateral para Trás)', grupo: 'Perna', observacao: 'Passo largo para trás', substitutos: [110], videoyoutube: 'https://youtube.com/watch?v=v73t-Tp76ek' },
        { idexercicio: 137, nmexercicio: 'Elevação Pélvica Unilateral', grupo: 'Glúteo', observacao: 'Uma perna só, máxima contração', substitutos: [23, 100], videoyoutube: 'https://youtube.com/watch?v=NCbTBccyIDE' },
      ];
      const existente = JSON.parse(localStorage.getItem(_exKey) || '[]');
      if (existente.length === 0) {
        localStorage.setItem(_exKey, JSON.stringify(base));
      } else {
        const existenteIds = new Set(existente.map(e => Number(e.idexercicio)));
        const existenteNomes = new Set(existente.map(e => (e.nmexercicio||'').trim().toLowerCase()));
        const novosBase = base.filter(e => !existenteIds.has(Number(e.idexercicio)) && !existenteNomes.has((e.nmexercicio||'').trim().toLowerCase()));
        if (novosBase.length > 0) {
          localStorage.setItem(_exKey, JSON.stringify([...existente, ...novosBase]));
        }
      }
    }
    // Alongamentos: sempre sobrescreve com a versão oficial do catálogo
    const baseAlong = [
      { idalongamento: 1,  nome: 'Panturrilha',                    grupo: 'Panturrilha',        videoyoutube: 'https://youtube.com/watch?v=6_b4_DDjtuI' },
      { idalongamento: 2,  nome: 'Iliopsoas',                      grupo: 'Quadril',            videoyoutube: 'https://youtube.com/watch?v=MPKGmYBhWGM' },
      { idalongamento: 3,  nome: 'Posteriores de Coxa',            grupo: 'Posterior',          videoyoutube: 'https://youtube.com/watch?v=GzqhfLLTfPc' },
      { idalongamento: 4,  nome: 'Adutores',                       grupo: 'Quadril',            videoyoutube: 'https://youtube.com/watch?v=AkY8gVqWH2s' },
      { idalongamento: 5,  nome: 'Paravertebrais',                 grupo: 'Coluna',             videoyoutube: 'https://youtube.com/watch?v=73sXBlArHl0' },
      { idalongamento: 6,  nome: 'Glúteos',                        grupo: 'Glúteo',             videoyoutube: 'https://youtube.com/watch?v=1YFM-CMaa6w' },
      { idalongamento: 7,  nome: 'Quadríceps',                     grupo: 'Quadríceps',         videoyoutube: 'https://youtube.com/watch?v=0rbIMu1U_xg' },
      { idalongamento: 8,  nome: 'Manguito e Cintura Escapular',   grupo: 'Ombro',              videoyoutube: 'https://youtube.com/watch?v=CXVerwcJZak' },
      { idalongamento: 9,  nome: 'Esternocleidomastóideo',         grupo: 'Pescoço',            videoyoutube: 'https://youtube.com/watch?v=YZsuk9I22fw' },
      { idalongamento: 10, nome: 'Linha Lateral',                  grupo: 'Lateral',            videoyoutube: 'https://youtube.com/watch?v=Aax0TNBPK2k' },
      { idalongamento: 11, nome: 'Geral',                          grupo: 'Geral',              videoyoutube: 'https://youtube.com/watch?v=2ctb_SH0SmM' },
      { idalongamento: 12, nome: 'Alongamento Completo',           grupo: 'Geral',              videoyoutube: 'https://youtube.com/watch?v=t6RT_9SGVVE' },
    ];
    localStorage.setItem(this.KEYS.alongamentos, JSON.stringify(baseAlong));
    if (!localStorage.getItem(this.KEYS.fichas)) {
      localStorage.setItem(this.KEYS.fichas, JSON.stringify([]));
    }
    if (!localStorage.getItem(this.KEYS.treinos)) {
      localStorage.setItem(this.KEYS.treinos, JSON.stringify([]));
    }
    if (!localStorage.getItem(this.KEYS.mensalidades) && typeof MOCK !== 'undefined') {
      // copia do MOCK adicionando idatleta relacional
      const mens = (MOCK.mensalidades || []).map((m, i) => ({
        ...m,
        idatleta: ((MOCK.atletas || []).find(a => a.nome === m.nmathleta) || {}).idatleta || null,
      }));
      localStorage.setItem(this.KEYS.mensalidades, JSON.stringify(mens));
    }
    if (!localStorage.getItem(this.KEYS.avaliacoes)) {
      localStorage.setItem(this.KEYS.avaliacoes, JSON.stringify([]));
    }
    if (!localStorage.getItem(this.KEYS.mensagens) && typeof MOCK !== 'undefined') {
      localStorage.setItem(this.KEYS.mensagens, JSON.stringify(MOCK.mensagens || []));
    }
  },

  get(key) {
    if (!this._seeded) { this._seed(); this._seeded = true; this.dedup('exercicios', 'nmexercicio'); }
    try { return JSON.parse(localStorage.getItem(this.KEYS[key]) || '[]'); }
    catch { return []; }
  },

  set(key, arr) {
    localStorage.setItem(this.KEYS[key], JSON.stringify(arr));
    try { window.dispatchEvent(new Event('intus-' + key + '-change')); } catch {}
  },

  dedup(key, nameField) {
    const lista = this.get(key);
    const seen = new Set();
    const unique = [];
    for (const item of lista) {
      const nome = (item[nameField]||'').trim().toLowerCase();
      if (!nome || seen.has(nome)) continue;
      seen.add(nome);
      unique.push(item);
    }
    if (unique.length < lista.length) {
      this.set(key, unique);
      console.log('[Intus] Dedup ' + key + ': removidos ' + (lista.length - unique.length) + ' duplicados');
    }
  },

  nextId(key, idField) {
    const lista = this.get(key);
    const maxStore = lista.reduce((m, x) => Math.max(m, Number(x[idField]) || 0), 0);
    const minLocal = 900000;
    return Math.max(maxStore, minLocal) + 1;
  },
};

// semeia o store assim que o script carrega
try { Store._seed(); } catch(e) { /* ignora se MOCK não carregou ainda */ }

// Controle de conectividade com o backend.
// Em vez de sempre tentar e falhar, respeitamos o resultado da última checagem.
let USAR_API = false;              // começa assumindo OFFLINE para ser responsivo
let _apiCheckPromise = null;
async function checarAPI() {
  if (_apiCheckPromise) return _apiCheckPromise;
  _apiCheckPromise = (async () => {
    if (Auth.isLocalToken()) {
      USAR_API = false;
      return USAR_API;
    }
    // Tokens aluno-* e prof-* são aceitos pelo backend (qualquer token não-vazio no Bearer).
    // Testa conectividade real com um endpoint leve.
    try {
      const r = await fetch(API_BASE + '/atletas.php?busca=__ping__', {
        method: 'GET',
        headers: { 'Authorization': 'Bearer ' + (Auth.getToken() || '') },
        cache: 'no-store',
      });
      USAR_API = r.ok || r.status === 200;
    } catch {
      USAR_API = false;
    }
    return USAR_API;
  })();
  return _apiCheckPromise;
}

// Re-checar conectividade quando app volta ao foco
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) { _apiCheckPromise = null; _flushPendingSync(); }
});

// ── Fila de operações pendentes (offline → retry quando voltar online) ──
const PENDING_SYNC_KEY = 'intus-pending-sync';
function _getPendingSync() { try { return JSON.parse(localStorage.getItem(PENDING_SYNC_KEY) || '[]'); } catch { return []; } }
function _savePendingSync(q) { localStorage.setItem(PENDING_SYNC_KEY, JSON.stringify(q)); }
function _enqueuePending(endpoint, options) {
  const q = _getPendingSync();
  q.push({ endpoint, options, ts: Date.now() });
  _savePendingSync(q);
}
async function _flushPendingSync() {
  const q = _getPendingSync();
  if (!q.length) return;
  await checarAPI();
  if (!USAR_API) return;
  const failed = [];
  for (const item of q) {
    try {
      await apiFetch(item.endpoint, item.options);
    } catch {
      failed.push(item);
    }
  }
  _savePendingSync(failed);
  _updatePendingBadge();
}
function _updatePendingBadge() {
  const q = _getPendingSync();
  let badge = document.getElementById('pending-sync-badge');
  if (!q.length) { if (badge) badge.remove(); return; }
  if (!badge) {
    badge = document.createElement('div');
    badge.id = 'pending-sync-badge';
    badge.style.cssText = 'position:fixed;bottom:16px;right:16px;z-index:9999;background:#f59e0b;color:#000;font-size:11px;font-weight:700;padding:6px 12px;border-radius:8px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.3);';
    badge.onclick = () => { _flushPendingSync(); };
    document.body.appendChild(badge);
  }
  badge.textContent = `⏳ ${q.length} pendente${q.length > 1 ? 's' : ''} — clique p/ sincronizar`;
}
window.addEventListener('online', () => { _apiCheckPromise = null; _flushPendingSync(); });

// Auto-migrar token local- → prof- se backend estiver acessível
setTimeout(async () => {
  _updatePendingBadge();
  if (Auth.isLocalToken()) {
    try {
      const r = await fetch(API_BASE + '/atletas.php?busca=__ping__', {
        headers: { 'Authorization': 'Bearer ' + Auth.getToken() },
        cache: 'no-store',
      });
      if (r.ok) {
        const user = Auth.getUser();
        const newToken = 'prof-' + (user.idusuario || 0) + '-' + Date.now();
        Auth.setToken(newToken);
        USAR_API = true;
        _apiCheckPromise = null;
        _flushPendingSync();
      }
    } catch {}
  } else {
    _flushPendingSync();
  }
}, 1500);

// Fetch base com token (usado quando USAR_API=true)
async function apiFetch(endpoint, options = {}) {
  const token = Auth.getToken();

  if (Auth.isLocalToken()) {
    USAR_API = false;
    throw new Error('offline');
  }

  const res = await fetch(API_BASE + endpoint, {
    cache: 'no-store',
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-cache',
      ...(token ? { 'Authorization': 'Bearer ' + token } : {}),
      ...(options.headers || {}),
    },
  });

  if (res.status === 401) {
    if (options.skipAuthRedirect) {
      let data = {};
      try { data = await res.json(); } catch {}
      const err = new Error(data.error || 'Credenciais inválidas');
      err.status = 401;
      throw err;
    }
    // Tokens aluno-* e local-*: backend pode não ter auth.php mas aceita nos endpoints de dados
    if (Auth.isLocalToken() || Auth.isStudentToken()) {
      USAR_API = false;
      throw new Error('offline');
    }
    Auth.clear();
    window.location.href = 'login.html';
    return;
  }

  let data = {};
  try { data = await res.json(); } catch {}
  if (!res.ok) {
    const err = new Error(data.error || 'Erro na requisição');
    err.status = res.status;
    throw err;
  }
  return data;
}

// Helper: tenta backend, em caso de falha cai para função local
// pendingInfo: { endpoint, options } — se fornecido, enfileira para retry quando voltar online
async function tryRemoteOrLocal(remote, local, pendingInfo) {
  try {
    await checarAPI();
    if (!USAR_API) throw new Error('offline');
    return await remote();
  } catch (e) {
    if (pendingInfo) _enqueuePending(pendingInfo.endpoint, pendingInfo.options);
    return local();
  }
}

// ── ENDPOINTS ────────────────────────────────────────────────────────────
const API = {

  // Auth ────────────────────────────────────────────────────────────────
  loginProfessor: (email, senha) =>
    apiFetch('/auth.php?action=login_professor', {
      method: 'POST',
      body: JSON.stringify({ email, senha }),
      skipAuthRedirect: true,
    }),

  logout: () => {
    Auth.clear();
    return Promise.resolve(true);
  },

  me: () => apiFetch('/auth.php?action=me'),

  // Atletas / Clientes ──────────────────────────────────────────────────
  // Helper: atleta esta bloqueado (excluido logicamente)?
  // Mantido como funcao no escopo de API para reuso
  // (definida abaixo via API._isBloqueado)

  // Normaliza professores_responsaveis: sempre array de Number
  _normalizarProfs: (atleta) => {
    let p = atleta.professores_responsaveis;
    if (typeof p === 'string') { try { p = JSON.parse(p); } catch { p = []; } }
    if (!Array.isArray(p)) p = [];
    atleta.professores_responsaveis = p.map(Number).filter(n => n > 0);
    return atleta;
  },

  // Filtra atletas pelo professor logado (retorna todos se admin)
  filtrarMeusAtletas: (lista) => {
    const _usr = Sessao.atual();
    if (!_usr || _usr.admin || !_usr.idusuario) return lista;
    const uid = Number(_usr.idusuario);
    return lista.filter(a => {
      API._normalizarProfs(a);
      const p = a.professores_responsaveis;
      return p && Array.isArray(p) && p.includes(uid);
    });
  },

  // listarAtletas(busca, incluirBloqueados=false)
  //   Por padrao NAO retorna bloqueados — assim treinos, mensalidades, avaliacoes etc
  //   nunca mostram aluno excluido. A pagina de gestao (alunos.html) passa true
  //   para ver bloqueados (e poder restaurar).
  listarAtletas: (busca = '', incluirBloqueados = false) => tryRemoteOrLocal(
    async () => {
      const remoto = await apiFetch('/atletas.php' + (busca ? `?busca=${encodeURIComponent(busca)}` : ''));
      const arr = Array.isArray(remoto) ? remoto : (remoto && Array.isArray(remoto.data) ? remoto.data : []);
      const normalized = arr.map(a => API._normalizarProfs(a));
      if (!busca) Store.set('atletas', normalized);
      return incluirBloqueados ? normalized : normalized.filter(a => !API._isBloqueado(a));
    },
    () => {
      let lista = Store.get('atletas').map(a => API._normalizarProfs(a));
      if (busca) {
        const q = busca.toLowerCase();
        lista = lista.filter(a =>
          (a.nome  || '').toLowerCase().includes(q) ||
          (a.email || '').toLowerCase().includes(q));
      }
      return incluirBloqueados ? lista : lista.filter(a => !API._isBloqueado(a));
    }
  ),

  _isBloqueado: (a) => {
    if (!a) return false;
    const v = a.stbloqueio;
    return v === 'S' || v === 1 || v === '1' || v === true;
  },

  getAtleta: (id) => tryRemoteOrLocal(
    () => apiFetch(`/atletas.php?id=${id}`),
    () => Store.get('atletas').find(a => Number(a.idatleta) === Number(id)) || null
  ),

  criarAtleta: (data) => tryRemoteOrLocal(
    () => apiFetch('/atletas.php', { method: 'POST', body: JSON.stringify(data) }),
    () => {
      const lista = Store.get('atletas');
      const novo = {
        idatleta:   Store.nextId('atletas', 'idatleta'),
        nome:       data.nome || '',
        email:      data.email || '',
        genero:     data.genero || 'M',
        stbloqueio: data.stbloqueio || 'N',
        codacesso:  data.codacesso || ('INT' + Date.now().toString().slice(-4)),
        dtcadastro: new Date().toISOString().slice(0,10),
        dtnascimento: data.dtnascimento || null,
        telefone:   data.telefone || '',
        observacao: data.observacao || '',
      };
      lista.push(novo);
      Store.set('atletas', lista);
      return novo;
    }
  ),

  editarAtleta: (id, data) => tryRemoteOrLocal(
    () => apiFetch(`/atletas.php?id=${id}`, { method: 'PUT', body: JSON.stringify(data) }),
    () => {
      const lista = Store.get('atletas');
      const i = lista.findIndex(a => Number(a.idatleta) === Number(id));
      if (i < 0) throw new Error('Aluno não encontrado');
      lista[i] = Object.assign({}, lista[i], data);
      Store.set('atletas', lista);
      return lista[i];
    },
    { endpoint: `/atletas.php?id=${id}`, options: { method: 'PUT', body: JSON.stringify(data) } }
  ),

  bloquearAtleta: (id) => tryRemoteOrLocal(
    () => apiFetch(`/atletas.php?id=${id}`, { method: 'DELETE' }),
    () => {
      const lista = Store.get('atletas');
      const i = lista.findIndex(a => Number(a.idatleta) === Number(id));
      if (i < 0) throw new Error('Aluno não encontrado');
      lista[i].stbloqueio = 'S';
      Store.set('atletas', lista);
      return true;
    }
  ),

  excluirAtleta: (id) => tryRemoteOrLocal(
    () => apiFetch(`/atletas.php?id=${id}&action=excluir`, { method: 'DELETE' }),
    () => {
      // Usa Atletas.excluir do _mock.js — limpa também matrículas/fichas/treinos/avaliações
      // vinculadas ao cliente, evitando órfãos no store.
      if (typeof Atletas !== 'undefined' && typeof Atletas.excluir === 'function') {
        return Atletas.excluir(id);
      }
      // fallback ingênuo se _mock.js não estiver carregado
      const lista = Store.get('atletas').filter(a => Number(a.idatleta) !== Number(id));
      Store.set('atletas', lista);
      return { ok: true };
    }
  ),

  // Exercícios ──────────────────────────────────────────────────────────
  listarExercicios: () => tryRemoteOrLocal(
    async () => {
      const lista = await apiFetch('/treinos.php?action=exercicios');
      const local = Store.get('exercicios');
      if (Array.isArray(lista) && lista.length > 0) {
        // Merge: backend + locais que NÃO existem no backend (por ID nem por nome)
        const idsBackend = new Set(lista.map(e => Number(e.idexercicio)));
        const nomesBackend = new Set(lista.map(e => (e.nmexercicio||'').trim().toLowerCase()));
        const somenteLocal = local.filter(e =>
          !idsBackend.has(Number(e.idexercicio)) &&
          !nomesBackend.has((e.nmexercicio||'').trim().toLowerCase())
        );
        const merged = [...lista, ...somenteLocal];
        Store.set('exercicios', merged);
        // Push exercícios locais faltantes para o backend em background
        if (somenteLocal.length > 0 && !API._syncingEx) {
          API._syncingEx = true;
          (async () => {
            for (const ex of somenteLocal) {
              if (!ex || !ex.nmexercicio) continue;
              try {
                await apiFetch('/treinos.php?action=exercicios', {
                  method: 'POST',
                  body: JSON.stringify({ nmexercicio: ex.nmexercicio, grupo: ex.grupo || '', grupos: ex.grupos || null, observacao: ex.observacao || '', descricao: ex.descricao || '', videoyoutube: ex.videoyoutube || '', substitutos: ex.substitutos || null }),
                });
              } catch {}
            }
            API._syncingEx = false;
          })();
        }
        return merged;
      }
      if (local.length > 0) {
        if (!API._syncingEx) {
          API._syncingEx = true;
          API.syncExerciseCatalog().finally(() => { API._syncingEx = false; });
        }
        return local;
      }
      return [];
    },
    () => Store.get('exercicios')
  ),

  criarExercicio: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/treinos.php?action=exercicios', { method: 'POST', body: JSON.stringify(data) });
      // Sync local after create
      let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean) : null;
      if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
      const grupoStr = grupos && grupos.length ? grupos.join(', ') : (data.grupo || null);
      const novo = {
        idexercicio:  res.idexercicio || Store.nextId('exercicios', 'idexercicio'),
        nmexercicio:  data.nmexercicio || '',
        grupo:        grupoStr,
        grupos:       grupos && grupos.length ? grupos : null,
        descricao:    data.descricao || null,
        videoyoutube: data.videoyoutube || null,
        observacao:   data.observacao || null,
        gifexercicio: data.gifexercicio || null,
        instrucao_ia: data.instrucao_ia || null,
        substitutos:  Array.isArray(data.substitutos) ? data.substitutos.map(Number) : [],
      };
      const lista = Store.get('exercicios');
      lista.push(novo);
      Store.set('exercicios', lista);
      return novo;
    },
    () => {
      const lista = Store.get('exercicios');
      let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean) : null;
      if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
      const grupoStr = grupos && grupos.length ? grupos.join(', ') : (data.grupo || null);
      const novo = {
        idexercicio:  Store.nextId('exercicios', 'idexercicio'),
        nmexercicio:  data.nmexercicio || '',
        grupo:        grupoStr,
        grupos:       grupos && grupos.length ? grupos : null,
        descricao:    data.descricao || null,
        videoyoutube: data.videoyoutube || null,
        observacao:   data.observacao || null,
        gifexercicio: data.gifexercicio || null,
        instrucao_ia: data.instrucao_ia || null,
        substitutos:  Array.isArray(data.substitutos) ? data.substitutos.map(Number) : [],
      };
      lista.push(novo);
      Store.set('exercicios', lista);
      return novo;
    }
  ),

  editarExercicio: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/treinos.php?action=exercicios', { method: 'PUT', body: JSON.stringify({ ...data, idexercicio: id }) });
      // Sync local
      const lista = Store.get('exercicios');
      const i = lista.findIndex(e => Number(e.idexercicio) === Number(id));
      if (i >= 0) {
        const merged = Object.assign({}, lista[i], data);
        let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean)
                  : (Array.isArray(merged.grupos) ? merged.grupos.filter(Boolean) : null);
        if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
        if (grupos && grupos.length) { merged.grupos = grupos; merged.grupo = grupos.join(', '); }
        lista[i] = merged;
        Store.set('exercicios', lista);
        return merged;
      }
      return data;
    },
    () => {
      const lista = Store.get('exercicios');
      const i = lista.findIndex(e => Number(e.idexercicio) === Number(id));
      if (i < 0) throw new Error('Exercício não encontrado');
      const merged = Object.assign({}, lista[i], data);
      let grupos = Array.isArray(data.grupos) ? data.grupos.filter(Boolean)
                : (Array.isArray(merged.grupos) ? merged.grupos.filter(Boolean) : null);
      if (!grupos && data.grupo) grupos = String(data.grupo).split(/[,;]\s*/).map(s => s.trim()).filter(Boolean);
      if (grupos && grupos.length) { merged.grupos = grupos; merged.grupo = grupos.join(', '); }
      lista[i] = merged;
      Store.set('exercicios', lista);
      return lista[i];
    }
  ),

  excluirExercicio: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/treinos.php?action=exercicios&id=' + id, { method: 'DELETE' });
      const lista = Store.get('exercicios').filter(e => Number(e.idexercicio) !== Number(id));
      Store.set('exercicios', lista);
      return true;
    },
    () => {
      const lista = Store.get('exercicios').filter(e => Number(e.idexercicio) !== Number(id));
      Store.set('exercicios', lista);
      return true;
    }
  ),

  // Alongamentos (backend-first via catalogo.php) ──────────────────────
  listarAlongamentos: () => tryRemoteOrLocal(
    async () => {
      const lista = await apiFetch('/catalogo.php?action=alongamentos');
      const local = Store.get('alongamentos');
      if (Array.isArray(lista) && lista.length > 0) {
        Store.set('alongamentos', lista);
        return lista;
      }
      return local.length > 0 ? local : (lista || []);
    },
    () => Store.get('alongamentos')
  ),

  criarAlongamento: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/catalogo.php?action=alongamentos', { method: 'POST', body: JSON.stringify(data) });
      const novo = { idalongamento: res.idalongamento, grupo: data.grupo || 'Geral', nome: data.nome || '', tempo: data.tempo || '30s', obs: data.obs || '', descricao: data.descricao || null, videoyoutube: data.videoyoutube || null, gifalongamento: data.gifalongamento || null, instrucao_ia: data.instrucao_ia || null };
      const lista = Store.get('alongamentos'); lista.push(novo); Store.set('alongamentos', lista);
      return novo;
    },
    () => {
      const lista = Store.get('alongamentos');
      const novo = { idalongamento: Store.nextId('alongamentos', 'idalongamento'), grupo: data.grupo || 'Geral', nome: data.nome || '', tempo: data.tempo || '30s', obs: data.obs || '', descricao: data.descricao || null, videoyoutube: data.videoyoutube || null };
      lista.push(novo); Store.set('alongamentos', lista);
      return novo;
    }
  ),

  editarAlongamento: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=alongamentos', { method: 'PUT', body: JSON.stringify({ ...data, idalongamento: id }) });
      const lista = Store.get('alongamentos');
      const i = lista.findIndex(x => Number(x.idalongamento) === Number(id));
      if (i >= 0) { lista[i] = Object.assign({}, lista[i], data); Store.set('alongamentos', lista); return lista[i]; }
      return data;
    },
    () => {
      const lista = Store.get('alongamentos');
      const i = lista.findIndex(x => Number(x.idalongamento) === Number(id));
      if (i < 0) throw new Error('Alongamento não encontrado');
      lista[i] = Object.assign({}, lista[i], data); Store.set('alongamentos', lista);
      return lista[i];
    }
  ),

  excluirAlongamento: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=alongamentos&id=' + id, { method: 'DELETE' });
      const lista = Store.get('alongamentos').filter(x => Number(x.idalongamento) !== Number(id));
      Store.set('alongamentos', lista);
      return true;
    },
    () => {
      const lista = Store.get('alongamentos').filter(x => Number(x.idalongamento) !== Number(id));
      Store.set('alongamentos', lista);
      return true;
    }
  ),

  // Treinos / Fichas (backend-first, espelha em Store p/ ficar offline-friendly) ─
  // Helper: faz upsert numa lista do Store por chave
  _upsertStore: (key, idField, item) => {
    const lista = Store.get(key);
    const i = lista.findIndex(x => Number(x[idField]) === Number(item[idField]));
    if (i >= 0) lista[i] = Object.assign({}, lista[i], item);
    else lista.push(item);
    Store.set(key, lista);
  },

  // Lista TODAS as fichas (cross-aluno) - para uso em treinos.html / dashboard
  listarFichas: () => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch('/treinos.php?action=fichas');
      const lista = Array.isArray(arr) ? arr : [];
      Store.set('fichas', lista); // espelha
      return lista;
    },
    () => Store.get('fichas')
  ),

  // Lista TODOS os exercicios prescritos (cross-ficha) - para uso em treinos.html
  listarTodosTreinos: () => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch('/treinos.php?action=treinos');
      const lista = Array.isArray(arr) ? arr : [];
      Store.set('treinos', lista);
      return lista;
    },
    () => Store.get('treinos')
  ),

  getFichaAtleta: (idatleta) => tryRemoteOrLocal(
    async () => {
      const f = await apiFetch(`/treinos.php?action=ficha&atleta=${idatleta}`);
      if (f && f.idficha) API._upsertStore('fichas', 'idficha', f);
      return f;
    },
    () => Store.get('fichas').find(f => Number(f.idatleta) === Number(idatleta)) || null
  ),

  criarFicha: (data) => tryRemoteOrLocal(
    async () => {
      const f = await apiFetch('/treinos.php?action=ficha', { method: 'POST', body: JSON.stringify(data) });
      if (f && f.idficha) API._upsertStore('fichas', 'idficha', f);
      return f;
    },
    () => {
      const lista = Store.get('fichas');
      const novo = {
        idficha:      Store.nextId('fichas', 'idficha'),
        idatleta:     data.idatleta,
        nmficha:      data.nmficha || 'Ficha',
        dtinicio:     data.dtinicio || new Date().toISOString().slice(0,10),
        dtfim:        data.dtfim || null,
        observacao:   data.observacao || '',
        ativo:        data.ativo !== undefined ? data.ativo : 1,
        alongamentos: data.alongamentos || null,
        divNomes:     data.divNomes || null,
      };
      lista.push(novo);
      Store.set('fichas', lista);
      return novo;
    }
  ),

  editarFicha: (id, data) => tryRemoteOrLocal(
    async () => {
      const f = await apiFetch(`/treinos.php?action=ficha&id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
      if (f && f.idficha) API._upsertStore('fichas', 'idficha', f);
      return f;
    },
    () => {
      const lista = Store.get('fichas');
      const i = lista.findIndex(f => Number(f.idficha) === Number(id));
      if (i < 0) throw new Error('Ficha não encontrada');
      lista[i] = Object.assign({}, lista[i], data);
      Store.set('fichas', lista);
      return lista[i];
    }
  ),

  excluirFicha: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch(`/treinos.php?action=ficha&id=${id}`, { method: 'DELETE' });
      const lista = Store.get('fichas').filter(f => Number(f.idficha) !== Number(id));
      Store.set('fichas', lista);
      return true;
    },
    () => {
      const lista = Store.get('fichas').filter(f => Number(f.idficha) !== Number(id));
      Store.set('fichas', lista);
      return true;
    }
  ),

  getTreinosDaFicha: (idficha) => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch(`/treinos.php?action=treinos&ficha=${idficha}`);
      const lista = Array.isArray(arr) ? arr : [];
      // Espelha estes registros no Store mantendo os de outras fichas intactos
      const others = Store.get('treinos').filter(t => Number(t.idficha) !== Number(idficha));
      Store.set('treinos', others.concat(lista));
      return lista;
    },
    () => Store.get('treinos').filter(t => Number(t.idficha) === Number(idficha))
  ),

  adicionarExercicioFicha: (data) => tryRemoteOrLocal(
    async () => {
      const t = await apiFetch('/treinos.php?action=treinos', { method: 'POST', body: JSON.stringify(data) });
      if (t && t.idtreino) API._upsertStore('treinos', 'idtreino', t);
      return t;
    },
    () => {
      const lista = Store.get('treinos');
      const novo = {
        idtreino:       Store.nextId('treinos', 'idtreino'),
        idficha:        data.idficha,
        idexercicio:    data.idexercicio,
        nmexercicio:    data.nmexercicio || '',
        qtdserie:       data.qtdserie || 3,
        qtdrepeticao:   data.qtdrepeticao || 12,
        repeticoes:     data.repeticoes || '',
        tempopausa:     data.tempopausa || 60,
        intervalo:      data.intervalo || '',
        metodo:         data.metodo || '',
        tut:            data.tut || '',
        falha:          data.falha || false,
        unilateral:     data.unilateral || false,
        cargas:         data.cargas || [],
        reps_por_serie: data.reps_por_serie || [],
        observacao:     data.observacao || '',
        divisao:        data.divisao || 'A',
        substitutos:    data.substitutos === undefined ? null
                        : (data.substitutos === null ? null : data.substitutos.map(Number)),
      };
      lista.push(novo);
      Store.set('treinos', lista);
      return novo;
    }
  ),

  editarExercicioFicha: (id, data) => tryRemoteOrLocal(
    async () => {
      const t = await apiFetch(`/treinos.php?action=treinos&id=${id}`, { method: 'PUT', body: JSON.stringify(data) });
      if (t && t.idtreino) API._upsertStore('treinos', 'idtreino', t);
      return t;
    },
    () => {
      const lista = Store.get('treinos');
      const i = lista.findIndex(t => Number(t.idtreino) === Number(id));
      if (i < 0) throw new Error('Treino não encontrado');
      lista[i] = Object.assign({}, lista[i], data);
      Store.set('treinos', lista);
      return lista[i];
    }
  ),

  removerExercicioFicha: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch(`/treinos.php?action=treinos&id=${id}`, { method: 'DELETE' });
      const lista = Store.get('treinos').filter(t => Number(t.idtreino) !== Number(id));
      Store.set('treinos', lista);
      return true;
    },
    () => {
      const lista = Store.get('treinos').filter(t => Number(t.idtreino) !== Number(id));
      Store.set('treinos', lista);
      return true;
    }
  ),

  // Sessões de treino (histórico) ──────────────────────────────────────
  listarSessoes: (idatleta) => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch(`/treinos.php?action=sessoes&atleta=${idatleta}`);
      return Array.isArray(arr) ? arr : [];
    },
    () => {
      const all = JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]');
      return all.filter(s => Number(s.idatleta) === Number(idatleta)).sort((a, b) => (b.dtsessao || '').localeCompare(a.dtsessao || ''));
    }
  ),

  listarTodasSessoes: () => tryRemoteOrLocal(
    async () => {
      const arr = await apiFetch('/treinos.php?action=sessoes');
      return Array.isArray(arr) ? arr : [];
    },
    () => JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]')
  ),

  criarSessao: (data) => tryRemoteOrLocal(
    async () => {
      const r = await apiFetch('/treinos.php?action=sessoes', { method: 'POST', body: JSON.stringify(data) });
      return r;
    },
    () => {
      const all = JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]');
      data.idsessao = (all.reduce((m, x) => Math.max(m, x.idsessao || 0), 0) + 1) || 1;
      all.push(data);
      localStorage.setItem('intus-sessoes-treino', JSON.stringify(all));
      return data;
    }
  ),

  excluirSessao: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch(`/treinos.php?action=sessoes&id=${id}`, { method: 'DELETE' });
      const all = JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]');
      const filtered = all.filter(s => Number(s.idsessao) !== Number(id));
      localStorage.setItem('intus-sessoes-treino', JSON.stringify(filtered));
      return true;
    },
    () => {
      const all = JSON.parse(localStorage.getItem('intus-sessoes-treino') || '[]');
      const filtered = all.filter(s => Number(s.idsessao) !== Number(id));
      localStorage.setItem('intus-sessoes-treino', JSON.stringify(filtered));
      return true;
    }
  ),

  // Dashboard ───────────────────────────────────────────────────────────
  getDashboard: () => tryRemoteOrLocal(
    () => apiFetch('/dashboard.php'),
    () => {
      const atletas = Store.get('atletas');
      const mens    = Store.get('mensalidades');
      const fichas  = Store.get('fichas');
      const hoje    = new Date().toISOString().slice(0,10);

      const vencidas = mens
        .filter(m => m.stpgto !== 'S' && (m.dtvencimento || '') < hoje)
        .map(m => {
          const a = atletas.find(x => Number(x.idatleta) === Number(m.idatleta)) || {};
          return {
            nome: m.nmathleta || a.nome || '—',
            dtvencimento: m.dtvencimento,
            vlpagar: m.vlpagar,
            professor: ''
          };
        });

      const inativos = atletas
        .filter(a => !fichas.some(f => Number(f.idatleta) === Number(a.idatleta)))
        .slice(0, 10)
        .map(a => ({ nome: a.nome, dt: '—' }));

      return {
        resumo: {
          total_atletas: atletas.length,
          atletas_ativos: atletas.filter(a => a.stbloqueio !== 'S').length,
          mens_vencidas: vencidas.length,
          msgs_nao_lidas: Store.get('mensagens').filter(m => m.stlido === 'N').length,
        },
        treinos_inativos: inativos,
        mensalidades_vencidas: vencidas,
      };
    }
  ),

  // Mensalidades ────────────────────────────────────────────────────────
  listarMensalidades: (filtro = '') => tryRemoteOrLocal(
    () => apiFetch('/mensalidades.php' + (filtro ? `?status=${filtro}` : '')),
    () => {
      const atletas = Store.get('atletas');
      let mens = Store.get('mensalidades').map(m => {
        const a = atletas.find(x => Number(x.idatleta) === Number(m.idatleta));
        return { ...m, nmathleta: m.nmathleta || (a ? a.nome : '—') };
      });
      if (filtro === 'pago')     mens = mens.filter(m => m.stpgto === 'S');
      if (filtro === 'pendente') mens = mens.filter(m => m.stpgto !== 'S');
      return mens;
    }
  ),

  getMensalidadesAtleta: (idatleta) => tryRemoteOrLocal(
    () => apiFetch(`/mensalidades.php?atleta=${idatleta}`),
    () => Store.get('mensalidades').filter(m => Number(m.idatleta) === Number(idatleta))
  ),

  criarMensalidade: (data) => tryRemoteOrLocal(
    () => apiFetch('/mensalidades.php', { method: 'POST', body: JSON.stringify(data) }),
    () => {
      const lista = Store.get('mensalidades');
      const atletas = Store.get('atletas');
      const atleta = atletas.find(a => Number(a.idatleta) === Number(data.idatleta));
      const novo = {
        idmensalidade: Store.nextId('mensalidades', 'idmensalidade'),
        idatleta:      data.idatleta,
        nmathleta:     atleta ? atleta.nome : '—',
        dshistorico:   data.dshistorico || 'Mensalidade',
        dtinicio:      data.dtinicio || null,
        dtvencimento:  data.dtvencimento,
        vlpagar:       parseFloat(data.vlpagar || 0),
        vldesconto:    parseFloat(data.vldesconto || 0),
        vljuro:        parseFloat(data.vljuro || 0),
        stpgto:        data.stpgto || 'N',
        dtpagamento:   data.dtpagamento || null,
        vlpagamento:   data.vlpagamento != null ? parseFloat(data.vlpagamento) : null,
        dspagamento:   data.dspagamento || null,
      };
      lista.push(novo);
      Store.set('mensalidades', lista);
      return novo;
    },
    { endpoint: '/mensalidades.php', options: { method: 'POST', body: JSON.stringify(data) } }
  ),

  editarMensalidade: (id, data) => tryRemoteOrLocal(
    () => apiFetch(`/mensalidades.php?id=${id}`, { method: 'PUT', body: JSON.stringify(data) }),
    () => {
      const lista = Store.get('mensalidades');
      const i = lista.findIndex(m => Number(m.idmensalidade) === Number(id));
      if (i < 0) throw new Error('Mensalidade não encontrada');
      lista[i] = Object.assign({}, lista[i], data);
      Store.set('mensalidades', lista);
      return lista[i];
    },
    { endpoint: `/mensalidades.php?id=${id}`, options: { method: 'PUT', body: JSON.stringify(data) } }
  ),

  pagarMensalidade: (m, dtpagamento, vlpagamento) => API.editarMensalidade(m.idmensalidade, {
    dshistorico:  m.dshistorico || 'Mensalidade',
    dtvencimento: m.dtvencimento,
    vlpagar:      m.vlpagar,
    stpgto:       'S',
    vldesconto:   m.vldesconto || 0,
    vljuro:       m.vljuro || 0,
    dtpagamento:  dtpagamento || new Date().toISOString().slice(0,10),
    vlpagamento:  (vlpagamento != null ? vlpagamento : m.vlpagar),
    dspagamento:  'Pagamento registrado',
  }),

  excluirMensalidade: (id) => tryRemoteOrLocal(
    () => apiFetch(`/mensalidades.php?id=${id}`, { method: 'DELETE' }),
    () => {
      const lista = Store.get('mensalidades').filter(m => Number(m.idmensalidade) !== Number(id));
      Store.set('mensalidades', lista);
      return true;
    }
  ),

  // Mensagens / Chat ────────────────────────────────────────────────────
  listarMensagens: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/mensagens.php?action=listar' + (idatleta ? '&atleta=' + idatleta : '');
      const lista = await apiFetch(url);
      if (Array.isArray(lista)) Store.set('mensagens', lista);
      return lista;
    },
    () => {
      const todas = Store.get('mensagens');
      return idatleta ? todas.filter(m => Number(m.idatleta) === Number(idatleta)) : todas;
    }
  ),

  enviarMensagem: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/mensagens.php?action=enviar', { method: 'POST', body: JSON.stringify(data) });
      if (res.ok && res.mensagem) {
        const lista = Store.get('mensagens');
        lista.push(res.mensagem);
        Store.set('mensagens', lista);
      }
      return res.mensagem || res;
    },
    () => {
      const lista = Store.get('mensagens');
      const novo = {
        idmensagem: Store.nextId('mensagens', 'idmensagem'),
        idatleta:   data.idatleta,
        idusuario:  data.idusuario || null,
        remetente:  data.remetente || 'aluno',
        texto:      data.texto,
        stlido:     'N',
        dtmensagem: new Date().toISOString().replace('T',' ').slice(0,19),
      };
      lista.push(novo);
      Store.set('mensagens', lista);
      return novo;
    },
    { endpoint: '/mensagens.php?action=enviar', options: { method: 'POST', body: JSON.stringify(data) } }
  ),

  marcarMensagensLidas: (ids) => tryRemoteOrLocal(
    () => apiFetch('/mensagens.php?action=marcar_lida', { method: 'POST', body: JSON.stringify({ ids }) }),
    () => {
      const lista = Store.get('mensagens');
      ids.forEach(id => { const m = lista.find(x => Number(x.idmensagem) === Number(id)); if (m) m.stlido = 'S'; });
      Store.set('mensagens', lista);
      return { ok: true };
    }
  ),

  excluirMensagem: (id) => tryRemoteOrLocal(
    () => apiFetch(`/mensagens.php?action=excluir&id=${id}`, { method: 'DELETE' }),
    () => {
      const lista = Store.get('mensagens').filter(m => Number(m.idmensagem) !== Number(id));
      Store.set('mensagens', lista);
      return true;
    }
  ),

  listarComentarios: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/mensagens.php?action=comentarios' + (idatleta ? '&atleta=' + idatleta : '');
      return await apiFetch(url);
    },
    () => {
      const sessoes = JSON.parse(localStorage.getItem('intus-sessoes') || '[]');
      return sessoes.filter(s => s.comentario && (!idatleta || Number(s.idatleta) === Number(idatleta)))
        .map((s, i) => ({
          idcomentario: i + 1, idatleta: s.idatleta, idficha: s.idficha,
          divisao: s.divisao, dtsessao: s.dtsessao, texto: s.comentario,
          dtcriacao: s.dtsessao + ' 00:00:00', reacoes: [],
        }));
    }
  ),

  reagirComentario: (data) => tryRemoteOrLocal(
    () => apiFetch('/mensagens.php?action=reagir', { method: 'POST', body: JSON.stringify(data) }),
    () => { return { ok: true }; },
    { endpoint: '/mensagens.php?action=reagir', options: { method: 'POST', body: JSON.stringify(data) } }
  ),

  salvarComentarioBackend: (data) => tryRemoteOrLocal(
    () => apiFetch('/mensagens.php?action=salvar_comentario', { method: 'POST', body: JSON.stringify(data) }),
    () => { return { ok: true }; },
    { endpoint: '/mensagens.php?action=salvar_comentario', options: { method: 'POST', body: JSON.stringify(data) } }
  ),

  // Desafios ────────────────────────────────────────────────────────────
  listarPlanosCatalogo: () => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/desafios.php?action=planos');
      if (Array.isArray(res)) localStorage.setItem('intus-planos-catalogo', JSON.stringify(res));
      return res;
    },
    () => JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]')
  ),

  criarPlanoCatalogo: (data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=planos', { method: 'POST', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]');
      const novo = { idplano: Date.now(), ...data, ativo: true };
      lista.push(novo);
      localStorage.setItem('intus-planos-catalogo', JSON.stringify(lista));
      return { ok: true, idplano: novo.idplano };
    }
  ),

  editarPlanoCatalogo: (id, data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=planos&id=' + id, { method: 'PUT', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]');
      const p = lista.find(x => Number(x.idplano) === Number(id));
      if (p) Object.assign(p, data);
      localStorage.setItem('intus-planos-catalogo', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  excluirPlanoCatalogo: (id) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=planos&id=' + id, { method: 'DELETE' }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-planos-catalogo') || '[]').filter(x => Number(x.idplano) !== Number(id));
      localStorage.setItem('intus-planos-catalogo', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  listarDesafios: () => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/desafios.php?action=desafios');
      if (Array.isArray(res)) localStorage.setItem('intus-desafios', JSON.stringify(res));
      return res;
    },
    () => JSON.parse(localStorage.getItem('intus-desafios') || '[]')
  ),

  obterDesafio: (id) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafio&id=' + id),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]');
      return lista.find(d => Number(d.iddesafio) === Number(id)) || null;
    }
  ),

  criarDesafio: (data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafios', { method: 'POST', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]');
      const novo = { iddesafio: Date.now(), ...data, ativo: true, participantes_count: (data.participantes || []).length };
      lista.push(novo);
      localStorage.setItem('intus-desafios', JSON.stringify(lista));
      return { ok: true, iddesafio: novo.iddesafio };
    }
  ),

  editarDesafio: (id, data) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafios&id=' + id, { method: 'PUT', body: JSON.stringify(data) }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]');
      const d = lista.find(x => Number(x.iddesafio) === Number(id));
      if (d) Object.assign(d, data);
      localStorage.setItem('intus-desafios', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  excluirDesafio: (id) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=desafios&id=' + id, { method: 'DELETE' }),
    () => {
      const lista = JSON.parse(localStorage.getItem('intus-desafios') || '[]').filter(x => Number(x.iddesafio) !== Number(id));
      localStorage.setItem('intus-desafios', JSON.stringify(lista));
      return { ok: true };
    }
  ),

  listarMensagensGrupo: (iddesafio) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=mensagens_grupo&desafio=' + iddesafio),
    () => JSON.parse(localStorage.getItem('intus-desafio-msgs-' + iddesafio) || '[]')
  ),

  enviarMensagemGrupo: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/desafios.php?action=mensagens_grupo', { method: 'POST', body: JSON.stringify(data) });
      return res.mensagem || res;
    },
    () => {
      const key = 'intus-desafio-msgs-' + data.iddesafio;
      const lista = JSON.parse(localStorage.getItem(key) || '[]');
      const novo = { idmensagem: Date.now(), ...data, dtmensagem: new Date().toISOString().replace('T',' ').slice(0,19) };
      lista.push(novo);
      localStorage.setItem(key, JSON.stringify(lista));
      return novo;
    }
  ),

  rankingDesafio: (iddesafio) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=ranking&desafio=' + iddesafio),
    () => []
  ),

  meusDesafios: (idatleta) => tryRemoteOrLocal(
    () => apiFetch('/desafios.php?action=meus_desafios&atleta=' + idatleta),
    () => JSON.parse(localStorage.getItem('intus-desafios') || '[]').filter(d => d.ativo)
  ),

  // Avaliações (backend-first via catalogo.php) ────────────────────────
  listarAvaliacoes: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/catalogo.php?action=avaliacoes' + (idatleta ? '&atleta=' + idatleta : '');
      const lista = await apiFetch(url);
      return Array.isArray(lista) ? lista : [];
    },
    () => {
      const todas = Store.get('avaliacoes');
      return idatleta ? todas.filter(x => Number(x.idatleta) === Number(idatleta)) : todas;
    }
  ),

  criarAvaliacao: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/catalogo.php?action=avaliacoes', { method: 'POST', body: JSON.stringify(data) });
      return { idavaliacao: res.idavaliacao, ...data };
    },
    () => {
      const lista = Store.get('avaliacoes');
      const nova = {
        idavaliacao: Store.nextId('avaliacoes', 'idavaliacao'),
        idatleta:    data.idatleta,
        dtavaliacao: data.dtavaliacao || new Date().toISOString().slice(0,10),
        peso:        parseFloat(data.peso || 0),
        altura:      parseFloat(data.altura || 0),
        percgordura: data.percgordura != null ? parseFloat(data.percgordura) : null,
        observacao:  data.observacao || '',
        medidas:     data.medidas || null,
        metodo:      data.metodo || null,
        fotos:       data.fotos || null,
      };
      lista.push(nova); Store.set('avaliacoes', lista);
      return nova;
    }
  ),

  editarAvaliacao: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=avaliacoes', { method: 'PUT', body: JSON.stringify({ ...data, idavaliacao: id }) });
      return { idavaliacao: id, ...data };
    },
    () => {
      const lista = Store.get('avaliacoes');
      const i = lista.findIndex(x => Number(x.idavaliacao) === Number(id));
      if (i < 0) throw new Error('Avaliação não encontrada');
      lista[i] = Object.assign({}, lista[i], data); Store.set('avaliacoes', lista);
      return lista[i];
    }
  ),

  excluirAvaliacao: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=avaliacoes&id=' + id, { method: 'DELETE' });
      return true;
    },
    () => {
      const lista = Store.get('avaliacoes').filter(x => Number(x.idavaliacao) !== Number(id));
      Store.set('avaliacoes', lista);
      return true;
    }
  ),

  // Nutrição — Planos Nutricionais (backend-first via catalogo.php) ────
  listarPlanosNutricionais: (idatleta) => tryRemoteOrLocal(
    async () => {
      const url = '/catalogo.php?action=nutricao' + (idatleta ? '&atleta=' + idatleta : '');
      const lista = await apiFetch(url);
      return Array.isArray(lista) ? lista : [];
    },
    () => {
      const todos = Store.get('nutricao');
      return idatleta ? todos.filter(x => Number(x.idatleta) === Number(idatleta)) : todos;
    }
  ),

  criarPlanoNutricional: (data) => tryRemoteOrLocal(
    async () => {
      const res = await apiFetch('/catalogo.php?action=nutricao', { method: 'POST', body: JSON.stringify(data) });
      return { idplano: res.idplano, ...data };
    },
    () => {
      const lista = Store.get('nutricao');
      const novo = {
        idplano:     Store.nextId('nutricao', 'idplano'),
        idatleta:    data.idatleta,
        titulo:      data.titulo || 'Plano Alimentar',
        objetivo:    data.objetivo || '',
        calorias:    data.calorias || null,
        proteina:    data.proteina || null,
        carboidrato: data.carboidrato || null,
        gordura:     data.gordura || null,
        refeicoes:   data.refeicoes || null,
        observacao:  data.observacao || '',
        comentario_inicio: data.comentario_inicio || '',
        comentario_fim:    data.comentario_fim || '',
        ativo:       data.ativo != null ? data.ativo : 1,
        dtinicio:    data.dtinicio || null,
        dtfim:       data.dtfim || null,
      };
      lista.push(novo); Store.set('nutricao', lista);
      return novo;
    }
  ),

  editarPlanoNutricional: (id, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=nutricao', { method: 'PUT', body: JSON.stringify({ ...data, idplano: id }) });
      return { idplano: id, ...data };
    },
    () => {
      const lista = Store.get('nutricao');
      const i = lista.findIndex(x => Number(x.idplano) === Number(id));
      if (i < 0) throw new Error('Plano não encontrado');
      lista[i] = Object.assign({}, lista[i], data); Store.set('nutricao', lista);
      return lista[i];
    }
  ),

  excluirPlanoNutricional: (id) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=nutricao&id=' + id, { method: 'DELETE' });
      return true;
    },
    () => {
      const lista = Store.get('nutricao').filter(x => Number(x.idplano) !== Number(id));
      Store.set('nutricao', lista);
      return true;
    }
  ),

  // Planos Config (backend-synced via catalogo.php) ───────────────────
  getPlanosConfig: () => tryRemoteOrLocal(
    async () => {
      const cfg = await apiFetch('/catalogo.php?action=planos_config');
      if (cfg && typeof cfg === 'object') localStorage.setItem('intus-planos-config', JSON.stringify(cfg));
      return cfg || {};
    },
    () => JSON.parse(localStorage.getItem('intus-planos-config') || '{}')
  ),

  savePlanosConfig: (cfg) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=planos_config', { method: 'PUT', body: JSON.stringify(cfg) });
      localStorage.setItem('intus-planos-config', JSON.stringify(cfg));
      return true;
    },
    () => { localStorage.setItem('intus-planos-config', JSON.stringify(cfg)); return true; }
  ),

  // Profile (avatar + prefs, backend-synced via catalogo.php) ─────────
  getAvatars: () => tryRemoteOrLocal(
    async () => {
      const map = await apiFetch('/catalogo.php?action=avatars');
      if (map && typeof map === 'object') localStorage.setItem('intus-avatars', JSON.stringify(map));
      return map || {};
    },
    () => JSON.parse(localStorage.getItem('intus-avatars') || '{}')
  ),

  getUserAvatars: () => tryRemoteOrLocal(
    async () => {
      const map = await apiFetch('/usuarios.php?action=avatars_usuarios');
      if (map && typeof map === 'object') localStorage.setItem('intus-user-avatars', JSON.stringify(map));
      return map || {};
    },
    () => JSON.parse(localStorage.getItem('intus-user-avatars') || '{}')
  ),

  saveUserAvatar: (idusuario, avatar) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/usuarios.php?action=avatar&uid=' + idusuario, { method: 'POST', body: JSON.stringify({ idusuario, avatar }) });
      const map = JSON.parse(localStorage.getItem('intus-user-avatars') || '{}');
      if (avatar) map[idusuario] = avatar; else delete map[idusuario];
      localStorage.setItem('intus-user-avatars', JSON.stringify(map));
      return true;
    },
    () => {
      const map = JSON.parse(localStorage.getItem('intus-user-avatars') || '{}');
      if (avatar) map[idusuario] = avatar; else delete map[idusuario];
      localStorage.setItem('intus-user-avatars', JSON.stringify(map));
      return true;
    }
  ),

  saveUserCargo: (idusuario, cargo) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/usuarios.php?action=cargo&uid=' + idusuario, { method: 'POST', body: JSON.stringify({ idusuario, cargo }) });
      return true;
    },
    () => true
  ),

  saveUserMeta: (idusuario, meta) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/usuarios.php?action=user_meta&uid=' + idusuario, { method: 'POST', body: JSON.stringify({ idusuario, ...meta }) });
      return true;
    },
    () => true
  ),

  getProfile: (idatleta) => tryRemoteOrLocal(
    async () => {
      const p = await apiFetch('/catalogo.php?action=profile&atleta=' + idatleta);
      if (p) localStorage.setItem('intus-profile-' + idatleta, JSON.stringify(p));
      return p || {};
    },
    () => JSON.parse(localStorage.getItem('intus-profile-' + idatleta) || '{}')
  ),

  saveProfile: (idatleta, data) => tryRemoteOrLocal(
    async () => {
      await apiFetch('/catalogo.php?action=profile&atleta=' + idatleta, { method: 'PUT', body: JSON.stringify({ idatleta, ...data }) });
      const ex = JSON.parse(localStorage.getItem('intus-profile-' + idatleta) || '{}');
      localStorage.setItem('intus-profile-' + idatleta, JSON.stringify(Object.assign({}, ex, data)));
      return true;
    },
    () => {
      const ex = JSON.parse(localStorage.getItem('intus-profile-' + idatleta) || '{}');
      localStorage.setItem('intus-profile-' + idatleta, JSON.stringify(Object.assign({}, ex, data)));
      return true;
    }
  ),

  // Usuários (BACKEND-FIRST com cache local) ───────────────────────────
  // Backend é a fonte de verdade quando a sessão é remota. Quando offline
  // ou em sessão local, cai para o store local (Usuarios de _mock.js).
  usuarios: (function() {
    const URL = API_BASE + '/usuarios.php';

    function _useBackend() {
      // usuarios.php aceita qualquer Bearer não-vazio — inclusive os tokens
      // "local-{id}-{ts}" criados quando o login passa pela rota validar.
      // Isso garante sincronização entre dispositivos sempre que houver sessão.
      return Auth.isLogged();
    }
    function _headers() {
      return {
        'Content-Type': 'application/json',
        'Authorization': 'Bearer ' + (Auth.getToken() || ''),
      };
    }
    async function _backend(method, query, body) {
      const opts = { method, headers: _headers(), cache: 'no-store' };
      if (body) opts.body = JSON.stringify(body);
      const url = URL + (query ? ('?' + query) : '');
      const res = await fetch(url, opts);
      let json = null;
      try { json = await res.json(); } catch { json = null; }
      if (!res.ok) {
        const msg = (json && (json.error || json.erro)) || ('HTTP ' + res.status);
        throw new Error(msg);
      }
      return json;
    }
    function _cacheSync(lista) {
      // Sincroniza o store local com a lista do backend para que outras
      // páginas (que ainda usam o store) e o fallback offline fiquem em dia.
      try {
        if (typeof Usuarios !== 'undefined' && Usuarios._save) {
          Usuarios._save(lista);
        }
      } catch {}
    }

    return {
      listar: async () => {
        if (_useBackend()) {
          try {
            const r = await _backend('GET', '');
            if (r && r.ok && Array.isArray(r.usuarios)) {
              _cacheSync(r.usuarios);
              return r.usuarios;
            }
          } catch (e) { /* cai para local */ }
        }
        return Usuarios.listar();
      },

      get: async (id) => {
        if (_useBackend()) {
          try {
            const r = await _backend('GET', 'id=' + encodeURIComponent(id));
            if (r && r.ok && r.usuario) return r.usuario;
          } catch (e) { /* cai para local */ }
        }
        return Usuarios.get(id);
      },

      criar: async (d) => {
        if (_useBackend()) {
          try {
            const r = await _backend('POST', 'action=criar', d);
            if (r && r.ok && r.usuario) {
              // Atualiza cache
              try {
                const lista = Usuarios.listar().filter(u => Number(u.idusuario) !== Number(r.usuario.idusuario));
                lista.push(Object.assign({}, r.usuario, { senha: d.senha || '' }));
                Usuarios._save(lista);
              } catch {}
              return r.usuario;
            }
          } catch (e) { throw e; }
        }
        return Usuarios.criar(d);
      },

      editar: async (id, d) => {
        if (_useBackend()) {
          try {
            const r = await _backend('POST', 'action=editar&id=' + encodeURIComponent(id), d);
            if (r && r.ok && r.usuario) {
              // Atualiza cache, preservando a senha localmente para exibir no modal
              try {
                const lista = Usuarios.listar();
                const idx = lista.findIndex(u => Number(u.idusuario) === Number(id));
                const senhaCache = d.senha || (idx >= 0 ? (lista[idx].senha || '') : '');
                const merged = Object.assign({}, lista[idx] || {}, r.usuario, { senha: senhaCache });
                if (idx >= 0) lista[idx] = merged; else lista.push(merged);
                Usuarios._save(lista);
              } catch {}
              return r.usuario;
            }
          } catch (e) { throw e; }
        }
        return Usuarios.editar(id, d);
      },

      excluir: async (id) => {
        if (_useBackend()) {
          try {
            const r = await _backend('DELETE', 'id=' + encodeURIComponent(id));
            if (r && r.ok) {
              try { Usuarios.excluir(id); } catch {}
              return true;
            }
          } catch (e) { throw e; }
        }
        return Usuarios.excluir(id);
      },

      // Validação de login via backend (sem token) — usada no login.html
      validar: async (email, senha) => {
        try {
          const res = await fetch(URL + '?action=validar', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email, senha }),
            cache: 'no-store',
          });
          let json = null;
          try { json = await res.json(); } catch { json = null; }
          if (res.ok && json && json.ok && json.usuario) return json.usuario;
          return null;
        } catch (e) {
          return null;
        }
      },
    };
  })(),

  // ── MIGRAÇÃO localStorage → backend (one-shot) ──────────────────────
  // Empurra atletas e mensalidades do Store local para o backend, evitando
  // duplicar (matches por email para atletas, e por chave composta para mens).
  // Roda só se sessão for backend real (token não-local) e ainda não migrou.
  migrarLocalParaBackend: async function(opts) {
    opts = opts || {};
    if (Auth.isLocalToken()) return { skip: 'sessao_local' };
    if (!Auth.isLogged())   return { skip: 'sem_sessao' };

    const FLAG = 'intus-migrado-v1';
    if (!opts.force && localStorage.getItem(FLAG)) return { skip: 'ja_migrado' };

    const log = {
      atletas:      { criados: 0, ja_existem: 0, erros: 0, detalhes: [] },
      mensalidades: { criados: 0, ja_existem: 0, erros: 0, detalhes: [] },
    };

    // 1) Atletas
    let backendAtletas = [];
    try {
      backendAtletas = await apiFetch('/atletas.php');
    } catch (e) {
      return { erro: 'falha_listar_backend_atletas', detalhe: e.message };
    }
    const idxByEmail = new Map(
      (backendAtletas || [])
        .filter(a => a.email)
        .map(a => [String(a.email).toLowerCase(), a])
    );
    const idxByName = new Map(
      (backendAtletas || [])
        .filter(a => a.nome)
        .map(a => [String(a.nome).trim().toLowerCase(), a])
    );
    const localAtletas = Store.get('atletas') || [];
    const mapaIds = new Map(); // id local → id backend

    for (const a of localAtletas) {
      if (!a || !a.nome) continue;
      // Skip mock-origin athletes (from _mock.js seed)
      if (a.origem === 'mock') { log.atletas.ja_existem++; continue; }
      const chave = String(a.email || '').toLowerCase();
      const nomeNorm = String(a.nome).trim().toLowerCase();
      // Check by email OR by exact name match
      if (chave && idxByEmail.has(chave)) {
        log.atletas.ja_existem++;
        mapaIds.set(Number(a.idatleta), Number(idxByEmail.get(chave).idatleta));
        continue;
      }
      if (idxByName.has(nomeNorm)) {
        log.atletas.ja_existem++;
        mapaIds.set(Number(a.idatleta), Number(idxByName.get(nomeNorm).idatleta));
        continue;
      }
      try {
        const novo = await apiFetch('/atletas.php', {
          method: 'POST',
          body: JSON.stringify({
            nome:         a.nome,
            email:        a.email || '',
            telefone:     a.telefone || '',
            genero:       a.genero || 'M',
            dtnascimento: a.dtnascimento || null,
            codacesso:    a.codacesso || '',
            stbloqueio:   a.stbloqueio || 'N',
            observacao:   a.observacao || '',
          }),
        });
        log.atletas.criados++;
        if (novo && novo.idatleta) {
          mapaIds.set(Number(a.idatleta), Number(novo.idatleta));
        }
      } catch (e) {
        log.atletas.erros++;
        log.atletas.detalhes.push({ nome: a.nome, erro: e.message });
      }
    }

    // 2) Mensalidades
    let backendMens = [];
    try { backendMens = await apiFetch('/mensalidades.php'); } catch {}
    const setBackend = new Set(
      (backendMens || []).map(m =>
        Number(m.idatleta) + '|' + (m.dtvencimento || m.dtinicio || '') + '|' + Number(m.vlpagar || m.valor || 0)
      )
    );
    const localMens = Store.get('mensalidades') || [];
    for (const m of localMens) {
      const idAtletaBack = mapaIds.get(Number(m.idatleta)) || Number(m.idatleta);
      const k = idAtletaBack + '|' + (m.dtvencimento || '') + '|' + Number(m.vlpagar || 0);
      if (setBackend.has(k)) { log.mensalidades.ja_existem++; continue; }
      try {
        await apiFetch('/mensalidades.php', {
          method: 'POST',
          body: JSON.stringify({
            idatleta:     idAtletaBack,
            dshistorico:  m.dshistorico || 'Mensalidade',
            dtvencimento: m.dtvencimento,
            vlpagar:      Number(m.vlpagar || 0),
            vldesconto:   Number(m.vldesconto || 0),
            vljuro:       Number(m.vljuro || 0),
            stpgto:       m.stpgto || 'N',
            dtpagamento:  m.dtpagamento || null,
            vlpagamento:  m.vlpagamento != null ? Number(m.vlpagamento) : null,
            dspagamento:  m.dspagamento || null,
          }),
        });
        log.mensalidades.criados++;
      } catch (e) {
        log.mensalidades.erros++;
        log.mensalidades.detalhes.push({ idmensalidade: m.idmensalidade, erro: e.message });
      }
    }

    // 3) Exercise catalog — push local to backend if backend is empty
    let exLog = { pushed: 0, erros: 0 };
    const EX_FLAG = 'intus-exercicios-synced';
    if (!localStorage.getItem(EX_FLAG)) {
      try {
        const backendEx = await apiFetch('/treinos.php?action=exercicios').catch(() => null);
        if (!backendEx || !Array.isArray(backendEx) || backendEx.length === 0) {
          const localEx = Store.get('exercicios') || [];
          for (const ex of localEx) {
            if (!ex || !ex.nmexercicio) continue;
            try {
              await apiFetch('/treinos.php?action=exercicios', {
                method: 'POST',
                body: JSON.stringify({
                  nmexercicio:  ex.nmexercicio,
                  grupo:        ex.grupo || '',
                  grupos:       ex.grupos || null,
                  observacao:   ex.observacao || '',
                  descricao:    ex.descricao || '',
                  videoyoutube: ex.videoyoutube || '',
                  gifexercicio: ex.gifexercicio || '',
                  instrucao_ia: ex.instrucao_ia || '',
                  substitutos:  ex.substitutos || null,
                }),
              });
              exLog.pushed++;
            } catch { exLog.erros++; }
          }
          if (exLog.erros === 0) localStorage.setItem(EX_FLAG, new Date().toISOString());
        } else {
          localStorage.setItem(EX_FLAG, new Date().toISOString());
        }
      } catch {}
    }
    log.exercicios = exLog;

    if (log.atletas.erros === 0 && log.mensalidades.erros === 0) {
      localStorage.setItem(FLAG, new Date().toISOString());
    }
    return log;
  },
};

// ── ANAMNESE ─────────────────────────────────────────────────────────────
API.getAnamnese = async function(idatleta) {
  try {
    const resp = await apiFetch('/catalogo.php?action=anamnese&atleta=' + idatleta);
    if (resp && typeof resp === 'object' && Object.keys(resp).length > 0) {
      localStorage.setItem('intus-anamnese-' + idatleta, JSON.stringify(resp));
      return resp;
    }
  } catch {}
  return JSON.parse(localStorage.getItem('intus-anamnese-' + idatleta) || 'null');
};

API.saveAnamnese = async function(idatleta, data) {
  data._idatleta = idatleta;
  data._timestamp = data._timestamp || new Date().toISOString();
  localStorage.setItem('intus-anamnese-' + idatleta, JSON.stringify(data));
  try {
    await apiFetch('/catalogo.php?action=anamnese', { method: 'POST', body: JSON.stringify(data) });
  } catch {}
  return true;
};

// ── SYNC EXERCÍCIOS (roda independente da migração geral) ────────────────
API.syncExerciseCatalog = async function() {
  if (Auth.isLocalToken() || !Auth.isLogged()) return;
  const EX_FLAG = 'intus-exercicios-synced';
  if (localStorage.getItem(EX_FLAG)) return;
  try {
    const backendEx = await apiFetch('/treinos.php?action=exercicios').catch(() => null);
    if (!backendEx || !Array.isArray(backendEx) || backendEx.length === 0) {
      const localEx = Store.get('exercicios') || [];
      if (localEx.length === 0) return;
      let ok = 0;
      for (const ex of localEx) {
        if (!ex || !ex.nmexercicio) continue;
        try {
          await apiFetch('/treinos.php?action=exercicios', {
            method: 'POST',
            body: JSON.stringify({
              nmexercicio: ex.nmexercicio, grupo: ex.grupo || '', grupos: ex.grupos || null,
              observacao: ex.observacao || '', descricao: ex.descricao || '',
              videoyoutube: ex.videoyoutube || '', gifexercicio: ex.gifexercicio || '',
              instrucao_ia: ex.instrucao_ia || '', substitutos: ex.substitutos || null,
            }),
          });
          ok++;
        } catch {}
      }
      if (ok > 0) localStorage.setItem(EX_FLAG, new Date().toISOString());
    } else {
      Store.set('exercicios', backendEx);
      localStorage.setItem(EX_FLAG, new Date().toISOString());
    }
  } catch {}
};

// ── GUARD DE AUTENTICAÇÃO ────────────────────────────────────────────────
// Chame no topo de cada página protegida (exceto login.html)
function requireLogin() {
  if (!Auth.isLogged()) {
    window.location.href = 'login.html';
    return;
  }
  // Aluno tentando acessar painel do professor → redireciona para app do aluno
  try {
    const u = JSON.parse(localStorage.getItem('mx-user') || '{}');
    if (u.tipo === 'aluno' && !window.location.pathname.includes('aluno.html')) {
      window.location.href = 'aluno.html';
    }
  } catch {}
  // Sync usuários do backend para manter permissões atualizadas
  if (!Auth.isStudentToken()) {
    _syncUsuariosBackground();
  }
}

function _syncUsuariosBackground() {
  const lastSync = Number(localStorage.getItem('intus-usuarios-sync-ts') || 0);
  if (Date.now() - lastSync < 300000) return;
  localStorage.setItem('intus-usuarios-sync-ts', String(Date.now()));
  fetch(API_BASE + '/usuarios.php', {
    headers: { 'Authorization': 'Bearer ' + (Auth.getToken() || ''), 'Cache-Control': 'no-cache' },
    cache: 'no-store',
  }).then(r => r.json()).then(d => {
    if (d && d.ok && Array.isArray(d.usuarios)) {
      if (typeof Usuarios !== 'undefined' && Usuarios._save) {
        Usuarios._save(d.usuarios);
      }
      // Se permissões do usuário atual mudaram, atualiza mx-user
      try {
        const cur = JSON.parse(localStorage.getItem('mx-user') || '{}');
        if (cur.idusuario) {
          const fresh = d.usuarios.find(u => Number(u.idusuario) === Number(cur.idusuario));
          if (fresh && fresh.permissoes) {
            const curPerms = JSON.stringify(cur.permissoes || {});
            const newPerms = JSON.stringify(fresh.permissoes);
            if (curPerms !== newPerms) {
              cur.permissoes = fresh.permissoes;
              if (fresh.admin !== undefined) cur.admin = fresh.admin;
              localStorage.setItem('mx-user', JSON.stringify(cur));
              window.location.reload();
            }
          }
        }
      } catch {}
    }
  }).catch(() => {});
}
