<?php

class ControllerExtensionPaymentSavvy extends Controller
{

    public function index()
    {
        $this->load->language('extension/payment/savvy');
        $this->load->model('checkout/order');

        $order = $this->model_checkout_order->getOrder($this->session->data['order_id']);
        $this->model_checkout_order->addOrderHistory($order['order_id'], $this->config->get('payment_savvy_pending_status_id'), null, false);

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['currenciesUrl'] = $this->url->link('extension/payment/savvy/currencies', array(
            'order' => $this->session->data['order_id']
        ), true);
        $data['statusUrl'] = $this->url->link('extension/payment/savvy/status', [
            'order' => $this->session->data['order_id']
        ], true);
        $data['callbackUrl'] = $this->url->link('extension/payment/savvy/callback', '', true);
        $data['redirectUrl'] = $this->url->link('checkout/success', '', true);
        $data['fiatValue'] = $order['total'];
        $data['currencyIso'] = $order['currency_code'];
        $data['currencySign'] = $this->currency->getSymbolLeft($this->session->data['currency']) ? $this->currency->getSymbolLeft($this->session->data['currency']) : $this->currency->getSymbolRight($this->session->data['currency']);
        $data['maxUnderpaymentFiat'] = $this->config->get('payment_savvy_max_underpayment');
        $data['minOverpaymentFiat'] = $this->config->get('payment_savvy_min_overpayment');
        $data['baseUrl'] = $this->config->get('config_url');
        $data['exchangeRateLocktime'] = (int) $this->config->get('payment_savvy_exchange_rate_locktime') * 60;

        return $this->load->view('extension/payment/savvy', $data);
    }

    public function currencies()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/savvy');
        $orderId = $this->request->get['order'];
        $orderInfo = $this->model_checkout_order->getOrder($orderId);

        if (isset($this->request->get['token'])) {
            $token = $this->request->get['token'];
            $data = $this->model_extension_payment_savvy->getCurrency($token, $orderId, true);
        } else {
            $data = [];
            $currencies = $this->model_extension_payment_savvy->getCurrencies();
            $getAddress = false;
            if (count($currencies) === 1) {
                $getAddress = true;
            }

            $savvyData = $this->model_extension_payment_savvy->findByOrderId($orderId);
            $currentCurrencyToken = null;
            if ($savvyData) {
                $allsavvyPayments = $this->model_extension_payment_savvy->getPayments($orderId);
                if (!empty($allsavvyPayments)) {
                    $firstPayment = current($allsavvyPayments);
                    $currentCurrencyToken = $firstPayment['blockchain'];
                }
            }

            // tmp solution
            if ($currentCurrencyToken) {
                $currency = $this->model_extension_payment_savvy->getCurrency($currentCurrencyToken, $orderId, true);
                $currencies = array();
                $currencies[$currentCurrencyToken] = $currency;
            }

            foreach ($currencies as $token => $currency) {
                $currency = $this->model_extension_payment_savvy->getCurrency($token, $orderId, $getAddress);
                if ($currency) {
                    $data[] = $currency;
                }
            }
        }

        echo json_encode($data);
    }

    public function callback()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/savvy');
        $this->load->model('extension/payment/savvy_transaction');

        if (empty($this->request->get['order']) || !$this->request->get['order']) {
            die('no order id');
        }

        $order = $this->model_checkout_order->getOrder($this->request->get['order']);
        $data = file_get_contents('php://input');

        if (empty($data) || empty($order)
            || (int) $this->config->get('payment_savvy_completed_status_id') === (int) $order['order_status_id']) {
            die();
        }

        $comment = '';

        $params = json_decode($data);
        $invoice = $params->invoice;
        $savvyData = $this->model_extension_payment_savvy->findByOrderId($order['order_id']);
        if (!$savvyData || $invoice !== $savvyData['invoice']) {
            die();
        }

        $currencies = $this->model_extension_payment_savvy->getCurrencies();
        $blockchain = $params->blockchain;
        $blockchainCode = strtoupper($blockchain);
        if (isset($currencies[$params->blockchain])) {
            $blockchainCode = $currencies[$params->blockchain]['code'];
        }
        /** @var array $allsavvyPayments */
        $allsavvyPayments = $this->model_extension_payment_savvy->getPayments($order['order_id'], $params->inTransaction->hash);
        $rate = $this->model_extension_payment_savvy->getRate($blockchain);

        $maxConfirmations = $params->maxConfirmations;
        if (!$maxConfirmations) {
            $maxConfirmations = $savvyData['max_confirmations'];
        }

        $maxUnderpaymentFiat = $this->config->get('payment_savvy_max_underpayment');
        $maxUnderpaymentCrypto = $maxUnderpaymentFiat / $rate;
        $maxDifference = max($maxUnderpaymentCrypto, 0.00000001);
        $toPay = (float) $savvyData['amount'];
        $alreadyPaid = 0;
        foreach ($allsavvyPayments as $payment) {
            $alreadyPaid += $payment['amount'];
        }
        $paidNow = $params->inTransaction->amount / (10 ** $params->inTransaction->exp);
        $totalPaid = (float) $paidNow + $alreadyPaid;

        $savvyPayment = $this->model_extension_payment_savvy_transaction->findByHash($params->inTransaction->hash);
        if (!$savvyPayment) {
            $savvyPayment = [
                'invoice' => $params->invoice,
                'max_confirmations' => $params->maxConfirmations,
                'order_id' => $order['order_id'],
                'blockchain' => $blockchain,
                'amount' => sprintf('%.8F', $paidNow),
                'currency' => $order['currency_code'],
                'address' => $savvyData['address'],
                'transaction_hash' => $params->inTransaction->hash,
                'date_added' => time()
            ];
        }

        if (isset($allsavvyPayments[$savvyPayment['transaction_hash']])) {
            $transactionIndex = array_search($savvyPayment['transaction_hash'], array_keys($allsavvyPayments), true);
            if ($transactionIndex > 0) { //avoid race conditions
                usleep($transactionIndex * 500);
            }
        }

        $savvyPayment['confirmations'] = $params->confirmations;

        if (!isset($savvyPayment['savvy_transaction_id'])) {
            $this->model_extension_payment_savvy_transaction->insert($savvyPayment);
        } else {
            $this->model_extension_payment_savvy_transaction->update($savvyPayment['savvy_transaction_id'], $savvyPayment);
        }

        if ($toPay - $totalPaid > $maxDifference) {
            $underpaid = $toPay - $totalPaid;
            $underpaidFiat = $underpaid * $rate;
            if (!isset($savvyPayment['savvy_transaction_id'])) {
                $comment = sprintf("Looks like you underpaid %.8F %s (%.2F %s)\n\nDon't worry, here is what to do next:\n\nContact the merchant directly and...\n-Request details on how you can pay the difference..\n-Request a refund and create a new order.\n\nTips for Paying with Crypto:\n\nTip 1) When paying, ensure you send the correct amount in %s. Do not manually enter the %s Value.\n\nTip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.\n\nTip 3) Be sure to successfully send your payment before the countdown timer expires.\nThis timer is setup to lock in a fixed rate for your payment. Once it expires, the rate changes.", $underpaid, strtoupper($blockchainCode), $underpaidFiat, $order['currency_code'], strtoupper($blockchainCode), $order['currency_code']);
            }
            if ((int) $order['order_status_id'] !== (int) $this->config->get('payment_savvy_mispaid_status_id')) {
                $this->model_extension_payment_savvy->log(sprintf('Savvy: mispaid order: %s', $order['order_id']));
                $this->model_checkout_order->addOrderHistory($order['order_id'], $this->config->get('payment_savvy_mispaid_status_id'), $comment, true);
            }
        }

        if ($params->confirmations >= $maxConfirmations && $maxConfirmations > 0) {
            if ($toPay > 0 && ($toPay - $totalPaid) < $maxDifference) {
                $orderTimestamp = strtotime($order['date_added']);
                $paymentTimestamp = strtotime($savvyPayment['date_added']);
                $deadline = $orderTimestamp + (int) $this->config->get('payment_savvy_exchange_rate_locktime') * 60;
                $orderStatus = $this->config->get('payment_savvy_completed_status_id');
                $notify = true;

                if ($paymentTimestamp > $deadline) {
                    $this->model_extension_payment_savvy->log('Savvy: late payment');

                    $fiatPaid = $totalPaid * $rate;
                    if ($order['total'] > $fiatPaid) {
                        $orderStatus = $this->config->get('payment_savvy_late_payment_status_id');
                        $this->model_extension_payment_savvy->log('Savvy: rate changed');
                        $underpaid = $toPay - $totalPaid;
                        $underpaidFiat = $underpaid * $rate;
                        $comment = sprintf('Looks like you underpaid %.8F %s (%.2F %s)\nThis was due to the payment being sent after the Countdown Timer Expired.\n\nDon\'t worry, here is what to do next:\n\nContact the merchant directly and...\n-Request details on how you can pay the difference..\n-Request a refund and create a new order.\n\nTips for Paying with Crypto:\n\nTip 1) When paying, ensure you send the correct amount in %s. Do not manually enter the %s Value.\n\nTip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.\n\nTip 3) Be sure to successfully send your payment before the countdown timer expires.\nThis timer is setup to lock in a fixed rate for your payment. Once it expires, the rate changes.', $underpaid, strtoupper($blockchainCode), $underpaidFiat, $order['currency_code'], strtoupper($blockchainCode), $order['currency_code']);
                        $notify = true;
                    } else {
                        $this->model_extension_payment_savvy->log('Savvy: payment complete');
                    }
                }

                $overpaid = $totalPaid - $toPay;
                $overpaidFiat = round(($totalPaid - $toPay) * $rate, 2);
                $minOverpaymentFiat = $this->config->get('payment_savvy_min_overpayment');
                if ($overpaidFiat > $minOverpaymentFiat) {
                    $comment = sprintf("Whoops, you overpaid: %.8F %s(%.2F %s)\n\nDon’t worry, here is what to do next:\nTo get your overpayment refunded, please contact the merchant directly and share your Order ID %s and %s Address to send your refund to.\n\nTips for Paying with Crypto:\n\nTip 1) When paying, ensure you send the correct amount in %s. Do not manually enter the %s Value.\n\nTip 2)  If you are sending from an exchange, be sure to correctly factor in their withdrawal fees.\n\nTip 3) Be sure to successfully send your payment before the countdown timer expires.\nThis timer is setup to lock in a fixed rate for your payment. Once it expires, the rate changes.", $overpaid, strtoupper($blockchainCode),$overpaidFiat, $order['currency_code'], $order['order_id'], strtoupper($blockchainCode), strtoupper($blockchainCode), strtoupper($order['currency_code']));
                    $notify = true;
                }

                if ((int) $order['order_status_id'] !== (int) $orderStatus) {
                    $this->model_checkout_order->addOrderHistory($order['order_id'], $orderStatus, $comment, $notify);
                }
            }

            echo $params->invoice;
            die();
        }
        die();
    }

    public function status()
    {
        $this->load->model('checkout/order');
        $this->load->model('extension/payment/savvy');

        $order = $this->model_checkout_order->getOrder($this->request->get['order']);
        $savvyData = $this->model_extension_payment_savvy->findByOrderId($order['order_id']);

        if (!$savvyData) {
            die();
        }

        $allPayments = $this->model_extension_payment_savvy->getPayments($order['order_id']);
        $toPay = $savvyData['amount'];
        $success = false;
        $unpaidConfirmations = array();
        $rate = $this->model_extension_payment_savvy->getRate($savvyData['token']);

        $maxUnderpaymentFiat = (float)$this->config->get('payment_savvy_max_underpayment');
        $maxUnderpaymentCrypto = $maxUnderpaymentFiat / $rate;
        $maxDifference = max($maxUnderpaymentCrypto, 0.00000001);
        // $maxConfirmations = $savvyData['max_confirmations'];

        $data = array();
        $coinsPaid = 0;
        foreach ($allPayments as $payment) {
            $success = false;
            $coinsPaid += $payment['amount'];
            $confirmations = $payment['confirmations'];
            $maxConfirmations = $payment['max_confirmations'];
            if (!$maxConfirmations) {
                $maxConfirmations = $savvyData['max_confirmations'];
            }
            if ($confirmations >= $maxConfirmations) {
                $success = true;
            }
            $unpaidConfirmations[] = $confirmations;
        }
        $data['coinsPaid'] = $coinsPaid;
        $data['success'] = $success && ($toPay > 0 && ($toPay - $coinsPaid) < $maxDifference);
        $data['confirmations'] = null;
        if (!empty($unpaidConfirmations)) {
            $data['confirmations'] = min($unpaidConfirmations);
        }

        echo json_encode($data);
    }

    public function confirm()
    {
        $url = $this->url->link('extension/payment/savvy/pay', '', true);
        $this->response->redirect($url);
    }
}
