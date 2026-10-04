<?php
/**
 * NativePaymentsBootstrap class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments;

use Automattic\WooCommerce\Container;
use Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyBootstrap;
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
	 * Provider-owned root matrix resolver.
	 *
	 * @var callable
	 * @phpstan-var callable(): array<string,array<string,array<int,class-string>>>
	 */
	private $root_matrix_resolver;

	/**
	 * Multi-Currency provider roots resolver.
	 *
	 * @var callable
	 * @phpstan-var callable(): array<int,class-string>
	 */
	private $multi_currency_provider_roots_resolver;

	/**
	 * Provider-owned hook registrar for sites where the provider's plugin owns the runtime.
	 *
	 * @var callable|null
	 * @phpstan-var (callable(Container|RuntimeContainer): void)|null
	 */
	private $plugin_owner_registrar;

	/**
	 * Create a neutral bootstrap for provider-owned root matrices.
	 *
	 * @param callable      $root_matrix_resolver                  Provider-owned native payments root matrix resolver.
	 * @param callable      $multi_currency_provider_roots_resolver Provider-owned Multi-Currency roots resolver.
	 * @param callable|null $plugin_owner_registrar                Provider-owned hooks to add only while the provider's plugin owns the runtime.
	 * @phpstan-param callable(): array<string,array<string,array<int,class-string>>> $root_matrix_resolver
	 * @phpstan-param callable(): array<int,class-string> $multi_currency_provider_roots_resolver
	 * @phpstan-param (callable(Container|RuntimeContainer): void)|null $plugin_owner_registrar
	 */
	public function __construct( callable $root_matrix_resolver, callable $multi_currency_provider_roots_resolver, ?callable $plugin_owner_registrar = null ) {
		$this->root_matrix_resolver                   = $root_matrix_resolver;
		$this->multi_currency_provider_roots_resolver = $multi_currency_provider_roots_resolver;
		$this->plugin_owner_registrar                 = $plugin_owner_registrar;
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

		( new MultiCurrencyBootstrap( $this->multi_currency_provider_roots_resolver ) )->register( $container, $is_rest_api_request );

		$state_store = $container->get( NativePaymentsState::class );
		$arbiter     = $container->get( NativePaymentsRuntimeArbiter::class );
		$owner       = $arbiter->get_runtime_owner();
		$state       = $state_store->get_state();
		$request     = MultiCurrencyBootstrap::classify_request( $is_rest_api_request );

		$this->register_roots( $container, $this->roots_for( $state, $request ) );

		if ( NativePaymentsRuntimeArbiter::OWNER_PLUGIN === $owner && null !== $this->plugin_owner_registrar ) {
			( $this->plugin_owner_registrar )( $container );
		}
	}

	/**
	 * Get the provider roots for one tier and request class.
	 *
	 * @param string $state   Effective tier.
	 * @param string $request Request class.
	 * @return array<int,class-string> Root class names in registration order.
	 */
	private function roots_for( string $state, string $request ): array {
		if ( NativePaymentsState::DISABLED === $state ) {
			return array();
		}

		$matrix = ( $this->root_matrix_resolver )();

		return $matrix[ $state ][ $request ] ?? array();
	}

	/**
	 * Resolve and register explicit roots once. The gateway registry takes the next root as its provider, resolved only when WooCommerce builds its gateway list.
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
				$registry->register_provider_resolver(
					static function () use ( $container, $provider_root ) {
						/**
						 * Native payment gateway provider.
						 *
						 * @var PaymentGatewayProviderContract $provider
						 */
						$provider = $container->get( $provider_root );
						return $provider;
					}
				);
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
