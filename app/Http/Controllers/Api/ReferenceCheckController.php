<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferenceValidator;
use App\Support\UploadRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReferenceCheckController extends Controller
{
    public function __invoke(Request $request, ReferenceValidator $validator)
    {
        $request->validate(['reference' => UploadRules::referenceRequired()]);
        $bytes = file_get_contents($request->file('reference')->getRealPath());
        $result = $validator->validate($bytes);

        Log::info('reference_check', ['user' => $request->user()?->getKey(), 'sha256' => hash('sha256', $bytes),
            'verdict' => $result['verdict'], 'reason' => $result['reason'], 'model' => $result['model']]);

        return response()->json($result);
    }
}
