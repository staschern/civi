#!/usr/bin/env bash
#
# Прогон миграции и тестов на живой базе.
#
#   MYSQL_ARGS="--socket=/tmp/civi.sock" tools/run-db-tests.sh
#   MYSQL_ARGS="-h 127.0.0.1 -u root -proot" tools/run-db-tests.sh
#
# Переменные:
#   MYSQL      клиент, по умолчанию mysql (для MariaDB — mariadb)
#   MYSQL_ARGS флаги подключения
#   DB         имя тестовой базы, по умолчанию civi_test (ПЕРЕСОЗДАЁТСЯ)
#
# Что проверяется:
#   1. миграция применяется на чистой базе;
#   2. сквозной сценарий db/tests/e2e.sql проходит целиком;
#   3. каждый файл db/tests/constraints/*.sql падает — то есть БД
#      действительно не даёт записать некорректные данные.

set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MYSQL="${MYSQL:-mysql}"
MYSQL_ARGS="${MYSQL_ARGS:-}"
DB="${DB:-civi_test}"

MIGRATION="$ROOT/db/migrations/0001_create_tech_tree_versions.sql"
E2E="$ROOT/db/tests/e2e.sql"
CONSTRAINTS_DIR="$ROOT/db/tests/constraints"

# shellcheck disable=SC2086
run_sql() { "$MYSQL" $MYSQL_ARGS --default-character-set=utf8mb4 "$@"; }

pass=0
fail=0

ok()   { echo "  ok    $1"; pass=$((pass + 1)); }
bad()  { echo "  ПРОВАЛ $1"; fail=$((fail + 1)); }

echo "База $DB пересоздаётся"
run_sql -e "DROP DATABASE IF EXISTS \`$DB\`;
            CREATE DATABASE \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || {
  echo "не удалось подключиться к серверу"; exit 2; }

echo
echo "1. Миграция"
if run_sql "$DB" < "$MIGRATION" 2>/tmp/civi-migration.err; then
  counts=$(run_sql "$DB" -N -B -e "SELECT CONCAT(
      (SELECT COUNT(*) FROM eras), ' эпох, ',
      (SELECT COUNT(*) FROM branches), ' веток, ',
      (SELECT COUNT(*) FROM technologies), ' технологий, ',
      (SELECT COUNT(*) FROM technology_prereqs), ' связей каталога');")
  ok "применилась: $counts"
else
  bad "не применилась: $(head -1 /tmp/civi-migration.err)"
  exit 1
fi

echo
echo "2. Сквозной сценарий"
if run_sql "$DB" < "$E2E" >/tmp/civi-e2e.out 2>/tmp/civi-e2e.err; then
  ok "генерация, ручная правка, загрузка, поиск по семени, каскадное удаление"
else
  bad "$(grep -i error /tmp/civi-e2e.err | head -1)"
fi

echo
echo "3. Ограничения целостности (каждый случай должен быть отбит)"
for f in "$CONSTRAINTS_DIR"/*.sql; do
  name=$(basename "$f" .sql)
  err=$(run_sql "$DB" < "$f" 2>&1)
  if [ $? -eq 0 ]; then
    bad "$name — БД пропустила некорректные данные"
  else
    # MySQL пишет имя ключа как 'таблица.ключ', MariaDB — просто 'ключ'
    reason=$(echo "$err" | grep -io "CONSTRAINT \`[a-z_]*\`\|constraint '[a-z_]*'\|key '[a-z_.]*'" | head -1)
    ok "$name ${reason:+→ $reason}"
  fi
done

echo
echo "Итог: успешно $pass, провалено $fail"
[ "$fail" -eq 0 ]
