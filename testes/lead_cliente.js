// Lead que ganha matrícula de cliente (paga ou bonificada) deixa de ser lead, qualquer que seja o caminho que criou a matrícula.
// Caso real de 23/10/2026: Ederson Souza e Diego Karvak seguiam como lead depois de receber plano. A regra mora em API.ehLead (api.js) e as telas
// (Clientes e painel inicial) só perguntam. O teste grátis NÃO tira ninguém de lead; "Cortesia" no tipo do teste do app também não.
//   cd testes && INTUS_DIR=../app/painel node lead_cliente.js
const { carregar } = require('./_api.js');
const fs = require('fs'), path = require('path');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) { const a = JSON.stringify(obtido), b = JSON.stringify(esperado); if (a === b) { ok++; console.log('  OK    ' + rot); } else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); } }

const r = carregar({ hoje: '2026-10-23' });
const API = r.API || r;
let n = 1;
const M = (idatleta, extra) => Object.assign({ idmensalidade: n++, idatleta, dshistorico: 'Mensalidade', tipo_plano: null, dtinicio: '2026-10-01', dtvencimento: '2026-11-01', stpgto: 'S', vlpagar: 300, vlpagamento: 300, stmatricula: 'ativa' }, extra || {});
const LEAD = (id, o) => Object.assign({ idatleta: id, nome: 'Lead ' + id, email: 'l' + id + '@x.com', origem: 'app', professores_responsaveis: [], stbloqueio: 'N' }, o || {});
const TESTE = (id) => M(id, { dshistorico: 'Teste Grátis (3 dias)', tipo_plano: 'Cortesia', stpgto: 'S', vlpagar: 0, vlpagamento: 0, dtvencimento: '2026-10-25' });

console.log('\n══ Quem continua lead ══');
eq('lead do app sem matrícula nenhuma', API.ehLead(LEAD(1), []), true);
eq('lead do app só com o teste grátis (o do app vem com tipo "Cortesia")', API.ehLead(LEAD(1), [TESTE(1)]), true);
eq('lead de página de captura só com o teste', API.ehLead(LEAD(1, { origem: 'captura' }), [TESTE(1)]), true);
eq('matrícula de OUTRA pessoa não conta', API.ehLead(LEAD(1), [M(2)]), true);
eq('matrícula de cliente que foi cancelada (criada por engano) não conta', API.ehLead(LEAD(1), [TESTE(1), M(1, { stmatricula: 'cancelada' })]), true);
eq('sem a lista de matrículas (chamada antiga) vale só a origem', API.ehLead(LEAD(1)), true);

console.log('\n══ Quem deixa de ser lead ══');
eq('plano pago', API.ehLead(LEAD(1), [TESTE(1), M(1)]), false);
eq('plano pago que ainda não foi baixado (matrícula criada, falta pagar)', API.ehLead(LEAD(1), [TESTE(1), M(1, { stpgto: 'N', vlpagamento: null })]), false);
eq('plano bonificado (tipo Bonificado, valor zero)', API.ehLead(LEAD(1), [M(1, { tipo_plano: 'Bonificado', dshistorico: 'Plano Bonificado', vlpagar: 0, vlpagamento: 0 })]), false);
eq('plano de cortesia de verdade (presente, 30 dias)', API.ehLead(LEAD(1), [TESTE(1), M(1, { dshistorico: 'Cortesia Premium 30 dias', vlpagar: 0, vlpagamento: 0 })]), false);
eq('cortesia no tipo e SEM a palavra teste: é bonificação', API.ehLead(LEAD(1), [M(1, { tipo_plano: 'Cortesia', vlpagar: 0, vlpagamento: 0 })]), false);
eq('plano pago já encerrado: quem foi cliente não volta a ser lead', API.ehLead(LEAD(1), [M(1, { stmatricula: 'encerrada', dtvencimento: '2026-09-01' })]), false);
eq('plano trancado também conta', API.ehLead(LEAD(1), [M(1, { stmatricula: 'trancada' })]), false);
eq('conta antiga sem origem e sem professor (lead pelo legado) com plano pago', API.ehLead(LEAD(1, { origem: '' }), [M(1)]), false);
eq('origem "lead" com plano pago', API.ehLead(LEAD(1, { origem: 'lead' }), [M(1)]), false);

console.log('\n══ O resto da regra não mudou ══');
eq('origem professor nunca é lead', API.ehLead(LEAD(1, { origem: 'professor' }), []), false);
eq('conta modelo do sistema nunca é lead', API.ehLead(LEAD(1, { email: 'modelo.feminino@intusfit.local' }), []), false);
eq('legado com professor responsável não é lead', API.ehLead(LEAD(1, { origem: '', professores_responsaveis: [3] }), []), false);

console.log('\n══ situacaoPlano e criarMensalidade acompanham ══');
eq('situacaoPlano.lead: com teste = lead', API.situacaoPlano(LEAD(1), [TESTE(1)]).lead, true);
eq('situacaoPlano.lead: com plano pago deixa de ser lead (e o plano ativo continua contando)', (s => [s.lead, s.ativo])(API.situacaoPlano(LEAD(1), [TESTE(1), M(1, { dtvencimento: '2026-12-01' })])), [false, true]);
eq('situacaoPlano.lead: bonificado deixa de ser lead', API.situacaoPlano(LEAD(1), [M(1, { tipo_plano: 'Bonificado', vlpagar: 0, vlpagamento: 0, dtvencimento: '2026-12-01' })]).lead, false);
eq('ehMatriculaDeCliente: teste e cancelada ficam de fora', [
  API.ehMatriculaDeCliente({ dshistorico: 'Teste Grátis (3 dias)', tipo_plano: 'Cortesia' }),
  API.ehMatriculaDeCliente({ dshistorico: 'Mensal', stmatricula: 'cancelada' }),
  API.ehMatriculaDeCliente({ dshistorico: 'Mensal' }),
  API.ehMatriculaDeCliente({ dshistorico: 'Bonificado', tipo_plano: 'Cortesia' }),
  API.ehMatriculaDeCliente(null),
], [false, false, true, true, false]);

console.log('\n══ As telas só perguntam ══');
const painel = path.resolve(process.env.INTUS_DIR || '../app/painel');
const lerTela = (f) => fs.readFileSync(path.join(painel, f), 'utf8').replace(/\r\n/g, '\n');
const alunos = lerTela('alunos.html'), indice = lerTela('index.html'), apijs = lerTela('api.js');
eq('Clientes (alunos.html) delega a API.ehLead com a lista de matrículas', /function isLead\(a\) \{\n[^\n]*\n?\s*if \(typeof API[^\n]*API\.ehLead\(a, mensalidades\)/.test(alunos), true);
eq('Painel inicial: _lead e os leads novos passam a lista de matrículas', /API\.ehLead\(a, mensalidades\)/.test(indice) && /API\.ehLead\(a, mens\)/.test(indice) && /_isLeadNovoIdx\(a, d\.mensalidades\)/.test(indice), true);
eq('criarMensalidade só reclassifica quando a matrícula criada é de cliente (teste grátis criado pelo painel não)', /API\.ehLead\(atleta\) && API\.ehMatriculaDeCliente\(data\)/.test(apijs), true);

console.log('\n' + ok + ' ok, ' + ruim + ' falhas');
process.exit(ruim ? 1 : 0);
