# CHECKLIST_HOMEWORK_GRADING_QC_17-11-2026.md — metadoc

_Created: 17-11-2026 · Handoff: [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md)_

- **What:** advisory QC-чеклист приёмки домашней работы — единое ядро verify-before-accepting для ручного и агентского контуров (8 пунктов ядра, санскрит-специфика, agent-assist trust gate).
- **Method:** факты из кода origin/main 17-11-2026: `HomeworkSubmission` (статусы draft→submitted→needs_revision→accepted, reviewed_by/at), `HomeworkController::store` → `HomeworkService::recordSubmission` → `HomeworkNotifier`; правило «fit first, errata second» и свидетель-правило перенесены из плейбука разбора ДЗ.
- **Verifier:** существование всех поименованных файлов/путей проверено скриптом по свежему origin/main worktree; PR-diff — приёмка MG (ничего не мержится непрочитанным, R12).
- **Residual:** агент-грейдинг как постоянный контур — отдельное решение MG (@DECIDE при появлении); сейчас только trust-секция.
