<?php

namespace App\Models\Concerns;

use App\Events\ActivityOccurred;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Automatically logs model creation, updates and deletion (the Eloquent
 * created, updated, deleted events, plus restored with SoftDeletes). Other
 * actions (duplication etc.) are logged by services via ActivityOccurred.
 *
 * Secrets never reach the log: attributes from the model's $hidden are skipped,
 * except the password — it is logged only as the fact of a change (masked).
 * Noisy technical columns are listed by the model in protected array $auditExclude.
 *
 * Entries are written by App\Listeners\WriteActivityLog → ActivityRecorder
 * (labels, author, error suppression).
 */
trait LogsActivity
{
    /** Replaces secret values in the log diff. */
    public const AUDIT_MASK = '••••••';

    public static function bootLogsActivity(): void
    {
        static::created(fn (Model $model) => ActivityOccurred::dispatch($model, 'created'));

        static::updated(function (Model $model) {
            // restore() saves the model, so updated fires right before restored;
            // the restore is logged once — by the restored handler.
            if (method_exists($model, 'getDeletedAtColumn')
                && $model->wasChanged($model->getDeletedAtColumn())
                && $model->{$model->getDeletedAtColumn()} === null) {
                return;
            }

            $changes = $model->auditChanges();

            if ($changes !== []) {
                ActivityOccurred::dispatch($model, 'updated', $changes);
            }
        });

        static::deleted(function (Model $model) {
            // forceDelete on a SoftDeletes model also fires deleted — tell them apart.
            $action = method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
                ? 'force_deleted'
                : 'deleted';

            ActivityOccurred::dispatch($model, $action);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(fn (Model $model) => ActivityOccurred::dispatch($model, 'restored'));
        }
    }

    /**
     * The diff of the current change for the log: field → [old, new], without
     * timestamps, hidden secrets and the model's excluded columns.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    protected function auditChanges(): array
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

        // Only the author changed (e.g. the real change was a skipped secret).
        if (array_keys($changes) === ['updated_by']) {
            return [];
        }

        return $changes;
    }
}
