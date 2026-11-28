#!/bin/bash
# Start-up wrapper for the Spotweb container.
#
# The theme files live in the app data dir (mounted read-only at
# /biereco-hooks). They are copied into the Spotweb docroot instead of being
# bind-mounted there, because the upstream entrypoint chowns the whole docroot
# and read-only mounts make that fail with "Read-only file system" noise.
#
# Order matters: copy first, then seed, so seed.php can read the file mtime
# for the cache-busting ?v= in the custom CSS import.
set -u

cp -p /biereco-hooks/theme.css /var/www/spotweb/biereco-theme.css
cp -p /biereco-hooks/mobile.css /var/www/spotweb/biereco-mobile.css
cp -p /biereco-hooks/mobile-header.inc.php /var/www/spotweb/templates/mobile/header.inc.php
cp -p /biereco-hooks/mobile-basic-header.inc.php /var/www/spotweb/templates/mobile/includes/basic-html-header.inc.php

php /biereco-hooks/seed.php

exec /entrypoint.sh
