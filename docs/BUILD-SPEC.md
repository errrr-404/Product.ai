# Build Specification — Bulk List Import for WooCommerce

> Internal build spec. Read this before making changes to the codebase.
> Public-facing overview lives in `README.md`.

---

## Scope rule: this plugin is product-category agnostic

Some fixtures and examples below are drinks, because that's the real data the
parser was first tested against. **That is test coverage, not scope.** The
plugin must work equally well for a phone shop, a pharmacy, a fashion boutique,
a building-materials supplier, a bookshop, or a grocery.

Concretely:

- The parser must not assume bottle sizes. `cl`/`ml` are two units among many —
  it handles `kg`, `g`, `mg`, `GB`, `TB`, `cm`, `inch`/`"`, `pack`, `pcs`,
  `x 100`, apparel sizes, and **rows with no unit at all**.
- Internally the extracted unit field is a generic **variant/spec**, never
  "size".
- Prompts are category-neutral by default; the user supplies industry and tone.
- Nothing hardcodes vocabulary from any one industry — no "tasting notes", no
  "ABV", no "vintage" as fixed fields.

**If a change would only make sense for one industry, it's wrong.**

---

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Plugin skeleton, activation, admin menu | done |
| 2 | Parser + preview table, no DB writes | done |
| 3 | Unit generalisation, product creation, import report | done |
| 4 | AI layer: recognition gate + SEO generation, queued | next |
| 5 | Hardening: errors, retries, rate limits, i18n, security audit | pending |

**Minimum WordPress version is 6.2, and largely decorative.** WooCommerce is a
hard dependency and its own minimum WordPress version is higher, so that is the
effective floor. The figure still has to be honest: declaring 6.0 promises
support we cannot deliver. Keep the plugin header and `README.md` in step.

Phases 1–3 ship a useful plugin with zero AI. That is intentional — get real
feedback on the foundation before taking on the hard part.

---

## File layout

```
bulk-list-import/
├── bulk-list-import.php          # header, guards, HPOS, bootstrap, bli_is_pro()
├── includes/
│   ├── class-plugin.php          # hook wiring
│   ├── class-parser.php          # core value — everything else is plumbing
│   ├── class-sku-generator.php   # MAX(sku) in PHP, never COUNT(products)
│   ├── class-importer.php        # WC_Product_Simple creation
│   ├── class-import-report.php   # post-import review screen
│   └── class-admin-page.php      # paste form + preview table
├── tests/
│   └── test-parser-fixtures.php
└── assets/
    ├── admin.css
    └── admin.js              # select-all + batch warning; plain JS, no build step
```

---

## Phase 4 — the AI layer

### We integrate an existing AI API. We do not build a model.

No model training, no ML pipeline, no local inference. The plugin makes HTTP
requests to a third-party LLM API and parses the JSON that comes back. That is
the entire AI component.

The value this plugin adds is everything *around* the API call — parsing,
gating, SKU logic, review workflow. The call itself is a commodity.

**Provider abstraction is required from the first commit:**

```php
interface DescriptionProvider {
    public function recognise( array $product_names ): array;
    public function describe( array $product, array $options ): array;
}
```

- Ship with **Gemini** as the default implementation.
- Structure so OpenAI, Claude, and OpenRouter drop in without touching calling
  code.
- Provider and model are a **user setting**, not a constant.
- A hosted-proxy implementation may replace bring-your-own-key later. Keep that
  door open.

### Output must be SEO-oriented

- Product name in the **first sentence**, bolded
- Keyword-rich prose covering brand, category, key specs, use case
- A short structured attribute block, category-appropriate
- A separate one-line short description
- A URL-safe slug
- Brand extracted as its own field

**JSON contract — validate this shape before writing anything:**

```json
{
  "long_description": "...",
  "short_description": "...",
  "slug": "...",
  "brand": "...",
  "meta_description": "...",
  "focus_keyword": "...",
  "attributes": [ { "label": "...", "value": "..." } ],
  "uncertain_fields": [ "..." ]
}
```

`attributes` is deliberately generic — tasting notes for a drink, specs for a
laptop, materials for furniture, dosage for a pharmacy item. The model decides
what suits the category. **Do not hardcode field names.**

### User-configurable prompt

Settings screen provides:

- **Industry / store type** (free text)
- **Tone** (premium, plain, technical, playful)
- **Description length** (short / medium / long)
- **Custom prompt template** with placeholders:
  `{product_name} {variant} {price} {category} {brand} {industry} {tone}`
- Sensible defaults so it works with nothing configured

### Two-pass design

The gate must appear in the preview table **before** import, but the
description call happens **during** import. So AI runs twice.

**Pass 1 — recognition check.** At parse time. Cheap. Batch ~20 names per call.

```
For each product name return JSON:
{ "name": "...", "known": true|false, "confidence": 0.0-1.0,
  "uncertain_fields": ["..."] }
Return known:false if you cannot identify the specific product with
confidence. Do not guess.
```

**Pass 2 — description write.** At import time. Only for rows that cleared the
gate. Cheaper overall, since prose is never generated for products the user is
about to reject.

### The fabrication problem — the most important constraint in this project

An LLM asked to describe a product it has never encountered will not say so. It
will produce confident, fluent, entirely invented copy — specific cask types,
precise ABV figures, maturation periods, material compositions, compatibility
claims. During manual catalogue work, six products were described this way
before anyone noticed. They were caught only because a human was reading each
one as it was written.

At scale, unattended, nobody catches it.

**A `needs-review` tag is a disclaimer — users learn to ignore it by product
#40. A blocked import is a gate — they can't.** We chose the gate.

**The harder failure mode `known:false` does not catch:** the model recognises
the *brand* but invents the *specifics*. The general form, for any industry, is
inventing a spec, measurement, material, certification, or compatibility claim
for a product line it knows only in outline.

Therefore:

- Confidence must be **field-level**, not just product-level — `uncertain_fields`
- A row can be "Ready" overall and still render one spec in amber with
  *"verify from source"*
- **Prompt rule: if you don't know a spec, omit it.** Never invent to fill a
  field.

For regulated categories — alcohol, pharmacy, supplements, electrical goods,
children's products — a wrong product claim is a legal exposure, not a cosmetic
flaw.

### Blocked-row UX

Clicking a blocked row opens an inline panel:

> **[Product name]** — no reliable information available for this product.
> Category · Brand · Key specs · Notes from the packaging
> `[ Write description myself ]` `[ Generate from my details ]` `[ Skip ]`

**"Generate from my details" is the sweet spot** — user supplies facts, AI
supplies prose. Keeps the time saving without inventing anything.

### Don't over-gate

If 60 of 200 rows block, a hard wall causes rage-quit. Provide:

- **Skip all blocked** — import the clean ones, leave the rest in the paste box
- **Bulk-fill** — one panel for "all 12 of these share a category and brand"
- Blocked count on the import button: `Import 16 products (4 blocked)`

The gate should feel like a seatbelt, not a locked door.

### Technical requirements

- **API keys never touch the browser.** Server-side only, via
  `wp_remote_post()`. Not cURL, not Guzzle.
- Store the key encrypted in `wp_options`, or allow a constant in
  `wp-config.php`.
- **Prompt for JSON. Validate the shape before writing anything.** Retry on
  malformed output. Never write unvalidated model output to the database.
- **Queue everything with Action Scheduler** (ships with WooCommerce). Do not
  hand-roll a cron loop.

  *Why a queue is mandatory, so nobody "simplifies" it away:* generation is not
  slow (~3–8s per description). PHP requests are short. Even 20 products × 5s =
  100s, against a `max_execution_time` of 30s by default and 60–300s on typical
  shared hosting, plus a ~60s nginx/proxy timeout. A single-request import dies
  partway through — **and it dies mid-write**, leaving some products created, no
  record of where it stopped, and no way to resume. The user re-runs it and gets
  duplicates. A queue keeps each run inside the limit, survives tab closure, and
  makes a failure at product #14 one retryable job instead of wreckage.

- Batch sizes: **20 products per import by default** (user-editable), ~20 names
  per recognition call, 3–5 concurrent description calls, 10–20 jobs per Action
  Scheduler run.
- Show a **live token estimate** before the user commits (~650 tokens/product).

---

## The Import Report

Two review moments, both required. They catch different failures and neither
replaces the other.

| | When | Catches |
|---|---|---|
| **Pre-import gate** | Before generation | *"I don't know this product"* — stops fabrication at source, saves tokens |
| **Import Report** | After generation | API failures, malformed JSON, low-confidence fields, timeouts, save errors |

The gate cannot catch a call that fails halfway through. The report cannot stop
tokens being spent on a product that was never going to work.

### Rules

- **Every input row appears.** A row vanishing silently is the worst outcome in
  this plugin — the user believes they imported 20 products when they imported 16.
- **Every non-success states a specific, human reason.** Never bare "Error".
  "API timeout after 3 retries", "Product not recognised", "SKU GE-0061 already
  in use", "Price could not be parsed".
- **Actionable inline.** Failed rows get Retry; not-generated rows open the same
  details panel as the gate; created-but-uncertain rows link to the edit screen.
- **Field-level warnings surface here too.** A product can be created and still
  carry `uncertain_fields` — that becomes a "verify" state, not a silent pass.
- **Persist it.** Store against the import job so the user can close the tab and
  come back. Keep the last 10.
- **Exportable** (CSV) so the "needs attention" list can be handed to someone
  else. Guard against formula injection: prefix cells starting with `= + - @`.

### Why this matters more than it looks

The original manual workflow had review built in by necessity — every
description was read as it was written, which is how the fabricated products
were caught. Automation removes that step. **The Import Report puts it back.**
It is a core feature, not error-handling polish.

---

## Licensing boundary

- Free tier: parsing, product creation, SKU sequencing.
- Paid tier: AI descriptions, recognition gate, report CSV export.
- `bli_is_pro()` exists in the main plugin file. **Gate every paid feature
  behind it from day one** — retrofitting the boundary later is painful.

---

## Conventions

- **PHP 8.0+**, `declare( strict_types = 1 );` in every file
- Namespace `BulkListImport`, function prefix `bli_`, text domain
  `bulk-list-import`
- `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top of every PHP file
- **Security is non-negotiable** — the top rejection reason for the WordPress.org
  repo:
  - `current_user_can( 'manage_woocommerce' )` on every admin action
  - `check_admin_referer()` / nonces on every form and AJAX endpoint
  - `sanitize_text_field()` / `sanitize_textarea_field()` / `absint()` /
    `wc_format_decimal()` on all input
  - `esc_html()` / `esc_attr()` / `wp_kses_post()` on all output
- WordPress Coding Standards via PHPCS + WPCS. Note: WPCS uses snake_case
  methods and tabs. If a linter flags these, it is configured for PSR-12 —
  fix the linter, not the code. WordPress.org rejects PSR-12-formatted plugins.
- HPOS compatibility is declared — don't break it.
- **No React yet.** Plain PHP forms until the parser is proven.

---

## Parser regression fixtures

**These are test cases, not a whitelist.** The parser has no concept of product
category — it matches units, price-like numbers, separators and leftover text.
Any industry parses through the same code path. The categories below are simply
where verified coverage sits; every other category is expected to work and is
just untested.

When a new product shape parses badly, **add a fixture line** rather than
special-casing the parser. Growing this list is how coverage expands.

**Keep all of these passing.**

```
ELECTRONICS                                      -> heading, skipped
Samsung 55" QLED TV — ₦450,000                   -> 55" is a spec, not a price
iPhone 15 Pro 256GB — $999                       -> 15 and 256 are specs
Dell XPS 13 9340 — 16GB/512GB — ₦1,850,000       -> multiple specs, one price
Anker PowerCore 20000mAh — ₦28,500

Nike Air Max 270 — UK 9 — ₦85,000                -> "270" and "9" are not prices
Levi's 501 Straight Jeans — W32 L34 — ₦45,000

Paracetamol 500mg x 100 tablets — ₦2,500         -> 500 and 100 are not prices
Vitamin C 1000mg (60 caps) — ₦8,000

Cement (50kg bag) — ₦9,500
Organic Basmati Rice — 5kg — ₦12,000
Office Chair, Ergonomic Mesh — ₦75,000           -> no unit at all, still valid

1. Glenfiddich 30 Years Old — 70cl — ₦2,260,000  -> "30" must NOT be the price
Rémy Martin 1738 — 70cl — ₦95,000                -> "1738" must NOT be the price
818 Tequila Blanco (75cl) 80,000                 -> leading digits are the NAME
4th Street Sweet Red Wine — 75cl — ₦5,000        -> "4th" must survive
Declan White Wine — 75cl 8000                    -> price with no symbol or comma
Aznauri Saperavi — 75cl                          -> flag: no price
Grey Goose	70cl	₦49,000                       -> tab-delimited
Element — 70cl — ₦43,000                         -> single-word name, no digits
Product / CL / Price                             -> column labels, skipped
```

**Accent normalisation:** these pairs must collapse to the same duplicate key.

```
Rémy Martin 1738  /  Remy Martin 1738
Volcán Cristalino /  Volcan Cristalino
Patrón Silver     /  Patron Silver
```

### Design notes

- Numbers attached to a recognised unit are **specs**, masked before price
  detection. This is the general rule; age statements are one instance of it.
- Price candidates are **scored** — currency symbol +100, thousands separator
  +50, later column +10/segment, magnitude as tiebreak — not "take the last
  number". This protects `1738`, `270`, `501`, `9340`.
- Numbering strip requires `digit + . or ) + space`, protecting names that
  legitimately start with digits: `818`, `4th`, `501`.
- Header detection is deliberately conservative (ALL-CAPS + no digits + short,
  or exact column-label match) because real products *are* single words with no
  digits. Headers are **flagged, never silently deleted.**
- **Rows with no unit are normal** and must score full confidence when name and
  price are present.
- Duplicate normalisation folds accents to ASCII **before** stripping
  non-alphanumerics, or accented and unaccented spellings of the same product
  both import. The other order deletes accented characters outright, so
  "Rémy Martin" would key as `rmymartin` and never match "Remy Martin".
- The parser owns its own folding table (Latin-1 Supplement + Latin Extended-A)
  and does **not** call `remove_accents()`, not even behind a
  `function_exists()` check. `class-parser.php` is deliberately free of every
  WordPress function, which is what lets `tests/test-parser-fixtures.php` run
  standalone — no WordPress boot, no database, milliseconds per run. A
  conditional branch would be worse than no folding at all: tests would
  exercise the fallback while production exercised `remove_accents()`, so the
  shipping path would never be the tested path and drift between them would
  surface only as a duplicate slipping through in a real store.
