<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-fills created_by / updated_by from the current user.
 *
 * created_by is set once on creation, updated_by — on every change.
 * Values are assigned directly (bypassing $fillable). If the request is
 * unauthenticated (factories, queue, seeders) — the fields are left as they are.
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
