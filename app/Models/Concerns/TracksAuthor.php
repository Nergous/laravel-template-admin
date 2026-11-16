<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Fills created_by / updated_by with the current user and provides the
 * creator() / editor() relations.
 *
 * created_by is set once on creation (unless set explicitly), updated_by on
 * every update. Values are assigned directly, bypassing $fillable. Without an
 * authenticated user (factories, the queue, seeders) the fields are left
 * alone — the calling code sets the author there.
 */
trait TracksAuthor
{
    public static function bootTracksAuthor(): void
    {
        static::creating(function ($model) {
            $id = Auth::id();
            if (! $id) {
                return;
            }
            if (empty($model->created_by)) {
                $model->created_by = $id;
            }
            if (empty($model->updated_by)) {
                $model->updated_by = $id;
            }
        });

        static::updating(function ($model) {
            if ($id = Auth::id()) {
                $model->updated_by = $id;
            }
        });
    }

    /** Who created the record. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Who last changed the record. */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
