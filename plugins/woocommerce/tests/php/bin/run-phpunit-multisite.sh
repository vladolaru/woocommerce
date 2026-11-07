#!/bin/sh
#
# Run every `@group multisite` PHPUnit class under a real multisite install.
#
# Each matching file is its own PHPUnit process. WordPress's own
# `wp_delete_site()` drops a temporary blog's tables with a DDL statement,
# which forces an implicit MySQL commit; the row removal that follows runs
# in that fresh autocommit context, but the *next* test's `wpmu_delete_blog()`
# for a *different* class can still leave a stale, cross-test blog id behind
# once several blog-creating classes share one long-lived PHPUnit worker
# process. One process per class removes that cross-class dependency instead
# of relying on file discovery order to avoid it.

set -eu

wp db query "SET GLOBAL innodb_flush_log_at_trx_commit=2" >/dev/null

files=$(find tests/php -name '*.php' -print0 | xargs -0 grep -l '@group multisite' | sort)

if [ -z "$files" ]; then
	echo "No @group multisite tests found."
	exit 0
fi

echo "Multisite classes to run:"
echo "$files" | sed 's/^/  - /'

status=0
for file in $files; do
	echo ""
	echo "== $file =="
	if ! php -d opcache.enable_cli=1 vendor/bin/phpunit -c tests/php/multisite.xml --bootstrap tests/legacy/bootstrap.php --group multisite "$file" --verbose; then
		status=1
	fi
done

exit $status
