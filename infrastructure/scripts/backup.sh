#!/bin/sh
# Backup di database e file caricati, tenuti per 14 giorni. Pensato per cron, una volta per notte:
#   0 3 * * * /home/ubuntu/marketing-os/infrastructure/scripts/backup.sh >> /home/ubuntu/backup-marketing-os/backup.log 2>&1
set -eu
cd "$(dirname "$0")/../.."
DEST="${BACKUP_DIR:-$HOME/backup-marketing-os}"
STAMP=$(date +%Y-%m-%d)
mkdir -p "$DEST"

docker compose exec -T db sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --no-tablespaces "$MYSQL_DATABASE"' \
  | gzip > "$DEST/db-$STAMP.sql.gz"
docker compose run --rm --no-deps --user root -v "$DEST:/backup" app \
  tar czf "/backup/uploads-$STAMP.tar.gz" -C /var/www/storage .

find "$DEST" -name '*.gz' -mtime +14 -delete
echo "$(date '+%Y-%m-%d %H:%M') backup completato in $DEST"
