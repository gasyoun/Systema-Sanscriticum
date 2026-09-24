# Vendor, скопированный между деревьями: битый $baseDir в autoload — artisan мёртв до dump-autoload

_Created: 24-09-2026 · Last updated: 24-09-2026_

## Факт (measured, 24-09-2026)

`vendor/` был продамплен composer'ом в дереве X, а затем СКОПИРОВАН в sibling-дерево Y:
сгенерированный `vendor/composer/autoload_psr4.php` содержит
`$baseDir = dirname(dirname($vendorDir)).'/X'` — резолв всех `App\`/`Tests\` классов уходит
в чужой (или уже удалённый) корень. Симптом-профиль:

- `php artisan *` (любая команда) падает `BindingResolutionException: Target class
  [App\Console\Kernel] does not exist`;
- прямой `vendor/bin/phpunit` при этом ЗЕЛЁНЫЙ — `phpunit.xml` грузит
  `tests/bootstrap_worktree.php`, который перебиндивает PSR-4 на текущее дерево в рантайме.

Кейс 24-09-2026: vendor из удалённого `Systema-Sanscriticum-h4832-drain` был разкопирован
в main-клон + 6 сессионных деревьев (`story-link`, `story-link-layout`, `story-series`,
`story-visible-link`, `h01a08efa`, `pilot-attribution-01a0bab7`); в тот же день живая сессия
story-серии копировала vendor из `h01a08efa` в соседей — вектор размножения живой.
Итого 7 из 19 Systema-деревьев с vendor были отравлены одним источником.

Лечение: `composer dump-autoload` на месте в каждом дереве (~10 с, без сети;
vendor gitignored — это ремонт окружения, не коммит). Диагностика:
`rg "baseDir = " vendor/composer/autoload_psr4.php` — если справа стоит ИМЯ ЧУЖОГО дерева,
vendor чужой.

## Правило

1. Никогда не копировать `vendor/` между деревьями «как есть»: после копии обязателен
   `composer dump-autoload` в дереве-приёмнике (или полноценный `composer install`).
2. Профиль «phpunit зелёный, artisan красный» = первым делом смотреть `baseDir`,
   а не код репо.
3. Родственный факт (H4463, junction-vendor): и symlink-vendor, и копия-vendor — оба
   способа шарить vendor между деревьями стреляют; безопасен только локальный
   `dump-autoload`/`install` в каждом дереве.

## Источник

- Сессия opencode/OxAlpha 24-09-2026 (GLM 5.3 flash): 7 деревьев отравлены, все
  починены `dump-autoload`; проверено `php artisan --version` (Laravel 13.31.0) +
  зелёный прогон `TelegramBusinessLaneTest` обеими дорожками (11 tests, 34 assertions).

_Гасунс_
