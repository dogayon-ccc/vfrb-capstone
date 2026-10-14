<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-time insert of the read-only 'owner' row into roles. Creates no user and assigns nobody.
 * Refuses to run when 'owner' already exists. --dry-run rolls back.
 */
class VfrbAddOwnerRole extends Command
{
    protected $signature = 'vfrb:add-owner-role {--dry-run : Print what would be written, then roll back}';

    protected $description = 'Add the read-only owner role to the roles table';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // Same guard as the existing manager row, so the new role matches what is already there.
        $guard = DB::table('roles')->where('name', 'manager')->value('guard_name');
        if (!$guard) {
            $this->error('No manager role found to copy guard_name from; refusing.');
            return self::FAILURE;
        }

        $this->line('roles now: ' . DB::table('roles')->orderBy('id')->get(['id', 'name', 'guard_name'])
            ->map(fn ($r) => "{$r->id}={$r->name}({$r->guard_name})")->implode(', '));

        if (DB::table('roles')->where('name', 'owner')->exists()) {
            $this->error("Role 'owner' already exists; refusing.");
            return self::FAILURE;
        }

        $before = DB::table('roles')->count();

        if (!$dry) {
            $path = 'backups/roles-' . now()->format('Ymd-His') . '.json';
            if (!Storage::disk('local')->put($path, DB::table('roles')->get()->toJson(JSON_PRETTY_PRINT))) {
                $this->error('Backup failed for roles; nothing written.');
                return self::FAILURE;
            }
            $this->line("Backup: storage/app/private/{$path} ({$before} rows)");
        }

        $row = ['name' => 'owner', 'guard_name' => $guard, 'created_at' => now(), 'updated_at' => now()];

        DB::beginTransaction();
        try {
            $id = DB::table('roles')->insertGetId($row);
            $this->line("insert roles: id={$id} name=owner guard_name={$guard}");
            $this->line("roles: {$before} -> " . DB::table('roles')->count());

            if ($dry) {
                DB::rollBack();
                $this->warn('DRY RUN: rolled back, nothing written.');
            } else {
                DB::commit();
                $this->info('Committed.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Rolled back: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Spatie caches roles; clear it so the new row is visible immediately.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return self::SUCCESS;
    }
}
