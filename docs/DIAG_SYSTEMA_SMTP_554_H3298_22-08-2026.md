_Created: 22-08-2026 · Last updated: 05-09-2026_

# DIAG — SMTP 554 / E-channel deliverability (H3298, 22-08-2026)

_Created: 22-08-2026 · Executor: Ox Alpha (`x-preview-f-free`) · Handoff: [H3298](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3298-OxAlpha_Systema-Sanscriticum_marathon-notify-rescue-prep_22.08.26.md)_

Diagnose-only session on prod `root@193.232.229.92` (read-only + tinker SELECTs; zero config/credential changes).

## 1. Root cause

**Primary — free-mailbox reputation, not DNS:** all mail leaves via `smtp.yandex.ru:465` from the free mailbox `rusamskrtam@yandex.ru` (`MAIL_FROM_NAME="ORS LMS"`). Yandex's own outbound filter intermittently rejects with `554 5.7.1 Message rejected under suspicion of SPAM; ya.cc/1IrBc`. SPF/DKIM alignment is NOT our gap here — From domain is yandex.ru itself and auth passes (password present in config, len 16); the filter is content/reputation-based against transactional-commercial mail from a free mailbox.

**Secondary — connection drops:** `Expected response code "250" but got empty code` (TCP drop mid-DATA, throttling-shaped). Hits several classes; queued mailables have a single attempt, so one drop = permanent fail.

**Infra gap that blocks the proper fix:** `samskrte.ru` has **no SPF, no DMARC, no MX** (DoH-verified via dns.google, reg.ru NS) — custom-domain sending cannot start until DNS records are added.

## 2. August damage census (failed_jobs, full month)

| Class × mode | Count | Meaning |
|---|---|---|
| PurchaseConfirmationMail \| 554 | **11** | paid students without receipt |
| PurchaseConfirmationMail \| empty | 1 | same |
| HomeworkSubmittedMail \| empty | 3 | teacher notification lost |
| StudentWelcomeMail \| empty | 1 | welcome lost |
| PasswordResetMail \| rfc | 1 | **not SMTP**: `RfcComplianceException: Email "@nadiyoga_practice"` — Telegram-style handle stored as `users.email`; data-validation bug, separate fix |
| SendTelegramChatMessageJob \| other | 44 | separate channel issue, out of scope here |

89 payments with status=paid since 06-08: most receipts went through (queued mailables, worker OK), ~13% of August receipts confirmed-failed. The 554 wave was concentrated 28-07→06-08 and quiet since — but it is content/volume-dependent: **a marathon blast through this mailbox re-invites the wave exactly at launch.**

## 3. Decision table (MG @DECIDE)

| Option | What | Cost | Effort (human) | Launch-week risk |
|---|---|---|---|---|
| A. Keep free Yandex mailbox | nothing | 0 ₽ | 0 | receipts keep failing; blast likely re-triggers 554 |
| B. **Yandex 360 on own domain (recommended)** | mailbox `mail@samskrte.ru`, verify domain in Yandex 360, add MX+SPF+DKIM+DMARC at reg.ru, swap `.env` MAIL_USERNAME/FROM | ~300 ₽/мес (360 standard) or trial | ~45–60 мин DNS+360 UI | low-moderate; new sender warms up in 2–3 days — decide TODAY to be green by 28-08 |
| C. RU transactional ESP (UniOne / SendPulse SMTP) | dedicated SMTP creds, SPF/DKIM records for subdomain, `.env` MAIL_HOST swap | ~0–800 ₽/мес | ~30 мин signup+DNS | lowest deliverability risk + bounce webhooks; one more vendor |

## 4. Paste-kit (what MG applies after choosing)

### Option B — Yandex 360
1. [ ] reg.ru DNS → samskrte.ru: `MX 10 mx.yandex.net.` · `TXT "v=spf1 include:_spf.yandex.net ~all"` · DKIM TXT from 360 panel · `TXT _dmarc "v=DMARC1; p=none; rua=mailto:mail@samskrte.ru"`
2. [ ] Yandex 360 → create `mail@samskrte.ru` → app password
3. [ ] Server `.env`: `MAIL_USERNAME=mail@samskrte.ru`, `MAIL_FROM_ADDRESS=mail@samskrte.ru`, new password → `php artisan config:cache`
4. [ ] Run smoke E below

### Option C — ESP
1. [ ] Sign up (UniOne recommended), verify samskrte.ru, add its SPF/DKIM TXT at reg.ru
2. [ ] Server `.env`: `MAIL_HOST=<esp-smtp>`, `MAIL_USERNAME=<api-key-login>`, new password, `MAIL_FROM_ADDRESS=mail@samskrte.ru` → `config:cache`
3. [ ] Run smoke E below

### Smoke E (scripted, run after switch)
```sh
cd /var/www/html && php artisan tinker --execute='Mail::raw("H3298 smoke E ".now(), function($m){ $m->to("<mg-email>")->subject("[smoke] H3298 E-channel"); }); echo "SENT_OK";'
```
Expected: `SENT_OK` + message received. Then retry lost receipts:
```sh
php artisan queue:retry --range=<ids>   # list first via failed_jobs ids 1438..1479 (PurchaseConfirmationMail only)
```

## 5. Evidence captured this session

- MAIL config: smtp.yandex.ru:465 ssl, USERNAME/FROM `rusamskrtam@yandex.ru`, runtime password len 16
- 20× 554 in failed_jobs total; last id1479 @06-08 12:27:38; all `PurchaseConfirmationMail`
- August census §2 (tinker aggregate over failed_jobs ≥2026-08-01)
- DoH: no TXT/_dmarc/MX for samskrte.ru (Authority SOA ns1.reg.ru)
- `Payment.php:1461` sync-looking send is actually queued (`implements ShouldQueue`) — payment transactions unaffected by mail failures
- PasswordReset failure root = RFC-compliance crash on malformed stored email, independent of SMTP

_Dr. Mārcis Gasūns_
