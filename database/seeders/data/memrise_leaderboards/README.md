_Created: 01-08-2026 · Last updated: 05-09-2026_

# Memrise leaderboard CSV import (H2054)

Перенос очков с лидербордов community-курсов Memrise в Systema.

## Формат CSV

Заголовки (регистр не важен; UTF-8, без BOM предпочтительно):

| column | required | notes |
|---|---|---|
| `course_id` | yes | e.g. `6679375` |
| `username` | yes | Memrise display username |
| `points` | yes | integer (commas stripped) |
| `period` | no | default `all` (`week` / `month` if you snapshot those) |
| `rank` | no | position on Memrise |

Example: [`example.csv`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/data/memrise_leaderboards/example.csv)

## Import

```bash
php artisan leaderboard:import-memrise database/seeders/data/memrise_leaderboards/example.csv
php artisan leaderboard:import-memrise path/to/export.csv --link
php artisan leaderboard:import-memrise --link-only
php artisan leaderboard:import-memrise path/to/export.csv --dry-run
```

`--link` / `--link-only` sets `memrise_leaderboard_imports.user_id` where
`users.memrise_username` matches (case-insensitive).

## Claim points for a student

```php
// tinker / admin
$user->update(['memrise_username' => 'TheirMemriseNick']);
// then:
// php artisan leaderboard:import-memrise --link-only
```

Boards live in [`config/leaderboards.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/leaderboards.php) — flip `enabled => false` to hide a top.

_Dr. Mārcis Gasūns_
