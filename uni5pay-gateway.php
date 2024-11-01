<?php
/*
 * Plugin Name: Uni5Pay+ Payment Gateway for Woocommerce
 * Plugin URI: https://uni5pay.sr/
 * Description: Accept Uni5Pay+ payments on your WooCommerce store.
 * Author: Data-Matic N.V.
 * Author URI: https://datamaticnv.com/
 * Version: 2.3.0
 * Requires Plugins: woocommerce
 * Requires at least: 6.2
 * Tested up to: 6.4.4
 * WC requires at least: 8.3.0
 * WC tested up to: 8.8.3
3
 */

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'uni5pay_add_gateway_class');
function uni5pay_add_gateway_class($gateways)
{
    $gateways[] = 'WC_Uni5Pay_Gateway'; // your class name is here
    return $gateways;
}


//Mark compatibility with checkout blocks
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'uni5pay_init_gateway_class');
function uni5pay_init_gateway_class()
{

    class WC_Uni5Pay_Gateway extends WC_Payment_Gateway
    {

        private $allowedCurrencies = array('SRD', 'EUR', 'USD');
        private $API_HOST = "https://payment.uni5pay.sr";
        private $API_SESSION_CREATE_ENDPOINT = "/v1/qrcode_online";
        private $API_SESSION_REFUND_ENDPOINT = "/v1/order_cancel";

        public function __construct()
        {
            $this->id = 'uni5pay'; // payment gateway plugin ID
            $this->icon = plugin_dir_url(__FILE__) . 'logo_uni5pay.png'; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'Uni5Pay+ Payment Gateway';
            $this->method_description = 'Betaling ontvangen in Woocommerce middels Uni5pay+ Payment Gateway'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            $this->supports = array(
                'products',
                'refunds',
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->title = 'Uni5Pay+';
            $this->description = $this->testmode ? 'Pay with Uni5Pay+ (TESTMODE)' : 'Pay with Uni5Pay+';
            $this->apiKey = $this->testmode ? $this->get_option('apiKey_test') : $this->get_option('apiKey');
            $this->header = array(
                'apiKey' => $this->apiKey,
            );

            // Site URL
            $this->siteUrl = get_site_url();

            // Register webhooks
            add_action('woocommerce_api_' . $this->id, array($this, 'webhook'));
            add_action('woocommerce_api_' . $this->id . '__success', array($this, 'webhook_success'));
            add_action('woocommerce_api_' . $this->id . '__failure', array($this, 'webhook_failure'));
            add_action('woocommerce_api_' . $this->id . '__notify', array($this, 'webhook_notify'));

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        }

        // Plugin options
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Uni5Pay+ Payment Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no',
                ),
                'testmode' => array(
                    'title' => 'Test mode',
                    'label' => 'Enable Test Mode',
                    'type' => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test credentials.',
                    'default' => 'yes',
                    'desc_tip' => true,
                ),
                'heading_test' => array(
                    'title' => 'Settings',
                    'type' => 'title',
                    'css' => 'border-bottom: solid 1px #ccc;text-transform: uppercase;padding-bottom: 5px;margin-bottom: 0;padding-top: 25px;max-width: 635px;font-weight: bold;',
                    'description' => '',
                ),
                'apiKey_test' => array(
                    'title' => 'apiKey (TEST)',
                    'type' => 'password',
                ),
                'apiKey' => array(
                    'title' => 'apiKey',
                    'type' => 'password',
                ),
            );
        }

        // Process payment
        public function process_payment($order_id)
        {
            // Check plugin settings
            if ($this->apiKey == null) {
                wc_add_notice('Invalid Uni5Pay+ Payment Gateway plugin settings.', 'error');
                return;
            }

            // Get order id
            $order = wc_get_order($order_id);

            // Get order amount
            $amount = $order->get_total();
            $amount = number_format($amount, 2);

            // Get woocommerce Currency
            switch (get_woocommerce_currency()) {
                case "USD":
                    $currency = "840";
                    break;
                case "SRD":
                    $currency = "968";
                    break;
                case "EUR":
                    $currency = "978";
                    break;
            }

            // Check currency
            if ($currency == null) {
                wc_add_notice('Uni5Pay+ does not support this currency. Please switch to SRD, USD or Euro.', 'error');
                return;
            }

            //To receive user id and order details
            $mchtOrderNo = $order->get_order_number();
            $orderIdString = '?orderId=' . $order_id;

            // Add meta
            add_post_meta($order_id, '_uni5pay_order_number', $mchtOrderNo);
            add_post_meta($order_id, '_uni5pay_order_id', $order_id);

            //Create a session and send it to Payment platform while handling errors
            $requestBody = array('mchtOrderNo' => $mchtOrderNo, 'amount' => $amount, 'currency' => $currency, 'terminalId' => 'WEB', 'payment_desc' => 'Your order: ' . $mchtOrderNo, 'url_success' => $this->siteUrl . '/wc-api/' . $this->id . '__success/' . $orderIdString, 'url_failure' => $this->siteUrl . '/wc-api/' . $this->id . '__failure/' . $orderIdString, 'url_notify' => $this->siteUrl . '/wc-api/' . $this->id . '__notify');

            //API post URL
            $apiUrl = $this->API_HOST . $this->API_SESSION_CREATE_ENDPOINT;

            //API response
            $response = wp_remote_post(
                $apiUrl,
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => $this->header,
                    'body' => $requestBody,
                )
            );

            $jsonResponse = json_decode($response['body'], true);

            if ($jsonResponse['rspCode'] == '00') {
                return array(
                    'result' => 'success',
                    'redirect' => $jsonResponse['paymentLink'],
                );
            } else {
                $order->add_order_note('Error: ' . $jsonResponse, true);
                wc_add_notice('Connection error. Please try again later', 'error');
                return;
            }
        }

        // Process refund
        public function process_refund($order_id, $amount = null, $reason = '')
        {
            // Get order id
            $order = wc_get_order($order_id);

            // Check amount
            if ($amount == 0 || $amount == null) {
                return new WP_Error('uni5pay_refund_error', __('Refund Error: You need to specify a refund amount.', 'wc-uni5pay-payment-gateway'));
            }

            $extOrderNo = get_post_meta($order_id, '_uni5pay_extOrderNo', true);

            if ($extOrderNo == null) {
                return new WP_Error('uni5pay_refund_error', __('Refund Error: No extOrderNo found for this order.', 'wc-uni5pay-payment-gateway'));
            }

            // Get Currency
            switch ($order->get_order_currency()) {
                case "USD":
                    $currency = "840";
                    break;
                case "SRD":
                    $currency = "968";
                    break;
                case "EUR":
                    $currency = "978";
                    break;
            }

            // Check currency
            if ($currency == null) {
                return new WP_Error('uni5pay_refund_error', __('Refund Error: Uni5Pay+ does not support this currency. (' . $order->get_order_currency() . ')', 'wc-uni5pay-payment-gateway'));
            }

            $requestBody = array('OrigextOrderNo' => $extOrderNo, 'amount' => $amount, 'currency' => 968, 'terminalId' => 'WEB');

            //API post URL
            $apiRefundUrl = $this->API_HOST . $this->API_SESSION_REFUND_ENDPOINT;

            //API response
            $response = wp_remote_post(
                $apiRefundUrl,
                array(
                    'method' => 'POST',
                    'timeout' => 45,
                    'redirection' => 5,
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => $this->header,
                    'body' => $requestBody,
                )
            );

            $jsonResponse = json_decode($response['body'], true);

            if ($jsonResponse['rspCode'] == '00') {
                return true;
            } else {

                $order->add_order_note('Server Error: ' . json_encode($jsonResponse), true);

                return new WP_Error('uni5pay_refund_error', __('Refund Error: Bad response from Uni5Pay+ Server' . json_encode($jsonResponse), 'wc-uni5pay-payment-gateway'));
            }
        }

        // Webhooks
        public function webhook_success()
        {
            // Get order
            $order = wc_get_order($_GET['orderId']);

            // Redirect to the thank you page
            return wp_redirect($this->get_return_url($order));
        }

        public function webhook_failure()
        {
            // Get order
            $order = wc_get_order($_GET['orderId']);

            $order->add_order_note('Uni5Pay+ Payment canceled', true);
            wc_add_notice('Uni5Pay+ Payment canceled.', 'error');
            wp_safe_redirect(wc_get_page_permalink('checkout'));
        }

        public function webhook_notify()
        {
            global $post;

            $ipn_raw = file_get_contents("php://input");

            $ipn = json_decode($ipn_raw);
            $order_number = $ipn->mchtOrderNo;

            if ($ipn->status == 'PAID') {
                // Get order
                $order = wc_get_order($order_number);

                // Compare order_id with order_number
                if (empty($order) || $order_number != $order->get_id()) {
                    $args    = array(
                        'post_type'      => 'shop_order',
                        'post_status'    => 'any',
                        'meta_query'     => array(
                            array(
                                'key'        => '_uni5pay_order_number',
                                'value'      => $order_number,
                                'compare'    => '=',
                            )
                        )
                    );
                    $query   = new WP_Query($args);
                    if (!empty($query->posts)) {
                        $order_id = $query->posts[0]->ID;
                    }

                    $order = wc_get_order($order_id);
                } else {
                    $order_id = $order_number;
                }

                // Add order note
                $order->add_order_note('Voucher Number: ' . $ipn->orderNo, true);
                $order->add_order_note('Order paid with Uni5Pay+', true);

                // Add meta
                add_post_meta($order_id, '_uni5pay_extOrderNo', $ipn->extOrderNo);
                add_post_meta($order_id, '_uni5pay_orderNo', $ipn->orderNo);

                // Set transaction id
                $order->set_transaction_id($ipn->orderNo);

                // Reduce order stock
                $order->reduce_order_stock();

                // Complete order
                $order->payment_complete();
            }
        }
    }
}
