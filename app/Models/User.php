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
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Generated (STORED) column only on MySQL/MariaDB — technical,
        // enforces UNIQUE on active emails. Hidden so that serialization
        // (auth.user in Inertia, etc.) is identical across all drivers.
        'email_active',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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
     * @param  string|null  $role  Role name (spatie); empty — filter is not applied
     */
    public function scopeFilterByRole(Builder $query, ?string $role): Builder
    {
        if (blank($role)) {
            return $query;
        }

        return $query->role($role);
    }
}
