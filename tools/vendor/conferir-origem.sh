#!/usr/bin/env bash
# Baixa de novo, do registro do npm, cada pacote que foi copiado para app/painel/vendor/ e confere que o arquivo daqui é byte a byte
# o original (hash sha384 de INTEGRIDADE.json). Precisa de internet e de node, npm, tar e openssl. Nada é alterado.
#   uso:  bash tools/vendor/conferir-origem.sh
set -euo pipefail
raiz="$(cd "$(dirname "$0")/../.." && pwd)"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
export RAIZ="$raiz" TMP="$tmp"
node - <<'JS'
const fs = require('fs'), path = require('path'), cp = require('child_process'), crypto = require('crypto');
const { RAIZ, TMP } = process.env;
const m = JSON.parse(fs.readFileSync(path.join(RAIZ, 'app/painel/vendor/INTEGRIDADE.json'), 'utf8'));
const sri = b => 'sha384-' + crypto.createHash('sha384').update(b).digest('base64');
const pacotes = {};   // 'nome@versao' -> pasta extraída
function extrai(pac) {
  if (pacotes[pac]) return pacotes[pac];
  const dir = path.join(TMP, pac.replace(/[@/]/g, '_')); fs.mkdirSync(dir);
  const nome = cp.execFileSync('npm', ['pack', pac, '--silent', '--pack-destination', dir], { encoding: 'utf8' }).trim().split('\n').pop();
  const esperado = m.pacotes_npm[pac].integridade_npm;
  const baixado = 'sha512-' + crypto.createHash('sha512').update(fs.readFileSync(path.join(dir, nome))).digest('base64');
  if (baixado !== esperado) throw new Error('pacote ' + pac + ' diferente do registrado: ' + baixado);
  cp.execFileSync('tar', ['-xzf', path.join(dir, nome), '-C', dir]);
  return (pacotes[pac] = path.join(dir, 'package'));
}
let ruim = 0;
function confere(rotulo, atual, original) {
  const ok = sri(atual) === sri(original); console.log((ok ? '  OK    ' : '  FALHA ') + rotulo); if (!ok) ruim++;
}
for (const [rel, info] of Object.entries(m.arquivos)) {
  const [, pac, dentro] = /^npm (\S+) (\S+)$/.exec(info.origem);
  confere(rel, fs.readFileSync(path.join(RAIZ, 'app/painel/vendor', rel)), fs.readFileSync(path.join(extrai(pac), dentro)));
}
for (const [nome, info] of Object.entries(m.terceiros_remotos)) {
  const [, pac, dentro] = /^npm (\S+) (\S+)$/.exec(info.origem);
  const ok = info.sha384 === sri(fs.readFileSync(path.join(extrai(pac), dentro)));
  console.log((ok ? '  OK    ' : '  FALHA ') + nome + ' (SRI usado nas telas)'); if (!ok) ruim++;
}
console.log('As fontes Inter vêm do Google Fonts (não do npm): conferir com a URL em INTEGRIDADE.json, se quiser.');
process.exit(ruim ? 1 : 0);
JS
