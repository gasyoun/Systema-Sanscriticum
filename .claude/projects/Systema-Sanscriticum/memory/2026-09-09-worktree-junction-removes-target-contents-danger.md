# Junction/symlink ВНУТРИ worktree: `git worktree remove --force` вычищает СОДЕРЖИМОЕ цели

_Created: 09-09-2026 · Last updated: 09-09-2026_

## Факт (measured, 09-09-2026, H4463)

Джанкшен/симлинк, созданный ВНУТРИ git-worktree на цель ВНЕ worktree, при
`git worktree remove --force` **обходится рекурсивно — удаление идёт в содержимое
ЦЕЛИ**, а не по ссылке. Реальный кейс 09-09-2026: в worktree
`Systema-Sanscriticum-h4463-tour` был создан junction
`worktree/vendor -> <main-tree>/vendor` (трюк ради переиспользования vendor без
повторного `composer install` для прогона тестов). `git worktree remove --force`
после мержа вычистил `vendor/` **главного дерева** (0 записей; `autoload.php`
исчез), хотя сам main tree никто не удалял. Прод не задет (свой vendor);
локальный main tree восстановлен `composer install` (~2 мин, детерминировано из
composer.lock).

## Правило

1. **Никогда не создавать junction/symlink/переопределение путей ВНУТРИ worktree
   на ресурсы вне worktree.** Копия или полноценный `composer install` в
   worktree — безопасные альтернативы.
2. Перед `git worktree remove` — проверять, есть ли в дереве junction/symlink:
   `dir /AL` (Windows) / `find . -type l` до remove.
3. Симптом после remove: «исчезли» файлы в СТОРОННЕМ дереве — восстанавливать по
   источнику правды (composer.lock / npm ci / git), а не искать их в корзине.
4. Родственный уже записанный факт (H2535, [DANGER_FACTS Systema](https://github.com/gasyoun/Uprava/blob/main/DANGER_FACTS.md)):
   worktree remove молча уничтожает и untracked — здесь новый класс: **уничтожает
   tracked-содержимое ЗА ПРЕДЕЛАМИ worktree** через ссылку.

## Источник

- Исполнение [H4463](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H4463-OxAlpha_Systema-Sanscriticum_cabinet-welcome-tour_09.09.26.md):
  junction создан OxAlpha (z-ai/glm-5.3-flash) 09-09-2026, повреждение и
  восстановление — та же сессия, vendor восстановлен `C:\php83\composer.bat install`.

_Dr. Mārcis Gasūns_
