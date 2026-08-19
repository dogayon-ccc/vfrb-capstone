<?php
// app/Http/Controllers/Api/AutomationController.php
// Manual "Run Now" trigger for the daily automation job — same command the
// scheduler calls, so manual and scheduled runs can never drift apart.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\JsonResponse;

class AutomationController extends Controller
{
    // ── POST /api/admin/automation/run-digest (manager only) ───────────────
    public function run(): JsonResponse
    {
        Artisan::call('vfrb:daily-digest');
        $output = trim(Artisan::output());

        return response()->json([
            'message' => 'Automation run complete.',
            'output'  => $output,
        ]);
    }
}
