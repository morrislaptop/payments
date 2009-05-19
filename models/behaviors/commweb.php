<?php

class CommwebBehavior extends ModelBehavior
{
	function setup(&$model, $settings) {
		if (!isset($this->settings[$model->alias])) {
			$this->settings[$model->alias] = array(
				'access_code' => 'option1_default_value',
				'merchant_id' => 'option2_default_value',
				'url' => 'http://www.google.com',
				'test_ccs' => array(
					'4111111111111111', #visa
					'5555555555554444', #mastercard
					'378282246310005', #amex
				)
			);
		}
		$this->settings[$model->alias] = array_merge($this->settings[$model->alias], (array) $settings);
	}

	function pay(&$model, $data)
	{
		$settings = $this->settings[$model->alias];
		extract($settings);

		// row will contain the IDs that have been saved and the payment log.
		// data contains the cc details.
		$row = $model->read();

		$fields = array(
			'vpc_Version' => 1,
			'vpc_Command' => 'pay',
			'vpc_AccessCode' => $access_code,
			'vpc_MerchTxnRef' => $row['Payment']['id'],
			'vpc_OrderInfo' => $row[$model->name][$model->primaryKey],
			'vpc_Merchant' => $merchant_id,
			'vpc_Amount' => round($row['Payment']['cc_amount'] * 100),
			'vpc_CardNum' => $data['Payment']['cc_number'],
			'vpc_CardExp' => $data['Payment']['cc_expiry']['year'] . $data['Payment']['cc_expiry']['month'],
			'vpc_CardSecurityCode' => $data['Payment']['cc_security']
		);

		$keyValues = array();
		foreach($fields as $key => $value) {
			if (strlen($value) > 0) {
				$keyValues[] = urlencode($key) . '=' . urlencode($value);
			}
		}
		$postData = implode('&', $keyValues);

		// Get a HTTPS connection to VPC Gateway and do transaction
		// turn on output buffering to stop response going to browser
		ob_start();

		// initialise Client URL object
		$ch = curl_init();

		// set the URL of the VPC
		curl_setopt ($ch, CURLOPT_URL, $url);
		curl_setopt ($ch, CURLOPT_POST, 1);
		curl_setopt ($ch, CURLOPT_POSTFIELDS, $postData);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 1);

		// (optional) set the proxy IP address and port
		//curl_setopt ($ch, CURLOPT_PROXY, "192.168.21.13:80");

		// (optional) certificate validation
		// trusted certificate file
		//curl_setopt($ch, CURLOPT_CAINFO, "c:/temp/ca-bundle.crt");

		//turn on/off cert validation
		// 0 = don't verify peer, 1 = do verify
		//curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);

		// 0 = don't verify hostname, 1 = check for existence of hostame, 2 = verify
		//curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);

		// connect
		curl_exec ($ch);

		// get response
		$response = ob_get_contents();

		// turn output buffering off.
		ob_end_clean();

		// set up message paramter for error outputs
		$pg_message = "";

		// serach if $response contains html error code
		if(strchr($response,"<html>") || strchr($response,"<html>")) {;
			$pg_message = $response;
		} else {
			// check for errors from curl
			if (curl_error($ch))
				  $pg_message = "%s: s". curl_errno($ch) . "<br/>" . curl_error($ch);
		}

		// close client URL
		curl_close ($ch);

		// Extract the available receipt fields from the VPC Response
		// If not present then let the value be equal to 'No Value Returned'
		$map = array();

		// process response if no errors
		if (strlen($pg_message) == 0) {
			$pairArray = split("&", $response);
			foreach ($pairArray as $pair) {
				$param = split("=", $pair);
				$map[urldecode($param[0])] = urldecode($param[1]);
			}
			$pg_message = $this->null2unknown($map, "vpc_Message");
		}

		// Standard Receipt Data
		$pg_amount          = $this->null2unknown($map, "vpc_Amount");
		$pg_locale          = $this->null2unknown($map, "vpc_Locale");
		$pg_batch_no        = $this->null2unknown($map, "vpc_BatchNo");
		$pg_command         = $this->null2unknown($map, "vpc_Command");
		$pg_version         = $this->null2unknown($map, "vpc_Version");
		$pg_card_type       = $this->null2unknown($map, "vpc_Card");
		$pg_order_info       = $this->null2unknown($map, "vpc_OrderInfo");
		$pg_receipt_no       = $this->null2unknown($map, "vpc_ReceiptNo");
		$pg_merchant      = $this->null2unknown($map, "vpc_Merchant");
		$pg_bank_auth_id     = $this->null2unknown($map, "vpc_AuthorizeId");
		$pg_txn_number   = $this->null2unknown($map, "vpc_TransactionNo");
		$pg_acq_response_code = $this->null2unknown($map, "vpc_AcqResponseCode");
		$pg_txn_response_code = $this->null2unknown($map, "vpc_TxnResponseCode");
		// message collected above.

		// Misc data.
		$id = $row['Payment']['id'];
		$success = '0' == $pg_txn_response_code; // IMPORTANT!
		$gateway = 'commweb';

		// Combine all data and save to the payment log.
		$payment = compact('pg_message', 'pg_amount', 'pg_locale', 'pg_batch_no', 'pg_command', 'pg_version', 'pg_card_type', 'pg_order_info', 'pg_receipt_no', 'pg_merchant', 'pg_bank_auth_id',
							'pg_txn_number', 'pg_acq_response_code', 'pg_txn_response_code', 'id', 'success', 'gateway');

		// Save receipt data, ensure it never fails by not validating
		$model->Payment->save($payment, false);

		// Set some invalidations based on the response code
		if ( !$success ) {
			$model->Payment->invalidate('cc_number', $pg_message);
		}

		// Allow test orders to go through.
		if ( Configure::read() && in_array($data['Payment']['cc_number'], $test_ccs) ) {
			return true;
		}

		// Return the result
		return $success;
	}

	function null2unknown($map, $key) {
	    if (array_key_exists($key, $map)) {
	        if (!is_null($map[$key])) {
	            return $map[$key];
	        }
	    }
	}
}

?>