<?php
/**
 * CDD: leads (captura, funil e atendimento). Biblioteca interna (o "_" no nome bloqueia o acesso direto), carregada por _nucleo.php.
 *
 * Público: a página de captura e a de vendas mandam o formulário para a rota `lead` (sem login). Proteções: campo-isca invisível (robô preenche,
 * pessoa não), limite por IP, dados cortados e validados, aceite de contato registrado (LGPD) com data. Quem se cadastra duas vezes (mesmo
 * e-mail ou mesmo WhatsApp) atualiza o mesmo lead em vez de duplicar.
 * Funil: novo > contatado > negociando > ganho | perdido. Uma venda paga com o e-mail do lead marca "ganho" sozinha (ver cddComercialProcessar).
 */

const CDD_LEAD_STATUS = ['novo', 'contatado', 'negociando', 'ganho', 'perdido'];
const CDD_ZAP_ATENDIMENTO = '5545991156006';   // WhatsApp de atendimento da Intus/CDD

function cddLeadsDdl(string $c): array {
    return [
        'cdd_lead' => "CREATE TABLE IF NOT EXISTS cdd_lead (
            idlead INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            email VARCHAR(190) NULL,
            whatsapp VARCHAR(20) NULL,
            objetivo VARCHAR(200) NULL,
            origem VARCHAR(40) NOT NULL DEFAULT 'site',
            utm_source VARCHAR(80) NULL,
            utm_medium VARCHAR(80) NULL,
            utm_campaign VARCHAR(120) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'novo',
            obs VARCHAR(500) NULL,
            idaluna INT NULL,
            optin_em DATETIME NOT NULL,
            contato_em DATETIME NULL,
            criado_em DATETIME NOT NULL,
            atualizado_em DATETIME NOT NULL,
            INDEX idx_status (status, criado_em),
            INDEX idx_email (email),
            INDEX idx_zap (whatsapp)
        ) $c",
    ];
}

/** Só dígitos; 10 ou 11 dígitos (DDD + número) ganham o 55 do Brasil; 12 ou 13 começando em 55 ficam como estão. Vazio se não parecer telefone. */
function cddTelefone($v): string {
    $d = preg_replace('/\D+/', '', (string)$v);
    if ($d === '') return '';
    if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;
    if (!preg_match('/^55\d{10,11}$/', $d)) return '';
    return $d;
}

function cddLeadMensagemZap(string $nome): string {
    $p = trim(explode(' ', trim($nome))[0] ?? '');
    return '[CDD] Oi' . ($p !== '' ? ', ' . $p : '') . '! Aqui é a equipe do Código da Definição. Vi que você pediu informações sobre o programa. Posso te explicar como funciona?';
}

/** Rota pública: grava ou atualiza o lead. */
function cddLeadReceber(PDO $pdo, array $b, string $ip): array {
    if (!empty($b['site'])) return cddResp(200, ['ok' => true]);   // campo-isca preenchido: finge sucesso e não grava
    if (cddBloqueado($pdo, 'lead', $ip, '', 8, 8, 3600)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Tente de novo mais tarde.');
    cddRegistrarTentativa($pdo, 'ip:' . $ip, 'lead');
    $nome = cddStr($b['nome'] ?? '', 120);
    $email = cddEmail($b['email'] ?? '');
    $zap = cddTelefone($b['whatsapp'] ?? '');
    if ($nome === '' || mb_strlen($nome) < 2) return cddErro(422, 'nome_obrigatorio', 'Informe o seu nome.');
    if ($zap === '' && $email === '') return cddErro(422, 'contato_obrigatorio', 'Informe um WhatsApp com DDD ou um e-mail válido.');
    if (empty($b['aceite'])) return cddErro(422, 'aceite_obrigatorio', 'Marque a caixa para autorizar o nosso contato.');
    $objetivo = cddStr($b['objetivo'] ?? '', 200);
    $origem = preg_replace('/[^a-z0-9_-]/', '', strtolower(cddStr($b['origem'] ?? 'site', 40))) ?: 'site';
    $us = cddStr($b['utm_source'] ?? '', 80); $um = cddStr($b['utm_medium'] ?? '', 80); $uc = cddStr($b['utm_campaign'] ?? '', 120);

    $achou = null;
    if ($email !== '') { $st = $pdo->prepare('SELECT idlead FROM cdd_lead WHERE email = ? ORDER BY idlead LIMIT 1'); $st->execute([$email]); $achou = $st->fetchColumn(); }
    if (!$achou && $zap !== '') { $st = $pdo->prepare('SELECT idlead FROM cdd_lead WHERE whatsapp = ? ORDER BY idlead LIMIT 1'); $st->execute([$zap]); $achou = $st->fetchColumn(); }
    $agora = cddAgora();
    if ($achou) {
        $pdo->prepare('UPDATE cdd_lead SET nome = ?, email = COALESCE(email, NULLIF(?, \'\')), whatsapp = COALESCE(whatsapp, NULLIF(?, \'\')), objetivo = COALESCE(NULLIF(?, \'\'), objetivo), optin_em = ?, atualizado_em = ? WHERE idlead = ?')
            ->execute([$nome, $email, $zap, $objetivo, $agora, $agora, (int)$achou]);
        $id = (int)$achou;
    } else {
        $pdo->prepare('INSERT INTO cdd_lead (nome, email, whatsapp, objetivo, origem, utm_source, utm_medium, utm_campaign, status, optin_em, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$nome, $email ?: null, $zap ?: null, $objetivo ?: null, $origem, $us ?: null, $um ?: null, $uc ?: null, 'novo', $agora, $agora, $agora]);
        $id = (int)$pdo->lastInsertId();
    }
    cddRegistrarEvento($pdo, null, $achou ? 'lead_atualizado' : 'lead_novo', 'site');
    return cddResp(200, ['ok' => true, 'zap' => 'https://wa.me/' . CDD_ZAP_ATENDIMENTO . '?text=' . rawurlencode('[CDD] Oi! Sou ' . $nome . '. Quero saber mais sobre o Código da Definição.')]);
}

/** Venda paga: o lead com este e-mail (ou WhatsApp) vira "ganho" e fica ligado à aluna. */
function cddLeadGanho(PDO $pdo, string $email, int $idaluna): void {
    try {
        $pdo->prepare("UPDATE cdd_lead SET status = 'ganho', idaluna = ?, atualizado_em = ? WHERE email = ? AND status <> 'ganho'")->execute([$idaluna, cddAgora(), $email]);
    } catch (Throwable $e) {}
}

function cddLeadsAdmin(PDO $pdo, string $metodo, string $acao, array $q, array $b): ?array {
    if (strpos($acao, 'a_lead') !== 0 && $acao !== 'a_com_pendentes') return null;
    $get = ($metodo === 'GET');

    if ($acao === 'a_leads' && $get) {
        $st = cddStr($q['status'] ?? '', 12); $busca = cddStr($q['busca'] ?? '', 80);
        $sql = 'SELECT idlead, nome, email, whatsapp, objetivo, origem, utm_source, utm_campaign, status, obs, idaluna, criado_em, contato_em FROM cdd_lead'; $w = []; $par = [];
        if (in_array($st, CDD_LEAD_STATUS, true)) { $w[] = 'status = ?'; $par[] = $st; }
        if ($busca !== '') { $w[] = '(nome LIKE ? OR email LIKE ? OR whatsapp LIKE ?)'; array_push($par, '%' . $busca . '%', '%' . $busca . '%', '%' . $busca . '%'); }
        if ($w) $sql .= ' WHERE ' . implode(' AND ', $w);
        $s = $pdo->prepare($sql . ' ORDER BY idlead DESC LIMIT 400'); $s->execute($par);
        $l = $s->fetchAll();
        foreach ($l as &$x) { $x['idlead'] = (int)$x['idlead']; $x['zap'] = $x['whatsapp'] ? 'https://wa.me/' . $x['whatsapp'] . '?text=' . rawurlencode(cddLeadMensagemZap($x['nome'])) : ''; }
        $cont = []; foreach (CDD_LEAD_STATUS as $s2) $cont[$s2] = 0;
        foreach ($pdo->query('SELECT status, COUNT(*) AS n FROM cdd_lead GROUP BY status')->fetchAll() as $r) $cont[$r['status']] = (int)$r['n'];
        return cddResp(200, ['leads' => $l, 'contagem' => $cont, 'zap_atendimento' => CDD_ZAP_ATENDIMENTO]);
    }
    if ($acao === 'a_lead_atualizar' && !$get) {
        $id = (int)($b['idlead'] ?? 0);
        $st = $pdo->prepare('SELECT idlead, status FROM cdd_lead WHERE idlead = ?'); $st->execute([$id]);
        $l = $st->fetch();
        if (!$l) return cddErro(404, 'lead_nao_encontrado', 'Lead não encontrado.');
        $novo = cddStr($b['status'] ?? $l['status'], 12);
        if (!in_array($novo, CDD_LEAD_STATUS, true)) return cddErro(422, 'status_invalido', 'Situação inválida.');
        $obs = array_key_exists('obs', $b) ? cddStr($b['obs'], 500) : null;
        $contato = $novo !== 'novo' && $l['status'] === 'novo' ? cddAgora() : null;
        $pdo->prepare('UPDATE cdd_lead SET status = ?, obs = COALESCE(?, obs), contato_em = COALESCE(?, contato_em), atualizado_em = ? WHERE idlead = ?')->execute([$novo, $obs, $contato, cddAgora(), $id]);
        return cddResp(200, ['ok' => true]);
    }
    if ($acao === 'a_com_pendentes' && $get) {
        // alunas liberadas que ainda não criaram a senha (o e-mail pode ter caído no spam ou não ter saído): a equipe reenvia o link
        $l = $pdo->query("SELECT a.idaluna, a.nome, a.email, m.expira_em, m.origem FROM cdd_aluna a JOIN cdd_matricula m ON m.idaluna = a.idaluna
                          WHERE a.papel = 'aluna' AND a.status = 'ativa' AND a.senha_hash IS NULL AND m.status = 'ativa' ORDER BY a.idaluna DESC LIMIT 100")->fetchAll();
        foreach ($l as &$x) $x['idaluna'] = (int)$x['idaluna'];
        return cddResp(200, ['pendentes' => $l]);
    }
    return null;
}
