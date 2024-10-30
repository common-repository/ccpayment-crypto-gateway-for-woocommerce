<?php

/**
 * WC_Gateway_CCPayment class
 *
 * @author   CCPayment
 * @package  WooCommerce CCPayment Payments Gateway
 * @since    1.0.0
 */


require_once(__DIR__ . '/class-ccpayment-client.php');

use WP_REST_Server;
/**
 * CCPayment Gateway.
 *
 * @class    WC_Gateway_CCPayment
 * @version  2.0.0
 */
class CCPayment_Gateway extends WC_Payment_Gateway {

    public string $appId;
    public string $appSecret;


    public int $expiredAt;
    //public $order_statuses;

    /** @var CCPayment_Client */
    private CCPayment_Client $ccpayment;

	/**
	 * Constructor for the gateway.
	 */
	public function __construct() {
        global $woocommerce;

		$this->id                 = CCPAYMENT_NAME;
		$this->icon               = apply_filters( 'ccpayment_icon', CCPAYMENT_PLUGIN_URL . 'assets/ccpayment.png' );
		$this->has_fields         = false;

		$this->method_title       = _x( 'CCPayment', 'CCPayment payment method', 'woocommerce-gateway-ccpayment' );
		$this->method_description = __( 'Start accepting cryptocurrency payments via CCPayment.', 'woocommerce-gateway-ccpayment' );

        $this->ccpayment = new CCPayment_Client();
		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables.
		$this->title                    = "CCPayment.com Payment Gateway for WooCommerce";
		$this->description              = "Start accepting cryptocurrency payments via CCPayment.";
		$this->instructions             = $this->get_option( 'instructions', $this->description );

        $this->appId = $this->get_option('app_id');
        $this->appSecret = $this->get_option('app_secret');
        $this->expiredAt = $this->get_option('expired_at');

        $this->ccpayment->setApp($this->appId, $this->appSecret);

        $ccpaymentSettings = get_option('ccpayment_settings', []);
        $ccpaymentSettings['icon'] = $this->icon;
        update_option('ccpayment_settings', $ccpaymentSettings);

		// Actions.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

       // add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_order_statuses' ) ); // todo 可以增加一些操作
        add_action('woocommerce_thankyou_ccpayment', array($this, 'thankyou'));
        add_action('woocommerce_api_wc_gateway_ccpayment', array($this, 'payment_callback'));

	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields(): void
    {

		$this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woocommerce'),
                'label' => __('Enable cryptocurrency payments via CCPayment', 'woocommerce'),
                'type' => 'checkbox',
                //  'disabled' => true,
                'description' => '',
                'default' => 'no',
            ),
            'app_id' => array(
                'title' => __('* APP ID', 'woocommerce'),
                'type' => 'text',
                'description' => __("Visiting the <a target='_blank' href= 'https://console.ccpayment.com/developer/config' >developer page </a> to access your APP ID", 'woocommerce'),
                'custom_attributes' => array('required' => 'required'),
                'default' => (empty($this->get_option('app_id')) ? '' : $this->get_option('app_id')),
            ),
            'app_secret' => array(
                'title' => __('* APP Secret', 'woocommerce'),
                'type' => 'password',
                'description' => __("Visiting the <a target='_blank' href= 'https://console.ccpayment.com/developer/config' >developer page </a> to access your APP Secret", 'woocommerce'),
                'custom_attributes' => array('required' => 'required'),
                'default' => (empty($this->get_option('app_secret')) ? '' : $this->get_option('app_secret')),
            ),

            'expired_at' => array(
                'title' => __( 'Order Expired', 'woocommerce' ),
                'type' => 'select',
                'class' => 'wc-enhanced-select',
                'description' =>  __("The active order's validity will be set with an expiration period, during which the rate between the fiat currency and the payment currency will remain fixed.", 'woocommerce'),
                'default' => 2,
                //'desc_tip' => true,
                'custom_attributes' => array('required' => 'required'),
                'options' => array(
                    1 => '30 Minutes',
                    2 => '1 Hour',
                    3 => '2 Hours',
                    4 => '3 Hours',
                    5 => '6 Hours',
                    6 => '12 Hours',
                    7 => '24 Hours',
                ),
            ),
            'Auto withdraw'=>array(
                'title' => __( 'Auto withdraw', 'woocommerce' ),
                'type' => 'hidden',
                'description' =>  __("Automatically withdraws to your designated address upon receiving payment from the payer. <a target='_blank' href='https://console.ccpayment.com/merchatsetting/menu/settings' >Enable Now></a>", 'woocommerce'),
            ),
		);
	}

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     * @return array
     * @throws \Random\RandomException
     */
	public function process_payment( $order_id ): array
    {
        global $woocommerce;
        $order = new WC_Order($order_id);

        $amount = $order->get_total();

        $data = array(
            'orderId' => $order->get_order_key().randomString(4).'-'.$order_id,
            'product' => $this->get_goods_name($order) . ' Order #' . $order->get_id(),
            'price' => number_format($amount, 2, '.', ''),
            'fiatSymbol' => $order->get_currency(),
            'expiredAt' => $this->convert_to_expiredAt($this->expiredAt),
            'buyerEmail'=>$order->get_billing_email('billing'),
//            'cancel_url' => $order->get_cancel_order_url(),
            'notifyUrl' => trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_ccpayment',
            'returnUrl' => add_query_arg('order-received', $order->get_id(), add_query_arg('key', $order->get_order_key(), $this->get_return_url($order))),
            'plugin' => 'woocommerce',
            'version' => CCPAYMENT_WOOCOMMERCE_VERSION
        );

        $response = $this->ccpayment->createCheckoutUrl($data);

        if ($response && $response['code'] == 10000 && !empty($response['data'])) {
            $woocommerce->cart->empty_cart();

            return array(
                'result' => 'success',
                'redirect' => $response['data']['checkoutUrl'],
            );
        }

        $message = __( 'Error occurred while processing the payment:  ' . esc_html__(esc_html($response['msg']).' '.esc_html($response['reason']), 'woocommerce-gateway-ccpayment' ));
        infof($message);
        throw new Exception( $message );
	}

    public function get_goods_name($order): string
    {
        $goodsName = '';
        $index = 0;
        foreach ($order->get_items() as $item_id => $item) {
            if ($index == 0) {
                $goodsName = $item->get_name();
            } else {
                $goodsName .= ', ' . $item->get_name();
            }
            $index++;
        }
        return wp_trim_words($goodsName);
    }
    public function convert_to_expiredAt($i): int
    {
        $expiredAt = 0;
        $hour = 3600;
        switch ($i) {
            case 1:
                $expiredAt =  $hour/2;
                break;
            case 2:
                $expiredAt =  $hour ;
                break;
            case 3:
                $expiredAt =  $hour *2;
                break;
            case 4:
                $expiredAt =  $hour *3;
                break;
            case 5:
                $expiredAt =  $hour*6;
                break;
            case 6:
                $expiredAt =  $hour*12;
                break;
            case 7:
                $expiredAt =  $hour*24;
                break;
        }
        return $expiredAt + time();
    }
    public function thankyou(): void
    {
        if ($description = $this->get_description()) {
            echo esc_textarea(wpautop(wptexturize($description)));
        }
    }

    private function verifyCallbackData($server, $body): bool
    {
        $appId = sanitize_text_field($server['APPID']);
        $timestamp = sanitize_text_field($server['TIMESTAMP']);
        $sign = sanitize_text_field($server['SIGN']);

        if (empty($appId) || empty($timestamp) || empty($sign) ) {
            return false;
        }

        $payload = $appId.$timestamp;
        if (!empty($body)){
            $payload .= $body;
        }
        $signature = hash_hmac('sha256', $payload, $this->appSecret);

        if ($signature != $sign) {
            infof("sign err, " . $sign.",sign:".$signature);
           return false;
        }

        return true;
    }

    /**
     * $_POST :
     * {
         * "type": "ApiDeposit",
         * "msg": {
             * "recordId": "20240227070332114087378078994432",
             * "orderId": "1709017331",
             * "coinId": 3096,
             * "coinSymbol": "TETH",
             * "status": "Success"
         * }
     * }
     */
    public function payment_callback(): void
    {
        try {
            $body = WP_REST_Server::get_raw_data();

            infof("callback1: ".$body);

            $request = json_decode($body, true);

            if ( !isset($request['type']) || $request['type']!= CCPAYMENT_TYPE){
                infof("This is not ccpayment order:".$request['type']);
                echo esc_html('Success');
                exit;
            }
            $rest = new WP_REST_Server();
            if (!$this->verifyCallbackData($rest->get_headers($_SERVER), $body)) {
                throw new Exception('VerifyCallbackData failed');
            }

            if ( !isset($request['msg']) ){
                throw new Exception('Message Format cannot be parsed:');
            }

            $data = $request['msg'];

            $tmp = explode("-", $data['orderId']);
            if ( !isset($tmp[1]) ){
                throw new Exception('Get orderId failed:'.$data['orderId']);
            }

            $orderId = $tmp[1];

            $order = new WC_Order($orderId);

            if ( !$order  || $order->get_payment_method() != CCPAYMENT_NAME) {
                infof('This ' . $orderId . '  is not CCPayment order');
                echo esc_html('Success');
                exit;
            }

            $ccpOrderInfo = $this->ccpayment->getOrderInfo(['orderId'=>$data['orderId']]);

            if ($ccpOrderInfo['code'] != 10000) {
                throw new Exception('This ' . $orderId . ' Fetch CCPayment order is err: '.$ccpOrderInfo['msg'] );
            }

            $status = $this->handlerOrderStatus($ccpOrderInfo);
            $virtual = $this->getVirtual($order);

            $wcOrderStatus = $this->getWoocommerceStatus($status, $virtual);

            switch ($status) { // todo The current states are only two: success or failure.
                case $this->ccpayment::OrderStatusNO:
                    WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id());
                    break;
                case $this->ccpayment::OrderStatusUnderpaid:
                    WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id());
                    $order->update_status($wcOrderStatus);
                    $order->add_order_note($this->ccpayment::OrderStatusUnderpaid);
                    break;
                case $this->ccpayment::OrderStatusSuccess:
                    $order->update_status($wcOrderStatus);
                    $order->add_order_note("Paid success");
                  //  $order->payment_complete();
                    break;
                case $this->ccpayment::OrderStatusOverpaid:
                    $order->update_status($wcOrderStatus);
                    $order->add_order_note($this->ccpayment::OrderStatusOverpaid);
                    break;
                case $this->ccpayment::OrderStatusExpired:
                    $order->update_status($wcOrderStatus);
                    $order->add_order_note($this->ccpayment::OrderStatusExpired);
                    break;
                case $this->ccpayment::OrderStatusFailed:
                    $order->update_status($wcOrderStatus);
                    $order->add_order_note($this->ccpayment::OrderStatusFailed);
                    break;
                case $this->ccpayment::OrderStatusExpiredUnderpaid:
                    $order->update_status($wcOrderStatus);
                    $order->add_order_note('Expired(Underpaid)');
                    break;
            }

            echo esc_html('Success');
            exit;
        } catch (Exception $e) {
            infof($e->getMessage());
            echo esc_html($e->getMessage());
            exit;
        }
    }

    protected function getVirtual($order): int
    {
        $virtual = 1;
        foreach ( $order->get_items() as $item ) {
            if ( ! is_object( $item ) ) {
                continue;
            }

            if ( $item->is_type( 'line_item' ) ) {
                $product        = $item->get_product();
                if ($product->get_virtual() == 0){
                    $virtual= 0;
                }
            }
        }
        return $virtual;
    }

    private function ccpaymentStatuses(): array
    {
        return array(
            $this->ccpayment::OrderStatusNO=> 'Pending',
            $this->ccpayment::OrderStatusSuccess => 'Paid',
            $this->ccpayment::OrderStatusUnderpaid => 'Underpaid',
            $this->ccpayment::OrderStatusOverpaid => 'Overpayed',
            $this->ccpayment::OrderStatusFailed => 'Failed',
            $this->ccpayment::OrderStatusExpired => 'Expired',
            $this->ccpayment::OrderStatusExpiredUnderpaid => 'Expired(Underpaid)',
            //'cancelled' => 'Cancelled'
        );
    }
    // {"Pending":"wc-pending","Paid":"wc-completed","Underpaid":"wc-processing","Overpayed":"wc-completed","Failed":"wc-failed","Expired":"wc-cancelled","ExpiredUnderpaid":"wc-failed"}
    private function woocommerceStatuses(): array
    {
        return array(
            $this->ccpayment::OrderStatusNO => 'wc-pending',
            $this->ccpayment::OrderStatusSuccess => 'wc-completed',
            $this->ccpayment::OrderStatusUnderpaid => 'wc-pending',
            $this->ccpayment::OrderStatusOverpaid => 'wc-completed',
            $this->ccpayment::OrderStatusFailed => 'wc-failed',
            $this->ccpayment::OrderStatusExpired => 'wc-cancelled',// $wcStatus == 'wc-cancelled'
            $this->ccpayment::OrderStatusExpiredUnderpaid => 'wc-failed',
        );
    }

    private function getWoocommerceStatus(string $status,int $virtual): string
    {
        $statuses = $this->woocommerceStatuses()[$status];
        if ($virtual == 0 && $statuses == "wc-completed"){
            return "wc-processing";
        }
        return $statuses;
    }

    private function handlerOrderStatus(array $ccpOrderInfo): string
    {
        $status = $this->ccpayment::OrderStatusNO;
        $paidAmount = "0";
        $data = $ccpOrderInfo['data'];

        if (!empty($data['paidList'])){
            foreach ($data['paidList'] as $item){
                if ($item['status'] == 'Success'){
                   $paidAmount = bcadd($paidAmount, $item['amount'],18);
                }
            }
        }
        $diff = bcsub($paidAmount, $data['amountToPay'],18);
        $comp = bccomp($diff, "0",18);

        $isPaid = bccomp($paidAmount, "0",18);
        if ( $comp == 0) {
            return $this->ccpayment::OrderStatusSuccess;
        }
        if ( $comp > 0) {
            return $this->ccpayment::OrderStatusOverpaid; // PayStatusOverpaid
        }

        if ( $data['expiredAt'] < time() && $isPaid <= 0){
            return $this->ccpayment::OrderStatusExpired; // PayStatusExpired
        }

        if ( $data['expiredAt'] < time() && $isPaid > 0){
            return $this->ccpayment::OrderStatusExpiredUnderpaid; // PayStatusExpiredUnderpaid
        }

        if ( $data['expiredAt'] > time() && $isPaid > 0){
            return $this->ccpayment::OrderStatusUnderpaid; // PayStatusUnderpaid
        }
        return $status;
    }

}
