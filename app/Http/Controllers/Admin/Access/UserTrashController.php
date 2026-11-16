<?php

namespace App\Http\Controllers\Admin\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\BulkUserActionRequest;
use App\Http\Requests\Access\UserTrashFilterRequest;
use App\Http\Resources\Access\UserResource;
use App\Models\User;
use App\Services\Access\UserTrashService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * User trash: soft-deleted accounts, restoring and permanent deletion. Access
 * is defined by the routes (users.delete, UserPolicy via "can:restore,user" /
 * "can:forceDelete,user"); the rules (e.g. a taken email) live in
 * UserTrashService.
 */
class UserTrashController extends Controller
{
    public function __construct(private readonly UserTrashService $trash) {}

    /** Soft-deleted users with search, sorting and page size. */
    public function index(UserTrashFilterRequest $request): Response|RedirectResponse
    {
        $users = $this->trash->listQuery($request->search())
            ->with(['roles.permissions', 'permissions'])
            ->orderBy($request->sortColumn(), $request->sortDirection())
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString()
            ->through(fn (User $user) => (new UserResource($user))->resolve($request));

        if ($redirect = $this->redirectPastLastPage($users, $request)) {
            return $redirect;
        }

        return Inertia::render('Users/Trashed', [
            'users' => $users,
            ...$request->sortProps(),
            ...$request->perPageProps(),
            'filters' => ['search' => $request->search()],
        ]);
    }

    /** Restores a user from the trash; an active account is not in the trash (404). */
    public function restore(User $user): RedirectResponse
    {
        abort_unless($user->trashed(), 404);

        $this->trash->restore($user);

        return $this->redirectToList('admin.users.trashed')
            ->with('success', 'Пользователь восстановлен');
    }

    /** Permanently deletes a user from the trash; an active account is not in the trash (404). */
    public function forceDelete(User $user): RedirectResponse
    {
        abort_unless($user->trashed(), 404);

        $this->trash->forceDelete($user);

        return $this->redirectToList('admin.users.trashed')
            ->with('success', 'Пользователь удалён навсегда');
    }

    /** Restores the selected users or all deleted users matching the search. */
    public function bulkRestore(BulkUserActionRequest $request): RedirectResponse
    {
        $result = $this->trash->bulkRestore($request->selection(), $request->user());

        return $this->redirectToList('admin.users.trashed')
            ->with('success', $this->bulkSummary('Восстановлено пользователей', $result['processed'], $result['skipped'], 'нет прав или email занят'));
    }

    /** Permanently deletes the selected users or all deleted users matching the search. */
    public function bulkForceDelete(BulkUserActionRequest $request): RedirectResponse
    {
        $result = $this->trash->bulkForceDelete($request->selection(), $request->user());

        return $this->redirectToList('admin.users.trashed')
            ->with('success', $this->bulkSummary('Удалено навсегда', $result['processed'], $result['skipped'], 'нет прав'));
    }
}
