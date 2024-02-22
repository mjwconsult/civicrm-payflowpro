<?php

namespace Civi\PayflowPro;

use Civi\Api4\ContributionRecur;
use Civi\Payment\System;

/**
 * PayflowPro doesn't offer Webhooks / Instant Payment Notification (IPN)
 * Instead we query it for updates via a scheduled job.
 * This class contains that functionality.
 *
 */
class RecurIPN {

  use \CRM_Core_Payment_MJWIPNTrait;

  /**
   * The CiviCRM Payment Processor ID
   *
   * @var int
   */
  private int $paymentProcessorID;

  /**
   *
   * @var string
   */
  protected string $paymentHistoryType;

  public function __construct($paymentProcessorID, $paymentHistoryType = 'Y') {
    $this->paymentProcessorID = $paymentProcessorID;
    $this->paymentHistoryType = $paymentHistoryType;
    $this->setPaymentProcessor($paymentProcessorID);
  }

  public function importLatestRecurPayments($recurProfileIDs = []): array {
    /**
     * @var \CRM_Core_Payment_PayflowPro $this->getPaymentProcessor()
     */
    $payflowAPI = new Api($this->getPaymentProcessor());

    // Get the list of recurring contributions to query.
    $contributionRecurApi = ContributionRecur::get(FALSE)
      ->addWhere('is_test', '=', $this->getPaymentProcessor()->getIsTestMode())
      ->addWhere('payment_processor_id', '=', $this->paymentProcessorID);
    if (!empty($this->recurProfileID)) {
      $contributionRecurApi->addWhere('processor_id', 'IN', $recurProfileIDs);
    }
    $contributionRecurs = $contributionRecurApi->execute();
    foreach ($contributionRecurs as $contributionRecur) {
      $results['recur'][$contributionRecur['id']] = [];
      // This gets a list of payments sorted by date
      $recurPaymentHistory = $payflowAPI->getRecurPaymentHistory($contributionRecur['processor_id'], $this->paymentHistoryType);
      // Get all the existing contributions for this recur ordered by most recent first
      $contributions = \Civi\Api4\Contribution::get(FALSE)
        ->addWhere('contribution_recur_id', '=', $contributionRecur['id'])
        ->addWhere('is_test', '=', $this->getPaymentProcessor()->getIsTestMode())
        ->addOrderBy('receive_date', 'DESC')
        ->execute()
        ->indexBy('trxn_id')
        ->getArrayCopy();

      // Now we need to loop through history received from PayflowPro and
      //   compare with what we have recorded in CiviCRM.
      foreach ($recurPaymentHistory as $payflowRecurPayment) {
        $matchedContribution = FALSE;
        $contributionTrxnIDs = array_keys($contributions);
        foreach ($contributionTrxnIDs as $contributionTrxnID) {
          // Check for existing contribution matching recurPayment by trxn_id
          if (str_contains($contributionTrxnID, $payflowRecurPayment['trxn_id'])) {
            // We've got a matching contribution for the payflow recur payment (ie. it's already recorded)
            // @todo Check status as we may not be Completed / Successful
            $matchedContribution = TRUE;
            break;
          }
        }
        if (!$matchedContribution) {
          // Create the next contribution for a recurring contribution
          $repeatContributionParams = [
            'contribution_recur_id' => $contributionRecur['id'],
            'contribution_status_id' => \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'),
            'receive_date' => date('YmdHis', $payflowRecurPayment['trxn_date']),
            'order_reference' => $payflowRecurPayment['trxn_id'],
            'trxn_id' => $payflowRecurPayment['trxn_id'],
            'total_amount' => $payflowRecurPayment['amount'],
            'fee_amount' => 0,
          ];
          $newContributionID = $this->repeatContribution($repeatContributionParams);
          $results['recur'][$contributionRecur['id']]['contributions_created'][] = $newContributionID;

          // Now record the payment
          $contributionParams = [
            'contribution_id' => $newContributionID,
            'trxn_date' => date('YmdHis', $payflowRecurPayment['trxn_date']),
            'order_reference' => $payflowRecurPayment['trxn_id'],
            'trxn_id' => $payflowRecurPayment['trxn_id'],
            'total_amount' => $payflowRecurPayment['amount'],
            'fee_amount' => 0,
          ];
          $this->updateContributionCompleted($contributionParams);
        }
      }
    }

    return $results ?? [];
  }
}