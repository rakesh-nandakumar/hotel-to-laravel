<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ProfileController extends Controller
{
    /**
     * The user's profile settings, including their active browser sessions.
     */
    public function edit(Request $request): JsonResponse
    {
        return response()->json([
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'sessions' => $this->getSessions($request),
        ]);
    }

    /**
     * @return array<int, array{id: string, agent: array{platform: string, browser: string, is_mobile: bool}, ip_address: string|null, location: string|null, is_current_device: bool, last_active: string}>
     */
    private function getSessions(Request $request): array
    {
        return DB::table('sessions')
            ->where('user_id', $request->user()->getAuthIdentifier())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function (object $session) use ($request): array {
                return [
                    'id' => $session->id,
                    'agent' => $this->parseAgent($session->user_agent ?? ''),
                    'ip_address' => $session->ip_address,
                    'location' => $this->resolveLocation($session->ip_address),
                    'is_current_device' => $session->id === $request->session()->getId(),
                    'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                ];
            })
            ->all();
    }

    private function resolveLocation(?string $ip): ?string
    {
        if (! $ip || in_array($ip, ['127.0.0.1', '::1'], true) || str_starts_with($ip, '192.168.') || str_starts_with($ip, '10.')) {
            return 'Local';
        }

        return Cache::remember("geoip:{$ip}", now()->addDay(), function () use ($ip): ?string {
            try {
                $response = Http::timeout(3)->get("http://ip-api.com/json/{$ip}?fields=city,regionName,country,status");

                if ($response->ok() && $response->json('status') === 'success') {
                    return implode(', ', array_filter([
                        $response->json('city'),
                        $response->json('regionName'),
                        $response->json('country'),
                    ]));
                }
            } catch (\Throwable) {
                // silently fail — location is cosmetic
            }

            return null;
        });
    }

    /**
     * @return array{platform: string, browser: string, is_mobile: bool}
     */
    private function parseAgent(string $userAgent): array
    {
        $platform = match (true) {
            (bool) preg_match('/Windows NT/i', $userAgent) => 'Windows',
            (bool) preg_match('/Mac OS X/i', $userAgent) => 'OS X',
            (bool) preg_match('/Android/i', $userAgent) => 'Android',
            (bool) preg_match('/iPhone|iPad/i', $userAgent) => 'iOS',
            (bool) preg_match('/Linux/i', $userAgent) => 'Linux',
            default => 'Unknown',
        };

        $browser = match (true) {
            (bool) preg_match('/Edg\//i', $userAgent) => 'Edge',
            (bool) preg_match('/Chrome/i', $userAgent) => 'Chrome',
            (bool) preg_match('/Firefox/i', $userAgent) => 'Firefox',
            (bool) preg_match('/Safari/i', $userAgent) => 'Safari',
            (bool) preg_match('/Opera|OPR/i', $userAgent) => 'Opera',
            default => 'Unknown Browser',
        };

        return [
            'platform' => $platform,
            'browser' => $browser,
            'is_mobile' => (bool) preg_match('/Mobile|Android|iPhone|iPad/i', $userAgent),
        ];
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): JsonResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return response()->json(['message' => 'Profile updated.']);
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Account deleted.']);
    }
}
