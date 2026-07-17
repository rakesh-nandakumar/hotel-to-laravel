<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BrowserSessionsController extends Controller
{
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        Auth::logoutOtherDevices($request->password);

        DB::table('sessions')
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return response()->json(['message' => 'Other browser sessions have been logged out.']);
    }

    public function destroySingle(Request $request, string $session): JsonResponse
    {
        DB::table('sessions')
            ->where('id', $session)
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return response()->json(['message' => 'Session revoked.']);
    }
}
