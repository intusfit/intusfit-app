<?php
/**
 * Traduz falhas da API do Claude em frases que o Luiz entende e sabe o que fazer. Sem dependência de banco nem de _wa.php:
 * a Central de atendimento (_atendimento.php) e o Intus Cut (_intuscut.php) carregam este arquivo.
 */

/**
 * Preço por milhão de tokens, [entrada, saída], da página de preços do Console (11/11/2026). Modelo desconhecido cobra como o Opus 5.5
 * (nunca subestima o gasto). O custo mostrado nas telas é estimativa: ignora o desconto do cache de prompt.
 */
function claude_preco_modelo(string $modelo): array {
    static $p = ['claude-opus-5-5' => [4.0, 20.0], 'claude-sonnet-5-5' => [2.0, 10.0], 'claude-haiku-5-5' => [0.10, 0.50]];
    return $p[$modelo] ?? $p['claude-opus-5-5'];
}

/**
 * Falhas da API do Claude que o Luiz precisa entender para agir: sem saldo, sem permissão, modelo indisponível, serviço cheio.
 * Devolve a frase, ou null para as demais (401, 429 e o resto seguem com a mensagem de cada tela). Sem saldo a API responde 400
 * ("credit balance is too low"), e a tela dizia só "HTTP 400".
 */
function atd_erro_claude_especial(int $st, $j, string $modelo = ''): ?string {
    $det = is_array($j) ? (string)($j['error']['message'] ?? '') : '';
    if (preg_match('/credit balance|purchase credits|plans\s*&\s*billing/i', $det)) {
        return 'A conta da API do Claude está sem saldo. A assinatura do Claude (claude.ai) não paga a API: o saldo fica em platform.claude.com, em Configurações > Faturamento.';
    }
    if ($st === 403) return 'A conta da API do Claude não deixou fazer isto' . ($det ? ' (' . mb_substr($det, 0, 140) . ')' : ' (HTTP 403)') . '. Confira a chave e a organização em platform.claude.com.';
    if ($st === 404 && preg_match('/model/i', $det)) return 'O modelo "' . $modelo . '" não está disponível para esta conta. Troque o modelo em admin/whatsapp-keys.php.';
    if ($st === 529) return 'O Claude está sobrecarregado agora. Tente de novo em alguns minutos.';
    return null;
}
