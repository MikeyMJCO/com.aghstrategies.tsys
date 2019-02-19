<?php
/*
 * Payment Processor class for Recurring Tsys Transactions
 */
class CRM_Tsys_Recur {

  /**
   * @param $contribution an array of a contribution to be created (or in case of future start date,
   * possibly an existing pending contribution to recycle, if it already has a contribution id).
   * @param $options like is membership or send email receipt
   * @param $original_contribution_id if included, use as a template for a recurring contribution.
   *
   *   A high-level utility function for making a contribution payment from an existing recurring schedule
   *   Used in the Tsysrecurringcontributions.php job and the one-time ('card on file') form.
   *
   *   Since 4.7.12, we can are using the new repeattransaction api.
   *
   * Borrowed from https://github.com/iATSPayments/com.iatspayments.civicrm/blob/master/iats.php#L1285 _iats_process_contribution_payment
   */
  function processContributionPayment(&$contribution, $options, $original_contribution_id) {
    // By default, don't use repeattransaction:
    // Borrowed from https://github.com/iATSPayments/com.iatspayments.civicrm/blob/2bf9dcdb1537fb75649aa6304cdab991a8a9d1eb/iats.php#L1285
    $use_repeattransaction = FALSE;
    $is_recurrence = !empty($original_contribution_id);
    // First try and get the money with iATS Payments, using my cover function.
    // TODO: convert this into an api job?
    $contribution['payment_token'] = CRM_Core_DAO::singleValueQuery("SELECT vault_token FROM civicrm_tsys_recur WHERE recur_id = " . $contribution['contribution_recur_id']);
    $result = $this->processTransaction($contribution, 'contribute');

    // Initialize the status to pending:
    $contribution['contribution_status_id'] = 2;

    // We processed it successflly and I can try to use repeattransaction.
    // Requires the original contribution id.
    // Issues with this api call:
    // 1. Always triggers an email and doesn't include trxn.
    // 2. Date is wrong.
    $use_repeattransaction = $is_recurrence && empty($contribution['id']);
    if ($use_repeattransaction) {
      // We processed it successflly and I can try to use repeattransaction.
      // Requires the original contribution id.
      // Issues with this api call:
      // 1. Always triggers an email and doesn't include trxn.
      // 2. Date is wrong.
      try {
        // $status = $result['contribution_status_id'] == 1 ? 'Completed' : 'Pending';
        $contributionResult = civicrm_api3('Contribution', 'repeattransaction', array(
          'original_contribution_id' => $original_contribution_id,
          'contribution_status_id' => 'Pending',
          'is_email_receipt' => 0,
          // 'invoice_id' => $contribution['invoice_id'],
          // 'receive_date' => $contribution['receive_date'],
          // 'campaign_id' => $contribution['campaign_id'],
          // 'financial_type_id' => $contribution['financial_type_id'],
          // 'payment_processor_id' => $contribution['payment_processor'],
          'contribution_recur_id' => $contribution['contribution_recur_id'],
        ));
        $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
      }
      catch (Exception $e) {
        // Ignore this, though perhaps I should log it.
      }
      if (empty($contribution['id'])) {
        // Assume I failed completely and fall back to doing it the manual way.
        $use_repeattransaction = FALSE;
      }
      else {
        // If repeattransaction succeded.
        // First restore/add various fields that the repeattransaction api may
        // overwrite or ignore.
        // TODO - fix this in core to allow these to be set above.
        civicrm_api3('contribution', 'create', array(
          'id' => $contribution['id'],
          'invoice_id' => $contribution['invoice_id'],
          'source' => $contribution['source'],
          'receive_date' => $contribution['receive_date'],
          'payment_instrument_id' => $contribution['payment_instrument_id'],
        ));
        // Save my status in the contribution array that was passed in.
        $contribution['contribution_status_id'] = $result['contribution_status_id'];
        if ($result['contribution_status_id'] == 1) {
          // My transaction completed, so record that fact in CiviCRM,
          // potentially sending an invoice.
          try {
            civicrm_api3('Contribution', 'completetransaction', array(
              'id' => $contribution['id'],
              'payment_processor_id' => $contribution['payment_processor'],
              'is_email_receipt' => (empty($options['is_email_receipt']) ? 0 : 1),
              'trxn_id' => $result['trxn_id'],
              'receive_date' => $contribution['receive_date'],
            ));
          }
          catch (Exception $e) {
            // Log the error and continue.
            CRM_Core_Error::debug_var('Unexpected Exception', $e);
          }
        }
      }
    }
    if (!$use_repeattransaction) {
      // If I'm not using repeattransaction for any reason,
      // I'll create the contribution manually.
      // This code assumes that the contribution_status_id has been set
      // properly above, either pending or failed.
      $contributionResult = civicrm_api3('contribution', 'create', $contribution);
      // Pass back the created id indirectly since I'm calling by reference.
      $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
      // Connect to a membership if requested.
      if (!empty($options['membership_id'])) {
        try {
          civicrm_api3('MembershipPayment', 'create', array('contribution_id' => $contribution['id'], 'membership_id' => $options['membership_id']));
        }
        catch (Exception $e) {
          // Ignore.
        }
      }
      /* And then I'm done unless it completed */
      if ($result['contribution_status_id'] == 1 && !empty($result['status'])) {
        /* success, and the transaction has completed */
        $complete = array(
          'id' => $contribution['id'],
          'payment_processor_id' => $contribution['payment_processor'],
          'trxn_id' => $trxn_id,
          'receive_date' => $contribution['receive_date'],
        );
        $complete['is_email_receipt'] = empty($options['is_email_receipt']) ? 0 : 1;
        try {
          $contributionResult = civicrm_api3('contribution', 'completetransaction', $complete);
        }
        catch (Exception $e) {
          // Don't throw an exception here, or else I won't have updated my
          // next contribution date for example.
          $contribution['source'] .= ' [with unexpected api.completetransaction error: ' . $e->getMessage() . ']';
        }
        // Restore my source field that ipn code irritatingly overwrites,
        // and make sure that the trxn_id is set also.
        civicrm_api3('contribution', 'setvalue', array(
          'id' => $contribution['id'],
          'value' => $contribution['source'],
          'field' => 'source',
        ));
        civicrm_api3('contribution', 'setvalue', array(
          'id' => $contribution['id'],
          'value' => $trxn_id,
          'field' => 'trxn_id',
        ));
        $message = $is_recurrence ? ts('Successfully processed contribution in recurring series id %1: ', array(1 => $contribution['contribution_recur_id'])) : ts('Successfully processed one-time contribution: ');
        return $message . $result['auth_result'];
      }
    }
    // Now return the appropriate message.
    if ($result['contribution_status_id'] == 1) {
      return ts('Successfully processed recurring contribution in series id %1: ', array(1 => $contribution['contribution_recur_id']));
    }
    else {
      return ts('Failed to process recurring contribution id %1: ', array(1 => $contribution['contribution_recur_id']));
    }
  }

  /**
   * @param  array $contribution the contribution
   * @param  array $options      options selected
   * @return array              the contribution
   * Borrowed from _iats_process_transaction https://github.com/iATSPayments/com.iatspayments.civicrm/blob/2bf9dcdb1537fb75649aa6304cdab991a8a9d1eb/iats.php#L1446
   */
  function processTransaction($contribution, $options) {
    // TODO generate a better trxn_id
    // cannot use invoice id in civi because it needs to be less than 8 numbers
    // and all numeric.
    if (empty($contribution['trxn_id'])) {
      $contribution['trxn_id'] = rand(1, 1000000);
    }

    // IF no Payment Token throw error.
    if (empty($contribution['payment_token']) || $contribution['payment_token'] == "Authorization token") {
      CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Tsys token was not passed!  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
    }

    // Get tsys credentials.
    if (!empty($contribution['payment_processor'])) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($contribution['payment_processor'], array(
        "signature",
        "subject",
        "user_name",
      ));
    }

    // Throw an error if no credentials found.
    if (empty($tsysCreds)) {
      CRM_Core_Error::statusBounce(ts('No valid payment processor credentials found'));
      Civi::log()->debug('No valid Tsys credentials found.  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
    }
    // Make transaction
    // TODO decide if we need these params
    // $contribution['fee_amount'] = $stripeBalanceTransaction->fee / 100;
    // $contribution['net_amount'] = $stripeBalanceTransaction->net / 100;
    $makeTransaction = CRM_Core_Payment_Tsys::composeSaleSoapRequest(
      $contribution['payment_token'],
      $tsysCreds,
      $contribution['total_amount'],
      $contribution['trxn_id']
    );

    // If transaction approved.
    if (!empty($makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus) && $makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus == "APPROVED") {
      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $contribution['contribution_status_id'] = $completedStatusId;
      $query = "SELECT COUNT(vault_token) FROM civicrm_tsys_recur WHERE vault_token = %1";
      $queryParams = array(1 => array($contribution['payment_token'], 'String'));
      // If transaction is recurring AND there is not an existing vault token
      // saved.
      if (CRM_Utils_Array::value('is_recur', $contribution) && CRM_Core_DAO::singleValueQuery($query, $queryParams) == 0 && !empty($contribution['contribution_recur_id'])) {
        CRM_Core_Payment_Tsys::boardCard($recur_id, $makeTransaction->Body->SaleResponse->SaleResult->Token, $tsysCreds);
      }
      return $contribution;
    }
    // If transaction fails.
    else {
      $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
      $contribution['contribution_status_id'] = $failedStatusId;
      return $contribution;
    }
  }

  /**
   * For a recurring contribution, find a candidate for a template!
   * TODO not using right now, can remove or implement
   * TODO update this description
   */
  function getContributionTemplate($contribution) {
    // Get the 1st contribution in the series that matches the total_amount:
    $template = array();
    $get = array(
      'contribution_recur_id' => $contribution['contribution_recur_id'],
      'options' => array('sort' => ' id', 'limit' => 1),
    );
    if (!empty($contribution['total_amount'])) {
      $get['total_amount'] = $contribution['total_amount'];
    }
    $result = civicrm_api3('contribution', 'get', $get);
    if (!empty($result['values'])) {
      $contribution_ids = array_keys($result['values']);
      $template = $result['values'][$contribution_ids[0]];
      $template['original_contribution_id'] = $contribution_ids[0];
      $template['line_items'] = array();
      $get = array('entity_table' => 'civicrm_contribution', 'entity_id' => $contribution_ids[0]);
      $result = civicrm_api3('LineItem', 'get', $get);
      if (!empty($result['values'])) {
        foreach ($result['values'] as $initial_line_item) {
          $line_item = array();
          foreach (array(
            'price_field_id',
            'qty',
            'line_total',
            'unit_price',
            'label',
            'price_field_value_id',
            'financial_type_id',
          ) as $key) {
            $line_item[$key] = $initial_line_item[$key];
          }
          $template['line_items'][] = $line_item;
        }
      }
    }
    return $template;
  }
}