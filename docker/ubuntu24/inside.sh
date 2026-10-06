#!/usr/bin/env bash
# Runs INSIDE the clean ubuntu:24.04 container; see smoke.sh. Prints a PROVED/FAILED line per step.
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive
FAILED=0
step() { printf '\n== %s\n' "$*"; }
ok()   { printf 'PROVED  %s\n' "$*"; }
bad()  { printf 'FAILED  %s\n' "$*"; FAILED=1; }

step "OS"
. /etc/os-release; echo "$PRETTY_NAME"; [ "$VERSION_ID" = "24.04" ] && ok "running on Ubuntu 24.04" || bad "not Ubuntu 24.04 ($VERSION_ID)"

step "apt packages an installer would pull in"
apt-get update -qq >/dev/null 2>&1 || bad "apt-get update"
apt-get install -y -qq --no-install-recommends ca-certificates curl git unzip php-cli php-mysql php-zip php-xml php-mbstring php-curl php-mysqli \
  composer mariadb-server redis-server poppler-utils openssl >/tmp/apt.log 2>&1 && ok "apt install (php-cli php-mysql php-zip php-xml php-mbstring composer mariadb-server redis-server poppler-utils)" || { tail -20 /tmp/apt.log; bad "apt install"; exit 1; }
php -v | head -1; mariadbd --version 2>/dev/null || mysqld --version; redis-server --version | cut -c1-60

step "composer install from a clean checkout"
mkdir /work && cd /src && tar --exclude=./vendor --exclude=./.git -cf - . | tar -xf - -C /work && cd /work
composer install --no-interaction --no-progress --prefer-dist >/tmp/composer.log 2>&1 && ok "composer install (dev dependencies included)" || { tail -20 /tmp/composer.log; bad "composer install"; exit 1; }
composer install --no-dev --no-interaction --no-progress --prefer-dist --dry-run >/dev/null 2>&1 && ok "composer install --no-dev resolves"
composer validate --no-check-publish >/dev/null 2>&1 && ok "composer validate"
php -r 'require "vendor/autoload.php"; echo count(RivetCore\Migration\CoreMigrations::all()), " migrations in the autoloaded package\n";'

step "MariaDB and Redis"
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld
(mariadbd --user=mysql --skip-networking=0 --bind-address=127.0.0.1 >/tmp/mariadb.log 2>&1 &)
for i in $(seq 1 60); do mariadb -e 'select 1' >/dev/null 2>&1 && break; sleep 1; done
mariadb -e 'select version()' >/dev/null 2>&1 && ok "MariaDB started ($(mariadb -N -e 'select version()'))" || { tail -20 /tmp/mariadb.log; bad "MariaDB did not start"; exit 1; }
PW="$(openssl rand -hex 12)"
mariadb -e "CREATE DATABASE rivetcore_scratch_u24 CHARACTER SET utf8mb4; CREATE DATABASE rivetcore_scratch_u24_up CHARACTER SET utf8mb4; CREATE DATABASE rivetcore_scratch_u24_fresh CHARACTER SET utf8mb4; CREATE USER 'rcu'@'127.0.0.1' IDENTIFIED BY '$PW'; GRANT ALL ON \`rivetcore\_scratch\_u24%\`.* TO 'rcu'@'127.0.0.1'; GRANT SELECT ON mysql.* TO 'rcu'@'127.0.0.1';"
export RIVETCORE_TEST_DB_HOST=127.0.0.1 RIVETCORE_TEST_DB_USER=rcu RIVETCORE_TEST_DB_PASS="$PW" RIVETCORE_TEST_DB_NAME=rivetcore_scratch_u24
(redis-server --port 6391 --bind 127.0.0.1 --save '' --appendonly no >/tmp/redis.log 2>&1 &)
sleep 1; redis-cli -p 6391 ping | grep -q PONG && ok "Redis started" || bad "Redis did not start"
export RIVETCORE_TEST_REDIS_PORT=6391

step "migration runner on an empty database"
out="$(php scripts/migrate-with.php /work rivetcore_scratch_u24)"; echo "$out"
n="$(php -r 'echo count(json_decode($argv[1]));' "$out")"
[ "$n" -ge 12 ] && ok "fresh install applied $n migrations" || bad "fresh install applied $n migrations"
out2="$(php scripts/migrate-with.php /work rivetcore_scratch_u24)"
[ "$out2" = "[]" ] && ok "second run applied nothing (idempotent)" || bad "second run applied $out2"

step "upgrade from old tags (their own migrations first, then this tree's)"
if [ -n "${TAGS:-}" ]; then
  RIVETCORE_TAG_DIR=/tags php scripts/verify-upgrade.php rivetcore_scratch_u24 $TAGS && ok "upgrade from: $TAGS" || bad "upgrade check"
fi

step "public API surface snapshot"
php scripts/api-surface-check.php && ok "API surface matches tests/api-surface.json (tables included)" || bad "API surface check"

if [ "${RUN_TESTS:-1}" = "1" ]; then
  step "PHPUnit (unit, integration, conformance) with poppler, MariaDB and Redis present"
  RIVETCORE_TEST_REDIS_AUTH_PORT_BASE=6397 vendor/bin/phpunit --no-progress > /tmp/phpunit.txt 2>&1
  rc=$?
  grep -E '^[0-9]+\) ' /tmp/phpunit.txt | head -30; tail -4 /tmp/phpunit.txt
  [ "$rc" = "0" ] && ok "PHPUnit passed" || bad "PHPUnit failed"
fi

echo
[ "$FAILED" = "0" ] && echo "ALL STEPS PROVED on a clean Ubuntu 24.04 container" || echo "SOME STEPS FAILED"
exit "$FAILED"
