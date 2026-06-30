<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * On a fresh DB (migrations only, no seeds — as with `composer setup` without seeds
     * or a Docker prod with RUN_SEEDS=false) the command must produce a WORKING admin:
     * with the superadmin role AND the full set of permissions, not an empty role. Otherwise
     * the admin gets a 403 in every section (B1).
     */
    public function test_creates_admin_with_full_permissions_on_fresh_database(): void
    {
        $this->artisan('app:create-admin', ['email' => 'boss@example.test', 'name' => 'Boss'])
            ->expectsQuestion('Введите пароль', 'Password1')
            ->expectsQuestion('Повторите пароль', 'Password1')
            ->assertSuccessful();

        $user = User::where('email', 'boss@example.test')->firstOrFail();

        $this->assertTrue($user->hasRole(RbacGuard::superadminRole()));
        $this->assertTrue($user->can('users.view'));
        $this->assertTrue($user->can('settings.edit'));

        // Real check: the admin opens the section instead of hitting a 403.
        $this->actingAs($user);
        $this->get(route('admin.users.index'))->assertOk();
    }

    public function test_is_idempotent_and_updates_existing_user(): void
    {
        $this->artisan('app:create-admin', ['email' => 'boss@example.test', 'name' => 'Boss'])
            ->expectsQuestion('Введите пароль', 'Password1')
            ->expectsQuestion('Повторите пароль', 'Password1')
            ->assertSuccessful();

        $this->artisan('app:create-admin', ['email' => 'boss@example.test', 'name' => 'Boss Renamed'])
            ->expectsQuestion('Введите пароль', 'Password2')
            ->expectsQuestion('Повторите пароль', 'Password2')
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'boss@example.test')->count());
        $user = User::where('email', 'boss@example.test')->firstOrFail();
        $this->assertSame('Boss Renamed', $user->name);
        $this->assertTrue($user->can('users.view'));
    }
}
