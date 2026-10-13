# VFRB Capstone — Claude rules

Laravel app for VFRB Enterprise (garment orders, production stages, materials, inventory).

## Audit first
- Before any change, read the relevant controller/model/view/migration and report what exists.
- State the planned edit and the files it touches before editing. No speculative refactors.

## AI scope
- AI is used for raw-material recommendation only (suggest materials for an order, with guidance and staff feedback).
- Do not add AI to pricing, cost estimates, usage rates, scheduling, or any other feature.
- Amended exception (Oct 2026, this feature only): `POST /api/ai/design-edits` turns a customer's request into strict DesignState operations (colour, pattern, text, placement) validated against the template's real zones. No quantities, sizing or prices.
- Do not reintroduce estimated ranges or usage-rate logic; that was removed on purpose.

## Migrations
- NEVER run, re-run, roll back, or refresh `2026_10_12_000001_remove_estimate_columns_and_usage_rates.php`.
- It is already applied locally; it drops columns, so a rollback/refresh loses data.
- No `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `migrate:rollback`. Ask before any `php artisan migrate`.

## Status labels
- Order statuses: pending, confirmed, pattern, segregation, cutting, sewing, qc, pressing, packing, completed.
- Display labels: Pending, Confirmed, In Production (pattern through packing), Delivered (completed).
- See the funnel map in `app/Http/Controllers/Api/AdminDashboardController.php`. Keep labels consistent across UI, API, and emails; never rename a status value.

## Git
- No commits, no pushes. The user commits.
- Never `git add .` or `git add -A`. If staging is requested, stage named files only.

## Off-limits
- Do not read `vendor/`, `node_modules/`, `storage/`, `*.lock`, or JSON backups in `storage/app/` (enforced in `.claude/settings.json`).
