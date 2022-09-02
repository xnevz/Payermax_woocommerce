<?php

require 'vendor/autoload.php';

use payermax\sdk\client\PayermaxClient;
use payermax\sdk\config\MerchantConfig;

/**
 * @param WC_Order $order
 */
function payermax_get_secure_url(
    $order,
    $successUrl,
    $notifyUrl,
    $merchantConfig,
    $isTesting
) {
    
    $outTradeNo = $order->get_order_key() . time();
    $amount = $order->get_total();
    $userId = $order->get_customer_id();
    $currency = $order->get_currency();
    $country = $order->get_shipping_country();

    if (!$country)
        $country = 'SA';

    try {
        if ($isTesting)
            PayermaxClient::setConfig(
                $merchantConfig,
                \payermax\sdk\constants\Env::$uat
            );
        else
            PayermaxClient::setConfig(
                $merchantConfig,
                \payermax\sdk\constants\Env::$prod
            );

        $data = json_decode("{
            \"outTradeNo\": \"$outTradeNo\",
            \"subject\": \"Buying goods\",
            \"totalAmount\": \"$amount\",
            \"currency\": \"$currency\",
            \"country\": \"$country\",
            \"userId\": \"$userId\",
            \"language\": \"ar\",
            \"frontCallbackURL\": \"$successUrl\",
            \"notifyUrl\": \"$notifyUrl\"
        }", true);

        $resp = PayermaxClient::send('orderAndPay', $data);
        return $resp;
    } catch (Exception $e) {
        return false;
    }
}
