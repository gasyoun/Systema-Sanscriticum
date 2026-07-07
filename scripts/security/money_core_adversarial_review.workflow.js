export const meta = {
  name: 'systema-money-core-adversarial-review',
  description: 'Repeatable finder+adversarial-verifier sweep of the Systema Sanscriticum money/access core',
  phases: [
    { title: 'Find', detail: '5 dimension-specific finder agents over the money-core files' },
    { title: 'Verify', detail: 'adversarial-verifier per candidate finding' },
  ],
}

// Captures the 02-07-2026 review that produced H071 (20 CONFIRMED / 0 PLAUSIBLE / 0
// REFUTED after dedup) as a reusable harness. Run this via the Workflow tool from a
// Claude Code session cd'ed into this repo:
//
//   Workflow({ scriptPath: 'scripts/security/money_core_adversarial_review.workflow.js' })
//
// Cadence (H081/D4, MG ruling 03-07-2026): per money-core release AND quarterly —
// NOT per-PR (too slow/noisy for CI). Findings are gitignored (SECURITY_AUDIT*.md,
// public repo) and become one-PR-each fixes under money-core review discipline
// (regression test, no auto-merge, manual MG review) — see AGENTS.md "High-Risk Areas".

const SCOPE_FILES = [
  'app/Models/Payment.php',
  'app/Models/Tariff.php',
  'app/Http/Controllers/PaymentController.php',
  'app/Services/ReferralService.php',
  'app/Services/TeacherSalaryService.php',
  // webhook controllers — enumerate at run time, paths shift as providers are added/renamed
  'app/Http/Controllers/**/*Webhook*.php',
  'app/Http/Controllers/**/*webhook*.php',
]

const DIMENSIONS = [
  {
    key: 'pricing',
    prompt: `Audit pricing/discount logic in ${SCOPE_FILES.join(', ')} (Systema Sanscriticum, a paid Laravel education platform). Look for: promo codes applied more than once, discounts stacking with loyalty/referral in unintended ways, conditional/deposit/trial/0-ruble orders being treated as "real" priced orders, stale or deleted-column reads feeding a displayed price. For each candidate: file:line, the concrete trigger (inputs/state), and the financial consequence. Return a list of candidate findings, not fixes.`,
  },
  {
    key: 'access',
    prompt: `Audit course/lesson access-grant logic in ${SCOPE_FILES.join(', ')} and any access-gate code they call (tariff key resolution, group membership grants, homework/lesson gates, Zoom/schedule link exposure). Look for: raw tariff type written instead of the derived access key, anonymous/unauthenticated routes that leak paid resources, access grants that survive a payment reversal. For each candidate: file:line, concrete trigger, and what unpaid access it grants. Return a list of candidate findings, not fixes.`,
  },
  {
    key: 'payment-state',
    prompt: `Audit payment state transitions in app/Models/Payment.php and app/Http/Controllers/PaymentController.php (Systema Sanscriticum). Look for: chargeback/failed/canceled transitions that don't revoke previously-granted access or credit, webhook handlers that don't re-validate price/amount against current server-side state, double-processing of the same provider callback, deposit consumption edge cases when a purchase is smaller than or spans multiple deposits. For each candidate: file:line, concrete trigger, financial/access consequence. Return candidate findings, not fixes.`,
  },
  {
    key: 'prana-referral',
    prompt: `Audit the referral/prana reward logic in app/Services/ReferralService.php and related Payment/Tariff hooks (Systema Sanscriticum). Look for: rewards paid on non-real orders (deposit/trial/conditional/0-ruble), rewards not reversed when the referred payment is reversed, double-crediting on retried webhooks. For each candidate: file:line, concrete trigger, consequence. Return candidate findings, not fixes.`,
  },
  {
    key: 'salary',
    prompt: `Audit teacher salary/payout calculation in app/Services/TeacherSalaryService.php and app/Filament/Pages/TeacherSalaries.php (Systema Sanscriticum). Look for: month-close double-counting already-paid periods, block payout ignoring refunds/"Расход" rows, already-paid shares (paidShareKeys) being paid again, advances not deducted. For each candidate: file:line, concrete trigger, consequence. Return candidate findings, not fixes.`,
  },
]

const FINDINGS_SCHEMA = {
  type: 'object',
  properties: {
    findings: {
      type: 'array',
      items: {
        type: 'object',
        properties: {
          file: { type: 'string' },
          line: { type: 'number' },
          title: { type: 'string' },
          trigger: { type: 'string' },
          consequence: { type: 'string' },
          severity: { type: 'string', enum: ['HIGH', 'MEDIUM', 'LOW'] },
        },
        required: ['file', 'title', 'trigger', 'consequence', 'severity'],
      },
    },
  },
  required: ['findings'],
}

const VERDICT_SCHEMA = {
  type: 'object',
  properties: {
    verdict: { type: 'string', enum: ['CONFIRMED', 'PLAUSIBLE', 'REFUTED'] },
    trigger: { type: 'string' },
    rationale: { type: 'string' },
  },
  required: ['verdict', 'trigger', 'rationale'],
}

const results = await pipeline(
  DIMENSIONS,
  d => agent(d.prompt, { label: `find:${d.key}`, phase: 'Find', schema: FINDINGS_SCHEMA }),
  review => parallel(
    (review?.findings ?? []).map(f => () =>
      agent(
        `Adversarially verify this Systema Sanscriticum money-core finding by reading the actual current code at the cited location — do NOT assume the earlier finder was right. Finding: "${f.title}" at ${f.file}:${f.line ?? '?'}. Claimed trigger: ${f.trigger}. Claimed consequence: ${f.consequence}. Read the file, trace the logic, and return CONFIRMED only if you can state the exact reproducing trigger against the code as it exists right now; PLAUSIBLE if the mechanism looks real but you can't fully confirm the trigger; REFUTED if the code already guards against this or the claim is wrong.`,
        { label: `verify:${f.file}`, phase: 'Verify', schema: VERDICT_SCHEMA },
      ).then(v => ({ ...f, verdict: v })),
    ),
  ),
)

const allFindings = results.flat().filter(Boolean)
const confirmed = allFindings.filter(f => f.verdict?.verdict === 'CONFIRMED')
const plausible = allFindings.filter(f => f.verdict?.verdict === 'PLAUSIBLE')

log(`${confirmed.length} CONFIRMED, ${plausible.length} PLAUSIBLE, ${allFindings.length - confirmed.length - plausible.length} REFUTED`)

// Caller (the Claude Code session that invoked this workflow) is responsible for
// writing the CONFIRMED/PLAUSIBLE findings into a fresh, dated, gitignored
// SECURITY_AUDIT_money_<YYYY-MM-DD>.md (see .gitignore: SECURITY_AUDIT*.md) and
// filing each as its own PR per the money-core discipline in AGENTS.md.
return { confirmed, plausible, all: allFindings }
