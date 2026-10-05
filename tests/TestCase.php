<?php

namespace Tests;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        // This runs before RefreshDatabase can migrate or clear any tables. The
        // suite only ever runs on SQLite :memory: (phpunit.xml forces it); CI
        // checks MariaDB with artisan commands instead (.github/workflows/ci.yml).
        if (! $app->environment('testing')
            || $app['config']->get('database.default') !== 'sqlite'
            || $app['config']->get('database.connections.sqlite.database') !== ':memory:'
            || $app['config']->get('database.connections.sqlite.url')) {
            throw new \RuntimeException('Tests require isolated SQLite :memory: with no DB_URL.');
        }

        // Backup tests create, rotate and delete dumps: never in the real folder.
        $backups = rtrim(str_replace('\\', '/', (string) $app['config']->get('backup.path')), '/');
        if ($backups === '' || $backups === rtrim(str_replace('\\', '/', $app->storagePath('app/backups')), '/')) {
            throw new \RuntimeException('Tests require BACKUP_PATH outside storage/app/backups (see phpunit.xml).');
        }

        Http::preventStrayRequests();

        return $app;
    }

    /**
     * The test suite runs pure PHP (no `npm run build`), so the Vite manifest is
     * absent. Stub Vite out globally — `@vite` in admin.blade.php would otherwise
     * throw ViteManifestNotFoundException on every Inertia full-page render.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

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
