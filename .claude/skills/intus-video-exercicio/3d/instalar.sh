#!/usr/bin/env bash
# Prepara o Blender (como módulo Python) e o gerador de corpo humano MPFB/MakeHuman numa sessão nova.
#   bash 3d/instalar.sh <pasta de trabalho, ex.: o scratchpad>
# Depois:  <pasta>/bpyenv/bin/python 3d/animar.py 3d 3d/roteiros/X.json <pasta>/quadros-X --fps 24 --amostras 10
set -euo pipefail
P="${1:?informe a pasta de trabalho}"; mkdir -p "$P"
# bpy (Blender 5.x) só existe para Python 3.13
[ -x "$P/bpyenv/bin/python" ] || { uv venv -q -p 3.13 "$P/bpyenv"; VIRTUAL_ENV="$P/bpyenv" uv pip install -q bpy; }
# MPFB 2 (MakeHuman para Blender): corpo, alvos de forma e esqueleto com pesos (malha e alvos CC0)
[ -d "$P/mpfb2" ] || git clone -q --depth 1 https://github.com/makehumancommunity/mpfb2.git "$P/mpfb2"
V=$("$P/bpyenv/bin/python" -c "import bpy; print('%d.%d' % bpy.app.version[:2])")
D="$HOME/.config/blender/$V/extensions/user_default"; mkdir -p "$D"
[ -d "$D/mpfb" ] || cp -r "$P/mpfb2/src/mpfb" "$D/mpfb"
"$P/bpyenv/bin/python" -c "import bpy, addon_utils; print('Blender', bpy.app.version_string, '· MPFB', bool(addon_utils.enable('bl_ext.user_default.mpfb')))"
