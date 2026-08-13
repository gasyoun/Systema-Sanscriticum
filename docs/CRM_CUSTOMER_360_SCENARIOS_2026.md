# CRM customer 360 — three journeys

_Created: 13-08-2026 · Last updated: 13-08-2026_

Own-data-shaped acceptance for H2483. Encoded in [`CustomerTimelineServiceTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Crm/CustomerTimelineServiceTest.php) and [`Customer360PageTest`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Crm/Customer360PageTest.php). Workspace URL: `/admin/customer-360?user={id}` or `?lead={id}`.

| Journey | Fixture | Expected next action | Source link |
|---|---|---|---|
| lead → paid | Lead (UTM) + matching User + paid `Payment` + won `Deal`, no cabinet event | `learning_nudge` — «Довести до первого действия в кабинете» | `/admin/users/{id}` · payment `/admin/payments/{id}/edit` |
| support → recovery | Open `SupportConversation` topic `access` + overdue `PaymentPromise` | `recovery_promise` — «Восстановить оплату / обещание» | `PaymentPromise` on the student card; Helpdesk `/admin/dialogs` |
| learner → repeat | Paid + won Deal + `first_cabinet_action`, no open Deal | `repeat_purchase` — «Предложить следующий курс» | `/admin/sales-board` |

Query budget: compose of a loaded card ≤ 30 queries (`CustomerTimelineService::QUERY_BUDGET`).

Browser stills: Feature/Livewire is the CI proof (Dusk is local-only here; money screens are not photographed). Capture later with `php artisan dusk` against a flag-on admin if a human wants PNG evidence under `docs/screenshots/customer-360/`.

_Dr. Mārcis Gasūns_
