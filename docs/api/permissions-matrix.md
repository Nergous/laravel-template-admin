# Матрица разрешений и каталог ошибок

Помаршрутная карта авторизации и возможных ответов об ошибках.

## Двухуровневая модель авторизации

Механизм один (`spatie/laravel-permission`, имена прав `модуль.действие`), но
**точка проверки зависит от типа действия**:

1. **Просмотр раздела** — middleware `permission:*.view` на группе маршрутов.
2. **Удаление** — middleware `permission:*.delete` (для медиа — `media.delete`),
   часто в отдельной группе.
3. **Создание / редактирование** — тонкий гейт в `FormRequest::authorize()` **по
   HTTP-методу**: `POST → *.create`, `PUT/PATCH → *.edit`. Поэтому resource-методы
   `store`/`update` физически висят в группе `*.view`, но реально гейтятся правом
   `*.create` / `*.edit`.
4. **Кросс-сущностный JSON** (`/admin/search`) — инлайн `can()` на каждую сущность
   внутри контроллера (одна ручка отдаёт несколько типов).

**Следствие, которое легко упустить:** роль с `users.view`, но без `users.create`
**пройдёт** middleware маршрута `POST /admin/users` (он под `users.view`) и будет
отклонена уже в `UserRequest::authorize()` → **403**. Это by design.

Дублирование во Vue (`can(perm)`, `resources/js/lib/can.js`) — **только для условного
рендера** (скрыть кнопку). Сервер остаётся источником правды: если право скрыто в UI,
но запрос всё равно отправлен — вернётся 403.

> Политики Laravel (`app/Policies`, `Gate::define`) намеренно не используются —
> для шаблона такого размера достаточно связки middleware + `FormRequest::authorize()`.

## Условные обозначения

- **Право маршрута** — middleware `permission:*` (или `auth` / `guest` / `bot.enabled`).
- **Право действия** — что реально проверяет `FormRequest::authorize()` (если оно
  отличается от права маршрута).
- Все маршруты — под префиксом `/admin`.

---

## Карта маршрут → право

### Auth

| Метод | URI       | Право маршрута            | Право действия | Примечания                            |
| ----- | --------- | ------------------------- | -------------- | ------------------------------------- |
| GET   | `/login`  | `guest`                   | —              | форма входа                           |
| POST  | `/login`  | `guest`, `throttle:login` | —              | `LoginRequest`; неверные данные → 422 |
| POST  | `/logout` | `auth`                    | —              | инвалидирует сессию                   |

### Dashboard / Search / Notifications

| Метод | URI                     | Право маршрута      | Право действия | Примечания                                       |
| ----- | ----------------------- | ------------------- | -------------- | ------------------------------------------------ |
| GET   | `/` (`/admin`)          | `auth`              | —              | дашборд                                          |
| GET   | `/search`               | `auth`              | инлайн `can()` | результаты фильтруются `users.view`/`media.view` |
| GET   | `/notifications/recent` | `activity-log.view` | —              | JSON-фид «колокольчика»                          |

### Users

| Метод     | URI                                                  | Право маршрута | Право действия     | Примечания                                         |
| --------- | ---------------------------------------------------- | -------------- | ------------------ | -------------------------------------------------- |
| GET       | `/users`                                             | `users.view`   | —                  | список                                             |
| POST      | `/users`                                             | `users.view`   | **`users.create`** | `UserRequest`                                      |
| PUT/PATCH | `/users/{user}`                                      | `users.view`   | **`users.edit`**   | `UserRequest`                                      |
| GET       | `/users/create`,`/users/{user}`,`/users/{user}/edit` | `users.view`   | —                  | заглушки-редиректы на index/edit (формы — дроверы) |
| DELETE    | `/users/{user}`                                      | `users.delete` | —                  | нельзя удалить себя → 422                          |
| GET       | `/users/trashed`                                     | `users.delete` | —                  | корзина                                            |
| PATCH     | `/users/restore/{id}`                                | `users.delete` | —                  | 404, если не в корзине                             |
| DELETE    | `/users/force/{id}`                                  | `users.delete` | —                  | 404, если не в корзине                             |
| POST      | `/users/trashed/bulk-restore`                        | `users.delete` | —                  | `BulkUserActionRequest`                            |
| DELETE    | `/users/trashed/bulk-force`                          | `users.delete` | —                  | `BulkUserActionRequest`                            |

### Roles

| Метод     | URI                                             | Право маршрута | Право действия                                       | Примечания                                |
| --------- | ----------------------------------------------- | -------------- | ---------------------------------------------------- | ----------------------------------------- |
| GET       | `/roles`                                        | `roles.view`   | —                                                    | список                                    |
| GET       | `/roles/create`, `/roles/{role}`, `/roles/{role}/edit` | `roles.view`   | —                                             | заглушки-редиректы на index (формы — дровер) |
| POST      | `/roles`                                        | `roles.view`   | **`roles.create`**                                   | `RoleRequest`                             |
| PUT/PATCH | `/roles/{role}`                                 | `roles.view`   | **`roles.edit`** (+ только админ для системной роли) | `RoleRequest`                             |
| DELETE    | `/roles/{role}`                                 | `roles.delete` | —                                                    | нельзя системную / назначенную роль → 422 |

### Permissions

| Метод     | URI                                                                     | Право маршрута       | Право действия           | Примечания                                       |
| --------- | ----------------------------------------------------------------------- | -------------------- | ------------------------ | ------------------------------------------------ |
| GET       | `/permissions`, `/permissions/create`, `/permissions/{permission}/edit` | `permissions.view`   | —                        | матрица / заглушки                               |
| PATCH     | `/permissions/matrix`                                                   | `permissions.edit`   | — (инлайн-валидация)     | тумблер ячейки; роль `admin` заблокирована → 422 |
| POST      | `/permissions`                                                          | `permissions.view`   | **`permissions.create`** | `PermissionRequest`; авто-грант роли `admin`     |
| PUT/PATCH | `/permissions/{permission}`                                             | `permissions.view`   | **`permissions.edit`**   | `PermissionRequest`                              |
| DELETE    | `/permissions/{permission}`                                             | `permissions.delete` | —                        | —                                                |

### Media

| Метод  | URI              | Право маршрута | Право действия        | Примечания                       |
| ------ | ---------------- | -------------- | --------------------- | -------------------------------- |
| GET    | `/media`         | `media.view`   | —                     | список                           |
| GET    | `/media/poll`    | `media.view`   | — (инлайн-валидация)  | JSON-поллинг                     |
| POST   | `/media`         | `media.upload` | (тоже `media.upload`) | `MediaRequest`, JSON, нужен CSRF |
| DELETE | `/media/{media}` | `media.delete` | —                     | —                                |
| DELETE | `/media/bulk`    | `media.delete` | —                     | `BulkDestroyMediaRequest`        |

### Activity Log / Settings

| Метод  | URI             | Право маршрута        | Право действия | Примечания                                             |
| ------ | --------------- | --------------------- | -------------- | ------------------------------------------------------ |
| GET    | `/activity-log` | `activity-log.view`   | —              | журнал                                                 |
| DELETE | `/activity-log` | `activity-log.delete` | —              | очистка журнала до даты `before` (события раньше — удаляются) |
| GET    | `/settings`     | `settings.view`       | —              | —                                                      |
| PUT   | `/settings`     | `settings.edit`     | —              | `UpdateSettingsRequest` (правила из `Setting::SCHEMA`) |

### Bot Messages (модуль под `bot.enabled`)

| Метод  | URI                    | Право маршрута                      | Право действия | Примечания                                         |
| ------ | ---------------------- | ----------------------------------- | -------------- | -------------------------------------------------- |
| GET    | `/bot-messages`        | `bot.enabled` + `bot-messages.view` | —              | —                                                  |
| PUT    | `/bot-messages/{code}` | `bot.enabled` + `bot-messages.edit` | —              | `BotMessageRequest`; `{code}` ∈ реестре, иначе 404 |
| DELETE | `/bot-messages/{code}` | `bot.enabled` + `bot-messages.edit` | —              | сброс к дефолту (имя маршрута `reset`)             |

> При `config('bot.enabled') === false` middleware `EnsureBotEnabled` отдаёт **404**
> на всех трёх (модуль неотличим от несуществующего).

---

## Каталог ошибок

### Коды фреймворка

| Код                            | Когда                                                                                                                                  |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------- |
| **401 / редирект на `/login`** | неаутентифицированный запрос к маршруту под `auth`                                                                                     |
| **403**                        | нет нужного права (middleware маршрута **или** `FormRequest::authorize()`)                                                             |
| **404**                        | модель не найдена (`{user}`/`{role}`/…), пользователь/медиа не в корзине, выключенный модуль бота, неизвестный `{code}` сообщения бота |
| **419**                        | мутация без валидного CSRF-токена (актуально для `POST /media`)                                                                        |
| **422**                        | ошибка валидации Form Request **или** доменная проверка (см. ниже)                                                                     |
| **429**                        | превышен лимит `throttle:login` на `POST /login` (по умолчанию **5/мин на IP**, настройка `security.login_throttle`)                   |

### Доменные ошибки (422, `ValidationException` → redirect back с ошибкой)

Доменные инварианты живут в `App\Services\*` и сигнализируются через
`ValidationException::withMessages([...])` — Laravel превращает их в redirect back с
ошибкой под указанным ключом (тот же UX, что и `back()->withErrors(...)`).

| Маршрут                     | Ключ            | Сообщение                                                                                                                                             |
| --------------------------- | --------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| `POST /login`               | `email`         | «Неверный email или пароль»                                                                                                                           |
| `DELETE /users/{user}`      | `user`          | «Вы не можете удалить самого себя»                                                                                                                    |
| `PUT /users/{user}`         | `roles`         | «Вы не можете убрать у себя роль admin»                                                                                                               |
| `POST`/`PUT /users`         | `roles.*`       | «Системную роль может назначать только администратор» / «Нельзя назначить роль с правами, которых у вас нет: …»                                       |
| `PUT /roles/{role}`         | `name`          | «Имя системной роли нельзя менять»                                                                                                                    |
| `POST`/`PUT /roles`         | `permissions.*` | «Нельзя назначить роли право, которого у вас нет: …» (анти-эскалация, S3)                                                                             |
| `DELETE /roles/{role}`      | `role`          | «Системную роль нельзя удалить» / «Нельзя удалить роль, назначенную пользователям»                                                                    |
| `PATCH /permissions/matrix` | `matrix`        | «Права системной роли „admin“ нельзя изменять» / «Права системной роли может менять только администратор» / «Нельзя выдать право, которого у вас нет» |
