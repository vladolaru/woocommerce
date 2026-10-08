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
 * A listed class that is a payment gateway provider is handed to the gateway registry, which resolves it only when
 * WooCommerce builds its gateway list; it is register()ed as well only when it also registers hooks. Every other listed
 * class is resolved and register()ed.
 *
 * @since 11.2.0
 * @internal
 */
final class NativePaymentsBootstrap {

	/** Test-only filter controlling the branch-native bootstrap. */
	public const FILTER_BOOTSTRAP_ENABLED = 'woocommerce_native_payments_bootstrap_enabled';

	/**
	 * Lists the classes a request type registers for the built-in payment provider.
	 *
	 * @var callable
	 * @phpstan-var callable(Container|RuntimeContainer, string): array<int,class-string>
	 */
	private $classes_for_request;

	/**
	 * Tells whether the built-in payment provider's gateways belong in the gateway list now.
	 *
	 * @var callable
	 * @phpstan-var callable(Container|RuntimeContainer): bool
	 */
	private $should_register_gateways;

	/**
	 * Lists the Multi-Currency classes the built-in payment provider contributes.
	 *
	 * @var callable
	 * @phpstan-var callable(): array<int,class-string>
	 */
	private $multi_currency_classes;

	/**
	 * Container for the cron roots this request registers when Action Scheduler first runs an action.
	 *
	 * @var Container|RuntimeContainer|null
	 */
	private $on_demand_container = null;

	/**
	 * Cron roots this request lacks, registered when Action Scheduler first runs an action.
	 *
	 * @var array<int,class-string>
	 */
	private array $on_demand_cron_roots = array();

	/**
	 * Create the bootstrap for WooCommerce's built-in payment provider.
	 *
	 * The inputs are the built-in provider's: the classes each request type registers, in order and empty when nothing
	 * is set up; whether its gateways belong in the gateway list, which the bootstrap only hands to the gateway registry;
	 * and the Multi-Currency classes it contributes, as the bootstrap starts Multi-Currency.
	 *
	 * @param callable $classes_for_request      Lists the classes a request type registers.
	 * @param callable $should_register_gateways Tells whether the provider's gateways belong in the gateway list now.
	 * @param callable $multi_currency_classes   Lists the provider's Multi-Currency classes.
	 * @phpstan-param callable(Container|RuntimeContainer, string): array<int,class-string> $classes_for_request
	 * @phpstan-param callable(Container|RuntimeContainer): bool $should_register_gateways
	 * @phpstan-param callable(): array<int,class-string> $multi_currency_classes
	 */
	public function __construct( callable $classes_for_request, callable $should_register_gateways, callable $multi_currency_classes ) {
		$this->classes_for_request      = $classes_for_request;
		$this->should_register_gateways = $should_register_gateways;
		$this->multi_currency_classes   = $multi_currency_classes;
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

		( new MultiCurrencyBootstrap( $this->multi_currency_classes ) )->register( $container, $is_rest_api_request );

		$request = MultiCurrencyBootstrap::classify_request( $is_rest_api_request );
		$roots   = ( $this->classes_for_request )( $container, $request );

		$this->register_roots( $container, $roots );
		$this->register_cron_roots_on_demand( $container, $request, $roots );
	}

	/**
	 * Register the tier's cron roots that this request lacks once Action Scheduler runs an action in it.
	 *
	 * ALTERNATE_WP_CRON and the Tools > Scheduled Actions "Run" link run actions inside a front or admin request.
	 * The client attaches its scheduled-action handlers on every request (client 11.1.0 `includes/class-wc-payments.php:603,657`).
	 *
	 * @param Container|RuntimeContainer $container  Runtime dependency container.
	 * @param string                     $request    Request type.
	 * @param array<int,class-string>    $registered Roots already registered for this request.
	 */
	private function register_cron_roots_on_demand( $container, string $request, array $registered ): void {
		if ( 'cron' === $request || 'cli' === $request ) {
			return;
		}

		$missing = self::roots_missing_from( ( $this->classes_for_request )( $container, 'cron' ), $registered );
		if ( empty( $missing ) ) {
			return;
		}

		$this->on_demand_container  = $container;
		$this->on_demand_cron_roots = $missing;
		// Action Scheduler fires this before it checks the action has callbacks and runs it (`ActionScheduler_Abstract_QueueRunner::process_action()`).
		add_action( 'action_scheduler_before_execute', array( $this, 'handle_action_scheduler_before_execute' ), 0 );
	}

	/**
	 * Register the missing cron roots before Action Scheduler runs the first action of this request.
	 *
	 * @internal
	 */
	public function handle_action_scheduler_before_execute(): void {
		remove_action( 'action_scheduler_before_execute', array( $this, 'handle_action_scheduler_before_execute' ), 0 );
		$roots                      = $this->on_demand_cron_roots;
		$this->on_demand_cron_roots = array();
		if ( empty( $roots ) || null === $this->on_demand_container ) {
			return;
		}

		$this->register_roots( $this->on_demand_container, $roots );
	}

	/**
	 * Get the roots not yet registered.
	 *
	 * @param array<int,class-string> $roots      Root class names in registration order.
	 * @param array<int,class-string> $registered Roots already registered.
	 * @return array<int,class-string> Missing roots in registration order.
	 */
	private static function roots_missing_from( array $roots, array $registered ): array {
		return array_values( array_diff( $roots, $registered ) );
	}

	/**
	 * Resolve and register explicit roots once, handing payment gateway providers to the gateway registry.
	 *
	 * @param Container|RuntimeContainer $container Runtime dependency container.
	 * @param array<int,class-string>    $roots     Root class names.
	 */
	private function register_roots( $container, array $roots ): void {
		foreach ( $roots as $root ) {
			if ( is_a( $root, PaymentGatewayProviderContract::class, true ) ) {
				$this->add_gateway_provider( $container, $root );
				if ( ! is_a( $root, RegisterHooksInterface::class, true ) ) {
					continue;
				}
			}

			$this->register_root( $container, $root );
		}
	}

	/**
	 * Hand a payment gateway provider to the gateway registry, resolved only when WooCommerce builds its gateway list.
	 *
	 * @param Container|RuntimeContainer $container     Runtime dependency container.
	 * @param class-string               $provider_root Payment gateway provider class name.
	 */
	private function add_gateway_provider( $container, string $provider_root ): void {
		/**
		 * Gateway registry.
		 *
		 * @var NativePaymentsGatewayRegistry $registry
		 */
		$registry = $container->get( NativePaymentsGatewayRegistry::class );
		$registry->add_provider(
			static function () use ( $container, $provider_root ) {
				/**
				 * Native payment gateway provider.
				 *
				 * @var PaymentGatewayProviderContract $provider
				 */
				$provider = $container->get( $provider_root );
				return $provider;
			},
			fn(): bool => ( $this->should_register_gateways )( $container )
		);
		$registry->register();
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
