#!/usr/bin/env python3
"""Roda um comando dentro de um namespace de rede novo, só com o loopback ligado.

Serve para provar que a suíte de testes não depende de internet: qualquer host externo falha na hora (sem DNS, sem rota) e os
servidores locais dos testes (127.0.0.1) continuam funcionando. Precisa de root (CAP_SYS_ADMIN): no CI roda com sudo.

  sudo python3 tools/ci/sem-rede.py bash testes/rodar.sh

Sai com 77 se não conseguiu criar o namespace (quem chama decide o que fazer); fora isso, devolve o código do comando.
"""
import fcntl, os, socket, struct, sys

SIOCGIFFLAGS, SIOCSIFFLAGS, IFF_UP = 0x8913, 0x8914, 0x1

def loopback_ligado():
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    ifr = struct.pack('16sH14s', b'lo', 0, b'')
    flags = struct.unpack('16sH14s', fcntl.ioctl(s, SIOCGIFFLAGS, ifr))[1]
    fcntl.ioctl(s, SIOCSIFFLAGS, struct.pack('16sH14s', b'lo', flags | IFF_UP, b''))

if len(sys.argv) < 2:
    sys.exit('uso: sem-rede.py comando [argumentos...]')
try:
    os.unshare(os.CLONE_NEWNET)
    loopback_ligado()
except (AttributeError, OSError) as e:
    print('sem-rede: não consegui isolar a rede (%s)' % e, file=sys.stderr)
    sys.exit(77)
# Variáveis de proxy apontam para um endereço que não existe neste namespace: tiradas para o erro ser o de "sem rede" mesmo.
for k in list(os.environ):
    if k.lower() in ('http_proxy', 'https_proxy', 'all_proxy', 'no_proxy', 'ftp_proxy') or k.startswith('GLOBAL_AGENT'):
        del os.environ[k]
os.execvp(sys.argv[1], sys.argv[1:])
