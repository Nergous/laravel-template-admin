<?php

namespace App\Traits;

use App\Models\ActivityLog;

/**
 * Automatic logging of model creation, update and deletion.
 *
 * Hooks into the Eloquent events created, updated, deleted.
 * For a manual log entry (duplicate, restore) — call self::logManual().
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            self::writeLog($model, 'created');
        });

        static::updated(function ($model) {
            $dirty = $model->getDirty();
            unset($dirty['updated_at']); // noise

            if (empty($dirty)) {
                return;
            }

            $changes = [];
            foreach ($dirty as $key => $newValue) {
                $changes[$key] = [
                    $model->getOriginal($key),
                    $newValue,
                ];
            }

            self::writeLog($model, 'updated', $changes);
        });

        static::deleted(function ($model) {
            // forceDelete on a soft-delete model also fires deleted — we distinguish them.
            $action = (method_exists($model, 'isForceDeleting') && $model->isForceDeleting())
                ? 'force_deleted'
                : 'deleted';

            self::writeLog($model, $action);
        });

        // SoftDeletes — restored
        if (method_exists(static::class, 'bootSoftDeletes')) {
            static::restored(function ($model) {
                self::writeLog($model, 'restored');
            });
        }
    }

    /**
     * Manual log entry (for duplication and other actions).
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model  The logged model (subject)
     * @param  string  $action  The action (e.g. duplicated)
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  Field diff: [old, new]
     */
    public static function logManual($model, string $action, ?array $changes = null): void
    {
        self::writeLog($model, $action, $changes);
    }

    private static function writeLog($model, string $action, ?array $changes = null): void
    {
        // The single write point (subject/author label, error swallowing) lives in the model.
        ActivityLog::record($model, $action, $changes);
    }
}
