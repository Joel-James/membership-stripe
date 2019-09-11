<?php

use Stripe\Stripe as StripeCheckout;
use Stripe\Checkout\Session as StripeCheckoutSession;

class MS_Gateway_Stripecheckout_View_Button extends MS_View {

	public function to_html() {
		$fields       = $this->prepare_fields();
		$subscription = $this->data['ms_relationship'];
		$invoice      = $subscription->get_next_billable_invoice();
		$member       = MS_Model_Member::get_current_member();
		$gateway      = $this->data['gateway'];

		$row_class = 'gateway_' . $gateway->id;
		if ( ! $gateway->is_live_mode() ) {
			$row_class .= ' sandbox-mode';
		}

		$stripe_data = array(
			'name'        => get_bloginfo( 'name' ),
			'description' => strip_tags( $invoice->short_description ),
			'label'       => $gateway->pay_button_url,
		);

		/**
		 * Users can change details (like the title or description) of the
		 * stripe checkout popup.
		 *
		 * @var array
		 * @since  1.0.2.4
		 */
		$stripe_data = apply_filters(
			'ms_gateway_stripecheckout_form_details',
			$stripe_data,
			$invoice
		);

		$stripe_data['email']    = $member->email;
		$stripe_data['key']      = $gateway->get_publishable_key();
		$stripe_data['currency'] = $invoice->currency;
		$stripe_data['amount']   = ceil( abs( $invoice->total * 100 ) ); // Amount in cents.
		$stripe_data['image']    = $gateway->get_vendor_logo();
		$stripe_data['locale']   = 'auto';
		$stripe_data['zip-code'] = 'true';

		if ( $invoice->discount ) {
			$stripe_data['duration']   = MS_Addon_Coupon_Model::DURATION_ONCE;
			$stripe_data['amount_off'] = ceil( abs( $invoice->discount * 100 ) );
		}

		$stripe_data = apply_filters( 'ms_gateway_stripecheckout_form_details_after', $stripe_data, $invoice );

		ob_start();
		?>
        <script src="https://js.stripe.com/v3/"></script>
        <button id="checkout-button"><?php esc_html_e( 'Subscribe', 'membership-stripe' ); ?></button>
        <script>
			var stripe = Stripe('<?php echo $gateway->get_publishable_key(); ?>');
			var button = document.getElementById('checkout-button');
			button.addEventListener('click', function () {
				stripe.redirectToCheckout({
					sessionId: '<?php echo $this->get_session(); ?>'
				}).then(function (result) {
					window.alert('Failed');
				});
			});
        </script>
		<?php
		$payment_form = apply_filters(
			'ms_gateway_form',
			ob_get_clean(),
			$gateway,
			$invoice,
			$this
		);

		ob_start();
		?>
        <tr class="<?php echo esc_attr( $row_class ); ?>">
            <td class="ms-buy-now-column" colspan="2">
				<?php echo $payment_form; ?>
            </td>
        </tr>
		<?php
		$html = ob_get_clean();

		$html = apply_filters(
			'ms_gateway_button-' . $gateway->id,
			$html,
			$this
		);

		return $html;
	}

	private function get_session() {
		try {
			$gateway      = $this->data['gateway'];
			// Setup API key.
			StripeCheckout::setApiKey( $gateway->get_secret_key() );

			// Make sure everyone is using the same API version. we can update this if/when necessary.
			// If we don't set this, Stripe will use latest version, which may break our implementation.
			StripeCheckout::setApiVersion( '2019-09-09' );
			$session = StripeCheckoutSession::create( [
				'payment_method_types' => [ 'card' ],
				'subscription_data'    => [
					'items' => [
						[
							'plan' => 'ms-plan-1459-6kIo9KTpcNmAlpyCiYM6Yy',
						],
					],
				],
				'success_url'          => 'https://m2.wpmudev.host/register',
				'cancel_url'           => 'https://m2.wpmudev.host/register',
			] );
			$session = $session->id;
		} catch ( Exception $e ) {
		    error_log($e->getMessage());
			$session = '';
		}

		return $session;
	}

	private function prepare_fields() {
		$gateway      = $this->data['gateway'];
		$subscription = $this->data['ms_relationship'];

		$fields = array(
			'_wpnonce'           => array(
				'id'    => '_wpnonce',
				'type'  => MS_Helper_Html::INPUT_TYPE_HIDDEN,
				'value' => wp_create_nonce(
					$gateway->id . '_' . $subscription->id
				),
			),
			'gateway'            => array(
				'id'    => 'gateway',
				'type'  => MS_Helper_Html::INPUT_TYPE_HIDDEN,
				'value' => $gateway->id,
			),
			'ms_relationship_id' => array(
				'id'    => 'ms_relationship_id',
				'type'  => MS_Helper_Html::INPUT_TYPE_HIDDEN,
				'value' => $subscription->id,
			),
			'step'               => array(
				'id'    => 'step',
				'type'  => MS_Helper_Html::INPUT_TYPE_HIDDEN,
				'value' => $this->data['step'],
			),
		);

		return $fields;
	}
}