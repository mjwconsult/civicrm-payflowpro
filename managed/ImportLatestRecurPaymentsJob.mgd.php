<?php

use CRM_Payflowpro_ExtensionUtil as E;

return [
  [
    'name' => 'job_payflowpro_importlatestrecurpayments',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'name' => 'PayflowPro: Import latest recur Payments',
        'description' => E::ts('Get the latest payments using PayflowPro API for each recurring payment and record/update in CiviCRM.'),
        'run_frequency' => 'Daily',
        'api_entity' => 'PayflowPro',
        'api_action' => 'importLatestRecurPayments',
        'parameters' => '',
      ],
      'is_active' => FALSE,
      'match' => [
        'name',
      ],
    ],
  ],
];
