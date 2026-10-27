#!/usr/bin/env bash
# Starts the local demo: WordPress + WooCommerce + this plugin + the invented PawPantry shop.
# Development helper only; not part of the plugin package.
#
# Indexing and answering need an OpenAI key in the environment of the cli and
# wordpress services, e.g.:
#   AIFAQ_OPENAI_API_KEY=... docker compose -p aifaq-demo up -d wordpress
#   AIFAQ_OPENAI_API_KEY=... docker compose -p aifaq-demo run --rm cli wp aifaq index
set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${AIFAQ_PORT:-8092}"
compose() { docker compose -p aifaq-demo "$@"; }
wp() { compose run --rm -T cli wp "$@"; }

compose up -d db wordpress

echo "Waiting for WordPress files..."
for _ in $(seq 1 60); do
	if compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then
		break
	fi
	sleep 2
done

if ! wp core is-installed 2>/dev/null; then
	wp core install \
		--url="http://localhost:${PORT}" \
		--title="PawPantry" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email
fi

wp plugin install woocommerce --activate
wp plugin activate ai-faq-chatbot
wp rewrite structure '/%postname%/'
wp eval-file wp-content/plugins/ai-faq-chatbot/bin/demo-seed.php

echo
echo "Ready: http://localhost:${PORT}/  and  http://localhost:${PORT}/wp-admin  (admin / admin)"
