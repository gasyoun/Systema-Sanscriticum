# Hiring contour: экзамен-стенды виртуального офиса

_Created: 23-08-2026 · H3397_

Доктрина найма: [VIRTUAL_OFFICE_RELIABILITY_DOCTRINE_23-08-2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/VIRTUAL_OFFICE_RELIABILITY_DOCTRINE_23-08-2026.md)
(pass^k-пороги ратифицированы MG 23-08-2026). Первый контур — «Проверяющий упражнений ПК-72».

## Жизненный цикл экзамена

1. **Стейджинг (read-only).** Реальные сданные работы выгружаются из кабинета
   в `hiring-quarantine/` (gitignored): `works/<submission-id>/*` — файлы сдачи,
   `assignments/*.md` — условия упражнений, `keys/*.md` — ключи ответов.
   В коммиты и мемо попадают только id работ, никогда имена студентов.
2. **Манифест.** Заполняется `docs/hiring/pk72_exam_manifest.json` по образцу
   [pk72_exam_manifest.example.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/hiring/pk72_exam_manifest.example.json):
   10 работ разной сложности, `expected_verdict` + `expected_rationale` проставлены
   **по ключам** до всякого прогона. Кандидат ключей не получает никогда.
3. **Заморозка** — `python tools/hiring/pk72_checker_exam.py freeze`.
   Каждый файл получает sha256, манифест запечатывается (`manifest_sha256`);
   правка после freeze детектируется и останавливает стенд.
4. **Серия N=10 подряд** — `... run --candidate "<cmd>" --candidate-id "<tier+model>"`.
   Каждый шаг завершается state-сверкой: строка дописана в
   `docs/hiring/pk72_exam_results.tsv` и перечитана дословно. Разбор кандидата —
   артефакт в `hiring-quarantine/artifacts/`, слова верификацией не считаются.
5. **Вердикт** — HIRE при 10/10; NO-HIRE иначе; STOP_STAND_DEFECT, если стенд
   упал дважды подряд на одной и той же работе (чинится стенд, не кандидат).
6. **Доказательство** — `... replay` воспроизводит каждую сверку из артефактов;
   `... memo-scaffold` выдаёт per-work таблицу для
   `docs/hiring/H3397_PK72_HIRE_VERDICT_*.md`.

## Контракт кандидата

`--candidate` — шаблон команды с подстановками `{work_files}` `{assignment}`
`{artifact}` `{work_id}` (пути без пробелов; каждый плейсхолдер — отдельный токен).
Кандидат печатает (или пишет в `{artifact}`) JSON:

```json
{"verdict": "pass", "analysis": "краткий разбор по пунктам упражнения"}
```

Пример: `--candidate "claude -p --allowedTools '' 'Проверь работу {work_files} по заданию {assignment}; верди JSON verdict pass|fail + analysis'"`.

## Ограждения

- Прод-кабинет в режиме экзамена читается только; запись оценок — отдельная
  более поздняя ступень с собственным верифицируемым вызовом.
- Персональные данные не покидают сервер/кворантин: id вместо имён, кворантин gitignored.
- Деньги/тарифы — вне контура полностью.
- Замороженная выборка одинакова для всех кандидатов; менять между кандидатами запрещено.

_Dr. Mārcis Gasūns_
