<?php

namespace Tests\Unit\Administration;

use App\Domains\Administration\Models\Desk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeskModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_desk_has_name_entity_and_region_attributes(): void
    {
        $desk = Desk::factory()->create([
            'name' => 'FX Trade Support',
            'entity' => 'CMOP Bank plc',
            'region' => 'EMEA',
        ]);

        $this->assertSame('FX Trade Support', $desk->name);
        $this->assertSame('CMOP Bank plc', $desk->entity);
        $this->assertSame('EMEA', $desk->region);
    }
}
