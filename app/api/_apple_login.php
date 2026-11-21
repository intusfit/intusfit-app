<?php
/**
 * Entrar com Apple (Sign in with Apple) e alerta de possíveis cadastros duplicados.
 *
 * O app (iOS) pega o "identity token" da Apple e manda para aluno_login.php?action=oauth_apple. Aqui o token é verificado de verdade (assinatura RS256
 * com as chaves públicas da própria Apple, emissor, destinatário e validade); o e-mail que o cliente diz ter nunca é aceito sozinho.
 *
 * Regras (as mesmas do Google e do Facebook: a conta de aluno NUNCA nasce por aqui, só por cadastro ou pelo professor):
 *  1. A Apple já foi ligada a um aluno (intus_apple_vinculo, pelo "sub" estável da Apple): entra nessa conta.
 *  2. Não foi ligada, mas o e-mail verificado é o de um aluno: entra e grava a ligação.
 *  3. Nada casa: vira lead e, se houver risco de ser alguém que já existe, nasce um alerta (intus_login_alerta) e o administrador recebe e-mail.
 *     Riscos: e-mail oculto da Apple ("Ocultar e-mail", @privaterelay.appleid.com), que nunca bate com o cadastro; mesmo nome de um aluno ou lead; mesmo e-mail de um lead.
 *  Quem já é aluno e entrou com e-mail oculto liga a Apple à própria conta com e-mail e senha (aluno_login.php?action=apple_vincular), o que fecha o alerta.
 */

const INTUS_APPLE_ISS = 'https://appleid.apple.com';
const INTUS_APPLE_AUDIENCIAS = ['br.com.intusfit.app'];   // bundle id do app iOS (a Apple põe o bundle id no "aud" do login nativo)

function _appleB64uDec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

function _appleDerLen(int $n): string
{
    if ($n < 128) return chr($n);
    $b = ltrim(pack('N', $n), "\0");
    return chr(0x80 | strlen($b)) . $b;
}

/** Chave pública RSA (n, e do JWK) em PEM (SubjectPublicKeyInfo). */
function intusAppleJwkParaPem(array $jwk): string
{
    $n = _appleB64uDec((string)($jwk['n'] ?? '')); $e = _appleB64uDec((string)($jwk['e'] ?? ''));
    if ($n === '' || $e === '') return '';
    $int = fn(string $v) => "\x02" . _appleDerLen(strlen("\0" . $v)) . "\0" . $v;   // inteiro positivo
    $pkcs1 = "\x30" . _appleDerLen(strlen($int($n) . $int($e))) . $int($n) . $int($e);
    $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bits = "\x03" . _appleDerLen(strlen("\0" . $pkcs1)) . "\0" . $pkcs1;
    $spki = "\x30" . _appleDerLen(strlen($algo . $bits)) . $algo . $bits;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** Chaves públicas da Apple (JWKS). Guardadas por 6 h no disco temporário. $GLOBALS['INTUS_APPLE_JWKS'] troca a fonte nos testes. */
function intusAppleChaves(): array
{
    if (!empty($GLOBALS['INTUS_APPLE_JWKS'])) return $GLOBALS['INTUS_APPLE_JWKS'];
    $arq = sys_get_temp_dir() . '/intus-apple-jwks.json';
    if (is_file($arq) && filemtime($arq) > time() - 21600) { $j = json_decode((string)@file_get_contents($arq), true); if (!empty($j['keys'])) return $j['keys']; }
    $ctx = stream_context_create(['http' => ['timeout' => 8]]);
    $resp = @file_get_contents('https://appleid.apple.com/auth/keys', false, $ctx);
    $j = $resp ? json_decode($resp, true) : null;
    if (!empty($j['keys'])) { @file_put_contents($arq, $resp, LOCK_EX); return $j['keys']; }
    return [];
}

/** Verifica o identity token. Devolve os dados (sub, email, email_verificado, email_oculto) ou null se qualquer conferência falhar. */
function intusAppleVerificar(string $token, ?array $auds = null, ?int $agora = null): ?array
{
    $p = explode('.', $token);
    if (count($p) !== 3) return null;
    $head = json_decode(_appleB64uDec($p[0]), true); $c = json_decode(_appleB64uDec($p[1]), true);
    if (!is_array($head) || !is_array($c) || ($head['alg'] ?? '') !== 'RS256' || empty($head['kid'])) return null;
    $jwk = null;
    foreach (intusAppleChaves() as $k) if (($k['kid'] ?? '') === $head['kid'] && ($k['kty'] ?? 'RSA') === 'RSA') { $jwk = $k; break; }
    if (!$jwk) return null;
    $pem = intusAppleJwkParaPem($jwk);
    $pub = $pem !== '' ? @openssl_pkey_get_public($pem) : false;
    if (!$pub || openssl_verify($p[0] . '.' . $p[1], _appleB64uDec($p[2]), $pub, OPENSSL_ALGO_SHA256) !== 1) return null;
    if (($c['iss'] ?? '') !== INTUS_APPLE_ISS) return null;
    if (!in_array((string)($c['aud'] ?? ''), $auds ?? INTUS_APPLE_AUDIENCIAS, true)) return null;
    if ((int)($c['exp'] ?? 0) < ($agora ?? time())) return null;
    if (trim((string)($c['sub'] ?? '')) === '') return null;
    $email = strtolower(trim((string)($c['email'] ?? '')));
    $tf = fn($v) => $v === true || $v === 'true' || $v === 1 || $v === '1';
    return ['sub' => (string)$c['sub'], 'email' => $email,
            'email_verificado' => $email !== '' && $tf($c['email_verified'] ?? false),
            'email_oculto' => $tf($c['is_private_email'] ?? false) || (bool)preg_match('/@privaterelay\.appleid\.com$/', $email)];
}

function intusAppleTabelas(PDO $pdo): void
{
    static $ok = [];
    $k = spl_object_id($pdo);
    if (!empty($ok[$k])) return;
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $pdo->exec('CREATE TABLE IF NOT EXISTS intus_apple_vinculo (
        sub VARCHAR(80) NOT NULL PRIMARY KEY,
        idatleta INT NOT NULL,
        email VARCHAR(180) NOT NULL DEFAULT \'\',
        criado_em VARCHAR(19) NOT NULL
    )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    $pdo->exec('CREATE TABLE IF NOT EXISTS intus_login_alerta (
        idalerta ' . ($sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY') . ',
        provedor VARCHAR(20) NOT NULL DEFAULT \'apple\',
        chave VARCHAR(80) NOT NULL DEFAULT \'\',
        nome VARCHAR(200) NOT NULL DEFAULT \'\',
        email VARCHAR(180) NOT NULL DEFAULT \'\',
        motivos VARCHAR(200) NOT NULL DEFAULT \'\',
        candidatos TEXT NULL,
        idlead INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT \'pendente\',
        email_em VARCHAR(19) NULL,
        criado_em VARCHAR(19) NOT NULL,
        decidido_em VARCHAR(19) NULL' . ($sqlite ? '' : ',
        INDEX idx_status (status), INDEX idx_chave (chave)') . '
    )' . ($sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    $ok[$k] = true;
}

function _appleNorm(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
    return trim((string)preg_replace('/\s+/', ' ', preg_replace('/[^a-z ]/', ' ', $s)));
}

function _appleLinhaAluno(PDO $pdo, string $tabela, array $m, int $id): ?array
{
    $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE `{$m['col_id']}` = ? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function _appleBloqueado(array $row, array $m): bool
{
    if (empty($m['col_block'])) return false;
    $bv = (string)($row[$m['col_block']] ?? '');
    return $m['col_block'] === 'stativo' ? $bv === 'N' : ($bv === 'S' || $bv === '1');
}

function intusAppleVincular(PDO $pdo, string $sub, int $idatleta, string $email): void
{
    intusAppleTabelas($pdo);
    $pdo->prepare('DELETE FROM intus_apple_vinculo WHERE sub = ?')->execute([$sub]);
    $pdo->prepare('INSERT INTO intus_apple_vinculo (sub, idatleta, email, criado_em) VALUES (?,?,?,?)')->execute([$sub, $idatleta, mb_substr($email, 0, 180), date('Y-m-d H:i:s')]);
    // a pessoa se identificou como aluno existente: o alerta dela está resolvido
    $pdo->prepare("UPDATE intus_login_alerta SET status = 'resolvido', decidido_em = ? WHERE provedor = 'apple' AND chave = ? AND status = 'pendente'")->execute([date('Y-m-d H:i:s'), $sub]);
}

/** Possíveis cadastros que a mesma pessoa já tem. Cada candidato: {tipo: aluno|lead, id, nome, email, por: nome|email}. */
function intusAppleCandidatos(PDO $pdo, string $tabela, array $m, string $nome, string $email): array
{
    $out = [];
    $nn = _appleNorm($nome);
    $p = explode(' ', $nn);
    $temNome = count($p) >= 2;   // primeiro nome sozinho não identifica ninguém
    if ($temNome) {
        $st = $pdo->prepare("SELECT `{$m['col_id']}` AS id, `{$m['col_nome']}` AS nome" . (!empty($m['col_email']) ? ", `{$m['col_email']}` AS email" : ", '' AS email") . " FROM `$tabela` WHERE LOWER(`{$m['col_nome']}`) LIKE ? LIMIT 50");
        $st->execute([mb_strtolower($p[0]) . '%']);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $rn = _appleNorm((string)$r['nome']); $rp = explode(' ', $rn);
            if ($rn === $nn || (count($rp) >= 2 && $rp[0] === $p[0] && end($rp) === end($p))) $out[] = ['tipo' => 'aluno', 'id' => (int)$r['id'], 'nome' => (string)$r['nome'], 'email' => (string)$r['email'], 'por' => 'nome'];
        }
    }
    try {
        $st = $pdo->prepare('SELECT idlead, nome, email FROM intus_lead WHERE (email IS NOT NULL AND email <> \'\' AND LOWER(email) = ?) ORDER BY idlead DESC LIMIT 5');
        $st->execute([$email]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[] = ['tipo' => 'lead', 'id' => (int)$r['idlead'], 'nome' => (string)$r['nome'], 'email' => (string)$r['email'], 'por' => 'email'];
        if ($temNome) {
            $st = $pdo->prepare('SELECT idlead, nome, email FROM intus_lead WHERE LOWER(nome) LIKE ? ORDER BY idlead DESC LIMIT 30');
            $st->execute([mb_strtolower($p[0]) . '%']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (_appleNorm((string)$r['nome']) === $nn && !array_filter($out, fn($o) => $o['tipo'] === 'lead' && $o['id'] === (int)$r['idlead'])) $out[] = ['tipo' => 'lead', 'id' => (int)$r['idlead'], 'nome' => (string)$r['nome'], 'email' => (string)$r['email'], 'por' => 'nome'];
        }
    } catch (Throwable $e) { /* sem tabela de leads ainda: sem candidatos de lead */ }
    return array_slice($out, 0, 6);
}

function intusAppleEmailAlerta(PDO $pdo, int $idalerta): bool
{
    $st = $pdo->prepare('SELECT * FROM intus_login_alerta WHERE idalerta = ?');
    $st->execute([$idalerta]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) return false;
    $mot = array_filter(explode(',', (string)$a['motivos']));
    $rot = ['email_oculto' => 'e-mail oculto pela Apple (não dá para saber se já é aluno)', 'nome_igual' => 'nome igual ao de um cadastro existente', 'email_igual' => 'e-mail igual ao de um lead existente'];
    $cand = json_decode((string)$a['candidatos'], true) ?: [];
    $corpo = "Alguém entrou com a Apple no app e pode já ter cadastro na Intus.\n\n"
        . 'Nome: ' . ($a['nome'] !== '' ? $a['nome'] : '(a Apple não informou)') . "\nE-mail: " . ($a['email'] !== '' ? $a['email'] : '(não informado)') . "\n"
        . 'Motivo do alerta: ' . implode('; ', array_map(fn($m) => $rot[$m] ?? $m, $mot)) . "\n";
    if ($cand) {
        $corpo .= "\nPossíveis cadastros da mesma pessoa:\n";
        foreach ($cand as $c) $corpo .= '- ' . ($c['tipo'] === 'aluno' ? 'Aluno' : 'Lead') . ' #' . $c['id'] . ': ' . $c['nome'] . ($c['email'] !== '' ? ' (' . $c['email'] . ')' : '') . ' (mesmo ' . ($c['por'] === 'email' ? 'e-mail' : 'nome') . ")\n";
    }
    $corpo .= "\nNenhuma conta de aluno foi criada: a pessoa ficou como lead. Se já é aluna, ela pode ligar a Apple à conta dela com e-mail e senha na própria tela de entrada, e este alerta se resolve sozinho. "
        . "Você também pode marcar como resolvido no Início do painel, em \"Possíveis cadastros duplicados\".\nhttps://intusfit.com.br/app/painel/index.html";
    $assunto = 'Intus Fit: possível cadastro duplicado (entrou com a Apple)';
    if (!empty($GLOBALS['INTUS_APPLE_EMAIL_STUB'])) $ok = (bool)call_user_func($GLOBALS['INTUS_APPLE_EMAIL_STUB'], $assunto, $corpo);
    elseif (function_exists('intus_enviar_email')) {
        $cfg = function_exists('intusSmtpConfig') ? intusSmtpConfig() : null;
        $dest = (is_array($cfg) && !empty($cfg['notificar'])) ? $cfg['notificar'] : 'contato@intusfit.com.br';
        $ok = (bool)@intus_enviar_email($dest, $assunto, $corpo, '');
    } else $ok = false;
    if ($ok) $pdo->prepare('UPDATE intus_login_alerta SET email_em = ? WHERE idalerta = ?')->execute([date('Y-m-d H:i:s'), $idalerta]);
    return $ok;
}

/**
 * Decide o que fazer com um login Apple já verificado.
 * @return array{status:string,row?:array,idlead?:int,idalerta?:int}  status: entrou | bloqueado | pendente
 */
function intusAppleEntrar(PDO $pdo, string $tabela, array $m, array $c, string $nomeInformado = ''): array
{
    intusAppleTabelas($pdo);
    $sub = $c['sub'];
    $st = $pdo->prepare('SELECT idatleta FROM intus_apple_vinculo WHERE sub = ?');
    $st->execute([$sub]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        $row = _appleLinhaAluno($pdo, $tabela, $m, $id);
        if ($row) return _appleBloqueado($row, $m) ? ['status' => 'bloqueado'] : ['status' => 'entrou', 'row' => $row];
        $pdo->prepare('DELETE FROM intus_apple_vinculo WHERE sub = ?')->execute([$sub]);   // aluno apagado: a ligação some junto
    }
    if ($c['email'] !== '' && $c['email_verificado'] && !$c['email_oculto']) {
        $st = $pdo->prepare("SELECT * FROM `$tabela` WHERE LOWER(`{$m['col_email']}`) = ? LIMIT 1");
        $st->execute([$c['email']]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (_appleBloqueado($row, $m)) return ['status' => 'bloqueado'];
            intusAppleVincular($pdo, $sub, (int)$row[$m['col_id']], $c['email']);
            return ['status' => 'entrou', 'row' => $row];
        }
    }
    // Ninguém com essa Apple: vira lead (uma vez só por pessoa) e, havendo risco de duplicidade, vira alerta
    $nome = trim($nomeInformado);
    $motivos = [];
    $pdo->exec("CREATE TABLE IF NOT EXISTS intus_lead (
        idlead " . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY') . ",
        nome VARCHAR(150) NOT NULL, whatsapp VARCHAR(30) NOT NULL DEFAULT '', email VARCHAR(150) NULL, objetivo VARCHAR(100) NULL,
        origem VARCHAR(50) NOT NULL DEFAULT 'landing-teste-gratis', status VARCHAR(20) NOT NULL DEFAULT 'novo', ip VARCHAR(45) NULL, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP)"
        . ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
    $ja = $pdo->prepare("SELECT idlead FROM intus_lead WHERE origem = 'login-apple' AND email = ? LIMIT 1");
    $chaveEmail = $c['email'] !== '' ? $c['email'] : ('apple-' . substr($sub, 0, 40));
    $ja->execute([$chaveEmail]);
    $idlead = (int)$ja->fetchColumn();
    if (!$idlead) {
        $pdo->prepare("INSERT INTO intus_lead (nome, whatsapp, email, origem, ip) VALUES (?, '', ?, 'login-apple', ?)")
            ->execute([mb_substr($nome !== '' ? $nome : 'Visitante (Apple)', 0, 150), mb_substr($chaveEmail, 0, 150), function_exists('intusClientIp') ? intusClientIp() : '']);
        $idlead = (int)$pdo->lastInsertId();
    }
    $cand = array_values(array_filter(intusAppleCandidatos($pdo, $tabela, $m, $nome, $c['email_oculto'] ? '' : $c['email']), fn($x) => !($x['tipo'] === 'lead' && $x['id'] === $idlead)));
    if ($c['email_oculto']) $motivos[] = 'email_oculto';
    foreach ($cand as $x) { $mm = $x['por'] === 'email' ? 'email_igual' : 'nome_igual'; if (!in_array($mm, $motivos, true)) $motivos[] = $mm; }
    $idalerta = 0;
    if ($motivos) {
        $q = $pdo->prepare("SELECT idalerta FROM intus_login_alerta WHERE provedor = 'apple' AND chave = ? AND status = 'pendente' LIMIT 1");
        $q->execute([$sub]);
        $idalerta = (int)$q->fetchColumn();
        if (!$idalerta) {
            $pdo->prepare('INSERT INTO intus_login_alerta (provedor, chave, nome, email, motivos, candidatos, idlead, criado_em) VALUES (?,?,?,?,?,?,?,?)')
                ->execute(['apple', $sub, mb_substr($nome, 0, 200), mb_substr($c['email'], 0, 180), implode(',', $motivos), json_encode($cand, JSON_UNESCAPED_UNICODE), $idlead, date('Y-m-d H:i:s')]);
            $idalerta = (int)$pdo->lastInsertId();
            try { intusAppleEmailAlerta($pdo, $idalerta); } catch (Throwable $e) {}
        }
    }
    return ['status' => 'pendente', 'idlead' => $idlead, 'idalerta' => $idalerta];
}

/** Alertas para o Início do painel. */
function intusAppleAlertas(PDO $pdo): array
{
    intusAppleTabelas($pdo);
    $rows = $pdo->query("SELECT * FROM intus_login_alerta WHERE status = 'pendente' ORDER BY idalerta DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    return ['pendentes' => array_map(fn($a) => ['idalerta' => (int)$a['idalerta'], 'provedor' => $a['provedor'], 'nome' => $a['nome'], 'email' => $a['email'],
        'motivos' => array_values(array_filter(explode(',', (string)$a['motivos']))), 'candidatos' => json_decode((string)$a['candidatos'], true) ?: [], 'quando' => $a['criado_em']], $rows)];
}

function intusAppleResolverAlerta(PDO $pdo, int $idalerta): array
{
    intusAppleTabelas($pdo);
    $st = $pdo->prepare("UPDATE intus_login_alerta SET status = 'resolvido', decidido_em = ? WHERE idalerta = ? AND status = 'pendente'");
    $st->execute([date('Y-m-d H:i:s'), $idalerta]);
    return ['ok' => $st->rowCount() > 0];
}
