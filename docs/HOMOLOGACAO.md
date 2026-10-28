# Roteiro de homologação e aceite

Os testes automatizados locais cobrem parte das regras com SQLite e HTTP simulado. Os cenários abaixo constituem roteiro pendente de homologação na infraestrutura e nos serviços reais. Guardar resultado, ambiente, data, responsável e evidência sem segredos. Falhas e limitações devem permanecer visíveis no termo de aceite.

## Contratação e documento

- Site, link com preenchimento do vendedor, link com preenchimento do cliente, tablet e papel.
- Tentativa de vendedor assinar digitalmente em lugar do titular; contato não verificado; link expirado/revogado e acesso a proposta de outro cliente.
- Dados obrigatórios incompletos e produto fora da espécie/idade/cobertura de unidade.
- Falha na geração ou no armazenamento do PDF: aceite preservado, estado documental pendente, sem ativação. Repetir a emissão pelos mesmos dados congelados.
- Papel: documento para impressão não ativa; arquivo incorreto, malicioso, muito grande ou incompleto recusado; digitalização pendente só vale após aprovação. Autor do upload e da conferência identificados.
- Alterar cadastro depois do aceite; comprovar que PDF, snapshot e hash anteriores não mudam. Novo arquivo cria versão, sem reescrever evidência anterior.
- Downloads próprios autorizados e download de outro titular/franquia negado; `.env`, banco, logs e PDFs privados inacessíveis diretamente.

## Imutabilidade, categorias e concorrência

- Um pet com Plano e Pacote simultâneos; segundo vínculo da mesma categoria negado em site, link e presencial.
- Corrida real de duas requisições e duas conexões MySQL para formalizar o mesmo pet/categoria: uma só vaga/assinatura comprometida.
- Mudar plano, pet, tutor, categoria, snapshot e locked_at após congelamento por Eloquent, update em massa e SQL do usuário operacional: banco deve rejeitar.
- Encerramento agendado mantém a vaga; efetivo libera atomicamente. Pagamento tardio não reativa contrato terminal.
- Nova contratação tem proposta, documento, data, carência e saldo próprios, sem herança. Contrato antigo e dívidas permanecem.
- Restaurar backup com triggers e verificar novamente todas as proteções e chaves únicas.

## Financeiro e rede

- Pagamento inicial integral sem documento não ativa; documento válido sem pagamento também não. Retorno de checkout e payload forjado não confirmam recebimento.
- Dívida em Plano na franquia A bloqueia consulta/reserva/execução na B, por todos os perfis e endpoints.
- Um tutor com dois pets/Planos e um Pacote: uma dívida bloqueia todos os benefícios. Quitar uma de várias dívidas mantém bloqueio; quitar todas reavalia carências e saldos.
- Parcela futura e recusa do cartão antes do vencimento não geram dívida vencida; primeiro pagamento pendente impede somente a ativação correspondente.
- Reserva regular antes do vencimento, tentativa de execução depois: negar. Duas franquias disputam último saldo com pagamento concorrente: sem consumo duplo ou liberação inconsistente.
- Status financeiro incerto ou desatualizado: não executar e mostrar a última atualização. Conferir virada de vencimento em America/Sao_Paulo.
- Webhook duplicado, fora de ordem, não autenticado; notificação perdida; worker interrompido; API indisponível; valor/vínculo divergente; estorno e contestação.
- Timeout após criar operação no provedor: conciliar antes de tentar outra; jamais emitir nova assinatura apenas por falha de rede.

## Benefícios e permissões

- Carências individuais, ciclos com dia 31, fevereiro e ano bissexto, limite anual versus cobrança mensal, fim exclusivo do período.
- Reserva expirada/cancelada, confirmação repetida, serviço_item duplicado, saldo ilimitado e limites por vigência.
- Estorno parcial e repetido: total nunca excede consumo original, histórico preservado e período antigo não concede crédito ao atual.
- Trocar franchise_id no formulário, URL e exportação; usuário sem vínculo, inativo e com dois vínculos autorizados.
- Cliente vê somente seus dados; outra unidade recebe apenas identificação mínima e resultado de elegibilidade, sem documentos/financeiro indevidos.

## Parceiros e infraestrutura

- SimplesVet/TudoPraPet indisponíveis: bloqueio local permanece; pendência externa explícita; nenhuma conta ou atualização fictícia.
- Provar com documentação e resposta real qualquer automação habilitada; tarefas manuais exigem responsável e confirmação auditada antes de servir como fluxo de produção.
- Reiniciar VPS e comprovar retorno de Nginx, PHP-FPM, banco, Redis, workers e scheduler sem sessão aberta.
- Alertas de fila, disco, certificado, falha de backup, PDF e última conciliação; confirmar que logs não expõem dados pessoais/segredos.
- Restaurar banco e documentos em ambiente isolado sem disparar cobrança/mensagem real; validar hashes e integridade; medir RPO/RTO.

## Registro de aceite

O aceite final precisa relacionar RF-01 a RF-19, condições comerciais aprovadas, integrações efetivamente homologadas, treinamento, responsáveis pela operação e limites restantes. Não apresentar preparação técnica ou simulação como integração/produção concluída.
