# Transactional email — ESP setup contract

_Created: 18-07-2026 · Last updated: 22-07-2026_

Vendor-agnostic setup contract for production transactional email
([issue #504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504)),
guarded by `php artisan mail:preflight`
([`app/Console/Commands/MailPreflight.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/MailPreflight.php)).
R-3 names a *class* of vendor (Unisender/SendGrid/Mailgun-class), not one — the
vendor choice, account creation, and prod secret are a human `@DECIDE`; see
[GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md).
This doc does not claim mail is delivered — only that the installable path is
documented and provable offline. `#504` stays open until a human completes it.

**D6 ruling (H1449, redirects the ESP-first framing of H1147):** the default
production transport is a **real mailbox over SMTP** (mail.ru / Yandex 360),
not an ESP account — `Option A` below already covers this with zero extra
code, since a mailbox's SMTP endpoint is just another `smtp` mailer target.
This is deliberately homegrown to match H1449's campaign engine, which is
transport-agnostic by design: **R1 escalation** — if staging shows poor
mailbox deliverability/throttling at campaign volume, a human can override D6
to an ESP-as-relay (Postmark/Mailgun, `Option B`/`Option C` below, already
installed) without touching any campaign-engine code. See
[`docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_ANTON_OPS_GAPS_2026H2.md)
and [`docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md)
risk R1. The new `MAIL_THROTTLE_PER_MINUTE` env (H1449 A2, enforced by
`App\Listeners\Email\EnforceMailSendingGuards`) exists specifically because a
mailbox — unlike a dedicated ESP — rate-limits/suspends on bulk send.

## Why `mailpit` in prod is the actual bug

`mailpit` is a **local dev mail-catcher with no outbound relay** — it swallows
every message instead of sending it. Prod was provisioned from
[`.env.example`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.env.example),
which shipped `MAIL_HOST=mailpit` as if it were a real value. `mail:preflight`
rejects this shape outside `APP_ENV=local` (`php artisan mail:preflight`,
non-zero exit + names `mailpit` as the reason). See
[`.env.example`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.env.example)
for the local-vs-production layout.

## Required `.env` keys per driver class

Laravel's `smtp` mailer (`config('mail.mailers.smtp')`) is already generic — any
SMTP-relay ESP (Unisender, SendGrid, Mailgun SMTP, Postmark SMTP, Amazon SES SMTP,
…) works through it with no extra package. Two API-based transports are also
installed (`symfony/mailgun-mailer`, `symfony/postmark-mailer`, both via
`symfony/http-client`) for ESPs that prefer their HTTP API over SMTP. Pick ONE.

### Option A — generic SMTP relay (works with almost any ESP, and with a real mailbox)

```
MAIL_MAILER=smtp
MAIL_HOST=<esp-or-mailbox-smtp-host> # e.g. smtp.sendgrid.net, smtp.eu.mailgun.org,
                                      #      smtp.mail.ru, smtp.yandex.ru (D6 default)
MAIL_PORT=587
MAIL_USERNAME=<esp-username-or-api-key, or the mailbox address>
MAIL_PASSWORD=<esp-password-or-api-secret, or the mailbox password/app-password>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS="<verified sender on your domain>"
MAIL_FROM_NAME="${APP_NAME}"
```

### Option B — Mailgun API transport

```
MAIL_MAILER=mailgun
MAILGUN_DOMAIN=<your-mailgun-domain>
MAILGUN_SECRET=<your-mailgun-api-key>
MAILGUN_ENDPOINT=api.mailgun.net      # or api.eu.mailgun.net for the EU region
MAIL_FROM_ADDRESS="<verified sender on your domain>"
MAIL_FROM_NAME="${APP_NAME}"
```

### Option C — Postmark API transport

```
MAIL_MAILER=postmark
POSTMARK_TOKEN=<your-postmark-server-token>
MAIL_FROM_ADDRESS="<verified sender on your domain>"
MAIL_FROM_NAME="${APP_NAME}"
```

Whichever option is chosen, `MAIL_FROM_ADDRESS` must NOT end in `example.com` —
`mail:preflight` rejects that placeholder outside local too.

## SPF + DKIM + DMARC — without these, mail lands in spam

Switching the transport is not enough on its own: an ESP sending on behalf of a
domain with no authentication records gets filtered as spam by Gmail/Yandex/etc.,
which would look identical to the current failure (mail sent, never seen) even
though the code path is now correct. This is what makes the ESP switch an actual
fix rather than a config change:

1. **SPF** (`TXT` record on the sending domain) — authorizes the ESP's servers to
   send as `@yourdomain`. Most ESPs generate the exact record to add.
2. **DKIM** (`TXT` record, usually `<selector>._domainkey.yourdomain`) — the ESP
   signs outgoing mail; the receiving server verifies the signature against this
   record. Also ESP-generated, one-time setup per sending domain.
3. **DMARC** (`TXT` record at `_dmarc.yourdomain`) — declares the policy for mail
   that fails SPF/DKIM (`p=none` to start, tightened once delivery is confirmed
   clean). Not strictly required to send, but strongly recommended — without it,
   spoofed mail using your domain has no policy to be rejected against.

All three are set once, at the DNS provider for the sending domain, and are
independent of which ESP is chosen. Verify with the ESP's own domain-verification
tool before flipping the deploy switch below.

## The `mailing` queue needs a worker

Every Mailable sent by this app is `ShouldQueue` on the `mailing` queue (see
[`app/Mail/README.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Mail/README.md)) —
so a correct ESP config alone still sends nothing if no worker consumes that
queue (issue #504 step 4). `mail:preflight` warns (does not fail) when
`QUEUE_CONNECTION` is not `sync`, since a non-`sync` queue needs an explicit
worker:

```
php artisan queue:work --queue=mailing
```

In production this should run under Horizon (already used elsewhere in this
repo) or a supervised worker process — not a one-off foreground command.

## Deploy sequence

See the `mail-esp` row in
[DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md):
set the `.env` keys for the chosen driver → `php artisan config:clear` →
`php artisan mail:preflight` (must exit 0) →
`php artisan mail:preflight --send=<addr>` (one real send, opt-in, confirms
end-to-end delivery to an inbox you control).

## What this doc does NOT cover

Picking the vendor, creating the account, and holding the secret are a human
`@DECIDE` — no agent can do these
([ARCHITECTURE §4.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)).
`mail:preflight` proves the installable path is correct; it does not prove a
real email reached a real inbox in production. [Issue #504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504)
stays open until a human completes the steps above.

_Dr. Mārcis Gasūns_
