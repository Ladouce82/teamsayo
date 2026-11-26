<?php

return [
    // Trial length for new workspaces (days).
    'trial_days' => (int) env('TEAMSAYO_TRIAL_DAYS', 14),

    // Plans offered. Prices are per seat (user) per month, in EUR, excluding VAT.
    // Annual billing charges 12 × the monthly-equivalent price; monthly billing adds the monthly premium.
    'plans' => [
        'team' => ['name' => 'Team', 'price' => 6.00],
        'business' => ['name' => 'Business', 'price' => 10.00],
    ],
    'monthly_premium' => 0.20,   // monthly billing costs 20% more than annual
    'nonprofit_discount' => 0.25,
    'currency' => 'EUR',
    // VAT added on top of plan prices by PayPal (percentage). Review with your accountant (EU OSS / reverse charge).
    'tax_percent' => env('TEAMSAYO_TAX_PERCENT', '19'),

    // Staging-only helpers.
    'staging_password' => env('STAGING_PASSWORD'),
    'test_accounts_password' => env('TEST_ACCOUNTS_PASSWORD'),
    'allow_demo_data' => env('APP_ENV') === 'staging',

    // Company details for invoices and legal pages.
    'legal' => [
        'company' => env('LEGAL_COMPANY', 'TeamSayo'),
        'address' => env('LEGAL_ADDRESS', '[Company address]'),
        'director' => env('LEGAL_DIRECTOR', '[Managing director]'),
        'email' => env('LEGAL_EMAIL', 'hello@teamsayo.com'),
        'phone' => env('LEGAL_PHONE', '[Phone]'),
        'register' => env('LEGAL_REGISTER', '[Commercial register and number]'),
        'vat_id' => env('LEGAL_VAT_ID', '[VAT ID]'),
    ],
];
