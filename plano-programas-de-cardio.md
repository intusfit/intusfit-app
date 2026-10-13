# Programas de cardio prontos: estudo e proposta (13/10/2026)

Pedido do Luiz: incluir programas prontos como HIIT e o método 12-3-30 (esteira inclinada) e outros desafiadores e motivantes.
Este arquivo é só estudo. Nada disso foi construído.

## 1. O que já existe e serve de base

- **Cardio ao vivo** (`aluno.html`, `_renderCardioLive`): cronômetro, GPS opcional, pausa, tela sempre ligada (wake lock), tela de esforço no fim, registro em `intus_sessao` (tipo `cardio`).
- **Pontos**: pontos-base = atividade x esforço x minutos (`API.cardioPontosBase`, `api.js`, o tempo manda). Depois entram fator 0,55, teto de 7 por sessão e limite de 3 cardios pontuados por dia.
- **Sem GPS** já existe o modo "num aparelho" (esteira, bike): só o cronômetro conta. É exatamente onde os programas vivem.
- O professor já recebe o cardio na lista e pode marcar "não contar".

## 2. Catálogo inicial sugerido (todos com tempo certo, sem equipamento além do que a pessoa já usa)

| Programa | Estrutura | Onde | Esforço padrão | Para quem |
|---|---|---|---|---|
| **12-3-30** | 5 min aquecimento leve, depois 30 min a 12% de inclinação e 3 mph (4,8 km/h; a versão "5 km/h" é uma variação), sem segurar no corrimão | esteira | 6 | iniciante a intermediário |
| **HIIT 1:1** | 5 min aquecimento, 10 x (30 s forte + 30 s leve), 5 min volta à calma | qualquer | 8 nos tiros | intermediário |
| **Tabata** | 8 x (20 s máximo + 10 s descanso) = 4 min, repetido 1 a 3 vezes com 2 min entre | bike, corda, corrida | 9 nos tiros | avançado |
| **Intervalado 4x4** (estilo norueguês) | 10 min aquecimento, 4 x (4 min forte + 3 min leve), 5 min volta à calma | corrida, bike | 8 | avançado |
| **Fartlek guiado** | 30 min com "acelerações livres" sinalizadas por avisos aleatórios (1 a 2 min) | rua, esteira | 6 a 8 | iniciante a intermediário |
| **Pirâmide** | 1, 2, 3, 2, 1 min fortes, com descanso igual ao tiro | qualquer | 7 a 8 | intermediário |
| **Zona 2** | 40 a 60 min contínuos, ritmo de conversar | qualquer | 4 | todos, base aeróbica |
| **Do sofá aos 5 km** | 8 semanas, 3 treinos por semana (caminha e corre alternando, aumentando o trote) | rua, esteira | 5 a 6 | iniciante |
| **Escada 10 minutos** | 5 x (1 min subindo rápido + 1 min descendo) | escada | 7 | tempo curto |

Cada programa tem: nome, resumo de uma frase, duração total, esforço padrão (RPE), e uma lista de etapas.

## 3. Como modelar (sem tabela nova no MVP)

Um programa é um JSON pequeno, guardado numa chave de configuração (`cardio_programas`, no padrão de `cardio_regras`) e editável no painel depois:

```json
{ "id": "12-3-30", "titulo": "12-3-30", "tipo": "Caminhada", "aparelho": "esteira", "rpe": 6,
  "etapas": [ {"nome": "Aquecimento", "seg": 300, "dica": "Esteira a 0%, 3 mph"},
              {"nome": "Inclinação 12%", "seg": 1800, "dica": "12% e 3 mph, sem apoiar", "repete": 1} ],
  "aviso": "Pare se sentir tontura ou dor." }
```

- Repetições (HIIT) viram `repete` num bloco ("30 s forte + 30 s leve" x 10), para não escrever 20 etapas.
- O app já tem tudo para tocar isso: cronômetro, pausa e wake lock. A parte nova é a tela de etapa atual.

## 4. A tela de execução (o que dá motivação)

- Etapa atual grande, contagem regressiva da etapa, barra do programa inteiro, próxima etapa em texto pequeno.
- **Aviso na troca de etapa**: vibração e bip curto (Web Audio, sem arquivo) e um texto ("Agora: 30 s forte"). Voz é opcional e fica para depois.
- Pausa e "pular etapa" (a pessoa precisa de saída segura). Funciona sem internet (o programa fica no aparelho).
- No fim, a tela de esforço de sempre, já com o RPE padrão do programa (a pessoa pode ajustar um pouco).
- Limitação conhecida: sem o app nativo o celular pode apagar a tela e parar os avisos. O wake lock cobre a maior parte, como já acontece no treino.

## 5. Pontos e motivação sem inflar o ranking

Ponto que depende do que a pessoa diz é o que o Luiz acabou de ver ser abusado. Proposta:

1. **Pontos pelo tempo, como hoje.** O programa registra a duração real e o RPE padrão. Não dá pontos extras por ser programa, para o ranking não virar corrida por "programa mais bonito".
2. **A recompensa fica nos distintivos**, que não somam pontos: "12-3-30 concluído", "10 HIITs", "Do sofá aos 5 km", "30 dias de cardio". Entram no mesmo sistema de conquistas (`CONQUISTAS_DEF`) com uma métrica nova `programas`.
3. **Desafios curtos** ("4 semanas de 12-3-30, 3x por semana") reaproveitando a tela de Desafios da equipe, com progresso automático porque o programa gera sessão com o nome dele.
4. Compartilhar no Feed o programa concluído, com a figurinha (já existe para treino e cardio).

## 6. Segurança e texto

- Aviso curto antes de cada programa e na primeira vez (aquecer, parar se sentir tontura ou dor). Nada de promessa de emagrecer ou de resultado em prazo.
- Programas avançados (Tabata, 4x4) mostram "para quem já treina há algum tempo". Quem marcou lesão ou condição de saúde na anamnese vê um aviso para falar com o professor antes.
- Texto de todos os programas segue a regra de escrita do Luiz: sem travessão e sem o padrão "não é X, é Y".

## 7. Faseamento e esforço

| Fase | O que entrega | Esforço |
|---|---|---|
| **MVP** | 6 programas fixos no código (12-3-30, HIIT 1:1, Tabata, 4x4, Zona 2, Pirâmide), tela de execução com avisos, registro como cardio com nome do programa, 1 distintivo por programa | 1 sessão de trabalho, só no `aluno.html`; sem mudança de banco |
| **2** | Programas guardados em `cardio_programas` e editáveis no painel; professor indica um programa para um aluno | 1 sessão (painel + chave de config) |
| **3** | Fartlek com avisos aleatórios, "Do sofá aos 5 km" em 8 semanas com calendário, desafios com progresso automático | 1 a 2 sessões |

Para a sessão ser registrada com o nome do programa sem coluna nova: guardar no `comentario` (ou em `cardio_tipo` como "Caminhada · 12-3-30"); a decisão deve ser tomada junto com a tela de esforço.

## 8. Decisões que preciso do Luiz

1. **12-3-30**: manter 3 mph (4,8 km/h, o método original) ou usar 5 km/h como no pedido? Sugestão: 3 mph como padrão, 5 km/h como "versão rápida" quando a pessoa já faz sem apoiar.
2. Os programas dão **só distintivos** (recomendado) ou também algum ponto?
3. **Quem pode usar**: todos os alunos com matrícula ativa, ou alguns programas só para quem o professor liberar?
4. Começar pelo **MVP com os 6 programas fixos**?
