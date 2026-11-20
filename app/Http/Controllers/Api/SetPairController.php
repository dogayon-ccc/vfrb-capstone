<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferenceValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Manager-only: the source of truth for which top and bottom VFRB accepts as one complete set.
class SetPairController extends Controller
{
    public function index()
    {
        return response()->json([
            'tops' => ReferenceValidator::TOPS,
            'bottoms' => ReferenceValidator::BOTTOMS,
            'pairs' => DB::table('approved_set_pairs')->orderBy('top_garment')->orderBy('bottom_garment')->get(['id', 'top_garment', 'bottom_garment', 'approved_by', 'created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'top_garment' => 'required|in:'.implode(',', ReferenceValidator::TOPS),
            'bottom_garment' => 'required|in:'.implode(',', ReferenceValidator::BOTTOMS),
        ]);
        DB::table('approved_set_pairs')->insertOrIgnore($data + ['approved_by' => $request->user()->getKey(), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['pair' => $data], 201);
    }

    public function destroy(int $id)
    {
        DB::table('approved_set_pairs')->where('id', $id)->delete();

        return response()->noContent();
    }
}
