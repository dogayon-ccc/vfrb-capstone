<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Database\Seeders\DefenseSeeder;
// FIX (Sony Mark, Sept 7 2026): App\Models\User import removed — it was
// only used by the broken User::factory() boilerplate call below, which
// no longer exists.

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // FIX (Sony Mark, Sept 7 2026) — removed, not just left alone.
        // This was Laravel's default boilerplate, unrelated to VFRB, and
        // it was genuinely broken: App\Models\User doesn't use the
        // HasFactory trait and no UserFactory.php exists in
        // database/factories/, so `User::factory()->create(...)` threw
        // `BadMethodCallException: Call to undefined method
        // App\Models\User::factory()` on every real `php artisan
        // db:seed` run — confirmed by actually running it. This means
        // plain `db:seed` (no --class flag) has never worked in this
        // repo, independent of the DefenseSeeder wiring below. The
        // placeholder "Test User" it tried to create has no purpose now
        // that DefenseSeeder provides real, meaningful demo accounts.
        //
        // Previously:
        //   User::factory(10)->create();
        //   User::factory()->create(['name' => 'Test User', 'email' => 'test@example.com']);

        // DefenseSeeder existed as a real,
        // verified-idempotent seeder (firstOrCreate/updateOrCreate
        // throughout, zero raw create() calls — confirmed by direct grep)
        // but was never called from here, so `php artisan db:seed` alone
        // never created Roxanne's manager account or any of the demo
        // data. Verified by actually running it twice against a real
        // local DB: same 9 demo orders (#5-#13) both times, no
        // duplicates, Roxanne's account persists correctly.
        $this->call(DefenseSeeder::class);
    }
}
