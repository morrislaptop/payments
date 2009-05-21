<?php

/**
* Migs is used by CBA and ANZ
*/
class MigsBehavior extends ModelBehavior
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

		$expiry = substr($data['Payment']['cc_expiry']['year'], 2); // known to be 4 digits.
		$expiry .= $data['Payment']['cc_expiry']['month']; // known to be 2 digits

		$fields = array(
			'vpc_Version' => 1,
			'vpc_Command' => 'pay',
			'vpc_AccessCode' => $access_code,
			'vpc_MerchTxnRef' => $row['Payment']['id'],
			'vpc_OrderInfo' => $row[$model->name][$model->primaryKey],
			'vpc_Merchant' => $merchant_id,
			'vpc_Amount' => round($row['Payment']['cc_amount'] * 100),
			'vpc_CardNum' => $data['Payment']['cc_number'],
			'vpc_CardExp' => $expiry,
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
		$gateway = 'migs';

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

	// This method uses the QSI Response code retrieved from the Digital
	// Receipt and returns an appropriate description for the QSI Response Code
	//
	// @param $responseCode String containing the QSI Response Code
	//
	// @return String containing the appropriate description
	//
	function getResponseDescription($responseCode) {

	    switch ($responseCode) {
	        case "0" : $result = "Transaction Successful"; break;
	        case "?" : $result = "Transaction status is unknown"; break;
	        case "1" : $result = "Unknown Error"; break;
	        case "2" : $result = "Bank Declined Transaction"; break;
	        case "3" : $result = "No Reply from Bank"; break;
	        case "4" : $result = "Expired Card"; break;
	        case "5" : $result = "Insufficient funds"; break;
	        case "6" : $result = "Error Communicating with Bank"; break;
	        case "7" : $result = "Payment Server System Error"; break;
	        case "8" : $result = "Transaction Type Not Supported"; break;
	        case "9" : $result = "Bank declined transaction (Do not contact Bank)"; break;
	        case "A" : $result = "Transaction Aborted"; break;
	        case "C" : $result = "Transaction Cancelled"; break;
	        case "D" : $result = "Deferred transaction has been received and is awaiting processing"; break;
	        case "F" : $result = "3D Secure Authentication failed"; break;
	        case "I" : $result = "Card Security Code verification failed"; break;
	        case "L" : $result = "Shopping Transaction Locked (Please try the transaction again later)"; break;
	        case "N" : $result = "Cardholder is not enrolled in Authentication scheme"; break;
	        case "P" : $result = "Transaction has been received by the Payment Adaptor and is being processed"; break;
	        case "R" : $result = "Transaction was not processed - Reached limit of retry attempts allowed"; break;
	        case "S" : $result = "Duplicate SessionID (OrderInfo)"; break;
	        case "T" : $result = "Address Verification Failed"; break;
	        case "U" : $result = "Card Security Code Failed"; break;
	        case "V" : $result = "Address Verification and Card Security Code Failed"; break;
	        default  : $result = "Unable to be determined";
	    }
	    return $result;
	}

	//  ----------------------------------------------------------------------------

	// This function uses the QSI AVS Result Code retrieved from the Digital
	// Receipt and returns an appropriate description for this code.

	// @param vAVSResultCode String containing the QSI AVS Result Code
	// @return description String containing the appropriate description

	function displayAVSResponse($avsResultCode) {

	    if ($avsResultCode != "") {
	        switch ($avsResultCode) {
	            Case "Unsupported" : $result = "AVS not supported or there was no AVS data provided"; break;
	            Case "X"  : $result = "Exact match - address and 9 digit ZIP/postal code"; break;
	            Case "Y"  : $result = "Exact match - address and 5 digit ZIP/postal code"; break;
	            Case "S"  : $result = "Service not supported or address not verified (international transaction)"; break;
	            Case "G"  : $result = "Issuer does not participate in AVS (international transaction)"; break;
	            Case "A"  : $result = "Address match only"; break;
	            Case "W"  : $result = "9 digit ZIP/postal code matched, Address not Matched"; break;
	            Case "Z"  : $result = "5 digit ZIP/postal code matched, Address not Matched"; break;
	            Case "R"  : $result = "Issuer system is unavailable"; break;
	            Case "U"  : $result = "Address unavailable or not verified"; break;
	            Case "E"  : $result = "Address and ZIP/postal code not provided"; break;
	            Case "N"  : $result = "Address and ZIP/postal code not matched"; break;
	            Case "0"  : $result = "AVS not requested"; break;
	            default   : $result = "Unable to be determined";
	        }
	    } else {
	        $result = "null response";
	    }
	    return $result;
	}

	//  ----------------------------------------------------------------------------

	// This function uses the QSI CSC Result Code retrieved from the Digital
	// Receipt and returns an appropriate description for this code.

	// @param vCSCResultCode String containing the QSI CSC Result Code
	// @return description String containing the appropriate description

	function displayCSCResponse($cscResultCode) {

	    if ($cscResultCode != "") {
	        switch ($cscResultCode) {
	            Case "Unsupported" : $result = "CSC not supported or there was no CSC data provided"; break;
	            Case "M"  : $result = "Exact code match"; break;
	            Case "S"  : $result = "Merchant has indicated that CSC is not present on the card (MOTO situation)"; break;
	            Case "P"  : $result = "Code not processed"; break;
	            Case "U"  : $result = "Card issuer is not registered and/or certified"; break;
	            Case "N"  : $result = "Code invalid or not matched"; break;
	            default   : $result = "Unable to be determined"; break;
	        }
	    } else {
	        $result = "null response";
	    }
	    return $result;
	}

	//  -----------------------------------------------------------------------------

	// This method uses the verRes status code retrieved from the Digital
	// Receipt and returns an appropriate description for the QSI Response Code

	// @param statusResponse String containing the 3DS Authentication Status Code
	// @return String containing the appropriate description

	function getStatusDescription($statusResponse) {
	    if ($statusResponse == "" || $statusResponse == "No Value Returned") {
	        $result = "3DS not supported or there was no 3DS data provided";
	    } else {
	        switch ($statusResponse) {
	            Case "Y"  : $result = "The cardholder was successfully authenticated."; break;
	            Case "E"  : $result = "The cardholder is not enrolled."; break;
	            Case "N"  : $result = "The cardholder was not verified."; break;
	            Case "U"  : $result = "The cardholder's Issuer was unable to authenticate due to some system error at the Issuer."; break;
	            Case "F"  : $result = "There was an error in the format of the request from the merchant."; break;
	            Case "A"  : $result = "Authentication of your Merchant ID and Password to the ACS Directory Failed."; break;
	            Case "D"  : $result = "Error communicating with the Directory Server."; break;
	            Case "C"  : $result = "The card type is not supported for authentication."; break;
	            Case "S"  : $result = "The signature on the response received from the Issuer could not be validated."; break;
	            Case "P"  : $result = "Error parsing input from Issuer."; break;
	            Case "I"  : $result = "Internal Payment Server system error."; break;
	            default   : $result = "Unable to be determined"; break;
	        }
	    }
	    return $result;
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