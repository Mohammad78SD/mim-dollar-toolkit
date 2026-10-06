#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

export WP_PORT="${WP_PORT:-8080}"
URL="http://localhost:$WP_PORT"
PLUGIN_DIR="../woo-toman-toolkit"
PLUGIN_SLUG="woo-toman-toolkit"
wp() { docker compose --progress quiet run --rm -T wpcli "$@"; }
opt() { wp option update "$@" >/dev/null 2>&1 || true; }  # update errors when value unchanged

echo "==> Starting containers"
docker compose --progress quiet up -d db wordpress

echo "==> Waiting for WordPress files (wp-config.php)"
for i in $(seq 1 90); do
  if docker compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then break; fi
  sleep 2
done
echo "==> Waiting for database"
for i in $(seq 1 60); do
  if wp db check >/dev/null 2>&1; then break; fi
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  echo "==> Installing WordPress"
  wp core install --url="$URL" --title="Toman Toolkit Dev" --admin_user=admin \
    --admin_password=admin --admin_email=admin@example.com --skip-email
else
  echo "==> WordPress already installed"
fi

echo "==> WooCommerce"
if ! wp plugin is-installed woocommerce >/dev/null 2>&1; then
  wp plugin install woocommerce
fi
wp plugin is-active woocommerce >/dev/null 2>&1 || wp plugin activate woocommerce

echo "==> Currency"
if wp eval 'echo isset(get_woocommerce_currencies()["IRT"]) ? "WTT_YES" : "WTT_NO";' 2>/dev/null | grep -q WTT_YES; then
  opt woocommerce_currency IRT
  CURRENCY=IRT
else
  echo "NOTE: IRT not valid in this WooCommerce version; falling back to IRR"
  opt woocommerce_currency IRR
  CURRENCY=IRR
fi

echo "==> Skipping Woo onboarding, setting permalinks"
opt woocommerce_onboarding_profile '{"skipped":true}' --format=json
opt woocommerce_task_list_hidden yes
opt woocommerce_task_list_complete yes
opt woocommerce_show_marketplace_suggestions no
opt woocommerce_coming_soon no
opt woocommerce_store_pages_only no
wp option update woocommerce_admin_install_timestamp 1 >/dev/null 2>&1 || true
wp rewrite structure '/%postname%/' --hard >/dev/null
wp rewrite flush --hard >/dev/null 2>&1 || true

PLUGIN_STATUS="not present (skipped)"
PRODUCTS="skipped"
if [ -f "$PLUGIN_DIR/$PLUGIN_SLUG.php" ]; then
  echo "==> Activating plugin"
  wp plugin is-active "$PLUGIN_SLUG" >/dev/null 2>&1 || wp plugin activate "$PLUGIN_SLUG"
  PLUGIN_STATUS="active"
  echo "==> Seeding sample products"
  wp eval-file /seed-products.php --user=admin
  PRODUCTS="4 sample products (3 simple, 1 variable)"
else
  echo "==> Plugin file $PLUGIN_DIR/$PLUGIN_SLUG.php not found; skipping activation and products"
fi

cat <<SUMMARY

================ Dev environment ready ================
Site:      $URL
Admin:     $URL/wp-admin   (admin / admin)
Currency:  $CURRENCY
Plugin:    $PLUGIN_STATUS
Products:  $PRODUCTS
Re-run ./setup.sh after the plugin appears to activate it and seed products.
WP-CLI:    docker compose run --rm wpcli <command>
=======================================================
SUMMARY
