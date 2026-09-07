<?php
// ── UTF8MB4 SELF-HEAL ────────────────────────────────────────────────────
// Varias tabelas deste projeto ja diziam CHARSET=utf8mb4 no CREATE TABLE,
// mas "IF NOT EXISTS" nao corrige uma tabela que ja existia de antes com
// outro charset (varias telas mexem no mesmo banco compartilhado desde
// antes deste projeto). Emoji de 4 bytes (a maioria dos emojis modernos,
// tipo 🔥) nao cabe em utf8/latin1 de 3 bytes: o MySQL guarda um "?" no
// lugar, sem avisar ninguem. A consulta ao information_schema e barata e
// so dispara o ALTER quando encontra coluna fora de utf8mb4 — seguro de
// chamar em toda requisicao.
function _intusGarantirUtf8mb4(PDO $pdo, string $tabela, array $colunas): void {
    try {
        $placeholders = implode(',', array_fill(0, count($colunas), '?'));
        $st = $pdo->prepare(
            "SELECT COLUMN_NAME, CHARACTER_SET_NAME FROM information_schema.COLUMNS " .
            "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($placeholders)"
        );
        $st->execute(array_merge([$tabela], $colunas));
        $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$linhas) return; // tabela/colunas ainda nao existem neste request
        $desatualizada = false;
        foreach ($linhas as $row) {
            if (strtolower((string)($row['CHARACTER_SET_NAME'] ?? '')) !== 'utf8mb4') { $desatualizada = true; break; }
        }
        if ($desatualizada) {
            $pdo->exec("ALTER TABLE `$tabela` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            @error_log("[intus utf8mb4] convertida: $tabela (" . implode(',', $colunas) . ')');
        }
    } catch (Throwable $e) {
        @error_log('[intus utf8mb4] falha ao verificar/corrigir ' . $tabela . ': ' . $e->getMessage());
    }
}
