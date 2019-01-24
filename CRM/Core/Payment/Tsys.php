<?php
/*
 * Payment Processor class for Stripe
 */
class CRM_Core_Payment_Tsys extends CRM_Core_Payment {

  /**
  * We only need one instance of this object. So we use the singleton
  * pattern and cache the instance in this variable
  *
  * @var object
  * @static
  */
 static private $_singleton = NULL;

 /**
  * Mode of operation: live or test.
  *
  * @var object
  */
 protected $_mode = NULL;

 /**
 * TRUE if we are dealing with a live transaction
 *
 * @var boolean
 */
private $_islive = FALSE;

 /**
  * Constructor
  *
  * @param string $mode
  *   The mode of operation: live or test.
  *
  * @return void
  */
 public function __construct($mode, &$paymentProcessor) {
   $this->_mode = $mode;
   $this->_islive = ($mode == 'live' ? 1 : 0);
   $this->_paymentProcessor = $paymentProcessor;
   $this->_processorName = ts('Tsys');
 }


  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any.
   *
   * @public
   */
  public function checkConfig() {
    // $config = CRM_Core_Config::singleton();
    $error = array();
    // TODO fix this up to be Tsys specific
    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Secret Key" is not set in the Tsys Payment Processor settings.');
    }
    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Publishable Key" is not set in the Tsys Payment Processor settings.');
    }
    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Get API Key and provide it to JS
    $paymentProcessorId = CRM_Utils_Array::value('id', $form->_paymentProcessor);
    $publishableKey = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($paymentProcessorId, "password");
    $publishableKey = $publishableKey['password'];
    CRM_Core_Resources::singleton()->addVars('tsys', array('api' => $publishableKey));
  }

  /**
   * Given a payment processor id, return the publishable key (password field)
   *
   * @param $paymentProcessorId
   *
   * @return string
   */
  public static function getPaymentProcessorSettings($paymentProcessorId, $fields) {
   try {
     $publishableKey = civicrm_api3('PaymentProcessor', 'getsingle', array(
       'return' => $fields,
       'id' => $paymentProcessorId,
     ));
   }
   catch (CiviCRM_API3_Exception $e) {
     return '';
   }
   return $publishableKey;
  }

  /**
   * Get array of fields that should be displayed on the payment form for credit cards.
   *
   * @return array
   */
  protected function getCreditCardFormFields() {
    return array(
      'credit_card_type',
      'credit_card_number',
      'cvv2',
      'credit_card_exp_date',
      // ADD PAYMENT TOKEN
      'payment_token',
    );
  }

  /**
   * Process payment
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    if (!empty($params['payment_token'])) {
      $makeTransaction = CRM_Core_Payment_Tsys::composeSoapRequest(
        $params['payment_token'],
        $params['payment_processor_id'],
        $params['amount'],
        $params['contributionID']
      );
      if ($makeTransaction == "APPROVED") {
        $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

        // TODO decide if we need these params
        // $params['fee_amount'] = $stripeBalanceTransaction->fee / 100;
        // $params['net_amount'] = $stripeBalanceTransaction->net / 100;
        // $params['trxn_id'] = $stripeCharge->id;

        $params['payment_status_id'] = $completedStatusId;
        return $params;
      }
      // TODO not an approved transaction deal with failure
      else {

      }
    }
  }

  /**
   * composes soap request and sends it to tsys
   * @param  [type] $token [description]
   * @return [type]        [description]
   */
  public static function composeSoapRequest($token, $paymentProcessorId, $amount, $contribID) {
    $response = "NO RESPONSE";
    // TODO need to find a way to generate unique invoice ids cannot use invoice id in civi because it needs to be less than 8 numbers and all numeric.
    $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($paymentProcessorId, array("signature", "subject", "user_name"));
    $soap_request = <<<HEREDOC
<?xml version="1.0"?>
    <soap:Envelope xmlns:soap='http://www.w3.org/2003/05/soap-envelope'>
       <soap:Body>
          <Sale xmlns='http://schemas.merchantwarehouse.com/merchantware/v45/'>
             <Credentials>
                <MerchantName>{$tsysCreds['user_name']}</MerchantName>
                <MerchantSiteId>{$tsysCreds['subject']}</MerchantSiteId>
                <MerchantKey>{$tsysCreds['signature']}</MerchantKey>
             </Credentials>
             <PaymentData>
                <Source>Vault</Source>
                <VaultToken>{$token}</VaultToken>
              </PaymentData>
             <Request>
                <Amount>$amount</Amount>
                <CashbackAmount>0.00</CashbackAmount>
                <SurchargeAmount>0.00</SurchargeAmount>
                <TaxAmount>0.00</TaxAmount>
                <InvoiceNumber>$contribID</InvoiceNumber>
             </Request>
          </Sale>
       </soap:Body>
    </soap:Envelope>
HEREDOC;

    $header = array(
      "Content-type: text/xml;charset=\"utf-8\"",
      "Accept: text/xml",
      "Cache-Control: no-cache",
      "Pragma: no-cache",
      "Content-length: ".strlen($soap_request),
    );

    $soap_do = curl_init();
    curl_setopt($soap_do, CURLOPT_URL, "https://ps1.merchantware.net/Merchantware/ws/RetailTransaction/v45/Credit.asmx" );
    curl_setopt($soap_do, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($soap_do, CURLOPT_TIMEOUT,        20);
    curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, true );
    curl_setopt($soap_do, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($soap_do, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($soap_do, CURLOPT_POST,           true );
    curl_setopt($soap_do, CURLOPT_POSTFIELDS,     $soap_request);
    curl_setopt($soap_do, CURLOPT_HTTPHEADER,     $header);
    $response = curl_exec($soap_do);

    if ($response === false) {
      $err = 'Curl error: ' . curl_error($soap_do);
      curl_close($soap_do);
      print $err;
    }
    else {
      curl_close($soap_do);
      $response = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $response);
      $xml = simplexml_load_string($response);
      if (!empty($xml->Body->SaleResponse->SaleResult->ApprovalStatus) && $xml->Body->SaleResponse->SaleResult->ApprovalStatus  == "APPROVED") {
        $response = $xml->Body->SaleResponse->SaleResult->ApprovalStatus;
      }
      else {
        $response = $xml;
      }
    }
    return $response;
  }
}
