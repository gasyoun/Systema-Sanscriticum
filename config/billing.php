<?php

declare(strict_types=1);

/**
 * School legal + bank details for company invoices (счёт на оплату юрлицу)
 * and future own-KKT / multi-provider billing docs.
 *
 * COMPANY_INVOICE_ENABLED default OFF — prod-inert until human enable
 * (.env + config:cache). Without filled legal fields the form still accepts
 * claims, but the printable invoice shows a "реквизиты не заполнены" banner
 * so ops know to fill BILLING_* before sending to companies.
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
     * Today fiscalisation is Create Payment Operation With Receipt on Tochka
     * (TochkaPaymentService). Buying a physical/online KKT is ops + accounting;
     * code switch is a later integration (OFD provider / new receipt path).
     */
    'own_kkt' => [
        'planned' => true,
        'status' => 'procurement', // procurement | integrate | live
        'notes' => 'Replace rented Tochka fiscal with school-owned KKT; keep acquiring or not is @DECIDE.',
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
