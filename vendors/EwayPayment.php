<?php
 /*
  * EwayPayment.php
  * Electronic Payment XML Interface for eWAY
  *
  * (c) Copyright Matthew Horoschun, CanPrint Communications 2005.
  *
  * $Id: EwayPayment.php,v 1.2 2005/04/18 03:30:33 matthew Exp $
  *
  * Date:    2005-04-18
  * Version: 2.0
  *
  */

define( 'EWAY_DEFAULT_GATEWAY_URL', 'https://www.eway.com.au/gateway_cvn/xmlpayment.asp' );
define( 'EWAY_DEFAULT_CUSTOMER_ID', '87654321' );

define( 'EWAY_CURL_ERROR_OFFSET', 1000 );
define( 'EWAY_XML_ERROR_OFFSET',  2000 );

define( 'EWAY_TRANSACTION_OK',       0 );
define( 'EWAY_TRANSACTION_FAILED',   1 );
define( 'EWAY_TRANSACTION_UNKNOWN',  2 );


class EwayPayment {
    var $parser;
    var $xmlData;
    var $currentTag;
    
    var $myGatewayURL;
    var $myCustomerID;
    
    var $myTotalAmount;
    var $myCustomerFirstname;
    var $myCustomerLastname;
    var $myCustomerEmail;
    var $myCustomerAddress;
    var $myCustomerPostcode;
    var $myCustomerInvoiceDescription;
    var $myCustomerInvoiceRef;
    var $myCardHoldersName;
    var $myCardNumber;
    var $myCardExpiryMonth;
    var $myCardExpiryYear;
    var $myTrxnNumber;
    var $myOption1;
    var $myOption2;
    var $myOption3;
    var $CVN;

    var $myResultTrxnStatus;
    var $myResultTrxnNumber;
    var $myResultTrxnOption1;
    var $myResultTrxnOption2;
    var $myResultTrxnOption3;
    var $myResultTrxnReference;
    var $myResultTrxnError;
    var $myResultAuthCode;
    var $myResultReturnAmount;
    
    var $myError;
    var $myErrorMessage;

    /***********************************************************************
     *** XML Parser - Callback functions                                 ***
     ***********************************************************************/
    function epXmlElementStart ($parser, $tag, $attributes) {
        $this->currentTag = $tag;
    }
    
    function epXmlElementEnd ($parser, $tag) {
        $this->currentTag = "";
    }
    
    function epXmlData ($parser, $cdata) {
        $this->xmlData[$this->currentTag] = $cdata;
    }
    
    /***********************************************************************
     *** SET values to send to eWAY                                      ***
     ***********************************************************************/
    function setCustomerID( $customerID ) {
        $this->myCustomerID = $customerID;
    }
    
    function setTotalAmount( $totalAmount ) {
        $this->myTotalAmount = $totalAmount;
    }
    
    function setCustomerFirstname( $customerFirstname ) {
        $this->myCustomerFirstname = $customerFirstname;
    }
    
    function setCustomerLastname( $customerLastname ) {
        $this->myCustomerLastname = $customerLastname;
    }
    
    function setCustomerEmail( $customerEmail ) {
        $this->myCustomerEmail = $customerEmail;
    }
    
    function setCustomerAddress( $customerAddress ) {
        $this->myCustomerAddress = $customerAddress;
    }
    
    function setCustomerPostcode( $customerPostcode ) {
        $this->myCustomerPostcode = $customerPostcode;
    }
    
    function setCustomerInvoiceDescription( $customerInvoiceDescription ) {
        $this->myCustomerInvoiceDescription = $customerInvoiceDescription;
    }
    
    function setCustomerInvoiceRef( $customerInvoiceRef ) {
        $this->myCustomerInvoiceRef = $customerInvoiceRef;
    }
    
    function setCardHoldersName( $cardHoldersName ) {
        $this->myCardHoldersName = $cardHoldersName;
    }
    
    function setCardNumber( $cardNumber ) {
        $this->myCardNumber = $cardNumber;
    }
    
    function setCardExpiryMonth( $cardExpiryMonth ) {
        $this->myCardExpiryMonth = $cardExpiryMonth;
    }
    
    function setCardExpiryYear( $cardExpiryYear ) {
        $this->myCardExpiryYear = $cardExpiryYear;
    }
    
    function setTrxnNumber( $trxnNumber ) {
        $this->myTrxnNumber = $trxnNumber;
    }
    
    function setOption1( $option1 ) {
        $this->myOption1 = $option1;
    }
    
    function setOption2( $option2 ) {
        $this->myOption2 = $option2;
    }
    
    function setOption3( $option3 ) {
        $this->myOption3 = $option3;
    }

    function setCVN( $CVN ) {
        $this->myCVN = $CVN;
    }

    /***********************************************************************
     *** GET values returned by eWAY                                     ***
     ***********************************************************************/
    function getTrxnStatus() {
        return $this->myResultTrxnStatus;
    }
    
    function getTrxnNumber() {
        return $this->myResultTrxnNumber;
    }
    
    function getTrxnOption1() {
        return $this->myResultTrxnOption1;
    }
    
    function getTrxnOption2() {
        return $this->myResultTrxnOption2;
    }
    
    function getTrxnOption3() {
        return $this->myResultTrxnOption3;
    }
    
    function getTrxnReference() {
        return $this->myResultTrxnReference;
    }
    
    function getTrxnError() {
        return $this->myResultTrxnError;
    }
    
    function getAuthCode() {
        return $this->myResultAuthCode;
    }
    
    function getReturnAmount() { 
        return $this->myResultReturnAmount;
    }

    function getCVN() { 
        return $this->myCVN;
    }

    function getError()
    {
        if( $this->myError != 0 ) {
            // Internal Error
            return $this->myError;
        } else {
            // eWAY Error
            if( $this->getTrxnStatus() == 'True' ) {
                return EWAY_TRANSACTION_OK;
            } elseif( $this->getTrxnStatus() == 'False' ) {
                return EWAY_TRANSACTION_FAILED;
            } else {
                return EWAY_TRANSACTION_UNKNOWN;
            }
        }
    }

    function getErrorMessage()
    {
        if( $this->myError != 0 ) {
            // Internal Error
            return $this->myErrorMessage;
        } else {
            // eWAY Error
            return $this->getTrxnError();
        }
    }

    /***********************************************************************
     *** Class Constructor                                               ***
     ***********************************************************************/
    function EwayPayment( $customerID = EWAY_DEFAULT_CUSTOMER_ID, $gatewayURL = EWAY_DEFAULT_GATEWAY_URL ) {
        $this->myCustomerID = $customerID;
        $this->myGatewayURL = $gatewayURL;
    }

    /***********************************************************************
     *** Business Logic                                                  ***
     ***********************************************************************/
    function doPayment() {
        $xmlRequest = "<ewaygateway>".
                "<ewayCustomerID>".htmlentities( $this->myCustomerID )."</ewayCustomerID>".
                "<ewayTotalAmount>".htmlentities( $this->myTotalAmount)."</ewayTotalAmount>".
                "<ewayCustomerFirstName>".htmlentities( $this->myCustomerFirstname )."</ewayCustomerFirstName>".
                "<ewayCustomerLastName>".htmlentities( $this->myCustomerLastname )."</ewayCustomerLastName>".
                "<ewayCustomerEmail>".htmlentities( $this->myCustomerEmail )."</ewayCustomerEmail>".
                "<ewayCustomerAddress>".htmlentities( $this->myCustomerAddress )."</ewayCustomerAddress>".
                "<ewayCustomerPostcode>".htmlentities( $this->myCustomerPostcode )."</ewayCustomerPostcode>".
                "<ewayCustomerInvoiceDescription>".htmlentities( $this->myCustomerInvoiceDescription )."</ewayCustomerInvoiceDescription>".
                "<ewayCustomerInvoiceRef>".htmlentities( $this->myCustomerInvoiceRef )."</ewayCustomerInvoiceRef>".
                "<ewayCardHoldersName>".htmlentities( $this->myCardHoldersName )."</ewayCardHoldersName>".
                "<ewayCardNumber>".htmlentities( $this->myCardNumber )."</ewayCardNumber>".
                "<ewayCardExpiryMonth>".htmlentities( $this->myCardExpiryMonth )."</ewayCardExpiryMonth>".
                "<ewayCardExpiryYear>".htmlentities( $this->myCardExpiryYear )."</ewayCardExpiryYear>".
                "<ewayTrxnNumber>".htmlentities( $this->myTrxnNumber )."</ewayTrxnNumber>".
                "<ewayCVN>".htmlentities( $this->myCVN )."</ewayCVN>".
                "<ewayOption1>".htmlentities( $this->myOption1 )."</ewayOption1>".
                "<ewayOption2>".htmlentities( $this->myOption2 )."</ewayOption2>".
                "<ewayOption3>".htmlentities( $this->myOption3 )."</ewayOption3>".
                "</ewaygateway>";

        /* Use CURL to execute XML POST and write output into a string */
        $ch = curl_init( $this->myGatewayURL );
        curl_setopt( $ch, CURLOPT_POST, 1 );
        curl_setopt( $ch, CURLOPT_POSTFIELDS, $xmlRequest );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 240 );
        $xmlResponse = curl_exec( $ch );
        
        // Check whether the curl_exec worked.
        if( curl_errno( $ch ) == CURLE_OK ) {
            // It worked, so setup an XML parser for the result.
            $this->parser = xml_parser_create();
            
            // Disable XML tag capitalisation (Case Folding)
            xml_parser_set_option ($this->parser, XML_OPTION_CASE_FOLDING, FALSE);
            
            // Define Callback functions for XML Parsing
            xml_set_object($this->parser, &$this);
            xml_set_element_handler ($this->parser, "epXmlElementStart", "epXmlElementEnd");
            xml_set_character_data_handler ($this->parser, "epXmlData");
            
            // Parse the XML response
            xml_parse($this->parser, $xmlResponse, TRUE);
            
            if( xml_get_error_code( $this->parser ) == XML_ERROR_NONE ) {
                // Get the result into local variables.
                $this->myResultTrxnStatus = $this->xmlData['ewayTrxnStatus'];
                $this->myResultTrxnNumber = $this->xmlData['ewayTrxnNumber'];
                if ( isset($this->xmlData['ewayTrxnOption1']) ) {
                	$this->myResultTrxnOption1 = $this->xmlData['ewayTrxnOption1'];
				}
				if ( isset($this->xmlData['ewayTrxnOption2']) ) {
                	$this->myResultTrxnOption2 = $this->xmlData['ewayTrxnOption2'];
				}
				if ( isset($this->xmlData['ewayTrxnOption3']) ) {
                	$this->myResultTrxnOption3 = $this->xmlData['ewayTrxnOption3'];
				}
                $this->myResultTrxnReference = $this->xmlData['ewayTrxnReference'];
                if ( isset($this->xmlData['ewayAuthCode']) ) {
                	$this->myResultAuthCode = $this->xmlData['ewayAuthCode'];
				}
                $this->myResultReturnAmount = $this->xmlData['ewayReturnAmount'];
                $this->myResultTrxnError = $this->xmlData['ewayTrxnError'];
                $this->myError = 0;
                $this->myErrorMessage = '';
            } else {
                // An XML error occured. Return the error message and number.
                $this->myError = xml_get_error_code( $this->parser ) + EWAY_XML_ERROR_OFFSET;
                $this->myErrorMessage = xml_error_string( $myError );
            }
            // Clean up our XML parser
            xml_parser_free( $this->parser );
        } else {
            // A CURL Error occured. Return the error message and number. (offset so we can pick the error apart)
            $this->myError = curl_errno( $ch ) + EWAY_CURL_ERROR_OFFSET;
            $this->myErrorMessage = curl_error( $ch );
        }
        // Clean up CURL, and return any error.
        curl_close( $ch );
        return $this->getError();
    }
}
?>
