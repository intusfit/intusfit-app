<?php
/**
 * Helper para registrar audit trail.
 * Uso: require_once __DIR__ . '/_audit_log.php'; auditLog($pdo, $tok, 'editar', 'atleta', 42, 'Editou atleta Fulano', $antes, $depois);
 */

function auditLog(PDO $pdo, string $tok, string $acao, string $entidade, ?int $entidade_id = null, ?string $descricao = null, $dados_antes = null, $dados_depois = null) {
    try {
        // Ensure table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS intus_audit (
            idaudit       INT AUTO_INCREMENT PRIMARY KEY,
            idusuario     INT NULL,
            nmusuario     VARCHAR(100) NULL,
            acao          VARCHAR(50) NOT NULL,
            entidade      VARCHAR(50) NOT NULL,
            entidade_id   INT NULL,
            descricao     VARCHAR(300) NULL,
            dados_antes   LONGTEXT NULL,
            dados_depois  LONGTEXT NULL,
            desfeito      TINYINT(1) DEFAULT 0,
            desfeito_por  INT NULL,
            dt_desfeito   DATETIME NULL,
            dtcriacao     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dt (dtcriacao),
            INDEX idx_entidade (entidade, entidade_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $idusuario = 0;
        $nmusuario = '';
        if (preg_match('/^(?:prof|local)-(\d+)-/', $tok, $m)) {
            $idusuario = (int)$m[1];
        }
        if ($idusuario > 0) {
            $userTable = null;
            foreach (['professor', 'usuario', 'usuarios', 'professores'] as $t) {
                try { $pdo->query("SELECT 1 FROM `$t` LIMIT 1"); $userTable = $t; break; } catch (Throwable $e) {}
            }
            if ($userTable) {
                $cols = array_column($pdo->query("SHOW COLUMNS FROM `$userTable`")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                $colId = null;
                foreach (['idusuario', 'idprofessor', 'id'] as $c) { if (in_array($c, $cols)) { $colId = $c; break; } }
                $colNome = null;
                foreach (['nome', 'nmusuario', 'nmprofessor', 'name'] as $c) { if (in_array($c, $cols)) { $colNome = $c; break; } }
                if ($colId && $colNome) {
                    $st = $pdo->prepare("SELECT `$colNome` FROM `$userTable` WHERE `$colId` = ? LIMIT 1");
                    $st->execute([$idusuario]);
                    $nmusuario = $st->fetchColumn() ?: '';
                }
            }
        }

        $st = $pdo->prepare("INSERT INTO intus_audit (idusuario, nmusuario, acao, entidade, entidade_id, descricao, dados_antes, dados_depois) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $st->execute([
            $idusuario ?: null,
            $nmusuario,
            $acao,
            $entidade,
            $entidade_id,
            $descricao ? mb_substr($descricao, 0, 300) : null,
            $dados_antes ? json_encode($dados_antes) : null,
            $dados_depois ? json_encode($dados_depois) : null,
        ]);
    } catch (Throwable $e) {
        // Audit logging should never break the main operation
    }
}
