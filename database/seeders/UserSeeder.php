<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use App\Services\Access\RbacGuard;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * @param  array{email?: string, name?: string, password?: string|null}  $initialAdmin  the first administrator (rbac.initial_admin)
     */
    public function __construct(
        private readonly RbacGuard $rbac,
        #[Config('rbac.initial_admin', [])] private readonly array $initialAdmin,
    ) {}

    /**
     * Creates the users.
     *
     * - production — only the administrator from rbac.initial_admin (ADMIN_EMAIL, ADMIN_NAME,
     *   ADMIN_PASSWORD); the password is hashed. Without a password no account is created.
     * - local and other environments — test administrator and operator with the
     *   password "password123". Do not use in production.
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
     * Creates the administrator from rbac.initial_admin.
     *
     * If ADMIN_PASSWORD is not set, prints a warning and creates nothing, so no
     * account with a known or empty password appears.
     */
    private function seedProductionAdmin(): void
    {
        $email = (string) ($this->initialAdmin['email'] ?? 'admin@example.com');
        $name = (string) ($this->initialAdmin['name'] ?? 'Администратор');
        $password = $this->initialAdmin['password'] ?? null;

        if (blank($password)) {
            $this->command?->warn(
                'UserSeeder: ADMIN_PASSWORD не задан — администратор не создан. '
                .'Задайте ADMIN_PASSWORD в .env или создайте админа командой `php artisan app:create-admin`.'
            );

            return;
        }

        $this->upsertUser($email, $name, (string) $password, $this->rbac->superadminRole());
    }

    /**
     * Creates two test users. Do not use in production.
     */
    private function seedTestUsers(): void
    {
        $this->upsertUser('admin@example.com', 'Администратор', 'password123', $this->rbac->superadminRole());
        $this->upsertUser('operator@example.com', 'Оператор', 'password123', 'operator');
    }

    /**
     * Idempotently creates or updates a user and assigns the role.
     *
     * Honours soft deletion: a user with this email in the trash is restored
     * (instead of failing on UNIQUE when the seeder runs again). Password and
     * name are set only on creation; existing ones are not overwritten.
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
