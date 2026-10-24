<?php
/**
 * MultiCurrencyAsyncPriceRendererController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\Jetpack\Constants;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;

/**
 * Registers native async price renderer hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyAsyncPriceRendererController implements RegisterHooksInterface {

	private const PRICE_TYPE_FILTER   = 'wcpay_multi_currency_async_price_type';
	public const SCRIPT_HANDLE        = 'wc-multi-currency-async-renderer';
	public const LEGACY_SCRIPT_HANDLE = 'wcpay-multi-currency-async-renderer';
	private const SCRIPT_PATH         = 'assets/js/frontend/multi-currency-async-renderer';
	private const STYLE_PATH          = 'assets/css/multi-currency-async-renderer.css';
	private const LOCALIZED_OBJECT    = 'wcpayAsyncPriceConfig';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var MultiCurrencyServiceFactory
	 */
	private MultiCurrencyServiceFactory $service_factory;

	/**
	 * Frontend projection service.
	 *
	 * @var MultiCurrencyFrontendProjectionService|null
	 */
	private ?MultiCurrencyFrontendProjectionService $frontend_projection_service = null;

	/**
	 * Request context.
	 *
	 * @var MultiCurrencyRequestContext
	 */
	private MultiCurrencyRequestContext $request_context;

	/**
	 * Active session resolver.
	 *
	 * @var callable|null
	 */
	private $active_session_resolver = null;

	/**
	 * Asset URL resolver.
	 *
	 * @var callable|null
	 */
	private $asset_url_resolver = null;

	/**
	 * Asset version resolver.
	 *
	 * @var callable|null
	 */
	private $asset_version_resolver = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param MultiCurrencyServiceFactory $service_factory Multi-Currency service factory.
	 * @param MultiCurrencyRequestContext $request_context Request context.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		MultiCurrencyServiceFactory $service_factory,
		MultiCurrencyRequestContext $request_context
	): void {
		$this->arbiter         = $arbiter;
		$this->service_factory = $service_factory;
		$this->request_context = $request_context;
	}

	/**
	 * Set the frontend projection service.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyFrontendProjectionService $frontend_projection_service Frontend projection service.
	 */
	public function set_frontend_projection_service( MultiCurrencyFrontendProjectionService $frontend_projection_service ): void {
		$this->frontend_projection_service = $frontend_projection_service;
	}

	/**
	 * Set the request context.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyRequestContext $request_context Request context.
	 */
	public function set_request_context( MultiCurrencyRequestContext $request_context ): void {
		$this->request_context = $request_context;
	}

	/**
	 * Set the active session resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $active_session_resolver Active session resolver.
	 */
	public function set_active_session_resolver( callable $active_session_resolver ): void {
		$this->active_session_resolver = $active_session_resolver;
	}

	/**
	 * Set the asset URL resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $asset_url_resolver Asset URL resolver.
	 */
	public function set_asset_url_resolver( callable $asset_url_resolver ): void {
		$this->asset_url_resolver = $asset_url_resolver;
	}

	/**
	 * Set the asset version resolver.
	 *
	 * @internal Used by tests.
	 *
	 * @param callable $asset_version_resolver Asset version resolver.
	 */
	public function set_asset_version_resolver( callable $asset_version_resolver ): void {
		$this->asset_version_resolver = $asset_version_resolver;
	}

	/**
	 * Register async price renderer hooks.
	 *
	 * Registration is deferred to `woocommerce_init` rather than decided here.
	 * `register()` runs from `WooCommerce::init_hooks()`, inside
	 * `WooCommerce::__construct()`, where two things are true: the singleton is
	 * not yet assigned, so reaching `WC()` re-enters the constructor and
	 * recurses without bound; and no session exists yet, so the session probe
	 * this decision depends on could not answer correctly even if it were safe
	 * to call. By `woocommerce_init` the singleton is assigned and
	 * `wc_load_cart()` has initialized the session, while every hook registered
	 * below still fires long afterwards.
	 */
	public function register() {
		add_action( 'woocommerce_init', array( $this, 'maybe_register_async_hooks' ) );
	}

	/**
	 * Register the async renderer hooks if this request should use them.
	 *
	 * Hooked to `woocommerce_init` by {@see register()}; not intended to be
	 * called during plugin bootstrap.
	 *
	 * @internal
	 */
	public function maybe_register_async_hooks(): void {
		$request_context = $this->request_context;
		if (
			! $this->arbiter->should_core_register()
			|| ! $request_context->should_register_frontend_hooks()
			|| $request_context->is_store_api_request()
			|| ! $this->is_using_auto_currency_switching()
			|| $this->has_pending_currency_switch()
		) {
			return;
		}

		if (
			! $this->get_frontend_projection_service()->is_cache_optimized_mode()
			|| is_admin()
			|| wp_doing_cron()
			|| $request_context->is_admin_api_request()
			|| $this->has_active_session()
		) {
			return;
		}

		add_filter( 'wc_price', array( $this, 'handle_wc_price' ), 999, 5 );
		add_filter( 'woocommerce_format_sale_price', array( $this, 'handle_woocommerce_format_sale_price' ), 999, 3 );
		add_filter( 'woocommerce_format_price_range', array( $this, 'handle_woocommerce_format_price_range' ), 999, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'handle_wp_enqueue_scripts' ) );
	}

	/**
	 * Wrap formatted prices with async renderer skeleton markup.
	 *
	 * @internal
	 *
	 * @param string              $price_html        Formatted price HTML.
	 * @param int|float|string    $price             Raw price.
	 * @param array<string,mixed> $args           Arguments passed to wc_price().
	 * @param int|float|string    $unformatted_price Raw unformatted price.
	 * @param int|float|string    $original_price    Original price before filtering.
	 * @return string
	 */
	public function handle_wc_price( string $price_html, $price, array $args, $unformatted_price, $original_price ): string {
		unset( $original_price );

		/**
		 * Filters the native async multi-currency price type.
		 *
		 * @param string           $price_type Price type.
		 * @param int|float|string $price      Raw price.
		 * @param array<string,mixed> $args    Arguments passed to wc_price().
		 *
		 * @since 11.0.0
		 */
		$price_type = apply_filters( self::PRICE_TYPE_FILTER, 'product', $price, $args );
		$price_type = is_string( $price_type ) ? $price_type : 'product';

		return sprintf(
			'<span class="woocommerce-Price-amount amount wcpay-async-price" data-wcpay-price="%s" data-wcpay-price-type="%s"><bdi class="wcpay-price-skeleton"></bdi><span class="screen-reader-text wcpay-price-placeholder">%s</span></span>',
			$this->escape_price_attribute( $unformatted_price ),
			esc_attr( $price_type ),
			wp_kses_post( $price_html )
		);
	}

	/**
	 * Annotate sale price screen-reader text for async conversion.
	 *
	 * @internal
	 *
	 * @param string           $price_html    Sale price HTML.
	 * @param int|float|string $regular_price Regular price.
	 * @param int|float|string $sale_price    Sale price.
	 * @return string
	 */
	public function handle_woocommerce_format_sale_price( string $price_html, $regular_price, $sale_price ): string {
		if ( ! is_numeric( $regular_price ) || ! is_numeric( $sale_price ) ) {
			return $price_html;
		}

		$count  = 0;
		$result = preg_replace_callback(
			'/<span class="screen-reader-text">/',
			function () use ( $regular_price, $sale_price, &$count ): string {
				++$count;

				if ( 1 === $count ) {
					return sprintf(
						'<span class="screen-reader-text" data-wcpay-sr-type="sale_original" data-wcpay-sr-price="%s">',
						$this->escape_price_attribute( $regular_price )
					);
				}

				return sprintf(
					'<span class="screen-reader-text" data-wcpay-sr-type="sale_current" data-wcpay-sr-price="%s">',
					$this->escape_price_attribute( $sale_price )
				);
			},
			$price_html,
			2
		);

		return is_string( $result ) ? $result : $price_html;
	}

	/**
	 * Annotate price range screen-reader text for async conversion.
	 *
	 * @internal
	 *
	 * @param string           $price_html Price range HTML.
	 * @param int|float|string $from       Minimum price.
	 * @param int|float|string $to         Maximum price.
	 * @return string
	 */
	public function handle_woocommerce_format_price_range( string $price_html, $from, $to ): string {
		if ( ! is_numeric( $from ) || ! is_numeric( $to ) ) {
			return $price_html;
		}

		$result = preg_replace(
			'/<span class="screen-reader-text">/',
			sprintf(
				'<span class="screen-reader-text" data-wcpay-sr-type="range" data-wcpay-sr-price-from="%s" data-wcpay-sr-price-to="%s">',
				$this->escape_price_attribute( $from ),
				$this->escape_price_attribute( $to )
			),
			$price_html,
			1
		);

		return is_string( $result ) ? $result : $price_html;
	}

	/**
	 * Enqueue async renderer assets and localized config.
	 *
	 * @internal
	 */
	public function handle_wp_enqueue_scripts(): void {
		$script_version = $this->get_asset_version( self::SCRIPT_PATH . '.js' );
		$style_version  = $this->get_asset_version( self::STYLE_PATH );

		wp_register_script(
			self::SCRIPT_HANDLE,
			$this->get_asset_url( self::SCRIPT_PATH . $this->get_script_suffix() . '.js' ),
			array( 'wp-polyfill' ),
			$script_version,
			array( 'in_footer' => true )
		);
		wp_register_script( self::LEGACY_SCRIPT_HANDLE, false, array( self::SCRIPT_HANDLE ), $script_version, array( 'in_footer' => true ) );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			self::LOCALIZED_OBJECT,
			array(
				'apiUrl'          => rest_url( 'wc/v3/payments/multi-currency/public/config' ),
				'defaultCurrency' => array(
					'symbol'       => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
					'decimals'     => wc_get_price_decimals(),
					'decimal_sep'  => wc_get_price_decimal_separator(),
					'thousand_sep' => wc_get_price_thousand_separator(),
					'symbol_pos'   => (string) get_option( 'woocommerce_currency_pos', 'left' ),
				),
				'srText'          => array(
					/* translators: %s: formatted price. */
					'sale_original' => __( 'Original price was: %s.', 'woocommerce' ),
					/* translators: %s: formatted price. */
					'sale_current'  => __( 'Current price is: %s.', 'woocommerce' ),
					/* translators: %1$s: minimum price, %2$s: maximum price. */
					'range'         => __( 'Price range: %1$s through %2$s', 'woocommerce' ),
				),
			)
		);
		wp_enqueue_script( self::SCRIPT_HANDLE );

		wp_register_style( self::SCRIPT_HANDLE, $this->get_asset_url( self::STYLE_PATH ), array(), $style_version );
		wp_register_style( self::LEGACY_SCRIPT_HANDLE, false, array( self::SCRIPT_HANDLE ), $style_version );
		wp_enqueue_style( self::SCRIPT_HANDLE );
	}

	/**
	 * Escape a raw price for an HTML attribute, with a dot decimal separator.
	 *
	 * @param int|float|string $price Raw price.
	 * @return string
	 */
	private function escape_price_attribute( $price ): string {
		return esc_attr( is_float( $price ) ? wc_float_to_string( $price ) : (string) $price );
	}

	/**
	 * Get the frontend projection service.
	 *
	 * @return MultiCurrencyFrontendProjectionService
	 */
	private function get_frontend_projection_service(): MultiCurrencyFrontendProjectionService {
		if ( null === $this->frontend_projection_service ) {
			$this->frontend_projection_service = $this->service_factory->create_frontend_projection_service();
		}

		return $this->frontend_projection_service;
	}

	/**
	 * Tell whether a cookie-backed WooCommerce session is already active.
	 *
	 * @return bool
	 */
	private function has_active_session(): bool {
		if ( is_callable( $this->active_session_resolver ) ) {
			return (bool) call_user_func( $this->active_session_resolver );
		}

		$session = WC()->session;

		// The session class is swappable (woocommerce_session_handler), and the WC_Session base class has no has_session().
		return is_object( $session )
			&& method_exists( $session, 'has_session' )
			&& $session->has_session();
	}

	/**
	 * Tell whether automatic currency switching is enabled.
	 *
	 * @return bool
	 */
	private function is_using_auto_currency_switching(): bool {
		return 'yes' === get_option( MultiCurrencyStateBuilder::OPTION_PREFIX . '_enable_auto_currency', 'no' );
	}

	/**
	 * Tell whether this request is explicitly switching currencies: any `currency` query argument counts, an empty one
	 * included (client 11.1.0 `includes/multi-currency/MultiCurrency.php:357-360`).
	 *
	 * @return bool
	 */
	private function has_pending_currency_switch(): bool {
		return isset( $_GET['currency'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Get an asset URL.
	 *
	 * @param string $path Asset path relative to the plugin root.
	 * @return string
	 */
	private function get_asset_url( string $path ): string {
		if ( is_callable( $this->asset_url_resolver ) ) {
			return (string) call_user_func( $this->asset_url_resolver, $path );
		}

		return plugins_url( $path, WC_PLUGIN_FILE );
	}

	/**
	 * Get an asset version.
	 *
	 * @param string $path Asset path relative to the plugin root.
	 * @return string
	 */
	private function get_asset_version( string $path ): string {
		if ( is_callable( $this->asset_version_resolver ) ) {
			return (string) call_user_func( $this->asset_version_resolver, $path );
		}

		unset( $path );

		return Constants::get_constant( 'WC_VERSION' );
	}

	/**
	 * Get the script suffix for debug/non-debug builds.
	 *
	 * @return string
	 */
	private function get_script_suffix(): string {
		return Constants::is_true( 'SCRIPT_DEBUG' ) ? '' : '.min';
	}
}
