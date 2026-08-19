<?php
// app/Http/Controllers/Api/AIController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\MaterialRecommendation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIController extends Controller
{
    // ── Gemini helper ─────────────────────────────────────────────────────────
    // DSA: round-robin O(1) — tries key_1, key_2, key_3 on 429
    private function callGemini(string $prompt, int $maxTokens = 1500): ?string
    {
        $keyCount = (int) config('services.gemini.key_count', 1);
        $model    = config('services.gemini.model', 'gemini-1.5-flash');

        for ($i = 0; $i < $keyCount; $i++) {
            $keyNum = $i + 1;
            $key    = config("services.gemini.key_{$keyNum}");
            if (!$key) continue;

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";

            try {
                $response = Http::timeout(30)->post($url, [
                    'contents'         => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'temperature'     => 0.3,
                        'maxOutputTokens' => $maxTokens,
                        // BUG-008 FIX (confirmed against Google's current docs,
                        // Aug 2026): gemini-3.x models (current GEMINI_MODEL =
                        // gemini-3.6-flash) think by default and CANNOT be
                        // fully disabled — "Gemini 3 Flash and Flash-Lite also
                        // do not support full thinking-off." Without this,
                        // thinking tokens were silently consuming the entire
                        // maxOutputTokens budget before any real answer text
                        // came out — matches the logged raw fragments exactly
                        // (e.g. raw="{\n  \"selections\":" — cut off after a
                        // handful of tokens). 'minimal' is the lowest level
                        // 3.x supports; this task is a simple catalog-selection
                        // classification with no need for deep reasoning.
                        // Do NOT add the legacy 'thinkingBudget' alongside
                        // this — 3.x models reject requests sending both.
                        'thinkingConfig'  => ['thinkingLevel' => 'minimal'],
                    ],
                    'safetySettings'   => [
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                    ],
                ]);

                // FIX: this used to only retry the next key on 429
                // (rate-limited). Any other failure — a bad/invalid key
                // (401/403), which is a real, distinct possibility here
                // since GEMINI_KEY_2/GEMINI_KEY_3 have a different string
                // format than GEMINI_KEY_1 and were never individually
                // verified — gave up immediately without ever trying the
                // remaining 2 keys. 401/403 are key-specific failures, just
                // like 429; a genuine 4xx request-shape error (400) or a
                // 5xx on Google's end would fail identically on every key,
                // so those still stop the loop rather than retry pointlessly.
                if (in_array($response->status(), [429, 401, 403])) {
                    Log::warning("Gemini key #{$keyNum} failed ({$response->status()}), trying next", [
                        'status' => $response->status(),
                    ]);
                    continue;
                }

                if (!$response->successful()) {
                    Log::error('Gemini error', ['status' => $response->status(), 'key' => $keyNum]);
                    return null;
                }

                // BUG-008 FIX: candidates[0].content.parts can hold MORE THAN
                // ONE part on thinking-enabled 3.x models — Google's own docs:
                // "the main result in a native Gemini response lives under
                // candidates[].content.parts" (plural). The old code always
                // read parts.0.text, which on a thinking model can be an
                // internal reasoning fragment rather than the final answer.
                // Concatenate every non-thought text part instead.
                $parts = $response->json('candidates.0.content.parts', []);
                $text  = collect($parts)
                    ->reject(fn($p) => $p['thought'] ?? false)
                    ->pluck('text')
                    ->filter()
                    ->implode('');

                // Diagnostic only (doesn't change control flow): confirms
                // whether a given failure was really the token budget running
                // out, next time this happens — usageMetadata.thoughtsTokenCount
                // shows exactly how much of maxOutputTokens thinking consumed.
                if ($response->json('candidates.0.finishReason') === 'MAX_TOKENS') {
                    Log::warning('Gemini hit MAX_TOKENS before finishing', [
                        'model'             => $model,
                        'key'               => $keyNum,
                        'requested_max'     => $maxTokens,
                        'thoughts_tokens'   => $response->json('usageMetadata.thoughtsTokenCount'),
                        'candidates_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
                        'text_length'       => strlen($text),
                    ]);
                }

                return $text !== '' ? $text : null;

            } catch (\Exception $e) {
                Log::error('Gemini exception', ['msg' => $e->getMessage(), 'key' => $keyNum]);
                continue; // try next key rather than give up on one transient failure
            }
        }

        Log::error('Gemini: all keys exhausted');
        return null;
    }

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
        $aiText = $this->callGemini($prompt, 3000);

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
        $qty         = (int) ($order->quantity_ordered ?? 0);

        MaterialRecommendation::where('order_id', $order->order_id)->delete();
        $i = 0;
        foreach ($parsed['selections'] as $sel) {
            $matId = (int) ($sel['material_id'] ?? 0);
            $mat   = $catalogById->get($matId);
            if (!$mat) continue; // hallucinated ID — skip rather than guess

            $computed = $this->computeDeterministicQty($matId, $order->garment_type, $qty, $mat->unit);

            MaterialRecommendation::create([
                'order_id'              => $order->order_id,
                'material_name'         => $mat->material_name,
                'material_id'           => $mat->material_id, // FIX: previously always null
                'category'              => $mat->category ?? 'Other',
                'estimated_range'       => $computed['estimated_range'],
                'total_estimated_range' => null, // avoid duplicate display — estimated_range already carries the full computed string
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

        return response()->json([
            'recommendation' => $parsed['narration'] ?? '',
            'materials'      => MaterialRecommendation::where('order_id', $order->order_id)
                                    ->orderBy('display_order')->get(),
        ]);
    }

    // ── Deterministic BOM engine — rule-based, NOT the LLM ──────────────────
    // Looks up the staff-configured rate for (material_id, garment_type) and
    // multiplies by quantity_ordered. If no rate has been set yet, this does
    // NOT invent a number — it says so plainly, per the project's "never
    // guess a business figure" rule. See material_usage_rates migration.
    private function computeDeterministicQty(int $materialId, ?string $garmentType, int $qtyOrdered, string $unit): array
    {
        $rate = \App\Models\MaterialUsageRate::where('material_id', $materialId)
            ->where('garment_type', $garmentType)
            ->first();

        if (!$rate) {
            return ['estimated_range' => 'Not yet configured — staff must set a usage rate for this material.'];
        }

        // IMPORTANT: both issueMaterialsToProduction() and materialCheck()
        // pull the number back out with preg_replace('/[^0-9.]/', '', ...) —
        // it strips every non-digit character and concatenates what's left,
        // so this string must contain exactly ONE number or the deduction/
        // feasibility logic silently reads garbage (e.g. "350.0 yards total
        // (3.5 per pc)" → "350.03.5100", an early version of this bug I
        // caught before shipping it). Keep this to a single clean figure —
        // matches the schema comment's own example format ("3.5 yards").
        $total = round($rate->qty_per_unit * $qtyOrdered, 2);
        return ['estimated_range' => "{$total} {$rate->unit}"];
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
        $qty     = (int) ($order->quantity_ordered ?? 0);

        MaterialRecommendation::where('order_id', $orderId)->delete();
        foreach ($request->material_ids as $i => $matId) {
            $mat = $catalog->get($matId);
            if (!$mat) continue;

            $computed = $this->computeDeterministicQty($matId, $order->garment_type, $qty, $mat->unit);

            MaterialRecommendation::create([
                'order_id'              => $orderId,
                'material_name'         => $mat->material_name,
                'material_id'           => $mat->material_id,
                'category'              => $mat->category ?? 'Other',
                'estimated_range'       => $computed['estimated_range'],
                'total_estimated_range' => null,
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
            Notification::create([
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

        MaterialRecommendation::where('order_id', $orderId)
            ->where('status', 'pending')
            ->update([
                'status'            => 'accepted',
                'customer_accepted' => 1,
                'accepted_at'       => now(),
                'customer_note'     => $request->notes,
                'updated_at'        => now(),
            ]);

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
            Notification::create([
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
            'message'           => 'Materials accepted. VFRB staff have been notified.',
            'materials_accepted'=> true,
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

        MaterialRecommendation::where('order_id', $orderId)
            ->where('status', 'pending')
            ->update([
                'status'            => 'rejected',
                'customer_accepted' => 0,
                'customer_note'     => $request->notes,
                'updated_at'        => now(),
            ]);

        $order->update([
            'ai_recommendation_status' => 'rejected',
        ]);

        $staffUsers = User::role(['staff', 'manager'])->get();
        foreach ($staffUsers as $staff) {
            Notification::create([
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
    // NEW — synthesizes the material_recommendations rows for this order into
    // the single-object shape OrderDetail.jsx renders:
    //   { rec_id, status, customer_accepted, materials_json: {name: {estimated_range}},
    //     total_estimated_range, notes }
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
            $materialsJson[$row->material_name] = [
                'estimated_range' => $row->estimated_range,
                'category'        => $row->category,
                'unit'            => $row->unit,
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
                'total_estimated_range' => $rows->first()->total_estimated_range,
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
  "description_summary": "One sentence summary"
}
PROMPT;

        // BUG-008: same thinking-token headroom issue as recommendMaterials()
        // — was 400, too tight on gemini-3.6-flash. Not confirmed broken by a
        // logged failure yet, but it shares callGemini() and is even smaller,
        // so it's exposed to the identical failure mode. Raised preventively.
        $text = $this->callGemini($prompt, 1000);
        if (!$text) return response()->json(['error' => 'AI unavailable'], 503);

        $clean  = preg_replace('/```json|```/i', '', $text);
        $config = json_decode(trim($clean), true);
        if (!$config) return response()->json(['error' => 'Parse error'], 422);

        return response()->json([
            'config'      => $config,
            'description' => $config['description_summary'] ?? $request->description,
        ]);
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
        $insight = $this->callGemini($prompt, 800);

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
        $color  = $order->color         ?? 'any';
        $notes  = $order->client_design_notes ?? '';

        $catalogLines = $catalog->map(fn($m) =>
            "  {$m->material_id} | {$m->material_name} | category: {$m->category} | unit: {$m->unit}"
        )->implode("\n");

        return <<<PROMPT
You are a materials specialist for VFRB Enterprise, a garment manufacturer in Bayanan, Muntinlupa, Philippines.

Job order specs (design layout only — no formulas involved):
- Garment: {$g} | Collar: {$c} | Sleeve: {$s} | Color: {$color}
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