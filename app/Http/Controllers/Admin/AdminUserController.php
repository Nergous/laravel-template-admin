<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Pagination\PerPage;
use App\Http\Requests\BulkUserActionRequest;
use App\Http\Requests\UserRequest;
use App\Http\Sorts\UserSort;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
use App\Support\RbacGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
     * List of users with search, role filter, sorting,
     * a list of roles for the filter, and a trash counter.
     */
    public function index(Request $request, UserSort $sort, PerPage $perPage): Response
    {
        $query = User::query()->with(['roles', 'creator:id,name', 'editor:id,name']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $users = $query
            ->filterByRole($request->role)
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->paginate($perPage->get())
            ->withQueryString()
            ->through(fn (User $user) => $user->setAttribute(
                'can_manage',
                RbacGuard::canManageUser($request->user(), $user),
            ));

        $roles = Role::orderBy('name')->pluck('name', 'name')->toArray();
        $trashedCount = User::onlyTrashed()->count();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles, // ['admin'=>'admin', ...] — for the filter
            'withoutRolesValue' => User::WITHOUT_ROLES,
            'trashedCount' => $trashedCount,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
            ...$perPage->toArray(), // perPage + perPageOptions for the page-size selector
            'filters' => $request->only('search', 'role'),
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

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Пользователь удалён');
    }

    /** Moves selected users, or every user matching current filters, to trash. */
    public function bulkDestroy(BulkUserActionRequest $request): RedirectResponse
    {
        $result = $request->boolean('all')
            ? $this->users->bulkDeleteAll(
                $request->validated('search'),
                $request->validated('role'),
                $request->user(),
            )
            : $this->users->bulkDelete($request->validated('ids'), $request->user());

        return redirect()
            ->route('admin.users.index')
            ->with('success', $this->bulkMessage('Перемещено в корзину', $result));
    }

    public function show(Request $request, User $user): Response
    {
        return Inertia::render('Users/Show', [
            'user' => $user->load(['roles:id,name', 'creator:id,name', 'editor:id,name']),
            'canManage' => RbacGuard::canManageUser($request->user(), $user),
            'isSelf' => $request->user()->is($user),
        ]);
    }

    /**
     * Trash — a list of soft-deleted users.
     */
    public function trashed(Request $request, UserSort $sort, PerPage $perPage): Response
    {
        $query = User::onlyTrashed()->with('roles');

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $users = $query
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->paginate($perPage->get())
            ->withQueryString();

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

        return redirect()
            ->route('admin.users.trashed')
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

        return redirect()
            ->route('admin.users.trashed')
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

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', $this->bulkMessage('Восстановлено пользователей', $result));
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

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', $this->bulkMessage('Удалено навсегда', $result));
    }

    /**
     * Flash text for a bulk action, mentioning skipped users.
     *
     * @param  array{processed: int, skipped: int}  $result
     */
    private function bulkMessage(string $label, array $result): string
    {
        $message = "{$label}: {$result['processed']}";

        return $result['skipped'] > 0
            ? "{$message}. Пропущено: {$result['skipped']} (нет прав или email занят)"
            : $message;
    }
}
