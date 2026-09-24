<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Automatic logging of model creation, update and deletion.
 *
 * Hooks into the Eloquent events created, updated, deleted.
 * For a manual log entry (duplicate, restore) — call self::logManual().
 *
 * Secrets never reach the log: attributes from the model's $hidden are skipped,
 * except the password, which is recorded only as the fact of a change (masked).
 * A model can also list noisy technical columns in a protected $auditExclude array.
 */
trait LogsActivity
{
    /** Replaces secret values in the log diff. */
    public const AUDIT_MASK = '••••••';

    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            self::writeLog($model, 'created');
        });

        static::updated(function ($model) {
            $changes = $model->auditChanges();

            if ($changes === []) {
                return;
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
     * The diff of the current update for the log: field → [old, new], without
     * timestamps, hidden secrets, and the model's excluded columns.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public function auditChanges(): array
    {
        $hidden = $this->getHidden();
        $excluded = property_exists($this, 'auditExclude') ? $this->auditExclude : [];
        $changes = [];

        foreach ($this->getDirty() as $key => $newValue) {
            if ($key === 'updated_at' || in_array($key, $excluded, true)) {
                continue;
            }

            if ($key === 'password') {
                $changes[$key] = [self::AUDIT_MASK, self::AUDIT_MASK];

                continue;
            }

            if (in_array($key, $hidden, true)) {
                continue;
            }

            $changes[$key] = [$this->getOriginal($key), $newValue];
        }

        // Only the author column changed (e.g. a skipped secret was the real change).
        if (array_keys($changes) === ['updated_by']) {
            return [];
        }

        return $changes;
    }

    /**
     * Manual log entry (for duplication and other actions).
     *
     * @param  Model  $model  The logged model (subject)
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
