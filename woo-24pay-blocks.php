<?php
defined( 'ABSPATH' ) or exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WooCommerce Checkout Block integration for the 24pay gateway.
 * Only registers the payment method in the block checkout UI - payment
 * processing, RURL/NURL handling and async notification processing stay
 * in Woo_24pay_Gateway (woo-24pay.php).
 */
final class WOO_24pay_Blocks_Support extends AbstractPaymentMethodType {

	// Must match Woo_24pay_Gateway::$id
	protected $name = '24pay_gateway';

	public function initialize() {
		$this->settings = get_option( 'woocommerce_24pay_gateway_settings', array() );
	}

	public function is_active() {
		return isset( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	public function get_payment_method_script_handles() {
		$path = PLUGIN_PATH_24PAY . 'assets/js/blocks.js';

		wp_register_script(
			'woo-24pay-blocks',
			plugins_url( 'assets/js/blocks.js', __FILE__ ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ),
			file_exists( $path ) ? filemtime( $path ) : '1.1.8',
			true
		);

		return array( 'woo-24pay-blocks' );
	}

	public function get_payment_method_data() {
		return array(
			'title'       => ! empty( $this->settings['title'] ) ? $this->settings['title'] : '24-pay',
			'description' => isset( $this->settings['description'] ) ? $this->settings['description'] : '',
			'icon'        => plugins_url( 'logos/24pay-icon.png', __FILE__ ),
			'supports'    => array( 'products' ),
		);
	}
}

