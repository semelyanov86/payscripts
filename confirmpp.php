<?php
/*
Accept PayPal
https://developer.paypal.com/webapps/developer/docs/classic/ipn/integration-guide/IPNandPDTVariables/#id091EB04C0HS
  'payment_type' => 'echeck',
  'payment_date' => 'Fri Feb 17 2017 16:58:33 GMT+0300 (RTZ 2 (%u0437%u0438%u043C%u0430))',
  'payment_status' => 'Completed',
  'address_status' => 'confirmed',
  'payer_status' => 'verified',
  'first_name' => 'John',
  'last_name' => 'Smith',
  'payer_email' => 'buyer@paypalsandbox.com',
  'payer_id' => 'TESTBUYERID01',
  'address_name' => 'John Smith',
  'address_country' => 'United States',
  'address_country_code' => 'US',
  'address_zip' => '95131',
  'address_state' => 'CA',
  'address_city' => 'San Jose',
  'address_street' => '123 any street',
  'business' => 'seller@paypalsandbox.com',
  'receiver_email' => 'seller@paypalsandbox.com',
  'receiver_id' => 'seller@paypalsandbox.com',
  'residence_country' => 'US',
  'item_name' => 'something',
  'item_number' => 'AK-1234',
  'quantity' => '1',
  'shipping' => '3.04',
  'tax' => '2.02',
  'mc_currency' => 'USD',
  'mc_fee' => '0.44',
  'mc_gross' => '12.34',
  'mc_gross_1' => '12.34',
  'txn_type' => 'web_accept',
  'txn_id' => '623582569',
  'notify_version' => '2.1',
  'custom' => 'xyz123',
  'invoice' => 'abc1234',
  'test_ipn' => '1',
  'verify_sign' => 'AFcWxV21C7fd0v3bYYYRCpSSRl31A4bv0kYnhVcddPTUEt-YjYFrWC2T' 
 */

define('APPPATH', 'whatever');

require_once 'CRMPayment.php';

$a = new CRMPayment();
w('Init');

$phone = '67771197';
w('Contact: '.$a->getCrmIdByPhone($phone));

$order = '16538';
w('Lead: ' . $a->getLeadByOrderNo($order));

$data = [
    'payer'      => '1046509',
    'related_to' => '1046541'
];

w('Payment: ' . $a->getPaymentId($data));


if ($_SERVER['REQUEST_METHOD'] != 'POST') exit();

//------Main-------------
$data = processPOST($_POST);

//------End--------------

function processPOST($request)
{

    //TODO check MD5
    $data = [];
    $data['phone']  = $request['customerNumber'];
    $data['amount'] = $request['orderSumAmount'];

    return $data;
}


function w($txt)
{
    print "$txt<br/>\n";
}
