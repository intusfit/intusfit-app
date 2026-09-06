// AS QUATRO TELAS TEM QUE DIZER A MESMA COISA.
// Este teste existe por causa de um sintoma especifico: Vanessa, Maud, Erick e
// Samantha sairam do vermelho no painel de matriculas e na tela inicial, e
// continuaram "em atraso" no Financeiro — porque cada tela tinha a propria
// regra. Agora quem decide e API.situacaoCobranca, e aqui a gente confere que
// o mapeamento de cada tela sai do MESMO estado.
const vm = require('vm');
const { carregarAPI } = require('./_api.js');
const HOJE = '2026-08-31';
const ctx = { console }; vm.createContext(ctx);
const API = carregarAPI(ctx, HOJE);

let f = 0;
const eq = (rot, obtido, esperado) => {
  const ok = JSON.stringify(obtido) === JSON.stringify(esperado);
  if (!ok) f++;
  console.log((ok ? '  ok   ' : '  FALHA') + '  ' + rot + ': ' + JSON.stringify(obtido) +
    (ok ? '' : '   (esperado: ' + JSON.stringify(esperado) + ')'));
};

let id = 1;
const C = (at, nome, plano, ini, venc, pg, valor) => ({ idmensalidade: id++, idatleta: at,
  nmathleta: nome, dshistorico: plano, recorrencia: 'mensal', dtinicio: ini, dtvencimento: venc,
  dtpagamento: pg || null, vlpagar: valor, stpgto: pg ? 'S' : 'N', stmatricula: 'ativa' });

// ── Os quatro casos que o Luiz apontou, com os ciclos reais ──
const VAN_PAGO = C(1,'Vanessa Carissimi','Personal 2x','2026-07-30','2026-08-30','2026-07-30',1225);
const VAN_NOVO = C(1,'Vanessa Carissimi','Personal 2x','2026-08-30','2026-09-30',null,1225);
const MAU_ABERTO = C(2,'Maud Pedreira Dias','Personal 3x','2026-08-22','2026-09-22',null,1050);
const MAU_DUP_B  = C(2,'Maud Pedreira Dias','Personal 3x','2026-07-22','2026-08-22','2026-08-23',1050);
const MAU_DUP_A  = C(2,'Maud Pedreira Dias','Personal 3x','2026-07-22','2026-08-22','2026-07-22',1050);
const ERI_ABERTO = C(3,'Erick Braz Schiesl','Personal 4x','2026-08-20','2026-09-20',null,1000);
const ERI_PAGO   = C(3,'Erick Braz Schiesl','Personal 4x','2026-07-21','2026-08-20','2026-08-19',1000);
const SAM_ABERTO = C(4,'Samantha Lara','Personal 3x','2026-08-15','2026-09-15',null,1000);
const SAM_PAGO   = C(4,'Samantha Lara','Personal 3x','2026-07-15','2026-08-15','2026-08-17',1000);
// Devedor de verdade, para a regra nao virar "ninguem deve nada".
const DEV = C(5,'Aluno Devedor','Personal 2x','2026-07-01','2026-08-01',null,500);
// Pago e a cobertura acabou, sem ciclo seguinte: precisa de acao, nao e divida.
const REN = C(6,'Aluno Renovar','Personal 2x','2026-07-22','2026-08-22','2026-07-22',400);

const TODAS = [VAN_PAGO,VAN_NOVO,MAU_ABERTO,MAU_DUP_B,MAU_DUP_A,ERI_ABERTO,ERI_PAGO,SAM_ABERTO,SAM_PAGO,DEV,REN];
const sit = m => API.situacaoCobranca(m, TODAS);

// ── Os mapeamentos que cada tela faz, copiados do codigo de cada uma ──
const telaMatriculas = st => (st==='paga'||st==='paga_no_anterior') ? 'ativa_paga'
                          : (st==='renovar' ? 'aguardando' : st);
const telaFinanceiro = st => (st==='paga'||st==='renovada') ? 'pago'
                          : (st==='vencida'||st==='inativa') ? 'atrasado'
                          : (st==='pendente'||st==='programada') ? 'pendente' : 'encerrado';
const telaClientes   = st => (st==='paga'||st==='paga_no_anterior') ? 'ativa_paga'
                          : (st==='pendente'||st==='programada') ? 'ativa_pend'
                          : (st==='vencida'||st==='inativa') ? 'vencida' : 'encerrada';
const telaInicio     = s2 => s2.ehDivida ? 'vencidas' : (s2.ehAcao ? 'renovar' : 'fora');

console.log('hoje = ' + HOJE + '\n');
console.log('── o estado de cada caso ──');
eq('Vanessa, ciclo novo (cobranca venceu ontem, na tolerancia)', sit(VAN_NOVO).estado, 'pendente');
eq('Maud, ciclo de agosto (baixa na linha de cima)', sit(MAU_ABERTO).estado, 'paga_no_anterior');
eq('Erick, ciclo de agosto (idem)', sit(ERI_ABERTO).estado, 'paga_no_anterior');
eq('Samantha, ciclo de agosto (idem)', sit(SAM_ABERTO).estado, 'paga_no_anterior');
eq('Devedor de verdade', sit(DEV).estado, 'inativa');
eq('Pago e sem ciclo seguinte', sit(REN).estado, 'renovar');

console.log('\n── NENHUM DELES E DIVIDA, menos o devedor ──');
[['Vanessa',VAN_NOVO],['Maud',MAU_ABERTO],['Erick',ERI_ABERTO],['Samantha',SAM_ABERTO]]
  .forEach(([nome,m]) => eq(nome + ' nao esta em atraso', sit(m).ehDivida && sit(m).estado !== 'pendente', false));
eq('so o devedor conta como atraso', sit(DEV).ehDivida, true);
eq('e quem precisa renovar NAO entra no atraso', sit(REN).ehDivida, false);
eq('mas aparece como acao a fazer', sit(REN).ehAcao, true);

console.log('\n── AS QUATRO TELAS DIZEM A MESMA COISA ──');
[['Maud',MAU_ABERTO],['Erick',ERI_ABERTO],['Samantha',SAM_ABERTO]].forEach(([nome,m]) => {
  const st = sit(m).estado;
  eq(nome + ' — Matriculas', telaMatriculas(st), 'ativa_paga');
  eq(nome + ' — Financeiro', telaFinanceiro(st) === 'atrasado', false);
  eq(nome + ' — Clientes', telaClientes(st), 'ativa_paga');
  eq(nome + ' — Inicio', telaInicio(sit(m)), 'fora');
});
const stV = sit(VAN_NOVO).estado;
eq('Vanessa — Financeiro nao a chama de atrasada', telaFinanceiro(stV), 'pendente');
eq('Vanessa — Clientes idem', telaClientes(stV), 'ativa_pend');
const stD = sit(DEV).estado;
eq('Devedor — Financeiro', telaFinanceiro(stD), 'atrasado');
eq('Devedor — Clientes', telaClientes(stD), 'vencida');
eq('Devedor — Inicio', telaInicio(sit(DEV)), 'vencidas');

console.log('\n── a proxima cobranca existe mesmo sem ciclo em aberto ──');
eq('quem precisa renovar tem data', sit(REN).proxima, '2026-08-22');
eq('o ciclo pago da Vanessa ja foi renovado, entao nao tem', sit(VAN_PAGO).proxima, '');
eq('e o estado dele e "renovada", nao "renovar"', sit(VAN_PAGO).estado, 'renovada');

console.log('\n' + (f ? '✗ ' + f + ' FALHA(S)' : 'todos os casos passaram'));
process.exit(f ? 1 : 0);
