# Comissões

Tela: `GET /comissoes` (`commissions.index`). Administração central vê toda a rede; gestão e financeiro, as unidades autorizadas; vendas vê apenas as próprias comissões ("Minhas comissões"). Recepção e tutores não têm acesso.

## Quem recebe

Regra aplicada no momento do primeiro pagamento confirmado, na ordem:

1. **Equipe que fez a proposta**: quem criou a proposta, se for da equipe (vendas, gestão ou administração).
2. **Dono do link**: se o próprio tutor contratou e chegou por um link de divulgação de alguém da equipe.
3. **Sem responsável comissionado**: contratação direta pelo site ou indicação feita por outro tutor, sem uma pessoa da equipe identificada. A origem e o indicador ficam registrados, mas o sistema não cria um pagamento automático.

Cada comissão guarda quem fez, canal, origem, campanha, link e indicação no momento do cálculo.

## Base, percentual e valor

- Base: valor pago na **primeira fatura**, quando ela está quitada e conciliada. Aceite, assinatura ou pagamento parcial não geram comissão.
- Regra: a administração central escolhe, por produto, um percentual sobre o primeiro pagamento ou um valor fixo. Cada alteração cria um novo registro com data e motivo (`commission_rates`). A regra e o valor ficam congelados na comissão.
- Sem regra definida, uma contratação com responsável identificado fica **Aguardando regra** e é calculada assim que a configuração do produto for salva.
- Contratações sem responsável da equipe ficam **Sem responsável comissionado**, com valor zero e origem preservada.
- Percentual: base × percentual, arredondado ao centavo. Valor fixo: o valor configurado, independentemente do preço pago.

## Situações

| Situação | Quando |
|---|---|
| A pagar | Primeiro pagamento confirmado e percentual definido |
| Aguardando regra | Primeiro pagamento confirmado sem percentual ou valor fixo para o produto |
| Sem responsável comissionado | Primeiro pagamento confirmado, mas nenhuma pessoa da equipe foi atribuída com segurança |
| Em contestação | O primeiro pagamento entrou em contestação; volta para "A pagar" se for confirmado de novo |
| Paga | Financeiro ou administração central registrou o pagamento ao beneficiário, com referência |
| Estornada | O primeiro pagamento foi estornado ou a fatura cancelada. Se a comissão já tinha sido paga, fica marcada para desconto do beneficiário |

O cálculo acontece em `FinancialService::applyVerifiedInvoice`, a mesma entrada usada pela confirmação demonstrativa e pela conciliação da Vindi, dentro da mesma transação. Todas as mudanças são auditadas (`commission_earned`, `commission_paid`, `commission_reversed`, `commission_rate_set`).

## Comando

`php artisan commissions:sync` gera comissões para primeiros pagamentos já confirmados (por exemplo, depois de atualizar uma base existente). Pode ser executado mais de uma vez sem duplicar. Em demonstração, `--demo-rates` cria percentuais de exemplo para produtos sem percentual.
