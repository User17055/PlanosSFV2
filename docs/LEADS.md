# Leads dos anúncios

Cada formulário enviado no Facebook chega ao sistema pelo Pluga e, com telefone ou e-mail, entra na lista da equipe. O sistema não consulta o Facebook: ele só registra o que o Pluga envia.

Este documento descreve o código em 04/11/2026. A automação atual no Pluga e os dados de produção não foram conferidos.

## Como funciona

```text
Formulário no Facebook → Automação no Pluga → POST /webhooks/leads/{token}
                                                        │
                                          Tem telefone ou e-mail?
                                           │                   │
                                          sim                 não
                                           │                   │
                                   Lista em /leads      Envio recusado (422)
                                           │            lead não é gravado
                        Equipe chama no WhatsApp               │
                           e marca a situação           Aviso no topo de /leads
                                           │            para administradores e gerentes
                               Painel em /leads/painel
```

O recebimento é síncrono: não usa fila, `event` nem observer. A lista aberta busca novidades a cada 20 segundos e pausa após 1 hora sem uso. Não há aviso por e-mail nem push, então o lead novo só aparece para quem está com o sistema aberto.

## Configuração no Pluga

| Item | Valor |
| --- | --- |
| Método | `POST` |
| Endereço | `https://saude.saofrancisco.vet.br/webhooks/leads/CODIGO_SECRETO` |
| Formato | JSON com pares de nome e valor |
| Limite | 120 envios por minuto, por IP |

O código secreto é o valor de `LEADS_WEBHOOK_TOKEN` e funciona como uma senha: quem o tem consegue gravar leads. Nunca o coloque neste arquivo, em planilhas ou em conversas abertas.

O formato cru da API do Facebook, com `field_data`, é recusado. O Pluga precisa enviar cada dado no seu próprio campo.

## Campos a enviar

Telefone ou e-mail é obrigatório. Use os nomes da coluna "Campo recomendado".

| Dado | Campo recomendado | Também aceita | Regra ou uso |
| --- | --- | --- | --- |
| Telefone | `telefone` | `phone`, `phone_number`, `celular`, `whatsapp` | Com DDD, em qualquer formato. Com menos de 10 dígitos, não aparece o botão de WhatsApp. |
| E-mail | `email` | `e_mail` | Obrigatório só se não houver telefone. |
| Nome | `nome` | `name`, `full_name`, `nome_completo` | Vazio vira "Sem nome". A mensagem do WhatsApp usa o primeiro nome. |
| Id do lead | `lead_id` | `id` | Id de cada lead, não do formulário. Evita duplicar em reenvios. |
| Campanha | `campanha` | `campaign`, `campaign_name`, `ad_name`, `form_name` | Nome legível. Alimenta "De onde veio". |
| Rede de origem | `plataforma` | `platform`, `origem`, `canal`, `rede_social` | Aparece na lista, com a logo do Instagram ou do Facebook. `ig` vira Instagram e `fb` vira Facebook; outro valor aparece como veio, sem logo. Sem o campo, a lista não mostra a rede. |
| Anúncio | `anuncio` | Nenhum | Alimenta "Por anúncio". |
| Formulário | `formulario` | Nenhum | Aparece nos dados do lead. |
| Nome do pet | `pet` | `pet_name`, `nome_do_pet`, `nome_pet` | Aparece nos dados do lead. |
| Espécie do pet | `especie_do_pet` | Nenhum | Alimenta "Cachorro ou gato". |
| Idade do pet | `idade_do_pet` | Nenhum | Aparece nos dados do lead. |

Qualquer outro campo aparece nos dados do lead, até 30 campos de 500 caracteres cada. O nome pode vir com maiúsculas, acentos ou espaços: `Espécie do Pet` vira `especie_do_pet`.

```json
{
  "lead_id": "1234567890",
  "nome": "Maria da Silva",
  "telefone": "+55 18 99999-0000",
  "email": "maria@example.test",
  "plataforma": "ig",
  "campanha": "Plano Econômico - Novembro",
  "anuncio": "Vídeo gato 01",
  "formulario": "Formulário Plano Econômico",
  "pet": "Lua",
  "especie_do_pet": "Gato",
  "idade_do_pet": "3 anos"
}
```

Cuidados que evitam perder dados:

- **Campanha, anúncio e formulário:** não envie `campaign_name`, `ad_name` e `form_name` juntos. O sistema guarda só o primeiro e descarta os outros.
- **Nome da campanha:** envie o nome, não o id. Um id numérico aparece como "Campanha nº …123456".
- **Anúncio no painel:** só o nome exato `anuncio` alimenta o relatório. `Nome do anúncio`, por exemplo, aparece nos dados do lead, mas fica fora do painel.
- **Perguntas parecidas:** sem `telefone`, a resposta de "Você tem WhatsApp?" é tratada como telefone. Uma resposta como "Sim" não tem dígitos e é descartada.
- **Formulário do Facebook:** deixe o telefone obrigatório. Um envio só com e-mail inválido entra na lista sem nenhum contato.

## Como testar

O teste do Facebook prova a conexão, e um envio real prova os campos.

1. **Conexão:** envie um lead pela ferramenta de teste do Facebook. Ele aparece na lista marcado como "Lead de teste" e fica fora do painel.
2. **Limpeza:** peça a um administrador ou gerente para clicar em "Apagar teste".
3. **Campos:** preencha o formulário real com um telefone seu e abra `/leads`.
4. **Conferência:** veja se nome, botão de WhatsApp, e-mail, rede de origem, campanha, anúncio e dados do pet estão no lugar certo.

O lead de teste do Facebook entra mesmo sem telefone reconhecido, então sozinho ele não prova o mapeamento. Lead real não pode ser apagado: o envio da etapa 3 fica no histórico e conta no painel.

## Respostas do sistema

| Código | Corpo | O que significa | O que fazer |
| --- | --- | --- | --- |
| 201 | `{"received":true,"id":1}` | Lead criado | Nada |
| 200 | `{"received":true,"duplicate":true,"id":1}` | Reenvio do mesmo lead; nada novo foi gravado | Nada |
| 422 | `{"received":false,"error":"Informe telefone ou e-mail do lead."}` | Sem telefone nem e-mail reconhecível, corpo vazio ou JSON inválido; o lead não foi gravado | Corrija os campos e recupere o contato no Facebook |
| 404 | HTML, ou JSON com `Accept: application/json` | Endereço errado, recebimento desligado ou nenhuma unidade ativa | Confira o endereço; se estiver certo, acione o responsável pelo sistema |
| 405 | Página de erro | Método diferente de `POST` | Use `POST` |
| 429 | HTML com `Retry-After` (testado com `Accept: */*`) | Mais de 120 envios em um minuto | Espere um minuto e confira se o lead entrou |
| 500 ou 503 | Página de erro | Falha ou manutenção no sistema | Acione o responsável e informe o horário |

A faixa no topo de `/leads` mostra quando chegou o último lead. Administradores e gerentes também veem nela o último envio recusado, com os nomes dos campos recebidos, por até 7 dias.

## Como ler os resultados

O painel em `/leads/painel` mostra os últimos 7, 30 (padrão) ou 90 dias, no horário de São Paulo, sem os leads de teste. A conversão depende do que a equipe marca à mão.

| Indicador | O que mede | Cuidado |
| --- | --- | --- |
| Chegaram | Leads recebidos no período | Compara com o período anterior de mesmo tamanho |
| Esperando contato | Leads em "Novo" neste momento | Ignora o período escolhido |
| Foram chamados | Leads que saíram de "Novo" | Inclui quem foi marcado direto como cliente ou sem interesse |
| Viraram cliente | Leads marcados assim pela equipe | Não vem de contrato nem de proposta |
| Até o 1º contato | Tempo típico (mediana) entre a chegada e o primeiro clique em uma situação | Mede o clique, não o envio da mensagem |
| De onde veio | Leads por `campanha` | Sem o campo, entra como "Sem nome de campanha" |
| Por anúncio | Leads por `anuncio` | Só aparece se algum lead do período tiver o campo |
| Cachorro ou gato | Leads por `especie_do_pet` | Só aparece se algum lead do período tiver o campo |
| Quem atendeu | Leads pela última pessoa que mudou a situação | Não mostra quem atendeu antes dela |

A lista em `/leads` tem quatro situações: Novo, Em contato, Virou cliente e Sem interesse. Os novos ficam no topo, e o "Esperando há..." muda de cor a partir de 1 hora e de novo a partir de 24 horas. Cada lead mostra só o próximo passo: novo tem "Já chamei" e "Sem interesse"; em contato tem "Virou cliente" e "Sem interesse"; os encerrados têm "Reabrir". Ao clicar em um lead, abrem a rede e o anúncio de origem, os dados do formulário, quem atendeu e os horários. "Atendido por" mostra só a última pessoa que mudou a situação, porque o sistema não guarda o histórico. O painel não tem dados de investimento, então o custo por lead continua vindo do Facebook.

## Acesso

| Ação | Perfis |
| --- | --- |
| Ver lista e painel, mudar situação | `central_admin`, `manager`, `sales`, `operator`, `attendant` |
| Apagar lead de teste | `central_admin`, `manager` |
| Ver envio recusado e recebimento desligado | `central_admin`, `manager` |

Só `central_admin` vê todas as unidades; os demais veem as unidades vinculadas ao seu cadastro. Todos os leads caem em uma única unidade (`LEADS_FRANCHISE_CODE`, padrão `CENTRAL`), então quem não está vinculado a ela não vê nenhum lead. O perfil `attendant` enxerga apenas as telas de Leads. A área Leads também precisa estar liberada no cadastro do funcionário.

## Quando os números não batem

Exemplo: o Facebook mostra 21 leads e o sistema, 10. Siga esta ordem:

1. **Período:** compare as mesmas datas nos dois lados. O painel usa o horário de São Paulo e não conta leads de teste; a lista conta.
2. **Pluga:** veja se todos os leads aparecem no histórico da automação, com qual resultado, e se ela já estava ativa quando eles chegaram.
3. **Sistema:** conte as respostas do webhook. O comando não mostra o token nem dados pessoais, mas só cobre o `access.log` atual:

```bash
ssh vps 'grep -a /webhooks/leads/ /var/log/nginx/access.log | cut -d\  -f6,9 | sort | uniq -c'
```

| Resultado | O que indica |
| --- | --- |
| Mesma quantidade de `POST` e de leads gravados, quase todos 201 | O sistema gravou o que recebeu, e os outros leads não chegaram até ele |
| Vários 422 | O sistema recusou por não reconhecer telefone nem e-mail, então o problema está nos campos enviados |
| Vários 200 | Eram envios repetidos, tratados como duplicados |
| Vários 404 | Endereço errado na automação, recebimento desligado ou nenhuma unidade ativa |
| Vários 429 | O limite de 120 envios por minuto foi atingido |

Ao analisar logs e a tabela `leads`, mascare o token do endereço e não exponha dados pessoais.

## Referência técnica

### Rota e configuração

| Item | Valor |
| --- | --- |
| Rota | `POST /webhooks/leads/{token}`, nome `webhooks.leads`, em `routes/web.php` |
| Controller | `LeadController@intake` |
| Middleware | `web` e `throttle:120,1`, sem login; fora do CSRF em `bootstrap/app.php` |
| Autenticação | Token no caminho, comparado com `hash_equals`; não há assinatura HMAC |
| Corpo aceito | JSON (com qualquer `Content-Type`), form-urlencoded ou query string |
| Variáveis | `LEADS_WEBHOOK_TOKEN` e `LEADS_FRANCHISE_CODE`, lidas em `config/services.php` |

Sem `LEADS_WEBHOOK_TOKEN`, todo envio recebe 404. Depois de mudar o `.env`, recarregue a configuração com `php artisan optimize`.

### Ordem de execução

1. Valida o token. Se falhar, registra `Log::warning` e responde 404.
2. Achata o corpo com `Arr::dot`, ficando só com o último trecho de cada chave, em slug com `_`. Valores vazios ou não escalares são ignorados e, entre chaves de mesmo nome, vale a primeira.
3. Mapeia os campos pelos nomes exatos e, para nome, telefone e e-mail, por busca aproximada (`LOOSE`), ignorando chaves de pet, campanha ou anúncio (`NOT_PERSON`).
4. Marca como `source = teste` se algum valor começar com `<test lead:`.
5. Sem telefone nem e-mail e sem ser teste, registra `Log::warning` só com os nomes dos campos, guarda a recusa em cache e responde 422.
6. Descarta o e-mail que não passa em `FILTER_VALIDATE_EMAIL`.
7. Verifica duplicidade e responde 200 se encontrar.
8. Escolhe a unidade `LEADS_FRANCHISE_CODE` ou, se ela não estiver ativa, a primeira unidade ativa.
9. Grava em `leads` com `status = new` e responde 201.

### Regras de duplicidade

| Situação | Resultado |
| --- | --- |
| Mesmo `external_id` e mesmo telefone ou e-mail | Duplicado; nada é gravado |
| Mesmo `external_id` com outro contato | Grava sem o id, porque o valor provavelmente é fixo, como o do formulário |
| Sem id, com o mesmo telefone ou e-mail nos últimos 10 minutos | Duplicado; nada é gravado |
| Id novo, mesmo com contato já existente | Grava um lead novo |

### Tabela `leads`

| Coluna | Limite e tratamento |
| --- | --- |
| `external_id` | 80 caracteres, único |
| `name` | 150 caracteres; padrão `Sem nome` |
| `phone` | 30 caracteres; só dígitos |
| `email` | 190 caracteres |
| `pet_name` | 100 caracteres |
| `campaign` | 160 caracteres |
| `source` | `facebook_lead_ads` ou `teste` |
| `status` | `new`, `contacted`, `converted` ou `lost` |
| `handled_by` | Última pessoa que mudou a situação |
| `contacted_at` | Primeira mudança de situação; não é apagado ao voltar para `new` |
| `extra` | JSON com até 30 campos de 500 caracteres |
| `originating_franchise_id` | Unidade que recebe o lead |

### Rastros do recebimento

- **Token errado ou não configurado:** `Log::warning` em `storage/logs`.
- **Envio recusado:** `Log::warning` com os nomes dos campos, nunca os valores. A chave de cache `leads:last-refused` dura 7 dias e guarda só a última recusa.
- **Cada chamada:** a rota está no grupo `web`, então a resposta traz os cookies `XSRF-TOKEN` e `sao-francisco-session`. Com `SESSION_DRIVER=database`, como no `.env.example`, isso deve gravar uma linha em `sessions` por chamada.

### Limitações conhecidas

| Limitação | Efeito | Como foi verificado |
| --- | --- | --- |
| Um envio só com e-mail inválido passa na validação | Lead gravado sem nenhum contato | Testado localmente |
| Token errado e falta de unidade ativa dão o mesmo 404 | A falta de unidade não gera aviso próprio no log | Testado localmente |
| `field_data` da Graph API não é interpretado | Resposta 422 | Testado localmente |
| `campaign_name`, `ad_name` e `form_name` disputam a mesma coluna | Só o primeiro é guardado | Testado localmente |
| Dois envios simultâneos com o mesmo id novo | O índice único deve fazer o segundo responder 500 | Leitura do código |
| Token na URL, sem assinatura | Fica no `access.log`; se vazar, permite gravar leads falsos até ser trocado aqui e no Pluga | Leitura do código |
| "Virou cliente" não cria cliente nem proposta | A conversão do painel é informada à mão | Leitura do código |

### Arquivos

| Assunto | Arquivo |
| --- | --- |
| Recebimento, lista, painel e situação | `app/Http/Controllers/LeadController.php` |
| Modelo, WhatsApp e rótulos | `app/Models/Lead.php` |
| Estrutura da tabela | `database/migrations/2026_10_30_000001_create_leads_table.php` |
| Telas | `resources/views/leads/` |
| Atualização ao vivo | `public/assets/leads-live.js` |
| Contador do menu | `app/Services/Reports/StaffCounts.php` |
| Testes | `tests/Feature/LeadIntakeTest.php`, `tests/Feature/LeadAccessTest.php`, `tests/js/leads-live.test.cjs` |

Para rodar os testes do PHP:

```powershell
.\test.ps1 --filter "LeadIntakeTest|LeadAccessTest"
```

## Pendências

- [ ] Comparar os campos da automação atual no Pluga com a tabela deste documento.
- [ ] Verificar se o histórico do Pluga mostra os códigos de resposta e se ele reenvia sozinho após um erro.
- [ ] Definir quem do marketing recebe acesso e com qual perfil.
- [ ] Indicar quem é o responsável pelo sistema para o marketing acionar.
