#!/usr/bin/env bash
# Run inside an SSH session on the host running the espoCRM container.
# Replace <CONTAINER> with the container name (e.g. your-espocrm-container).
#
# Assumes the files (PHP class + selectDefs JSON + clientDefs JSON) have
# already been placed at the paths listed in paths.txt.
#
# Usage: bash apply.sh <CONTAINER>

set -euo pipefail
CONTAINER="${1:-your-espocrm-container}"

echo "==> Fixing permissions on custom/"
sudo podman exec "$CONTAINER" chown -R www-data:www-data /var/www/html/custom/

echo "==> Clearing cache"
sudo podman exec "$CONTAINER" php /var/www/html/command.php clear-cache

echo "==> Rebuilding"
sudo podman exec "$CONTAINER" php /var/www/html/command.php rebuild

echo "==> Done. User should Ctrl+Shift+R in browser."
