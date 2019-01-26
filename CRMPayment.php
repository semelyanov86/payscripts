<?php
if (!defined('APPPATH')) exit('No direct access');

//set_include_path('../');

chdir('../');
require_once 'config.inc.php';
require_once 'data/CRMEntity.php';
require_once 'include/database/PearDatabase.php';
require_once 'modules/Users/Users.php';
//ModTracker reqs
require_once 'include/utils/utils.php';
require_once 'includes/Loader.php';
require_once 'includes/runtime/BaseModel.php';
require_once 'includes/runtime/Globals.php';
require_once 'includes/runtime/LanguageHandler.php';

require_once 'modules/SPPayments/SPPayments.php';

$adb  = PearDatabase::getInstance();
$current_user = Users::getActiveAdminUser();

/**
 * Class for payments processing
 */
class CRMPayment
{
    public $msg = [];
    private $type = '';
    const DBG = true;
    //const MAILBOX = 'kazirackaya.d@flowersoffantasy.ru';
    const MAILBOX = 'denis.k@pinstudio.ru';

    /**
     * Construct an instance
     * globals PearDatabase, Users
     *
     * @param str $pType label of payments system
     *
     * @return $this;
     */
    function __construct($pType = '')
    {
        $this->type = $pType;
        $this->db = PearDatabase::getInstance();
        $this->usr = Users::getActiveAdminId();
    }

    /**
     * Phone lookup
     * 
     * @param str $phone number
     *
     * @return <string> Contact|Lead crmid
     */
    function getCRMIdByPhone($phone)
    {
        $sql = 'SELECT vc.crmid FROM vtiger_pbxmanager_phonelookup vpp
                LEFT JOIN vtiger_crmentity vc ON vc.crmid = vpp.crmid
            WHERE deleted = 0
                AND (
                    rnumber LIKE ?
                    OR fnumber LIKE ?
                )
            ORDER BY crmid DESC LIMIT 1';
        $result = $this->db->pquery($sql, ['%'.$phone, '%'.$phone]);

        return ($this->db->num_rows($result)>0)
            ? $result->FetchRow()['crmid']
            : false;
    }

    /**
     * SQL Lead lookup by LEA123
     *
     * @param str $leadNo Lead CRM number
     *
     * @return mixed false | Lead data
     */
    public function getLeadByLeadNo($leadNo)
    {
        $sql = 'SELECT vc.crmid, converted, vlc.cf_845 orderno
            FROM vtiger_leaddetails vld
                LEFT JOIN vtiger_leadscf vlc
                    using (leadid)
                LEFT JOIN vtiger_crmentity vc
                    ON vc.crmid = vld.leadid
            WHERE lead_no = ?
                AND deleted = 0
            ORDER BY crmid DESC LIMIT 1';
        $result = $this->db->pquery($sql, [$leadNo]);

        return ($this->db->num_rows($result)>0)
            ? $result->FetchRow()
            : false;
    }

    /*
     * @param <string> Lead CRM Id
     * @return <string> Order CRM Id
     */
    public function getOrderByLead($leadid)
    {
        $sql = 'SELECT
            leadid,
            vlcr.salesorderid, cf_851 soOrderId, cf_834 soPayed, cf_835 soDoplata, total,
            vlcr.contactid
            FROM vtiger_leadcontrel vlcr
                LEFT JOIN vtiger_salesorder vso   USING (salesorderid)
                LEFT JOIN vtiger_crmentity vcso   ON salesorderid = vcso.crmid
                LEFT JOIN vtiger_salesordercf vsc USING (salesorderid)
            WHERE vcso.deleted = 0
                AND leadid = ?
            ORDER BY leadid DESC
            LIMIT 1';
        $result = $this->db->pquery($sql, [$leadid]);
        $crmid = false;

        if ($this->db->num_rows($result)>0) {
            $data = $result->FetchRow();
            $crmid = [
                'type' => 'order',
                'id'   => $data['salesorderid'],
                'payer'=> $data['contactid']
            ];
        }
        return $crmid;
    }

    /*
     * Get Order directly from orders table
     * @param <string> SalesOrder CRM Number
     * @return <Array> Order CRM data
     */
    public function getOrderByCRMOrderNo($orderNo)
    {
        $sql = 'SELECT crmid, salesorderid, contactid
            FROM vtiger_salesorder
            LEFT JOIN vtiger_crmentity vc ON salesorderid = crmid
            WHERE deleted = 0
                AND salesorder_no = ?
                AND vc.createdtime > (NOW() - INTERVAL 14 DAY)';
        //time hack to avoid linking to older Orders
        $result = $this->db->pquery($sql, [$orderNo]);
        $nr = $this->db->num_rows($result);

        if ($nr == 0) return false;
        if ($nr > 1) $this->msg[] = 'MSG_MORE_THEN_ONE';

        $data = $result->FetchRow();

        return [
            'type' => 'order',
            'id'   => $data['salesorderid'],
            'payer'=> $data['contactid'],
        ];
    }
    /*
     * Get order indirectly via converted table
     * $crmid['type'] string lead | order
     * $crmid['id']   string CRM entity id
     * $crmid['payer'] string in case if type == order, Contact entity id
     * @param <string> OrderId | SalesOrder Number | Lead Number
     * @return <array> $crmid
     */
    public function getSalesorderByOrderNo($soNo)
    {
        // find lead contact order
        $sql = 'SELECT
             leadid, lead_no, vl.converted, vcl.deleted lDel, cf_845 lOrderId, cf_1051 lPayed,
            salesorderid, salesorder_no, vcso.deleted soDel, cf_851 soOrderId, cf_834 soPayed, cf_835 soDoplata, total,
            vso.contactid
            FROM `vtiger_leadscf` vlc
                LEFT JOIN vtiger_leaddetails vl   USING (leadid)
                LEFT JOIN vtiger_crmentity vcl    ON leadid = vcl.crmid
                LEFT JOIN vtiger_leadcontrel vlcr USING (leadid)
                LEFT JOIN vtiger_salesorder vso   USING (salesorderid)
                LEFT JOIN vtiger_crmentity vcso   ON salesorderid = vcso.crmid
                LEFT JOIN vtiger_salesordercf vsc USING (salesorderid)
            WHERE vcl.deleted = 0
                AND (cf_845 = ? OR salesorder_no = ?)
            ORDER BY vlc.leadid DESC
            LIMIT 1'; //there could be more
        $result = $this->db->pquery($sql, [$soNo, $soNo]);
        $crmid = false;

        if ($this->db->num_rows($result) != 1) {
            //Order without a Lead case
            return $this->getOrderByCRMOrderNo($soNo);
        }

        $data = $result->FetchRow();
        if ($data['converted'] == 0) {
            $crmid = [
                'type' => 'lead',
                'id'   => $data['leadid']
            ];
        } else {
            $crmid = [
                'type' => 'order',
                'id'   => $data['salesorderid'],
                'payer'=> $data['contactid'],
            ];
        }

        return $crmid;
    }

    /*
     * Retrive CRM Payment Id by attribs
     * $args['payer'] <str> payer CRM id
     * $args['related_to'] <str> CRM id to which Payment is related to
     * @param $args fields for Payment lookup
     * @param <Array> default payment status
     * @return <mixed>
     *      false if nothing found
     *      single id if only one record found
     *      <Array> of ids otherwise
     */
    private function getPaymentsId($args, $queryArgs = ['Scheduled'])
    {
        $where = '';
        $keys = ['payer', 'related_to'];
        foreach ($keys as $k) {
            if (array_key_exists($k, $args)) {
                $where .= " AND {$k} = ? ";
                $queryArgs[] = $args[$k];
            }
        }

        $sql = 'SELECT vc.crmid
            FROM sp_payments sp
                LEFT JOIN vtiger_crmentity vc
                    ON vc.crmid = sp.payid
            WHERE deleted = 0
                AND spstatus = ?'
            . $where
            . ' ORDER BY crmid DESC LIMIT 1';
        $result = $this->db->pquery($sql, $queryArgs);

        $nr = $this->db->num_rows($result);
        if ($nr == 0) return false;

        if ($nr == 1) return $result->FetchRow()['crmid'];

        $ids = [];
        while ($row = $result->FetchRow()) {
            $ids[] = $row['crmid'];
        };

        return $ids;
    }

    /*
     *
     * @param <int> mandatory payment amount
     * @param <array> payment additional data:
     */
    public function createPayment($amount, $data = [])
    {
        $this->amount = $amount;
        $ts = date('Y-m-d');
        $status = 'Executed';

        $props  = ['payer', 'related_to', 'description'];
        if (array_key_exists('related_to', $data)) {
            $soId = $data['related_to'];
            //$this->processOrder($soId, $amount, $data['description']);
            /*
             * $total, $payed, $remains
             * $this->amount
             * $existing payed and remains
             *
             * if amount > total: notify
             *
             * if foundOne:
             *   if foundRemain->amount == total == amount: change status
             *   if foundRemain->amount < amount: remove & createPayment
             *   if foundRemain->amount > amount: reduce & createPayed
             *
             * if not found
             *   if amount == total: create
             *   if amount < total: createPayed & Remain
             *   if amount > total: notify
             */
            $so = Vtiger_Record_Model::getInstanceById($soId, 'SalesOrder');
            $doplata = $so->get('cf_835');

            if ($so->get('total') == $so->get('cf_834')) {
                $this->msg[] = 'MSG_ORDER_ALREADY_PAYED';
                $id = $this->getPaymentsId(
                    ['related_to' => $soId],
                    ['Executed']
                );
                if (!is_bool($id)) return 'Already ' . $id;
                if ($id === false) $this->msg[] = 'MSG_PAYED_WO_PAYMENT';
            }

            if ($amount == $doplata) {
                $updid = $this->updatePayment($soId, $data['description'], $amount);
                if (!is_bool($updid)) return $updid;

                if ($id === false) $this->msg[] = 'MSG_NO_SCHED_PAYMENT';
            } else {
                $rest = $doplata - $amount;
                if (($rest < -1) && ($doplata != 0)) {
                    $this->msg[] = 'MSG_EXCESSIVE';
                    //return 'Excess: ' . $rest;
                }

                $wipeResult = $this->cleanupPaymentsForOrder($soId);

                if ($this::DBG) {
                    w(
                    "rest: {$rest}, dop: {$doplata}, amnt: {$amount}, "
                    . var_export($wipeResult, true)
                    );
                }
            }
        }

        $pay = new SPPayments();
        $pay->column_fields['amount']   = $amount;
        $pay->column_fields['pay_date'] = $ts;
        $pay->column_fields['cf_648']   = $this->type;
        $pay->column_fields['spstatus'] = $status;
        $pay->column_fields['assigned_user_id'] = $this->usr;
        foreach ($props as $key){
            if (array_key_exists($key, $data))
                $pay->column_fields[$key] = $data[$key];
        }

        $result = $pay->save('SPPayments');

        return $pay->id;
    }

    /*
    * Move related Scheduled payments to recycle bin
    */
    private function cleanupPaymentsForOrder($soId)
    {
        $ids = $this->getPaymentsId(['related_to' => $soId]);
        if (is_bool($ids)) return $ids;

        $pay = new SPPayments();
        if (is_array($ids)) {
            //TODO try
            foreach ($ids as $payid){
                $pay->trash('SPPayments', $payid);
            }
            return count($ids);
        }

        $pay->trash('SPPayments', $ids);

        return 1;
    }

    private function updatePayment($soId, $info = '', $amount = 0)
    {
        $id = $this->getPaymentsId(['related_to' => $soId]);
        if (is_bool($id)) return false;

        $pp = Vtiger_Record_Model::getInstanceById($id, 'SPPayments');

        if ($pp->get('amount') != $amount) return false;

        $pp->set('mode','edit');
        $pp->set('spstatus', 'Executed');
        if ($info){
            $pp->set('description', $info);
        }
        $pp->save();

        return $id;
    }

    public function processOrder($soId, $amount, $inf = '')
    {
        $so = Vtiger_Record_Model::getInstanceById($soId, 'SalesOrder');
        $doplata = $so->get('cf_835');

        if ($so->get('total') == $so->get('cf_834')) {
            $this->msg[] = 'MSG_ORDER_ALREADY_PAYED';
            $id = $this->getPaymentsId(
                ['related_to' => $soId],
                ['Executed']
            );
            if (gettype($id) != 'boolean') return 'Already ' . $id;
            if ($id === false) $this->msg[] = 'MSG_PAYED_WO_PAYMENT';
        }

        if ($amount == $doplata) {
            $updid = $this->updatePayment($soId, $inf);
            if (!is_bool($updid)) return $updid;

            if ($id === false) $this->msg[] = 'MSG_NO_SCHED_PAYMENT';
        } else {
            $rest = $doplata - $amount;
            if ($rest < -1) return 'Excess: ' . $rest;
            $wipeResult = $this->cleanupPaymentsForOrder($soId);

            if ($this::DBG) {
                w(
                "rest: {$rest}, dop: {$doplata}, amnt: {$amount}, "
                . var_export($wipeResult, true)
                );
            }
        }
    }

    public function notify($body = '')
    {
        global $HELPDESK_SUPPORT_EMAIL_ID, $HELPDESK_SUPPORT_NAME;
        require_once 'modules/Emails/mail.php';
        $subject  = $this->type . ' Notification';

        return send_mail(
            'Payments',
            $this::MAILBOX,
            $HELPDESK_SUPPORT_NAME,
            $HELPDESK_SUPPORT_EMAIL_ID,
            $subject,
            $body,
            '','','','','',
            true
        );
    }

    function quit($reason = '')
    {
        $ts = date('Y-m-d H:i:s / ');
        if ($reason) {
            file_put_contents(
                'vt.log',
                $ts . $reason . "\n",
                FILE_APPEND
            );
        }
        exit(0);
    }
}


