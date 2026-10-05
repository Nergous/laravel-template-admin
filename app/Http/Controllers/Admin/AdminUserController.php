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
use App\Support\FilterValues;
use App\Support\Impersonation;
use App\Support\RbacGuard;
use App\Support\TableExport;
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
            'exportColumns' => TableExport::options($this->exportColumns()),
        ]);
    }

    public function create(): Response
    {
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
        // users.edit is checked by the route; the target must not be above the actor.
        abort_unless(RbacGuard::canManageUser($request->user(), $user), 403);

        return Inertia::render('Users/FormPage', [
            'mode' => 'edit',
            'user' => $user->load(['roles:id,name', 'creator:id,name', 'editor:id,name']),
            'allRoles' => Role::orderBy('name')->get(['id', 'name', 'description']),
            'isSelf' => $request->user()->is($user),
        ]);
    }

    /**
     * Updates a user and their roles. The password is changed only if provided;
     * roles are synced only when roles[] is sent (a client that omits the key
     * keeps them); an admin cannot remove the admin role from themselves (rule in the service).
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
            $request->exists('roles') ? ($request->input('roles') ?? []) : null,
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
            ->with('success', $this->bulkSummary('Перемещено в корзину', $result['processed'], $result['skipped'], 'нет прав или собственная учётная запись'));
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
            ->with('success', $this->bulkSummary(
                $active ? 'Разблокировано' : 'Заблокировано',
                $result['processed'],
                $result['skipped'],
                'нет прав или собственная учётная запись',
            ));
    }

    /**
     * Streams the filtered and sorted user list as CSV or XLSX with the chosen
     * columns (see TableExport). lazy() keeps the roles eager load per chunk.
     */
    public function export(Request $request, UserSort $sort): StreamedResponse
    {
        $timezone = SettingsServiceProvider::displayTimezone();
        $query = $this->filteredQuery($request)
            ->with('roles')
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->orderBy('id');

        return TableExport::fromRequest($request, $this->exportColumns())->download(
            $query->lazy(500),
            'users-'.now($timezone)->format('Y-m-d_His'),
            'Пользователи',
            fn (int $count) => ActivityLog::record(null, 'users_exported', ['rows' => [null, $count]]),
        );
    }

    /**
     * Columns of the user export, in file order.
     *
     * @return array<string, array{0: string, 1: \Closure(User): (string|int|null)}>
     */
    private function exportColumns(): array
    {
        $timezone = SettingsServiceProvider::displayTimezone();
        $date = fn ($value) => $value?->timezone($timezone)->format('Y-m-d H:i:s');

        return [
            'id' => ['ID', fn (User $user) => $user->id],
            'name' => ['Имя', fn (User $user) => $user->name],
            'email' => ['Email', fn (User $user) => $user->email],
            'roles' => ['Роли', fn (User $user) => $user->roles->pluck('name')->implode(', ')],
            'status' => ['Статус', fn (User $user) => $user->is_active ? 'Активен' : 'Заблокирован'],
            'must_change_password' => ['Смена пароля', fn (User $user) => $user->must_change_password ? 'Да' : 'Нет'],
            'last_login_at' => ['Последний вход', fn (User $user) => $date($user->last_login_at)],
            'created_at' => ['Создан', fn (User $user) => $date($user->created_at)],
        ];
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
            ->with('success', $this->bulkSummary('Восстановлено пользователей', $result['processed'], $result['skipped'], 'нет прав или email занят'));
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
            ->with('success', $this->bulkSummary('Удалено навсегда', $result['processed'], $result['skipped'], 'нет прав'));
    }

    /** The list query with the index filters from the query string. */
    private function filteredQuery(Request $request): Builder
    {
        return $this->users->listQuery(
            $request->string('search')->toString(),
            FilterValues::strings($request->input('role')),
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
}
