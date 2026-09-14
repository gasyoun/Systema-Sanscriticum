# Super-admin is a user row, not “the other mailbox”

_Created: 21-08-2026 · Last updated: 21-08-2026_

Staff `role` and the student-cabinet badge are different columns. Logging into [https://samskrte.ru/dvaram](https://samskrte.ru/dvaram) as `gasyoun@ya.ru` and seeing «студент» does **not** mean this mailbox lacks `super_admin`.

Verified on prod 21-08-2026 (`laravel.users`, Grok 4.6 `grok-4.6`).

## What the UI is showing

| Surface | URL | What it is |
|---|---|---|
| Student cabinet | [https://samskrte.ru/dvaram](https://samskrte.ru/dvaram) | Learner home (`student.dashboard`). Admins can open it; the chrome stays student. |
| Staff panel | [https://samskrte.ru/admin](https://samskrte.ru/admin) | Filament. Gated by `User::isAdminLike()` (`role` in `super_admin` / `admin`), not by `global_status`. |

Login (`AuthController::login`) sends `is_admin` users to `/admin` unless `intended()` is already `/dvaram`. Opening `/dvaram` after that still shows the student cabinet.

## Two columns

| Column | Meaning | Default / seed |
|---|---|---|
| `users.role` | Staff role. `null` = ordinary student. `super_admin` is the owner role (`User::isSuperAdmin()`). | Seeded historically onto `pe4kinsmart@gmail.com` in [database/migrations/2026_05_02_140000_add_roles_to_users.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_05_02_140000_add_roles_to_users.php). |
| `users.is_admin` | Legacy boolean, synced from `role` on save when `role` is dirty (`User::booted`). | `true` for `super_admin` and `admin`. |
| `users.global_status` | CRM label on the student card (VIP / бартер / «Обычный студент»). | Default «Обычный студент» from [database/migrations/2026_03_29_144842_update_tables_for_excel_import.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_03_29_144842_update_tables_for_excel_import.php). Filament: [UserResource](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Resources/UserResource.php) «Глобальный статус». |

`global_status` is **not** the staff role. Both live super-admins still have `global_status = «Обычный студент»`.

## Prod rows (21-08-2026)

| id | email | `role` | `is_admin` | `global_status` |
|---|---|---|---|---|
| 6755 | `gasyoun@ya.ru` | `super_admin` | 1 | Обычный студент |
| 937 | `pe4kinsmart@gmail.com` | `super_admin` | 1 | Обычный студент |

`gasyoun@gmail.com` has **no** `users` row (PayPal / contact mailbox only).

`pe4kin.85@mail.ru` (id 6854) exists with `role = null`, `is_admin = 0`. That string is the **code default** for `config('services.admin.email')` in [config/services.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/services.php) (`env('ADMIN_EMAIL', 'pe4kin.85@mail.ru')`). The April 2026 migration [promote_admin_email_to_is_admin](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/migrations/2026_04_25_120000_promote_admin_email_to_is_admin.php) also hard-coded `pe4kinsmart@gmail.com`. Live super-admin is **not** “whatever `ADMIN_EMAIL` is today”; it is the `role` column.

## How to check

```text
cd /var/www/html && php artisan tinker --execute="echo json_encode(DB::table(\"users\")->select(\"id\",\"email\",\"role\",\"is_admin\",\"global_status\")->where(\"role\",\"super_admin\")->get());"
```

Do not grant rights by editing `.env` `ADMIN_EMAIL`. Set `users.role = super_admin` on the intended row (Filament user form, or a one-shot artisan update).

## Code pointers

- [app/Support/Roles.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/Roles.php) — `SUPER_ADMIN`, `adminLike()`
- [app/Models/User.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/User.php) — `isSuperAdmin()`, `isAdminLike()`, `canAccessPanel()`
- [app/Http/Controllers/AuthController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/AuthController.php) — `/admin` vs `student.dashboard`

_Dr. Mārcis Gasūns_
