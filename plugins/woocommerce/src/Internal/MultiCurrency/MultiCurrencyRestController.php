<?php
/**
 * MultiCurrencyRestController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyException;
use Automattic\WooCommerce\Internal\MultiCurrency\Exceptions\InvalidCurrencyRateException;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyFrontendProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyCacheRenderingService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRateService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRestProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySettingsCurrencyCatalog;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Providers\CurrencyRateProviderRegistryFactory;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Registers native multi-currency REST routes when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyRestController extends WP_REST_Controller implements RegisterHooksInterface {

	private const OPTION_PREFIX        = 'wcpay_multi_currency';
	private const REST_NAMESPACE       = 'wc/v3';
	private const REST_BASE            = 'payments/multi-currency';
	private const RENDERING_MODE_SPEED = 'speed';
	private const RENDERING_MODE_CACHE = 'cache';

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
	 * Frontend projection service.
	 *
	 * @var MultiCurrencyFrontendProjectionService|null
	 */
	private ?MultiCurrencyFrontendProjectionService $frontend_projection_service = null;

	/**
	 * Settings currency catalog.
	 *
	 * @var MultiCurrencySettingsCurrencyCatalog|null
	 */
	private ?MultiCurrencySettingsCurrencyCatalog $settings_currency_catalog = null;

	/**
	 * Projection service factory.
	 *
	 * @var MultiCurrencyProjectionServiceFactory
	 */
	private MultiCurrencyProjectionServiceFactory $projection_service_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter             $arbiter                    Runtime owner arbiter.
	 * @param MultiCurrencyStateBuilderFactory        $state_builder_factory      State builder factory.
	 * @param MultiCurrencyProjectionServiceFactory   $projection_service_factory Projection service factory.
	 * @param MultiCurrencyCacheRenderingService|null $cache_rendering_service Cache rendering service.
	 */
	final public function init( MultiCurrencyRuntimeArbiter $arbiter, MultiCurrencyStateBuilderFactory $state_builder_factory, MultiCurrencyProjectionServiceFactory $projection_service_factory, ?MultiCurrencyCacheRenderingService $cache_rendering_service = null ): void {
		$this->arbiter                    = $arbiter;
		$this->state_builder_factory      = $state_builder_factory;
		$this->projection_service_factory = $projection_service_factory;
		$this->cache_rendering_service    = $cache_rendering_service ?? wc_get_container()->get( MultiCurrencyCacheRenderingService::class );
		$this->namespace                  = self::REST_NAMESPACE;
		$this->rest_base                  = self::REST_BASE;
	}

	/**
	 * Set the state builder.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencyStateBuilder $state_builder State builder.
	 */
	public function set_state_builder( MultiCurrencyStateBuilder $state_builder ): void {
		$this->state_builder = $state_builder;
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
	 * Set the settings currency catalog.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencySettingsCurrencyCatalog $settings_currency_catalog Settings currency catalog.
	 */
	public function set_settings_currency_catalog( MultiCurrencySettingsCurrencyCatalog $settings_currency_catalog ): void {
		$this->settings_currency_catalog = $settings_currency_catalog;
	}

	/**
	 * Register REST route hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		$this->add_action_once( 'rest_api_init', array( $this, 'handle_rest_api_init' ) );
	}

	/**
	 * Register native multi-currency REST routes.
	 *
	 * @internal
	 */
	public function handle_rest_api_init(): void {
		$manifest = MultiCurrencyRestProjectionService::get_route_manifest(
			$this->get_frontend_projection_service()->is_cache_optimized_mode()
		);

		foreach ( $manifest['routes'] as $route ) {
			$args = array(
				'methods'             => $route['methods'],
				'callback'            => $this->get_route_callback( (string) $route['callback'] ),
				'permission_callback' => 'public' === $route['permission'] ? '__return_true' : array( $this, 'check_permission' ),
			);

			if ( ! empty( $route['args'] ) ) {
				$args['args'] = $route['args'];
			}

			register_rest_route( (string) $manifest['namespace'], (string) $route['path'], $args );
		}
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

		update_option( self::OPTION_PREFIX . '_enabled_currencies', $enabled_codes );
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
			return rest_ensure_response( $this->get_frontend_projection_service()->get_single_currency_settings( $currency_code ) );
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
				$this->get_frontend_projection_service()->get_settings(),
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

		foreach ( $this->get_updateable_settings() as $option_name ) {
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
				self::OPTION_PREFIX . '_rendering_mode' === $option_name
				&& ! in_array( $value, array( self::RENDERING_MODE_SPEED, self::RENDERING_MODE_CACHE ), true )
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
		$response = rest_ensure_response( $this->get_frontend_projection_service()->get_public_config() );

		foreach ( MultiCurrencyRestProjectionService::get_public_config_headers() as $name => $value ) {
			$response->header( $name, $value );
		}

		return $response;
	}

	/**
	 * Get a route callback from a manifest callback marker.
	 *
	 * @param string $callback Callback marker.
	 * @return callable
	 */
	private function get_route_callback( string $callback ): callable {
		$callbacks = array(
			'get_public_config'               => array( $this, 'get_public_config' ),
			'get_store_currencies'            => array( $this, 'get_store_currencies' ),
			'update_enabled_currencies'       => array( $this, 'update_enabled_currencies' ),
			'get_single_currency_settings'    => array( $this, 'get_single_currency_settings' ),
			'get_settings'                    => array( $this, 'get_settings' ),
			'update_single_currency_settings' => array( $this, 'update_single_currency_settings' ),
			'update_settings'                 => array( $this, 'update_settings' ),
		);

		return $callbacks[ $callback ] ?? array( $this, 'get_store_currencies' );
	}

	/**
	 * Update preserved single-currency option keys.
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

			update_option( self::OPTION_PREFIX . '_manual_rate_' . $currency_id, (float) $manual_rate );
		}

		update_option( self::OPTION_PREFIX . '_price_rounding_' . $currency_id, $price_rounding );
		update_option( self::OPTION_PREFIX . '_price_charm_' . $currency_id, $price_charm );

		if ( in_array( $exchange_rate_type, array( 'automatic', 'manual' ), true ) ) {
			update_option( self::OPTION_PREFIX . '_exchange_rate_' . $currency_id, $exchange_rate_type );
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
			$currency_code = strtoupper( (string) $currency_code );
			if ( 3 !== strlen( $currency_code ) || in_array( $currency_code, $enabled_codes, true ) ) {
				continue;
			}

			foreach ( array( 'price_charm', 'price_rounding', 'manual_rate', 'exchange_rate' ) as $setting ) {
				delete_option( self::OPTION_PREFIX . '_' . $setting . '_' . strtolower( $currency_code ) );
			}
		}
	}

	/**
	 * Get updateable store settings.
	 *
	 * @return string[]
	 */
	private function get_updateable_settings(): array {
		return array(
			self::OPTION_PREFIX . '_enable_auto_currency',
			self::OPTION_PREFIX . '_enable_storefront_switcher',
			self::OPTION_PREFIX . '_rendering_mode',
			MultiCurrencyCacheRenderingService::DISMISSED_OPTION,
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
			$this->state_builder = $this->state_builder_factory->create();
		}

		return $this->state_builder;
	}

	/**
	 * Get the frontend projection service.
	 *
	 * @return MultiCurrencyFrontendProjectionService
	 */
	private function get_frontend_projection_service(): MultiCurrencyFrontendProjectionService {
		if ( null === $this->frontend_projection_service ) {
			$this->frontend_projection_service = $this->projection_service_factory->create_frontend_projection_service( null, $this->get_state_builder() );
		}

		return $this->frontend_projection_service;
	}

	/**
	 * Get the settings currency catalog.
	 *
	 * @return MultiCurrencySettingsCurrencyCatalog
	 */
	private function get_settings_currency_catalog(): MultiCurrencySettingsCurrencyCatalog {
		if ( null === $this->settings_currency_catalog ) {
			$localization_service = new MultiCurrencyLocalizationService();
			$registry_factory     = wc_get_container()->get( CurrencyRateProviderRegistryFactory::class );

			$this->settings_currency_catalog = new MultiCurrencySettingsCurrencyCatalog(
				$localization_service,
				$this->get_state_builder(),
				new MultiCurrencyRateService( $registry_factory->create() )
			);
		}

		return $this->settings_currency_catalog;
	}

	/**
	 * Register an action only once for this controller instance.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Hook callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	private function add_action_once( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( false === has_action( $hook, $callback ) ) {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}
}
