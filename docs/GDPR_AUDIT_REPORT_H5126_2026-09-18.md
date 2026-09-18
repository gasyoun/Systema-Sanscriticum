# GDPR/privacy audit — Systema-Sanscriticum (H5126 night run)

_Created: 18-09-2026 · Last updated: 18-09-2026_

Machine-readable findings: [GDPR_AUDIT_FINDINGS_H5126_2026-09-18.json](GDPR_AUDIT_FINDINGS_H5126_2026-09-18.json)

## Scope

- **Materials reviewed:** full tree at `37b88c00` (origin/main worktree `h5126-drain`) — app/, config/, database/migrations, routes/, resources/views.
- **Date of audit:** 2026-09-18 (night run, H5126).
- **Excluded:** vendor/, node_modules/, git history, prod data contents (code-only audit), runtime .env values.
- **Assumptions:** school = controller; RU/152-ФЗ primary jurisdiction; GDPR assessed for EU-resident students where applicable.
- **Missing context:** DPAs (PayPal/OpenRouter/Yandex), RoPA, privacy-notice full text (served from DB via `/dokumenty/{slug}`, not in code).

## Processing map

| Activity | Data categories | Recipients / region | Retention |
|---|---|---|---|
| Student accounts | name, email, password hash, telegram_username, vk_id, phone (partial) | internal / RU | unclear — no erasure path |
| Payments | transactions, identity, balances | Tochka (RU), PayPal (US) | long (finance) |
| Messaging bots | ids, chat ids, phone digits, content | Telegram (UAE/US), VK (RU), SMS.ru (RU) | unclear |
| Support chats | chat logs + contact auto-link | Telegram infra + internal | unclear |
| AI responder | message text as prompt | DeepSeek via OpenRouter (US) | n/a |
| Lead exports | contact, email, phone, utm | internal | file lifetime |
| Logs | incl. student email, phone digits | internal | 14 days |
| Backups | full DB | local + Yandex Disk (RU), encrypted | 7d/16d/8w/4m |

## Findings

| ID | Sev | Conf | Type | Articles | Title |
|---|---|---|---|---|---|
| F-01 | High | High | confirmed | 5(1)(c), 25, 32 | Student email + phone digits in plaintext application logs |
| F-02 | High | High | evidence_gap | 15, 17, 20 | No DSAR erasure/access/export workflow |
| F-03 | Medium | Medium | likely | 28, 44, 46 | Chat content → DeepSeek via OpenRouter (US), no DPA/TIA documented |
| F-04 | Medium | Medium | evidence_gap | 28, 44-46 | PayPal payer data, no DPA/SCC artifact |
| F-05 | Low | Medium | advisory | 32, 44 | Yandex Disk backup replication — encrypted, retention defined, posture undocumented |
| F-06 | Low | Medium | advisory | 5(1)(e) | Free-form `users.notes` legacy personal data, no retention rule |

### Details (key evidence)

- **F-01** — [app/Models/Payment.php:1651](../../app/Models/Payment.php): `Log::info("…Студент: {$student->email}…")`; same at :1655; [app/Models/User.php:783](../../app/Models/User.php), :830; [app/Services/Messaging/SmsRuChannel.php:47](../../app/Services/Messaging/SmsRuChannel.php): `'phone' => $digits`. Fix: log user_id, mask phone; redaction helper + sweep.
- **F-02** — command census: no user erasure/export mechanism. Fix: artisan `gdpr:erase-user` / `gdpr:export-user` (payments retained per finance-law constraint, documented).
- **F-03** — [app/Jobs/ProcessVkBotMessage.php:267](../../app/Jobs/ProcessVkBotMessage.php); mitigating: [app/Services/Bot/CuratorAi.php](../../app/Services/Bot/CuratorAi.php) has an explicit never-fall-back-to-OpenRouter privacy guard. Fix: DPA/TIA on file, strip identifiers from prompts, extend guard to all AI call sites.
- **F-04/F-05/F-06** — see JSON for evidence and fixes.

## Positives

1. Consent flows present: 152-ФЗ newsletter opt-in (TrialController), donation name-publication consent, RQ4 study consent.
2. Log retention 14 days ([config/logging.php:73](../../config/logging.php)).
3. Lead CSV export runs through `FormulaGuard` (H5086 remediation).
4. AI lane has an explicit privacy no-fallback guard.
5. Backups encrypted (`BACKUP_ARCHIVE_PASSWORD`) with a defined retention ladder.

## Summary

- Confirmed issues: 1 · Likely issues: 1 · Evidence gaps: 2 · Advisory: 2
- DPIA recommended: **no** (bounded code-only evidence; AI responder is support Q&A, not evaluation/scoring with legal effect).

## Recommended next actions

1. F-01 log redaction sweep (bounded, mechanical — highest leverage per effort).
2. F-02 DSAR command pair (`gdpr:erase-user` / `gdpr:export-user`) with payment-retention constraint.
3. F-03/F-04 DPA/TIA documentation pass (org-level, Uprava compliance file).

## Disclaimer

This is a technical GDPR audit of the provided materials. It is not legal advice and does not constitute a compliance determination. Consult a qualified DPO or data protection lawyer for legal questions, supervisory authority engagement, or material decisions.

_Гасунс_
