<?php
/*
   +----------------------------------------------------------------------------+
   | Payflow Pro Core Payment Module for CiviCRM version 5                      |
   +----------------------------------------------------------------------------+
   | Licensed to CiviCRM under the Academic Free License version 3.0            |
   |                                                                            |
   | Written & Contributed by Eileen McNaughton - 2009                          |
   +---------------------------------------------------------------------------+
  */

use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use Brick\Money\Money;
use CRM_Payflowpro_ExtensionUtil as E;

/**
 * Class CRM_Core_Payment_PayflowPro.
 */
class CRM_Core_Payment_PayflowPro extends CRM_Core_Payment {

  use CRM_Core_Payment_MJWTrait;

  /**
   * @var \GuzzleHttp\Client
   */
  public \GuzzleHttp\Client $guzzleClient;

  /**
   * @return \GuzzleHttp\Client
   */
  public function getGuzzleClient(): \GuzzleHttp\Client {
    return $this->guzzleClient ?? new \GuzzleHttp\Client();
  }

  /**
   * @param \GuzzleHttp\Client $guzzleClient
   */
  public function setGuzzleClient(\GuzzleHttp\Client $guzzleClient) {
    $this->guzzleClient = $guzzleClient;
  }

  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   * @param $paymentProcessor
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_paymentProcessor = $paymentProcessor;
    $this->api = new \Civi\PayflowPro\Api($this);
  }

  /**
   * This public function checks to see if we have the right processor config values set
   *
   * NOTE: Called by Events and Contribute to check config params are set prior to trying
   *  register any credit card details
   *
   * @return string|null
   *   the error message if any, null if OK
   */
  public function checkConfig() {
    $errorMsg = [];
    if (empty($this->_paymentProcessor['user_name'])) {
      $errorMsg[] = ' ' . ts('Vendor ID is not set for this payment processor');
    }

    if (empty($this->_paymentProcessor['url_site'])) {
      $errorMsg[] = ' ' . ts('URL is not set for %1', [1 => $this->_paymentProcessor['name']]);
    }

    if (!empty($errorMsg)) {
      return implode('<p>', $errorMsg);
    }
    return NULL;
  }

  /*
   * This function  sends request and receives response from
   * the processor. It is the main function for processing on-server
   * credit card transactions
   */

  /**
   * This function collects all the information from a web/api form and invokes
   * the relevant payment processor specific functions to perform the transaction
   *
   * @param array|\Civi\Payment\PropertyBag $paymentParams
   *
   * @param string $component
   *
   * @return array
   *   Result array (containing at least the key payment_status_id)
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$paymentParams, $component = 'contribute') {
    /* @var \Civi\Payment\PropertyBag $propertyBag */
    $propertyBag = $this->beginDoPayment($paymentParams);

    $zeroAmountPayment = $this->processZeroAmountPayment($propertyBag);
    if ($zeroAmountPayment) {
      return $zeroAmountPayment;
    }

    /*
     * define variables for connecting with the gateway
     */
    $payflowApi = new \Civi\PayflowPro\Api($this);

    $payflow_query_array = array_merge(
      $payflowApi->getQueryArrayAuth(),
      [
        // C - Direct Payment using credit card
        'TENDER' => 'C',
        // A - Authorization, S - Sale
        'TRXTYPE' => 'S',
        'ACCT' => urlencode($paymentParams['credit_card_number']),
        'CVV2' => $paymentParams['cvv2'],
        'EXPDATE' => urlencode(sprintf('%02d', (int) $paymentParams['month']) . substr($paymentParams['year'], 2, 2)),
        'ACCTTYPE' => urlencode($paymentParams['credit_card_type']),
        // @todo: Do we need to machinemoney format? ie. 1024.00 or is 1024 ok for API?
        'AMT' => \Civi::format()->machineMoney($propertyBag->getAmount()),
        'CURRENCY' => urlencode($propertyBag->getCurrency()),
        'FIRSTNAME' => $propertyBag->has('firstName') ? $propertyBag->getFirstName() : '',
        //credit card name
        'LASTNAME' => $propertyBag->has('lastName') ? $propertyBag->getLastName() : '',
        //credit card name
        'STREET' => $propertyBag->getBillingStreetAddress(),
        'CITY' => urlencode($propertyBag->getBillingCity()),
        'STATE' => urlencode($propertyBag->getBillingStateProvince()),
        'ZIP' => urlencode($propertyBag->getBillingPostalCode()),
        'COUNTRY' => urlencode($propertyBag->getBillingCountry()),
        'EMAIL' => $propertyBag->getEmail(),
        'CUSTIP' => urlencode($paymentParams['ip_address']),
        'COMMENT1' => urlencode($paymentParams['contributionType_accounting_code']),
        'COMMENT2' => $this->_paymentProcessor['is_test'] ? 'test' : 'live',
        'INVNUM' => urlencode($propertyBag->getInvoiceID()),
        'ORDERDESC' => urlencode($propertyBag->getDescription()),
        'VERBOSITY' => 'MEDIUM',
        'BILLTOCOUNTRY' => urlencode($propertyBag->getBillingCountry()),
      ]);

    if ($paymentParams['installments'] == 1) {
      $paymentParams['is_recur'] = FALSE;
    }

    if ($paymentParams['is_recur'] == TRUE) {

      $payflow_query_array['TRXTYPE'] = 'R';
      $payflow_query_array['OPTIONALTRX'] = 'S';
      // @todo: Do we need to machinemoney format? ie. 1024.00 or is 1024 ok for API?
      $payflow_query_array['OPTIONALTRXAMT'] = \Civi::format()->machineMoney($propertyBag->getAmount());
      //Amount of the initial Transaction. Required
      $payflow_query_array['ACTION'] = 'A';
      //A for add recurring (M-modify,C-cancel,R-reactivate,I-inquiry,P-payment
      $payflow_query_array['PROFILENAME'] = urlencode('RegularContribution');
      //A for add recurring (M-modify,C-cancel,R-reactivate,I-inquiry,P-payment
      if ($paymentParams['installments'] > 0) {
        $payflow_query_array['TERM'] = $propertyBag->getRecurInstallments() - 1;
        //ie. in addition to the one happening with this transaction
      }
      // $payflow_query_array['COMPANYNAME']
      // $payflow_query_array['DESC']  =  not set yet  Optional
      // description of the goods or
      //services being purchased.
      //This parameter applies only for ACH_CCD accounts.
      // The
      // $payflow_query_array['MAXFAILPAYMENTS']   = 0;
      // number of payment periods (as s
      //pecified by PAYPERIOD) for which the transaction is allowed
      //to fail before PayPal cancels a profile.  the default
      // value of 0 (zero) specifies no
      //limit. Retry
      //attempts occur until the term is complete.
      // $payflow_query_array['RETRYNUMDAYS'] = (not set as can't assume business rule

      if ($propertyBag->getRecurFrequencyUnit() === 'day') {
        throw new PaymentProcessorException('Current implementation does not support recurring with frequency "day"');
      }
      $interval = $propertyBag->getRecurFrequencyInterval() . " " . $propertyBag->getRecurFrequencyUnit();
      switch ($interval) {
        case '1 week':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 7,
            date("Y")
          );
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (7 * $payflow_query_array['TERM']),
            date("Y")
          );
          $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $payflow_query_array['PAYPERIOD'] = "WEEK";
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(1);
          break;

        case '2 weeks':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 14, date("Y"));
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (14 * $payflow_query_array['TERM']), date("Y ")
          );
          $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $payflow_query_array['PAYPERIOD'] = "BIWK";
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(2);
          break;

        case '4 weeks':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d") + 28, date("Y")
          );
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d") + (28 * $payflow_query_array['TERM']), date("Y")
          );
          $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $payflow_query_array['PAYPERIOD'] = "FRWK";
          $propertyBag->setRecurFrequencyUnit('week');
          $propertyBag->setRecurFrequencyInterval(4);
          break;

        case '1 month':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 1,
            date("d"), date("Y")
          );
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m") +
            (1 * $payflow_query_array['TERM']),
            date("d"), date("Y")
          );
          $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $payflow_query_array['PAYPERIOD'] = "MONT";
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(1);
          break;

        case '3 months':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 3, date("d"), date("Y")
          );
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m") +
            (3 * $payflow_query_array['TERM']),
            date("d"), date("Y")
          );
          $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $payflow_query_array['PAYPERIOD'] = "QTER";
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(3);
          break;

        case '6 months':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m") + 6, date("d"),
            date("Y")
          );
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m") +
            (6 * $payflow_query_array['TERM']),
            date("d"), date("Y")
          );
          $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']
          );
          $payflow_query_array['PAYPERIOD'] = "SMYR";
          $propertyBag->setRecurFrequencyUnit('month');
          $propertyBag->setRecurFrequencyInterval(6);
          break;

        case '1 year':
          $paymentParams['next_sched_contribution_date'] = mktime(0, 0, 0, date("m"), date("d"),
            date("Y") + 1
          );
          $paymentParams['end_date'] = mktime(0, 0, 0, date("m"), date("d"),
            date("Y") +
            (1 * $payflow_query_array['TEM'])
          );
          $payflow_query_array['START'] = date('mdY', $paymentParams['next_sched_contribution_date']);
          $payflow_query_array['PAYPERIOD'] = "YEAR";
          $propertyBag->setRecurFrequencyUnit('year');
          $propertyBag->setRecurFrequencyInterval(1);
          break;
      }
    }

    CRM_Utils_Hook::alterPaymentProcessorParams($this, $propertyBag, $payflow_query_array);
    $payflow_query = $payflowApi->convert_to_nvp($payflow_query_array);

    /*
     * Check to see if we have a duplicate before we send
     */
    // if ($this->checkDupe($params['invoiceID'], $params['contributionID'] ?? NULL)) {
    //  throw new PaymentProcessorException('It appears that this transaction is a duplicate.  Have you already submitted the form once?  If so there may have been a connection problem.  Check your email for a receipt.  If you do not receive a receipt within 2 hours you can try your transaction again.  If you continue to have problems please contact the site administrator.', 9003);
    //}

    $responseData = $payflowApi->submit_transaction($payflow_query);

    /*
     * Payment successfully sent to gateway - process the response now
     */
    $nvpArray = $payflowApi->processResponseData($responseData);

    switch ($nvpArray['RESULT']) {
      case 0:
        // Success
        if ($paymentParams['is_recur'] == TRUE) {
          // Store the PROFILEID on the recur
          \Civi\Api4\ContributionRecur::update(FALSE)
            ->addWhere('id', '=', $propertyBag->getContributionRecurID())
            ->addValue('processor_id', $nvpArray['PROFILEID'])
            ->execute();
        }
        $result = $this->setStatusPaymentCompleted([]);
        //'trxn_id' is varchar(255) field. returned value is length 12
        $result['trxn_id'] = ($nvpArray['PNREF'] ?? '') . ($nvpArray['TRXPNREF'] ?? '');
        return $result;

      case 1:
        throw new PaymentProcessorException('There is a payment processor configuration problem. This is usually due to invalid account information or ip restrictions on the account.  You can verify ip restriction by logging         // into Manager.  See Service Settings >> Allowed IP Addresses.   ', 9003);

      case 12:
        // Hard decline from bank.
        throw new PaymentProcessorException('Your transaction was declined   ', 9009);

      case 13:
        // Voice authorization required.
        throw new PaymentProcessorException('Your Transaction is pending. Contact Customer Service to complete your order.', 9010);

      case 23:
        // Issue with credit card number or expiration date.
        throw new PaymentProcessorException('Invalid credit card information. Please re-enter.', 9011);

      case 26:
        throw new PaymentProcessorException('You have not configured your payment processor with the correct credentials. Make sure you have provided both the "vendor" and the "user" variables ', 9012);

      default:
        throw new PaymentProcessorException('Error - from payment processor: [' . $nvpArray['RESULT'] . " " . $nvpArray['RESPMSG'] . "] ", 9013);
    }
  }

  /**
   * Submit a refund payment
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \Brick\Money\Exception\UnknownCurrencyException
   */
  public function doRefund(&$params) {
    $requiredParams = ['trxn_id', 'amount'];
    foreach ($requiredParams as $required) {
      if (!isset($params[$required])) {
        $message = 'doRefund: Missing mandatory parameter: ' . $required;
        $this->api->logError($message);
        throw new PaymentProcessorException($message);
      }
    }

    $propertyBag = PropertyBag::cast($params);

    // Call the API to refund
    $payflowApi = new \Civi\PayflowPro\Api($this);
    $payflow_query_array = $payflowApi->getQueryArrayAuth();
    $payflow_query_array['TRXTYPE'] = 'C';
    $payflow_query_array['TENDER'] = 'C';
    $payflow_query_array['ORIGID'] = $params['trxn_id'];
    $payflow_query_array['AMT'] = \Civi::format()->machineMoney($propertyBag->getAmount());

    $payflow_query = $payflowApi->convert_to_nvp($payflow_query_array);

    $responseData = $payflowApi->submit_transaction($payflow_query);

    $nvpArray = $payflowApi->processResponseData($responseData);

    switch ($nvpArray['RESULT']) {
      case 0:
        // Success
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
        $refundStatusName = 'Completed';
        break;

      default:
        $refundStatus = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
        $refundStatusName = 'Failed';
        \Civi::log('payflowpro')->error('Refund failed: ' . $nvpArray['RESPMSG'] . print_r($nvpArray,TRUE));
    }

    $refundParams = [
      'refund_trxn_id' => $nvpArray['PNREF'],
      'refund_status_id' => $refundStatus,
      'refund_status' => $refundStatusName,
      'fee_amount' => 0,
    ];
    return $refundParams;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }

  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * We can edit stripe recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return TRUE;
  }

  /**
   * Does this processor support cancelling recurring contributions through code.
   *
   * If the processor returns true it must be possible to take action from within CiviCRM
   * that will result in no further payments being processed.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return TRUE;
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return TRUE;
  }

  /**
   * Attempt to cancel the subscription at Stripe.
   *
   * @param \Civi\Payment\PropertyBag $propertyBag
   *
   * @return array|null[]
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doCancelRecurring(PropertyBag $propertyBag) {
    // By default we always notify the processor and we don't give the user the option
    // because supportsCancelRecurringNotifyOptional() = FALSE
    if (!$propertyBag->has('isNotifyProcessorOnCancelRecur')) {
      // If isNotifyProcessorOnCancelRecur is NOT set then we set our default
      $propertyBag->setIsNotifyProcessorOnCancelRecur(TRUE);
    }
    $notifyProcessor = $propertyBag->getIsNotifyProcessorOnCancelRecur();

    if (!$notifyProcessor) {
      return ['message' => E::ts('Successfully cancelled the subscription in CiviCRM ONLY.')];
    }

    // Check we have an ID for the recur at PayflowPro (the PROFILEID)
    if (!$propertyBag->has('recurProcessorID')) {
      $errorMessage = E::ts('The recurring contribution cannot be cancelled (No reference (processor_id) found).');
      \Civi::log('payflowpro')->error($errorMessage);
      throw new PaymentProcessorException($errorMessage);
    }

    // Call the API to cancel the subscription
    $payflowApi = new \Civi\PayflowPro\Api($this);
    $payflow_query_array = $payflowApi->getQueryArrayAuth();
    $payflow_query_array['TRXTYPE'] = 'R';
    $payflow_query_array['ACTION'] = 'C';
    $payflow_query_array['ORIGPROFILEID'] = $propertyBag->getRecurProcessorID();

    $payflow_query = $payflowApi->convert_to_nvp($payflow_query_array);

    $responseData = $payflowApi->submit_transaction($payflow_query);

    $nvpArray = $payflowApi->processResponseData($responseData);

    switch ($nvpArray['RESULT']) {
      case 0:
        // Success
        return ['message' => E::ts('Successfully cancelled the subscription at PayflowPro.')];

      default:
        throw new PaymentProcessorException(E::ts('Could not cancel PayflowPro subscription: %1', [1 => $nvpArray['RESPMSG']]));
    }
  }

  /**
   * Change the amount of the recurring payment.
   *
   * @param string $message
   * @param array $params
   *
   * @return bool|object
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    // We only support the following params: amount
    $propertyBag = $this->beginChangeSubscriptionAmount($params);
    try {
      // Get the PayflowPro subscription
      $existingRecur = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addWhere('id', '=', $propertyBag->getContributionRecurID())
        ->execute()
        ->first();

      // Check if amount has actually changed!
      if (Money::of($existingRecur['amount'], mb_strtoupper($existingRecur['currency']))
        ->isAmountAndCurrencyEqualTo(Money::of($propertyBag->getAmount(), $propertyBag->getCurrency()))) {
        throw new PaymentProcessorException('Amount is the same as before!');
      }

      // Call the API to update the subscription amount
      $payflowApi = new \Civi\PayflowPro\Api($this);
      $payflow_query_array = $payflowApi->getQueryArrayAuth();
      $payflow_query_array['TRXTYPE'] = 'R';
      $payflow_query_array['TENDER'] = 'C';
      $payflow_query_array['ACTION'] = 'M';
      $payflow_query_array['ORIGPROFILEID'] = $propertyBag->getRecurProcessorID();
      $payflow_query_array['AMT'] = \Civi::format()->machineMoney($propertyBag->getAmount());

      $payflow_query = $payflowApi->convert_to_nvp($payflow_query_array);

      $responseData = $payflowApi->submit_transaction($payflow_query);

      $nvpArray = $payflowApi->processResponseData($responseData);

      switch ($nvpArray['RESULT']) {
        case 0:
          // Success
          \Civi::log('payflowpro')->info('Update subscription success: ' . print_r($nvpArray, TRUE));
          break;

        default:
          throw new PaymentProcessorException('Update Subscription Failed: ' . print_r($nvpArray, TRUE));
      }
    }
    catch (Exception $e) {
      // On ANY failure, throw an exception which will be reported back to the user.
      $this->api->logError('Update Subscription failed for RecurID: ' . $propertyBag->getContributionRecurID() . ' Error: ' . $e->getMessage());
      throw new PaymentProcessorException('Update Subscription Failed: ' . $e->getMessage(), $e->getCode(), $params);
    }

    return TRUE;
  }

  /**
   * Update payment details at Authorize.net.
   *
   * @param string $message
   * @param array $params
   *
   * @return bool|object
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function updateSubscriptionBillingInfo(&$message = '', $params = []) {
    $propertyBag = $this->beginUpdateSubscriptionBillingInfo($params);

    try {
      // Call the API to update the subscription amount
      $payflowApi = new \Civi\PayflowPro\Api($this);
      $payflow_query_array = $payflowApi->getQueryArrayAuth();
      $payflow_query_array['TRXTYPE'] = 'R';
      $payflow_query_array['TENDER'] = 'C';
      $payflow_query_array['ACTION'] = 'M';
      $payflow_query_array['ORIGPROFILEID'] = $propertyBag->getRecurProcessorID();
      $payflow_query_array['ACCT'] = $propertyBag->getCustomProperty('credit_card_number'); // credit card number
      $payflow_query_array['CVV2'] = $propertyBag->getCustomProperty('cvv2'); // CVV
      $expDate = $propertyBag->getCustomProperty('credit_card_exp_date');
      $expDateFormatted = date('my', strtotime($expDate['Y'] . $expDate['M'] . '01000000'));
      $payflow_query_array['EXPDATE'] = $expDateFormatted; // Expiry card
      $payflow_query_array['BILLTOFIRSTNAME'] = $propertyBag->getFirstName();
      $payflow_query_array['BILLTOLASTNAME'] = $propertyBag->getLastName();
      $payflow_query_array['BILLTOSTREET'] = $propertyBag->getBillingStreetAddress();
      $payflow_query_array['BILLTOCITY'] = $propertyBag->getBillingCity();
      $payflow_query_array['BILLTOSTATE'] = $propertyBag->getBillingStateProvince();
      $payflow_query_array['BILLTOZIP'] = $propertyBag->getBillingPostalCode();
      $payflow_query_array['BILLTOCOUNTRY'] = $propertyBag->getBillingCountry();

      $payflow_query = $payflowApi->convert_to_nvp($payflow_query_array);

      $responseData = $payflowApi->submit_transaction($payflow_query);

      $nvpArray = $payflowApi->processResponseData($responseData);

      switch ($nvpArray['RESULT']) {
        case 0:
          // Success
          \Civi::log('payflowpro')->info('Update billing details success: ' . print_r($nvpArray, TRUE));
          break;

        default:
          throw new PaymentProcessorException('Update billing details failed: ' . print_r($nvpArray, TRUE));
      }
    }
    catch (Exception $e) {
      // On ANY failure, throw an exception which will be reported back to the user.
      $this->api->logError('Update billing details failed for RecurID: ' . $propertyBag->getContributionRecurID() . ' Error: ' . $e->getMessage());
      throw new PaymentProcessorException('Update billing details failed: ' . $e->getMessage(), $e->getCode(), $params);
    }

    return TRUE;
  }

  /**
   * Get an array of the fields that can be edited on the recurring contribution.
   *
   * Some payment processors support editing the amount and other scheduling details of recurring payments, especially
   * those which use tokens. Others are fixed. This function allows the processor to return an array of the fields that
   * can be updated from the contribution recur edit screen.
   *
   * The fields are likely to be a subset of these
   *  - 'amount',
   *  - 'installments',
   *  - 'frequency_interval',
   *  - 'frequency_unit',
   *  - 'cycle_day',
   *  - 'next_sched_contribution_date',
   *  - 'end_date',
   * - 'failure_retry_day',
   *
   * The form does not restrict which fields from the contribution_recur table can be added (although if the html_type
   * metadata is not defined in the xml for the field it will cause an error.
   *
   * Open question - would it make sense to return membership_id in this - which is sometimes editable and is on that
   * form (UpdateSubscription).
   *
   * @return array
   */
  public function getEditableRecurringScheduleFields() {
    if ($this->supports('changeSubscriptionAmount')) {
      return ['amount'];
    }
    return [];
  }


}
