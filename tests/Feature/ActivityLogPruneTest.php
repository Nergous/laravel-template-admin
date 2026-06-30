<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Регрессия на находку M3: ежедневный model:prune обрезает журнал действий
 * (activity_log) по сроку хранения config('audit.retention_days'), а нулевой
 * срок полностью отключает чистку.
 */
class ActivityLogPruneTest extends TestCase
{
    use RefreshDatabase;

    /** Хелпер: запись журнала с явным created_at ($timestamps = false). */
    private function makeLog(string $action, \DateTimeInterface $createdAt): ActivityLog
    {
        return ActivityLog::create([
            'action' => $action,
            'created_at' => $createdAt,
        ]);
    }

    public function test_prune_removes_records_older_than_retention(): void
    {
        config(['audit.retention_days' => 180]);

        $old = $this->makeLog('old', now()->subDays(200));
        $fresh = $this->makeLog('fresh', now()->subDays(10));

        Artisan::call('model:prune', ['--model' => [ActivityLog::class]]);

        // Старая запись удалена, свежая осталась.
        $this->assertDatabaseMissing('activity_log', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $fresh->id]);
    }

    public function test_prune_disabled_when_retention_is_zero(): void
    {
        // Конфиг должен быть установлен ДО вызова команды — prunable() читает его.
        config(['audit.retention_days' => 0]);

        $old = $this->makeLog('old', now()->subDays(200));

        Artisan::call('model:prune', ['--model' => [ActivityLog::class]]);

        // Чистка отключена — даже очень старая запись остаётся на месте.
        $this->assertDatabaseHas('activity_log', ['id' => $old->id]);
    }
}
