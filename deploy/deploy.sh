#!/usr/bin/env bash
# Implantação da gestão por releases (docs/OPERACAO.md, "Deploy revisável"). Executar como root:
#   bash deploy/deploy.sh [ref]            (ref padrão: origin/main)
# Estrutura em $BASE: repo.git (clone --mirror, feito uma vez), releases/<data>-<commit>, shared/.env,
# shared/storage e o link current. O release novo só vira o current depois de instalado e migrado.
# Migrations devem ser compatíveis com o release anterior: o rollback volta só o código.
set -euo pipefail

BASE=${PLANOS_BASE:-/var/www/saofrancisco}
REF=${1:-origin/main}
DEPLOY_USER=${PLANOS_DEPLOY_USER:-deploy}
WEB_USER=${PLANOS_WEB_USER:-www-data}
PHP=${PLANOS_PHP:-/usr/bin/php8.4}
FPM_SERVICE=${PLANOS_FPM_SERVICE:-php8.4-fpm}
# Credencial de migração (dona do banco): arquivo só do root com DB_USERNAME/DB_PASSWORD.
MIGRATOR_ENV=${PLANOS_MIGRATOR_ENV:-/root/.saofrancisco-migrator.env}
BACKUP_CNF=${PLANOS_BACKUP_CNF:-/etc/saofrancisco/backup.cnf}
BACKUP_DIR=${PLANOS_BACKUP_DIR:-/var/backups/saofrancisco}
DB=${PLANOS_DB:-saofrancisco}
KEEP=${PLANOS_KEEP_RELEASES:-5}

as_deploy() { sudo -u "$DEPLOY_USER" -H "$@"; }

for f in "$BASE/repo.git" "$BASE/shared/.env" "$BASE/shared/storage" "$MIGRATOR_ENV" "$BACKUP_CNF"; do
  [ -e "$f" ] || { echo "deploy: falta $f (ver docs/OPERACAO.md)." >&2; exit 1; }
done

echo "==> Código ($REF)"
as_deploy git --git-dir="$BASE/repo.git" fetch --quiet --prune origin
COMMIT=$(as_deploy git --git-dir="$BASE/repo.git" rev-parse --verify "$REF^{commit}")
RELEASE="$BASE/releases/$(date +%Y%m%d-%H%M%S)-${COMMIT:0:8}"
PREVIOUS=$(readlink -f "$BASE/current" 2>/dev/null || true)
as_deploy mkdir -p "$RELEASE"
as_deploy git --git-dir="$BASE/repo.git" archive "$COMMIT" | as_deploy tar -x -C "$RELEASE"
as_deploy rm -rf "$RELEASE/storage" "$RELEASE/institucional"
as_deploy ln -s "$BASE/shared/.env" "$RELEASE/.env"
as_deploy ln -s "$BASE/shared/storage" "$RELEASE/storage"
cd "$RELEASE"

echo "==> Dependências de produção"
as_deploy composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --quiet
as_deploy npm ci --no-audit --no-fund --silent
as_deploy npm run build --silent
as_deploy rm -rf node_modules

echo "==> Backup do banco antes da migração"
(umask 077; mkdir -p "$BACKUP_DIR"
 mysqldump --defaults-extra-file="$BACKUP_CNF" --single-transaction --routines --triggers --events "$DB" | gzip > "$BACKUP_DIR/pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz")

echo "==> Migrations com a credencial de migração"
# Sem cache de config, as variáveis do ambiente valem mais que o .env: só a migration usa o dono do banco.
# shellcheck source=/dev/null
(set -a; . "$MIGRATOR_ENV"; set +a; "$PHP" artisan migrate --force)
MYSQL="mysql --no-defaults -uroot" PLANOS_DB="$DB" bash deploy/app-grants.sh

echo "==> Caches do release"
chown -R "$WEB_USER:$DEPLOY_USER" bootstrap/cache
sudo -u "$WEB_USER" "$PHP" artisan optimize

echo "==> Troca atômica do current"
ln -sfn "$RELEASE" "$BASE/current.tmp"
mv -Tf "$BASE/current.tmp" "$BASE/current"
systemctl reload "$FPM_SERVICE"
sudo -u "$WEB_USER" "$PHP" "$BASE/current/artisan" queue:restart

echo "==> Verificação"
URL=$(grep '^APP_URL=' "$BASE/shared/.env" | cut -d= -f2-)
if ! curl -fsS -o /dev/null -w "HTTP %{http_code}\n" "$URL/up"; then
  echo "deploy: $URL/up falhou. Para voltar o código: bash deploy/rollback.sh ${PREVIOUS:+$(basename "$PREVIOUS")}" >&2
  exit 1
fi

echo "==> Releases antigos (mantém $KEEP)"
find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort | head -n -"$KEEP" | while read -r old; do
  [ "$BASE/releases/$old" = "$(readlink -f "$BASE/current")" ] || rm -rf "${BASE:?}/releases/$old"
done
echo "deploy: current = $(basename "$RELEASE") (anterior: ${PREVIOUS:+$(basename "$PREVIOUS")})"
