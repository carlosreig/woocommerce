# Requirements

- PHP 5.4 or higher
- WordPress
- WooCommerce plugin
- WooCommerce Subscriptions plugin (optional)

# Installation

1. Copy the content of the folder to wp-content/plugins.
2. Open includes/slimpay-credentials.php.
3. Configure your application credentials.
4. Activate the SlimPay Gateway for WooCommerce in the WordPress plugins configuration page.

# Return page

The return URL of your SlimPay application must be set to "your_wordpress_url/wc-api/wc_gateway_slimpay"

No notification URL is needed but if required, use the URL to your home page.

# Credentials

You can get test credentials at http://www.slimpay.net/rest-hapi-crawler/login.php?signup

You can get production credentials from the SlimPay sales/support team.

# Translation

See: http://codex.wordpress.org/I18n_for_WordPress_Developers

A .pot file is already available in the i18n folder.