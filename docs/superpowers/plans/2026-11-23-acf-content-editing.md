# Editing the shop's wording with ACF Pro: implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every client-editable word on the site lives in plain ACF Pro fields; the block editor and Site Editor are out of the client's way; the site renders identically with defaults.

**Architecture:** The core plugin owns the field schema, ACF registration, safe readers, pre-fill and the editor switches (`plugins/bookstore-nepal-core/src/Content/`). The theme owns the default wording (`themes/bookstore-nepal/inc/Wording.php`), hands it to the plugin through the `bsn_content_defaults` filter, and its patterns print `Wording::text()/rich()/rows()` instead of hard-coded strings. Markup and classes do not change.

**Tech Stack:** WordPress 6.x block theme, WooCommerce, ACF Pro (runtime only), PHPUnit 9 with the WP test suite (`vendor/bin/phpunit`).

**Spec:** `docs/superpowers/specs/2026-11-23-acf-content-editing-design.md`

## Global Constraints

- ACF Pro is never required for the site to render: every reader returns the default when ACF is inactive, the field is unknown, its page is unset, or the stored value is empty or whitespace-only.
- Field groups are registered in PHP with `acf_add_local_field_group` on `acf/init`; nothing is created through the ACF admin UI.
- No raw-HTML, colour, class or layout fields. Field types used: `text`, `textarea`, `wysiwyg` (toolbar `basic`, `media_upload` 0), `repeater`.
- Rich output always passes `wp_kses_post( wpautop( $value ) )`; plain text is escaped at output with `esc_html` as today.
- Pre-fill never overwrites a field that has a non-empty value.
- Site Editor is hidden and `site-editor.php` redirects to the dashboard unless `define( 'BSN_SITE_EDITOR', true );`.
- All field names start with `bsn_`. Labels and instructions are plain shop-owner English.
- Front-end markup, CSS classes and `motion.js` targets stay identical; the existing 475 tests keep passing.
- Commits end with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Client pastes `<script>`, `<style>`, `onclick=` or an `<iframe>` into a rich box: stripped, rest of the text kept. (Task 1 test)
2. Front page not set, or a store page's option points at a deleted page: defaults render, no PHP notice. (Task 1 test)
3. A FAQ row with an empty question or empty answer: that row is skipped; only when no usable rows remain do the defaults show. (Task 1 test)
4. Pre-fill runs a second time after the client has edited some boxes: their edits survive, only still-empty boxes are filled. (Task 5 test)
5. A store page someone already detached and rewrote in blocks: their text lands in the page's first box and nothing is lost. (Task 5 test)

---

## Field schema (used by every task)

Group key → location → fields (name, type). Labels are the builder's call; keep them in page order.

| Group | Location | Fields |
|---|---|---|
| `home` | `front_page` | `bsn_hero_pill` text, `bsn_hero_headline` text, `bsn_hero_lead` textarea, `bsn_hero_button_ages` text, `bsn_hero_button_gift` text, `bsn_hero_book_1` / `_2` / `_3` text, `bsn_ages_heading` text, `bsn_age_0_3_title` / `bsn_age_0_3_text`, `bsn_age_4_7_title` / `bsn_age_4_7_text`, `bsn_age_8_12_title` / `bsn_age_8_12_text` text, `bsn_new_heading`, `bsn_new_more`, `bsn_best_heading`, `bsn_best_more`, `bsn_featured_heading`, `bsn_featured_more` text, `bsn_gift_heading` text, `bsn_gift_text` textarea, `bsn_trust_delivery` text |
| `about` | `page:about` | `bsn_about_text` wysiwyg |
| `contact` | `page:contact` | `bsn_contact_intro` wysiwyg, `bsn_contact_after` wysiwyg |
| `delivery` | `page:delivery` | `bsn_delivery_intro` wysiwyg, `bsn_delivery_times` wysiwyg, `bsn_delivery_after` wysiwyg |
| `returns` | `page:returns` | `bsn_returns_text` wysiwyg |
| `privacy` | `page:privacy` | `bsn_privacy_text` wysiwyg |
| `terms` | `page:terms` | `bsn_terms_text` wysiwyg |
| `faq` | `page:faq` | `bsn_faq_intro` wysiwyg, `bsn_faq_items` repeater of `heading` text (optional: "Heading above this question"), `question` text, `answer` textarea |
| `settings` | `options` | `bsn_settings_contact_hint` message (read-only hint, no stored value), `bsn_footer_about` textarea, `bsn_footer_help_heading` text |

The first field of each store-page group is that page's "first box" (Task 5).
`page:<key>` keys are those of `StorePages::definitions()`; the page ID is `(int) get_option( $def['option'] )`.

---

### Task 1: Content readers, schema and ACF test stubs

**Files:**
- Create: `plugins/bookstore-nepal-core/src/Content/Fields.php` (schema only in this task)
- Create: `plugins/bookstore-nepal-core/src/Content/Content.php`
- Create: `tests/Support/acf-stubs.php`
- Modify: `tests/bootstrap.php` (require the stubs before the WP bootstrap)
- Test: `tests/Unit/ContentTest.php`

**Interfaces:**
- Produces:
  - `Fields::VERSION` (string const, `'1'`); `Fields::schema(): array<string, array{title:string, location:string, fields:list<array{name:string,label:string,type:string,instructions?:string,sub_fields?:list<array>}>}>` per the table above.
  - `Content::acf_active(): bool` = `function_exists( 'get_field' ) && function_exists( 'update_field' ) && (bool) apply_filters( 'bsn_acf_active', true )`.
  - `Content::location_of( string $field ): ?string`; `Content::post_id_for( string $location ): int|string|null` (`front_page` → `page_on_front` when `show_on_front` is `page` and the page is published; `page:<key>` → the StorePages option ID when it is a published page; `options` → `'option'`; else `null`).
  - `Content::text( string $field, string $default ): string`, `Content::rich( string $field, string $default_html ): string`, `Content::rows( string $field, array $default_rows ): list<array<string,string>>`.
  - `Content::managed_page_ids(): list<int>` (front page + every store page that resolves).
- Stubs (`tests/Support/acf-stubs.php`, global functions guarded by `function_exists`): `get_field( $name, $post_id = false, $format = true )` and `update_field( $name, $value, $post_id )` storing in post meta, or in option `options_{$name}` when `$post_id === 'option'`; `acf_add_local_field_group( array $g )` and `acf_add_options_page( array $p )` appending to `$GLOBALS['bsn_acf_registered']['groups'|'pages']`.

- [ ] **Step 1: Write failing tests** in `tests/Unit/ContentTest.php`:
  - `test_text_falls_back_when_empty_whitespace_or_acf_inactive`: unset → default; `update_field( 'bsn_hero_headline', '   ', $front )` → default; value `'Hello'` → `'Hello'`; with `add_filter( 'bsn_acf_active', '__return_false' )` → default.
  - `test_unknown_field_returns_default`.
  - `test_missing_front_page_or_deleted_store_page_returns_default`: `show_on_front` = `posts` → default; `bsn_page_about` pointing at a trashed page → default; no notices (`$this->expectNotToPerformAssertions` not used; assert values).
  - `test_rich_strips_dangerous_markup`: stored `<p>Hi <strong>there</strong></p><script>x()</script><p onclick="y()">Ok</p><iframe src="//e.com"></iframe>` → contains `<strong>there</strong>` and `Ok`; does not contain `script`, `onclick`, `iframe`.
  - `test_rich_adds_paragraphs_to_plain_lines`: `"One\n\nTwo"` → contains `<p>One</p>` and `<p>Two</p>`.
  - `test_rows_skip_incomplete_and_fall_back_when_none_usable`: rows `[ [question=>'Q1',answer=>'A1'], [question=>'',answer=>'A2'], [question=>'Q3',answer=>' '] ]` → one row `Q1`; rows all incomplete → `$default_rows`.
  - `test_schema_field_names_are_unique_prefixed_and_first_store_fields_match`: every name unique and starts `bsn_`; first field of `about` is `bsn_about_text`, of `faq` is `bsn_faq_intro`.
  - `test_managed_page_ids_lists_front_and_store_pages`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter ContentTest` → FAIL (class not found).
- [ ] **Step 3: Implement** `Fields::schema()` and `Content` in the files above; write the stubs and require them in `tests/bootstrap.php` before `require $bsn_tests_dir . '/includes/bootstrap.php'`.
- [ ] **Step 4: Run** `vendor/bin/phpunit` → all pass.
- [ ] **Step 5: Commit** `feat: content readers and field schema for ACF wording`.

### Task 2: Register field groups and the Shop settings page

**Files:**
- Modify: `plugins/bookstore-nepal-core/src/Content/Fields.php`
- Modify: `plugins/bookstore-nepal-core/bookstore-nepal-core.php` (`add_action( 'bsn_booted', array( \BookstoreNepal\Content\Fields::class, 'register' ) );`)
- Test: `tests/Unit/ContentFieldsTest.php`

**Interfaces:**
- Consumes: `Fields::schema()`, `Content::post_id_for()`.
- Produces: `Fields::register(): void` (hooks `acf/init`), `Fields::register_groups(): void`, `Fields::acf_group( string $key ): array` (the array passed to `acf_add_local_field_group`).

- [ ] **Step 1: Failing tests:**
  - `test_register_groups_adds_one_group_per_schema_entry_and_the_options_page`: call `Fields::register_groups()`; `$GLOBALS['bsn_acf_registered']['groups']` has `count( Fields::schema() )` entries; `pages` has one with `menu_slug` `bsn-shop-settings`, `page_title` `Shop settings`, `capability` `manage_woocommerce`.
  - `test_store_page_group_targets_that_page_id`: `acf_group( 'about' )['location']` is `[ [ [ 'param' => 'page', 'operator' => '==', 'value' => (string) $about_id ] ] ]`.
  - `test_home_group_targets_front_page_type`: location param `page_type`, value `front_page`.
  - `test_settings_group_targets_options_page`: param `options_page`, value `bsn-shop-settings`.
  - `test_unresolved_store_page_group_is_skipped`: option points at nothing → that group is not registered.
  - `test_wysiwyg_fields_use_basic_toolbar_without_media`: every wysiwyg field has `toolbar` `basic`, `media_upload` `0`.
  - `test_field_keys_are_stable`: each field `key` is `field_` . name; group key `group_bsn_` . group key.
  - `test_settings_page_hints_where_contact_details_live`: the settings group has a `message` field whose `message` contains `WooCommerce › Settings › General`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter ContentFieldsTest` → FAIL.
- [ ] **Step 3: Implement** `register`, `register_groups`, `acf_group`; options page via `acf_add_options_page` only when that function exists, `position` 56, `icon_url` `dashicons-store`. Groups get `hide_on_screen` `[ 'the_content' ]`.
- [ ] **Step 4: Run** `vendor/bin/phpunit` → all pass.
- [ ] **Step 5: Commit** `feat: register ACF field groups and Shop settings page`.

### Task 3: Theme wording defaults; home page and footer read fields

**Files:**
- Create: `themes/bookstore-nepal/inc/Wording.php`
- Modify: `themes/bookstore-nepal/functions.php` (require it), `themes/bookstore-nepal/inc/Theme.php` (`register()` adds `add_filter( 'bsn_content_defaults', array( Wording::class, 'defaults' ) )`; add `Theme::html( array $items ): string`)
- Modify patterns: `hero.php`, `shop-by-age.php`, `new-arrivals.php`, `best-sellers.php`, `featured-books.php`, `gift-finder.php`, `trust-strip.php`, `footer-about.php`, `footer-help.php`
- Test: `tests/Unit/WordingTest.php`

**Interfaces:**
- Consumes: `Content::text/rich/rows` (only via `class_exists( '\BookstoreNepal\Content\Content' )`).
- Produces (namespace `BookstoreNepal\Theme`): `Wording::defaults(): array<string, string|list<array<string,string>>>` keyed by every schema field name; `Wording::text( string $field ): string`, `Wording::rich( string $field ): string`, `Wording::rows( string $field ): list<array<string,string>>` (default from `defaults()`, delegating to `Content` when present); `Theme::html( array $items ): string` = `Theme::blocks( $items )` with every `<!-- wp:… -->` / `<!-- /wp:… -->` comment removed.

- [ ] **Step 1: Failing tests:**
  - `test_defaults_cover_every_schema_field`: `array_keys( Wording::defaults() )` equals all field names from `Fields::schema()` (sorted), excluding `message` fields.
  - `test_defaults_match_current_wording`: `Wording::defaults()['bsn_hero_headline'] === 'Books that grow with your child'`, `['bsn_age_4_7_title'] === 'Early readers'`, `['bsn_best_more'] === 'See all best sellers'`, `['bsn_footer_about'] === "Children's books in Nepali and English, delivered all over Nepal."`.
  - `test_hero_renders_field_value_and_escapes_it`: `update_field( 'bsn_hero_headline', 'Read <b>more</b>', $front )`; rendered hero pattern contains `Read &lt;b&gt;more&lt;/b&gt;`.
  - `test_home_patterns_render_defaults_unchanged_when_fields_empty`: render each modified pattern with no fields; output equals the output captured before the change (snapshot the current output in the test as literal substrings: `bsn-hero__book--2">हिमालको कथा<`, `<h2 class="wp-block-heading">Shop by age</h2>`, `bsn-age-tile--sky`).
  - `test_html_strips_block_comments_keeps_markup`: `Theme::html( [ [ 'h2', 'A' ], [ 'p', 'B' ] ] )` has no `<!--` and contains `<h2 class="wp-block-heading">A</h2>` and `<p>B</p>`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter WordingTest` → FAIL.
- [ ] **Step 3: Implement** `Wording` (move the existing translatable strings out of the listed patterns into `defaults()`, still wrapped in `__()`), then replace each hard-coded string in those patterns with `esc_html( Wording::text( '…' ) )`. Do not change markup.
- [ ] **Step 4: Run** `vendor/bin/phpunit` → all pass, including the existing ThemeTest render tests.
- [ ] **Step 5: Commit** `feat: home page and footer wording from editable fields`.

### Task 4: Store pages read fields, in today's order

**Files:**
- Modify: `themes/bookstore-nepal/inc/Wording.php` (page defaults)
- Modify patterns: `page-about.php`, `page-contact.php`, `page-delivery.php`, `page-returns.php`, `page-privacy.php`, `page-terms.php`, `page-faq.php`
- Test: `tests/Unit/WordingPagesTest.php`

**Interfaces:**
- Consumes: `Wording::rich/rows`, `Theme::html`.
- Produces: page defaults in `Wording::defaults()` for every `page:*` field: rich defaults are `Theme::html()` of today's items for that stretch of the page; `bsn_faq_items` default is a list of `['heading'=>…, 'question'=>…, 'answer'=>…]` built from today's FAQ (headings `Choosing books` / `Returns and help` on the first row of their group; eSewa / cash-on-delivery rows only when `Theme::payment_methods()` has them).

Page layout after this task (automatic parts unchanged, in brackets):
- About / Returns / Privacy / Terms: `<div class="bsn-info">` + rich box.
- Contact: intro box (intro + "Get in touch" heading) → [`bsn-contact` group with contact-details pattern] → after box (Opening hours, Already placed an order?).
- Delivery: intro box (intro + "Delivery charges" heading) → [delivery-rates pattern] → times box ("The charge is worked out…", "How long delivery takes" list, remote-districts line) → ["Paying for your order" heading + payment paragraphs, automatic as today] → after box (Following your order … Something wrong…).
- FAQ: intro box → each row as today's `qa` details, a row's `heading` printed as an `h2` before it.

- [ ] **Step 1: Failing tests:**
  - `test_store_pages_render_same_text_with_empty_fields`: for each of the seven pages, rendered pattern output (block comments stripped, whitespace collapsed) contains today's key sentences: about `We are a bookshop for young readers.`, delivery `We deliver to all 77 districts of Nepal`, returns `When you can send a book back`, privacy `Individual Privacy Act, 2075 (2018)`, terms `By placing an order with us`, contact `Opening hours`, faq `Choosing books`.
  - `test_delivery_keeps_rates_table_between_intro_and_times`: positions: `Delivery charges` < rates-table marker (`bsn-rates` class or the delivery-rates pattern output) < `How long delivery takes` < `Paying for your order` < `Following your order`.
  - `test_contact_keeps_details_between_boxes`: `Get in touch` < `bsn-contact` < `Opening hours`.
  - `test_faq_answer_about_delivery_cost_has_no_rupee_figures`: the default row for `How much does delivery cost` has an answer without `Rs` / `रु` / digits followed by a currency and with a link to the Delivery page.
  - `test_faq_rows_render_heading_once_before_its_question`: two rows, first with heading `Ordering` → one `<h2 class="wp-block-heading">Ordering</h2>` before the first `<details class="wp-block-details bsn-faq">`.
  - `test_client_text_replaces_default`: `update_field( 'bsn_about_text', '<p>Our own words</p>', $about_id )` → render contains `Our own words` and not `We are a bookshop for young readers.`
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter WordingPagesTest` → FAIL.
- [ ] **Step 3: Implement** defaults and pattern changes; keep `StorePages` untouched (pages still hold their pattern block).
- [ ] **Step 4: Run** `vendor/bin/phpunit` → all pass (update any existing StorePagesTest/ThemeTest assertion only if it pinned block comments inside page text; note each such change in the commit message).
- [ ] **Step 5: Commit** `feat: store pages read their wording from editable fields`.

### Task 5: Pre-fill the fields once

**Files:**
- Create: `plugins/bookstore-nepal-core/src/Content/Prefill.php`
- Modify: `plugins/bookstore-nepal-core/bookstore-nepal-core.php` (`add_action( 'bsn_booted', array( \BookstoreNepal\Content\Prefill::class, 'register' ) );`)
- Modify: `plugins/bookstore-nepal-core/src/Cli/SetupCommand.php` (run pre-fill after `StorePages::ensure_all()`; log `Wording fields: N filled` or a `WP_CLI::warning` "ACF Pro is not active, so the wording fields were not filled. Install and activate ACF Pro, then run wp bsn setup again." — never an error)
- Test: `tests/Unit/PrefillTest.php`, extend `tests/Unit/SetupCommandTest.php`

**Interfaces:**
- Consumes: `Fields::schema()`, `Fields::VERSION`, `Content::acf_active()`, `Content::post_id_for()`, `StorePages::definitions()`, `StorePages::content()`, filter `bsn_content_defaults`.
- Produces: `Prefill::OPTION = 'bsn_content_prefilled'`; `Prefill::register(): void` (hooks `maybe_run` on `acf/init` priority 20); `Prefill::maybe_run(): void` (runs when ACF is active and the option is not `Fields::VERSION`, then sets it); `Prefill::run(): int` (number of fields written).

- [ ] **Step 1: Failing tests:**
  - `test_run_fills_only_empty_fields`: set `bsn_hero_headline` to `Mine`; `run()`; headline still `Mine`; `bsn_hero_lead` now equals its default.
  - `test_second_run_keeps_client_edits`: `run()`, change `bsn_about_text` to `<p>Edited</p>`, `run()` again → still `<p>Edited</p>`; second run returns `0`.
  - `test_detached_page_text_moves_into_first_box`: set the About page's `post_content` to `<!-- wp:paragraph --><p>Our own story</p><!-- /wp:paragraph -->`; `run()` → `get_field( 'bsn_about_text', $about_id )` contains `<p>Our own story</p>` and no `<!-- wp:`; the page's `post_content` is now `StorePages::content( 'about', … )`.
  - `test_untouched_page_gets_default_not_its_pattern_block`.
  - `test_maybe_run_runs_once_per_version_and_not_without_acf`: with `bsn_acf_active` false → nothing written, option unset; with it true → written, option `Fields::VERSION`; second call writes nothing.
  - `test_unresolved_locations_are_skipped`: front page unset → home fields not written, no notice.
  - `test_setup_warns_without_acf_and_fills_with_it` (SetupCommandTest): `run()` report includes the warning text when inactive; with ACF active the hero headline is filled.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter 'PrefillTest|SetupCommandTest'` → FAIL.
- [ ] **Step 3: Implement.** Repeater defaults are written as the row arrays. "Detached" means `trim( post_content )` is non-empty and differs from `StorePages::content( $key, $def['title'] )`; strip block comments with the same regex as `Theme::html` before saving.
- [ ] **Step 4: Run** `vendor/bin/phpunit` → all pass.
- [ ] **Step 5: Commit** `feat: pre-fill wording fields once, keeping any existing edits`.

### Task 6: Block editor off for managed pages; Site Editor hidden

**Files:**
- Create: `plugins/bookstore-nepal-core/src/Content/EditorSwitch.php`
- Modify: `plugins/bookstore-nepal-core/bookstore-nepal-core.php` (`add_action( 'bsn_booted', array( \BookstoreNepal\Content\EditorSwitch::class, 'register' ) );`)
- Modify: `docs/deployment.md` (logo step: add `define( 'BSN_SITE_EDITOR', true );` to wp-config.php first, remove it after)
- Test: `tests/Unit/EditorSwitchTest.php`

**Interfaces:**
- Consumes: `Content::managed_page_ids()`.
- Produces: `EditorSwitch::register(): void`; `EditorSwitch::filter_block_editor( bool $use, \WP_Post $post ): bool`; `EditorSwitch::hide_content_editor(): void` (on `load-post.php`: when the edited post ID is managed, `remove_post_type_support( 'page', 'editor' )`); `EditorSwitch::site_editor_allowed(): bool`; `EditorSwitch::remove_site_editor_menu(): void` (`admin_menu` priority 999, `remove_submenu_page( 'themes.php', 'site-editor.php' )`); `EditorSwitch::block_site_editor(): void` (on `load-site-editor.php`: `wp_safe_redirect( admin_url() ); exit;` unless allowed).

- [ ] **Step 1: Failing tests:**
  - `test_block_editor_off_only_for_managed_pages`: front page and About → `false`; another page → unchanged `true`; a product → unchanged.
  - `test_site_editor_menu_removed_unless_constant`: build `$GLOBALS['submenu']['themes.php']` with a `site-editor.php` entry; call `remove_site_editor_menu()` → gone. (Constant case: assert `site_editor_allowed()` reads `BSN_SITE_EDITOR` via a filter `bsn_site_editor_allowed` defaulting to `defined( 'BSN_SITE_EDITOR' ) && BSN_SITE_EDITOR`, and with the filter true the entry stays.)
  - `test_hooks_registered`: `has_filter( 'use_block_editor_for_post', … )`, `has_action( 'load-post.php', … )`, `has_action( 'admin_menu', … )`, `has_action( 'load-site-editor.php', … )`.
- [ ] **Step 2: Run** `vendor/bin/phpunit --filter EditorSwitchTest` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** `vendor/bin/phpunit` → all pass.
- [ ] **Step 5: Commit** `feat: hide the block and site editors from the shop's wording pages`.

### Task 7: Admin guide, and the real check with ACF Pro

**Files:**
- Modify: `docs/admin-guide.md` (replace section 8 "Shop information pages"; add "Change the home page wording" and "Shop settings"; warn that links inside page text stop working if a page's web address is changed)

- [ ] **Step 1: Rewrite the guide sections** in the existing guide's voice and numbered-steps style. No screenshots until ACF Pro is installed.
- [ ] **Step 2: Commit** `docs: admin guide for editing the shop's wording`.
- [ ] **Step 3 (blocked until the user supplies the ACF Pro zip or licence key):** install and activate ACF Pro on `~/Herd/bookstore-nepal`, run `wp bsn setup`, then for Home, About, Delivery, FAQ and Shop settings: change one box, save, and check the change at 1280px and 320px; check the block editor does not open for those pages and Appearance › Editor is gone; deactivate ACF and check every page still shows its wording. Add screenshots to `docs/admin-guide/` and commit `docs: admin guide screenshots for wording screens`.
