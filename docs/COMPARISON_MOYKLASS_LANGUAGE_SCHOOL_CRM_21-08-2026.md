# Comparison — Moyklass language-school CRM vs Systema (21-08-2026)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Sources: [Мой Класс · CRM для языковых школ](https://moyklass.com/crm-dlja-jazykovyh-shkol), [тарифы](https://moyklass.com/prices), [интеграции](https://moyklass.com/integratsii). Ours: Filament `/admin`, [GetCourse-parity spec](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md), [CRM/Jivo architecture](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_VISUALDCS_CRM_JIVO.md), public schedule widget ([H1427](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1427-Sonnet_Systema-Sanscriticum_public-schedule-widget-w1b_21.07.26.md)). `hub_grep moyklass` empty that day.

Cover plan: [PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md). Model: Grok 4.6 (`grok-4.6`).

## They have as a product, we do not (or only weakly)

| Cluster Moyklass | Systema now | Gap |
|---|---|---|
| Абонемент N занятий / заморозка / остаток | Tariffs, installments, membership, Tochka/PayPal | No visit-pack |
| Пробный урок as funnel object + post-trial follow-up | Lead, Deal, free-intro CTA, marathon, `Course.trial_*` paid SKU | No booked/attended/no-show/converted on Deal — **wave 1 cluster 1** |
| Group fill by CEFR A1–C1 | Groups, intake, waitlist, categories | No CEFR axis (out of programme) |
| Placement test → group | Marathon diagnostic, not a CRM object | No |
| Online-book widget + site/VK schedule | `/widgets/schedule` + `/api/public/schedule` (H1427); no book CTA | Partial — **wave 1 cluster 2** |
| Native iOS/Android + push | Cabinet/PWA, Telegram/VK; `CABINET_HYBRID` is a separate flag | No store apps (out of wave 1) |
| Teacher pay = rate × who came | `TeacherSalaryService` from revenue/blocks | Different model (out) |
| In-person attendance journal | Zoom auto-attendance + dashboard + «предупредил» | Online yes, paper journal no |
| IP telephony / call log | Jivo telephony HOLD | Out |
| WhatsApp / Wazzup | TG, VK, MAX transport, sms.ru | No WhatsApp (out) |
| Contract templates + FNS 13% certificates | Course certificates, admin docs | No |
| Warehouse, bonus lessons, birthdays | Shop courses, Prana | No |
| Multi-branch P&L | Investment model «do not open a branch» | Not our contour |
| Parent dual-login | Student cabinet | No (adult school) |
| Public CRM API/webhooks as a product | Inbound Zoom/payment webhooks | Not selling Systema as SaaS |

## We already cover their «funnel / pay / schedule / cabinet» pitch

Leads and kanban, Deals, Customer 360, follow-up tasks, segments, sales forecast, debts and promises, unified inbox, group schedules, teacher load (plan + partial code), payouts, Tochka acquiring, `RoleGate`.

## Wave 1 (ruled `/ask` 21-08-2026)

Trial as `Deal.kind=trial` (free intro seat **and** paid trial SKU), then a book button on the existing iframe. Execute [H3247 (Grok 4.6) — Wave 1 cluster 1: Deal.kind=trial, TrialBookingService, Zoom reconcile, FollowUpTask draft](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3247-Grok_Systema-Sanscriticum_trial-deal-kind-booking_21.08.26.md), then [H3248 (Grok 4.6) — Wave 1 cluster 2: book CTA on /widgets/schedule after cluster 1](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3248-Grok_Systema-Sanscriticum_public-schedule-trial-book-cta_21.08.26.md).

_Dr. Mārcis Gasūns_
