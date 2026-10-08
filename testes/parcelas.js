// Plano parcelado (API.gerarParcelas e as regras de cobrança aplicadas às parcelas).
const { carregar } = require('./_api.js');
let ok = 0, ruim = 0;
function eq(rot, obtido, esperado) {
  const a = JSON.stringify(obtido), b = JSON.stringify(esperado);
  if (a === b) { ok++; console.log('  OK    ' + rot); }
  else { ruim++; console.log('  FALHA ' + rot + '\n          esperado ' + b + '\n          obtido   ' + a); }
}

// Monta as linhas como o banco as devolve (com id, ligação à parcela 1 e estado de pagamento).
function linhas(API, o, pagas, extra) {
  const g = API.gerarParcelas(o);
  if (!g.ok) throw new Error(g.erro);
  return g.linhas.map((l, i) => Object.assign({
    idmensalidade: 100 + i, idatleta: 7, tipo_plano: 'Treino', stmatricula: 'ativa', recorrencia: null, forma_pgto: 'pix', vljuro: 0,
    idmatricula_origem: i === 0 ? null : 100,
    stpgto: (pagas || []).includes(i + 1) ? 'S' : 'N',
    dtpagamento: (pagas || []).includes(i + 1) ? l.dtinicio : null,
  }, l, extra || {}));
}
const base = { inicio: '2026-10-01', meses: 3, parcelas: 3, valor: 600, desconto: 0, descricao: 'Trimestral Treino' };

console.log('\n── Gerar parcelas ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  const g = API.gerarParcelas(base);
  eq('trimestral em 3x: três linhas de 200', g.linhas.map(l => l.vlpagar), [200, 200, 200]);
  eq('janelas mensais contíguas', g.linhas.map(l => l.dtinicio + '>' + l.dtvencimento), ['2026-10-01>2026-11-01', '2026-11-01>2026-12-01', '2026-12-01>2027-01-01']);
  eq('descrição leva o número da parcela', g.linhas.map(l => l.dshistorico), ['Trimestral Treino (1/3)', 'Trimestral Treino (2/3)', 'Trimestral Treino (3/3)']);
  eq('fim do plano', g.fim, '2027-01-01');
  const d = API.gerarParcelas({ inicio: '2026-10-01', meses: 3, parcelas: 3, valor: 100, desconto: 10, descricao: 'X' });
  eq('resto da divisão vai para a última e a soma fecha', d.linhas.map(l => l.vlpagar), [33.33, 33.33, 33.34]);
  eq('desconto dividido também fecha', +d.linhas.reduce((s, l) => s + l.vldesconto, 0).toFixed(2), 10);
  eq('líquido', d.liquido, 90);
  const e = API.gerarParcelas({ inicio: '2026-01-31', meses: 3, parcelas: 3, valor: 300, descricao: 'X' });
  eq('dia 31 não vaza para o mês seguinte', e.linhas.map(l => l.dtinicio), ['2026-01-31', '2026-02-28', '2026-03-31']);
  const f = API.gerarParcelas({ inicio: '2026-10-01', meses: 12, parcelas: 10, valor: 1200, descricao: 'Anual' });
  eq('anual em 10x: 10 parcelas mensais e a última cobre o resto do plano', [f.linhas[0].dtvencimento, f.linhas[9].dtinicio, f.linhas[9].dtvencimento], ['2026-11-01', '2027-07-01', '2027-10-01']);
  eq('semestral em 3x a cada 2 meses', API.gerarParcelas({ inicio: '2026-10-01', meses: 6, parcelas: 3, intervalo: 2, valor: 600, descricao: 'S' }).linhas.map(l => l.dtinicio), ['2026-10-01', '2026-12-01', '2027-02-01']);
}

console.log('\n── Validação ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  eq('plano mensal não parcela', API.gerarParcelas(Object.assign({}, base, { meses: 1, parcelas: 2 })).ok, false);
  eq('mais parcelas que meses', API.gerarParcelas(Object.assign({}, base, { meses: 3, parcelas: 4 })).ok, false);
  eq('1 parcela não é parcelamento', API.gerarParcelas(Object.assign({}, base, { parcelas: 1 })).ok, false);
  eq('mais de 12 parcelas', API.gerarParcelas(Object.assign({}, base, { meses: 24, parcelas: 13 })).ok, false);
  eq('valor zerado', API.gerarParcelas(Object.assign({}, base, { valor: 0 })).ok, false);
  eq('desconto maior que o valor', API.gerarParcelas(Object.assign({}, base, { desconto: 600 })).ok, false);
  eq('sem data de início', API.gerarParcelas(Object.assign({}, base, { inicio: '' })).ok, false);
}

console.log('\n── Cada parcela é uma janela de um mês ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  const L = linhas(API, base);
  eq('ehParcelada', [API.ehParcelada(L[0]), API.ehParcelada({ parcela_total: 1 }), API.ehParcelada({})], [true, false, false]);
  eq('meses da parcela = janela dela, não "trimestral" da descrição', L.map(l => API.mesesDoPlano(l)), [1, 1, 1]);
  eq('cobrança de cada parcela é o início da janela', L.map(l => API.dtCobranca(l)), ['2026-10-01', '2026-11-01', '2026-12-01']);
  const A = linhas(API, { inicio: '2026-10-01', meses: 12, parcelas: 10, valor: 1200, descricao: 'Anual' });
  eq('a última parcela do anual em 10x cobre 3 meses', [API.mesesDoPlano(A[0]), API.mesesDoPlano(A[9])], [1, 3]);
  eq('e a cobrança dela continua sendo o início da janela', API.dtCobranca(A[9]), '2027-07-01');
  eq('a mesma matrícula mesmo sem ligação, só pelo nome sem "(i/n)"', API.chaveMatricula(L[0], L.map(l => Object.assign({}, l, { idmatricula_origem: null }))) === API.chaveMatricula(L[2], L.map(l => Object.assign({}, l, { idmatricula_origem: null }))), true);
  eq('serie das parcelas', API.serieParcelas(L[1], L).map(l => l.parcela_num), [1, 2, 3]);
  eq('rótulo', L.map(l => API.rotuloParcela(l)), ['Parcela 1/3', 'Parcela 2/3', 'Parcela 3/3']);
}

console.log('\n── Situação ao longo do tempo (aluno pagou só no início do mês) ──');
function estados(hoje, pagas, o) {
  const API = carregar({ hoje: hoje });
  const L = linhas(API, o || base, pagas);
  return L.map(l => API.situacaoCobranca(l, L).estado);
}
eq('dia do cadastro, 1ª paga: 1ª paga, 2ª e 3ª programadas', estados('2026-10-01', [1]), ['paga', 'programada', 'programada']);
eq('15 dias antes da 2ª: ela vira "pendente" (cobrança a fazer)', estados('2026-10-17', [1]), ['paga', 'pendente', 'programada']);
eq('2ª paga dentro do mês: 1ª concluída, 2ª paga', estados('2026-11-03', [1, 2]), ['renovada', 'paga', 'programada']);
eq('2ª não paga e passou a tolerância: vencida', estados('2026-11-10', [1]), ['renovada', 'vencida', 'programada']);
eq('2ª não paga há mais de 30 dias: inativa', estados('2026-12-05', [1]), ['renovada', 'inativa', 'pendente']);
eq('todas pagas e plano acabou: última pede renovação, não é dívida', estados('2027-01-10', [1, 2, 3]), ['renovada', 'renovada', 'renovar']);
{
  const API = carregar({ hoje: '2027-01-10' });
  const L = linhas(API, base, [1, 2, 3]);
  eq('"renovar" não é dívida', API.situacaoCobranca(L[2], L).ehDivida, false);
}
{
  // pagou a parcela 1 no fim da janela dela (30/10): a heurística de "pagou o ciclo seguinte" não pode salvar a parcela 2
  const API = carregar({ hoje: '2026-11-20' });
  const L = linhas(API, base, [1]);
  L[0].dtpagamento = '2026-10-30';
  eq('parcela paga tarde não quita a seguinte', API.situacaoCobranca(L[1], L).estado, 'vencida');
  eq('a baixa não é lida como pagamento do próximo ciclo', API.pagamentoDoProximoCiclo(L[0]), false);
}

console.log('\n── Acesso do aluno ──');
{
  const aluno = { idatleta: 7, nome: 'A', stbloqueio: 'N', origem: 'professor', professores_responsaveis: [1] };
  const sit = (hoje, pagas) => { const API = carregar({ hoje: hoje }); return API.situacaoPlano(aluno, linhas(API, base, pagas)).estado; };
  eq('parcela 1 paga: ativo', sit('2026-10-20', [1]), 'ativo');
  eq('2ª parcela com 4 dias de atraso (dentro da tolerância): ativo', sit('2026-11-05', [1]), 'ativo');
  eq('2ª parcela com 8 dias de atraso: inativo, mesmo com a 3ª em aberto à frente', sit('2026-11-09', [1]), 'inativo');
  eq('2ª parcela com 20 dias de atraso: inativo', sit('2026-11-21', [1]), 'inativo');
  eq('2ª paga: volta a ativo', sit('2026-11-21', [1, 2]), 'ativo');
  eq('plano quitado e dentro da cobertura: ativo', sit('2026-12-20', [1, 2, 3]), 'ativo');
}

console.log('\n── Resumo e renovação ──');
{
  const API = carregar({ hoje: '2026-11-03' });
  const L = linhas(API, { inicio: '2026-10-01', meses: 3, parcelas: 3, valor: 100, desconto: 10, descricao: 'Trimestral Treino' }, [1]);
  const r = API.resumoParcelas(L[0], L);
  eq('pagas e restantes', [r.total, r.pagas, r.abertas], [3, 1, 2]);
  eq('valores em líquido (com desconto)', [r.valorTotal, r.valorRestante], [90, 60]);
  eq('próxima parcela a receber', [r.proxima.parcela, r.proxima.cobranca, r.proxima.valor], [2, '2026-11-01', 30]);
  eq('parcelas abertas a partir da 2', API.parcelasAbertas(L[0], L, 2).map(l => l.parcela_num), [2, 3]);
  const R = API.dadosRenovacaoParcelada(L[2], L);
  eq('renovação: mesmo plano a partir do fim do anterior', [R.inicio, R.meses, R.parcelas, R.valor, R.desconto, R.descricao], ['2027-01-01', 3, 3, 100, 10, 'Trimestral Treino']);
  const nova = API.gerarParcelas(R);
  eq('a renovação gera de novo 3 parcelas', [nova.ok, nova.linhas.length, nova.fim], [true, 3, '2027-04-01']);
  eq('descrição base sem o sufixo', API.descricaoBase({ dshistorico: 'Trimestral Treino (2/3)' }), 'Trimestral Treino');
}

console.log('\n── Não muda o que já funcionava ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  const mensal = { idmensalidade: 1, idatleta: 7, dshistorico: 'Mensal Treino', recorrencia: 'mensal', dtinicio: '2026-10-01', dtvencimento: '2026-11-01', stpgto: 'S', dtpagamento: '2026-10-01', vlpagar: 100, stmatricula: 'ativa' };
  eq('mensal normal continua mensal', API.mesesDoPlano(mensal), 1);
  eq('trimestral normal continua 3', API.mesesDoPlano({ dshistorico: 'Trimestral', dtinicio: '2026-10-01', dtvencimento: '2027-01-01' }), 3);
  eq('mensal pago em dia: paga', API.situacaoCobranca(mensal, [mensal]).estado, 'paga');
  eq('plano sem parcela no nome agrupa como antes', API.chaveMatricula({ idmensalidade: 1, idatleta: 7, dshistorico: 'Mensal Treino' }, null), '7|mensal treino');
}

console.log('\n' + ok + ' ok, ' + ruim + ' com falha');
process.exit(ruim ? 1 : 0);
