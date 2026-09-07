# Bulk List Import for WooCommerce

Turn a raw pasted product list into reviewable draft products — with continuous
SKUs and AI-written, SEO-oriented descriptions.

**Stop typing. Start reviewing.**

---

## The problem

Adding products to WooCommerce by hand is one of the most tedious jobs in
e-commerce. For every single item you repeat the same loop:

```
read product name -> write description -> copy -> open WordPress ->
Add New Product -> paste long description -> paste short description ->
set price -> find the last SKU -> increment it -> Save Draft -> repeat
```

That is roughly 3–5 minutes per product. For a 100-product catalogue it's a
full working day, and it's the kind of repetitive work where mistakes creep in:
duplicate entries, skipped SKUs, inconsistent descriptions.

## What this plugin does

Paste your supplier's list exactly as you received it — messy formatting,
inconsistent dashes, mixed units and all:

```
Samsung 55" QLED TV — ₦450,000
iPhone 15 Pro 256GB — $999
Organic Basmati Rice — 5kg — ₦12,000
Office Chair, Ergonomic Mesh — ₦75,000
```

The plugin parses it into a structured preview table, works out the next SKU in
your existing sequence, generates SEO-oriented descriptions, and creates
everything as drafts for you to review.

You still control images, categories, and publishing. What disappears is the
typing.

## What makes it different

Most AI product tools work *downstream* of product creation — they write
descriptions for products that already exist in your store. This one starts a
step earlier, and does three things they don't:

**Parses messy real-world text.** Supplier lists arrive as WhatsApp messages
and copy-pasted spreadsheets, not clean CSVs. The parser handles inconsistent
separators, mixed units, embedded specs, and missing fields — and tells you
where it wasn't sure.

**Continues your existing SKU sequence.** It reads the highest SKU already in
your store and carries on from there. No manual hunting, no accidental
collisions.

**Refuses to invent facts.** If the AI doesn't reliably recognise a product, it
says so and asks you for the details instead of generating confident, plausible,
wrong copy. For anyone selling regulated goods — alcohol, pharmacy, supplements,
electrical — an invented spec is a liability, not a typo.

## Works with any product category

There is no product taxonomy in this plugin. The parser matches units,
price-like numbers, separators and leftover text — nothing about it is specific
to a category. Electronics, fashion, pharmacy, groceries, building materials,
books, drinks: all the same code path.

Units recognised include volume, weight, digital storage, length, power,
display size, count and apparel sizing. Products with no unit at all are
completely normal and handled as such.

## Status

Early development. Not yet released.

| Phase | Scope | Status |
|---|---|---|
| 1 | Plugin skeleton, admin menu | Complete |
| 2 | Parser and preview table | Complete |
| 3 | Unit generalisation, product creation, import report | Complete |
| 4 | AI layer — recognition gate and description generation | In progress |
| 5 | Hardening, error recovery, rate limiting, i18n | Planned |

Phases 1–3 work with no AI at all: paste a list, review it, create draft
products with correct SKUs. The AI layer builds on top of that foundation.

## Requirements

- WordPress 6.2+
- WooCommerce (any recent version — HPOS compatible)
- PHP 8.0+
- An API key from a supported AI provider (Phase 4 onward)

## Installation (development)

```bash
git clone git@github.com:errrr-404/Product.ai.git
```

Copy the `bulk-list-import` folder into `wp-content/plugins/` and activate it
from the WordPress admin. The import screen appears under **Products → Bulk
List Import**.

## Design principles

**Draft by default.** Nothing is ever auto-published. Every generated product
lands as a draft tagged `needs-review`.

**Every row is accounted for.** After an import you get a report showing what
happened to every single line you pasted — created, skipped, or failed, each
with a specific reason. A row never disappears silently.

**Warn, don't forbid.** Large imports get a warning about review fatigue, not a
hard block. Uncertain rows get flagged, not deleted. The user stays in control.

**Uncertainty is surfaced, not hidden.** Where the AI isn't confident about a
specific detail, that field is marked for verification rather than presented as
fact.

## Development

```bash
# Lint against WordPress Coding Standards
composer run lint

# Run the parser fixture suite
php tests/test-parser-fixtures.php
```

The fixture suite is the regression safety net for the parser. When you find a
product line that parses badly, add a fixture for it rather than special-casing
the parser.

## License

GPL-2.0-or-later
