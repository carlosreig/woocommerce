<?php

use \HapiClient\Http;
use \HapiClient\Hal;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Gateway_SlimPay extends WC_Payment_Gateway {
	private static $PROD_URL = 'https://api.slimpay.net';
	private static $SANDBOX_URL = 'https://api-sandbox.slimpay.net';

	private static $KEY_LAST_ORDER_ID = '_slimpay_last_order_id';
	private static $KEY_ACTIVE_MANDATE = '_slimpay_active_mandate';

	private $hapiClient;
	private $entryPoint;

	/**
	 * Constructor for the gateway.
	 * Two scenarios for process_payment( $order_id ):
	 * 1) the user doesn't have an active mandate:
	 * 		-	he is redirected to the SlimPay pages to sign a mandate
	 * 		-	when returning to the shop, the mandate reference is stored
	 * 			and a direct debit is created
	 * 2) the user has an active mandate:
	 * 		-	a direct debit is created using the mandate reference stored
	 * For a subscription payment (except for the first one), the mandate reference
	 * stored is used to create the direct debit. If the mandate is not active anymore,
	 * the payment is set as "failed" and the user will have to come back to the shop
	 * to renew his subscription.
	 */
	public function __construct() {
		// Features supported
		$this->supports = array(
			'products',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'gateway_scheduled_payments'
		);

		// Method settings
		$this->id                 = 'slimpay';
		$this->icon               = apply_filters('woocommerce_slimpay_icon',
										plugins_url( 'assets/images/slimpay.png', __FILE__ ));
		$this->has_fields         = false;
		$this->method_title       = __( 'SlimPay', 'woocommerce-slimpay' );
		$this->method_description = __( 'Pay online directly from your bank account.', 'woocommerce-slimpay' );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title			= $this->get_option( 'title' );
		$this->description		= $this->get_option( 'description' );
		$this->description_alt	= $this->get_option( 'description_alt' );
		$this->confirmation		= $this->get_option( 'confirmation' );
		$this->debit_label		= $this->get_option( 'debit_label' );

		// Credentials (we do not store them in the database for maximum security)
		require_once __DIR__ . '/slimpay-credentials.php';
		$this->appid		= trim(SLIMPAY_APPID);
		$this->appsecret	= trim(SLIMPAY_APPSECRET);
		$this->creditor		= trim(SLIMPAY_CREDITOR);
		$this->production	= (boolean) SLIMPAY_PRODUCTION;

		// Settings filter
		add_filter( 'woocommerce_gateway_description', array( $this, 'filter_description' ), 10, 2 );

		// Settings action
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id,
					 array( $this, 'process_admin_options' ) );

		// Can SlimPay be enabled?
		if ( !$this->is_valid_for_use() || !$this->appid || !$this->appsecret || !$this->creditor) {
			$this->enabled = 'no';
			return;
		}

		// Payment actions
		add_action( 'woocommerce_api_wc_gateway_slimpay', array( $this, 'return_url' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

		// Subscriptions automatic recurring billing
		add_action( 'scheduled_subscription_payment_' . $this->id,
					array( $this, 'scheduled_subscription_payment' ), 10, 3 );
	}

	/**
	 * During the checkout the description is different
	 * if the user has an active mandate or not.
	 */
	public function filter_description($description, $gateway_id) {
		if ($this->id != $gateway_id)
			return $description;

		if (!is_user_logged_in())
			return $description;

		// The current user
		$current_user = wp_get_current_user();
		$user_id = $current_user->ID;

		// The active mandate
		$mandate = $this->retrieve_active_mandate( $user_id );
		if (!$mandate)
			return $description;

		$rum = $mandate['rum'];
		$date = $this->format_date( $mandate['dateCreated'] );

		return $this->format_parameters(compact('rum', 'date'), $this->description_alt);
	}

	/**
	 * Initialize Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = include __DIR__ . '/slimpay-form-fields.php';
	}

	/**
	 * Check if this gateway is enabled and available for the shop currency.
	 *
	 * @return bool
	 */
	public function is_valid_for_use() {
		$current_currency = get_woocommerce_currency();
		$supported_currencies = apply_filters( 'woocommerce_slimpay_supported_currencies', array( 'EUR' ) );
		return in_array( $current_currency, $supported_currencies );
	}

	/**
	 * Admin Panel Options
	 */
	public function admin_options() {
		if ( ! $this->is_valid_for_use() ) {
			?>
			<div class="inline error">
				<p>
					<strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>:
					<?php printf( __( 'SlimPay does not support your store currency (%s).', 'woocommerce' ),
									get_woocommerce_currency() ); ?>
				</p>
			</div>
			<?php
		} elseif ( !$this->appid || !$this->appsecret || !$this->creditor ) {
			?>
			<div class="inline error">
				<p>
					<strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>:
					<?php printf( __( 'Please configure your SlimPay credentials in %s', 'woocommerce-slimpay' ),
					__DIR__ . '/slimpay-credentials.php' ); ?>
				</p>
			</div>
			<?php
		} else {
			parent::admin_options();
		}
	}

	/**
	 * URL to the REST HAPI Server
	 */
	public function hapi_url() {
		if ( $this->production )
			return self::$PROD_URL;
		else
			return self::$SANDBOX_URL;
	}

	/**
	 * Instanciate the HAPI client, proceed with
	 * an authentication request then return the client.
	 */
	public function hapi_client() {
		if ($this->hapiClient)
			return $this->hapiClient;

		require_once __DIR__ . '/vendor/autoload.php';

		// $this->hapiClient = new SlimPay\Client($this->hapi_url(), self::$PROD_URL . '/alps/v1');
		// $this->hapiClient->oauth2($this->appid, $this->appsecret);
		// $this->entryPoint = $this->hapiClient->request(new SlimPay\Request('/'));
		$this->hapiClient = new Http\HapiClient(
			$this->hapi_url(),
			'/',
			self::$PROD_URL . '/alps/v1',
			new Http\Auth\Oauth2BasicAuthentication(
				'/oauth/token',
				$this->appid,
				$this->appsecret
			)
		);
		return $this->hapiClient;
	}

	/**
	 * Process the payment during the checkout
	 *
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$customer = WC()->customer;
		$order = wc_get_order( $order_id );
		$user_id = $order->get_user_id();

		try {
			// Check for active mandate and use it if existing
			if ($user_id && $mandate = $this->retrieve_active_mandate( $user_id )) {
				// Complete the payment for this order
				$this->process_order_payment( $order, $mandate['rum'] );

				// Return to thank you page
				return array('result' => 'success', 'redirect' => $this->get_return_url( $order ));
			}

			// Create a mandate signature order
			$subscriberReference = $user_id ? $user_id : 'guest_' . $order_id;
			$slimpayOrder = $this->create_mandate_signature( $subscriberReference, $order );

			// Store the order reference
			$this->store_order_reference( $order_id, $slimpayOrder->getState()['reference'] );

			// Redirect to the SlimPay checkout
			return array('result' => 'success', 'redirect' => $slimpayOrder->getLink('https://api.slimpay.net/alps#user-approval')->getHref());
		} catch (Exception $e) {
			wc_add_notice( $e->getMessage(), 'error' );
		}
	}

	/**
	 * Process the return url from the mandate signature
	 */
	public function return_url() {

		// The current user
		$current_user = wp_get_current_user();
		$user_id = $current_user ? $current_user->ID : null;

		// His last ongoing order using SlimPay
		$order_and_reference = $this->retrieve_current_order();
		if (!$order_and_reference) {
			$html  = '<p>' . __('No order using SlimPay as payment method was found.', 'woocommerce-slimpay') . '</p>';
			$html .= '<p>' . __('If you did make an order, your session may have timed out.', 'woocommerce-slimpay') . '</p>';
			$html .= '<p>' . sprintf(__('Go back to the <a href="%s">home page</a>.', 'woocommerce-slimpay'), home_url()) . '</p>';

			wp_die( $html , __('SlimPay', 'woocommerce-slimpay'), array( 'response' => 200 ) );
		}

		extract($order_and_reference);
		
		// Retrieve the order
		$slimpayOrder = $this->retrieve_slimpay_order($order_reference);
		if ($slimpayOrder) {
			$state = $slimpayOrder->getState();
			// The state of the order
			if (strpos($state['state'], 'open') === 0) {
				// Well, this is awkward...
				wp_redirect( $slimpayOrder->getLink('user-approval')->getHref());
				exit;
			} elseif (strpos($state['state'], 'closed.completed') === 0) {
				// The subscriber reference
				$subscriberReference = $user_id ? $user_id : 'guest_' . $order->id;

				try {
					// Get the mandate rum and store it for this user
					$hapiClient = $this->hapi_client();
					$rel = new Hal\CustomRel('https://api.slimpay.net/alps#get-mandate');
					$mandate = $hapiClient->sendFollow(new Http\Follow($rel, 'GET'), $slimpayOrder);
					$state = $mandate->getState();
					$rum = $state['rum'];


					$this->store_active_mandate( $subscriberReference, $rum );
				} catch (\HapiClient\Exception\HttpException $e) {
					wp_die( $this->process_http_exception($e), __('SlimPay', 'woocommerce-slimpay'), array( 'response' => 200 ) );
				} catch (Exception $e) {
					wp_die( $e->getMessage(), __('SlimPay', 'woocommerce-slimpay'), array( 'response' => 200 ) );
				}

				// Complete the payment for this order
				$this->process_order_payment( $order, $rum );

				// Return to thank you page
				wp_redirect( $this->get_return_url( $order ) );
				exit;
			}
		}

		// Show error message
		$html  = '<p>' . __('The mandate signature was not completed.', 'woocommerce-slimpay') . '</p>';
		$html .= '<p>' . sprintf(__('Go back to the <a href="%s">checkout</a>.', 'woocommerce-slimpay'), $order->get_checkout_payment_url()) . '</p>';

		wp_die( $html , __('SlimPay', 'woocommerce-slimpay'), array( 'response' => 200 ) );
	}

	/**
	 * Complete the payment for the order and empty the cart.
	 */
	private function process_order_payment($order, $rum) {
		$is_subscription =  class_exists( 'WC_Subscriptions_Order' ) &&
							WC_Subscriptions_Order::order_contains_subscription( $order->id );

		// Amount depending on product type
		if ( $is_subscription ) {
			$amount = WC_Subscriptions_Order::get_total_initial_payment( $order );
			$ddReference = 'subscription_' . $order->id;
		} else {
			$amount = $order->get_total();
			$ddReference = 'order_' . $order->id;
		}

		// Send the direct debit if needed
		// We do not create a direct debit for a change of payment method
		$transaction_id = '';
		if ($amount > 0)
			$transaction_id = $this->create_direct_debit( $rum, $amount, $ddReference )->getState()['id'];

		// The order is completed (ID of the direct-debit as transaction_id)
		$order->payment_complete($transaction_id);

		// Remove cart
		WC()->cart->empty_cart();

		// Activate subscriptions
		if ($is_subscription)
			WC_Subscriptions_Manager::activate_subscriptions_for_order( $order );
	}

	/**
	 * Output for the order received page.
	 */
	public function thankyou_page( $order_id ) {
		// The last order
		$order = wc_get_order( $order_id );

		try {
			if (!$directDebit = $this->retrieve_direct_debit($order->get_transaction_id()))
				return;
			
			$directDebitRepresentation = $directDebit->getState();

			// Some information about the direct debit
			$amount	= wc_price($directDebitRepresentation['amount']);
			$label	= $directDebitRepresentation['label'];
			$date	= $this->format_date( $directDebitRepresentation['executionDate'], 2 );

			echo $this->format_parameters(compact('amount', 'date', 'label'), $this->confirmation);
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}

	/**
	 * Stores the order reference about the order
	 * so we can retrieve it in the return URL.
	 */
	private function store_order_reference( $order_id, $order_reference ) {
		update_post_meta( $order_id, '_slimpay_order_reference', $order_reference );
	}

	/**
	 * Returns the current order and its reference.
	 * @return	array('order' => $order, 'order_reference' => $order_reference)
	 *			or null if not found.
	 */
	private function retrieve_current_order() {
		if (!isset(WC()->session->order_awaiting_payment))
			return null;

		$order_id = absint( WC()->session->order_awaiting_payment );
		if (!$order_id)
			return null;

		$order_reference = get_post_meta($order_id, '_slimpay_order_reference', true);
		if (!$order_reference)
			return null;

		if ($order = wc_get_order( $order_id ))
			return array('order' => $order, 'order_reference' => $order_reference);
		else
			return null;
	}

	/**
	 * Store the active mandate for the user.
	 */
	private function store_active_mandate( $user_id, $rum ) {
		if ( strpos($user_id, 'guest_') === 0 )
			update_post_meta( str_replace( 'guest_', '', $user_id ), self::$KEY_ACTIVE_MANDATE, $rum );
		else
			update_user_meta( $user_id, self::$KEY_ACTIVE_MANDATE, $rum );
	}

	/**
	 * Return the array representation of the active
	 * mandate for the user or null if not found.
	 */
	private function retrieve_active_mandate( $user_id ) {
		$isGuest = strpos($user_id, 'guest_') === 0;
		if ( $isGuest )
			$rum = get_post_meta( str_replace( 'guest_', '', $user_id ), self::$KEY_ACTIVE_MANDATE, true );
		else
			$rum = get_user_meta( $user_id, self::$KEY_ACTIVE_MANDATE, true );

		if ($rum && $mandate = $this->retrieve_mandate($rum)) {
			$state = $mandate->getState();

			if ($state['state'] == 'active')
				return $state;
			else {
				if ( $isGuest )
					delete_post_meta( str_replace( 'guest_', '', $user_id ), self::$KEY_ACTIVE_MANDATE );
				else
					delete_user_meta( $user_id, self::$KEY_ACTIVE_MANDATE );
			}
		}

		return null;
	}

	/**
	 * Get a mandate by its rum
	 * @return SlimPay\Resource
	 */
	private function retrieve_mandate($rum) {

		  // Retrieve the entry point resource

		$hapiClient = $this->hapi_client();
		$res = $hapiClient->getEntryPointResource();
		//   // Data for get-mandates
		 $requestData = array('creditorReference' => $this->creditor, 'rum' => $rum);
		//
		//   // Follow the get-mandates link
		//   // URL: /mandates{?creditorReference,rum}
		// $follow = new SlimPay\Follow('get-mandates', 'GET', $requestData, 'urlencoded');

		$rel = new Hal\CustomRel('https://api.slimpay.net/alps#get-mandates');

		try {
			return $hapiClient->sendFollow(new Http\Follow($rel, 'GET', $requestData), $res);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Send the user to the SlimPay checkout for a mandate signature
	 * during the process_payment.
	 * @return SlimPay\Resource
	 */
	private function create_mandate_signature($subscriberReference, $order) {

		$order_id = $order->id;

		  // Retrieve the entry point resource
		$hapiClient = $this->hapi_client();
		$res = $hapiClient->getEntryPointResource();
		  // Data for create-orders
		$requestData = new Http\JsonBody(array(
				'started' => true,
				'creditor' => array('reference' => $this->creditor),
				'subscriber' => array('reference' => $subscriberReference),
				'items' => array(
					array(
						'type' => 'signMandate',
						'mandate' => array(
							//'rum' => '',
							'standard' => 'SEPA',
							'signatory' => array(
								//'honorificPrefix' => 'Mr',
								'givenName' => $order->billing_first_name,
								'familyName' => $order->billing_last_name,
								'email' => $order->billing_email,
								//'telephone' => null,
								'companyName' => empty($order->billing_company) ? null : $order->billing_company,
								'billingAddress' => array(
									'street1' => $order->billing_address_1,
									'street2' => $order->billing_address_2,
									'postalCode' => $order->billing_postcode,
									'city' => $order->billing_city,
									'country' => $order->billing_country
								)
							)
						)
					)
				)
			)
		);

		  // Follow the create-orders link
		  // URL: /orders
		$rel = new Hal\CustomRel('https://api.slimpay.net/alps#create-orders');
		$follow = new Http\Follow($rel, 'POST', null, $requestData);
		try {
			return $hapiClient->sendFollow($follow, $res);
		} catch (HapiClient\Exception\HttpException $e) {
			throw new Exception($this->process_http_exception($e));
		}
	}

	/**
	 * Create a direct debit for the given $user_id to
	 * be processed using the given mandate reference.
	 * @return SlimPay\Resource
	 */
	private function create_direct_debit($rum, $amount, $paymentReference = '') {

		  // Retrieve the entry point resource
		$hapiClient = $this->hapi_client();
		$res = $hapiClient->getEntryPointResource();
		  // Data for create-direct-debits
		$requestData = new Http\JsonBody(array(
				'amount' => $amount,
				'label' => $this->format_parameters(array('creditor' => $this->creditor), $this->debit_label),
				'paymentReference' => $paymentReference,
				'creditor' => array('reference' => $this->creditor),
				'mandate' => array('rum' => $rum, 'standard' => 'SEPA')
			)
		);

		  // Follow the create-direct-debits link
		  // URL: /direct-debits
		$rel = new Hal\CustomRel('https://api.slimpay.net/alps#create-direct-debits');
		$follow = new Http\Follow($rel, 'POST', null, $requestData);
		try {
			return $hapiClient->sendFollow($follow, $res);
		} catch (SlimPay\Exception\HttpException $e) {
			throw new Exception($this->process_http_exception($e));
		}
	}

	/**
	 * Get a direct debit by its ID
	 * @return SlimPay\Resource
	 */
	private function retrieve_direct_debit($id) {

		  // Retrieve the entry point resource
		$hapiClient = $this->hapi_client();
		$res = $hapiClient->getEntryPointResource();
		  // Data for get-direct-debits
		$requestData = array('id' => $id);

		  // Follow the get-mandates link
		  // URL: /direct-debits{?id}
		$rel = new Hal\CustomRel('https://api.slimpay.net/alps#get-direct-debits');
		$follow = new Http\Follow($rel, 'GET', $requestData);
		try {
			return $hapiClient->sendFollow($follow, $res);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * Get an order by its reference
	 * @return SlimPay\Resource
	 */
	private function retrieve_slimpay_order($order_reference) {
		  // Retrieve the entry point resource
		$hapiClient = $this->hapi_client();
		$res = $hapiClient->getEntryPointResource();

		  // Data for get-orders
		$requestData = array(
			'creditorReference' => $this->creditor,
			'reference' => $order_reference
		);

		  // Follow the get-orders link
		  // URL: /orders
		$rel = new Hal\CustomRel('https://api.slimpay.net/alps#get-orders');
		$follow = new Http\Follow($rel, 'GET', $requestData);
		try {
			return $hapiClient->sendFollow($follow, $res);
		} catch (Exception $e) {
			return null;
		}
	}

	/**
	 * scheduled_subscription_payment function.
	 *
	 * @param $amount_to_charge float The amount to charge.
	 * @param $order WC_Order The WC_Order object of the order which the subscription was purchased in.
	 * @param $product_id int The ID of the subscription product for which this payment relates.
	 * @access public
	 * @return void
	 */
	public function scheduled_subscription_payment( $amount_to_charge, $order, $product_id ) {
		$result = $this->process_subscription_payment( $order, $amount_to_charge );

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( __( 'Unable to charge!', 'woocommerce-slimpay' ) . ' ' . $result->get_error_message() );
			WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order, $product_id );
		} else {
			WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );
		}
	}

	/**
	 * process_subscription_payment function.
	 *
	 * @access public
	 * @param mixed $order
	 * @param int $amount (default: 0)
	 * @return void
	 */
	public function process_subscription_payment( $order = '', $amount = 0 ) {
		if ( !$amount )
			return true;

		$parent_order_id = WC_Subscriptions_Renewal_Order::get_parent_order_id($order);

		$user_id = $order->get_user_id();
		if (!$user_id) // Subscriptions do not allow a guest order but just in case...
			$user_id = 'guest_' . $parent_order_id;

		try {
			// Check for active mandate
			$mandate = $this->retrieve_active_mandate( $user_id );
			if (!$mandate) {
				if ($user_id)
					return new WP_Error( 'slimpay_error',
						sprintf( __( 'The mandate of the user ID "%s" is no longer active.',
										'woocommerce-slimpay' ), (string) $user_id ) );
				else
					return new WP_Error( 'slimpay_error',
						sprintf( __( 'The mandate of the order ID "%s" is no longer active.',
										'woocommerce-slimpay' ), (string) $parent_order_id ) );
			}

			// Send the payment for this order
			$this->create_direct_debit( $mandate['rum'], $amount, 'subscription_' . $parent_order_id );
		} catch (Exception $e) {
			return new WP_Error( 'slimpay_error', $e->getMessage() );
		}
	}

	/**
	 * Process HTTP exceptions
	 * @return 	The message from the response if existing.
	 *			The HTTP status code and reason phrase otherwise.
	 */
	private function process_http_exception(HapiClient\Exception\HttpException $e) {
		$message = null;

		try {
			$body = $e->getResponse()->json();

			if (isset($body['message']))
				$message = $body['message'] . (isset($body['code']) ? ' (' . $body['code'] . ')' : '');
		} catch (\Exception $e) { }

		if (!$message)
			$message = $e->getMessage() . '<br />' . $e->getResponse()->getBody();

		return $message;
	}

	/**
	 * Format a date using the WordPress and WooCommerce functions.
	 * $date must be a date/time string. Valid formats are explained here:
	 * http://php.net/manual/en/datetime.formats.php
	 * If $gmt_offset is null, the option set in WordPress will be used.
	 */
	private function format_date($date, $gmt_offset = null) {
		if (!$gmt_offset)
			$gmt_offset = get_option( 'gmt_offset' );

		$time = strtotime($date) + ( $gmt_offset * HOUR_IN_SECONDS );

		return date_i18n( wc_date_format(), $time );
	}

	/**
	 * Matches the keys (preceded and followed by %) and replace them by their values.
	 * @param array $aParameters	An associative array key -> value
	 * 								where key is a string used to match the parameter.
	 * @param string $text	The text potentially containing the parameters in the form %key%.
	 */
	private function format_parameters($aParameters, $text) {
		foreach ($aParameters as $key => $value)
			$text = str_replace("%$key%", $value, $text);

		return $text;
	}
}
