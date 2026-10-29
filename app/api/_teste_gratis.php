<?php
/**
 * Teste grátis: copia para a conta nova as fichas ATIVAS do aluno-modelo (modelo.masculino@intusfit.local
 * ou modelo.feminino@intusfit.local, pelo gênero informado; sem gênero vale o masculino).
 *
 * Copia TODAS as colunas da ficha e dos treinos (lê com SELECT *), menos as chaves e os carimbos de data.
 * Antes eram listadas à mão e ficaram de fora a ordem das divisões (div_ordem), as técnicas por série
 * (tecnicas) e o unilateral: o aluno do teste via um treino diferente do exemplo. Coluna que o banco
 * ganhar no futuro passa a ser copiada sozinha.
 *
 * Devolve ['modelo' => achou o aluno-modelo, 'fichas' => n copiadas, 'treinos' => n copiados].
 * Nunca derruba o cadastro: qualquer erro vai para o log do servidor e o cadastro segue.
 */
function intusCopiarFichasModelo(PDO $pdo, string $tabela, string $colId, string $colEmail, int $novoId, string $genero, string $dtInicio, string $dtFim): array {
    $out = ['modelo' => false, 'fichas' => 0, 'treinos' => 0];
    try {
        $email = (strtoupper($genero) === 'F') ? 'modelo.feminino@intusfit.local' : 'modelo.masculino@intusfit.local';
        $st = $pdo->prepare("SELECT `$colId` FROM `$tabela` WHERE `$colEmail` = ? LIMIT 1");
        $st->execute([$email]);
        $modeloId = $st->fetchColumn();
        if (!$modeloId) { @error_log("[intus teste gratis] aluno-modelo $email nao encontrado: conta $novoId ficou sem treino"); return $out; }
        $out['modelo'] = true;

        $st = $pdo->prepare("SELECT * FROM intus_ficha WHERE idatleta = ? AND ativo = 1 ORDER BY idficha");
        $st->execute([$modeloId]);
        $fichas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$fichas) { @error_log("[intus teste gratis] $email sem ficha ativa: conta $novoId ficou sem treino"); return $out; }

        foreach ($fichas as $fm) {
            $idModelo = $fm['idficha'];
            $f = $fm;
            unset($f['idficha'], $f['created_at'], $f['updated_at']);
            $f['idatleta'] = $novoId; $f['dtinicio'] = $dtInicio; $f['dtfim'] = $dtFim; $f['ativo'] = 1;
            if (($f['observacao'] ?? null) === null) $f['observacao'] = '';
            $novaId = _tgInserir($pdo, 'intus_ficha', $f);
            $out['fichas']++;

            $st = $pdo->prepare("SELECT * FROM intus_treino WHERE idficha = ? ORDER BY idtreino");
            $st->execute([$idModelo]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                unset($tr['idtreino'], $tr['created_at'], $tr['updated_at']);
                $tr['idficha'] = $novaId;
                _tgInserir($pdo, 'intus_treino', $tr);
                $out['treinos']++;
            }
        }
    } catch (Throwable $e) {
        @error_log('[intus teste gratis] copia do treino modelo falhou (conta ' . $novoId . '): ' . $e->getMessage());
    }
    return $out;
}

/** INSERT genérico a partir de um array coluna => valor. Devolve o id novo. */
function _tgInserir(PDO $pdo, string $tabela, array $linha): int {
    $cols = array_keys($linha);
    $sql = "INSERT INTO `$tabela` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
    $pdo->prepare($sql)->execute(array_values($linha));
    return (int)$pdo->lastInsertId();
}
