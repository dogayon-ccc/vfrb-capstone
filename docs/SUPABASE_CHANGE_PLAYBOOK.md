# Changing the database safely (Supabase / Postgres)

Local development may run MySQL; production runs Supabase (Postgres). A change that works locally can fail in production.
This page is the routine for every database or backend change. Do the steps in order.

## 0. Know the two databases
| | Local | Production |
|---|---|---|
| Engine | MySQL (Laragon) | Postgres (Supabase) |
| Flags (yes/no columns) | `tinyint(1)` = 0/1 | may be `boolean` or `smallint` |
| Month labels, JSON objects, case-insensitive LIKE | differ | differ |

Rule: raw SQL that differs by engine goes through `App\Support\Sql` (`Sql::flag()`, `Sql::monthLabel()`, `Sql::like()` ...).
Never write `passed = 1`, `DATE_FORMAT(...)`, `IFNULL(...)` straight into a query.

## 1. Before you change anything
1. `git pull` and make sure the branch is clean (`git status -sb`).
2. Run the guard: `php scripts/pg-compat-check.php` (must print `OK`). The same check runs on GitHub for every push.

## 2. Make the change as a migration (never edit the live database by hand)
1. `php artisan make:migration add_something_to_orders_table`
2. Use Laravel's schema builder (`$table->string(...)`). If you must write raw SQL, guard it:
   `if (DB::connection()->getDriverName() !== 'pgsql') { return; }` or branch with `Sql::isPostgres()`.
3. Write `down()` so the change can be undone.
4. Every new table needs Row Level Security: copy the pattern in `2026_11_02_000001_enable_rls_on_remaining_tables.php`.

## 3. Test locally
`php artisan migrate` then `php artisan test` (all green) and `php scripts/pg-compat-check.php`.

## 4. Production (Supabase) - owner action
1. Take a backup first (Supabase dashboard > Database > Backups) and note the time.
2. Preview without changing anything: `php artisan migrate --pretend` with production `.env`. Read the SQL it prints.
3. Apply: `php artisan migrate --force`. Never import the old MySQL dump into production.
4. Verify: open the affected pages (Admin Activity Log, Orders, Production). Check logs for SQL errors.
5. If it fails: `php artisan migrate:rollback --step=1`, or restore the backup from step 1.

## 5. One-time check the owner must run in the Supabase SQL editor
The Activity Log was comparing flags to the number 1. Confirm what the production column types are:

```sql
select table_name, column_name, data_type
from information_schema.columns
where table_schema = 'public'
  and column_name in ('passed', 'stock_adjusted', 'is_active', 'is_default', 'reconciled')
order by table_name, column_name;
```
`boolean` or `smallint` are both fine now (`Sql::flag()` reads either). Keep the result for the capstone defense evidence.

## 6. AI and client reference photos (what the code guarantees)
- The photo is untrusted. The model may only choose garment names from a fixed list (`ReferenceValidator::GARMENTS`).
- The accept / needs review / reject decision is made in PHP (`decide()`), not by the model.
- Uncertain, one-piece, PDF with several pages, unreadable or provider failure all go to staff review. Nothing is guessed.
- The model never outputs sizes, quantities, prices or dates. AI material suggestions never change stock, price or orders.
- The same file is answered from cache for 24 hours (key = SHA-256 of the file + prompt version). Failures are never cached.
