<?php
// app/Http/Controllers/Api/VersionController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

// GET /api/version -- what is actually live: release/commit, DB connectivity.
// Set APP_RELEASE (e.g. the git short SHA) in .env when deploying; it is read through config so config:cache is safe.
class VersionController extends Controller
{
    public function show(): JsonResponse
    {
        $payload = [
            'app'            => 'vfrb-capstone',
            'commit'         => $this->currentCommit(),
            'deploy_id'      => config('services.release.deploy_id') ?: null,
            'database'       => $this->databaseStatus(),
            'checked_at_utc' => now()->utc()->toIso8601String(),
        ];

        // Runtime versions help debugging but are not worth publishing on a public endpoint.
        if (config('app.debug')) {
            $payload['php_version']     = PHP_VERSION;
            $payload['laravel_version'] = app()->version();
        }

        return response()->json($payload);
    }

    private function currentCommit(): ?string
    {
        $release = config('services.release.sha');
        if ($release) {
            return substr($release, 0, 7);
        }

        // Shared hosts often disable exec(); fall back to reading .git/HEAD directly.
        $head = base_path('.git/HEAD');
        if (is_readable($head)) {
            $ref = trim((string) file_get_contents($head));
            if (str_starts_with($ref, 'ref: ')) {
                $refFile = base_path('.git/' . substr($ref, 5));
                $ref     = is_readable($refFile) ? trim((string) file_get_contents($refFile)) : '';
            }
            return preg_match('/^[0-9a-f]{40}$/', $ref) ? substr($ref, 0, 7) : null;
        }
        return null;
    }

    private function databaseStatus(): array
    {
        try {
            DB::select('SELECT 1');
            return ['connected' => true, 'error' => null];
        } catch (Throwable $e) {
            return ['connected' => false, 'error' => 'Database connection failed'];
        }
    }
}
