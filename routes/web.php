<?php

use App\Http\Controllers\Admin\AdminActivityLogController;
use App\Http\Controllers\Admin\AdminBotMessageController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminMediaController;
use App\Http\Controllers\Admin\AdminPermissionController;
use App\Http\Controllers\Admin\AdminRoleController;
use App\Http\Controllers\Admin\AdminSearchController;
use App\Http\Controllers\Admin\AdminSettingsController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::prefix('/admin')->group(function () {

    Route::middleware('guest')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])
            ->name('login');

        Route::post('/login', [LoginController::class, 'login'])
            ->middleware('throttle:login') // лимит из настройки security.login_throttle
            ->name('admin.login');
    });

    Route::middleware('auth')->group(function () {

        Route::get('/', [AdminDashboardController::class, 'index'])
            ->name('admin.dashboard');

        Route::post('/logout', [LoginController::class, 'logout'])
            ->name('logout');

        // Глобальный поиск (Cmd+K)
        Route::get('search', [AdminSearchController::class, 'index'])
            ->name('admin.search');

        // Уведомления — последние действия за 24 часа (фид журнала для колокольчика).
        Route::middleware('permission:activity-log.view')
            ->get('notifications/recent', [AdminActivityLogController::class, 'recent'])
            ->name('admin.notifications.recent');

        // Удаление пользователей
        Route::middleware('permission:users.delete')->group(function () {
            Route::get('users/trashed', [AdminUserController::class, 'trashed'])
                ->name('admin.users.trashed');

            Route::post('users/trashed/bulk-restore', [AdminUserController::class, 'bulkRestore'])
                ->name('admin.users.bulk-restore');

            Route::delete('users/trashed/bulk-force', [AdminUserController::class, 'bulkForceDelete'])
                ->name('admin.users.bulk-force-delete');

            Route::patch('users/restore/{id}', [AdminUserController::class, 'restore'])
                ->name('admin.users.restore');

            Route::delete('users/force/{id}', [AdminUserController::class, 'forceDelete'])
                ->name('admin.users.force-delete');

            Route::delete('users/{user}', [AdminUserController::class, 'destroy'])
                ->name('admin.users.destroy');
        });

        // Просмотр/создание/редактирование пользователей.
        Route::middleware('permission:users.view')->group(function () {
            Route::name('admin')->resource('users', AdminUserController::class)->except('destroy');
        });

        // Роли
        Route::middleware('permission:roles.view')->group(function () {
            Route::name('admin')->resource('roles', AdminRoleController::class)->except('destroy');
        });

        Route::middleware('permission:roles.delete')->group(function () {
            Route::name('admin')->resource('roles', AdminRoleController::class)->only('destroy');
        });

        // Разрешения. Матрица доступа (переключение прав у ролей)
        Route::middleware('permission:permissions.edit')->group(function () {
            Route::patch('permissions/matrix', [AdminPermissionController::class, 'sync'])
                ->name('admin.permissions.sync');
        });

        // Разрешения
        Route::middleware('permission:permissions.view')->group(function () {
            Route::name('admin')->resource('permissions', AdminPermissionController::class)->except('destroy');
        });
        Route::middleware('permission:permissions.delete')->group(function () {
            Route::name('admin')->resource('permissions', AdminPermissionController::class)->only('destroy');
        });

        // Медиа-библиотека: просмотр под media.view, загрузка под media.upload,
        // удаление (одиночное и массовое) под media.delete.
        Route::middleware('permission:media.view')->group(function () {
            Route::get('media/poll', [AdminMediaController::class, 'poll'])
                ->name('admin.media.poll');

            Route::get('media', [AdminMediaController::class, 'index'])
                ->name('admin.media.index');
        });

        Route::middleware('permission:media.upload')->group(function () {
            Route::post('media', [AdminMediaController::class, 'store'])
                ->name('admin.media.store');
        });
        Route::middleware('permission:media.delete')->group(function () {
            Route::delete('media/bulk', [AdminMediaController::class, 'bulkDestroy'])
                ->name('admin.media.bulk-destroy');

            Route::delete('media/{media}', [AdminMediaController::class, 'destroy'])
                ->name('admin.media.destroy');
        });

        // Журнал действий
        Route::middleware('permission:activity-log.view')->group(function () {
            Route::get('activity-log', [AdminActivityLogController::class, 'index'])
                ->name('admin.activity-log.index');
        });

        // Очистка журнала до выбранной даты — под отдельным правом.
        Route::middleware('permission:activity-log.delete')->group(function () {
            Route::delete('activity-log', [AdminActivityLogController::class, 'clear'])
                ->name('admin.activity-log.clear');
        });

        // Настройки
        Route::middleware('permission:settings.view')->group(function () {
            Route::get('settings', [AdminSettingsController::class, 'index'])
                ->name('admin.settings.index');
        });

        Route::middleware('permission:settings.edit')->group(function () {
            Route::put('settings', [AdminSettingsController::class, 'update'])
                ->name('admin.settings.update');
        });

        // Сообщения бота (опциональный модуль; роуты закрыты middleware bot.enabled).
        Route::middleware('bot.enabled')->group(function () {
            Route::middleware('permission:bot-messages.view')
                ->get('bot-messages', [AdminBotMessageController::class, 'index'])
                ->name('admin.bot-messages.index');

            Route::middleware('permission:bot-messages.edit')->group(function () {
                Route::put('bot-messages/{code}', [AdminBotMessageController::class, 'update'])
                    ->where('code', '[a-z0-9_]+')
                    ->name('admin.bot-messages.update');

                Route::delete('bot-messages/{code}', [AdminBotMessageController::class, 'destroy'])
                    ->where('code', '[a-z0-9_]+')
                    ->name('admin.bot-messages.reset');
            });
        });
    });
});
