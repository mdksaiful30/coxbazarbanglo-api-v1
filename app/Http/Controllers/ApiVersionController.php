<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class ApiVersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'application_version' => config('release.application_version'),
            'api_version' => config('release.api_version'),
            'api_base_path' => '/api/v1',
        ]])->header('Cache-Control', 'no-store');
    }
}
