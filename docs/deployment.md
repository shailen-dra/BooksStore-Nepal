# Deployment guide

For whoever deploys and maintains the site. The repo holds only our custom plugin and theme; WordPress core, WooCommerce and third-party plugins are installed on the server.

## 1. Hosting requirements

- PHP 8.1 or newer (8.3 recommended), with the usual WordPress extensions (curl, mbstring, xml, zip, gd or imagick, intl).
- MySQL 8 or MariaDB 10.6+.
- SSL certificate (HTTPS) on the final domain. eSewa and Khalti both require it.
- WP-CLI on the server (used for setup).
- At least 256 MB PHP memory limit. If WP-CLI runs out of memory, run it as `php -d memory_limit=512M $(which wp) ...`.
- Outgoing email that works (SMTP or the host's mail). Order and low-stock emails depend on it.
- Shell or SFTP access, and a daily off-site backup option (see section 5).

## 2. Deploy steps

Run WP-CLI commands from the WordPress directory, or pass `--path=`.

1. Create the database and user, then install WordPress with the client's domain:
   ```bash
   wp core download
   wp config create --dbname=... --dbuser=... --dbpass=... --dbhost=...
   wp core install --url=https://CLIENT-DOMAIN --title="SHOP NAME" --admin_user=... --admin_email=... --prompt=admin_password
   ```
   Use a strong, non-default admin username and password.
2. Install WooCommerce:
   ```bash
   wp plugin install woocommerce --activate
   ```
3. **Do not install** the third-party Khalti plugin (`integrate-khalti-in-wc-store`). It does not work with the block checkout this site uses; Khalti is built into our plugin (see "Khalti" in section 3). If it is already installed, deactivate it: `wp plugin deactivate integrate-khalti-in-wc-store`.
4. Copy this repo's plugin and theme to the server:
   - `plugins/bookstore-nepal-core/` to `wp-content/plugins/bookstore-nepal-core/`
   - `themes/bookstore-nepal/` to `wp-content/themes/bookstore-nepal/`

   The theme is a child theme of Twenty Twenty-Five, so the parent theme must be installed too: `wp theme install twentytwentyfive`.
5. Activate and configure, using the client's real delivery rates (whole NPR):
   ```bash
   wp plugin activate bookstore-nepal-core
   wp bsn setup --valley=<npr> --rest=<npr> --free-over=<npr>
   wp theme activate bookstore-nepal
   ```
   Until the client supplies real rates, the placeholders are Valley NPR 100, Rest of Nepal NPR 200, free delivery on orders of NPR 2,000 or more. Running `wp bsn setup` with no flags uses these on a fresh install and leaves existing rates alone on later runs. Pass a flag to change just that rate.

   `wp bsn setup` is safe to run again. Every run sets currency to NPR, the default country to Nepal and stock management on, creates the Age group / Language / Book type attributes, the "Kathmandu Valley" and "Rest of Nepal" shipping zones and the "Track your order" page and the store information pages (About us, Contact us, Delivery information, Returns & refunds, Privacy policy, Terms & conditions, FAQ) if any are missing, and switches permalinks to `/%postname%/` if they are still plain.

   The **first run only** also applies these store defaults, which the owner may later change in wp-admin: cash on delivery enabled for every delivery method, low-stock alert at 3 (out of stock at 0) emailed to the site admin address, guest checkout on, no decimals in prices, phone number required at checkout, and selling and delivering to Nepal only. Later runs leave them as the owner set them and print a line saying so. Setup records the first run in the `bsn_setup_done` option; to apply the defaults again (overwriting the owner's changes), run `wp option delete bsn_setup_done && wp bsn setup`.
6. **Rewrite rules.** `wp bsn setup` only clears the stored rewrite rules so WordPress rebuilds them on the next request. That does not write `.htaccess`. On Apache hosts run this once after setup, or open **Settings › Permalinks** in wp-admin and click **Save Changes**:
   ```bash
   wp rewrite flush --hard
   ```
   (Nginx sites need the usual WordPress `try_files` rule instead.) Skipping this can make shop and book pages return 404 errors.
7. Go live in WooCommerce: **WooCommerce › Settings › Site visibility**, set the site to **Live**. While "Coming soon" is on, visitors see an empty or blocked shop.
8. Add the real books, or import them with **Products › All Products › Import**.

### Content and branding checklist

- **Contact details.** Enter the store address, **Shop phone**, **WhatsApp number** (a Nepali mobile such as `9812345678`; `977` is added, other shapes are rejected) and **Contact email** in **WooCommerce › Settings › General**. They feed the Contact page and the footer. Or set them at setup: `wp bsn setup --phone="+977 1 4XXXXXX" --whatsapp=9812345678 --email=hello@example.com` (each flag is applied only when given; an empty value clears it). Anything missing shows a `[CLIENT ...]` placeholder; search the live site for "CLIENT" afterwards.
- **Store information pages.** `wp bsn setup` creates and publishes About us, Contact us, Delivery information, FAQ, Terms & conditions, and fills WordPress's draft Privacy policy and WooCommerce's draft Refund and Returns Policy (it also sets the Terms page used by the checkout link). Each page holds one theme pattern with draft wording and `[CLIENT ...]` blanks (return period, refund timing, delivery times, VAT, retention, opening hours, founder story). Pages already published or edited by the owner are never overwritten, and a draft the owner edited stays a draft. A page that already exists at the same address (for example an existing `/about/`) is adopted instead of duplicated. Private, pending and scheduled pages are left alone. If a store page is moved to the Trash or permanently deleted, the next `wp bsn setup` creates it again; to opt out, keep it as an edited draft rather than deleting it. If setup runs while the Bookstore Nepal theme is not active, it warns and the pages get a short placeholder paragraph instead of their text. **Review Privacy policy, Terms & conditions and Returns & refunds with the client, and ideally a lawyer, before launch.** To edit a page's text, detach the pattern in the page editor (see the admin guide, "Shop information pages").
- **Site icon and logo.** Once the client's logo exists, set the site icon in **Settings › General** (Site Icon) or **Appearance › Editor**, and add the logo to the header in the Site Editor.
- **Currency symbol.** WooCommerce shows the Nepali rupee as ₨ (for example ₨1,800). That is fine to leave. Under **WooCommerce › Settings › General › Currency options** you can only pick the currency (Nepalese rupee (₨) — NPR) and the **Currency position** (Left, Right, Left with space, Right with space). There is no setting for the symbol itself; showing "Rs" or "NPR" instead of ₨ needs a small code snippet using WooCommerce's `woocommerce_currency_symbol` filter, or a plugin.
- **Emails.** In **WooCommerce › Settings › Emails**, check the sender name and address, and add the shop logo and footer text.
- **Admin email.** Low-stock and new-order emails go to the address in **Settings › General** (Administration Email Address) and **WooCommerce › Settings › Emails**. Make sure it is monitored.

## 3. Payments: switch to live

Never commit payment keys. Enter them only in the live site's wp-admin.

### eSewa

1. Get the live **product code** and **secret key** from the client's eSewa merchant account.
2. Go to **WooCommerce › Settings › Payments** and open **eSewa**.
3. Enter **Live product code** and **Live secret key**.
   ![eSewa settings](admin-guide/08-esewa.png)
4. **Untick Test mode** ("Use the eSewa sandbox"). While test mode is ticked, the live code and secret are ignored and the sandbox (product code `EPAYTEST`) is used, so real money is never taken. Enter the live code and secret first: while test mode is off without them, eSewa is hidden at checkout and unpaid eSewa orders are **not** cancelled automatically (the website cannot check them).
5. Tick **Enable eSewa** and click **Save changes**.
6. Place a real low-value order with eSewa and confirm the order moves to **Processing**. Refund it in eSewa afterwards.

#### Scheduled tasks (WP-Cron / Action Scheduler)

The eSewa and Khalti automatic payment checks run every 5 minutes through Action Scheduler (part of WooCommerce), as do WooCommerce's own jobs such as cancelling unpaid orders and sending deferred emails. Action Scheduler is started by WP-Cron, and WP-Cron by default only runs when someone visits the site, so on a quiet site the check can be late. On the live server:

1. Add `define( 'DISABLE_WP_CRON', true );` to `wp-config.php`.
2. Add a system cron job that runs every minute, either:
   ```bash
   * * * * * curl -fsS https://CLIENT-DOMAIN/wp-cron.php?doing_wp_cron > /dev/null
   ```
   or, with WP-CLI on the server:
   ```bash
   * * * * * cd /path/to/wordpress && wp action-scheduler run --quiet
   ```

To check it is working, open **WooCommerce › Status › Scheduled Actions**, search for `bsn_esewa_reconcile` (group `bsn`): there should be one **Pending** action due within 5 minutes, and the **Complete** list should show recent runs. From the command line: `wp action-scheduler action list --hook=bsn_esewa_reconcile`. The action is created automatically (on the next wp-admin, cron or WP-CLI request) after the plugin is activated, and removed when the plugin is deactivated. If eSewa's status API cannot be reached, a line starting "Bookstore Nepal eSewa: automatic check of order" is written to the PHP error log (or `wp-content/debug.log` when debug logging is on) and the order is checked again on the next run.

Test mode is on by default. Check this setting before launch and after any restore of a staging database onto the live site.

**Switching a live shop back to test mode** (eSewa or Khalti) holds every unpaid order that has a live payment attempt: the sandbox cannot check real payments, so these orders are not completed or cancelled automatically. Each gets a note ("eSewa payment started in live mode — check the live merchant portal" / "Khalti payment started in live mode — check the Khalti merchant dashboard"). Check them by hand in the live merchant portal, or switch back to live mode, where they are checked again as usual. Test-mode attempts seen from live mode are never real money and do not hold an order.

### Khalti

Khalti (Web Checkout, KPG-2) is a gateway in our plugin, shown in the block checkout like eSewa. Customers are sent to Khalti to pay; the order is marked paid only after Khalti's lookup API confirms the payment for the exact order total.

1. Get the **live secret key** from the client's Khalti merchant dashboard (https://admin.khalti.com).
2. Go to **WooCommerce › Settings › Payments** and open **Khalti** (our gateway; its settings page is `section=bsn_khalti`).
3. Enter the **Live secret key**.
4. **Untick Test mode**. While test mode is ticked, the live key is ignored and the sandbox (`dev.khalti.com`) is used with the **Test secret key**. Enter the live key first: while the current mode has no key, Khalti is hidden at checkout and unpaid Khalti orders are **not** cancelled automatically (the website cannot check them). Payments started in test mode are not checked once live mode is on (and the reverse); each such order gets a note saying which Khalti dashboard to check.
5. Tick **Enable Khalti** and click **Save changes**. Khalti only appears at checkout when the store currency is NPR and the key for the current mode is filled in.
6. Place a real low-value order with Khalti (Khalti's minimum is NPR 10) and confirm the order moves to **Processing**. Refund it in the Khalti merchant dashboard afterwards.

**Sandbox testing.** Sign up as a merchant at https://test-admin.khalti.com (login OTP `987654`), copy the test secret key into **Test secret key** and keep **Test mode** ticked. On Khalti's test payment page use one of the test Khalti IDs `9800000000`–`9800000005`, MPIN `1111` and OTP `987654`.

Do not install or activate the third-party plugin `integrate-khalti-in-wc-store`: it has no block checkout support, and two Khalti gateways would confuse staff. The storefront payment copy (trust strip, footer, delivery text) mentions Khalti automatically once our gateway is enabled and has a key.

A Khalti automatic payment check (`bsn_khalti_reconcile`, group `bsn`) runs every 5 minutes, like the eSewa one; see "Scheduled tasks" above. Failed lookups are logged as lines starting "Bookstore Nepal Khalti:".

### Cash on delivery

Enabled by the first `wp bsn setup` run. Later runs do not touch it, so if the owner switches it off or changes its title, it stays that way. Check it under **WooCommerce › Settings › Payments** if needed.

## 4. Final checks before launch

- Place one order each with cash on delivery to a Kathmandu Valley address and to another district. Check the delivery price, the order email and the order in **WooCommerce › Orders**.
- Place an order of NPR 2,000 or more (or the client's free-delivery amount) and confirm delivery is free.
- Confirm an out-of-stock book cannot be added to the cart.
- Confirm the `bsn_esewa_reconcile` and `bsn_khalti_reconcile` scheduled actions are pending and have recent completed runs (see "Scheduled tasks" in section 3).
- Place one Khalti order (real, low value, then refund it) and confirm it moves to **Processing**.
- Confirm the site is **Live** (Site visibility), HTTPS works with no mixed-content warnings, and the footer and information pages have no `[CLIENT ...]` placeholders.
- Confirm the checkout shows working links to the Terms & conditions and Privacy policy, and that the Privacy, Terms and Returns pages were reviewed with the client.
- Delete or set to draft any sample or test books and test orders.

## 5. Backups

- **Daily** automatic backups of the database and `wp-content/` (uploads, plugins, theme).
- Store copies **off-site**: a different provider or cloud storage bucket, not only on the web server. Keep at least 14 daily copies.
- Take an extra backup immediately before any update, and before the site goes live.
- Test a restore on a staging copy at least once, and after any hosting change.
- A plugin such as UpdraftPlus or the host's own backup tool is fine, as long as it can send copies off-site.

## 6. Monthly update routine

1. Take a backup (section 5).
2. On a staging copy if available, update WordPress core, WooCommerce and other plugins and the Twenty Twenty-Five parent theme (`wp core update`, `wp plugin update --all`, `wp theme update --all`). Check the site, then repeat on the live site. Update WooCommerce on its own, not in the same batch as other plugins.
3. After updating, run through the checks in section 4 (at minimum one cash on delivery order, and an eSewa order if keys are live).
4. Review **WooCommerce › Status** for warnings, and check there are no PHP errors in the server log.
5. Check that low-stock and order emails are still being delivered.
6. Remove unused plugins and users. Make sure admin accounts have strong passwords.
7. Deploy any new version of `bookstore-nepal-core` or `bookstore-nepal` by copying the updated folders from the repo, then re-run `wp bsn setup` (without rate flags, so rates are untouched; the owner's payment, stock, checkout and country settings are also left alone) and `wp rewrite flush --hard` on Apache.
