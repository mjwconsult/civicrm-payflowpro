<?php

use CRM_AdvancedEvents_ExtensionUtil as E;

return [
  [
    'name' => 'payflowpro_settings',
    'entity' => 'Navigation',
    'cleanup' => 'always',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'label' => E::ts('PayPal PayflowPro Settings'),
        'name' => 'payflowpro_settings',
        'url' => 'civicrm/admin/setting/payflowpro',
        'permission' => 'administer PayPal Payflow Pro',
        'permission_operator' => 'OR',
        'parent_id.name' => 'CiviContribute',
        'is_active' => TRUE,
        'has_separator' => 0,
        'weight' => 90,
      ],
      'match' => ['name'],
    ],
  ],
];
