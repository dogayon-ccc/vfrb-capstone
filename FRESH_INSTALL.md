# Database resets — read this before running `migrate:fresh`

**Don't run `php artisan migrate:fresh` on this project.** It will fail
partway through, repeatedly, on a cascading series of tables.

## Why

This database was never built from Laravel migrations alone. It was
provisioned once by directly importing `vfrb_db.sql`, and every migration
written since then is an incremental `Schema::table()` (ALTER) patch on
top of that import — assuming the table already exists. As of Aug 27 2026,
roughly **22 of the 34 real tables have no `Schema::create()` migration at
all**, including the entire Spatie permission package (`roles`,
`permissions`, `model_has_roles`, `model_has_permissions`,
`role_has_permissions` — the vendor migration was never committed).

Running `migrate:fresh` starts from zero and executes every migration
in order — including ALTER patches for tables that were never defined
from scratch. It fails on the first such table, and if you keep
re-running it after skipping/faking that one, it fails again on the next.

This is not a bug any one contributor introduced — it's how the project
has been set up since the beginning. It was only discovered because
someone actually tried a from-scratch `migrate:fresh` for the first time
on Aug 27 2026.

## What to do instead

Use `scripts/fresh-install.sh`. It:
1. Imports the real, current, authoritative schema from `vfrb_db.sql`
   (this file is ahead of any migration if the two ever disagree — it's
   the actual schema this app runs against).
2. Truncates every business-data table (orders, materials, transactions,
   etc.) so you start with zero test data.
3. Leaves the framework/package tables untouched (`migrations`, `roles`,
   `permissions`, `role_has_permissions`, `model_has_permissions`,
   `cache`, `jobs`, etc.).
4. Prompts you to create one manager account so you can log in
   immediately.

Run it from the `backend/` directory:
```
./scripts/fresh-install.sh
```

## The two options considered, and why this one was picked

1. **Hand-write the ~22 missing `Schema::create()` migrations** — the
   "correct" long-term fix, but with the Aug 31 defense date this close,
   and no way to test `migrate:fresh` end-to-end in every environment
   before then, one wrong FK/index/enum breaks the whole chain with very
   little time to catch it.
2. **Formalize the working reset process** (this script) — lower risk,
   already proven by hand on Aug 27 2026, gives every future session a
   reliable from-zero path without touching the migration chain at all.

Option 2 was chosen for now. If there's ever time after Aug 31, writing
the real migrations is still worth doing — `vfrb_db.sql` is the exact
reference to build them from.

## Status of related items, for whichever session picks this up next

- Customer-facing feedback widget (`FeedbackWidget.jsx`) — built and
  claimed mounted in `CustomerLayout.jsx`. Not independently verified in
  this session since `CustomerLayout.jsx` wasn't part of the files
  reviewed here — confirm before assuming it's live.
- `AIMaterials.jsx` still linked in the customer sidebar pending
  Account 2's OrderWizard/AI-flow restructuring (scoped, not built).
- Comment-bloat trim on `AIController.php`/`AdminLayout.jsx` — not
  started.
