#!/bin/bash
# Hot backup for the Magento server. The shop stays online.
# InnoDB is read with a transaction snapshot, and media is copied with rsync.
# Do not use bin/magento setup:backup. That command turns maintenance mode on.
#
# Install on the Debian server:
#   chmod +x /var/www/magento/scripts/hot-backup.sh
#   crontab -e
#   15 4 * * * /var/www/magento/scripts/hot-backup.sh >> /var/log/magento-backup.log 2>&1
#
# Optional: BACKUP_DIR=/mnt/offsite/magento /var/www/magento/scripts/hot-backup.sh

set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"
DEST="${BACKUP_DIR:-/var/backups/magento}/${STAMP}"
mkdir -p "$DEST"

eval "$(php -r '
$env = include $argv[1];
$db = $env["db"]["connection"]["default"];
$emit = static function (string $name, string $value): void {
    echo $name . "=" . escapeshellarg($value) . "\n";
};
$emit("DB_HOST", (string) ($db["host"] ?? "localhost"));
$emit("DB_USER", (string) ($db["username"] ?? ""));
$emit("DB_PASS", (string) ($db["password"] ?? ""));
$emit("DB_NAME", (string) ($db["dbname"] ?? ""));
' "$ROOT/app/etc/env.php")"

CNF="$(mktemp)"
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT
cat > "$CNF" <<EOF
[client]
host=${DB_HOST}
user=${DB_USER}
password=${DB_PASS}
EOF

mysqldump --defaults-extra-file="$CNF" --single-transaction --quick --no-tablespaces "$DB_NAME" | gzip > "$DEST/db.sql.gz"
mkdir -p "$DEST/media"
rsync -a "$ROOT/pub/media/" "$DEST/media/"

find "$(dirname "$DEST")" -mindepth 1 -maxdepth 1 -type d -mtime +14 -exec rm -rf {} +
echo "Online backup written to $DEST"
