# Children's Bookstore (WooCommerce) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A WooCommerce children's bookstore selling across Nepal, with eSewa, Khalti and cash on delivery, that a non-technical shop owner manages from wp-admin.

**Architecture:** WordPress core and third-party plugins are *not* in this repo. The repo holds only our code: one plugin (`bookstore-nepal-core`: catalogue attributes, districts/shipping, eSewa gateway, gift finder, setup command) and one block child theme (`bookstore-nepal`). Locally, WordPress runs under Laravel Herd at `~/Herd/bookstore-nepal`, with the plugin and theme symlinked from this repo. Store configuration is scripted as a WP-CLI command so any environment (local, staging, live) can be set up identically.

**Tech Stack:** WordPress 6.x, WooCommerce (latest), PHP 8.1+ (local: Herd PHP 8.3), MySQL 8 (local: Homebrew), WP-CLI, PHPUnit 9 + yoast/phpunit-polyfills with the WordPress core test suite, Composer. Theme: block child theme of Twenty Twenty-Five. Khalti via its official WooCommerce plugin.

**Spec:** `docs/client-proposal.md`

## Global Constraints

- Payment methods: eSewa, Khalti, cash on delivery. Delivery: anywhere in Nepal, zone-based rates.
- Store currency NPR, base country Nepal (`NP`). Guest checkout enabled.
- Catalogue filters (global WooCommerce attributes, used for browse + filter):
  - Age group: `0–3`, `4–7`, `8–12` (slugs `0-3`, `4-7`, `8-12`)
  - Language: `Nepali`, `English`
  - Book type: `Picture book`, `Story`, `Activity`, `Educational`
- Book page shows: cover, price, age range, language, page count, short description (+ author).
- Shipping zones: "Kathmandu Valley" (districts Kathmandu, Lalitpur, Bhaktapur) and "Rest of Nepal". Rates and free-delivery threshold are **placeholders until the client supplies them**: Valley NPR 100, Rest NPR 200, free over NPR 2,000.
- Low-stock threshold 3, email to shop admin.
- Mobile-first; Lighthouse mobile Performance ≥ 85 and Accessibility ≥ 95 on home, shop and product pages.
- All code prefixed `bsn_` / namespace `BookstoreNepal\`. Text domain `bookstore-nepal`.
- No secrets in git: eSewa/Khalti keys live in wp-admin settings (database) only.

## Review Focus

1. **eSewa callback tampering** — a forged or modified `data` payload (wrong signature, lower amount, other order's UUID) must never mark an order paid. Test in Task 4.
2. **Duplicate eSewa callback** (customer refreshes the success page) — order is completed once, stock reduced once, one email. Test in Task 4.
3. **Abandoned / failed payment** — customer returns via failure URL or never returns: order stays `pending`/`failed`, cart is preserved, customer can retry or choose COD. Test in Task 4 (failure URL) + manual check in Task 8.
4. **Devanagari content** — Nepali titles/authors must be searchable and produce valid product URLs. Test in Task 2.
5. **Checkout without a district** or a district outside the list — checkout must reject with a clear message, not ship at the wrong rate. Test in Task 3.

---

## File Structure

```
.gitignore
README.md
composer.json                         # dev deps: phpunit, polyfills
phpunit.xml.dist
bin/install-wp-tests.sh               # standard WP test-suite installer
bin/link-local.sh                     # symlinks plugin+theme into ~/Herd/bookstore-nepal
tests/bootstrap.php                   # loads WP test suite + WooCommerce + our plugin
tests/Unit/                           # one test file per src class
plugins/bookstore-nepal-core/
  bookstore-nepal-core.php            # plugin header, autoload, bootstraps classes
  src/Catalog.php                     # attributes + book meta fields
  src/Districts.php                   # NP districts list, Valley set
  src/Shipping.php                    # zone/rate setup + district validation
  src/Gateway/EsewaSignature.php      # pure HMAC helpers
  src/Gateway/EsewaGateway.php        # WC_Payment_Gateway subclass
  src/GiftFinder.php                  # shortcode + URL builder
  src/OrderStatuses.php               # Packed / Shipped / Delivered
  src/Cli/SetupCommand.php            # `wp bsn setup`
themes/bookstore-nepal/
  style.css, theme.json, functions.php
  templates/front-page.html
  parts/header.html, parts/footer.html
docs/admin-guide.md                   # client-facing how-to with screenshots
docs/deployment.md
```

---

### Task 1: Local environment and test harness

**Files:**
- Create: `composer.json`, `phpunit.xml.dist`, `bin/install-wp-tests.sh`, `bin/link-local.sh`, `tests/bootstrap.php`, `tests/Unit/PluginLoadsTest.php`, `plugins/bookstore-nepal-core/bookstore-nepal-core.php`
- Modify: `.gitignore` (add `vendor/`), `README.md` (dev setup)

**Interfaces:**
- Produces: plugin constant `BSN_VERSION`, `BSN_PLUGIN_FILE`; PSR-4 autoload `BookstoreNepal\` → `plugins/bookstore-nepal-core/src/`; function `bsn_boot(): void` hooked on `plugins_loaded` (priority 20, after WooCommerce).

- [ ] **Step 1:** Install WP-CLI (`brew install wp-cli`). Create DB `bookstore_nepal`, download WordPress into `~/Herd/bookstore-nepal` (`wp core download`, `wp config create`, `wp core install --url=bookstore-nepal.test`), install + activate WooCommerce. Expected: `http://bookstore-nepal.test` loads.
- [ ] **Step 2:** Write `bin/link-local.sh` — symlinks `plugins/bookstore-nepal-core` and `themes/bookstore-nepal` into the Herd site's `wp-content`. Run it, `wp plugin activate bookstore-nepal-core`. Expected: active, no PHP notices in `wp-content/debug.log` (`WP_DEBUG_LOG` on).
- [ ] **Step 3:** `composer.json` with dev deps `phpunit/phpunit:^9.6`, `yoast/phpunit-polyfills:^2`; `bin/install-wp-tests.sh` (from `wp scaffold plugin-tests`). `tests/bootstrap.php` loads WooCommerce from `$_tests_dir`-adjacent `WP_PLUGIN_DIR` on `muplugins_loaded`, then our plugin.
- [ ] **Step 4: Failing test** `PluginLoadsTest::test_plugin_boots_after_woocommerce` — asserts `defined('BSN_VERSION')`, `class_exists('WooCommerce')`, `has_action('plugins_loaded', 'bsn_boot') === 20`.
- [ ] **Step 5:** Run `bash bin/install-wp-tests.sh wp_tests root '' 127.0.0.1 latest && vendor/bin/phpunit` → FAIL, then add plugin header/constants/`bsn_boot`, re-run → PASS.
- [ ] **Step 6:** README "Local development" section (steps 1–5 as commands). Commit `chore: local env, test harness, plugin skeleton`.

### Task 2: Catalogue attributes and book fields

**Files:**
- Create: `plugins/bookstore-nepal-core/src/Catalog.php`, `tests/Unit/CatalogTest.php`

**Interfaces:**
- Produces: `Catalog::ATTRIBUTES` (array slug ⇒ [label, terms]); `Catalog::install(): void` (idempotent, called on activation + by `wp bsn setup`); `Catalog::META_PAGES = '_bsn_page_count'`, `Catalog::META_AUTHOR = '_bsn_author'`; admin fields on the product *General* tab; front-end display under the price via `woocommerce_single_product_summary` priority 25.

- [ ] **Step 1: Failing tests**
  - `test_install_creates_three_global_attributes` — after `Catalog::install()`, `wc_attribute_taxonomy_id_by_name('age-group')`, `'language'`, `'book-type'` are all > 0 and terms match Global Constraints exactly.
  - `test_install_is_idempotent` — calling twice leaves exactly 3 attributes and 9 terms.
  - `test_page_count_and_author_saved` — saving a product with `_bsn_page_count = 32`, `_bsn_author = 'लक्ष्मीप्रसाद देवकोटा'` round-trips.
  - `test_devanagari_title_is_searchable` — product titled `मुनामदन` is returned by `wc_get_products(['s' => 'मुनामदन'])` and its permalink is non-empty and valid UTF-8.
- [ ] **Step 2:** Run → FAIL. Implement `Catalog`. Run → PASS.
- [ ] **Step 3:** Manual: add one book in wp-admin, see fields and attributes on product page. Commit `feat: book catalogue attributes and fields`.

### Task 3: Districts, shipping zones and checkout validation

**Files:**
- Create: `src/Districts.php`, `src/Shipping.php`, `tests/Unit/ShippingTest.php`

**Interfaces:**
- Produces: `Districts::all(): array<string code, string name>` — Nepal's 77 districts, codes `NP-D-<ASCII slug>` e.g. `NP-D-KATHMANDU`; `Districts::VALLEY = ['NP-D-KATHMANDU','NP-D-LALITPUR','NP-D-BHAKTAPUR']`. Filter `woocommerce_states` replaces `NP` states with `Districts::all()` (checkout "State" field relabelled "District" via `woocommerce_get_country_locale`, required). `Shipping::install(array $rates = ['valley'=>100,'rest'=>200,'free_over'=>2000]): void` — creates zones "Kathmandu Valley" (VALLEY districts) and "Rest of Nepal" (country NP), each with flat rate + free shipping (min amount `free_over`); idempotent.

- [ ] **Step 1: Failing tests**
  - `test_77_districts_and_valley_subset` — `count(Districts::all()) === 77`; every VALLEY code is a key.
  - `test_valley_package_gets_valley_rate` — package to `NP-D-LALITPUR`, cart NPR 500 → rate cost 100.
  - `test_outside_valley_gets_rest_rate` — `NP-D-KASKI`, NPR 500 → 200.
  - `test_free_over_threshold` — `NP-D-KASKI`, NPR 2000 → free shipping available.
  - `test_checkout_requires_known_district` — posting checkout with empty or unknown district adds a `wc_add_notice` error and no order is created.
  - `test_install_idempotent` — two calls → exactly 2 zones named as above.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: districts and zone shipping`.

### Task 4: eSewa payment gateway (ePay v2)

Verify endpoints and field names against eSewa's current developer docs (developer.esewa.com.np) before coding; values below are the v2 form flow.

**Files:**
- Create: `src/Gateway/EsewaSignature.php`, `src/Gateway/EsewaGateway.php`, `tests/Unit/EsewaSignatureTest.php`, `tests/Unit/EsewaGatewayTest.php`

**Interfaces:**
- Produces:
  - `EsewaSignature::sign(array $fields, array $names, string $secret): string` — message = `name=value` pairs for `$names` in order joined by `,`; returns `base64(hmac_sha256(message, secret, raw))`.
  - `EsewaSignature::verify(array $payload, string $secret): bool` — uses `$payload['signed_field_names']`, constant-time compare (`hash_equals`).
  - `EsewaGateway extends WC_Payment_Gateway`, id `esewa`; settings: `enabled`, `testmode`, `product_code` (test `EPAYTEST`), `secret_key` (test `8gBm/:&EnhH.1/q`). Form URL test `https://rc-epay.esewa.com.np/api/epay/main/v2/form`, live `https://epay.esewa.com.np/api/epay/main/v2/form`. Signed fields `total_amount,transaction_uuid,product_code`. `transaction_uuid` = `{order_id}-{time()}`, stored in order meta `_bsn_esewa_uuid`.
  - Callback: `WC()->api_request_url('bsn_esewa')`, `?result=success|failure&order_id=N`. Success → base64-decode `data`, `verify()`, check `transaction_uuid` = stored meta, `total_amount` = order total, `status === 'COMPLETE'`, then server-side status check API before `payment_complete($transaction_code)`.

- [ ] **Step 1: Failing signature tests** — `test_sign_matches_esewa_doc_example` (use the worked example from eSewa's docs: their message, secret and expected signature verbatim); `test_verify_rejects_modified_amount`; `test_verify_rejects_wrong_secret`.
- [ ] **Step 2:** Run → FAIL. Implement `EsewaSignature`. Run → PASS.
- [ ] **Step 3: Failing gateway tests** (status-check HTTP mocked with `pre_http_request` filter):
  - `test_valid_callback_completes_order` — order status `processing`, transaction id saved.
  - `test_tampered_signature_leaves_order_pending`
  - `test_amount_mismatch_leaves_order_pending` — payload amount 100 for a 500 order.
  - `test_uuid_of_other_order_rejected`
  - `test_duplicate_callback_processed_once` — second identical callback: no status change, `payment_complete` not called again, stock reduced once.
  - `test_status_api_not_complete_leaves_pending`
  - `test_failure_url_marks_failed_and_keeps_cart` — order `failed`, redirect to cart with notice "eSewa payment was not completed. You can try again or choose another payment method."
- [ ] **Step 4:** Run → FAIL. Implement `EsewaGateway` (register via `woocommerce_payment_gateways`). Run → PASS.
- [ ] **Step 5:** Manual sandbox payment with eSewa test credentials end to end. Commit `feat: eSewa ePay v2 gateway`.

### Task 5: Store setup command, order statuses, Khalti and COD

**Files:**
- Create: `src/Cli/SetupCommand.php`, `src/OrderStatuses.php`, `tests/Unit/SetupCommandTest.php`, `tests/Unit/OrderStatusesTest.php`

**Interfaces:**
- Consumes: `Catalog::install()`, `Shipping::install()`.
- Produces: `wp bsn setup [--valley=<npr>] [--rest=<npr>] [--free-over=<npr>]` — sets `woocommerce_currency=NPR`, `woocommerce_default_country=NP:NP-D-KATHMANDU`, `woocommerce_enable_guest_checkout=yes`, `woocommerce_manage_stock=yes`, `woocommerce_notify_low_stock_amount=3`, `woocommerce_notify_no_stock_amount=0`, price decimals 0; enables COD (title "Cash on delivery", enabled for both zones); runs `Catalog::install()` and `Shipping::install()`. Idempotent.

- Also produces: `src/OrderStatuses.php` — registers order statuses `wc-packed` ("Packed") and `wc-shipped` ("Shipped"), inserted after Processing in `wc_order_statuses`; relabels `wc-completed` to "Delivered"; adds them to bulk actions on the orders screen. Setup creates a "Track your order" page containing `[woocommerce_order_tracking]` (option `bsn_tracking_page_id`).

- [ ] **Step 1: Failing tests** `test_setup_applies_store_options` — invoke command class directly; assert each option above and COD `enabled === 'yes'`; run twice, assert 2 zones and one tracking page. `test_custom_order_statuses` — order can be set to `packed` then `shipped`; `wc_get_order_statuses()['wc-completed'] === 'Delivered'`.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS.
- [ ] **Step 3:** Install the official Khalti WooCommerce plugin from wordpress.org (confirm current slug on wordpress.org), configure test keys from Khalti's sandbox dashboard. Manual test payment completes an order. Record the plugin slug in README.
- [ ] **Step 4:** Commit `feat: wp bsn setup command; COD enabled`.

### Task 6: Gift finder

**Files:**
- Create: `src/GiftFinder.php`, `tests/Unit/GiftFinderTest.php`

**Interfaces:**
- Produces: `GiftFinder::url(string $age_slug, ?int $max_price): string` → shop URL with `filter_age-group=<slug>&query_type_age-group=or` and `max_price=<n>` when given; invalid age slug ⇒ plain shop URL. Shortcode `[bsn_gift_finder]` renders a GET form (age select from `Catalog::ATTRIBUTES`, budget select: Under NPR 500 / 1,000 / 2,000 / Any) that posts to itself and redirects to `url()`.

- [ ] **Step 1: Failing tests** — `test_url_with_age_and_budget`, `test_url_without_budget`, `test_invalid_age_falls_back_to_shop`, `test_shortcode_renders_all_age_options`.
- [ ] **Step 2:** Run → FAIL. Implement. Run → PASS. Commit `feat: gift finder`.

### Task 7: Child theme

**Files:**
- Create: `themes/bookstore-nepal/style.css` (Template: `twentytwentyfive`), `theme.json`, `functions.php`, `templates/front-page.html`, `parts/header.html`, `parts/footer.html`

**Decisions:**
- `theme.json`: palette of 5 bright, high-contrast colours (all text/background pairs ≥ 4.5:1), rounded corners, base font size 18px, one display font + one body font (Google Fonts, self-hosted via `fontFace`, both with Devanagari support, e.g. Baloo 2 + Mukta).
- Front page, top to bottom: hero with search; "Shop by age" — three tiles linking to `GiftFinder::url()` for each age; New arrivals (WooCommerce Product Collection block, newest 8); Featured (featured 8); Gift finder shortcode; trust strip (eSewa · Khalti · Cash on delivery · Delivery all over Nepal).
- Header: logo, search, cart, menu with Age / Language / Type links and "Track your order". Footer: contact (phone, WhatsApp, address from client), return policy link.

- [ ] **Step 1:** Build theme, activate. Seed 12 sample books (`wp wc product create` script in `bin/seed-sample-books.sh`, covering every attribute term including Nepali-language titles).
- [ ] **Step 2: Verify** — Lighthouse (mobile) on `/`, `/shop/`, one product page meets Global Constraints thresholds; check at 360px width: no horizontal scroll, tap targets ≥ 44px.
- [ ] **Step 3:** Commit `feat: bookstore child theme`.

### Task 8: End-to-end check, admin guide and deployment docs

**Files:**
- Create: `docs/admin-guide.md`, `docs/deployment.md`

- [ ] **Step 1: Manual E2E on local** (record pass/fail in PR description): order with eSewa (sandbox), Khalti (sandbox), COD to Kathmandu, COD to Kaski, order over NPR 2,000 (free delivery), abandoned eSewa payment then retry with COD, out-of-stock book cannot be added, low-stock email received.
- [ ] **Step 2:** `docs/admin-guide.md` for the client, one section each: add a book, change price / set sale, update stock, process an order (incl. confirming COD payment), create a discount code, change homepage featured books, change delivery rates. Screenshots in `docs/admin-guide/`.
- [ ] **Step 3:** `docs/deployment.md`: hosting requirements (PHP ≥ 8.1, MySQL 8, SSL), deploy steps (install WP + WooCommerce + Khalti plugin, upload plugin/theme from repo, `wp bsn setup` with client's real rates), switch eSewa/Khalti to live keys and turn off test mode, backups (daily, off-site), monthly update routine.
- [ ] **Step 4:** Commit `docs: admin guide and deployment`.
