# Census: student manuals for logged-in users

_Created: 22-08-2026 · Last updated: 22-08-2026_

Inventory of **student-facing manuals** that require a logged-in session, versus the one public student book and the staff map. Grounded in [routes/web.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) (`auth` + `student.maintenance` group) and the product-docs seed in [ARCHITECTURE_SYSTEMA_PRODUCT_DOCS_CATALOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_PRODUCT_DOCS_CATALOG.md). Model: Grok 4.6 (`grok-4.6`).

## Behind login

| Manual | Live URL | Source | Notes |
|---|---|---|---|
| Как пользоваться кабинетом | [https://samskrte.ru/dvaram/help](https://samskrte.ru/dvaram/help) (`student.help`) | [STUDENT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_GUIDE_RU.md) | Product book: seven scenarios + tab reference. Guest redirected to login. Menu: «Как пользоваться». H3212. |
| Почему баланс праны уменьшился | [https://samskrte.ru/help/prana-balance](https://samskrte.ru/help/prana-balance) (`help.prana-balance`) | [resources/views/help/prana-balance.blade.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/help/prana-balance.blade.php) | Short help (perk, checkout discount, P2P, decay). Same auth group as `/dvaram`. H1756. |
| Онбординг (первые минуты) | Checklist on [https://samskrte.ru/dvaram](https://samskrte.ru/dvaram) | [onboarding-student.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) | In-cabinet checklist, not a `/help` route. |

`product_docs` audience `student` seeded rows: `student`, `prana`. `/dvaram/proverka` is the cabinet-mastery **quiz**, not a manual.

## Student book, no login

| Manual | Live URL | Source |
|---|---|---|
| Как сдавать домашнее задание | [https://samskrte.ru/faq/dz](https://samskrte.ru/faq/dz) (`faq.dz`) | [STUDENT_HOMEWORK_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_HOMEWORK_GUIDE_RU.md) |

`/help/homework` 301s here so the URL can be pasted in group chats. The cabinet guide links out; it does not duplicate file limits.

## Not student product

| File | Audience |
|---|---|
| [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) | Curator/developer map of every cabinet tab. Header sends humans to `/dvaram/help`. |
| [lila-games-manual.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lila-games-manual.html) | Games HTML for guest **and** student. Lives in `docs/`, not behind `/dvaram`. |

**Count:** two auth-only help pages + onboarding in the cabinet. Homework is the third student book, on purpose without login.

_Dr. Mārcis Gasūns_
