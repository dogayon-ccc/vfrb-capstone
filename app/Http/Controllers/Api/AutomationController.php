<?php
// app/Http/Controllers/Api/AutomationController.php
// Manual "Run Now" trigger for the daily automation job — same command the
// scheduler calls, so manual and scheduled runs can never drift apart.

namespace App\Http\Controllers\Api;

use App\Console\Commands\VfrbDailyDigest;
use App\Http\Controllers\Controller;
use App\Support\ScheduledJobs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class AutomationController extends Controller
{
    // ── POST /api/admin/automation/run-digest (manager only) ───────────────
    // A run already in progress (cron or another click) is reported as skipped with 200, so Reports.jsx shows the message.
    // `summary` counts what this run sent (VfrbDailyDigest::SUMMARY_KEYS); null when skipped.
    public function run(): JsonResponse
    {
        $skipped = Artisan::call('vfrb:daily-digest') === VfrbDailyDigest::BUSY;
        $output = trim(Artisan::output());

        return response()->json([
            'message' => $skipped ? 'Automation is already running. Try again in a minute.' : 'Automation run complete.',
            'skipped' => $skipped,
            'summary' => $skipped ? null : VfrbDailyDigest::parseSummary($output),
            'output'  => $output,
        ]);
    }

    // ── GET|POST /api/cron/run (Authorization: Bearer <CRON_SECRET>) ───────
    // Serverless stand-in for schedule:run: runs every ScheduledJobs entry whose time today has passed.
    // A job busy elsewhere (e.g. Run Now holding the digest lock) is listed in `skipped`; that run covers it.
    public function cron(Request $request): JsonResponse
    {
        $secret = (string) config('services.cron.secret');
        $given = (string) $request->bearerToken();
        if ($secret === '' || $given === '' || ! hash_equals($secret, $given)) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $lock = Cache::lock('vfrb:cron-run', 300);
        if (! $lock->get()) {
            return response()->json(['message' => 'A cron run is already in progress.', 'ran' => [], 'skipped' => []], 409);
        }

        $ran = [];
        $skipped = [];
        try {
            foreach (ScheduledJobs::due() as $command) {
                if (Artisan::call($command) === VfrbDailyDigest::BUSY) {
                    $skipped[] = $command;
                } else {
                    $ran[$command] = trim(Artisan::output());
                }
            }
        } finally {
            $lock->release();
        }

        return response()->json(['message' => 'Cron run complete.', 'ran' => $ran, 'skipped' => $skipped]);
    }
}
