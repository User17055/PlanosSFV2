# Implantação e operação propostas

Não houve implantação real nesta entrega. Adapte caminhos, versões PHP, domínio, responsáveis e capacidade à VPS contratada. Base técnica: [implantação Laravel 13](https://laravel.com/framework/docs/13.x/deployment). O banco de produção proposto é MySQL; a execução local usa SQLite.

## Preparar a VPS

- Linux mantido, acesso SSH por chave e usuário de deploy sem privilégios administrativos permanentes.
- Nginx, PHP-FPM compatível com `composer.lock`, MySQL e Redis. HTTPS com renovação monitorada.
- PHP CLI e FPM com as mesmas extensões necessárias; usar `pdo_mysql` em produção. ClamAV operacional para anexos.
- Usuários separados para aplicação e migração. O usuário da aplicação não pode remover triggers ou alterar estrutura.
- Firewall e serviços internos sem exposição pública. Não publicar MySQL, Redis, ClamAV ou Supervisor.
- Inventário de serviços, contatos, ambientes e responsáveis em cofre seguro; sem senhas na documentação.

Estrutura sugerida:

```text
/var/www/saofrancisco/releases/<release>/   código imutável
/var/www/saofrancisco/current              symlink do release ativo
/var/www/saofrancisco/shared/.env          segredos privados
/var/www/saofrancisco/shared/storage/      contratos e estado persistente
```

O web root será exclusivamente `current/public`. Copie e adapte [nginx.conf](ops/nginx.conf). Nunca publique a raiz do projeto. `storage/app/private/contracts`, `.env`, logs e backups ficam fora de `public`; não execute `storage:link` para documentos contratuais.

Permissões devem permitir escrita de PHP apenas em `storage` e `bootstrap/cache`. `.env` e chaves ficam legíveis somente pelas contas necessárias. A `APP_KEY` deve ser preservada entre releases e protegida também fora da VPS.

## Configuração de produção

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_DEMO=false
APP_URL=https://planos.seudominio.com.br
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=saofrancisco
DB_USERNAME=sf_application
DB_PASSWORD=INJETAR_PELO_COFRE
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
CONTRACT_REQUIRE_ANTIVIRUS=true
CONTRACT_CLAMD_SOCKET=tcp://127.0.0.1:3310
VINDI_ENABLED=false
VINDI_ALLOW_CREATION=false
```

Os valores ilustrativos não devem ser usados como segredos. Configurar e testar SMTP para confirmação do contato. Ativar a política comercial somente após aprovação. Não carregar os usuários/planos demonstrativos em produção. MFA e recuperação segura da equipe precisam ser concluídos antes da entrada em operação.

## Deploy revisável

1. Executar testes do release, revisar migrações e confirmar compatibilidade com a versão anterior. Testar triggers no MySQL exato da VPS.
2. Confirmar backup externo recente de banco **e** documentos e capacidade de restaurar. Registrar release anterior e novo.
3. Preparar novo diretório de release, instalar `composer install --no-dev --prefer-dist --optimize-autoloader`, associar `.env` e `storage` compartilhados. Não executar `key:generate` em cada deploy.
4. Usar credencial de migração para `php artisan migrate --force`. Mudanças devem adicionar estruturas antes de retirar as antigas. Não apagar dados de contratos/pagamentos em deploy comum.
5. Executar `php artisan optimize`, validar configuração e trocar o symlink de forma atômica. Reiniciar/recarregar PHP-FPM e `php artisan queue:restart` para workers usarem o código novo.
6. Conferir saúde HTTP, login, escopos, contrato privado, fila e agendador. Confirmar que `.env`, SQLite, logs e anexos não são acessíveis pela URL.
7. Monitorar erros e conciliação. Se necessário, retornar o symlink ao release anterior e reiniciar workers. Não executar rollback destrutivo do banco: pagamentos podem ter chegado após a implantação.

## Workers, agendador e reinício

Adapte [supervisor.conf](ops/supervisor.conf). Separar Vindi e parceiros permite que a indisponibilidade de um destino não paralise os demais. O `timeout` deve ser menor que `retry_after` da conexão de fila; para os exemplos, configure `retry_after` maior que 150 segundos.

Cron do usuário de aplicação:

```cron
* * * * * cd /var/www/saofrancisco/current && /usr/bin/php artisan schedule:run >> /var/log/saofrancisco-scheduler.log 2>&1
```

Instalar monitoramento do cron e dos workers. Após reiniciar a VPS, verificar Nginx, FPM, banco, Redis, Supervisor e processamento sem usuário logado. Essa prova de reinício não foi executada nesta entrega.

Monitorar: falha de login, geração de PDF, espaço em disco, backup, certificado HTTPS, filas paradas, jobs falhos, eventos em `manual/error`, última conciliação financeira e divergências. Logs não devem conter documento do titular, cartão, senha, token, URL com segredo ou corpo integral dos webhooks.

## Backup proposto

Proposta da especificação: backup diário criptografado de banco e documentos, externo à VPS, com retenção inicial de 30 dias. Um backup diário pode perder até 24 horas de alterações; aprovar RPO/RTO e considerar binlog/PITR no MySQL e versionamento frequente dos arquivos.

O conjunto mínimo inclui:

- Dump consistente do banco com triggers, esquema e dados, mais binlogs quando a política exigir PITR.
- `storage/app/private/contracts` e demais arquivos persistentes necessários.
- `APP_KEY` e chaves de recuperação protegidas separadamente, além de configuração/inventário sem exposição de segredos.
- Identificação do release e `composer.lock` compatível com o ponto de recuperação.

Exemplo do componente de banco, executado por uma conta de backup restrita; o arquivo de credenciais deve ter permissão privada:

```bash
mysqldump --defaults-extra-file=/etc/saofrancisco/backup.cnf --single-transaction --routines --triggers --events saofrancisco > /var/backups/saofrancisco/database.sql
```

Esse dump intermediário contém dados pessoais: diretório com acesso restrito, vida curta e envio a repositório externo criptografado, como Restic com credenciais mantidas em cofre. Programar retenção e alertar falhas. Snapshot na mesma VPS não é backup suficiente. Não deixar dumps dentro do release ou do web root.

## Restauração em ambiente isolado

1. Abrir incidente, identificar ponto consistente e responsável. Preservar evidências do ambiente afetado.
2. Subir ambiente separado com integrações, SMTP, workers e cron desabilitados; `APP_DEMO=false`, `VINDI_ENABLED=false`, sem acesso de clientes.
3. Restaurar esquema/dados/triggers e os documentos do mesmo ponto. Recuperar a `APP_KEY` correta. Aplicar PITR somente segundo procedimento já testado.
4. Verificar chaves estrangeiras e únicas, contratos congelados, ocupação pet/categoria, estados terminais, hashes de amostra dos PDFs, faturas/pagamentos e lançamentos de benefício.
5. Executar testes com integrações simuladas. Reprocessar eventos pendentes idempotentemente; não criar cobranças, saldos ou ativações em duplicidade. Conciliar diferenças de pagamentos recebidos após o backup.
6. Registrar perda efetiva, duração e evidências. Autorizar retorno da produção, habilitar serviços gradualmente e monitorar. Não declarar recuperação concluída só porque o banco iniciou.

Definir titular e substituto para administração da VPS, segredos, monitoramento, financeiro e execução de restauração. Contatos e localização segura dos backups permanecem pendentes do contratante.
