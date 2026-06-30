<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Наполнение пользователями.
     *
     * - production            — только администратор, пароль берётся из env
     *                           (ADMIN_PASSWORD) и хэшируется. Без пароля аккаунт
     *                           не создаётся.
     * - local / прочие среды  — тестовые администратор и оператор с паролем
     *                           "password123". Не для production.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->seedProductionAdmin();

            return;
        }

        $this->seedTestUsers();
    }

    /**
     * Создаёт администратора по данным из окружения.
     *
     * Если ADMIN_PASSWORD не задан — выводит предупреждение и ничего не создаёт,
     * чтобы не появился аккаунт с известным/пустым паролем.
     */
    private function seedProductionAdmin(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $name = env('ADMIN_NAME', 'Администратор');
        $password = env('ADMIN_PASSWORD');

        if (blank($password)) {
            $this->command?->warn(
                'UserSeeder: ADMIN_PASSWORD не задан — администратор не создан. '
                .'Задайте ADMIN_PASSWORD в .env или создайте админа через `php artisan app:create-admin`.'
            );

            return;
        }

        $this->upsertUser($email, $name, $password, RbacGuard::superadminRole());
    }

    /**
     * Создаёт двух тестовых пользователей. Не использовать в production.
     */
    private function seedTestUsers(): void
    {
        $this->upsertUser('admin@example.com', 'Администратор', 'password123', RbacGuard::superadminRole());
        $this->upsertUser('operator@example.com', 'Оператор', 'password123', 'operator');
    }

    /**
     * Идемпотентно создаёт/обновляет пользователя и назначает роль.
     *
     * Учитывает soft-delete: если пользователь с таким email лежит в корзине —
     * восстанавливает его (а не падает на UNIQUE при повторном прогоне сидера).
     * Пароль/имя выставляются только при создании, существующие не перезаписываются.
     */
    private function upsertUser(string $email, string $name, string $password, string $roleName): void
    {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = $name;
            $user->password = Hash::make($password);
        }

        if ($user->trashed()) {
            $user->restore(); // снимает deleted_at и сохраняет
        } else {
            $user->save();
        }

        $user->syncRoles([Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])]);
    }
}
