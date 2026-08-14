# PACKET — Literal-Jivo telephony, departments and routing gate

_Created: 14-08-2026 · Last updated: 14-08-2026_

**H2486** (Grok 4.6 `grok-4.6`). Architecture / provider gate only. **No production account, number, call, recording or contract.**

Parent: [PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md).
Owners: [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md) · [support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md) · [CustomerTimelineService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Crm/CustomerTimelineService.php).
Privacy surface: [BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md) and [public/docs/privacy.pdf](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/docs/privacy.pdf) (ред. 22-01-2026).

## Verdict

**HOLD. Do not activate PSTN, Jivo telephony, departments or capacity routing.**

The CRM spine (Waves 1–3) exists. Measured school traffic is a Telegram-heavy inbox with **two** web responders in 30 days, **zero** conversation phone numbers, **zero** recorded voice events, and **one** closed thread. H2749 re-measured the same day (23:18 MSK) and **stopped** Phase 2: 0 completed `FollowUpTask` type=call, H2747 not live. See section 10. Jivo's own research doc already parked telephony and departments at this scale ([jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md)). This packet locks the adapter contracts and the numbers that would un-park each layer.

A human still has to sign a contract and flip a flag before any later implementation handoff may buy a number or turn a flag on. This packet is not that sign-off.

## 1. Own-volume snapshot (prod `/var/www/html`, 14-08-2026 19:12 MSK)

Read-only: `php artisan support:parity-report --days=30 --json`, `php artisan crm:forecast-report --json`, plus aggregate tinker counts. No PII exported.

### 1.1 Support / inbox (30 days, window 16-07 → 14-08)

| Channel | Conversations | Incoming | Outgoing | Unanswered | Unresolved &gt;24h | Avg first response |
|---|---:|---:|---:|---:|---:|---:|
| telegram (imported support) | 310 | 920 | 572 | 75 | 148 | 1 986 s (~33 min) |
| telegram_bot | 9 | 12 | 12 | 0 | 6 | 18 s |
| web | 4 | 6 | 5 | 0 | 2 | 5 s |
| vk | 3 | 3 | 3 | 0 | 1 | 4 s |
| **Total** | **326** | **941** | **592** | **75** | **157** | — |

Message stores, same 30 days: `chat_messages` **41**, `telegram_support_messages` **5 957** (import includes group/outbound traffic the rollup does not treat as one-to-one conversations). 90 days: 189 / 15 236.

| Thread KPI | Value |
|---|---:|
| `support_conversations` | 126 |
| open / closed | 125 / **1** |
| stale open &gt;24 h | 108 |
| `contact_phone` filled | **0** |
| assigned / distinct assignees | 1 / 1 |
| visitor_city / lead_captured_at / live presence | 0 / 0 / 0 |

Topic chat-days (30 d): uncategorized 128, access 123, payment 52, schedule 46, technical 15, materials 13, refund 4, certificate 1. Access + payment + schedule already dominate — that is **topic routing on the existing taxonomy**, not a new department object.

### 1.2 Operators and CRM owners

| Slice | Value |
|---|---|
| Users | 1 018 (empty-role 1 007 · admin 4 · teacher 4 · manager 1 · super_admin 1 · accountant 1) |
| Users with `users.phone` | 289 (28 %) |
| Distinct `chat_messages.answered_by` (30 d) | **2** |
| Distinct `telegram_support_messages.responder_user_id` (30 d) | 0 (import path does not populate it) |
| Open Deals | 3 · all stage `new` · all `assigned_to` null · 18 000 ₽ |
| Weighted forecast next 30 d | 1 440 … **2 700** … 4 500 ₽ |
| Qualifying paid last 30 d | **115** payments · **568 825** ₽ |
| Open CRM `FollowUpTask` | 1 (forecast KPI) |

Canonical owners stay: `User` + `social_accounts` (identity), `SupportConversation` + `UnifiedInboxReader` (inbox), `Lead` / `Deal` / `FollowUpTask` / `Payment` (CRM), `CustomerTimelineService` (compose, never copy). Telephony must not add a fourth identity table ([support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md)).

`FollowUpTask` already has `TYPE_CALL = 'call'` and a nullable `support_conversation_id` (H2381). Phase-0 callback is a **new write path into that row**, not a new task store.

### 1.3 What this volume is *not*

- Not a call centre. ~31 rollup incoming / day, almost all Telegram.
- Not measured voice demand. Zero `contact_phone`, zero call-like `ActivityEvent` types, no callback form.
- Not multi-queue specialization. One assignee, one closed thread, two web responders. `queue` is already `technical|general` on `SupportConversation`.
- Not a CRM-owned sales-call machine. Three unassigned Deals vs 568 k₽ paid without Deal coverage (same honesty as H2485).

jivo.md's 2026-07 line still holds: for 1–2 people and 1–100 messages/day, telephony and enterprise routing cost more than they return.

## 2. Use cases that *would* justify voice (not yet observed)

| Use case | Who | Trigger | Current substitute | Activate when |
|---|---|---|---|---|
| U1 Callback from site / cabinet | visitor or student | «перезвоните» | Telegram / web chat | ≥8 `FollowUpTask` type=call *requests* in 14 days |
| U2 Sales consult | manager | high-ticket / ambiguous tariff | chat + Deal next action | ≥20 completed manual callbacks in 30 days **or** a written sales-call SOP |
| U3 Access / payment escalation | curator | lock / promise / refund | Helpdesk + promise | topic access+payment share stays high **and** U1 fires |
| U4 Missed-chat voice fallback | operator | unanswered &gt; N min | TG follow-up | median TG first response &gt; 15 min for 14 days **and** ≥3 assigned operators |
| U5 Debtor outbound | accountant / manager | overdue promise | existing reminder / SMS.ru | **out of scope** until a separate money-contour handoff; this packet forbids auto-dial |

U5 is listed so nobody "just adds" outbound dialer to the callback form. Auto-dial of debtors is a money/privacy contour of its own.

## 3. Provider matrix (primary sources, 14-08-2026)

| Option | What it is | Number ownership | Recording / retention | Legal / RF | Monthly cost at *our* size (2 seats, 0–50 calls) | Fit |
|---|---|---|---|---|---|---|
| **A. Manual callback (recommended now)** | Form → `FollowUpTask::TYPE_CALL` → curator phones from existing device | School / personal. No new number | None unless operator records locally (forbidden by policy until consent) | No new processor. Phone stays on `users.phone` / task note under existing §5.1 | **0 ₽** + engineering | **Only option that matches measured demand** |
| **B. Jivo callback on Free** | Widget «заказать звонок»; 3-round ring | Default AON is **Jivo's** `+7 499 350-43-27` until you buy/SIP a number ([callback help](https://www.jivo.ru/help/telephony/nastroika-funktsyy-zakazat-obratnyi-zvonok.html)) | Optional; **3 months in Jivo app** | Jivo has a 152-ФЗ consent checkbox ([policy help](https://www.jivo.ru/help/functions/policy.html)). Receive path **requires a Russian IP**. Carrier under the hood is **Voximplant** (KYC email from Voximplant; price PDFs on `cdn.voximplant.com`) | 0 license + per-minute + **second inbox** | Fail: parallel CRM/identity, we do not own the CLI |
| **C. Jivo Pro + «Телефония Плюс» + city number** | In-app VATS, widget calls, IVR, SIP bring-your-own | Buy-in-Jivo **or** SIP your number. Docs for purchase: [telephony.html](https://www.jivo.ru/help/telephony/telephony.html). List prices: 8-800 990 ₽/mo; city standard **300 ₽/mo** (0 connect); business 3 000 connect + 300/mo; premium 7 000 + 300 ([jivo.ru/telephony](https://www.jivo.ru/telephony/)) | Plus-module transcripts; 3-month callback recordings | Same Voximplant KYC. Chat API / routing only on Corporate | Pro **1 342 ₽/op** (2y prepay) × 2 + Plus **416 ₽/mo** (1y) + number 300 ≈ **3 400 ₽** + minutes ([pricing](https://www.jivo.ru/pricing/)) | Fail at this volume. Departments need Pro; **capacity routing needs Corporate 3 142 ₽/op** |
| **D. Voximplant direct** | Same carrier Jivo resells | Own numbers after operator KYC | Configurable; we would own the webhook | Need a written DPA + storage-region fact before any record flag | Usage + number. Outbound price list: [cdn.voximplant.com/new_price/pricing_ru.pdf](https://cdn.voximplant.com/new_price/pricing_ru.pdf) | **Preferred carrier if** Phase 2 PSTN ever unlocks — adapter to *our* inbox, not Jivo's |
| **E. MANGO OFFICE VATS** | RF cloud PBX, 300+ CRM connectors, Russian software registry, ISO 27001 | Own numbers; SIP-in of foreign numbers free ([product](https://www.mango-office.ru/products/virtualnaya_ats/)) | Unlimited cloud recording (operator-chosen TTL) | Strong RF story. No first-class Laravel/Systema connector — we would still write the webhook adapter | Base **from 1 600 ₽/mo** (3 users) — already larger than the team | Overkill until U2/U4 fire. Keep as RF-friendly alternative to Voximplant |
| **F. sms.ru (already wired)** | SMS only | N/A | N/A | Existing `SmsRuChannel`; API id optional | 0 extra if already configured | Keep for SMS. **Not voice** |

**Do not pick C to "get Jivo parity".** School-operational Jivo *inbox* is already our Helpdesk. Buying Jivo's VATS would fork conversations into `app.jivosite.com` and recreate the identity-chaos [support-identity.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-identity.md) exists to prevent.

**Number-ownership rule:** if a later handoff buys a number, the school (or the legal entity on the privacy policy) must be the subscriber. Jivo-default AON is forbidden. SIP-in of a number we already own is allowed.

## 4. Adapter contracts (no tables until Phase 1)

### 4.1 Identity

| Fact | Owner | Telephony rule |
|---|---|---|
| Person | `users` | Call never creates a user |
| External id | `social_accounts` (`provider`, `provider_id`) | If a provider gives a stable SIP/CLI id, store `provider=telephony` (or the carrier name) **here**. No fourth table |
| Phone cache | `users.phone` (already used by SMS.ru) | Write only from an explicit, consented callback form or from a verified inbound CLI match. Do not scrape chat text |
| Guest | `SupportConversation.guest_token` + `contact_phone` | Guest callback fills `contact_phone` on the **existing** thread, then a `FollowUpTask` |

### 4.2 Call event DTO → timeline

Code: [`app/Support/Telephony/CallEvent.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/Telephony/CallEvent.php). Immutable. Not persisted.

Allow-listed `type` values:

- `call.requested` — form or widget, no PSTN yet
- `call.started` / `call.answered` / `call.missed` / `call.ended`
- `call.recording_available` — metadata only (url, ttl, sha256). **Never** the audio bytes in the DTO

`CustomerTimelineService` later composes these the same way it composes `FollowUpTask` and `SupportConversation`: read, do not copy into a mirror CRM table. Persistence, when Phase 1 lands, is either:

1. `ActivityEvent` with the same allow-listed types (preferred — append-only telemetry already exists), or
2. a narrow `call_events` table **owned as a channel adapter**, FK `user_id` / `support_conversation_id` / `follow_up_task_id`, no deal-amount columns.

Option 2 is allowed only if ActivityEvent query cost is measured too high (same bar Wave 1 used for a timeline mirror). Default is (1).

### 4.3 Callback request (Phase 0 / 1)

Input: consented phone + optional slot + `user_id` or `guest_token` + `support_conversation_id?`.

Write:

1. `FollowUpTask` `{type: call, support_conversation_id, assigned_to, due_at, note}`
2. `CallEvent` `call.requested`
3. optional `users.phone` / `contact_phone` update

No provider HTTP. Flag `features.telephony_callback_request` default **OFF**.

### 4.4 PSTN adapter (Phase 2, gated)

One interface, one implementation per carrier:

```
CallProvider
  placeCallback(CallEvent $requested): provider_call_id
  handleWebhook(Request): CallEvent   // HMAC + idempotency key
```

Webhook is secret-gated, idempotent on `provider_call_id`, 404 when `features.telephony_pstn` is OFF (same pattern as n8n clip callback). Recordings stay at the carrier until `features.telephony_recording` is ON **and** a DPA + policy edit exist.

### 4.5 Departments / capacity (Phase 3–4, gated)

Do **not** invent a `departments` table while `SupportConversation.queue` (`technical|general`) and `SupportTopicRule.category` already exist.

| Jivo feature | Our existing stand-in | Unlock |
|---|---|---|
| Отделы (Pro; client picks a department; 20 s then everyone — [otdely](https://www.jivo.ru/help/functions/funktsyia-otdely.html)) | `queue` + required close-topic | ≥3 staff each closing ≥10 threads in 30 d **and** a measured topic split (sales vs access vs schedule) |
| Max chats / operator (Corporate) | Helpdesk assignment + `FollowUpTask` load | ≥4 concurrent online operators **or** median first response &gt; 15 min for 14 d on an assigned queue |
| Routing rules (Corporate only — [chats-routing](https://www.jivo.ru/help/functions/chats-routing.html)) | Topic classifier + `assigned_to` + queue | Same as capacity, plus a written rule list a human approved. No geo-IP routing until the 152-ФЗ geo brief is closed |

Flags `features.support_departments` and `features.support_capacity_routing` default **OFF**. Threshold numbers live in [`config/telephony.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/telephony.php) so later code cannot invent new ones in Blade.

## 5. Data flow and threat model

```
[visitor/student]
    │  consented phone (checkbox §9.2)
    ▼
FollowUpTask type=call ──► Helpdesk / WorkQueue (human calls)
    │
    ▼  only if telephony_pstn ON
CallProvider.placeCallback ──► carrier (Voximplant / Mango / …)
    │  webhook HMAC
    ▼
CallEvent ──► ActivityEvent ──► CustomerTimelineService
    │
    ▼  only if telephony_recording ON + DPA
recording URL at carrier (TTL) — audio not in our DB
```

| Threat | Why it is real here | Mitigation (locked) |
|---|---|---|
| T1 Recording without a purpose / consent | Jivo stores callback recordings **3 months** by default; our policy §9.2 already uses an **explicit checkbox** for consent-gated processing; voice can be biometric (ст. 11 152-ФЗ) if used to identify | Recording flag OFF. No default-on. Separate checkbox from cookie banner. Purpose = "quality / dispute", not identification |
| T2 Parallel CRM in Jivo | Widget callback creates a Jivo dialog; Chat API is Corporate-only | Forbidden. Adapter writes our `FollowUpTask` / timeline only |
| T3 Fourth identity table | Classic inbox failure mode | `social_accounts` only |
| T4 Number we do not own | Jivo default AON `+7 499 350-43-27` | School-owned subscriber or no PSTN |
| T5 Cross-border / non-RF processor | Policy §1.5 RF-only; Jivo's carrier is Voximplant; geo brief already killed ip-api.com on the same rule | Written storage-region + DPA before PSTN. Mango is the RF-registry alternative |
| T6 Transcripts into OpenRouter | `support_ai_include_telegram` is already OFF for imported DMs | Call transcripts follow the same flag family; default exclude |
| T7 Auto-dial debtors | High harm, money contour | U5 forbidden in this programme |
| T8 Operator personal phones leak into chat | Manual Phase 0 | Curator calls out; never paste the school's private mobile into a student thread |
| T9 Russian-IP lock for Jivo receive | Documented on the callback page | Irrelevant if we never use Jivo's VATS |
| T10 Webhook spoof | Future PSTN | Shared secret, flag-off 404, idempotency, no PII in query string |

This is **not** a lawyer's opinion. Residual legal questions (same class as the geo brief):

- **Q-law-4.** Is a one-off consented callback (we call the number the subject typed) covered by contract performance (п. 5 ч. 1 ст. 6 152-ФЗ) for a logged-in student, or is it a new purpose that needs §9.2?
- **Q-law-5.** May we store a recording / transcript at Voximplant or Mango under §1.5 / §7.3–7.4, and for how long? Policy today names payments, hosting and mail — **not** a telephony processor.
- **Q-law-6.** Is voice used only as conversation content, or would any later "voice biometrics / operator QA scoring" trip ст. 11?

A human lawyer answers those before `telephony_recording` or `telephony_pstn` may flip. Phase 0 (task-only callback) can proceed after Q-law-4.

## 6. Cost scenarios (illustrative, list prices 14-08-2026)

Assumptions: 2 operator seats, ≤50 connected minutes / month, one city number if any, no 8-800, no WhatsApp, no AI-operator add-on.

| Scenario | Monthly (list) | One-off | What we still cannot buy |
|---|---:|---:|---|
| S0 Status quo | 0 | 0 | Voice |
| S1 Phase 0 form (recommended) | 0 | engineering | PSTN |
| S2 Jivo Free callback, their AON | minutes only | 0 | Number ownership, our inbox |
| S3 Jivo Pro ×2 + Telephony+ + city standard | ~3 400 ₽ + minutes | 0 | Capacity routing (Corporate), Chat API |
| S4 Jivo Corporate ×2 + number | ~6 700 ₽ + minutes | 0 | Still a second inbox |
| S5 Mango Base (3 users) + city number | from 1 600 ₽ + number/min | 0 | Native Systema UI |
| S6 Voximplant + owned number + our adapter | number ~300 ₽ + minutes | KYC | Recording until Q-law-5 |

At 568 k₽ / 30 d qualifying revenue, even S3 is cheap **in money** and expensive **in identity and operator attention**. Cost is not the blocker. Volume and legal surface are.

## 7. Canary and rollback

| Lane | Flag / env | Default | Canary | Rollback |
|---|---|---|---|---|
| Callback request (no PSTN) | `TELEPHONY_CALLBACK_REQUEST` | OFF | Staff + one owner-approved test user; 14-day count of tasks created | Flag false + `config:cache`. Rows stay (they are FollowUpTasks) |
| PSTN | `TELEPHONY_PSTN` | OFF | One owned number, business hours, no recording, internal CLI only | Flag false. Webhook 404s. Do **not** port the number away in the same hour — cancel outbound first |
| Recording | `TELEPHONY_RECORDING` | OFF | After Q-law-5/6 + DPA + policy §5/§7 edit | Flag false. Leave carrier TTL to expire. Do not copy audio into git/S3 "just in case" |
| Departments | `SUPPORT_DEPARTMENTS` | OFF | One extra queue label on Helpdesk, no client-facing picker until 2+ queues have operators | Flag false. `queue=general` remains |
| Capacity routing | `SUPPORT_CAPACITY_ROUTING` | OFF | Cap = 3 open assigned threads / operator, Helpdesk-only | Flag false. Assignment stays manual |

Hard stops (same family as the VisualDCS verification halt list): privacy exposure, identity/thread ambiguity, money/access writes, production send without approval, destructive migration.

Reproduce the volume half of this packet:

```bash
ssh -o BatchMode=yes root@193.232.229.92 'cd /var/www/html && php artisan support:parity-report --days=30 --json && php artisan crm:forecast-report --json'
```

## 8. Phased backlog (separately mintable)

Thresholds are in [`config/telephony.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/telephony.php). A later session that cannot show the live count **above** the threshold must not implement that phase.

| Phase | What | Gate | Suggested executor |
|---|---|---|---|
| **0 / 1** | Consented callback **request** → `FollowUpTask::TYPE_CALL` + `CallEvent::requested` + Helpdesk list. No provider | Q-law-4 answered **or** treat as contract-performance for logged-in students only (guests stay off). Flag still OFF until a human flips | Sonnet — mechanical, existing models |
| **2** | `CallProvider` + one carrier webhook + owned number. No recording | Phase 1 live 30 d **and** ≥20 completed call tasks **and** Q-law-5 DPA | Grok — provider/legal |
| **3** | Departments = extra `queue` values + Helpdesk filter. No client picker | ≥3 staff × ≥10 closed / 30 d + topic split | Sonnet |
| **4** | Capacity cap + fallback "invite all" | ≥4 concurrent operators **or** 14-day median FR &gt; 15 min | Grok |
| **never here** | Auto-dial debtors, Jivo widget as second inbox, default-on recording, Jivo-owned AON | — | — |

## 9. Evidence checklist (this pass)

- [x] Architecture / provider packet (this file)
- [x] Own-volume table + provider total-cost comparison (sections 1 and 6)
- [x] Privacy / recording / retention / consent threat model (section 5)
- [x] Adapter contracts (section 4 + `CallEvent` + `config/telephony.php`)
- [x] Flags default OFF + test
- [x] Canary / rollback (section 7)
- [x] Separately mintable backlog (section 8)
- [x] H2749 Phase 2 re-measure (14-08-2026 23:18 MSK) — **STOP** (0 completed call tasks vs ≥20; H2747 not live). Section 10.
- [x] DPA / storage-region note — **absent**; recording stays OFF (section 10.3)
- [x] H2750 Phase 3–4 re-measure (14-08-2026 23:49 MSK) — **STOP** (0 staff at the close bar; 14-day median FR 244 s; 1 assignee). Section 11.
- [ ] Human contract approval / flag flip — **not this session**
- [ ] Q-law-4/5/6 — parked for a lawyer; they do not block Phase 0 design


## 10. H2749 Phase 2 re-measure (14-08-2026 23:18 MSK) — STOP

H2749 (Grok 4.6 `grok-4.6`) re-ran the packet volume half on prod `/var/www/html` the same calendar day as H2486. **Phase 2 is not implemented.** No number was purchased. No `CallProvider` landed. Flags stay OFF.

### 10.1 Gate check

| Gate | Threshold ([config/telephony.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/telephony.php)) | Live 14-08-2026 23:18 MSK | Result |
|---|---|---|---|
| H2747 (Sonnet 5) consented callback request live 30 d | Phase 1 shipped and `TELEPHONY_CALLBACK_REQUEST` on for 30 days | H2747 still queued; no PR; `telephony_callback_request=false` | FAIL |
| Completed `FollowUpTask` `type=call` in 30 d | ≥ 20 (`activation.completed_manual_callbacks_per_30d`) | **0** created, **0** with `done_at`, **0** all-time | FAIL |
| Q-law-5 DPA + storage-region fact | Written DPA + policy §7.3 telephony processor + RF storage region | No DPA in repo; policy still names payments / hosting / mail only | FAIL (recording) |

U1 (callback requests ≥8 / 14 d) also fails: 0 call tasks exist.

### 10.2 Support volume (same command as section 1)

`php artisan support:parity-report --days=30 --json` at 2026-08-14T23:18:46+03:00. Window 16-07 → 14-08.

| Channel | Conversations | Incoming | Outgoing | Unanswered | Unresolved >24h | Avg first response |
|---|---:|---:|---:|---:|---:|---:|
| telegram | 311 | 924 | 572 | 76 | 148 | 1 986 s |
| telegram_bot | 9 | 12 | 12 | 0 | 6 | 18 s |
| web | 4 | 6 | 5 | 0 | 2 | 5 s |
| vk | 3 | 3 | 3 | 0 | 1 | 4 s |
| **Total** | **327** | **945** | **592** | **76** | **157** | — |

| Slice | Value |
|---|---:|
| `FollowUpTask` rows (all types) | 2 |
| `FollowUpTask` `type=call` | **0** |
| `FollowUpTask` `type=call` with `done_at` in 30 d | **0** |
| `support_conversations.contact_phone` filled | **0** |
| `features.telephony_callback_request` | false |
| `features.telephony_pstn` | false |
| `features.telephony_recording` | false |

The inbox is still Telegram-heavy (~31 rollup incoming / day). Voice demand is still unmeasured. [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md)'s 1–2 people / 1–100 messages/day line still holds.

### 10.3 DPA / storage-region (Q-law-5) — recording stays OFF

This is **not** a lawyer's opinion.

| Fact | Source | Implication |
|---|---|---|
| Policy §1.5: processing and storage of personal data only on RF territory | [public/docs/privacy.pdf](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/public/docs/privacy.pdf) ред. 22-01-2026; same quote in [BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md) section 2.2 | A telephony processor whose recording or transcript store is outside the RF is blocked until a human lawyer answers Q-law-5 |
| Policy §7.3 names payment systems, hosting, mail — **not** a telephony processor | same | Adding Voximplant or Mango as a processor needs a policy edit, not just a flag flip |
| Policy §7.4: third-party transfer only under a concluded contract with confidentiality clauses | same | Need a written DPA (поручение / договор обработки) before any recording URL is accepted |
| Policy §9.2: explicit checkbox for consent-gated purposes; voice can be biometric (ст. 11 152-ФЗ) if used to identify | packet section 5 T1 / Q-law-6 | Recording flag stays OFF; no default-on; no QA-scoring / voice biometrics |
| Voximplant storage region | not in repo; carrier docs do not substitute for a signed DPA | Preferred *carrier* if Phase 2 later unlocks; **not** authorized to hold recordings |
| Mango OFFICE | RF software registry + ISO 27001 (vendor claim, packet section 3 E) | Stronger RF story; still needs a written DPA and a §7.3 name; overkill at this volume |
| Jivo default AON `+7 499 350-43-27` | packet section 3 B, `config/telephony.php` `forbidden_default_aon` | Still forbidden. School must be the subscriber if a later handoff buys a number |

**Recording stays OFF.** `TELEPHONY_RECORDING` remains `false`. Do not copy audio into the app DB / S3 / git.

### 10.4 Canary / rollback — PSTN lane not armed

Section 7 table is unchanged. This pass does **not** arm the PSTN or recording canaries.

| Lane | Armed this pass? | Rollback if someone flips the env anyway |
|---|---|---|
| Callback request | No — H2747 has not shipped | Flag false + `config:cache`. Rows stay |
| PSTN | **No.** No adapter, no number, no webhook route | N/A. If a later PR adds the webhook: flag-off must 404; do not port a number away in the same hour |
| Recording | **No.** Q-law-5 open | Flag false. Leave carrier TTL to expire |
| Departments / capacity | **No.** H2750 STOP (section 11) | Flag false |

Hard stops from section 7 still apply: privacy exposure, identity/thread ambiguity, money/access writes, production send without approval, auto-dial debtors.

### 10.5 What a later Phase 2 session must show

Re-mint only when **all three** are true:

1. H2747 merged **and** `TELEPHONY_CALLBACK_REQUEST` live for 30 days.
2. `FollowUpTask::TYPE_CALL` with `done_at` in the last 30 days ≥ `config('telephony.activation.completed_manual_callbacks_per_30d')` (20).
3. For recording only: Q-law-5 answered + DPA + policy §5/§7 edit. PSTN without recording can proceed after (1)+(2) and a written storage-region fact for signaling metadata; audio still OFF.

Until then: do not buy a number, do not implement `CallProvider`, do not accept a Jivo VATS contract.

## 11. H2750 Phase 3–4 re-measure (14-08-2026 23:49 MSK) — STOP

H2750 (Grok 4.6 `grok-4.6`) re-measured the department and capacity gates on prod `/var/www/html` the same calendar day as H2486 / H2749. **Phase 3 (extra `queue` values + Helpdesk filter) is not implemented. Phase 4 (capacity cap) is not implemented.** No `departments` table. No client-facing department picker. No Jivo Corporate routing clone. Flags stay OFF.

Packet numbers win over the 30-day Telegram *average* first response (~1 986 s). The capacity gate is **median** first response on an **assigned** queue over 14 days.

### 11.1 Gate check

| Gate | Threshold ([config/telephony.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/telephony.php)) | Live 14-08-2026 23:49 MSK | Result |
|---|---|---|---|
| Phase 3 staff × closed threads | ≥ 3 staff each closing ≥ 10 threads / 30 d (`department_staff` × `department_closed_threads_per_staff_30d`) | **0** staff meet the bar. Closed threads 30 d = **1**, and that row has `assigned_to` null | FAIL |
| Phase 3 topic split | Measured sales vs access vs schedule on the existing `SupportTopicRule` taxonomy | Access 123 / payment 52 / schedule 46 chat-days (same 30 d window). Split exists; it is **not** a new department object | FAIL (staff bar first) |
| Phase 4 concurrent operators | ≥ 4 concurrent online operators (`capacity_concurrent_operators`) | Distinct assignees = **1**. Web `answered_by` 30 d = **2**, 14 d = **0**. Telegram `responder_user_id` 30 d = **0**. Open assigned threads = **1** | FAIL |
| Phase 4 median first response | 14-day median FR > 900 s **on an assigned queue** (`capacity_median_first_response_seconds`, `capacity_window_days`) | Assigned-queue median: **n = 0** (no assigned rollup in 14 d). Overall 14-day median FR = **244 s** (n = 66). Overall 14-day *average* FR = 2 399 s (outliers; not the gate) | FAIL |

Implementing extra queues or a cap on today's 1-assignee inbox is the packet fail case. The existing `queue` values stay `technical` / `general` (live: 118 technical, 1 general, 7 empty). Helpdesk already filters `queue=technical` on the «Тех.» tab via `assigned_to` + `queue` — that is not a department picker.

### 11.2 Support volume (same command as section 1)

`php artisan support:parity-report --days=30 --json` at 2026-08-14T23:48:02+03:00. Window 16-07 → 14-08.

| Slice | Value |
|---|---:|
| `support_conversations` | 126 |
| open / closed | 125 / **1** |
| closed in 30 d | **1** |
| assigned / distinct assignees | 1 / **1** |
| staff closing ≥10 threads / 30 d | **0** |
| web responders 30 d / 14 d | 2 / 0 |
| 14-day median first response (all rollups) | 244 s |
| 14-day median first response (assigned queue) | n/a (n = 0) |
| `features.support_departments` | false |
| `features.support_capacity_routing` | false |

The inbox is still the 14-08-2026 shape: two web responders, one assignee, one closed thread. [jivo.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/jivo.md)'s 1–2 people / 1–100 messages/day line still holds.

### 11.3 What was not built

| Temptation | Why not |
|---|---|
| Extra `SupportConversation.queue` labels (sales / access / schedule) | Phase 3 staff bar failed. Topic taxonomy already exists (`SupportTopicRule`) |
| Helpdesk department filter behind `support_departments` | Same. No second queue has operators |
| Client-facing department picker | Packet forbids until two queues have operators |
| Cap on assigned open threads (`support_capacity_routing`) | Phase 4 both OR-branches failed. One assignee; assigned-queue median FR unmeasurable |
| New `departments` table | Packet forbids while `queue` + `assigned_to` exist |
| Geo-IP routing | 152-ФЗ geo brief still closed |

### 11.4 Canary / rollback — department and capacity lanes not armed

Section 7 table is unchanged.

| Lane | Armed this pass? | Rollback if someone flips the env anyway |
|---|---|---|
| Departments | **No.** No extra queue values, no new Helpdesk filter | `SUPPORT_DEPARTMENTS=false` + `config:cache`. `queue=general` remains |
| Capacity routing | **No.** No assignment cap | `SUPPORT_CAPACITY_ROUTING=false`. Assignment stays manual |

### 11.5 What a later Phase 3 / 4 session must show

Re-mint only when the matching gate is true. Packet numbers still win.

**Phase 3 (departments = extra `queue` + Helpdesk filter, no client picker):**

1. Distinct staff each with ≥ `config('telephony.activation.department_closed_threads_per_staff_30d')` (10) closed `SupportConversation` rows in 30 days ≥ `config('telephony.activation.department_staff')` (3).
2. The existing topic split still shows at least two operator-relevant categories (access / payment / schedule already do). Do **not** rebuild `SupportTopicRule` as departments.

**Phase 4 (capacity cap + fallback "invite all"):**

1. ≥ `config('telephony.activation.capacity_concurrent_operators')` (4) concurrent online operators, **or**
2. 14-day **median** first response on an **assigned** queue > `config('telephony.activation.capacity_median_first_response_seconds')` (900). Average FR and unassigned rollups do not count.

Until then: do not add queue values, do not cap assignment, do not flip `SUPPORT_DEPARTMENTS` or `SUPPORT_CAPACITY_ROUTING`.

_Dr. Mārcis Gasūns_
