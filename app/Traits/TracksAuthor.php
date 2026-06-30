<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Автозаполнение created_by / updated_by из текущего пользователя.
 *
 * created_by ставится один раз при создании, updated_by — при каждом изменении.
 * Значения присваиваются напрямую (минуя $fillable). Если запрос неаутентифицирован
 * (фабрики, очередь, сидеры) — поля остаются как есть.
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
