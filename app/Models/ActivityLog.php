<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An activity (audit) log entry: who (user_id) did what (action) to which
 * entity (the polymorphic subject relation). changes is a JSON diff. The time
 * is written manually (created_at); updated_at is not stored. Entries are
 * created by App\Services\System\ActivityRecorder on the ActivityOccurred event
 * (dispatched by the LogsActivity trait and services). Screen labels live in
 * App\Services\System\ActivityLogLabels.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property string|null $actor_label
 * @property array|null $changes
 * @property Carbon|null $created_at
 * @property-read User|null $user
 */
class ActivityLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = 'activity_log';

    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'actor_label',
        'changes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The action subject (polymorphic); withTrashed to show deleted ones too. */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    /**
     * Entries of the last 24 hours — the single definition of the "recent"
     * actions window.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDay());
    }

    /** Newest entries first; id separates entries created in the same second. */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->latest('created_at')->latest('id');
    }

    /** Entries where the user is the author or the subject. */
    public function scopeInvolving(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('user_id', $user->id)
            ->orWhere(fn (Builder $query) => $query
                ->where('subject_type', User::class)
                ->where('subject_id', $user->id)));
    }

    /**
     * Entries older than the retention period (config('activity.retention_days'),
     * 180 days by default). The daily model:prune deletes them with one DELETE —
     * MassPrunable creates no models and fires no events, so pruning is cheap.
     * retention_days <= 0 yields an always-empty selection: pruning is off and
     * the log grows unbounded.
     */
    public function prunable(): Builder
    {
        $days = (int) config('activity.retention_days', 180);

        if ($days <= 0) {
            return static::whereRaw('1 = 0');
        }

        return static::where('created_at', '<', now()->subDays($days));
    }
}
