<?php
/**
 * Nepal shipping zones and district checkout rules.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * Zone-based delivery rates and district validation.
 */
class Shipping {

	public const ZONE_VALLEY = 'Kathmandu Valley';
	public const ZONE_REST   = 'Rest of Nepal';

	/**
	 * Hooks runtime behaviour.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_states', array( self::class, 'states' ) );
		add_filter( 'woocommerce_get_country_locale', array( self::class, 'locale' ) );
		add_filter( 'woocommerce_package_rates', array( self::class, 'hide_paid_rates_when_free' ) );
		add_action( 'woocommerce_after_checkout_validation', array( self::class, 'validate_checkout' ), 10, 2 );
	}

	/**
	 * When free delivery applies, hides the paid flat-rate delivery so customers are not shown (or defaulted to) it.
	 * Every other method, such as local pickup, stays available.
	 *
	 * @param array $rates Rate id => WC_Shipping_Rate.
	 * @return array
	 */
	public static function hide_paid_rates_when_free( $rates ) {
		if ( ! is_array( $rates ) ) {
			return $rates;
		}
		$has_free = false;
		foreach ( $rates as $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate && 'free_shipping' === $rate->get_method_id() ) {
				$has_free = true;
				break;
			}
		}
		if ( ! $has_free ) {
			return $rates;
		}
		return array_filter(
			$rates,
			static fn( $rate ) => ! ( $rate instanceof \WC_Shipping_Rate && 'flat_rate' === $rate->get_method_id() )
		);
	}

	/**
	 * Replaces Nepal's states with districts.
	 *
	 * @param array $states Country => states.
	 * @return array
	 */
	public static function states( $states ) {
		$states            = is_array( $states ) ? $states : array();
		$states['NP']      = Districts::all();
		return $states;
	}

	/**
	 * Labels Nepal's state field "District" and makes it required.
	 *
	 * @param array $locale Country => field overrides.
	 * @return array
	 */
	public static function locale( $locale ) {
		$locale       = is_array( $locale ) ? $locale : array();
		$existing     = isset( $locale['NP']['state'] ) && is_array( $locale['NP']['state'] ) ? $locale['NP']['state'] : array();
		$locale['NP'] = ( $locale['NP'] ?? array() );
		$locale['NP']['state'] = array_merge(
			$existing,
			array(
				'label'    => __( 'District', 'bookstore-nepal' ),
				'required' => true,
				'hidden'   => false,
			)
		);
		return $locale;
	}

	/**
	 * Classic checkout: explicit message when the district is missing or unknown.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors.
	 */
	public static function validate_checkout( $data, $errors ): void {
		$groups = array( 'billing' );
		if ( ! empty( $data['ship_to_different_address'] ) ) {
			$groups[] = 'shipping';
		}
		$districts = Districts::all();
		foreach ( $groups as $group ) {
			if ( 'NP' !== ( $data[ $group . '_country' ] ?? '' ) ) {
				continue;
			}
			$key = $group . '_state';
			// WooCommerce already reported this field.
			if ( $errors->get_error_data( $key . '_required' ) || $errors->get_error_data( $key . '_validation' ) || $errors->get_error_message( $key . '_required' ) || $errors->get_error_message( $key . '_validation' ) ) {
				continue;
			}
			$state = (string) ( $data[ $key ] ?? '' );
			if ( ! isset( $districts[ $state ] ) ) {
				$errors->add(
					'bsn_district',
					__( 'Please choose your district from the list so we can calculate delivery.', 'bookstore-nepal' ),
					array( 'id' => $key )
				);
			}
		}
	}

	/**
	 * Creates or updates the two zones and their methods; safe to run repeatedly.
	 *
	 * @param array $rates Keys valley, rest, free_over (NPR).
	 * @return string[] Human-readable errors; empty array means success.
	 */
	public static function install( array $rates = array(
		'valley'    => 100,
		'rest'      => 200,
		'free_over' => 2000,
	) ): array {
		return self::apply( $rates, false );
	}

	/**
	 * Creates missing zones with placeholder rates and leaves existing zones alone.
	 * Any rate passed in $overrides (keys valley, rest, free_over) is applied, even to existing zones.
	 *
	 * @param array $overrides Optional rates to set (NPR).
	 * @return string[] Human-readable errors; empty array means success.
	 */
	public static function seed( array $overrides = array() ): array {
		return self::apply( $overrides, true );
	}

	/**
	 * Shared implementation of install() and seed().
	 *
	 * @param array $given Rates supplied by the caller.
	 * @param bool  $seed  True to keep existing zones and only apply given rates to them.
	 * @return string[]
	 */
	private static function apply( array $given, bool $seed ): array {
		if ( ! class_exists( '\WC_Shipping_Zones' ) || ! class_exists( '\WC_Shipping_Zone' ) ) {
			return self::report( array( __( 'WooCommerce is required to set up shipping.', 'bookstore-nepal' ) ) );
		}

		$rates  = array_merge(
			array(
				'valley'    => 100,
				'rest'      => 200,
				'free_over' => 2000,
			),
			$given
		);
		$errors = array();

		$valley_locations = array();
		foreach ( Districts::VALLEY as $code ) {
			$valley_locations[] = array( 'code' => 'NP:' . $code, 'type' => 'state' );
		}

		$zones = array(
			array( self::ZONE_VALLEY, 0, $valley_locations, $rates['valley'], 'valley' ),
			array( self::ZONE_REST, 1, array( array( 'code' => 'NP', 'type' => 'country' ) ), $rates['rest'], 'rest' ),
		);

		foreach ( $zones as list( $name, $order, $locations, $cost, $rate_key ) ) {
			$existing = self::find_zone( $name );
			$keep     = $seed && null !== $existing;
			$zone     = $existing ?? new \WC_Shipping_Zone( null );
			if ( ! $keep ) {
				$zone->set_zone_name( $name );
				$zone->set_zone_order( $order );
				$zone->clear_locations( array( 'state', 'country', 'continent', 'postcode' ) );
				foreach ( $locations as $location ) {
					$zone->add_location( $location['code'], $location['type'] );
				}
				$zone->save();
			}

			if ( ! $zone->get_id() ) {
				/* translators: %s: zone name. */
				$errors[] = sprintf( __( 'Could not save shipping zone %s.', 'bookstore-nepal' ), $name );
				continue;
			}

			$settings = array(
				'flat_rate'     => array( 'cost' => (string) $cost, 'title' => __( 'Delivery', 'bookstore-nepal' ) ),
				'free_shipping' => array(
					'requires'   => 'min_amount',
					'min_amount' => (string) $rates['free_over'],
					'title'      => __( 'Free delivery', 'bookstore-nepal' ),
				),
			);
			if ( $keep ) {
				// Existing zone: touch only the rates the caller explicitly gave.
				$settings = array();
				if ( isset( $given[ $rate_key ] ) ) {
					$settings['flat_rate'] = array( 'cost' => (string) $cost );
				}
				if ( isset( $given['free_over'] ) ) {
					$settings['free_shipping'] = array(
						'requires'   => 'min_amount',
						'min_amount' => (string) $rates['free_over'],
					);
				}
			}
			foreach ( $settings as $method_id => $values ) {
				$error = self::ensure_method( $zone, $method_id, $values );
				if ( $error ) {
					/* translators: 1: method id, 2: zone name, 3: error. */
					$errors[] = sprintf( __( 'Could not set up %1$s for %2$s: %3$s', 'bookstore-nepal' ), $method_id, $name, $error );
				}
			}
		}

		return self::report( $errors );
	}

	/**
	 * Finds a zone by exact name.
	 *
	 * @param string $name Zone name.
	 * @return \WC_Shipping_Zone|null
	 */
	private static function find_zone( string $name ): ?\WC_Shipping_Zone {
		foreach ( \WC_Shipping_Zones::get_zones() as $data ) {
			if ( $name === $data['zone_name'] ) {
				return new \WC_Shipping_Zone( $data['zone_id'] );
			}
		}
		return null;
	}

	/**
	 * Adds the method if missing, then applies the settings.
	 *
	 * @param \WC_Shipping_Zone $zone      Zone.
	 * @param string            $method_id Method id.
	 * @param array             $values    Settings to apply.
	 * @return string Error message, or empty string.
	 */
	private static function ensure_method( \WC_Shipping_Zone $zone, string $method_id, array $values ): string {
		$method = null;
		foreach ( $zone->get_shipping_methods() as $candidate ) {
			if ( $method_id === $candidate->id ) {
				$method = $candidate;
				break;
			}
		}
		if ( ! $method ) {
			$instance_id = $zone->add_shipping_method( $method_id );
			if ( ! $instance_id ) {
				return __( 'method could not be added', 'bookstore-nepal' );
			}
			foreach ( $zone->get_shipping_methods() as $candidate ) {
				if ( (int) $instance_id === (int) $candidate->get_instance_id() ) {
					$method = $candidate;
					break;
				}
			}
		}
		if ( ! $method ) {
			return __( 'method not found after adding', 'bookstore-nepal' );
		}
		$method->init_instance_settings();
		$method->instance_settings = array_merge( $method->instance_settings, $values );
		update_option(
			$method->get_instance_option_key(),
			apply_filters( 'woocommerce_shipping_' . $method->id . '_instance_settings_values', $method->instance_settings, $method ),
			'yes'
		);
		return '';
	}

	/**
	 * Logs errors and passes them through.
	 *
	 * @param string[] $errors Errors.
	 * @return string[]
	 */
	private static function report( array $errors ): array {
		foreach ( $errors as $error ) {
			error_log( 'bookstore-nepal: ' . $error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $errors;
	}
}
