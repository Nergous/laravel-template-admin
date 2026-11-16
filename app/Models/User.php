<?php

namespace App\Models;

use App\Models\Concerns\HasSearch;
use App\Models\Concerns\LogsActivity;
use App\Models\Concerns\TracksAuthor;
use App\Support\FilterValues;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Admin panel user.
 *
 * Roles and permissions are managed by spatie/laravel-permission.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property string|null $blocked_reason
 * @property Carbon|null $last_login_at
 * @property Carbon|null $email_verified_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasSearch, LogsActivity, SoftDeletes, TracksAuthor;

    /** Role filter value that selects users without roles. */
    public const WITHOUT_ROLES = '__none';

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'blocked_reason',
    ];

    /**
     * Mirrors the column defaults so that a freshly created model (factories,
     * services) is active even before it is re-read from the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // A generated (STORED) column on MySQL/MariaDB only — technical, it
        // enforces UNIQUE for active emails. Hidden so that serialization
        // (auth.user in Inertia etc.) is the same on every driver.
        'email_active',
    ];

    /**
     * Technical columns that change on ordinary actions (sign-in).
     * LogsActivity skips them so they do not clutter the log.
     *
     * @var list<string>
     */
    protected array $auditExclude = ['last_login_at'];

    /** @var list<string> Search by name and email (HasSearch). */
    protected array $searchable = ['name', 'email'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Updates technical columns without model events, timestamps or log
     * entries (last sign-in, password, remember token). Values pass through
     * the model casts first, so arrays and dates are stored in the column format.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateSilently(array $values): void
    {
        $this->forceFill($values);
        static::query()->whereKey($this->getKey())->toBase()
            ->update(array_intersect_key($this->getAttributes(), $values));
        $this->syncOriginalAttributes(array_keys($values));
    }

    /**
     * Filter by role names (spatie): a user with any of them matches.
     *
     * @param  string|list<string>|null  $role  Comma-separated or listed role names
     *                                          (FilterValues); empty — no filter;
     *                                          self::WITHOUT_ROLES — users without roles
     */
    public function scopeFilterByRole(Builder $query, string|array|null $role): Builder
    {
        $names = FilterValues::strings($role);

        if ($names === []) {
            return $query;
        }

        $withoutRoles = in_array(self::WITHOUT_ROLES, $names, true);
        $names = array_values(array_diff($names, [self::WITHOUT_ROLES]));

        return $query->where(function (Builder $query) use ($names, $withoutRoles) {
            if ($names !== []) {
                $query->whereHas('roles', fn (Builder $roles) => $roles->whereIn('name', $names));
            }
            if ($withoutRoles) {
                $query->orWhereDoesntHave('roles');
            }
        });
    }
}
