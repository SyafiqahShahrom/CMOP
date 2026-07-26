<?php

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Models\User;

test('a user with valid credentials can log in and is redirected to the dashboard', function () {
    $desk = Desk::factory()->create();
    $user = User::factory()->create([
        'email' => 'amara@cmop.test',
        'password' => bcrypt('password123'),
        'desk_id' => $desk->id,
    ]);
    $user->assignRole(CmopRole::Analyst->value);

    $response = $this->post('/login', [
        'email' => 'amara@cmop.test',
        'password' => 'password123',
    ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

test('a user with invalid credentials is not logged in', function () {
    User::factory()->create([
        'email' => 'amara@cmop.test',
        'password' => bcrypt('password123'),
    ]);

    $response = $this->from('/login')->post('/login', [
        'email' => 'amara@cmop.test',
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

test('an inactive user cannot log in even with correct credentials', function () {
    User::factory()->create([
        'email' => 'inactive@cmop.test',
        'password' => bcrypt('password123'),
        'is_active' => false,
    ]);

    $response = $this->from('/login')->post('/login', [
        'email' => 'inactive@cmop.test',
        'password' => 'password123',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});
