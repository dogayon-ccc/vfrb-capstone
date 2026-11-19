<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MaterialSpec;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What VFRB requires of the fabric for a kind of garment (top | bottom | skirt | dress). Manager-defined, owner read-only
 * (route group). Nothing is seeded: with no rows the recommender behaves exactly as before.
 *   GET /api/admin/material-spec-requirements[?garment_kind=top]
 *   PUT /api/admin/material-spec-requirements/{kind}   { requirements: [ {attribute, min_value?, max_value?, text_value?, is_required?} ] }
 * PUT replaces that kind's whole set atomically; an empty list clears it. An is_required row removes a fabric only on a
 * staff-verified mismatch (see MaterialSpecMatcher).
 */
class MaterialSpecRequirementController extends Controller
{
    public function index(Request $request)
    {
        $kind = $request->validate(['garment_kind' => 'sometimes|in:' . implode(',', MaterialSpec::GARMENT_KINDS)])['garment_kind'] ?? null;
        return response()->json(['data' => DB::table('material_spec_requirements')
            ->when($kind, fn ($q) => $q->where('garment_kind', $kind))
            ->orderBy('garment_kind')->orderBy('attribute')->get()]);
    }

    public function replace(Request $request, string $kind)
    {
        if (!in_array($kind, MaterialSpec::GARMENT_KINDS, true)) {
            return response()->json(['message' => 'Unknown garment kind.'], 404);
        }
        $data = $request->validate(['requirements' => 'present|array|max:20', 'requirements.*' => 'array']);

        $rows = []; $errors = []; $seen = [];
        foreach ($data['requirements'] as $i => $r) {
            $n = MaterialSpec::requirement($r);
            if ($n['error']) { $errors["requirements.{$i}"] = [$n['error']]; continue; }
            if (isset($seen[$n['row']['attribute']])) { $errors["requirements.{$i}"] = ["{$n['row']['attribute']} is listed twice."]; continue; }
            $seen[$n['row']['attribute']] = true;
            $rows[] = $n['row'];
        }
        if ($errors) { throw ValidationException::withMessages($errors); }

        DB::transaction(function () use ($kind, $rows) {
            DB::table('material_spec_requirements')->where('garment_kind', $kind)->delete();
            foreach ($rows as $row) {
                DB::table('material_spec_requirements')->insert($row + ['garment_kind' => $kind, 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        // Recommendation cache keys hash the eligible rows including each spec check, so a changed rule never serves a stale reply.

        return $this->index(new Request(['garment_kind' => $kind]));
    }
}
