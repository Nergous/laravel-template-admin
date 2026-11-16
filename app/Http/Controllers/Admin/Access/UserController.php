<?php

namespace App\Http\Controllers\Admin\Access;

use App\Exports\UserExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\BulkUserActionRequest;
use App\Http\Requests\Access\BulkUserStatusRequest;
use App\Http\Requests\Access\DestroyUserRequest;
use App\Http\Requests\Access\ExportUsersRequest;
use App\Http\Requests\Access\StoreUserRequest;
use App\Http\Requests\Access\UpdateUserRequest;
use App\Http\Requests\Access\UserFilterRequest;
use App\Http\Resources\Access\UserDetailsResource;
use App\Http\Resources\Access\UserResource;
use App\Http\Resources\System\ActivityLogResource;
use App\Http\Responses\TableDownload;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\UserService;
use App\Services\System\ActivityLogLabels;
use App\Services\System\ActivityLogService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User management: list, CRUD, bulk actions and export. The trash has its own
 * controller (UserTrashController).
 *
 * The controller handles HTTP: it takes validated input from form requests,
 * builds the Inertia props and redirects. Access is defined by the routes
 * (users.* permissions, UserPolicy via "can:update,user") and DestroyUserRequest;
 * domain rules (transactions, syncRoles, no demoting or deleting oneself) live
 * in App\Services\Access\UserService, export in App\Exports\UserExport.
 */
class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /**
     * User list with search, role and status filters, sorting, the role list
     * for the filter and the trash counter.
     */
    public function index(UserFilterRequest $request, UserExport $export): Response|RedirectResponse
    {
        $users = $this->users->listQuery($request->filters())
            ->with(['roles.permissions', 'permissions'])
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (User $user) => (new UserResource($user))->resolve($request));

        if ($redirect = $this->redirectPastLastPage($users, $request)) {
            return $redirect;
        }

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => Role::orderBy('name')->pluck('name', 'name')->toArray(), // ['admin'=>'admin', ...] — for the filter
            'withoutRolesValue' => User::WITHOUT_ROLES,
            'trashedCount' => User::onlyTrashed()->count(),
            ...$request->sortProps(),
            ...$request->perPageProps(),
            'filters' => $request->only('search', 'role', 'status'),
            'exportColumns' => $export->options(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Users/FormPage', [
            'mode' => 'create',
            'allRoles' => $this->roleOptions(),
        ]);
    }

    /** Creates a user and assigns roles (roles[]). */
    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = $this->users->create($request->userData(), $request->roleNames());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Пользователь успешно создан');
    }

    public function edit(Request $request, User $user): Response
    {
        return Inertia::render('Users/FormPage', [
            'mode' => 'edit',
            'user' => (new UserDetailsResource($user->load(['roles:id,name,description', 'creator:id,name', 'editor:id,name'])))->resolve($request),
            'allRoles' => $this->roleOptions(),
            'isSelf' => $request->user()->is($user),
        ]);
    }

    /**
     * Updates the user and their roles. The password changes only when given;
     * roles are synced only when roles[] is passed (UpdateUserRequest::roleNames());
     * an administrator cannot remove the admin role from themselves (a service rule).
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->users->update($user, $request->userData(), $request->roleNames(), $request->user());

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', 'Пользователь успешно обновлён');
    }

    /** Moves a user to the trash or deletes permanently (one cannot delete oneself — a service rule). */
    public function destroy(DestroyUserRequest $request, User $user): RedirectResponse
    {
        $request->force()
            ? $this->users->forceDelete($user, $request->user())
            : $this->users->delete($user, $request->user());

        return $this->redirectToList('admin.users.index')
            ->with('success', $request->force() ? 'Пользователь удалён навсегда' : 'Пользователь удалён');
    }

    /** Deletes the selected users or all users matching the current filters. */
    public function bulkDestroy(BulkUserActionRequest $request): RedirectResponse
    {
        $force = $request->boolean('force');
        $result = $force
            ? $this->users->bulkForceDelete($request->selection(), $request->user())
            : $this->users->bulkDelete($request->selection(), $request->user());

        return $this->redirectToList('admin.users.index')
            ->with('success', $this->bulkSummary(
                $force ? 'Удалено навсегда' : 'Перемещено в корзину',
                $result['processed'],
                $result['skipped'],
                'нет прав или собственная учётная запись',
            ));
    }

    /** Blocks or unblocks the selected users or all users matching the current filters. */
    public function bulkStatus(BulkUserStatusRequest $request): RedirectResponse
    {
        $active = $request->boolean('active');
        $result = $this->users->bulkSetActive($request->selection(), $active, $request->user(), $request->validated('reason'));

        return $this->redirectToList('admin.users.index')
            ->with('success', $this->bulkSummary(
                $active ? 'Разблокировано' : 'Заблокировано',
                $result['processed'],
                $result['skipped'],
                'нет прав или собственная учётная запись',
            ));
    }

    /** Streams the filtered and sorted user list as CSV or XLSX (UserExport). */
    public function export(ExportUsersRequest $request, UserExport $export): TableDownload
    {
        $query = $this->users->listQuery($request->filters())
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id');

        return $export->download($query, $request->exportFormat(), $request->exportColumns(), $request->user());
    }

    /**
     * User page. With the activity-log.view permission it also shows the latest
     * entries where the user is the author or the subject.
     */
    public function show(Request $request, User $user, ActivityLogService $log, ActivityLogLabels $labels): Response
    {
        $user->load(['roles.permissions', 'permissions', 'creator:id,name', 'editor:id,name']);
        $canViewActivity = $request->user()?->can('activity-log.view') === true;
        $activity = $canViewActivity
            ? $log->latestInvolving($user, 15)->map(fn (ActivityLog $entry) => (new ActivityLogResource($entry, $labels))->resolve($request))
            : [];

        return Inertia::render('Users/Show', [
            'user' => (new UserDetailsResource($user))->resolve($request),
            'isSelf' => $request->user()->is($user),
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

    /** @return Collection<int, Role> Roles selectable in the user form. */
    private function roleOptions(): Collection
    {
        return Role::orderBy('name')->get(['id', 'name', 'description']);
    }
}
