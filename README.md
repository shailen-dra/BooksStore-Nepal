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
- PHP 8.1+ (local: Laravel Herd, PHP 8.3), MySQL 8
- WP-CLI, Composer, PHPUnit 9
- Payments: eSewa ePay v2 (custom gateway in our plugin), Khalti (official WooCommerce plugin), WooCommerce cash on delivery

## Local development

Requirements: [Laravel Herd](https://herd.laravel.com), MySQL (`brew install mysql`), WP-CLI (`brew install wp-cli`), Composer.

```bash
# 1. WordPress site served by Herd at http://bookstore-nepal.test
mysql -u root -e "CREATE DATABASE bookstore_nepal"
mkdir -p ~/Herd/bookstore-nepal && cd ~/Herd/bookstore-nepal
wp core download
wp config create --dbname=bookstore_nepal --dbuser=root --dbpass=''
wp core install --url=bookstore-nepal.test --title="Bookstore Nepal" \
  --admin_user=admin --admin_email=admin@example.com --prompt=admin_password
wp plugin install woocommerce --activate

# 2. Link this repo's plugin and theme into the site, then configure the store
cd /path/to/this/repo
bash bin/link-local.sh
wp --path=~/Herd/bookstore-nepal plugin activate bookstore-nepal-core
wp --path=~/Herd/bookstore-nepal bsn setup

# 3. Tests
composer install
bash bin/install-wp-tests.sh wp_tests root '' 127.0.0.1 latest
vendor/bin/phpunit
```

> The `bin/` scripts, plugin and theme are created by the build plan. Until those tasks are done, only step 1 works.

## Payments: test mode

- **eSewa:** WooCommerce → Settings → Payments → eSewa. Test mode uses product code `EPAYTEST` and eSewa's public test secret. Live keys come from the client's eSewa merchant account.
- **Khalti:** configure the official Khalti plugin with sandbox keys from the Khalti merchant dashboard.
- Never commit payment keys. They live only in each site's wp-admin settings.

## Open items from the client

Shop name and logo, domain, eSewa and Khalti merchant accounts, real delivery rates (the plan uses placeholders: Valley NPR 100, rest of Nepal NPR 200, free over NPR 2,000), courier partner, return policy, first 20–30 books. See the proposal's "Next steps" section.
