<?php
/**
 * Central de atendimento: conteúdo de fábrica (funis de mensagens e base de conceitos).
 *
 * Só dados. Nenhuma regra mora aqui. O que Luiz editar na tela fica em intus_config
 * (chaves atd_*) e passa a valer no lugar destes padrões; "Restaurar padrão" volta a eles.
 *
 * Regras de escrita (valem para toda cópia da Intus):
 *  - sem travessão e sem o padrão "não é X, é Y";
 *  - sem promessa de resultado, prazo ou número; sem urgência falsa; sem depoimento inventado;
 *  - o teste grátis é só de treino, não inclui dieta;
 *  - preço da Premium só uma pessoa fala.
 *
 * Variáveis das mensagens: {{1}} nome, {{2}} link, {{3}} nome de quem indicou.
 */

function atd_funis_padrao(): array {
    return [
        [
            'id' => 'teste-gratis',
            'nome' => 'Teste grátis de 3 dias',
            'status' => 'aprovado',
            'gatilho' => 'Conta criada na página do teste grátis, com consentimento de WhatsApp',
            'objetivo' => 'Fazer a pessoa treinar de verdade nos 3 dias e chegar ao fim do teste querendo continuar com acompanhamento.',
            'variaveis' => ['1' => 'nome', '2' => 'link do app'],
            'padrao' => true,
            'mensagens' => [
                [
                    'id' => 't1', 'nome' => 'Boas-vindas', 'tipo' => 'agendada', 'dia' => 0, 'hora' => null,
                    'condicao' => 'todos', 'categoria' => 'utilidade', 'template' => 'intus_t1_boas_vindas', 'aprovada' => true,
                    'texto' => 'Oi {{1}}, tudo bem? Aqui é a Cora, assistente virtual da Intus. Seu teste grátis de treino já está liberado por 3 dias. Para entrar no app, use este link: {{2}}. Se tiver qualquer dúvida, é só responder esta mensagem.',
                    'conceito' => 'Clareza e primeiro passo',
                    'por_que' => 'Quem acabou de se cadastrar está no pico de interesse. A mensagem entrega o acesso, diz quem está falando e abre a porta para dúvidas, sem pedir nada.',
                ],
                [
                    'id' => 't2', 'nome' => 'Ainda não treinou', 'tipo' => 'agendada', 'dia' => 1, 'hora' => 10,
                    'condicao' => 'sem_treino', 'categoria' => 'marketing', 'template' => 'intus_t2_primeiro_treino', 'aprovada' => true,
                    'texto' => 'Oi {{1}}, deixamos seu treino de teste pronto no app. Já conseguiu fazer o primeiro? Se travou em alguma parte, me conta aqui que a gente resolve.',
                    'conceito' => 'Reduzir atrito',
                    'por_que' => 'Quem não treinou normalmente travou em algo pequeno (login, não saber por onde começar). A pergunta é fácil de responder e oferece ajuda concreta.',
                ],
                [
                    'id' => 't3', 'nome' => 'Parabéns pelo treino', 'tipo' => 'agendada', 'dia' => 1, 'hora' => 20,
                    'condicao' => 'com_treino', 'categoria' => 'marketing', 'template' => 'intus_t3_pos_treino', 'aprovada' => true,
                    'texto' => '{{1}}, parabéns pelo treino de hoje. Como foi a experiência? Se algum exercício ficou confuso, é só me dizer.',
                    'conceito' => 'Reforço positivo',
                    'por_que' => 'Reconhecer o esforço logo depois do treino fortalece o hábito e abre uma conversa sobre a experiência real do app.',
                ],
                [
                    'id' => 't4', 'nome' => 'Dúvida de execução', 'tipo' => 'agendada', 'dia' => 2, 'hora' => 9,
                    'condicao' => 'todos', 'categoria' => 'marketing', 'template' => 'intus_t4_duvidas', 'aprovada' => true,
                    'texto' => 'Bom dia, {{1}}! Alguma dorzinha dos treinos? Se tiver dúvida em algum exercício, manda um vídeo aqui que um professor da Intus te ajuda com a execução.',
                    'conceito' => 'Cuidado e autoridade de verdade',
                    'por_que' => 'Mostra que existe um profissional por trás do app. O vídeo que chegar vai para um professor, nunca para o robô. É o momento em que o acompanhamento deixa de ser promessa.',
                ],
                [
                    'id' => 't5', 'nome' => 'Último dia do teste', 'tipo' => 'agendada', 'dia' => 3, 'hora' => 9,
                    'condicao' => 'todos', 'categoria' => 'marketing', 'template' => 'intus_t5_ultimo_dia', 'aprovada' => true,
                    'texto' => '{{1}}, hoje é o último dia do seu teste grátis. Se gostou e quer continuar com acompanhamento de um profissional, responde aqui que eu te explico como funciona a Consultoria Premium da Intus.',
                    'conceito' => 'Escassez real (o teste acaba mesmo)',
                    'por_que' => 'O prazo é verdadeiro, então a urgência é honesta. O convite é para uma conversa, não para uma compra.',
                ],
                [
                    'id' => 't6', 'nome' => 'Convite depois do teste', 'tipo' => 'agendada', 'dia' => 4, 'hora' => 18,
                    'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_t6_convite', 'aprovada' => true,
                    'texto' => '{{1}}, seu teste terminou, mas o convite continua de pé. Na Consultoria Premium um profissional monta e acompanha o seu treino de perto. Quer que eu te conte como funciona?',
                    'conceito' => 'Convite sem pressão',
                    'por_que' => 'Para quem sumiu, a mensagem retoma o benefício central (profissional que acompanha) e pergunta se quer saber mais, com resposta fácil de sim ou não.',
                ],
                [
                    'id' => 't7', 'nome' => 'Última tentativa', 'tipo' => 'agendada', 'dia' => 8, 'hora' => 18,
                    'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_t7_ultima', 'aprovada' => true,
                    'texto' => '{{1}}, passo por aqui uma última vez. Se quiser retomar seu treino com a Intus, é só responder esta mensagem. Se preferir não receber mais mensagens, responda SAIR.',
                    'conceito' => 'Saída digna e respeito à autonomia',
                    'por_que' => 'Avisar que é a última e oferecer o SAIR protege a reputação do número e costuma gerar resposta de quem tinha interesse e deixou passar.',
                ],
            ],
        ],
        [
            'id' => 'chamou-whatsapp',
            'nome' => 'Quem chama no WhatsApp (QR code, botão do site)',
            'status' => 'rascunho',
            'gatilho' => 'A pessoa manda a primeira mensagem. A janela de 24 horas abre, então vale texto livre, sem template',
            'objetivo' => 'Entender objetivo e dificuldade em poucas perguntas e levar ao teste grátis ou, se pedir, a uma pessoa da equipe.',
            'variaveis' => ['1' => 'nome', '2' => 'link do teste grátis'],
            'padrao' => true,
            'mensagens' => [
                [
                    'id' => 'c1', 'nome' => 'Resposta imediata', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Oi! Aqui é a Cora, assistente virtual da Intus. Que bom ter você por aqui. Para eu te ajudar do jeito certo: qual é o seu objetivo hoje? Ganhar massa, perder gordura ou voltar a treinar com constância?',
                    'conceito' => 'Acolhimento e pergunta aberta curta',
                    'por_que' => 'Responder rápido e se apresentar como assistente virtual cria confiança. Uma pergunta só, com opções, é mais fácil de responder do que "como posso ajudar?".',
                ],
                [
                    'id' => 'c2', 'nome' => 'Rotina atual', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Entendi. E hoje, como está a sua rotina de treino? Você já treina em algum lugar, em casa, ou está começando agora?',
                    'conceito' => 'Qualificação',
                    'por_que' => 'Separa quem está começando de quem já treina, e muda o tom das próximas mensagens.',
                ],
                [
                    'id' => 'c3', 'nome' => 'Maior dificuldade', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'E o que mais te atrapalha para manter a constância? Falta de plano, de tempo, de acompanhamento ou outra coisa?',
                    'conceito' => 'Dor em palavras do próprio lead',
                    'por_que' => 'A resposta é a dor que vai guiar a oferta. Guarde as palavras exatas da pessoa e use-as depois.',
                ],
                [
                    'id' => 'c4', 'nome' => 'Convite para o teste grátis', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Faz sentido. Posso liberar um teste grátis de 3 dias do treino da Intus, direto no app e sem cartão. Você treina, vê como funciona o acompanhamento e decide depois. Se quiser, é só preencher aqui: {{2}}',
                    'conceito' => 'Reciprocidade e baixo risco',
                    'por_que' => 'Dar a experiência antes de pedir qualquer compromisso. O link leva ao cadastro oficial, que cria a conta de verdade.',
                ],
                [
                    'id' => 'c5', 'nome' => 'Quer saber da Premium', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Boa pergunta. Para te explicar a Consultoria Premium direitinho, vou chamar uma pessoa da equipe para continuar essa conversa com você por aqui. Pode ser?',
                    'conceito' => 'Passagem para humano',
                    'por_que' => 'Preço e fechamento são conversa de pessoa. O robô avisa que vai passar, sem inventar valor e sem prometer horário.',
                ],
                [
                    'id' => 'c6', 'nome' => 'Silêncio de 3 horas', 'tipo' => 'silencio', 'minutos' => 180, 'condicao' => 'sem_resposta', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Ficou alguma dúvida? Se preferir, me responde só com o número: 1 para testar grátis, 2 para saber da consultoria, 3 para tirar uma dúvida.',
                    'conceito' => 'Reduzir esforço de resposta',
                    'por_que' => 'Responder um número custa quase nada. Quem parou por preguiça de escrever volta.',
                ],
                [
                    'id' => 'c7', 'nome' => 'Reabertura depois de 24 horas', 'tipo' => 'silencio', 'minutos' => 1500, 'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_c7_retomada', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, a gente conversou ontem sobre o seu treino. Quer retomar de onde parou? Se não for o momento, é só responder SAIR.',
                    'conceito' => 'Compromisso e coerência',
                    'por_que' => 'Depois de 24 horas só vale template aprovado. Lembra a conversa anterior e dá a saída clara.',
                ],
            ],
        ],
        [
            'id' => 'indicacao',
            'nome' => 'Amigo indicado por aluno',
            'status' => 'rascunho',
            'gatilho' => 'Cadastro no teste grátis pelo link de indicação de um aluno (parâmetro ref)',
            'objetivo' => 'Usar a confiança de quem indicou, sem expor dados do aluno, e levar o indicado a treinar nos 3 dias.',
            'variaveis' => ['1' => 'nome', '2' => 'link do app', '3' => 'nome de quem indicou'],
            'padrao' => true,
            'mensagens' => [
                [
                    'id' => 'i1', 'nome' => 'Boas-vindas com indicação', 'tipo' => 'agendada', 'dia' => 0, 'hora' => null, 'condicao' => 'todos', 'categoria' => 'utilidade', 'template' => 'intus_i1_indicacao', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, tudo bem? Aqui é a Cora, assistente virtual da Intus. {{3}} indicou você e seu teste grátis de treino já está liberado por 3 dias. Para entrar no app, use este link: {{2}}. Qualquer dúvida, é só responder esta mensagem.',
                    'conceito' => 'Prova social verdadeira',
                    'por_que' => 'A indicação de alguém conhecido pesa mais do que qualquer argumento nosso. Só use o nome quando o aluno que indicou permitir.',
                ],
                [
                    'id' => 'i2', 'nome' => 'Primeiro treino', 'tipo' => 'agendada', 'dia' => 1, 'hora' => 10, 'condicao' => 'sem_treino', 'categoria' => 'marketing', 'template' => 'intus_i2_primeiro_treino', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, seu treino de teste está pronto no app. Que tal fazer o primeiro hoje? Se precisar de ajuda para começar, me chama aqui.',
                    'conceito' => 'Ação simples e específica',
                    'por_que' => 'Um convite concreto para hoje vale mais do que perguntar como está.',
                ],
                [
                    'id' => 'i3', 'nome' => 'Fim do teste', 'tipo' => 'agendada', 'dia' => 3, 'hora' => 9, 'condicao' => 'todos', 'categoria' => 'marketing', 'template' => 'intus_i3_fim_teste', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, hoje é o último dia do seu teste grátis. Se quiser continuar com acompanhamento de um profissional, responde aqui que eu te explico como funciona.',
                    'conceito' => 'Escassez real',
                    'por_que' => 'Mesmo prazo honesto do funil principal, em tom mais curto.',
                ],
            ],
        ],
        [
            'id' => 'reativacao',
            'nome' => 'Reativação de quem fez o teste e não fechou',
            'status' => 'rascunho',
            'gatilho' => 'Teste vencido há 14 dias, pessoa sem resposta recente e sem pedido de saída. O dia conta a partir do fim do teste',
            'objetivo' => 'Reabrir a conversa uma ou duas vezes, com respeito, para quem deixou passar.',
            'variaveis' => ['1' => 'nome'],
            'padrao' => true,
            'mensagens' => [
                [
                    'id' => 'r1', 'nome' => 'Retomada', 'tipo' => 'agendada', 'dia' => 14, 'hora' => 10, 'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_r1_retomada', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, faz um tempo que você testou o treino da Intus. Se ainda pensa em voltar a treinar com um profissional ao seu lado, me conta o que te segurou que eu vejo como ajudar.',
                    'conceito' => 'Descobrir a objeção real',
                    'por_que' => 'Perguntar o que segurou transforma silêncio em informação. Toda resposta vira dado para melhorar o funil.',
                ],
                [
                    'id' => 'r2', 'nome' => 'Despedida', 'tipo' => 'agendada', 'dia' => 30, 'hora' => 18, 'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_r2_despedida', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, vou deixar de te escrever por aqui. A porta continua aberta: se quiser retomar, é só mandar um oi. Se preferir não receber mais mensagens, responda SAIR.',
                    'conceito' => 'Saída digna',
                    'por_que' => 'Encerrar bem preserva a marca e deixa o caminho de volta aberto.',
                ],
            ],
        ],
        [
            'id' => 'recuperacao-teste',
            'nome' => 'Recuperação de quem fez o teste e não recebeu nada',
            'status' => 'rascunho',
            'gatilho' => 'Teste grátis terminado há 5 dias ou mais, pessoa que nunca recebeu mensagem da Intus, sem pedido de saída. O dia conta a partir do fim do teste',
            'objetivo' => 'Retomar com honestidade o contato com quem se cadastrou, não foi chamado na época e deixou o teste passar. Reconhece o silêncio da Intus, pergunta e oferece ajuda, sem pressão.',
            'variaveis' => ['1' => 'nome'],
            'padrao' => true,
            'mensagens' => [
                [
                    'id' => 'rec1', 'nome' => 'Retomada (reconhece o silêncio)', 'tipo' => 'agendada', 'dia' => 5, 'hora' => 10, 'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_rec1_retomada', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, aqui é da Intus. Você se cadastrou no teste grátis do nosso treino e eu acabei não te chamar por aqui na época, desculpa por isso. Ainda faz sentido para você começar a treinar com acompanhamento de um profissional? Se sim, me responde que eu vejo como te ajudar a dar o primeiro passo.',
                    'conceito' => 'Reciprocidade e honestidade',
                    'por_que' => 'Assumir que a falha foi nossa tira a culpa da pessoa e abre a conversa. A pergunta é de sim ou não, então responder custa pouco. Não promete novo teste nem condição.',
                ],
                [
                    'id' => 'rec2', 'nome' => 'Segunda tentativa (descobrir o que pesa)', 'tipo' => 'agendada', 'dia' => 8, 'hora' => 18, 'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_rec2_segunda', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, passei por aqui de novo para saber se você ainda quer treinar com a Intus. Se hoje o que pesa é falta de tempo, de ideia do que fazer na academia ou de alguém para te orientar, me conta qual é o seu caso que eu te respondo com calma.',
                    'conceito' => 'Descobrir a objeção real',
                    'por_que' => 'Oferece três respostas prováveis sem empurrar nenhuma. Toda resposta vira dado para melhorar o funil.',
                ],
                [
                    'id' => 'rec3', 'nome' => 'Despedida', 'tipo' => 'agendada', 'dia' => 13, 'hora' => 10, 'condicao' => 'sem_resposta', 'categoria' => 'marketing', 'template' => 'intus_rec3_despedida', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, vou encerrar por aqui para não te incomodar. Se mais para frente você quiser retomar o treino com a Intus, é só me mandar um oi. Se preferir não receber mais mensagens, responda SAIR.',
                    'conceito' => 'Saída digna',
                    'por_que' => 'Encerrar com respeito protege a marca e mantém o caminho de volta aberto. A opção SAIR protege o número.',
                ],
                [
                    'id' => 'rec-treinou', 'nome' => 'Alternativa: quem chegou a treinar', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, aqui é da Intus. Você testou o treino com a gente e eu queria saber como foi para você. Ficou alguma dúvida sobre o app ou sobre ter um profissional acompanhando o seu treino? Se quiser continuar, me responde aqui que eu te explico como funciona.',
                    'conceito' => 'Reconhecer o que a pessoa já fez',
                    'por_que' => 'Quem treinou já deu o passo difícil. Começar pelo treino que ela fez mostra atenção e leva à conversa sobre continuar. Use no lugar da rec1 quando a pessoa tem treino registrado.',
                ],
                [
                    'id' => 'rec-parado', 'nome' => 'Alternativa: quem não chegou a treinar', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, aqui é da Intus. O seu teste grátis passou sem você ter feito o primeiro treino, e isso acontece com bastante gente. Quer que eu te ajude a abrir o app e escolher por onde começar? Me responde aqui que eu te acompanho nisso.',
                    'conceito' => 'Reduzir a barreira de começar',
                    'por_que' => 'Quem não treinou geralmente travou no começo. A oferta é de ajuda concreta e pequena, sem cobrar o treino perdido. Use no lugar da rec1 quando a pessoa não tem treino registrado.',
                ],
                [
                    'id' => 'rec-premium', 'nome' => 'Alternativa: explicar a Consultoria Premium', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, aqui é da Intus. Quem passa pelo teste costuma ficar com dúvida de como é o acompanhamento no dia a dia. Posso te explicar como funciona a Consultoria Premium, sem compromisso? É só me responder aqui.',
                    'conceito' => 'Convite para conversa',
                    'por_que' => 'Para quem já conhece o app e só precisa entender o acompanhamento. O pedido é por uma conversa, não por uma compra; preço e fechamento ficam para uma pessoa da equipe.',
                ],
            ],
        ],
        [
            'id' => 'fechamento',
            'nome' => 'Fechamento: planos, valores, pagamento e acesso',
            'status' => 'rascunho',
            'gatilho' => 'A pessoa quer contratar, perguntou preço ou respondeu com interesse. Vale para qualquer funil: as mensagens aparecem na lista de escolha de todo lead',
            'objetivo' => 'Levar da dúvida ao pagamento com honestidade: apresentar os planos e valores reais (os de Gestão de Planos), enviar o link do plano escolhido, confirmar e agradecer, entregar o acesso ou chamar uma pessoa da equipe.',
            'variaveis' => ['1' => 'nome', '2' => 'link do app', '4' => 'planos e valores', '5' => 'link de pagamento do plano', '6' => 'nome do plano', '7' => 'valor do plano', '8' => 'prova social'],
            'padrao' => true,
            'mensagens' => [
                [
                    'id' => 'f1', 'nome' => 'Abrir o fechamento (pedir licença)', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Oi {{1}}, aqui é a Cora, assistente virtual da Intus. Pelo que você me contou, o que você quer é treinar com alguém te orientando de perto. Posso te mostrar os planos e quanto cada um custa?',
                    'conceito' => 'Pedido de permissão',
                    'por_que' => 'Pedir licença antes de falar de preço mostra respeito e faz o preço aparecer como resposta a um pedido da própria pessoa. A pergunta é de sim ou não, então responder custa pouco.',
                ],
                [
                    'id' => 'f2', 'nome' => 'Planos e valores', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Claro, {{1}}! Estes são os planos da Intus:

{{4}}

Em todos eles um profissional da Intus monta e acompanha o seu plano de perto. Qual deles parece mais com o que você precisa agora?',
                    'conceito' => 'Transparência e escolha',
                    'por_que' => 'Mostrar todos os planos e valores de uma vez, sem esconder nada, gera confiança. Terminar com uma pergunta de escolha (qual deles) leva a pessoa a decidir entre opções, e não entre comprar ou não comprar. Os valores vêm de Gestão de Planos.',
                ],
                [
                    'id' => 'f3', 'nome' => 'Recomendar um plano', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false, 'por_plano' => true,
                    'texto' => 'Pelo que você me contou, eu começaria pelo plano {{6}}, que fica em {{7}}. Ele inclui o acompanhamento de um profissional da Intus. Quer que eu te mande o link para garantir o seu?',
                    'conceito' => 'Recomendação assumida',
                    'por_que' => 'Quem está em dúvida costuma agradecer uma sugestão clara. A recomendação vem com o motivo (o que a pessoa contou) e termina em um próximo passo pequeno: receber o link. Há uma opção para cada plano.',
                ],
                [
                    'id' => 'f4', 'nome' => 'Quando o valor pesa', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Entendo, {{1}}, olhar para o valor é normal. Me conta: o que faria esse investimento valer a pena para você? Se preferir, eu chamo uma pessoa da equipe para ver com você as formas de pagamento.',
                    'conceito' => 'Escutar a objeção real',
                    'por_que' => 'Perguntar o que faria valer a pena transforma "está caro" em informação: pode ser prazo, forma de pagamento ou dúvida sobre o método. Oferecer uma pessoa da equipe evita improvisar condição que a Intus não aprovou.',
                ],
                [
                    'id' => 'f5', 'nome' => 'Enviar o link de pagamento', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false, 'por_plano' => true,
                    'texto' => 'Combinado, {{1}}! Aqui está o link do plano {{6}} ({{7}}): {{5}}

Assim que o pagamento for confirmado eu libero o seu acesso. Se aparecer qualquer dúvida na hora de pagar, é só me chamar.',
                    'conceito' => 'Próximo passo claro',
                    'por_que' => 'A pessoa recebe o plano e o valor que combinou junto com o link, sem ter que procurar nada. Avisar o que acontece depois do pagamento (acesso liberado) tira a insegurança de pagar. O link vem de Gestão de Planos, plano a plano.',
                ],
                [
                    'id' => 'f6', 'nome' => 'Lembrar o link (quem não pagou)', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false, 'por_plano' => true,
                    'texto' => 'Oi {{1}}, o link do plano {{6}} continua disponível: {{5}}

Se ainda ficou alguma dúvida, me conta que eu te ajudo ou chamo uma pessoa da equipe.',
                    'conceito' => 'Lembrete sem pressão',
                    'por_que' => 'Um lembrete curto, com o link à mão e uma saída para a dúvida, recupera quem só se distraiu. Não inventa urgência: o plano continua disponível mesmo.',
                ],
                [
                    'id' => 'f7', 'nome' => 'Prova social (história real)', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => '{{1}}, uma história real para você ver como é o acompanhamento: {{8}}

Cada pessoa tem o seu ritmo, mas conhecer um caso parecido costuma ajudar a decidir. Quer ver os planos de novo?',
                    'conceito' => 'Prova social verdadeira',
                    'por_que' => 'A história vem do Banco de provas sociais (Quadro de Resultados), só de quem autorizou o uso. Dizer que cada pessoa tem o seu ritmo mantém a honestidade e não promete resultado.',
                ],
                [
                    'id' => 'f8', 'nome' => 'Preciso pensar (isolar a dúvida)', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Claro, {{1}}, pensa com calma. Para eu te ajudar a decidir, qual é a sua maior dúvida hoje: o valor, o formato do acompanhamento ou o momento?',
                    'conceito' => 'Isolar a objeção',
                    'por_que' => 'Oferecer três respostas prováveis facilita responder e mostra qual é o verdadeiro obstáculo, sem pressionar.',
                ],
                [
                    'id' => 'f9', 'nome' => 'Pagamento confirmado: agradecer', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => '{{1}}, recebi a confirmação do seu pagamento. Obrigada pela confiança! Já vou te mandar o seu acesso.',
                    'conceito' => 'Gratidão e reforço da decisão',
                    'por_que' => 'Agradecer logo após o pagamento confirma que a decisão foi boa e abre a conversa do acesso.',
                ],
                [
                    'id' => 'f10', 'nome' => 'Enviar o acesso', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Seu acesso está liberado, {{1}}! Entre pelo app com o e-mail e a senha que você cadastrou: {{2}}

O primeiro passo é preencher a anamnese dentro do app, porque é com ela que o profissional monta o seu plano. Se tiver qualquer dificuldade para entrar, me chama aqui que uma pessoa da equipe te ajuda.',
                    'conceito' => 'Primeiro passo pequeno',
                    'por_que' => 'Quem acabou de pagar precisa saber o que fazer agora. Um único primeiro passo (a anamnese) evita que o acesso fique parado.',
                ],
                [
                    'id' => 'f11', 'nome' => 'Chamar uma pessoa da equipe', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Vou chamar uma pessoa da equipe para continuar com você por aqui, {{1}}. Ela vai ver tudo o que a gente conversou. Pode ser?',
                    'conceito' => 'Passagem para humano',
                    'por_que' => 'Para desconto, parcelamento especial, dúvida de saúde ou qualquer coisa que o assistente não deva decidir, a passagem é honesta e a pessoa não precisa repetir a história.',
                ],
                [
                    'id' => 'f12', 'nome' => 'Despedida sem pressão', 'tipo' => 'resposta', 'condicao' => 'todos', 'categoria' => 'texto livre', 'template' => '', 'aprovada' => false,
                    'texto' => 'Tudo bem, {{1}}. Fico por aqui sem insistir. Se mudar de ideia, os planos e o link continuam disponíveis: é só me chamar. Se preferir não receber mais mensagens, responda SAIR.',
                    'conceito' => 'Saída digna',
                    'por_que' => 'Encerrar com respeito protege a marca, mantém o caminho de volta aberto e a opção SAIR protege o número.',
                ],
            ],
        ],
    ];
}

function atd_conceitos_padrao(): array {
    return [
        [
            'id' => 'narrativa', 'titulo' => 'Narrativa',
            'resumo' => 'Toda mensagem conta um pedaço de uma história com três papéis: a pessoa é a protagonista, a Intus é o guia e o app e os profissionais são o plano. Falar de nós fica em segundo plano.',
            'como_aplicar' => "1. Comece pela situação da pessoa (o treino dela, o cadastro dela, o dia dela).\n2. Mostre o próximo passo claro e pequeno.\n3. Deixe visível o que acontece se ela der o passo (ver o treino, ter ajuda, ser acompanhada), sem prometer resultado.\n4. Em sequências, cada mensagem avança a história: acesso, primeiro treino, reforço, ajuda, decisão, convite, despedida.",
            'exemplo' => 'T1 entrega o acesso, T3 reconhece o treino feito, T5 apresenta a decisão, T7 encerra com respeito. Em nenhuma delas a Intus é a protagonista.',
            'armadilha' => 'Falar de si ("somos a melhor consultoria") em vez de falar da pessoa. Inventar drama para criar tensão.',
        ],
        [
            'id' => 'dor', 'titulo' => 'Dor',
            'resumo' => 'A dor é o que incomoda hoje, nas palavras da pessoa. Hipóteses comuns de quem treina sozinho, para validar em conversas reais: não saber o que fazer na academia, treino genérico que para de funcionar, falta de constância, medo de se machucar, vergonha de perguntar, falta de tempo e a sensação de treinar muito e não ver mudança.',
            'como_aplicar' => "Pergunte antes de afirmar (c3 do funil de WhatsApp). Registre a frase exata do lead e devolva com as palavras dele. Nomeie a dor com respeito, sem dramatizar e sem culpar.",
            'exemplo' => 'Lead: "começo e paro em duas semanas". Resposta: "Parar em duas semanas é comum quando não tem ninguém acompanhando. É isso que você sente?"',
            'armadilha' => 'Cutucar a ferida para vender (exagerar o problema). Atribuir à pessoa uma dor que ela não disse.',
        ],
        [
            'id' => 'desejo', 'titulo' => 'Desejo',
            'resumo' => 'O desejo é o estado que a pessoa quer viver: ter um plano claro, sentir-se bem treinando, ver evolução, ter alguém de olho. Fale do estado, não de números nem prazos.',
            'como_aplicar' => "Descreva a vida depois do primeiro passo em termos de experiência (treinar sabendo o que fazer, ter um profissional ajustando o treino). Use o vocabulário do lead. Conecte o desejo ao produto certo: teste para experimentar, Premium para ser acompanhado.",
            'exemplo' => '"Na Consultoria Premium um profissional monta e acompanha o seu treino de perto" descreve o estado (ser acompanhado) sem prometer resultado.',
            'armadilha' => 'Prometer corpo, quilos ou prazo. O que a Intus oferece é acompanhamento de profissionais.',
        ],
        [
            'id' => 'objecao', 'titulo' => 'Objeções',
            'resumo' => 'Objeção é informação. Quase sempre há um medo ou uma dúvida embaixo. Passos: acolher, perguntar, responder com fato, devolver a escolha. Robô nunca fala preço da Premium: preço e fechamento são de uma pessoa.',
            'como_aplicar' => "\"Está caro\": pergunte com o que está comparando e passe para uma pessoa, que apresenta as condições. \"Não tenho tempo\": mostre que o treino é montado para a rotina dela e convide a testar 3 dias. \"Vou pensar\": pergunte o que ajudaria a decidir. \"Já tenho treino\": pergunte se alguém acompanha e ajusta. \"Tenho medo de me machucar\" ou qualquer dor/lesão: passa para um professor, sem opinar. \"Já tentei e desisti\": pergunte o que fez parar e destaque o acompanhamento. \"Preciso falar com alguém\": ofereça o teste para ela mostrar e responder depois.",
            'exemplo' => 'Lead: "vou pensar". Resposta: "Claro. Quer me contar o que ajudaria você a decidir? Se for dúvida de como funciona, eu explico em poucas linhas."',
            'armadilha' => 'Rebater a objeção como se fosse ataque. Insistir depois de um não. Dar opinião sobre dor, lesão ou doença.',
        ],
        [
            'id' => 'produtos', 'titulo' => 'Produtos e estrutura da oferta',
            'resumo' => "Teste grátis: 3 dias, só treino (não inclui dieta), sem cartão, na mesma plataforma que os alunos pagantes usam.\nConsultoria Premium: treino e nutrição online, com profissionais que montam, acompanham e ajustam tudo com a pessoa, do iniciante ao atleta. Preço e condições só uma pessoa apresenta.\nCódigo da Definição (CDD): programa próprio, entra no atendimento numa fase seguinte.",
            'como_aplicar' => "O teste é a porta de entrada, a Premium é o destino da conversa. Nunca inclua dieta no que o teste entrega. Se a pergunta não estiver nestes fatos, passe para uma pessoa.",
            'exemplo' => '"O teste é só de treino, sem dieta e sem cartão. Se gostar, aí te explico a consultoria."',
            'armadilha' => 'Inventar benefício, prazo, preço ou condição. Prometer que o teste inclui nutrição. Fatos a confirmar com o Luiz: valores, política de cancelamento, tempo de resposta da equipe.',
        ],
        [
            'id' => 'metodo', 'titulo' => 'Método Intus',
            'resumo' => 'Ideia central: te levamos ao próximo nível. Consultoria de treino e nutrição online, do iniciante ao atleta. Nossos profissionais montam, acompanham e ajustam tudo com você. O ciclo: conhecer a pessoa, prescrever, acompanhar a execução e ajustar com base no que ela fez de verdade.',
            'como_aplicar' => "Use o ciclo para explicar por que o acompanhamento importa: o treino muda porque a pessoa mudou. Use \"próximo nível\" como direção, sem número e sem prazo.",
            'exemplo' => '"Seu treino não fica parado: os profissionais ajustam conforme você evolui."',
            'armadilha' => 'Transformar o método em jargão. Explicar demais antes de a pessoa perguntar.',
        ],
        [
            'id' => 'persuasao', 'titulo' => 'Persuasão ética',
            'resumo' => 'Princípios que funcionam sem enganar: reciprocidade (dar o teste), compromisso (pequeno passo primeiro), prova social verdadeira (indicação real), autoridade (profissional de verdade), escassez real (o teste acaba mesmo), afinidade (falar como a pessoa fala) e clareza.',
            'como_aplicar' => "Cada mensagem usa no máximo um princípio. Se o princípio exige inventar algo (vaga limitada, depoimento, desconto falso), não use.",
            'exemplo' => 'T5 usa escassez real: "hoje é o último dia do seu teste". Isso é fato.',
            'armadilha' => 'Urgência fabricada, culpa, medo, comparação humilhante. Quebra a confiança e arrisca denúncia do número na Meta.',
        ],
        [
            'id' => 'estrutura', 'titulo' => 'Anatomia de uma mensagem de WhatsApp',
            'resumo' => 'Gancho com o nome, uma frase de contexto, uma frase de valor e uma pergunta fácil. Curta, uma ideia, uma pergunta. Para template da Meta: corpo de até 1024 caracteres, variáveis numeradas em sequência, e a Meta costuma recusar template que começa ou termina com variável. Categoria utilidade serve para avisos ligados a algo que a pessoa fez (cadastro). Convites e retomadas são marketing e custam mais.',
            'como_aplicar' => "Escreva e depois corte. Se tiver mais de uma pergunta, deixe só a principal. Termine com a pergunta ou com o SAIR nas últimas mensagens. Use o Analisador para conferir antes de cadastrar na Meta.",
            'exemplo' => '"Oi {{1}}, deixamos seu treino de teste pronto no app. Já conseguiu fazer o primeiro?" (gancho, contexto, pergunta fácil).',
            'armadilha' => 'Textão, vários pedidos na mesma mensagem, emoji demais, caixa alta, vários pontos de exclamação.',
        ],
        [
            'id' => 'tom', 'titulo' => 'Tom de voz e regras de escrita',
            'resumo' => "Direto, acolhedor e humano. Frases curtas. Sem gíria forçada. No máximo um emoji. Sempre se apresenta como assistente virtual, nunca finge ser pessoa.\nRegras: sem travessão; sem o padrão \"não é X, é Y\"; sem promessa de resultado, prazo ou número; sem urgência falsa; sem depoimento inventado; sem dieta no teste; sem preço da Premium; nunca insiste depois de um não ou do SAIR; mensagens proativas só entre 8h e 21h, no máximo uma por dia.",
            'como_aplicar' => 'Releia em voz alta. Se soa como anúncio ou como texto de IA, reescreva com as palavras que a pessoa usaria.',
            'exemplo' => '"Se travou em alguma parte, me conta aqui que a gente resolve."',
            'armadilha' => 'Linguagem corporativa, clichês ("jornada", "transformar sua vida", "espero que esteja bem"), frases de efeito vazias.',
        ],
    ];
}
