_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4462: аудит-след тихой перезаписи пароля — `User::setPasswordAttribute` логирует writer каждой перезаписи хеша (OxAlpha z-ai/glm-5.3-flash, 09-09-2026)

Инцидент 09-09-2026: smoke-студент (id=6857) — хеш пароля перезаписан неустановленным локальным актором (last_login 127.0.0.1 19:38:39 UTC), в логах ни одной строки (failed_jobs/activity_log/magic-links пусты) — [H4462](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4462-OxAlpha_Systema-Sanscriticum_smoke-user-password-audit-trail_09.09.26.md).

## Что изменилось

- `app/Models/User.php` — новый set-мутатор `setPasswordAttribute`: перехватывает ЛЮБУЮ запись password через Eloquent (fill/update/forceFill/свойство). Set-мутатор в Laravel 10 имеет приоритет над `hashed`-кастом, поэтому мутатор повторяет логику `castAttributeAsHashedString()` (null → null; уже-хеш → как есть; иначе `Hash::make`) — поведение хеширования не меняется.
- Логируется только ПЕРЕзапись у существующего пользователя: был валидный хеш → стал ДРУГОЙ валидный хеш. Создание пользователя, пустое значение и повторная установка того же хеша не логируются (без шума на каждый save()).
- Строка security-лога `password.rewritten` (уровень info, канал `services.password_audit.channel` из `.env` `PASSWORD_AUDIT_LOG_CHANNEL`, default `stack`): `user_id`, `email`, `writer` (`cli:<artisan-команда>` / `http:<ip>`), `auth_id`, `session_id` (12 символов). Ни пароля, ни хеша в лог не попадают; вход помечен `#[\SensitiveParameter]`.
- Аудит-запись обёрнута в try/catch + null-guard: под `Log::spy()` в тестах и при любом сбое канала запись пароля не ломается (найдено full-suite прогоном: `DepositReversalTest` → `Payment.php:1583`).
- `config/services.php` — блок `password_audit.channel`.
- `tests/Feature/PasswordRewriteAuditTrailTest.php` — 6 тестов: перезапись логируется (writer cli:, auth/session null), создание не логируется, plain-пароль хешируется мутатором (Hash::check проходит), уже-хеш хранится как есть, повторная установка того же хеша не логируется, `users:ensure-test-student` при смене пароля даёт строку аудита.

## Не изменилось

- Алгоритм/каст хеширования, все места записи пароля, DEV/prod .env, флаги — не тронуты. Записи через query-builder (`DB::table('users')->update`) минуют Eloquent-мутатор и по-прежнему не логируются (в кодовой базе таких путей к паролям нет).

## Проверка

- `php artisan test tests/Feature/PasswordRewriteAuditTrailTest.php` — 6 passed (10 assertions).
- Полный сьют: `php artisan test --parallel` — 5478 tests / 27668 assertions, 0 errors/failures (PHPUnit deprecations + 6 skipped — фоновый шум репо).
- Pint — passed.
