# CLAUDE.md — Bulk List Import for WooCommerce

> Project context for Claude Code. Read this before making changes.

---

## What we're building

A WordPress/WooCommerce plugin that turns a **raw pasted product list** into
**reviewable draft products** with **continuous SKUs** and AI-written,
SEO-oriented descriptions.

The one-line pitch: **"Stop typing. Start reviewing."**
It converts a data-entry job into an editing job.

### This plugin is product-category agnostic — read this first

The idea came from a wine and spirits store, and some examples below are drinks
because that's the real data we tested against. **That is origin story, not
scope.** The plugin must work equally well for a phone shop, a pharmacy, a
fashion boutique, a building-materials supplier, a bookshop, or a grocery.

Concretely, this means:

- The parser must not assume bottle sizes. `cl`/`ml` are just two of many units
  — it must handle `kg`, `g`, `mg`, `GB`, `TB`, `cm`, `inch`/`"`, `pack`,
  `pcs`, `x 100`, clothing sizes, and **rows with no unit at all**.
- Internally the extracted unit field is a generic **variant/spec**, not "size".
- Prompts must be category-neutral by default, with the *user* supplying
  industry and tone.
- Nothing in the codebase should hardcode drink vocabulary — no "tasting
  notes", no "ABV", no "vintage" as fixed fields.

If a change would only make sense for a liquor store, it's wrong.

### Where the idea came from

The developer manually created 100+ products for a Nigerian store. The loop was:

```
read product name -> write description -> copy -> open WordPress ->
Add New Product -> paste long desc -> paste short desc -> set price ->
hunt for the last SKU -> increment -> set category -> Save Draft -> repeat
```

3–5 minutes per product. ~8 hours for 100 products. Real errors occurred: a
duplicate product imported twice, and a near SKU collision because the store
had 57 products but `GE-0058` was the highest SKU in use.

### Competitive position — protect this

Existing plugins (DraftLab AI, AI Product Tools, Uncanny Automator,
WriteText.ai, WP Sheet Editor) all operate **downstream of product creation** —
they write descriptions for products that already exist.

**Nothing on the market does these three things together:**

1. **Parse messy raw text** into structured products at bulk
2. **Continue an existing SKU sequence** automatically
3. **Gate the import** — block products the AI doesn't genuinely know,
   instead of confidently inventing details

Those three are the entire differentiation. Do not drift toward "another AI
description generator" — that market is crowded and well-funded.

---

## Current state

Phase 2 is complete. The plugin activates, adds an admin page, parses pasted
text, and renders a preview table with the SKU sequence. **It writes nothing to
the database yet.** That is deliberate.

```
bulk-list-import/
├── bulk-list-import.php          # header, guards, HPOS, bootstrap, bli_is_pro()
├── includes/
│   ├── class-plugin.php          # hook wiring — the closest thing to main()
│   ├── class-parser.php          # THE CORE VALUE. Everything else is plumbing.
│   ├── class-sku-generator.php   # MAX(sku) — never COUNT(products)
│   └── class-admin-page.php      # paste form + preview table
└── assets/admin.css
```

**Known gap:** the current parser's unit regex only recognises volume units
(`cl`, `ml`, `l`). Generalising it to the full unit set above is part of
Phase 3.

---

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 | Plugin skeleton, activation, admin menu | done |
| 2 | Parser + preview table, no DB writes | done |
| 3 | **Generalise units + create real products** | **next** |
| 4 | AI layer: recognition gate + SEO generation, queued | pending |
| 5 | Hardening: errors, retries, rate limits, i18n, security audit | pending |

Phases 1–3 ship a genuinely useful plugin with **zero AI**. That's intentional —
get real users and real feedback before taking on the hard part.

---

## Phase 3 — what to build next

**a) Generalise the parser's unit handling.**
Replace the volume-only regex with a unit set covering at least:
volume (`cl ml l ltr litre`), weight (`kg g mg lb oz`), digital
(`GB TB MB`), length (`mm cm m inch in "`), count (`pack pcs pc x`),
and apparel sizes (`UK 9`, `EU 42`, `S M L XL XXL`).
Rows with **no unit** are normal and must not be penalised.

**b) Wire the preview table to real product creation.**

- Use the **`WC_Product_Simple` CRUD API**. Never `wp_insert_post()` + raw
  postmeta — deprecated and breaks under HPOS.
  ```php
  $product = new \WC_Product_Simple();
  $product->set_name( $row['name'] );
  $product->set_regular_price( (string) $row['price'] );
  $product->set_sku( $sku->next() );
  $product->set_status( 'draft' );   // draft is the default, always
  $product->save();
  ```
- **Draft status by default.** Auto-publish may be an opt-in setting later,
  never the default.
- Tag every created product `needs-review`.
- Add a **checkbox column** so the user selects which rows to import; heading
  and duplicate rows are unselectable.
- **Editable name / variant / price fields** inline in the preview table
  before import — the user must be able to fix a parse error without re-pasting.
- Wrap SKU assignment in a **transaction with a lock** so two concurrent
  imports can't claim the same number.
- Build the **Import Report** screen (own section below). Even with no AI, rows
  fail — duplicate SKU, invalid price, save error. The report structure should
  exist now so Phase 4 only adds new failure *reasons*, not a new screen.
- **Default batch size 20**, user-editable. Soft-warn above it:
  *"Large imports are harder to review carefully."* Warn, never forbid.

**Do not add the AI layer in this phase.**

---

## Phase 4 — the AI layer

### We are integrating an existing AI API. We are not building a model.

To be unambiguous: there is **no model training, no ML pipeline, no local
inference** in this project. The plugin makes HTTP requests to a third-party
LLM API and parses the JSON that comes back. That is the entire AI component.

Every competitor works this way too — DraftLab calls OpenAI/DeepSeek,
AI Product Tools calls OpenAI/Gemini/Claude/OpenRouter. The value this plugin
adds is **everything around the API call** — parsing, gating, SKU logic, review
workflow. The API call itself is a commodity.

**Provider abstraction is required from the first commit:**

```php
interface DescriptionProvider {
    public function recognise( array $product_names ): array;
    public function describe( array $product, array $options ): array;
}
```

- Ship with **Gemini** as the default implementation (cheapest at this volume,
  generous free tier, simplest signup).
- Structure so OpenAI, Claude, and OpenRouter can be added as further
  implementations without touching calling code.
- Provider and model are a **user setting**, not a constant.
- A `HostedProvider` may replace `BYOKProvider` later — see business
  constraints.

### Output must be SEO-oriented

This is a stated requirement. Generated copy should follow the pattern the
developer already validated by hand:

- Product name appears in the **first sentence**, bolded
- Descriptive, keyword-rich prose — brand, category, key specs, use case
- Then a short structured attribute block (bulleted, category-appropriate)
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

`attributes` is deliberately generic — it becomes tasting notes for a drink,
specs for a laptop, materials for furniture, dosage for a pharmacy item. The
model decides what's appropriate for the category. **Do not hardcode field
names.**

### The prompt is user-configurable

Settings screen provides:

- **Industry / store type** (free text, e.g. "electronics retailer")
- **Tone** (e.g. premium, plain, technical, playful)
- **Long description length** (short / medium / long)
- **Custom prompt template** with placeholders:
  `{product_name} {variant} {price} {category} {brand} {industry} {tone}`
- Sensible defaults so it works out of the box with nothing configured

### Two-pass design

The gate must appear in the preview table **before** import, but the
description call happens **during** import. So AI runs twice:

**Pass 1 — recognition check.** At parse time. Cheap. Batch ~20 names per call.
```
For each product name return JSON:
{ "name": "...", "known": true|false, "confidence": 0.0-1.0,
  "uncertain_fields": ["..."] }
Return known:false if you cannot identify the specific product with
confidence. Do not guess.
```

**Pass 2 — description write.** At import time. Only for rows that cleared the
gate. Also cheaper overall, since we no longer generate prose for products the
user is about to reject.

### The hallucination problem — the most important constraint in this project

While building the source catalogue by hand, the AI produced confident,
plausible, **entirely invented** descriptions for six products it had no real
knowledge of. The developer caught them all because he was reviewing one at a
time. At 200 products, unattended, nobody catches it.

**A `needs-review` tag is a disclaimer. Users learn to ignore it by product #40.
A blocked import is a gate. They can't.** We chose the gate.

**The harder failure mode `known:false` does NOT catch:** the model recognises
the *brand* but invents the *specifics*. Real examples: confidently asserted
cask types for each variant in a product line, a wrong ABV figure stated
without hedging, an invented maturation period. All would return `known: true`.
The general form of this — for any industry — is inventing a spec, a
measurement, a material, a certification, or a compatibility claim.

Therefore:
- Confidence must be **field-level**, not just product-level -> `uncertain_fields`
- A row can be "Ready" overall but still render a specific spec in amber with
  *"verify from source"*
- **Prompt rule: if you don't know a spec, don't state it.** Omit a claim
  rather than invent one.

For regulated categories — alcohol, pharmacy, supplements, electrical goods,
children's products — factually wrong product claims are a real liability, not
a cosmetic issue.

### Blocked-row UX

Clicking a blocked row opens an inline panel:

> **[Product name]** — I don't have reliable information about this product.
> Category · Brand · Key specs · Notes from the packaging
> `[ Write description myself ]` `[ Generate from my details ]` `[ Skip ]`

**"Generate from my details" is the sweet spot** — user supplies facts, AI
supplies prose. Keeps the time saving without inventing anything.

### Don't over-gate

If 60 of 200 rows block, a hard wall causes rage-quit. Provide:
- **Skip all blocked** — import the clean ones, leave the rest in the paste box
- **Bulk-fill** — one panel for "all 12 of these are the same category and brand"
- Blocked count on the import button: `Import 16 products (4 blocked)`

The gate should feel like a seatbelt, not a locked door.

### Technical requirements

- **API keys never touch the browser.** All calls server-side via
  `wp_remote_post()`. Not cURL, not Guzzle.
- Store the key encrypted in `wp_options`, or let users define a constant in
  `wp-config.php`.
- **Prompt for JSON. Validate the shape before writing anything.** Retry on
  malformed output. Never write unvalidated AI output to the database.
- **Queue everything with Action Scheduler** (ships with WooCommerce, already
  installed). Do not hand-roll a cron loop.

  *Why a queue is mandatory — the reasoning, so nobody "simplifies" it away:*
  generation is not slow (~3–8s per description). The problem is that PHP
  requests are short. Even 20 products x 5s = 100s, against a
  `max_execution_time` of 30s by default and 60–300s on typical shared hosting,
  plus a ~60s nginx/proxy timeout. A single-request import dies partway
  through — **and it dies mid-write**, leaving some products created, no record
  of where it stopped, and no way to resume. The user re-runs it and gets
  duplicates. A queue keeps each run well inside the limit, survives tab
  closure, and makes a failure at product #14 a single retryable job instead of
  wreckage.

- Batch sizes: **20 products per import by default** (user-editable),
  ~20 names per recognition call, 3–5 concurrent description calls, 10–20 jobs
  per Action Scheduler run (safe on cheap shared hosting).
- Show a **live token estimate** before the user commits (~650 tokens/product).

---

## The Import Report — post-generation review

There are **two review moments**, and both are required. They catch different
failures and neither replaces the other.

| | When | Catches |
|---|---|---|
| **Pre-import gate** | Before generation | *"I don't know this product"* — stops invention at source, saves tokens |
| **Import Report** | After generation | API failures, malformed JSON, low-confidence fields, timeouts, save errors |

The gate cannot catch a call that fails halfway through. The report cannot stop
tokens being spent on a product that was never going to work.

### What the report must show

After an import finishes, land the user on a report — not a toast notification,
not a redirect to the products list. A summary line, then a table of **every**
row and what happened to it.

```
Import complete — 16 created, 2 need attention, 2 failed
```

| Row | Product | SKU | Outcome | Reason |
|---|---|---|---|---|
| 1 | Samsung 55" QLED TV | GE-0059 | Created | — |
| 7 | [unknown item] | — | Not generated | Product not recognised — details required |
| 12 | Dell XPS 13 9340 | GE-0068 | Created, verify | RAM spec uncertain — check source |
| 19 | Ergonomic Mesh Chair | — | Failed | API timeout after 3 retries |
| 20 | Organic Basmati Rice 5kg | — | Skipped | Duplicate of row 18 |

### Rules for the report

- **Every input row appears.** A row that vanishes silently is the single worst
  outcome in this plugin — the user believes they imported 20 products when
  they imported 16.
- **Every non-success states a specific, human reason.** Never "Error" or
  "Failed" alone. "API timeout after 3 retries", "Product not recognised",
  "SKU GE-0061 already in use", "Price could not be parsed".
- **Actionable inline.** Failed rows get **Retry**; not-generated rows open the
  same details panel as the pre-import gate; created-but-uncertain rows link
  straight to the product edit screen.
- **Field-level warnings surface here too.** A product can be created
  successfully and still carry `uncertain_fields` — those become the "verify"
  state, not a silent pass.
- **Persist it.** Store the report against the import job so the user can close
  the tab, come back, and still see what happened. Keep the last N imports.
- **Exportable** (CSV) so a shop owner can hand the "needs attention" list to
  someone else.

### Why this matters more than it looks

The developer's original manual workflow had a review step built in by
necessity — he read every description as it was written, which is how the
invented products got caught. Automation removes that step. **The Import Report
is what puts it back.** Treat it as a core feature, not error-handling polish.

---

## Business constraints that affect the code

- **Freemium.** Free version on WordPress.org (cannot sell there — it's the
  distribution channel). Pro sold via **Freemius**, which acts as merchant of
  record and pays out to Nigeria via PayPal/Payoneer/wire.
- **Free tier:** parsing, product creation, SKU sequencing.
  **Pro tier:** AI descriptions, recognition gate, Import Report export.
- `bli_is_pro()` already exists in the main file returning `false`. **Gate every
  premium feature behind it from day one.** Retrofitting a freemium boundary is
  painful.
- **Why the provider interface matters commercially:** many target users cannot
  easily get an international-billing card for an AI API key. A future hosted
  backend would let them pay in local currency and never touch a key. Keep that
  door open.

---

## Conventions

- **PHP 8.0+**, `declare( strict_types = 1 );` in every file
- Namespace `BulkListImport`, function prefix `bli_`, text domain
  `bulk-list-import`
- `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top of every PHP file
- **Security is non-negotiable** — the #1 reason plugins get rejected from the
  WP repo:
  - `current_user_can( 'manage_woocommerce' )` on every admin action
  - `check_admin_referer()` / nonces on every form and AJAX endpoint
  - `sanitize_text_field()` / `sanitize_textarea_field()` on all input
  - `esc_html()` / `esc_attr()` / `wp_kses_post()` on all output
- WordPress Coding Standards via PHPCS
- HPOS compatibility is already declared — don't break it
- **No React yet.** Plain PHP forms until the parser is proven. A build pipeline
  now would slow down the part that actually matters.

---

## Developer context

The developer is **strong in Java**, newer to PHP and WordPress. Where a
PHP-specific or WordPress-specific idiom appears, briefly explain it rather than
assuming familiarity — particularly hooks (`add_action`/`add_filter`), PHP
arrays doing double duty as List and Map, and the fact that nothing runs unless
it's hooked.

---

## Parser regression fixtures

**These are test cases, not a whitelist.** The parser has no concept of product
category — it matches units, price-like numbers, delimiters, and leftover text.
Any industry parses through the same code path. The categories below are simply
where verified coverage currently sits; every other category is expected to work
and is just untested.

When you encounter a new product shape that parses badly, **add a fixture line
for it** rather than special-casing the parser. Growing this list is how
coverage expands.

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

**Design notes behind those:**

- Numbers attached to a recognised unit are **specs**, and are masked before
  price detection. This is the general rule; alcohol age statements
  ("30 Years Old") are just one instance of it.
- Price candidates are **scored** — currency symbol +100, thousands separator
  +50, magnitude as tiebreak — rather than "take the last number". This is what
  protects `1738`, `270`, `501`, and `9340`.
- Numbering strip requires `digit + . or ) + space`, which protects names that
  legitimately start with digits: `818`, `4th`, `501`.
- Header detection is deliberately conservative (ALL-CAPS + no digits + short,
  or an exact column-label match) because real products *are* single words with
  no digits. Headers are **flagged, never silently deleted.**
- **Rows with no unit are completely normal** and must score full confidence if
  name and price are present.

---

## First task

Read all six existing files, then implement **Phase 3** as specified above —
starting with generalising the unit handling, since every fixture above depends
on it.

Ask before adding dependencies, changing the file structure, or introducing a
build step.
