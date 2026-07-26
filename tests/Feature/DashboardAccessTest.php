<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Models\User;

test('a guest is redirected to login when visiting the dashboard', function () {
    $response = $this->get('/dashboard');

    $response->assertRedirect('/login');
});

test('an authenticated user sees the dashboard with their roles shared to Inertia', function () {
    $user = User::factory()->create();
    $user->assignRole(CmopRole::Analyst->value);

    $response = $this->actingAs($user)->get('/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('auth.user.email', $user->email)
        ->where('auth.user.roles', [CmopRole::Analyst->value])
    );
});
