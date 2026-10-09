<?php
/*
Plugin Name: Woocommerce 24pay Payment gateway
Plugin URI: http://www.24-pay.sk
Description: 24pay Payment Gateway for WooCommerce e-shop.
Author: 24pay
Version: 1.1.8
Author URI: https://www.24-pay.sk
License: MIT
*/
 
defined( 'ABSPATH' ) or exit;

define( 'PLUGIN_PATH_24PAY', plugin_dir_path( __FILE__ ) );

require_once( PLUGIN_PATH_24PAY . 'woo-24pay-signgenerator.php' );
require_once( PLUGIN_PATH_24PAY . 'woo-24pay-datavalidator.php' );
require_once( PLUGIN_PATH_24PAY . 'woo-24pay-formbuilder.php' );
require_once( PLUGIN_PATH_24PAY . 'woo-24pay-nurlparser.php' );
require_once( PLUGIN_PATH_24PAY . 'woo-24pay-orderresolver.php' );

// Make sure WooCommerce is active
if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	return;
}


function woo_24pay_add_to_gateways( $gateways ) {
	$gateways[] = 'Woo_24pay_Gateway';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'woo_24pay_add_to_gateways' );

function woo_24pay_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=24pay_gateway' ) . '">' . __( 'Configure', 'wc-gateway-offline' ) . '</a>'
	);

	return array_merge( $plugin_links, $links );
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'woo_24pay_gateway_plugin_links' );

add_action( 'plugins_loaded', 'woo_24pay_gateway_init', 11 );

add_action('before_woocommerce_init', function(){
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
});

// Checkout Block support - registration only, no effect on payment/NURL processing.
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		return;
	}
	require_once PLUGIN_PATH_24PAY . 'woo-24pay-blocks.php';
	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		$registry->register( new WOO_24pay_Blocks_Support() );
	} );
} );

function woo_24pay_gateway_init() {

	class Woo_24pay_Gateway extends WC_Payment_Gateway {

		/**
		 * Whether the WordPress hooks below have already been registered
		 * in this request. This class gets instantiated MORE THAN ONCE per
		 * request in practice - once by WooCommerce itself (whenever it
		 * loads the list of available payment gateways, e.g.
		 * WC_Payment_Gateways::payment_gateways()) and once more by our own
		 * listener_24pay() (hooked to 'init', which runs on every single
		 * request so it can detect RURL/NURL requests).
		 *
		 * Without this guard, EVERY new instance's constructor would
		 * add_action() the SAME hooks again (with a different $this each
		 * time, so WordPress treats them as distinct callbacks and calls
		 * both). That was silently breaking the "payment is being
		 * processed" notice: start_thankyou_buffer() would run twice,
		 * opening TWO nested output buffers, and render_payment_status_notice()
		 * would then also run twice - the second call's ob_end_clean()
		 * discarded the notice the first call had just echoed, so nothing
		 * ever reached the browser. Guarding hook registration to a single
		 * instance per request fixes this at the root, regardless of how
		 * many times the class is instantiated.
		 *
		 * @var bool
		 */
		private static $hooks_registered = false;

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
	  
			$this->id                 = '24pay_gateway';
			$this->icon = plugins_url('', __FILE__).'/logos/24pay-icon.png';
			$this->has_fields         = false;
			$this->method_title       = '24pay_gateway';
			$this->method_description = 'Payment gateway 24-pay description.';
		  
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
		  
			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
//			$this->instructions = $this->get_option( 'instructions', $this->description );

			if ( self::$hooks_registered ){
				return;
			}
			self::$hooks_registered = true;

			// Actions
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action('woocommerce_receipt_24pay_gateway', array($this, 'payment_form'));
			// Background handler for NURL notifications - see process_nurl().
			add_action('woo_24pay_process_notification', array($this, 'handle_scheduled_notification'));

			// While the customer is redirected back from the gateway (RURL)
			// but the asynchronous NURL notification hasn't been applied to
			// the order yet, hide the "Pay"/"Cancel" actions that WooCommerce
			// (or the theme) shows for orders that still needs_payment() -
			// otherwise the customer briefly sees "pay again", even though
			// the payment might still succeed a moment later. See
			// filter_order_needs_payment() and render_payment_status_notice().
			add_filter('woocommerce_order_needs_payment', array($this, 'filter_order_needs_payment'), 10, 3);
			// Shows a friendly, auto-refreshing status message ("payment is
			// being processed" / "paid" / retry option on failure) on the
			// order-received (thank you) and My Account "view order" pages.
			//
			// woocommerce_before_thankyou fires right at the top of
			// checkout/thankyou.php, BEFORE WooCommerce's own hardcoded
			// "your transaction was declined, please try again" message
			// (which core prints purely based on the order's CURRENT
			// status, completely bypassing our needs_payment filter above).
			// While we're still awaiting the async NURL result, we buffer
			// and discard that stock markup in start_thankyou_buffer(), and
			// replace it with our own "processing" notice in
			// render_payment_status_notice() once the buffer is closed -
			// otherwise a customer whose retry payment is still being
			// confirmed in the background would briefly see a stale
			// "payment declined" message left over from an earlier FAIL.
			add_action('woocommerce_before_thankyou', array($this, 'start_thankyou_buffer'));
			add_action('woocommerce_thankyou_' . $this->id, array($this, 'render_payment_status_notice'));
			add_action('woocommerce_order_details_after_order_table', array($this, 'render_payment_status_notice'));
		}

		/**
		 * @var array<int,bool> Order IDs for which start_thankyou_buffer()
		 * has opened an output buffer that still needs to be closed by
		 * render_payment_status_notice().
		 */
		private $buffered_thankyou_orders = array();

		/**
		 * Starts buffering the thank-you page output for orders paid with
		 * this gateway while we're still awaiting the async NURL result, so
		 * WooCommerce's hardcoded "your payment was declined" markup (based
		 * on the order's current, possibly stale, status) never reaches the
		 * customer. The buffer is closed and discarded in
		 * render_payment_status_notice().
		 */
		public function start_thankyou_buffer($order_id){
			$order = wc_get_order($order_id);

			if ( $order && $order->get_payment_method() === $this->id && $this->is_awaiting_notification($order) ){
				ob_start();
				$this->buffered_thankyou_orders[$order_id] = true;
			}
		}

		/**
		 * How long (in seconds) we keep hiding the pay-again action and
		 * showing the "processing" notice after the customer is redirected
		 * back from the gateway without having received any NURL
		 * notification yet. Acts as a safety valve - if the webhook is lost
		 * for some reason, the customer can still retry payment manually
		 * after this window instead of being stuck forever.
		 */
		const AWAITING_NOTIFICATION_TIMEOUT = 600; // 10 minutes

		public function init_form_fields() {
	  
			$this->form_fields = apply_filters( 'woo_24pay_gateway_form_fields', array(
		  
				'enabled' => array(
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable 24-pay',
					'default' => 'yes'
				),
				
				'title' => array(
				  'title' => 'Title',
				  'type' => 'text',
				  'description' => 'This controls the title which the user sees during checkout.',
				  'default' => '24-pay | Platobná brána',
				),
				
				'description' => array(
				  'title' => 'Method description',
				  'type' => 'textarea',
				  'description' => 'Method description when selected during checkout.',
				  'default' => 'Zaplaťte bezpečne s vašou kreditnou kartou alebo bankovým prevodom pomocou služby 24pay.',
				),

				'is_test' => array(
				  'title' => 'Test mode',
				  'type' => 'checkbox',
				  'label' => 'Make payment on test environment (Use only during development!)',
				  'default' => 'yes',
				),
				
				'mid' => array(
					'title'       => 'Mid',
					'type'        => 'text',
					'description' => 'This parameter was send to you via SMS after contract sing.',
					'default'     => 'demoOMED',
					'desc_tip'    => true,
				),
				
				'key' => array(
					'title'       => 'Key',
					'type'        => 'text',
					'description' => 'This parameter was send to you via SMS after contract sing.',
					'default'     => '1234567812345678123456781234567812345678123456781234567812345678',
					'desc_tip'    => true,
				),

                'eshop' => array(
                    'title'       => 'EUR EshopId',
                    'type'        => 'text',
                    'description' => 'This parameter was send to you via SMS after contract sing.',
                    'default'     => '11111111',
                    'desc_tip'    => true,
                ),

                'eshop_czk' => array(
                    'title'       => 'CZK EshopId',
                    'type'        => 'text',
                    'description' => 'This parameter was send to you via SMS after contract sing.',
                    'default'     => '33333333',
                    'desc_tip'    => true,
                ),

                'eshop_pln' => array(
                    'title'       => 'PLN EshopId',
                    'type'        => 'text',
                    'description' => 'This parameter was send to you via SMS after contract sing.',
                    'default'     => '11111111',
                    'desc_tip'    => true,
                ),

                'eshop_huf' => array(
                    'title'       => 'HUF EshopId',
                    'type'        => 'text',
                    'description' => 'This parameter was send to you via SMS after contract sing.',
                    'default'     => '66666666',
                    'desc_tip'    => true,
                ),

                'rurl' => array(
                    'title' => 'EUR RURL',
                    'type' => 'text',
                    'description' => 'Specify url to which customer will be redirected after payment.',
                    'default' => get_site_url().'/24pay-rurl/',
                ),

                'rurl_czk' => array(
                    'title' => 'CZK RURL',
                    'type' => 'text',
                    'description' => 'Specify url to which customer will be redirected after payment (CZK).',
                    'default' => get_site_url().'/24pay-rurl/',
                ),

                'rurl_pln' => array(
                    'title' => 'PLN RURL',
                    'type' => 'text',
                    'description' => 'Specify url to which customer will be redirected after payment (PLN).',
                    'default' => get_site_url().'/24pay-rurl/',
                ),

                'rurl_huf' => array(
                    'title' => 'HUF RURL',
                    'type' => 'text',
                    'description' => 'Specify url to which customer will be redirected after payment (HUF).',
                    'default' => get_site_url().'/24pay-rurl/',
                ),

                'nurl' => array(
                    'title' => 'NURL',
                    'type' => 'text',
                    'description' => 'Specify url to which you will receive notification message.',
                    'default' => get_site_url().'/24pay-nurl/',
                ),

				'notify_email' => array(
				  'title' => 'Notify Email (optional)',
				  'type' => 'text',
				  'description' => 'Set email where you want receive notification ater payment.',
				  'default' => '',
				),

				'notify_client' => array(
					'title'   => 'Notify client by email',
					'type'    => 'checkbox',
					'label'   => 'Send payment status email to client',
					'default' => 'no'
				),

                'save_transaction_email' => array(
                    'title'   => 'Save transaction email',
                    'type'    => 'checkbox',
                    'label'   => 'Send offline payment link in case of no response or declined payment.',
                    'default' => 'no'
                ),

                'enable_logs' => array(
                    'title'   => 'Enable/Disable logs',
                    'type'    => 'checkbox',
                    'label'   => 'Enable 24-pay logs',
                    'default' => 'no'
                ),

                'language' => array(
                    'title' => 'Language',
                    'description' => 'If you choose specific language, the payment will always be displayed in this language!',
                    'type' => 'select',
                    'options' => array(
                        "auto"=>"automatically (based on the language in the order)",
                        "sk"=>"Slovenčina",
                        "cz"=>"Čeština",
                        "en"=>"English",
                        "de"=>"Deutch",
                        "fr"=>"Français",
                        "it"=>"Italiano",
                        "pl"=>"Polski",
                        "hu"=>"Magyar",
                        "es"=>"Español",
                        "ro"=>"Română",
                        "sl"=>"Slovenščina",
                    ),
                    'default' => 'auto'
                ),

                'cart' => array(
                    'title'   => 'Include cart & shipping',
                    'type'    => 'checkbox',
                    'label'   => 'Required only for the pay later payment method.',
                    'default' => 'no'
                ),
				
			) );
		}

		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}

		function process_payment($order_id)
	    {
	      $order = wc_get_order($order_id);
	      $redirect_url = add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true));

	      return array(
	        'result'    => 'success',
	        'redirect'  => $redirect_url
	      );
	    }
	
		function payment_form($order_id)
	    {
	      $order = wc_get_order($order_id);

	      $is_test = (!empty($this->settings['is_test']) && $this->settings['is_test']=='yes') ? true : false;
		  $notify_client = (!empty($this->settings['notify_client']) && $this->settings['notify_client']=='yes') ? true : false;
		  $save_transaction_email = (!empty($this->settings['save_transaction_email']) && $this->settings['save_transaction_email']=='yes') ? true : false;
		  $cart = (!empty($this->settings['cart']) && $this->settings['cart']=='yes') ? true : false;

//	      $language = 'SK';
	      $language = $this->get_current_lang_code();
	      $country = 'SVK';

	      // Remember which language the customer actually saw at checkout,
	      // so the status notice on the return page (render_payment_status_notice())
	      // is shown in the same language later, regardless of what the
	      // site's/server's "current" language happens to be at that time.
	      $order->update_meta_data('_24pay_lang_code', $language);
	      $order->save_meta_data();

	      $data = array(
	        'Mid' => $this->settings['mid'],
            'EshopId' => $this->get_eshop_id_by_currency(),
	        'MsTxnId' => $order->get_order_number(),
	        // Use option below if 3rd party order number plugin is used withou order load support in method load_order_by_mstxnid
		//'MsTxnId' => $order->get_id(), 
	        'Amount' => number_format($order->get_total(), 2, '.', ''),
	        'CurrAlphaCode' => get_woocommerce_currency(),
	        'ClientId' => str_pad($order->get_order_number(),3,"0",STR_PAD_LEFT),
	        'FirstName' => $order->get_billing_first_name(),
	        'FamilyName' => $order->get_billing_last_name(),
	        'Email' => $order->get_billing_email(),
	        'Country' => $country,
	        'Timestamp' => date("Y-m-d H:i:s"),
	        'LangCode' => $language,
	        'RedirectSign' => 'true',
	        'RURL' => $this->get_rurl_by_currency(),
	        'NURL' => $this->settings['nurl'],
	        'Debug' => 'true',
	      );

	      $signGenerator = new WOO_24pay_SignGenerator($data, $this->settings['key']);
	      $data['Sign'] = $signGenerator->sign();

 		  if (!empty($this->settings['notify_email']))
 		  	$data['NotifyEmail'] = $this->settings['notify_email'];
		  
		  if ($is_test)
			$data['url'] = 'https://test.24-pay.eu/pay_gate/paygt';
		  else
			$data['url'] = 'https://admin.24-pay.eu/pay_gate/paygt';

		  if ($notify_client)
			$data['NotifyClient'] = $order->get_billing_email();

          if ($save_transaction_email)
              $data['SaveTransactionEmail'] = $order->get_billing_email();

          if ($cart)
              $data['Cart'] = $this->get_cart_json_base64($order);

		  $dataValidator = new WOO_24pay_DataValidator();

		  if ($dataValidator->validate($data)){
		  	$formBuilder = new WOO_24pay_FormBuilder();
		  	echo $formBuilder->build($data);
		  	die();
		  }
		  else{
			echo $dataValidator->renderErrors();
			die();
		  }

		}

        public function get_eshop_id_by_currency(){
            $currAlphaCode = strtolower(get_woocommerce_currency());
            $eshopIdSuffixConf = array(
                'eur' => '',
                'czk' => '_czk',
                'pln' => '_pln',
                'huf' => '_huf'
            );

            if(array_key_exists($currAlphaCode, $eshopIdSuffixConf)){
                $suffix = $eshopIdSuffixConf[$currAlphaCode];
                return $this->settings['eshop' . $suffix];
            }

            // fallback if an unsupported currency slips through
            $this->write_log("Unsupported currency for EshopId lookup: " . $currAlphaCode);
            return $this->settings['eshop'];
        }

        public function get_rurl_by_currency(){
            $currAlphaCode = strtolower(get_woocommerce_currency());
            $rurlSuffixConf = array(
                'eur' => '',
                'czk' => '_czk',
                'pln' => '_pln',
                'huf' => '_huf'
            );

            if(array_key_exists($currAlphaCode, $rurlSuffixConf)){
                $suffix = $rurlSuffixConf[$currAlphaCode];
                $rurlKey = 'rurl' . $suffix;
                if (!empty($this->settings[$rurlKey])) {
                    return $this->settings[$rurlKey];
                }
            } else {
                $this->write_log("Unsupported currency for RURL lookup: " . $currAlphaCode);
            }

            if (!empty($this->settings['rurl'])) {
                return $this->settings['rurl'];
            }

            return get_site_url().'/24pay-rurl/';
        }

        function get_cart_json_base64( $order ) {
            if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
                return null;
            }

            $shipping_methods = $order->get_shipping_methods();
            $deliveryName  = '';
            $deliveryPrice = 0;

            if ( ! empty( $shipping_methods ) ) {
                $shipping_method = array_shift( $shipping_methods );
                $deliveryName  = $shipping_method->get_name();
                $deliveryPrice = $shipping_method->get_total();
            }

            $items = [];
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();

                $items[] = [
                    'itemName'        => $item->get_name(),
                    'itemDescription' => $product ? $product->get_short_description() : '',
                    'quantity'        => $item->get_quantity(),
                    'itemPrice'       => number_format( $item->get_total() / max(1, $item->get_quantity()), 2, '.', ''),
                ];
            }

            $data = [
                'deliveryName'  => $deliveryName,
                'deliveryPrice' => number_format( $deliveryPrice, 2, '.', '' ),
                'items'         => $items,
            ];

            return base64_encode( json_encode($data, JSON_UNESCAPED_UNICODE) );
        }

        public function get_current_lang_code(){
            if(!empty($this->settings['language']) && $this->settings['language'] != "auto") {
                return $this->settings['language'];
            }
            $supported_lang_codes = array("cs", "de", "en", "es", "fr", "hu", "it", "pl", "ro", "sk");
            $lang = get_bloginfo('language');
            $lang_code = explode("-", $lang);
            if(!$lang_code) {
                return "en";
            }
            return in_array($lang_code[0], $supported_lang_codes) ? $lang_code[0] : "en";
        }
	    public function load_order_by_mstxnid( $order_id )
	    {
	        $resolved_id = Order_Number_Resolver::resolve( $order_id );
	        return $resolved_id ? wc_get_order( $resolved_id ) : false;
	    }

		public function process_rurl($msTxnId){
			$order = $this->load_order_by_mstxnid($msTxnId);

			$redirectTarget = home_url();

			if($order!= false)
      		{
                $order->add_order_note("Client was successfully redirected");

      			$signGenerator = new WOO_24pay_SignGenerator(array('Mid'=>$this->settings['mid']), $this->settings['key']);
      			$message = $_GET['MsTxnId'].$_GET['Amount'].$_GET['CurrCode'].$_GET['Result'];
      			if ($signGenerator->sign($message) == $_GET['Sign']){
      				$redirectTarget = $this->get_return_url($order);

      				// IMPORTANT: RURL must NEVER be used to decide/confirm the
      				// order's final status (paid or failed) - not even for a
      				// "definitely negative" Result value. RURL is only a
      				// browser redirect initiated by the customer's own
      				// browser and can be replayed, skipped, blocked, or
      				// simply never arrive (customer closes the tab) - only
      				// the server-to-server NURL notification is authoritative
      				// and is the sole place order status transitions happen
      				// (see apply_notification_result()).
      				//
      				// So all we do here is (re-)arm "awaiting notification"
      				// so the order-received/view-order pages keep showing the
      				// "payment is being processed" message until the real
      				// NURL notification arrives and is applied - at which
      				// point the page will show either "paid" or the normal
      				// "pay again" action, per render_payment_status_notice().
      				// IMPORTANT: this must be (re-)armed on EVERY redirect,
      				// not just the very first one - a customer retrying
      				// payment after an earlier FAIL already has
      				// '_24pay_last_result' = 'FAIL' set, and without
      				// refreshing the flag here that retry's outcome would
      				// never show as "processing" while its own notification
      				// is still in flight. We skip it if the order is already
      				// known to be paid (avoids a pointless flicker back to
      				// "processing" on a stray repeat redirect), OR already in
      				// a terminal non-paid state (failed/cancelled) - which
      				// means a NURL notification with a definitive negative
      				// result was ALREADY applied for this very attempt, so
      				// there is nothing left to "await" and re-arming here
      				// would incorrectly show the "processing" spinner in a
      				// loop for up to AWAITING_NOTIFICATION_TIMEOUT even
      				// though the result is already known.
      				if ( ! $order->is_paid() && ! $order->has_status( array( 'failed', 'cancelled' ) ) ){
      					$order->update_meta_data('_24pay_awaiting_notification', time());
      					$order->save_meta_data();
      				}
      			}
      			else{
      				wc_add_notice('INVALID REDIRECT SIGN!', 'error');
      			}
      		}
            else {
                $this->write_log("Unable to get Order ID! for "  . $msTxnId);
            }
            $this->write_log("RURL: " . $redirectTarget);
      		wp_safe_redirect($redirectTarget);
        	die();
		}


		/**
		 * Whether we are still within the "grace window" after the customer
		 * was redirected back from the gateway, waiting for the async NURL
		 * notification to arrive and be applied, for an order paid with this
		 * gateway.
		 */
		private function is_awaiting_notification($order){
			if ( ! $order || $order->get_payment_method() !== $this->id ){
				return false;
			}

			$awaiting_since = (int) $order->get_meta('_24pay_awaiting_notification');
			if ( ! $awaiting_since ){
				return false;
			}

			return ( time() - $awaiting_since ) < self::AWAITING_NOTIFICATION_TIMEOUT;
		}

		/**
		 * Hides the "Pay"/"Cancel" actions WooCommerce (or the theme) shows
		 * for orders that still needs_payment(), while we are awaiting the
		 * async NURL result for this gateway - so the customer isn't
		 * offered to "pay again" for a payment that is still being
		 * processed in the background.
		 */
		public function filter_order_needs_payment($needs_payment, $order, $valid_order_statuses){
			if ( $needs_payment && $this->is_awaiting_notification($order) ){
				return false;
			}
			return $needs_payment;
		}

		/**
		 * Renders a friendly, auto-refreshing payment status notice on the
		 * order-received (thank you) and My Account "view order" pages for
		 * orders paid with this gateway:
		 *  - notification not processed yet -> "payment is being processed"
		 *    (page refreshes itself automatically, since the result arrives
		 *    asynchronously and there is nothing else to make it "live");
		 *  - success -> "paid";
		 *  - anything else (failed/refunded/...) -> no notice, so the
		 *    normal "pay again" action (rendered elsewhere) is shown.
		 */
		/**
		 * Translations for the status notices shown by
		 * render_payment_status_notice(), keyed by the same language codes
		 * used elsewhere in the plugin (see get_current_lang_code() /
		 * the 'LangCode' sent to the gateway in payment_form()). This lets
		 * the notices match whatever language the customer actually saw at
		 * checkout, without depending on WordPress' .mo/.po translation
		 * loading (which this plugin does not currently set up).
		 * 'cz' is kept as an alias of 'cs' since the "language" gateway
		 * setting stores Czech as 'cz', while get_current_lang_code()'s
		 * auto-detection (based on the site locale) uses the ISO code 'cs'.
		 */
		private function get_status_notice_translations(){
			return array(
				'sk' => array(
					'pending'  => 'Platba sa spracováva, chvíľu strpenie...',
					'paid'     => 'Platba bola úspešne prijatá. Ďakujeme za nákup!',
					'refunded' => 'Platba bola vrátená.',
				),
				'cs' => array(
					'pending'  => 'Platba se zpracovává, chviličku strpení...',
					'paid'     => 'Platba byla úspěšně přijata. Děkujeme za nákup!',
					'refunded' => 'Platba byla vrácena.',
				),
				'cz' => array(
					'pending'  => 'Platba se zpracovává, chviličku strpení...',
					'paid'     => 'Platba byla úspěšně přijata. Děkujeme za nákup!',
					'refunded' => 'Platba byla vrácena.',
				),
				'en' => array(
					'pending'  => 'Your payment is being processed, please wait...',
					'paid'     => 'Your payment was received successfully. Thank you for your purchase!',
					'refunded' => 'Your payment has been refunded.',
				),
				'de' => array(
					'pending'  => 'Die Zahlung wird verarbeitet, bitte warten...',
					'paid'     => 'Die Zahlung wurde erfolgreich empfangen. Vielen Dank für Ihren Einkauf!',
					'refunded' => 'Die Zahlung wurde zurückerstattet.',
				),
				'fr' => array(
					'pending'  => 'Le paiement est en cours de traitement, veuillez patienter...',
					'paid'     => 'Votre paiement a été reçu avec succès. Merci pour votre achat !',
					'refunded' => 'Votre paiement a été remboursé.',
				),
				'it' => array(
					'pending'  => 'Il pagamento è in fase di elaborazione, attendere prego...',
					'paid'     => 'Il pagamento è stato ricevuto con successo. Grazie per il tuo acquisto!',
					'refunded' => 'Il pagamento è stato rimborsato.',
				),
				'pl' => array(
					'pending'  => 'Płatność jest przetwarzana, proszę czekać...',
					'paid'     => 'Płatność została pomyślnie przyjęta. Dziękujemy za zakupy!',
					'refunded' => 'Płatność została zwrócona.',
				),
				'hu' => array(
					'pending'  => 'A fizetés feldolgozás alatt áll, kérjük várjon...',
					'paid'     => 'A fizetés sikeresen megtörtént. Köszönjük a vásárlást!',
					'refunded' => 'A fizetés visszatérítésre került.',
				),
				'es' => array(
					'pending'  => 'El pago se está procesando, espere un momento...',
					'paid'     => 'Su pago se ha recibido correctamente. ¡Gracias por su compra!',
					'refunded' => 'Su pago ha sido reembolsado.',
				),
				'ro' => array(
					'pending'  => 'Plata este în curs de procesare, vă rugăm așteptați...',
					'paid'     => 'Plata a fost primită cu succes. Vă mulțumim pentru achiziție!',
					'refunded' => 'Plata a fost rambursată.',
				),
				'sl' => array(
					'pending'  => 'Plačilo se obdeluje, prosimo počakajte...',
					'paid'     => 'Plačilo je bilo uspešno prejeto. Hvala za nakup!',
					'refunded' => 'Plačilo je bilo vrnjeno.',
				),
			);
		}

		/**
		 * Returns the given status notice ('pending'/'paid'/'refunded') in
		 * the language the customer actually used at checkout for this
		 * order (stored by payment_form() in '_24pay_lang_code'), falling
		 * back to the site's/gateway's current language, and finally to
		 * English if the language isn't one we have a translation for.
		 * Also passed through __() so a site can still override any of
		 * these via a standard .mo file for the '24pay' text domain if it
		 * chooses to (e.g. via Loco Translate), without needing that setup
		 * to already show a sensible, language-matched default.
		 */
		private function get_status_notice_text($order, $key){
			$lang = $order->get_meta('_24pay_lang_code');
			if ( ! $lang ){
				$lang = $this->get_current_lang_code();
			}

			$translations = $this->get_status_notice_translations();
			$text = isset($translations[$lang][$key]) ? $translations[$lang][$key] : $translations['en'][$key];

			return __($text, '24pay');
		}

		public function render_payment_status_notice($order){
			if ( is_numeric($order) ){
				$order = wc_get_order($order);
			}

			if ( ! $order || $order->get_payment_method() !== $this->id ){
				return;
			}

			$order_id = $order->get_id();

			// Discard whatever WooCommerce's thankyou.php already printed
			// before this hook fired (its hardcoded "payment declined"
			// message, based on the order's current - possibly stale -
			// status) if we started buffering it in start_thankyou_buffer().
			// This must happen unconditionally, before the dedup check
			// below, so the buffer is never left open.
			if ( isset($this->buffered_thankyou_orders[$order_id]) ){
				if ( ob_get_level() > 0 ){
					ob_end_clean();
				}
				unset($this->buffered_thankyou_orders[$order_id]);
			}

			// Both 'woocommerce_thankyou_{gateway_id}' and
			// 'woocommerce_order_details_after_order_table' can fire for the
			// very same order on the same page load (e.g. on the thank-you
			// page in newer WooCommerce versions) - make sure we only print
			// the notice once per order per request.
			static $rendered_for = array();
			if ( isset($rendered_for[$order_id]) ){
				return;
			}
			$rendered_for[$order_id] = true;

			if ( $this->is_awaiting_notification($order) ){
				echo '<div class="woocommerce-info woocommerce-24pay-status woocommerce-24pay-status--pending">'
					. esc_html( $this->get_status_notice_text($order, 'pending') )
					. '</div>';
				// The result is delivered asynchronously in the background -
				// there is no client-side event to react to, so we simply
				// reload the page every few seconds until the status changes.
				echo '<script>setTimeout(function(){ window.location.reload(); }, 5000);</script>';
				return;
			}

			if ( $order->is_paid() ){
				echo '<div class="woocommerce-message woocommerce-24pay-status woocommerce-24pay-status--paid">'
					. esc_html( $this->get_status_notice_text($order, 'paid') )
					. '</div>';
				return;
			}

			if ( $order->has_status('refunded') ){
				echo '<div class="woocommerce-info woocommerce-24pay-status woocommerce-24pay-status--refunded">'
					. esc_html( $this->get_status_notice_text($order, 'refunded') )
					. '</div>';
			}

			// Any other state (e.g. failed) - intentionally no notice here,
			// the standard "pay again" action (shown by WooCommerce/theme
			// based on $order->needs_payment()) already covers it.
		}

		/**
		 * Handles NURL notifications. Works correctly whether the gateway
		 * delivers the notification synchronously (single, in-order delivery
		 * right after the transaction) or asynchronously (server-to-server,
		 * possibly retried, delayed, duplicated or delivered out of order).
		 *
		 * IMPORTANT: this method returns as soon as the notification has been
		 * validated and safely reserved for processing - it does NOT wait for
		 * the actual order status update (payment_complete(), emails, stock
		 * changes, third-party hooks...) to finish. That potentially slow
		 * work is handed off to Action Scheduler (bundled with WooCommerce)
		 * and runs in the background, so the payment gateway gets an
		 * immediate response and never times out waiting on us. If Action
		 * Scheduler is unavailable for some reason, processing falls back to
		 * the previous fully-synchronous behaviour.
		 *
		 * No custom database table is used - idempotency state is stored
		 * directly in the order's own meta data (works transparently with
		 * both legacy post-based storage and HPOS), and concurrent
		 * notifications for the same order are serialized using an atomic
		 * WordPress option-based lock (wp_options.option_name has a UNIQUE
		 * index, so add_option() is atomic even without an external object
		 * cache).
		 *
		 * IMPORTANT: the per-order lock is NOT taken here anymore. It used
		 * to be acquired up-front (before handing the notification to Action
		 * Scheduler) and only released once the background job finished -
		 * which meant that a second notification for the same order (e.g. a
		 * SUCCESS arriving shortly after a FAIL, which is a perfectly normal
		 * gateway sequence - retried/alternative payment attempt) could be
		 * rejected outright just because the first one was still mid-flight
		 * in the background queue. The gateway would then have to retry the
		 * second notification later, and if its retry policy gave up before
		 * the lock was free, that notification was effectively lost.
		 * Now every syntactically/cryptographically valid notification for
		 * a known order is accepted immediately (gateway gets "OK" and never
		 * needs to retry), and the lock is only acquired - with a short,
		 * bounded wait instead of an immediate failure - right before the
		 * actual processing happens in the background, in
		 * handle_scheduled_notification(). This guarantees notifications for
		 * the same order are still applied strictly one at a time (and in
		 * the order Action Scheduler picks them up), without ever silently
		 * dropping one.
		 */
		public function process_nurl($xml)
    	{
	      $this->write_log($xml);
	      $notification = new WOO_24pay_NurlParser($xml, $this->settings['mid'], $this->settings['key']);

	      if (!$notification->parsed || !$notification->validateSign()){
	      	return false;
	      }

	      $order = $this->load_order_by_mstxnid($notification->msTxnId);

	      if ($order == false){
	      	$this->write_log("Unable to get Order ID! for " . $notification->msTxnId);
	      	return false;
	      }

	      $order_id = $order->get_id();

	      if ($this->is_notification_processed($order, $notification)){
	      	$this->write_log("Duplicate notification ignored: {$notification->msTxnId}/{$notification->pspTxnId}/{$notification->result}");
	      	return true;
	      }

	      $notification_data = array(
	      	'order_id'   => $order_id,
	      	'ms_txn_id'  => $notification->msTxnId,
	      	'psp_txn_id' => $notification->pspTxnId,
	      	'result'     => $notification->result,
	      );

	      // Preferred path: answer the gateway immediately (it gets its "OK"
	      // response right away and the HTTP connection is closed), then keep
	      // running THIS SAME PHP request to actually apply the notification -
	      // no WP-Cron / Action Scheduler round-trip involved. This
	      // completely avoids the multi-second "pickup delay" that Action
	      // Scheduler's cron-based pickup can add (worse under load, or when
	      // a security plugin/firewall throttles the loopback request that
	      // spawn_cron() relies on).
	      //
	      // fastcgi_finish_request() is provided by PHP-FPM, the standard
	      // SAPI on virtually all modern hosting (Apache+mod_fcgid,
	      // Nginx+PHP-FPM, etc.). It is NOT available under classic mod_php
	      // or the CLI SAPI, so we gracefully fall back to the Action
	      // Scheduler path below when it doesn't exist. LiteSpeed's
	      // equivalent (litespeed_finish_request()) is handled the same way,
	      // in its own branch since the two have had subtle behavioural
	      // differences historically.
	      if ( function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request') ){
	      	echo 'OK';
	      	http_response_code(200);

	      	if ( function_exists('fastcgi_finish_request') ){
	      		fastcgi_finish_request();
	      	} else {
	      		litespeed_finish_request();
	      	}

	      	$this->handle_scheduled_notification($notification_data);
	      	// The HTTP response was already sent above - nothing more to
	      	// output, and the caller (listener_24pay()) must not try to
	      	// echo/die on our behalf since the connection is already closed.
	      	die();
	      }

	      if ( function_exists('as_enqueue_async_action') ){
	      	// Hand off the actual processing to a background request. Note
	      	// this always succeeds immediately (no lock is taken here), so
	      	// several notifications for the same order arriving close
	      	// together (e.g. FAIL followed by SUCCESS) are all accepted and
	      	// queued straight away instead of some being rejected.
	      	as_enqueue_async_action('woo_24pay_process_notification', array($notification_data), '24pay');
	      	// Nudge WP-Cron to run right away (non-blocking) so the background
	      	// job is picked up as soon as possible instead of waiting for the
	      	// next opportunistic WP-Cron trigger.
	      	if ( function_exists('spawn_cron') ){
	      		spawn_cron();
	      	}
	      	return true;
	      }

	      // Fallback if Action Scheduler is not available: process
	      // synchronously, waiting briefly for the per-order lock (instead of
	      // failing immediately) so a notification is never rejected just
	      // because a sibling notification for the same order is still being
	      // applied on another concurrent request.
	      if (!$this->acquire_order_lock_blocking($order_id)){
	      	$this->write_log("Could not acquire processing lock for order {$order_id} in time, will retry.");
	      	return false;
	      }

	      try {
	      	// Re-fetch the order and re-check idempotency now that we hold the lock.
	      	$order = wc_get_order($order_id);

	      	if (!$order){
	      		$this->write_log("Order {$order_id} disappeared while waiting for lock.");
	      		return false;
	      	}

	      	if ($this->is_notification_processed($order, $notification)){
	      		$this->write_log("Duplicate notification ignored: {$notification->msTxnId}/{$notification->pspTxnId}/{$notification->result}");
	      		return true;
	      	}

	      	$result = $this->apply_notification_result($order, $notification);

	      	if ($result){
	      		$this->mark_notification_processed($order, $notification);
	      	}

	      	return $result;
	      }
	      finally {
	      	$this->release_order_lock($order_id);
	      }
	    }

		/**
		 * Background handler (invoked via Action Scheduler) that performs the
		 * actual, potentially slow order status update for a previously
		 * validated NURL notification.
		 *
		 * The per-order lock is acquired here (with a short bounded wait) so
		 * that notifications for the same order are still applied strictly
		 * one at a time, even though process_nurl() no longer serializes
		 * them up-front. If the lock is still held for longer than the wait
		 * (e.g. an unusually slow previous run), this job simply reschedules
		 * itself a few seconds later instead of dropping the notification.
		 */
		public function handle_scheduled_notification($notification_data){
			$order_id = isset($notification_data['order_id']) ? (int) $notification_data['order_id'] : 0;

			if (!$order_id){
				return;
			}

			if (!$this->acquire_order_lock_blocking($order_id)){
				$this->write_log("Order {$order_id} still locked, rescheduling notification for retry.");
				if ( function_exists('as_schedule_single_action') ){
					as_schedule_single_action( time() + 5, 'woo_24pay_process_notification', array($notification_data), '24pay' );
				}
				return;
			}

			try {
				$order = wc_get_order($order_id);

				if (!$order){
					$this->write_log("Scheduled notification: order {$order_id} not found.");
					return;
				}

				$notification = (object) array(
					'msTxnId'  => $notification_data['ms_txn_id'],
					'pspTxnId' => $notification_data['psp_txn_id'],
					'result'   => $notification_data['result'],
				);

				if ($this->is_notification_processed($order, $notification)){
					$this->write_log("Scheduled notification already processed, skipping: {$notification->pspTxnId}/{$notification->result}");
					return;
				}

				$result = $this->apply_notification_result($order, $notification);

				if ($result){
					$this->mark_notification_processed($order, $notification);
				}
			}
			finally {
				$this->release_order_lock($order_id);
			}
		}

		/**
		 * Atomic per-order lock, implemented via a WordPress option. Relies
		 * on the UNIQUE index on wp_options.option_name, so add_option()
		 * fails (returns false) if another process holds the lock already -
		 * no custom table or external cache required. Stale locks (left
		 * behind by a crashed/timed-out request) are automatically reclaimed.
		 */
		private function acquire_order_lock($order_id, $timeout_seconds = 20){
			$lock_name = '24pay_nurl_lock_' . $order_id;

			if ( add_option( $lock_name, time(), '', 'no' ) ){
				return true;
			}

			$locked_at = (int) get_option( $lock_name );
			if ( $locked_at && ( time() - $locked_at ) > $timeout_seconds ){
				update_option( $lock_name, time(), 'no' );
				return true;
			}

			return false;
		}

		/**
		 * Same as acquire_order_lock(), but waits (retrying with a short
		 * delay) for up to $max_wait_seconds before giving up, instead of
		 * failing on the very first attempt. Used so that a notification for
		 * an order that is already being processed is queued/delayed rather
		 * than rejected - which is what previously allowed a FAIL followed
		 * shortly by a SUCCESS notification to end up blocking the SUCCESS
		 * one.
		 */
		private function acquire_order_lock_blocking($order_id, $max_wait_seconds = 10, $timeout_seconds = 20){
			$deadline = microtime(true) + $max_wait_seconds;

			do {
				if ( $this->acquire_order_lock($order_id, $timeout_seconds) ){
					return true;
				}
				usleep(200000); // 200ms between attempts
			} while ( microtime(true) < $deadline );

			return false;
		}

		private function release_order_lock($order_id){
			delete_option( '24pay_nurl_lock_' . $order_id );
		}

		/**
		 * Checks whether this exact notification (same transaction + result)
		 * was already applied to this order before, using a small history
		 * kept in the order's own meta data.
		 */
		private function is_notification_processed($order, $notification){
			$processed = $order->get_meta('_24pay_processed_notifications');
			if (!is_array($processed)) $processed = array();

			return in_array($notification->pspTxnId . ':' . $notification->result, $processed, true);
		}

		private function mark_notification_processed($order, $notification){
			$processed = $order->get_meta('_24pay_processed_notifications');
			if (!is_array($processed)) $processed = array();

			$processed[] = $notification->pspTxnId . ':' . $notification->result;
			// Bound the history size - an order will realistically never receive more than a handful of notifications.
			$processed = array_slice($processed, -20);

			$order->update_meta_data('_24pay_processed_notifications', $processed);
			$order->save_meta_data();
		}

		/**
		 * Applies the notification result to the order, guarding against
		 * out-of-order delivery via a simple state-machine: a notification
		 * can never move the order to a "less final" state than one already
		 * applied by a previous notification for the SAME payment attempt.
		 *
		 * IMPORTANT: the "less final state" guard is scoped to a single
		 * payment attempt (identified by PspTxnId), not to the order as a
		 * whole. Merchants commonly let a customer retry payment on the same
		 * order after a FAIL, possibly with a different payment method -
		 * that retry is a brand new transaction at the gateway (new
		 * PspTxnId) and its notifications (which may legitimately start at
		 * PENDING/AUTHORIZED again) must never be suppressed just because a
		 * previous, unrelated attempt already ended in a "more final" state
		 * such as FAIL. Only notifications belonging to the same PspTxnId as
		 * the last one recorded are compared by priority (protecting against
		 * genuine out-of-order/duplicate delivery of that one transaction).
		 */
		private function apply_notification_result($order, $notification){
			$priority = array(
				'PENDING'    => 1,
				'AUTHORIZED' => 2,
				'FAIL'       => 3,
				'REVERSAL'   => 4,
				'OK'         => 5,
			);

			$last_result      = $order->get_meta('_24pay_last_result');
			$last_psp_txn_id  = $order->get_meta('_24pay_last_psp_txn_id');
			$same_attempt     = $last_psp_txn_id && $last_psp_txn_id === $notification->pspTxnId;
			$last_priority    = isset($priority[$last_result]) ? $priority[$last_result] : 0;
			$new_priority     = isset($priority[$notification->result]) ? $priority[$notification->result] : 0;

			if ($same_attempt && $last_result && $new_priority < $last_priority){
				$this->write_log("Ignoring out-of-order notification: {$notification->result} after {$last_result} for order {$order->get_id()} (attempt {$notification->pspTxnId})");
				return true;
			}

			// Note: not saved explicitly here - update_status()/payment_complete()
			// below already persist the order (and any pending meta changes,
			// including this one) via their own internal save(), so an extra
			// save_meta_data() call here would just be a redundant DB write.
			$order->update_meta_data('_24pay_last_result', $notification->result);
			$order->update_meta_data('_24pay_last_psp_txn_id', $notification->pspTxnId);
			// A real result for this attempt has now been recorded, so the
			// order-received/view-order pages no longer need to show the
			// "payment is being processed" placeholder - see
			// is_awaiting_notification() / render_payment_status_notice().
			$order->delete_meta_data('_24pay_awaiting_notification');

			/* OK - FAIL - PENDING - AUTHORIZED - REVERSAL */

			if($notification->result == 'OK')
	        {
                $this->write_log("Notification message received with Success result");

	        	$order->add_order_note("Notification message received with Success result");
	        	$order->payment_complete();
	        }
	        else if($notification->result == 'PENDING')
	        {
                $this->write_log("Notification message received with Pending result");

	        	$order->add_order_note("Notification message received with Pending result");
	        	$order->update_status('on-hold', '24-pay payment is pending. Payment status will be processed with next notification message.');
	        }
		    else if($notification->result == 'AUTHORIZED')
	        {
                $this->write_log("Notification message received with AUTHORIZED result");

	        	$order->add_order_note("Notification message received with AUTHORIZED result");
	        	$order->update_status('on-hold', '24-pay payment is AUTHORIZED. Payment status will be processed by your action.');
	        }
            else if($notification->result == 'REVERSAL')
            {
                $this->write_log("Notification message received with REVERSAL result");

                $order->add_order_note("Notification message received with REVERSAL result");
                $order->update_status('refunded', '24-pay payment is REVERSAL.');
            }
	        else
	        {
                $this->write_log("Notification message received with Fail result");

	        	$order->add_order_note("Notification message received with Fail result");
	        	$order->update_status('failed', '24-pay payment failed.');
	        }

	        // Belt-and-braces fix for a race condition with process_rurl():
	        // the browser's RURL redirect and this NURL notification can run
	        // as two CONCURRENT requests. If process_rurl() loads its own
	        // fresh $order and writes '_24pay_awaiting_notification' AFTER
	        // OUR $order object (above) already loaded its meta into memory
	        // but BEFORE we called save()/payment_complete() above, then our
	        // earlier $order->delete_meta_data('_24pay_awaiting_notification')
	        // call was a no-op for that entry - WooCommerce's per-object meta
	        // cache can only queue a delete for meta it already knows about,
	        // it cannot delete a row that a sibling request inserted
	        // concurrently. Net effect without this fix: the "payment is
	        // being processed" flag can survive in the database for up to
	        // AWAITING_NOTIFICATION_TIMEOUT (10 minutes) even though we just
	        // finished recording a definitive result - which is exactly the
	        // "keeps refreshing even though the notification was already
	        // processed" symptom. Re-fetching a FRESH order object here (as
	        // the very last step, after payment_complete()/update_status()
	        // already committed) and clearing the flag again closes that
	        // race for all practical purposes, since process_rurl() itself
	        // does very little work (a sync signature check + redirect) and
	        // will essentially always have finished its own save by now.
	        $fresh_order = wc_get_order( $order->get_id() );
	        if ( $fresh_order && $fresh_order->get_meta('_24pay_awaiting_notification') ){
	        	$fresh_order->delete_meta_data('_24pay_awaiting_notification');
	        	$fresh_order->save_meta_data();
	        	$this->write_log("Cleared a concurrently re-armed '_24pay_awaiting_notification' flag for order {$order->get_id()} (race with RURL redirect).");
	        }

	        return true;
	    }

        function write_log($log) {
            if((!empty($this->settings['enable_logs'])) && $this->settings['enable_logs']=='yes') {
                $logfile = fopen(PLUGIN_PATH_24PAY . "log.txt", "a") or die("Unable to open file!");

                if (is_array($log) || is_object($log)) {
                    fwrite($logfile, "[" . date("Y-m-d H:i:s") . "] => " . print_r($log, true) . "\n");
                } else {
                    fwrite($logfile, "[" . date("Y-m-d H:i:s") . "] => " . $log . "\n");
                }
                fclose($logfile);
            }
        }
		
	  }
  
	  add_action('init', 'listener_24pay');
	  function listener_24pay()
	  {
		$fullUrl = get_site_url().$_SERVER['REQUEST_URI'];
		$httpsUrl="https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
		$httpUrl="http://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']; 
		
		$gateway = new Woo_24pay_Gateway();
	    if(isset($_GET['MsTxnId']) && isset($_GET['Result'])) // RURL
	    {
      		$gateway->process_rurl($_GET['MsTxnId']);
	    }
	    else if(isset($_POST['params'])) // NURL
	    {	
	    	if (($httpsUrl == $gateway->settings['nurl']) || ($httpUrl == $gateway->settings['nurl']) || ($fullUrl == $gateway->settings['nurl'])){
		    	if(!$gateway->process_nurl($_POST['params'])){
		        	echo 'FAIL';
		        	die();
		    	}
		    	else{
		    		echo 'OK';
				http_response_code(200);
				die();
			};
	    	}
		else{
			//echo "URL MISMATCH <br/>";
			//echo "LISTENING ON: ".$gateway->settings['nurl']. "<br/>";
			//echo "HTTPS: ".$httpsUrl. "<br/>";
			//echo "HTTP: ".$httpUrl. "<br/>";
			//echo "FULL: ".$fullUrl. "<br/>";
		}

	    	//die();
	    }
	  }
}
