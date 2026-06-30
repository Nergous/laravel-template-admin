<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Regression for finding M3: the daily model:prune trims the activity log
 * (activity_log) by the config('audit.retention_days') retention period, while a
 * zero retention fully disables pruning.
 */
class ActivityLogPruneTest extends TestCase
{
    use RefreshDatabase;

    /** Helper: an activity log record with an explicit created_at ($timestamps = false). */
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

        // The old record is removed, the fresh one remains.
        $this->assertDatabaseMissing('activity_log', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $fresh->id]);
    }

    public function test_prune_disabled_when_retention_is_zero(): void
    {
        // The config must be set BEFORE calling the command — prunable() reads it.
        config(['audit.retention_days' => 0]);

        $old = $this->makeLog('old', now()->subDays(200));

        Artisan::call('model:prune', ['--model' => [ActivityLog::class]]);

        // Pruning is disabled — even a very old record stays in place.
        $this->assertDatabaseHas('activity_log', ['id' => $old->id]);
    }
}
