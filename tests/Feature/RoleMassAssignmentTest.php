<?php

namespace Tests\Feature;

use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleMassAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `is_system` marks undeletable/unrenamable system roles —
     * it MUST NOT be settable via mass assignment (otherwise a careless
     * `Role::create($request->all())` would bypass the system-role protection).
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
     * Trusted code (the seeder) sets the flag via explicit assignment — that works.
     */
    public function test_is_system_can_be_set_explicitly(): void
    {
        $role = Role::create(['name' => 'sys', 'guard_name' => 'web']);
        $role->is_system = true;
        $role->save();

        $this->assertTrue((bool) $role->fresh()->is_system);
    }
}
