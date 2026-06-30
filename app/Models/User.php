<?php

namespace App\Models;

use App\Traits\HasSearch;
use App\Traits\LogsActivity;
use App\Traits\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Модель пользователя административной панели.
 *
 * Роли и разрешения управляются пакетом spatie/laravel-permission.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property \Carbon\Carbon|null $email_verified_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, HasRoles, HasSearch, LogsActivity, Notifiable, SoftDeletes, TracksAuthor;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Генерируемая (STORED) колонка только на MySQL/MariaDB — техническая,
        // обеспечивает UNIQUE активных email. Прячем, чтобы сериализация
        // (auth.user в Inertia и т.п.) была одинаковой на всех драйверах.
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
     * Поиск по имени и email (подстрока).
     *
     * @param  string|null  $search  Поисковая строка
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $this->scopeSearchLike($query, $search, ['name', 'email']);
    }

    /**
     * Фильтр по имени роли (spatie).
     *
     * @param  string|null  $role  Имя роли (spatie); пустое — фильтр не применяется
     */
    public function scopeFilterByRole(Builder $query, ?string $role): Builder
    {
        if (blank($role)) {
            return $query;
        }

        return $query->role($role);
    }
}
