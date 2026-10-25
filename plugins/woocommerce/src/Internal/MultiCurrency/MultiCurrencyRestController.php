<?php
/**
 * MultiCurrencyRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyDisplayService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySettingsCurrencyCatalog;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves the Multi-Currency REST routes under `wc/v3/payments/multi-currency`, including the public config the async
 * renderer reads.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencyRestController extends WP_REST_Controller implements RegisterHooksInterface {

	public const REST_NAMESPACE = 'wc/v3';
	public const REST_BASE      = 'payments/multi-currency';

	private const PUBLIC_CONFIG_CACHE_CONTROL = 'private, max-age=300';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Cache rendering service.
	 *
	 * @var MultiCurrencyCacheRenderingService
	 */
	private MultiCurrencyCacheRenderingService $cache_rendering_service;

	/**
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

	/**
	 * State builder factory.
	 *
	 * @var MultiCurrencyStateBuilderFactory
	 */
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

	/**
	 * Display service, built on first use.
	 *
	 * @var MultiCurrencyDisplayService|null
	 */
	private ?MultiCurrencyDisplayService $display_service = null;

	/**
	 * Settings currency catalog.
	 *
	 * @var MultiCurrencySettingsCurrencyCatalog|null
	 */
	private ?MultiCurrencySettingsCurrencyCatalog $settings_currency_catalog = null;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var MultiCurrencyServiceFactory
	 */
	private MultiCurrencyServiceFactory $service_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter             $arbiter                 Runtime owner arbiter.
	 * @param MultiCurrencyStateBuilderFactory        $state_builder_factory   State builder factory.
	 * @param MultiCurrencyServiceFactory             $service_factory         Multi-Currency service factory.
	 * @param MultiCurrencyCacheRenderingService|null $cache_rendering_service Cache rendering service.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyStateBuilderFactory $state_builder_factory, MultiCurrencyServiceFactory $service_factory, ?MultiCurrencyCacheRenderingService $cache_rendering_service = null ): void {
		$this->arbiter                 = $arbiter;
		$this->state_builder_factory   = $state_builder_factory;
		$this->service_factory         = $service_factory;
		$this->cache_rendering_service = $cache_rendering_service ?? wc_get_container()->get( MultiCurrencyCacheRenderingService::class );
		$this->namespace               = self::REST_NAMESPACE;
		$this->rest_base               = self::REST_BASE;
	}

	/**
	 * Register REST route hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'handle_rest_api_init' ) );
	}

	/**
	 * Register the Multi-Currency REST routes.
	 *
	 * @internal
	 */
	public function handle_rest_api_init(): void {
		$base  = '/' . self::REST_BASE;
		$admin = array( $this, 'check_permission' );

		// The async renderer reads the public config only in cache-optimized rendering mode.
		if ( $this->get_display_service()->is_cache_optimized_mode() ) {
			register_rest_route(
				self::REST_NAMESPACE,
				$base . '/public/config',
				array(
					'methods'             => array( 'GET' ),
					'callback'            => array( $this, 'get_public_config' ),
					'permission_callback' => '__return_true',
				)
			);
		}

		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/currencies',
			array(
				'methods'             => array( 'GET' ),
				'callback'            => array( $this, 'get_store_currencies' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/update-enabled-currencies',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => array( $this, 'update_enabled_currencies' ),
				'permission_callback' => $admin,
				'args'                => array(
					'enabled' => array(
						'type'     => 'array',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/currencies/(?P<currency_code>[A-Za-z]{3})',
			array(
				array(
					'methods'             => array( 'GET' ),
					'callback'            => array( $this, 'get_single_currency_settings' ),
					'permission_callback' => $admin,
					'args'                => array(
						'currency_code' => array(
							'type'     => 'string',
							'format'   => 'text-field',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => array( 'POST' ),
					'callback'            => array( $this, 'update_single_currency_settings' ),
					'permission_callback' => $admin,
					'args'                => array(
						'currency_code'      => array(
							'type'     => 'string',
							'format'   => 'text-field',
							'required' => true,
						),
						'exchange_rate_type' => array(
							'type'     => 'string',
							'format'   => 'text-field',
							'required' => true,
						),
						'manual_rate'        => array(
							'type'     => 'number',
							'required' => false,
						),
						'price_rounding'     => array(
							'type'     => 'number',
							'required' => true,
						),
						'price_charm'        => array(
							'type'     => 'number',
							'required' => true,
						),
					),
				),
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/get-settings',
			array(
				'methods'             => array( 'GET' ),
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => $admin,
			)
		);
		register_rest_route(
			self::REST_NAMESPACE,
			$base . '/update-settings',
			array(
				'methods'             => array( 'POST' ),
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => $admin,
				'args'                => $this->get_updatable_settings(),
			)
		);
	}

	/**
	 * Verify admin route access.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Get store currencies.
	 *
	 * @return WP_REST_Response
	 */
	public function get_store_currencies(): WP_REST_Response {
		return rest_ensure_response( $this->get_settings_currency_catalog()->get_store_currencies() );
	}

	/**
	 * Update enabled currencies.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_enabled_currencies( WP_REST_Request $request ) {
		$enabled = $request->get_param( 'enabled' );
		if ( ! is_array( $enabled ) || array() === $enabled ) {
			return $this->get_store_currencies();
		}

		$enabled_codes = $this->sanitize_currency_codes( $enabled );

		try {
			$this->validate_available_currency_codes( $enabled_codes, __FUNCTION__ );
		} catch ( InvalidCurrencyException $exception ) {
			return $this->get_error_response( $exception );
		}

		$previous_enabled_codes = $this->get_settings_currency_catalog()->get_configured_currency_codes();

		update_option( MultiCurrencyStateBuilder::ENABLED_CURRENCIES_OPTION, $enabled_codes );
		$this->remove_removed_currency_settings( $previous_enabled_codes, $enabled_codes );
		$this->get_state_builder()->reset();

		return $this->get_store_currencies();
	}

	/**
	 * Get a single currency settings response.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_single_currency_settings( WP_REST_Request $request ) {
		$currency_code = strtoupper( (string) $request->get_param( 'currency_code' ) );

		try {
			$this->validate_available_currency_codes( array( $currency_code ), __FUNCTION__ );
			return rest_ensure_response( $this->get_display_service()->get_single_currency_settings( $currency_code ) );
		} catch ( InvalidCurrencyException $exception ) {
			return $this->get_error_response( $exception );
		}
	}

	/**
	 * Update a single currency settings response.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_single_currency_settings( WP_REST_Request $request ) {
		$currency_code      = strtoupper( (string) $request->get_param( 'currency_code' ) );
		$exchange_rate_type = sanitize_text_field( (string) $request->get_param( 'exchange_rate_type' ) );
		$price_rounding     = (float) $request->get_param( 'price_rounding' );
		$price_charm        = (float) $request->get_param( 'price_charm' );
		$manual_rate        = $request->get_param( 'manual_rate' );

		try {
			$this->validate_available_currency_codes( array( $currency_code ), __FUNCTION__ );
			$error = $this->update_single_currency_options( $currency_code, $exchange_rate_type, $price_rounding, $price_charm, $manual_rate );
			if ( $error instanceof WP_Error ) {
				return $error;
			}
		} catch ( InvalidCurrencyException | InvalidCurrencyRateException $exception ) {
			return $this->get_error_response( $exception );
		}

		$this->get_state_builder()->reset();

		return $this->get_single_currency_settings( $request );
	}

	/**
	 * Get store-level settings.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return rest_ensure_response(
			array_merge(
				$this->get_display_service()->get_settings(),
				array(
					'should_recommend_cache_mode'    => $this->cache_rendering_service->should_recommend_cache_mode(),
					'cache_recommendation_dismissed' => $this->cache_rendering_service->is_cache_recommendation_dismissed(),
				)
			)
		);
	}

	/**
	 * Update store-level settings.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$params = $request->get_params();

		foreach ( array_keys( $this->get_updatable_settings() ) as $option_name ) {
			if ( ! isset( $params[ $option_name ] ) ) {
				continue;
			}

			if ( MultiCurrencyCacheRenderingService::DISMISSED_OPTION === $option_name ) {
				$value = $params[ $option_name ];
				if ( ! is_string( $value ) || ! in_array( $value, array( 'yes', 'no' ), true ) ) {
					continue;
				}

				update_option( $option_name, $value );
				continue;
			}

			$value = sanitize_text_field( (string) $params[ $option_name ] );

			if (
				MultiCurrencyDisplayService::RENDERING_MODE_OPTION === $option_name
				&& ! in_array( $value, array( MultiCurrencyDisplayService::RENDERING_MODE_SPEED, MultiCurrencyDisplayService::RENDERING_MODE_CACHE ), true )
			) {
				continue;
			}

			update_option( $option_name, $value );
		}

		return $this->get_settings();
	}

	/**
	 * Get public async renderer config.
	 *
	 * @return WP_REST_Response
	 */
	public function get_public_config(): WP_REST_Response {
		$response = rest_ensure_response( $this->get_display_service()->get_public_config() );
		$response->header( 'Cache-Control', self::PUBLIC_CONFIG_CACHE_CONTROL );

		return $response;
	}

	/**
	 * Save one currency's rate type, manual rate, rounding and charm options.
	 *
	 * @param string     $currency_code      Currency code.
	 * @param string     $exchange_rate_type Exchange rate type.
	 * @param float      $price_rounding     Price rounding setting.
	 * @param float      $price_charm        Price charm setting.
	 * @param mixed|null $manual_rate        Manual exchange rate.
	 * @throws InvalidCurrencyRateException When the manual rate is invalid.
	 * @return WP_Error|null
	 */
	private function update_single_currency_options(
		string $currency_code,
		string $exchange_rate_type,
		float $price_rounding,
		float $price_charm,
		$manual_rate
	) {
		$currency_id     = strtolower( $currency_code );
		$automatic_rates = $this->get_settings_currency_catalog()->get_automatic_rates_descriptor();

		if ( 'automatic' === $exchange_rate_type && null === $automatic_rates['source'] ) {
			return new WP_Error(
				'woocommerce_multi_currency_automatic_rates_unavailable',
				esc_html__( 'Automatic currency rates are unavailable because no rate provider is registered.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( 'manual' === $exchange_rate_type && null !== $manual_rate ) {
			if ( ! is_numeric( $manual_rate ) || 0 >= (float) $manual_rate ) {
				throw new InvalidCurrencyRateException( esc_html( 'Invalid manual currency rate passed to update_single_currency_settings: ' . (string) $manual_rate ), 500 );
			}

			update_option( 'wcpay_multi_currency_manual_rate_' . $currency_id, (float) $manual_rate );
		}

		update_option( 'wcpay_multi_currency_price_rounding_' . $currency_id, $price_rounding );
		update_option( 'wcpay_multi_currency_price_charm_' . $currency_id, $price_charm );

		if ( in_array( $exchange_rate_type, array( 'automatic', 'manual' ), true ) ) {
			update_option( 'wcpay_multi_currency_exchange_rate_' . $currency_id, $exchange_rate_type );
		}

		return null;
	}

	/**
	 * Validate currency codes against available currencies.
	 *
	 * @param string[] $currency_codes Currency codes.
	 * @param string   $method         Calling method.
	 * @throws InvalidCurrencyException When a code is unavailable.
	 */
	private function validate_available_currency_codes( array $currency_codes, string $method ): void {
		$invalid_codes = array_filter(
			$currency_codes,
			fn( string $currency_code ): bool => ! $this->get_settings_currency_catalog()->contains( $currency_code )
		);

		if ( array() === $invalid_codes ) {
			return;
		}

		throw new InvalidCurrencyException(
			esc_html( 'Invalid currency passed to ' . $method . ': ' . implode( ', ', $invalid_codes ) ),
			500
		);
	}

	/**
	 * Sanitize currency code input.
	 *
	 * @param array<mixed> $currency_codes Raw currency code input.
	 * @return string[]
	 */
	private function sanitize_currency_codes( array $currency_codes ): array {
		$sanitized = array();

		foreach ( $currency_codes as $currency_code ) {
			if ( ! is_scalar( $currency_code ) ) {
				continue;
			}

			$currency_code = strtoupper( sanitize_text_field( (string) $currency_code ) );
			if ( '' !== $currency_code ) {
				$sanitized[] = $currency_code;
			}
		}

		return array_values( array_unique( $sanitized ) );
	}

	/**
	 * Remove per-currency settings for currencies that are no longer enabled.
	 *
	 * @param string[] $previous_enabled_codes Previous enabled currency codes.
	 * @param string[] $enabled_codes          New enabled currency codes.
	 */
	private function remove_removed_currency_settings( array $previous_enabled_codes, array $enabled_codes ): void {
		$removed_codes = array_diff( $previous_enabled_codes, $enabled_codes );

		foreach ( $removed_codes as $currency_code ) {
			$currency_code = strtoupper( $currency_code );
			if ( 3 !== strlen( $currency_code ) || in_array( $currency_code, $enabled_codes, true ) ) {
				continue;
			}

			foreach ( array( 'price_charm', 'price_rounding', 'manual_rate', 'exchange_rate' ) as $setting ) {
				delete_option( MultiCurrencyStateBuilder::OPTION_PREFIX . '_' . $setting . '_' . strtolower( $currency_code ) );
			}
		}
	}

	/**
	 * Get the store settings the update-settings route writes, by option name, with each one's route argument schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_updatable_settings(): array {
		return array(
			MultiCurrencyStateBuilder::OPTION_PREFIX . '_enable_auto_currency' => array(
				'type'     => 'string',
				'format'   => 'text-field',
				'required' => true,
			),
			MultiCurrencyStateBuilder::OPTION_PREFIX . '_enable_storefront_switcher' => array(
				'type'     => 'string',
				'format'   => 'text-field',
				'required' => true,
			),
			MultiCurrencyDisplayService::RENDERING_MODE_OPTION => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( MultiCurrencyDisplayService::RENDERING_MODE_SPEED, MultiCurrencyDisplayService::RENDERING_MODE_CACHE ),
			),
			MultiCurrencyCacheRenderingService::DISMISSED_OPTION => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'yes', 'no' ),
			),
		);
	}

	/**
	 * Build a WP error response from an exception.
	 *
	 * @param \Throwable $exception Exception.
	 * @return WP_Error
	 */
	private function get_error_response( \Throwable $exception ): WP_Error {
		return new WP_Error(
			0 !== $exception->getCode() ? (string) $exception->getCode() : 'woocommerce_rest_multi_currency_error',
			$exception->getMessage()
		);
	}

	/**
	 * Get the state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = $this->state_builder_factory->get_state_builder();
		}

		return $this->state_builder;
	}

	/**
	 * Get the display service.
	 *
	 * @return MultiCurrencyDisplayService
	 */
	private function get_display_service(): MultiCurrencyDisplayService {
		if ( null === $this->display_service ) {
			$this->display_service = $this->service_factory->create_display_service();
		}

		return $this->display_service;
	}

	/**
	 * Get the settings currency catalog.
	 *
	 * @return MultiCurrencySettingsCurrencyCatalog
	 */
	private function get_settings_currency_catalog(): MultiCurrencySettingsCurrencyCatalog {
		if ( null === $this->settings_currency_catalog ) {
			$this->settings_currency_catalog = $this->service_factory->create_settings_currency_catalog();
		}

		return $this->settings_currency_catalog;
	}
}
