<?php

namespace App\Models;

use App\Support\Impersonation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * An activity (audit) log entry: who (user_id) did what (action) and to which
 * entity (the polymorphic subject relation). changes is a JSON diff of the
 * changes. The time is recorded manually (created_at); updated_at is not kept.
 * Created by the LogsActivity trait and by the roles/permissions controllers —
 * both write through the single ActivityLog::record() entry point.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $impersonator_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $subject_label
 * @property string|null $actor_label
 * @property string|null $impersonator_label
 * @property array|null $changes
 * @property Carbon|null $created_at
 */
class ActivityLog extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = 'activity_log';

    /**
     * Bell notification categories a user can mute (users.notification_mutes).
     * "system" is everything that fits no other category (backups, queue, log cleanup).
     */
    public const NOTIFICATION_CATEGORIES = ['auth', 'users', 'roles', 'media', 'settings', 'system'];

    /** Account-security actions: they go to "auth" whatever their subject is. */
    private const AUTH_ACTIONS = ['login_failed', 'session_ended', 'sessions_ended', 'impersonation_started', 'impersonation_stopped'];

    /** Media actions written without a subject row (folders, failed uploads). */
    private const MEDIA_ACTIONS = ['upload_failed', 'folder_renamed', 'folder_cleared'];

    protected $fillable = [
        'user_id',
        'impersonator_id',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'actor_label',
        'impersonator_label',
        'changes',
        'created_at',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Author override for code that runs without an authenticated user (queue
     * jobs): see actingAs(). $hasActorOverride tells an explicit null ("the
     * system") apart from "no override".
     */
    private static ?User $actorOverride = null;

    private static bool $hasActorOverride = false;

    // ---------- Relations ----------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The superadmin who performed the action in "sign in as" mode. */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
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

    /**
     * Actions for the user's notification bell: entries by other users (and the
     * system) since the bell was last opened, at most one week back. Routine
     * successful logins and logouts are left out as noise.
     */
    public static function forBell(User $user): Builder
    {
        $query = static::query()
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->getKey()))
            ->whereNotIn('action', ['login', 'logout'])
            ->where('created_at', '>=', now()->subWeek());

        $muted = array_values(array_intersect(self::NOTIFICATION_CATEGORIES, $user->notification_mutes ?? []));

        if (in_array('system', $muted, true)) {
            // Keep only rows that belong to a category the user still wants.
            $wanted = array_diff(self::NOTIFICATION_CATEGORIES, $muted);
            $query->where(function (Builder $q) use ($wanted) {
                $q->whereRaw('1 = 0');
                foreach ($wanted as $category) {
                    $q->orWhere(fn (Builder $inner) => self::applyCategory($inner, $category));
                }
            });
        } else {
            foreach ($muted as $category) {
                $query->whereNot(fn (Builder $q) => self::applyCategory($q, $category));
            }
        }

        return $query;
    }

    /**
     * Constrains $query to one notification category (other than "system").
     * Every condition is null-safe, so the constraint can be negated with whereNot().
     */
    private static function applyCategory(Builder $query, string $category): void
    {
        $subjectIn = fn (Builder $q, array $types) => $q->whereNotNull('subject_type')->whereIn('subject_type', $types);

        match ($category) {
            'auth' => $query->whereIn('action', self::AUTH_ACTIONS),
            'users' => $query->whereNotIn('action', self::AUTH_ACTIONS)->where(fn (Builder $q) => $q
                ->where(fn (Builder $s) => $subjectIn($s, [User::class]))
                ->orWhere('action', 'users_exported')),
            'roles' => $query->where(fn (Builder $s) => $subjectIn($s, [SpatieRole::class, Permission::class])),
            'media' => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $s) => $subjectIn($s, [Media::class]))
                ->orWhereIn('action', self::MEDIA_ACTIONS)),
            'settings' => $query->where('action', 'settings_updated'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** Notification category of this entry (see NOTIFICATION_CATEGORIES). */
    public function category(): string
    {
        return match (true) {
            in_array($this->action, self::AUTH_ACTIONS, true) => 'auth',
            $this->subject_type === User::class, $this->action === 'users_exported' => 'users',
            in_array($this->subject_type, [SpatieRole::class, Permission::class], true) => 'roles',
            $this->subject_type === Media::class, in_array($this->action, self::MEDIA_ACTIONS, true) => 'media',
            $this->action === 'settings_updated' => 'settings',
            default => 'system',
        };
    }

    /**
     * Unread bell entries: newer than the user's last look at the bell (24 hours if never).
     */
    public static function unreadFor(User $user): Builder
    {
        $since = $user->notifications_seen_at ?? now()->subDay();

        return static::forBell($user)->where('created_at', '>', $since);
    }

    /**
     * Unread bell counter. A burst of failed sign-ins for one account counts
     * once, so a password-guessing attempt does not flood the badge.
     */
    public static function unreadCountFor(User $user): int
    {
        $others = static::unreadFor($user)->where('action', '!=', 'login_failed')->count();
        $failedLogins = static::unreadFor($user)
            ->where('action', 'login_failed')
            ->distinct()
            ->count('subject_label');
        $failedWithoutLabel = static::unreadFor($user)
            ->where('action', 'login_failed')
            ->whereNull('subject_label')
            ->exists();

        return $others + $failedLogins + ($failedWithoutLabel ? 1 : 0);
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
     * @param  Model|null  $subject  The entity the action was performed on; null for
     *                               actions without an entity (a failed login with an
     *                               unknown email, clearing the log)
     * @param  string  $action  The action name (created, updated, deleted, restored …)
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  Diff of changed fields: field → [old, new]
     * @param  string|null  $label  Subject label override (defaults to the subject's name)
     */
    public static function record(?Model $subject, string $action, ?array $changes = null, ?string $label = null): void
    {
        $actor = self::$hasActorOverride ? self::$actorOverride : Auth::user();
        $actorId = $actor?->getKey();

        if ($actorId !== null && ! User::withTrashed()->whereKey($actorId)->exists()) {
            $actorId = null;
        }

        $impersonator = self::$hasActorOverride ? null : self::currentImpersonator();

        try {
            static::create([
                'user_id' => $actorId,
                'impersonator_id' => $impersonator?->getKey(),
                'action' => $action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'subject_label' => $label ?? ($subject ? static::labelFor($subject) : null),
                'actor_label' => $actor?->name,
                'impersonator_label' => $impersonator?->name,
                'changes' => $changes,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Runs $callback with $actor as the author of every entry it writes.
     *
     * Queue workers have no authenticated user, so without this a file uploaded
     * by an admin would be logged as a system action (and show up in the
     * uploader's own notification bell).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function actingAs(?User $actor, callable $callback): mixed
    {
        $previous = [self::$hasActorOverride, self::$actorOverride];
        self::$hasActorOverride = true;
        self::$actorOverride = $actor;

        try {
            return $callback();
        } finally {
            [self::$hasActorOverride, self::$actorOverride] = $previous;
        }
    }

    private static function currentImpersonator(): ?User
    {
        $id = Impersonation::impersonatorId();

        return $id !== null ? User::withTrashed()->find($id) : null;
    }

    /**
     * Human-readable subject label: title → name → original_name → filename → id.
     */
    protected static function labelFor($subject): string
    {
        return $subject->title
            ?? $subject->name
            ?? $subject->original_name
            ?? $subject->filename
            ?? (string) $subject->getKey();
    }

    // ---------- Display helpers ----------

    /**
     * Author for display: the live name, then the name snapshot, then "Система".
     * Actions done in "sign in as" mode name the superadmin as well. Eager load
     * user and impersonator to avoid a query per row.
     */
    public function actorName(): string
    {
        $name = $this->user?->name ?? $this->actor_label ?? 'Система';
        $impersonator = $this->impersonator?->name ?? $this->impersonator_label;

        return $impersonator ? "{$name} (действовал {$impersonator})" : $name;
    }

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

        if ($type === '') {
            return __('activity.subjects.system');
        }

        $key = config('audit.subjects')[$type] ?? null;

        return $key ? __('activity.subjects.'.$key) : class_basename($type);
    }
}
