<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

/**
 * An activity (audit) log entry: who (user_id) did what (action) and to which
 * entity (the polymorphic subject relation). changes is a JSON diff of the
 * changes. The time is recorded manually (created_at); updated_at is not kept.
 * Created by the LogsActivity trait and by the roles/permissions controllers —
 * both write through the single ActivityLog::record() entry point.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property string|null $actor_label
 * @property array|null $changes
 * @property \Carbon\Carbon|null $created_at
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

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    // ---------- Relations ----------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The action subject (polymorphic relation); withTrashed — to also show deleted ones. */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    // ---------- Scopes ----------

    /**
     * Entries from the last 24 hours. The single definition of the "recent"
     * actions window — used by both the badge counter in HandleInertiaRequests
     * and the recent() feed.
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDay());
    }

    // ---------- Retention / cleanup ----------

    /**
     * Log entries older than the retention period (config('audit.retention_days'),
     * 180 days by default). The daily model:prune deletes them in a single
     * DELETE — MassPrunable does not hydrate models and does not fire events (the
     * audit log has no deletion events, the cleanup must be cheap).
     * retention_days <= 0 → return a deliberately empty result set (cleanup is
     * disabled, the log grows without bound).
     */
    public function prunable(): Builder
    {
        $days = (int) config('audit.retention_days', 180);

        if ($days <= 0) {
            return static::whereRaw('1 = 0');
        }

        return static::where('created_at', '<', now()->subDays($days));
    }

    // ---------- Writing ----------

    /**
     * The single entry point for writing to the log. Used both by the LogsActivity
     * trait (automatic model events) and by the roles/permissions controllers
     * (manual logging).
     *
     * Why two entry points: own models
     * (User, Media) are logged automatically by the LogsActivity trait via Eloquent
     * events. Role/Permission are models from the spatie package; the trait can be
     * attached to them only via a subclass, and that would change their
     * getMorphClass() and require migrating the already accumulated log rows.
     *
     * Besides subject_label, it records actor_label — a snapshot of the author's
     * name, so that "who did it" survives the physical deletion of the user. A
     * failure to write the log must not break the user's action, so exceptions are
     * swallowed (with report()).
     *
     * @param  Model  $subject  The entity the action was performed on
     * @param  string  $action  The action name (created, updated, deleted, restored …)
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  Diff of changed fields: field → [old, new]
     */
    public static function record($subject, string $action, ?array $changes = null): void
    {
        try {
            static::create([
                'user_id' => Auth::id(),
                'action' => $action,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'subject_label' => static::labelFor($subject),
                'actor_label' => Auth::user()?->name,
                'changes' => $changes,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Human-readable subject label: title → name → filename → id.
     */
    protected static function labelFor($subject): string
    {
        return $subject->title
            ?? $subject->name
            ?? $subject->filename
            ?? (string) $subject->getKey();
    }

    // ---------- Display helpers ----------

    /**
     * Human-readable action name (strings are in lang/<locale>/activity.php).
     */
    public function actionLabel(): string
    {
        $key = 'activity.actions.'.$this->action;

        return Lang::has($key) ? __($key) : $this->action;
    }

    /**
     * Human-readable model name for display in the log.
     *
     * The "class → translation key" map is data-driven: new entities are
     * registered in config('audit.subjects'), the model is not edited.
     * An unregistered type falls back to class_basename.
     */
    public function subjectTypeLabel(): string
    {
        $type = (string) ($this->subject_type ?? '');
        $key = config('audit.subjects')[$type] ?? null;

        return $key ? __('activity.subjects.'.$key) : class_basename($type);
    }
}
