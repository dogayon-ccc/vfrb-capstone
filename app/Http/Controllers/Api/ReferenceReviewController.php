<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferenceValidator;
use App\Support\SetPairs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Manager-only: a held reference (needs_review) becomes orderable only when staff name the top and bottom.
class ReferenceReviewController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['needs_review', 'approved', 'rejected'], true) ? $request->query('status') : 'needs_review';
        $rows = DB::table('reference_validations as r')->leftJoin('users as u', 'u.user_id', '=', 'r.user_id')
            ->where('r.status', $status)->when($status === 'needs_review', fn ($q) => $q->whereNull('r.order_id'))
            ->orderBy('r.id')->limit(100)
            ->get(['r.id', 'r.user_id', 'u.name as customer_name', 'r.reason', 'r.top_garment', 'r.bottom_garment', 'r.status', 'r.path', 'r.created_at', 'r.reviewed_at']);

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'user_id' => $r->user_id, 'customer_name' => $r->customer_name, 'status' => $r->status,
            'reason' => $r->reason, 'top_garment' => $r->top_garment, 'bottom_garment' => $r->bottom_garment,
            'pair_approved' => SetPairs::approved($r->top_garment, $r->bottom_garment),
            'is_pdf' => strtolower(pathinfo($r->path, PATHINFO_EXTENSION)) === 'pdf',
            'created_at' => $r->created_at, 'reviewed_at' => $r->reviewed_at,
        ])->values());
    }

    public function decide(Request $request, int $id)
    {
        $data = $request->validate([
            'decision' => 'required|in:approve,reject',
            'top_garment' => 'required_if:decision,approve|nullable|in:'.implode(',', ReferenceValidator::TOPS),
            'bottom_garment' => 'required_if:decision,approve|nullable|in:'.implode(',', ReferenceValidator::BOTTOMS),
        ]);
        $row = DB::table('reference_validations')->where('id', $id)->first();
        if (! $row || $row->status !== 'needs_review' || $row->order_id) {
            return response()->json(['message' => 'Only an unused reference waiting for review can be decided.'], 422);
        }
        $approve = $data['decision'] === 'approve';
        if ($approve && ! SetPairs::approved($data['top_garment'], $data['bottom_garment'])) {
            return response()->json(['message' => 'That top and bottom are not an approved VFRB set. Add the pair to the approved sets first, or reject the reference.'], 422);
        }
        DB::table('reference_validations')->where('id', $id)->update([
            'status' => $approve ? 'approved' : 'rejected',
            'top_garment' => $approve ? $data['top_garment'] : $row->top_garment,
            'bottom_garment' => $approve ? $data['bottom_garment'] : $row->bottom_garment,
            'reviewed_by' => $request->user()->getKey(), 'reviewed_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id, 'status' => $approve ? 'approved' : 'rejected']);
    }
}
