<?php

use CRM_Payflowpro_ExtensionUtil as E;

return [
  [
    'name' => 'PaymentProcessorType_PayflowPro',
    'entity' => 'PaymentProcessorType',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'PayflowPro',
        'title' => E::ts('PayflowPro'),
        'user_name_label' => 'Vendor ID',
        'password_label' => 'Password',
        'signature_label' => 'Partner (merchant)',
        'subject_label' => 'User',
        'class_name' => 'Payment_PayflowPro',
        'url_site_default' => 'https://payflowpro.paypal.com',
        'url_site_test_default' => 'https://pilot-payflowpro.paypal.com',
        'billing_mode' => 1,
        'is_recur' => TRUE,
        'payment_instrument_id:name' => 'Credit Card',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
