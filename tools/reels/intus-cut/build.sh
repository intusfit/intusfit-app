#!/bin/bash
# Monta intus-cut/intuscut.js (o editor que roda no site) a partir de app.js + site_extra.js + novo.js.
# A página intus-cut/index.html foi gerada de casca.html; se mudar o CSS ou o HTML de casca.html, copie para lá.
set -e
S=$(cd "$(dirname "$0")" && pwd)
R=$(cd "$S/../../../intus-cut" && pwd)
cd "$S" && python3 transformar.py
S="$S" R="$R" python3 - <<'PY'
import os
S = os.environ['S'] + '/'; R = os.environ['R'] + '/'
js = open(S + 'site.js').read().replace("LOGO.src = 'data:image/png;base64,__LOGO__';", "LOGO.src = 'logo.png';")
a = "window.addEventListener('beforeunload'"; assert js.count(a) == 1
js = js.replace(a, "$('b-rec').onclick = function(){ if (!P){ estado('Abra um projeto primeiro', 'erro'); return; } abrirEstudio(); };\n" + a)
open(R + 'intuscut.js', 'w').write(js)
os.remove(S + 'site.js')
PY
node -e "new Function(require('fs').readFileSync('$R/intuscut.js','utf8'))" && echo "intuscut.js montado"
