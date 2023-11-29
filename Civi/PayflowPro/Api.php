<?php

namespace Civi\PayflowPro;

use Civi\Payment\Exception\PaymentProcessorException;
use CRM_Payflowpro_ExtensionUtil as E;

class Api {

  /**
   * @var \CRM_Core_Payment_PayflowPro $paymentProcessor;
   */
  protected \CRM_Core_Payment_PayflowPro $paymentProcessor;

  public function __construct($paymentProcessor) {
    $this->paymentProcessor = $paymentProcessor;
  }

  private function getPaymentProcessor(): \CRM_Core_Payment_PayflowPro {
    return $this->paymentProcessor;
  }

  /**
   * The PayflowPro user
   *
   * @return string
   */
  public function getUser(): string {
    //if you have not set up a separate user account the vendor name is used as the username
    if (!$this->getPaymentProcessor()['subject']) {
      return $this->getPaymentProcessor()['user_name'];
    }
    else {
      return $this->getPaymentProcessor()['subject'];
    }
  }

  public function getQueryArrayAuth() {
    return [
      'USER' => $this->getUser(),
      'VENDOR' => $this->getPaymentProcessor()['user_name'],
      'PARTNER' => $this->getPaymentProcessor()['signature'],
      'PWD' => $this->getPaymentProcessor()['password'],
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
   * @return mixed|object
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function submit_transaction($payflow_query) {
    $submiturl = $this->getPaymentProcessor()['url_site'];
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
    $response = $this->getPaymentProcessor()->getGuzzleClient()->post($submiturl, [
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

    if (($responseData === FALSE) || (strlen($responseData) == 0)) {
      throw new PaymentProcessorException("Error: Connection to payment gateway failed - no data
                                           returned. Gateway url set to $submiturl", 9006);
    }

    /*
     * If gateway returned no data - tell 'em and bail out
     */
    if (empty($responseData)) {
      throw new PaymentProcessorException('Error: No data returned from payment gateway.', 9007);
    }

    /*
     * Success so far - close the curl and check the data
     */
    return $responseData;
  }

  /**
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
      \Civi::log('payflowpro')->error('payflopro: ' . $nvpArray['RESPMSG']);
    }

    return $nvpArray;

    switch ($result_code) {
      case 0:

        /*******************************************************
         * Success !
         * This is a successful transaction. Payflow Pro does return further information
         * about transactions to help you identify fraud including whether they pass
         * the cvv check, the avs check. This is stored in
         * CiviCRM as part of the transact
         * but not further processing is done. Business rules would need to be defined
         *******************************************************/
        $result['trxn_id'] = ($nvpArray['PNREF'] ?? '') . ($nvpArray['TRXPNREF'] ?? '');
        //'trxn_id' is varchar(255) field. returned value is length 12
        $params['trxn_result_code'] = $nvpArray['AUTHCODE'] . "-Cvv2:" . $nvpArray['CVV2MATCH'] . "-avs:" . $nvpArray['AVSADDR'];

        if ($params['is_recur'] == TRUE) {
          $params['recur_trxn_id'] = $nvpArray['PROFILEID'];
          //'trxn_id' is varchar(255) field. returned value is length 12
        }
        $result = $this->setStatusPaymentCompleted($result);
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
        throw new PaymentProcessorException('Error - from payment processor: [' . $result_code . " " . $nvpArray['RESPMSG'] . "] ", 9013);
    }

  }

}
