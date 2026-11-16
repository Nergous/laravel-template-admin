<?php

namespace App\Exports;

use App\Enums\ExportFormat;
use App\Events\ActivityOccurred;
use App\Http\Responses\TableDownload;
use App\Models\User;
use App\Services\System\DisplayTime;
use App\Support\Export\TableExport;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * User list export (CSV or XLSX, see TableExport): columns, file name and
 * the users_exported log entry once the file is written.
 */
class UserExport
{
    public function __construct(private readonly DisplayTime $time) {}

    /** @return list<array{key: string, label: string}> Columns to choose from on the list page. */
    public function options(): array
    {
        return TableExport::options($this->columns());
    }

    /**
     * Streams the users of $query in the chosen format and columns.
     * lazy() keeps eager loading of roles for every chunk.
     *
     * @param  Builder<User>  $query  Filtered and sorted list query
     * @param  list<string>  $columns  Keys of the chosen columns; empty — all
     * @param  User  $actor  Who exports the list (for the log)
     */
    public function download(Builder $query, ExportFormat $format, array $columns, User $actor): TableDownload
    {
        return new TableDownload(
            TableExport::make($this->columns(), $format, $this->time, $columns),
            $query->with('roles')->lazy(500),
            'users',
            'Пользователи',
            fn (int $count) => ActivityOccurred::by($actor, null, 'users_exported', ['rows' => [null, $count]]),
        );
    }

    /**
     * Columns in file order.
     *
     * @return array<string, array{0: string, 1: Closure(User): (string|int|null)}>
     */
    private function columns(): array
    {
        $timezone = $this->time->zone();
        $date = fn ($value) => $value?->timezone($timezone)->format('Y-m-d H:i:s');

        return [
            'id' => ['ID', fn (User $user) => $user->id],
            'name' => ['Имя', fn (User $user) => $user->name],
            'email' => ['Email', fn (User $user) => $user->email],
            'roles' => ['Роли', fn (User $user) => $user->roles->pluck('name')->implode(', ')],
            'status' => ['Статус', fn (User $user) => $user->is_active ? 'Активен' : 'Заблокирован'],
            'last_login_at' => ['Последний вход', fn (User $user) => $date($user->last_login_at)],
            'created_at' => ['Создан', fn (User $user) => $date($user->created_at)],
        ];
    }
}
