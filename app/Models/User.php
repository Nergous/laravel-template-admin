<?php

namespace App\Models;

use App\Traits\HasSearch;
use App\Traits\LogsActivity;
use App\Traits\TracksAuthor;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Admin panel user model.
 *
 * Roles and permissions are managed by the spatie/laravel-permission package.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property bool $must_change_password
 * @property Carbon|null $last_login_at
 * @property Carbon|null $notifications_seen_at
 * @property Carbon|null $email_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasSearch, LogsActivity, Notifiable, SoftDeletes, TracksAuthor;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'must_change_password',
    ];

    /**
     * Mirrors the column defaults, so a freshly created model (factories,
     * services) is active before it is reloaded from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'must_change_password' => false,
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Generated (STORED) column only on MySQL/MariaDB — technical,
        // enforces UNIQUE on active emails. Hidden so that serialization
        // (auth.user in Inertia, etc.) is identical across all drivers.
        'email_active',
    ];

    /**
     * Technical columns that change on routine actions (login, opening the
     * notification bell). LogsActivity skips them so they do not flood the log.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['last_login_at', 'notifications_seen_at'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'notifications_seen_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Updates technical columns without model events, timestamps, or audit
     * entries (last login, notification read marker).
     *
     * @param  array<string, mixed>  $values
     */
    public function updateSilently(array $values): void
    {
        static::query()->whereKey($this->getKey())->toBase()->update($values);
        $this->forceFill($values)->syncOriginalAttributes(array_keys($values));
    }

    /**
     * Search by name and email (substring).
     *
     * @param  string|null  $search  Search string
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $this->scopeSearchLike($query, $search, ['name', 'email']);
    }

    /**
     * Filter by role name (spatie).
     *
     * @param  string|null  $role  Role name (spatie); empty — filter is not applied;
     *                             self::WITHOUT_ROLES — users without any role
     */
    public function scopeFilterByRole(Builder $query, ?string $role): Builder
    {
        if (blank($role)) {
            return $query;
        }

        if ($role === self::WITHOUT_ROLES) {
            return $query->doesntHave('roles');
        }

        return $query->role($role);
    }

    /** Role filter value that selects users without any role. */
    public const WITHOUT_ROLES = '__none';
}
