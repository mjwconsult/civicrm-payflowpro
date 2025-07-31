<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

use CRM_Payflowpro_ExtensionUtil as E;

return [
  'payflowpro_testmodesettlement' => [
    'name' => 'payflowpro_testmodesettlement',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('In test mode complete transactions pending settlement.'),
    'description' => E::ts('In test mode some banks (eg. FISERV) don\'t progress to "settlement completed/successfully". Enable this to treat "settlement pending" as Completed.'),
    'html_attributes' => [],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 10,
      ]
    ],
  ],
  'payflowpro_cardonfile' => [
    'name' => 'payflowpro_cardonfile',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'default' => 0,
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => E::ts('Enable Card-on-file functionality.'),
    'description' => E::ts('Enable this to allow users to save their cards for future use.'),
    'html_attributes' => [],
    'settings_pages' => [
      'payflowpro' => [
        'weight' => 20,
      ]
    ],
  ],
];
