# Changelog

Все значимые изменения проекта документируются в этом файле.

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
проект придерживается [семантического версионирования](https://semver.org/lang/ru/).

## [1.1.0] — 2026-06-30

### Добавлено

- **Роли в дровере.** Создание и редактирование ролей открываются правым дровером
  прямо на странице списка (`/admin/roles`) — как у пользователей, — а не отдельными
  страницами. Матрица прав в дровере схлопывается в одну колонку. Resource-методы
  `create()`/`edit()` контроллера теперь редиректят на `index`.
- **Очистка журнала действий.** На странице «Журнал действий» появилась кнопка
  «Очистить журнал»: модалка с выбором даты удаляет все события раньше выбранного
  дня одним массовым `DELETE`. Действие закрыто отдельным правом
  `activity-log.delete` и сопровождается флешем с числом удалённых записей
  (`DELETE /admin/activity-log`, `admin.activity-log.clear`).
- **`npm run ds:pull`** — обновление вендоренного снапшота дизайн-системы
  nergous-cit из канонического репозитория [`Nergous/nergous-cit`](https://github.com/Nergous/nergous-cit).

### Изменено

- **⚠️ Требуется PHP 8.4.** Минимальная версия PHP поднята с `^8.2` до `^8.4`
  (`composer.json`, `composer.lock`, `README.md`); матрица CI сведена к одной
  версии — 8.4. Установки на PHP 8.2/8.3 больше не поддерживаются.
- Документация API (`docs/api/permissions-matrix.md`, `docs/api/routes.snapshot.txt`)
  отражает право `activity-log.delete` и маршрут очистки; resource-роуты
  `roles.create`/`roles.edit`/`roles.show` помечены как заглушки-редиректы на
  `index` (формы — в дровере).

### Исправлено

- **Модуль max-bot**: базовый URL MAX API переключён на
  `https://platform-api2.max.ru`; `API_BASE_URL` добавлен в `.env.example`.

### Удалено

- Отдельные страницы `resources/js/admin/pages/Roles/Create.vue` и `Edit.vue`
  (заменены дровером).
- Рантайм-файлы сессий больше не отслеживаются гитом — добавлен
  `storage/framework/sessions/.gitignore` (ошибочно закоммичены в 1.0.0).

## [1.0.0] — 2026-06-30

Первый релиз шаблона админ-панели Laravel: Inertia + Vue 3 SPA, дизайн-система
nergous-cit, RBAC (spatie/laravel-permission), медиатека с асинхронной обработкой,
журнал действий, настройки и опциональный модуль бота.

[1.1.0]: https://github.com/Nergous/laravel-template-admin/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Nergous/laravel-template-admin/releases/tag/v1.0.0
