<?php

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(MenuSeeder::class);
    $this->seed(PermissionsAndRolesSeeder::class);

    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    Mail::fake();
});

it('records successful and failed login attempts', function () {
    $user = User::factory()->create();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password']);
    expect(AuditLog::where('action', 'user.login_failed')->where('subject_id', $user->id)->exists())->toBeTrue();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
    expect(AuditLog::where('action', 'user.login')->where('subject_id', $user->id)->exists())->toBeTrue();

    $this->postJson('/api/logout');
    expect(AuditLog::where('action', 'user.logout')->exists())->toBeTrue();
});

it('records failed otp attempts during the email challenge', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_email_enabled' => true])->save();

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password']);
    $this->postJson(route('otp.login.store'), ['code' => '000000']);

    expect(AuditLog::where('action', 'user.login_otp_failed')->where('subject_id', $user->id)->exists())->toBeTrue();
});

it('records user creation and permission changes', function () {
    $admin = fullAdmin();

    $this->actingAs($admin)->postJson(route('user-management.users.store'), [
        'name' => 'Audited User',
        'email' => 'audited@example.com',
        'password' => 'Audit-Trail-Pass-2024',
        'password_confirmation' => 'Audit-Trail-Pass-2024',
        'status' => User::STATUS_ACTIVE,
        'role_ids' => [],
        'permissions' => ['audit_logs.access'],
        'warehouse_ids' => [],
    ]);

    $log = AuditLog::where('action', 'user.created')->where('actor_id', $admin->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->context['allow_overrides'])->toContain('audit_logs.access');
});

it('records role permission changes with the added and removed sets', function () {
    $admin = fullAdmin();
    $role = Role::create(['name' => 'Audit Role', 'is_full_admin' => false, 'is_active' => true, 'is_system' => false]);
    $role->permissions()->sync(Permission::whereIn('name', ['audit_logs.access'])->pluck('id'));

    $this->actingAs($admin)->putJson(route('user-management.roles.update', $role), [
        'name' => 'Audit Role',
        'description' => null,
        'is_active' => true,
        'permissions' => ['audit_logs.access', 'audit_logs.view'],
    ]);

    $log = AuditLog::where('action', 'role.updated')->where('subject_id', $role->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->context['added'])->toContain('audit_logs.view')
        ->and($log->context['removed'])->toBe([]);
});

it('renders human descriptions for the new security actions', function () {
    $user = User::factory()->create();

    $log = AuditLog::create([
        'action' => 'user.recovery_code_used',
        'subject_type' => User::class,
        'subject_id' => $user->id,
        'context' => [],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(App\Services\AuditLog::describe($log))
        ->toContain('recovery code was used');
});
