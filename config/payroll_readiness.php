<?php

declare(strict_types=1);

return [
    // October payroll remains on the legacy calculation engine. The readiness
    // layer is read-only and fails closed when private evidence is absent.
    'target_date' => env('TEACHER_PAYROLL_TARGET_DATE', '2026-10-01'),
    'expected_teacher_count' => (int) env('TEACHER_PAYROLL_EXPECTED_COUNT', 23),
    'evidence_from' => env('TEACHER_PAYROLL_EVIDENCE_FROM', '2026-08-01'),
    'evidence_max_age_days' => (int) env('TEACHER_PAYROLL_EVIDENCE_MAX_AGE_DAYS', 7),

    // Payroll-only channel corrections. The generated historical rate file is
    // not a payout-routing authority: Edgar is paid through Xoom, while other
    // foreign teachers use PayPal.
    'channel_overrides' => [
        'leytan' => 'xoom_mg',
    ],

    // Human-set window starts for teachers with no payout/transfer history
    // (no teacher_payouts, no «Расход» records, no bank evidence). Without an
    // entry here such a teacher stays outside_calculator and is absent from
    // the per-line tables. Keyed by teachers.id. Fail-closed: missing,
    // invalid, or future-dated values are ignored.
    // Гасунс Марцис (prod id 2): MG ruling 29-09-2026 (H5554) resets the
    // legacy 2.6M claim — the payable window counts from 2026-08-01, not from
    // full history depth.
    'teacher_since_overrides' => [
        2 => '2026-08-01',
    ],
    // Private, gitignored manifest prepared by accounting. It contains hashes
    // and dates, not credentials. See docs/TEACHER_PAYROLL_READINESS_2026.md.
    'evidence_manifest_path' => env(
        'TEACHER_PAYROLL_EVIDENCE_MANIFEST',
        storage_path('app/private/payroll/evidence-manifest.json'),
    ),
];
