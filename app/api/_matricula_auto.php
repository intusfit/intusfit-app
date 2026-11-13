<?php
/**
 * Matrícula automática de uma compra paga no checkout da Intus (decisões do Luiz, 13/11/2026).
 * Biblioteca interna (o "_" no nome bloqueia o acesso direto), chamada por ick_webhook (_ck_intus.php) quando o Asaas avisa que o pedido foi pago.
 *
 * O que faz, nesta ordem:
 *  1. Acha o aluno pelo e-mail ou cria o cadastro (nome, e-mail, WhatsApp, CPF, origem "professor", senha aleatória que ninguém conhece: o aluno cria a dele pelo "Esqueci a senha").
 *  2. Grava a matrícula em intus_mensalidade, igual ao que o painel grava:
 *     - Pix ou cartão à vista: UM ciclo pago (valor total, início hoje, vencimento no fim do período, recorrência do plano) e o ciclo seguinte já em aberto,
 *       como o painel faz na baixa de um plano recorrente.
 *     - Cartão parcelado: a série de parcelas do plano parcelado (mesmas linhas de API.gerarParcelas), TODAS com baixa na data da compra (o valor total foi cobrado
 *       do cliente), marcadas "Asaas checkout" para o Financeiro mostrar o dinheiro caindo parcela a parcela, como o Asaas repassa (ver API.gateway.itemDeMatricula).
 *       Plano parcelado não gera ciclo novo sozinho: depois da última parcela a renovação é a ação ↻ (regra de sempre dos planos parcelados).
 *  3. Quem já é aluno e já tem matrícula: o novo ciclo começa no fim do que já está pago (ou hoje) e a equipe é avisada para conferir.
 * Nada é apagado, nada existente é alterado, nenhuma ficha é criada. A equipe sempre recebe o aviso "REVISAR" com o que foi gravado.
 * SQL portável (SQLite nos testes, MySQL 5.6 na produção).
 */

const IMA_PLANO_TIPOS = ['treino' => 'Treino', 'dieta' => 'Nutrição', 'treino-dieta' => 'Combo (Treino + Dieta)', 'teste' => 'Teste checkout'];
const IMA_RECORRENCIA = [3 => 'trimestral', 6 => 'semestral', 12 => 'anual'];

function ima_hoje(): string { return (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d'); }

/** Soma meses a uma data ISO sem estourar o mês curto (31/01 + 1 mês = 28/02). Mesma regra de API._somarMeses. */
function ima_somar_meses(string $iso, int $n): string {
    $d = new DateTime($iso . ' 12:00:00');
    $dia = (int)$d->format('j');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), 1);
    $d->modify(($n >= 0 ? '+' : '') . $n . ' months');
    $d->setDate((int)$d->format('Y'), (int)$d->format('n'), min($dia, (int)$d->format('t')));
    return $d->format('Y-m-d');
}

/**
 * Linhas de um plano parcelado em partes iguais (o resto dos centavos vai para a última). Mesma conta de API.gerarParcelas sem desconto nem valores combinados.
 * @return array linhas [parcela_num, parcela_total, dtinicio, dtvencimento, vlpagar]
 */
function ima_gerar_parcelas(string $inicio, int $meses, int $parcelas, float $total): array {
    $intervalo = max(1, intdiv($meses, $parcelas));
    $tc = (int)round($total * 100); $base = intdiv($tc, $parcelas);
    $fim = ima_somar_meses($inicio, $meses);
    $inis = []; for ($i = 0; $i < $parcelas; $i++) $inis[] = ima_somar_meses($inicio, $i * $intervalo);
    $r = [];
    for ($i = 1; $i <= $parcelas; $i++) {
        $ultima = $i === $parcelas;
        $r[] = ['parcela_num' => $i, 'parcela_total' => $parcelas, 'dtinicio' => $inis[$i - 1], 'dtvencimento' => $ultima ? $fim : $inis[$i],
                'vlpagar' => ($ultima ? $tc - $base * ($parcelas - 1) : $base) / 100];
    }
    return $r;
}

function ima_colunas(PDO $pdo, string $tabela): array {
    $st = $pdo->query("SELECT * FROM `$tabela` LIMIT 0");
    $r = []; for ($i = 0; $i < $st->columnCount(); $i++) { $m = $st->getColumnMeta($i); if (!empty($m['name'])) $r[] = $m['name']; }
    return $r;
}
function ima_pick(array $cols, array $cand): ?string { foreach ($cand as $c) if (in_array($c, $cols, true)) return $c; return null; }

function ima_tabela_atleta(PDO $pdo): ?string {
    foreach (['atleta', 'atletas', 'aluno', 'alunos'] as $t) { try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); return $t; } catch (Throwable $e) {} }
    return null;
}

/** Documento só com letras e números, em maiúsculas (CPF "529.982.247-25" e passaporte "ab 123456" viram comparáveis). */
function ima_doc_norm($v): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$v)); }

/**
 * Matricula o pedido. Devolve ['ok' => bool, 'erro' => ?, 'idatleta', 'novo_aluno' => bool, 'ids' => [idmensalidade...], 'avisos' => [texto...], 'recorrente' => bool].
 * Nunca lança exceção: o webhook não pode cair por causa disto.
 */
function ima_matricular(PDO $pdo, array $p): array {
    $abriu = false;
    try {
        $tabela = ima_tabela_atleta($pdo);
        if (!$tabela) return ['ok' => false, 'erro' => 'tabela de alunos não encontrada', 'ids' => [], 'avisos' => []];
        $cols = ima_colunas($pdo, $tabela);
        $cId = ima_pick($cols, ['idatleta', 'idaluno', 'id']); $cNome = ima_pick($cols, ['nome', 'nmatleta', 'nmaluno', 'name']);
        $cEmail = ima_pick($cols, ['email', 'dsemail', 'nmemail']); $cSenha = ima_pick($cols, ['senha', 'dssenha', 'password', 'hash']);
        $cTel = ima_pick($cols, ['telefone', 'nrtelefone', 'dstelefone', 'tel', 'celular']); $cBloq = ima_pick($cols, ['stbloqueio', 'bloqueado', 'stativo']);
        if (!$cId || !$cNome || !$cEmail || !$cSenha) return ['ok' => false, 'erro' => 'colunas de aluno não reconhecidas', 'ids' => [], 'avisos' => []];
        $avisos = [];
        $email = strtolower(trim((string)$p['email']));
        $abriu = $pdo->beginTransaction();   // aluno e matrícula entram juntos (se alguma tabela não for transacional, a falha é avisada à equipe)

        // 1. aluno: o DOCUMENTO (CPF ou documento estrangeiro) manda; o e-mail só é usado quando o cadastro não tem documento
        $doc = ima_doc_norm($p['cpf'] ?? ''); $temCpf = in_array('cpf', $cols, true);
        $conflito = null; $novo = false; $idatleta = 0;
        $st = $pdo->prepare("SELECT `$cId` FROM `$tabela` WHERE LOWER(`$cEmail`) = ? LIMIT 1"); $st->execute([$email]);
        $idPorEmail = (int)$st->fetchColumn();
        $porDoc = [];
        if ($doc !== '' && $temCpf) {
            $sd = $pdo->prepare("SELECT `$cId` AS id, `$cEmail` AS email FROM `$tabela` WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(`cpf`, '.', ''), '-', ''), ' ', ''), '/', '')) = ? ORDER BY `$cId` LIMIT 5");
            $sd->execute([$doc]); $porDoc = $sd->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($porDoc) {
            $idatleta = (int)$porDoc[0]['id']; $emailAtual = strtolower(trim((string)$porDoc[0]['email']));
            $avisos[] = 'O documento ' . $doc . ' JÁ TINHA cadastro (id ' . $idatleta . ', e-mail ' . $emailAtual . '). A matrícula foi ligada a ele, sem criar outro cadastro.';
            if (count($porDoc) > 1) $avisos[] = 'Há MAIS DE UM cadastro com este documento (ids ' . implode(', ', array_column($porDoc, 'id')) . '): usei o mais antigo. Unifique.';
            if ($idPorEmail && $idPorEmail !== $idatleta) $avisos[] = 'O e-mail da compra (' . $email . ') pertence a OUTRO cadastro (id ' . $idPorEmail . '). Não mexi em nenhum dos dois: unifique à mão.';
            elseif ($emailAtual !== $email) $conflito = ['idatleta' => $idatleta, 'email_atual' => $emailAtual, 'email_novo' => $email];
        } elseif ($idPorEmail) {
            $idatleta = $idPorEmail;
            if ($temCpf && $doc !== '') {
                $sc = $pdo->prepare("SELECT `cpf` FROM `$tabela` WHERE `$cId` = ?"); $sc->execute([$idatleta]);
                $cpfAtual = ima_doc_norm($sc->fetchColumn());
                if ($cpfAtual === '') { $pdo->prepare("UPDATE `$tabela` SET `cpf` = ? WHERE `$cId` = ?")->execute([$doc, $idatleta]); $avisos[] = 'O cadastro (id ' . $idatleta . ') não tinha documento: preenchi com o da compra.'; }
                elseif ($cpfAtual !== $doc) $avisos[] = 'O cadastro com este e-mail (id ' . $idatleta . ') tem OUTRO documento (' . $cpfAtual . ') que o da compra (' . $doc . '). Confira se é a mesma pessoa.';
            }
        }
        if (!$idatleta) {
            $c = [$cNome => $p['nome'], $cEmail => $email, $cSenha => password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT)];
            if ($cTel) $c[$cTel] = $p['telefone'];
            if ($temCpf) $c['cpf'] = $doc;
            if (in_array('origem', $cols, true)) $c['origem'] = 'professor';
            if (in_array('dt_cadastro', $cols, true)) $c['dt_cadastro'] = date('Y-m-d H:i:s');
            if (in_array('ranking_optin', $cols, true)) $c['ranking_optin'] = 'S';
            if ($cBloq === 'stativo') $c[$cBloq] = 'S'; elseif ($cBloq) $c[$cBloq] = 'N';
            if (in_array('codacesso', $cols, true)) $c['codacesso'] = 'INT' . strtoupper(bin2hex(random_bytes(3))) . random_int(0, 9);
            $pdo->prepare('INSERT INTO `' . $tabela . '` (' . implode(',', array_map(function ($k) { return "`$k`"; }, array_keys($c))) . ') VALUES (' . implode(',', array_fill(0, count($c), '?')) . ')')->execute(array_values($c));
            $idatleta = (int)$pdo->lastInsertId(); $novo = true;
        }

        // 2. o que o aluno já tem: o MESMO plano com as datas batendo é renovação; plano de outro tipo (ex.: tem Treino e paga Dieta) é matrícula nova, em paralelo
        $hoje = ima_hoje();
        $meses = (int)$p['meses']; $total = (float)$p['valor']; $parc = max(1, (int)$p['parcelas']);
        $tipo = IMA_PLANO_TIPOS[$p['produto']] ?? 'Outro';
        $rotulo = ['tri' => 'Trimestral', 'sem' => 'Semestral', 'anual' => 'Anual'][$p['periodo']] ?? ucfirst((string)$p['periodo']);
        $nomePlano = $rotulo . ' · ' . $tipo;
        $forma = $p['forma'] === 'cartao' ? 'asaas_cartao' : 'asaas_pix';
        $rec = IMA_RECORRENCIA[$meses] ?? null;
        $q = $pdo->prepare("SELECT * FROM intus_mensalidade WHERE idatleta = ? ORDER BY idmensalidade"); $q->execute([$idatleta]);
        $ehTeste = function (array $m) { return (bool)preg_match('/\bteste\b|gr[aá]tis|cortesia|trial/i', (string)$m['tipo_plano'] . ' ' . (string)$m['dshistorico']) && stripos((string)$m['tipo_plano'], 'Teste checkout') === false; };
        $vivas = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $m) {
            if (in_array((string)$m['stmatricula'], ['cancelada', 'encerrada'], true) || $ehTeste($m)) continue;
            $vivas[] = $m;
        }
        $mesmoTipo = array_values(array_filter($vivas, function ($m) use ($tipo) { return mb_strtolower(trim((string)$m['tipo_plano'])) === mb_strtolower($tipo); }));
        $outrosTipos = array_values(array_unique(array_filter(array_map(function ($m) use ($tipo) { $x = trim((string)$m['tipo_plano']); return mb_strtolower($x) === mb_strtolower($tipo) ? '' : ($x === '' ? '(sem tipo)' : $x); }, $vivas))));
        if ($outrosTipos) $avisos[] = 'O aluno já tinha plano de OUTRO tipo (' . implode(', ', $outrosTipos) . '). Este é um plano diferente, então criei uma matrícula NOVA, em paralelo, começando hoje. Se era troca ou upgrade, encerre o plano antigo no painel.';
        // ciclo em aberto do mesmo plano (mesmo tipo e mesma duração) com início perto de hoje: é o que esta compra paga
        $aberto = null;
        foreach ($mesmoTipo as $m) {
            if ($m['stpgto'] !== 'N' || !empty($m['parcela_num']) || $rec === null || (string)$m['recorrencia'] !== $rec || empty($m['dtinicio'])) continue;
            $dias = abs((int)round((strtotime($m['dtinicio']) - strtotime($hoje)) / 86400));
            if ($dias <= 45 && ($aberto === null || $m['dtinicio'] < $aberto['dtinicio'])) $aberto = $m;
        }
        // início da matrícula nova do mesmo tipo: hoje, ou o fim do que já está pago desse plano
        $inicio = $hoje;
        foreach ($mesmoTipo as $m) if ($m['stpgto'] === 'S' && (string)$m['dtvencimento'] > $inicio) $inicio = (string)$m['dtvencimento'];
        $ins = $pdo->prepare('INSERT INTO intus_mensalidade (idatleta, dshistorico, dtinicio, dtvencimento, vlpagar, vldesconto, vljuro, stpgto, dtpagamento, vlpagamento, dspagamento, professor,
                              tipo_plano, stmatricula, idmatricula_origem, parcela_num, parcela_total, forma_pgto, recorrencia) VALUES (?, ?, ?, ?, ?, 0, 0, ?, ?, ?, ?, NULL, ?, \'ativa\', ?, ?, ?, ?, ?)');
        $ids = []; $renovacao = false;
        $dsp = 'Asaas checkout: ' . ($parc > 1 ? 'cartão em ' . $parc . 'x (liberação mensal)' : ($forma === 'asaas_pix' ? 'Pix' : 'cartão à vista'));
        if ($aberto !== null && $parc === 1) {
            // RENOVAÇÃO: dá a baixa no ciclo em aberto (como o painel) e gera o seguinte
            $renovacao = true;
            $pdo->prepare("UPDATE intus_mensalidade SET stpgto = 'S', dtpagamento = ?, vlpagamento = ?, dspagamento = ?, forma_pgto = ? WHERE idmensalidade = ? AND stpgto = 'N'")
                ->execute([$hoje, $total, $dsp, $forma, (int)$aberto['idmensalidade']]);
            $ids[] = (int)$aberto['idmensalidade'];
            $avisos[] = 'RENOVAÇÃO: o plano pago é o mesmo do ciclo em aberto (' . $aberto['dshistorico'] . ', início ' . date('d/m/Y', strtotime($aberto['dtinicio'])) . '). Dei a baixa nesse ciclo em vez de criar matrícula nova.';
            if (abs((float)$aberto['vlpagar'] - $total) > 0.009) $avisos[] = 'O valor pago (R$ ' . number_format($total, 2, ',', '.') . ') é diferente do combinado no ciclo (R$ ' . number_format((float)$aberto['vlpagar'], 2, ',', '.') . '). Confira o preço.';
            $venc = (string)$aberto['dtvencimento'];
            $jaTem = $pdo->prepare("SELECT COUNT(*) FROM intus_mensalidade WHERE idatleta = ? AND stpgto = 'N' AND dtinicio >= ? AND idmensalidade <> ? AND stmatricula NOT IN ('cancelada', 'encerrada') AND LOWER(COALESCE(tipo_plano, '')) = ?");
            $jaTem->execute([$idatleta, $venc, (int)$aberto['idmensalidade'], mb_strtolower($tipo)]);
            if ((int)$jaTem->fetchColumn() === 0) {
                $ins->execute([$idatleta, $aberto['dshistorico'], $venc, ima_somar_meses($venc, $meses), (float)$aberto['vlpagar'], 'N', null, null, null, $aberto['tipo_plano'], (int)($aberto['idmatricula_origem'] ?: $aberto['idmensalidade']), null, null, null, $rec]);
                $ids[] = (int)$pdo->lastInsertId();
                $avisos[] = 'Ciclo seguinte criado em aberto (vence em ' . date('d/m/Y', strtotime(ima_somar_meses($venc, $meses))) . ').';
            }
        } else {
            if ($mesmoTipo) {
                if ($aberto !== null && $parc > 1) {
                    $inicio = (string)$aberto['dtinicio'] > $hoje ? (string)$aberto['dtinicio'] : $hoje;
                    $pdo->prepare("UPDATE intus_mensalidade SET stmatricula = 'cancelada' WHERE idmensalidade = ? AND stpgto = 'N'")->execute([(int)$aberto['idmensalidade']]);
                    $avisos[] = 'RENOVAÇÃO PARCELADA: o ciclo em aberto (' . $aberto['dshistorico'] . ') foi marcado como cancelado porque a série de parcelas paga o mesmo período. Se não era isso, reative-o no painel.';
                } else {
                    $avisos[] = 'O aluno já tinha plano ' . $tipo . ' ativo, mas ESTA compra não bate com ele (outra duração ou datas longe do início do ciclo em aberto, ou pagamento parcelado sem ciclo em aberto). Criei uma matrícula nova começando em ' . date('d/m/Y', strtotime($inicio)) . ' (fim do que já estava pago). Confira se era renovação ou troca de plano e se existe ciclo antigo em aberto (cobraria em dobro).';
                }
            }
            if ($parc > 1) {
                $raiz = null;
                foreach (ima_gerar_parcelas($inicio, $meses, $parc, $total) as $l) {
                    $ins->execute([$idatleta, $nomePlano . ' (' . $l['parcela_num'] . '/' . $l['parcela_total'] . ')', $l['dtinicio'], $l['dtvencimento'], $l['vlpagar'], 'S', $hoje, $l['vlpagar'], $dsp, $tipo, $raiz, $l['parcela_num'], $l['parcela_total'], $forma, null]);
                    $id = (int)$pdo->lastInsertId(); $ids[] = $id; if ($raiz === null) $raiz = $id;
                }
                $avisos[] = 'Cartão parcelado: a série de ' . $parc . ' parcelas foi criada com baixa na data da compra (valor total). O Financeiro mostra o dinheiro caindo mês a mês, como o Asaas repassa. Plano parcelado não renova sozinho: use ↻ perto do fim.';
            } else {
                $venc = ima_somar_meses($inicio, $meses);
                $ins->execute([$idatleta, $nomePlano, $inicio, $venc, $total, 'S', $hoje, $total, $dsp, $tipo, null, null, null, $forma, $rec]);
                $id = (int)$pdo->lastInsertId(); $ids[] = $id;
                if ($rec) {   // o ciclo seguinte já em aberto, como o painel faz na baixa de um plano recorrente
                    $ins->execute([$idatleta, $nomePlano, $venc, ima_somar_meses($venc, $meses), $total, 'N', null, null, null, $tipo, $id, null, null, null, $rec]);
                    $ids[] = (int)$pdo->lastInsertId();
                    $avisos[] = 'Ciclo seguinte criado em aberto (vence em ' . date('d/m/Y', strtotime(ima_somar_meses($venc, $meses))) . '): a cobrança dele aparece perto da data, como nos outros planos recorrentes.';
                }
            }
        }
        if ($abriu) $pdo->commit();
        return ['ok' => true, 'conflito' => $conflito, 'idatleta' => $idatleta, 'novo_aluno' => $novo, 'ids' => $ids, 'avisos' => $avisos, 'recorrente' => $parc === 1 && isset(IMA_RECORRENCIA[$meses]), 'renovacao' => $renovacao, 'inicio' => $inicio];
    } catch (Throwable $e) {
        if ($abriu && $pdo->inTransaction()) { try { $pdo->rollBack(); } catch (Throwable $e2) {} }
        @error_log('[matricula auto] ' . $e->getMessage());
        return ['ok' => false, 'erro' => $e->getMessage(), 'ids' => [], 'avisos' => []];
    }
}
