<?php
/**
 * Plugin Name: CCPayment Crypto Gateway For WooCommerce
 * Plugin URI: https://github.com/cctip/ccpayment-crypto-gateway-for-woocommerce
 * Description: Adds the CCPayment Crypto gateway to your WooCommerce website.
 * Version: 1.0.0
 *
 * Author: CCPayment
 * Author URI: https://ccpayment.com/
 *
 * Text Domain: ccpayment-crypto-gateway-for-woocommerce
 *
 * Requires at least: 5.4
 * Tested up to: 6.5
 *
 * License: GPLv2 or later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WC CCPayment Payment gateway plugin class.
 *
 * @class WC_CCPayment_Payments
 */
class CCPayment_Payments {

	/**
	 * Plugin bootstrapping.
	 */
	public static function init() {

        define('CCPAYMENT_PLUGIN_PATH', __DIR__);
        define('CCPAYMENT_PLUGIN_DIR_NAME', basename(CCPAYMENT_PLUGIN_PATH));
        define('CCPAYMENT_PLUGIN_URL', plugins_url(CCPAYMENT_PLUGIN_DIR_NAME . '/'));
        define('CCPAYMENT_WOOCOMMERCE_VERSION', '1.0.0');
        define('CCPAYMENT_TYPE', 'ApiDeposit'); // ApiDeposit
        define('CCPAYMENT_NAME', 'ccpayment');
        define('CCPAYMENT_LOG_FILE', CCPAYMENT_PLUGIN_PATH.'/error.log');

		// CCPayment Payments gateway class.
		add_action( 'plugins_loaded', array( __CLASS__, 'includes' ), 0 );

		// Make the CCPayment Payments gateway available to WC.
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );

		// Registers WooCommerce Blocks integration.
		add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'ccpayment_woocommerce_block_support') );

	}

	/**
	 * Add the CCPayment Payment gateway to the list of available gateways.
	 *
	 * @param array
	 */
	public static function add_gateway( $gateways ) {

		$options = get_option( 'ccpayment_settings', array() );

		if ( isset( $options['hide_for_non_admin_users'] ) ) {
			$hide_for_non_admin_users = $options['hide_for_non_admin_users'];
		} else {
			$hide_for_non_admin_users = 'no';
		}

		if ( ( 'yes' === $hide_for_non_admin_users && current_user_can( 'manage_options' ) ) || 'no' === $hide_for_non_admin_users ) {
			$gateways[] = 'CCPayment_Gateway';
		}
		return $gateways;
	}

	/**
	 * Plugin includes.
	 */
	public static function includes() {

		// Make the CCPayment_Gateway class available.
		if ( class_exists( 'WC_Payment_Gateway' ) ) {
			require_once 'includes/class-ccpayment-gateway.php';
		}
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Plugin url.
	 *
	 * @return string
	 */
	public static function plugin_abspath() {
		return trailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Registers WooCommerce Blocks integration.
	 *
	 */
	public static function ccpayment_woocommerce_block_support() {
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			require_once 'includes/blocks/class-ccpayment-blocks-support.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new CCPayment_Gateway_Blocks_Support() );
				}
			);
		}
	}

}

CCPayment_Payments::init();

function infof($message): void
{
    error_log('['.gmdate('Y-m-d H:i:s').'][CCPayment] '.$message.PHP_EOL, 3, CCPAYMENT_LOG_FILE);
}

/**
 * @throws \Random\RandomException
 */
function randomString($length): string
{
    $bytes = random_bytes($length);
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $index = ord($bytes[$i]) % strlen($characters);
        $randomString .= $characters[$index];
    }
    return $randomString;
}