<?php

namespace Database\Seeders;

use App\Domains\Administration\Models\Desk;
use Illuminate\Database\Seeder;

class DeskSeeder extends Seeder
{
    public function run(): void
    {
        Desk::factory()->createMany([
            ['name' => 'FX Trade Support', 'entity' => 'CMOP Bank plc', 'region' => 'EMEA'],
            ['name' => 'Fixed Income Ops', 'entity' => 'CMOP Bank plc', 'region' => 'AMERICAS'],
        ]);
    }
}
