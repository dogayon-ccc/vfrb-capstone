# AI recommendation review (thesis Table 4-x)

Answers the Data Analyst's request: run the recommender on 15 to 20 test orders, have the person who decides materials rate each suggestion, report counts, fallback rate and allowed-list rejections.

## Run it (Laragon, `vfrb-capstone`)

1. `.env` needs `GEMINI_KEY_1` (and optionally `GEMINI_KEY_2`/`GEMINI_KEY_3`, `GEMINI_KEY_COUNT`). The model is `GEMINI_MODEL` (default `gemini-3.8-flash`); the run file records it.
2. Orders to test must have a Studio design (`studio_config`). For the demo set: `DB_DATABASE=vfrb_demo php artisan db:seed --class=DemoDataSeeder` (it refuses any other database name). It makes Polo, Scrub Top, Barong and Mandarin orders only; add orders in the Studio for other garments and colours VFRB produces.
3. `php artisan vfrb:ai-review-run --latest=20` or `--orders=9,12,15`. Writes `storage/app/ai-review/run-<stamp>.json` and `rating-sheet-<stamp>.csv`. No database writes, no cache; each order's design context is sent to Google as in the live endpoint.
4. Give the CSV to the owner or production supervisor. Per row: `rating` = `appropriate` / `acceptable` / `inappropriate`; `vfrb_used` = material codes (`;`-separated) VFRB used on that past order, where records exist.
5. `php artisan vfrb:ai-review-report storage/app/ai-review/run-<stamp>.json storage/app/ai-review/rating-sheet-<stamp>.csv` prints the table. Add `--json` to copy exact figures.

## What the numbers mean

- Rating counts and percentages are the owner's judgement of materials only. The AI never produces quantities, yardage or prices.
- `catalog_fallback`: orders where Gemini gave no usable reply and the deterministic catalogue pick was used. These are not AI results; report them separately.
- `rejected_not_in_allowed_list`, `rejected_duplicate`: Gemini picks removed by the allowed-list check.
- The report refuses percentages while any suggestion is unrated.
- State in the manuscript: model name from the run file, test date, and that outputs can vary between runs.
- Do not report a run made without a Gemini key: every order then shows `catalog_fallback` at 100%.
