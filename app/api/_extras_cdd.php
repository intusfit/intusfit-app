<?php
/**
 * GERADO por tools/extras/gerar-catalogo.py a partir do seed do CDD. Não edite à mão: rode o gerador de novo.
 * Módulos que viram Extras da Intus (Cintura de Atleta, Treinos em casa, Assista antes de treinar, Lives e aulas exclusivas).
 * Aula que o CDD guardava como rascunho entra como rascunho. Capas em app/painel/extras/capas (tools/extras/gerar-capas.py).
 */
function extras_catalogo_cdd(): array {
    static $c = null;
    if ($c === null) $c = json_decode(<<<'JSON_FIM'
[
 {
  "id": "cintura-de-atleta",
  "titulo": "Cintura de Atleta",
  "descricao": "Passo a passo para trabalhar a região da cintura: respiração, bracing, vacuum abdominal, hipopressivos e a técnica de R.A.P.",
  "emoji": "🔥",
  "cor": "#f59e0b",
  "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta.jpg?v=2",
  "publicado": true,
  "visibilidade": {
   "modo": "ativos",
   "ids": []
  },
  "itens": [
   {
    "id": "cdd-cintura-de-atleta-1",
    "titulo": "Aula ao vivo Hipopressivo e Pompoarismo - Especial SOS JANEIRO 21 Dias",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=V12gDJbW8M4",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-1.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-2",
    "titulo": "Aprenda a Respiração Correta",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=3UyI1Rz6GnQ",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-2.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-3",
    "titulo": "Aprenda como fazer a Ativação de Bracing",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=GE8dQae7p_A",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-3.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-4",
    "titulo": "Aprenda como fazer o Vacuum Abdominal",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=WdLhBPgzGuo",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-4.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-5",
    "titulo": "Exercícios Hipopressivos",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=RpKP05XPd5M",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-5.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-6",
    "titulo": "Exercícios Hipopressivos (aula 2)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=icPr7YfZb98",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-6.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-7",
    "titulo": "Exercícios Hipopressivos (aula 3)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=i8CU1A6ryVc",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-7.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-8",
    "titulo": "Exercícios Hipopressivos (aula 4)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=MC5L1xTOv6Q",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-8.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-9",
    "titulo": "Exercícios Hipopressivos (aula 5)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=UDnxy7XuuTM",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-9.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-10",
    "titulo": "Exercícios Hipopressivos (aula 6)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=uf6M3_1zoU4",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-10.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-11",
    "titulo": "Técnica de R.A.P.",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=XIH7huD5he4",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-11.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-cintura-de-atleta-12",
    "titulo": "Técnica de R.A.P. (aula 2)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=HN71meamNXU",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/cintura-de-atleta-aula-12.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   }
  ]
 },
 {
  "id": "treinos-em-casa",
  "titulo": "Treinos em casa",
  "descricao": "Orientações, alongamentos e treinos para fazer em casa nos dias em que você não puder ir à academia.",
  "emoji": "🏠",
  "cor": "#22c55e",
  "capa": "https://intusfit.com.br/app/painel/extras/capas/treinos-em-casa.jpg?v=2",
  "publicado": true,
  "visibilidade": {
   "modo": "ativos",
   "ids": []
  },
  "itens": [
   {
    "id": "cdd-treinos-em-casa-1",
    "titulo": "Orientações para os treinos em casa",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=WbOKQW8qgJA",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/treinos-em-casa-aula-1.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-treinos-em-casa-2",
    "titulo": "Alongamentos para fazer em casa",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=AyR1Gx-dNG8",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/treinos-em-casa-aula-2.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-treinos-em-casa-3",
    "titulo": "Treino em Casa 1.A",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=xCDYsomWcx4",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/treinos-em-casa-aula-3.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-treinos-em-casa-4",
    "titulo": "Treino em Casa 1.B",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=8VOgvbd7eKM",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/treinos-em-casa-aula-4.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-treinos-em-casa-5",
    "titulo": "Treino em Casa 1.C",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=E363NBP18Dc",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/treinos-em-casa-aula-5.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   }
  ]
 },
 {
  "id": "assista-antes-de-treinar",
  "titulo": "Assista antes de treinar",
  "descricao": "Aulas curtas para começar bem: diário da definição, segurança nos treinos, pegadas e pisadas e ativação do bracing.",
  "emoji": "🎬",
  "cor": "#0ea5e9",
  "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar.jpg?v=2",
  "publicado": true,
  "visibilidade": {
   "modo": "ativos",
   "ids": []
  },
  "itens": [
   {
    "id": "cdd-assista-antes-de-treinar-1",
    "titulo": "DDD - Diário da Definição",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=RZ7_a47NUZQ",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar-aula-1.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-assista-antes-de-treinar-2",
    "titulo": "Instruções de Segurança nos Treinos",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=kQX3HlOwtG4",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar-aula-2.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-assista-antes-de-treinar-3",
    "titulo": "Pegadas e Pisadas",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=pjCTdb-orNM",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar-aula-3.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-assista-antes-de-treinar-4",
    "titulo": "Aprenda a ativar o Bracing e definir a Cintura",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=40Z_iq17rY4",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar-aula-4.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-assista-antes-de-treinar-5",
    "titulo": "Encontre as cargas ideais para os seus treinos",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=81GB8Ii-O4k",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar-aula-5.jpg?v=2",
    "publicado": false,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-assista-antes-de-treinar-6",
    "titulo": "Aprenda a Respiração correta nos treinos",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=4pzygmIyKwg",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar-aula-6.jpg?v=2",
    "publicado": false,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-assista-antes-de-treinar-7",
    "titulo": "Aprenda o TUT (Time Under Tension)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=CJl3YOBGlKE",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/assista-antes-de-treinar-aula-7.jpg?v=2",
    "publicado": false,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   }
  ]
 },
 {
  "id": "lives-e-aulas-exclusivas",
  "titulo": "Lives e aulas exclusivas",
  "descricao": "Aulas e lives gravadas com a equipe: aeróbico, hipopressivo, lipedema, treino e dieta e mais.",
  "emoji": "🔴",
  "cor": "#ef4444",
  "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas.jpg?v=2",
  "publicado": true,
  "visibilidade": {
   "modo": "ativos",
   "ids": []
  },
  "itens": [
   {
    "id": "cdd-lives-e-aulas-exclusivas-1",
    "titulo": "Aula sobre Aeróbico: Correr ou Caminhar, o que queima mais calorias?",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=GLkoV5NJnRY",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-1.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-lives-e-aulas-exclusivas-2",
    "titulo": "Aula ao vivo Hipopressivo e Pompoarismo",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=cLSk2sE_zOc",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-2.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-lives-e-aulas-exclusivas-3",
    "titulo": "Esteroides Anabolizantes para Mulheres",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=oxLTQPh8OVg",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-3.jpg?v=2",
    "publicado": false,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-lives-e-aulas-exclusivas-4",
    "titulo": "Remédios para Emagrecer",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=ZA-pZRy3_WU",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-4.jpg?v=2",
    "publicado": false,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-lives-e-aulas-exclusivas-5",
    "titulo": "O que é a \"Molécula da Definição\" (MGF)",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=psGnlbXsFPs",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-5.jpg?v=2",
    "publicado": false,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-lives-e-aulas-exclusivas-6",
    "titulo": "Aula sobre Lipedema - O que é e como tratar",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=f_asiwEChPw",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-6.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-lives-e-aulas-exclusivas-7",
    "titulo": "3 pilares do treino para eliminar a gordura da barriga",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=P_tUiVzKo4I",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-7.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   },
   {
    "id": "cdd-lives-e-aulas-exclusivas-8",
    "titulo": "Perguntas e Respostas sobre Treino e Dieta",
    "descricao": "",
    "tipo": "video",
    "url": "https://www.youtube.com/watch?v=u_fKsW-GUKo",
    "duracao": "",
    "data": "",
    "capa": "https://intusfit.com.br/app/painel/extras/capas/lives-e-aulas-exclusivas-aula-8.jpg?v=2",
    "publicado": true,
    "visibilidade": null,
    "criado_em": "2026-10-21 12:00:00"
   }
  ]
 }
]
JSON_FIM
, true);
    return $c;
}
