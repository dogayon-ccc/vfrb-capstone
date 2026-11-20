<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferenceValidator;
use App\Support\UploadDisk;
use App\Support\SetOrder;
use App\Support\SetPairs;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferenceCheckController extends Controller
{
    public function __invoke(Request $request, ReferenceValidator $validator)
    {
        $request->validate(['reference' => UploadRules::referenceRequired()]);
        $file = $request->file('reference');
        $bytes = file_get_contents($file->getRealPath());
        $result = $validator->validate($bytes);
        // A clear pair still needs staff when VFRB has not approved that top+bottom combination as a set.
        if ($result['verdict'] === 'accepted' && ! SetPairs::approved($result['top'], $result['bottom'])) {
            $result['verdict'] = 'needs_review';
            $result['reason'] = 'pair_not_approved';
        }
        $hash = hash('sha256', $bytes);

        // The verdict is stored against this user and these exact bytes; the order later points at this row, never at client fields.
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$validator->sniffMime($bytes)] ?? 'bin';
        $path = null;
        if ($result['reason'] !== 'unsupported_file') {
            $path = $file->storeAs('design-refs', $hash.'-'.$request->user()->getKey().'.'.$ext, UploadDisk::name());
        }
        $id = $path ? DB::table('reference_validations')->insertGetId([
            'user_id' => $request->user()->getKey(), 'path' => $path, 'sha256' => $hash,
            'status' => $result['verdict'], 'reason' => $result['reason'],
            'top_garment' => $result['top'], 'bottom_garment' => $result['bottom'], 'colors' => json_encode($result['colors']),
            'model' => $result['model'], 'prompt_version' => $result['prompt_version'], 'created_at' => now(), 'updated_at' => now(),
        ]) : null;

        Log::info('reference_check', ['user' => $request->user()->getKey(), 'sha256' => $hash,
            'verdict' => $result['verdict'], 'reason' => $result['reason'], 'model' => $result['model']]);

        return response()->json(['validation_id' => $id] + $result);
    }

    // The customer's own unused uploads, so a held reference can be picked up again after staff decide. No file path is returned.
    public function mine(Request $request)
    {
        $rows = DB::table('reference_validations')->where('user_id', $request->user()->getKey())->whereNull('order_id')
            ->orderByDesc('id')->limit(20)->get(['id', 'status', 'reason', 'top_garment', 'bottom_garment', 'created_at']);

        return response()->json($rows->map(fn ($r) => (array) $r + [
            'orderable' => in_array($r->status, SetOrder::ORDERABLE, true) && SetPairs::approved($r->top_garment, $r->bottom_garment),
        ])->values());
    }
}
