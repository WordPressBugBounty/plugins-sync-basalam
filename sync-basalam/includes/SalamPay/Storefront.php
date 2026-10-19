<?php
/**
 * Storefront promotion UI for Salampay.
 *
 * @package Salampay_Plugin
 */

namespace SyncBasalam\SalamPay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a theme-independent promotion bar and product badges.
 */
final class Storefront {

	/**
	 * Cached gateway settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private $settings = null;

	/**
	 * Enqueues the small storefront stylesheet only when promotion is active.
	 *
	 * @return void
	 */
	public function enqueue_styles() {
		if ( ! $this->is_gateway_enabled() || ( ! $this->is_bar_enabled() && ! $this->is_badge_enabled() ) ) {
			return;
		}

		wp_enqueue_style(
			'sync-basalam-salampay-storefront',
			Module::assetUrl('storefront.css'),
			array(),
			\SyncBasalam\Plugin::VERSION
		);
	}

	/**
	 * Renders the promotion bar above the site header.
	 *
	 * @return void
	 */
	public function render_promotion_bar() {
		if ( ! $this->is_gateway_enabled() || ! $this->is_bar_enabled() ) {
			return;
		}

		$text = isset( $this->get_settings()['promotion_bar_text'] )
			? sanitize_text_field( $this->get_settings()['promotion_bar_text'] )
			: '';

		if ( '' === $text ) {
			$text = __( 'برای پرداخت سفارش می‌توانید سلام پی را انتخاب کنید.', 'sync-basalam' );
		}
		?>
		<aside class="salampay-promotion-bar" aria-label="<?php esc_attr_e( 'معرفی روش پرداخت سلام پی', 'sync-basalam' ); ?>">
			<div class="salampay-promotion-bar__inner">
				<span class="salampay-promotion-bar__mark" aria-hidden="true">
					<img src="<?php echo esc_url( $this->get_logo_url() ); ?>" alt="" width="78" height="28" decoding="async">
				</span>
				<span class="salampay-promotion-bar__divider" aria-hidden="true"></span>
				<span class="salampay-promotion-bar__text"><?php echo esc_html( $text ); ?></span>
			</div>
		</aside>
		<?php
	}

	/**
	 * Renders the compact badge on WooCommerce product cards.
	 *
	 * @return void
	 */
	public function render_loop_product_badge() {
		if ( ! $this->is_gateway_enabled() || ! $this->is_badge_enabled() ) {
			return;
		}

		$this->render_badge( 'loop' );
	}

	/**
	 * Renders the badge below the product title on single-product pages.
	 *
	 * @return void
	 */
	public function render_single_product_badge() {
		if ( ! $this->is_gateway_enabled() || ! $this->is_badge_enabled() ) {
			return;
		}

		$this->render_badge( 'single' );
	}

	/**
	 * Outputs accessible badge markup.
	 *
	 * @param string $context Badge placement context.
	 * @return void
	 */
	private function render_badge( $context ) {
		$classes = 'salampay-product-badge salampay-product-badge--' . sanitize_html_class( $context );
		?>
		<span class="<?php echo esc_attr( $classes ); ?>">
			<img src="<?php echo esc_url( $this->get_logo_url() ); ?>" alt="<?php esc_attr_e( 'سلام پی', 'sync-basalam' ); ?>" width="54" height="20" loading="lazy" decoding="async">
			<span><?php esc_html_e( 'پرداخت با سلام پی', 'sync-basalam' ); ?></span>
		</span>
		<?php
	}

	/**
	 * Gets gateway settings once per request.
	 *
	 * @return array<string, mixed>
	 */
	private function get_settings() {
		if ( null === $this->settings ) {
			$settings       = get_option( 'woocommerce_salampay_settings', array() );
			$this->settings = is_array( $settings ) ? $settings : array();
		}

		return $this->settings;
	}

	/**
	 * Whether the payment gateway itself is enabled.
	 *
	 * @return bool
	 */
	private function is_gateway_enabled() {
		return Settings::configured() && Money::supports(get_woocommerce_currency());
	}

	/**
	 * Whether the promotion bar is enabled (on by default after upgrade).
	 *
	 * @return bool
	 */
	private function is_bar_enabled() {
		return ! isset( $this->get_settings()['promotion_bar_enabled'] ) || 'yes' === $this->get_settings()['promotion_bar_enabled'];
	}

	/**
	 * Whether product badges are enabled (on by default after upgrade).
	 *
	 * @return bool
	 */
	private function is_badge_enabled() {
		return ! isset( $this->get_settings()['product_badge_enabled'] ) || 'yes' === $this->get_settings()['product_badge_enabled'];
	}

	/**
	 * Gets the bundled logo URL.
	 *
	 * @return string
	 */
	private function get_logo_url() {
		return Module::assetUrl('logo.svg');
	}
}
