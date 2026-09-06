#!/usr/bin/env bash
# Suíte de testes do Intus Fit.
#   uso:  INTUS_DIR="/caminho/para/painel" ./rodar.sh
# Sem INTUS_DIR, assume que a pasta do painel é a pasta-mãe desta.
set -uo pipefail
export INTUS_DIR="${INTUS_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
export INTUS_BASE="$(cd "$(dirname "$0")" && pwd)"
cd "$INTUS_BASE"
echo "painel: $INTUS_DIR"; echo

falhas=0
passo () { echo "── $1"; shift; "$@" || falhas=$((falhas+1)); echo; }

passo "sintaxe de todos os scripts"        node val.js
passo "nenhuma função sumiu"               node funcoes.js
passo "pontuação do ranking (unidade)"     node pontos.js
passo "regra de cobrança: 4 telas iguais"  node fonte_unica.js

# Os testes de navegador precisam de um servidor HTTP: o cache-buster "?v=" não
# funciona em file:// e as telas nem chegam a carregar os scripts.
if command -v python3 >/dev/null && node -e "require('playwright')" 2>/dev/null; then
  ( cd "$INTUS_DIR" && python3 -m http.server 8099 >/dev/null 2>&1 & echo $! > /tmp/intus-srv.pid )
  ( cd "$INTUS_DIR" && python3 -m http.server 8899 >/dev/null 2>&1 & echo $! > /tmp/intus-srv2.pid )
  sleep 2
  passo "telas de verdade no navegador"      node navegador.js
  passo "as 3 telas concordam na pontuação"  node concordancia.js
  passo "financeiro de verdade no navegador" node financeiro_tela.js
  kill "$(cat /tmp/intus-srv.pid)" "$(cat /tmp/intus-srv2.pid)" 2>/dev/null
else
  echo "!! playwright ou python3 ausentes: testes de navegador pulados."
  echo "   npm install playwright  (o Chromium já vem no container)"
  echo
fi

echo "════════════════════════════════════════"
[ "$falhas" -eq 0 ] && echo "TUDO PASSOU" || echo "$falhas ETAPA(S) COM FALHA"
exit "$falhas"
