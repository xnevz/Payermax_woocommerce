<?php

namespace payermax\sdk\client;

use http\Exception\RuntimeException;
use payermax\sdk\constants\Env;
use payermax\sdk\domain\GatewayReq;
use GuzzleHttp\Client;
use payermax\sdk\domain\GatewayResult;
use payermax\sdk\utils\RSAUtils;

class PayermaxClient
{

    private static $merchantConfig;

    private static $client;
    private static $env;

    public static function setConfig($config, $env = null) {
        self::$merchantConfig = $config;
        if(empty($env)) {
            self::$env = Env::$prod;
        } else {
            self::$env = $env;
        }
        self::init();
    }

    private static function init() {
        self::$client = new Client([
            'base_uri' => self::$env,
            'timeout'  => 15.0
        ]);
    }


    public static function send($apiName, $data) {
        //构建参数
        $req = new GatewayReq();
        //ISO 8601 带毫秒的
        $dateTime = new \DateTime();
        $req->requestTime = $dateTime->format('Y-m-d\TH:i:s.vP');
        $req->merchantAppId = self::$merchantConfig->merchantAppId;
        $req->merchantNo = self::$merchantConfig->merchantNo;
        $req->data = $data;

        //转成json并签名
        $reqBody = json_encode($req);
        $sign = RSAUtils::sign($reqBody, self::$merchantConfig->merchantPrivateKey);
        //发送请求
        $requestPath = "/aggregate-pay/api/gateway/" . $apiName;
        $reqOptions = [
            'headers' => [
                'sign' => $sign,
                'content-type' => 'application/json'
            ],
            'body' => $reqBody
        ];
        $response = self::$client->request('POST', $requestPath, $reqOptions);

        $respBody = (string)$response->getBody();

        $respJson = json_decode($respBody, true);

        if(GatewayResult::success($respJson)
            && RSAUtils::verify($respBody, $response->getHeader('sign')[0], self::$merchantConfig->payermaxPublicKey)) {
            return $respJson;
        }

        throw new \RuntimeException($respBody);
    }

    public static function verify($body, $sign) {
        if(empty(self::$merchantConfig) || empty(self::$merchantConfig->payermaxPublicKey)) {
            throw new \RuntimeException("请配置payermax公钥");
        }

        return RSAUtils::verify($body, $sign, self::$merchantConfig->payermaxPublicKey);
    }



}
