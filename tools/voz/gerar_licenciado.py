#!/usr/bin/env python3
"""
Gera (ou regera) os áudios das vozes dos programas de cardio com o Azure Speech, que tem licença comercial.

Por quê: os áudios que estão em app/painel/voz/ foram gerados de forma provisória com o serviço de leitura do Microsoft Edge (pacote
edge-tts), que NÃO tem contrato de uso comercial. Antes de publicar nas lojas, rode este script: ele troca os arquivos pela mesma voz
(pt-BR-FranciscaNeural) vinda da API oficial e marca o manifesto como "licenciado": true. Sem isso, o app das lojas ignora
os áudios gravados e usa a voz do aparelho (ver _progClipesCarregar em app/painel/aluno.html).

Uso (a chave NUNCA vai para o código nem para o repositório; passe só pelo ambiente):
    set AZURE_SPEECH_KEY=<chave do recurso Speech>        (PowerShell: $env:AZURE_SPEECH_KEY = "...")
    set AZURE_SPEECH_REGION=brazilsouth                    (a região do recurso)
    python tools/voz/gerar_licenciado.py

Só usa a biblioteca padrão do Python. Lê as frases de app/painel/voz/manifest.json (todas as chaves que não começam com "_"), então frase
nova de voz = acrescentar a chave no manifesto (valor qualquer) e rodar de novo. Os nomes dos arquivos são o SHA-1 da frase (10 primeiros
caracteres), os mesmos de sempre.
"""
import hashlib, json, os, sys, urllib.request, urllib.error
from xml.sax.saxutils import escape

RAIZ = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PASTA = os.path.join(RAIZ, 'app', 'painel', 'voz')
MANIFESTO = os.path.join(PASTA, 'manifest.json')
VOZ = os.environ.get('AZURE_SPEECH_VOICE', 'pt-BR-FranciscaNeural')
CHAVE = os.environ.get('AZURE_SPEECH_KEY', '').strip()
REGIAO = os.environ.get('AZURE_SPEECH_REGION', '').strip()


def nome(frase):
    return hashlib.sha1(frase.encode('utf-8')).hexdigest()[:10] + '.mp3'


def estilo(frase):
    # mesma lógica do gerador provisório: energia nas frases de esforço, calma nas de recuperação
    f = frase.lower()
    forte = any(k in f for k in ['forte', 'vamos', 'bora', 'acelera', 'corre', 'sobe', 'pedala', 'pula', 'dá tudo', 'consegue', 'faltam', 'prepare', 'abre e fecha'])
    calma = any(k in f for k in ['respira', 'recupera', 'devagar', 'calma', 'desacelera', 'conversar', 'descanso', 'anda no lugar'])
    if forte and not calma:
        return '+10%', '+6Hz'
    if calma and not forte:
        return '-2%', '+0Hz'
    return '+4%', '+3Hz'


def sintetizar(frase):
    rate, pitch = estilo(frase)
    ssml = ('<speak version="1.0" xmlns="http://www.w3.org/2001/10/synthesis" xml:lang="pt-BR">'
            '<voice name="%s"><prosody rate="%s" pitch="%s">%s</prosody></voice></speak>') % (VOZ, rate, pitch, escape(frase))
    req = urllib.request.Request(
        'https://%s.tts.speech.microsoft.com/cognitiveservices/v1' % REGIAO,
        data=ssml.encode('utf-8'),
        headers={'Ocp-Apim-Subscription-Key': CHAVE, 'Content-Type': 'application/ssml+xml',
                 'X-Microsoft-OutputFormat': 'audio-24khz-48kbitrate-mono-mp3', 'User-Agent': 'intusfit-voz'})
    with urllib.request.urlopen(req, timeout=60) as r:
        return r.read()


def main():
    if not CHAVE or not REGIAO:
        print('Defina AZURE_SPEECH_KEY e AZURE_SPEECH_REGION no ambiente (veja o topo deste arquivo).')
        return 1
    man = json.load(open(MANIFESTO, encoding='utf-8'))
    frases = [k for k in man if not k.startswith('_')]
    novo = {}
    for f in frases:
        try:
            audio = sintetizar(f)
        except urllib.error.HTTPError as e:
            print('Falhou em "%s": HTTP %s %s' % (f, e.code, e.reason)); return 2
        if len(audio) < 1000:
            print('Áudio suspeito (muito pequeno) em "%s"; nada foi alterado.' % f); return 3
        with open(os.path.join(PASTA, nome(f)), 'wb') as fh:
            fh.write(audio)
        novo[f] = nome(f)
        print('ok', nome(f), f)
    saida = {'_meta': {'fonte': 'Azure Speech (%s)' % VOZ, 'licenciado': True}}
    saida.update(novo)
    json.dump(saida, open(MANIFESTO, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('\n%d áudios gerados. Manifesto marcado como licenciado. Faça o commit da pasta app/painel/voz e republique.' % len(novo))
    return 0


if __name__ == '__main__':
    sys.exit(main())
