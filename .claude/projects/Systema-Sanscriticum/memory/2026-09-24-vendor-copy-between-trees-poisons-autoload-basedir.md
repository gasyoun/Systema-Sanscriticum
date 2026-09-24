# Symlink/junction-vendor (не копия!) — корень битого $baseDir в autoload; копия лишь размножает яд, artisan мёртв до dump-autoload

_Created: 24-09-2026 · Last updated: 24-09-2026_

## Корневая причина (measured, 24-09-2026)

`vendor/` в сессионных деревьях Systema — **symlink на `Systema-Sanscriticum/vendor` (main)**,
трюк ради переиспользования vendor без `composer install`. Любой `composer dump-autoload`/`install`
внутри такого дерева пишет **ЧЕРЕЗ symlink** в `main/vendor`, и `$baseDir` в
`vendor/composer/autoload_psr4.php` принимает имя ТОГО дерева, из которого запущен composer
(`$baseDir = dirname(dirname($vendorDir)).'/X'`). Итог: main + все деревья, шарящие `main/vendor`
(4 story-* + слот-дерево), резолвят `App\`/`Tests\` в X. Симптом-профиль:

- `php artisan *` (любая команда) падает `BindingResolutionException: Target class
  [App\Console\Kernel] does not exist`;
- прямой `vendor/bin/phpunit` при этом ЗЕЛЁНЫЙ — `phpunit.xml` грузит
  `tests/bootstrap_worktree.php`, который перебиндивает PSR-4 на текущее дерево в рантайме.

**Диагностика чтением `autoload_psr4.php` ВРАНЬЁ** — после `dump-autoload` строка относительная;
решает только рантайм-резолв класса:
`php -r 'require "vendor/autoload.php"; echo (new ReflectionClass("App\\Console\\Kernel"))->getFileName();'`
должен вернуть ЭТО дерево.

## Dangling-target (родственный класс, H4463)

Ссылка, чья ЦЕЛЬ опустошена/удалена — ровно то, что делает `git worktree remove --force`
(рекурсивно чистит СОДЕРЖИМОЕ цели) — лишает `autoload.php` СРАЗУ всех шареров, пока
`ls vendor` ещё показывает имена; `test -f`/`file_exists` по ссылке читают «нет файла»;
«восстановление отсутствующего vendor» записью через висячую ссылку материализует контент
в ЧУЖОМ дереве.

## Копия — не корень, а вектор размножения

Копирование `vendor/` несёт уже отравленный `autoload_psr4.php` с чужим `$baseDir` в приёмник.
Кейс 24-09-2026: источник — удалённый `Systema-Sanscriticum-h4832-drain`; живая story-сессия
копировала vendor из `h01a08efa` в соседей, 7 из 19 деревьев отравлены одним источником.

## Правило

1. Никогда не делать symlink/junction `vendor` из worktree наружу (ни на main, ни на соседа)
   и никогда не копировать `vendor/` «как есть».
2. Безопасен только ЛОКАЛЬНЫЙ vendor: `composer install` либо `composer dump-autoload` в самом
   дереве; после вынужденной копии — обязателен `dump-autoload` в приёмнике, main — ПОСЛЕДНИМ.
3. Перед `git worktree remove` — `find . -maxdepth 2 -type l` (`dir /AL` на Windows):
   ссылка наружу = сначала снять.
4. Профиль «phpunit зелёный, artisan красный» → первым делом рантайм-резолв класса
   (`ReflectionClass`), не код репо.
5. Живой симптом 24-09-2026 21:02: `Systema-Sanscriticum-h5475-36973/vendor →
   Systema-Sanscriticum/vendor` (residual GTD `0G1`).

## Источник

- H5458 close 24-09-2026 (OxAlpha `zai-coding-plan/glm-5.3-flash via opencode`; Codex verifier PASS):
  12 деревьев с `vendor`, BAD=0; цензус MSI/prod чист (GTD `0FN`).
- [DANGER_FACTS row](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md)
  «symlink/junction-vendor poisons shared $baseDir».
- H4463 — junction-traversal (`worktree remove --force` вычищает содержимое ЦЕЛИ).

_Гасунс_