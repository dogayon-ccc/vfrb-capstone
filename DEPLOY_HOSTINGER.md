# Deploying VFRB on Hostinger + Cloudflare + Cloudinary

Layout: `your-domain.com` serves the React build (static). `api.your-domain.com` serves Laravel from its `public/` folder.

## 0. Confirm before you buy or deploy
Not verified from this repo; check in hPanel:
- PHP **8.4** is selectable (`composer.json` requires `^8.4`). If the plan tops out below that, the backend will not boot.
- SSH access and Composer exist on your plan. Without SSH, run `composer install --no-dev` locally and upload `vendor/`.
- A subdomain's document root can point at a custom folder (needed to expose only `public/`).
- Cron jobs are available.

## 1. Database
1. hPanel > Databases: create a MySQL database + user.
2. phpMyAdmin > Import `vfrb_db.sql` (or run `php artisan migrate --force` on an empty DB).
3. **Always run `php artisan migrate --force` afterwards.** The dump predates `2026_10_04_000001_add_ai_catalog_controls_to_materials`, which adds `materials.ai_eligible` and `materials.applies_to`.
4. The dump holds real account data. Do not share it or commit it.

## 2. Backend
1. Put the project **outside** the web root (for example `~/vfrb-capstone`). Point the `api` subdomain's document root at `~/vfrb-capstone/public`.
   Fallback if the document root cannot be changed: never expose the project root; the `.env` and `vendor/` must not be web-readable.
2. `composer install --no-dev --optimize-autoloader`
3. `cp .env.hostinger.example .env`, fill it in, then `php artisan key:generate`.
4. `chmod -R ug+rwX storage bootstrap/cache`
5. `php artisan migrate --force`
6. `php artisan config:cache && php artisan route:cache` (safe: no `env()` calls remain outside `config/`).
7. `php artisan storage:link` only if you serve any uploads from local disk. With Cloudinary configured, uploads do not use it.
8. Cron (hPanel > Advanced > Cron Jobs), every minute:
   `/usr/bin/php /home/USER/vfrb-capstone/artisan schedule:run >> /dev/null 2>&1`
   Use the full path of the PHP 8.4 binary that hPanel shows.

## 3. Frontend
1. Copy `.env.production.example` to `.env.production`, set `VITE_API_URL=https://api.your-domain.com` (no `/api`).
2. `npm ci && npm run build`
3. Upload the **contents** of `dist/` to `public_html/`, including the hidden `.htaccess` (SPA fallback and cache headers).

## 4. Cloudflare
- Register the domain at GoDaddy, then set GoDaddy's nameservers to the two Cloudflare gives you.
- DNS records for `@`/`www` and `api` point at the Hostinger server IP, proxied.
- SSL/TLS mode: **Full (strict)** (install Hostinger's SSL first).
- Add a cache rule to **bypass cache** for `api.your-domain.com/*`.
- Leave Rocket Loader off; it can break the module-based app.

## 5. Cloudinary and Gemini
- Cloudinary: fill `CLOUDINARY_*`, then upload a design reference or a company logo and confirm the stored URL is a `res.cloudinary.com` link.
- Gemini: in Google AI Studio, enable billing on the project that owns the keys, set the **project spend cap**, then put the keys in `.env`. Confirm `GEMINI_MODEL` exists for your key in the model list before the demo.

## 6. Controlling what the AI may recommend
The AI can only choose from `materials` rows that are `ai_eligible` and whose `applies_to` includes the order's garment. Defaults change nothing (all materials, all garments).
```sql
-- hide a material from the AI (it stays in stock and in manual selection)
UPDATE materials SET ai_eligible = 0 WHERE material_id = 3;

-- restrict a material to certain garments (names must match the garment names used in the Studio)
UPDATE materials SET applies_to = JSON_ARRAY('Pants','Shorts') WHERE material_id = 16;

-- back to "all garments"
UPDATE materials SET applies_to = NULL WHERE material_id = 16;
```
The catalog has near-duplicates (for example ids 14 vs 4/5 for polyester thread, 16 vs 6 for elastic). Decide with VFRB staff which to expose; the AI will otherwise see both.

## 7. Smoke test
- `https://api.your-domain.com/up` returns 200; `/api/version` shows `database.connected: true`.
- Log in as customer and as staff; load a page that lists orders (checks CORS + token auth).
- Submit an order, run the material recommendation, confirm only catalog materials appear with no quantities or prices.
- Google sign-in returns to `FRONTEND_URL` (not localhost).

## Rollback
Keep the previous release folder and swap the document root back. `php artisan migrate:rollback --step=1` removes the two new `materials` columns; the code tolerates them being absent, so it can also just stay.
