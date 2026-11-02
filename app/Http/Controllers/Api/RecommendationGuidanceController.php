<?php
// app/Http/Controllers/Api/RecommendationGuidanceController.php
// "Trainable" AI material recommendations without fine-tuning:
//   - managers keep guidance rules that are injected into the prompt as grounding
//   - staff mark each recommended line accept/reject; rejections become "avoid" hints
// Neither path may carry a quantity, price or formula.
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRecommendation;
use App\Models\Order;
use App\Models\RecommendationGuidance;
use App\Services\MaterialCatalogMatcher;
use App\Services\MaterialRecommender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecommendationGuidanceController extends Controller
{
    public function __construct(private MaterialCatalogMatcher $matcher) {}

    // GET /api/admin/ai/recommendation-metrics?months=6 (manager; owner read-only)
    // How often staff and customers accept AI-recommended lines, overall, per garment and per month. Customer self-picks excluded.
    public function metrics(Request $request)
    {
        $months = (int) ($request->validate(['months' => 'nullable|integer|min:1|max:24'])['months'] ?? 6);
        $since  = now()->subMonths($months - 1)->startOfMonth();
        $rows   = DB::table('material_recommendations as r')
            ->join('orders as o', 'o.order_id', '=', 'r.order_id')
            ->where('r.created_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('r.ai_note')->orWhere('r.ai_note', '!=', MaterialRecommendation::CUSTOMER_PICK_NOTE))
            ->get(['r.staff_feedback', 'r.status', 'r.created_at', 'o.garment_type']);

        $norm = fn ($s) => trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $s)));
        $rate = fn (int $yes, int $all) => $all ? round($yes / $all, 3) : null;
        $stat = function ($set) use ($rate) {
            $accept = $set->where('staff_feedback', 'accept')->count();
            $reject = $set->where('staff_feedback', 'reject')->count();
            // The customer's answer is the line status: customer_accepted is NOT NULL, so 0 cannot tell "rejected" from "not yet".
            $decided = $set->whereIn('status', ['accepted', 'rejected']);
            $custYes = $decided->where('status', 'accepted')->count();
            return [
                'lines'                    => $set->count(),
                'staff_accepted'           => $accept,
                'staff_rejected'           => $reject,
                'staff_acceptance_rate'    => $rate($accept, $accept + $reject),
                'customer_accepted'        => $custYes,
                'customer_rejected'        => $decided->count() - $custYes,
                'customer_acceptance_rate' => $rate($custYes, $decided->count()),
            ];
        };

        return response()->json([
            'window_months' => $months,
            'since'         => $since->toDateString(),
            'overall'       => $stat($rows),
            'by_garment'    => $rows->groupBy(fn ($r) => $norm($r->garment_type) ?: 'unspecified')
                ->map(fn ($set, $g) => ['garment' => ucwords($g)] + $stat($set))->sortByDesc('lines')->values(),
            'by_month'      => $rows->groupBy(fn ($r) => substr((string) $r->created_at, 0, 7))->sortKeys()
                ->map(fn ($set, $m) => ['month' => $m] + $stat($set))->values(),
            'note'          => 'Covers current recommendation lines; regenerating a list replaces its earlier lines.',
        ]);
    }

    // GET /api/admin/orders/{id}/ai-context (manager; owner read-only)
    // What the AI would see for this order if asked now: guidance, staff verdicts and reasons, preferences, eligible catalog.
    public function aiContext(int $id, MaterialRecommender $recommender)
    {
        $order = Order::findOrFail($id);
        $prep  = $recommender->prepare($order);
        if (is_string($prep)) {
            return response()->json(['message' => $prep], 422);
        }
        $ctx  = $recommender->context($order, $prep['eligible'], $prep['profile']);
        $keep = array_flip(['material_id', 'material_code', 'material_name', 'category', 'unit', 'in_stock', 'colour_match']);

        return response()->json(['data' => [
            'as_of'                => now()->toIso8601String(),
            'scope'                => 'What the AI would see if asked now. Past requests are not recorded.',
            'garment'              => $ctx['garment'],
            'kind'                 => $ctx['kind'],
            'category'             => $ctx['category'],
            'needs'                => $ctx['needs'],
            'guidance'             => $ctx['guidance'],
            'staff_approved'       => $ctx['approved'],
            'staff_rejected'       => $ctx['avoid'],
            'staff_reasons'        => $ctx['feedback_notes'],
            'customer_preferences' => $ctx['preferences'],
            'catalog'              => array_map(fn ($m) => array_intersect_key($m, $keep), $ctx['catalog']),
        ]]);
    }

    // GET /api/admin/recommendation-guidance (manager)
    public function index()
    {
        return response()->json([
            'data' => RecommendationGuidance::orderByDesc('is_active')->orderBy('scope_category')->orderBy('scope_garment')->get(),
        ]);
    }

    // POST /api/admin/recommendation-guidance (manager)
    public function store(Request $request)
    {
        $data = $this->validated($request);
        if ($data instanceof JsonResponse) { return $data; }
        return response()->json(['data' => RecommendationGuidance::create($data + ['updated_by' => Auth::id()])], 201);
    }

    // PUT /api/admin/recommendation-guidance/{id} (manager). is_active=false retires a rule; nothing is hard-deleted.
    public function update(Request $request, int $id)
    {
        $rule = RecommendationGuidance::findOrFail($id);
        $data = $this->validated($request);
        if ($data instanceof JsonResponse) { return $data; }
        $rule->update($data + ['updated_by' => Auth::id()]);
        return response()->json(['data' => $rule]);
    }

    // PATCH /api/admin/material-recommendations/{recId}/feedback (staff + manager)
    public function feedback(Request $request, int $recId)
    {
        $data = $request->validate([
            'verdict' => 'required|in:accept,reject,clear',
            'note'    => 'nullable|string|max:280',
        ]);
        $note = trim(strip_tags((string) ($data['note'] ?? '')));
        if ($note !== '' && $this->matcher->hasForbiddenTerms($note)) {
            return response()->json(['message' => 'Feedback notes describe suitability only, never quantities or prices.'], 422);
        }

        $rec   = MaterialRecommendation::findOrFail($recId);
        $clear = $data['verdict'] === 'clear';
        $rec->update([
            'staff_feedback'      => $clear ? null : $data['verdict'],
            'staff_feedback_note' => $clear || $note === '' ? null : $note,
            'staff_feedback_by'   => $clear ? null : Auth::id(),
            'staff_feedback_at'   => $clear ? null : now(),
        ]);
        return response()->json(['data' => $rec->only(['rec_id', 'staff_feedback', 'staff_feedback_note', 'staff_feedback_at'])]);
    }

    private function validated(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'scope_category' => 'nullable|string|max:60',
            'scope_garment'  => 'nullable|string|max:80',
            'rule'           => 'required|string|min:5|max:500',
            'is_active'      => 'sometimes|boolean',
        ]);
        $data['rule'] = trim(strip_tags($data['rule']));
        foreach (['scope_category', 'scope_garment'] as $k) {
            $data[$k] = isset($data[$k]) && trim($data[$k]) !== '' ? trim($data[$k]) : null;
        }
        if ($this->matcher->hasForbiddenTerms($data['rule'])) {
            return response()->json(['message' => 'Guidance says which materials suit a use. It cannot contain quantities, prices or formulas.'], 422);
        }
        return $data;
    }
}
