#!/usr/bin/env bash
# Volta o CÓDIGO para um release anterior trocando o link current. Executar como root:
#   bash deploy/rollback.sh [release]      (padrão: o release anterior ao current)
# Não desfaz migrations nem apaga pagamentos recebidos depois do deploy (docs/OPERACAO.md): reversão de
# banco exige procedimento próprio (restaurar backup em homologação, conferir e só então aplicar).
set -euo pipefail

BASE=${PLANOS_BASE:-/var/www/saofrancisco}
WEB_USER=${PLANOS_WEB_USER:-www-data}
PHP=${PLANOS_PHP:-/usr/bin/php8.4}
FPM_SERVICE=${PLANOS_FPM_SERVICE:-php8.4-fpm}

CURRENT=$(basename "$(readlink -f "$BASE/current")")
mapfile -t RELEASES < <(find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort)
TARGET=""
if [ -n "${1:-}" ]; then
  TARGET=$(basename "$1")
else
  for r in "${RELEASES[@]}"; do
    [ "$r" = "$CURRENT" ] && break
    TARGET=$r
  done
fi
if [ -z "$TARGET" ] || [ "$TARGET" = "$CURRENT" ] || [ ! -d "$BASE/releases/$TARGET" ]; then
  echo "rollback: não há release anterior a $CURRENT para voltar (releases: ${RELEASES[*]})." >&2
  exit 1
fi

ln -sfn "$BASE/releases/$TARGET" "$BASE/current.tmp"
mv -Tf "$BASE/current.tmp" "$BASE/current"
systemctl reload "$FPM_SERVICE"
sudo -u "$WEB_USER" "$PHP" "$BASE/current/artisan" queue:restart
echo "rollback: current = $TARGET (era $CURRENT). As migrations do release $CURRENT continuam aplicadas."
