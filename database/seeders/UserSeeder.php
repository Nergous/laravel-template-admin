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
     * Seeding users.
     *
     * - production            — only the administrator; the password is taken from env
     *                           (ADMIN_PASSWORD) and hashed. Without a password the account
     *                           is not created.
     * - local / other envs    — test administrator and operator with the password
     *                           "password123". Not for production.
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
     * Creates the administrator from environment data.
     *
     * If ADMIN_PASSWORD is not set — prints a warning and creates nothing,
     * so that no account with a known/empty password appears.
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
     * Creates two test users. Do not use in production.
     */
    private function seedTestUsers(): void
    {
        $this->upsertUser('admin@example.com', 'Администратор', 'password123', RbacGuard::superadminRole());
        $this->upsertUser('operator@example.com', 'Оператор', 'password123', 'operator');
    }

    /**
     * Idempotently creates/updates a user and assigns a role.
     *
     * Accounts for soft delete: if a user with this email is in the trash —
     * restores it (rather than failing on UNIQUE when the seeder runs again).
     * Password/name are set only on creation; existing ones are not overwritten.
     */
    private function upsertUser(string $email, string $name, string $password, string $roleName): void
    {
        $user = User::withTrashed()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->name = $name;
            $user->password = Hash::make($password);
        }

        if ($user->trashed()) {
            $user->restore(); // clears deleted_at and saves
        } else {
            $user->save();
        }

        $user->syncRoles([Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web'])]);
    }
}
