<?php

use App\Models\CentralAdmin;

beforeEach(function () {
    $this->withoutHeader('X-Tenant-Slug');
});

it('lists platform operators', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    CentralAdmin::factory()->create(['name' => 'Second Operator']);

    $this->getJson('/api/central/admins')
        ->assertOk()
        ->assertJsonCount(2, 'admins');
});

it('creates a platform operator', function () {
    actingAsCentral(CentralAdmin::factory()->create());

    $this->postJson('/api/central/admins', [
        'name' => 'New Operator',
        'email' => 'new@platform.test',
        'password' => 'secret-pass-123',
    ])->assertCreated()->assertJsonPath('admin.email', 'new@platform.test');

    $admin = CentralAdmin::query()->where('email', 'new@platform.test')->first();
    expect($admin)->not->toBeNull()->and($admin->is_active)->toBeTrue();
});

it('rejects a short password for a new operator', function () {
    actingAsCentral(CentralAdmin::factory()->create());

    $this->postJson('/api/central/admins', [
        'name' => 'Weak',
        'email' => 'weak@platform.test',
        'password' => 'short',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('updates a platform operator', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $admin = CentralAdmin::factory()->create(['name' => 'Old Name']);

    $this->putJson("/api/central/admins/{$admin->id}", [
        'name' => 'New Name',
    ])->assertOk()->assertJsonPath('admin.name', 'New Name');
});

it('deactivates a platform operator while another remains active', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $target = CentralAdmin::factory()->create();
    CentralAdmin::factory()->create();

    $this->putJson("/api/central/admins/{$target->id}", [
        'is_active' => false,
    ])->assertOk();

    expect($target->fresh()->is_active)->toBeFalse();
});

it('keeps the platform reachable by never blocking the last active operator', function () {
    $self = CentralAdmin::factory()->create();
    actingAsCentral($self);
    $target = CentralAdmin::factory()->create();

    // Deactivating the only other operator is fine — the acting one remains.
    $this->putJson("/api/central/admins/{$target->id}", [
        'is_active' => false,
    ])->assertOk();

    expect(CentralAdmin::query()->where('is_active', true)->count())->toBe(1);

    // And the guard refuses to let that last active account go dark too.
    $this->putJson("/api/central/admins/{$self->id}", [
        'is_active' => false,
    ])->assertStatus(422);

    $this->deleteJson("/api/central/admins/{$self->id}")
        ->assertStatus(422);
});

it('refuses to deactivate or delete your own account', function () {
    $self = CentralAdmin::factory()->create();
    actingAsCentral($self);

    $this->putJson("/api/central/admins/{$self->id}", [
        'is_active' => false,
    ])->assertStatus(422);

    $this->deleteJson("/api/central/admins/{$self->id}")
        ->assertStatus(422);
});

it('deletes an operator who is not the last active one', function () {
    actingAsCentral(CentralAdmin::factory()->create());
    $target = CentralAdmin::factory()->create();

    $this->deleteJson("/api/central/admins/{$target->id}")
        ->assertOk();

    expect(CentralAdmin::query()->find($target->id))->toBeNull();
});
