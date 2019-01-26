<?php

chdir('../');
require_once 'includes/main/WebUI.php';
require_once 'include/Webservices/Revise.php';
require_once 'include/Webservices/Create.php';
require_once 'include/Webservices/Delete.php';
require_once 'modules/Users/Users.php';
require_once 'modules/SPPayments/SPPayments.php';

$current_user = Users::getActiveAdminUser();
$adb = PearDatabase::getInstance();

file_put_contents(
    'vt.log',
    sprintf("%s / %s / %s:%s %s\n",
        date('Ymd H:i:s'),
        $_SERVER['REMOTE_ADDR'],
        array_pop(explode('/',__FILE__)),
        __LINE__,
        var_export($_GET,1)
    ),
    FILE_APPEND
);

$data = processIncome($_GET);

if (is_bool($data)){
    header('Content-Type: application/json');
    echo json_encode(['status'=>'Err']);
    exit();
}

if ($data['status'] == 'Y') {
    //wsUpdate($data);
    wsCreatePayment($data);
} else {
    wsZeroPayments($data['id']);
}

/*
 * order - cf_845
 * status Y | N
 */

header('Content-Type: application/json');
echo json_encode(['status'=>'Ok']);

function processIncome($data)
{
    $keys = ['order', 'status', 'amount'];
    foreach ($keys as $field){
        if (!(array_key_exists($field, $data)
            && !empty($data[$field])
            && ($data['amount'] != '0.00'))
        ){
            return false;
        }
    }

    $leadid = findLeadBySiteId($data['order']);

    return empty($leadid)? false : [
        'id'      => $leadid,
        'cf_1051' => $data['amount'],
        'status'  => $data['status']
    ];
}

function findLeadBySiteId($id)
{
    $db = PearDatabase::getInstance();
    $db->database->SetFetchMode(2);
    $sql = "SELECT leadid FROM vtiger_leadscf
        LEFT JOIN vtiger_crmentity vc ON leadid = crmid
        WHERE cf_845 = ?
            AND deleted = 0
        ORDER BY leadid DESC LIMIT 1";
    $leadResult = $db->pquery($sql, [$id]);
    $nr = $db->num_rows($leadResult);

    if ($nr == 0) return false;

    return $leadResult->FetchRow()['leadid'];
}

function wsUpdate($data)
{
    if (empty($data)) return false;

    global $current_user;
    try {
        $wsid = vtws_getWebserviceEntityId('Leads', $data['id']);
        $data['id'] = $wsid;
        vtws_revise($data, $current_user);

        return true;
    } catch (WebServiceException $ex) {
        return $ex->getMessage();
    }
}

function wsZeroPayments($leadid)
{
    $db = PearDatabase::getInstance();
    $db->database->SetFetchMode(2);
    $sql = "SELECT payid FROM sp_payments
        LEFT JOIN vtiger_crmentity vc ON payid = crmid
        WHERE payer = ?
            AND deleted = 0
        ORDER BY payid DESC";
    $paymentsResult = $db->pquery($sql, [$leadid]);
    $nr = $db->num_rows($paymentsResult);

    if ($nr == 0) return false;

    while ($row = $db->fetch_array($paymentsResult)){
        wsDeletePayment($row['payid']);
    }

    return $nr;
}

function wsCreatePayment($data = [])
{
    if (empty($data)) return false;

    global $current_user;
    $paymentsMod   = 'SPPayments';
    $wsid = vtws_getWebserviceEntityId('Leads', $data['id']);

    $payment['payer']             = $wsid;
    $payment['pay_date']          = date('Y-m-d');
    $payment['amount']            = $data['cf_1051'];
    $payment['cf_648']            = 'CloudPayments';
    $payment['spstatus']          = 'Executed';
    $payment['assigned_user_id']  = $current_user->id;

    return vtws_create($paymentsMod, $payment, $current_user);
}

function wsDeletePayment($id)
{
    global $current_user;
    try {
        $wsid = vtws_getWebserviceEntityId('SPPayments', $id);
        vtws_delete($wsid, $current_user);
    } catch (WebServiceException $ex) {
            echo $ex->getMessage();
    }
}
