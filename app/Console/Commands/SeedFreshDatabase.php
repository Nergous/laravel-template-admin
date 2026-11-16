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
    protected $signature = 'app:seed-fresh {--users : Создать и начальных пользователей}';

    protected $description = 'Один раз заполняет пустую БД начальными данными, существующие данные не меняет';

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
                $this->error("Нет таблицы {$table}: сначала выполните миграции.");

                return self::FAILURE;
            }
        }

        $marker = DB::table('settings')->where('key', self::MARKER_KEY)->value('value');

        if ($marker === 'complete') {
            $this->info('Начальные данные уже созданы, пропускаем.');

            return self::SUCCESS;
        }

        if ($marker !== null && $marker !== 'pending') {
            $this->error('Неизвестное состояние начального заполнения, данные БД не изменены.');

            return self::FAILURE;
        }

        if ($marker === null) {
            if ($this->hasApplicationData()) {
                $this->info('В БД уже есть данные приложения, начальное заполнение пропущено.');

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

        // The pending marker survives the transaction rollback, so the next run repeats the seeding.
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

        $this->info('Начальные данные созданы.');

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
            throw new RuntimeException("Сидер {$class} завершился с ошибкой.");
        }
    }
}
