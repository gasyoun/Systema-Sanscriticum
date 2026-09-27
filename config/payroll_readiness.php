<?php

declare(strict_types=1);

return [
    // October payroll remains on the legacy calculation engine. The readiness
    // layer is read-only and fails closed when private evidence is absent.
    'target_date' => env('TEACHER_PAYROLL_TARGET_DATE', '2026-10-01'),
    'expected_teacher_count' => (int) env('TEACHER_PAYROLL_EXPECTED_COUNT', 23),
    'evidence_from' => env('TEACHER_PAYROLL_EVIDENCE_FROM', '2026-08-01'),
    'evidence_max_age_days' => (int) env('TEACHER_PAYROLL_EVIDENCE_MAX_AGE_DAYS', 7),

    // Private, gitignored manifest prepared by accounting. It contains hashes
    // and dates, not credentials. See docs/TEACHER_PAYROLL_READINESS_2026.md.
    'evidence_manifest_path' => env(
        'TEACHER_PAYROLL_EVIDENCE_MANIFEST',
        storage_path('app/private/payroll/evidence-manifest.json'),
    ),
];
