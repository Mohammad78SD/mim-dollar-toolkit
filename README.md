# Toman Toolkit for WooCommerce

A WooCommerce plugin for Iranian stores: USD-based Toman pricing, a pre-invoice checkout instead of online payment, SMS notifications and mobile-number (OTP) login, in one modular plugin.

> Built for, and running in production on, an Iranian carpet retailer's WooCommerce store. Sole developer: Mohammad Sabet Daryani.

<!-- TODO: screenshots (demo data) -->

## Modules

Each module can be switched on or off from **WooCommerce > Toman Toolkit**. All four modules are on by default.

### USD pricing

Enter product prices in USD; the plugin keeps the Toman prices up to date.

- Reads the USDT/Toman rate from **Bitpin, Nobitex and Wallex**, and uses the **median** of the providers that answered. With three or more answers it drops any value more than 5% away from the median.
- **Sanity guard:** a new rate that differs from the last accepted one by more than a configurable percentage (default 10%) is not applied. It waits for an admin to accept it with one click.
- Effective rate = raw rate plus an optional markup, rounded up to a configurable step; or a **manual override** that is used exactly as typed.
- Prices are recalculated in **batches through Action Scheduler**, so a large catalogue does not time out. Only products whose price actually changes are saved.
- Works with simple products and variations (sale prices and sale schedules keep working), and for stores whose currency is Toman (IRT) or Rial (IRR).
- After a sync it purges the common page caches (LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, SG Optimizer).
- Shortcode `[wtt_usd_rate]` prints the current rate.

### Pre-invoice checkout

For shops that do not take online payment: the customer requests a pre-invoice, and the shop contacts them to arrange payment.

- A payment method, "Request pre-invoice", and a custom order status, **Pre-invoice sent**. Works with the classic checkout and the block checkout.
- **Stock is reserved** when the pre-invoice is issued, and **auto-cancelled** after a configurable number of days (stock is restored). An order can be kept reserved from the order screen.
- Per-year numbering (`PI-1405-0001`), with a validity period.
- A printable, standalone **pre-invoice page** (A4 print styles) with **Jalali dates** and the total in Persian words, reachable from the email, the thank-you page and My Account.
- **Three emails**, editable under WooCommerce > Settings > Emails: pre-invoice to the customer, new pre-invoice to the admin, pre-invoice cancelled to the customer.
- Order actions: resend, mark as paid, extend validity, keep reserved.

### SMS

- Pattern (template) SMS through the **FarazSMS / IranPayamak API**. An older IPPanel Edge provider is still included.
- Events: pre-invoice issued, new pre-invoice (to admins), payment confirmed, pre-invoice cancelled, and the login code.
- Asynchronous sending through Action Scheduler, **one automatic retry** after 5 minutes on a network error or an HTTP 5xx answer. Client errors (such as 401 or 422) are not retried.
- A test-send form and an account check (balance and lines) that never sends a message.

### Accounts (auto account and mobile login)

- Guests who check out get a customer account created from their mobile number, and the order is attached to it. A newly created account is logged in on the order-received page; an existing account with the same mobile or email is reused and the visitor is *not* logged in.
- **Mobile OTP login** on the My Account page, with the password form kept as a fallback.
- Per-mobile and per-IP rate limits, and a choice of where the client IP comes from (`REMOTE_ADDR`, `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`, `AR-Real-IP` or a custom header), for sites behind a CDN or proxy.
- Details in [Security notes](#security-notes-otp).

## Constraints it was designed for

The target store runs on shared hosting with **no SSH and no WP-CLI**, so everything is done from wp-admin: install from a zip, configure from settings pages, trigger syncs and tests from buttons. There is no Composer dependency and no build step for the plugin itself. Persian (fa_IR) is shipped; the source strings are English.

## Architecture

```
woo-toman-toolkit/
  woo-toman-toolkit.php      bootstrap, HPOS declaration, WooCommerce check
  includes/                  module registry and base class, admin screen, logger,
                             Jalali and Persian helpers, migration and legacy shims
  modules/
    usd-pricing/             rate providers, rate manager, price sync, product fields, admin page
    pre-invoice/             gateway, order status, numbering, emails, printable page, block integration
    sms/                     providers, events, async sender, checkout mobile validation
    accounts/                auto account, OTP, REST routes, rate limiter, login form
  languages/                 .pot, fa_IR .po/.mo
tests/                       PHPUnit tests (no WordPress needed)
dev/                         Docker development environment
```

Key decisions:

- **Modules.** Each module extends one small abstract class (`id`, settings tab, `activate`, `deactivate`, `uninstall`) and is loaded only when enabled.
- **Native price fields instead of runtime filters.** The USD module writes the computed Toman price into WooCommerce's own `_regular_price`, `_sale_price` and `_price`, rather than filtering prices at display time. Sorting, price filters, product feeds, caches, the cart and old orders all keep working with no extra code, and a page cache cannot show a stale filtered price. The cost is that prices must be re-synced when the rate changes, which is what the Action Scheduler batches are for.
- **HPOS compatible.** The plugin declares compatibility with custom order tables and registers its order-list hooks for both storage layouts.
- **Classic and block checkout.** The pre-invoice payment method has a small JavaScript integration for the block checkout (no build step), and the usual gateway for the classic one.
- **No secrets in logs.** Logs go to a capped ring buffer per module; phone numbers are masked and OTP codes are never logged.
- **Migration from an earlier internal version.** The first version of this code used an `azk_` prefix. `includes/class-migration.php` is a one-time, idempotent migration: it copies options, renames post, user, order and HPOS meta keys, order statuses and payment methods, removes old schedules, and deactivates the old plugin. `includes/class-legacy.php` keeps old shortcodes and already-emailed pre-invoice links working. Both files intentionally mention the old names and can be deleted once nothing depends on them.

## Security notes (OTP)

- The code is a `random_int` value (5 or 6 digits, configurable). Only an **HMAC-SHA256** of mobile plus code, keyed with the site's `auth` salt, is stored, never the code. It is compared with `hash_equals`, expires (default 5 minutes) and is single use.
- **5 wrong attempts** invalidate the code. Failed verifications also count against a per-IP hourly limit.
- Requests are limited by a resend cooldown, an hourly limit per mobile and an hourly limit per IP. Rate limits and the response are identical whether or not an account exists, so the endpoint does not reveal which numbers are registered.
- Requests and verifications for one mobile are serialised with a small database lock.
- Any account that can edit posts, manage WooCommerce or manage the site (editors, shop managers, administrators) can never log in with an OTP, and orders are never attached to it.
- **Session protection:** if a mobile number was not proven before (for example an account auto-created at checkout by someone else), the first successful OTP login destroys every existing session for that account.
- Behind a CDN or proxy, set the IP source to the header your CDN sets; otherwise all visitors share the proxy's address. Only enable a header your infrastructure really controls, because headers can be forged by clients otherwise.

## Requirements

- PHP 7.4 or newer
- WordPress 6.0 or newer
- WooCommerce 7.0 or newer (the plugin header declares tested up to 11.1; the dev environment installs the current WooCommerce release)
- For SMS and OTP: a FarazSMS (IranPayamak) account with pattern templates

## Install

1. Build or download the zip. To build: `./build.sh` creates `dist/woo-toman-toolkit.zip` (the plugin folder only; tests, dev files and Composer files are not included).
2. In wp-admin: Plugins > Add New > Upload Plugin, choose the zip, activate.
3. Open **WooCommerce > Toman Toolkit** to enable modules and configure each one.

## Local development

`dev/` has a Docker environment (WordPress on PHP 8.2, MariaDB, WP-CLI) with the plugin folder mounted live:

```bash
cd dev
WP_PORT=8081 ./setup.sh        # installs WordPress and WooCommerce, sets the currency, activates the plugin, seeds sample products
WP_PORT=8081 docker compose run --rm wpcli <wp-cli command without the leading "wp">
./reset.sh                     # delete containers and volumes
```

Site: `http://localhost:8081`, admin `admin` / `admin` (local only). Two must-use plugins in `dev/mu-plugins/` replace the outside world: `wtt-sms-mock.php` fakes the SMS APIs (and logs requests to `wp-content/sms.log`, including OTP codes, for development only), and `wtt-mail-log.php` writes outgoing mail to files instead of sending it. See [dev/README.md](dev/README.md).

## Tests

Unit tests cover the pure logic and need no WordPress install: PHPUnit 9.6 with a small bootstrap (`tests/bootstrap.php`) that stubs the few WordPress functions the code touches.

```bash
composer install
vendor/bin/phpunit
```

Or without a local PHP:

```bash
docker run --rm -v $PWD:/app -w /app composer:2 install
docker run --rm -v $PWD:/app -w /app php:7.4-cli vendor/bin/phpunit
docker run --rm -v $PWD:/app -w /app php:8.3-cli vendor/bin/phpunit
```

Current run: **153 tests, 3342 assertions**, passing on PHP 7.4 and PHP 8.3. They cover Jalali conversion (including Nowruz boundaries and the 1403 and 1408 leap years), Jalali date formatting and Persian digits, number-to-words, phone normalisation and validation, sender-line handling per provider, rate aggregation and effective rate, price calculation, the FarazSMS and IPPanel response handling (success, 401, 422, 5xx, network error), and the OTP code hashing, rate-limit counters and client-IP handling.

Not covered by unit tests: anything that needs WordPress or WooCommerce at run time (checkout, order status flow, emails, admin screens, Action Scheduler batches, the OTP request and verify flow end to end). Those were exercised by hand in the Docker environment. CI runs `php -l` on every plugin file and the unit tests on PHP 7.4, 8.1 and 8.3, plus a `msgfmt -c` check of the translation.

## Translations

The source language is English. A complete Persian translation is in `woo-toman-toolkit/languages/` (`woo-toman-toolkit-fa_IR.po` / `.mo`, plus the `.pot` template). Jalali dates, Persian digits and amounts in words are used when the site locale is `fa_*`.

## License

[MIT](LICENSE). Copyright (c) 2026 Mohammad Sabet Daryani.
