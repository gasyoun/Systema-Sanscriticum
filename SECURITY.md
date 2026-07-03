# Security Policy

_Created: 03-07-2026 · Last updated: 03-07-2026_

## Reporting a vulnerability

**Do not open a public GitHub issue for security problems.**

This is a paid education platform that processes personal data and payments.
If you find a vulnerability — an authentication/authorization bypass, a
payment or pricing defect, an information leak, an injection, or exposed
credentials/personal data — report it privately:

- **Preferred:** GitHub → the repository's **Security** tab → **Report a
  vulnerability** (private vulnerability reporting is enabled on this repo).
- **Email:** ai.chatgpt.ocr@gmail.com

Please include enough to reproduce: the endpoint or file, the exact steps, and
the impact (what an attacker gains). We aim to acknowledge within a few days.

## Scope

In scope: the Laravel application in this repository (`app/`, `routes/`,
`config/`, `resources/`), its webhooks, its Filament admin, and its deploy
tooling (`deploy.sh`, `docker-compose.yml`).

Out of scope: third-party services (the payment gateway, Zoom, Telegram/VK
infrastructure) except where our integration mishandles their data or trust
boundary.

## What we treat as security-sensitive

- **Money & access core** — anything under `app/Models/Payment.php`,
  `app/Models/Tariff.php`, `app/Http/Controllers/PaymentController.php`,
  `app/Services/ReferralService.php`, `app/Services/TeacherSalaryService.php`,
  and the webhook controllers. Changes here get manual review and are never
  auto-merged.
- **Personal data** — student names, emails, password hashes, IPs. These must
  never be committed to the repository (see the `.gitignore` dump patterns).
- **Secrets** — never commit `.env`, API keys, session strings, or webhook
  secrets. Secret scanning and push protection are enabled; a blocked push
  means rotate the secret, do not bypass.

## Supported versions

Only the `main` branch (what production runs) is supported. There are no
backports to older tags.

_Dr. Mārcis Gasūns_
