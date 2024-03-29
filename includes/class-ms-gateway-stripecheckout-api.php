<?php

// Include composer autoload.
require_once M2STRIPE_DIR . '/vendor/autoload.php';

use Stripe\Stripe as StripeCheckout;
use Stripe\Plan as StripeCheckoutPlan;
use Stripe\Charge as StripeCheckoutCharge;
use Stripe\Webhook as StripeCheckoutWebhook;
use Stripe\Customer as StripeCheckoutCustomer;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Subscription as StripeCheckoutSubscription;
use Stripe\Exception\SignatureVerificationException;

/**
 * Class that handles API functionality of Stripe checkout.
 *
 * @link    https://github.com/Joel-James/membership-stripe
 * @since   1.0.0
 * @author  Joel James <me@joelsays.com>
 */
class MS_Gateway_StripeCheckout_Api extends MS_Model_Option {

	/**
	 * Gateway class unique ID.
	 *
	 * @since 1.0.0
	 */
	const ID = 'stripecheckout';

	/**
	 * Gateway singleton instance.
	 *
	 * @var   string $instance
	 *
	 * @since 1.0.0
	 */
	public static $instance;

	/**
	 * Holds a reference to the parent gateway (either stripe or stripecheckout)
	 *
	 * @var MS_Gateway_Stripe|MS_Gateway_StripeCheckout
	 *
	 * @since 1.0.0
	 */
	protected $_gateway = null;

	/**
	 * Sets the parent gateway of the API object.
	 *
	 * The parent gateway object is used to fetch the API keys.
	 *
	 * @param MS_Gateway $gateway The parent gateway.
	 *
	 * @since 1.0.0
	 */
	public function set_gateway( $gateway ) {
		$this->_gateway = $gateway;

		// Setup API key.
		StripeCheckout::setApiKey( $this->_gateway->get_secret_key() );

		// If we don't set this, Stripe will use latest version, which may break our implementation.
		StripeCheckout::setApiVersion( '2019-09-09' );

		// Setup plugin info.
		StripeCheckout::setAppInfo(
			'Membership 2 - Stripe Checkout',
			M2STRIPE_VERSION,
			site_url()
		);
	}

	/**
	 * Create and return a new checkout session.
	 *
	 * @param string $plan            Plan ID.
	 * @param string $subscription_id Subscription ID.
	 * @param int    $step            Step.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_session( $plan, $subscription_id, $step ) {
		try {
			// Get current member.
			$member = MS_Model_Member::get_current_member();

			// Get Stripe customer if found.
			$customer = $this->find_customer( $member );

			// Base items required.
			$return_args = [
				'step'               => $step,
				'gateway'            => $this->_gateway->id,
				'ms_relationship_id' => $subscription_id,
				'_wpnonce'           => wp_create_nonce(
					$this->_gateway->id . '_' . $subscription_id
				),
			];

			// Custom return urls.
			$return_args_success = array_merge( [ 'stripe-checkout-success' => 1 ], $return_args );
			$return_args_cancel  = array_merge( [ 'stripe-checkout-success' => 0 ], $return_args );

			$session_args = [
				'payment_method_types' => [ 'card' ],
				'customer_email'       => $member->email,
				'subscription_data'    => [
					'items'    => [
						[
							'plan' => $plan,
						],
					],
					'metadata' => [
						'ms_relationship_id' => $subscription_id,
					],
				],
				'success_url'          => site_url() . add_query_arg( $return_args_success ),
				'cancel_url'           => site_url() . add_query_arg( $return_args_cancel ),
			];

			// If Stripe custom already exist.
			if ( ! empty( $customer->id ) ) {
				$session_args['customer'] = $customer->id;
				unset( $session_args['customer_email'] );
			}

			// Create session.
			$session = StripeCheckoutSession::create( $session_args );

			// Get generated session id.
			$session = $session->id;
		} catch ( Exception $e ) {
			error_log( $e->getMessage() );
			$session = '';
		}

		return $session;
	}

	/**
	 * Get Member's Stripe Customer Object, creates a new customer if not found.
	 *
	 * @param MS_Model_Member $member The member.
	 * @param string          $token  The credit card token.
	 *
	 * @since 1.0.0
	 *
	 * @return StripeCheckoutCustomer $customer
	 */
	public function get_stripe_customer( $member, $token ) {
		$customer = $this->find_customer( $member );

		if ( empty( $customer ) ) {
			try {
				$customer = StripeCheckoutCustomer::create( [
					'card'  => $token,
					'email' => $member->email,
				] );
				$member->set_gateway_profile( self::ID, 'customer_id', $customer->id );
				$member->save();
			} catch ( Exception $e ) {
				$customer = false;
			}
		} else {
			$this->add_card( $member, $customer, $token );
		}

		return $customer;
	}

	/**
	 * Get Member's Stripe Customer Object.
	 *
	 * @param MS_Model_Member $member The member.
	 *
	 * @since  1.0.0
	 *
	 * @return StripeCheckoutCustomer|null $customer
	 */
	public function find_customer( $member ) {
		$customer_id = $member->get_gateway_profile( self::ID, 'customer_id' );
		$customer    = null;

		if ( ! empty( $customer_id ) ) {
			try {
				$customer = StripeCheckoutCustomer::retrieve( $customer_id );

				// Seems like the customer was manually deleted on Stripe website.
				if ( isset( $customer->deleted ) && $customer->deleted ) {
					$customer = null;
					$member->set_gateway_profile( self::ID, 'customer_id', '' );
				}
			} catch ( Exception $e ) {
				// Failed.
				$customer = null;
			}
		}

		return $customer;
	}

	/**
	 * Add card info to Stripe customer profile and to WordPress user meta.
	 *
	 * @param MS_Model_Member        $member   The member model.
	 * @param StripeCheckoutCustomer $customer The stripe customer object.
	 * @param string                 $token    The stripe card token generated by the gateway.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function add_card( $member, $customer, $token ) {
		$card = false;

		try {
			// Stripe API since 2015-02-18
			if ( ! empty( $customer->sources ) ) {
				$card                     = $customer->sources->create( array( 'card' => $token ) );
				$customer->default_source = $card->id;
			}

			if ( $card ) {
				$customer->save();
			}

			/**
			 * This action is used by the Taxamo Add-on to check additional country
			 * evidence (CC country).
			 *
			 * @since  1.0.0
			 */
			do_action( 'ms_gateway_stripe_credit_card_saved', $card, $member, $this );

			// 2. Save card to WordPress user meta.

			if ( $card ) {
				$member->set_gateway_profile(
					self::ID,
					'card_exp',
					gmdate( 'Y-m-d', strtotime( "{$card->exp_year}-{$card->exp_month}-01" ) )
				);
				$member->set_gateway_profile( self::ID, 'card_num', $card->last4 );
				$member->save();
			}
		} catch ( Exception $e ) {
			// Failed.
		}
	}

	/**
	 * Creates a one-time charge that is immediately captured.
	 *
	 * This means the money is instantly transferred to our own stripe account.
	 *
	 * @param StripeCheckoutCustomer $customer    Stripe customer to charge.
	 * @param float                  $amount      Amount in currency (i.e. in USD, not in cents)
	 * @param string                 $currency    3-digit currency code.
	 * @param string                 $description This is displayed on the invoice to customer.
	 *
	 * @since 1.0.0
	 *
	 * @return StripeCheckoutCharge The resulting charge object.
	 */
	public function charge( $customer, $amount, $currency, $description ) {
		try {
			$charge = StripeCheckoutCharge::create( [
				'customer'    => $customer->id,
				'amount'      => intval( strval( $amount * 100 ) ), // Amount in cents!
				'currency'    => strtolower( $currency ),
				'description' => $description,
			] );
		} catch ( Exception $e ) {
			$charge = null;
		}

		return $charge;
	}

	/**
	 * Fetches an existing subscription from Stripe using ID.
	 *
	 * @param string $subscription_id Subscription ID.
	 *
	 * @since 1.0.0
	 *
	 * @return StripeCheckoutSubscription|false The resulting subscription object.
	 */
	public function retrieve_subscription( $subscription_id ) {
		// First check cache.
		$subscription = wp_cache_get( 'stripe_subscription_' . $subscription_id, 'membership-stripe' );

		// Get from API.
		if ( empty( $subscription ) ) {
			try {
				$subscription = StripeCheckoutSubscription::retrieve( $subscription_id );
			} catch ( Exception $e ) {
				$subscription = false;
			}
		}

		return $subscription;
	}

	/**
	 * Fetches an existing subscription from Stripe and returns it.
	 *
	 * If the specified customer did not subscribe to the membership then
	 * boolean FALSE will be returned.
	 *
	 * @param StripeCheckoutCustomer $customer   Stripe customer to charge.
	 * @param MS_Model_Membership    $membership The membership.
	 *
	 * @since 1.0.0
	 *
	 * @return Stripe_Subscription|false The resulting charge object.
	 */
	public function get_subscription( $customer, $membership ) {
		$plan_id = MS_Gateway_StripeCheckout::get_the_id(
			$membership->id,
			'plan'
		);

		/*
		 * Check all subscriptions of the customer and find the subscription
		 * for the specified membership.
		 */
		$last_checked = false;
		$has_more     = false;
		$subscription = false;

		do {
			$args = array();
			if ( $last_checked ) {
				$args['starting_after'] = $last_checked;
			}
			$active_subs = $customer->subscriptions->all( $args );
			$has_more    = $active_subs->has_more;

			foreach ( $active_subs->data as $sub ) {
				if ( $sub->plan->id == $plan_id ) {
					$subscription = $sub;
					$has_more     = false;
					break 2;
				}
				$last_checked = $sub->id;
			}
		} while ( $has_more );

		return $subscription;
	}

	/**
	 * Get subscription data.
	 *
	 * @since 1.0.0
	 *
	 * @return StripeCheckoutSubscription $subscription
	 */
	public function get_subscription_data( $subscription_data, $membership ) {
		$plan_id = MS_Gateway_StripeCheckout::get_the_id(
			$membership->id,
			'plan'
		);

		$subscription = false;

		foreach ( $subscription_data as $sub ) {
			if ( $sub->plan->id == $plan_id ) {
				$subscription = $sub;
			}
		}

		return $subscription;
	}

	/**
	 * Creates a subscription that starts immediately.
	 *
	 * @param StripeCheckoutCustomer $customer Stripe customer to charge.
	 * @param MS_Model_Invoice       $invoice  The relevant invoice.
	 *
	 * @since 1.0.0
	 *
	 * @return StripeCheckoutSubscription The resulting charge object.
	 */
	public function subscribe( $customer, $invoice ) {
		$membership = $invoice->get_membership();
		$plan_id    = MS_Gateway_StripeCheckout::get_the_id(
			$membership->id,
			'plan'
		);

		$subscription = $this->get_subscription( $customer, $membership );

		// We don't need cancelled subscriptions.
		if ( isset( $subscription->cancel_at_period_end ) && $subscription->cancel_at_period_end == true ) {
			try {
				// Cancel the subscription immediately.
				$subscription->cancel();
			} catch ( Exception $e ) {
				// Well, failed to cancel.
			}

			// No subscription.
			$subscription = false;
		}

		/*
		 * If no active subscription was found for the membership create it.
		 */
		if ( ! $subscription ) {
			$tax_percent = null;
			$coupon_id   = null;

			if ( is_numeric( $invoice->tax_rate ) && $invoice->tax_rate > 0 ) {
				$tax_percent = floatval( $invoice->tax_rate );
			}
			if ( $invoice->coupon_id ) {
				$coupon_id = MS_Gateway_StripeCheckout::get_the_id(
					$invoice->coupon_id,
					'coupon'
				);
			}

			$args         = array(
				'plan'        => $plan_id,
				'tax_percent' => $tax_percent,
				'coupon'      => $coupon_id,
			);
			$subscription = $customer->subscriptions->create( $args );
		}

		return $subscription;
	}

	/**
	 * Creates or updates the payment plan specified by the function parameter.
	 *
	 * @param array $plan_data The plan-object containing all details for Stripe.
	 *
	 * @since 1.0.0
	 */
	public function create_or_update_plan( $plan_data ) {
		$item_id   = $plan_data['id'];
		$all_items = MS_Factory::get_transient( 'ms_stripecheckout_plans' );
		$all_items = mslib3()->array->get( $all_items );

		if ( ! isset( $all_items[ $item_id ] )
		     || ! is_a( $all_items[ $item_id ], 'StripeCheckoutPlan' )
		) {
			try {
				$item = StripeCheckoutPlan::retrieve( $item_id );
			} catch ( Exception $e ) {
				// If the plan does not exist then stripe will throw an Exception.
				$item = false;
			}
			$all_items[ $item_id ] = $item;
		} else {
			$item = $all_items[ $item_id ];
		}

		/*
		 * Stripe can only update the plan-name, so we have to delete and
		 * recreate the plan manually.
		 */
		if ( $item && is_a( $item, 'StripeCheckoutPlan' ) ) {
			$item->delete();
			$all_items[ $item_id ] = false;
		}

		if ( $plan_data['amount'] > 0 ) {
			try {
				$item                  = StripeCheckoutPlan::create( $plan_data );
				$all_items[ $item_id ] = $item;
			} catch ( Exception $e ) {
				// Nothing.
			}
		}

		MS_Factory::set_transient(
			'ms_stripecheckout_plans',
			$all_items,
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Get the event object from the webhook payload.
	 *
	 * @param string $payload    Webhook data.
	 * @param string $sig_header Webhook signature.
	 *
	 * @since 1.0.0
	 *
	 * @return \Stripe\Event|void
	 */
	public function get_webhook_event( $payload, $sig_header ) {
		try {
			// Construct the event data after verification.
			$event = StripeCheckoutWebhook::constructEvent(
				$payload, $sig_header, $this->_gateway->get_signing_secret()
			);

			return $event;
		} catch ( UnexpectedValueException $e ) {
			// Invalid payload.
			http_response_code( 400 );
			exit();
		} catch ( SignatureVerificationException $e ) {
			// Invalid signature.
			http_response_code( 400 );
			exit();
		}
	}
}