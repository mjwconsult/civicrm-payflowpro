<?php

namespace Civi\PayflowPro;

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Payflowpro_ExtensionUtil as E;

/**
 * This implements an abstraction layer for the PayflowPro API
 * used by CRM_Core_Payment_PayflowPro and \Civi\PayflowPro\RecurIPN
 */
class Api {

  /**
   * @var \CRM_Core_Payment_PayflowPro $paymentProcessor;
   */
  protected \CRM_Core_Payment_PayflowPro $paymentProcessor;

  /**
   * @param \CRM_Core_Payment_PayflowPro $paymentProcessor
   */
  public function __construct(\CRM_Core_Payment_PayflowPro $paymentProcessor) {
    $this->paymentProcessor = $paymentProcessor;
  }

  /**
   * Get the PaymentProcessor object
   */
  private function getPaymentProcessor(): \CRM_Core_Payment_PayflowPro {
    return $this->paymentProcessor;
  }

  /**
   * Get the array of PaymentProcessor configuration
   *
   * @return array
   */
  private function getPaymentProcessorArray(): array {
    return $this->paymentProcessor->getPaymentProcessor();
  }

  /**
   * The PayflowPro user
   *
   * @return string
   */
  public function getUser(): string {
    //if you have not set up a separate user account the vendor name is used as the username
    if (!$this->getPaymentProcessorArray()['subject']) {
      return $this->getPaymentProcessorArray()['user_name'];
    }
    else {
      return $this->getPaymentProcessorArray()['subject'];
    }
  }

  /**
   * Convert the CiviCRM PaymentProcessor auth params into keys for PayflowPro authentication
   *
   * @return array
   */
  public function getQueryArrayAuth(): array {
    return [
      'USER' => $this->getUser(),
      'VENDOR' => $this->getPaymentProcessorArray()['user_name'],
      'PARTNER' => $this->getPaymentProcessorArray()['signature'],
      'PWD' => $this->getPaymentProcessorArray()['password'],
    ];
  }

  /**
   * convert to a name/value pair (nvp) string
   *
   * @param $payflow_query_array
   *
   * @return array|string
   */
  public function convert_to_nvp($payflow_query_array) {
    foreach ($payflow_query_array as $key => $value) {
      $payflow_query[] = $key . '[' . strlen($value) . ']=' . $value;
    }
    $payflow_query = implode('&', $payflow_query);

    return $payflow_query;
  }

  /**
   * Submit transaction using cURL
   *
   * @param string $payflow_query value string to be posted
   *
   * @return string
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function submit_transaction(string $payflow_query): string {
    $submiturl = $this->getPaymentProcessorArray()['url_site'];
    // get data ready for API
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Guzzle';
    // Here's your custom headers; adjust appropriately for your setup:
    $headers[] = "Content-Type: text/namevalue";
    //or text/xml if using XMLPay.
    $headers[] = "Content-Length : " . strlen($payflow_query);
    // Length of data to be passed
    // Here the server timeout value is set to 45, but notice
    // below in the cURL section, the timeout
    // for cURL is 90 seconds.  You want to make sure the server
    // timeout is less, then the connection.
    $headers[] = "X-VPS-Timeout: 45";
    //random unique number  - the transaction is retried using this transaction ID
    // in this function but if that doesn't work and it is re- submitted
    // it is treated as a new attempt. Payflow Pro doesn't allow
    // you to change details (e.g. card no) when you re-submit
    // you can only try the same details
    $headers[] = "X-VPS-Request-ID: " . rand(1, 1000000000);
    // optional header field
    $headers[] = "X-VPS-VIT-Integration-Product: CiviCRM";
    // other Optional Headers.  If used adjust as necessary.
    // Name of your OS
    //$headers[] = "X-VPS-VIT-OS-Name: Linux";
    // OS Version
    //$headers[] = "X-VPS-VIT-OS-Version: RHEL 4";
    // What you are using
    //$headers[] = "X-VPS-VIT-Client-Type: PHP/cURL";
    // For your info
    //$headers[] = "X-VPS-VIT-Client-Version: 0.01";
    // For your info
    //$headers[] = "X-VPS-VIT-Client-Architecture: x86";
    // Application version
    //$headers[] = "X-VPS-VIT-Integration-Version: 0.01";
    $response = $this->paymentProcessor->getGuzzleClient()->post($submiturl, [
      'body' => $payflow_query,
      'headers' => $headers,
      'curl' => [
        CURLOPT_SSL_VERIFYPEER => \Civi::settings()->get('verifySSL'),
        CURLOPT_USERAGENT => $user_agent,
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYHOST => \Civi::settings()->get('verifySSL') ? 2 : 0,
        CURLOPT_POST => TRUE,
      ],
    ]);

    // Try to submit the transaction up to 3 times with 5 second delay.  This can be used
    // in case of network issues.  The idea here is since you are posting via HTTPS there
    // could be general network issues, so try a few times before you tell customer there
    // is an issue.

    $i = 1;
    while ($i++ <= 3) {
      $responseData = $response->getBody();
      $http_code = $response->getStatusCode();
      if ($http_code != 200) {
        // Let's wait 5 seconds to see if its a temporary network issue.
        sleep(5);
      }
      elseif ($http_code == 200) {
        // we got a good response, drop out of loop.
        break;
      }
    }
    if ($http_code != 200) {
      throw new PaymentProcessorException('Error connecting to the Payflow Pro API server.', 9015);
    }

    $responseDataString = $responseData->getContents();
    if (($responseDataString === FALSE) || (strlen($responseDataString) === 0)) {
      throw new PaymentProcessorException("Error: Connection to payment gateway failed - no data
                                           returned. Gateway url set to $submiturl", 9006);
    }

    /*
     * If gateway returned no data - tell 'em and bail out
     */
    if (empty($responseDataString)) {
      throw new PaymentProcessorException('Error: No data returned from payment gateway.', 9007);
    }

    /*
     * Success so far - close the curl and check the data
     */
    return $responseDataString;
  }

  /**
   * Process the response received from PayflowPro API
   *
   * @param string $responseData
   *
   * @return mixed
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function processResponseData(string $responseData) {
    $responseResult = strstr($responseData, 'RESULT');
    if (empty($responseResult)) {
      throw new PaymentProcessorException('No RESULT code from PayPal.', 9016);
    }

    $nvpArray = [];
    while (strlen($responseResult)) {
      // name
      $keypos = strpos($responseResult, '=');
      $keyval = substr($responseResult, 0, $keypos);
      // value
      $valuepos = strpos($responseResult, '&') ? strpos($responseResult, '&') : strlen($responseResult);
      $valval = substr($responseResult, $keypos + 1, $valuepos - $keypos - 1);
      // decoding the respose
      $nvpArray[$keyval] = $valval;
      $responseResult = substr($responseResult, $valuepos + 1, strlen($responseResult));
    }
    // get the result code to validate.
    $result_code = $nvpArray['RESULT'];
    if ($result_code > 0) {
      $this->logError($nvpArray['RESPMSG']);
    }

    return $nvpArray;
  }

  /**
   * Log an info message with payment processor prefix
   * @param string $message
   *
   * @return void
   */
  public function logInfo(string $message) {
    $this->log('info', $message);
  }

  /**
   * Log an error message with payment processor prefix
   *
   * @param string $message
   *
   * @return void
   */
  public function logError(string $message) {
    $this->log('error', $message);
  }

  /**
   * Log a debug message with payment processor prefix
   *
   * @param string $message
   *
   * @return void
   */
  public function logDebug(string $message) {
    $this->log('debug', $message);
  }

  /**
   * @param string $level
   * @param string $message
   *
   * @return void
   */
  private function log(string $level, string $message) {
    $channel = 'payflowpro';
    $prefix = $channel . '(' . $this->getPaymentProcessor()->getID() . '): ';
    \Civi::log($channel)->$level($prefix . $message);
  }

  public function getRecurPaymentHistory(string $recurProfileID, string $paymentHistoryType = 'Y') {
    // Call the API to refund.
    $payflowApi = $this;
    $payflow_query_array = $payflowApi->getQueryArrayAuth();
    $payflow_query_array['ACTION'] = 'I';
    $payflow_query_array['TRXTYPE'] = 'R';
    $payflow_query_array['ORIGPROFILEID'] = $recurProfileID;
    $payflow_query_array['PAYMENTHISTORY'] = $paymentHistoryType;

    $payflow_query = $payflowApi->convert_to_nvp($payflow_query_array);

    $responseData = $payflowApi->submit_transaction($payflow_query);

    $nvpArray = $payflowApi->processResponseData($responseData);

    // get the result code to validate.
    $result_code = $nvpArray['RESULT'];
    if ($result_code > 0) {
      throw new PaymentProcessorException($nvpArray['RESPMSG']);
    }

    /**
     * Will return something in this format. We'll parse it now
    RESULT=0&RPREF=RKM500141021&PROFILEID=RT0000000100&P_PNREF1=VWYA06156256&P_
    TRANSTIME1=21-May-04 04:47
    PM&P_RESULT1=0&P_TENDER1=C&P_AMT1=1.00&P_TRANSTATE1=8&P_PNREF2=VWYA06156269
    &P_TRANSTIME2=27-May-04 01:19
     */

    $keyMap = [
      'P_PNREF' => [
        'key' => 'trxn_id',
        'type' => 'String',
      ],
      'P_TRANSTIME' => [
        'key' => 'trxn_date',
        'type' => 'Timestamp',
      ],
      'P_TRANSTATE' => [
        'key' => 'status_id:name',
        'type' => 'Status',
      ],
      'P_TENDER' => [
        'key' => 'payment_method',
        'type' => 'String',
      ],
      'P_AMT' => [
        'key' => 'amount',
        'type' => 'Float',
      ],
    ];

    // Result is 0 (ok) since we got here
    foreach ($nvpArray as $key => $value) {
      // It's a "payment" parameter
      // We're only going to look for the following parameters:
      // - PNREF: Payment reference: trxn_id eg. VWYA06156256
      // - TRANSTIME: Transaction time. Eg. 21-May-04 04:47
      // - TRANSTATE: Transaction status: one of:
      //    - 1: error
      //    - 6: settlement pending
      //    - 7: settlement in progress
      //    - 8: settlement completed/successfully
      //    - 11: settlement failed
      //    - 14: settlement incomplete
      // - TENDER: C = Credit card; P = PayPal; A = Automated Clearinghouse
      // - AMT: Amount (eg. 1.00)
      foreach ($keyMap as $srcKey => $dest) {
        if (str_contains($key, $srcKey)) {
          $paymentID = substr($key, strlen($srcKey));
          switch ($dest['type']) {
            case 'Timestamp':
              $paymentsByID[$paymentID][$dest['key']] = strtotime($value);
              break;

            case 'Status':
              $mapToCivi = [
                1 => [
                  'name' => 'Failed',
                  'description' => 'error',
                ],
                6 => [
                  'name' => 'Pending',
                  'description' => 'settlement pending',
                ],
                7 => [
                  'name' => 'Pending',
                  'description' => 'settlement in progress',
                ],
                8 => [
                  'name' => 'Completed',
                  'description' => 'settlement completed/successfully',
                ],
                11 => [
                  'name' => 'Failed',
                  'description' => 'settlement failed',
                ],
                14 => [
                  'name' => 'Failed',
                  'description' => 'settlement incomplete',
                ],
              ];
              $paymentsByID[$paymentID][$srcKey] = $value;
              $paymentsByID[$paymentID][$dest['key'] . '_description'] = $mapToCivi[$value]['description'];
              $paymentsByID[$paymentID][$dest['key']] = $mapToCivi[$value]['name'];
              break;

            default:
              $paymentsByID[$paymentID][$dest['key']] = $value;
          }
        }
      }
    }
    foreach ($paymentsByID as $id => $detail) {
      $paymentsByDate[$paymentsByID[$id]['trxn_date']] = $detail;
      $paymentsByDate[$paymentsByID[$id]['trxn_date']]['id'] = $id;
    }

    return $paymentsByDate ?? [];
  }

}
