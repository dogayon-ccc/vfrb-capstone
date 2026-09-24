<?php
// app/Http/Controllers/Api/AIController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\MaterialRecommendation;
use App\Models\User;
use App\Services\GeminiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    private GeminiClient $gemini;

    public function __construct(GeminiClient $gemini)
    {
        $this->gemini = $gemini;
    }
    // Gemini API calls moved to app/Services/GeminiClient.php (Sept 2026)
    // — same logic, now shared cleanly instead of living inline here.

    // =========================================================================
    // AI LAYER 1 — Raw Material Recommendation
    // POST /api/customer/ai/recommend-materials
    // =========================================================================
    public function recommendMaterials(Request $request)
    {
        $request->validate(['order_id' => 'required|integer']);

        $user  = Auth::user();
        $order = Order::where('order_id', $request->order_id)
                      ->where('user_id', $user->user_id)
                      ->firstOrFail();

        $order->update([
            'ai_recommendation_status'       => 'generating',
            'ai_recommendation_requested_at' => now(),
        ]);

        // FIX (Aug 1 2026 scope clarification): the AI's ONLY job is to look
        // at the real materials catalog and pick which ones apply to this
        // design, then explain why in plain language — no quantities, no
        // formulas, no yardage. It used to be asked for "range per piece"
        // and "total range", which is exactly the AI-as-calculator anti-
        // pattern the project scope explicitly rules out, AND it never set
        // material_id, so none of those recommendations were ever usable by
        // the materials-deduction or feasibility-check logic downstream —
        // this fixes both problems at once by having the AI select directly
        // from real catalog rows instead of inventing free-text names.
        $catalog = \App\Models\Material::select('material_id', 'material_name', 'category', 'unit')->get();
        if ($catalog->isEmpty()) {
            $order->update(['ai_recommendation_status' => 'failed']);
            return response()->json(['message' => 'No materials catalog configured yet.'], 422);
        }

        $prompt = $this->buildPrompt($order, $catalog);
        // BUG-008: was 1200 — too tight once thinking tokens (unavoidable on
        // gemini-3.6-flash, see callGemini()) share this same budget with the
        // actual JSON answer. Raised with headroom for both.
        $aiText = $this->gemini->call($prompt, 3000);

        if (!$aiText) {
            $order->update(['ai_recommendation_status' => 'failed']);
            return response()->json(['message' => 'AI service unavailable. Try again.'], 503);
        }

        $clean  = preg_replace('/```json|```/i', '', $aiText);
        $parsed = json_decode(trim($clean), true);

        if (!$parsed || empty($parsed['selections'])) {
            // BUG-008: log the raw response BEFORE discarding it. Without
            // this, the 422 path leaves zero trace of what Gemini actually
            // sent — can't fix a parse bug blind. Do not remove until the
            // real cause (truncation vs. stray prose vs. bad quoting) is
            // confirmed from real logged output.
            Log::warning('Gemini material-rec parse failed', [
                'order_id' => $order->order_id,
                'raw'      => $aiText,
            ]);
            $order->update(['ai_recommendation_status' => 'failed']);
            return response()->json(['message' => 'Could not parse AI response. Try again.'], 422);
        }

        // Defensive: only trust material_ids that actually exist in the
        // catalog we sent — never trust an LLM-generated ID at face value.
        $catalogById = $catalog->keyBy('material_id');

        MaterialRecommendation::where('order_id', $order->order_id)->delete();
        $i = 0;
        foreach ($parsed['selections'] as $sel) {
            $matId = (int) ($sel['material_id'] ?? 0);
            $mat   = $catalogById->get($matId);
            if (!$mat) continue; // hallucinated ID — skip rather than guess

            MaterialRecommendation::create([
                'order_id'              => $order->order_id,
                'material_name'         => $mat->material_name,
                'material_id'           => $mat->material_id, // FIX: previously always null
                'category'              => $mat->category ?? 'Other',
                'unit'                  => $mat->unit,
                // AI note is narration only — no numbers ever come from Gemini
                'ai_note'               => $sel['reason'] ?? null,
                'display_order'         => $i++,
                'status'                => 'pending',
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }

        $order->update(['ai_recommendation_status' => 'ready']);

        // SCOPE CORRECTION (Aug 28 2026): estimated_range/total_estimated_range
        // no longer exist on the create() call above at all — there is no
        // formula/BOM anywhere in this system now. Automated inventory
        // deduction still happens on Pattern completion, but the quantity
        // comes from staff manually entering actual usage at that point (see
        // ProductionStageService::issueMaterialsToProduction()), not from
        // anything computed here. Gemini recommends material TYPES only —
        // that was already the locked customer-facing rule (SCOPE-001); this
        // makes it true internally as well, so makeHidden() below is now
        // belt-and-suspenders rather than load-bearing (nothing to hide that
        // wasn't already never written).
        $materials = MaterialRecommendation::where('order_id', $order->order_id)
                        ->orderBy('display_order')->get();

        return response()->json([
            'recommendation' => $parsed['narration'] ?? '',
            'materials'      => $materials,
        ]);
    }

    // ── Customer chooses their own materials instead of accepting the AI's ──
    // GET /api/customer/materials-catalog — read-only, no stock/cost exposed
    public function customerMaterialsCatalog()
    {
        $catalog = \App\Models\Material::select('material_id', 'material_name', 'category', 'unit')
            ->orderBy('category')->orderBy('material_name')->get();
        return response()->json(['materials' => $catalog]);
    }

    // POST /api/customer/orders/{id}/select-materials — customer picks
    // material_ids themselves; same deterministic engine computes quantity;
    // notifies staff exactly like acceptRecommendation() does.
    public function customerSelectMaterials(Request $request, $orderId)
    {
        $request->validate([
            'material_ids'   => 'required|array|min:1',
            'material_ids.*' => 'integer|exists:materials,material_id',
            'notes'          => 'nullable|string|max:1000',
        ]);

        $user  = Auth::user();
        $order = Order::where('order_id', $orderId)
                      ->where('user_id', $user->user_id)
                      ->firstOrFail();

        $catalog = \App\Models\Material::whereIn('material_id', $request->material_ids)->get()->keyBy('material_id');

        MaterialRecommendation::where('order_id', $orderId)->delete();
        foreach ($request->material_ids as $i => $matId) {
            $mat = $catalog->get($matId);
            if (!$mat) continue;

            MaterialRecommendation::create([
                'order_id'              => $orderId,
                'material_name'         => $mat->material_name,
                'material_id'           => $mat->material_id,
                'category'              => $mat->category ?? 'Other',
                'unit'                  => $mat->unit,
                'ai_note'               => 'Selected directly by customer, not AI-recommended.',
                'display_order'         => $i,
                'status'                => 'accepted',
                'customer_accepted'     => 1,
                'accepted_at'           => now(),
                'customer_note'         => $request->notes,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);
        }

        $order->update([
            'ai_recommendation_status'      => 'accepted',
            'ai_recommendation_accepted_at' => now(),
        ]);

        $staffUsers = User::role(['staff', 'manager'])->get();
        foreach ($staffUsers as $staff) {
            // BUG FIX (pre-deployment audit): DB::table()->insert() instead of
            // Notification::create() — see acceptRecommendation() above for why.
            DB::table('notifications')->insert([
                'user_id'    => $staff->user_id,
                'order_id'   => $orderId,
                'message'    => "{$user->name} chose their own materials for Order #{$orderId} (skipped AI recommendation).",
                'type'       => 'materials_accepted',
                'title'      => "Materials Selected — Order #{$orderId}",
                'is_read'    => 0,
                'date_sent'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['message' => 'Your material selection has been sent to VFRB.']);
    }

    // =========================================================================
    // Accept recommendation → notify staff
    // POST /api/customer/orders/{id}/accept-materials
    // =========================================================================
    public function acceptRecommendation(Request $request, $orderId)
    {
        $request->validate(['notes' => 'nullable|string|max:1000']);

        $user  = Auth::user();
        $order = Order::where('order_id', $orderId)
                      ->where('user_id', $user->user_id)
                      ->firstOrFail();

        // BUG FIX (pre-deployment audit): this update is correctly scoped to
        // status='pending', which makes the DB write itself idempotent — a
        // second call after acceptance matches 0 rows. But the notification
        // loop below used to run unconditionally regardless of that count,
        // so double-clicking "Accept" (or a retried request) still sent
        // duplicate "materials accepted" notifications to every staff/manager
        // even though the order's actual state only changed once. Capture the
        // affected-row count and gate everything below it on that.
        $updated = MaterialRecommendation::where('order_id', $orderId)
            ->where('status', 'pending')
            ->update([
                'status'            => 'accepted',
                'customer_accepted' => 1,
                'accepted_at'       => now(),
                'customer_note'     => $request->notes,
                'updated_at'        => now(),
            ]);

        if ($updated === 0) {
            // Already accepted (or never had a pending recommendation) —
            // nothing changed, so nothing to notify. Not an error: the
            // customer's intent ("accept these materials") is already
            // satisfied, so we return success without re-firing side effects.
            return response()->json([
                'message'            => 'Materials were already accepted.',
                'materials_accepted' => true,
                'already_accepted'   => true,
            ]);
        }

        $order->update([
            'ai_recommendation_status'      => 'accepted',
            'ai_recommendation_accepted_at' => now(),
        ]);

        // Notify all staff + managers (using real notifications schema)
        // FIX: users table has no 'role' column — roles come from Spatie's
        // model_has_roles/roles tables via the HasRoles trait. whereIn('role', ...)
        // would throw "Unknown column 'role'" on every call. Use Spatie's role() scope.
        $staffUsers = User::role(['staff', 'manager'])->get();
        foreach ($staffUsers as $staff) {
            // BUG FIX (pre-deployment audit): Notification::create() silently
            // drops 'type' and 'title' because the model's $fillable omits
            // them even though both columns exist in the schema — Eloquent's
            // mass-assignment guard drops unlisted fields with no error.
            // Use DB::table()->insert() instead, same pattern already used
            // correctly by ProductionController::notifyStageAdvance().
            DB::table('notifications')->insert([
                'user_id'    => $staff->user_id,
                'order_id'   => $orderId,
                'message'    => "{$user->name} accepted AI material recommendations for Order #{$orderId}."
                              . ($request->notes ? " Note: {$request->notes}" : ''),
                'type'       => 'materials_accepted',
                'title'      => "Materials Accepted — Order #{$orderId}",
                'is_read'    => 0,
                'date_sent'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message'            => 'Materials accepted. VFRB staff have been notified.',
            'materials_accepted' => true,
            'already_accepted'   => false,
        ]);
    }

    // =========================================================================
    // Reject recommendation → order stays in 'rejected' state, no stock issued.
    // POST /api/customer/orders/{id}/reject-materials
    // NEW — mirrors acceptRecommendation() but declines instead of accepting.
    // =========================================================================
    public function rejectRecommendation(Request $request, $orderId)
    {
        $request->validate(['notes' => 'nullable|string|max:1000']);

        $user  = Auth::user();
        $order = Order::where('order_id', $orderId)
                      ->where('user_id', $user->user_id)
                      ->firstOrFail();

        // BUG FIX (pre-deployment audit): same idempotency + $fillable issue
        // as acceptRecommendation() — gate on affected-row count and use
        // DB::table()->insert() so 'type'/'title' actually persist.
        $updated = MaterialRecommendation::where('order_id', $orderId)
            ->where('status', 'pending')
            ->update([
                'status'            => 'rejected',
                'customer_accepted' => 0,
                'customer_note'     => $request->notes,
                'updated_at'        => now(),
            ]);

        if ($updated === 0) {
            return response()->json([
                'message'          => 'Materials were already declined.',
                'already_rejected' => true,
            ]);
        }

        $order->update([
            'ai_recommendation_status' => 'rejected',
        ]);

        $staffUsers = User::role(['staff', 'manager'])->get();
        foreach ($staffUsers as $staff) {
            DB::table('notifications')->insert([
                'user_id'    => $staff->user_id,
                'order_id'   => $orderId,
                'message'    => "{$user->name} declined the AI material recommendation for Order #{$orderId}."
                              . ($request->notes ? " Note: {$request->notes}" : ''),
                'type'       => 'materials_rejected',
                'title'      => "Materials Declined — Order #{$orderId}",
                'is_read'    => 0,
                'date_sent'  => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'message'            => 'Recommendation declined.',
            'materials_accepted' => false,
        ]);
    }

    // =========================================================================
    // Get recommendation summary for an order (customer OrderDetail.jsx)
    // GET /api/customer/orders/{id}/ai-recommendation
    // Synthesizes the material_recommendations rows for this order into the
    // single-object shape OrderDetail.jsx renders:
    //   { rec_id, status, customer_accepted, materials_json: {name: {category,unit}}, notes }
    // No quantity field anywhere in this shape — no formula/BOM exists in
    // this system (Aug 28 2026 design decision).
    // =========================================================================
    public function showForOrder($orderId)
    {
        $user  = Auth::user();
        $order = Order::where('order_id', $orderId)
                      ->where('user_id', $user->user_id)
                      ->firstOrFail();

        $rows = MaterialRecommendation::where('order_id', $orderId)
                    ->orderBy('display_order')->get();

        if ($rows->isEmpty()) {
            return response()->json(['recommendation' => null]);
        }

        $materialsJson = [];
        $notesParts    = [];
        foreach ($rows as $row) {
            // Material TYPE only — category/unit, never a quantity. This was
            // already the locked customer-facing rule (SCOPE-001) and is now
            // also true of every other code path in this file (Aug 28 2026):
            // admin/OrderDetail.jsx's separate endpoint (GET
            // /api/admin/orders/{id}) no longer has an estimated_range to
            // show either — that column is never written anymore.
            $materialsJson[$row->material_name] = [
                'category' => $row->category,
                'unit'     => $row->unit,
            ];
            if ($row->ai_note) {
                $notesParts[] = $row->ai_note;
            }
        }

        return response()->json([
            'recommendation' => [
                'rec_id'                => $rows->first()->rec_id,
                'status'                => $order->ai_recommendation_status,
                'customer_accepted'     => $rows->first()->customer_accepted,
                'materials_json'        => $materialsJson,
                'notes'                 => implode(' ', $notesParts),
            ],
        ]);
    }

    // =========================================================================
    // AI LAYER 2 — Design Prompt → Garment Config (Design Studio)
    // POST /api/ai/describe-design
    // =========================================================================
    public function describeDesign(Request $request)
    {
        // Two real callers hit this route with different shapes:
        //   AIPanel.jsx (in-canvas, one-shot)   -> { description }
        //   AIDesignChat.jsx (floating widget)  -> { prompt, chat_context, mode:'chat' }
        // They were never reconciled — chat mode always failed validation
        // (no `description` field) and, even past that, the response shape
        // this method returned had no `chat_response` key the widget reads.
        if ($request->input('mode') === 'chat') {
            return $this->describeDesignChat($request);
        }

        $request->validate(['description' => 'required|string|max:500']);

        $prompt = <<<PROMPT
You are a uniform design assistant for VFRB Enterprise, a garment manufacturer in Bayanan, Muntinlupa, Philippines.

Customer described: "{$request->description}"

Return ONLY valid JSON (no markdown, no backticks):
{
  "garmentType": "Top" or "Bottom",
  "category": "Medical / Scrubs" | "School Uniform" | "Corporate" | "PE/Sports",
  "collarType": "V-Neck" | "Polo Collar" | "Round Neck" | "Mandarin",
  "sleeveType": "Short Sleeve" | "Long Sleeve" | "3/4 Sleeve" | "Sleeveless",
  "pocketType": "none" | "left_chest" | "side_x2" | "both",
  "colors": { "body": "#hexcode", "accent": "#hexcode" },
  "pattern": "solid" | "h-stripe" | "v-stripe" | "pinstripe" | "grid" | "dots",
  "textContent": "text/name/number the customer wants printed on the garment, or null if none was mentioned",
  "textBold": true or false (true if customer said "bold", "block letters", or similar),
  "description_summary": "One sentence summary"
}
PROMPT;

        // BUG-008: same thinking-token headroom issue as recommendMaterials()
        // — was 400, too tight on gemini-3.6-flash. Not confirmed broken by a
        // logged failure yet, but it shares callGemini() and is even smaller,
        // so it's exposed to the identical failure mode. Raised preventively.
        $text = $this->gemini->call($prompt, 1000);
        if (!$text) return response()->json(['error' => 'AI unavailable'], 503);

        $clean  = preg_replace('/```json|```/i', '', $text);
        $config = json_decode(trim($clean), true);
        if (!$config) return response()->json(['error' => 'Parse error'], 422);

        return response()->json([
            'config'      => $config,
            'description' => $config['description_summary'] ?? $request->description,
        ]);
    }

    // Chat-mode branch of describeDesign(): free-form Q&A for the floating
    // widget, kept separate from the config-generating branch above because
    // the two need different prompts, different max tokens, and a plain-text
    // reply rather than a JSON config.
    private function describeDesignChat(Request $request)
    {
        $request->validate(['prompt' => 'required|string|max:500']);

        // chat_context already carries the system prompt + conversation
        // history, built client-side in AIDesignChat.jsx — pass it through
        // rather than reconstructing it here, so the two stay in sync by
        // construction instead of by convention.
        $prompt = $request->input('chat_context') ?: $request->input('prompt');

        $text = $this->gemini->call($prompt, 400);
        if (!$text) {
            return response()->json(['chat_response' => null, 'error' => 'AI unavailable'], 503);
        }

        return response()->json(['chat_response' => trim($text)]);
    }

    // =========================================================================
    // AI LAYER 3 — Analytics Assistant (Admin)
    // GET /api/admin/ai/analytics-summary
    // =========================================================================
    public function analyticsSummary()
    {
        $now       = now();
        $thisMonth = Order::whereMonth('created_at', $now->month)->whereYear('created_at', $now->year)->count();
        $lastMonth = Order::whereMonth('created_at', $now->copy()->subMonth()->month)->count();
        $revenue   = \App\Models\SalesTransaction::whereMonth('created_at', $now->month)->sum('amount_paid');
        $lowStock  = \App\Models\Material::whereRaw('quantity_in_stock <= reorder_threshold')->count();
        $pending   = Order::where('status', 'pending')->count();
        $accepted  = Order::where('ai_recommendation_status', 'accepted')->count();
        $trend     = $lastMonth > 0 ? round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1) : 0;

        $prompt = <<<PROMPT
You are VFRB Enterprise's business analytics assistant. Write a 3-sentence business summary for the manager.

Metrics:
- Orders this month: {$thisMonth} (vs {$lastMonth} last month, {$trend}% change)
- Revenue this month: PHP {$revenue}
- Materials below reorder threshold: {$lowStock}
- Orders pending confirmation: {$pending}
- Orders with accepted AI material recommendations: {$accepted}

Write professionally. Note what needs immediate attention and one actionable recommendation.
No bullet points.
PROMPT;

        // BUG-008: same reasoning as describeDesign() above — raised
        // preventively for the same thinking-token headroom issue.
        $insight = $this->gemini->call($prompt, 800);

        return response()->json([
            'insight'  => $insight ?? 'Analytics summary unavailable.',
            'metrics'  => compact('thisMonth','lastMonth','trend','revenue','lowStock','pending','accepted'),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================
    private function buildPrompt(Order $order, $catalog): string
    {
        $g      = $order->garment_type  ?? 'garment';
        $c      = $order->collar_type   ?? 'standard';
        $s      = $order->sleeve_type   ?? 'standard';
        $p      = $order->pocket_type   ?? 'none';
        $qty    = $order->quantity_ordered ?? null;
        $color  = $order->color         ?? 'any';
        $notes  = $order->client_design_notes ?? '';

        $catalogLines = $catalog->map(fn($m) =>
            "  {$m->material_id} | {$m->material_name} | category: {$m->category} | unit: {$m->unit}"
        )->implode("\n");

        return <<<PROMPT
You are a materials specialist for VFRB Enterprise, a garment manufacturer in Bayanan, Muntinlupa, Philippines.

Job order specs (design layout only — no formulas involved):
- Garment: {$g} | Collar: {$c} | Sleeve: {$s} | Pocket: {$p} | Color: {$color}
- Quantity ordered: {$qty} pieces
- Notes: {$notes}

Here is VFRB's real materials catalog. Choose ONLY from this list —
do not invent a material that isn't here:
{$catalogLines}

Your ONLY job is to decide which of these catalog materials are relevant to
this design, and explain why in one short plain-language sentence each.

Do NOT estimate or mention any quantity, yardage, weight, or formula —
that is calculated separately by VFRB's own system, not by you.

Return ONLY valid JSON (no markdown, no backticks):
{
  "selections": [
    { "material_id": 8, "reason": "Main body fabric for the polo shirt." },
    { "material_id": 3, "reason": "Matching thread for stitching." }
  ],
  "narration": "One short paragraph, customer-facing, summarizing the recommended materials by name — still no numbers."
}
PROMPT;
    }
}