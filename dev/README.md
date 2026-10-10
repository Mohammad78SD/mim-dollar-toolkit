# Dev environment

Requires Docker. From this directory:

- `./setup.sh` - start containers, install WP + WooCommerce (currency IRT), activate the plugin and seed sample products if `../mim-dollar-toolkit/mim-dollar-toolkit.php` exists. Idempotent.
- `./reset.sh` - stop and delete everything (containers + volumes).
- WP-CLI: `docker compose run --rm wpcli <command>`

Site: http://localhost:8080 (override port: `WP_PORT=8081 ./setup.sh`) - Admin: /wp-admin (admin / admin)

The plugin folder is bind-mounted, so edits are live. Build a release zip with `../build.sh` (outputs `../dist/mim-dollar-toolkit.zip`).

The compose project is named `mdt-dev` (containers and volumes `mdt-dev-*`).
