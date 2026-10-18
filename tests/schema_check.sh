#!/usr/bin/env bash
# Prüft, dass db.sql (Neuinstallation per Import) und includes/migrations.php (Migrationen) dasselbe
# Schema erzeugen. Braucht einen MySQL/MariaDB-Zugang, der Datenbanken anlegen darf:
#
#   MYSQL="mysql -uroot" tests/schema_check.sh
#
# Läuft auch in der CI (siehe .github/workflows/ci.yml).
set -euo pipefail
cd "$(dirname "$0")/.."

MYSQL="${MYSQL:-mysql -uroot}"
A=vt_schema_migrated
B=vt_schema_import

for db in "$A" "$B"; do
    $MYSQL -e "DROP DATABASE IF EXISTS $db; CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
done

VERTRAEGE_DB_NAME=$A php migrate.php >/dev/null
$MYSQL --default-character-set=utf8mb4 "$B" < db.sql

dump() {
    $MYSQL -N -e "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE
                    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$1' ORDER BY 1,3;
                  SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE
                    FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$1' ORDER BY 1,2,3;
                  SELECT version, description FROM $1.schema_migrations ORDER BY 1;
                  SELECT name, color_hex, icon, sort_order FROM $1.categories ORDER BY id;"
}

if diff <(dump "$A") <(dump "$B"); then
    echo "OK: db.sql und Migrationen erzeugen dasselbe Schema."
else
    echo "FEHLER: db.sql und Migrationen weichen voneinander ab (siehe Diff oben)." >&2
    exit 1
fi

# Erneuter Import darf nichts verdoppeln
$MYSQL --default-character-set=utf8mb4 "$B" < db.sql
[ "$($MYSQL -N -e "SELECT COUNT(*) FROM $B.categories")" = "8" ] || { echo "FEHLER: Re-Import verdoppelt Kategorien." >&2; exit 1; }
echo "OK: Re-Import ist idempotent."

for db in "$A" "$B"; do $MYSQL -e "DROP DATABASE $db;"; done
