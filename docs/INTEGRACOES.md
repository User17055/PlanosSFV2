# Integrações: contratos e estado real

## Vindi Service v1

Foi preparada integração para **Vindi Service/Recorrência**, não para Vindi Commerce. A conta contratada, recursos, limites e meios de pagamento precisam ser homologados. Fontes primárias consultadas: [API oficial](https://vindi.github.io/api-docs/dist/), [autenticação](https://developers.vindi.com.br/reference/seguran%C3%A7a-e-autentica%C3%A7%C3%A3o), [payloads](https://developers.vindi.com.br/reference/payload-dos-webhooks) e [segurança/entrega de webhooks](https://atendimento.vindi.com.br/a/203305800-o-que-s%C3%A3o-e-como-funcionam-os-webhooks-).

`VindiClient` usa HTTPS e HTTP Basic com a chave da API como usuário e senha vazia. Leituras disponíveis: fatura, assinatura e faturas da assinatura com paginação. Criações de cliente/assinatura e cancelamento existem no cliente técnico, mas permanecem bloqueados por `VINDI_ALLOW_CREATION=false`. Não há retry automático de POST: timeout pode significar que a operação foi concluída externamente.

Variáveis, sem segredos no repositório:

```dotenv
VINDI_ENABLED=false
VINDI_BASE_URL=https://sandbox-app.vindi.com.br/api/v1
VINDI_API_KEY=
VINDI_ACCOUNT=central
VINDI_WEBHOOK_USER=
VINDI_WEBHOOK_PASSWORD=
VINDI_ALLOW_CREATION=false
```

Habilitar somente depois de testar credenciais e conta. A senha do webhook exige pelo menos 24 caracteres aleatórios. A configuração do provedor deve usar HTTPS e HTTP Basic. Não utilizar a mesma senha de um usuário do aplicativo. Impedir que proxy e logs registrem o cabeçalho Authorization ou credenciais da URL. No Nginx, preservar `HTTP_AUTHORIZATION` ao encaminhar para PHP-FPM.

O endpoint `POST /webhooks/vindi` autentica antes de persistir. O armazenamento contém identificadores, tipo/data do evento e hash SHA-256 do corpo; dados de cartão, token de pagamento e contato do payload não são gravados. Deduplicação usa o ID do evento quando fornecido; sem ID, deduplica corpos exatamente iguais. Uma atualização diferente da mesma fatura gera nova verificação. O banco é a caixa durável; o agendador recupera eventos pendentes se o despacho para a fila falhar.

`ProcessVindiWebhookJob` consulta `GET bills/{id}` pela credencial configurada. Somente essa resposta atual alimenta `FinancialService::applyVerifiedInvoice`. O vínculo exige conta e assinatura externa local inequívocas. O status do payload não comprova pagamento; retorno visual de checkout também não. A data da última alteração do provedor protege contra regressão por ordem de eventos. Pagamento em contrato terminal preserva o encerramento.

Se houver divergência de valor, estado não mapeado, recebimento parcial, estorno ou contestação, o evento segue para revisão. Quando a fatura local é conhecida, sua conciliação fica incerta e a verificação financeira pendente. A aplicação não pode afirmar que a situação está regular nesse caso.

Os avisos da Vindi são processados a no máximo 100 por minuto, abaixo do limite de 120 consultas da API. Na cobrança diária, que manda um lote de uma vez, o excedente espera na fila por até 6 horas sem gastar tentativas; só erros de verdade contam, com desistência no quinto. Cada falha registra no log o tipo, o código HTTP e o local, sem dados de cliente.

O `ReconcileVindiSubscriptionJob` percorre as faturas da assinatura. A rotina precisa operar dentro dos limites contratados, com monitoramento e ajuste de frequência. A frequência de execução não prova que a conta foi homologada. A competência depende das datas efetivamente retornadas pela API e deve ser conferida na implantação.

### Importação de cadastros

`php8.4 artisan plans:import-vindi-customers` lê da Vindi os clientes com assinatura ativa em plano cujo nome começa com "Plano" e mostra o que faria, sem gravar. Pacotes, serviços, direitos de uso de marca e testes ficam de fora. Com `--apply`, grava nome, CPF, e-mail, telefone, endereço e `vindi_customer_id` na unidade `CENTRAL`, e registra `customer.imported_from_vindi` na auditoria. Só faz GET na Vindi, não envia e-mail e pode rodar de novo sem duplicar. Quem tem CPF inválido ou repetido, ou conflita com um cadastro local, fica de fora e aparece na saída pelo id da Vindi.

O cadastro importado não liga a assinatura da Vindi a um contrato. Por isso, as faturas dele continuam indo para revisão manual, e iniciar o pagamento online de um contrato novo para esse cliente abre outra cobrança, além da assinatura que ele já tem. No site, quem tem o CPF importado recebe a orientação de pedir à unidade o link de acesso.

### Homologação ainda necessária

1. Confirmar conta central/franquia e impedir mistura de credenciais.
2. Aprovar mapeamento entre versões locais e produtos/planos externos.
3. Homologar a criação idempotente e a recuperação de resultado incerto implementadas em `VindiCheckoutService` antes de habilitar o checkout real. O institucional acessa esse fluxo pela API autenticada; detalhes em `PORTAL_INSTITUCIONAL.md`.
4. Validar cliente já existente, uma emissão inicial, mesma cobrança em cliques repetidos e checkout hospedado.
5. Testar pagamento, parcela futura, recusa antes de vencer, atraso, parcial, cancelamento, estorno e contestação.
6. Validar assinatura de webhook, repetição, ordem invertida, interrupção do worker e recuperação pela conciliação.
7. Definir monitoramento, responsável e prazo de resolução; registrar evidências sem segredos.

## SimplesVet e TudoPraPet

`SimplesVetAdapter` e `TudoPraPetAdapter` estão desabilitados. Ambos levantam `IntegrationPendingException` e a tarefa fica `manual`, com explicação. Não existe endpoint inventado, criação de conta ou sincronização declarada como concluída.

Solicitar aos fornecedores: documentação do ambiente correto, autorização, objetos e campos de escrita, pesquisa de cadastro existente, paginação, limites, erros, credenciais de teste e suporte. Os IDs deverão incluir o escopo da conta/franquia. Não associar pessoas por coincidência de nome. Não compartilhar prontuário com e-commerce, criar senha padrão ou sobrescrever cadastro em conflito de identidade.

Cada parceiro utiliza sua fila: `simplesvet` e `tudoprapet`. Uma tarefa manual deve identificar responsável, ação efetivamente realizada, evidência e data. Esta versão registra a pendência e permite reprocessamento técnico; o formulário completo de confirmação manual externa ainda precisa ser desenvolvido antes de usar essa operação como alternativa de produção.

O bloqueio no software permanece efetivo quando um parceiro falha. Uma marca estática de planista não autoriza serviços. Não foi implementado bloqueio de serviços lançados diretamente em sistemas externos, nem retirada automática de descontos no TudoPraPet.

## Estados e reprocessamento

`pending` = intenção persistida; `processing` = tentativa em andamento; `synced` = processamento confirmado; `error` = falha técnica; `manual` = revisão necessária. Reprocessar significa voltar explicitamente a `pending`, sem apagar o histórico de tentativas. Credenciais, payloads completos e respostas HTTP sensíveis não devem aparecer no painel ou em logs públicos.

Os testes usam HTTP simulado. Nenhuma integração foi testada com credenciais reais nesta entrega.
