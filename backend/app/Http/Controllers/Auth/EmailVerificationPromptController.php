<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationPromptController extends Controller
{
    /**
     * Report whether the authenticated user still needs to verify their email.
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'verified' => $request->user()->hasVerifiedEmail(),
        ]);
    }
}
