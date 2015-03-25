<?php
/*
    Copyright 2009-2014 Edward L. Platt <ed@elplatt.com>

    This file is part of the Seltzer CRM Project
    ipn.php - PayPal Payment module IPN interface

    Seltzer is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    any later version.

    Seltzer is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Seltzer.  If not, see <http://www.gnu.org/licenses/>.
*/

// PayPal IPN receiver
// https://developer.paypal.com/docs/classic/ipn/ht_ipn/
// https://developer.paypal.com/docs/classic/ipn/gs_IPN/

// We must be authenticated to insert into the database
session_start();
$_SESSION['userId'] = 1;
// Save path of directory containing index.php
$crm_root = realpath(dirname(__FILE__) . '/../..');
// Bootstrap the crm
require_once('../../include/crm.inc.php');

global $config_paypal_email;
global $config_paypal_sandbox;

// Read POST data
// reading posted data directly from $_POST causes serialization
// issues with array data in POST. Reading raw POST data from input stream instead.
$raw_post_data = file_get_contents('php://input');
$raw_post_array = explode('&', $raw_post_data);
$myPost = array();
foreach ($raw_post_array as $keyval) {
	$keyval = explode ('=', $keyval);
	if (count($keyval) == 2)
		$myPost[$keyval[0]] = urldecode($keyval[1]);
}
// read the post from PayPal system and add 'cmd'
$req = 'cmd=_notify-validate';
if(function_exists('get_magic_quotes_gpc')) {
	$get_magic_quotes_exists = true;
}
foreach ($myPost as $key => $value) {
	if($get_magic_quotes_exists == true && get_magic_quotes_gpc() == 1) {
		$value = urlencode(stripslashes($value));
	} else {
		$value = urlencode($value);
	}
	$req .= "&$key=$value";
}

// Post IPN data back to PayPal to validate the IPN data is genuine
// Without this step anyone can fake IPN data

if($config_paypl_sandbox == true) {
	$paypal_url = "https://www.sandbox.paypal.com/cgi-bin/webscr";
} else {
	$paypal_url = "https://www.paypal.com/cgi-bin/webscr";
}

$ch = curl_init($paypal_url);
if ($ch == FALSE) {
	error_log("PayPal IPN: curl_int failed");
	return FALSE;
}

curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);

// Set TCP timeout to 30 seconds
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));

// CONFIG: Please download 'cacert.pem' from "http://curl.haxx.se/docs/caextract.html" and set the directory path
// of the certificate as shown below. Ensure the file is readable by the webserver.
// This is mandatory for some environments.

//$cert = __DIR__ . "./cacert.pem";
//curl_setopt($ch, CURLOPT_CAINFO, $cert);

$res = curl_exec($ch);
if (curl_errno($ch) != 0) // cURL error
{
	curl_close($ch);
	die();

} else {
		curl_close($ch);
}

// Inspect IPN validation result and act accordingly

// Split response headers and payload, a better way for strcmp
$tokens = explode("\r\n\r\n", trim($res));
$res = trim(end($tokens));

if (strcmp ($res, "VERIFIED") == 0) {
	// check whether the payment_status is Completed
	// check that txn_id has not been previously processed
	// check that payment_amount/payment_currency are correct
	// process payment and mark item as paid.

	if ($_POST['payment_status'] != 'Completed'){
		error_log("PayPal IPN: payment_status is " . $_POST['payment_status'] );
		die();
	}

	if ($_POST['receiver_email'] != $config_paypal_email){
		error_log("PayPal IPN: receiver email is " . $_POST['receiver_email']);
		die();
	}

	// Check if the payment already exists
	// Skip transactions that have already been imported
	$payment_opts = array(
		'filter' => array('confirmation' => $_POST['txn_id'] )
	);
	$data = crm_get_data('payment', $payment_opts);
	if (count($data) > 0) {
		error_log("PayPal IPN: " . count($data));
		die();
	}
	// Determine cid
	$cid = $_POST['item_number'];
	if (empty($cid)) {
		// Check if the paypal email is linked to a contact
		$opts = array('filter'=>array('paypal_email'=>$_POST['payer_email']));
		$contact_data = paypal_payment_contact_data($opts);
		if (count($contact_data) > 0) {
			$cid = $contact_data[0]['cid'];
		}
	}
	$payment = array(
		'date' =>date('Y-m-d')
		, 'credit_cid' => $cid
		, 'code' => $_POST['mc_currency']
		, 'value' => (string)payment_parse_currency($_POST['mc_gross'],$_POST['mc_currency'])['value']
		, 'description' => $_POST['item_name']
		, 'method' => 'Paypal'
		, 'confirmation' => $_POST['txn_id']
		, 'paypal_email' => $_POST['payer_email']
	);
	$payment = payment_save($payment);
	// Log out
	$_SESSION['userId'] = 0;
	session_destroy();
} else if (strcmp ($res, "INVALID") == 0) {
	// log for manual investigation
	// Add business logic here which deals with invalid IPN messages
	error_log("PayPal IPN: Invalid IPN");
	die();
}
