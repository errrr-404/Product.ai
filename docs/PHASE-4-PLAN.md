# Phase 4 — implementation sequence

> **Temporal document.** This is the running order for Phase 4 and nothing else.
> Durable decisions — storage model, namespace layout, key-storage framing,
> queue model — live in `BUILD-SPEC.md`, which is authoritative. Archive this
> file when Phase 4 lands.

Each step is one commit. The parser fixtures and the validator fixtures stay
green throughout; PHPCS stays clean.

---

## 4a — Provider seam, no network

Everything that can be built and tested without an API key or a network call.

- `interface-description-provider.php` — `recognise()` and `describe()`.
- `class-prompt-builder.php` — industry, tone, length, custom template with
  `{product_name} {variant} {price} {category} {brand} {industry} {tone}`.
  Category-neutral defaults that work with nothing configured.
- `class-response-validator.php` — the JSON contract. **This is the only door
  between model output and the database.**
- `class-fake-provider.php` — replays recorded fixtures. Test seam only.
- Autoloader: sub-namespace segments become directories, and interfaces resolve
  to `interface-*.php`.
- `tests/test-response-validator.php` + `tests/fixtures/*.json`.

**Gate:** validator fixtures green, standalone, no WordPress and no network —
same property as the parser suite.

**Stop here for review of the fixture set before 4b.**

## 4b — Gemini provider + key storage

- `class-gemini-provider.php` via `wp_remote_post()`. Timeouts, retry policy,
  one re-ask on malformed JSON with a "return only JSON" nudge, then fail with a
  specific reason.
- `class-api-key-store.php` — `wp-config.php` constant preferred,
  `sodium_crypto_secretbox` fallback keyed off the salts.
- Key never rendered back to the browser: masked placeholder, write-only field.

## 4c — Settings screen

- Provider, model, industry, tone, description length, custom prompt template.
- **Model dropdown populated from `list_models()`**, cached 24h in a transient,
  falling back to the provider's short hardcoded list when the call fails.
- WP Settings API, `register_setting` with sanitize callbacks.
- Entire AI section behind `bli_is_pro()`.

## 4d — Recognition gate (pass 1) — done

- Batched at ~20 names per call. Results cached in a transient keyed on
  `hash( provider | model | name )`, so re-previewing after editing one row does
  not re-spend.
- **Synchronous path hard-capped at one recognition call (~25 names.)** Beyond
  the cap, gate the first call's worth and mark the remainder *not yet checked*
  rather than blocking the page.
- **Preview submit goes through `fetch()`.** Intercept in `admin.js`, show a
  spinner, render the table on response. Same request shape as today. A
  synchronous POST that leaves the browser blank for 8s reads as a hang, and
  users resubmit.
- Blocked-row detail panel: category, brand, key specs, notes from the packaging
  → *Write description myself* / *Generate from my details* / *Skip*.
- Anti-over-gating: *Skip all blocked*, bulk-fill for rows sharing a category and
  brand, and a blocked count on the button — `Import 16 products (4 blocked)`.
- Live token estimate from the selected count (~650 tokens/product).

**Landed with two things worth carrying forward.**

`Recognition_Gate::judge()` maps provider verdicts back to rows *by position*.
That is only safe because `Response_Validator::validate_recognition()` guarantees
one record per requested name in the order requested, and throws when a name is
missing. If that guarantee is ever relaxed, this loop starts handing one
product's judgement to another — marking a product the model does not know as
recognised, which is the failure the gate exists to prevent. The coupling is
commented at both ends.

`UNCHECKED` is not a verdict and must never be rendered as one. Rows past the
one-call cap are labelled "Not yet checked", stay selectable, and are re-checked
in 4e before anything is generated for them. Non-candidate rows — headings,
duplicates — carry no gate key at all rather than a default state, so nothing can
mistake "never asked" for "passed".

## 4e — Queued generation (pass 2)

- Custom tables `{prefix}bli_imports` and `{prefix}bli_import_rows`, `dbDelta`
  gated behind a schema version option, uninstall hook dropping both.
- **Re-check `UNCHECKED` rows before generating.** They cleared the preview only
  because they fell past the one-call cap, not because anyone judged them. A row
  that reaches generation ungated is a row the gate did not gate.
- Action Scheduler, **one row per job**, each carrying its pre-assigned SKU.
- SKUs reserved once at enqueue time.
- Report rows written incrementally, so closing the tab loses nothing.
- **Build the outer retry loop.** Action Scheduler does not reschedule failed
  actions, so a retryable verdict currently ends the row. On retryable failure:
  `as_schedule_single_action()` at `Retry_Policy::outer_delay()`, carrying an
  attempt counter, stopping at `MAX_OUTER_ATTEMPTS`. A `Retry-After` from the
  provider overrides the schedule.
- Report rows gain an `attempts` column: "failed once, will retry" and "gave up
  after three" are different states and the user has to tell them apart.

## 4f — Field-level uncertainty

- `uncertain_fields` → amber *"verify from source"* against specific fields.
- A row can be **Ready** overall and still carry an amber field.
- Report gains new *reasons* only. `not_generated` and `created_verify` are
  already in the outcome enum and `outcome_label()`, so there is no new screen —
  as intended.

**Gate:** `tests/fixtures/recognition-adversarial.json`, run live against the
configured provider and model. Six products that must return `known:false`, three
that must return `known:true` with a non-empty `uncertain_fields`. Record
provider, model, date and per-case result in the commit message.

A `known:true` in the first group is a hard fail — the gate is broken. An empty
`uncertain_fields` in the second group is also a failure, and the more dangerous
one: nothing looks wrong, and the output is confident, plausible and
unverifiable.

---

## Standing constraints

Full reasoning in `BUILD-SPEC.md`; repeated here so they are not re-litigated
mid-phase.

- **One row per description job.** `wp_remote_post()` is blocking; Action
  Scheduler defaults to a single concurrent batch. Do not design for
  parallelism. Wall-clock proportional to row count is fine — nobody is watching.
- **Reserve SKUs at enqueue, never inside a job.** A lock held across generation
  is a lock held for minutes.
- **The outer retry loop is ours to build.** Action Scheduler marks a throwing
  action failed and stops. Do not write code, or comments, that assume otherwise.
- **Model names come from `list_models()`, not from a constant.** The hardcoded
  pair is a fallback for when that call fails, nothing more.
- **Never write unvalidated model output.** Shape-check first, every time.
- **Draft status always.** Auto-publish is not a Phase 4 feature.
- **Every input row appears in the report.** Including the ones the gate blocked
  and the ones nobody selected.
- **Gate every paid feature on `bli_is_pro()` from the first commit**, not at the
  end of the phase.

## Known unknown

The gate catches *"I don't have reliable information about this product."* It
does **not** catch brand-recognised-specifics-invented, because that returns
`known:true`. Only `uncertain_fields` plus the omit-rather-than-invent prompt
rule address that, and both are model-dependent rather than enforceable in code.

The adversarial set is the check on it, and it is a sample, not a proof. Expect
to re-run it whenever the provider or model setting changes — a model swap can
silently regress hedging behaviour while every automated test stays green.
