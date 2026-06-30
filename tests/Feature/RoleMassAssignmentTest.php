<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `is_system` помечает неудаляемые/непереименовываемые системные роли —
     * его НЕЛЬЗЯ выставить массовым присваиванием (иначе неосторожный
     * `Role::create($request->all())` обошёл бы защиту системной роли).
     */
    public function test_is_system_cannot_be_mass_assigned(): void
    {
        $role = Role::create([
            'name' => 'sneaky',
            'guard_name' => 'web',
            'is_system' => true,
        ]);

        $this->assertFalse((bool) $role->fresh()->is_system);
    }

    /**
     * Доверенный код (сидер) выставляет флаг явным присваиванием — это работает.
     */
    public function test_is_system_can_be_set_explicitly(): void
    {
        $role = Role::create(['name' => 'sys', 'guard_name' => 'web']);
        $role->is_system = true;
        $role->save();

        $this->assertTrue((bool) $role->fresh()->is_system);
    }
}
