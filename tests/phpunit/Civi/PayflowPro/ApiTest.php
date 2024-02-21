<?php

namespace Civi\PayflowPro;

use CRM_PayflowPro_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class ApiTest extends \PHPUnit\Framework\TestCase implements HeadlessInterface, HookInterface {

  use \Civi\Test\GuzzleTestTrait;
  use \Civi\Test\Api3TestTrait;

  /**
   * Instance of CRM_Core_Payment_PayflowPro|null
   * @var \CRM_Core_Payment_PayflowPro
   */
  protected $processor;

  /**
   * Created Object Ids
   * @var array
   */
  public $ids;

  /**
   * @var int
   */
  protected int $contactID;

  /**
   * @var int
   */
  protected int $contributionID;

  /**
   * @var int
   */
  protected int $contributionRecurID;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->install('mjwshared')
      ->apply();
  }

  public function setUp(): void {
    $this->setUpPayflowProcessor();
    $this->processor = \Civi\Payment\System::singleton()->getById($this->ids['PaymentProcessor']['PayflowPro']);
    parent::setUp();
  }

  public function tearDown(): void {
    $this->callAPISuccess('PaymentProcessor', 'delete', ['id' => $this->ids['PaymentProcessor']['PayflowPro']]);
    parent::tearDown();
  }

  /**
   * Test making a recurring payment
   */
  public function testRecurPaymentHistory(): void {
    $this->setupMockHandler(NULL);

    $recurPayments = \Civi\Api4\PayflowPro::getRecurPaymentHistory(FALSE)
      ->setRecurProfileID('RT0000000100')
      ->setPaymentProcessorID($this->ids['PaymentProcessor']['PayflowPro'])
      ->execute();

    $this->assertEquals(6, count($recurPayments));
    $this->assertEquals('VWYA06157668', $recurPayments['1086882420']['trxn_id']);
    $this->assertEquals('1.00', $recurPayments['1086882420']['amount']);
    $this->assertEquals(4, $recurPayments['1086882420']['id']);
    $this->assertEquals('C', $recurPayments['1086882420']['payment_method']);
    $this->assertEquals('8', $recurPayments['1086882420']['status_id']);
  }

  /**
   * Get some basic billing parameters.
   *
   * These are what are entered by the form-filler.
   *
   * @return array
   */
  protected function getBillingParams(): array {
    return [
      'first_name' => 'John',
      'middle_name' => '',
      'last_name' => "O'Connor",
      'billing_street_address-5' => '8 Hobbitton Road',
      'billing_city-5' => 'The Shire',
      'billing_state_province_id-5' => 1012,
      'billing_postal_code-5' => 5010,
      'billing_country_id-5' => 1228,
      'credit_card_number' => '4111111111111111',
      'cvv2' => 123,
      'credit_card_exp_date' => [
        'M' => 9,
        'Y' => 2025,
      ],
      'credit_card_type' => 'Visa',
      'year' => 2022,
      'month' => 10,
    ];
  }

  public function setUpPayflowProcessor(): void {
    $paymentProcessorType = $this->callAPISuccess('PaymentProcessorType', 'get', ['name' => 'PayflowPro']);
    $this->callAPISuccess('PaymentProcessorType', 'create', ['id' => $paymentProcessorType['id'], 'is_active' => 1]);
    $params = [
      'name' => 'demo',
      'title' => 'demo',
      'domain_id' => \CRM_Core_Config::domainID(),
      'payment_processor_type_id' => 'PayflowPro',
      'is_active' => 1,
      'is_default' => 0,
      'is_test' => 0,
      'user_name' => 'test',
      'password' => 'test1234',
      'url_site' => 'https://pilot-Payflowpro.paypal.com',
      'class_name' => 'Payment_PayflowPro',
      'billing_mode' => 1,
      'financial_type_id' => 1,
      'financial_account_id' => 12,
      // Credit card = 1 so can pass 'by accident'.
      'payment_instrument_id' => 'Debit Card',
      'signature' => 'PayPal',
    ];
    if (!is_numeric($params['payment_processor_type_id'])) {
      // really the api should handle this through getoptions but it's not exactly api call so lets just sort it
      //here
      $params['payment_processor_type_id'] = $this->callAPISuccess('payment_processor_type', 'getvalue', [
        'name' => $params['payment_processor_type_id'],
        'return' => 'id',
      ], 'integer');
    }
    $result = $this->callAPISuccess('payment_processor', 'create', $params);
    $processorID = $result['id'];
    $this->setupMockHandler($processorID);
    $this->ids['PaymentProcessor']['PayflowPro'] = $processorID;
  }

  /**
   * Add a mock handler to the Payflow Pro processor for testing.
   *
   * @param int|null $id
   *
   * @throws \CRM_Core_Exception
   */
  protected function setupMockHandler($id = NULL): void {
    if ($id) {
      $this->processor = \Civi\Payment\System::singleton()->getById($id);
    }
    $response = $this->getExpectedRecurPaymentHistoryResponse();
    // Comment the next line out when trying to capture the response.
    // see https://github.com/civicrm/civicrm-core/pull/18350
    $this->createMockHandler($response);
    $this->setUpClientWithHistoryContainer();
    $this->processor->setGuzzleClient($this->getGuzzleClient());
  }

  public function getExpectedRecurPaymentHistoryResponse(): array {
    return [
      'RESULT=0&RPREF=RKM500141021&PROFILEID=RT0000000100&P_PNREF1=VWYA06156256&P_TRANSTIME1=21-May-04 04:47PM&P_RESULT1=0&P_TENDER1=C&P_AMT1=1.00&P_TRANSTATE1=8&P_PNREF2=VWYA06156269&P_TRANSTIME2=27-May-04 01:19PM&P_RESULT2=0&P_TENDER2=C&P_AMT2=1.00&P_TRANSTATE2=8&P_PNREF3=VWYA06157650&P_TRANSTIME3=03-Jun-04 04:47PM&P_RESULT3=0&P_TENDER3=C&P_AMT3=1.00&P_TRANSTATE3=8&P_PNREF4=VWYA06157668&P_TRANSTIME4=10-Jun-04 04:47PM&P_RESULT4=0&P_TENDER4=C&P_AMT4=1.00&P_TRANSTATE4=8&P_PNREF5=VWYA06158795&P_TRANSTIME5=17-Jun-04 04:47PM&P_RESULT5=0&P_TENDER5=C&P_AMT5=1.00&P_TRANSTATE5=8&P_PNREF6=VJLA00000060&P_TRANSTIME6=05-Aug-04 05:54PM&P_RESULT6=0&P_TENDER6=C&P_AMT6=1.00&P_TRANSTATE6=1'
    ];
  }

}
