<?php
/**
 * Plugin Name: SlimPay Gateway for WooCommerce
 * Plugin URI: http://slimpay.net/
 * Description: The SlimPay Gateway for WooCommerce. Allow your customers to pay by direct debit from their bank account.
 * Version: 1.1.2
 * Author: SlimPay (youssef@slimpay.com)
 * Tested up to: 4.3.1
 *
 * Text Domain: woocommerce-slimpay
 * Domain Path: /i18n/
 *
 * @package SlimPay
 * @author SlimPay
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * I18n
 */
add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'woocommerce-slimpay', false, plugin_basename( dirname( __FILE__ ) ) . '/i18n' ); 
});

/**
 * Required functions
 */
if ( ! function_exists( 'is_woocommerce_active' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

/**
 * Check if WooCommerce is active, and if it isn't, disable the SlimPay Gateway.
 */
if ( ! is_woocommerce_active() ) {
	add_action( 'admin_notices', function () {
		if ( current_user_can( 'activate_plugins' ) && ! is_woocommerce_active() ) {
?>
<div id="message" class="error">
	<p><?php
printf(
	__( '%sSlimPay Gateway for WooCommerce is inactive.%s ' .
		'The %sWooCommerce plugin%s must be active for SlimPay Gateway for WooCommerce to work. ' .
		'Please %sinstall & activate WooCommerce%s', 'woocommerce-slimpay' ),
		'<strong>', '</strong>',
		'<a href="http://wordpress.org/extend/plugins/woocommerce/">', '</a>',
		'<a href="' . admin_url( 'plugins.php' ) . '">', '&nbsp;&raquo;</a>' ); ?></p>
</div>
<?php
		}
	} );
	return;
}

add_filter( 'woocommerce_payment_gateways', function ( $methods ) {
	$methods[] = 'WC_Gateway_SlimPay';
	return $methods;
});

  // The declaration of the Gateway class
add_action( 'plugins_loaded', function () {
	require_once( 'includes/class-wc-gateway-slimpay.php' );
} );