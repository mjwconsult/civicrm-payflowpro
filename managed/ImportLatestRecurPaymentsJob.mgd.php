<?php

use CRM_Payflowpro_ExtensionUtil as E;

return [
  [
    'name' => 'job_payflowpro_importlatestrecurpayments',
    'entity' => 'Job',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'values' => [
        'is_active' => FALSE,
        'name' => 'PayflowPro: Import latest recur Payments',
        'description' => E::ts('Get the latest payments using PayflowPro API for each recurring payment and record/update in CiviCRM.'),
        'run_frequency' => 'Daily',
        'api_entity' => 'PayflowPro',
        'api_action' => 'importLatestRecurPayments',
        'parameters' => 'version=4
paymentProcessorID=1',
      ],
      'match' => [
        'name',
      ],
    ],
  ],
];
