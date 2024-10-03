<?php
namespace Opencart\Admin\Model\Extension\IpayOpencart\Payment;

use BtIpay\Opencart\Order\StatusService;
use Opencart\System\Engine\Model;

class BtIpay extends Model
{

    protected const FRONTEND_FLASH = "bt_ipay_frontend_flash";
    protected const ACCOUNT_COF_LISTENER_NAME = "bt_ipay_cof_display";
    public const CONFIG_KEY = "payment_bt_ipay";
    protected const TABLE_PAYMENTS = 'bt_ipay_payments';
    protected const TABLE_CARDS = 'bt_ipay_cards';
    protected const TABLE_REFUNDS = 'bt_ipay_refunds';
    public function install()
    {
        $this->addEvents();
        $this->createDatabaseTables();
        $this->allowSessionRestore();
        if ($this->getPartialRefundStatus() === 2) {
            $this->addCustomStatus();
        }
    }

    private function allowSessionRestore() {
        $this->load->model('setting/setting');
        $this->load->model('setting/store');

		foreach ($this->model_setting_store->getStores() as $store) {
            $this->model_setting_setting->editValue('config','config_session_samesite','Lax', $store['store_id']);
            $this->model_setting_setting->editValue('config','config_telephone_display','1', $store['store_id']);
            $this->model_setting_setting->editValue('config','config_telephone_required','1', $store['store_id']);
            
		}
        $this->model_setting_setting->editValue('config','config_telephone_display','1');
        $this->model_setting_setting->editValue('config','config_telephone_required','1');
        $this->model_setting_setting->editValue('config','config_session_samesite','Lax');
    }

    public function uninstall()
    {
        $this->removeEvents();
    }

    /**
     * Get config value,
     * If no custom config is present for this store use the value from store = 0
     *
     * @param string $code
     *
     * @return mixed
     */
    public function getConfig(string $code)
    {
        $storeId = $this->config->get('config_store_id');

        $this->load->model('setting/setting');

        $useMasterConfig = $this->model_setting_setting->getValue(self::CONFIG_KEY . "_customStoreConfig", (int)$storeId) !== "1";

        if ($useMasterConfig && $storeId != 0) {
            $storeId = 0;
        }
        return $this->model_setting_setting->getValue(
            self::CONFIG_KEY . "_" . $code,
            (int)$storeId
        );
    }

    private function addCustomStatus()
    {
        $this->db->query(
            "INSERT INTO `" . DB_PREFIX . "order_status` (`order_status_id`, `language_id`, `name`) VALUES (NULL, '".(int)$this->config->get('config_language_id')."', 'Partially Refunded')"
        );
		$sql = "SELECT * FROM `" . DB_PREFIX . "order_status` WHERE `language_id` = '" . (int)$this->config->get('config_language_id') . "' ORDER BY `name` ASC";
        $this->cache->delete('order_status.' . md5($sql));
    }

    public function getPartialRefundStatus(): int
    {
        $qry = $this->db->query(
            "SELECT `order_status_id` FROM `" . DB_PREFIX . "order_status` WHERE `name` = 'Partially Refunded'"
        );

        if ($qry->num_rows === 0 || !isset($qry->row["order_status_id"])) {
            return 2;
        }

        return intval($qry->row["order_status_id"]);
    }

    /**
     * Create tables for payment data, cards and refunds
     *
     * @return void
     */
    private function createDatabaseTables()
    {
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . self::TABLE_PAYMENTS . "` (
                `id` INT NOT NULL AUTO_INCREMENT,
				`order_id` BIGINT NOT NULL,
				`ipay_id` VARCHAR(255) NOT NULL,
				`amount` DECIMAL(15,2) NOT NULL,
				`status` VARCHAR(255) NOT NULL,
				`loy_id` VARCHAR(255) DEFAULT NULL,
				`loy_amount` DECIMAL(15,2) NOT NULL,
				`loy_status` VARCHAR(255) NOT NULL,
				`data` TEXT DEFAULT NULL,
				`created_at` TIMESTAMP NOT NULL,
				PRIMARY KEY `id` (`id`),
				INDEX `order_id` (`order_id`),
				INDEX `loy_id` (`loy_id`),
				UNIQUE KEY `ipay_id` (`ipay_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );


        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . self::TABLE_CARDS . "` (
                `id` INT NOT NULL AUTO_INCREMENT,
				`customer_id` BIGINT NOT NULL,
				`ipay_id` VARCHAR(255) NOT NULL,
				`expiration` VARCHAR(255) NOT NULL,
				`cardholderName` VARCHAR(255) NOT NULL,
				`pan` VARCHAR(255) NOT NULL,
				`status` VARCHAR(255) NOT NULL,
				`created_at` TIMESTAMP NOT NULL,
				PRIMARY KEY (`id`),
				INDEX (`customer_id`),
				INDEX (`customer_id`, `pan`),
				UNIQUE KEY (`ipay_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );


        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . self::TABLE_REFUNDS . "` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `order_id` BIGINT NOT NULL,
            `ipay_id` VARCHAR(255) NOT NULL,
            `amount` DECIMAL(15,2) NOT NULL,
            PRIMARY KEY (`id`),
            INDEX (`order_id`)
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
        );
    }

    /**
     * Add bt ipay order admin tab
     *
     * @return void
     */
    private function addEvents()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->addEvent(
            [
                'code' => self::ACCOUNT_COF_LISTENER_NAME,
                'description' => '',
                'trigger' => 'catalog/controller/extension/opencart/module/account/after',
                'action' => 'extension/ipay_opencart/payment/bt_ipay.render_cof',
                'status' => 1,
                'sort_order' => 1
            ]
        );
        $this->model_setting_event->addEvent(
            [
                'code' => self::FRONTEND_FLASH,
                'description' => '',
                'trigger' => 'catalog/view/common/success/before',
                'action' => 'extension/ipay_opencart/payment/bt_ipay.render_flash',
                'status' => 1,
                'sort_order' => 1
            ]
        );
    }

    /**
     * Remove bt ipay order admin tab
     *
     * @return void
     */
    private function removeEvents()
    {
        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(self::ACCOUNT_COF_LISTENER_NAME);
        $this->model_setting_event->deleteEventByCode(self::FRONTEND_FLASH);
    }

    public function getPayments(int $orderId): array
    {
        $qry = $this->db->query(
            "SELECT `ipay_id`, `amount`, `status`, `loy_amount`, `loy_id`, `loy_status` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC"
        );

        if ($qry->num_rows > 0) {
            return $qry->rows;
        }
        return [];
    }

    public function getPaymentByOrderId(int $orderId): ?array
    {
        $qry = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC LIMIT 1"
        );

        if ($qry->num_rows === 0) {
            return null;
        }
        return $qry->row;
    }


    public function getAuthorizedAmount(int $orderId): float
    {
        $qry = $this->db->query(
            "SELECT `amount`, `loy_amount`, `loy_status`, `status` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC LIMIT 1"
        );

        if ($qry->num_rows === 0) {
            return 0.0;
        }
        $total = 0.0;

        if ($qry->row["status"] === StatusService::STATUS_APPROVED) {
            $total += floatval($qry->row["amount"]);
        }

        $loyAmount = floatval($qry->row["loy_amount"]);
        if ($loyAmount > 0 && $qry->row["loy_status"] === StatusService::STATUS_APPROVED) {
            $total += $loyAmount;
        }

        return $total;
    }

    public function getCapturedAmount(int $orderId): float
    {
        $qry = $this->db->query(
            "SELECT `amount`, `loy_amount`, `loy_status`, `status` FROM `" . DB_PREFIX . "bt_ipay_payments` WHERE `order_id` = '" . $orderId . "' ORDER BY `created_at` DESC LIMIT 1"
        );

        if ($qry->num_rows === 0) {
            return 0.0;
        }
        $total = 0.0;

        if (in_array($qry->row["status"], [StatusService::STATUS_DEPOSITED, StatusService::STATUS_PARTIALLY_REFUNDED, StatusService::STATUS_REFUNDED])) {
            $total += floatval($qry->row["amount"]);
        }

        $loyAmount = floatval($qry->row["loy_amount"]);
        if ($loyAmount > 0 && in_array($qry->row["loy_status"], [StatusService::STATUS_DEPOSITED, StatusService::STATUS_PARTIALLY_REFUNDED, StatusService::STATUS_REFUNDED])) {
            $total += $loyAmount;
        }

        return $total;
    }


    public function getRefundedAmount(int $orderId): float
    {
        $qry = $this->db->query(
            "SELECT SUM(`amount`) as `refunded` FROM `" . DB_PREFIX . "bt_ipay_refunds` WHERE `order_id` = '" . $orderId . "'"
        );

        if ($qry->num_rows === 0 || !isset($qry->row["refunded"])) {
            return 0.0;
        }

        return floatval($qry->row["refunded"]);
    }

    public function getRefunds(int $orderId): array
    {
        $qry = $this->db->query(
            "SELECT * FROM `" . DB_PREFIX . "bt_ipay_refunds` WHERE `order_id` = '" . $orderId . "'"
        );

        if ($qry->num_rows > 0) {
            return $qry->rows;
        }
        return [];
    }

    public function addRefunds(int $orderId, array $refunds)
    {

        $newRefunds = [];

        $keys = [];
        foreach ($refunds as $refund) {
            $values = array_merge(
                $refund,
                ["order_id" => $orderId]
            );
            $newRefunds[] = "(".$this->formatValuesForInsert($values).")";

            if (count($keys) === 0 ) {
                $keys = array_keys($values);
            }
        }

        $keys = implode(
			",",
			array_map(
				function ($key) {
					return "`" . $key . "`";
				},
				$keys
			)
		);

        if (count($newRefunds) === 0)
        {
            return;
        }

        $this->db->query("DELETE FROM `" . DB_PREFIX . self::TABLE_REFUNDS."` WHERE `order_id` = '" . $orderId . "'");
		
        $dataString = implode("," , $newRefunds);
		$this->db->query("INSERT INTO `" . DB_PREFIX . self::TABLE_REFUNDS."` ( " . $keys . ") VALUES ".$dataString);

    }

    private function formatValuesForInsert(array $data)
    {
        return implode(
			",",
			array_map(function ($field) {
				return "'" . $this->db->escape($field) . "'";
			}, $data)
		);
    }

    public function updatePaymentStatus(string $ipayId, string $status)
    {
        $this->updatePayment($ipayId, ["status" => $status]);
    }

    public function updateLoyStatus(string $ipayId, string $status)
    {
        $this->updatePayment($ipayId, ["loy_status" => $status]);
    }

    public function updatePaymentStatusAndAmount(string $ipayId, string $status, float $amount)
    {
        $this->updatePayment($ipayId, ["status" => $status, "amount" => $amount]);
    }

    public function updateLoyStatusAndAmount(string $ipayId, string $status, float $amount)
    {
        $this->updatePayment($ipayId, ["loy_status" => $status, "loy_amount" => $amount]);
    }

    public function updatePayment(string $ipayId, array $data)
    {
        $this->db->query("UPDATE `" . DB_PREFIX . "bt_ipay_payments` SET " . $this->formatUpdateValues($data) . " WHERE `ipay_id` = '" . $this->db->escape($ipayId) . "'");
    }

    /**
     * Get order from database
     *
     * @param integer $orderId
     *
     * @return void
     */
    public function getOrder(int $orderId)
    {
        $this->load->model('sale/order');
        return $this->model_sale_order->getOrder($orderId);
    }

    /**
     * Format float to currency
     *
     * @param float $amount
     *
     * @return void
     */
    public function formatCurrency(float $amount)
    {
        return $this->currency->format($amount, $this->config->get('config_currency'), 1);
    }

    /**
     * Add order history
     *
     * @param int $order_id
     * @param int $order_status_id
     * @param string $comment
     *
     * @return string
     */
    public function addOrderHistory($order_id, $order_status_id, $comment = '')
    {
        $json = array();

        $data = array(
            'order_id' => $order_id,
            'order_status_id' => $order_status_id,
            'notify' => 0,
            'comment' => $comment
        );

        $store_id = $this->config->get('config_store_id');

        $this->load->model('setting/store');

        $store_info = $this->model_setting_store->getStore((int)$store_id);

        if ($store_info) {
            $url = $store_info['url'];
        } else {
            $url = HTTP_CATALOG;
        }

        $session = $this->apiSession();
        $curl = curl_init();

        // Set SSL if required
        if (substr($url, 0, 5) == 'https') {
            curl_setopt($curl, CURLOPT_PORT, 443);
        }
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_USERAGENT, $this->request->server['HTTP_USER_AGENT']);
        curl_setopt($curl, CURLOPT_FORBID_REUSE, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_URL, $url . 'index.php?route=api/sale/order.addHistory');
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($curl, CURLOPT_COOKIE, $this->config->get('session_name') . '=' . $session->getId() . ';');

        $json = curl_exec($curl);

        curl_close($curl);
        return $json;
    }
    
    /**
     * Create a session with api credentials for the add order history
     * call
     *
     * @return  \Opencart\System\Library\Session|null
     */
	private function apiSession()
	{
        $this->load->model('user/api');
		$api_info = $this->model_user_api->getApi($this->config->get('config_api_id'));
        if ($api_info && $this->user->hasPermission('modify', 'sale/order')) {
            $session = new \Opencart\System\Library\Session($this->config->get('session_engine'), $this->registry);
            
            $session->start();
                    
            $this->model_user_api->deleteSessionBySessionId($session->getId());
            
            $this->model_user_api->addSession($api_info['api_id'], $session->getId(), $this->request->server['REMOTE_ADDR']);
            
            $session->data['api_id'] = $api_info['api_id'];
            $session->close();
			return $session;
        }
	}

    /**
     * Format array values for update
     *
     * @param array $values
     *
     * @return string
     */
    private function formatUpdateValues(array $values): string
    {
        $data = [];
        foreach ($values as $key => $value) {
            if (is_string($key)) {
                $data[] = "`" . $key . "` = '" . $this->db->escape($value) . "'";
            }
        }
        return implode(",", $data);
    }

    public function updateOrderTotals(int $orderId, string $ipayId, float $paymentTotal, float $loyTotal)
	{
        $authorized = $this->getPaymentByOrderId($orderId);
        $notCaptured = $authorized['amount'] + $authorized['loy_amount'] - $paymentTotal - $loyTotal;

		$totals = [
			[
				'extension' => 'ipay_opencart',
                'code' => 'bt_ipay_captured_amount',
                'title' => 'BT iPay Total Captured(currency)',
                'value' => $paymentTotal,
                'sort_order' => 11
			]
		];

        if ($authorized['amount'] - $paymentTotal  > 0.005) {
            $totals[] = [
                'extension' => 'ipay_opencart',
                'code' => 'bt_ipay_not_captured_amount',
                'title' => '<span style="color:red">BT iPay Total Not Captured(currency)</span>',
                'value' => $authorized['amount'] - $paymentTotal,
               'sort_order' => 11
            ];
        }

		if ($loyTotal > 0) {
			$totals[] = [
                'extension' => 'ipay_opencart',
                'code' => 'bt_ipay_captured_loyalty',
                'title' => 'BT iPay Total Captured(loyalty points)',
                'value' => $loyTotal,
                'sort_order' => 12
            ];
            if ($authorized['loy_amount'] - $loyTotal  > 0.005) {
                $totals[] = [
                    'extension' => 'ipay_opencart',
                    'code' => 'bt_ipay_not_captured_loyality',
                    'title' => '<span style="color:red">BT iPay Total Not Captured(loyalty points)</span>',
                    'value' => $authorized['loy_amount'] - $loyTotal,
                   'sort_order' => 12
                ];
            }
		}

        if ($notCaptured > 0.005) {
            $totals[] = [
                'extension' => 'ipay_opencart',
                'code' => 'bt_ipay_total_not_captured',
                'title' => '<span style="color:red">BT iPay Not Captured(Unpaid amount)</span>',
                'value' => $notCaptured,
               'sort_order' => 13
            ];
            if ($loyTotal > 0) {
                $totals[] = [
                    'extension' => 'ipay_opencart',
                    'code' => 'bt_ipay_total_paid',
                    'title' => 'BT iPay Total (Captured)',
                    'value' => $paymentTotal + $loyTotal,
                'sort_order' => 14
                ];
            }
        }

		foreach ($totals as $total) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "order_total` SET `order_id` = '" . (int)$orderId . "', `extension` = '" . $this->db->escape($total['extension']) . "', `code` = '" . $this->db->escape($total['code']) . "', `title` = '" . $this->db->escape($total['title']) . "', `value` = '" . (float)$total['value'] . "', `sort_order` = '" . (int)$total['sort_order'] . "'");
		}
	}

    public function updateOrderRefundTotals(int $orderId)
	{
        $totalRefunded = $this->getRefundedAmount($orderId);
        $totalCaptured = $this->getCapturedAmount($orderId);

		$this->db->query("DELETE FROM `" . DB_PREFIX . "order_total` WHERE `extension` = 'ipay_opencart' AND `code` IN ('bt_ipay_total_refunded','bt_ipay_total_available')");

		$totals = [
			[
				'extension' => 'ipay_opencart',
                'code' => 'bt_ipay_total_refunded',
                'title' => 'BT iPay Total Refunded',
                'value' => $totalRefunded,
                'sort_order' => 15
            ],
            [
				'extension' => 'ipay_opencart',
                'code' => 'bt_ipay_total_available',
                'title' => 'BT iPay Total Available to be refunded',
                'value' => number_format($totalCaptured - $totalRefunded,2),
                'sort_order' => 16
			]
		];

		foreach ($totals as $total) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "order_total` SET `order_id` = '" . (int)$orderId . "', `extension` = '" . $this->db->escape($total['extension']) . "', `code` = '" . $this->db->escape($total['code']) . "', `title` = '" . $this->db->escape($total['title']) . "', `value` = '" . (float)$total['value'] . "', `sort_order` = '" . (int)$total['sort_order'] . "'");
		}
	}
}