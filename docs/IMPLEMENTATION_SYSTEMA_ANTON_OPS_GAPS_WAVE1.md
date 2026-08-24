# IMPLEMENTATION — Wave 1 (email revival + homegrown campaigns)

_Created: 22-07-2026 · Last updated: 22-07-2026_

File-level, step-ordered build sequence for **Wave 1 only** (D9). Architecture:
[`docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md).
Acceptance per step: [`docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md).
Each step names the files it touches and its dependency on prior steps. Nothing here touches
money code; all new mail is flag-gated OFF; no live send — tests use Laravel's `Mail::fake()` /
array transport and staging targets only (D12).

Waves 2–4 get their own IMPLEMENTATION docs when each starts (per the roadmap-per-wave-handoff
convention); this doc is Wave 1 so an agent can execute it unattended today.

---

## Part A — transactional revival (W1a)

**Step A1 — mailer config switch (no secret committed).**
Files: `config/mail.php`, `.env.example`, `config/features.php` (unchanged here — transactional
isn't flag-gated, it's a config swap). Confirm `MAIL_MAILER` reads from env; document the
mailbox-SMTP settings (host/port/encryption/username) in `.env.example` with placeholders
only. Do **not** commit real creds (D12). Depends on: none.

**Step A2 — send throttle + suppression list.**
Files: `app/Models/SuppressedEmail.php` (new), `database/migrations/xxxx_create_suppressed_emails_table.php` (new: `email` unique, `reason`, `suppressed_at`), a queued-mail middleware or a
`Mailable` base guard that (a) skips any address in `SuppressedEmail`, (b) respects a per-minute
cap from `config/mail.php` (`throttle_per_minute`, env-backed, never hardcode). Depends on: A1.

**Step A3 — bounce/complaint capture.**
Files: `app/Http/Controllers/Api/MailWebhookController.php` (new, if the mailbox relay supports a
bounce webhook) OR a scheduled IMAP bounce-scan command `app/Console/Commands/ScanBounces.php`
(new) — pick per what mail.ru/Yandex 360 exposes (D11: if unclear, implement the scheduled
IMAP-scan default and log the choice). On hard bounce → insert `SuppressedEmail`. Route (if
webhook): `routes/api.php`, secret-guarded like the other webhooks. Depends on: A2.

---

## Part B — homegrown campaign engine (W1b), all behind `email_campaigns`

**Step B1 — feature flag.**
Files: `config/features.php` — add `'email_campaigns' => (bool) env('EMAIL_CAMPAIGNS', false)`
with a doc comment in the repo idiom (explain: OFF = no `CampaignResource`, tracking endpoints
404, sender early-returns). Depends on: none (do this first so every later step reads the flag).

**Step B2 — models + migrations (additive).**
Files (new): `app/Models/Campaign.php`, `app/Models/CampaignRecipient.php`, and migrations
`xxxx_create_campaigns_table.php` (`subject`, `body_html` longtext, `segment` json, `status`
enum-string draft/sending/sent, `sent_at` nullable), `xxxx_create_campaign_recipients_table.php`
(`campaign_id` fk, `user_id` fk nullable, `email`, `pixel_token` unique, `sent_at`, `opened_at`,
`clicked_at`, `bounced_at`, `resend_of_id` self-fk nullable). Index `(campaign_id, opened_at)`
for the догон query. Casts + relations per `docs/ARCHITECTURE_SYSTEMA_ANTON_OPS_GAPS.md`.
Depends on: B1.

**Step B3 — segment resolver.**
Files: `app/Services/Email/CampaignSegmentResolver.php` (new) — turns a `segment` json filter
into a `User`/`Lead` query. Start with a minimal filter vocabulary (all-subscribers, a course's
students, a `LeadStage`); D11 default when a requested filter isn't defined: resolve to empty +
log, never to all-users (fail-safe). Reuse existing `Lead`/`User` scopes. Depends on: B2.

**Step B4 — tracking endpoints (token-scoped, no PII in URL).**
Files: `app/Http/Controllers/Email/TrackingController.php` (new), `routes/web.php` (add
`GET /e/o/{token}.gif` open pixel, `GET /e/c/{token}/{link}` click redirect). Both resolve the
`CampaignRecipient` server-side from `pixel_token`; open stamps `opened_at` (idempotent — only
first), click stamps `clicked_at` and 302s to the decoded target. When `email_campaigns` is
OFF → 404. The click target is validated against an allowlist/signature so the redirect can't be
turned into an open redirect. Depends on: B2.

**Step B5 — link + pixel rewriter.**
Files: `app/Services/Email/CampaignHtmlRenderer.php` (new) — given `body_html` + a recipient,
rewrite every `<a href>` to `/e/c/{token}/{link}` and append the open pixel `<img>`. Anton's
"кнопка → персональная ссылка" made native. Depends on: B4.

**Step B6 — sender job.**
Files: `app/Jobs/SendCampaignRecipient.php` (new), `app/Services/Email/CampaignSender.php` (new)
— dispatch one queued job per `CampaignRecipient`, each: skip if suppressed (A2), render via B5,
send a generic `CampaignMail` `Mailable` (new, `app/Mail/CampaignMail.php`), stamp `sent_at`.
Respects the A2 throttle. Early-returns entirely if `email_campaigns` OFF. Depends on: A2, B3, B5.

**Step B7 — resend-to-non-openers (догон).**
Files: `app/Services/Email/CampaignSender.php` (extend) — a `resend()` that creates a new
`Campaign` targeting recipients of the source campaign with `opened_at IS NULL`, links each new
`CampaignRecipient.resend_of_id`. Exposed as a `CampaignResource` action in B8. Depends on: B6.

**Step B8 — Filament admin.**
Files: `app/Filament/Resources/CampaignResource.php` (new) + pages — compose (`tiptap` editor,
already a dep), pick segment, send, and read open/click counts (aggregate over
`CampaignRecipient`); a "Догнать неоткрывших" action calling B7. Copy the shape of the existing
`AnnouncementResource`. Gate the whole resource on `email_campaigns` + an admin role. Depends
on: B7.

**Step B9 — changelog + activation row.**
Files: `CHANGELOG.md` (`[Unreleased]` → `### Added`), `DEPLOY_QUEUE.md` (append an activation
row: set `MAIL_MAILER` + mailbox creds, run the new migrations, flip `EMAIL_CAMPAIGNS=true`,
`config:cache`; note the SPF/DKIM/DMARC + first-segment-on-staging prerequisites). Per the
changelog-cadence rule this is mandatory in the same pass. Depends on: all above.

---

## Step order (dependency graph)

```
A1 → A2 → A3
B1 → B2 → B3 ─┐
        B2 → B4 → B5 → B6 → B7 → B8 → B9
   A2 ─────────────────┘ (B6 needs A2)
```

Ambiguity policy (D11): where a step lists a fork (A3 webhook-vs-IMAP, B3 filter vocabulary),
take the marked default, log it in the wave handoff, press on. Stop only per the §4 autonomy-
contract stop conditions in the PLAN — chiefly if a step would require a real send or money-code
change (it won't, if built as above).

_Dr. Mārcis Gasūns_
