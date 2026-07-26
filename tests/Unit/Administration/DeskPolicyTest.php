<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Domains\Administration\Policies\DeskPolicy;
use App\Models\User;

test('an admin can update any desk', function () {
    $admin = User::factory()->create();
    $admin->assignRole(CmopRole::Admin->value);
    $desk = Desk::factory()->create();

    expect((new DeskPolicy)->update($admin, $desk))->toBeTrue();
});

test('an analyst cannot update desk configuration', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(CmopRole::Analyst->value);
    $desk = Desk::factory()->create();

    expect((new DeskPolicy)->update($analyst, $desk))->toBeFalse();
});

test('any authenticated user can view the desk list', function () {
    $analyst = User::factory()->create();
    $analyst->assignRole(CmopRole::Analyst->value);

    expect((new DeskPolicy)->viewAny($analyst))->toBeTrue();
});
