<?php

namespace Database\Factories;

use App\Domains\Administration\Models\Desk;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeskFactory extends Factory
{
    protected $model = Desk::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company() . ' Desk',
            'entity' => 'CMOP Bank plc',
            'region' => $this->faker->randomElement(['EMEA', 'APAC', 'AMERICAS']),
        ];
    }
}
