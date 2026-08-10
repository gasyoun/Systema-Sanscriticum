# Support / JIVO parity — production acceptance (H2382)

_Created: 07-08-2026 · Last updated: 07-08-2026_

> This acceptance remains deliberately scoped to **school-operational parity**. The 08-08-2026
> successor plan schedules CRM and later telephony/departments/routing only after this gate:
> [PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VISUALDCS_CRM_JIVO_2026H2.md).

**Model:** Grok 4.5 (`grok-4.5`) · handoff
[H2382](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2382-Grok_Systema-Sanscriticum_support-parity-production-acceptance_07.08.26.md)

**Verdict: HOLD** — not full parity. Capstone matrix + live aggregates shipped;
H2381 operator workflow and H1200 email/reply-out residuals still block GO.

Evidence playbook: [PLAYBOOK_EVIDENCE_OF_DONE_2026.md](https://github.com/gasyoun/Uprava/blob/main/docs/PLAYBOOK_EVIDENCE_OF_DONE_2026.md).
Ground truth for code: [support-subsystem-map.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/support-subsystem-map.md).
Visitor roadmap: [ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_JIVO_VISITOR_PARITY_2026_2027.md).

---

## 1. How to re-run the 14-day report

```bash
# local / CI (seeded DB)
php artisan support:parity-report --days=14 --json

# production (read-only)
cd /var/www/html && php artisan support:parity-report --days=14 --json
```

Owning code:

- [`app/Services/Support/SupportParityReportBuilder.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportParityReportBuilder.php)
- [`app/Console/Commands/SupportParityReport.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SupportParityReport.php)
- Tests: `tests/Feature/Support/SupportParityReportTest.php`

Exit code: **0 = GO**, **1 = HOLD** (current expected state is HOLD).

Privacy: aggregate channel/topic counts only; no per-student export, no message bodies, no IP export.

---

## 2. Prod probe snapshot (07-08-2026, `root@193.232.229.92`)

Read-only probes this session (no production writes, no unsolicited sends).

### 2.1 Feature flags (live config)

| Flag / config | Prod value | Meaning |
|---|---|---|
| `support_unified_reply` | **true** | Helpdesk TG-support reply path enabled |
| `support_ai_assist` | **true** | AI drafts on (never auto-send) |
| `support_ai_include_telegram` | **false** | Imported TG DMs not sent to LLM |
| `support_answer_suggester` | **true** | FAQ suggester deploy gate on |
| `support_template_drafts` | **true** | Template drafts before LLM |
| `support_visitor_geo` | **true** | Geo resolve *allowed* |
| `support_geo.driver` | **`null`** | **City resolve never runs** |
| `support_visitor_presence` | **true** | Presence beacon allowed |
| `support_lead_capture` | **true** | Widget contact fields allowed |
| `support_observability` | **true** | Observability dashboard on |
| `support_web_rollups` | **true** | Web/VK/TG-bot daily rollups on |
| `crm_cockpit` | **true** | Manager cockpit / assignment UI |
| `services.telegram_support.enabled` | **true** | Userbot sync scheduled |

### 2.2 14-day channel rollups (`support_daily_rollups`, since 2026-07-24)

| Channel | Conversations | Incoming | Outgoing | Unanswered | Unresolved &gt;24h | Avg first response |
|---|---:|---:|---:|---:|---:|---:|
| telegram (imported support) | 20 | 181 | 1 | 19 | 0 | — |
| telegram_bot (student bot) | 6 | 9 | 9 | 0 | 4 | ~22 s |
| web | 1 | 0 | 1 | 0 | 0 | — |
| vk | **0** | 0 | 0 | 0 | 0 | — |

Message stores (14 d): `chat_messages` 19 (18 `telegram_bot`, 1 web); `telegram_support_messages` 175. **VK all-time: 0.**

### 2.3 Threads / visitor / leads

| KPI | Value |
|---|---:|
| `support_conversations` total | 45 |
| open | 45 |
| closed | **0** |
| with `visitor_city` | **0** |
| with `entry_url` | **0** |
| with `lead_captured_at` | **0** |
| live `support_visitor_presences` | **0** |
| open CRM `FollowUpTask` (deals) | 0 |
| stale open threads &gt;24 h | 43 |

### 2.4 Infrastructure

- `telegram-support:healthcheck --dry` → **Все Telegram-support сессии здоровы.**
- Account `support` enabled, last successful sync **2026-08-07T19:05Z**.
- Account `harvester` disabled (expected).
- Reply-out tracked window: outgoing 2, tracked-with-`pending_delivery` 1, **pending 1**, with `telegram_message_id` 2.
- Canary technical thread `SupportConversation` id **12** still open (`source_telegram_chat_id=-1003671345641`, last message 2026-07-30) — H594 internal canary precedent from support-subsystem-map; **no new live-fire canary executed this session** (guardrail: production sends require explicit approval).

### 2.5 Topic mix (1-month weighted ranking, prod `support:topic-ranking --months=1 --json`)

Top categories by chat-days: schedule (75), uncategorized (98), technical (53), access (40), payment (27), materials (8), refund (3), certificate (1). Web share highest on uncategorized (~0.12) and access (~0.08).

---

## 3. Parity matrix (capability → code → flag → prod config → canary → KPI)

Legend for **Live state**:

- **verified-live** — code + flag + prod evidence of real traffic or approved canary
- **code-complete/config-off** — code exists; flag off or driver null or zero traffic
- **missing** — not built
- **blocked** — depends on open residual (H2381 / H1200)

| # | Capability | Code | Flag / gate | Prod config 07-08 | Canary / traffic | KPI / proof | Live state |
|---|---|---|---|---|---|---|---|
| 1 | Reverb web chat (real-time + poll fallback) | PublicChatController, widget, Reverb | broadcast stack | Reverb used in prod (roadmap 17-07) | widget on samskrte.ru historically verified | connState not re-probed this session | **verified-live** (prior) / re-probe optional |
| 2 | Guest / lead capture | SupportLeadCaptureService, widget fields | `support_lead_capture` | **true** | 0 `lead_captured_at` | leads=0 | **code-complete, not verified-live** |
| 3 | Visitor geo (city) | VisitorGeoResolver, ResolveVisitorGeoJob | `support_visitor_geo` + `support_geo.driver` | flag **true**, driver **null** | 0 cities | city=0 | **code-complete/config-off** (driver) |
| 4 | Presence monitor | SupportVisitorPresence, PublicPresenceController | `support_visitor_presence` | **true** | 0 live rows | presence=0 | **code-complete, not verified-live** |
| 5 | Proactive human message | VisitorsOnline «Написать» | same as presence | **true** | no approved canary this session | — | **code-complete, not verified-live** |
| 6 | Inbox tabs / assignment | Helpdesk tabs + assignThread | `crm_cockpit` for full assign | **true** | UI in prod | tabs tested in PHPUnit | **verified-live** (code+flag; low web traffic) |
| 7 | Canned replies | MessageTemplate CATEGORY_SUPPORT | always available in Helpdesk | seeded templates (H2339) | seeder residual on prod if not re-seeded | template UI | **code-complete** (seed verify ops) |
| 8 | AI assist (draft only) | SupportAiService | `support_ai_assist` | **true** | LLM events via observability | never auto-sends | **verified-live** (flag on; drafts only) |
| 9 | FAQ suggester / templates | SupportAnswerSuggester, template drafts | answer_suggester + template_drafts | both **true** | scheduled suggest-answers | — | **code-complete** (ops volume not sampled) |
| 10 | Topic taxonomy (rollup) | SupportTopicRule / Assignment / Classifier | web via `support_web_rollups` | rollups **true** | topic-ranking live | schedule/access/payment rows | **verified-live** (TG-heavy) |
| 11 | Required close topic | — | — | — | — | closed_threads=0 | **missing / blocked H2381** |
| 12 | Support dialog follow-ups | FollowUpTask is **CRM deal** only | — | open CRM tasks=0 | — | no `support_conversation_id` link | **missing / blocked H2381** |
| 13 | EdTech sidebar complete | Helpdesk modal partial | — | payments/promises present | — | courses/access/next-lesson gaps | **partial / blocked H2381** |
| 14 | Daily rollups + unresolved&gt;N h | SupportDailyRollup + aggregators | `support_web_rollups` | **true** | 27 rollup rows / 14 d | unresolved_24h bot=4 | **verified-live** |
| 15 | Observability dashboard | SupportObservability | `support_observability` | **true** | healthcheck green | delivery pending=1 residual | **verified-live** (HOLD on pending) |
| 16 | TG support ingest + sync | telegram-support:sync | TELEGRAM_SUPPORT_ENABLED | **true** | 175 msgs / 14 d | account healthy | **verified-live** |
| 17 | TG support reply-out | SupportReplyService + DeliverSupportReply | `support_unified_reply` | **true** | tracked pending=1; thread 12 historical | delivery not clean | **HOLD** (pending + no fresh canary) |
| 18 | TG student bot | TelegramWebhookController source=telegram_bot | bot webhook | traffic present | 18 msgs / 14 d | channel badge | **verified-live** |
| 19 | VK bot | ProcessVkBotMessage source=vk | VK bot env | 0 all-time | — | badge code only | **code-complete, not verified-live** |
| 20 | Inbound email | — | — | — | — | — | **missing / blocked H1200** |
| 21 | Identity | social_accounts canonical | identity backfill cmd | policy in support-identity.md | — | no 4th table | **verified-live** (policy) |
| 22 | Outcome insight | support:parity-report outcome_slices | — | aggregates only | topic access/payment vs promises | section 4 | **partial** (aggregate side-by-side; no per-student join) |

---

## 4. Outcome insight (bounded aggregates)

Printed by `support:parity-report` under `outcome_slices`:

- Topic **access** / **payment|refund** / **schedule** chat-day shares (denominators = sum of topic chat-days).
- School-wide open / window-created / overdue `PaymentPromise` counts (not joined to individual support threads).
- Explicit note: CRM `FollowUpTask` is **not** a support-dialog follow-up (H2381 residual).

No per-student export. Self-resolution / absence correlation remains future work once dialog-level topics and close statuses exist (H2381).

---

## 5. Approved canaries policy (this pass)

| Canary | Status this session |
|---|---|
| Internal technical TG chat (−1003671345641 / conversation 12) | **Not re-fired.** Historical H594 evidence only. New send would need explicit human approval. |
| Owner-approved test mailbox (email) | **N/A** — inbound email not implemented. |
| Student live-fire | **Forbidden** by handoff guardrails. |

---

## 6. Rollback switches (exact)

| Lane | Switch |
|---|---|
| Unified TG reply | `SUPPORT_UNIFIED_REPLY=false` → `php artisan config:clear` |
| AI drafts | `SUPPORT_AI_ASSIST=false` |
| FAQ suggester | `SUPPORT_ANSWER_SUGGESTER=false` + admin toggle off |
| Geo | `SUPPORT_VISITOR_GEO=false` (or keep flag, set driver null) |
| Presence / proactive | `SUPPORT_VISITOR_PRESENCE=false` |
| Lead capture | `SUPPORT_LEAD_CAPTURE=false` |
| Web rollups | `SUPPORT_WEB_ROLLUPS=false` |
| Observability UI | `SUPPORT_OBSERVABILITY=false` |
| TG sync | `TELEGRAM_SUPPORT_ENABLED=false` or disable account row |
| Email | N/A until built |

Full map also in report JSON `rollback`.

---

## 7. Full-parity boundary (NOT-doing)

No telephony/callback centre, enterprise departments, capacity routing, generic deals CRM rebuild, or bot autopilot (AI never auto-sends). Confirmed school-inbox scope only.

---

## 8. GO / HOLD and residuals

**HOLD** — reasons that must clear before full parity GO:

1. H2381 still open: required close-topic, dialog-linked follow-ups, complete EdTech sidebar.
2. H1200 residual: inbound email not started; reply-out needs a **fresh** approved canary with zero pending_delivery.
3. Geo driver still `null` while flag is on — city is not verified-live.
4. Lead capture, presence, proactive: flags on, **zero** production evidence.
5. VK: zero traffic all-time.
6. 0 closed threads / 43 stale open — operator close workflow unused.
7. Tracked reply-out still shows pending_delivery in window.

### Monitoring continuation (14 d)

Re-run weekly until GO:

```bash
php artisan support:parity-report --days=14 --json >> storage/logs/support-parity-$(date +%F).json
php artisan telegram-support:healthcheck --dry
```

Track: channel mix, unresolved&gt;24h, stale open threads, pending delivery, first appearance of visitor_city / lead_captured_at / presence rows after driver/canary decisions.

### Human ops next (not auto)

- Pick geo driver (cloudflare / maxmind) after 152-ФЗ sign-off — brief already exists.
- Approve one internal TG reply-out canary after confirming no IPC lock.
- Finish H2381 then re-run this matrix.

---

## 9. Dependencies status

| ID | Status | Effect on H2382 |
|---|---|---|
| H2381 | open (claimed concurrent Grok) | Capstone may prepare matrix; **cannot** declare full parity |
| H1200 | PARTIAL | Email + fresh reply-out residual explicit |

---

_Dr. Mārcis Gasūns_
