<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Password policy (Password::defaults() in AppServiceProvider):
 * minimum 15 characters, mixed case, numbers and symbols.
 */
class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function storeUserWithPassword(string $password): TestResponse
    {
        return $this->post(route('admin.users.store'), [
            'name' => 'Policy Probe',
            'email' => 'policy@example.test',
            'password' => $password,
            'roles' => [],
        ]);
    }

    public function test_rejects_password_shorter_than_15_characters(): void
    {
        $this->actingAsAdmin();

        $this->storeUserWithPassword('Sh0rt!Pass')->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['email' => 'policy@example.test']);
    }

    public function test_rejects_password_without_symbols(): void
    {
        $this->actingAsAdmin();

        $this->storeUserWithPassword('NoSymbolsHere12345')->assertSessionHasErrors('password');
    }

    public function test_rejects_password_without_numbers(): void
    {
        $this->actingAsAdmin();

        $this->storeUserWithPassword('NoNumbersHere!!!ab')->assertSessionHasErrors('password');
    }

    public function test_rejects_password_without_mixed_case(): void
    {
        $this->actingAsAdmin();

        $this->storeUserWithPassword('alllowercase123!!!')->assertSessionHasErrors('password');
    }

    public function test_accepts_compliant_password(): void
    {
        $this->actingAsAdmin();

        $this->storeUserWithPassword('Str0ng!Passw0rd#42')->assertSessionDoesntHaveErrors('password');
        $this->assertDatabaseHas('users', ['email' => 'policy@example.test']);
    }

    public function test_create_admin_command_rejects_weak_password(): void
    {
        $this->artisan('app:create-admin', ['email' => 'weak@example.test', 'name' => 'Weak'])
            ->expectsQuestion('Введите пароль', 'Password1')
            ->expectsQuestion('Повторите пароль', 'Password1')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'weak@example.test']);
    }
}
