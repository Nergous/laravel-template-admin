<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Pagination\PerPage;
use App\Http\Requests\BulkUserActionRequest;
use App\Http\Requests\BulkUserStatusRequest;
use App\Http\Requests\UserRequest;
use App\Http\Sorts\UserSort;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Services\UserService;
use App\Support\Impersonation;
use App\Support\RbacGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * User management: list, CRUD, and the trash (soft deletion).
 *
 * The controller handles HTTP: validates input (UserRequest), assembles Inertia
 * props, and redirects. Domain rules and orchestration (transactions, syncRoles,
 * preventing self-demotion/self-deletion) live in App\Services\UserService.
 *
 * Roles are assigned via a roles[] array (spatie role names). Resource pages
 * provide stable URLs for viewing, creating, and editing users.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /**
     * List of users with search, role/status/password-change filters, sorting,
     * a list of roles for the filter, and a trash counter.
     *
     * roles.permissions and permissions are eager loaded for
     * RbacGuard::canManageUser(), which would otherwise query per row.
     */
    public function index(Request $request, UserSort $sort, PerPage $perPage): Response|RedirectResponse
    {
        $query = $this->filteredQuery($request)->with([
            'roles.permissions',
            'permissions',
            'creator:id,name',
            'editor:id,name',
        ]);

        $users = $query
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->orderBy('id')
            ->paginate($perPage->get())
            ->withQueryString()
            ->through(fn (User $user) => $this->withCanManage($request, $user));

        if ($redirect = $this->redirectPastLastPage($users, $request)) {
            return $redirect;
        }

        $roles = Role::orderBy('name')->pluck('name', 'name')->toArray();
        $trashedCount = User::onlyTrashed()->count();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles, // ['admin'=>'admin', ...] — for the filter
            'withoutRolesValue' => User::WITHOUT_ROLES,
            'trashedCount' => $trashedCount,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
            ...$perPage->toArray(), // perPage + perPageOptions for the page-size selector
            'filters' => $request->only('search', 'role', 'status', 'must_change_password'),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('users.create'), 403);

        return Inertia::render('Users/FormPage', [
            'mode' => 'create',
            'allRoles' => Role::orderBy('name')->get(['id', 'name', 'description']),
        ]);
    }

    /** Creates a user and assigns roles to them (roles[]). */
    public function store(UserRequest $request): RedirectResponse
    {
        $user = $this->users->create(
            [
                ...$request->only('name', 'email', 'password'),
                'is_active' => $request->boolean('is_active', true),
                'blocked_reason' => $request->input('blocked_reason'),
                'must_change_password' => $request->boolean('must_change_password'),
            ],
            $request->input('roles', []),
        );

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Пользователь успешно создан');
    }

    public function edit(Request $request, User $user): Response
    {
        abort_unless(
            $request->user()?->can('users.edit') && RbacGuard::canManageUser($request->user(), $user),
            403,
        );

        return Inertia::render('Users/FormPage', [
            'mode' => 'edit',
            'user' => $user->load(['roles:id,name', 'creator:id,name', 'editor:id,name']),
            'allRoles' => Role::orderBy('name')->get(['id', 'name', 'description']),
            'isSelf' => $request->user()->is($user),
        ]);
    }

    /**
     * Updates a user and their roles. The password is changed only if provided;
     * does not allow an admin to remove the admin role from themselves (rule in the service).
     */
    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->input('password'),
        ];

        foreach (['is_active', 'must_change_password'] as $flag) {
            if ($request->has($flag)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        if ($request->has('blocked_reason')) {
            $data['blocked_reason'] = $request->input('blocked_reason');
        }

        $this->users->update(
            $user,
            $data,
            $request->input('roles', []),
            $request->user(),
        );

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Пользователь успешно обновлён');
    }

    /** Soft-deletes a user (you cannot delete yourself — rule in the service). */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->users->delete($user, $request->user());

        return $this->redirectToList('admin.users.index')
            ->with('success', 'Пользователь удалён');
    }

    /** Moves selected users, or every user matching current filters, to trash. */
    public function bulkDestroy(BulkUserActionRequest $request): RedirectResponse
    {
        $result = $request->boolean('all')
            ? $this->users->bulkDeleteAll(
                $request->validated('search'),
                $request->validated('role'),
                $request->validated('status'),
                $request->boolean('must_change_password'),
                $request->user(),
            )
            : $this->users->bulkDelete($request->validated('ids'), $request->user());

        return $this->redirectToList('admin.users.index')
            ->with('success', $this->bulkMessage(
                'Перемещено в корзину',
                $result,
                'нет прав или собственная учётная запись',
            ));
    }

    /** Blocks or unblocks selected users, or every user matching current filters. */
    public function bulkStatus(BulkUserStatusRequest $request): RedirectResponse
    {
        $active = $request->boolean('active');
        $result = $request->boolean('all')
            ? $this->users->bulkSetActiveAll(
                $request->validated('search'),
                $request->validated('role'),
                $request->validated('status'),
                $request->boolean('must_change_password'),
                $active,
                $request->user(),
                $request->validated('reason'),
            )
            : $this->users->bulkSetActive(
                $request->validated('ids'),
                $active,
                $request->user(),
                $request->validated('reason'),
            );

        return $this->redirectToList('admin.users.index')
            ->with('success', $this->bulkMessage(
                $active ? 'Разблокировано' : 'Заблокировано',
                $result,
                'нет прав или собственная учётная запись',
            ));
    }

    /**
     * Streams the filtered and sorted user list as a semicolon-separated CSV
     * (UTF-8 with BOM for Excel). lazy() keeps the roles eager load per chunk.
     */
    public function export(Request $request, UserSort $sort): StreamedResponse
    {
        $timezone = SettingsServiceProvider::displayTimezone();
        $query = $this->filteredQuery($request)
            ->with('roles')
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->orderBy('id');

        return response()->streamDownload(function () use ($query, $timezone) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID',
                'Имя',
                'Email',
                'Роли',
                'Статус',
                'Смена пароля',
                'Последний вход',
                'Создан',
            ], ';');

            $count = 0;
            foreach ($query->lazy(500) as $user) {
                /** @var User $user */
                fputcsv($out, array_map($this->csvCell(...), [
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->roles->pluck('name')->implode(', '),
                    $user->is_active ? 'Активен' : 'Заблокирован',
                    $user->must_change_password ? 'Да' : 'Нет',
                    $user->last_login_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                    $user->created_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                ]), ';');
                $count++;
            }

            fclose($out);

            ActivityLog::record(null, 'users_exported', ['rows' => [null, $count]]);
        }, 'users-'.now($timezone)->format('Y-m-d_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * The user page. With activity-log.view it also shows the latest entries
     * where the user is the author or the subject.
     */
    public function show(Request $request, User $user): Response
    {
        $user->load(['roles.permissions', 'permissions', 'creator:id,name', 'editor:id,name']);
        $canManage = RbacGuard::canManageUser($request->user(), $user);
        $this->hidePermissions($user);
        $canViewActivity = $request->user()?->can('activity-log.view') === true;
        $activity = [];

        if ($canViewActivity) {
            $activity = ActivityLog::query()
                ->with(['user', 'impersonator'])
                ->where(fn (Builder $query) => $query
                    ->where('user_id', $user->id)
                    ->orWhere(fn (Builder $query) => $query
                        ->where('subject_type', User::class)
                        ->where('subject_id', $user->id)))
                ->latest('created_at')
                ->latest('id')
                ->limit(15)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'action' => $log->action,
                    'actionLabel' => $log->actionLabel(),
                    'actor' => $log->actorName(),
                    'subject' => $log->subject_label ?: $log->subjectTypeLabel(),
                    'subjectType' => $log->subjectTypeLabel(),
                    'changesCount' => is_array($log->changes) ? count($log->changes) : 0,
                    'createdAt' => $log->created_at?->toIso8601String(),
                ]);
        }

        return Inertia::render('Users/Show', [
            'user' => $user,
            'canManage' => $canManage,
            'isSelf' => $request->user()->is($user),
            'canImpersonate' => Impersonation::canImpersonate($request->user(), $user),
            'activity' => $activity,
            'activityLinks' => $canViewActivity ? [
                'byUser' => route('admin.activity-log.index', ['user_id' => $user->id]),
                'aboutUser' => route('admin.activity-log.index', [
                    'subject_type' => $user->getMorphClass(),
                    'subject_id' => $user->id,
                ]),
            ] : null,
        ]);
    }

    /**
     * Trash — a list of soft-deleted users.
     */
    public function trashed(Request $request, UserSort $sort, PerPage $perPage): Response|RedirectResponse
    {
        $query = User::onlyTrashed()->with(['roles.permissions', 'permissions']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $users = $query
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->orderBy('id')
            ->paginate($perPage->get())
            ->withQueryString()
            ->through(fn (User $user) => $this->withCanManage($request, $user));

        if ($redirect = $this->redirectPastLastPage($users, $request)) {
            return $redirect;
        }

        return Inertia::render('Users/Trashed', [
            'users' => $users,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
            ...$perPage->toArray(),
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * Restore a user from the trash.
     *
     * @param  int  $id  The identifier of the user in the trash
     */
    public function restore(Request $request, int $id): RedirectResponse
    {
        $this->users->restore($id, $request->user());

        return $this->redirectToList('admin.users.trashed')
            ->with('success', 'Пользователь восстановлен');
    }

    /**
     * Permanently delete a user from the trash (along with their files/relations).
     *
     * @param  int  $id  The identifier of the user in the trash
     */
    public function forceDelete(Request $request, int $id): RedirectResponse
    {
        $this->users->forceDelete($id, $request->user());

        return $this->redirectToList('admin.users.trashed')
            ->with('success', 'Пользователь удалён навсегда');
    }

    /**
     * Bulk restore from the trash.
     *
     * @param  BulkUserActionRequest  $request  Selected ids or all users matching the current filter
     */
    public function bulkRestore(BulkUserActionRequest $request): RedirectResponse
    {
        $result = $request->boolean('all')
            ? $this->users->bulkRestoreAll($request->validated('search'), $request->user())
            : $this->users->bulkRestore($request->validated('ids'), $request->user());

        return $this->redirectToList('admin.users.trashed')
            ->with('success', $this->bulkMessage(
                'Восстановлено пользователей',
                $result,
                'нет прав или email занят',
            ));
    }

    /**
     * Bulk permanent deletion from the trash.
     *
     * @param  BulkUserActionRequest  $request  Selected ids or all users matching the current filter
     */
    public function bulkForceDelete(BulkUserActionRequest $request): RedirectResponse
    {
        $result = $request->boolean('all')
            ? $this->users->bulkForceDeleteAll($request->validated('search'), $request->user())
            : $this->users->bulkForceDelete($request->validated('ids'), $request->user());

        return $this->redirectToList('admin.users.trashed')
            ->with('success', $this->bulkMessage('Удалено навсегда', $result, 'нет прав'));
    }

    /**
     * Flash text for a bulk action, mentioning skipped users.
     *
     * @param  array{processed: int, skipped: int}  $result
     */
    private function bulkMessage(string $label, array $result, string $skippedReason): string
    {
        $message = "{$label}: {$result['processed']}";

        return $result['skipped'] > 0
            ? "{$message}. Пропущено: {$result['skipped']} ({$skippedReason})"
            : $message;
    }

    /** The list query with the index filters from the query string. */
    private function filteredQuery(Request $request): Builder
    {
        return $this->users->listQuery(
            $request->string('search')->toString(),
            $request->string('role')->toString(),
            $request->string('status')->toString(),
            $request->boolean('must_change_password'),
        );
    }

    /** Adds the can_manage flag for Vue and drops the permission data it was computed from. */
    private function withCanManage(Request $request, User $user): User
    {
        $user->setAttribute('can_manage', RbacGuard::canManageUser($request->user(), $user));

        return $this->hidePermissions($user);
    }

    /** Keeps the eager-loaded permission relations out of the Inertia payload. */
    private function hidePermissions(User $user): User
    {
        $user->makeHidden('permissions');
        $user->roles->each->makeHidden('permissions');

        return $user;
    }

    /** Neutralizes spreadsheet formulas in exported cells. */
    private function csvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
