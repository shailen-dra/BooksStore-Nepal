# Editing the shop's words with ACF Pro: design

Date: 2026-11-23
Status: draft, awaiting approval

## Why

The client finds the WordPress block editor confusing. Today almost every word on the site lives somewhere a
shop owner cannot comfortably reach:

- Home page wording (hero, Shop by age tiles, section headings, gift finder, trust strip) is fixed in theme
  patterns (`themes/bookstore-nepal/patterns/*.php`). Changing it means the Site Editor.
- The seven store pages (About, Contact, Delivery, Returns, Privacy, Terms, FAQ) are pages whose content is a
  single `wp:pattern` block (`plugins/bookstore-nepal-core/src/StorePages.php`). Editing means "Detach
  pattern" and then working in blocks.
- Footer wording is in theme patterns (`footer-about.php`, `footer-help.php`, ...).
- Contact details already come from WooCommerce › Settings › General (`Theme::contact_details()`), which is
  simple but hard to find.

Success: the client can change any wording on the site by filling in labelled boxes, like a form, and never
sees a block, a pattern or the Site Editor.

## Goals

1. Every piece of shop wording the client might want to change is an ACF field with a plain-English label and
   a short hint.
2. The block editor does not appear for the Home page or the store pages; those screens show only ACF fields.
3. The Site Editor (Appearance › Editor) is hidden from the admin menu so the client is not lured into it.
4. Fields open pre-filled with the current wording, so the client edits words rather than starts from blank.
5. An empty field never leaves a hole: the built-in default wording shows instead.
6. The site looks and behaves exactly as it does now: same design, animations (`feat/playful-motion`),
   sliders, accessibility work, and tests keep passing.
7. `docs/admin-guide.md` section 8 (and a new "Change the home page wording" section) explains the new screens.

## Non-goals

- Rebuilding the theme as classic PHP templates. The block templates stay; the client never sees them.
- Books/products: they already use WooCommerce's classic product screen plus our own book fields. Unchanged.
- Navigation menus, colours, fonts, layout or section order: not editable by the client in this change.
- An announcement bar or any other new section.
- Multilingual content. The site has no translations today; fields hold one language (the client may type
  Nepali or English in any field).

## Anti-goals (would make this a failure even if it works)

- Any page showing blank sections or "[field missing]" because a field is empty or ACF is deactivated.
- The client being able to break the layout from the new screens (no raw HTML fields, no colour or
  class fields).
- Facts that WooCommerce already knows (delivery rates, which payment methods are on, phone, email,
  WhatsApp, address) being retyped in ACF, where they would drift out of date.

## Constraints

- ACF Pro is required (repeaters, options page). The client buys the licence (about USD 49/year). For local
  development the developer needs the ACF Pro plugin zip or licence key; until then the code is written
  against the ACF Pro API and tested with ACF functions stubbed in the unit tests.
- Field groups are registered in PHP (`acf_add_local_field_group`) inside the core plugin, so they are in git,
  reviewed and tested, and cannot be accidentally deleted in the ACF admin.
- If ACF is inactive, the site still renders using default wording (goal 5); nothing fatal.
- All output stays escaped as today. Rich-text fields go through `wp_kses_post`.

## Approaches considered

- **A. Keep the block theme; move the words into ACF fields (chosen).** Patterns read fields with the current
  wording as fallback. Block editor switched off for the Home page and store pages. Design, animations and
  tests untouched. A few days of work.
- B. Rebuild as a classic PHP theme with ACF. The client sees the same field screens as in A, but every
  template, the sliders and the motion work must be redone. Weeks of work for no visible gain.
- C. No ACF; lock the blocks and hide the Site Editor. Cheapest, but the client still edits inside the block
  editor, which is the problem.

The user chose A with ACF Pro.

## Design

### Where the client edits

| Admin screen | What it holds |
|---|---|
| **Pages › Home** (block editor off) | Hero, Shop by age, section headings, gift finder, trust strip wording |
| **Pages › About us / Delivery / Returns / Privacy / Terms / Contact** (block editor off) | "Page text": one rich-text box (bold, links, lists, headings), pre-filled |
| **Pages › Frequently asked questions** (block editor off) | Intro text + a list of Question / Answer pairs (repeater, add/remove/reorder) |
| **Shop settings** (new ACF options page) | Footer "about" line, footer help text |

Contact details stay in WooCommerce › Settings › General (single source of truth), and the Shop settings page
shows a link and a one-line hint pointing there.

### Home page fields

- Hero: small pill text, headline, intro paragraph, button 1 label, button 2 label. The three decorative book
  titles in the hero (`.bsn-hero__books`) become three short text fields.
- Shop by age: section heading; for each of the three tiles (0–3, 4–7, 8–12) a title and a description.
  Fixed at three because each tile links to an age-group attribute; no add/remove.
- New arrivals / Best sellers / Featured: section heading and "See all" link label each. Which books appear
  stays automatic (and Featured still follows the WooCommerce "featured" star, per admin guide section 6).
- Gift finder: heading and intro sentence.
- Trust strip: the delivery line text. Payment lines stay automatic from enabled gateways.

### Store pages

Pages keep today's order. Where a page has an automatic part in the middle (one WooCommerce already knows
about), the client's text is split into labelled boxes around it, so the automatic part stays where it is:

- About, Returns, Privacy, Terms: one "Page text" box.
- Delivery: "Text above the delivery charges", [automatic rates table], "Text about delivery times",
  [automatic "Ways to pay" paragraphs, built from the enabled gateways as today], "Text below payments".
- Contact: "Text above contact details", [automatic phone / WhatsApp / email / address], "Text below contact
  details" (opening hours, "Already placed an order?").
- FAQ: intro text + the client's question list. Answers that quote live figures today (delivery charges) are
  rewritten in the defaults to point at the Delivery page instead of quoting numbers, so they never go stale.

Default wording for each box is today's pattern text for that stretch of the page. Links inside pre-filled
text (Track your order, Returns, Contact) are written in as the pages' current addresses; they keep working
unless a page's address is changed later, which the admin guide will warn about. (Builder's call: accepted,
because the alternative is placeholder codes in the client's text.)

### How it is wired

- Core plugin, new `src/Content/` area:
  - `Fields` registers the field groups and the options page on `acf/init`, targeting the front page and the
    store pages by the IDs held in the `page_on_front` and `StorePages::definitions()` options.
  - `Content::get( $key, $default )` reads a field, returns the default when ACF is off or the field is empty.
    Patterns call this instead of `__( '...' )` for client-editable wording; the default stays a translatable
    string.
  - `EditorSwitch` filters `use_block_editor_for_post` to false and removes post-type `editor` support display
    for those page IDs, and removes the Site Editor submenu for users who are not developers (filterable).
- Theme patterns change only where they print wording; markup and classes stay identical so CSS, motion.js
  and existing render tests keep working.
- Store pages: the template renders the page's fields through a new `bookstore-nepal/store-page` pattern
  instead of the saved `wp:pattern` content. Existing page content is left in the database untouched.
- Pre-fill: the first time the site loads with ACF Pro active (on `acf/init`, remembered in an option so it
  runs once), and whenever `wp bsn setup` runs with ACF Pro active, the default wording is written into empty
  fields, so the client opens a filled form. It never overwrites a field that has a value. If a store page's
  saved content is no longer just the original pattern block (someone detached and edited it), that page's
  saved text goes into its first text box instead of the default, so no edit is lost. `wp bsn setup` prints a
  warning, not an error, when ACF Pro is missing.
- After pre-fill, the wording belongs to the client: later wording fixes in the theme only reach fields that
  are empty. (Builder's call: this is the point of the change.)
- Empty field = default wording, for every field. There is no "hide this line" switch; the client changes
  words, not layout. (Builder's call, from the anti-goal on breaking the layout.)
- Site Editor: the Appearance › Editor menu item is removed and `site-editor.php` redirects to the dashboard
  for everyone, unless `define( 'BSN_SITE_EDITOR', true );` is in wp-config.php (the developer switches it on
  when needed, e.g. to add the logo per docs/deployment.md, which gets a line about this). (Builder's call:
  client and developer are both Administrators, so roles cannot separate them.)

### Testing

- Unit tests with ACF functions stubbed: each field falls back to default when empty or when ACF is missing;
  rich text is kses-filtered; block editor is off for exactly the targeted pages; Site Editor menu removed;
  pre-fill never overwrites.
- Existing pattern render tests keep passing unchanged (same markup with default wording).
- Manual check with real ACF Pro on the local site once the zip is available: edit each screen, see it on the
  front end at desktop and phone width.

## Current state checked

The site is not live. On the local site every store page still holds only its original pattern block and
there are no saved template or template-part overrides, so no migration is needed now; the detached-page rule
above covers a later surprise.

## Left to the builder

- Exact field names/keys, labels and hint wording (plain English, shop-owner voice).
- Whether the options page sits under WooCommerce or as its own top-level menu item (prefer whichever is
  easier to find in screenshots for the admin guide).
- Order of fields on each screen (match the order things appear on the page).
