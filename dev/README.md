# Dev environment

Requires Docker. From this directory:

- `./setup.sh` - start containers, install WP + WooCommerce (currency IRT), activate the plugin and seed sample products if `../woo-toman-toolkit/woo-toman-toolkit.php` exists. Idempotent.
- `./reset.sh` - stop and delete everything (containers + volumes).
- WP-CLI: `docker compose run --rm wpcli <command>`

Site: http://localhost:8080 (override port: `WP_PORT=8081 ./setup.sh`) - Admin: /wp-admin (admin / admin)

The plugin folder is bind-mounted, so edits are live. Build a release zip with `../build.sh` (outputs `../dist/woo-toman-toolkit.zip`).

The compose project is named `wtt-dev` (containers and volumes `wtt-dev-*`).
