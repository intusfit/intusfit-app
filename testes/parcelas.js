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

console.log('\n── Valores combinados por parcela ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  const t = { inicio: '2026-10-05', meses: 3, parcelas: 2, valor: 1497, descricao: 'Treino + Dieta', valores: [700] };
  const g = API.gerarParcelas(t);
  eq('R$ 700 de entrada e o resto na 2ª: 700 e 797', g.linhas.map(l => l.vlpagar), [700, 797]);
  eq('datas continuam mensais', g.linhas.map(l => l.dtinicio), ['2026-10-05', '2026-11-05']);
  eq('marcada como personalizada e sem valor único de parcela', [g.personalizado, g.valorParcela], [true, null]);
  eq('a soma fecha com o plano', +g.linhas.reduce((s, l) => s + l.vlpagar, 0).toFixed(2), 1497);
  const g3 = API.gerarParcelas({ inicio: '2026-10-05', meses: 3, parcelas: 3, valor: 900, descricao: 'X', valores: [500] });
  eq('3x com a 1ª combinada: as outras dividem o resto igualmente', g3.linhas.map(l => l.vlpagar), [500, 200, 200]);
  const g4 = API.gerarParcelas({ inicio: '2026-10-05', meses: 3, parcelas: 3, valor: 900, descricao: 'X', valores: [500, 300] });
  eq('1ª e 2ª combinadas, a 3ª é o saldo', g4.linhas.map(l => l.vlpagar), [500, 300, 100]);
  const gd = API.gerarParcelas({ inicio: '2026-10-05', meses: 3, parcelas: 2, valor: 1000, desconto: 100, descricao: 'X', valores: [400] });
  eq('com desconto, o valor combinado é o líquido da parcela', gd.linhas.map(l => +(l.vlpagar - l.vldesconto).toFixed(2)), [400, 500]);
  eq('o desconto total continua o mesmo', +gd.linhas.reduce((s, l) => s + l.vldesconto, 0).toFixed(2), 100);
  eq('sem valores informados nada muda', API.gerarParcelas({ inicio: '2026-10-05', meses: 3, parcelas: 2, valor: 1497, descricao: 'X', valores: [null] }).linhas.map(l => l.vlpagar), [748.5, 748.5]);
  eq('valor da 1ª igual ao plano inteiro: erro', API.gerarParcelas(Object.assign({}, t, { valores: [1497] })).ok, false);
  eq('valor da 1ª maior que o plano: erro', API.gerarParcelas(Object.assign({}, t, { valores: [2000] })).ok, false);
  eq('valor zero: erro', API.gerarParcelas(Object.assign({}, t, { valores: [0] })).ok, false);
  const L = linhas(API, t, [1, 2]);
  const R = API.dadosRenovacaoParcelada(L[1], L);
  eq('renovação repete os valores da série anterior', API.gerarParcelas(R).linhas.map(l => l.vlpagar), [700, 797]);
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

console.log('\n── Data de cada parcela ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  const d = API.gerarParcelas(Object.assign({}, base, { datas: ['2026-10-01', '2026-11-15', '2026-12-10'] }));
  eq('datas combinadas viram o início de cada janela', d.linhas.map(l => l.dtinicio), ['2026-10-01', '2026-11-15', '2026-12-10']);
  eq('janelas contíguas, a última vai até o fim do plano', d.linhas.map(l => l.dtvencimento), ['2026-11-15', '2026-12-10', '2027-01-01']);
  eq('marca datas personalizadas', d.datasPersonalizadas, true);
  eq('sem datas não marca', API.gerarParcelas(base).datasPersonalizadas, false);
  eq('valores continuam iguais com datas novas', d.linhas.map(l => l.vlpagar), [200, 200, 200]);
  const v = API.gerarParcelas({ inicio: '2026-10-05', meses: 2, parcelas: 2, valor: 1400, descricao: 'Bimestral', valores: [700], datas: ['2026-10-05', '2026-11-05'] });
  eq('caso da Ana: 700 no dia 5 e o resto no mês seguinte', v.linhas.map(l => [l.dtinicio, l.vlpagar]), [['2026-10-05', 700], ['2026-11-05', 700]]);
  eq('data vazia cai no padrão mensal', API.gerarParcelas(Object.assign({}, base, { datas: [null, null, '2026-12-20'] })).linhas.map(l => l.dtinicio), ['2026-10-01', '2026-11-01', '2026-12-20']);
  eq('data igual à anterior é recusada', API.gerarParcelas(Object.assign({}, base, { datas: [null, '2026-10-01'] })).ok, false);
  eq('data antes da anterior é recusada', API.gerarParcelas(Object.assign({}, base, { datas: [null, '2026-12-15', '2026-12-01'] })).ok, false);
  eq('última parcela no fim do plano é recusada', API.gerarParcelas(Object.assign({}, base, { datas: [null, null, '2027-01-01'] })).ok, false);
  eq('data da 1ª parcela vira o início do plano', API.gerarParcelas(Object.assign({}, base, { datas: ['2026-10-10'] })).fim, '2027-01-10');
  const L = linhas(API, Object.assign({}, base, { datas: [null, '2026-11-15', null] }), [1]);
  eq('parcela com data adiada ainda não vence antes dela', API.situacaoCobranca(L[1], L).estado, 'programada');
}

console.log('\n── Parcela paga a menos: restante fica em aberto ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  const L = linhas(API, base, []);                       // 3x de 200, nenhuma paga
  const m = L[1];                                        // parcela 2/3
  const c150 = API.calcularRecebimento(m, 0, '150');
  eq('150 de 200: sobram 50 em aberto', API.restanteDaBaixa(m, c150), 50);
  eq('valor igual ao combinado: nada em aberto', API.restanteDaBaixa(m, API.calcularRecebimento(m, 0, '200')), 0);
  eq('valor MAIOR que o combinado: nada em aberto', API.restanteDaBaixa(m, API.calcularRecebimento(m, 0, '230')), 0);
  const avulsa = { idmensalidade: 5, idatleta: 7, dshistorico: 'Mensal', dtinicio: '2026-10-01', dtvencimento: '2026-11-01', vlpagar: 200, stpgto: 'N' };
  eq('plano que não é parcelado não usa isto', API.restanteDaBaixa(avulsa, API.calcularRecebimento(avulsa, 0, '150')), 0);
  eq('com juros de 20: combinado 220, recebeu 190, restam 30', API.restanteDaBaixa(m, API.calcularRecebimento(m, 20, '190')), 30);

  let criada = null;
  API.criarMensalidade = async (corpo) => { criada = corpo; return { ok: true, idmensalidade: 999 }; };
  API.criarRestanteParcela(m, 50, { cobranca: '2026-10-06', forma: 'pix' });
  eq('restante: mesma parcela e mesma série', [criada.parcela_num, criada.parcela_total, criada.idmatricula_origem], [2, 3, 100]);
  eq('restante: em aberto, sem desconto, valor certo', [criada.stpgto, criada.vlpagar, criada.vldesconto], ['N', 50, 0]);
  eq('restante: cobrado na data combinada e vai até o fim da janela', [criada.dtinicio, criada.dtvencimento], ['2026-10-06', m.dtvencimento]);
  eq('restante: nome marcado', criada.dshistorico, 'Trimestral Treino (2/3) · restante');
  eq('restante: não gera ciclo sozinho', criada.recorrencia, null);
  API.criarRestanteParcela(m, 10, { cobranca: '2027-03-01', forma: 'dinheiro' });
  eq('data depois do fim da janela: janela ganha 1 dia', [criada.dtinicio, criada.dtvencimento], ['2027-03-01', '2027-03-02']);
  API.criarRestanteParcela(Object.assign({}, m, { dshistorico: 'Trimestral Treino (2/3) · restante' }), 10, { cobranca: '2026-10-06' });
  eq('restante do restante não repete a marca', criada.dshistorico, 'Trimestral Treino (2/3) · restante');

  // série depois da baixa parcial: parcela 2 paga com 150 + restante de 50 aberto
  const S = linhas(API, base, [1]);
  S[1] = Object.assign({}, S[1], { stpgto: 'S', dtpagamento: '2026-11-02', vlpagar: 150, vlpagamento: 150 });
  const R = { idmensalidade: 999, idatleta: 7, tipo_plano: 'Treino', stmatricula: 'ativa', forma_pgto: 'pix', idmatricula_origem: 100, parcela_num: 2, parcela_total: 3,
    dshistorico: 'Trimestral Treino (2/3) · restante', dtinicio: '2026-11-03', dtvencimento: '2026-12-01', vlpagar: 50, vldesconto: 0, stpgto: 'N' };
  const lista = [S[0], S[1], R, S[2]];
  eq('reconhece a linha do restante', [API.ehRestante(R), API.ehRestante(S[1])], [true, false]);
  eq('rótulo do restante', API.rotuloParcela(R), 'Parcela 2/3 (restante)');
  eq('descrição base ignora a marca', API.descricaoBase(R), 'Trimestral Treino');
  let rs = API.resumoParcelas(S[1], lista);
  eq('resumo: parcela 2 ainda NÃO conta como paga (falta o restante)', [rs.pagas, rs.abertas, rs.valorRestante, rs.quitado], [1, 2, 250, false]);
  eq('resumo: o que já entrou é contado', rs.valorPago, 350);
  eq('resumo: o total do plano não muda', rs.valorTotal, 600);
  const R2 = Object.assign({}, R, { stpgto: 'S', dtpagamento: '2026-11-04', vlpagamento: 50 });
  rs = API.resumoParcelas(S[1], [S[0], S[1], R2, S[2]]);
  eq('restante pago: parcela 2 passa a contar como paga uma vez só', [rs.pagas, rs.abertas, rs.valorRestante], [2, 1, 200]);
  const ren = API.dadosRenovacaoParcelada(S[0], lista);
  eq('renovação trata as duas linhas como UMA parcela de 200', [ren.parcelas, ren.valor, ren.valores], [3, 600, [200, 200]]);
  const API2 = carregar({ hoje: '2026-11-10' });
  eq('restante vencido (passou da tolerância) é dívida', API2.situacaoCobranca(R, lista).ehDivida, true);
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

console.log('\n── Editar o vencimento de UMA parcela ──');
{
  const API = carregar({ hoje: '2026-10-05' });
  const L = linhas(API, base, [1]);
  const chamadas = [];
  API.editarMensalidade = async (id, corpo) => { chamadas.push([id, corpo]); return {}; };
  const p2 = L[1];
  eq('nova cobrança entre a anterior e o fim da parcela é aceita', API.validarCobrancaParcela(p2, L, '2026-11-10', p2.dtvencimento), '');
  eq('cobrança antes da parcela anterior é recusada', !!API.validarCobrancaParcela(p2, L, '2026-10-01', p2.dtvencimento), true);
  eq('cobrança depois do fim da própria parcela é recusada', !!API.validarCobrancaParcela(p2, L, '2026-12-05', p2.dtvencimento), true);
  eq('data vazia é recusada', !!API.validarCobrancaParcela(p2, L, '', p2.dtvencimento), true);
  eq('a 1ª parcela só precisa ficar antes do fim', API.validarCobrancaParcela(L[0], L, '2026-09-20', L[0].dtvencimento), '');
  (async () => {
    const n = await API.alinharParcelaAnterior(p2, L, p2.dtinicio, '2026-11-10');
    eq('o fim da parcela anterior acompanha a nova cobrança', [n, chamadas], [1, [[100, { dtvencimento: '2026-11-10' }]]]);
    chamadas.length = 0;
    const L2 = L.map(x => Object.assign({}, x)); L2[0].dtvencimento = '2026-10-25';   // janela já descolada: não mexe
    eq('janela que não estava colada fica como está', [await API.alinharParcelaAnterior(p2, L2, p2.dtinicio, '2026-11-10'), chamadas.length], [0, 0]);
    eq('sem mudança de data não faz nada', await API.alinharParcelaAnterior(p2, L, p2.dtinicio, p2.dtinicio), 0);
    console.log('\n' + ok + ' ok, ' + ruim + ' com falha');
    process.exit(ruim ? 1 : 0);
  })();
}
