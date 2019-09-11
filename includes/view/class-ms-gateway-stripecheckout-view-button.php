<?php

/**
 * Class MS_Gateway_StripeCheckout_View_Button.
 *
 * @link    https://github.com/Joel-James/membership-stripe
 * @since   1.0.0
 * @author  Joel James <me@joelsays.com>
 */
class MS_Gateway_StripeCheckout_View_Button extends MS_View {

	/**
	 * Generate html to display in payment page.
	 *
	 * This is where we render the Stripe checkout button
	 * which is used to redirect to Stripe.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function to_html() {
		// Current subscription.
		$subscription = $this->data['ms_relationship'];
		// Current invoice.
		$invoice = $subscription->get_next_billable_invoice();
		// Gateway instance.
		$gateway = $this->data['gateway'];

		// Create wrapper class.
		$row_class = 'gateway_' . $gateway->id;
		if ( ! $gateway->is_live_mode() ) {
			$row_class .= ' sandbox-mode';
		}

		ob_start();
		?>
        <tr class="<?php echo esc_attr( $row_class ); ?>">
            <td class="ms-buy-now-column" colspan="2">
                <script src="https://js.stripe.com/v3/"></script>
                <button id="membership-stripe-checkout-button"><?php esc_html_e( 'Subscribe', 'membership-stripe' ); ?></button>
                <script>
					// Create new instance of Stripe.
					var stripe = Stripe('<?php echo $gateway->get_publishable_key(); ?>');
					// Checkout button element.
					var button = document.getElementById('membership-stripe-checkout-button');
					// On button click redirect to checkout.
					button.addEventListener('click', function () {
						stripe.redirectToCheckout({
							// Custom created session id.
							sessionId: '<?php echo $gateway->get_session( $invoice->membership_id ); ?>'
						}).then(function (result) {
							window.alert('Payment failed. Please try agin.');
						});
					});
                </script>
            </td>
        </tr>
		<?php
		$html = ob_get_clean();

		/**
		 * Filter to modify the html output.
		 *
		 * @since 1.0.0
		 */
		return apply_filters( 'ms_gateway_button-' . $gateway->id, $html, $this );
	}
}