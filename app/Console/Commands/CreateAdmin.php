<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Support\RbacGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Creates or updates an administrator.
 *
 * First seeds the base permission catalog (RolePermissionSeeder, idempotent) —
 * otherwise on a fresh DB (after `migrate` without seeds, as in Docker prod with
 * RUN_SEEDS=false or a manual install) the superadmin role would be created
 * without a single permission, and the admin would get 403 in every section. Then
 * assigns the superadmin role from config('rbac.superadmin_role').
 *
 * Missing arguments (email, name) and the password are requested interactively.
 * Returns SUCCESS on a successful create / update and FAILURE on a password
 * mismatch or a validation error.
 *
 * Usage:
 *   php artisan app:create-admin admin@example.com "Name"
 *   php artisan app:create-admin
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email? : Email администратора} {name? : Имя администратора}';

    protected $description = 'Создать или обновить пользователя с ролью администратора';

    /**
     * Requests the missing data, validates it and saves the administrator.
     *
     * @return int self::SUCCESS — success; self::FAILURE — passwords did not match
     *             or validation failed.
     */
    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->ask('Введите email администратора');
        $name = $this->argument('name') ?? $this->ask('Введите имя администратора');

        $password = $this->secret('Введите пароль');
        $passwordConfirm = $this->secret('Повторите пароль');

        if ($password !== $passwordConfirm) {
            $this->error('Пароли не совпадают');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'name' => $name],
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:8'],
                'name' => ['required', 'string', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Ensure the base RBAC (permission catalog + granting to the superadmin)
        // exists BEFORE assigning the role. Idempotent, so safe on every run.
        $this->callSilent('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
            ]
        );

        $role = Role::firstOrCreate(['name' => RbacGuard::superadminRole(), 'guard_name' => 'web']);
        $user->syncRoles([$role]);

        $this->info('Администратор успешно создан / обновлён');
        $this->line('ID:    '.$user->id);
        $this->line('Email: '.$user->email);
        $this->line('Name:  '.$user->name);
        $this->line('Roles: '.$user->roles->pluck('name')->join(', '));

        return self::SUCCESS;
    }
}
