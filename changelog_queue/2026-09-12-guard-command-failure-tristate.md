_Created: 12-09-2026 · Last updated: 12-09-2026_

- **H4611 (Codex, GPT-5): git-integrity guard больше не скрывает ошибки `git status` и `git diff`.** Реальный код отказа сохраняется в журнале, оба сбоя завершают проверку операционным `exit 1`, а отдельный shell-тест фиксирует контракт `0 = clean`, `1 = проверка невозможна`, `2 = tamper suspect`.

_Dr. Mārcis Gasūns_
