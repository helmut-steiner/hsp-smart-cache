#!/usr/bin/env bash

set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}

TMPDIR=${TMPDIR:-/tmp}
WP_TESTS_DIR=${WP_TESTS_DIR:-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-$TMPDIR/wordpress/}

mkdir -p "$WP_TESTS_DIR" "$WP_CORE_DIR"

if [ ! -d "$WP_CORE_DIR" ] || [ ! -f "$WP_CORE_DIR/wp-load.php" ]; then
  curl -L https://wordpress.org/${WP_VERSION}.tar.gz | tar -xz -C "$TMPDIR"
fi

if [ ! -d "$WP_TESTS_DIR/includes" ]; then
  svn checkout --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
  svn checkout --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR/data"
fi

cp "$WP_TESTS_DIR/includes/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"

sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"

mysqladmin -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" create "$DB_NAME" || true
