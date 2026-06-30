<?php

namespace App\Traits;

use App\Models\ActivityLog;

/**
 * Автоматическое логирование создания, обновления и удаления модели.
 *
 * Подключается к Eloquent-событиям created, updated, deleted.
 * Для ручного лога (duplicate, restore) — вызывайте self::logManual().
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
            unset($dirty['updated_at']); // шум

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
            // forceDelete у soft-delete модели тоже бросает deleted — различаем.
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
     * Ручная запись лога (для дублирования и прочих действий).
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model  Логируемая модель (субъект)
     * @param  string  $action  Действие (например, duplicated)
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  Diff полей: [старое, новое]
     */
    public static function logManual($model, string $action, ?array $changes = null): void
    {
        self::writeLog($model, $action, $changes);
    }

    private static function writeLog($model, string $action, ?array $changes = null): void
    {
        // Единая точка записи (метка субъекта/автора, проглатывание ошибок) — в модели.
        ActivityLog::record($model, $action, $changes);
    }
}
