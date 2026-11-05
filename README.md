# São Francisco — Planos e Pacotes

Contratação e área do cliente no institucional, com arquivos separados da gestão e conexão por API: [integração do institucional](docs/PORTAL_INSTITUCIONAL.md). Abra `Iniciar Site e Gestao.cmd` para executar os dois projetos localmente.

Aplicação web PHP/Laravel baseada na especificação V2 fornecida: painel da rede, tutores/pets, catálogo, propostas dos três canais, contratos PDF, financeiro, elegibilidade, carências, reservas/consumo/estorno e portal do cliente.

**Entrega local demonstrativa. Não houve implantação em VPS nem homologação com contas reais dos parceiros.** Dados, valores, benefícios e textos de exemplo não constituem oferta comercial aprovada.

## Executar no Windows

Requisitos: Composer 2 e PHP 8.3+ com `pdo_sqlite`, `sqlite3`, `mbstring`, `dom`, `xml`, `fileinfo`, `openssl` e `curl`. Ambiente verificado: PHP 8.4.6, Laravel 13.37.0, Dompdf 3.1.6. Versões exatas em `composer.lock`.

```powershell
composer install
Copy-Item .env.example .env
php -d extension=pdo_sqlite -d extension=sqlite3 artisan key:generate
```

Configure no `.env` para demonstração:

```dotenv
APP_NAME="São Francisco"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8765
APP_DEMO=true
DB_CONNECTION=sqlite
QUEUE_CONNECTION=database
MAIL_MAILER=log
VINDI_ENABLED=false
```

```powershell
if (-not (Test-Path -LiteralPath database/database.sqlite)) { New-Item -ItemType File -Path database/database.sqlite }
php -d extension=pdo_sqlite -d extension=sqlite3 artisan migrate --seed
.\start.ps1
```

Abra `Iniciar Sistema.cmd` por duplo clique ou execute `.\start.ps1`. O script inicia somente o servidor web local, preservando as extensões PHP. Mantenha sua janela aberta; Ctrl+C encerra o servidor. Não usar `artisan serve` neste ambiente: seu processo filho pode perder as flags `-d`.

Workers e agendador devem ser iniciados separadamente:

```powershell
.\worker.ps1
.\worker.ps1 -Scheduler
```

As flags `-d` são necessárias no PHP Windows usado aqui, cujas extensões SQLite não estão ativadas no `php.ini`. Com as extensões habilitadas, use `php artisan` normalmente. Os assets prontos estão em `public/assets`; a interface não exige compilação do frontend.

Contas **exclusivamente demonstrativas**, senha `Demo@2026!`:

| Perfil | E-mail |
|---|---|
| Administração central | `admin@saofrancisco.local` |
| Financeiro | `financeiro@saofrancisco.local` |
| Cliente | `cliente@saofrancisco.local` |

Não executar o seeder demonstrativo em produção. Use contas próprias e credenciais novas.

## Fluxo de demonstração

Abra `/painel` como administrador e consulte tutor, pets, vínculos e parcelas. Crie uma proposta e conclua o aceite com o usuário cliente. O aceite gera o contrato, mas não confirma pagamento. O financeiro pode simular recebimento com referência auditável em modo demo; esse comando é bloqueado em produção.

No tablet, o titular manifesta aceite e desenha a assinatura. No papel, a emissão reserva a categoria e produz documento sem assinatura. Envie todas as páginas em PDF, informe a data real da assinatura e faça a conferência documental. Um anexo pendente não ativa o vínculo.

Em `/cadastro`, um novo cliente cria seu acesso e pet. Para tutor já cadastrado, é necessário convite válido da proposta, CPF/e-mail correspondentes e ausência de outro acesso vinculado; o nome sozinho não concede acesso ao histórico. Fora do modo demo, a confirmação do contato utiliza link assinado. Em teste local com `APP_DEMO=false` e `MAIL_MAILER=log`, a mensagem fica no arquivo privado `storage/logs/laravel.log`; o desenvolvedor pode abrir o link ali. Com `APP_DEMO=true`, a confirmação é simulada para navegação. Produção exige SMTP configurado e entrega homologada. O log local não representa envio real.

O portal permite solicitar cancelamento com motivo; a unidade responsável confirma a data efetiva e o tratamento das cobranças. A solicitação do cliente não encerra o vínculo automaticamente. Tutor e pet podem ter os campos cadastrais permitidos corrigidos com motivo e auditoria; os dados históricos de contratos aceitos continuam preservados, e a elegibilidade é reavaliada nas próximas autorizações.

Teste dívida vencida de um Plano bloqueando os benefícios de outro pet e de um Pacote do mesmo tutor. A confirmação de execução revalida a situação atual; uma reserva anterior não permite contornar o bloqueio.

## Integrações e documentos

O botão flutuante **Chat** abre o atendimento dos leads em tela inteira, com conexão individual ao Digisac e acesso restrito ao responsável local, e leitura para os administradores. Ativação, configuração e limites: [Chat com Digisac](docs/DIGISAC_CHAT.md). A conexão real exige token pessoal e os IDs do usuário/canal, cadastrados em **Conexão**.

| Destino | Entregue | Pendente |
|---|---|---|
| Vindi Service | Cliente HTTP HTTPS/Basic Auth; webhook autenticado, durável e deduplicado; fila; consulta atual da fatura; conciliação pelo domínio | Credenciais, conta central/franquia, mapa comercial, checkout e emissão/cancelamento homologados; testes reais |
| SimplesVet | Adaptador desabilitado e pendência manual explícita | Documentação e autorização de escrita |
| TudoPraPet | Adaptador desabilitado e pendência manual explícita | API de clientes/convites e política de benefícios comerciais |

O navegador e o payload financeiro do webhook nunca confirmam pagamento. O processador consulta a Vindi. Divergência, vínculo desconhecido, parcial ou estorno não homologado exigem revisão. Os métodos de criação HTTP não repetem chamadas automaticamente e ficam bloqueados por `VINDI_ALLOW_CREATION=false`. A aplicação não oferece emissão real enquanto o mapa comercial estiver pendente.

PDFs ficam no disco privado `local`, em `storage/app/private/contracts`, com SHA-256, versão e evidências. Downloads passam por autenticação e escopo. Não criar link público desse diretório. Hash não substitui identidade ou aprovação do instrumento contratual. O ClamAV está preparado, mas não instalado nesta entrega; produção deve usar `CONTRACT_REQUIRE_ANTIVIRUS=true` com scanner operacional.

## Matriz de requisitos

| RF | Situação do código e limites |
|---|---|
| 01 Tutores/pets | Cadastro, consulta e correção de campos permitidos com motivo/auditoria; mesclagem de duplicidades pendente |
| 02 Catálogo versionado | Criar/editar rascunho, clonar versão e publicar com proteções; valores, coberturas e texto reais aguardam aprovação |
| 03 Canais | Site, link e presencial com unidade e responsável |
| 04 Aceite/documento | PDF servidor, evidências e papel com revisão; aprovação jurídica pendente |
| 05 Cobrança Vindi | Conector preparado; emissão e checkout reais pendentes |
| 06 Conciliação | Implementação autoritativa/idempotente e testes HTTP simulados; homologação real pendente |
| 07 Vigência | Ativação central com requisitos e estados terminais |
| 08 Carências | Regras por benefício a partir da vigência própria |
| 09 Uso | Reserva, consumo e estorno rastreáveis; concorrência multiprocesso em MySQL pendente de teste |
| 10 Parceiros | Pendências manuais identificadas; sincronização real não concluída |
| 11 Painel/portal | Interface interna e portal com escopo |
| 12 Segurança/recuperação | Perfis, escopos, auditoria, filas e reprocessamento; MFA, monitoramento e restauração real pendentes |
| 13 Franquias | Administração de unidades/usuários/vínculos em `/rede`, além de origem operacional e escopos |
| 14 Bloqueio global | Dívida vencida de Plano bloqueia benefícios do tutor em toda a rede; incerteza impede execução |
| 15 Visões | Central e escopo por unidade; relatórios comerciais, origem das contratações, campanhas/indicações e exportação CSV implementados |
| 16 Categorias | Plano/Pacote explícitos e vaga única por pet/categoria |
| 17 Imutabilidade | Snapshot e vínculos protegidos no domínio/banco; permissões reais do banco precisam ser testadas |
| 18 Nova contratação | Solicitação de cancelamento pelo cliente e encerramento autorizado; novo vínculo sem herança; acompanhamento do cancelamento Vindi real pendente |
| 19 Concorrência | Chave única e bloqueios transacionais; carga simultânea em MySQL pendente |

A matriz descreve o código; não representa aceite operacional integral. As condições reais precisam de aprovação e os testes de produção precisam ser executados.

## Verificar

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/bin/phpunit
```

Use PHPUnit diretamente neste Windows: `artisan test` pode iniciar outro processo PHP sem as extensões passadas por `-d`. Os testes usam SQLite em memória e HTTP simulado. A suíte de contratos/integrações verifica PDF privado/idempotente, papel pendente, arquivo inválido, webhook forjado/duplicado e prevalência da resposta atual do provedor. O total atualizado é informado pelo comando.

## Documentação

- [Operação, VPS, backup e restauração](docs/OPERACAO.md)
- [Contratos técnicos das integrações](docs/INTEGRACOES.md)
- [Rotas e serviços internos](docs/ENDPOINTS.md)
- [Leads dos anúncios](docs/LEADS.md)
- [Decisões pendentes](docs/DECISOES_PENDENTES.md)
- [Roteiro de homologação](docs/HOMOLOGACAO.md)
- [Nginx](docs/ops/nginx.conf) e [Supervisor](docs/ops/supervisor.conf)

Produção proposta: Linux, HTTPS, PHP-FPM, MySQL, Redis, workers e agendador. A VPS e os acessos devem ser escolhidos e liberados. Esta entrega não executou deploy, reinicialização de VPS, restauração externa, carga simultânea ou testes com cartões e contas reais.

O servidor local usa por padrão a porta 8765. Para escolher outra, execute `.\start.ps1 -Port 9001`. O script prepara um `php.ini` local quando as extensões SQLite estiverem desativadas, sem modificar a configuração global.

Em uma instalação vazia de produção, após as migrações, use `php artisan plans:bootstrap-admin` para criar o primeiro administrador de forma interativa. Nenhuma senha é passada em argumento. Depois, cadastre as unidades e os acessos na tela Rede e acessos.

## Identidade e origem das contratações

A interface segue o tema claro Mint do BlurAdmin (public/assets/mint.css), com o logotipo e a fonte Poppins do site saofrancisco.vet.br. Os arquivos de imagem e fonte são locais; referências e critérios de contraste estão em docs/IDENTIDADE_VISUAL.md.

- Relatórios: http://127.0.0.1:8765/relatorios — administração central, gestores e financeiro, respeitando as unidades autorizadas. Filtra propostas por período, responsável, franquia, produto, canal, origem, campanha, link e situação; permite exportar CSV com o mesmo escopo.
- Links de divulgação: http://127.0.0.1:8765/links-divulgacao — administração central, gestores e vendas. Cria links reutilizáveis por campanha, indicação, responsável e unidade; permite copiar novamente, definir validade ou desativar sem apagar o histórico.
- O link de divulgação é diferente do convite individual de uma proposta, que continua sendo exclusivo do tutor. A origem atravessa o cadastro/login da mesma sessão e é preservada no aceite.
- Origem declarada pelo tutor, responsável pela proposta, responsável pelo link e canal de contratação são informações separadas. Acessos não são pessoas únicas, pagamentos ou contratos aceitos.
- Registros anteriores que não guardavam origem permanecem como Não informado. Não existe reconstrução automática de indicações e campanhas antigas.

## Comissões

- Comissões: http://127.0.0.1:8765/comissoes — administração central acompanha toda a rede; gestão e financeiro respeitam suas unidades; vendas vê apenas as próprias comissões.
- O registro separa quem criou a proposta, o responsável pelo link, canal, origem, campanha e indicação. Se o tutor contratou sozinho por um link, a atribuição usa o responsável daquele link.
- A administração central configura por produto um percentual sobre o primeiro pagamento ou um valor fixo. A regra fica congelada quando o primeiro pagamento integral é confirmado.
- Contratação direta sem pessoa da equipe identificada mantém a origem, mas não gera pagamento automático. Estorno, cancelamento ou contestação do primeiro pagamento atualizam a comissão com auditoria.

Detalhamento das regras e situações: docs/COMISSOES.md.

Detalhamento dos indicadores, filtros e exportação: docs/RELATORIOS.md. Em outro dispositivo ou após perda da sessão, não se presume o mesmo contato de campanha; o tutor pode informar a origem no cadastro da proposta.
