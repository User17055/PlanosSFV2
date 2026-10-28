# Relatórios comerciais e de aquisição

`GET /relatorios` (`reports.index`) e `GET /relatorios/exportar` (`reports.export`) estão disponíveis para administração central, gestão e financeiro. Cliente, vendedor e recepção não têm acesso. O serviço revalida perfil ativo e aplica o escopo antes das consultas, contagens, opções e exportação.

## Escopo e filtros

A administração central consulta toda a rede, inclusive vínculos históricos de franquias inativas. Gestor e financeiro consultam somente franquias ativas com vínculo de usuário ativo; a antiga franquia padrão do usuário, sozinha, não concede acesso. Forçar `franchise_id` de outra unidade é rejeitado. Revogar vínculo ou desativar a unidade remove os dados do escopo.

Filtros aceitos: `date_from`, `date_to`, `franchise_id`, `salesperson_id`, `product_id`, `channel`, `acquisition_source`, `marketing_link_id`, `link_owner_id`, `campaign` e `status`. O responsável é o usuário que criou a proposta (`created_by`); o dono do link possui filtro separado (`link_owner_id`) aplicado tanto às propostas quanto aos links e seus acessos. Autocontratação do cliente é identificada e não atribuída a um vendedor inexistente.

O padrão é o primeiro dia do mês atual até hoje, no fuso America/Sao_Paulo. O início do primeiro dia é inclusivo; o início do dia seguinte ao último é exclusivo. A consulta aceita até 366 dias e não aceita datas futuras, invertidas ou anteriores a 2000. Os filtros por produto incluem as versões desse produto. `status` é a situação operacional da assinatura ou `no_subscription` para proposta sem assinatura.

## Definições de contagem

Todos os indicadores de contratação usam **a mesma seleção de propostas pela data de criação**, pelo escopo e pelos demais filtros. A situação apresentada é atual; não se reconstrói a situação histórica em uma data de corte.

| Indicador | Definição |
|---|---|
| Propostas criadas | Quantidade de propostas da seleção, incluindo as não aceitas |
| Aceites registrados | Proposta com aceite digital registrado ou documento final em papel assinado e validado |
| Documento válido | Assinatura com situação documental válida registrada no sistema |
| Primeiro pagamento | Assinatura com ao menos uma fatura inicial integralmente paga, confirmada e verificada |
| Já ativadas | Primeira ativação registrada, mesmo que depois haja suspensão ou encerramento |
| Status ativo/agendado | Situação operacional atual ativa ou com cancelamento agendado; não substitui elegibilidade |
| Recebido conciliado | Transações de pagamento no período para a seleção, em faturas verificadas/confirmadas com estado pago ou parcial |
| Recebimento demo | Mesma regra, mas transações cuja origem começa por `demo`, sempre separadas do recebido real |

Aceite, documento, pagamento e ativação são etapas diferentes. Os números não precisam formar uma sequência decrescente: um recebimento pode anteceder a conferência do contrato em papel. Não somar preço de contrato ou mensalidades futuras como receita. Pagamentos de propostas criadas fora do período não entram neste relatório, ainda que recebidos dentro dele; essa é uma análise da seleção comercial, não o extrato completo do caixa.

Os valores são somados a partir das transações, sem junção multiplicadora com documentos, links ou outros pagamentos. Faturas com conciliação incerta, canceladas, estornadas ou contestadas não entram. O relatório não calcula receita líquida depois de taxas, nem um livro contábil de estornos. Uma alteração financeira posterior pode modificar a soma atual; os filtros não são uma fotografia contábil imutável.

## Aquisição e links

As origens são `unknown`, `direct`, `referral`, `shared_link`, `instagram`, `facebook`, `google` e `other`. Valores históricos ausentes ou desconhecidos aparecem como **Não informado**. O canal de contratação não é usado para inventar aquisição: uma proposta no canal de link não comprova origem por campanha ou indicação.

A linha da proposta reúne tutor/pet/produto preservados no snapshot quando disponível, responsável pela criação, franquia de origem, canal, origem, indicador, campanha, link e seu responsável, estado operacional/documental, primeiro pagamento e datas de aceite/ativação. Ela não expõe tokens de compartilhamento, identificador de sessão, CPF, e-mail ou endereço no CSV.

A seção de links conta registros de acesso (`attribution_touches.touched_at`) no período e apresenta, separadamente, propostas da seleção, aceites e ativações atribuídos. Inclui links sem propostas e distingue links ativos, revogados e expirados, preservando seus números históricos. Acessos são eventos; repetição de sessão ou dispositivo não prova pessoas únicas. Não é calculada uma taxa de conversão por pessoa.

Período, franquia, produto, origem, campanha, link e responsável pelo link filtram os acessos. Responsável pela proposta, canal e situação filtram somente as propostas atribuídas, pois não têm correspondência inequívoca com um acesso anterior. Essa diferença é indicada na tela. O escopo de franquias é obrigatório em ambas as bases.

## CSV e auditoria

Uma linha por proposta com os mesmos filtros e permissões da tela. A exportação possui UTF-8 com BOM, separador `;`, valores monetários em reais com vírgula decimal e limite de 10.000 propostas por seleção. Reduza o período quando atingir o limite. A exportação é auditada com ator, filtros e quantidade; a resposta não deve ficar em cache público.

Campos potencialmente interpretados como fórmula por planilhas recebem apóstrofo inicial. Isso inclui textos iniciados por `=`, `+`, `-` ou `@`, inclusive após espaços e caracteres de controle. O arquivo deve continuar sendo tratado como informação pessoal com acesso restrito.

## Verificação automatizada

`tests/Feature/ReportTest.php` cobre isolamento por franquia/vínculo ativo na tela e CSV, acesso por perfil, filtros combinados, aceite diferente de ativação, soma sem multiplicar pagamentos por documentos, origens desconhecidas, neutralização de fórmula, limites de datas e fronteiras do fuso de Brasília, além de acessos repetidos sem prometer pessoas únicas.

Esses testes usam banco de teste e transações sintéticas. Não demonstram atribuição ou conciliação financeira com fornecedores reais.
