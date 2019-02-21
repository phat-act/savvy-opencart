<?php

class ModelExtensionPaymentSavvy extends Model
{
    public static $rates;

    public static $currencies = null;

    public static $baseUrl = 'https://api.savvy.io/v3';

    public static $testUrl = 'https://api.test.savvy.io/v3';

    public function getMethod($address, $total)
    {
        $methodData = array(
            'code'       => 'savvy',
            'title'      => $this->config->get('payment_savvy_title'),
            'terms'      => '',
            'sort_order' => $this->config->get('payment_savvy_sort_order')
        );

        return $methodData;
    }

    public function getCurrency($token, $orderId, $getAddress = false)
    {
        $token = $this->sanitizaToken($token);
        $rate = $this->getRate($token);

        if ($rate) {
            $this->load->model('checkout/order');
            $orderInfo = $this->model_checkout_order->getOrder($orderId);
            $fiatValue = $orderInfo['total'];
            $coinsValue = round($fiatValue / $rate, 8);

            $currencies = $this->getCurrencies();
            $currency = (object) $currencies[strtolower($token)];
            $currency->coinsValue = $coinsValue;
            $currency->rate = round($this->getRate($currency->code), 2);

            if ($getAddress) {
                $currency->address = $this->getAddress($orderId, $token);
            } else {
                $currency->currencyUrl = html_entity_decode($this->url->link('extension/payment/savvy/currencies', ['token' => $token, 'order' => $orderId]));
            }

            return $currency;

        }

        $this->log('can\'t get rate for ' . $token);

        return null;
    }

    public function getCurrencies()
    {
        if (self::$currencies === null) {
            $url = sprintf('%s/currencies?token=%s', self::$baseUrl, $this->config->get('payment_savvy_api_secret'));
            if ($this->config->get('payment_savvy_testnet')) {
                $url = sprintf('%s/currencies?token=%s', self::$testUrl, $this->config->get('payment_savvy_api_testnet'));
            }
            $response = file_get_contents($url);
            $data = json_decode($response, true);

            self::$currencies = $data['data'];
        }

        return self::$currencies;
    }

    public function getRate($curCode)
    {
        $rates = $this->getRates();
        $curCode = strtolower($curCode);

        return isset($rates->$curCode) ? $rates->$curCode->mid : false;
    }

    public function getRates()
    {
        if (empty(self::$rates)) {
            $needUpdate = false;
            $currency = $this->session->data['currency'];
            if (!$currency) {
                $currency = 'usd';
            }

            $ratesKey = sprintf('payment_savvy_%s_rates', strtolower($currency));
            $ratesTimestampKey = sprintf('%s_timestamp', $ratesKey);
            $ratesString = $this->config->get($ratesKey);
            $ratesTimestamp = (int) $this->config->get($ratesTimestampKey);

            if ($ratesString && $ratesTimestamp) {
                $ratesDeadline = $ratesTimestamp + $this->config->get('payment_savvy_exchange_rate_locktime') * 60;
                if ($ratesDeadline < time()) {
                    $needUpdate = true;
                }
            }

            if (!$needUpdate && !empty($ratesString)) {
                self::$rates = json_decode($ratesString);
            } else {
                $url = sprintf("%s/exchange/%s/rate", self::$baseUrl, strtolower($currency));

                if ($this->config->get('payment_savvy_testnet')) {
                    $url = sprintf("%s/exchange/%s/rate", self::$testUrl, strtolower($currency));
                }

                if ($response = file_get_contents($url)) {
                    $response = json_decode($response);
                    if ($response->success) {
                        $ratesData = [];
                        $ratesData[$ratesKey] = json_encode($response->data);
                        $ratesData[$ratesTimestampKey] = time();
                        $this->editSettings($ratesData);
                        self::$rates = $response->data;
                    }
                }
            }
        }

        return self::$rates;
    }

    public function getAddress($orderId, $token = 'ETH')
    {
        $token = $this->sanitizaToken($token);
        $this->load->model('checkout/order');
        // $order =
        $data = $this->findByOrderId($orderId);
        // /** @var Order $order */
        $order = $this->model_checkout_order->getOrder($orderId);

        $rate = $this->getRate($token);

        if ($data && $this->sanitizaToken($data['token']) === $token) {
            return $data['address'];
        }

        if (!$data) {
            $data = [
                'order_id' => (int) $orderId,
                'token' => strtolower($token)
            ];
        }

        $apiSecret = $this->config->get('payment_savvy_api_secret');
        $apiTestnet = $this->config->get('payment_savvy_api_testnet');
        $lock_address_timeout = $this->config->get('lock_address_timeout');
        $lock_address_timeout = (empty($lock_address_timeout)) ? 86400 : $lock_address_timeout;

        $callbackUrl = $this->url->link('extension/payment/savvy/callback', ['order' => $orderId], false);
        $callbackUrl = str_replace('&amp;', '&', $callbackUrl);

        $url = sprintf('%s/%s/payment/%s?token=%s&lock_address_timeout=%s', self::$baseUrl, strtolower($token), urlencode($callbackUrl), $apiSecret, $lock_address_timeout);
        if ($this->config->get('payment_savvy_testnet')) {
            $url = sprintf('%s/%s/payment/%s?token=%s&lock_address_timeout=%s', self::$testUrl, strtolower($token), urlencode($callbackUrl), $apiTestnet, $lock_address_timeout);
        }
        if ($response = file_get_contents($url)) {
            $response = json_decode($response);
            $currencies = $this->getCurrencies();

            if (isset($response->data->address)) {
                $fiatAmount = $order['total'];
                $coinsAmount = round($fiatAmount / $rate, 8);

                $data['confirmations'] = null;
                $data['token'] = strtolower($token);
                $data['address'] = $response->data->address;
                $data['invoice'] = $response->data->invoice;
                $data['amount'] = $coinsAmount;
                $data['max_confirmations'] = $currencies[strtolower($token)]['maxConfirmations'];
                if (isset($data['savvy_id'])) {
                    $this->updateData($data);
                } else {
                    $this->addData($data);
                }

                return $response->data->address;
            }
        }

        return null;
    }

    public function addData($data)
    {
        $now = date('Y-m-d H:i:s');
        if (!isset($data['date_added'])) {
            $data['date_added'] = $now;
        }

        if (!isset($data['date_modified'])) {
            $data['date_modified'] = $now;
        }

        $valuesStrings = [];
        foreach ($data as $field => $value) {
            if (empty($value) && ($value !== 0 || $value !== '0')) {
                continue;
            }

            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("INSERT INTO " . DB_PREFIX . "savvy SET " . implode(', ', $valuesStrings));
    }

    public function updateData($data)
    {
        if (!isset($data['date_modified'])) {
            $data['date_modified'] = date('Y-m-d H:i:s');
        }

        $rowId = $data['savvy_id'];
        unset($data['savvy_id']);

        $valuesStrings = [];
        foreach ($data as $field => $value) {
            $valuesStrings[] = sprintf('%s = "%s"', $field, $value);
        }

        $this->db->query("UPDATE " . DB_PREFIX . "savvy SET " . implode(', ', $valuesStrings) . ' WHERE savvy_id = ' . $rowId);
    }

    public function findByOrderId($orderId)
    {
        $query = $this->db->query("SELECT * FROM " . DB_PREFIX . "savvy WHERE order_id = " . (int) $orderId);

        return $query->row;
    }

    public function log($message) {
        if ($this->config->get('payment_savvy_debug') == 1) {
            $log = new Log('savvy.log');
            // $backtrace = debug_backtrace();
            // $log->write('Origin: ' . $backtrace[6]['class'] . '::' . $backtrace[6]['function']);
            $log->write(print_r($message, 1));
        }
    }

    /**
     * @param string $token
     *
     * @return string
     */
    public function sanitizaToken($token)
    {
        $token = strtolower($token);
        $token = preg_replace('/[^a-z0-9:]/', '', $token);

        return $token;
    }

    public function getPayments($orderId, $excludeHash = null)
    {
        $sql = "SELECT * FROM " . DB_PREFIX . "savvy_transaction WHERE order_id = " . (int) $orderId;
        if ($excludeHash) {
            $sql .= ' AND transaction_hash != "' . $excludeHash . '"';
        }
        $query = $this->db->query($sql);
        $result = $query->rows;
        $data = [];
        foreach ($result as $row) {
            $data[$row['transaction_hash']] = $row;
        }

        return $data;
    }

    public function editSettings($data, $store_id = 0) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "setting` WHERE store_id = '" . (int)$store_id . "' AND `code` = 'payment_savvy' AND `key` IN ('" . implode("','", array_keys($data)) . "') ");
        $exists = [];
        foreach ($query->rows as $row) {
            $exists[$row['key']] = $row;
        }

        foreach ($data as $key => $value) {
            if (isset($exists[$key])) {
                $this->db->query("UPDATE " . DB_PREFIX . "setting SET `value` = '" . $this->db->escape($value) . "', serialized = '0'  WHERE `code` = 'payment_savvy' AND `key` = '" . $this->db->escape($key) . "' AND store_id = '" . (int)$store_id . "'");
            } else {
                $this->db->query("INSERT INTO " . DB_PREFIX . "setting SET store_id = '" . (int)$store_id . "', `code` = 'payment_savvy', `key` = '" . $this->db->escape($key) . "', `value` = '" . $this->db->escape($value) . "'");
            }
        }
    }

}
