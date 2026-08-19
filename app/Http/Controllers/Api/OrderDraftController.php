<?php
// app/Http/Controllers/Api/OrderDraftController.php
//
// Task T — Design Studio draft persistence
//
// Three endpoints, all require auth:sanctum + role:customer:
//
//   GET  /api/customer/drafts/latest   → return latest draft for the authed user
//   POST /api/customer/drafts          → upsert (one draft per user — always overwrites)
//   DELETE /api/customer/drafts/latest → clear draft (called after Order This completes)
//
// Design decisions:
//   - One draft per user. No draft list UI yet (Month 5 stretch goal).
//     updateOrCreate on user_id means zero orphan rows.
//   - preview_dataurl is optional — if canvas PNG export failed (WebGL context lost),
//     the config is still saved. Frontend shows a garment SVG fallback.
//   - studio_config stored as JSON. Laravel json() column casts to array automatically.
//   - No Cache::remember — drafts must always be fresh (user's own live data).

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderDraftController extends Controller
{
    // ── GET /api/customer/drafts/latest ──────────────────────────────────────
    // Returns the user's latest draft, or 404 if none exists.
    // Called on DesignStudio mount to restore previous session.
    public function latest()
    {
        $draft = DB::table('order_drafts')
            ->where('user_id', Auth::id())
            ->orderByDesc('updated_at')
            ->first();

        if (!$draft) {
            return response()->json(['draft' => null], 200);
        }

        // Decode the JSON column so frontend receives a plain object, not a string
        $draft->studio_config = is_string($draft->studio_config)
            ? json_decode($draft->studio_config, true)
            : $draft->studio_config;

        return response()->json(['draft' => $draft], 200);
    }

    // ── POST /api/customer/drafts ─────────────────────────────────────────────
    // Upserts the user's draft. One row per user — always overwrites.
    // Called by the 30-second auto-save debounce AND the manual Save button.
    public function store(Request $request)
    {
        $request->validate([
            'studio_config'   => 'required|array',
            'preview_dataurl' => 'nullable|string|max:300000', // ~220KB base64 PNG
            'label'           => 'nullable|string|max:120',
        ]);

        $userId = Auth::id();

        DB::table('order_drafts')->updateOrInsert(
            ['user_id' => $userId],
            [
                'studio_config'   => json_encode($request->input('studio_config')),
                'preview_dataurl' => $request->input('preview_dataurl'),
                'label'           => $request->input('label'),
                'updated_at'      => now(),
                'created_at'      => DB::table('order_drafts')
                                        ->where('user_id', $userId)
                                        ->exists()
                                     ? DB::table('order_drafts')
                                           ->where('user_id', $userId)
                                           ->value('created_at')
                                     : now(),
            ]
        );

        return response()->json(['message' => 'Draft saved.'], 200);
    }

    // ── DELETE /api/customer/drafts/latest ───────────────────────────────────
    // Clears the user's draft after they complete an order.
    // Called by orderThis() in DesignStudio immediately before navigate().
    public function destroy()
    {
        DB::table('order_drafts')
            ->where('user_id', Auth::id())
            ->delete();

        return response()->json(['message' => 'Draft cleared.'], 200);
    }
}
