<?php

declare(strict_types=1);

/**
 * School legal + bank details for company invoices (счёт на оплату юрлицу)
 * and future own-KKT / multi-provider billing docs.
 *
 * Defaults in code remain OFF/empty (safe for CI). Prod (31-07-2026, H2017):
 * COMPANY_INVOICE_ENABLED=true and BILLING_* filled from Tochka open-banking
 * customer + site footer — see docs/TOCHKA_PAYMENT_METHODS_AUDIT_2026-07-31.md.
 * Incomplete legal fields: printable invoice still works but shows a banner.
 */
return [

    'company_invoice' => [
        'enabled' => (bool) env('COMPANY_INVOICE_ENABLED', false),
    ],

    /**
     * Planned — not wired. YooMoney / YooKassa second acquiring path.
     * Recorded so product/roadmap sessions do not re-decide "should we?".
     * Implementation is explicitly deferred (H2017).
     */
    'yoomoney' => [
        'planned' => true,
        'enabled' => false,
        'notes' => 'Second acquirer after Tochka; not built. Timepad-class wallet method.',
    ],

    /**
     * Own cash register (ККТ) instead of renting Tochka's fiscal cloud.
     * Integrated: Digital Kassa API v2.1 behind features.digitalkassa_receipts
     * (IssueDigitalKassaReceiptJob after the Tochka paid webhook). Flag OFF →
     * legacy Create Payment Operation With Receipt on Tochka.
     */
    'own_kkt' => [
        'planned' => true,
        'status' => 'integrate', // procurement | integrate | live
        'notes' => 'Digital Kassa receipts; Tochka keeps acquiring (/payments). Flip to live after the flag is ON in prod.',
    ],

    'legal' => [
        'name' => env('BILLING_LEGAL_NAME', ''),
        'inn' => env('BILLING_INN', ''),
        'kpp' => env('BILLING_KPP', ''),
        'ogrn' => env('BILLING_OGRN', ''),
        'ogrnip' => env('BILLING_OGRNIP', ''),
        'address' => env('BILLING_LEGAL_ADDRESS', ''),
        'bank_name' => env('BILLING_BANK_NAME', ''),
        'bik' => env('BILLING_BIK', ''),
        'account' => env('BILLING_ACCOUNT', ''),
        'corr_account' => env('BILLING_CORR_ACCOUNT', ''),
        'email' => env('BILLING_EMAIL', ''),
        'phone' => env('BILLING_PHONE', ''),
    ],
];
