# 4e verification — manual test plan

> **Why this exists.** 4e made three structural changes at once — custom tables,
> queued generation, and an outer retry loop — and the automated suites cover
> none of them. 191 assertions test the parser, validator, retry table, crypto,
> prompts and gate policy; every one of those runs without WordPress, which is
> exactly why none of them touches persistence, Action Scheduler, or dbDelta.
>
> Phase 5 will touch these same paths, so results are recorded here rather than
> in a chat log.

Run the scenarios **in order**. Uninstall is last because it destroys everything
worth inspecting.

---

## Before you start

### Force the Pro tier

`bli_is_pro()` returns `false`, and `Importer::dispatch()` requires it. Without
this the recognition gate never runs, generation never runs, and **the queue is
never used at all** — scenarios 3–6 would silently test the synchronous path.

`wp-content/mu-plugins/bli-dev.php`:

```php
<?php
add_filter( 'bli_is_pro', '__return_true' );
```

### Turn on error logging

Several failure modes below are fatals, which otherwise show as a white screen.
In `wp-config.php`:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Then `tail -f wp-content/debug.log` in a second terminal.

### Record the environment

Scenario 6's diagnosis depends on MySQL 8 dropping integer display widths from
`SHOW CREATE TABLE`. **MariaDB does not do this**, so if this reports MariaDB,
re-read that scenario's prediction before trusting it.

```bash
wp db query "SELECT VERSION()"
wp plugin list --status=active --field=name
wp db prefix
```

| | |
|---|---|
| Database version | |
| WooCommerce version | |
| Table prefix | |
| Date run | |

### Where to watch

- **Scheduled Actions:** WooCommerce → Status → Scheduled Actions. Filter to
  group `bulk-list-import`, hook `bli_process_row`.
- **Row state**, which is the ground truth for most of this:

  ```bash
  wp db query "SELECT id,line,name,sku,outcome,attempts,LEFT(reason,70) AS reason \
    FROM $(wp db prefix)bli_import_rows ORDER BY id DESC LIMIT 20"
  ```

- **Forcing the queue.** WP-Cron only fires on a page load, so on LocalWP nothing
  runs while you are looking at the terminal. This is faster than clicking
  around, and will *not* run actions dated in the future — which is the whole
  point of scenario 5:

  ```bash
  wp action-scheduler run --group=bulk-list-import
  ```

---

## 1. Activation creates both tables

**Steps**

```bash
wp plugin deactivate bulk-list-import
wp option delete bli_db_version
wp db query "DROP TABLE IF EXISTS $(wp db prefix)bli_import_rows, $(wp db prefix)bli_imports"
wp plugin activate bulk-list-import

wp db query "SHOW TABLES LIKE '$(wp db prefix)bli_%'"
wp option get bli_db_version
```

**Pass** — both tables listed, `bli_db_version` is `1`.

**Most likely failure.** Tables missing with no error at all. The activation hook
and `Schema::maybe_upgrade()` are two independent paths to the same install, and
the one thing that disables both is WooCommerce being inactive — `plugins_loaded`
bails before `maybe_upgrade()` runs. Confirm WooCommerce is active first. A
*fatal* here instead means the autoloader did not resolve `Schema`, which would
be a naming or path problem rather than an activation problem.

**Result**

```
```

---

## 2. No API key falls back to synchronous

**Steps.** Pro filter on, **no API key saved** (Products → Bulk Import Settings,
tick "Delete the stored key" if one is stored). Import about five rows.

**Pass**

- You land on the report with every row already `created` or `skipped`.
- **Zero** rows show "Waiting".
- Scheduled Actions shows **nothing** in the `bulk-list-import` group.

**Most likely failure.** Rows stuck at "Waiting" indefinitely. That means
`dispatch()` took the queue branch, so `Recognition_Gate::is_available()`
returned true without a key and `API_Key_Store::has_key()` is wrong. The tell is
a pending action existing in the group when there should be none. Nothing will
ever run those rows, because the job path is only reachable through the queue.

**Result**

```
```

---

## 3. Wrong model name is terminal, and does not lose the product

**Steps.** Save a real API key. Set the model to `gemini-does-not-exist` — it
passes the shape check, so it will save. Import 3 rows, then run the queue.

Costs nothing in API spend: every call 404s before generating anything.

**Pass**

- Products **are created**, as drafts, with **no description**.
- Outcome `created_verify`, reason contains "The configured model no longer
  exists".
- `attempts` is **1**. A 404 is terminal and must not be retried.

**Most likely failure.** Two distinct ones, and they mean opposite things.
`attempts` climbing past 1 means 404 leaked into the retryable set — three calls
to be told the same thing. Rows ending `failed` with **no product** means the
"create without copy on a non-retryable failure" path did not fire, and a
configuration typo has just thrown away work the user already reviewed in the
preview.

**Result**

```
```

---

## 4. A network failure reads as UNAVAILABLE, never BLOCKED

This is the most important scenario in the plan, and the first end-to-end check
of `Gate_Policy`'s core safety property.

`BLOCKED` means *the model says it does not know this product*. `UNAVAILABLE`
means *nobody could ask*. If a network failure surfaces as BLOCKED, the gate has
failed in the direction that **looks safe**: the user sees "Not recognised",
dutifully types in the details by hand for every row, and never learns their key
was fine and their connection was not. Nothing in the UI would contradict it.

**Steps.** Key saved, valid model, Pro on. Add to `wp-config.php`:

```php
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
```

Paste 3 rows and press Preview. **Stop at the preview** — scenario 5 continues
from here.

**Pass**

- Status column reads **"Check unavailable"**, not "Not recognised".
- Hovering it shows a reason naming the transport failure, not a product
  judgement.
- Rows stay **selectable** — the gate being down is not grounds to block an
  import, and the free tier has no gate at all.
- No blocked-row detail panel appears.

**Most likely failure.** Rows showing "Not recognised" with detail panels. That
means the provider exception is being mapped to `BLOCKED` instead of
`UNAVAILABLE` somewhere between `Recognition_Gate::ask()` and
`Gate_Policy::unavailable()`. A subtler variant: the rows are correctly
`UNAVAILABLE` but got **cached** that way, in which case they will still show
"Check unavailable" after you restore the network. `Gate_Policy::is_cacheable()`
should prevent that — verify by removing the constant and re-previewing the same
names, which must produce real verdicts immediately.

**Result**

```
```

---

## 5. The outer retry loop actually reschedules

Continues from scenario 4 with `WP_HTTP_BLOCK_EXTERNAL` still defined. This is
new code that has never run, and its failure mode is silence.

**Optional — shorten the wait.** The schedule is filterable, so no source edit is
needed. Add to `bli-dev.php`:

```php
add_filter( 'bli_outer_backoff', fn() => array( 5, 5 ) );
```

**Steps.** Import the 3 rows from scenario 4. Then run the queue, wait, and run
it again. Default timings are **60s then 300s** — terminal after about six
minutes.

| After | `attempts` | outcome | reason ends with |
|---|---|---|---|
| 1st run | 1 | `pending` | "retrying in 1 min (attempt 2 of 3)" |
| ~60s | 2 | `pending` | "retrying in 5 mins (attempt 3 of 3)" |
| ~300s | 3 | `failed` | "gave up after 3 attempts" |

Between runs, Scheduled Actions must show a **pending** `bli_process_row` with a
**future** date. That pending action is the proof the reschedule happened.

**Pass** — `attempts` reaches exactly 3 and stops. The row ends `failed`, not
`pending`. No product was created for it.

**Most likely failure, and it is quiet.** If after the first run there is **no
pending action** and the row sits at `pending` with `attempts` = 1, the
reschedule did not happen and that row is dead — it will never be picked up
again, and the report will claim it is still waiting. `attempts` climbing past 3
means `has_outer_attempts_left()` is off by one. `attempts` stuck at 1 across
several runs, while actions *are* being rescheduled, means the counter is not
persisting and the loop will never terminate.

**Teardown — do this before scenario 6.** Remove `WP_HTTP_BLOCK_EXTERNAL` from
`wp-config.php`. Leaving it defined makes every later test look like a network
failure.

**Result**

```
```

---

## 6. Deactivate and reactivate (dbDelta idempotence)

The classic activation-hook bug only appears on the *second* activation.

**Steps.** With both tables populated from earlier scenarios:

```bash
wp db query "SELECT COUNT(*) AS rows_before FROM $(wp db prefix)bli_import_rows"
wp plugin deactivate bulk-list-import && wp plugin activate bulk-list-import
wp db query "SELECT COUNT(*) AS rows_after FROM $(wp db prefix)bli_import_rows"
wp option get bli_db_version
tail -50 wp-content/debug.log
```

**Pass** — row count unchanged, `bli_db_version` still `1`, no new errors logged.

**Most likely failure, and the highest-risk item here.** LocalWP runs MySQL 8,
which **removed integer display widths** from `SHOW CREATE TABLE`. The schema
declares `bigint(20) unsigned`; MySQL 8 reports back `bigint unsigned`. dbDelta
compares those as strings, concludes the column changed, and issues an
`ALTER TABLE … CHANGE COLUMN` on every run. That is harmless on its own —
WordPress core carries the same mismatch throughout — but the symptom to watch
for is a **"Duplicate key name"** error, which is what happens when dbDelta's
index comparison misfires and it tries to re-add `created_at` or `import_id`. If
that appears, the fix is to name the keys explicitly, not to drop them.

**If `VERSION()` above reported MariaDB, this prediction does not apply** —
MariaDB retains display widths, so the mismatch that drives it does not exist and
any failure here has a different cause.

Data loss would be a different bug entirely: dbDelta never drops columns, so an
*empty* table after reactivation would mean the activation hook is calling
something other than `Schema::install()`.

**Result**

```
```

---

## 7. Uninstall removes everything

Destructive, and last for that reason.

**Steps.** Deactivate, then **Delete** from the Plugins screen. Deactivation
alone runs nothing — `uninstall.php` fires only on deletion.

```bash
wp db query "SHOW TABLES LIKE '$(wp db prefix)bli_%'"
wp db query "SELECT option_name FROM $(wp db prefix)options \
  WHERE option_name LIKE 'bli\_%' OR option_name LIKE '\_transient%bli\_%'"
```

**Pass** — both queries return nothing.

**Most likely failure.** Tables left behind. `uninstall.php` runs with the plugin
un-bootstrapped: no autoloader, no constants, no WooCommerce. If anything in it
reaches for a plugin class it fatals silently, and WordPress deletes the files
anyway — leaving orphaned tables and no trace of why. It deliberately rebuilds
the table names from `$wpdb->prefix` for exactly this reason, so a failure here
points at something else in that file.

Remember to remove `wp-content/mu-plugins/bli-dev.php` afterwards.

**Result**

```
```

---

## Summary

| # | Scenario | Pass / fail | Notes |
|---|---|---|---|
| 1 | Activation creates both tables | | |
| 2 | No key falls back to synchronous | | |
| 3 | Wrong model is terminal, keeps the product | | |
| 4 | Network failure is UNAVAILABLE, not BLOCKED | | |
| 5 | Outer retry reschedules and stops at 3 | | |
| 6 | Reactivation is a dbDelta no-op | | |
| 7 | Uninstall removes everything | | |

### Still unverified after this plan

Worth naming so nobody reads a clean sheet as more than it is.

- **A successful generation end to end.** Scenarios 3–5 all exercise failure
  paths. Nothing here confirms that a working key and a valid model produce a
  product with real copy, correct attributes, and `wp_kses_post()` applied.
- **Concurrency.** The custom tables exist because concurrent jobs would lose
  updates against a serialised option. Nothing here runs two jobs at once.
- **A large import.** Everything above uses three to five rows. The batch-size
  warning, the one-call recognition cap, and the deferred `UNCHECKED` path only
  engage past 25 rows.
- **The adversarial sets** in `tests/fixtures/recognition-adversarial.json`,
  which are the 4f gate and need a live model.
