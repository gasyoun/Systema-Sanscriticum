_Created: 12-09-2026 · Last updated: 12-09-2026_

# Инцидент 12-09: OpenRouter deepseek-v4-pro timeout 600 с на оглавлении 101-мин лекции — exec 2880 упал после успешных YouTube/Rutube (диагностика glm-5.3-flash, 12-09-2026)

Тот же конвейер `ZOOM 1.4` ([1EIqqNzMl5NNIxST](https://context-ai.ru/workflow/1EIqqNzMl5NNIxST)), новый механизм отказа; полный разбор в [docs/INCIDENT_N8N_OPENROUTER_TIMEOUT_EXEC2880_12-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_N8N_OPENROUTER_TIMEOUT_EXEC2880_12-09-2026.md).

- **Exec 2880** (урок «Грамматика хинди гр. 2, суббота 13:00», meeting 86124823978, 101 мин, 334 МБ): DOWNLOAD → YouTube → обложка → Rutube ×2 → транскрипт — всё success; затем `AI Agent1`/`OpenRouter Chat Model1` (`deepseek/deepseek-v4-pro`, retryOnFail выключен) = ровно 600 021 мс → `Request timed out.` → error 13:41 UTC.
- **Контент цел** (заливки прошли до AI), потерян только хвост: урок в админке, оглавления/описания YT/Rutube, TXT на Drive, финальный TG. Полный ретрай запрещён (дубль YouTube, рунбук §7) — починка хирургическая: pinned-input реплей `AI Agent1` в UI + пошаговый хвост, попутно Retry On Fail (Max 2 / 60 с) на модельной ноде.
- **Первый таймаут класса** из 7 больших прогонов; гипотеза — генерация reasoning-моделью на самом длинном свежем транскрипте >600 с. @DECIDE: модель оглавлений (оставить + retry / быстрый класс).
_Dr. Mārcis Gasūns_
