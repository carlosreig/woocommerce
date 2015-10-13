<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * You can get test credentials at http://www.slimpay.net/rest-hapi-crawler/login.php?signup
 * You can get production credentials from the SlimPay sales/support team.
 */
define('SLIMPAY_APPID', '');
define('SLIMPAY_APPSECRET', '');
define('SLIMPAY_CREDITOR', '');

define('SLIMPAY_PRODUCTION', false); // true for production, false for the sandbox
?>