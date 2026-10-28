# Institucional e gestão separados

O cliente conhece os planos, se cadastra, confere e assina o contrato e acessa seus dados em `https://saofrancisco.vet.br`. A equipe usa `https://sf.lisontech.com.br`, onde também fica a API. O pagamento abre a página segura da Vindi e sua confirmação aparece na área do cliente.

| Projeto | Arquivos | Responsabilidade |
|---|---|---|
| Gestão/API Laravel | Raiz: `app`, `bootstrap`, `config`, `database`, `routes`, `resources`, `public` | Cadastro oficial, propostas, assinatura, PDF privado, cobrança, elegibilidade e administração |
| Institucional Symfony | `institucional/public_html` | Site existente e novas telas do cliente |
| Configuração do institucional | `institucional/configuracao_privada`, fora de `public_html` | Chave da API e configurações privadas |
| Estado do institucional | `institucional/var_runtime`, fora de `public_html` | Sessões, cache e logs |

O institucional não importa arquivos PHP, templates ou banco da gestão. Comunica-se por HTTPS com `/api/site/v1`. Fontes e recursos das novas telas estão incluídos no próprio institucional.

## Telas e fluxo

- `/planos-saude/`: conserva o banner e a estrutura visual; obtém nomes, valores e benefícios do catálogo versionado da gestão. Se a API estiver indisponível, mantém o conteúdo informativo do CMS e o contato com a equipe.
- `/contratar/{code}`: cadastro com plano selecionado, ou escolha de pet para cliente autenticado.
- `/area-do-cliente`: pets, propostas pendentes, contratos, benefícios/carências e pagamentos.
- Login, confirmação/reenvio de e-mail, recuperação de senha, cadastro de outro pet e logout.
- Conferência do PDF autenticado, assinatura pelo titular e geração/recuperação do documento final sem duplicar contratação.
- Pagamento de faturas disponíveis pela Vindi e solicitação de cancelamento para análise da unidade.

O cadastro não registra aceite nem pagamento. Um CPF existente não permite assumir o cadastro de outra pessoa. A ativação continua dependente de documento válido, pagamento confirmado e elegibilidade. PDFs são servidos pelo próprio institucional, com autorização verificada na gestão, sem expor caminhos privados.

Os antigos `PLAN_SUBSCRIPTION_URL` e `PLAN_SUBSCRIPTION_CODES` não são mais necessários: os botões levam às rotas locais do institucional.

## Conexão de produção

Na gestão, configurar no `.env` privado:

```dotenv
SITE_API_ENABLED=true
SITE_API_KEY=CHAVE_ALEATORIA_PRIVADA_DE_PELO_MENOS_32_CARACTERES
SITE_PUBLIC_URL=https://saofrancisco.vet.br
SITE_TOKEN_HOURS=24
```

Gerar uma chave exclusiva; o texto acima é um marcador. Na gestão, configurar `APP_URL=https://sf.lisontech.com.br`. No institucional, copiar `configuracao_exemplo/portal.php.example` para `configuracao_privada/portal.php`, ao lado de `public_html`. A URL da API é `https://sf.lisontech.com.br/api/site/v1`; preencher a mesma chave privada. Também são aceitas as variáveis `PORTAL_API_URL`, `PORTAL_API_KEY` e `PORTAL_PAYMENT_HOSTS`. Limpar os caches e reiniciar/recarregar os processos após mudar a configuração.

A chave compartilhada e o token individual circulam somente entre servidores. O navegador recebe apenas o cookie de sessão do institucional. Os tokens são armazenados por hash na gestão, expiram e são invalidados pelo logout, troca de senha ou desativação do cliente. Os formulários têm CSRF; cada operação verifica titularidade. Não compartilhar cookies entre domínios nem configurar CORS aberto para o navegador.

Instalar os projetos separadamente com seus respectivos `composer.lock`. O institucional exige PHP 8.5 e cURL. Executar `php artisan migrate` na gestão para adicionar `vindi_checkouts` e `portal_tokens`. Preservar bancos e documentos; não usar `migrate:fresh` nem o seeder demonstrativo em produção.

Web roots: `public_html` no institucional e `public` na gestão. Não publicar configurações privadas, sessões, logs, SQLite, backups ou testes. As rotas `portal_*` usam a API, sem depender das tabelas de usuários/roles do CMS; `/luzsisadm` conserva sua autenticação anterior.

Configurar SMTP na gestão: as mensagens de confirmação e recuperação apontam para o institucional. Os contratos Word originais continuam dependendo do conversor existente: Windows/Microsoft Word, ou uma substituição validada para a hospedagem escolhida.

## Vindi

Seguir `INTEGRACOES.md`. Depois de homologar conta, método e webhook, habilitar explicitamente `COMMERCIAL_RULES_APPROVED`, `VINDI_ENABLED`, `VINDI_ALLOW_CREATION` e `VINDI_CHECKOUT_ENABLED`. Manter workers/agendador operacionais.

`VINDI_CHECKOUT_PLANS` relaciona a versão local imutável ao plano remoto. Exemplo ilustrativo:

```dotenv
VINDI_CHECKOUT_PLANS='{"123":{"approved":true,"plan_id":456,"price_cents":5200,"enrollment_fee_cents":0,"billing_cycle":"monthly","duration_months":12,"renewal_policy":"automatic"}}'
VINDI_PAYMENT_HOSTS=app.vindi.com.br,sandbox-app.vindi.com.br
```

Conferir IDs, preços, adesão, periodicidade, duração e renovação reais. O plano remoto fornece as condições de cobrança; mantê-lo imutável depois da homologação evita divergências antes da conferência da fatura. Planos demonstrativos e `local_preview` não emitem cobranças reais. O institucional não coleta cartão/CVV.

A intenção de emissão é persistida antes das chamadas externas. Repetições não repetem POST. Depois de timeout, a recuperação consulta a referência existente; resultado incerto exige conferência manual. Não apagar a tentativa para emitir outra. A fatura inicial é vinculada à obrigação já criada pelo contrato. Cliente, plano, valor e endereço de pagamento são conferidos. O retorno do navegador não confirma recebimento: a conciliação consulta a API Vindi autenticada.

Solicitar cancelamento não cancela automaticamente a cobrança remota. A equipe conclui o fluxo local e confere a obrigação na Vindi conforme a política vigente.

## Abrir localmente

A conexão local foi configurada sem habilitar pagamentos reais. As duas tabelas foram adicionadas ao SQLite existente, com cópia anterior em `storage/app/private/portal-setup`.

Abrir `Iniciar Site e Gestao.cmd` ou executar `./start-site.ps1 -SitePhp "C:\caminho\php85\php.exe"`. O iniciador também procura `PHP85_PATH` e o runtime local já utilizado para o institucional.

- Site e planos: `http://127.0.0.1:8080/planos-saude/`.
- Cliente: `http://127.0.0.1:8080/area-do-cliente`.
- Gestão: `http://127.0.0.1:8765/painel`.

Sem configuração prévia, `scripts/configure-local-site.ps1` gera uma chave nos dois arquivos privados. Exige `APP_ENV=local` e `APP_DEMO=true`, faz backup do `.env` e preserva a Vindi. Não usar esse configurador para produção. Para outras portas, ajustar a URL da API e `SITE_PUBLIC_URL` nos dois lados.

Sem banco CMS configurado, o iniciador ativa uma prévia somente de `/planos-saude/`, com o catálogo da gestão e um banner local. A opção exige `APP_ENV=local` e `PORTAL_LOCAL_PREVIEW=1`; não representa cópia completa do conteúdo institucional de produção.

## Verificação

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit --filter "SitePortalApiTest|VindiCheckoutTest|OnlineRegistrationTest|CustomerPortalTest|SigningReliabilityTest"
& "C:\caminho\php85\php.exe" institucional/public_html/vendor/phpunit/phpunit/phpunit -c institucional/tests/phpunit.xml
python tests/portal_http.py --php-api C:/php/php.exe --php-site "C:\caminho\php85\php.exe"
```

Passaram 36 testes/237 verificações do conjunto Laravel selecionado e 8 testes/34 verificações do institucional. O teste HTTP real entre os dois processos também passou: catálogo, cadastro, conferência PDF, aceite, documento final, portal e logout. Usa bancos descartáveis e não envia e-mail/cobrança real. A prévia com o banco local existente respondeu HTTP 200 nos planos e login, com quatro botões locais de contratação.

O endereço da gestão foi definido como `sf.lisontech.com.br`. Ainda é necessário configurar hospedagem/HTTPS, conexão e SMTP de produção, relacionar os planos Vindi e homologar emissão, pagamento, recorrência e cancelamento. Nenhum arquivo foi enviado para os servidores de produção nesta alteração.
