#!/usr/bin/env bash
# Monta o branch "cdd-site" para publicar o CDD no plano da KingHost do dominio proprio (codigodadefinicao.com.br/app).
# O branch tem so o necessario: /app (pasta cdd sem testes) e na raiz um redirecionamento para /app/.
# Uso (na raiz do repositorio):  bash tools/cdd/montar-publicacao.sh   e depois   git push -f origin cdd-site
set -euo pipefail
raiz="$(git rev-parse --show-toplevel)"; cd "$raiz"
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/app"
git archive HEAD cdd | tar -x -C "$tmp"
cp -r "$tmp/cdd/." "$tmp/app/"; rm -rf "$tmp/cdd" "$tmp/app/testes"
cat > "$tmp/.htaccess" <<'HT'
Options -Indexes
RedirectMatch 302 ^/$ /app/
<FilesMatch "^\.">
    Order allow,deny
    Deny from all
</FilesMatch>
HT
cat > "$tmp/index.html" <<'HT'
<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=app/"><title>Codigo da Definicao</title><a href="app/">Entrar</a>
HT
cd "$tmp"; git init -q -b cdd-site; git add -A
git -c user.name="CDD" -c user.email="contato@luiznunest.com.br" commit -q -m "Publicacao do CDD ($(git -C "$raiz" rev-parse --short HEAD))"
git -C "$raiz" fetch -q "$tmp" cdd-site:cdd-site --force
echo "Branch cdd-site pronto: $(git -C "$raiz" ls-tree -r --name-only cdd-site | wc -l) arquivos. Envie com: git push -f origin cdd-site"
