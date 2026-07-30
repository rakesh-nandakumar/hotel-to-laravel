<?php

namespace App\Http\Controllers\Central;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $admin = $request->user('central');

        return response()->json([
            'admin' => $admin?->only(['id', 'name', 'email']),
        ]);
    }
}
