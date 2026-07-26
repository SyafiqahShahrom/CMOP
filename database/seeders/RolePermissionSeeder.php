<?php

namespace Database\Seeders;

use App\Domains\Administration\Enums\CmopRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (CmopRole::cases() as $role) {
            Role::findOrCreate($role->value);
        }
    }
}
