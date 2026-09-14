# Census: student manuals for logged-in users

_Created: 22-08-2026 · Last updated: 28-08-2026_

Inventory of **student-facing manuals** against the live `/admin/documentation` catalog (H3243 eight seeded books) plus the in-cabinet surfaces the 28-08 fill named. Guest HTTP is a real GET with no cookies, `--max-redirs 0`, from this machine at **28-08-2026 20:43 UTC**. Catalog row ids are from prod `product_docs` via artisan tinker on `.92` the same hour (eight active seeded rows, ids 1–8, matching [ProductDocSeeder](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/ProductDocSeeder.php)). Model: Grok 4.6 (`grok-4.6`). No manuals rewritten. RoleGate untouched. AdminDocument is a different shelf.

## Student catalog rows (`audience = student`)

| Book | URL | Guest HTTP | Intended login-required | Catalog row id |
|---|---|---|---|---|
| Как пользоваться кабинетом | [https://samskrte.ru/dvaram/help](https://samskrte.ru/dvaram/help) (`student.help`) | **302** → [https://samskrte.ru/login](https://samskrte.ru/login) | yes — `auth` + `student.maintenance` group in [routes/web.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php) | **1** (`slug=student`) |
| Как сдавать домашнее задание | [https://samskrte.ru/faq/dz](https://samskrte.ru/faq/dz) (`faq.dz`) | **200** | no — public FAQ; seeder description says so | **5** (`slug=homework`) |
| Почему баланс праны уменьшился | [https://samskrte.ru/help/prana-balance](https://samskrte.ru/help/prana-balance) (`help.prana-balance`) | **302** → [https://samskrte.ru/login](https://samskrte.ru/login) | yes — same auth group as `/dvaram` | **6** (`slug=prana`) |

Three student rows. Homework is the one student book that is meant to be readable without a session (pasteable in group chats). `/help/homework` is **not** a fourth book: it is a 301 alias to `/faq/dz` that lives **inside** the auth group, so a guest sees **302 → /login** and never the 301.

## Named student surfaces that are not catalog rows

| Surface | URL | Guest HTTP | Intended login-required | Catalog row id |
|---|---|---|---|---|
| Онбординг (первые минуты) | [https://samskrte.ru/dvaram](https://samskrte.ru/dvaram) (`student.dashboard`) | **302** → /login | yes — in-cabinet checklist from [OnboardingChecklist](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/OnboardingChecklist.php), source [onboarding-student.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) | none |
| Публичный близнец гида кабинета | [https://samskrte.ru/help/kabinet](https://samskrte.ru/help/kabinet) | **200** | no — H3499 public render of the same [STUDENT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_GUIDE_RU.md) so never-logged-in mail can land | none |
| Проверка кабинета (quiz) | [https://samskrte.ru/dvaram/proverka](https://samskrte.ru/dvaram/proverka) | **302** → /login | yes — quiz, not a manual | none (`quiz_audience=student` on row 1) |

## Catalog inventory itself (not a student book)

| Surface | URL | Guest HTTP | Intended login-required | Catalog row id |
|---|---|---|---|---|
| Каталог документации | [https://samskrte.ru/admin/documentation](https://samskrte.ru/admin/documentation) | **302** → [https://samskrte.ru/admin/login](https://samskrte.ru/admin/login) | yes — Filament admin; guest must not read the pointer | n/a (the page lists rows 1–8) |

## Staff / ops catalog rows (not student manuals)

Counted here so they are not silently folded into the student total. Guest GET on a teacher guide: **302** → `/admin/login` (same hour).

| id | slug | audience | url_path |
|---|---|---|---|
| 2 | teacher | teacher | `/admin/teacher-guide` |
| 3 | curator | curator | `/admin/curator-guide` |
| 4 | accountant | accountant | `/admin/accountant-guide` |
| 7 | payout-guide | accountant | `/admin/payout-attribution-guide` |
| 8 | important-files | ops | `/admin/admin-documents` (AdminDocument shelf, H2570 — not merged) |

## Not student product (repo files)

| File | Audience |
|---|---|
| [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) | Curator/developer map of every cabinet tab. Header sends humans to `/dvaram/help`. |
| [lila-games-manual.html](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/lila-games-manual.html) | Games HTML for guest **and** student. Lives in `docs/`, not behind `/dvaram`. |

**Count:** three student catalog books; two of them require login (`/dvaram/help`, `/help/prana-balance`); homework stays public. Onboarding lives on `/dvaram` and is not a catalog row. `/help/kabinet` is the public twin of the cabinet guide, not a second catalog book.

_Dr. Mārcis Gasūns_
