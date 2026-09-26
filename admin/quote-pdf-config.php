<?php
/**
 * PROFORMA INVOICE PDF configuration for Gloria Trading.
 *
 * Company name, address, phone, and email are already public business details.
 * Bank account fields printed on invoices are loaded from the private
 * invoice-bank.php file. This repository copy keeps those fields empty.
 * Invoice sequence numbers are stored only in the private directory.
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
        'bank_name' => '',
        'swift_code' => '',
        'branch_name' => '',
        'branch_phone' => '',
        'account_name' => '',
        'account_number' => '',
        'branch_address' => '',
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
    'sequence_file' => '',
];
