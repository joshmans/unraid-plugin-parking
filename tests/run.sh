#!/bin/sh
# Runs every *_test.php (needs only php-cli) and the un-get reminder's shell test (needs bash).
cd "$(dirname "$0")" || exit 1
rc=0
for t in *_test.php; do
  php "$t" || rc=1
done
bash ./unget_reminder_test.sh || rc=1
exit $rc
