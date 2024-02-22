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

namespace Civi\Api4\Action\PayflowPro;

use Civi\PayflowPro\Api;
use Civi\Payment\System;


/**
 * @inheritDoc
 */
class ImportLatestRecurPayments extends \Civi\Api4\Generic\AbstractAction {

  /**
   * The CiviCRM Payment Processor ID
   *
   * @var int
   */
  protected int $paymentProcessorID = 0;

  /**
   *
   * @var string
   */
  protected string $recurProfileID = '';

  /**
   *
   * @var string
   */
  protected string $paymentHistoryType = 'Y';

  /**
   * @param \Civi\Api4\Generic\Result $result
   *
   * @return void
   * @throws \CRM_Core_Exception
   * @throws \Stripe\Exception\ApiErrorException
   */
  public function _run(\Civi\Api4\Generic\Result $result) {
    if (empty($this->paymentProcessorID)) {
      throw new \CRM_Core_Exception('Missing paymentProcessorID');
    }

    $ipnProcessor = new \Civi\PayflowPro\RecurIPN($this->paymentProcessorID);
    if (empty($this->recurProfileID)) {
      $recurProfilesIDs = [];
    }
    else {
      $recurProfilesIDs = [$this->recurProfileID];
    }
    $results = $ipnProcessor->importLatestRecurPayments($recurProfilesIDs);

    // Loop through them.
    $result->exchangeArray($results ?? []);
  }

}
