<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkUserActionRequest;
use App\Http\Requests\UserRequest;
use App\Http\Sorts\UserSort;
use App\Models\Role;
use App\Models\User;
use App\Services\UserService;
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
 * Roles are assigned via a roles[] array (spatie role names). Creation and
 * editing live in a drawer on the Index page, so the resource methods
 * create/edit/show merely redirect.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /**
     * List of users with search, role filter, sorting,
     * a list of roles for the filter, and a trash counter.
     */
    public function index(Request $request, UserSort $sort): Response
    {
        $query = User::query()->with(['roles', 'creator:id,name', 'editor:id,name']);

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $users = $query
            ->filterByRole($request->role)
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->paginate(10)
            ->withQueryString();

        $allRoles = Role::orderBy('name')->get(['name', 'description']);
        $roles = $allRoles->pluck('name', 'name')->toArray();
        $trashedCount = User::onlyTrashed()->count();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles, // ['admin'=>'admin', ...] — for the filter
            // Full list of roles with descriptions — for the option cards in the drawer.
            'allRoles' => $allRoles,
            'trashedCount' => $trashedCount,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
            'filters' => $request->only('search', 'role'),
        ]);
    }

    public function create(): RedirectResponse
    {
        // Creation lives in a drawer on the Index page.
        return redirect()->route('admin.users.index');
    }

    /** Creates a user and assigns roles to them (roles[]). */
    public function store(UserRequest $request): RedirectResponse
    {
        $this->users->create(
            $request->only('name', 'email', 'password'),
            $request->input('roles', []),
        );

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Пользователь успешно создан');
    }

    public function edit(User $user): RedirectResponse
    {
        // Editing lives in a drawer on the Index page.
        return redirect()->route('admin.users.index');
    }

    /**
     * Updates a user and their roles. The password is changed only if provided;
     * does not allow an admin to remove the admin role from themselves (rule in the service).
     */
    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $this->users->update(
            $user,
            [
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->input('password'),
            ],
            $request->input('roles', []),
            $request->user(),
        );

        return redirect()
            ->route('admin.users.index')
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

    public function show(User $user): RedirectResponse
    {
        return redirect()->route('admin.users.edit', $user);
    }

    /**
     * Trash — a list of soft-deleted users.
     */
    public function trashed(Request $request, UserSort $sort): Response
    {
        $query = User::onlyTrashed()->with('roles');

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $users = $query
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Users/Trashed', [
            'users' => $users,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * Restore a user from the trash.
     *
     * @param  int  $id  The identifier of the user in the trash
     */
    public function restore(int $id): RedirectResponse
    {
        $this->users->restore($id);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', 'Пользователь восстановлен');
    }

    /**
     * Permanently delete a user from the trash (along with their files/relations).
     *
     * @param  int  $id  The identifier of the user in the trash
     */
    public function forceDelete(int $id): RedirectResponse
    {
        $this->users->forceDelete($id);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', 'Пользователь удалён навсегда');
    }

    /**
     * Bulk restore from the trash.
     *
     * @param  BulkUserActionRequest  $request  ids (int[]) — user identifiers
     */
    public function bulkRestore(BulkUserActionRequest $request): RedirectResponse
    {
        $count = $this->users->bulkRestore($request->ids);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', "Восстановлено пользователей: {$count}");
    }

    /**
     * Bulk permanent deletion from the trash.
     *
     * @param  BulkUserActionRequest  $request  ids (int[]) — user identifiers
     */
    public function bulkForceDelete(BulkUserActionRequest $request): RedirectResponse
    {
        $count = $this->users->bulkForceDelete($request->ids);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', "Удалено навсегда: {$count}");
    }
}
