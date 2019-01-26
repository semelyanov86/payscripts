<?php
/*
Accept Yandex Money
https://tech.yandex.ru/money/doc/payment-solution/payment-notifications/payment-notifications-aviso-docpage/
verify
valid ip

action; paymentAviso
orderSumAmount;
orderSumCurrencyPaycash;
orderSumBankPaycash;
shopId;
invoiceId;
customerNumber; - phone
shopPassword
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

    $data = [];
    $data['phone']  = $request['customerNumber'];
    $data['amount'] = $request['orderSumAmount'];

    return $data;
}


function w($txt)
{
    print "$txt<br/>\n";
}
