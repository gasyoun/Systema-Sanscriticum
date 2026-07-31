# Verification — GetCourse-parity Wave 1

_Created: 17-07-2026 · Last updated: 17-07-2026_

How each wave-1 deliverable is proven, with exact commands. Index:
[PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_GETCOURSE_PARITY_WAVE1_2026H2.md).
Authored by Opus 4.8 (`claude-opus-4-8[1m]`).

**Ground rule.** A deliverable is proven by a command that **fails before the change and
passes after**. A test that passes both ways proves nothing. Where no such command can exist
because a human gate blocks it, §6 says so explicitly rather than substituting a weaker
proof and calling it done.

All commands run from the worktree root. Test runner is `php artisan test` (the suite was
unblocked on SQLite in commit `16ef950`).

---

## 1. W1-D2 — SRS default restored

### 1.1 Prove the defect first

Run **before** the change. This must currently print `true` — if it prints `false`, the
defect is already fixed and the deliverable is a no-op (IMPLEMENTATION §1 step 1):

```sh
php artisan tinker --execute="var_dump(config('srs.enabled'));"
```

Confirm the three documents that contradict it, so the divergence is on the record:

```sh
grep -n "SRS_ENABLED" config/srs.php
grep -n "в проде OFF" routes/web.php
grep -n "SRS_ENABLED=false" DEPLOY_QUEUE.md
```

### 1.2 Prove the fix

```sh
php artisan test --filter=SrsFlagDefaultTest
```

Must assert, with no `SRS_ENABLED` in the environment:

- `config('srs.enabled') === false`
- `GET /dvaram/koloda` → **404**

The 404 is the load-bearing assertion — it is the student-visible surface R-6 protects. A
config assertion alone would pass even if a route were registered unconditionally elsewhere.

### 1.3 Prove nothing else broke

```sh
php artisan test --filter=Srs
php artisan test
```

The full suite must be green. A test that fails **only** because it assumed the old default
is fixed by setting the flag in that test — never by reverting the default.

### 1.4 Prove the student surface is dark

```sh
grep -n "srs.enabled" resources/views/layouts/student.blade.php
```

Line 104's `@if (config('srs.enabled'))` now evaluates false by default: no SRS menu item
renders. This is the exact R-6 condition ("surfacing it mid-baseline would corrupt the
measurement").

> **Proven when:** §1.1 printed `true` before and `false` after; `SrsFlagDefaultTest` passes;
> the full suite is green.

---

## 2. W1-D1 — the parity production spec

A document deliverable, so verification is structural rather than executable. Each check is
still objective — pass/fail, not opinion.

### 2.1 Structural checks

```sh
test -f docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md && echo "spec exists"
test -f docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.meta.md && echo "metadoc exists"
grep -c "^## " docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md   # expect >= 8
```

### 2.2 Coverage — every ticket, exactly once

```sh
for t in GC-A1 GC-A2 GC-A3 GC-A4 GC-B1 GC-B2 GC-B3 GC-C1 GC-C2 GC-C3 GC-D1 GC-D2 GC-D3 GC-D4; do
  n=$(grep -c -- "$t" docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md)
  [ "$n" -ge 1 ] && echo "$t ok ($n)" || echo "$t MISSING"
done
```

All 14 must appear. A missing ticket means the composition table is not a composition table.

### 2.3 The rules that make wave 2 executable

```sh
grep -n -iE "Payment|money|деньг|never authorise|never grants" docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md
grep -n -iE "@DECIDE|fork|развилка" docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md
```

- The money-core boundary rule ([ARCHITECTURE §2.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)) must be present and stated as a rule, not an aspiration.
- **≥1 named fork, none resolved.** A spec with zero forks is a red flag: the programme is
  under-specified by construction (that is R-1's whole premise), so a pass that surfaces no
  open question almost certainly skipped the analysis.

### 2.4 Doc-contract checks (hook-enforced anyway)

```sh
head -3 docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md | grep -E "_Created: [0-9]{2}-[0-9]{2}-[0-9]{4}"
tail -2 docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md | grep "Dr. Mārcis Gasūns"
grep -nE "\]\((\.\./|docs/|[A-Za-z0-9_-]+\.md)" docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md
```

The third must return **nothing** — committed markdown takes full blob URLs, never relative.

### 2.5 The human check

> Can a fresh agent start GC-C1 from §3 **without** reading the H438 roadmap?

If no, the spec has not replaced the roadmap as the build input and R-1 is not satisfied.
This is the criterion that actually matters; the greps above are necessary, not sufficient.

> **Proven when:** all 14 tickets present with verified states; the boundary rule is stated;
> ≥1 fork named and none resolved; metadoc exists; no relative links; the §2.5 question
> answers yes.

---

## 3. W1-D5 — Memrise export runner

### 3.1 Validator passes the real fixture

The fixture the importer is already tested against — `manifest.json` + `level_01.csv` +
`level_02.csv`:

```sh
python scripts/memrise_export_validate.py tests/fixtures/memrise_sample/
echo "exit: $?"    # expect 0
```

### 3.2 Validator fails a broken export — the check that matters

A validator that only ever passes is not a validator. Prove both failure modes
independently, on a copy:

```sh
cp -r tests/fixtures/memrise_sample "$TMPDIR/mem_broken" && cd "$TMPDIR/mem_broken"

# (a) a level file named in the manifest is gone
rm level_02.csv
python scripts/memrise_export_validate.py . ; echo "missing-level exit: $?"   # expect non-zero

# (b) a column named in the manifest is not in the CSV header
cp -r tests/fixtures/memrise_sample "$TMPDIR/mem_broken2" && cd "$TMPDIR/mem_broken2"
sed -i '1s/.*/wrong,headers,entirely/' level_01.csv
python scripts/memrise_export_validate.py . ; echo "bad-columns exit: $?"     # expect non-zero
```

(b) is the important one: the importer resolves columns **by name** through the manifest's
`columns` map, so a header/manifest mismatch is precisely the failure a human's export can
produce — and precisely what would otherwise surface only at import time, possibly after the
course is gone.

### 3.3 Runner contract, without credentials

```sh
python scripts/memrise_export.py --help
```

Must document `MEMRISE_SESSION` (env, not argv) and `--course` / `--out` / `--dry-run`.

### 3.4 No credential leaked

```sh
git diff origin/main --stat
git diff origin/main | grep -inE "session=|cookie|password|token|MEMRISE_SESSION=[^ ]" 
```

The grep must return nothing but the *documentation* of the variable name — never a value.

### 3.5 Downstream contract still holds

The runner's output must satisfy the importer that already exists:

```sh
php artisan srs:import-memrise tests/fixtures/memrise_sample --dry-run
```

Must report counts and write nothing — proving the contract the runner targets is the one
the importer reads.

> **Proven when:** §3.1 exits 0; **both** §3.2 cases exit non-zero; `--help` documents the
> env var; §3.4 finds no value; §3.5 dry-run reports counts.

**Not provable by an agent:** that the runner works against live Memrise (no login). See §6.

---

## 4. W1-D3 — ESP transport + preflight

### 4.1 Preflight catches the actual production bug

The proof that this deliverable addresses #504 rather than merely adding a driver — the
current dev values, in a non-local env, must be **rejected**:

```sh
APP_ENV=production MAIL_HOST=mailpit MAIL_FROM_ADDRESS="hello@example.com" \
  php artisan mail:preflight ; echo "exit: $?"    # expect non-zero
```

Output must name `mailpit` as the reason. This is exactly the prod state #504 found.

### 4.2 Preflight accepts a plausible ESP config

```sh
APP_ENV=production MAIL_MAILER=smtp MAIL_HOST=smtp.example-esp.com MAIL_PORT=587 \
  MAIL_USERNAME=apikey MAIL_PASSWORD=dummy MAIL_FROM_ADDRESS="hello@samskrte.ru" \
  php artisan mail:preflight ; echo "exit: $?"    # expect 0
```

### 4.3 Local dev is not broken

Mailpit is correct for local — the guard must not fire there:

```sh
APP_ENV=local MAIL_HOST=mailpit php artisan mail:preflight ; echo "exit: $?"   # expect 0
```

### 4.4 Automated

```sh
php artisan test --filter=MailPreflightTest
php artisan test
```

### 4.5 `.env.example` no longer ships the bug silently

```sh
grep -nE "^#? ?MAIL_" .env.example
```

Must show mailpit still present for local **and** a commented production shape pointing at
`docs/mail-esp.md`.

### 4.6 The docs carry the two facts that make an ESP work

```sh
grep -n -iE "SPF|DKIM|DMARC" docs/mail-esp.md
grep -n -iE "mailing|queue|worker" docs/mail-esp.md
```

Both must hit. Without SPF/DKIM/DMARC the mail lands in spam (a switched transport that
still does not arrive); without a `mailing`-queue worker nothing sends at all, since every
Mailable here is `ShouldQueue` (#504 step 4).

### 4.7 No secret in the diff

```sh
git diff origin/main | grep -inE "api[_-]?key|password|secret|token" 
```

Variable *names* only; never a value.

> **Proven when:** §4.1 non-zero naming mailpit; §4.2 and §4.3 zero; `MailPreflightTest` and
> the full suite green; §4.5/§4.6 hit; §4.7 finds no value.

**Not provable by an agent:** that a real email reaches a real inbox — see §6. **#504 stays
open.**

---

## 5. W1-D4 — the five marathon Mailables

### 5.1 Automated

```sh
php artisan test --filter=MarathonMailablesTest
```

Asserts per Mailable: renders; carries the ruled subject; queues on `mailing`; no unresolved
`{placeholder}` remains under a full data set.

### 5.2 The copy is unaltered — the criterion most at risk

The copy is ruled (H1067). The likeliest silent failure of this deliverable is an agent
"improving" it while transcribing. Check subjects against the source:

```sh
grep -n "^\*\*Тема:\*\*" marketing/marathon-2026-08/marathon-email-sequence.md
grep -rn "subject" app/Mail/Marathon*.php
```

Each of the five subjects must match its source verbatim.

```sh
grep -rniE "🎉|🚀|✨|!!!|только сегодня|успей|осталось" resources/views/emails/marathon/
```

Must return **nothing** — the copy is deliberately emoji-free, «вы»-register, no urgency
devices, for an anxiety-sensitive audience.

### 5.3 Placeholder vocabulary is not extended

```sh
grep -rhoE "\{[a-z_]+\}" resources/views/emails/marathon/ | sort -u
```

Every result must be one of `{link}` `{tg_link}` `{date}` `{host}` `{coupon}`
`{recording_link}`. A new placeholder means invention — a `@DECIDE`, not a fix.

### 5.4 Queue + inertness

```sh
grep -rn "ShouldQueue\|onQueue" app/Mail/Marathon*.php   # all five, queue 'mailing'
grep -rn "MarathonWelcomeMail\|MarathonDay1Mail\|MarathonDay2Mail\|MarathonDay3Mail\|MarathonRecordingMail" app/ --include=*.php | grep -v "^app/Mail/"
```

The second must return **nothing outside `app/Mail/`**: the Mailables are deliberately not
wired to any send site ([ARCHITECTURE §5.3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_GETCOURSE_PARITY_WAVE1.md)) — enqueuing mail that cannot be delivered is worse than not sending. A hit here means someone wired a send past the ESP gate.

### 5.5 Full suite

```sh
php artisan test
```

> **Proven when:** `MarathonMailablesTest` passes; five subjects match verbatim; no emoji or
> urgency tokens; placeholder set unextended; all five `ShouldQueue` on `mailing`; no send
> site wired; suite green.

---

## 6. What cannot be proven by an agent — and what to do instead

Stated plainly, because a plan that implied otherwise would be lying about its own gates.

| Claim | Why unprovable here | Substitute proof shipped | Who closes it |
|---|---|---|---|
| "The locked-out student can log in" | needs the ESP account + prod secret + prod `config:clear` — no agent has these | preflight rejects the broken config and accepts a good one (§4.1–§4.3); `docs/mail-esp.md` carries the exact steps | human — [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504), DEPLOY_QUEUE row |
| "The marathon emails send" | same gate | five tested, renderable, queue-correct Mailables (§5) | human, after #504 |
| "The Memrise course is exported" | needs a Memrise login | runner + validator proven against the real fixture (§3), reducing the human step to two commands | human — `@DO`, time-critical |
| "The R20 baseline is 2 weeks old" | needs DEPLOY_QUEUE №25 on prod; the clock has not started | W1-D2 guarantees the baseline is not corrupted by SRS **whenever** it does start | human — deploy №25 |
| "GC-C1 is built" | out of wave 1 by R-1 — the wave buys the spec | the spec makes it executable (§2.5) | wave 2 |

**The rule this table encodes:** when a gate blocks proof, ship the strongest *available*
proof and name the gate. Never relabel a weaker proof as the real one.

---

## 7. Wave-1 exit check

Run once, at the end. Wave 1 is complete when all pass:

```sh
php artisan test                                                      # green
php artisan tinker --execute="var_dump(config('srs.enabled'));"       # false
python scripts/memrise_export_validate.py tests/fixtures/memrise_sample/ ; echo $?   # 0
APP_ENV=production MAIL_HOST=mailpit php artisan mail:preflight ; echo $?            # non-zero
test -f docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md && echo spec-ok
git diff origin/main | grep -icE "api[_-]?key=|password=|secret=|MEMRISE_SESSION=."  # 0
```

Plus, non-mechanically: every human gate in §6 has a filed `@DO`/`@DECIDE` row in
[GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) or
[DEPLOY_QUEUE.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md).
An unfiled gate is an unfinished deliverable — the work is only as useful as the human's next
step is obvious.

_Dr. Mārcis Gasūns_
