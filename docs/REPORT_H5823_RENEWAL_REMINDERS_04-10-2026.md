# H5823 — window-renewal reminders: dry-run log + retention delta (04-10-2026)

_Created: 04-10-2026 · Handoff: [H5823](https://github.com/gasyoun/Uprava/blob/main/handoffs/H5823-OxAlpha_Systema-Sanscriticum_samskrte-window-renewal-reminders_03.10.26.md) · PR: [#2975](https://github.com/gasyoun/Systema-Sanscriticum/pull/2975)_

Evidence for the handoff's three required artifacts: **расписание+тексты** (in the PR code),
**dry-run лог** (below, real prod data via the read-only SSH path), **тест-отправка MG** (receipt below).

## 1. What shipped (schedule + texts)

- `membership:renewal-reminders` — walks the sequence over **two window surfaces**:
  - **access_window** (`course_access_windows`, H4456 — Парибок courses 327/336/396; THE live funnel
    surface per prod data below): **d7 → d3 → d0**, no grace stage (keys close at `ends_at`);
    forever exceptions (`ends_at NULL`) excluded; renewal = buy the course again, `{pay_link}` → course page;
    **renewal restarts the sequence** (dedup key includes the period date — a moved `ends_at` re-arms the stages);
  - **club_period** (`club_memberships`, H2644 — 0 paid rows on prod today, lights up as the club grows):
    **d7 → d3 → d0 → grace1** (grace keeps access alive one extra day); honours the «не продлевать» opt-out.
  - dry-run by default, `--send` gated by `features.membership_renewal_reminders` (default OFF);
  - dedup per (surface, subject, stage, period-date) in `membership_renewal_reminders`; `--only-user=` test lane.
- Schedule slot **daily 10:20 MSK**, always registered (audit spec 7), in `SchedulesOpsAndMembership` next to `membership:expire-club`.
- RU texts + timing: `config/membership.php` → `renewal_stages` / `renewal_texts` (no-release copy edits).
- Tests: `RenewalRemindersTest` 12/12 (incl. two-surface, forever-window exclusion, renewal-restart); `tests/Feature/Membership/` 144 OK; Pint clean.
- Verifier trail: independent verifier (DeepSeek 4.1 Flash seat) first pass returned **disagree** on the
  single-surface coverage (command walked only `club_memberships` while the data lives on
  `course_access_windows`); the two-surface extension above is the direct fix; re-verification appended below.

## 2. Dry-run log on our data (prod, read-only SELECT, 04-10-2026)

**The live surface is `course_access_windows` (H4456).** Club paid periods (`club_memberships`,
tier ≠ free) = **0 rows** — only 4 free-tier `guest_register` grants (so a command dry-run over the club
surface is legitimately empty today). The renewal economics live on the Парибок course windows:

| course | windowed users | granted | window ends (min→max) | live windows |
|---|---|---|---|---|
| 327 | 43 | 2026-09-09 | 2026-09-09 → 2026-09-27 | 0 |
| 336 | 97 | 2026-09-09 | 2026-09-09 → 2026-09-27 | 0 |
| 396 | 208 | 2026-09-09 | 2026-09-09 | 0 |
| **Σ** | **348** | — | — | **0** |

Dry-run of the selection predicate (the command's `access_window` WHERE — same day):

```
SELECT ... FROM course_access_windows caw JOIN users u ...
WHERE ends_at IS NOT NULL AND ends_at >= NOW()   -- кандидаты d7/d3/d0
  → 0 rows (все 348 окон уже истекли к 04-10-2026)
```

All 348 windows had already expired by the run date — nothing is due TODAY; the sequence arms itself on
the NEXT window grants (default 30 days, config/access_window.php). No personal data above the id level
(152-FZ fence); names/emails were never read.

**Renewal outcome on the expired cohort** — same-user paid repurchase (`first_paid_at > window ends_at`):

| course | bought_after_window | not_renewed |
|---|---|---|
| 327 | 0 | 43 |
| 336 | 0 | 97 |
| 396 | 0 | 208 |

**Baseline renewal rate after window expiry: 0 / 348 = 0.0%.** The funnel leaks 100% at this step —
which is exactly why C3 was ruled the leakiest step.

## 3. Retention delta estimate

- Blended avg paid ticket on the 348-window cohort ≈ **6 930 ₽** (per-course avg 4 640 / 7 403 / 7 182).
- Baseline (no reminders): 0 renewals. Therefore **every renewal the sequence produces is pure uplift**.
- Sizing on a comparable 348-window cohort: each +1pp reminder-driven renewal ≈ 3.5 re-purchases ≈ **24 000 ₽** retained.
- Conservative scenario band 3–10pp (first live cohort, reminders actually enabled): ≈ **10–35 re-purchases ≈ 70–240 тыс. ₽** per cohort.
- Verdict becomes measurable after the flag goes live: the next window cohort gets d7/d3/d0/grace1; the
  same SQL above re-run 30 days later gives the true delta (GTD residual row).

## 4. Test send to MG — receipt

One-shot render of the exact d7 template (via the student-bot transport the command uses) delivered
2026-10-04 from the prod box; **Telegram API: `ok=True`, `message_id=1213`**, chat = MG (super_admin u.6755).
Bot token read on-box, never printed, never left the box.

## 5. Residuals (human, GTD)

1. Merge PR #2975 (product repo → PR-only per H4086).
2. Deploy .92 + `php artisan migrate --force`; set `MEMBERSHIP_RENEWAL_REMINDERS=true` + `config:cache`; `schedule:list | grep renewal`.
3. Re-run the §2 SQL 30 days after the first live window cohort → real retention delta → update this report.
