<?php

/**
 * Class that handles Stripe Checkout gateway.
 *
 * @link    https://github.com/Joel-James/membership-stripe
 * @since   1.0.0
 * @author  Joel James <me@joelsays.com>
 */
class MS_Gateway_Stripecheckout extends MS_Gateway {

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
	 * Stripe publishable key (live).
	 *
	 * @var string $publishable_key
	 *
	 * @since  1.0.0
	 */
	protected $publishable_key = '';

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
		$this->_api = MS_Factory::load( 'MS_Gateway_Stripecheckout_Api' );

		// If the gateway is initialized for the first time then copy settings.
		if ( false === $this->test_secret_key ) {
			$single                     = MS_Factory::load( 'MS_Gateway_Stripe' );
			$this->test_secret_key      = $single->test_secret_key;
			$this->secret_key           = $single->secret_key;
			$this->test_publishable_key = $single->test_publishable_key;
			$this->publishable_key      = $single->publishable_key;
			$this->save();
		}

		$this->id                        = self::ID;
		$this->name                      = __( 'Stripe Checkout 2.0 Gateway', 'membership-stripe' );
		$this->group                     = 'Stripe Checkout';
		$this->manual_payment            = false; // Recurring charged automatically.
		$this->pro_rate                  = true;
		$this->unsupported_payment_types = array(
			MS_Model_Membership::PAYMENT_TYPE_PERMANENT,
			MS_Model_Membership::PAYMENT_TYPE_FINITE,
			MS_Model_Membership::PAYMENT_TYPE_DATE_RANGE,
		);

		// Update all payment plans and coupons.
		$this->add_action(
			'ms_gateway_toggle_stripeplan',
			'update_stripe_data'
		);

		// Update a single payment plan.
		$this->add_action(
			'ms_saved_MS_Model_Membership',
			'update_stripe_data_membership'
		);

		// Update a single coupon.
		$this->add_action(
			'ms_saved_MS_Addon_Coupon_Model',
			'update_stripe_data_coupon'
		);

		//Delete Coupon
		$this->add_action(
			'ms_deleted_MS_Addon_Coupon_Model',
			'delete_stripe_coupon', 10, 3
		);
	}

	/**
	 * Get Stripe publishable key.
	 *
	 * @since  1.0.0
	 * @return string The Stripe API publishable key.
	 * @api
	 *
	 */
	public function get_publishable_key() {
		$publishable_key = null;

		if ( $this->is_live_mode() ) {
			$publishable_key = $this->publishable_key;
		} else {
			$publishable_key = $this->test_publishable_key;
		}

		return $publishable_key;
	}

	/**
	 * Get Stripe secret key.
	 *
	 * @since    1.0.0
	 * @return string The Stripe API secret key.
	 * @internal The secret key should not be used outside this object!
	 *
	 */
	public function get_secret_key() {
		$secret_key = null;

		if ( $this->is_live_mode() ) {
			$secret_key = $this->secret_key;
		} else {
			$secret_key = $this->test_secret_key;
		}

		return $secret_key;
	}

	/**
	 * Get Stripe Vendor Logo.
	 *
	 * @since  1.0.3.4
	 * @return string The Stripe Vendor Logo.
	 * @api
	 *
	 */

	public function get_vendor_logo() {
		return $this->vendor_logo;
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

		$is_configured = ! ( empty( $key_pub ) || empty( $key_sec ) );

		return $is_configured;
	}

	/**
	 * Checks all Memberships and creates/updates the payment plan on stripe if
	 * the membership changed since the plan was last changed.
	 *
	 * This function is called when the gateway is activated and after a
	 * membership was saved to database.
	 *
	 * @since  1.0.0
	 */
	public function update_stripe_data() {
		if ( ! $this->active ) {
			return false;
		}

		$this->_api->set_gateway( $this );

		// 1. Update all playment plans.
		$memberships = MS_Model_Membership::get_memberships();
		foreach ( $memberships as $membership ) {
			$this->update_stripe_data_membership( $membership );
		}

		// 2. Update all coupons (if Add-on is enabled)
		if ( MS_Addon_Coupon::is_active() ) {
			$coupons = MS_Addon_Coupon_Model::get_coupons();
			foreach ( $coupons as $coupon ) {
				$this->update_stripe_data_coupon( $coupon );
			}
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

		$plan_data = array(
			'id'     => self::get_the_id( $membership->id, 'plan' ),
			'amount' => 0,
		);

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
			$plan_data['product']           = array(
				"name" => $membership->name,
			);
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
	 * Creates or updates a single coupon on Stripe.
	 *
	 * This function is called when the gateway is activated and after a
	 * coupon was saved to database.
	 *
	 * @since  1.0.0
	 */
	public function update_stripe_data_coupon( $coupon ) {
		if ( ! $this->active ) {
			return false;
		}
		$this->_api->set_gateway( $this );

		$settings    = MS_Plugin::instance()->settings;
		$duration    = MS_Addon_Coupon_Model::DURATION_ONCE === $coupon->duration ? 'once' : 'forever';
		$percent_off = null;
		$amount_off  = null;

		if ( MS_Addon_Coupon_Model::TYPE_VALUE == $coupon->discount_type ) {
			$amount_off = abs( intval( strval( $coupon->discount * 100 ) ) );
		} else {
			$percent_off = $coupon->discount;
		}

		$coupon_data = apply_filters( 'ms_gateway_stripe_coupon_data', array(
			'id'          => self::get_the_id( $coupon->id, 'coupon' ),
			'duration'    => $duration,
			'amount_off'  => $amount_off,
			'percent_off' => $percent_off,
			'currency'    => $settings->currency,
		), $coupon, $settings );

		// Check if the plan needs to be updated.
		$serialized_data = json_encode( $coupon_data );
		$temp_key        = substr( 'ms-stripe-' . $coupon_data['id'], 0, 45 );
		$temp_data       = MS_Factory::get_transient( $temp_key );

		if ( $temp_data != $serialized_data ) {
			MS_Factory::set_transient(
				$temp_key,
				$serialized_data,
				HOUR_IN_SECONDS
			);

			$this->_api->create_or_update_coupon( $coupon_data );
		}
	}

	/**
	 * Action when coupon is deleted
	 *
	 * @param MS_Addon_Coupon_Model $coupon  - the current coupon
	 * @param bool                  $deleted - if it was deleted
	 * @param int                   $id      - the reference ID
	 *
	 * @since 1.1.5
	 */
	public function delete_stripe_coupon( $coupon, $deleted, $id ) {
		if ( ! $this->active ) {
			return false;
		}
		$this->_api->set_gateway( $this );
		$coupon_id = apply_filters(
			'ms_gateway_stripe_coupon_id',
			self::get_the_id( $id, 'coupon' ),
			$id,
			$coupon
		);

		$this->_api->delete_coupon( $coupon_id );
	}

	/**
	 * Auto-update some fields of the _api instance if required.
	 *
	 * @param string $key   Field name.
	 * @param mixed  $value Field value.
	 *
	 * @since  1.0.0
	 * @internal
	 *
	 */
	public function __set( $key, $value ) {
		switch ( $key ) {
			case 'test_secret_key':
			case 'test_publishable_key':
			case 'secret_key':
			case 'publishable_key':
				$this->_api->$key = $value;
				break;
		}

		if ( property_exists( $this, $key ) ) {
			$this->$key = $value;
		}
	}
}