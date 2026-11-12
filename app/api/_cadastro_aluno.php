<?php
/**
 * Regras do AUTOCADASTRO de aluno (aluno_login.php?action=criar): idade minima e aceite dos Termos.
 *
 * Por que aqui e nao dentro do endpoint: e a unica copia da regra (a tela so avisa antes, quem decide e o servidor) e da para
 * testar sem banco nem e-mail. SQL nenhum: so conta com datas.
 *
 * Decisao: o cadastro direto (app, landing do teste gratis) e so para maiores de 18 anos (INTUS_IDADE_MINIMA). Menor de idade
 * entra apenas por um professor, com autorizacao do responsavel legal (LGPD, art. 14), pelo painel (atletas.php), que nao passa
 * por esta regra. Mudar o numero muda a regra no servidor; as telas leem o mesmo valor de 18 anos escrito nelas (teste confere).
 */

if (!defined('INTUS_IDADE_MINIMA')) define('INTUS_IDADE_MINIMA', 18);
// Versao do texto dos Termos de Uso e da Politica de Privacidade que a pessoa aceitou. Subir ao mudar o texto de forma relevante.
if (!defined('INTUS_TERMOS_VERSAO')) define('INTUS_TERMOS_VERSAO', '2026-11');

/** Data de hoje em Brasilia (AAAA-MM-DD). `$hoje` so existe para os testes. */
function intusCadHoje(?string $hoje = null): string {
    if ($hoje !== null) return $hoje;
    return (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
}

/** Idade em anos completos em `$hoje`, ou null se a data nao e uma data real AAAA-MM-DD, e futura ou anterior a 1900. */
function intusIdadeDe(string $dtnascimento, ?string $hoje = null): ?int {
    $dt = trim($dtnascimento);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dt, $m)) return null;
    [$a, $mes, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    if ($a < 1900 || !checkdate($mes, $d, $a)) return null;
    $h = intusCadHoje($hoje);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $h, $mh)) return null;
    [$ha, $hm, $hd] = [(int)$mh[1], (int)$mh[2], (int)$mh[3]];
    if ($dt > $h) return null;
    $idade = $ha - $a;
    if ($hm < $mes || ($hm === $mes && $hd < $d)) $idade--;
    return $idade;
}

/**
 * Confere o corpo do cadastro. Devolve null se esta tudo certo, ou ['http' => int, 'codigo' => string, 'erro' => string].
 * Ordem: data valida, idade minima, aceite. O texto de `erro` e o que a pessoa le.
 */
function intusValidarIdadeEAceite(array $body, ?string $hoje = null): ?array {
    $dt = trim((string)($body['dtnascimento'] ?? ''));
    $idade = $dt === '' ? null : intusIdadeDe($dt, $hoje);
    if ($idade === null) {
        return ['http' => 400, 'codigo' => 'nascimento_invalido', 'erro' => 'Informe a sua data de nascimento (dia, mes e ano).'];
    }
    if ($idade < INTUS_IDADE_MINIMA) {
        return ['http' => 403, 'codigo' => 'idade_minima',
            'erro' => 'O cadastro direto e so para maiores de ' . INTUS_IDADE_MINIMA . ' anos. Para menores, o responsavel legal fala com a equipe da Intus e o cadastro e feito por um professor.'];
    }
    $aceite = $body['aceite_termos'] ?? null;
    if ($aceite !== true && $aceite !== 1 && $aceite !== '1' && $aceite !== 'true') {
        return ['http' => 400, 'codigo' => 'aceite_necessario', 'erro' => 'Para criar a conta e preciso aceitar os Termos de Uso e a Politica de Privacidade.'];
    }
    return null;
}
