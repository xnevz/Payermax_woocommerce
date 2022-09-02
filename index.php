<?php
/*
 * Plugin Name: WooCommerce Payermax Payment Gateway
 * Plugin URI: https://facebook.com/mr.a.blizzard
 * Description: This is the payermax payment method integration plugin for woocommerce
 * Author: Abdelkarim Bettayeb
 * Author URI: https://facebook.com/mr.a.blizzard
 * Version: 1.0.0
 */

require_once __DIR__ . '/api/App.php';

use payermax\sdk\config\MerchantConfig;

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */

add_filter('woocommerce_payment_gateways', 'payermax_add_gateway_class');
function payermax_add_gateway_class($gateways)
{
    $gateways[] = 'WC_PayerMax_Gateway';
    return $gateways;
}


/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'payermax_init_gateway_class');
function payermax_init_gateway_class()
{

    class WC_PayerMax_Gateway extends WC_Payment_Gateway
    {

        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct()
        {

            // gateway id
            $this->id = 'payermax';

            // logo
            $this->icon = plugins_url('/assets/logo.png', __FILE__);

            $this->method_title = 'Payermax Gateway';
            $this->method_description = 'PayerMax Direct Payment Gateway';

            $this->supports = array(
                'products'
            );

            $this->init_form_fields();

            $this->init_settings();

            // getting gateway options
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');

            $this->isTesting = 'yes' === $this->get_option('isTesting');
            $this->merchantNo = $this->get_option('merchantId');
            $this->merchantAppId = $this->get_option('appId');

            $this->testMerchantPrivateKey = $this->get_option('testMerchantPrivateKey');
            $this->testPayermaxPublicKey = $this->get_option('testPayermaxPublicKey');

            $this->merchantPrivateKey = $this->get_option('merchantPrivateKey');
            $this->payermaxPublicKey = $this->get_option('payermaxPublicKey');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // a callback hook to notify payment success
            add_action('woocommerce_api_' . $this->id, array($this, 'payment_success_callback'));
        }

        public function init_form_fields()
        {

            $this->form_fields = array(
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Credit Card',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Pay with your credit card.',
                ),
                'merchantId' => array(
                    'title'       => 'Merchant ID',
                    'type'        => 'text',
                    'description' => 'The merchant ID.',
                    'default'     => '',
                ),
                'appId' => array(
                    'title'       => 'Merchant App ID',
                    'type'        => 'text',
                    'description' => 'The merchant App ID.',
                    'default'     => '',
                ),
                'isTesting' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default'     => 'yes',
                    'desc_tip'    => true,
                ),
                'testMerchantPrivateKey' => array(
                    'title'       => 'Test Merchant Private Key',
                    'type'        => 'text'
                ),
                'testPayermaxPublicKey' => array(
                    'title'       => 'Test Payermax Public Key',
                    'type'        => 'text',
                ),
                'merchantPrivateKey' => array(
                    'title'       => 'Merchant Private Key',
                    'type'        => 'text'
                ),
                'payermaxPublicKey' => array(
                    'title'       => 'Payermax Public Key',
                    'type'        => 'text'
                )
            );
        }

        /*
		 * We're processing the payments here
		 */
        public function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            $merchantConfig = new MerchantConfig();
            $merchantConfig->merchantNo = $this->merchantNo;
            $merchantConfig->merchantAppId = $this->merchantAppId;

            if ($this->isTesting) {
                $merchantConfig->merchantPrivateKey = $this->testMerchantPrivateKey;
                $merchantConfig->payermaxPublicKey = $this->testPayermaxPublicKey;
            } else {
                $merchantConfig->merchantPrivateKey = $this->merchantPrivateKey;
                $merchantConfig->payermaxPublicKey = $this->payermaxPublicKey;
            }

            // create a nonce for security
            $nonce = substr(str_shuffle(MD5(microtime())), 0, 12);

            // set order nonce
            wc_add_order_item_meta($order_id, 'ipn_nonce', $nonce);

            // 
            $response = payermax_get_secure_url(
                $order,
                $this->get_return_url($order),
                home_url() . "?wc-api=payermax&nonce=" . $nonce . "&order_id=" . $order_id,
                $merchantConfig,
                $this->isTesting
            );

            // if payermax responded with a url ...
            if (isset($response['code']) && $response['code'] == 'APPLY_SUCCESS') {
                $url = $response['data']['redirectUrl'];
                // return a redirect response
                return array(
                    'result' => 'success',
                    'redirect' => $url
                );
            } else {
                // if not then do nothing and let woocommerce return an error by default
            }
        }

        /*
		 * This callback is fired by payermax when the payment succeeds
		 */
        public function payment_success_callback()
        {
            // get the order_id and nonce
            $order_id = isset($_REQUEST['order_id']) ? $_REQUEST['order_id'] : null;
            $nonce = isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : null;


            if (
                // if order_id or nonce is not defined ...
                is_null($order_id) ||
                is_null($nonce) ||
                // or nonces don't match
                wc_get_order_item_meta($order_id, 'ipn_nonce') != $nonce
            )
                // then do nothing
                return;

            $order = wc_get_order($order_id);

            // assert order is pending
            if ($order->get_status() != 'pending')
                return;
            
            // mark order as complete and reduce stock levels
            $order->payment_complete();
            wc_reduce_stock_levels($order_id);
        }
    }
}

// this action is fired before the thank you page
add_action('woocommerce_before_thankyou', 'before_thankyou', 10, 1);

function before_thankyou($order_id)
{
    global $wp;
    $order = new WC_Order($order_id);

    $success = isset($_REQUEST['status']) && $_REQUEST['status'] == 'SUCCESS';
    $refreshed = isset($_REQUEST['refreshed']) && $_REQUEST['refreshed'] == 'true';

    // if payment result is not sucess ...
    if (!$success) {

        // set order status to failed (needs refresh)
        $order->set_status('failed');
        $order->save();

        // we need to refresh once
        if (!$refreshed) {
            $vars = $_GET;
            $vars['refreshed'] = 'true';
            // redirect to the same url with the refreshed argument set to true
            wp_redirect(add_query_arg($vars, home_url($wp->request)));
        }
    }
}
