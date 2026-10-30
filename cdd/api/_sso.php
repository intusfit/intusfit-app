<?php
/**
 * CDD: entrada da equipe vinda do painel da Intus, sem digitar senha (ver app/api/cdd_sso.php, que emite o bilhete).
 * O bilhete é "<corpo>.<assinatura>": corpo = JSON em base64url {e: e-mail, n: nome, i: id na Intus, x: validade (unix), r: aleatório};
 * assinatura = HMAC-SHA256 do corpo com o segredo compartilhado (cdd_config.sso_segredo). Vale 60 s e só uma vez (o aleatório fica gravado).
 * Quem chega assim vira (ou já é) conta de EQUIPE do CDD com aquele e-mail e ganha uma sessão de 7 dias.
 * Conta de ALUNA com o mesmo e-mail nunca é promovida por aqui: fica recusado, para ninguém virar administrador por coincidência de e-mail.
 */

function cddSsoVerificar(PDO $pdo, string $bilhete, ?int $agora = null): array {
    $agora = $agora ?? time();
    $seg = cddConfig($pdo, 'sso_segredo', '');
    if (strlen($seg) < 32) return [false, 'sso_nao_configurado', 'O vínculo com o painel da Intus ainda não foi preparado. Abra o CDD uma vez pelo painel da Intus.'];
    $p = explode('.', trim($bilhete));
    if (count($p) !== 2 || $p[0] === '' || $p[1] === '') return [false, 'bilhete_invalido', 'Bilhete inválido.'];
    $dec = function (string $s) { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); };
    $esperada = rtrim(strtr(base64_encode(hash_hmac('sha256', $p[0], $seg, true)), '+/', '-_'), '=');
    if (!hash_equals($esperada, $p[1])) return [false, 'bilhete_invalido', 'Bilhete inválido.'];
    $j = json_decode((string)$dec($p[0]), true);
    if (!is_array($j) || empty($j['e']) || empty($j['x']) || empty($j['r'])) return [false, 'bilhete_invalido', 'Bilhete inválido.'];
    if ((int)$j['x'] < $agora) return [false, 'bilhete_expirado', 'O bilhete venceu. Volte ao painel da Intus e clique em CDD de novo.'];
    $email = cddEmail((string)$j['e']);
    if ($email === '') return [false, 'bilhete_invalido', 'Bilhete inválido.'];
    return [true, $j, $email];
}

/** Troca o bilhete por uma sessão de equipe. Devolve resposta no formato do cddTratar. */
function cddSsoEntrar(PDO $pdo, string $bilhete, string $ip): array {
    if (cddBloqueado($pdo, 'sso', $ip, '', 15, 15)) return cddErro(429, 'muitas_tentativas', 'Muitas tentativas. Aguarde alguns minutos.');
    $r = cddSsoVerificar($pdo, $bilhete);
    if ($r[0] !== true) { cddRegistrarTentativa($pdo, 'ip:' . $ip, 'sso'); return cddErro(401, $r[1], $r[2]); }
    [, $j, $email] = $r;
    // uso único: o aleatório do bilhete é gravado; repetir o mesmo bilhete é recusado
    $uso = 'sso:' . cddStr((string)$j['r'], 40);
    $ja = $pdo->prepare('SELECT 1 FROM cdd_evento WHERE id_externo = ?'); $ja->execute([$uso]);
    if ($ja->fetchColumn()) { cddRegistrarTentativa($pdo, 'ip:' . $ip, 'sso'); return cddErro(401, 'bilhete_usado', 'Este bilhete já foi usado. Volte ao painel da Intus e clique em CDD de novo.'); }
    $st = $pdo->prepare('SELECT idaluna, nome, papel, status FROM cdd_aluna WHERE email = ?'); $st->execute([$email]);
    $c = $st->fetch();
    if ($c && $c['papel'] !== 'admin') return cddErro(403, 'conta_de_aluna', 'Já existe uma conta de aluna com este e-mail no CDD, então ela não pode virar equipe por aqui. Use outro e-mail na Intus ou fale com o administrador.');
    if ($c && $c['status'] !== 'ativa') return cddErro(403, 'conta_bloqueada', 'Esta conta de equipe está bloqueada no CDD.');
    $novo = false;
    if (!$c) {
        $nome = cddStr((string)($j['n'] ?? ''), 160); if ($nome === '') $nome = $email;
        $pdo->prepare('INSERT INTO cdd_aluna (nome, email, senha_hash, sexo, papel, status, termos_versao, termos_em, criado_em) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?)')
            ->execute([$nome, $email, 'masculino', 'admin', 'ativa', CDD_TERMOS_VERSAO, cddAgora(), cddAgora()]);
        $id = (int)$pdo->lastInsertId(); $novo = true; $nomeFinal = $nome;
    } else { $id = (int)$c['idaluna']; $nomeFinal = $c['nome']; }
    try { $pdo->prepare('INSERT INTO cdd_evento (idaluna, tipo, origem, id_externo, payload, resultado, criado_em) VALUES (?, ?, ?, ?, NULL, ?, ?)')->execute([$id, $novo ? 'equipe_criada_sso' : 'entrada_sso', 'intus', $uso, 'ok', cddAgora()]); }
    catch (Throwable $e) { return cddErro(401, 'bilhete_usado', 'Este bilhete já foi usado.'); }
    cddLimparTentativas($pdo, 'ip:' . $ip, 'sso');
    $t = cddCriarSessao($pdo, $id, $ip, 7);
    return cddResp(200, ['token' => $t, 'usuario' => ['idaluna' => $id, 'nome' => $nomeFinal, 'email' => $email, 'papel' => 'admin']]);
}
