<?php

namespace App\Console\Commands;

use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SeedFreshDatabase extends Command
{
    protected $signature = 'app:seed-fresh {--users : Include initial users}';

    protected $description = 'Seed an empty database once without changing existing data';

    private const MARKER_KEY = 'system.initial_seed';

    private const INFRASTRUCTURE_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'sqlite_sequence',
    ];

    public function handle(): int
    {
        foreach (['settings', 'users', 'roles', 'permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Table {$table} is missing; run migrations before seeding.");

                return self::FAILURE;
            }
        }

        $marker = DB::table('settings')->where('key', self::MARKER_KEY)->value('value');

        if ($marker === 'complete') {
            $this->info('Initial seeds already completed; skipping.');

            return self::SUCCESS;
        }

        if ($marker !== null && $marker !== 'pending') {
            $this->error('Unknown initial seed state; refusing to change database data.');

            return self::FAILURE;
        }

        if ($marker === null) {
            if ($this->hasApplicationData()) {
                $this->info('Existing application data found; initial seeds skipped.');

                return self::SUCCESS;
            }

            $time = now();
            DB::table('settings')->insert([
                'group' => 'system',
                'key' => self::MARKER_KEY,
                'type' => 'string',
                'value' => 'pending',
                'created_at' => $time,
                'updated_at' => $time,
            ]);
        }

        // The pending marker survives a failed seed transaction, so the next boot retries.
        DB::transaction(function (): void {
            $this->runSeeder(RolePermissionSeeder::class);

            if ($this->option('users')) {
                $this->runSeeder(UserSeeder::class);
            }

            DB::table('settings')->where('key', self::MARKER_KEY)->update([
                'value' => 'complete',
                'updated_at' => now(),
            ]);
        });

        $this->info('Initial seeds completed.');

        return self::SUCCESS;
    }

    private function hasApplicationData(): bool
    {
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (in_array($table, self::INFRASTRUCTURE_TABLES, true)) {
                continue;
            }

            if (DB::table($table)->exists()) {
                return true;
            }
        }

        return false;
    }

    private function runSeeder(string $class): void
    {
        $exitCode = $this->call('db:seed', [
            '--class' => $class,
            '--force' => true,
            '--no-interaction' => true,
        ]);

        if ($exitCode !== self::SUCCESS) {
            throw new RuntimeException("Initial seeder {$class} failed.");
        }
    }
}
