<?php
/**
* eWay can be used by anyone
*/
class EwayBehavior extends ModelBehavior
{
	function setup(&$model, $settings) {
		if (!isset($this->settings[$model->alias])) {
			$this->settings[$model->alias] = array(
				'CustomerID' => '123456',
				'test_ccs' => array(
					'4111111111111111', #visa
					'5555555555554444', #mastercard
					'378282246310005', #amex
				)
			);
		}
		$this->settings[$model->alias] = array_merge($this->settings[$model->alias], (array) $settings);
	}

	/**
	* @todo Match the transaction data to model fields in the settings.
	*/
	function pay(&$model, $data)
	{
		$settings = $this->settings[$model->alias];
		extract($settings);
		
		// Import eWay library
		App::import('Vendor', 'Payments.EwayPayment', array('file' => 'EwayPayment.php'));

		// row will contain the IDs that have been saved and the payment log.
		// data contains the cc details.
		$row = $model->read();
		
		// remvoe all non numbers from the cc number
		$data['Payment']['cc_number'] = preg_replace('/\D/', '', $data['Payment']['cc_number']);
		
		// make expiry year 2 digits
		$data['Payment']['cc_expiry']['year'] = substr($data['Payment']['cc_expiry']['year'], 2); // known to be 4 digits.
			
		// Init
		$eway = new EwayPayment( $CustomerID, 'https://www.eway.com.au/gateway_cvn/xmltest/testpage.asp' );

		// Set properties
		$eway->setCustomerFirstname( $row['User']['first_name'] ); 
		$eway->setCustomerLastname( $row['User']['last_name'] );
		$eway->setCustomerEmail( $row['User']['email'] );
		$eway->setCustomerAddress( $row['BillingAddress']['postal_address'] );
		$eway->setCustomerPostcode( $row['BillingAddress']['postcode'] );
		$eway->setCustomerInvoiceDescription( $model->name );
		$eway->setCustomerInvoiceRef( $row[$model->name][$model->primaryKey] );
		$eway->setCardHoldersName( $data['Payment']['cc_name'] );
		$eway->setCardNumber( $data['Payment']['cc_number'] );
		$eway->setCardExpiryMonth( $data['Payment']['cc_expiry']['month'] );
		$eway->setCardExpiryYear( $data['Payment']['cc_expiry']['year'] );
		$eway->setTrxnNumber( $row['Payment']['id'] );
		$eway->setTotalAmount( $row['Payment']['cc_amount'] );
		$eway->setCVN( $data['Payment']['cc_security'] );

		// DO!!!!
		$doPayment = $eway->doPayment();

		// Determine result
		$success = $doPayment == EWAY_TRANSACTION_OK; // IMPORTANT!
		
		// Save the payment.
		/*
                $this->myResultTrxnStatus = $this->xmlData['ewayTrxnStatus'];
                $this->myResultTrxnNumber = $this->xmlData['ewayTrxnNumber'];
                $this->myResultTrxnOption1 = $this->xmlData['ewayTrxnOption1'];
                $this->myResultTrxnOption2 = $this->xmlData['ewayTrxnOption2'];
                $this->myResultTrxnOption3 = $this->xmlData['ewayTrxnOption3'];
                $this->myResultTrxnReference = $this->xmlData['ewayTrxnReference'];
                $this->myResultAuthCode = $this->xmlData['ewayAuthCode'];
                $this->myResultReturnAmount = $this->xmlData['ewayReturnAmount'];
                $this->myResultTrxnError = $this->xmlData['ewayTrxnError'];
        */
		$payment = array(
			'success' => $success,
			'pg_txn_response_code' => $eway->getTrxnStatus(),
			'pg_txn_number' => $eway->getTrxnNumber(),
			'pg_receipt_no' => $eway->getTrxnReference(),
			'pg_bank_auth_id' => $eway->getAuthCode(),
			'pg_amount' => $eway->getReturnAmount(),
			'pg_message' => $eway->getErrorMessage(),
			'gateway' => 'eway'
		);

		// Save receipt data, ensure it never fails by not validating
		$model->Payment->save($payment, false);

		// Set some invalidations based on the response code
		if ( !$success ) {
			$model->Payment->invalidate('cc_number', $payment['pg_message']);
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