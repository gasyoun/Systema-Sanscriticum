# Manuals → UI content audit

_Created: 09-07-2026 · Last updated: 09-07-2026_

Maps what today lives only in the team manuals ([`student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md), [`onboarding-student.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md), [`admin-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md), [`access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md), [`debtor-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-spec.md)) onto where a student should actually encounter that knowledge — inside the product, at the moment it's needed — versus what should stay manual-only for the team. This is a **document-only** audit; no UI or copy changes are made here. Related sibling audits from the same UX-audit queue: [`PUBLIC_STORE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PUBLIC_STORE_UX_AUDIT_2026.md), [`CHECKOUT_PURCHASE_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKOUT_PURCHASE_UX_AUDIT_2026.md), [`FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FIRST_5_MINUTES_STUDENT_UX_AUDIT_2026.md).

## 1. Content north star

Manuals are the **source of truth for the team** (curators, admins, developers) — long, technical, complete. A student should almost never need to open one. The UI's job is to answer the question **at the exact place and moment it comes up**, in one or two sentences, in plain Russian, with a single next action (a button, not a paragraph). If a screen needs a manual-length explanation to be usable, that is a product gap, not a content gap — flag it, don't paper over it with a wall of text.

Three content tiers, by where an answer belongs:

1. **In-UI snippet** — one line or a short tooltip/badge, visible on the screen where the question naturally arises (e.g. "why is this locked" right on the locked lesson card).
2. **Help page** — a short, student-facing page (not the raw manual) for topics that need more than a sentence but are still self-service (e.g. "how the dictionary works," "how prana works"). Linked from a "?" icon or a support-chat FAQ answer, not force-fed on every visit.
3. **Team-only** — stays in the manual. Role gates, internal service names, `RoleGate::` code, cron job names, edge cases a curator resolves manually. Never surfaces to a student, even simplified.

## 2. Manual inventory

| Manual | Audience | Student-relevant content? |
|---|---|---|
| [`student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) | Curator/developer — full technical map of the student cabinet | Yes — nearly all of it describes screens a student sees; written for the team, not the student |
| [`onboarding-student.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) | **Already student-facing** (doubles as the in-cabinet welcome checklist) | Yes — this is the one manual that is already a content source, not just a spec |
| [`admin-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md) | Admin/super-admin/curator/teacher — `/admin` panel delta | No — describes `/admin`, not the student cabinet; team-only by definition |
| [`finance-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/finance-manual.md) | Accountant/admin — money-facing `/admin` sections | No — reviewed only for boundaries (confirms nothing here should ever reach student UI: role gates, P&L, payout mechanics) |
| [`access-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/access-self-service-spec.md) | Developer spec, Phase 1 | Yes — the "why is my lesson locked" diagnostics panel is specced but **not yet built into any view** (confirmed: no `AccessDiagnosticsService`/"Почему закрыто" markup found in `resources/views`) — the content plan below should be ready for whoever implements it |
| [`debtor-self-service-spec.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/debtor-self-service-spec.md) | Developer spec, Phase 1 (already shipped per [`student-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) §5) | Yes — the "Мои долги" tab and its two CTAs ("Оплатить"/"Внести N ₽"/"Погасить всё") exist in [`dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php); current copy is button-label only, no explanatory microcopy |

**Current state of visible copy** (checked directly in the Blade views): [`dashboard.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/dashboard.blade.php) (952 lines) has almost no explanatory prose — button labels, `title=` tooltips on two icons, and one inline comment marking the "next lesson" hint logic. [`course.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/course.blade.php), [`lesson.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/student/lesson.blade.php), and [`checkout/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/checkout/show.blade.php) are similarly copy-light. This confirms the audit's premise: the manuals hold the explanations, the UI mostly doesn't — the gap is real, not already closed by scattered tooltips.

## 3. Mapping table

| Manual section | UI surface | Treatment |
|---|---|---|
| Login by order email, spam-folder note (`onboarding-student.md` §"Первый вход") | Login page, "not found" state | **Snippet** — already partly live via onboarding copy; ensure the "email not found" state itself repeats the spam-folder hint, not just the welcome doc |
| Course access unlocks automatically after payment, may take a few minutes (`onboarding-student.md` §"Личный кабинет") | Dashboard "Мои курсы" empty/pending state (right after checkout, before webhook lands) | **Snippet** — one line under a just-purchased course tile: "Доступ откроется автоматически, обычно за пару минут" |
| Lesson page: video / transcript / materials / homework / Zoom link / recording (`student-manual.md` §2.1) | Lesson page (`lesson.blade.php`) | **Snippet**, per element — e.g. "Запись появится здесь после занятия" already exists as a state per the manual; audit confirms it should stay inline, not become a help page (it's self-explanatory once seen) |
| Homework status meanings: черновик / на проверке / на доработку / принято (`onboarding-student.md` §"Домашние задания") | Homework block on lesson page — status badge | **Snippet** — status badge + one-line tooltip per state, not the full table; the full table stays in `onboarding-student.md` as the help-page version (link from a "?" near the badge) |
| Dictionary: search by Devanagari/IAST/Cyrillic/translation simultaneously (`onboarding-student.md` §"Словарь санскрита") | Dictionary tab, search input | **Snippet** — one-line placeholder text in the search box ("Ищите деванагари, IAST, кириллицей или переводом") replaces needing to read the manual table at all |
| "Мои оплаты" = payment history, confirms whether a block is covered (`student-manual.md` §4) | Payments tab | **Team-only framing, student gets a snippet** — student doesn't need the internal "diagnosis" framing, just sees their payment list; if empty, one line: "Здесь появится история ваших оплат" |
| "Мои долги" tab — "не продлил" vs installment, two CTAs (`student-manual.md` §5, `debtor-self-service-spec.md`) | Debts tab (already built) | **Snippet, currently missing** — add one-line context above the CTA distinguishing "оплатите весь непогашенный блок" vs "у вас согласована рассрочка — платите по графику или сразу всё"; today the tab shows only the buttons, per the Blade scan above |
| "Почему урок закрыт?" diagnostics: materialize / connect bot / reset password / no-payment routing (`access-self-service-spec.md` §"Архитектура Phase 1") | Locked-lesson card, on implementation | **Snippet, not yet built** — this is the highest-value gap: today a locked lesson has no explanation at all in the UI (confirmed no markup exists), and a student must ask a curator, which is exactly the support cost the spec was written to cut. When `AccessDiagnosticsService` ships, each finding code needs a **one-sentence, non-technical translation** (see §4 copy rules) — drafted in §5 below so implementation doesn't stall on copy |
| Prana: balance, shop, streak, badges, leaderboard, P2P, decay (`student-manual.md` §8, `onboarding-student.md` §"Прана") | Prana tab | **Split** — balance/shop/streak/badges/leaderboard are already self-explanatory UI (numbers, icons, progress bars) and need no manual-length text; **decay** (§8.7 — "no separate notification, balance just drops") is the one sub-topic that causes support tickets ("куда делась прана") and deserves a **help page** link ("Почему баланс праны уменьшился?") rather than silence |
| Referral program: link, invited count, money credit (`student-manual.md` §9) | Dashboard referral block | **Snippet** — already partly self-explanatory (counter + link), confirm one line stays: "Кредит начисляется автоматически, когда друг оплатит курс" |
| Profile/avatar/messenger binding scattered across dashboard, no dedicated page (`student-manual.md` §10) | Dashboard settings area | **Team-only structural note, not copy** — this is a navigation/IA finding (no "Профиль" page exists), not a content gap; out of scope for this audit, flag for a UX pass instead |
| Mobile: responsive web only, separate API with no client app (`student-manual.md` §11) | N/A | **Team-only** — API existence is irrelevant to a student in the browser; never surface |
| `/admin` role gates, `RoleGate::*`, Filament resource names, cron job names, CRM/Helpdesk internals (`admin-manual.md` entire) | N/A | **Team-only, always** — zero overlap with student UI by construction |
| Financial role matrix, P&L, payouts, accounting vs finance gate distinction (`finance-manual.md` entire) | N/A | **Team-only, always** — reviewed only to confirm no accidental student-facing leakage; none found |
| Debt payment resolver internals: `DebtPaymentResolver`, `PromiseAutoFulfiller`, promise-linking (`debtor-self-service-spec.md` §"Архитектура") | N/A | **Team-only** — student sees only the resulting CTA and amount, never the resolution logic |

## 4. Copy rules

- **Russian-first, English-ready key concepts.** Write the sentence in Russian; when a concept has a stable English/Sanskrit term used elsewhere in the product (dharma, IAST, Zoom, praná→прана — already localized), keep it consistent with existing manual usage rather than inventing a new translation.
- **One sentence, one action.** A snippet states the fact and offers exactly one button/link. If it needs "and also," it's a help page, not a snippet.
- **No internal names.** Never surface a service class, model name, config key, or role-gate string (`RoleGate::finance()`, `BlockAccessMaterializer`) — those are manual-only vocabulary.
- **State what happened, not why the system is built that way.** "Доступ откроется через несколько минут" not "оплата обрабатывается асинхронно через вебхук". The manuals explain mechanism for the team; the UI states outcome for the student.
- **Empty/error states always get a sentence, never a blank space.** Every tab/list that can be empty (payments, debts, badges) gets one line — see §3 for concrete examples.
- **Avoid long visible explanatory blocks in working screens.** If a screen already has more than ~2 lines of standing explanatory text (not counting labels/buttons), that is itself a finding — it belongs on a help page instead, linked via "?", not inline.
- **Reusable snippets, not per-screen originals.** The mapping table above intentionally reuses the same sentence for login, access, payments, homework, dictionary, and support across screens where the same fact recurs (e.g. "доступ откроется автоматически" applies to both a fresh purchase and a resolved debt payment) — write these once as shared partials/lang strings, not copy-pasted per view.

## 5. Prioritized content tickets

1. **"Почему закрыто?" snippet set** (blocks `access-self-service-spec.md` implementation from shipping with good UX). Draft one sentence + one CTA label per finding code:
   - `key_missing_for_paid_range` → "Оплаченный блок ещё не открылся автоматически." — **[Открыть оплаченные блоки]**
   - `not_connected_bot` → "Похоже, бот не подключён — это может мешать уведомлениям и поддержке." — **[Подключить бота]**
   - `login_issue` → "Если не получается войти — сбросьте пароль." — **[Сбросить пароль]**
   - `not_paid` (pending/failed payment found) → "Ваш платёж ещё обрабатывается." — **[Проверить статус оплаты]**
   - `not_paid` (no payment found) → "За этот блок ещё нет оплаты." — **[Оплатить]** (routes to debt self-service)
   - `no_finding` → "С доступом всё в порядке. Если урок всё равно не открывается — напишите куратору." — **[Позвать куратора]**
2. **"Мои долги" tab context line** — add the one-sentence distinction (не продлил vs рассрочка) above the existing CTAs; smallest-effort, highest-frequency (debts tab is seen by every debtor on every login).
3. **Homework status tooltips** — turn the existing `onboarding-student.md` table into four short badge tooltips on the lesson page; link the full table as a help page from a "?" if a student wants more.
4. **Dictionary search placeholder** — one-line copy change, no new page needed.
5. **Prana decay help page** — smallest new surface (one help page, linked from the Prana tab and from the support-bot FAQ), closes a named recurring support question ("куда делась прана").
6. **Payment/purchase empty-state copy** — audit-wide sweep for blank states across dashboard tabs (payments, badges, purchases) to add the one-line fallback text from §4.

## 6. Acceptance

Every frequent student question identified across the manuals now has a contextual UI answer:

- "Почему урок закрыт?" → answered on the lesson card itself (ticket 1), not by asking a curator.
- "Как оплатить долг?" → answered on the "Мои долги" tab with clear framing (ticket 2).
- "Что значит этот статус ДЗ?" → answered by a tooltip on the badge (ticket 3).
- "Как искать в словаре?" → answered by the search placeholder (ticket 4).
- "Куда делась прана?" → answered by a linked help page (ticket 5).
- "Дошла ли моя оплата / появятся ли уроки?" → answered by empty-state copy on the relevant tab (ticket 6).

Anything not covered above (financial internals, admin panel mechanics, role gates) is confirmed team-only per §2–§3 and intentionally excluded from student-facing content.

_Dr. Mārcis Gasūns_
