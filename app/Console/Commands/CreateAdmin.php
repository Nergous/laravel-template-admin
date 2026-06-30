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
 * Создаёт или обновляет администратора.
 *
 * Сначала засевает базовый каталог прав (RolePermissionSeeder, идемпотентно) —
 * иначе на свежей БД (после `migrate` без сидов, как в Docker-проде с
 * RUN_SEEDS=false или ручной установке) роль суперадмина создалась бы без единого
 * права, и админ получал бы 403 во всех разделах. Затем назначает роль суперадмина
 * из config('rbac.superadmin_role').
 *
 * Недостающие аргументы (email, name) и пароль запрашиваются интерактивно.
 * Возвращает SUCCESS при успешном создании / обновлении и FAILURE при
 * несовпадении паролей или ошибке валидации.
 *
 * Использование:
 *   php artisan app:create-admin admin@example.com "Имя"
 *   php artisan app:create-admin
 */
class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email? : Email администратора} {name? : Имя администратора}';

    protected $description = 'Создать или обновить пользователя с ролью администратора';

    /**
     * Запрашивает недостающие данные, валидирует их и сохраняет администратора.
     *
     * @return int self::SUCCESS — успех; self::FAILURE — пароли не совпали
     *             или не прошла валидация.
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

        // Гарантируем, что базовый RBAC (каталог прав + выдача суперадмину) существует
        // ДО назначения роли. Идемпотентно, поэтому безопасно при каждом запуске.
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
