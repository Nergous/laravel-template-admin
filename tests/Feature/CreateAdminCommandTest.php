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
     * На свежей БД (только миграции, без сидов — как при `composer setup` без сидов
     * или Docker-проде с RUN_SEEDS=false) команда обязана выдать РАБОЧЕГО админа:
     * с ролью суперадмина И полным набором прав, а не пустую роль. Иначе админ
     * получает 403 во всех разделах (B1).
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

        // Реальная проверка: админ заходит в раздел, а не ловит 403.
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
