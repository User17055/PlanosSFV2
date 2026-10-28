# Site S?o Francisco ? PHP 8.5

Site institucional e painel migrados para PHP 8.5, Symfony 7.4 LTS, Twig 3, Doctrine DBAL 4, Glide 3 e PHPMailer 7.

## Estrutura

### Contratação e área do cliente

As telas de contratação e `/area-do-cliente` agora ficam neste próprio projeto. Os dados oficiais permanecem na gestão em `https://sf.lisontech.com.br`, acessada pela API HTTPS `/api/site/v1`. Não é necessário copiar arquivos ou conectar diretamente ao banco Laravel.

Copie `../configuracao_exemplo/portal.php.example` para `../configuracao_privada/portal.php`, fora da raiz pública, e configure a chave privada compartilhada com `SITE_API_KEY` da gestão. Consulte `../../docs/PORTAL_INSTITUCIONAL.md` no workspace de desenvolvimento para os detalhes de publicação, Vindi e execução local.

As telas novas usam recursos locais, CSRF e uma sessão própria do cliente, sem alterar o acesso administrativo existente em `/luzsisadm`. Contratos PDF são servidos mediante autorização pela API e não são armazenados em pasta pública.

- `public_html/`: raiz p?blica, recursos, c?digo e depend?ncias Composer. Apache bloqueia acesso HTTP direto a c?digo/configura??o/dados internos.
- `configuracao_privada/`: pasta irm? de `public_html`, com configura??es de aplicativo, banco e e-mail.
- `var_runtime/`: pasta irm?, grav?vel pelo processo PHP, para cache, logs, sess?es e imagens processadas.
- `migracao_php_backup_2026-10-26/`: vers?o anterior; manter privada e n?o enviar como conte?do p?blico.
- `ferramentas_locais/php85/`: runtime port?til usado nos testes Windows; n?o ? necess?rio envi?-lo ? hospedagem.

O DocumentRoot da hospedagem deve continuar sendo `public_html`. Manter os nomes e a posi??o das pastas irm?s ou adaptar explicitamente os caminhos em `Kernel.php` e `DatabaseFactory.php`.

## Instala??o na hospedagem

1. Selecionar **PHP 8.5 com os patches de seguran?a mais recentes** no painel da hospedagem. A c?pia foi validada com PHP 8.5.12.
2. Habilitar `ctype`, `curl`, `dom`, `fileinfo`, `gd`, `iconv`, `intl`, `mbstring`, `PDO`, `pdo_mysql`, `SimpleXML`, `xml`, `session` e `tokenizer`. SQLite ? necess?rio somente para os testes locais.
3. Preservar os arquivos de `upload/` e o banco MySQL. Esta migra??o n?o altera tabelas nem apaga registros do banco.
4. Instalar as depend?ncias com o PHP correto: `composer install --no-dev --prefer-dist --optimize-autoloader` dentro de `public_html`.
5. Colocar `configuracao_privada/` fora da raiz p?blica. As configura??es existentes foram preservadas ali; n?o copiar credenciais de exemplo por cima delas.
6. Permitir grava??o em `var_runtime/` e nos diret?rios de uploads. O usu?rio PHP deve ser propriet?rio ou ter permiss?o apropriada; n?o aplicar 777 indiscriminadamente.
7. Executar `php console cache:clear --env=prod` e conferir as p?ginas e o painel em homologa??o antes de publicar.

Usar `APP_ENV=prod` e `APP_DEBUG=0`. O debug ? desligado por padr?o.

O c?digo n?o troca a vers?o PHP do servidor automaticamente. Essa sele??o depende do painel/administrador da hospedagem. Backups e pastas de ferramentas/testes devem ficar fora de `public_html`.

## Banco e segredos

A configura??o privada preserva o acesso anterior. Como alternativa, definir `DATABASE_URL` no ambiente com um DSN MySQL, codificando caracteres especiais da senha na URL. `APP_SECRET` deve ter ao menos 32 caracteres aleat?rios e permanecer est?vel entre requisi??es.

As senhas existentes no formato SHA-512 do Silex s?o verificadas e migradas para hash atual ap?s login bem-sucedido. N?o ? necess?rio redefinir todas as senhas. Sess?es antigas precisam de novo login. Mudan?as de senha, pap?is e desativa??o de contas s?o verificadas pelo novo sistema.

## Painel e comandos

O painel continua em `/luzsisadm/login`, com as URLs de cadastro e edi??o preservadas.

- `php console debug:router`: lista as rotas.
- `php console lint:twig views`: valida templates.
- `php console lint:container`: valida depend?ncias dos servi?os.
- `php console user:new usuario email@exemplo.com "Nome completo"`: cria um usu?rio; a senha ? solicitada de forma oculta e o papel deve existir no banco.

Os geradores antigos de CRUD, servidor local e comandos de esquema dependiam de Silex/Symfony 2 e foram arquivados no backup, sem executar altera??es no banco. O servidor local usa o comando PHP padr?o abaixo; a listagem de rotas usa `debug:router`.

## Executar localmente no Windows

Na raiz do workspace:

```powershell
& ./ferramentas_locais/php85/runtime/php.exe -S 127.0.0.1:8080 -t ./public_html ./public_html/index.php
```

Para os comandos do painel:

```powershell
& ../ferramentas_locais/php85/runtime/php.exe console about
```

Executar o segundo comando dentro de `public_html`.

## Testes

Na raiz do workspace:

```powershell
& ./ferramentas_locais/php85/runtime/php.exe ./public_html/vendor/phpunit/phpunit/phpunit -c ./auditoria_site/testes_migracao/phpunit.xml
```

Os testes usam SQLite em mem?ria e dados fict?cios; n?o acessam o MySQL da hospedagem nem enviam e-mails. Cobrem p?ginas p?blicas, autentica??o, atualiza??o de senhas antigas, permiss?es, formul?rios, CRUD de pap?is/usu?rios, ordena??o e processamento de imagens.

## Revista, contato e m?dulos retirados

- `/revista` entrega o PDF existente e registra acessos em `var_runtime/revista_clicks.csv`, fora da ?rea p?blica.
- `/revista-analytics.php` exige `REVISTA_DASHBOARD_KEY`; sem configura??o, o acesso ? bloqueado. Definida a vari?vel, a chave ? informada por `?key=...` no relat?rio legado.
- Os formul?rios de contato/franquia continuam enviando para a API externa configurada nos scripts existentes. O endpoint local PHPMailer foi atualizado, mas o envio SMTP real n?o foi disparado nos testes.
- `/contato-send`, anteriormente sem implementa??o, retorna 410 com orienta??o de usar o formul?rio ou WhatsApp. Essa rota n?o ? usada pelos formul?rios atuais.
- `/whatsapp` agora tem uma rota funcional de redirecionamento.
- `magazine/` e `MercadoLivre/` permanecem fora da vers?o publicada por decis?o do usu?rio. C?pias est?o no backup privado.

## Refer?ncias

- PHP suportado: https://www.php.net/supported-versions.php
- Downloads oficiais: https://www.php.net/downloads.php
- Symfony 7.4 LTS: https://symfony.com/releases/7.4
