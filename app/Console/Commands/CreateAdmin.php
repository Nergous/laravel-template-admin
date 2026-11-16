<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\Access\RbacGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates or updates an administrator.
 *
 * First fills in the base permission catalog (RolePermissionSeeder::ensure()).
 * Otherwise on a fresh database (after `migrate` without seeders, as in Docker in
 * production with RUN_SEEDS=false or on a manual install) the superadmin role would
 * have no permissions at all and the admin would get 403 everywhere. ensure() only
 * adds what is missing: operator and other role permissions configured in the
 * admin panel are left alone, so the command can be repeated on a live database.
 * Then assigns the superadmin role from config('rbac.superadmin_role').
 *
 * Missing arguments (email, name) and the password are asked for in the console.
 * Returns SUCCESS when the admin was created or updated and FAILURE when the
 * passwords did not match or the data failed validation.
 *
 * Usage:
 *   php artisan app:create-admin admin@example.com "Name"
 *   php artisan app:create-admin
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email? : Email администратора} {name? : Имя администратора}';

    protected $description = 'Создаёт или обновляет пользователя с ролью администратора';

    /**
     * Asks for the missing data, validates it and saves the administrator.
     *
     * @return int self::SUCCESS — success; self::FAILURE — the passwords did not match
     *             or the data failed validation.
     */
    public function handle(RbacGuard $rbac): int
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
                'password' => ['required', 'string', Password::defaults()],
                'name' => ['required', 'string', 'max:255'],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        // Base permissions (the catalog and the superadmin grant) are created BEFORE the role is assigned.
        // Nothing is deleted, so it is safe on every run.
        app(RolePermissionSeeder::class)->ensure();

        // An account with this email is restored from the trash so that no second
        // user with the same email is created.
        $user = User::where('email', $email)->first()
            ?? User::onlyTrashed()->where('email', $email)->latest('deleted_at')->first()
            ?? new User(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => $password,
            'is_active' => true,
        ]);

        if ($user->trashed()) {
            $user->restore();
        } else {
            $user->save();
        }

        $role = Role::firstOrCreate(['name' => $rbac->superadminRole(), 'guard_name' => 'web']);
        $user->syncRoles([$role]);

        $this->info('Администратор успешно создан / обновлён');
        $this->line('ID:    '.$user->id);
        $this->line('Email: '.$user->email);
        $this->line('Имя:   '.$user->name);
        $this->line('Роли:  '.$user->roles->pluck('name')->join(', '));

        return self::SUCCESS;
    }
}
