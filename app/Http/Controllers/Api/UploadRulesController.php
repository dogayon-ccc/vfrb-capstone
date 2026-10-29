<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;

class UploadRulesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(UploadRules::forClient())->header('Cache-Control', 'private, max-age=3600');
    }
}
