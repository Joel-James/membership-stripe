<?php

/**
 * Class that handles Stripe Checkout gateway.
 *
 * @link    https://github.com/Joel-James/membership-stripe
 * @since   1.0.0
 * @author  Joel James <me@joelsays.com>
 */
class MS_Gateway_StripeCheckout extends MS_Gateway {

	/**
	 * Unique ID for the gateway.
	 *
	 * @var string $ID
	 *
	 * @since 1.0.0
	 */
	const ID = 'stripecheckout';

	/**
	 * Gateway singleton instance.
	 *
	 * @var string $instance
	 *
	 * @since  1.0.0
	 */
	public static $instance;

	/**
	 * Stripe test secret key (sandbox).
	 *
	 * @var string $test_secret_key
	 *
	 * @since  1.0.0
	 * @see    https://support.stripe.com/questions/where-do-i-find-my-api-keys
	 */
	protected $test_secret_key = '';

	/**
	 * Stripe Secret key (live).
	 *
	 * @var string $secret_key
	 *
	 * @since  1.0.0
	 */
	protected $secret_key = '';

	/**
	 * Stripe test publishable key (sandbox).
	 *
	 * @var string $test_publishable_key
	 *
	 * @since  1.0.0
	 */
	protected $test_publishable_key = '';

	/**
	 * Stripe test singing secret (sandbox).
	 *
	 * @var string $test_signing_secret
	 *
	 * @since 1.0.0
	 */
	protected $test_signing_secret = '';

	/**
	 * Stripe publishable key (live).
	 *
	 * @var string $publishable_key
	 *
	 * @since  1.0.0
	 */
	protected $publishable_key = '';

	/**
	 * Stripe singing secret (live).
	 *
	 * @var string $publishable_key
	 *
	 * @since  1.0.0
	 */
	protected $signing_secret = '';

	/**
	 * Stripe Vendor Logo.
	 *
	 * @var string $vendor_logo
	 *
	 * @since  1.0.0
	 */
	protected $vendor_logo = '';

	/**
	 * Instance of the shared stripe API integration
	 *
	 * @var MS_Gateway_Stripecheckout_Api $api
	 *
	 * @since  1.0.0
	 */
	protected $_api = null;

	/**
	 * Initialize the object.
	 *
	 * @since  1.0.0
	 */
	public function after_load() {
		parent::after_load();

		// Create new instance of Checkout API.
		$this->_api = MS_Factory::load( 'MS_Gateway_StripeCheckout_Api' );

		// If the gateway is initialized for the first time then copy settings from Stripe Subsciptions gateway.
		if ( false === $this->test_secret_key ) {
			$single                     = MS_Factory::load( 'MS_Gateway_Stripeplan' );
			$this->test_secret_key      = $single->test_secret_key;
			$this->secret_key           = $single->secret_key;
			$this->test_publishable_key = $single->test_publishable_key;
			$this->publishable_key      = $single->publishable_key;
			$this->save();
		}

		$this->id             = self::ID;
		$this->name           = __( 'Stripe Checkout Gateway', 'membership-stripe' );
		$this->group          = 'Stripe Checkout';
		$this->manual_payment = false; // Recurring charged automatically.
		$this->pro_rate       = true;

		// These payment types are unsupported.
		$this->unsupported_payment_types = [
			MS_Model_Membership::PAYMENT_TYPE_PERMANENT,
			MS_Model_Membership::PAYMENT_TYPE_FINITE,
			MS_Model_Membership::PAYMENT_TYPE_DATE_RANGE,
		];

		// Update all payment plans and coupons.
		$this->add_action(
			'ms_gateway_toggle_stripecheckout',
			'update_stripe_data'
		);

		// Update a single payment plan.
		$this->add_action(
			'ms_saved_MS_Model_Membership',
			'update_stripe_data_membership'
		);
	}

	/**
	 * Get Stripe publishable key.
	 *
	 * @since 1.0.0
	 *
	 * @return string The Stripe API publishable key.
	 */
	public function get_publishable_key() {
		if ( $this->is_live_mode() ) {
			return $this->publishable_key;
		} else {
			return $this->test_publishable_key;
		}
	}

	/**
	 * Get Stripe secret key.
	 *
	 * The secret key should not be used outside this object!
	 *
	 * @since 1.0.0
	 *
	 * @return string The Stripe API secret key.
	 */
	public function get_secret_key() {
		if ( $this->is_live_mode() ) {
			return $this->secret_key;
		} else {
			return $this->test_secret_key;
		}
	}

	/**
	 * Get Stripe webhook signing secret.
	 *
	 * The secret key should be used to verify the webhook events.
	 *
	 * @since 1.0.0
	 *
	 * @return string The Stripe webhook secret.
	 */
	public function get_signing_secret() {
		if ( $this->is_live_mode() ) {
			return $this->signing_secret;
		} else {
			return $this->test_signing_secret;
		}
	}

	/**
	 * Creates the external Stripe-ID of the specified item.
	 *
	 * This ID takes the current WordPress Site-URL into account to avoid
	 * collisions when several Membership2 sites use the same stripe account.
	 *
	 * @note  : This is for backward compatibility.
	 *
	 * @param int    $id   The internal ID.
	 * @param string $type The item type, e.g. 'plan' or 'coupon'.
	 *
	 * @since 1.0.0
	 *
	 * @return string The external Stripe-ID.
	 */
	static public function get_the_id( $id, $type = 'item' ) {
		// Create a unique name.
		$hash = strtolower( md5( get_option( 'site_url' ) . $type . $id ) );

		// Generate hash.
		$hash = mslib3()->convert(
			$hash,
			'0123456789abcdef',
			'0123456789ABCDEFGHIJKLMNOPQRSTUVXXYZabcdefghijklmnopqrstuvxxyz'
		);

		$result = 'ms-' . $type . '-' . $id . '-' . $hash;

		return $result;
	}

	/**
	 * Create and return a new checkout session.
	 *
	 * @param int $membership_id   Membership ID.
	 * @param int $subscription_id Subscription relationship ID.
	 * @param int $step            Current step.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_session( $membership_id, $subscription_id, $step ) {
		$this->_api->set_gateway( $this );

		$plan_id = self::get_the_id(
			$membership_id,
			'plan'
		);

		//$plan_id = 'ms-plan-1459-6kIo9KTpcNmAlpyCiYM6Yy';

		return $this->_api->get_session( $plan_id, $subscription_id, $step );
	}

	/**
	 * Verify required fields.
	 *
	 * @since  1.0.0
	 * @return boolean True if configured.
	 * @api
	 *
	 */
	public function is_configured() {
		$key_pub = $this->get_publishable_key();
		$key_sec = $this->get_secret_key();

		$is_configured = ( ! empty( $key_pub ) && ! empty( $key_sec ) );

		return $is_configured;
	}

	/**
	 * Process the return after payment.
	 *
	 * We can not simply do anything here as we don't know the status of payment.
	 * We will process the subscription once we receive the webhook.
	 *
	 * @param MS_Model_Relationship $subscription The related membership relationship.
	 *
	 * @since 1.0.0
	 *
	 * @todo  We need to properly handle the payment return. Currently we are always marking
	 *       the invoice as paid.
	 *
	 * @return mixed|void
	 */
	public function process_purchase( $subscription ) {
		$success = false;

		$this->_api->set_gateway( $this );

		$subscription = MS_Factory::load(
			'MS_Model_Relationship',
			$subscription->id
		);

		// Get the invoice.
		$invoice = $subscription->get_previous_invoice();
		error_log(print_r($invoice,true));
		$note = '';

		// If the stripe flag is true.
		/*if ( isset( $_GET['stripe-checkout-success'] ) && 1 == $_GET['stripe-checkout-success'] ) {
			try {
				// Free, just process.
				if ( 0 == $invoice->total ) {
					$invoice->changed();
					$success = true;
					$note    = __( 'No payment for free membership', 'membership-stripe' );
				} else {
					// We are marking it as paid.
					$invoice->pay_it( self::ID );
					$note    = __( 'Payment successful', 'membership-stripe' );
					$success = true;
				}
			} catch ( Exception $e ) {
				$note = 'Stripe error: ' . $e->getMessage();
				MS_Model_Event::save_event( MS_Model_Event::TYPE_PAYMENT_FAILED, $subscription );
			}
		} else {
			// Payment failed.
			$note = __( 'Stripe payment failed. Please try again.', 'membership-stripe' );
		}*/

		// Save invoice.
		$invoice->gateway_id = self::ID;
		$invoice->save();

		// Debug log.
		MS_Helper_Debug::debug_log( $note );

		do_action(
			'ms_gateway_transaction_log',
			self::ID, // gateway ID
			'process', // request|process|handle
			$success, // success flag
			$subscription->id, // subscription ID
			$invoice->id, // invoice ID
			$invoice->total, // charged amount
			$note, // Descriptive text
			'' // External ID
		);

		return $invoice;
	}

	/**
	 * Checks all Memberships and creates/updates the payment plan on stripe if
	 * the membership changed since the plan was last changed.
	 *
	 * This function is called when the gateway is activated and after a
	 * membership was saved to database.
	 *
	 * @since 1.0.0
	 *
	 * @return void|bool
	 */
	public function update_stripe_data() {
		if ( ! $this->active ) {
			return false;
		}

		$this->_api->set_gateway( $this );

		// 1. Update all payment plans.
		$memberships = MS_Model_Membership::get_memberships();
		foreach ( $memberships as $membership ) {
			$this->update_stripe_data_membership( $membership );
		}
	}

	/**
	 * Creates or updates a single payment plan on Stripe.
	 *
	 * This function is called when the gateway is activated and after a
	 * membership was saved to database.
	 *
	 * @param MS_Model_Membership $membership Membership model.
	 *
	 * @since 1.0.0
	 *
	 * @return void|bool
	 */
	public function update_stripe_data_membership( $membership ) {
		if ( ! $this->active ) {
			return false;
		}

		$this->_api->set_gateway( $this );

		$plan_data = [
			'id'     => self::get_the_id( $membership->id, 'plan' ),
			'amount' => 0,
		];

		if ( ! $membership->is_free()
		     && $membership->payment_type == MS_Model_Membership::PAYMENT_TYPE_RECURRING
		) {
			// Prepare the plan-data for Stripe.
			$trial_days = null;
			if ( $membership->has_trial() ) {
				$trial_days = MS_Helper_Period::get_period_in_days(
					$membership->trial_period_unit,
					$membership->trial_period_type
				);
			}

			$interval  = 'day';
			$max_count = 365;
			switch ( $membership->pay_cycle_period_type ) {
				case MS_Helper_Period::PERIOD_TYPE_WEEKS:
					$interval  = 'week';
					$max_count = 52;
					break;

				case MS_Helper_Period::PERIOD_TYPE_MONTHS:
					$interval  = 'month';
					$max_count = 12;
					break;

				case MS_Helper_Period::PERIOD_TYPE_YEARS:
					$interval  = 'year';
					$max_count = 1;
					break;
			}

			$interval_count = min(
				$max_count,
				$membership->pay_cycle_period_unit
			);

			$settings                       = MS_Plugin::instance()->settings;
			$plan_data['amount']            = abs( intval( strval( $membership->price * 100 ) ) );
			$plan_data['currency']          = $settings->currency;
			$plan_data['product']           = [
				'name' => $membership->name,
			];
			$plan_data['interval']          = $interval;
			$plan_data['interval_count']    = $interval_count;
			$plan_data['trial_period_days'] = $trial_days;

			// Check if the plan needs to be updated.
			$serialized_data = json_encode( $plan_data );
			$temp_key        = substr( 'ms-stripe-' . $plan_data['id'], 0, 45 );
			$temp_data       = MS_Factory::get_transient( $temp_key );

			if ( $temp_data != $serialized_data ) {
				MS_Factory::set_transient(
					$temp_key,
					$serialized_data,
					HOUR_IN_SECONDS
				);

				$this->_api->create_or_update_plan( $plan_data );
			}
		}
	}

	/**
	 * Process Stripe Checkout WebHook requests.
	 *
	 * We will get webhook notification whenever the payements are processed
	 * by the Stripe.
	 *
	 * @since 1.0.0
	 */
	public function handle_webhook() {
		// Setup gateway.
		$this->_api->set_gateway( $this );

		// Webhook data.
		$payload = @file_get_contents( 'php://input' );

		// Get webhook signature.
		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : null;

		$event = $this->_api->get_webhook_event( $payload, $sig_header );

		// Log the data.
		$this->log( $payload );

		// Only required events needs to be processed.
		if ( ! $this->valid_event( $event->type ) ) {
			// No need to process this event.
			http_response_code( 200 );
			exit();
		}
		error_log( $event->type );

		// Handle the checkout.session.completed event
		if ( 'checkout.session.completed' === $event->type ) {
			//return $this->process_invoice_payment( $event->data->object->subscription );
		} elseif ( 'invoice.payment_succeeded' === $event->type ) {
			return $this->process_invoice_payment( $event->data->object->subscription );
		} elseif ( 'customer.subscription.deleted' === $event->type || 'invoice.payment_failed' === $event->type ) {
			$this->process_cancel( $event->data->object->subscription );
		}

		return true;
	}

	/**
	 * @param \Stripe\Checkout\Session $session
	 */
	private function process_invoice_payment( $subscription_id ) {
		// Get the Stripe subscription.
		$subscription = $this->_api->retrieve_subscription( $subscription_id );

		// Try if we can get the relationship id from subscription.
		if ( isset( $subscription->metadata['ms_relationship_id'] ) ) {
			$relationship = MS_Factory::load(
				'MS_Model_Relationship',
				$subscription->metadata['ms_relationship_id']
			);

			$invoice    = $relationship->get_current_invoice();
			$membership = $relationship->get_membership();

			if ( $invoice ) {
				if ( $invoice->status == MS_Model_Invoice::STATUS_PAID ) {
					$invoice = $subscription->get_next_invoice();
				}

				$invoice->ms_relationship_id = $relationship->id;
				$invoice->membership_id      = $membership->id;

				if ( 0 == $invoice->total ) {
					// Free, just process.
					$invoice->changed();
					$success = true;
					$notes   = __( 'No payment required for free membership', 'membership2' );
					$invoice->add_notes( $notes );
				} else {
					$notes           = __( 'Payment successful', 'membership2' );
					$success         = true;
					$invoice->status = MS_Model_Invoice::STATUS_PAID;
					$invoice->pay_it( self::ID, $subscription->latest_invoice );
					$invoice->add_notes( $notes );
					if ( defined( 'MS_STRIPE_PLAN_RENEWAL_MAIL' ) && MS_STRIPE_PLAN_RENEWAL_MAIL ) {
						MS_Model_Event::save_event( MS_Model_Event::TYPE_MS_RENEWED, $subscription );
					}
				}
				$invoice->save();
			} else {
				$this->log( 'Invoice not found after session completion' );
			}
		} else {
			$this->log( 'Could not find Stripe subscription : ' . $subscription_id );
		}
	}

	private function process_cancel( $subscription_id ) {
		// Get the Stripe subscription.
		$subscription = $this->_api->retrieve_subscription( $subscription_id );

		// Try if we can get the relationship id from subscription.
		if ( isset( $subscription->metadata['ms_relationship_id'] ) ) {
			$relationship = MS_Factory::load(
				'MS_Model_Relationship',
				$subscription->metadata['ms_relationship_id']
			);
			$relationship->cancel_membership();
		} else {
			$this->log( 'Could not find Stripe subscription : ' . $subscription_id );
		}
	}

	/**
	 * Valid Stripe events to check.
	 *
	 * We need to process only these events.
	 *
	 * @param string $event The event.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function valid_event( $event ) {
		$valid_events = [
			'checkout.session.completed',
			'invoice.created',
			'invoice.payment_succeeded',
			'customer.subscription.deleted',
			'invoice.payment_failed',
		];

		return in_array( $event, $valid_events );
	}

	/**
	 * Auto-update some fields of the _api instance if required.
	 *
	 * @param string $key   Field name.
	 * @param mixed  $value Field value.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function __set( $key, $value ) {
		switch ( $key ) {
			case 'test_secret_key':
			case 'test_publishable_key':
			case 'test_signing_secret':
			case 'secret_key':
			case 'publishable_key':
			case 'signing_secret':
				$this->_api->$key = $value;
				break;
		}

		if ( property_exists( $this, $key ) ) {
			$this->$key = $value;
		}
	}
}