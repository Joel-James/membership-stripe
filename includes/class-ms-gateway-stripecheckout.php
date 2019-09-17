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

		// Generate the plan ID.
		$plan_id = self::get_the_id(
			$membership_id,
			'plan'
		);

		return $this->_api->get_session( $plan_id, $subscription_id, $step );
	}

	/**
	 * Verify required fields configured.
	 *
	 * Verify if the public key and secret keys are configured, then
	 * only the payment will work.
	 *
	 * @since 1.0.0
	 *
	 * @return boolean True if configured.
	 */
	public function is_configured() {
		// Get keys.
		$key_pub = $this->get_publishable_key();
		$key_sec = $this->get_secret_key();

		// Both public and secret keys are required.
		$is_configured = ( ! empty( $key_pub ) && ! empty( $key_sec ) );

		return $is_configured;
	}

	/**
	 * Process the return after payment.
	 *
	 * Stripe Checkout will send the invoice payment webhook event before the payment
	 * is returned back to the success page. So during this page is loaded, invoice
	 * should be in paid status.
	 *
	 * @see   https://stripe.com/docs/payments/checkout/fulfillment#webhooks
	 *
	 * @param MS_Model_Relationship $subscription The related membership relationship.
	 *
	 * @since 1.0.0
	 *
	 * @return MS_Model_Invoice|null
	 */
	public function process_purchase( $subscription ) {
		$success = false;

		$this->_api->set_gateway( $this );

		// Get the relationship object.
		$subscription = MS_Factory::load(
			'MS_Model_Relationship',
			$subscription->id
		);

		// Get the paying/paid invoice.
		$invoice = $subscription->get_previous_invoice();

		// Dummy note.
		$note = __( 'Stripe Checkout payment processing.', 'membership-stripe' );

		// Set the gateway id.
		if ( $invoice instanceof MS_Model_Invoice ) {
			$invoice->gateway_id = self::ID;
			$invoice->save();

			// Mark as paid.
			if ( $invoice->status === MS_Model_Invoice::STATUS_PAID ) {
				$success = true;
				$note    = __( 'Stripe Checkout payment processed.', 'membership-stripe' );
			}
		}

		// Debug log.
		MS_Helper_Debug::debug_log( $note );

		/**
		 * Execute transaction log hook after return.
		 *
		 * @param string $gateway_id      Gateway ID.
		 * @param string $process         Process type (request|process|handle).
		 * @param bool   $success         Success flag.
		 * @param string $subscription_id Subscription ID.
		 * @param string $invoice_id      Invoice ID.
		 * @param int    $total           Invoice total amount.
		 * @param string $note            Invoice note.
		 * @param string $external_id     External ID.
		 *
		 * @since 1.0.0
		 */
		do_action( 'ms_gateway_transaction_log', self::ID, 'process', $success, $subscription->id, $invoice->id, $invoice->total, $note, '' );

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

		// Get all available memberships.
		$memberships = MS_Model_Membership::get_memberships();

		// Loop through each membership and update the Stripe plan if required.
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

		// Only when membership is paid and recurring.
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

				// Create or update the plan in Stripe.
				$this->_api->create_or_update_plan( $plan_data );
			}
		}
	}

	/**
	 * Process Stripe Checkout WebHook requests.
	 *
	 * We will get web hook notification whenever the payments are processed
	 * by the Stripe.
	 * Stripe checkout will create the subscription and everything. We don't need
	 * to manually create them.
	 * We need to verify the webhook request with our webhook secret key.
	 *
	 * @since 1.0.0
	 *
	 * @uses  http_response_code()
	 *
	 * @return void
	 */
	public function handle_webhook() {
		// Setup gateway.
		$this->_api->set_gateway( $this );

		// Webhook data.
		$payload = @file_get_contents( 'php://input' );

		// Get webhook signature.
		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : null;

		// Get the webhook event from request.
		$event = $this->_api->get_webhook_event( $payload, $sig_header );

		// Log the data.
		$this->log( $payload );

		// Only required events needs to be processed.
		if ( ! $this->valid_event( $event->type ) ) {
			// No need to process this event.
			http_response_code( 200 );
			exit();
		}

		switch ( $event->type ) {
			// Handle the checkout completion event.
			case 'checkout.session.completed':
				// Currently nothing to do.
				break;
			// Handle when new customer is created in Stripe.
			case 'customer.created':
				$this->process_customer_creation( $event->data->object->id, $event->data->object->email );
				break;
			// Handle the invoice payment event.
			case 'invoice.payment_succeeded':
				$this->process_invoice_payment( $event->data->object->subscription );
				break;
			// Handle invoice creation.
			case 'invoice.created':
				$this->process_invoice_creation( $event->data->object->subscription );
				break;
			// Handle the subscription cancellation event.
			case 'invoice.payment_failed':
			case 'customer.subscription.deleted':
				$this->process_cancel( $event->data->object->subscription );
				break;
		}

		// Send success response.
		http_response_code( 200 );
		exit();
	}

	/**
	 * Process the Stripe Invoice Payment web hook request.
	 *
	 * Handles the invoice payment automatically processed by Stripe.
	 * We need to mark the M2 invoice as paid. M2 will automatically
	 * handle other required actions for the subscription.
	 * This will only work if we set M2 relationship id in Stripe subscription
	 * meta during session creation.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 *
	 * @since 1.0.0
	 *
	 * @return void
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

			// Do not process system memberships.
			if ( ! $relationship instanceof MS_Model_Relationship || $relationship->is_system() ) {
				return;
			}

			// Get current invoice. It's not paid yet.
			$invoice    = $relationship->get_current_invoice();
			$membership = $relationship->get_membership();

			if ( $invoice instanceof MS_Model_Invoice ) {
				// Incase if the invoice was already paid, get next invoice.
				if ( $invoice->status == MS_Model_Invoice::STATUS_PAID ) {
					$invoice = $relationship->get_next_invoice();
				}

				// Set the required IDs.
				$invoice->ms_relationship_id = $relationship->id;
				$invoice->membership_id      = $membership->id;

				// If free, just process right away.
				if ( 0 == $invoice->total ) {
					$invoice->changed();
					$notes = __( 'No payment required for free membership', 'membership-stripe' );
					$invoice->add_notes( $notes );
				} else {
					$notes = __( 'Payment successful', 'membership-stripe' );
					// Mark paid.
					$invoice->status = MS_Model_Invoice::STATUS_PAID;
					// Make the payment.
					$invoice->pay_it( self::ID, $subscription->latest_invoice );
					$invoice->add_notes( $notes );

					// Set the email event.
					if ( defined( 'MS_STRIPE_PLAN_RENEWAL_MAIL' ) && MS_STRIPE_PLAN_RENEWAL_MAIL ) {
						MS_Model_Event::save_event( MS_Model_Event::TYPE_MS_RENEWED, $subscription );
					}
				}

				$invoice->save();
			} else {
				$this->log( __( 'Invoice not found after session completion', 'membership-stripe' ) );
			}
		} else {
			$this->log(
				sprintf(
					__( 'Could not find Stripe subscription : %s', 'membership-stripe' ),
					$subscription_id
				)
			);
		}
	}

	/**
	 * Process the Stripe cancellation web hook request.
	 *
	 * When a subscription is cancelled in Stripe, we need to cancel
	 * it in the Membership subscription also.
	 * This will work only when relation id is found in Stripe subscription
	 * meta data.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function process_cancel( $subscription_id ) {
		// Get the Stripe subscription.
		$subscription = $this->_api->retrieve_subscription( $subscription_id );

		// Try if we can get the relationship id from subscription.
		if ( isset( $subscription->metadata['ms_relationship_id'] ) ) {
			$relationship = MS_Factory::load(
				'MS_Model_Relationship',
				$subscription->metadata['ms_relationship_id']
			);

			// Do not process system memberships.
			if ( ! $relationship instanceof MS_Model_Relationship || $relationship->is_system() ) {
				return;
			}

			// Cancel the membership.
			$relationship->cancel_membership();
		} else {
			$this->log(
				sprintf(
					__( 'Could not find Stripe subscription : %s', 'membership-stripe' ),
					$subscription_id
				)
			);
		}
	}

	/**
	 * Process the Stripe customer creation web hook request.
	 *
	 * Set the gateway profile for the member.
	 *
	 * @param string $customer_id Stripe customer ID.
	 * @param string $email       Stripe email ID.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function process_customer_creation( $customer_id, $email ) {
		// Get the WP user.
		$user = get_user_by_email( $email );

		if ( ! $user instanceof WP_User ) {
			$this->log(
				sprintf(
					__( 'Could not find a user for email : %s', 'membership-stripe' ),
					$email
				)
			);

			return;
		}

		// Get Member.
		$member = MS_Factory::load(
			'MS_Model_Member',
			$user->ID
		);

		// Set the gateway profile.
		if ( $member instanceof MS_Model_Member ) {
			$member->set_gateway_profile( self::ID, 'customer_id', $customer_id );
			$member->save();
		} else {
			$this->log(
				sprintf(
					__( 'Could not find a member for Stripe customer : %s', 'membership-stripe' ),
					$customer_id
				)
			);
		}
	}

	/**
	 * Process the Stripe cancellation web hook request.
	 *
	 * When a subscription is cancelled in Stripe, we need to cancel
	 * it in the Membership subscription also.
	 * This will work only when relation id is found in Stripe subscription
	 * meta data.
	 *
	 * @param string $subscription_id Stripe subscription ID.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function process_invoice_creation( $subscription_id ) {
		// Get the Stripe subscription.
		$subscription = $this->_api->retrieve_subscription( $subscription_id );

		// Try if we can get the relationship id from subscription.
		if ( isset( $subscription->metadata['ms_relationship_id'] ) ) {
			$relationship = MS_Factory::load(
				'MS_Model_Relationship',
				$subscription->metadata['ms_relationship_id']
			);

			// Do not process system memberships.
			if ( ! $relationship instanceof MS_Model_Relationship || $relationship->is_system() ) {
				return;
			}

			// Get the membership.
			$membership = $relationship->get_membership();

			// Process if trial was availed.
			if ( $membership instanceof MS_Model_Membership && $membership->has_trial() ) {
				if ( $relationship->status == MS_Model_Relationship::STATUS_TRIAL_EXPIRED
				     && MS_Model_Addon::is_enabled( MS_Model_Addon::ADDON_TRIAL )
				) {
					$relationship->status = MS_Model_Relationship::STATUS_PENDING;
					$relationship->save();
				}
			}
		} else {
			$this->log(
				sprintf(
					__( 'Could not find the M2 subscription for Stripe subscription : %s', 'membership-stripe' ),
					$subscription_id
				)
			);
		}
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

		// Update the property if exist.
		if ( property_exists( $this, $key ) ) {
			$this->$key = $value;
		}
	}

	/**
	 * Valid Stripe events to check.
	 *
	 * We need to process only these events. This is used to
	 * avoid the server load.
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
			'customer.created',
		];

		return in_array( $event, $valid_events );
	}
}