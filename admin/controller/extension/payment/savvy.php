<?php

class ControllerExtensionPaymentSavvy extends Controller
{
    private $error = [];

    public function index()
    {
        $this->load->model('setting/setting');
        $this->load->model('extension/payment/savvy');
        $this->load->language('extension/payment/savvy');

        $installedVersion = $this->config->get('payment_savvy_version');
        if (!$installedVersion) {
            $installedVersion = '0.2.0';
        }
        $currentVersion = $this->model_extension_payment_savvy->getVersion();

        if (version_compare($currentVersion, $installedVersion) > 0) {
            $this->model_extension_payment_savvy->upgrade($installedVersion);
            $this->model_setting_setting->editSettingValue('payment_savvy', 'payment_savvy_version', $currentVersion);
        }

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('payment_savvy', $this->request->post);

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true));
        }

        $data['log_filename'] = 'savvy.log';
        $data['log_lines'] = $this->readLastLines(DIR_LOGS . $data['log_filename'], 500);
        $data['clear_log'] = str_replace('&amp;', '&', $this->url->link('extension/payment/savvy/clearlog', 'user_token=' . $this->session->data['user_token'], true));
        $data['default_currency'] = $this->config->get('config_currency');

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true)
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/savvy', 'user_token=' . $this->session->data['user_token'], true)
        ];


        $data['action'] = $this->url->link('extension/payment/savvy', 'user_token=' . $this->session->data['user_token'], true);

        $data['cancel'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=payment', true);

        $this->load->model('extension/payment/savvy');

        $fields = array(
            'payment_savvy_title',
            'payment_savvy_description',
            'payment_savvy_api_secret',
            'payment_savvy_api_public',
            'payment_savvy_api_testnet',
            'payment_savvy_testnet',
            'payment_savvy_exchange_rate_locktime',
            'payment_savvy_completed_status_id',
            'payment_savvy_awaiting_confirmations_status_id',
            // 'payment_savvy_failed_status_id',
            'payment_savvy_pending_status_id',
            'payment_savvy_mispaid_status_id',
            'payment_savvy_late_payment_status_id',
            'payment_savvy_status',
            'payment_savvy_debug',
            'payment_savvy_max_underpayment',
            'payment_savvy_min_overpayment',
            'payment_savvy_version'
        );

        foreach ($fields as $field) {
            if (isset($this->request->post[$field])) {
                $data[$field] = $this->request->post[$field];
            } else {
                $data[$field] = $this->config->get($field);
            }
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/payment/savvy', $data));
    }

    public function install()
    {
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/language');

        $langs = $this->model_localisation_language->getLanguages();

        $defaultParams = [
            'payment_savvy_version' => '0.4.3',
            'payment_savvy_status' => 1,
            'payment_savvy_title' => 'Pay with Crypto via Savvy (BTC/ETH/LTC and more)',
            'payment_savvy_exchange_rate_locktime' => 15,
            'payment_savvy_max_underpayment' => 0.01,
            'payment_savvy_min_overpayment' => 1,
        ];

        $orderStatuses = $this->model_localisation_order_status->getOrderStatuses();
        foreach ($orderStatuses as $status) {
            if ($status['name'] == 'Savvy: Payment Accepted') {
                $defaultParams['payment_savvy_completed_status_id'] = $status['order_status_id'];
            }
            if ($status['name'] == 'Savvy: Awaiting Confirmations') {
                $defaultParams['payment_savvy_awaiting_confirmations_status_id'] = $status['order_status_id'];
            }
            if ($status['name'] == 'Savvy: Mispaid') {
                $defaultParams['payment_savvy_mispaid_status_id'] = $status['order_status_id'];
            }
            if ($status['name'] == 'Savvy: Late Payment') {
                $defaultParams['payment_savvy_late_payment_status_id'] = $status['order_status_id'];
            }
        }


        foreach ($langs as $lang) {
            if (!isset($defaultParams['payment_savvy_completed_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'Savvy: Payment Accepted']
                    ]
                ]);

                $defaultParams['payment_savvy_completed_status_id'] = $this->db->getLastId();
            }

            if (!isset($defaultParams['payment_savvy_awaiting_confirmations_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'Savvy: Awaiting Confirmations']
                    ]
                ]);

                $defaultParams['payment_savvy_awaiting_confirmations_status_id'] = $this->db->getLastId();
            }

            if (!isset($defaultParams['payment_savvy_mispaid_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'Savvy: Mispaid']
                    ]
                ]);

                $defaultParams['payment_savvy_mispaid_status_id'] = $this->db->getLastId();
            }

            if (!isset($defaultParams['payment_savvy_late_payment_status_id'])) {
                $this->model_localisation_order_status->addOrderStatus([
                    'order_status' => [
                        $lang['language_id'] => ['name' => 'Savvy: Late Payment']
                    ]
                ]);

                $defaultParams['payment_savvy_late_payment_status_id'] = $this->db->getLastId();
            }

            break;
        }

        $this->load->model('setting/setting');
        $this->model_setting_setting->editSetting('payment_savvy', $defaultParams);

        $this->load->model('extension/payment/savvy');
        $this->model_extension_payment_savvy->install();
    }

    public function uninstall()
    {
        $this->load->model('localisation/order_status');
        $this->load->model('localisation/language');
        $this->load->model('setting/setting');

        $this->model_setting_setting->deleteSetting('payment_savvy');

        $this->db->query("DELETE FROM " . DB_PREFIX . "order_status WHERE name = 'Savvy: Payment Accepted' ");
    }

    private function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/savvy')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        return !$this->error;
    }

    public function clearLog()
    {
        $json = [];
        $this->load->language('extension/payment/savvy');
        if ($this->validatePermission()) {
            if (is_file(DIR_LOGS . 'savvy.log')) {
                @unlink(DIR_LOGS . 'savvy.log');
            }
            $json['success'] = $this->language->get('text_clear_log_success');
        } else {
            $json['error'] = $this->language->get('error_permission');
        }

        $this->response->addHeader('Content-Type: applicationbn/json');
        $this->response->setOutput(json_encode($json));
    }

    protected function validatePermission()
    {
        return $this->user->hasPermission('modify', 'extension/payment/savvy');
    }

    protected function readLastLines($filename, $lines)
    {
        if (!is_file($filename)) {
            return [];
        }
        $handle = @fopen($filename, "r");
        if (!$handle) {
            return [];
        }
        $linecounter = $lines;
        $pos = -1;
        $beginning = false;
        $text = [];

        while ($linecounter > 0) {
            $t = " ";

            while ($t != "\n") {
                /* if fseek() returns -1 we need to break the cycle*/
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true;
                    break;
                }
                $t = fgetc($handle);
                $pos--;
            }

            $linecounter--;

            if ($beginning) {
                rewind($handle);
            }

            $text[$lines - $linecounter - 1] = fgets($handle);

            if ($beginning) {
                break;
            }
        }
        fclose($handle);

        return array_reverse($text);
    }
}
