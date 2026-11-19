<?php
/**
 * Gift finder: age and budget form that redirects to a filtered shop.
 *
 * @package BookstoreNepal
 */

namespace BookstoreNepal;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [bsn_gift_finder] and its redirect handler.
 */
class GiftFinder {

	public const PARAM_AGE    = 'bsn_gift_age';
	public const PARAM_BUDGET = 'bsn_gift_budget';

	/**
	 * Allowed budgets: max price => label.
	 *
	 * @return array<int,string>
	 */
	private static function budgets(): array {
		return array(
			500  => __( 'Under NPR 500', 'bookstore-nepal' ),
			1000 => __( 'Under NPR 1,000', 'bookstore-nepal' ),
			2000 => __( 'Under NPR 2,000', 'bookstore-nepal' ),
		);
	}

	/**
	 * Hooks the shortcode and redirect.
	 */
	public static function register(): void {
		add_shortcode( 'bsn_gift_finder', array( self::class, 'render' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_redirect' ) );
	}

	/**
	 * Shop URL filtered by age group and optional maximum price.
	 *
	 * @param string   $age_slug  Age group term slug.
	 * @param int|null $max_price Maximum price, or null for any.
	 */
	public static function url( string $age_slug, ?int $max_price ): string {
		$shop = wc_get_page_permalink( 'shop' );
		if ( ! isset( Catalog::ATTRIBUTES['age-group'][1][ $age_slug ] ) ) {
			return $shop;
		}
		$args = array(
			'filter_age-group'     => $age_slug,
			'query_type_age-group' => 'or',
		);
		if ( null !== $max_price ) {
			$args['max_price'] = $max_price;
		}
		return add_query_arg( $args, $shop );
	}

	/**
	 * Destination for a submitted form, or null when the form was not submitted.
	 *
	 * @param array $query Request parameters (e.g. $_GET).
	 */
	public static function target_from_request( array $query ): ?string {
		if ( ! isset( $query[ self::PARAM_AGE ] ) || ! is_scalar( $query[ self::PARAM_AGE ] ) ) {
			return null;
		}
		$age     = (string) $query[ self::PARAM_AGE ];
		$budget  = isset( $query[ self::PARAM_BUDGET ] ) && is_scalar( $query[ self::PARAM_BUDGET ] ) ? (string) $query[ self::PARAM_BUDGET ] : '';
		$budgets = self::budgets();
		$max     = ( ctype_digit( $budget ) && isset( $budgets[ (int) $budget ] ) ) ? (int) $budget : null;
		return self::url( $age, $max );
	}

	/**
	 * Redirects a submitted form to the filtered shop.
	 */
	public static function maybe_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search form.
		$target = self::target_from_request( wp_unslash( $_GET ) );
		if ( null !== $target ) {
			wp_safe_redirect( $target );
			exit;
		}
	}

	/**
	 * Renders the form.
	 */
	public static function render(): string {
		$action = get_permalink() ? get_permalink() : home_url( '/' );
		ob_start();
		?>
		<form class="bsn-gift-finder" method="get" action="<?php echo esc_url( $action ); ?>">
			<p>
				<label for="bsn_gift_age"><?php esc_html_e( 'Child’s age group', 'bookstore-nepal' ); ?></label>
				<select id="bsn_gift_age" name="<?php echo esc_attr( self::PARAM_AGE ); ?>">
					<?php foreach ( Catalog::ATTRIBUTES['age-group'][1] as $slug => $name ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="bsn_gift_budget"><?php esc_html_e( 'Budget', 'bookstore-nepal' ); ?></label>
				<select id="bsn_gift_budget" name="<?php echo esc_attr( self::PARAM_BUDGET ); ?>">
					<?php foreach ( self::budgets() as $max => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $max ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
					<option value=""><?php esc_html_e( 'Any budget', 'bookstore-nepal' ); ?></option>
				</select>
			</p>
			<p><button type="submit"><?php esc_html_e( 'Show gift ideas', 'bookstore-nepal' ); ?></button></p>
		</form>
		<?php
		return (string) ob_get_clean();
	}
}
