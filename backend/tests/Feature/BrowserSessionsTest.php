<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

test('profile endpoint includes sessions data', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/settings/profile')
        ->assertOk()
        ->assertJsonStructure(['sessions']);
});

test('other browser sessions can be logged out', function () {
    $user = User::factory()->create();

    // Insert a fake "other" session for this user
    DB::table('sessions')->insert([
        'id' => 'other-session-id',
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->subHour()->timestamp,
    ]);

    $this->actingAs($user)
        ->deleteJson('/api/settings/browser-sessions', ['password' => 'password'])
        ->assertOk();

    expect(DB::table('sessions')->where('id', 'other-session-id')->exists())->toBeFalse();
});

test('correct password is required to logout other sessions', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/settings/browser-sessions', ['password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});
