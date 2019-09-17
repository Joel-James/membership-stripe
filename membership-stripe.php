<?php
/**
 * Plugin Name: Membership 2 - Stripe Gateway
 * Plugin URI:  https://github.com/Joel-James/membership-stripe
 * Version:     1.0.0
 * Description: Stripe Checkout 2.0 implementation for Membership 2.
 * Author:      Joel James
 * Author URI:  https://github.com/Joel-James
 * License:     GPL2
 * License URI: http://opensource.org/licenses/GPL-2.0
 * Text Domain: membership-stripe
 */

// Direct hit? Rest in peace.
defined( 'WPINC' ) || die;

// Plugin directory.
define( 'M2STRIPE_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory.
define( 'M2STRIPE_VERSION', '1.0.0' );

if ( class_exists( 'MS_Gateway' ) ) {

	// Load required files.
	add_action( 'plugins_loaded', 'membership_stripe_load_dependencies' );

	// Register our gateway with M2.
	add_filter( 'ms_model_gateway_register', 'membership_stripe_register_gateway' );

	/**
	 * Include all required files.
	 *
	 * @since 1.0.0
	 */
	function membership_stripe_load_dependencies() {
		include_once 'includes/class-ms-gateway-stripecheckout.php';
		include_once 'includes/class-ms-gateway-stripecheckout-api.php';
		include_once 'includes/view/class-ms-gateway-stripecheckout-view-button.php';
		include_once 'includes/view/class-ms-gateway-stripecheckout-view-settings.php';
	}

	/**
	 * Register custom gatway with Membership 2.
	 *
	 * @param array $list Existing gateways.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	function membership_stripe_register_gateway( $list ) {
		// Add our custom gateway.
		$list['stripecheckout'] = 'MS_Gateway_StripeCheckout';

		return $list;
	}
}