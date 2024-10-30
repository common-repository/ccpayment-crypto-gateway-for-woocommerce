<?php

class CCPayment_Client
{
    protected string $appId = '';
    protected string $appSecret = '';

    public string $apiEndPoint = 'https://ccpayment.com';


    const OrderStatusNO = 'Pending';
    const OrderStatusSuccess = 'Paid';
    const OrderStatusUnderpaid = 'Underpaid';
    const OrderStatusOverpaid = 'Overpayed';
    const OrderStatusFailed = 'Failed';
    const OrderStatusExpired = 'Expired';
    const OrderStatusExpiredUnderpaid = 'ExpiredUnderpaid';

    public function __construct()
    {

    }

    public function setApp($appId, $appSecret): void
    {
        $this->appId = $appId;
        $this->appSecret = $appSecret;
    }
    protected function getApiUrl($commandUrl): string
    {
        return trim($this->apiEndPoint, '/') . '/ccpayment/v2/' . $commandUrl;
    }

    public function  getOrderInfo($data)
    {
        return  $this->apiCall('getAppOrderInfo', $data);
    }

    /**
     * @param $req
     * // orderId/ product / fiatId / price /buyerEmail / notifyUrl / returnUrl
     * @return array
     */
    public function createCheckoutUrl($req): array
    {
        return $this->apiCall('createCheckoutUrl',$req);
    }

    private function isSetup(): bool
    {
        return !empty($this->appSecret) || !empty($this->appId);
    }

    private function apiCall($uri, $req = array())
    {
        if (!$this->isSetup()) {
            return array('error' => 'You have not called the Setup function with your appId and appSecret!');
        }
        return $this->guestApiCall($uri, $req);
    }

    public function guestApiCall($uri, $req = array())
    {
        $body = wp_json_encode($req);
        $timestamp = time();
        $payload = $this->appId.$timestamp;
        if (!empty($req)){
            $payload .= $body;
        }
        $signature = hash_hmac('sha256', $payload, $this->appSecret);

        $headers = array(
            'Content-Type' => 'application/json',
            "Timestamp" => $timestamp,
            "Appid" => $this->appId,
            "Sign" => $signature
        );

        $args = array(
            'timeout' => '60',
            'redirection' => '8',
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => $headers,
            'cookies' => array(),
        );
        if (!empty($req)){
            $args['body'] = $body;
        }

        try {
            $resp = wp_remote_post($this->getApiUrl($uri), $args);

            if (is_wp_error( $resp )){
                throw New Exception('cURL error: ' . $resp->get_error_message());
            }
            if ( ! isset( $resp['response'] ) ) {
                throw New Exception('cURL error: ' . wp_json_encode($resp));
            }
            if ($resp['response']['code'] != 200){
                throw New Exception('cURL error: ' . esc_html($resp['response']['message']));
            }

            $respBody = wp_remote_retrieve_body($resp);
            return $this->jsonDecode($respBody);
        }catch (\Exception $e) {
            infof("ccpayment response uri: " . $uri.",err: ".$e->getMessage());
            return array('code' => 10001, 'msg' => 'Could not send request to ccpayment API : ' . $e->getMessage());
        }
    }

    private function jsonDecode($data)
    {
        if (PHP_INT_SIZE < 8 && version_compare(PHP_VERSION, '5.4.0') >= 0) {
            // We are on 32-bit PHP, so use the bigint as string option. If you are using any API calls with Satoshis it is highly NOT recommended to use 32-bit PHP
            $dec = json_decode($data, TRUE, 512, JSON_BIGINT_AS_STRING);
        } else {
            $dec = json_decode($data, TRUE);
        }
        return $dec;
    }
}
