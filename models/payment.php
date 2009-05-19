<?php
class Payment extends AppModel
{
	var $validate = array(
		'cc_name' => array(
			'rule' => 'notempty',
			'message' => 'Please enter your credit card name',
			'on' => 'create'
		),
		'cc_number' => array(
			'rule' => array('cc', array('mc', 'visa', 'amex'), true),
			'message' => 'Please enter a valid credit card number',
			'required' => true,
			'on' => 'create'
		),
		'cc_security' => array(
			'rule' => 'notempty',
			'message' => 'Please enter the credit card security code',
			'on' => 'create'
		),
	);

	var $cardTypes = array(
		'amex' => '/^3[4|7]\\d{13}$/',
		'bankcard' => '/^56(10\\d\\d|022[1-5])\\d{10}$/',
		'diners'   => '/^(?:3(0[0-5]|[68]\\d)\\d{11})|(?:5[1-5]\\d{14})$/',
		'disc'     => '/^(?:6011|650\\d)\\d{12}$/',
		'electron' => '/^(?:417500|4917\\d{2}|4913\\d{2})\\d{10}$/',
		'enroute'  => '/^2(?:014|149)\\d{11}$/',
		'jcb'      => '/^(3\\d{4}|2100|1800)\\d{11}$/',
		'maestro'  => '/^(?:5020|6\\d{3})\\d{12}$/',
		'mc'       => '/^5[1-5]\\d{14}$/',
		'solo'     => '/^(6334[5-9][0-9]|6767[0-9]{2})\\d{10}(\\d{2,3})?$/',
		'switch'   => '/^(?:49(03(0[2-9]|3[5-9])|11(0[1-2]|7[4-9]|8[1-2])|36[0-9]{2})\\d{10}(\\d{2,3})?)|(?:564182\\d{10}(\\d{2,3})?)|(6(3(33[0-4][0-9])|759[0-9]{2})\\d{10}(\\d{2,3})?)$/',
		'visa'     => '/^4\\d{12}(\\d{3})?$/',
		'voyager'  => '/^8699[0-9]{11}$/'
	);

	function beforeSave() {
		if ( !empty($this->data['Payment']['cc_number']) ) {
			if ( empty($this->data['Payment']['cc_type']) ) {
				$this->data['Payment']['cc_type'] = $this->getCardType($this->data['Payment']['cc_number']);
			}
			if ( empty($this->data['Payment']['cc_last4']) ) {
				$this->data['Payment']['cc_last4'] = substr($this->data['Payment']['cc_number'], -4);
			}
		}
		return true;
	}

	function getCardType($number)
	{
		foreach ($this->cardTypes as $type => $regex) {
			if ( preg_match($regex, $number) ) {
				return $type;
			}
		}
	}
}
?>
