<?php

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('stamps created_by on creating', function () {
    $user = User::factory()->create();
    Auth::login($user);

    // Branch uses HasUserstamps (existing); we assert the same semantics
    // hold so future Auditable trait swaps drop in cleanly.
    $branch = Branch::create(['name' => 'Audited Branch']);

    expect($branch->created_by)->toBe($user->id);
    expect($branch->updated_by)->toBe($user->id);
});

it('updates updated_by on subsequent edits without touching created_by', function () {
    $original = User::factory()->create();
    Auth::login($original);
    $branch = Branch::create(['name' => 'Branch A']);

    $editor = User::factory()->create();
    Auth::login($editor);

    $branch->update(['name' => 'Branch A (renamed)']);
    $branch->refresh();

    expect($branch->created_by)->toBe($original->id);
    expect($branch->updated_by)->toBe($editor->id);
});
