<?php
/**
 * Traduz falhas da API do Claude em frases que o Luiz entende e sabe o que fazer. Sem dependência de banco nem de _wa.php:
 * a Central de atendimento (_atendimento.php) e o Intus Cut (_intuscut.php) carregam este arquivo.
 */

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
