<?php

use App\Models\User;

it('lets a user enable email codes and receive recovery codes once', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson(route('profile.two-factor.email.enable'));

    $response->assertOk()->assertJsonStructure(['freshRecoveryCodes']);

    $user->refresh();
    expect($user->two_factor_email_enabled)->toBeTrue()
        ->and($user->two_factor_recovery_codes)->not->toBeNull();

    $codes = json_decode(decrypt($user->two_factor_recovery_codes), true);
    expect($codes)->toHaveCount(8);
});

it('keeps existing recovery codes when enabling email codes again', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_recovery_codes' => encrypt(json_encode(['keep-this-code-11111'])),
    ])->save();

    $this->actingAs($user)->postJson(route('profile.two-factor.email.enable'));

    $codes = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
    expect($codes)->toBe(['keep-this-code-11111']);
});

it('lets a user disable email codes when not required', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_email_enabled' => true])->save();

    $this->actingAs($user)->deleteJson(route('profile.two-factor.email.disable'))
        ->assertOk();

    expect($user->fresh()->two_factor_email_enabled)->toBeFalse();
});

it('regenerates recovery codes and invalidates the old set', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_email_enabled' => true,
        'two_factor_recovery_codes' => encrypt(json_encode(['old-code-11111'])),
    ])->save();

    $this->actingAs($user)->postJson(route('profile.two-factor.recovery-codes'))
        ->assertOk()
        ->assertJsonStructure(['freshRecoveryCodes']);

    $codes = json_decode(decrypt($user->fresh()->two_factor_recovery_codes), true);
    expect($codes)->toHaveCount(8)
        ->and($codes)->not->toContain('old-code-11111');
});

it('refuses to generate recovery codes without any second factor', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('profile.two-factor.recovery-codes'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('two_factor');
});
