# Decisões antes da operação real

Referência: especificação V2, 18/10/2026. As propostas técnicas precisam de aprovação e homologação.

| Tema | Aprovação necessária | Comportamento atual |
|---|---|---|
| Catálogo | Valores, coberturas, carências, espécies, idades, unidades, validade, renovação e texto integral | Exemplos demonstrativos sem autorização de venda |
| Vigência | Confirmar marco quando todos os requisitos forem verificados ou data futura contratada, se posterior | Regra central proposta; evento atrasado não retroage vigência |
| Aceite | Instrumento jurídico, identidade, confirmação de contato, tablet e conferência em papel | Evidências técnicas e hash não suprem aprovação jurídica |
| Presencial | Caixa/PIX/maquininha ou Vindi; comprovação, conciliação e próximas parcelas | Assinatura aguarda financeiro; confirmação simulada só no demo |
| Vindi | Conta central ou por franquia, recebedor, repasses, mapa de produtos e meios de pagamento | Uma conta configurada explicitamente; emissão bloqueada |
| Pacotes | Validade, renovação, término e bloqueio de dívidas originadas em Pacotes a prazo | Cobrança única com validade; parcelamento não liberado |
| Comissões | Percentual ou valor fixo real de cada plano e pacote; se indicação feita por outro tutor gera algum benefício; confirmar a prioridade quando a equipe cria a proposta de um cliente que chegou pelo link de outra pessoa | Nenhum valor é presumido. Quem criou a proposta tem prioridade; na autocontratação, usa-se o responsável pelo link; sem pessoa da equipe identificada, a origem fica registrada e não há pagamento automático |
| Financeiro | Parciais, excedentes, descontos, isenções, renegociação, estornos e contestação | Casos não homologados seguem conciliação, sem liberação automática |
| Cancelamento | Último período, data efetiva, débitos, próximas cobranças e confirmação Vindi | Encerramento local e financeiro tratados separadamente |
| Rede | Origem das vendas no site e cobertura entre franquias | Origem e unidade de atendimento explícitas |
| SimplesVet | API, autorização e pesquisa/vínculo de tutor/pet | Tarefa manual; marca “planista” não autoriza serviço |
| TudoPraPet | API de cadastro/convite, conflito de identidade, desconto e retirada em dívida | Tarefa manual; não cria senha padrão ou declara conta criada |
| Atualização financeira | SLA de conciliação e limite máximo sem verificação | Incerteza/desatualização impede confirmação; ajustar FINANCIAL_MAX_AGE_MINUTES |
| Privacidade | Aviso, retenção, descarte, acesso e atendimento aos titulares | Arquivo privado e dados mínimos; política organizacional pendente |
| Segurança | MFA, recuperação de contas, ClamAV, segredos e alertas | Serviços de produção precisam ser instalados e validados |
| Recuperação | Responsáveis, armazenamento externo, RPO/RTO e retenção | Procedimento proposto, sem teste de VPS/restauração externa realizado |

Nenhum perfil pode ignorar dívida, trocar produto congelado, transferir saldo/carência ou reativar contrato terminal. Essas invariantes não são decisões configuráveis por operador.
