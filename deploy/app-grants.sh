#!/usr/bin/env bash
# Permissões do usuário da aplicação (DB_USERNAME do .env). A credencial de migração (dona do banco) fica só
# para `php artisan migrate`. Rodar como root depois de cada migrate: o MySQL não tem "tudo menos uma tabela",
# então tabela nova precisa de grant próprio. Idempotente.
#   - tabelas comuns: SELECT, INSERT, UPDATE, DELETE;
#   - audit_logs: só SELECT e INSERT (os triggers já recusam alterar e excluir; aqui o banco também recusa
#     pela permissão, mesmo se um trigger for removido).
# Sem CREATE, ALTER, DROP, TRIGGER nem LOCK TABLES: a aplicação não muda a estrutura em execução.
# Não revoga nada: se uma tabela passar a ser só inserção, revogue UPDATE/DELETE dela à mão.
set -euo pipefail
DB=${PLANOS_DB:-saofrancisco}
APP_USER=${PLANOS_APP_USER:-sf_application}
APP_HOST=${PLANOS_APP_HOST:-localhost}
MYSQL=${MYSQL:-mysql --no-defaults -uroot}
APPEND_ONLY=" audit_logs "

# $MYSQL sem aspas de propósito: é o comando com as opções. tr: o cliente mysql do Windows termina as
# linhas com CRLF.
# shellcheck disable=SC2086
run() { $MYSQL -N -e "$1" | tr -d '\r'; }

if [ -z "$(run "SELECT 1 FROM mysql.user WHERE user = '$APP_USER' AND host = '$APP_HOST'")" ]; then
  echo "app-grants: usuário '$APP_USER'@'$APP_HOST' não existe; nada a fazer." >&2
  exit 0
fi

SQL=""
for t in $(run "SELECT table_name FROM information_schema.tables WHERE table_schema = '$DB' AND table_type = 'BASE TABLE' ORDER BY table_name"); do
  case "$APPEND_ONLY" in
    *" $t "*) SQL+="GRANT SELECT, INSERT ON \`$DB\`.\`$t\` TO '$APP_USER'@'$APP_HOST';" ;;
    *) SQL+="GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB\`.\`$t\` TO '$APP_USER'@'$APP_HOST';" ;;
  esac
done
if [ -z "$SQL" ]; then
  echo "app-grants: o banco $DB não tem tabelas; rode as migrations antes." >&2
  exit 1
fi
run "$SQL"
echo "app-grants: permissões de '$APP_USER'@'$APP_HOST' em $DB atualizadas."
