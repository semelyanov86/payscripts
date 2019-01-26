<?php
/**
    Accept cloudPayments
    https://cloudpayments.ru/docs/notifications
    verify: HMAC
    valid ips: 130.193.70.192 185.98.85.109

    Fields:
    TransactionId
    InvoiceId - LeadNo | SalesOrderNo | OrderId
    AccountId - Phone | Name | Mail ...
    Amount
    Status: Complited
 */

date_default_timezone_set('Europe/Moscow');
ini_set('display_errors', 0);
error_reporting(0);

$ipList = ['130.193.70.192', '185.98.85.109'];
$ip = $_SERVER['REMOTE_ADDR'];

//Log all requests
w($ip . ((in_array($ip, $ipList))?'':', invalid'));

if ($_SERVER['REQUEST_METHOD'] != 'POST') quit('No direct access');

//------Main-------------
define('APPPATH', '/var/www/dostavka');
$msgs = [
    'MSG_LEAD_NOT_FOUND' =>
        'Не найдено Обращение.',
    'MSG_CONVERTED_LEAD_WO_ORDERNO' =>
        'Найдено преобразованное Обращение. Невозможно установить Номер заказа.',
    'MSG_NODATA' =>
        'Нет данных для CRM',
    'MSG_SO_NOT_FOUND' =>
        'Не найден Заказ по указанному номеру.',
    'MSG_ORDER_ALREADY_PAYED' =>
        'поле Оплачено уже равно Итогу',
    'MSG_PAYED_WO_PAYMENT' =>
        'Заказ Оплачен но не найден Платеж в статусе Выполнен',
    'MSG_NO_SCHED_PAYMENT' =>
        'не найден Платеж в статусе Запланирован',
    'MSG_EXCESSIVE' =>
        'сумма Платежа превышает Доплату',
    'MSG_MORE_THEN_ONE' =>
        'Найдено несколько заказов с таким номером'
];

$data = processPOST($_POST);

//return early - on slow netowrk
header('Content-Type: application/json');
print json_encode(['code' => 0]);

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

require_once 'CRMPayment.php';

//check duplicates
$alreadyProcessed = transactExists($data['cloudid']);

if ($alreadyProcessed) quit('Nothing new: ' . $data['cloudid']);

if ($data['amount'] == 0) quit('Zero amount');

$a = new CRMPayment('CloudPayments');

$marker = $data['crm'];
$crmData['description'] =
    (($data['cloudid']) ? 'Track: ' . $data['cloudid']:'')
    . (($data['crm'])   ? ', Invoice: ' . $data['crm']:'')
    . (($data['acc'])   ? ', Account: ' . $data['acc']:'')
    . (($data['mail'])  ? ', Mail: ' . $data['mail']:'')
    . (($data['info'])  ? ', Info: ' . $data['info']:'');

switch ($marker) {
    case (bool)preg_match('/^LEA\d+$/', $data['descr']):
        $marker = $data['descr'];
    case substr($marker,0,3) == 'LEA':
        $leadData = $a->getLeadByLeadNo($marker);
        if ($leadData === false){
            $a->msg[] = 'MSG_LEAD_NOT_FOUND';
            break;
        }
        if ($leadData['converted'] == 0){
            $crmData['payer'] = $leadData['crmid'];
            break;
        }
        //$a->msg[] = 'MSG_LEAD_IS_CONVERTED';
        //check linked order
        $crmId = $a->getOrderByLead($leadData['crmid']);
        if ($crmId === false) {
            $a->msg[] = 'MSG_SO_NOT_FOUND';
            break;
        }
        if ($crmId['type'] == 'order') {
            $crmData['payer']      = $crmId['payer'];
            $crmData['related_to'] = $crmId['id'];
        }
    break;
    case (bool)preg_match('/^\d+$/',$marker):
        $crmId = $a->getSalesorderByOrderNo($marker);
        if ($crmId === false) {
            $a->msg[] = 'MSG_SO_NOT_FOUND';
            break;
        }
        if ($crmId['type'] == 'lead') {
            $crmData['payer'] = $crmId['id'];
        }
        if ($crmId['type'] == 'order') {
            $crmData['payer']      = $crmId['payer'];
            $crmData['related_to'] = $crmId['id'];
        }
    break;
    default:
        $a->msg[] = 'MSG_NODATA';
}

//Last outpost - check if Account contains Phone Number
if (
    empty($crmData['payer'])
    && empty($crmData['related_to'])
    && ((bool)preg_match('/^.*(\d{10})$/', $data['acc'], $m))
) {
    $crmid = $a->getCRMIdByPhone($m[1]);
    if (!is_bool($crmid)) {
        //$a->msg[] = 'MSG_PHONE_LOOKUP';
        $crmData['payer'] = $crmid;
    }
}

$payid = $a->createPayment($data['amount'], $crmData);

//Log payment
/*
w(
    'Created: '. $payid
    . ((!!$a->msg)?implode('|',$a->msg):'')
);
 */

if ($a->msg) {
    $url = 'http://dostavka.eliteflower.ru/index.php?'
        .'module=SPPayments&view=Detail&record=' . $payid;
    $link = '<a href="'.$url.'">Ссылка</a>';
    $body = "Создан Платеж #{$payid} ({$link})<br/>"
        . concat($msgs, $a->msg)
        . "<pre>"
        . var_export($data, true)
        . "</pre>";
    $a->notify($body);
}
//*
//Log valid payments Notifications
$db = PearDatabase::getInstance();

$sql = 'INSERT INTO pin_payments (track,amount,invoice,acc,mail,name,descr,crmdata)
    VALUES (?,?,?,?,?,?,?,?)';

$results = [];
if ($crmData['payer']) $results[] = "p: {$crmData['payer']}";
if ($crmData['related_to']) $results[] = "r: {$crmData['related_to']}";
if ($payid) $results[] = $payid;
if ($a->msg) $results[] = implode(',',$a->msg);

$db->pquery( $sql,[
    $_POST['TransactionId'],
    $_POST['Amount'],
    $_POST['InvoiceId'],
    $_POST['AccountId'],
    $_POST['Email'],
    $_POST['Name'],
    $_POST['Description'],
    implode(', ', $results)
]);
//*/

exit();
//------End--------------

function processPOST($request)
{
    /* //raw
    $raw_post_data = file_get_contents('php://input');
    $raw_post_array = explode('&', $raw_post_data);
    */
    $data = [];
    $data['hash']     = $_SERVER['HTTP_CONTENT_HMAC'];
    $data['cloudid']  = trim($request['TransactionId']);
    $data['crm']      = trim($request['InvoiceId']);
    $data['acc']      = trim($request['AccountId']);
    $data['mail']     = $request['Email'];
    $data['amount']   = trim($request['Amount']);
    $data['info']     = trim($request['Description']);
    $data['status']   = $request['Status'];

    //w(var_export($data,1));
    return $data;
}

function transactExists($transId)
{
    $db = PearDatabase::getInstance();

    $sql = 'SELECT * FROM pin_payments WHERE track = ?';
    $exist = $db->pquery($sql, [$transId]);

    return ($db->num_rows($exist) > 0);
}

/**
 * Insert new log row
 */
function logPOST()
{
    $db = PearDatabase::getInstance();

    $sql = 'INSERT INTO pin_payments (track,amount,invoice,acc,mail,name,descr)
        VALUES (?,?,?,?,?,?,?)';

    $newTrack = $db->pquery( $sql,[
        $_POST['TransactionId'],
        $_POST['Amount'],
        $_POST['InvoiceId'],
        $_POST['AccountId'],
        $_POST['Email'],
        $_POST['Name'],
        $_POST['Description'],
    ]);

    return $db->getAffectedRowCount($newTrack)?$_POST['TransactionId']:false;
}

/**
 * Update log table with script results
 */
function updateCrmData($crmData, $payid = false, $a = false)
{
    //Log valid payments Notifications
    $db = PearDatabase::getInstance();

    $sql = 'UPDATE pin_payments SET crmdata = ?
        WHERE track = ?';

    $results = [];
    if ($crmData['payer']) $results[] = "p: {$crmData['payer']}";
    if ($crmData['related_to']) $results[] = "r: {$crmData['related_to']}";
    if ($payid) $results[] = $payid;
    if ($a->msg) $results[] = implode(',',$a->msg);

    $updTrack = $db->pquery( $sql,[implode(', ', $results), $track]);

    return $db->getAffectedRowCount($updTrack)?$_POST['TransactionId']:false;
}

/*
 * @param <array> associative, translations
 * @param <array> plain, message labels
 */
function concat($trans, $list)
{
    $translated = [];
    foreach ($list as $msg){
        $translated[] = (array_key_exists($msg, $trans))?
            $trans[$msg] : $msg;
    }

    return implode(';<br>', $translated);
}

function w($txt)
{
    $ts = date('Ymd H:i:s / ');
    file_put_contents(
        'vt.log',
        "{$ts}{$txt}\n",
        FILE_APPEND
    );
}

function hmac()
{
    $s = hash_hmac('sha256', 'Message', 'secret', true);
    echo base64_encode($s);
}

function quit($msg)
{
    exit($msg);
}

