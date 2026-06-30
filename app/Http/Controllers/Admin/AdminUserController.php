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

/**
 * Управление пользователями: список, CRUD и корзина (мягкое удаление).
 *
 * Контроллер занимается HTTP: валидирует вход (UserRequest), собирает пропсы
 * Inertia и редиректит. Доменные правила и оркестрация (транзакции, syncRoles,
 * запрет само-понижения/само-удаления) живут в App\Services\UserService.
 *
 * Роли назначаются массивом roles[] (имена spatie-ролей). Создание и
 * редактирование живут в дровере на странице Index, поэтому resource-методы
 * create/edit/show лишь редиректят.
 */
class AdminUserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    /**
     * Список пользователей с поиском, фильтром по роли, сортировкой,
     * списком ролей для фильтра и счётчиком корзины.
     */
    public function index(Request $request, UserSort $sort): \Inertia\Response
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
            'roles' => $roles, // ['admin'=>'admin', ...] — для фильтра
            // Полный список ролей с описанием — для карточек-опций в дровере.
            'allRoles' => $allRoles,
            'trashedCount' => $trashedCount,
            ...$sort->toArray(), // currentSort + currentDirection из валидированного Sort
            'filters' => $request->only('search', 'role'),
        ]);
    }

    public function create(): RedirectResponse
    {
        // Создание живёт в дровере на странице Index.
        return redirect()->route('admin.users.index');
    }

    /** Создаёт пользователя и назначает ему роли (roles[]). */
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
        // Редактирование живёт в дровере на странице Index.
        return redirect()->route('admin.users.index');
    }

    /**
     * Обновляет пользователя и его роли. Пароль меняется только если передан;
     * не позволяет администратору снять роль admin с самого себя (правило в сервисе).
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

    /** Мягко удаляет пользователя (себя удалить нельзя — правило в сервисе). */
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
     * Корзина — список мягко удалённых пользователей.
     */
    public function trashed(Request $request, UserSort $sort): \Inertia\Response
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
            ...$sort->toArray(), // currentSort + currentDirection из валидированного Sort
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * Восстановить пользователя из корзины.
     *
     * @param  int  $id  Идентификатор пользователя в корзине
     */
    public function restore(int $id): RedirectResponse
    {
        $this->users->restore($id);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', 'Пользователь восстановлен');
    }

    /**
     * Удалить пользователя из корзины окончательно (вместе с файлами/связями).
     *
     * @param  int  $id  Идентификатор пользователя в корзине
     */
    public function forceDelete(int $id): RedirectResponse
    {
        $this->users->forceDelete($id);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', 'Пользователь удалён навсегда');
    }

    /**
     * Массовое восстановление из корзины.
     *
     * @param  BulkUserActionRequest  $request  ids (int[]) — идентификаторы пользователей
     */
    public function bulkRestore(BulkUserActionRequest $request): RedirectResponse
    {
        $count = $this->users->bulkRestore($request->ids);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', "Восстановлено пользователей: {$count}");
    }

    /**
     * Массовое окончательное удаление из корзины.
     *
     * @param  BulkUserActionRequest  $request  ids (int[]) — идентификаторы пользователей
     */
    public function bulkForceDelete(BulkUserActionRequest $request): RedirectResponse
    {
        $count = $this->users->bulkForceDelete($request->ids);

        return redirect()
            ->route('admin.users.trashed')
            ->with('success', "Удалено навсегда: {$count}");
    }
}
