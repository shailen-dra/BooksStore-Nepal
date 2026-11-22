# BooksStore-Nepal

Online children's bookstore for Nepal, built on WordPress + WooCommerce. Customers browse books by age group, language and type, and pay with **eSewa**, **Khalti** or **cash on delivery**, with delivery anywhere in Nepal. The shop owner manages books, prices, stock and orders from wp-admin.

## Documents

| Document | What it is |
| --- | --- |
| [docs/client-proposal.md](docs/client-proposal.md) | Client-facing proposal: features, payments, delivery, platform choice, costs |
| [docs/superpowers/plans/2026-11-19-woocommerce-bookstore.md](docs/superpowers/plans/2026-11-19-woocommerce-bookstore.md) | Developer build plan, task by task |

## What's in this repo

This repo holds only our custom code. WordPress core, WooCommerce and third-party plugins are installed separately in each environment.

| Path | Purpose |
| --- | --- |
| `plugins/bookstore-nepal-core/` | Custom plugin: catalogue attributes, Nepal districts and shipping, eSewa gateway, gift finder, custom order statuses, `wp bsn setup` command |
| `themes/bookstore-nepal/` | Block child theme of Twenty Twenty-Five |
| `tests/` | PHPUnit tests (WordPress core test suite + WooCommerce) |
| `bin/` | Local setup and seed scripts |
| `docs/` | Proposal, build plan, admin guide, deployment notes |

## Tech stack

- WordPress 6.x + WooCommerce
- PHP 8.1+ (local: Laravel Herd, PHP 8.3), MariaDB 10.6+ or MySQL 8
- WP-CLI, Composer, PHPUnit 9
- Payments: eSewa ePay v2 (custom gateway in our plugin), Khalti (official WooCommerce plugin), WooCommerce cash on delivery

## Local development

Requirements: [Laravel Herd](https://herd.laravel.com), MariaDB 10.6+ or MySQL 8 listening on 127.0.0.1:3306, WP-CLI (`brew install wp-cli`), Composer.

One-time database setup (as a DB admin), creating a `bookstore` user used by the site and the tests:

```sql
CREATE DATABASE bookstore_nepal;
CREATE DATABASE wp_tests;
CREATE USER 'bookstore'@'127.0.0.1' IDENTIFIED BY 'bookstore';
GRANT ALL ON bookstore_nepal.* TO 'bookstore'@'127.0.0.1';
GRANT ALL ON wp_tests.* TO 'bookstore'@'127.0.0.1';
```

`wp_tests` is wiped on every test run, so never point the tests at a database you care about.

```bash
# 1. WordPress site served by Herd at http://bookstore-nepal.test
mkdir -p ~/Herd/bookstore-nepal && cd ~/Herd/bookstore-nepal
wp core download
wp config create --dbname=bookstore_nepal --dbuser=bookstore --dbpass=bookstore --dbhost=127.0.0.1 \
  --extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
PHP
wp core install --url=bookstore-nepal.test --title="Bookstore Nepal" \
  --admin_user=admin --admin_email=admin@example.com --admin_password=admin --skip-email
wp plugin install woocommerce --activate

# 2. Link this repo's plugin and theme into the site, then configure the store
cd /path/to/this/repo
bash bin/link-local.sh
wp --path=~/Herd/bookstore-nepal plugin activate bookstore-nepal-core
wp --path=~/Herd/bookstore-nepal bsn setup
wp --path=~/Herd/bookstore-nepal theme activate bookstore-nepal
bash bin/seed-sample-books.sh   # optional: 12 sample books

# 3. Tests
composer install
bash bin/install-wp-tests.sh wp_tests bookstore bookstore 127.0.0.1 latest true
vendor/bin/phpunit
# Optional env: WP_TESTS_DIR (default: system temp dir/wordpress-tests-lib), WC_PLUGIN_DIR (default: the Herd site's WooCommerce)
```

> If WP-CLI runs out of memory, run it as `php -d memory_limit=512M $(which wp) ...`. `wp bsn setup` leaves existing shipping rates alone; pass `--valley=<npr> --rest=<npr> --free-over=<npr>` to change them. Placeholder contact details (phone, WhatsApp, address) live in the theme footer: Appearance › Editor › Patterns › Footer. WooCommerce's "coming soon" mode hides store pages from visitors until you launch (WooCommerce › Settings › Site visibility).

## Payments: test mode

- **eSewa:** WooCommerce → Settings → Payments → eSewa. Test mode uses product code `EPAYTEST` and eSewa's public test secret. Live keys come from the client's eSewa merchant account.
- **Khalti:** plugin slug `integrate-khalti-in-wc-store` ("Integrate Khalti in WC Store", wordpress.org account `khaltiwp`, built by ACT360, linked from khalti.com). Install with `wp plugin install integrate-khalti-in-wc-store --activate`, then enter sandbox keys from the Khalti merchant dashboard in WooCommerce -> Settings -> Payments -> Khalti. **Known limitation:** this plugin has no WooCommerce Blocks integration, so it does not render in the block checkout, which this site uses. It ships enabled by default, so keep it disabled until keys and a block-checkout decision are in place.
- Never commit payment keys. They live only in each site's wp-admin settings.

## Open items from the client

Shop name and logo, domain, eSewa and Khalti merchant accounts, real delivery rates (the plan uses placeholders: Valley NPR 100, rest of Nepal NPR 200, free over NPR 2,000), courier partner, return policy, first 20–30 books. See the proposal's "Next steps" section.
