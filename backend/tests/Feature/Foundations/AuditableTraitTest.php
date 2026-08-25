<?php

use App\Models\Till;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('stamps created_by on creating', function () {
    $user = User::factory()->create();
    Auth::login($user);

    // Till uses HasUserstamps (existing); we assert the same semantics
    // hold so future Auditable trait swaps drop in cleanly.
    $till = Till::create(['name' => 'Audited Till']);

    expect($till->created_by)->toBe($user->id);
    expect($till->updated_by)->toBe($user->id);
});

it('updates updated_by on subsequent edits without touching created_by', function () {
    $original = User::factory()->create();
    Auth::login($original);
    $till = Till::create(['name' => 'Till A']);

    $editor = User::factory()->create();
    Auth::login($editor);

    $till->update(['name' => 'Till A (renamed)']);
    $till->refresh();

    expect($till->created_by)->toBe($original->id);
    expect($till->updated_by)->toBe($editor->id);
});
