<?php
// app/Http/Controllers/Api/RecommendationGuidanceController.php
// "Trainable" AI material recommendations without fine-tuning:
//   - managers keep guidance rules that are injected into the prompt as grounding
//   - staff mark each recommended line accept/reject; rejections become "avoid" hints
// Neither path may carry a quantity, price or formula.
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MaterialRecommendation;
use App\Models\RecommendationGuidance;
use App\Services\MaterialCatalogMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RecommendationGuidanceController extends Controller
{
    public function __construct(private MaterialCatalogMatcher $matcher) {}

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
