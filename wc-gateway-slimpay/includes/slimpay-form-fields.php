<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

return array(
	'enabled' => array(
		'title'   => __( 'Enable/Disable', 'woocommerce' ),
		'type'    => 'checkbox',
		'label'   => __( 'Enable SlimPay', 'slimpay' ),
		'default' => 'yes'
	),
	'title' => array(
		'title'       => __( 'Title', 'woocommerce' ),
		'type'        => 'text',
		'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
		'default'     => __( 'SlimPay', 'slimpay' ),
		'desc_tip'    => true
	),
	'description' => array(
		'title'       => __( 'Description', 'woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'Payment method description that the customer will see on your checkout.', 'woocommerce' ),
		'default'     => __( 'Pay by direct debit. You need your bank account number and your phone.', 'slimpay' ),
		'desc_tip'    => true
	),
	'description_alt' => array(
		'title'       => __( 'Description for an active mandate', 'woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'Payment method description that the customer will see on your checkout in case he already signed a mandate (parameters: %rum%, %date%).', 'woocommerce' ),
		'default'     => __( 'The amount will be debited directly from your account using the mandate <strong>%rum%</strong> you signed on %date%.', 'slimpay' ),
		'desc_tip'    => true
	),
	'confirmation' => array(
		'title'       => __( 'Confirmation of the debit order', 'woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'Confirmation that the customer will see after completing the checkout (parameters: %amount%, %date%, %label%).', 'woocommerce' ),
		'default'     => __( 'Your account will be charged %amount% on %date%. It will appear in your bank statement as "%label%".', 'slimpay' ),
		'desc_tip'    => true
	),
	'debit_label' => array(
		'title'       => __( 'Debit order label', 'woocommerce' ),
		'type'        => 'textarea',
		'description' => __( 'The debit description that will appear in the customer\'s bank account statement (parameters: %creditor%).', 'woocommerce' ),
		'default'     => __( '%creditor%', 'slimpay' ),
		'desc_tip'    => true
	)
);
?>