<?php
// app/Http/Controllers/Api/VersionController.php — VFRB Enterprise
// NEW Aug 30 2026 — added specifically to answer "what's actually live
// on Railway right now" without needing to SSH in and check by hand.
// This has repeatedly been a real point of confusion this project
// (Railway 3 days behind local, uncertainty about which commit is
// deployed) — this endpoint exists to make that answerable in one
// request instead of guessing from screenshots.
//
// Deliberately PUBLIC (no auth) — a validator or anyone checking
// deploy status shouldn't need to log in first. Returns no sensitive
// data: no env values, no user data, no stack traces on failure.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class VersionController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'app'              => 'vfrb-capstone',
            'commit'           => $this->currentCommit(),
            'railway_deploy_id'=> $this->deployId(),
            'php_version'      => PHP_VERSION,
            'laravel_version'  => app()->version(),
            'database'         => $this->databaseStatus(),
            'checked_at_utc'   => now()->utc()->toIso8601String(),
        ]);
    }

    /**
     * Real commit hash if available. Railway's build environment
     * doesn't guarantee a .git directory survives into the deployed
     * container, so this deliberately falls back to a Railway-provided
     * env var rather than failing — RAILWAY_GIT_COMMIT_SHA is set
     * automatically by Railway on GitHub-triggered deploys (confirmed
     * against docs.railway.com), which matches how this service is
     * configured. Caveat found while researching this, worth knowing:
     * some Railway users report this var showing up empty despite the
     * docs saying it should populate — if 'commit' comes back null
     * here even right after a real deploy, that's a known Railway-side
     * inconsistency to check for, not necessarily a bug in this code.
     */
    private function currentCommit(): ?string
    {
        $envSha = env('RAILWAY_GIT_COMMIT_SHA');
        if ($envSha) {
            return substr($envSha, 0, 7);
        }

        // Local dev fallback — only works if .git is actually present
        // (it usually isn't inside a deployed container, by design).
        $head = @exec('git rev-parse --short HEAD 2>/dev/null');
        return $head ?: null;
    }

    private function deployId(): ?string
    {
        // RAILWAY_DEPLOYMENT_ID is real and Railway-provided; there is no
        // confirmed Railway-provided deploy TIMESTAMP variable (checked
        // docs.railway.com before writing this — didn't find one, so not
        // claiming one exists). deployed_at is therefore left out rather
        // than filled with a guess; Railway's own dashboard is still the
        // source of truth for exact deploy time.
        return env('RAILWAY_DEPLOYMENT_ID') ?: null;
    }

    /**
     * Real connectivity check, not just "did config load" — actually
     * runs a trivial query so a broken DB_* env var on Railway shows
     * up here immediately instead of only surfacing when a real
     * feature silently 500s later.
     */
    private function databaseStatus(): array
    {
        try {
            DB::select('SELECT 1');
            return ['connected' => true, 'error' => null];
        } catch (Throwable $e) {
            // Message only, never the full exception — this is a public
            // route, don't leak connection strings/credentials in a trace.
            return ['connected' => false, 'error' => 'Database connection failed'];
        }
    }
}
