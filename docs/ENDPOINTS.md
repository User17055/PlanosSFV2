# Rotas e serviços internos

A aplicação usa sessões Laravel, proteção CSRF em formulários, autenticação e verificações de perfil/recurso/franquia. As rotas abaixo são da aplicação; não são endpoints dos parceiros. A lista autoritativa atual é obtida com `php artisan route:list`.

| Método e caminho | Função |
|---|---|
| GET /planos | Catálogo de oferta publicado |
| GET/POST /cadastro | Cadastro de cliente e pet |
| GET/POST /entrar; POST /sair | Sessão autenticada |
| GET /painel | Indicadores internos com escopo |
| GET /clientes; GET /clientes/{customer} | Busca e perfil do tutor |
| POST /clientes; POST /clientes/{customer}/pets | Cadastro autorizado |
| POST /clientes/{customer}/editar; POST /pets/{pet}/editar | Corrigir campos cadastrais permitidos com motivo e auditoria |
| GET /catalogo | Catálogo interno e versões |
| GET /rede | Administração central de franquias, usuários e vínculos autorizados |
| GET /propostas; GET /propostas/nova; POST /propostas | Consultar/criar proposta com versão e origem |
| GET /propostas/{proposal} | Revisão e acompanhamento |
| POST /propostas/{proposal}/link | Gerar link individual seguro |
| GET /convite/{token} | Acesso ao fluxo vinculado, exigindo identificação autorizada |
| POST /propostas/{proposal}/aceite | Aceite explícito do cliente autenticado |
| POST /propostas/{proposal}/tablet | Aceite presencial do titular com assinatura |
| POST /propostas/{proposal}/papel | Congelar, reservar e gerar documento para impressão |
| POST /propostas/{proposal}/documentos | Upload do papel assinado, PDF até 10 MB e signed_at real |
| POST /documentos/{document}/validar | Aprovar/rejeitar a digitalização; motivo na rejeição |
| GET /documentos/{document}/download | Download privado, integridade e auditoria |
| POST /propostas/{proposal}/cancelar | Cancelar proposta segundo seus efeitos já assumidos |
| POST /assinaturas/{subscription}/cancelar | Encerramento imediato ou agendado, conforme domínio |
| POST /assinaturas/{subscription}/solicitar-cancelamento | Pedido do cliente com motivo; não encerra automaticamente o contrato |
| GET /financeiro | Faturas e valores com escopo |
| POST /financeiro/{invoice}/confirmar | Simulação de recebimento somente no demo, perfil financeiro autorizado |
| GET /integracoes | Eventos e pendências, administração central |
| POST /integracoes/{event}/reprocessar | Nova tentativa auditada; não comprova sucesso externo |
| POST /webhooks/vindi | Entrada Basic Auth independente da sessão; CSRF isento apenas nesta rota |
| GET /portal | Dados próprios, contratos e situação financeira do cliente |

Elegibilidade/reserva recebe `subscription_id`, `benefit_rule_id`, `servicing_franchise_id` e `quantity`. A reserva exige `idempotency_key`; `service_item_id` identifica a execução única quando disponível. A consulta informativa não garante saldo. Confirmação volta a verificar dívida, carência, vigência e reserva.

Campos comerciais e financeiros são calculados pelo servidor. Não enviar `paid`, preço, saldo, novo produto ou unidade não autorizada esperando que alterem a assinatura. Não existe endpoint de troca de produto, transferência de saldo ou liberação manual de inadimplência.

Respostas HTML usam redirecionamento e mensagens; consultas que aceitam JSON retornam dados. Erros do domínio possuem código estável e mensagem. Convenções: 401 sem autenticação; 403 sem autorização; 404 recurso inexistente/não exposto; 409 conflito; 422 entrada inválida/regra; 429 limite. Não tratar uma consulta de elegibilidade negada como prova de falha de infraestrutura.

## Serviços de domínio

- `ProposalService`: cria proposta, emite link, registra aceite e emite papel, assegurando a vaga de pet/categoria.
- `ActivationService::activate`: único ponto de ativação; documento, pagamento, cadastro, vaga, vigência e bloqueios precisam estar regulares.
- `FinancialService::applyVerifiedInvoice`: entrada exclusivamente de adaptador que já verificou o provedor; nunca expor diretamente ao navegador.
- `EligibilityService`: requisitos da assinatura e do benefício, dívida global e atualidade financeira.
- `BenefitService`: reserva, confirmação, cancelamento e estorno com transação e trilha.
- `CancellationService`: encerramento e liberação de vaga sem apagar histórico ou herdar direitos.
- `ContractService`: PDF digital, papel para impressão, upload/revisão e verificação SHA-256 no disco privado.
- `VindiWebhookService` e `VindiReconciler`: entrada durável e consulta externa antes da atualização financeira.

## Rotinas de operação

`plans:recover-events` recupera eventos persistidos pendentes/erro; `plans:reconcile` agenda consulta das assinaturas com ID Vindi; `plans:finalize` efetiva encerramentos agendados. Os horários e filas estão em `routes/console.php`. O agendador e os workers precisam estar ativos; a chamada do comando não garante que um parceiro concluiu a operação.

## Orientação à equipe

1. Pesquisar tutor por documento antes de cadastrar. Confirmar pet e unidade; nomes parecidos não autorizam mesclagem.
2. Criar proposta e apresentar todas as condições ao titular. O vendedor preencher dados não constitui aceite.
3. No papel, imprimir a revisão congelada, colher assinatura, digitalizar todas as páginas e submeter à conferência. Rejeição exige motivo; correção de arquivo vira nova versão.
4. Em “aguardando pagamento”, abrir a cobrança existente quando houver integração homologada. Não emitir outra por clique repetido ou usar comprovante visual para ativar.
5. Para atendimento, selecionar pet, assinatura, benefício e unidade. Consultar, reservar e confirmar somente na execução; agendamento não é consumo.
6. “Carência” indica a data a aguardar; “limite atingido” exige conferir histórico; “inadimplente” exige regularizar todas as dívidas vencidas do tutor; “verificação pendente” exige conciliação. Não há liberação por exceção financeira.
7. Para corrigir execução, registrar estorno com motivo e quantidade permitida. Não apagar atendimento nem levar saldo a outro contrato/período.
8. Em integração manual/erro, conferir causa e responsável. Reprocessar não deve ser usado para criar cobrança com resultado anterior incerto. Nenhuma pendência externa deve aparecer como sincronização concluída.
9. A solicitação de cancelamento do portal deve ser tratada pela unidade responsável, com data efetiva e cobranças conferidas antes do encerramento. Correções cadastrais exigem motivo e preservam os dados congelados dos contratos anteriores.
