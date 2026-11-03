# Dashboard e funcionários

O painel em `/painel` reúne vendas, recebimentos conciliados, faturamento, saldo a receber, atrasos, comissões, carteira atual, conversão, evolução mensal e rankings. Os filtros permitem até 366 dias, por unidade, funcionário, produto, categoria, UF, cidade e bairro. Datas são interpretadas em Brasília e consultadas em UTC. O resumo pode ser exportado em CSV, com acesso autenticado e registro de auditoria.

Vendas contam propostas aceitas pela data do aceite e somam uma parcela, sem anualização. Recebimentos usam lançamentos de pagamento efetivos no período, com conciliação verificada, e excluem faturas estornadas, canceladas ou em disputa. Pagamentos de comissões usam a data do repasse, mesmo quando a comissão foi gerada em outro mês. O saldo após comissões não é lucro: outras despesas não são registradas neste módulo. Carteira e mensalidades previstas representam o estado atual, independentemente do período comercial. A conversão considera somente propostas criadas no período.

Registros demonstrativos ficam separados. O modo real exclui versões demonstrativas, versões de teste local (`local_preview`) e recebimentos cuja origem começa por `demo`. A seleção de demonstração só é permitida com `APP_DEMO=true`. Os planos reais em teste continuam disponíveis como opções de produto, mas suas operações de teste aparecem exclusivamente na base demonstrativa.

Vendedores consultam apenas propostas próprias e contratações feitas pelo tutor a partir de seus links. A atribuição segue a mesma precedência da comissão: vendedor da proposta, ou responsável pelo link quando o criador é o tutor. Gestores e financeiro consultam as unidades ativas autorizadas. O operador não recebe dados de comissões. As consultas e exportações aplicam as mesmas restrições no servidor.

## Carteira, previsão e origem

Cães e gatos são contados por pet distinto com plano de saúde ativo ou cancelamento agendado, dentro dos filtros e das unidades autorizadas. Propostas, vínculos aguardando regularização e planos cancelados não entram nessa contagem. O plano mais vendido usa a quantidade de aceites no período e mostra todos os empatados na liderança.

A previsão usa o mês da data final do filtro e soma faturas emitidas com vencimento nesse mês, incluindo vencimentos futuros. Faturas canceladas e estornadas são excluídas. O saldo dessas faturas desconta os pagamentos registrados; o recebido no mês usa separadamente a data dos pagamentos conciliados, inclusive de contratos vendidos ou faturas vencidas em outros meses. Mensalidades ainda não faturadas não são inventadas. O indicador de mensalidade ativa prevista continua disponível como referência da carteira recorrente atual.

A tabela de produtos separa vendas, recebido no período, previsto no mês, ativos hoje e cancelamentos efetivados no período. Cancelamento agendado permanece ativo até a data efetiva; encerramento natural da vigência não é contado como cancelamento. Os quadros de origem e indicação contam aceites e tutores distintos no período, enquanto os recebimentos podem vir de contratos mais antigos. Indicações por nome livre são declaradas e agrupadas pelo nome normalizado; tutores vinculados por cadastro mantêm sua identificação. A exportação CSV inclui esses indicadores e aplica os mesmos filtros e proteções da tela.

O cadastro online e os formulários de tutor permitem informar como a pessoa conheceu a São Francisco e quem recomendou. Esses dados ficam em `customers.acquisition_profile` e podem preencher futuras propostas. Links e campanhas continuam sendo resolvidos pelo servidor; o navegador não define responsáveis comerciais ou unidades por identificadores arbitrários. Alterar a origem no cadastro não altera propostas e assinaturas já congeladas. Registros históricos sem origem permanecem como não informados.

## Funcionários

Em `/funcionarios`, administração, gestão e financeiro consultam a equipe autorizada, vendas de planos do mês, meta, saldo de comissões a pagar e comissões pagas no mês. Somente a administração central cria e edita funcionários. O cadastro inclui matrícula única, cargo, telefone, admissão, e-mail, senha, perfil e unidades. A senha é armazenada por hash e nunca entra no log de auditoria. A desativação usa o controle existente de sessões. Não há exclusão do histórico de vendas ou comissões. As regras de cálculo continuam na tela de comissões por plano: valor fixo ou percentual do primeiro pagamento confirmado.

### Cargos e permissões

Em `/cargos`, a administração central cria cargos e escolhe, por tópico, o que cada um pode ver e fazer. Depois, atribui o cargo no cadastro do funcionário.

- **Nível de acesso:** todo cargo parte de um perfil (vendas, gestão, recepção, financeiro ou atendimento). O cargo só restringe esse nível, nunca vai além dele, e o nível também define os dados que a pessoa vê. Administração central não pode ser nível de cargo.
- **Bloqueio:** a regra vale no servidor (`ActiveUser`). Abrir uma tela não liberada leva à primeira tela permitida; gravar é recusado. Rota nova que a equipe alcança precisa entrar em `JobRole::OPTIONS`; o teste `JobRoleTest` falha se ela ficar de fora.
- **Limite do modelo:** como o cargo não ultrapassa o nível, não dá para juntar o que nenhum perfil tem junto. Assinar exige gestão ou vendas, e confirmar recebimento exige financeiro, então um mesmo cargo não faz as duas coisas.
- **Financeiro fora da tela de Financeiro:** sem "Ver faturas e pagamentos", a ficha do tutor esconde faturas e valores. A situação (regular ou bloqueado) continua visível, porque orienta o atendimento. A Visão geral mostra valores, então deve ficar desmarcada em cargos que não podem vê-los.
- **Sem cargo:** o funcionário segue o perfil e a lista "O que pode abrir", como antes.

## Geografia

Os mapas mostram somente a carteira do sistema, não uma estimativa de pessoas com planos de outras empresas. A intensidade do azul aumenta continuamente conforme a quantidade de planos ativos por localidade, em relação ao maior total no mapa exibido. Áreas sem planos ficam cinza. Os totais de tutores são contados separadamente, sem duplicar quem tem mais de um pet. Endereços atuais do cadastro determinam a localização; snapshots dos contratos permanecem intactos. Bairro foi acrescentado ao cadastro público e aos formulários administrativos. Endereços ausentes não recebem coordenadas ou bairros presumidos.

O mapa começa em Presidente Prudente e amplia seu enquadramento automaticamente para incluir as cidades com planos ativos na carteira autorizada. Não há aba Brasil nem um conjunto fixo de 28 cidades exibidas. A lista de cidades do filtro acompanha a carteira, mantendo Presidente Prudente como base. Um plano em Santo Anastácio, por exemplo, acrescenta a cidade ao mapa; sem planos ativos, ela deixa de compor a área exibida. Os filtros de unidade, funcionário, produto e base real ou demonstrativa são respeitados. Cadastro sem plano ativo não expande o mapa.

O servidor agrega os locais sem enviar endereços de tutores a serviços externos. Cidade e UF são conferidas no catálogo de municípios do IBGE; nomes não reconhecidos entram no aviso de endereço incompleto. O navegador recorta a malha e desenha somente os códigos das cidades da carteira e Presidente Prudente. O zoom considera os limites dessas cidades. São Paulo tem malha local; outras UFs são consultadas no IBGE quando necessárias e mantidas em cache. Uma falha parcial preserva as cidades com cartografia disponível e os rankings. Os arquivos do antigo recorte fixo permanecem apenas como referência e não determinam a abrangência atual.

O mapa de bairros usa as malhas do Censo 2022, conforme disponibilidade. A preparação inicial contém os 58 municípios paulistas presentes na base oficial. Presidente Prudente não possui bairros nessa base específica: nesse caso, o ranking funciona com o endereço cadastrado e a interface informa a ausência de cartografia. Pontos públicos de bairros do OpenStreetMap podem ser preparados como complemento por `scripts/prepare-osm-neighborhoods.py`, se o serviço estiver disponível; eles representam referências dos bairros, não endereços de clientes. Nomes sem correspondência cartográfica são informados e continuam nos rankings.

Para atualizar os recursos públicos, execute `python scripts/download-dashboard-maps.py`. Para preparar bairros de outra UF, execute `python scripts/prepare-neighborhood-map.py UF`. O script lê o GeoPackage público, preserva as coordenadas geográficas e exporta GeoJSON por município, sem dependências adicionais. Dados do OpenStreetMap requerem atribuição, já presente na interface. Fontes: [IBGE Malhas](https://servicodados.ibge.gov.br/api/docs/malhas?versao=3), [IBGE Censo 2022 bairros](https://geoftp.ibge.gov.br/organizacao_do_territorio/malhas_territoriais/malhas_de_setores_censitarios__divisoes_intramunicipais/censo_2022/bairros/), [OpenStreetMap](https://www.openstreetmap.org/copyright).
