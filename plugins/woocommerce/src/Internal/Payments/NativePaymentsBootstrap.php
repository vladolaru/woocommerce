<?php
/**
 * NativePaymentsBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
use Automattic\WooCommerce\Internal\Payments\Shadow\NativePaymentsShadowMode;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Selects native payment registrations for the current request.
 *
 * @since 11.2.0
 * @internal
 */
final class NativePaymentsBootstrap {

	/** Test-only filter controlling the branch-native bootstrap. */
	public const FILTER_BOOTSTRAP_ENABLED = 'woocommerce_native_payments_bootstrap_enabled';

	/**
	 * Effective tier published by the current bootstrap request.
	 *
	 * A disabled default keeps callers passive and safe before registration, including the controlled no-op comparison path.
	 *
	 * @var string
	 */
	private static $effective_state = NativePaymentsState::DISABLED;

	/**
	 * Provider-owned root matrix resolver.
	 *
	 * @var callable
	 * @phpstan-var callable(): array<string,array<string,array<int,class-string>>>
	 */
	private $root_matrix_resolver;

	/**
	 * Create a neutral bootstrap for one provider-owned root matrix.
	 *
	 * @param callable $root_matrix_resolver Provider-owned root matrix resolver.
	 * @phpstan-param callable(): array<string,array<string,array<int,class-string>>> $root_matrix_resolver
	 */
	public function __construct( callable $root_matrix_resolver ) {
		$this->root_matrix_resolver = $root_matrix_resolver;
	}

	/**
	 * Register the integrations needed for the current request.
	 *
	 * @since 11.2.0
	 *
	 * @param Container|RuntimeContainer $container           Runtime dependency container.
	 * @param callable                   $is_rest_api_request Whether the current request is a REST request.
	 */
	public function register( $container, callable $is_rest_api_request ): void {
		self::$effective_state = NativePaymentsState::DISABLED;

		/**
		 * Filters whether the branch-native payments bootstrap runs for automated performance comparison.
		 *
		 * This internal filter defaults to enabled and is not a merchant-facing kill switch.
		 *
		 * @since 11.2.0
		 *
		 * @param bool $enabled Whether to bootstrap the branch-native payments registrations.
		 */
		if ( ! apply_filters( self::FILTER_BOOTSTRAP_ENABLED, true ) ) {
			return;
		}

		( new MultiCurrencyBootstrap() )->register( $container );

		$state_store           = $container->get( NativePaymentsState::class );
		$arbiter               = $container->get( NativePaymentsRuntimeArbiter::class );
		$owner                 = $arbiter->get_runtime_owner();
		$state                 = self::effective_state( $state_store->get_state(), $owner );
		$request               = $this->classify_request( $is_rest_api_request );
		self::$effective_state = $state;

		$this->register_roots( $container, $this->roots_for( $state, $request ) );

		/**
		 * Filters whether read-only native payments shadow mode is enabled.
		 *
		 * @since 11.0.0
		 *
		 * @param bool $enabled Whether shadow mode is enabled. Default false.
		 */
		if ( NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $owner && apply_filters( NativePaymentsShadowMode::FILTER_SHADOW_ENABLED, false ) ) {
			$this->register_root( $container, NativePaymentsShadowMode::class );
		}
	}

	/**
	 * Get the effective tier selected by bootstrap for this request.
	 *
	 * @since 11.2.0
	 *
	 * @return string Effective tier.
	 */
	public static function get_effective_state(): string {
		return self::$effective_state;
	}

	/**
	 * Reset the published effective tier to its safe default.
	 *
	 * @since 11.2.0
	 *
	 * @internal
	 */
	public static function reset_effective_state(): void {
		self::$effective_state = NativePaymentsState::DISABLED;
	}

	/**
	 * Apply request-local ownership clamps to the durable tier.
	 *
	 * @param string $state Persisted tier.
	 * @param string $owner Runtime owner.
	 * @return string Effective tier.
	 */
	private static function effective_state( string $state, string $owner ): string {
		if ( NativePaymentsRuntimeArbiter::OWNER_NONE === $owner ) {
			return NativePaymentsState::DISABLED;
		}

		if (
			NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $owner &&
			in_array( $state, array( NativePaymentsState::CONNECTED, NativePaymentsState::ACTIVE ), true )
		) {
			return NativePaymentsState::AVAILABLE;
		}

		return $state;
	}

	/**
	 * Classify the current request without resolving another service.
	 *
	 * @param callable $is_rest_api_request Whether the current request is a REST request.
	 * @return string Request class.
	 */
	private function classify_request( callable $is_rest_api_request ): string {
		return self::classify_signals(
			defined( 'WP_CLI' ) && WP_CLI,
			wp_doing_cron() || wc_is_running_from_async_action_scheduler(),
			wp_doing_ajax(),
			(bool) $is_rest_api_request(),
			is_admin()
		);
	}

	/**
	 * Select a request class from early-safe signals in precedence order.
	 *
	 * @param bool $is_cli   Whether WP-CLI is running.
	 * @param bool $is_cron  Whether cron or Action Scheduler is running.
	 * @param bool $is_ajax  Whether WordPress AJAX is running.
	 * @param bool $is_rest  Whether this is a REST request.
	 * @param bool $is_admin Whether this is an admin request.
	 * @return string Request class.
	 */
	private static function classify_signals( bool $is_cli, bool $is_cron, bool $is_ajax, bool $is_rest, bool $is_admin ): string {
		if ( $is_cli ) {
			return 'cli';
		}
		if ( $is_cron ) {
			return 'cron';
		}
		if ( $is_ajax ) {
			return 'ajax';
		}
		if ( $is_rest ) {
			return 'rest';
		}

		return $is_admin ? 'admin' : 'front';
	}

	/**
	 * Get the provider roots for one tier and request class.
	 *
	 * @param string $state   Effective tier.
	 * @param string $request Request class.
	 * @return array<int,class-string> Root class names in registration order.
	 */
	private function roots_for( string $state, string $request ): array {
		if ( 'cli' === $request || NativePaymentsState::DISABLED === $state ) {
			return array();
		}

		$matrix = ( $this->root_matrix_resolver )();

		return $matrix[ $state ][ $request ] ?? array();
	}

	/**
	 * Resolve and register explicit roots once, preserving gateway-provider order.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param array<int,class-string>    $roots     Root class names.
	 */
	private function register_roots( $container, array $roots ): void {
		$count = count( $roots );
		for ( $index = 0; $index < $count; ++$index ) {
			$root = $roots[ $index ];
			if ( NativePaymentsGatewayRegistry::class === $root ) {
				/**
				 * Gateway registry.
				 *
				 * @var NativePaymentsGatewayRegistry $registry
				 */
				$registry      = $container->get( $root );
				$provider_root = $roots[ ++$index ];
				/**
				 * Native payment gateway provider.
				 *
				 * @var PaymentGatewayProviderContract $provider
				 */
				$provider = $container->get( $provider_root );
				$registry->register_provider( $provider );
				$registry->register();
				continue;
			}

			$this->register_root( $container, $root );
		}
	}

	/**
	 * Resolve and register one explicit root.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param class-string               $root      Root class name.
	 */
	private function register_root( $container, string $root ): void {
		/**
		 * Explicit registrar.
		 *
		 * @var RegisterHooksInterface $registrar
		 */
		$registrar = $container->get( $root );
		$registrar->register();
	}
}
