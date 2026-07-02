<?php
/**
 * PROFORMA INVOICE PDF configuration for Gloria Trading.
 *
 * This file intentionally contains only non-secret company, bank, and document
 * defaults used for customer-facing PDF generation. Sensitive credentials must
 * not be stored here.
 */

return [
    'company' => [
        'name' => 'Gloria Trading',
        'address' => '2-4-5 Sogodai, Natori-shi, Miyagi, 982-0046, Japan',
        'tel' => '+81-22-398-4475',
        'fax' => '+81-22-398-4476',
        'email' => 'info@gloriatrading.com',
    ],
    'bank' => [
        'bank_name' => 'MUFG BANK, LTD',
        'swift_code' => 'BOTKJPJT',
        'branch_name' => 'SENDAI BRANCH',
        'branch_phone' => '+81-22-222-7191',
        'account_name' => 'GLORIA TRADING MUNEO SATO',
        'account_number' => '314-0059629',
        'branch_address' => '2-1, Chuo 2-chome, Aoba-ku, Sendai, Miyagi, 980-0021 Japan',
    ],
    'document' => [
        'invoice_prefix' => 'PI',
        'currency' => 'USD',
        'port_of_loading' => 'Sendai Port, Japan',
        'incoterms' => 'CIF',
        'payment_terms' => 'T/T in advance',
        'footer_note' => 'This is a Proforma Invoice only and not a demand for payment.',
    ],
    'assets' => [
        'logo' => dirname(__DIR__) . '/frontend/assets/proforma/company-logo.jpg',
        'stamp' => dirname(__DIR__) . '/frontend/assets/proforma/company-stamp.jpg',
    ],
    'sequence_file' => dirname(__DIR__) . '/frontend/data/proforma-invoice-sequence.json',
];
