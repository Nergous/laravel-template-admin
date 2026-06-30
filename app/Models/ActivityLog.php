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
 * Запись журнала действий (аудита): кто (user_id) что сделал (action)
 * и над какой сущностью (полиморфная связь subject). changes — JSON-diff
 * изменений. Время фиксируется вручную (created_at), updated_at не ведётся.
 * Создаётся трейтом LogsActivity и контроллерами ролей/разрешений — оба
 * пишут через единую точку ActivityLog::record().
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

    /** Субъект действия (полиморфная связь); withTrashed — чтобы показывать и удалённые. */
    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    // ---------- Scopes ----------

    /**
     * Записи за последние сутки. Единое определение окна «недавних» действий —
     * используют и счётчик-бейдж в HandleInertiaRequests, и фид recent().
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->where('created_at', '>=', now()->subDay());
    }

    // ---------- Retention / чистка ----------

    /**
     * Записи журнала старше срока хранения (config('audit.retention_days'),
     * по умолчанию 180 дней). Ежедневный model:prune удаляет их одним
     * DELETE — MassPrunable не гидрирует модели и не шлёт события (для
     * аудит-лога событий на удаление нет, чистка должна быть дешёвой).
     * retention_days <= 0 → возвращаем заведомо пустую выборку (чистка
     * отключена, журнал растёт неограниченно).
     */
    public function prunable(): Builder
    {
        $days = (int) config('audit.retention_days', 180);

        if ($days <= 0) {
            return static::whereRaw('1 = 0');
        }

        return static::where('created_at', '<', now()->subDays($days));
    }

    // ---------- Запись ----------

    /**
     * Единая точка записи в журнал. Используется и трейтом LogsActivity
     * (авто-события моделей), и контроллерами ролей/разрешений (ручной лог).
     *
     * Почему две точки входа: собственные модели
     * (User, Media) логируются автоматически трейтом LogsActivity через события
     * Eloquent. Role/Permission — модели пакета spatie; повесить на них трейт
     * можно лишь подклассом, а это сменило бы их getMorphClass() и потребовало
     * миграции уже накопленных строк журнала.
     *
     * Помимо subject_label фиксирует actor_label — снимок имени автора, чтобы
     * «кто сделал» переживало физическое удаление пользователя. Сбой записи
     * журнала не должен срывать действие пользователя, поэтому исключения
     * проглатываются (с report()).
     *
     * @param  Model  $subject  Сущность, над которой совершено действие
     * @param  string  $action  Имя действия (created, updated, deleted, restored …)
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  Diff изменённых полей: поле → [старое, новое]
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
     * Человеко-читаемая метка субъекта: title → name → filename → id.
     */
    protected static function labelFor($subject): string
    {
        return $subject->title
            ?? $subject->name
            ?? $subject->filename
            ?? (string) $subject->getKey();
    }

    // ---------- Helpers отображения ----------

    /**
     * Человеко-читаемое название действия (строки — в lang/<locale>/activity.php).
     */
    public function actionLabel(): string
    {
        $key = 'activity.actions.'.$this->action;

        return Lang::has($key) ? __($key) : $this->action;
    }

    /**
     * Человеко-читаемое имя модели для отображения в журнале.
     *
     * Карта «класс → ключ перевода» — data-driven, в config('audit.subjects')
     * новые сущности регистрируются там, модель не редактируется.
     * Незарегистрированный тип откатывается на class_basename.
     */
    public function subjectTypeLabel(): string
    {
        $type = (string) ($this->subject_type ?? '');
        $key = config('audit.subjects')[$type] ?? null;

        return $key ? __('activity.subjects.'.$key) : class_basename($type);
    }
}
