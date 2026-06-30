<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seeds base roles and permissions (admin/operator + all permissions).
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * Creates and logs in a user with the admin role (all permissions).
     */
    protected function actingAsAdmin(): User
    {
        $this->seedRolesAndPermissions();

        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user);

        return $user;
    }

    /**
     * Creates and logs in a user with exactly the given direct permissions.
     *
     * @param  list<string>  $permissions
     */
    protected function actingAsUserWith(array $permissions = []): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        if ($permissions !== []) {
            $user->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($user);

        return $user;
    }
}
