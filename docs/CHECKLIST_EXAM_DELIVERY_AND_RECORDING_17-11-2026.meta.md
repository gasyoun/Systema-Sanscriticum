# CHECKLIST_EXAM_DELIVERY_AND_RECORDING_17-11-2026.md — metadoc

_Created: 17-11-2026 · Handoff: [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md)_

- **What:** экзамен в двух секциях: A — студенческий (до/во время/запись/расхождения через n8n → Sheet → `/api/webhooks/exam-scores`), B — pk72 hiring-exam (freeze → прогон → вердикт с числами).
- **Method:** код origin/main 17-11-2026: `ExamScoresWebhookController` (sig `verify.n8n.examscores`, дедуп `PaymentWebhookEvent.event_hash`), `ZoomWebhookController`+`AttendanceRecorder`→`webinar_attendances`, pk72-манифест и раннер `tools/hiring/pk72_checker_exam.py`.
- **Verifier:** существование путей проверено по свежему origin/main worktree; PR-diff — приёмка MG (R12).
- **Residual:** пароль/доступ на сам лист Google и состав n8n-воркфлоу `exam-scores-sync` в чеклист не вносились (живут на `.91`) — при первом реальном прогоне дополнить A1 фактами.
