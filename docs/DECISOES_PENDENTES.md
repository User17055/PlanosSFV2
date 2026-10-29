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

## Pendências registradas em 28/10/2026

Levantadas ao remover os descontos em ração e em produtos Franpet (pedido do doutor, 28/10).

| Tema | O que falta | Por quê |
|---|---|---|
| Contratos já aceitos | Nada a fazer no código; avisar a equipe | O contrato aceito guarda a versão da época. A remoção dos descontos vale só para contratações novas |
| Texto do CMS do institucional | Apagar "Até 20% OFF em rações" do conteúdo da página `/planos-saude/` no banco do site | Esse texto aparece quando a API da gestão está indisponível e voltaria a prometer o desconto |
| Conversão Word → PDF em produção | Escolher e validar um conversor no servidor da gestão (por exemplo LibreOffice headless), comparando o PDF com o gerado pelo Word | `OriginalWordContract::pdf` só converte no Windows com Microsoft Word; em Linux recusa a geração do contrato |
| Word originais dos contratos | Atualizar também os `.docx` de origem (pasta de contratos do André) sem as duas frases | Os arquivos preparados em `resources/contracts/word` foram editados direto; uma nova importação a partir dos originais antigos traria as frases de volta |
| Servidor da gestão | Decidir onde a gestão roda (a VPS atual tem só PHP 8.3; o V2 exige 8.4) e então executar `deploy/deploy.sh` e `deploy/rollback.sh` uma vez em homologação | Os scripts seguem esta documentação, mas nunca rodaram num servidor; o CI só confere a sintaxe (shellcheck) |
| MySQL de produção | Ligar `log_bin_trust_function_creators` (ou migrar com usuário `SUPER`) e criar os dois usuários de banco | Sem isso as migrations param nos triggers (erro 1419); ver `OPERACAO.md` |