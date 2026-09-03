# VERIFICATION — Telegram support leverage

_Created: 03-09-2026 · Last updated: 03-09-2026_

Index: [PLAN_SYSTEMA_TELEGRAM_SUPPORT_LEVERAGE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_TELEGRAM_SUPPORT_LEVERAGE_2026H2.md). What proves each deliverable works, the gate every student-visible surface passes, and the register of risks and spikes. Ruling V2 sets the standing bar: a feature test per resolver and per lane on SQLite with `Carbon::setTestNow`, an artisan smoke per new integration, and the full suite green before each pull request.

The 31-08 incident behind that bar: eight green tests proved the wrong half of a contract. Every acceptance line below therefore names the observable it checks, not the function it calls.

## 1. Acceptance per deliverable

### Wave 1

| Deliverable | Proof |
|---|---|
| Five new fact resolvers | One feature test per resolver on SQLite with time pinned: a seeded student with a known homework, balance, access, certificate and schedule state gets the expected text, and a student with no such record gets null rather than an invented answer |
| Draft-only enforcement for balance, access, certificate | A test asserting no auto-send path exists for those categories even when they are added to the live list by hand — the fence is code, not configuration |
| Finance escalation | A test where the computed balance differs from the claimed amount and a follow-up task is created for the finance lead with no outbound message to the student |
| One-tap send on every hint type | A test per hint type asserting a `TelegramSendGuard` claim precedes the send, the daily cap is consulted, and a second tap on the same draft sends nothing |
| Filament draft queue | A Filament page test covering Send, Edit and Skip, including that Edit sends the edited text and not the original |
| Auto link-invite | A test that an unlinked contact's first DM triggers exactly one invite, a second DM inside the cooldown triggers none, and no code path matches a contact to a student by phone or username |
| Weekly unlinked report | Command output over seeded data lists contacts with at least two DMs and no linked user |
| SLA timer | A time-travelled test crossing 15 and 60 minutes inside working hours, a run inside quiet hours that pings nobody, and a conversation with an outbound message that is never pinged |
| Wave exit | Seven days of shadow data, at least twenty would-send events per category, curator-reviewed precision at or above 95 %, then a human flips the flag |

### Wave 2

| Deliverable | Proof |
|---|---|
| Router shadow | A test asserting the classifier's verdict is written to `SupportAiReplyEvent` and that the delivered category still comes from `categorize()`; a diff of outbound traffic during the shadow window that shows zero change |
| One report builder | The same seeded window produces identical numbers in the command, the digest and the Filament page; a test pins that identity so the three surfaces cannot drift apart again |
| Funnel transparency | Every reported rate prints its numerator and denominator, asserted in the builder test |
| Weekly precision sheets | A curator can mark 30 samples per live category; a category scoring below 95 % leaves the live list in the test, and two consecutive misses keep it out |

### Wave 3

| Deliverable | Proof |
|---|---|
| `knowledge_chunks` and providers | A migration test on SQLite storing and reading back a 4-dim fixture vector; a test that `NullEmbeddingProvider` yields the BM25 ranking unchanged plus one warning line |
| `knowledge:index` | An artisan smoke that indexes a small corpus against the live tunnel and reports rows written; re-running it with no content change writes nothing |
| `HybridRetriever` | Recall@5 at least 83 % and MRR at least 0.713 on the 80Q set, and hybrid at least matching BM25 on the fresh 100Q set; a fusion below BM25 fails the wave |
| Fresh 100Q set | The committed fixture contains no names, phone numbers or handles, asserted by a masking test over the file itself |
| Latency | p95 retrieval latency at most 2 s measured through the tunnel; a slower path must fall back rather than block the DM handler |
| Score floors | Re-derived with `faq:score-floor` and recorded in the pull request body |

### Wave 3b

| Deliverable | Proof |
|---|---|
| Shadow local generation | A test asserting both drafts are logged and only the cloud draft reaches a curator surface; an artisan smoke pinging the tunnel's tag list and running one generation |
| Cloud-versus-local agreement report | The report runs over seeded events and reports an agreement rate with its denominator |
| Clarifying-question slot | A two-turn test: first inbound sets the slot, the reply inside six hours fills it and re-runs the same resolver, an expired slot is ignored, and a second miss hands the thread to a curator with no third question |
| Clarifier shadow | `dm_shadow_would_ask` events accumulate with zero change to outbound traffic, asserted the same way as the wave-2 router shadow |

### Standing checks before every pull request

- `./vendor/bin/pint` clean.
- `php artisan test` green, once, before the pull request rather than per commit — the suite runs about eleven minutes.
- Money or access diffs follow `/money-pr-land`: worktree off `origin/main`, flag default OFF, money and access tests mandatory, the no-auto-merge marker present.
- A new `env()` key means `php scripts/generate_env_inventory.php` in the same pass.
- No PII in any file about to be committed. Finding some is a stop condition, not a cleanup task.

## 2. Risks and spikes register

| # | Risk | Likelihood | What it costs | Mitigation or spike |
|---|---|---|---|---|
| 1 | The linked-user share stays too low for fact resolution to matter | Medium | Wave 1 lands and the zero-typing share barely moves | The link invite is wave 1 deliverable 5, ahead of the shadow week; the weekly unlinked report measures the ceiling directly. Spike before wave 1 closes: count how many of the last 226 DMs came from linked contacts |
| 2 | A balance draft is wrong because the money model has a case the resolver misses | Low | A student is told a false number by a curator who trusted the draft | Draft-only forever, curator in the loop, disputed balances escalate instead of answering; money tests mandatory |
| 3 | The 15-minute SLA ping becomes noise and curators mute it | Medium | The escalation is ignored and the response-time target is missed anyway | Quiet hours, two curators rather than a broadcast, and a review of ping volume in the first weekly report |
| 4 | The tunnel to `.92` is down when wave 3 or 3b work runs | Medium | Retrieval and local generation stall mid-wave | `NullEmbeddingProvider` keeps the lane on BM25; the tunnel being down at build time is stop condition 6, and the tunnel-independent steps finish first |
| 5 | Hybrid retrieval scores below BM25 after fusion | Medium | Wave 3 produces no gain | BM25 stays the floor by contract; the fresh 100Q set exists precisely so a tuning win on 80Q cannot hide a loss |
| 6 | The fresh eval set leaks student PII into a public repository | Low | A privacy incident in a committed fixture | Masking runs before commit and is itself asserted by a test; the ORS-FAQ dialog store is out of scope entirely |
| 7 | The clarifying question annoys students or loops | Low | A student-visible regression on the lane's most sensitive surface | One question maximum, six-hour expiry, second miss hands off, and a full shadow week before any student sees it |
| 8 | The classifier's gate is never met and wave 2 leaves a permanent shadow path | Medium | Dead code carrying maintenance cost | The shadow costs one event row per message; if the gate is unmet by 31-10-2026 a human decides whether to remove the path |
| 9 | Auto-send precision drops after a category goes live | Low | Wrong answers reach students | Weekly 30-sample review, per-category kill switch, two consecutive misses force a re-shadow |
| 10 | A new send point misses its guard claim and double-sends | Low | The 24-08-2026 incident repeats | Every send path in this plan is tested for the claim; the guard contract is restated in the architecture document |

**Spikes to run before committing to the design.** Two, both cheap: measure the linked-contact share of recent DMs before wave 1's shadow week closes (risk 1), and time one `bge-m3` embedding round trip through the tunnel before building the indexer (risk 4). Neither blocks the start of wave 1.

_Dr. Mārcis Gasūns_
