# Rolling Donut Box Builder

An interactive **Build Your Own Box** UI layered on top of **WPC Product Bundles
for WooCommerce** (`woosb`). It turns a flagged bundle product into a drag-and-drop
box builder while WPC keeps owning the cart, pricing, validation, order lines and
emails.

This is the first phase of replacing the legacy `box-builder-woo` plugin. The
goal is to keep the reliable third-party bundle engine and add our own improved
UI (and, in later phases, the extra features) on top of it.

## How it works (phase 1: no custom cart code)

WPC renders an editable quantity input (`.woosb-qty`) for every **optional**
bundle item and recalculates the bundle total + add-to-cart state whenever that
input fires a `change` event. This plugin:

1. Renders a **Build Your Own Box** toggle above the bundle, plus a box grid and
   a flavour picker (hidden until toggled).
2. Reads the rendered `.woosb-product` nodes to build a friendlier UI.
3. On add / remove / drag-and-drop, writes the new value into the matching
   `.woosb-qty` input and triggers `change` — WPC does the rest.

No cart, price or order code lives here in phase 1. See
`includes/class-cart.php` for the additive-hook contract reserved for later
phases (logo upload, special occasion, stands, etc.).

## Requirements

- WooCommerce
- WPC Product Bundles for WooCommerce (`woo-product-bundle` / Premium)

The plugin no-ops with an admin notice if either is missing.

## Per-product setup

For each box you want to be customizable:

1. Create/edit the product as **Product type: Product Bundle**.
2. Add every selectable donut **variation** (e.g. "Vanilla Glaze - large") as a
   bundled item and mark each one **Optional** (this is what makes WPC render the
   editable quantity input the builder drives). Set sensible default quantities
   if the box should start pre-filled.
3. Set **Limit the whole quantities** min and max to the box size (e.g. `12` and
   `12`) so an exact box is enforced.
4. Use a **fixed price** = the box price.
5. Tick **Enable Box Builder** (General tab).

If a flagged product is missing optional items or a whole-quantity limit, an
admin notice on the edit screen explains what to fix.

## Files

- `rd-box-builder.php` — bootstrap + dependency checks.
- `includes/helpers.php` — `rd_box_builder_is_enabled()`, capacity/placeholder,
  config validation.
- `includes/class-flag.php` — the per-product checkbox + setup notice.
- `includes/class-render.php` — toggle + box + picker markup (via woosb hooks).
- `includes/class-assets.php` — conditional JS/CSS/SortableJS enqueue.
- `includes/class-cart.php` — phase-1 no-op; documents the later-phase hook contract.
- `includes/class-stats.php` — first-party usage counters + admin columns.
- `assets/js/rd-box-builder.js` — the skin behaviour.
- `assets/css/rd-box-builder.css` — the skin styles (black/gold, mobile-first).

## Usage tracking (no third-party service)

Two per-product events are counted with no external analytics:

- **Box opens** — each click of "Build Your Own Box" to enter builder mode.
- **Box adds** — each configured box added to the basket (includes Buy Now).

Counts are stored in product meta (`_rd_bb_opens` / `_rd_bb_adds`) and incremented
atomically over an authenticated AJAX endpoint (`includes/class-stats.php`), so
concurrent shoppers never clobber each other's tally. They surface two ways:

- **Products admin list** — sortable **Box opens** / **Box adds** columns.
- **WP-CLI** — `wp rd-box-builder stats` (add `--format=csv` to export).

Only products with **Enable Box Builder** ticked are counted; a spoofed product
id is rejected server-side. Deleting `class-stats.php` stops counting and removes
the columns with no other effect.

## Filters

- `rd_box_builder_placeholder_url` — image used for empty box slots.
- `rd_box_builder_after_box` / `rd_box_builder_after_picker` — render slots for
  future add-ons.

## Roadmap

1. Port `box-builder-woo` features as thin additive hooks on the woosb parent line.
2. Migrate existing `donut_box_builder` products to flagged `woosb` bundles.
3. Retire the bespoke theme template + type branch.
4. Deactivate and delete `box-builder-woo`.
