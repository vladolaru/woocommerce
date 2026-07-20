<?php
/**
 * MultiCurrencySwitcherBlockController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySwitcherProjectionService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers native multi-currency switcher block rendering when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencySwitcherBlockController implements RegisterHooksInterface {

	private const BLOCK_NAME                 = 'woocommerce-payments/multi-currency-switcher';
	private const EDITOR_SCRIPT_HANDLE       = self::BLOCK_NAME;
	private const EDITOR_SCRIPT_PATH         = 'assets/client/blocks/multi-currency-switcher.js';
	private const EDITOR_SCRIPT_ASSET_PATH   = 'assets/client/blocks/multi-currency-switcher.asset.php';
	private const EDITOR_SCRIPT_DEPENDENCIES = array(
		'react-jsx-runtime',
		'wp-block-editor',
		'wp-blocks',
		'wp-components',
		'wp-i18n',
		'wp-polyfill',
		'wp-server-side-render',
	);

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Compatibility controller.
	 *
	 * @var MultiCurrencyCompatibilityController
	 */
	private MultiCurrencyCompatibilityController $compatibility_controller;

	/**
	 * Runtime service factory.
	 *
	 * @var MultiCurrencyRuntimeServiceFactory
	 */
	private MultiCurrencyRuntimeServiceFactory $runtime_service_factory;

	/**
	 * Switcher projection service.
	 *
	 * @var MultiCurrencySwitcherProjectionService|null
	 */
	private ?MultiCurrencySwitcherProjectionService $switcher_projection_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter          $arbiter                  Runtime owner arbiter.
	 * @param MultiCurrencyCompatibilityController $compatibility_controller Compatibility controller.
	 * @param MultiCurrencyRuntimeServiceFactory   $runtime_service_factory  Runtime service factory.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		MultiCurrencyCompatibilityController $compatibility_controller,
		MultiCurrencyRuntimeServiceFactory $runtime_service_factory
	): void {
		$this->arbiter                  = $arbiter;
		$this->compatibility_controller = $compatibility_controller;
		$this->runtime_service_factory  = $runtime_service_factory;
	}

	/**
	 * Set the switcher projection service.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencySwitcherProjectionService $switcher_projection_service Switcher projection service.
	 */
	public function set_switcher_projection_service( MultiCurrencySwitcherProjectionService $switcher_projection_service ): void {
		$this->switcher_projection_service = $switcher_projection_service;
	}

	/**
	 * Register switcher block hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		$this->add_action_once( 'init', array( $this, 'handle_init' ) );
	}

	/**
	 * Register the native switcher block type.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::BLOCK_NAME ) ) {
			return;
		}

		$this->register_editor_script();

		register_block_type(
			self::BLOCK_NAME,
			// @phpstan-ignore-next-line argument.type (WordPress accepts integer api_version values and stores them unchanged at runtime.)
			array(
				'api_version'     => 3,
				'editor_script'   => self::EDITOR_SCRIPT_HANDLE,
				'render_callback' => array( $this, 'render_block_widget' ),
				'attributes'      => self::get_block_attributes(),
			)
		);
	}

	/**
	 * Register the editor-only switcher block script.
	 */
	private function register_editor_script(): void {
		if ( wp_script_is( self::EDITOR_SCRIPT_HANDLE, 'registered' ) ) {
			return;
		}

		$asset_data_path = WC()->plugin_path() . '/' . self::EDITOR_SCRIPT_ASSET_PATH;
		$asset_data      = array();

		if ( file_exists( $asset_data_path ) ) {
			$loaded_asset_data = require $asset_data_path;

			if ( is_array( $loaded_asset_data ) ) {
				$asset_data = $loaded_asset_data;
			}
		}

		$asset_dependencies = $asset_data['dependencies'] ?? null;
		$dependencies       = self::resolve_editor_script_dependencies( $asset_dependencies );
		$version            = isset( $asset_data['version'] ) && is_string( $asset_data['version'] ) && '' !== $asset_data['version']
			? $asset_data['version']
			: WC_VERSION;

		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			WC()->plugin_url() . '/' . self::EDITOR_SCRIPT_PATH,
			$dependencies,
			$version,
			true
		);
		wp_set_script_translations( self::EDITOR_SCRIPT_HANDLE, 'woocommerce' );
	}

	/**
	 * Resolve build-derived editor dependencies with a complete runtime fallback.
	 *
	 * @param mixed $asset_dependencies Dependencies from generated asset metadata.
	 * @return string[]
	 */
	private static function resolve_editor_script_dependencies( $asset_dependencies ): array {
		$metadata_dependencies = is_array( $asset_dependencies )
			? array_values(
				array_filter(
					$asset_dependencies,
					static function ( $dependency ): bool {
						return is_string( $dependency ) && '' !== $dependency;
					}
				)
			)
			: array();

		return array_values(
			array_unique( array_merge( self::EDITOR_SCRIPT_DEPENDENCIES, $metadata_dependencies ) )
		);
	}

	/**
	 * Render the native switcher block.
	 *
	 * @param mixed $block_attributes Block attributes.
	 * @return string
	 */
	public function render_block_widget( $block_attributes ): string {
		return $this->get_switcher_projection_service()->get_block_markup(
			is_array( $block_attributes ) ? $block_attributes : array(),
			$this->get_current_query_args(),
			$this->compatibility_controller->should_disable_currency_switching()
		);
	}

	/**
	 * Get preserved WooPayments switcher block attributes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function get_block_attributes(): array {
		return array(
			'symbol'          => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'flag'            => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'fontSize'        => array(
				'type'    => 'integer',
				'default' => 14,
			),
			'fontLineHeight'  => array(
				'type'    => 'number',
				'default' => 1.5,
			),
			'fontColor'       => array(
				'type'    => 'string',
				'default' => '#000000',
			),
			'border'          => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'borderRadius'    => array(
				'type'    => 'integer',
				'default' => 3,
			),
			'borderColor'     => array(
				'type'    => 'string',
				'default' => '#000000',
			),
			'backgroundColor' => array(
				'type'    => 'string',
				'default' => 'transparent',
			),
		);
	}

	/**
	 * Get sanitized current query arguments for switcher forms.
	 *
	 * @return array<string,mixed>
	 */
	private function get_current_query_args(): array {
		$query_args = wc_clean( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query preservation for switcher form inputs.

		return is_array( $query_args ) ? $query_args : array();
	}

	/**
	 * Get the switcher projection service.
	 *
	 * @return MultiCurrencySwitcherProjectionService
	 */
	private function get_switcher_projection_service(): MultiCurrencySwitcherProjectionService {
		if ( null === $this->switcher_projection_service ) {
			$this->switcher_projection_service = $this->runtime_service_factory->create_switcher_projection_service();
		}

		return $this->switcher_projection_service;
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
